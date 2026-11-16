<?php
// Companies — administrators only (pipeline). New companies get their own settings, module switches and reject reasons.
$db = db(); $id = $GLOBALS['id']; $method = $GLOBALS['method']; $body = $GLOBALS['body'];
$fields = ['group_name', 'name_en', 'name_ar', 'legal_name', 'cr_number', 'vat_number', 'address', 'currency', 'is_active'];
switch ($method) {
case 'GET':
    if ($id) { $s = $db->prepare('SELECT * FROM companies WHERE id = ?'); $s->execute([$id]); $r = $s->fetch(); if (!$r) error_response('Not found', 404); json_response($r); }
    json_response($db->query('SELECT * FROM companies ORDER BY id')->fetchAll());
case 'POST':
    if (empty($body['name_en'])) error_response('name_en is required', 422);
    $cols = []; $p = [];
    foreach ($fields as $f) if (array_key_exists($f, $body)) { $cols[] = "`$f`"; $p[] = $body[$f]; }
    $db->prepare('INSERT INTO companies (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($p), '?')) . ')')->execute($p);
    $new = (int)$db->lastInsertId();
    // clone defaults from company 1 (settings, modules, reject reasons)
    $db->prepare('INSERT INTO settings (company_id, setting_key, setting_value, value_type, group_name, label_en, label_ar) SELECT ?, setting_key, setting_value, value_type, group_name, label_en, label_ar FROM settings WHERE company_id = 1')->execute([$new]);
    $db->prepare('INSERT INTO module_switches (company_id, module_key, is_enabled) SELECT ?, module_key, 1 FROM module_switches WHERE company_id = 1')->execute([$new]);
    $db->prepare('INSERT INTO reject_reasons (company_id, gate, code, name_en, name_ar) SELECT ?, gate, code, name_en, name_ar FROM reject_reasons WHERE company_id = 1')->execute([$new]);
    $db->prepare("INSERT INTO company_branches (company_id, code, name_en, name_ar, is_main) VALUES (?, 'MAIN', 'Main branch', 'الفرع الرئيسي', 1)")->execute([$new]);
    $db->prepare('INSERT INTO leave_types (company_id, name, max_days_allowed, is_paid, description, kind) SELECT ?, name, max_days_allowed, is_paid, description, kind FROM leave_types WHERE company_id = 1')->execute([$new]);
    $db->prepare('INSERT INTO prep_waste_reasons (company_id, code, name_en, name_ar, waste_class, default_decision) SELECT ?, code, name_en, name_ar, waste_class, default_decision FROM prep_waste_reasons WHERE company_id = 1')->execute([$new]);
    $db->prepare('INSERT INTO gl_accounts (company_id, code, name_en, name_ar, type, is_postable, system_key) SELECT ?, code, name_en, name_ar, type, is_postable, system_key FROM gl_accounts WHERE company_id = 1')->execute([$new]);
    $db->prepare("UPDATE gl_accounts c JOIN gl_accounts p ON p.company_id = c.company_id AND p.code = CONCAT(SUBSTRING(c.code, 1, 1), '000') SET c.parent_id = p.id WHERE c.company_id = ? AND c.is_postable = 1")->execute([$new]);
    $s = $db->prepare('SELECT * FROM companies WHERE id = ?'); $s->execute([$new]); json_response($s->fetch(), 201);
case 'PUT':
    if (!$id) error_response('ID required');
    $set = []; $p = [];
    foreach ($fields as $f) if (array_key_exists($f, $body)) { $set[] = "`$f` = ?"; $p[] = $body[$f]; }
    if (!$set) error_response('Nothing to update', 422);
    $p[] = $id; $db->prepare('UPDATE companies SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($p);
    $s = $db->prepare('SELECT * FROM companies WHERE id = ?'); $s->execute([$id]); json_response($s->fetch());
case 'DELETE':
    error_response('Companies are never deleted. Set is_active = 0 instead.', 405);
}
