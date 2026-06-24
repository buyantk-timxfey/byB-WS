// ===== APP.JS =====

// ── iOS fix: click на <tr onclick="..."> ненадёжен внутри overflow:auto таблиц ──
// Используем touchend + вызываем функцию через window[name]() — без eval/new Function
;(function() {
    var _startY = 0, _startX = 0;
    document.addEventListener('touchstart', function(e) {
        if (e.touches.length === 1) {
            _startY = e.touches[0].clientY;
            _startX = e.touches[0].clientX;
        }
    }, { passive: true });

    document.addEventListener('touchend', function(e) {
        if (window.innerWidth > 768) return;
        var t = e.changedTouches[0];
        // Дрифт > 10px — скролл, не тап
        if (Math.abs(t.clientY - _startY) > 10 || Math.abs(t.clientX - _startX) > 10) return;
        var row = e.target.closest('tbody tr[onclick]');
        if (!row) return;
        // Не перехватываем клики по интерактивным элементам внутри строки
        if (e.target.closest('button, a, .btn, .btn-icon, .btn-action, .status-dropdown, select, input')) return;
        var fn = row.getAttribute('onclick');
        if (!fn) return;
        // Безопасный вызов: парсим "funcName(arg1, arg2)" и вызываем через window
        var m = fn.match(/^([A-Za-z_$][A-Za-z0-9_$]*)\(([^)]*)\)$/);
        if (!m) return;
        var func = window[m[1]];
        if (typeof func !== 'function') return;
        var args = m[2] ? m[2].split(',').map(function(a) {
            a = a.trim();
            return isNaN(a) ? a.replace(/['"]/g, '') : Number(a);
        }) : [];
        e.preventDefault();
        func.apply(null, args);
    }, { passive: false });
})();

function formatPrice(price) {
    return new Intl.NumberFormat('ru-RU', { style: 'currency', currency: 'RUB', minimumFractionDigits: 0 }).format(price);
}

function formatDate(dateStr) {
    if (!dateStr) return '—';
    return new Date(dateStr).toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' });
}

function daysSince(dateStr) {
    if (!dateStr) return 0;
    return Math.floor((new Date() - new Date(dateStr)) / 86400000);
}

function getStatusBadge(status) {
    const map = {
        'Ожидает отправки': ['badge-waiting','Ожидает'],
        'В пути':           ['badge-transit','В пути'],
        'Завершено':        ['badge-done','Завершено'],
        '⚠️':              ['badge-alert','Форс-мажор'],
        'На складе':        ['badge-instock','На складе'],
        'Частично продан':  ['badge-partial','Частично'],
        'Продан':           ['badge-sold','Продан'],
    };
    const [cls, label] = map[status] || ['badge-waiting', status];
    return `<span class="badge ${cls}">${label}</span>`;
}

// ===== API =====
async function api(endpoint, method = 'GET', data = null) {
    const options = { method, headers: { 'Content-Type': 'application/json', 'Cache-Control': 'no-cache' } };
    if (data) options.body = JSON.stringify(data);
    try {
        const res = await fetch(`api/${endpoint}`, options);
        if (res.status === 401) { window.location.href = 'login.php'; return; }
        if (!res.ok && res.headers.get('content-type')?.includes('text/html')) {
            throw new Error(`Ошибка сервера ${res.status}`);
        }
        const json = await res.json();
        if (json.error) throw new Error(json.error);
        return json;
    } catch (e) {
        showToast(e.message || 'Ошибка запроса', 'error');
        throw e;
    }
}

// ===== SPA НАВИГАЦИЯ =====
async function navigateTo(page) {
    const url = new URL(window.location.href);
    url.searchParams.set('page', page);
    history.pushState({ page }, '', url.toString());
    await reloadPage();

    // Обновляем активный пункт сайдбара (только у ссылок с href)
    document.querySelectorAll('.nav-item').forEach(a => {
        a.classList.toggle('active', (a.href || '').includes(`page=${page}`));
    });
}

// Перехватываем клики по сайдбару
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.nav-item[href]').forEach(a => {
        a.addEventListener('click', e => {
            const url = new URL(a.href);
            const page = url.searchParams.get('page');
            if (page) { e.preventDefault(); navigateTo(page); }
        });
    });

    if (window.lucide) lucide.createIcons();
    initAllSearches();
});

window.addEventListener('popstate', () => reloadPage());

// ===== ЧАСТИЧНАЯ ПЕРЕЗАГРУЗКА =====
async function reloadPage() {
    const main = document.querySelector('.main-content');
    const scrollTop = main ? main.scrollTop : 0;
    try {
        const res = await fetch(window.location.href);
        const html = await res.text();
        const doc = new DOMParser().parseFromString(html, 'text/html');
        const newMain = doc.querySelector('.main-content');
        if (main && newMain) {
            // Сбрасываем кнопки страницы перед загрузкой новой
            window._gsPageActions = undefined;
            const gsAct = document.getElementById('gs-page-actions');
            if (gsAct) gsAct.innerHTML = '';
            main.innerHTML = newMain.innerHTML;
            main.scrollTop = scrollTop;
            if (window.lucide) lucide.createIcons();
            initAllSearches();
            main.querySelectorAll('script').forEach(old => {
                const s = document.createElement('script');
                s.textContent = old.textContent;
                old.replaceWith(s);
            });
            // Восстанавливаем данные тайлов и фильтры после SPA-навигации
            setTimeout(() => {
                if (typeof restoreBankFilters === 'function') restoreBankFilters();
                if (typeof loadNotesTile === 'function' && document.getElementById('notes-tile-body')) loadNotesTile();
                if (typeof loadProcurementTile === 'function' && document.getElementById('procurement-tile-body')) loadProcurementTile();
                if (typeof loadNotifications === 'function') loadNotifications();
                // Trigger animations after SPA navigation
                document.dispatchEvent(new CustomEvent('spa:navigated'));
            }, 50);
        }
    } catch (e) { window.location.reload(); }
}

function hardReload() { window.location.reload(); }

// ===== ПОИСК ПО ЧАСТИ СЛОВА =====
function initTableSearch(inputId, tableBodyId) {
    const input = document.getElementById(inputId);
    if (!input) return;
    input.removeEventListener('input', input._searchHandler);
    input._searchHandler = function() {
        const q = this.value.toLowerCase().trim().replace(/\s+/g, '');
document.querySelectorAll(`#${tableBodyId} tr`).forEach(row => {
    const text = row.textContent.toLowerCase().replace(/[\s\u00a0]+/g, '');
    row.style.display = !q || text.includes(q) ? '' : 'none';
});
    };
    input.addEventListener('input', input._searchHandler);
}

// Автоинициализация всех поисков на странице
function initAllSearches() {
    document.querySelectorAll('[data-search-input][data-search-target]').forEach(input => {
        input.removeEventListener('input', input._searchHandler);
        input._searchHandler = function() {
            const q = this.value.toLowerCase().trim().replace(/\s+/g, '');
            document.querySelectorAll(`#${this.dataset.searchTarget} tr`).forEach(row => {
                const text = row.textContent.toLowerCase().replace(/[\s\u00a0]+/g, '');
                row.style.display = !q || text.includes(q) ? '' : 'none';
            });
        };
        input.addEventListener('input', input._searchHandler);
    });
    // Финансы и Банк — локальные поиски оставлены намеренно
    [
        ['search-expenses',  'expenses-tbody'],
        ['search-bank',      'bank-ops-tbody'],
    ].forEach(([inputId, tbodyId]) => initTableSearch(inputId, tbodyId));
}

// ===== ГЛОБАЛЬНЫЙ ФИЛЬТР =====
function toggleGlobalFilter() { document.getElementById('gf-panel').classList.toggle('active'); }

function applyGlobalFilter() {
    const url = new URL(window.location.href);
    url.searchParams.set('month', document.getElementById('filter-month').value);
    url.searchParams.set('year', document.getElementById('filter-year').value);
    url.searchParams.delete('period');
    window.location.href = url.toString();
}

// «Все периоды» — явный режим без фильтра (?period=all)
function resetGlobalFilter() {
    const url = new URL(window.location.href);
    url.searchParams.delete('month');
    url.searchParams.delete('year');
    url.searchParams.set('period', 'all');
    window.location.href = url.toString();
}

// ===== ЛОАДЕР =====
window.addEventListener('load', () => {
    const loader = document.getElementById('page-loader');
    if (loader) { loader.classList.add('hidden'); setTimeout(() => loader.remove(), 300); }
});

// ===== DATEPICKER (глобальный) =====
(function injectDpStyles() {
    if (document.getElementById('dp-styles')) return;
    const s = document.createElement('style');
    s.id = 'dp-styles';
    s.textContent = `
        .dp-wrap { position: relative; }
        .dp-input-row { display: flex; gap: 6px; align-items: center; }
        .dp-clear { background: none; border: none; color: var(--text-dim, #555); cursor: pointer; font-size: 16px; padding: 0 4px; line-height: 1; transition: color 0.15s; flex-shrink: 0; }
        .dp-clear:hover { color: var(--danger, #F44336); }
        .dp-calendar {
            position: absolute; top: calc(100% + 4px); left: 0;
            background: var(--card-bg, #1A1A1A);
            border: 1px solid var(--border, #2A2A2A);
            border-radius: var(--radius-lg, 12px);
            box-shadow: 0 8px 32px rgba(0,0,0,0.5);
            z-index: 600; width: 280px; padding: 16px; display: none;
        }
        .dp-calendar.open { display: block; }
        .dp-nav { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }
        .dp-nav-btn { background: none; border: 1px solid var(--border, #2A2A2A); color: var(--text-muted, #888); border-radius: 6px; width: 28px; height: 28px; cursor: pointer; font-size: 14px; display: flex; align-items: center; justify-content: center; transition: 0.15s; }
        .dp-nav-btn:hover { border-color: var(--accent, #C9A96E); color: var(--accent, #C9A96E); }
        .dp-month-label { font-size: 13px; font-weight: 500; color: var(--text, #F5F5F5); }
        .dp-weekdays { display: grid; grid-template-columns: repeat(7, 1fr); gap: 2px; margin-bottom: 4px; }
        .dp-weekday { text-align: center; font-size: 11px; color: var(--text-dim, #555); font-weight: 600; padding: 4px 0; }
        .dp-days { display: grid; grid-template-columns: repeat(7, 1fr); gap: 2px; }
        .dp-day { text-align: center; font-size: 13px; color: var(--text-muted, #888); padding: 6px 0; border-radius: 6px; cursor: pointer; transition: 0.15s; border: 1px solid transparent; }
        .dp-day:hover { background: rgba(201,169,110,0.1); color: var(--accent, #C9A96E); }
        .dp-day.today { border-color: var(--border, #2A2A2A); color: var(--text, #F5F5F5); }
        .dp-day.selected { background: var(--accent, #C9A96E); color: #000; font-weight: 600; }
        .dp-day.other-month { color: var(--text-dim, #555); }
        .dp-day.empty { cursor: default; } .dp-day.empty:hover { background: none; }
    `;
    document.head.appendChild(s);
})();

const _dpState = {};

// Простой хелпер для полей в модалках — hidden input сохраняет id оригинального поля
function dpField(id, initialValue) {
    const today = new Date().toISOString().split('T')[0];
    // Битые даты из БД ('0000-00-00' и т.п.) заменяем сегодняшней, иначе календарь ломается
    const isValid = initialValue
        && String(initialValue).match(/^\d{4}-\d{2}-\d{2}$/)
        && !String(initialValue).startsWith('0000');
    const val = isValid ? initialValue : today;
    const [y, m, d] = val.split('-');
    _dpState[id] = { year: parseInt(y), month: parseInt(m) - 1, selected: val };
    const display = `${d}.${m}.${y}`;
    return `<div class="dp-wrap" id="dpw-${id}">
        <div class="dp-input-row">
            <input type="text" class="form-control" data-dpid="${id}" placeholder="дд.мм.гггг"
                autocomplete="off" readonly onclick="dpOpen('${id}')" style="cursor:pointer" value="${display}">
            <input type="hidden" id="${id}" class="dp-hidden-val" value="${val}">
            <button class="dp-clear" onclick="dpClear('${id}')" title="Очистить"><i data-lucide="x" style="width:15px;height:15px"></i></button>
        </div>
        <div class="dp-calendar" id="dpc-${id}"></div>
    </div>`;
}

function dpOpen(id) {
    document.querySelectorAll('.dp-calendar.open').forEach(c => { if (c.id !== 'dpc-'+id) c.classList.remove('open'); });
    const cal = document.getElementById('dpc-'+id);
    if (!cal) return;
    if (cal.classList.contains('open')) { cal.classList.remove('open'); return; }
    const now = new Date();
    if (!_dpState[id]) _dpState[id] = { year: now.getFullYear(), month: now.getMonth(), selected: null };
    dpRender(id);
    cal.classList.add('open');
}

function dpRender(id) {
    const cal = document.getElementById('dpc-'+id);
    if (!cal) return;
    const s = _dpState[id];
    const monthNames = ['Январь','Февраль','Март','Апрель','Май','Июнь','Июль','Август','Сентябрь','Октябрь','Ноябрь','Декабрь'];
    const today = new Date(); today.setHours(0,0,0,0);
    const first = new Date(s.year, s.month, 1);
    const startDay = (first.getDay() + 6) % 7;
    const daysInMonth = new Date(s.year, s.month + 1, 0).getDate();
    const daysInPrev = new Date(s.year, s.month, 0).getDate();
    let html = '';
    for (let i = 0; i < startDay; i++) html += `<div class="dp-day other-month">${daysInPrev - startDay + i + 1}</div>`;
    for (let d = 1; d <= daysInMonth; d++) {
        const date = new Date(s.year, s.month, d);
        const iso = `${s.year}-${String(s.month+1).padStart(2,'0')}-${String(d).padStart(2,'0')}`;
        let cls = 'dp-day';
        if (date.getTime() === today.getTime()) cls += ' today';
        if (s.selected === iso) cls += ' selected';
        html += `<div class="${cls}" onmousedown="dpSelect('${id}','${iso}')">${d}</div>`;
    }
    const rem = (7 - (startDay + daysInMonth) % 7) % 7;
    for (let i = 1; i <= rem; i++) html += `<div class="dp-day other-month">${i}</div>`;
    cal.innerHTML = `
        <div class="dp-nav">
            <button class="dp-nav-btn" onmousedown="dpPrev('${id}')">‹</button>
            <span class="dp-month-label">${monthNames[s.month]} ${s.year}</span>
            <button class="dp-nav-btn" onmousedown="dpNext('${id}')">›</button>
        </div>
        <div class="dp-weekdays">${['Пн','Вт','Ср','Чт','Пт','Сб','Вс'].map(d=>`<div class="dp-weekday">${d}</div>`).join('')}</div>
        <div class="dp-days">${html}</div>`;
}

function dpPrev(id) { const s=_dpState[id]; if(s.month===0){s.month=11;s.year--;}else s.month--; dpRender(id); }
function dpNext(id) { const s=_dpState[id]; if(s.month===11){s.month=0;s.year++;}else s.month++; dpRender(id); }

function dpSelect(id, iso) {
    const parts = iso.split('-');
    const yr = parseInt(parts[0]), mo = parseInt(parts[1]), dy = parts[2];
    if (!_dpState[id]) _dpState[id] = {};
    _dpState[id].selected = iso;
    _dpState[id].year  = yr;
    _dpState[id].month = mo - 1;
    const wrap = document.getElementById('dpw-'+id);
    if (!wrap) return;
    const display = `${dy}.${parts[1]}.${parts[0]}`;
    const textInput = wrap.querySelector('[data-dpid]');
    const hiddenInput = wrap.querySelector('[class$="-val"]');
    if (textInput) textInput.value = display;
    if (hiddenInput) hiddenInput.value = iso;
    dpRender(id);
    setTimeout(() => { const cal = document.getElementById('dpc-'+id); if(cal) cal.classList.remove('open'); }, 50);
}

function dpClear(id) {
    if (_dpState[id]) _dpState[id].selected = null;
    const wrap = document.getElementById('dpw-'+id);
    if (!wrap) return;
    const textInput = wrap.querySelector('[data-dpid]');
    const hiddenInput = wrap.querySelector('[class$="-val"]');
    if (textInput) textInput.value = '';
    if (hiddenInput) hiddenInput.value = '';
}

document.addEventListener('click', e => {
    if (!e.target.closest('.dp-wrap')) {
        document.querySelectorAll('.dp-calendar.open').forEach(c => c.classList.remove('open'));
    }
});


