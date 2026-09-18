<?php
declare(strict_types=1);
/**
 * Página de uma edição: cartas com busca, filtros (raridade, cores, tipos e temas) e ordenação.
 * Mostra também os outros códigos da mesma coleção (Commander, fichas, promos…).
 */
require __DIR__ . '/db.php';
require __DIR__ . '/functions.php';
require __DIR__ . '/partials.php';
require __DIR__ . '/catalog_cache.php';
require __DIR__ . '/card_filters.php';

$set = strtolower(trim((string)($_GET['set'] ?? '')));
if ($set === '' || !preg_match('/^[a-z0-9]{2,8}$/', $set)) {
    header('Location: /editions.php');
    exit;
}
$infoStmt = db()->prepare(<<<'SQL'
    SELECT set_code, MAX(set_name) AS set_name, MAX(raw->>'set_type') AS set_type,
           MIN(released_at)::text AS first_release, MAX(released_at)::text AS last_release,
           COUNT(*) AS printings, COUNT(DISTINCT COALESCE(oracle_id, id)) AS unique_cards
    FROM cards WHERE set_code = ? GROUP BY set_code
SQL);
$infoStmt->execute([$set]);
$edition = $infoStmt->fetch();
if (!$edition) {
    http_response_code(404);
    pageHeader('Edição não encontrada');
    echo '<section class="empty-state"><h2>Edição não encontrada</h2><p>Nenhuma carta com o código ' . h(strtoupper($set)) . ' no acervo.</p><a href="/editions.php">Ver todas as edições</a></section>';
    pageFooter();
    exit;
}

// Filtros: mesmos do catálogo, com a edição fixa e cores no modo "contém qualquer uma".
$f = cardFilters();
$f['set'] = $set;
if ($f['colors'] && $f['colors_mode'] === '') $f['colors_mode'] = 'any';
$view = ($_GET['view'] ?? '') === 'printings' ? 'printings' : 'unique';
$sortOptions = ['number' => 'Número de colecionador', 'name' => 'Nome', 'rarity' => 'Raridade', 'cmc' => 'Valor de mana', 'price' => 'Preço (maior primeiro)', 'color' => 'Cor'];
$sort = is_string($_GET['sort'] ?? null) && isset($sortOptions[$_GET['sort']]) ? $_GET['sort'] : 'number';
$usdRate = (float)(getenv('USD_BRL_RATE') ?: 5.5);
$eurRate = (float)(getenv('EUR_BRL_RATE') ?: 6.0);
$number = "CASE WHEN c.collector_number ~ '^[0-9]+$' THEN c.collector_number::int ELSE 999999 END, c.collector_number";
$orderSql = match ($sort) {
    'name' => 'c.name, ' . $number,
    'rarity' => "CASE c.rarity WHEN 'mythic' THEN 0 WHEN 'rare' THEN 1 WHEN 'uncommon' THEN 2 WHEN 'common' THEN 3 ELSE 4 END, {$number}",
    'cmc' => "c.cmc NULLS LAST, c.name",
    'price' => "COALESCE(NULLIF(c.prices->>'usd','')::numeric*{$usdRate}, NULLIF(c.prices->>'eur','')::numeric*{$eurRate}, NULLIF(c.prices->>'usd_foil','')::numeric*{$usdRate}) DESC NULLS LAST, c.name",
    // Brancas, azuis, pretas, vermelhas, verdes; depois multicoloridas e incolores.
    'color' => "CASE WHEN jsonb_array_length(COALESCE(c.colors,'[]'::jsonb)) > 1 THEN 5 WHEN c.colors @> '[\"W\"]' THEN 0 WHEN c.colors @> '[\"U\"]' THEN 1 WHEN c.colors @> '[\"B\"]' THEN 2 WHEN c.colors @> '[\"R\"]' THEN 3 WHEN c.colors @> '[\"G\"]' THEN 4 ELSE 6 END, {$number}",
    default => $number . ', c.name',
};
[$whereSql, $params] = cardFilterSql($f);
$perPage = 60;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;
$sql = $view === 'unique'
    ? "WITH ranked AS (
            SELECT c.*, row_number() OVER (PARTITION BY COALESCE(c.oracle_id, c.id) ORDER BY (c.lang = 'en') DESC, (c.image_uri IS NOT NULL) DESC, c.collector_number, c.id) AS rn
            FROM cards c {$whereSql}
       )
       SELECT c.*, COUNT(*) OVER () AS total_rows FROM ranked c WHERE c.rn = 1 ORDER BY {$orderSql}, c.id LIMIT {$perPage} OFFSET {$offset}"
    : "SELECT c.*, COUNT(*) OVER () AS total_rows FROM cards c {$whereSql} ORDER BY {$orderSql}, c.id LIMIT {$perPage} OFFSET {$offset}";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$cards = $stmt->fetchAll();
$total = (int)($cards[0]['total_rows'] ?? 0);
$pages = max(1, (int)ceil($total / $perPage));

$rarityLabels = ['mythic' => 'Mítica', 'rare' => 'Rara', 'uncommon' => 'Incomum', 'common' => 'Comum', 'special' => 'Especial', 'bonus' => 'Bônus'];
$rarityStmt = db()->prepare('SELECT rarity, COUNT(DISTINCT COALESCE(oracle_id, id)) AS total FROM cards WHERE set_code = ? GROUP BY rarity');
$rarityStmt->execute([$set]);
$rarityCounts = $rarityStmt->fetchAll(PDO::FETCH_KEY_PAIR);
uksort($rarityCounts, static fn($a, $b) => array_search($a, array_keys($rarityLabels)) <=> array_search($b, array_keys($rarityLabels)));

// Outros códigos da mesma coleção (ex.: DSK, DSC, TDSK, PDSK).
$umbrella = editionUmbrella((string)$edition['set_name']);
$siblings = array_values(array_filter(
    catalogCached('filter-sets', fn() => db()->query('SELECT set_code,MAX(set_name) set_name FROM cards GROUP BY set_code ORDER BY MAX(set_name)')->fetchAll()),
    static fn(array $s): bool => $s['set_code'] !== $set && editionUmbrella((string)$s['set_name']) === $umbrella
));

$icon = setIconUrl($set);
$logo = '/assets/deckarium-favicon.png';
$active = $f['q'] !== '' || $f['type'] !== '' || $f['rarity'] !== '' || $f['colors'];
$baseParams = array_filter(['set' => $set, 'view' => $view === 'unique' ? '' : $view, 'sort' => $sort === 'number' ? '' : $sort, 'q' => $f['q'], 'type' => $f['type'], 'rarity' => $f['rarity'], 'colors' => $f['colors']], static fn($v) => $v !== '' && $v !== []);
$colorLabels = ['W' => 'Branco', 'U' => 'Azul', 'B' => 'Preto', 'R' => 'Vermelho', 'G' => 'Verde', 'C' => 'Incolor'];

pageHeader($edition['set_name']);
?>
<section class="set-hero">
    <a class="back-link" href="/editions.php#ano-<?= h(substr((string)$edition['first_release'], 0, 4)) ?>">← Linha do tempo das edições</a>
    <div class="set-hero-main">
        <span class="set-hero-mark"><img class="set-icon" src="<?= h($icon ?: $logo) ?>" alt="" width="72" height="72" onerror="this.onerror=null;this.src='<?= h($logo) ?>';this.classList.add('is-logo')"<?= $icon ? '' : ' data-logo' ?>></span>
        <div>
            <p class="set-hero-kicker"><span><?= h(strtoupper($set)) ?></span><?= h(displayDate($edition['first_release'])) ?><?= $edition['last_release'] !== $edition['first_release'] ? ' — ' . h(displayDate($edition['last_release'])) : '' ?></p>
            <h1><?= h($edition['set_name']) ?></h1>
            <p class="muted"><?= number_format((int)$edition['unique_cards'], 0, ',', '.') ?> cartas únicas · <?= number_format((int)$edition['printings'], 0, ',', '.') ?> impressões</p>
        </div>
    </div>
    <?php if ($siblings): ?><nav class="set-siblings" aria-label="Outros códigos da mesma coleção"><span>Da mesma coleção</span><?php foreach ($siblings as $sibling): ?><a href="/edition.php?set=<?= h(rawurlencode((string)$sibling['set_code'])) ?>"><?= h($sibling['set_name']) ?> <b><?= h(strtoupper((string)$sibling['set_code'])) ?></b></a><?php endforeach; ?></nav><?php endif; ?>
</section>

<form class="set-filters" method="get" action="/edition.php" role="search" aria-label="Filtrar cartas da edição">
    <input type="hidden" name="set" value="<?= h($set) ?>">
    <div class="set-filters-row">
        <label class="set-field is-wide">Nome<input type="search" name="q" value="<?= h($f['q']) ?>" placeholder="Nome da carta"></label>
        <label class="set-field is-wide">Tipos e temas<input name="type" value="<?= h($f['type']) ?>" placeholder="creature; treasure; flying"></label>
        <label class="set-field">Raridade<select name="rarity" data-auto-submit><option value="">Todas</option><?php foreach ($rarityCounts as $rarity => $count): ?><option value="<?= h($rarity) ?>"<?= $f['rarity'] === $rarity ? ' selected' : '' ?>><?= h($rarityLabels[$rarity] ?? ucfirst((string)$rarity)) ?> (<?= (int)$count ?>)</option><?php endforeach; ?></select></label>
        <label class="set-field">Ordenar por<select name="sort" data-auto-submit><?php foreach ($sortOptions as $key => $label): ?><option value="<?= $key ?>"<?= $sort === $key ? ' selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select></label>
    </div>
    <div class="set-filters-row">
        <fieldset class="set-colors"><legend>Cores</legend><?php foreach ($colorLabels as $color => $label): ?><label title="<?= h($label) ?>"><input type="checkbox" name="colors[]" value="<?= $color ?>" data-auto-submit<?= in_array($color, $f['colors'], true) ? ' checked' : '' ?>><?= manaSymbols('{' . $color . '}') ?><span class="sr-only"><?= h($label) ?></span></label><?php endforeach; ?></fieldset>
        <fieldset class="set-view"><legend class="sr-only">Mostrar</legend>
            <label><input type="radio" name="view" value="unique"<?= $view === 'unique' ? ' checked' : '' ?> data-auto-submit> Cartas únicas</label>
            <label><input type="radio" name="view" value="printings"<?= $view === 'printings' ? ' checked' : '' ?> data-auto-submit> Todas as versões</label>
        </fieldset>
        <button class="primary-link">Filtrar</button>
        <?php if ($active || $sort !== 'number'): ?><a class="set-clear" href="/edition.php?set=<?= h(rawurlencode($set)) ?><?= $view === 'printings' ? '&amp;view=printings' : '' ?>">Limpar filtros</a><?php endif; ?>
    </div>
</form>

<p class="set-result muted"><?= number_format($total, 0, ',', '.') ?> <?= $view === 'unique' ? ($total === 1 ? 'carta' : 'cartas') : ($total === 1 ? 'versão' : 'versões') ?><?= $active ? ' com os filtros atuais' : '' ?> · <?= h(mb_strtolower($sortOptions[$sort])) ?><?= $pages > 1 ? ' · página ' . $page . ' de ' . $pages : '' ?></p>
<?php if (!$cards): ?><div class="empty-state"><h2>Nenhuma carta com esses filtros</h2><p>Remova um filtro ou <a href="/edition.php?set=<?= h(rawurlencode($set)) ?>">veja todas as cartas da edição</a>.</p></div><?php endif; ?>
<div class="grid">
<?php foreach ($cards as $card): ?>
  <?php cardTile($card); ?>
<?php endforeach; ?>
</div>
<?php if ($pages > 1) numberedPager($page, $pages, $baseParams); ?>
<?php pageFooter(); ?>
