<script setup lang="ts">
import { ref, computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AppShell from '@/Layouts/AppShell.vue';
import Icon from '@/Components/Icon.vue';
import AppModal from '@/Components/AppModal.vue';
import DatePicker from '@/Components/DatePicker.vue';
import { money, num, date as fdate } from '@/lib/format';

const props = defineProps<{
    settings: any; fuelUps: any[]; trips: any[]; washes: any[]; topups: any[];
    monthSpend: number; monthKm: number;
}>();

const tab = ref<'fuel' | 'trips' | 'wash' | 'card'>('fuel');
const today = new Date().toISOString().slice(0, 10);

const fuelTotal = computed(() => props.fuelUps.reduce((a, x) => a + Number(x.sum), 0));
const tripsKm = computed(() => props.trips.reduce((a, x) => a + Number(x.km), 0));
const washTotal = computed(() => props.washes.reduce((a, x) => a + Number(x.sum), 0));

// Формы
const open = ref(false);
const fuel = useForm({ date: today, azs: '', liters: 0, price: 0, odometer: null as number | null, paid_from: 'card' });
const trip = useForm({ date: today, route: '', km: 0, fuel: 0, goal: '' });
const wash = useForm({ date: today, place: '', type: '', sum: 0 });
const topup = useForm({ date: today, sum: 0, source: '' });

function add() { open.value = true; }
function submit() {
    const opts = { onSuccess: () => { open.value = false; } };
    if (tab.value === 'fuel') fuel.post('/vehicle/fuel', opts);
    else if (tab.value === 'trips') trip.post('/vehicle/trip', opts);
    else if (tab.value === 'wash') wash.post('/vehicle/wash', opts);
    else topup.post('/vehicle/topup', opts);
}
const addLabel = computed(() => ({ fuel: 'Заправка', trips: 'Маршрут', wash: 'Мойка', card: 'Пополнение' }[tab.value]));
</script>

<template>
    <Head title="Транспорт" />
    <AppShell>
        <div class="toolbar">
            <h1>Транспорт</h1>
            <button class="btn-primary pressable" style="margin-left:auto" @click="add"><Icon name="plus" :size="17" /> {{ addLabel }}</button>
        </div>

        <div class="veh-hero">
            <div class="vh glass">
                <div class="vh-l">Остаток топлива</div>
                <div class="vh-v tnum">{{ num(settings.fuel_left) }} л <span class="vh-pct">/ {{ num(settings.tank) }} л</span></div>
                <div class="gauge"><div class="gauge-fill" :class="{ 'gauge-fill--low': settings.fuel_pct < 25 }" :style="{ width: settings.fuel_pct + '%' }"></div></div>
                <div class="vh-s">{{ settings.fuel_pct }}% · хватит на ~{{ settings.range_km }} км</div>
            </div>
            <div class="vh glass"><div class="vh-l">Одометр</div><div class="vh-v tnum">{{ num(settings.odometer) }} км</div><div class="vh-s">расход {{ settings.consumption }} л/100км</div></div>
            <div class="vh glass"><div class="vh-l">Топливная карта</div><div class="vh-v tnum">{{ money(settings.card_balance) }}</div><div class="vh-s">с неё списываются АЗС и мойки</div></div>
            <div class="vh glass"><div class="vh-l">За месяц</div><div class="vh-v tnum">{{ money(monthSpend) }}</div><div class="vh-s">{{ num(monthKm) }} км пробега</div></div>
        </div>

        <div class="seg veh-tabs">
            <button :class="{ on: tab === 'fuel' }" @click="tab = 'fuel'">Заправки</button>
            <button :class="{ on: tab === 'trips' }" @click="tab = 'trips'">Маршруты</button>
            <button :class="{ on: tab === 'wash' }" @click="tab = 'wash'">Мойки</button>
            <button :class="{ on: tab === 'card' }" @click="tab = 'card'">Топливная карта</button>
        </div>

        <div class="jcard glass">
            <div class="jscroll">
                <table v-if="tab === 'fuel'" class="jtable">
                    <thead><tr><th>Дата</th><th>АЗС</th><th class="num">Литры</th><th class="num">Цена/л</th><th class="num">Сумма</th><th class="num">Одометр</th></tr></thead>
                    <tbody>
                        <tr v-for="x in fuelUps" :key="x.id"><td class="text-ink-2">{{ fdate(x.date) }}</td><td>{{ x.azs }}</td><td class="num">{{ num(x.liters) }} л</td><td class="num text-ink-2">{{ Number(x.price).toFixed(2) }} ₽</td><td class="num">{{ money(x.sum) }}</td><td class="num text-ink-2">{{ x.odometer ? num(x.odometer) : '—' }}</td></tr>
                        <tr v-if="fuelUps.length"><td colspan="4">Итого за период</td><td class="num" style="font-weight:700">{{ money(fuelTotal) }}</td><td></td></tr>
                        <tr v-if="!fuelUps.length"><td colspan="6"><div class="j-empty">Заправок нет</div></td></tr>
                    </tbody>
                </table>
                <table v-else-if="tab === 'trips'" class="jtable">
                    <thead><tr><th>Дата</th><th>Маршрут</th><th class="num">Км</th><th class="num">Топливо</th><th>Цель</th></tr></thead>
                    <tbody>
                        <tr v-for="x in trips" :key="x.id"><td class="text-ink-2">{{ fdate(x.date) }}</td><td>{{ x.route }}</td><td class="num">{{ num(x.km) }}</td><td class="num text-ink-2">{{ Number(x.fuel).toFixed(1) }} л</td><td class="text-ink-2">{{ x.goal }}</td></tr>
                        <tr v-if="trips.length"><td colspan="2">Итого пробег</td><td class="num" style="font-weight:700">{{ num(tripsKm) }} км</td><td colspan="2"></td></tr>
                        <tr v-if="!trips.length"><td colspan="5"><div class="j-empty">Маршрутов нет</div></td></tr>
                    </tbody>
                </table>
                <table v-else-if="tab === 'wash'" class="jtable">
                    <thead><tr><th>Дата</th><th>Место</th><th>Тип</th><th class="num">Сумма</th></tr></thead>
                    <tbody>
                        <tr v-for="x in washes" :key="x.id"><td class="text-ink-2">{{ fdate(x.date) }}</td><td>{{ x.place }}</td><td class="text-ink-2">{{ x.type }}</td><td class="num">{{ money(x.sum) }}</td></tr>
                        <tr v-if="washes.length"><td colspan="3">Итого за период</td><td class="num" style="font-weight:700">{{ money(washTotal) }}</td></tr>
                        <tr v-if="!washes.length"><td colspan="4"><div class="j-empty">Моек нет</div></td></tr>
                    </tbody>
                </table>
                <table v-else class="jtable">
                    <thead><tr><th>Дата</th><th class="num">Пополнение</th><th>Источник</th></tr></thead>
                    <tbody>
                        <tr v-for="x in topups" :key="x.id"><td class="text-ink-2">{{ fdate(x.date) }}</td><td class="num" :style="{ color: 'var(--income)' }">+ {{ money(x.sum) }}</td><td class="text-ink-2">{{ x.source }}</td></tr>
                        <tr><td>Текущий баланс</td><td class="num" style="font-weight:700">{{ money(settings.card_balance) }}</td><td></td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <AppModal :open="open" :title="addLabel" @close="open = false">
            <template v-if="tab === 'fuel'">
                <div class="fld-row"><div class="fld"><label>Дата</label><DatePicker v-model="fuel.date" /></div><div class="fld"><label>АЗС</label><input v-model="fuel.azs" /></div></div>
                <div class="fld-row"><div class="fld"><label>Литры</label><input v-model.number="fuel.liters" type="number" /></div><div class="fld"><label>Цена/л</label><input v-model.number="fuel.price" type="number" step="0.01" /></div></div>
                <div class="fld-row"><div class="fld"><label>Одометр</label><input v-model.number="fuel.odometer" type="number" /></div><div class="fld"><label>Оплата</label><select v-model="fuel.paid_from"><option value="card">Топл. карта</option><option value="cash">Наличные</option></select></div></div>
            </template>
            <template v-else-if="tab === 'trips'">
                <div class="fld"><label>Дата</label><DatePicker v-model="trip.date" /></div>
                <div class="fld"><label>Маршрут</label><input v-model="trip.route" /></div>
                <div class="fld-row"><div class="fld"><label>Км</label><input v-model.number="trip.km" type="number" /></div><div class="fld"><label>Топливо, л (авто)</label><input v-model.number="trip.fuel" type="number" /></div></div>
                <div class="fld"><label>Цель</label><input v-model="trip.goal" /></div>
            </template>
            <template v-else-if="tab === 'wash'">
                <div class="fld-row"><div class="fld"><label>Дата</label><DatePicker v-model="wash.date" /></div><div class="fld"><label>Сумма</label><input v-model.number="wash.sum" type="number" /></div></div>
                <div class="fld"><label>Место</label><input v-model="wash.place" /></div>
                <div class="fld"><label>Тип</label><input v-model="wash.type" /></div>
            </template>
            <template v-else>
                <div class="fld-row"><div class="fld"><label>Дата</label><DatePicker v-model="topup.date" /></div><div class="fld"><label>Сумма</label><input v-model.number="topup.sum" type="number" /></div></div>
                <div class="fld"><label>Источник</label><input v-model="topup.source" placeholder="перевод со счёта…" /></div>
            </template>
            <template #footer>
                <button class="btn-primary pressable" style="flex:1;justify-content:center" @click="submit">Добавить</button>
            </template>
        </AppModal>

        <div class="set-hint" style="margin-top:12px">Расходы на топливо и мойки попадают в P&L по статье «Транспорт / Топливо».</div>
    </AppShell>
</template>
