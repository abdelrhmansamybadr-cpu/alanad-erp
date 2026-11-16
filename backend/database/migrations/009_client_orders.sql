-- Phase 2: client orders (the order that arrives by e-mail / phone), stock check and stock reservation.
-- Safe to re-run: CREATE IF NOT EXISTS only.

CREATE TABLE IF NOT EXISTS `client_orders` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` BIGINT UNSIGNED NOT NULL,
  `branch_id` BIGINT UNSIGNED NULL,
  `number` VARCHAR(50) NOT NULL,
  `customer_id` BIGINT UNSIGNED NULL,
  `customer_name` VARCHAR(255) NOT NULL,
  `channel` VARCHAR(20) NOT NULL DEFAULT 'email',
  `order_date` DATE NOT NULL,
  `due_date` DATE NULL,
  `status` ENUM('Draft','Reserved','Short','Cancelled','Closed') NOT NULL DEFAULT 'Draft',
  `purchase_request_id` BIGINT UNSIGNED NULL,
  `notes` TEXT NULL,
  `created_by` BIGINT UNSIGNED NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_client_orders_number` (`company_id`, `number`),
  KEY `idx_client_orders_status` (`company_id`, `status`),
  KEY `idx_client_orders_branch` (`branch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `client_order_lines` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id` BIGINT UNSIGNED NOT NULL,
  `item_id` BIGINT UNSIGNED NOT NULL,
  `qty_kg` DECIMAL(14,3) NOT NULL,
  `reserved_kg` DECIMAL(14,3) NOT NULL DEFAULT 0,
  `note` VARCHAR(255) NULL,
  PRIMARY KEY (`id`),
  KEY `idx_col_order` (`order_id`),
  CONSTRAINT `fk_col_order` FOREIGN KEY (`order_id`) REFERENCES `client_orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `stock_reservations` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` BIGINT UNSIGNED NOT NULL,
  `order_id` BIGINT UNSIGNED NOT NULL,
  `line_id` BIGINT UNSIGNED NOT NULL,
  `item_id` BIGINT UNSIGNED NOT NULL,
  `batch_id` BIGINT UNSIGNED NOT NULL,
  `warehouse_id` BIGINT UNSIGNED NULL,
  `cold_room_id` BIGINT UNSIGNED NULL,
  `qty` DECIMAL(14,3) NOT NULL,
  `status` ENUM('Active','Released') NOT NULL DEFAULT 'Active',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `released_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  KEY `idx_res_batch` (`company_id`, `batch_id`, `status`),
  KEY `idx_res_order` (`order_id`, `status`),
  CONSTRAINT `fk_res_order` FOREIGN KEY (`order_id`) REFERENCES `client_orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
