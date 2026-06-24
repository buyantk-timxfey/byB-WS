<?php
// Добавляем поле цены продажи если его нет
try { $pdo->exec("ALTER TABLE products ADD COLUMN sale_price DECIMAL(10,2) DEFAULT NULL"); } catch (PDOException $e) {}

// Добавляем статус Зарезервировано в ENUM склада
try { $pdo->exec("ALTER TABLE warehouse MODIFY COLUMN status ENUM('На складе','Частично продан','Продан','Зарезервировано') DEFAULT 'На складе'"); } catch (PDOException $e) {}

// Исправляем записи с пустым статусом (появились из-за ENUM без Зарезервировано)
try {
    $emptyStatus = $pdo->query("SELECT w.id FROM warehouse w JOIN shipments s ON s.id=w.shipment_id WHERE w.status='' AND s.status!='Завершено'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($emptyStatus as $wId) {
        $hasSales = $pdo->prepare("SELECT COUNT(*) FROM sale_items WHERE warehouse_id=?");
        $hasSales->execute([$wId]);
        if ($hasSales->fetchColumn() > 0) {
            $pdo->prepare("UPDATE warehouse SET status='Зарезервировано' WHERE id=?")->execute([$wId]);
        } else {
            $pdo->prepare("DELETE FROM warehouse WHERE id=?")->execute([$wId]);
        }
    }
} catch (PDOException $e) {}

// Авто-исправление: убрать со склада товары из незавершённых поставок
// (могли попасть туда если статус случайно ставили "Завершено" а потом меняли обратно)
try {
    $badItems = $pdo->query("
        SELECT w.id FROM warehouse w
        JOIN shipments s ON s.id = w.shipment_id
        WHERE s.status != 'Завершено'
          AND w.status NOT IN ('Зарезервировано')
    ")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($badItems as $wId) {
        $hasSales = $pdo->prepare("SELECT COUNT(*) FROM sale_items WHERE warehouse_id=?");
        $hasSales->execute([$wId]);
        if ($hasSales->fetchColumn() > 0) {
            $pdo->prepare("UPDATE warehouse SET status='Зарезервировано' WHERE id=?")->execute([$wId]);
        } else {
            $pdo->prepare("DELETE FROM warehouse WHERE id=?")->execute([$wId]);
        }
    }
} catch (PDOException $e) {}

// Миграция: проставить product_id у warehouse-записей без него, создав продукт при необходимости
try {
    $orphans = $pdo->query("SELECT id, name FROM warehouse WHERE product_id IS NULL AND name IS NOT NULL AND name != ''")->fetchAll();
    foreach ($orphans as $row) {
        $find = $pdo->prepare("SELECT id FROM products WHERE LOWER(TRIM(name)) = LOWER(TRIM(?))");
        $find->execute([$row['name']]);
        $pid = $find->fetchColumn();
        if (!$pid) {
            $pdo->prepare("INSERT INTO products (name, unit) VALUES (?, 'шт')")->execute([$row['name']]);
            $pid = $pdo->lastInsertId();
        }
        $pdo->prepare("UPDATE warehouse SET product_id=? WHERE id=?")->execute([$pid, $row['id']]);
    }
} catch (PDOException $e) {}

$categories = $pdo->query("SELECT * FROM warehouse_categories ORDER BY id")->fetchAll();

// Получаем все товары из номенклатуры с агрегированными остатками
// avg_actual_sale_price — средняя цена из реальных продаж (запасной вариант когда sale_price не задан вручную)
$products = $pdo->query("
    SELECT p.*, wc.name as category_name,
        COALESCE(SUM(w.quantity_left), 0) as stock_left,
        COALESCE(SUM(w.quantity_total), 0) as stock_total,
        COUNT(DISTINCT CASE WHEN w.quantity_left > 0 THEN w.id END) as batches_available,
        COUNT(DISTINCT w.id) as batches_total,
        MIN(w.purchase_price) as min_price,
        MAX(w.purchase_price) as max_price,
        COALESCE(SUM(w.quantity_left * w.purchase_price), 0) as stock_value,
        (SELECT w2.id FROM warehouse w2 WHERE w2.product_id = p.id AND w2.quantity_left > 0 ORDER BY w2.id LIMIT 1) as first_warehouse_id,
        (
            SELECT AVG(CASE WHEN si2.sale_price > 0 THEN si2.sale_price ELSE NULL END)
            FROM sale_items si2
            JOIN sales s2 ON s2.id = si2.sale_id
            JOIN warehouse w3 ON w3.id = si2.warehouse_id
            WHERE w3.product_id = p.id AND s2.status != 'Отменено'
        ) as avg_actual_sale_price
    FROM products p
    LEFT JOIN warehouse_categories wc ON wc.id = p.category_id
    LEFT JOIN warehouse w ON w.product_id = p.id
    GROUP BY p.id
    ORDER BY p.name
")->fetchAll();

// Группируем по категориям
$grouped = [];
foreach ($products as $p) {
    $catId = $p['category_id'] ?? 0;
    $catName = $p['category_name'] ?? 'Без категории';
    if (!isset($grouped[$catId])) $grouped[$catId] = ['name' => $catName, 'items' => []];
    $grouped[$catId]['items'][] = $p;
}

$totalProducts = count($products);
$totalStock = array_sum(array_column($products, 'stock_left'));
$totalStockValue = array_sum(array_column($products, 'stock_value'));
$zeroStockCount = count(array_filter($products, fn($p) => (int)$p['stock_left'] === 0 && (int)$p['stock_total'] > 0));
$activeCount = count(array_filter($products, fn($p) => (int)$p['stock_left'] > 0));

// Зарезервированные позиции (из поставок "В пути")
$reservedItems = $pdo->query("
    SELECT w.*, s.eta, s.status as shipment_status,
        COALESCE(c.company_type,'') as supplier_type,
        COALESCE(c.name,'—') as supplier_name,
        (w.quantity_total - w.quantity_left) as qty_sold,
        (SELECT sh2.status FROM shipments sh2 WHERE sh2.id = w.shipment_id) as shipment_status2
    FROM warehouse w
    LEFT JOIN shipments s ON s.id = w.shipment_id
    LEFT JOIN counterparties c ON c.id = s.counterparty_id
    WHERE w.status = 'Зарезервировано'
    ORDER BY s.eta ASC
")->fetchAll();
?>

<div class="page-header">
    <h1 class="page-title">Склад — Номенклатура</h1>
</div>
<script>
window._gsPageActions = ''
    + '<button class="btn btn-ghost" onclick="openManageCategories()"><i data-lucide="settings" style="width:13px;height:13px"></i> Категории</button>'
    + '<button class="btn btn-ghost" onclick="openAddProduct()"><i data-lucide="plus" style="width:13px;height:13px"></i> Позиция</button>'
    + '<button class="btn btn-primary" onclick="openSaleModal()">+ Продажа</button>';
</script>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:24px">
    <div class="stat-card">
        <div class="stat-label">Позиций в наличии</div>
        <div class="stat-value" style="color:var(--success)"><?= $activeCount ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Позиций продано</div>
        <div class="stat-value" style="color:var(--text-muted)"><?= $zeroStockCount ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Всего остаток</div>
        <div class="stat-value"><?= number_format($totalStock, 0, '.', ' ') ?> шт</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Стоимость склада</div>
        <div class="stat-value" style="color:var(--accent)"><?= number_format($totalStockValue, 0, '.', ' ') ?> ₽</div>
    </div>
</div>

<div style="display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap;align-items:center">
    <button class="tab active" id="wh-tab-all" onclick="filterWarehouseCategory(0, this)">
        Все <span style="opacity:.6;font-size:11px">(<?= $totalProducts ?>)</span>
    </button>
    <?php foreach ($categories as $cat):
        $catCount = count(array_filter($products, fn($p) => $p['category_id'] == $cat['id']));
    ?>
    <button class="tab" data-cat-id="<?= $cat['id'] ?>" onclick="filterWarehouseCategory(<?= $cat['id'] ?>, this)">
        <?= htmlspecialchars($cat['name']) ?>
        <span style="opacity:.6;font-size:11px">(<?= $catCount ?>)</span>
    </button>
    <?php endforeach; ?>
    <button class="tab" id="wh-tab-reserved" onclick="filterWarehouseReserved(this)" style="<?= !empty($reservedItems) ? 'color:#A78BFA' : '' ?>">
        Зарезервировано<?php if (!empty($reservedItems)): ?>
        <span style="background:#A78BFA;color:#fff;font-size:10px;font-weight:700;padding:1px 5px;border-radius:10px;margin-left:2px"><?= count($reservedItems) ?></span>
        <?php endif; ?>
    </button>
</div>

<!-- Секция зарезервированных позиций (показывается только по вкладке) -->
<div id="wh-reserved-section" style="display:none">
    <?php if (empty($reservedItems)): ?>
    <div class="empty-state" style="padding:40px 16px">
        <p style="color:var(--text-muted)">Нет зарезервированных позиций</p>
        <p style="font-size:12px;margin-top:8px;color:var(--text-dim)">Здесь появятся товары из поставок «В пути», проданные до прихода</p>
    </div>
    <?php else: ?>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Товар</th>
                    <th>Поставка / ETA</th>
                    <th>Поставщик</th>
                    <th>Продано / Всего</th>
                    <th>Цена закупки</th>
                    <th></th>
                </tr>
            </thead>
            <tbody class="m-cards m-cards-resv">
                <?php foreach ($reservedItems as $r): ?>
                <tr style="cursor:pointer" onclick="openWarehouseItem(<?= $r['id'] ?>)">
                    <td style="font-weight:500"><?= htmlspecialchars($r['name']) ?></td>
                    <td>
                        <div style="font-size:13px">Поставка #<?= $r['shipment_id'] ?></div>
                        <?php if ($r['eta']): ?>
                        <div style="font-size:11px;color:var(--text-muted)">ETA: <?= date('d.m.Y', strtotime($r['eta'])) ?></div>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:13px;color:var(--text-muted)"><?= htmlspecialchars($r['supplier_type'] . ' ' . $r['supplier_name']) ?></td>
                    <td class="m-cell-label" data-l="Продано / всего">
                        <span style="color:var(--danger)"><?= $r['qty_sold'] ?> шт</span>
                        <span style="color:var(--text-dim)"> / <?= $r['quantity_total'] ?> шт</span>
                    </td>
                    <td class="m-cell-label" data-l="Закупка" style="font-size:13px;color:var(--text-muted)"><?= number_format($r['purchase_price'], 0, '.', ' ') ?> ₽</td>
                    <td>
                        <span class="badge badge-reserved">Зарезервировано</span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php if (empty($products)): ?>
<div class="empty-state">
    <p>Номенклатура пуста</p>
    <p style="font-size:12px;margin-top:8px;color:var(--text-dim)">Товары появляются автоматически при завершении поставки или добавьте вручную</p>
    <button class="btn btn-primary" style="margin-top:16px" onclick="openAddProduct()">+ Добавить позицию</button>
</div>
<?php else: ?>

<?php foreach ($grouped as $catId => $group): ?>
<div class="warehouse-category-group" data-cat-id="<?= $catId ?>">
    <div style="display:flex;align-items:center;gap:8px;margin:20px 0 10px">
        <span style="font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.8px;color:var(--text-muted)">
            <?= htmlspecialchars($group['name']) ?>
        </span>
        <span style="font-size:11px;color:var(--text-muted);opacity:.6"><?= count($group['items']) ?> поз.</span>
    </div>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Товар</th>
                    <th>Остаток</th>
                    <th>Партии</th>
                    <th>Цена закупки</th>
                    <th>Цена продажи</th>
                    <th>Маржа</th>
                    <th>Статус</th>
                    <th></th>
                </tr>
            </thead>
            <tbody class="warehouse-tbody m-cards m-cards-wh">
                <?php foreach ($group['items'] as $p):
                    $stockLeft = (int)$p['stock_left'];
                    $stockTotal = (int)$p['stock_total'];
                    if ($stockLeft <= 0 && $stockTotal > 0) { $badgeCls = 'badge-sold'; $badgeLabel = 'Продан'; }
                    elseif ($stockLeft > 0 && $stockLeft < $stockTotal) { $badgeCls = 'badge-partial'; $badgeLabel = 'Частично'; }
                    elseif ($stockLeft > 0) { $badgeCls = 'badge-instock'; $badgeLabel = 'На складе'; }
                    else { $badgeCls = 'badge-waiting'; $badgeLabel = 'Нет поставок'; }

 $minPrice = $p['min_price'] ?? 0;
                    $maxPrice = $p['max_price'] ?? 0;
                    $priceStr = $minPrice == $maxPrice
                        ? number_format($minPrice, 0, '.', ' ') . ' ₽'
                        : number_format($minPrice, 0, '.', ' ') . ' — ' . number_format($maxPrice, 0, '.', ' ') . ' ₽';
                ?>
<?php
    // Цена продажи: сначала ручная из номенклатуры, затем средняя из реальных продаж
    $salePrice = floatval($p['sale_price'] ?? 0);
    if ($salePrice <= 0) $salePrice = floatval($p['avg_actual_sale_price'] ?? 0);
    $salePriceSource = (floatval($p['sale_price'] ?? 0) > 0) ? 'manual' : (($salePrice > 0) ? 'avg' : 'none');

    $avgBuyPrice = $stockTotal > 0 ? (floatval($p['min_price'] ?? 0) + floatval($p['max_price'] ?? 0)) / 2 : 0;
    $margin = ($salePrice > 0 && $avgBuyPrice > 0) ? round(($salePrice - $avgBuyPrice) / $salePrice * 100) : null;
    $marginColor = $margin !== null ? ($margin >= 30 ? 'var(--success)' : ($margin >= 10 ? 'var(--accent)' : 'var(--danger)')) : '';
?>
                <tr class="warehouse-row" onclick="openProductDetail(<?= $p['id'] ?>)" style="cursor:pointer">
                    <td>
                        <div style="font-weight:500"><?= htmlspecialchars($p['name']) ?></div>
                        <?php if ($p['comment']): ?>
                        <div style="font-size:11px;color:var(--text-muted)"><?= htmlspecialchars(mb_substr($p['comment'], 0, 50)) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="m-cell-label" data-l="Остаток"><?= $stockLeft ?> <?= htmlspecialchars($p['unit'] ?? 'шт') ?></td>
                    <td class="m-cell-label" data-l="Партии">
                        <?php if ($p['batches_total'] > 0): ?>
                        <span style="color:var(--text-muted);font-size:12px"><?= $p['batches_available'] ?> из <?= $p['batches_total'] ?> активны</span>
                        <?php else: ?>
                        <span style="color:var(--text-dim);font-size:12px">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="m-cell-label" data-l="Закупка" style="font-size:13px;color:var(--text-muted)"><?= $stockTotal > 0 ? $priceStr : '—' ?></td>
                    <td class="m-cell-label" data-l="Продажа" style="font-size:13px;color:var(--text-muted)">
                        <?php if ($salePrice > 0): ?>
                            <?= number_format($salePrice, 0, '.', ' ') ?> ₽
                            <?php if ($salePriceSource === 'avg'): ?>
                                <span style="font-size:10px;color:var(--text-dim)" title="Средняя из продаж">~</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span style="color:var(--text-dim)">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="m-cell-label" data-l="Маржа" style="font-size:12px;font-weight:600;color:<?= $marginColor ?>">
                        <?= $margin !== null ? $margin . '%' : '<span style="color:var(--text-dim)">—</span>' ?>
                    </td>
                    <td><span class="badge <?= $badgeCls ?>"><?= $badgeLabel ?></span></td>
                    <td class="row-actions">
                        <button class="btn-icon" onclick="event.stopPropagation(); openEditProduct(<?= $p['id'] ?>)" title="Изменить">
                            <i data-lucide="pencil" style="width:14px;height:14px"></i>
                        </button>
                        <?php if ($stockLeft > 0 && $p['first_warehouse_id']): ?>
                        <button class="btn-icon btn-icon-primary" onclick="event.stopPropagation(); openSaleModal(<?= $p['first_warehouse_id'] ?>)" title="Продать">
                            <i data-lucide="shopping-cart" style="width:14px;height:14px"></i>
                        </button>
                        <?php endif; ?>
                        <button class="btn-icon btn-icon-danger" onclick="event.stopPropagation(); deleteProduct(<?= $p['id'] ?>)" title="Удалить">
                            <i data-lucide="trash-2" style="width:14px;height:14px"></i>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<script>
function filterWarehouseSearch(q) {
    q = q.toLowerCase();
    document.querySelectorAll('.warehouse-category-group').forEach(group => {
        let hasVisible = false;
        group.querySelectorAll('.warehouse-row').forEach(row => {
            const show = row.textContent.toLowerCase().includes(q);
            row.style.display = show ? '' : 'none';
            if (show) hasVisible = true;
        });
        group.style.display = hasVisible ? '' : 'none';
    });
}

function filterWarehouseCategory(catId, btn) {
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById('wh-reserved-section').style.display = 'none';
    document.querySelectorAll('.warehouse-category-group').forEach(g => {
        g.style.display = (catId === 0 || g.dataset.catId == catId) ? '' : 'none';
    });
}

function filterWarehouseReserved(btn) {
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
    btn.classList.add('active');
    document.querySelectorAll('.warehouse-category-group').forEach(g => g.style.display = 'none');
    document.getElementById('wh-reserved-section').style.display = '';
}

async function openAddProduct() {
    const cats = await api('warehouse.php?categories=1');
    const catOptions = cats.map(c => `<option value="${c.id}">${c.name}</option>`).join('');
    openModal(`
        <div class="modal-header"><span class="modal-title">Новая позиция</span><button class="btn-close" onclick="closeModal()"><i data-lucide="x" style="width:15px;height:15px"></i></button></div>
        <div class="modal-body">
            <div class="form-group"><label class="form-label">Название</label><input type="text" class="form-control" id="prod-name" placeholder="Название товара"></div>
            <div class="form-group"><label class="form-label">Категория</label>
                <select class="form-control" id="prod-cat"><option value="">— Без категории —</option>${catOptions}</select>
            </div>
            <div class="form-group"><label class="form-label">Единица измерения</label>
                <select class="form-control" id="prod-unit">
                    <option value="шт">шт</option><option value="кг">кг</option><option value="м">м</option><option value="л">л</option><option value="уп">уп</option>
                </select>
            </div>
            <div class="form-group"><label class="form-label">Цена продажи (₽)</label><input type="number" class="form-control" id="prod-sale-price" placeholder="Необязательно" min="0"></div>
            <div class="form-group"><label class="form-label">Комментарий</label><input type="text" class="form-control" id="prod-comment" placeholder="Необязательно"></div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" onclick="closeModal()">Отмена</button>
            <button class="btn btn-primary" onclick="saveProduct()">Сохранить</button>
        </div>
    `);
}

async function saveProduct() {
    const name = document.getElementById('prod-name').value.trim();
    if (!name) { showToast('Укажите название', 'error'); return; }
    await api('products.php', 'POST', {
        name, category_id: document.getElementById('prod-cat').value || null,
        unit: document.getElementById('prod-unit').value,
        sale_price: parseFloat(document.getElementById('prod-sale-price').value) || null,
        comment: document.getElementById('prod-comment').value.trim() || null
    });
    showToast('Позиция добавлена'); closeModal(); setTimeout(reloadPage, 400);
}

async function openEditProduct(id) {
    const [products, cats] = await Promise.all([api(`products.php?id=${id}`), api('warehouse.php?categories=1')]);
    const p = products;
    const catOptions = cats.map(c => `<option value="${c.id}" ${c.id==p.category_id?'selected':''}>${c.name}</option>`).join('');
    openModal(`
        <div class="modal-header"><span class="modal-title">Редактировать позицию</span><button class="btn-close" onclick="closeModal()"><i data-lucide="x" style="width:15px;height:15px"></i></button></div>
        <div class="modal-body">
            <div class="form-group"><label class="form-label">Название</label><input type="text" class="form-control" id="edit-prod-name" value="${p.name}"></div>
            <div class="form-group"><label class="form-label">Категория</label>
                <select class="form-control" id="edit-prod-cat"><option value="">— Без категории —</option>${catOptions}</select>
            </div>
            <div class="form-group"><label class="form-label">Единица измерения</label>
                <select class="form-control" id="edit-prod-unit">
                    ${['шт','кг','м','л','уп'].map(u => `<option value="${u}" ${p.unit===u?'selected':''}>${u}</option>`).join('')}
                </select>
            </div>
            <div class="form-group"><label class="form-label">Цена продажи (₽)</label><input type="number" class="form-control" id="edit-prod-sale-price" value="${p.sale_price||''}" placeholder="Необязательно" min="0"></div>
            <div class="form-group"><label class="form-label">Комментарий</label><input type="text" class="form-control" id="edit-prod-comment" value="${p.comment||''}"></div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" onclick="closeModal()">Отмена</button>
            <button class="btn btn-primary" onclick="updateProduct(${id})">Сохранить</button>
        </div>
    `);
}

async function updateProduct(id) {
    const name = document.getElementById('edit-prod-name').value.trim();
    if (!name) { showToast('Укажите название', 'error'); return; }
    await api('products.php', 'PUT', {
        id, name, category_id: document.getElementById('edit-prod-cat').value || null,
        unit: document.getElementById('edit-prod-unit').value,
        sale_price: parseFloat(document.getElementById('edit-prod-sale-price').value) || null,
        comment: document.getElementById('edit-prod-comment').value.trim() || null
    });
    showToast('Сохранено'); closeModal(); setTimeout(reloadPage, 400);
}

function deleteProduct(id) {
    confirmAction('Удалить позицию из номенклатуры? Партии на складе останутся.', async () => {
        await api(`products.php?id=${id}`, 'DELETE');
        showToast('Позиция удалена'); setTimeout(reloadPage, 400);
    });
}

async function openProductDetail(id) {
    const p = await api(`products.php?id=${id}`);
    const statusMap = {'На складе':'badge-instock','Частично продан':'badge-partial','Продан':'badge-sold'};

    const batchesHtml = (p.shipments||[]).length > 0
        ? p.shipments.map(b => `
            <tr onclick="openWarehouseItem(${b.warehouse_id})" style="cursor:pointer">
                <td><span style="color:var(--accent)">#${b.shipment_id}</span> · ${formatDate(b.order_date)}</td>
                <td>${b.supplier_type} ${b.supplier_name}</td>
                <td>${b.quantity_left} / ${b.quantity_total} шт</td>
                <td>${formatPrice(b.purchase_price)}</td>
                <td><span class="badge ${statusMap[b.status]||'badge-instock'}">${b.status}</span></td>
                <td><button class="btn-icon" onclick="event.stopPropagation();openProductChain(${b.warehouse_id})"><i data-lucide="link" style="width:13px;height:13px"></i></button></td>
            </tr>`).join('')
        : `<tr><td colspan="6" style="text-align:center;color:var(--text-muted);padding:20px">Поставок нет</td></tr>`;

    const salesHtml = (p.sales||[]).length > 0
        ? p.sales.map(s => `
            <tr>
                <td>${formatDate(s.sale_date)}</td>
                <td>${s.buyer}</td>
                <td>${s.quantity} шт</td>
                <td style="color:var(--success)">${formatPrice(s.sale_price)}</td>
            </tr>`).join('')
        : `<tr><td colspan="4" style="text-align:center;color:var(--text-muted);padding:20px">Продаж нет</td></tr>`;

    const avgBuy = p.shipments && p.shipments.length > 0
        ? p.shipments.reduce((s, b) => s + parseFloat(b.purchase_price||0), 0) / p.shipments.length : 0;
    const saleP = parseFloat(p.sale_price||0);
    const marginPct = saleP > 0 && avgBuy > 0 ? Math.round((saleP - avgBuy) / saleP * 100) : null;
    const marginColor = marginPct !== null ? (marginPct >= 30 ? 'var(--success)' : (marginPct >= 10 ? 'var(--accent)' : 'var(--danger)')) : 'var(--text-muted)';
    const stockVal = (p.shipments||[]).reduce((s, b) => s + b.quantity_left * b.purchase_price, 0);

    openModal(`
        <div class="modal-header">
            <span class="modal-title">${p.name}</span>
            <button class="btn-close" onclick="closeModal()"><i data-lucide="x" style="width:15px;height:15px"></i></button>
        </div>
        <div class="modal-body">
            <div class="detail-grid" style="margin-bottom:20px">
                <div class="detail-item"><span class="detail-label">Остаток</span><span class="detail-value" style="color:var(--accent)">${p.stock_left} ${p.unit||'шт'}</span></div>
                <div class="detail-item"><span class="detail-label">Категория</span><span class="detail-value">${p.category_name||'—'}</span></div>
                <div class="detail-item"><span class="detail-label">Всего закуплено</span><span class="detail-value">${p.stock_total} ${p.unit||'шт'}</span></div>
                <div class="detail-item"><span class="detail-label">Партий</span><span class="detail-value">${p.shipments_count}</span></div>
                <div class="detail-item"><span class="detail-label">Цена продажи</span><span class="detail-value">${saleP > 0 ? formatPrice(saleP) : '—'}</span></div>
                <div class="detail-item"><span class="detail-label">Маржа</span><span class="detail-value" style="color:${marginColor};font-weight:600">${marginPct !== null ? marginPct + '%' : '—'}</span></div>
                <div class="detail-item"><span class="detail-label">Стоимость остатка</span><span class="detail-value">${formatPrice(stockVal)}</span></div>
            </div>
            <h4 style="font-size:11px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.8px;margin-bottom:10px">Партии (поставки)</h4>
            <div class="table-wrapper" style="margin-bottom:20px">
                <table><thead><tr><th>Поставка</th><th>Поставщик</th><th>Остаток</th><th>Цена</th><th>Статус</th><th></th></tr></thead>
                <tbody>${batchesHtml}</tbody></table>
            </div>
            <h4 style="font-size:11px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.8px;margin-bottom:10px">История продаж</h4>
            <div class="table-wrapper">
                <table><thead><tr><th>Дата</th><th>Покупатель</th><th>Кол-во</th><th>Сумма</th></tr></thead>
                <tbody>${salesHtml}</tbody></table>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" onclick="closeModal()">Закрыть</button>
            ${p.stock_left > 0 ? `<button class="btn btn-primary" onclick="closeModal(); openSaleModal()">Продать</button>` : ''}
        </div>
    `);
    if (window.lucide) lucide.createIcons();
}

async function openManageCategories() {
    const cats = await api('warehouse.php?categories=1');
    const listHtml = cats.filter(c => c.id > 1).map(c => `
        <div style="display:flex;align-items:center;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--border)">
            <span>${c.name}</span>
            <button class="btn-icon btn-icon-danger" onclick="deleteWarehouseCategory(${c.id})"><i data-lucide="trash-2" style="width:13px;height:13px"></i></button>
        </div>`).join('');
    openModal(`
        <div class="modal-header"><span class="modal-title">Категории склада</span><button class="btn-close" onclick="closeModal()"><i data-lucide="x" style="width:15px;height:15px"></i></button></div>
        <div class="modal-body">
            <div style="margin-bottom:16px">${listHtml||'<p style="color:var(--text-muted)">Нет категорий</p>'}</div>
            <div class="form-group"><label class="form-label">Новая категория</label>
                <div style="display:flex;gap:8px">
                    <input type="text" class="form-control" id="new-cat-name" placeholder="Название">
                    <button class="btn btn-primary" onclick="addWarehouseCategory()">+ Добавить</button>
                </div>
            </div>
        </div>
        <div class="modal-footer"><button class="btn btn-ghost" onclick="closeModal()">Закрыть</button></div>
    `);
    if (window.lucide) lucide.createIcons();
}

async function addWarehouseCategory() {
    const name = document.getElementById('new-cat-name').value.trim();
    if (!name) { showToast('Введите название', 'error'); return; }
    await api('warehouse.php', 'POST', { action: 'add_category', name });
    showToast('Категория добавлена'); closeModal(); setTimeout(reloadPage, 400);
}

async function deleteWarehouseCategory(id) {
    confirmAction('Удалить категорию?', async () => {
        await api(`warehouse.php?category=${id}`, 'DELETE');
        showToast('Категория удалена'); closeModal(); setTimeout(reloadPage, 400);
    });
}
</script>
