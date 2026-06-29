<?php

namespace App\Http\Controllers;

use App\Models\MailAccount;
use App\Models\MailMessage;
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
            'imapAvailable' => function_exists('imap_open'),
        ]);
    }

    public function storeAccount(Request $r)
    {
        $d = $r->validate([
            'email' => 'required|email', 'imap_host' => 'nullable|string', 'imap_port' => 'nullable|integer',
            'smtp_host' => 'nullable|string', 'smtp_port' => 'nullable|integer',
            'login' => 'nullable|string', 'password' => 'nullable|string', 'use_ssl' => 'boolean',
        ]);
        MailAccount::create($d);

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

    // Синхронизация INBOX по IMAP (если расширение доступно на сервере)
    public function sync(MailAccount $account)
    {
        if (! function_exists('imap_open') || ! $account->imap_host) {
            return back()->withErrors(['imap' => 'IMAP недоступен на сервере или не настроен.']);
        }
        $mbox = '{'.$account->imap_host.':'.($account->imap_port ?: 993).'/imap'.($account->use_ssl ? '/ssl' : '').'}INBOX';
        $imap = @imap_open($mbox, $account->login ?: $account->email, $account->password);
        if (! $imap) {
            return back()->withErrors(['imap' => 'Не удалось подключиться к IMAP.']);
        }
        $ids = imap_search($imap, 'ALL') ?: [];
        $ids = array_slice(array_reverse($ids), 0, 30);
        foreach ($ids as $num) {
            $o = imap_headerinfo($imap, $num);
            $uid = (string) imap_uid($imap, $num);
            if (MailMessage::where('account_id', $account->id)->where('uid', $uid)->exists()) {
                continue;
            }
            $body = imap_fetchbody($imap, $num, '1');
            MailMessage::create([
                'account_id' => $account->id, 'folder' => 'INBOX', 'uid' => $uid,
                'from_name' => isset($o->from[0]->personal) ? imap_utf8($o->from[0]->personal) : null,
                'from_email' => ($o->from[0]->mailbox ?? '').'@'.($o->from[0]->host ?? ''),
                'subject' => isset($o->subject) ? imap_utf8($o->subject) : null,
                'preview' => mb_substr(trim((string) $body), 0, 120),
                'body' => $body, 'date' => isset($o->date) ? date('Y-m-d H:i:s', strtotime($o->date)) : now(),
                'is_read' => ($o->Unseen ?? 'U') !== 'U',
            ]);
        }
        imap_close($imap);

        return back();
    }
}
