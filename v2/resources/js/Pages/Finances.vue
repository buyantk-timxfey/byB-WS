<script setup lang="ts">
import { ref, computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppShell from '@/Layouts/AppShell.vue';
import Icon from '@/Components/Icon.vue';
import AppModal from '@/Components/AppModal.vue';

const money = (n: number) => new Intl.NumberFormat('ru-RU').format(Math.round(n)) + ' ₽';
const pct = (n: number) => n.toFixed(1).replace('.', ',') + ' %';

// Ставки (в Настройках)
const TAX = 0.16, SALARY = 0.20;

// Сводка за период (июнь 2026), кассовый метод по выручке, COGS по проданному (FIFO)
const revenue = 1240000;       // выручка — оплаченные продажи
const cogs = 940000;           // себестоимость проданного (FIFO)
const acquiring = 15100;       // эквайринг — отдельная строка
const expenses = 92000;        // прочие расходы ИП (по статьям)
const salesCount = 8;

const gross = computed(() => revenue - cogs - acquiring - expenses);
const tax = computed(() => Math.round(gross.value * TAX));
const net = computed(() => gross.value - tax.value);
const salary = computed(() => Math.round(net.value * SALARY));
const retained = computed(() => net.value - salary.value);

const marginPct = computed(() => (gross.value / revenue) * 100);
const roiPct = computed(() => (gross.value / (cogs + acquiring + expenses)) * 100);
const avgCheck = computed(() => Math.round(revenue / salesCount));

const period = ref<'month' | 'quarter' | 'year'>('month');
const tab = ref<'summary' | 'months'>('summary');

// Динамика прибыли 12 мес (тыс. ₽)
const months = ['Июл', 'Авг', 'Сен', 'Окт', 'Ноя', 'Дек', 'Янв', 'Фев', 'Мар', 'Апр', 'Май', 'Июн'];
const profit12 = [88, 102, 76, 120, 95, 140, 110, 130, 150, 165, 180, 193];
const maxP = Math.max(...profit12);

// Таблица «По месяцам» — последние 6 месяцев
const m6 = ['Янв', 'Фев', 'Мар', 'Апр', 'Май', 'Июн'];
const tableRows = [
    { label: 'Выручка', cls: 'in', vals: [712000, 845000, 980000, 1075000, 1160000, 1240000] },
    { label: 'Себестоимость (COGS)', cls: 'out', vals: [548000, 642000, 744000, 812000, 876000, 940000] },
    { label: 'Эквайринг', cls: 'out', vals: [9100, 10800, 12400, 13600, 14200, 15100] },
    { label: 'Расходы ИП', cls: 'out', vals: [78000, 82000, 88000, 85000, 90000, 92000] },
    { label: 'Валовая прибыль', cls: 'total', vals: [76900, 110200, 135600, 164400, 179800, 192900] },
    { label: 'Налог 16 %', cls: 'out', vals: [12304, 17632, 21696, 26304, 28768, 30864] },
    { label: 'Чистая прибыль', cls: 'total', vals: [64596, 92568, 113904, 138096, 151032, 162036] },
];

// Расшифровка (drill-down)
const open = ref(false);
const drillTitle = ref('');
type DRow = { doc: string; party: string; date: string; sum: number };
const drillRows = ref<DRow[]>([]);
const drillTotal = computed(() => drillRows.value.reduce((a, r) => a + r.sum, 0));

const drills: Record<string, DRow[]> = {
    'Выручка': [
        { doc: 'Продажа ИН-0029', party: 'ООО Строймонтаж', date: '22.06.2026', sum: 236000 },
        { doc: 'Продажа ИН-0028', party: 'ИП Громов', date: '18.06.2026', sum: 186000 },
        { doc: 'Продажа ИН-0030', party: 'ИП Васильев', date: '27.06.2026', sum: 60000 },
        { doc: 'Возмещение СБП', party: 'Эквайринг (Точка)', date: '26.06.2026', sum: 97797 },
    ],
    'Себестоимость проданного (COGS)': [
        { doc: 'Партия ИН-0042', party: 'Кабель ВВГ 3×2.5 · 320 м', date: 'списано 22.06', sum: 191000 },
        { doc: 'Партия ИН-0041', party: 'Автомат ABB · 100 шт', date: 'списано 18.06', sum: 145200 },
    ],
    'Эквайринг': [
        { doc: 'Комиссия к возм.', party: 'СБП · Точка', date: '26.06.2026', sum: 1203 },
        { doc: 'Комиссия эквайринг', party: 'Карты · Сбер', date: '24.06.2026', sum: 13897 },
    ],
    'Прочие расходы ИП': [
        { doc: 'Аренда офиса', party: 'ООО Деловые Сети', date: '20.06.2026', sum: 45000 },
        { doc: 'Транспорт', party: 'СДЭК / ПЭК', date: 'июнь', sum: 22000 },
        { doc: 'Прочее', party: 'разное', date: 'июнь', sum: 16600 },
        { doc: 'Связь и интернет', party: 'ПАО Ростелеком', date: '19.06.2026', sum: 8400 },
    ],
};
function drill(key: string) {
    if (!drills[key]) return;
    drillTitle.value = key;
    drillRows.value = drills[key];
    open.value = true;
}
</script>

<template>
    <Head title="Финансы" />
    <AppShell>
        <div class="toolbar">
            <h1>Финансы</h1>
            <div class="seg" style="margin-left:auto">
                <button :class="{ on: period === 'month' }" @click="period = 'month'">Месяц</button>
                <button :class="{ on: period === 'quarter' }" @click="period = 'quarter'">Квартал</button>
                <button :class="{ on: period === 'year' }" @click="period = 'year'">Год</button>
            </div>
        </div>

        <!-- KPI -->
        <div class="fin-kpi">
            <div class="fk glass"><div class="fk-l">Выручка</div><div class="fk-v tnum">{{ money(revenue) }}</div><div class="fk-s">{{ salesCount }} продаж · ср. чек {{ money(avgCheck) }}</div></div>
            <div class="fk glass"><div class="fk-l">Валовая прибыль</div><div class="fk-v tnum" :style="{ color: 'var(--income)' }">{{ money(gross) }}</div><div class="fk-s">чистая {{ money(net) }}</div></div>
            <div class="fk glass"><div class="fk-l">Маржа</div><div class="fk-v tnum">{{ pct(marginPct) }}</div><div class="fk-s">валовая / выручка</div></div>
            <div class="fk glass"><div class="fk-l">ROI</div><div class="fk-v tnum">{{ pct(roiPct) }}</div><div class="fk-s">прибыль / затраты</div></div>
        </div>

        <div class="seg fin-tabs">
            <button :class="{ on: tab === 'summary' }" @click="tab = 'summary'">Сводка</button>
            <button :class="{ on: tab === 'months' }" @click="tab = 'months'">По месяцам</button>
        </div>

        <!-- СВОДКА -->
        <div v-if="tab === 'summary'" class="fin-grid">
            <!-- P&L водопад -->
            <div class="pnl glass">
                <div class="pnl-h">Отчёт о прибыли · июнь 2026</div>
                <div class="pnl-row pnl-row--in" @click="drill('Выручка')">
                    <span>Выручка <span class="pnl-hint">оплаченные продажи</span></span>
                    <span class="tnum" :style="{ color: 'var(--income)' }">{{ money(revenue) }}</span>
                </div>
                <div class="pnl-row pnl-row--out" @click="drill('Себестоимость проданного (COGS)')">
                    <span>− Себестоимость проданного <span class="pnl-hint">COGS, FIFO</span></span>
                    <span class="tnum">{{ money(cogs) }}</span>
                </div>
                <div class="pnl-row pnl-row--out" @click="drill('Эквайринг')">
                    <span>− Эквайринг <span class="pnl-hint">комиссии карт и СБП</span></span>
                    <span class="tnum">{{ money(acquiring) }}</span>
                </div>
                <div class="pnl-row pnl-row--out" @click="drill('Прочие расходы ИП')">
                    <span>− Прочие расходы ИП <span class="pnl-hint">по статьям</span></span>
                    <span class="tnum">{{ money(expenses) }}</span>
                </div>
                <div class="pnl-row pnl-row--total">
                    <span>= Валовая прибыль <span class="pnl-hint">маржа {{ pct(marginPct) }}</span></span>
                    <span class="tnum">{{ money(gross) }}</span>
                </div>
                <div class="pnl-sep"></div>
                <div class="pnl-row pnl-row--minor">
                    <span>Налог <span class="pnl-hint">{{ Math.round(TAX * 100) }} % от валовой</span></span>
                    <span class="tnum">− {{ money(tax) }}</span>
                </div>
                <div class="pnl-row pnl-row--total">
                    <span>= Чистая прибыль</span>
                    <span class="tnum">{{ money(net) }}</span>
                </div>
                <div class="pnl-row pnl-row--minor">
                    <span>Зарплата <span class="pnl-hint">{{ Math.round(SALARY * 100) }} % от чистой</span></span>
                    <span class="tnum">− {{ money(salary) }}</span>
                </div>
                <div class="pnl-row pnl-row--final">
                    <span>Остаётся в бизнесе</span>
                    <span class="tnum">{{ money(retained) }}</span>
                </div>
                <div class="pnl-foot">Клик по строке — расшифровка до документов</div>
            </div>

            <!-- Динамика 12 мес -->
            <div class="chart-card glass">
                <div class="pnl-h">Прибыль, 12 месяцев <span class="pnl-hint">тыс. ₽</span></div>
                <div class="chart-bars">
                    <div v-for="(v, i) in profit12" :key="i" class="cb">
                        <div class="cb-bar" :class="{ 'cb-bar--last': i === profit12.length - 1 }" :style="{ height: (v / maxP * 100) + '%' }">
                            <span class="cb-val">{{ v }}</span>
                        </div>
                        <span class="cb-m">{{ months[i] }}</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- ПО МЕСЯЦАМ -->
        <div v-else class="jcard glass">
            <div class="jscroll">
                <table class="jtable">
                    <thead>
                        <tr><th>Статья</th><th v-for="m in m6" :key="m" class="num">{{ m }}</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in tableRows" :key="r.label" :class="{ 'mrow-total': r.cls === 'total' }">
                            <td>{{ r.label }}</td>
                            <td v-for="(v, i) in r.vals" :key="i" class="num"
                                :style="r.cls === 'in' ? { color: 'var(--income)' } : (r.cls === 'total' ? { fontWeight: 700 } : {})">
                                {{ money(v) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Расшифровка -->
        <AppModal :open="open" :title="drillTitle" :subtitle="'Расшифровка · ' + money(drillTotal)" @close="open = false">
            <div class="jcard" style="box-shadow:none;border:0;background:transparent">
                <div v-for="(r, i) in drillRows" :key="i" class="drill-row">
                    <div><div class="dr-doc">{{ r.doc }}</div><div class="dr-sub">{{ r.party }} · {{ r.date }}</div></div>
                    <div class="tnum dr-sum">{{ money(r.sum) }}</div>
                </div>
                <div class="drill-total"><span>Итого</span><span class="tnum">{{ money(drillTotal) }}</span></div>
            </div>
            <template #footer>
                <button class="btn-ghost pressable" style="flex:1;justify-content:center" @click="open = false">Закрыть</button>
            </template>
        </AppModal>
    </AppShell>
</template>
