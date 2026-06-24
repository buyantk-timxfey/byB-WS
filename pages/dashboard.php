<?php
// Метка периода для KPI-карточек («месяц» или «Всё время» в режиме всех периодов)
$periodLabel = $filterAll ? 'Всё время' : $monthNames[$currentMonth-1];

// Выручка за период (оплаченные продажи)
[$saleCond, $saleP] = periodFilter('sale_date');
$incStmt = $pdo->prepare("SELECT COALESCE(SUM(sale_price),0) FROM sales WHERE status='Оплачено' AND $saleCond");
$incStmt->execute($saleP);
$monthIncome = $incStmt->fetchColumn();

$totalIncome   = $pdo->query("SELECT COALESCE(SUM(sale_price),0) FROM sales WHERE status='Оплачено'")->fetchColumn();
// Закупки = только завершённые поставки (единая методика с P&L)
$totalPurchase = $pdo->query("SELECT COALESCE((SELECT SUM(si.quantity*si.purchase_price) FROM shipment_items si JOIN shipments s ON s.id=si.shipment_id WHERE s.status='Завершено'),0)+COALESCE((SELECT SUM(carrier_cost) FROM shipments WHERE status='Завершено'),0)")->fetchColumn();
$totalBizExp   = $pdo->query("SELECT COALESCE(SUM(amount),0) FROM business_expenses")->fetchColumn();
$totalProfit   = $totalIncome - $totalPurchase - $totalBizExp;

$pendingAmount = $pdo->query("SELECT COALESCE(SUM(sale_price),0) FROM sales WHERE status='Счёт выставлен'")->fetchColumn();

// Закупки за период (товары + доставка), по дате заказа поставки
[$shipCond, $shipP] = periodFilter('s.order_date');
[$carCond,  $carP]  = periodFilter('order_date');
$monthPurchase = $pdo->prepare("SELECT COALESCE((SELECT SUM(si.quantity*si.purchase_price) FROM shipment_items si JOIN shipments s ON s.id=si.shipment_id WHERE s.status='Завершено' AND $shipCond),0)+COALESCE((SELECT SUM(carrier_cost) FROM shipments WHERE status='Завершено' AND $carCond),0)");
$monthPurchase->execute(array_merge($shipP, $carP));
$monthPurchaseVal = $monthPurchase->fetchColumn();

// Расходы предпринимателя за период
[$expCond, $expP] = periodFilter('expense_date');
$monthBizExp = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM business_expenses WHERE $expCond");
$monthBizExp->execute($expP);
$monthBizExpVal = $monthBizExp->fetchColumn();
$monthProfit = $monthIncome - $monthPurchaseVal - $monthBizExpVal;

$roi = $totalPurchase > 0 ? round(($totalProfit / $totalPurchase) * 100, 1) : 0;

$transitStmt = $pdo->query("SELECT COUNT(*) as cnt, COALESCE((SELECT SUM(si.quantity*si.purchase_price) FROM shipment_items si JOIN shipments s ON s.id=si.shipment_id WHERE s.status='В пути'),0)+COALESCE((SELECT SUM(carrier_cost) FROM shipments WHERE status='В пути'),0) as total FROM shipments WHERE status='В пути'");
$transitData  = $transitStmt->fetch();
$transitCount = $transitData['cnt'];
$transitTotal = $transitData['total'];

$overdueStmt = $pdo->query("SELECT s.id, s.eta, COALESCE(c.company_type,'') as company_type, COALESCE(c.name,'—') as counterparty FROM shipments s LEFT JOIN counterparties c ON c.id=s.counterparty_id WHERE s.status='В пути' AND s.eta < CURDATE() ORDER BY s.eta ASC LIMIT 3");
$overdueShipments = $overdueStmt->fetchAll();

// Поставки для календаря (В пути, с ETA)
$calendarShipments = $pdo->query("
    SELECT s.id, COALESCE(s.name,'—') as shipment_name, s.eta,
           COALESCE(c.company_type,'') as company_type,
           COALESCE(c.name,'—') as counterparty
    FROM shipments s
    LEFT JOIN counterparties c ON c.id = s.counterparty_id
    WHERE s.status = 'В пути' AND s.eta IS NOT NULL
    ORDER BY s.eta ASC
")->fetchAll();

// Группируем по дате для JS
$calendarData = [];
foreach ($calendarShipments as $cs) {
    $dateKey = $cs['eta']; // YYYY-MM-DD
    if (!isset($calendarData[$dateKey])) $calendarData[$dateKey] = [];
    $calendarData[$dateKey][] = [
        'id'           => $cs['id'],
        'name'         => $cs['shipment_name'],
        'counterparty' => trim($cs['company_type'].' '.$cs['counterparty']),
    ];
}

// Поставки для трекера (все кроме Завершено)
$trackerShipments = $pdo->query("
    SELECT s.id, s.name as shipment_name, s.order_date, s.eta, s.status,
           COALESCE(c.company_type,'') as company_type,
           COALESCE(c.name,'—') as counterparty
    FROM shipments s
    LEFT JOIN counterparties c ON c.id = s.counterparty_id
    WHERE s.status != 'Завершено'
    ORDER BY s.order_date DESC
")->fetchAll();

// Балансы банковских счетов
$bankAccounts = $pdo->query("SELECT * FROM bank_accounts ORDER BY id")->fetchAll();
$bankBalancesMap = [];
foreach ($pdo->query("
    SELECT account_id,
        SUM(CASE WHEN type IN ('Продажа','Прочий приход')      THEN amount ELSE 0 END) as total_income,
        SUM(CASE WHEN type IN ('Закупка','Расход','Выплата ЗП') THEN amount ELSE 0 END) as total_expense,
        SUM(CASE WHEN type = 'Перевод'                          THEN amount ELSE 0 END) as transfer_net
    FROM bank_operations WHERE status IN ('confirmed','pending') GROUP BY account_id
")->fetchAll() as $row) {
    $bankBalancesMap[$row['account_id']] = $row;
}
foreach ($bankAccounts as &$acc) {
    $b = $bankBalancesMap[$acc['id']] ?? ['total_income' => 0, 'total_expense' => 0, 'transfer_net' => 0];
    $acc['balance']       = $acc['initial_balance'] + $b['total_income'] - $b['total_expense'] + ($b['transfer_net'] ?? 0);
    $acc['total_income']  = $b['total_income'];
    $acc['total_expense'] = $b['total_expense'];
}
unset($acc);

$days   = ['Воскресенье','Понедельник','Вторник','Среда','Четверг','Пятница','Суббота'];
$months = ['января','февраля','марта','апреля','мая','июня','июля','августа','сентября','октября','ноября','декабря'];
?>

<div class="page-header" style="margin-bottom:20px">
    <div>
        <div class="dashboard-date"><?= $days[date('w')] ?>, <?= date('j') ?> <?= $months[date('n')-1] ?> <?= date('Y') ?></div>
        <h1 style="font-size:24px;font-weight:600;letter-spacing:-0.4px;margin-top:2px">Добрый день, <span style="color:var(--accent)">Buka</span></h1>
    </div>
</div>

<!-- Основная сетка дашборда (6 колонок) -->
<div class="dashboard-layout">

    <!-- [1] KPI — колонки 1-3 -->
    <div class="dashboard-kpi">
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-card-header">
                    <span class="stat-label">Выручка — <?= $periodLabel ?></span>
                    <div class="stat-icon stat-icon-accent">
                        <i data-lucide="trending-up" style="width:15px;height:15px"></i>
                    </div>
                </div>
                <div class="stat-value accent"><?= number_format($monthIncome,0,'.','&nbsp;') ?> ₽</div>
                <div class="stat-sub">Оплаченные продажи</div>
            </div>
            <div class="stat-card">
                <div class="stat-card-header">
                    <span class="stat-label">Ожидают оплаты</span>
                    <div class="stat-icon stat-icon-warning">
                        <i data-lucide="clock" style="width:15px;height:15px"></i>
                    </div>
                </div>
                <div class="stat-value" style="color:var(--warning)"><?= number_format($pendingAmount,0,'.','&nbsp;') ?> ₽</div>
                <div class="stat-sub">Счета выставлены</div>
            </div>
            <div class="stat-card">
                <div class="stat-card-header">
                    <span class="stat-label">Прибыль — <?= $periodLabel ?></span>
                    <div class="stat-icon <?= $monthProfit >= 0 ? 'stat-icon-success' : 'stat-icon-danger' ?>">
                        <i data-lucide="<?= $monthProfit >= 0 ? 'arrow-up-right' : 'arrow-down-right' ?>" style="width:15px;height:15px"></i>
                    </div>
                </div>
                <div class="stat-value" style="color:<?= $monthProfit >= 0 ? 'var(--success)' : 'var(--danger)' ?>"><?= number_format($monthProfit >= 0 ? $monthProfit : 0, 0, '.', '&nbsp;') ?> ₽</div>
                <div class="stat-sub">Доход − Закупки − Расходы</div>
            </div>
        </div>
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-card-header">
                    <span class="stat-label">Чистая прибыль (всё время)</span>
                    <div class="stat-icon <?= $totalProfit >= 0 ? 'stat-icon-success' : 'stat-icon-danger' ?>">
                        <i data-lucide="wallet" style="width:15px;height:15px"></i>
                    </div>
                </div>
                <div class="stat-value" style="color:<?= $totalProfit >= 0 ? 'var(--success)' : 'var(--danger)' ?>"><?= number_format($totalProfit,0,'.','&nbsp;') ?> ₽</div>
                <div class="stat-sub">Накопленный результат</div>
            </div>
            <div class="stat-card" style="cursor:pointer" onclick="window._openShipmentsTab='transit'; navigateTo('shipments')">
                <div class="stat-card-header">
                    <span class="stat-label">Поставки в пути</span>
                    <div class="stat-icon stat-icon-info">
                        <i data-lucide="package" style="width:15px;height:15px"></i>
                    </div>
                </div>
                <div class="stat-value accent"><?= $transitCount ?> шт</div>
                <div class="stat-sub"><?= number_format($transitTotal,0,'.','&nbsp;') ?> ₽ в закупке</div>
            </div>
            <div class="stat-card">
                <div class="stat-card-header">
                    <span class="stat-label">ROI (всё время)</span>
                    <div class="stat-icon <?= $roi >= 0 ? 'stat-icon-success' : 'stat-icon-danger' ?>">
                        <i data-lucide="percent" style="width:15px;height:15px"></i>
                    </div>
                </div>
                <div class="stat-value" style="color:<?= $roi >= 0 ? 'var(--success)' : 'var(--danger)' ?>"><?= $roi ?>%</div>
                <div class="stat-sub">Прибыль / Закупки × 100</div>
            </div>
        </div>
    </div><!-- /dashboard-kpi -->

    <!-- [2] Заметки + План — колонки 4-6 -->
    <div class="dashboard-notes-row">
        <div class="notes-tile">
            <div class="notes-tile-header" onclick="toggleNotesTile()">
                <div class="notes-tile-left">
                    <i data-lucide="file-text" style="width:14px;height:14px;color:var(--accent)"></i>
                    <span class="notes-tile-title">Заметки</span>
                    <span id="notes-tile-count" class="notes-tile-count">0</span>
                </div>
                <i data-lucide="chevron-down" id="notes-tile-chevron" style="width:14px;height:14px;color:var(--text-dim);transition:transform .2s;transform:rotate(180deg)"></i>
            </div>
            <div id="notes-tile-body" class="notes-tile-body open">
                <div style="padding:16px;color:var(--text-dim);font-size:13px;display:flex;align-items:center;gap:8px">
                    <span class="loader-ring" style="width:14px;height:14px;border-width:2px;flex-shrink:0"></span> Загрузка...
                </div>
            </div>
        </div>
        <div class="notes-tile">
            <div class="notes-tile-header" onclick="navigateTo('deals')" style="cursor:pointer">
                <div class="notes-tile-left">
                    <i data-lucide="handshake" style="width:14px;height:14px;color:var(--accent)"></i>
                    <span class="notes-tile-title">Сделки</span>
                    <span id="deals-tile-count" class="notes-tile-count">0</span>
                </div>
                <i data-lucide="arrow-right" style="width:14px;height:14px;color:var(--text-dim)"></i>
            </div>
            <div id="deals-tile-body" class="notes-tile-body open">
                <div style="padding:16px;color:var(--text-dim);font-size:13px;display:flex;align-items:center;gap:8px">
                    <span class="loader-ring" style="width:14px;height:14px;border-width:2px;flex-shrink:0"></span> Загрузка...
                </div>
            </div>
        </div>
    </div><!-- /dashboard-notes-row -->

    <!-- [3+6] Левая колонка: просроченные + календарь — колонки 1-2 -->
    <div class="dashboard-left-col">
    <div class="dashboard-overdue">
        <div class="dashboard-overdue-header" onclick="window._openShipmentsTab='overdue-eta'; navigateTo('shipments')" style="cursor:pointer">
            <i data-lucide="alert-triangle" style="width:14px;height:14px;color:var(--danger)"></i>
            <span>Просроченные поставки</span>
            <i data-lucide="arrow-right" style="width:12px;height:12px;margin-left:auto;color:rgba(239,68,68,0.6)"></i>
        </div>
        <?php if (empty($overdueShipments)): ?>
            <div style="padding:16px;font-size:13px;color:var(--text-dim);text-align:center">Нет просроченных</div>
        <?php else: ?>
            <?php foreach ($overdueShipments as $sh): ?>
            <div class="dashboard-overdue-row" onclick="openShipment(<?= $sh['id'] ?>)">
                <span class="dashboard-overdue-name"><?= htmlspecialchars($sh['company_type'].' '.$sh['counterparty']) ?></span>
                <span class="dashboard-overdue-eta">ETA: <?= date('d.m.Y', strtotime($sh['eta'])) ?></span>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div><!-- /dashboard-overdue -->

    <!-- [6] Календарь поставок -->
    <div class="dashboard-calendar">
        <div class="dashboard-calendar__header">
            <button class="dashboard-calendar__nav" id="cal-prev"><i data-lucide="chevron-left" style="width:14px;height:14px"></i></button>
            <span class="dashboard-calendar__title" id="cal-title"></span>
            <button class="dashboard-calendar__nav" id="cal-next"><i data-lucide="chevron-right" style="width:14px;height:14px"></i></button>
        </div>
        <div class="dashboard-calendar__weekdays">
            <span>Пн</span><span>Вт</span><span>Ср</span><span>Чт</span><span>Пт</span><span>Сб</span><span>Вс</span>
        </div>
        <div class="dashboard-calendar__grid" id="cal-grid"></div>
        <div class="dashboard-calendar__tooltip" id="cal-tooltip"></div>
    </div>
    </div><!-- /dashboard-left-col -->

    <!-- [4+5] Банковские карты + Трекер — колонки 3-6 -->
    <div class="dashboard-right-bottom">

    <!-- Банковские карты -->
    <div class="bank-cards-row">
        <?php
        $bankThemes = ['альфа' => 'alfa', 'альфа-банк' => 'alfa', 'точка' => 'tochka', 'ozon' => 'ozon', 'озон' => 'ozon'];
        foreach ($bankAccounts as $acc):
            $theme = 'alfa';
            foreach ($bankThemes as $keyword => $t) {
                if (mb_stripos($acc['name'], $keyword) !== false) { $theme = $t; break; }
            }
            $balance = $acc['balance'];
        ?>
        <div class="bank-card bank-card--<?= $theme ?>">
            <div class="bank-card__top">
                <div class="bank-card__logo">
                    <?php if ($theme === 'alfa'): ?>
                        <span class="bank-card__logo-letter">А</span>
                    <?php elseif ($theme === 'tochka'): ?>
                        <span class="bank-card__logo-text">точка</span>
                    <?php else: ?>
                        <span class="bank-card__logo-ozon">ozon<br><span style="font-size:0.7em">банк</span></span>
                    <?php endif; ?>
                </div>
                <div class="bank-card__name"><?= htmlspecialchars($acc['name']) ?></div>
            </div>
            <div class="bank-card__chiprow">
                <div class="bank-card__chip"></div>
                <div class="bank-card__balance"><?= number_format($balance, 2, ',', ' ') ?> ₽</div>
            </div>
            <div class="bank-card__bottom">
                <div class="bank-card__flows">
                    <div class="bank-card__flow">
                        <i data-lucide="arrow-down" style="width:11px;height:11px;color:rgba(100,255,150,0.8)"></i>
                        <span><?= number_format($acc['total_income'], 0, '.', ' ') ?> ₽</span>
                    </div>
                    <div class="bank-card__flow">
                        <i data-lucide="arrow-up" style="width:11px;height:11px;color:rgba(255,100,100,0.8)"></i>
                        <span><?= number_format($acc['total_expense'], 0, '.', ' ') ?> ₽</span>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div><!-- /bank-cards-row -->

    <!-- [5] Трекер поставок — карточки напрямую в 6-кол. гриде -->
    <?php foreach ($trackerShipments as $s):
            $today     = strtotime('today');
            $orderDate = strtotime($s['order_date']);
            $eta       = $s['eta'] ? strtotime($s['eta']) : null;
            $isOverdue = $eta && $today > $eta && $s['status'] === 'В пути';
            $isWaiting = $s['status'] === 'Ожидает отправки' || $s['status'] === '⚠️';

            $pct = 0;
            if (!$isWaiting && $eta && $eta > $orderDate) {
                $pct = min(100, round(($today - $orderDate) / ($eta - $orderDate) * 100));
            }

            $shipmentName  = htmlspecialchars($s['shipment_name'] ?? '—');
            $counterparty  = htmlspecialchars(trim($s['company_type'].' '.$s['counterparty']));
            $fillPct       = $isOverdue ? 100 : ($isWaiting ? 0 : $pct);

            // Цвета кольца
            if ($isOverdue)     { $ringColor = '#ef4444'; $ringGlow = 'rgba(239,68,68,0.4)';  $textColor = '#ef4444'; }
            elseif ($isWaiting) { $ringColor = '#4b5563'; $ringGlow = 'rgba(75,85,99,0.2)';   $textColor = '#6b7280'; }
            elseif ($pct >= 50) { $ringColor = '#4ade80'; $ringGlow = 'rgba(74,222,128,0.4)'; $textColor = '#4ade80'; }
            else                { $ringColor = '#facc15'; $ringGlow = 'rgba(250,204,21,0.4)'; $textColor = '#facc15'; }

            // Кольцо: r=36, circumference = 2πr ≈ 226.19
            $r     = 36;
            $circ  = round(2 * M_PI * $r, 2);
            $offset = round($circ * (1 - $fillPct / 100), 2);
        ?>
        <div class="tracker-card" onclick="openShipment(<?= $s['id'] ?>)">
            <div class="tracker-card__counterparty"><?= $counterparty ?></div>
            <div class="tracker-card__title"><?= $shipmentName ?></div>

            <!-- Кольцо Apple Watch -->
            <div class="tracker-ring-wrap">
                <svg viewBox="0 0 100 100" class="tracker-ring-svg">
                    <defs>
                        <filter id="glow-<?= $s['id'] ?>" x="-50%" y="-50%" width="200%" height="200%">
                            <feGaussianBlur stdDeviation="2.5" result="blur"/>
                            <feMerge><feMergeNode in="blur"/><feMergeNode in="SourceGraphic"/></feMerge>
                        </filter>
                    </defs>

                    <!-- Фоновое кольцо (трек) -->
                    <circle cx="50" cy="50" r="<?= $r ?>"
                            fill="none"
                            stroke="rgba(255,255,255,0.07)"
                            stroke-width="9"/>

                    <?php if ($isWaiting): ?>
                        <!-- Спиннер: короткая дуга вращается -->
                        <circle cx="50" cy="50" r="<?= $r ?>"
                                fill="none"
                                stroke="<?= $ringColor ?>"
                                stroke-width="9"
                                stroke-linecap="round"
                                stroke-dasharray="<?= round($circ * 0.25, 2) ?> <?= round($circ * 0.75, 2) ?>"
                                transform="rotate(-90 50 50)"
                                class="tracker-ring-spin"/>
                    <?php else: ?>
                        <!-- Прогресс кольцо -->
                        <circle cx="50" cy="50" r="<?= $r ?>"
                                fill="none"
                                stroke="<?= $ringColor ?>"
                                stroke-width="9"
                                stroke-linecap="round"
                                stroke-dasharray="<?= $circ ?>"
                                stroke-dashoffset="<?= $offset ?>"
                                transform="rotate(-90 50 50)"
                                filter="url(#glow-<?= $s['id'] ?>)"
                                opacity="0.9"/>
                    <?php endif; ?>

                    <?php if ($isOverdue): ?>
                        <!-- Крестик пульсирующий -->
                        <line x1="38" y1="38" x2="62" y2="62" stroke="<?= $ringColor ?>" stroke-width="3.5" stroke-linecap="round" class="tracker-pulse"/>
                        <line x1="62" y1="38" x2="38" y2="62" stroke="<?= $ringColor ?>" stroke-width="3.5" stroke-linecap="round" class="tracker-pulse"/>
                    <?php elseif ($isWaiting): ?>
                        <text x="50" y="50" text-anchor="middle" dominant-baseline="middle"
                              font-size="11" fill="<?= $textColor ?>" opacity="0.8"
                              font-family="-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif">Ожидает</text>
                    <?php else: ?>
                        <text x="50" y="50" text-anchor="middle" dominant-baseline="middle"
                              font-size="20" font-weight="700" fill="<?= $textColor ?>"
                              font-family="-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif"><?= $pct ?>%</text>
                    <?php endif; ?>
                </svg>
            </div>

            <div class="tracker-card__footer">
                <span><?= $s['order_date'] ? date('d.m.Y', strtotime($s['order_date'])) : '—' ?></span>
                <span class="<?= $isOverdue ? 'tracker-card__eta--overdue' : '' ?>">ETA: <?= $eta ? date('d.m.Y', $eta) : '—' ?></span>
            </div>
        </div>
        <?php endforeach; ?>

    </div><!-- /dashboard-right-bottom -->

</div><!-- /dashboard-layout -->

<script>
setTimeout(() => {
    if (typeof loadNotesTile === 'function') loadNotesTile();
    if (typeof initDealsWidget === 'function') initDealsWidget();
}, 100);

// ===== Календарь поставок =====
(function() {
    const calData = <?= json_encode($calendarData, JSON_UNESCAPED_UNICODE) ?>;

    let curYear, curMonth;
    const today = new Date();
    curYear  = today.getFullYear();
    curMonth = today.getMonth(); // 0-based

    const monthNames = ['Январь','Февраль','Март','Апрель','Май','Июнь',
                        'Июль','Август','Сентябрь','Октябрь','Ноябрь','Декабрь'];

    const grid    = document.getElementById('cal-grid');
    const title   = document.getElementById('cal-title');
    const tooltip = document.getElementById('cal-tooltip');

    function renderCalendar() {
        title.textContent = monthNames[curMonth] + ' ' + curYear;
        grid.innerHTML = '';
        tooltip.style.display = 'none';

        // Первый день месяца (0=вс..6=сб → переводим в пн=0)
        const firstDay = new Date(curYear, curMonth, 1).getDay();
        const offset   = (firstDay === 0) ? 6 : firstDay - 1;
        const daysInMonth = new Date(curYear, curMonth + 1, 0).getDate();

        // Пустые ячейки перед первым днём
        for (let i = 0; i < offset; i++) {
            const empty = document.createElement('div');
            empty.className = 'cal-day cal-day--empty';
            grid.appendChild(empty);
        }

        for (let d = 1; d <= daysInMonth; d++) {
            const dateStr = curYear + '-'
                + String(curMonth + 1).padStart(2, '0') + '-'
                + String(d).padStart(2, '0');

            const cell = document.createElement('div');
            cell.className = 'cal-day';

            const isToday = (curYear === today.getFullYear() &&
                             curMonth === today.getMonth() &&
                             d === today.getDate());
            if (isToday) cell.classList.add('cal-day--today');

            const shipments = calData[dateStr];
            if (shipments && shipments.length > 0) {
                cell.classList.add('cal-day--has-eta');
                cell.setAttribute('data-date', dateStr);

                cell.addEventListener('mouseenter', function(e) {
                    const items = calData[dateStr];
                    let html = '<div class="cal-tooltip__date">' + dateStr.split('-').reverse().join('.') + '</div>';
                    items.forEach(function(sh) {
                        html += '<div class="cal-tooltip__item">'
                              + '<span class="cal-tooltip__name">' + sh.name + '</span>'
                              + '<span class="cal-tooltip__cp">' + sh.counterparty + '</span>'
                              + '</div>';
                    });
                    tooltip.innerHTML = html;
                    tooltip.style.display = 'block';
                    positionTooltip(e, cell);
                });
                cell.addEventListener('mouseleave', function() {
                    tooltip.style.display = 'none';
                });
            }

            const num = document.createElement('span');
            num.textContent = d;
            cell.appendChild(num);

            if (shipments && shipments.length > 0) {
                const dot = document.createElement('span');
                dot.className = 'cal-dot';
                cell.appendChild(dot);
            }

            grid.appendChild(cell);
        }

        lucide.createIcons();
    }

    function positionTooltip(e, cell) {
        const calRect  = document.querySelector('.dashboard-calendar').getBoundingClientRect();
        const cellRect = cell.getBoundingClientRect();
        const tipW = 200;
        let left = cellRect.left - calRect.left + cellRect.width / 2 - tipW / 2;
        let top  = cellRect.bottom - calRect.top + 6;
        left = Math.max(0, Math.min(left, calRect.width - tipW));
        tooltip.style.left = left + 'px';
        tooltip.style.top  = top + 'px';
    }

    document.getElementById('cal-prev').addEventListener('click', function() {
        curMonth--;
        if (curMonth < 0) { curMonth = 11; curYear--; }
        renderCalendar();
    });
    document.getElementById('cal-next').addEventListener('click', function() {
        curMonth++;
        if (curMonth > 11) { curMonth = 0; curYear++; }
        renderCalendar();
    });

    renderCalendar();
})();
</script>
