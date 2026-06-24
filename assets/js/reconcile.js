// ═══════════════════════════════════════════════════════════════════════════
// RECONCILE.JS — сопоставление банковских платежей
// ═══════════════════════════════════════════════════════════════════════════

let _recon = null;

async function openReconciliation(silent = false) {
    if (!silent) showToast('Загрузка…', 'info');
    _recon = await api('bank.php?reconcile=1');
    if (!_recon) return;
    _renderReconModal();
}

function _renderReconModal() {
    const { pending, lines, matched, batches, ignored } = _recon;
    const pCnt = pending.length;
    const lCnt = lines.length;
    const mCnt = matched.length;
    const bCnt = (batches || []).length;
    const iCnt = (ignored || []).length;

    // Считаем строки с мелкими расхождениями (< 1 ₽, одна привязанная операция)
    const kopeckLines = lines.filter(l => {
        const rem = +l.amount - (+l.matched_sum || 0);
        return l.matches?.length === 1 && Math.abs(rem) > 0 && Math.abs(rem) < 1;
    });

    openWideModal(`
        <div class="modal-header" style="border-bottom:1px solid var(--border);padding-bottom:12px">
            <span class="modal-title">Сопоставление платежей</span>
            <div style="display:flex;gap:8px;align-items:center">
                ${kopeckLines.length ? `
                <button id="accept-kopecks-btn" class="btn btn-ghost btn-sm" style="color:var(--warning)"
                    onclick="_acceptAllKopecks()">
                    Закрыть расхождения · ${kopeckLines.length}
                </button>` : ''}
                <button class="btn btn-ghost btn-sm" onclick="_openUploadStatement()">
                    <i data-lucide="upload" style="width:13px;height:13px;margin-right:4px"></i>Загрузить выписку
                </button>
                <button class="btn-close" onclick="closeModal()"><i data-lucide="x" style="width:15px;height:15px"></i></button>
            </div>
        </div>
        <div class="modal-body" style="padding:0;display:flex;flex-direction:column;flex:1;min-height:0">
            <div style="display:flex;border-bottom:1px solid var(--border);padding:0 20px;flex-shrink:0">
                <button class="recon-tab active" id="recon-tab-btn-lines"   onclick="_reconTab('lines')">
                    Выписка <span class="recon-tab-badge" id="recon-cnt-lines">${lCnt}</span>
                </button>
                <button class="recon-tab" id="recon-tab-btn-pending" onclick="_reconTab('pending')">
                    Ожидающие <span class="recon-tab-badge" id="recon-cnt-pending">${pCnt}</span>
                </button>
                <button class="recon-tab" id="recon-tab-btn-matched" onclick="_reconTab('matched')">
                    Сопоставлено <span class="recon-tab-badge recon-tab-badge--ok" id="recon-cnt-matched">${mCnt}</span>
                </button>
                <button class="recon-tab" id="recon-tab-btn-totals" onclick="_reconTab('totals')">
                    Контроль сумм${bCnt ? ` <span class="recon-tab-badge" id="recon-cnt-totals">${bCnt}</span>` : ''}
                </button>
                <button class="recon-tab" id="recon-tab-btn-ignored" onclick="_reconTab('ignored')">
                    Игнор${iCnt ? ` <span class="recon-tab-badge" id="recon-cnt-ignored">${iCnt}</span>` : ''}
                </button>
            </div>
            <div id="recon-tab-lines"   style="flex:1;overflow-y:auto;padding:16px">${_renderLines(lines)}</div>
            <div id="recon-tab-pending" style="flex:1;overflow-y:auto;padding:16px;display:none">${_renderPending(pending)}</div>
            <div id="recon-tab-matched" style="flex:1;overflow-y:auto;padding:16px;display:none">${_renderMatched(matched)}</div>
            <div id="recon-tab-totals"  style="flex:1;overflow-y:auto;padding:16px;display:none">${_renderBatches(batches || [])}</div>
            <div id="recon-tab-ignored" style="flex:1;overflow-y:auto;padding:16px;display:none">${_renderIgnored(ignored)}</div>
        </div>
    `);
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

function _reconTab(name) {
    ['lines','pending','matched','totals','ignored'].forEach(t => {
        document.getElementById(`recon-tab-${t}`).style.display = t === name ? '' : 'none';
        document.getElementById(`recon-tab-btn-${t}`).classList.toggle('active', t === name);
    });
}

// ── Рендер строк выписки ─────────────────────────────────────────────────────
function _renderLines(lines) {
    if (!lines.length) return `<div style="text-align:center;padding:48px;color:var(--text-muted)">
        <i data-lucide="check-circle" style="width:36px;height:36px;display:block;margin:0 auto 12px;color:var(--success)"></i>
        Все строки сопоставлены
    </div>`;
    return lines.map(l => _renderOneLine(l)).join('');
}

function _renderOneLine(l) {
    const isIn        = l.direction === 'in';
    const lineAmount  = +l.amount;
    const matchedSum  = +l.matched_sum || 0;
    const remaining   = lineAmount - matchedSum;
    const isPartial   = matchedSum > 0 && Math.abs(remaining) > 0.01;
    const isDone      = Math.abs(remaining) <= 0.01 && matchedSum > 0;
    const suggests    = (_recon.suggestions || {})[l.id] || [];
    const matchesHtml = _renderLineMatches(l);

    // Прогресс-бар
    const pct = lineAmount > 0 ? Math.min(100, Math.round(matchedSum / lineAmount * 100)) : 0;
    // Кнопка принятия эталонной суммы: когда расхождение < 1 ₽ и одна операция
    const smallDiff = Math.abs(remaining) > 0 && Math.abs(remaining) < 1;
    const acceptBtn = smallDiff && l.matches?.length === 1 ? `
        <button class="btn btn-ghost btn-sm" style="font-size:11px;color:var(--warning);margin-top:4px"
            onclick="_acceptStatementAmount(${l.id}, ${l.matches[0].op_id})">
            Принять сумму из выписки (разница ${remaining > 0 ? '+' : ''}${remaining.toFixed(2)} ₽)
        </button>` : '';
    const progressHtml = matchedSum > 0 ? `
        <div style="margin-top:8px">
            <div style="display:flex;justify-content:space-between;font-size:11px;color:var(--text-muted);margin-bottom:4px">
                <span>Привязано: ${matchedSum.toLocaleString('ru-RU')} ₽</span>
                <span style="color:${isDone ? 'var(--success)' : isPartial ? 'var(--warning)' : 'var(--text-muted)'}">
                    ${isDone ? '<i data-lucide="check" style="width:11px;height:11px;vertical-align:-1px"></i> Готово' : 'Осталось: ' + remaining.toLocaleString('ru-RU') + ' ₽'}
                </span>
            </div>
            <div style="height:4px;background:var(--border);border-radius:2px;overflow:hidden">
                <div style="height:100%;width:${pct}%;background:${isDone ? 'var(--success)' : 'var(--accent)'};transition:width 0.3s"></div>
            </div>
            ${acceptBtn}
        </div>` : '';

    // Авто-предложения (только если ещё нет матчей)
    const sugHtml = !matchedSum && suggests.length ? `
        <div style="margin-top:8px">
            ${suggests.map(op => {
                const isExact = +op.exact_match === 1;
                return isExact ? `
                <div style="display:flex;align-items:center;gap:8px;padding:7px 10px;background:rgba(76,175,80,0.1);border:1px solid rgba(76,175,80,0.3);border-radius:7px;margin-bottom:4px">
                    <div style="flex:1;min-width:0">
                        <div style="font-size:11px;color:var(--success);font-weight:600;margin-bottom:2px"><i data-lucide="sparkles" style="width:11px;height:11px;vertical-align:-1px"></i> Точное совпадение</div>
                        <div style="font-size:12px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${escHtml(op.description||'—')}</div>
                        <div style="font-size:11px;color:var(--text-muted)">${(+op.amount).toLocaleString('ru-RU')} ₽ · ${_fmtDate(op.operation_date)}</div>
                    </div>
                    <button class="btn btn-primary btn-sm" style="flex-shrink:0" onclick="_doMatch(${l.id},${op.id})">Привязать</button>
                </div>` : `
                <div style="display:flex;align-items:center;gap:8px;padding:5px 8px;background:var(--bg-subtle);border-radius:6px;margin-bottom:3px">
                    <div style="flex:1;min-width:0">
                        <div style="font-size:11px;color:var(--text-muted);margin-bottom:1px">Возможное совпадение</div>
                        <div style="font-size:12px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${escHtml(op.description||'—')} · ${(+op.amount).toLocaleString('ru-RU')} ₽</div>
                    </div>
                    <button class="btn btn-ghost btn-sm" style="flex-shrink:0" onclick="_doMatch(${l.id},${op.id})">Добавить</button>
                </div>`;
            }).join('')}
        </div>` : '';

    return `<div class="recon-line" id="reconline-${l.id}">
        <div style="display:flex;align-items:flex-start;gap:12px">
            <div class="recon-dir ${isIn ? 'recon-dir--in' : 'recon-dir--out'}">${isIn ? '↑' : '↓'}</div>
            <div style="flex:1;min-width:0">
                <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;flex-wrap:wrap">
                    <div>
                        <span style="font-weight:600;font-size:14px;color:${isIn ? 'var(--success)' : 'var(--danger)'}">
                            ${isIn?'+':'−'}${lineAmount.toLocaleString('ru-RU')} ₽
                        </span>
                        <span style="font-size:12px;color:var(--text-muted);margin-left:8px">${_fmtDate(l.operation_date)}</span>
                    </div>
                    <div style="display:flex;gap:5px;flex-shrink:0">
                        <button class="btn btn-ghost btn-sm" onclick="_openPickOp(${l.id})">+ Операция</button>
                        <button class="btn btn-ghost btn-sm" onclick="_openCreateFromLine(${l.id})">Создать</button>
                        <button class="btn btn-ghost btn-sm" style="color:var(--text-muted)" onclick="_ignoreLine(${l.id})">Игнор</button>
                    </div>
                </div>
                ${l.counterparty ? `<div style="font-size:12px;color:var(--text-secondary);margin-top:2px">${escHtml(l.counterparty)}</div>` : ''}
                ${l.description  ? `<div style="font-size:11px;color:var(--text-muted);margin-top:1px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${escHtml(l.description)}</div>` : ''}
                ${matchesHtml}
                ${progressHtml}
                ${sugHtml}
            </div>
        </div>
    </div>`;
}

function _renderLineMatches(l) {
    if (!l.matches || !l.matches.length) return '';
    return `<div style="margin-top:8px;border-top:1px solid var(--border);padding-top:8px">
        ${l.matches.map(m => `
            <div style="display:flex;align-items:center;gap:8px;padding:4px 8px;background:var(--bg-subtle);border-radius:5px;margin-bottom:3px">
                <div style="flex:1;min-width:0">
                    <span style="font-size:12px">${escHtml(m.description||'—')}</span>
                    <span style="font-size:11px;color:var(--text-muted);margin-left:6px">${(+m.amount).toLocaleString('ru-RU')} ₽</span>
                    ${m.source_label ? `<span style="font-size:10px;color:var(--text-muted)"> · ${escHtml(m.source_label)}</span>` : ''}
                </div>
                <button style="background:none;border:none;cursor:pointer;color:var(--text-muted);font-size:14px;padding:0 4px"
                    title="Убрать" onclick="_doUnmatchOp(${l.id},${m.op_id})">×</button>
            </div>`).join('')}
    </div>`;
}

// ── Рендер ожидающих операций ─────────────────────────────────────────────────
function _renderPending(ops) {
    if (!ops.length) return `<div style="text-align:center;padding:48px;color:var(--text-muted)">
        <i data-lucide="check-circle" style="width:36px;height:36px;display:block;margin:0 auto 12px;color:var(--success)"></i>
        Нет ожидающих операций
    </div>`;
    return ops.map(op => {
        const isIn = ['Продажа','Прочий приход'].includes(op.type);
        return `<div class="recon-line" id="reconop-${op.id}">
            <div style="display:flex;align-items:flex-start;gap:12px">
                <div class="recon-dir ${isIn?'recon-dir--in':'recon-dir--out'}">${isIn?'↑':'↓'}</div>
                <div style="flex:1;min-width:0">
                    <div style="display:flex;justify-content:space-between;align-items:center;gap:8px">
                        <div>
                            <span style="font-weight:600;font-size:14px;color:${isIn?'var(--success)':'var(--danger)'}">
                                ${isIn?'+':'−'}${(+op.amount).toLocaleString('ru-RU')} ₽
                            </span>
                            <span style="font-size:12px;color:var(--text-muted);margin-left:8px">${_fmtDate(op.operation_date)}</span>
                        </div>
                        <button class="btn btn-primary btn-sm" onclick="_confirmOp(${op.id})"><i data-lucide="check" style="width:13px;height:13px;vertical-align:-2px"></i> Подтвердить</button>
                    </div>
                    <div style="font-size:12px;color:var(--text-secondary);margin-top:2px">${escHtml(op.description||'—')}</div>
                    ${op.source_label ? `<div style="font-size:11px;color:var(--text-muted)">${escHtml(op.source_label)}</div>` : ''}
                    <span class="badge badge-waiting" style="font-size:10px;margin-top:4px">Ожидает подтверждения</span>
                </div>
            </div>
        </div>`;
    }).join('');
}

// ── Рендер сопоставленных ─────────────────────────────────────────────────────
function _renderMatched(items) {
    items = items || [];
    const search = `
        <input type="text" class="form-control" id="recon-matched-search"
            placeholder="Поиск по сумме, контрагенту или операции..."
            style="margin-bottom:12px"
            oninput="_filterMatched(this.value)">`;
    return search + `<div id="recon-matched-list">${_renderMatchedCards(items)}</div>`;
}

function _filterMatched(q) {
    const query = (q || '').toLowerCase().trim();
    const all = _recon?.matched || [];
    const items = !query ? all : all.filter(l =>
        String(l.amount).includes(query) ||
        (l.counterparty || '').toLowerCase().includes(query) ||
        (l.description  || '').toLowerCase().includes(query) ||
        (l.matches || []).some(m => (m.description || '').toLowerCase().includes(query) || String(m.amount).includes(query))
    );
    const el = document.getElementById('recon-matched-list');
    if (el) el.innerHTML = _renderMatchedCards(items);
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

function _renderMatchedCards(items) {
    if (!items.length) return `<div style="text-align:center;padding:48px;color:var(--text-muted)">Нет сопоставленных</div>`;
    return items.map(l => {
        const isIn   = l.direction === 'in';
        const mCount = l.matches?.length || 0;
        return `<div class="recon-line" style="opacity:.85">
            <div style="display:flex;align-items:flex-start;gap:12px">
                <div class="recon-dir ${isIn?'recon-dir--in':'recon-dir--out'}">${isIn?'↑':'↓'}</div>
                <div style="flex:1;min-width:0">
                    <div style="display:flex;justify-content:space-between;align-items:center;gap:8px">
                        <div>
                            <span style="font-weight:600;font-size:14px;color:${isIn?'var(--success)':'var(--danger)'}">
                                ${isIn?'+':'−'}${(+l.amount).toLocaleString('ru-RU')} ₽
                            </span>
                            <span style="font-size:12px;color:var(--text-muted);margin-left:8px">${_fmtDate(l.operation_date)}</span>
                        </div>
                        <button class="btn btn-ghost btn-sm" style="font-size:11px;color:var(--text-muted)" onclick="_doUnmatch(${l.id})">Сбросить</button>
                    </div>
                    ${l.counterparty ? `<div style="font-size:12px;color:var(--text-secondary);margin-top:2px">${escHtml(l.counterparty)}</div>` : ''}
                    ${l.matches?.length ? `<div style="margin-top:6px;font-size:12px;color:var(--text-muted)">
                        ${l.matches.map(m => `${escHtml(m.description||'—')} <b>${(+m.amount).toLocaleString('ru-RU')} ₽</b>`).join(' + ')}
                    </div>` : ''}
                    <span class="badge badge-done" style="font-size:10px;margin-top:4px"><i data-lucide="check" style="width:10px;height:10px;vertical-align:-1px"></i> Сопоставлено${mCount > 1 ? ' ('+mCount+' операции)' : ''}</span>
                </div>
            </div>
        </div>`;
    }).join('');
}

// ── Рендер проигнорированных ──────────────────────────────────────────────────
function _renderIgnored(items) {
    items = items || [];
    if (!items.length) return `<div style="text-align:center;padding:48px;color:var(--text-muted)">
        <i data-lucide="eye-off" style="width:36px;height:36px;display:block;margin:0 auto 12px"></i>
        Нет проигнорированных строк
    </div>`;
    return `<div style="font-size:12px;color:var(--text-muted);margin-bottom:12px">
            Строки, отправленные в «Игнор». Нажмите «Вернуть», чтобы снова сопоставить.
        </div>` + items.map(l => {
        const isIn = l.direction === 'in';
        return `<div class="recon-line" style="opacity:.85">
            <div style="display:flex;align-items:flex-start;gap:12px">
                <div class="recon-dir ${isIn?'recon-dir--in':'recon-dir--out'}">${isIn?'↑':'↓'}</div>
                <div style="flex:1;min-width:0">
                    <div style="display:flex;justify-content:space-between;align-items:center;gap:8px">
                        <div>
                            <span style="font-weight:600;font-size:14px;color:${isIn?'var(--success)':'var(--danger)'}">
                                ${isIn?'+':'−'}${(+l.amount).toLocaleString('ru-RU')} ₽
                            </span>
                            <span style="font-size:12px;color:var(--text-muted);margin-left:8px">${_fmtDate(l.operation_date)}</span>
                        </div>
                        <button class="btn btn-ghost btn-sm" onclick="_unignoreLine(${l.id})">
                            <i data-lucide="undo-2" style="width:13px;height:13px;vertical-align:-2px;margin-right:4px"></i>Вернуть
                        </button>
                    </div>
                    ${l.counterparty ? `<div style="font-size:12px;color:var(--text-secondary);margin-top:2px">${escHtml(l.counterparty)}</div>` : ''}
                    ${l.description  ? `<div style="font-size:11px;color:var(--text-muted);margin-top:1px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${escHtml(l.description)}</div>` : ''}
                </div>
            </div>
        </div>`;
    }).join('');
}

// ── Действия ──────────────────────────────────────────────────────────────────

async function _unignoreLine(lineId) {
    await api('bank.php', 'POST', { action: 'unignore_line', line_id: lineId });
    showToast('Строка возвращена в «Выписка»', 'success');
    await _reconRefresh();
}

async function _doMatch(lineId, opId) {
    const res = await api('bank.php', 'POST', { action: 'match', line_id: lineId, op_id: opId });
    if (!res) return;

    if (res.line_status === 'matched') {
        showToast('Платёж закрыт', 'success');
    } else {
        showToast('Операция добавлена', 'success');
    }
    await _reconRefresh();
}

async function _doUnmatchOp(lineId, opId) {
    await api('bank.php', 'POST', { action: 'unmatch_op', line_id: lineId, op_id: opId });
    showToast('Операция отвязана');
    await _reconRefresh();
}

async function _doUnmatch(lineId) {
    await api('bank.php', 'POST', { action: 'unmatch', line_id: lineId });
    showToast('Сопоставление сброшено');
    await _reconRefresh();
}

async function _ignoreLine(lineId) {
    await api('bank.php', 'POST', { action: 'ignore_line', line_id: lineId });
    showToast('Строка проигнорирована');
    await _reconRefresh();
}

async function _acceptStatementAmount(lineId, opId) {
    const res = await api('bank.php', 'POST', { action: 'accept_statement_amount', line_id: lineId, op_id: opId });
    if (!res) return;
    showToast('Принято', 'success');
    reloadPage();
    await _reconRefresh();
}

async function _acceptAllKopecks() {
    const toFix = (_recon.lines || []).filter(l => {
        const rem = +l.amount - (+l.matched_sum || 0);
        return l.matches?.length === 1 && Math.abs(rem) > 0 && Math.abs(rem) < 1;
    });
    if (!toFix.length) { showToast('Нет расхождений для закрытия'); return; }
    const btn = document.getElementById('accept-kopecks-btn');
    if (btn) { btn.disabled = true; btn.textContent = '…'; }
    for (const l of toFix) {
        await api('bank.php', 'POST', { action: 'accept_statement_amount', line_id: l.id, op_id: l.matches[0].op_id });
    }
    showToast(`Закрыто расхождений: ${toFix.length}`, 'success');
    reloadPage();
    await _reconRefresh();
}

async function _confirmOp(opId) {
    await api('bank.php', 'POST', { action: 'confirm_op', op_id: opId });
    showToast('Операция подтверждена', 'success');
    await _reconRefresh();
}

async function _reconRefresh() {
    _recon = await api('bank.php?reconcile=1');
    if (!_recon) return;
    const { pending, lines, matched, ignored } = _recon;

    const upd = (id, html) => { const el = document.getElementById(id); if (el) el.innerHTML = html; };
    upd('recon-tab-lines',   _renderLines(lines));
    upd('recon-tab-pending', _renderPending(pending));
    upd('recon-tab-matched', _renderMatched(matched));
    upd('recon-tab-totals',  _renderBatches(_recon.batches || []));
    upd('recon-tab-ignored', _renderIgnored(ignored));

    const updTxt = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };
    updTxt('recon-cnt-lines',   lines.length);
    updTxt('recon-cnt-pending', pending.length);
    updTxt('recon-cnt-matched', matched.length);
    updTxt('recon-cnt-ignored', (ignored || []).length);

    const badge = document.getElementById('reconcile-badge');
    const total = lines.length + pending.length;
    if (badge) { badge.textContent = total; badge.style.display = total > 0 ? 'inline-block' : 'none'; }

    if (typeof lucide !== 'undefined') lucide.createIcons();
}

// ── Пикер операций (с поддержкой нескольких) ─────────────────────────────────
async function _openPickOp(lineId) {
    const line = _recon.lines.find(l => l.id === lineId);
    if (!line) return;

    // Вычисляем остаток который надо покрыть
    const remaining = Math.max(0, +line.amount - (+line.matched_sum || 0));
    // Ищем по рублям без копеек
    const searchAmount = Math.floor(remaining);
    // Уже привязанные — исключаем из поиска
    const excludeIds = (line.matches || []).map(m => m.op_id).join(',');
    const excludeParam = excludeIds ? `&exclude=${excludeIds}` : '';

    const accountParam    = line.account_id ? `&account_id=${line.account_id}` : '';
    const directionParam  = line.direction  ? `&direction=${line.direction}`   : '';
    const baseParams      = `${excludeParam}${accountParam}${directionParam}`;

    let ops = await api(`bank.php?search_ops=1&amount=${searchAmount}${baseParams}`);
    if (!ops) return;

    // Если по сумме ничего не нашли — сразу грузим все несопоставленные по этому счёту/направлению
    let showingAll = ops.length === 0;
    if (showingAll) {
        ops = await api(`bank.php?search_ops=1${baseParams}`) || [];
    }

    window._pickOpItems      = ops;
    window._pickOpLineId     = lineId;
    window._pickOpRemaining  = remaining;
    window._pickOpSearchAmount = searchAmount;
    window._pickOpBaseParams = baseParams;

    const listId = 'pick-op-list-' + lineId;

    openModal(`
        <div class="modal-header">
            <span class="modal-title">Добавить операцию к строке</span>
            <button class="btn-close" onclick="closeModal()"><i data-lucide="x" style="width:15px;height:15px"></i></button>
        </div>
        <div class="modal-body">
            <div style="padding:8px 10px;background:var(--accent-subtle);border-radius:6px;margin-bottom:12px;font-size:13px">
                ${line.direction === 'in' ? '+' : '−'}${(+line.amount).toLocaleString('ru-RU')} ₽
                · ${_fmtDate(line.operation_date)}
                ${line.counterparty ? '· ' + escHtml(line.counterparty) : ''}
                ${+line.matched_sum > 0 ? `<br><span style="color:var(--warning);font-size:11px">Уже привязано: ${(+line.matched_sum).toLocaleString('ru-RU')} ₽ · Осталось: ${remaining.toLocaleString('ru-RU')} ₽</span>` : ''}
            </div>
            <div style="display:flex;gap:8px;margin-bottom:10px;align-items:center">
                <input type="text" class="form-control" placeholder="Поиск по наименованию или сумме..."
                    style="flex:1"
                    oninput="_filterPickOps(this.value, '${listId}', ${lineId}, ${remaining}, '${excludeParam}')">
                <button class="btn btn-ghost btn-sm" id="pick-show-all-btn" style="white-space:nowrap;flex-shrink:0"
                    onclick="_pickLoadAll('${listId}', ${lineId}, '${excludeParam}')">
                    Все операции
                </button>
            </div>
            ${showingAll ? `<div style="font-size:11px;color:var(--text-muted);margin-bottom:8px">Точных совпадений нет — показаны все несопоставленные операции</div>` : ''}
            <div id="${listId}" style="max-height:360px;overflow-y:auto">
                ${_renderPickOpItems(ops, lineId)}
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" onclick="closeModal()">Закрыть</button>
        </div>
    `);
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

async function _pickLoadAll(listId, lineId, excludeParam) {
    const btn = document.getElementById('pick-show-all-btn');
    if (btn) { btn.disabled = true; btn.textContent = '…'; }
    const baseParams = window._pickOpBaseParams || excludeParam;
    const all = await api(`bank.php?search_ops=1${baseParams}`);
    if (!all) { if (btn) { btn.disabled = false; btn.textContent = 'Все операции'; } return; }
    window._pickOpItems = all;
    const el = document.getElementById(listId);
    if (el) el.innerHTML = _renderPickOpItems(all, lineId);
    if (btn) btn.style.display = 'none';
    // Показываем подсказку
    const hint = document.createElement('div');
    hint.style.cssText = 'font-size:11px;color:var(--text-muted);margin-bottom:8px';
    hint.textContent = `Показаны все несопоставленные операции (${all.length})`;
    el?.parentElement?.insertBefore(hint, el);
}

function _renderPickOpItems(ops, lineId) {
    const suggests = (_recon.suggestions || {})[lineId] || [];
    const sugIds   = new Set(suggests.map(s => s.id));
    if (!ops.length) return `<div style="text-align:center;padding:24px;color:var(--text-muted);font-size:13px">
        Ничего не найдено по запросу
    </div>`;
    return ops.map(op => {
        const isPending = op.status === 'pending';
        const isSug = sugIds.has(op.id);
        const bg = isSug ? 'rgba(201,169,110,0.08)' : 'var(--bg-subtle)';
        const badge = isPending
            ? `<span style="font-size:10px;color:var(--warning);margin-left:4px">● ожидает</span>`
            : `<span style="font-size:10px;color:var(--success);margin-left:4px">● в банке</span>`;
        // Для перевода направление по знаку суммы, отображаем по модулю
        const isIncome = op.type === 'Перевод' ? (+op.amount >= 0) : ['Продажа','Прочий приход'].includes(op.type);
        const amtColor = isIncome ? 'var(--success)' : 'var(--danger)';
        const dispAmount = Math.abs(+op.amount);
        const typeLabel = op.type || '—';
        return `<div style="display:flex;align-items:center;gap:8px;padding:8px 10px;border-radius:6px;background:${bg};margin-bottom:4px">
            <div style="flex:1;min-width:0">
                <div style="font-size:13px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${escHtml(op.description||'—')}${badge}</div>
                <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:2px;font-size:11px;color:var(--text-muted)">
                    <span style="color:${amtColor};font-weight:600">${isIncome?'+':'−'}${dispAmount.toLocaleString('ru-RU')} ₽</span>
                    <span>${_fmtDate(op.operation_date)}</span>
                    <span style="background:var(--border);border-radius:3px;padding:0 4px">${escHtml(typeLabel)}</span>
                    ${op.account_name ? `<span>${escHtml(op.account_name)}</span>` : ''}
                    ${op.source_label ? `<span style="color:var(--accent)">${escHtml(op.source_label)}</span>` : ''}
                </div>
            </div>
            <button class="btn btn-primary btn-sm" onclick="_addOpToLine(${lineId},${op.id},this)">+ Добавить</button>
        </div>`;
    }).join('');
}

async function _addOpToLine(lineId, opId, btn) {
    if (btn) { btn.disabled = true; btn.textContent = '…'; }
    const res = await api('bank.php', 'POST', { action: 'match', line_id: lineId, op_id: opId });
    if (!res) { if (btn) { btn.disabled = false; btn.textContent = '+ Добавить'; } return; }

    if (btn) btn.closest('div[style*="display:flex"]').style.opacity = '0.4';

    // Обновляем остаток в шапке
    _recon = await api('bank.php?reconcile=1');
    if (_recon) {
        const updatedLine = _recon.lines.find(l => l.id === lineId) || _recon.matched.find(l => l.id === lineId);
        if (updatedLine) {
            const rem = Math.max(0, +updatedLine.amount - (+updatedLine.matched_sum || 0));
            const info = document.querySelector('.modal-body [style*="accent-subtle"]');
            if (info) {
                const matchedSpan = info.querySelector('span');
                if (matchedSpan && rem > 0) {
                    matchedSpan.innerHTML = `Уже привязано: ${(+updatedLine.matched_sum).toLocaleString('ru-RU')} ₽ · Осталось: ${rem.toLocaleString('ru-RU')} ₽`;
                } else if (matchedSpan && rem <= 0.01) {
                    matchedSpan.innerHTML = `<span style="color:var(--success)"><i data-lucide="check-circle" style="width:12px;height:12px;vertical-align:-2px"></i> Полностью сопоставлено!</span>`;
                }
            }
        }
    }

    if (res.line_status === 'matched') {
        showToast('Платёж закрыт', 'success');
        setTimeout(() => closeModal(), 800);
    } else {
        showToast('Добавлено. Можно добавить ещё.');
    }
    // Обновляем фоновый список
    _updateReconTabs();
}

function _updateReconTabs() {
    if (!_recon) return;
    const { pending, lines, matched, ignored } = _recon;
    ['lines','pending','matched','totals','ignored'].forEach(t => {
        const el = document.getElementById(`recon-tab-${t}`);
        if (el && el.style.display !== 'none') {
            if (t === 'lines')   el.innerHTML = _renderLines(lines);
            if (t === 'pending') el.innerHTML = _renderPending(pending);
            if (t === 'matched') el.innerHTML = _renderMatched(matched);
            if (t === 'totals')  el.innerHTML = _renderBatches(_recon.batches || []);
            if (t === 'ignored') el.innerHTML = _renderIgnored(ignored);
        }
    });
    const updTxt = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };
    updTxt('recon-cnt-lines', lines.length);
    updTxt('recon-cnt-pending', pending.length);
    updTxt('recon-cnt-matched', matched.length);
    updTxt('recon-cnt-ignored', (ignored || []).length);
    const badge = document.getElementById('reconcile-badge');
    const total = lines.length + pending.length;
    if (badge) { badge.textContent = total; badge.style.display = total > 0 ? 'inline-block' : 'none'; }
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

let _filterPickTimer = null;
function _filterPickOps(query, listId, lineId, remaining, excludeParam) {
    const q   = query.toLowerCase().trim();
    const ops = (window._pickOpItems || []).filter(op =>
        !q ||
        (op.description || '').toLowerCase().includes(q) ||
        String(op.amount).includes(q)
    );
    const el = document.getElementById(listId);
    if (el) el.innerHTML = _renderPickOpItems(ops, lineId);

    clearTimeout(_filterPickTimer);
    if (q.length >= 2) {
        _filterPickTimer = setTimeout(async () => {
            const baseParams = window._pickOpBaseParams || excludeParam;
            const fresh = await api(`bank.php?search_ops=1&amount=${Math.floor(remaining)}&q=${encodeURIComponent(q)}${baseParams}`);
            if (fresh) {
                window._pickOpItems = fresh;
                const el2 = document.getElementById(listId);
                if (el2) el2.innerHTML = _renderPickOpItems(fresh, lineId);
            }
        }, 350);
    }
}

// ── Создать операцию из строки ────────────────────────────────────────────────
async function _openCreateFromLine(lineId) {
    const line = _recon.lines.find(l => l.id === lineId);
    if (!line) return;
    const accounts = await api('bank.php?accounts=1');
    const isIn     = line.direction === 'in';
    const remaining = Math.max(0, +line.amount - (+line.matched_sum || 0));

    const typeOptions = isIn
        ? `<option value="Прочий приход">Прочий приход</option><option value="Продажа">Продажа</option>`
        : `<option value="Расход">Расход</option><option value="Закупка">Закупка</option><option value="Выплата ЗП">Выплата ЗП</option>`;
    const accOptions = accounts.map(a =>
        `<option value="${a.id}" ${a.id == line.account_id ? 'selected' : ''}>${a.name}</option>`
    ).join('');
    const defDesc = [line.counterparty, line.description ? line.description.substring(0,80) : ''].filter(Boolean).join(' — ');

    openModal(`
        <div class="modal-header">
            <span class="modal-title">Создать операцию из выписки</span>
            <button class="btn-close" onclick="closeModal()"><i data-lucide="x" style="width:15px;height:15px"></i></button>
        </div>
        <div class="modal-body">
            <div style="padding:8px 10px;background:var(--accent-subtle);border-radius:6px;margin-bottom:16px;font-size:13px">
                <b>${isIn?'+':'−'}${remaining.toLocaleString('ru-RU')} ₽</b> · ${_fmtDate(line.operation_date)}
                ${+line.matched_sum > 0 ? `<span style="color:var(--text-muted);font-size:11px"> (остаток)</span>` : ''}
            </div>
            <div class="form-group">
                <label class="form-label">Тип</label>
                <select class="form-control" id="cfl-type">${typeOptions}</select>
            </div>
            <div class="form-group">
                <label class="form-label">Наименование</label>
                <input type="text" class="form-control" id="cfl-desc" value="${escHtml(defDesc)}">
            </div>
            <div class="form-group">
                <label class="form-label">Счёт</label>
                <select class="form-control" id="cfl-account">${accOptions}</select>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" onclick="closeModal()">Отмена</button>
            <button class="btn btn-primary" onclick="_saveCreateFromLine(${lineId})">Создать</button>
        </div>
    `);
}

async function _saveCreateFromLine(lineId) {
    const type      = document.getElementById('cfl-type').value;
    const desc      = document.getElementById('cfl-desc').value.trim();
    const accountId = document.getElementById('cfl-account').value;
    await api('bank.php', 'POST', { action: 'create_from_line', line_id: lineId, type, description: desc, account_id: accountId });
    showToast('Операция создана', 'success');
    closeModal();
    reloadPage(); // обновить карточки баланса за модалкой
    await _reconRefresh();
}

// ── Загрузка выписки ──────────────────────────────────────────────────────────
async function _openUploadStatement() {
    const accounts = await api('bank.php?accounts=1');
    const accOptions = accounts.map(a => `<option value="${a.id}">${a.name}</option>`).join('');
    openModal(`
        <div class="modal-header">
            <span class="modal-title">Загрузить выписку</span>
            <button class="btn-close" onclick="closeModal()"><i data-lucide="x" style="width:15px;height:15px"></i></button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label class="form-label">Банковский счёт</label>
                <select class="form-control" id="stmt-account">${accOptions}</select>
            </div>
            <div class="form-group">
                <label class="form-label">Файл (.txt, формат 1CClientBankExchange)</label>
                <input type="file" class="form-control" id="stmt-file" accept=".txt,.1c">
            </div>
            <p style="font-size:12px;color:var(--text-muted);margin-top:8px">
                Альфа-банк: «Выписка» → «Экспорт» → формат 1С. Кодировка Windows-1251.
            </p>
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" onclick="closeModal()">Отмена</button>
            <button class="btn btn-primary" id="stmt-upload-btn" onclick="_doUploadStatement()">Загрузить</button>
        </div>
    `);
}

async function _doUploadStatement() {
    const accountId = document.getElementById('stmt-account').value;
    const file      = document.getElementById('stmt-file').files[0];
    if (!file)      { showToast('Выберите файл', 'error'); return; }
    if (!accountId) { showToast('Выберите счёт', 'error'); return; }

    const btn = document.getElementById('stmt-upload-btn');
    if (btn) { btn.disabled = true; btn.textContent = 'Загрузка…'; }

    const formData = new FormData();
    formData.append('statement', file);
    formData.append('account_id', accountId);

    try {
        const resp = await fetch('api/bank.php', { method: 'POST', body: formData });
        const data = await resp.json();
        if (data.error) throw new Error(data.error);
        showToast(`Загружено ${data.imported} строк`, 'success');
        if (data.duplicates > 0) showToast(`Пропущено дублей: ${data.duplicates}`, 'warning');
        closeModal();
        // Ждём закрытия анимации (300мс), потом открываем сопоставление заново
        setTimeout(() => openReconciliation(true), 350);
    } catch (e) {
        showToast('Ошибка: ' + e.message, 'error');
        if (btn) { btn.disabled = false; btn.textContent = 'Загрузить'; }
    }
}

// ── Контрольные суммы (сравнение выписки с ЦРМ) ──────────────────────────────
function _renderBatches(batches) {
    if (!batches.length) return `<div style="text-align:center;padding:48px;color:var(--text-muted)">
        <i data-lucide="file-text" style="width:36px;height:36px;display:block;margin:0 auto 12px"></i>
        Нет загруженных выписок.<br>
        <span style="font-size:12px">Загрузите выписку из Альфа-банка — итоговые суммы появятся здесь для сверки.</span>
    </div>`;

    return batches.map(b => {
        // По выписке — сумма загруженных строк счёта (игнорированные не считаются)
        const stmtIn  = +b.total_in  || 0;
        const stmtOut = +b.total_out || 0;
        // В ЦРМ — все операции счёта (без обрезки по периоду выписки)
        const crmIn   = +b.crm_in  || 0;
        const crmOut  = +b.crm_out || 0;

        // Разница со знаком: плюс — операции ждут подтверждения выпиской, минус — в ЦРМ не хватает
        const diffIn   = crmIn  - stmtIn;
        const diffOut  = crmOut - stmtOut;
        const okIn     = Math.abs(diffIn)  < 0.02;
        const okOut    = Math.abs(diffOut) < 0.02;
        const allOk    = okIn && okOut;

        const openBal  = +b.opening_balance || 0;
        const closeBal = +b.closing_balance || 0;

        const dateRange = b.start_date && b.end_date
            ? `${_fmtDate(b.start_date)} — ${_fmtDate(b.end_date)}`
            : _fmtDate(b.start_date || b.end_date);

        const fmt = v => v.toLocaleString('ru-RU', {minimumFractionDigits:2, maximumFractionDigits:2});

        const statusCell = (isOk, diff) => `<td style="padding:7px 12px;text-align:center">
            ${isOk
                ? `<span style="color:var(--success)"><i data-lucide="check" style="width:13px;height:13px"></i></span>`
                : `<span style="color:${diff < 0 ? 'var(--danger)' : 'var(--warning)'};font-size:12px">${diff > 0 ? '+' : '−'}${fmt(Math.abs(diff))} ₽</span>`
            }
        </td>`;

        const row = (label, stmtVal, crmVal, isOk, diff) => `
            <tr style="border-bottom:1px solid var(--border)">
                <td style="padding:8px 12px;color:var(--text-muted);font-size:12px">${label}</td>
                <td style="padding:8px 12px;text-align:right;font-size:13px;font-weight:500">${fmt(stmtVal)} ₽</td>
                <td style="padding:8px 12px;text-align:right;font-size:13px">${fmt(crmVal)} ₽</td>
                ${statusCell(isOk, diff)}
            </tr>`;

        // Список неучтённых строк (у частичных — остаток, а не полная сумма)
        const unmatched = b.unmatched_lines || [];
        const lineAmt   = l => l.remaining != null ? +l.remaining : +l.amount;
        const unmatchedHtml = unmatched.length ? `
            <div style="margin-top:14px">
                <div style="font-size:12px;font-weight:600;color:var(--warning);margin-bottom:8px">
                    <i data-lucide="triangle-alert" style="width:13px;height:13px;vertical-align:-2px"></i> Не учтено в ЦРМ: ${unmatched.length} строк
                    (${fmt(unmatched.reduce((s,l) => s + lineAmt(l), 0))} ₽)
                </div>
                ${unmatched.map(l => {
                    const isIn = l.direction === 'in';
                    const isPartial = l.status === 'partial';
                    return `<div style="display:flex;align-items:center;gap:10px;padding:6px 10px;border-radius:6px;background:var(--bg-subtle);margin-bottom:3px;font-size:12px">
                        <span style="color:${isIn ? 'var(--success)' : 'var(--danger)'};font-weight:600;flex-shrink:0">
                            ${isIn ? '+' : '−'}${fmt(lineAmt(l))} ₽
                        </span>
                        <span style="color:var(--text-muted);flex-shrink:0">${_fmtDate(l.operation_date)}</span>
                        <span style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--text-secondary)">
                            ${escHtml(l.counterparty || l.description || '—')}
                        </span>
                        ${isPartial ? `<span style="font-size:10px;color:var(--warning);flex-shrink:0">частично, остаток</span>` : ''}
                        <button class="btn btn-ghost btn-sm" style="flex-shrink:0;font-size:11px" onclick="_openCreateFromLine(${l.id});_reconTab('lines')">Создать</button>
                    </div>`;
                }).join('')}
            </div>` : `
            <div style="margin-top:8px;padding:8px 12px;background:rgba(76,175,80,0.06);border-radius:6px;font-size:12px;color:var(--success)">
                <i data-lucide="check" style="width:13px;height:13px;vertical-align:-2px"></i> Все строки выписки учтены в ЦРМ
            </div>`;

        return `<div class="recon-line" style="margin-bottom:16px">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;flex-wrap:wrap;gap:6px">
                <div>
                    <span style="font-weight:600;font-size:14px">${escHtml(b.account_name || 'Счёт')}</span>
                    ${b.account_number ? `<span style="font-size:11px;color:var(--text-muted);margin-left:8px">${escHtml(b.account_number)}</span>` : ''}
                    ${dateRange ? `<span style="font-size:12px;color:var(--text-muted);margin-left:8px">${dateRange}</span>` : ''}
                </div>
            </div>
            ${openBal || closeBal ? `
            <div style="display:flex;gap:24px;padding:8px 12px;background:var(--bg-subtle);border-radius:6px;margin-bottom:10px;font-size:12px">
                <div><span style="color:var(--text-muted)">Нач. остаток:</span> <b>${fmt(openBal)} ₽</b></div>
                <div><span style="color:var(--text-muted)">Кон. остаток:</span> <b>${fmt(closeBal)} ₽</b></div>
            </div>` : ''}
            <table style="width:100%;border-collapse:collapse;border:1px solid var(--border);border-radius:8px;overflow:hidden">
                <thead>
                    <tr style="background:var(--bg-subtle)">
                        <th style="padding:7px 12px;text-align:left;font-size:11px;color:var(--text-muted);font-weight:500;width:120px"></th>
                        <th style="padding:7px 12px;text-align:right;font-size:11px;color:var(--text-muted);font-weight:500">По выписке</th>
                        <th style="padding:7px 12px;text-align:right;font-size:11px;color:var(--text-muted);font-weight:500">В ЦРМ (всего)</th>
                        <th style="padding:7px 12px;text-align:center;font-size:11px;color:var(--text-muted);font-weight:500;width:100px">Разница</th>
                    </tr>
                </thead>
                <tbody>
                    ${row('Поступило', stmtIn,  crmIn,  okIn,  diffIn)}
                    ${row('Списано',   stmtOut, crmOut, okOut, diffOut)}
                </tbody>
            </table>
            ${!allOk ? ((diffIn < -0.01 || diffOut < -0.01) ? `
            <div style="margin-top:8px;padding:8px 12px;background:rgba(244,67,54,0.08);border-radius:6px;font-size:12px;color:var(--danger)">
                <i data-lucide="triangle-alert" style="width:13px;height:13px;vertical-align:-2px"></i> Расхождение — в ЦРМ не хватает операций из выписки
            </div>` : `
            <div style="margin-top:8px;padding:8px 12px;background:rgba(250,204,21,0.08);border-radius:6px;font-size:12px;color:var(--warning)">
                Операции на ${fmt(Math.abs(diffIn) + Math.abs(diffOut))} ₽ ещё не подтверждены выпиской — загрузите свежую выписку и сопоставьте
                ${(b.unconfirmed_ops || []).length ? `
                <a href="#" style="color:var(--warning);text-decoration:underline;margin-left:6px"
                   onclick="event.preventDefault();const el=document.getElementById('unconf-${b.account_id}');el.style.display=el.style.display==='none'?'':'none'">
                   показать (${b.unconfirmed_ops.length})
                </a>` : ''}
            </div>
            ${(b.unconfirmed_ops || []).length ? `
            <div id="unconf-${b.account_id}" style="display:none;margin-top:6px">
                ${b.unconfirmed_ops.map(o => {
                    const isIn = ['Продажа','Прочий приход'].includes(o.type) || (o.type === 'Перевод' && +o.amount >= 0);
                    return `<div style="display:flex;align-items:center;gap:10px;padding:5px 10px;border-radius:6px;background:var(--bg-subtle);margin-bottom:3px;font-size:12px">
                        <span style="color:${isIn ? 'var(--success)' : 'var(--danger)'};font-weight:600;flex-shrink:0">
                            ${isIn ? '+' : '−'}${fmt(Math.abs(+o.amount))} ₽
                        </span>
                        <span style="color:var(--text-muted);flex-shrink:0">${_fmtDate(o.operation_date)}</span>
                        <span style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--text-secondary)">${escHtml(o.description || '—')}</span>
                        ${o.status === 'pending' ? `<span style="font-size:10px;color:var(--warning);flex-shrink:0">ожидает</span>` : ''}
                    </div>`;
                }).join('')}
            </div>` : ''}`) : `
            <div style="margin-top:8px;padding:8px 12px;background:rgba(76,175,80,0.06);border-radius:6px;font-size:12px;color:var(--success)">
                <i data-lucide="check" style="width:13px;height:13px;vertical-align:-2px"></i> Суммы совпадают с выпиской
            </div>`}
            ${unmatchedHtml}
        </div>`;
    }).join('');
}

// ── Утилиты ───────────────────────────────────────────────────────────────────
function _fmtDate(d) {
    if (!d) return '—';
    const p = d.split('-');
    return p.length === 3 ? `${p[2]}.${p[1]}.${p[0]}` : d;
}
function escHtml(str) {
    return String(str ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
