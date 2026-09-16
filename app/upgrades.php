<?php
declare(strict_types=1);
require __DIR__ . '/functions.php';
require __DIR__ . '/partials.php';
require __DIR__ . '/deck_library.php';
$authUser=authRequireLogin();
$userId=(int)$authUser['id'];
$_SESSION['upgrade_csrf'] ??= bin2hex(random_bytes(24));
$csrf=$_SESSION['upgrade_csrf']; $message=$_SESSION['upgrade_message']??''; unset($_SESSION['upgrade_message']);
$error=''; deckSchema();
$deckId=max(0,(int)($_GET['deck']??$_POST['deck']??0));
if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        if(!hash_equals($csrf,(string)($_POST['csrf']??''))) throw new RuntimeException('Sessão expirada. Recarregue a página e tente novamente.');
        $action=(string)($_POST['action']??'');
        $deck=deckQuery("SELECT * FROM builder_decks WHERE id=? AND user_id=? AND status='ready'",[$deckId,$userId])->fetch();
        if(!$deck) throw new RuntimeException('Escolha um deck finalizado.');
        if($action==='create'){
            $remove=(string)($_POST['remove_card']??''); $add=(string)($_POST['add_card']??'');
            if(!preg_match('/^[a-f0-9-]{36}$/i',$remove)||!preg_match('/^[a-f0-9-]{36}$/i',$add)) throw new RuntimeException('Escolha as duas cartas da troca.');
            $out=deckQuery("SELECT c.* FROM builder_items i JOIN cards c ON c.id=i.card_id WHERE i.deck_id=? AND i.card_id=? AND i.stage='deck'",[$deckId,$remove])->fetch();
            $incoming=deckQuery('SELECT c.*,COALESCE(o.quantity,0) owned_printing FROM cards c LEFT JOIN '.deckCollectionPrintingSql().' o ON o.scryfall_id=c.id WHERE c.id=?',[$add])->fetch();
            if(!$out) throw new RuntimeException('A carta que sai não pertence à lista finalizada deste deck.');
            if(!$incoming) throw new RuntimeException('Escolha uma impressão existente no catálogo local para entrar.');
            if(($out['oracle_id']?:$out['id'])===($incoming['oracle_id']?:$incoming['id'])) throw new RuntimeException('Escolha cartas diferentes para registrar a troca.');
            deckQuery('INSERT INTO deck_upgrades(deck_id,remove_card_id,add_card_id,reason) VALUES (?,?,?,?)',[$deckId,$remove,$add,substr(trim((string)($_POST['reason']??'')),0,2000)]);
            $message='Troca registrada. A lista original do deck não foi alterada.';
        }elseif(in_array($action,['toggle','delete'],true)){
            $upgradeId=max(0,(int)($_POST['upgrade']??0));
            $row=deckQuery('SELECT * FROM deck_upgrades WHERE id=? AND deck_id=?',[$upgradeId,$deckId])->fetch();
            if(!$row) throw new RuntimeException('Plano de upgrade não encontrado.');
            if($action==='delete'){deckQuery('DELETE FROM deck_upgrades WHERE id=?',[$upgradeId]);$message='Plano removido.';}
            else{deckQuery("UPDATE deck_upgrades SET status=CASE status WHEN 'planned' THEN 'done' ELSE 'planned' END WHERE id=?",[$upgradeId]);$message=$row['status']==='planned'?'Troca marcada como concluída.':'Troca devolvida ao planejamento.';}
        }else throw new RuntimeException('Ação inválida.');
        $_SESSION['upgrade_message']=$message; header('Location: /upgrades.php?deck='.$deckId,true,303);exit;
    }catch(Throwable $e){$error=$e instanceof RuntimeException&&!($e instanceof PDOException)?$e->getMessage():'Não foi possível salvar o plano. Tente novamente.';}
}
session_write_close();
$decks=deckQuery("SELECT d.*,c.name commander,(SELECT COUNT(*) FROM deck_upgrades u WHERE u.deck_id=d.id AND u.status='planned') pending FROM builder_decks d LEFT JOIN cards c ON c.id=d.commander_id WHERE d.status='ready' AND d.user_id=? ORDER BY d.name",[$userId])->fetchAll();
$deck=$deckId?deckQuery("SELECT d.*,c.name commander,c.color_identity FROM builder_decks d LEFT JOIN cards c ON c.id=d.commander_id WHERE d.id=? AND d.user_id=? AND d.status='ready'",[$deckId,$userId])->fetch():null;
if(!$deck)$deckId=0;
$outId=(string)($_GET['out']??''); $q=substr(trim((string)($_GET['q']??'')),0,160);
$deckCards=$deck?deckQuery("SELECT c.*,i.quantity,COALESCE(bc.quantity,0) owned_printing FROM builder_items i JOIN cards c ON c.id=i.card_id LEFT JOIN ".deckCollectionPrintingSql()." bc ON bc.scryfall_id=c.id WHERE i.deck_id=? AND i.stage='deck' ORDER BY c.name",[$deckId])->fetchAll():[];
$outCard=null; foreach($deckCards as $candidate) if($candidate['id']===$outId)$outCard=$candidate;
$incoming=[];
if($deck&&$outCard&&$q!==''){
    $like='%'.str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$q).'%';
    $incoming=deckQuery("SELECT c.*,COALESCE(o.quantity,0) owned_printing FROM cards c LEFT JOIN ".deckCollectionPrintingSql()." o ON o.scryfall_id=c.id WHERE c.name ILIKE ? AND COALESCE(c.oracle_id,c.id)<>?::uuid ORDER BY (o.quantity IS NOT NULL) DESC,c.name,".deckCheapestPriceSql('c')." ASC NULLS LAST,(c.lang='en') DESC,c.released_at DESC NULLS LAST LIMIT 24",[$like,$outCard['oracle_id']?:$outCard['id']])->fetchAll();
}
$plans=$deck?deckQuery("SELECT u.* FROM deck_upgrades u WHERE u.deck_id=? ORDER BY (u.status='planned') DESC,u.created_at DESC",[$deckId])->fetchAll():[];
foreach($plans as &$plan){$plan['remove_card']=deckQuery('SELECT c.*,COALESCE(o.quantity,0) owned_printing FROM cards c LEFT JOIN '.deckCollectionPrintingSql().' o ON o.scryfall_id=c.id WHERE c.id=?',[$plan['remove_card_id']])->fetch();$plan['add_card']=deckQuery('SELECT c.*,COALESCE(o.quantity,0) owned_printing FROM cards c LEFT JOIN '.deckCollectionPrintingSql().' o ON o.scryfall_id=c.id WHERE c.id=?',[$plan['add_card_id']])->fetch();}unset($plan);
pageHeader('Upgrades');
?>
<section class="hero upgrade-hero"><div><h1>Planeje a próxima versão</h1><p>Escolha uma carta do deck atual e qualquer impressão do catálogo para entrar no lugar. O Deckarium informa se ela está na coleção, mas não limita sua escolha.</p></div><a class="text-link" href="/decks.php">Criar ou planejar decks</a></section>
<?php if($message):?><p class="notice ok" role="status"><?=h($message)?></p><?php endif;if($error):?><p class="notice error" role="alert"><?=h($error)?></p><?php endif;?>
<?php if(!$decks):?><section class="upgrade-empty"><img src="/assets/deckarium-mark-v2.png" alt="" width="180" height="180"><div><h2>Finalize um deck para começar</h2><p>Importe uma lista pronta ou marque um planejamento como finalizado. Depois ele aparecerá aqui sem que você precise duplicar cartas.</p><a class="primary-link" href="/decks.php">Ir para Meus decks</a></div></section><?php else:?>
<nav class="deck-switcher" aria-label="Deck para planejar upgrades"><?php foreach($decks as $item):?><a href="?deck=<?=$item['id']?>" <?=$deckId===(int)$item['id']?'aria-current="page"':''?>><span><strong><?=h($item['name'])?></strong><small><?=h($item['commander']?:'Sem comandante')?></small></span><?php if((int)$item['pending']):?><b><?=$item['pending']?> planejada<?=((int)$item['pending']===1?'':'s')?></b><?php endif;?></a><?php endforeach;?></nav>
<?php if(!$deck):?><p class="empty-state">Escolha acima qual deck finalizado deseja revisar.</p><?php else:?>
<section class="upgrade-planner"><div class="planner-heading"><div><h2>Monte uma troca</h2><p>Primeiro selecione o que sai. Depois busque qualquer carta do catálogo e confira se a impressão está na coleção.</p></div><span><?=count($deckCards)?> cartas na lista</span></div>
<div class="upgrade-steps"><section><h3>1. Escolha o que sai</h3><div class="deck-card-picker"><?php foreach($deckCards as $card):?><a href="?deck=<?=$deckId?>&out=<?=h($card['id'])?>#new-upgrade" class="<?=$outId===$card['id']?'is-selected':''?>"><?php if($src=cardImageUrl($card,'front','small')):?><img src="<?=h($src)?>" alt="" loading="lazy"><?php endif;?><span><strong><?=h($card['name'])?></strong><small><?=h(strtoupper((string)$card['set_code']))?> #<?=h($card['collector_number'])?> · <?=$card['quantity']?> no deck</small></span></a><?php endforeach;?></div></section>
<section id="new-upgrade"><h3>2. Encontre o que entra</h3><?php if(!$outCard):?><p class="empty-state compact-empty">Selecione uma carta da lista ao lado para liberar a busca.</p><?php else:?><div class="selected-out"><span>Sai</span><strong><?=h($outCard['name'])?></strong><small><?=h(strtoupper((string)$outCard['set_code']))?> #<?=h($outCard['collector_number'])?></small></div><form method="get" class="upgrade-search"><input type="hidden" name="deck" value="<?=$deckId?>"><input type="hidden" name="out" value="<?=h($outId)?>"><label for="upgrade-q">Buscar em todo o catálogo</label><div><input id="upgrade-q" name="q" value="<?=h($q)?>" placeholder="Nome da carta" required><button class="secondary-link">Buscar</button></div></form><?php if($q!==''&&!$incoming):?><p class="empty-state compact-empty">Nenhuma impressão encontrada no catálogo local. Tente outro nome.</p><?php endif;?><div class="printing-results"><?php foreach($incoming as $card):?><form method="post" class="printing-option"><input type="hidden" name="csrf" value="<?=h($csrf)?>"><input type="hidden" name="action" value="create"><input type="hidden" name="deck" value="<?=$deckId?>"><input type="hidden" name="remove_card" value="<?=h($outId)?>"><input type="hidden" name="add_card" value="<?=h($card['id'])?>"><?php if($src=cardImageUrl($card,'front','small')):?><img src="<?=h($src)?>" alt="" loading="lazy"><?php endif;?><div><strong><?=h($card['name'])?></strong><small><?=h(strtoupper((string)$card['set_code']))?> #<?=h($card['collector_number'])?> · <?=((int)$card['owned_printing']>0?(int)$card['owned_printing'].' na coleção':'fora da coleção')?></small><label>Motivo da troca<textarea name="reason" rows="2" maxlength="2000" placeholder="O que esta mudança melhora?"></textarea></label><button class="primary-link">Registrar esta troca</button></div></form><?php endforeach;?></div><?php endif;?></section></div></section>
<section class="planned-section"><div class="section-heading"><div><h2>Mudanças registradas</h2><p class="muted">Um histórico visual do que você pretende testar, sem alterar o deck automaticamente.</p></div><span><?=count($plans)?> troca<?=count($plans)===1?'':'s'?></span></div><?php if(!$plans):?><p class="empty-state">Nenhuma troca registrada para este deck.</p><?php endif;?><div class="planned-list"><?php foreach($plans as $plan):$out=$plan['remove_card'];$in=$plan['add_card'];?><article class="planned-swap <?=$plan['status']==='done'?'is-done':''?>"><div class="planned-pair"><figure><?php if($src=cardImageUrl($out,'front','small')):?><img src="<?=h($src)?>" alt="<?=h($out['name'])?>" loading="lazy"><?php endif;?><figcaption><span>Sai</span><strong><?=h($out['name'])?></strong><small><?=h(strtoupper((string)$out['set_code']))?> #<?=h($out['collector_number'])?></small></figcaption></figure><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14m-5-5 5 5-5 5"/></svg><figure><?php if($src=cardImageUrl($in,'front','small')):?><img src="<?=h($src)?>" alt="<?=h($in['name'])?>" loading="lazy"><?php endif;?><figcaption><span>Entra · <?=$in['owned_printing']?> na coleção</span><strong><?=h($in['name'])?></strong><small><?=h(strtoupper((string)$in['set_code']))?> #<?=h($in['collector_number'])?></small></figcaption></figure></div><div class="planned-note"><span class="plan-status"><?=$plan['status']==='done'?'Concluída':'Planejada'?></span><p><?=h($plan['reason']?:'Sem observação adicionada.')?></p><form method="post"><input type="hidden" name="csrf" value="<?=h($csrf)?>"><input type="hidden" name="deck" value="<?=$deckId?>"><input type="hidden" name="upgrade" value="<?=$plan['id']?>"><button name="action" value="toggle" class="secondary-link"><?=$plan['status']==='done'?'Reabrir':'Marcar concluída'?></button><button name="action" value="delete" class="builder-remove">Excluir registro</button></form></div></article><?php endforeach;?></div></section>
<?php endif;endif;pageFooter();?>
