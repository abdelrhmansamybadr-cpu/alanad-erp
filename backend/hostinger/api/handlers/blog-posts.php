<?php
// Company-scoped CRUD (see core/crud.php). Generated from the legacy handler; edit the config below.
require_once __DIR__ . '/../core/crud.php';
crud_handle([
    'table' => 'blog_posts',
    'search' => ['title', 'author'],
    'filters' => ['status'],
    'order' => 'id DESC',
]);
