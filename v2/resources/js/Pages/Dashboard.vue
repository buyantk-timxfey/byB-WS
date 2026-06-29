<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AppShell from '@/Layouts/AppShell.vue';
import Icon from '@/Components/Icon.vue';
import Sparkline from '@/Components/Sparkline.vue';
import Calendar from '@/Components/Calendar.vue';

// Демо-данные (Phase 0 — каркас UI; реальные данные в Фазе 1).
const money = (n: number) => new Intl.NumberFormat('ru-RU').format(n) + ' ₽';

const kpis = [
    { label: 'Выручка', value: '1 284 000 ₽', delta: '+12%', down: false, spark: [26, 28, 27, 34, 31, 40, 42, 48], color: 'var(--income)' },
    { label: 'Прибыль', value: '312 400 ₽', delta: '+8%', down: false, spark: [22, 24, 23, 30, 27, 33, 35, 40], color: 'var(--income)' },
    { label: 'Маржа', value: '24.3%', delta: '+1.2пп', down: false, spark: [20, 19, 22, 21, 24, 23, 26, 28], color: 'var(--income)' },
    { label: 'ROI', value: '38%', delta: '+3пп', down: false, spark: [21, 23, 22, 26, 28, 27, 32, 34], color: 'var(--income)' },
    { label: 'Закупки', value: '972 000 ₽', delta: '+6%', down: true, spark: [30, 28, 31, 26, 27, 24, 25, 22], color: 'var(--expense)' },
];

const accounts = [
    { name: 'Тинькофф', balance: 842300 },
    { name: 'Сбербанк', balance: 156800 },
    { name: 'Касса', balance: 24500 },
];
const totalBalance = accounts.reduce((s, a) => s + a.balance, 0);

const unrec = { count: 5, amount: 248500 };

const C = 214; // длина окружности r=34
const shipments = [
    { cp: 'ООО Электро', name: 'Партия кабеля', kind: 'pct', pct: 72, color: 'var(--income)', dates: '10.06 → 02.07' },
    { cp: 'ИП Сидоров', name: 'Насосы Grundfos', kind: 'pct', pct: 35, color: 'var(--warn)', dates: '20.06 → 10.07' },
    { cp: 'ООО Профиль', name: 'Кабель-канал', kind: 'wait', pct: 0, color: 'var(--ink-3)', dates: '27.06 → 12.07' },
    { cp: 'ИП Кузнецов', name: 'Автоматы ABB', kind: 'overdue', pct: 100, color: 'var(--expense)', dates: '01.06 → 25.06' },
];

const warehouse = {
    frozen: 1480000,
    positions: 48,
    stale: [
        { name: 'Автоматы ABB', days: 95, cost: 142000, warn: true },
        { name: 'Кабель ВВГ 3×2.5', days: 62, cost: 88000, warn: true },
        { name: 'Светильники LED', days: 47, cost: 54000, warn: false },
    ],
};

const mailboxes = [
    { addr: 'info@bybuka.ru', unread: 3, letters: [
        { from: 'ООО Электро', sub: 'Счёт на оплату №1242' },
        { from: 'ИП Сидоров', sub: 'Отгрузка готова, трек 1Z…' },
    ] },
    { addr: 'zakaz@bybuka.ru', unread: 1, letters: [
        { from: 'ООО Лайт', sub: 'Запрос КП на насосы Grundfos' },
    ] },
];

const tx = [
    { who: 'ООО Электро', cat: 'Продажа', amount: 184000, kind: 'in' },
    { who: 'ИП Сидоров', cat: 'Закупка', amount: -96500, kind: 'out' },
    { who: 'Аренда склада', cat: 'Расход', amount: -45000, kind: 'out' },
    { who: 'ООО Лайт', cat: 'Продажа', amount: 62300, kind: 'in' },
    { who: 'Эквайринг', cat: 'Комиссия', amount: -2245, kind: 'out' },
];
</script>

<template>
    <Head title="Дашборд" />

    <AppShell>
        <!-- Ряд 1: KPI (S) -->
        <div class="sec">
            <div v-for="k in kpis" :key="k.label" class="glass w-pad wgt-s pressable">
                <div class="flex items-center justify-between">
                    <span class="h2">{{ k.label }}</span>
                    <span class="pill" :class="{ 'pill-down': k.down }" :style="k.down ? '' : 'background:rgba(52,199,89,.16);color:var(--income)'">{{ k.delta }}</span>
                </div>
                <div class="kpinum tnum">{{ k.value }}</div>
                <Sparkline :data="k.spark" :color="k.color" class="kpi-spark" />
            </div>
        </div>

        <!-- Ряд 2: сигнал (M) + поставки (S) -->
        <div class="sec">
            <div class="glass w-pad wgt-m pressable" style="flex-direction:row;align-items:center;justify-content:space-between;gap:12px">
                <div class="flex items-center gap-3" style="min-width:0">
                    <span class="chip" style="width:38px;height:38px;color:var(--warn)"><Icon name="alert" :size="20" /></span>
                    <div style="min-width:0">
                        <div class="text-[14px] font-semibold">Неразнесённые строки</div>
                        <div class="text-[12px] text-ink-2">{{ unrec.count }} операций · {{ money(unrec.amount) }}</div>
                    </div>
                </div>
                <button class="inkbtn pressable">Свести</button>
            </div>

            <div v-for="s in shipments" :key="s.name" class="glass w-pad wgt-s shipw pressable">
                <div class="cp">{{ s.cp }}</div>
                <div class="nm">{{ s.name }}</div>
                <div class="ring-s">
                    <svg viewBox="0 0 100 100" width="60" height="60">
                        <circle v-if="s.kind !== 'overdue'" cx="50" cy="50" r="34" fill="none" stroke="var(--glass-border)" stroke-width="9" />
                        <circle v-if="s.kind === 'pct'" cx="50" cy="50" r="34" fill="none" :stroke="s.color" stroke-width="9" stroke-linecap="round" :stroke-dasharray="C" :stroke-dashoffset="C * (1 - s.pct / 100)" transform="rotate(-90 50 50)" />
                        <circle v-if="s.kind === 'overdue'" cx="50" cy="50" r="34" fill="none" stroke="var(--expense)" stroke-width="9" />
                    </svg>
                    <span v-if="s.kind === 'pct'">{{ s.pct }}%</span>
                    <span v-else-if="s.kind === 'wait'" style="font-size:11px;color:var(--ink-2)">Ожидает</span>
                    <span v-else style="font-size:20px;font-weight:700;color:var(--expense)">×</span>
                </div>
                <div class="dts">{{ s.dates }}</div>
            </div>
        </div>

        <!-- Ряд 3: крупные виджеты (L) -->
        <div class="sec" style="max-width:760px">
            <!-- Деньги по счетам -->
            <div class="glass w-pad wgt-l">
                <span class="h2">Деньги по счетам</span>
                <div class="flex items-center justify-between" style="margin-top:6px">
                    <span class="text-[12px] text-ink-2">Всего</span>
                    <span class="tnum" style="font-size:24px;font-weight:700">{{ money(totalBalance) }}</span>
                </div>
                <div class="accw">
                    <div v-for="a in accounts" :key="a.name" class="it">
                        <div class="flex items-center gap-3" style="min-width:0">
                            <span class="chip"><Icon name="wallet" :size="16" /></span>
                            <span class="text-[14px] font-medium">{{ a.name }}</span>
                        </div>
                        <span class="text-[14px] font-semibold tnum">{{ money(a.balance) }}</span>
                    </div>
                </div>
            </div>

            <!-- Календарь ETA -->
            <Calendar class="wgt-l overflow-hidden" />

            <!-- Последние операции (Apple Card) -->
            <div class="glass w-pad wgt-l">
                <span class="h2">Последние операции</span>
                <div class="txw">
                    <div v-for="t in tx" :key="t.who + t.amount" class="t">
                        <div class="left">
                            <span class="av">{{ t.who.slice(0, 2).toUpperCase() }}</span>
                            <div style="min-width:0">
                                <div class="nm">{{ t.who }}</div>
                                <div class="cat">{{ t.cat }}</div>
                            </div>
                        </div>
                        <span class="text-[14px] font-semibold tnum" :style="{ color: t.kind === 'in' ? 'var(--income)' : 'var(--ink)' }">
                            {{ t.kind === 'in' ? '+' : '' }}{{ money(t.amount) }}
                        </span>
                    </div>
                </div>
            </div>

            <!-- Склад -->
            <div class="glass w-pad wgt-l">
                <span class="h2">Склад</span>
                <div class="text-[12px] text-ink-2" style="margin-top:8px">Замороженные деньги</div>
                <div class="tnum" style="font-size:24px;font-weight:700">{{ money(warehouse.frozen) }}</div>
                <div class="text-[12px] text-ink-3" style="margin-top:2px">{{ warehouse.positions }} позиций на складе</div>
                <div class="h2" style="margin:14px 0 4px">Залежалое · 30+ дней</div>
                <div class="txw">
                    <div v-for="p in warehouse.stale" :key="p.name" class="t">
                        <div class="left">
                            <span class="av" :style="p.warn ? 'color:var(--warn)' : 'color:var(--ink-2)'">{{ p.days }}</span>
                            <div style="min-width:0">
                                <div class="nm">{{ p.name }}</div>
                                <div class="cat">дней на складе</div>
                            </div>
                        </div>
                        <span class="text-[14px] font-semibold tnum">{{ money(p.cost) }}</span>
                    </div>
                </div>
            </div>

            <!-- Почта по ящикам -->
            <div class="glass w-pad wgt-l">
                <span class="h2">Почта</span>
                <div v-for="(mb, idx) in mailboxes" :key="mb.addr" :style="idx ? 'margin-top:16px' : 'margin-top:10px'">
                    <div class="flex items-center justify-between" style="margin-bottom:4px">
                        <span class="text-[13px] font-semibold">{{ mb.addr }}</span>
                        <span class="pill" style="background:rgba(10,132,255,.18);color:#0a84ff">{{ mb.unread }} нов.</span>
                    </div>
                    <div v-for="(lt, i) in mb.letters" :key="i" class="mletter">
                        <b>{{ lt.from }}</b> · {{ lt.sub }}
                    </div>
                </div>
            </div>
        </div>
    </AppShell>
</template>
