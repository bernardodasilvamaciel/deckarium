<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require __DIR__ . '/functions.php';
require __DIR__ . '/partials.php';
require __DIR__ . '/catalog_cache.php';

$q = trim((string)($_GET['q'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 48;
$offset = ($page - 1) * $perPage;

$params = [];
$where = ['set_code IS NOT NULL', "set_code <> ''"];
if ($q !== '') {
    $where[] = '(set_name ILIKE :q OR set_code ILIKE :q)';
    $params[':q'] = '%' . $q . '%';
}
$whereSql = 'WHERE ' . implode(' AND ', $where);

$sql = <<<SQL
WITH stats AS (
    SELECT set_code,
           MAX(set_name) AS set_name,
           MIN(released_at) AS first_release,
           MAX(released_at) AS last_release,
           COUNT(*) AS printings,
           COUNT(DISTINCT COALESCE(oracle_id,id)) AS unique_cards,
           NULL::text AS set_type
    FROM cards
    {$whereSql}
    GROUP BY set_code
), selected_sets AS (
    SELECT * FROM stats ORDER BY first_release DESC NULLS LAST,set_name
    LIMIT {$perPage} OFFSET {$offset}
)
SELECT s.*
FROM selected_sets s
ORDER BY s.first_release DESC NULLS LAST, s.set_name
SQL;
$loadEditions = function () use ($sql, $params): array {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
};
$editions = $q === '' ? catalogCached('editions-' . $page, $loadEditions) : $loadEditions();
$editions = array_values($editions);
usort($editions, static fn(array $a, array $b): int => [(string)($b['first_release'] ?? ''), (string)$b['set_name']] <=> [(string)($a['first_release'] ?? ''), (string)$a['set_name']]);

$countSql = "SELECT COUNT(DISTINCT set_code) FROM cards {$whereSql}";
$countStmt = db()->prepare($countSql);
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

pageHeader('Edições - Deckarium');
?>
<section class="hero">
  <div><h1>Edições de Magic</h1><p>Explore cada coleção e suas subedições sob o mesmo guarda-chuva.</p></div>
  <span class="hero-count"><?= number_format($total, 0, ',', '.') ?> coleções no acervo</span>
</section>

<form class="search editions-search" method="get">
  <label class="field">Nome ou código da edição<input type="search" name="q" value="<?= h($q) ?>" placeholder="Ex.: Modern Horizons, MH3…"></label>
  <button>Buscar edição</button>
</form>
<p class="muted edition-result-count"><?= number_format($total, 0, ',', '.') ?> edição(ões) encontradas.</p>

<?php
if (!$editions) {
    echo '<div class="empty-state"><h2>Nenhuma edição encontrada</h2><p>Tente outro nome ou código.</p><a href="/editions.php">Ver todas as edições</a></div>';
}
$years = [];
foreach ($editions as $edition) {
    $year = !empty($edition['first_release']) ? substr((string)$edition['first_release'], 0, 4) : 'Sem data';
    $umbrella = editionUmbrella((string)$edition['set_name']);
    $years[$year][$umbrella][] = $edition;
}
uksort($years, static fn(string $a, string $b): int => strcmp($b, $a));
foreach ($years as &$groups) {
    foreach ($groups as &$group) {
        usort($group, static fn(array $a, array $b): int => [(string)($b['first_release'] ?? ''), (string)$b['set_name']] <=> [(string)($a['first_release'] ?? ''), (string)$a['set_name']]);
    }
    unset($group);
    uksort($groups, static function (string $a, string $b) use ($groups): int {
        $dateA = (string)($groups[$a][0]['first_release'] ?? '');
        $dateB = (string)($groups[$b][0]['first_release'] ?? '');
        return [$dateB, $b] <=> [$dateA, $a];
    });
}
unset($groups);
foreach ($years as $year => $groups):
?>
<section class="edition-year"><div class="year-heading"><h2><?= h($year) ?></h2><span><?= number_format(array_sum(array_map('count', $groups)), 0, ',', '.') ?> edições</span></div>
<?php foreach ($groups as $umbrella => $group): ?>
<section class="edition-group" aria-labelledby="umbrella-<?= h(md5($year . $umbrella)) ?>"><div class="edition-umbrella"><h3 id="umbrella-<?= h(md5($year . $umbrella)) ?>"><?= h($umbrella) ?></h3><span><?= count($group) === 1 ? '1 edição' : count($group) . ' subedições' ?></span></div><div class="edition-grid">
<?php
$master = null;
foreach ($group as $candidate) {
    if (strcasecmp(trim((string)$candidate['set_name']), trim((string)$umbrella)) === 0) {
        $master = $candidate;
        break;
    }
}
$masterCode = $master['set_code'] ?? ($group[0]['set_code'] ?? null);
foreach ($group as $edition):
    // Mantém o ícone próprio quando a Scryfall publica um. Se o arquivo
    // não existir, o navegador tenta imediatamente o ícone da coleção-mãe.
    $icon = setIconUrl($edition['set_code']);
    $masterIcon = ($masterCode && (string)$masterCode !== (string)$edition['set_code']) ? setIconUrl($masterCode) : null;
    $genericIcon = '/assets/set-placeholder.svg';
    $iconLabel = strtoupper((string)$edition['set_code']);
?>
  <article class="edition-card">
    <a class="edition-cover" href="/edition.php?set=<?= urlencode((string)$edition['set_code']) ?>" aria-label="Abrir edição <?= h($edition['set_name']) ?>">
      <span class="set-mark<?= $icon ? '' : ' is-fallback' ?>">
        <?php if ($icon): ?><img class="set-icon" decoding="async" loading="lazy" src="<?= h($icon) ?>"<?= $masterIcon ? ' data-fallback-src="' . h($masterIcon) . '"' : '' ?> data-generic-src="<?= h($genericIcon) ?>" alt="" width="96" height="96" onerror="const fallback=this.dataset.fallbackSrc;const generic=this.dataset.genericSrc;if(fallback){this.src=fallback;this.removeAttribute('data-fallback-src')}else if(generic){this.src=generic;this.removeAttribute('data-generic-src')}else{this.remove();this.parentElement.classList.add('is-fallback')}"><?php endif; ?>
        <span class="set-icon-fallback" aria-hidden="true"><?= h($iconLabel) ?></span>
      </span>
    </a>
    <div class="edition-info">
      <span class="set-code-pill"><?= h(strtoupper((string)$edition['set_code'])) ?></span>
      <h3><a href="/edition.php?set=<?= urlencode((string)$edition['set_code']) ?>"><?= h($edition['set_name']) ?></a></h3>
      <p><?= h(displayDate($edition['first_release'])) ?><?= $edition['last_release'] !== $edition['first_release'] ? ' — ' . h(displayDate($edition['last_release'])) : '' ?></p>
      <div class="edition-stats">
        <span><?= number_format((int)$edition['unique_cards'], 0, ',', '.') ?> cartas</span>
        <span><?= number_format((int)$edition['printings'], 0, ',', '.') ?> impressões</span>
      </div>
    </div>
  </article>
<?php endforeach; ?></div></section>
<?php endforeach; ?></section>
<?php endforeach; ?>

<?php if ($total > $perPage): ?>
<nav class="pager">
  <?php if ($page > 1): ?><a href="?q=<?= urlencode($q) ?>&page=<?= $page-1 ?>">← Anterior</a><?php endif; ?>
  <span>Página <?= $page ?></span>
  <?php if ($offset + $perPage < $total): ?><a href="?q=<?= urlencode($q) ?>&page=<?= $page+1 ?>">Próxima →</a><?php endif; ?>
</nav>
<?php endif; ?>
<?php pageFooter(); ?>



