-- ============================================================
-- Миграция 004: колонки bank_operations (status, transfer_id, расширенный ENUM)
-- Если колонки уже есть — этот файл упадёт с предупреждением, это нормально.
-- Главное что таблицы из миграции 003 уже созданы.
-- ============================================================

ALTER TABLE bank_operations
    MODIFY COLUMN type ENUM('Продажа','Закупка','Прочий приход','Расход','Выплата ЗП','Перевод') NOT NULL;

ALTER TABLE bank_operations
    ADD COLUMN status ENUM('pending','confirmed') NOT NULL DEFAULT 'confirmed';

ALTER TABLE bank_operations
    ADD COLUMN transfer_id INT NULL
