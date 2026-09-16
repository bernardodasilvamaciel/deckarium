<?php
declare(strict_types=1);
// "O que o deck precisa": metas de função do Índice de Encaixe + cartas do catálogo para cada uma.
// Incluído por deck_discovery_view.php quando há comandante definida.

$needMissing = array_filter($needPanel, fn($need) => $need['missing'] > 0);
$needFirst = array_key_first($needMissing ?: $needPanel);
$needNumber = static fn(float $value): string => rtrim(rtrim(number_format($value, 1, ',', ''), '0'), ',');
$needHints = [
    'lands' => 'Terrenos que produzem as cores da comandante. Terrenos básicos completam o que faltar.',
    'ramp' => 'Aceleração de mana: artefatos de mana, busca de terrenos e Tesouros.',
    'draw' => 'Vantagem de cartas contínua ou em rajadas.',
    'removal' => 'Respostas pontuais: destruir, exilar, devolver ou anular uma ameaça.',
    'wipe' => 'Reset da mesa quando os oponentes passam na frente.',
    'protection' => 'Mantém a comandante e as peças-chave vivas: indestrutível, hexproof, fase.',
    'recursion' => 'Traz de volta cartas do cemitério.',
    'tutor' => 'Busca a peça que falta no grimório.',
];
$needExploreUrl = static fn(string $role): string => '?' . http_build_query(['deck' => $id, 'colors_set' => 1, 'colors' => 1, 'oracle' => '', 'role' => $role, 'sort' => 'synergy']) . '#explore';
?>
<section class="deck-needs" id="deck-needs" data-deck-needs>
    <div class="deck-needs-head">
        <div>
            <span class="guide-kicker">O que o deck precisa</span>
            <h2><?= $needMissing ? count($needMissing) . (count($needMissing) === 1 ? ' função abaixo da meta' : ' funções abaixo da meta') : 'Todas as metas de função atingidas' ?></h2>
            <p>Contagem das cartas já aprovadas no deck contra as metas da fórmula (<?= (int)$finalCount ?>/100 cartas). As sugestões respeitam a identidade da comandante, deixam de fora o que já está na seleção e vêm primeiro pela sinergia no EDHREC.</p>
        </div>
        <a class="secondary-link" href="?deck=<?= (int)$id ?>&view=selection&stage=candidate#fit-panel">Ajustar metas</a>
    </div>

    <div class="deck-needs-tabs" role="tablist" aria-label="Funções do deck">
        <?php foreach ($needPanel as $role => $need): $percent = $need['target'] > 0 ? min(100, round($need['current'] / $need['target'] * 100)) : 100; ?>
        <button type="button" role="tab" id="need-tab-<?= h($role) ?>" aria-controls="need-panel-<?= h($role) ?>" aria-selected="<?= $role === $needFirst ? 'true' : 'false' ?>" data-need-tab="<?= h($role) ?>" class="<?= $need['missing'] > 0 ? 'is-missing' : 'is-done' ?>">
            <span class="deck-need-label"><?= h($need['label']) ?></span>
            <span class="deck-need-count"><?= $need['missing'] > 0 ? 'faltam <b>' . (int)$need['missing'] . '</b>' : 'meta atingida' ?></span>
            <span class="deck-need-bar" aria-hidden="true"><i style="width:<?= $percent ?>%"></i></span>
        </button>
        <?php endforeach; ?>
    </div>

    <?php foreach ($needPanel as $role => $need): ?>
    <div class="deck-needs-panel" role="tabpanel" id="need-panel-<?= h($role) ?>" aria-labelledby="need-tab-<?= h($role) ?>" data-need-panel="<?= h($role) ?>">
        <div class="deck-needs-panel-head">
            <div>
                <h3><?= h($need['label']) ?> <small><?= $needNumber((float)$need['current']) ?> de <?= (int)$need['target'] ?> no deck<?= $need['candidates'] ? ' · ' . (int)$need['candidates'] . ' nas candidatas' : '' ?></small></h3>
                <p><?= h($needHints[$role] ?? '') ?><?php if ($need['missing'] > 0 && $need['candidates']): ?> Você já guardou <?= (int)$need['candidates'] ?> <?= $need['candidates'] === 1 ? 'candidata' : 'candidatas' ?> com essa função — <a href="?deck=<?= (int)$id ?>&view=selection&stage=candidate#selection">revise antes de buscar mais</a>.<?php endif; ?></p>
            </div>
            <a href="<?= h($needExploreUrl($role)) ?>" class="deck-needs-more">Ver todas as <?= number_format((int)$need['total'], 0, ',', '.') ?> →</a>
        </div>
        <?php if (!$need['cards']): ?>
        <p class="guide-empty">Nenhuma carta nova com essa função na identidade da comandante.</p>
        <?php else: ?>
        <div class="deck-needs-cards">
            <?php foreach ($need['cards'] as $card): $src = cardImageUrl($card, 'front', 'normal') ?: cardImageUrl($card); ?>
            <div class="deck-need-card">
                <a href="/card.php?id=<?= h($card['id']) ?>" class="deck-need-art">
                    <?php if ($src): ?><img src="<?= h($src) ?>" alt="<?= h($card['name']) ?>" loading="lazy" width="146" height="204"><?php else: ?><span class="placeholder image-fallback"><strong><?= h($card['name']) ?></strong></span><?php endif; ?>
                    <?php if ($card['need_game_changer']): ?><b class="gc-badge deck-need-gc" title="Game Changer">GC</b><?php endif; ?>
                </a>
                <strong class="deck-need-name"><?= h($card['name']) ?></strong>
                <small class="deck-need-meta"><?php if ($card['need_synergy'] !== null): ?><span class="is-synergy">Sinergia <?= sprintf('%+.0f', $card['need_synergy'] * 100) ?>%</span><?php elseif ($card['edhrec_rank_cached']): ?><span>#<?= number_format((int)$card['edhrec_rank_cached'], 0, ',', '.') ?> no EDHREC</span><?php endif; ?><span class="<?= $card['need_owned'] ? 'is-owned' : '' ?>"><?= $card['need_owned'] ? 'Na coleção' : 'Fora da coleção' ?></span></small>
                <form method="post"><?php $tokenFields('add', $card['id']); ?><button class="secondary-link">Adicionar às candidatas</button></form>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</section>
