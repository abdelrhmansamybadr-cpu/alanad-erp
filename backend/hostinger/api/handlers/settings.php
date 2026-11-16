<?php
// Settings: every open question is an editable setting. View: any user; change: administrators (pipeline).
$db = db(); $id = $GLOBALS['id']; $method = $GLOBALS['method']; $body = $GLOBALS['body'];
$cid = (int)$GLOBALS['company_id'];
function setting_cast(array $s): array {
    $v = $s['setting_value'];
    $s['value'] = match ($s['value_type']) { 'int' => (int)$v, 'decimal' => (float)$v, 'bool' => $v === '1' || $v === 'true', 'json' => json_decode((string)$v, true), default => $v };
    return $s;
}
if ($method === 'GET') {
    $q = $db->prepare('SELECT * FROM settings WHERE company_id = ? ' . (!empty($_GET['group']) ? 'AND group_name = ? ' : '') . 'ORDER BY group_name, setting_key');
    $q->execute(!empty($_GET['group']) ? [$cid, $_GET['group']] : [$cid]);
    json_response(array_map('setting_cast', $q->fetchAll()));
}
if ($method === 'PUT') {
    // PUT /settings  { "finance.vat_rate_pct": 15, ... }  (keys contain dots: read the raw JSON, not the sanitised body)
    $raw = json_decode(file_get_contents('php://input'), true) ?? [];
    if (!$raw) error_response('Nothing to update', 422);
    foreach ($raw as $k => $v) {
        $k = (string)$k;
        $q = $db->prepare('SELECT value_type FROM settings WHERE company_id = ? AND setting_key = ?'); $q->execute([$cid, $k]);
        $type = $q->fetchColumn(); if (!$type) error_response("Unknown setting: $k", 422);
        if (($type === 'int' || $type === 'decimal') && !is_numeric($v)) error_response("$k must be a number", 422);
        if ($type === 'bool') $v = ($v === true || $v === 1 || $v === '1' || $v === 'true') ? '1' : '0';
        if ($type === 'json') $v = json_encode($v);
        $db->prepare('UPDATE settings SET setting_value = ?, updated_by = ? WHERE company_id = ? AND setting_key = ?')->execute([(string)$v, $GLOBALS['auth_user']['user_id'], $cid, $k]);
    }
    $q = $db->prepare('SELECT * FROM settings WHERE company_id = ? ORDER BY group_name, setting_key'); $q->execute([$cid]);
    json_response(array_map('setting_cast', $q->fetchAll()));
}
error_response('Method not allowed', 405);
