// ===== WAREHOUSE.JS =====

async function openWarehouseItem(id) {
    const item = await api(`warehouse.php?id=${id}`);

    const salesHtml = item.sales.length > 0
        ? item.sales.map(s => `
            <tr>
                <td>${formatDate(s.sale_date)}</td>
                <td>${s.counterparty || '—'}</td>
                <td>${s.quantity} шт</td>
                <td>${formatPrice(s.sale_price)}</td>
            </tr>
        `).join('')
        : `<tr><td colspan="4" style="text-align:center;color:var(--text-muted);padding:20px">Продаж нет</td></tr>`;

    openModal(`
        <div class="modal-header">
            <span class="modal-title">${item.name}</span>
            <button class="btn-close" onclick="closeModal()"><i data-lucide="x" style="width:15px;height:15px"></i></button>
        </div>
        <div class="modal-body">
            <div class="detail-grid">
                <div class="detail-item">
                    <span class="detail-label">Остаток</span>
                    <span class="detail-value">${item.quantity_left} / ${item.quantity_total} шт</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Статус</span>
                    <span class="detail-value">${getStatusBadge(item.status)}</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Цена закупки</span>
                    <span class="detail-value">${formatPrice(item.purchase_price)}</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Поставка</span>
                    <span class="detail-value" style="color:var(--accent);cursor:pointer" onclick="${item.shipment_id ? `closeModal(); openShipment(${item.shipment_id})` : ''}">#${item.shipment_id || '—'}</span>
                </div>
            </div>
            <div class="modal-section-title" style="margin-top:16px">История продаж</div>
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr><th>Дата</th><th>Покупатель</th><th>Кол-во</th><th>Сумма</th></tr>
                    </thead>
                    <tbody>${salesHtml}</tbody>
                </table>
            </div>
            <div id="entity-notes-warehouse-${id}"><div class="notes-loading">Загрузка заметок…</div></div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" onclick="closeModal()">Закрыть</button>
            <button class="btn btn-ghost" onclick="openProductChain(${id})">Движение</button>
            ${item.quantity_left > 0 ? `<button class="btn btn-primary" onclick="closeModal(); openSaleModal(${id})">Продать</button>` : ''}
        </div>
    `);
    if (window.lucide) lucide.createIcons();
    if (typeof loadEntityNotes === 'function') loadEntityNotes('warehouse', id);
}