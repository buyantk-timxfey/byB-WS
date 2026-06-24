// ===== BANK.JS =====

// Экранирование для вставки в HTML/атрибуты (защита от кавычек/тегов из выписки)
function _esc(str) {
    return String(str ?? '')
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

// ===== Лента операций: серверная фильтрация + постраничная навигация =====
const BANK_PAGE_SIZE = 50;
let _bankOpsLoading = false;
let _bankPage = 1;
let _bankTotalPages = 1;

function _bankFilterParams() {
    const accountId = document.getElementById('filter-account')?.value || '';
    const type = document.getElementById('filter-type')?.value || '';
    return { accountId, type };
}

// Загружает страницу операций (заменяет содержимое таблицы)
async function loadBankOpsPage(page) {
    const tbody = document.getElementById('bank-ops-tbody');
    if (!tbody || _bankOpsLoading) return;
    _bankOpsLoading = true;

    const { accountId, type } = _bankFilterParams();
    const offset = (page - 1) * BANK_PAGE_SIZE;
    const qs = new URLSearchParams({ operations_html: '1', offset });
    if (accountId) qs.set('account_id', accountId);
    if (type) qs.set('direction', type);
    // Передаём действующий период. На P&L без параметров по умолчанию «Все месяцы» —
    // сервер сообщает это через data-period-all.
    const cur = new URLSearchParams(window.location.search);
    if (cur.get('period') === 'all' || tbody.dataset.periodAll === '1') qs.set('period', 'all');
    else { if (cur.get('month')) qs.set('month', cur.get('month')); if (cur.get('year')) qs.set('year', cur.get('year')); }

    try {
        const res = await api('bank.php?' + qs.toString());
        if (!res) return;
        _bankPage = page;
        _bankTotalPages = Math.max(1, Math.ceil((res.total || 0) / BANK_PAGE_SIZE));
        tbody.innerHTML = res.html || '';
        if (!tbody.querySelector('tr')) {
            tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;color:var(--text-muted);padding:40px">Операций не найдено</td></tr>';
        }
        _updateBankPager();
        if (window.lucide) lucide.createIcons();
        // К началу таблицы при листании
        document.getElementById('bank-ops-table')?.scrollIntoView({ block: 'start', behavior: 'smooth' });
    } finally {
        _bankOpsLoading = false;
    }
}

function _updateBankPager() {
    const pager = document.getElementById('bank-pager');
    if (!pager) return;
    pager.style.display = _bankTotalPages <= 1 ? 'none' : '';
    const info = document.getElementById('bank-pager-info');
    if (info) info.textContent = `Стр. ${_bankPage} из ${_bankTotalPages}`;
    const prev = document.getElementById('bank-pager-prev');
    const next = document.getElementById('bank-pager-next');
    if (prev) prev.disabled = _bankPage <= 1;
    if (next) next.disabled = _bankPage >= _bankTotalPages;
}

function bankGoPage(delta) {
    const target = _bankPage + delta;
    if (target < 1 || target > _bankTotalPages) return;
    loadBankOpsPage(target);
}

// Смена фильтра — перезагрузка с первой страницы
async function filterBankOps() {
    const { accountId, type } = _bankFilterParams();
    sessionStorage.setItem('bankFilterAccount', accountId);
    sessionStorage.setItem('bankFilterType', type);
    await loadBankOpsPage(1);
}

// Инициализация страницы банка (вызывается и при полной загрузке, и при SPA-навигации)
function initBankPage() {
    const accEl = document.getElementById('filter-account');
    if (!accEl) return; // не на странице банка
    _bankPage = 1;
    _bankTotalPages = parseInt(document.getElementById('bank-pager')?.dataset.totalPages || '1', 10);

    const accountId = sessionStorage.getItem('bankFilterAccount') || '';
    const type = sessionStorage.getItem('bankFilterType') || '';
    if (accountId) accEl.value = accountId;
    const typeEl = document.getElementById('filter-type');
    if (typeEl && type) typeEl.value = type;

    // Если есть сохранённый фильтр — перезагружаем ленту под него
    if (accountId || type) filterBankOps();
}

// Открыть детали операции
async function openBankOperation(id) {
    const op = await api(`bank.php?operation_id=${id}`);

    // Перевод между счетами — пара связанных операций, редактировать нельзя (только удалить пару)
    if (op.type === 'Перевод') { _openTransferView(op); return; }

    const isIncome = ['Продажа', 'Прочий приход'].includes(op.type);
    const isManual = op.source_type === 'manual';

    // Блок источника
    let sourceHtml = '';
    if (op.source_type === 'sale' && op.source) {
        sourceHtml = `
            <div class="modal-section">
                <div class="modal-section-title">Источник — Продажа #${op.source.id}</div>
                <div class="detail-grid">
                    <div class="detail-item"><span class="detail-label">Дата</span><span class="detail-value">${formatDate(op.source.date)}</span></div>
                    <div class="detail-item"><span class="detail-label">Покупатель</span><span class="detail-value">${_esc(op.source.counterparty)}</span></div>
                    <div class="detail-item"><span class="detail-label">Товары</span><span class="detail-value">${_esc(op.source.items) || '—'}</span></div>
                    <div class="detail-item"><span class="detail-label">Статус</span><span class="detail-value">${getStatusBadge(op.source.status)}</span></div>
                </div>
                <button class="btn btn-ghost" style="font-size:12px" onclick="event.stopPropagation(); closeModal(); setTimeout(() => openSaleDetail(${op.source.id}), 50)">Открыть продажу →</button>
            </div>
        `;
    } else if (op.source_type === 'shipment' && op.source) {
        sourceHtml = `
            <div class="modal-section">
                <div class="modal-section-title">Источник — Поставка #${op.source.id}</div>
                <div class="detail-grid">
                    <div class="detail-item"><span class="detail-label">Дата</span><span class="detail-value">${formatDate(op.source.date)}</span></div>
                    <div class="detail-item"><span class="detail-label">Поставщик</span><span class="detail-value">${_esc(op.source.counterparty)}</span></div>
                    <div class="detail-item"><span class="detail-label">Статус</span><span class="detail-value">${getStatusBadge(op.source.status)}</span></div>
                </div>
                <button class="btn btn-ghost" style="font-size:12px" onclick="openShipment(${op.source.id})">Открыть поставку →</button>
            </div>
        `;
    }

    // Форма редактирования
    const accounts = await api('bank.php?accounts=1');
    const accOptions = accounts.map(a =>
        `<option value="${a.id}" ${a.id == op.account_id ? 'selected' : ''}>${a.name}</option>`
    ).join('');

    // Для автоопераций показываем чекбокс синхронизации с источником
    const syncCheckbox = !isManual ? `
        <div class="form-group" style="display:flex;align-items:center;gap:8px;margin-top:4px">
            <input type="checkbox" id="bop-sync" checked style="width:16px;height:16px;accent-color:var(--accent)">
            <label for="bop-sync" style="font-size:13px;color:var(--text-muted);cursor:pointer">
                Синхронизировать с ${op.source_type === 'sale' ? 'продажей' : 'поставкой'}
            </label>
        </div>
    ` : '';

    // Определяем кассу и эквайринг
    const acquiringMatch = (op.description || '').match(/эквайринг ([\d.]+)%/);
    const isKassa = !!acquiringMatch || (op.description || '').includes('кассе');
    let acquiringPct = acquiringMatch ? acquiringMatch[1] : '1.22';
    // Защита от деления на ноль в реверс-расчёте кассы (1 - pct/100)
    if (parseFloat(acquiringPct) >= 100) acquiringPct = '99.99';
    const acquiringHtml = isKassa ? `
        <div class="form-group">
            <label class="form-label">% эквайринга</label>
            <input type="number" class="form-control" id="bop-edit-acquiring" value="${acquiringPct}" min="0" max="100" step="0.01" oninput="calcEditAcquiring()">
        </div>
        <div class="form-group">
            <label class="form-label">К зачислению (₽)</label>
            <input type="number" class="form-control" id="bop-edit-amount-net" readonly style="opacity:.6">
            <div style="font-size:11px;color:var(--text-muted);margin-top:4px">Комиссия: <span id="bop-edit-fee">0</span> ₽</div>
        </div>
    ` : '';

    openModal(`
        <div class="modal-header">
            <span class="modal-title">Операция #${op.id}</span>
            <button class="btn-close" onclick="closeModal()"><i data-lucide="x" style="width:15px;height:15px"></i></button>
        </div>
        <div class="modal-body">
            ${sourceHtml}
            <div class="form-group">
                <label class="form-label">Наименование</label>
                <input type="text" class="form-control" id="bop-edit-desc" value="${_esc(op.description)}" ${!isManual ? 'readonly style="opacity:.6"' : ''}>
            </div>
            <div class="form-group">
                <label class="form-label" id="bop-edit-amount-label">${isKassa ? 'Сумма продажи (₽)' : 'Сумма (₽)'}</label>
                <input type="number" class="form-control" id="bop-edit-amount" value="${isKassa ? Math.round(op.amount / (1 - acquiringPct/100) * 100) / 100 : op.amount}" min="0" oninput="calcEditAcquiring()">
            </div>
            ${acquiringHtml}
            <div class="form-group">
                <label class="form-label">Дата</label>
                ${dpField('bop-edit-date', op.operation_date)}
            </div>
            <div class="form-group">
                <label class="form-label">Счёт</label>
                <select class="form-control" id="bop-edit-account">${accOptions}</select>
            </div>
            ${syncCheckbox}
            <div id="entity-notes-bank_op-${op.id}"><div class="notes-loading">Загрузка заметок…</div></div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" onclick="closeModal()">Отмена</button>
            <button class="btn btn-primary" onclick="saveBankOperationEdit(${op.id})">Сохранить</button>
        </div>
    `);
    if (isKassa) setTimeout(() => calcEditAcquiring(), 50);
    if (typeof loadEntityNotes === 'function') loadEntityNotes('bank_op', op.id);
}

// Просмотр перевода (read-only). Перевод — пара связанных операций; правка запрещена.
function _openTransferView(op) {
    const amount = Math.abs(parseFloat(op.amount) || 0);
    const isOut = parseFloat(op.amount) < 0;
    openModal(`
        <div class="modal-header">
            <span class="modal-title">Перевод между счетами</span>
            <button class="btn-close" onclick="closeModal()"><i data-lucide="x" style="width:15px;height:15px"></i></button>
        </div>
        <div class="modal-body">
            <div class="detail-grid">
                <div class="detail-item"><span class="detail-label">Направление</span>
                    <span class="detail-value" style="color:${isOut ? 'var(--danger)' : 'var(--success)'}">
                        ${isOut ? 'Списание со счёта' : 'Зачисление на счёт'}
                    </span></div>
                <div class="detail-item"><span class="detail-label">Счёт</span><span class="detail-value">${_esc(op.account_name)}</span></div>
                <div class="detail-item"><span class="detail-label">Сумма</span><span class="detail-value">${amount.toLocaleString('ru-RU')} ₽</span></div>
                <div class="detail-item"><span class="detail-label">Дата</span><span class="detail-value">${formatDate(op.operation_date)}</span></div>
                <div class="detail-item"><span class="detail-label">Описание</span><span class="detail-value">${_esc(op.description) || '—'}</span></div>
            </div>
            <p style="font-size:12px;color:var(--text-muted);margin-top:12px">
                Перевод состоит из двух связанных операций. Редактирование недоступно — удаление убирает обе ноги.
            </p>
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" onclick="closeModal()">Закрыть</button>
            <button class="btn btn-danger" onclick="deleteBankOperation(${op.id})">Удалить перевод</button>
        </div>
    `);
}

function calcEditAcquiring() {
    const amount = parseFloat(document.getElementById('bop-edit-amount').value) || 0;
    const pct = parseFloat(document.getElementById('bop-edit-acquiring')?.value) || 1.22;
    const fee = Math.round(amount * pct / 100 * 100) / 100;
    const net = Math.round((amount - fee) * 100) / 100;
    const netEl = document.getElementById('bop-edit-amount-net');
    const feeEl = document.getElementById('bop-edit-fee');
    if (netEl) netEl.value = net;
    if (feeEl) feeEl.textContent = fee.toLocaleString('ru-RU');
}

async function saveBankOperationEdit(id) {
    const date = document.getElementById('bop-edit-date').value;
    const accountId = document.getElementById('bop-edit-account').value;
    const syncEl = document.getElementById('bop-sync');
    const syncSource = syncEl ? syncEl.checked : false;
    const hasAcquiring = !!document.getElementById('bop-edit-acquiring');

    if (!date) { showToast('Укажите дату', 'error'); return; }

    let amount, desc;

    if (hasAcquiring) {
        const gross = parseFloat(document.getElementById('bop-edit-amount').value);
        const pct = parseFloat(document.getElementById('bop-edit-acquiring').value) || 1.22;
        if (!gross || gross <= 0) { showToast('Укажите сумму', 'error'); return; }
        const fee = Math.round(gross * pct / 100 * 100) / 100;
        amount = Math.round((gross - fee) * 100) / 100;
        const baseName = document.getElementById('bop-edit-desc').value.replace(/\s*\(эквайринг.*?\)/, '').replace('Продажа по кассе', 'Продажа по кассе').trim();
        desc = `${baseName} (эквайринг ${pct}%, комиссия ${fee} ₽)`;
    } else {
        amount = parseFloat(document.getElementById('bop-edit-amount').value);
        desc = document.getElementById('bop-edit-desc').value.trim();
        if (!amount || amount <= 0) { showToast('Укажите сумму', 'error'); return; }
    }

    await api('bank.php', 'PUT', {
        id, amount, operation_date: date,
        account_id: accountId,
        description: desc,
        sync_source: syncSource
    });

    showToast('Операция обновлена');
    closeModal();
    setTimeout(reloadPage, 400);
}

// Новая ручная операция
async function openAddBankOperation() {
    const accounts = await api('bank.php?accounts=1');
    const accountOptions = accounts.map(a => `<option value="${a.id}">${a.name}</option>`).join('');

    openDrawer(`
        <div class="drawer-header">
            <span class="drawer-title">Новая операция</span>
            <button class="btn-close" onclick="closeDrawer()"><i data-lucide="x" style="width:15px;height:15px"></i></button>
        </div>
        <div class="drawer-body">
            <div class="form-group">
                <label class="form-label">Дата</label>
                ${dpField('bop-date')}
            </div>
            <div class="form-group">
                <label class="form-label">Тип</label>
                <select class="form-control" id="bop-type" onchange="toggleBankOpType()">
                    <option value="Прочий приход">Приход</option>
                    <option value="Касса">Касса</option>
                    <option value="Расход">Расход</option>
                    <option value="Перевод">Перевод между счетами</option>
                </select>
            </div>
            <div id="bop-transfer-fields" style="display:none">
                <div class="form-group">
                    <label class="form-label">Со счёта</label>
                    <select class="form-control" id="bop-from-account">${accountOptions}</select>
                </div>
                <div class="form-group">
                    <label class="form-label">На счёт</label>
                    <select class="form-control" id="bop-to-account">${accountOptions}</select>
                </div>
            </div>
            <div id="bop-acquiring-group" style="display:none">
                <div class="form-group">
                    <label class="form-label">% эквайринга</label>
                    <input type="number" class="form-control" id="bop-acquiring" value="1.22" min="0" max="100" step="0.01" oninput="calcAcquiringAmount()">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Наименование</label>
                <input type="text" class="form-control" id="bop-description" placeholder="Например: Аренда офиса">
            </div>
            <div class="form-group">
                <label class="form-label" id="bop-amount-label">Сумма (₽)</label>
                <input type="number" class="form-control" id="bop-amount" placeholder="0" min="0" oninput="calcAcquiringAmount()">
            </div>
            <div id="acquiring-result" style="display:none">
                <div class="form-group">
                    <label class="form-label">К зачислению (₽)</label>
                    <input type="number" class="form-control" id="bop-amount-net" readonly style="opacity:.7">
                    <div style="font-size:11px;color:var(--text-muted);margin-top:4px">Комиссия: <span id="acquiring-fee">0</span> ₽</div>
                </div>
            </div>
            <div class="form-group" id="bop-account-group">
                <label class="form-label">Счёт</label>
                <select class="form-control" id="bop-account">${accountOptions}</select>
            </div>
        </div>
        <div class="drawer-footer">
            <button class="btn btn-ghost" onclick="closeDrawer()">Отмена</button>
            <button class="btn btn-primary" onclick="saveBankOperation()">Сохранить</button>
        </div>
    `);
}

function toggleBankOpType() {
    const type = document.getElementById('bop-type').value;
    const isKassa = type === 'Касса';
    const isTransfer = type === 'Перевод';
    document.getElementById('bop-transfer-fields').style.display = isTransfer ? '' : 'none';
    document.getElementById('bop-account-group').style.display = isTransfer ? 'none' : '';
    document.getElementById('bop-acquiring-group').style.display = isKassa ? '' : 'none';
    document.getElementById('acquiring-result').style.display = isKassa ? '' : 'none';
    document.getElementById('bop-amount-label').textContent = isKassa ? 'Сумма продажи (₽)' : 'Сумма (₽)';
    if (isKassa) calcAcquiringAmount();
}
function toggleAcquiring() { toggleBankOpType(); }

function calcAcquiringAmount() {
    const amount = parseFloat(document.getElementById('bop-amount').value) || 0;
    const pct = parseFloat(document.getElementById('bop-acquiring')?.value) || 1.22;
    const fee = Math.round(amount * pct / 100 * 100) / 100;
    const net = Math.round((amount - fee) * 100) / 100;
    const netEl = document.getElementById('bop-amount-net');
    const feeEl = document.getElementById('acquiring-fee');
    if (netEl) netEl.value = net;
    if (feeEl) feeEl.textContent = fee.toLocaleString('ru-RU');
}

async function saveBankOperation() {
    const date = document.getElementById('bop-date').value;
    const type = document.getElementById('bop-type').value;
    const description = document.getElementById('bop-description').value.trim();

    if (!date) { showToast('Укажите дату', 'error'); return; }

    if (type === 'Перевод') {
        const fromId = document.getElementById('bop-from-account').value;
        const toId = document.getElementById('bop-to-account').value;
        const amount = parseFloat(document.getElementById('bop-amount').value);
        if (!amount || amount <= 0) { showToast('Укажите сумму', 'error'); return; }
        if (fromId === toId) { showToast('Выберите разные счета', 'error'); return; }
        const desc = description || 'Перевод между счетами';
        // Один атомарный запрос: сервер создаёт обе связанные операции в транзакции
        const res = await api('bank.php', 'POST', {
            action: 'transfer',
            from_account_id: fromId,
            to_account_id: toId,
            amount,
            description: desc,
            operation_date: date,
        });
        if (!res || !res.success) return; // api() уже показал ошибку
        showToast('Перевод выполнен');
        closeDrawer();
        setTimeout(reloadPage, 500);
        return;
    }

    const accountId = document.getElementById('bop-account').value;
    if (!accountId) { showToast('Выберите счёт', 'error'); return; }

    let amount, finalType, finalDesc;

    if (type === 'Касса') {
        const gross = parseFloat(document.getElementById('bop-amount').value);
        const pct = parseFloat(document.getElementById('bop-acquiring').value) || 1.22;
        if (!gross || gross <= 0) { showToast('Укажите сумму', 'error'); return; }
        const fee = Math.round(gross * pct / 100 * 100) / 100;
        amount = Math.round((gross - fee) * 100) / 100;
        finalType = 'Прочий приход';
        finalDesc = (description || 'Продажа по кассе') + ` (эквайринг ${pct}%, комиссия ${fee} ₽)`;
    } else {
        amount = parseFloat(document.getElementById('bop-amount').value);
        if (!amount || amount <= 0) { showToast('Укажите сумму', 'error'); return; }
        finalType = type;
        finalDesc = description;
    }

    await api('bank.php', 'POST', { account_id: accountId, type: finalType, amount, description: finalDesc, operation_date: date });
    showToast('Операция добавлена');
    closeDrawer();
    setTimeout(reloadPage, 500);
}

function deleteBankOperation(id) {
    confirmAction('Удалить операцию?', async () => {
        const res = await api(`bank.php?id=${id}`, 'DELETE');
        if (res === undefined) return; // api() показал ошибку
        if (typeof closeModal === 'function') closeModal();
        showToast('Операция удалена');
        if (res && res.warning) showToast(res.warning, 'warning');
        setTimeout(reloadPage, 500);
    });
}

function openEditBalance(id, name, currentBalance) {
    openModal(`
        <div class="modal-header">
            <span class="modal-title">${_esc(name)} — начальный баланс</span>
            <button class="btn-close" onclick="closeModal()"><i data-lucide="x" style="width:15px;height:15px"></i></button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label class="form-label">Начальный баланс (₽)</label>
                <input type="number" class="form-control" id="edit-balance" value="${currentBalance}">
            </div>
            <p style="font-size:12px;color:var(--text-muted);margin-top:8px">
                Стартовый баланс счёта. К нему прибавляются приходы и вычитаются расходы.
            </p>
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" onclick="closeModal()">Отмена</button>
            <button class="btn btn-primary" onclick="saveBalance(${id})">Сохранить</button>
        </div>
    `);
}

async function saveBalance(accountId) {
    const balance = parseFloat(document.getElementById('edit-balance').value) || 0;
    await api('bank.php', 'POST', { update_balance: true, account_id: accountId, initial_balance: balance });
    showToast('Баланс обновлён');
    closeModal();
    setTimeout(reloadPage, 500);
}