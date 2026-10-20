<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/src/firefly.php';
require_method('GET');
try {
    json_response(['ok' => true, 'categories' => get_firefly_categories()]);
} catch (Throwable $e) {
    json_response(['ok' => false, 'message' => $e->getMessage()], 502);
}
