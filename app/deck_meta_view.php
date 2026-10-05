<?php
declare(strict_types=1);
/**
 * Meta do formato (aba "Meta do formato" dos construídos) e guia dos formatos com líder sem EDHREC (Oathbreaker).
 * Incluído por decks.php. Os dados vêm de deck_meta.php (listas públicas do MTGO).
 */
$metaAvailable = metaSlug($fmt) !== null;
$metaState = $metaAvailable ? ($metaStatus ?? metaEnsure($fmt)) : null;
$metaHasData = $metaState && $metaState['decks'] > 0;
$metaNumber = static fn($value): string => number_format((float)$value, 0, ',', '.');
$metaPercent = static fn(float $value): string => max($value > 0 ? 1 : 0, (int)round($value * 100)) . '%';
$metaMine = deckMetaMine($items);
$metaStatsView = $metaHasData ? metaCardStats($fmt['key']) : ['total' => 0, 'cards' => []];
$metaSimilar = $metaHasData && $metaMine ? array_values(array_filter(metaSimilarDecks($fmt['key'], $metaMine, 6), fn($row) => $row['similarity'] > 0.04)) : [];
$metaArchetypeRows = $metaHasData ? metaArchetypes($fmt['key'], 14, (int)$id) : [];
$metaCoverageRows = $metaHasData ? metaCoverage($fmt['key'], (int)$id) : [];
$metaCoverageById = [];
foreach ($metaCoverageRows as $row) $metaCoverageById[(int)$row['id']] = $row;
// Um deck por arquétipo no "dá para montar": o de maior cobertura de cada um.
$metaBuildable = [];
foreach ($metaCoverageRows as $row) { if (!isset($metaBuildable[$row['archetype']])) $metaBuildable[$row['archetype']] = $row; if (count($metaBuildable) >= 6) break; }
$metaSelectedLogical = [];
foreach ($items as $item) $metaSelectedLogical[(string)($item['oracle_id'] ?: $item['id'])] = $item['stage'];
$metaColorPips = static fn(string $colors): string => $colors === '' ? '<span class="meta-colorless">Incolor</span>' : manaSymbols(implode('', array_map(fn($c) => '{' . $c . '}', str_split($colors))));

/** Lista de um deck do meta, com o que está na coleção e o que falta. */
$metaDeckList = function (int $metaDeckId) use ($metaSelectedLogical, $id): void {
    $cards = metaDeckCards($metaDeckId, (int)$id);
    $sections = ['Deck' => [], 'Sideboard' => []];
    foreach ($cards as $card) $sections[in_array($card['sideboard'], [true, 't', 1, '1'], true) ? 'Sideboard' : 'Deck'][] = $card;
    echo '<div class="meta-decklist">';
    foreach ($sections as $label => $rows) {
        if (!$rows) continue;
        echo '<div><h5>' . h($label) . ' <small>' . array_sum(array_column($rows, 'quantity')) . '</small></h5><ul>';
        foreach ($rows as $row) {
            $basic = in_array($row['basic'], [true, 't', 1, '1'], true);
            $free = $basic ? (int)$row['quantity'] : min((int)$row['quantity'], (int)$row['free']);
            $state = $free >= (int)$row['quantity'] ? 'is-owned' : ($free > 0 || (int)$row['owned'] > 0 ? 'is-partial' : 'is-missing');
            $inDeck = isset($metaSelectedLogical[(string)$row['logical_id']]);
            $preview = $row['card_id'] ? ' data-preview="/image.php?id=' . h($row['card_id']) . '&amp;size=normal"' : '';
            echo '<li class="' . $state . '"' . $preview . '><b>' . (int)$row['quantity'] . '</b><span>' . ($row['card_id'] ? '<a href="/card.php?id=' . h($row['card_id']) . '">' . h($row['name']) . '</a>' : h($row['name'])) . '</span>'
                . '<small>' . ($basic ? 'básico' : ($free >= (int)$row['quantity'] ? 'na coleção' : ($free > 0 ? $free . ' de ' . (int)$row['quantity'] . ' livres' : ((int)$row['owned'] > 0 ? 'em outros decks' : 'falta')))) . ($inDeck ? ' · na seleção' : '') . '</small></li>';
        }
        echo '</ul></div>';
    }
    echo '</div>';
};

/** Ações de um deck do meta: levar o que falta para as candidatas e copiar como deck novo. */
$metaDeckActions = function (array $row, bool $missing = true) use ($tokenFields, $csrf, $fmt): void { ?>
    <div class="meta-deck-actions">
        <?php if ($missing): ?><form method="post"><?php $tokenFields('meta_missing'); ?><input type="hidden" name="meta_deck" value="<?= (int)$row['id'] ?>"><button class="secondary-link">Levar o que falta para as candidatas</button></form><?php endif; ?>
        <form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="import_meta"><input type="hidden" name="meta_deck" value="<?= (int)$row['id'] ?>"><button class="secondary-link">Copiar como deck novo</button></form>
    </div>
<?php };
?>
<section class="meta-guide" id="meta-guide">
<?php if (!$metaAvailable): ?>
    <header class="meta-hero">
        <div><span class="guide-kicker"><?= h(deckGuideLabel($fmt)) ?></span><h2><?= h($fmt['name']) ?></h2><p><?= h($fmt['summary']) ?></p></div>
    </header>
    <div class="meta-rules">
        <article><h3>Construção</h3><p><?= h($fmt['leader_rule'] ?? '') ?> <?= (int)$fmt['size'] ?> cartas no total, uma de cada (básicos à parte), todas na identidade de cor <?= $fmt['leader'] === 'oathbreaker' ? 'do oathbreaker' : 'da comandante' ?>.</p></article>
        <article><h3>Partida</h3><p><?= (int)$fmt['life'] ?> de vida<?= !empty($fmt['life_multiplayer']) ? ' (' . (int)$fmt['life_multiplayer'] . ' com mais de dois jogadores)' : '' ?>. <?= $fmt['commander_damage'] ? 'Dano de comandante: ' . (int)$fmt['commander_damage'] . '.' : 'Sem derrota por dano de comandante.' ?> <?= $fmt['leader'] === 'oathbreaker' ? 'O feitiço de assinatura só pode ser lançado enquanto o oathbreaker estiver no campo e volta para a zona de comando; os dois pagam o imposto de {2} a cada vez.' : '' ?></p></article>
        <article><h3>Dados</h3><p>Não há uma fonte pública com as listas deste formato (o EDHREC não abre os dados do <?= h($fmt['name']) ?> e o MTGO não o organiza). As metas usam a base de <?= (int)$fmt['size'] ?> cartas, as relações do quadro e a sua coleção.</p></article>
    </div>
    <p class="meta-next"><a class="primary-link" href="?deck=<?= (int)$id ?>&amp;view=explore&amp;sort=fit">Cartas da coleção que encaixam</a> <a class="secondary-link" href="?deck=<?= (int)$id ?>&amp;view=needs">O que falta</a></p>
<?php else: ?>
    <header class="meta-hero">
        <div>
            <span class="guide-kicker">Meta do formato</span>
            <h2><?= h($fmt['name']) ?> no MTGO</h2>
            <p><?php if ($metaHasData): ?><b><?= $metaNumber($metaState['decks']) ?></b> listas de <b><?= (int)$metaState['events'] ?></b> torneios recentes (Challenges, qualifiers e ligas 5-0)<?= $metaState['synced_at'] ? ', atualizadas em ' . date('d/m/Y H:i', $metaState['synced_at']) : '' ?>.<?php elseif ($metaState['running']): ?>Buscando as listas recentes do MTGO. Leva um ou dois minutos: recarregue a página depois.<?php else: ?>O meta ainda não foi carregado<?= $metaState['message'] ? ' (' . h($metaState['message']) . ')' : '' ?>.<?php endif; ?></p>
        </div>
        <div class="meta-hero-actions">
            <form method="post"><?php $tokenFields('sync_meta'); ?><button class="secondary-link" <?= $metaState['running'] ? 'disabled' : '' ?>><?= $metaState['running'] ? 'Atualizando…' : 'Atualizar agora' ?></button></form>
            <small>Fonte: <a href="https://www.mtgo.com/decklists" rel="noopener" target="_blank">mtgo.com/decklists</a>, as listas que a Wizards publica.</small>
        </div>
    </header>

    <?php if (!$metaHasData): ?>
    <p class="empty-state">Assim que as listas chegarem, esta aba mostra os arquétipos do <?= h($fmt['name']) ?>, os decks parecidos com o seu, o que dá para montar com a sua coleção e as cartas mais jogadas.</p>
    <?php else: ?>

    <?php if ($metaSimilar): ?>
    <section class="meta-block">
        <div class="meta-block-head"><h3>Decks do meta parecidos com o seu</h3><p>Pelas cartas em comum (sem básicos), com as cópias. Abra a lista para ver o que já está na sua coleção.</p></div>
        <div class="meta-similar">
            <?php foreach ($metaSimilar as $row): $coverage = $metaCoverageById[(int)$row['id']] ?? null; ?>
            <article class="meta-deck">
                <header><span class="meta-deck-colors"><?= $metaColorPips((string)$row['colors']) ?></span><strong><?= h($row['archetype'] ?: 'Deck sem nome') ?></strong><b class="meta-similarity" title="Cartas em comum (com as cópias) sobre as cartas dos dois decks"><?= $metaPercent((float)$row['similarity']) ?> parecido</b></header>
                <p class="meta-deck-source"><?= h($row['player']) ?> · <?= h($row['event_name']) ?> · <?= h(date('d/m', strtotime((string)$row['event_date']))) ?><?= ($result = metaDeckResult($row)) !== '' ? ' · <b>' . h($result) . '</b>' : '' ?></p>
                <?php if ($coverage): ?><p class="meta-coverage"><span class="meta-bar"><i style="width:<?= (int)round($coverage['coverage'] * 100) ?>%"></i></span><span>Você tem <b><?= (int)$coverage['have'] ?></b> de <?= (int)$coverage['main_count'] ?> cartas livres na coleção</span></p><?php endif; ?>
                <details><summary>Ver a lista</summary><?php $metaDeckList((int)$row['id']); ?></details>
                <?php $metaDeckActions($row); ?>
            </article>
            <?php endforeach; ?>
        </div>
    </section>
    <?php elseif (!$metaMine): ?>
    <p class="meta-start">Seu deck ainda não tem cartas aprovadas. Comece de um arquétipo abaixo (“Copiar como deck novo” ou “Levar o que falta para as candidatas”) ou aprove algumas cartas para ver os decks do meta mais parecidos com o seu.</p>
    <?php endif; ?>

    <section class="meta-block">
        <div class="meta-block-head"><h3>Arquétipos</h3><p>Batizados pelas cores e pela carta que define o deck. A barra é a participação no meta; a coluna da coleção, quanto do melhor deck do arquétipo você já tem livre.</p></div>
        <div class="meta-archetypes" role="table" aria-label="Arquétipos do meta">
            <div class="meta-archetype is-head" role="row"><span role="columnheader">Arquétipo</span><span role="columnheader">Meta</span><span role="columnheader">Resultado</span><span role="columnheader">Sua coleção</span></div>
            <?php foreach ($metaArchetypeRows as $row): $best = $row['best']; $sample = (int)($best['id'] ?? $row['sample_id']); ?>
            <details class="meta-archetype-row">
                <summary class="meta-archetype" role="row">
                    <span role="cell"><span class="meta-deck-colors"><?= $metaColorPips((string)$row['colors']) ?></span><strong><?= h($row['archetype']) ?></strong></span>
                    <span role="cell" class="meta-share"><span class="meta-bar"><i style="width:<?= min(100, (int)round($row['share'] * 250)) ?>%"></i></span><b><?= $metaPercent((float)$row['share']) ?></b><small><?= (int)$row['decks'] ?> deck<?= (int)$row['decks'] === 1 ? '' : 's' ?></small></span>
                    <span role="cell" class="meta-record"><?= (int)$row['wins'] + (int)$row['losses'] > 0 ? (int)$row['wins'] . '-' . (int)$row['losses'] . ' <small>(' . $metaPercent((int)$row['wins'] / max(1, (int)$row['wins'] + (int)$row['losses'])) . ')</small>' : '—' ?></span>
                    <span role="cell" class="meta-own"><?php if ($best): ?><span class="meta-bar is-own"><i style="width:<?= (int)round($best['coverage'] * 100) ?>%"></i></span><b><?= $metaPercent((float)$best['coverage']) ?></b><?php else: ?>—<?php endif; ?></span>
                </summary>
                <div class="meta-archetype-body">
                    <?php $sampleRow = $metaCoverageById[$sample] ?? metaDeck($sample); if ($sampleRow): ?><p class="meta-deck-source">Lista de <?= h((string)$sampleRow['player']) ?> · <?= h((string)$sampleRow['event_name']) ?><?= ($result = metaDeckResult($sampleRow)) !== '' ? ' · <b>' . h($result) . '</b>' : '' ?></p><?php endif; ?>
                    <?php $metaDeckList($sample); ?>
                    <?php $metaDeckActions(['id' => $sample]); ?>
                </div>
            </details>
            <?php endforeach; ?>
        </div>
    </section>

    <?php if ($metaBuildable): ?>
    <section class="meta-block">
        <div class="meta-block-head"><h3>O que dá para montar com a sua coleção</h3><p>Listas do meta com a maior parte das cartas livres na sua coleção (fora de outros decks). Básicos contam como tidos.</p></div>
        <div class="meta-buildable">
            <?php foreach ($metaBuildable as $row): ?>
            <article class="meta-deck is-compact">
                <header><span class="meta-deck-colors"><?= $metaColorPips((string)$row['colors']) ?></span><strong><?= h($row['archetype']) ?></strong></header>
                <p class="meta-coverage"><span class="meta-bar is-own"><i style="width:<?= (int)round($row['coverage'] * 100) ?>%"></i></span><span><b><?= $metaPercent((float)$row['coverage']) ?></b> · faltam <?= max(0, (int)$row['main_count'] - (int)$row['have']) ?> cartas</span></p>
                <p class="meta-deck-source"><?= h($row['player']) ?> · <?= h($row['event_name']) ?><?= ($result = metaDeckResult($row)) !== '' ? ' · ' . h($result) : '' ?></p>
                <details><summary>Ver a lista</summary><?php $metaDeckList((int)$row['id']); ?></details>
                <?php $metaDeckActions($row); ?>
            </article>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <?php
    $staples = array_filter($metaStatsView['cards'], fn($row) => !$row['basic'] && (!$identity || true));
    uasort($staples, fn($a, $b) => $b['share'] <=> $a['share']);
    $staples = array_slice($staples, 0, 36, true);
    $stapleCards = [];
    if ($staples) {
        foreach (deckQuery("SELECT DISTINCT ON (COALESCE(c.oracle_id,c.id)) COALESCE(c.oracle_id,c.id)::text lid, c.*, COALESCE(bc.quantity,0) owned_printing
            FROM cards c LEFT JOIN " . deckCollectionPrintingSql() . " bc ON bc.scryfall_id=c.id WHERE COALESCE(c.oracle_id,c.id) = ANY(?::uuid[])
            ORDER BY COALESCE(c.oracle_id,c.id), (COALESCE(bc.quantity,0)>0) DESC, (c.lang='en') DESC, (c.local_image IS NOT NULL) DESC, c.released_at DESC NULLS LAST", ['{' . implode(',', array_keys($staples)) . '}'])->fetchAll() as $row) $stapleCards[$row['lid']] = $row;
    }
    $ownedMap = deckOwnedLogicalMap();
    ?>
    <section class="meta-block">
        <div class="meta-block-head"><h3>Cartas mais jogadas</h3><p>Em quantos decks do meta cada carta aparece e com quantas cópias, em média. “Adicionar” manda para as candidatas já com essas cópias.</p></div>
        <div class="meta-staples">
            <?php foreach ($staples as $lid => $stat): $card = $stapleCards[$lid] ?? null; if (!$card) continue; $owned = (int)($ownedMap[$lid] ?? 0); $inSelection = $metaSelectedLogical[$lid] ?? null; ?>
            <article class="meta-staple">
                <a href="/card.php?id=<?= h($card['id']) ?>"><img src="<?= h(cardImageUrl($card, 'front', 'small') ?? '') ?>" alt="<?= h($card['name']) ?>" loading="lazy" width="146" height="204"></a>
                <div><strong><?= h($card['name']) ?></strong>
                    <span class="meta-staple-stat"><b><?= $metaPercent((float)$stat['share']) ?></b> dos decks · <?= number_format((float)$stat['avg'], 1, ',', '.') ?> cópias</span>
                    <small class="<?= $owned ? 'is-owned' : '' ?>"><?= $owned ? $owned . ' na coleção' : 'Fora da coleção' ?></small>
                    <?php if ($inSelection): ?><small class="builder-selection-status"><?= h(['candidate'=>'Já nas candidatas','deck'=>'Já no deck','sideboard'=>'Já no sideboard'][$inSelection] ?? 'Já na seleção') ?></small><?php else: ?>
                    <form method="post"><?php $tokenFields('add', $card['id']); ?><input type="hidden" name="quantity" value="<?= max(1, min(deckCopyLimit($card, $fmt) ?? 4, (int)round((float)$stat['avg']))) ?>"><button class="secondary-link">Adicionar <?= max(1, min(deckCopyLimit($card, $fmt) ?? 4, (int)round((float)$stat['avg']))) ?>×</button></form><?php endif; ?>
                </div>
            </article>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>
<?php endif; ?>
</section>
