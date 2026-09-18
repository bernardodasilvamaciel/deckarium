<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require __DIR__ . '/functions.php';
require __DIR__ . '/partials.php';
require __DIR__ . '/card_filters.php';

$query = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']), 0, 120) : '';
$sortOptions = ['new' => 'Mais novos', 'popular' => 'Mais populares', 'name' => 'Nome (A–Z)', 'added' => 'Adicionados recentemente'];
$sort = is_string($_GET['sort'] ?? null) && isset($sortOptions[$_GET['sort']]) ? $_GET['sort'] : 'new';
$perPage = 24;
$page = max(1, (int)($_GET['page'] ?? 1));
$parameters = [];
// Cartas só digitais (Alchemy/Arena) não entram: não existem para Commander de papel.
$where = "c.commander_eligible AND c.layout NOT IN ('art_series', 'token', 'double_faced_token', 'emblem') AND COALESCE(c.raw->>'digital', 'false') <> 'true'";
if ($query !== '') {
    $where .= ' AND c.name ILIKE :name';
    $parameters['name'] = '%' . strtr($query, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']) . '%';
}
// "Mais novos" usa a primeira impressão do comandante: reimpressões não o tornam novo.
$order = match ($sort) {
    'popular' => 'best_rank ASC NULLS LAST, name',
    'name' => 'name, first_released DESC NULLS LAST',
    'added' => 'last_imported DESC, first_released DESC NULLS LAST, name',
    default => 'first_released DESC NULLS LAST, name',
};
$fetchCommanders = static function (int $offset) use ($where, $parameters, $order, $perPage): array {
    $statement = db()->prepare(<<<SQL
        WITH printings AS (
            SELECT c.*, COALESCE(c.oracle_id::text,c.card_faces->0->>'oracle_id',c.id::text) AS oracle_key
            FROM cards c
            WHERE {$where}
        ), commanders AS (
            SELECT DISTINCT ON (oracle_key) p.*,
                MIN(p.released_at) OVER (PARTITION BY oracle_key) AS first_released,
                MIN(p.edhrec_rank_cached) OVER (PARTITION BY oracle_key) AS best_rank,
                MAX(p.imported_at) OVER (PARTITION BY oracle_key) AS last_imported
            FROM printings p
            ORDER BY oracle_key, (p.layout = 'reversible_card'), (p.lang <> 'en'), COALESCE(p.raw->>'promo', 'false') = 'true', p.released_at DESC NULLS LAST, p.id
        )
        SELECT c.*, COUNT(*) OVER () AS total_commanders
        FROM commanders c
        ORDER BY {$order}
        LIMIT {$perPage} OFFSET {$offset}
    SQL);
    $statement->execute($parameters);
    return $statement->fetchAll();
};
$commanders = $fetchCommanders(($page - 1) * $perPage);
if (!$commanders && $page > 1) {
    $page = 1;
    $commanders = $fetchCommanders(0);
}
$total = (int)($commanders[0]['total_commanders'] ?? 0);
$totalPages = max(1, (int)ceil($total / $perPage));

$popular = db()->query(<<<'SQL'
    SELECT c.* FROM (
        SELECT DISTINCT ON (COALESCE(c.oracle_id::text,c.card_faces->0->>'oracle_id',c.id::text)) c.*
        FROM cards c
        WHERE c.commander_eligible AND c.edhrec_rank_cached IS NOT NULL
          AND c.layout NOT IN ('art_series', 'token', 'double_faced_token', 'emblem')
        ORDER BY COALESCE(c.oracle_id::text,c.card_faces->0->>'oracle_id',c.id::text), (c.layout = 'reversible_card'), c.edhrec_rank_cached ASC, c.released_at DESC NULLS LAST, c.id
    ) c
    ORDER BY c.edhrec_rank_cached ASC, c.name
    LIMIT 8
SQL)->fetchAll();

// Prefer downloaded artwork; image.php redirects to Scryfall when it is not cached.
$commanderImage = static fn(array $card): string => cardImageUrl($card)
    ? '/image.php?id=' . rawurlencode((string)$card['id']) . '&size=normal' : '';

pageHeader('Comandantes');
?>
<div class="commander-page">
    <header class="commander-header">
        <div><h1>Comandantes</h1><p>Explore as cartas que podem liderar seu próximo deck.</p></div>
        <a class="secondary-link" href="/decks.php">Meus decks <span aria-hidden="true">&rarr;</span></a>
    </header>

    <form class="commander-search" action="/commanders.php" method="get" role="search" aria-label="Buscar comandantes">
        <div class="commander-search-controls">
            <label class="commander-field commander-field-name">Buscar comandante por nome
                <input id="commander-query" name="q" type="search" maxlength="120" placeholder="Nome do comandante…" value="<?= h($query) ?>">
            </label>
            <label class="commander-field">Ordenar por
                <select name="sort" data-auto-submit>
                    <?php foreach ($sortOptions as $value => $label): ?><option value="<?= h($value) ?>"<?= $sort === $value ? ' selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?>
                </select>
            </label>
            <button class="primary-link" type="submit">Buscar</button>
            <?php if ($query !== '' || $sort !== 'new'): ?><a href="/commanders.php" class="commander-clear">Limpar</a><?php endif; ?>
        </div>
    </form>

    <div class="commander-layout">
        <section class="commander-gallery" aria-labelledby="latest-commanders">
            <div class="commander-section-heading">
                <h2 id="latest-commanders"><?= $query !== '' ? 'Resultado da busca' : 'Todos os comandantes' ?></h2>
                <p><?= number_format($total, 0, ',', '.') ?> <?= $total === 1 ? 'comandante' : 'comandantes' ?><?= $query !== '' ? ' para “' . h($query) . '”' : ' no catálogo' ?>, sem repetir reimpressões.</p>
            </div>
            <?php if ($commanders): ?>
                <div class="commander-grid">
                    <?php foreach ($commanders as $index => $card): $image = $commanderImage($card); ?>
                        <article class="commander-tile">
                            <a href="/card.php?id=<?= h(rawurlencode((string)$card['id'])) ?>">
                                <span class="commander-art">
                                    <?php if ($image): ?><img src="<?= h($image) ?>" alt="" width="488" height="680" decoding="async" loading="<?= $index < 3 ? 'eager' : 'lazy' ?>"><?php else: ?><span class="commander-no-image">Imagem indisponível</span><?php endif; ?>
                                </span>
                                <h3><?= h($card['name']) ?></h3>
                            </a>
                            <p><?= h($card['set_name']) ?><?php if ($sort === 'new' && $card['first_released']): ?> · desde <?= h(substr((string)$card['first_released'], 0, 4)) ?><?php elseif ($sort === 'popular' && $card['best_rank'] !== null): ?> · EDHREC #<?= number_format((int)$card['best_rank'], 0, ',', '.') ?><?php endif; ?></p>
                        </article>
                    <?php endforeach; ?>
                </div>
                <?php if ($totalPages > 1) numberedPager($page, $totalPages, array_filter(['q' => $query, 'sort' => $sort === 'new' ? '' : $sort], 'strlen'), '#latest-commanders'); ?>
            <?php else: ?>
                <div class="commander-empty">
                    <h3><?= $query !== '' ? 'Nenhum comandante encontrado' : 'Seu catálogo ainda não tem comandantes' ?></h3>
                    <p><?= $query !== '' ? 'Tente parte do nome ou use o nome original da carta.' : 'Os comandantes aparecem aqui conforme as cartas são importadas.' ?></p>
                    <a href="<?= $query !== '' ? '/commanders.php' : '/?catalog=1#catalogo' ?>"><?= $query !== '' ? 'Ver todos os comandantes' : 'Ver catálogo' ?></a>
                </div>
            <?php endif; ?>
        </section>

        <aside class="commander-popular" aria-labelledby="popular-commanders">
            <div class="commander-section-heading">
                <h2 id="popular-commanders">Mais populares</h2>
                <p>Entre os comandantes do catálogo.</p>
            </div>
            <details class="commander-ranking-help">
                <summary>Como funciona esta lista?</summary>
                <p>Usamos a posição geral da carta no EDHREC, disponível na última importação. Quanto menor o número, mais popular a carta. Não é um ranking específico de uso como comandante.</p>
            </details>
            <?php if ($popular): ?>
                <ol class="commander-ranking">
                    <?php foreach ($popular as $index => $card): $image = $commanderImage($card); ?>
                        <li>
                            <a href="/card.php?id=<?= h(rawurlencode((string)$card['id'])) ?>">
                                <span class="commander-position" aria-hidden="true"><?= $index + 1 ?></span>
                                <span class="commander-thumbnail"><?php if ($image): ?><img loading="lazy" decoding="async" width="488" height="680" src="<?= h($image) ?>" alt=""><?php endif; ?></span>
                                <span class="commander-ranked-name"><strong><?= h($card['name']) ?></strong><small>EDHREC #<?= number_format((int)$card['edhrec_rank_cached'], 0, ',', '.') ?></small></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php else: ?><p class="commander-empty">Ainda não há dados de popularidade. As posições aparecem após uma importação com dados do EDHREC.</p><?php endif; ?>
        </aside>
    </div>
</div>
<?php pageFooter(); ?>
