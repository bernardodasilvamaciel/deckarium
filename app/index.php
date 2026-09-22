<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require __DIR__ . '/functions.php';
require __DIR__ . '/partials.php';
require __DIR__ . '/catalog_cache.php';
require __DIR__ . '/card_filters.php';
require __DIR__ . '/card_actions.php';
// Ações rápidas da carta (coleção e candidatas de um deck).
$cardActionsUser = (int)(authUser()['id'] ?? 0);
cardActionHandlePost($cardActionsUser);
$GLOBALS['cardActionsContext'] = ['user_id' => $cardActionsUser, 'decks' => cardActionDecks($cardActionsUser),
    'wishlist' => wishlistLogicalIds($cardActionsUser),
    'back' => authSafeNext((string)($_SERVER['REQUEST_URI'] ?? '/'), '/')];
$f=cardFilters();
// Ordenação do catálogo: preço usa o menor valor entre normal e foil, convertido em reais.
$catalogSorts = ['new'=>'Lançamento (mais novas)','old'=>'Lançamento (mais antigas)','name'=>'Nome (A–Z)',
    'price_desc'=>'Preço: maior primeiro','price_asc'=>'Preço: menor primeiro'];
$sort = is_string($_GET['sort'] ?? null) && isset($catalogSorts[$_GET['sort']]) ? (string)$_GET['sort'] : 'new';
$priceExpr = deckCheapestPriceSql('c');
$catalogOrder = [
    'new' => 'c.released_at DESC NULLS LAST, c.name, c.id',
    'old' => 'c.released_at ASC NULLS LAST, c.name, c.id',
    'name' => 'c.name ASC, c.released_at DESC NULLS LAST, c.id',
    'price_desc' => "{$priceExpr} DESC NULLS LAST, c.name, c.id",
    'price_asc' => "{$priceExpr} ASC NULLS LAST, c.name, c.id",
][$sort];
// A mesma ordem aplicada dentro do CTE de cartas únicas, onde o preço já vem calculado.
$rankedOrder = [
    'new' => 'released_at DESC NULLS LAST, name, id',
    'old' => 'released_at ASC NULLS LAST, name, id',
    'name' => 'name ASC, released_at DESC NULLS LAST, id',
    'price_desc' => 'cheapest DESC NULLS LAST, name, id',
    'price_asc' => 'cheapest ASC NULLS LAST, name, id',
][$sort];
$isHome = !array_filter($f) && !isset($_GET['catalog']) && !isset($_GET['view']) && !isset($_GET['page']) && !isset($_GET['sort']);
// "/" sem busca nem filtros é a página inicial, que explica cada módulo do site.
if ($isHome) {
    $GLOBALS['isHome'] = true;
    require __DIR__ . '/home.php';
    exit;
}

$q = trim((string)($_GET['q'] ?? ''));
$set = trim((string)($_GET['set'] ?? ''));
$view = (string)($_GET['view'] ?? 'unique');
$view = $view === 'printings' ? 'printings' : 'unique';
$page = max(1, min(1000000, (int)($_GET['page'] ?? 1)));
$perPage = 36;
$offset = ($page - 1) * $perPage;


// Pequeno resumo das edições recentes, abaixo dos resultados.
$recentSetsSql = <<<'SQL'
SELECT set_code,
       MAX(set_name) AS set_name,
       MIN(released_at) AS released_at,
       COUNT(*) AS printings,
       COUNT(DISTINCT COALESCE(oracle_id,id)) AS unique_cards
FROM cards
WHERE set_code IS NOT NULL
  AND released_at IS NOT NULL
  AND released_at <= CURRENT_DATE
GROUP BY set_code
ORDER BY MIN(released_at) DESC NULLS LAST, set_code
LIMIT 4
SQL;
$recentSets = catalogCached("recent-sets", fn() => db()->query($recentSetsSql)->fetchAll());

[$whereSql,$params]=cardFilterSql($f);
$total = catalogCached('filter-count-v1'.json_encode([$view,$f]), function() use($view,$whereSql,$params) {
    $sql=$view==='unique' ? "SELECT count(DISTINCT COALESCE(c.oracle_id,c.id)) FROM cards c {$whereSql}" : "SELECT count(*) FROM cards c {$whereSql}";
    $stmt=db()->prepare($sql);$stmt->execute($params);return [(int)$stmt->fetchColumn()];
})[0];
$pages=max(1,(int)ceil($total/$perPage));$page=min($page,$pages);$offset=($page-1)*$perPage;

if ($view === 'unique') {
    $sql = <<<SQL
WITH ranked AS (
    SELECT c.id,c.name,c.released_at,{$priceExpr} AS cheapest,
           row_number() OVER (
               PARTITION BY COALESCE(c.oracle_id, c.id)
               ORDER BY
                    c.released_at DESC NULLS LAST,
                    (c.lang = 'en') DESC,
                    (c.image_uri IS NOT NULL) DESC,
                    c.id
           ) AS rn
    FROM cards c
    {$whereSql}
)
SELECT c.* FROM (
    SELECT id FROM ranked WHERE rn = 1 ORDER BY {$rankedOrder} LIMIT {$perPage} OFFSET {$offset}
) selected JOIN cards c USING(id) ORDER BY {$catalogOrder}
SQL;
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $cards = $stmt->fetchAll();


} else {
    $sql = "SELECT id,name,mana_cost,type_line,oracle_text,set_code,set_name,collector_number,rarity,released_at,local_image,local_image_back,image_uri,image_uri_back,raw,oracle_id FROM cards c {$whereSql} ORDER BY {$catalogOrder} LIMIT {$perPage} OFFSET {$offset}";
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $cards = $stmt->fetchAll();


}

pageHeader('Cartas');
echo cardActionNotice();
?>
<section class="hero">
  <div>
    <h1><?= te('Encontre sua próxima carta.') ?></h1>
    <p><?= te('Pesquise cartas, explore edições e encontre novas possibilidades para seus decks.') ?></p>
  </div>
  <a class="primary-link" href="/editions.php"><?= te('Explorar por edição') ?> →</a>
</section>

<section id="catalogo" class="catalog-section">
  <div class="section-heading">
    <div>
      <h2><?= $q === '' && $set === '' ? t('Últimas cartas lançadas') : t('Pesquisar cartas') ?></h2>
      <?php if ($q === '' && $set === ''): ?><p class="muted"><?= te('As cartas mais recentes do acervo, ordenadas pela data de lançamento.') ?></p><?php endif; ?>
    </div>
  </div>
  <?php
  $sortForm = '<form class="filters-sort" method="get" action="/#catalogo">';
  foreach (array_filter($f, fn($v) => $v !== '' && $v !== []) as $key => $value) {
      foreach (is_array($value) ? $value : [$value] as $item) $sortForm .= '<input type="hidden" name="' . h($key . (is_array($value) ? '[]' : '')) . '" value="' . h((string)$item) . '">';
  }
  $sortForm .= '<input type="hidden" name="catalog" value="1"><input type="hidden" name="view" value="' . h($view) . '">'
      . '<label>' . te('Ordenar') . '<select name="sort" data-auto-submit>';
  foreach ($catalogSorts as $sortKey => $sortLabel) {
      $sortForm .= '<option value="' . h($sortKey) . '"' . ($sort === $sortKey ? ' selected' : '') . '>' . te($sortLabel) . '</option>';
  }
  $sortForm .= '</select></label></form>';
  cardFilterForm($f, catalogCached('filter-sets',fn()=>db()->query('SELECT set_code,MAX(set_name) set_name FROM cards GROUP BY set_code ORDER BY MAX(set_name)')->fetchAll()), '/#catalogo', $view, $sortForm);
  ?>
  <div class="view-toggle">
    <a class="<?= $view === 'unique' ? 'active' : '' ?>" href="?<?= h(http_build_query(array_merge($f,['view'=>'unique']))) ?>#catalogo"><?= te('Cartas únicas') ?></a>
    <a class="<?= $view === 'printings' ? 'active' : '' ?>" href="?<?= h(http_build_query(array_merge($f,['view'=>'printings']))) ?>#catalogo"><?= te('Todas as impressões') ?></a>
  </div>
  <p class="muted"><?= number_format($total, 0, ',', '.') ?> <?= te('resultado(s)') ?> — <?= $view === 'unique' ? te('sem repetir reimpressões') : te('incluindo reimpressões') ?>.</p>
  <?php if (!$cards): ?><div class="empty-state"><h2><?= te('Nenhuma carta encontrada') ?></h2><p><?= te('Tente um nome mais curto ou remova o filtro de edição.') ?></p><a class="primary-link" href="/#catalogo"><?= te('Limpar filtros') ?></a></div><?php endif; ?>
  <?php if ($q !== '' || $set !== ''): ?><p class="filter-reset"><a href="/#catalogo"><?= te('Limpar filtros') ?></a></p><?php endif; ?>
  <div class="grid">
  <?php foreach ($cards as $card): ?>
    <?php cardTile($card); ?>
  <?php endforeach; ?>
  </div>
  <?php numberedPager($page,$pages,array_merge($f,['view'=>$view]),'#catalogo'); ?>
</section>
<section class="section-block">
  <div class="section-heading inline-heading">
    <div>
      
      <h2><?= te('Edições recentes') ?></h2>
    </div>
    <a class="subtle-link" href="/editions.php"><?= te('Ver todas') ?> →</a>
  </div>
  <div class="edition-mini-grid">
    <?php foreach ($recentSets as $edition): ?>
      <a class="edition-mini" href="/edition.php?set=<?= urlencode((string)$edition['set_code']) ?>">
        <strong><?= h($edition['set_name']) ?></strong>
        <span><?= h(strtoupper((string)$edition['set_code'])) ?> · <?= h((string)$edition['released_at']) ?></span>
        <small><?= number_format((int)$edition['unique_cards'], 0, ',', '.') ?> cartas únicas</small>
      </a>
    <?php endforeach; ?>
  </div>
</section>

<?php pageFooter(); ?>



