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
$rows = tradeCards($list, true);
$query = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']), 0, 120) : '';
if ($query !== '') {
    $needle = mb_strtolower($query);
    $rows = array_values(array_filter($rows, static fn(array $row): bool => str_contains(mb_strtolower((string)$row['name']), $needle)
        || str_contains(mb_strtolower((string)$row['set_name']), $needle)));
}
$sort = in_array($_GET['sort'] ?? '', ['price_desc', 'price_asc', 'name'], true) ? (string)$_GET['sort'] : 'name';
usort($rows, static function (array $a, array $b) use ($sort): int {
    if ($sort === 'name') return [mb_strtolower((string)$a['name']), $a['set_code']] <=> [mb_strtolower((string)$b['name']), $b['set_code']];
    $priceA = $a['final_price'] ?? -1;
    $priceB = $b['final_price'] ?? -1;
    return $sort === 'price_desc' ? $priceB <=> $priceA : $priceA <=> $priceB;
});
$summary = tradeSummary($rows);

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

<form class="public-search trade-search" method="get" role="search">
    <input type="hidden" name="t" value="<?= h((string)$list['token']) ?>">
    <label class="field">Buscar na lista<input type="search" name="q" value="<?= h($query) ?>" placeholder="Nome da carta ou edição"></label>
    <label class="field">Ordenar<select name="sort" onchange="this.form.submit()">
        <option value="name" <?= $sort === 'name' ? 'selected' : '' ?>>Nome</option>
        <option value="price_desc" <?= $sort === 'price_desc' ? 'selected' : '' ?>>Maior preço</option>
        <option value="price_asc" <?= $sort === 'price_asc' ? 'selected' : '' ?>>Menor preço</option>
    </select></label>
    <button class="primary-link">Buscar</button>
    <?php if ($rows): ?><a class="secondary-link" href="/public_trade.php?<?= h(http_build_query(array_filter(['t' => $list['token'], 'q' => $query, 'sort' => $sort, 'export' => 'csv']))) ?>" download>Baixar CSV</a><?php endif; ?>
</form>

<?php if (!$rows): ?>
<p class="empty-state"><?= $query !== '' ? 'Nenhuma carta com esse nome na lista.' : 'Nenhuma carta disponível nesta lista agora.' ?></p>
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
