import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.vue',
    ],

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
