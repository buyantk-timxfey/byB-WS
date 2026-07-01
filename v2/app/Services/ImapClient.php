<?php

namespace App\Services;

use RuntimeException;

// Минимальный IMAP-клиент на сокетах — без PHP-расширения imap.
// Умеет: подключиться по SSL, залогиниться, выбрать INBOX, отдать последние письма.
class ImapClient
{
    /** @var resource|null */
    private $sock = null;
    private int $tag = 0;

    public function __construct(
        private string $host,
        private int $port,
        private string $user,
        private string $pass,
        private bool $ssl = true,
        private int $timeout = 6,
    ) {}

    public function connect(): void
    {
        $ctx = stream_context_create(['ssl' => [
            'verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true,
        ]]);
        $proto = $this->ssl ? 'ssl' : 'tcp';
        $sock = @stream_socket_client(
            $proto.'://'.$this->host.':'.$this->port, $errno, $errstr, $this->timeout,
            STREAM_CLIENT_CONNECT, $ctx
        );
        if (! $sock) {
            throw new RuntimeException('подключение не удалось ('.($errstr ?: 'нет ответа').')');
        }
        $this->sock = $sock;
        stream_set_timeout($this->sock, $this->timeout);
        fgets($this->sock); // приветствие сервера
    }

    public function login(): void
    {
        $this->command('LOGIN '.$this->quote($this->user).' '.$this->quote($this->pass));
    }

    public function selectInbox(): void
    {
        $this->command('SELECT INBOX');
    }

    public function select(string $folder): void
    {
        // IMAP требует modified UTF-7 для имён папок с не-ASCII символами
        if (preg_match('/[^\x00-\x7F]/', $folder)) {
            $folder = (string) mb_convert_encoding($folder, 'UTF7-IMAP', 'UTF-8');
        }
        $this->command('SELECT '.$this->quote($folder));
    }

    /** Список имён папок с сервера (LIST "" "*"). */
    public function listFolders(): array
    {
        $resp    = $this->command('LIST "" "*"');
        $folders = [];
        foreach (preg_split('/\r?\n/', $resp) as $line) {
            // Формат: * LIST (\flags) "/" folderName   или   * LIST () NIL folderName
            if (preg_match('/^\* LIST\s+\([^)]*\)\s+(?:"[^"]*"|NIL)\s+(.+)$/i', $line, $m)) {
                $name = trim($m[1], "\" \t\r\n");
                if ($name !== '') {
                    $folders[] = $name;
                }
            }
        }

        return $folders;
    }

    /** Последние $limit UID входящих. */
    public function recentUids(int $limit): array
    {
        $resp = $this->command('UID SEARCH ALL');
        preg_match('/\* SEARCH([0-9 ]*)/', $resp, $m);
        $uids = array_values(array_filter(array_map('intval', preg_split('/\s+/', trim($m[1] ?? '')))));
        sort($uids);

        return array_slice($uids, -$limit);
    }

    /** Заголовки (From/Subject/Date), тело и флаг прочтения по UID.
     *  Тянет несколько BODY-частей за один запрос, чтобы корректно обрабатывать
     *  вложенные multipart (где BODY[1] содержит сырые MIME-разделители). */
    public function fetch(int $uid): array
    {
        $resp = $this->command(
            "UID FETCH $uid (FLAGS BODY.PEEK[HEADER.FIELDS (FROM SUBJECT DATE)] BODY.PEEK[1] BODY.PEEK[1.1] BODY.PEEK[1.2] BODY.PEEK[2])"
        );
        $header = '';
        $parts  = [];
        foreach ($this->extractLiterals($resp) as $lit) {
            if (stripos($lit['key'], '[HEADER') !== false) {
                $header = $lit['value'];
            } elseif (preg_match('/BODY(?:\.PEEK)?\[([^\]]+)\]/i', $lit['key'], $m)) {
                $parts[$m[1]] = $lit['value'];
            }
        }
        // Порядок: HTML-часть вложенного multipart (1.2) предпочтительна — у многих
        // отправителей авто-сгенерированная plain-text альтернатива (1.1) склеена без
        // переносов строк, тогда как их же HTML-версия структурирована тегами (div/p/br),
        // которые decodeBody() превращает в переносы. Плоские части (1) — для не-multipart
        // писем. Вложения (PDF/PNG, часто part 2) в конце и обнаруживаются как бинарные
        // в decodeBody, что возвращает пустую строку.
        $body = '';
        foreach (['1.2', '1.1', '1', '2'] as $part) {
            $candidate = $parts[$part] ?? '';
            if ($candidate !== '' && ! preg_match('/^-{4,}[A-Za-z0-9]/m', $candidate)) {
                $body = $candidate;
                break;
            }
        }
        $seen = (bool) preg_match('/FLAGS \([^)]*\\\\Seen/i', $resp);

        return ['header' => $header, 'body' => $body, 'seen' => $seen];
    }

    public function close(): void
    {
        if ($this->sock) {
            @fwrite($this->sock, 'Z LOGOUT'."\r\n");
            @fclose($this->sock);
            $this->sock = null;
        }
    }

    private function command(string $cmd): string
    {
        $tag = 'A'.(++$this->tag);
        fwrite($this->sock, $tag.' '.$cmd."\r\n");

        return $this->readResponse($tag);
    }

    // Читает ответ до строки «<tag> OK/NO/BAD», корректно поглощая литералы {N}.
    private function readResponse(string $tag): string
    {
        $data = '';
        while ($this->sock && ! feof($this->sock)) {
            $line = fgets($this->sock);
            if ($line === false) {
                break;
            }
            $data .= $line;
            if (preg_match('/\{(\d+)\}\r\n$/', $line, $m)) {
                $need = (int) $m[1];
                $got = 0;
                while ($got < $need && ! feof($this->sock)) {
                    $chunk = fread($this->sock, $need - $got);
                    if ($chunk === '' || $chunk === false) {
                        break;
                    }
                    $data .= $chunk;
                    $got += strlen($chunk);
                }

                continue;
            }
            if (preg_match('/^'.preg_quote($tag, '/').' (OK|NO|BAD)\b/i', $line, $m)) {
                if (strtoupper($m[1]) !== 'OK') {
                    throw new RuntimeException(trim((string) preg_replace('/^\S+\s+\w+\s+/', '', $line)));
                }
                break;
            }
        }

        return $data;
    }

    // Достаёт все литералы ответа вместе с предшествующим ключом (BODY[...]).
    private function extractLiterals(string $data): array
    {
        $out = [];
        $pos = 0;
        while (($bp = strpos($data, '{', $pos)) !== false) {
            $end = strpos($data, "}\r\n", $bp);
            if ($end === false) {
                break;
            }
            $numStr = substr($data, $bp + 1, $end - $bp - 1);
            if (! ctype_digit($numStr)) {
                $pos = $bp + 1;

                continue;
            }
            $n = (int) $numStr;
            $valStart = $end + 3;
            $value = substr($data, $valStart, $n);
            $nl = strrpos(substr($data, 0, $bp), "\n");
            $keyFrom = $nl === false ? 0 : $nl + 1;
            $key = trim(substr($data, $keyFrom, $bp - $keyFrom));
            $out[] = ['key' => $key, 'value' => $value];
            $pos = $valStart + $n;
        }

        return $out;
    }

    private function quote(string $s): string
    {
        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $s).'"';
    }
}
