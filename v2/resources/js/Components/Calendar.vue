<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { router } from '@inertiajs/vue3';
import Icon from '@/Components/Icon.vue';

// data: { 'YYYY-MM-DD': [{ name, cp }] } — ETA поставок «в пути» из регистра
const props = defineProps<{ data?: Record<string, { name: string; cp: string }[]> }>();

const monthNames = ['Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь', 'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь'];
const pad = (n: number) => String(n).padStart(2, '0');
const now = new Date();
const todayKey = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;

const cur = ref(new Date(now.getFullYear(), now.getMonth(), 1));
const title = computed(() => `${monthNames[cur.value.getMonth()]} ${cur.value.getFullYear()}`);

type Cell = { d: number; key: string; list?: { name: string; cp: string }[]; today: boolean } | null;
const cells = computed(() => {
    const y = cur.value.getFullYear();
    const m = cur.value.getMonth();
    const lead = (new Date(y, m, 1).getDay() + 6) % 7; // Пн = 0
    const dim = new Date(y, m + 1, 0).getDate();
    const data = props.data ?? {};
    const arr: Cell[] = [];
    for (let i = 0; i < lead; i++) arr.push(null);
    for (let d = 1; d <= dim; d++) {
        const key = `${y}-${pad(m + 1)}-${pad(d)}`;
        const list = data[key];
        arr.push({ d, key, today: key === todayKey, list: list && list.length ? list : undefined });
    }
    return arr;
});

const step = (n: number) => { cur.value = new Date(cur.value.getFullYear(), cur.value.getMonth() + n, 1); pop.value = null; };

// ── Поповер: наведение на десктопе, тап на тачах ──
const root = ref<HTMLElement | null>(null);
const pop = ref<{ key: string; list: { name: string; cp: string }[]; x: number; y: number } | null>(null);
let hideTimer: ReturnType<typeof setTimeout> | null = null;

function placePop(cell: Exclude<Cell, null>, el: HTMLElement) {
    if (!root.value || !cell.list) return;
    const rootBox = root.value.getBoundingClientRect();
    const box = el.getBoundingClientRect();
    // Над ячейкой, по центру; x зажимается кламппом в CSS через max-width + translate
    // x зажимаем, чтобы поповер не вылезал за края виджета
    const x = Math.min(Math.max(box.left - rootBox.left + box.width / 2, 100), rootBox.width - 100);
    pop.value = { key: cell.key, list: cell.list, x, y: box.top - rootBox.top };
}
function onEnter(cell: Cell, e: MouseEvent) {
    // mouseenter стреляет и на тачах перед click — вреда нет, click перезапишет
    if (!cell?.list) return;
    if (hideTimer) { clearTimeout(hideTimer); hideTimer = null; }
    placePop(cell, e.currentTarget as HTMLElement);
}
function onLeave() {
    // задержка, чтобы успеть увести курсор в сам поповер (там ссылки)
    hideTimer = setTimeout(() => { pop.value = null; }, 180);
}
function keepPop() { if (hideTimer) { clearTimeout(hideTimer); hideTimer = null; } }
function onTap(cell: Cell, e: MouseEvent) {
    if (!cell?.list) { pop.value = null; return; }
    e.stopPropagation();
    if (pop.value?.key === cell.key) { pop.value = null; return; }
    placePop(cell, e.currentTarget as HTMLElement);
}
const closeOnOutside = () => { pop.value = null; };
onMounted(() => document.addEventListener('click', closeOnOutside));
onUnmounted(() => document.removeEventListener('click', closeOnOutside));

const goShipments = () => router.visit('/shipments');
</script>

<template>
    <div ref="root" class="glass relative flex flex-col p-4">
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
                    v-else class="cal-day" :class="{ 'has-eta': c.list, today: c.today, 'cal-day--open': pop?.key === c.key }"
                    @mouseenter="onEnter(c, $event)" @mouseleave="onLeave" @click="onTap(c, $event)"
                >
                    <span>{{ c.d }}</span>
                    <span v-if="c.list" class="cal-dot" style="background:var(--info,#0a84ff)"></span>
                </div>
            </template>
        </div>

        <!-- Поповер с поставками дня: строка — переход в Поставки -->
        <Transition name="calpop">
            <div
                v-if="pop" class="cal-pop glass-strong"
                :style="{ left: pop.x + 'px', top: pop.y + 'px' }"
                @mouseenter="keepPop" @mouseleave="onLeave" @click.stop
            >
                <button v-for="(e, i) in pop.list" :key="i" type="button" class="cal-pop-row pressable" @click="goShipments">
                    <span class="cal-pop-txt">
                        <b>{{ e.name }}</b>
                        <i>{{ e.cp }}</i>
                    </span>
                    <Icon name="chevron-right" :size="14" class="text-ink-3" style="flex-shrink:0" />
                </button>
            </div>
        </Transition>
    </div>
</template>

<style scoped>
.cal-pop {
    position: absolute;
    z-index: 30;
    transform: translate(-50%, -100%) translateY(-8px);
    min-width: 190px;
    max-width: 250px;
    padding: 6px;
    border-radius: 14px;
    display: flex;
    flex-direction: column;
    gap: 2px;
    /* Плотная подложка вместо стекла: на iOS backdrop-filter внутри другого
       стеклянного слоя не работает, и поповер просвечивал до нечитаемости. */
    background: var(--glass-solid);
}
.cal-pop-row {
    display: flex;
    align-items: center;
    gap: 8px;
    width: 100%;
    text-align: left;
    padding: 8px 10px;
    border: 0;
    border-radius: 10px;
    background: transparent;
    color: var(--ink);
    cursor: pointer;
    font: inherit;
}
.cal-pop-row:hover { background: var(--glass-fill); }
.cal-pop-txt { display: flex; flex-direction: column; min-width: 0; flex: 1; }
.cal-pop-txt b { font-size: 13px; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.cal-pop-txt i { font-style: normal; font-size: 11.5px; color: var(--ink-2); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.cal-day--open { background: var(--glass-fill-strong); }
.calpop-enter-active, .calpop-leave-active { transition: opacity .16s ease, transform .16s cubic-bezier(.22,1,.36,1); }
.calpop-enter-from, .calpop-leave-to { opacity: 0; transform: translate(-50%, -100%) translateY(-2px); }
@media (prefers-reduced-motion: reduce) { .calpop-enter-active, .calpop-leave-active { transition: none; } }
</style>
