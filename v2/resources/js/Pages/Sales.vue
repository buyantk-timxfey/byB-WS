<script setup lang="ts">
import { ref, computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppShell from '@/Layouts/AppShell.vue';
import Icon from '@/Components/Icon.vue';
import StatusPill from '@/Components/StatusPill.vue';
import AppModal from '@/Components/AppModal.vue';

const money = (n: number) => new Intl.NumberFormat('ru-RU').format(n) + ' ₽';

type Item = { name: string; qty: number; price: number; cost: number };
type Sale = {
    id: string; date: string; buyer: string; sum: number; paid: number; cost: number;
    status: 'Счёт' | 'Отгружено' | 'Отменено'; comment: string; account: string;
    source: string; items: Item[];
};

const data = ref<Sale[]>([
    { id: 'ИЛ-0031', date: '02.07.2026', buyer: 'ООО Строймонтаж', sum: 268000, paid: 0, cost: 214000, status: 'Счёт', comment: '', account: '', source: '', items: [{ name: 'Кабель-канал 40×40', qty: 200, price: 1340, cost: 1070 }] },
    { id: 'ИН-0030', date: '27.06.2026', buyer: 'ИП Васильев', sum: 124000, paid: 60000, cost: 96500, status: 'Отгружено', comment: '', account: 'Точка · 5512', source: 'ИН-0043', items: [{ name: 'Насос Grundfos UPS 25-40', qty: 5, price: 24800, cost: 19300 }] },
    { id: 'ИН-0029', date: '22.06.2026', buyer: 'ООО Строймонтаж', sum: 236000, paid: 236000, cost: 191000, status: 'Отгружено', comment: '', account: 'Сбер · 7781', source: 'ИН-0042', items: [{ name: 'Кабель ВВГ 3×2.5', qty: 320, price: 737, cost: 597 }] },
    { id: 'ИН-0028', date: '18.06.2026', buyer: 'ИП Громов', sum: 186000, paid: 186000, cost: 145200, status: 'Отгружено', comment: '', account: 'Сбер · 7781', source: 'ИН-0041', items: [{ name: 'Автомат ABB SH201 C16', qty: 100, price: 1860, cost: 1452 }] },
    { id: 'ИН-0027', date: '14.06.2026', buyer: 'ООО Энергосети', sum: 98000, paid: 0, cost: 83400, status: 'Отменено', comment: 'Отказ покупателя', account: '', source: '', items: [{ name: 'Лоток 100×50', qty: 60, price: 1633, cost: 1390 }] },
]);

const seg = ref<'all' | 'Счёт' | 'Отгружено' | 'Отменено'>('all');
const q = ref('');

const rows = computed(() => data.value.filter((s) => {
    if (seg.value !== 'all' && s.status !== seg.value) return false;
    if (q.value && !(`${s.id} ${s.buyer}`.toLowerCase().includes(q.value.toLowerCase()))) return false;
    return true;
}));

// Итоги: только проведённые (Отгружено) дают выручку/прибыль
const shipped = computed(() => rows.value.filter((s) => s.status === 'Отгружено'));
const totalRevenue = computed(() => shipped.value.reduce((a, s) => a + s.sum, 0));
const totalProfit = computed(() => shipped.value.reduce((a, s) => a + (s.sum - s.cost), 0));
const avgCheck = computed(() => shipped.value.length ? Math.round(totalRevenue.value / shipped.value.length) : 0);
const totalDebt = computed(() => shipped.value.reduce((a, s) => a + (s.sum - s.paid), 0));

const statusVariant = (s: Sale['status']) => s === 'Отгружено' ? 'ok' : s === 'Счёт' ? 'info' : 'neutral';
const payText = (s: Sale) => s.paid >= s.sum ? 'Оплачено' : s.paid > 0 ? 'Частично' : 'Не оплачено';
const payVariant = (s: Sale) => s.paid >= s.sum ? 'ok' : s.paid > 0 ? 'warn' : 'bad';
const margin = (s: Sale) => s.sum ? Math.round(((s.sum - s.cost) / s.sum) * 100) : 0;

const open = ref(false);
const cur = ref<Sale | null>(null);
function openDoc(s: Sale) { cur.value = s; open.value = true; }
function create() {
    cur.value = { id: 'ИН-0032', date: '', buyer: '', sum: 0, paid: 0, cost: 0, status: 'Счёт', comment: '', account: '', source: '', items: [] };
    open.value = true;
}
const curProfit = computed(() => cur.value ? cur.value.sum - cur.value.cost : 0);
const curMargin = computed(() => cur.value && cur.value.sum ? Math.round((curProfit.value / cur.value.sum) * 100) : 0);
</script>

<template>
    <Head title="Продажи" />
    <AppShell>
        <div class="toolbar">
            <h1>Продажи</h1>
            <div class="tb-search">
                <Icon name="search" :size="16" class="text-ink-3" />
                <input v-model="q" placeholder="Поиск по покупателю, №…" />
            </div>
            <div class="seg">
                <button :class="{ on: seg === 'all' }" @click="seg = 'all'">Все</button>
                <button :class="{ on: seg === 'Счёт' }" @click="seg = 'Счёт'">Счета</button>
                <button :class="{ on: seg === 'Отгружено' }" @click="seg = 'Отгружено'">Отгружено</button>
                <button :class="{ on: seg === 'Отменено' }" @click="seg = 'Отменено'">Отменено</button>
            </div>
            <button class="btn-primary pressable" @click="create"><Icon name="plus" :size="17" /> Создать</button>
        </div>

        <div class="jcard glass">
            <div class="jscroll">
                <table class="jtable">
                    <thead>
                        <tr>
                            <th>№</th><th>Дата</th><th>Покупатель</th>
                            <th class="num">Сумма</th><th>Оплата</th>
                            <th class="num">Прибыль</th><th>Статус</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="s in rows" :key="s.id" @click="openDoc(s)">
                            <td>{{ s.id }}</td>
                            <td class="text-ink-2">{{ s.date }}</td>
                            <td>{{ s.buyer }}</td>
                            <td class="num">{{ money(s.sum) }}</td>
                            <td><StatusPill :text="payText(s)" :variant="payVariant(s)" /></td>
                            <td class="num" :class="s.status === 'Отменено' ? 'text-ink-3' : ''">
                                <span v-if="s.status !== 'Отменено'" :style="{ color: 'var(--income)' }">{{ money(s.sum - s.cost) }}</span>
                                <span v-else>—</span>
                                <span v-if="s.status !== 'Отменено'" class="text-ink-3" style="font-size:12px"> · {{ margin(s) }}%</span>
                            </td>
                            <td><StatusPill :text="s.status" :variant="statusVariant(s.status)" /></td>
                        </tr>
                        <tr v-if="!rows.length"><td colspan="7"><div class="j-empty">Ничего не найдено</div></td></tr>
                    </tbody>
                    <tfoot v-if="rows.length">
                        <tr>
                            <td colspan="3">Выручка (отгружено): {{ shipped.length }}</td>
                            <td class="num">{{ money(totalRevenue) }}</td>
                            <td>Ср. чек {{ money(avgCheck) }}</td>
                            <td class="num" :style="{ color: 'var(--income)' }">{{ money(totalProfit) }}</td>
                            <td>Долг: {{ money(totalDebt) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <!-- Форма-документ: Реализация -->
        <AppModal :open="open" :title="cur?.id || 'Новая продажа'" :subtitle="cur?.buyer" @close="open = false">
            <template v-if="cur">
                <div class="fld-row">
                    <div class="fld"><label>Покупатель</label><input :value="cur.buyer" placeholder="Выберите контрагента" /></div>
                    <div class="fld"><label>Статус</label>
                        <select :value="cur.status"><option>Счёт</option><option>Отгружено</option><option>Отменено</option></select>
                    </div>
                </div>
                <div class="fld-row">
                    <div class="fld"><label>Дата</label><input :value="cur.date" placeholder="дд.мм.гггг" /></div>
                    <div class="fld"><label>Счёт зачисления</label><input :value="cur.account" placeholder="—" /></div>
                </div>
                <div class="fld"><label>Источник-поставка (опц.)</label><input :value="cur.source" placeholder="№ поставки" /></div>

                <div>
                    <div class="items-h">
                        <span class="h2">Позиции</span>
                        <button class="btn-ghost" style="padding:6px 12px;font-size:13px">+ Товар</button>
                    </div>
                    <div v-for="(it, i) in cur.items" :key="i" class="item-row item-row--sale">
                        <div><div class="nm">{{ it.name }}</div><div class="sub">{{ it.qty }} × {{ money(it.price) }} · с/с {{ money(it.cost) }}</div></div>
                        <div class="num text-ink-2">{{ it.qty }}</div>
                        <div class="num">{{ money(it.qty * it.price) }}</div>
                        <div class="text-ink-3" style="text-align:center">✕</div>
                    </div>
                    <div v-if="!cur.items.length" class="text-ink-3" style="padding:12px 0;font-size:14px">Добавьте товары со склада (списываются по FIFO)</div>
                </div>

                <div class="sale-sums">
                    <div class="ss-row"><span class="text-ink-2 text-[14px]">Выручка</span><span class="tnum text-[15px] font-semibold">{{ money(cur.sum) }}</span></div>
                    <div class="ss-row"><span class="text-ink-2 text-[14px]">Себестоимость (FIFO)</span><span class="tnum text-[15px] text-ink-2">{{ money(cur.cost) }}</span></div>
                    <div class="ss-row ss-row--total"><span class="text-[14px] font-semibold">Прибыль · {{ curMargin }}%</span><span class="tnum text-[18px] font-bold" :style="{ color: 'var(--income)' }">{{ money(curProfit) }}</span></div>
                </div>
            </template>

            <template #footer>
                <button class="btn-primary pressable" style="flex:1;justify-content:center">
                    {{ cur && cur.status === 'Счёт' ? 'Провести (Отгрузить)' : 'Сохранить' }}
                </button>
                <button class="btn-ghost pressable">Оплачено сразу</button>
                <button class="btn-ghost pressable">Цепочка</button>
            </template>
        </AppModal>
    </AppShell>
</template>
