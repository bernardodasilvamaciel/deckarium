<?php
declare(strict_types=1);
/**
 * Coleção pública de um usuário: somente leitura, com os mesmos filtros do catálogo,
 * opção de mostrar só as cópias que sobram fora dos decks e paginação.
 * Não mostra em quais decks as cartas estão nem valores.
 */
require __DIR__ . '/functions.php';
require __DIR__ . '/partials.php';
require __DIR__ . '/deck_library.php';
require __DIR__ . '/card_filters.php';
require __DIR__ . '/trade_lib.php';
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
// Mesmos filtros do catálogo e de Minha coleção; tudo vai na URL, então o link já sai filtrado.
$f = cardFilters();
[$whereSql, $params] = cardFilterSql($f);
$finish = in_array($_GET['finish'] ?? '', ['foil', 'normal'], true) ? (string)$_GET['finish'] : '';
// "Sobrando": só as cópias que nenhum deck reserva (mesma conta da lista À venda).
$avail = ($_GET['avail'] ?? '') === 'free' ? 'free' : '';
$sort = in_array($_GET['sort'] ?? '', ['color', 'price'], true) ? (string)$_GET['sort'] : 'name';
$usdRate = (float)(getenv('USD_BRL_RATE') ?: 5.5); $eurRate = (float)(getenv('EUR_BRL_RATE') ?: 6.0);
$finishPrice = "CASE WHEN o.foil THEN COALESCE(NULLIF(c.prices->>'usd_foil','')::numeric*{$usdRate},NULLIF(c.prices->>'eur_foil','')::numeric*{$eurRate}) ELSE COALESCE(NULLIF(c.prices->>'usd','')::numeric*{$usdRate},NULLIF(c.prices->>'eur','')::numeric*{$eurRate}) END";
$sortSql = match ($sort) {
    'color' => "COALESCE(c.colors::text,'[]'),c.name,c.set_code,c.collector_number",
    'price' => "{$finishPrice} DESC NULLS LAST,c.name,c.set_code,c.collector_number",
    default => 'c.name,c.set_code,c.collector_number',
};
$where = [$whereSql !== '' ? substr($whereSql, 6) : 'TRUE', 'o.user_id=?'];
$params[] = $ownerId;
if ($finish !== '') { $where[] = 'o.foil=?'; $params[] = $finish === 'foil' ? 'true' : 'false'; }
$from = ' FROM builder_collection o JOIN cards c ON c.id=o.scryfall_id';
$qty = 'o.quantity';
if ($avail === 'free') {
    $from .= ' JOIN ' . tradeFreeCopiesSql($ownerId) . ' fc ON fc.scryfall_id=o.scryfall_id AND fc.foil=o.foil';
    $where[] = 'fc.available > 0';
    $qty = 'fc.available';
}
$from .= ' WHERE ' . implode(' AND ', $where);
$perPage = 48;
$count = (int)deckQuery("SELECT COUNT(*){$from}", $params)->fetchColumn();
$copies = (int)deckQuery("SELECT COALESCE(SUM({$qty}),0){$from}", $params)->fetchColumn();
$pages = max(1, (int)ceil($count / $perPage));
$page = min(max(1, (int)($_GET['page'] ?? 1)), $pages);
$cards = deckQuery("SELECT c.*,{$qty} AS quantity,o.foil{$from} ORDER BY {$sortSql},o.foil LIMIT {$perPage} OFFSET " . (($page - 1) * $perPage), $params)->fetchAll();
$summary = deckQuery('SELECT COALESCE(SUM(o.quantity),0) total,COUNT(DISTINCT COALESCE(c.oracle_id,c.id)) unique_cards FROM builder_collection o JOIN cards c ON c.id=o.scryfall_id WHERE o.user_id=?', [$ownerId])->fetch();
$filtered = cardFilterActiveCount($f) > 0 || $finish !== '' || $avail !== '';
$linkParams = array_filter(['u' => $owner['username']] + cardFilterQuery($f) + ['finish' => $finish, 'avail' => $avail, 'sort' => $sort === 'name' ? '' : $sort], static fn($v) => $v !== '');
$sharePath = '/public_collection.php?' . http_build_query($linkParams);

pageHeader('Coleção de @' . $owner['username']);
?>
<div class="public-page">
<?php if (!$isPublic): ?><p class="notice warning">Pré-visualização: sua coleção é privada. Torne-a pública em <a href="/collection.php">Minha coleção</a> para compartilhar o link.</p><?php endif; ?>
<section class="hero"><div><p class="public-kicker">Coleção pública</p><h1>@<?= h($owner['username']) ?></h1><p><?= number_format((int)$summary['total'], 0, ',', '.') ?> cartas · <?= number_format((int)$summary['unique_cards'], 0, ',', '.') ?> cartas diferentes</p></div><a class="text-link" href="/public.php?u=<?= h(rawurlencode((string)$owner['username'])) ?>">Decks públicos de @<?= h($owner['username']) ?></a></section>
<?php
// Acabamento, disponibilidade e ordem ficam à vista, ao lado do botão de filtros.
$keep = array_diff_key($linkParams, ['finish' => 1, 'avail' => 1, 'sort' => 1]);
ob_start(); ?>
<form class="filters-sort collection-controls" method="get" action="/public_collection.php">
    <?php foreach ($keep as $key => $value): foreach (is_array($value) ? $value : [$value] as $v): ?><input type="hidden" name="<?= h($key . (is_array($value) ? '[]' : '')) ?>" value="<?= h((string)$v) ?>"><?php endforeach; endforeach; ?>
    <label>Cópias<select name="avail" data-auto-submit><option value="">Todas</option><option value="free" <?= $avail === 'free' ? 'selected' : '' ?>>Só as que sobram (fora de decks)</option></select></label>
    <label>Acabamento<select name="finish" data-auto-submit><option value="">Todos</option><option value="normal" <?= $finish === 'normal' ? 'selected' : '' ?>>Somente normais</option><option value="foil" <?= $finish === 'foil' ? 'selected' : '' ?>>Somente foil</option></select></label>
    <label>Ordenar<select name="sort" data-auto-submit><option value="name">Nome</option><option value="color" <?= $sort === 'color' ? 'selected' : '' ?>>Cor</option><option value="price" <?= $sort === 'price' ? 'selected' : '' ?>>Preço</option></select></label>
</form>
<?php if ($filtered): ?><div class="collection-actions"><button type="button" class="secondary-link" data-copy-share="<?= h($sharePath) ?>">Copiar link desta busca</button></div><?php endif; ?>
<?php
$controls = ob_get_clean();
$hidden = array_intersect_key($linkParams, ['u' => 1, 'finish' => 1, 'avail' => 1, 'sort' => 1]);
cardFilterForm($f, deckQuery('SELECT c.set_code,MAX(c.set_name) set_name FROM builder_collection o JOIN cards c ON c.id=o.scryfall_id WHERE o.user_id=? GROUP BY c.set_code ORDER BY MAX(c.set_name)', [$ownerId])->fetchAll(), '/public_collection.php', '', $controls, $hidden);
?>
<p class="muted"><?= number_format($count, 0, ',', '.') ?> <?= $count === 1 ? 'versão' : 'versões' ?> · <?= number_format($copies, 0, ',', '.') ?> <?= $copies === 1 ? 'carta' : 'cartas' ?><?= $avail === 'free' ? ' sobrando fora dos decks' : '' ?><?= $filtered ? ' com estes filtros' : '' ?>.</p>
<?php if (!$cards): ?><p class="empty-state"><?= $filtered ? 'Nenhuma carta com esses filtros.' : 'Esta coleção está vazia.' ?></p><?php endif; ?>
<div class="public-card-grid is-large">
<?php foreach ($cards as $card): $src = cardImageUrl($card, 'front', 'small'); $isFoil = deckIsFoil($card['foil']); ?>
    <a class="public-card<?= $isFoil ? ' is-foil' : '' ?>" href="/card.php?id=<?= h(rawurlencode((string)$card['id'])) ?>">
        <span class="public-card-art"><?php if ($src): ?><img src="<?= h($src) ?>" alt="" loading="lazy" width="146" height="204"><?php endif; ?><b><?= (int)$card['quantity'] ?>×</b></span>
        <span class="public-card-name"><?= h($card['name']) ?></span>
        <small><?= h(strtoupper((string)$card['set_code'])) ?> #<?= h((string)$card['collector_number']) ?><?= $isFoil ? ' · foil' : '' ?> · <?= h(strtoupper((string)$card['lang'])) ?></small>
    </a>
<?php endforeach; ?>
</div>
<?php if ($pages > 1) numberedPager($page, $pages, $linkParams); ?>
</div>
<?php pageFooter(); ?>
