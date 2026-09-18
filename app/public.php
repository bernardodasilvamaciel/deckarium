<?php
declare(strict_types=1);
/** Comunidade: decks e coleções que os usuários tornaram públicos. Aberta mesmo sem conta. */
require __DIR__ . '/functions.php';
require __DIR__ . '/partials.php';
require __DIR__ . '/deck_library.php';
deckSchema();
$viewer = authUser();
session_write_close();

$username = is_string($_GET['u'] ?? null) ? mb_substr(trim($_GET['u']), 0, 60) : '';
$query = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']), 0, 120) : '';
$where = 'd.is_public AND u.is_active';
$params = [];
if ($username !== '') { $where .= ' AND lower(u.username)=lower(?)'; $params[] = $username; }
if ($query !== '') {
    $where .= ' AND (d.name ILIKE ? OR c.name ILIKE ?)';
    $like = '%' . strtr($query, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']) . '%';
    array_push($params, $like, $like);
}
$decks = deckQuery("SELECT d.id,d.name,d.status,u.username,c.id commander_card_id,c.name commander,c.color_identity,
        (SELECT COALESCE(SUM(quantity),0) FROM builder_items WHERE deck_id=d.id AND stage='deck') + CASE WHEN d.commander_id IS NULL THEN 0 ELSE 1 END card_count
    FROM builder_decks d JOIN users u ON u.id=d.user_id LEFT JOIN cards c ON c.id=d.commander_id
    WHERE {$where} ORDER BY d.id DESC LIMIT 120", $params)->fetchAll();
$collectionWhere = 'u.collection_public AND u.is_active' . ($username !== '' ? ' AND lower(u.username)=lower(?)' : '');
$collections = deckQuery("SELECT u.username,COALESCE(SUM(o.quantity),0) total,COUNT(DISTINCT o.scryfall_id) printings
    FROM users u LEFT JOIN builder_collection o ON o.user_id=u.id WHERE {$collectionWhere}
    GROUP BY u.id,u.username ORDER BY total DESC LIMIT 60", $username !== '' ? [$username] : [])->fetchAll();

pageHeader($username !== '' ? '@' . $username . ' · Comunidade' : 'Comunidade');
?>
<div class="public-page">
<section class="hero"><div><h1><?= $username !== '' ? '@' . h($username) : 'Comunidade' ?></h1><p><?= $username !== '' ? 'Decks e coleção que este usuário tornou públicos.' : 'Decks e coleções que outros jogadores tornaram públicos. Para compartilhar os seus, use “Tornar público” no deck ou em Minha coleção.' ?></p></div><?php if ($username !== ''): ?><a class="text-link" href="/public.php">&larr; Toda a comunidade</a><?php endif; ?></section>

<form class="public-search" method="get" role="search"><?php if ($username !== ''): ?><input type="hidden" name="u" value="<?= h($username) ?>"><?php endif; ?><label class="field">Buscar decks<input type="search" name="q" value="<?= h($query) ?>" placeholder="Nome do deck ou da comandante"></label><button class="primary-link">Buscar</button></form>

<section aria-labelledby="public-decks-title">
    <div class="section-heading"><h2 id="public-decks-title">Decks públicos <span class="muted"><?= count($decks) ?></span></h2></div>
    <?php if (!$decks): ?><p class="empty-state"><?= $query !== '' ? 'Nenhum deck público encontrado.' : 'Ainda não há decks públicos.' ?></p><?php endif; ?>
    <div class="public-deck-grid">
    <?php foreach ($decks as $d): $identity = json_decode((string)($d['color_identity'] ?? '[]'), true) ?: []; ?>
        <a class="public-deck-tile" href="/public_deck.php?id=<?= (int)$d['id'] ?>">
            <span class="public-deck-tile-art"><?php if ($d['commander_card_id']): ?><img src="/image.php?id=<?= h(rawurlencode((string)$d['commander_card_id'])) ?>&amp;size=small" alt="" loading="lazy" width="146" height="204"><?php endif; ?></span>
            <span class="public-deck-tile-copy"><strong><?= h($d['name']) ?></strong><span><?= h($d['commander'] ?: 'Comandante a escolher') ?></span><small><?= $identity ? manaSymbols(implode('', array_map(fn($c) => '{' . $c . '}', $identity))) : '' ?> <?= (int)$d['card_count'] ?>/100 · @<?= h($d['username']) ?></small></span>
        </a>
    <?php endforeach; ?>
    </div>
</section>

<section aria-labelledby="public-collections-title" class="public-collections">
    <div class="section-heading"><h2 id="public-collections-title">Coleções públicas <span class="muted"><?= count($collections) ?></span></h2></div>
    <?php if (!$collections): ?><p class="muted">Nenhuma coleção pública<?= $username !== '' ? ' deste usuário' : ' ainda' ?>.</p><?php else: ?>
    <ul class="public-collection-list">
        <?php foreach ($collections as $collection): ?><li><a href="/public_collection.php?u=<?= h(rawurlencode((string)$collection['username'])) ?>">@<?= h($collection['username']) ?></a><span><?= number_format((int)$collection['total'], 0, ',', '.') ?> cartas · <?= number_format((int)$collection['printings'], 0, ',', '.') ?> versões</span></li><?php endforeach; ?>
    </ul>
    <?php endif; ?>
</section>
</div>
<?php pageFooter(); ?>
