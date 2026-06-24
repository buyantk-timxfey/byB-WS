// ===== PROCUREMENT.JS =====

// ── Dashboard tile ────────────────────────────────────────────────────────────

async function loadProcurementTile() {
    try {
        const res = await fetch('api/procurement.php?plans=1');
        const plans = await res.json();
        renderProcurementTile(plans);
    } catch (e) {
        const el = document.getElementById('procurement-tile-body');
        if (el) el.innerHTML = '<p style="color:var(--danger);font-size:13px;">Ошибка загрузки</p>';
    }
}

function renderProcurementTile(plans) {
    const el = document.getElementById('procurement-tile-body');
    if (!el) return;
    el.classList.add('open');
    const chevron = document.getElementById('procurement-tile-chevron');
    if (chevron) chevron.style.transform = 'rotate(180deg)';

    const countEl = document.getElementById('procurement-tile-count');
    if (countEl) countEl.textContent = plans ? plans.length : 0;

    if (!plans || plans.length === 0) {
        el.innerHTML = `
            <div style="display:flex;flex-direction:column;align-items:center;justify-content:center;padding:20px 0;">
                <p style="color:var(--text-dim);font-size:13px;margin-bottom:12px;">Нет планов закупок</p>
                <button class="btn btn-ghost btn-sm" onclick="openCreatePlan()">
                    <i data-lucide="plus" style="width:14px;height:14px;"></i> Новый план
                </button>
            </div>`;
        if (window.lucide) lucide.createIcons();
        return;
    }

    const rows = plans.map(p => `
        <div style="display:flex;align-items:center;justify-content:space-between;padding:8px 10px;border-radius:var(--radius);transition:background 0.15s;cursor:pointer;" onmouseover="this.style.background='rgba(255,255,255,0.04)'" onmouseout="this.style.background=''">
            <div style="display:flex;align-items:center;gap:8px;min-width:0;flex:1;" onclick="openPlanDetail(${p.id})">
                <i data-lucide="clipboard-list" style="width:14px;height:14px;flex-shrink:0;color:var(--text-muted);"></i>
                <span style="font-size:13px;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${escHtml(p.name)}</span>
            </div>
            <div style="display:flex;align-items:center;gap:6px;flex-shrink:0;margin-left:8px;">
                <span style="font-size:11px;color:var(--text-dim);">${p.items_count} поз.</span>
                <span class="${procStatusBadge(p.status)}">${escHtml(p.status)}</span>
                <button class="btn-icon" style="width:24px;height:24px;" onclick="openEditPlanName(${p.id},${JSON.stringify(p.name)})" title="Переименовать">
                    <i data-lucide="pencil" style="width:12px;height:12px;"></i>
                </button>
            </div>
        </div>`).join('');

    el.innerHTML = `
        <div style="display:flex;flex-direction:column;gap:1px;">${rows}</div>
        <div style="margin-top:10px;border-top:1px solid var(--border);padding-top:10px;">
            <button class="btn btn-ghost btn-sm" onclick="openCreatePlan()" style="width:100%;justify-content:center;">
                <i data-lucide="plus" style="width:14px;height:14px;"></i> Новый план
            </button>
        </div>`;

    if (window.lucide) lucide.createIcons();
}

// ── Status badge ──────────────────────────────────────────────────────────────

function procStatusBadge(status) {
    const map = {
        'В работе':     'badge badge-transit',
        'На уточнении': 'badge badge-waiting',
        'Готов':        'badge badge-done',
        'Исполнен':     'badge badge-done',
        'Отменён':      'badge badge-alert',
    };
    return map[status] || 'badge badge-transit';
}

function escHtml(str) {
    if (str == null) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Edit plan name ────────────────────────────────────────────────────────────

function openEditPlanName(planId, currentName) {
    openModal(`
        <div class="modal-header">
            <span class="modal-title">Переименовать план</span>
            <button class="btn-close" onclick="closeModal()"><i data-lucide="x" style="width:15px;height:15px"></i></button>
        </div>
        <div class="modal-body">
            <div class="form-group" style="margin-bottom:0">
                <label class="form-label">Название</label>
                <input id="edit-plan-name" class="form-control" type="text" value="${escHtml(currentName)}">
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" onclick="closeModal()">Отмена</button>
            <button class="btn btn-primary" onclick="submitEditPlanName(${planId})">Сохранить</button>
        </div>
    `);
    setTimeout(() => { const i = document.getElementById('edit-plan-name'); if(i){i.focus();i.select();} }, 50);
}

async function submitEditPlanName(planId) {
    const name = document.getElementById('edit-plan-name')?.value.trim();
    if (!name) { showToast('Введите название', 'error'); return; }
    try {
        const res = await fetch('api/procurement.php', {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ plan_id: planId, name })
        });
        const data = await res.json();
        if (data.success) { closeModal(); showToast('Название обновлено'); loadProcurementTile(); }
        else showToast(data.error || 'Ошибка', 'error');
    } catch (e) { showToast('Ошибка сети', 'error'); }
}

// ── Create plan ───────────────────────────────────────────────────────────────

async function openCreatePlan() {
    openModal(`
        <div class="modal-header">
            <span class="modal-title">Новый план закупок</span>
            <button class="btn-close" onclick="closeModal()"><i data-lucide="x" style="width:15px;height:15px"></i></button>
        </div>
        <div class="modal-body">
            <div class="form-group" style="margin-bottom:0">
                <label class="form-label">Название плана</label>
                <input id="proc-plan-name" class="form-control" type="text" placeholder="Например: Закупка Q3 2026">
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" onclick="closeModal()">Отмена</button>
            <button class="btn btn-primary" onclick="submitCreatePlan()">Создать</button>
        </div>
    `);
    setTimeout(() => document.getElementById('proc-plan-name')?.focus(), 50);
}

async function submitCreatePlan() {
    const name = document.getElementById('proc-plan-name')?.value.trim();
    if (!name) { showToast('Введите название плана', 'error'); return; }
    try {
        const res = await fetch('api/procurement.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'create_plan', name })
        });
        const data = await res.json();
        if (data.success) { closeModal(); showToast('План создан'); loadProcurementTile(); }
        else showToast(data.error || 'Ошибка', 'error');
    } catch (e) { showToast('Ошибка сети', 'error'); }
}

// ── Plan detail ───────────────────────────────────────────────────────────────

async function openPlanDetail(id) {
    try {
        const res = await fetch(`api/procurement.php?plan_id=${id}`);
        const data = await res.json();
        if (data.error) { showToast(data.error, 'error'); return; }
        renderPlanDetail(data);
    } catch (e) { showToast('Ошибка загрузки плана', 'error'); }
}

function renderPlanDetail(data) {
    const { plan, items } = data;

    const statusOptions = ['В работе','На уточнении','Готов','Исполнен','Отменён'];
    const statusSelect = `
        <select id="proc-status-select" class="form-control" style="width:auto;font-size:13px;" onchange="changePlanStatus(${plan.id},this.value)">
            ${statusOptions.map(s => `<option value="${escHtml(s)}"${s===plan.status?' selected':''}>${escHtml(s)}</option>`).join('')}
        </select>`;

    const createShipmentsBtn = plan.status === 'Готов' ? `
        <button class="btn btn-primary btn-sm" onclick="createShipmentsFromPlan(${plan.id})">
            <i data-lucide="package" style="width:14px;height:14px;"></i> Создать поставки
        </button>` : '';

    const deletePlanBtn = `
        <button class="btn btn-ghost btn-sm" style="color:var(--danger);" onclick="deletePlan(${plan.id})">
            <i data-lucide="trash-2" style="width:14px;height:14px;"></i>
        </button>`;

    const itemsHtml = items.length === 0
        ? `<p style="color:var(--text-dim);font-size:13px;padding:12px 0;">Нет позиций. Добавьте первую.</p>`
        : items.map(item => renderItemSection(item, plan.id)).join('');

    openWideModal(`
        <div class="modal-header" style="flex-wrap:wrap;gap:8px;">
            <div style="display:flex;align-items:center;gap:10px;min-width:0;">
                <i data-lucide="clipboard-list" style="width:18px;height:18px;color:var(--accent);flex-shrink:0;"></i>
                <span class="modal-title">${escHtml(plan.name)}</span>
            </div>
            <div style="display:flex;align-items:center;gap:8px;flex-shrink:0;margin-left:auto;">
                ${statusSelect}
                ${createShipmentsBtn}
                ${deletePlanBtn}
                <button class="btn-close" onclick="closeModal()"><i data-lucide="x" style="width:15px;height:15px"></i></button>
            </div>
        </div>
        <div class="modal-body" id="proc-plan-items-body">
            ${itemsHtml}
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" onclick="addPlanItem(${plan.id})">
                <i data-lucide="plus" style="width:14px;height:14px;"></i> Добавить товар
            </button>
        </div>
    `);

    if (window.lucide) lucide.createIcons();
}

function renderItemSection(item, planId) {
    const hasVariants = item.variants && item.variants.length > 0;

    let bestPriceId = null, bestDelivId = null;
    if (hasVariants) {
        const prices = item.variants.map(v => parseFloat(v.price)||Infinity);
        const delivs = item.variants.map(v => v.delivery_days!=null ? parseInt(v.delivery_days) : Infinity);
        const minP   = Math.min(...prices);
        const minD   = Math.min(...delivs);
        if (isFinite(minP)) { const bpv = item.variants.find(v => (parseFloat(v.price)||Infinity)===minP); if(bpv) bestPriceId=bpv.id; }
        if (isFinite(minD)) { const bdv = item.variants.find(v => (v.delivery_days!=null?parseInt(v.delivery_days):Infinity)===minD); if(bdv) bestDelivId=bdv.id; }
    }

    const variantsTable = hasVariants ? `
        <div style="overflow-x:auto;margin-top:10px;">
            <table style="width:100%;border-collapse:collapse;font-size:12.5px;">
                <thead>
                    <tr style="border-bottom:1px solid var(--border);">
                        <th style="text-align:left;padding:7px 10px;font-weight:600;color:var(--text-muted);font-size:11px;text-transform:uppercase;letter-spacing:0.5px;">Поставщик</th>
                        <th style="text-align:right;padding:7px 10px;font-weight:600;color:var(--text-muted);font-size:11px;text-transform:uppercase;letter-spacing:0.5px;">Цена</th>
                        <th style="text-align:right;padding:7px 10px;font-weight:600;color:var(--text-muted);font-size:11px;text-transform:uppercase;letter-spacing:0.5px;">Срок (дн)</th>
                        <th style="text-align:right;padding:7px 10px;font-weight:600;color:var(--text-muted);font-size:11px;text-transform:uppercase;letter-spacing:0.5px;">Доставка</th>
                        <th style="text-align:left;padding:7px 10px;font-weight:600;color:var(--text-muted);font-size:11px;text-transform:uppercase;letter-spacing:0.5px;">Ссылка</th>
                        <th style="text-align:left;padding:7px 10px;font-weight:600;color:var(--text-muted);font-size:11px;text-transform:uppercase;letter-spacing:0.5px;">Примечание</th>
                        <th style="text-align:center;padding:7px 10px;font-weight:600;color:var(--text-muted);font-size:11px;text-transform:uppercase;letter-spacing:0.5px;">Выбрать</th>
                        <th style="padding:7px 4px;width:60px;"></th>
                    </tr>
                </thead>
                <tbody>
                    ${item.variants.map(v => {
                        const isWinner    = item.winner_variant_id == v.id;
                        const isBestPrice = bestPriceId == v.id;
                        const isBestDeliv = !isBestPrice && bestDelivId == v.id;
                        let rowBg = '';
                        if (isWinner)         rowBg = 'background:rgba(34,197,94,0.07);';
                        else if (isBestPrice) rowBg = 'background:rgba(34,197,94,0.04);';
                        else if (isBestDeliv) rowBg = 'background:rgba(59,130,246,0.04);';

                        // Delivery cell
                        let delivCell;
                        if (v.carrier_cost != null && v.carrier_cost !== '' && parseFloat(v.carrier_cost) > 0) {
                            delivCell = `<td style="text-align:right;padding:7px 10px;color:var(--warning);">+${Number(v.carrier_cost).toLocaleString('ru-RU')} ₽</td>`;
                        } else {
                            delivCell = `<td style="text-align:right;padding:7px 10px;color:var(--text-dim);">—</td>`;
                        }

                        const linkCell = v.link
                            ? `<td style="padding:7px 10px;"><a href="${escHtml(v.link)}" target="_blank" rel="noopener" style="color:var(--accent);font-size:12px;display:flex;align-items:center;gap:3px;"><i data-lucide="external-link" style="width:11px;height:11px;"></i>открыть</a></td>`
                            : `<td style="padding:7px 10px;color:var(--text-dim);">—</td>`;

                        const noteCell = v.notes
                            ? `<td style="padding:7px 10px;color:var(--text-muted);max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${escHtml(v.notes)}">${escHtml(v.notes)}</td>`
                            : `<td style="padding:7px 10px;color:var(--text-dim);">—</td>`;

                        const winnerCell = isWinner
                            ? `<td style="text-align:center;padding:7px 10px;"><i data-lucide="star" style="width:14px;height:14px;color:#F59E0B;fill:#F59E0B;"></i></td>`
                            : `<td style="text-align:center;padding:7px 10px;">
                                <button class="btn btn-ghost btn-sm" style="font-size:11px;padding:2px 8px;" onclick="selectWinner(${item.id},${v.id},${planId})">
                                    <i data-lucide="check" style="width:11px;height:11px;"></i> Выбрать
                                </button>
                               </td>`;

                        return `<tr style="${rowBg}border-bottom:1px solid var(--border);">
                            <td style="padding:7px 10px;color:var(--text);font-weight:500;">${escHtml(v.supplier_name||'—')}</td>
                            <td style="text-align:right;padding:7px 10px;${isBestPrice?'color:var(--success);font-weight:600;':''}font-variant-numeric:tabular-nums;">${v.price!=null?Number(v.price).toLocaleString('ru-RU')+' ₽':'—'}</td>
                            <td style="text-align:right;padding:7px 10px;${isBestDeliv?'color:var(--info);font-weight:600;':''}">${v.delivery_days!=null?v.delivery_days:'—'}</td>
                            ${delivCell}
                            ${linkCell}
                            ${noteCell}
                            ${winnerCell}
                            <td style="padding:7px 4px;">
                                <div style="display:flex;gap:2px;">
                                    <button class="btn-icon" style="width:26px;height:26px;" onclick="openEditVariant(${v.id},${item.id},${planId},${JSON.stringify(v)})" title="Редактировать">
                                        <i data-lucide="pencil" style="width:12px;height:12px;"></i>
                                    </button>
                                    <button class="btn-icon" style="width:26px;height:26px;color:var(--danger);" onclick="deleteVariant(${v.id},${item.id},${planId})" title="Удалить">
                                        <i data-lucide="trash-2" style="width:12px;height:12px;"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>`;
                    }).join('')}
                </tbody>
            </table>
        </div>` : `<p style="font-size:12px;color:var(--text-dim);margin:8px 0 4px;">Нет вариантов поставки.</p>`;

    return `
        <div style="border:1px solid var(--border);border-radius:var(--radius-lg);margin-bottom:10px;overflow:hidden;" id="proc-item-${item.id}">
            <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 14px;background:var(--bg-subtle);">
                <div style="display:flex;align-items:center;gap:8px;min-width:0;flex:1;">
                    ${item.winner_variant_id
                        ? `<i data-lucide="check-circle" style="width:14px;height:14px;color:var(--success);flex-shrink:0;"></i>`
                        : `<i data-lucide="circle" style="width:14px;height:14px;color:var(--text-dim);flex-shrink:0;"></i>`}
                    <span style="font-size:14px;font-weight:500;color:var(--text);">${escHtml(item.name)}</span>
                    <span style="font-size:12px;color:var(--text-dim);">× ${item.quantity}</span>
                </div>
                <div style="display:flex;align-items:center;gap:4px;flex-shrink:0;">
                    <button class="btn btn-ghost btn-sm" onclick="openEditItem(${item.id},${planId},${JSON.stringify(item.name)},${item.quantity})" title="Редактировать позицию">
                        <i data-lucide="pencil" style="width:12px;height:12px;"></i>
                    </button>
                    <button class="btn btn-ghost btn-sm" onclick="openAddVariant(${item.id},${planId})">
                        <i data-lucide="plus" style="width:12px;height:12px;"></i> Вариант
                    </button>
                    <button class="btn-icon" style="color:var(--danger);width:28px;height:28px;" onclick="deleteItem(${item.id},${planId})" title="Удалить позицию">
                        <i data-lucide="trash-2" style="width:13px;height:13px;"></i>
                    </button>
                </div>
            </div>
            <div style="padding:6px 14px 14px;">${variantsTable}</div>
        </div>`;
}

// ── Edit item ─────────────────────────────────────────────────────────────────

function openEditItem(itemId, planId, currentName, currentQty) {
    openModal(`
        <div class="modal-header">
            <span class="modal-title">Редактировать позицию</span>
            <button class="btn-close" onclick="closeModal()"><i data-lucide="x" style="width:15px;height:15px"></i></button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label class="form-label">Название товара</label>
                <input id="edit-item-name" class="form-control" type="text" value="${escHtml(currentName)}">
            </div>
            <div class="form-group" style="margin-bottom:0">
                <label class="form-label">Количество</label>
                <input id="edit-item-qty" class="form-control" type="number" min="1" value="${currentQty}">
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" onclick="closeModal();openPlanDetail(${planId})">Отмена</button>
            <button class="btn btn-primary" onclick="submitEditItem(${itemId},${planId})">Сохранить</button>
        </div>
    `);
    setTimeout(() => { const i = document.getElementById('edit-item-name'); if(i){i.focus();i.select();} }, 50);
}

async function submitEditItem(itemId, planId) {
    const name = document.getElementById('edit-item-name')?.value.trim();
    const qty  = parseInt(document.getElementById('edit-item-qty')?.value) || 1;
    if (!name) { showToast('Введите название', 'error'); return; }
    try {
        const res = await fetch('api/procurement.php', {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ item_id: itemId, name, quantity: qty })
        });
        const data = await res.json();
        if (data.success) { showToast('Позиция обновлена'); openPlanDetail(planId); }
        else showToast(data.error || 'Ошибка', 'error');
    } catch (e) { showToast('Ошибка сети', 'error'); }
}

// ── Add plan item ─────────────────────────────────────────────────────────────

async function addPlanItem(planId) {
    const body = document.getElementById('proc-plan-items-body');
    if (!body) return;
    const existing = document.getElementById('proc-inline-add-item');
    if (existing) existing.remove();

    const form = document.createElement('div');
    form.id = 'proc-inline-add-item';
    form.style.cssText = 'border:1px dashed var(--accent);border-radius:var(--radius-lg);padding:12px 14px;margin-bottom:10px;';
    form.innerHTML = `
        <div style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;">
            <div class="form-group" style="flex:2;min-width:140px;margin-bottom:0;">
                <label class="form-label">Название товара</label>
                <input id="proc-new-item-name" class="form-control" type="text" placeholder="Название">
            </div>
            <div class="form-group" style="width:80px;margin-bottom:0;">
                <label class="form-label">Кол-во</label>
                <input id="proc-new-item-qty" class="form-control" type="number" value="1" min="1">
            </div>
            <button class="btn btn-primary btn-sm" onclick="submitAddItem(${planId})">Добавить</button>
            <button class="btn btn-ghost btn-sm" onclick="document.getElementById('proc-inline-add-item').remove()">Отмена</button>
        </div>`;
    body.appendChild(form);
    document.getElementById('proc-new-item-name')?.focus();
    if (window.lucide) lucide.createIcons();
}

async function submitAddItem(planId) {
    const name = document.getElementById('proc-new-item-name')?.value.trim();
    const qty  = parseInt(document.getElementById('proc-new-item-qty')?.value) || 1;
    if (!name) { showToast('Введите название', 'error'); return; }
    try {
        const res = await fetch('api/procurement.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'add_item', plan_id: planId, name, quantity: qty })
        });
        const data = await res.json();
        if (data.success) { showToast('Позиция добавлена'); openPlanDetail(planId); loadProcurementTile(); }
        else showToast(data.error || 'Ошибка', 'error');
    } catch (e) { showToast('Ошибка сети', 'error'); }
}

// ── Variant form (shared HTML) ────────────────────────────────────────────────

function _variantFormHtml(v) {
    v = v || {};
    const hasCarrierCost = v.carrier_cost != null && v.carrier_cost !== '' && parseFloat(v.carrier_cost) > 0;
    return `
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
            <div class="form-group">
                <label class="form-label">Поставщик</label>
                <input id="pv-supplier" class="form-control" type="text" placeholder="Название поставщика" value="${escHtml(v.supplier_name||'')}">
            </div>
            <div class="form-group">
                <label class="form-label">Цена (₽)</label>
                <input id="pv-price" class="form-control" type="number" step="0.01" placeholder="0.00" value="${v.price!=null?v.price:''}">
            </div>
            <div class="form-group">
                <label class="form-label">Срок доставки (дней)</label>
                <input id="pv-delivery" class="form-control" type="number" placeholder="7" value="${v.delivery_days!=null?v.delivery_days:''}">
            </div>
            <div class="form-group">
                <label class="form-label">Доставка</label>
                <div style="display:flex;gap:8px;align-items:center;">
                    <select id="pv-carrier-type" class="form-control" style="flex:1;" onchange="toggleCarrierCost()">
                        <option value="none"${!hasCarrierCost?' selected':''}>— Не участвует</option>
                        <option value="cost"${hasCarrierCost?' selected':''}>+ К стоимости</option>
                    </select>
                </div>
                <div id="pv-carrier-cost-wrap" style="margin-top:6px;${hasCarrierCost?'':'display:none;'}">
                    <input id="pv-carrier-cost" class="form-control" type="number" step="0.01" placeholder="Сумма доставки" value="${hasCarrierCost?v.carrier_cost:''}">
                </div>
            </div>
        </div>
        <div class="form-group">
            <label class="form-label">Ссылка</label>
            <input id="pv-link" class="form-control" type="url" placeholder="https://..." value="${escHtml(v.link||'')}">
        </div>
        <div class="form-group" style="margin-bottom:0">
            <label class="form-label">Примечание</label>
            <textarea id="pv-notes" class="form-control" rows="2" placeholder="Дополнительная информация...">${escHtml(v.notes||'')}</textarea>
        </div>`;
}

function toggleCarrierCost() {
    const sel  = document.getElementById('pv-carrier-type');
    const wrap = document.getElementById('pv-carrier-cost-wrap');
    if (sel && wrap) wrap.style.display = sel.value === 'cost' ? 'block' : 'none';
}

// ── Add variant ───────────────────────────────────────────────────────────────

async function openAddVariant(itemId, planId) {
    openModal(`
        <div class="modal-header">
            <span class="modal-title">Добавить вариант поставки</span>
            <button class="btn-close" onclick="closeModal();openPlanDetail(${planId})"><i data-lucide="x" style="width:15px;height:15px"></i></button>
        </div>
        <div class="modal-body">${_variantFormHtml()}</div>
        <div class="modal-footer">
            <button class="btn btn-ghost" onclick="closeModal();openPlanDetail(${planId})">Отмена</button>
            <button class="btn btn-primary" onclick="submitAddVariant(${itemId},${planId})">Сохранить</button>
        </div>
    `);
    if (window.lucide) lucide.createIcons();
    setTimeout(() => document.getElementById('pv-supplier')?.focus(), 50);
}

async function submitAddVariant(itemId, planId) {
    const supplier     = document.getElementById('pv-supplier')?.value.trim();
    const price        = document.getElementById('pv-price')?.value;
    const deliveryDays = document.getElementById('pv-delivery')?.value;
    const carrierType  = document.getElementById('pv-carrier-type')?.value;
    const carrierCost  = carrierType === 'cost' ? (document.getElementById('pv-carrier-cost')?.value || null) : null;
    const link         = document.getElementById('pv-link')?.value.trim();
    const notes        = document.getElementById('pv-notes')?.value.trim();

    try {
        const res = await fetch('api/procurement.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action:'add_variant', item_id:itemId, supplier_name:supplier, price:price||0, delivery_days:deliveryDays||'', carrier_cost:carrierCost, link, notes })
        });
        const data = await res.json();
        if (data.success) { showToast('Вариант добавлен'); openPlanDetail(planId); }
        else showToast(data.error || 'Ошибка', 'error');
    } catch (e) { showToast('Ошибка сети', 'error'); }
}

// ── Edit variant ──────────────────────────────────────────────────────────────

function openEditVariant(variantId, itemId, planId, v) {
    openModal(`
        <div class="modal-header">
            <span class="modal-title">Редактировать вариант</span>
            <button class="btn-close" onclick="closeModal();openPlanDetail(${planId})"><i data-lucide="x" style="width:15px;height:15px"></i></button>
        </div>
        <div class="modal-body">${_variantFormHtml(v)}</div>
        <div class="modal-footer">
            <button class="btn btn-ghost" onclick="closeModal();openPlanDetail(${planId})">Отмена</button>
            <button class="btn btn-primary" onclick="submitEditVariant(${variantId},${itemId},${planId})">Сохранить</button>
        </div>
    `);
    if (window.lucide) lucide.createIcons();
    setTimeout(() => document.getElementById('pv-supplier')?.focus(), 50);
}

async function submitEditVariant(variantId, itemId, planId) {
    const supplier     = document.getElementById('pv-supplier')?.value.trim();
    const price        = document.getElementById('pv-price')?.value;
    const deliveryDays = document.getElementById('pv-delivery')?.value;
    const carrierType  = document.getElementById('pv-carrier-type')?.value;
    const carrierCost  = carrierType === 'cost' ? (document.getElementById('pv-carrier-cost')?.value || null) : null;
    const link         = document.getElementById('pv-link')?.value.trim();
    const notes        = document.getElementById('pv-notes')?.value.trim();

    try {
        const res = await fetch('api/procurement.php', {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ variant_id:variantId, supplier_name:supplier, price:price||0, delivery_days:deliveryDays||null, carrier_cost:carrierCost, link, notes })
        });
        const data = await res.json();
        if (data.success) { showToast('Вариант обновлён'); openPlanDetail(planId); }
        else showToast(data.error || 'Ошибка', 'error');
    } catch (e) { showToast('Ошибка сети', 'error'); }
}

// ── Select winner ─────────────────────────────────────────────────────────────

async function selectWinner(itemId, variantId, planId) {
    try {
        const res = await fetch('api/procurement.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action:'select_winner', item_id:itemId, variant_id:variantId })
        });
        const data = await res.json();
        if (data.success) { showToast('Победитель выбран'); openPlanDetail(planId); }
        else showToast(data.error || 'Ошибка', 'error');
    } catch (e) { showToast('Ошибка сети', 'error'); }
}

// ── Change plan status ────────────────────────────────────────────────────────

async function changePlanStatus(planId, status) {
    try {
        const res = await fetch('api/procurement.php', {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ plan_id:planId, status })
        });
        const data = await res.json();
        if (data.success) { showToast('Статус обновлён'); loadProcurementTile(); openPlanDetail(planId); }
        else showToast(data.error || 'Ошибка', 'error');
    } catch (e) { showToast('Ошибка сети', 'error'); }
}

// ── Create shipments from plan ────────────────────────────────────────────────

async function createShipmentsFromPlan(planId) {
    try {
        const res = await fetch('api/procurement.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action:'create_shipments_from_plan', plan_id:planId })
        });
        const data = await res.json();
        if (data.success) {
            showToast(`Создано поставок: ${data.shipment_ids?.length||0}`);
            if (data.warning) showToast(data.warning, 'warning');
            closeModal(); loadProcurementTile();
        } else showToast(data.error || 'Ошибка', 'error');
    } catch (e) { showToast('Ошибка сети', 'error'); }
}

// ── Delete plan / item / variant ──────────────────────────────────────────────

async function deletePlan(planId) {
    confirmAction('Удалить план закупок? Все позиции и варианты будут удалены.', async () => {
        try {
            const res = await fetch(`api/procurement.php?plan_id=${planId}`, { method:'DELETE' });
            const data = await res.json();
            if (data.success) { showToast('План удалён'); closeModal(); loadProcurementTile(); }
            else showToast(data.error || 'Ошибка', 'error');
        } catch (e) { showToast('Ошибка сети', 'error'); }
    });
}

async function deleteItem(itemId, planId) {
    confirmAction('Удалить позицию и все её варианты?', async () => {
        try {
            const res = await fetch(`api/procurement.php?item_id=${itemId}`, { method:'DELETE' });
            const data = await res.json();
            if (data.success) { showToast('Позиция удалена'); openPlanDetail(planId); loadProcurementTile(); }
            else showToast(data.error || 'Ошибка', 'error');
        } catch (e) { showToast('Ошибка сети', 'error'); }
    });
}

async function deleteVariant(variantId, itemId, planId) {
    confirmAction('Удалить вариант поставки?', async () => {
        try {
            const res = await fetch(`api/procurement.php?variant_id=${variantId}`, { method:'DELETE' });
            const data = await res.json();
            if (data.success) { showToast('Вариант удалён'); openPlanDetail(planId); }
            else showToast(data.error || 'Ошибка', 'error');
        } catch (e) { showToast('Ошибка сети', 'error'); }
    });
}

// ── Tile toggle ───────────────────────────────────────────────────────────────

function toggleProcurementTile() {
    const body    = document.getElementById('procurement-tile-body');
    const chevron = document.getElementById('procurement-tile-chevron');
    if (!body) return;
    body.classList.toggle('open');
    if (chevron) chevron.style.transform = body.classList.contains('open') ? 'rotate(180deg)' : '';
}

document.addEventListener('DOMContentLoaded', () => { loadProcurementTile(); });
