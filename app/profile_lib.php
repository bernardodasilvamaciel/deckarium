<?php
declare(strict_types=1);

/**
 * Perfil público do usuário: nome de exibição, bio, local, site, cores favoritas, foto e capa.
 * Todo usuário ativo tem perfil público; decks e coleção continuam seguindo a escolha de cada um (is_public / collection_public).
 * Imagens enviadas ficam em STORAGE_DIR/profiles/<id>/ e são servidas por profile_image.php, sem metadados (EXIF, GPS, XMP).
 */
const PROFILE_IMAGE_MAX_BYTES = 2 * 1024 * 1024;
const PROFILE_BIO_MAX = 600;

function profileSchema(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        if (db()->query("SELECT 1 FROM app_migrations WHERE name='profile_v1'")->fetchColumn()) return;
    } catch (PDOException) {
    }
    db()->exec("CREATE TABLE IF NOT EXISTS app_migrations (name text PRIMARY KEY, applied_at timestamptz NOT NULL DEFAULT now());
        ALTER TABLE users ADD COLUMN IF NOT EXISTS collection_public boolean NOT NULL DEFAULT false;
        ALTER TABLE users ADD COLUMN IF NOT EXISTS display_name text NOT NULL DEFAULT '';
        ALTER TABLE users ADD COLUMN IF NOT EXISTS bio text NOT NULL DEFAULT '';
        ALTER TABLE users ADD COLUMN IF NOT EXISTS location text NOT NULL DEFAULT '';
        ALTER TABLE users ADD COLUMN IF NOT EXISTS website text NOT NULL DEFAULT '';
        ALTER TABLE users ADD COLUMN IF NOT EXISTS favorite_colors jsonb NOT NULL DEFAULT '[]'::jsonb;
        ALTER TABLE users ADD COLUMN IF NOT EXISTS avatar_file text NULL;
        ALTER TABLE users ADD COLUMN IF NOT EXISTS cover_file text NULL;
        ALTER TABLE users ADD COLUMN IF NOT EXISTS cover_card_id uuid NULL;
        ALTER TABLE users ADD COLUMN IF NOT EXISTS cover_position smallint NOT NULL DEFAULT 50;
        INSERT INTO app_migrations(name) VALUES ('profile_v1') ON CONFLICT DO NOTHING;");
}

/** Nome mostrado publicamente: o nome de exibição escolhido, senão @usuário. O nome completo nunca é público. */
function profileName(array $user): string
{
    $display = trim((string)($user['display_name'] ?? ''));
    return $display !== '' ? $display : '@' . $user['username'];
}

function profileInitials(array $user): string
{
    $source = trim((string)($user['display_name'] ?? '')) ?: (string)$user['username'];
    $parts = preg_split('/\s+/', $source) ?: [];
    $initials = '';
    foreach (array_slice($parts, 0, 2) as $part) $initials .= mb_strtoupper(mb_substr($part, 0, 1));
    return $initials ?: '?';
}

function profileAvatarUrl(array $user): ?string
{
    return !empty($user['avatar_file']) ? '/profile_image.php?u=' . (int)$user['id'] . '&kind=avatar&v=' . rawurlencode((string)$user['avatar_file']) : null;
}

/** Capa: imagem enviada, senão a ilustração (art_crop) da carta escolhida. */
function profileCoverUrl(array $user): ?string
{
    if (!empty($user['cover_file'])) return '/profile_image.php?u=' . (int)$user['id'] . '&kind=cover&v=' . rawurlencode((string)$user['cover_file']);
    if (!empty($user['cover_card_id'])) {
        $art = db()->prepare("SELECT COALESCE(raw->'image_uris'->>'art_crop', raw->'card_faces'->0->'image_uris'->>'art_crop') FROM cards WHERE id=?");
        $art->execute([$user['cover_card_id']]);
        return ($url = $art->fetchColumn()) ? (string)$url : null;
    }
    return null;
}

function profileDirectory(int $userId): string
{
    $config = require __DIR__ . '/config.php';
    return rtrim((string)$config['storage_dir'], '/') . '/profiles/' . $userId;
}

/** Valida a imagem enviada (JPEG, PNG ou WebP, até 2 MB e 6000 px), remove metadados e grava. Devolve o nome do arquivo. */
function profileStoreImage(array $upload, int $userId, string $kind): string
{
    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_INI_SIZE || ($upload['error'] ?? 0) === UPLOAD_ERR_FORM_SIZE) throw new RuntimeException('A imagem passa de 2 MB.');
    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$upload['tmp_name'])) throw new RuntimeException('Não foi possível receber a imagem. Tente de novo.');
    if ((int)$upload['size'] > PROFILE_IMAGE_MAX_BYTES) throw new RuntimeException('A imagem passa de 2 MB.');
    $info = @getimagesize((string)$upload['tmp_name']);
    $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
    if (!$info || !isset($types[$info[2]])) throw new RuntimeException('Envie uma imagem JPEG, PNG ou WebP.');
    if ($info[0] < 64 || $info[1] < 64 || $info[0] > 6000 || $info[1] > 6000) throw new RuntimeException('A imagem precisa ter entre 64 e 6000 pixels de lado.');
    $bytes = (string)file_get_contents((string)$upload['tmp_name']);
    $clean = match ($info[2]) { IMAGETYPE_JPEG => profileStripJpeg($bytes), IMAGETYPE_PNG => profileStripPng($bytes), default => profileStripWebp($bytes) };
    if ($clean === null) throw new RuntimeException('A imagem parece corrompida. Tente outro arquivo.');
    $directory = profileDirectory($userId);
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) throw new RuntimeException('Não foi possível salvar a imagem agora.');
    $file = $kind . '-' . bin2hex(random_bytes(6)) . '.' . $types[$info[2]];
    if (file_put_contents($directory . '/' . $file, $clean) === false) throw new RuntimeException('Não foi possível salvar a imagem agora.');
    return $file;
}

function profileDeleteImage(int $userId, ?string $file): void
{
    if ($file && preg_match('/^(avatar|cover)-[a-f0-9]{12}\.(jpg|png|webp)$/', $file)) @unlink(profileDirectory($userId) . '/' . $file);
}

/** JPEG sem segmentos APP1 (EXIF/XMP, onde ficam GPS e dados da câmera) nem comentários. */
function profileStripJpeg(string $data): ?string
{
    if (substr($data, 0, 2) !== "\xFF\xD8") return null;
    $out = "\xFF\xD8";
    $position = 2;
    $length = strlen($data);
    while ($position + 4 <= $length) {
        if ($data[$position] !== "\xFF") return null;
        $marker = ord($data[$position + 1]);
        if ($marker === 0xDA) return $out . substr($data, $position); // início dos dados da imagem
        $size = unpack('n', substr($data, $position + 2, 2))[1];
        if ($size < 2 || $position + 2 + $size > $length) return null;
        if ($marker !== 0xE1 && $marker !== 0xFE) $out .= substr($data, $position, 2 + $size);
        $position += 2 + $size;
    }
    return null;
}

/** PNG sem blocos de texto e EXIF (tEXt, zTXt, iTXt, eXIf). */
function profileStripPng(string $data): ?string
{
    if (substr($data, 0, 8) !== "\x89PNG\r\n\x1a\n") return null;
    $out = substr($data, 0, 8);
    $position = 8;
    $length = strlen($data);
    while ($position + 12 <= $length) {
        $size = unpack('N', substr($data, $position, 4))[1];
        $type = substr($data, $position + 4, 4);
        if ($position + 12 + $size > $length) return null;
        if (!in_array($type, ['tEXt', 'zTXt', 'iTXt', 'eXIf'], true)) $out .= substr($data, $position, 12 + $size);
        $position += 12 + $size;
        if ($type === 'IEND') return $out;
    }
    return null;
}

/** WebP sem os blocos EXIF e XMP (e sem os sinalizadores deles no cabeçalho VP8X). */
function profileStripWebp(string $data): ?string
{
    if (substr($data, 0, 4) !== 'RIFF' || substr($data, 8, 4) !== 'WEBP') return null;
    $body = '';
    $position = 12;
    $length = strlen($data);
    while ($position + 8 <= $length) {
        $type = substr($data, $position, 4);
        $size = unpack('V', substr($data, $position + 4, 4))[1];
        $chunk = substr($data, $position, 8 + $size + ($size % 2));
        if (strlen($chunk) < 8 + $size) return null;
        if ($type === 'VP8X') $chunk[8] = chr(ord($chunk[8]) & ~0x0C);
        if ($type !== 'EXIF' && $type !== 'XMP ') $body .= $chunk;
        $position += 8 + $size + ($size % 2);
    }
    return 'RIFF' . pack('V', strlen($body) + 4) . 'WEBP' . $body;
}

/** Valida os campos de texto do perfil. Devolve [dados, erros]. */
function profileValidate(array $post): array
{
    $text = fn(string $key, int $max) => mb_substr(trim(preg_replace('/[\x00-\x09\x0B-\x1F\x7F]/u', '', (string)($post[$key] ?? '')) ?? ''), 0, $max);
    $data = [
        'display_name' => $text('display_name', 60),
        'bio' => str_replace("\r\n", "\n", $text('bio', PROFILE_BIO_MAX)),
        'location' => $text('location', 80),
        'website' => $text('website', 200),
        'favorite_colors' => array_values(array_intersect(['W', 'U', 'B', 'R', 'G', 'C'], array_map('strval', array_filter((array)($post['favorite_colors'] ?? []), 'is_scalar')))),
        'cover_position' => max(0, min(100, (int)($post['cover_position'] ?? 50))),
    ];
    $errors = [];
    if ($data['website'] !== '') {
        if (!preg_match('~^https?://~i', $data['website'])) $data['website'] = 'https://' . $data['website'];
        if (!filter_var($data['website'], FILTER_VALIDATE_URL) || !preg_match('~^https?://[^/\s]+\.[^/\s]+~i', $data['website'])) $errors['website'] = 'Informe um endereço válido, como https://exemplo.com.';
    }
    return [$data, $errors];
}

/** Carta usada como capa: aceita o nome exato (inglês); prefere uma impressão com ilustração em alta. */
function profileFindCoverCard(string $name): ?array
{
    $stmt = db()->prepare("SELECT id,name FROM cards WHERE lower(name)=lower(?) AND COALESCE(raw->'image_uris'->>'art_crop', raw->'card_faces'->0->'image_uris'->>'art_crop') IS NOT NULL
        ORDER BY (lang='en') DESC, (raw->>'full_art')::boolean DESC NULLS LAST, released_at DESC NULLS LAST LIMIT 1");
    $stmt->execute([trim($name)]);
    return $stmt->fetch() ?: null;
}
