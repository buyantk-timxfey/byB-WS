<script setup lang="ts">
import { ref, computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppShell from '@/Layouts/AppShell.vue';
import Icon from '@/Components/Icon.vue';
import StatusPill from '@/Components/StatusPill.vue';
import { money, money0, num, date as fdate } from '@/lib/format';

type Batch = { ship: string; date: string; qty: number; cost: number; days: number };
type Row = { id: number; name: string; group: string; unit: string; qty: number; reserved: number; available: number; value: number; days: number; stale: boolean; negative: boolean; batches: Batch[] };

const props = defineProps<{
    rows: Row[]; staleDays: number; frozen: number; staleMoney: number; posCount: number; negCount: number; reservedCount?: number;
}>();

const seg = ref<'all' | 'stock' | 'stale' | 'neg'>('all');
const q = ref('');
const rows = computed(() => props.rows.filter((p) => {
    if (seg.value === 'stock' && p.qty === 0) return false;
    if (seg.value === 'stale' && !p.stale) return false;
    if (seg.value === 'neg' && !p.negative) return false;
    if (q.value && !(`${p.name} ${p.group}`.toLowerCase().includes(q.value.toLowerCase()))) return false;
    return true;
}));

const expanded = ref<number | null>(null);
const toggle = (id: number) => { expanded.value = expanded.value === id ? null : id; };
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
        </div>

        <div class="wh-stats">
            <div class="wh-stat glass">
                <div class="wh-label">Замороженные деньги</div>
                <div class="wh-val tnum">{{ money0(frozen) }}</div>
                <div class="wh-sub">стоимость остатков по себестоимости</div>
            </div>
            <div class="wh-stat glass" :class="{ 'wh-stat--warn': staleMoney > 0 }">
                <div class="wh-label">Зависло (старше {{ staleDays }} дн.)</div>
                <div class="wh-val tnum" :style="staleMoney > 0 ? { color: 'var(--warn)' } : {}">{{ money0(staleMoney) }}</div>
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
                            <th style="width:34px"></th><th>Товар</th><th>Группа</th><th>Ед.</th>
                            <th class="num">Остаток</th><th class="num">Резерв</th><th class="num">Доступно</th><th class="num">Стоимость</th><th class="num">Дней</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="p in rows" :key="p.id">
                            <tr @click="toggle(p.id)" :class="{ 'tr-open': expanded === p.id }">
                                <td class="cell-chev"><Icon name="chevron-right" :size="16" class="chev" :class="{ 'chev-open': expanded === p.id }" /></td>
                                <td>
                                    {{ p.name }}
                                    <StatusPill v-if="p.stale" text="Залежалый" variant="warn" class="ml-2" />
                                    <StatusPill v-if="p.negative" text="Под заказ" variant="bad" class="ml-2" />
                                </td>
                                <td class="text-ink-2">{{ p.group }}</td>
                                <td class="text-ink-2">{{ p.unit }}</td>
                                <td class="num" :style="p.negative ? { color: 'var(--expense)' } : {}">{{ num(p.qty) }}</td>
                                <td class="num" :style="p.reserved > 0 ? { color: 'var(--warn)' } : { color: 'var(--ink-3)' }">{{ p.reserved > 0 ? num(p.reserved) : '—' }}</td>
                                <td class="num" :style="p.available < 0 ? { color: 'var(--expense)' } : {}">{{ num(p.available) }}</td>
                                <td class="num">{{ money(p.value) }}</td>
                                <td class="num text-ink-2">{{ p.negative ? '—' : p.days }}</td>
                            </tr>
                            <tr v-if="expanded === p.id" class="batch-tr">
                                <td></td>
                                <td colspan="8">
                                    <div v-if="p.batches.length" class="batches">
                                        <div class="batch-head">
                                            <span>Поступление</span><span>Дата прихода</span>
                                            <span class="num">Кол-во</span><span class="num">Себест. ед.</span>
                                            <span class="num">Дней</span>
                                        </div>
                                        <div v-for="(b, i) in p.batches" :key="i" class="batch-row" :class="{ stale: b.days >= staleDays }">
                                            <span class="bship" :title="b.ship">{{ b.ship }}</span>
                                            <span class="text-ink-2">{{ fdate(b.date) }}</span>
                                            <span class="num">{{ num(b.qty) }}</span>
                                            <span class="num">{{ money(b.cost) }}</span>
                                            <span class="num text-ink-2">{{ b.days }}</span>
                                        </div>
                                    </div>
                                    <div v-else class="text-ink-3" style="padding:8px 0;font-size:13px">
                                        Партий нет — товар продан под заказ, остаток отрицательный. Закроется ближайшим приходом.
                                    </div>
                                </td>
                            </tr>
                        </template>
                        <tr v-if="!rows.length"><td colspan="9"><div class="j-empty">На складе пусто — оприходуйте поставку</div></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppShell>
</template>
