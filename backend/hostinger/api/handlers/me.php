<?php
// Who am I: user, role, permissions, current company, switchable companies, enabled modules.
$u = $GLOBALS['auth_user'];
$mods = db()->prepare('SELECT module_key, is_enabled FROM module_switches WHERE company_id = ?');
$mods->execute([$u['company_id']]);
$modules = [];
foreach ($mods->fetchAll() as $m) $modules[$m['module_key']] = (bool)$m['is_enabled'];
$in = implode(',', array_map('intval', $u['companies']));
$cos = db()->query("SELECT id, name_en, name_ar FROM companies WHERE id IN ($in) AND is_active = 1")->fetchAll();
$bin = $u['branch_access'] ? implode(',', array_map('intval', $u['branch_access'])) : '0';
$branches = db()->query("SELECT id, code, name_en, name_ar, city, is_main FROM company_branches WHERE id IN ($bin) ORDER BY is_main DESC, id")->fetchAll();
$emp = db()->prepare("SELECT id FROM employees WHERE company_id = ? AND user_id = ? AND status = 'Active' LIMIT 1"); $emp->execute([$u['company_id'], $u['user_id']]);
$hasEmployee = (bool)$emp->fetchColumn();
json_response([
    'has_employee' => $hasEmployee,
    'branches' => $branches, 'branch_id' => is_array($u['branch_scope']) && count($u['branch_scope']) === 1 ? $u['branch_scope'][0] : null,
    'id' => $u['user_id'], 'name' => $u['name'], 'email' => $u['email'], 'role' => $u['role'], 'lang' => $u['lang'],
    'must_change_password' => (bool)$u['must_change_password'],
    'permissions' => $u['permissions'], 'company_id' => $u['company_id'], 'companies' => $cos, 'modules' => $modules,
]);
