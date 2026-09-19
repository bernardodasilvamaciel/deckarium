<?php
declare(strict_types=1);
/** Foto e capa do perfil (públicas, como o perfil). O nome do arquivo muda a cada envio, então o cache pode ser longo. */
require __DIR__ . '/db.php';
require __DIR__ . '/profile_lib.php';
profileSchema();

$userId = max(0, (int)($_GET['u'] ?? 0));
$kind = ($_GET['kind'] ?? '') === 'cover' ? 'cover' : 'avatar';
$stmt = db()->prepare("SELECT {$kind}_file FROM users WHERE id=? AND is_active");
$stmt->execute([$userId]);
$file = (string)$stmt->fetchColumn();
$path = profileDirectory($userId) . '/' . $file;
if (!preg_match('/^(avatar|cover)-[a-f0-9]{12}\.(jpg|png|webp)$/', $file, $match) || !is_file($path)) {
    http_response_code(404);
    exit;
}
header('Content-Type: ' . ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][$match[2]]);
header('Content-Length: ' . filesize($path));
header('Cache-Control: public, max-age=2592000, immutable');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'");
readfile($path);
