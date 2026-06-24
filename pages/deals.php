<?php // Сделки: воронка Заявка → Поиск → Заказано → Завершено. Данные и рендер — в assets/js/deals.js ?>
<div class="page-header">
    <h1 class="page-title">Сделки</h1>
</div>

<div class="tab-toolbar">
    <div class="tab-toolbar-left">
        <input type="text" id="search-deals" class="form-control toolbar-search" placeholder="Поиск по сделкам..." oninput="filterDeals(this.value)">
    </div>
    <div class="tab-toolbar-right">
        <button class="btn btn-ghost" id="deals-show-done" onclick="toggleDoneDeals()">Показать завершённые</button>
        <button class="btn btn-primary" onclick="openAddDeal()">+ Сделка</button>
    </div>
</div>

<div class="deals-board" id="deals-board">
    <div style="padding:40px;text-align:center;color:var(--text-dim);grid-column:1/-1">
        <span class="loader-ring" style="width:16px;height:16px;border-width:2px;display:inline-block;vertical-align:middle;margin-right:8px"></span>
        Загрузка…
    </div>
</div>

<script>
setTimeout(() => { if (typeof initDealsPage === 'function') initDealsPage(); }, 0);
</script>
