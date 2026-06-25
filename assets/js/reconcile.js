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

    const missing = d.missing_lines || [];
    let opsHtml = '';
    if (missing.length) {
        const totalMissing = missing.reduce((s, l) => s + Math.abs(+l.amount), 0);
        opsHtml = `
        <div style="margin-top:16px">
            <div style="font-size:12px;font-weight:600;color:var(--warning);margin-bottom:8px">
                <i data-lucide="triangle-alert" style="width:13px;height:13px;vertical-align:-2px;margin-right:4px"></i>
                Нет в ЦРМ: ${missing.length} ${_plural(missing.length, 'операция', 'операции', 'операций')} на ${fmt(totalMissing)} ₽
            </div>
            <div style="max-height:280px;overflow-y:auto">
            ${missing.map(l => {
                const isIn = l.direction === 'in';
                return `<div style="display:flex;align-items:flex-start;gap:10px;padding:7px 10px;border-radius:6px;background:var(--bg-subtle);margin-bottom:3px;font-size:12px">
                    <span style="color:${isIn ? 'var(--success)' : 'var(--danger)'};font-weight:600;flex-shrink:0">${isIn ? '+' : '−'}${fmt(Math.abs(+l.amount))} ₽</span>
                    <span style="color:var(--text-muted);flex-shrink:0">${fmtDate(l.operation_date)}</span>
                    <div style="flex:1;min-width:0">
                        ${l.counterparty ? `<div style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${_escHtml(l.counterparty)}</div>` : ''}
                        ${l.description ? `<div style="font-size:11px;color:var(--text-muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${_escHtml(l.description)}</div>` : ''}
                    </div>
                </div>`;
            }).join('')}
            </div>
            <div style="font-size:11px;color:var(--text-muted);margin-top:6px">
                Эти платежи есть в выписке банка, но не внесены в ЦРМ. Добавьте их через «+ Операция».
            </div>
        </div>`;
    } else if (!isOk) {
        opsHtml = `<div style="margin-top:12px;font-size:12px;color:var(--text-muted)">
            Все строки выписки нашлись в ЦРМ, но баланс всё равно расходится — проверьте начальный остаток счёта или операции вне периода выписки.
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

function _plural(n, one, few, many) {
    const m10 = n % 10, m100 = n % 100;
    if (m10 === 1 && m100 !== 11) return one;
    if (m10 >= 2 && m10 <= 4 && (m100 < 10 || m100 >= 20)) return few;
    return many;
}
