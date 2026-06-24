// ===== REMINDERS.JS =====
// Общий виджет напоминаний для любой сущности

async function renderReminderSection(entityType, entityId) {
    let reminders = [];
    try {
        reminders = await fetch(`api/reminders.php?entity_type=${entityType}&entity_id=${entityId}`)
            .then(r => r.json());
    } catch(e) { reminders = []; }

    const listHtml = reminders.length > 0
        ? reminders.map(r => {
            const dt = new Date(r.remind_at);
            const dtStr = dt.toLocaleString('ru-RU', {day:'2-digit',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit'});
            const isPast = dt < new Date() && !r.is_done;
            const isDone = parseInt(r.is_done);
            return `
            <div class="reminder-item ${isDone?'reminder-done':''} ${isPast&&!isDone?'reminder-overdue':''}" id="rem-${r.id}">
                <i data-lucide="${isDone?'check-circle':'bell'}" style="width:13px;height:13px;flex-shrink:0;color:${isDone?'var(--success)':isPast?'var(--danger)':'var(--accent)'}"></i>
                <div style="flex:1;min-width:0">
                    <div style="font-size:13px;color:${isDone?'var(--text-dim)':'var(--text)'};text-decoration:${isDone?'line-through':''}">${r.text}</div>
                    <div style="font-size:11px;color:${isPast&&!isDone?'var(--danger)':'var(--text-muted)'}">${dtStr}</div>
                </div>
                <div style="display:flex;gap:4px;flex-shrink:0">
                    ${!isDone ? `<button class="btn-icon" onclick="toggleReminder(${r.id},'${entityType}',${entityId})" title="Выполнено"><i data-lucide="check" style="width:12px;height:12px"></i></button>` : `<button class="btn-icon" onclick="toggleReminder(${r.id},'${entityType}',${entityId})" title="Вернуть"><i data-lucide="rotate-ccw" style="width:12px;height:12px"></i></button>`}
                    <button class="btn-icon btn-icon-danger" onclick="deleteReminder(${r.id},'${entityType}',${entityId})" title="Удалить"><i data-lucide="trash-2" style="width:12px;height:12px"></i></button>
                </div>
            </div>`;
        }).join('')
        : '<div style="font-size:12px;color:var(--text-dim);padding:4px 0">Нет напоминаний</div>';

    return `
        <div class="reminder-section" id="rem-section-${entityType}-${entityId}">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;margin-top:20px;padding-top:16px;border-top:1px solid var(--border)">
                <span style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.8px;color:var(--text-muted);display:flex;align-items:center;gap:6px">
                    <i data-lucide="bell" style="width:13px;height:13px"></i> Напоминания
                </span>
                <button class="btn btn-ghost" style="padding:4px 10px;font-size:11px" onclick="openAddReminder('${entityType}',${entityId})">
                    <i data-lucide="plus" style="width:11px;height:11px"></i> Добавить
                </button>
            </div>
            <div class="reminder-list" id="rem-list-${entityType}-${entityId}">${listHtml}</div>
        </div>`;
}

function openAddReminder(entityType, entityId) {
    const now = new Date();
    now.setMinutes(0, 0, 0);
    now.setHours(now.getHours() + 1);
    const defaultDt = now.toISOString().slice(0, 16);

    // Inline форма под кнопкой «Добавить»
    const section = document.getElementById(`rem-section-${entityType}-${entityId}`);
    if (!section) return;
    const existing = section.querySelector('.reminder-add-form');
    if (existing) { existing.remove(); return; }

    const form = document.createElement('div');
    form.className = 'reminder-add-form';
    form.style.cssText = 'background:rgba(255,255,255,.03);border:1px solid var(--border);border-radius:8px;padding:12px;margin-top:8px';
    form.innerHTML = `
        <div class="form-group" style="margin-bottom:8px">
            <input type="text" class="form-control" id="rem-new-text-${entityType}-${entityId}" placeholder="Текст напоминания">
        </div>
        <div class="form-group" style="margin-bottom:8px">
            <input type="datetime-local" class="form-control" id="rem-new-dt-${entityType}-${entityId}" value="${defaultDt}">
        </div>
        <div style="display:flex;gap:8px">
            <button class="btn btn-primary" style="flex:1;font-size:12px" onclick="saveReminder('${entityType}',${entityId})">Сохранить</button>
            <button class="btn btn-ghost" style="font-size:12px" onclick="this.closest('.reminder-add-form').remove()">Отмена</button>
        </div>`;
    section.appendChild(form);
    if (window.lucide) lucide.createIcons();
}

async function saveReminder(entityType, entityId) {
    const text = document.getElementById(`rem-new-text-${entityType}-${entityId}`)?.value.trim();
    const dt = document.getElementById(`rem-new-dt-${entityType}-${entityId}`)?.value;
    if (!text) { showToast('Введите текст', 'error'); return; }
    if (!dt) { showToast('Укажите дату и время', 'error'); return; }

    await fetch('api/reminders.php', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ entity_type: entityType, entity_id: entityId, text, remind_at: dt })
    });

    // Перерисовать секцию
    const section = document.getElementById(`rem-section-${entityType}-${entityId}`);
    if (section) {
        const newHtml = await renderReminderSection(entityType, entityId);
        const tmp = document.createElement('div');
        tmp.innerHTML = newHtml;
        section.replaceWith(tmp.firstElementChild);
        if (window.lucide) lucide.createIcons();
    }
    if (typeof loadNotifications === 'function') loadNotifications();
    showToast('Напоминание добавлено');
}

async function toggleReminder(id, entityType, entityId) {
    await fetch(`api/reminders.php?id=${id}`, {
        method: 'PUT',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ is_done: 1 }) // API toggles
    });
    const section = document.getElementById(`rem-section-${entityType}-${entityId}`);
    if (section) {
        const newHtml = await renderReminderSection(entityType, entityId);
        const tmp = document.createElement('div');
        tmp.innerHTML = newHtml;
        section.replaceWith(tmp.firstElementChild);
        if (window.lucide) lucide.createIcons();
    }
    if (typeof loadNotifications === 'function') loadNotifications();
}

async function deleteReminder(id, entityType, entityId) {
    await fetch(`api/reminders.php?id=${id}`, { method: 'DELETE' });
    const section = document.getElementById(`rem-section-${entityType}-${entityId}`);
    if (section) {
        const newHtml = await renderReminderSection(entityType, entityId);
        const tmp = document.createElement('div');
        tmp.innerHTML = newHtml;
        section.replaceWith(tmp.firstElementChild);
        if (window.lucide) lucide.createIcons();
    }
    if (typeof loadNotifications === 'function') loadNotifications();
}
