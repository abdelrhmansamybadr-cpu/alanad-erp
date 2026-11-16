<?php
// Company-scoped CRUD (see core/crud.php).
require_once __DIR__ . '/../core/crud.php';
if ($GLOBALS['method'] === 'PUT' && $GLOBALS['id'] && !empty($GLOBALS['body']['branch_id'])) {     // a warehouse with cold rooms stays in its branch (its stock would change branch)
    $cur = db()->prepare('SELECT branch_id, (SELECT COUNT(*) FROM cold_rooms r WHERE r.warehouse_id = warehouses.id) AS rooms FROM warehouses WHERE id = ? AND company_id = ?');
    $cur->execute([(int)$GLOBALS['id'], (int)$GLOBALS['company_id']]);
    $cw = $cur->fetch();
    if ($cw && (int)$cw['branch_id'] !== (int)$GLOBALS['body']['branch_id'] && (int)$cw['rooms'] > 0) error_response('This warehouse has cold rooms: its branch cannot change', 409);
}
crud_handle(['table' => 'warehouses', 'search' => ['name', 'code', 'name_ar'], 'filters' => ['type', 'is_active'], 'order' => 'code ASC', 'branch_col' => 'branch_id']);
