<?php
declare(strict_types=1);
// Painel do Índice de Encaixe na Minha seleção. Incluído por deck_selection_view.php.

$presets = deckScorePresets();
$presetLabel = $presets[$scoreConfig['preset']]['label'] ?? 'Personalizada';
$bracketNames = [1 => 'Exhibition', 2 => 'Core', 3 => 'Upgraded', 4 => 'Optimized', 5 => 'cEDH'];
$destinationLabel = $selectionStage === 'candidate' ? 'avaliação' : 'deck';
$missingNeeds = array_filter($scores['needs'], fn($need) => $need['missing'] > 0 && $need['target'] > 0);
$weightHints = [
    'commander' => 'O que a carta produz e a comandante procura (e vice-versa): Tesouros, fichas, mortes, tribos, termos raros em comum.',
    'deck' => 'Quantas ligações a carta faz com as cartas em avaliação e no deck.',
    'roles' => 'Se a carta preenche uma função que ainda está abaixo da meta.',
    'curve' => 'Se o custo de mana dela está sobrando ou faltando na curva desejada.',
    'colors' => 'Penaliza símbolos repetidos de cores pouco presentes no deck.',
    'terms' => 'Bônus para cartas com os termos de "Minha intenção".',
    'edhrec' => 'Sinergia, inclusão nos decks da comandante e popularidade geral.',
    'collection' => 'Preferência por cartas que você já tem.',
    'price' => 'Preferência por cartas baratas ou dentro do limite de preço.',
];
?>
<section class="fit-panel" id="fit-panel" data-fit-panel>
    <div class="fit-panel-head">
        <div>
            <span class="fit-kicker">Índice de Encaixe</span>
            <h3><?= $selectionStage === 'deck' ? 'Como cada carta sustenta o deck' : 'Quais cartas merecem avançar' ?></h3>
            <p>Nota de 0 a 100 calculada com o texto das cartas, as funções que faltam, a curva e as regras de Commander. Clique numa carta para ver o porquê.</p>
        </div>
        <div class="fit-panel-actions">
            <?php if ($selectionStage !== 'deck'): ?>
            <button type="button" class="primary-link" data-dialog-open="fit-apply-dialog" <?= $scoreSuggestions ? '' : 'disabled' ?>>Aplicar sugestões <span data-fit-suggestions><?= count($scoreSuggestions) ?></span></button>
            <?php endif; ?>
            <button type="button" class="secondary-link" data-dialog-open="fit-config-dialog">Ajustar fórmula</button>
        </div>
    </div>
    <div class="fit-panel-stats">
        <div class="fit-bands">
            <?php foreach (['advance' => 'Avançar', 'review' => 'Avaliar', 'hold' => 'Segurar', 'blocked' => 'Bloqueadas'] as $band => $label):
                $count = count(array_filter($scores['cards'], fn($card) => $card['stage'] === $selectionStage && $card['band'] === $band)); ?>
            <span class="fit-band is-<?= $band ?>"><b data-fit-count="<?= $band ?>"><?= $count ?></b> <?= $label ?></span>
            <?php endforeach; ?>
        </div>
        <div class="fit-context">
            <span>Fórmula <strong><?= h($presetLabel) ?></strong></span>
            <span>Bracket <strong><?= (int)$scoreConfig['bracket'] ?> · <?= h($bracketNames[(int)$scoreConfig['bracket']]) ?></strong></span>
            <span>Game Changers <strong><?= (int)$scores['game_changers'] ?><?= (int)$scoreConfig['bracket'] === 3 ? '/3' : ((int)$scoreConfig['bracket'] <= 2 ? '/0' : '') ?></strong></span>
            <span>Vagas <strong><?= (int)$scores['open_slots'] ?></strong></span>
        </div>
    </div>
    <?php if ($missingNeeds): ?>
    <div class="fit-needs"><span>O deck ainda precisa de</span>
        <?php foreach ($missingNeeds as $need): ?><b><?= (int)$need['missing'] ?> <?= h(mb_strtolower($need['label'])) ?></b><?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php if ($selectionStage === 'deck' && $scores['weakest']): ?>
    <div class="fit-needs is-weakest"><span>Menores notas do deck (bons alvos para upgrade)</span>
        <?php foreach ($scores['weakest'] as $weak): ?><b><?= h($weak['name']) ?> · <?= (int)$weak['score'] ?></b><?php endforeach; ?>
    </div>
    <?php endif; ?>
</section>

<?php if ($selectionStage !== 'deck'): ?>
<dialog class="fit-dialog" id="fit-apply-dialog" aria-labelledby="fit-apply-title">
    <form method="dialog" class="selection-dialog-close"><button type="submit" aria-label="Fechar">×</button></form>
    <form method="post" class="fit-apply-form"><?php $tokenFields('apply_suggestions'); ?>
        <h3 id="fit-apply-title">Mover para <?= h($destinationLabel) ?></h3>
        <p class="muted">Cartas com nota de avanço (<?= (int)$scoreConfig['thresholds']['advance'] ?>+), limitadas às vagas do deck<?= $selectionStage === 'candidate' ? ' com uma margem para comparar' : '' ?> e ao limite de Game Changers do bracket. Desmarque o que quiser manter onde está.</p>
        <?php if (!$scoreSuggestions): ?>
        <p class="guide-empty">Nenhuma carta atingiu a nota de avanço<?= $scores['open_slots'] === 0 ? ' ou o deck já está completo' : '' ?>.</p>
        <?php else: ?>
        <ul class="fit-apply-list">
            <?php foreach ($scoreSuggestions as $cardId => $suggestion): $top = array_values(array_intersect_key($suggestion['breakdown'], array_flip(['commander', 'deck', 'roles', 'terms', 'edhrec']))); $top = array_filter($top, fn($part) => $part['value'] >= .5); usort($top, fn($a, $b) => $b['points'] <=> $a['points']); ?>
            <li><label>
                <input type="checkbox" name="cards[]" value="<?= h((string)$cardId) ?>" checked>
                <b class="fit-badge is-<?= h($suggestion['band']) ?>"><?= (int)$suggestion['score'] ?></b>
                <span><strong><?= h($suggestion['name']) ?></strong><small><?= h($top ? implode(' ', array_map(fn($part) => $part['detail'], array_slice($top, 0, 2))) : 'Boa nota em curva, cor e custo.') ?></small></span>
            </label></li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
        <div class="fit-dialog-footer">
            <button type="button" class="secondary-link" data-dialog-close>Cancelar</button>
            <button class="primary-link" <?= $scoreSuggestions ? '' : 'disabled' ?>>Mover selecionadas</button>
        </div>
    </form>
</dialog>
<?php endif; ?>

<dialog class="fit-dialog fit-config" id="fit-config-dialog" aria-labelledby="fit-config-title">
    <form method="dialog" class="selection-dialog-close"><button type="submit" aria-label="Fechar">×</button></form>
    <form method="post" class="fit-config-form" data-fit-config data-presets="<?= h(json_encode(array_map(fn($p) => ['weights' => $p['weights'], 'bracket' => $p['bracket']], $presets))) ?>"><?php $tokenFields('scoring_config'); ?>
        <h3 id="fit-config-title">Fórmula deste deck</h3>
        <p class="muted">nota = 100 × regras × Σ(peso × componente) ÷ Σ(pesos). A prévia atualiza as notas da tela enquanto você ajusta; salve para manter.</p>

        <fieldset class="fit-presets"><legend>Ponto de partida</legend>
            <?php foreach ($presets as $key => $preset): ?>
            <label class="fit-preset"><input type="radio" name="preset" value="<?= h($key) ?>" <?= $scoreConfig['preset'] === $key ? 'checked' : '' ?>><span><strong><?= h($preset['label']) ?></strong><small><?= h($preset['description']) ?></small></span></label>
            <?php endforeach; ?>
            <label class="fit-preset"><input type="radio" name="preset" value="custom" <?= !isset($presets[$scoreConfig['preset']]) ? 'checked' : '' ?>><span><strong>Personalizada</strong><small>Seus próprios pesos.</small></span></label>
        </fieldset>

        <div class="fit-config-grid">
            <fieldset class="fit-weights"><legend>Pesos dos componentes</legend>
                <?php foreach (DECK_SCORE_COMPONENTS as $key => $label): ?>
                <label class="fit-slider" title="<?= h($weightHints[$key]) ?>">
                    <span><?= h($label) ?> <output data-fit-output="<?= h($key) ?>"><?= (int)$scoreConfig['weights'][$key] ?></output></span>
                    <input type="range" name="weights[<?= h($key) ?>]" min="0" max="50" step="1" value="<?= (int)$scoreConfig['weights'][$key] ?>" data-fit-weight="<?= h($key) ?>">
                    <small><?= h($weightHints[$key]) ?></small>
                </label>
                <?php endforeach; ?>
            </fieldset>

            <div class="fit-config-side">
                <fieldset><legend>Metas de função</legend>
                    <div class="fit-number-grid">
                        <?php foreach ($scoreConfig['targets'] as $key => $value): ?>
                        <label><?= h(DECK_SCORE_ROLES[$key]) ?><input type="number" name="targets[<?= h($key) ?>]" min="0" max="60" value="<?= (int)$value ?>"></label>
                        <?php endforeach; ?>
                    </div>
                    <small class="muted">Plano de jogo recebe o que sobra até 99 cartas (<span data-fit-plan><?= max(0, 99 - array_sum($scoreConfig['targets'])) ?></span>).</small>
                </fieldset>
                <fieldset><legend>Curva desejada (cartas que não são terreno)</legend>
                    <div class="fit-curve-grid">
                        <?php foreach ($scoreConfig['curve'] as $cost => $value): ?>
                        <label><?= $cost === '7' ? '7+' : h($cost) ?><input type="number" name="curve[<?= h($cost) ?>]" min="0" max="40" value="<?= (int)$value ?>"></label>
                        <?php endforeach; ?>
                    </div>
                </fieldset>
                <fieldset><legend>Regras e limites</legend>
                    <div class="fit-number-grid">
                        <label>Bracket<select name="bracket" data-fit-bracket><?php foreach ($bracketNames as $number => $name): ?><option value="<?= $number ?>" <?= (int)$scoreConfig['bracket'] === $number ? 'selected' : '' ?>><?= $number ?> · <?= h($name) ?></option><?php endforeach; ?></select></label>
                        <label>Preço máximo (R$)<input type="number" name="max_price" min="0" step="0.5" value="<?= $scoreConfig['max_price'] > 0 ? h((string)$scoreConfig['max_price']) : '' ?>" placeholder="Sem limite"></label>
                        <label>Nota para avançar<input type="number" name="thresholds[advance]" min="0" max="100" value="<?= (int)$scoreConfig['thresholds']['advance'] ?>"></label>
                        <label>Nota para avaliar<input type="number" name="thresholds[review]" min="0" max="100" value="<?= (int)$scoreConfig['thresholds']['review'] ?>"></label>
                    </div>
                    <small class="muted">Brackets 1–2: sem Game Changers. Bracket 3: até 3 Game Changers, sem destruição de terrenos em massa e sem combos de duas cartas. Brackets 4–5: sem restrições.</small>
                </fieldset>
            </div>
        </div>

        <div class="fit-dialog-footer">
            <span class="fit-preview-status" data-fit-preview-status role="status" aria-live="polite"></span>
            <button type="submit" class="primary-link">Salvar fórmula</button>
            <button type="button" class="secondary-link" data-dialog-close>Cancelar</button>
            <button type="submit" class="builder-remove fit-reset" name="action" value="scoring_reset" formnovalidate>Restaurar padrão</button>
        </div>
    </form>
</dialog>
