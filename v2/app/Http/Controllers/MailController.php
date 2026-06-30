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
        $accounts = MailAccount::get()->map(fn (MailAccount $a) => [
            'id' => $a->id, 'email' => $a->email,
            'folders' => [
                ['id' => 'INBOX:'.$a->id, 'name' => 'Входящие', 'count' => $a->messages()->where('folder', 'INBOX')->where('is_read', false)->count()],
                ['id' => 'Sent:'.$a->id, 'name' => 'Отправленные', 'count' => 0],
            ],
        ]);

        $messages = MailMessage::with('counterparty:id,name')->orderByDesc('date')->orderByDesc('id')->limit(300)->get()
            ->map(fn (MailMessage $m) => [
                'id' => $m->id, 'account_id' => $m->account_id, 'folder' => $m->folder,
                'from' => $m->from_name ?? $m->from_email ?? '—', 'email' => $m->from_email,
                'subject' => $m->subject, 'preview' => $m->preview, 'body' => $m->body,
                'time' => optional($m->date)->format('d.m H:i'), 'unread' => ! $m->is_read,
                'attach' => $m->has_attach ? 1 : 0, 'party' => $m->counterparty?->name,
            ]);

        return Inertia::render('Mail', [
            'accounts' => $accounts,
            'messages' => $messages,
            'imapAvailable' => true,   // чтение через собственный IMAP-клиент, расширение PHP не нужно
        ]);
    }

    public function storeAccount(Request $r)
    {
        $d = $r->validate(['email' => 'required|email', 'password' => 'required|string', 'login' => 'nullable|string']);
        MailAccount::create($this->withDefaults($d));

        return back();
    }

    // Сервер почты выводится из домена (mail.<домен>), SSL 993/143-аналог — как в старой CRM.
    // Пользователь вводит только email (логин) и пароль.
    private function withDefaults(array $d): array
    {
        $domain = substr((string) strrchr($d['email'], '@'), 1);
        $d['login'] = ($d['login'] ?? '') ?: $d['email'];
        $d['imap_host'] = 'mail.'.$domain;
        $d['imap_port'] = 993;
        $d['smtp_host'] = 'mail.'.$domain;
        $d['smtp_port'] = 465;
        $d['use_ssl'] = true;

        return $d;
    }

    public function updateAccount(Request $r, MailAccount $account)
    {
        $d = $r->validate(['email' => 'required|email', 'password' => 'nullable|string', 'login' => 'nullable|string']);
        $hasPassword = ! empty($d['password']);
        $d = $this->withDefaults($d);
        if (! $hasPassword) {
            unset($d['password']);   // пустой пароль — не перезаписывать существующий
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
            'account_id' => 'required|exists:mail_accounts,id',
            'to' => 'required|email', 'subject' => 'nullable|string', 'body' => 'nullable|string',
            'counterparty_id' => 'nullable|exists:counterparties,id',
            'doc_type' => 'nullable|string', 'doc_id' => 'nullable|integer',
        ]);
        $account = MailAccount::findOrFail($d['account_id']);

        // best-effort отправка по SMTP аккаунта
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
            'from_name' => $account->email, 'from_email' => $account->email,
            'subject' => $d['subject'], 'preview' => mb_substr((string) ($d['body'] ?? ''), 0, 120),
            'body' => $d['body'], 'date' => now(), 'is_read' => true,
            'counterparty_id' => $d['counterparty_id'] ?? null,
            'doc_type' => $d['doc_type'] ?? null, 'doc_id' => $d['doc_id'] ?? null,
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

    // Синхронизация INBOX через собственный IMAP-клиент на сокетах (без расширения imap).
    public function sync(MailAccount $account)
    {
        $domain = substr((string) strrchr($account->email, '@'), 1);
        // На шаред-хостинге почта обычно доступна локально; пробуем по очереди, коротким таймаутом.
        $candidates = array_values(array_unique(array_filter([
            $account->imap_host, 'localhost', gethostname() ?: null, 'mail.'.$domain,
        ])));
        $port = $account->imap_port ?: 993;
        $user = $account->login ?: $account->email;

        $client = null;
        $lastError = 'не удалось подключиться';
        foreach ($candidates as $host) {
            try {
                $c = new ImapClient($host, $port, $user, (string) $account->password, (bool) $account->use_ssl, 4);
                $c->connect();
                $c->login();
                $client = $c;
                if ($host !== $account->imap_host) {
                    $account->update(['imap_host' => $host]);   // запомнить рабочий сервер
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
                $msg = $client->fetch($uid);
                [$fromName, $fromEmail, $subject, $date] = $this->parseHeader($msg['header']);
                $body = $this->decodeBody($msg['body']);
                MailMessage::create([
                    'account_id' => $account->id, 'folder' => 'INBOX', 'uid' => (string) $uid,
                    'from_name' => $fromName, 'from_email' => $fromEmail, 'subject' => $subject,
                    'preview' => mb_substr(trim($body), 0, 120), 'body' => $body,
                    'date' => $date, 'is_read' => $msg['seen'],
                ]);
            }
            $client->close();
        } catch (\Throwable $e) {
            $client->close();

            return back()->withErrors(['imap' => 'Почта: '.$e->getMessage()]);
        }

        return back();
    }

    // Разбор заголовков письма: имя/почта отправителя, тема, дата.
    private function parseHeader(string $raw): array
    {
        $raw = (string) preg_replace('/\r?\n[ \t]+/', ' ', $raw); // развернуть сложенные строки
        $from = $subject = $date = '';
        foreach (preg_split('/\r?\n/', $raw) as $line) {
            if (preg_match('/^From:\s*(.+)$/i', $line, $m)) {
                $from = trim($m[1]);
            } elseif (preg_match('/^Subject:\s*(.+)$/i', $line, $m)) {
                $subject = trim($m[1]);
            } elseif (preg_match('/^Date:\s*(.+)$/i', $line, $m)) {
                $date = trim($m[1]);
            }
        }
        $fromName = '';
        $fromEmail = $from;
        if (preg_match('/^(.*)<([^>]+)>/', $from, $m)) {
            $fromName = $this->mime(trim($m[1], " \"'"));
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

    // Декод тела: quoted-printable / base64, приведение к UTF-8, очистка HTML для текста.
    private function decodeBody(string $b): string
    {
        $b = trim($b);
        if ($b === '') {
            return '';
        }
        $out = quoted_printable_decode($b);
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
            $out = trim((string) preg_replace('/[ \t]*\R+[ \t]*/u', "\n", strip_tags($out)));
        }

        return $out;
    }
}
