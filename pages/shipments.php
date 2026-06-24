<?php
// $filterActive / $currentMonth / $currentYear задаёт глобальный фильтр в index.php

// Добавляем статус Зарезервировано в ENUM склада (нужен для продаж из поставок "В пути")
try { $pdo->exec("ALTER TABLE warehouse MODIFY COLUMN status ENUM('На складе','Частично продан','Продан','Зарезервировано') DEFAULT 'На складе'"); } catch (PDOException $e) {}

// Автосоздание таблицы документов если не существует
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS shipment_docs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        shipment_id INT NOT NULL,
        filename VARCHAR(255) NOT NULL,
        original_name VARCHAR(255) NOT NULL,
        uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_shipment_id (shipment_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (PDOException $e) {}

// Добавляем поле name если не существует
try { $pdo->exec("ALTER TABLE shipments ADD COLUMN name VARCHAR(255) DEFAULT NULL"); } catch (PDOException $e) {}

$_shipmentCols = "
    SELECT s.*,
        c.name as counterparty, c.company_type,
        cr.name as carrier,
        COALESCE(SUM(si.quantity * si.purchase_price), 0) + s.carrier_cost as total,
        GROUP_CONCAT(si.name SEPARATOR ', ') as items_list,
        ba.name as account_name,
        (SELECT COUNT(*) FROM shipment_docs sd WHERE sd.shipment_id = s.id) as docs_count,
        (SELECT COUNT(*) FROM shipments s2 WHERE s2.id <= s.id) as display_num
    FROM shipments s
    LEFT JOIN counterparties c ON c.id = s.counterparty_id
    LEFT JOIN carriers cr ON cr.id = s.carrier_id
    LEFT JOIN shipment_items si ON si.shipment_id = s.id
    LEFT JOIN bank_accounts ba ON ba.id = s.account_id";

if ($filterActive) {
    $stmt = $pdo->prepare($_shipmentCols . "
        WHERE MONTH(s.order_date) = ? AND YEAR(s.order_date) = ?
        GROUP BY s.id ORDER BY s.order_date DESC");
    $stmt->execute([$currentMonth, $currentYear]);
    $shipments = $stmt->fetchAll();

    // Просроченные незавершённые вне текущего периода — не подпадают под фильтр, грузим отдельно
    $inIds = array_map('intval', array_column($shipments, 'id'));
    $notIn = $inIds ? "AND s.id NOT IN (" . implode(',', $inIds) . ")" : "";
    $overdueExtra = $pdo->query($_shipmentCols . "
        WHERE s.eta < CURDATE() AND s.status != 'Завершено' $notIn
        GROUP BY s.id ORDER BY s.eta ASC")->fetchAll();
} else {
    $shipments = $pdo->query($_shipmentCols . "
        GROUP BY s.id ORDER BY s.order_date DESC")->fetchAll();
    $overdueExtra = [];
}
?>

<div class="page-header">
    <h1 class="page-title">Поставки</h1>
</div>
<script>
window._gsPageActions = '<button class="btn btn-primary" onclick="openAddShipment()">+ Добавить</button>';
</script>

<?php
$overdueEtaCount = count($overdueExtra);
foreach ($shipments as $s) {
    if ($s['eta'] && $s['status'] !== 'Завершено' && strtotime($s['eta']) < strtotime('today')) $overdueEtaCount++;
}
?>
<div class="tabs">
    <button class="tab active" data-tab="all" onclick="filterShipments('all')">Все</button>
    <button class="tab" data-tab="transit" onclick="filterShipments('transit')">В пути</button>
    <button class="tab" data-tab="done" onclick="filterShipments('done')">Завершённые</button>
    <button class="tab" data-tab="alert" onclick="filterShipments('alert')">Форс-мажор</button>
    <button class="tab" data-tab="overdue-eta" onclick="filterShipments('overdue-eta')" style="<?= $overdueEtaCount > 0 ? 'color:var(--danger)' : '' ?>">
        Просрочена ETA<?= $overdueEtaCount > 0 ? ' <span style="background:var(--danger);color:#fff;font-size:10px;font-weight:700;padding:1px 5px;border-radius:10px;margin-left:2px">' . $overdueEtaCount . '</span>' : '' ?>
    </button>
</div>

<?php if (empty($shipments)): ?>
    <div class="empty-state">
        <p>Поставок пока нет</p>
        <button class="btn btn-primary" style="margin-top:16px" onclick="openAddShipment()">+ Добавить первую</button>
    </div>
<?php else: ?>
<div class="table-wrapper">
    <table>
        <thead>
            <tr>
                <th>Поставка</th>
                <th>Даты</th>
                <th>Дней</th>
                <th>Поставщик</th>
                <th>Товары</th>
                <th>ТК / Трек</th>
                <th>Статус</th>
                <th>Сумма</th>
                <th></th>
                <th></th>
            </tr>
        </thead>
        <tbody id="shipments-tbody" class="m-cards m-cards-ship">
            <?php foreach ($shipments as $s):
                if ($s['status'] === 'Завершено' && $s['eta']) {
    $days = (int)((strtotime($s['eta']) - strtotime($s['order_date'])) / 86400);
    $daysClass = '';
} else {
    $days = (int)((time() - strtotime($s['order_date'])) / 86400);
    $daysClass = $days > 30 ? 'long' : '';
}

                $badgeMap = [
                    'Ожидает отправки' => ['badge-waiting', 'Ожидает'],
                    'В пути'           => ['badge-transit', 'В пути'],
                    'Завершено'        => ['badge-done',    'Завершено'],
                    '⚠️'              => ['badge-alert',   'Форс-мажор'],
                ];
                [$badgeCls, $badgeLabel] = $badgeMap[$s['status']] ?? ['badge-waiting', $s['status'] ?? '—'];
            ?>
            <tr data-status="<?= htmlspecialchars($s['status'] ?? '') ?>" data-eta="<?= $s['eta'] ? $s['eta'] : '' ?>" onclick="openShipment(<?= $s['id'] ?>)">
                <!-- Поставка: номер + название -->
                <td>
                    <div style="display:flex;align-items:baseline;gap:6px">
                        <span style="color:var(--text-dim);font-size:11px;flex-shrink:0">#<?= $s['display_num'] ?></span>
                        <?php if ($s['name']): ?>
                        <span style="font-weight:500"><?= htmlspecialchars($s['name']) ?></span>
                        <?php endif; ?>
                    </div>
                </td>
                <!-- Даты: заказ + ETA -->
                <td>
                    <div style="font-size:13px"><?= date('d.m.Y', strtotime($s['order_date'])) ?></div>
                    <?php if ($s['eta']): ?>
                    <div style="font-size:11px;color:var(--text-muted)">→ <?= date('d.m.Y', strtotime($s['eta'])) ?></div>
                    <?php endif; ?>
                </td>
                <td><span class="days-counter <?= $daysClass ?>"><?= $days ?> дн</span></td>
                <td><?= htmlspecialchars(($s['company_type'] ?? '') . ' ' . ($s['counterparty'] ?? '—')) ?></td>
                <td>
                    <div class="items-list">
                        <?php
                        $items = explode(', ', $s['items_list'] ?? '');
                        foreach (array_slice($items, 0, 2) as $item):
                        ?>
                        <span class="item-chip"><?= htmlspecialchars($item) ?></span>
                        <?php endforeach; ?>
                        <?php if (count($items) > 2): ?>
                        <span class="item-chip" style="color:var(--text-dim)">+<?= count($items) - 2 ?> ещё</span>
                        <?php endif; ?>
                    </div>
                </td>
                <!-- ТК + Трек в одной ячейке -->
                <td>
                    <div style="font-size:13px"><?= htmlspecialchars($s['carrier'] ?? '—') ?></div>
                    <?php if ($s['tracking']): ?>
                    <div style="font-size:11px;color:var(--text-muted)"><?= htmlspecialchars(mb_substr($s['tracking'],0,20)) ?><?= mb_strlen($s['tracking'])>20?'…':'' ?></div>
                    <?php endif; ?>
                </td>
               <td onclick="event.stopPropagation()">
    <div class="status-dropdown" id="sd-<?= $s['id'] ?>">
        <span class="badge <?= $badgeCls ?>" onclick="toggleStatusDropdown(<?= $s['id'] ?>)" style="cursor:pointer">
            <?= $badgeLabel ?> ▾
        </span>
        <div class="status-dropdown-menu" id="sdm-<?= $s['id'] ?>">
            <?php $cur = addslashes($s['status'] ?? ''); ?>
            <div onclick="changeStatus(<?= $s['id'] ?>, 'Ожидает отправки', '<?= $cur ?>')">Ожидает</div>
            <div onclick="changeStatus(<?= $s['id'] ?>, 'В пути', '<?= $cur ?>')">В пути</div>
            <div onclick="changeStatus(<?= $s['id'] ?>, 'Завершено', '<?= $cur ?>')">Завершено</div>
            <div onclick="changeStatus(<?= $s['id'] ?>, '⚠️', '<?= $cur ?>')">Форс-мажор</div>
        </div>
    </div>
</td>
<td><?= number_format($s['total'], 0, '.', ' ') ?> ₽</td>
                <td style="width:20px;text-align:center">
                    <?php if ($s['docs_count'] > 0): ?>
                    <span class="doc-badge" title="<?= $s['docs_count'] ?> документ(ов)"><i data-lucide="paperclip" style="width:14px;height:14px"></i></span>
                    <?php endif; ?>
                </td>
                <td class="row-actions">
                    <button class="btn-icon" onclick="event.stopPropagation(); openEditShipment(<?= $s['id'] ?>)" title="Изменить">
                        <i data-lucide="pencil" style="width:14px;height:14px"></i>
                    </button>
                    <button class="btn-icon btn-icon-danger" onclick="event.stopPropagation(); deleteShipment(<?= $s['id'] ?>)" title="Удалить">
                        <i data-lucide="trash-2" style="width:14px;height:14px"></i>
                    </button>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php foreach ($overdueExtra as $s):
                $days = (int)((time() - strtotime($s['order_date'])) / 86400);
                $daysClass = $days > 30 ? 'long' : '';
                $badgeMap = ['Ожидает отправки'=>['badge-waiting','Ожидает'],'В пути'=>['badge-transit','В пути'],'⚠️'=>['badge-alert','Форс-мажор']];
                [$badgeCls, $badgeLabel] = $badgeMap[$s['status']] ?? ['badge-waiting', $s['status'] ?? '—'];
            ?>
            <tr data-status="<?= htmlspecialchars($s['status'] ?? '') ?>"
                data-eta="<?= $s['eta'] ?>"
                data-extra-overdue="1"
                style="display:none"
                onclick="openShipment(<?= $s['id'] ?>)">
                <td>
                    <div style="display:flex;align-items:baseline;gap:6px">
                        <span style="color:var(--text-dim);font-size:11px;flex-shrink:0">#<?= $s['display_num'] ?></span>
                        <?php if ($s['name']): ?><span style="font-weight:500"><?= htmlspecialchars($s['name']) ?></span><?php endif; ?>
                    </div>
                </td>
                <td>
                    <div style="font-size:13px"><?= date('d.m.Y', strtotime($s['order_date'])) ?></div>
                    <div style="font-size:11px;color:var(--danger)">→ <?= date('d.m.Y', strtotime($s['eta'])) ?></div>
                </td>
                <td><span class="days-counter <?= $daysClass ?>"><?= $days ?> дн</span></td>
                <td><?= htmlspecialchars(($s['company_type'] ?? '') . ' ' . ($s['counterparty'] ?? '—')) ?></td>
                <td>
                    <div class="items-list">
                        <?php $items = explode(', ', $s['items_list'] ?? '');
                        foreach (array_slice($items, 0, 2) as $item): ?>
                        <span class="item-chip"><?= htmlspecialchars($item) ?></span>
                        <?php endforeach; ?>
                        <?php if (count($items) > 2): ?><span class="item-chip" style="color:var(--text-dim)">+<?= count($items)-2 ?> ещё</span><?php endif; ?>
                    </div>
                </td>
                <td>
                    <div style="font-size:13px"><?= htmlspecialchars($s['carrier'] ?? '—') ?></div>
                    <?php if ($s['tracking']): ?>
                    <div style="font-size:11px;color:var(--text-muted)"><?= htmlspecialchars(mb_substr($s['tracking'],0,20)) ?><?= mb_strlen($s['tracking'])>20?'…':'' ?></div>
                    <?php endif; ?>
                </td>
                <td onclick="event.stopPropagation()">
                    <div class="status-dropdown" id="sd-<?= $s['id'] ?>">
                        <span class="badge <?= $badgeCls ?>" onclick="toggleStatusDropdown(<?= $s['id'] ?>)" style="cursor:pointer"><?= $badgeLabel ?> ▾</span>
                        <div class="status-dropdown-menu" id="sdm-<?= $s['id'] ?>">
                            <?php $cur2 = addslashes($s['status'] ?? ''); ?>
                            <div onclick="changeStatus(<?= $s['id'] ?>, 'Ожидает отправки', '<?= $cur2 ?>')">Ожидает</div>
                            <div onclick="changeStatus(<?= $s['id'] ?>, 'В пути', '<?= $cur2 ?>')">В пути</div>
                            <div onclick="changeStatus(<?= $s['id'] ?>, 'Завершено', '<?= $cur2 ?>')">Завершено</div>
                            <div onclick="changeStatus(<?= $s['id'] ?>, '⚠️', '<?= $cur2 ?>')">Форс-мажор</div>
                        </div>
                    </div>
                </td>
                <td><?= number_format($s['total'], 0, '.', ' ') ?> ₽</td>
                <td style="width:20px;text-align:center">
                    <?php if ($s['docs_count'] > 0): ?>
                    <span class="doc-badge" title="<?= $s['docs_count'] ?> документ(ов)"><i data-lucide="paperclip" style="width:14px;height:14px"></i></span>
                    <?php endif; ?>
                </td>
                <td class="row-actions">
                    <button class="btn-icon" onclick="event.stopPropagation(); openEditShipment(<?= $s['id'] ?>)" title="Изменить"><i data-lucide="pencil" style="width:14px;height:14px"></i></button>
                    <button class="btn-icon btn-icon-danger" onclick="event.stopPropagation(); deleteShipment(<?= $s['id'] ?>)" title="Удалить"><i data-lucide="trash-2" style="width:14px;height:14px"></i></button>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
