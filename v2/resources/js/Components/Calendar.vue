<script setup lang="ts">
import { ref, computed } from 'vue';
import Icon from '@/Components/Icon.vue';

// data: { 'YYYY-MM-DD': [{ name, cp }] } — ETA поставок «в пути» из регистра
const props = defineProps<{ data?: Record<string, { name: string; cp: string }[]> }>();

const monthNames = ['Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь', 'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь'];
const pad = (n: number) => String(n).padStart(2, '0');
const now = new Date();
const todayKey = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;

const cur = ref(new Date(now.getFullYear(), now.getMonth(), 1));
const title = computed(() => `${monthNames[cur.value.getMonth()]} ${cur.value.getFullYear()}`);

const cells = computed(() => {
    const y = cur.value.getFullYear();
    const m = cur.value.getMonth();
    const lead = (new Date(y, m, 1).getDay() + 6) % 7; // Пн = 0
    const dim = new Date(y, m + 1, 0).getDate();
    const data = props.data ?? {};
    const arr: ({ d: number; key: string; eta?: { tip: string }; today: boolean } | null)[] = [];
    for (let i = 0; i < lead; i++) arr.push(null);
    for (let d = 1; d <= dim; d++) {
        const key = `${y}-${pad(m + 1)}-${pad(d)}`;
        const list = data[key];
        arr.push({
            d, key, today: key === todayKey,
            eta: list && list.length ? { tip: list.map((e) => `${e.name} · ${e.cp}`).join('\n') } : undefined,
        });
    }
    return arr;
});

const step = (n: number) => { cur.value = new Date(cur.value.getFullYear(), cur.value.getMonth() + n, 1); };
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
                <div v-else class="cal-day" :class="{ 'has-eta': c.eta, today: c.today }" :title="c.eta?.tip">
                    <span>{{ c.d }}</span>
                    <span v-if="c.eta" class="cal-dot" style="background:var(--info,#0a84ff)"></span>
                </div>
            </template>
        </div>
    </div>
</template>
