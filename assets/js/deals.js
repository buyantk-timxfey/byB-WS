// ═══════════════════════════════════════════════════════════════════════════
// DEALS.JS — сделки: заявка → поиск → заказано → завершено
// ═══════════════════════════════════════════════════════════════════════════

const DEAL_STAGES = ['Заявка', 'Поиск', 'Заказано', 'Завершено'];
let _deals = [];
let _dealsShowDone = false;
let _dealsQuery = '';

async function initDealsPage() {
    await loadDeals();
}

async function loadDeals() {
    _deals = await api('deals.php') || [];
    renderDealsBoard();
}

function _dealFmt(v) { return new Intl.NumberFormat('ru-RU').format(Math.round(+v || 0)); }

function _dealEsc(str) {
    return String(str ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function _dealDate(d) {
    if (!d) return '';
    const p = String(d).split('-');
    return p.length === 3 ? `${p[2]}.${p[1]}.${p[0]}` : d;
}

// Лучший вариант = минимальная полная стоимость (товар + доставка)
function _bestOption(deal) {
    if (!deal.options || !deal.options.length) return null;
    const chosen = deal.options.find(o => +o.is_chosen === 1);
    if (chosen) return chosen;
    return deal.options.reduce((b, o) => (+o.price + +o.delivery_cost) < (+b.price + +b.delivery_cost) ? o : b);
}

// Выгода против конкурента: цена конкурента − (товар + доставка)
function _optionSaving(deal, opt) {
    if (deal.competitor_price == null || !opt) return null;
    return +deal.competitor_price - (+opt.price + +opt.delivery_cost);
}

// ── Доска ─────────────────────────────────────────────────────────────────────
function renderDealsBoard() {
    const board = document.getElementById('deals-board');
    if (!board) return;

    const stages = _dealsShowDone ? DEAL_STAGES : DEAL_STAGES.filter(s => s !== 'Завершено');
    board.style.gridTemplateColumns = `repeat(${stages.length}, 1fr)`;

    const q = _dealsQuery.toLowerCase();
    const visible = _deals.filter(d =>
        !q ||
        (d.title || '').toLowerCase().includes(q) ||
        (d.counterparty_name || '').toLowerCase().includes(q) ||
        (d.note || '').toLowerCase().includes(q)
    );

    board.innerHTML = stages.map(stage => {
        const items = visible.filter(d => d.stage === stage);
        return `<div class="deals-col">
            <div class="deals-col-header">
                <span>${stage}</span>
                <span class="deals-col-count">${items.length}</span>
            </div>
            ${items.length
                ? items.map(d => renderDealCard(d)).join('')
                : `<div class="deals-col-empty">Пусто</div>`}
        </div>`;
    }).join('');

    if (window.lucide) lucide.createIcons();
}

function renderDealCard(d) {
    const best   = _bestOption(d);
    const saving = _optionSaving(d, best);
    const today  = new Date().toISOString().slice(0, 10);
    const overdue = d.deadline && d.deadline < today && d.stage !== 'Завершено';

    return `<div class="deal-card" onclick="openDealDetail(${d.id})">
        <div class="deal-card-title">${_dealEsc(d.title)}${+d.quantity > 1 ? ` <span class="deal-card-qty">×${d.quantity}</span>` : ''}</div>
        ${d.counterparty_name ? `<div class="deal-card-cp">${_dealEsc(d.counterparty_name)}</div>` : ''}
        <div class="deal-card-meta">
            ${d.competitor_price != null ? `<span title="Цена конкурента"><i data-lucide="crosshair" style="width:11px;height:11px"></i> ${_dealFmt(d.competitor_price)} ₽</span>` : ''}
            ${d.options.length ? `<span title="Найдено вариантов"><i data-lucide="search" style="width:11px;height:11px"></i> ${d.options.length}</span>` : ''}
            ${d.deadline ? `<span style="${overdue ? 'color:var(--danger)' : ''}" title="Дедлайн"><i data-lucide="clock" style="width:11px;height:11px"></i> ${_dealDate(d.deadline)}</span>` : ''}
        </div>
        ${best && saving != null ? `
        <div class="deal-card-saving ${saving > 0 ? 'pos' : 'neg'}">
            ${saving > 0 ? 'Дешевле конкурента на ' + _dealFmt(saving) + ' ₽' : 'Дороже конкурента на ' + _dealFmt(-saving) + ' ₽'}
        </div>` : ''}
        ${d.shipment_id ? `<div class="deal-card-shipment"><i data-lucide="package" style="width:11px;height:11px"></i> Поставка #${d.shipment_id}${d.shipment_status ? ' · ' + _dealEsc(d.shipment_status) : ''}</div>` : ''}
    </div>`;
}

function filterDeals(q) { _dealsQuery = q || ''; renderDealsBoard(); }

function toggleDoneDeals() {
    _dealsShowDone = !_dealsShowDone;
    const btn = document.getElementById('deals-show-done');
    if (btn) btn.textContent = _dealsShowDone ? 'Скрыть завершённые' : 'Показать завершённые';
    renderDealsBoard();
}

// ── Создание сделки ───────────────────────────────────────────────────────────
async function openAddDeal() {
    const customers = await api('counterparties.php') || [];
    const cpOpts = customers.map(c =>
        `<option value="${c.id}">${_dealEsc((c.company_type || '') + ' ' + c.name)}</option>`
    ).join('');

    openModal(`
        <div class="modal-header">
            <span class="modal-title">Новая сделка</span>
            <button class="btn-close" onclick="closeModal()"><i data-lucide="x" style="width:15px;height:15px"></i></button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label class="form-label">Что ищем</label>
                <input type="text" class="form-control" id="deal-title" placeholder="Название товара">
            </div>
            <div style="display:flex;gap:12px">
                <div class="form-group" style="flex:1">
                    <label class="form-label">Количество</label>
                    <input type="number" class="form-control" id="deal-qty" value="1" min="1">
                </div>
                <div class="form-group" style="flex:1">
                    <label class="form-label">Цена конкурента (₽)</label>
                    <input type="number" class="form-control" id="deal-competitor" placeholder="Ориентир" min="0">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Заказчик</label>
                <select class="form-control" id="deal-cp">
                    <option value="">— не указан —</option>${cpOpts}
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Дедлайн</label>
                <input type="date" class="form-control" id="deal-deadline">
            </div>
            <div class="form-group">
                <label class="form-label">Заметка</label>
                <textarea class="form-control" id="deal-note" rows="2" placeholder="Детали заявки"></textarea>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" onclick="closeModal()">Отмена</button>
            <button class="btn btn-primary" onclick="saveNewDeal()">Создать</button>
        </div>
    `);
}

async function saveNewDeal() {
    const title = document.getElementById('deal-title').value.trim();
    if (!title) { showToast('Укажите, что ищем', 'error'); return; }
    const res = await api('deals.php', 'POST', {
        action: 'create',
        title,
        quantity:         document.getElementById('deal-qty').value,
        competitor_price: document.getElementById('deal-competitor').value,
        counterparty_id:  document.getElementById('deal-cp').value,
        deadline:         document.getElementById('deal-deadline').value,
        note:             document.getElementById('deal-note').value,
    });
    if (!res) return;
    showToast('Сделка создана', 'success');
    closeModal();
    await loadDeals();
}

// ── Детали сделки ─────────────────────────────────────────────────────────────
async function openDealDetail(id) {
    const d = _deals.find(x => x.id === id) || await api(`deals.php?id=${id}`);
    if (!d) return;

    const stageBtns = DEAL_STAGES.map(s => `
        <button class="btn ${d.stage === s ? 'btn-primary' : 'btn-ghost'} btn-sm"
            onclick="setDealStage(${d.id}, '${s}')">${s}</button>`).join('');

    const optionsHtml = (d.options || []).length
        ? d.options.map(o => renderDealOption(d, o)).join('')
        : `<div style="font-size:12px;color:var(--text-muted);padding:8px 0">Вариантов пока нет — добавь найденные предложения ниже</div>`;

    openWideModal(`
        <div class="modal-header">
            <span class="modal-title">${_dealEsc(d.title)}${+d.quantity > 1 ? ' ×' + d.quantity : ''}</span>
            <div style="display:flex;gap:8px;align-items:center">
                <button class="btn btn-ghost btn-sm" onclick="openEditDeal(${d.id})"><i data-lucide="pencil" style="width:13px;height:13px"></i></button>
                <button class="btn btn-ghost btn-sm" style="color:var(--danger)" onclick="deleteDeal(${d.id})"><i data-lucide="trash-2" style="width:13px;height:13px"></i></button>
                <button class="btn-close" onclick="closeModal()"><i data-lucide="x" style="width:15px;height:15px"></i></button>
            </div>
        </div>
        <div class="modal-body">
            <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:16px">${stageBtns}</div>

            <div style="display:flex;gap:24px;flex-wrap:wrap;margin-bottom:14px;font-size:13px">
                ${d.counterparty_name ? `<div><span style="color:var(--text-muted)">Заказчик:</span> ${_dealEsc(d.counterparty_name)}</div>` : ''}
                ${d.competitor_price != null ? `<div><span style="color:var(--text-muted)">Цена конкурента:</span> <b>${_dealFmt(d.competitor_price)} ₽</b></div>` : ''}
                ${d.deadline ? `<div><span style="color:var(--text-muted)">Дедлайн:</span> ${_dealDate(d.deadline)}</div>` : ''}
                ${d.shipment_id ? `<div><span style="color:var(--text-muted)">Поставка:</span> <a href="#" onclick="closeModal();openShipment(${d.shipment_id});return false" style="color:var(--accent)">#${d.shipment_id}</a></div>` : ''}
            </div>
            ${d.note ? `<div style="font-size:13px;color:var(--text-secondary);background:var(--bg-subtle);border-radius:8px;padding:10px 12px;margin-bottom:16px;white-space:pre-wrap">${_dealEsc(d.note)}</div>` : ''}

            <div style="font-size:13px;font-weight:600;margin-bottom:8px">Варианты поиска</div>
            <div id="deal-options-list">${optionsHtml}</div>

            <div class="deal-add-option">
                <input type="text"   class="form-control" id="dop-supplier" placeholder="Поставщик">
                <input type="text"   class="form-control" id="dop-url" placeholder="Ссылка">
                <input type="number" class="form-control" id="dop-price" placeholder="Цена ₽" min="0">
                <input type="number" class="form-control" id="dop-delivery" placeholder="Доставка ₽" min="0">
                <input type="number" class="form-control" id="dop-days" placeholder="Срок, дн" min="0">
                <button class="btn btn-primary btn-sm" onclick="addDealOption(${d.id})">+ Вариант</button>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" onclick="closeModal()">Закрыть</button>
        </div>
    `);
    if (window.lucide) lucide.createIcons();
}

function renderDealOption(d, o) {
    const total  = +o.price + +o.delivery_cost;
    const saving = _optionSaving(d, o);
    const chosen = +o.is_chosen === 1;
    return `<div class="deal-option ${chosen ? 'deal-option--chosen' : ''}">
        <div style="flex:1;min-width:0">
            <div style="font-size:13px;font-weight:500;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                ${chosen ? '<i data-lucide="check-circle" style="width:13px;height:13px;color:var(--success);vertical-align:-2px"></i> ' : ''}
                ${_dealEsc(o.supplier || 'Без названия')}
                ${o.url ? ` <a href="${_dealEsc(o.url)}" target="_blank" rel="noopener" onclick="event.stopPropagation()" style="color:var(--accent);font-size:11px">ссылка ↗</a>` : ''}
            </div>
            <div style="display:flex;gap:10px;flex-wrap:wrap;font-size:11px;color:var(--text-muted);margin-top:2px">
                <span>Товар: <b style="color:var(--text-secondary)">${_dealFmt(o.price)} ₽</b></span>
                <span>Доставка: ${_dealFmt(o.delivery_cost)} ₽</span>
                <span>Итого: <b style="color:var(--text-secondary)">${_dealFmt(total)} ₽</b></span>
                ${o.delivery_days != null ? `<span>${o.delivery_days} дн</span>` : ''}
                ${saving != null ? `<span style="color:${saving > 0 ? 'var(--success)' : 'var(--danger)'};font-weight:600">${saving > 0 ? '−' + _dealFmt(saving) + ' ₽ к конкуренту' : '+' + _dealFmt(-saving) + ' ₽ к конкуренту'}</span>` : ''}
            </div>
        </div>
        <div style="display:flex;gap:4px;flex-shrink:0;align-items:center">
            ${!chosen ? `<button class="btn btn-ghost btn-sm" title="Выбрать этот вариант" onclick="chooseDealOption(${o.id}, ${d.id})">Выбрать</button>` : ''}
            <button class="btn btn-primary btn-sm" title="Создать поставку из варианта" onclick="createShipmentFromDeal(${d.id}, ${o.id})">→ Поставка</button>
            <button class="btn-icon btn-icon-danger" onclick="deleteDealOption(${o.id}, ${d.id})"><i data-lucide="x" style="width:12px;height:12px"></i></button>
        </div>
    </div>`;
}

async function addDealOption(dealId) {
    const supplier = document.getElementById('dop-supplier').value.trim();
    const price    = document.getElementById('dop-price').value;
    if (!supplier && !price) { showToast('Укажите поставщика или цену', 'error'); return; }
    await api('deals.php', 'POST', {
        action: 'add_option',
        deal_id: dealId,
        supplier,
        url:           document.getElementById('dop-url').value.trim(),
        price,
        delivery_cost: document.getElementById('dop-delivery').value,
        delivery_days: document.getElementById('dop-days').value,
    });
    await loadDeals();
    openDealDetail(dealId);
}

async function deleteDealOption(optId, dealId) {
    await api('deals.php', 'POST', { action: 'delete_option', id: optId });
    await loadDeals();
    openDealDetail(dealId);
}

async function chooseDealOption(optId, dealId) {
    await api('deals.php', 'POST', { action: 'choose_option', id: optId });
    await loadDeals();
    openDealDetail(dealId);
}

async function setDealStage(dealId, stage) {
    await api('deals.php', 'POST', { action: 'set_stage', id: dealId, stage });
    await loadDeals();
    openDealDetail(dealId);
}

async function deleteDeal(dealId) {
    if (!confirm('Удалить сделку? Поставки и продажи не затрагиваются.')) return;
    await api(`deals.php?id=${dealId}`, 'DELETE');
    showToast('Сделка удалена');
    closeModal();
    await loadDeals();
}

// ── Редактирование сделки ─────────────────────────────────────────────────────
async function openEditDeal(id) {
    const d = _deals.find(x => x.id === id);
    if (!d) return;
    const customers = await api('counterparties.php') || [];
    const cpOpts = customers.map(c =>
        `<option value="${c.id}" ${c.id == d.counterparty_id ? 'selected' : ''}>${_dealEsc((c.company_type || '') + ' ' + c.name)}</option>`
    ).join('');

    openModal(`
        <div class="modal-header">
            <span class="modal-title">Редактировать сделку</span>
            <button class="btn-close" onclick="closeModal()"><i data-lucide="x" style="width:15px;height:15px"></i></button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label class="form-label">Что ищем</label>
                <input type="text" class="form-control" id="deal-title" value="${_dealEsc(d.title)}">
            </div>
            <div style="display:flex;gap:12px">
                <div class="form-group" style="flex:1">
                    <label class="form-label">Количество</label>
                    <input type="number" class="form-control" id="deal-qty" value="${d.quantity}" min="1">
                </div>
                <div class="form-group" style="flex:1">
                    <label class="form-label">Цена конкурента (₽)</label>
                    <input type="number" class="form-control" id="deal-competitor" value="${d.competitor_price ?? ''}" min="0">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Заказчик</label>
                <select class="form-control" id="deal-cp"><option value="">— не указан —</option>${cpOpts}</select>
            </div>
            <div class="form-group">
                <label class="form-label">Дедлайн</label>
                <input type="date" class="form-control" id="deal-deadline" value="${d.deadline ?? ''}">
            </div>
            <div class="form-group">
                <label class="form-label">Заметка</label>
                <textarea class="form-control" id="deal-note" rows="2">${_dealEsc(d.note ?? '')}</textarea>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" onclick="closeModal()">Отмена</button>
            <button class="btn btn-primary" onclick="saveEditDeal(${d.id})">Сохранить</button>
        </div>
    `);
}

async function saveEditDeal(id) {
    const title = document.getElementById('deal-title').value.trim();
    if (!title) { showToast('Укажите, что ищем', 'error'); return; }
    await api('deals.php', 'POST', {
        action: 'update',
        id,
        title,
        quantity:         document.getElementById('deal-qty').value,
        competitor_price: document.getElementById('deal-competitor').value,
        counterparty_id:  document.getElementById('deal-cp').value,
        deadline:         document.getElementById('deal-deadline').value,
        note:             document.getElementById('deal-note').value,
    });
    showToast('Сохранено', 'success');
    closeModal();
    await loadDeals();
    openDealDetail(id);
}

// ── Создание поставки из варианта ─────────────────────────────────────────────
// Открывает СТАНДАРТНУЮ шторку «Новая поставка» с предзаполнением.
// Сохранение идёт обычным путём (pending-операция, склад) — ничего не дублируется.
async function createShipmentFromDeal(dealId, optId) {
    const d = _deals.find(x => x.id === dealId);
    if (!d || typeof openAddShipment !== 'function') return;
    const o = (d.options || []).find(x => x.id === optId);

    window._dealLinkId = dealId; // после сохранения shipments.js привяжет поставку к сделке
    window._dealLinkKeep = true;
    closeModal();
    await openAddShipment();

    // Предзаполняем форму первой вкладки
    setTimeout(() => {
        const nameInput = document.querySelector('.sf-name[data-idx="0"]');
        if (nameInput) nameInput.value = d.title;
        const costInput = document.querySelector('.sf-carrier-cost[data-idx="0"]');
        if (costInput && o) costInput.value = +o.delivery_cost || '';
        const itemRow = document.querySelector('.sf-items[data-idx="0"] .item-row');
        if (itemRow) {
            const inputs = itemRow.querySelectorAll('input');
            if (inputs[0]) inputs[0].value = d.title;
            if (inputs[1]) inputs[1].value = d.quantity || 1;
            if (inputs[2] && o) inputs[2].value = +o.price || '';
        }
        if (typeof calcShipmentTotal === 'function') calcShipmentTotal(0);
        showToast('Форма заполнена из сделки — выбери поставщика и счёт', 'info');
    }, 100);
}

// ── Виджет на дашборде ────────────────────────────────────────────────────────
async function initDealsWidget() {
    const body = document.getElementById('deals-tile-body');
    if (!body) return;
    const deals = await api('deals.php?active=1') || [];

    const cnt = s => deals.filter(d => d.stage === s).length;
    const today = new Date().toISOString().slice(0, 10);
    const top = deals.slice(0, 5);

    const countEl = document.getElementById('deals-tile-count');
    if (countEl) countEl.textContent = deals.length;

    body.innerHTML = `
        <div class="deals-widget-funnel">
            ${['Заявка','Поиск','Заказано'].map(s => `
            <div class="deals-widget-stage" onclick="navigateTo('deals')">
                <div class="deals-widget-stage-num">${cnt(s)}</div>
                <div class="deals-widget-stage-label">${s}</div>
            </div>`).join('')}
        </div>
        ${top.length ? top.map(d => {
            const overdue = d.deadline && d.deadline < today;
            return `<div class="deals-widget-row" onclick="navigateTo('deals')">
                <span class="deals-widget-row-title">${_dealEsc(d.title)}</span>
                <span class="deals-widget-row-meta">
                    <span class="deals-widget-row-stage">${d.stage}</span>
                    ${d.deadline ? `<span style="${overdue ? 'color:var(--danger)' : ''}">${_dealDate(d.deadline)}</span>` : ''}
                </span>
            </div>`;
        }).join('') : `<div style="padding:12px 16px;font-size:12px;color:var(--text-dim)">Нет активных сделок</div>`}
        <div class="deals-widget-footer" onclick="navigateTo('deals')">Все сделки →</div>
    `;
    if (window.lucide) lucide.createIcons();
}
