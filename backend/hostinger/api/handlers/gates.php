<?php
// Gates of a branch (the truck chooses one when the entry permit is issued).
require_once __DIR__ . '/../core/crud.php';
$cid = (int)$GLOBALS['company_id'];
if (in_array($GLOBALS['method'], ['POST', 'PUT'], true)) {
    $b = $GLOBALS['body'];
    if ($GLOBALS['method'] === 'POST') {
        if (empty($b['code']) || empty($b['name'])) error_response('code and name are required', 422);
        $b['branch_id'] = !empty($b['branch_id']) ? branch_validate($cid, (int)$b['branch_id']) : branch_default($cid);
    } elseif (!empty($b['branch_id'])) $b['branch_id'] = branch_validate($cid, (int)$b['branch_id']);
    $GLOBALS['body'] = $b;
}
crud_handle(['table' => 'gates', 'search' => ['code', 'name', 'name_ar'], 'filters' => ['is_active'], 'order' => 'code ASC', 'branch_col' => 'branch_id']);
