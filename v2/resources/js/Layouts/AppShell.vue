<script setup lang="ts">
import { computed, ref, onMounted, onUnmounted } from 'vue';
import { Link, usePage, router } from '@inertiajs/vue3';
import Icon from '@/Components/Icon.vue';
import SearchPalette from '@/Components/SearchPalette.vue';
import NotificationsPanel from '@/Components/NotificationsPanel.vue';
import ConfirmHost from '@/Components/ConfirmHost.vue';

const search = ref<InstanceType<typeof SearchPalette> | null>(null);
const notif = ref<InstanceType<typeof NotificationsPanel> | null>(null);
const notifItems = ref<any[]>([]);
const notifCount = computed(() => notifItems.value.length);
const menuOpen = ref(false);
const moreOpen = ref(false);

const page = usePage();
const url = computed(() => page.url);

const nav = [
    { label: 'Главная', icon: 'home', href: '/dashboard' },
    { label: 'Поставки', icon: 'package', href: '/shipments' },
    { label: 'Продажи', icon: 'cart', href: '/sales' },
    { label: 'Склад', icon: 'warehouse', href: '/warehouse' },
    { label: 'Банк', icon: 'building-columns', href: '/bank' },
    { label: 'Финансы', icon: 'chart', href: '/finances' },
    { label: 'Почта', icon: 'mail', href: '/mail' },
    { label: 'Транспорт', icon: 'car', href: '/vehicle' },
    { label: 'Справочники', icon: 'book', href: '/references' },
];
// Мобильный таб-бар: 4 главных раздела + «Ещё» с остальными
const tabMain = nav.slice(0, 3).concat([nav[4]]);   // Главная, Поставки, Продажи, Банк
const tabMore = [nav[3], nav[5], nav[6], nav[7], nav[8], { label: 'Настройки', icon: 'gear', href: '/settings' }];
const isActive = (href: string) => url.value.startsWith(href);
const moreActive = computed(() => tabMore.some((t) => isActive(t.href)));

onMounted(async () => {
    try {
        const res = await fetch('/notifications', { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        const data = await res.json();
        notifItems.value = data.items ?? [];
    } catch { /* ignore */ }
});

// Автоблокировка после бездействия — сервер тоже проверяет это на каждом запросе
// (см. LockIdleSession), но таймер в браузере блокирует сразу по истечении времени,
// даже если пользователь просто смотрит на экран и ничего не запрашивает с сервера.
const idleMinutes = computed(() => Number((page.props as any).idleLockMinutes) || 30);
let idleTimer: ReturnType<typeof setTimeout> | null = null;
function resetIdleTimer() {
    if (idleTimer) clearTimeout(idleTimer);
    idleTimer = setTimeout(() => router.visit('/pin'), idleMinutes.value * 60 * 1000);
}
const idleEvents = ['mousemove', 'keydown', 'mousedown', 'touchstart', 'scroll'];
onMounted(() => {
    idleEvents.forEach((e) => window.addEventListener(e, resetIdleTimer, { passive: true }));
    resetIdleTimer();
});
onUnmounted(() => {
    idleEvents.forEach((e) => window.removeEventListener(e, resetIdleTimer));
    if (idleTimer) clearTimeout(idleTimer);
});
</script>

<template>
    <div class="min-h-dvh">
        <div class="orbs" aria-hidden="true">
            <i class="orb-a"></i><i class="orb-b"></i><i class="orb-c"></i><i class="orb-d"></i>
        </div>

        <!-- ВЕРХ: лого слева, меню по центру, иконки отдельным овалом справа -->
        <header class="sticky top-3 z-30 mx-auto mb-5 mt-3 flex max-w-[1400px] items-center gap-3 px-3">
            <div class="flex flex-1 items-center">
                <!-- Логотип + выпадающее меню -->
                <div class="relative">
                    <button class="pressable glass-strong flex h-[42px] w-[42px] items-center justify-center rounded-full" @click="menuOpen = !menuOpen">
                        <img src="/images/logo-black.png" alt="byBuka" class="brand-logo-light" style="width:20px;height:20px;object-fit:contain" />
                        <img src="/images/logo-white.png" alt="byBuka" class="brand-logo-dark" style="width:20px;height:20px;object-fit:contain" />
                    </button>
                    <Transition name="menu">
                        <div v-if="menuOpen" class="user-menu glass-strong" @click="menuOpen = false">
                            <Link href="/settings" class="um-item"><Icon name="gear" :size="17" /> Настройки</Link>
                            <Link href="/logout" method="post" as="button" class="um-item um-item--bad"><Icon name="logout" :size="17" /> Выход</Link>
                        </div>
                    </Transition>
                    <div v-if="menuOpen" class="fixed inset-0 z-10" @click="menuOpen = false"></div>
                </div>
            </div>

            <nav class="glass-strong hidden items-center gap-0.5 overflow-x-auto px-2.5 py-2 sm:flex" style="border-radius: 999px; scrollbar-width: none">
                <Link
                    v-for="item in nav"
                    :key="item.href"
                    :href="item.href"
                    class="pressable flex items-center gap-1.5 whitespace-nowrap rounded-full px-3 py-2 text-[14px] font-medium"
                    :class="isActive(item.href) ? 'nav-active' : 'text-ink-2 hover:text-ink'"
                >
                    <Icon :name="item.icon" :size="17" />
                    <span>{{ item.label }}</span>
                </Link>
            </nav>

            <div class="flex flex-1 items-center justify-end gap-2">
                <!-- Овал с поиском и уведомлениями -->
                <div class="glass-strong flex items-center gap-1 px-2 py-1.5" style="border-radius: 999px">
                    <button class="pressable flex h-[34px] w-[34px] items-center justify-center rounded-full text-ink-2 hover:text-ink" @click="search?.show()">
                        <Icon name="search" :size="19" />
                    </button>
                    <button class="pressable relative flex h-[34px] w-[34px] items-center justify-center rounded-full text-ink-2 hover:text-ink" @click="notif?.toggle()">
                        <Icon name="bell" :size="19" />
                        <span v-if="notifCount" class="notif-badge">{{ notifCount }}</span>
                    </button>
                </div>
            </div>
        </header>

        <main class="page-anim mx-auto max-w-[1400px] px-3 pb-28 sm:pb-12">
            <slot />
        </main>

        <!-- Мобильный таб-бар (телефоны) -->
        <Transition name="menu">
            <div v-if="moreOpen" class="tabmore-ov sm:hidden" @click="moreOpen = false"></div>
        </Transition>
        <Transition name="sheetup">
            <div v-if="moreOpen" class="tabmore glass-strong sm:hidden">
                <Link v-for="t in tabMore" :key="t.href" :href="t.href" class="tabmore-item pressable" :class="{ 'tabmore-item--on': isActive(t.href) }" @click="moreOpen = false">
                    <Icon :name="t.icon" :size="22" />
                    <span>{{ t.label }}</span>
                </Link>
            </div>
        </Transition>
        <nav class="tabbar glass-strong sm:hidden">
            <Link v-for="t in tabMain" :key="t.href" :href="t.href" class="tab-item pressable" :class="{ 'tab-item--on': isActive(t.href) }" @click="moreOpen = false">
                <Icon :name="t.icon" :size="21" />
                <span>{{ t.label }}</span>
            </Link>
            <button type="button" class="tab-item pressable" :class="{ 'tab-item--on': moreActive || moreOpen }" @click="moreOpen = !moreOpen">
                <Icon name="grid" :size="21" />
                <span>Ещё</span>
            </button>
        </nav>

        <SearchPalette ref="search" />
        <NotificationsPanel ref="notif" :items="notifItems" />
        <ConfirmHost />
    </div>
</template>

<style scoped>
.nav-active { background: var(--info, #0a84ff); color: #fff; }
.notif-badge { position: absolute; top: 1px; right: 1px; min-width: 16px; height: 16px; padding: 0 4px; border-radius: 999px; background: var(--expense); color: #fff; font-size: 10px; font-weight: 700; display: flex; align-items: center; justify-content: center; }
.user-menu { position: absolute; left: 0; top: 50px; z-index: 20; min-width: 180px; border-radius: 16px; padding: 6px; display: flex; flex-direction: column; }
.um-item { display: flex; align-items: center; gap: 10px; padding: 10px 12px; border-radius: 10px; font-size: 14px; font-weight: 500; color: var(--ink); background: transparent; border: 0; cursor: pointer; text-align: left; width: 100%; }
.um-item:hover { background: var(--glass-fill); }
.um-item--bad { color: var(--expense); }
.menu-enter-active, .menu-leave-active { transition: opacity .15s, transform .15s; }
.menu-enter-from, .menu-leave-to { opacity: 0; transform: translateY(-6px); }

/* Мобильный таб-бар */
.tabbar {
    position: fixed; left: 10px; right: 10px; bottom: calc(8px + env(safe-area-inset-bottom, 0px));
    z-index: 40; display: flex; border-radius: 24px; padding: 6px;
}
.tab-item {
    flex: 1; display: flex; flex-direction: column; align-items: center; gap: 3px;
    padding: 7px 0 5px; border-radius: 18px; border: 0; background: transparent;
    color: var(--ink-2); font-size: 10.5px; font-weight: 600; cursor: pointer; text-decoration: none;
}
.tab-item--on { color: var(--info, #0a84ff); background: var(--glass-fill); }
.tabmore-ov { position: fixed; inset: 0; z-index: 39; background: rgba(0,0,0,.3); }
.tabmore {
    position: fixed; left: 10px; right: 10px; bottom: calc(76px + env(safe-area-inset-bottom, 0px));
    z-index: 40; display: grid; grid-template-columns: repeat(3, 1fr); gap: 4px;
    border-radius: 22px; padding: 10px;
}
.tabmore-item {
    display: flex; flex-direction: column; align-items: center; gap: 6px;
    padding: 14px 4px 12px; border-radius: 16px; color: var(--ink-2);
    font-size: 11.5px; font-weight: 600; text-decoration: none;
}
.tabmore-item--on { color: var(--info, #0a84ff); background: var(--glass-fill); }
.sheetup-enter-active, .sheetup-leave-active { transition: opacity .2s ease, transform .22s cubic-bezier(.22,1,.36,1); }
.sheetup-enter-from, .sheetup-leave-to { opacity: 0; transform: translateY(14px); }
@media (prefers-reduced-motion: reduce) { .sheetup-enter-active, .sheetup-leave-active { transition: none; } }
</style>
