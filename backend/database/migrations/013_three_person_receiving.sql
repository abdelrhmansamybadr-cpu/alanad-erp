-- Phase 5: three people at the truck (quality + counter, then a Finance person who confirms papers and goods), then the route (sorting or store).

-- @optional
ALTER TABLE `deliveries`
  ADD COLUMN `counted_units` INT NULL, ADD COLUMN `count_unit` VARCHAR(20) NULL, ADD COLUMN `counted_by` BIGINT UNSIGNED NULL, ADD COLUMN `counted_at` DATETIME NULL, ADD COLUMN `count_note` VARCHAR(500) NULL,
  ADD COLUMN `finance_status` ENUM('Passed','Disputed') NULL, ADD COLUMN `finance_by` BIGINT UNSIGNED NULL, ADD COLUMN `finance_at` DATETIME NULL,
  ADD COLUMN `delivery_note_no` VARCHAR(60) NULL, ADD COLUMN `note_units` INT NULL, ADD COLUMN `papers_ok` TINYINT(1) NULL, ADD COLUMN `goods_ok` TINYINT(1) NULL, ADD COLUMN `finance_note` VARCHAR(500) NULL,
  ADD COLUMN `route` ENUM('store','sorting') NULL, ADD COLUMN `routed_by` BIGINT UNSIGNED NULL, ADD COLUMN `routed_at` DATETIME NULL;

-- @optional
ALTER TABLE `deliveries` MODIFY `status` ENUM('Arrived','Temp_Hold','Checking','Ready','Stored','Rejected','To_Sorting') NOT NULL DEFAULT 'Arrived';

-- Which items go through sorting (الفرز) after receiving, e.g. fresh tomatoes; the rest go straight to the store.
-- @optional
ALTER TABLE `items` ADD COLUMN `needs_sorting` TINYINT(1) NOT NULL DEFAULT 0;

INSERT IGNORE INTO `settings` (`company_id`, `setting_key`, `setting_value`, `value_type`, `group_name`, `label_en`, `label_ar`)
SELECT c.id, s.k, s.v, s.t, 'receiving', s.en, s.ar FROM `companies` c JOIN (
  SELECT 'receiving.three_person_rule' AS k, '0' AS v, 'bool' AS t, 'Every truck needs three different people: quality, a counter, then a Finance person, before it is routed' AS en, 'كل شاحنة تحتاج ثلاثة أشخاص مختلفين: الجودة ثم العدّاد ثم مسؤول المالية، قبل توجيهها' AS ar
  UNION ALL SELECT 'receiving.count_tolerance_pct', '1', 'decimal', 'Allowed difference between the counted units and the units on the delivery note (%)', 'الفرق المسموح بين العدد المُعدود والعدد في إشعار التسليم (%)'
) s;

INSERT INTO `roles` (`company_id`, `role_key`, `name_en`, `name_ar`, `is_system`)
SELECT NULL, 'counter', 'Counter', 'العدّاد', 1 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `roles` WHERE `role_key` = 'counter' AND `company_id` IS NULL);
INSERT INTO `roles` (`company_id`, `role_key`, `name_en`, `name_ar`, `is_system`)
SELECT NULL, 'receiving_finance', 'Receiving Finance Officer', 'مسؤول المالية للاستلام', 1 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `roles` WHERE `role_key` = 'receiving_finance' AND `company_id` IS NULL);
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission`)
SELECT r.id, p.perm FROM `roles` r JOIN (
  SELECT 'counter' AS k, '*.view' AS perm UNION ALL SELECT 'counter', 'deliveries.count'
  UNION ALL SELECT 'receiving_finance', '*.view' UNION ALL SELECT 'receiving_finance', 'deliveries.finance'
) p ON p.k = r.role_key WHERE r.company_id IS NULL;
