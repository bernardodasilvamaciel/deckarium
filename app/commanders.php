<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require __DIR__ . '/functions.php';
require __DIR__ . '/partials.php';

$query = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']), 0, 120) : '';
$parameters = [];
$where = "c.commander_eligible AND c.layout NOT IN ('art_series', 'token', 'double_faced_token', 'emblem')";
if ($query !== '') {
    $where .= ' AND c.name ILIKE :name';
    $parameters['name'] = '%' . strtr($query, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']) . '%';
}
$statement = db()->prepare(<<<SQL
    SELECT c.* FROM (
        SELECT DISTINCT ON (COALESCE(c.oracle_id::text,c.card_faces->0->>'oracle_id',c.id::text)) c.*
        FROM cards c
        WHERE {$where}
        ORDER BY COALESCE(c.oracle_id::text,c.card_faces->0->>'oracle_id',c.id::text), (c.layout = 'reversible_card'), c.imported_at DESC, c.released_at DESC NULLS LAST, c.id
    ) c
    ORDER BY c.imported_at DESC, c.released_at DESC NULLS LAST, c.name
    LIMIT 12
SQL);
$statement->execute($parameters);
$latest = $statement->fetchAll();

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
        <label for="commander-query">Buscar comandante por nome</label>
        <div class="commander-search-controls">
            <input id="commander-query" name="q" type="search" maxlength="120" placeholder="Nome do comandante…" value="<?= h($query) ?>">
            <button class="primary-link" type="submit">Buscar</button>
            <?php if ($query !== ''): ?><a href="/commanders.php" class="commander-clear">Limpar busca</a><?php endif; ?>
        </div>
    </form>

    <div class="commander-layout">
        <section class="commander-gallery" aria-labelledby="latest-commanders">
            <div class="commander-section-heading">
                <h2 id="latest-commanders"><?= $query !== '' ? 'Resultado da busca' : 'Últimos adicionados' ?></h2>
                <p><?= $query !== '' ? 'Até 12 comandantes para “' . h($query) . '”. Refine o nome para encontrar outros.' : 'Novidades do catálogo local, sem repetir reimpressões.' ?></p>
            </div>
            <?php if ($latest): ?>
                <div class="commander-grid">
                    <?php foreach ($latest as $index => $card): $image = $commanderImage($card); ?>
                        <article class="commander-tile">
                            <a href="/card.php?id=<?= h(rawurlencode((string)$card['id'])) ?>">
                                <span class="commander-art">
                                    <?php if ($image): ?><img src="<?= h($image) ?>" alt="" width="488" height="680" decoding="async" loading="<?= $index < 3 ? 'eager' : 'lazy' ?>"><?php else: ?><span class="commander-no-image">Imagem indisponível</span><?php endif; ?>
                                </span>
                                <h3><?= h($card['name']) ?></h3>
                            </a>
                            <p><?= h($card['set_name']) ?></p>
                        </article>
                    <?php endforeach; ?>
                </div>
                <a class="commander-catalog-link" href="/?catalog=1#catalogo">Explorar o catálogo completo <span aria-hidden="true">&rarr;</span></a>
            <?php else: ?>
                <div class="commander-empty">
                    <h3><?= $query !== '' ? 'Nenhum comandante encontrado' : 'Seu catálogo ainda não tem comandantes' ?></h3>
                    <p><?= $query !== '' ? 'Tente parte do nome ou use o nome original da carta.' : 'Os comandantes aparecem aqui conforme as cartas são importadas.' ?></p>
                    <a href="<?= $query !== '' ? '/commanders.php' : '/?catalog=1#catalogo' ?>"><?= $query !== '' ? 'Ver últimos adicionados' : 'Ver catálogo' ?></a>
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
