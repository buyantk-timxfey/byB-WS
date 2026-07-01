<script setup lang="ts">
import { ref, computed, watch, onMounted, onUnmounted, nextTick } from 'vue';
import Icon from '@/Components/Icon.vue';

const props = defineProps<{
    modelValue: number | null;
    options: { id: number; name: string }[];
    placeholder?: string;
}>();
const emit = defineEmits<{ (e: 'update:modelValue', v: number | null): void }>();

const open = ref(false);
const query = ref('');
const root = ref<HTMLElement | null>(null);
const dropEl = ref<HTMLElement | null>(null);
const inputEl = ref<HTMLInputElement | null>(null);
const highlighted = ref(0);
const dropStyle = ref<Record<string, string>>({});

const selectedName = computed(() => props.options.find((o) => o.id === props.modelValue)?.name ?? '');

const filtered = computed(() => {
    const q = query.value.trim().toLowerCase();
    if (!q) return props.options;
    return props.options.filter((o) => o.name.toLowerCase().includes(q));
});

// Список рендерится через Teleport в <body> с fixed-позицией по координатам поля —
// иначе position:absolute внутри модалки раздувает её scrollHeight и добавляет
// лишнюю прокрутку (особенно заметно на Windows, где скроллбар всегда видимый).
function updatePosition() {
    const r = root.value?.getBoundingClientRect();
    if (!r) return;
    dropStyle.value = {
        position: 'fixed',
        left: `${r.left}px`,
        top: `${r.bottom + 6}px`,
        width: `${r.width}px`,
    };
}

function show() {
    open.value = true;
    query.value = '';
    highlighted.value = 0;
    updatePosition();
    nextTick(() => inputEl.value?.select());
}
function hide() {
    open.value = false;
    query.value = '';
}
function pick(o: { id: number; name: string } | null) {
    emit('update:modelValue', o ? o.id : null);
    hide();
}
function onKey(e: KeyboardEvent) {
    if (e.key === 'Escape') {
        hide();
        inputEl.value?.blur();
    } else if (e.key === 'ArrowDown') {
        e.preventDefault();
        highlighted.value = Math.min(highlighted.value + 1, filtered.value.length - 1);
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        highlighted.value = Math.max(highlighted.value - 1, 0);
    } else if (e.key === 'Enter') {
        e.preventDefault();
        const o = filtered.value[highlighted.value];
        if (o) pick(o);
    }
}
watch(query, () => { highlighted.value = 0; });

function onClickOutside(e: MouseEvent) {
    const t = e.target as Node;
    // Список вынесен через Teleport в <body> и физически больше не находится
    // внутри root — проверяем обе части (поле и сам всплывающий список),
    // иначе клик по любой опции считался бы "кликом снаружи" и закрывал
    // список ДО того, как успевал сработать выбор.
    if (root.value?.contains(t) || dropEl.value?.contains(t)) return;
    hide();
}
// Капчурим scroll (не всплывает), чтобы отслеживать прокрутку и модалки, и страницы.
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
    <div ref="root" class="ssel" :class="{ open }">
        <input
            ref="inputEl"
            :value="open ? query : selectedName"
            :placeholder="placeholder ?? '— выбрать —'"
            autocomplete="off"
            @focus="show"
            @input="query = ($event.target as HTMLInputElement).value"
            @keydown="onKey"
        />
        <Icon name="chevron-down" :size="15" class="ssel-chev" />
        <Teleport to="body">
            <div v-if="open" ref="dropEl" class="ssel-drop glass-strong" :style="dropStyle">
                <button type="button" class="ssel-item" @mousedown.prevent="pick(null)">— выбрать —</button>
                <button
                    v-for="(o, i) in filtered"
                    :key="o.id"
                    type="button"
                    class="ssel-item"
                    :class="{ hi: i === highlighted, sel: o.id === modelValue }"
                    @mousedown.prevent="pick(o)"
                >{{ o.name }}</button>
                <div v-if="!filtered.length" class="ssel-empty">Ничего не найдено</div>
            </div>
        </Teleport>
    </div>
</template>

<style scoped>
.ssel { position: relative; }
.ssel input { width: 100%; border: 1px solid var(--glass-border); background: var(--glass-fill); border-radius: 12px; padding: 0 30px 0 12px; height: 42px; box-sizing: border-box; color: var(--ink); font-size: 14px; font-family: inherit; outline: none; }
.ssel.open input, .ssel input:focus { border-color: var(--ink-3); }
.ssel-chev { position: absolute; right: 10px; top: 50%; transform: translateY(-50%); color: var(--ink-3); pointer-events: none; }
.ssel-drop { position: fixed; z-index: 100; max-height: 260px; overflow-y: auto; border-radius: 14px; padding: 6px; }
.ssel-item { display: block; width: 100%; text-align: left; padding: 9px 10px; border-radius: 9px; font-size: 14px; background: transparent; border: 0; cursor: pointer; color: var(--ink); }
.ssel-item.hi, .ssel-item:hover { background: var(--glass-fill); }
.ssel-item.sel { font-weight: 600; }
.ssel-empty { padding: 14px; text-align: center; color: var(--ink-3); font-size: 13px; }
</style>
