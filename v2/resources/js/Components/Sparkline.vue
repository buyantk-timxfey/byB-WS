<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{ data: number[]; color?: string }>();

const path = computed(() => {
    const d = props.data;
    if (!d.length) return '';
    const max = Math.max(...d), min = Math.min(...d);
    const span = max - min || 1;
    const w = 120, h = 34, pad = 4;
    return d
        .map((v, i) => {
            const x = (i / (d.length - 1)) * w;
            const y = h - pad - ((v - min) / span) * (h - pad * 2);
            return `${x.toFixed(1)},${y.toFixed(1)}`;
        })
        .join(' ');
});
</script>

<template>
    <svg class="block w-full" height="34" viewBox="0 0 120 34" preserveAspectRatio="none">
        <polyline
            :points="path"
            fill="none"
            :stroke="color ?? 'var(--income)'"
            stroke-width="2.5"
            vector-effect="non-scaling-stroke"
            stroke-linecap="round"
            stroke-linejoin="round"
            opacity="0.7"
        />
    </svg>
</template>
