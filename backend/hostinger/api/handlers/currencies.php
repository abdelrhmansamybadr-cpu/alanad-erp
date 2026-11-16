<?php
// Company-scoped CRUD (see core/crud.php). Generated from the legacy handler; edit the config below.
require_once __DIR__ . '/../core/crud.php';
crud_handle([
    'table' => 'fin_currencies',
    'search' => ['code', 'name'],
    'filters' => [],
    'order' => 'id DESC',
]);
