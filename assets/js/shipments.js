// ===== SHIPMENTS.JS =====

// Стили для searchable-select (инжектируются один раз)
(function injectSearchableStyles() {
    if (document.getElementById('searchable-select-styles')) return;
    const style = document.createElement('style');
    style.id = 'searchable-select-styles';
    style.textContent = `
        .searchable-select { position: relative; }
        .searchable-dropdown {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            z-index: 500;
            background: var(--card-bg, #1A1A1A);
            border: 1px solid var(--border, #2A2A2A);
            border-radius: var(--radius, 8px);
            max-height: 200px;
            overflow-y: auto;
            box-shadow: 0 8px 24px rgba(0,0,0,0.4);
            margin-top: 2px;
        }
        .searchable-option {
            padding: 8px 12px;
            font-size: 13px;
            color: var(--text-muted, #888);
            cursor: pointer;
            transition: background 0.15s, color 0.15s;
        }
        .searchable-option:hover {
            background: rgba(255,255,255,0.04);
            color: var(--accent, #C9A96E);
        }
        .item-row > div[style*="relative"] { flex: 2; min-width: 0; }
        .product-dropdown { min-width: 220px; }
        .shipment-tab-btn-wrap { position: relative; display: inline-flex; align-items: center; }
        .shipment-tab-close { position: absolute; top: 2px; right: 2px; background: none; border: none; color: var(--text-dim, #555); cursor: pointer; font-size: 10px; line-height: 1; padding: 1px 3px; border-radius: 3px; transition: color 0.15s; }
        .shipment-tab-close:hover { color: var(--danger, #F44336); }
        .shipment-tab-btn-wrap .shipment-tab-btn { padding-right: 18px; }

        /* ===== SHIPMENT DOCS ===== */
        .sdoc-section { margin-top: 20px; border-top: 1px solid var(--border, #2A2A2A); padding-top: 16px; }
        .sdoc-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; }
        .sdoc-upload-btn { cursor: pointer; font-size: 12px; color: var(--accent, #C9A96E); border: 1px solid var(--accent, #C9A96E); border-radius: 6px; padding: 4px 10px; transition: 0.15s; user-select: none; }
        .sdoc-upload-btn:hover { background: rgba(201,169,110,0.1); }
        .sdoc-list { display: flex; flex-direction: column; gap: 6px; }
        .sdoc-empty { font-size: 12px; color: var(--text-dim, #555); }
        .sdoc-item { display: flex; align-items: center; gap: 8px; background: rgba(255,255,255,0.03); border: 1px solid var(--border, #2A2A2A); border-radius: 6px; padding: 7px 10px; }
        .sdoc-link { display: flex; align-items: center; gap: 8px; flex: 1; min-width: 0; text-decoration: none; color: var(--text, #F5F5F5); font-size: 13px; }
        .sdoc-link:hover .sdoc-name { color: var(--accent, #C9A96E); }
        .sdoc-icon { font-size: 16px; flex-shrink: 0; }
        .sdoc-name { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .sdoc-del { background: none; border: none; color: var(--text-dim, #555); cursor: pointer; font-size: 14px; padding: 0 4px; line-height: 1; flex-shrink: 0; transition: color 0.15s; }
        .sdoc-del:hover { color: var(--danger, #F44336); }
        .doc-badge { display: inline-flex; align-items: center; color: var(--accent, #C9A96E); cursor: default; }

    `;
    document.head.appendChild(style);
})();

// dpHtml — для мультитабной формы добавления поставок (idx нужен для считывания полей по data-idx)
function dpHtml(id, idx, fieldClass, placeholder) {
    return `
        <div class="dp-wrap" id="dpw-${id}">
            <div class="dp-input-row">
                <input type="text" class="form-control ${fieldClass}" data-idx="${idx}" data-dpid="${id}"
                    placeholder="${placeholder}" autocomplete="off" readonly
                    onclick="dpOpen('${id}')" style="cursor:pointer">
                <input type="hidden" class="${fieldClass}-val" data-idx="${idx}">
                <button class="dp-clear" onclick="dpClear('${id}')" title="Очистить"><i data-lucide="x" style="width:15px;height:15px"></i></button>
            </div>
            <div class="dp-calendar" id="dpc-${id}"></div>
        </div>`;
}


function _saveShipmentsTab() {
    const active = document.querySelector('.tab[data-tab].active');
    if (active) window._openShipmentsTab = active.dataset.tab;
}

document.addEventListener('spa:navigated', () => {
    if (window._openShipmentsTab && document.querySelector('.tab[data-tab]')) {
        filterShipments(window._openShipmentsTab);
        window._openShipmentsTab = null;
    }
});

function filterShipments(tab) {
    document.querySelectorAll('.tab[data-tab]').forEach(t => t.classList.remove('active'));
    document.querySelector(`[data-tab="${tab}"]`)?.classList.add('active');
    const d = new Date();
    const todayStr = `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`;
    document.querySelectorAll('tbody tr[data-status]').forEach(row => {
        const s = row.dataset.status;
        const eta = row.dataset.eta || '';
        const isOverdueEta = eta && eta < todayStr && s !== 'Завершено';
        const isExtra = row.dataset.extraOverdue === '1';
        if (isExtra) { row.style.display = (tab === 'overdue-eta') ? '' : 'none'; return; }
        const show = tab==='all'
            || (tab==='transit' && s==='В пути')
            || (tab==='done' && s==='Завершено')
            || (tab==='alert' && (s==='⚠️' || s===''))
            || (tab==='overdue-eta' && isOverdueEta);
        row.style.display = show ? '' : 'none';
    });
}

// ===== ДОБАВЛЕНИЕ НЕСКОЛЬКИХ ПОСТАВОК =====
let _shipmentTabs = []; // [{id, data}]
let _activeShipmentTab = 0;
let _suppliers = [];
let _carriers = [];
let _accounts = [];
let _productNames = [];

async function openAddShipment() {
    // Сбрасываем привязку к сделке, если шторка открыта не из сделки
    if (!window._dealLinkKeep) window._dealLinkId = null;
    window._dealLinkKeep = false;
    [_suppliers, _carriers, _accounts] = await Promise.all([
        api('counterparties.php?type=supplier'),
        api('carriers.php'),
        api('bank.php?accounts=1')
    ]);
    // Загружаем имена из номенклатуры для автодополнения
    try {
        const prods = await api('products.php');
        _productNames = prods.map(p => p.name);
    } catch(e) { _productNames = []; }

    // Получаем следующий номер поставки (лёгкий запрос, без полного списка)
    let nextNum = 1;
    try {
        const r = await api('shipments.php?next_number=1');
        nextNum = r?.next || 1;
    } catch(e) {}

    _nextShipmentNum = nextNum;
    _shipmentTabs = [{ id: Date.now(), title: `Поставка ${nextNum}` }];
    _activeShipmentTab = 0;
    renderShipmentDrawer();
}

function renderShipmentDrawer() {
    const tabsHtml = _shipmentTabs.map((t, i) => `
        <div class="shipment-tab-btn-wrap" data-idx="${i}">
            <button class="shipment-tab-btn ${i===_activeShipmentTab?'active':''}" onclick="switchShipmentTab(${i})">${t.title}</button>${_shipmentTabs.length > 1 ? `<button class="shipment-tab-close" onclick="removeShipmentTab(${i})"><i data-lucide="x" style="width:15px;height:15px"></i></button>` : ''}
        </div>
    `).join('');

    const formsHtml = _shipmentTabs.map((t, i) => `
        <div class="shipment-tab-form" id="stf-${i}" style="${i!==_activeShipmentTab?'display:none':''}">
            ${renderShipmentForm(i)}
        </div>
    `).join('');

    openWideModal(`
        <div class="modal-header">
            <span class="modal-title">Новая поставка</span>
            <button class="btn-close" onclick="closeModal()"><i data-lucide="x" style="width:15px;height:15px"></i></button>
        </div>
        <div class="shipment-tabs-bar" style="display:flex;gap:4px;padding:12px 24px 0;border-bottom:1px solid var(--border);flex-wrap:wrap;align-items:flex-end">
            ${tabsHtml}
            <button class="shipment-tab-btn shipment-tab-add" onclick="addShipmentTab()">+ Ещё</button>
        </div>
        <div class="modal-body shipment-forms-container">
            ${formsHtml}
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" onclick="closeModal()">Отмена</button>
            <button class="btn btn-primary" onclick="saveAllShipments()">Сохранить все (${_shipmentTabs.length})</button>
        </div>
    `);
    if (window.lucide) lucide.createIcons();
    // Дата заказа = сегодня по умолчанию
    const _now = new Date();
    const _todayIso = `${_now.getFullYear()}-${String(_now.getMonth()+1).padStart(2,'0')}-${String(_now.getDate()).padStart(2,'0')}`;
    _shipmentTabs.forEach((t, i) => dpSelect(`od-${i}`, _todayIso));
}

function renderShipmentForm(idx) {
    const accOpts = _accounts.map(a => `<option value="${a.id}">${a.name}</option>`).join('');

    return `
        <!-- Секция: Основное -->
        <div class="modal-section">
            <div class="modal-section-title">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/></svg>
                Основное
            </div>
            <div class="sf-grid">
                <div class="form-group sf-full">
                    <label class="form-label">Название <span style="color:var(--text-dim);font-size:11px">(необязательно)</span></label>
                    <input type="text" class="form-control sf-name" data-idx="${idx}" placeholder="Например: Весенняя закупка 2025">
                </div>
                <div class="form-group">
                    <label class="form-label">Дата заказа</label>
                    ${dpHtml(`od-${idx}`, idx, 'sf-order-date', 'дд.мм.гггг')}
                </div>
                <div class="form-group">
                    <label class="form-label">ETA</label>
                    ${dpHtml(`eta-${idx}`, idx, 'sf-eta', 'дд.мм.гггг')}
                </div>
            </div>
        </div>

        <!-- Секция: Логистика -->
        <div class="modal-section">
            <div class="modal-section-title">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
                Логистика
            </div>
            <div class="sf-grid">
                <div class="form-group">
                    <label class="form-label">Поставщик</label>
                    <div style="display:flex;gap:8px">
                        <div class="searchable-select" style="flex:1" data-idx="${idx}" data-field="supplier">
                            <input type="text" class="form-control sf-supplier-search" data-idx="${idx}"
                                placeholder="Поиск поставщика..."
                                autocomplete="off"
                                oninput="filterSearchableSelect(this, 'supplier', ${idx})"
                                onfocus="showSearchableDropdown(this, 'supplier', ${idx})"
                                onblur="hideSearchableDropdown('supplier', ${idx})">
                            <input type="hidden" class="sf-supplier-val" data-idx="${idx}">
                            <div class="searchable-dropdown" id="sd-supplier-${idx}" style="display:none">
                                ${_suppliers.map(c => {
                                    const label = `${c.company_type} ${c.name}`;
                                    return `<div class="searchable-option" data-id="${c.id}" data-label="${escAttr(label)}" onmousedown="selectSearchableOption(this, 'supplier', ${idx})">${escAttr(label)}</div>`;
                                }).join('')}
                            </div>
                        </div>
                        <button class="btn btn-ghost" style="flex-shrink:0" onclick="openQuickAddCounterparty()">+ Новый</button>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">ТК</label>
                    <div class="searchable-select" data-idx="${idx}" data-field="carrier">
                        <input type="text" class="form-control sf-carrier-search" data-idx="${idx}"
                            placeholder="Поиск транспортной компании..."
                            autocomplete="off"
                            oninput="filterSearchableSelect(this, 'carrier', ${idx})"
                            onfocus="showSearchableDropdown(this, 'carrier', ${idx})"
                            onblur="hideSearchableDropdown('carrier', ${idx})">
                        <input type="hidden" class="sf-carrier-val" data-idx="${idx}">
                        <div class="searchable-dropdown" id="sd-carrier-${idx}" style="display:none">
                            ${_carriers.map(c => `<div class="searchable-option" data-id="${c.id}" data-label="${escAttr(c.name)}" onmousedown="selectSearchableOption(this, 'carrier', ${idx})">${escAttr(c.name)}</div>`).join('')}
                        </div>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Трек-номер</label>
                    <input type="text" class="form-control sf-tracking" data-idx="${idx}" placeholder="Номер отслеживания">
                </div>
                <div class="form-group">
                    <label class="form-label">Статус</label>
                    <select class="form-control sf-status" data-idx="${idx}">
                        <option value="Ожидает отправки">Ожидает отправки</option>
                        <option value="В пути">В пути</option>
                        <option value="Завершено">Завершено</option>
                        <option value="⚠️">Форс-мажор</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- Секция: Финансы -->
        <div class="modal-section">
            <div class="modal-section-title">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                Финансы
            </div>
            <div class="sf-grid">
                <div class="form-group">
                    <label class="form-label">Стоимость ТК (₽)</label>
                    <input type="number" class="form-control sf-carrier-cost" data-idx="${idx}" placeholder="0" min="0" oninput="calcShipmentTotal(${idx})">
                </div>
                <div class="form-group">
                    <label class="form-label">Счёт списания</label>
                    <select class="form-control sf-account" data-idx="${idx}">
                        <option value="">— не указывать —</option>${accOpts}
                    </select>
                </div>
            </div>
        </div>

        <!-- Секция: Товары -->
        <div class="modal-section">
            <div class="modal-section-title">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 2 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.29 7 12 12 20.71 7"/><line x1="12" y1="22" x2="12" y2="12"/></svg>
                Товары
            </div>
            <div class="sf-items-header">
                <span>Название</span><span>Кол-во</span><span>Цена ₽</span><span></span>
            </div>
            <div class="sf-items" data-idx="${idx}">
                ${makeItemRow(idx)}
            </div>
            <button class="btn-add-item" onclick="addShipmentItem(${idx})">+ Добавить товар</button>
            <div class="total-row" style="margin-top:12px">
                <span class="total-label">Итого:</span>
                <span class="total-value sf-total" data-idx="${idx}">0 ₽</span>
            </div>
        </div>
    `;
}

// Строит HTML одной строки товара с живым поиском
function makeItemRow(idx) {
    const opts = _productNames.map(n =>
        `<div class="searchable-option" data-label="${n}" onmousedown="selectProductOption(this, ${idx})">${n}</div>`
    ).join('');
    return `
        <div class="item-row" style="position:relative">
            <div style="position:relative;flex:2">
                <input type="text" class="form-control item-name-input" placeholder="Название товара"
                    autocomplete="off"
                    oninput="filterProductDropdown(this, ${idx})"
                    onfocus="showProductDropdown(this, ${idx})"
                    onblur="hideProductDropdown(this)">
                <div class="searchable-dropdown product-dropdown" style="display:none">
                    ${opts}
                </div>
            </div>
            <input type="number" class="form-control" placeholder="Кол-во" min="1" oninput="calcShipmentTotal(${idx})">
            <input type="number" class="form-control" placeholder="Цена ₽" min="0" oninput="calcShipmentTotal(${idx})">
            <button class="btn-remove-item" onclick="removeShipmentItem(this, ${idx})"><i data-lucide="x" style="width:15px;height:15px"></i></button>
        </div>`;
}

// ===== Searchable select helpers =====
function showSearchableDropdown(input, field, idx) {
    const dd = document.getElementById(`sd-${field}-${idx}`);
    if (dd) { dd.style.display = ''; filterSearchableSelect(input, field, idx); }
}
function hideSearchableDropdown(field, idx) {
    setTimeout(() => {
        const dd = document.getElementById(`sd-${field}-${idx}`);
        if (dd) dd.style.display = 'none';
    }, 150);
}
function filterSearchableSelect(input, field, idx) {
    const q = input.value.toLowerCase();
    const dd = document.getElementById(`sd-${field}-${idx}`);
    if (!dd) return;
    dd.style.display = '';
    dd.querySelectorAll('.searchable-option').forEach(opt => {
        opt.style.display = opt.dataset.label.toLowerCase().includes(q) ? '' : 'none';
    });
}
function selectSearchableOption(opt, field, idx) {
    const id = opt.dataset.id;
    const label = opt.dataset.label;
    const searchInput = document.querySelector(`.sf-${field}-search[data-idx="${idx}"]`);
    const hiddenInput = document.querySelector(`.sf-${field}-val[data-idx="${idx}"]`);
    if (searchInput) searchInput.value = label;
    if (hiddenInput) hiddenInput.value = id;
    const dd = document.getElementById(`sd-${field}-${idx}`);
    if (dd) dd.style.display = 'none';
}

// Безопасное экранирование для HTML-атрибутов
function escAttr(str) {
    return String(str || '').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

// ===== Product search in item rows =====
function showProductDropdown(input, idx) {
    const dd = input.nextElementSibling;
    if (dd) { dd.style.display = ''; filterProductDropdown(input, idx); }
}
function hideProductDropdown(input) {
    setTimeout(() => {
        const dd = input.nextElementSibling;
        if (dd) dd.style.display = 'none';
    }, 150);
}
function filterProductDropdown(input, idx) {
    const q = input.value.toLowerCase();
    const dd = input.nextElementSibling;
    if (!dd) return;
    dd.style.display = '';
    let hasVisible = false;
    dd.querySelectorAll('.searchable-option').forEach(opt => {
        const show = !q || opt.dataset.label.toLowerCase().includes(q);
        opt.style.display = show ? '' : 'none';
        if (show) hasVisible = true;
    });
    dd.style.display = hasVisible ? '' : 'none';
}
function selectProductOption(opt, idx) {
    const row = opt.closest('.item-row');
    const input = row.querySelector('.item-name-input');
    if (input) input.value = opt.dataset.label;
    const dd = opt.closest('.searchable-dropdown');
    if (dd) dd.style.display = 'none';
}

function switchShipmentTab(idx) {
    _activeShipmentTab = idx;
    document.querySelectorAll('.shipment-tab-btn-wrap .shipment-tab-btn').forEach(b => {
        const wrap = b.closest('[data-idx]');
        b.classList.toggle('active', parseInt(wrap.dataset.idx) === idx);
    });
    document.querySelectorAll('.shipment-tab-form').forEach(f => {
        f.style.display = f.id === `stf-${idx}` ? '' : 'none';
    });
}

function addShipmentTab() {
    const n = _nextShipmentNum + _shipmentTabs.length;
    const tab = { id: Date.now(), title: `Поставка ${n}` };
    _shipmentTabs.push(tab);
    const newIdx = _shipmentTabs.length - 1;

    // Добавляем кнопку вкладки
    const tabsBar = document.querySelector('.shipment-tabs-bar');
    const addBtn = tabsBar.querySelector('.shipment-tab-add');
    const wrap = document.createElement('div');
    wrap.className = 'shipment-tab-btn-wrap';
    wrap.dataset.idx = newIdx;
    wrap.innerHTML = `<button class="shipment-tab-btn" onclick="switchShipmentTab(${newIdx})">${tab.title}</button><button class="shipment-tab-close" onclick="removeShipmentTab(${newIdx})"><i data-lucide="x" style="width:15px;height:15px"></i></button>`;
    tabsBar.insertBefore(wrap, addBtn);

    // Показываем кнопки закрытия на всех вкладках
    tabsBar.querySelectorAll('.shipment-tab-btn-wrap').forEach(w => {
        if (!w.querySelector('.shipment-tab-close')) {
            const closeBtn = document.createElement('button');
            closeBtn.className = 'shipment-tab-close';
            closeBtn.innerHTML = '<i data-lucide="x" style="width:11px;height:11px"></i>';
            const wIdx = parseInt(w.dataset.idx);
            closeBtn.onclick = () => removeShipmentTab(wIdx);
            w.appendChild(closeBtn);
        }
    });

    // Добавляем форму
    const formsContainer = document.querySelector('.shipment-forms-container');
    const div = document.createElement('div');
    div.className = 'shipment-tab-form';
    div.id = `stf-${newIdx}`;
    div.style.display = 'none';
    div.innerHTML = renderShipmentForm(newIdx);
    formsContainer.appendChild(div);

    // Обновляем счётчик на кнопке сохранения
    const saveBtn = document.querySelector('.modal-footer .btn-primary');
    if (saveBtn) saveBtn.textContent = `Сохранить все (${_shipmentTabs.length})`;

    // Дата заказа = сегодня для новой вкладки
    const _now = new Date();
    const _todayIso = `${_now.getFullYear()}-${String(_now.getMonth()+1).padStart(2,'0')}-${String(_now.getDate()).padStart(2,'0')}`;
    dpSelect(`od-${newIdx}`, _todayIso);

    switchShipmentTab(newIdx);
}

function removeShipmentTab(idx) {
    if (_shipmentTabs.length <= 1) return;
    _shipmentTabs.splice(idx, 1);

    // Полная перерисовка — проще и надёжнее чем патчить индексы
    _activeShipmentTab = Math.min(idx, _shipmentTabs.length - 1);
    renderShipmentDrawer();
}

function addShipmentItem(idx) {
    const container = document.querySelector(`.sf-items[data-idx="${idx}"]`);
    container.insertAdjacentHTML('beforeend', makeItemRow(idx));
}

function removeShipmentItem(btn, idx) {
    const container = document.querySelector(`.sf-items[data-idx="${idx}"]`);
    if (container.children.length > 1) { btn.parentElement.remove(); calcShipmentTotal(idx); }
}

function calcShipmentTotal(idx) {
    const rows = document.querySelectorAll(`.sf-items[data-idx="${idx}"] .item-row`);
    let total = 0;
    rows.forEach(row => {
        const inputs = row.querySelectorAll('input');
        total += (parseFloat(inputs[1].value)||0) * (parseFloat(inputs[2].value)||0);
    });
    total += parseFloat(document.querySelector(`.sf-carrier-cost[data-idx="${idx}"]`)?.value||0);
    const el = document.querySelector(`.sf-total[data-idx="${idx}"]`);
    if (el) el.textContent = formatPrice(total);
}

let _savingShipments = false;
async function saveAllShipments() {
    if (_savingShipments) return;   // защита от двойного клика по «Сохранить»
    const shipments = [];
    let valid = true;

    _shipmentTabs.forEach((t, idx) => {
        const form = document.getElementById(`stf-${idx}`);
        if (!form) return;
        const orderDate = form.querySelector('.sf-order-date-val')?.value;
        if (!orderDate) { showToast(`Поставка ${idx+1}: укажите дату заказа`, 'error'); valid = false; return; }

        const status = form.querySelector('.sf-status')?.value || 'Ожидает отправки';
        const eta = form.querySelector('.sf-eta-val')?.value;
        if (status !== 'Ожидает отправки' && !eta) { showToast(`Поставка ${idx+1}: укажите ETA`, 'error'); valid = false; return; }

        const items = [];
        form.querySelectorAll('.sf-items .item-row').forEach(row => {
            const inputs = row.querySelectorAll('input');
            const name = row.querySelector('.item-name-input')?.value.trim() || inputs[0]?.value.trim();
            if (name) items.push({ name, quantity: parseInt(inputs[1]?.value)||1, purchase_price: parseFloat(inputs[2]?.value)||0 });
        });
        if (items.length === 0) { showToast(`Поставка ${idx+1}: добавьте товары`, 'error'); valid = false; return; }

        shipments.push({
            name: form.querySelector('.sf-name')?.value.trim() || null,
            order_date: orderDate, eta: eta||null, status,
            counterparty_id: form.querySelector('.sf-supplier-val')?.value || null,
            carrier_id: form.querySelector('.sf-carrier-val')?.value || null,
            tracking: form.querySelector('.sf-tracking')?.value || '',
            carrier_cost: form.querySelector('.sf-carrier-cost')?.value || 0,
            account_id: form.querySelector('.sf-account')?.value || null,
            items
        });
    });

    if (!valid || shipments.length === 0) return;

    _savingShipments = true;
    const saveBtn = document.querySelector('.modal-footer .btn-primary');
    if (saveBtn) { saveBtn.disabled = true; saveBtn.textContent = 'Сохранение…'; }
    try {
        const res = await api('shipments.php', 'POST', { shipments });
        // Поставка создана из сделки — привязываем её и переводим сделку в «Заказано»
        if (window._dealLinkId && res?.ids?.length) {
            await api('deals.php', 'POST', { action: 'link_shipment', id: window._dealLinkId, shipment_id: res.ids[0] });
            window._dealLinkId = null;
        }
        showToast(`Сохранено поставок: ${shipments.length}`);
        closeModal();
        setTimeout(reloadPage, 500);
    } catch (e) {
        // Ошибка — возвращаем кнопку, чтобы можно было повторить
        if (saveBtn) { saveBtn.disabled = false; saveBtn.textContent = `Сохранить все (${_shipmentTabs.length})`; }
    } finally {
        _savingShipments = false;
    }
}

// ===== PDF DOCUMENTS =====

function renderShipmentDocs(docs, shipmentId) {
    const list = docs.map(d => `
        <div class="sdoc-item" id="sdoc-${d.id}">
            <a href="api/shipment_docs.php?download=${d.id}" target="_blank" class="sdoc-link">
                <i data-lucide="file-text" style="width:14px;height:14px;color:var(--accent)"></i>
                <span class="sdoc-name">${d.original_name}</span>
            </a>
            <button class="sdoc-del" onclick="deleteShipmentDoc(${d.id}, ${shipmentId})" title="Удалить"><i data-lucide="x" style="width:15px;height:15px"></i></button>
        </div>`).join('');
    return `
        <div class="sdoc-section">
            <div class="sdoc-header">
                <span class="detail-label">Документы</span>
                <label class="sdoc-upload-btn" title="Прикрепить PDF">
                    + Прикрепить
                    <input type="file" accept="application/pdf,.pdf" style="display:none" onchange="uploadShipmentDoc(this, ${shipmentId})">
                </label>
            </div>
            <div class="sdoc-list" id="sdoc-list-${shipmentId}">${list || '<span class="sdoc-empty">Нет документов</span>'}</div>
        </div>`;
}

async function uploadShipmentDoc(input, shipmentId) {
    const file = input.files[0];
    if (!file) return;
    const btn = input.closest('label');
    btn.textContent = 'Загрузка...';
    const fd = new FormData();
    fd.append('pdf', file);
    fd.append('shipment_id', shipmentId);
    try {
        const res = await fetch('api/shipment_docs.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.error) { alert(data.error); return; }
        const list = document.getElementById(`sdoc-list-${shipmentId}`);
        const empty = list.querySelector('.sdoc-empty');
        if (empty) empty.remove();
        const item = document.createElement('div');
        item.className = 'sdoc-item';
        item.id = `sdoc-${data.id}`;
        item.innerHTML = `
            <a href="api/shipment_docs.php?download=${data.id}" target="_blank" class="sdoc-link">
                <i data-lucide="file-text" style="width:14px;height:14px;color:var(--accent)"></i>
                <span class="sdoc-name">${data.original_name}</span>
            </a>
            <button class="sdoc-del" onclick="deleteShipmentDoc(${data.id}, ${shipmentId})" title="Удалить"><i data-lucide="x" style="width:15px;height:15px"></i></button>`;
        list.appendChild(item);
    } catch(e) { alert('Ошибка загрузки'); }
    btn.innerHTML = '+ Прикрепить<input type="file" accept="application/pdf,.pdf" style="display:none" onchange="uploadShipmentDoc(this, ' + shipmentId + ')">';
}

async function deleteShipmentDoc(docId, shipmentId) {
    if (!confirm('Удалить документ?')) return;
    await fetch(`api/shipment_docs.php?id=${docId}`, { method: 'DELETE' });
    const el = document.getElementById(`sdoc-${docId}`);
    if (el) {
        el.remove();
        const list = document.getElementById(`sdoc-list-${shipmentId}`);
        if (list && !list.querySelector('.sdoc-item')) {
            list.innerHTML = '<span class="sdoc-empty">Нет документов</span>';
        }
    }
}

// Карточка поставки
async function openShipment(id) {
    const [shipment, docs] = await Promise.all([
        api(`shipments.php?id=${id}`),
        fetch(`api/shipment_docs.php?id=${id}`).then(r => r.json()).catch(() => [])
    ]);
    const itemsHtml = shipment.items.map(item => `
        <tr>
            <td>${item.name}${item.warehouse_id ? ` <button class="btn-chain-link" onclick="openProductChain(${item.warehouse_id})" title="Движение">🔗</button>` : ''}</td>
            <td>${item.quantity} шт</td>
            <td>${formatPrice(item.purchase_price)}</td>
            <td>${formatPrice(item.quantity * item.purchase_price)}</td>
        </tr>`).join('');

    const shipTitle = shipment.name ? `${shipment.name} <span style="font-size:12px;color:var(--text-dim);font-weight:400">#${shipment.display_num||shipment.id}</span>` : `Поставка #${shipment.display_num||shipment.id}`;
    openWideModal(`
        <div class="modal-header">
            <span class="modal-title">${shipTitle}</span>
            <button class="btn-close" onclick="closeModal()"><i data-lucide="x" style="width:15px;height:15px"></i></button>
        </div>
        <div class="modal-body">
            <div class="detail-grid">
                <div class="detail-item"><span class="detail-label">Дата заказа</span><span class="detail-value">${formatDate(shipment.order_date)}</span></div>
                <div class="detail-item"><span class="detail-label">ETA</span><span class="detail-value">${formatDate(shipment.eta)}</span></div>
                <div class="detail-item"><span class="detail-label">Поставщик</span><span class="detail-value">${shipment.counterparty||'—'}</span></div>
                <div class="detail-item"><span class="detail-label">ТК</span><span class="detail-value">${shipment.carrier||'—'}</span></div>
                <div class="detail-item"><span class="detail-label">Трек</span><span class="detail-value">${shipment.tracking||'—'}</span></div>
                <div class="detail-item"><span class="detail-label">Статус</span><span class="detail-value">${getStatusBadge(shipment.status)}</span></div>
                <div class="detail-item"><span class="detail-label">Стоимость ТК</span><span class="detail-value">${formatPrice(shipment.carrier_cost)}</span></div>
                <div class="detail-item"><span class="detail-label">Дней с заказа</span><span class="detail-value">${daysSince(shipment.order_date)} дн.</span></div>
            </div>
            <div class="table-wrapper">
                <table><thead><tr><th>Товар</th><th>Кол-во</th><th>Цена</th><th>Сумма</th></tr></thead>
                <tbody>${itemsHtml}<tr class="summary-row"><td colspan="3">Итого</td><td>${formatPrice(shipment.total)}</td></tr></tbody></table>
            </div>
            ${renderShipmentDocs(docs, shipment.id)}
            ${await renderReminderSection('shipment', shipment.id)}
            <div id="entity-notes-shipment-${shipment.id}"><div class="notes-loading">Загрузка заметок…</div></div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" onclick="closeModal()">Закрыть</button>
            ${shipment.status === 'В пути' ? `<button class="btn btn-ghost" style="color:var(--accent);border-color:var(--accent)" onclick="openCreateSaleFromShipment(${shipment.id})"><i data-lucide="shopping-cart" style="width:13px;height:13px;margin-right:4px"></i>Создать продажу</button>` : ''}
            <button class="btn btn-ghost" onclick="closeModal(); openEditShipment(${shipment.id})">Изменить</button>
            <button class="btn btn-danger" onclick="deleteShipment(${shipment.id})">Удалить</button>
        </div>
    `);
    if (window.lucide) lucide.createIcons();
    if (typeof loadEntityNotes === 'function') loadEntityNotes('shipment', shipment.id);
}

// Редактирование поставки — полное с товарами
async function openEditShipment(id) {
    const [shipment, suppliers, carriers] = await Promise.all([
        api(`shipments.php?id=${id}`),
        api('counterparties.php?type=supplier'),
        api('carriers.php'),
    ]);
    let accounts = [];
    try { accounts = await api('bank.php?accounts=1'); } catch(e) {}
    let productNames = [];
    try { productNames = (await api('products.php')).map(p => p.name); } catch(e) {}

    const cpOpts = suppliers.map(c => `<option value="${c.id}" ${shipment.counterparty_id==c.id?'selected':''}>${c.company_type} ${c.name}</option>`).join('');
    const crOpts = carriers.map(c => `<option value="${c.id}" ${shipment.carrier_id==c.id?'selected':''}>${c.name}</option>`).join('');
    const accOpts = accounts.map(a => `<option value="${a.id}" ${shipment.account_id==a.id?'selected':''}>${a.name}</option>`).join('');
    const statusOpts = ['Ожидает отправки','В пути','Завершено','⚠️'].map(s => `<option value="${s}" ${shipment.status===s?'selected':''}>${s==='⚠️'?'Форс-мажор':s}</option>`).join('');
    const originalStatus = shipment.status;

    const itemsHtml = (shipment.items||[]).map(item => `
        <div class="item-row">
            <input type="text" class="form-control" value="${item.name}" list="edit-shipment-items-list">
            <input type="number" class="form-control" value="${item.quantity}" min="1" oninput="calcEditTotal()">
            <input type="number" class="form-control" value="${item.purchase_price}" min="0" oninput="calcEditTotal()">
            <button class="btn-remove-item" onclick="this.parentElement.remove(); calcEditTotal()"><i data-lucide="x" style="width:15px;height:15px"></i></button>
        </div>`).join('');

    openDrawer(`
        <div class="drawer-header">
            <span class="drawer-title">Редактировать поставку #${shipment.display_num||shipment.id}</span>
            <button class="btn-close" onclick="closeDrawer()"><i data-lucide="x" style="width:15px;height:15px"></i></button>
        </div>
        <div class="drawer-body">
            <div class="form-group"><label class="form-label">Название <span style="color:var(--text-dim);font-size:11px">(необязательно)</span></label><input type="text" class="form-control" id="e-name" value="${shipment.name||''}" placeholder="Например: Весенняя закупка 2025"></div>
            <div class="form-group"><label class="form-label">Дата заказа</label>${dpField('e-order-date', shipment.order_date)}</div>
            <div class="form-group"><label class="form-label">ETA <span style="font-size:11px;color:var(--text-dim)">(необязательно)</span></label>${dpField('e-eta', shipment.eta||'')}</div>
            <div class="form-group"><label class="form-label">Поставщик</label>
                <select class="form-control" id="e-counterparty"><option value="">— выбрать —</option>${cpOpts}</select></div>
            <div class="form-group"><label class="form-label">ТК</label>
                <select class="form-control" id="e-carrier"><option value="">— выбрать —</option>${crOpts}</select></div>
            <div class="form-group"><label class="form-label">Трек-номер</label><input type="text" class="form-control" id="e-tracking" value="${shipment.tracking||''}"></div>
            <div class="form-group"><label class="form-label">Статус</label><select class="form-control" id="e-status" data-original="${originalStatus}">${statusOpts}</select></div>
            <div class="form-group">
                <label class="form-label">Товары</label>
                <div class="items-table" id="edit-items-container">${itemsHtml}</div>
                <button class="btn-add-item" onclick="addEditItem()">+ Добавить товар</button>
            </div>
            <div class="form-group"><label class="form-label">Стоимость ТК (₽)</label>
                <input type="number" class="form-control" id="e-carrier-cost" value="${shipment.carrier_cost||0}" oninput="calcEditTotal()"></div>
            <div class="form-group"><label class="form-label">Счёт списания</label>
                <select class="form-control" id="e-account"><option value="">— не указывать —</option>${accOpts}</select></div>
            <div class="total-row"><span class="total-label">Итого:</span><span class="total-value" id="edit-total">0 ₽</span></div>
        </div>
        <div class="drawer-footer">
            <button class="btn btn-ghost" onclick="closeDrawer()">Отмена</button>
            <button class="btn btn-primary" onclick="updateShipment(${shipment.id})">Сохранить</button>
        </div>
        <datalist id="edit-shipment-items-list">
            ${productNames.map(n => `<option value="${n}">`).join('')}
        </datalist>
    `);
    setTimeout(calcEditTotal, 50);
}

function addEditItem() {
    const container = document.getElementById('edit-items-container');
    const row = document.createElement('div');
    row.className = 'item-row';
    row.innerHTML = `
        <input type="text" class="form-control" placeholder="Название товара" list="edit-shipment-items-list">
        <input type="number" class="form-control" placeholder="Кол-во" min="1" value="1" oninput="calcEditTotal()">
        <input type="number" class="form-control" placeholder="Цена ₽" min="0" value="0" oninput="calcEditTotal()">
        <button class="btn-remove-item" onclick="this.parentElement.remove(); calcEditTotal()"><i data-lucide="x" style="width:15px;height:15px"></i></button>
    `;
    container.appendChild(row);
}

function calcEditTotal() {
    const rows = document.querySelectorAll('#edit-items-container .item-row');
    let total = 0;
    rows.forEach(row => {
        const inputs = row.querySelectorAll('input');
        total += (parseFloat(inputs[1]?.value)||0) * (parseFloat(inputs[2]?.value)||0);
    });
    total += parseFloat(document.getElementById('e-carrier-cost')?.value||0);
    const el = document.getElementById('edit-total');
    if (el) el.textContent = formatPrice(total);
}

async function updateShipment(id) {
    const newStatus    = document.getElementById('e-status')?.value;
    const origStatus   = document.getElementById('e-status')?.dataset.original;

    if (origStatus === 'Завершено' && newStatus !== 'Завершено') {
        confirmAction(
            'Поставка уже завершена. Товары будут убраны со склада. Продолжить?',
            () => _doUpdateShipment(id)
        );
        return;
    }
    await _doUpdateShipment(id);
}

async function _doUpdateShipment(id) {
    const items = [];
    document.querySelectorAll('#edit-items-container .item-row').forEach(row => {
        const inputs = row.querySelectorAll('input');
        const name = inputs[0]?.value.trim();
        if (name) items.push({ name, quantity: parseInt(inputs[1]?.value)||1, purchase_price: parseFloat(inputs[2]?.value)||0 });
    });
    if (items.length === 0) { showToast('Добавьте товары', 'error'); return; }

    await api('shipments.php', 'PUT', {
        id, items,
        name: document.getElementById('e-name')?.value.trim() || null,
        order_date: document.getElementById('e-order-date').value,
        eta: document.getElementById('e-eta').value || null,
        counterparty_id: document.getElementById('e-counterparty').value || null,
        carrier_id: document.getElementById('e-carrier').value || null,
        tracking: document.getElementById('e-tracking').value,
        status: document.getElementById('e-status').value,
        carrier_cost: document.getElementById('e-carrier-cost').value || 0,
        account_id: document.getElementById('e-account').value || null
    });
    showToast('Поставка обновлена'); closeDrawer(); _saveShipmentsTab(); setTimeout(reloadPage, 500);
}

function deleteShipment(id) {
    confirmAction('Удалить поставку? Все товары из неё будут удалены со склада.', async () => {
        await api(`shipments.php?id=${id}`, 'DELETE');
        showToast('Поставка удалена'); _saveShipmentsTab(); setTimeout(reloadPage, 500);
    });
}

function toggleStatusDropdown(id) {
    const existing = document.getElementById('floating-status-menu');
    if (existing) { existing.remove(); if (existing.dataset.id == id) return; }
    const badge = document.querySelector(`#sd-${id} .badge`);
    const rect = badge.getBoundingClientRect();
    const menu = document.createElement('div');
    menu.id = 'floating-status-menu'; menu.dataset.id = id;
    menu.style.cssText = `position:fixed;z-index:9999;background:var(--card-bg);border:1px solid var(--border);border-radius:var(--radius);min-width:140px;box-shadow:0 8px 24px rgba(0,0,0,0.4);overflow:hidden;left:${rect.left}px;top:${rect.bottom+6}px`;
    [['Ожидает отправки','Ожидает'],['В пути','В пути'],['Завершено','Завершено'],['⚠️','Форс-мажор']].forEach(([v,l]) => {
        const item = document.createElement('div');
        item.textContent = l;
        item.style.cssText = 'padding:9px 14px;font-size:13px;color:#888;cursor:pointer;transition:0.2s;font-family:Inter,sans-serif';
        item.onmouseover = () => { item.style.color='#C9A96E'; item.style.background='rgba(255,255,255,0.04)'; };
        item.onmouseout = () => { item.style.color='#888'; item.style.background=''; };
        item.onclick = () => { changeStatus(id, v); menu.remove(); };
        menu.appendChild(item);
    });
    document.body.appendChild(menu);
    setTimeout(() => { const r=menu.getBoundingClientRect(); if(r.bottom>window.innerHeight) menu.style.top=(rect.top-r.height-6)+'px'; }, 0);
}

async function changeStatus(id, status, currentStatus = null) {
    if (currentStatus === 'Завершено' && status !== 'Завершено') {
        confirmAction(
            'Поставка уже завершена. Товары будут убраны со склада. Продолжить?',
            async () => {
                await api('shipments.php', 'PUT', { id, status });
                showToast('Статус обновлён'); _saveShipmentsTab(); setTimeout(reloadPage, 500);
            }
        );
        return;
    }
    await api('shipments.php', 'PUT', { id, status });
    showToast('Статус обновлён'); _saveShipmentsTab(); setTimeout(reloadPage, 500);
}

document.addEventListener('click', e => {
    if (!e.target.closest('.status-dropdown') && !e.target.closest('#floating-status-menu')) {
        document.getElementById('floating-status-menu')?.remove();
    }
});

function openQuickAddCounterparty() {
    openModal(`
        <div class="modal-header">
            <span class="modal-title">Новый поставщик</span>
            <button class="btn-close" onclick="closeModal()"><i data-lucide="x" style="width:15px;height:15px"></i></button>
        </div>
        <div class="modal-body">
            <div class="form-group"><label class="form-label">Тип компании</label>
                <select class="form-control" id="qcp-type"><option>ИП</option><option>ООО</option><option>ПАО</option><option>АО</option></select></div>
            <div class="form-group"><label class="form-label">Название</label>
                <input type="text" class="form-control" id="qcp-name" placeholder="Название или ФИО"></div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" onclick="closeModal()">Отмена</button>
            <button class="btn btn-primary" onclick="saveQuickCounterparty()">Сохранить</button>
        </div>
    `);
}

async function saveQuickCounterparty() {
    const name = document.getElementById('qcp-name').value.trim();
    if (!name) { showToast('Введите название', 'error'); return; }
    const result = await api('counterparties.php', 'POST', {
        company_type: document.getElementById('qcp-type').value,
        name, type: 'Поставщик', requisites: '', comment: ''
    });
    // Обновляем все селекты поставщиков в форме
    document.querySelectorAll('.sf-supplier').forEach(sel => {
        const opt = new Option(`${document.getElementById('qcp-type').value} ${name}`, result.id, true, true);
        sel.appendChild(opt);
    });
    showToast('Поставщик добавлен');
    closeModal();
}
// ===== ПРОДАЖА ИЗ ПОСТАВКИ "В ПУТИ" =====

async function openCreateSaleFromShipment(shipmentId) {
    const [shipment, buyers, accounts] = await Promise.all([
        api(`shipments.php?id=${shipmentId}`),
        api('counterparties.php?type=buyer').catch(() => []),
        api('bank.php?accounts=1').catch(() => [])
    ]);

    const buyerOpts = buyers.map(c =>
        `<option value="${c.id}" data-type="${c.company_type}">${c.company_type} ${c.name}</option>`
    ).join('');
    const accountOpts = accounts.map(a => `<option value="${a.id}">${a.name}</option>`).join('');

    const itemsRows = shipment.items.map((item, i) => {
        const avail = item.available_qty ?? item.quantity;
        return `
        <div class="sale-receipt-item-row" id="fs-row-${i}">
            <div style="font-size:12px;font-weight:500;color:#1a1a1a;padding:5px 0;display:flex;align-items:center;gap:6px">
                ${_esc(item.name)}
                <span style="font-size:10px;color:#aaa;font-weight:400">· ${item.quantity} шт</span>
            </div>
            <input type="number" class="form-control" id="fs-qty-${i}"
                data-item-id="${item.id}" data-max="${avail}"
                value="0" min="0" max="${avail}"
                oninput="calcFromShipmentTotal()">
            <input type="number" class="form-control" id="fs-price-${i}"
                placeholder="Цена" min="0" step="0.01"
                oninput="calcFromShipmentTotal()">
            <div class="item-row-sum" id="fs-sum-${i}">—</div>
            <div></div>
        </div>`;
    }).join('');

    openModal(`
        <div class="modal-header">
            <span class="modal-title">Продажа из поставки</span>
            <button class="btn-close" onclick="closeModal()"><i data-lucide="x" style="width:15px;height:15px"></i></button>
        </div>
        <div class="modal-body" style="padding:0;display:flex;flex-direction:column">

            <!-- ЧЕК -->
            <div class="sale-receipt">
                <div class="sale-receipt-logo">
                    <img src="assets/img/logo.png" alt="byBuka">
                </div>
                <div class="sale-receipt-tagline">byBuka</div>
                <hr class="sale-receipt-divider-top">

                <div class="sale-receipt-row">
                    <span class="sale-receipt-row-label">Дата</span>
                    ${dpField('fs-date')}
                </div>
                <div class="sale-receipt-row">
                    <span class="sale-receipt-row-label">Покупатель</span>
                    <select class="form-control" id="fs-counterparty" onchange="onFsBuyerChange()" style="flex:1">
                        <option value="">— выбрать —</option>
                        ${buyerOpts}
                        <option value="__manual__" data-type="Розничный покупатель">Ввести вручную (розница)</option>
                    </select>
                </div>
                <div class="sale-receipt-row" id="fs-buyer-name-group" style="display:none">
                    <span class="sale-receipt-row-label">Имя</span>
                    <input type="text" class="form-control" id="fs-buyer-name" placeholder="Розничный покупатель" style="flex:1">
                </div>

                <hr class="sale-receipt-divider-top">

                <div class="sale-receipt-items-header">
                    <span>Товар</span><span>Кол</span><span>Цена</span><span style="text-align:right">Сумма</span><span></span>
                </div>
                <div id="fs-items-container">${itemsRows}</div>

                <div class="sale-receipt-total-block">
                    <span class="sale-receipt-total-label">Итого</span>
                    <span class="sale-receipt-total-value" id="fs-total">0 ₽</span>
                </div>
            </div>
            <svg class="sale-receipt-zigzag" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 420 14" preserveAspectRatio="none" width="100%" height="14">
                <path d="M0,0 L420,0 L420,7 L413,14 L406,7 L399,14 L392,7 L385,14 L378,7 L371,14 L364,7 L357,14 L350,7 L343,14 L336,7 L329,14 L322,7 L315,14 L308,7 L301,14 L294,7 L287,14 L280,7 L273,14 L266,7 L259,14 L252,7 L245,14 L238,7 L231,14 L224,7 L217,14 L210,7 L203,14 L196,7 L189,14 L182,7 L175,14 L168,7 L161,14 L154,7 L147,14 L140,7 L133,14 L126,7 L119,14 L112,7 L105,14 L98,7 L91,14 L84,7 L77,14 L70,7 L63,14 L56,7 L49,14 L42,7 L35,14 L28,7 L21,14 L14,7 L7,14 L0,7 Z" fill="#f9f7f4"/>
            </svg>

            <!-- ПОД ЧЕКОМ -->
            <div class="sale-receipt-bottom">
                <div style="background:rgba(167,139,250,0.1);border:1px solid rgba(167,139,250,0.25);border-radius:8px;padding:10px 14px;margin-bottom:14px;font-size:12px;color:#A78BFA;display:flex;align-items:flex-start;gap:8px">
                    <i data-lucide="info" style="width:13px;height:13px;flex-shrink:0;margin-top:1px"></i>
                    <span>Товар ещё в пути. Запись попадёт на склад как <b>Зарезервировано</b>, при завершении поставки — автоматически станет <b>Продан</b>.</span>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                    <div class="form-group" style="margin:0">
                        <label class="form-label">Статус оплаты</label>
                        <select class="form-control" id="fs-status">
                            <option value="Счёт выставлен">Счёт выставлен</option>
                            <option value="Оплачено">Оплачено</option>
                        </select>
                    </div>
                    <div class="form-group" style="margin:0">
                        <label class="form-label">Счёт зачисления</label>
                        <select class="form-control" id="fs-account">
                            <option value="">— не указывать —</option>
                            ${accountOpts}
                        </select>
                    </div>
                </div>
                <div class="form-group" id="fs-acquiring-group" style="display:none;margin-top:12px">
                    <label class="form-label">% эквайринга</label>
                    <input type="number" class="form-control" id="fs-acquiring" value="1.22" min="0" max="100" step="0.01">
                    <div style="font-size:11px;color:var(--text-dim);margin-top:4px">Комиссия вычтется при зачислении</div>
                </div>
            </div>

        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" onclick="closeModal()">Отмена</button>
            <button class="btn btn-primary" onclick="saveFromShipmentSale(${shipmentId}, ${shipment.items.length})">Создать продажу</button>
        </div>
    `);
    if (window.lucide) lucide.createIcons();
}


function onFsBuyerChange() {
    const sel = document.getElementById('fs-counterparty');
    const opt = sel?.options[sel.selectedIndex];
    const isRetail = opt && (opt.dataset.type === 'Розничный покупатель' || opt.value === '__manual__');
    const isManual = opt?.value === '__manual__';
    const grpName = document.getElementById('fs-buyer-name-group');
    const grpAcq  = document.getElementById('fs-acquiring-group');
    if (grpName) grpName.style.display = isManual ? '' : 'none';
    if (grpAcq)  grpAcq.style.display  = isRetail ? '' : 'none';
}

function calcFromShipmentTotal() {
    let total = 0;
    document.querySelectorAll('[id^="fs-qty-"]').forEach(el => {
        const i     = el.id.replace('fs-qty-', '');
        const qty   = parseFloat(el.value) || 0;
        const price = parseFloat(document.getElementById(`fs-price-${i}`)?.value) || 0;
        const sum   = qty * price;
        total += sum;
        const sumEl = document.getElementById(`fs-sum-${i}`);
        if (sumEl) sumEl.textContent = sum > 0 ? sum.toLocaleString('ru-RU') + ' ₽' : '—';
    });
    const el = document.getElementById('fs-total');
    if (el) el.textContent = total.toLocaleString('ru-RU') + ' ₽';
}

async function saveFromShipmentSale(shipmentId, itemCount) {
    const items = [];
    for (let i = 0; i < itemCount; i++) {
        const qtyEl = document.getElementById(`fs-qty-${i}`);
        if (!qtyEl) continue;
        const qty   = parseInt(qtyEl.value) || 0;
        const price = parseFloat(document.getElementById(`fs-price-${i}`)?.value) || 0;
        const max   = parseInt(qtyEl.dataset.max) || 0;
        if (qty <= 0) continue;
        if (qty > max) { showToast(`Количество превышает доступное: ${qtyEl.closest('tr').querySelector('td').textContent.trim()}`, 'error'); return; }
        items.push({ shipment_item_id: qtyEl.dataset.itemId, quantity: qty, sale_price: price });
    }
    if (!items.length) { showToast('Укажите количество хотя бы для одного товара', 'error'); return; }

    const cpVal     = document.getElementById('fs-counterparty')?.value;
    const cpId      = (cpVal && cpVal !== '__manual__') ? cpVal : null;
    const buyerName = (cpVal === '__manual__') ? (document.getElementById('fs-buyer-name')?.value.trim() || null) : null;
    const status    = document.getElementById('fs-status')?.value || 'Счёт выставлен';
    const date      = document.getElementById('fs-date')?.value;
    const accountId = document.getElementById('fs-account')?.value || null;
    const acqGroup  = document.getElementById('fs-acquiring-group');
    const acquiringPct = (acqGroup && acqGroup.style.display !== 'none')
        ? (parseFloat(document.getElementById('fs-acquiring')?.value) || 1.22) : null;

    if (!date) { showToast('Укажите дату', 'error'); return; }

    const res = await api('sales.php', 'POST', {
        action: 'from_shipment',
        shipment_id: shipmentId,
        counterparty_id: cpId,
        buyer_name: buyerName,
        status, sale_date: date, items,
        account_id: accountId,
        acquiring_pct: acquiringPct
    });
    if (!res?.success) return;
    showToast('Продажа создана', 'success');
    closeModal();
    _saveShipmentsTab();
    setTimeout(reloadPage, 400);
}
