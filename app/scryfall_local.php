<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * Funções compartilhadas para localizar cartas, consultar o Scryfall sob demanda
 * e salvar imagens no armazenamento local.
 */

function appConfig(): array
{
    static $config = null;
    if ($config === null) {
        $config = require __DIR__ . '/config.php';
    }
    return $config;
}

function scryfallGetJson(string $url): array
{
    $config = appConfig();
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('Não foi possível inicializar o cURL.');
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => [
            'User-Agent: ' . $config['scryfall']['user_agent'],
            'Accept: application/json',
        ],
    ]);

    $body = curl_exec($ch);
    $error = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($body === false) {
        throw new RuntimeException('Falha ao consultar o Scryfall: ' . $error);
    }

    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Resposta inválida recebida do Scryfall.');
    }

    if ($code < 200 || $code >= 300 || (($decoded['object'] ?? '') === 'error')) {
        $details = (string)($decoded['details'] ?? "HTTP {$code}");
        throw new RuntimeException('Scryfall: ' . $details);
    }

    return $decoded;
}

function scryfallImageUris(array $card): array
{
    $front = $card['image_uris']['normal'] ?? $card['image_uris']['large'] ?? null;
    $back = null;

    if ((!$front || empty($card['image_uris'])) && !empty($card['card_faces']) && is_array($card['card_faces'])) {
        $front = $card['card_faces'][0]['image_uris']['normal']
            ?? $card['card_faces'][0]['image_uris']['large']
            ?? null;
        $back = $card['card_faces'][1]['image_uris']['normal']
            ?? $card['card_faces'][1]['image_uris']['large']
            ?? null;
    } elseif (!empty($card['card_faces']) && is_array($card['card_faces'])) {
        $back = $card['card_faces'][1]['image_uris']['normal']
            ?? $card['card_faces'][1]['image_uris']['large']
            ?? null;
    }

    return [$front, $back];
}

function findCardByName(string $name): ?array
{
    $stmt = db()->prepare(<<<SQL
SELECT *
FROM cards
WHERE lower(name) = lower(:name)
   OR lower(split_part(name, ' // ', 1)) = lower(:name)
ORDER BY
    (lang = 'en') DESC,
    (COALESCE(raw->>'digital','false') = 'false') DESC,
    (local_image IS NOT NULL) DESC,
    (image_uri IS NOT NULL) DESC,
    released_at DESC NULLS LAST
LIMIT 1
SQL);
    $stmt->execute([':name' => trim($name)]);
    $card = $stmt->fetch();
    return $card ?: null;
}

function findCardById(string $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM cards WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $card = $stmt->fetch();
    return $card ?: null;
}

function prepareCardUpsert(PDO $pdo): PDOStatement
{
    return $pdo->prepare(<<<SQL
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
}

function upsertScryfallCard(array $card): array
{
    if (empty($card['id']) || empty($card['name'])) {
        throw new RuntimeException('O Scryfall não retornou uma carta válida.');
    }

    [$front, $back] = scryfallImageUris($card);
    $stmt = prepareCardUpsert(db());
    $stmt->execute([
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

    $saved = findCardById((string)$card['id']);
    if (!$saved) {
        throw new RuntimeException('A carta foi recebida, mas não pôde ser salva no banco.');
    }
    return $saved;
}

function fetchCardFromScryfallByName(string $name): array
{
    $url = 'https://api.scryfall.com/cards/named?exact=' . rawurlencode(trim($name));
    return upsertScryfallCard(scryfallGetJson($url));
}

function findOrFetchCard(string $name, ?string $id = null): array
{
    if ($id) {
        $card = findCardById($id);
        if ($card) {
            return $card;
        }
    }

    $card = findCardByName($name);
    if ($card) {
        return $card;
    }

    return fetchCardFromScryfallByName($name);
}

function downloadRemoteImage(string $url, string $target): void
{
    $config = appConfig();
    $dir = dirname($target);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Não foi possível criar o diretório de imagens.');
    }

    $tmp = $target . '.part-' . bin2hex(random_bytes(4));
    $fp = fopen($tmp, 'wb');
    if (!$fp) {
        throw new RuntimeException('Não foi possível criar o arquivo temporário da imagem.');
    }

    $ch = curl_init($url);
    if ($ch === false) {
        fclose($fp);
        @unlink($tmp);
        throw new RuntimeException('Não foi possível inicializar o download da imagem.');
    }

    curl_setopt_array($ch, [
        CURLOPT_FILE => $fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_HTTPHEADER => [
            'User-Agent: ' . $config['scryfall']['user_agent'],
            'Accept: image/avif,image/webp,image/apng,image/*,*/*;q=0.8',
        ],
    ]);

    $ok = curl_exec($ch);
    $error = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    fclose($fp);

    if (!$ok || $code < 200 || $code >= 300 || !is_file($tmp) || filesize($tmp) === 0) {
        @unlink($tmp);
        throw new RuntimeException("Falha ao baixar a imagem (HTTP {$code}). {$error}");
    }

    if (!rename($tmp, $target)) {
        @unlink($tmp);
        throw new RuntimeException('Não foi possível mover a imagem para o armazenamento local.');
    }
}

/**
 * Garante que frente/verso da carta estejam no disco.
 * Retorna quantas imagens novas foram baixadas (0, 1 ou 2).
 */
function ensureCardImages(array $card): int
{
    $config = appConfig();
    $storage = $config['storage_dir'];
    $imageDir = $storage . '/images';
    if (!is_dir($imageDir)) {
        @mkdir($imageDir, 0775, true);
    }

    $downloaded = 0;
    $frontRel = $card['local_image'] ?: null;
    $backRel = $card['local_image_back'] ?: null;

    if (!$frontRel && !empty($card['image_uri'])) {
        $frontRel = 'images/' . $card['id'] . '-front.jpg';
        downloadRemoteImage((string)$card['image_uri'], $storage . '/' . $frontRel);
        $downloaded++;
    }

    if (!$backRel && !empty($card['image_uri_back'])) {
        $backRel = 'images/' . $card['id'] . '-back.jpg';
        downloadRemoteImage((string)$card['image_uri_back'], $storage . '/' . $backRel);
        $downloaded++;
    }

    // Se o banco possui caminho, mas o arquivo foi apagado manualmente, baixa novamente.
    if ($frontRel && !is_file($storage . '/' . ltrim((string)$frontRel, '/')) && !empty($card['image_uri'])) {
        downloadRemoteImage((string)$card['image_uri'], $storage . '/' . ltrim((string)$frontRel, '/'));
        $downloaded++;
    }

    if ($backRel && !is_file($storage . '/' . ltrim((string)$backRel, '/')) && !empty($card['image_uri_back'])) {
        downloadRemoteImage((string)$card['image_uri_back'], $storage . '/' . ltrim((string)$backRel, '/'));
        $downloaded++;
    }

    if (!$frontRel && empty($card['image_uri'])) {
        throw new RuntimeException('Esta impressão não possui URI de imagem no banco/Scryfall.');
    }

    $stmt = db()->prepare(<<<SQL
UPDATE cards
SET local_image = :front,
    local_image_back = :back
WHERE id = :id
SQL);
    $stmt->execute([
        ':front' => $frontRel,
        ':back' => $backRel,
        ':id' => $card['id'],
    ]);

    return $downloaded;
}
