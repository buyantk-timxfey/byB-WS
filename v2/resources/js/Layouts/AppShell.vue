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
const isActive = (href: string) => url.value.startsWith(href);

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

            <nav class="glass-strong top-nav flex items-center gap-0.5 overflow-x-auto px-2.5 py-2" style="border-radius: 999px; scrollbar-width: none">
                <Link
                    v-for="item in nav"
                    :key="item.href"
                    :href="item.href"
                    class="nav-item pressable flex items-center gap-1.5 whitespace-nowrap rounded-full px-3 py-2 text-[14px] font-medium"
                    :class="isActive(item.href) ? 'nav-active' : 'text-ink-2 hover:text-ink'"
                >
                    <Icon :name="item.icon" :size="17" />
                    <span class="nav-label">{{ item.label }}</span>
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

        <main class="page-anim mx-auto max-w-[1400px] px-3 pb-12">
            <slot />
        </main>

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

/* Телефон: верхнее меню остаётся, но пункты — только иконками */
@media (max-width: 640px) {
    .nav-label { display: none; }
    .nav-item { padding: 9px 10px; }
    .nav-item :deep(svg) { width: 19px; height: 19px; }
}

</style>
