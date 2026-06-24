// ===== FINANCES.JS =====

// ══════════════════════════════════════════════════════
// P&L — ДЕТАЛИЗАЦИЯ (клик по карточке / строке месяца)
// ══════════════════════════════════════════════════════

async function openPnlDetail(type, year, month) {
    const params = new URLSearchParams({ type });
    if (year)  params.set('year',  year);
    if (month) params.set('month', month);

    // Табличные типы — широкая модалка; формулы (налог/маржа/ROI) — обычная
    const wideTypes = ['income', 'purchase', 'biz', 'warehouse', 'transit'];
    const opener = wideTypes.includes(type) ? openWideModal : openModal;

    // Показываем модалку-скелетон пока грузится
    opener(`
        <div class="modal-header">
            <span class="modal-title" id="pnl-detail-title">Загрузка…</span>
            <button class="btn-close" onclick="closeModal()"><i data-lucide="x" style="width:15px;height:15px"></i></button>
        </div>
        <div class="modal-body" id="pnl-detail-body" style="min-height:120px;display:flex;align-items:center;justify-content:center">
            <span style="color:var(--text-dim);font-size:13px">Загрузка данных…</span>
        </div>
        <div class="modal-footer"><button class="btn btn-ghost" onclick="closeModal()">Закрыть</button></div>
    `);

    const d = await api(`pnl_detail.php?${params}`).catch(() => null);
    if (!d || d.error) {
        document.getElementById('pnl-detail-body').innerHTML =
            `<span style="color:var(--danger)">Не удалось загрузить данные</span>`;
        return;
    }

    const fmt  = v => formatPrice(v);
    const fmtd = s => s ? formatDate(s) : '—';
    const periodLabel = (year && month)
        ? (['','Январь','Февраль','Март','Апрель','Май','Июнь','Июль','Август','Сентябрь','Октябрь','Ноябрь','Декабрь'][month] + ' ' + year)
        : (year ? year + ' год' : 'За всё время');

    const titles = {
        income:   'Выручка',
        purchase: 'Закупки',
        biz:      'Расходы ИП',
        tax:      'Налог',
        margin:   'Маржа',
        roi:      'ROI',
        warehouse:'На складе',
        transit:  'В пути',
    };

    document.getElementById('pnl-detail-title').textContent =
        titles[type] + (periodLabel !== 'За всё время' ? ' — ' + periodLabel : '');

    let html = '';

    if (type === 'income') {
        if (!d.rows.length) { html = _pnlEmpty(); }
        else {
            html = `
            <div class="pnl-detail-summary">
                <span>Итого оплаченных продаж: <strong>${d.cnt}</strong></span>
                <span class="pnl-detail-sum pos">${fmt(d.total)}</span>
            </div>
            <div class="table-wrapper">
            <table class="pnl-detail-table">
                <thead><tr><th>Дата</th><th>Покупатель</th><th>Товар</th><th>Кол-во</th><th class="tar">Сумма</th></tr></thead>
                <tbody>${d.rows.map(r => `
                <tr>
                    <td>${fmtd(r.date)}</td>
                    <td>${r.buyer}</td>
                    <td style="color:var(--text-muted)">${r.product}</td>
                    <td>${r.qty} шт</td>
                    <td class="tar" style="color:var(--success);font-weight:600">${fmt(r.amount)}</td>
                </tr>`).join('')}
                </tbody>
            </table></div>`;
        }
    }

    else if (type === 'purchase') {
        if (!d.rows.length) { html = _pnlEmpty(); }
        else {
            html = `
            <div class="pnl-detail-summary">
                <span>Поставок: <strong>${d.rows.length}</strong></span>
                <span class="pnl-detail-sum neg">${fmt(d.total)}</span>
            </div>
            <div class="table-wrapper">
            <table class="pnl-detail-table">
                <thead><tr><th>Дата</th><th>Поставщик</th><th>Поставка</th><th>Статус</th><th class="tar">Итого</th></tr></thead>
                <tbody>${d.rows.map(r => `
                <tr>
                    <td>${fmtd(r.date)}</td>
                    <td>${r.supplier}</td>
                    <td style="color:var(--text-muted)">${r.name}</td>
                    <td>${getStatusBadge(r.status)}</td>
                    <td class="tar" style="color:var(--danger);font-weight:600">${fmt(r.total)}</td>
                </tr>`).join('')}
                </tbody>
            </table></div>`;
        }
    }

    else if (type === 'biz') {
        if (!d.rows.length) { html = _pnlEmpty(); }
        else {
            const bycatHtml = Object.entries(d.bycat).map(([cat, sum]) => `
                <div class="pnl-bycat-row">
                    <span>${cat}</span>
                    <span style="color:var(--danger)">${fmt(sum)}</span>
                </div>`).join('');
            html = `
            <div class="pnl-detail-summary">
                <span>Записей: <strong>${d.rows.length}</strong></span>
                <span class="pnl-detail-sum neg">${fmt(d.total)}</span>
            </div>
            <div class="pnl-bycat-block">${bycatHtml}</div>
            <div class="table-wrapper" style="margin-top:12px">
            <table class="pnl-detail-table">
                <thead><tr><th>Дата</th><th>Название</th><th>Категория</th><th>Счёт</th><th class="tar">Сумма</th></tr></thead>
                <tbody>${d.rows.map(r => `
                <tr>
                    <td>${fmtd(r.date)}</td>
                    <td>${r.name}</td>
                    <td style="color:var(--text-muted)">${r.category}</td>
                    <td style="color:var(--text-muted)">${r.account}</td>
                    <td class="tar" style="color:var(--danger);font-weight:600">${fmt(r.amount)}</td>
                </tr>`).join('')}
                </tbody>
            </table></div>`;
        }
    }

    else if (type === 'tax') {
        const isNeg = d.gross <= 0;
        html = `
        <div class="pnl-formula-detail">
            <div class="pnl-fd-row">
                <span class="pnl-fd-label">Выручка</span>
                <span class="pnl-fd-val pos">${fmt(d.income)}</span>
            </div>
            <div class="pnl-fd-row">
                <span class="pnl-fd-label">− Закупки</span>
                <span class="pnl-fd-val neg">${fmt(d.purchase)}</span>
            </div>
            <div class="pnl-fd-row">
                <span class="pnl-fd-label">− Расходы ИП</span>
                <span class="pnl-fd-val neg">${fmt(d.biz)}</span>
            </div>
            <div class="pnl-fd-row pnl-fd-sep">
                <span class="pnl-fd-label">= Валовая прибыль</span>
                <span class="pnl-fd-val" style="color:${isNeg?'var(--danger)':'var(--text)'}">${fmt(d.gross)}</span>
            </div>
            <div class="pnl-fd-row pnl-fd-accent">
                <span class="pnl-fd-label">× ${d.rate}% (налог УСН)</span>
                <span class="pnl-fd-val" style="color:var(--danger)">${isNeg ? '0 ₽ (база отрицательная)' : fmt(d.tax)}</span>
            </div>
            <div class="pnl-fd-row pnl-fd-result">
                <span class="pnl-fd-label">Чистая прибыль</span>
                <span class="pnl-fd-val" style="color:${d.net>=0?'var(--success)':'var(--danger)'}">${fmt(d.net)}</span>
            </div>
        </div>`;
    }

    else if (type === 'margin') {
        html = `
        <div class="pnl-formula-detail">
            <div class="pnl-fd-row">
                <span class="pnl-fd-label">Выручка</span>
                <span class="pnl-fd-val pos">${fmt(d.income)}</span>
            </div>
            <div class="pnl-fd-row">
                <span class="pnl-fd-label">− Закупки</span>
                <span class="pnl-fd-val neg">${fmt(d.purchase)}</span>
            </div>
            <div class="pnl-fd-row">
                <span class="pnl-fd-label">− Расходы ИП</span>
                <span class="pnl-fd-val neg">${fmt(d.biz)}</span>
            </div>
            <div class="pnl-fd-row">
                <span class="pnl-fd-label">− Налог ${d.tax > 0 ? '16%' : '(база ≤ 0)'}</span>
                <span class="pnl-fd-val neg">${fmt(d.tax)}</span>
            </div>
            <div class="pnl-fd-row pnl-fd-sep">
                <span class="pnl-fd-label">= Чистая прибыль</span>
                <span class="pnl-fd-val" style="color:${d.net>=0?'var(--success)':'var(--danger)'}">${fmt(d.net)}</span>
            </div>
            <div class="pnl-fd-row pnl-fd-accent">
                <span class="pnl-fd-label">÷ Выручка</span>
                <span class="pnl-fd-val">${fmt(d.income)}</span>
            </div>
            <div class="pnl-fd-row pnl-fd-result">
                <span class="pnl-fd-label">= Маржа</span>
                <span class="pnl-fd-val" style="color:${d.margin>=0?'var(--success)':'var(--danger)'}">${d.margin}%</span>
            </div>
        </div>`;
    }

    else if (type === 'roi') {
        const gross_roi = d.income - d.purchase;
        html = `
        <div class="pnl-formula-detail">
            <div class="pnl-fd-row">
                <span class="pnl-fd-label">Выручка</span>
                <span class="pnl-fd-val pos">${fmt(d.income)}</span>
            </div>
            <div class="pnl-fd-row">
                <span class="pnl-fd-label">− Закупки</span>
                <span class="pnl-fd-val neg">${fmt(d.purchase)}</span>
            </div>
            <div class="pnl-fd-row pnl-fd-sep">
                <span class="pnl-fd-label">= Валовой доход</span>
                <span class="pnl-fd-val" style="color:${gross_roi>=0?'var(--success)':'var(--danger)'}">${fmt(gross_roi)}</span>
            </div>
            <div class="pnl-fd-row pnl-fd-accent">
                <span class="pnl-fd-label">÷ Закупки</span>
                <span class="pnl-fd-val neg">${fmt(d.purchase)}</span>
            </div>
            <div class="pnl-fd-row pnl-fd-result">
                <span class="pnl-fd-label">= ROI</span>
                <span class="pnl-fd-val" style="color:${d.roi>=0?'var(--success)':'var(--danger)'}">${d.roi}%</span>
            </div>
        </div>`;
    }

    else if (type === 'warehouse') {
        if (!d.rows.length) { html = _pnlEmpty('На складе нет остатков'); }
        else {
            html = `
            <div class="pnl-detail-summary">
                <span>Позиций: <strong>${d.rows.length}</strong></span>
                <span class="pnl-detail-sum" style="color:var(--accent)">${fmt(d.total)}</span>
            </div>
            <div class="table-wrapper">
            <table class="pnl-detail-table">
                <thead><tr><th>Товар</th><th>Поставщик</th><th>Остаток</th><th class="tar">Цена закупки</th><th class="tar">Заморожено</th></tr></thead>
                <tbody>${d.rows.map(r => `
                <tr>
                    <td style="font-weight:500">${r.name}</td>
                    <td style="color:var(--text-muted)">${r.supplier}</td>
                    <td>${r.qty_left} / ${r.qty_total} шт</td>
                    <td class="tar" style="color:var(--text-muted)">${fmt(r.purchase_price)}</td>
                    <td class="tar" style="color:var(--accent);font-weight:600">${fmt(r.frozen)}</td>
                </tr>`).join('')}
                </tbody>
            </table></div>`;
        }
    }

    else if (type === 'transit') {
        if (!d.rows.length) { html = _pnlEmpty('Нет активных поставок в пути'); }
        else {
            html = `
            <div class="pnl-detail-summary">
                <span>Поставок: <strong>${d.rows.length}</strong></span>
                <span class="pnl-detail-sum" style="color:var(--accent)">${fmt(d.total)}</span>
            </div>
            <div class="table-wrapper">
            <table class="pnl-detail-table">
                <thead><tr><th>Поставка</th><th>Поставщик</th><th>Заказ</th><th>ETA</th><th class="tar">Товары</th><th class="tar">Доставка</th><th class="tar">Итого</th></tr></thead>
                <tbody>${d.rows.map(r => `
                <tr>
                    <td style="font-weight:500">${r.name}</td>
                    <td style="color:var(--text-muted)">${r.supplier}</td>
                    <td>${fmtd(r.order_date)}</td>
                    <td>${fmtd(r.eta)}</td>
                    <td class="tar">${fmt(r.items_cost)}</td>
                    <td class="tar" style="color:var(--text-muted)">${r.carrier_cost > 0 ? fmt(r.carrier_cost) : '—'}</td>
                    <td class="tar" style="color:var(--accent);font-weight:600">${fmt(r.total)}</td>
                </tr>`).join('')}
                </tbody>
            </table></div>`;
        }
    }

    const bodyEl = document.getElementById('pnl-detail-body');
    bodyEl.style.cssText = '';   // сбрасываем flex-центрирование скелетона
    bodyEl.innerHTML = html;
    if (window.lucide) lucide.createIcons();
}

function _pnlEmpty(msg = 'Данных за этот период нет') {
    return `<div style="padding:32px;text-align:center;color:var(--text-dim);font-size:13px">${msg}</div>`;
}

// Сводка по конкретному месяцу — все показатели + детализация
async function openPnlMonthDetail(year, month) {
    const months = ['','Январь','Февраль','Март','Апрель','Май','Июнь','Июль','Август','Сентябрь','Октябрь','Ноябрь','Декабрь'];
    const label  = months[month] + ' ' + year;
    const fmt    = v => formatPrice(v);
    const fmtd   = s => s ? formatDate(s) : '—';

    openWideModal(`
        <div class="modal-header">
            <span class="modal-title">${label}</span>
            <button class="btn-close" onclick="closeModal()"><i data-lucide="x" style="width:15px;height:15px"></i></button>
        </div>
        <div class="modal-body" id="pnl-month-body" style="min-height:160px;display:flex;align-items:center;justify-content:center">
            <span style="color:var(--text-dim);font-size:13px">Загрузка…</span>
        </div>
        <div class="modal-footer"><button class="btn btn-ghost" onclick="closeModal()">Закрыть</button></div>
    `);

    const [income, purchase, biz] = await Promise.all([
        api(`pnl_detail.php?type=income&year=${year}&month=${month}`).catch(() => null),
        api(`pnl_detail.php?type=purchase&year=${year}&month=${month}`).catch(() => null),
        api(`pnl_detail.php?type=biz&year=${year}&month=${month}`).catch(() => null),
    ]);

    // Если хотя бы один блок не загрузился — показываем что именно
    const failed = [];
    if (!income || income.error)   failed.push('Выручка');
    if (!purchase || purchase.error) failed.push('Закупки');
    if (!biz || biz.error)         failed.push('Расходы ИП');
    if (failed.length) {
        document.getElementById('pnl-month-body').innerHTML =
            `<div style="padding:24px;text-align:center">
                <div style="color:var(--danger);font-size:13px;margin-bottom:8px">Не удалось загрузить: <strong>${failed.join(', ')}</strong></div>
                <div style="color:var(--text-dim);font-size:12px">Попробуйте обновить страницу</div>
             </div>`;
        return;
    }

    const gross  = income.total - purchase.total - biz.total;
    const tax    = gross > 0 ? Math.round(gross * 0.16) : 0;
    const net    = gross - tax;
    const margin = income.total > 0 ? (net / income.total * 100).toFixed(1) : 0;

    // Формула
    const formulaHtml = `
        <div class="pnl-month-formula">
            <div class="pnl-mf-item clickable" onclick="openPnlDetail('income',${year},${month})">
                <div class="pnl-mf-val pos">${fmt(income.total)}</div>
                <div class="pnl-mf-label">Выручка</div>
                <div class="pnl-mf-hint">${income.cnt} продаж</div>
            </div>
            <div class="pnl-mf-op">−</div>
            <div class="pnl-mf-item clickable" onclick="openPnlDetail('purchase',${year},${month})">
                <div class="pnl-mf-val neg">${fmt(purchase.total)}</div>
                <div class="pnl-mf-label">Закупки</div>
                <div class="pnl-mf-hint">${purchase.rows.length} поставок</div>
            </div>
            <div class="pnl-mf-op">−</div>
            <div class="pnl-mf-item clickable" onclick="openPnlDetail('biz',${year},${month})">
                <div class="pnl-mf-val neg">${fmt(biz.total)}</div>
                <div class="pnl-mf-label">Расходы ИП</div>
                <div class="pnl-mf-hint">${biz.rows.length} записей</div>
            </div>
            <div class="pnl-mf-op">−</div>
            <div class="pnl-mf-item clickable" onclick="openPnlDetail('tax',${year},${month})">
                <div class="pnl-mf-val" style="color:var(--danger)">${fmt(tax)}</div>
                <div class="pnl-mf-label">Налог 16%</div>
                <div class="pnl-mf-hint">${gross > 0 ? fmt(gross) + ' база' : 'база ≤ 0'}</div>
            </div>
            <div class="pnl-mf-op">=</div>
            <div class="pnl-mf-item pnl-mf-result">
                <div class="pnl-mf-val" style="color:${net>=0?'var(--success)':'var(--danger)'}">${fmt(net)}</div>
                <div class="pnl-mf-label">Чистая прибыль</div>
                <div class="pnl-mf-hint">Маржа ${margin}%</div>
            </div>
        </div>`;

    // Последние продажи
    const salesHtml = income.rows.length ? `
        <div class="pnl-month-section">
            <div class="pnl-month-section-title">
                Продажи
                <span class="pnl-month-section-link" onclick="openPnlDetail('income',${year},${month})">Все ${income.rows.length} →</span>
            </div>
            <div class="table-wrapper">
            <table class="pnl-detail-table">
                <thead><tr><th>Дата</th><th>Покупатель</th><th>Товар</th><th class="tar">Сумма</th></tr></thead>
                <tbody>${income.rows.slice(0,5).map(r=>`
                <tr>
                    <td>${fmtd(r.date)}</td>
                    <td>${r.buyer}</td>
                    <td style="color:var(--text-muted)">${r.product}</td>
                    <td class="tar" style="color:var(--success);font-weight:600">${fmt(r.amount)}</td>
                </tr>`).join('')}
                </tbody>
            </table></div>
        </div>` : '';

    // Последние поставки
    const purchHtml = purchase.rows.length ? `
        <div class="pnl-month-section">
            <div class="pnl-month-section-title">
                Закупки
                <span class="pnl-month-section-link" onclick="openPnlDetail('purchase',${year},${month})">Все ${purchase.rows.length} →</span>
            </div>
            <div class="table-wrapper">
            <table class="pnl-detail-table">
                <thead><tr><th>Дата</th><th>Поставщик</th><th>Поставка</th><th class="tar">Итого</th></tr></thead>
                <tbody>${purchase.rows.slice(0,5).map(r=>`
                <tr>
                    <td>${fmtd(r.date)}</td>
                    <td>${r.supplier}</td>
                    <td style="color:var(--text-muted)">${r.name}</td>
                    <td class="tar" style="color:var(--danger);font-weight:600">${fmt(r.total)}</td>
                </tr>`).join('')}
                </tbody>
            </table></div>
        </div>` : '';

    document.getElementById('pnl-month-body').style.cssText = '';
    document.getElementById('pnl-month-body').innerHTML = formulaHtml + salesHtml + purchHtml;
    if (window.lucide) lucide.createIcons();
}

// Карточка продажи из ленты
async function openSaleDetail(id) {
    const sale = await api(`sales.php?id=${id}`).catch(() => null);
    if (!sale || sale.error) return;

    const itemsHtml = (sale.items || []).map(i =>
        `<tr><td>${i.product_name}</td><td>${i.quantity} шт</td></tr>`
    ).join('') || `<tr><td colspan="2" style="color:var(--text-muted)">—</td></tr>`;

    openModal(`
        <div class="modal-header">
            <span class="modal-title">${sale.company_type === 'Розничный покупатель' || sale.counterparty === 'Розничный покупатель' ? 'Касса' : 'Продажа'} #${sale.id}</span>
            <button class="btn-close" onclick="closeModal()"><i data-lucide="x" style="width:15px;height:15px"></i></button>
        </div>
        <div class="modal-body">
            <div class="detail-grid">
                <div class="detail-item"><span class="detail-label">Дата</span><span class="detail-value">${formatDate(sale.sale_date)}</span></div>
                <div class="detail-item"><span class="detail-label">Покупатель</span><span class="detail-value">${sale.counterparty || sale.buyer_name || '—'}</span></div>
                <div class="detail-item"><span class="detail-label">Сумма</span><span class="detail-value" style="color:var(--success)">${formatPrice(sale.sale_price)}</span></div>
                <div class="detail-item"><span class="detail-label">Статус</span><span class="detail-value">${getStatusBadge(sale.status || 'Оплачено')}</span></div>
            </div>
            <div class="table-wrapper"><table>
                <thead><tr><th>Товар</th><th>Кол-во</th></tr></thead>
                <tbody>${itemsHtml}</tbody>
            </table></div>
        </div>
        <div class="modal-footer"><button class="btn btn-ghost" onclick="closeModal()">Закрыть</button></div>
    `);
}

// ===== РАСХОДЫ ПРЕДПРИНИМАТЕЛЯ =====

async function openAddBizExpense() {
    const [cats, accounts] = await Promise.all([
        api('business_expenses.php?categories=1'),
        api('bank.php?accounts=1')
    ]);

    const catOptions = cats.map(c => `<option value="${c.id}">${c.name}</option>`).join('');
    const accOptions = accounts.map(a => `<option value="${a.id}">${a.name}</option>`).join('');

    openDrawer(`
        <div class="drawer-header">
            <span class="drawer-title">Новый расход</span>
            <button class="btn-close" onclick="closeDrawer()"><i data-lucide="x" style="width:15px;height:15px"></i></button>
        </div>
        <div class="drawer-body">
            <div class="form-group">
                <label class="form-label">Название</label>
                <input type="text" class="form-control" id="biz-name" placeholder="Например: Figma, Adobe, Разработка логотипа">
            </div>
            <div class="form-group">
                <label class="form-label">Категория</label>
                <select class="form-control" id="biz-category">
                    <option value="">— выбрать —</option>
                    ${catOptions}
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Сумма (₽)</label>
                <input type="number" class="form-control" id="biz-amount" placeholder="0" min="0">
            </div>
            <div class="form-group">
                <label class="form-label">Дата</label>
                ${dpField('biz-date')}
            </div>
            <div class="form-group">
                <label class="form-label">Счёт списания</label>
                <select class="form-control" id="biz-account">
                    <option value="">— не указывать —</option>
                    ${accOptions}
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Комментарий</label>
                <input type="text" class="form-control" id="biz-comment" placeholder="Необязательно">
            </div>
        </div>
        <div class="drawer-footer">
            <button class="btn btn-ghost" onclick="closeDrawer()">Отмена</button>
            <button class="btn btn-primary" onclick="saveBizExpense()">Сохранить</button>
        </div>
    `);
}

async function saveBizExpense() {
    const name = document.getElementById('biz-name').value.trim();
    const amount = parseFloat(document.getElementById('biz-amount').value);
    const date = document.getElementById('biz-date').value;
    const catId = document.getElementById('biz-category').value;
    const accountId = document.getElementById('biz-account').value;
    const comment = document.getElementById('biz-comment').value.trim();

    if (!name) { showToast('Укажите название', 'error'); return; }
    if (!amount || amount <= 0) { showToast('Укажите сумму', 'error'); return; }
    if (!date) { showToast('Укажите дату', 'error'); return; }

    await api('business_expenses.php', 'POST', {
        name, amount, expense_date: date,
        category_id: catId || null,
        account_id: accountId || null,
        comment: comment || null
    });

    showToast('Расход добавлен');
    closeDrawer();
    setTimeout(reloadPage, 400);
}

async function openEditBizExpense(id) {
    const [expenses, cats, accounts] = await Promise.all([
        api('business_expenses.php'),
        api('business_expenses.php?categories=1'),
        api('bank.php?accounts=1')
    ]);

    const expense = expenses.find(e => e.id == id);
    if (!expense) return;

    const catOptions = cats.map(c =>
        `<option value="${c.id}" ${c.id == expense.category_id ? 'selected' : ''}>${c.name}</option>`
    ).join('');
    const accOptions = accounts.map(a =>
        `<option value="${a.id}" ${a.id == expense.account_id ? 'selected' : ''}>${a.name}</option>`
    ).join('');

    openDrawer(`
        <div class="drawer-header">
            <span class="drawer-title">Редактировать расход</span>
            <button class="btn-close" onclick="closeDrawer()"><i data-lucide="x" style="width:15px;height:15px"></i></button>
        </div>
        <div class="drawer-body">
            <div class="form-group">
                <label class="form-label">Название</label>
                <input type="text" class="form-control" id="edit-biz-name" value="${expense.name}">
            </div>
            <div class="form-group">
                <label class="form-label">Категория</label>
                <select class="form-control" id="edit-biz-category">
                    <option value="">— выбрать —</option>
                    ${catOptions}
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Сумма (₽)</label>
                <input type="number" class="form-control" id="edit-biz-amount" value="${expense.amount}" min="0">
            </div>
            <div class="form-group">
                <label class="form-label">Дата</label>
                ${dpField('edit-biz-date', expense.expense_date)}
            </div>
            <div class="form-group">
                <label class="form-label">Счёт списания</label>
                <select class="form-control" id="edit-biz-account">
                    <option value="">— не указывать —</option>
                    ${accOptions}
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Комментарий</label>
                <input type="text" class="form-control" id="edit-biz-comment" value="${expense.comment || ''}">
            </div>
        </div>
        <div class="drawer-footer">
            <button class="btn btn-ghost" onclick="closeDrawer()">Отмена</button>
            <button class="btn btn-primary" onclick="updateBizExpense(${id})">Сохранить</button>
        </div>
    `);
}

async function updateBizExpense(id) {
    const name = document.getElementById('edit-biz-name').value.trim();
    const amount = parseFloat(document.getElementById('edit-biz-amount').value);
    const date = document.getElementById('edit-biz-date').value;
    const catId = document.getElementById('edit-biz-category').value;
    const accountId = document.getElementById('edit-biz-account').value;
    const comment = document.getElementById('edit-biz-comment').value.trim();

    if (!name) { showToast('Укажите название', 'error'); return; }
    if (!amount || amount <= 0) { showToast('Укажите сумму', 'error'); return; }

    await api('business_expenses.php', 'PUT', {
        id, name, amount, expense_date: date,
        category_id: catId || null,
        account_id: accountId || null,
        comment: comment || null
    });

    showToast('Расход обновлён');
    closeDrawer();
    setTimeout(reloadPage, 400);
}

async function openBizExpenseDetail(id) {
    const expenses = await api('business_expenses.php');
    const e = expenses.find(ex => ex.id == id);
    if (!e) return;
    const reminderHtml = await renderReminderSection('expense', id);

    openModal(`
        <div class="modal-header">
            <span class="modal-title">${e.name}</span>
            <button class="btn-close" onclick="closeModal()"><i data-lucide="x" style="width:15px;height:15px"></i></button>
        </div>
        <div class="modal-body">
            <div class="detail-grid">
                <div class="detail-item"><span class="detail-label">Дата</span><span class="detail-value">${formatDate(e.expense_date)}</span></div>
                <div class="detail-item"><span class="detail-label">Категория</span><span class="detail-value">${e.category_name || '—'}</span></div>
                <div class="detail-item"><span class="detail-label">Сумма</span><span class="detail-value" style="color:var(--danger)">−${formatPrice(e.amount)}</span></div>
                <div class="detail-item"><span class="detail-label">Счёт</span><span class="detail-value">${e.account_name || '—'}</span></div>
                ${e.comment ? `<div class="detail-item" style="grid-column:1/-1"><span class="detail-label">Комментарий</span><span class="detail-value">${e.comment}</span></div>` : ''}
            </div>
            ${reminderHtml}
            <div id="entity-notes-expense-${id}"><div class="notes-loading">Загрузка заметок…</div></div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" onclick="closeModal()">Закрыть</button>
            <button class="btn btn-primary" onclick="closeModal(); openEditBizExpense(${id})">Изменить</button>
        </div>
    `);
    if (typeof loadEntityNotes === 'function') loadEntityNotes('expense', id);
}

function deleteBizExpense(id) {
    confirmAction('Удалить расход?', async () => {
        await api(`business_expenses.php?id=${id}`, 'DELETE');
        showToast('Расход удалён');
        setTimeout(reloadPage, 400);
    });
}

async function openManageExpenseCategories() {
    const cats = await api('business_expenses.php?categories=1');
    const listHtml = cats.map(c => `
        <div style="display:flex;align-items:center;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--border)">
            <span>${c.name}</span>
            <button class="btn-action danger" onclick="deleteExpenseCategory(${c.id})">Удалить</button>
        </div>
    `).join('');

    openModal(`
        <div class="modal-header">
            <span class="modal-title">Категории расходов</span>
            <button class="btn-close" onclick="closeModal()"><i data-lucide="x" style="width:15px;height:15px"></i></button>
        </div>
        <div class="modal-body">
            <div class="modal-section">
                ${listHtml || '<p style="color:var(--text-muted)">Нет категорий</p>'}
            </div>
            <div class="form-group">
                <label class="form-label">Новая категория</label>
                <div style="display:flex;gap:8px">
                    <input type="text" class="form-control" id="new-exp-cat" placeholder="Название">
                    <button class="btn btn-primary" onclick="addExpenseCategory()">+ Добавить</button>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" onclick="closeModal()">Закрыть</button>
        </div>
    `);
}

async function addExpenseCategory() {
    const name = document.getElementById('new-exp-cat').value.trim();
    if (!name) { showToast('Введите название', 'error'); return; }
    await api('business_expenses.php', 'POST', { action: 'add_category', name });
    showToast('Категория добавлена');
    closeModal();
    setTimeout(reloadPage, 400);
}

async function deleteExpenseCategory(id) {
    confirmAction('Удалить категорию?', async () => {
        await api(`business_expenses.php?category=${id}`, 'DELETE');
        showToast('Категория удалена');
        closeModal();
        setTimeout(reloadPage, 400);
    });
}

// ══════════════════════════════════════════════════════
// ЗАРПЛАТА
// ══════════════════════════════════════════════════════

async function loadSalaryTab() {
    const wrap = document.getElementById('salary-table-wrap');
    const stats = document.getElementById('salary-stats');
    if (!wrap) return;

    wrap.innerHTML = `<div style="padding:20px;color:var(--text-dim);font-size:13px">Загрузка…</div>`;

    const entries = await api('salary.php');
    if (!entries || entries.error) { wrap.innerHTML = ''; return; }

    // Зарплата 20% — нарастающим итогом: каждая запись показывает разницу со своим
    // предыдущим периодом (Июнь 200, Август 500 → 300). Порядок — по возрастанию id
    // (как добавляли). Первая запись = свой итог целиком.
    const _asc = [...entries].sort((a, b) => a.id - b.id);
    const _salaryDelta = {};
    let _prevSalary = 0;
    _asc.forEach((e, i) => {
        _salaryDelta[e.id] = i === 0 ? e.salary : (e.salary - _prevSalary);
        _prevSalary = e.salary;
    });
    const salaryOf = e => _salaryDelta[e.id] ?? e.salary;

    // Итоги
    // Зарплата итого — сумма всех зарплат (колонки с разницами).
    const totalSalary  = entries.reduce((s, e) => s + Math.max(0, salaryOf(e)), 0);
    // Доход/расход — значение из последней введённой записи (доходы вводятся
    // нарастающим итогом, поэтому последняя запись и есть текущая сумма).
    const _last        = _asc[_asc.length - 1] || null;
    const totalIncome  = _last ? parseFloat(_last.income)   : 0;
    const totalExp     = _last ? parseFloat(_last.expenses) : 0;

    stats.innerHTML = `
        <div class="stats-grid" style="margin-bottom:0">
            <div class="stat-card">
                <div class="stat-card-header">
                    <span class="stat-label">Зарплата итого</span>
                    <div class="stat-icon stat-icon-accent"><i data-lucide="wallet" style="width:15px;height:15px"></i></div>
                </div>
                <div class="stat-value accent no-count">${formatPrice(totalSalary)}</div>
                <div class="stat-sub">За все периоды</div>
            </div>
            <div class="stat-card">
                <div class="stat-card-header">
                    <span class="stat-label">Суммарный доход</span>
                    <div class="stat-icon stat-icon-success"><i data-lucide="trending-up" style="width:15px;height:15px"></i></div>
                </div>
                <div class="stat-value no-count" style="color:var(--success)">${formatPrice(totalIncome)}</div>
                <div class="stat-sub">Последний период</div>
            </div>
            <div class="stat-card">
                <div class="stat-card-header">
                    <span class="stat-label">Суммарные расходы</span>
                    <div class="stat-icon stat-icon-danger"><i data-lucide="trending-down" style="width:15px;height:15px"></i></div>
                </div>
                <div class="stat-value no-count" style="color:var(--danger)">${formatPrice(totalExp)}</div>
                <div class="stat-sub">Последний период</div>
            </div>
        </div>`;
    if (window.lucide) lucide.createIcons();
    // Финансовые суммы — без анимации-счётчика (класс no-count в .stat-value):
    // точное значение сразу, спарклайн остаётся.
    // Карточки только что созданы — инициализируем все анимации
    setTimeout(() => window._bybAnimations?.reinitCardAnimations(
        document.getElementById('salary-stats')
    ), 80);

    if (entries.length === 0) {
        wrap.innerHTML = `<div class="empty-state" style="padding:40px"><p>Записей пока нет</p><p style="font-size:12px;color:var(--text-dim);margin-top:8px">Добавьте первую запись</p></div>`;
        return;
    }

    const rows = entries.map(e => `
        <tr>
            <td style="font-weight:500">${e.period}</td>
            <td style="color:var(--success)">${formatPrice(e.income)}</td>
            <td style="color:var(--danger)">${formatPrice(e.expenses)}</td>
            <td>${formatPrice(e.base)}</td>
            <td style="color:var(--danger);font-size:12px">−${formatPrice(e.tax)}</td>
            <td>${formatPrice(e.net)}</td>
            <td style="color:var(--accent);font-weight:600">${formatPrice(Math.max(0, salaryOf(e)))}</td>
            <td class="row-actions">
                <button class="btn-icon" onclick="openEditSalaryEntry(${e.id})" title="Изменить">
                    <i data-lucide="pencil" style="width:13px;height:13px"></i>
                </button>
                <button class="btn-icon btn-icon-danger" onclick="deleteSalaryEntry(${e.id})" title="Удалить">
                    <i data-lucide="trash-2" style="width:13px;height:13px"></i>
                </button>
            </td>
        </tr>`).join('');

    wrap.innerHTML = `
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Период</th>
                        <th>Доходы</th>
                        <th>Расходы</th>
                        <th>База</th>
                        <th>Налог 16%</th>
                        <th>После налога</th>
                        <th>Зарплата 20%</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>${rows}</tbody>
            </table>
        </div>`;

    if (window.lucide) lucide.createIcons();
}

function _salaryDrawerHtml(title, saveFunc, entry = null) {
    const today = new Date();
    const months = ['Январь','Февраль','Март','Апрель','Май','Июнь','Июль','Август','Сентябрь','Октябрь','Ноябрь','Декабрь'];
    const defaultPeriod = months[today.getMonth()] + ' ' + today.getFullYear();

    return `
        <div class="drawer-header">
            <span class="drawer-title">${title}</span>
            <button class="btn-close" onclick="closeDrawer()"><i data-lucide="x" style="width:15px;height:15px"></i></button>
        </div>
        <div class="drawer-body">
            <div class="form-group">
                <label class="form-label">Период</label>
                <input type="text" class="form-control" id="sal-period" placeholder="Например: Май 2026">
            </div>
            <div class="form-group">
                <label class="form-label">Доходы (₽)</label>
                <input type="number" class="form-control" id="sal-income" placeholder="0" min="0" oninput="calcSalaryPreview()">
            </div>
            <div class="form-group">
                <label class="form-label">Расходы (₽)</label>
                <input type="number" class="form-control" id="sal-expenses" placeholder="0" min="0" oninput="calcSalaryPreview()">
            </div>
            <div id="sal-preview" style="background:var(--bg);border:1px solid var(--border);border-radius:var(--radius);padding:14px;margin-top:8px;display:none">
                <div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.8px;color:var(--text-muted);margin-bottom:10px">Расчёт</div>
                <div style="display:flex;flex-direction:column;gap:6px;font-size:13px">
                    <div style="display:flex;justify-content:space-between">
                        <span style="color:var(--text-muted)">База (Доход − Расход)</span>
                        <span id="sal-p-base">0 ₽</span>
                    </div>
                    <div style="display:flex;justify-content:space-between">
                        <span style="color:var(--text-muted)">Налог 16%</span>
                        <span id="sal-p-tax" style="color:var(--danger)">0 ₽</span>
                    </div>
                    <div style="display:flex;justify-content:space-between;border-top:1px solid var(--border);padding-top:6px">
                        <span style="color:var(--text-muted)">После налога</span>
                        <span id="sal-p-net">0 ₽</span>
                    </div>
                    <div style="display:flex;justify-content:space-between;font-weight:600">
                        <span style="color:var(--accent)">Зарплата 20%</span>
                        <span id="sal-p-salary" style="color:var(--accent)">0 ₽</span>
                    </div>
                </div>
            </div>
        </div>
        <div class="drawer-footer">
            <button class="btn btn-ghost" onclick="closeDrawer()">Отмена</button>
            <button class="btn btn-primary" onclick="${saveFunc}">Сохранить</button>
        </div>
    `;
}

function calcSalaryPreview() {
    const income   = parseFloat(document.getElementById('sal-income')?.value) || 0;
    const expenses = parseFloat(document.getElementById('sal-expenses')?.value) || 0;
    const preview  = document.getElementById('sal-preview');
    if (!preview) return;

    const base   = income - expenses;
    const tax    = Math.round(base * 0.16);
    const net    = Math.round(base * 0.84);
    const salary = Math.round(net * 0.20);
    const fmt    = v => new Intl.NumberFormat('ru-RU').format(Math.max(0, v)) + ' ₽';

    preview.style.display = (income > 0 || expenses > 0) ? '' : 'none';
    document.getElementById('sal-p-base').textContent   = fmt(base);
    document.getElementById('sal-p-tax').textContent    = '−' + fmt(tax);
    document.getElementById('sal-p-net').textContent    = fmt(net);
    document.getElementById('sal-p-salary').textContent = fmt(salary);
}

function openAddSalaryEntry() {
    const drawer = document.getElementById('drawer');
    drawer.innerHTML = _salaryDrawerHtml('Новая запись', 'saveSalaryEntry()');

    const months = ['Январь','Февраль','Март','Апрель','Май','Июнь','Июль','Август','Сентябрь','Октябрь','Ноябрь','Декабрь'];
    const today  = new Date();
    drawer.querySelector('#sal-period').value = months[today.getMonth()] + ' ' + today.getFullYear();

    drawer.classList.add('active');
    document.getElementById('overlay').classList.add('active');
}

async function openEditSalaryEntry(id) {
    const entries = await api('salary.php');
    const e = entries.find(x => x.id == id);
    if (!e) return;

    const drawer = document.getElementById('drawer');
    drawer.innerHTML = _salaryDrawerHtml('Редактировать запись', `updateSalaryEntry(${id})`);

    drawer.querySelector('#sal-period').value   = e.period;
    drawer.querySelector('#sal-income').value   = e.income;
    drawer.querySelector('#sal-expenses').value = e.expenses;
    calcSalaryPreview();

    drawer.classList.add('active');
    document.getElementById('overlay').classList.add('active');
}

async function saveSalaryEntry() {
    const period   = document.getElementById('sal-period').value.trim();
    const income   = parseFloat(document.getElementById('sal-income').value) || 0;
    const expenses = parseFloat(document.getElementById('sal-expenses').value) || 0;
    if (!period) { showToast('Укажите период', 'error'); return; }
    await api('salary.php', 'POST', { period, income, expenses });
    showToast('Запись добавлена');
    closeDrawer();
    loadSalaryTab();
}

async function updateSalaryEntry(id) {
    const period   = document.getElementById('sal-period').value.trim();
    const income   = parseFloat(document.getElementById('sal-income').value) || 0;
    const expenses = parseFloat(document.getElementById('sal-expenses').value) || 0;
    if (!period) { showToast('Укажите период', 'error'); return; }
    await api('salary.php', 'PUT', { id, period, income, expenses });
    showToast('Запись обновлена');
    closeDrawer();
    loadSalaryTab();
}

function deleteSalaryEntry(id) {
    confirmAction('Удалить запись?', async () => {
        await api(`salary.php?id=${id}`, 'DELETE');
        showToast('Запись удалена');
        loadSalaryTab();
    });
}
// ══════════ Калькулятор прибыли (вкладка «Калькулятор») ══════════

// Ввод плановой цены: мгновенный пересчёт + отложенное сохранение в БД
function calcPlanInput(inp) {
    recalcCalcTab();
    clearTimeout(inp._calcTimer);
    inp._calcTimer = setTimeout(() => {
        api('shipments.php', 'PUT', {
            set_planned: 1,
            item_id: parseInt(inp.dataset.itemId),
            planned_sale_price: inp.value === '' ? null : parseFloat(inp.value)
        });
    }, 600);
}

function recalcCalcTab() {
    let tCost = 0, tRev = 0;
    document.querySelectorAll('.calc-shipment').forEach(card => {
        let cost = parseFloat(card.dataset.carrier) || 0, rev = 0;
        card.querySelectorAll('.calc-plan').forEach(inp => {
            const qty = parseFloat(inp.dataset.qty) || 0;
            const pp  = parseFloat(inp.dataset.pp) || 0;
            const plan = parseFloat(inp.value);
            cost += qty * pp;
            const row = inp.closest('tr');
            if (!isNaN(plan)) {
                const r = qty * plan, profit = r - qty * pp;
                rev += r;
                row.querySelector('.calc-row-rev').textContent = formatPrice(r);
                const pEl = row.querySelector('.calc-row-profit');
                pEl.textContent = formatPrice(profit);
                pEl.style.color = profit >= 0 ? 'var(--success)' : 'var(--danger)';
                row.querySelector('.calc-row-margin').textContent = r > 0 ? (profit / r * 100).toFixed(1) + '%' : '—';
            } else {
                row.querySelector('.calc-row-rev').textContent = '—';
                const pEl = row.querySelector('.calc-row-profit');
                pEl.textContent = '—'; pEl.style.color = '';
                row.querySelector('.calc-row-margin').textContent = '—';
            }
        });
        const profit = rev - cost;
        card.querySelector('.calc-ship-cost').textContent = formatPrice(cost);
        card.querySelector('.calc-ship-rev').textContent = rev > 0 ? formatPrice(rev) : '—';
        const pe = card.querySelector('.calc-ship-profit');
        pe.textContent = rev > 0 ? formatPrice(profit) : '—';
        pe.style.color = rev > 0 ? (profit >= 0 ? 'var(--success)' : 'var(--danger)') : '';
        card.querySelector('.calc-ship-margin').textContent = rev > 0 ? (profit / rev * 100).toFixed(1) + '%' : '—';
        tCost += cost; tRev += rev;
    });
    const tProfit = tRev - tCost;
    const set = (id, val, color) => {
        const el = document.getElementById(id);
        if (el) { el.textContent = val; if (color !== undefined) el.style.color = color; }
    };
    set('calc-total-cost', formatPrice(tCost));
    set('calc-total-rev', tRev > 0 ? formatPrice(tRev) : '—');
    set('calc-total-profit', tRev > 0 ? formatPrice(tProfit) : '—', tRev > 0 ? (tProfit >= 0 ? 'var(--success)' : 'var(--danger)') : '');
    set('calc-total-margin', tRev > 0 ? (tProfit / tRev * 100).toFixed(1) + '%' : '—');
}

function initCalcTab() {
    if (document.getElementById('fin-calc')) recalcCalcTab();
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initCalcTab);
else initCalcTab();
