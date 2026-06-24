// ===== CHAIN.JS — Цепочка движения товара =====

async function openProductChain(warehouseId) {
    const data = await api(`warehouse.php?chain=${warehouseId}`);
    const { warehouse: w, sales, stats } = data;

    const statusMap = {
        'На складе':       '<span class="badge badge-instock">На складе</span>',
        'Частично продан': '<span class="badge badge-partial">Частично продан</span>',
        'Продан':          '<span class="badge badge-sold">Продан</span>',
    };
    const saleStatusMap = {
        'Оплачено':       '<span class="badge badge-done">Оплачено</span>',
        'Счёт выставлен': '<span class="badge badge-waiting">Счёт выставлен</span>',
        'Отменено':       '<span class="badge badge-alert">Отменено</span>',
    };

    // Блок поставки
    const shipmentBlock = `
        <div class="chain-block chain-block--shipment">
            <div class="chain-block-icon"><i data-lucide="package" style="width:18px;height:18px;color:var(--info)"></i></div>
            <div class="chain-block-content">
                <div class="chain-block-title">
                    Поставка #${w.shipment_id}
                    <span style="font-weight:400;color:var(--text-muted);font-size:12px;margin-left:8px">${formatDate(w.shipment_order_date)}</span>
                </div>
                <div class="chain-block-rows">
                    <div class="chain-row"><span class="chain-label">Поставщик</span><span class="chain-value">${w.supplier_type ? w.supplier_type + ' ' : ''}${w.supplier_name}</span></div>
                    ${w.carrier_name ? `<div class="chain-row"><span class="chain-label">ТК</span><span class="chain-value">${w.carrier_name}</span></div>` : ''}
                    ${w.tracking ? `<div class="chain-row"><span class="chain-label">Трек</span><span class="chain-value" style="color:var(--accent)">${w.tracking}</span></div>` : ''}
                    <div class="chain-row"><span class="chain-label">ETA</span><span class="chain-value">${w.shipment_eta ? formatDate(w.shipment_eta) : '—'}</span></div>
                    <div class="chain-row"><span class="chain-label">Статус</span><span class="chain-value">${getStatusBadge(w.shipment_status)}</span></div>
                </div>
            </div>
        </div>
    `;

    // Блок склада
    const warehouseBlock = `
        <div class="chain-arrow">↓</div>
        <div class="chain-block chain-block--warehouse">
            <div class="chain-block-icon"><i data-lucide="warehouse" style="width:18px;height:18px;color:var(--accent)"></i></div>
            <div class="chain-block-content">
                <div class="chain-block-title">
                    Склад — ${w.name}
                </div>
                <div class="chain-block-rows">
                    <div class="chain-row"><span class="chain-label">Закуплено</span><span class="chain-value">${w.quantity_total} шт × ${formatPrice(w.purchase_price)} = <strong style="color:var(--danger)">${formatPrice(w.purchase_price * w.quantity_total)}</strong></span></div>
                    <div class="chain-row"><span class="chain-label">Продано</span><span class="chain-value">${stats.total_sold} шт</span></div>
                    <div class="chain-row"><span class="chain-label">Остаток</span><span class="chain-value">${stats.total_left} шт</span></div>
                    <div class="chain-row"><span class="chain-label">Статус</span><span class="chain-value">${statusMap[w.status] || w.status}</span></div>
                    ${w.category_name ? `<div class="chain-row"><span class="chain-label">Категория</span><span class="chain-value">${w.category_name}</span></div>` : ''}
                </div>
            </div>
        </div>
    `;

    // Блок продаж
    const salesRows = sales.length > 0
        ? sales.map(s => `
            <div class="chain-sale-row">
                <span class="chain-sale-date">${formatDate(s.sale_date)}</span>
                <span class="chain-sale-buyer">${s.buyer_type ? s.buyer_type + ' ' : ''}${s.buyer_name}</span>
                <span class="chain-sale-qty">${s.quantity} шт</span>
                <span class="chain-sale-price" style="color:var(--success)">${formatPrice(s.sale_price)}</span>
                <span>${saleStatusMap[s.sale_status] || s.sale_status}</span>
            </div>
        `).join('')
        : `<div style="color:var(--text-muted);font-size:13px;padding:8px 0">Продаж пока нет</div>`;

    const salesBlock = `
        <div class="chain-arrow">↓</div>
        <div class="chain-block chain-block--sales">
            <div class="chain-block-icon"><i data-lucide="circle-dollar-sign" style="width:18px;height:18px;color:var(--success)"></i></div>
            <div class="chain-block-content">
                <div class="chain-block-title">Продажи</div>
                <div class="chain-sales-list">${salesRows}</div>
            </div>
        </div>
    `;

    // Итоговый блок
    const profitColor = stats.profit >= 0 ? 'var(--success)' : 'var(--danger)';
    const summaryBlock = stats.total_sold > 0 ? `
        <div class="chain-arrow">↓</div>
        <div class="chain-block chain-block--summary">
            <div class="chain-block-icon"><i data-lucide="bar-chart-2" style="width:18px;height:18px;color:var(--warning)"></i></div>
            <div class="chain-block-content">
                <div class="chain-block-title">Итог по товару</div>
                <div class="chain-block-rows">
                    <div class="chain-row"><span class="chain-label">Выручка</span><span class="chain-value" style="color:var(--success)">${formatPrice(stats.total_revenue)}</span></div>
                    <div class="chain-row"><span class="chain-label">Себестоимость</span><span class="chain-value" style="color:var(--danger)">${formatPrice(stats.purchase_cost)}</span></div>
                    <div class="chain-row"><span class="chain-label">Прибыль</span><span class="chain-value" style="color:${profitColor};font-weight:600">${formatPrice(stats.profit)}</span></div>
                </div>
            </div>
        </div>
    ` : '';

    openModal(`
        <div class="modal-header">
            <span class="modal-title">Движение товара</span>
            <button class="btn-close" onclick="closeModal()"><i data-lucide="x" style="width:15px;height:15px"></i></button>
        </div>
        <div class="modal-body" style="padding:20px">
            <div style="font-size:15px;font-weight:500;margin-bottom:16px;color:var(--accent)">${w.name}</div>
            <div class="chain-container">
                ${shipmentBlock}
                ${warehouseBlock}
                ${salesBlock}
                ${summaryBlock}
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" onclick="closeModal()">Закрыть</button>
            ${stats.total_left > 0 ? `<button class="btn btn-primary" onclick="openSaleModal(${warehouseId})">Продать</button>` : ''}
        </div>
    `);
    document.getElementById('modal').classList.add('chain-modal-wide');
    if (window.lucide) lucide.createIcons();
}