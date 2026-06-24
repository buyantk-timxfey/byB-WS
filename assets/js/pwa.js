// ╔══════════════════════════════════════════════════════════════╗
// ║  byBuka Workspace — PWA Client                              ║
// ║  Установка, Push-уведомления, Офлайн-индикатор             ║
// ╚══════════════════════════════════════════════════════════════╝

// VAPID Public Key — замени после запуска setup-pwa.php
const VAPID_PUBLIC_KEY = 'REPLACE_AFTER_RUNNING_SETUP_PWA';

// ─── Service Worker регистрация ───────────────────────────────────────────────

let _swRegistration = null;

async function registerServiceWorker() {
    if (!('serviceWorker' in navigator)) return;

    try {
        // sw.php отдаёт sw.js с автоматической версией кэша (по mtime статики) —
        // ручной бамп APP_VERSION больше не нужен
        _swRegistration = await navigator.serviceWorker.register('./sw.php', {
            scope: './',
            updateViaCache: 'none',
        });

        // Тихое обновление при новой версии SW
        _swRegistration.addEventListener('updatefound', () => {
            const newWorker = _swRegistration.installing;
            newWorker.addEventListener('statechange', () => {
                if (newWorker.state === 'installed' && navigator.serviceWorker.controller) {
                    showPwaUpdateBanner();
                }
            });
        });

        // Авто-применение новой версии: когда новый SW берёт управление страницей
        // (sw.js делает skipWaiting + clients.claim), перезагружаем один раз —
        // иначе страница продолжает крутить старый закэшированный JS («версия на шаг
        // назад»). Слушатель вешаем только если уже есть контроллер, чтобы не
        // перезагружаться на самой первой установке SW.
        if (navigator.serviceWorker.controller) {
            navigator.serviceWorker.addEventListener('controllerchange', () => {
                if (window._swReloading) return;
                window._swReloading = true;
                window.location.reload();
            });
        }

        console.log('[PWA] Service Worker зарегистрирован');

        // Подписываемся на push если разрешение уже есть
        if (Notification.permission === 'granted') {
            await subscribeToPush();
        }

    } catch (err) {
        console.warn('[PWA] Ошибка регистрации SW:', err);
    }
}

// ─── Определение платформы ────────────────────────────────────────────────────

function isIOS() {
    return /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
}

function isInStandaloneMode() {
    return window.navigator.standalone === true
        || window.matchMedia('(display-mode: standalone)').matches;
}

function isAndroid() {
    return /Android/.test(navigator.userAgent);
}

// ─── Баннер установки ─────────────────────────────────────────────────────────

let _deferredPrompt = null; // Chrome/Android install prompt

// Перехватываем стандартный промпт (Chrome/Android/Desktop)
window.addEventListener('beforeinstallprompt', e => {
    e.preventDefault();
    _deferredPrompt = e;

    // Показываем через 3 секунды если не в standalone
    if (!isInStandaloneMode()) {
        setTimeout(showInstallBanner, 3000);
    }
});

window.addEventListener('appinstalled', () => {
    _deferredPrompt = null;
    hideInstallBanner();
    localStorage.setItem('pwa_installed', '1');
    showToast('byBuka установлен на рабочий стол!');
});

function showInstallBanner() {
    // Не показываем если уже установлено или отклонено недавно
    if (isInStandaloneMode()) return;
    if (localStorage.getItem('pwa_install_dismissed')) {
        const dismissed = parseInt(localStorage.getItem('pwa_install_dismissed'));
        if (Date.now() - dismissed < 7 * 24 * 60 * 60 * 1000) return; // 7 дней
    }

    if (isIOS()) {
        showIOSInstallBanner();
    } else if (_deferredPrompt) {
        showAndroidInstallBanner();
    }
}

function showIOSInstallBanner() {
    if (document.getElementById('pwa-install-banner')) return;

    const banner = document.createElement('div');
    banner.id    = 'pwa-install-banner';
    banner.innerHTML = `
        <div class="pwa-banner-content">
            <img src="assets/img/icon-72.png" alt="byBuka" class="pwa-banner-icon">
            <div class="pwa-banner-text">
                <strong>Установить byBuka</strong>
                <span>Нажми <span class="pwa-share-icon">⎙</span> затем «На экран Домой»</span>
            </div>
            <button class="pwa-banner-close" onclick="dismissInstallBanner()"><i data-lucide="x" style="width:15px;height:15px"></i></button>
        </div>
        <div class="pwa-banner-arrow"></div>
    `;
    document.body.appendChild(banner);

    // Анимация появления
    requestAnimationFrame(() => banner.classList.add('visible'));
}

function showAndroidInstallBanner() {
    if (document.getElementById('pwa-install-banner')) return;

    const banner = document.createElement('div');
    banner.id    = 'pwa-install-banner';
    banner.innerHTML = `
        <div class="pwa-banner-content">
            <img src="assets/img/icon-72.png" alt="byBuka" class="pwa-banner-icon">
            <div class="pwa-banner-text">
                <strong>Установить byBuka</strong>
                <span>Быстрый доступ с рабочего стола</span>
            </div>
            <button class="pwa-banner-install" onclick="triggerInstallPrompt()">Установить</button>
            <button class="pwa-banner-close" onclick="dismissInstallBanner()"><i data-lucide="x" style="width:15px;height:15px"></i></button>
        </div>
    `;
    document.body.appendChild(banner);
    requestAnimationFrame(() => banner.classList.add('visible'));
}

async function triggerInstallPrompt() {
    if (!_deferredPrompt) return;
    _deferredPrompt.prompt();
    const { outcome } = await _deferredPrompt.userChoice;
    _deferredPrompt = null;
    hideInstallBanner();
    if (outcome === 'accepted') {
        showToast('byBuka устанавливается...');
    }
}

function dismissInstallBanner() {
    localStorage.setItem('pwa_install_dismissed', Date.now().toString());
    hideInstallBanner();
}

function hideInstallBanner() {
    const banner = document.getElementById('pwa-install-banner');
    if (banner) {
        banner.classList.remove('visible');
        setTimeout(() => banner.remove(), 300);
    }
}

// ─── Баннер обновления SW ─────────────────────────────────────────────────────

function showPwaUpdateBanner() {
    if (document.getElementById('pwa-update-banner')) return;

    const banner = document.createElement('div');
    banner.id    = 'pwa-update-banner';
    banner.innerHTML = `
        <span>Доступна новая версия byBuka</span>
        <button onclick="applySwUpdate()" class="pwa-update-btn">Обновить</button>
        <button onclick="this.parentElement.remove()" class="pwa-banner-close"><i data-lucide="x" style="width:15px;height:15px"></i></button>
    `;
    document.body.appendChild(banner);
    requestAnimationFrame(() => banner.classList.add('visible'));
}

function applySwUpdate() {
    if (_swRegistration?.waiting) {
        _swRegistration.waiting.postMessage({ type: 'SKIP_WAITING' });
    }
    window.location.reload();
}

// ─── Офлайн-индикатор ─────────────────────────────────────────────────────────

function showOfflineBadge() {
    let badge = document.getElementById('pwa-offline-badge');
    if (!badge) {
        badge = document.createElement('div');
        badge.id = 'pwa-offline-badge';
        badge.textContent = '📵 Офлайн — данные из кэша';
        document.body.appendChild(badge);
    }
    badge.classList.add('visible');
}

function hideOfflineBadge() {
    const badge = document.getElementById('pwa-offline-badge');
    if (badge) badge.classList.remove('visible');
}

window.addEventListener('online',  hideOfflineBadge);
window.addEventListener('offline', showOfflineBadge);
if (!navigator.onLine) showOfflineBadge();

// ─── Push-уведомления ─────────────────────────────────────────────────────────

function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - base64String.length % 4) % 4);
    const base64  = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const raw     = atob(base64);
    return Uint8Array.from([...raw].map(c => c.charCodeAt(0)));
}

async function requestPushPermission() {
    if (!('Notification' in window)) {
        showToast('Push-уведомления не поддерживаются браузером', 'error');
        return false;
    }

    if (Notification.permission === 'granted') {
        await subscribeToPush();
        return true;
    }

    if (Notification.permission === 'denied') {
        showToast('Push-уведомления заблокированы. Разреши в настройках браузера.', 'error');
        return false;
    }

    const permission = await Notification.requestPermission();
    if (permission === 'granted') {
        await subscribeToPush();
        showToast('Push-уведомления включены!');
        return true;
    }

    return false;
}

async function subscribeToPush() {
    if (!_swRegistration) return;
    if (VAPID_PUBLIC_KEY === 'REPLACE_AFTER_RUNNING_SETUP_PWA') return;

    try {
        // Проверяем существующую подписку
        let subscription = await _swRegistration.pushManager.getSubscription();

        if (!subscription) {
            subscription = await _swRegistration.pushManager.subscribe({
                userVisibleOnly:      true,
                applicationServerKey: urlBase64ToUint8Array(VAPID_PUBLIC_KEY),
            });
        }

        // Отправляем подписку на сервер
        await fetch('api/push.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action:       'subscribe',
                subscription: subscription.toJSON(),
            }),
        });

        console.log('[PWA] Push-подписка активна');

    } catch (err) {
        console.warn('[PWA] Ошибка push-подписки:', err);
    }
}

async function unsubscribeFromPush() {
    if (!_swRegistration) return;
    const subscription = await _swRegistration.pushManager.getSubscription();
    if (subscription) {
        await subscription.unsubscribe();
        await fetch('api/push.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'unsubscribe', endpoint: subscription.endpoint }),
        });
    }
}

// Слушаем сообщения от SW (навигация по клику на уведомление)
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.addEventListener('message', event => {
        if (event.data?.type === 'NAVIGATE' && typeof navigateTo === 'function') {
            navigateTo(event.data.page);
        }
    });
}

// ─── Инициализация ────────────────────────────────────────────────────────────

document.addEventListener('DOMContentLoaded', () => {
    registerServiceWorker();

    // Показываем баннер установки через 5 сек на iOS если не установлено
    if (isIOS() && !isInStandaloneMode()) {
        setTimeout(showInstallBanner, 5000);
    }
});
