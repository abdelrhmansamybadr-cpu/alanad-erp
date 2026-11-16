-- ============================================================
-- Manzuma ERP — MySQL 8 Schema
-- Import via Hostinger hPanel > phpMyAdmin
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';

-- ─── AUTH ────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `users` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(255) NOT NULL,
  `email` VARCHAR(255) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `role` ENUM('admin','user','viewer') NOT NULL DEFAULT 'user',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── MASTERS ─────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `branches` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(50) NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `address` TEXT,
  `city` VARCHAR(100),
  `country` VARCHAR(100),
  `phone` VARCHAR(50),
  `email` VARCHAR(255),
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `branches_code_unique` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `item_groups` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(255) NOT NULL,
  `parent_group` VARCHAR(255),
  `description` TEXT,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `customer_groups` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(255) NOT NULL,
  `description` TEXT,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `supplier_groups` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(255) NOT NULL,
  `description` TEXT,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `territories` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(255) NOT NULL,
  `parent_territory` VARCHAR(255),
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `payment_terms` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(255) NOT NULL,
  `days` INT NOT NULL DEFAULT 30,
  `description` TEXT,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `price_lists` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(255) NOT NULL,
  `currency` VARCHAR(10) NOT NULL DEFAULT 'SAR',
  `is_buying` TINYINT(1) NOT NULL DEFAULT 0,
  `is_selling` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `uom` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `abbreviation` VARCHAR(20),
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `brands` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(255) NOT NULL,
  `description` TEXT,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sales_persons` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(255) NOT NULL,
  `employee_id` BIGINT UNSIGNED NULL,
  `commission_rate` DECIMAL(5,2) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `addresses` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `address_type` ENUM('Billing','Shipping','Both') NOT NULL DEFAULT 'Both',
  `line1` TEXT,
  `line2` TEXT,
  `city` VARCHAR(100),
  `state` VARCHAR(100),
  `country` VARCHAR(100),
  `postal_code` VARCHAR(20),
  `entity_type` VARCHAR(50),
  `entity_id` BIGINT UNSIGNED,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `terms_conditions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(255) NOT NULL,
  `terms` TEXT,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── CUSTOMERS & SUPPLIERS ────────────────────────────────────

CREATE TABLE IF NOT EXISTS `customers` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(50),
  `name` VARCHAR(255) NOT NULL,
  `email` VARCHAR(255),
  `phone` VARCHAR(50),
  `mobile` VARCHAR(50),
  `website` VARCHAR(255),
  `tax_id` VARCHAR(100),
  `customer_group` VARCHAR(255),
  `territory` VARCHAR(255),
  `currency` VARCHAR(10) NOT NULL DEFAULT 'SAR',
  `credit_limit` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `payment_terms` VARCHAR(255),
  `address` TEXT,
  `city` VARCHAR(100),
  `country` VARCHAR(100),
  `notes` TEXT,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `customers_name_index` (`name`),
  KEY `customers_email_index` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `suppliers` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(50),
  `name` VARCHAR(255) NOT NULL,
  `email` VARCHAR(255),
  `phone` VARCHAR(50),
  `mobile` VARCHAR(50),
  `website` VARCHAR(255),
  `tax_id` VARCHAR(100),
  `supplier_group` VARCHAR(255),
  `currency` VARCHAR(10) NOT NULL DEFAULT 'SAR',
  `payment_terms` VARCHAR(255),
  `address` TEXT,
  `city` VARCHAR(100),
  `country` VARCHAR(100),
  `notes` TEXT,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `suppliers_name_index` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── ITEMS ────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `items` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(100),
  `name` VARCHAR(255) NOT NULL,
  `description` TEXT,
  `item_group` VARCHAR(255),
  `brand` VARCHAR(255),
  `uom` VARCHAR(50) NOT NULL DEFAULT 'Unit',
  `is_stock_item` TINYINT(1) NOT NULL DEFAULT 1,
  `is_sales_item` TINYINT(1) NOT NULL DEFAULT 1,
  `is_purchase_item` TINYINT(1) NOT NULL DEFAULT 1,
  `standard_selling_rate` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `standard_buying_rate` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `opening_stock` DECIMAL(15,3) NOT NULL DEFAULT 0,
  `reorder_level` DECIMAL(15,3) NOT NULL DEFAULT 0,
  `currency` VARCHAR(10) NOT NULL DEFAULT 'SAR',
  `notes` TEXT,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `items_name_index` (`name`),
  KEY `items_code_index` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── INVOICES ─────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `invoices` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `number` VARCHAR(50) NOT NULL,
  `date` DATE NOT NULL,
  `due_date` DATE,
  `customer_id` BIGINT UNSIGNED,
  `customer_name` VARCHAR(255),
  `currency` VARCHAR(10) NOT NULL DEFAULT 'SAR',
  `subtotal` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `tax_amount` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `discount_amount` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `total` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `paid_amount` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `outstanding` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `status` ENUM('Draft','Submitted','Paid','Overdue','Cancelled') NOT NULL DEFAULT 'Draft',
  `notes` TEXT,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `invoices_number_unique` (`number`),
  KEY `invoices_customer_id_index` (`customer_id`),
  KEY `invoices_status_index` (`status`),
  KEY `invoices_date_index` (`date`),
  CONSTRAINT `invoices_customer_fk` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `invoice_lines` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `invoice_id` BIGINT UNSIGNED NOT NULL,
  `item_id` BIGINT UNSIGNED,
  `item_name` VARCHAR(255),
  `description` TEXT,
  `qty` DECIMAL(15,3) NOT NULL DEFAULT 1,
  `uom` VARCHAR(50),
  `rate` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `amount` DECIMAL(15,2) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `invoice_lines_invoice_id_index` (`invoice_id`),
  CONSTRAINT `invoice_lines_invoice_fk` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE,
  CONSTRAINT `invoice_lines_item_fk` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── QUOTATIONS ───────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `quotations` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `number` VARCHAR(50) NOT NULL,
  `date` DATE NOT NULL,
  `valid_until` DATE,
  `customer_id` BIGINT UNSIGNED,
  `customer_name` VARCHAR(255),
  `currency` VARCHAR(10) NOT NULL DEFAULT 'SAR',
  `subtotal` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `tax_amount` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `total` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `status` ENUM('Draft','Sent','Accepted','Rejected','Expired') NOT NULL DEFAULT 'Draft',
  `notes` TEXT,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `quotations_number_unique` (`number`),
  KEY `quotations_customer_id_index` (`customer_id`),
  CONSTRAINT `quotations_customer_fk` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `quotation_lines` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `quotation_id` BIGINT UNSIGNED NOT NULL,
  `item_id` BIGINT UNSIGNED,
  `item_name` VARCHAR(255),
  `qty` DECIMAL(15,3) NOT NULL DEFAULT 1,
  `uom` VARCHAR(50),
  `rate` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `amount` DECIMAL(15,2) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `quotation_lines_quotation_id_index` (`quotation_id`),
  CONSTRAINT `quotation_lines_quotation_fk` FOREIGN KEY (`quotation_id`) REFERENCES `quotations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── SALES ORDERS ─────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `sales_orders` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `number` VARCHAR(50) NOT NULL,
  `date` DATE NOT NULL,
  `delivery_date` DATE,
  `customer_id` BIGINT UNSIGNED,
  `customer_name` VARCHAR(255),
  `currency` VARCHAR(10) NOT NULL DEFAULT 'SAR',
  `subtotal` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `total` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `status` ENUM('Draft','Confirmed','Delivered','Cancelled') NOT NULL DEFAULT 'Draft',
  `notes` TEXT,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `sales_orders_number_unique` (`number`),
  KEY `sales_orders_customer_id_index` (`customer_id`),
  CONSTRAINT `sales_orders_customer_fk` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sales_order_lines` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `sales_order_id` BIGINT UNSIGNED NOT NULL,
  `item_id` BIGINT UNSIGNED,
  `item_name` VARCHAR(255),
  `qty` DECIMAL(15,3) NOT NULL DEFAULT 1,
  `uom` VARCHAR(50),
  `rate` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `amount` DECIMAL(15,2) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  CONSTRAINT `sales_order_lines_so_fk` FOREIGN KEY (`sales_order_id`) REFERENCES `sales_orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── PURCHASE ORDERS ──────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `purchase_orders` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `number` VARCHAR(50) NOT NULL,
  `date` DATE NOT NULL,
  `expected_date` DATE,
  `supplier_id` BIGINT UNSIGNED,
  `supplier_name` VARCHAR(255),
  `currency` VARCHAR(10) NOT NULL DEFAULT 'SAR',
  `subtotal` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `tax_amount` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `total` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `status` ENUM('Draft','Submitted','Approved','Received','Cancelled') NOT NULL DEFAULT 'Draft',
  `notes` TEXT,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `purchase_orders_number_unique` (`number`),
  KEY `purchase_orders_supplier_id_index` (`supplier_id`),
  CONSTRAINT `purchase_orders_supplier_fk` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `purchase_order_lines` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `purchase_order_id` BIGINT UNSIGNED NOT NULL,
  `item_id` BIGINT UNSIGNED,
  `item_name` VARCHAR(255),
  `qty` DECIMAL(15,3) NOT NULL DEFAULT 1,
  `uom` VARCHAR(50),
  `rate` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `amount` DECIMAL(15,2) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  CONSTRAINT `purchase_order_lines_po_fk` FOREIGN KEY (`purchase_order_id`) REFERENCES `purchase_orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── PAYMENTS ─────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `payments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `number` VARCHAR(50) NOT NULL,
  `date` DATE NOT NULL,
  `type` ENUM('Receive','Pay') NOT NULL DEFAULT 'Receive',
  `party_type` ENUM('Customer','Supplier') NOT NULL DEFAULT 'Customer',
  `party_id` BIGINT UNSIGNED,
  `party_name` VARCHAR(255),
  `amount` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `currency` VARCHAR(10) NOT NULL DEFAULT 'SAR',
  `bank_account` VARCHAR(255),
  `reference` VARCHAR(255),
  `notes` TEXT,
  `status` ENUM('Draft','Confirmed','Cancelled') NOT NULL DEFAULT 'Draft',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `payments_number_unique` (`number`),
  KEY `payments_date_index` (`date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── CRM ──────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `crm_leads` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `number` VARCHAR(50) NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `email` VARCHAR(255),
  `phone` VARCHAR(50),
  `company` VARCHAR(255),
  `source` ENUM('Website','Referral','Cold Call','Exhibition','Social Media','Other') NOT NULL DEFAULT 'Other',
  `status` ENUM('New','Contacted','Qualified','Unqualified','Converted') NOT NULL DEFAULT 'New',
  `assigned_to` VARCHAR(255),
  `notes` TEXT,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `crm_leads_number_unique` (`number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `crm_deals` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `number` VARCHAR(50) NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `contact_name` VARCHAR(255),
  `organization` VARCHAR(255),
  `value` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `currency` VARCHAR(10) NOT NULL DEFAULT 'SAR',
  `stage` ENUM('Qualification','Demo','Proposal / Quotation','Negotiation','Ready to Close','Won','Lost') NOT NULL DEFAULT 'Qualification',
  `expected_close` DATE,
  `notes` TEXT,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `crm_deals_number_unique` (`number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `crm_contacts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `first_name` VARCHAR(255) NOT NULL,
  `last_name` VARCHAR(255),
  `email` VARCHAR(255),
  `mobile` VARCHAR(50),
  `organization` VARCHAR(255),
  `job_title` VARCHAR(255),
  `notes` TEXT,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `crm_organizations` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(255) NOT NULL,
  `industry` VARCHAR(255),
  `website` VARCHAR(255),
  `email` VARCHAR(255),
  `phone` VARCHAR(50),
  `country` VARCHAR(100),
  `notes` TEXT,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── FINANCE ──────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `fin_accounts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(50) NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `type` ENUM('Asset','Liability','Equity','Revenue','Expense') NOT NULL,
  `parent_id` BIGINT UNSIGNED,
  `currency` VARCHAR(10) NOT NULL DEFAULT 'SAR',
  `balance` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `notes` TEXT,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fin_accounts_code_unique` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `fin_journal_entries` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `number` VARCHAR(50) NOT NULL,
  `date` DATE NOT NULL,
  `description` TEXT,
  `total_debit` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `total_credit` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `status` ENUM('Draft','Posted','Cancelled') NOT NULL DEFAULT 'Draft',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fin_journal_entries_number_unique` (`number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `fin_journal_lines` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `journal_entry_id` BIGINT UNSIGNED NOT NULL,
  `account_id` BIGINT UNSIGNED,
  `account_name` VARCHAR(255),
  `debit` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `credit` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `notes` TEXT,
  PRIMARY KEY (`id`),
  CONSTRAINT `fin_journal_lines_entry_fk` FOREIGN KEY (`journal_entry_id`) REFERENCES `fin_journal_entries` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `fin_bank_accounts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `account_name` VARCHAR(255) NOT NULL,
  `bank_name` VARCHAR(255),
  `account_number` VARCHAR(100),
  `iban` VARCHAR(100),
  `currency` VARCHAR(10) NOT NULL DEFAULT 'SAR',
  `balance` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `fin_currencies` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(10) NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `symbol` VARCHAR(10),
  `exchange_rate` DECIMAL(10,4) NOT NULL DEFAULT 1,
  `is_base` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fin_currencies_code_unique` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `fin_cost_centers` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(50) NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `department` VARCHAR(255),
  `budget` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `currency` VARCHAR(10) NOT NULL DEFAULT 'SAR',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fin_cost_centers_code_unique` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `fiscal_years` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `start_date` DATE NOT NULL,
  `end_date` DATE NOT NULL,
  `is_closed` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tax_templates` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(255) NOT NULL,
  `type` ENUM('Sales','Purchase') NOT NULL DEFAULT 'Sales',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tax_template_rates` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tax_template_id` BIGINT UNSIGNED NOT NULL,
  `account_head` VARCHAR(255),
  `rate` DECIMAL(5,2) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  CONSTRAINT `tax_template_rates_fk` FOREIGN KEY (`tax_template_id`) REFERENCES `tax_templates` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `payment_orders` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `number` VARCHAR(50) NOT NULL,
  `date` DATE NOT NULL,
  `party_name` VARCHAR(255),
  `amount` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `currency` VARCHAR(10) NOT NULL DEFAULT 'SAR',
  `status` ENUM('Draft','Submitted','Cancelled') NOT NULL DEFAULT 'Draft',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `payment_orders_number_unique` (`number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── HR ───────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `employees` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `employee_id` VARCHAR(50),
  `first_name` VARCHAR(255) NOT NULL,
  `last_name` VARCHAR(255),
  `email` VARCHAR(255),
  `mobile` VARCHAR(50),
  `department` VARCHAR(255),
  `designation` VARCHAR(255),
  `branch` VARCHAR(255),
  `employment_type` VARCHAR(100),
  `date_of_joining` DATE,
  `salary` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `currency` VARCHAR(10) NOT NULL DEFAULT 'SAR',
  `status` ENUM('Active','Inactive','Left') NOT NULL DEFAULT 'Active',
  `notes` TEXT,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `employees_employee_id_unique` (`employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `departments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(255) NOT NULL,
  `parent_department` VARCHAR(255),
  `head_employee` VARCHAR(255),
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `designations` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(255) NOT NULL,
  `description` TEXT,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `employment_types` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(255) NOT NULL,
  `description` TEXT,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `salary_components` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(255) NOT NULL,
  `type` ENUM('Earning','Deduction') NOT NULL DEFAULT 'Earning',
  `amount_type` ENUM('Fixed','Percentage') NOT NULL DEFAULT 'Fixed',
  `amount` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `description` TEXT,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `salary_structures` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(255) NOT NULL,
  `description` TEXT,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `salary_structure_components` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `salary_structure_id` BIGINT UNSIGNED NOT NULL,
  `component_name` VARCHAR(255),
  `type` ENUM('Earning','Deduction') NOT NULL DEFAULT 'Earning',
  `amount` DECIMAL(15,2) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  CONSTRAINT `salary_structure_components_fk` FOREIGN KEY (`salary_structure_id`) REFERENCES `salary_structures` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `salary_slips` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `number` VARCHAR(50) NOT NULL,
  `employee_id` BIGINT UNSIGNED,
  `employee_name` VARCHAR(255),
  `month` INT NOT NULL,
  `year` INT NOT NULL,
  `gross_pay` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `total_deduction` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `net_pay` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `status` ENUM('Draft','Submitted','Paid') NOT NULL DEFAULT 'Draft',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `salary_slips_number_unique` (`number`),
  CONSTRAINT `salary_slips_employee_fk` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `leave_types` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(255) NOT NULL,
  `max_days_allowed` INT NOT NULL DEFAULT 0,
  `is_paid` TINYINT(1) NOT NULL DEFAULT 1,
  `description` TEXT,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `leave_requests` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `number` VARCHAR(50) NOT NULL,
  `employee_id` BIGINT UNSIGNED,
  `employee_name` VARCHAR(255),
  `leave_type` VARCHAR(255),
  `from_date` DATE NOT NULL,
  `to_date` DATE NOT NULL,
  `days` INT NOT NULL DEFAULT 1,
  `reason` TEXT,
  `status` ENUM('Draft','Pending','Approved','Rejected') NOT NULL DEFAULT 'Draft',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `leave_requests_number_unique` (`number`),
  CONSTRAINT `leave_requests_employee_fk` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `holiday_lists` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(255) NOT NULL,
  `year` INT NOT NULL,
  `country` VARCHAR(100),
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `holiday_list_dates` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `holiday_list_id` BIGINT UNSIGNED NOT NULL,
  `date` DATE NOT NULL,
  `description` VARCHAR(255),
  PRIMARY KEY (`id`),
  CONSTRAINT `holiday_list_dates_fk` FOREIGN KEY (`holiday_list_id`) REFERENCES `holiday_lists` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `appraisals` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `number` VARCHAR(50) NOT NULL,
  `employee_id` BIGINT UNSIGNED,
  `employee_name` VARCHAR(255),
  `appraisal_date` DATE NOT NULL,
  `score` DECIMAL(5,2) NOT NULL DEFAULT 0,
  `status` ENUM('Draft','Submitted','Completed') NOT NULL DEFAULT 'Draft',
  `notes` TEXT,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `appraisals_number_unique` (`number`),
  CONSTRAINT `appraisals_employee_fk` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `promotions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `employee_id` BIGINT UNSIGNED,
  `employee_name` VARCHAR(255),
  `from_designation` VARCHAR(255),
  `to_designation` VARCHAR(255),
  `effective_date` DATE NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `promotions_employee_fk` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `transfers` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `employee_id` BIGINT UNSIGNED,
  `employee_name` VARCHAR(255),
  `from_branch` VARCHAR(255),
  `to_branch` VARCHAR(255),
  `from_department` VARCHAR(255),
  `to_department` VARCHAR(255),
  `effective_date` DATE NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `transfers_employee_fk` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `separations` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `employee_id` BIGINT UNSIGNED,
  `employee_name` VARCHAR(255),
  `separation_type` ENUM('Resignation','Termination','Retirement','Other') NOT NULL DEFAULT 'Resignation',
  `last_working_date` DATE NOT NULL,
  `reason` TEXT,
  `status` ENUM('Draft','Approved') NOT NULL DEFAULT 'Draft',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `separations_employee_fk` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `shift_types` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(255) NOT NULL,
  `start_time` TIME,
  `end_time` TIME,
  `description` TEXT,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `job_applicants` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `applicant_name` VARCHAR(255) NOT NULL,
  `email` VARCHAR(255),
  `phone` VARCHAR(50),
  `position` VARCHAR(255),
  `status` ENUM('Open','Interviewing','Offered','Hired','Rejected') NOT NULL DEFAULT 'Open',
  `notes` TEXT,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── MANUFACTURING ────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `bom` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `number` VARCHAR(50) NOT NULL,
  `item_id` BIGINT UNSIGNED,
  `item_name` VARCHAR(255),
  `quantity` DECIMAL(15,3) NOT NULL DEFAULT 1,
  `uom` VARCHAR(50),
  `status` ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
  `notes` TEXT,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `bom_number_unique` (`number`),
  CONSTRAINT `bom_item_fk` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `bom_items` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `bom_id` BIGINT UNSIGNED NOT NULL,
  `item_id` BIGINT UNSIGNED,
  `item_name` VARCHAR(255),
  `quantity` DECIMAL(15,3) NOT NULL DEFAULT 1,
  `uom` VARCHAR(50),
  `rate` DECIMAL(15,2) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  CONSTRAINT `bom_items_bom_fk` FOREIGN KEY (`bom_id`) REFERENCES `bom` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `work_orders` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `number` VARCHAR(50) NOT NULL,
  `item_id` BIGINT UNSIGNED,
  `item_name` VARCHAR(255),
  `bom_id` BIGINT UNSIGNED,
  `qty` DECIMAL(15,3) NOT NULL DEFAULT 1,
  `planned_start` DATE,
  `planned_end` DATE,
  `status` ENUM('Draft','In Progress','Completed','Cancelled') NOT NULL DEFAULT 'Draft',
  `notes` TEXT,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `work_orders_number_unique` (`number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `job_cards` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `number` VARCHAR(50) NOT NULL,
  `work_order_id` BIGINT UNSIGNED,
  `operation` VARCHAR(255),
  `employee_name` VARCHAR(255),
  `planned_qty` DECIMAL(15,3) NOT NULL DEFAULT 1,
  `completed_qty` DECIMAL(15,3) NOT NULL DEFAULT 0,
  `status` ENUM('Open','In Progress','Completed') NOT NULL DEFAULT 'Open',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `job_cards_number_unique` (`number`),
  CONSTRAINT `job_cards_wo_fk` FOREIGN KEY (`work_order_id`) REFERENCES `work_orders` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── ASSETS ───────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `assets` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(50),
  `name` VARCHAR(255) NOT NULL,
  `asset_category` VARCHAR(255),
  `purchase_date` DATE,
  `purchase_amount` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `current_value` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `location` VARCHAR(255),
  `custodian` VARCHAR(255),
  `status` ENUM('Active','Under Maintenance','Disposed') NOT NULL DEFAULT 'Active',
  `notes` TEXT,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `asset_movements` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `asset_id` BIGINT UNSIGNED NOT NULL,
  `from_location` VARCHAR(255),
  `to_location` VARCHAR(255),
  `movement_date` DATE NOT NULL,
  `moved_by` VARCHAR(255),
  `notes` TEXT,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `asset_movements_asset_fk` FOREIGN KEY (`asset_id`) REFERENCES `assets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── PROJECTS ─────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `projects` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(255) NOT NULL,
  `description` TEXT,
  `customer_id` BIGINT UNSIGNED,
  `customer_name` VARCHAR(255),
  `start_date` DATE,
  `end_date` DATE,
  `budget` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `currency` VARCHAR(10) NOT NULL DEFAULT 'SAR',
  `status` ENUM('Open','In Progress','Completed','Cancelled') NOT NULL DEFAULT 'Open',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tasks` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title` VARCHAR(255) NOT NULL,
  `project_id` BIGINT UNSIGNED,
  `project_name` VARCHAR(255),
  `assigned_to` VARCHAR(255),
  `priority` ENUM('Low','Medium','High','Urgent') NOT NULL DEFAULT 'Medium',
  `status` ENUM('Open','In Progress','Completed','Cancelled') NOT NULL DEFAULT 'Open',
  `due_date` DATE,
  `description` TEXT,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `tasks_project_fk` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `timesheets` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `employee_name` VARCHAR(255),
  `project_id` BIGINT UNSIGNED,
  `project_name` VARCHAR(255),
  `task` VARCHAR(255),
  `date` DATE NOT NULL,
  `hours` DECIMAL(5,2) NOT NULL DEFAULT 0,
  `notes` TEXT,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `timesheets_project_fk` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── QUALITY ──────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `quality_procedures` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(255) NOT NULL,
  `procedure_type` VARCHAR(255),
  `description` TEXT,
  `steps` TEXT,
  `status` ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── POS ──────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `pos_profiles` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(255) NOT NULL,
  `branch` VARCHAR(255),
  `customer_name` VARCHAR(255),
  `currency` VARCHAR(10) NOT NULL DEFAULT 'SAR',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `pos_sessions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `pos_profile` VARCHAR(255),
  `cashier_name` VARCHAR(255),
  `opening_amount` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `closing_amount` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `status` ENUM('Open','Closed') NOT NULL DEFAULT 'Open',
  `opening_time` TIMESTAMP NULL,
  `closing_time` TIMESTAMP NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `pos_invoices` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `number` VARCHAR(50) NOT NULL,
  `pos_session_id` BIGINT UNSIGNED,
  `customer_name` VARCHAR(255),
  `total` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `currency` VARCHAR(10) NOT NULL DEFAULT 'SAR',
  `payment_method` ENUM('Cash','Card','Transfer') NOT NULL DEFAULT 'Cash',
  `status` ENUM('Draft','Paid','Cancelled') NOT NULL DEFAULT 'Draft',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `pos_invoices_number_unique` (`number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── WEBSITE ──────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `website_pages` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title` VARCHAR(255) NOT NULL,
  `slug` VARCHAR(255) NOT NULL,
  `content` LONGTEXT,
  `status` ENUM('Draft','Published') NOT NULL DEFAULT 'Draft',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `website_pages_slug_unique` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `blog_posts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title` VARCHAR(255) NOT NULL,
  `slug` VARCHAR(255) NOT NULL,
  `content` LONGTEXT,
  `author` VARCHAR(255),
  `status` ENUM('Draft','Published') NOT NULL DEFAULT 'Draft',
  `published_at` TIMESTAMP NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `blog_posts_slug_unique` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── SEED DATA ────────────────────────────────────────────────

-- Default admin user
INSERT IGNORE INTO `users` (`name`, `email`, `password_hash`, `role`) VALUES
('Admin', 'admin@manzuma.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin'),
('Abdelrhman', 'abdelrhmansamybadr@gmail.com', '$2b$10$x6XWmi3Fcc7Pt9iQcVTGCuD/kEuYn.966A8QZnILmAyP5kwuq1mvG', 'admin');

-- Currencies
INSERT IGNORE INTO `fin_currencies` (`code`, `name`, `symbol`, `exchange_rate`, `is_base`) VALUES
('SAR', 'Saudi Riyal', '﷼', 1.0000, 1),
('AED', 'UAE Dirham', 'د.إ', 0.9800, 0),
('USD', 'US Dollar', '$', 3.7500, 0),
('EUR', 'Euro', '€', 4.0500, 0),
('GBP', 'British Pound', '£', 4.7200, 0),
('EGP', 'Egyptian Pound', 'E£', 0.0750, 0),
('KWD', 'Kuwaiti Dinar', 'KD', 12.1900, 0);

-- Default UOM
INSERT IGNORE INTO `uom` (`name`, `abbreviation`) VALUES
('Unit', 'Unit'), ('Kilogram', 'KG'), ('Gram', 'G'), ('Litre', 'L'),
('Meter', 'M'), ('Box', 'Box'), ('Piece', 'Pc'), ('Dozen', 'Dz'), ('Hour', 'Hr');

-- Default Payment Terms
INSERT IGNORE INTO `payment_terms` (`name`, `days`) VALUES
('Immediate', 0), ('Net 15', 15), ('Net 30', 30), ('Net 45', 45), ('Net 60', 60);

SET FOREIGN_KEY_CHECKS = 1;
