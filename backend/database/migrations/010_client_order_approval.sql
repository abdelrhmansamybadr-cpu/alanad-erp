-- Phase 2 follow-up: optional approval of big client orders (a setting, off by default, as in Odoo / NetSuite).
-- @optional
ALTER TABLE `client_orders` ADD COLUMN `approved_by` BIGINT UNSIGNED NULL, ADD COLUMN `approved_at` TIMESTAMP NULL;

INSERT IGNORE INTO `settings` (`company_id`, `setting_key`, `setting_value`, `value_type`, `group_name`, `label_en`, `label_ar`)
SELECT c.id, s.k, s.v, s.t, 'sales', s.en, s.ar FROM `companies` c JOIN (
  SELECT 'sales.approval_above_kg' AS k, '0' AS v, 'decimal' AS t, 'A client order above this many kg needs approval by a second person before stock is reserved (0 = never)' AS en, 'طلب العميل الذي يزيد عن هذه الكمية (كجم) يحتاج موافقة شخص ثانٍ قبل حجز المخزون (٠ = لا يحتاج)' AS ar
) s;
