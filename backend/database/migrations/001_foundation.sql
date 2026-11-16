-- ============================================================
-- Alanad ERP — Migration 001: Foundation (Phase 0)
-- Companies, roles & permissions, settings, module switches,
-- audit log (append-only), login throttle, workflow engine.
-- Statements are split on ";" + newline. A line "-- @optional"
-- before a statement means: warn instead of failing if the host
-- refuses it (e.g. TRIGGER on hosts with binary logging).
-- ============================================================

-- ─── Companies (multi-company core) ─────────────────────────
CREATE TABLE IF NOT EXISTS `companies` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `group_name` VARCHAR(255) NULL,
  `name_en` VARCHAR(255) NOT NULL,
  `name_ar` VARCHAR(255) NULL,
  `legal_name` VARCHAR(255) NULL,
  `cr_number` VARCHAR(50) NULL,
  `vat_number` VARCHAR(50) NULL,
  `address` TEXT NULL,
  `currency` CHAR(3) NOT NULL DEFAULT 'SAR',
  `logo_path` VARCHAR(255) NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `companies` (`id`, `group_name`, `name_en`, `name_ar`, `currency`)
VALUES (1, 'Al Anad Group', 'Al Anad National', 'العناد الوطنية', 'SAR');

-- ─── Roles & permissions ────────────────────────────────────
CREATE TABLE IF NOT EXISTS `roles` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` BIGINT UNSIGNED NULL COMMENT 'NULL = system role shared by all companies',
  `role_key` VARCHAR(60) NOT NULL,
  `name_en` VARCHAR(120) NOT NULL,
  `name_ar` VARCHAR(120) NULL,
  `is_system` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_roles_key` (`company_id`, `role_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `role_permissions` (
  `role_id` BIGINT UNSIGNED NOT NULL,
  `permission` VARCHAR(120) NOT NULL COMMENT 'resource.action, e.g. invoices.approve; * and *.view wildcards allowed',
  PRIMARY KEY (`role_id`, `permission`),
  CONSTRAINT `fk_rp_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `roles` (`id`, `company_id`, `role_key`, `name_en`, `name_ar`, `is_system`) VALUES
 (1, NULL, 'admin',   'Company Admin', 'مدير النظام', 1),
 (2, NULL, 'manager', 'Manager / Approver', 'مدير / معتمِد', 1),
 (3, NULL, 'user',    'Standard User', 'مستخدم', 1),
 (4, NULL, 'viewer',  'Viewer (read only)', 'مشاهدة فقط', 1);

INSERT IGNORE INTO `role_permissions` (`role_id`, `permission`) VALUES
 (1, '*'),
 (2, '*.view'), (2, '*.create'), (2, '*.edit'), (2, '*.approve'), (2, '*.export'),
 (3, '*.view'), (3, '*.create'), (3, '*.edit'),
 (4, '*.view');

-- ─── Users: company, role link, language ────────────────────
ALTER TABLE `users` ADD COLUMN `role_id` BIGINT UNSIGNED NULL AFTER `role`;
ALTER TABLE `users` ADD COLUMN `company_id` BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER `role_id`;
ALTER TABLE `users` ADD COLUMN `lang` VARCHAR(5) NOT NULL DEFAULT 'ar' AFTER `company_id`;
ALTER TABLE `users` ADD COLUMN `must_change_password` TINYINT(1) NOT NULL DEFAULT 0 AFTER `lang`;

UPDATE `users` u JOIN `roles` r ON r.`role_key` = u.`role` AND r.`company_id` IS NULL SET u.`role_id` = r.`id` WHERE u.`role_id` IS NULL;

CREATE TABLE IF NOT EXISTS `user_companies` (
  `user_id` BIGINT UNSIGNED NOT NULL,
  `company_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`user_id`, `company_id`),
  CONSTRAINT `fk_uc_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Settings (every open question becomes an editable setting)
CREATE TABLE IF NOT EXISTS `settings` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` BIGINT UNSIGNED NOT NULL,
  `setting_key` VARCHAR(120) NOT NULL,
  `setting_value` TEXT NULL,
  `value_type` ENUM('string','int','decimal','bool','json') NOT NULL DEFAULT 'string',
  `group_name` VARCHAR(60) NOT NULL DEFAULT 'general',
  `label_en` VARCHAR(255) NULL,
  `label_ar` VARCHAR(255) NULL,
  `updated_by` BIGINT UNSIGNED NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_settings` (`company_id`, `setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `settings` (`company_id`, `setting_key`, `setting_value`, `value_type`, `group_name`, `label_en`, `label_ar`) VALUES
 (1, 'general.currency', 'SAR', 'string', 'general', 'Company currency', 'عملة الشركة'),
 (1, 'finance.vat_rate_pct', '15', 'decimal', 'finance', 'VAT rate (%)', 'نسبة ضريبة القيمة المضافة'),
 (1, 'finance.price_tolerance_pct', '2', 'decimal', 'finance', '3-way match: price tolerance (%)', 'المطابقة الثلاثية: سماح السعر'),
 (1, 'finance.po_approval_limit', '0', 'decimal', 'finance', 'Purchase order needs Finance above (0 = always)', 'حد اعتماد المالية لأمر الشراء'),
 (1, 'stock.over_receipt_pct', '5', 'decimal', 'stock', 'Over-receipt allowed (%)', 'نسبة الزيادة المسموحة في الاستلام'),
 (1, 'production.shrink_allowance_pct', '1.5', 'decimal', 'production', 'Allowed shrinkage (%)', 'النقص المسموح (%)'),
 (1, 'production.high_waste_pct', '15', 'decimal', 'production', 'High waste above (%) needs supervisor', 'هالك عالٍ يحتاج مراجعة المشرف'),
 (1, 'workflow.max_rejects_before_escalation', '3', 'int', 'workflow', 'Rejections before supervisor decides', 'عدد الارتدادات قبل قرار المشرف'),
 (1, 'workflow.maker_not_checker', '1', 'bool', 'workflow', 'Creator can never approve own document', 'المُنشئ لا يعتمد مستنده'),
 (1, 'auth.login_max_failures', '5', 'int', 'security', 'Failed logins before lock', 'محاولات الدخول الفاشلة قبل القفل'),
 (1, 'auth.login_lock_minutes', '15', 'int', 'security', 'Lock duration (minutes)', 'مدة القفل بالدقائق');

-- ─── Module switches (close = hidden + blocked, data kept) ──
CREATE TABLE IF NOT EXISTS `module_switches` (
  `company_id` BIGINT UNSIGNED NOT NULL,
  `module_key` VARCHAR(40) NOT NULL,
  `is_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `changed_by` BIGINT UNSIGNED NULL,
  `changed_at` TIMESTAMP NULL,
  PRIMARY KEY (`company_id`, `module_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `module_switches` (`company_id`, `module_key`, `is_enabled`) VALUES
 (1,'sales',1),(1,'crm',1),(1,'purchase',1),(1,'stock',1),(1,'finance',1),(1,'hr',1),
 (1,'manufacturing',1),(1,'quality',1),(1,'assets',1),(1,'projects',1),(1,'pos',1),(1,'website',1);

-- ─── Audit log (append-only) ────────────────────────────────
CREATE TABLE IF NOT EXISTS `audit_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` BIGINT UNSIGNED NULL,
  `user_id` BIGINT UNSIGNED NULL,
  `user_email` VARCHAR(255) NULL,
  `action` VARCHAR(40) NOT NULL,
  `resource` VARCHAR(80) NOT NULL,
  `resource_id` VARCHAR(40) NULL,
  `http_status` SMALLINT NULL,
  `details` JSON NULL,
  `ip` VARCHAR(64) NULL,
  `user_agent` VARCHAR(255) NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_company_time` (`company_id`, `created_at`),
  KEY `idx_audit_resource` (`resource`, `resource_id`),
  KEY `idx_audit_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- @optional
CREATE TRIGGER `audit_log_no_update` BEFORE UPDATE ON `audit_log` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_log is append-only';

-- @optional
CREATE TRIGGER `audit_log_no_delete` BEFORE DELETE ON `audit_log` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_log is append-only';

-- ─── Login throttle ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email` VARCHAR(255) NOT NULL,
  `ip` VARCHAR(64) NULL,
  `success` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_la_email_time` (`email`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Workflow engine ────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `reject_reasons` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` BIGINT UNSIGNED NOT NULL,
  `gate` VARCHAR(40) NOT NULL DEFAULT 'general',
  `code` VARCHAR(40) NOT NULL,
  `name_en` VARCHAR(160) NOT NULL,
  `name_ar` VARCHAR(160) NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_reject_reason` (`company_id`, `gate`, `code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `reject_reasons` (`company_id`, `gate`, `code`, `name_en`, `name_ar`) VALUES
 (1,'general','quantity_mismatch','Quantity does not match','الكمية لا تطابق'),
 (1,'general','quality_issue','Quality problem','مشكلة في الجودة'),
 (1,'general','price_mismatch','Price does not match','السعر لا يطابق'),
 (1,'general','missing_document','Missing document','مستند ناقص'),
 (1,'general','wrong_item','Wrong item','صنف خاطئ'),
 (1,'general','temperature','Temperature out of range','الحرارة خارج النطاق'),
 (1,'general','other','Other (note required)','أخرى (الملاحظة إلزامية)');

CREATE TABLE IF NOT EXISTS `workflows` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` BIGINT UNSIGNED NOT NULL,
  `wf_key` VARCHAR(60) NOT NULL,
  `name_en` VARCHAR(160) NOT NULL,
  `name_ar` VARCHAR(160) NULL,
  `doc_type` VARCHAR(60) NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_workflow_key` (`company_id`, `wf_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `workflow_steps` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `workflow_id` BIGINT UNSIGNED NOT NULL,
  `position` INT NOT NULL,
  `name_en` VARCHAR(160) NOT NULL,
  `name_ar` VARCHAR(160) NULL,
  `role_key` VARCHAR(60) NULL COMMENT 'who may act: role key (admin always may)',
  `user_id` BIGINT UNSIGNED NULL COMMENT 'or a named person',
  `rule` ENUM('maker_not_checker','any') NOT NULL DEFAULT 'maker_not_checker',
  `time_limit_hours` INT NULL,
  `reject_to_position` INT NULL COMMENT 'step a rejected document returns to (default: previous)',
  `required_fields` JSON NULL,
  `checklist` JSON NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wf_step` (`workflow_id`, `position`),
  CONSTRAINT `fk_wfs_workflow` FOREIGN KEY (`workflow_id`) REFERENCES `workflows` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `workflow_instances` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` BIGINT UNSIGNED NOT NULL,
  `workflow_id` BIGINT UNSIGNED NOT NULL,
  `doc_type` VARCHAR(60) NOT NULL,
  `doc_id` BIGINT UNSIGNED NOT NULL,
  `current_position` INT NOT NULL,
  `status` ENUM('open','approved','cancelled') NOT NULL DEFAULT 'open',
  `needs_supervisor` TINYINT(1) NOT NULL DEFAULT 0,
  `reject_count` INT NOT NULL DEFAULT 0,
  `created_by` BIGINT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_wfi_doc` (`company_id`, `doc_type`, `doc_id`),
  KEY `idx_wfi_status` (`company_id`, `status`),
  CONSTRAINT `fk_wfi_workflow` FOREIGN KEY (`workflow_id`) REFERENCES `workflows` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `workflow_actions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `instance_id` BIGINT UNSIGNED NOT NULL,
  `step_position` INT NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `action` ENUM('submit','approve','reject','decide_approve','decide_cancel','cancel') NOT NULL,
  `reason_code` VARCHAR(40) NULL,
  `note` TEXT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_wfa_instance` (`instance_id`),
  CONSTRAINT `fk_wfa_instance` FOREIGN KEY (`instance_id`) REFERENCES `workflow_instances` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default template from the client's sketch: Goods-in → Quality → Finance → Store
INSERT IGNORE INTO `workflows` (`id`, `company_id`, `wf_key`, `name_en`, `name_ar`, `doc_type`) VALUES
 (1, 1, 'goods_in_standard', 'Goods-in to Store (Standard)', 'من البضاعة إلى المخزن (قياسي)', 'goods_receipt');

INSERT IGNORE INTO `workflow_steps` (`workflow_id`, `position`, `name_en`, `name_ar`, `role_key`, `rule`, `reject_to_position`) VALUES
 (1, 1, 'Quality and Quantity', 'الجودة والكميات', 'user',    'maker_not_checker', NULL),
 (1, 2, 'Finance approval',     'اعتماد المالية',    'manager', 'maker_not_checker', 1),
 (1, 3, 'Store put-away',       'استلام المخزن',     'user',    'maker_not_checker', 2);
