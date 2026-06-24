<?php
/**
 * Отдаёт sw.js с автоматической версией кэша.
 * Версия = максимальный mtime статики (JS/CSS/index.php): любое изменение файла
 * автоматически инвалидирует кэш у клиентов — ручной бамп APP_VERSION не нужен.
 */
$root  = __DIR__;
$files = array_merge(
    glob($root . '/assets/js/*.js')  ?: [],
    glob($root . '/assets/css/*.css') ?: [],
    [$root . '/index.php', $root . '/sw.js']
);
$v = 0;
foreach ($files as $f) {
    $m = @filemtime($f);
    if ($m) $v = max($v, $m);
}

$src = file_get_contents($root . '/sw.js');
$src = preg_replace("/const APP_VERSION\s*=\s*'[^']*';/", "const APP_VERSION    = 'v{$v}';", $src, 1);

header('Content-Type: application/javascript; charset=UTF-8');
header('Cache-Control: no-cache, must-revalidate');
header('Service-Worker-Allowed: ./');
echo $src;
