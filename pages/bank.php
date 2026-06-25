<?php
require_once __DIR__ . '/_bank_op_row.php';

const BANK_OPS_PAGE_SIZE = 50;

$accounts = $pdo->query("SELECT * FROM bank_accounts ORDER BY id")->fetchAll();

// Балансы по подтверждённым операциям — одним сгруппированным запросом (без N+1)
$balancesMap = [];
foreach ($pdo->query("
    SELECT account_id,
        SUM(CASE WHEN type IN ('Продажа','Прочий приход')       THEN amount ELSE 0 END) as total_income,
        SUM(CASE WHEN type IN ('Закупка','Расход','Выплата ЗП')  THEN amount ELSE 0 END) as total_expense,
        SUM(CASE WHEN type = 'Перевод'                           THEN amount ELSE 0 END) as transfer_net
    FROM bank_operations
    WHERE status IN ('confirmed','pending')
    GROUP BY account_id
")->fetchAll() as $row) {
    $balancesMap[$row['account_id']] = $row;
}

$accountsData = [];
foreach ($accounts as $acc) {
    $b = $balancesMap[$acc['id']] ?? ['total_income' => 0, 'total_expense' => 0, 'transfer_net' => 0];
    $accountsData[] = [
        'id'              => $acc['id'],
        'name'            => $acc['name'],
        'initial_balance' => $acc['initial_balance'],
        'total_income'    => $b['total_income'],
        'total_expense'   => $b['total_expense'],
        // Переводы (±) входят в баланс, но не в ↑приход/↓расход счёта
        'balance'         => $acc['initial_balance'] + $b['total_income'] - $b['total_expense'] + ($b['transfer_net'] ?? 0)
    ];
}

$totalBalance = array_sum(array_column($accountsData, 'balance'));

// Первая страница операций (листание — постранично, через api operations_html).
// Лента фильтруется по периоду; балансы счетов выше — кумулятивные (период не применяется).
[$opPeriodCond, $opPeriodP] = periodFilter('bo.operation_date');
$opsCntStmt = $pdo->prepare("SELECT COUNT(*) FROM bank_operations bo WHERE bo.status IN ('confirmed','pending') AND $opPeriodCond");
$opsCntStmt->execute($opPeriodP);
$opsTotal = (int)$opsCntStmt->fetchColumn();
$opsTotalPages = max(1, (int)ceil($opsTotal / BANK_OPS_PAGE_SIZE));

$opsStmt = $pdo->prepare("
    SELECT bo.*, ba.name as account_name
    FROM bank_operations bo
    LEFT JOIN bank_accounts ba ON ba.id = bo.account_id
    WHERE bo.status IN ('confirmed','pending') AND $opPeriodCond
    ORDER BY bo.operation_date DESC, bo.created_at DESC
    LIMIT " . BANK_OPS_PAGE_SIZE . "
");
$opsStmt->execute($opPeriodP);
$operations = $opsStmt->fetchAll();

?>

<div class="bank-cards-row bank-cards-row--bank">
    <?php
    $bankThemes = ['альфа' => 'alfa', 'альфа-банк' => 'alfa', 'точка' => 'tochka', 'ozon' => 'ozon', 'озон' => 'ozon'];
    foreach ($accountsData as $acc):
        $theme = 'alfa';
        foreach ($bankThemes as $keyword => $t) {
            if (mb_stripos($acc['name'], $keyword) !== false) { $theme = $t; break; }
        }
    ?>
    <div class="bank-card bank-card--<?= $theme ?>" style="cursor:pointer"
         onclick="openEditBalance(<?= $acc['id'] ?>, <?= htmlspecialchars(json_encode($acc['name'], JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>, <?= $acc['initial_balance'] ?>)">
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
            <div class="bank-card__balance"><?= number_format($acc['balance'], 2, ',', ' ') ?> ₽</div>
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

    <!-- Общий баланс — нейтральная карта -->
    <div class="bank-card bank-card--total">
        <div class="bank-card__top">
            <div class="bank-card__logo">
                <i data-lucide="wallet" style="width:24px;height:24px;color:rgba(255,255,255,0.85)"></i>
            </div>
            <div class="bank-card__name">Общий баланс</div>
        </div>
        <div class="bank-card__chiprow">
            <div class="bank-card__chip"></div>
            <div class="bank-card__balance" style="<?= $totalBalance < 0 ? 'color:var(--danger)' : '' ?>">
                <?= number_format($totalBalance, 2, ',', ' ') ?> ₽
            </div>
        </div>
        <div class="bank-card__bottom">
            <div class="bank-card__flows">
                <div class="bank-card__flow">
                    <i data-lucide="arrow-down" style="width:11px;height:11px;color:rgba(100,255,150,0.8)"></i>
                    <span><?= number_format(array_sum(array_column($accountsData, 'total_income')), 0, '.', ' ') ?> ₽</span>
                </div>
                <div class="bank-card__flow">
                    <i data-lucide="arrow-up" style="width:11px;height:11px;color:rgba(255,100,100,0.8)"></i>
                    <span><?= number_format(array_sum(array_column($accountsData, 'total_expense')), 0, '.', ' ') ?> ₽</span>
                </div>
            </div>
            <span class="bank-card__mir" style="letter-spacing:0.5px;font-size:11px">ВСЕ СЧЕТА</span>
        </div>
    </div>
</div>

<div class="tab-toolbar">
    <div class="tab-toolbar-left">
        <select class="form-control" id="filter-account" onchange="filterBankOps()">
            <option value="">Все счета</option>
            <?php foreach ($accountsData as $acc): ?>
            <option value="<?= $acc['id'] ?>"><?= htmlspecialchars($acc['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select class="form-control" id="filter-type" onchange="filterBankOps()">
            <option value="">Все типы</option>
            <option value="income">Приходы</option>
            <option value="expense">Расходы</option>
        </select>
        <?= $finPeriodSelect ?? '' ?>
        <input type="text" id="search-bank" class="form-control toolbar-search" placeholder="Поиск...">
    </div>
    <div class="tab-toolbar-right">
        <button class="btn-icon" title="Выгрузить в Excel" onclick="exportBankOps()" style="color:var(--text-muted)">
            <i data-lucide="file-spreadsheet" style="width:15px;height:15px"></i>
        </button>
        <button class="btn btn-ghost" style="display:flex;align-items:center;gap:8px" onclick="openBalanceCalibration()">
            <i data-lucide="scan-line" style="width:14px;height:14px"></i>
            Калибровка баланса
        </button>
        <button class="btn btn-primary" onclick="openAddBankOperation()">+ Операция</button>
    </div>
</div>

<div class="table-wrapper">
    <table id="bank-ops-table">
        <thead>
            <tr>
                <th>Дата</th>
                <th>Тип</th>
                <th>Наименование</th>
                <th>Счёт</th>
                <th style="text-align:right">Сумма</th>
                <th></th>
            </tr>
        </thead>
        <tbody id="bank-ops-tbody" class="m-cards m-cards-bank"
               data-period-all="<?= $filterActive ? '0' : '1' ?>">
            <?php if (empty($operations)): ?>
                <tr id="bank-ops-empty"><td colspan="6" style="text-align:center;color:var(--text-muted);padding:40px">
                    Нет операций за выбранный период
                </td></tr>
            <?php else: ?>
                <?php foreach ($operations as $op) echo renderBankOpRow($op); ?>
            <?php endif; ?>
        </tbody>
    </table>
    <!-- Постраничная навигация -->
    <div class="bank-pager" id="bank-pager" data-total-pages="<?= $opsTotalPages ?>" style="<?= $opsTotalPages <= 1 ? 'display:none' : '' ?>">
        <button class="btn btn-ghost btn-sm" id="bank-pager-prev" onclick="bankGoPage(-1)" disabled>
            <i data-lucide="chevron-left" style="width:13px;height:13px"></i> Назад
        </button>
        <span class="bank-pager-info" id="bank-pager-info">Стр. 1 из <?= $opsTotalPages ?></span>
        <button class="btn btn-ghost btn-sm" id="bank-pager-next" onclick="bankGoPage(1)" <?= $opsTotalPages <= 1 ? 'disabled' : '' ?>>
            Вперёд <i data-lucide="chevron-right" style="width:13px;height:13px"></i>
        </button>
    </div>
</div>

<script>
setTimeout(() => { if (typeof initBankPage === 'function') initBankPage(); }, 0);
</script>
