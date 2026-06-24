// ===== CARRIERS.JS =====

// Открыть drawer добавления ТК
function openAddCarrier() {
    openDrawer(`
        <div class="drawer-header">
            <span class="drawer-title">Новая ТК</span>
            <button class="btn-close" onclick="closeDrawer()"><i data-lucide="x" style="width:15px;height:15px"></i></button>
        </div>
        <div class="drawer-body">
            <div class="form-group">
                <label class="form-label">Название</label>
                <input type="text" class="form-control" id="cr-name" placeholder="Например: СДЭК">
            </div>
            <div class="form-group">
                <label class="form-label">Сайт / Личный кабинет</label>
                <input type="url" class="form-control" id="cr-website" placeholder="https://cdek.ru">
            </div>
            <div class="form-group">
                <label class="form-label">Комментарий</label>
                <textarea class="form-control" id="cr-comment" rows="3" placeholder="Заметки..."></textarea>
            </div>
        </div>
        <div class="drawer-footer">
            <button class="btn btn-ghost" onclick="closeDrawer()">Отмена</button>
            <button class="btn btn-primary" onclick="saveCarrier()">Сохранить</button>
        </div>
    `);
}

// Сохранить ТК
async function saveCarrier() {
    const name = document.getElementById('cr-name').value.trim();
    if (!name) {
        showToast('Введите название ТК', 'error');
        return;
    }

    await api('carriers.php', 'POST', {
        name,
        website: document.getElementById('cr-website').value,
        comment: document.getElementById('cr-comment').value
    });

    showToast('ТК добавлена');
    closeDrawer();
    setTimeout(reloadPage, 500);
}

// Открыть карточку ТК
async function openCarrier(id) {
    const carrier = await api(`carriers.php?id=${id}`);

    openModal(`
        <div class="modal-header">
            <span class="modal-title">${carrier.name}</span>
            <button class="btn-close" onclick="closeModal()"><i data-lucide="x" style="width:15px;height:15px"></i></button>
        </div>
        <div class="modal-body">
            ${carrier.website ? `
            <div class="detail-grid" style="grid-template-columns:1fr">
                <div class="detail-item">
                    <span class="detail-label">Сайт / Личный кабинет</span>
                    <span class="detail-value"><a href="${carrier.website}" target="_blank" class="track-link">${carrier.website}</a></span>
                </div>
            </div>` : ''}
            ${carrier.comment ? `
            <div class="form-group" style="margin-top:${carrier.website ? '0' : '0'}">
                <label class="form-label">Комментарий</label>
                <div style="font-size:13px;color:var(--text-muted);line-height:1.6">${carrier.comment}</div>
            </div>` : ''}
            ${!carrier.website && !carrier.comment ? `<p style="color:var(--text-dim);font-size:13px">Дополнительной информации нет</p>` : ''}
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" onclick="closeModal()">Закрыть</button>
            <button class="btn btn-ghost" onclick="closeModal(); setTimeout(() => openEditCarrier(${carrier.id}), 100)">Изменить</button>
            <button class="btn btn-danger" onclick="deleteCarrier(${carrier.id})">Удалить</button>
        </div>
    `);
}

// Удалить ТК
function deleteCarrier(id) {
    confirmAction('Удалить транспортную компанию?', async () => {
        await api(`carriers.php?id=${id}`, 'DELETE');
        showToast('ТК удалена');
        setTimeout(reloadPage, 500);
    });
}
// Открыть форму редактирования ТК
async function openEditCarrier(id) {
    const cr = await api(`carriers.php?id=${id}`);

    const drawer = document.getElementById('drawer');
    drawer.innerHTML = `
        <div class="drawer-header">
            <span class="drawer-title">Редактировать ТК</span>
            <button class="btn-close" onclick="closeDrawer()"><i data-lucide="x" style="width:15px;height:15px"></i></button>
        </div>
        <div class="drawer-body">
            <div class="form-group">
                <label class="form-label">Название</label>
                <input type="text" class="form-control" id="edit-cr-name">
            </div>
            <div class="form-group">
                <label class="form-label">Сайт / Личный кабинет</label>
                <input type="url" class="form-control" id="edit-cr-website">
            </div>
            <div class="form-group">
                <label class="form-label">Комментарий</label>
                <textarea class="form-control" id="edit-cr-comment" rows="3"></textarea>
            </div>
        </div>
        <div class="drawer-footer">
            <button class="btn btn-ghost" onclick="closeDrawer()">Отмена</button>
            <button class="btn btn-primary" onclick="updateCarrier(${id})">Сохранить</button>
        </div>
    `;
    drawer.querySelector('#edit-cr-name').value = cr.name || '';
    drawer.querySelector('#edit-cr-website').value = cr.website || '';
    drawer.querySelector('#edit-cr-comment').value = cr.comment || '';

    drawer.classList.add('active');
    document.getElementById('overlay').classList.add('active');
}

// Сохранить изменения ТК
async function updateCarrier(id) {
    const name = document.getElementById('edit-cr-name').value.trim();
    if (!name) {
        showToast('Введите название', 'error');
        return;
    }

    await api('carriers.php', 'PUT', {
        id,
        name,
        website: document.getElementById('edit-cr-website').value,
        comment: document.getElementById('edit-cr-comment').value
    });

    showToast('ТК обновлена');
    closeDrawer();
    setTimeout(reloadPage, 500);
}