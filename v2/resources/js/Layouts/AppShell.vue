<script setup lang="ts">
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import Icon from '@/Components/Icon.vue';

const page = usePage();
const url = computed(() => page.url);

const nav = [
    { label: 'Главная', icon: 'grid', href: '/dashboard' },
    { label: 'Поступления', icon: 'box', href: '/shipments' },
    { label: 'Продажи', icon: 'bag', href: '/sales' },
    { label: 'Склад', icon: 'warehouse', href: '/warehouse' },
    { label: 'Банк', icon: 'wallet', href: '/bank' },
    { label: 'Финансы', icon: 'chart', href: '/finances' },
    { label: 'Почта', icon: 'mail', href: '/mail' },
    { label: 'Транспорт', icon: 'car', href: '/vehicle' },
    { label: 'Справочники', icon: 'book', href: '/references' },
];

const isActive = (href: string) => url.value.startsWith(href);
</script>

<template>
    <div class="min-h-dvh">
        <!-- Цветная глубина фона за стеклом -->
        <div class="orbs" aria-hidden="true">
            <i class="orb-a"></i><i class="orb-b"></i><i class="orb-c"></i><i class="orb-d"></i>
        </div>

        <!-- ВЕРХНЯЯ ПОЛОСА-НАВИГАЦИЯ (как Apple) -->
        <header
            class="glass-strong sticky top-3 z-30 mx-auto mb-5 mt-3 flex max-w-[1400px] items-center gap-2.5 px-3.5 py-2"
            style="border-radius: 999px"
        >
            <div class="flex items-center gap-2 pr-1.5">
                <div
                    class="flex h-[30px] w-[30px] items-center justify-center rounded-[9px]"
                    style="background: var(--ink); color: var(--bg)"
                >
                    <span class="text-[12px] font-bold">bB</span>
                </div>
                <span class="hidden whitespace-nowrap text-[16px] font-semibold tracking-tight sm:inline">
                    byBuka
                </span>
            </div>

            <nav class="flex flex-1 items-center gap-0.5 overflow-x-auto" style="scrollbar-width: none">
                <Link
                    v-for="item in nav"
                    :key="item.href"
                    :href="item.href"
                    class="pressable flex items-center gap-1.5 whitespace-nowrap rounded-full px-3 py-2 text-[14px] font-medium"
                    :class="isActive(item.href) ? '' : 'text-ink-2 hover:text-ink'"
                    :style="isActive(item.href) ? 'background: var(--ink); color: var(--bg)' : ''"
                >
                    <Icon :name="item.icon" :size="17" />
                    <span>{{ item.label }}</span>
                </Link>
            </nav>

            <div class="flex items-center gap-1">
                <button class="pressable flex h-[34px] w-[34px] items-center justify-center rounded-full text-ink-2 hover:text-ink">
                    <Icon name="search" :size="19" />
                </button>
                <button class="pressable flex h-[34px] w-[34px] items-center justify-center rounded-full text-ink-2 hover:text-ink">
                    <Icon name="bell" :size="19" />
                </button>
            </div>
        </header>

        <main class="mx-auto max-w-[1400px] px-3 pb-12">
            <slot />
        </main>
    </div>
</template>
