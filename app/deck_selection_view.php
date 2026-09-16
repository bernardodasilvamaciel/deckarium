<?php
$stageCounts=array_fill_keys(array_keys($stages),0);
foreach($items as $entry) $stageCounts[$entry['stage']]+=(int)$entry['quantity'];
if ($commander) $stageCounts['deck']++;
$typeLabels=['Land'=>'Terrenos','Creature'=>'Criaturas','Artifact'=>'Artefatos','Enchantment'=>'Encantamentos','Planeswalker'=>'Planeswalkers','Instant'=>'Instantâneas','Sorcery'=>'Feitiços','Battle'=>'Batalhas'];
$groups=[];
foreach($items as $entry){
    if($entry['stage']!==$selectionStage) continue;
    $category='Outras cartas';
    foreach($typeLabels as $typeKey=>$typeLabel) if(str_contains(explode(' // ',(string)$entry['type_line'])[0],$typeKey)){$category=$typeLabel;break;}
    $groups[$category][]=$entry;
}
ksort($groups);
// Índice de Encaixe: nota de cada carta e ordem dentro de cada tipo.
$scoreConfig = deckScoreConfig($deck['scoring_config'] ?? null);
$scores = $commander ? deckScoreSelection($deck, $commander, $items, $scoreConfig) : null;
$scoreSuggestions = $scores ? deckScoreSuggestions($scores, $selectionStage, $scoreConfig) : [];
if ($scores) {
    foreach ($groups as &$groupEntries) usort($groupEntries, fn($a, $b) => ($scores['cards'][$b['id']]['score'] ?? -1) <=> ($scores['cards'][$a['id']]['score'] ?? -1));
    unset($groupEntries);
}
$commanderEntry = null;
if ($selectionStage==='deck' && $commander) {
    $commanderEntry = $commander;
    $commanderEntry['stage']='deck'; $commanderEntry['quantity']=1; $commanderEntry['role']='Comandante'; $commanderEntry['notes']='';
    $commanderEntry['owned_printing']=(int)deckQuery('SELECT COALESCE(SUM(quantity),0) FROM builder_collection WHERE scryfall_id=? AND user_id=?',[$commander['id'],$userId])->fetchColumn();
    $commanderEntry['owned']=(int)deckQuery(deckOwnedSql().'SELECT COALESCE((SELECT owned FROM owned WHERE logical_id=?::uuid),0)',[$commander['oracle_id']?:$commander['id']])->fetchColumn();
    $commanderEntry['other_used']=(int)deckQuery("SELECT COALESCE(SUM(x.quantity),0) FROM (SELECT SUM(i.quantity)::int quantity FROM builder_items i JOIN builder_decks ud ON ud.id=i.deck_id AND ud.user_id=? JOIN cards c ON c.id=i.card_id WHERE i.stage='deck' AND i.deck_id<>? AND COALESCE(c.oracle_id,c.id)=?::uuid UNION ALL SELECT COUNT(*)::int FROM builder_decks d JOIN cards c ON c.id=d.commander_id WHERE d.user_id=? AND d.id<>? AND COALESCE(c.oracle_id,c.id)=?::uuid) x",[$userId,$id,$commander['oracle_id']?:$commander['id'],$userId,$id,$commander['oracle_id']?:$commander['id']])->fetchColumn();
    $groups = ['Comandante'=>[$commanderEntry]] + $groups;
}
$inventoryBadge=function(array $entry): string {
    $total=(int)($entry['owned']??0); $other=(int)($entry['other_used']??0); $need=(int)($entry['quantity']??1); $free=max(0,$total-$other);
    if($total===0) return '<span class="inventory-badge is-missing">Não está na coleção</span>';
    if($free===0) return '<span class="inventory-badge is-reserved">'.$total.' na coleção · '.$other.' em outros decks</span>';
    if($need>$free) return '<span class="inventory-badge is-limited">'.$total.' na coleção · '.$other.' em outros decks</span>';
    return '<span class="inventory-badge is-available">'.$total.' na coleção · '.$other.' em outros decks</span>';
};
$moveButton=function(array $entry,string $destination,string $label) use($tokenFields):void { ?>
<form method="post"><?php $tokenFields('move',$entry['id']); ?><input type="hidden" name="stage" value="<?= h($destination) ?>"><button class="secondary-link"><?= h($label) ?></button></form>
<?php };
$upgradeIncoming = null;
if (!empty($_GET['upgrade_card'])) foreach($items as $candidate) if($candidate['id']===(string)$_GET['upgrade_card'] && $candidate['stage']==='review') {$upgradeIncoming=$candidate;break;}
$upgradeDetail=function(array $card,string $label): void { $src=cardImageUrl($card,'front','small'); ?>
<article class="upgrade-detail-card">
<?php if($src): ?><img src="<?= h($src) ?>" alt="<?= h($card['name']) ?>" loading="lazy"><?php endif; ?>
<div><span class="upgrade-detail-label"><?= h($label) ?></span><h4><?= h($card['name']) ?></h4>
<small><?= h(strtoupper((string)($card['set_code']??''))) ?> #<?= h((string)($card['collector_number']??'')) ?> · <?= h(deckPriceVariantsLabel($card)) ?></small>
<small><?= h($card['type_line']??'Tipo não informado') ?> · <?= h($card['mana_cost']??'Sem custo de mana') ?></small>
<p><?= nl2br(h(deckText($card) ?: 'Texto Oracle não disponível.')) ?></p></div>
</article>
<?php };
?>
<?php
$stageHints=[
    'candidate'=>'Possibilidades que você guardou. Abra um tipo e clique em uma carta para registrar a avaliação ou mandá-la adiante.',
    'review'=>'Cartas em comparação. Abra cada uma para escrever o que ela acrescenta e decidir se entra no deck.',
    'deck'=>'A versão escolhida do seu deck, organizada por tipo. Clique em uma carta para ajustar ou devolvê-la à avaliação.',
];
?>
<section id="selection" class="selection-workspace" data-selection-workspace="<?= (int)$id ?>-<?= h($selectionStage) ?>">
<div class="section-heading"><div><h2>Minha seleção</h2><p><?= h($stageHints[$selectionStage]) ?></p></div><a href="?deck=<?= $id ?>&view=discover#explore">Descobrir cartas</a></div>
<nav class="tabs selection-tabs" aria-label="Etapas da seleção"><?php foreach($stages as $key=>$label): ?><a href="?deck=<?= $id ?>&view=selection&stage=<?= h($key) ?>" <?= $key===$selectionStage?'aria-current="page"':'' ?>><?= h($label) ?> <span><?= $stageCounts[$key] ?></span></a><?php endforeach; ?></nav>
<?php if($pendingUpgrades): foreach($pendingUpgrades as $pendingUpgrade): $pendingOut=deckQuery('SELECT * FROM cards WHERE id=?',[$pendingUpgrade['remove_card_id']])->fetch(); $pendingIn=deckQuery('SELECT * FROM cards WHERE id=?',[$pendingUpgrade['add_card_id']])->fetch(); ?>
<section class="upgrade-pending" id="upgrade-<?= (int)$pendingUpgrade['id'] ?>"><div class="upgrade-pending-copy"><span class="plan-status">100 + 1 upgrade pendente</span><h3><?= h($pendingUpgrade['add_name']) ?> sobre <?= h($pendingUpgrade['remove_name']) ?></h3><p>Compare os dois lados antes de confirmar. O deck físico continua com 100 cartas até a troca ser aprovada.</p><div class="selection-actions"><form method="post"><?php $tokenFields('apply_upgrade'); ?><input type="hidden" name="upgrade" value="<?= (int)$pendingUpgrade['id'] ?>"><button class="primary-link">Confirmar troca</button></form><form method="post"><?php $tokenFields('cancel_upgrade'); ?><input type="hidden" name="upgrade" value="<?= (int)$pendingUpgrade['id'] ?>"><button class="secondary-link">Cancelar upgrade</button></form></div></div><div class="upgrade-pending-cards"><?php if($pendingOut) $upgradeDetail($pendingOut,'Sai'); ?><span class="upgrade-arrow" aria-hidden="true">→</span><?php if($pendingIn) $upgradeDetail($pendingIn,'Entra'); ?></div></section>
<?php endforeach; endif; ?>
<?php if($selectionStage==='review' && $upgradeIncoming && $finalCount===100): ?>
<section class="upgrade-chooser" id="upgrade"><div class="section-heading"><div><h3>Escolha o que sai</h3><p>Compare a carta que entra com cada carta final sugerida para sair. A escolha apenas cria um plano; nada é substituído ainda.</p></div><span>100 + 1 upgrade</span></div><div class="upgrade-incoming-preview"><?php $upgradeDetail($upgradeIncoming,'Entra'); ?></div><div class="upgrade-cut-grid"><?php foreach($upgradeCuts as $cut): ?><article class="upgrade-cut"><?php $upgradeDetail($cut,'Sai'); ?><div class="upgrade-cut-meta"><small><?= (float)$cut['cut_score'] ? 'Associação EDHREC: '.number_format((float)$cut['cut_score']*100,0,',','.').'%' : 'Sem associação encontrada na cache' ?></small><form method="post"><?php $tokenFields('confirm_upgrade',$upgradeIncoming['id']); ?><input type="hidden" name="remove_card" value="<?= h($cut['id']) ?>"><input type="hidden" name="reason" value="Plano de upgrade sugerido pela menor associação EDHREC."><button class="primary-link">Usar como carta que sai</button></form></div></article><?php endforeach; ?></div></section>
<?php endif; ?>

<?php if($scores && $groups): require __DIR__.'/deck_scoring_view.php'; elseif(!$commander && $groups): ?><p class="notice warning">Escolha a comandante para calcular o Índice de Encaixe das cartas.</p><?php endif; ?>
<?php if(!$groups): ?>
<p class="empty-state">Nenhuma carta nesta etapa. <a href="?deck=<?= $id ?>&view=discover#explore">Explore o catálogo</a> ou mova uma carta de outra etapa.</p>
<?php else: ?>
<div class="selection-toolbar"><span><?= count($groups) ?> <?= count($groups)===1?'tipo':'tipos' ?> · <?= array_sum(array_map(fn($entries)=>array_sum(array_column($entries,'quantity')),$groups)) ?> cartas</span><div><button type="button" class="selection-toggle-all" data-selection-expand>Abrir todos</button><button type="button" class="selection-toggle-all" data-selection-collapse>Fechar todos</button></div></div>
<?php endif; ?>

<div class="selection-groups stage-<?= h($selectionStage) ?>">
<?php foreach($groups as $category=>$entries): $groupKey=substr(md5($category),0,10); $groupCount=array_sum(array_column($entries,'quantity')); ?>
<details class="selection-type" data-selection-group="<?= h($groupKey) ?>">
<summary>
    <span class="selection-type-title"><?= h($category) ?> <b><?= $groupCount ?></b></span>
    <span class="selection-type-peek" aria-hidden="true"><?php foreach(array_slice($entries,0,5) as $peek): if($peekSrc=cardImageUrl($peek,'front','small')): ?><img src="<?= h($peekSrc) ?>" alt="" loading="lazy" width="34" height="47"><?php endif; endforeach; ?><?php if(count($entries)>5): ?><i>+<?= count($entries)-5 ?></i><?php endif; ?></span>
    <span class="selection-type-chevron" aria-hidden="true"></span>
</summary>
<div class="selection-slots">
<?php foreach($entries as $entry): $preview=cardImageUrl($entry); $isCommander=$category==='Comandante'; $dialogId='selection-card-'.$entry['id']; $hasNotes=trim((string)($entry['notes']??''))!==''; $fit=$isCommander?null:($scores['cards'][$entry['id']]??null); ?>
<article class="selection-slot <?= $isCommander?'is-commander':'' ?>">
<?php if($isCommander): ?>
    <a class="selection-tile" href="/card.php?id=<?= h($entry['id']) ?>" title="Abrir página da comandante">
<?php else: ?>
    <button type="button" class="selection-tile" data-selection-open="<?= h($dialogId) ?>" aria-haspopup="dialog" aria-label="Avaliar <?= h($entry['name']) ?>">
<?php endif; ?>
        <span class="selection-art"><?php if($preview): ?><img src="<?= h($preview) ?>" alt="<?= h($entry['name']) ?>" loading="lazy" width="244" height="340"><?php else: ?><span class="placeholder image-fallback"><strong><?= h($entry['name']) ?></strong><span>Imagem indisponível</span></span><?php endif; ?>
        <?php if($fit): ?><b class="fit-badge is-<?= h($fit['band']) ?>" data-fit-card="<?= h($entry['id']) ?>" title="Índice de Encaixe <?= (int)$fit['score'] ?> · <?= h(deckScoreBandLabel($fit['band'])) ?>"><?= (int)$fit['score'] ?></b><?php endif; ?>
        <?php if((int)$entry['quantity']>1): ?><b class="selection-qty <?= $fit?'has-fit':'' ?>"><?= (int)$entry['quantity'] ?>×</b><?php endif; ?>
        <?php if(deckIsGameChanger($entry)): ?><b class="gc-badge selection-gc" title="Game Changer">GC</b><?php endif; ?>
        <?php if($selectionStage==='deck'||$isCommander) echo $inventoryBadge($entry); ?></span>
        <span class="selection-tile-name"><?= h($entry['name']) ?></span>
        <span class="selection-tile-meta"><?php if($isCommander): ?>Comandante<?php else: ?><?php if($fit): ?><span class="fit-band-text is-<?= h($fit['band']) ?>" data-fit-label="<?= h($entry['id']) ?>"><?= h(deckScoreBandLabel($fit['band'])) ?></span> · <?php endif; ?><?= $entry['role']!==''&&$entry['role']!==null?h($entry['role']):($fit?h(implode(', ',array_slice($fit['roles'],0,2))):'Sem função') ?><?php if($hasNotes): ?> · <span class="selection-noted">avaliada</span><?php endif; ?><?php endif; ?></span>
<?php if($isCommander): ?></a><?php else: ?></button><?php endif; ?>

<?php if(!$isCommander): ?>
<dialog class="selection-dialog" id="<?= h($dialogId) ?>" aria-labelledby="<?= h($dialogId) ?>-title">
    <form method="dialog" class="selection-dialog-close"><button type="submit" aria-label="Fechar">×</button></form>
    <div class="selection-dialog-layout">
        <figure class="selection-dialog-art">
            <?php if($preview): ?><img src="<?= h($preview) ?>" alt="<?= h($entry['name']) ?>" loading="lazy" width="488" height="680"><?php endif; ?>
            <figcaption><a href="/card.php?id=<?= h($entry['id']) ?>">Abrir página da carta</a></figcaption>
        </figure>
        <div class="selection-dialog-body">
            <span class="selection-dialog-stage stage-pill-<?= h($entry['stage']) ?>"><?= h($stages[$entry['stage']]??$entry['stage']) ?></span>
            <h3 id="<?= h($dialogId) ?>-title"><?= h($entry['name']) ?><?php if(deckIsGameChanger($entry)): ?> <b class="gc-badge" title="Game Changer">GC</b><?php endif; ?></h3>
            <p class="selection-dialog-meta"><?= h(strtoupper((string)$entry['set_code'])) ?> #<?= h($entry['collector_number']) ?> · <?= h(deckPriceLabel($entry)) ?> · <?= (int)$entry['owned']>0?(int)$entry['owned'].' na coleção':'Fora da coleção' ?></p>
            <p class="selection-dialog-type"><span><?= h($entry['type_line'] ?: 'Tipo não informado') ?></span><span class="selection-dialog-mana"><?= manaSymbols($entry['mana_cost']??null) ?></span></p>
            <div class="selection-dialog-oracle"><?= nl2br(h(deckText($entry) ?: 'Texto Oracle não disponível.')) ?></div>
            <?php if($fit): ?>
            <details class="fit-breakdown" <?= $selectionStage!=='deck'?'open':'' ?>>
                <summary><b class="fit-badge is-<?= h($fit['band']) ?>"><?= (int)$fit['score'] ?></b><span><strong>Índice de Encaixe · <?= h(deckScoreBandLabel($fit['band'])) ?></strong><small>Por que esta nota?</small></span></summary>
                <?php if($fit['blocked']): ?><p class="fit-alert is-blocked"><?= h($fit['blocked']) ?></p><?php endif; ?>
                <?php foreach($fit['notes'] as $fitNote): ?><p class="fit-alert"><?= h($fitNote) ?></p><?php endforeach; ?>
                <ul class="fit-parts">
                    <?php foreach($fit['breakdown'] as $part): ?>
                    <li><span class="fit-part-label"><?= h($part['label']) ?></span><span class="fit-part-bar" aria-hidden="true"><i style="width:<?= round($part['max'] ? $part['points']/$part['max']*100 : 0) ?>%"></i></span><b><?= number_format($part['points'],0,',','.') ?><small>/<?= number_format($part['max'],0,',','.') ?></small></b><small class="fit-part-detail"><?= h($part['detail']) ?></small></li>
                    <?php endforeach; ?>
                </ul>
                <?php if($fit['multiplier']<1 || $fit['bonus']): ?><p class="fit-math">Soma dos componentes × <?= number_format($fit['multiplier'],2,',','.') ?> pelas regras<?= $fit['bonus'] ? ' + '.(int)$fit['bonus'].' de bônus' : '' ?> = <?= (int)$fit['score'] ?>.</p><?php endif; ?>
                <p class="fit-tags"><?php if($fit['roles']): ?><span><em>Função</em> <?= h(implode(', ',$fit['roles'])) ?></span><?php endif; ?><?php if($fit['produces']): ?><span><em>Produz</em> <?= h(implode(', ',array_slice($fit['produces'],0,5))) ?></span><?php endif; ?><?php if($fit['cares']): ?><span><em>Procura</em> <?= h(implode(', ',array_slice($fit['cares'],0,5))) ?></span><?php endif; ?></p>
            </details>
            <?php endif; ?>

            <div class="selection-dialog-moves">
                <?php if($entry['stage']==='candidate'): $moveButton($entry,'review','Enviar para avaliação →'); ?>
                <?php elseif($entry['stage']==='review'): ?>
                    <?php if($finalCount===100): ?><form method="post"><?php $tokenFields('prepare_upgrade',$entry['id']); ?><button class="primary-link">Preparar upgrade</button></form><?php else: ?><form method="post"><?php $tokenFields('move',$entry['id']); ?><input type="hidden" name="stage" value="deck"><button class="primary-link">Aprovar para o deck →</button></form><?php endif; ?>
                    <?php $moveButton($entry,'candidate','← Voltar às candidatas'); ?>
                <?php elseif($entry['stage']==='deck'): $moveButton($entry,'review','← Voltar para avaliação'); ?>
                <?php endif; ?>
            </div>

            <form method="post" class="builder-form selection-dialog-form"><?php $tokenFields('item',$entry['id']); ?>
                <div class="selection-dialog-fields">
                    <label>Etapa<select name="stage"><?php $stageOptions=['candidate'=>['candidate','review'],'review'=>['candidate','review','deck'],'deck'=>['deck','review']][$entry['stage']]??[$entry['stage']]; foreach($stageOptions as $key): ?><option value="<?= h($key) ?>" <?= $key===$entry['stage']?'selected':'' ?>><?= h($stages[$key]) ?></option><?php endforeach; ?></select></label>
                    <label>Quantidade<input type="number" name="quantity" min="1" max="1000" value="<?= (int)$entry['quantity'] ?>" required></label>
                </div>
                <label>Função<input name="role" value="<?= h($entry['role']) ?>" maxlength="100" placeholder="Compra, ramp, proteção…"></label>
                <label>Minha avaliação<textarea name="notes" maxlength="2000" rows="4" placeholder="O que esta carta acrescenta ao plano? O que ela substitui?"><?= h($entry['notes']) ?></textarea></label>
                <div class="selection-dialog-footer"><button class="primary-link">Salvar</button><button type="button" class="secondary-link" data-dialog-close>Cancelar</button><button name="action" value="remove" class="builder-remove" formnovalidate>Retirar da seleção</button></div>
            </form>
        </div>
    </div>
</dialog>
<?php endif; ?>
</article>
<?php endforeach; ?>
</div>
</details>
<?php endforeach; ?>
</div>
</section>
