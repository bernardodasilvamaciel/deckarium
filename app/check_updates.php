<?php
declare(strict_types=1);
require __DIR__ . '/scryfall_local.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

try {
    $config = appConfig();
    $bulkType = (string)($config['scryfall']['bulk_type'] ?? 'default_cards');
    $manifest = scryfallGetJson('https://api.scryfall.com/bulk-data');
    $entry = null;
    foreach (($manifest['data'] ?? []) as $candidate) {
        if (($candidate['type'] ?? '') === $bulkType) {
            $entry = $candidate;
            break;
        }
    }
    if (!$entry) {
        throw new RuntimeException('A coleção de dados configurada não foi encontrada no Scryfall.');
    }

    $stmt = db()->prepare('SELECT scryfall_updated_at, imported_at, card_count FROM sync_status WHERE bulk_type = :bulk_type LIMIT 1');
    $stmt->execute([':bulk_type' => $bulkType]);
    $local = $stmt->fetch() ?: null;
    $remoteUpdatedAt = (string)($entry['updated_at'] ?? '');
    $remoteTimestamp = $remoteUpdatedAt !== '' ? strtotime($remoteUpdatedAt) : false;
    $localTimestamp = $local && !empty($local['scryfall_updated_at']) ? strtotime((string)$local['scryfall_updated_at']) : false;
    $hasUpdate = $remoteTimestamp !== false && ($localTimestamp === false || $remoteTimestamp > $localTimestamp);

    echo json_encode([
        'ok' => true,
        'bulk_type' => $bulkType,
        'remote_updated_at' => $remoteUpdatedAt,
        'local_updated_at' => $local['scryfall_updated_at'] ?? null,
        'local_imported_at' => $local['imported_at'] ?? null,
        'card_count' => (int)($local['card_count'] ?? 0),
        'has_update' => $hasUpdate,
        'checked_at' => gmdate('c'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(502);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
