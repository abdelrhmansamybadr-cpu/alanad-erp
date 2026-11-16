-- Phase 10: HR core inside the ERP, shaped by the finished HR project (ShiftPulse) and Saudi labor-law practice:
-- the employee master with the documents a food factory must watch (iqama, passport, health card, contract end),
-- Saudi leave types with balances, and leave requests that follow the designer-controlled approval flow.
-- Attendance, payroll, recruitment and the employee mobile app stay in the HR product (linked from the HR screen).

-- @optional
ALTER TABLE `employees`
  ADD COLUMN `national_id` VARCHAR(30) NULL, ADD COLUMN `nationality` VARCHAR(60) NULL, ADD COLUMN `gender` ENUM('M','F') NULL, ADD COLUMN `date_of_birth` DATE NULL,
  ADD COLUMN `iqama_expiry` DATE NULL, ADD COLUMN `passport_no` VARCHAR(30) NULL, ADD COLUMN `passport_expiry` DATE NULL,
  ADD COLUMN `health_card_no` VARCHAR(40) NULL, ADD COLUMN `health_card_expiry` DATE NULL, ADD COLUMN `contract_end` DATE NULL,
  ADD COLUMN `gosi_no` VARCHAR(30) NULL, ADD COLUMN `iban` VARCHAR(40) NULL, ADD COLUMN `emergency_contact` VARCHAR(255) NULL,
  ADD COLUMN `branch_id` BIGINT UNSIGNED NULL, ADD COLUMN `manager_id` BIGINT UNSIGNED NULL, ADD COLUMN `user_id` BIGINT UNSIGNED NULL;

-- @optional
ALTER TABLE `leave_types` ADD COLUMN `kind` VARCHAR(20) NULL;

-- @optional
ALTER TABLE `leave_requests`
  ADD COLUMN `leave_type_id` BIGINT UNSIGNED NULL, ADD COLUMN `workflow_instance_id` BIGINT UNSIGNED NULL, ADD COLUMN `decided_by` BIGINT UNSIGNED NULL,
  ADD COLUMN `decided_at` DATETIME NULL, ADD COLUMN `reject_reason` VARCHAR(255) NULL, ADD COLUMN `requested_by` BIGINT UNSIGNED NULL;

-- @optional
ALTER TABLE `leave_requests` MODIFY `status` ENUM('Draft','Pending','Approved','Rejected','Cancelled') NOT NULL DEFAULT 'Draft';

-- Saudi leave types (Labor Law articles 109-117 in brief); max_days_allowed is the yearly entitlement, the annual leave grows with service (settings)
INSERT INTO `leave_types` (`company_id`, `name`, `max_days_allowed`, `is_paid`, `description`, `kind`)
SELECT c.id, t.n, t.d, t.p, t.ds, t.k FROM `companies` c JOIN (
  SELECT 'Annual leave' AS n, 21 AS d, 1 AS p, 'إجازة سنوية: ٢١ يوماً، و٣٠ بعد ٥ سنوات خدمة' AS ds, 'annual' AS k
  UNION ALL SELECT 'Sick leave', 120, 1, 'إجازة مرضية: ٣٠ يوماً بأجر كامل، ثم ٦٠ بـ٧٥٪، ثم ٣٠ بلا أجر', 'sick'
  UNION ALL SELECT 'Marriage leave', 5, 1, 'إجازة زواج', 'other'
  UNION ALL SELECT 'Bereavement leave', 5, 1, 'إجازة وفاة', 'other'
  UNION ALL SELECT 'Maternity leave', 70, 1, 'إجازة وضع: ١٠ أسابيع', 'other'
  UNION ALL SELECT 'Paternity leave', 3, 1, 'إجازة مولود', 'other'
  UNION ALL SELECT 'Hajj leave', 10, 1, 'إجازة حج: مرة واحدة', 'other'
  UNION ALL SELECT 'Unpaid leave', 365, 0, 'إجازة بدون أجر', 'unpaid'
) t WHERE NOT EXISTS (SELECT 1 FROM `leave_types` x WHERE x.`company_id` = c.id AND x.`name` = t.n);

INSERT IGNORE INTO `settings` (`company_id`, `setting_key`, `setting_value`, `value_type`, `group_name`, `label_en`, `label_ar`)
SELECT c.id, s.k, s.v, s.t, 'hr', s.en, s.ar FROM `companies` c JOIN (
  SELECT 'hr.expiry_warning_days' AS k, '60' AS v, 'int' AS t, 'Warn this many days before an iqama, passport, health card or contract expires' AS en, 'التنبيه قبل انتهاء الإقامة أو الجواز أو الشهادة الصحية أو العقد بعدد الأيام' AS ar
  UNION ALL SELECT 'hr.weekend_days', '5,6', 'string', 'Weekend days not counted in leave (0 = Sunday ... 5 = Friday, 6 = Saturday)', 'أيام نهاية الأسبوع التي لا تُحسب من الإجازة (٥ = الجمعة، ٦ = السبت)'
  UNION ALL SELECT 'hr.annual_after_years', '5', 'int', 'Years of service after which the annual leave grows', 'سنوات الخدمة التي تزيد بعدها الإجازة السنوية'
  UNION ALL SELECT 'hr.annual_days_after_years', '30', 'int', 'Annual leave days after those years of service', 'أيام الإجازة السنوية بعد تلك السنوات'
  UNION ALL SELECT 'hr.portal_url', 'https://alanadhr.quadrocloud.net', 'string', 'Address of the HR portal (attendance, payroll, recruitment, mobile app)', 'عنوان بوابة الموارد البشرية (الحضور والرواتب والتوظيف وتطبيق الجوال)'
) s;

-- the HR step of the leave flow belongs to the HR officer (the team head stays the manager)
UPDATE `workflow_steps` s JOIN `workflows` w ON w.id = s.workflow_id SET s.role_key = 'hr_officer' WHERE w.wf_key = 'leave_request' AND s.position = 2 AND s.role_key = 'manager';

INSERT INTO `roles` (`company_id`, `role_key`, `name_en`, `name_ar`, `is_system`)
SELECT NULL, 'hr_officer', 'HR Officer', 'مسؤول الموارد البشرية', 1 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `roles` WHERE `role_key` = 'hr_officer' AND `company_id` IS NULL);
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission`)
SELECT r.id, p.perm FROM `roles` r JOIN (
  SELECT '*.view' AS perm UNION ALL SELECT 'employees.create' UNION ALL SELECT 'employees.edit' UNION ALL SELECT 'hr-leaves.create' UNION ALL SELECT 'hr-leaves.manage' UNION ALL SELECT 'hr-leaves.edit'
) p WHERE r.role_key = 'hr_officer' AND r.company_id IS NULL;
