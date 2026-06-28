<script setup lang="ts">
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import Icon from '@/Components/Icon.vue';

const page = usePage();
const url = computed(() => page.url);

const nav = [
    { label: 'Дашборд', icon: 'grid', href: '/dashboard' },
    { label: 'Поступления', icon: 'box', href: '/shipments' },
    { label: 'Продажи', icon: 'bag', href: '/sales' },
    { label: 'Склад', icon: 'warehouse', href: '/warehouse' },
    { label: 'Банк', icon: 'wallet', href: '/bank' },
    { label: 'Финансы', icon: 'chart', href: '/finances' },
    { label: 'Почта', icon: 'mail', href: '/mail' },
    { label: 'Транспорт', icon: 'car', href: '/vehicle' },
];

const bottomNav = [
    { label: 'Справочники', icon: 'book', href: '/references' },
    { label: 'Настройки', icon: 'gear', href: '/settings' },
];

const tabBar = [
    { label: 'Главная', icon: 'grid', href: '/dashboard' },
    { label: 'Поступления', icon: 'box', href: '/shipments' },
    { label: 'Продажи', icon: 'bag', href: '/sales' },
    { label: 'Банк', icon: 'wallet', href: '/bank' },
];

const isActive = (href: string) => url.value.startsWith(href);
</script>

<template>
    <div class="min-h-dvh">
        <!-- DESKTOP SIDEBAR -->
        <aside
            class="glass-strong fixed left-3 top-3 bottom-3 z-30 hidden w-[240px] flex-col p-3 lg:flex"
            style="border-radius: var(--r-sheet)"
        >
            <div class="flex items-center gap-2 px-2 py-3">
                <div
                    class="flex h-9 w-9 items-center justify-center rounded-control text-bg"
                    style="background: var(--ink)"
                >
                    <span class="text-sm font-bold">bB</span>
                </div>
                <span class="text-[17px] font-semibold tracking-tight">byBuka</span>
            </div>

            <nav class="mt-2 flex flex-1 flex-col gap-1">
                <Link
                    v-for="item in nav"
                    :key="item.href"
                    :href="item.href"
                    class="pressable flex items-center gap-3 rounded-control px-3 py-2.5 text-[15px] font-medium"
                    :class="
                        isActive(item.href)
                            ? 'bg-[var(--glass-fill-strong)] text-ink shadow-glass'
                            : 'text-ink-2 hover:text-ink'
                    "
                >
                    <Icon :name="item.icon" :size="20" />
                    <span>{{ item.label }}</span>
                </Link>
            </nav>

            <div class="flex flex-col gap-1 border-t border-[var(--glass-border)] pt-2">
                <Link
                    v-for="item in bottomNav"
                    :key="item.href"
                    :href="item.href"
                    class="pressable flex items-center gap-3 rounded-control px-3 py-2.5 text-[15px] font-medium text-ink-2 hover:text-ink"
                >
                    <Icon :name="item.icon" :size="20" />
                    <span>{{ item.label }}</span>
                </Link>
            </div>
        </aside>

        <!-- MAIN -->
        <div class="lg:pl-[260px]">
            <!-- top bar -->
            <header
                class="glass sticky top-3 z-20 mx-3 mb-4 mt-3 flex items-center gap-3 px-4 py-2.5"
            >
                <div class="min-w-0 flex-1">
                    <slot name="title">
                        <h1 class="truncate text-[17px] font-semibold tracking-tight">
                            {{ $page.props.title ?? 'byBuka' }}
                        </h1>
                    </slot>
                </div>
                <button class="pressable flex h-9 w-9 items-center justify-center rounded-control text-ink-2 hover:text-ink">
                    <Icon name="search" :size="20" />
                </button>
                <button class="pressable flex h-9 w-9 items-center justify-center rounded-control text-ink-2 hover:text-ink">
                    <Icon name="bell" :size="20" />
                </button>
            </header>

            <main class="px-3 pb-28 lg:pb-8">
                <slot />
            </main>
        </div>

        <!-- MOBILE TAB BAR -->
        <nav
            class="glass-strong fixed inset-x-3 bottom-3 z-30 flex items-center justify-around px-2 py-2 lg:hidden"
            style="border-radius: var(--r-sheet); padding-bottom: max(0.5rem, env(safe-area-inset-bottom))"
        >
            <Link
                v-for="item in tabBar"
                :key="item.href"
                :href="item.href"
                class="pressable flex flex-1 flex-col items-center gap-1 rounded-control py-1.5 text-[11px] font-medium"
                :class="isActive(item.href) ? 'text-ink' : 'text-ink-3'"
            >
                <Icon :name="item.icon" :size="22" />
                <span>{{ item.label }}</span>
            </Link>
            <Link
                href="/references"
                class="pressable flex flex-1 flex-col items-center gap-1 rounded-control py-1.5 text-[11px] font-medium text-ink-3"
            >
                <Icon name="grid" :size="22" />
                <span>Ещё</span>
            </Link>
        </nav>
    </div>
</template>
