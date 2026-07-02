import '../css/app.css';
import './bootstrap';

import { createInertiaApp, router } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createApp, DefineComponent, h } from 'vue';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';
import { applyTheme } from './lib/theme';

// Windows (Chrome/Edge) рендерит backdrop-filter слабее, чем macOS/Safari — стекло
// выглядит гораздо более прозрачным. Класс включает компенсирующую плотность в app.css.
if (/Windows/i.test(navigator.userAgent)) {
    document.documentElement.classList.add('is-windows');
}

// SPA-переход не перезагружает страницу, поэтому мобильный Safari не всегда
// сам скрывает системную клавиатуру, если раньше был сфокусирован текстовый
// input (например, поля логина/пароля). Снимаем фокус ДО начала перехода
// (пока ещё видна старая страница), а не после — иначе можно случайно
// перебить autofocus, который следующая страница ставит сама (PIN).
router.on('before', () => {
    (document.activeElement as HTMLElement | null)?.blur();
});

// Тема (Настройки → Тема): применяем при каждой навигации (см. lib/theme.ts) —
// сама настройка меняется мгновенно по клику через тот же applyTheme() из Settings.vue.
let themeMode: string | undefined;
router.on('navigate', (event) => {
    themeMode = (event.detail.page.props as any).themeMode;
    applyTheme(themeMode);
});
setInterval(() => { if (themeMode === 'auto_time') applyTheme(themeMode); }, 5 * 60 * 1000);

const appName = import.meta.env.VITE_APP_NAME || 'byBuka';

createInertiaApp({
    title: (title) => `${title} — ${appName}`,
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob<DefineComponent>('./Pages/**/*.vue'),
        ),
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .mount(el);
    },
    progress: {
        color: '#4B5563',
    },
});
