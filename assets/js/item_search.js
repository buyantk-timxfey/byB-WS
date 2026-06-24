// ===== ITEM SEARCH — живой поиск по товарам в селекте =====
// Используется в продажах и поставках

function createItemSearch(options, onSelect, placeholder = 'Начните вводить название...') {
    const id = 'is_' + Math.random().toString(36).slice(2, 8);
    const optsHtml = options.map(o => {
        const label = (o.label || '').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        const meta  = (o.meta  || '').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        return `
            <div class="item-search-option"
                data-value="${o.value}"
                data-label="${label}"
                data-meta="${meta}"
                style="padding:10px 14px;cursor:pointer;font-size:13px;
                border-bottom:1px solid var(--border);transition:.15s"
                onmousedown="cancelHideItemDropdown('${id}'); selectItemSearch('${id}', this)"
                onmouseover="this.style.background='var(--bg)'"
                onmouseout="this.style.background=''">
                <div style="font-weight:500;color:var(--text)">${o.label || ''}</div>
                ${o.meta ? `<div style="font-size:11px;color:var(--text-muted);margin-top:2px">${o.meta}</div>` : ''}
            </div>`;
    }).join('');

    return `
        <div class="item-search-wrap" style="position:relative">
            <input type="text" class="form-control item-search-input" id="${id}_input"
                placeholder="${placeholder}" autocomplete="off"
                oninput="filterItemSearch('${id}')"
                onfocus="showItemDropdown('${id}')"
                onblur="hideItemDropdownDelayed('${id}')">
            <input type="hidden" class="item-search-value" id="${id}_value">
            <div class="item-search-dropdown" id="${id}_dropdown" style="display:none;
                position:absolute;top:100%;left:0;right:0;z-index:200;
                background:var(--card-bg);border:1px solid var(--border);
                border-radius:var(--radius);max-height:240px;overflow-y:auto;
                box-shadow:0 8px 24px rgba(0,0,0,0.3);margin-top:4px">
                ${optsHtml}
            </div>
        </div>
    `;
}

function filterItemSearch(id) {
    const input = document.getElementById(id + '_input');
    const dropdown = document.getElementById(id + '_dropdown');
    const q = input.value.toLowerCase();
    const opts = dropdown.querySelectorAll('.item-search-option');
    let visible = 0;
    opts.forEach(opt => {
        const match = opt.dataset.label.toLowerCase().includes(q);
        opt.style.display = match ? '' : 'none';
        if (match) visible++;
    });
    dropdown.style.display = visible > 0 ? '' : 'none';
    document.getElementById(id + '_value').value = '';
}

function showItemDropdown(id) {
    const dropdown = document.getElementById(id + '_dropdown');
    const opts = dropdown.querySelectorAll('.item-search-option');
    opts.forEach(o => o.style.display = '');
    dropdown.style.display = '';
}

function hideItemDropdown(id) {
    const dropdown = document.getElementById(id + '_dropdown');
    if (dropdown) dropdown.style.display = 'none';
}

function selectItemSearch(id, el) {
    const label = el.dataset.label || '';
    const value = el.dataset.value || '';
    document.getElementById(id + '_input').value = label;
    document.getElementById(id + '_value').value = value;
    document.getElementById(id + '_dropdown').style.display = 'none';
    if (window.lucide) lucide.createIcons();
}

function getItemSearchValue(container) {
    const hidden = container.querySelector('.item-search-value');
    return hidden ? hidden.value : '';
}

let _itemSearchBlurTimer = {};
function hideItemDropdownDelayed(id) {
    _itemSearchBlurTimer[id] = setTimeout(() => hideItemDropdown(id), 300);
}
function cancelHideItemDropdown(id) {
    clearTimeout(_itemSearchBlurTimer[id]);
}