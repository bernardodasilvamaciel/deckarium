<?php
declare(strict_types=1);

/**
 * Lista de desejos: cartas guardadas do catálogo, das edições ou dos comandantes,
 * mesmo sem nenhuma cópia na coleção. Serve de lista de compras pessoal.
 */

require __DIR__ . '/db.php';
require __DIR__ . '/functions.php';
require __DIR__ . '/partials.php';
require __DIR__ . '/deck_library.php';
require __DIR__ . '/card_actions.php';

$user = authRequireLogin();
$userId = (int)$user['id'];
wishlistSchema();
cardActionHandlePost($userId);

$sorts = ['added' => 'Adicionadas recentemente', 'price_desc' => 'Preço: maior primeiro',
    'price_asc' => 'Preço: menor primeiro', 'name' => 'Nome (A–Z)'];
$sort = is_string($_GET['sort'] ?? null) && isset($sorts[$_GET['sort']]) ? (string)$_GET['sort'] : 'added';
$order = [
    'added' => 'w.created_at DESC, c.name',
    'price_desc' => deckCheapestPriceSql('c') . ' DESC NULLS LAST, c.name',
    'price_asc' => deckCheapestPriceSql('c') . ' ASC NULLS LAST, c.name',
    'name' => 'c.name ASC',
][$sort];

// owned: cópias da carta na coleção, para separar o que já foi comprado.
$cards = deckQuery(deckOwnedSql() . "SELECT c.*, w.created_at, COALESCE(o.owned,0) AS owned
    FROM wishlist w JOIN cards c ON c.id = w.card_id
    LEFT JOIN owned o ON o.logical_id = COALESCE(c.oracle_id, c.id)
    WHERE w.user_id = ? ORDER BY {$order}", [$userId])->fetchAll();

$total = 0.0; $semCotacao = 0; $jaTenho = 0;
foreach ($cards as $card) {
    $price = deckPriceBrl($card);
    if ($price === null) $semCotacao++; else $total += $price;
    if ((int)$card['owned'] > 0) $jaTenho++;
}
$decks = cardActionDecks($userId);
$wishlist = wishlistLogicalIds($userId);
$back = '/wishlist.php' . ($sort !== 'added' ? '?sort=' . $sort : '');

pageHeader('Lista de desejos', 'Cartas que você quer comprar, guardadas do catálogo e das edições.', ['noindex' => true]);
echo cardActionNotice();
?>
<section class="hero">
  <div>
    <h1><?= te('Lista de desejos') ?></h1>
    <p><?= te('Cartas guardadas para comprar depois. Salve daqui do catálogo, das edições ou da página da carta.') ?></p>
  </div>
  <a class="text-link" href="/?catalog=1#catalogo"><?= te('Procurar cartas') ?> →</a>
</section>

<?php if ($cards): ?>
<dl class="wishlist-summary">
  <div><dt><?= te('Cartas') ?></dt><dd><?= count($cards) ?></dd></div>
  <div><dt><?= te('Valor estimado') ?></dt><dd>R$ <?= number_format($total, 2, ',', '.') ?></dd><span><?= $semCotacao ? te(':count sem cotação', ['count' => $semCotacao]) : te('Todas com cotação') ?></span></div>
  <div><dt><?= te('Já na coleção') ?></dt><dd><?= $jaTenho ?></dd><span><?= te('cópias que você já comprou') ?></span></div>
</dl>

<div class="filters-bar">
  <form class="filters-sort" method="get" action="/wishlist.php">
    <label><?= te('Ordenar') ?><select name="sort" data-auto-submit>
      <?php foreach ($sorts as $key => $label): ?><option value="<?= h($key) ?>" <?= $sort === $key ? 'selected' : '' ?>><?= te($label) ?></option><?php endforeach; ?>
    </select></label>
  </form>
</div>

<div class="grid wishlist-grid">
  <?php foreach ($cards as $card): $src = cardImageUrl($card, 'front', 'normal'); $owned = (int)$card['owned']; ?>
  <article class="card-tile wishlist-card<?= $owned ? ' is-owned' : '' ?>">
    <a class="card-art" href="/card.php?id=<?= h($card['id']) ?>">
      <?php if ($src): ?><img src="<?= h($src) ?>" alt="<?= h($card['name']) ?>" width="488" height="680" loading="lazy" decoding="async">
      <?php else: ?><div class="placeholder"><strong><?= h($card['name']) ?></strong><span><?= te('Imagem indisponível') ?></span></div><?php endif; ?>
      <?php if ($owned): ?><b class="wishlist-owned-badge"><?= te(':count na coleção', ['count' => $owned]) ?></b><?php endif; ?>
    </a>
    <div class="card-tile-actions">
      <?php cardActionsMenu($card, $decks, $userId, $back, $wishlist); ?>
      <form method="post" class="wishlist-remove">
        <input type="hidden" name="csrf" value="<?= h((string)($_SESSION['builder_csrf'] ?? '')) ?>">
        <input type="hidden" name="card" value="<?= h($card['id']) ?>">
        <input type="hidden" name="back" value="<?= h($back) ?>">
        <input type="hidden" name="card_action" value="wishlist_remove">
        <button class="text-button" aria-label="<?= te('Tirar :name da lista', ['name' => $card['name']]) ?>"><?= te('Tirar da lista') ?></button>
      </form>
    </div>
    <div class="card-meta">
      <a href="/card.php?id=<?= h($card['id']) ?>"><?= h($card['name']) ?></a>
      <small><?= h(strtoupper((string)$card['set_code'])) ?> · #<?= h((string)$card['collector_number']) ?></small>
      <small class="card-price"><?= h(deckPriceLabel($card)) ?></small>
    </div>
  </article>
  <?php endforeach; ?>
</div>
<?php else: ?>
<div class="empty-state">
  <h2><?= te('Sua lista está vazia') ?></h2>
  <p><?= te('No catálogo, nas edições ou na página de uma carta, abra Guardar e escolha “Salvar na lista”. A carta fica aqui com o preço atualizado, mesmo que você não tenha nenhuma cópia.') ?></p>
  <a class="primary-link" href="/?catalog=1#catalogo"><?= te('Procurar cartas') ?></a>
</div>
<?php endif; ?>
<?php pageFooter(); ?>
