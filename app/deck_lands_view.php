<?php
// Janela "Completar com terrenos": prévia do plano de deckLandPlan() e aplicação no deck.
$landColorNames = ['W'=>'Branco','U'=>'Azul','B'=>'Preto','R'=>'Vermelho','G'=>'Verde','C'=>'Incolor'];
$landAvailability = ['candidate'=>['Nas candidatas','is-available'],'free'=>['Na coleção','is-available'],'reserved'=>['Em outro deck','is-reserved'],'missing'=>['Fora da coleção','is-missing']];
$landReferenceLabel = ['edhrec'=>'média do EDHREC para a comandante','text'=>'base pela leitura da comandante','custom'=>'meta personalizada em Metas e regras'][$landPlan['reference_source']];
$landTargetLabel = ['request'=>'informada agora','saved'=>'salva neste deck','suggestion'=>'sugestão pelo deck'][$landPlan['target_source']];
$landPlanTotal = $landPlan['manual'] + $landPlan['need'];
$landBasicTotal = array_sum(array_column($landPlan['basics'],'quantity'));
$landSourceTotal = max(1, array_sum($landPlan['sources']));
$landSuggestion = $landPlan['suggestion'];
?>
<dialog class="land-dialog" id="land-fill" aria-labelledby="land-fill-title" data-land-dialog>
<form method="dialog" class="selection-dialog-close"><button type="submit" aria-label="Fechar">×</button></form>
<div class="land-dialog-head">
    <span class="land-kicker">Base de mana automática</span>
    <h3 id="land-fill-title">Completar com terrenos</h3>
    <p>Você cuida das mágicas; o Deckarium escolhe os terrenos. Eles só são escolhidos com as mágicas fechadas (comandante + mágicas = 100 − terrenos), para as cores e os não básicos serem calculados sobre a lista final.</p>
</div>

<div class="land-suggestion">
    <div class="land-suggestion-total"><span>Sugestão pelo deck</span><strong><?= (int)$landSuggestion['total'] ?></strong><small>terrenos</small></div>
    <ul>
        <li><span>Base de Commander</span><b>37</b></li>
        <?php foreach($landSuggestion['parts'] as [$partLabel,$partDelta]): ?><li><span><?= h($partLabel) ?></span><b class="<?= $partDelta<0?'is-down':($partDelta>0?'is-up':'') ?>"><?= $partDelta>0?'+'.$partDelta:($partDelta<0?'−'.abs($partDelta):'±0') ?></b></li><?php endforeach; ?>
        <?php if($landSuggestion['raw']!==$landSuggestion['total']): ?><li><span>Limitado à faixa 31–42</span><b><?= (int)$landSuggestion['total'] ?></b></li><?php endif; ?>
    </ul>
    <p class="muted">Referência: <?= (int)$landPlan['reference'] ?> (<?= h($landReferenceLabel) ?>). A sugestão lê as mágicas aprovadas; ela muda conforme o deck muda.</p>
</div>

<form method="post" class="land-controls" data-land-preview>
    <?php $tokenFields('land_target'); ?><input type="hidden" name="land_buy_set" value="1">
    <?php foreach($landOptionsRequest['skip'] as $skipped): ?><input type="hidden" name="land_skip[]" value="<?= h($skipped) ?>"><?php endforeach; ?>
    <label class="land-total">Meta de terrenos<input type="number" name="land_total" min="20" max="60" placeholder="<?= (int)$landSuggestion['total'] ?>" value="<?= $landPlan['target_source']==='suggestion' ? '' : (int)$landPlan['target'] ?>"><small>Vazio = usar a sugestão (<?= (int)$landSuggestion['total'] ?>). Atual: <?= (int)$landPlan['target'] ?>, <?= h($landTargetLabel) ?>.</small></label>
    <label class="builder-check land-buy"><input type="checkbox" name="land_buy" value="1" <?= $landPlan['include_missing']?'checked':'' ?>> Incluir terrenos fora da coleção</label>
    <button class="secondary-link">Salvar e recalcular</button>
</form>

<dl class="land-summary">
    <div><dt>Mágicas</dt><dd><?= (int)$landPlan['nonland'] ?><small>/<?= (int)$landPlan['spells_needed'] ?></small></dd><span>+ comandante</span></div>
    <div><dt>Terrenos seus</dt><dd><?= (int)$landPlan['manual'] ?></dd><span>aprovados por você</span></div>
    <div><dt>Entram agora</dt><dd><?= (int)$landPlan['need'] ?></dd><span><?= $landPlan['blocked'] ? 'aguardando as mágicas' : count($landPlan['picks']).' não básicos · '.$landBasicTotal.' básicos' ?></span></div>
</dl>
<?php if($landPlan['blocked']): ?><p class="notice warning land-note"><?= h($landPlan['blocked']) ?></p><?php endif; ?>
<?php foreach($landPlan['notes'] as $landNote): ?><p class="notice warning land-note"><?= h($landNote) ?></p><?php endforeach; ?>
<?php if($landPlan['auto_existing'] && !$landPlan['blocked']): ?><p class="land-note muted"><?= (int)$landPlan['auto_existing'] ?> terreno(s) automático(s) anteriores serão substituídos por este plano.</p><?php endif; ?>

<?php if($landPlan['identity'] && !$landPlan['blocked']): ?>
<div class="land-colors" aria-label="Demanda e fontes por cor">
<?php foreach($landPlan['colors'] as $color): $percent=(int)round($landPlan['share'][$color]*100); $sourcePercent=(int)round($landPlan['sources'][$color]/$landSourceTotal*100); ?>
    <div class="land-color">
        <span class="land-color-name"><img class="mana-symbol" src="https://svgs.scryfall.io/card-symbols/<?= h($color) ?>.svg" alt="" width="18" height="18"> <?= h($landColorNames[$color]) ?></span>
        <span class="land-color-bars"><i class="mana-fill mana-<?= h($color) ?> is-demand" style="width:<?= $percent ?>%"></i><i class="land-source-bar" style="width:<?= min(100,$sourcePercent) ?>%"></i></span>
        <small><?= $percent ?>% dos símbolos · <?= $sourcePercent ?>% das fontes (<?= (int)$landPlan['sources'][$color] ?>)</small>
    </div>
<?php endforeach; ?>
<p class="muted">Barra colorida: participação da cor nos símbolos dos custos (os da comandante valem o dobro). Barra escura: participação da cor nas fontes de mana dos terrenos, já com este plano. Quanto mais parecidas, mais equilibrada a base.</p>
</div>
<?php endif; ?>

<form method="post" class="land-apply" data-land-apply>
<?php $tokenFields('autofill_lands'); ?>
<?php foreach($landOptionsRequest['skip'] as $skipped): ?><input type="hidden" name="land_skip[]" value="<?= h($skipped) ?>"><?php endforeach; ?>
<?php if($landPlan['picks']): ?>
<h4>Não básicos <span><?= count($landPlan['picks']) ?></span></h4>
<p class="muted land-hint">Desmarque um terreno e clique em Recalcular para trocá-lo pela próxima opção.</p>
<ul class="land-list">
<?php foreach($landPlan['picks'] as $pick): [$availabilityLabel,$availabilityClass]=$landAvailability[$pick['availability']]; $landSrc=cardImageUrl($pick['card'],'front','small'); ?>
<li>
    <input type="hidden" name="land_shown[]" value="<?= h($pick['logical_id']) ?>">
    <label class="land-row">
        <input type="checkbox" name="land_keep[]" value="<?= h($pick['logical_id']) ?>" checked data-land-keep>
        <?php if($landSrc): ?><img src="<?= h($landSrc) ?>" alt="" loading="lazy" width="46" height="64"><?php endif; ?>
        <span class="land-row-copy"><strong><?= h($pick['card']['name']) ?></strong><small><?= h(implode(' · ', $pick['reasons']) ?: 'Terreno utilitário') ?></small></span>
        <span class="land-row-meta"><span class="land-pips"><?php foreach($pick['colors'] as $pipColor): ?><img class="mana-symbol" src="https://svgs.scryfall.io/card-symbols/<?= h($pipColor) ?>.svg" alt="<?= h($pipColor) ?>" width="16" height="16"><?php endforeach; ?></span><b class="land-availability <?= $availabilityClass ?>"><?= h($availabilityLabel) ?></b></span>
    </label>
</li>
<?php endforeach; ?>
</ul>
<?php elseif($landPlan['need'] && !$landPlan['blocked']): ?>
<p class="muted land-hint"><?= $landPlan['include_missing'] ? 'Nenhum terreno não básico passou na avaliação para estas cores.' : 'Nenhum terreno não básico livre na coleção serve a este deck. Marque “Incluir terrenos fora da coleção” para ver sugestões de compra.' ?></p>
<?php endif; ?>
<?php if($landPlan['basics']): ?>
<h4>Básicos <span><?= $landBasicTotal ?></span></h4>
<ul class="land-basics">
<?php foreach($landPlan['basics'] as $basic): $basicSrc=cardImageUrl($basic['card'],'front','small'); ?>
<li><?php if($basicSrc): ?><img src="<?= h($basicSrc) ?>" alt="" loading="lazy" width="46" height="64"><?php endif; ?><span><strong><?= (int)$basic['quantity'] ?>× <?= h($basic['card']['name']) ?></strong><small><?= $basic['missing'] ? (int)$basic['free'].' livres na coleção · faltam '.(int)$basic['missing'] : (int)$basic['free'].' livres na coleção' ?></small></span></li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
<div class="land-dialog-footer">
    <?php if($landPlan['blocked']): ?><p class="muted">Ajuste as mágicas ou a meta; o botão aparece quando o deck estiver pronto para os terrenos.</p><?php elseif($landPlan['need'] || $landPlan['auto_existing']): ?><button class="primary-link"><?= $landPlan['need'] ? ($landPlan['auto_existing'] ? 'Refazer com ' : 'Adicionar ').(int)$landPlan['need'].' terreno'.($landPlan['need']===1?'':'s') : 'Retirar terrenos automáticos' ?></button><?php else: ?><p class="muted">O deck já tem os terrenos da meta.</p><?php endif; ?>
    <button type="button" class="secondary-link" data-dialog-close>Cancelar</button>
    <?php if($landOptionsRequest['skip']): ?><a class="land-reset" href="?deck=<?= (int)$id ?>&amp;view=selection&amp;stage=deck#land-fill">Restaurar terrenos descartados</a><?php endif; ?>
</div>
<p class="muted land-footnote">Os terrenos entram com a função “<?= h(DECK_AUTO_LAND_ROLE) ?>”: ao completar de novo, só eles são refeitos. Terrenos que você aprovou nunca são trocados.</p>
</form>
<?php if($landPlan['auto_existing']): ?>
<form method="post" class="land-clear"><?php $tokenFields('clear_auto_lands'); ?><button class="builder-remove">Retirar os <?= (int)$landPlan['auto_existing'] ?> terrenos automáticos do deck</button></form>
<?php endif; ?>
</dialog>
