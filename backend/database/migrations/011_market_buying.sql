-- Phase 3: buying at the market. Buyer prepares a purchase offer, the quality person inspects each line at the market BEFORE buying,
-- Finance approves, then gives the buyer cash (custody / عهدة) that the buyer must settle with receipts.

CREATE TABLE IF NOT EXISTS `market_purchases` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` BIGINT UNSIGNED NOT NULL,
  `branch_id` BIGINT UNSIGNED NULL,
  `number` VARCHAR(50) NOT NULL,
  `buyer_id` BIGINT UNSIGNED NOT NULL,
  `market_name` VARCHAR(255) NOT NULL,
  `supplier_id` BIGINT UNSIGNED NOT NULL,
  `client_order_id` BIGINT UNSIGNED NULL,
  `status` ENUM('Draft','Submitted','Approved','Returned','Cash_Issued','Settlement_Submitted','Settled','Cancelled') NOT NULL DEFAULT 'Draft',
  `notes` TEXT NULL,
  `total_amount` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `po_id` BIGINT UNSIGNED NULL,
  `submitted_at` DATETIME NULL,
  `approved_by` BIGINT UNSIGNED NULL,
  `approved_at` DATETIME NULL,
  `return_reason` VARCHAR(500) NULL,
  `issued_amount` DECIMAL(15,2) NULL,
  `cash_issued_by` BIGINT UNSIGNED NULL,
  `cash_issued_at` DATETIME NULL,
  `due_date` DATE NULL,
  `spent_amount` DECIMAL(15,2) NULL,
  `returned_amount` DECIMAL(15,2) NULL,
  `settlement_note` VARCHAR(500) NULL,
  `settlement_submitted_at` DATETIME NULL,
  `settled_by` BIGINT UNSIGNED NULL,
  `settled_at` DATETIME NULL,
  `gl_issue_entry_id` BIGINT UNSIGNED NULL,
  `gl_settle_entry_id` BIGINT UNSIGNED NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_market_purchases_number` (`company_id`, `number`),
  KEY `idx_mp_status` (`company_id`, `status`),
  KEY `idx_mp_buyer` (`company_id`, `buyer_id`, `status`),
  KEY `idx_mp_branch` (`branch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `market_purchase_lines` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `purchase_id` BIGINT UNSIGNED NOT NULL,
  `item_id` BIGINT UNSIGNED NOT NULL,
  `qty_kg` DECIMAL(14,3) NOT NULL,
  `offer_price` DECIMAL(12,2) NOT NULL,
  `inspect_result` ENUM('Pending','Pass','Fail') NOT NULL DEFAULT 'Pending',
  `inspect_grade` VARCHAR(10) NULL,
  `inspect_note` VARCHAR(500) NULL,
  `inspected_by` BIGINT UNSIGNED NULL,
  `inspected_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `idx_mpl_purchase` (`purchase_id`),
  CONSTRAINT `fk_mpl_purchase` FOREIGN KEY (`purchase_id`) REFERENCES `market_purchases` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Accounts: the cash box becomes a system account and the buyers' custody (employee advances) gets its own account.
-- @optional
UPDATE `gl_accounts` SET `system_key` = 'cash' WHERE `code` = '1120' AND `system_key` IS NULL;

INSERT IGNORE INTO `gl_accounts` (`company_id`, `code`, `name_en`, `name_ar`, `type`, `is_postable`, `system_key`)
SELECT c.id, '1130', 'Buyers custody (cash advances)', 'عهد المشترين النقدية', 'asset', 1, 'custody' FROM `companies` c;
UPDATE `gl_accounts` c JOIN `gl_accounts` p ON p.company_id = c.company_id AND p.code = '1000' SET c.parent_id = p.id WHERE c.code = '1130' AND c.parent_id IS NULL;

-- Settings (defaults follow common ERP practice for employee advances; all editable)
INSERT IGNORE INTO `settings` (`company_id`, `setting_key`, `setting_value`, `value_type`, `group_name`, `label_en`, `label_ar`)
SELECT c.id, s.k, s.v, s.t, 'market', s.en, s.ar FROM `companies` c JOIN (
  SELECT 'market.custody_limit_per_buyer' AS k, '0' AS v, 'decimal' AS t, 'Highest total of open cash custody one buyer may hold (SAR, 0 = no limit)' AS en, 'أعلى مجموع عُهَد مفتوحة يحملها مشترٍ واحد (ريال، ٠ = بلا حد)' AS ar
  UNION ALL SELECT 'market.settlement_days', '3', 'int', 'Days a buyer has to settle a custody with receipts', 'عدد الأيام المتاحة للمشتري لتسوية العهدة بالإيصالات'
  UNION ALL SELECT 'market.block_if_overdue', '1', 'bool', 'No new cash for a buyer who has an overdue unsettled custody', 'لا نقد جديد لمشترٍ لديه عهدة متأخرة لم تُسوَّ'
  UNION ALL SELECT 'market.advance_pct', '100', 'decimal', 'Cash given as % of the approved purchase total', 'النقد المُسلَّم كنسبة من إجمالي الشراء المعتمد (%)'
  UNION ALL SELECT 'market.require_field_inspection', '1', 'bool', 'Every line must be inspected by the quality person at the market before it goes to Finance', 'كل بند يجب أن يفحصه مسؤول الجودة في السوق قبل إرساله للمالية'
) s;

-- A system role for the quality person who inspects at the market (view everything, inspect market purchases, place quality holds).
INSERT INTO `roles` (`company_id`, `role_key`, `name_en`, `name_ar`, `is_system`)
SELECT NULL, 'quality_inspector', 'Quality Inspector', 'مسؤول الجودة (فاحص)', 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `roles` WHERE `role_key` = 'quality_inspector' AND `company_id` IS NULL);
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission`)
SELECT r.id, p.perm FROM `roles` r JOIN (
  SELECT '*.view' AS perm UNION ALL SELECT 'market-purchases.inspect' UNION ALL SELECT 'quality-holds.create' UNION ALL SELECT 'quality-holds.edit'
) p WHERE r.role_key = 'quality_inspector' AND r.company_id IS NULL;

-- Journal entries made by the custody steps
-- @optional
ALTER TABLE `gl_entries` MODIFY `source_type` ENUM('manual','receipt','ap_invoice','ap_payment','reversal','prep_issue','prep_complete','prep_waste','custody_issue','custody_settle') NOT NULL DEFAULT 'manual';
