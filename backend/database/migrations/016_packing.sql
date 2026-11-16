-- Phase 8: packing (التعبئة). Sorted goods are packed in boxes (1 kg or 2 kg, decided by the head of preparation),
-- 40 boxes go in a carton, cartons stand on a pallet (طبلية). Every carton and pallet has a code that leads back to the batches.

CREATE TABLE IF NOT EXISTS `pack_specs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` BIGINT UNSIGNED NOT NULL,
  `customer_id` BIGINT UNSIGNED NULL,
  `item_id` BIGINT UNSIGNED NOT NULL,
  `box_kg_options` VARCHAR(60) NOT NULL DEFAULT '1,2',
  `default_box_kg` DECIMAL(8,3) NOT NULL DEFAULT 1,
  `boxes_per_carton` INT NOT NULL DEFAULT 40,
  `cartons_per_pallet` INT NULL,
  `label_note` VARCHAR(255) NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pack_spec_lookup` (`company_id`, `item_id`, `customer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `pack_orders` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` BIGINT UNSIGNED NOT NULL,
  `branch_id` BIGINT UNSIGNED NULL,
  `number` VARCHAR(50) NOT NULL,
  `client_order_id` BIGINT UNSIGNED NULL,
  `customer_id` BIGINT UNSIGNED NULL,
  `customer_name` VARCHAR(255) NULL,
  `item_id` BIGINT UNSIGNED NOT NULL,
  `grade` VARCHAR(2) NOT NULL DEFAULT 'A',
  `required_kg` DECIMAL(14,3) NOT NULL,
  `due_date` DATE NULL,
  `spec_id` BIGINT UNSIGNED NULL,
  `box_kg` DECIMAL(8,3) NULL,
  `boxes_per_carton` INT NULL,
  `cartons_per_pallet` INT NULL,
  `status` ENUM('Draft','Ready','In_Progress','Packed','Cancelled') NOT NULL DEFAULT 'Draft',
  `decided_by` BIGINT UNSIGNED NULL,
  `decided_at` DATETIME NULL,
  `planned_boxes` INT NULL,
  `issued_kg` DECIMAL(14,3) NULL,
  `cost_total` DECIMAL(15,2) NULL,
  `started_by` BIGINT UNSIGNED NULL,
  `started_at` DATETIME NULL,
  `boxes_packed` INT NULL,
  `loss_kg` DECIMAL(14,3) NULL,
  `cartons` INT NULL,
  `pallets` INT NULL,
  `packed_by` BIGINT UNSIGNED NULL,
  `packed_at` DATETIME NULL,
  `cancel_reason` VARCHAR(500) NULL,
  `notes` VARCHAR(1000) NULL,
  `created_by` BIGINT UNSIGNED NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pack_order_number` (`company_id`, `number`),
  KEY `idx_pack_order_status` (`company_id`, `status`),
  KEY `idx_pack_order_branch` (`branch_id`),
  KEY `idx_pack_order_client` (`client_order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `pack_order_batches` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id` BIGINT UNSIGNED NOT NULL,
  `batch_id` BIGINT UNSIGNED NOT NULL,
  `kg` DECIMAL(14,3) NOT NULL,
  `unit_cost` DECIMAL(12,4) NULL,
  PRIMARY KEY (`id`),
  KEY `idx_pob_order` (`order_id`),
  CONSTRAINT `fk_pob_order` FOREIGN KEY (`order_id`) REFERENCES `pack_orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `pack_units` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` BIGINT UNSIGNED NOT NULL,
  `order_id` BIGINT UNSIGNED NOT NULL,
  `kind` ENUM('pallet','carton') NOT NULL,
  `code` VARCHAR(80) NOT NULL,
  `parent_id` BIGINT UNSIGNED NULL,
  `boxes` INT NOT NULL DEFAULT 0,
  `kg` DECIMAL(14,3) NOT NULL DEFAULT 0,
  `is_partial` TINYINT(1) NOT NULL DEFAULT 0,
  `status` ENUM('Packed','Shipped') NOT NULL DEFAULT 'Packed',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pack_unit_code` (`company_id`, `code`),
  KEY `idx_pack_unit_order` (`order_id`),
  CONSTRAINT `fk_pu_order` FOREIGN KEY (`order_id`) REFERENCES `pack_orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `settings` (`company_id`, `setting_key`, `setting_value`, `value_type`, `group_name`, `label_en`, `label_ar`)
SELECT c.id, s.k, s.v, s.t, 'packing', s.en, s.ar FROM `companies` c JOIN (
  SELECT 'packing.box_sizes_kg' AS k, '1,2' AS v, 'string' AS t, 'Box sizes the head of preparation may choose (kg, comma separated)' AS en, 'أحجام الصندوق التي يختار منها رئيس التجهيز (كجم، مفصولة بفاصلة)' AS ar
  UNION ALL SELECT 'packing.boxes_per_carton', '40', 'int', 'Boxes in one carton when the client has no pack specification', 'عدد الصناديق في الكرتونة عندما لا توجد مواصفات تعبئة للعميل'
  UNION ALL SELECT 'packing.cartons_per_pallet', '40', 'int', 'Cartons on one pallet when the client has no pack specification', 'عدد الكراتين على الطبلية عندما لا توجد مواصفات تعبئة للعميل'
  UNION ALL SELECT 'packing.balance_tolerance_pct', '2', 'decimal', 'Allowed gap between the kg issued and boxes x box size + loss (%)', 'الفرق المسموح بين الكمية المصروفة وعدد الصناديق × حجمها + الفاقد (%)'
  UNION ALL SELECT 'packing.default_grade', 'A', 'string', 'Grade packed for clients (A = class 1 from sorting)', 'الدرجة التي تُعبأ للعملاء (A = الدرجة ١ من الفرز)'
) s;

INSERT INTO `roles` (`company_id`, `role_key`, `name_en`, `name_ar`, `is_system`)
SELECT NULL, 'head_of_preparation', 'Head of Preparation', 'رئيس التجهيز', 1 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `roles` WHERE `role_key` = 'head_of_preparation' AND `company_id` IS NULL);
INSERT INTO `roles` (`company_id`, `role_key`, `name_en`, `name_ar`, `is_system`)
SELECT NULL, 'packer', 'Packer', 'مسؤول التعبئة', 1 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `roles` WHERE `role_key` = 'packer' AND `company_id` IS NULL);
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission`)
SELECT r.id, p.perm FROM `roles` r JOIN (
  SELECT 'head_of_preparation' AS k, '*.view' AS perm UNION ALL SELECT 'head_of_preparation', 'pack-orders.create' UNION ALL SELECT 'head_of_preparation', 'pack-orders.decide' UNION ALL SELECT 'head_of_preparation', 'pack-specs.create' UNION ALL SELECT 'head_of_preparation', 'pack-specs.edit'
  UNION ALL SELECT 'packer', '*.view' UNION ALL SELECT 'packer', 'pack-orders.create' UNION ALL SELECT 'packer', 'pack-orders.edit'
) p ON p.k = r.role_key WHERE r.company_id IS NULL;
