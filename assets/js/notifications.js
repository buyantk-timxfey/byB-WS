// ── Notifications ─────────────────────────────────────────────────────────────

let _notifications = [];
let _notifOpen     = false;
const DISMISSED_KEY = 'byb_dismissed_notifs';
const DISMISSED_MAX = 300;          // максимум хранимых ключей
const DISMISSED_TTL_DAYS = 90;      // срок жизни записи
const _DISMISSED_TTL_MS = DISMISSED_TTL_DAYS * 86400000;

// Записи хранятся как {k: ключ, t: timestamp}. Старый формат (массив строк)
// читается прозрачно. На чтении и записи отсекаем устаревшее и лишнее.
function _normalizeDismissed(arr) {
    if (!Array.isArray(arr)) return [];
    const now = Date.now();
    let out = arr
        .map(e => (typeof e === 'string') ? { k: e, t: now } : e)
        .filter(e => e && e.k && (now - (e.t || now)) < _DISMISSED_TTL_MS);
    if (out.length > DISMISSED_MAX) out = out.slice(-DISMISSED_MAX);
    return out;
}

function _getDismissed() {
    try { return _normalizeDismissed(JSON.parse(localStorage.getItem(DISMISSED_KEY) || '[]')); }
    catch { return []; }
}

function _saveDismissed(arr) {
    try { localStorage.setItem(DISMISSED_KEY, JSON.stringify(_normalizeDismissed(arr))); } catch {}
}

// Множество скрытых ключей — для быстрых проверок
function _dismissedKeys() {
    return new Set(_getDismissed().map(e => e.k));
}

// Добавляет ключ в список (если ещё нет)
function _addDismissed(arr, key) {
    if (!arr.some(e => e.k === key)) arr.push({ k: key, t: Date.now() });
}

// Stable unique key per notification
function _notifKey(item) {
    if (item.type === 'reminder') return `reminder_${item.reminder_id}`;
    if (item.type === 'mail')     return item._mail_key || `mail_${item.title}`;
    if (item.link_id)             return `${item.type}_${item.link_id}`;
    return `${item.type}_${item.title}`;
}

function _filterActive(all) {
    const keys = _dismissedKeys();
    return all.filter(n => !keys.has(_notifKey(n)));
}

async function loadNotifications() {
    try {
        const res = await fetch('api/notifications.php');
        if (!res.ok) throw new Error('HTTP ' + res.status);
        _notifications = await res.json();

        // Добавляем почтовые уведомления из localStorage
        try {
            const keys = _dismissedKeys();
            const mailNotifs = JSON.parse(localStorage.getItem('byb_mail_notifs') || '[]');
            mailNotifs.forEach(m => {
                if (!keys.has(m.key)) {
                    _notifications.push({
                        type:      'mail',
                        icon:      'mail',
                        severity:  'info',
                        title:     'Новое письмо' + (m.label ? ` — ${m.label}` : ''),
                        sub:       (m.from ? m.from + ': ' : '') + (m.subject || ''),
                        link_page: 'mail',
                        link_id:   0,
                        _mail_key: m.key,
                        _sort_date: new Date(m.ts).toISOString().slice(0,10),
                    });
                }
            });
        } catch {}

        updateNotifBadge();
        if (_notifOpen) renderNotifDropdown();
    } catch (e) {
        console.warn('Notifications load error:', e);
    }
}

function updateNotifBadge() {
    const count   = _filterActive(_notifications).length;
    const countTxt = count > 99 ? '99+' : String(count);

    // Десктоп-колокольчик (sidebar)
    const badge   = document.getElementById('notif-badge');
    const bellNav = document.getElementById('notif-bell-nav');
    if (badge) {
        badge.textContent = countTxt;
        badge.classList.toggle('visible', count > 0);
    }
    bellNav && bellNav.classList.toggle('has-notifs', count > 0);

    // Мобильный bottom-nav колокольчик
    const navBadge   = document.getElementById('notif-badge-nav');
    const navBellBtn = document.getElementById('bottom-nav-bell-btn');
    if (navBadge) {
        navBadge.textContent = countTxt;
        navBadge.classList.toggle('visible', count > 0);
    }
    navBellBtn && navBellBtn.classList.toggle('has-notifs', count > 0);
}

function dismissNotification(key) {
    const dismissed = _getDismissed();
    _addDismissed(dismissed, key);
    _saveDismissed(dismissed);
    // Если это почтовое уведомление — чистим из localStorage
    if (key.startsWith('mail_')) {
        try {
            const ml = JSON.parse(localStorage.getItem('byb_mail_notifs') || '[]');
            localStorage.setItem('byb_mail_notifs', JSON.stringify(ml.filter(m => m.key !== key)));
        } catch {}
    }
    updateNotifBadge();
    renderNotifDropdown();
}

function markAllRead() {
    const active    = _filterActive(_notifications);
    const dismissed = _getDismissed();
    active.forEach(n => {
        const key = _notifKey(n);
        _addDismissed(dismissed, key);
        if (n.type === 'reminder' && n.reminder_id) {
            fetch('api/reminders.php?id=' + n.reminder_id, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ is_done: 1 }),
            }).catch(() => {});
        }
    });
    _saveDismissed(dismissed);
    updateNotifBadge();
    renderNotifDropdown();
}

// ── Icon per type ─────────────────────────────────────────────────────────────

function _notifIconHtml(item) {
    const iconMap = {
        shipment: 'package',
        sale:     'credit-card',
        note:     'file-text',
        reminder: 'bell',
        bank_op:  'landmark',
        expense:  'receipt',
    };
    const icon = iconMap[item.type] || item.icon || 'bell';
    const cls  = item.severity || 'info';
    return `<div class="notif-icon ${cls}">
        <i data-lucide="${icon}" style="width:15px;height:15px;"></i>
    </div>`;
}

// ── Render dropdown ───────────────────────────────────────────────────────────

function renderNotifDropdown() {
    const dropdown = document.getElementById('notif-dropdown');
    if (!dropdown) return;

    const active = _filterActive(_notifications);

    let listHtml;
    if (active.length === 0) {
        listHtml = `<div class="notif-empty">
            <i data-lucide="check-circle" style="width:28px;height:28px;color:var(--success);margin-bottom:8px;display:block;margin-left:auto;margin-right:auto;"></i>
            Всё в порядке, уведомлений нет
        </div>`;
    } else {
        listHtml = active.map(item => {
            const key = _notifKey(item);
            const navigable = item.link_page && item.link_id;
            const navAttr   = navigable
                ? `onclick="_notifNavigate('${_escHtml(item.link_page)}', ${item.link_id}, '${key}')"`
                : '';
            return `<div class="notif-item" ${navAttr} style="${navigable ? '' : 'cursor:default;'}">
                ${_notifIconHtml(item)}
                <div class="notif-content">
                    <div class="notif-title">${_escHtml(item.title)}</div>
                    <div class="notif-sub">${_escHtml(item.sub || '')}</div>
                    ${item.type === 'reminder' && item.reminder_id
                        ? `<button class="notif-done-btn" onclick="event.stopPropagation();markReminderDone(${item.reminder_id},'${key}')">Выполнено</button>`
                        : ''}
                </div>
                <button class="notif-dismiss-btn" onclick="event.stopPropagation();dismissNotification('${key}')" title="Скрыть">
                    <i data-lucide="x" style="width:13px;height:13px;"></i>
                </button>
            </div>`;
        }).join('');
    }

    dropdown.innerHTML = `
        <div class="notif-dropdown-header">
            <span>Уведомления${active.length > 0 ? ` <span style="font-size:11px;font-weight:400;color:var(--text-muted);">(${active.length})</span>` : ''}</span>
            ${active.length > 0
                ? `<button class="notif-mark-all-btn" onclick="markAllRead()">Прочитать все</button>`
                : ''}
        </div>
        <div class="notif-list">${listHtml}</div>`;

    if (typeof lucide !== 'undefined') lucide.createIcons();
}

function _notifNavigate(page, id, key) {
    closeNotifDropdown();
    if (typeof navigateTo === 'function') {
        navigateTo(page).then(() => {
            // попытка открыть запись после навигации
            setTimeout(() => {
                const fnMap = {
                    shipments: id => typeof openShipment === 'function' && openShipment(id),
                    sales:     id => typeof openSaleDetail === 'function' && openSaleDetail(id),
                };
                if (fnMap[page]) fnMap[page](id);
            }, 400);
        });
    }
}

async function markReminderDone(reminderId, key) {
    try {
        await fetch('api/reminders.php?id=' + reminderId, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ is_done: 1 }),
        });
    } catch {}
    dismissNotification(key);
}

// ── Toggle dropdown ───────────────────────────────────────────────────────────

function toggleNotifDropdown() {
    // На мобильном используем drawer — он гарантированно работает
    if (window.innerWidth <= 768) {
        _openMobileNotifDrawer();
        return;
    }
    _notifOpen = !_notifOpen;
    const dropdown = document.getElementById('notif-dropdown');
    if (!dropdown) return;
    if (_notifOpen) {
        dropdown.classList.add('open');
        loadNotifications().then(() => renderNotifDropdown());
        if (typeof requestPushPermission === 'function'
            && Notification?.permission === 'default'
            && !localStorage.getItem('push_asked')) {
            localStorage.setItem('push_asked', '1');
            setTimeout(requestPushPermission, 800);
        }
    } else {
        dropdown.classList.remove('open');
    }
}

function closeNotifDropdown() {
    _notifOpen = false;
    const dropdown = document.getElementById('notif-dropdown');
    if (dropdown) dropdown.classList.remove('open');
}

async function _openMobileNotifDrawer() {
    await loadNotifications();
    const active = _filterActive(_notifications);

    let listHtml;
    if (active.length === 0) {
        listHtml = `<div style="display:flex;flex-direction:column;align-items:center;gap:8px;padding:40px 20px;color:var(--text-muted);font-size:13px">
            <i data-lucide="check-circle" style="width:36px;height:36px;color:var(--success)"></i>
            Уведомлений нет
        </div>`;
    } else {
        listHtml = active.map(item => {
            const key = _notifKey(item);
            const navigable = item.link_page && item.link_id;
            return `<div class="notif-item" style="${navigable ? 'cursor:pointer' : 'cursor:default'}"
                        ${navigable ? `onclick="_notifNavigate('${_escHtml(item.link_page)}',${item.link_id},'${key}')"` : ''}>
                ${_notifIconHtml(item)}
                <div class="notif-content">
                    <div class="notif-title">${_escHtml(item.title)}</div>
                    <div class="notif-sub">${_escHtml(item.sub || '')}</div>
                    ${item.type === 'reminder' && item.reminder_id
                        ? `<button class="notif-done-btn" onclick="event.stopPropagation();markReminderDone(${item.reminder_id},'${key}')">Выполнено</button>`
                        : ''}
                </div>
                <button class="notif-dismiss-btn" style="opacity:1" onclick="event.stopPropagation();dismissNotification('${key}');_refreshMobileNotifDrawer()">
                    <i data-lucide="x" style="width:13px;height:13px"></i>
                </button>
            </div>`;
        }).join('');
    }

    const markAllBtn = active.length > 0
        ? `<button class="btn btn-ghost btn-sm" onclick="markAllRead();_refreshMobileNotifDrawer()">Прочитать все</button>`
        : '';

    openDrawer(`
        <div class="drawer-header">
            <h3 class="drawer-title">Уведомления${active.length > 0 ? ` <span style="font-size:12px;font-weight:400;color:var(--text-muted)">(${active.length})</span>` : ''}</h3>
            <div style="display:flex;gap:6px;align-items:center">
                ${markAllBtn}
                <button class="btn-close" onclick="closeModal()"><i data-lucide="x" style="width:15px;height:15px"></i></button>
            </div>
        </div>
        <div class="drawer-body" style="padding:0">
            <div id="mobile-notif-list">${listHtml}</div>
        </div>
    `);
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

function _refreshMobileNotifDrawer() {
    const list = document.getElementById('mobile-notif-list');
    if (!list) return;
    const active = _filterActive(_notifications);
    if (active.length === 0) {
        list.innerHTML = `<div style="display:flex;flex-direction:column;align-items:center;gap:8px;padding:40px 20px;color:var(--text-muted);font-size:13px">
            <i data-lucide="check-circle" style="width:36px;height:36px;color:var(--success)"></i>
            Уведомлений нет
        </div>`;
    } else {
        list.innerHTML = active.map(item => {
            const key = _notifKey(item);
            const navigable = item.link_page && item.link_id;
            return `<div class="notif-item" ${navigable ? `onclick="_notifNavigate('${_escHtml(item.link_page)}',${item.link_id},'${key}')"` : ''} style="${navigable?'cursor:pointer':''}">
                ${_notifIconHtml(item)}
                <div class="notif-content">
                    <div class="notif-title">${_escHtml(item.title)}</div>
                    <div class="notif-sub">${_escHtml(item.sub || '')}</div>
                </div>
                <button class="notif-dismiss-btn" style="opacity:1" onclick="event.stopPropagation();dismissNotification('${key}');_refreshMobileNotifDrawer()">
                    <i data-lucide="x" style="width:13px;height:13px"></i>
                </button>
            </div>`;
        }).join('');
    }
    if (typeof lucide !== 'undefined') lucide.createIcons();
    updateNotifBadge();
}

// closeNotifDropdown() определена выше

// Close on outside click
document.addEventListener('click', function(e) {
    if (!_notifOpen) return;
    const bellNav  = document.getElementById('notif-bell-nav');
    const dropdown = document.getElementById('notif-dropdown');
    if (bellNav  && bellNav.contains(e.target))  return;
    if (dropdown && dropdown.contains(e.target)) return;
    closeNotifDropdown();
});

function _escHtml(str) {
    return String(str||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}
