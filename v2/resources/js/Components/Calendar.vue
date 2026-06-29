<script setup lang="ts">
import { ref, computed } from 'vue';
import Icon from '@/Components/Icon.vue';

const monthNames = ['Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь', 'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь'];

// Демо-данные ETA (Фаза 0). В Фазе 1 придут из регистра поставок.
const etas: Record<string, { name: string; cp: string; color: string }> = {
    '2026-07-02': { name: 'Партия кабеля', cp: 'ООО Электро', color: 'var(--income)' },
    '2026-07-10': { name: 'Насосы Grundfos', cp: 'ИП Сидоров', color: 'var(--warn)' },
    '2026-07-12': { name: 'Кабель-канал', cp: 'ООО Профиль', color: 'var(--ink-3)' },
};
const today = '2026-06-29';

const cur = ref(new Date(2026, 6, 1)); // Июль 2026

const title = computed(() => `${monthNames[cur.value.getMonth()]} ${cur.value.getFullYear()}`);

const cells = computed(() => {
    const y = cur.value.getFullYear();
    const m = cur.value.getMonth();
    const lead = (new Date(y, m, 1).getDay() + 6) % 7; // Пн = 0
    const dim = new Date(y, m + 1, 0).getDate();
    const arr: ({ d: number; key: string; eta?: { name: string; cp: string; color: string }; today: boolean } | null)[] = [];
    for (let i = 0; i < lead; i++) arr.push(null);
    for (let d = 1; d <= dim; d++) {
        const key = `${y}-${String(m + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
        arr.push({ d, key, eta: etas[key], today: key === today });
    }
    return arr;
});

const step = (n: number) => {
    cur.value = new Date(cur.value.getFullYear(), cur.value.getMonth() + n, 1);
};
</script>

<template>
    <div class="glass flex flex-col p-4">
        <div class="mb-3.5 flex items-center justify-between">
            <span class="text-[13px] font-semibold uppercase tracking-wide text-ink-2">Календарь поставок</span>
            <div class="flex items-center gap-2">
                <button class="pressable flex h-7 w-7 items-center justify-center rounded-full text-ink-2 hover:text-ink" @click="step(-1)">
                    <Icon name="chevron-left" :size="18" />
                </button>
                <span class="min-w-[104px] text-center text-[14px] font-semibold">{{ title }}</span>
                <button class="pressable flex h-7 w-7 items-center justify-center rounded-full text-ink-2 hover:text-ink" @click="step(1)">
                    <Icon name="chevron-right" :size="18" />
                </button>
            </div>
        </div>

        <div class="cal-head">
            <span>Пн</span><span>Вт</span><span>Ср</span><span>Чт</span><span>Пт</span><span>Сб</span><span>Вс</span>
        </div>
        <div class="cal-grid">
            <template v-for="(c, i) in cells" :key="i">
                <div v-if="!c"></div>
                <div
                    v-else
                    class="cal-day"
                    :class="{ 'has-eta': c.eta, today: c.today }"
                    :data-tip="c.eta ? `${c.eta.name} · ${c.eta.cp}` : undefined"
                >
                    <span>{{ c.d }}</span>
                    <span v-if="c.eta" class="cal-dot" :style="`background:${c.eta.color}`"></span>
                </div>
            </template>
        </div>
    </div>
</template>
