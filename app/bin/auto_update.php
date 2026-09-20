<?php
declare(strict_types=1);
require __DIR__ . '/common.php';
require dirname(__DIR__) . '/auto_update.php';

/*
 * Rotina automática chamada pelo cron do container (padrão: 06:10 e 18:10).
 *
 *   php bin/auto_update.php [--force] [--no-images] [--mode=all|unique] [--source=cron|manual]
 *
 * Consulta o manifesto do Scryfall; havendo publicação mais nova que a importação
 * local, roda bin/sync_scryfall.php e, na sequência, bin/download_images.php.
 * O andamento e o resultado ficam em auto_update_runs, exibidos em /status.php.
 */

$options = ['force' => false, 'images' => true, 'source' => 'cron', 'mode' => null];
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--force') $options['force'] = true;
    elseif ($argument === '--no-images') $options['images'] = false;
    elseif (str_starts_with($argument, '--mode=')) $options['mode'] = substr($argument, 7);
    elseif (str_starts_with($argument, '--source=')) $options['source'] = substr($argument, 9);
    else { fwrite(STDERR, "Argumento desconhecido: {$argument}\n"); exit(1); }
}
$source = $options['source'] === 'manual' ? 'manual' : 'cron';

$config = cfg();
$autoConfig = autoUpdateConfig();
$storage = rtrim($config['storage_dir'], '/');
@mkdir($storage, 0775, true);
$bulkType = (string)$config['scryfall']['bulk_type'];
$imageMode = in_array((string)$options['mode'], ['all', 'unique'], true) ? (string)$options['mode'] : $autoConfig['image_mode'];

$stamp = static fn(): string => date('d/m/Y H:i:s');
echo "[{$stamp()}] Rotina automática iniciada ({$source}).\n";

// Uma rotina por vez: se a anterior ainda estiver rodando, a execução de agora é descartada.
$lock = @fopen($storage . '/auto-update.lock', 'c');
if (!$lock || !@flock($lock, LOCK_EX | LOCK_NB)) {
    echo "[{$stamp()}] Outra rotina automática ainda está em andamento. Nada a fazer.\n";
    exit(0);
}

$pdo = db();
autoUpdateSchema($pdo);
autoUpdateReconcile($pdo, $storage);

$insert = $pdo->prepare('INSERT INTO auto_update_runs (trigger_source, state, bulk_type) VALUES (?, ?, ?) RETURNING id');
$insert->execute([$source, 'checking', $bulkType]);
$runId = (int)$insert->fetchColumn();
$finished = false;

/** Grava o andamento da rotina; a página Status lê essa mesma linha. */
function autoRunPatch(array $fields): void
{
    global $pdo, $runId;
    if (!$fields) return;
    $sets = [];
    $values = [];
    foreach ($fields as $column => $value) { $sets[] = "{$column} = ?"; $values[] = $value; }
    $values[] = $runId;
    try {
        $pdo->prepare('UPDATE auto_update_runs SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($values);
    } catch (Throwable $e) {
        fwrite(STDERR, 'Não foi possível gravar o andamento da rotina: ' . $e->getMessage() . PHP_EOL);
    }
}

function autoRunFinish(string $state, array $fields = []): void
{
    global $finished;
    if ($finished) return;
    $finished = true;
    autoRunPatch(['state' => $state] + $fields + ['finished_at' => date('c')]);
}

set_exception_handler(static function (Throwable $e) use ($stamp): void {
    autoRunFinish('error', ['error' => $e->getMessage()]);
    fwrite(STDERR, "[{$stamp()}] Erro: " . $e->getMessage() . PHP_EOL);
    exit(1);
});
register_shutdown_function(static function (): void {
    autoRunFinish('interrupted', ['error' => 'A rotina parou antes do fim. O que já foi importado ou baixado continua no acervo.']);
});
if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    foreach ([SIGTERM, SIGINT] as $signal) {
        pcntl_signal($signal, static function () use ($stamp): void {
            autoRunFinish('interrupted', ['error' => 'A rotina foi interrompida. O que já foi importado ou baixado continua no acervo.']);
            fwrite(STDERR, "[{$stamp()}] Rotina interrompida por sinal do sistema.\n");
            exit(1);
        });
    }
}

/** Roda um script do downloader/sincronizador herdando a saída, que o cron manda para storage/auto-update.log. */
function autoRunScript(array $command): int
{
    $exitCode = 0;
    passthru(implode(' ', array_map('escapeshellarg', $command)), $exitCode);
    return $exitCode;
}

function autoRunProgress(string $path): array
{
    $data = json_decode((string)@file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

// 1. O Scryfall publicou algo mais novo do que a última importação?
$manifest = json_decode(httpGet('https://api.scryfall.com/bulk-data'), true, flags: JSON_THROW_ON_ERROR);
$entry = null;
foreach (($manifest['data'] ?? []) as $candidate) {
    if (($candidate['type'] ?? '') === $bulkType) { $entry = $candidate; break; }
}
if (!$entry) throw new RuntimeException("A coleção {$bulkType} não foi encontrada no manifesto do Scryfall.");

$remoteUpdatedAt = (string)($entry['updated_at'] ?? '');
$localStatus = $pdo->prepare('SELECT scryfall_updated_at FROM sync_status WHERE bulk_type = ? LIMIT 1');
$localStatus->execute([$bulkType]);
$localUpdatedAt = $localStatus->fetchColumn();
$remoteTimestamp = $remoteUpdatedAt !== '' ? strtotime($remoteUpdatedAt) : false;
$localTimestamp = $localUpdatedAt ? strtotime((string)$localUpdatedAt) : false;
$hasUpdate = $remoteTimestamp !== false && ($localTimestamp === false || $remoteTimestamp > $localTimestamp);
autoRunPatch(['remote_updated_at' => $remoteUpdatedAt ?: null, 'had_update' => $hasUpdate ? 'true' : 'false']);

if (!$hasUpdate && !$options['force']) {
    echo "[{$stamp()}] Nada novo no Scryfall (publicação de {$remoteUpdatedAt}).\n";
    autoRunFinish('up_to_date');
    exit(0);
}

// 2. Importa o catálogo publicado.
echo "[{$stamp()}] Publicação nova em {$remoteUpdatedAt}. Importando o catálogo...\n";
autoRunPatch(['state' => 'syncing', 'had_update' => 'true']);
$syncCode = autoRunScript([PHP_BINARY, __DIR__ . '/sync_scryfall.php', $bulkType]);
$syncProgress = autoRunProgress($storage . '/sync-progress.json');
$syncFields = [
    'sync_run_id' => isset($syncProgress['run_id']) ? (int)$syncProgress['run_id'] : null,
    'cards_imported' => (int)($syncProgress['imported'] ?? 0),
    'cards_added' => (int)($syncProgress['added'] ?? 0),
];
autoRunPatch($syncFields);

if ($syncCode === 2) {
    echo "[{$stamp()}] Já havia uma sincronização em andamento; a rotina será retomada no próximo horário.\n";
    autoRunFinish('skipped');
    exit(0);
}
if ($syncCode !== 0) {
    $message = (string)($syncProgress['last_error'] ?? '') ?: "A importação do catálogo terminou com código {$syncCode}.";
    autoRunFinish('error', ['error' => $message]);
    fwrite(STDERR, "[{$stamp()}] {$message}\n");
    exit(1);
}
echo "[{$stamp()}] Catálogo importado: {$syncFields['cards_imported']} registros, {$syncFields['cards_added']} cartas novas.\n";

if (!$options['images']) {
    autoRunFinish('completed');
    exit(0);
}

// 3. Baixa as imagens das cartas (retomável: o que já está no disco é preservado).
echo "[{$stamp()}] Baixando as imagens ({$imageMode}, normal)...\n";
autoRunPatch(['state' => 'downloading_images']);
// Uma pausa manual antiga não pode segurar a rotina automática para sempre.
@unlink($storage . '/STOP_DOWNLOAD');
$imageCode = autoRunScript([PHP_BINARY, __DIR__ . '/download_images.php', $imageMode, 'normal', (string)$autoConfig['image_concurrency']]);
$imageProgress = autoRunProgress($storage . '/download-progress.json');
$imageFields = [
    'images_downloaded' => (int)($imageProgress['downloaded'] ?? 0),
    'images_failed' => (int)($imageProgress['failed'] ?? 0),
    'images_bytes' => (int)($imageProgress['bytes'] ?? 0),
];
autoRunPatch($imageFields);

if ($imageCode === 3) {
    echo "[{$stamp()}] Um download de imagens já estava em andamento; o catálogo novo já está importado.\n";
    autoRunFinish('skipped', ['error' => 'O catálogo foi importado, mas um download de imagens manual já estava rodando.']);
    exit(0);
}
if ($imageCode === 4) {
    autoRunFinish('error', ['error' => 'Espaço em disco insuficiente para baixar as imagens. O catálogo foi importado.']);
    fwrite(STDERR, "[{$stamp()}] Espaço insuficiente para as imagens.\n");
    exit(1);
}
if ($imageCode !== 0 && $imageCode !== 2) {
    autoRunFinish('error', ['error' => "O download das imagens terminou com código {$imageCode}. O catálogo foi importado."]);
    fwrite(STDERR, "[{$stamp()}] Download de imagens terminou com código {$imageCode}.\n");
    exit(1);
}

$pending = $imageFields['images_failed'] > 0 || (string)($imageProgress['state'] ?? '') !== 'completed';
$error = $pending ? (string)($imageProgress['last_error'] ?? '') : '';
autoRunFinish($pending ? 'images_pending' : 'completed', $error !== '' ? ['error' => $error] : []);
echo "[{$stamp()}] Rotina concluída: {$syncFields['cards_added']} cartas novas, {$imageFields['images_downloaded']} imagens baixadas"
    . ($imageFields['images_failed'] > 0 ? ", {$imageFields['images_failed']} com falha" : '') . ".\n";
exit(0);
