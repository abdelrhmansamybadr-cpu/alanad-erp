-- Phase 7: sorting (الفرز). A truck routed to sorting is sorted into three SELLABLE classes (stored as grade A / B / C batches):
--   class 1 = good, for the client (must be at least 80 % of the load, else the quality person files an incident report)
--   class 2 = surplus of normal quality     class 3 = lower quality     (all three are sold, to different clients)

CREATE TABLE IF NOT EXISTS `sorting_runs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` BIGINT UNSIGNED NOT NULL,
  `branch_id` BIGINT UNSIGNED NULL,
  `number` VARCHAR(50) NOT NULL,
  `delivery_id` BIGINT UNSIGNED NOT NULL,
  `status` ENUM('In_Progress','Awaiting_Signoff','Completed','Cancelled') NOT NULL DEFAULT 'In_Progress',
  `created_by` BIGINT UNSIGNED NULL,
  `results_by` BIGINT UNSIGNED NULL,
  `results_at` DATETIME NULL,
  `signed_by` BIGINT UNSIGNED NULL,
  `signed_at` DATETIME NULL,
  `gl_entry_id` BIGINT UNSIGNED NULL,
  `cancel_reason` VARCHAR(500) NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sorting_number` (`company_id`, `number`),
  KEY `idx_sorting_delivery` (`delivery_id`, `status`),
  KEY `idx_sorting_status` (`company_id`, `status`),
  KEY `idx_sorting_branch` (`branch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sorting_lines` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `run_id` BIGINT UNSIGNED NOT NULL,
  `delivery_line_id` BIGINT UNSIGNED NOT NULL,
  `item_id` BIGINT UNSIGNED NOT NULL,
  `input_kg` DECIMAL(14,3) NOT NULL,
  `class1_kg` DECIMAL(14,3) NULL,
  `class2_kg` DECIMAL(14,3) NULL,
  `class3_kg` DECIMAL(14,3) NULL,
  `shrink_kg` DECIMAL(14,3) NULL,
  `class1_pct` DECIMAL(6,2) NULL,
  `min_pct` DECIMAL(6,2) NULL,
  PRIMARY KEY (`id`),
  KEY `idx_sl_run` (`run_id`),
  CONSTRAINT `fk_sl_run` FOREIGN KEY (`run_id`) REFERENCES `sorting_runs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sorting_outputs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `run_id` BIGINT UNSIGNED NOT NULL,
  `line_id` BIGINT UNSIGNED NOT NULL,
  `class_no` TINYINT NOT NULL,
  `kg` DECIMAL(14,3) NOT NULL,
  `batch_id` BIGINT UNSIGNED NOT NULL,
  `cold_room_id` BIGINT UNSIGNED NOT NULL,
  `unit_cost` DECIMAL(12,4) NULL,
  PRIMARY KEY (`id`),
  KEY `idx_so_run` (`run_id`),
  CONSTRAINT `fk_so_run` FOREIGN KEY (`run_id`) REFERENCES `sorting_runs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sorting_incidents` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` BIGINT UNSIGNED NOT NULL,
  `run_id` BIGINT UNSIGNED NOT NULL,
  `supplier_id` BIGINT UNSIGNED NULL,
  `reason_code` VARCHAR(40) NOT NULL,
  `note` VARCHAR(1000) NULL,
  `filed_by` BIGINT UNSIGNED NOT NULL,
  `filed_at` DATETIME NOT NULL,
  `status` ENUM('Open','Reviewed') NOT NULL DEFAULT 'Open',
  `claim_supplier` TINYINT(1) NOT NULL DEFAULT 0,
  `reviewed_by` BIGINT UNSIGNED NULL,
  `reviewed_at` DATETIME NULL,
  `review_note` VARCHAR(1000) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_incident_run` (`run_id`),
  KEY `idx_incident_company` (`company_id`, `status`),
  CONSTRAINT `fk_si_run` FOREIGN KEY (`run_id`) REFERENCES `sorting_runs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- per-item override of the class-1 minimum (empty = the company setting)
-- @optional
ALTER TABLE `items` ADD COLUMN `min_class1_pct` DECIMAL(5,2) NULL;

-- journal entries made by sorting
-- @optional
ALTER TABLE `gl_entries` MODIFY `source_type` ENUM('manual','receipt','ap_invoice','ap_payment','reversal','prep_issue','prep_complete','prep_waste','custody_issue','custody_settle','sorting') NOT NULL DEFAULT 'manual';

INSERT IGNORE INTO `settings` (`company_id`, `setting_key`, `setting_value`, `value_type`, `group_name`, `label_en`, `label_ar`)
SELECT c.id, s.k, s.v, s.t, 'sorting', s.en, s.ar FROM `companies` c JOIN (
  SELECT 'sorting.min_class1_pct' AS k, '80' AS v, 'decimal' AS t, 'Lowest share of class 1 (good, for the client) in a sorted load (%); below it an incident report is required' AS en, 'أقل نسبة للدرجة الأولى (الجيدة للعميل) في الحمولة المفروزة (%)، وما دونها يلزم تقرير حادثة' AS ar
  UNION ALL SELECT 'sorting.balance_tolerance_pct', '2', 'decimal', 'Allowed gap between the kg that came in and the three classes + shrink (%)', 'الفرق المسموح بين الكمية الداخلة ومجموع الدرجات الثلاث والفاقد (%)'
  UNION ALL SELECT 'sorting.require_incident_below_min', '1', 'bool', 'A load below the class-1 minimum cannot be signed off without an incident report', 'لا يُعتمد فرز أقل من الحد الأدنى للدرجة الأولى بدون تقرير حادثة'
) s;

-- reasons for the incident report
INSERT IGNORE INTO `reject_reasons` (`company_id`, `gate`, `code`, `name_en`, `name_ar`)
SELECT c.id, 'sorting', r.k, r.en, r.ar FROM `companies` c JOIN (
  SELECT 'poor_harvest' AS k, 'Poor harvest / quality of the crop' AS en, 'ضعف المحصول أو جودته' AS ar
  UNION ALL SELECT 'weather_damage', 'Weather damage', 'ضرر الطقس'
  UNION ALL SELECT 'transport_damage', 'Damage during transport', 'ضرر أثناء النقل'
  UNION ALL SELECT 'market_grade_mismatch', 'Not the grade that was inspected at the market', 'لا يطابق الدرجة التي فُحصت في السوق'
  UNION ALL SELECT 'storage_age', 'Too old / held too long before arriving', 'قديم أو تأخر قبل الوصول'
  UNION ALL SELECT 'other', 'Other (write a note)', 'أخرى (اكتب ملاحظة)'
) r;

-- roles: the sorter enters the results; the quality inspector signs them off and files incident reports
INSERT INTO `roles` (`company_id`, `role_key`, `name_en`, `name_ar`, `is_system`)
SELECT NULL, 'sorter', 'Sorter', 'مسؤول الفرز', 1 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `roles` WHERE `role_key` = 'sorter' AND `company_id` IS NULL);
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission`)
SELECT r.id, p.perm FROM `roles` r JOIN (
  SELECT 'sorter' AS k, '*.view' AS perm UNION ALL SELECT 'sorter', 'sorting-runs.create' UNION ALL SELECT 'sorter', 'sorting-runs.edit'
  UNION ALL SELECT 'quality_inspector', 'sorting-runs.signoff'
) p ON p.k = r.role_key WHERE r.company_id IS NULL;
