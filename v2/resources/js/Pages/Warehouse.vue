<script setup lang="ts">
import { ref, computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppShell from '@/Layouts/AppShell.vue';
import Icon from '@/Components/Icon.vue';
import StatusPill from '@/Components/StatusPill.vue';
import AppModal from '@/Components/AppModal.vue';

const money = (n: number) => new Intl.NumberFormat('ru-RU').format(Math.round(n)) + ' ₽';
const STALE_DAYS = 60; // порог залежалости (в настройках)

type Batch = { ship: string; date: string; qty: number; cost: number; days: number };
type Product = { id: number; name: string; group: string; unit: string; batches: Batch[]; backorder: number };

const data = ref<Product[]>([
    { id: 1, name: 'Кабель-канал 40×40', group: 'Профиль', unit: 'шт', backorder: 0, batches: [{ ship: 'ИЛ-0044', date: '01.07.2026', qty: 200, cost: 1070, days: 0 }] },
    { id: 2, name: 'Насос Grundfos UPS 25-40', group: 'Насосы', unit: 'шт', backorder: 0, batches: [{ ship: 'ИН-0043', date: '28.06.2026', qty: 3, cost: 19300, days: 1 }] },
    { id: 3, name: 'Кабель ВВГ 3×2.5', group: 'Кабель', unit: 'м', backorder: 0, batches: [{ ship: 'ИН-0035', date: '25.03.2026', qty: 150, cost: 540, days: 96 }] },
    { id: 4, name: 'Автомат ABB SH201 C16', group: 'Автоматы', unit: 'шт', backorder: 0, batches: [{ ship: 'ИН-0041', date: '15.06.2026', qty: 40, cost: 1452, days: 14 }] },
    { id: 5, name: 'Лоток 100×50', group: 'Лотки', unit: 'шт', backorder: 0, batches: [{ ship: 'ИН-0040', date: '12.06.2026', qty: 60, cost: 1390, days: 17 }] },
    { id: 6, name: 'Светильник LED 36W', group: 'Освещение', unit: 'шт', backorder: 10, batches: [] },
]);

const qty = (p: Product) => p.batches.reduce((a, b) => a + b.qty, 0) - p.backorder;
const value = (p: Product) => p.batches.reduce((a, b) => a + b.qty * b.cost, 0) - p.backorder * 1400;
const oldest = (p: Product) => p.batches.length ? Math.max(...p.batches.map((b) => b.days)) : 0;
const isStale = (p: Product) => oldest(p) >= STALE_DAYS;
const isNeg = (p: Product) => qty(p) < 0;

const seg = ref<'all' | 'stock' | 'stale' | 'neg'>('all');
const q = ref('');

const rows = computed(() => data.value.filter((p) => {
    if (seg.value === 'stock' && qty(p) === 0) return false;
    if (seg.value === 'stale' && !isStale(p)) return false;
    if (seg.value === 'neg' && !isNeg(p)) return false;
    if (q.value && !(`${p.name} ${p.group}`.toLowerCase().includes(q.value.toLowerCase()))) return false;
    return true;
}));

// Замороженные деньги — стоимость положительных остатков; залежало — стоимость залежалых партий
const frozen = computed(() => data.value.reduce((a, p) => a + Math.max(0, value(p)), 0));
const staleMoney = computed(() => data.value.filter(isStale).reduce((a, p) => a + value(p), 0));
const posCount = computed(() => data.value.filter((p) => qty(p) > 0).length);
const negCount = computed(() => data.value.filter(isNeg).length);

const expanded = ref<number | null>(null);
const toggle = (id: number) => { expanded.value = expanded.value === id ? null : id; };

const open = ref(false);
const cur = ref<Product | null>(null);
function card(p: Product) { cur.value = p; open.value = true; }
</script>

<template>
    <Head title="Склад" />
    <AppShell>
        <div class="toolbar">
            <h1>Склад</h1>
            <div class="tb-search">
                <Icon name="search" :size="16" class="text-ink-3" />
                <input v-model="q" placeholder="Поиск по товару, группе…" />
            </div>
            <div class="seg">
                <button :class="{ on: seg === 'all' }" @click="seg = 'all'">Все</button>
                <button :class="{ on: seg === 'stock' }" @click="seg = 'stock'">Есть остаток</button>
                <button :class="{ on: seg === 'stale' }" @click="seg = 'stale'">Залежалые</button>
                <button :class="{ on: seg === 'neg' }" @click="seg = 'neg'">Под заказ</button>
            </div>
            <button class="btn-ghost pressable">Списание</button>
            <button class="btn-ghost pressable">Инвентаризация</button>
        </div>

        <!-- Сводка по складу -->
        <div class="wh-stats">
            <div class="wh-stat glass">
                <div class="wh-label">Замороженные деньги</div>
                <div class="wh-val tnum">{{ money(frozen) }}</div>
                <div class="wh-sub">стоимость остатков по себестоимости</div>
            </div>
            <div class="wh-stat glass" :class="{ 'wh-stat--warn': staleMoney > 0 }">
                <div class="wh-label">Зависло (старше {{ STALE_DAYS }} дн.)</div>
                <div class="wh-val tnum" :style="staleMoney > 0 ? { color: 'var(--warn)' } : {}">{{ money(staleMoney) }}</div>
                <div class="wh-sub">залежалый товар</div>
            </div>
            <div class="wh-stat glass">
                <div class="wh-label">Позиций на складе</div>
                <div class="wh-val tnum">{{ posCount }}</div>
                <div class="wh-sub"><span v-if="negCount" :style="{ color: 'var(--expense)' }">{{ negCount }} под заказ (минус)</span><span v-else>нет отрицательных</span></div>
            </div>
        </div>

        <div class="jcard glass">
            <div class="jscroll">
                <table class="jtable jtable--exp">
                    <thead>
                        <tr>
                            <th style="width:34px"></th>
                            <th>Товар</th><th>Группа</th><th>Ед.</th>
                            <th class="num">Остаток</th><th class="num">Стоимость</th>
                            <th class="num">Дней</th><th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="p in rows" :key="p.id">
                            <tr @click="toggle(p.id)" :class="{ 'tr-open': expanded === p.id }">
                                <td class="cell-chev">
                                    <Icon name="chevron-right" :size="16" class="chev" :class="{ 'chev-open': expanded === p.id }" />
                                </td>
                                <td>
                                    {{ p.name }}
                                    <StatusPill v-if="isStale(p)" text="Залежалый" variant="warn" class="ml-2" />
                                    <StatusPill v-if="isNeg(p)" text="Под заказ" variant="bad" class="ml-2" />
                                </td>
                                <td class="text-ink-2">{{ p.group }}</td>
                                <td class="text-ink-2">{{ p.unit }}</td>
                                <td class="num" :style="isNeg(p) ? { color: 'var(--expense)' } : {}">{{ qty(p) }}</td>
                                <td class="num">{{ money(value(p)) }}</td>
                                <td class="num text-ink-2">{{ isNeg(p) ? '—' : oldest(p) }}</td>
                                <td class="num"><button class="link-btn" @click.stop="card(p)">Карточка</button></td>
                            </tr>
                            <tr v-if="expanded === p.id" class="batch-tr">
                                <td></td>
                                <td colspan="7">
                                    <div v-if="p.batches.length" class="batches">
                                        <div class="batch-head">
                                            <span>Поступление</span><span>Дата прихода</span>
                                            <span class="num">Кол-во</span><span class="num">Себест. ед.</span>
                                            <span class="num">Дней</span><span></span>
                                        </div>
                                        <div v-for="(b, i) in p.batches" :key="i" class="batch-row" :class="{ stale: b.days >= STALE_DAYS }">
                                            <span class="bship">{{ b.ship }}</span>
                                            <span class="text-ink-2">{{ b.date }}</span>
                                            <span class="num">{{ b.qty }}</span>
                                            <span class="num">{{ money(b.cost) }}</span>
                                            <span class="num text-ink-2">{{ b.days }}<span v-if="b.days >= STALE_DAYS" :style="{ color: 'var(--warn)' }"> ⚠</span></span>
                                            <span class="num"><button class="link-btn link-btn--bad">Списать</button></span>
                                        </div>
                                    </div>
                                    <div v-else class="text-ink-3" style="padding:8px 0;font-size:13px">
                                        Партий нет — товар продан под заказ, остаток отрицательный. Закроется ближайшим приходом.
                                    </div>
                                </td>
                            </tr>
                        </template>
                        <tr v-if="!rows.length"><td colspan="8"><div class="j-empty">Ничего не найдено</div></td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Карточка товара -->
        <AppModal :open="open" :title="cur?.name || ''" :subtitle="cur ? cur.group + ' · ' + cur.unit : ''" @close="open = false">
            <template v-if="cur">
                <div class="card-kpis">
                    <div class="ck"><div class="ck-l">Остаток</div><div class="ck-v tnum">{{ qty(cur) }} {{ cur.unit }}</div></div>
                    <div class="ck"><div class="ck-l">Стоимость</div><div class="ck-v tnum">{{ money(value(cur)) }}</div></div>
                    <div class="ck"><div class="ck-l">Партий</div><div class="ck-v tnum">{{ cur.batches.length }}</div></div>
                    <div class="ck"><div class="ck-l">Старшая</div><div class="ck-v tnum">{{ oldest(cur) }} дн.</div></div>
                </div>
                <div>
                    <span class="h2">Текущие партии (FIFO)</span>
                    <div v-for="(b, i) in cur.batches" :key="i" class="item-row">
                        <div><div class="nm">{{ b.ship }}</div><div class="sub">приход {{ b.date }} · {{ b.days }} дн. на складе</div></div>
                        <div class="num text-ink-2">{{ b.qty }}</div>
                        <div class="num">{{ money(b.cost) }}</div>
                        <div></div>
                    </div>
                    <div v-if="!cur.batches.length" class="text-ink-3" style="padding:10px 0;font-size:14px">Партий нет (отрицательный остаток).</div>
                </div>
                <div>
                    <span class="h2">История движений</span>
                    <div class="moves">
                        <div class="mv"><span class="mv-in">+ Приход</span><span class="text-ink-2">ИН-0035 · 25.03.2026</span><span class="num">150 м</span></div>
                        <div class="mv"><span class="mv-out">− Продажа</span><span class="text-ink-2">ИН-0029 · 22.06.2026</span><span class="num">−320 м</span></div>
                        <div class="mv"><span class="mv-in">+ Приход</span><span class="text-ink-2">ИН-0042 · 20.06.2026</span><span class="num">320 м</span></div>
                    </div>
                </div>
            </template>
            <template #footer>
                <button class="btn-primary pressable" style="flex:1;justify-content:center">Списать</button>
                <button class="btn-ghost pressable">Инвентаризация</button>
            </template>
        </AppModal>
    </AppShell>
</template>
