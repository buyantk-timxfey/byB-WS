<!-- Оверлей и дропдаун переключения аккаунтов (мобильный) -->
<div class="mail-account-dropdown-overlay" id="mail-acc-overlay" onclick="closeMobileAccDropdown()"></div>
<div class="mail-account-dropdown" id="mail-acc-dropdown">
    <div class="mail-account-dropdown-title">Почтовый ящик</div>
    <div id="mail-accounts-list-mobile"></div>
</div>

<div class="mail-layout" id="mail-layout">

    <!-- Левый сайдбар (десктоп) -->
    <div class="mail-sidebar" id="mail-sidebar">
        <div class="mail-compose-btn">
            <button class="btn btn-primary btn-compose" onclick="composeMail()">
                <i data-lucide="pencil" style="width:15px;height:15px"></i>
                Написать
            </button>
        </div>
        <div id="mail-accounts-list"></div>
        <div class="mail-folders-label">Папки</div>
        <div id="mail-folders-list"></div>
    </div>

    <!-- Список писем -->
    <div class="mail-list-panel" id="mail-list-panel">
        <div class="mail-list-header" id="mail-list-header">
            <!-- Аккаунт-аватар (мобильный) -->
            <button class="mail-account-switch-btn" id="mail-account-switch-btn"
                    onclick="toggleMobileAccDropdown()" style="display:none" title="Сменить ящик">C</button>
            <span class="mail-folder-title" id="mail-folder-title">Входящие</span>
            <div style="display:flex;gap:4px;align-items:center">
                <button class="btn btn-primary btn-sm" onclick="composeMail()" id="mobile-compose-btn" style="display:none">
                    <i data-lucide="pencil" style="width:13px;height:13px"></i>
                </button>
                <button class="btn-icon" onclick="refreshMessages()" title="Обновить">
                    <i data-lucide="refresh-cw" style="width:15px;height:15px"></i>
                </button>
            </div>
        </div>

        <!-- Поиск по текущей папке -->
        <div class="mail-search-row">
            <i data-lucide="search" style="width:14px;height:14px;flex-shrink:0;color:var(--text-muted)"></i>
            <input type="text" id="mail-search-input" placeholder="Поиск по письмам..."
                   autocomplete="off" oninput="onMailSearchInput(this.value)">
            <button id="mail-search-clear" style="display:none" title="Сбросить"
                    onclick="document.getElementById('mail-search-input').value='';onMailSearchInput('')">
                <i data-lucide="x" style="width:13px;height:13px"></i>
            </button>
        </div>

        <!-- Горизонтальные чипы папок (мобильный) -->
        <div class="mail-mobile-folders" id="mail-mobile-folders" style="display:none"></div>

        <div id="mail-messages-container">
            <div class="mail-loading">
                <i data-lucide="loader-2" style="width:20px;height:20px"></i>
                Загрузка…
            </div>
        </div>
        <div id="mail-pagination-bar"></div>
    </div>

    <!-- Просмотр письма -->
    <div class="mail-view-panel" id="mail-view-panel">
        <button class="mail-view-back" onclick="closeMobileMailView()">
            <i data-lucide="chevron-left" style="width:18px;height:18px"></i> Назад
        </button>
        <div class="mail-view-empty" id="mail-view-empty">
            <i data-lucide="mail-open" style="width:40px;height:40px;color:var(--text-dim)"></i>
            <p style="color:var(--text-muted);margin-top:8px;font-size:13px">Выберите письмо</p>
        </div>
        <div id="mail-view-content" style="display:none"></div>
    </div>

</div>

<script>
// Запускаем сразу — DOMContentLoaded уже отстрелял при SPA-навигации
initMail();
if (window.innerWidth <= 768) {
    var _cb = document.getElementById('mobile-compose-btn');
    var _ab = document.getElementById('mail-account-switch-btn');
    var _mf = document.getElementById('mail-mobile-folders');
    if (_cb) _cb.style.display = 'flex';
    if (_ab) _ab.style.display = 'flex';
    if (_mf) _mf.style.display = 'flex';
}

function toggleMobileAccDropdown() {
    var dd = document.getElementById('mail-acc-dropdown');
    var ov = document.getElementById('mail-acc-overlay');
    var open = dd.classList.contains('open');
    if (open) { closeMobileAccDropdown(); }
    else { dd.classList.add('open'); ov.classList.add('open'); }
}
function closeMobileAccDropdown() {
    document.getElementById('mail-acc-dropdown').classList.remove('open');
    document.getElementById('mail-acc-overlay').classList.remove('open');
}
</script>
