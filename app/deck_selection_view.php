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
// Relações e metas: sem nota, ranking ou aprovação automática.
$scoreConfig = deckScoreConfigFor($deck, $commander ?: null);
$scores = $commander ? deckScoreSelection($deck, $commander, $items, $scoreConfig) : null;
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
if (!empty($_GET['upgrade_card'])) foreach($items as $candidate) if($candidate['id']===(string)$_GET['upgrade_card'] && $candidate['stage']==='candidate') {$upgradeIncoming=$candidate;break;}
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
// Lista de relações: cada linha diz o que esta carta dá ou recebe e mostra o trecho do texto dos dois lados.
$relationshipList=function(array $relationships): void {
    $groups=DECK_RELATION_GROUPS; ?>
<ul class="relationship-list relationship-list-v2"><?php foreach($relationships as $relationship): ?><li>
    <strong><?= h($relationship['name']) ?></strong>
    <?php foreach([['gives','out','Esta carta'],['takes','in',$relationship['name']]] as [$side,$direction,$subject]): foreach(($relationship[$side]??[]) as $reason): $color=$groups[$reason['group']][1]??'#59625f'; ?>
    <div class="relation-reason is-<?= $direction ?>" style="--relation-color:<?= h($color) ?>">
        <span class="relation-reason-head"><b class="relation-chip"><?= h($reason['label']) ?></b><?= $direction==='out' ? 'Esta carta '.h($reason['give']).' <span aria-hidden="true">→</span> '.h(preg_replace('/ · comandante$/','',$relationship['name'])).' '.h($reason['take']) : h(preg_replace('/ · comandante$/','',$relationship['name'])).' '.h($reason['give']).' <span aria-hidden="true">→</span> esta carta '.h($reason['take']) ?><?php if(($reason['source']??'')==='tag'): ?> <small class="relation-source">Scryfall Tagger</small><?php endif; ?></span>
        <span class="relation-quotes"><q><?= h($reason['from_text']) ?></q><q><?= h($reason['to_text']) ?></q></span>
    </div>
    <?php endforeach; endforeach; ?>
</li><?php endforeach; ?></ul>
<?php };
$stageHints=[
    'candidate'=>'Cartas que você guardou para comparar. Abra um tipo, clique numa carta para anotar o que ela acrescenta e aprove as que entram no deck.',
    'deck'=>'A versão escolhida do seu deck, organizada por tipo. Clique em uma carta para ajustar ou devolvê-la às candidatas.',
];
?>
<section id="selection" class="selection-workspace" data-selection-workspace="<?= (int)$id ?>-<?= h($selectionStage) ?>">
<div class="section-heading"><div><h2>Minha seleção</h2><p><?= h($stageHints[$selectionStage]) ?></p></div><a href="?deck=<?= $id ?>&view=explore">Descobrir cartas</a></div>
<nav class="tabs selection-tabs" aria-label="Etapas da seleção"><?php foreach($stages as $key=>$label): ?><a href="?deck=<?= $id ?>&view=selection&stage=<?= h($key) ?>" <?= $key===$selectionStage?'aria-current="page"':'' ?>><?= h($label) ?> <span><?= $stageCounts[$key] ?></span></a><?php endforeach; ?></nav>
<?php if($pendingUpgrades): foreach($pendingUpgrades as $pendingUpgrade): $pendingOut=deckQuery('SELECT * FROM cards WHERE id=?',[$pendingUpgrade['remove_card_id']])->fetch(); $pendingIn=deckQuery('SELECT * FROM cards WHERE id=?',[$pendingUpgrade['add_card_id']])->fetch(); ?>
<section class="upgrade-pending" id="upgrade-<?= (int)$pendingUpgrade['id'] ?>"><div class="upgrade-pending-copy"><span class="plan-status">100 + 1 upgrade pendente</span><h3><?= h($pendingUpgrade['add_name']) ?> sobre <?= h($pendingUpgrade['remove_name']) ?></h3><p>Compare os dois lados antes de confirmar. O deck físico continua com 100 cartas até a troca ser aprovada.</p><div class="selection-actions"><form method="post"><?php $tokenFields('apply_upgrade'); ?><input type="hidden" name="upgrade" value="<?= (int)$pendingUpgrade['id'] ?>"><button class="primary-link">Confirmar troca</button></form><form method="post"><?php $tokenFields('cancel_upgrade'); ?><input type="hidden" name="upgrade" value="<?= (int)$pendingUpgrade['id'] ?>"><button class="secondary-link">Cancelar upgrade</button></form></div></div><div class="upgrade-pending-cards"><?php if($pendingOut) $upgradeDetail($pendingOut,'Sai'); ?><span class="upgrade-arrow" aria-hidden="true">→</span><?php if($pendingIn) $upgradeDetail($pendingIn,'Entra'); ?></div></section>
<?php endforeach; endif; ?>
<?php if($selectionStage==='candidate' && $upgradeIncoming && $finalCount===100): ?>
<section class="upgrade-chooser" id="upgrade"><div class="section-heading"><div><h3>Escolha o que sai</h3><p>Compare a carta que entra com cada carta final sugerida para sair. A escolha apenas cria um plano; nada é substituído ainda.</p></div><span>100 + 1 upgrade</span></div><div class="upgrade-incoming-preview"><?php $upgradeDetail($upgradeIncoming,'Entra'); ?></div><div class="upgrade-cut-grid"><?php foreach($upgradeCuts as $cut): ?><article class="upgrade-cut"><?php $upgradeDetail($cut,'Sai'); ?><div class="upgrade-cut-meta"><small><?= (float)$cut['cut_score'] ? 'Associação EDHREC: '.number_format((float)$cut['cut_score']*100,0,',','.').'%' : 'Sem associação encontrada na cache' ?></small><form method="post"><?php $tokenFields('confirm_upgrade',$upgradeIncoming['id']); ?><input type="hidden" name="remove_card" value="<?= h($cut['id']) ?>"><input type="hidden" name="reason" value="Plano de upgrade sugerido pela menor associação EDHREC."><button class="primary-link">Usar como carta que sai</button></form></div></article><?php endforeach; ?></div></section>
<?php endif; ?>

<?php
$bulkEntries = array_filter($items, fn($entry) => $entry['stage'] === $selectionStage);
$openSlots = max(0, 100 - $finalCount);
$bulkMoves = ['candidate' => [['deck', 'Aprovar para o deck →', 'primary-link']], 'deck' => [['candidate', '← Voltar às candidatas', 'secondary-link']]][$selectionStage];
?>
<?php if($scores && $groups): require __DIR__.'/deck_scoring_view.php'; elseif(!$commander && $groups): ?><p class="notice warning">Escolha a comandante para calcular o Índice de Encaixe das cartas.</p><?php endif; ?>
<?php if(!$groups): ?>
<p class="empty-state">Nenhuma carta nesta etapa. <a href="?deck=<?= $id ?>&view=explore">Explore o catálogo</a> ou mova uma carta de outra etapa.</p>
<?php else: ?>
<div class="selection-toolbar"><span><?= count($groups) ?> <?= count($groups)===1?'tipo':'tipos' ?> · <?= array_sum(array_map(fn($entries)=>array_sum(array_column($entries,'quantity')),$groups)) ?> cartas</span><div><?php if($bulkEntries): ?><button type="button" class="selection-toggle-all selection-bulk-toggle" data-bulk-toggle aria-pressed="false" aria-controls="bulk-move-form">Selecionar várias</button><?php endif; ?><button type="button" class="selection-toggle-all" data-selection-expand>Abrir todos</button><button type="button" class="selection-toggle-all" data-selection-collapse>Fechar todos</button></div></div>
<?php endif; ?>

<div class="selection-groups stage-<?= h($selectionStage) ?>">
<?php foreach($groups as $category=>$entries): $groupKey=substr(md5($category),0,10); $groupCount=array_sum(array_column($entries,'quantity')); $groupPickable=$category==='Comandante'?0:count($entries); ?>
<div class="selection-type-wrap">
<?php if($groupPickable): ?><button type="button" class="selection-group-pick" data-bulk-group="<?= h($groupKey) ?>">Marcar <?= h(mb_strtolower($category)) ?></button><?php endif; ?>
<details class="selection-type" data-selection-group="<?= h($groupKey) ?>">
<summary>
    <span class="selection-type-title"><?= h($category) ?> <b><?= $groupCount ?></b></span>
    <span class="selection-type-peek" aria-hidden="true"><?php foreach(array_slice($entries,0,5) as $peek): if($peekSrc=cardImageUrl($peek,'front','small')): ?><img src="<?= h($peekSrc) ?>" alt="" loading="lazy" width="34" height="47"><?php endif; endforeach; ?><?php if(count($entries)>5): ?><i>+<?= count($entries)-5 ?></i><?php endif; ?></span>
    <span class="selection-type-chevron" aria-hidden="true"></span>
</summary>
<div class="selection-slots">
<?php foreach($entries as $entry): $preview=cardImageUrl($entry); $isCommander=$category==='Comandante'; $dialogId='selection-card-'.$entry['id']; $hasNotes=trim((string)($entry['notes']??''))!==''; $fit=$isCommander?null:($scores['cards'][$entry['id']]??null); ?>
<article class="selection-slot <?= $isCommander?'is-commander':'' ?>">
<?php if(!$isCommander): ?><label class="selection-pick"><input type="checkbox" name="cards[]" value="<?= h($entry['id']) ?>" form="bulk-move-form" data-bulk-card data-quantity="<?= (int)$entry['quantity'] ?>"><span class="sr-only">Marcar <?= h($entry['name']) ?></span></label><?php endif; ?>
<?php if($isCommander): ?>
    <a class="selection-tile" href="/card.php?id=<?= h($entry['id']) ?>" title="Abrir página da comandante">
<?php else: ?>
    <button type="button" class="selection-tile" data-selection-open="<?= h($dialogId) ?>" aria-haspopup="dialog" aria-label="Avaliar <?= h($entry['name']) ?>">
<?php endif; ?>
        <span class="selection-art"><?php if($preview): ?><img src="<?= h($preview) ?>" alt="<?= h($entry['name']) ?>" loading="lazy" width="244" height="340"><?php else: ?><span class="placeholder image-fallback"><strong><?= h($entry['name']) ?></strong><span>Imagem indisponível</span></span><?php endif; ?>
        <?php if((int)$entry['quantity']>1): ?><b class="selection-qty"><?= (int)$entry['quantity'] ?>×</b><?php endif; ?>
        <?php if(deckIsGameChanger($entry)): ?><b class="gc-badge selection-gc" title="Game Changer">GC</b><?php endif; ?>
        <?php $relationshipCount=$fit?array_sum(array_map('count',$fit['relationships'])):0; if(!$isCommander && $relationshipCount): ?><b class="selection-relation" title="<?= $relationshipCount ?> relações diretas; abra a carta para ver quais"><?= $relationshipCount ?> relação<?= $relationshipCount===1?'':'ões' ?></b><?php endif; ?>
        <?php echo $inventoryBadge($entry); ?></span>
        <span class="selection-tile-name"><?= h($entry['name']) ?></span>
        <span class="selection-tile-meta"><?php if($isCommander): ?>Comandante<?php else: ?><?= $entry['role']!==''&&$entry['role']!==null?h($entry['role']):($fit?h(implode(', ',array_slice($fit['roles'],0,2))):'Sem função') ?><?php if($hasNotes): ?> · <span class="selection-noted">avaliada</span><?php endif; ?><?php endif; ?></span>
        <?php if(!$isCommander && $entry['synergy_score']!==null): ?><span class="selection-tile-synergy"><?= $entry['synergy_metric']==='lift'?'Lift EDHREC: '.number_format((float)$entry['synergy_score'],2,',','.').' · sinergia não percentual':'Sinergia EDHREC: '.sprintf('%+.0f',(float)$entry['synergy_score']*100).'%' ?></span><?php endif; ?>
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
            <details class="fit-breakdown relationship-breakdown" <?= $selectionStage==='candidate'?'open':'' ?>>
                <?php $relationshipCount=array_sum(array_map('count',$fit['relationships'])); ?>
                <summary><span><span class="relationship-title"><strong><?= $entry['stage']==='candidate' ? 'Relações com candidatas' : 'Relações da carta' ?></strong><span class="relationship-question" tabindex="0" role="note" aria-label="Como as relações funcionam" data-help="Uma relação aparece quando uma carta fornece algo que outra aproveita — fichas, Tesouros, mortes, marcadores, terrenos entrando, uma tribo. Cada linha mostra o trecho do texto das duas cartas. Isso descreve interação, não força.">?</span></span><small><?= $relationshipCount ? $relationshipCount.' conexão(ões) direta(s)' : 'Nenhuma relação direta nesta etapa' ?></small></span></summary>
                <?php if($fit['blocked']): ?><p class="fit-alert is-blocked"><?= h($fit['blocked']) ?></p><?php endif; ?>
                <?php foreach($fit['notes'] as $fitNote): ?><p class="fit-alert"><?= h($fitNote) ?></p><?php endforeach; ?>
                <?php if($entry['stage']==='candidate'): ?>
                    <?php if($fit['relationships']['candidates']): ?><?php $relationshipList($fit['relationships']['candidates']); ?><?php else: ?><p class="fit-alert">Ainda não há uma oferta ou necessidade textual que conecte esta carta às candidatas. Isso não é uma avaliação negativa.</p><?php endif; ?>
                <?php else: ?>
                    <section class="relationship-group"><h4>Com o deck</h4><?php if($fit['relationships']['deck']): ?><?php $relationshipList($fit['relationships']['deck']); ?><?php else: ?><p class="fit-alert">Nenhuma relação direta com as cartas já no deck.</p><?php endif; ?></section>
                    <section class="relationship-group"><h4>Com as candidatas</h4><?php if($fit['relationships']['candidates']): ?><?php $relationshipList($fit['relationships']['candidates']); ?><?php else: ?><p class="fit-alert">Nenhuma relação direta com as candidatas.</p><?php endif; ?></section>
                <?php endif; ?>
                <p class="relationship-board-link"><a href="/deck_board.php?deck=<?= (int)$id ?>&amp;focus=<?= h($entry['id']) ?>">Ver esta carta no quadro de relações →</a></p>
                <p class="fit-tags"><?php if($fit['roles']): ?><span><em>Função</em> <?= h(implode(', ',$fit['roles'])) ?></span><?php endif; ?><?php if($fit['produces']): ?><span><em>Produz</em> <?= h(implode(', ',array_slice($fit['produces'],0,5))) ?></span><?php endif; ?><?php if($fit['cares']): ?><span><em>Procura</em> <?= h(implode(', ',array_slice($fit['cares'],0,5))) ?></span><?php endif; ?></p>
            </details>
            <?php endif; ?>

            <div class="selection-dialog-moves">
                <?php if($entry['stage']==='candidate'): ?>
                    <?php if($finalCount===100): ?><form method="post"><?php $tokenFields('prepare_upgrade',$entry['id']); ?><button class="primary-link">Preparar upgrade</button></form><?php else: ?><form method="post"><?php $tokenFields('move',$entry['id']); ?><input type="hidden" name="stage" value="deck"><button class="primary-link">Aprovar para o deck →</button></form><?php endif; ?>
                <?php elseif($entry['stage']==='deck'): $moveButton($entry,'candidate','← Voltar às candidatas'); ?>
                <?php endif; ?>
            </div>

            <form method="post" class="builder-form selection-dialog-form"><?php $tokenFields('item',$entry['id']); ?>
                <div class="selection-dialog-fields">
                    <label>Etapa<select name="stage"><?php $stageOptions=$stageMoves[$entry['stage']]??[$entry['stage']]; foreach($stageOptions as $key): ?><option value="<?= h($key) ?>" <?= $key===$entry['stage']?'selected':'' ?>><?= h($stages[$key]) ?></option><?php endforeach; ?></select></label>
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
</div>
<?php endforeach; ?>
</div>
<?php if($bulkEntries): ?>
<form method="post" id="bulk-move-form" class="selection-bulkbar" data-bulk-bar data-open-slots="<?= $openSlots ?>" hidden><?php $tokenFields('bulk_move'); ?>
    <div class="selection-bulk-info">
        <strong data-bulk-count role="status" aria-live="polite">Nenhuma carta marcada</strong>
        <span class="selection-bulk-quick">
            <button type="button" data-bulk-all>Marcar todas</button>
            <button type="button" data-bulk-none>Desmarcar</button>
        </span>
        <small class="selection-bulk-warning" data-bulk-warning hidden></small>
    </div>
    <div class="selection-bulk-actions">
        <?php foreach($bulkMoves as [$destination,$label,$class]): ?>
        <button type="submit" name="stage" value="<?= h($destination) ?>" class="<?= h($class) ?>" data-bulk-submit="<?= h($destination) ?>" disabled><?= h($label) ?></button>
        <?php endforeach; ?>
        <?php if($selectionStage==='candidate'): ?><button type="submit" name="action" value="bulk_remove" class="selection-bulk-remove" data-bulk-submit="remove" disabled>Remover das candidatas</button><?php endif; ?>
        <button type="button" class="selection-toggle-all" data-bulk-exit>Concluir</button>
    </div>
</form>
<?php endif; ?>
</section>
