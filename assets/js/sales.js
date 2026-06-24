// ===== SALES.JS =====

let _warehouseItems = []; // кэш товаров склада
let _transitItems = [];   // кэш товаров «в пути» (позиции поставок В пути)

// Опции выпадашки: товары склада + товары в пути (с пометкой)
function _receiptProductOptions() {
    const wh = _warehouseItems.map(w =>
        `<div class="searchable-option" data-source="wh" data-id="${w.id}" data-label="${escAttr(w.name)}" data-price="${w.purchase_price||0}" onmousedown="selectReceiptProduct(this)">${escAttr(w.name)} <span style="opacity:.5;font-size:11px">· ${w.quantity_left} шт</span></div>`
    ).join('');
    const tr = _transitItems.map(t =>
        `<div class="searchable-option" data-source="tr" data-id="${t.shipment_item_id}" data-label="${escAttr(t.name)}" data-price="${t.purchase_price||0}" onmousedown="selectReceiptProduct(this)">${escAttr(t.name)} <span style="font-size:11px;color:var(--accent)">· в пути · Поставка #${t.shipment_id}${t.counterparty ? ' · ' + escAttr(t.counterparty) : ''} · ${t.quantity} шт</span></div>`
    ).join('');
    return wh + tr;
}

// Строка товара в стиле чека
function createReceiptItemRow(warehouseItems, qty = 1, price = '', removable = true) {
    const opts = _receiptProductOptions();
    const rowId = 'rrow_' + Math.random().toString(36).slice(2,8);
    return `
    <div class="sale-receipt-item-row" id="${rowId}">
        <div style="position:relative">
            <input type="text" class="form-control sale-item-name-input" placeholder="Товар..." autocomplete="off"
                oninput="filterReceiptProduct(this)" onfocus="showReceiptProductDrop(this)" onblur="hideReceiptProductDrop(this)">
            <input type="hidden" class="sale-item-id-input">
            <input type="hidden" class="sale-item-source-input" value="wh">
            <div class="searchable-dropdown receipt-prod-drop" style="display:none">${opts}</div>
        </div>
        <input type="number" class="form-control sale-qty-input" placeholder="Кол" min="1" value="${qty}" oninput="calcReceiptTotal()">
        <input type="number" class="form-control sale-price-input" placeholder="Цена" min="0" value="${price}" oninput="calcReceiptTotal()">
        <div class="item-row-sum">—</div>
        ${removable ? `<button class="btn-remove-item" onclick="this.closest('.sale-receipt-item-row').remove();calcReceiptTotal()"><i data-lucide="x" style="width:15px;height:15px"></i></button>` : '<div></div>'}
    </div>`;
}

function escAttr(s) { return String(s).replace(/"/g,'&quot;').replace(/'/g,'&#39;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

function filterReceiptProduct(inp) {
    const q = inp.value.toLowerCase();
    const drop = inp.parentElement.querySelector('.receipt-prod-drop');
    if (!drop) return;
    drop.style.display = 'block';
    drop.querySelectorAll('.searchable-option').forEach(o => {
        o.style.display = o.dataset.label.toLowerCase().includes(q) ? '' : 'none';
    });
}
function showReceiptProductDrop(inp) {
    const drop = inp.parentElement.querySelector('.receipt-prod-drop');
    if (drop) drop.style.display = 'block';
}
function hideReceiptProductDrop(inp) {
    setTimeout(() => {
        const drop = inp.parentElement.querySelector('.receipt-prod-drop');
        if (drop) drop.style.display = 'none';
    }, 180);
}
function selectReceiptProduct(opt) {
    const wrap = opt.closest('div[style*="relative"]') || opt.closest('.sale-receipt-item-row > div');
    if (!wrap) return;
    const inp = wrap.querySelector('.sale-item-name-input');
    const hid = wrap.querySelector('.sale-item-id-input');
    const src = wrap.querySelector('.sale-item-source-input');
    if (inp) inp.value = opt.dataset.label;
    if (hid) hid.value = opt.dataset.id;
    if (src) src.value = opt.dataset.source || 'wh';
    const drop = wrap.querySelector('.receipt-prod-drop');
    if (drop) drop.style.display = 'none';
    calcReceiptTotal();
}

function calcReceiptTotal() {
    let total = 0;
    document.querySelectorAll('#receipt-items-container .sale-receipt-item-row').forEach(row => {
        const qty   = parseFloat(row.querySelector('.sale-qty-input')?.value) || 0;
        const price = parseFloat(row.querySelector('.sale-price-input')?.value) || 0;
        const sum   = qty * price;
        total += sum;
        const sumEl = row.querySelector('.item-row-sum');
        if (sumEl) sumEl.textContent = sum > 0 ? new Intl.NumberFormat('ru-RU').format(sum) + ' ₽' : '—';
    });
    const el = document.getElementById('receipt-total-value');
    if (el) el.textContent = new Intl.NumberFormat('ru-RU', { minimumFractionDigits: 0 }).format(total) + ' ₽';
}

// Создать строку выбора товара с живым поиском (legacy — используется в openEditSale)
function createSaleItemRow(warehouseItems, selectedId = null, selectedName = '', qty = 1, price = '', removable = true) {
    const options = warehouseItems.map(w => ({
        value: w.id,
        label: w.name,
        meta: `Остаток: ${w.quantity_left} шт · ${new Intl.NumberFormat('ru-RU').format(w.purchase_price)} ₽/шт`
    }));

    const searchHtml = createItemSearch(options, null, 'Поиск товара...');
    const rowId = 'srow_' + Math.random().toString(36).slice(2, 8);

    return `
        <div class="sale-item-row" id="${rowId}" style="display:grid;grid-template-columns:1fr 80px 100px ${removable ? '32px' : ''};gap:8px;margin-bottom:8px;align-items:start">
            <div class="sale-item-search">
                ${searchHtml}
            </div>
            <input type="number" class="form-control sale-qty-input" placeholder="Кол-во" min="1" value="${qty}" style="height:40px" oninput="calcSaleTotal()">
            <input type="number" class="form-control sale-price-input" placeholder="Цена ₽" min="0" value="${price}" style="height:40px" oninput="calcSaleTotal()">
            ${removable ? `<button class="btn-remove-item" onclick="this.closest('.sale-item-row').remove(); calcSaleTotal()" style="height:40px"><i data-lucide="x" style="width:15px;height:15px"></i></button>` : ''}
        </div>
    `;
}

// Открыть форму новой продажи — чековый стиль
async function openSaleModal(preselectedWarehouseId = null) {
    const [warehouse, counterparties, accounts, transit] = await Promise.all([
        api('warehouse.php?status=available'),
        api('counterparties.php?type=buyer'),
        api('bank.php?accounts=1'),
        api('shipments.php?in_transit_items=1')
    ]);
    _warehouseItems = warehouse;
    _transitItems   = Array.isArray(transit) ? transit : [];

    const cpOptions = counterparties.map(c =>
        `<option value="${c.id}" data-type="${c.company_type}">${c.company_type} ${c.name}</option>`
    ).join('');
    const accountOptions = accounts.map(a =>
        `<option value="${a.id}">${a.name}</option>`
    ).join('');

    const firstRow = createReceiptItemRow(warehouse, 1, '', false);

    openModal(`
        <div class="modal-header">
            <span class="modal-title">Новая продажа</span>
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
                    ${dpField('sale-date')}
                </div>

                <div class="sale-receipt-row">
                    <span class="sale-receipt-row-label">Покупатель</span>
                    <select class="form-control" id="sale-counterparty" onchange="onSaleCpChange()" style="flex:1">
                        <option value="">— выбрать —</option>
                        ${cpOptions}
                    </select>
                </div>

                <hr class="sale-receipt-divider-top">

                <div class="sale-receipt-items-header">
                    <span>Товар</span><span>Кол</span><span>Цена</span><span style="text-align:right">Сумма</span><span></span>
                </div>
                <div id="receipt-items-container">${firstRow}</div>
                <button class="sale-receipt-add-btn" onclick="addReceiptItemRow()">+ Добавить товар</button>

                <div class="sale-receipt-total-block">
                    <span class="sale-receipt-total-label">Итого</span>
                    <span class="sale-receipt-total-value" id="receipt-total-value">0 ₽</span>
                </div>
            </div>
            <svg class="sale-receipt-zigzag" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 420 14" preserveAspectRatio="none" width="100%" height="14">
                <path d="M0,0 L420,0 L420,7 L413,14 L406,7 L399,14 L392,7 L385,14 L378,7 L371,14 L364,7 L357,14 L350,7 L343,14 L336,7 L329,14 L322,7 L315,14 L308,7 L301,14 L294,7 L287,14 L280,7 L273,14 L266,7 L259,14 L252,7 L245,14 L238,7 L231,14 L224,7 L217,14 L210,7 L203,14 L196,7 L189,14 L182,7 L175,14 L168,7 L161,14 L154,7 L147,14 L140,7 L133,14 L126,7 L119,14 L112,7 L105,14 L98,7 L91,14 L84,7 L77,14 L70,7 L63,14 L56,7 L49,14 L42,7 L35,14 L28,7 L21,14 L14,7 L7,14 L0,7 Z" fill="#f9f7f4"/>
            </svg>

            <!-- ПОД ЧЕКОМ -->
            <div class="sale-receipt-bottom">
                <div class="form-group">
                    <label class="form-label">Статус оплаты</label>
                    <select class="form-control" id="sale-status">
                        <option value="Оплачено">Оплачено</option>
                        <option value="Счёт выставлен">Счёт выставлен</option>
                        <option value="Отменено">Отменено</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Счёт зачисления</label>
                    <select class="form-control" id="sale-account">
                        <option value="">— не указывать —</option>
                        ${accountOptions}
                    </select>
                </div>
                <div class="form-group" id="sale-acquiring-group" style="display:none">
                    <label class="form-label">% эквайринга</label>
                    <input type="number" class="form-control" id="sale-acquiring" value="1.22" min="0" max="100" step="0.01">
                    <div style="font-size:11px;color:var(--text-dim);margin-top:4px">Комиссия вычитается из суммы зачисления</div>
                </div>
            </div>

        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" onclick="closeModal()">Отмена</button>
            <button class="btn btn-primary" onclick="saveSale()">Сохранить</button>
        </div>
    `);

    if (preselectedWarehouseId) {
        const w = warehouse.find(x => x.id == preselectedWarehouseId);
        if (w) {
            const firstNameInp = document.querySelector('#receipt-items-container .sale-item-name-input');
            const firstIdInp   = document.querySelector('#receipt-items-container .sale-item-id-input');
            if (firstNameInp) firstNameInp.value = w.name;
            if (firstIdInp)   firstIdInp.value   = w.id;
            calcReceiptTotal();
        }
    }
}

function addReceiptItemRow() {
    const container = document.getElementById('receipt-items-container');
    const div = document.createElement('div');
    div.innerHTML = createReceiptItemRow(_warehouseItems, 1, '', true);
    container.appendChild(div.firstElementChild);
}

function calcSaleTotal() {
    let total = 0;
    document.querySelectorAll('#sale-items-container .sale-item-row').forEach(row => {
        const qty = parseFloat(row.querySelector('.sale-qty-input')?.value) || 0;
        const price = parseFloat(row.querySelector('.sale-price-input')?.value) || 0;
        total += qty * price;
    });
    const el = document.getElementById('sale-total-display');
    if (el) el.textContent = new Intl.NumberFormat('ru-RU', { style: 'currency', currency: 'RUB', minimumFractionDigits: 0 }).format(total);
}

function calcEditSaleTotal() {
    let total = 0;
    document.querySelectorAll('#edit-sale-items-container .edit-sale-item-row').forEach(row => {
        const qty = parseFloat(row.querySelector('.edit-sale-qty-input')?.value) || 0;
        const price = parseFloat(row.querySelector('.edit-sale-price-input')?.value) || 0;
        total += qty * price;
    });
    const el = document.getElementById('edit-sale-total-display');
    if (el) el.textContent = new Intl.NumberFormat('ru-RU', { style: 'currency', currency: 'RUB', minimumFractionDigits: 0 }).format(total);
}

function addSaleItemRow() {
    const container = document.getElementById('sale-items-container');
    const div = document.createElement('div');
    div.innerHTML = createSaleItemRow(_warehouseItems, null, '', 1, '', true);
    container.appendChild(div.firstElementChild);
}

function onSaleCpChange() {
    const sel = document.getElementById('sale-counterparty');
    const opt = sel.options[sel.selectedIndex];
    const isRetail = opt && opt.dataset.type === 'Розничный покупатель';
    const group = document.getElementById('sale-acquiring-group');
    if (group) group.style.display = isRetail ? '' : 'none';
}

function onEditSaleCpChange() {
    const sel = document.getElementById('edit-sale-counterparty');
    const opt = sel.options[sel.selectedIndex];
    const isRetail = opt && opt.dataset.type === 'Розничный покупатель';
    const group = document.getElementById('edit-sale-acquiring-group');
    if (group) group.style.display = isRetail ? '' : 'none';
}

async function saveSale() {
    const date = document.getElementById('sale-date').value;
    const counterpartyId = document.getElementById('sale-counterparty').value;
    const accountId = document.getElementById('sale-account').value;
    const saleStatus = document.getElementById('sale-status').value;
    const acquiringGroup = document.getElementById('sale-acquiring-group');
    const isRetail = acquiringGroup && acquiringGroup.style.display !== 'none';
    const acquiringPct = isRetail ? (parseFloat(document.getElementById('sale-acquiring').value) || 1.22) : null;

    const items = [];
    let totalPrice = 0;
    document.querySelectorAll('#receipt-items-container .sale-receipt-item-row').forEach(row => {
        const id    = row.querySelector('.sale-item-id-input')?.value;
        const src   = row.querySelector('.sale-item-source-input')?.value || 'wh';
        const qty   = parseInt(row.querySelector('.sale-qty-input')?.value) || 1;
        const price = parseFloat(row.querySelector('.sale-price-input')?.value) || 0;
        if (!id) return;
        if (src === 'tr') {
            items.push({ shipment_item_id: id, quantity: qty, sale_price: price });
        } else {
            items.push({ warehouse_id: id, quantity: qty, sale_price: price });
        }
        totalPrice += qty * price;
    });

    if (items.length === 0) { showToast('Выберите хотя бы один товар', 'error'); return; }
    if (totalPrice <= 0) { showToast('Укажите цену товаров', 'error'); return; }
    if (!date) { showToast('Укажите дату продажи', 'error'); return; }
    if (!counterpartyId) { showToast('Укажите покупателя', 'error'); return; }

    await api('sales.php', 'POST', {
        items, sale_price: totalPrice, sale_date: date,
        counterparty_id: counterpartyId,
        buyer_name: null,
        account_id: saleStatus === 'Оплачено' ? accountId || null : null,
        status: saleStatus,
        acquiring_pct: acquiringPct
    });

    showToast('Продажа сохранена');
    closeModal();
    setTimeout(reloadPage, 500);
}

// Редактирование продажи — с поиском по товарам
async function openEditSale(id) {
    const [salesData, counterparties, accounts, warehouse] = await Promise.all([
        api('sales.php'),
        api('counterparties.php?type=buyer'),
        api('bank.php?accounts=1'),
        api('warehouse.php?status=available')
    ]);
    _warehouseItems = warehouse;

    const sale = salesData.find(s => s.id == id);
    if (!sale) return;

    const saleStatus = sale.status || 'Оплачено';
    const isRetailEdit = sale.company_type === 'Розничный покупатель';
    const cpOptions = counterparties.map(c =>
        `<option value="${c.id}" data-type="${c.company_type}" ${sale.counterparty_id == c.id ? 'selected' : ''}>${c.company_type} ${c.name}</option>`
    ).join('');
    const accountOptions = accounts.map(a =>
        `<option value="${a.id}" ${sale.account_id == a.id ? 'selected' : ''}>${a.name}</option>`
    ).join('');

    const existingRows = (sale.items || []).map(item => {
        const allItems = [...warehouse];
        if (!allItems.find(w => w.id == item.warehouse_id)) {
            allItems.unshift({ id: item.warehouse_id, name: item.product_name, quantity_left: item.quantity, purchase_price: item.purchase_price });
        }
        const options = allItems.map(w => ({
            value: w.id,
            label: w.name,
            meta: `Остаток: ${w.quantity_left} шт`
        }));
        const searchHtml = createItemSearch(options);
        return `
            <div class="edit-sale-item-row" style="display:grid;grid-template-columns:1fr 80px 100px 32px;gap:8px;margin-bottom:8px;align-items:start">
                <div class="sale-item-search" data-warehouse-id="${item.warehouse_id}">
                    ${searchHtml}
                </div>
                <input type="number" class="form-control edit-sale-qty-input" min="1" value="${item.quantity}" style="height:40px" oninput="calcEditSaleTotal()">
                <input type="number" class="form-control edit-sale-price-input" placeholder="Цена ₽" min="0" value="${item.sale_price || ''}" style="height:40px" oninput="calcEditSaleTotal()">
                <button class="btn-remove-item" onclick="this.closest('.edit-sale-item-row').remove(); calcEditSaleTotal()" style="height:40px"><i data-lucide="x" style="width:15px;height:15px"></i></button>
            </div>
        `;
    }).join('');

    openDrawer(`
        <div class="drawer-header">
            <span class="drawer-title">Редактировать продажу #${id}</span>
            <button class="btn-close" onclick="closeDrawer()"><i data-lucide="x" style="width:15px;height:15px"></i></button>
        </div>
        <div class="drawer-body">
            <div class="form-group">
                <label class="form-label">Товары</label>
                <div id="edit-sale-items-container">${existingRows}</div>
                <button class="btn-add-item" onclick="addEditSaleItemRow()">+ Добавить товар</button>
            </div>
            <div class="total-row" style="margin-bottom:16px">
                <span class="total-label">Итого продажа:</span>
                <span class="total-value" id="edit-sale-total-display">0 ₽</span>
            </div>
            <div class="form-group">
                <label class="form-label">Покупатель</label>
                <select class="form-control" id="edit-sale-counterparty" onchange="onEditSaleCpChange()">
                    <option value="">— выбрать —</option>
                    ${cpOptions}
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Статус оплаты</label>
                <select class="form-control" id="edit-sale-status">
                    <option value="Оплачено" ${saleStatus === 'Оплачено' ? 'selected' : ''}>Оплачено</option>
                    <option value="Счёт выставлен" ${saleStatus === 'Счёт выставлен' ? 'selected' : ''}>Счёт выставлен</option>
                    <option value="Отменено" ${saleStatus === 'Отменено' ? 'selected' : ''}>Отменено</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Счёт зачисления</label>
                <select class="form-control" id="edit-sale-account">
                    <option value="">— не указывать —</option>
                    ${accountOptions}
                </select>
            </div>
            <div class="form-group" id="edit-sale-acquiring-group" style="${isRetailEdit ? '' : 'display:none'}">
                <label class="form-label">% эквайринга</label>
                <input type="number" class="form-control" id="edit-sale-acquiring" value="1.22" min="0" max="100" step="0.01">
                <div style="font-size:11px;color:var(--text-muted);margin-top:6px">Комиссия будет вычтена из суммы зачисления на счёт</div>
            </div>
            <div class="form-group">
                <label class="form-label">Дата продажи</label>
                ${dpField('edit-sale-date', sale.sale_date)}
            </div>
        </div>
        <div class="drawer-footer">
            <button class="btn btn-ghost" onclick="closeDrawer()">Отмена</button>
            <button class="btn btn-primary" onclick="updateSale(${id})">Сохранить</button>
        </div>
    `);

    setTimeout(() => {
        document.querySelectorAll('#edit-sale-items-container .edit-sale-item-row').forEach((row, i) => {
            const item = (sale.items || [])[i];
            if (!item) return;
            const inp = row.querySelector('.item-search-input');
            const val = row.querySelector('.item-search-value');
            if (inp) inp.value = item.product_name;
            if (val) val.value = item.warehouse_id;
        });
        calcEditSaleTotal();
    }, 50);
}

function addEditSaleItemRow() {
    const container = document.getElementById('edit-sale-items-container');
    const div = document.createElement('div');
    const html = createSaleItemRow(_warehouseItems, null, '', 1, '', true)
        .replace(/sale-item-row/g, 'edit-sale-item-row')
        .replace(/sale-qty-input/g, 'edit-sale-qty-input')
        .replace(/sale-price-input/g, 'edit-sale-price-input')
        .replace(/calcSaleTotal/g, 'calcEditSaleTotal')
        .replace(/sale-items-container/g, 'edit-sale-items-container');
    div.innerHTML = html;
    container.appendChild(div.firstElementChild);
}

async function updateSale(id) {
    const date = document.getElementById('edit-sale-date').value;
    const counterpartyId = document.getElementById('edit-sale-counterparty').value;
    const status = document.getElementById('edit-sale-status').value;
    const accountId = document.getElementById('edit-sale-account').value;
    const acquiringGroup = document.getElementById('edit-sale-acquiring-group');
    const isRetail = acquiringGroup && acquiringGroup.style.display !== 'none';
    const acquiringPct = isRetail ? (parseFloat(document.getElementById('edit-sale-acquiring').value) || 1.22) : null;

    if (!date) { showToast('Укажите дату', 'error'); return; }
    if (!counterpartyId) { showToast('Укажите покупателя', 'error'); return; }

    const items = [];
    let totalPrice = 0;
    document.querySelectorAll('#edit-sale-items-container .edit-sale-item-row').forEach(row => {
        const warehouseId = getItemSearchValue(row);
        const qty = parseInt(row.querySelector('.edit-sale-qty-input').value) || 1;
        const price = parseFloat(row.querySelector('.edit-sale-price-input').value) || 0;
        if (warehouseId) {
            items.push({ warehouse_id: warehouseId, quantity: qty, sale_price: price });
            totalPrice += qty * price;
        }
    });

    if (items.length === 0) { showToast('Добавьте хотя бы один товар', 'error'); return; }

    await api('sales.php', 'PUT', {
        id, items, sale_price: totalPrice, sale_date: date,
        counterparty_id: counterpartyId,
        buyer_name: null,
        status,
        account_id: status === 'Оплачено' ? accountId || null : null,
        acquiring_pct: acquiringPct
    });

    showToast('Продажа обновлена');
    closeDrawer();
    setTimeout(reloadPage, 500);
}

function deleteSale(id) {
    confirmAction('Удалить продажу? Товар вернётся на склад.', async () => {
        await api(`sales.php?id=${id}`, 'DELETE');
        showToast('Продажа удалена');
        setTimeout(reloadPage, 500);
    });
}

async function openSaleDetail(id) {
    const [sales, reminderHtml] = await Promise.all([
        api('sales.php'),
        renderReminderSection('sale', id)
    ]);
    const sale = sales.find(s => s.id == id);
    if (!sale) return;

    const isCash = sale.company_type === 'Розничный покупатель' || sale.counterparty === 'Розничный покупатель';
    const itemsHtml = (sale.items || []).map(item => `
        <tr>
            <td>
                ${item.product_name}
                ${item.warehouse_id ? `<button class="btn-chain-link" onclick="openProductChain(${item.warehouse_id})" title="Движение товара">🔗</button>` : ''}
            </td>
            <td>${item.quantity} шт</td>
        </tr>
    `).join('') || `<tr><td colspan="2" style="color:var(--text-muted)">—</td></tr>`;

    openModal(`
        <div class="modal-header">
            <span class="modal-title">${isCash ? 'Касса' : 'Продажа'} #${sale.id}</span>
            <button class="btn-close" onclick="closeModal()"><i data-lucide="x" style="width:15px;height:15px"></i></button>
        </div>
        <div class="modal-body">
            <div class="detail-grid">
                <div class="detail-item"><span class="detail-label">Дата</span><span class="detail-value">${formatDate(sale.sale_date)}</span></div>
                <div class="detail-item"><span class="detail-label">Покупатель</span><span class="detail-value">${sale.counterparty || sale.buyer_name || '—'}</span></div>
                <div class="detail-item"><span class="detail-label">Сумма</span><span class="detail-value" style="color:var(--success)">${formatPrice(sale.sale_price)}</span></div>
                <div class="detail-item"><span class="detail-label">Статус</span><span class="detail-value">${sale.status || 'Оплачено'}</span></div>
            </div>
            <div class="table-wrapper"><table>
                <thead><tr><th>Товар</th><th>Кол-во</th></tr></thead>
                <tbody>${itemsHtml}</tbody>
            </table></div>
            ${reminderHtml}
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" onclick="closeModal()">Закрыть</button>
        </div>
    `);
    if (window.lucide) lucide.createIcons();
}

// Открыть группу одинаковых товаров на складе
async function openWarehouseGroup(idsJson) {
    const ids = JSON.parse(idsJson);
    const items = await Promise.all(ids.map(id => api(`warehouse.php?id=${id}`)));

    const rowsHtml = items.map(item => `
        <tr onclick="closeModal(); openWarehouseItem(${item.id})" style="cursor:pointer">
            <td>Поставка #${item.shipment_id || '—'}</td>
            <td>${item.quantity_left} / ${item.quantity_total} шт</td>
            <td>${formatPrice(item.purchase_price)}</td>
            <td>${item.status}</td>
            <td>
                <button class="btn-action" onclick="event.stopPropagation(); openProductChain(${item.id})">🔗</button>
            </td>
        </tr>
    `).join('');

    openModal(`
        <div class="modal-header">
            <span class="modal-title">${items[0]?.name}</span>
            <button class="btn-close" onclick="closeModal()"><i data-lucide="x" style="width:15px;height:15px"></i></button>
        </div>
        <div class="modal-body">
            <p style="color:var(--text-muted);font-size:13px;margin-bottom:16px">Несколько партий этого товара — выберите для просмотра</p>
            <div class="table-wrapper"><table>
                <thead><tr><th>Поставка</th><th>Остаток</th><th>Цена</th><th>Статус</th><th></th></tr></thead>
                <tbody>${rowsHtml}</tbody>
            </table></div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" onclick="closeModal()">Закрыть</button>
        </div>
    `);
}