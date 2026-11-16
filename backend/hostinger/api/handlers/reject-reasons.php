<?php
if ($GLOBALS['method'] !== 'GET') error_response('Method not allowed', 405);
$q = db()->prepare('SELECT code, gate, name_en, name_ar FROM reject_reasons WHERE company_id = ? AND is_active = 1 ORDER BY id');
$q->execute([$GLOBALS['company_id']]); json_response($q->fetchAll());
