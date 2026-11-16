<?php
// Selling prices per kg by item, grade (A / B / C or any) and client (or any), from a date.
require_once __DIR__ . '/../core/crud.php';
$cid = (int)$GLOBALS['company_id'];
if (in_array($GLOBALS['method'], ['POST', 'PUT'], true)) {
    $b = $GLOBALS['body'];
    if ($GLOBALS['method'] === 'POST') {
        $it = db()->prepare('SELECT 1 FROM items WHERE id = ? AND company_id = ?'); $it->execute([(int)($b['item_id'] ?? 0), $cid]);
        if (!$it->fetchColumn()) error_response('Item not found', 404);
        if (empty($b['valid_from'])) $b['valid_from'] = date('Y-m-d');
        $b['created_by'] = $GLOBALS['auth_user']['user_id'];
    }
    if (array_key_exists('price_per_kg', $b) && (!is_numeric($b['price_per_kg']) || (float)$b['price_per_kg'] <= 0)) error_response('price_per_kg must be a positive number', 422);
    if ($GLOBALS['method'] === 'POST' && !isset($b['price_per_kg'])) error_response('price_per_kg is required', 422);
    if (!empty($b['grade']) && !in_array($b['grade'], ['A', 'B', 'C'], true)) error_response('grade must be A, B or C (or empty for any grade)', 422);
    if (isset($b['grade']) && $b['grade'] === '') $b['grade'] = null;
    if (!empty($b['customer_id'])) { $c = db()->prepare('SELECT 1 FROM customers WHERE id = ? AND company_id = ?'); $c->execute([(int)$b['customer_id'], $cid]); if (!$c->fetchColumn()) error_response('Customer not found', 422); }
    if (!empty($b['valid_from']) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$b['valid_from'])) error_response('valid_from must be YYYY-MM-DD', 422);
    $GLOBALS['body'] = $b;
}
crud_handle(['table' => 'sales_prices', 'search' => [], 'filters' => ['item_id', 'customer_id', 'grade', 'is_active'], 'order' => 'valid_from DESC, id DESC']);
