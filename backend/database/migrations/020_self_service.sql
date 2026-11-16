-- ─── Phase 14: two layers of tabs ───────────────────────────────────────────────
-- Layer 1 (fixed, every signed-in person): my leave, my IT tickets, my profile. They need NO permission and only ever
--   show the person's OWN data (handlers/my.php, it-tickets handler).
-- Layer 2 (by role): only the person's own work. Before this migration every work role carried the wildcard `*.view`
--   (and the plain user `*.create` / `*.edit` too), so each account could open every module.
--   resource.view   = the role has its own TAB for that list.
--   resource.lookup = the role's screens read that list in the background (an item picker, a cold-room name...) but the
--                     role gets NO tab for it. The server accepts `lookup` wherever it accepts `view` on a GET.
-- Safe to run again.

DELETE rp FROM `role_permissions` rp JOIN `roles` r ON r.`id` = rp.`role_id`
 WHERE r.`company_id` IS NULL
   AND r.`role_key` IN ('user','quality_inspector','storekeeper','gate_officer','counter','receiving_finance','sorter',
                        'head_of_preparation','packer','dispatcher','sales_accountant','hr_officer','it_staff','it_manager')
   AND (rp.`permission` IN ('*.view','*.create','*.edit') OR rp.`permission` LIKE '%.view' OR rp.`permission` LIKE '%.lookup');

INSERT IGNORE INTO `role_permissions` (`role_id`, `permission`)
SELECT r.`id`, CONCAT(p.res, '.', p.kind) FROM `roles` r JOIN (
  SELECT 'quality_inspector' AS k, 'market-purchases' AS res, 'view' AS kind UNION ALL SELECT 'quality_inspector','sorting-runs','view' UNION ALL SELECT 'quality_inspector','deliveries','view'
  UNION ALL SELECT 'quality_inspector','items','lookup' UNION ALL SELECT 'quality_inspector','suppliers','lookup' UNION ALL SELECT 'quality_inspector','cold-rooms','lookup'
  UNION ALL SELECT 'quality_inspector','reject-reasons','lookup' UNION ALL SELECT 'quality_inspector','stock','lookup' UNION ALL SELECT 'quality_inspector','quality-holds','lookup' UNION ALL SELECT 'quality_inspector','purchase-orders','lookup'
  UNION ALL SELECT 'storekeeper','entry-permits','view' UNION ALL SELECT 'storekeeper','deliveries','view'
  UNION ALL SELECT 'storekeeper','vehicles','lookup' UNION ALL SELECT 'storekeeper','gates','lookup' UNION ALL SELECT 'storekeeper','purchase-orders','lookup' UNION ALL SELECT 'storekeeper','cold-rooms','lookup'
  UNION ALL SELECT 'storekeeper','reject-reasons','lookup' UNION ALL SELECT 'storekeeper','stock','lookup' UNION ALL SELECT 'storekeeper','items','lookup' UNION ALL SELECT 'storekeeper','warehouses','lookup'
  UNION ALL SELECT 'gate_officer','entry-permits','view' UNION ALL SELECT 'gate_officer','vehicles','view' UNION ALL SELECT 'gate_officer','shipments','view'
  UNION ALL SELECT 'gate_officer','gates','lookup' UNION ALL SELECT 'gate_officer','purchase-orders','lookup' UNION ALL SELECT 'gate_officer','client-orders','lookup' UNION ALL SELECT 'gate_officer','deliveries','lookup' UNION ALL SELECT 'gate_officer','reject-reasons','lookup'
  UNION ALL SELECT 'counter','deliveries','view' UNION ALL SELECT 'counter','purchase-orders','lookup' UNION ALL SELECT 'counter','cold-rooms','lookup' UNION ALL SELECT 'counter','reject-reasons','lookup'
  UNION ALL SELECT 'receiving_finance','deliveries','view' UNION ALL SELECT 'receiving_finance','purchase-orders','lookup' UNION ALL SELECT 'receiving_finance','cold-rooms','lookup' UNION ALL SELECT 'receiving_finance','reject-reasons','lookup'
  UNION ALL SELECT 'sorter','sorting-runs','view' UNION ALL SELECT 'sorter','deliveries','lookup' UNION ALL SELECT 'sorter','cold-rooms','lookup' UNION ALL SELECT 'sorter','reject-reasons','lookup' UNION ALL SELECT 'sorter','items','lookup' UNION ALL SELECT 'sorter','stock','lookup'
  UNION ALL SELECT 'head_of_preparation','pack-orders','view' UNION ALL SELECT 'head_of_preparation','prep-specs','view'
  UNION ALL SELECT 'head_of_preparation','pack-specs','lookup' UNION ALL SELECT 'head_of_preparation','prep-orders','lookup' UNION ALL SELECT 'head_of_preparation','production-reports','lookup' UNION ALL SELECT 'head_of_preparation','client-orders','lookup'
  UNION ALL SELECT 'head_of_preparation','customers','lookup' UNION ALL SELECT 'head_of_preparation','items','lookup' UNION ALL SELECT 'head_of_preparation','stock','lookup' UNION ALL SELECT 'head_of_preparation','cold-rooms','lookup'
  UNION ALL SELECT 'packer','pack-orders','view'
  UNION ALL SELECT 'packer','pack-specs','lookup' UNION ALL SELECT 'packer','prep-specs','lookup' UNION ALL SELECT 'packer','prep-orders','lookup' UNION ALL SELECT 'packer','production-reports','lookup' UNION ALL SELECT 'packer','client-orders','lookup'
  UNION ALL SELECT 'packer','customers','lookup' UNION ALL SELECT 'packer','items','lookup' UNION ALL SELECT 'packer','stock','lookup'
  UNION ALL SELECT 'dispatcher','shipments','view'
  UNION ALL SELECT 'dispatcher','client-orders','lookup' UNION ALL SELECT 'dispatcher','customers','lookup' UNION ALL SELECT 'dispatcher','items','lookup' UNION ALL SELECT 'dispatcher','pack-orders','lookup' UNION ALL SELECT 'dispatcher','reject-reasons','lookup' UNION ALL SELECT 'dispatcher','recalls','lookup'
  UNION ALL SELECT 'sales_accountant','sales-invoices','view' UNION ALL SELECT 'sales_accountant','sales-prices','view' UNION ALL SELECT 'sales_accountant','shipments','view'
  UNION ALL SELECT 'sales_accountant','customers','lookup' UNION ALL SELECT 'sales_accountant','items','lookup' UNION ALL SELECT 'sales_accountant','client-orders','lookup' UNION ALL SELECT 'sales_accountant','recalls','lookup'
  UNION ALL SELECT 'hr_officer','employees','view' UNION ALL SELECT 'hr_officer','hr-expiry','view' UNION ALL SELECT 'hr_officer','hr-leaves','view'
  UNION ALL SELECT 'hr_officer','reject-reasons','lookup' UNION ALL SELECT 'hr_officer','leave-types','lookup' UNION ALL SELECT 'hr_officer','departments','lookup' UNION ALL SELECT 'hr_officer','designations','lookup'
  UNION ALL SELECT 'it_staff','it-tickets','view' UNION ALL SELECT 'it_staff','it-assets','view'
  UNION ALL SELECT 'it_manager','it-tickets','view' UNION ALL SELECT 'it_manager','it-assets','view'
) p ON p.k = r.`role_key` WHERE r.`company_id` IS NULL;

-- the plain employee: his role now means "self-service only"
UPDATE `roles` SET `name_en` = 'Employee (self-service only)', `name_ar` = 'موظف (خدماته فقط)' WHERE `role_key` = 'user' AND `company_id` IS NULL;
