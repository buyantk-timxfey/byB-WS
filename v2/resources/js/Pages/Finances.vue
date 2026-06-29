<script setup lang="ts">
import { ref, computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppShell from '@/Layouts/AppShell.vue';
import AppModal from '@/Components/AppModal.vue';
import { money, pct } from '@/lib/format';

const props = defineProps<{
    period: string; periodLabel: string; taxRate: number; salaryRate: number;
    pnl: { revenue: number; cogs: number; acquiring: number; expenses: number; gross: number; tax: number; net: number; salary: number; retained: number; salesCount: number };
    metrics: { margin: number; roi: number; avgCheck: number };
    chart: { months: string[]; profit: number[] };
    drill: Record<string, { doc: string; date: string; sum: number }[]>;
}>();

const tab = ref<'summary' | 'months'>('summary');
const maxP = computed(() => Math.max(1, ...props.chart.profit.map((v) => Math.abs(v))));
const setPeriod = (p: string) => router.get('/finances', { period: p }, { preserveState: true, preserveScroll: true });

const open = ref(false);
const drillTitle = ref('');
const drillRows = ref<{ doc: string; date: string; sum: number }[]>([]);
const drillTotal = computed(() => drillRows.value.reduce((a, r) => a + r.sum, 0));
const titles: Record<string, string> = { income: 'Выручка', cogs: 'Себестоимость проданного', acquiring: 'Эквайринг', expense: 'Прочие расходы ИП' };
function drill(key: string) {
    drillRows.value = props.drill[key] ?? [];
    drillTitle.value = titles[key] ?? key;
    open.value = true;
}
</script>

<template>
    <Head title="Финансы" />
    <AppShell>
        <div class="toolbar">
            <h1>Финансы</h1>
            <div class="seg" style="margin-left:auto">
                <button :class="{ on: period === 'month' }" @click="setPeriod('month')">Месяц</button>
                <button :class="{ on: period === 'quarter' }" @click="setPeriod('quarter')">Квартал</button>
                <button :class="{ on: period === 'year' }" @click="setPeriod('year')">Год</button>
            </div>
        </div>

        <div class="fin-kpi">
            <div class="fk glass"><div class="fk-l">Выручка</div><div class="fk-v tnum">{{ money(pnl.revenue) }}</div><div class="fk-s">{{ pnl.salesCount }} продаж · ср. чек {{ money(metrics.avgCheck) }}</div></div>
            <div class="fk glass"><div class="fk-l">Валовая прибыль</div><div class="fk-v tnum" :style="{ color: 'var(--income)' }">{{ money(pnl.gross) }}</div><div class="fk-s">чистая {{ money(pnl.net) }}</div></div>
            <div class="fk glass"><div class="fk-l">Маржа</div><div class="fk-v tnum">{{ pct(metrics.margin) }}</div><div class="fk-s">валовая / выручка</div></div>
            <div class="fk glass"><div class="fk-l">ROI</div><div class="fk-v tnum">{{ pct(metrics.roi) }}</div><div class="fk-s">прибыль / затраты</div></div>
        </div>

        <div class="seg fin-tabs">
            <button :class="{ on: tab === 'summary' }" @click="tab = 'summary'">Сводка</button>
            <button :class="{ on: tab === 'months' }" @click="tab = 'months'">По месяцам</button>
        </div>

        <div v-if="tab === 'summary'" class="fin-grid">
            <div class="pnl glass">
                <div class="pnl-h">Отчёт о прибыли · {{ periodLabel }}</div>
                <div class="pnl-row pnl-row--in" @click="drill('income')">
                    <span>Выручка <span class="pnl-hint">оплаченные продажи</span></span>
                    <span class="tnum" :style="{ color: 'var(--income)' }">{{ money(pnl.revenue) }}</span>
                </div>
                <div class="pnl-row pnl-row--out" @click="drill('cogs')">
                    <span>− Себестоимость проданного <span class="pnl-hint">COGS, FIFO</span></span>
                    <span class="tnum">{{ money(pnl.cogs) }}</span>
                </div>
                <div class="pnl-row pnl-row--out" @click="drill('acquiring')">
                    <span>− Эквайринг <span class="pnl-hint">комиссии карт и СБП</span></span>
                    <span class="tnum">{{ money(pnl.acquiring) }}</span>
                </div>
                <div class="pnl-row pnl-row--out" @click="drill('expense')">
                    <span>− Прочие расходы ИП <span class="pnl-hint">по статьям</span></span>
                    <span class="tnum">{{ money(pnl.expenses) }}</span>
                </div>
                <div class="pnl-row pnl-row--total">
                    <span>= Валовая прибыль <span class="pnl-hint">маржа {{ pct(metrics.margin) }}</span></span>
                    <span class="tnum">{{ money(pnl.gross) }}</span>
                </div>
                <div class="pnl-sep"></div>
                <div class="pnl-row pnl-row--minor"><span>Налог <span class="pnl-hint">{{ taxRate }} % от валовой</span></span><span class="tnum">− {{ money(pnl.tax) }}</span></div>
                <div class="pnl-row pnl-row--total"><span>= Чистая прибыль</span><span class="tnum">{{ money(pnl.net) }}</span></div>
                <div class="pnl-row pnl-row--minor"><span>Зарплата <span class="pnl-hint">{{ salaryRate }} % от чистой</span></span><span class="tnum">− {{ money(pnl.salary) }}</span></div>
                <div class="pnl-row pnl-row--final"><span>Остаётся в бизнесе</span><span class="tnum">{{ money(pnl.retained) }}</span></div>
                <div class="pnl-foot">Клик по строке — расшифровка до документов</div>
            </div>

            <div class="chart-card glass">
                <div class="pnl-h">Прибыль, 12 месяцев <span class="pnl-hint">тыс. ₽</span></div>
                <div class="chart-bars">
                    <div v-for="(v, i) in chart.profit" :key="i" class="cb">
                        <div class="cb-bar" :class="{ 'cb-bar--last': i === chart.profit.length - 1 }" :style="{ height: Math.max(2, (Math.abs(v) / maxP) * 100) + '%' }">
                            <span class="cb-val">{{ v }}</span>
                        </div>
                        <span class="cb-m">{{ chart.months[i] }}</span>
                    </div>
                </div>
            </div>
        </div>

        <div v-else class="jcard glass">
            <div class="jscroll" style="padding:18px 20px">
                <div class="text-ink-3" style="font-size:14px">Помесячная таблица появится по мере накопления данных.</div>
            </div>
        </div>

        <AppModal :open="open" :title="drillTitle" :subtitle="'Расшифровка · ' + money(drillTotal)" @close="open = false">
            <div>
                <div v-for="(r, i) in drillRows" :key="i" class="drill-row">
                    <div><div class="dr-doc">{{ r.doc }}</div><div class="dr-sub">{{ r.date }}</div></div>
                    <div class="tnum dr-sum">{{ money(r.sum) }}</div>
                </div>
                <div v-if="!drillRows.length" class="text-ink-3" style="padding:14px 0;font-size:14px">Нет данных за период.</div>
                <div v-if="drillRows.length" class="drill-total"><span>Итого</span><span class="tnum">{{ money(drillTotal) }}</span></div>
            </div>
            <template #footer><button class="btn-ghost pressable" style="flex:1;justify-content:center" @click="open = false">Закрыть</button></template>
        </AppModal>
    </AppShell>
</template>
