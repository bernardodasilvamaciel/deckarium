<?php
declare(strict_types=1);
/**
 * Coleção pública de um usuário: somente leitura, com busca por nome e paginação.
 * Não mostra em quais decks as cartas estão nem valores.
 */
require __DIR__ . '/functions.php';
require __DIR__ . '/partials.php';
require __DIR__ . '/deck_library.php';
require __DIR__ . '/card_filters.php';
deckSchema();
$viewer = authUser();
session_write_close();

$username = is_string($_GET['u'] ?? null) ? mb_substr(trim($_GET['u']), 0, 60) : '';
$owner = $username !== '' ? deckQuery('SELECT id,username,collection_public FROM users WHERE lower(username)=lower(?) AND is_active', [$username])->fetch() : null;
$isOwner = $owner && $viewer && (int)$viewer['id'] === (int)$owner['id'];
$isPublic = $owner && in_array($owner['collection_public'], [true, 't', 1, '1'], true);
if (!$owner || (!$isPublic && !$isOwner)) {
    http_response_code(404);
    pageHeader('Coleção não encontrada');
    echo '<section class="empty-state"><h2>Coleção não encontrada</h2><p>Esta coleção não existe ou é privada.</p><p><a href="/public.php">Ver a comunidade</a></p></section>';
    pageFooter();
    exit;
}
$ownerId = (int)$owner['id'];
$query = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']), 0, 120) : '';
$where = 'o.user_id=?';
$params = [$ownerId];
if ($query !== '') {
    $where .= ' AND c.name ILIKE ?';
    $params[] = '%' . strtr($query, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']) . '%';
}
$perPage = 48;
$count = (int)deckQuery("SELECT COUNT(*) FROM builder_collection o JOIN cards c ON c.id=o.scryfall_id WHERE {$where}", $params)->fetchColumn();
$pages = max(1, (int)ceil($count / $perPage));
$page = min(max(1, (int)($_GET['page'] ?? 1)), $pages);
$cards = deckQuery("SELECT c.*,o.quantity,o.foil FROM builder_collection o JOIN cards c ON c.id=o.scryfall_id WHERE {$where} ORDER BY c.name,c.set_code,c.collector_number,o.foil LIMIT {$perPage} OFFSET " . (($page - 1) * $perPage), $params)->fetchAll();
$summary = deckQuery('SELECT COALESCE(SUM(o.quantity),0) total,COUNT(DISTINCT COALESCE(c.oracle_id,c.id)) unique_cards FROM builder_collection o JOIN cards c ON c.id=o.scryfall_id WHERE o.user_id=?', [$ownerId])->fetch();

pageHeader('Coleção de @' . $owner['username']);
?>
<div class="public-page">
<?php if (!$isPublic): ?><p class="notice warning">Pré-visualização: sua coleção é privada. Torne-a pública em <a href="/collection.php">Minha coleção</a> para compartilhar o link.</p><?php endif; ?>
<section class="hero"><div><p class="public-kicker">Coleção pública</p><h1>@<?= h($owner['username']) ?></h1><p><?= number_format((int)$summary['total'], 0, ',', '.') ?> cartas · <?= number_format((int)$summary['unique_cards'], 0, ',', '.') ?> cartas diferentes</p></div><a class="text-link" href="/public.php?u=<?= h(rawurlencode((string)$owner['username'])) ?>">Decks públicos de @<?= h($owner['username']) ?></a></section>
<form class="public-search" method="get" role="search"><input type="hidden" name="u" value="<?= h($owner['username']) ?>"><label class="field">Buscar na coleção<input type="search" name="q" value="<?= h($query) ?>" placeholder="Nome da carta"></label><button class="primary-link">Buscar</button><?php if ($query !== ''): ?><a href="?u=<?= h(rawurlencode((string)$owner['username'])) ?>">Limpar</a><?php endif; ?></form>
<p class="muted"><?= number_format($count, 0, ',', '.') ?> <?= $count === 1 ? 'versão' : 'versões' ?><?= $query !== '' ? ' para “' . h($query) . '”' : '' ?>.</p>
<?php if (!$cards): ?><p class="empty-state"><?= $query !== '' ? 'Nenhuma carta com esse nome.' : 'Esta coleção está vazia.' ?></p><?php endif; ?>
<div class="public-card-grid is-large">
<?php foreach ($cards as $card): $src = cardImageUrl($card, 'front', 'small'); $isFoil = deckIsFoil($card['foil']); ?>
    <a class="public-card<?= $isFoil ? ' is-foil' : '' ?>" href="/card.php?id=<?= h(rawurlencode((string)$card['id'])) ?>">
        <span class="public-card-art"><?php if ($src): ?><img src="<?= h($src) ?>" alt="" loading="lazy" width="146" height="204"><?php endif; ?><b><?= (int)$card['quantity'] ?>×</b></span>
        <span class="public-card-name"><?= h($card['name']) ?></span>
        <small><?= h(strtoupper((string)$card['set_code'])) ?> #<?= h((string)$card['collector_number']) ?><?= $isFoil ? ' · foil' : '' ?> · <?= h(strtoupper((string)$card['lang'])) ?></small>
    </a>
<?php endforeach; ?>
</div>
<?php if ($pages > 1) numberedPager($page, $pages, array_filter(['u' => $owner['username'], 'q' => $query])); ?>
</div>
<?php pageFooter(); ?>
