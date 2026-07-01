<script setup lang="ts">
import { ref, computed, watch, onMounted, onUnmounted } from 'vue';
import Icon from '@/Components/Icon.vue';
import { date as fdate } from '@/lib/format';

const props = defineProps<{ modelValue: string; placeholder?: string }>();
const emit = defineEmits<{ (e: 'update:modelValue', v: string): void }>();

const monthNames = ['Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь', 'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь'];
const pad = (n: number) => String(n).padStart(2, '0');
const toKey = (y: number, m: number, d: number) => `${y}-${pad(m + 1)}-${pad(d)}`;

function parseVal(v: string): Date | null {
    if (!v) return null;
    const d = new Date(v + 'T00:00:00');
    return isNaN(d.getTime()) ? null : d;
}

const open = ref(false);
const root = ref<HTMLElement | null>(null);
const cur = ref(parseVal(props.modelValue) ?? new Date());

watch(() => props.modelValue, (v) => {
    const d = parseVal(v);
    if (d) cur.value = d;
});

const title = computed(() => `${monthNames[cur.value.getMonth()]} ${cur.value.getFullYear()}`);
const now = new Date();
const todayKey = toKey(now.getFullYear(), now.getMonth(), now.getDate());
const selectedKey = computed(() => props.modelValue || '');

const cells = computed(() => {
    const y = cur.value.getFullYear();
    const m = cur.value.getMonth();
    const lead = (new Date(y, m, 1).getDay() + 6) % 7; // Пн = 0
    const dim = new Date(y, m + 1, 0).getDate();
    const arr: ({ d: number; key: string } | null)[] = [];
    for (let i = 0; i < lead; i++) arr.push(null);
    for (let d = 1; d <= dim; d++) arr.push({ d, key: toKey(y, m, d) });
    return arr;
});

function step(n: number) { cur.value = new Date(cur.value.getFullYear(), cur.value.getMonth() + n, 1); }
function pick(key: string) { emit('update:modelValue', key); open.value = false; }
function clear() { emit('update:modelValue', ''); open.value = false; }
function toggle() {
    open.value = !open.value;
    if (open.value) cur.value = parseVal(props.modelValue) ?? new Date();
}
function onClickOutside(e: MouseEvent) {
    if (root.value && !root.value.contains(e.target as Node)) open.value = false;
}
onMounted(() => document.addEventListener('mousedown', onClickOutside));
onUnmounted(() => document.removeEventListener('mousedown', onClickOutside));
</script>

<template>
    <div ref="root" class="dpick" :class="{ open }">
        <button type="button" class="dpick-input" @click="toggle">
            <span :class="{ 'dpick-ph': !modelValue }">{{ modelValue ? fdate(modelValue) : (placeholder ?? 'Выбрать дату') }}</span>
            <Icon name="calendar" :size="16" class="dpick-ic" />
        </button>
        <div v-if="open" class="dpick-pop glass-strong">
            <div class="dpick-head">
                <button type="button" class="pressable dpick-nav" @click="step(-1)"><Icon name="chevron-left" :size="16" /></button>
                <span>{{ title }}</span>
                <button type="button" class="pressable dpick-nav" @click="step(1)"><Icon name="chevron-right" :size="16" /></button>
            </div>
            <div class="dpick-wd">
                <span>Пн</span><span>Вт</span><span>Ср</span><span>Чт</span><span>Пт</span><span>Сб</span><span>Вс</span>
            </div>
            <div class="dpick-grid">
                <template v-for="(c, i) in cells" :key="i">
                    <div v-if="!c"></div>
                    <button
                        v-else
                        type="button"
                        class="dpick-day"
                        :class="{ today: c.key === todayKey, sel: c.key === selectedKey }"
                        @click="pick(c.key)"
                    >{{ c.d }}</button>
                </template>
            </div>
            <button v-if="modelValue" type="button" class="dpick-clear" @click="clear">Очистить</button>
        </div>
    </div>
</template>

<style scoped>
.dpick { position: relative; }
.dpick-input { width: 100%; display: flex; align-items: center; justify-content: space-between; border: 1px solid var(--glass-border); background: var(--glass-fill); border-radius: 12px; padding: 0 12px; height: 42px; box-sizing: border-box; color: var(--ink); font-size: 14px; font-family: inherit; cursor: pointer; text-align: left; }
.dpick.open .dpick-input { border-color: var(--ink-3); }
.dpick-ph { color: var(--ink-3); }
.dpick-ic { color: var(--ink-3); flex-shrink: 0; }
.dpick-pop { position: absolute; left: 0; top: calc(100% + 6px); z-index: 30; width: 280px; border-radius: 16px; padding: 12px; }
.dpick-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; font-size: 14px; font-weight: 600; }
.dpick-nav { display: flex; align-items: center; justify-content: center; width: 26px; height: 26px; border-radius: 999px; color: var(--ink-2); }
.dpick-wd { display: grid; grid-template-columns: repeat(7, 1fr); text-align: center; font-size: 11px; color: var(--ink-3); margin-bottom: 4px; }
.dpick-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 2px; }
.dpick-day { aspect-ratio: 1; display: flex; align-items: center; justify-content: center; border-radius: 9px; font-size: 13px; background: transparent; border: 0; cursor: pointer; color: var(--ink); }
.dpick-day:hover { background: var(--glass-fill); }
.dpick-day.today { font-weight: 700; color: var(--info, #0a84ff); }
.dpick-day.sel { background: var(--info, #0a84ff); color: #fff; }
.dpick-clear { width: 100%; margin-top: 8px; padding: 8px; border-radius: 9px; font-size: 13px; color: var(--ink-2); background: transparent; border: 1px solid var(--glass-border); cursor: pointer; }
</style>
