// ── Notes tile ───────────────────────────────────────────────────────────────

async function loadNotesTile() {
    try {
        const res = await fetch('api/notes.php');
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const notes = await res.json();
        renderNotesTile(notes);
    } catch (e) {
        const body = document.getElementById('notes-tile-body');
        if (body) body.innerHTML = '<div style="padding:16px;color:var(--danger);font-size:13px;">Ошибка загрузки заметок</div>';
    }
}

function renderNotesTile(notes) {
    const body = document.getElementById('notes-tile-body');
    if (!body) return;

    const countEl = document.getElementById('notes-tile-count');
    if (countEl) countEl.textContent = notes.length;

    const today    = new Date(); today.setHours(0,0,0,0);
    const tomorrow = new Date(today); tomorrow.setDate(tomorrow.getDate()+1);

    const entityLabels = { shipment:'Поставка', warehouse:'Склад', expense:'Расход', bank_op:'Операция', sale:'Продажа' };
    let html = '';

    notes.forEach(note => {
        let deadlineBadge = '';
        if (note.deadline) {
            const dl = new Date(note.deadline + 'T00:00:00');
            let cls = 'normal', label = _noteFormatDate(note.deadline);
            if (dl < today)      { cls = 'overdue'; label = 'Просрочено: ' + label; }
            else if (dl.getTime() === today.getTime() || dl.getTime() === tomorrow.getTime()) cls = 'soon';
            deadlineBadge = `<span class="note-deadline ${cls}"><i data-lucide="calendar" style="width:10px;height:10px;vertical-align:middle;margin-right:2px;"></i>${label}</span>`;
        }

        const entityBadge = note.entity_type
            ? `<span style="font-size:10px;padding:1px 6px;border-radius:4px;background:var(--accent-subtle);color:var(--accent);flex-shrink:0;">${entityLabels[note.entity_type]||note.entity_type} #${note.entity_id}</span>`
            : '';

        html += `<div class="note-item" data-id="${note.id}">
            <i data-lucide="file-text" style="width:14px;height:14px;color:var(--text-muted);flex-shrink:0;margin-top:2px;"></i>
            <span class="note-text" onclick="openEditNote(${note.id},${JSON.stringify(note.text)},${JSON.stringify(note.deadline||'')},${JSON.stringify(note.entity_type||null)},${note.entity_id||'null'})">${escapeHtml(note.text)}</span>
            ${entityBadge}
            ${deadlineBadge}
            <button onclick="deleteNote(${note.id},${JSON.stringify(note.entity_type||null)},${note.entity_id||'null'})" style="background:none;border:none;cursor:pointer;color:var(--text-dim);padding:2px;margin-left:2px;display:flex;align-items:center;border-radius:4px;transition:color 0.15s,background 0.15s;" onmouseenter="this.style.color='var(--danger)';this.style.background='var(--danger-subtle)'" onmouseleave="this.style.color='var(--text-dim)';this.style.background=''" title="Удалить">
                <i data-lucide="trash-2" style="width:13px;height:13px;"></i>
            </button>
        </div>`;
    });

    html += `<div class="note-add-form" id="note-add-form">
        <textarea id="note-add-text" placeholder="Текст заметки…" class="form-control" style="min-height:72px;resize:vertical;margin-bottom:8px;" rows="3"></textarea>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
            <input type="date" id="note-add-deadline" class="form-control" style="flex:1;min-width:120px;">
            <button onclick="saveNote()" class="btn btn-primary btn-sm" style="white-space:nowrap;">Сохранить</button>
            <button onclick="closeAddNoteInline()" class="btn btn-ghost btn-sm">Отмена</button>
        </div>
    </div>`;

    html += `<div class="notes-add-btn" id="notes-add-btn" onclick="openAddNoteInline()">
        <i data-lucide="plus" style="width:13px;height:13px;"></i> Добавить заметку
    </div>`;

    body.innerHTML = html;
    body.classList.add('open');

    const chevron = document.getElementById('notes-tile-chevron');
    if (chevron) chevron.style.transform = 'rotate(180deg)';
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

function openAddNoteInline() {
    const form = document.getElementById('note-add-form');
    const btn  = document.getElementById('notes-add-btn');
    if (form) { form.classList.add('open'); form.style.display = 'block'; }
    if (btn)  btn.style.display = 'none';
    setTimeout(() => document.getElementById('note-add-text')?.focus(), 30);
}

function closeAddNoteInline() {
    const form = document.getElementById('note-add-form');
    const btn  = document.getElementById('notes-add-btn');
    if (form) { form.classList.remove('open'); form.style.display = 'none'; }
    if (btn)  btn.style.display = '';
}

async function saveNote(entityType, entityId) {
    const text     = (document.getElementById('note-add-text')?.value || '').trim();
    const deadline = document.getElementById('note-add-deadline')?.value || null;
    if (!text) { showToast('Введите текст заметки', 'error'); return; }
    try {
        const res = await fetch('api/notes.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ text, deadline: deadline||null, entity_type: entityType||null, entity_id: entityId ? parseInt(entityId) : null }),
        });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        if (entityType && entityId) {
            await loadEntityNotes(entityType, entityId);
        } else {
            await loadNotesTile();
        }
    } catch (e) {
        showToast('Ошибка при сохранении: ' + e.message, 'error');
    }
}

async function deleteNote(id, entityType, entityId) {
    confirmAction('Удалить заметку?', async () => {
        try {
            const res = await fetch('api/notes.php?id=' + id, { method: 'DELETE' });
            if (!res.ok) throw new Error('HTTP ' + res.status);
            if (entityType && entityId) {
                await loadEntityNotes(entityType, entityId);
            } else {
                await loadNotesTile();
            }
        } catch (e) {
            showToast('Ошибка при удалении: ' + e.message, 'error');
        }
    });
}

function openEditNote(id, currentText, currentDeadline, entityType, entityId) {
    openModal(`
        <div class="modal-header">
            <span class="modal-title">Редактировать заметку</span>
            <button class="btn-close" onclick="closeModal()"><i data-lucide="x" style="width:15px;height:15px"></i></button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label class="form-label">Текст</label>
                <textarea id="note-edit-text" class="form-control" style="min-height:96px;resize:vertical;" rows="4">${escapeHtml(currentText)}</textarea>
            </div>
            <div class="form-group" style="margin-bottom:0">
                <label class="form-label">Дедлайн</label>
                <input type="date" id="note-edit-deadline" class="form-control" value="${escapeHtml(currentDeadline||'')}">
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" onclick="closeModal()">Отмена</button>
            <button class="btn btn-primary" onclick="updateNote(${id},${JSON.stringify(entityType||null)},${entityId?entityId:'null'})">Сохранить</button>
        </div>
    `);
    setTimeout(() => document.getElementById('note-edit-text')?.focus(), 50);
}

async function updateNote(id, entityType, entityId) {
    const text     = (document.getElementById('note-edit-text')?.value || '').trim();
    const deadline = document.getElementById('note-edit-deadline')?.value || null;
    if (!text) { showToast('Введите текст заметки', 'error'); return; }
    try {
        const res = await fetch('api/notes.php', {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id, text, deadline: deadline||null }),
        });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        closeModal();
        if (entityType && entityId) {
            await loadEntityNotes(entityType, entityId);
        } else {
            await loadNotesTile();
        }
        showToast('Заметка обновлена');
    } catch (e) {
        showToast('Ошибка при обновлении: ' + e.message, 'error');
    }
}

// ── Entity notes ─────────────────────────────────────────────────────────────

async function loadEntityNotes(entityType, entityId) {
    const container = document.getElementById(`entity-notes-${entityType}-${entityId}`);
    if (!container) return;
    try {
        const res = await fetch(`api/notes.php?entity_type=${entityType}&entity_id=${entityId}`);
        if (!res.ok) throw new Error('HTTP ' + res.status);
        renderEntityNotes(await res.json(), entityType, entityId, container);
    } catch (e) {
        container.innerHTML = `<p style="color:var(--danger);font-size:12px;">Ошибка загрузки заметок</p>`;
    }
}

function renderEntityNotes(notes, entityType, entityId, container) {
    const today    = new Date(); today.setHours(0,0,0,0);
    const tomorrow = new Date(today); tomorrow.setDate(tomorrow.getDate()+1);

    let html = `<div style="margin-top:16px;border-top:1px solid var(--border);padding-top:14px;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
            <span style="font-size:12px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.6px;">Заметки</span>
            <button class="btn btn-sm btn-ghost" onclick="openEntityNoteForm('${entityType}',${entityId})">
                <i data-lucide="plus" style="width:12px;height:12px;"></i> Добавить
            </button>
        </div>`;

    if (notes.length === 0) {
        html += `<p style="font-size:12px;color:var(--text-dim);margin:0;">Заметок нет</p>`;
    } else {
        notes.forEach(note => {
            let deadlineBadge = '';
            if (note.deadline) {
                const dl = new Date(note.deadline + 'T00:00:00');
                let cls = 'normal', label = _noteFormatDate(note.deadline);
                if (dl < today) { cls = 'overdue'; label = 'Просрочено: ' + label; }
                else if (dl.getTime() === today.getTime() || dl.getTime() === tomorrow.getTime()) cls = 'soon';
                deadlineBadge = `<span class="note-deadline ${cls}" style="font-size:10px;">${label}</span>`;
            }
            html += `<div style="display:flex;align-items:flex-start;gap:8px;padding:8px 0;border-bottom:1px solid var(--border);">
                <i data-lucide="file-text" style="width:13px;height:13px;color:var(--text-muted);flex-shrink:0;margin-top:2px;"></i>
                <div style="flex:1;min-width:0;">
                    <span style="font-size:13px;color:var(--text);cursor:pointer;line-height:1.4;" onclick="openEditNote(${note.id},${JSON.stringify(note.text)},${JSON.stringify(note.deadline||'')},'${entityType}',${entityId})">${escapeHtml(note.text)}</span>
                    ${deadlineBadge ? '<br>' + deadlineBadge : ''}
                </div>
                <button onclick="deleteNote(${note.id},'${entityType}',${entityId})" style="background:none;border:none;cursor:pointer;color:var(--text-dim);padding:2px;flex-shrink:0;border-radius:4px;transition:color 0.15s;" onmouseenter="this.style.color='var(--danger)'" onmouseleave="this.style.color='var(--text-dim)'" title="Удалить">
                    <i data-lucide="trash-2" style="width:13px;height:13px;"></i>
                </button>
            </div>`;
        });
    }

    html += `<div id="entity-note-form-${entityType}-${entityId}" style="display:none;margin-top:10px;">
        <textarea id="entity-note-text-${entityType}-${entityId}" class="form-control" placeholder="Текст заметки…" style="min-height:60px;resize:vertical;margin-bottom:6px;" rows="2"></textarea>
        <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
            <input type="date" id="entity-note-deadline-${entityType}-${entityId}" class="form-control" style="flex:1;min-width:110px;">
            <button onclick="submitEntityNote('${entityType}',${entityId})" class="btn btn-primary btn-sm" style="white-space:nowrap;">Сохранить</button>
            <button onclick="closeEntityNoteForm('${entityType}',${entityId})" class="btn btn-ghost btn-sm">Отмена</button>
        </div>
    </div></div>`;

    container.innerHTML = html;
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

function openEntityNoteForm(entityType, entityId) {
    const form = document.getElementById(`entity-note-form-${entityType}-${entityId}`);
    if (form) form.style.display = 'block';
    setTimeout(() => document.getElementById(`entity-note-text-${entityType}-${entityId}`)?.focus(), 30);
}

function closeEntityNoteForm(entityType, entityId) {
    const form = document.getElementById(`entity-note-form-${entityType}-${entityId}`);
    if (form) form.style.display = 'none';
}

async function submitEntityNote(entityType, entityId) {
    const text     = (document.getElementById(`entity-note-text-${entityType}-${entityId}`)?.value || '').trim();
    const deadline = document.getElementById(`entity-note-deadline-${entityType}-${entityId}`)?.value || null;
    if (!text) { showToast('Введите текст заметки', 'error'); return; }
    try {
        const res = await fetch('api/notes.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ text, deadline: deadline||null, entity_type: entityType, entity_id: parseInt(entityId) }),
        });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        await loadEntityNotes(entityType, entityId);
    } catch (e) {
        showToast('Ошибка при сохранении: ' + e.message, 'error');
    }
}

// ── Helpers ──────────────────────────────────────────────────────────────────

function _noteFormatDate(dateStr) {
    if (!dateStr) return '';
    const [y,m,d] = dateStr.split('-');
    return `${d}.${m}.${y}`;
}

function escapeHtml(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

function toggleNotesTile() {
    const body    = document.getElementById('notes-tile-body');
    const chevron = document.getElementById('notes-tile-chevron');
    if (!body) return;
    body.classList.toggle('open');
    if (chevron) chevron.style.transform = body.classList.contains('open') ? 'rotate(180deg)' : '';
}

document.addEventListener('DOMContentLoaded', () => { loadNotesTile(); });
