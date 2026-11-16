<?php
// Company-scoped CRUD (see core/crud.php). Generated from the legacy handler; edit the config below.
require_once __DIR__ . '/../core/crud.php';
crud_handle([
    'table' => 'salary_slips',
    'search' => ['number', 'employee_name'],
    'filters' => ['status'],
    'order' => 'id DESC',
]);
