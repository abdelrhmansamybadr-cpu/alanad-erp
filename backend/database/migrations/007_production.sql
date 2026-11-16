-- ============================================================
-- Alanad ERP — Migration 007: Production and Cold Chain (Phase 5)
-- Preparation orders (wash, sort, trim, pack): materials issued oldest-expiry first with their real cost,
-- a timer for the time outside the cold room, wash-water / sanitiser / hygiene logs (HACCP style),
-- outputs by grade A/B/C, waste by reason with a decision (rework, by-product, scrap sale, destroy),
-- a named inspector, yield against plan, and work-in-progress costing with waste.
-- ============================================================

-- the notifications table was only in a stray file (database/notifications_migration.sql): a fresh install needs it too
CREATE TABLE IF NOT EXISTS `notifications` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NULL COMMENT 'NULL = broadcast to all users',
  `type` VARCHAR(50) NOT NULL DEFAULT 'info',
  `title` VARCHAR(255) NOT NULL,
  `body` TEXT NOT NULL,
  `data` JSON NULL,
  `is_read` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_notifications_user` (`user_id`, `is_read`),
  KEY `idx_notifications_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- every batch knows what it cost per kg and, when it was made here, which preparation order made it (traceability)
ALTER TABLE `item_batches` ADD COLUMN `unit_cost` DECIMAL(15,4) NULL;
ALTER TABLE `item_batches` ADD COLUMN `prep_order_id` BIGINT UNSIGNED NULL;

-- batches that came in on a purchase order carry the order price
UPDATE `item_batches` b JOIN (SELECT l.batch_id, MAX(l.ref_id) AS po FROM `stock_ledger` l WHERE l.ref_type = 'purchase_order' AND l.movement = 'receipt' GROUP BY l.batch_id) x ON x.batch_id = b.id
SET b.unit_cost = (SELECT SUM(pl.qty * pl.rate) / NULLIF(SUM(pl.qty), 0) FROM `purchase_order_lines` pl WHERE pl.purchase_order_id = x.po AND pl.item_id = b.item_id) WHERE b.unit_cost IS NULL;

ALTER TABLE `gl_entries` MODIFY `source_type` ENUM('manual','receipt','ap_invoice','ap_payment','reversal','prep_issue','prep_complete','prep_waste') NOT NULL DEFAULT 'manual';

-- ─── accounts the production postings need ───────────────────
INSERT IGNORE INTO `gl_accounts` (`company_id`, `code`, `name_en`, `name_ar`, `type`, `is_postable`, `system_key`)
SELECT c.id, a.code, a.en, a.ar, a.t, 1, a.k FROM `companies` c JOIN (
  SELECT '1320' AS code, 'Work in progress' AS en, 'إنتاج تحت التشغيل' AS ar, 'asset' AS t, 'wip' AS k
  UNION ALL SELECT '4200', 'Scrap sales', 'مبيعات الخردة والهالك', 'revenue', 'scrap_income'
) a;

UPDATE `gl_accounts` SET `system_key` = 'cash' WHERE `code` = '1120' AND `system_key` IS NULL;
UPDATE `gl_accounts` c JOIN `gl_accounts` p ON p.company_id = c.company_id AND p.code = CONCAT(SUBSTRING(c.code, 1, 1), '000') SET c.parent_id = p.id WHERE c.is_postable = 1 AND c.parent_id IS NULL;

-- ─── specifications (the "recipe" of a prepared product) ─────
CREATE TABLE IF NOT EXISTS `prep_specs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `code` VARCHAR(30) NOT NULL,
  `name_en` VARCHAR(160) NOT NULL,
  `name_ar` VARCHAR(160) NULL,
  `input_item_id` BIGINT UNSIGNED NOT NULL,
  `output_item_id` BIGINT UNSIGNED NOT NULL,
  `byproduct_item_id` BIGINT UNSIGNED NULL,
  `planned_yield_pct` DECIMAL(5,2) NOT NULL DEFAULT 85,
  `yield_tolerance_pct` DECIMAL(5,2) NOT NULL DEFAULT 3,
  `waste_high_pct` DECIMAL(5,2) NOT NULL DEFAULT 20 COMMENT 'waste above this share of the input is a "waste high" result',
  `max_minutes_outside` INT NOT NULL DEFAULT 45,
  `requires_wash` TINYINT(1) NOT NULL DEFAULT 1,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_prep_spec_code` (`company_id`, `code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `prep_waste_reasons` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` BIGINT UNSIGNED NOT NULL,
  `code` VARCHAR(40) NOT NULL,
  `name_en` VARCHAR(160) NOT NULL,
  `name_ar` VARCHAR(160) NULL,
  `waste_class` ENUM('high','low') NOT NULL DEFAULT 'low' COMMENT 'high = still has value (rework / by-product), low = mostly rubbish',
  `default_decision` ENUM('rework','by_product','scrap_sale','destroy') NOT NULL DEFAULT 'destroy',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_prep_waste_reason` (`company_id`, `code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `prep_waste_reasons` (`company_id`, `code`, `name_en`, `name_ar`, `waste_class`, `default_decision`)
SELECT c.id, r.code, r.en, r.ar, r.cls, r.dflt FROM `companies` c JOIN (
  SELECT 'trim' AS code, 'Trim and peel' AS en, 'قصاصات وقشور' AS ar, 'low' AS cls, 'by_product' AS dflt
  UNION ALL SELECT 'rot', 'Rotten or spoiled', 'تالف أو متعفن', 'low', 'destroy'
  UNION ALL SELECT 'damage', 'Damaged but usable', 'مجروح لكن صالح', 'high', 'rework'
  UNION ALL SELECT 'size', 'Wrong size', 'مقاس غير مطابق', 'high', 'rework'
  UNION ALL SELECT 'cosmetic', 'Cosmetic defect', 'عيب شكلي', 'high', 'scrap_sale'
) r;

-- ─── preparation orders ──────────────────────────────────────
CREATE TABLE IF NOT EXISTS `prep_orders` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `number` VARCHAR(30) NOT NULL,
  `spec_id` BIGINT UNSIGNED NOT NULL,
  `planned_input_kg` DECIMAL(14,3) NOT NULL,
  `status` ENUM('Draft','Approved','In_Progress','Awaiting_Inspection','Completed','Cancelled') NOT NULL DEFAULT 'Draft',
  `input_kg` DECIMAL(14,3) NULL,
  `issued_cost` DECIMAL(15,2) NULL,
  `output_kg` DECIMAL(14,3) NULL,
  `waste_kg` DECIMAL(14,3) NULL,
  `yield_pct` DECIMAL(6,2) NULL,
  `waste_pct` DECIMAL(6,2) NULL,
  `result` ENUM('high_quality','waste_high') NULL,
  `started_at` DATETIME NULL,
  `finished_at` DATETIME NULL,
  `minutes_outside` INT NULL,
  `over_time` TINYINT(1) NULL,
  `overtime_note` VARCHAR(255) NULL,
  `yield_reason` VARCHAR(255) NULL,
  `created_by` BIGINT UNSIGNED NULL,
  `approved_by` BIGINT UNSIGNED NULL,
  `approved_at` DATETIME NULL,
  `results_by` BIGINT UNSIGNED NULL,
  `inspected_by` BIGINT UNSIGNED NULL,
  `inspected_at` DATETIME NULL,
  `inspection_note` VARCHAR(255) NULL,
  `cancel_reason` VARCHAR(255) NULL,
  `notes` TEXT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_prep_order_number` (`company_id`, `number`),
  KEY `idx_prep_order_status` (`company_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `prep_inputs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id` BIGINT UNSIGNED NOT NULL,
  `batch_id` BIGINT UNSIGNED NOT NULL,
  `item_id` BIGINT UNSIGNED NOT NULL,
  `cold_room_id` BIGINT UNSIGNED NULL,
  `supplier_id` BIGINT UNSIGNED NULL,
  `kg` DECIMAL(14,3) NOT NULL,
  `unit_cost` DECIMAL(15,4) NOT NULL DEFAULT 0,
  `cost` DECIMAL(15,2) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_prep_input_order` (`order_id`),
  CONSTRAINT `fk_prep_input_order` FOREIGN KEY (`order_id`) REFERENCES `prep_orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `prep_outputs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id` BIGINT UNSIGNED NOT NULL,
  `grade` ENUM('A','B','C') NOT NULL,
  `kg` DECIMAL(14,3) NOT NULL,
  `batch_id` BIGINT UNSIGNED NULL,
  `cold_room_id` BIGINT UNSIGNED NULL,
  `value` DECIMAL(15,2) NULL,
  PRIMARY KEY (`id`),
  KEY `idx_prep_output_order` (`order_id`),
  CONSTRAINT `fk_prep_output_order` FOREIGN KEY (`order_id`) REFERENCES `prep_orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `prep_waste` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id` BIGINT UNSIGNED NOT NULL,
  `reason_code` VARCHAR(40) NOT NULL,
  `waste_class` ENUM('high','low') NOT NULL,
  `kg` DECIMAL(14,3) NOT NULL,
  `decision` ENUM('rework','by_product','scrap_sale','destroy') NOT NULL,
  `scrap_income` DECIMAL(15,2) NULL,
  `status` ENUM('Planned','Pending','Done','Returned') NOT NULL DEFAULT 'Planned',
  `cost` DECIMAL(15,2) NULL,
  `batch_id` BIGINT UNSIGNED NULL,
  `decision_note` VARCHAR(255) NULL,
  `decided_by` BIGINT UNSIGNED NULL,
  `decided_at` DATETIME NULL,
  `entry_id` BIGINT UNSIGNED NULL,
  PRIMARY KEY (`id`),
  KEY `idx_prep_waste_order` (`order_id`),
  CONSTRAINT `fk_prep_waste_order` FOREIGN KEY (`order_id`) REFERENCES `prep_orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── food-safety (HACCP style) logs ──────────────────────────
CREATE TABLE IF NOT EXISTS `haccp_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `kind` ENUM('wash_water','sanitiser','hygiene') NOT NULL,
  `prep_order_id` BIGINT UNSIGNED NULL,
  `value` DECIMAL(8,2) NULL,
  `person` VARCHAR(120) NULL,
  `ok` TINYINT(1) NOT NULL,
  `note` VARCHAR(255) NULL,
  `corrective_action` VARCHAR(255) NULL,
  `created_by` BIGINT UNSIGNED NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_haccp_time` (`company_id`, `created_at`),
  KEY `idx_haccp_order` (`prep_order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── settings ────────────────────────────────────────────────
INSERT IGNORE INTO `settings` (`company_id`, `setting_key`, `setting_value`, `value_type`, `group_name`, `label_en`, `label_ar`)
SELECT c.id, s.k, s.v, s.t, 'production', s.en, s.ar FROM `companies` c JOIN (
  SELECT 'production.wash_water_max_c' AS k, '10' AS v, 'decimal' AS t, 'Highest accepted wash-water temperature (°C)' AS en, 'أعلى حرارة مقبولة لمياه الغسيل (°م)' AS ar
  UNION ALL SELECT 'production.sanitiser_min_ppm', '100', 'decimal', 'Lowest accepted sanitiser level (ppm)', 'أقل تركيز مقبول للمعقّم (ppm)'
  UNION ALL SELECT 'production.sanitiser_max_ppm', '200', 'decimal', 'Highest accepted sanitiser level (ppm)', 'أعلى تركيز مقبول للمعقّم (ppm)'
  UNION ALL SELECT 'production.balance_tolerance_pct', '3', 'decimal', 'Allowed gap between input and weighed output + waste (%)', 'الفرق المسموح بين المدخل ووزن المخرج + الهالك (%)'
) s;
