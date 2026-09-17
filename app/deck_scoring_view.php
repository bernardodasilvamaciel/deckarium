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
            <h3>Relações entre as cartas</h3>
            <p>Sem nota e sem recomendação automática: cada relação mostra o que uma carta fornece e outra aproveita, com o trecho do texto das duas. O quadro desenha tudo com setas.</p>
            <details class="relationship-help"><summary aria-label="Como funcionam as relações">?</summary><p>Uma relação aparece quando uma carta fornece algo que outra aproveita — fichas, Tesouros, mortes, marcadores, terrenos entrando, uma tribo (inclusive “escolha um tipo de criatura”, resolvido pela tribo principal do deck) ou um combo conhecido. Em Candidatas, a comparação é só entre candidatas. No Deck, as conexões são separadas entre cartas já aprovadas e candidatas.</p></details>
        </div>
        <div class="fit-panel-actions"><a class="primary-link" href="/deck_board.php?deck=<?= (int)$id ?>">Abrir quadro de relações</a><button type="button" class="secondary-link" data-dialog-open="fit-config-dialog">Ajustar metas e regras</button></div>
    </div>
    <div class="fit-panel-stats"><div class="fit-context">
        <span>Candidatas <strong><?= count(array_filter($scores['cards'], fn($card) => $card['stage'] === 'candidate')) ?></strong></span>
        <span>Bracket <strong><?= (int)$scoreConfig['bracket'] ?> · <?= h($bracketNames[(int)$scoreConfig['bracket']]) ?></strong></span>
        <span>Game Changers <strong><?= (int)$scores['game_changers'] ?><?= (int)$scoreConfig['bracket'] === 3 ? '/3' : ((int)$scoreConfig['bracket'] <= 2 ? '/0' : '') ?></strong></span>
        <span>Vagas <strong><?= (int)$scores['open_slots'] ?></strong></span>
    </div></div>
    <?php if ($missingNeeds): ?><p class="fit-needs-link"><span><?= count($missingNeeds) ?> <?= count($missingNeeds) === 1 ? 'função ainda está abaixo da meta' : 'funções ainda estão abaixo da meta' ?> no deck.</span> <a href="?deck=<?= (int)$id ?>&amp;view=needs">Encontrar cartas para elas →</a></p><?php endif; ?>
</section>

<dialog class="fit-dialog fit-config" id="fit-config-dialog" aria-labelledby="fit-config-title">
    <form method="dialog" class="selection-dialog-close"><button type="submit" aria-label="Fechar">×</button></form>
    <form method="post" class="fit-config-form"><?php $tokenFields('scoring_config'); ?>
        <h3 id="fit-config-title">Metas e regras deste deck</h3>
        <p class="muted">Estas referências continuam independentes das relações: elas mostram lacunas de função e protegem as regras de Commander, sem transformar cartas em uma nota.</p>
        <div class="fit-config-grid relationship-config">
            <div class="fit-config-side">
                <?php $dynamic = $scoreConfig['dynamic'] ?? null; $isAuto = $scoreConfig['targets_mode'] === 'auto'; ?>
                <fieldset class="fit-targets-mode" data-targets-mode><legend>Metas de função e curva</legend>
                    <label class="fit-mode-option"><input type="radio" name="targets_mode" value="auto" <?= $isAuto ? 'checked' : '' ?>><span><strong>Automáticas para <?= h($commander['name'] ?? 'a comandante') ?></strong><small><?= $dynamic && $dynamic['source'] === 'edhrec' ? 'Calculadas com a média das listas no EDHREC' . ($dynamic['decks'] ? ' (' . number_format((int)$dynamic['decks'], 0, ',', '.') . ' decks)' : '') . ': terrenos, curva e quantas cartas de cada função os decks costumam usar.' : 'Calculadas pela leitura do texto e do custo da comandante. Atualize o EDHREC no guia para usar a média real das listas.' ?></small></span></label>
                    <label class="fit-mode-option"><input type="radio" name="targets_mode" value="manual" <?= $isAuto ? '' : 'checked' ?>><span><strong>Personalizadas</strong><small>Os números abaixo ficam fixos até você voltar ao automático.</small></span></label>
                </fieldset>
                <fieldset><legend>Metas de função</legend><div class="fit-number-grid"><?php foreach ($scoreConfig['targets'] as $key => $value): $autoValue = $dynamic['targets'][$key] ?? null; ?><label title="<?= h($dynamic['notes'][$key] ?? '') ?>"><?= h(DECK_SCORE_ROLES[$key]) ?><input type="number" name="targets[<?= h($key) ?>]" min="0" max="60" value="<?= (int)$value ?>" data-auto-value="<?= $autoValue === null ? '' : (int)$autoValue ?>"><?php if ($autoValue !== null && !$isAuto && (int)$autoValue !== (int)$value): ?><small class="fit-auto-hint">auto: <?= (int)$autoValue ?></small><?php endif; ?></label><?php endforeach; ?></div>
                    <?php if ($dynamic && $dynamic['notes']): $groupedNotes = []; foreach ($dynamic['notes'] as $role => $note) $groupedNotes[$note][] = $role === 'curve' ? 'Curva' : (DECK_SCORE_ROLES[$role] ?? $role); ?><ul class="fit-target-notes"><?php foreach ($groupedNotes as $note => $roles): ?><li><strong><?= h(implode(', ', $roles)) ?>:</strong> <?= h($note) ?></li><?php endforeach; ?></ul><?php endif; ?>
                    <small class="muted">Plano de jogo recebe o que sobra até 99 cartas.</small></fieldset>
                <fieldset><legend>Curva desejada</legend><div class="fit-curve-grid"><?php foreach ($scoreConfig['curve'] as $cost => $value): ?><label><?= $cost === '7' ? '7+' : h($cost) ?><input type="number" name="curve[<?= h($cost) ?>]" min="0" max="40" value="<?= (int)$value ?>" data-auto-value="<?= isset($dynamic['curve'][$cost]) ? (int)$dynamic['curve'][$cost] : '' ?>"></label><?php endforeach; ?></div></fieldset>
            </div>
            <div class="fit-config-side"><fieldset><legend>Regras e limites</legend><div class="fit-number-grid"><label>Bracket<select name="bracket"><?php foreach ($bracketNames as $number => $name): ?><option value="<?= $number ?>" <?= (int)$scoreConfig['bracket'] === $number ? 'selected' : '' ?>><?= $number ?> · <?= h($name) ?></option><?php endforeach; ?></select></label><label>Preço máximo (R$)<input type="number" name="max_price" min="0" step="0.5" value="<?= $scoreConfig['max_price'] > 0 ? h((string)$scoreConfig['max_price']) : '' ?>" placeholder="Sem limite"></label></div><small class="muted">Brackets 1–2: sem Game Changers. Bracket 3: até 3 Game Changers, sem destruição de terrenos em massa e sem combos de duas cartas.</small></fieldset></div>
        </div>
        <div class="fit-dialog-footer"><button class="primary-link">Salvar metas e regras</button><button type="button" class="secondary-link" data-dialog-close>Cancelar</button><button type="submit" class="builder-remove fit-reset" name="action" value="scoring_reset" formnovalidate>Restaurar padrão (automático)</button></div>
    </form>
</dialog>
