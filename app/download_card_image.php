<?php
declare(strict_types=1);

require __DIR__ . '/scryfall_local.php';
require __DIR__ . '/auth.php';
authRequireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Método não permitido.');
}

$name = trim((string)($_POST['card_name'] ?? ''));
$id = trim((string)($_POST['card_id'] ?? '')) ?: null;
$deck = trim((string)($_POST['deck'] ?? ''));

$return = '/upgrades.php';
if ($deck !== '') $return .= '?deck=' . rawurlencode($deck);
$separator = str_contains($return, '?') ? '&' : '?';

if ($name === '' && !$id) {
    header('Location: ' . $return . $separator . 'img_status=error&img_message=' . rawurlencode('Carta não informada.'));
    exit;
}

try {
    $card = findOrFetchCard($name, $id);
    // A imagem pequena será cacheada automaticamente por image.php quando o navegador a exibir.
    $message = "{$card['name']} disponível no banco. A imagem será cacheada automaticamente.";
    header('Location: ' . $return . $separator . 'img_status=ok&img_message=' . rawurlencode($message));
} catch (Throwable $e) {
    header('Location: ' . $return . $separator . 'img_status=error&img_message=' . rawurlencode($e->getMessage()));
}
exit;
