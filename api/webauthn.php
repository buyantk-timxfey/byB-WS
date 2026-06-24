<?php
session_start();
require_once '../config.php';

header('Content-Type: application/json');

// ─── Table setup ────────────────────────────────────────────────────────────
$pdo->exec("CREATE TABLE IF NOT EXISTS webauthn_credentials (
    id INT AUTO_INCREMENT PRIMARY KEY,
    credential_id VARCHAR(512) NOT NULL UNIQUE,
    public_key_pem TEXT NOT NULL,
    sign_count INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// ─── Helpers ─────────────────────────────────────────────────────────────────
function b64url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function b64url_decode(string $data): string {
    $pad = strlen($data) % 4;
    if ($pad) $data .= str_repeat('=', 4 - $pad);
    return base64_decode(strtr($data, '-_', '+/'));
}

function err(string $msg, int $code = 400): never {
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}

function get_rp_id(): string {
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    // Strip port if present
    return preg_replace('/:\d+$/', '', $host);
}

// ─── Minimal CBOR parser ─────────────────────────────────────────────────────
function cbor_decode(string $data, int &$offset = 0): mixed {
    if ($offset >= strlen($data)) err('CBOR: unexpected end of data');

    $initial = ord($data[$offset++]);
    $major = ($initial >> 5) & 0x07;
    $info  = $initial & 0x1f;

    $value = cbor_read_length($data, $offset, $info);

    switch ($major) {
        case 0: // unsigned int
            return $value;
        case 1: // negative int
            return -1 - $value;
        case 2: // byte string
            $bytes = substr($data, $offset, $value);
            $offset += $value;
            return $bytes;
        case 3: // text string
            $str = substr($data, $offset, $value);
            $offset += $value;
            return $str;
        case 4: // array
            $arr = [];
            for ($i = 0; $i < $value; $i++) {
                $arr[] = cbor_decode($data, $offset);
            }
            return $arr;
        case 5: // map
            $map = [];
            for ($i = 0; $i < $value; $i++) {
                $k = cbor_decode($data, $offset);
                $v = cbor_decode($data, $offset);
                $map[$k] = $v;
            }
            return $map;
        case 6: // tag — skip tag, decode next item
            return cbor_decode($data, $offset);
        case 7: // float/simple
            if ($info === 20) return false;
            if ($info === 21) return true;
            if ($info === 22) return null;
            if ($info === 25) { // float16
                $offset += 2;
                return 0.0;
            }
            if ($info === 26) { // float32
                $f = unpack('G', substr($data, $offset, 4))[1];
                $offset += 4;
                return $f;
            }
            if ($info === 27) { // float64
                $f = unpack('E', substr($data, $offset, 8))[1];
                $offset += 8;
                return $f;
            }
            return null;
        default:
            err('CBOR: unsupported major type ' . $major);
    }
}

function cbor_read_length(string $data, int &$offset, int $info): int {
    if ($info <= 23) return $info;
    if ($info === 24) { $v = ord($data[$offset++]); return $v; }
    if ($info === 25) {
        $v = unpack('n', substr($data, $offset, 2))[1];
        $offset += 2;
        return $v;
    }
    if ($info === 26) {
        $v = unpack('N', substr($data, $offset, 4))[1];
        $offset += 4;
        return $v;
    }
    if ($info === 27) {
        // 64-bit — read as two 32-bit and combine
        $hi = unpack('N', substr($data, $offset, 4))[1];
        $lo = unpack('N', substr($data, $offset + 4, 4))[1];
        $offset += 8;
        return ($hi << 32) | $lo;
    }
    err('CBOR: indefinite length not supported (info=' . $info . ')');
}

// ─── COSE P-256 key → PEM ────────────────────────────────────────────────────
function cose_to_pem(array $cose): string {
    // COSE keys use integer labels: 1=kty, 3=alg, -1=crv, -2=x, -3=y
    $x = $cose[-2] ?? null;
    $y = $cose[-3] ?? null;
    if (!is_string($x) || !is_string($y) || strlen($x) !== 32 || strlen($y) !== 32) {
        err('Invalid COSE key: missing or malformed x/y coordinates');
    }
    // SubjectPublicKeyInfo DER for EC P-256
    $der = hex2bin('3059')                                          // SEQUENCE (89 bytes)
         . hex2bin('301306072a8648ce3d020106082a8648ce3d030107')    // AlgorithmIdentifier
         . "\x03\x42\x00\x04"                                       // BIT STRING, uncompressed point
         . $x . $y;
    return "-----BEGIN PUBLIC KEY-----\n"
         . chunk_split(base64_encode($der), 64, "\n")
         . "-----END PUBLIC KEY-----\n";
}

// ─── Parse authData ──────────────────────────────────────────────────────────
function parse_auth_data(string $authData): array {
    if (strlen($authData) < 37) err('authData too short');
    $offset = 0;
    $rpIdHash = substr($authData, $offset, 32); $offset += 32;
    $flags    = ord($authData[$offset]);         $offset += 1;
    $signCount = unpack('N', substr($authData, $offset, 4))[1]; $offset += 4;

    $attCredData = null;
    $coseKey     = null;
    $credentialId = null;

    // AT flag (bit 6) indicates attested credential data present
    if ($flags & 0x40) {
        if (strlen($authData) < $offset + 18) err('authData: attested credential data truncated');
        // aaguid (16 bytes)
        $offset += 16;
        $credIdLen = unpack('n', substr($authData, $offset, 2))[1]; $offset += 2;
        $credentialId = substr($authData, $offset, $credIdLen); $offset += $credIdLen;
        // COSE key is the rest
        $coseBytes = substr($authData, $offset);
        $pos = 0;
        $coseKey = cbor_decode($coseBytes, $pos);
    }

    return [
        'rpIdHash'     => $rpIdHash,
        'flags'        => $flags,
        'signCount'    => $signCount,
        'credentialId' => $credentialId,
        'coseKey'      => $coseKey,
    ];
}

// ─── Router ──────────────────────────────────────────────────────────────────
$action = $_GET['action'] ?? '';

switch ($action) {

    // ── register_begin ───────────────────────────────────────────────────────
    case 'register_begin': {
        if (empty($_SESSION['auth'])) err('Not authenticated', 401);

        $challenge = random_bytes(32);
        $_SESSION['wn_challenge'] = b64url_encode($challenge);

        echo json_encode([
            'challenge'  => $_SESSION['wn_challenge'],
            'rp_id'      => get_rp_id(),
            'user_id'    => 'ceo',
            'user_name'  => 'byBuka CEO',
        ]);
        break;
    }

    // ── register_complete ────────────────────────────────────────────────────
    case 'register_complete': {
        if (empty($_SESSION['auth'])) err('Not authenticated', 401);

        $body = json_decode(file_get_contents('php://input'), true);
        if (!$body) err('Invalid JSON body');

        $clientDataJSON   = b64url_decode($body['clientDataJSON']   ?? '');
        $attestationObject = b64url_decode($body['attestationObject'] ?? '');

        if (!$clientDataJSON || !$attestationObject) err('Missing clientDataJSON or attestationObject');

        // Verify clientDataJSON
        $clientData = json_decode($clientDataJSON, true);
        if (!$clientData) err('Cannot parse clientDataJSON');
        if (($clientData['type'] ?? '') !== 'webauthn.create') err('Wrong clientData type');

        // Verify challenge
        $storedChallenge = $_SESSION['wn_challenge'] ?? '';
        $receivedChallenge = rtrim($clientData['challenge'] ?? '', '=');
        $storedChallengeTrimmed = rtrim($storedChallenge, '=');
        if (!hash_equals($storedChallengeTrimmed, $receivedChallengeTrimmed)) {
            err('Challenge mismatch');
        }

        // Parse attestationObject (CBOR)
        $offset = 0;
        $attObj = cbor_decode($attestationObject, $offset);
        if (!is_array($attObj) || !isset($attObj['authData'])) err('Invalid attestationObject');

        $authData = $attObj['authData'];
        $parsed = parse_auth_data($authData);

        if (!$parsed['credentialId']) err('No credential ID in authData');
        if (!$parsed['coseKey'])      err('No COSE key in authData');

        $credentialIdB64 = b64url_encode($parsed['credentialId']);
        $pem = cose_to_pem($parsed['coseKey']);

        // Store in DB (replace if same credential_id)
        $stmt = $pdo->prepare("INSERT INTO webauthn_credentials (credential_id, public_key_pem, sign_count)
                               VALUES (?, ?, ?)
                               ON DUPLICATE KEY UPDATE public_key_pem=VALUES(public_key_pem), sign_count=VALUES(sign_count)");
        $stmt->execute([$credentialIdB64, $pem, $parsed['signCount']]);

        unset($_SESSION['wn_challenge']);
        echo json_encode(['ok' => true]);
        break;
    }

    // ── auth_begin ───────────────────────────────────────────────────────────
    case 'auth_begin': {
        // Check if credential exists
        $stmt = $pdo->query("SELECT credential_id FROM webauthn_credentials LIMIT 1");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            echo json_encode(['registered' => false]);
            break;
        }

        $challenge = random_bytes(32);
        $_SESSION['wn_challenge'] = b64url_encode($challenge);

        $credentialId = $row['credential_id'];

        echo json_encode([
            'challenge'     => $_SESSION['wn_challenge'],
            'rp_id'         => get_rp_id(),
            'credential_id' => $credentialId,
            'allow_credentials' => [[
                'type' => 'public-key',
                'id'   => $credentialId,
            ]],
        ]);
        break;
    }

    // ── auth_complete ────────────────────────────────────────────────────────
    case 'auth_complete': {
        $body = json_decode(file_get_contents('php://input'), true);
        if (!$body) err('Invalid JSON body');

        $credentialIdB64   = $body['credentialId']      ?? '';
        $clientDataJSON    = b64url_decode($body['clientDataJSON']    ?? '');
        $authenticatorData = b64url_decode($body['authenticatorData'] ?? '');
        $signature         = b64url_decode($body['signature']         ?? '');

        if (!$clientDataJSON || !$authenticatorData || !$signature) {
            err('Missing required fields');
        }

        // Verify clientDataJSON type
        $clientData = json_decode($clientDataJSON, true);
        if (!$clientData) err('Cannot parse clientDataJSON');
        if (($clientData['type'] ?? '') !== 'webauthn.get') err('Wrong clientData type');

        // Verify challenge
        $storedChallenge = $_SESSION['wn_challenge'] ?? '';
        if (!$storedChallenge) err('No challenge in session');
        $receivedChallenge = rtrim($clientData['challenge'] ?? '', '=');
        $storedChallengeTrimmed = rtrim($storedChallenge, '=');
        if (!hash_equals($storedChallengeTrimmed, $receivedChallengeTrimmed)) {
            err('Challenge mismatch');
        }

        // Verify rpIdHash
        $rpId = get_rp_id();
        $expectedRpIdHash = hash('sha256', $rpId, true);
        $actualRpIdHash   = substr($authenticatorData, 0, 32);
        if (!hash_equals($expectedRpIdHash, $actualRpIdHash)) {
            err('RP ID hash mismatch');
        }

        // Load credential from DB
        $stmt = $pdo->prepare("SELECT * FROM webauthn_credentials WHERE credential_id = ? LIMIT 1");
        $stmt->execute([$credentialIdB64]);
        $cred = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$cred) err('Unknown credential');

        // Verify signature
        $clientDataHash = hash('sha256', $clientDataJSON, true);
        $signedData     = $authenticatorData . $clientDataHash;

        $pubKey = openssl_pkey_get_public($cred['public_key_pem']);
        if (!$pubKey) err('Cannot load public key');

        $result = openssl_verify($signedData, $signature, $pubKey, OPENSSL_ALGO_SHA256);
        if ($result !== 1) err('Signature verification failed');

        // Update sign_count
        $newSignCount = unpack('N', substr($authenticatorData, 33, 4))[1];
        $pdo->prepare("UPDATE webauthn_credentials SET sign_count = ? WHERE credential_id = ?")
            ->execute([$newSignCount, $credentialIdB64]);

        // Set session
        unset($_SESSION['wn_challenge']);
        $_SESSION['auth'] = true;

        // Запоминаем устройство — чтобы дальше пускал по PIN
        if (function_exists('issueDeviceToken')) issueDeviceToken($pdo);

        echo json_encode(['ok' => true]);
        break;
    }

    default:
        err('Unknown action');
}
