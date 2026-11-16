<?php
// Company-scoped CRUD (see core/crud.php). Generated from the legacy handler; edit the config below.
require_once __DIR__ . '/../core/crud.php';
crud_handle([
    'table' => 'leave_requests',
    'search' => ['number', 'employee_name'],
    'filters' => ['status'],
    'order' => 'id DESC',
    'prefix' => 'LR',
]);
