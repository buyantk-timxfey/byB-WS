<?php
$finTab = isset($_GET['fintab']) ? $_GET['fintab'] : 'bank';
// $filterActive / $currentMonth / $currentYear задаёт глобальный фильтр в index.php

// ── Агрегированные данные за все месяцы (3 запроса вместо 48) ──────────────
$incomeByYM = $purchaseByYM = $bizByYM = [];

$s = $pdo->query("SELECT YEAR(sale_date) y, MONTH(sale_date) m, COALESCE(SUM(sale_price),0) v, COUNT(*) cnt FROM sales WHERE status='Оплачено' GROUP BY y,m");
foreach ($s->fetchAll() as $row) { $incomeByYM[$row['y']][$row['m']] = [(float)$row['v'], (int)$row['cnt']]; }

// Правильный расчёт стоимости поставок без дублирования carrier_cost.
// Только завершённые: пока товар едет — это не расход, а замороженные деньги
// (видны отдельно в карточке «В пути»).
$s = $pdo->query("
    SELECT YEAR(sh.order_date) y, MONTH(sh.order_date) m,
           COALESCE(SUM(items_cost),0) + COALESCE(SUM(sh.carrier_cost),0) v
    FROM shipments sh
    LEFT JOIN (
        SELECT shipment_id, SUM(quantity*purchase_price) items_cost FROM shipment_items GROUP BY shipment_id
    ) si ON si.shipment_id = sh.id
    WHERE sh.status = 'Завершено'
    GROUP BY y, m");
foreach ($s->fetchAll() as $row) { $purchaseByYM[$row['y']][$row['m']] = (float)$row['v']; }

$s = $pdo->query("SELECT YEAR(expense_date) y, MONTH(expense_date) m, COALESCE(SUM(amount),0) v FROM business_expenses GROUP BY y,m");
foreach ($s->fetchAll() as $row) { $bizByYM[$row['y']][$row['m']] = (float)$row['v']; }

// ── Вспомогательная функция расчёта показателей ──────────────────────────────
function calcMetrics($income, $cnt, $purchase, $biz) {
    $gross  = $income - $purchase - $biz;
    $tax    = $gross > 0 ? round($gross * 0.16) : 0;
    $net    = $gross - $tax;
    $salary = $net > 0 ? round($net * 0.20) : 0;
    $margin = $income > 0 ? round($net / $income * 100, 1) : 0;
    $roi    = $purchase > 0 ? round(($income - $purchase) / $purchase * 100, 1) : 0;
    $avg    = $cnt > 0 ? round($income / $cnt) : 0;
    return compact('income','purchase','biz','gross','tax','net','salary','margin','roi','cnt','avg');
}

if ($filterActive) {
    $income   = $incomeByYM[$currentYear][$currentMonth][0] ?? 0;
    $cnt      = $incomeByYM[$currentYear][$currentMonth][1] ?? 0;
    $purchase = $purchaseByYM[$currentYear][$currentMonth] ?? 0;
    $biz      = $bizByYM[$currentYear][$currentMonth] ?? 0;
    $r        = calcMetrics($income, $cnt, $purchase, $biz);

    $pm = $currentMonth == 1 ? 12 : $currentMonth - 1;
    $py = $currentMonth == 1 ? $currentYear - 1 : $currentYear;
    $prevIncome = $incomeByYM[$py][$pm][0] ?? 0;
    $incomeGrowth = $prevIncome > 0 ? round(($r['income'] - $prevIncome) / $prevIncome * 100, 1) : null;
} else {
    // Всё время — суммируем из агрегатов
    $income = $cnt = $purchase = $biz = 0;
    foreach ($incomeByYM as $yr => $months) foreach ($months as $mo => [$v,$c]) { $income += $v; $cnt += $c; }
    foreach ($purchaseByYM as $yr => $months) foreach ($months as $mo => $v) $purchase += $v;
    foreach ($bizByYM as $yr => $months) foreach ($months as $mo => $v) $biz += $v;
    $r = calcMetrics($income, $cnt, $purchase, $biz);
    $incomeGrowth = null;
}

// ── История по 12 месяцам (без SQL-запросов) ────────────────────────────────
$metricsHistory = [];
for ($i = 11; $i >= 0; $i--) {
    $ts = strtotime("-{$i} months");
    $sm = (int)date('n', $ts); $sy = (int)date('Y', $ts);
    $inc  = $incomeByYM[$sy][$sm][0] ?? 0;
    $c    = $incomeByYM[$sy][$sm][1] ?? 0;
    $pur  = $purchaseByYM[$sy][$sm] ?? 0;
    $bz   = $bizByYM[$sy][$sm] ?? 0;
    $mh   = calcMetrics($inc, $c, $pur, $bz);
    $mh['label'] = $monthNames[$sm-1] . ' ' . $sy;
    $metricsHistory[] = $mh;
}

// ── Данные для вкладки "Итоги" (за всё время) ───────────────────────────────
$allIncome   = $allCnt = $allPurchase = $allBiz = 0;
foreach ($incomeByYM  as $yr => $months) foreach ($months as $mo => [$v,$c]) { $allIncome += $v; $allCnt += $c; }
foreach ($purchaseByYM as $yr => $months) foreach ($months as $mo => $v)     { $allPurchase += $v; }
foreach ($bizByYM     as $yr => $months) foreach ($months as $mo => $v)      { $allBiz += $v; }

// ── Помесячная таблица (для правой колонки Итогов) ───────────────────────────
$monthlyRows = [];
// Собираем все уникальные год-месяц ключи
$allYM = [];
foreach ($incomeByYM  as $yr => $months) foreach ($months as $mo => $_) $allYM[sprintf('%04d-%02d', $yr, $mo)] = [$yr, $mo];
foreach ($purchaseByYM as $yr => $months) foreach ($months as $mo => $_) $allYM[sprintf('%04d-%02d', $yr, $mo)] = [$yr, $mo];
foreach ($bizByYM     as $yr => $months) foreach ($months as $mo => $_) $allYM[sprintf('%04d-%02d', $yr, $mo)] = [$yr, $mo];
krsort($allYM); // новые сверху (корректно, т.к. ключи zero-padded)
foreach ($allYM as $ym => [$yr, $mo]) {
    $inc  = $incomeByYM[$yr][$mo][0]  ?? 0;
    $pur  = $purchaseByYM[$yr][$mo]   ?? 0;
    $biz  = $bizByYM[$yr][$mo]        ?? 0;
    $gross = $inc - $pur - $biz;
    $tax  = $gross > 0 ? round($gross * 0.16) : 0;
    $net  = $gross - $tax;
    $margin = $inc > 0 ? round($net / $inc * 100, 1) : 0;
    $ruMonthsFull = ['','Январь','Февраль','Март','Апрель','Май','Июнь','Июль','Август','Сентябрь','Октябрь','Ноябрь','Декабрь'];
    $monthlyRows[] = [
        'key'    => $ym,
        'label'  => $ruMonthsFull[$mo] . ' ' . $yr,
        'year'   => $yr,
        'income' => $inc,
        'pur'    => $pur,
        'biz'    => $biz,
        'net'    => $net,
        'margin' => $margin,
    ];
}
$allR       = calcMetrics($allIncome, $allCnt, $allPurchase, $allBiz);
$allGross   = $allR['gross'];
$allTax     = $allR['tax'];
$allNet     = $allR['net'];
$allMargin  = $allR['margin'];
$allRoi     = $allR['roi'];
$allAvg     = $allR['avg'];
$netPct     = $allIncome > 0 ? min(100, max(0, round($allNet / $allIncome * 100))) : 0;

// Кол-во уникальных поставщиков
$allSuppliers = (int)$pdo->query("SELECT COUNT(DISTINCT counterparty_id) FROM shipments WHERE counterparty_id IS NOT NULL")->fetchColumn();

// Заморожено на складе (закупочная стоимость остатков)
$warehouseFrozen = (float)$pdo->query("SELECT COALESCE(SUM(quantity_left * purchase_price),0) FROM warehouse WHERE quantity_left > 0")->fetchColumn();

// В пути сейчас (сумма поставок со статусом "В пути") — carrier_cost через подзапрос, без дублирования
$inTransit = (float)$pdo->query("
    SELECT COALESCE(SUM(items_cost),0) + COALESCE(SUM(sh.carrier_cost),0)
    FROM shipments sh
    LEFT JOIN (
        SELECT shipment_id, SUM(quantity * purchase_price) items_cost
        FROM shipment_items GROUP BY shipment_id
    ) si ON si.shipment_id = sh.id
    WHERE sh.status = 'В пути'
")->fetchColumn();

// ── Прогноз ──────────────────────────────────────────────────────────────────
$fcNowY  = (int)date('Y');
$fcNowM  = (int)date('n');
$fcDay   = (int)date('j');
$fcDays  = (int)date('t');

// 1. Run-rate текущего месяца: темп с начала месяца, растянутый на весь месяц
$fcCurIncome = $incomeByYM[$fcNowY][$fcNowM][0] ?? 0;
$fcCurCnt    = $incomeByYM[$fcNowY][$fcNowM][1] ?? 0;
$fcCurPur    = $purchaseByYM[$fcNowY][$fcNowM] ?? 0;
$fcCurBiz    = $bizByYM[$fcNowY][$fcNowM] ?? 0;
$fcFactor    = $fcDays / max(1, $fcDay);
$fcRunRate   = calcMetrics(
    round($fcCurIncome * $fcFactor),
    (int)round($fcCurCnt * $fcFactor),
    round($fcCurPur * $fcFactor),
    round($fcCurBiz * $fcFactor)
);
// Первые дни месяца run-rate случаен (1 день × 30) — помечаем как ненадёжный
$fcEarlyMonth = $fcDay < 5;

// 2. Следующий месяц: взвешенное среднее последних 3 ПОЛНЫХ месяцев (свежие весомее)
$fcHist = [];
for ($i = 1; $i <= 3; $i++) {
    $ts = strtotime("-{$i} months");
    $m = (int)date('n', $ts); $y = (int)date('Y', $ts);
    $inc = $incomeByYM[$y][$m][0] ?? 0;
    $pur = $purchaseByYM[$y][$m]  ?? 0;
    $biz = $bizByYM[$y][$m]       ?? 0;
    if ($inc > 0 || $pur > 0 || $biz > 0) {
        $fcHist[] = ['income' => $inc, 'pur' => $pur, 'biz' => $biz, 'weight' => 4 - $i];
    }
}
$fcNext = null; $fcNextMin = $fcNextMax = 0;
if (!empty($fcHist)) {
    $wSum = array_sum(array_column($fcHist, 'weight'));
    $wInc = $wPur = $wBiz = 0;
    foreach ($fcHist as $h) {
        $wInc += $h['income'] * $h['weight'];
        $wPur += $h['pur']    * $h['weight'];
        $wBiz += $h['biz']    * $h['weight'];
    }
    $fcNext    = calcMetrics(round($wInc / $wSum), 0, round($wPur / $wSum), round($wBiz / $wSum));
    $fcNextMin = min(array_column($fcHist, 'income'));
    $fcNextMax = max(array_column($fcHist, 'income'));
}

// 3. Потенциал: товар на складе и в пути × средняя наценка за всю историю
$fcMarkup      = $allPurchase > 0 ? $allIncome / $allPurchase : 0;
$fcPotWarehouse = round($warehouseFrozen * $fcMarkup);
$fcPotTransit   = round($inTransit * $fcMarkup);
$fcPotTotal     = $fcPotWarehouse + $fcPotTransit;
$fcPotGross     = $fcPotTotal - round($warehouseFrozen + $inTransit);

// Данные графика: 12 мес факта + 3 мес прогноза (пунктир)
$fcChartLabels = array_column($metricsHistory, 'label');
$fcChartIncome = array_column($metricsHistory, 'income');
$fcChartNet    = array_column($metricsHistory, 'net');
$fcForecastLabels = [];
for ($i = 1; $i <= 3; $i++) {
    $ts = strtotime("+{$i} months");
    $fcForecastLabels[] = $monthNames[(int)date('n', $ts) - 1] . ' ' . date('Y', $ts);
}

// ── Расходы предпринимателя ──
$bizExpSql = "SELECT be.*, ec.name as category_name, ba.name as account_name
    FROM business_expenses be
    LEFT JOIN expense_categories ec ON ec.id = be.category_id
    LEFT JOIN bank_accounts ba ON ba.id = be.account_id";
if ($filterActive) {
    $bizExpSql .= " WHERE MONTH(be.expense_date) = ? AND YEAR(be.expense_date) = ?";
    $bizExpStmt = $pdo->prepare($bizExpSql . " ORDER BY be.expense_date DESC");
    $bizExpStmt->execute([$currentMonth, $currentYear]);
} else {
    $bizExpStmt = $pdo->query($bizExpSql . " ORDER BY be.expense_date DESC");
}
$bizExpenses = $bizExpStmt->fetchAll();

// Все категории с суммами
$allCats = []; $totalBizAll = 0;
foreach ($bizExpenses as $e) {
    $cat = $e['category_name'] ?? 'Без категории';
    $allCats[$cat] = ($allCats[$cat] ?? 0) + $e['amount'];
    $totalBizAll += $e['amount'];
}
arsort($allCats);

// ── Селектор периода для вкладок «Банк» и «Расходы предпринимателя» ──────────
// Использует ту же механику что глобальный фильтр (index.php): month/year или period=all.
$finMonthsRu = ['Январь','Февраль','Март','Апрель','Май','Июнь','Июль','Август','Сентябрь','Октябрь','Ноябрь','Декабрь'];
$finSelM = $currentMonth ?? (int)date('n');
$finSelY = $currentYear ?? (int)date('Y');
// Месяцы от текущего назад до апреля 2026 (старт учёта), без будущих
$fy = (int)date('Y'); $fm = (int)date('n');
ob_start(); ?>
<select class="form-control fin-period-select" onchange="setFinPeriod(this.value)" style="width:160px">
    <option value="all" <?= !$filterActive ? 'selected' : '' ?>>Все месяцы</option>
    <?php while ($fy > 2026 || ($fy === 2026 && $fm >= 4)): ?>
    <option value="<?= $fy ?>-<?= $fm ?>" <?= ($filterActive && (int)$finSelM === $fm && (int)$finSelY === $fy) ? 'selected' : '' ?>>
        <?= $finMonthsRu[$fm-1] ?> <?= $fy ?>
    </option>
    <?php $fm--; if ($fm === 0) { $fm = 12; $fy--; } endwhile; ?>
</select>
<?php $finPeriodSelect = ob_get_clean(); ?>

<?php
// ── Калькулятор прибыли: незавершённые поставки с плановыми ценами продажи ──
$calcShipments = [];
$s = $pdo->query("
    SELECT s.id, s.name, s.status, s.order_date, s.carrier_cost,
           COALESCE(c.name, '—') AS counterparty,
           si.id AS item_id, si.name AS item_name, si.quantity, si.purchase_price, si.planned_sale_price
    FROM shipments s
    LEFT JOIN counterparties c ON c.id = s.counterparty_id
    JOIN shipment_items si ON si.shipment_id = s.id
    WHERE s.status <> 'Завершено'
    ORDER BY s.order_date DESC, si.id");
foreach ($s->fetchAll() as $row) {
    $sid = $row['id'];
    if (!isset($calcShipments[$sid])) {
        $calcShipments[$sid] = [
            'id' => $sid, 'name' => $row['name'], 'status' => $row['status'],
            'carrier_cost' => (float)$row['carrier_cost'], 'counterparty' => $row['counterparty'],
            'items' => []
        ];
    }
    $calcShipments[$sid]['items'][] = $row;
}
?>
<div class="page-header"><h1 class="page-title">P&amp;L</h1></div>

<div class="tabs" style="margin-bottom:24px">
    <button class="tab <?= $finTab==='bank'?'active':'' ?>"     onclick="switchFinTab('bank',event)">Банк</button>
    <button class="tab <?= $finTab==='expenses'?'active':'' ?>" onclick="switchFinTab('expenses',event)">Расходы предпринимателя</button>
    <button class="tab <?= $finTab==='salary'?'active':'' ?>"   onclick="switchFinTab('salary',event)">Зарплата</button>
    <button class="tab <?= $finTab==='analytics'?'active':'' ?>" onclick="switchFinTab('analytics',event)">Итоги</button>
    <button class="tab <?= $finTab==='forecast'?'active':'' ?>" onclick="switchFinTab('forecast',event)">Прогноз</button>
    <button class="tab <?= $finTab==='calc'?'active':'' ?>" onclick="switchFinTab('calc',event)">Калькулятор</button>
</div>

<!-- ══════════ КАЛЬКУЛЯТОР ПРИБЫЛИ ══════════ -->
<div id="fin-calc" style="<?= $finTab!=='calc'?'display:none':'' ?>">

    <div style="font-size:12px;color:var(--text-muted);margin-bottom:16px;display:flex;align-items:center;gap:6px">
        <i data-lucide="calculator" style="width:14px;height:14px"></i>
        Плановый доход по незавершённым поставкам. Укажите цену продажи за единицу — значение сохраняется автоматически.
    </div>

    <div class="pnl-cards-row calc-cards-row" style="margin-bottom:20px">
        <div class="stat-card">
            <div class="stat-label">Затраты (товар + доставка)</div>
            <div class="stat-value" id="calc-total-cost">—</div>
            <div class="stat-sub">По всем активным поставкам</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">План продажи</div>
            <div class="stat-value accent" id="calc-total-rev">—</div>
            <div class="stat-sub">По заполненным ценам</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Доход</div>
            <div class="stat-value" id="calc-total-profit">—</div>
            <div class="stat-sub">План продажи − затраты</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Маржа</div>
            <div class="stat-value" id="calc-total-margin">—</div>
            <div class="stat-sub">Доход / план продажи</div>
        </div>
    </div>

    <?php if (empty($calcShipments)): ?>
        <div style="text-align:center;padding:40px;color:var(--text-muted);font-size:13px">Нет активных поставок — все завершены.</div>
    <?php else: foreach ($calcShipments as $cs): ?>
    <div class="pnl-monthly-card calc-shipment" data-carrier="<?= $cs['carrier_cost'] ?>" style="padding:16px;margin-bottom:16px">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:10px;flex-wrap:wrap">
            <div>
                <span style="font-weight:600"><?= htmlspecialchars($cs['name'] ?: 'Поставка #'.$cs['id']) ?></span>
                <span style="font-size:12px;color:var(--text-muted);margin-left:8px"><?= htmlspecialchars($cs['counterparty']) ?></span>
            </div>
            <span style="font-size:12px;color:var(--text-muted)"><?= htmlspecialchars($cs['status']==='⚠️'?'Форс-мажор':$cs['status']) ?> · доставка <?= number_format($cs['carrier_cost'],0,'.','&nbsp;') ?> ₽</span>
        </div>
        <div class="table-wrapper">
            <table>
                <thead><tr><th>Товар</th><th>Кол-во</th><th>Закупка/шт</th><th>Продажа/шт</th><th>Затраты</th><th>Выручка</th><th>Доход</th><th>Маржа</th></tr></thead>
                <tbody>
                <?php foreach ($cs['items'] as $it): ?>
                    <tr>
                        <td><?= htmlspecialchars($it['item_name']) ?></td>
                        <td><?= (int)$it['quantity'] ?> шт</td>
                        <td><?= number_format($it['purchase_price'],0,'.','&nbsp;') ?> ₽</td>
                        <td><input type="number" class="form-control calc-plan" style="width:110px;padding:4px 8px;font-size:13px" min="0" step="0.01"
                                   value="<?= $it['planned_sale_price'] !== null ? htmlspecialchars(rtrim(rtrim(number_format($it['planned_sale_price'],2,'.',''),'0'),'.')) : '' ?>"
                                   placeholder="₽" data-item-id="<?= (int)$it['item_id'] ?>" data-qty="<?= (int)$it['quantity'] ?>" data-pp="<?= $it['purchase_price'] ?>"
                                   oninput="calcPlanInput(this)"></td>
                        <td><?= number_format($it['quantity']*$it['purchase_price'],0,'.','&nbsp;') ?> ₽</td>
                        <td class="calc-row-rev">—</td>
                        <td class="calc-row-profit">—</td>
                        <td class="calc-row-margin">—</td>
                    </tr>
                <?php endforeach; ?>
                <tr class="summary-row">
                    <td colspan="4">Итого (с доставкой)</td>
                    <td class="calc-ship-cost">—</td>
                    <td class="calc-ship-rev">—</td>
                    <td class="calc-ship-profit">—</td>
                    <td class="calc-ship-margin">—</td>
                </tr>
                </tbody>
            </table>
        </div>
    </div>
    <?php endforeach; endif; ?>
</div>

<!-- ══════════ ПРОГНОЗ ══════════ -->
<div id="fin-forecast" style="<?= $finTab!=='forecast'?'display:none':'' ?>">

    <div style="font-size:12px;color:var(--text-muted);margin-bottom:16px;display:flex;align-items:center;gap:6px">
        <i data-lucide="info" style="width:14px;height:14px"></i>
        Прогноз — ориентир по текущим темпам и реальным остаткам, не обещание. Точность вырастет с накоплением истории.
    </div>

    <div class="pnl-cards-row" style="margin-bottom:20px">
        <!-- Run-rate текущего месяца -->
        <div class="stat-card">
            <div class="stat-label"><?= $monthNames[$fcNowM-1] ?> — прогноз по темпу</div>
            <?php if ($fcEarlyMonth && $fcNext): ?>
            <div class="stat-value"><?= number_format($fcNext['income'],0,'.','&nbsp;') ?> ₽</div>
            <div class="stat-sub" style="color:var(--warning)">Начало месяца — мало данных, показано среднее за 3 месяца</div>
            <?php elseif ($fcEarlyMonth): ?>
            <div class="stat-value" style="color:var(--text-muted)">—</div>
            <div class="stat-sub">Начало месяца — прогноз появится с 5 числа</div>
            <?php else: ?>
            <div class="stat-value accent"><?= number_format($fcRunRate['income'],0,'.','&nbsp;') ?> ₽</div>
            <div class="stat-sub">Чистая: <span style="color:<?= $fcRunRate['net']>=0?'var(--success)':'var(--danger)' ?>"><?= number_format($fcRunRate['net'],0,'.','&nbsp;') ?> ₽</span> · по темпу за <?= $fcDay ?> <?= $fcDay==1?'день':($fcDay<5?'дня':'дней') ?></div>
            <?php endif; ?>
        </div>

        <!-- Следующий месяц -->
        <div class="stat-card">
            <div class="stat-label">Следующий месяц</div>
            <?php if ($fcNext): ?>
            <div class="stat-value"><?= number_format($fcNext['income'],0,'.','&nbsp;') ?> ₽</div>
            <div class="stat-sub">
                Диапазон: <?= number_format($fcNextMin,0,'.','&nbsp;') ?> – <?= number_format($fcNextMax,0,'.','&nbsp;') ?> ₽
                · Чистая: <span style="color:<?= $fcNext['net']>=0?'var(--success)':'var(--danger)' ?>"><?= number_format($fcNext['net'],0,'.','&nbsp;') ?> ₽</span>
            </div>
            <?php else: ?>
            <div class="stat-value" style="color:var(--text-muted)">—</div>
            <div class="stat-sub">Мало истории для расчёта</div>
            <?php endif; ?>
        </div>

        <!-- Потенциал склада и поставок -->
        <div class="stat-card">
            <div class="stat-label">Потенциал склада и поставок</div>
            <div class="stat-value" style="color:var(--info)"><?= number_format($fcPotTotal,0,'.','&nbsp;') ?> ₽</div>
            <div class="stat-sub">
                Склад <?= number_format($fcPotWarehouse,0,'.','&nbsp;') ?> ₽ + в пути <?= number_format($fcPotTransit,0,'.','&nbsp;') ?> ₽
                · наценка ×<?= round($fcMarkup, 2) ?>
            </div>
        </div>
    </div>

    <div class="pnl-cards-row" style="margin-bottom:20px">
        <div class="stat-card">
            <div class="stat-label">Ожидаемая валовая с остатков</div>
            <div class="stat-value" style="color:<?= $fcPotGross>=0?'var(--success)':'var(--danger)' ?>"><?= number_format($fcPotGross,0,'.','&nbsp;') ?> ₽</div>
            <div class="stat-sub">Если продать всё со склада и из пути по средней наценке</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Сделок в этом месяце (прогноз)</div>
            <div class="stat-value"><?= $fcRunRate['cnt'] ?></div>
            <div class="stat-sub">Средний чек: <?= number_format($fcRunRate['avg'],0,'.','&nbsp;') ?> ₽</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Налог в этом месяце (прогноз)</div>
            <div class="stat-value" style="color:var(--danger)"><?= number_format($fcRunRate['tax'],0,'.','&nbsp;') ?> ₽</div>
            <div class="stat-sub">16% с прогнозной валовой</div>
        </div>
    </div>

    <!-- График: факт + пунктирный прогноз -->
    <div class="pnl-monthly-card" style="padding:16px">
        <div style="font-size:13px;font-weight:600;margin-bottom:12px">Выручка и чистая прибыль: факт и прогноз</div>
        <div style="position:relative;height:280px">
            <canvas id="forecast-chart"></canvas>
        </div>
    </div>

<script>
window._fcChartData = {
    labels:         <?= json_encode($fcChartLabels) ?>,
    income:         <?= json_encode($fcChartIncome) ?>,
    net:            <?= json_encode($fcChartNet) ?>,
    forecastLabels: <?= json_encode($fcForecastLabels) ?>,
    // Текущий месяц — run-rate (в начале месяца — среднее), дальше — взвешенное среднее
    forecastIncome: <?= json_encode([($fcEarlyMonth && $fcNext) ? $fcNext['income'] : $fcRunRate['income'], $fcNext['income'] ?? null, $fcNext['income'] ?? null]) ?>,
    forecastNet:    <?= json_encode([($fcEarlyMonth && $fcNext) ? $fcNext['net'] : $fcRunRate['net'],    $fcNext['net']    ?? null, $fcNext['net']    ?? null]) ?>
};
window._fcChartInited = false;

function initForecastChart() {
    if (window._fcChartInited || typeof Chart === 'undefined') return;
    const el = document.getElementById('forecast-chart');
    if (!el) return;
    window._fcChartInited = true;

    const d = window._fcChartData;
    // Факт занимает первые N точек, прогноз — следующие 3.
    // Последняя точка факта = текущий незавершённый месяц, его заменяет run-rate.
    const histLabels = d.labels.slice(0, -1);          // полные месяцы
    const histIncome = d.income.slice(0, -1);
    const histNet    = d.net.slice(0, -1);
    const labels = histLabels.concat([d.labels[d.labels.length-1]], d.forecastLabels.slice(0, 2));
    const pad    = n => new Array(n).fill(null);

    // Прогнозные линии стартуют с последней фактической точки, чтобы линия была непрерывной
    const lastIncome = histIncome[histIncome.length-1];
    const lastNet    = histNet[histNet.length-1];
    const fcIncome = pad(histIncome.length-1).concat([lastIncome], d.forecastIncome);
    const fcNet    = pad(histNet.length-1).concat([lastNet], d.forecastNet);

    const css = v => getComputedStyle(document.documentElement).getPropertyValue(v).trim();
    const accent = css('--accent') || '#c9a96e';
    const success = css('--success') || '#4ade80';

    new Chart(el, {
        type: 'line',
        data: {
            labels,
            datasets: [
                { label: 'Выручка (факт)', data: histIncome, borderColor: accent, backgroundColor: 'transparent', tension: 0.3, pointRadius: 3 },
                { label: 'Чистая (факт)', data: histNet, borderColor: success, backgroundColor: 'transparent', tension: 0.3, pointRadius: 3 },
                { label: 'Выручка (прогноз)', data: fcIncome, borderColor: accent, borderDash: [6,4], backgroundColor: 'transparent', tension: 0.3, pointRadius: 3, pointStyle: 'rectRot' },
                { label: 'Чистая (прогноз)', data: fcNet, borderColor: success, borderDash: [6,4], backgroundColor: 'transparent', tension: 0.3, pointRadius: 3, pointStyle: 'rectRot' }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { labels: { color: css('--text-secondary') || '#aaa', boxWidth: 12, font: { size: 11 } } },
                tooltip: { callbacks: { label: ctx => ctx.dataset.label + ': ' + (ctx.parsed.y == null ? '—' : new Intl.NumberFormat('ru-RU').format(ctx.parsed.y) + ' ₽') } }
            },
            scales: {
                x: { ticks: { color: css('--text-muted') || '#777', font: { size: 10 } }, grid: { display: false } },
                y: { ticks: { color: css('--text-muted') || '#777', font: { size: 10 }, callback: v => new Intl.NumberFormat('ru-RU', { notation: 'compact' }).format(v) }, grid: { color: 'rgba(255,255,255,0.05)' } }
            }
        }
    });
}
</script>
</div>

<!-- ══════════ ИТОГИ (за всё время) ══════════ -->
<div id="fin-analytics" style="<?= $finTab!=='analytics'?'display:none':'' ?>">

    <?php
    // Цвет кольца: зелёный если маржа > 20%, жёлтый 10-20%, красный < 10%
    $ringColor = $allMargin >= 20 ? '#4ade80' : ($allMargin >= 10 ? '#facc15' : '#ef4444');
    $circumference = 2 * M_PI * 54; // r=54
    $offset = $circumference * (1 - $netPct / 100);
    ?>

    <!-- HERO-БЛОК — три крупных цифры + формула -->
    <div class="pnl-hero">
        <div class="pnl-hero-metrics">
            <div class="pnl-big-metric">
                <div class="pnl-big-label">Выручка</div>
                <div class="pnl-big-value accent"><?= number_format($allIncome,0,'.','&nbsp;') ?> ₽</div>
                <div class="pnl-big-sub">Все оплаченные продажи</div>
            </div>
            <div class="pnl-hero-divider"></div>
            <div class="pnl-big-metric">
                <div class="pnl-big-label">Чистая прибыль</div>
                <div class="pnl-big-value" style="color:<?= $allNet>=0?'var(--success)':'var(--danger)' ?>"><?= number_format($allNet,0,'.','&nbsp;') ?> ₽</div>
                <div class="pnl-big-sub">Выручка − Закупки − Расходы − Налог</div>
            </div>
            <div class="pnl-hero-divider"></div>
            <div class="pnl-big-metric">
                <div class="pnl-big-label">ROI</div>
                <div class="pnl-big-value" style="color:<?= $allRoi>=0?'var(--success)':'var(--danger)' ?>"><?= $allRoi ?>%</div>
                <div class="pnl-big-sub">(Выручка − Закупки) / Закупки</div>
            </div>
        </div>
        <?php
        // Спарклайн чистой прибыли по 12 месяцам: тренд одним взглядом
        $sparkVals = array_column($metricsHistory, 'net');
        $sparkMin = min($sparkVals); $sparkMax = max($sparkVals);
        $sparkRange = max(1, $sparkMax - $sparkMin);
        $sparkPts = [];
        foreach ($sparkVals as $i => $v) {
            $x = round($i / max(1, count($sparkVals) - 1) * 300, 1);
            $y = round(34 - (($v - $sparkMin) / $sparkRange) * 28, 1);
            $sparkPts[] = "$x,$y";
        }
        $sparkZeroY = round(34 - ((0 - $sparkMin) / $sparkRange) * 28, 1);
        ?>
        <div class="pnl-hero-spark">
            <span class="pnl-hero-spark-label">Чистая прибыль, 12 мес</span>
            <svg viewBox="0 0 300 40" preserveAspectRatio="none">
                <?php if ($sparkMin < 0 && $sparkMax > 0): ?>
                <line x1="0" y1="<?= $sparkZeroY ?>" x2="300" y2="<?= $sparkZeroY ?>" class="pnl-spark-zero"/>
                <?php endif; ?>
                <polyline points="<?= implode(' ', $sparkPts) ?>" class="pnl-spark-line"/>
            </svg>
        </div>

        <div class="pnl-formula-row">
            <span class="pnl-formula-item">
                <span class="pnl-formula-val"><?= number_format($allIncome,0,'.','&nbsp;') ?> ₽</span>
                <span class="pnl-formula-name">Выручка</span>
            </span>
            <span class="pnl-formula-op">−</span>
            <span class="pnl-formula-item">
                <span class="pnl-formula-val"><?= number_format($allPurchase,0,'.','&nbsp;') ?> ₽</span>
                <span class="pnl-formula-name">Закупки</span>
            </span>
            <span class="pnl-formula-op">−</span>
            <span class="pnl-formula-item">
                <span class="pnl-formula-val"><?= number_format($allBiz,0,'.','&nbsp;') ?> ₽</span>
                <span class="pnl-formula-name">Расходы</span>
            </span>
            <span class="pnl-formula-op">−</span>
            <span class="pnl-formula-item">
                <span class="pnl-formula-val" style="color:var(--danger)"><?= number_format($allTax,0,'.','&nbsp;') ?> ₽</span>
                <span class="pnl-formula-name">Налог 16%</span>
            </span>
            <span class="pnl-formula-op">=</span>
            <span class="pnl-formula-item" style="border-color:<?= $allNet>=0?'rgba(74,222,128,0.3)':'rgba(239,68,68,0.3)' ?>;background:<?= $allNet>=0?'rgba(74,222,128,0.06)':'rgba(239,68,68,0.06)' ?>">
                <span class="pnl-formula-val" style="color:<?= $allNet>=0?'var(--success)':'var(--danger)' ?>"><?= number_format($allNet,0,'.','&nbsp;') ?> ₽</span>
                <span class="pnl-formula-name">Чистая</span>
            </span>
        </div>
    </div>

    <div class="pnl-body">
        <!-- ЛЕВАЯ КОЛОНКА — карточки 3 в ряд -->
        <div class="pnl-cards-col">

            <!-- Строка 1: основные деньги -->
            <div class="pnl-cards-row">
                <div class="stat-card pnl-clickable" onclick="openPnlDetail('income')">
                    <div class="stat-label">Выручка</div>
                    <div class="stat-value accent"><?= number_format($allIncome,0,'.','&nbsp;') ?> ₽</div>
                    <div class="stat-sub">Оплаченные продажи</div>
                </div>
                <div class="stat-card pnl-clickable" onclick="openPnlDetail('purchase')">
                    <div class="stat-label">Закупки</div>
                    <div class="stat-value"><?= number_format($allPurchase,0,'.','&nbsp;') ?> ₽</div>
                    <div class="stat-sub">Завершённые поставки</div>
                </div>
                <div class="stat-card pnl-clickable" onclick="openPnlDetail('biz')">
                    <div class="stat-label">Расходы ИП</div>
                    <div class="stat-value" style="color:var(--warning)"><?= number_format($allBiz,0,'.','&nbsp;') ?> ₽</div>
                    <div class="stat-sub">ПО, сервисы, прочее</div>
                </div>
            </div>

            <!-- Строка 2: налог + эффективность -->
            <div class="pnl-cards-row">
                <div class="stat-card pnl-clickable" onclick="openPnlDetail('tax')">
                    <div class="stat-label">Налог (16%)</div>
                    <div class="stat-value" style="color:var(--danger)"><?= number_format($allTax,0,'.','&nbsp;') ?> ₽</div>
                    <div class="stat-sub">С валовой прибыли</div>
                </div>
                <div class="stat-card pnl-clickable" onclick="openPnlDetail('margin')">
                    <div class="stat-label">Маржинальность</div>
                    <div class="stat-value" style="color:<?= $allMargin>=20?'var(--success)':($allMargin>=0?'var(--accent)':'var(--danger)') ?>"><?= $allMargin ?>%</div>
                    <div class="stat-sub">Чистая / Выручка</div>
                </div>
                <div class="stat-card pnl-clickable" onclick="openPnlDetail('roi')">
                    <div class="stat-label">ROI</div>
                    <div class="stat-value" style="color:<?= $allRoi>=0?'var(--success)':'var(--danger)' ?>"><?= $allRoi ?>%</div>
                    <div class="stat-sub">(Выручка − Закупки) / Закупки</div>
                </div>
            </div>

            <!-- Строка 3: активность -->
            <div class="pnl-cards-row">
                <div class="stat-card pnl-clickable" onclick="openPnlDetail('income')">
                    <div class="stat-label">Сделок</div>
                    <div class="stat-value"><?= $allCnt ?></div>
                    <div class="stat-sub">Оплаченных продаж</div>
                </div>
                <div class="stat-card pnl-clickable" onclick="openPnlDetail('income')">
                    <div class="stat-label">Средний чек</div>
                    <div class="stat-value"><?= number_format($allAvg,0,'.','&nbsp;') ?> ₽</div>
                    <div class="stat-sub">Выручка / сделок</div>
                </div>
                <div class="stat-card pnl-clickable" onclick="openPnlDetail('purchase')">
                    <div class="stat-label">Поставщиков</div>
                    <div class="stat-value"><?= $allSuppliers ?></div>
                    <div class="stat-sub">Уникальных</div>
                </div>
            </div>

            <!-- Строка 4: текущее состояние -->
            <div class="pnl-cards-row">
                <div class="stat-card pnl-clickable" onclick="openPnlDetail('warehouse')">
                    <div class="stat-label">На складе</div>
                    <div class="stat-value" style="color:var(--accent)"><?= number_format($warehouseFrozen,0,'.','&nbsp;') ?> ₽</div>
                    <div class="stat-sub">Заморожено</div>
                </div>
                <div class="stat-card pnl-clickable" onclick="openPnlDetail('transit')">
                    <div class="stat-label">В пути</div>
                    <div class="stat-value" style="color:var(--info)"><?= number_format($inTransit,0,'.','&nbsp;') ?> ₽</div>
                    <div class="stat-sub">Активные поставки</div>
                </div>
            </div>

        </div>

        <!-- ПРАВАЯ КОЛОНКА — помесячная таблица -->
        <div class="pnl-right-col">
            <div class="pnl-monthly-card">
                <div class="pnl-monthly-header">
                    <span class="pnl-monthly-title">По месяцам</span>
                    <div class="pnl-filter-wrap">
                        <button class="pnl-filter-btn" id="pnlFilterBtn" onclick="togglePnlFilter(event)">
                            <i data-lucide="sliders-horizontal"></i>
                            <span id="pnlFilterLabel">Все</span>
                            <i data-lucide="chevron-down" style="width:12px;height:12px"></i>
                        </button>
                        <div class="pnl-filter-drop" id="pnlFilterDrop">
                            <div class="pnl-filter-option active" data-filter="all"    onclick="setPnlFilter('all','Все',this)">Все месяцы</div>
                            <div class="pnl-filter-option"        data-filter="3"      onclick="setPnlFilter('3','3 мес',this)">Последние 3 месяца</div>
                            <div class="pnl-filter-option"        data-filter="6"      onclick="setPnlFilter('6','6 мес',this)">Последние 6 месяцев</div>
                            <div class="pnl-filter-option"        data-filter="12"     onclick="setPnlFilter('12','12 мес',this)">Последние 12 месяцев</div>
                        </div>
                    </div>
                </div>
                <div class="pnl-monthly-table-wrap">
                    <table class="pnl-monthly-table" id="pnlMonthlyTable">
                        <thead>
                            <tr>
                                <th>Месяц</th>
                                <th>Выручка</th>
                                <th>Закупки</th>
                                <th>Чистая</th>
                                <th>Маржа</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($monthlyRows as $row): ?>
                            <tr class="pnl-mrow" data-ym="<?= $row['key'] ?>" data-year="<?= $row['year'] ?>"
                                onclick="openPnlMonthDetail(<?= $row['year'] ?>,<?= (int)explode('-',$row['key'])[1] ?>)"
                                style="cursor:pointer">
                                <td class="pnl-mcell-month"><?= $row['label'] ?></td>
                                <td class="pnl-mcell-num"><?= $row['income'] > 0 ? number_format($row['income'],0,'.','&nbsp;') : '—' ?></td>
                                <td class="pnl-mcell-num pnl-mcell-dim"><?= $row['pur'] > 0 ? number_format($row['pur'],0,'.','&nbsp;') : '—' ?></td>
                                <td class="pnl-mcell-num <?= $row['net'] >= 0 ? 'pnl-pos' : 'pnl-neg' ?>">
                                    <?= $row['net'] != 0 ? number_format($row['net'],0,'.','&nbsp;') : '—' ?>
                                </td>
                                <td class="pnl-mcell-margin">
                                    <?php if ($row['income'] > 0): ?>
                                    <span class="pnl-margin-badge <?= $row['margin'] >= 15 ? 'good' : ($row['margin'] >= 5 ? 'ok' : 'bad') ?>">
                                        <?= $row['margin'] ?>%
                                    </span>
                                    <?php else: ?>—<?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

<script>
(function(){
    // Данные для JS-фильтрации
    var allRows = <?= json_encode(array_map(fn($r)=>['key'=>$r['key'],'year'=>$r['year']], $monthlyRows)) ?>;

    window.togglePnlFilter = function(e) {
        e.stopPropagation();
        var drop = document.getElementById('pnlFilterDrop');
        drop.classList.toggle('open');
    };
    document.addEventListener('click', function() {
        var drop = document.getElementById('pnlFilterDrop');
        if (drop) drop.classList.remove('open');
    });

    window.setPnlFilter = function(filter, label, el) {
        document.getElementById('pnlFilterLabel').textContent = label;
        document.querySelectorAll('.pnl-filter-option').forEach(o => o.classList.remove('active'));
        el.classList.add('active');
        document.getElementById('pnlFilterDrop').classList.remove('open');
        applyPnlFilter(filter);
    };

    function applyPnlFilter(filter) {
        var rows = document.querySelectorAll('#pnlMonthlyTable .pnl-mrow');
        var now = new Date();
        var cutoff = null;
        if (filter === '3' || filter === '6' || filter === '12') {
            cutoff = new Date(now.getFullYear(), now.getMonth() - parseInt(filter) + 1, 1);
        }
        rows.forEach(function(tr) {
            var ym = tr.dataset.ym.split('-');
            var rowDate = new Date(parseInt(ym[0]), parseInt(ym[1]) - 1, 1);
            var show = true;
            if (filter === 'all') show = true;
            else if (cutoff) show = rowDate >= cutoff;
            else if (filter.startsWith('y')) show = tr.dataset.year === filter.slice(1);
            tr.style.display = show ? '' : 'none';
        });
    }
})();
</script>
    </div>

</div>

<!-- ══════════ РАСХОДЫ ПРЕДПРИНИМАТЕЛЯ ══════════ -->
<div id="fin-expenses" style="<?= $finTab!=='expenses'?'display:none':'' ?>">
    <div class="tab-toolbar">
        <div class="tab-toolbar-left">
            <button class="btn btn-ghost" onclick="openManageExpenseCategories()"><i data-lucide="settings" style="width:13px;height:13px"></i> Категории</button>
        </div>
        <div class="tab-toolbar-right">
            <?= $finPeriodSelect ?>
            <input type="text" id="search-expenses" class="form-control toolbar-search" placeholder="Поиск...">
            <button class="btn btn-primary" onclick="openAddBizExpense()">+ Расход</button>
        </div>
    </div>

    <!-- Плитки по всем категориям -->
    <div class="stats-grid-compact" style="margin-bottom:20px">
        <div class="stat-card">
            <div class="stat-label">Итого расходов</div>
            <div class="stat-value" style="color:var(--danger)"><?= number_format($totalBizAll,0,'.','&nbsp;') ?> ₽</div>
            <div class="stat-sub"><?= count($allCats) ?> <?= count($allCats)===1?'категория':'категорий' ?></div>
        </div>
        <?php foreach ($allCats as $cat => $sum): ?>
        <div class="stat-card" style="cursor:pointer" onclick="filterExpensesByCategory(<?= json_encode($cat) ?>)">
            <div class="stat-label"><?= htmlspecialchars($cat) ?></div>
            <div class="stat-value" style="color:var(--warning)"><?= number_format($sum,0,'.','&nbsp;') ?> ₽</div>
            <div class="stat-sub"><?= $totalBizAll>0?round($sum/$totalBizAll*100):0 ?>% от общего</div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Фильтр по категориям -->
    <div class="filter-chips">
        <button class="chip active" onclick="filterExpensesByCategory(null,this)">Все</button>
        <?php foreach (array_keys($allCats) as $cat): ?>
        <button class="chip" data-exp-cat="<?= htmlspecialchars($cat) ?>" onclick="filterExpensesByCategory(<?= json_encode($cat) ?>,this)">
            <?= htmlspecialchars($cat) ?>
        </button>
        <?php endforeach; ?>
    </div>

    <div class="table-wrapper">
        <table>
            <thead><tr><th>Дата</th><th>Название</th><th>Категория</th><th>Счёт</th><th style="text-align:right">Сумма</th><th></th></tr></thead>
            <tbody id="expenses-tbody" class="m-cards m-cards-exp">
                <?php if (empty($bizExpenses)): ?>
                <tr><td colspan="6" style="text-align:center;color:var(--text-muted);padding:40px">Расходов пока нет</td></tr>
                <?php else: foreach ($bizExpenses as $e): ?>
                <tr style="cursor:pointer" data-exp-cat="<?= htmlspecialchars($e['category_name']??'Без категории') ?>" onclick="openBizExpenseDetail(<?= $e['id'] ?>)">
                    <td><?= date('d.m.Y', strtotime($e['expense_date'])) ?></td>
                    <td style="font-weight:500"><?= htmlspecialchars($e['name']) ?></td>
                    <td><span class="badge badge-waiting"><?= htmlspecialchars($e['category_name']??'—') ?></span></td>
                    <td style="color:var(--text-muted);font-size:12px"><?= htmlspecialchars($e['account_name']??'—') ?></td>
                    <td style="text-align:right;color:var(--danger);font-weight:500">−<?= number_format($e['amount'],0,'.','&nbsp;') ?> ₽</td>
                    <td class="row-actions">
                        <button class="btn-icon" onclick="event.stopPropagation();openEditBizExpense(<?= $e['id'] ?>)"><i data-lucide="pencil" style="width:13px;height:13px"></i></button>
                        <button class="btn-icon btn-icon-danger" onclick="event.stopPropagation();deleteBizExpense(<?= $e['id'] ?>)"><i data-lucide="trash-2" style="width:13px;height:13px"></i></button>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ══════════ ЗАРПЛАТА ══════════ -->
<div id="fin-salary" style="<?= $finTab!=='salary'?'display:none':'' ?>">
    <div class="tab-toolbar">
        <div class="tab-toolbar-left"></div>
        <div class="tab-toolbar-right">
            <button class="btn btn-primary" onclick="openAddSalaryEntry()">+ Добавить запись</button>
        </div>
    </div>
    <div id="salary-stats" style="margin-bottom:20px"></div>
    <div id="salary-table-wrap"></div>
</div>

<!-- ══════════ БАНК ══════════ -->
<div id="fin-bank" style="<?= $finTab!=='bank'?'display:none':'' ?>">
    <?php include 'bank.php'; ?>
</div>

<script>
window._finTab = <?= json_encode($finTab) ?>;
if (window._finTab === 'forecast') setTimeout(() => initForecastChart(), 100);
// Калькулятор открыт сразу (после SPA-навигации finances.js не перезапускается) — считаем тут
if (window._finTab === 'calc') setTimeout(() => { if (typeof recalcCalcTab === 'function') recalcCalcTab(); }, 50);

// Перезагрузка страницы P&L с выбранным периодом, сохраняя активную вкладку
function setFinPeriod(val) {
    const p = new URLSearchParams();
    p.set('page', 'finances');
    p.set('fintab', window._finTab || 'bank');
    if (val === 'all') {
        p.set('period', 'all');
    } else {
        const [y, m] = val.split('-');
        p.set('year', y);
        p.set('month', m);
    }
    window.location.href = '?' + p.toString();
}

function switchFinTab(tab, e) {
    ['analytics','expenses','bank','salary','forecast','calc'].forEach(t => document.getElementById('fin-'+t).style.display = t===tab?'':'none');
    if (tab === 'forecast') setTimeout(() => initForecastChart(), 50);
    if (tab === 'calc' && typeof recalcCalcTab === 'function') recalcCalcTab();
    document.querySelectorAll('.tabs .tab').forEach(t => t.classList.remove('active'));
    if (e&&e.target) e.target.classList.add('active');
    window._finTab = tab;
    sessionStorage.setItem('finTab', tab);
    if (tab === 'salary') loadSalaryTab();
    const delay = tab === 'salary' ? 600 : 80;
    setTimeout(() => window._bybAnimations?.reinitCardAnimations('fin-' + tab), delay);
}
// Аналитика всегда по умолчанию — sessionStorage не используем

// Фильтр расходов по категории
function filterExpensesByCategory(cat, btn) {
    // Снять активный класс со всех чипов
    document.querySelectorAll('.filter-chips .chip').forEach(t => t.classList.remove('active'));
    if (btn) btn.classList.add('active');

    document.querySelectorAll('#expenses-tbody tr[data-exp-cat]').forEach(row => {
        row.style.display = (!cat || row.dataset.expCat === cat) ? '' : 'none';
    });
}

if (typeof initTableSearch === 'function') {
    initTableSearch('search-expenses', 'expenses-tbody');
    initTableSearch('search-bank', 'bank-ops-tbody');
}
setTimeout(() => { if (typeof restoreBankFilters === 'function') restoreBankFilters(); }, 100);
if (window.lucide) lucide.createIcons();

// ══════════ ИСТОРИЯ ПО МЕСЯЦАМ ══════════
const _metricsHistory = <?= json_encode($metricsHistory) ?>;

function openMetricsHistory(highlight) {
    const fmt = v => new Intl.NumberFormat('ru-RU').format(Math.round(v));
    const rows = _metricsHistory.filter(m => m.income > 0 || m.purchase > 0 || m.biz > 0).map(m => `
        <tr>
            <td style="font-weight:500;white-space:nowrap">${m.label}</td>
            <td style="color:var(--accent)">${fmt(m.income)} ₽</td>
            <td>${fmt(m.purchase)} ₽</td>
            <td style="color:var(--warning)">${fmt(m.biz)} ₽</td>
            <td style="color:${m.net>=0?'var(--success)':'var(--danger)'}">${fmt(m.net)} ₽</td>
            <td>${m.margin}%</td>
            <td>${m.roi}%</td>
            <td style="color:var(--danger)">${fmt(m.tax)} ₽</td>
        </tr>`).join('') || `<tr><td colspan="8" style="text-align:center;color:var(--text-muted);padding:20px">Нет данных</td></tr>`;

    openWideModal(`
        <div class="modal-header">
            <span class="modal-title">История показателей по месяцам</span>
            <button class="btn-close" onclick="closeModal()"><i data-lucide="x" style="width:15px;height:15px"></i></button>
        </div>
        <div class="modal-body" style="overflow-x:auto">
            <table style="min-width:600px">
                <thead><tr>
                    <th>Месяц</th>
                    <th>Доход</th>
                    <th>Закупки</th>
                    <th>Расходы</th>
                    <th>Чист. прибыль</th>
                    <th>Маржа</th>
                    <th>ROI</th>
                    <th>Налог</th>
                </tr></thead>
                <tbody>${rows}</tbody>
            </table>
        </div>
        <div class="modal-footer"><button class="btn btn-ghost" onclick="closeModal()">Закрыть</button></div>
    `);
}
</script>
