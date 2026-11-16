-- ============================================================
-- Alanad ERP — Migration 008: Branches (Phase 1 of the owner's plan)
-- A company has one or more branches in different locations. Warehouses (and so cold rooms and stock),
-- purchase orders, deliveries and preparation orders belong to a branch. A user may be limited to some branches.
-- Items, suppliers, clients and the ledger stay company-wide.
-- ============================================================

CREATE TABLE IF NOT EXISTS `company_branches` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` BIGINT UNSIGNED NOT NULL,
  `code` VARCHAR(20) NOT NULL,
  `name_en` VARCHAR(160) NOT NULL,
  `name_ar` VARCHAR(160) NULL,
  `city` VARCHAR(80) NULL,
  `address` VARCHAR(255) NULL,
  `latitude` DECIMAL(10,7) NULL,
  `longitude` DECIMAL(10,7) NULL,
  `phone` VARCHAR(40) NULL,
  `is_main` TINYINT(1) NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_branch_code` (`company_id`, `code`),
  KEY `idx_branch_company` (`company_id`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- users with rows here may only work in these branches; users without rows may use every branch of their company
CREATE TABLE IF NOT EXISTS `user_branches` (
  `user_id` BIGINT UNSIGNED NOT NULL,
  `branch_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`user_id`, `branch_id`),
  KEY `idx_user_branch_branch` (`branch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- every existing company starts with one main branch
INSERT IGNORE INTO `company_branches` (`company_id`, `code`, `name_en`, `name_ar`, `is_main`)
SELECT c.id, 'MAIN', 'Main branch', 'الفرع الرئيسي', 1 FROM `companies` c;

ALTER TABLE `warehouses` ADD COLUMN `branch_id` BIGINT UNSIGNED NULL;
ALTER TABLE `cold_rooms` ADD COLUMN `branch_id` BIGINT UNSIGNED NULL;
ALTER TABLE `purchase_orders` ADD COLUMN `branch_id` BIGINT UNSIGNED NULL;
ALTER TABLE `deliveries` ADD COLUMN `branch_id` BIGINT UNSIGNED NULL;
ALTER TABLE `prep_orders` ADD COLUMN `branch_id` BIGINT UNSIGNED NULL;

UPDATE `warehouses` w JOIN `company_branches` b ON b.company_id = w.company_id AND b.is_main = 1 SET w.branch_id = b.id WHERE w.branch_id IS NULL;
UPDATE `cold_rooms` r JOIN `warehouses` w ON w.id = r.warehouse_id SET r.branch_id = w.branch_id WHERE r.branch_id IS NULL;
UPDATE `purchase_orders` p JOIN `company_branches` b ON b.company_id = p.company_id AND b.is_main = 1 SET p.branch_id = b.id WHERE p.branch_id IS NULL;
UPDATE `deliveries` d JOIN `company_branches` b ON b.company_id = d.company_id AND b.is_main = 1 SET d.branch_id = b.id WHERE d.branch_id IS NULL;
UPDATE `prep_orders` o JOIN `company_branches` b ON b.company_id = o.company_id AND b.is_main = 1 SET o.branch_id = b.id WHERE o.branch_id IS NULL;

ALTER TABLE `warehouses` ADD INDEX `idx_wh_branch` (`company_id`, `branch_id`);
ALTER TABLE `cold_rooms` ADD INDEX `idx_room_branch` (`company_id`, `branch_id`);
ALTER TABLE `purchase_orders` ADD INDEX `idx_po_branch` (`company_id`, `branch_id`);
ALTER TABLE `deliveries` ADD INDEX `idx_delivery_branch` (`company_id`, `branch_id`);
ALTER TABLE `prep_orders` ADD INDEX `idx_prep_branch` (`company_id`, `branch_id`);
