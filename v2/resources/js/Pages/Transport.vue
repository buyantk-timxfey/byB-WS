<script setup lang="ts">
import { ref, computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppShell from '@/Layouts/AppShell.vue';
import Icon from '@/Components/Icon.vue';

const money = (n: number) => new Intl.NumberFormat('ru-RU').format(Math.round(n)) + ' ₽';

// Настройки машины (vehicle_settings)
const tank = 60;            // объём бака, л
const consumption = 8.0;    // расход, л/100км
const odometer = 142350;    // одометр, км
const fuelLeft = 38;        // остаток топлива по калибровке, л
const cardBalance = 12400;  // баланс топливной карты, ₽
const fuelPct = computed(() => Math.round((fuelLeft / tank) * 100));
const rangeKm = computed(() => Math.round((fuelLeft / consumption) * 100));

const tab = ref<'fuel' | 'trips' | 'wash' | 'card'>('fuel');

const fuelUps = ref([
    { date: '28.06.2026', azs: 'Лукойл', liters: 42, price: 58.5, sum: 2457, odo: 142100 },
    { date: '20.06.2026', azs: 'Газпромнефть', liters: 38, price: 57.9, sum: 2200, odo: 141600 },
    { date: '12.06.2026', azs: 'Лукойл', liters: 45, price: 58.2, sum: 2619, odo: 141050 },
]);
const trips = ref([
    { date: '27.06.2026', route: 'Москва → Подольск → Москва', km: 84, fuel: 6.7, goal: 'Доставка ИН-0029' },
    { date: '24.06.2026', route: 'По городу', km: 45, fuel: 3.6, goal: 'Закупка' },
    { date: '22.06.2026', route: 'Москва → Тверь', km: 320, fuel: 25.6, goal: 'Доставка ИН-0028' },
]);
const washes = ref([
    { date: '26.06.2026', place: 'Мойка №1', type: 'Комплекс', sum: 1200 },
    { date: '15.06.2026', place: 'Fast Wash', type: 'Кузов', sum: 600 },
]);
const topups = ref([
    { date: '15.06.2026', sum: 10000, src: 'Перевод со Сбер · 7781' },
    { date: '01.06.2026', sum: 15000, src: 'Перевод со Сбер · 7781' },
]);

const fuelTotal = computed(() => fuelUps.value.reduce((a, x) => a + x.sum, 0));
const tripsKm = computed(() => trips.value.reduce((a, x) => a + x.km, 0));
const washTotal = computed(() => washes.value.reduce((a, x) => a + x.sum, 0));
</script>

<template>
    <Head title="Транспорт" />
    <AppShell>
        <div class="toolbar">
            <h1>Транспорт</h1>
            <button class="btn-ghost pressable" style="margin-left:auto"><Icon name="gear" :size="16" /> Машина</button>
            <button class="btn-primary pressable"><Icon name="plus" :size="17" /> Заправка</button>
        </div>

        <!-- Состояние машины -->
        <div class="veh-hero">
            <div class="vh glass vh-fuel">
                <div class="vh-l">Остаток топлива</div>
                <div class="vh-v tnum">{{ fuelLeft }} л <span class="vh-pct">/ {{ tank }} л</span></div>
                <div class="gauge"><div class="gauge-fill" :class="{ 'gauge-fill--low': fuelPct < 25 }" :style="{ width: fuelPct + '%' }"></div></div>
                <div class="vh-s">{{ fuelPct }}% · хватит на ~{{ rangeKm }} км</div>
            </div>
            <div class="vh glass"><div class="vh-l">Одометр</div><div class="vh-v tnum">{{ new Intl.NumberFormat('ru-RU').format(odometer) }} км</div><div class="vh-s">расход {{ consumption.toFixed(1) }} л/100км</div></div>
            <div class="vh glass"><div class="vh-l">Топливная карта</div><div class="vh-v tnum">{{ money(cardBalance) }}</div><div class="vh-s">с неё списываются АЗС и мойки</div></div>
            <div class="vh glass"><div class="vh-l">За месяц</div><div class="vh-v tnum">{{ money(fuelTotal + washTotal) }}</div><div class="vh-s">{{ tripsKm }} км пробега</div></div>
        </div>

        <div class="seg veh-tabs">
            <button :class="{ on: tab === 'fuel' }" @click="tab = 'fuel'">Заправки</button>
            <button :class="{ on: tab === 'trips' }" @click="tab = 'trips'">Маршруты</button>
            <button :class="{ on: tab === 'wash' }" @click="tab = 'wash'">Мойки</button>
            <button :class="{ on: tab === 'card' }" @click="tab = 'card'">Топливная карта</button>
        </div>

        <div class="jcard glass">
            <div class="jscroll">
                <!-- Заправки -->
                <table v-if="tab === 'fuel'" class="jtable">
                    <thead><tr><th>Дата</th><th>АЗС</th><th class="num">Литры</th><th class="num">Цена/л</th><th class="num">Сумма</th><th class="num">Одометр</th></tr></thead>
                    <tbody>
                        <tr v-for="(x, i) in fuelUps" :key="i">
                            <td class="text-ink-2">{{ x.date }}</td><td>{{ x.azs }}</td>
                            <td class="num">{{ x.liters }} л</td><td class="num text-ink-2">{{ x.price.toFixed(2) }} ₽</td>
                            <td class="num">{{ money(x.sum) }}</td><td class="num text-ink-2">{{ new Intl.NumberFormat('ru-RU').format(x.odo) }}</td>
                        </tr>
                        <tr><td colspan="4">Итого за период</td><td class="num" style="font-weight:700">{{ money(fuelTotal) }}</td><td></td></tr>
                    </tbody>
                </table>
                <!-- Маршруты -->
                <table v-else-if="tab === 'trips'" class="jtable">
                    <thead><tr><th>Дата</th><th>Маршрут</th><th class="num">Км</th><th class="num">Топливо</th><th>Цель</th></tr></thead>
                    <tbody>
                        <tr v-for="(x, i) in trips" :key="i">
                            <td class="text-ink-2">{{ x.date }}</td><td>{{ x.route }}</td>
                            <td class="num">{{ x.km }}</td><td class="num text-ink-2">{{ x.fuel.toFixed(1) }} л</td><td class="text-ink-2">{{ x.goal }}</td>
                        </tr>
                        <tr><td colspan="2">Итого пробег</td><td class="num" style="font-weight:700">{{ tripsKm }} км</td><td colspan="2"></td></tr>
                    </tbody>
                </table>
                <!-- Мойки -->
                <table v-else-if="tab === 'wash'" class="jtable">
                    <thead><tr><th>Дата</th><th>Место</th><th>Тип</th><th class="num">Сумма</th></tr></thead>
                    <tbody>
                        <tr v-for="(x, i) in washes" :key="i">
                            <td class="text-ink-2">{{ x.date }}</td><td>{{ x.place }}</td><td class="text-ink-2">{{ x.type }}</td><td class="num">{{ money(x.sum) }}</td>
                        </tr>
                        <tr><td colspan="3">Итого за период</td><td class="num" style="font-weight:700">{{ money(washTotal) }}</td></tr>
                    </tbody>
                </table>
                <!-- Топливная карта -->
                <table v-else class="jtable">
                    <thead><tr><th>Дата</th><th class="num">Пополнение</th><th>Источник</th></tr></thead>
                    <tbody>
                        <tr v-for="(x, i) in topups" :key="i">
                            <td class="text-ink-2">{{ x.date }}</td><td class="num" :style="{ color: 'var(--income)' }">+ {{ money(x.sum) }}</td><td class="text-ink-2">{{ x.src }}</td>
                        </tr>
                        <tr><td>Текущий баланс</td><td class="num" style="font-weight:700">{{ money(cardBalance) }}</td><td></td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="set-hint" style="margin-top:12px">Расходы на топливо и мойки попадают в P&L по статье «Транспорт / Топливо». Остаток топлива считается по калибровке бака и среднему расходу.</div>
    </AppShell>
</template>
