# Деплой byBuka 2.0 на reg.ru (ISPmanager)

Стек: Laravel 13 + Inertia + Vue 3. Собранные ассеты (`public/build`) уже в репозитории —
**Node на сервере не нужен**. Нужны: PHP 8.2+, Composer, MySQL, доступ по SSH.

## 1. Подготовка в ISPmanager
1. **PHP** → версия сайта **8.2** или **8.3**.
2. **Базы данных** → создать БД и пользователя, запомнить имя/логин/пароль.
3. **Сайты** → создать сайт; **корневую директорию (docroot)** указать на `.../bybuka/public`.
   Если docroot менять нельзя — см. раздел «Если docroot фиксирован».

## 2. Заливка кода
По SSH (Shell-клиент):
```bash
cd ~/                       # домашняя директория
git clone <repo> bybuka     # или загрузить папку v2/ как bybuka/ через Менеджер файлов
cd bybuka
```

## 3. Зависимости и конфиг
```bash
composer install --no-dev --optimize-autoloader

cp .env.production.example .env
# отредактировать .env: APP_URL, DB_*, ADMIN_* (Менеджер файлов или nano)

php artisan key:generate
```

## 4. База данных «с нуля»
```bash
php artisan migrate --force          # создаёт все таблицы
php artisan db:seed --force          # один аккаунт, ставки, статьи, авто-правила
```

## 5. Кэш и права
```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
chmod -R 775 storage bootstrap/cache
```

## 6. Готово
Открыть `APP_URL`, войти под `ADMIN_EMAIL` / `ADMIN_PASSWORD`. Сразу сменить пароль и PIN в Настройках.

## 7. Cron (для очередей/почты)
ISPmanager → Планировщик (Cron), раз в минуту:
```
* * * * * cd ~/bybuka && php artisan schedule:run >> /dev/null 2>&1
```

---

## Если docroot фиксирован (нельзя указать /public)
Положить в корень сайта `index.php`:
```php
<?php require __DIR__.'/bybuka/public/index.php';
```
и `.htaccess`:
```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-d
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^ index.php [L]
```
Статику (`/build/...`) скопировать/симлинкнуть из `bybuka/public/build` в корень сайта.

## Обновление (новый патч)
```bash
cd ~/bybuka && git pull          # или залить изменённые файлы
composer install --no-dev -o     # если менялись зависимости
php artisan migrate --force       # если есть новые миграции
php artisan config:cache && php artisan route:cache && php artisan view:cache
```
Ассеты пересобираются локально (`npm run build`) и попадают в репозиторий — на сервере сборка не нужна.

## Бэкап БД
Перед обновлением: ISPmanager → Базы данных → экспорт, либо
`mysqldump -u USER -p DB > backup-$(date +%F).sql`.
