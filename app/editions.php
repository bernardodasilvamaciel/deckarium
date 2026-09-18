<?php
declare(strict_types=1);
/**
 * Edições: linha do tempo de todos os lançamentos em uma única página.
 * Subedições (Commander, fichas, promos, art series…) entram como marcas na linha da coleção-mãe.
 * O espectrograma do topo mostra, por ano, quantas cartas inéditas surgiram e a proporção de cada cor de mana.
 */
require __DIR__ . '/db.php';
require __DIR__ . '/functions.php';
require __DIR__ . '/partials.php';
require __DIR__ . '/catalog_cache.php';

$sets = catalogCached('editions-timeline-v1', static fn(): array => db()->query(<<<'SQL'
    WITH logical AS (
        SELECT DISTINCT ON (set_code, COALESCE(oracle_id, id)) set_code,
               COALESCE(NULLIF(colors, '[]'::jsonb), card_faces->0->'colors', '[]'::jsonb) AS c
        FROM cards WHERE set_code IS NOT NULL AND set_code <> ''
    ), spectrum AS (
        SELECT set_code,
               COUNT(*) FILTER (WHERE c @> '["W"]') AS w, COUNT(*) FILTER (WHERE c @> '["U"]') AS u,
               COUNT(*) FILTER (WHERE c @> '["B"]') AS b, COUNT(*) FILTER (WHERE c @> '["R"]') AS r,
               COUNT(*) FILTER (WHERE c @> '["G"]') AS g, COUNT(*) FILTER (WHERE c = '[]'::jsonb) AS colorless
        FROM logical GROUP BY set_code
    )
    SELECT s.set_code, MAX(c.set_name) AS set_name, MAX(c.raw->>'set_type') AS set_type,
           MIN(c.released_at)::text AS first_release, COUNT(DISTINCT COALESCE(c.oracle_id, c.id)) AS unique_cards,
           s.w, s.u, s.b, s.r, s.g, s.colorless
    FROM cards c JOIN spectrum s ON s.set_code = c.set_code
    GROUP BY s.set_code, s.w, s.u, s.b, s.r, s.g, s.colorless
SQL)->fetchAll(), 86400);

// Cartas inéditas por ano: cada carta conta uma vez, no ano da primeira impressão (sem fichas e cartas só digitais).
$newCards = catalogCached('editions-new-cards-v1', static fn(): array => db()->query(<<<'SQL'
    WITH firsts AS (
        SELECT DISTINCT ON (COALESCE(oracle_id, id)) released_at,
               COALESCE(NULLIF(colors, '[]'::jsonb), card_faces->0->'colors', '[]'::jsonb) AS c
        FROM cards
        WHERE released_at IS NOT NULL
          AND layout NOT IN ('token', 'double_faced_token', 'emblem', 'art_series')
          AND COALESCE(raw->>'set_type', '') NOT IN ('token', 'memorabilia')
          AND COALESCE(raw->>'digital', 'false') <> 'true'
        ORDER BY COALESCE(oracle_id, id), released_at
    )
    SELECT extract(year FROM released_at)::int::text AS year, COUNT(*) AS cards,
           COUNT(*) FILTER (WHERE c @> '["W"]') AS "W", COUNT(*) FILTER (WHERE c @> '["U"]') AS "U",
           COUNT(*) FILTER (WHERE c @> '["B"]') AS "B", COUNT(*) FILTER (WHERE c @> '["R"]') AS "R",
           COUNT(*) FILTER (WHERE c @> '["G"]') AS "G", COUNT(*) FILTER (WHERE c = '[]'::jsonb) AS "C"
    FROM firsts GROUP BY 1
SQL)->fetchAll(), 86400);

// Categorias do filtro, a partir do set_type do Scryfall.
$categories = [
    'main' => 'Expansões',
    'draft' => 'Draft e Masters',
    'commander' => 'Commander',
    'special' => 'Produtos especiais',
    'extras' => 'Promos e fichas',
];
$categoryOf = static fn(string $type): string => match ($type) {
    'expansion', 'core' => 'main',
    'draft_innovation', 'masters', 'eternal' => 'draft',
    'commander', 'planechase', 'archenemy', 'vanguard' => 'commander',
    'promo', 'token', 'memorabilia' => 'extras',
    default => 'special',
};
$typeLabels = [
    'expansion' => 'Expansão', 'core' => 'Coleção básica', 'draft_innovation' => 'Draft especial', 'masters' => 'Masters',
    'eternal' => 'Eternal', 'commander' => 'Commander', 'planechase' => 'Planechase', 'archenemy' => 'Archenemy',
    'vanguard' => 'Vanguard', 'box' => 'Box set', 'duel_deck' => 'Duel Decks', 'from_the_vault' => 'From the Vault',
    'premium_deck' => 'Premium Deck', 'spellbook' => 'Signature Spellbook', 'arsenal' => 'Arsenal', 'masterpiece' => 'Masterpiece',
    'starter' => 'Iniciante', 'treasure_chest' => 'Treasure Chest', 'funny' => 'Humor', 'minigame' => 'Minijogo',
    'alchemy' => 'Alchemy (digital)', 'promo' => 'Promos', 'token' => 'Fichas', 'memorabilia' => 'Memorabilia',
];
// Prioridade para escolher a coleção-mãe quando nenhum nome coincide com o guarda-chuva.
$typeRank = static fn(string $type): int => ['main' => 0, 'draft' => 1, 'commander' => 2, 'special' => 3, 'extras' => 4][$categoryOf($type)];
$childLabels = ['Commander' => 'Commander', 'Tokens' => 'Fichas', 'Token' => 'Fichas', 'Promos' => 'Promos', 'Art Series' => 'Série de arte',
    'Front Cards' => 'Frentes', 'Substitute Cards' => 'Substitutas', 'Minigames' => 'Minijogos', 'Commander Tokens' => 'Fichas Commander',
    'Jumpstart Front Cards' => 'Jumpstart', 'Commander Edition' => 'Commander'];
$months = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];
$today = date('Y-m-d');
$fold = static fn(string $text): string => mb_strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) ?: $text);

// Agrupa códigos pela coleção que as pessoas reconhecem (ex.: DSK, DSC, TDSK, PDSK → Duskmourn).
$groups = [];
foreach ($sets as $set) {
    $set['set_type'] = (string)($set['set_type'] ?? '');
    $groups[editionUmbrella((string)$set['set_name'])][] = $set;
}
$releases = [];
foreach ($groups as $umbrella => $members) {
    usort($members, static function (array $a, array $b) use ($umbrella, $typeRank): int {
        $exact = static fn(array $s): int => strcasecmp(trim((string)$s['set_name']), trim((string)$umbrella)) === 0 ? 0 : 1;
        return [$exact($a), $typeRank($a['set_type']), (string)$a['first_release'], $a['set_code']]
            <=> [$exact($b), $typeRank($b['set_type']), (string)$b['first_release'], $b['set_code']];
    });
    $master = array_shift($members);
    usort($members, static fn(array $a, array $b): int => [(string)$a['first_release'], $a['set_code']] <=> [(string)$b['first_release'], $b['set_code']]);
    $children = array_map(static function (array $child) use ($umbrella, $childLabels, $typeLabels): array {
        $name = (string)$child['set_name'];
        $rest = stripos($name, (string)$umbrella) === 0 ? trim(substr($name, strlen((string)$umbrella)), " :-–") : '';
        $label = $childLabels[$rest] ?? ($rest !== '' ? $rest : ($typeLabels[$child['set_type']] ?? $name));
        return ['code' => (string)$child['set_code'], 'name' => $name, 'label' => $label];
    }, $members);
    $date = (string)($master['first_release'] ?? '');
    $releases[] = [
        'code' => (string)$master['set_code'], 'name' => (string)$master['set_name'], 'type' => $master['set_type'],
        'category' => $categoryOf($master['set_type']), 'date' => $date, 'year' => $date !== '' ? substr($date, 0, 4) : 'Sem data',
        'cards' => (int)$master['unique_cards'], 'future' => $date > $today,
        'spectrum' => ['W' => (int)$master['w'], 'U' => (int)$master['u'], 'B' => (int)$master['b'], 'R' => (int)$master['r'], 'G' => (int)$master['g'], 'C' => (int)$master['colorless']],
        'children' => $children,
        'search' => $fold($master['set_name'] . ' ' . $master['set_code'] . ' ' . implode(' ', array_map(static fn($c) => $c['name'] . ' ' . $c['code'], $children))),
    ];
}
usort($releases, static fn(array $a, array $b): int => [$b['date'], $a['name']] <=> [$a['date'], $b['name']]);

// Linha do tempo por ano; o espectrograma usa as cartas inéditas de cada ano.
$years = [];
$spectro = [];
$blankYear = ['cards' => 0, 'releases' => 0, 'W' => 0, 'U' => 0, 'B' => 0, 'R' => 0, 'G' => 0, 'C' => 0];
foreach ($releases as $release) {
    $years[$release['year']][] = $release;
    if ($release['year'] === 'Sem data') continue;
    $spectro[$release['year']] ??= $blankYear;
    $spectro[$release['year']]['releases']++;
}
foreach ($newCards as $row) {
    $spectro[$row['year']] ??= $blankYear;
    foreach (['cards', 'W', 'U', 'B', 'R', 'G', 'C'] as $key) $spectro[$row['year']][$key] = (int)$row[$key];
}
if ($spectro) {
    for ($y = (int)min(array_keys($spectro)); $y <= (int)max(array_keys($spectro)); $y++) {
        $spectro[(string)$y] ??= ['cards' => 0, 'releases' => 0, 'W' => 0, 'U' => 0, 'B' => 0, 'R' => 0, 'G' => 0, 'C' => 0];
    }
    ksort($spectro);
}
$maxYearCards = max(1, ...array_column($spectro, 'cards'));
$categoryCounts = array_count_values(array_column($releases, 'category'));
$firstYear = $spectro ? array_key_first($spectro) : '';
$lastYear = $spectro ? array_key_last($spectro) : '';
$colorNames = ['W' => 'branco', 'U' => 'azul', 'B' => 'preto', 'R' => 'vermelho', 'G' => 'verde', 'C' => 'incolor'];
$spectrumBar = static function (array $spectrum) use ($colorNames): string {
    $total = array_sum($spectrum);
    if (!$total) return '<span class="spectrum is-empty" aria-hidden="true"></span>';
    $parts = [];
    $html = '';
    foreach ($spectrum as $color => $count) {
        if (!$count) continue;
        $html .= '<i class="m-' . $color . '" style="flex-grow:' . $count . '"></i>';
        $parts[] = $colorNames[$color] . ' ' . round($count / $total * 100) . '%';
    }
    return '<span class="spectrum" role="img" aria-label="Cores: ' . h(implode(', ', $parts)) . '" title="' . h(implode(' · ', $parts)) . '">' . $html . '</span>';
};
$query = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']), 0, 80) : '';
$total = count($sets);

pageHeader('Edições');
?>
<div class="timeline-page" data-timeline>
<header class="timeline-intro">
    <h1>Edições</h1>
    <p><?= number_format($total, 0, ',', '.') ?> códigos de edição, de <?= h($firstYear) ?> a <?= h($lastYear) ?>, reunidos em <?= number_format(count($releases), 0, ',', '.') ?> lançamentos. Cada coluna abaixo é um ano: a altura mostra quantas cartas inéditas surgiram nele e as faixas, a proporção de cada cor de mana entre elas. Clique num ano para ir até ele.</p>
</header>

<div class="spectro-sentinel" data-spectro-sentinel aria-hidden="true"></div>
<nav class="spectro" data-spectro aria-label="Anos da linha do tempo">
    <ol class="spectro-bars">
    <?php $column = 0; foreach ($spectro as $year => $data): $column++;
        $height = $data['cards'] ? max(3, round($data['cards'] / $maxYearCards * 100, 2)) : 0;
        $colors = array_intersect_key($data, array_flip(['W', 'U', 'B', 'R', 'G', 'C']));
        $sum = array_sum($colors); ?>
        <li style="--i:<?= $column ?>"><a href="#ano-<?= h($year) ?>" data-spectro-year="<?= h($year) ?>" aria-label="<?= h($year) ?>: <?= $data['releases'] ?> lançamentos, <?= number_format($data['cards'], 0, ',', '.') ?> cartas inéditas"<?= $data['releases'] ? ' title="' . h($year . ': ' . number_format($data['cards'], 0, ',', '.') . ' cartas inéditas · ' . $data['releases'] . ' lançamentos') . '"' : ' aria-disabled="true" tabindex="-1"' ?>>
            <span class="spectro-col" style="height:<?= $height ?>%"><?php foreach (array_reverse($colors, true) as $color => $count): if (!$count) continue; ?><i class="m-<?= $color ?>" style="flex-grow:<?= $count ?>"></i><?php endforeach; ?></span>
            <span class="spectro-year<?= ((int)$year % 5 === 0 || $year === $firstYear || $year === $lastYear) ? ' is-labeled' : '' ?>"><?= h($year) ?></span>
        </a></li>
    <?php endforeach; ?>
    </ol>
    <p class="spectro-legend" aria-hidden="true"><?php foreach ($colorNames as $color => $name): ?><span><i class="m-<?= $color ?>"></i><?= h(ucfirst($name)) ?></span><?php endforeach; ?></p>
</nav>

<div class="timeline-controls">
    <label class="timeline-search"><span>Buscar edição</span><input type="search" value="<?= h($query) ?>" placeholder="Nome ou código, ex.: Duskmourn, MH3" data-timeline-search autocomplete="off"></label>
    <fieldset class="timeline-filters"><legend>Mostrar</legend>
        <?php foreach ($categories as $key => $label): ?><label><input type="checkbox" value="<?= $key ?>" data-timeline-filter<?= $key === 'extras' ? '' : ' checked' ?>><?= h($label) ?> <b><?= number_format($categoryCounts[$key] ?? 0, 0, ',', '.') ?></b></label><?php endforeach; ?>
    </fieldset>
    <p class="timeline-count" data-timeline-count role="status" aria-live="polite"></p>
</div>

<?php if (!$releases): ?><div class="empty-state"><h2>Nenhuma edição no acervo</h2><p>Importe o catálogo do Scryfall em Status para montar a linha do tempo.</p></div><?php endif; ?>

<div class="timeline">
<?php foreach ($years as $year => $items): ?>
<section class="timeline-year" id="ano-<?= h($year) ?>" data-timeline-year="<?= h($year) ?>" aria-labelledby="ano-<?= h($year) ?>-titulo">
    <h2 class="timeline-year-label" id="ano-<?= h($year) ?>-titulo"><span><?= h($year) ?></span><small data-year-count><?= count($items) ?> lançamentos</small></h2>
    <ol class="timeline-releases">
    <?php foreach ($items as $release):
        $icon = setIconUrl($release['code']);
        $url = '/edition.php?set=' . rawurlencode($release['code']);
        $day = $release['date'] !== '' ? (int)substr($release['date'], 8, 2) . ' ' . $months[(int)substr($release['date'], 5, 2) - 1] : '—'; ?>
        <li class="release is-<?= $release['category'] ?><?= $release['future'] ? ' is-future' : '' ?>" data-category="<?= $release['category'] ?>" data-search="<?= h($release['search']) ?>">
            <time datetime="<?= h($release['date']) ?>"><?= h($day) ?></time>
            <span class="release-mark"><?php if ($icon): ?><img class="set-icon" src="<?= h($icon) ?>" alt="" width="26" height="26" loading="lazy" decoding="async" onerror="this.onerror=null;this.src='/assets/set-placeholder.svg'"><?php endif; ?></span>
            <div class="release-body">
                <a class="release-name" href="<?= h($url) ?>"><?= h($release['name']) ?></a>
                <span class="release-code"><?= h(strtoupper($release['code'])) ?></span>
                <?php if ($release['future']): ?><span class="release-soon">Em breve</span><?php endif; ?>
                <?php if ($release['children']): ?><span class="release-children"><?php foreach ($release['children'] as $child): ?><a href="/edition.php?set=<?= h(rawurlencode($child['code'])) ?>" title="<?= h($child['name']) ?>"><?= h($child['label']) ?> <b><?= h(strtoupper($child['code'])) ?></b></a><?php endforeach; ?></span><?php endif; ?>
            </div>
            <span class="release-type"><?= h($typeLabels[$release['type']] ?? ($release['type'] ?: 'Outro')) ?></span>
            <?= $spectrumBar($release['spectrum']) ?>
            <span class="release-cards"><?= number_format($release['cards'], 0, ',', '.') ?> <small>cartas</small></span>
        </li>
    <?php endforeach; ?>
    </ol>
</section>
<?php endforeach; ?>
</div>
<p class="timeline-empty" data-timeline-empty hidden>Nenhuma edição corresponde à busca. Confira o nome ou o código, ou marque mais categorias em “Mostrar”.</p>
</div>
<script src="/assets/editions.js?v=<?= h((string)@filemtime(__DIR__ . '/assets/editions.js')) ?>" defer></script>
<?php pageFooter(); ?>
