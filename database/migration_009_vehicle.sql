CREATE TABLE IF NOT EXISTS vehicle_trips (
    id INT AUTO_INCREMENT PRIMARY KEY,
    date_from DATE NOT NULL,
    date_to DATE NULL,
    km INT NOT NULL DEFAULT 0,
    note VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fuel_ups (
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

CREATE TABLE IF NOT EXISTS fuel_card_topups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    expense_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_topup_expense (expense_id),
    FOREIGN KEY (expense_id) REFERENCES business_expenses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vehicle_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tank_capacity DECIMAL(6,2) NOT NULL DEFAULT 47,
    consumption_rate DECIMAL(5,2) NOT NULL DEFAULT 8.8,
    calib_date DATE NULL,
    calib_liters DECIMAL(8,2) NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
