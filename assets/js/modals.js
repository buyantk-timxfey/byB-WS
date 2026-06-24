// ===== MODALS & DRAWER MANAGEMENT =====

let _modalOpenedAt = 0;
// Отложенная очистка содержимого при закрытии. Если новая модалка открывается
// раньше чем через 300мс — таймер отменяется, иначе он стёр бы свежую форму.
let _modalWipeTimer  = null;
let _drawerWipeTimer = null;

function openDrawer(html) {
    clearTimeout(_drawerWipeTimer);
    document.getElementById('drawer').innerHTML = html;
    document.getElementById('drawer').classList.add('active');
    document.getElementById('overlay').classList.add('active');
    _modalOpenedAt = Date.now();
    if (window.lucide) lucide.createIcons();
}

function openModal(html) {
    clearTimeout(_modalWipeTimer);
    const modal = document.getElementById('modal');
    modal.innerHTML = html;
    modal.classList.remove('modal-wide');
    modal.classList.add('active');
    document.getElementById('overlay').classList.add('active');
    _modalOpenedAt = Date.now();
    modal.style.pointerEvents = 'none';
    setTimeout(() => { modal.style.pointerEvents = ''; }, 300);
    if (window.lucide) lucide.createIcons();
}

function openWideModal(html) {
    clearTimeout(_modalWipeTimer);
    const modal = document.getElementById('modal');
    modal.innerHTML = html;
    modal.classList.add('active', 'modal-wide');
    document.getElementById('overlay').classList.add('active');
    _modalOpenedAt = Date.now();
    modal.style.pointerEvents = 'none';
    setTimeout(() => { modal.style.pointerEvents = ''; }, 300);
    if (window.lucide) lucide.createIcons();
}

function closeAllModals() {
    document.getElementById('drawer').classList.remove('active');
    document.getElementById('modal').classList.remove('active');
    document.getElementById('overlay').classList.remove('active');
    clearTimeout(_modalWipeTimer);
    clearTimeout(_drawerWipeTimer);
    _modalWipeTimer = setTimeout(() => {
        document.getElementById('modal').innerHTML = '';
    }, 300);
    _drawerWipeTimer = setTimeout(() => {
        document.getElementById('drawer').innerHTML = '';
    }, 300);
}

function closeDrawer() {
    document.getElementById('drawer').classList.remove('active');
    document.getElementById('overlay').classList.remove('active');
    clearTimeout(_drawerWipeTimer);
    _drawerWipeTimer = setTimeout(() => {
        document.getElementById('drawer').innerHTML = '';
    }, 300);
}

function closeModal() {
    document.getElementById('modal').classList.remove('active');
    document.getElementById('overlay').classList.remove('active');
    clearTimeout(_modalWipeTimer);
    _modalWipeTimer = setTimeout(() => {
        document.getElementById('modal').innerHTML = '';
    }, 300);
}

// Закрытие по Escape
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeAllModals();
});

// Блокируем всплытие кликов с модалки и drawer на overlay
document.getElementById('modal').addEventListener('click', (e) => e.stopPropagation());
document.getElementById('drawer').addEventListener('click', (e) => e.stopPropagation());

// Закрытие по клику на оверлей
document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('overlay')?.addEventListener('click', (e) => {
        if (e.target !== document.getElementById('overlay')) return;
        if (Date.now() - _modalOpenedAt > 300) closeAllModals();
    });
});

// ===== CONFIRM DIALOG =====
let _confirmCallback = null;

function confirmAction(message, callback) {
    _confirmCallback = callback;
    openModal(`
        <div class="modal-header">
            <span class="modal-title">Подтверждение</span>
            <button class="btn-close" onclick="closeModal()"><i data-lucide="x" style="width:15px;height:15px"></i></button>
        </div>
        <div class="modal-body">
            <p class="confirm-text">${message}</p>
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" onclick="closeModal()">Отмена</button>
            <button class="btn btn-danger" onclick="closeModal(); if(_confirmCallback) _confirmCallback();">Удалить</button>
        </div>
    `);
}

// ===== TOAST NOTIFICATIONS =====
function _getToastContainer() {
    let c = document.getElementById('toast-container');
    if (!c) {
        c = document.createElement('div');
        c.id = 'toast-container';
        c.className = 'toast-container';
        document.body.appendChild(c);
    }
    return c;
}

function showToast(message, type = 'success') {
    const duration = 3500;
    const icons = {
        success: '<svg class="toast-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color:#4CAF50"><polyline points="20 6 9 17 4 12"/></svg>',
        error:   '<svg class="toast-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color:#F44336"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>',
        warning: '<svg class="toast-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color:#FF9800"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
        info:    '<svg class="toast-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color:#2196F3"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>',
    };
    const container = _getToastContainer();
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    toast.innerHTML = `
        ${icons[type] || icons.info}
        <span class="toast-msg">${message}</span>
        <div class="toast-progress" style="animation-duration:${duration}ms"></div>
    `;
    container.appendChild(toast);

    setTimeout(() => {
        toast.classList.add('removing');
        setTimeout(() => toast.remove(), 220);
    }, duration);
}