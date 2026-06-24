<?php
/**
 * Провайдеры выписок по API: Точка-банк (1С:DirectBank) и Ozon (Seller API).
 *
 * Нормализованный формат строки выписки (совместим с importStatementLines()):
 *   ['direction'=>'in'|'out', 'amount'=>float(>0), 'operation_date'=>'Y-m-d',
 *    'value_date'=>?string, 'counterparty'=>string, 'inn'=>?string,
 *    'description'=>string, 'doc_number'=>string, 'raw'=>array]
 *
 * Внешние HTTP-вызовы идут с сервера. HTTP-коды и ошибки cURL пишутся в php-error.log.
 */

define('TOCHKA_DIRECT', 'https://api.tochka.com/direct1c');  // из XML-настроек
define('TOCHKA_FORMAT', '2.3.4');                              // formatVersion из настроек
define('TOCHKA_BIC',    '044525104');                          // BIC Точки из настроек
define('OZON_BASE',     'https://api-seller.ozon.ru');

// ─── HTTP-хелперы ──────────────────────────────────────────────────────────

/**
 * Универсальный cURL-запрос.
 * Возвращает ['code'=>int, 'body'=>string, 'error'=>?string].
 */
function httpRaw(string $method, string $url, array $headers = [], string $body = ''): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    if ($body !== '') curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $raw  = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch) ?: null;
    curl_close($ch);
    error_log("[byB API] $method $url -> HTTP $code" . ($err ? " curl_err=$err" : ''));
    return ['code' => $code, 'body' => $raw, 'error' => $err];
}

function httpJson(string $method, string $url, array $headers = [], $body = null): array {
    $bodyStr = ($body !== null) ? (is_string($body) ? $body : json_encode($body, JSON_UNESCAPED_UNICODE)) : '';
    $r = httpRaw($method, $url, $headers, $bodyStr);
    $json = null;
    if ($r['body'] !== '') {
        $d = json_decode($r['body'], true);
        if (json_last_error() === JSON_ERROR_NONE) $json = $d;
    }
    return ['code' => $r['code'], 'json' => $json, 'raw' => $r['body'], 'error' => $r['error']];
}

// ─────────────────────────────  ТОЧКА  ─────────────────────────────────────
// Протокол: 1С:DirectBank v2.3.4
// Документация: https://github.com/1C-Company/DirectBank
//
// Поток (двухшаговый):
//   1. Logon  → SID
//   2. SendPack (StatementRequest в Packet) → сохраняем время отправки
//   3. GetPackList с fromDateTime → список новых GUID
//   4. GetPack(GUID) → Packet со Statement → парсим операции

/** Стандартные заголовки для всех запросов к Точке. */
function tochkaHeaders(string $sid = '', string $customerID = ''): array {
    $h = [
        'Content-Type: application/xml; charset=utf-8',
        'APIVersion: ' . TOCHKA_FORMAT,
        'CustomerID: ' . ($customerID ?: '0'),
    ];
    if ($sid !== '') $h[] = 'SID: ' . $sid;
    return $h;
}

/**
 * Логин → SessionID.
 * Поддерживает однофакторный (200 OK + SID) и двухфакторный (200 OK, статус Unauthorized + OTP).
 * Возвращает ['sid'=>string] / ['need_otp'=>true,'tmp_sid'=>string] / ['error'=>string].
 */
function tochkaLogon(string $serverUrl, string $customerID, string $login, string $password): array {
    $auth    = base64_encode($login . ':' . $password);
    $headers = array_merge(
        tochkaHeaders('', $customerID),
        ['Authorization: Basic ' . $auth, 'AvailableAPIVersion: ' . TOCHKA_FORMAT]
    );

    $r = httpRaw('POST', rtrim($serverUrl, '/') . '/Logon', $headers);
    if ($r['code'] >= 500) {
        return ['error' => 'Logon HTTP ' . $r['code'] . ': ' . mb_substr($r['body'], 0, 300)];
    }

    $xml = @simplexml_load_string($r['body']);
    if (!$xml) {
        return ['error' => 'Logon: не удалось распарсить ответ (HTTP ' . $r['code'] . '). Тело: ' . mb_substr($r['body'], 0, 300)];
    }

    // Ищем SID независимо от namespace
    $sid = '';
    foreach ([$xml] as $x) {
        $sid = (string)($x->SID ?? $x->SessionID ?? '');
        if ($sid !== '') break;
        foreach ($x->getNamespaces(true) as $uri) {
            $c = $x->children($uri);
            $sid = (string)($c->SID ?? $c->SessionID ?? '');
            if ($sid !== '') break 2;
        }
    }

    if ($sid === '') {
        return ['error' => 'Logon: SID не найден. HTTP=' . $r['code'] . ' Тело: ' . mb_substr($r['body'], 0, 400)];
    }

    // Если банк вернул "неавторизованный SID" (двухфакторный) — нужен OTP
    // Признак: HTTP 200 но статус Unauthorized в XML, или HTTP 401 с SID
    $logonStatus = (string)($xml->LogonStatus ?? $xml->Status ?? '');
    if ($r['code'] === 401 || stripos($logonStatus, 'Unauthorized') !== false || stripos($logonStatus, '401') !== false) {
        return ['need_otp' => true, 'tmp_sid' => $sid];
    }

    return ['sid' => $sid];
}

/**
 * LogonOTP — подтвердить сессию одноразовым кодом (если банк требует двухфакторку).
 * Возвращает ['sid'=>string] или ['error'=>string].
 */
function tochkaLogonOTP(string $serverUrl, string $customerID, string $tmpSid, string $otp): array {
    $headers = array_merge(tochkaHeaders($tmpSid, $customerID), ['OTP: ' . $otp]);
    $r = httpRaw('POST', rtrim($serverUrl, '/') . '/LogonOTP', $headers);
    $xml = @simplexml_load_string($r['body']);
    if (!$xml || $r['code'] >= 400) {
        return ['error' => 'LogonOTP HTTP ' . $r['code'] . ': ' . mb_substr($r['body'], 0, 300)];
    }
    $sid = '';
    foreach ([$xml] as $x) {
        $sid = (string)($x->SID ?? $x->SessionID ?? '');
        if ($sid !== '') break;
        foreach ($x->getNamespaces(true) as $uri) {
            $c = $x->children($uri);
            $sid = (string)($c->SID ?? $c->SessionID ?? '');
            if ($sid !== '') break 2;
        }
    }
    if ($sid === '') return ['error' => 'LogonOTP: SID не найден. Тело: ' . mb_substr($r['body'], 0, 300)];
    return ['sid' => $sid];
}

/**
 * Шаг 2: Отправить запрос выписки через SendPack.
 * Возвращает ['sent_at'=>'DD.MM.YYYY HH:MM:SS'] или ['error'=>string].
 */
function tochkaSendStatementRequest(
    string $serverUrl, string $customerID, string $sid,
    string $accNo, string $dateFrom, string $dateTo
): array {
    $packetId    = strtoupper(sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0,0xffff),mt_rand(0,0xffff),mt_rand(0,0xffff),
        mt_rand(0,0x0fff)|0x4000, mt_rand(0,0x3fff)|0x8000,
        mt_rand(0,0xffff),mt_rand(0,0xffff),mt_rand(0,0xffff)));
    $docId       = strtolower(sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0,0xffff),mt_rand(0,0xffff),mt_rand(0,0xffff),
        mt_rand(0,0x0fff)|0x4000, mt_rand(0,0x3fff)|0x8000,
        mt_rand(0,0xffff),mt_rand(0,0xffff),mt_rand(0,0xffff)));
    $now         = date('Y-m-d\TH:i:s');

    // StatementRequest XML (docKind="05" — запрос выписки в DirectBank)
    $statReqXml = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<StatementRequest xmlns="http://directbank.1c.ru/XMLSchema"'
        . ' id="' . $docId . '"'
        . ' formatVersion="' . TOCHKA_FORMAT . '"'
        . ' creationDate="' . $now . '"'
        . ' userAgent="byBuka/1.0">'
        . '<Sender id="' . htmlspecialchars($customerID) . '"/>'
        . '<Recipient bic="' . TOCHKA_BIC . '"/>'
        . '<Data>'
        . '<StatementType>0</StatementType>'
        . '<DateFrom>' . $dateFrom . 'T00:00:00</DateFrom>'
        . '<DateTo>'   . $dateTo   . 'T23:59:59</DateTo>'
        . '<Account>' . htmlspecialchars($accNo) . '</Account>'
        . '<Bank><BIC>' . TOCHKA_BIC . '</BIC></Bank>'
        . '</Data>'
        . '</StatementRequest>';

    // Оборачиваем в транспортный Packet
    $packetXml = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<Packet xmlns="http://directbank.1c.ru/XMLSchema"'
        . ' id="' . $packetId . '"'
        . ' formatVersion="' . TOCHKA_FORMAT . '"'
        . ' creationDate="' . $now . '"'
        . ' userAgent="byBuka/1.0"'
        . ' sender="' . htmlspecialchars($customerID) . '"'
        . ' receiver="' . TOCHKA_BIC . '">'
        . '<Document id="' . $docId . '" dockind="05" formatVersion="' . TOCHKA_FORMAT . '" encoded="false">'
        . htmlspecialchars($statReqXml, ENT_XML1)
        . '</Document>'
        . '</Packet>';

    $sentAt = date('d.m.Y H:i:s');
    $r = httpRaw('POST', rtrim($serverUrl, '/') . '/SendPack', tochkaHeaders($sid, $customerID), $packetXml);
    if ($r['code'] !== 200) {
        return ['error' => 'SendPack HTTP ' . $r['code'] . ': ' . mb_substr($r['body'], 0, 300)];
    }
    return ['sent_at' => $sentAt];
}

/**
 * Шаг 3+4: Получить список новых пакетов и забрать Statement.
 * $fromDateTime — 'DD.MM.YYYY HH:MM:SS' (время отправки запроса).
 * Возвращает ['lines'=>[...]] или ['error'=>string, 'not_ready'=>bool].
 */
function tochkaCollectStatements(string $serverUrl, string $customerID, string $sid, string $fromDateTime): array {
    // GetPackList
    $dt  = urlencode($fromDateTime);
    $r   = httpRaw('GET', rtrim($serverUrl, '/') . '/GetPackList?date=' . $dt, tochkaHeaders($sid, $customerID));
    if ($r['code'] !== 200) {
        return ['error' => 'GetPackList HTTP ' . $r['code'] . ': ' . mb_substr($r['body'], 0, 300)];
    }
    $xml = @simplexml_load_string($r['body']);
    if (!$xml) return ['error' => 'GetPackList: не удалось распарсить XML'];

    // Собираем GUID пакетов
    $guids = [];
    // Ищем <PackList><Pack id="..."/></PackList> или просто <id>
    foreach ($xml->xpath('//*[local-name()="Pack"]') as $pack) {
        $id = (string)($pack['id'] ?? $pack->id ?? '');
        if ($id !== '') $guids[] = $id;
    }
    // Альтернативный путь — <id> прямо в списке
    foreach ($xml->xpath('//*[local-name()="PacketID"]') as $p) {
        $id = (string)$p;
        if ($id !== '') $guids[] = $id;
    }
    $guids = array_unique($guids);

    if (empty($guids)) {
        return ['error' => 'Выписка ещё не готова — попробуйте через несколько секунд', 'not_ready' => true];
    }

    $lines = [];
    foreach ($guids as $guid) {
        $r2 = httpRaw('GET', rtrim($serverUrl, '/') . '/GetPack?id=' . urlencode($guid), tochkaHeaders($sid, $customerID));
        if ($r2['code'] !== 200) continue;
        $xml2 = @simplexml_load_string($r2['body']);
        if (!$xml2) continue;

        // Ищем Document с dockind="10" (Statement)
        foreach ($xml2->xpath('//*[local-name()="Document"]') as $doc) {
            $kind = (string)($doc['dockind'] ?? '');
            if ($kind !== '10') continue;
            $encoded = ((string)($doc['encoded'] ?? 'false')) === 'true';
            $content = (string)$doc;
            if ($encoded) $content = base64_decode($content);
            $stmXml = @simplexml_load_string(html_entity_decode($content));
            if (!$stmXml) continue;
            $lines = array_merge($lines, tochkaMapStatement($stmXml));
        }
    }

    return ['lines' => $lines];
}

/**
 * Полный цикл запроса выписки (Logon → Send → Collect).
 * Используется для шага 2 (tochka_request) и опционально шага 3 (tochka_collect).
 */
function tochkaGetStatementFull(array $acc, string $dateFrom, string $dateTo): array {
    $serverUrl  = trim((string)($acc['api_server_url'] ?? TOCHKA_DIRECT));
    $customerID = trim((string)($acc['api_customer_code'] ?? ''));
    $accNo      = trim((string)($acc['api_account_id'] ?? ''));
    $login      = trim((string)($acc['api_client_id'] ?? ''));   // логин из 1С (напр. 2613156)
    $password   = trim((string)($acc['api_token'] ?? ''));       // пароль из 1С
    if ($customerID === '' || $accNo === '' || $login === '' || $password === '') {
        return ['error' => 'Не заданы CustomerID, номер счёта, логин или пароль Точки'];
    }
    $logon = tochkaLogon($serverUrl, $customerID, $login, $password);
    if (isset($logon['error'])) return $logon;
    return ['sid' => $logon['sid'], 'server_url' => $serverUrl, 'customer_id' => $customerID];
}

/**
 * Запросить выписку (шаг 1 из 2).
 * Возвращает ['sent_at'=>string] для сохранения в api_statement_id или ['error'=>string].
 */
function tochkaRequestStatement(array $acc, string $dateFrom, string $dateTo): array {
    $auth = tochkaGetStatementFull($acc, $dateFrom, $dateTo);
    if (isset($auth['error'])) return $auth;

    $accNo = trim((string)($acc['api_account_id'] ?? ''));
    $r = tochkaSendStatementRequest($auth['server_url'], $auth['customer_id'], $auth['sid'], $accNo, $dateFrom, $dateTo);
    if (isset($r['error'])) return $r;
    // sent_at используется как "from" для GetPackList
    return ['statement_id' => $r['sent_at'], 'status' => 'Processing'];
}

/**
 * Забрать готовую выписку (шаг 2 из 2).
 * api_statement_id = время отправки запроса ('DD.MM.YYYY HH:MM:SS').
 */
function tochkaGetStatement(array $acc): array {
    $sentAt = trim((string)($acc['api_statement_id'] ?? ''));
    if ($sentAt === '') return ['error' => 'Нет активного запроса (api_statement_id пусто)'];

    $auth = tochkaGetStatementFull($acc, '', '');
    if (isset($auth['error'])) return $auth;

    $r = tochkaCollectStatements($auth['server_url'], $auth['customer_id'], $auth['sid'], $sentAt);
    if (isset($r['error'])) return $r;
    return ['status' => 'Created', 'lines' => $r['lines']];
}

/**
 * Чистый парсинг Statement XML в нормализованные строки.
 * Поддерживает форматы: 1C-Bank_Statement.xsd и camt.053 (ISO 20022).
 */
function tochkaMapStatement(\SimpleXMLElement $xml): array {
    $lines = [];
    $ns = $xml->getNamespaces(true);

    // Ищем операции в 1C-Bank_Statement.xsd: //Operations/Operation
    foreach ($xml->xpath('//*[local-name()="Operation"]') as $op) {
        $amount = abs((float)str_replace(',', '.', (string)($op->Summa ?? $op->Amount ?? $op->Amt ?? 0)));
        if ($amount == 0) continue;
        $indStr = strtolower((string)($op->DC ?? $op->CreditDebitIndicator ?? $op->CdtDbtInd ?? ''));
        $dir    = (str_contains($indStr, 'кред') || str_contains($indStr, 'cred') || $indStr === 'к') ? 'in' : 'out';
        $date   = substr((string)($op->DateDoc ?? $op->TransactionDate ?? $op->BookgDt ?? ''), 0, 10);
        if (!$date) continue;

        $cp     = (string)($op->CorrespondentName ?? $op->Counterparty ?? $op->CorrespName ?? '');
        $inn    = (string)($op->CorrespondentINN ?? $op->INN ?? '');
        $desc   = (string)($op->Purpose ?? $op->PaymentPurpose ?? $op->Desc ?? '');
        $docNum = (string)($op->DocNumber ?? $op->DocumentNumber ?? $op->NumDoc ?? '');

        $lines[] = [
            'direction'      => $dir,
            'amount'         => $amount,
            'operation_date' => $date,
            'value_date'     => null,
            'counterparty'   => mb_substr($cp, 0, 255),
            'inn'            => $inn ?: null,
            'description'    => $desc,
            'doc_number'     => $docNum,
            'raw'            => json_decode(json_encode($op), true),
        ];
    }

    // Если нашли через 1C-Bank — возвращаем
    if (!empty($lines)) return $lines;

    // Fallback: ISO 20022 camt.053 — //Ntry (Entry)
    foreach ($xml->xpath('//*[local-name()="Ntry"]') as $ntry) {
        $amount = abs((float)(string)($ntry->Amt ?? 0));
        if ($amount == 0) continue;
        $ind = strtoupper((string)($ntry->CdtDbtInd ?? ''));
        $dir = ($ind === 'CRDT') ? 'in' : 'out';
        $date = substr((string)($ntry->BookgDt->Dt ?? $ntry->ValDt->Dt ?? ''), 0, 10);
        if (!$date) continue;

        $txDtls = $ntry->NtryDtls->TxDtls ?? null;
        $cp     = $txDtls ? (string)($txDtls->RltdPties->Cdtr->Nm ?? $txDtls->RltdPties->Dbtr->Nm ?? '') : '';
        $desc   = $txDtls ? (string)($txDtls->RmtInf->Ustrd ?? '') : '';
        $docNum = (string)($ntry->AcctSvcrRef ?? '');

        $lines[] = [
            'direction'      => $dir,
            'amount'         => $amount,
            'operation_date' => $date,
            'value_date'     => null,
            'counterparty'   => mb_substr($cp, 0, 255),
            'inn'            => null,
            'description'    => $desc,
            'doc_number'     => $docNum,
            'raw'            => json_decode(json_encode($ntry), true),
        ];
    }
    return $lines;
}

// ─────────────────────────────  OZON  ──────────────────────────────────────

/** Тянет все финансовые операции Ozon за период (постранично). */
function ozonFetchTransactions(array $acc, string $from, string $to): array {
    $clientId = trim((string)($acc['api_client_id'] ?? ''));
    $apiKey   = trim((string)($acc['api_token'] ?? ''));
    if ($clientId === '' || $apiKey === '') return ['error' => 'Не заданы Client-Id или Api-Key Ozon'];

    $headers = ['Client-Id: ' . $clientId, 'Api-Key: ' . $apiKey, 'Content-Type: application/json'];
    $lines = [];
    $page  = 1;
    $guard = 0;
    do {
        $r = httpJson('POST', OZON_BASE . '/v3/finance/transaction/list', $headers, [
            'filter'    => ['date' => ['from' => $from . 'T00:00:00.000Z', 'to' => $to . 'T23:59:59.999Z'], 'transaction_type' => 'all'],
            'page'      => $page,
            'page_size' => 1000,
        ]);
        if ($r['code'] >= 400 || !$r['json']) {
            return ['error' => 'Ozon HTTP ' . $r['code'] . ': ' . mb_substr($r['raw'], 0, 300)];
        }
        $result = $r['json']['result'] ?? [];
        $lines  = array_merge($lines, ozonMapOperations($result['operations'] ?? []));
        $pageCount = (int)($result['page_count'] ?? 1);
        $page++;
        $guard++;
    } while ($page <= $pageCount && $guard < 100);

    return ['lines' => array_values(array_filter($lines, fn($l) => !empty($l['operation_date'])))];
}

/** Чистый маппинг операций Ozon → нормализованные строки. */
function ozonMapOperations(array $ops): array {
    $lines = [];
    foreach ($ops as $op) {
        $amount = (float)($op['amount'] ?? 0);
        if ($amount == 0.0) continue;
        $posting = $op['posting']['posting_number'] ?? '';
        $name    = $op['operation_type_name'] ?? ($op['operation_type'] ?? 'Операция Ozon');
        $lines[] = [
            'direction'      => $amount >= 0 ? 'in' : 'out',
            'amount'         => abs($amount),
            'operation_date' => substr((string)($op['operation_date'] ?? ''), 0, 10),
            'value_date'     => null,
            'counterparty'   => 'Ozon',
            'inn'            => null,
            'description'    => trim($name . ($posting ? " · {$posting}" : '')),
            'doc_number'     => (string)($op['operation_id'] ?? ''),
            'raw'            => $op,
        ];
    }
    return $lines;
}
