-- Phase 9: sales prices, shipments (delivery note + exit permit), sales invoices (VAT, ZATCA-ready QR and hash chain), recalls.

CREATE TABLE IF NOT EXISTS `sales_prices` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` BIGINT UNSIGNED NOT NULL,
  `item_id` BIGINT UNSIGNED NOT NULL,
  `grade` CHAR(1) NULL,
  `customer_id` BIGINT UNSIGNED NULL,
  `price_per_kg` DECIMAL(12,4) NOT NULL,
  `valid_from` DATE NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_by` BIGINT UNSIGNED NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sales_price` (`company_id`, `item_id`, `valid_from`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `shipments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` BIGINT UNSIGNED NOT NULL,
  `branch_id` BIGINT UNSIGNED NULL,
  `number` VARCHAR(50) NOT NULL,
  `client_order_id` BIGINT UNSIGNED NULL,
  `customer_id` BIGINT UNSIGNED NULL,
  `customer_name` VARCHAR(255) NOT NULL,
  `vehicle_id` BIGINT UNSIGNED NULL,
  `vehicle_plate` VARCHAR(30) NULL,
  `driver_name` VARCHAR(120) NULL,
  `driver_id_no` VARCHAR(40) NULL,
  `status` ENUM('Draft','Loaded','Permit_Issued','Dispatched','Delivered','Cancelled') NOT NULL DEFAULT 'Draft',
  `invoice_id` BIGINT UNSIGNED NULL,
  `loaded_by` BIGINT UNSIGNED NULL,
  `loaded_at` DATETIME NULL,
  `exit_permit_no` VARCHAR(50) NULL,
  `permit_by` BIGINT UNSIGNED NULL,
  `permit_at` DATETIME NULL,
  `dispatched_by` BIGINT UNSIGNED NULL,
  `dispatched_at` DATETIME NULL,
  `delivered_at` DATETIME NULL,
  `received_by` VARCHAR(120) NULL,
  `cancel_reason` VARCHAR(500) NULL,
  `notes` VARCHAR(1000) NULL,
  `created_by` BIGINT UNSIGNED NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_shipment_number` (`company_id`, `number`),
  KEY `idx_shipment_status` (`company_id`, `status`),
  KEY `idx_shipment_branch` (`branch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `shipment_units` (
  `shipment_id` BIGINT UNSIGNED NOT NULL,
  `unit_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`shipment_id`, `unit_id`),
  KEY `idx_su_unit` (`unit_id`),
  CONSTRAINT `fk_su_shipment` FOREIGN KEY (`shipment_id`) REFERENCES `shipments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sales_invoices` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` BIGINT UNSIGNED NOT NULL,
  `branch_id` BIGINT UNSIGNED NULL,
  `number` VARCHAR(50) NOT NULL,
  `shipment_id` BIGINT UNSIGNED NOT NULL,
  `customer_id` BIGINT UNSIGNED NULL,
  `customer_name` VARCHAR(255) NOT NULL,
  `customer_vat` VARCHAR(40) NULL,
  `invoice_type` ENUM('standard','simplified') NOT NULL,
  `issued_at` DATETIME NOT NULL,
  `subtotal` DECIMAL(15,2) NOT NULL,
  `vat_rate` DECIMAL(5,2) NOT NULL,
  `vat_amount` DECIMAL(15,2) NOT NULL,
  `total` DECIMAL(15,2) NOT NULL,
  `cogs` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `uuid` CHAR(36) NOT NULL,
  `icv` INT NOT NULL,
  `pih` VARCHAR(100) NOT NULL,
  `invoice_hash` VARCHAR(100) NOT NULL,
  `qr_base64` TEXT NOT NULL,
  `xml` MEDIUMTEXT NOT NULL,
  `zatca_status` ENUM('Not_submitted','Cleared','Reported','Rejected') NOT NULL DEFAULT 'Not_submitted',
  `gl_entry_id` BIGINT UNSIGNED NULL,
  `created_by` BIGINT UNSIGNED NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sales_invoice_number` (`company_id`, `number`),
  UNIQUE KEY `uq_sales_invoice_icv` (`company_id`, `icv`),
  UNIQUE KEY `uq_sales_invoice_shipment` (`shipment_id`),
  KEY `idx_sales_invoice_branch` (`branch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sales_invoice_lines` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `invoice_id` BIGINT UNSIGNED NOT NULL,
  `item_id` BIGINT UNSIGNED NOT NULL,
  `item_name` VARCHAR(255) NOT NULL,
  `grade` CHAR(1) NULL,
  `kg` DECIMAL(14,3) NOT NULL,
  `price_per_kg` DECIMAL(12,4) NOT NULL,
  `amount` DECIMAL(15,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_sil_invoice` (`invoice_id`),
  CONSTRAINT `fk_sil_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `sales_invoices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `recalls` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` BIGINT UNSIGNED NOT NULL,
  `number` VARCHAR(50) NOT NULL,
  `batch_id` BIGINT UNSIGNED NOT NULL,
  `reason` VARCHAR(500) NOT NULL,
  `status` ENUM('Open','Closed') NOT NULL DEFAULT 'Open',
  `hold_id` BIGINT UNSIGNED NULL,
  `created_by` BIGINT UNSIGNED NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `closed_by` BIGINT UNSIGNED NULL,
  `closed_at` DATETIME NULL,
  `close_note` VARCHAR(500) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_recall_number` (`company_id`, `number`),
  KEY `idx_recall_batch` (`company_id`, `batch_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- accounts and journal source
-- @optional
UPDATE `gl_accounts` SET `system_key` = 'ar_customers' WHERE `code` = '1210' AND `system_key` IS NULL;
-- @optional
ALTER TABLE `gl_entries` MODIFY `source_type` ENUM('manual','receipt','ap_invoice','ap_payment','reversal','prep_issue','prep_complete','prep_waste','custody_issue','custody_settle','sorting','sales_invoice') NOT NULL DEFAULT 'manual';

INSERT IGNORE INTO `settings` (`company_id`, `setting_key`, `setting_value`, `value_type`, `group_name`, `label_en`, `label_ar`)
SELECT c.id, s.k, s.v, s.t, 'sales', s.en, s.ar FROM `companies` c JOIN (
  SELECT 'dispatch.require_invoice_before_exit' AS k, '1' AS v, 'bool' AS t, 'The tax invoice must be issued before the exit permit' AS en, 'يجب إصدار الفاتورة الضريبية قبل إذن الخروج' AS ar
  UNION ALL SELECT 'dispatch.require_load_check', '1', 'bool', 'Every pallet / carton must be scanned onto the truck before the exit permit', 'يجب مسح كل طبلية/كرتونة عند التحميل قبل إذن الخروج'
  UNION ALL SELECT 'zatca.seller_address', '', 'string', 'Seller address printed on the e-invoice (street, city)', 'عنوان البائع المطبوع على الفاتورة الإلكترونية (الشارع، المدينة)'
) s;

INSERT INTO `roles` (`company_id`, `role_key`, `name_en`, `name_ar`, `is_system`)
SELECT NULL, 'dispatcher', 'Dispatcher', 'مسؤول الشحن', 1 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `roles` WHERE `role_key` = 'dispatcher' AND `company_id` IS NULL);
INSERT INTO `roles` (`company_id`, `role_key`, `name_en`, `name_ar`, `is_system`)
SELECT NULL, 'sales_accountant', 'Sales Accountant', 'محاسب المبيعات', 1 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `roles` WHERE `role_key` = 'sales_accountant' AND `company_id` IS NULL);
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission`)
SELECT r.id, p.perm FROM `roles` r JOIN (
  SELECT 'dispatcher' AS k, '*.view' AS perm UNION ALL SELECT 'dispatcher', 'shipments.create' UNION ALL SELECT 'dispatcher', 'shipments.edit' UNION ALL SELECT 'dispatcher', 'shipments.load'
  UNION ALL SELECT 'gate_officer', 'shipments.gate'
  UNION ALL SELECT 'sales_accountant', '*.view' UNION ALL SELECT 'sales_accountant', 'shipments.invoice' UNION ALL SELECT 'sales_accountant', 'sales-prices.create' UNION ALL SELECT 'sales_accountant', 'sales-prices.edit' UNION ALL SELECT 'sales_accountant', 'sales-invoices.create'
) p ON p.k = r.role_key WHERE r.company_id IS NULL;
