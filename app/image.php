<?php
declare(strict_types=1);

require __DIR__ . '/db.php';
require __DIR__ . '/scryfall_local.php';

$id = trim((string)($_GET['id'] ?? ''));
$face = (string)($_GET['face'] ?? 'front');
$size = (string)($_GET['size'] ?? 'normal');
$face = $face === 'back' ? 'back' : 'front';
$size = in_array($size, ['small', 'normal'], true) ? $size : 'small';

if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i', $id)) {
    http_response_code(400);
    exit('ID não informado');
}

// Serve the deterministic cache before opening a database connection.
$config = appConfig();
$cached = $config['storage_dir'] . '/images/' . $size . '/' . $id . '-' . $face . '.jpg';
if (is_file($cached) && filesize($cached) > 0) {
    header('Content-Type: image/jpeg');
    header('Cache-Control: public, max-age=31536000, immutable');
    header('Content-Length: ' . filesize($cached));
    readfile($cached);
    exit;
}

$stmt = db()->prepare('SELECT * FROM cards WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $id]);
$card = $stmt->fetch();
if (!$card) {
    http_response_code(404);
    exit('Carta não encontrada');
}

$config = appConfig();
$storage = $config['storage_dir'];

function decodedRawCard(array $card): array
{
    $raw = $card['raw'] ?? null;
    if (is_array($raw)) return $raw;
    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) return $decoded;
    }
    return [];
}

function remoteUriForVariant(array $card, string $face, string $size): ?string
{
    $raw = decodedRawCard($card);
    $sizeKey = $size === 'small' ? 'small' : 'normal';

    if ($face === 'front') {
        $uri = $raw['image_uris'][$sizeKey] ?? null;
        if (!$uri && !empty($raw['card_faces'][0]['image_uris'][$sizeKey])) {
            $uri = $raw['card_faces'][0]['image_uris'][$sizeKey];
        }
        if (!$uri) {
            $uri = $card['image_uri'] ?? null;
        }
        return $uri ?: null;
    }

    $uri = $raw['card_faces'][1]['image_uris'][$sizeKey] ?? null;
    if (!$uri) {
        $uri = $card['image_uri_back'] ?? null;
    }
    return $uri ?: null;
}

$existingRel = $face === 'back' ? ($card['local_image_back'] ?? null) : ($card['local_image'] ?? null);
$full = null;

// Para a imagem normal, aproveita o caminho antigo já registrado no banco.
if ($size === 'normal' && $existingRel) {
    $candidate = $storage . '/' . ltrim((string)$existingRel, '/');
    if (is_file($candidate) && filesize($candidate) > 0) {
        $full = $candidate;
    }
}

if (!$full) {
    $subdir = $size === 'small' ? 'images/small' : 'images/normal';
    $suffix = $face === 'back' ? 'back' : 'front';
    $rel = $subdir . '/' . $card['id'] . '-' . $suffix . '.jpg';
    $full = $storage . '/' . $rel;

    if (!is_file($full) || filesize($full) === 0) {
        $remote = remoteUriForVariant($card, $face, $size);
        if (!$remote) {
            http_response_code(404);
            exit('Esta impressão não possui imagem disponível');
        }
        // Bulk downloads populate the local cache. Do not occupy an Apache
        // worker waiting on a remote image during interactive browsing.
        if (parse_url($remote, PHP_URL_SCHEME) !== 'https' || parse_url($remote, PHP_URL_HOST) !== 'cards.scryfall.io') {
            http_response_code(404);
            exit('Origem de imagem indisponível');
        }
        header('Cache-Control: no-store');
        header('Location: ' . $remote, true, 302);
        exit;
    }

    // Apenas imagens normais são gravadas nos campos legados do banco.
    // Thumbnails pequenos usam caminho determinístico e não precisam de coluna própria.
    if ($size === 'normal') {
        $column = $face === 'back' ? 'local_image_back' : 'local_image';
        $update = db()->prepare("UPDATE cards SET {$column} = :path WHERE id = :id");
        $update->execute([':path' => $rel, ':id' => $card['id']]);
    }
}

if (!$full || !is_file($full)) {
    http_response_code(404);
    exit('Imagem não encontrada');
}

$mime = mime_content_type($full) ?: 'image/jpeg';
header('Content-Type: ' . $mime);
header('Cache-Control: public, max-age=31536000, immutable');
header('Content-Length: ' . filesize($full));
readfile($full);
