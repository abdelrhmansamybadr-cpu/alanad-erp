-- ============================================================
-- Alanad ERP — Migration 006: Finance (Phase 4)
-- Saudi chart of accounts, an append-only double-entry ledger, supplier
-- invoices with a 3-way match (order <-> net weight received <-> invoice),
-- supplier payments with approval, VAT. Posting is automatic: goods stored
-- in a cold room debit Inventory / credit "Goods received not invoiced";
-- an approved invoice clears that account into Payables; a payment clears
-- Payables into the Bank.
-- ============================================================

CREATE TABLE IF NOT EXISTS `gl_accounts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `code` VARCHAR(20) NOT NULL,
  `name_en` VARCHAR(160) NOT NULL,
  `name_ar` VARCHAR(160) NULL,
  `type` ENUM('asset','liability','equity','revenue','expense') NOT NULL,
  `parent_id` BIGINT UNSIGNED NULL,
  `is_postable` TINYINT(1) NOT NULL DEFAULT 1,
  `system_key` VARCHAR(30) NULL COMMENT 'accounts the system posts to: inventory, grni, ap, vat_input, vat_output, bank, price_variance, waste, sales, cogs',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_gl_account_code` (`company_id`, `code`),
  KEY `idx_gl_account_key` (`company_id`, `system_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `gl_entries` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `number` VARCHAR(30) NOT NULL,
  `date` DATE NOT NULL,
  `description` VARCHAR(255) NOT NULL,
  `source_type` ENUM('manual','receipt','ap_invoice','ap_payment','reversal') NOT NULL DEFAULT 'manual',
  `source_id` BIGINT UNSIGNED NULL,
  `status` ENUM('Posted','Reversed') NOT NULL DEFAULT 'Posted',
  `reversal_of` BIGINT UNSIGNED NULL,
  `total` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `created_by` BIGINT UNSIGNED NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_gl_entry_number` (`company_id`, `number`),
  UNIQUE KEY `uq_gl_entry_source` (`company_id`, `source_type`, `source_id`),
  KEY `idx_gl_entry_date` (`company_id`, `date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `gl_lines` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `entry_id` BIGINT UNSIGNED NOT NULL,
  `account_id` BIGINT UNSIGNED NOT NULL,
  `debit` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `credit` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `memo` VARCHAR(255) NULL,
  `supplier_id` BIGINT UNSIGNED NULL,
  PRIMARY KEY (`id`),
  KEY `idx_gl_line_entry` (`entry_id`),
  KEY `idx_gl_line_account` (`account_id`),
  CONSTRAINT `fk_gl_line_entry` FOREIGN KEY (`entry_id`) REFERENCES `gl_entries` (`id`),
  CONSTRAINT `fk_gl_line_account` FOREIGN KEY (`account_id`) REFERENCES `gl_accounts` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- @optional
CREATE TRIGGER `gl_lines_no_update` BEFORE UPDATE ON `gl_lines` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'gl_lines is append-only';

-- @optional
CREATE TRIGGER `gl_lines_no_delete` BEFORE DELETE ON `gl_lines` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'gl_lines is append-only';

CREATE TABLE IF NOT EXISTS `ap_invoices` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `number` VARCHAR(30) NOT NULL,
  `supplier_id` BIGINT UNSIGNED NOT NULL,
  `supplier_invoice_no` VARCHAR(60) NOT NULL,
  `invoice_date` DATE NOT NULL,
  `due_date` DATE NOT NULL,
  `purchase_order_id` BIGINT UNSIGNED NOT NULL,
  `status` ENUM('Draft','Pending','On_Hold','Approved','Rejected') NOT NULL DEFAULT 'Draft',
  `vat_rate_pct` DECIMAL(5,2) NOT NULL DEFAULT 15,
  `subtotal` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `vat_amount` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `total` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `paid_amount` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `match_status` ENUM('matched','mismatch') NULL,
  `match_note` TEXT NULL,
  `decision_note` VARCHAR(255) NULL,
  `reject_reason_code` VARCHAR(40) NULL,
  `decided_by` BIGINT UNSIGNED NULL,
  `decided_at` DATETIME NULL,
  `entry_id` BIGINT UNSIGNED NULL,
  `notes` TEXT NULL,
  `created_by` BIGINT UNSIGNED NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ap_invoice_number` (`company_id`, `number`),
  UNIQUE KEY `uq_ap_supplier_invoice` (`company_id`, `supplier_id`, `supplier_invoice_no`),
  KEY `idx_ap_invoice_status` (`company_id`, `status`),
  KEY `idx_ap_invoice_po` (`company_id`, `purchase_order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ap_invoice_lines` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `invoice_id` BIGINT UNSIGNED NOT NULL,
  `item_id` BIGINT UNSIGNED NOT NULL,
  `qty_kg` DECIMAL(14,3) NOT NULL,
  `price` DECIMAL(15,4) NOT NULL,
  `amount` DECIMAL(15,2) NOT NULL,
  `po_price` DECIMAL(15,4) NULL,
  `received_kg` DECIMAL(14,3) NULL,
  `billable_kg` DECIMAL(14,3) NULL,
  `issue` VARCHAR(255) NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ap_line_invoice` (`invoice_id`),
  CONSTRAINT `fk_ap_line_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `ap_invoices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ap_payments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `number` VARCHAR(30) NOT NULL,
  `supplier_id` BIGINT UNSIGNED NOT NULL,
  `date` DATE NOT NULL,
  `amount` DECIMAL(15,2) NOT NULL,
  `method` ENUM('bank_transfer','cheque','cash') NOT NULL DEFAULT 'bank_transfer',
  `reference` VARCHAR(100) NULL,
  `bank_account_id` BIGINT UNSIGNED NULL COMMENT 'the ledger account the money leaves (a bank / cash account)',
  `status` ENUM('Pending','Posted','Rejected') NOT NULL DEFAULT 'Pending',
  `reject_reason_code` VARCHAR(40) NULL,
  `decision_note` VARCHAR(255) NULL,
  `decided_by` BIGINT UNSIGNED NULL,
  `decided_at` DATETIME NULL,
  `entry_id` BIGINT UNSIGNED NULL,
  `created_by` BIGINT UNSIGNED NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ap_payment_number` (`company_id`, `number`),
  KEY `idx_ap_payment_status` (`company_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ap_payment_allocations` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `payment_id` BIGINT UNSIGNED NOT NULL,
  `invoice_id` BIGINT UNSIGNED NOT NULL,
  `amount` DECIMAL(15,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ap_alloc_payment` (`payment_id`),
  KEY `idx_ap_alloc_invoice` (`invoice_id`),
  CONSTRAINT `fk_ap_alloc_payment` FOREIGN KEY (`payment_id`) REFERENCES `ap_payments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Chart of accounts (Saudi starter set, every company gets its own copy) ───
INSERT IGNORE INTO `gl_accounts` (`company_id`, `code`, `name_en`, `name_ar`, `type`, `is_postable`, `system_key`)
SELECT c.id, a.code, a.en, a.ar, a.t, a.p, a.k FROM `companies` c JOIN (
  SELECT '1000' AS code, 'Assets' AS en, 'الأصول' AS ar, 'asset' AS t, 0 AS p, NULL AS k
  UNION ALL SELECT '1110', 'Bank - main account', 'البنك - الحساب الرئيسي', 'asset', 1, 'bank'
  UNION ALL SELECT '1120', 'Cash on hand', 'النقدية بالصندوق', 'asset', 1, NULL
  UNION ALL SELECT '1210', 'Trade receivables', 'العملاء', 'asset', 1, NULL
  UNION ALL SELECT '1310', 'Inventory - fresh produce', 'المخزون - خضار وفاكهة', 'asset', 1, 'inventory'
  UNION ALL SELECT '1410', 'VAT input (recoverable)', 'ضريبة القيمة المضافة - مدخلات', 'asset', 1, 'vat_input'
  UNION ALL SELECT '2000', 'Liabilities', 'الالتزامات', 'liability', 0, NULL
  UNION ALL SELECT '2110', 'Trade payables (suppliers)', 'الموردون - ذمم دائنة', 'liability', 1, 'ap'
  UNION ALL SELECT '2120', 'Goods received not invoiced', 'بضاعة مستلمة لم تُفوتَر', 'liability', 1, 'grni'
  UNION ALL SELECT '2210', 'VAT output', 'ضريبة القيمة المضافة - مخرجات', 'liability', 1, 'vat_output'
  UNION ALL SELECT '3000', 'Equity', 'حقوق الملكية', 'equity', 0, NULL
  UNION ALL SELECT '3100', 'Capital', 'رأس المال', 'equity', 1, NULL
  UNION ALL SELECT '3200', 'Retained earnings', 'الأرباح المبقاة', 'equity', 1, NULL
  UNION ALL SELECT '4000', 'Revenue', 'الإيرادات', 'revenue', 0, NULL
  UNION ALL SELECT '4100', 'Sales', 'المبيعات', 'revenue', 1, 'sales'
  UNION ALL SELECT '5000', 'Expenses', 'المصروفات', 'expense', 0, NULL
  UNION ALL SELECT '5100', 'Cost of goods sold', 'تكلفة البضاعة المباعة', 'expense', 1, 'cogs'
  UNION ALL SELECT '5200', 'Purchase price variance', 'فروقات أسعار الشراء', 'expense', 1, 'price_variance'
  UNION ALL SELECT '5300', 'Waste and shrinkage', 'الهالك والفاقد', 'expense', 1, 'waste'
  UNION ALL SELECT '5400', 'Bank charges', 'عمولات بنكية', 'expense', 1, NULL
) a;

UPDATE `gl_accounts` c JOIN `gl_accounts` p ON p.company_id = c.company_id AND p.code = CONCAT(SUBSTRING(c.code, 1, 1), '000') SET c.parent_id = p.id WHERE c.is_postable = 1 AND c.parent_id IS NULL;

-- ─── Settings ────────────────────────────────────────────────
INSERT IGNORE INTO `settings` (`company_id`, `setting_key`, `setting_value`, `value_type`, `group_name`, `label_en`, `label_ar`)
SELECT c.id, s.k, s.v, s.t, 'finance', s.en, s.ar FROM `companies` c JOIN (
  SELECT 'finance.match_qty_tolerance_pct' AS k, '2' AS v, 'decimal' AS t, 'Invoice quantity may exceed the quantity received by (%)' AS en, 'يُسمح أن تزيد كمية الفاتورة عن المستلمة بنسبة (%)' AS ar
  UNION ALL SELECT 'finance.payment_terms_days', '30', 'int', 'Default days from invoice date to due date', 'عدد الأيام الافتراضي من تاريخ الفاتورة إلى الاستحقاق'
  UNION ALL SELECT 'finance.closed_until', '', 'string', 'No postings on or before this date (period close, YYYY-MM-DD)', 'لا ترحيل في أو قبل هذا التاريخ (إقفال الفترة)'
) s;

-- ─── Reject reasons for the finance gate ─────────────────────
INSERT IGNORE INTO `reject_reasons` (`company_id`, `gate`, `code`, `name_en`, `name_ar`)
SELECT c.id, 'finance', r.code, r.en, r.ar FROM `companies` c JOIN (
  SELECT 'duplicate_invoice' AS code, 'Duplicate invoice' AS en, 'فاتورة مكررة' AS ar
  UNION ALL SELECT 'not_received', 'Goods not received', 'البضاعة لم تُستلم'
  UNION ALL SELECT 'price_mismatch', 'Price does not match the order', 'السعر لا يطابق أمر الشراء'
  UNION ALL SELECT 'quantity_mismatch', 'Quantity does not match what was received', 'الكمية لا تطابق المستلم'
  UNION ALL SELECT 'wrong_vat', 'Wrong VAT', 'ضريبة خاطئة'
  UNION ALL SELECT 'missing_document', 'Missing document', 'مستند ناقص'
  UNION ALL SELECT 'other', 'Other (note required)', 'أخرى (الملاحظة إلزامية)'
) r;
