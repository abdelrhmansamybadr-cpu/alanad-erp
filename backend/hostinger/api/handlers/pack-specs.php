<?php
// Pack specifications: how an item is packed, in general or for one client (box sizes allowed, boxes per carton, cartons per pallet).
require_once __DIR__ . '/../core/crud.php';
require_once __DIR__ . '/../core/packing.php';
$cid = (int)$GLOBALS['company_id'];
if (in_array($GLOBALS['method'], ['POST', 'PUT'], true)) {
    $b = $GLOBALS['body'];
    if ($GLOBALS['method'] === 'POST') {
        $it = db()->prepare('SELECT 1 FROM items WHERE id = ? AND company_id = ?'); $it->execute([(int)($b['item_id'] ?? 0), $cid]);
        if (!$it->fetchColumn()) error_response('Item not found', 404);
    }
    if (!empty($b['customer_id'])) { $c = db()->prepare('SELECT 1 FROM customers WHERE id = ? AND company_id = ?'); $c->execute([(int)$b['customer_id'], $cid]); if (!$c->fetchColumn()) error_response('Customer not found', 422); }
    if (isset($b['box_kg_options'])) { $sizes = pk_sizes((string)$b['box_kg_options']); if (!$sizes) error_response('box_kg_options needs at least one size, e.g. "1,2"', 422); $b['box_kg_options'] = implode(',', $sizes); }
    foreach (['boxes_per_carton', 'cartons_per_pallet'] as $f) if (isset($b[$f]) && $b[$f] !== null && (!is_numeric($b[$f]) || (int)$b[$f] != $b[$f] || (int)$b[$f] < 1)) error_response("$f must be a whole number above zero", 422);
    if (isset($b['default_box_kg']) && (!is_numeric($b['default_box_kg']) || (float)$b['default_box_kg'] <= 0)) error_response('default_box_kg must be a positive number', 422);
    $GLOBALS['body'] = $b;
}
crud_handle(['table' => 'pack_specs', 'search' => ['label_note'], 'filters' => ['item_id', 'customer_id', 'is_active'], 'order' => 'id DESC']);
