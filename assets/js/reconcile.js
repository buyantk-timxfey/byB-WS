// ═══════════════════════════════════════════════════════════════════════════
// RECONCILE.JS — калибровка баланса по банковской выписке
// ═══════════════════════════════════════════════════════════════════════════

async function openBalanceCalibration() {
    const accounts = await api('bank.php?accounts=1');
    if (!accounts) return;
    const accOptions = accounts.map(a => `<option value="${a.id}">${_escHtml(a.name)}</option>`).join('');

    openModal(`
        <div class="modal-header">
            <span class="modal-title">Калибровка баланса</span>
            <button class="btn-close" onclick="closeModal()"><i data-lucide="x" style="width:15px;height:15px"></i></button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label class="form-label">Банковский счёт</label>
                <select class="form-control" id="cal-account">${accOptions}</select>
            </div>
            <div class="form-group">
                <label class="form-label">Файл выписки (.txt, формат 1CClientBankExchange)</label>
                <input type="file" class="form-control" id="cal-file" accept=".txt,.1c">
            </div>
            <p style="font-size:12px;color:var(--text-muted);margin-top:8px">
                Альфа-банк: «Выписка» → «Экспорт» → формат 1С. Кодировка Windows-1251.
            </p>
            <div id="cal-result"></div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" onclick="closeModal()">Закрыть</button>
            <button class="btn btn-primary" id="cal-btn" onclick="_doCalibrate()">Проверить</button>
        </div>
    `);
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

async function _doCalibrate() {
    const accountId = document.getElementById('cal-account').value;
    const file      = document.getElementById('cal-file').files[0];
    if (!file)      { showToast('Выберите файл', 'error'); return; }
    if (!accountId) { showToast('Выберите счёт', 'error'); return; }

    const btn = document.getElementById('cal-btn');
    if (btn) { btn.disabled = true; btn.textContent = 'Загрузка…'; }

    const formData = new FormData();
    formData.append('statement', file);
    formData.append('account_id', accountId);

    try {
        const resp = await fetch('api/bank.php', { method: 'POST', body: formData });
        const data = await resp.json();
        if (data.error) throw new Error(data.error);

        const el = document.getElementById('cal-result');
        if (el) el.innerHTML = _renderCalibResult(data);
        if (typeof lucide !== 'undefined') lucide.createIcons();
    } catch (e) {
        showToast('Ошибка: ' + e.message, 'error');
    } finally {
        if (btn) { btn.disabled = false; btn.textContent = 'Проверить'; }
    }
}

function _renderCalibResult(d) {
    const fmt     = v => (+v).toLocaleString('ru-RU', {minimumFractionDigits:2, maximumFractionDigits:2});
    const fmtDate = s => { if (!s) return '—'; const p = s.split('-'); return p.length === 3 ? `${p[2]}.${p[1]}.${p[0]}` : s; };

    const diff  = +d.difference;
    const isOk  = Math.abs(diff) < 0.02;
    const isOver = diff > 0;

    const dateRange = d.date_range?.start && d.date_range?.end
        ? `${fmtDate(d.date_range.start)} — ${fmtDate(d.date_range.end)}`
        : '';

    let statusHtml;
    if (isOk) {
        statusHtml = `<div style="padding:10px 14px;background:rgba(76,175,80,0.08);border-radius:8px;font-size:13px;color:var(--success)">
            <i data-lucide="check-circle" style="width:15px;height:15px;vertical-align:-3px;margin-right:6px"></i>
            Баланс совпадает с выпиской
        </div>`;
    } else {
        const diffAbs = fmt(Math.abs(diff));
        const msg = isOver
            ? `В ЦРМ на ${diffAbs} ₽ больше, чем в выписке`
            : `В ЦРМ на ${diffAbs} ₽ меньше, чем в выписке`;
        statusHtml = `<div style="padding:10px 14px;background:rgba(244,67,54,0.08);border-radius:8px;font-size:13px;color:var(--danger)">
            <i data-lucide="triangle-alert" style="width:15px;height:15px;vertical-align:-3px;margin-right:6px"></i>
            ${msg}
        </div>`;
    }

    const ops = d.period_operations || [];
    let opsHtml = '';
    if (!isOk && ops.length) {
        opsHtml = `
        <div style="margin-top:16px">
            <div style="font-size:12px;font-weight:600;color:var(--text-secondary);margin-bottom:8px">
                Операции за период выписки${dateRange ? ' (' + dateRange + ')' : ''}
            </div>
            <div style="max-height:260px;overflow-y:auto">
            ${ops.map(op => {
                const isIn  = ['Продажа','Прочий приход'].includes(op.type) || (op.type === 'Перевод' && +op.amount >= 0);
                const amt   = Math.abs(+op.amount);
                const isPending = op.status === 'pending';
                return `<div style="display:flex;align-items:center;gap:10px;padding:6px 10px;border-radius:6px;background:var(--bg-subtle);margin-bottom:3px;font-size:12px">
                    <span style="color:${isIn ? 'var(--success)' : 'var(--danger)'};font-weight:600;flex-shrink:0">${isIn ? '+' : '−'}${fmt(amt)} ₽</span>
                    <span style="color:var(--text-muted);flex-shrink:0">${fmtDate(op.operation_date)}</span>
                    <span style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${_escHtml(op.description || '—')}</span>
                    ${isPending ? `<span style="font-size:10px;color:var(--warning);flex-shrink:0">ожидает</span>` : ''}
                </div>`;
            }).join('')}
            </div>
        </div>`;
    } else if (!isOk) {
        opsHtml = `<div style="margin-top:12px;font-size:12px;color:var(--text-muted)">
            Нет операций за период выписки для сравнения
        </div>`;
    }

    const info = d.statement_info || {};
    const infoHtml = (info.total_in || info.total_out) ? `
        <div style="display:flex;gap:16px;font-size:12px;color:var(--text-muted);margin-top:8px">
            <span>Поступило: <b style="color:var(--success)">${fmt(info.total_in || 0)} ₽</b></span>
            <span>Списано: <b style="color:var(--danger)">${fmt(info.total_out || 0)} ₽</b></span>
            ${info.opening != null ? `<span>Нач. остаток: <b>${fmt(info.opening)} ₽</b></span>` : ''}
        </div>` : '';

    return `
    <div style="margin-top:16px;border-top:1px solid var(--border);padding-top:16px">
        <div style="font-size:12px;color:var(--text-muted);margin-bottom:10px">
            ${_escHtml(d.account_name || '')}${dateRange ? ' · ' + dateRange : ''}
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px">
            <div style="padding:10px 14px;background:var(--bg-subtle);border-radius:8px">
                <div style="font-size:11px;color:var(--text-muted);margin-bottom:4px">Остаток по выписке</div>
                <div style="font-size:18px;font-weight:700">${fmt(d.statement_balance)} ₽</div>
            </div>
            <div style="padding:10px 14px;background:var(--bg-subtle);border-radius:8px">
                <div style="font-size:11px;color:var(--text-muted);margin-bottom:4px">Остаток в ЦРМ</div>
                <div style="font-size:18px;font-weight:700;color:${isOk ? 'inherit' : (isOver ? 'var(--warning)' : 'var(--danger)')}">${fmt(d.crm_balance)} ₽</div>
            </div>
        </div>
        ${statusHtml}
        ${infoHtml}
        ${opsHtml}
    </div>`;
}

function _escHtml(str) {
    return String(str ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
