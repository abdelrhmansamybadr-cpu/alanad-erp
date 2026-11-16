-- Phase 12: IT core inside the ERP, shaped by the finished TechDesk project: helpdesk tickets with SLA, and the IT asset register
-- (laptops, phones, barcode scanners, label printers, scales, cold-room sensors, network gear) with warranty alerts.
-- Remote support (WebRTC meetings), the knowledge base and the Flutter apps stay in TechDesk (linked from the IT screen).

CREATE TABLE IF NOT EXISTS `it_assets` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` BIGINT UNSIGNED NOT NULL,
  `branch_id` BIGINT UNSIGNED NULL,
  `code` VARCHAR(40) NOT NULL,
  `name` VARCHAR(160) NOT NULL,
  `type` ENUM('laptop','desktop','phone','scanner','printer','scale','sensor','network','other') NOT NULL DEFAULT 'other',
  `brand` VARCHAR(80) NULL,
  `model` VARCHAR(80) NULL,
  `serial_no` VARCHAR(80) NULL,
  `location` VARCHAR(160) NULL,
  `assigned_employee_id` BIGINT UNSIGNED NULL,
  `status` ENUM('In_Use','In_Stock','Repair','Retired') NOT NULL DEFAULT 'In_Use',
  `purchase_date` DATE NULL,
  `warranty_end` DATE NULL,
  `cost` DECIMAL(12,2) NULL,
  `notes` VARCHAR(1000) NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_it_asset_code` (`company_id`, `code`),
  KEY `idx_it_asset_branch` (`branch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `it_tickets` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` BIGINT UNSIGNED NOT NULL,
  `branch_id` BIGINT UNSIGNED NULL,
  `number` VARCHAR(50) NOT NULL,
  `requester_id` BIGINT UNSIGNED NOT NULL,
  `category` ENUM('hardware','software','network','account','erp','other') NOT NULL DEFAULT 'other',
  `priority` ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
  `subject` VARCHAR(200) NOT NULL,
  `description` TEXT NULL,
  `status` ENUM('New','Assigned','In_Progress','Waiting','Resolved','Closed','Cancelled') NOT NULL DEFAULT 'New',
  `assignee_id` BIGINT UNSIGNED NULL,
  `asset_id` BIGINT UNSIGNED NULL,
  `due_at` DATETIME NULL,
  `first_response_at` DATETIME NULL,
  `resolved_at` DATETIME NULL,
  `resolution` VARCHAR(2000) NULL,
  `closed_at` DATETIME NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_it_ticket_number` (`company_id`, `number`),
  KEY `idx_it_ticket_status` (`company_id`, `status`),
  KEY `idx_it_ticket_requester` (`requester_id`),
  KEY `idx_it_ticket_assignee` (`assignee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `it_ticket_comments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `body` VARCHAR(4000) NOT NULL,
  `is_internal` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_it_comment_ticket` (`ticket_id`),
  CONSTRAINT `fk_it_comment_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `it_tickets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `settings` (`company_id`, `setting_key`, `setting_value`, `value_type`, `group_name`, `label_en`, `label_ar`)
SELECT c.id, s.k, s.v, s.t, 'it', s.en, s.ar FROM `companies` c JOIN (
  SELECT 'it.sla_hours_critical' AS k, '4' AS v, 'int' AS t, 'Hours to solve a critical ticket' AS en, 'ساعات حل التذكرة الحرجة' AS ar
  UNION ALL SELECT 'it.sla_hours_high', '8', 'int', 'Hours to solve a high-priority ticket', 'ساعات حل التذكرة العالية'
  UNION ALL SELECT 'it.sla_hours_medium', '24', 'int', 'Hours to solve a medium ticket', 'ساعات حل التذكرة المتوسطة'
  UNION ALL SELECT 'it.sla_hours_low', '72', 'int', 'Hours to solve a low-priority ticket', 'ساعات حل التذكرة المنخفضة'
  UNION ALL SELECT 'it.warranty_warning_days', '60', 'int', 'Warn this many days before a device warranty ends', 'التنبيه قبل انتهاء ضمان الجهاز بعدد الأيام'
  UNION ALL SELECT 'it.auto_close_days', '7', 'int', 'A resolved ticket closes by itself after this many days (0 = never)', 'تُغلق التذكرة المحلولة تلقائياً بعد هذه الأيام (٠ = أبداً)'
  UNION ALL SELECT 'it.portal_url', 'https://it.quadrotechs.com', 'string', 'Address of TechDesk (remote support, knowledge base, mobile app)', 'عنوان TechDesk (الدعم عن بُعد وقاعدة المعرفة وتطبيق الجوال)'
) s;

INSERT IGNORE INTO `module_switches` (`company_id`, `module_key`, `is_enabled`) SELECT id, 'it', 1 FROM `companies`;

INSERT INTO `roles` (`company_id`, `role_key`, `name_en`, `name_ar`, `is_system`)
SELECT NULL, 'it_staff', 'IT Support', 'دعم تقنية المعلومات', 1 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `roles` WHERE `role_key` = 'it_staff' AND `company_id` IS NULL);
INSERT INTO `roles` (`company_id`, `role_key`, `name_en`, `name_ar`, `is_system`)
SELECT NULL, 'it_manager', 'IT Manager', 'مدير تقنية المعلومات', 1 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `roles` WHERE `role_key` = 'it_manager' AND `company_id` IS NULL);
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission`)
SELECT r.id, p.perm FROM `roles` r JOIN (
  SELECT 'it_staff' AS k, '*.view' AS perm UNION ALL SELECT 'it_staff', 'it-tickets.create' UNION ALL SELECT 'it_staff', 'it-tickets.work' UNION ALL SELECT 'it_staff', 'it-assets.create' UNION ALL SELECT 'it_staff', 'it-assets.edit'
  UNION ALL SELECT 'it_manager', '*.view' UNION ALL SELECT 'it_manager', 'it-tickets.create' UNION ALL SELECT 'it_manager', 'it-tickets.work' UNION ALL SELECT 'it_manager', 'it-tickets.manage' UNION ALL SELECT 'it_manager', 'it-assets.create' UNION ALL SELECT 'it_manager', 'it-assets.edit'
) p ON p.k = r.role_key WHERE r.company_id IS NULL;
