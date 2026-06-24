<?php
// $filterActive / $currentMonth / $currentYear задаёт глобальный фильтр в index.php
// Миграция колонки (если ещё нет)
try { $pdo->exec("ALTER TABLE sales ADD COLUMN source_shipment_id INT DEFAULT NULL"); } catch (PDOException $e) {}

$salesSelect = "
    SELECT s.*,
        COALESCE(c.company_type, '') as company_type,
        COALESCE(c.name, s.buyer_name, '—') as buyer,
        sh.status as source_shipment_status
    FROM sales s
    LEFT JOIN counterparties c ON c.id = s.counterparty_id
    LEFT JOIN shipments sh ON sh.id = s.source_shipment_id";

if ($filterActive) {
    $stmt = $pdo->prepare($salesSelect . " WHERE MONTH(s.sale_date)=? AND YEAR(s.sale_date)=? ORDER BY s.sale_date DESC");
    $stmt->execute([$currentMonth, $currentYear]);
    $sales = $stmt->fetchAll();
} else {
    $sales = $pdo->query($salesSelect . " ORDER BY s.sale_date DESC")->fetchAll();
}

foreach ($sales as &$sale) {
    $items = $pdo->prepare("
        SELECT si.*, w.name as product_name, w.purchase_price
        FROM sale_items si
        LEFT JOIN warehouse w ON w.id = si.warehouse_id
        WHERE si.sale_id = ?
    ");
    $items->execute([$sale['id']]);
    $sale['items'] = $items->fetchAll();
    $sale['total_qty'] = array_sum(array_column($sale['items'], 'quantity'));
}

// Оплаченные продажи
$paidSales   = array_filter($sales, fn($s) => ($s['status'] ?? 'Оплачено') === 'Оплачено');
$pendingSales = array_filter($sales, fn($s) => ($s['status'] ?? '') === 'Счёт выставлен');

$totalRevenue  = array_sum(array_column($paidSales, 'sale_price'));
$pendingAmount = array_sum(array_column($pendingSales, 'sale_price')); // ожидают оплаты
$totalQty      = array_sum(array_column($sales, 'total_qty'));
?>

<div class="page-header">
    <h1 class="page-title">Продажи</h1>
</div>
<script>
window._gsPageActions = '<button class="btn btn-primary" onclick="openSaleModal()">+ Продажа</button>';
</script>

<div class="stats-grid" style="grid-template-columns:repeat(3,1fr);margin-bottom:28px">
    <div class="stat-card">
        <div class="stat-label">Всего продаж</div>
        <div class="stat-value"><?= count($sales) ?></div>
        <div class="stat-sub">Сделок</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Ожидают оплаты</div>
        <div class="stat-value" style="color:var(--warning)"><?= number_format($pendingAmount, 0, '.', ' ') ?> ₽</div>
        <div class="stat-sub"><?= count($pendingSales) ?> счетов выставлено</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Общая выручка</div>
        <div class="stat-value accent"><?= number_format($totalRevenue, 0, '.', ' ') ?> ₽</div>
        <div class="stat-sub">Оплаченные продажи</div>
    </div>
</div>

<?php if (empty($sales)): ?>
    <div class="empty-state">
        <p>Продаж пока нет</p>
        <button class="btn btn-primary" style="margin-top:16px" onclick="openSaleModal()">+ Добавить первую</button>
    </div>
<?php else: ?>
<div class="table-wrapper">
    <table>
        <thead>
            <tr>
                <th>Дата</th>
                <th>Товары</th>
                <th>Покупатель</th>
                <th>Кол-во</th>
                <th>Статус</th>
                <th>Сумма</th>
                <th></th>
            </tr>
        </thead>
        <tbody id="sales-tbody" class="m-cards m-cards-sale">
            <?php foreach ($sales as $s):
                $statusMap = [
                    'Оплачено'       => ['badge-done',    'Оплачено'],
                    'Счёт выставлен' => ['badge-waiting', 'Счёт выставлен'],
                    'Отменено'       => ['badge-alert',   'Отменено'],
                ];
                $saleStatus = $s['status'] ?? 'Оплачено';
                [$sCls, $sLabel] = $statusMap[$saleStatus] ?? ['badge-done', 'Оплачено'];
            ?>
            <tr onclick="openSaleDetail(<?= $s['id'] ?>)" style="cursor:pointer">
                <td><?= date('d.m.Y', strtotime($s['sale_date'])) ?></td>
                <td>
                    <div class="items-list">
                        <?php foreach (array_slice($s['items'], 0, 2) as $item): ?>
                        <span class="item-chip"><?= htmlspecialchars($item['product_name']) ?></span>
                        <?php endforeach; ?>
                        <?php if (count($s['items']) > 2): ?>
                        <span class="item-chip" style="color:var(--text-dim)">+<?= count($s['items']) - 2 ?> ещё</span>
                        <?php endif; ?>
                    </div>
                </td>
                <td><?= htmlspecialchars(trim($s['company_type'] . ' ' . $s['buyer'])) ?></td>
                <td><?= $s['total_qty'] ?> шт</td>
                <td>
                    <span class="badge <?= $sCls ?>"><?= $sLabel ?></span>
                    <?php if (!empty($s['source_shipment_id']) && $s['source_shipment_status'] === 'В пути'): ?>
                    <span class="badge badge-reserved" style="margin-left:4px;font-size:10px">В пути</span>
                    <?php endif; ?>
                </td>
                <td style="color:var(--accent);font-weight:500">
                    <?= number_format($s['sale_price'], 0, '.', ' ') ?> ₽
                </td>
                <td class="row-actions">
                    <button class="btn-icon" onclick="event.stopPropagation(); openEditSale(<?= $s['id'] ?>)" title="Изменить">
                        <i data-lucide="pencil" style="width:14px;height:14px"></i>
                    </button>
                    <button class="btn-icon btn-icon-danger" onclick="event.stopPropagation(); deleteSale(<?= $s['id'] ?>)" title="Удалить">
                        <i data-lucide="trash-2" style="width:14px;height:14px"></i>
                    </button>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
