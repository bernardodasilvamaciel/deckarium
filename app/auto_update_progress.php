<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require __DIR__ . '/auto_update.php';
require __DIR__ . '/auth.php';
authRequireAdmin();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$config = require __DIR__ . '/config.php';
try {
    $payload = ['ok' => true] + autoUpdateOverview(db(), rtrim((string)$config['storage_dir'], '/'));
} catch (Throwable $e) {
    http_response_code(500);
    $payload = ['ok' => false, 'message' => $e->getMessage()];
}
echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
