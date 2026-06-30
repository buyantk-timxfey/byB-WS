<?php

namespace App\Http\Controllers;

use App\Models\MailAccount;
use App\Models\MailMessage;
use App\Services\ImapClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;

class MailController extends Controller
{
    public function index()
    {
        $this->bootstrapAccounts();

        $accounts = MailAccount::get()->map(fn (MailAccount $a) => [
            'id'      => $a->id,
            'email'   => $a->email,
            'folders' => $this->accountFolders($a),
        ]);

        $messages = MailMessage::with('counterparty:id,name')->orderByDesc('date')->orderByDesc('id')->limit(300)->get()
            ->map(fn (MailMessage $m) => [
                'id'         => $m->id, 'account_id' => $m->account_id, 'folder' => $m->folder,
                'from'       => $m->from_name ?? $m->from_email ?? '—', 'email' => $m->from_email,
                'subject'    => $m->subject,
                'preview'    => $this->cleanBody($m->preview),
                'body'       => $this->cleanBody($m->body),
                'time'       => optional($m->date)->format('d.m H:i'), 'unread' => ! $m->is_read,
                'attach'     => $m->has_attach ? 1 : 0, 'party' => $m->counterparty?->name,
            ]);

        return Inertia::render('Mail', [
            'accounts'      => $accounts,
            'messages'      => $messages,
            'imapAvailable' => true,
        ]);
    }

    // Формирует список папок для аккаунта: из БД если уже обнаружены, иначе дефолт.
    private function accountFolders(MailAccount $a): array
    {
        if ($a->folders) {
            return collect($a->folders)->map(fn ($f) => [
                'id'        => $f['name'].':'.$a->id,
                'name'      => $f['label'],
                'imap_name' => $f['name'],
                'count'     => $a->messages()->where('folder', $f['name'])->where('is_read', false)->count(),
            ])->toArray();
        }

        return [
            ['id' => 'INBOX:'.$a->id, 'name' => 'Входящие',      'imap_name' => 'INBOX', 'count' => $a->messages()->where('folder', 'INBOX')->where('is_read', false)->count()],
            ['id' => 'Sent:'.$a->id,  'name' => 'Отправленные',   'imap_name' => 'Sent',  'count' => 0],
        ];
    }

    // Создаёт аккаунты из .env при первом запуске, если таблица пустая.
    private function bootstrapAccounts(): void
    {
        if (MailAccount::exists()) {
            return;
        }

        $slots = [
            ['email' => env('BYBUKA_MAIL_1_EMAIL'), 'password' => env('BYBUKA_MAIL_1_PASS')],
            ['email' => env('BYBUKA_MAIL_2_EMAIL'), 'password' => env('BYBUKA_MAIL_2_PASS')],
        ];

        foreach ($slots as $slot) {
            if (empty($slot['email']) || empty($slot['password'])) {
                continue;
            }
            MailAccount::create($this->withDefaults($slot));
        }
    }

    public function storeAccount(Request $r)
    {
        $d = $r->validate(['email' => 'required|email', 'password' => 'required|string', 'login' => 'nullable|string']);
        MailAccount::create($this->withDefaults($d));

        return back();
    }

    private function withDefaults(array $d): array
    {
        $domain = substr((string) strrchr($d['email'], '@'), 1);
        $d['login'] = ($d['login'] ?? '') ?: $d['email'];
        [$imapHost, $smtpHost] = $this->detectProvider($domain);
        $d['imap_host'] = $imapHost;
        $d['imap_port'] = 993;
        $d['smtp_host'] = $smtpHost;
        $d['smtp_port'] = 465;
        $d['use_ssl']   = true;

        return $d;
    }

    private function detectProvider(string $domain): array
    {
        $known = [
            'mail.ru'     => ['imap.mail.ru',          'smtp.mail.ru'],
            'inbox.ru'    => ['imap.mail.ru',          'smtp.mail.ru'],
            'list.ru'     => ['imap.mail.ru',          'smtp.mail.ru'],
            'bk.ru'       => ['imap.mail.ru',          'smtp.mail.ru'],
            'yandex.ru'   => ['imap.yandex.ru',        'smtp.yandex.ru'],
            'ya.ru'       => ['imap.yandex.ru',        'smtp.yandex.ru'],
            'gmail.com'   => ['imap.gmail.com',        'smtp.gmail.com'],
            'outlook.com' => ['outlook.office365.com', 'smtp.office365.com'],
            'hotmail.com' => ['outlook.office365.com', 'smtp.office365.com'],
        ];
        if (isset($known[$domain])) {
            return $known[$domain];
        }

        $mxHosts = [];
        if (@getmxrr($domain, $mxHosts)) {
            foreach ($mxHosts as $mx) {
                $mx = strtolower(rtrim((string) $mx, '.'));
                if (str_contains($mx, 'mail.ru'))                              return ['imap.mail.ru',          'smtp.mail.ru'];
                if (str_contains($mx, 'yandex'))                               return ['imap.yandex.ru',        'smtp.yandex.ru'];
                if (str_contains($mx, 'google') || str_contains($mx, 'gmail')) return ['imap.gmail.com',        'smtp.gmail.com'];
                if (str_contains($mx, 'outlook') || str_contains($mx, 'office365')) return ['outlook.office365.com', 'smtp.office365.com'];
            }
        }

        return ['mail.'.$domain, 'mail.'.$domain];
    }

    public function updateAccount(Request $r, MailAccount $account)
    {
        $d = $r->validate(['email' => 'required|email', 'password' => 'nullable|string', 'login' => 'nullable|string']);
        $hasPassword = ! empty($d['password']);
        $d = $this->withDefaults($d);
        if (! $hasPassword) {
            unset($d['password']);
        }
        $account->update($d);

        return back();
    }

    public function destroyAccount(MailAccount $account)
    {
        $account->delete();

        return back();
    }

    public function compose(Request $r)
    {
        $d = $r->validate([
            'account_id'     => 'required|exists:mail_accounts,id',
            'to'             => 'required|email', 'subject' => 'nullable|string', 'body' => 'nullable|string',
            'counterparty_id' => 'nullable|exists:counterparties,id',
            'doc_type'       => 'nullable|string', 'doc_id' => 'nullable|integer',
        ]);
        $account = MailAccount::findOrFail($d['account_id']);

        $sent = false;
        if ($account->smtp_host) {
            try {
                config(['mail.mailers.dynamic' => [
                    'transport' => 'smtp', 'host' => $account->smtp_host,
                    'port' => $account->smtp_port ?: 465, 'encryption' => $account->use_ssl ? 'ssl' : 'tls',
                    'username' => $account->login ?: $account->email, 'password' => $account->password,
                ]]);
                Mail::mailer('dynamic')->raw($d['body'] ?? '', function ($m) use ($d, $account) {
                    $m->from($account->email)->to($d['to'])->subject($d['subject'] ?? '(без темы)');
                });
                $sent = true;
            } catch (\Throwable $e) {
                report($e);
            }
        }

        MailMessage::create([
            'account_id' => $account->id, 'folder' => 'Sent',
            'from_name'  => $account->email, 'from_email' => $account->email,
            'subject'    => $d['subject'], 'preview' => mb_substr((string) ($d['body'] ?? ''), 0, 120),
            'body'       => $d['body'], 'date' => now(), 'is_read' => true,
            'counterparty_id' => $d['counterparty_id'] ?? null,
            'doc_type'   => $d['doc_type'] ?? null, 'doc_id' => $d['doc_id'] ?? null,
        ]);

        return back()->with('mailSent', $sent);
    }

    public function markRead(MailMessage $message)
    {
        $message->update(['is_read' => true]);

        return back();
    }

    public function destroy(MailMessage $message)
    {
        $message->delete();

        return back();
    }

    // Очищает тела писем, сохранённых старым кодом: CSS-блоки + HTML-сущности.
    private function cleanBody(?string $text): string
    {
        if ($text === null || $text === '') {
            return '';
        }
        // Декодируем HTML-сущности (&quot; &amp; &lt; и т.д.)
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Убираем "RTF Template" заголовок
        $text = (string) preg_replace('/\ARTF\s+Template[ \t]*\r?\n/u', '', $text);
        // Убираем ведущие CSS-блоки любого вида (.class{}, @media{}, :root{}, body{} и т.д.)
        // Используем подсчёт глубины скобок, чтобы корректно обрабатывать вложенность @media
        if (preg_match('/\A[ \t]*(?:@|\.[\w-]|#[\w-]|:[a-z]|\*[ \t]*\{|html[ \t{]|body[ \t{])/iu', $text)) {
            $n     = strlen($text);
            $i     = 0;
            $depth = 0;
            while ($i < $n) {
                $c = $text[$i];
                if ($c === '{') {
                    $depth++;
                } elseif ($c === '}') {
                    $depth--;
                    if ($depth <= 0) {
                        $depth = 0;
                        // Пропускаем пробелы/переносы после закрывающей скобки
                        $j = $i + 1;
                        while ($j < $n && ($text[$j] === ' ' || $text[$j] === "\t" || $text[$j] === "\r" || $text[$j] === "\n")) {
                            $j++;
                        }
                        $rest = substr($text, $j);
                        // Если следующее содержимое больше не выглядит как CSS — это начало текста письма
                        if (!preg_match('/\A(?:@|\.[\w-]|#[\w-]|:[a-z]|\*[ \t]*\{|html[ \t{]|body[ \t{])/iu', $rest)) {
                            $text = $rest;
                            break;
                        }
                        $i = $j - 1;
                    }
                }
                $i++;
            }
        }

        return trim($text);
    }

    // Синхронизация папки: PHP imap-расширение (приоритет) → сокеты (fallback).
    public function sync(Request $r, MailAccount $account)
    {
        $folder = $r->input('folder', 'INBOX');

        if (function_exists('imap_open')) {
            return $this->syncViaExtension($account, $folder);
        }

        return $this->syncViaSockets($account);
    }

    private function syncViaExtension(MailAccount $account, string $targetFolder = 'INBOX')
    {
        $port = $account->imap_port ?: 993;
        $user = $account->login ?: $account->email;

        $domain = substr((string) strrchr($account->email, '@'), 1);
        [$mxHost] = $this->detectProvider($domain);
        $hosts = array_values(array_unique(array_filter([$account->imap_host, $mxHost])));

        $imap     = null;
        $mbPrefix = '';
        $lastErr  = 'не удалось подключиться';

        foreach ($hosts as $host) {
            $prefix = '{' . $host . ':' . $port . '/imap/ssl/novalidate-cert}';
            $conn   = @imap_open($prefix . 'INBOX', $user, (string) $account->password, 0, 1, ['DISABLE_AUTHENTICATOR' => 'GSSAPI']);
            if ($conn) {
                $imap     = $conn;
                $mbPrefix = $prefix;
                if ($host !== $account->imap_host) {
                    $account->update(['imap_host' => $host]);
                }
                break;
            }
            $lastErr = imap_last_error() ?: $lastErr;
        }

        if (! $imap) {
            return back()->withErrors(['imap' => 'Почта: ' . $lastErr]);
        }

        try {
            // Обновляем список папок при каждой синхронизации
            $account->update(['folders' => $this->detectFolders($imap, $mbPrefix)]);

            // Переключаемся на нужную папку
            if ($targetFolder !== 'INBOX') {
                $reopened = @imap_reopen($imap, $mbPrefix . $targetFolder);
                if (! $reopened) {
                    // Попытка с UTF-7 кодированием
                    $encoded  = mb_convert_encoding($targetFolder, 'UTF7-IMAP', 'UTF-8');
                    $reopened = @imap_reopen($imap, $mbPrefix . $encoded);
                }
                if (! $reopened) {
                    imap_close($imap);
                    return back()->withErrors(['imap' => 'Папка не найдена: ' . $targetFolder]);
                }
            }

            $total = imap_num_msg($imap);
            if ($total > 0) {
                $start    = max(1, $total - 49);
                $overview = imap_fetch_overview($imap, "$start:$total", 0) ?: [];
                foreach (array_reverse($overview) as $msg) {
                    $uid = $msg->uid;
                    if (MailMessage::where('account_id', $account->id)->where('folder', $targetFolder)->where('uid', (string) $uid)->exists()) {
                        continue;
                    }
                    $subject = $this->imapMimeDecode($msg->subject ?? '');
                    $from    = $this->imapMimeDecode($msg->from ?? '');
                    [$fromName, $fromEmail] = $this->parseFromStr($from);
                    $body = '';
                    try {
                        $structure = imap_fetchstructure($imap, $uid, FT_UID);
                        $body      = $this->fetchPlainBody($imap, $uid, $structure);
                    } catch (\Throwable) {}

                    MailMessage::create([
                        'account_id' => $account->id, 'folder' => $targetFolder, 'uid' => (string) $uid,
                        'from_name'  => $fromName ?: null, 'from_email' => $fromEmail ?: null,
                        'subject'    => $subject ?: null,
                        'preview'    => mb_substr(trim($body), 0, 120), 'body' => $body,
                        'date'       => $msg->date ? date('Y-m-d H:i:s', strtotime($msg->date) ?: time()) : now(),
                        'is_read'    => (bool) ($msg->seen ?? false),
                    ]);
                }
            }
            imap_close($imap);
        } catch (\Throwable $e) {
            @imap_close($imap);

            return back()->withErrors(['imap' => 'Почта: ' . $e->getMessage()]);
        }

        return back();
    }

    // Получает список папок с IMAP-сервера и возвращает в нормализованном виде.
    private function detectFolders($imap, string $mbPrefix): array
    {
        $nameMap = [
            'INBOX'        => 'Входящие',
            'Sent'         => 'Отправленные', 'Sent Items' => 'Отправленные', 'SENT' => 'Отправленные',
            'Drafts'       => 'Черновики',    'DRAFTS' => 'Черновики',
            'Trash'        => 'Корзина',      'Deleted' => 'Корзина', 'Deleted Items' => 'Корзина', 'TRASH' => 'Корзина',
            'Spam'         => 'Спам',         'Junk' => 'Спам', 'Junk Email' => 'Спам', 'Bulk Mail' => 'Спам',
            // mail.ru возвращает русские имена
            'Отправленные' => 'Отправленные',
            'Черновики'    => 'Черновики',
            'Удалённые'    => 'Корзина',
            'Спам'         => 'Спам',
        ];
        $sortOrder = ['INBOX', 'Sent', 'Sent Items', 'Отправленные', 'Drafts', 'Черновики', 'Spam', 'Junk', 'Спам', 'Trash', 'Deleted', 'Удалённые'];

        $raw = @imap_list($imap, $mbPrefix, '*');
        if (! $raw) {
            return [
                ['name' => 'INBOX', 'label' => 'Входящие'],
                ['name' => 'Sent',  'label' => 'Отправленные'],
            ];
        }

        $folders = [];
        foreach ($raw as $f) {
            $name = substr($f, strlen($mbPrefix));
            // Раскодируем modified UTF-7 (IMAP-кодировка non-ASCII имён папок)
            if (str_contains($name, '&')) {
                $decoded = @mb_convert_encoding($name, 'UTF-8', 'UTF7-IMAP');
                if ($decoded && $decoded !== '') {
                    $name = $decoded;
                }
            }
            $label     = $nameMap[$name] ?? $name;
            $folders[] = ['name' => $name, 'label' => $label];
        }

        usort($folders, function ($a, $b) use ($sortOrder) {
            $ia = array_search($a['name'], $sortOrder);
            $ib = array_search($b['name'], $sortOrder);
            $ia = ($ia === false) ? 999 : $ia;
            $ib = ($ib === false) ? 999 : $ib;

            return $ia - $ib;
        });

        return $folders;
    }

    private function imapMimeDecode(string $s): string
    {
        if ($s === '' || ! function_exists('imap_mime_header_decode')) {
            return $s;
        }
        $parts = imap_mime_header_decode($s);
        $out   = '';
        foreach ($parts as $p) {
            $charset = ($p->charset === 'default') ? 'UTF-8' : $p->charset;
            $out    .= @mb_convert_encoding($p->text, 'UTF-8', $charset ?: 'UTF-8');
        }

        return $out;
    }

    private function parseFromStr(string $from): array
    {
        if (preg_match('/^(.*?)<([^>]+)>/', trim($from), $m)) {
            return [trim($m[1], " \"'\t"), trim($m[2])];
        }

        return ['', $from];
    }

    private function fetchPlainBody($imap, int $uid, $structure, string $partNum = ''): string
    {
        $type = (int) ($structure->type ?? 0);
        if ($type === 1) {
            foreach (($structure->parts ?? []) as $i => $part) {
                $num  = $partNum ? "$partNum." . ($i + 1) : (string) ($i + 1);
                $body = $this->fetchPlainBody($imap, $uid, $part, $num);
                if ($body !== '') {
                    return $body;
                }
            }

            return '';
        }
        if ($type !== 0) {
            return '';
        }
        $subtype = strtolower($structure->subtype ?? 'plain');
        if ($subtype !== 'plain' && $subtype !== 'html') {
            return '';
        }
        $encoding = $structure->encoding ?? 0;
        $charset  = 'UTF-8';
        foreach (($structure->parameters ?? []) as $p) {
            if (strcasecmp($p->attribute, 'charset') === 0) {
                $charset = $p->value;
                break;
            }
        }
        $raw  = imap_fetchbody($imap, $uid, $partNum ?: '1', FT_UID | FT_PEEK);
        $body = match ((int) $encoding) {
            3       => base64_decode($raw),
            4       => quoted_printable_decode($raw),
            default => $raw,
        };
        $body = (string) @mb_convert_encoding($body, 'UTF-8', $charset);
        if ($subtype === 'html') {
            // Удаляем содержимое <style> и <script> до strip_tags, иначе CSS попадает в текст
            $body = preg_replace('/<style[^>]*>.*?<\/style>/si', '', $body);
            $body = preg_replace('/<script[^>]*>.*?<\/script>/si', '', $body);
            $body = trim((string) preg_replace('/[ \t]*\R+[ \t]*/u', "\n", strip_tags($body)));
            $body = html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return $body;
    }

    private function syncViaSockets(MailAccount $account)
    {
        $domain     = substr((string) strrchr($account->email, '@'), 1);
        [$mxImap]   = $this->detectProvider($domain);
        $candidates = array_values(array_unique(array_filter([
            $account->imap_host, $mxImap, 'localhost', gethostname() ?: null, 'mail.'.$domain,
        ])));
        $port = $account->imap_port ?: 993;
        $user = $account->login ?: $account->email;

        $client    = null;
        $lastError = 'не удалось подключиться';
        foreach ($candidates as $host) {
            try {
                $c = new ImapClient($host, $port, $user, (string) $account->password, (bool) $account->use_ssl, 4);
                $c->connect();
                $c->login();
                $client = $c;
                if ($host !== $account->imap_host) {
                    $account->update(['imap_host' => $host]);
                }
                break;
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
            }
        }
        if (! $client) {
            return back()->withErrors(['imap' => 'Почта: '.$lastError]);
        }

        try {
            $client->selectInbox();
            foreach (array_reverse($client->recentUids(30)) as $uid) {
                if (MailMessage::where('account_id', $account->id)->where('uid', (string) $uid)->exists()) {
                    continue;
                }
                $msg                              = $client->fetch($uid);
                [$fromName, $fromEmail, $subject, $date] = $this->parseHeader($msg['header']);
                $body                             = $this->decodeBody($msg['body']);
                MailMessage::create([
                    'account_id' => $account->id, 'folder' => 'INBOX', 'uid' => (string) $uid,
                    'from_name'  => $fromName, 'from_email' => $fromEmail, 'subject' => $subject,
                    'preview'    => mb_substr(trim($body), 0, 120), 'body' => $body,
                    'date'       => $date, 'is_read' => $msg['seen'],
                ]);
            }
            $client->close();
        } catch (\Throwable $e) {
            $client->close();

            return back()->withErrors(['imap' => 'Почта: '.$e->getMessage()]);
        }

        return back();
    }

    private function parseHeader(string $raw): array
    {
        $raw     = (string) preg_replace('/\r?\n[ \t]+/', ' ', $raw);
        $from    = $subject = $date = '';
        foreach (preg_split('/\r?\n/', $raw) as $line) {
            if (preg_match('/^From:\s*(.+)$/i', $line, $m)) {
                $from = trim($m[1]);
            } elseif (preg_match('/^Subject:\s*(.+)$/i', $line, $m)) {
                $subject = trim($m[1]);
            } elseif (preg_match('/^Date:\s*(.+)$/i', $line, $m)) {
                $date = trim($m[1]);
            }
        }
        $fromName  = '';
        $fromEmail = $from;
        if (preg_match('/^(.*)<([^>]+)>/', $from, $m)) {
            $fromName  = $this->mime(trim($m[1], " \"'"));
            $fromEmail = trim($m[2]);
        }
        $dt = $date ? date('Y-m-d H:i:s', strtotime($date) ?: time()) : now();

        return [$fromName ?: null, $fromEmail ?: null, $this->mime($subject) ?: null, $dt];
    }

    private function mime(string $s): string
    {
        if ($s === '') {
            return '';
        }
        $d = @mb_decode_mimeheader($s);

        return $d !== false && $d !== '' ? $d : $s;
    }

    private function decodeBody(string $b): string
    {
        $b = trim($b);
        if ($b === '') {
            return '';
        }
        $out     = quoted_printable_decode($b);
        $compact = (string) preg_replace('/\s+/', '', $b);
        if ($compact !== '' && strlen($compact) % 4 === 0 && preg_match('/^[A-Za-z0-9+\/=]+$/', $compact)) {
            $dec = base64_decode($compact, true);
            if ($dec !== false && $dec !== '') {
                $out = $dec;
            }
        }
        if (! mb_check_encoding($out, 'UTF-8')) {
            $out = (string) @mb_convert_encoding($out, 'UTF-8', 'Windows-1251, ISO-8859-1, UTF-8');
        }
        if (preg_match('/<\/?(html|body|div|p|br|table|span)\b/i', $out)) {
            $out = preg_replace('/<style[^>]*>.*?<\/style>/si', '', $out);
            $out = preg_replace('/<script[^>]*>.*?<\/script>/si', '', $out);
            $out = trim((string) preg_replace('/[ \t]*\R+[ \t]*/u', "\n", strip_tags($out)));
        }

        return $out;
    }
}
