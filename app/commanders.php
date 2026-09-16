<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require __DIR__ . '/functions.php';
require __DIR__ . '/partials.php';

$latest = db()->query(<<<'SQL'
    SELECT c.* FROM (
        SELECT DISTINCT ON (COALESCE(c.oracle_id,c.id)) c.*
        FROM cards c
        WHERE c.commander_eligible
        ORDER BY COALESCE(c.oracle_id,c.id), c.imported_at DESC, c.released_at DESC NULLS LAST, c.id
    ) c
    ORDER BY c.imported_at DESC, c.released_at DESC NULLS LAST, c.name
    LIMIT 12
SQL)->fetchAll();

$popular = db()->query(<<<'SQL'
    SELECT c.* FROM (
        SELECT DISTINCT ON (COALESCE(c.oracle_id,c.id)) c.*
        FROM cards c
        WHERE c.commander_eligible AND c.edhrec_rank_cached IS NOT NULL
        ORDER BY COALESCE(c.oracle_id,c.id), c.edhrec_rank_cached ASC, c.released_at DESC NULLS LAST, c.id
    ) c
    ORDER BY c.edhrec_rank_cached ASC, c.name
    LIMIT 12
SQL)->fetchAll();

$commanderTile = static function (array $card, bool $showRank = false): void {
    $url = '/card.php?id=' . rawurlencode((string)$card['id']);
    $image = cardImageUrl($card);
    ?>
    <article class="commander-tile">
        <a class="commander-art" href="<?= h($url) ?>"><?php if ($image): ?><img loading="lazy" decoding="async" width="488" height="680" src="<?= h($image) ?>" alt="<?= h($card['name']) ?>"><?php else: ?><span class="placeholder"><strong><?= h($card['name']) ?></strong><span>Imagem indisponível</span></span><?php endif; ?></a>
        <div><a href="<?= h($url) ?>"><?= h($card['name']) ?></a><small><?= h($card['type_line'] ?: 'Tipo não informado') ?></small><?php if ($showRank): ?><span>#<?= number_format((int)$card['edhrec_rank_cached'], 0, ',', '.') ?> no EDHREC</span><?php else: ?><span>Adicionada em <?= h(displayDate((string)$card['imported_at'])) ?></span><?php endif; ?></div>
    </article>
    <?php
};

pageHeader('Comandantes');
?>
<section class="hero commander-hero"><div><h1>Encontre quem lidera seu deck.</h1><p>Comandantes elegíveis no seu acervo local, organizados pelo que acabou de entrar e pelo que mais aparece no EDHREC.</p></div><a class="primary-link" href="/decks.php">Criar um deck</a></section>

<section class="commander-section" aria-labelledby="latest-commanders"><div class="section-heading"><div><h2 id="latest-commanders">Últimos adicionados</h2><p class="muted">As entradas mais recentes no acervo local, sem repetir reimpressões.</p></div></div><?php if ($latest): ?><div class="commander-grid"><?php foreach ($latest as $card) $commanderTile($card); ?></div><?php else: ?><p class="empty-state">Os comandantes aparecerão após a sincronização do catálogo.</p><?php endif; ?></section>

<section class="commander-section" aria-labelledby="popular-commanders"><div class="section-heading"><div><h2 id="popular-commanders">Mais populares</h2><p class="muted">Ordenados pela posição EDHREC disponível no acervo. Uma posição menor indica mais uso.</p></div><a href="/?catalog=1#catalogo">Ver catálogo</a></div><?php if ($popular): ?><div class="commander-grid"><?php foreach ($popular as $card) $commanderTile($card, true); ?></div><?php else: ?><p class="empty-state">Ainda não há posições EDHREC disponíveis para os comandantes deste acervo.</p><?php endif; ?></section>
<?php pageFooter(); ?>
