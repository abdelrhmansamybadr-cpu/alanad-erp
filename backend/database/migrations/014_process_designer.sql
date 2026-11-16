-- Phase 6: the visual process designer. The designer edits the approval workflows (the real engine), keeps every saved version,
-- remembers where each step sits on the canvas, and more documents now follow their workflow's approver.

CREATE TABLE IF NOT EXISTS `workflow_versions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `workflow_id` BIGINT UNSIGNED NOT NULL,
  `version` INT NOT NULL,
  `steps_json` MEDIUMTEXT NOT NULL,
  `note` VARCHAR(255) NULL,
  `saved_by` BIGINT UNSIGNED NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wf_version` (`workflow_id`, `version`),
  CONSTRAINT `fk_wfv_workflow` FOREIGN KEY (`workflow_id`) REFERENCES `workflows` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- where each step sits on the canvas
-- @optional
ALTER TABLE `workflow_steps` ADD COLUMN `pos_x` INT NULL, ADD COLUMN `pos_y` INT NULL;

-- the flows the designer controls besides the purchase request / order: one approval step each, assigned to the manager role as today
INSERT IGNORE INTO `workflows` (`company_id`, `wf_key`, `name_en`, `name_ar`, `doc_type`)
SELECT c.id, f.k, f.en, f.ar, f.dt FROM `companies` c JOIN (
  SELECT 'client_order_approval' AS k, 'Client order approval' AS en, 'اعتماد طلب العميل' AS ar, 'client_order' AS dt
  UNION ALL SELECT 'market_purchase_approval', 'Market purchase approval (Finance)', 'اعتماد الشراء من السوق (المالية)', 'market_purchase'
  UNION ALL SELECT 'prep_order_approval', 'Preparation order approval', 'اعتماد أمر التجهيز', 'prep_order'
  UNION ALL SELECT 'waste_decision', 'Waste decision approval (Finance)', 'اعتماد قرار الهالك (المالية)', 'prep_waste'
) f;

INSERT IGNORE INTO `workflow_steps` (`workflow_id`, `position`, `name_en`, `name_ar`, `role_key`, `rule`)
SELECT w.id, 1,
  CASE w.wf_key WHEN 'client_order_approval' THEN 'Manager approval' WHEN 'market_purchase_approval' THEN 'Finance approval' WHEN 'prep_order_approval' THEN 'Plant manager approval' ELSE 'Finance approval' END,
  CASE w.wf_key WHEN 'client_order_approval' THEN 'اعتماد المدير' WHEN 'market_purchase_approval' THEN 'اعتماد المالية' WHEN 'prep_order_approval' THEN 'اعتماد مدير المصنع' ELSE 'اعتماد المالية' END,
  'manager', 'maker_not_checker'
FROM `workflows` w WHERE w.wf_key IN ('client_order_approval', 'market_purchase_approval', 'prep_order_approval', 'waste_decision');
