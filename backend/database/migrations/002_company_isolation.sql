-- ============================================================
-- Alanad ERP — Migration 002: company isolation for the business tables
-- Every operational table gets company_id (existing rows -> company 1)
-- and unique codes / numbers become unique PER COMPANY.
-- ============================================================

ALTER TABLE `addresses` ADD COLUMN `company_id` BIGINT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE `addresses` ADD INDEX `idx_addresses_company` (`company_id`);

ALTER TABLE `appraisals` ADD COLUMN `company_id` BIGINT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE `appraisals` ADD INDEX `idx_appraisals_company` (`company_id`);
ALTER TABLE `appraisals` DROP INDEX `appraisals_number_unique`;
ALTER TABLE `appraisals` ADD UNIQUE KEY `appraisals_number_unique` (`company_id`, `number`);

ALTER TABLE `asset_movements` ADD COLUMN `company_id` BIGINT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE `asset_movements` ADD INDEX `idx_asset_movements_company` (`company_id`);

ALTER TABLE `assets` ADD COLUMN `company_id` BIGINT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE `assets` ADD INDEX `idx_assets_company` (`company_id`);

ALTER TABLE `blog_posts` ADD COLUMN `company_id` BIGINT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE `blog_posts` ADD INDEX `idx_blog_posts_company` (`company_id`);
ALTER TABLE `blog_posts` DROP INDEX `blog_posts_slug_unique`;
ALTER TABLE `blog_posts` ADD UNIQUE KEY `blog_posts_slug_unique` (`company_id`, `slug`);

ALTER TABLE `bom` ADD COLUMN `company_id` BIGINT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE `bom` ADD INDEX `idx_bom_company` (`company_id`);
ALTER TABLE `bom` DROP INDEX `bom_number_unique`;
ALTER TABLE `bom` ADD UNIQUE KEY `bom_number_unique` (`company_id`, `number`);

ALTER TABLE `branches` ADD COLUMN `company_id` BIGINT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE `branches` ADD INDEX `idx_branches_company` (`company_id`);
ALTER TABLE `branches` DROP INDEX `branches_code_unique`;
ALTER TABLE `branches` ADD UNIQUE KEY `branches_code_unique` (`company_id`, `code`);

ALTER TABLE `brands` ADD COLUMN `company_id` BIGINT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE `brands` ADD INDEX `idx_brands_company` (`company_id`);

ALTER TABLE `crm_contacts` ADD COLUMN `company_id` BIGINT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE `crm_contacts` ADD INDEX `idx_crm_contacts_company` (`company_id`);

ALTER TABLE `crm_deals` ADD COLUMN `company_id` BIGINT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE `crm_deals` ADD INDEX `idx_crm_deals_company` (`company_id`);
ALTER TABLE `crm_deals` DROP INDEX `crm_deals_number_unique`;
ALTER TABLE `crm_deals` ADD UNIQUE KEY `crm_deals_number_unique` (`company_id`, `number`);

ALTER TABLE `crm_leads` ADD COLUMN `company_id` BIGINT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE `crm_leads` ADD INDEX `idx_crm_leads_company` (`company_id`);
ALTER TABLE `crm_leads` DROP INDEX `crm_leads_number_unique`;
ALTER TABLE `crm_leads` ADD UNIQUE KEY `crm_leads_number_unique` (`company_id`, `number`);

ALTER TABLE `crm_organizations` ADD COLUMN `company_id` BIGINT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE `crm_organizations` ADD INDEX `idx_crm_organizations_company` (`company_id`);

ALTER TABLE `customer_groups` ADD COLUMN `company_id` BIGINT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE `customer_groups` ADD INDEX `idx_customer_groups_company` (`company_id`);

ALTER TABLE `customers` ADD COLUMN `company_id` BIGINT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE `customers` ADD INDEX `idx_customers_company` (`company_id`);

ALTER TABLE `departments` ADD COLUMN `company_id` BIGINT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE `departments` ADD INDEX `idx_departments_company` (`company_id`);

ALTER TABLE `designations` ADD COLUMN `company_id` BIGINT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE `designations` ADD INDEX `idx_designations_company` (`company_id`);

ALTER TABLE `employees` ADD COLUMN `company_id` BIGINT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE `employees` ADD INDEX `idx_employees_company` (`company_id`);
ALTER TABLE `employees` DROP INDEX `employees_employee_id_unique`;
ALTER TABLE `employees` ADD UNIQUE KEY `employees_employee_id_unique` (`company_id`, `employee_id`);

ALTER TABLE `employment_types` ADD COLUMN `company_id` BIGINT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE `employment_types` ADD INDEX `idx_employment_types_company` (`company_id`);

ALTER TABLE `fin_accounts` ADD COLUMN `company_id` BIGINT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE `fin_accounts` ADD INDEX `idx_fin_accounts_company` (`company_id`);
ALTER TABLE `fin_accounts` DROP INDEX `fin_accounts_code_unique`;
ALTER TABLE `fin_accounts` ADD UNIQUE KEY `fin_accounts_code_unique` (`company_id`, `code`);

ALTER TABLE `fin_bank_accounts` ADD COLUMN `company_id` BIGINT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE `fin_bank_accounts` ADD INDEX `idx_fin_bank_accounts_company` (`company_id`);

ALTER TABLE `fin_cost_centers` ADD COLUMN `company_id` BIGINT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE `fin_cost_centers` ADD INDEX `idx_fin_cost_centers_company` (`company_id`);
ALTER TABLE `fin_cost_centers` DROP INDEX `fin_cost_centers_code_unique`;
ALTER TABLE `fin_cost_centers` ADD UNIQUE KEY `fin_cost_centers_code_unique` (`company_id`, `code`);

ALTER TABLE `fin_currencies` ADD COLUMN `company_id` BIGINT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE `fin_currencies` ADD INDEX `idx_fin_currencies_company` (`company_id`);
ALTER TABLE `fin_currencies` DROP INDEX `fin_currencies_code_unique`;
ALTER TABLE `fin_currencies` ADD UNIQUE KEY `fin_currencies_code_unique` (`company_id`, `code`);

ALTER TABLE `fin_journal_entries` ADD COLUMN `company_id` BIGINT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE `fin_journal_entries` ADD INDEX `idx_fin_journal_entries_company` (`company_id`);
ALTER TABLE `fin_journal_entries` DROP INDEX `fin_journal_entries_number_unique`;
ALTER TABLE `fin_journal_entries` ADD UNIQUE KEY `fin_journal_entries_number_unique` (`company_id`, `number`);

ALTER TABLE `fiscal_years` ADD COLUMN `company_id` BIGINT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE `fiscal_years` ADD INDEX `idx_fiscal_years_company` (`company_id`);

ALTER TABLE `holiday_lists` ADD COLUMN `company_id` BIGINT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE `holiday_lists` ADD INDEX `idx_holiday_lists_company` (`company_id`);

ALTER TABLE `invoices` ADD COLUMN `company_id` BIGINT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE `invoices` ADD INDEX `idx_invoices_company` (`company_id`);
ALTER TABLE `invoices` DROP INDEX `invoices_number_unique`;
ALTER TABLE `invoices` ADD UNIQUE KEY `invoices_number_unique` (`company_id`, `number`);

ALTER TABLE `item_groups` ADD COLUMN `company_id` BIGINT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE `item_groups` ADD INDEX `idx_item_groups_company` (`company_id`);

ALTER TABLE `items` ADD COLUMN `company_id` BIGINT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE `items` ADD INDEX `idx_items_company` (`company_id`);

ALTER TABLE `job_applicants` ADD COLUMN `company_id` BIGINT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE `job_applicants` ADD INDEX `idx_job_applicants_company` (`company_id`);

ALTER TABLE `job_cards` ADD COLUMN `company_id` BIGINT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE `job_cards` ADD INDEX `idx_job_cards_company` (`company_id`);
ALTER TABLE `job_cards` DROP INDEX `job_cards_number_unique`;
ALTER TABLE `job_cards` ADD UNIQUE KEY `job_cards_number_unique` (`company_id`, `number`);

ALTER TABLE `leave_requests` ADD COLUMN `company_id` BIGINT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE `leave_requests` ADD INDEX `idx_leave_requests_company` (`company_id`);
ALTER TABLE `leave_requests` DROP INDEX `leave_requests_number_unique`;
ALTER TABLE `leave_requests` ADD UNIQUE KEY `leave_requests_number_unique` (`company_id`, `number`);

ALTER TABLE `leave_types` ADD COLUMN `company_id` BIGINT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE `leave_types` ADD INDEX `idx_leave_types_company` (`company_id`);

ALTER TABLE `payment_orders` ADD COLUMN `company_id` BIGINT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE `payment_orders` ADD INDEX `idx_payment_orders_company` (`company_id`);
ALTER TABLE `payment_orders` DROP INDEX `payment_orders_number_unique`;
ALTER TABLE `payment_orders` ADD UNIQUE KEY `payment_orders_number_unique` (`company_id`, `number`);

ALTER TABLE `payment_terms` ADD COLUMN `company_id` BIGINT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE `payment_terms` ADD INDEX `idx_payment_terms_company` (`company_id`);

ALTER TABLE `payments` ADD COLUMN `company_id` BIGINT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE `payments` ADD INDEX `idx_payments_company` (`company_id`);
ALTER TABLE `payments` DROP INDEX `payments_number_unique`;
ALTER TABLE `payments` ADD UNIQUE KEY `payments_number_unique` (`company_id`, `number`);

ALTER TABLE `pos_invoices` ADD COLUMN `company_id` BIGINT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE `pos_invoices` ADD INDEX `idx_pos_invoices_company` (`company_id`);
ALTER TABLE `pos_invoices` DROP INDEX `pos_invoices_number_unique`;
ALTER TABLE `pos_invoices` ADD UNIQUE KEY `pos_invoices_number_unique` (`company_id`, `number`);

ALTER TABLE `pos_profiles` ADD COLUMN `company_id` BIGINT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE `pos_profiles` ADD INDEX `idx_pos_profiles_company` (`company_id`);

ALTER TABLE `pos_sessions` ADD COLUMN `company_id` BIGINT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE `pos_sessions` ADD INDEX `idx_pos_sessions_company` (`company_id`);

ALTER TABLE `price_lists` ADD COLUMN `company_id` BIGINT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE `price_lists` ADD INDEX `idx_price_lists_company` (`company_id`);

ALTER TABLE `projects` ADD COLUMN `company_id` BIGINT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE `projects` ADD INDEX `idx_projects_company` (`company_id`);

ALTER TABLE `promotions` ADD COLUMN `company_id` BIGINT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE `promotions` ADD INDEX `idx_promotions_company` (`company_id`);

ALTER TABLE `purchase_orders` ADD COLUMN `company_id` BIGINT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE `purchase_orders` ADD INDEX `idx_purchase_orders_company` (`company_id`);
ALTER TABLE `purchase_orders` DROP INDEX `purchase_orders_number_unique`;
ALTER TABLE `purchase_orders` ADD UNIQUE KEY `purchase_orders_number_unique` (`company_id`, `number`);

ALTER TABLE `quality_procedures` ADD COLUMN `company_id` BIGINT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE `quality_procedures` ADD INDEX `idx_quality_procedures_company` (`company_id`);

ALTER TABLE `quotations` ADD COLUMN `company_id` BIGINT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE `quotations` ADD INDEX `idx_quotations_company` (`company_id`);
ALTER TABLE `quotations` DROP INDEX `quotations_number_unique`;
ALTER TABLE `quotations` ADD UNIQUE KEY `quotations_number_unique` (`company_id`, `number`);

ALTER TABLE `salary_components` ADD COLUMN `company_id` BIGINT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE `salary_components` ADD INDEX `idx_salary_components_company` (`company_id`);

ALTER TABLE `salary_slips` ADD COLUMN `company_id` BIGINT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE `salary_slips` ADD INDEX `idx_salary_slips_company` (`company_id`);
ALTER TABLE `salary_slips` DROP INDEX `salary_slips_number_unique`;
ALTER TABLE `salary_slips` ADD UNIQUE KEY `salary_slips_number_unique` (`company_id`, `number`);

ALTER TABLE `salary_structures` ADD COLUMN `company_id` BIGINT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE `salary_structures` ADD INDEX `idx_salary_structures_company` (`company_id`);

ALTER TABLE `sales_orders` ADD COLUMN `company_id` BIGINT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE `sales_orders` ADD INDEX `idx_sales_orders_company` (`company_id`);
ALTER TABLE `sales_orders` DROP INDEX `sales_orders_number_unique`;
ALTER TABLE `sales_orders` ADD UNIQUE KEY `sales_orders_number_unique` (`company_id`, `number`);

ALTER TABLE `sales_persons` ADD COLUMN `company_id` BIGINT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE `sales_persons` ADD INDEX `idx_sales_persons_company` (`company_id`);

ALTER TABLE `separations` ADD COLUMN `company_id` BIGINT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE `separations` ADD INDEX `idx_separations_company` (`company_id`);

ALTER TABLE `shift_types` ADD COLUMN `company_id` BIGINT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE `shift_types` ADD INDEX `idx_shift_types_company` (`company_id`);

ALTER TABLE `supplier_groups` ADD COLUMN `company_id` BIGINT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE `supplier_groups` ADD INDEX `idx_supplier_groups_company` (`company_id`);

ALTER TABLE `suppliers` ADD COLUMN `company_id` BIGINT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE `suppliers` ADD INDEX `idx_suppliers_company` (`company_id`);

ALTER TABLE `tasks` ADD COLUMN `company_id` BIGINT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE `tasks` ADD INDEX `idx_tasks_company` (`company_id`);

ALTER TABLE `tax_templates` ADD COLUMN `company_id` BIGINT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE `tax_templates` ADD INDEX `idx_tax_templates_company` (`company_id`);

ALTER TABLE `terms_conditions` ADD COLUMN `company_id` BIGINT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE `terms_conditions` ADD INDEX `idx_terms_conditions_company` (`company_id`);

ALTER TABLE `territories` ADD COLUMN `company_id` BIGINT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE `territories` ADD INDEX `idx_territories_company` (`company_id`);

ALTER TABLE `timesheets` ADD COLUMN `company_id` BIGINT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE `timesheets` ADD INDEX `idx_timesheets_company` (`company_id`);

ALTER TABLE `transfers` ADD COLUMN `company_id` BIGINT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE `transfers` ADD INDEX `idx_transfers_company` (`company_id`);

ALTER TABLE `uom` ADD COLUMN `company_id` BIGINT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE `uom` ADD INDEX `idx_uom_company` (`company_id`);

ALTER TABLE `website_pages` ADD COLUMN `company_id` BIGINT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE `website_pages` ADD INDEX `idx_website_pages_company` (`company_id`);
ALTER TABLE `website_pages` DROP INDEX `website_pages_slug_unique`;
ALTER TABLE `website_pages` ADD UNIQUE KEY `website_pages_slug_unique` (`company_id`, `slug`);

ALTER TABLE `work_orders` ADD COLUMN `company_id` BIGINT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE `work_orders` ADD INDEX `idx_work_orders_company` (`company_id`);
ALTER TABLE `work_orders` DROP INDEX `work_orders_number_unique`;
ALTER TABLE `work_orders` ADD UNIQUE KEY `work_orders_number_unique` (`company_id`, `number`);
