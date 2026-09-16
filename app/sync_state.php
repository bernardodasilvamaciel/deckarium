<?php
declare(strict_types=1);

/** Lê o progresso da sincronização do catálogo e confirma pelo lock se o processo ainda está vivo. */
function syncCatalogProgress(string $storage): array
{
    $progress = json_decode((string)@file_get_contents($storage . '/sync-progress.json'), true);
    $progress = is_array($progress) ? $progress : [];
    $state = (string)($progress['state'] ?? '');
    $lock = @fopen($storage . '/sync-catalog.lock', 'c');
    $locked = false;
    if ($lock) {
        $free = @flock($lock, LOCK_EX | LOCK_NB);
        $locked = !$free;
        if ($free) flock($lock, LOCK_UN);
        fclose($lock);
    }
    $running = ['starting', 'checking', 'downloading', 'importing'];
    if ($locked && !in_array($state, $running, true)) $state = 'checking';
    if (!$locked && in_array($state, $running, true)) {
        // "starting" é gravado pela página antes de o processo pegar o lock.
        $state = ($state === 'starting' && time() - (int)($progress['updated_at'] ?? 0) < 20) ? 'starting' : 'interrupted';
    }
    $labels = [
        '' => 'Nenhuma sincronização iniciada por aqui',
        'starting' => 'Preparando sincronização',
        'checking' => 'Consultando o Scryfall',
        'downloading' => 'Baixando dados do Scryfall',
        'importing' => 'Importando cartas',
        'completed' => 'Catálogo atualizado',
        'error' => 'A sincronização falhou',
        'interrupted' => 'Sincronização interrompida',
    ];
    $bytesTotal = (int)($progress['bytes_total'] ?? 0);
    $bytesDone = (int)($progress['bytes_downloaded'] ?? 0);
    $percent = match ($state) {
        'downloading' => $bytesTotal > 0 ? min(100, (int)floor($bytesDone / $bytesTotal * 100)) : null,
        'importing' => isset($progress['import_percent']) ? (int)$progress['import_percent'] : null,
        'completed' => 100,
        default => null,
    };
    return [
        'state' => $state,
        'label' => $labels[$state] ?? 'Status desconhecido',
        'active' => in_array($state, $running, true),
        'percent' => $percent,
        'bytes_downloaded' => $bytesDone,
        'bytes_total' => $bytesTotal,
        'imported' => (int)($progress['imported'] ?? 0),
        'bulk_type' => (string)($progress['bulk_type'] ?? ''),
        'remote_updated_at' => $progress['remote_updated_at'] ?? null,
        'last_error' => $state === 'interrupted' && empty($progress['last_error'])
            ? 'A sincronização parou antes do fim. As cartas já importadas foram mantidas; inicie novamente para concluir.'
            : (string)($progress['last_error'] ?? ''),
        'started_at' => (int)($progress['started_at'] ?? 0),
        'updated_at' => (int)($progress['updated_at'] ?? 0),
        'finished_at' => isset($progress['finished_at']) ? (int)$progress['finished_at'] : null,
    ];
}
