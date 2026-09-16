<?php
declare(strict_types=1);
require __DIR__ . '/scryfall_local.php';
require __DIR__ . '/sync_state.php';
require __DIR__ . '/auth.php';
authRequireAdmin();

function syncJson(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    syncJson(['ok' => false, 'message' => 'Método não permitido.'], 405);
}

try {
    $config = appConfig();
    $storage = rtrim((string)$config['storage_dir'], '/');
    @mkdir($storage, 0775, true);
    $bulkType = (string)($config['scryfall']['bulk_type'] ?? 'default_cards');
    $force = ($_POST['force'] ?? '') === '1';

    if (syncCatalogProgress($storage)['active']) {
        syncJson(['ok' => false, 'message' => 'Já existe uma sincronização em andamento.'], 409);
    }

    // Sem "force", só baixa quando o Scryfall publicou algo mais novo do que a importação local.
    if (!$force) {
        $manifest = scryfallGetJson('https://api.scryfall.com/bulk-data');
        $entry = null;
        foreach (($manifest['data'] ?? []) as $candidate) {
            if (($candidate['type'] ?? '') === $bulkType) { $entry = $candidate; break; }
        }
        if (!$entry) throw new RuntimeException('A coleção de dados configurada não foi encontrada no Scryfall.');
        $stmt = db()->prepare('SELECT scryfall_updated_at FROM sync_status WHERE bulk_type = :bulk_type LIMIT 1');
        $stmt->execute([':bulk_type' => $bulkType]);
        $localUpdatedAt = $stmt->fetchColumn();
        $remoteTimestamp = strtotime((string)($entry['updated_at'] ?? ''));
        $localTimestamp = $localUpdatedAt ? strtotime((string)$localUpdatedAt) : false;
        if ($remoteTimestamp !== false && $localTimestamp !== false && $remoteTimestamp <= $localTimestamp) {
            syncJson(['ok' => true, 'started' => false, 'up_to_date' => true, 'remote_updated_at' => $entry['updated_at'] ?? null,
                'message' => 'Seu acervo já está com a publicação mais recente do Scryfall.']);
        }
    }

    $progressPath = $storage . '/sync-progress.json';
    $initial = ['state' => 'starting', 'bulk_type' => $bulkType, 'bytes_downloaded' => 0, 'bytes_total' => 0, 'imported' => 0,
        'import_percent' => null, 'last_error' => '', 'started_at' => time(), 'updated_at' => time(), 'finished_at' => null];
    @file_put_contents($progressPath . '.tmp', json_encode($initial, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    @chmod($progressPath . '.tmp', 0666);
    @rename($progressPath . '.tmp', $progressPath);

    $logPath = $storage . '/sync.log';
    $command = [PHP_BINDIR . '/php', __DIR__ . '/bin/sync_scryfall.php', $bulkType];
    $shell = 'nohup ' . implode(' ', array_map('escapeshellarg', $command)) . ' >> ' . escapeshellarg($logPath) . ' 2>&1 < /dev/null & echo $!';
    $process = @proc_open(['/bin/sh', '-c', $shell], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $logPath, 'ab']], $pipes, __DIR__ . '/bin');
    if (!is_resource($process)) throw new RuntimeException('Não foi possível iniciar a sincronização.');
    $pid = (int)trim((string)stream_get_contents($pipes[1]));
    foreach ($pipes as $pipe) if (is_resource($pipe)) @fclose($pipe);
    @proc_close($process);

    syncJson(['ok' => true, 'started' => true, 'pid' => $pid,
        'message' => 'Sincronização iniciada. O download e a importação continuam mesmo se você sair desta página.']);
} catch (Throwable $e) {
    syncJson(['ok' => false, 'message' => $e->getMessage()], 502);
}
