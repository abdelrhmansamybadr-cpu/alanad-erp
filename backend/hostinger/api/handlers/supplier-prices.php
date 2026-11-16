<?php
// Dated supplier price lists (price per kg).
//   GET  /supplier-prices[?supplier_id=&item_id=]        all entries, newest first
//   GET  /supplier-prices/current?supplier_id=&item_id=  the price valid today (or ?date=YYYY-MM-DD)
//   POST /supplier-prices {supplier_id, item_id, price, valid_from?}   a new price from a date; earlier entries are kept as history
//   DELETE /supplier-prices/{id}
require_once __DIR__ . '/../core/purchasing.php';
$u = $GLOBALS['auth_user']; $cid = (int)$u['company_id']; $id = $GLOBALS['id']; $method = $GLOBALS['method']; $body = $GLOBALS['body'];

if ($method === 'GET' && $id === 'current') {
    $date = $_GET['date'] ?? date('Y-m-d');
    $price = pur_price($cid, (int)($_GET['supplier_id'] ?? 0), (int)($_GET['item_id'] ?? 0), $date);
    json_response(['price' => $price, 'date' => $date]);
}
if ($method === 'GET') {
    $w = 'p.company_id = ?'; $p = [$cid];
    foreach (['supplier_id', 'item_id'] as $f) if (!empty($_GET[$f])) { $w .= " AND p.$f = ?"; $p[] = (int)$_GET[$f]; }
    $q = db()->prepare("SELECT p.*, s.name AS supplier_name, i.name AS item_name, i.name_ar AS item_name_ar FROM supplier_prices p JOIN suppliers s ON s.id = p.supplier_id JOIN items i ON i.id = p.item_id WHERE $w ORDER BY p.valid_from DESC, p.id DESC LIMIT 500");
    $q->execute($p); json_response($q->fetchAll());
}
if ($method === 'POST') {
    $price = $body['price'] ?? null;
    if (!is_numeric($price) || (float)$price <= 0) error_response('price must be greater than zero', 422);
    $from = $body['valid_from'] ?? date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) error_response('valid_from must be YYYY-MM-DD', 422);
    foreach (['suppliers' => 'supplier_id', 'items' => 'item_id'] as $tbl => $f) {
        $c = db()->prepare("SELECT 1 FROM $tbl WHERE id = ? AND company_id = ?"); $c->execute([(int)($body[$f] ?? 0), $cid]);
        if (!$c->fetchColumn()) error_response("$f not found", 422);
    }
    try {
        db()->prepare('INSERT INTO supplier_prices (company_id, supplier_id, item_id, price, valid_from, created_by) VALUES (?,?,?,?,?,?)')
            ->execute([$cid, (int)$body['supplier_id'], (int)$body['item_id'], round((float)$price, 4), $from, $u['user_id']]);
    } catch (PDOException $e) {
        if ((int)($e->errorInfo[1] ?? 0) === 1062) error_response('There is already a price for this supplier, item and date', 409);
        throw $e;
    }
    json_response(['id' => (int)db()->lastInsertId(), 'price' => round((float)$price, 4), 'valid_from' => $from], 201);
}
if ($method === 'DELETE' && $id) {
    $st = db()->prepare('DELETE FROM supplier_prices WHERE id = ? AND company_id = ?'); $st->execute([$id, $cid]);
    if (!$st->rowCount()) error_response('Not found', 404);
    json_response(['message' => 'Deleted']);
}
error_response('Not found', 404);
