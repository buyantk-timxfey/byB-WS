<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AppShell from '@/Layouts/AppShell.vue';
import Widget from '@/Components/Widget.vue';
import Ring from '@/Components/Ring.vue';
import Icon from '@/Components/Icon.vue';

// Демо-данные (Phase 0 — каркас UI; реальные данные подключим в Фазе 1).
const money = (n: number) => new Intl.NumberFormat('ru-RU').format(n) + ' ₽';

const kpis = [
    { label: 'Выручка', value: 1284000, delta: '+12%' },
    { label: 'Прибыль', value: 312400, delta: '+8%' },
    { label: 'Маржа', value: '24.3%', delta: '+1.2пп' },
];

const accounts = [
    { name: 'Тинькофф · Расчётный', balance: 842300 },
    { name: 'Сбербанк · Бизнес', balance: 156800 },
    { name: 'Касса', balance: 24500 },
];

const shipments = [
    { name: 'Партия кабеля', cp: 'ООО Электро', pct: 72, color: 'var(--income)' },
    { name: 'Насосы Grundfos', cp: 'ИП Сидоров', pct: 35, color: 'var(--warn)' },
    { name: 'Светильники', cp: 'ООО Лайт', pct: 100, color: 'var(--income)' },
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
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-6">
            <!-- KPI -->
            <Widget
                v-for="k in kpis"
                :key="k.label"
                :title="k.label"
                class="col-span-1 lg:col-span-2"
            >
                <div class="flex items-end justify-between">
                    <span class="text-[26px] font-bold tracking-tight tnum">
                        {{ typeof k.value === 'number' ? money(k.value) : k.value }}
                    </span>
                    <span class="pill" style="background: rgba(52, 199, 89, 0.16); color: var(--income)">
                        {{ k.delta }}
                    </span>
                </div>
            </Widget>

            <!-- Деньги по счетам (Apple Card style) -->
            <Widget title="Деньги по счетам" class="col-span-2 lg:col-span-3">
                <div class="flex flex-col gap-2">
                    <div
                        v-for="a in accounts"
                        :key="a.name"
                        class="flex items-center justify-between rounded-control px-3 py-2.5"
                        style="background: var(--glass-fill)"
                    >
                        <div class="flex items-center gap-3">
                            <span class="flex h-8 w-8 items-center justify-center rounded-control text-ink-2" style="background: var(--glass-fill-strong)">
                                <Icon name="wallet" :size="18" />
                            </span>
                            <span class="text-[15px] font-medium">{{ a.name }}</span>
                        </div>
                        <span class="text-[15px] font-semibold tnum">{{ money(a.balance) }}</span>
                    </div>
                </div>
            </Widget>

            <!-- Долги -->
            <Widget title="Взаиморасчёты" class="col-span-2 lg:col-span-3">
                <div class="grid grid-cols-2 gap-3">
                    <div class="rounded-control p-3" style="background: var(--glass-fill)">
                        <div class="text-[13px] text-ink-2">Нам должны</div>
                        <div class="mt-1 text-[22px] font-bold tnum" style="color: var(--income)">
                            {{ money(428000) }}
                        </div>
                    </div>
                    <div class="rounded-control p-3" style="background: var(--glass-fill)">
                        <div class="text-[13px] text-ink-2">Мы должны</div>
                        <div class="mt-1 text-[22px] font-bold tnum" style="color: var(--expense)">
                            {{ money(213500) }}
                        </div>
                    </div>
                </div>
            </Widget>

            <!-- Трекер поставок -->
            <Widget title="Трекер поставок" class="col-span-2 lg:col-span-3">
                <div class="flex justify-around">
                    <div v-for="s in shipments" :key="s.name" class="flex flex-col items-center gap-2 text-center">
                        <Ring :pct="s.pct" :color="s.color" />
                        <div class="text-[13px] font-medium leading-tight">{{ s.name }}</div>
                        <div class="text-[11px] text-ink-3">{{ s.cp }}</div>
                    </div>
                </div>
            </Widget>

            <!-- Банковская лента (Apple Card) -->
            <Widget title="Последние операции" class="col-span-2 lg:col-span-3">
                <div class="flex flex-col">
                    <div
                        v-for="t in tx"
                        :key="t.who + t.amount"
                        class="flex items-center justify-between border-b border-[var(--glass-border)] py-2.5 last:border-0"
                    >
                        <div class="flex items-center gap-3">
                            <span
                                class="flex h-9 w-9 items-center justify-center rounded-full text-[13px] font-semibold"
                                style="background: var(--glass-fill-strong)"
                            >
                                {{ t.who.slice(0, 2).toUpperCase() }}
                            </span>
                            <div>
                                <div class="text-[15px] font-medium leading-tight">{{ t.who }}</div>
                                <div class="text-[12px] text-ink-3">{{ t.cat }}</div>
                            </div>
                        </div>
                        <span
                            class="text-[15px] font-semibold tnum"
                            :style="{ color: t.kind === 'in' ? 'var(--income)' : 'var(--ink)' }"
                        >
                            {{ t.kind === 'in' ? '+' : '' }}{{ money(t.amount) }}
                        </span>
                    </div>
                </div>
            </Widget>
        </div>
    </AppShell>
</template>
