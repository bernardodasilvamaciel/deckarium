<?php
declare(strict_types=1);
// Guia da comandante. Incluído por deck_discovery_view.php quando há comandante definida.

$guideDeckCount = (int)($guideInsights['deck_count'] ?? 0);
$guideSynced = !empty($guideInsights['synced_at']) ? date('d/m/Y', strtotime((string)$guideInsights['synced_at'])) : null;
$guideMechanicCount = count($guideMechanics['own']) + count($guideMechanics['new']);
$guideArt = cardImageUrl($commander);
$guideNumber = static fn($value): string => number_format((float)$value, 0, ',', '.');
$guidePiece = static function (array $piece, bool $withImage = true): void {
    $card = $piece['card'];
    $owned = $card && (int)($card['owned_printing'] ?? 0) > 0;
    $src = $card ? cardImageUrl($card, 'front', 'small') : null;
    $tag = $card ? 'a href="/card.php?id=' . h($card['id']) . '"' : 'span';
    echo '<' . $tag . ' class="guide-piece' . ($owned ? ' is-owned' : '') . '" title="' . h($piece['name']) . '">';
    if ($withImage) echo $src ? '<img src="' . h($src) . '" alt="" loading="lazy" width="146" height="204">' : '<span class="guide-piece-placeholder">' . h($piece['name']) . '</span>';
    echo '<span class="guide-piece-name">' . h($piece['name']) . '</span><small>' . ($card ? ($owned ? 'Na coleção' : 'Fora da coleção') : 'Fora do catálogo local') . '</small>';
    echo '</' . strtok($tag, ' ') . '>';
};
$guideTabs = [
    'plans' => ['Planos de jogo', count($guidePlans)],
    'combos' => ['Combos', count($guideCombos)],
    'mechanics' => ['Mecânicas', $guideMechanicCount],
    'news' => ['Novidades', count($guideNewCards) + count($guideSimilar)],
];
?>
<details class="commander-guide" id="commander-guide" data-commander-guide="<?= h((string)($commander['oracle_id'] ?: $commander['id'])) ?>" open>
<summary class="guide-summary">
    <span class="guide-summary-art" aria-hidden="true"><?php if ($guideArt): ?><img src="<?= h($guideArt) ?>" alt="" loading="lazy"><?php endif; ?></span>
    <span class="guide-summary-copy">
        <span class="guide-kicker">Guia da comandante</span>
        <strong><?= h($commander['name']) ?></strong>
        <span class="guide-summary-stats">
            <?php if ($guideDeckCount): ?><span><b><?= $guideNumber($guideDeckCount) ?></b> decks no EDHREC</span><?php endif; ?>
            <span><b><?= count($guidePlans) ?></b> planos</span>
            <span><b><?= count($guideCombos) ?></b> combos</span>
            <span><b><?= $guideMechanicCount ?></b> mecânicas</span>
        </span>
    </span>
    <span class="guide-summary-toggle" aria-hidden="true"></span>
</summary>

<div class="guide-body">
    <div class="guide-toolbar">
        <div class="guide-tabs" role="tablist" aria-label="Seções do guia">
            <?php foreach ($guideTabs as $key => [$label, $count]): ?>
            <button type="button" role="tab" id="guide-tab-<?= $key ?>" aria-controls="guide-panel-<?= $key ?>" aria-selected="<?= $key === 'plans' ? 'true' : 'false' ?>" data-guide-tab="<?= $key ?>"><?= h($label) ?> <span><?= (int)$count ?></span></button>
            <?php endforeach; ?>
        </div>
        <form method="post" class="guide-sync"><?php $tokenFields('sync_edhrec'); ?>
            <span><?= $guideSynced ? 'EDHREC atualizado em ' . h($guideSynced) : 'Ainda sem dados do EDHREC' ?></span>
            <button class="secondary-link"><?= $guideSynced ? 'Atualizar' : 'Buscar no EDHREC' ?></button>
        </form>
    </div>

    <?php if (!$guideInsights): ?>
    <p class="guide-empty-source">Os planos abaixo vêm da leitura do texto da comandante. Busque no EDHREC para ver os temas mais jogados, combos populares, cartas novas e comandantes parecidas.</p>
    <?php endif; ?>

    <section class="guide-panel" id="guide-panel-plans" role="tabpanel" aria-labelledby="guide-tab-plans" data-guide-panel="plans">
        <div class="guide-plan-grid">
        <?php foreach ($guidePlans as $plan): $ownedInPlan = count(array_filter($plan['cards'], fn($c) => (int)$c['owned'] > 0)); ?>
            <article class="guide-plan">
                <div class="guide-fan" aria-hidden="true">
                    <?php foreach (array_slice($plan['cards'], 0, 5) as $index => $planCard): if ($src = cardImageUrl($planCard, 'front', 'small')): ?>
                    <img src="<?= h($src) ?>" alt="" loading="lazy" style="--i:<?= $index ?>">
                    <?php endif; endforeach; ?>
                </div>
                <div class="guide-plan-body">
                    <span class="guide-source <?= $plan['source'] === 'edhrec' ? 'is-edhrec' : '' ?>"><?= $plan['source'] === 'edhrec' ? 'EDHREC · ' . $guideNumber($plan['count']) . ' decks' : 'Leitura do texto' ?></span>
                    <h4><?= h($plan['label']) ?></h4>
                    <p><?= h($plan['description']) ?></p>
                    <?php if ($plan['share'] !== null): ?><div class="guide-meter" role="img" aria-label="Popularidade relativa: <?= (int)$plan['share'] ?>%"><i style="width:<?= max(4, (int)$plan['share']) ?>%"></i></div><?php endif; ?>
                    <?php if ($plan['cards']): ?>
                    <ul class="guide-plan-cards">
                        <?php foreach (array_slice($plan['cards'], 0, 4) as $planCard): ?>
                        <li class="<?= (int)$planCard['owned'] > 0 ? 'is-owned' : '' ?>"><a href="/card.php?id=<?= h($planCard['id']) ?>"><?= h($planCard['name']) ?></a><?php if (deckIsGameChanger($planCard)): ?> <b class="gc-badge" title="Game Changer">GC</b><?php endif; ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <?php else: ?><p class="guide-muted">Nenhuma carta do catálogo local corresponde a este tema.</p><?php endif; ?>
                </div>
                <footer><span><?= $plan['cards'] ? $ownedInPlan . '/' . count($plan['cards']) . ' na coleção' : '' ?></span><a href="<?= h(deckGuideExploreUrl($id, $plan)) ?>">Explorar plano →</a></footer>
            </article>
        <?php endforeach; ?>
        </div>
    </section>

    <section class="guide-panel" id="guide-panel-combos" role="tabpanel" aria-labelledby="guide-tab-combos" data-guide-panel="combos">
        <?php if (!$guideCombos): ?>
        <p class="guide-empty">Nenhum combo conhecido encontrado para esta identidade de cor<?= $guideInsights ? '' : ' ainda. Busque no EDHREC para carregar os combos mais jogados com esta comandante' ?>.</p>
        <?php else: ?>
        <div class="guide-combo-list">
        <?php foreach ($guideCombos as $combo): $ownedPieces = count(array_filter($combo['pieces'], fn($p) => $p['card'] && (int)($p['card']['owned_printing'] ?? 0) > 0)); ?>
            <article class="guide-combo">
                <div class="guide-combo-pieces">
                    <?php foreach ($combo['pieces'] as $index => $piece): ?>
                        <?php if ($index > 0): ?><span class="guide-plus" aria-hidden="true">+</span><?php endif; ?>
                        <?php $guidePiece($piece); ?>
                    <?php endforeach; ?>
                </div>
                <div class="guide-combo-body">
                    <div class="guide-combo-meta">
                        <span class="guide-source <?= $combo['source'] === 'edhrec' ? 'is-edhrec' : '' ?>"><?= $combo['source'] === 'edhrec' ? 'EDHREC' : 'Catálogo Deckarium' ?></span>
                        <?php if ($combo['count']): ?><span><?= $guideNumber($combo['count']) ?> decks<?= $combo['percentage'] !== null ? ' · ' . number_format((float)$combo['percentage'], 2, ',', '.') . '%' : '' ?></span><?php endif; ?>
                        <?php if ($combo['bracket']): ?><span class="guide-bracket" title="Faixa de poder sugerida pela comunidade">Bracket <?= h($combo['bracket']) ?></span><?php endif; ?>
                        <span class="guide-owned <?= $ownedPieces === count($combo['pieces']) ? 'is-complete' : '' ?>"><?= $ownedPieces ?>/<?= count($combo['pieces']) ?> na coleção</span>
                    </div>
                    <?php if ($combo['results']): ?>
                    <div class="guide-result"><span>Resultado</span>
                        <ul><?php foreach (array_slice($combo['results'], 0, 4) as $result): ?><li><?= h($result) ?></li><?php endforeach; ?></ul>
                    </div>
                    <?php else: ?><p class="guide-muted">Resultado não informado pela fonte. Veja os detalhes no EDHREC.</p><?php endif; ?>
                    <?php if ($combo['prerequisites'] || $combo['steps']): ?>
                    <details class="guide-howto"><summary>Como funciona</summary>
                        <?php if ($combo['prerequisites']): ?><h5>Pré-requisitos</h5><ul><?php foreach ($combo['prerequisites'] as $item): ?><li><?= h($item) ?></li><?php endforeach; ?></ul><?php endif; ?>
                        <?php if ($combo['steps']): ?><h5>Passo a passo</h5><ol><?php foreach ($combo['steps'] as $item): ?><li><?= h($item) ?></li><?php endforeach; ?></ol><?php endif; ?>
                        <p class="guide-muted">Texto original da Commander Spellbook, via EDHREC.</p>
                    </details>
                    <?php endif; ?>
                    <?php if ($combo['href']): ?><a class="guide-external" href="<?= h($combo['href']) ?>" target="_blank" rel="noopener noreferrer">Ver no EDHREC ↗</a><?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </section>

    <section class="guide-panel" id="guide-panel-mechanics" role="tabpanel" aria-labelledby="guide-tab-mechanics" data-guide-panel="mechanics">
        <?php if ($guideMechanics['own']): ?>
        <h4 class="guide-subtitle">Na própria comandante</h4>
        <div class="guide-mechanic-grid">
            <?php foreach ($guideMechanics['own'] as $mechanic): ?>
            <article class="guide-mechanic is-own">
                <h5><?= h($mechanic['name']) ?></h5>
                <?php if ($mechanic['description']): ?><p><?= h($mechanic['description']) ?></p><?php endif; ?>
                <?php if ($mechanic['reminder']): ?><small><span>Oracle</span> <?= h($mechanic['reminder']) ?></small><?php endif; ?>
                <?php if (!$mechanic['description'] && !$mechanic['reminder']): ?><p class="guide-muted">Consulte o texto da comandante para os detalhes desta habilidade.</p><?php endif; ?>
                <a href="?<?= h(http_build_query(['deck' => $id, 'q' => '', 'oracle' => strtolower($mechanic['name']), 'colors' => 1])) ?>#explore">Cartas com <?= h($mechanic['name']) ?> →</a>
            </article>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <h4 class="guide-subtitle">Mecânicas novas que cabem na identidade</h4>
        <?php if (!$guideMechanics['new']): ?>
        <p class="guide-empty">Nenhuma mecânica lançada nos últimos 24 meses tem cartas suficientes nesta identidade de cor. Sincronize o catálogo em Status para incluir as coleções mais recentes.</p>
        <?php else: ?>
        <div class="guide-mechanic-grid">
            <?php foreach ($guideMechanics['new'] as $mechanic): ?>
            <article class="guide-mechanic">
                <span class="guide-mechanic-set">Estreou em <?= h($mechanic['first_set']) ?> · <?= h(date('m/Y', strtotime($mechanic['first_seen']))) ?></span>
                <h5><?= h($mechanic['name']) ?></h5>
                <?php if ($mechanic['description']): ?><p><?= h($mechanic['description']) ?></p><?php endif; ?>
                <?php if ($mechanic['reminder']): ?><small><span>Oracle</span> <?= h($mechanic['reminder']) ?></small><?php endif; ?>
                <a href="?<?= h(http_build_query(['deck' => $id, 'q' => '', 'oracle' => strtolower($mechanic['name']), 'colors' => 1])) ?>#explore"><?= $guideNumber($mechanic['cards']) ?> cartas na identidade →</a>
            </article>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </section>

    <section class="guide-panel" id="guide-panel-news" role="tabpanel" aria-labelledby="guide-tab-news" data-guide-panel="news">
        <h4 class="guide-subtitle">Cartas novas que já aparecem nos decks desta comandante</h4>
        <?php if (!$guideNewCards): ?>
        <p class="guide-empty"><?= $guideInsights ? 'O EDHREC não listou cartas novas compatíveis com esta comandante.' : 'Busque no EDHREC para ver as cartas recém-lançadas que jogadores estão testando.' ?></p>
        <?php else: ?>
        <div class="guide-new-cards">
            <?php foreach ($guideNewCards as $newCard): $newCardData = $newCard['card']; $alreadySelected = $newCardData ? ($selectedByLogical[(string)($newCardData['oracle_id'] ?: $newCardData['id'])] ?? null) : null; $inclusion = $newCard['potential_decks'] > 0 ? $newCard['num_decks'] / $newCard['potential_decks'] * 100 : null; ?>
            <article class="guide-new-card">
                <?php if ($newCardData && ($src = cardImageUrl($newCardData, 'front', 'small'))): ?><a href="/card.php?id=<?= h($newCardData['id']) ?>"><img src="<?= h($src) ?>" alt="<?= h($newCard['name']) ?>" loading="lazy" width="146" height="204"></a><?php else: ?><span class="guide-piece-placeholder"><?= h($newCard['name']) ?></span><?php endif; ?>
                <strong><?= h($newCard['name']) ?></strong>
                <small><?= $inclusion !== null ? number_format($inclusion, 1, ',', '.') . '% dos decks' : 'Uso não informado' ?><?= $newCard['synergy'] !== null ? ' · sinergia ' . sprintf('%+.0f', $newCard['synergy'] * 100) . '%' : '' ?></small>
                <?php if ($newCardData): ?>
                <form method="post"><?php $tokenFields('add', $newCardData['id']); $filterHidden(); ?><button class="secondary-link" <?= $alreadySelected ? 'disabled' : '' ?>><?= $alreadySelected ? 'Já na seleção' : 'Adicionar às candidatas' ?></button></form>
                <?php else: ?><small class="guide-muted">Sincronize o catálogo para adicioná-la.</small><?php endif; ?>
            </article>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ($guideSimilar): ?>
        <h4 class="guide-subtitle">Comandantes parecidas</h4>
        <div class="guide-similar">
            <?php foreach ($guideSimilar as $similar): ?>
                <?php if ($similar['card']): ?><a href="/card.php?id=<?= h($similar['card']['id']) ?>"><?php if ($src = cardImageUrl($similar['card'], 'front', 'small')): ?><img src="<?= h($src) ?>" alt="" loading="lazy"><?php endif; ?><span><?= h($similar['name']) ?></span></a>
                <?php else: ?><span><span><?= h($similar['name']) ?></span></span><?php endif; ?>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </section>

    <p class="guide-credits">Temas, combos e cartas novas: <a href="<?= h((string)($guideInsights['source_url'] ?? 'https://edhrec.com')) ?>" target="_blank" rel="noopener noreferrer">EDHREC</a> e Commander Spellbook. Mecânicas: catálogo Scryfall local.</p>
</div>
</details>
