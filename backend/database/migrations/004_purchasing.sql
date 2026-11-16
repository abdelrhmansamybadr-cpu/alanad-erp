-- ============================================================
-- Alanad ERP — Migration 004: Purchasing (Phase 2)
-- Dated supplier prices, purchase requests (PR) with supervisor approval,
-- purchase orders (PO) created from approved PRs with finance approval
-- above a limit, reorder levels on items.
-- ============================================================

-- items.reorder_level already exists in the base schema: below this on-hand quantity (kg) the item appears on the purchase suggestions

-- ─── Dated supplier price lists ──────────────────────────────
CREATE TABLE IF NOT EXISTS `supplier_prices` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `supplier_id` BIGINT UNSIGNED NOT NULL,
  `item_id` BIGINT UNSIGNED NOT NULL,
  `price` DECIMAL(15,4) NOT NULL COMMENT 'per kg',
  `valid_from` DATE NOT NULL,
  `created_by` BIGINT UNSIGNED NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_supplier_price` (`company_id`, `supplier_id`, `item_id`, `valid_from`),
  KEY `idx_supplier_prices_lookup` (`company_id`, `supplier_id`, `item_id`, `valid_from`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Purchase requests ───────────────────────────────────────
CREATE TABLE IF NOT EXISTS `purchase_requests` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `number` VARCHAR(50) NOT NULL,
  `date` DATE NOT NULL,
  `needed_by` DATE NULL,
  `requested_by` BIGINT UNSIGNED NOT NULL,
  `status` ENUM('Draft','Pending','Approved','Rejected','Converted','Cancelled') NOT NULL DEFAULT 'Draft',
  `workflow_instance_id` BIGINT UNSIGNED NULL,
  `reject_reason` VARCHAR(255) NULL,
  `notes` TEXT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pr_number` (`company_id`, `number`),
  KEY `idx_pr_status` (`company_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `purchase_request_lines` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `purchase_request_id` BIGINT UNSIGNED NOT NULL,
  `item_id` BIGINT UNSIGNED NOT NULL,
  `qty` DECIMAL(14,3) NOT NULL,
  `note` VARCHAR(255) NULL,
  PRIMARY KEY (`id`),
  KEY `idx_prl_pr` (`purchase_request_id`),
  CONSTRAINT `fk_prl_pr` FOREIGN KEY (`purchase_request_id`) REFERENCES `purchase_requests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Purchase orders: link to the request and the approval ───
ALTER TABLE `purchase_orders` ADD COLUMN `purchase_request_id` BIGINT UNSIGNED NULL;
ALTER TABLE `purchase_orders` ADD COLUMN `workflow_instance_id` BIGINT UNSIGNED NULL;
ALTER TABLE `purchase_orders` ADD COLUMN `approved_by` BIGINT UNSIGNED NULL;
ALTER TABLE `purchase_orders` ADD COLUMN `approved_at` DATETIME NULL;
ALTER TABLE `purchase_orders` ADD COLUMN `created_by` BIGINT UNSIGNED NULL;

-- ─── Approval flows and settings ─────────────────────────────
INSERT IGNORE INTO `workflows` (`company_id`, `wf_key`, `name_en`, `name_ar`, `doc_type`) VALUES
 (1, 'purchase_request', 'Purchase request approval', 'اعتماد طلب الشراء', 'purchase_request'),
 (1, 'purchase_order', 'Purchase order approval (Finance)', 'اعتماد أمر الشراء (المالية)', 'purchase_order');

INSERT IGNORE INTO `workflow_steps` (`workflow_id`, `position`, `name_en`, `name_ar`, `role_key`, `rule`)
SELECT w.id, 1, 'Supervisor approval', 'اعتماد المشرف', 'manager', 'maker_not_checker' FROM `workflows` w WHERE w.company_id = 1 AND w.wf_key = 'purchase_request';

INSERT IGNORE INTO `workflow_steps` (`workflow_id`, `position`, `name_en`, `name_ar`, `role_key`, `rule`)
SELECT w.id, 1, 'Finance approval', 'اعتماد المالية', 'manager', 'maker_not_checker' FROM `workflows` w WHERE w.company_id = 1 AND w.wf_key = 'purchase_order';

INSERT IGNORE INTO `settings` (`company_id`, `setting_key`, `setting_value`, `value_type`, `group_name`, `label_en`, `label_ar`) VALUES
 (1, 'purchasing.pr_approval_required', '1', 'bool', 'purchasing', 'Purchase requests need supervisor approval', 'طلبات الشراء تحتاج اعتماد المشرف');

INSERT IGNORE INTO `settings` (`company_id`, `setting_key`, `setting_value`, `value_type`, `group_name`, `label_en`, `label_ar`)
SELECT c.id, 'purchasing.pr_approval_required', '1', 'bool', 'purchasing', 'Purchase requests need supervisor approval', 'طلبات الشراء تحتاج اعتماد المشرف'
FROM `companies` c WHERE c.id <> 1;
