<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AppShell from '@/Layouts/AppShell.vue';
import Icon from '@/Components/Icon.vue';

defineProps<{ title: string }>();
</script>

<template>
    <Head :title="title" />
    <AppShell>
        <div class="glass mx-auto mt-6 flex max-w-[640px] flex-col items-center gap-3 p-12 text-center">
            <span class="flex h-14 w-14 items-center justify-center rounded-card" style="background: var(--glass-fill-strong); color: var(--ink-2)">
                <Icon name="box" :size="26" />
            </span>
            <h1 class="text-[22px] font-bold tracking-tight">{{ title }}</h1>
            <p class="text-[15px] text-ink-2">Раздел в разработке — свёрстаем следующим этапом.</p>
        </div>
    </AppShell>
</template>
