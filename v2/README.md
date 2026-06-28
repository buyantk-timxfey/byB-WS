# byBuka 2.0

Новая CRM/ERP — рерайт byB-WS. Учёт по модели «документы + регистры», дизайн Liquid Glass (iOS 26/27).

Документы планирования — в корне репозитория: `docs/CRM-PLAN.md`, `docs/PAGES.md`, `docs/DESIGN.md`.

## Стек
Laravel 13 · Inertia · Vue 3 + TypeScript · Tailwind CSS (v3) · MySQL (в проде) / SQLite (локально).

## Запуск локально
```bash
composer install
npm install
cp .env.example .env && php artisan key:generate
php artisan migrate           # БД по умолчанию — sqlite
npm run dev                   # или npm run build
php artisan serve
```

## Что готово (Фаза 0 — каркас)
- Скаффолд Laravel + Breeze (Vue + Inertia + TS), аутентификация.
- Дизайн-токены Liquid Glass: темы (светлая/тёмная, авто), уровни стекла, семантические цвета, SF Pro, иконки в стиле SF Symbols.
- Каркас навигации: стеклянный сайдбар (десктоп) + таб-бар (мобайл) — resources/js/Layouts/AppShell.vue.
- Демо-дашборд с виджетами и банковской лентой в стиле Apple Card — resources/js/Pages/Dashboard.vue.

Дальше — Фаза 1 (учётное ядро: документы, регистры, справочники).
