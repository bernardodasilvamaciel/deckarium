<?php
declare(strict_types=1);

/**
 * Sitemap do Deckarium (servido em /sitemap.xml).
 *
 * Só entra o que qualquer visitante vê sem login: início, catálogo, edições,
 * comandantes mais jogados e o conteúdo que cada dono marcou como público.
 * Páginas de conta e de administração ficam de fora, como no robots.txt.
 */

require __DIR__ . '/db.php';
require __DIR__ . '/catalog_cache.php';

// O cache guarda só os caminhos: o domínio entra na hora de escrever o XML.
$scheme = (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'http') ? 'http' : 'https';
$base = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'deckarium.bernas.shop');

$urls = catalogCached('sitemap-v2', function (): array {
    $urls = [
        ['loc' => '/', 'changefreq' => 'daily', 'priority' => '1.0'],
        ['loc' => '/commanders.php', 'changefreq' => 'weekly', 'priority' => '0.9'],
        ['loc' => '/editions.php', 'changefreq' => 'weekly', 'priority' => '0.9'],
        ['loc' => '/public.php', 'changefreq' => 'daily', 'priority' => '0.7'],
    ];

    foreach (db()->query("SELECT set_code, MAX(released_at) AS released_at FROM cards GROUP BY set_code ORDER BY MAX(released_at) DESC NULLS LAST")->fetchAll() as $set) {
        $urls[] = ['loc' => '/edition.php?set=' . rawurlencode((string)$set['set_code']),
            'lastmod' => $set['released_at'] ? substr((string)$set['released_at'], 0, 10) : null, 'changefreq' => 'monthly', 'priority' => '0.7'];
    }

    // Comandantes mais jogados: as páginas de carta com mais procura. As demais são
    // encontradas pelos links das edições, sem inchar o arquivo com 118 mil endereços.
    $commanders = db()->query("SELECT DISTINCT ON (COALESCE(oracle_id,id)) id FROM cards
        WHERE type_line ILIKE 'Legendary Creature%' AND lang='en' AND edhrec_rank_cached IS NOT NULL
        ORDER BY COALESCE(oracle_id,id), edhrec_rank_cached LIMIT 2000")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($commanders as $commanderId) $urls[] = ['loc' => '/card.php?id=' . rawurlencode((string)$commanderId), 'changefreq' => 'weekly', 'priority' => '0.5'];

    return $urls;
}, 86400);

// Conteúdo da comunidade fora do cache: muda quando um dono publica ou despublica.
try {
    foreach (db()->query("SELECT d.id FROM builder_decks d JOIN users u ON u.id=d.user_id WHERE d.is_public AND u.is_active ORDER BY d.id DESC LIMIT 5000")->fetchAll(PDO::FETCH_COLUMN) as $deckId) {
        $urls[] = ['loc' => '/public_deck.php?id=' . (int)$deckId, 'changefreq' => 'weekly', 'priority' => '0.6'];
    }
    foreach (db()->query("SELECT username, collection_public FROM users WHERE is_active ORDER BY id LIMIT 5000")->fetchAll() as $player) {
        $urls[] = ['loc' => '/profile.php?u=' . rawurlencode((string)$player['username']), 'changefreq' => 'weekly', 'priority' => '0.5'];
        if ($player['collection_public']) $urls[] = ['loc' => '/public_collection.php?u=' . rawurlencode((string)$player['username']), 'changefreq' => 'weekly', 'priority' => '0.4'];
    }
} catch (Throwable $e) {
    // Instalação nova, ainda sem as tabelas da comunidade: o sitemap sai só com o catálogo.
    error_log('Sitemap: ' . $e->getMessage());
}

header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=3600');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($urls as $url) {
    echo '  <url><loc>' . htmlspecialchars($base . $url['loc'], ENT_XML1) . '</loc>';
    if (!empty($url['lastmod'])) echo '<lastmod>' . htmlspecialchars($url['lastmod'], ENT_XML1) . '</lastmod>';
    if (!empty($url['changefreq'])) echo '<changefreq>' . $url['changefreq'] . '</changefreq>';
    if (!empty($url['priority'])) echo '<priority>' . $url['priority'] . '</priority>';
    echo '</url>' . "\n";
}
echo '</urlset>' . "\n";
