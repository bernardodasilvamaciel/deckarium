<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require __DIR__ . '/functions.php';
require __DIR__ . '/partials.php';
require __DIR__ . '/catalog_cache.php';
require __DIR__ . '/card_filters.php';
$f=cardFilters();

$q = trim((string)($_GET['q'] ?? ''));
$set = trim((string)($_GET['set'] ?? ''));
$view = (string)($_GET['view'] ?? 'unique');
$view = $view === 'printings' ? 'printings' : 'unique';
$page = max(1, min(1000000, (int)($_GET['page'] ?? 1)));
$perPage = 36;
$offset = ($page - 1) * $perPage;


// Pequeno resumo de edições recentes para a home.
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
    SELECT c.id,c.name,c.lang,c.oracle_id,c.image_uri,c.released_at,
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
    SELECT id FROM ranked WHERE rn = 1 ORDER BY released_at DESC NULLS LAST, name, id LIMIT {$perPage} OFFSET {$offset}
) selected JOIN cards c USING(id) ORDER BY c.released_at DESC NULLS LAST, c.name, c.id
SQL;
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $cards = $stmt->fetchAll();


} else {
    $sql = "SELECT id,name,mana_cost,type_line,oracle_text,set_code,set_name,collector_number,rarity,released_at,local_image,local_image_back,image_uri,image_uri_back,raw,oracle_id FROM cards c {$whereSql} ORDER BY released_at DESC NULLS LAST, name ASC LIMIT {$perPage} OFFSET {$offset}";
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $cards = $stmt->fetchAll();


}

pageHeader('Cartas - Deckarium');
?>
<section class="hero">
  <div>
    <h1>Encontre sua próxima carta.</h1>
    <p>Pesquise cartas, explore edições e encontre novas possibilidades para seus decks.</p>
  </div>
  <a class="primary-link" href="/editions.php">Explorar por edição →</a>
</section>

<section id="catalogo" class="catalog-section">
  <div class="section-heading">
    <div>
      <h2><?= $q === '' && $set === '' ? 'Últimas cartas lançadas' : 'Pesquisar cartas' ?></h2>
      <?php if ($q === '' && $set === ''): ?><p class="muted">As cartas mais recentes do acervo, ordenadas pela data de lançamento.</p><?php endif; ?>
    </div>
  </div>
  <?php cardFilterForm($f, catalogCached('filter-sets',fn()=>db()->query('SELECT set_code,MAX(set_name) set_name FROM cards GROUP BY set_code ORDER BY MAX(set_name)')->fetchAll()), '/#catalogo', $view); ?>
  <div class="view-toggle">
    <a class="<?= $view === 'unique' ? 'active' : '' ?>" href="?<?= h(http_build_query(array_merge($f,['view'=>'unique']))) ?>#catalogo">Cartas únicas</a>
    <a class="<?= $view === 'printings' ? 'active' : '' ?>" href="?<?= h(http_build_query(array_merge($f,['view'=>'printings']))) ?>#catalogo">Todas as impressões</a>
  </div>
  <p class="muted"><?= number_format($total, 0, ',', '.') ?> resultado(s) — <?= $view === 'unique' ? 'sem repetir reimpressões' : 'incluindo reimpressões' ?>.</p>
  <?php if (!$cards): ?><div class="empty-state"><h2>Nenhuma carta encontrada</h2><p>Tente um nome mais curto ou remova o filtro de edição.</p><a class="primary-link" href="/#catalogo">Limpar filtros</a></div><?php endif; ?>
  <?php if ($q !== '' || $set !== ''): ?><p class="filter-reset"><a href="/#catalogo">Limpar filtros</a></p><?php endif; ?>
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
      
      <h2>Edições recentes</h2>
    </div>
    <a class="subtle-link" href="/editions.php">Ver todas →</a>
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



