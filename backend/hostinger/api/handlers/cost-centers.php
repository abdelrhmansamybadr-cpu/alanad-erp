<?php
// Company-scoped CRUD (see core/crud.php). Generated from the legacy handler; edit the config below.
require_once __DIR__ . '/../core/crud.php';
crud_handle([
    'table' => 'fin_cost_centers',
    'search' => ['name', 'code'],
    'filters' => ['department'],
    'order' => 'id DESC',
]);
