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
// Ручной ввод в формате дд.мм.гггг → ISO-ключ. Возвращает null, если дата некорректна
// (в т.ч. "31.02.2026" — new Date() в JS такое молча "перекатывает" на март).
function parseTyped(s: string): string | null {
    const m = s.trim().match(/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/);
    if (!m) return null;
    const d = Number(m[1]);
    const mo = Number(m[2]);
    const y = Number(m[3]);
    const dt = new Date(y, mo - 1, d);
    if (dt.getFullYear() !== y || dt.getMonth() !== mo - 1 || dt.getDate() !== d) return null;

    return `${y}-${pad(mo)}-${pad(d)}`;
}

const open = ref(false);
const root = ref<HTMLElement | null>(null);
const popEl = ref<HTMLElement | null>(null);
const cur = ref(parseVal(props.modelValue) ?? new Date());
const popStyle = ref<Record<string, string>>({});
const text = ref(props.modelValue ? fdate(props.modelValue) : '');

watch(() => props.modelValue, (v) => {
    const d = parseVal(v);
    if (d) cur.value = d;
    text.value = v ? fdate(v) : '';
});

// Попап рендерится через Teleport в <body> с fixed-позицией по координатам поля —
// иначе position:absolute внутри модалки раздувает её scrollHeight (особенно
// заметно на Windows, где скроллбар всегда видимый, в отличие от macOS).
function updatePosition() {
    const r = root.value?.getBoundingClientRect();
    if (!r) return;
    popStyle.value = { position: 'fixed', left: `${r.left}px`, top: `${r.bottom + 6}px` };
}

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
function clear() { emit('update:modelValue', ''); text.value = ''; open.value = false; }
function toggle() {
    open.value = !open.value;
    if (open.value) {
        cur.value = parseVal(props.modelValue) ?? new Date();
        updatePosition();
    }
}
function onTextBlur() {
    if (text.value.trim() === '') {
        if (props.modelValue) emit('update:modelValue', '');
        return;
    }
    const iso = parseTyped(text.value);
    if (iso) {
        emit('update:modelValue', iso);
    } else {
        // Некорректный ввод — откатываем к последнему валидному значению
        text.value = props.modelValue ? fdate(props.modelValue) : '';
    }
}
function onTextEnter(e: KeyboardEvent) {
    onTextBlur();
    (e.target as HTMLInputElement).blur();
}
function onClickOutside(e: MouseEvent) {
    const t = e.target as Node;
    // Попап вынесен через Teleport в <body> и физически больше не находится
    // внутри root — проверяем обе части (поле и сам попап), иначе клик по дню/
    // стрелке навигации считался бы "кликом снаружи" и закрывал попап ДО того,
    // как успевал сработать выбор (mousedown срабатывает раньше click).
    if (root.value?.contains(t) || popEl.value?.contains(t)) return;
    open.value = false;
}
onMounted(() => {
    document.addEventListener('mousedown', onClickOutside);
    window.addEventListener('scroll', updatePosition, true);
    window.addEventListener('resize', updatePosition);
});
onUnmounted(() => {
    document.removeEventListener('mousedown', onClickOutside);
    window.removeEventListener('scroll', updatePosition, true);
    window.removeEventListener('resize', updatePosition);
});
</script>

<template>
    <div ref="root" class="dpick" :class="{ open }">
        <div class="dpick-input">
            <input
                v-model="text"
                class="dpick-text"
                :placeholder="placeholder ?? 'дд.мм.гггг'"
                inputmode="numeric"
                @blur="onTextBlur"
                @keydown.enter.prevent="onTextEnter"
            />
            <button type="button" class="dpick-ic-btn" @click="toggle">
                <Icon name="calendar" :size="16" class="dpick-ic" />
            </button>
        </div>
        <Teleport to="body">
            <div v-if="open" ref="popEl" class="dpick-pop glass-strong" :style="popStyle">
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
        </Teleport>
    </div>
</template>

<style scoped>
.dpick { position: relative; }
.dpick-input { width: 100%; display: flex; align-items: center; border: 1px solid var(--glass-border); background: var(--glass-fill); border-radius: 12px; height: 42px; box-sizing: border-box; }
.dpick.open .dpick-input { border-color: var(--ink-3); }
.dpick-text { flex: 1; min-width: 0; height: 100%; border: 0; background: transparent; padding: 0 0 0 12px; color: var(--ink); font-size: 14px; font-family: inherit; outline: none; }
.dpick-text::placeholder { color: var(--ink-3); }
.dpick-ic-btn { flex-shrink: 0; width: 38px; height: 100%; display: flex; align-items: center; justify-content: center; background: transparent; border: 0; cursor: pointer; color: var(--ink-3); }
.dpick-pop { position: fixed; z-index: 100; width: 280px; border-radius: 16px; padding: 12px; transform-origin: top center; animation: pop-in .18s cubic-bezier(.22, 1, .36, 1); }
@keyframes pop-in { from { opacity: 0; transform: scale(.97) translateY(-4px); } to { opacity: 1; transform: none; } }
@media (prefers-reduced-motion: reduce) { .dpick-pop { animation: none; } }
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
