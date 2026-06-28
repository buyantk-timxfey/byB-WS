<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{ pct: number; color?: string; label?: string }>();

const C = 2 * Math.PI * 36; // circumference, r=36
const offset = computed(() => C * (1 - Math.min(100, Math.max(0, props.pct)) / 100));
</script>

<template>
    <div class="relative inline-flex items-center justify-center">
        <svg viewBox="0 0 100 100" class="h-20 w-20 -rotate-90">
            <circle cx="50" cy="50" r="36" fill="none" stroke="var(--glass-border)" stroke-width="8" />
            <circle
                cx="50"
                cy="50"
                r="36"
                fill="none"
                :stroke="color ?? 'var(--income)'"
                stroke-width="8"
                stroke-linecap="round"
                :stroke-dasharray="C"
                :stroke-dashoffset="offset"
                style="transition: stroke-dashoffset 0.6s cubic-bezier(0.22, 1, 0.36, 1)"
            />
        </svg>
        <span class="absolute text-[15px] font-semibold tnum">{{ label ?? `${Math.round(pct)}%` }}</span>
    </div>
</template>
