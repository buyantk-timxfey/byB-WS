USE byb_workspace;

-- Номенклатура товаров
CREATE TABLE IF NOT EXISTS products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    category_id INT NULL,
    unit VARCHAR(50) DEFAULT 'шт',
    comment TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES warehouse_categories(id) ON DELETE SET NULL
);

-- Обновляем warehouse — добавляем product_id
ALTER TABLE warehouse
    ADD COLUMN IF NOT EXISTS product_id INT NULL,
    ADD CONSTRAINT IF NOT EXISTS fk_warehouse_product
        FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL;

-- Обновляем shipment_items — добавляем product_id
ALTER TABLE shipment_items
    ADD COLUMN IF NOT EXISTS product_id INT NULL;

-- Очищаем склад (данные будут заполнены заново)
TRUNCATE TABLE warehouse;
