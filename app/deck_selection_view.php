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
$commanderEntry = null;
if ($selectionStage==='deck' && $commander) {
    $commanderEntry = $commander;
    $commanderEntry['stage']='deck'; $commanderEntry['quantity']=1; $commanderEntry['role']='Comandante'; $commanderEntry['notes']='';
    $commanderEntry['owned_printing']=(int)deckQuery('SELECT COALESCE(SUM(quantity),0) FROM builder_collection WHERE scryfall_id=?',[$commander['id']])->fetchColumn();
    $commanderEntry['owned']=(int)deckQuery(deckOwnedSql().'SELECT COALESCE((SELECT owned FROM owned WHERE logical_id=?::uuid),0)',[$commander['oracle_id']?:$commander['id']])->fetchColumn();
    $commanderEntry['other_used']=(int)deckQuery("SELECT COALESCE(SUM(x.quantity),0) FROM (SELECT SUM(i.quantity)::int quantity FROM builder_items i JOIN cards c ON c.id=i.card_id WHERE i.stage='deck' AND i.deck_id<>? AND COALESCE(c.oracle_id,c.id)=?::uuid UNION ALL SELECT COUNT(*)::int FROM builder_decks d JOIN cards c ON c.id=d.commander_id WHERE d.id<>? AND COALESCE(c.oracle_id,c.id)=?::uuid) x",[$id,$commander['oracle_id']?:$commander['id'],$id,$commander['oracle_id']?:$commander['id']])->fetchColumn();
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
<section id="selection" class="selection-workspace">
<div class="section-heading"><div><h2>Minha seleção</h2><p><?= ['candidate'=>'Guarde possibilidades. Passe o mouse sobre um nome para ver a carta.','review'=>'Compare suas escolhas e registre o que ainda precisa decidir.','deck'=>'A versão escolhida do seu deck, organizada por tipo.'][$selectionStage] ?></p></div><a href="?deck=<?= $id ?>&view=discover#explore">Descobrir cartas</a></div>
<nav class="tabs selection-tabs" aria-label="Etapas da seleção"><?php foreach($stages as $key=>$label): ?><a href="?deck=<?= $id ?>&view=selection&stage=<?= h($key) ?>" <?= $key===$selectionStage?'aria-current="page"':'' ?>><?= h($label) ?> <span><?= $stageCounts[$key] ?></span></a><?php endforeach; ?></nav>
<?php if($pendingUpgrades): foreach($pendingUpgrades as $pendingUpgrade): $pendingOut=deckQuery('SELECT * FROM cards WHERE id=?',[$pendingUpgrade['remove_card_id']])->fetch(); $pendingIn=deckQuery('SELECT * FROM cards WHERE id=?',[$pendingUpgrade['add_card_id']])->fetch(); ?>
<section class="upgrade-pending" id="upgrade-<?= (int)$pendingUpgrade['id'] ?>"><div class="upgrade-pending-copy"><span class="plan-status">100 + 1 upgrade pendente</span><h3><?= h($pendingUpgrade['add_name']) ?> sobre <?= h($pendingUpgrade['remove_name']) ?></h3><p>Compare os dois lados antes de confirmar. O deck físico continua com 100 cartas até a troca ser aprovada.</p><div class="selection-actions"><form method="post"><?php $tokenFields('apply_upgrade'); ?><input type="hidden" name="upgrade" value="<?= (int)$pendingUpgrade['id'] ?>"><button class="primary-link">Confirmar troca</button></form><form method="post"><?php $tokenFields('cancel_upgrade'); ?><input type="hidden" name="upgrade" value="<?= (int)$pendingUpgrade['id'] ?>"><button class="secondary-link">Cancelar upgrade</button></form></div></div><div class="upgrade-pending-cards"><?php if($pendingOut) $upgradeDetail($pendingOut,'Sai'); ?><span class="upgrade-arrow" aria-hidden="true">→</span><?php if($pendingIn) $upgradeDetail($pendingIn,'Entra'); ?></div></section>
<?php endforeach; endif; ?>
<?php if($selectionStage==='review' && $upgradeIncoming && $finalCount===100): ?>
<section class="upgrade-chooser" id="upgrade"><div class="section-heading"><div><h3>Escolha o que sai</h3><p>Compare a carta que entra com cada carta final sugerida para sair. A escolha apenas cria um plano; nada é substituído ainda.</p></div><span>100 + 1 upgrade</span></div><div class="upgrade-incoming-preview"><?php $upgradeDetail($upgradeIncoming,'Entra'); ?></div><div class="upgrade-cut-grid"><?php foreach($upgradeCuts as $cut): ?><article class="upgrade-cut"><?php $upgradeDetail($cut,'Sai'); ?><div class="upgrade-cut-meta"><small><?= (float)$cut['cut_score'] ? 'Associação EDHREC: '.number_format((float)$cut['cut_score']*100,0,',','.').'%' : 'Sem associação encontrada na cache' ?></small><form method="post"><?php $tokenFields('confirm_upgrade',$upgradeIncoming['id']); ?><input type="hidden" name="remove_card" value="<?= h($cut['id']) ?>"><input type="hidden" name="reason" value="Plano de upgrade sugerido pela menor associação EDHREC."><button class="primary-link">Usar como carta que sai</button></form></div></article><?php endforeach; ?></div></section>
<?php endif; ?>
<?php if(!$groups): ?><p class="empty-state">Nenhuma carta nesta etapa. Explore as recomendações ou mova uma carta de outra etapa.</p><?php endif; ?>
<div class="selection-groups stage-<?= h($selectionStage) ?>">
<?php foreach($groups as $category=>$entries): ?>
<section class="selection-type"><h3><?= h($category) ?> <span><?= array_sum(array_column($entries,'quantity')) ?></span></h3><div class="selection-cards">
<?php foreach($entries as $entry): $preview=cardImageUrl($entry); ?>
<article class="selection-card <?= $category==='Comandante'?'is-commander':'' ?>">
<?php if($selectionStage!=='candidate'): ?><a class="selection-art" href="/card.php?id=<?= h($entry['id']) ?>"><img src="<?= h($preview??'') ?>" alt="<?= h($entry['name']) ?>" loading="lazy" width="146" height="204"><?php if($selectionStage==='deck'||$category==='Comandante') echo $inventoryBadge($entry); ?></a><?php endif; ?>
<div class="selection-copy">
<a class="card-name-preview" href="/card.php?id=<?= h($entry['id']) ?>" data-card-preview="<?= h($preview??'') ?>"><span class="selection-quantity"><?= (int)$entry['quantity'] ?></span> <?= h($entry['name']) ?></a>
<?php if($selectionStage!=='candidate'): ?><small><?= h(strtoupper((string)$entry['set_code'])) ?> #<?= h($entry['collector_number']) ?> · <?= (int)$entry['owned_printing'] ?> desta impressão · <?= h(deckPriceLabel($entry)) ?></small><?php endif; ?>
<?php if($selectionStage==='review'): ?><p class="review-note"><?= h($entry['notes']?:'O que esta carta acrescenta ao plano? Registre sua avaliação em “Editar carta”.') ?></p><div class="selection-actions"><?php if($finalCount===100): ?><form method="post"><?php $tokenFields('prepare_upgrade',$entry['id']); ?><button class="primary-link">Preparar upgrade</button></form><?php else: $moveButton($entry,'deck','Aprovar para o deck'); endif; ?><?php $moveButton($entry,'candidate','Voltar às candidatas'); ?></div><?php endif; ?>
<?php if($category!=='Comandante'): ?><details class="selection-editor"><summary>Editar carta</summary>
<?php if($selectionStage==='candidate'): ?><img class="candidate-touch-preview" src="<?= h($preview??'') ?>" alt="<?= h($entry['name']) ?>" loading="lazy" width="146" height="204"><?php endif; ?>
<div class="selection-card-detail"><div><strong><?= h($entry['name']) ?></strong><span><?= h(strtoupper((string)$entry['set_code'])) ?> #<?= h($entry['collector_number']) ?> · <?= h(deckPriceLabel($entry)) ?></span><span><?= h($entry['type_line'] ?: 'Tipo não informado') ?></span><span><?= h($entry['mana_cost'] ?: 'Sem custo de mana') ?></span><small><?= nl2br(h(deckText($entry) ?: 'Texto Oracle não disponível.')) ?></small></div></div>
<form method="post" class="builder-form"><?php $tokenFields('item',$entry['id']); ?>
<label>Etapa<select name="stage"><?php $stageOptions=['candidate'=>['candidate','review'],'review'=>['candidate','review','deck'],'deck'=>['deck','review']][$entry['stage']]??[$entry['stage']]; foreach($stageOptions as $key): ?><option value="<?= h($key) ?>" <?= $key===$entry['stage']?'selected':'' ?>><?= h($stages[$key]) ?></option><?php endforeach; ?></select></label>
<label>Quantidade<input type="number" name="quantity" min="1" max="1000" value="<?= (int)$entry['quantity'] ?>" required></label>
<label>Função<input name="role" value="<?= h($entry['role']) ?>" maxlength="100" placeholder="Compra, ramp, proteção…"></label>
<label>Minha avaliação<textarea name="notes" maxlength="2000" rows="3"><?= h($entry['notes']) ?></textarea></label>
<button class="secondary-link">Salvar</button><button name="action" value="remove" class="builder-remove">Retirar da seleção</button></form>
</details><?php endif; ?></div></article>
<?php endforeach; ?></div></section><?php endforeach; ?></div></section>
