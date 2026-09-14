<?php
declare(strict_types=1);
require __DIR__ . '/functions.php';
require __DIR__ . '/partials.php';
require __DIR__ . '/deck_library.php';
deckSchema();
require __DIR__ . '/card_filters.php';
$f=cardFilters();
$q = substr(trim((string)($_GET['q'] ?? '')), 0, 200);
$oracle = substr(trim((string)($_GET['oracle'] ?? '')), 0, 200);
$page = max(1, (int)($_GET['page'] ?? 1));
[$whereSql,$params]=cardFilterSql($f);
$from = ' FROM builder_collection o JOIN cards c ON c.id=o.scryfall_id ' . $whereSql;
$count = (int)deckQuery('SELECT COUNT(*)'.$from, $params)->fetchColumn();
$pages = max(1, (int)ceil($count / 36)); $page = min($page, $pages); $offset = ($page-1)*36;
$cards = deckQuery('SELECT c.*,o.quantity'.$from." ORDER BY c.name,c.set_code,c.collector_number,c.id LIMIT 36 OFFSET {$offset}", $params)->fetchAll();
$summary = deckQuery('SELECT COALESCE(SUM(quantity),0) total,COUNT(*) printings FROM builder_collection')->fetch();
pageHeader('Minha coleção');
?>
<section class="hero"><div><h1>Minha coleção</h1><p><?= number_format((int)$summary['total'],0,',','.') ?> cartas em <?= number_format((int)$summary['printings'],0,',','.') ?> impressões. Veja as versões e quantidades que você importou.</p></div><a href="/decks.php">Importar coleção ou construir deck</a></section>
<?php cardFilterForm($f,deckQuery('SELECT c.set_code,MAX(c.set_name) set_name FROM builder_collection o JOIN cards c ON c.id=o.scryfall_id GROUP BY c.set_code ORDER BY MAX(c.set_name)')->fetchAll(),'/collection.php'); ?>
<p class="muted"><?= number_format($count,0,',','.') ?> impressões encontradas. <a href="/collection.php">Limpar filtros</a></p>
<?php if (!$cards): ?><p class="empty-state"><?= $summary['printings'] ? 'Nenhuma carta corresponde à busca. Altere os termos ou limpe os filtros.' : 'Sua coleção ainda está vazia. Importe seu CSV em Meus decks para ver as cartas aqui.' ?></p><?php endif; ?>
<div class="grid"><?php foreach ($cards as $card): ?><div><?php cardTile($card); ?><p class="collection-quantity"><?= (int)$card['quantity'] ?> cópia(s) na coleção · <?= h(strtoupper($card['lang'])) ?></p></div><?php endforeach; ?></div>
<?php numberedPager($page,$pages,$f); ?>
<?php pageFooter(); ?>
