<?php
declare(strict_types=1);
require __DIR__ . '/common.php';

$config = cfg();
$bulkType = $argv[1] ?? $config['scryfall']['bulk_type'];
$allowed = ['oracle_cards', 'default_cards', 'all_cards', 'unique_artwork'];
if (!in_array($bulkType, $allowed, true)) {
    fwrite(STDERR, "Bulk inválido. Use: " . implode(', ', $allowed) . PHP_EOL);
    exit(1);
}

$storage = $config['storage_dir'];
$bulkDir = $storage . '/bulk';
@mkdir($bulkDir, 0775, true);

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
downloadFile($url, $target);
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
SQL);

function importCard(PDOStatement $upsert, array $card): void
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
}

$count = 0;
$pdo->beginTransaction();
try {
    if ($isGzipJsonl) {
        $fh = gzopen($target, 'rb');
        if (!$fh) throw new RuntimeException('Falha ao abrir JSONL gzipado.');
        while (!gzeof($fh)) {
            $line = trim((string)gzgets($fh));
            if ($line === '') continue;
            $card = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            importCard($upsert, $card);
            $count++;
            if ($count % 1000 === 0) {
                $pdo->commit();
                echo "Importadas {$count} cartas...\r";
                $pdo->beginTransaction();
            }
        }
        gzclose($fh);
    } else {
        // Compatibilidade com o formato JSON-array antigo.
        $raw = file_get_contents($target);
        if ($raw === false) throw new RuntimeException('Falha ao ler bulk JSON.');
        $cards = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        foreach ($cards as $card) {
            importCard($upsert, $card);
            $count++;
            if ($count % 1000 === 0) {
                $pdo->commit();
                echo "Importadas {$count} cartas...\r";
                $pdo->beginTransaction();
            }
        }
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
}

echo "\nImportação concluída: {$count} registros.\n";
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
