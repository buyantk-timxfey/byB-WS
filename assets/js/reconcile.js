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

    const flows = d.flows || {};
    let flowsHtml = '';
    if (flows.stmt_in || flows.stmt_out) {
        const fmtDiff = (v) => {
            if (Math.abs(v) < 0.02) return `<span style="color:var(--success)">совпадает</span>`;
            const sign = v > 0 ? '+' : '−';
            return `<span style="color:var(--danger)">${sign}${fmt(Math.abs(v))} ₽</span>`;
        };
        flowsHtml = `
        <div style="margin-top:16px;border-top:1px solid var(--border);padding-top:14px">
            <div style="font-size:12px;font-weight:600;color:var(--text-muted);margin-bottom:10px">Обороты за период выписки</div>
            <table style="width:100%;border-collapse:collapse;font-size:12px">
                <thead>
                    <tr>
                        <th style="text-align:left;padding:4px 8px;color:var(--text-muted);font-weight:500"></th>
                        <th style="text-align:right;padding:4px 8px;color:var(--text-muted);font-weight:500">По банку</th>
                        <th style="text-align:right;padding:4px 8px;color:var(--text-muted);font-weight:500">По ЦРМ</th>
                        <th style="text-align:right;padding:4px 8px;color:var(--text-muted);font-weight:500">Разница</th>
                    </tr>
                </thead>
                <tbody>
                    <tr style="border-top:1px solid var(--border)">
                        <td style="padding:6px 8px"><i data-lucide="arrow-down-circle" style="width:11px;height:11px;vertical-align:-1px;margin-right:4px;color:var(--success)"></i>Поступления</td>
                        <td style="text-align:right;padding:6px 8px;font-weight:500">${fmt(flows.stmt_in)} ₽</td>
                        <td style="text-align:right;padding:6px 8px;font-weight:500">${fmt(flows.crm_in)} ₽</td>
                        <td style="text-align:right;padding:6px 8px">${fmtDiff(flows.diff_in)}</td>
                    </tr>
                    <tr style="border-top:1px solid var(--border)">
                        <td style="padding:6px 8px"><i data-lucide="arrow-up-circle" style="width:11px;height:11px;vertical-align:-1px;margin-right:4px;color:var(--danger)"></i>Списания</td>
                        <td style="text-align:right;padding:6px 8px;font-weight:500">${fmt(flows.stmt_out)} ₽</td>
                        <td style="text-align:right;padding:6px 8px;font-weight:500">${fmt(flows.crm_out)} ₽</td>
                        <td style="text-align:right;padding:6px 8px">${fmtDiff(flows.diff_out)}</td>
                    </tr>
                </tbody>
            </table>
            ${!isOk ? `<div style="margin-top:10px;font-size:11px;color:var(--text-muted)">
                Если разница в поступлениях или списаниях ненулевая — проверьте операции за этот период в ЦРМ.
            </div>` : ''}
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
        ${flowsHtml}
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
