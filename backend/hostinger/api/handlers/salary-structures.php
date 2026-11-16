<?php
// Company-scoped CRUD (see core/crud.php). Generated from the legacy handler; edit the config below.
require_once __DIR__ . '/../core/crud.php';
crud_handle([
    'table' => 'salary_structures',
    'search' => [],
    'filters' => [],
    'order' => 'name',
    'child' => [
        'key' => 'components',
        'table' => 'salary_structure_components',
        'fk' => 'salary_structure_id',
    ],
]);
