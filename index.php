<?php
session_start();
if (!isset($_SESSION['auth']) || $_SESSION['auth'] !== true) {
    header('Location: login.php');
    exit;
}
require_once 'config.php';

$page = isset($_GET['page']) ? $_GET['page'] : 'dashboard';
$allowed_pages = ['dashboard', 'shipments', 'warehouse', 'counterparties', 'carriers', 'finances', 'sales', 'references', 'mail', 'deals', 'vehicle'];
if (!in_array($page, $allowed_pages)) $page = 'dashboard';

// Версия ассетов = макс. mtime JS/CSS — для cache-busting (?v=) в тегах ниже
$ASSET_V = 0;
foreach (array_merge(glob(__DIR__.'/assets/js/*.js') ?: [], glob(__DIR__.'/assets/css/*.css') ?: []) as $__f) {
    $__m = @filemtime($__f); if ($__m > $ASSET_V) $ASSET_V = $__m;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>byBuka — Workspace</title>

    <!-- Аварийный сброс зависшего Service Worker. Грузится по сети (навигация =
         network-first), поэтому доходит до браузера мимо старого кэша. Сносит
         зависший SW и кэш один раз (по флагу), после чего страница берёт всё
         свежее. Бамп версии флага (-vN) повторит сброс у всех клиентов. -->
    <script>
    (function () {
        try {
            if (!('serviceWorker' in navigator)) return;
            if (localStorage.getItem('byb-sw-reset-v1')) return;
            var clearCaches = window.caches
                ? caches.keys().then(function (ks) { return Promise.all(ks.map(function (k) { return caches.delete(k); })); })
                : Promise.resolve();
            var unreg = navigator.serviceWorker.getRegistrations()
                .then(function (rs) { return Promise.all(rs.map(function (r) { return r.unregister(); })); });
            Promise.all([unreg, clearCaches]).catch(function () {}).then(function () {
                localStorage.setItem('byb-sw-reset-v1', '1');
                location.reload();
            });
        } catch (e) {}
    })();
    </script>

    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#0C0C0E" media="(prefers-color-scheme: dark)">
    <meta name="theme-color" content="#F5F5F5" media="(prefers-color-scheme: light)">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="byBuka">
    <link rel="apple-touch-icon" href="assets/img/icon-180.png">
    <link rel="apple-touch-icon" sizes="152x152" href="assets/img/icon-152.png">
    <link rel="icon" type="image/png" sizes="192x192" href="assets/img/icon-192.png">
    <link rel="icon" type="image/x-icon" href="assets/img/favicon.ico">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <!-- Styles (mobile first so desktop overrides) -->
    <link rel="stylesheet" href="assets/css/main.css?v=<?= $ASSET_V ?>">
    <link rel="stylesheet" href="assets/css/sidebar.css?v=<?= $ASSET_V ?>">
    <link rel="stylesheet" href="assets/css/tables.css?v=<?= $ASSET_V ?>">
    <link rel="stylesheet" href="assets/css/modals.css?v=<?= $ASSET_V ?>">
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?= $ASSET_V ?>">
    <link rel="stylesheet" href="assets/css/search.css?v=<?= $ASSET_V ?>">
    <link rel="stylesheet" href="assets/css/chain.css?v=<?= $ASSET_V ?>">
    <link rel="stylesheet" href="assets/css/notifications.css?v=<?= $ASSET_V ?>">
    <link rel="stylesheet" href="assets/css/pwa.css?v=<?= $ASSET_V ?>">
    <link rel="stylesheet" href="assets/css/mobile.css?v=<?= $ASSET_V ?>">
    <link rel="stylesheet" href="assets/css/animations.css?v=<?= $ASSET_V ?>">
    <link rel="stylesheet" href="assets/css/mail.css?v=<?= $ASSET_V ?>">
    <link rel="stylesheet" href="assets/css/deals.css?v=<?= $ASSET_V ?>">
    <link rel="stylesheet" href="assets/css/vehicle.css?v=<?= $ASSET_V ?>">

    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>

</head>
<body>
    <div class="layout">

        <!-- SIDEBAR -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-logo">
                <img src="assets/img/logo1.png" alt="by B" class="sidebar-logo-img">
            </div>
            <nav class="sidebar-nav">
                <a href="?page=dashboard" class="nav-item <?= $page === 'dashboard' ? 'active' : '' ?>">
                    <i data-lucide="layout-dashboard" class="nav-icon"></i>
                    <span class="nav-label">Дашборд</span>
                </a>
                <a href="?page=deals" class="nav-item <?= $page === 'deals' ? 'active' : '' ?>">
                    <i data-lucide="handshake" class="nav-icon"></i>
                    <span class="nav-label">Сделки</span>
                </a>
                <a href="?page=shipments" class="nav-item <?= $page === 'shipments' ? 'active' : '' ?>">
                    <i data-lucide="package" class="nav-icon"></i>
                    <span class="nav-label">Поставки</span>
                </a>
                <a href="?page=sales" class="nav-item <?= $page === 'sales' ? 'active' : '' ?>">
                    <i data-lucide="shopping-bag" class="nav-icon"></i>
                    <span class="nav-label">Продажи</span>
                </a>
                <a href="?page=warehouse" class="nav-item <?= $page === 'warehouse' ? 'active' : '' ?>">
                    <i data-lucide="warehouse" class="nav-icon"></i>
                    <span class="nav-label">Склад</span>
                </a>
                <a href="?page=finances" class="nav-item <?= $page === 'finances' ? 'active' : '' ?>">
                    <i data-lucide="bar-chart-2" class="nav-icon"></i>
                    <span class="nav-label">P&amp;L</span>
                </a>
                <a href="?page=mail" class="nav-item <?= $page === 'mail' ? 'active' : '' ?>">
                    <i data-lucide="mail" class="nav-icon"></i>
                    <span class="nav-label">Почта</span>
                </a>
                <a href="?page=vehicle" class="nav-item <?= $page === 'vehicle' ? 'active' : '' ?>">
                    <i data-lucide="car" class="nav-icon"></i>
                    <span class="nav-label">Маршруты</span>
                </a>

            </nav>
            <div class="sidebar-bottom">
                <a href="?page=references" class="nav-item <?= $page === 'references' ? 'active' : '' ?>">
                    <i data-lucide="book-open" class="nav-icon"></i>
                    <span class="nav-label">Справочник</span>
                </a>
                <div id="notif-bell-nav" class="nav-item nav-item-bell" onclick="toggleNotifDropdown()" style="cursor:pointer">
                    <i data-lucide="bell" class="nav-icon"></i>
                    <span class="nav-label">Уведомления</span>
                    <span id="notif-badge" class="nav-bell-badge"></span>
                </div>
<a href="logout.php" class="sidebar-logout">
                    <i data-lucide="log-out" class="nav-icon"></i>
                    <span class="nav-label">Выйти</span>
                </a>
            </div>
        </aside>

        <!-- MAIN -->
        <main class="main-content">
<?php
// Глобальный фильтр периода:
//  - по умолчанию (нет параметров) — текущий месяц
//  - ?period=all — все периоды (без фильтра)
//  - ?month=&year= — конкретный месяц
//  - P&L (finances): по умолчанию «все периоды», т.к. вкладка «Итоги» — всегда за всё время.
//    Явный выбор месяца/года/периода в фильтре по-прежнему работает.
$hasExplicitPeriod = isset($_GET['period']) || isset($_GET['month']) || isset($_GET['year']);
$filterAll    = (($_GET['period'] ?? '') === 'all')
             || ($page === 'finances' && !$hasExplicitPeriod);
$currentMonth = $filterAll ? null : (isset($_GET['month']) ? intval($_GET['month']) : intval(date('n')));
$currentYear  = $filterAll ? null : (isset($_GET['year'])  ? intval($_GET['year'])  : intval(date('Y')));
$filterActive = !$filterAll;   // фильтруем всегда, кроме явного «Все периоды»
$monthNames   = ['Январь','Февраль','Март','Апрель','Май','Июнь','Июль','Август','Сентябрь','Октябрь','Ноябрь','Декабрь'];
$years        = range(date('Y'), 2026);
// Значения для выпадашек фильтра (даже в режиме «Все периоды» показываем текущие)
$selMonth     = $currentMonth ?? intval(date('n'));
$selYear      = $currentYear  ?? intval(date('Y'));

/**
 * Условие фильтра периода для столбца-даты. Возвращает [sql, params].
 * $dateColumn — доверенный литерал из нашего кода (не пользовательский ввод).
 */
function periodFilter(string $dateColumn): array {
    global $filterActive, $currentMonth, $currentYear;
    if (!$filterActive) return ['1=1', []];
    return ["MONTH($dateColumn) = ? AND YEAR($dateColumn) = ?", [$currentMonth, $currentYear]];
}
?>
            <?php require_once "pages/{$page}.php"; ?>
        </main>
    </div>

    <!-- MODALS -->
    <div id="overlay" class="overlay"></div>
    <div id="drawer"  class="drawer"></div>
    <div id="modal"   class="modal"></div>

    <!-- Hidden bell (JS compatibility) -->
    <div id="notif-bell" style="display:none"></div>
    <div id="notif-dropdown" class="notif-dropdown"></div>

    <!-- GLOBAL SEARCH BAR (рендерится JS-ом) -->
    <div id="global-searchbar" style="display:none">
        <div id="gs-page-actions" style="display:flex;align-items:center;gap:8px"></div>
        <div id="gs-search-controls" style="display:flex;align-items:center;gap:8px"></div>
    </div>
    <div id="gs-dropdown"></div>

    <script>
    window._gsData = {
        months:    <?= json_encode(array_values($monthNames), JSON_UNESCAPED_UNICODE) ?>,
        years:     <?= json_encode($years) ?>,
        selMonth:  <?= (int)$selMonth ?>,
        selYear:   <?= (int)$selYear ?>,
        filterAll: <?= $filterAll ? 'true' : 'false' ?>
    };
    </script>

    <!-- MOBILE BOTTOM NAV -->
    <nav class="bottom-nav" id="bottom-nav">
        <a href="?page=dashboard"  class="bottom-nav-item <?= $page==='dashboard'  ?'active':'' ?>"><i data-lucide="layout-dashboard" style="width:20px;height:20px"></i><span>Главная</span></a>
        <a href="?page=shipments"  class="bottom-nav-item <?= $page==='shipments'  ?'active':'' ?>"><i data-lucide="package"          style="width:20px;height:20px"></i><span>Поставки</span></a>
        <a href="?page=sales"      class="bottom-nav-item <?= $page==='sales'      ?'active':'' ?>"><i data-lucide="shopping-bag"      style="width:20px;height:20px"></i><span>Продажи</span></a>
        <a href="?page=warehouse"  class="bottom-nav-item <?= $page==='warehouse'  ?'active':'' ?>"><i data-lucide="warehouse"         style="width:20px;height:20px"></i><span>Склад</span></a>
        <button type="button" class="bottom-nav-item bottom-nav-bell" id="bottom-nav-bell-btn" onclick="toggleNotifDropdown()">
            <span class="bottom-nav-bell-wrap">
                <i data-lucide="bell" style="width:20px;height:20px"></i>
                <span id="notif-badge-nav" class="bottom-nav-badge"></span>
            </span>
        </button>
        <button class="bottom-nav-item <?= in_array($page,['finances','mail','references']) ?'active':'' ?>" onclick="toggleMoreMenu()" id="more-nav-btn">
            <i data-lucide="grid-2x2" style="width:20px;height:20px"></i><span>Ещё</span>
        </button>
    </nav>

    <!-- MORE MENU -->
    <div class="more-menu-overlay" id="more-menu-overlay" onclick="toggleMoreMenu()"></div>
    <div class="more-menu-sheet" id="more-menu-sheet">
        <div class="more-menu-handle"></div>
        <div class="more-menu-grid">
            <a href="?page=finances"   class="more-menu-item <?= $page==='finances'   ?'active':'' ?>" onclick="closeMoreMenu()">
                <i data-lucide="bar-chart-2"  style="width:22px;height:22px"></i><span>P&amp;L</span>
            </a>
            <a href="?page=mail"       class="more-menu-item <?= $page==='mail'       ?'active':'' ?>" onclick="closeMoreMenu()">
                <i data-lucide="mail"         style="width:22px;height:22px"></i><span>Почта</span>
            </a>
            <a href="?page=vehicle"    class="more-menu-item <?= $page==='vehicle'    ?'active':'' ?>" onclick="closeMoreMenu()">
                <i data-lucide="car"          style="width:22px;height:22px"></i><span>Маршруты</span>
            </a>
            <a href="?page=deals" class="more-menu-item <?= $page==='deals' ?'active':'' ?>" onclick="closeMoreMenu()">
                <i data-lucide="handshake"    style="width:22px;height:22px"></i><span>Сделки</span>
            </a>
            <a href="?page=references" class="more-menu-item <?= $page==='references' ?'active':'' ?>" onclick="closeMoreMenu()">
                <i data-lucide="book-open"    style="width:22px;height:22px"></i><span>Справочник</span>
            </a>
            <a href="logout.php" class="more-menu-item more-menu-item-danger">
                <i data-lucide="log-out"      style="width:22px;height:22px"></i><span>Выйти</span>
            </a>
        </div>
    </div>

    <script>
    function toggleMoreMenu() {
        var overlay = document.getElementById('more-menu-overlay');
        var sheet   = document.getElementById('more-menu-sheet');
        if (!overlay || !sheet) return;
        var isOpen = sheet.classList.contains('open');
        if (isOpen) { closeMoreMenu(); } else { overlay.classList.add('open'); sheet.classList.add('open'); }
    }
    function closeMoreMenu() {
        var o = document.getElementById('more-menu-overlay');
        var s = document.getElementById('more-menu-sheet');
        if (o) o.classList.remove('open');
        if (s) s.classList.remove('open');
    }
    // Инициализируем иконки Lucide после загрузки страницы
    document.addEventListener('DOMContentLoaded', function() {
        if (typeof lucide !== 'undefined') lucide.createIcons();
        // Face ID настраивается вручную через login.php после входа
    });

    // ── Face ID helpers ───────────────────────────────────────────────────────
    function b64url(buf) {
        return btoa(String.fromCharCode(...new Uint8Array(buf))).replace(/\+/g,'-').replace(/\//g,'_').replace(/=+$/,'');
    }
    function b64urlDecode(str) {
        str = str.replace(/-/g,'+').replace(/_/g,'/');
        while (str.length % 4) str += '=';
        return Uint8Array.from(atob(str), c => c.charCodeAt(0)).buffer;
    }
    async function registerFaceId() {
        if (!window.PublicKeyCredential) { if(typeof showToast!=='undefined') showToast('Браузер не поддерживает Face ID','error'); return; }
        try {
            var res  = await fetch('api/webauthn.php?action=register_begin');
            var opts = await res.json();
            if (opts.error) { showToast(opts.error,'error'); return; }
            var cred = await navigator.credentials.create({ publicKey: {
                challenge: b64urlDecode(opts.challenge),
                rp: { id: opts.rp_id, name: 'byBuka' },
                user: { id: new TextEncoder().encode(opts.user_id), name: opts.user_name, displayName: opts.user_name },
                pubKeyCredParams: [{ type:'public-key', alg:-7 }],
                authenticatorSelection: { authenticatorAttachment:'platform', userVerification:'required', residentKey:'preferred' },
                timeout: 60000,
            }});
            var body = JSON.stringify({ clientDataJSON: b64url(cred.response.clientDataJSON), attestationObject: b64url(cred.response.attestationObject) });
            var r = await (await fetch('api/webauthn.php?action=register_complete',{method:'POST',headers:{'Content-Type':'application/json'},body})).json();
            if (r.ok) showToast('Face ID подключён! Теперь можно входить биометрией.','success');
            else showToast(r.error||'Ошибка регистрации Face ID','error');
        } catch(e) { if(e.name!=='NotAllowedError') showToast('Face ID: '+e.message,'error'); }
    }
    </script>

    <!-- LOADER -->
    <div class="loader-overlay" id="page-loader">
        <div class="loader">
            <div class="loader-rings">
                <div class="loader-ring-3"></div>
                <div class="loader-ring-2"></div>
                <div class="loader-ring-1"></div>
                <img src="assets/img/logo1.png" alt="by B" class="loader-logo">
            </div>
            <div class="loader-dots"><span></span><span></span><span></span></div>
        </div>
    </div>

    <!-- JS -->
    <script src="assets/js/modals.js?v=<?= $ASSET_V ?>"></script>
    <script src="assets/js/app.js?v=<?= $ASSET_V ?>"></script>
    <script src="assets/js/shipments.js?v=<?= $ASSET_V ?>"></script>
    <script src="assets/js/warehouse.js?v=<?= $ASSET_V ?>"></script>
    <script src="assets/js/counterparties.js?v=<?= $ASSET_V ?>"></script>
    <script src="assets/js/carriers.js?v=<?= $ASSET_V ?>"></script>
    <script src="assets/js/finances.js?v=<?= $ASSET_V ?>"></script>
    <script src="assets/js/item_search.js?v=<?= $ASSET_V ?>"></script>
    <script src="assets/js/sales.js?v=<?= $ASSET_V ?>"></script>
    <script src="assets/js/chain.js?v=<?= $ASSET_V ?>"></script>
    <script src="assets/js/bank.js?v=<?= $ASSET_V ?>"></script>
    <script src="assets/js/reconcile.js?v=<?= $ASSET_V ?>"></script>
    <script src="assets/js/reminders.js?v=<?= $ASSET_V ?>"></script>
    <script src="assets/js/notes.js?v=<?= $ASSET_V ?>"></script>
    <script src="assets/js/notifications.js?v=<?= $ASSET_V ?>"></script>
    <script src="assets/js/procurement.js?v=<?= $ASSET_V ?>"></script>
    <script src="assets/js/pwa.js?v=<?= $ASSET_V ?>"></script>
    <script src="assets/js/animations.js?v=<?= $ASSET_V ?>"></script>
    <script src="assets/js/search.js?v=<?= $ASSET_V ?>"></script>
    <script src="assets/js/mail.js?v=<?= $ASSET_V ?>"></script>
    <script src="assets/js/vehicle.js?v=<?= $ASSET_V ?>"></script>
    <script src="assets/js/deals.js?v=<?= $ASSET_V ?>"></script>
</body>
</html>
