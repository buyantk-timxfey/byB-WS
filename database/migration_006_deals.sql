CREATE TABLE IF NOT EXISTS deals (
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
    CONSTRAINT fk_deals_counterparty FOREIGN KEY (counterparty_id) REFERENCES counterparties(id) ON DELETE SET NULL,
    CONSTRAINT fk_deals_shipment FOREIGN KEY (shipment_id) REFERENCES shipments(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS deal_options (
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
    CONSTRAINT fk_deal_options_deal FOREIGN KEY (deal_id) REFERENCES deals(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
