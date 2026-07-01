import '../css/app.css';
import './bootstrap';

import { createInertiaApp, router } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createApp, DefineComponent, h } from 'vue';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';

// Windows (Chrome/Edge) рендерит backdrop-filter слабее, чем macOS/Safari — стекло
// выглядит гораздо более прозрачным. Класс включает компенсирующую плотность в app.css.
if (/Windows/i.test(navigator.userAgent)) {
    document.documentElement.classList.add('is-windows');
}

// SPA-переход не перезагружает страницу, поэтому мобильный Safari не всегда
// сам скрывает системную клавиатуру, если раньше был сфокусирован текстовый
// input (например, поля логина/пароля) — снимаем фокус на каждой навигации,
// иначе клавиатура остаётся видимой даже на страницах без единого input (PIN).
router.on('navigate', () => {
    (document.activeElement as HTMLElement | null)?.blur();
});

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
