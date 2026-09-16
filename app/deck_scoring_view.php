<?php
declare(strict_types=1);
// Painel de relações e metas da seleção. Incluído por deck_selection_view.php.
$bracketNames = [1 => 'Exhibition', 2 => 'Core', 3 => 'Upgraded', 4 => 'Optimized', 5 => 'cEDH'];
$missingNeeds = array_filter($scores['needs'], fn($need) => $need['missing'] > 0 && $need['target'] > 0);
unset($missingNeeds['plan']);
?>
<section class="fit-panel relationship-panel" id="fit-panel">
    <div class="fit-panel-head">
        <div>
            <h3>Relações entre candidatas</h3>
            <p>Sem nota e sem recomendação automática: as relações mostram trocas diretas entre textos de cartas na sua seleção.</p>
            <details class="relationship-help"><summary aria-label="Como funcionam as relações">?</summary><p>Uma relação aparece quando uma carta produz algo que outra procura — por exemplo, fichas, tesouros, marcadores, sacrifícios ou efeitos de entrada. Em Candidatas, a comparação é só entre candidatas. No Deck, as conexões são separadas entre cartas já aprovadas e candidatas.</p></details>
        </div>
        <div class="fit-panel-actions"><button type="button" class="secondary-link" data-dialog-open="fit-config-dialog">Ajustar metas e regras</button></div>
    </div>
    <div class="fit-panel-stats"><div class="fit-context">
        <span>Candidatas <strong><?= count(array_filter($scores['cards'], fn($card) => $card['stage'] === 'candidate')) ?></strong></span>
        <span>Bracket <strong><?= (int)$scoreConfig['bracket'] ?> · <?= h($bracketNames[(int)$scoreConfig['bracket']]) ?></strong></span>
        <span>Game Changers <strong><?= (int)$scores['game_changers'] ?><?= (int)$scoreConfig['bracket'] === 3 ? '/3' : ((int)$scoreConfig['bracket'] <= 2 ? '/0' : '') ?></strong></span>
        <span>Vagas <strong><?= (int)$scores['open_slots'] ?></strong></span>
    </div></div>
    <?php if ($missingNeeds): ?><p class="fit-needs-link"><span><?= count($missingNeeds) ?> <?= count($missingNeeds) === 1 ? 'função ainda está abaixo da meta' : 'funções ainda estão abaixo da meta' ?> no deck.</span> <a href="?deck=<?= (int)$id ?>#deck-needs">Encontrar cartas para elas →</a></p><?php endif; ?>
</section>

<dialog class="fit-dialog fit-config" id="fit-config-dialog" aria-labelledby="fit-config-title">
    <form method="dialog" class="selection-dialog-close"><button type="submit" aria-label="Fechar">×</button></form>
    <form method="post" class="fit-config-form"><?php $tokenFields('scoring_config'); ?>
        <h3 id="fit-config-title">Metas e regras deste deck</h3>
        <p class="muted">Estas referências continuam independentes das relações: elas mostram lacunas de função e protegem as regras de Commander, sem transformar cartas em uma nota.</p>
        <div class="fit-config-grid relationship-config">
            <div class="fit-config-side">
                <fieldset><legend>Metas de função</legend><div class="fit-number-grid"><?php foreach ($scoreConfig['targets'] as $key => $value): ?><label><?= h(DECK_SCORE_ROLES[$key]) ?><input type="number" name="targets[<?= h($key) ?>]" min="0" max="60" value="<?= (int)$value ?>"></label><?php endforeach; ?></div><small class="muted">Plano de jogo recebe o que sobra até 99 cartas.</small></fieldset>
                <fieldset><legend>Curva desejada</legend><div class="fit-curve-grid"><?php foreach ($scoreConfig['curve'] as $cost => $value): ?><label><?= $cost === '7' ? '7+' : h($cost) ?><input type="number" name="curve[<?= h($cost) ?>]" min="0" max="40" value="<?= (int)$value ?>"></label><?php endforeach; ?></div></fieldset>
            </div>
            <div class="fit-config-side"><fieldset><legend>Regras e limites</legend><div class="fit-number-grid"><label>Bracket<select name="bracket"><?php foreach ($bracketNames as $number => $name): ?><option value="<?= $number ?>" <?= (int)$scoreConfig['bracket'] === $number ? 'selected' : '' ?>><?= $number ?> · <?= h($name) ?></option><?php endforeach; ?></select></label><label>Preço máximo (R$)<input type="number" name="max_price" min="0" step="0.5" value="<?= $scoreConfig['max_price'] > 0 ? h((string)$scoreConfig['max_price']) : '' ?>" placeholder="Sem limite"></label></div><small class="muted">Brackets 1–2: sem Game Changers. Bracket 3: até 3 Game Changers, sem destruição de terrenos em massa e sem combos de duas cartas.</small></fieldset></div>
        </div>
        <div class="fit-dialog-footer"><button class="primary-link">Salvar metas e regras</button><button type="button" class="secondary-link" data-dialog-close>Cancelar</button><button type="submit" class="builder-remove fit-reset" name="action" value="scoring_reset" formnovalidate>Restaurar padrão</button></div>
    </form>
</dialog>
