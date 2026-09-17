<?php
declare(strict_types=1);
/**
 * Sincroniza as tags de função do Scryfall Tagger usadas pelas relações (tabela card_tags).
 *
 * Uso: php bin/sync_tagger.php [--search]
 *  - Primeiro procura um arquivo de tags no Bulk Data do Scryfall (oracle_tags);
 *  - se não existir (ou com --search), consulta a busca da API com otag:<tag>, respeitando o intervalo pedido pelo Scryfall.
 * Só as tags listadas em deckRelationTagMap() são guardadas.
 */
require __DIR__ . '/common.php';
require dirname(__DIR__) . '/deck_library.php';

set_exception_handler(static function (Throwable $e): void {
    fwrite(STDERR, "\nNão foi possível sincronizar as tags: {$e->getMessage()}\nAs tags já salvas foram mantidas. Verifique o acesso a api.scryfall.com e tente de novo.\n");
    exit(1);
});
$forceSearch = in_array('--search', $argv, true);
$wanted = array_keys(deckRelationTagMap());
$pdo = db();
$pdo->exec('CREATE TABLE IF NOT EXISTS card_tags (oracle_id uuid NOT NULL, tag text NOT NULL, PRIMARY KEY(oracle_id, tag)); CREATE INDEX IF NOT EXISTS card_tags_tag_idx ON card_tags(tag)');

/** Grava as cartas de uma tag substituindo o que havia antes. */
function saveTag(PDO $pdo, string $tag, array $oracleIds): void
{
    $oracleIds = array_values(array_unique(array_filter($oracleIds, fn($id) => is_string($id) && preg_match('/^[0-9a-f-]{36}$/i', $id))));
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM card_tags WHERE tag=?')->execute([$tag]);
        foreach (array_chunk($oracleIds, 2000) as $chunk) {
            $pdo->prepare('INSERT INTO card_tags(oracle_id, tag) SELECT unnest(?::uuid[]), ? ON CONFLICT DO NOTHING')->execute(['{' . implode(',', $chunk) . '}', $tag]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

$found = [];
if (!$forceSearch) {
    try {
        $manifest = json_decode(httpGet('https://api.scryfall.com/bulk-data'), true, flags: JSON_THROW_ON_ERROR);
        $entry = null;
        foreach ((array)($manifest['data'] ?? []) as $candidate) {
            if (in_array($candidate['type'] ?? '', ['oracle_tags', 'oracle_tag', 'tags'], true)) { $entry = $candidate; break; }
        }
        $url = $entry['jsonl_download_uri'] ?? $entry['download_uri'] ?? null;
        if ($url) {
            echo "Baixando tags do Bulk Data ({$entry['type']})...\n";
            $target = rtrim(cfg()['storage_dir'], '/') . '/bulk/oracle_tags' . (str_contains($url, '.jsonl.gz') ? '.jsonl.gz' : '.json');
            @mkdir(dirname($target), 0775, true);
            downloadFile($url, $target);
            $raw = file_get_contents(str_ends_with($target, '.gz') ? 'compress.zlib://' . $target : $target);
            $records = [];
            $decoded = json_decode((string)$raw, true);
            if (is_array($decoded)) $records = isset($decoded['data']) ? (array)$decoded['data'] : $decoded;
            else foreach (preg_split('/\r?\n/', (string)$raw) ?: [] as $line) if (trim($line) !== '' && is_array($row = json_decode($line, true))) $records[] = $row;
            foreach ($records as $record) {
                if (!is_array($record)) continue;
                $slug = strtolower((string)($record['label'] ?? $record['slug'] ?? $record['name'] ?? ''));
                if (!in_array($slug, $wanted, true)) continue;
                $ids = (array)($record['oracle_ids'] ?? []);
                foreach ((array)($record['taggings'] ?? $record['cards'] ?? []) as $item) if (is_array($item) && isset($item['oracle_id'])) $ids[] = $item['oracle_id'];
                if ($ids) $found[$slug] = array_merge($found[$slug] ?? [], $ids);
            }
            echo count($found) . " tags encontradas no arquivo.\n";
        }
    } catch (Throwable $e) {
        echo "Bulk Data de tags indisponível: {$e->getMessage()}\n";
    }
}

$missing = array_values(array_diff($wanted, array_keys($found)));
if ($missing) echo 'Consultando a busca do Scryfall para ' . count($missing) . " tags (otag:)...\n";
$empty = [];
foreach ($missing as $tag) {
    $ids = [];
    $url = 'https://api.scryfall.com/cards/search?' . http_build_query(['q' => 'otag:' . $tag, 'unique' => 'cards', 'format' => 'json']);
    while ($url) {
        usleep(120000); // O Scryfall pede 50–100 ms entre requisições.
        try {
            $page = json_decode(httpGet($url), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            if (str_contains($e->getMessage(), 'HTTP 404')) break; // tag sem cartas (ou inexistente)
            if (str_contains($e->getMessage(), 'HTTP 429')) { sleep(5); continue; }
            throw $e;
        }
        foreach ((array)($page['data'] ?? []) as $card) if (!empty($card['oracle_id'])) $ids[] = $card['oracle_id'];
        $url = !empty($page['has_more']) ? (string)($page['next_page'] ?? '') : '';
    }
    if ($ids) $found[$tag] = $ids; else $empty[] = $tag;
    echo str_pad($tag, 28) . count($ids) . " cartas\n";
}

$total = 0;
foreach ($found as $tag => $ids) { saveTag($pdo, $tag, $ids); $total += count(array_unique($ids)); }
echo "\nConcluído: " . count($found) . " tags, {$total} ligações carta-tag salvas.\n";
if ($empty) echo 'Sem cartas no Scryfall: ' . implode(', ', $empty) . "\n";
