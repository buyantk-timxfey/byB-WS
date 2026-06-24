-- by B Workspace — структура базы данных

CREATE DATABASE IF NOT EXISTS byb_workspace CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE byb_workspace;

-- Контрагенты
CREATE TABLE counterparties (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_type ENUM('ИП', 'ООО') NOT NULL,
    name VARCHAR(255) NOT NULL,
    type ENUM('Поставщик', 'Покупатель', 'Оба') NOT NULL,
    requisites TEXT,
    comment TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Транспортные компании
CREATE TABLE carriers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    website VARCHAR(255),
    comment TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Поставки
CREATE TABLE shipments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) DEFAULT NULL,
    order_date DATE NOT NULL,
    eta DATE NOT NULL,
    counterparty_id INT,
    carrier_id INT,
    tracking VARCHAR(255),
    status ENUM('Ожидает отправки', 'В пути', 'Завершено', '⚠️') DEFAULT 'Ожидает отправки',
    carrier_cost DECIMAL(10,2) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (counterparty_id) REFERENCES counterparties(id) ON DELETE SET NULL,
    FOREIGN KEY (carrier_id) REFERENCES carriers(id) ON DELETE SET NULL
);

-- Товары внутри поставки
CREATE TABLE shipment_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    shipment_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    purchase_price DECIMAL(10,2) NOT NULL DEFAULT 0,
    planned_sale_price DECIMAL(10,2) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (shipment_id) REFERENCES shipments(id) ON DELETE CASCADE
);

-- Склад
CREATE TABLE warehouse (
    id INT AUTO_INCREMENT PRIMARY KEY,
    shipment_item_id INT,
    shipment_id INT,
    name VARCHAR(255) NOT NULL,
    quantity_total INT NOT NULL DEFAULT 0,
    quantity_left INT NOT NULL DEFAULT 0,
    purchase_price DECIMAL(10,2) NOT NULL DEFAULT 0,
    status ENUM('На складе', 'Частично продан', 'Продан') DEFAULT 'На складе',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (shipment_item_id) REFERENCES shipment_items(id) ON DELETE SET NULL,
    FOREIGN KEY (shipment_id) REFERENCES shipments(id) ON DELETE SET NULL
);

-- Продажи (шапка). Позиции продажи — в отдельной таблице sale_items.
-- ВНИМАНИЕ: у sales НЕТ колонок quantity/warehouse_id — они на уровне позиций.
CREATE TABLE sales (
    id INT AUTO_INCREMENT PRIMARY KEY,
    counterparty_id INT,
    buyer_name VARCHAR(255),
    sale_price DECIMAL(10,2) NOT NULL DEFAULT 0,
    sale_date DATE NOT NULL,
    status ENUM('Оплачено','Счёт выставлен','Отменено') DEFAULT 'Оплачено',
    account_id INT,
    source_shipment_id INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (counterparty_id) REFERENCES counterparties(id) ON DELETE SET NULL
);

-- Позиции продажи (товары внутри одной продажи)
CREATE TABLE sale_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sale_id INT NOT NULL,
    warehouse_id INT NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    sale_price DECIMAL(10,2) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
    FOREIGN KEY (warehouse_id) REFERENCES warehouse(id) ON DELETE CASCADE
);

-- Банковские счета
CREATE TABLE bank_accounts (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    name             VARCHAR(255) NOT NULL,
    initial_balance  DECIMAL(10,2) NOT NULL DEFAULT 0,
    provider             VARCHAR(20)   NULL,
    api_token            TEXT          NULL,
    api_client_id        VARCHAR(100)  NULL,
    api_customer_code    VARCHAR(50)   NULL,
    api_account_id       VARCHAR(80)   NULL,
    api_statement_id     VARCHAR(80)   NULL,
    api_statement_status VARCHAR(20)   NULL,
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Банковские операции
CREATE TABLE bank_operations (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    account_id     INT NULL,
    type           ENUM('Продажа','Закупка','Прочий приход','Расход','Выплата ЗП','Перевод') NOT NULL,
    amount         DECIMAL(10,2) NOT NULL,
    description    TEXT,
    operation_date DATE NOT NULL,
    status         ENUM('pending','confirmed') NOT NULL DEFAULT 'confirmed',
    sale_id        INT NULL,
    shipment_id    INT NULL,
    transfer_id    INT NULL,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (account_id)   REFERENCES bank_accounts(id)  ON DELETE SET NULL,
    FOREIGN KEY (sale_id)      REFERENCES sales(id)           ON DELETE SET NULL,
    FOREIGN KEY (shipment_id)  REFERENCES shipments(id)       ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Строки банковской выписки (1CClientBankExchange)
CREATE TABLE bank_statement_lines (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    account_id       INT NULL,
    direction        ENUM('in','out') NOT NULL,
    amount           DECIMAL(12,2) NOT NULL,
    operation_date   DATE NOT NULL,
    value_date       DATE NULL,
    counterparty     VARCHAR(255) NULL,
    counterparty_inn VARCHAR(12)  NULL,
    description      TEXT,
    doc_number       VARCHAR(50)  NULL,
    raw_section      JSON NULL,
    import_batch     VARCHAR(50)  NULL,
    status           ENUM('unmatched','partial','matched','ignored') NOT NULL DEFAULT 'unmatched',
    bank_operation_id INT NULL,
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (account_id) REFERENCES bank_accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- M2M: привязка операций к строкам выписки
CREATE TABLE bank_statement_matches (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    line_id             INT NOT NULL,
    bank_operation_id   INT NOT NULL,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_match (line_id, bank_operation_id),
    FOREIGN KEY (line_id)           REFERENCES bank_statement_lines(id) ON DELETE CASCADE,
    FOREIGN KEY (bank_operation_id) REFERENCES bank_operations(id)      ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Метаданные батчей загрузки выписки
CREATE TABLE bank_statement_batches (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    account_id       INT NULL,
    import_batch     VARCHAR(50) NOT NULL,
    start_date       DATE NULL,
    end_date         DATE NULL,
    opening_balance  DECIMAL(12,2) NULL,
    closing_balance  DECIMAL(12,2) NULL,
    total_in         DECIMAL(12,2) NULL,
    total_out        DECIMAL(12,2) NULL,
    account_number   VARCHAR(30)  NULL,
    imported_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (account_id) REFERENCES bank_accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Сделки (воронка: Заявка -> Поиск -> Заказано -> Завершено)
CREATE TABLE deals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    quantity INT DEFAULT 1,
    counterparty_id INT NULL,
    competitor_price DECIMAL(10,2) NULL,
    stage VARCHAR(20) NOT NULL DEFAULT 'Заявка',
    note TEXT NULL,
    deadline DATE NULL,
    shipment_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (counterparty_id) REFERENCES counterparties(id) ON DELETE SET NULL,
    FOREIGN KEY (shipment_id) REFERENCES shipments(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Варианты поиска по сделке (найденные предложения поставщиков)
CREATE TABLE deal_options (
    id INT AUTO_INCREMENT PRIMARY KEY,
    deal_id INT NOT NULL,
    supplier VARCHAR(255) NULL,
    url VARCHAR(500) NULL,
    price DECIMAL(10,2) DEFAULT 0,
    delivery_cost DECIMAL(10,2) DEFAULT 0,
    delivery_days INT NULL,
    note VARCHAR(255) NULL,
    is_chosen TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (deal_id) REFERENCES deals(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Вход по PIN (банковский сценарий): хэш PIN + запомненные устройства
CREATE TABLE auth_pin (
    id INT AUTO_INCREMENT PRIMARY KEY,
    pin_hash VARCHAR(255) NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE device_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    token_hash CHAR(64) NOT NULL,
    label VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_used_at TIMESTAMP NULL DEFAULT NULL,
    UNIQUE KEY uq_device_token (token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Маршрутные листы (топливо/пробег, одна машина) ──────────────────────────
CREATE TABLE vehicle_trips (
    id INT AUTO_INCREMENT PRIMARY KEY,
    date_from DATE NOT NULL,
    date_to DATE NULL,
    km INT NOT NULL DEFAULT 0,
    odometer INT NULL,
    note VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Списания с топливной карты (мойка и т.п.) — только уменьшают баланс карты, в P&L не идут
CREATE TABLE car_washes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    wash_date DATE NOT NULL,
    amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    note VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE fuel_ups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    fuel_date DATE NOT NULL,
    liters DECIMAL(8,2) NOT NULL DEFAULT 0,
    amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    card_type ENUM('Топливная','Обычная') NOT NULL DEFAULT 'Топливная',
    expense_id INT NULL,
    note VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (expense_id) REFERENCES business_expenses(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE fuel_card_topups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    expense_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_topup_expense (expense_id),
    FOREIGN KEY (expense_id) REFERENCES business_expenses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE vehicle_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tank_capacity DECIMAL(6,2) NOT NULL DEFAULT 47,
    consumption_rate DECIMAL(5,2) NOT NULL DEFAULT 8.8,
    start_odometer INT NOT NULL DEFAULT 0,
    calib_date DATE NULL,
    calib_liters DECIMAL(8,2) NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
