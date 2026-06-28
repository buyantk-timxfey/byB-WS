<script setup lang="ts">
defineProps<{ title?: string }>();
</script>

<template>
    <section class="glass pressable flex flex-col p-4">
        <header v-if="title || $slots.action" class="mb-3 flex items-center justify-between">
            <h2 class="text-[13px] font-semibold uppercase tracking-wide text-ink-2">
                {{ title }}
            </h2>
            <slot name="action" />
        </header>
        <slot />
    </section>
</template>
