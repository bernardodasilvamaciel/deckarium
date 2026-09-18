<?php
declare(strict_types=1);
require __DIR__ . '/common.php';
require dirname(__DIR__) . '/sync_log.php';

$config = cfg();
$bulkType = $argv[1] ?? $config['scryfall']['bulk_type'];
$allowed = ['oracle_cards', 'default_cards', 'all_cards', 'unique_artwork'];
if (!in_array($bulkType, $allowed, true)) {
    fwrite(STDERR, "Bulk inválido. Use: " . implode(', ', $allowed) . PHP_EOL);
    exit(1);
}

$storage = rtrim($config['storage_dir'], '/');
$bulkDir = $storage . '/bulk';
@mkdir($bulkDir, 0775, true);

// Uma sincronização por vez, seja iniciada pelo terminal ou pela página de status.
$syncLock = @fopen($storage . '/sync-catalog.lock', 'c');
if (!$syncLock || !@flock($syncLock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Já existe uma sincronização do catálogo em andamento." . PHP_EOL);
    exit(2);
}

$syncProgressPath = $storage . '/sync-progress.json';
$syncProgress = [
    'state' => 'checking',
    'bulk_type' => $bulkType,
    'remote_updated_at' => null,
    'bytes_downloaded' => 0,
    'bytes_total' => 0,
    'imported' => 0,
    'added' => 0,
    'run_id' => null,
    'import_percent' => null,
    'last_error' => '',
    'started_at' => time(),
    'updated_at' => time(),
    'finished_at' => null,
];
function syncProgress(array $patch, bool $throttle = false): void
{
    global $syncProgress, $syncProgressPath;
    static $lastWrite = 0.0;
    $syncProgress = array_merge($syncProgress, $patch, ['updated_at' => time()]);
    $now = microtime(true);
    if ($throttle && $now - $lastWrite < 0.8) return;
    $lastWrite = $now;
    $temp = $syncProgressPath . '.' . getmypid() . '.tmp';
    if (@file_put_contents($temp, json_encode($syncProgress, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) !== false) {
        @chmod($temp, 0666);
        @rename($temp, $syncProgressPath);
    }
}
/** Fecha o registro do histórico com o estado final; falhas aqui não podem esconder o erro original. */
function finishSyncRun(string $state, string $error = ''): void
{
    global $syncProgress;
    if (empty($syncProgress['run_id'])) return;
    try {
        $pdo = db();
        if ($pdo->inTransaction()) $pdo->rollBack();
        $pdo->prepare('UPDATE sync_runs SET state = ?, error = ?, finished_at = now(), added = (SELECT count(*) FROM sync_run_cards WHERE run_id = sync_runs.id) WHERE id = ?')
            ->execute([$state, $error, $syncProgress['run_id']]);
    } catch (Throwable) {
    }
}
syncProgress([]);
set_exception_handler(static function (Throwable $e): void {
    finishSyncRun('error', $e->getMessage());
    syncProgress(['state' => 'error', 'last_error' => $e->getMessage(), 'finished_at' => time()]);
    fwrite(STDERR, PHP_EOL . 'Erro: ' . $e->getMessage() . PHP_EOL);
    exit(1);
});
foreach (['SIGTERM' => 15, 'SIGINT' => 2] as $signalName => $signalNumber) {
    if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
        pcntl_async_signals(true);
        pcntl_signal(defined($signalName) ? constant($signalName) : $signalNumber, static function (): void {
            finishSyncRun('interrupted', 'Sincronização interrompida antes do fim.');
            syncProgress(['state' => 'interrupted', 'last_error' => 'Sincronização interrompida antes do fim. Cartas já importadas foram mantidas.', 'finished_at' => time()]);
            exit(1);
        });
    }
}

echo "Consultando manifesto de Bulk Data do Scryfall...\n";
$manifest = json_decode(httpGet('https://api.scryfall.com/bulk-data'), true, flags: JSON_THROW_ON_ERROR);
$entry = null;
foreach (($manifest['data'] ?? []) as $candidate) {
    if (($candidate['type'] ?? '') === $bulkType) {
        $entry = $candidate;
        break;
    }
}
if (!$entry) {
    throw new RuntimeException("Bulk type {$bulkType} não encontrado no manifesto.");
}

// Desde 2026 o Scryfall passou a publicar Bulk Data como JSONL gzipado.
$url = $entry['jsonl_download_uri'] ?? $entry['download_uri'] ?? null;
if (!$url) {
    throw new RuntimeException('O manifesto não contém jsonl_download_uri/download_uri.');
}
$isGzipJsonl = str_contains($url, '.jsonl.gz') || isset($entry['jsonl_download_uri']);
$ext = $isGzipJsonl ? '.jsonl.gz' : '.json';
$target = $bulkDir . '/' . $bulkType . $ext;

echo "Baixando {$bulkType}...\n";
syncProgress(['state' => 'downloading', 'remote_updated_at' => $entry['updated_at'] ?? null, 'bytes_total' => (int)($entry['size'] ?? 0)]);
downloadFile($url, $target, static function (int $downloaded, int $total): void {
    syncProgress(['bytes_downloaded' => $downloaded, 'bytes_total' => $total > 0 ? $total : $GLOBALS['syncProgress']['bytes_total']], true);
});
syncProgress(['bytes_downloaded' => (int)filesize($target), 'bytes_total' => (int)filesize($target)]);
echo "Arquivo salvo em {$target}\n";

$pdo = db();
$upsert = $pdo->prepare(<<<SQL
INSERT INTO cards (
  id, oracle_id, lang, name, mana_cost, cmc, type_line, oracle_text,
  colors, color_identity, keywords, set_code, set_name, collector_number,
  rarity, artist, released_at, layout, image_uri, image_uri_back,
  prices, legalities, card_faces, raw, imported_at
) VALUES (
  :id, :oracle_id, :lang, :name, :mana_cost, :cmc, :type_line, :oracle_text,
  CAST(:colors AS jsonb), CAST(:color_identity AS jsonb), CAST(:keywords AS jsonb),
  :set_code, :set_name, :collector_number, :rarity, :artist, :released_at, :layout,
  :image_uri, :image_uri_back, CAST(:prices AS jsonb), CAST(:legalities AS jsonb),
  CAST(:card_faces AS jsonb), CAST(:raw AS jsonb), now()
)
ON CONFLICT (id) DO UPDATE SET
  oracle_id = EXCLUDED.oracle_id,
  lang = EXCLUDED.lang,
  name = EXCLUDED.name,
  mana_cost = EXCLUDED.mana_cost,
  cmc = EXCLUDED.cmc,
  type_line = EXCLUDED.type_line,
  oracle_text = EXCLUDED.oracle_text,
  colors = EXCLUDED.colors,
  color_identity = EXCLUDED.color_identity,
  keywords = EXCLUDED.keywords,
  set_code = EXCLUDED.set_code,
  set_name = EXCLUDED.set_name,
  collector_number = EXCLUDED.collector_number,
  rarity = EXCLUDED.rarity,
  artist = EXCLUDED.artist,
  released_at = EXCLUDED.released_at,
  layout = EXCLUDED.layout,
  image_uri = EXCLUDED.image_uri,
  image_uri_back = EXCLUDED.image_uri_back,
  prices = EXCLUDED.prices,
  legalities = EXCLUDED.legalities,
  card_faces = EXCLUDED.card_faces,
  raw = EXCLUDED.raw,
  imported_at = now()
RETURNING (xmax = 0) AS inserted
SQL);

/** Retorna true quando a carta entrou no banco pela primeira vez. */
function importCard(PDOStatement $upsert, array $card): bool
{
    [$front, $back] = pickImageUris($card);
    $upsert->execute([
        ':id' => $card['id'],
        ':oracle_id' => $card['oracle_id'] ?? null,
        ':lang' => $card['lang'] ?? 'en',
        ':name' => $card['name'] ?? '',
        ':mana_cost' => $card['mana_cost'] ?? null,
        ':cmc' => $card['cmc'] ?? null,
        ':type_line' => $card['type_line'] ?? null,
        ':oracle_text' => $card['oracle_text'] ?? null,
        ':colors' => json_encode($card['colors'] ?? [], JSON_UNESCAPED_UNICODE),
        ':color_identity' => json_encode($card['color_identity'] ?? [], JSON_UNESCAPED_UNICODE),
        ':keywords' => json_encode($card['keywords'] ?? [], JSON_UNESCAPED_UNICODE),
        ':set_code' => $card['set'] ?? null,
        ':set_name' => $card['set_name'] ?? null,
        ':collector_number' => $card['collector_number'] ?? null,
        ':rarity' => $card['rarity'] ?? null,
        ':artist' => $card['artist'] ?? null,
        ':released_at' => $card['released_at'] ?? null,
        ':layout' => $card['layout'] ?? null,
        ':image_uri' => $front,
        ':image_uri_back' => $back,
        ':prices' => json_encode($card['prices'] ?? [], JSON_UNESCAPED_UNICODE),
        ':legalities' => json_encode($card['legalities'] ?? [], JSON_UNESCAPED_UNICODE),
        ':card_faces' => json_encode($card['card_faces'] ?? [], JSON_UNESCAPED_UNICODE),
        ':raw' => json_encode($card, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    $inserted = $upsert->fetchColumn();
    $upsert->closeCursor();
    return $inserted === true || $inserted === 't' || $inserted === 1 || $inserted === '1';
}

// Registra a execução no histórico para saber exatamente quais cartas foram adicionadas.
syncLogSchema($pdo);
$initialImport = !$pdo->query('SELECT EXISTS (SELECT 1 FROM cards)')->fetchColumn();
$run = $pdo->prepare('INSERT INTO sync_runs (bulk_type, remote_updated_at, initial_import) VALUES (?, ?, ?) RETURNING id');
$run->execute([$bulkType, $entry['updated_at'] ?? null, $initialImport ? 'true' : 'false']);
$runId = (int)$run->fetchColumn();
$runProgress = $pdo->prepare('UPDATE sync_runs SET processed = ?, added = ? WHERE id = ?');

$count = 0;
$added = 0;
$pendingAdded = [];
syncProgress(['state' => 'importing', 'imported' => 0, 'added' => 0, 'run_id' => $runId, 'import_percent' => 0]);
// Os IDs novos são gravados na mesma transação do lote: se o lote falhar, o histórico não fica divergente.
$flushAdded = static function () use ($pdo, $runId, $runProgress, &$pendingAdded, &$count, &$added): void {
    if ($pendingAdded) syncLogAddCards($pdo, $runId, $pendingAdded);
    $pendingAdded = [];
    $runProgress->execute([$count, $added, $runId]);
};
$importedOne = static function (array $card, float|int|null $percent) use ($upsert, $pdo, &$count, &$added, &$pendingAdded, $flushAdded): void {
    if (importCard($upsert, $card)) {
        $added++;
        $pendingAdded[] = $card['id'];
    }
    $count++;
    if ($count % 1000 === 0) {
        $flushAdded();
        $pdo->commit();
        echo "Importadas {$count} cartas ({$added} novas)...\r";
        syncProgress(['imported' => $count, 'added' => $added, 'import_percent' => $percent === null ? null : (int)floor($percent)]);
        $pdo->beginTransaction();
    }
};
$pdo->beginTransaction();
try {
    if ($isGzipJsonl) {
        // Descompacta em blocos para saber quanto do arquivo já foi lido e mostrar o percentual real.
        $fh = fopen($target, 'rb');
        $inflate = inflate_init(ZLIB_ENCODING_GZIP);
        if (!$fh || !$inflate) throw new RuntimeException('Falha ao abrir JSONL gzipado.');
        $compressedSize = max(1, (int)filesize($target));
        $buffer = '';
        $consumeLines = static function (string &$buffer, bool $final, float $percent) use ($importedOne): void {
            $offset = 0;
            while (($newline = strpos($buffer, "\n", $offset)) !== false) {
                $line = trim(substr($buffer, $offset, $newline - $offset));
                $offset = $newline + 1;
                if ($line !== '') $importedOne(json_decode($line, true, flags: JSON_THROW_ON_ERROR), $percent);
            }
            $buffer = substr($buffer, $offset);
            if ($final && trim($buffer) !== '') {
                $importedOne(json_decode(trim($buffer), true, flags: JSON_THROW_ON_ERROR), 100);
                $buffer = '';
            }
        };
        while (!feof($fh)) {
            $chunk = fread($fh, 1 << 20);
            if ($chunk === false) throw new RuntimeException('Falha ao ler o arquivo baixado.');
            if ($chunk === '') continue;
            $inflated = inflate_add($inflate, $chunk, ZLIB_SYNC_FLUSH);
            if ($inflated === false) throw new RuntimeException('O arquivo baixado está corrompido. Tente sincronizar novamente.');
            $buffer .= $inflated;
            $consumeLines($buffer, false, min(99.9, ftell($fh) / $compressedSize * 100));
        }
        $buffer .= (string)inflate_add($inflate, '', ZLIB_FINISH);
        $consumeLines($buffer, true, 100);
        fclose($fh);
    } else {
        // Compatibilidade com o formato JSON-array antigo.
        $raw = file_get_contents($target);
        if ($raw === false) throw new RuntimeException('Falha ao ler bulk JSON.');
        $cards = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        $totalCards = max(1, count($cards));
        foreach ($cards as $card) {
            $importedOne($card, ($count + 1) / $totalCards * 100);
        }
    }
    $flushAdded();
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
}

echo "\nImportação concluída: {$count} registros, {$added} cartas novas.\n";
$pdo->prepare("UPDATE sync_runs SET state = 'completed', finished_at = now(), processed = ?, added = ? WHERE id = ?")->execute([$count, $added, $runId]);
$status = $pdo->prepare(<<<SQL
INSERT INTO sync_status (bulk_type, scryfall_updated_at, imported_at, source_url, card_count)
VALUES (:bulk_type, :updated_at, now(), :source_url, :card_count)
ON CONFLICT (bulk_type) DO UPDATE SET
  scryfall_updated_at = EXCLUDED.scryfall_updated_at,
  imported_at = now(),
  source_url = EXCLUDED.source_url,
  card_count = EXCLUDED.card_count
SQL);
$status->execute([
    ':bulk_type' => $bulkType,
    ':updated_at' => $entry['updated_at'] ?? null,
    ':source_url' => $url,
    ':card_count' => $count,
]);
syncProgress(['state' => 'completed', 'imported' => $count, 'added' => $added, 'import_percent' => 100, 'finished_at' => time()]);
flock($syncLock, LOCK_UN);
