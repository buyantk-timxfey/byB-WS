// ===== GLOBAL SEARCH + PERIOD FILTER =====

var _gsPage = (new URLSearchParams(location.search).get('page')) || 'dashboard';
var _gsDebounce = null;

var _GS_SKIP   = ['finances', 'bank', 'salary', 'wb'];
var _GS_NOFILTER = ['dashboard', 'references'];

var _GS_TYPE_ICONS = {
    shipment:     '<path d="M16.5 9.4 7.55 4.24"/><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 2 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.29 7 12 12 20.71 7"/><line x1="12" y1="22" x2="12" y2="12"/>',
    sale:         '<path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/>',
    warehouse:    '<path d="M22 8.35V20a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V8.35A2 2 0 0 1 3.26 6.5l8-3.43a2 2 0 0 1 1.48 0l8 3.43A2 2 0 0 1 22 8.35z"/><path d="M6 18h12"/><path d="M6 14h12"/><rect x="8" y="10" width="8" height="8"/>',
    counterparty: '<path d="M6 22V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v18Z"/><path d="M6 12H4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h2"/><path d="M18 9h2a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2h-2"/><path d="M10 6h4"/><path d="M10 10h4"/><path d="M10 14h4"/><path d="M10 18h4"/>',
    carrier:      '<rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/>',
    expense:      '<path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1Z"/><path d="M16 8H8"/><path d="M16 12H8"/><path d="M12 16H8"/>',
    bank:         '<line x1="3" y1="22" x2="21" y2="22"/><line x1="6" y1="18" x2="6" y2="11"/><line x1="10" y1="18" x2="10" y2="11"/><line x1="14" y1="18" x2="14" y2="11"/><line x1="18" y1="18" x2="18" y2="11"/><polygon points="12 2 20 7 4 7"/>',
};
var _GS_TYPE_LABELS = {
    shipment: 'Поставки', sale: 'Продажи', warehouse: 'Склад',
    counterparty: 'Контрагенты', carrier: 'Перевозчики',
    expense: 'Расходы', bank: 'Банк',
};

// ── Глобальные обработчики (вызываются из inline-атрибутов) ──────────────────

window.gsInput = function(val) {
    clearTimeout(_gsDebounce);
    _gsDebounce = setTimeout(function() { gsDoSearch(val.trim()); }, 280);
};

window.gsApplyPeriod = function() {
    var m = document.getElementById('gs-month');
    var y = document.getElementById('gs-year');
    if (!m || !y) return;
    var url = new URL(location.href);
    url.searchParams.set('page',  _gsPage);
    url.searchParams.set('month', m.value);
    url.searchParams.set('year',  y.value);
    url.searchParams.delete('period');
    location.href = url.toString();
};

window.gsResetPeriod = function() {
    var url = new URL(location.href);
    url.searchParams.set('page',   _gsPage);
    url.searchParams.set('period', 'all');
    url.searchParams.delete('month');
    url.searchParams.delete('year');
    location.href = url.toString();
};

window.gsCloseDropdown = function() {
    var d = document.getElementById('gs-dropdown');
    if (d) d.classList.remove('open');
};

window.gsPickResult = function(encoded) {
    gsCloseDropdown();
    var item = JSON.parse(decodeURIComponent(encoded));
    if (_gsPage === item.page) {
        gsOpenModal(item.type, item.id);
    } else {
        window._gsOpenAfterNav = item;
        navigateTo(item.page);
    }
};

window.gsOpenModal = function(type, id) {
    if (type === 'shipment'     && typeof openShipment     === 'function') return openShipment(id);
    if (type === 'sale'         && typeof openSaleDetail   === 'function') return openSaleDetail(id);
    if (type === 'warehouse'    && typeof openProductModal === 'function') return openProductModal(id);
    if (type === 'counterparty' && typeof openCounterparty === 'function') return openCounterparty(id);
    if (type === 'carrier'      && typeof openCarrier      === 'function') return openCarrier(id);
    navigateTo('finances');
};

// ── Основная логика поиска ────────────────────────────────────────────────────

function gsDoSearch(q) {
    if (!q) {
        gsCloseDropdown();
        if (_gsPage !== 'dashboard') gsLocalFilter('');
        return;
    }

    if (_gsPage === 'dashboard') {
        if (q.length < 2) return;
        fetch('api/search.php?q=' + encodeURIComponent(q))
            .then(function(r) { return r.json(); })
            .then(function(data) { gsRenderDropdown(data); })
            .catch(function() {});
    } else {
        gsLocalFilter(q);
    }
}

function gsLocalFilter(q) {
    var lq = q.toLowerCase();

    if (_gsPage === 'warehouse') {
        if (typeof filterWarehouseSearch === 'function') filterWarehouseSearch(q);
        return;
    }

    var tbodies = document.querySelectorAll('.main-content tbody');
    tbodies.forEach(function(tb) {
        tb.querySelectorAll('tr').forEach(function(row) {
            row.style.display = (!lq || row.textContent.toLowerCase().indexOf(lq) !== -1) ? '' : 'none';
        });
    });
}

function gsRenderDropdown(items) {
    var d = document.getElementById('gs-dropdown');
    if (!d) return;

    if (!items || !items.length) {
        d.innerHTML = '<div class="gs-drop-empty">Ничего не найдено</div>';
        d.classList.add('open');
        return;
    }

    var groups = {};
    items.forEach(function(item) {
        if (!groups[item.type]) groups[item.type] = [];
        groups[item.type].push(item);
    });

    var html = '';
    Object.keys(groups).forEach(function(type) {
        var list  = groups[type];
        var label = _GS_TYPE_LABELS[type] || type;
        var icon  = _GS_TYPE_ICONS[type]  || '';
        html += '<div class="gs-drop-group"><div class="gs-drop-group-label">' + label + '</div>';
        list.forEach(function(item) {
            var encoded = encodeURIComponent(JSON.stringify(item));
            html += '<div class="gs-drop-item" onclick="gsPickResult(\'' + encoded + '\')">'
                +   '<div class="gs-drop-icon gs-drop-icon--' + type + '">'
                +   '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:13px;height:13px">' + icon + '</svg>'
                +   '</div>'
                +   '<div class="gs-drop-body">'
                +   '<div class="gs-drop-title">' + gsEsc(item.title) + '</div>'
                +   (item.subtitle ? '<div class="gs-drop-sub">' + gsEsc(item.subtitle) + '</div>' : '')
                +   '</div>'
                +   (item.meta ? '<div class="gs-drop-meta">' + gsEsc(item.meta) + '</div>' : '')
                +   '</div>';
        });
        html += '</div>';
    });

    d.innerHTML = html;
    d.classList.add('open');
}

function gsEsc(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Построить панель поиска ───────────────────────────────────────────────────

function gsRenderBar() {
    var bar = document.getElementById('global-searchbar');
    if (!bar) return;

    _gsPage = (new URLSearchParams(location.search).get('page')) || 'dashboard';

    if (_GS_SKIP.indexOf(_gsPage) !== -1) {
        bar.style.display = 'none';
        return;
    }

    var showFilter = _GS_NOFILTER.indexOf(_gsPage) === -1;
    var gsData = window._gsData || {};
    var urlP   = new URLSearchParams(location.search);
    var selM   = parseInt(urlP.get('month')) || gsData.selMonth || (new Date().getMonth() + 1);
    var selY   = parseInt(urlP.get('year'))  || gsData.selYear  || new Date().getFullYear();
    var isAll  = urlP.get('period') === 'all';
    var months = gsData.months || [];
    var years  = gsData.years  || [new Date().getFullYear()];

    var html = '';

    if (showFilter) {
        var mOpts = months.map(function(m, i) {
            return '<option value="' + (i+1) + '"' + (selM === i+1 ? ' selected' : '') + '>' + m + '</option>';
        }).join('');
        var yOpts = years.map(function(y) {
            return '<option value="' + y + '"' + (selY === y ? ' selected' : '') + '>' + y + '</option>';
        }).join('');

        html += '<div class="gs-period">'
             +  '<svg class="gs-period-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>'
             +  '<select id="gs-month" onchange="gsApplyPeriod()">' + mOpts + '</select>'
             +  '<span class="gs-period-sep">·</span>'
             +  '<select id="gs-year" onchange="gsApplyPeriod()">' + yOpts + '</select>'
             +  '<span class="gs-period-sep">|</span>'
             +  '<button class="gs-period-all" onclick="gsResetPeriod()">' + (isAll ? 'Всё <i data-lucide="check" style="width:11px;height:11px;vertical-align:-1px"></i>' : 'Всё') + '</button>'
             +  '</div>';
    }

    html += '<div class="gs-input-wrap">'
         +  '<svg class="gs-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>'
         +  '<input type="text" id="gs-input" placeholder="Поиск..." autocomplete="off" oninput="gsInput(this.value)">'
         +  '</div>';

    var controls = document.getElementById('gs-search-controls');
    if (controls) controls.innerHTML = html;

    // Кнопки страницы — берём из переменной, выставленной инлайн-скриптом страницы
    var actions = document.getElementById('gs-page-actions');
    if (actions && window._gsPageActions !== undefined) {
        actions.innerHTML = window._gsPageActions;
        window._gsPageActions = undefined;
        if (window.lucide) lucide.createIcons();
    }

    bar.style.display = 'flex';
}

// ── Закрытие дропдауна при клике вне ─────────────────────────────────────────

document.addEventListener('click', function(e) {
    var bar = document.getElementById('global-searchbar');
    var d   = document.getElementById('gs-dropdown');
    if (bar && !bar.contains(e.target) && d && !d.contains(e.target)) {
        gsCloseDropdown();
    }
});

// ── SPA-навигация ─────────────────────────────────────────────────────────────

document.addEventListener('spa:navigated', function() {
    gsRenderBar();
    gsCloseDropdown();

    if (window._gsOpenAfterNav) {
        var item = window._gsOpenAfterNav;
        window._gsOpenAfterNav = null;
        setTimeout(function() { gsOpenModal(item.type, item.id); }, 400);
    }
});

// ── Первичная инициализация ───────────────────────────────────────────────────

(function init() {
    var bar = document.getElementById('global-searchbar');
    if (!bar) return;
    if (_GS_SKIP.indexOf(_gsPage) !== -1) { bar.style.display = 'none'; return; }
    gsRenderBar();
})();
