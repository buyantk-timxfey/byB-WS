<?php
ini_set('display_errors', 0);
error_reporting(0);
session_start();
if (empty($_SESSION['auth'])) {
    http_response_code(401);
    exit(json_encode(['error' => 'Unauthorized']));
}

$mail_cfg_file = __DIR__ . '/../mail_config.php';
if (!file_exists($mail_cfg_file)) {
    http_response_code(500);
    header('Content-Type: application/json');
    exit(json_encode(['error' => 'mail_config.php не найден']));
}
$mail_cfg = require $mail_cfg_file;

$action      = $_GET['action'] ?? '';
$account_key = $_GET['account'] ?? array_key_first($mail_cfg['accounts']);
$acc         = $mail_cfg['accounts'][$account_key] ?? null;

// Attachment download — выходим до установки JSON заголовка
if ($action === 'attachment') {
    if (!$acc) { http_response_code(400); exit('Bad account'); }
    $uid    = (int)($_GET['uid'] ?? 0);
    $folder = $_GET['folder'] ?? 'INBOX';
    $part   = $_GET['part'] ?? '1';
    try {
        $imap       = imap_open_acc($acc, $folder);
        $structure  = imap_fetchstructure($imap, $uid, FT_UID);
        $part_struct = find_part($structure, $part);
        $encoding   = $part_struct->encoding ?? 0;
        $filename   = get_param($part_struct->dparameters ?? null, 'filename')
                   ?? get_param($part_struct->parameters ?? null, 'name')
                   ?? 'attachment';
        $filename = decode_header_str($filename);
        $body     = imap_fetchbody($imap, $uid, $part, FT_UID);
        $body     = decode_body($body, $encoding);
        imap_close($imap);
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
        header('Content-Length: ' . strlen($body));
        echo $body;
    } catch (Exception $e) {
        http_response_code(500);
        echo $e->getMessage();
    }
    exit;
}

header('Content-Type: application/json; charset=utf-8');

if (!$acc) {
    http_response_code(400);
    exit(json_encode(['error' => 'Неизвестный аккаунт']));
}

// ─── Helpers ──────────────────────────────────────────────────────────────────

function imap_open_acc($acc, $folder = 'INBOX') {
    $mb   = '{' . $acc['imap_host'] . ':' . $acc['imap_port'] . '/imap/ssl/novalidate-cert}' . $folder;
    $conn = @imap_open($mb, $acc['email'], $acc['password'], 0, 1, ['DISABLE_AUTHENTICATOR' => 'GSSAPI']);
    if (!$conn) {
        throw new RuntimeException('IMAP: ' . imap_last_error());
    }
    return $conn;
}

function imap_mb_root($acc) {
    return '{' . $acc['imap_host'] . ':' . $acc['imap_port'] . '/imap/ssl/novalidate-cert}';
}

function decode_body($body, $encoding) {
    switch ((int)$encoding) {
        case 3: return base64_decode($body);
        case 4: return quoted_printable_decode($body);
        default: return $body;
    }
}

function get_param($params, $name) {
    if (!$params) return null;
    foreach ($params as $p) {
        if (strcasecmp($p->attribute, $name) === 0) return $p->value;
    }
    return null;
}

function decode_header_str($str) {
    if (!$str) return '';
    $parts  = imap_mime_header_decode($str);
    $result = '';
    foreach ($parts as $part) {
        $charset = ($part->charset === 'default') ? 'UTF-8' : $part->charset;
        $result .= @mb_convert_encoding($part->text, 'UTF-8', $charset ?: 'UTF-8');
    }
    return $result;
}

function decode_folder_name($name) {
    // Используем mb_convert_encoding с UTF7-IMAP — надёжнее чем imap_utf7_decode
    $decoded = @mb_convert_encoding($name, 'UTF-8', 'UTF7-IMAP');
    return $decoded ?: $name;
}

function extract_parts($imap, $uid, $structure, $part_num = '') {
    $html        = '';
    $plain       = '';
    $attachments = [];
    $inlines     = []; // cid → ['part','mime'] для картинок, встроенных в HTML
    $type        = (int)($structure->type ?? 0);

    if ($type === 1) { // multipart
        $subtype = strtolower($structure->subtype ?? '');
        foreach (($structure->parts ?? []) as $i => $part) {
            $num = $part_num ? "$part_num." . ($i + 1) : (string)($i + 1);
            [$h, $p, $a, $inl] = extract_parts($imap, $uid, $part, $num);
            if ($subtype === 'alternative') {
                // prefer html, fallback to plain
                $html  = $html  ?: $h;
                $plain = $plain ?: $p;
            } else {
                $html  = $html  ?: $h;
                $plain = $plain ?: $p;
                $attachments = array_merge($attachments, $a);
            }
            $inlines = array_merge($inlines, $inl);
        }
    } elseif ($type === 0) { // text
        $subtype     = strtolower($structure->subtype ?? 'plain');
        $encoding    = $structure->encoding ?? 0;
        $charset     = get_param($structure->parameters ?? null, 'charset') ?? 'UTF-8';
        $disposition = strtolower($structure->disposition ?? '');
        $filename    = get_param($structure->dparameters ?? null, 'filename')
                    ?? get_param($structure->parameters  ?? null, 'name');

        if ($filename || $disposition === 'attachment') {
            $attachments[] = [
                'part' => $part_num ?: '1',
                'name' => decode_header_str($filename ?? 'file'),
                'size' => $structure->bytes ?? 0,
            ];
        } else {
            $body = imap_fetchbody($imap, $uid, $part_num ?: '1', FT_UID | FT_PEEK);
            $body = decode_body($body, $encoding);
            $body = @mb_convert_encoding($body, 'UTF-8', $charset);
            if ($subtype === 'html') {
                $html = $body;
            } else {
                $plain = $body;
            }
        }
    } else { // attachment (type 2–5)
        $filename = get_param($structure->dparameters ?? null, 'filename')
                 ?? get_param($structure->parameters  ?? null, 'name')
                 ?? 'attachment';
        // Картинка с Content-ID — встроена в HTML (логотипы, подписи)
        if ($type === 5 && !empty($structure->id)) {
            $cid = trim($structure->id, '<>');
            $inlines[$cid] = [
                'part' => $part_num ?: '1',
                'mime' => 'image/' . strtolower($structure->subtype ?? 'png'),
                'size' => $structure->bytes ?? 0,
            ];
        } else {
            $attachments[] = [
                'part' => $part_num ?: '1',
                'name' => decode_header_str($filename),
                'size' => $structure->bytes ?? 0,
            ];
        }
    }

    return [$html, $plain, $attachments, $inlines];
}

/** Ищет служебную папку (Отправленные/Черновики/Корзина/Спам) по списку имён. */
function find_special_folder($imap, $root, array $names): ?string {
    $folders = imap_list($imap, $root, '*') ?: [];
    foreach ($folders as $f) {
        $fname = mb_strtolower(decode_folder_name(str_replace($root, '', $f)));
        foreach ($names as $n) {
            if ($fname === $n || str_contains($fname, $n)) return $f;
        }
    }
    return null;
}

const MAIL_FOLDER_SENT   = ['sent', 'отправленные'];
const MAIL_FOLDER_DRAFTS = ['drafts', 'черновики'];
const MAIL_FOLDER_TRASH  = ['trash', 'корзина', 'удалённые', 'deleted'];
const MAIL_FOLDER_SPAM   = ['spam', 'junk', 'спам'];

function encode_mime_header($str) {
    return preg_match('/[^\x20-\x7E]/', $str) ? '=?UTF-8?B?' . base64_encode($str) . '?=' : $str;
}

/**
 * Собирает MIME-письмо: HTML-тело + вложения (multipart/mixed).
 * $attachments: [['name'=>..., 'mime'=>..., 'data'=>raw], ...]
 * Возвращает [$headers, $body].
 */
function build_mime($acc, $to, $cc, $subject, $bodyHtml, array $attachments) {
    $msg_id  = '<' . uniqid('byb.', true) . '@' . explode('@', $acc['email'])[1] . '>';
    $headers = 'From: ' . encode_mime_header($acc['label']) . " <{$acc['email']}>\r\n"
             . "To: $to\r\n"
             . ($cc !== '' ? "Cc: $cc\r\n" : '')
             . 'Subject: ' . encode_mime_header($subject) . "\r\n"
             . 'Date: ' . date('r') . "\r\n"
             . "Message-ID: $msg_id\r\n"
             . "MIME-Version: 1.0\r\n";

    $htmlPart = chunk_split(base64_encode($bodyHtml));

    if (empty($attachments)) {
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n"
                  . "Content-Transfer-Encoding: base64\r\n";
        return [$headers, $htmlPart];
    }

    $boundary = 'byb-' . md5(uniqid('', true));
    $headers .= "Content-Type: multipart/mixed; boundary=\"$boundary\"\r\n";

    $body  = "--$boundary\r\n"
           . "Content-Type: text/html; charset=UTF-8\r\n"
           . "Content-Transfer-Encoding: base64\r\n\r\n"
           . $htmlPart . "\r\n";

    foreach ($attachments as $a) {
        $encName = encode_mime_header($a['name']);
        $body .= "--$boundary\r\n"
               . "Content-Type: {$a['mime']}; name=\"$encName\"\r\n"
               . "Content-Transfer-Encoding: base64\r\n"
               . "Content-Disposition: attachment; filename=\"$encName\"\r\n\r\n"
               . chunk_split(base64_encode($a['data'])) . "\r\n";
    }
    $body .= "--$boundary--\r\n";
    return [$headers, $body];
}

/** Список адресов "a@b, Name <c@d>" → массив чистых e-mail для RCPT. */
function parse_rcpts($str) {
    $out = [];
    foreach (explode(',', $str) as $t) {
        $t = trim($t);
        if ($t === '') continue;
        if (preg_match('/<(.+?)>/', $t, $m)) $t = $m[1];
        if (str_contains($t, '@')) $out[] = $t;
    }
    return $out;
}

function find_part($structure, $partNum) {
    $path = explode('.', (string)$partNum);
    $cur  = $structure;
    foreach ($path as $idx) {
        $cur = $cur->parts[(int)$idx - 1] ?? $cur;
    }
    return $cur;
}

function format_addr($addr) {
    if (!$addr) return '';
    $name  = !empty($addr->personal) ? decode_header_str($addr->personal) : '';
    $email = ($addr->mailbox ?? '') . '@' . ($addr->host ?? '');
    return $name ? "$name <$email>" : $email;
}

// ─── Actions ──────────────────────────────────────────────────────────────────

switch ($action) {

    case 'accounts':
        $result = [];
        foreach ($mail_cfg['accounts'] as $key => $a) {
            $result[] = ['key' => $key, 'email' => $a['email'], 'label' => $a['label']];
        }
        echo json_encode($result);
        break;

    case 'folders':
        try {
            $imap    = imap_open_acc($acc, 'INBOX');
            $root    = imap_mb_root($acc);
            $folders = imap_list($imap, $root, '*') ?: [];
            $result  = [];
            foreach ($folders as $f) {
                $name    = str_replace($root, '', $f);
                $decoded = decode_folder_name($name);
                $unseen  = 0;
                try {
                    $status = imap_status($imap, $f, SA_UNSEEN);
                    $unseen = $status->unseen ?? 0;
                } catch (Exception $e) {}
                $result[] = ['name' => $name, 'label' => $decoded, 'unseen' => (int)$unseen];
            }
            imap_close($imap);
            echo json_encode($result);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    case 'list':
        $folder   = $_GET['folder'] ?? 'INBOX';
        $page     = max(1, (int)($_GET['page'] ?? 1));
        $q        = trim($_GET['q'] ?? '');
        $per_page = 30;
        try {
            $imap     = imap_open_acc($acc, $folder);
            $messages = [];

            if ($q !== '') {
                // Поиск: тема, отправитель или текст письма
                $qEsc = str_replace('"', '', $q);
                $uids = @imap_search($imap, 'TEXT "' . $qEsc . '"', SE_UID, 'UTF-8')
                     ?: @imap_search($imap, 'SUBJECT "' . $qEsc . '"', SE_UID, 'UTF-8')
                     ?: @imap_search($imap, 'TEXT "' . $qEsc . '"', SE_UID)
                     ?: [];
                rsort($uids); // новые сверху
                $total = count($uids);
                $pageUids = array_slice($uids, ($page - 1) * $per_page, $per_page);
                if ($pageUids) {
                    $overview = imap_fetch_overview($imap, implode(',', $pageUids), FT_UID) ?: [];
                    // imap_fetch_overview не гарантирует порядок — сортируем сами
                    usort($overview, fn($a, $b) => $b->uid <=> $a->uid);
                    foreach ($overview as $msg) {
                        $messages[] = [
                            'uid'     => $msg->uid,
                            'subject' => decode_header_str($msg->subject ?? '(без темы)'),
                            'from'    => decode_header_str($msg->from ?? ''),
                            'date'    => $msg->date ?? '',
                            'seen'    => (bool)($msg->seen ?? false),
                            'flagged' => (bool)($msg->flagged ?? false),
                            'size'    => (int)($msg->size ?? 0),
                        ];
                    }
                }
            } else {
                $total = imap_num_msg($imap);
                if ($total > 0) {
                    $end   = max(1, $total - ($page - 1) * $per_page);
                    $start = max(1, $end - $per_page + 1);
                    $overview = imap_fetch_overview($imap, "$start:$end", 0);
                    $overview = array_reverse($overview);
                    foreach ($overview as $msg) {
                        $messages[] = [
                            'uid'     => $msg->uid,
                            'subject' => decode_header_str($msg->subject ?? '(без темы)'),
                            'from'    => decode_header_str($msg->from ?? ''),
                            'date'    => $msg->date ?? '',
                            'seen'    => (bool)($msg->seen ?? false),
                            'flagged' => (bool)($msg->flagged ?? false),
                            'size'    => (int)($msg->size ?? 0),
                        ];
                    }
                }
            }
            imap_close($imap);
            echo json_encode(['messages' => $messages, 'total' => $total, 'page' => $page, 'q' => $q]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    case 'message':
        $uid    = (int)($_GET['uid'] ?? 0);
        $folder = $_GET['folder'] ?? 'INBOX';
        try {
            $imap  = imap_open_acc($acc, $folder);
            $msgno = imap_msgno($imap, $uid);
            if (!$msgno) {
                // uid не найден — пробуем напрямую как msgno
                $msgno = $uid;
            }

            // Помечаем прочитанным
            @imap_setflag_full($imap, (string)$msgno, '\\Seen');

            $raw_header = imap_fetchheader($imap, $uid, FT_UID);
            if (!$raw_header) {
                // Fallback: попробуем через msgno
                $raw_header = imap_fetchheader($imap, $msgno, 0);
            }
            $header    = imap_rfc822_parse_headers($raw_header ?: '');
            $structure = imap_fetchstructure($imap, $uid, FT_UID)
                      ?: imap_fetchstructure($imap, $msgno, 0);

            [$html, $plain, $attachments, $inlines] = $structure
                ? extract_parts($imap, $uid, $structure)
                : ['', '', [], []];

            // Встраиваем inline-картинки (cid:) как data-URI, чтобы они отображались
            if ($html && $inlines && str_contains($html, 'cid:')) {
                $budget = 6 * 1024 * 1024; // суммарный лимит, чтобы не раздуть ответ
                foreach ($inlines as $cid => $inf) {
                    if (!str_contains($html, 'cid:' . $cid)) continue;
                    if (($inf['size'] ?? 0) > 2 * 1024 * 1024 || $budget <= 0) continue;
                    $data = imap_fetchbody($imap, $uid, $inf['part'], FT_UID | FT_PEEK);
                    $part_struct = find_part($structure, $inf['part']);
                    $data = decode_body($data, $part_struct->encoding ?? 3);
                    $budget -= strlen($data);
                    $html = str_replace('cid:' . $cid, 'data:' . $inf['mime'] . ';base64,' . base64_encode($data), $html);
                }
            }

            $to_list = [];
            foreach (($header->to ?? []) as $t) $to_list[] = format_addr($t);
            $cc_list = [];
            foreach (($header->cc ?? []) as $t) $cc_list[] = format_addr($t);

            // Флаг "важное" для кнопки-звёздочки
            $ov = imap_fetch_overview($imap, (string)$uid, FT_UID);
            $flagged = (bool)($ov[0]->flagged ?? false);

            $result = [
                'uid'         => $uid,
                'subject'     => decode_header_str($header->subject ?? '(без темы)'),
                'from'        => format_addr($header->from[0] ?? null),
                'reply_to'    => format_addr(($header->reply_to[0] ?? $header->from[0]) ?? null),
                'to'          => implode(', ', $to_list),
                'cc'          => implode(', ', $cc_list),
                'date'        => $header->date ?? '',
                'flagged'     => $flagged,
                'html'        => $html,
                'plain'       => $plain,
                'attachments' => $attachments,
            ];
            imap_close($imap);
            echo json_encode($result);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    case 'mark':
        $uid    = (int)($_GET['uid'] ?? 0);
        $folder = $_GET['folder'] ?? 'INBOX';
        $read   = (int)($_GET['read'] ?? 1);
        try {
            $imap  = imap_open_acc($acc, $folder);
            $msgno = (string)imap_msgno($imap, $uid);
            $read ? imap_setflag_full($imap, $msgno, '\\Seen')
                  : imap_clearflag_full($imap, $msgno, '\\Seen');
            imap_close($imap);
            echo json_encode(['ok' => true]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    // Пометить/снять флажок «важное»
    case 'flag':
        $uid     = (int)($_GET['uid'] ?? 0);
        $folder  = $_GET['folder'] ?? 'INBOX';
        $flagged = (int)($_GET['flagged'] ?? 1);
        try {
            $imap  = imap_open_acc($acc, $folder);
            $msgno = (string)imap_msgno($imap, $uid);
            $flagged ? imap_setflag_full($imap, $msgno, '\\Flagged')
                     : imap_clearflag_full($imap, $msgno, '\\Flagged');
            imap_close($imap);
            echo json_encode(['ok' => true, 'flagged' => (bool)$flagged]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    // Переместить письмо в другую папку (target = имя папки без корня)
    case 'move':
        $uid    = (int)($_GET['uid'] ?? 0);
        $folder = $_GET['folder'] ?? 'INBOX';
        $target = $_GET['target'] ?? '';
        if ($target === '' || $target === $folder) {
            http_response_code(400);
            echo json_encode(['error' => 'Не указана папка назначения']);
            break;
        }
        try {
            $imap = imap_open_acc($acc, $folder);
            $ok   = imap_mail_move($imap, (string)$uid, $target, CP_UID);
            if (!$ok) throw new RuntimeException('Не удалось переместить: ' . imap_last_error());
            imap_expunge($imap);
            imap_close($imap);
            echo json_encode(['ok' => true]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    // Удаление: перемещаем в Корзину; из Корзины (или purge=1) — навсегда
    case 'delete':
        $uid    = (int)($_GET['uid'] ?? 0);
        $folder = $_GET['folder'] ?? 'INBOX';
        $purge  = (int)($_GET['purge'] ?? 0);
        try {
            $imap = imap_open_acc($acc, $folder);
            $root = imap_mb_root($acc);

            $isTrash = false;
            foreach (MAIL_FOLDER_TRASH as $n) {
                if (str_contains(mb_strtolower(decode_folder_name($folder)), $n)) { $isTrash = true; break; }
            }

            if ($purge || $isTrash) {
                $msgno = imap_msgno($imap, $uid);
                imap_delete($imap, (string)$msgno);
                imap_expunge($imap);
                $moved = false;
            } else {
                $trash = find_special_folder($imap, $root, MAIL_FOLDER_TRASH);
                if ($trash) {
                    imap_mail_move($imap, (string)$uid, str_replace($root, '', $trash), CP_UID);
                    imap_expunge($imap);
                    $moved = true;
                } else {
                    $msgno = imap_msgno($imap, $uid);
                    imap_delete($imap, (string)$msgno);
                    imap_expunge($imap);
                    $moved = false;
                }
            }
            imap_close($imap);
            echo json_encode(['ok' => true, 'moved_to_trash' => $moved]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    case 'send':
    case 'save_draft':
        // FormData: to, cc, bcc, subject, body (HTML), attachments[],
        // draft_uid/draft_folder (перезапись черновика), forward_uid/forward_folder (вложения оригинала)
        $to      = trim($_POST['to'] ?? '');
        $cc      = trim($_POST['cc'] ?? '');
        $bcc     = trim($_POST['bcc'] ?? '');
        $subject = trim($_POST['subject'] ?? '');
        $body    = trim($_POST['body'] ?? '');
        $isDraft = $action === 'save_draft';

        if (!$isDraft && (!$to || !$subject)) {
            http_response_code(400);
            echo json_encode(['error' => 'Заполните получателя и тему']);
            break;
        }

        try {
            // Собираем вложения: загруженные файлы (+ лимит 20 МБ суммарно)
            $attachments = [];
            $totalSize   = 0;
            if (!empty($_FILES['attachments'])) {
                $files = $_FILES['attachments'];
                $count = is_array($files['name']) ? count($files['name']) : 0;
                for ($i = 0; $i < $count; $i++) {
                    if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
                    $totalSize += (int)$files['size'][$i];
                    $attachments[] = [
                        'name' => $files['name'][$i],
                        'mime' => $files['type'][$i] ?: 'application/octet-stream',
                        'data' => file_get_contents($files['tmp_name'][$i]),
                    ];
                }
            }

            // Пересылка: переносим вложения оригинального письма
            $fwdUid    = (int)($_POST['forward_uid'] ?? 0);
            $fwdFolder = $_POST['forward_folder'] ?? 'INBOX';
            if ($fwdUid) {
                $imapF = imap_open_acc($acc, $fwdFolder);
                $structF = imap_fetchstructure($imapF, $fwdUid, FT_UID);
                if ($structF) {
                    [, , $origAtt] = extract_parts($imapF, $fwdUid, $structF);
                    foreach ($origAtt as $a) {
                        if (($a['size'] ?? 0) <= 0) continue;
                        $ps   = find_part($structF, $a['part']);
                        $data = decode_body(imap_fetchbody($imapF, $fwdUid, $a['part'], FT_UID | FT_PEEK), $ps->encoding ?? 3);
                        $totalSize += strlen($data);
                        $attachments[] = ['name' => $a['name'], 'mime' => 'application/octet-stream', 'data' => $data];
                    }
                }
                imap_close($imapF);
            }

            if ($totalSize > 20 * 1024 * 1024) {
                http_response_code(400);
                echo json_encode(['error' => 'Вложения превышают 20 МБ суммарно']);
                break;
            }

            [$headers, $bodyMime] = build_mime($acc, $to, $cc, $subject, $body, $attachments);

            if ($isDraft) {
                // Сохраняем в Черновики
                $imap  = imap_open_acc($acc, 'INBOX');
                $root  = imap_mb_root($acc);
                $drafts = find_special_folder($imap, $root, MAIL_FOLDER_DRAFTS);
                if (!$drafts) throw new RuntimeException('Папка «Черновики» не найдена');
                imap_append($imap, $drafts, $headers . "\r\n" . $bodyMime, '\\Seen \\Draft');
            } else {
                // Отправка: получатели = Кому + Копия + Скрытая (скрытая не попадает в заголовки)
                $rcpts = array_merge(parse_rcpts($to), parse_rcpts($cc), parse_rcpts($bcc));
                if (empty($rcpts)) throw new RuntimeException('Не распознан ни один адрес получателя');
                $smtp = new SimpleSMTP($acc['smtp_host'], $acc['smtp_port'], $acc['email'], $acc['password']);
                $smtp->sendRaw($acc['email'], $rcpts, $headers, $bodyMime);
                $smtp->quit();

                // Сохраняем копию в «Отправленные»
                $imap = imap_open_acc($acc, 'INBOX');
                $root = imap_mb_root($acc);
                try {
                    $sent = find_special_folder($imap, $root, MAIL_FOLDER_SENT);
                    if ($sent) imap_append($imap, $sent, $headers . "\r\n" . $bodyMime, '\\Seen');
                } catch (Exception $e) { /* не критично */ }
            }

            // Удаляем старый черновик (письмо было открыто из Черновиков)
            $draftUid    = (int)($_POST['draft_uid'] ?? 0);
            $draftFolder = $_POST['draft_folder'] ?? '';
            if ($draftUid && $draftFolder) {
                try {
                    $imapD = imap_open_acc($acc, $draftFolder);
                    $msgno = imap_msgno($imapD, $draftUid);
                    if ($msgno) { imap_delete($imapD, (string)$msgno); imap_expunge($imapD); }
                    imap_close($imapD);
                } catch (Exception $e) { /* не критично */ }
            }
            if (isset($imap) && $imap) @imap_close($imap);

            echo json_encode(['ok' => true]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    case 'check_new':
        // Проверяем новые письма по всем аккаунтам (для push-уведомлений)
        $state_file  = __DIR__ . '/../mail_state.json';
        $state       = file_exists($state_file) ? json_decode(file_get_contents($state_file), true) : [];
        $new_messages = [];

        foreach ($mail_cfg['accounts'] as $key => $a) {
            try {
                $imap   = imap_open_acc($a, 'INBOX');
                $unseen = imap_search($imap, 'UNSEEN', SE_UID);
                if ($unseen) {
                    $last_uid  = (int)($state[$key]['last_uid'] ?? 0);
                    $latest_uid = max($unseen);
                    $new_uids  = array_filter($unseen, fn($u) => $u > $last_uid);
                    foreach ($new_uids as $uid) {
                        $ov = imap_fetch_overview($imap, "$uid", FT_UID);
                        if ($ov) {
                            $new_messages[] = [
                                'account' => $key,
                                'email'   => $a['email'],
                                'label'   => $a['label'],
                                'uid'     => $uid,
                                'subject' => decode_header_str($ov[0]->subject ?? ''),
                                'from'    => decode_header_str($ov[0]->from ?? ''),
                            ];
                        }
                    }
                    $state[$key]['last_uid'] = $latest_uid;
                }
                imap_close($imap);
            } catch (Exception $e) { /* пропускаем аккаунт */ }
        }

        file_put_contents($state_file, json_encode($state));
        echo json_encode(['new' => $new_messages, 'count' => count($new_messages)]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Неизвестное действие']);
}

// ─── Simple SMTP over SSL ─────────────────────────────────────────────────────

class SimpleSMTP {
    private $sock;

    public function __construct($host, $port, $user, $pass) {
        $ctx  = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
        $this->sock = stream_socket_client("ssl://$host:$port", $errno, $errstr, 30, STREAM_CLIENT_CONNECT, $ctx);
        if (!$this->sock) throw new RuntimeException("SMTP подключение: $errstr ($errno)");
        $this->read();
        $this->cmd('EHLO ' . (gethostname() ?: 'localhost'));
        $this->cmd('AUTH LOGIN');
        $this->cmd(base64_encode($user));
        $resp = $this->cmd(base64_encode($pass));
        if (!str_starts_with($resp, '235')) throw new RuntimeException("SMTP авторизация: $resp");
    }

    public function sendRaw($from, $to, $headers, $body) {
        $this->cmd("MAIL FROM:<$from>");
        // $to — массив чистых адресов или строка через запятую
        $rcpts = is_array($to) ? $to : array_map('trim', explode(',', $to));
        foreach ($rcpts as $t) {
            if (preg_match('/<(.+?)>/', $t, $m)) $t = $m[1];
            if ($t !== '') $this->cmd("RCPT TO:<$t>");
        }
        $this->cmd('DATA');
        fwrite($this->sock, $headers . "\r\n" . $body . "\r\n.\r\n");
        $this->read();
    }

    public function quit() {
        $this->cmd('QUIT');
        fclose($this->sock);
    }

    private function cmd($cmd) {
        fwrite($this->sock, $cmd . "\r\n");
        return $this->read();
    }

    private function read() {
        $resp = '';
        while (!feof($this->sock)) {
            $line  = fgets($this->sock, 515);
            $resp .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') break;
        }
        return $resp;
    }
}
