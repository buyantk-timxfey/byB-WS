<?php
require_once '../config.php';
session_start();
if (!isset($_SESSION['auth']) || $_SESSION['auth'] !== true) {
    http_response_code(401); echo json_encode(['error' => 'Unauthorized']); exit;
}
header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

// ── Вспомогательные функции ──────────────────────────────────────────────────

function parseDateRU(string $d): ?string {
    if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $d, $m)) {
        return "{$m[3]}-{$m[2]}-{$m[1]}";
    }
    return null;
}

/**
 * Парсит файл формата 1CClientBankExchange (кодировка Windows-1251).
 * Возвращает ['sections' => [...], 'account' => [...|null]]
 */
function parse1cStatement(string $rawBytes): array {
    $content = iconv('Windows-1251', 'UTF-8//IGNORE', $rawBytes);
    $lines   = preg_split('/\r?\n/', $content);

    $sections = [];
    $cur      = null;
    $account  = null;
    $inAcct   = false;
    $acctBuf  = [];

    $prefix    = 'СекцияДокумент=';
    $prefixLen = mb_strlen($prefix);

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;

        if ($line === 'СекцияРасчСчет') {
            $inAcct = true; $acctBuf = []; continue;
        }
        if ($line === 'КонецРасчСчет') {
            if ($inAcct) { $account = $acctBuf; $inAcct = false; } continue;
        }
        if ($inAcct && str_contains($line, '=')) {
            [$k, $v] = explode('=', $line, 2);
            $acctBuf[trim($k)] = trim($v);
            continue;
        }

        if (str_starts_with($line, $prefix)) {
            $cur = ['_doctype' => trim(mb_substr($line, $prefixLen))];
        } elseif ($line === 'КонецДокумента') {
            if ($cur !== null) { $sections[] = $cur; $cur = null; }
        } elseif ($cur !== null && str_contains($line, '=')) {
            [$k, $v] = explode('=', $line, 2);
            $cur[trim($k)] = trim($v);
        }
    }
    return ['sections' => $sections, 'account' => $account];
}

// ── Маршрутизация ─────────────────────────────────────────────────────────────
try {
switch ($method) {

    // ════════════════════════════════════════════════════════════
    case 'GET':

        // Счета с балансами
        if (isset($_GET['accounts'])) {
            $accounts = $pdo->query("SELECT * FROM bank_accounts ORDER BY id")->fetchAll();

            $balancesMap = [];
            foreach ($pdo->query("
                SELECT account_id,
                    SUM(CASE WHEN type IN ('Продажа','Прочий приход') THEN amount ELSE 0 END) as total_income,
                    SUM(CASE WHEN type IN ('Закупка','Расход','Выплата ЗП') THEN amount ELSE 0 END) as total_expense,
                    SUM(CASE WHEN type = 'Перевод' THEN amount ELSE 0 END) as transfer_net
                FROM bank_operations
                WHERE status IN ('confirmed','pending')
                GROUP BY account_id
            ")->fetchAll() as $row) {
                $balancesMap[$row['account_id']] = $row;
            }

            foreach ($accounts as &$acc) {
                $b = $balancesMap[$acc['id']] ?? ['total_income' => 0, 'total_expense' => 0, 'transfer_net' => 0];
                $acc['total_income']  = $b['total_income'];
                $acc['total_expense'] = $b['total_expense'];
                $acc['balance']       = $acc['initial_balance'] + $b['total_income'] - $b['total_expense'] + ($b['transfer_net'] ?? 0);
            }
            echo json_encode($accounts);
            break;
        }

        // Детали одной операции
        if (isset($_GET['operation_id'])) {
            $stmt = $pdo->prepare("
                SELECT bo.*, ba.name as account_name
                FROM bank_operations bo
                LEFT JOIN bank_accounts ba ON ba.id = bo.account_id
                WHERE bo.id = ?
            ");
            $stmt->execute([$_GET['operation_id']]);
            $op = $stmt->fetch();
            if (!$op) { echo json_encode(['error' => 'Операция не найдена']); break; }

            if ($op['sale_id']) {
                $src = $pdo->prepare("
                    SELECT s.id, s.sale_date as date, s.sale_price as amount, s.status,
                        COALESCE(c.name, s.buyer_name, '—') as counterparty,
                        GROUP_CONCAT(w.name SEPARATOR ', ') as items
                    FROM sales s
                    LEFT JOIN counterparties c ON c.id = s.counterparty_id
                    LEFT JOIN sale_items si ON si.sale_id = s.id
                    LEFT JOIN warehouse w ON w.id = si.warehouse_id
                    WHERE s.id = ? GROUP BY s.id
                ");
                $src->execute([$op['sale_id']]);
                $op['source'] = $src->fetch();
                $op['source_type'] = 'sale';
            } elseif ($op['shipment_id']) {
                $src = $pdo->prepare("
                    SELECT s.id, s.order_date as date, s.status,
                        COALESCE(SUM(si.quantity * si.purchase_price), 0) + s.carrier_cost as amount,
                        COALESCE(c.name, '—') as counterparty, s.carrier_cost
                    FROM shipments s
                    LEFT JOIN counterparties c ON c.id = s.counterparty_id
                    LEFT JOIN shipment_items si ON si.shipment_id = s.id
                    WHERE s.id = ? GROUP BY s.id
                ");
                $src->execute([$op['shipment_id']]);
                $op['source'] = $src->fetch();
                $op['source_type'] = 'shipment';
            } else {
                $op['source'] = null; $op['source_type'] = 'manual';
            }
            echo json_encode($op);
            break;
        }

        // Догрузка ленты операций (постраничная)
        if (isset($_GET['operations_html'])) {
            require_once __DIR__ . '/../pages/_bank_op_row.php';
            $pageSize = 50;
            $offset   = max(0, (int)($_GET['offset'] ?? 0));

            $where  = ["bo.status IN ('confirmed','pending')"];
            $params = [];
            if (!empty($_GET['account_id'])) { $where[] = 'bo.account_id = ?'; $params[] = (int)$_GET['account_id']; }
            if (($_GET['direction'] ?? '') === 'income') {
                $where[] = "(bo.type IN ('Продажа','Прочий приход') OR (bo.type='Перевод' AND bo.amount >= 0))";
            } elseif (($_GET['direction'] ?? '') === 'expense') {
                $where[] = "(bo.type IN ('Закупка','Расход','Выплата ЗП') OR (bo.type='Перевод' AND bo.amount < 0))";
            }
            if (($_GET['period'] ?? '') !== 'all') {
                $m = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('n');
                $y = isset($_GET['year'])  ? (int)$_GET['year']  : (int)date('Y');
                $where[]  = "MONTH(bo.operation_date) = ? AND YEAR(bo.operation_date) = ?";
                $params[] = $m;
                $params[] = $y;
            }
            $whereStr = 'WHERE ' . implode(' AND ', $where);

            $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM bank_operations bo {$whereStr}");
            $cntStmt->execute($params);
            $total = (int)$cntStmt->fetchColumn();

            $stmt = $pdo->prepare("
                SELECT bo.*, ba.name as account_name
                FROM bank_operations bo
                LEFT JOIN bank_accounts ba ON ba.id = bo.account_id
                {$whereStr}
                ORDER BY bo.operation_date DESC, bo.created_at DESC
                LIMIT " . $pageSize . " OFFSET " . $offset . "
            ");
            $stmt->execute($params);
            $rows = $stmt->fetchAll();

            $html = '';
            foreach ($rows as $op) $html .= renderBankOpRow($op);

            echo json_encode([
                'html'        => $html,
                'count'       => count($rows),
                'total'       => $total,
                'has_more'    => $offset + count($rows) < $total,
                'next_offset' => $offset + count($rows),
            ]);
            break;
        }

        // Лента операций (JSON)
        if (isset($_GET['operations'])) {
            $where  = ["bo.status IN ('confirmed','pending')"];
            $params = [];
            if (!empty($_GET['account_id'])) { $where[] = 'bo.account_id = ?'; $params[] = $_GET['account_id']; }
            if (!empty($_GET['type']))        { $where[] = 'bo.type = ?';       $params[] = $_GET['type']; }
            $whereStr = 'WHERE ' . implode(' AND ', $where);

            $stmt = $pdo->prepare("
                SELECT bo.*, ba.name as account_name
                FROM bank_operations bo
                LEFT JOIN bank_accounts ba ON ba.id = bo.account_id
                {$whereStr}
                ORDER BY bo.operation_date DESC, bo.created_at DESC
            ");
            $stmt->execute($params);
            echo json_encode($stmt->fetchAll());
            break;
        }
        break;

    // ════════════════════════════════════════════════════════════
    case 'POST':

        // ── Калибровка баланса по выписке (multipart) ────────────────────────
        if (isset($_FILES['statement'])) {
            $accountId = (int)($_POST['account_id'] ?? 0);
            if (!$accountId) apiError('Не выбран счёт', null, 400);

            $raw    = file_get_contents($_FILES['statement']['tmp_name']);
            $parsed = parse1cStatement($raw);
            $acctInfo = $parsed['account'];
            if (!$acctInfo) apiError('Не удалось распознать секцию счёта в файле выписки', null, 422);

            $p = fn($k) => floatval(str_replace(',', '.', $acctInfo[$k] ?? '0'));
            $statementBalance = $p('КонечныйОстаток');
            $startDate = parseDateRU($acctInfo['ДатаНачала'] ?? '');
            $endDate   = parseDateRU($acctInfo['ДатаКонца'] ?? '');

            // Баланс счёта в ЦРМ
            $accStmt = $pdo->prepare("SELECT * FROM bank_accounts WHERE id = ?");
            $accStmt->execute([$accountId]);
            $account = $accStmt->fetch();
            if (!$account) apiError('Счёт не найден', null, 404);

            $balStmt = $pdo->prepare("
                SELECT
                    COALESCE(SUM(CASE WHEN type IN ('Продажа','Прочий приход') THEN amount ELSE 0 END), 0) as total_income,
                    COALESCE(SUM(CASE WHEN type IN ('Закупка','Расход','Выплата ЗП') THEN amount ELSE 0 END), 0) as total_expense,
                    COALESCE(SUM(CASE WHEN type = 'Перевод' THEN amount ELSE 0 END), 0) as transfer_net
                FROM bank_operations
                WHERE account_id = ? AND status IN ('confirmed','pending')
            ");
            $balStmt->execute([$accountId]);
            $bal = $balStmt->fetch();
            $crmBalance = round(
                $account['initial_balance'] + $bal['total_income'] - $bal['total_expense'] + $bal['transfer_net'],
                2
            );

            // Операции за период выписки — для контекста при расхождении
            $periodOps = [];
            if ($startDate && $endDate) {
                $opsStmt = $pdo->prepare("
                    SELECT bo.id, bo.type, bo.amount, bo.description, bo.operation_date, bo.status,
                           ba.name as account_name
                    FROM bank_operations bo
                    LEFT JOIN bank_accounts ba ON ba.id = bo.account_id
                    WHERE bo.account_id = ? AND bo.status IN ('confirmed','pending')
                      AND bo.operation_date BETWEEN ? AND ?
                    ORDER BY bo.operation_date DESC
                ");
                $opsStmt->execute([$accountId, $startDate, $endDate]);
                $periodOps = $opsStmt->fetchAll();
            }

            echo json_encode([
                'statement_balance' => $statementBalance,
                'crm_balance'       => $crmBalance,
                'difference'        => round($crmBalance - $statementBalance, 2),
                'account_name'      => $account['name'],
                'date_range'        => ['start' => $startDate, 'end' => $endDate],
                'period_operations' => $periodOps,
                'statement_info'    => [
                    'total_in'  => $p('ВсегоПоступило'),
                    'total_out' => $p('ВсегоСписано'),
                    'opening'   => $p('НачальныйОстаток'),
                ],
            ]);
            break;
        }

        $data = json_decode(file_get_contents('php://input'), true) ?? [];

        if (isset($data['action'])) {
            switch ($data['action']) {

                // Перевод между счетами — атомарно, двумя связанными операциями
                case 'transfer':
                    $fromId = (int)($data['from_account_id'] ?? 0);
                    $toId   = (int)($data['to_account_id']   ?? 0);
                    $amount = round((float)($data['amount'] ?? 0), 2);
                    $date   = $data['operation_date'] ?? '';
                    $desc   = trim($data['description'] ?? '');
                    if ($desc === '') $desc = 'Перевод между счетами';
                    if (!$fromId || !$toId || $fromId === $toId || $amount <= 0 || !$date) {
                        apiError('Неверные параметры перевода', null, 400);
                    }
                    $pdo->beginTransaction();
                    try {
                        $ins = $pdo->prepare("
                            INSERT INTO bank_operations (account_id, type, amount, description, operation_date, status)
                            VALUES (?, 'Перевод', ?, ?, ?, 'confirmed')
                        ");
                        $ins->execute([$fromId, -$amount, $desc, $date]);
                        $outId = (int)$pdo->lastInsertId();
                        $ins->execute([$toId, $amount, $desc, $date]);
                        $inId = (int)$pdo->lastInsertId();
                        $pdo->prepare("UPDATE bank_operations SET transfer_id=? WHERE id IN (?,?)")
                            ->execute([$outId, $outId, $inId]);
                        $pdo->commit();
                        echo json_encode(['success' => true, 'transfer_id' => $outId]);
                    } catch (\Throwable $e) {
                        $pdo->rollBack();
                        apiError('Не удалось выполнить перевод', $e);
                    }
                    break;

                default:
                    apiError('Неизвестное действие', null, 400);
            }
            break;
        }

        // Обновить начальный баланс счёта
        if (isset($data['update_balance'])) {
            $pdo->prepare("UPDATE bank_accounts SET initial_balance = ? WHERE id = ?")
                ->execute([$data['initial_balance'], $data['account_id']]);
            echo json_encode(['success' => true]);
            break;
        }

        // Создать операцию вручную
        if (empty($data['account_id']) || empty($data['amount']) || empty($data['operation_date'])) {
            echo json_encode(['error' => 'Заполните обязательные поля']); break;
        }
        $pdo->prepare("INSERT INTO bank_operations (account_id, type, amount, description, operation_date, status) VALUES (?, ?, ?, ?, ?, 'confirmed')")
            ->execute([$data['account_id'], $data['type'], $data['amount'], $data['description'] ?? '', $data['operation_date']]);
        echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
        break;

    // ════════════════════════════════════════════════════════════
    case 'PUT':
        $data  = json_decode(file_get_contents('php://input'), true) ?? [];
        $opId  = (int)($data['id'] ?? 0);
        $newAmount = floatval($data['amount'] ?? 0);
        if (!$opId || !isset($data['amount'])) apiError('Неверные параметры: нужны id и amount', null, 400);

        $op = $pdo->prepare("SELECT * FROM bank_operations WHERE id = ?");
        $op->execute([$opId]);
        $operation = $op->fetch();
        if (!$operation) apiError('Операция не найдена', null, 404);

        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE bank_operations SET amount=?, description=?, operation_date=?, account_id=? WHERE id=?")
                ->execute([$newAmount, $data['description'] ?? $operation['description'], $data['operation_date'] ?? $operation['operation_date'], $data['account_id'] ?? $operation['account_id'], $opId]);

            if ($operation['sale_id'] && !empty($data['sync_source'])) {
                $pdo->prepare("UPDATE sales SET sale_price=? WHERE id=?")->execute([$newAmount, $operation['sale_id']]);
            }
            if ($operation['shipment_id'] && !empty($data['sync_source'])) {
                $items = $pdo->prepare("SELECT COALESCE(SUM(quantity*purchase_price),0) FROM shipment_items WHERE shipment_id=?");
                $items->execute([$operation['shipment_id']]);
                $pdo->prepare("UPDATE shipments SET carrier_cost=? WHERE id=?")
                    ->execute([max(0, $newAmount - $items->fetchColumn()), $operation['shipment_id']]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            apiError('Не удалось обновить операцию', $e);
        }

        echo json_encode(['success' => true]);
        break;

    // ════════════════════════════════════════════════════════════
    case 'DELETE':
        $delId = (int)$_GET['id'];
        $pdo->beginTransaction();
        try {
            // Если операция — часть перевода, удаляем обе ноги
            $tr = $pdo->prepare("SELECT transfer_id FROM bank_operations WHERE id=?");
            $tr->execute([$delId]);
            $transferId = $tr->fetchColumn();

            if ($transferId) {
                $pdo->prepare("DELETE FROM bank_operations WHERE transfer_id=?")->execute([$transferId]);
            } else {
                $pdo->prepare("DELETE FROM bank_operations WHERE id=? AND sale_id IS NULL AND shipment_id IS NULL")
                    ->execute([$delId]);
            }
            $pdo->commit();
            echo json_encode(['success' => true]);
        } catch (\Throwable $e) {
            $pdo->rollBack();
            apiError('Не удалось удалить операцию', $e);
        }
        break;
}
} catch (PDOException $e) {
    apiError('Ошибка базы данных', $e);
}
