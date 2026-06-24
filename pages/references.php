<?php
$counterparties = $pdo->query("SELECT * FROM counterparties ORDER BY name")->fetchAll();
$carriers       = $pdo->query("SELECT * FROM carriers ORDER BY name")->fetchAll();

// Группировка контрагентов: поставщики → оба → покупатели
$groups = ['Поставщик' => [], 'Оба' => [], 'Покупатель' => []];
foreach ($counterparties as $cp) {
    $key = isset($groups[$cp['type']]) ? $cp['type'] : 'Поставщик';
    $groups[$key][] = $cp;
}
$groupLabels = ['Поставщик' => 'Поставщики', 'Оба' => 'Поставщики и покупатели', 'Покупатель' => 'Покупатели'];
?>

<div class="page-header">
    <h1 class="page-title">Справочник</h1>
</div>
<script>
window._gsPageActions = ''
    + '<button class="btn btn-ghost" onclick="openSecurityModal()" title="PIN и устройства"><i data-lucide="shield" style="width:13px;height:13px"></i> Безопасность</button>'
    + '<button class="btn btn-ghost" onclick="openDeployModal()" title="Загрузить патч на сервер"><i data-lucide="upload-cloud" style="width:13px;height:13px"></i> Деплой</button>'
    + '<button class="btn btn-ghost" onclick="openAddCarrier()"><i data-lucide="truck" style="width:13px;height:13px"></i> ТК</button>'
    + '<button class="btn btn-primary" onclick="openAddCounterparty()">+ Контрагент</button>';
</script>

<!-- ── Контрагенты ──────────────────────────────────────────────────────────── -->
<?php if (empty($counterparties)): ?>
    <div class="empty-state">
        <p>Контрагентов пока нет</p>
        <button class="btn btn-primary" style="margin-top:16px" onclick="openAddCounterparty()">+ Добавить первого</button>
    </div>
<?php else: ?>
    <?php foreach ($groups as $type => $items):
        if (empty($items)) continue; ?>
    <div style="display:flex;align-items:center;gap:8px;margin:20px 0 10px">
        <span style="font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.8px;color:var(--text-muted)"><?= $groupLabels[$type] ?></span>
        <span style="font-size:11px;color:var(--text-muted);opacity:.6"><?= count($items) ?></span>
    </div>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th style="width:80px">Тип</th>
                    <th>Название</th>
                    <th>Реквизиты</th>
                    <th></th>
                </tr>
            </thead>
            <tbody class="m-cards m-cards-refcp">
                <?php foreach ($items as $cp): ?>
                <tr onclick="openCounterparty(<?= $cp['id'] ?>)">
                    <td>
                        <span style="display:inline-block;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600;background:rgba(201,169,110,0.1);color:var(--accent);white-space:nowrap"><?= htmlspecialchars($cp['company_type']) ?></span>
                    </td>
                    <td style="font-weight:500"><?= htmlspecialchars($cp['name']) ?></td>
                    <td>
                        <span style="color:var(--text-muted);font-size:12px">
                            <?= $cp['requisites'] ? htmlspecialchars(mb_substr($cp['requisites'], 0, 50)) . (mb_strlen($cp['requisites']) > 50 ? '…' : '') : '—' ?>
                        </span>
                    </td>
                    <td class="row-actions">
                        <button class="btn-icon" onclick="event.stopPropagation(); openEditCounterparty(<?= $cp['id'] ?>)" title="Изменить">
                            <i data-lucide="pencil" style="width:14px;height:14px"></i>
                        </button>
                        <button class="btn-icon btn-icon-danger" onclick="event.stopPropagation(); deleteCounterparty(<?= $cp['id'] ?>)" title="Удалить">
                            <i data-lucide="trash-2" style="width:14px;height:14px"></i>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endforeach; ?>
<?php endif; ?>

<!-- ── Транспортные компании ───────────────────────────────────────────────── -->
<div style="display:flex;align-items:center;gap:8px;margin:28px 0 10px">
    <span style="font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.8px;color:var(--text-muted)">Транспортные компании</span>
    <span style="font-size:11px;color:var(--text-muted);opacity:.6"><?= count($carriers) ?></span>
</div>
<?php if (empty($carriers)): ?>
    <div class="empty-state" style="padding:24px">
        <p>Транспортных компаний пока нет</p>
        <button class="btn btn-primary" style="margin-top:16px" onclick="openAddCarrier()">+ Добавить первую</button>
    </div>
<?php else: ?>
<div class="table-wrapper">
    <table>
        <thead>
            <tr>
                <th>Название</th>
                <th></th>
            </tr>
        </thead>
        <tbody class="m-cards m-cards-refcar">
            <?php foreach ($carriers as $cr): ?>
            <tr onclick="openCarrier(<?= $cr['id'] ?>)">
                <td style="font-weight:500"><?= htmlspecialchars($cr['name']) ?></td>
                <td class="row-actions">
                    <button class="btn-icon" onclick="event.stopPropagation(); openEditCarrier(<?= $cr['id'] ?>)" title="Изменить">
                        <i data-lucide="pencil" style="width:14px;height:14px"></i>
                    </button>
                    <button class="btn-icon btn-icon-danger" onclick="event.stopPropagation(); deleteCarrier(<?= $cr['id'] ?>)" title="Удалить">
                        <i data-lucide="trash-2" style="width:14px;height:14px"></i>
                    </button>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<script>
function openDeployModal() {
    openModal(`
        <div class="modal-header">
            <span class="modal-title">Загрузить патч</span>
            <button class="btn-close" onclick="closeModal()"><i data-lucide="x" style="width:15px;height:15px"></i></button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label class="form-label">ZIP-архив</label>
                <input type="file" id="deploy-zip-input" accept=".zip" class="form-control" style="cursor:pointer">
            </div>
            <div class="form-group">
                <label class="form-label">Секретный ключ</label>
                <input type="password" id="deploy-key-input" class="form-control"
                    placeholder="Ключ из deploy.php" value="${localStorage.getItem('deploy_key')||''}">
                <div style="font-size:11px;color:var(--text-dim);margin-top:4px">Сохраняется в браузере</div>
            </div>
            <div id="deploy-result" style="display:none;margin-top:4px;border-radius:8px;overflow:hidden"></div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" onclick="closeModal()">Отмена</button>
            <button class="btn btn-primary" id="deploy-submit-btn" onclick="submitDeploy()">
                <i data-lucide="upload-cloud" style="width:13px;height:13px"></i> Загрузить
            </button>
        </div>
    `);
    if (window.lucide) lucide.createIcons();
}

async function submitDeploy() {
    const zip = document.getElementById('deploy-zip-input')?.files?.[0];
    const key = document.getElementById('deploy-key-input')?.value?.trim();
    const resultEl = document.getElementById('deploy-result');
    const submitBtn = document.getElementById('deploy-submit-btn');
    if (!zip) { showToast('Выберите ZIP-файл', 'error'); return; }
    if (!key) { showToast('Введите секретный ключ', 'error'); return; }
    localStorage.setItem('deploy_key', key);
    submitBtn.disabled = true;
    submitBtn.innerHTML = 'Загрузка...';
    resultEl.style.display = 'none';
    try {
        const formData = new FormData();
        formData.append('zip', zip);
        const res = await fetch('deploy/deploy.php', { method: 'POST', headers: { 'X-Deploy-Key': key }, body: formData });
        const data = await res.json();
        const isOk = data.status === 'ok', isPartial = data.status === 'partial';
        const bg = isOk ? 'rgba(76,175,80,0.08)' : isPartial ? 'rgba(255,152,0,0.08)' : 'rgba(244,67,54,0.08)';
        const bd = isOk ? 'rgba(76,175,80,0.3)' : isPartial ? 'rgba(255,152,0,0.3)' : 'rgba(244,67,54,0.3)';
        const sc = isOk ? 'var(--success)' : isPartial ? 'var(--warning)' : 'var(--danger)';
        let html = `<div style="font-size:13px;font-weight:600;color:${sc};margin-bottom:8px">${data.message}</div>`;
        if (data.deployed_files?.length) {
            html += `<div style="font-size:11px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.8px;margin-bottom:4px">Файлы (${data.deployed_files.length})</div>`;
            data.deployed_files.forEach(f => { html += `<div style="font-size:12px;font-family:monospace;color:var(--text)"><span style="color:var(--success)">+</span> ${f}</div>`; });
        }
        if (data.errors?.length) {
            html += `<div style="font-size:11px;color:var(--danger);margin:8px 0 4px">Ошибки</div>`;
            data.errors.forEach(e => { html += `<div style="font-size:12px;color:var(--danger)">✗ ${e}</div>`; });
        }
        if (data.meta) html += `<div style="margin-top:10px;padding-top:10px;border-top:1px solid var(--border);font-size:11px;color:var(--text-dim)">branch: ${data.meta.git_branch} · commit: ${data.meta.git_commit} · бэкапов: ${data.backed_up ?? 0}</div>`;
        resultEl.style.cssText = `display:block;border:1px solid ${bd};background:${bg};border-radius:8px;padding:14px 16px;margin-top:4px`;
        resultEl.innerHTML = html;
        resultEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        const cancelBtn = document.querySelector('#modal .btn-ghost');
        if (cancelBtn) cancelBtn.textContent = 'Закрыть';
    } catch (e) {
        resultEl.style.cssText = 'display:block;border:1px solid rgba(244,67,54,0.3);background:rgba(244,67,54,0.08);border-radius:8px;padding:14px 16px;margin-top:4px';
        resultEl.innerHTML = `<span style="color:var(--danger);font-size:13px">Ошибка: ${e.message}</span>`;
    } finally {
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i data-lucide="upload-cloud" style="width:13px;height:13px"></i> Загрузить ещё';
        if (window.lucide) lucide.createIcons();
    }
}

// ── Безопасность: PIN-код и запомненные устройства ──────────────────────────────
async function openSecurityModal() {
    let st = {};
    try { st = await (await fetch('api/auth_pin.php?action=status')).json(); } catch (e) {}
    const devices = st.devices || [];
    const pinSet = !!st.pinSet;

    const devHtml = devices.length ? devices.map(d => {
        const last = d.last_used_at ? formatDate(d.last_used_at) : '—';
        return `<div style="display:flex;align-items:center;justify-content:space-between;gap:8px;padding:8px 0;border-bottom:1px solid var(--border)">
            <div><div style="font-size:13px">${d.label || 'Устройство'}${d.current ? ' <span style="color:var(--accent);font-size:11px">· это устройство</span>' : ''}</div>
            <div style="font-size:11px;color:var(--text-dim)">последний вход: ${last}</div></div>
        </div>`;
    }).join('') : '<div style="font-size:12px;color:var(--text-dim);padding:8px 0">Нет запомненных устройств</div>';

    openModal(`
        <div class="modal-header">
            <span class="modal-title">Безопасность</span>
            <button class="btn-close" onclick="closeModal()"><i data-lucide="x" style="width:15px;height:15px"></i></button>
        </div>
        <div class="modal-body">
            <div class="modal-section">
                <div class="modal-section-title"><i data-lucide="lock"></i> PIN-код</div>
                <div style="font-size:12px;color:var(--text-muted);margin-bottom:12px">
                    Быстрый вход 4 цифрами. Работает только на запомненных устройствах после полного входа.
                    ${pinSet ? '<span style="color:var(--success)">PIN задан.</span>' : '<span style="color:var(--warning)">PIN не задан.</span>'}
                </div>
                <div class="form-group">
                    <label class="form-label">${pinSet ? 'Новый PIN' : 'PIN'} (4 цифры)</label>
                    <input type="password" id="sec-pin" class="form-control" inputmode="numeric" maxlength="4" autocomplete="off" placeholder="••••">
                </div>
                <div class="form-group">
                    <label class="form-label">Повторите PIN</label>
                    <input type="password" id="sec-pin2" class="form-control" inputmode="numeric" maxlength="4" autocomplete="off" placeholder="••••">
                </div>
                <div style="display:flex;gap:8px">
                    <button class="btn btn-primary" onclick="savePin()">${pinSet ? 'Сменить PIN' : 'Задать PIN'}</button>
                    ${pinSet ? '<button class="btn btn-ghost" onclick="disablePin()">Отключить PIN</button>' : ''}
                </div>
            </div>

            <div class="modal-section">
                <div class="modal-section-title"><i data-lucide="smartphone"></i> Устройства</div>
                ${devHtml}
                <div style="display:flex;gap:8px;margin-top:12px">
                    <button class="btn btn-ghost" onclick="forgetDevice()">Забыть это устройство</button>
                    <button class="btn btn-ghost" style="color:var(--danger)" onclick="forgetAllDevices()">Забыть все</button>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" onclick="closeModal()">Закрыть</button>
        </div>
    `);
    if (window.lucide) lucide.createIcons();
    ['sec-pin', 'sec-pin2'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.addEventListener('input', () => { el.value = el.value.replace(/\D/g, '').slice(0, 4); });
    });
}

async function savePin() {
    const p1 = document.getElementById('sec-pin')?.value || '';
    const p2 = document.getElementById('sec-pin2')?.value || '';
    if (!/^\d{4}$/.test(p1)) { showToast('PIN — ровно 4 цифры', 'error'); return; }
    if (p1 !== p2) { showToast('PIN не совпадает', 'error'); return; }
    const r = await (await fetch('api/auth_pin.php?action=set', {
        method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ pin: p1 })
    })).json();
    if (r.ok) { showToast('PIN сохранён'); closeModal(); }
    else showToast(r.error || 'Ошибка', 'error');
}

function disablePin() {
    confirmAction('Отключить вход по PIN?', async () => {
        const r = await (await fetch('api/auth_pin.php?action=disable', { method: 'POST' })).json();
        if (r.ok) { showToast('PIN отключён'); }
    });
}

async function forgetDevice() {
    const r = await (await fetch('api/auth_pin.php?action=forget', { method: 'POST' })).json();
    if (r.ok) { showToast('Устройство забыто'); closeModal(); }
}

function forgetAllDevices() {
    confirmAction('Забыть все устройства? На всех устройствах снова потребуется полный вход.', async () => {
        const r = await (await fetch('api/auth_pin.php?action=forget_all', { method: 'POST' })).json();
        if (r.ok) { showToast('Все устройства забыты'); closeModal(); }
    });
}
</script>
