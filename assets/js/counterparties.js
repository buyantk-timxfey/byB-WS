// ===== COUNTERPARTIES.JS =====

// Открыть drawer добавления контрагента
function openAddCounterparty() {
    openDrawer(`
        <div class="drawer-header">
            <span class="drawer-title">Новый контрагент</span>
            <button class="btn-close" onclick="closeDrawer()"><i data-lucide="x" style="width:15px;height:15px"></i></button>
        </div>
        <div class="drawer-body">
            <div class="form-group">
                <label class="form-label">Тип компании</label>
                <select class="form-control" id="cp-company-type">
                    <option value="ИП">ИП</option>
                    <option value="ООО">ООО</option>
                    <option value="ПАО">ПАО</option>
                    <option value="АО">АО</option>
                    <option value="Розничный покупатель">Розничный покупатель</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Название</label>
                <input type="text" class="form-control" id="cp-name" placeholder="Название компании или ФИО">
            </div>
            <div class="form-group">
                <label class="form-label">Тип контрагента</label>
                <select class="form-control" id="cp-type">
                    <option value="Поставщик">Поставщик</option>
                    <option value="Покупатель">Покупатель</option>
                    <option value="Оба">Оба</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Реквизиты</label>
                <textarea class="form-control" id="cp-requisites" rows="3" placeholder="ИНН, расчётный счёт, БИК..."></textarea>
            </div>
            <div class="form-group">
                <label class="form-label">Комментарий</label>
                <textarea class="form-control" id="cp-comment" rows="2" placeholder="Заметки..."></textarea>
            </div>
        </div>
        <div class="drawer-footer">
            <button class="btn btn-ghost" onclick="closeDrawer()">Отмена</button>
            <button class="btn btn-primary" onclick="saveCounterparty()">Сохранить</button>
        </div>
    `);
}

// Сохранить контрагента
async function saveCounterparty() {
    const name = document.getElementById('cp-name').value.trim();
    if (!name) {
        showToast('Введите название', 'error');
        return;
    }

    await api('counterparties.php', 'POST', {
        company_type: document.getElementById('cp-company-type').value,
        name,
        type: document.getElementById('cp-type').value,
        requisites: document.getElementById('cp-requisites').value,
        comment: document.getElementById('cp-comment').value
    });

    showToast('Контрагент добавлен');
    closeDrawer();
    setTimeout(reloadPage, 500);
}

// Открыть карточку контрагента
async function openCounterparty(id) {
    const [cp, reminderHtml] = await Promise.all([
        api(`counterparties.php?id=${id}`),
        renderReminderSection('counterparty', id)
    ]);

    const shipmentsHtml = cp.shipments.length > 0
        ? cp.shipments.map(s => `
            <tr>
                <td>#${s.id}</td>
                <td>${formatDate(s.order_date)}</td>
                <td>${getStatusBadge(s.status)}</td>
                <td>${formatPrice(s.total)}</td>
            </tr>
        `).join('')
        : `<tr><td colspan="4" style="text-align:center;color:var(--text-muted);padding:20px">Поставок нет</td></tr>`;

    // Загружаем продажи для покупателей
    let salesSection = '';
    if (cp.type === 'Покупатель' || cp.type === 'Оба') {
        const allSales = await api('sales.php');
        const cpSales = allSales.filter(s => s.counterparty_id == cp.id);
        const totalSalesAmount = cpSales.reduce((sum, s) => sum + parseFloat(s.sale_price||0), 0);
        const salesRows = cpSales.slice(0, 5).map(s => `
            <tr onclick="closeModal(); openSaleDetail(${s.id})" style="cursor:pointer">
                <td>${formatDate(s.sale_date)}</td>
                <td style="font-size:12px;color:var(--text-muted)">${(s.items||[]).map(i=>i.product_name).join(', ')||'—'}</td>
                <td style="color:var(--accent)">${formatPrice(s.sale_price)}</td>
            </tr>`).join('') || '<tr><td colspan="3" style="text-align:center;color:var(--text-muted);padding:12px">Продаж нет</td></tr>';
        salesSection = `
            <div class="modal-section-title" style="margin-top:16px">История продаж · Итого: ${formatPrice(totalSalesAmount)}</div>
            <div class="table-wrapper"><table>
                <thead><tr><th>Дата</th><th>Товары</th><th>Сумма</th></tr></thead>
                <tbody>${salesRows}</tbody>
            </table></div>`;
    }

    openModal(`
        <div class="modal-header">
            <span class="modal-title">${cp.company_type} ${cp.name}</span>
            <button class="btn-close" onclick="closeModal()"><i data-lucide="x" style="width:15px;height:15px"></i></button>
        </div>
        <div class="modal-body">
            <div class="detail-grid">
                <div class="detail-item">
                    <span class="detail-label">Тип</span>
                    <span class="detail-value">${cp.type}</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Компания</span>
                    <span class="detail-value">${cp.company_type}</span>
                </div>
            </div>
            ${cp.requisites ? `
            <div class="form-group">
                <label class="form-label">Реквизиты</label>
                <div style="font-size:13px;color:var(--text);line-height:1.6">${cp.requisites}</div>
            </div>` : ''}
            ${cp.comment ? `
            <div class="form-group">
                <label class="form-label">Комментарий</label>
                <div style="font-size:13px;color:var(--text-muted)">${cp.comment}</div>
            </div>` : ''}
            ${salesSection}
            <div class="modal-section-title" style="margin-top:16px">История поставок</div>
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr><th>#</th><th>Дата</th><th>Статус</th><th>Сумма</th></tr>
                    </thead>
                    <tbody>${shipmentsHtml}</tbody>
                </table>
            </div>
            ${reminderHtml}
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" onclick="closeModal()">Закрыть</button>
            <button class="btn btn-ghost" onclick="closeModal(); setTimeout(() => openEditCounterparty(${cp.id}), 100)">Изменить</button>
            <button class="btn btn-danger" onclick="deleteCounterparty(${cp.id})">Удалить</button>
        </div>
    `);
    if (window.lucide) lucide.createIcons();
}

// Удалить контрагента
function deleteCounterparty(id) {
    confirmAction('Удалить контрагента?', async () => {
        await api(`counterparties.php?id=${id}`, 'DELETE');
        showToast('Контрагент удалён');
        setTimeout(reloadPage, 500);
    });
}
// Открыть форму редактирования контрагента
async function openEditCounterparty(id) {
    const cp = await api(`counterparties.php?id=${id}`);
    const sel = (val, target) => val === target ? 'selected' : '';
    const esc = s => (s||'').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    const escText = s => (s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');

    const drawer = document.getElementById('drawer');
    drawer.innerHTML = `
        <div class="drawer-header">
            <span class="drawer-title">Редактировать контрагента</span>
            <button class="btn-close" onclick="closeDrawer()"><i data-lucide="x" style="width:15px;height:15px"></i></button>
        </div>
        <div class="drawer-body">
            <div class="form-group">
                <label class="form-label">Тип компании</label>
                <select class="form-control" id="edit-cp-company-type">
                    <option value="ИП" ${sel(cp.company_type,'ИП')}>ИП</option>
                    <option value="ООО" ${sel(cp.company_type,'ООО')}>ООО</option>
                    <option value="ПАО" ${sel(cp.company_type,'ПАО')}>ПАО</option>
                    <option value="АО" ${sel(cp.company_type,'АО')}>АО</option>
                    <option value="Розничный покупатель" ${sel(cp.company_type,'Розничный покупатель')}>Розничный покупатель</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Название</label>
                <input type="text" class="form-control" id="edit-cp-name">
            </div>
            <div class="form-group">
                <label class="form-label">Тип контрагента</label>
                <select class="form-control" id="edit-cp-type">
                    <option value="Поставщик" ${sel(cp.type,'Поставщик')}>Поставщик</option>
                    <option value="Покупатель" ${sel(cp.type,'Покупатель')}>Покупатель</option>
                    <option value="Оба" ${sel(cp.type,'Оба')}>Оба</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Реквизиты</label>
                <textarea class="form-control" id="edit-cp-requisites" rows="3"></textarea>
            </div>
            <div class="form-group">
                <label class="form-label">Комментарий</label>
                <textarea class="form-control" id="edit-cp-comment" rows="2"></textarea>
            </div>
        </div>
        <div class="drawer-footer">
            <button class="btn btn-ghost" onclick="closeDrawer()">Отмена</button>
            <button class="btn btn-primary" onclick="updateCounterparty(${id})">Сохранить</button>
        </div>
    `;
    // Заполняем значения через JS — безопасно для любых спецсимволов
    drawer.querySelector('#edit-cp-name').value = cp.name || '';
    drawer.querySelector('#edit-cp-requisites').value = cp.requisites || '';
    drawer.querySelector('#edit-cp-comment').value = cp.comment || '';

    drawer.classList.add('active');
    document.getElementById('overlay').classList.add('active');
}

// Сохранить изменения контрагента
async function updateCounterparty(id) {
    const name = document.getElementById('edit-cp-name').value.trim();
    if (!name) {
        showToast('Введите название', 'error');
        return;
    }

    await api('counterparties.php', 'PUT', {
        id,
        company_type: document.getElementById('edit-cp-company-type').value,
        name,
        type: document.getElementById('edit-cp-type').value,
        requisites: document.getElementById('edit-cp-requisites').value,
        comment: document.getElementById('edit-cp-comment').value
    });

    showToast('Контрагент обновлён');
    closeDrawer();
    setTimeout(reloadPage, 500);
}