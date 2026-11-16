-- ============================================================
-- Alanad ERP — Migration 003: Inventory and Cold Rooms (Phase 1)
-- Warehouses, cold rooms (temperature / humidity rules), batches with
-- arrival and expiry dates, an append-only stock ledger (FEFO), readings,
-- alarms and quality holds.
-- ============================================================

-- ─── Items: shelf life and storage rules ─────────────────────
ALTER TABLE `items` ADD COLUMN `shelf_life_days` INT NULL;
ALTER TABLE `items` ADD COLUMN `storage_temp_min` DECIMAL(5,1) NULL;
ALTER TABLE `items` ADD COLUMN `storage_temp_max` DECIMAL(5,1) NULL;
ALTER TABLE `items` ADD COLUMN `humidity_min` DECIMAL(5,1) NULL;
ALTER TABLE `items` ADD COLUMN `humidity_max` DECIMAL(5,1) NULL;
ALTER TABLE `items` ADD COLUMN `compat_group` VARCHAR(40) NULL COMMENT 'items of different groups must not share a cold room';
ALTER TABLE `items` ADD COLUMN `barcode` VARCHAR(64) NULL;
ALTER TABLE `items` ADD COLUMN `name_ar` VARCHAR(255) NULL;

CREATE TABLE IF NOT EXISTS `unit_conversions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `item_id` BIGINT UNSIGNED NOT NULL,
  `unit` VARCHAR(30) NOT NULL COMMENT 'crate, carton, pallet ...',
  `kg_per_unit` DECIMAL(12,3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_unit_conv` (`company_id`, `item_id`, `unit`),
  KEY `idx_unit_conv_company` (`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Warehouses and cold rooms ───────────────────────────────
CREATE TABLE IF NOT EXISTS `warehouses` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `code` VARCHAR(30) NOT NULL,
  `name` VARCHAR(160) NOT NULL,
  `name_ar` VARCHAR(160) NULL,
  `type` ENUM('dry','cold','freezer','receiving','dispatch','hold') NOT NULL DEFAULT 'cold',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_warehouses_code` (`company_id`, `code`),
  KEY `idx_warehouses_company` (`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `cold_rooms` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `warehouse_id` BIGINT UNSIGNED NOT NULL,
  `code` VARCHAR(30) NOT NULL,
  `name` VARCHAR(160) NOT NULL,
  `name_ar` VARCHAR(160) NULL,
  `temp_min` DECIMAL(5,1) NOT NULL,
  `temp_max` DECIMAL(5,1) NOT NULL,
  `humidity_min` DECIMAL(5,1) NULL,
  `humidity_max` DECIMAL(5,1) NULL,
  `capacity_kg` DECIMAL(14,3) NULL,
  `compat_group` VARCHAR(40) NULL,
  `sensor_id` VARCHAR(60) NULL,
  `status` ENUM('ok','alarm','hold') NOT NULL DEFAULT 'ok',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cold_rooms_code` (`company_id`, `code`),
  KEY `idx_cold_rooms_company` (`company_id`),
  CONSTRAINT `fk_cold_rooms_wh` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Batches (arrival + expiry dates are mandatory) ──────────
CREATE TABLE IF NOT EXISTS `item_batches` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `item_id` BIGINT UNSIGNED NOT NULL,
  `batch_no` VARCHAR(60) NOT NULL,
  `supplier_id` BIGINT UNSIGNED NULL,
  `arrival_date` DATE NOT NULL,
  `harvest_date` DATE NULL,
  `expiry_date` DATE NOT NULL,
  `grade` ENUM('A','B','C') NULL,
  `qty_received` DECIMAL(14,3) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_batch_no` (`company_id`, `batch_no`),
  KEY `idx_batches_item_expiry` (`company_id`, `item_id`, `expiry_date`),
  CONSTRAINT `fk_batches_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Stock ledger: every movement, never edited ──────────────
CREATE TABLE IF NOT EXISTS `stock_ledger` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `item_id` BIGINT UNSIGNED NOT NULL,
  `batch_id` BIGINT UNSIGNED NOT NULL,
  `warehouse_id` BIGINT UNSIGNED NOT NULL,
  `cold_room_id` BIGINT UNSIGNED NULL,
  `rack` VARCHAR(20) NULL,
  `position` VARCHAR(20) NULL,
  `movement` ENUM('receipt','issue','transfer_in','transfer_out','adjust','waste','return') NOT NULL,
  `qty` DECIMAL(14,3) NOT NULL COMMENT 'signed: + in, - out (kg)',
  `reason` VARCHAR(255) NULL,
  `ref_type` VARCHAR(40) NULL,
  `ref_id` BIGINT UNSIGNED NULL,
  `user_id` BIGINT UNSIGNED NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ledger_balance` (`company_id`, `item_id`, `batch_id`, `cold_room_id`),
  KEY `idx_ledger_room` (`company_id`, `cold_room_id`),
  KEY `idx_ledger_time` (`company_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- @optional
CREATE TRIGGER `stock_ledger_no_update` BEFORE UPDATE ON `stock_ledger` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'stock_ledger is append-only';

-- @optional
CREATE TRIGGER `stock_ledger_no_delete` BEFORE DELETE ON `stock_ledger` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'stock_ledger is append-only';

-- ─── Temperature readings, alarms, quality holds ─────────────
CREATE TABLE IF NOT EXISTS `temperature_readings` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `cold_room_id` BIGINT UNSIGNED NOT NULL,
  `temperature` DECIMAL(5,1) NOT NULL,
  `humidity` DECIMAL(5,1) NULL,
  `source` ENUM('manual','sensor') NOT NULL DEFAULT 'manual',
  `user_id` BIGINT UNSIGNED NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_readings_room_time` (`company_id`, `cold_room_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `cold_room_alarms` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `cold_room_id` BIGINT UNSIGNED NOT NULL,
  `kind` ENUM('temp_high','temp_low','humidity') NOT NULL,
  `status` ENUM('open','acknowledged','recovered') NOT NULL DEFAULT 'open',
  `first_value` DECIMAL(5,1) NOT NULL,
  `opened_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `acknowledged_by` BIGINT UNSIGNED NULL,
  `acknowledged_note` VARCHAR(255) NULL,
  `recovered_at` TIMESTAMP NULL,
  `hold_id` BIGINT UNSIGNED NULL,
  PRIMARY KEY (`id`),
  KEY `idx_alarms_room_status` (`company_id`, `cold_room_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `quality_holds` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `cold_room_id` BIGINT UNSIGNED NULL,
  `batch_id` BIGINT UNSIGNED NULL,
  `reason` VARCHAR(255) NOT NULL,
  `status` ENUM('active','released') NOT NULL DEFAULT 'active',
  `auto` TINYINT(1) NOT NULL DEFAULT 0,
  `created_by` BIGINT UNSIGNED NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `released_by` BIGINT UNSIGNED NULL,
  `released_at` TIMESTAMP NULL,
  `release_note` VARCHAR(255) NULL,
  PRIMARY KEY (`id`),
  KEY `idx_holds_status` (`company_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Settings for this phase ─────────────────────────────────
INSERT IGNORE INTO `settings` (`company_id`, `setting_key`, `setting_value`, `value_type`, `group_name`, `label_en`, `label_ar`) VALUES
 (1, 'stock.alarm_hold_minutes', '30', 'int', 'stock', 'Alarm open longer than (minutes) puts the cold room on quality hold', 'إنذار مفتوح أكثر من (دقيقة) يحجز التلاجة للجودة'),
 (1, 'stock.use_first_days', '3', 'int', 'stock', 'Show batches expiring within (days) on the "use first" list', 'إظهار الدفعات التي تنتهي خلال (يوم) في قائمة استخدم أولاً'),
 (1, 'stock.allow_expired_issue', '0', 'bool', 'stock', 'Allow issuing expired batches', 'السماح بصرف الدفعات المنتهية'),
 (1, 'stock.enforce_room_rules', '1', 'bool', 'stock', 'Block storing an item in a cold room outside its temperature range or compatibility group', 'منع التخزين خارج نطاق الحرارة أو مجموعة التوافق');

INSERT IGNORE INTO `settings` (`company_id`, `setting_key`, `setting_value`, `value_type`, `group_name`, `label_en`, `label_ar`)
SELECT c.id, s.setting_key, s.setting_value, s.value_type, s.group_name, s.label_en, s.label_ar
FROM `companies` c JOIN `settings` s ON s.company_id = 1 AND s.setting_key IN ('stock.alarm_hold_minutes','stock.use_first_days','stock.allow_expired_issue','stock.enforce_room_rules')
WHERE c.id <> 1;
