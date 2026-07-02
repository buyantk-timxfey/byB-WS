<script setup lang="ts">
import { ref, computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppShell from '@/Layouts/AppShell.vue';
import AppModal from '@/Components/AppModal.vue';
import Icon from '@/Components/Icon.vue';
import { money, money0, pct } from '@/lib/format';

type DrillRow = { doc: string; date: string; sum: number; article_id: number | null; article: string | null };
type Delta = [string, boolean];

const props = defineProps<{
    period: string; anchor: string; isCurrent: boolean; periodLabel: string; cmpLabel: string;
    taxRate: number; salaryRate: number;
    pnl: { revenue: number; cogs: number; acquiring: number; expenses: number; otherIncome: number; gross: number; tax: number; net: number; salary: number; retained: number; salesCount: number };
    metrics: { margin: number; roi: number; avgCheck: number };
    deltas: { revenue: Delta; gross: Delta; margin: Delta; roi: Delta };
    byArticle: { article_id: number | null; name: string; sum: number; acquiring: boolean }[];
    monthRows: { label: string; revenue: number; other: number; costs: number; gross: number; tax: number; net: number; salary: number }[];
    taxYtd: number;
    chart: { months: string[]; profit: number[] };
    drill: Record<string, DrillRow[]>;
}>();

const tab = ref<'summary' | 'months'>('summary');
const maxP = computed(() => Math.max(1, ...props.chart.profit.map((v) => Math.abs(v))));
const nav = (params: Record<string, string>) => router.get('/finances', params, { preserveState: true, preserveScroll: true });
const setPeriod = (p: string) => nav({ period: p });

// Листание периодов: якорь сдвигается на месяц / квартал / год
function shiftPeriod(dir: number) {
    const d = new Date(props.anchor + 'T00:00:00');
    if (props.period === 'quarter') d.setMonth(d.getMonth() + 3 * dir, 1);
    else if (props.period === 'year') d.setFullYear(d.getFullYear() + dir, 0, 1);
    else d.setMonth(d.getMonth() + dir, 1);
    const iso = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    nav({ period: props.period, anchor: iso });
}

const byArtMax = computed(() => Math.max(1, ...props.byArticle.map((a) => a.sum)));
const totalByArt = computed(() => props.byArticle.reduce((a, r) => a + r.sum, 0));

const open = ref(false);
const drillTitle = ref('');
const drillRows = ref<DrillRow[]>([]);
const drillTotal = computed(() => drillRows.value.reduce((a, r) => a + r.sum, 0));
const titles: Record<string, string> = { income: 'Выручка', income_other: 'Прочие доходы', cogs: 'Себестоимость проданного', acquiring: 'Эквайринг', expense: 'Прочие расходы ИП' };
function drill(key: string) {
    drillRows.value = props.drill[key] ?? [];
    drillTitle.value = titles[key] ?? key;
    open.value = true;
}
// Клик по статье — расшифровка только её платежей
function drillArticle(a: { article_id: number | null; name: string; acquiring: boolean }) {
    if (a.acquiring) return drill('acquiring');
    drillRows.value = (props.drill.expense ?? []).filter((r) => r.article_id === a.article_id);
    drillTitle.value = a.name;
    open.value = true;
}
</script>

<template>
    <Head title="Финансы" />
    <AppShell>
        <div class="toolbar">
            <h1>Финансы</h1>
            <div class="pnav glass">
                <button class="pnav-btn pressable" @click="shiftPeriod(-1)"><Icon name="chevron-left" :size="16" /></button>
                <span class="pnav-label">{{ periodLabel }}</span>
                <button class="pnav-btn pressable" :disabled="isCurrent" :style="isCurrent ? 'opacity:.3;cursor:default' : ''" @click="!isCurrent && shiftPeriod(1)"><Icon name="chevron-right" :size="16" /></button>
            </div>
            <div class="seg" style="margin-left:auto">
                <button :class="{ on: period === 'month' }" @click="setPeriod('month')">Месяц</button>
                <button :class="{ on: period === 'quarter' }" @click="setPeriod('quarter')">Квартал</button>
                <button :class="{ on: period === 'year' }" @click="setPeriod('year')">Год</button>
            </div>
        </div>

        <div class="fin-kpi">
            <div class="fk glass">
                <div class="fk-top"><div class="fk-l">Выручка</div><span v-if="deltas.revenue[0]" class="fk-pill" :class="{ 'fk-pill--down': deltas.revenue[1] }">{{ deltas.revenue[0] }}</span></div>
                <div class="fk-v tnum">{{ money0(pnl.revenue) }}</div>
                <div class="fk-s">{{ pnl.salesCount }} продаж · ср. чек {{ money0(metrics.avgCheck) }}</div>
            </div>
            <div class="fk glass">
                <div class="fk-top"><div class="fk-l">Валовая прибыль</div><span v-if="deltas.gross[0]" class="fk-pill" :class="{ 'fk-pill--down': deltas.gross[1] }">{{ deltas.gross[0] }}</span></div>
                <div class="fk-v tnum" :style="{ color: 'var(--income)' }">{{ money0(pnl.gross) }}</div>
                <div class="fk-s">чистая {{ money0(pnl.net) }}</div>
            </div>
            <div class="fk glass">
                <div class="fk-top"><div class="fk-l">Маржа</div><span v-if="deltas.margin[0]" class="fk-pill" :class="{ 'fk-pill--down': deltas.margin[1] }">{{ deltas.margin[0] }}</span></div>
                <div class="fk-v tnum">{{ pct(metrics.margin) }}</div>
                <div class="fk-s">валовая / выручка</div>
            </div>
            <div class="fk glass">
                <div class="fk-top"><div class="fk-l">ROI</div><span v-if="deltas.roi[0]" class="fk-pill" :class="{ 'fk-pill--down': deltas.roi[1] }">{{ deltas.roi[0] }}</span></div>
                <div class="fk-v tnum">{{ pct(metrics.roi) }}</div>
                <div class="fk-s">прибыль / затраты</div>
            </div>
        </div>
        <div v-if="deltas.revenue[0] || deltas.gross[0]" class="fk-cmp">Сравнение с: {{ cmpLabel }}</div>

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
                <div class="pnl-row pnl-row--in" @click="drill('income_other')">
                    <span>+ Прочие доходы <span class="pnl-hint">кэшбэк, проценты</span></span>
                    <span class="tnum" :style="{ color: 'var(--income)' }">{{ money(pnl.otherIncome) }}</span>
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
                <div class="pnl-row pnl-row--minor"><span>Налог с начала года <span class="pnl-hint">отложить к уплате</span></span><span class="tnum">{{ money(taxYtd) }}</span></div>
                <div class="pnl-foot">Клик по строке — расшифровка до документов</div>
            </div>

            <div class="fin-right">
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

                <!-- На что ушли деньги: расходы периода по статьям -->
                <div class="byart glass">
                    <div class="pnl-h">На что ушли деньги <span class="pnl-hint">{{ periodLabel }}</span></div>
                    <div v-for="a in byArticle" :key="a.name" class="ba-row pressable" @click="drillArticle(a)">
                        <div class="ba-info">
                            <span class="ba-name">{{ a.name }}</span>
                            <span class="ba-sum tnum">{{ money(a.sum) }}</span>
                        </div>
                        <div class="ba-track"><div class="ba-fill" :style="{ width: (a.sum / byArtMax * 100) + '%' }"></div></div>
                    </div>
                    <div v-if="!byArticle.length" class="text-ink-3" style="font-size:13px;padding:8px 0">Расходов за период нет.</div>
                    <div v-if="byArticle.length" class="ba-total"><span>Итого</span><span class="tnum">{{ money(totalByArt) }}</span></div>
                </div>
            </div>
        </div>

        <div v-else class="jcard glass">
            <div class="jscroll">
                <table class="jtable">
                    <thead><tr><th>Месяц</th><th class="num">Выручка</th><th class="num">Прочие доходы</th><th class="num">Затраты</th><th class="num">Валовая</th><th class="num">Налог</th><th class="num">Чистая</th><th class="num">Зарплата</th></tr></thead>
                    <tbody>
                        <tr v-for="m in [...monthRows].reverse()" :key="m.label">
                            <td style="font-weight:600">{{ m.label }}</td>
                            <td class="num">{{ money(m.revenue) }}</td>
                            <td class="num">{{ m.other ? money(m.other) : '—' }}</td>
                            <td class="num">{{ money(m.costs) }}</td>
                            <td class="num" :style="{ color: m.gross >= 0 ? 'var(--income)' : 'var(--expense)', fontWeight: 600 }">{{ money(m.gross) }}</td>
                            <td class="num">{{ money(m.tax) }}</td>
                            <td class="num">{{ money(m.net) }}</td>
                            <td class="num">{{ money(m.salary) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <AppModal :open="open" :title="drillTitle" :subtitle="'Расшифровка · ' + money(drillTotal)" @close="open = false">
            <div>
                <div v-for="(r, i) in drillRows" :key="i" class="drill-row">
                    <div>
                        <div class="dr-doc">{{ r.doc }}</div>
                        <div class="dr-sub">{{ r.date }}<template v-if="r.article"> · {{ r.article }}</template></div>
                    </div>
                    <div class="tnum dr-sum">{{ money(r.sum) }}</div>
                </div>
                <div v-if="!drillRows.length" class="text-ink-3" style="padding:14px 0;font-size:14px">Нет данных за период.</div>
                <div v-if="drillRows.length" class="drill-total"><span>Итого</span><span class="tnum">{{ money(drillTotal) }}</span></div>
            </div>
            <template #footer><button class="btn-ghost pressable" style="flex:1;justify-content:center" @click="open = false">Закрыть</button></template>
        </AppModal>
    </AppShell>
</template>

<style scoped>
/* Листание периодов */
.pnav { display: flex; align-items: center; gap: 4px; padding: 4px 6px; border-radius: 999px; }
.pnav-btn { width: 30px; height: 30px; border-radius: 50%; display: flex; align-items: center; justify-content: center; background: transparent; border: 0; color: var(--ink-2); cursor: pointer; }
.pnav-btn:hover { background: var(--glass-fill); color: var(--ink); }
.pnav-label { font-size: 14px; font-weight: 600; min-width: 120px; text-align: center; }

/* Дельты на KPI */
.fk-top { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
.fk-pill { font-size: 11px; font-weight: 700; padding: 1px 8px; border-radius: 999px; background: rgba(52, 199, 89, .16); color: var(--income); white-space: nowrap; }
.fk-pill--down { background: rgba(255, 69, 58, .14); color: var(--expense); }
.fk-cmp { font-size: 12px; color: var(--ink-3); margin: -8px 2px 14px; }

/* Правая колонка сводки: график + расходы по статьям */
.fin-right { display: flex; flex-direction: column; gap: 16px; min-width: 0; }

/* Расходы по статьям */
.byart { padding: 18px 20px; }
.ba-row { padding: 9px 0 7px; cursor: pointer; border-radius: 10px; }
.ba-row:hover .ba-name { color: var(--ink); }
.ba-info { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 5px; }
.ba-name { font-size: 13.5px; font-weight: 600; color: var(--ink-2); min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.ba-sum { font-size: 13px; font-weight: 600; white-space: nowrap; }
.ba-track { height: 6px; border-radius: 999px; background: var(--glass-fill); overflow: hidden; }
.ba-fill { height: 100%; border-radius: 999px; background: linear-gradient(90deg, #ff9f0a, #ff6b3d); }
.ba-total { display: flex; justify-content: space-between; font-size: 13px; font-weight: 700; padding-top: 12px; margin-top: 8px; border-top: 1px solid var(--glass-border); }

@media (max-width: 640px) {
    .pnav { order: 3; width: 100%; justify-content: space-between; }
    .pnav-label { flex: 1; }
}
</style>
