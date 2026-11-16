-- ============================================================
-- Alanad ERP — Migration 005: Receiving, Weighing and Quality (Phase 3)
-- A delivery follows a truck from the gate to the cold room:
--   arrival (driver, plate, order) -> gross weight -> truck and product temperature
--   -> sampling, grade A/B/C, rejected kg with a reason -> tare weight (net weight)
--   -> quality sign-off -> put-away into a cold room (stock ledger, new batches).
-- ============================================================

CREATE TABLE IF NOT EXISTS `deliveries` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `number` VARCHAR(30) NOT NULL,
  `purchase_order_id` BIGINT UNSIGNED NOT NULL,
  `supplier_id` BIGINT UNSIGNED NULL,
  `driver_name` VARCHAR(120) NOT NULL,
  `driver_id_no` VARCHAR(40) NULL,
  `vehicle_plate` VARCHAR(30) NULL,
  `status` ENUM('Arrived','Temp_Hold','Checking','Ready','Stored','Rejected') NOT NULL DEFAULT 'Arrived',
  `arrived_at` DATETIME NOT NULL,
  `gross_kg` DECIMAL(14,3) NULL,
  `tare_kg` DECIMAL(14,3) NULL,
  `net_kg` DECIMAL(14,3) NULL,
  `truck_air_temp` DECIMAL(5,1) NULL,
  `product_temp` DECIMAL(5,1) NULL,
  `temp_ok` TINYINT(1) NULL,
  `temp_checked_by` BIGINT UNSIGNED NULL,
  `temp_decision_by` BIGINT UNSIGNED NULL,
  `temp_decision_note` VARCHAR(255) NULL,
  `quality_by` BIGINT UNSIGNED NULL,
  `quality_at` DATETIME NULL,
  `accepted_by` BIGINT UNSIGNED NULL,
  `accepted_at` DATETIME NULL,
  `reject_reason_code` VARCHAR(40) NULL,
  `reject_note` VARCHAR(255) NULL,
  `rejected_by` BIGINT UNSIGNED NULL,
  `rejected_at` DATETIME NULL,
  `notes` TEXT NULL,
  `created_by` BIGINT UNSIGNED NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_delivery_number` (`company_id`, `number`),
  KEY `idx_delivery_status` (`company_id`, `status`),
  KEY `idx_delivery_po` (`company_id`, `purchase_order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `delivery_lines` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `delivery_id` BIGINT UNSIGNED NOT NULL,
  `item_id` BIGINT UNSIGNED NOT NULL,
  `grade` ENUM('A','B','C') NOT NULL,
  `received_kg` DECIMAL(14,3) NOT NULL,
  `rejected_kg` DECIMAL(14,3) NOT NULL DEFAULT 0,
  `reject_reason_code` VARCHAR(40) NULL,
  `sample_kg` DECIMAL(14,3) NULL,
  `note` VARCHAR(255) NULL,
  `batch_id` BIGINT UNSIGNED NULL,
  `cold_room_id` BIGINT UNSIGNED NULL,
  `stored_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `idx_dl_delivery` (`delivery_id`),
  CONSTRAINT `fk_dl_delivery` FOREIGN KEY (`delivery_id`) REFERENCES `deliveries` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Settings (every open question is an editable setting) ───
INSERT IGNORE INTO `settings` (`company_id`, `setting_key`, `setting_value`, `value_type`, `group_name`, `label_en`, `label_ar`)
SELECT c.id, s.k, s.v, s.t, 'receiving', s.en, s.ar FROM `companies` c JOIN (
  SELECT 'receiving.truck_air_max' AS k, '8' AS v, 'decimal' AS t, 'Highest accepted truck air temperature (°C)' AS en, 'أعلى حرارة مقبولة لهواء الشاحنة (°م)' AS ar
  UNION ALL SELECT 'receiving.product_temp_max', '8', 'decimal', 'Highest accepted product temperature when the item has no limit of its own (°C)', 'أعلى حرارة مقبولة للمنتج إذا لم يكن للصنف حد خاص (°م)'
  UNION ALL SELECT 'receiving.product_temp_tolerance', '2', 'decimal', 'Product temperature tolerance above the item limit (°C)', 'سماح حرارة المنتج فوق حد الصنف (°م)'
  UNION ALL SELECT 'receiving.net_tolerance_pct', '2', 'decimal', 'Allowed gap between net weight and the weighed lines (%)', 'الفرق المسموح بين الوزن الصافي ووزن البنود (%)'
  UNION ALL SELECT 'receiving.max_wait_minutes', '60', 'int', 'Warn when a truck waits at the gate longer than (minutes)', 'تنبيه إذا انتظرت الشاحنة أكثر من (دقيقة)'
  UNION ALL SELECT 'receiving.allow_direct_po_receipt', '0', 'bool', 'Allow receiving an order straight into stock without the gate / quality steps', 'السماح باستلام الأمر مباشرة للمخزون دون خطوات البوابة والجودة'
) s;

-- ─── Reject reasons for the receiving gate ───────────────────
INSERT IGNORE INTO `reject_reasons` (`company_id`, `gate`, `code`, `name_en`, `name_ar`)
SELECT c.id, 'receiving', r.code, r.en, r.ar FROM `companies` c JOIN (
  SELECT 'rotten' AS code, 'Rotten or spoiled' AS en, 'تالف أو متعفن' AS ar
  UNION ALL SELECT 'wet', 'Wet or waterlogged', 'مبلل'
  UNION ALL SELECT 'wrong_size', 'Wrong size', 'مقاس خاطئ'
  UNION ALL SELECT 'below_grade', 'Below the lowest grade', 'أقل من أدنى درجة'
  UNION ALL SELECT 'damaged_packaging', 'Damaged packaging', 'تغليف تالف'
  UNION ALL SELECT 'mixed_items', 'Mixed or wrong items', 'أصناف مختلطة أو خاطئة'
  UNION ALL SELECT 'temperature', 'Temperature out of range', 'الحرارة خارج النطاق'
  UNION ALL SELECT 'other', 'Other (note required)', 'أخرى (الملاحظة إلزامية)'
) r;
