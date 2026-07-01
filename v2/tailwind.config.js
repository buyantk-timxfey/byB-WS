import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.vue',
        './resources/js/**/*.ts',
    ],

    // Классы, которые ставятся на <html> только через JS (classList.add/toggle в app.ts),
    // а не пишутся буквально в шаблонах — без safelist Tailwind считает их неиспользуемыми
    // и вырезает вместе с их правилами при сборке (именно так один раз тихо пропало стекло
    // на Windows и вся тема light/dark — правила были в исходнике, но не доехали до билда).
    safelist: ['is-windows', 'theme-light', 'theme-dark'],

    theme: {
        extend: {
            fontFamily: {
                sans: [
                    '-apple-system',
                    'BlinkMacSystemFont',
                    'SF Pro Text',
                    'SF Pro Display',
                    'system-ui',
                    'Segoe UI',
                    'sans-serif',
                ],
            },
            colors: {
                ink: {
                    DEFAULT: 'var(--ink)',
                    2: 'var(--ink-2)',
                    3: 'var(--ink-3)',
                },
                income: 'var(--income)',
                expense: 'var(--expense)',
                warn: 'var(--warn)',
                info: 'var(--info)',
            },
            borderRadius: {
                control: 'var(--r-control)',
                card: 'var(--r-card)',
                sheet: 'var(--r-sheet)',
            },
            boxShadow: {
                glass: 'var(--shadow)',
                'glass-lg': 'var(--shadow-lg)',
            },
        },
    },

    plugins: [forms],
};
