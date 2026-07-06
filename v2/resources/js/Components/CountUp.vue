<script setup lang="ts">
// Счётчик цифр «как в iOS»: при появлении значение проматывается от 0 (или от прошлого
// значения при обновлении) до цели с ease-out. Работает с уже отформатированными строками
// KPI («184 500 ₽», «12,5 %», «42 шт») — анимируется числовая часть, префикс/суффикс сохраняются.
// Если число распарсить нельзя («—», текст) — показываем строку как есть, без анимации.
import { ref, watch, onMounted, onBeforeUnmount } from 'vue';

const props = defineProps<{ value: string | number; duration?: number }>();

// prefix (не-цифры) · число (с ru-RU разделителями: обычный/неразрывный/узкий пробел, запятая) · suffix
const NUM_RE = /^(\D*?)(-?[\d\s  .,]*\d)([\s\S]*)$/;
function parse(v: string | number) {
    const s = String(v);
    const m = s.match(NUM_RE);
    if (!m) return null;
    const target = parseFloat(m[2].replace(/[^\d,-]/g, '').replace(',', '.'));
    if (isNaN(target)) return null;
    const dec = (m[2].match(/,(\d+)/) || [])[1]?.length ?? 0;
    return { prefix: m[1], target, dec, suffix: m[3] };
}
function fmt(p: { prefix: string; dec: number; suffix: string }, n: number) {
    return p.prefix + new Intl.NumberFormat('ru-RU', { minimumFractionDigits: p.dec, maximumFractionDigits: p.dec }).format(n) + p.suffix;
}

const shown = ref(String(props.value));
let raf = 0;
const reduce = () => window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false;

function run(from: number) {
    const p = parse(props.value);
    if (!p) { shown.value = String(props.value); return; }
    if (reduce()) { shown.value = fmt(p, p.target); return; }
    const dur = props.duration ?? 650;
    const t0 = performance.now();
    cancelAnimationFrame(raf);
    const tick = (now: number) => {
        const t = Math.min(1, (now - t0) / dur);
        const e = 1 - Math.pow(1 - t, 3); // easeOutCubic
        shown.value = fmt(p, from + (p.target - from) * e);
        if (t < 1) raf = requestAnimationFrame(tick);
        else shown.value = fmt(p, p.target);
    };
    raf = requestAnimationFrame(tick);
}

onMounted(() => run(0));
// При обновлении данных (например, сменился месяц/пришли новые props) — плавно
// доезжаем от текущего показанного числа к новому, а не прыгаем и не считаем с нуля.
watch(() => props.value, () => {
    const cur = parseFloat(shown.value.replace(/[^\d,-]/g, '').replace(',', '.'));
    run(isNaN(cur) ? 0 : cur);
});
onBeforeUnmount(() => cancelAnimationFrame(raf));
</script>

<template>{{ shown }}</template>
