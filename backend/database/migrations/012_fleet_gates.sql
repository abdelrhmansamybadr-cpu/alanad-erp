-- Phase 4: the truck fleet (own and rented), the gates, the entry permit (إذن دخول) and the store's space pre-approval.

CREATE TABLE IF NOT EXISTS `vehicles` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` BIGINT UNSIGNED NOT NULL,
  `plate` VARCHAR(30) NOT NULL,
  `kind` ENUM('own','rented') NOT NULL DEFAULT 'own',
  `rental_company` VARCHAR(255) NULL,
  `capacity_kg` DECIMAL(12,3) NULL,
  `refrigerated` TINYINT(1) NOT NULL DEFAULT 0,
  `default_driver` VARCHAR(120) NULL,
  `default_driver_id_no` VARCHAR(40) NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `notes` VARCHAR(500) NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_vehicle_plate` (`company_id`, `plate`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `gates` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` BIGINT UNSIGNED NOT NULL,
  `branch_id` BIGINT UNSIGNED NULL,
  `code` VARCHAR(30) NOT NULL,
  `name` VARCHAR(120) NOT NULL,
  `name_ar` VARCHAR(120) NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_gate_code` (`company_id`, `code`),
  KEY `idx_gate_branch` (`branch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `entry_permits` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` BIGINT UNSIGNED NOT NULL,
  `branch_id` BIGINT UNSIGNED NULL,
  `number` VARCHAR(50) NOT NULL,
  `po_id` BIGINT UNSIGNED NOT NULL,
  `vehicle_id` BIGINT UNSIGNED NULL,
  `vehicle_plate` VARCHAR(30) NOT NULL,
  `driver_name` VARCHAR(120) NOT NULL,
  `driver_id_no` VARCHAR(40) NULL,
  `expected_kg` DECIMAL(14,3) NOT NULL,
  `status` ENUM('Requested','Store_Approved','Rejected','Issued','Entered','Cancelled') NOT NULL DEFAULT 'Requested',
  `gate_id` BIGINT UNSIGNED NULL,
  `store_by` BIGINT UNSIGNED NULL,
  `store_at` DATETIME NULL,
  `store_free_kg` DECIMAL(14,3) NULL,
  `store_note` VARCHAR(500) NULL,
  `issued_by` BIGINT UNSIGNED NULL,
  `issued_at` DATETIME NULL,
  `delivery_id` BIGINT UNSIGNED NULL,
  `entered_at` DATETIME NULL,
  `cancel_reason` VARCHAR(500) NULL,
  `created_by` BIGINT UNSIGNED NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_entry_permit_number` (`company_id`, `number`),
  KEY `idx_ep_status` (`company_id`, `status`),
  KEY `idx_ep_po` (`po_id`),
  KEY `idx_ep_branch` (`branch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- @optional
ALTER TABLE `deliveries` ADD COLUMN `entry_permit_id` BIGINT UNSIGNED NULL;

-- Settings. The entry-permit rule starts OFF so existing companies keep working; the factory switches it on in Settings → Fleet.
INSERT IGNORE INTO `settings` (`company_id`, `setting_key`, `setting_value`, `value_type`, `group_name`, `label_en`, `label_ar`)
SELECT c.id, s.k, s.v, s.t, 'fleet', s.en, s.ar FROM `companies` c JOIN (
  SELECT 'fleet.require_entry_permit' AS k, '0' AS v, 'bool' AS t, 'A truck cannot be registered at the gate without an issued entry permit' AS en, 'لا تُسجَّل شاحنة عند البوابة بدون إذن دخول صادر' AS ar
  UNION ALL SELECT 'fleet.require_store_approval', '1', 'bool', 'The store must approve that there is space before an entry permit is issued', 'يجب أن يوافق المخزن على وجود مساحة قبل إصدار إذن الدخول'
  UNION ALL SELECT 'fleet.space_check_strict', '0', 'bool', 'Refuse (instead of warning) when the expected load is more than the free cold-room space', 'الرفض (بدل التحذير) إذا كان الحمل المتوقع أكبر من المساحة الحرة في غرف التبريد'
  UNION ALL SELECT 'fleet.allow_double_booking', '0', 'bool', 'Allow one truck on two open entry permits at the same time', 'السماح بشاحنة واحدة على إذني دخول مفتوحين في الوقت نفسه'
) s;

-- Roles: the store keeper (pre-approves space) and the gate officer (issues the permit, registers the arrival)
INSERT INTO `roles` (`company_id`, `role_key`, `name_en`, `name_ar`, `is_system`)
SELECT NULL, 'storekeeper', 'Store Keeper', 'أمين المخزن', 1 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `roles` WHERE `role_key` = 'storekeeper' AND `company_id` IS NULL);
INSERT INTO `roles` (`company_id`, `role_key`, `name_en`, `name_ar`, `is_system`)
SELECT NULL, 'gate_officer', 'Gate Officer', 'مسؤول البوابة', 1 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `roles` WHERE `role_key` = 'gate_officer' AND `company_id` IS NULL);
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission`)
SELECT r.id, p.perm FROM `roles` r JOIN (
  SELECT 'storekeeper' AS k, '*.view' AS perm UNION ALL SELECT 'storekeeper', 'entry-permits.store'
  UNION ALL SELECT 'gate_officer', '*.view' UNION ALL SELECT 'gate_officer', 'entry-permits.create' UNION ALL SELECT 'gate_officer', 'entry-permits.gate' UNION ALL SELECT 'gate_officer', 'deliveries.create'
) p ON p.k = r.role_key WHERE r.company_id IS NULL;
