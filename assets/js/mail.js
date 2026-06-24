// ──────────────────────────────────────────────────────
//  Mail client
// ──────────────────────────────────────────────────────

const mailState = {
    account:  'ceo',
    folder:   'INBOX',
    page:     1,
    selectedUid: null,
    accounts: [],
    folders:  [],   // список папок текущего ящика (для меню «Переместить»)
    total:    0,
    perPage:  30,
    q:        '',   // строка поиска
};

// Подписи по аккаунтам
const MAIL_SIGNATURES = {
    ceo:  '\n\n--\nС уважением, Тимофей Б.\nCEO by Buka.',
    info: '\n\n--\nС уважением, команда byB.',
};

let mailCheckInterval = null;

async function initMail() {
    if (!document.getElementById('mail-layout')) return;
    await loadMailAccounts();
    await loadMailFolders();
    await loadMailMessages();
    startMailPolling();
    lucide.createIcons();
}

// ── Аккаунты ──────────────────────────────────────────

async function loadMailAccounts() {
    const data = await mailApi('accounts');
    if (!data || data.error) return;
    mailState.accounts = data;

    const accountHtml = data.map(a => `
        <div class="mail-account-item ${a.key === mailState.account ? 'active' : ''}"
             data-account="${a.key}"
             onclick="switchMailAccount('${a.key}')">
            <div class="mail-account-avatar">${a.label[0]}</div>
            <div class="mail-account-info">
                <div class="mail-account-label">${a.label}</div>
                <div class="mail-account-email">${a.email}</div>
            </div>
        </div>
    `).join('');

    // Десктопный список
    const el = document.getElementById('mail-accounts-list');
    if (el) el.innerHTML = accountHtml;

    // Мобильный дропдаун
    const mobileEl = document.getElementById('mail-accounts-list-mobile');
    if (mobileEl) mobileEl.innerHTML = accountHtml;

    // Обновляем аватар в шапке
    _updateAccountSwitchBtn();
}

function _updateAccountSwitchBtn() {
    const btn = document.getElementById('mail-account-switch-btn');
    if (!btn) return;
    const acc = mailState.accounts.find(a => a.key === mailState.account);
    btn.textContent = acc ? acc.label[0].toUpperCase() : '?';
    btn.title = acc ? `${acc.label} <${acc.email}> — сменить ящик` : 'Сменить ящик';
}

async function switchMailAccount(key) {
    mailState.account = key;
    mailState.folder  = 'INBOX';
    mailState.page    = 1;
    mailState.selectedUid = null;
    resetMailSearch();
    document.getElementById('mail-view-empty').style.display  = 'flex';
    document.getElementById('mail-view-content').style.display = 'none';
    document.getElementById('mail-folder-title').textContent   = 'Входящие';
    document.querySelectorAll('.mail-account-item').forEach(el =>
        el.classList.toggle('active', el.dataset.account === key)
    );
    _updateAccountSwitchBtn();
    // Закрываем мобильный дропдаун
    if (typeof closeMobileAccDropdown === 'function') closeMobileAccDropdown();
    await loadMailFolders();
    await loadMailMessages();
}

// ── Папки ──────────────────────────────────────────────

async function loadMailFolders() {
    const el = document.getElementById('mail-folders-list');
    if (!el) return;

    const data = await mailApi(`folders&account=${mailState.account}`);
    if (!data || data.error) return;
    mailState.folders = data;

    const priorityOrder = ['INBOX', 'Отправленные', 'Черновики', 'Спам', 'Корзина', 'Sent', 'Drafts', 'Spam', 'Trash'];
    const sorted = [...data].sort((a, b) => {
        const ia = priorityOrder.findIndex(p => a.label.toLowerCase().includes(p.toLowerCase()));
        const ib = priorityOrder.findIndex(p => b.label.toLowerCase().includes(p.toLowerCase()));
        if (ia !== -1 && ib !== -1) return ia - ib;
        if (ia !== -1) return -1;
        if (ib !== -1) return 1;
        return a.label.localeCompare(b.label);
    });

    const icons = {
        'inbox': 'inbox', 'отправленные': 'send', 'sent': 'send',
        'черновики': 'file-edit', 'drafts': 'file-edit',
        'спам': 'shield-alert', 'spam': 'shield-alert',
        'корзина': 'trash-2', 'trash': 'trash-2',
    };

    el.innerHTML = sorted.map(f => {
        const labelLower = f.label.toLowerCase();
        const icon = Object.entries(icons).find(([k]) => labelLower.includes(k))?.[1] ?? 'folder';
        const isActive = f.name === mailState.folder;
        return `
            <div class="mail-folder-item ${isActive ? 'active' : ''}"
                 data-folder="${f.name}"
                 onclick="switchMailFolder('${f.name}', '${escAttr(f.label)}')">
                <i data-lucide="${icon}" style="width:15px;height:15px;flex-shrink:0"></i>
                <span class="mail-folder-name">${f.label}</span>
                ${f.unseen > 0 ? `<span class="mail-folder-badge">${f.unseen}</span>` : ''}
            </div>
        `;
    }).join('');

    // Мобильные чипы папок
    const mobileBar = document.getElementById('mail-mobile-folders');
    if (mobileBar) {
        mobileBar.innerHTML = sorted.map(f => `
            <div class="mail-folder-chip ${f.name === mailState.folder ? 'active' : ''}"
                 data-folder="${f.name}"
                 onclick="switchMailFolder('${f.name}', '${escAttr(f.label)}')">
                ${f.label}${f.unseen > 0 ? ` <b style="color:var(--accent)">${f.unseen}</b>` : ''}
            </div>
        `).join('');
    }

    lucide.createIcons();
}

async function switchMailFolder(name, label) {
    mailState.folder = name;
    mailState.page   = 1;
    mailState.selectedUid = null;
    resetMailSearch();
    document.getElementById('mail-folder-title').textContent = label;
    document.getElementById('mail-view-empty').style.display = 'flex';
    document.getElementById('mail-view-content').style.display = 'none';
    document.querySelectorAll('.mail-folder-item, .mail-folder-chip').forEach(el =>
        el.classList.toggle('active', el.dataset.folder === name)
    );
    await loadMailMessages();
}

// ── Список писем (с кэшированием) ─────────────────────

function mailCacheKey() {
    return `mail_cache_${mailState.account}_${mailState.folder}_${mailState.page}`;
}

function mailCacheGet() {
    try {
        const raw = localStorage.getItem(mailCacheKey());
        return raw ? JSON.parse(raw) : null;
    } catch { return null; }
}

function mailCacheSet(data) {
    try {
        localStorage.setItem(mailCacheKey(), JSON.stringify({
            messages: data.messages,
            total: data.total,
            ts: Date.now(),
        }));
    } catch {}
}

async function loadMailMessages() {
    const container = document.getElementById('mail-messages-container');
    if (!container) return;

    // При поиске кэш не используем
    const cached = mailState.q ? null : mailCacheGet();
    if (cached) {
        mailState.total = cached.total;
        renderMailList(cached.messages);
        renderMailPagination();
        // Тихий индикатор обновления
        const refreshBtn = document.querySelector('#mail-list-header .btn-icon');
        if (refreshBtn) refreshBtn.classList.add('spinning');
    } else {
        container.innerHTML = `
            <div class="mail-loading">
                <i data-lucide="loader-2" style="width:18px;height:18px"></i> Загрузка…
            </div>`;
        lucide.createIcons();
    }

    const qParam = mailState.q ? `&q=${encodeURIComponent(mailState.q)}` : '';
    const data = await mailApi(`list&account=${mailState.account}&folder=${encodeURIComponent(mailState.folder)}&page=${mailState.page}${qParam}`);

    // Снимаем спиннер
    const refreshBtn = document.querySelector('#mail-list-header .btn-icon');
    if (refreshBtn) refreshBtn.classList.remove('spinning');

    if (!data || data.error) {
        if (!cached) container.innerHTML = `<div class="mail-error">${data?.error ?? 'Ошибка загрузки'}</div>`;
        return;
    }

    if (!mailState.q) mailCacheSet(data);
    mailState.total = data.total;
    renderMailList(data.messages);
    renderMailPagination();
}

function renderMailList(messages) {
    const container = document.getElementById('mail-messages-container');
    if (!container) return;

    if (!messages.length) {
        container.innerHTML = '<div class="mail-empty">Нет писем</div>';
        return;
    }

    container.innerHTML = messages.map(m => {
        const date    = formatMailDate(m.date);
        const from    = extractName(m.from);
        const subject = m.subject || '(без темы)';
        return `
            <div class="mail-message-item ${m.seen ? '' : 'unread'} ${m.uid === mailState.selectedUid ? 'selected' : ''}"
                 data-uid="${m.uid}" onclick="viewMessage(${m.uid})">
                <button class="mail-msg-star ${m.flagged ? 'flagged' : ''}" title="${m.flagged ? 'Снять отметку' : 'Отметить важным'}"
                        onclick="event.stopPropagation(); toggleMailFlag(${m.uid}, ${m.flagged ? 0 : 1})">
                    <i data-lucide="star" style="width:13px;height:13px"></i>
                </button>
                <div class="mail-msg-from">${escHtml(from)}</div>
                <div class="mail-msg-subject">${escHtml(subject)}</div>
                <div class="mail-msg-date">${date}</div>
            </div>
        `;
    }).join('');
    lucide.createIcons();
}

// ── Флажок «важное» ────────────────────────────────────
async function toggleMailFlag(uid, flagged) {
    const res = await mailApi(`flag&account=${mailState.account}&folder=${encodeURIComponent(mailState.folder)}&uid=${uid}&flagged=${flagged}`);
    if (!res?.ok) { showToast(res?.error ?? 'Ошибка', 'error'); return; }
    const star = document.querySelector(`.mail-message-item[data-uid="${uid}"] .mail-msg-star`);
    if (star) {
        star.classList.toggle('flagged', !!flagged);
        star.setAttribute('onclick', `event.stopPropagation(); toggleMailFlag(${uid}, ${flagged ? 0 : 1})`);
    }
    const viewStar = document.getElementById('mail-view-star');
    if (viewStar && mailState.selectedUid === uid) {
        viewStar.classList.toggle('flagged', !!flagged);
        viewStar.setAttribute('onclick', `toggleMailFlag(${uid}, ${flagged ? 0 : 1})`);
    }
    try { localStorage.removeItem(mailCacheKey()); } catch {}
}

// ── Поиск по письмам ───────────────────────────────────
let _mailSearchTimer = null;
function onMailSearchInput(val) {
    const clearBtn = document.getElementById('mail-search-clear');
    if (clearBtn) clearBtn.style.display = val ? '' : 'none';
    clearTimeout(_mailSearchTimer);
    _mailSearchTimer = setTimeout(async () => {
        mailState.q    = val.trim();
        mailState.page = 1;
        await loadMailMessages();
    }, 400);
}

// Сброс поиска при смене папки/ящика
function resetMailSearch() {
    mailState.q = '';
    const inp = document.getElementById('mail-search-input');
    if (inp) inp.value = '';
    const clearBtn = document.getElementById('mail-search-clear');
    if (clearBtn) clearBtn.style.display = 'none';
}

function renderMailPagination() {
    const bar = document.getElementById('mail-pagination-bar');
    if (!bar) return;
    const pages = Math.ceil(mailState.total / mailState.perPage);
    if (pages <= 1) { bar.innerHTML = ''; return; }

    bar.innerHTML = `
        <div class="mail-pagination">
            <button onclick="mailGoPage(${mailState.page - 1})" ${mailState.page <= 1 ? 'disabled' : ''}>
                <i data-lucide="chevron-left" style="width:14px;height:14px"></i>
            </button>
            <span>${mailState.page} / ${pages}</span>
            <button onclick="mailGoPage(${mailState.page + 1})" ${mailState.page >= pages ? 'disabled' : ''}>
                <i data-lucide="chevron-right" style="width:14px;height:14px"></i>
            </button>
        </div>
    `;
    lucide.createIcons();
}

async function mailGoPage(p) {
    const pages = Math.ceil(mailState.total / mailState.perPage);
    if (p < 1 || p > pages) return;
    mailState.page = p;
    await loadMailMessages();
}

async function refreshMessages() {
    // Сбрасываем кэш текущей папки перед обновлением
    try { localStorage.removeItem(mailCacheKey()); } catch {}
    await loadMailFolders();
    await loadMailMessages();
}

// ── Просмотр письма ────────────────────────────────────

async function viewMessage(uid) {
    mailState.selectedUid = uid;
    document.querySelectorAll('.mail-message-item').forEach(el =>
        el.classList.toggle('selected', parseInt(el.dataset.uid) === uid)
    );
    const item = document.querySelector(`.mail-message-item[data-uid="${uid}"]`);
    if (item) item.classList.remove('unread');

    const viewEl  = document.getElementById('mail-view-content');
    const emptyEl = document.getElementById('mail-view-empty');
    emptyEl.style.display  = 'none';
    viewEl.style.display   = 'block';
    if (window.innerWidth <= 768) {
        document.getElementById('mail-view-panel').classList.add('mobile-open');
    }
    viewEl.innerHTML = `
        <div class="mail-view-loading">
            <i data-lucide="loader-2" style="width:20px;height:20px"></i>
        </div>`;
    lucide.createIcons();

    const msg = await mailApi(`message&account=${mailState.account}&folder=${encodeURIComponent(mailState.folder)}&uid=${uid}`);
    if (!msg || msg.error) {
        viewEl.innerHTML = `<div class="mail-error" style="padding:24px">${msg?.error ?? 'Ошибка'}</div>`;
        return;
    }

    const bodyHtml = msg.html
        ? `<iframe id="mail-iframe" sandbox="allow-same-origin allow-popups" class="mail-body-frame" srcdoc="${escAttr(msg.html)}"></iframe>`
        : `<div class="mail-body-plain">${escHtml(msg.plain || '(пусто)').replace(/\n/g, '<br>')}</div>`;

    const attachments = msg.attachments.length
        ? `<div class="mail-attachments">
            ${msg.attachments.map(a => `
                <a class="mail-attachment-chip"
                   href="api/mail.php?action=attachment&account=${mailState.account}&folder=${encodeURIComponent(mailState.folder)}&uid=${uid}&part=${a.part}"
                   download="${escAttr(a.name)}">
                    <i data-lucide="paperclip" style="width:13px;height:13px"></i>
                    ${escHtml(a.name)} <span style="color:var(--text-muted)">${formatSize(a.size)}</span>
                </a>
            `).join('')}
           </div>`
        : '';

    const folderLow = (mailState.folder || '').toLowerCase();
    const isDrafts  = folderLow.includes('draft') || decodeURIComponent(folderLow).includes('черновик');
    const isTrash   = folderLow.includes('trash') || ['корзина','удалённые'].some(n => mailState.folder.toLowerCase().includes(n));

    // Папки для меню «Переместить» (кроме текущей)
    const moveItems = (mailState.folders || [])
        .filter(f => f.name !== mailState.folder)
        .map(f => `<div class="mail-move-option" onclick="moveMessage(${uid}, '${escAttr(f.name)}', '${escAttr(f.label)}')">${escHtml(f.label)}</div>`)
        .join('');
    const spamFolder = (mailState.folders || []).find(f => /спам|spam|junk/i.test(f.label));

    viewEl.innerHTML = `
        <div class="mail-view-toolbar">
            ${isDrafts ? `
            <button class="btn btn-primary btn-sm" onclick="editDraft(${uid})">
                <i data-lucide="pencil" style="width:14px;height:14px"></i> Редактировать
            </button>` : `
            <button class="btn btn-ghost btn-sm" onclick="replyMail(${uid})">
                <i data-lucide="reply" style="width:14px;height:14px"></i> Ответить
            </button>
            <button class="btn btn-ghost btn-sm" onclick="forwardMail(${uid})">
                <i data-lucide="forward" style="width:14px;height:14px"></i> Переслать
            </button>`}
            <button class="mail-msg-star mail-view-star ${msg.flagged ? 'flagged' : ''}" id="mail-view-star"
                    title="Важное" onclick="toggleMailFlag(${uid}, ${msg.flagged ? 0 : 1})">
                <i data-lucide="star" style="width:15px;height:15px"></i>
            </button>
            <button class="btn btn-ghost btn-sm" onclick="markMessage(${uid}, 0)" title="Отметить непрочитанным">
                <i data-lucide="mail" style="width:14px;height:14px"></i>
            </button>
            ${spamFolder && !folderLow.includes('spam') && !/спам/i.test(mailState.folder) ? `
            <button class="btn btn-ghost btn-sm" title="В спам" onclick="moveMessage(${uid}, '${escAttr(spamFolder.name)}', 'Спам')">
                <i data-lucide="shield-alert" style="width:14px;height:14px"></i>
            </button>` : ''}
            <div class="mail-move-wrap">
                <button class="btn btn-ghost btn-sm" title="Переместить в папку" onclick="event.stopPropagation(); document.getElementById('mail-move-drop').classList.toggle('open')">
                    <i data-lucide="folder-input" style="width:14px;height:14px"></i>
                </button>
                <div class="mail-move-drop" id="mail-move-drop">${moveItems}</div>
            </div>
            <button class="btn btn-ghost btn-sm btn-danger-hover" title="${isTrash ? 'Удалить навсегда' : 'В корзину'}" onclick="deleteMessage(${uid})">
                <i data-lucide="trash-2" style="width:14px;height:14px"></i>
            </button>
        </div>
        <div class="mail-view-meta">
            <div class="mail-view-subject">${escHtml(msg.subject)}</div>
            <div class="mail-view-from">
                <span class="mail-view-meta-label">От:</span> ${escHtml(msg.from)}
            </div>
            <div class="mail-view-to">
                <span class="mail-view-meta-label">Кому:</span> ${escHtml(msg.to)}
            </div>
            ${msg.cc ? `<div class="mail-view-to">
                <span class="mail-view-meta-label">Копия:</span> ${escHtml(msg.cc)}
            </div>` : ''}
            <div class="mail-view-date">
                <span class="mail-view-meta-label">Дата:</span> ${formatMailDateFull(msg.date)}
            </div>
        </div>
        ${attachments}
        <div class="mail-view-body">
            ${bodyHtml}
        </div>
    `;
    lucide.createIcons();

    // Авто-resize iframe
    const iframe = document.getElementById('mail-iframe');
    if (iframe) {
        const resize = () => {
            try {
                const h = iframe.contentDocument?.body?.scrollHeight;
                if (h) iframe.style.height = h + 'px';
            } catch(e) {}
        };
        iframe.onload = resize;
        setTimeout(resize, 500);
    }
}

// Текст → HTML с переносами (для цитат и подписи)
function nl2brEsc(s) {
    return escHtml(String(s ?? '')).replace(/\n/g, '<br>');
}

function mailSigHtml(accountKey) {
    return nl2brEsc(MAIL_SIGNATURES[accountKey] ?? '');
}

async function replyMail(uid) {
    const msg = await mailApi(`message&account=${mailState.account}&folder=${encodeURIComponent(mailState.folder)}&uid=${uid}`);
    if (!msg || msg.error) return;
    const replyAddr = msg.reply_to || msg.from;
    const subject   = msg.subject.startsWith('Re:') ? msg.subject : 'Re: ' + msg.subject;
    const quoteText = (msg.plain || stripHtml(msg.html)).slice(0, 4000);
    const bodyHtml  = '<br>' + mailSigHtml(mailState.account)
        + '<br><br><div style="color:#888;font-size:12px">--- ' + escHtml(msg.from) + ' написал(а) ---</div>'
        + '<blockquote style="border-left:2px solid #999;margin:6px 0;padding:2px 0 2px 10px;color:#777">'
        + nl2brEsc(quoteText) + '</blockquote>';
    composeMail({ to: replyAddr, subject, bodyHtml, cursorTop: true });
}

async function forwardMail(uid) {
    const msg = await mailApi(`message&account=${mailState.account}&folder=${encodeURIComponent(mailState.folder)}&uid=${uid}`);
    if (!msg || msg.error) return;
    const subject  = msg.subject.startsWith('Fwd:') ? msg.subject : 'Fwd: ' + msg.subject;
    const fwdText  = msg.plain || stripHtml(msg.html);
    const bodyHtml = '<br>' + mailSigHtml(mailState.account)
        + '<br><br><div style="color:#888;font-size:12px">--- Пересылка ---<br>От: ' + escHtml(msg.from)
        + '<br>Кому: ' + escHtml(msg.to) + '<br>Дата: ' + escHtml(msg.date) + '</div><br>'
        + nl2brEsc(fwdText);
    composeMail({
        subject, bodyHtml, cursorTop: true,
        forwardUid: uid, forwardFolder: mailState.folder,
        forwardAttachments: msg.attachments || [],
    });
}

async function markMessage(uid, read) {
    await mailApi(`mark&account=${mailState.account}&folder=${encodeURIComponent(mailState.folder)}&uid=${uid}&read=${read}`);
    if (!read) {
        const item = document.querySelector(`.mail-message-item[data-uid="${uid}"]`);
        if (item) item.classList.add('unread');
    }
}

async function deleteMessage(uid) {
    const isTrash = /trash|корзина|удалённые/i.test(decodeURIComponent(mailState.folder));
    const label   = isTrash ? 'Удалить письмо навсегда?' : 'Переместить письмо в корзину?';
    confirmAction(label, async () => {
        const res = await mailApi(`delete&account=${mailState.account}&folder=${encodeURIComponent(mailState.folder)}&uid=${uid}`);
        if (res?.ok) {
            showToast(res.moved_to_trash ? 'Перемещено в корзину' : 'Письмо удалено', 'success');
            document.getElementById('mail-view-empty').style.display = 'flex';
            document.getElementById('mail-view-content').style.display = 'none';
            try { localStorage.removeItem(mailCacheKey()); } catch {}
            await loadMailMessages();
            await loadMailFolders();
        }
    });
}

// Переместить письмо в папку
async function moveMessage(uid, target, label) {
    const drop = document.getElementById('mail-move-drop');
    if (drop) drop.classList.remove('open');
    const res = await mailApi(`move&account=${mailState.account}&folder=${encodeURIComponent(mailState.folder)}&uid=${uid}&target=${encodeURIComponent(target)}`);
    if (!res?.ok) { showToast(res?.error ?? 'Не удалось переместить', 'error'); return; }
    showToast(`Перемещено: ${label}`, 'success');
    document.getElementById('mail-view-empty').style.display = 'flex';
    document.getElementById('mail-view-content').style.display = 'none';
    try { localStorage.removeItem(mailCacheKey()); } catch {}
    await loadMailMessages();
    await loadMailFolders();
}

// Открыть черновик на дописывание
async function editDraft(uid) {
    const msg = await mailApi(`message&account=${mailState.account}&folder=${encodeURIComponent(mailState.folder)}&uid=${uid}`);
    if (!msg || msg.error) return;
    composeMail({
        to:       msg.to,
        cc:       msg.cc || '',
        subject:  msg.subject === '(без темы)' ? '' : msg.subject,
        bodyHtml: msg.html || nl2brEsc(msg.plain || ''),
        draftUid: uid,
        draftFolder: mailState.folder,
    });
}

// Закрытие меню «Переместить» по клику мимо
document.addEventListener('click', () => {
    document.getElementById('mail-move-drop')?.classList.remove('open');
});

// ── Написать письмо ────────────────────────────────────

const MAIL_MAX_ATTACH = 20 * 1024 * 1024; // 20 МБ суммарно
let _composeFiles = [];   // выбранные File-объекты
let _composeCtx   = {};   // draftUid/draftFolder/forwardUid/forwardFolder

function composeMail(opts = {}) {
    const { to = '', cc = '', subject = '', bodyHtml = '', cursorTop = false,
            draftUid = null, draftFolder = '', forwardUid = null, forwardFolder = '',
            forwardAttachments = [] } = opts;

    _composeFiles = [];
    _composeCtx   = { draftUid, draftFolder, forwardUid, forwardFolder };

    const bodyVal  = bodyHtml || mailSigHtml(mailState.account);
    const contacts = getContactEmails();
    const datalistOpts = [...contacts].map(e => `<option value="${escAttr(e)}">`).join('');

    const accOptions = mailState.accounts.map(a =>
        `<option value="${a.key}" ${a.key === mailState.account ? 'selected' : ''}>${a.label} &lt;${a.email}&gt;</option>`
    ).join('');

    // Вложения пересылаемого письма (прикрепляются сервером)
    const fwdChips = forwardAttachments.length ? `
        <div class="compose-fwd-attachments">
            <i data-lucide="paperclip" style="width:12px;height:12px"></i>
            Будут пересланы: ${forwardAttachments.map(a => escHtml(a.name)).join(', ')}
        </div>` : '';

    openDrawer(`
        <div class="drawer-header">
            <h3 class="drawer-title">${draftUid ? 'Черновик' : 'Новое письмо'}</h3>
            <button class="btn-close" onclick="closeMailDrawer()"><i data-lucide="x" style="width:15px;height:15px"></i></button>
        </div>
        <div class="drawer-body compose-body-wrap">
            <div class="form-group">
                <label class="form-label">От</label>
                <select class="form-control" id="compose-from" onchange="onComposeAccountChange(this.value)">${accOptions}</select>
            </div>
            <div class="form-group">
                <label class="form-label" style="display:flex;justify-content:space-between;align-items:center">
                    Кому
                    <span class="compose-cc-links">
                        <a href="#" onclick="event.preventDefault();toggleComposeField('cc')">Копия</a>
                        <a href="#" onclick="event.preventDefault();toggleComposeField('bcc')">Скрытая</a>
                    </span>
                </label>
                <input class="form-control" id="compose-to" type="text"
                       list="mail-contacts-datalist"
                       value="${escAttr(to)}" placeholder="email@example.com, второй@адрес.ru" autocomplete="off">
                <datalist id="mail-contacts-datalist">${datalistOpts}</datalist>
            </div>
            <div class="form-group" id="compose-cc-group" style="${cc ? '' : 'display:none'}">
                <label class="form-label">Копия</label>
                <input class="form-control" id="compose-cc" type="text" value="${escAttr(cc)}" placeholder="email@example.com" autocomplete="off">
            </div>
            <div class="form-group" id="compose-bcc-group" style="display:none">
                <label class="form-label">Скрытая копия</label>
                <input class="form-control" id="compose-bcc" type="text" placeholder="email@example.com" autocomplete="off">
            </div>
            <div class="form-group">
                <label class="form-label">Тема</label>
                <input class="form-control" id="compose-subject" value="${escAttr(subject)}" placeholder="Тема письма">
            </div>
            <div class="form-group" style="flex:1;display:flex;flex-direction:column;min-height:0">
                <div class="compose-toolbar">
                    <button type="button" title="Жирный" onmousedown="event.preventDefault();composeCmd('bold')"><i data-lucide="bold"></i></button>
                    <button type="button" title="Курсив" onmousedown="event.preventDefault();composeCmd('italic')"><i data-lucide="italic"></i></button>
                    <button type="button" title="Подчёркнутый" onmousedown="event.preventDefault();composeCmd('underline')"><i data-lucide="underline"></i></button>
                    <span class="compose-toolbar-sep"></span>
                    <button type="button" title="Список" onmousedown="event.preventDefault();composeCmd('insertUnorderedList')"><i data-lucide="list"></i></button>
                    <button type="button" title="Ссылка" onmousedown="event.preventDefault();composeLink()"><i data-lucide="link"></i></button>
                    <button type="button" title="Очистить форматирование" onmousedown="event.preventDefault();composeCmd('removeFormat')"><i data-lucide="eraser"></i></button>
                    <span class="compose-toolbar-sep"></span>
                    <button type="button" title="Прикрепить файл" onclick="document.getElementById('compose-file-input').click()"><i data-lucide="paperclip"></i></button>
                </div>
                <div class="compose-editor" id="compose-editor" contenteditable="true">${bodyVal}</div>
                <input type="file" id="compose-file-input" multiple style="display:none" onchange="composeAddFiles(this.files); this.value=''">
                ${fwdChips}
                <div class="compose-attachments" id="compose-attachments"></div>
            </div>
        </div>
        <div class="drawer-footer">
            <button class="btn btn-ghost" onclick="closeMailDrawer()">Отмена</button>
            <button class="btn btn-ghost" id="compose-draft-btn" onclick="saveMailDraft()">
                <i data-lucide="file-edit" style="width:14px;height:14px"></i> Черновик
            </button>
            <button class="btn btn-primary" id="compose-send-btn" onclick="sendMail()">
                <i data-lucide="send" style="width:14px;height:14px"></i> Отправить
            </button>
        </div>
    `);
    lucide.createIcons();
    setTimeout(() => {
        const toInput = document.getElementById('compose-to');
        const editor  = document.getElementById('compose-editor');
        if (toInput && !to) { toInput.focus(); return; }
        if (cursorTop && editor) {
            // Курсор в начало письма (над цитатой)
            editor.focus();
            const range = document.createRange();
            range.setStart(editor, 0);
            range.collapse(true);
            const sel = window.getSelection();
            sel.removeAllRanges();
            sel.addRange(range);
        } else {
            document.getElementById('compose-subject')?.focus();
        }
    }, 100);
}

function toggleComposeField(which) {
    const el = document.getElementById(`compose-${which}-group`);
    if (!el) return;
    el.style.display = el.style.display === 'none' ? '' : 'none';
    if (el.style.display !== 'none') el.querySelector('input')?.focus();
}

function composeCmd(cmd) {
    document.getElementById('compose-editor')?.focus();
    document.execCommand(cmd, false, null);
}

function composeLink() {
    const url = prompt('Адрес ссылки (https://...)');
    if (!url) return;
    document.getElementById('compose-editor')?.focus();
    document.execCommand('createLink', false, url);
}

// ── Вложения в композере ───────────────────────────────
function composeAddFiles(fileList) {
    for (const f of fileList) _composeFiles.push(f);
    const total = _composeFiles.reduce((s, f) => s + f.size, 0);
    if (total > MAIL_MAX_ATTACH) {
        showToast('Вложения превышают 20 МБ суммарно', 'error');
        // Убираем последние добавленные до лимита
        while (_composeFiles.reduce((s, f) => s + f.size, 0) > MAIL_MAX_ATTACH && _composeFiles.length) {
            _composeFiles.pop();
        }
    }
    renderComposeAttachments();
}

function composeRemoveFile(idx) {
    _composeFiles.splice(idx, 1);
    renderComposeAttachments();
}

function renderComposeAttachments() {
    const el = document.getElementById('compose-attachments');
    if (!el) return;
    el.innerHTML = _composeFiles.map((f, i) => `
        <span class="compose-attach-chip">
            <i data-lucide="paperclip" style="width:11px;height:11px"></i>
            ${escHtml(f.name)} <span class="compose-attach-size">${formatSize(f.size)}</span>
            <button title="Убрать" onclick="composeRemoveFile(${i})"><i data-lucide="x" style="width:11px;height:11px"></i></button>
        </span>
    `).join('');
    lucide.createIcons();
}

// При смене аккаунта в compose — обновляем подпись если она стоит в конце
function onComposeAccountChange(key) {
    const editor = document.getElementById('compose-editor');
    if (!editor) return;
    const sigs = Object.keys(MAIL_SIGNATURES).map(k => mailSigHtml(k));
    const cur  = editor.innerHTML;
    const oldSig = sigs.find(s => s && cur.endsWith(s));
    if (cur === '' || oldSig) {
        const base = oldSig ? cur.slice(0, cur.length - oldSig.length) : '';
        editor.innerHTML = base + mailSigHtml(key);
    }
}

function closeMailDrawer() {
    const drawer  = document.getElementById('drawer');
    const overlay = document.getElementById('overlay');
    if (drawer)  { drawer.classList.remove('active'); setTimeout(() => { drawer.innerHTML = ''; }, 300); }
    if (overlay) overlay.classList.remove('active');
}

// Собирает FormData композера (общая для отправки и черновика)
function _composeFormData() {
    const fd = new FormData();
    fd.append('to',      document.getElementById('compose-to')?.value.trim() ?? '');
    fd.append('cc',      document.getElementById('compose-cc')?.value.trim() ?? '');
    fd.append('bcc',     document.getElementById('compose-bcc')?.value.trim() ?? '');
    fd.append('subject', document.getElementById('compose-subject')?.value.trim() ?? '');
    fd.append('body',    document.getElementById('compose-editor')?.innerHTML ?? '');
    for (const f of _composeFiles) fd.append('attachments[]', f, f.name);
    if (_composeCtx.draftUid)   { fd.append('draft_uid', _composeCtx.draftUid); fd.append('draft_folder', _composeCtx.draftFolder); }
    if (_composeCtx.forwardUid) { fd.append('forward_uid', _composeCtx.forwardUid); fd.append('forward_folder', _composeCtx.forwardFolder); }
    return fd;
}

async function sendMail() {
    const fromKey = document.getElementById('compose-from')?.value;
    const to      = document.getElementById('compose-to')?.value.trim();
    const subject = document.getElementById('compose-subject')?.value.trim();

    if (!to || !subject) {
        showToast('Заполните получателя и тему', 'warning');
        return;
    }

    const btn = document.getElementById('compose-send-btn');
    if (btn) { btn.disabled = true; btn.innerHTML = 'Отправка…'; }

    const res = await fetch(`api/mail.php?action=send&account=${fromKey}`, {
        method: 'POST',
        body:   _composeFormData(),
    }).then(r => r.json()).catch(() => null);

    if (res?.ok) {
        const cc = document.getElementById('compose-cc')?.value ?? '';
        [...to.split(','), ...cc.split(',')].forEach(e => saveContactEmail(e.trim()));
        showToast('Письмо отправлено', 'success');
        closeMailDrawer();
        // Если открыты Черновики — письмо оттуда исчезло, обновляем
        if (_composeCtx.draftUid) { try { localStorage.removeItem(mailCacheKey()); } catch {}; await loadMailMessages(); }
    } else {
        showToast(res?.error ?? 'Ошибка отправки', 'error');
        if (btn) { btn.disabled = false; btn.innerHTML = '<i data-lucide="send" style="width:14px;height:14px"></i> Отправить'; lucide.createIcons(); }
    }
}

async function saveMailDraft() {
    const fromKey = document.getElementById('compose-from')?.value;
    const btn = document.getElementById('compose-draft-btn');
    if (btn) { btn.disabled = true; btn.textContent = 'Сохранение…'; }

    const res = await fetch(`api/mail.php?action=save_draft&account=${fromKey}`, {
        method: 'POST',
        body:   _composeFormData(),
    }).then(r => r.json()).catch(() => null);

    if (res?.ok) {
        showToast('Черновик сохранён', 'success');
        closeMailDrawer();
        try { localStorage.removeItem(mailCacheKey()); } catch {}
        await loadMailFolders();
        await loadMailMessages();
    } else {
        showToast(res?.error ?? 'Ошибка сохранения черновика', 'error');
        if (btn) { btn.disabled = false; btn.innerHTML = '<i data-lucide="file-edit" style="width:14px;height:14px"></i> Черновик'; lucide.createIcons(); }
    }
}

// ── История адресов ────────────────────────────────────

function getContactEmails() {
    try {
        return new Set(JSON.parse(localStorage.getItem('mail_contacts') ?? '[]'));
    } catch { return new Set(); }
}

function saveContactEmail(email) {
    if (!email || !email.includes('@')) return;
    const emails = getContactEmails();
    emails.add(email.trim().toLowerCase());
    try { localStorage.setItem('mail_contacts', JSON.stringify([...emails])); } catch {}
}

// ── Polling для уведомлений ────────────────────────────

function startMailPolling() {
    if (mailCheckInterval) clearInterval(mailCheckInterval);
    checkNewMail();
    mailCheckInterval = setInterval(checkNewMail, 5 * 60 * 1000);
}

async function checkNewMail() {
    const data = await mailApi('check_new');
    if (!data || !data.count) return;

    // Сохраняем в localStorage для колокольчика
    try {
        const pending = JSON.parse(localStorage.getItem('byb_mail_notifs') || '[]');
        (data.new || []).forEach(m => {
            const key = `mail_${m.uid || m.subject}`;
            if (!pending.find(p => p.key === key)) {
                pending.push({ key, from: m.from, subject: m.subject, label: m.label, ts: Date.now() });
            }
        });
        localStorage.setItem('byb_mail_notifs', JSON.stringify(pending.slice(-30)));
        if (typeof loadNotifications === 'function') loadNotifications();
    } catch {}

    // Push через Service Worker
    if ('serviceWorker' in navigator && navigator.serviceWorker.controller) {
        (data.new || []).forEach(m => {
            navigator.serviceWorker.controller.postMessage({
                type: 'SHOW_NOTIFICATION',
                title: `Новое письмо — ${m.label || 'Почта'}`,
                body:  `${extractName(m.from)}: ${m.subject}`,
                url:   '?page=mail'
            });
        });
    } else if ('Notification' in window && Notification.permission === 'granted') {
        data.new.forEach(m => {
            new Notification(`Новое письмо — ${m.label}`, {
                body: `${extractName(m.from)}: ${m.subject}`,
                icon: 'assets/img/icon-192.png',
            });
        });
    } else if ('Notification' in window && Notification.permission === 'default') {
        Notification.requestPermission();
    }

    showToast(`${data.count} новое письмо`, 'warning');
}

// ── Утилиты ────────────────────────────────────────────

async function mailApi(query) {
    try {
        const res = await fetch(`api/mail.php?action=${query}`);
        if (res.status === 401) { location.href = 'login.php'; return null; }
        return await res.json();
    } catch (e) {
        return null;
    }
}

function extractName(from) {
    if (!from) return '';
    const m = from.match(/^(.+?)\s*<.+>$/);
    return m ? m[1].trim() : from;
}

function formatMailDate(dateStr) {
    if (!dateStr) return '';
    const d = new Date(dateStr);
    if (isNaN(d)) return dateStr;
    const now  = new Date();
    const diff = now - d;
    if (diff < 24 * 3600 * 1000 && d.getDate() === now.getDate()) {
        return d.toLocaleTimeString('ru', { hour: '2-digit', minute: '2-digit' });
    }
    if (diff < 7 * 24 * 3600 * 1000) {
        return d.toLocaleDateString('ru', { weekday: 'short' });
    }
    return d.toLocaleDateString('ru', { day: 'numeric', month: 'short' });
}

function formatMailDateFull(dateStr) {
    if (!dateStr) return '';
    const d = new Date(dateStr);
    if (isNaN(d)) return dateStr;
    return d.toLocaleString('ru', { day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

function formatSize(bytes) {
    if (!bytes) return '';
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return Math.round(bytes / 1024) + ' KB';
    return (bytes / 1024 / 1024).toFixed(1) + ' MB';
}

function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function escAttr(s) {
    return String(s).replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

function stripHtml(html) {
    if (!html) return '';
    return html.replace(/<[^>]+>/g, '').replace(/&nbsp;/g, ' ').replace(/&amp;/g, '&');
}

function closeMobileMailView() {
    const panel = document.getElementById('mail-view-panel');
    if (panel) panel.classList.remove('mobile-open');
    document.querySelectorAll('.mail-message-item').forEach(el => el.classList.remove('selected'));
    mailState.selectedUid = null;
}

function toggleMobileSidebar() {
    const sb = document.getElementById('mail-sidebar');
    const ov = document.getElementById('mail-sidebar-overlay');
    if (!sb) return;
    const open = sb.classList.contains('mobile-open');
    if (open) { closeMobileSidebar(); } else { sb.classList.add('mobile-open'); if (ov) ov.classList.add('active'); }
}

function closeMobileSidebar() {
    const sb = document.getElementById('mail-sidebar');
    const ov = document.getElementById('mail-sidebar-overlay');
    if (sb) sb.classList.remove('mobile-open');
    if (ov) ov.classList.remove('active');
}

// ── Push-уведомления при новых письмах ────────────────────────────────────

let _lastMailUids = null;

function checkNewMailForPush(messages) {
    if (!messages || !messages.length) return;
    const uids = messages.map(m => m.uid).filter(Boolean).sort((a,b) => b - a);
    if (_lastMailUids === null) { _lastMailUids = uids; return; } // первый запрос — запоминаем

    const newUids = uids.filter(u => !_lastMailUids.includes(u));
    _lastMailUids = uids;

    if (!newUids.length) return;

    const newMsgs = messages.filter(m => newUids.includes(m.uid));
    newMsgs.forEach(m => {
        const title = 'Новое письмо';
        const body  = (m.from || '') + (m.subject ? ': ' + m.subject : '');
        // Push через SW если подписка есть
        if ('serviceWorker' in navigator && navigator.serviceWorker.controller) {
            navigator.serviceWorker.controller.postMessage({
                type: 'SHOW_NOTIFICATION', title, body, url: '?page=mail'
            });
        }
        // Добавляем в колокольчик через localStorage
        try {
            const pending = JSON.parse(localStorage.getItem('byb_mail_notifs') || '[]');
            pending.push({ uid: m.uid, from: m.from, subject: m.subject, ts: Date.now() });
            localStorage.setItem('byb_mail_notifs', JSON.stringify(pending.slice(-20)));
        } catch {}
        if (typeof loadNotifications === 'function') loadNotifications();
    });
}
