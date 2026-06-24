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
 * Возвращает массив секций-документов.
 */
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

    $prefix = 'СекцияДокумент=';
    $prefixLen = mb_strlen($prefix);

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;

        // Секция счёта (итоги выписки)
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

        // Секции документов
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

/** Определяет направление платежа из секции 1С (Альфа-банк: всегда "Платежное поручение"). */
function sectionDirection(array $s): string {
    // Поступление: ДатаПоступило заполнена, ДатаСписано пустая
    if (!empty($s['ДатаПоступило'])) return 'in';
    // Списание: ДатаСписано заполнена
    if (!empty($s['ДатаСписано'])) return 'out';
    // Fallback по типу документа
    $type = mb_strtolower($s['_doctype'] ?? '');
    if (str_contains($type, 'поступ')) return 'in';
    return 'out';
}

/** Пересчитывает статус строки выписки по сумме привязанных операций. */
function updateLineStatus(PDO $pdo, int $lineId, float $lineAmount): string {
    // ABS — нога перевода между счетами хранится со знаком (списание отрицательное),
    // но для закрытия строки выписки важна величина, а не знак.
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(ABS(bo.amount)), 0)
        FROM bank_statement_matches bsm
        JOIN bank_operations bo ON bo.id = bsm.bank_operation_id
        WHERE bsm.line_id = ?
    ");
    $stmt->execute([$lineId]);
    $matchedSum = (float)$stmt->fetchColumn();

    if ($matchedSum <= 0) {
        $status = 'unmatched';
    } elseif (abs($matchedSum - $lineAmount) <= 0.01) {
        $status = 'matched';
    } else {
        $status = 'partial';
    }
    $pdo->prepare("UPDATE bank_statement_lines SET status=? WHERE id=?")->execute([$status, $lineId]);
    return $status;
}

/** Возвращает id строк выписки, к которым привязана операция (M2M + legacy-колонка). */
function affectedLineIds(PDO $pdo, int $opId): array {
    $ids = [];
    $a = $pdo->prepare("SELECT line_id FROM bank_statement_matches WHERE bank_operation_id=?");
    $a->execute([$opId]);
    foreach ($a->fetchAll(PDO::FETCH_COLUMN) as $id) $ids[(int)$id] = true;
    $b = $pdo->prepare("SELECT id FROM bank_statement_lines WHERE bank_operation_id=?");
    $b->execute([$opId]);
    foreach ($b->fetchAll(PDO::FETCH_COLUMN) as $id) $ids[(int)$id] = true;
    return array_keys($ids);
}

/** Пересчитывает статус набора строк выписки по их текущим привязкам. */
function recalcLines(PDO $pdo, array $lineIds): void {
    if (empty($lineIds)) return;
    $sel = $pdo->prepare("SELECT amount FROM bank_statement_lines WHERE id=?");
    foreach ($lineIds as $lineId) {
        $sel->execute([$lineId]);
        $amount = $sel->fetchColumn();
        if ($amount !== false) updateLineStatus($pdo, (int)$lineId, (float)$amount);
    }
}

/**
 * Вставляет нормализованные строки выписки с дедупом. Источник (файл/API) неважен.
 * $lines: [['direction','amount'(>0),'operation_date','value_date','counterparty','inn','description','doc_number','raw'], ...]
 * Возвращает ['inserted'=>int,'duplicates'=>int].
 */
function importStatementLines(PDO $pdo, int $accountId, array $lines, string $batch): array {
    $ins = $pdo->prepare("
        INSERT INTO bank_statement_lines
          (account_id, direction, amount, operation_date, value_date,
           counterparty, counterparty_inn, description, doc_number, raw_section, import_batch)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)
    ");
    // Дедуп: строка считается дублем по счёту+дате+направлению+сумме+номеру+контрагенту
    $dup = $pdo->prepare("
        SELECT COUNT(*) FROM bank_statement_lines
        WHERE account_id=? AND operation_date=? AND direction=? AND amount=?
          AND doc_number <=> ? AND counterparty <=> ? AND status != 'ignored'
    ");
    $inserted = $duplicates = 0;
    foreach ($lines as $l) {
        $dir    = ($l['direction'] ?? 'out') === 'in' ? 'in' : 'out';
        $date   = $l['operation_date'] ?? null;
        $amount = (float)($l['amount'] ?? 0);
        if (!$date || $amount <= 0) continue;
        $cp     = mb_substr((string)($l['counterparty'] ?? ''), 0, 255);
        $docNum = (string)($l['doc_number'] ?? '');

        $dup->execute([$accountId, $date, $dir, $amount, $docNum, $cp]);
        if ((int)$dup->fetchColumn() > 0) { $duplicates++; continue; }

        $ins->execute([
            $accountId, $dir, $amount, $date, $l['value_date'] ?? null,
            $cp, ($l['inn'] ?? null) ?: null,
            (string)($l['description'] ?? ''), $docNum,
            json_encode($l['raw'] ?? $l, JSON_UNESCAPED_UNICODE),
            $batch,
        ]);
        $inserted++;
    }
    return ['inserted' => $inserted, 'duplicates' => $duplicates];
}

/** Загружает привязанные операции для списка строк выписки. */
function loadLineMatches(PDO $pdo, array $lineIds): array {
    if (empty($lineIds)) return [];
    $in   = implode(',', array_fill(0, count($lineIds), '?'));
    $stmt = $pdo->prepare("
        SELECT bsm.line_id, bsm.id as match_id, bo.id as op_id,
               bo.amount, bo.description, bo.type, bo.status as op_status,
               bo.operation_date, bo.sale_id, bo.shipment_id,
               COALESCE(
                   (SELECT CONCAT('Продажа #',s.id,' — ',COALESCE(c.name,s.buyer_name,'?'))
                    FROM sales s LEFT JOIN counterparties c ON c.id=s.counterparty_id WHERE s.id=bo.sale_id),
                   (SELECT CONCAT('Поставка #',sh.id,' — ',COALESCE(cp.name,'?'))
                    FROM shipments sh LEFT JOIN counterparties cp ON cp.id=sh.counterparty_id WHERE sh.id=bo.shipment_id)
               ) as source_label
        FROM bank_statement_matches bsm
        JOIN bank_operations bo ON bo.id = bsm.bank_operation_id
        WHERE bsm.line_id IN ($in)
        ORDER BY bsm.id
    ");
    $stmt->execute($lineIds);
    $result = [];
    foreach ($stmt->fetchAll() as $row) {
        $result[$row['line_id']][] = $row;
    }
    return $result;
}

/** Возвращает авто-предложения сопоставления для строки выписки. */
function suggestMatches(PDO $pdo, array $line): array {
    $remaining = (float)$line['amount'] - (float)($line['matched_sum'] ?? 0);
    if ($remaining <= 0) return [];
    $accId = (int)($line['account_id'] ?? 0);
    $stmt = $pdo->prepare("
        SELECT bo.*, ba.name as account_name,
               (ABS(ABS(bo.amount) - ?) <= 0.01) as exact_match
        FROM bank_operations bo
        LEFT JOIN bank_accounts ba ON ba.id = bo.account_id
        WHERE (bo.status = 'pending' OR bo.type = 'Перевод')
          AND FLOOR(ABS(bo.amount)) = FLOOR(?)
          AND NOT EXISTS (SELECT 1 FROM bank_statement_matches bsm WHERE bsm.bank_operation_id = bo.id)
          AND ABS(DATEDIFF(bo.operation_date, ?)) <= 14
          AND (bo.type <> 'Перевод' OR ? = 0 OR bo.account_id = ?)
        ORDER BY exact_match DESC, ABS(DATEDIFF(bo.operation_date, ?))
        LIMIT 5
    ");
    $stmt->execute([$remaining, $remaining, $line['operation_date'], $accId, $accId, $line['operation_date']]);
    return $stmt->fetchAll();
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
                // Переводы (±) учитываются в балансе, но не в ↑приход/↓расход счёта
                $acc['balance']       = $acc['initial_balance'] + $b['total_income'] - $b['total_expense'] + ($b['transfer_net'] ?? 0);
            }
            echo json_encode($accounts);
            break;
        }

        // Поиск операций для пикера сопоставления
        if (isset($_GET['search_ops'])) {
            $amount    = floatval($_GET['amount'] ?? 0);
            $query     = trim($_GET['q'] ?? '');
            $excludeOps = array_filter(array_map('intval', explode(',', $_GET['exclude'] ?? '')));

            $where  = [
                "bo.status IN ('pending','confirmed')",
                // Не показываем операции уже привязанные к ДРУГИМ строкам
                "NOT EXISTS (SELECT 1 FROM bank_statement_matches bsm2 WHERE bsm2.bank_operation_id = bo.id)",
            ];
            $params = [];

            // Фильтр по счёту (выписка привязана к конкретному счёту)
            if (!empty($_GET['account_id'])) {
                $where[]  = "bo.account_id = ?";
                $params[] = (int)$_GET['account_id'];
            }

            // Фильтр по направлению: in → приходы (+нога перевода), out → расходы (−нога перевода)
            if (!empty($_GET['direction'])) {
                // Переводы показываем в обоих направлениях — счёт+сумма однозначно
                // определяют ногу, а направление в выписке может быть распарсено иначе.
                if ($_GET['direction'] === 'in') {
                    $where[] = "(bo.type IN ('Продажа','Прочий приход') OR bo.type='Перевод')";
                } elseif ($_GET['direction'] === 'out') {
                    $where[] = "(bo.type IN ('Закупка','Расход','Выплата ЗП') OR bo.type='Перевод')";
                }
            }

            // Исключаем операции уже добавленные к этой строке (переданы через exclude=)
            if (!empty($excludeOps)) {
                $inEx   = implode(',', $excludeOps);
                $where[] = "bo.id NOT IN ($inEx)";
            }

            if ($amount > 0 && $query === '') {
                // Совпадение по рублям без копеек (по модулю — у ноги перевода сумма отрицательна)
                $where[]  = "FLOOR(ABS(bo.amount)) = FLOOR(?)";
                $params[] = $amount;
            }
            if ($query !== '') {
                // Ищем по описанию операции, сумме, названию поставки и названию продажи
                $where[]  = "(bo.description LIKE ?
                              OR CAST(bo.amount AS CHAR) LIKE ?
                              OR EXISTS (SELECT 1 FROM shipments sh WHERE sh.id = bo.shipment_id AND sh.name LIKE ?)
                              OR EXISTS (SELECT 1 FROM sales sa LEFT JOIN counterparties cp ON cp.id = sa.counterparty_id
                                         WHERE sa.id = bo.sale_id AND (cp.name LIKE ? OR sa.buyer_name LIKE ?)))";
                $like = '%' . $query . '%';
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
            }

            $whereStr    = 'WHERE ' . implode(' AND ', $where);
            $orderAmount = $amount > 0 ? "ABS(ABS(bo.amount) - $amount) ASC," : '';

            $stmt = $pdo->prepare("
                SELECT bo.*, ba.name as account_name,
                    CASE WHEN bo.sale_id IS NOT NULL THEN 'sale'
                         WHEN bo.shipment_id IS NOT NULL THEN 'shipment'
                         ELSE 'manual' END as source_type,
                    COALESCE(
                        (SELECT CONCAT('Продажа #',s.id,' — ',COALESCE(c.name,s.buyer_name,'?'))
                         FROM sales s LEFT JOIN counterparties c ON c.id=s.counterparty_id WHERE s.id=bo.sale_id),
                        (SELECT CONCAT('Поставка #',sh2.id,IF(sh2.name IS NOT NULL AND sh2.name != '', CONCAT(' ',sh2.name), ''),' — ',COALESCE(cp.name,'?'))
                         FROM shipments sh2 LEFT JOIN counterparties cp ON cp.id=sh2.counterparty_id WHERE sh2.id=bo.shipment_id)
                    ) as source_label
                FROM bank_operations bo
                LEFT JOIN bank_accounts ba ON ba.id = bo.account_id
                $whereStr
                ORDER BY bo.status='pending' DESC, $orderAmount bo.operation_date DESC
                LIMIT 200
            ");
            $stmt->execute($params);
            echo json_encode($stmt->fetchAll());
            break;
        }

        // Данные для экрана сопоставления
        if (isset($_GET['reconcile'])) {
            // Ожидающие операции
            $pending = $pdo->query("
                SELECT bo.*, ba.name as account_name,
                    CASE WHEN bo.sale_id IS NOT NULL THEN 'sale'
                         WHEN bo.shipment_id IS NOT NULL THEN 'shipment'
                         ELSE 'manual' END as source_type,
                    COALESCE(
                        (SELECT CONCAT('Продажа #',s.id,' — ',COALESCE(c.name,s.buyer_name,'?'))
                         FROM sales s LEFT JOIN counterparties c ON c.id=s.counterparty_id WHERE s.id=bo.sale_id),
                        (SELECT CONCAT('Поставка #',sh.id,' — ',COALESCE(cp.name,'?'))
                         FROM shipments sh LEFT JOIN counterparties cp ON cp.id=sh.counterparty_id WHERE sh.id=bo.shipment_id)
                    ) as source_label
                FROM bank_operations bo
                LEFT JOIN bank_accounts ba ON ba.id = bo.account_id
                WHERE bo.status='pending'
                  AND NOT EXISTS (SELECT 1 FROM bank_statement_matches bsm WHERE bsm.bank_operation_id=bo.id)
                ORDER BY bo.operation_date DESC
            ")->fetchAll();

            // Строки выписки: несопоставленные + частично сопоставленные
            $lines = $pdo->query("
                SELECT bsl.*, ba.name as account_name
                FROM bank_statement_lines bsl
                LEFT JOIN bank_accounts ba ON ba.id = bsl.account_id
                WHERE bsl.status IN ('unmatched','partial')
                ORDER BY bsl.operation_date DESC
            ")->fetchAll();

            // Полностью сопоставленные строки
            $matched = $pdo->query("
                SELECT bsl.*, ba.name as account_name
                FROM bank_statement_lines bsl
                LEFT JOIN bank_accounts ba ON ba.id = bsl.account_id
                WHERE bsl.status = 'matched'
                ORDER BY bsl.operation_date DESC
                LIMIT 2000
            ")->fetchAll();

            // Загружаем привязанные операции для всех строк
            $allLineIds  = array_column(array_merge($lines, $matched), 'id');
            $matchesByLine = loadLineMatches($pdo, $allLineIds);

            // Добавляем matches и matched_sum к каждой строке
            foreach ($lines as &$l) {
                $l['matches']     = $matchesByLine[$l['id']] ?? [];
                $l['matched_sum'] = array_sum(array_map(fn($m) => abs((float)$m['amount']), $l['matches']));
            }
            foreach ($matched as &$l) {
                $l['matches']     = $matchesByLine[$l['id']] ?? [];
                $l['matched_sum'] = array_sum(array_map(fn($m) => abs((float)$m['amount']), $l['matches']));
            }
            unset($l);

            // ── Авто-предложения: один запрос на все pending, матчинг в PHP ────────
            $suggestions = [];
            $linesNeedSugg = array_filter($lines, fn($l) => empty($l['matches']));
            if (!empty($linesNeedSugg)) {
                // pending CRM-операции + подтверждённые переводы между счетами
                $allPending = $pdo->query("
                    SELECT bo.id, bo.amount, bo.operation_date, bo.description, bo.type, bo.status,
                           bo.sale_id, bo.shipment_id, bo.account_id
                    FROM bank_operations bo
                    WHERE (bo.status = 'pending' OR bo.type = 'Перевод')
                      AND NOT EXISTS (SELECT 1 FROM bank_statement_matches bsm WHERE bsm.bank_operation_id = bo.id)
                    ORDER BY bo.operation_date DESC
                    LIMIT 500
                ")->fetchAll();

                foreach ($linesNeedSugg as $line) {
                    $remaining = (float)$line['amount'] - (float)($line['matched_sum'] ?? 0);
                    if ($remaining <= 0) continue;
                    $lineTs = strtotime($line['operation_date']);
                    $candidates = [];
                    foreach ($allPending as $op) {
                        $amt = abs((float)$op['amount']); // нога перевода со знаком
                        if ((int)floor($amt) !== (int)floor($remaining)) continue;
                        $daysDiff = abs(strtotime($op['operation_date']) - $lineTs) / 86400;
                        if ($daysDiff > 14) continue;
                        // Перевод: ноги лежат на разных счетах, поэтому счёт+сумма
                        // однозначно определяют нужную ногу — направление не проверяем
                        // (банк может распарсить его иначе у входящего перевода).
                        if ($op['type'] === 'Перевод') {
                            if (!empty($line['account_id']) && !empty($op['account_id'])
                                && (int)$line['account_id'] !== (int)$op['account_id']) continue;
                        }
                        $op['exact_match'] = abs($amt - $remaining) <= 0.01 ? 1 : 0;
                        $op['days_diff']   = $daysDiff;
                        $candidates[] = $op;
                    }
                    if (!empty($candidates)) {
                        usort($candidates, fn($a, $b) =>
                            $b['exact_match'] <=> $a['exact_match'] ?: $a['days_diff'] <=> $b['days_diff']
                        );
                        $suggestions[$line['id']] = array_slice($candidates, 0, 5);
                    }
                }
            }

            // ── Контроль сумм: одна карточка на банковский счёт ──────────────────
            // Сторона «По выписке» — сумма загруженных строк (игнорированные исключены),
            // сторона «В ЦРМ» — все операции счёта за период, который покрывает выписка.
            $batches = $pdo->query("
                SELECT bsl.account_id, ba.name as account_name,
                       MIN(bsl.operation_date) as start_date,
                       MAX(bsl.operation_date) as end_date,
                       SUM(CASE WHEN bsl.direction='in'  THEN bsl.amount ELSE 0 END) as total_in,
                       SUM(CASE WHEN bsl.direction='out' THEN bsl.amount ELSE 0 END) as total_out
                FROM bank_statement_lines bsl
                LEFT JOIN bank_accounts ba ON ba.id = bsl.account_id
                WHERE bsl.status != 'ignored'
                GROUP BY bsl.account_id, ba.name
                ORDER BY bsl.account_id
            ")->fetchAll();

            // Остатки и номер счёта из метаданных выписок: начальный — из самой
            // ранней выписки счёта, конечный — из самой поздней
            $metaByAcc = [];
            if (tableExists($pdo, 'bank_statement_batches')) {
                foreach ($pdo->query("
                    SELECT account_id, opening_balance, closing_balance, account_number
                    FROM bank_statement_batches
                    WHERE start_date IS NOT NULL
                    ORDER BY start_date ASC
                ")->fetchAll() as $r) {
                    $aid = $r['account_id'];
                    if (!isset($metaByAcc[$aid])) {
                        $metaByAcc[$aid] = [
                            'opening_balance' => $r['opening_balance'],
                            'account_number'  => $r['account_number'],
                        ];
                    }
                    $metaByAcc[$aid]['closing_balance'] = $r['closing_balance'];
                }
            }

            // Операции ЦРМ по счёту — ВСЕ (без обрезки по периоду выписки).
            // Положительная разница = операции, ещё не подтверждённые выпиской;
            // после загрузки свежей выписки и сопоставления разница уходит в ноль.
            $crmStmt = $pdo->prepare("
                SELECT
                    COALESCE(SUM(CASE WHEN type IN ('Продажа','Прочий приход') OR (type='Перевод' AND amount >= 0) THEN ABS(amount) ELSE 0 END), 0) as crm_in,
                    COALESCE(SUM(CASE WHEN type IN ('Закупка','Расход','Выплата ЗП') OR (type='Перевод' AND amount < 0) THEN ABS(amount) ELSE 0 END), 0) as crm_out
                FROM bank_operations
                WHERE account_id = ? AND status IN ('confirmed','pending')
            ");

            // Несопоставленные строки по счетам — из уже загруженных $lines.
            // Для частично закрытых отдаём остаток, а не полную сумму.
            $linesByAcc = [];
            foreach ($lines as $l) {
                $linesByAcc[$l['account_id']][] = [
                    'id'             => $l['id'],
                    'direction'      => $l['direction'],
                    'amount'         => $l['amount'],
                    'remaining'      => max(0, round((float)$l['amount'] - (float)$l['matched_sum'], 2)),
                    'operation_date' => $l['operation_date'],
                    'counterparty'   => $l['counterparty'],
                    'description'    => $l['description'],
                    'status'         => $l['status'],
                ];
            }

            // Операции, не подтверждённые выпиской (без привязки к строкам) — для расшифровки разницы
            $unconfStmt = $pdo->prepare("
                SELECT id, type, amount, description, operation_date, status
                FROM bank_operations bo
                WHERE bo.account_id = ? AND bo.status IN ('confirmed','pending')
                  AND NOT EXISTS (SELECT 1 FROM bank_statement_matches bsm WHERE bsm.bank_operation_id = bo.id)
                ORDER BY bo.operation_date DESC
                LIMIT 50
            ");

            foreach ($batches as &$b) {
                $crmStmt->execute([$b['account_id']]);
                $crmRow = $crmStmt->fetch();
                $b['crm_in']          = (float)($crmRow['crm_in']  ?? 0);
                $b['crm_out']         = (float)($crmRow['crm_out'] ?? 0);
                $b['opening_balance'] = $metaByAcc[$b['account_id']]['opening_balance'] ?? null;
                $b['closing_balance'] = $metaByAcc[$b['account_id']]['closing_balance'] ?? null;
                $b['account_number']  = $metaByAcc[$b['account_id']]['account_number']  ?? null;
                $b['unmatched_lines'] = $linesByAcc[$b['account_id']] ?? [];
                $unconfStmt->execute([$b['account_id']]);
                $b['unconfirmed_ops'] = $unconfStmt->fetchAll();
            }
            unset($b);

            // Проигнорированные строки — чтобы их можно было увидеть и вернуть
            $ignored = $pdo->query("
                SELECT bsl.*, ba.name as account_name
                FROM bank_statement_lines bsl
                LEFT JOIN bank_accounts ba ON ba.id = bsl.account_id
                WHERE bsl.status = 'ignored'
                ORDER BY bsl.operation_date DESC
                LIMIT 200
            ")->fetchAll();

            echo json_encode([
                'pending'     => $pending,
                'lines'       => $lines,
                'matched'     => $matched,
                'ignored'     => $ignored,
                'suggestions' => $suggestions,
                'batches'     => $batches,
            ]);
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

        // Догрузка ленты операций для бесконечного скролла — готовые HTML-строки
        if (isset($_GET['operations_html'])) {
            require_once __DIR__ . '/../pages/_bank_op_row.php';
            $pageSize = 50;
            $offset   = max(0, (int)($_GET['offset'] ?? 0));

            // Model C: показываем и подтверждённые, и ожидающие (pending помечается в строке).
            $where  = ["bo.status IN ('confirmed','pending')"];
            $params = [];
            if (!empty($_GET['account_id'])) { $where[] = 'bo.account_id = ?'; $params[] = (int)$_GET['account_id']; }
            if (($_GET['direction'] ?? '') === 'income') {
                $where[] = "(bo.type IN ('Продажа','Прочий приход') OR (bo.type='Перевод' AND bo.amount >= 0))";
            } elseif (($_GET['direction'] ?? '') === 'expense') {
                $where[] = "(bo.type IN ('Закупка','Расход','Выплата ЗП') OR (bo.type='Перевод' AND bo.amount < 0))";
            }
            // Фильтр периода (тот же, что в index.php): по умолчанию текущий месяц, period=all — всё
            if (($_GET['period'] ?? '') !== 'all') {
                $m = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('n');
                $y = isset($_GET['year'])  ? (int)$_GET['year']  : (int)date('Y');
                $where[]  = "MONTH(bo.operation_date) = ? AND YEAR(bo.operation_date) = ?";
                $params[] = $m;
                $params[] = $y;
            }
            $whereStr = 'WHERE ' . implode(' AND ', $where);

            // Общее количество для пагинации
            $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM bank_operations bo {$whereStr}");
            $cntStmt->execute($params);
            $total = (int)$cntStmt->fetchColumn();

            $stmt = $pdo->prepare("
                SELECT bo.*, ba.name as account_name,
                    EXISTS (SELECT 1 FROM bank_statement_matches bsm WHERE bsm.bank_operation_id = bo.id) as has_statement
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

        // Лента операций — подтверждённые + ожидающие (Model C)
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

        // ── Загрузка файла выписки (multipart) ───────────────────────────────
        if (isset($_FILES['statement'])) {
            $accountId = (int)($_POST['account_id'] ?? 0);
            if (!$accountId) apiError('Не выбран счёт', null, 400);

            $raw      = file_get_contents($_FILES['statement']['tmp_name']);
            $parsed   = parse1cStatement($raw);
            $sections = $parsed['sections'];
            $acctInfo = $parsed['account'];
            if (empty($sections)) apiError('Не удалось распознать файл выписки', null, 422);

            $batch = date('Ymd-His') . '-' . substr(md5($raw), 0, 6);

            // Нормализуем секции 1С в общий формат строк
            $lines = [];
            foreach ($sections as $s) {
                $dir    = sectionDirection($s);
                $date   = parseDateRU($s['Дата'] ?? '');
                $amount = (float) str_replace(',', '.', $s['Сумма'] ?? '0');
                if (!$date || $amount <= 0) continue;
                $lines[] = [
                    'direction'      => $dir,
                    'amount'         => $amount,
                    'operation_date' => $date,
                    'value_date'     => parseDateRU($s['ДатаПоступления'] ?? $s['ДатаСписания'] ?? '') ?: null,
                    'counterparty'   => $dir === 'in' ? ($s['Плательщик'] ?? '') : ($s['Получатель'] ?? ''),
                    'inn'            => $dir === 'in' ? ($s['ИНН плательщика'] ?? '') : ($s['ИНН получателя'] ?? ''),
                    'description'    => $s['НазначениеПлатежа'] ?? '',
                    'doc_number'     => $s['Номер'] ?? '',
                    'raw'            => $s,
                ];
            }
            $res        = importStatementLines($pdo, $accountId, $lines, $batch);
            $inserted   = $res['inserted'];
            $duplicates = $res['duplicates'];
            $skipped    = count($sections) - count($lines);

            // Сохраняем итоги батча (если есть секция счёта)
            $batchMeta = null;
            if ($acctInfo && $inserted > 0) {
                $p = fn($k) => floatval(str_replace(',', '.', $acctInfo[$k] ?? '0'));
                $batchMeta = [
                    'start_date'      => parseDateRU($acctInfo['ДатаНачала'] ?? ''),
                    'end_date'        => parseDateRU($acctInfo['ДатаКонца'] ?? ''),
                    'opening_balance' => $p('НачальныйОстаток'),
                    'closing_balance' => $p('КонечныйОстаток'),
                    'total_in'        => $p('ВсегоПоступило'),   // Альфа-банк
                    'total_out'       => $p('ВсегоСписано'),      // Альфа-банк
                    'account_number'  => $acctInfo['РасчСчет'] ?? $acctInfo['РасчетныйСчет'] ?? null,
                ];
                if (tableExists($pdo, 'bank_statement_batches')) {
                    $pdo->prepare("
                        INSERT INTO bank_statement_batches
                          (account_id, import_batch, start_date, end_date,
                           opening_balance, closing_balance, total_in, total_out, account_number)
                        VALUES (?,?,?,?,?,?,?,?,?)
                    ")->execute([
                        $accountId, $batch,
                        $batchMeta['start_date'], $batchMeta['end_date'],
                        $batchMeta['opening_balance'], $batchMeta['closing_balance'],
                        $batchMeta['total_in'], $batchMeta['total_out'],
                        $batchMeta['account_number'],
                    ]);
                }
            }

            echo json_encode(['success' => true, 'imported' => $inserted, 'skipped' => $skipped, 'duplicates' => $duplicates, 'batch' => $batch, 'statement_totals' => $batchMeta]);
            break;
        }

        $data = json_decode(file_get_contents('php://input'), true) ?? [];

        // ── Действия сопоставления ────────────────────────────────────────────
        if (isset($data['action'])) {
            switch ($data['action']) {

                // Добавить операцию к строке выписки (поддержка нескольких)
                case 'match':
                    $lineId = (int)($data['line_id'] ?? 0);
                    $opId   = (int)($data['op_id']   ?? 0);
                    if (!$lineId || !$opId) apiError('Неверные параметры', null, 400);

                    $pdo->beginTransaction();
                    try {
                        $ls = $pdo->prepare("SELECT * FROM bank_statement_lines WHERE id=?");
                        $ls->execute([$lineId]);
                        $lineRow = $ls->fetch();
                        if (!$lineRow) throw new RuntimeException('Строка выписки не найдена');

                        // Добавляем связь (IGNORE если уже есть)
                        $pdo->prepare("INSERT IGNORE INTO bank_statement_matches (line_id, bank_operation_id) VALUES (?,?)")
                            ->execute([$lineId, $opId]);

                        // Подтверждаем операцию с датой из выписки (если была pending)
                        $pdo->prepare("
                            UPDATE bank_operations
                            SET status='confirmed',
                                operation_date = CASE WHEN status='pending' THEN ? ELSE operation_date END
                            WHERE id=?
                        ")->execute([$lineRow['operation_date'], $opId]);

                        // Пересчитываем статус строки
                        $newStatus = updateLineStatus($pdo, $lineId, (float)$lineRow['amount']);
                        $pdo->commit();
                        echo json_encode(['success' => true, 'line_status' => $newStatus]);
                    } catch (\Throwable $e) {
                        $pdo->rollBack();
                        apiError('Ошибка сопоставления', $e);
                    }
                    break;

                // Убрать конкретную операцию из строки
                case 'unmatch_op':
                    $lineId = (int)($data['line_id'] ?? 0);
                    $opId   = (int)($data['op_id']   ?? 0);
                    $pdo->beginTransaction();
                    try {
                        $ls = $pdo->prepare("SELECT amount FROM bank_statement_lines WHERE id=?");
                        $ls->execute([$lineId]);
                        $lineAmount = (float)$ls->fetchColumn();

                        $pdo->prepare("DELETE FROM bank_statement_matches WHERE line_id=? AND bank_operation_id=?")
                            ->execute([$lineId, $opId]);

                        // Если эта операция не привязана к другим строкам и пришла из продажи/поставки — вернуть в pending
                        $others = $pdo->prepare("SELECT COUNT(*) FROM bank_statement_matches WHERE bank_operation_id=?");
                        $others->execute([$opId]);
                        if ((int)$others->fetchColumn() === 0) {
                            $pdo->prepare("UPDATE bank_operations SET status='pending' WHERE id=? AND (sale_id IS NOT NULL OR shipment_id IS NOT NULL)")
                                ->execute([$opId]);
                        }

                        updateLineStatus($pdo, $lineId, $lineAmount);
                        $pdo->commit();
                        echo json_encode(['success' => true]);
                    } catch (\Throwable $e) {
                        $pdo->rollBack();
                        apiError('Ошибка', $e);
                    }
                    break;

                // Отвязать ВСЕ операции от строки (полный сброс)
                case 'unmatch':
                    $lineId = (int)($data['line_id'] ?? 0);
                    $pdo->beginTransaction();
                    try {
                        // Получаем все привязанные операции
                        $ops = $pdo->prepare("SELECT bank_operation_id FROM bank_statement_matches WHERE line_id=?");
                        $ops->execute([$lineId]);
                        foreach ($ops->fetchAll() as $row) {
                            $others = $pdo->prepare("SELECT COUNT(*) FROM bank_statement_matches WHERE bank_operation_id=? AND line_id != ?");
                            $others->execute([$row['bank_operation_id'], $lineId]);
                            if ((int)$others->fetchColumn() === 0) {
                                $pdo->prepare("UPDATE bank_operations SET status='pending' WHERE id=? AND (sale_id IS NOT NULL OR shipment_id IS NOT NULL)")
                                    ->execute([$row['bank_operation_id']]);
                            }
                        }
                        $pdo->prepare("DELETE FROM bank_statement_matches WHERE line_id=?")->execute([$lineId]);
                        $pdo->prepare("UPDATE bank_statement_lines SET status='unmatched' WHERE id=?")->execute([$lineId]);
                        $pdo->commit();
                        echo json_encode(['success' => true]);
                    } catch (\Throwable $e) {
                        $pdo->rollBack();
                        apiError('Ошибка', $e);
                    }
                    break;

                // Игнорировать строку выписки
                case 'ignore_line':
                    $pdo->prepare("UPDATE bank_statement_lines SET status='ignored' WHERE id=?")
                        ->execute([(int)($data['line_id'] ?? 0)]);
                    echo json_encode(['success' => true]);
                    break;

                // Вернуть строку из игнора
                case 'unignore_line':
                    $pdo->prepare("UPDATE bank_statement_lines SET status='unmatched' WHERE id=?")
                        ->execute([(int)($data['line_id'] ?? 0)]);
                    echo json_encode(['success' => true]);
                    break;

                // Подтвердить операцию без строки выписки (вручную)
                case 'confirm_op':
                    $pdo->prepare("UPDATE bank_operations SET status='confirmed' WHERE id=?")
                        ->execute([(int)($data['op_id'] ?? 0)]);
                    echo json_encode(['success' => true]);
                    break;

                // Вернуть подтверждённую операцию в ожидание
                case 'unconfirm_op':
                    $opId = (int)($data['op_id'] ?? 0);
                    $pdo->beginTransaction();
                    try {
                        // Собираем строки, к которым привязана операция (M2M + legacy-колонка)
                        $affected = affectedLineIds($pdo, $opId);
                        // Снимаем сопоставление в обоих механизмах
                        $pdo->prepare("DELETE FROM bank_statement_matches WHERE bank_operation_id=?")->execute([$opId]);
                        $pdo->prepare("UPDATE bank_statement_lines SET bank_operation_id=NULL WHERE bank_operation_id=?")->execute([$opId]);
                        $pdo->prepare("UPDATE bank_operations SET status='pending' WHERE id=? AND (sale_id IS NOT NULL OR shipment_id IS NOT NULL)")
                            ->execute([$opId]);
                        recalcLines($pdo, $affected);
                        $pdo->commit();
                        echo json_encode(['success' => true]);
                    } catch (\Throwable $e) {
                        $pdo->rollBack();
                        apiError('Ошибка', $e);
                    }
                    break;

                // Создать подтверждённую операцию из строки выписки (для транзакций без аналога)
                case 'create_from_line':
                    $lineId    = (int)($data['line_id'] ?? 0);
                    $accountId = (int)($data['account_id'] ?? 0);

                    $row = $pdo->prepare("SELECT * FROM bank_statement_lines WHERE id=?");
                    $row->execute([$lineId]);
                    $lineRow = $row->fetch();
                    if (!$lineRow || !$accountId) apiError('Неверные параметры', null, 400);

                    $type = $data['type'] ?? ($lineRow['direction'] === 'in' ? 'Прочий приход' : 'Расход');
                    $desc = $data['description'] ?? ($lineRow['counterparty'] ? $lineRow['counterparty'] . ' — ' . mb_substr($lineRow['description'] ?? '', 0, 80) : mb_substr($lineRow['description'] ?? '', 0, 100));

                    $pdo->beginTransaction();
                    try {
                        $pdo->prepare("
                            INSERT INTO bank_operations (account_id, type, amount, description, operation_date, status)
                            VALUES (?, ?, ?, ?, ?, 'confirmed')
                        ")->execute([$accountId, $type, $lineRow['amount'], $desc, $lineRow['operation_date']]);
                        $newOpId = $pdo->lastInsertId();

                        // Привязываем через M2M (единый механизм) и пересчитываем статус строки
                        $pdo->prepare("INSERT IGNORE INTO bank_statement_matches (line_id, bank_operation_id) VALUES (?,?)")
                            ->execute([$lineId, $newOpId]);
                        updateLineStatus($pdo, $lineId, (float)$lineRow['amount']);
                        $pdo->commit();
                        echo json_encode(['success' => true, 'op_id' => $newOpId]);
                    } catch (\Throwable $e) {
                        $pdo->rollBack();
                        apiError('Ошибка создания операции', $e);
                    }
                    break;

                // Принять сумму из выписки как эталонную (±копейки)
                case 'accept_statement_amount':
                    $lineId = (int)($data['line_id'] ?? 0);
                    $opId   = (int)($data['op_id']   ?? 0);
                    $pdo->beginTransaction();
                    try {
                        $ls = $pdo->prepare("SELECT * FROM bank_statement_lines WHERE id=?");
                        $ls->execute([$lineId]);
                        $lineRow = $ls->fetch();
                        $os = $pdo->prepare("SELECT * FROM bank_operations WHERE id=?");
                        $os->execute([$opId]);
                        $opRow = $os->fetch();
                        if (!$lineRow || !$opRow) throw new RuntimeException('Не найдено');

                        // Перевод между счетами не трогаем по сумме — у него фиксированная
                        // парная сумма со знаком; только привязываем.
                        $trueAmount = (float)$opRow['amount'];
                        if ($opRow['type'] !== 'Перевод') {
                            // Истинная сумма = сумма строки минус то, что уже привязано другими операциями
                            $otherSt = $pdo->prepare("
                                SELECT COALESCE(SUM(ABS(bo.amount)), 0)
                                FROM bank_statement_matches bsm
                                JOIN bank_operations bo ON bo.id = bsm.bank_operation_id
                                WHERE bsm.line_id = ? AND bsm.bank_operation_id != ?
                            ");
                            $otherSt->execute([$lineId, $opId]);
                            $trueAmount = round((float)$lineRow['amount'] - (float)$otherSt->fetchColumn(), 2);

                            // Обновляем операцию
                            $pdo->prepare("UPDATE bank_operations SET amount=? WHERE id=?")
                                ->execute([$trueAmount, $opId]);

                            // Синхронизируем источник
                            if ($opRow['sale_id']) {
                                $pdo->prepare("UPDATE sales SET sale_price=? WHERE id=?")
                                    ->execute([$trueAmount, $opRow['sale_id']]);
                            }
                            if ($opRow['shipment_id']) {
                                // carrier_cost = эталонная сумма − стоимость позиций (не уходим в минус)
                                $items = $pdo->prepare("SELECT COALESCE(SUM(quantity*purchase_price),0) FROM shipment_items WHERE shipment_id=?");
                                $items->execute([$opRow['shipment_id']]);
                                $pdo->prepare("UPDATE shipments SET carrier_cost=? WHERE id=?")
                                    ->execute([max(0, $trueAmount - (float)$items->fetchColumn()), $opRow['shipment_id']]);
                            }
                        }

                        // Привязываем и подтверждаем
                        $pdo->prepare("INSERT IGNORE INTO bank_statement_matches (line_id, bank_operation_id) VALUES (?,?)")
                            ->execute([$lineId, $opId]);
                        $pdo->prepare("UPDATE bank_operations SET status='confirmed', operation_date=? WHERE id=?")
                            ->execute([$lineRow['operation_date'], $opId]);

                        $newStatus = updateLineStatus($pdo, $lineId, (float)$lineRow['amount']);
                        $pdo->commit();
                        echo json_encode(['success' => true, 'line_status' => $newStatus, 'new_amount' => $trueAmount]);
                    } catch (\Throwable $e) {
                        $pdo->rollBack();
                        apiError('Ошибка', $e);
                    }
                    break;

                // Предложения сопоставления для конкретной строки
                case 'suggest':
                    $lineId = (int)($data['line_id'] ?? 0);
                    $row = $pdo->prepare("SELECT * FROM bank_statement_lines WHERE id=?");
                    $row->execute([$lineId]);
                    $lineRow = $row->fetch();
                    if (!$lineRow) { echo json_encode([]); break; }
                    echo json_encode(suggestMatches($pdo, $lineRow));
                    break;

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
                        // Списание со счёта-источника хранится отрицательной суммой,
                        // зачисление — положительной. Так баланс считается одним SUM.
                        $ins = $pdo->prepare("
                            INSERT INTO bank_operations (account_id, type, amount, description, operation_date, status)
                            VALUES (?, 'Перевод', ?, ?, ?, 'confirmed')
                        ");
                        $ins->execute([$fromId, -$amount, $desc, $date]);
                        $outId = (int)$pdo->lastInsertId();
                        $ins->execute([$toId, $amount, $desc, $date]);
                        $inId = (int)$pdo->lastInsertId();
                        // Связываем пару общим transfer_id (= id операции списания)
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

        // ── Стандартные POST-операции ─────────────────────────────────────────

        if (isset($data['update_balance'])) {
            $pdo->prepare("UPDATE bank_accounts SET initial_balance = ? WHERE id = ?")
                ->execute([$data['initial_balance'], $data['account_id']]);
            echo json_encode(['success' => true]);
            break;
        }

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

            // Сумма операции изменилась — пересчитываем статус привязанных строк выписки
            recalcLines($pdo, affectedLineIds($pdo, $opId));
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

            $opIds = [$delId];
            if ($transferId) {
                $pair = $pdo->prepare("SELECT id FROM bank_operations WHERE transfer_id=?");
                $pair->execute([$transferId]);
                $opIds = array_map('intval', $pair->fetchAll(PDO::FETCH_COLUMN));
            }

            // Собираем строки выписки, которые останутся без операции
            $affected = [];
            foreach ($opIds as $oid) {
                foreach (affectedLineIds($pdo, $oid) as $lid) $affected[$lid] = true;
            }
            $affected = array_keys($affected);

            // Удаляем операции и связи в обоих механизмах
            $in = implode(',', array_fill(0, count($opIds), '?'));
            if ($transferId) {
                $pdo->prepare("DELETE FROM bank_operations WHERE transfer_id=?")->execute([$transferId]);
            } else {
                $pdo->prepare("DELETE FROM bank_operations WHERE id=? AND sale_id IS NULL AND shipment_id IS NULL")
                    ->execute([$delId]);
            }
            $pdo->prepare("DELETE FROM bank_statement_matches WHERE bank_operation_id IN ($in)")->execute($opIds);
            $pdo->prepare("UPDATE bank_statement_lines SET bank_operation_id=NULL WHERE bank_operation_id IN ($in)")->execute($opIds);

            // Пересчитываем статус затронутых строк (вернутся в unmatched/partial)
            recalcLines($pdo, $affected);
            $pdo->commit();

            $resp = ['success' => true];
            if (!empty($affected)) {
                $resp['unlinked_lines'] = count($affected);
                $resp['warning'] = 'Операция была привязана к выписке (' . count($affected) . ' стр.) — строки снова без операции, требуется сопоставление';
            }
            echo json_encode($resp);
        } catch (\Throwable $e) {
            $pdo->rollBack();
            apiError('Не удалось удалить операцию', $e);
        }
        break;
}
} catch (PDOException $e) {
    apiError('Ошибка базы данных', $e);
}
