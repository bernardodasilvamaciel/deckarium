<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require __DIR__ . '/functions.php';
require __DIR__ . '/sync_state.php';
require __DIR__ . '/auth.php';
authRequireAdmin();

$config = require __DIR__ . '/config.php';
$payload = ['ok' => true] + syncCatalogProgress(rtrim((string)$config['storage_dir'], '/'));
try {
    $payload['rows'] = array_map(static fn(array $row): array => [
        'bulk_type' => (string)$row['bulk_type'],
        'scryfall_updated_at' => displayDate($row['scryfall_updated_at']),
        'imported_at' => displayDate($row['imported_at']),
        'card_count' => number_format((int)$row['card_count'], 0, ',', '.'),
    ], db()->query('SELECT * FROM sync_status ORDER BY imported_at DESC')->fetchAll());
} catch (Throwable) {
    $payload['rows'] = null;
}
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
