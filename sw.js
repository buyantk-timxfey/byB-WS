// ╔══════════════════════════════════════════════════════════════╗
// ║  byBuka Workspace — Service Worker                          ║
// ║  Кэширование + Офлайн-режим + Push-уведомления             ║
// ╚══════════════════════════════════════════════════════════════╝

const APP_VERSION    = 'v2.3';
const STATIC_CACHE   = `byb-static-${APP_VERSION}`;
const API_CACHE      = `byb-api-${APP_VERSION}`;
const ALL_CACHES     = [STATIC_CACHE, API_CACHE];

// Базовый путь (работает и на /byB-workspace/ и на корне /)
const BASE = self.registration.scope;

// Статические ресурсы — кэшируются при установке
const PRECACHE_ASSETS = [
    './',
    './index.php',
    './login.php',
    './assets/css/main.css',
    './assets/css/sidebar.css',
    './assets/css/tables.css',
    './assets/css/modals.css',
    './assets/css/dashboard.css',
    './assets/css/search.css',
    './assets/css/notifications.css',
    './assets/css/mobile.css',
    './assets/css/chain.css',
    './assets/js/app.js',
    './assets/js/modals.js',
    './assets/js/shipments.js',
    './assets/js/warehouse.js',
    './assets/js/finances.js',
    './assets/js/bank.js',
    './assets/js/sales.js',
    './assets/js/counterparties.js',
    './assets/js/carriers.js',
    './assets/js/notes.js',
    './assets/js/notifications.js',
    './assets/js/procurement.js',
    './assets/js/reminders.js',
    './assets/js/pwa.js',
    './assets/js/search.js',
    './assets/js/deals.js',
    './assets/css/deals.css',
    './assets/js/vehicle.js',
    './assets/css/vehicle.css',
    './assets/img/logo1.png',
    './assets/img/icon-192.png',
    './assets/img/icon-512.png',
];

// API эндпоинты которые кэшируем для офлайна
const CACHEABLE_API = [
    'api/shipments.php',
    'api/sales.php',
    'api/warehouse.php',
    'api/counterparties.php',
    'api/carriers.php',
    'api/finances.php',
    'api/notes.php',
    'api/procurement.php',
    'api/bank.php',
    'api/notifications.php',
];

// ─── Install ──────────────────────────────────────────────────────────────────

self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(STATIC_CACHE)
            .then(cache => {
                // Кэшируем по одному — чтобы ошибка одного не ломала всё
                return Promise.allSettled(
                    PRECACHE_ASSETS.map(url =>
                        cache.add(url).catch(() => {
                            // Тихо игнорируем ошибки отдельных ресурсов
                        })
                    )
                );
            })
            .then(() => self.skipWaiting())
    );
});

// ─── Activate ─────────────────────────────────────────────────────────────────

self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys()
            .then(keys => Promise.all(
                keys
                    .filter(key => !ALL_CACHES.includes(key))
                    .map(key => caches.delete(key))
            ))
            .then(() => self.clients.claim())
    );
});

// ─── Fetch ────────────────────────────────────────────────────────────────────

self.addEventListener('fetch', event => {
    const url = new URL(event.request.url);

    // Пропускаем не-GET запросы и чужие домены
    if (event.request.method !== 'GET') return;
    if (url.origin !== self.location.origin)  return;

    const path = url.pathname;

    // ── API запросы: Network First → Cache fallback ──────────────────────────
    if (CACHEABLE_API.some(api => path.includes(api))) {
        event.respondWith(networkFirstWithCache(event.request, API_CACHE));
        return;
    }

    // ── Навигация (страницы PHP): Network First → Cache ──────────────────────
    if (event.request.mode === 'navigate') {
        event.respondWith(
            fetch(event.request)
                .then(res => {
                    // Кэшируем успешные навигационные ответы
                    if (res.ok) {
                        const clone = res.clone();
                        caches.open(STATIC_CACHE).then(c => c.put(event.request, clone));
                    }
                    return res;
                })
                .catch(() => caches.match(event.request)
                    .then(cached => cached || caches.match('./index.php'))
                )
        );
        return;
    }

    // ── Статические ресурсы: Cache First → Network ───────────────────────────
    if (isStaticAsset(path)) {
        event.respondWith(
            caches.match(event.request)
                .then(cached => {
                    if (cached) return cached;
                    return fetch(event.request).then(res => {
                        if (res.ok) {
                            const clone = res.clone();
                            caches.open(STATIC_CACHE).then(c => c.put(event.request, clone));
                        }
                        return res;
                    });
                })
        );
        return;
    }

    // ── Всё остальное: Network с fallback ────────────────────────────────────
    event.respondWith(
        fetch(event.request).catch(() => caches.match(event.request))
    );
});

function isStaticAsset(path) {
    return /\.(css|js|png|jpg|jpeg|gif|svg|ico|woff2?|ttf|otf)$/i.test(path);
}

async function networkFirstWithCache(request, cacheName) {
    try {
        const res   = await fetch(request);
        const cache = await caches.open(cacheName);
        if (res.ok) cache.put(request, res.clone());
        return res;
    } catch {
        const cached = await caches.match(request);
        if (cached) return cached;
        // Офлайн: возвращаем пустой JSON чтобы не ломать UI
        return new Response(JSON.stringify([]), {
            headers: { 'Content-Type': 'application/json', 'X-Offline': '1' }
        });
    }
}

// ─── Push-уведомления ─────────────────────────────────────────────────────────

self.addEventListener('push', event => {
    let data = { title: 'byBuka', body: 'Новое уведомление', icon: './assets/img/icon-192.png' };

    try {
        if (event.data) {
            const payload = event.data.json();
            data = { ...data, ...payload };
        }
    } catch {
        if (event.data) data.body = event.data.text();
    }

    const options = {
        body:    data.body,
        icon:    data.icon    || './assets/img/icon-192.png',
        badge:   data.badge   || './assets/img/icon-96.png',
        tag:     data.tag     || 'byb-notification',
        data:    data.data    || {},
        silent:  false,
        vibrate: [200, 100, 200],
        actions: data.actions || [],
    };

    event.waitUntil(
        self.registration.showNotification(data.title, options)
    );
});

// ─── Клик по push-уведомлению ─────────────────────────────────────────────────

self.addEventListener('notificationclick', event => {
    event.notification.close();

    const url  = event.notification.data?.url || './index.php';
    const page = event.notification.data?.page;

    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true })
            .then(windowClients => {
                // Если приложение уже открыто — фокусируем и навигируем
                for (const client of windowClients) {
                    if ('focus' in client) {
                        client.focus();
                        if (page) {
                            client.postMessage({ type: 'NAVIGATE', page });
                        }
                        return;
                    }
                }
                // Иначе открываем новую вкладку
                return clients.openWindow(url);
            })
    );
});

// ─── Sync (фоновая синхронизация) ─────────────────────────────────────────────

self.addEventListener('sync', event => {
    if (event.tag === 'sync-notifications') {
        event.waitUntil(
            fetch('./api/notifications.php')
                .then(res => res.json())
                .then(data => {
                    if (data.length > 0) {
                        return self.registration.showNotification('byBuka — Новые уведомления', {
                            body:  `${data.length} активных уведомлений`,
                            icon:  './assets/img/icon-192.png',
                            badge: './assets/img/icon-96.png',
                            tag:   'byb-sync',
                        });
                    }
                })
                .catch(() => {})
        );
    }
});

// ─── Сообщения от клиента ─────────────────────────────────────────────────────

self.addEventListener('message', event => {
    if (event.data?.type === 'SKIP_WAITING') {
        self.skipWaiting();
    }
    if (event.data?.type === 'CACHE_VERSION') {
        event.ports[0].postMessage({ version: APP_VERSION });
    }
    // Показ уведомления из mail.js (не через Push API, а через postMessage)
    if (event.data?.type === 'SHOW_NOTIFICATION') {
        const { title, body, url } = event.data;
        event.waitUntil(
            self.registration.showNotification(title, {
                body,
                icon:  './assets/img/icon-192.png',
                badge: './assets/img/icon-72.png',
                tag:   'byb-mail',
                data:  { url: url || './' },
            })
        );
    }
});
