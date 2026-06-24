// ═══════════ Маршрутные листы: топливо и пробег ═══════════

function _vehToday() { return new Date().toISOString().slice(0, 10); }

// ── Пробег (по одометру) ──────────────────────────────────────────────────────
function openTrip(t) {
    const e = t && t.id ? t : null;
    const s = window._vehSettings || {};
    const prevOdo = s.last_odometer || s.start_odometer || 0;
    openModal(`
        <div class="modal-header">
            <span class="modal-title">${e ? 'Запись пробега' : 'Новая запись пробега'}</span>
            <button class="btn-close" onclick="closeModal()"><i data-lucide="x" style="width:15px;height:15px"></i></button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label class="form-label">Дата</label>
                <input type="date" id="trip-from" class="form-control" value="${e ? e.date_from : _vehToday()}">
            </div>
            <div class="form-group">
                <label class="form-label">Одометр, км <span style="color:var(--text-dim);font-size:11px">(показание спидометра)</span></label>
                <input type="number" id="trip-odo" class="form-control" min="0" inputmode="numeric" value="${e ? e.odometer : ''}" placeholder="${prevOdo}">
                ${!e ? `<div style="font-size:11px;color:var(--text-dim);margin-top:4px">Последнее показание: ${formatNum(prevOdo)} км · проедено будет посчитано как разница</div>` : ''}
            </div>
            <div class="form-group">
                <label class="form-label">Заметка <span style="color:var(--text-dim);font-size:11px">(необязательно)</span></label>
                <input type="text" id="trip-note" class="form-control" value="${e ? (e.note || '') : ''}" placeholder="Маршрут, цель…">
            </div>
        </div>
        <div class="modal-footer">
            ${e ? `<button class="btn btn-danger" onclick="deleteVeh('trip', ${e.id})">Удалить</button>` : ''}
            <button class="btn btn-ghost" onclick="closeModal()">Отмена</button>
            <button class="btn btn-primary" onclick="saveTrip(${e ? e.id : 0})">Сохранить</button>
        </div>
    `);
    if (window.lucide) lucide.createIcons();
}

function formatNum(v) { return (v || 0).toLocaleString('ru-RU'); }

async function saveTrip(id) {
    const body = {
        entity: 'trip',
        date_from: document.getElementById('trip-from').value,
        odometer:  parseInt(document.getElementById('trip-odo').value) || 0,
        note:      document.getElementById('trip-note').value.trim() || null,
    };
    if (!body.date_from) { showToast('Укажите дату', 'error'); return; }
    if (!body.odometer)  { showToast('Укажите показание одометра', 'error'); return; }
    if (id) body.id = id;
    const r = await api('vehicle.php', id ? 'PUT' : 'POST', body);
    if (r && !r.error) { showToast('Сохранено'); closeModal(); setTimeout(reloadPage, 400); }
}

// ── Мойка / списание с карты ──────────────────────────────────────────────────
function openWash(w) {
    const e = w && w.id ? w : null;
    openModal(`
        <div class="modal-header">
            <span class="modal-title">${e ? 'Списание с карты' : 'Мойка / списание с карты'}</span>
            <button class="btn-close" onclick="closeModal()"><i data-lucide="x" style="width:15px;height:15px"></i></button>
        </div>
        <div class="modal-body">
            <div style="font-size:12px;color:var(--text-muted);margin-bottom:12px">Списывается с баланса топливной карты. В расходы и P&amp;L не попадает.</div>
            <div style="display:flex;gap:10px">
                <div class="form-group" style="flex:1">
                    <label class="form-label">Дата</label>
                    <input type="date" id="wash-date" class="form-control" value="${e ? e.wash_date : _vehToday()}">
                </div>
                <div class="form-group" style="flex:1">
                    <label class="form-label">Сумма, ₽</label>
                    <input type="number" id="wash-amount" class="form-control" min="0" step="1" inputmode="numeric" value="${e ? e.amount : ''}" placeholder="0">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Заметка <span style="color:var(--text-dim);font-size:11px">(необязательно)</span></label>
                <input type="text" id="wash-note" class="form-control" value="${e ? (e.note || '') : ''}" placeholder="Мойка">
            </div>
        </div>
        <div class="modal-footer">
            ${e ? `<button class="btn btn-danger" onclick="deleteVeh('wash', ${e.id})">Удалить</button>` : ''}
            <button class="btn btn-ghost" onclick="closeModal()">Отмена</button>
            <button class="btn btn-primary" onclick="saveWash(${e ? e.id : 0})">Сохранить</button>
        </div>
    `);
    if (window.lucide) lucide.createIcons();
}

async function saveWash(id) {
    const body = {
        entity: 'wash',
        wash_date: document.getElementById('wash-date').value,
        amount: parseFloat(document.getElementById('wash-amount').value) || 0,
        note: document.getElementById('wash-note').value.trim() || null,
    };
    if (!body.wash_date) { showToast('Укажите дату', 'error'); return; }
    if (id) body.id = id;
    const r = await api('vehicle.php', id ? 'PUT' : 'POST', body);
    if (r && !r.error) { showToast('Сохранено'); closeModal(); setTimeout(reloadPage, 400); }
}

// ── Заправка ────────────────────────────────────────────────────────────────
function openFuelUp(f) {
    const e = f && f.id ? f : null;
    const card = e ? e.card_type : 'Топливная';
    openModal(`
        <div class="modal-header">
            <span class="modal-title">${e ? 'Заправка' : 'Новая заправка'}</span>
            <button class="btn-close" onclick="closeModal()"><i data-lucide="x" style="width:15px;height:15px"></i></button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label class="form-label">Дата</label>
                <input type="date" id="fu-date" class="form-control" value="${e ? e.fuel_date : _vehToday()}">
            </div>
            <div style="display:flex;gap:10px">
                <div class="form-group" style="flex:1">
                    <label class="form-label">Литры</label>
                    <input type="number" id="fu-liters" class="form-control" min="0" step="0.01" inputmode="decimal" value="${e ? e.liters : ''}" placeholder="0">
                </div>
                <div class="form-group" style="flex:1">
                    <label class="form-label">Сумма, ₽</label>
                    <input type="number" id="fu-amount" class="form-control" min="0" step="0.01" inputmode="decimal" value="${e ? e.amount : ''}" placeholder="0">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Карта</label>
                <select id="fu-card" class="form-control" onchange="vehToggleExpense()">
                    <option value="Топливная" ${card === 'Топливная' ? 'selected' : ''}>Топливная карта</option>
                    <option value="Обычная" ${card === 'Обычная' ? 'selected' : ''}>Обычная карта</option>
                </select>
            </div>
            <div class="form-group" id="fu-expense-row" style="display:none">
                <label class="form-label">Связать с расходом ИП <span style="color:var(--text-dim);font-size:11px">(чтобы не считать дважды)</span></label>
                <select id="fu-expense" class="form-control"><option value="">— загрузка… —</option></select>
            </div>
            <div class="form-group">
                <label class="form-label">Заметка <span style="color:var(--text-dim);font-size:11px">(необязательно)</span></label>
                <input type="text" id="fu-note" class="form-control" value="${e ? (e.note || '') : ''}" placeholder="АЗС, заметка…">
            </div>
        </div>
        <div class="modal-footer">
            ${e ? `<button class="btn btn-danger" onclick="deleteVeh('fuelup', ${e.id})">Удалить</button>` : ''}
            <button class="btn btn-ghost" onclick="closeModal()">Отмена</button>
            <button class="btn btn-primary" onclick="saveFuelUp(${e ? e.id : 0})">Сохранить</button>
        </div>
    `);
    if (window.lucide) lucide.createIcons();
    vehLoadExpenses(e && e.expense_id ? { id: e.expense_id, name: e.expense_name } : null);
    vehToggleExpense();
}

function vehToggleExpense() {
    const card = document.getElementById('fu-card')?.value;
    const row = document.getElementById('fu-expense-row');
    if (row) row.style.display = card === 'Обычная' ? '' : 'none';
}

async function vehLoadExpenses(current) {
    const sel = document.getElementById('fu-expense');
    if (!sel) return;
    let list = [];
    try { list = await api('vehicle.php?expenses=1'); } catch (e) {}
    let opts = '<option value="">— не связывать —</option>';
    if (current && current.id) opts += `<option value="${current.id}" selected>${current.name || 'расход'} (текущий)</option>`;
    (list || []).forEach(x => {
        opts += `<option value="${x.id}">${x.name} · ${formatPrice(x.amount)} · ${formatDate(x.expense_date)}</option>`;
    });
    sel.innerHTML = opts;
}

async function saveFuelUp(id) {
    const card = document.getElementById('fu-card').value;
    const body = {
        entity: 'fuelup',
        fuel_date: document.getElementById('fu-date').value,
        liters: parseFloat(document.getElementById('fu-liters').value) || 0,
        amount: parseFloat(document.getElementById('fu-amount').value) || 0,
        card_type: card,
        expense_id: card === 'Обычная' ? (document.getElementById('fu-expense').value || null) : null,
        note: document.getElementById('fu-note').value.trim() || null,
    };
    if (!body.fuel_date) { showToast('Укажите дату', 'error'); return; }
    if (id) body.id = id;
    const r = await api('vehicle.php', id ? 'PUT' : 'POST', body);
    if (r && !r.error) { showToast('Сохранено'); closeModal(); setTimeout(reloadPage, 400); }
}

// ── Пополнение топливной карты ────────────────────────────────────────────────
function openTopup() {
    openModal(`
        <div class="modal-header">
            <span class="modal-title">Пополнение топливной карты</span>
            <button class="btn-close" onclick="closeModal()"><i data-lucide="x" style="width:15px;height:15px"></i></button>
        </div>
        <div class="modal-body">
            <div style="font-size:12px;color:var(--text-muted);margin-bottom:12px">
                Выберите расход ИП — пополнение карты. Он уйдёт в баланс карты и не будет считаться дважды.
            </div>
            <div class="form-group">
                <label class="form-label">Расход ИП</label>
                <select id="tu-expense" class="form-control"><option value="">— загрузка… —</option></select>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" onclick="closeModal()">Отмена</button>
            <button class="btn btn-primary" onclick="saveTopup()">Добавить</button>
        </div>
    `);
    if (window.lucide) lucide.createIcons();
    (async () => {
        const sel = document.getElementById('tu-expense');
        let list = [];
        try { list = await api('vehicle.php?expenses=1'); } catch (e) {}
        let opts = '<option value="">— выбрать —</option>';
        (list || []).forEach(x => { opts += `<option value="${x.id}">${x.name} · ${formatPrice(x.amount)} · ${formatDate(x.expense_date)}</option>`; });
        sel.innerHTML = opts;
    })();
}

async function saveTopup() {
    const eid = document.getElementById('tu-expense').value;
    if (!eid) { showToast('Выберите расход', 'error'); return; }
    const r = await api('vehicle.php', 'POST', { entity: 'topup', expense_id: eid });
    if (r && !r.error) { showToast('Пополнение добавлено'); closeModal(); setTimeout(reloadPage, 400); }
}

// ── Настройки ─────────────────────────────────────────────────────────────────
function openVehicleSettings() {
    const s = window._vehSettings || { consumption_rate: 8.8, start_odometer: 0 };
    openModal(`
        <div class="modal-header">
            <span class="modal-title">Настройки авто</span>
            <button class="btn-close" onclick="closeModal()"><i data-lucide="x" style="width:15px;height:15px"></i></button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label class="form-label">Стартовый одометр, км</label>
                <input type="number" id="vs-start" class="form-control" min="0" step="1" inputmode="numeric" value="${s.start_odometer}">
                <div style="font-size:11px;color:var(--text-dim);margin-top:4px">Показание спидометра, с которого начинаем учёт.</div>
            </div>
            <div class="form-group">
                <label class="form-label">Расход, л/100 км</label>
                <input type="number" id="vs-rate" class="form-control" min="0.1" step="0.1" value="${s.consumption_rate}">
                <div style="font-size:11px;color:var(--text-dim);margin-top:4px">Норматив для расчёта расхода по пробегу.</div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" onclick="closeModal()">Отмена</button>
            <button class="btn btn-primary" onclick="saveVehicleSettings()">Сохранить</button>
        </div>
    `);
    if (window.lucide) lucide.createIcons();
}

async function saveVehicleSettings() {
    const body = {
        entity: 'settings',
        consumption_rate: parseFloat(document.getElementById('vs-rate').value) || 8.8,
        start_odometer: parseInt(document.getElementById('vs-start').value) || 0,
    };
    const r = await api('vehicle.php', 'PUT', body);
    if (r && !r.error) { showToast('Настройки сохранены'); closeModal(); setTimeout(reloadPage, 400); }
}

// ── Удаление ────────────────────────────────────────────────────────────────
function deleteVeh(entity, id) {
    const names = { trip: 'запись пробега', fuelup: 'заправку', topup: 'пополнение', wash: 'списание' };
    confirmAction(`Удалить ${names[entity] || 'запись'}?`, async () => {
        const r = await api(`vehicle.php?entity=${entity}&id=${id}`, 'DELETE');
        if (r && !r.error) { showToast('Удалено'); closeModal(); setTimeout(reloadPage, 400); }
    });
}
