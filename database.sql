-- Entrada de Stock Simple (MySQL 5.7+/8.0+)
-- NOTA: En este MVP las contraseñas se guardan en texto plano (NO recomendado).
-- Cambiar a password_hash/password_verify cuando quieras.

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  role ENUM('superadmin','admin','vendedor','lectura') NOT NULL DEFAULT 'superadmin',
  first_name VARCHAR(80) NOT NULL,
  last_name  VARCHAR(80) NOT NULL,
  email      VARCHAR(190) NOT NULL,
  password_plain VARCHAR(190) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS products (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  sku VARCHAR(80) NOT NULL,
  name VARCHAR(190) NOT NULL,
  brand VARCHAR(120) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_products_sku (sku)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS product_codes (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id INT UNSIGNED NOT NULL,
  code VARCHAR(190) NOT NULL,
  code_type ENUM('BARRA','MPN') NOT NULL DEFAULT 'BARRA',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_product_codes_code (code),
  KEY idx_product_codes_product (product_id),
  CONSTRAINT fk_product_codes_product
    FOREIGN KEY (product_id) REFERENCES products(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS stock_lists (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(190) NOT NULL,
  created_by INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  status ENUM('open','closed') NOT NULL DEFAULT 'open',
  sync_target VARCHAR(40) NOT NULL DEFAULT '', -- 'prestashop' o ''
  synced_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_stock_lists_created_by (created_by),
  CONSTRAINT fk_stock_lists_user
    FOREIGN KEY (created_by) REFERENCES users(id)
    ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS stock_list_items (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  stock_list_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  qty INT NOT NULL DEFAULT 0,
  synced_qty INT NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_list_product (stock_list_id, product_id),
  KEY idx_items_list (stock_list_id),
  KEY idx_items_product (product_id),
  CONSTRAINT fk_items_list
    FOREIGN KEY (stock_list_id) REFERENCES stock_lists(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_items_product
    FOREIGN KEY (product_id) REFERENCES products(id)
    ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


CREATE TABLE IF NOT EXISTS settings (
  `key` VARCHAR(120) NOT NULL,
  `value` TEXT NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Valores iniciales (podés editarlos desde la pantalla Config PrestaShop)
INSERT INTO settings(`key`,`value`) VALUES
('prestashop_url',''),
('prestashop_api_key',''),
('prestashop_mode','replace')
ON DUPLICATE KEY UPDATE `value` = `value`;
