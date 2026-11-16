<?php
// Specifications of prepared products: what goes in, what comes out, planned yield, limits, time outside the cold room.
//   GET  /prep-specs        POST /prep-specs {code, name_en, name_ar?, input_item_id, output_item_id, byproduct_item_id?, planned_yield_pct?, yield_tolerance_pct?, waste_high_pct?, max_minutes_outside?, requires_wash?}
//   PUT  /prep-specs/{id}   same fields (a spec is deactivated with is_active = 0, never deleted)
require_once __DIR__ . '/../core/production.php';
$u = $GLOBALS['auth_user']; $cid = (int)$u['company_id']; $id = $GLOBALS['id']; $method = $GLOBALS['method']; $body = $GLOBALS['body'];
if ($method === 'GET') {
    if ($id) { $s = prod_spec_row($cid, (int)$id); json_response($s); }
    json_response(prod_spec_list($cid));
}
if ($method === 'POST' && !$id) json_response(prod_spec_save($u, null, $body), 201);
if ($method === 'PUT' && $id) json_response(prod_spec_save($u, (int)$id, $body));
error_response('Method not allowed', 405);
