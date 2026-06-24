<?php
// ── Маршрутные листы: пробег по одометру и топливо (одна машина) ────────────────
$vs = $pdo->query("SELECT * FROM vehicle_settings ORDER BY id LIMIT 1")->fetch();
$rate      = $vs ? (float)$vs['consumption_rate'] : 8.8;
$startOdo  = $vs ? (int)$vs['start_odometer'] : 0;

$trips   = $pdo->query("SELECT * FROM vehicle_trips ORDER BY odometer DESC, date_from DESC, id DESC")->fetchAll();
$fuelups = $pdo->query("
    SELECT f.*, be.name AS expense_name
    FROM fuel_ups f LEFT JOIN business_expenses be ON be.id = f.expense_id
    ORDER BY f.fuel_date DESC, f.id DESC")->fetchAll();
$topups  = $pdo->query("
    SELECT t.id, t.expense_id, be.name, be.amount, be.expense_date
    FROM fuel_card_topups t JOIN business_expenses be ON be.id = t.expense_id
    ORDER BY be.expense_date DESC, t.id DESC")->fetchAll();
$washes  = $pdo->query("SELECT * FROM car_washes ORDER BY wash_date DESC, id DESC")->fetchAll();

// ── Пробег по одометру: дистанция = разница показаний (по возрастанию) ───────────
$asc = $trips;
usort($asc, fn($a, $b) => ((int)$a['odometer'] <=> (int)$b['odometer']));
$dist = []; $prev = $startOdo;
foreach ($asc as $r) {
    $odo = (int)$r['odometer'];
    $dist[$r['id']] = max(0, $odo - $prev);
    $prev = max($prev, $odo);
}
$currentOdo = $trips ? max(array_map(fn($r) => (int)$r['odometer'], $trips)) : $startOdo;
$totalDist  = max(0, $currentOdo - $startOdo);

// средний пробег/день
$dates = array_filter(array_map(fn($r) => $r['date_from'], $trips));
$days = 1;
if ($dates) {
    $minD = min(array_map('strtotime', $dates));
    $maxD = max(array_map('strtotime', $dates));
    $days = max(1, (int)floor(($maxD - $minD) / 86400) + 1);
}
$avgPerDay = $days > 0 ? round($totalDist / $days) : 0;

$lastTrip = $trips[0] ?? null; // max odometer
$lastDist = $lastTrip ? ($dist[$lastTrip['id']] ?? 0) : 0;

// расход факт
$litersAll = 0; foreach ($fuelups as $f) $litersAll += (float)$f['liters'];
$factRate = $totalDist > 0 ? round($litersAll / $totalDist * 100, 1) : null;

// топливная карта: баланс = пополнения − заправки с карты − мойки
$topupSum = 0; foreach ($topups as $t) $topupSum += (float)$t['amount'];
$cardSpent = 0; foreach ($fuelups as $f) if ($f['card_type'] === 'Топливная') $cardSpent += (float)$f['amount'];
$washSum = 0; foreach ($washes as $w) $washSum += (float)$w['amount'];
$cardBalance = $topupSum - $cardSpent - $washSum;

// заправлено за месяц
$ym = date('Y-m'); $monthL = 0; $monthRub = 0;
foreach ($fuelups as $f) if (substr($f['fuel_date'], 0, 7) === $ym) { $monthL += (float)$f['liters']; $monthRub += (float)$f['amount']; }

$nf = fn($v, $d = 0) => number_format($v, $d, '.', ' ');
?>

<div class="page-header"><h1 class="page-title">Маршрутные листы</h1></div>
<script>
window._gsPageActions = ''
    + '<button class="btn btn-ghost" onclick="openVehicleSettings()"><i data-lucide="settings" style="width:13px;height:13px"></i> Настройки</button>'
    + '<button class="btn btn-ghost" onclick="openFuelUp()"><i data-lucide="fuel" style="width:13px;height:13px"></i> Заправка</button>'
    + '<button class="btn btn-primary" onclick="openTrip()">+ Пробег</button>';
</script>

<!-- ── Карточки ── -->
<div class="vehicle-cards">
    <div class="stat-card veh-card">
        <div class="stat-label">Текущий одометр</div>
        <div class="stat-value"><?= $nf($currentOdo) ?> <span class="veh-unit">км</span></div>
        <div class="stat-sub">старт <?= $nf($startOdo) ?> · проехал <?= $nf($totalDist) ?> км</div>
    </div>
    <div class="stat-card veh-card">
        <div class="stat-label">Расход (факт)</div>
        <div class="stat-value"><?= $factRate !== null ? $nf($factRate, 1) : '—' ?> <span class="veh-unit">л/100</span></div>
        <div class="stat-sub">норматив <?= $nf($rate, 1) ?> л/100 км</div>
    </div>
    <div class="stat-card veh-card">
        <div class="stat-label">Средний пробег/день</div>
        <div class="stat-value"><?= $nf($avgPerDay) ?> <span class="veh-unit">км</span></div>
        <div class="stat-sub">всего <?= $nf($totalDist) ?> км</div>
    </div>
    <div class="stat-card veh-card">
        <div class="stat-label">Последний пробег</div>
        <div class="stat-value"><?= $lastTrip ? $nf($lastDist) : '—' ?> <span class="veh-unit">км</span></div>
        <div class="stat-sub"><?= $lastTrip ? date('d.m.Y', strtotime($lastTrip['date_from'])) : 'нет записей' ?></div>
    </div>
    <div class="stat-card veh-card">
        <div class="stat-label">Остаток на топл. карте</div>
        <div class="stat-value" style="color:<?= $cardBalance >= 0 ? 'var(--text)' : 'var(--danger)' ?>"><?= $nf($cardBalance) ?> ₽</div>
        <div class="stat-sub">пополнено <?= $nf($topupSum) ?> ₽</div>
    </div>
    <div class="stat-card veh-card">
        <div class="stat-label">Заправлено за месяц</div>
        <div class="stat-value"><?= $nf($monthL, 1) ?> <span class="veh-unit">л</span></div>
        <div class="stat-sub"><?= $nf($monthRub) ?> ₽</div>
    </div>
</div>

<!-- ── Пробег ── -->
<div class="veh-section-title">
    <span>Пробег</span>
    <button class="btn btn-ghost btn-sm" onclick="openTrip()">+ Запись</button>
</div>
<?php if (empty($trips)): ?>
    <div class="empty-state" style="padding:24px"><p>Записей пробега пока нет</p><div style="font-size:12px;color:var(--text-dim);margin-top:6px">Стартовый одометр: <?= $nf($startOdo) ?> км</div></div>
<?php else: ?>
<div class="table-wrapper">
    <table>
        <thead><tr><th>Дата</th><th>Одометр</th><th>Проехал</th><th>Расход</th><th>Заметка</th><th></th></tr></thead>
        <tbody class="m-cards m-cards-trip">
            <?php foreach ($trips as $t):
                $d = $dist[$t['id']] ?? 0;
                $cons = $d * $rate / 100; ?>
            <tr onclick='openTrip(<?= json_encode($t, JSON_UNESCAPED_UNICODE) ?>)'>
                <td class="m-cell-label" data-l="Дата"><?= date('d.m.Y', strtotime($t['date_from'])) ?></td>
                <td class="m-cell-label" data-l="Одометр"><?= $nf((int)$t['odometer']) ?> км</td>
                <td class="m-cell-label" data-l="Проехал"><?= $nf($d) ?> км</td>
                <td class="m-cell-label" data-l="Расход"><?= $nf($cons, 1) ?> л</td>
                <td class="cell-muted"><?= htmlspecialchars($t['note'] ?? '') ?: '—' ?></td>
                <td class="row-actions">
                    <button class="btn-icon btn-icon-danger" onclick='event.stopPropagation(); deleteVeh("trip", <?= (int)$t['id'] ?>)' title="Удалить"><i data-lucide="trash-2" style="width:14px;height:14px"></i></button>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<!-- ── Заправки ── -->
<div class="veh-section-title" style="margin-top:28px">
    <span>Заправки</span>
    <button class="btn btn-ghost btn-sm" onclick="openFuelUp()">+ Заправка</button>
</div>
<?php if (empty($fuelups)): ?>
    <div class="empty-state" style="padding:24px"><p>Заправок пока нет</p></div>
<?php else: ?>
<div class="table-wrapper">
    <table>
        <thead><tr><th>Дата</th><th>Литры</th><th>Сумма</th><th>Карта</th><th>Расход ИП</th><th></th></tr></thead>
        <tbody class="m-cards m-cards-fuel">
            <?php foreach ($fuelups as $f):
                $isFuel = $f['card_type'] === 'Топливная'; ?>
            <tr onclick='openFuelUp(<?= json_encode($f, JSON_UNESCAPED_UNICODE) ?>)'>
                <td class="m-cell-label" data-l="Дата"><?= date('d.m.Y', strtotime($f['fuel_date'])) ?></td>
                <td class="m-cell-label" data-l="Литры"><?= $nf((float)$f['liters'], 1) ?> л</td>
                <td class="m-cell-label" data-l="Сумма"><?= $nf((float)$f['amount']) ?> ₽</td>
                <td><span class="badge <?= $isFuel ? 'badge-info' : 'badge-waiting' ?>"><?= $isFuel ? 'Топл. карта' : 'Обычная' ?></span></td>
                <td class="cell-muted"><?= $f['expense_name'] ? htmlspecialchars($f['expense_name']) : '—' ?></td>
                <td class="row-actions">
                    <button class="btn-icon btn-icon-danger" onclick='event.stopPropagation(); deleteVeh("fuelup", <?= (int)$f['id'] ?>)' title="Удалить"><i data-lucide="trash-2" style="width:14px;height:14px"></i></button>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<!-- ── Топливная карта ── -->
<div class="veh-section-title" style="margin-top:28px">
    <span>Топливная карта</span>
    <span style="display:flex;gap:6px">
        <button class="btn btn-ghost btn-sm" onclick="openWash()">+ Мойка</button>
        <button class="btn btn-ghost btn-sm" onclick="openTopup()">+ Пополнение</button>
    </span>
</div>
<div class="veh-card-balance">
    <div>
        <div class="stat-label">Баланс карты</div>
        <div class="stat-value" style="color:<?= $cardBalance >= 0 ? 'var(--accent)' : 'var(--danger)' ?>"><?= $nf($cardBalance) ?> ₽</div>
    </div>
    <div class="veh-card-balance-sub">
        Пополнено <?= $nf($topupSum) ?> ₽ · топливо <?= $nf($cardSpent) ?> ₽ · мойка <?= $nf($washSum) ?> ₽
    </div>
</div>

<?php if (!empty($washes)): ?>
<div class="veh-sub-label">Мойки и списания</div>
<div class="table-wrapper">
    <table>
        <thead><tr><th>Дата</th><th>Сумма</th><th>Заметка</th><th></th></tr></thead>
        <tbody class="m-cards m-cards-wash">
            <?php foreach ($washes as $w): ?>
            <tr onclick='openWash(<?= json_encode($w, JSON_UNESCAPED_UNICODE) ?>)'>
                <td class="m-cell-label" data-l="Дата"><?= date('d.m.Y', strtotime($w['wash_date'])) ?></td>
                <td class="m-cell-label" data-l="Сумма"><?= $nf((float)$w['amount']) ?> ₽</td>
                <td class="cell-muted"><?= htmlspecialchars($w['note'] ?? '') ?: 'Мойка' ?></td>
                <td class="row-actions">
                    <button class="btn-icon btn-icon-danger" onclick='event.stopPropagation(); deleteVeh("wash", <?= (int)$w['id'] ?>)' title="Удалить"><i data-lucide="trash-2" style="width:14px;height:14px"></i></button>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php if (!empty($topups)): ?>
<div class="veh-sub-label">Пополнения (расходы ИП)</div>
<div class="table-wrapper">
    <table>
        <thead><tr><th>Расход ИП</th><th>Дата</th><th>Сумма</th><th></th></tr></thead>
        <tbody class="m-cards m-cards-topup">
            <?php foreach ($topups as $t): ?>
            <tr>
                <td class="m-cell-label" data-l="Расход"><?= htmlspecialchars($t['name']) ?></td>
                <td class="m-cell-label" data-l="Дата"><?= date('d.m.Y', strtotime($t['expense_date'])) ?></td>
                <td class="m-cell-label" data-l="Сумма"><?= $nf((float)$t['amount']) ?> ₽</td>
                <td class="row-actions">
                    <button class="btn-icon btn-icon-danger" onclick='deleteVeh("topup", <?= (int)$t['id'] ?>)' title="Убрать"><i data-lucide="x" style="width:14px;height:14px"></i></button>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<script>
window._vehSettings = <?= json_encode(['consumption_rate' => $rate, 'start_odometer' => $startOdo, 'last_odometer' => $currentOdo], JSON_UNESCAPED_UNICODE) ?>;
if (window.lucide) lucide.createIcons();
</script>
