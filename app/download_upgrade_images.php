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

$deck = trim((string)($_POST['deck'] ?? ''));
$params = [];
$where = '';
if ($deck !== '') {
    $where = 'WHERE deck_slug = :deck';
    $params[':deck'] = $deck;
}

$stmt = db()->prepare(<<<SQL
SELECT wanted_name
FROM (
    SELECT add_name AS wanted_name, deck_slug FROM upgrade_items
    UNION
    SELECT remove_name AS wanted_name, deck_slug FROM upgrade_items
) x
{$where}
ORDER BY wanted_name
SQL);
$stmt->execute($params);
$names = array_values(array_unique(array_map(
    static fn(array $row): string => (string)$row['wanted_name'],
    $stmt->fetchAll()
)));

$processed = 0;
$fetched = 0;
$errors = [];
foreach ($names as $name) {
    try {
        $before = findCardByName($name);
        if (!$before) {
            fetchCardFromScryfallByName($name);
            $fetched++;
            // Somente chamadas à API precisam ser espaçadas.
            usleep(120000);
        }
        $processed++;
    } catch (Throwable $e) {
        $errors[] = $name . ': ' . $e->getMessage();
    }
}

$return = '/upgrades.php';
if ($deck !== '') $return .= '?deck=' . rawurlencode($deck);
$separator = str_contains($return, '?') ? '&' : '?';

if ($errors) {
    $summary = "Verificadas {$processed} cartas; {$fetched} buscadas no Scryfall; " . count($errors) . ' falhas. Primeira: ' . $errors[0];
    header('Location: ' . $return . $separator . 'img_status=warning&img_message=' . rawurlencode($summary));
} else {
    $summary = "Concluído: {$processed} cartas verificadas e {$fetched} adicionadas ao banco. As imagens serão cacheadas ao aparecer na tela.";
    header('Location: ' . $return . $separator . 'img_status=ok&img_message=' . rawurlencode($summary));
}
exit;
