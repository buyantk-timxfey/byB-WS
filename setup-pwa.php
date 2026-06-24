<?php
/**
 * setup-pwa.php — одноразовый скрипт настройки PWA
 *
 * 1. Генерирует иконки всех размеров из assets/img/icon-src.png
 * 2. Генерирует VAPID-ключи для Web Push
 *
 * УДАЛИ ЭТОТ ФАЙЛ ПОСЛЕ ЗАПУСКА!
 * Запуск: https://yourdomain.com/setup-pwa.php?key=REPLACE_WITH_SECRET_KEY
 */

define('SETUP_KEY', 'REPLACE_WITH_SECRET_KEY');

if (($_GET['key'] ?? '') !== SETUP_KEY) {
    http_response_code(403);
    die('Forbidden');
}

$results = [];
$errors  = [];

// ─── 1. Генерация иконок ──────────────────────────────────────────────────────

$srcPath = __DIR__ . '/assets/img/icon-src.png';

if (!file_exists($srcPath)) {
    $errors[] = 'Файл icon-src.png не найден. Загрузи логотип как assets/img/icon-src.png';
} elseif (!function_exists('imagecreatefrompng')) {
    $errors[] = 'PHP GD не установлен';
} else {
    $src = imagecreatefrompng($srcPath);
    if (!$src) {
        // Попробуем как JPEG или другой формат
        $src = imagecreatefromstring(file_get_contents($srcPath));
    }

    if ($src) {
        $sizes = [72, 96, 128, 144, 152, 180, 192, 512];

        foreach ($sizes as $size) {
            $dest     = imagecreatetruecolor($size, $size);
            $black    = imagecolorallocate($dest, 13, 13, 13); // #0D0D0D
            imagefill($dest, 0, 0, $black);
            imagealphablending($dest, true);
            imagesavealpha($dest, true);
            imagecopyresampled($dest, $src, 0, 0, 0, 0, $size, $size, imagesx($src), imagesy($src));

            $outPath = __DIR__ . "/assets/img/icon-{$size}.png";
            if (imagepng($dest, $outPath, 9)) {
                $results[] = "✓ icon-{$size}.png создан";
            } else {
                $errors[] = "✗ Не удалось создать icon-{$size}.png (права на запись?)";
            }
            imagedestroy($dest);
        }

        // Maskable icon — логотип с отступом 20% (safe zone)
        $maskSize   = 512;
        $padding    = (int)($maskSize * 0.2);
        $logoSize   = $maskSize - ($padding * 2);
        $maskable   = imagecreatetruecolor($maskSize, $maskSize);
        $black      = imagecolorallocate($maskable, 13, 13, 13);
        imagefill($maskable, 0, 0, $black);
        imagecopyresampled($maskable, $src, $padding, $padding, 0, 0, $logoSize, $logoSize, imagesx($src), imagesy($src));
        $maskPath   = __DIR__ . '/assets/img/icon-maskable-512.png';
        if (imagepng($maskable, $maskPath, 9)) {
            $results[] = '✓ icon-maskable-512.png создан (с отступом для Android)';
        } else {
            $errors[] = '✗ Не удалось создать icon-maskable-512.png';
        }
        imagedestroy($maskable);
        imagedestroy($src);
    } else {
        $errors[] = 'Не удалось открыть icon-src.png — убедись что файл корректный PNG/JPG';
    }
}

// ─── 2. Генерация VAPID-ключей ────────────────────────────────────────────────

$vapidResults = [];

if (!function_exists('openssl_pkey_new')) {
    $errors[] = 'OpenSSL недоступен — VAPID ключи не сгенерированы';
} else {
    try {
        // Генерируем EC ключевую пару (P-256)
        $key = openssl_pkey_new([
            'curve_name'       => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);

        if (!$key) {
            throw new Exception('Не удалось создать EC ключ');
        }

        $details = openssl_pkey_get_details($key);
        openssl_pkey_export($key, $privatePem);

        // Извлекаем raw ключи из DER
        $pubKeyDer  = $details['key'];
        $privKeyDer = '';
        openssl_pkey_export($key, $privKeyPem);

        // Парсим приватный ключ (SEC1 формат)
        $privDer = '';
        $lines   = explode("\n", trim($privKeyPem));
        array_shift($lines); array_pop($lines);
        $privDer = base64_decode(implode('', $lines));

        // Публичный ключ — uncompressed point (04 || x || y), 65 байт
        $pubPoint = $details['ec']['x'] . $details['ec']['y'];
        $pubPoint = "\x04" . str_pad($details['ec']['x'], 32, "\x00", STR_PAD_LEFT)
                           . str_pad($details['ec']['y'], 32, "\x00", STR_PAD_LEFT);

        // Приватный ключ — d, 32 байта
        $privPoint = str_pad($details['ec']['d'], 32, "\x00", STR_PAD_LEFT);

        $vapidPublic  = rtrim(strtr(base64_encode($pubPoint),  '+/', '-_'), '=');
        $vapidPrivate = rtrim(strtr(base64_encode($privPoint), '+/', '-_'), '=');

        // Сохраняем в файл конфига
        $vapidConfig = __DIR__ . '/vapid.php';
        $vapidContent = "<?php\n// VAPID ключи для Web Push — НЕ КОММИТЬ В GIT!\n"
            . "define('VAPID_PUBLIC_KEY',  '{$vapidPublic}');\n"
            . "define('VAPID_PRIVATE_KEY', '{$vapidPrivate}');\n"
            . "define('VAPID_SUBJECT',     'mailto:admin@example.com');\n";

        file_put_contents($vapidConfig, $vapidContent);

        $vapidResults = [
            'public'  => $vapidPublic,
            'private' => '***скрыт*** (сохранён в vapid.php)',
        ];

        $results[] = '✓ VAPID ключи сгенерированы и сохранены в vapid.php';
        $results[] = '⚠ Добавь vapid.php в .gitignore!';

    } catch (Exception $e) {
        $errors[] = 'Ошибка генерации VAPID: ' . $e->getMessage();
    }
}

// ─── Вывод ────────────────────────────────────────────────────────────────────
?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<title>PWA Setup</title>
<style>
  body { font-family: monospace; background: #0D0D0D; color: #F5F5F5; padding: 32px; max-width: 700px; }
  h1   { color: #C9A96E; margin-bottom: 24px; }
  h2   { color: #888; font-size: 14px; text-transform: uppercase; letter-spacing: 1px; margin-top: 24px; }
  .ok  { color: #4CAF50; margin: 4px 0; }
  .err { color: #F44336; margin: 4px 0; }
  .key { background: #1A1A1A; padding: 12px 16px; border-radius: 8px; word-break: break-all;
         border: 1px solid #2A2A2A; margin: 8px 0; font-size: 12px; color: #C9A96E; }
  .warn { color: #FF9800; background: rgba(255,152,0,0.1); border: 1px solid rgba(255,152,0,0.3);
          padding: 12px 16px; border-radius: 8px; margin-top: 24px; }
</style>
</head>
<body>
<h1>🚀 PWA Setup</h1>

<h2>Результаты</h2>
<?php foreach ($results as $r): ?>
    <div class="ok"><?= htmlspecialchars($r) ?></div>
<?php endforeach; ?>
<?php foreach ($errors as $e): ?>
    <div class="err"><?= htmlspecialchars($e) ?></div>
<?php endforeach; ?>

<?php if (!empty($vapidResults)): ?>
<h2>VAPID Public Key (вставь в pwa.js)</h2>
<div class="key"><?= htmlspecialchars($vapidResults['public']) ?></div>
<?php endif; ?>

<div class="warn">
    ⚠️ <strong>УДАЛИ setup-pwa.php после использования!</strong><br>
    Также добавь в .gitignore строку: <code>vapid.php</code>
</div>
</body>
</html>
