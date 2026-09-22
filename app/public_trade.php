<?php
declare(strict_types=1);
/**
 * Página pública de venda e troca, aberta por link (token) e sem exigir conta.
 * Mostra as cartas disponíveis, quantidades, preços e o contato do dono.
 */
require __DIR__ . '/functions.php';
require __DIR__ . '/partials.php';
require __DIR__ . '/deck_library.php';
require __DIR__ . '/profile_lib.php';
require __DIR__ . '/trade_lib.php';
require __DIR__ . '/card_filters.php';
deckSchema();
profileSchema();
$viewer = authUser();
session_write_close();

$token = is_string($_GET['t'] ?? null) ? trim($_GET['t']) : '';
$list = tradeListByToken($token);
$isOwner = $list && $viewer && (int)$viewer['id'] === (int)$list['user_id'];
if (!$list || !$list['is_active'] || (!tradeIsPublic($list) && !$isOwner)) {
    http_response_code(404);
    pageHeader('Lista não encontrada');
    echo '<section class="empty-state"><h2>Lista não encontrada</h2><p>Este link não existe mais ou foi desligado pelo dono.</p><p><a href="/public.php">Ver a comunidade</a></p></section>';
    pageFooter();
    exit;
}

$owner = ['username' => $list['username'], 'display_name' => $list['display_name'], 'full_name' => $list['full_name'], 'avatar_file' => $list['avatar_file'], 'id' => $list['user_id']];
$showPrices = deckIsFoil($list['show_prices']);
$allRows = tradeCards($list, true);
$rows = $allRows;
// Mesmos filtros do catálogo (nome, Oracle, cores, tipo...), aplicados às cartas da lista.
$f = cardFilters();
$finish = in_array($_GET['finish'] ?? '', ['foil', 'normal'], true) ? (string)$_GET['finish'] : '';
if ($finish !== '') $rows = array_values(array_filter($rows, static fn(array $row): bool => $row['is_foil'] === ($finish === 'foil')));
[$whereSql, $params] = cardFilterSql($f);
if ($whereSql !== '' && $rows) {
    $params[] = '{' . implode(',', array_unique(array_map(static fn(array $row): string => (string)$row['id'], $rows))) . '}';
    $match = array_flip(deckQuery("SELECT c.id::text FROM cards c {$whereSql} AND c.id = ANY(?::uuid[])", $params)->fetchAll(PDO::FETCH_COLUMN));
    $rows = array_values(array_filter($rows, static fn(array $row): bool => isset($match[(string)$row['id']])));
}
$filtered = $whereSql !== '' || $finish !== '';
$sort = in_array($_GET['sort'] ?? '', ['price_desc', 'price_asc', 'name'], true) ? (string)$_GET['sort'] : 'name';
usort($rows, static function (array $a, array $b) use ($sort): int {
    if ($sort === 'name') return [mb_strtolower((string)$a['name']), $a['set_code']] <=> [mb_strtolower((string)$b['name']), $b['set_code']];
    $priceA = $a['final_price'] ?? -1;
    $priceB = $b['final_price'] ?? -1;
    return $sort === 'price_desc' ? $priceB <=> $priceA : $priceA <=> $priceB;
});
$summary = tradeSummary($allRows);
$shown = tradeSummary($rows);
$linkParams = array_filter(['t' => $list['token']] + cardFilterQuery($f) + ['finish' => $finish, 'sort' => $sort === 'name' ? '' : $sort], static fn($v) => $v !== '');

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="cartas-a-venda-' . preg_replace('/[^a-z0-9]+/i', '-', (string)$list['username']) . '.csv"');
    $out = fopen('php://output', 'w');
    $header = ['Name', 'Scryfall ID', 'Quantity', 'Foil', 'Edição', 'Código', 'Número', 'Idioma'];
    if ($showPrices) $header[] = 'Preço (R$)';
    $header[] = 'Observação';
    fputcsv($out, $header, ',', '"', '');
    foreach ($rows as $row) {
        $line = [$row['name'], $row['id'], $row['available'], $row['is_foil'] ? 'foil' : 'normal', $row['set_name'], strtoupper((string)$row['set_code']), $row['collector_number'], strtoupper((string)$row['lang'])];
        if ($showPrices) $line[] = $row['final_price'] === null ? '' : number_format((float)$row['final_price'], 2, ',', '');
        $line[] = (string)($row['note'] ?? '');
        fputcsv($out, $line, ',', '"', '');
    }
    fclose($out);
    exit;
}

$title = trim((string)$list['title']) !== '' ? (string)$list['title'] : 'Cartas à venda de @' . $list['username'];
$avatar = profileAvatarUrl($owner);
pageHeader($title, 'Cartas de Magic à venda e para troca na lista de @' . $list['username'] . '.', ['noindex' => true]);
?>
<div class="public-page trade-public">
<?php if (!tradeIsPublic($list)): ?><p class="notice warning">Prévia: seu link está desligado. Ative em <a href="/trade.php">À venda</a> para compartilhar.</p><?php endif; ?>

<section class="hero trade-hero">
    <div>
        <p class="public-kicker">Venda e troca</p>
        <h1><?= h($title) ?></h1>
        <p><?= number_format($summary['copies'], 0, ',', '.') ?> <?= $summary['copies'] === 1 ? 'carta disponível' : 'cartas disponíveis' ?> · <?= number_format($summary['printings'], 0, ',', '.') ?> <?= $summary['printings'] === 1 ? 'versão' : 'versões' ?><?= $showPrices && $summary['total'] > 0 ? ' · R$ ' . number_format($summary['total'], 2, ',', '.') . ' no total' : '' ?></p>
    </div>
    <a class="trade-seller" href="/profile.php?u=<?= h(rawurlencode((string)$list['username'])) ?>">
        <span class="player-avatar"><?php if ($avatar): ?><img src="<?= h($avatar) ?>" alt="" width="48" height="48"><?php else: ?><?= h(profileInitials($owner)) ?><?php endif; ?></span>
        <span class="player-copy"><strong><?= h(profileName($owner)) ?></strong><small>@<?= h($list['username']) ?></small></span>
    </a>
</section>

<?php if (trim((string)$list['intro']) !== '' || trim((string)$list['contact']) !== ''): ?>
<section class="panel trade-contact">
    <?php if (trim((string)$list['intro']) !== ''): ?><p class="trade-intro-text"><?= nl2br(h((string)$list['intro'])) ?></p><?php endif; ?>
    <?php if (trim((string)$list['contact']) !== ''): ?><p class="trade-contact-line"><span>Contato</span><strong><?= h((string)$list['contact']) ?></strong></p><?php endif; ?>
</section>
<?php endif; ?>

<?php
// Acabamento e ordem ficam à vista, ao lado do botão de filtros.
$keep = array_diff_key($linkParams, ['finish' => 1, 'sort' => 1]);
ob_start(); ?>
<form class="filters-sort collection-controls" method="get" action="/public_trade.php">
    <?php foreach ($keep as $key => $value): foreach (is_array($value) ? $value : [$value] as $v): ?><input type="hidden" name="<?= h($key . (is_array($value) ? '[]' : '')) ?>" value="<?= h((string)$v) ?>"><?php endforeach; endforeach; ?>
    <label>Acabamento<select name="finish" data-auto-submit><option value="">Todos</option><option value="normal" <?= $finish === 'normal' ? 'selected' : '' ?>>Somente normais</option><option value="foil" <?= $finish === 'foil' ? 'selected' : '' ?>>Somente foil</option></select></label>
    <label>Ordenar<select name="sort" data-auto-submit>
        <option value="name" <?= $sort === 'name' ? 'selected' : '' ?>>Nome</option>
        <option value="price_desc" <?= $sort === 'price_desc' ? 'selected' : '' ?>>Maior preço</option>
        <option value="price_asc" <?= $sort === 'price_asc' ? 'selected' : '' ?>>Menor preço</option>
    </select></label>
</form>
<div class="collection-actions">
    <?php if ($filtered): ?><button type="button" class="secondary-link" data-copy-share="/public_trade.php?<?= h(http_build_query($linkParams)) ?>">Copiar link desta busca</button><?php endif; ?>
    <?php if ($rows): ?><a class="secondary-link" href="/public_trade.php?<?= h(http_build_query($linkParams + ['export' => 'csv'])) ?>" download>Baixar CSV</a><?php endif; ?>
</div>
<?php
$controls = ob_get_clean();
$setRows = [];
foreach ($allRows as $row) $setRows[(string)$row['set_code']] = ['set_code' => (string)$row['set_code'], 'set_name' => (string)$row['set_name']];
uasort($setRows, static fn(array $a, array $b): int => strcmp($a['set_name'], $b['set_name']));
cardFilterForm($f, array_values($setRows), '/public_trade.php', '', $controls, array_intersect_key($linkParams, ['t' => 1, 'finish' => 1, 'sort' => 1]));
?>
<?php if ($filtered): ?><p class="muted"><?= number_format($shown['copies'], 0, ',', '.') ?> <?= $shown['copies'] === 1 ? 'carta' : 'cartas' ?> com estes filtros<?= $showPrices && $shown['total'] > 0 ? ' · R$ ' . number_format($shown['total'], 2, ',', '.') : '' ?>.</p><?php endif; ?>

<?php if (!$rows): ?>
<p class="empty-state"><?= $filtered ? 'Nenhuma carta da lista com esses filtros.' : 'Nenhuma carta disponível nesta lista agora.' ?></p>
<?php endif; ?>

<div class="public-card-grid is-large trade-grid">
<?php foreach ($rows as $row): $src = cardImageUrl($row, 'front', 'small'); ?>
    <article class="public-card trade-card<?= $row['is_foil'] ? ' is-foil' : '' ?>">
        <a class="public-card-art" href="/card.php?id=<?= h(rawurlencode((string)$row['id'])) ?>">
            <?php if ($src): ?><img src="<?= h($src) ?>" alt="<?= h((string)$row['name']) ?>" loading="lazy" width="146" height="204"><?php endif; ?>
            <b><?= (int)$row['available'] ?>×</b>
        </a>
        <a class="public-card-name" href="/card.php?id=<?= h(rawurlencode((string)$row['id'])) ?>"><?= h($row['name']) ?></a>
        <small><?= h(strtoupper((string)$row['set_code'])) ?> #<?= h((string)$row['collector_number']) ?> · <?= $row['is_foil'] ? 'foil' : 'normal' ?> · <?= h(strtoupper((string)$row['lang'])) ?></small>
        <?php if ($showPrices): ?><strong class="trade-card-price"><?= h(tradePriceLabel($row['final_price'])) ?></strong><?php endif; ?>
        <?php if (trim((string)($row['note'] ?? '')) !== ''): ?><small class="trade-card-note"><?= h((string)$row['note']) ?></small><?php endif; ?>
    </article>
<?php endforeach; ?>
</div>
<?php if ($showPrices && $rows): ?><p class="muted trade-footnote">Preços sem valor informado saem como “a combinar”; os demais seguem o que o dono da lista definiu ou a referência do Scryfall convertida em reais.</p><?php endif; ?>
</div>
<?php pageFooter(); ?>
