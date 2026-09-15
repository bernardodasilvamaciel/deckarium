<?php
declare(strict_types=1);
require __DIR__ . '/functions.php';
require __DIR__ . '/partials.php';
require __DIR__ . '/deck_library.php';
session_start();
$_SESSION['builder_csrf'] ??= bin2hex(random_bytes(24));
$csrf = $_SESSION['builder_csrf'];
deckSchema();
$id = max(0,(int)($_GET['deck'] ?? $_POST['deck'] ?? 0));
$message = $_SESSION['builder_message'] ?? ''; unset($_SESSION['builder_message']);
$error = '';
$view = ($_GET['view'] ?? $_POST['view'] ?? '') === 'selection' ? 'selection' : 'discover';
$discoverMode = ($_GET['mode'] ?? $_POST['mode'] ?? 'synergy') === 'catalog' ? 'catalog' : 'synergy';
$showSynergy = ($_GET['synergy'] ?? '1') === '1';
$selectionStage = (string)($_GET['stage'] ?? $_POST['selection_stage'] ?? 'candidate');
if (!in_array($selectionStage,['candidate','review','deck'],true)) $selectionStage='candidate';
$includeOutside = ($_GET['outside'] ?? $_POST['outside'] ?? '1') === '1';
$synergyPage = max(1,min(10000,(int)($_GET['synergy_page'] ?? $_POST['synergy_page'] ?? 1)));
$stages = ['candidate'=>'Candidatas','review'=>'Em avaliação','deck'=>'No deck'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals($csrf,(string)($_POST['csrf'] ?? ''))) throw new RuntimeException('Sessão expirada. Recarregue a página e tente novamente.');
        $action = $_POST['action'] ?? '';
        if ($action === 'create') {
            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '' || strlen($name)>160) throw new RuntimeException('Informe um nome de até 160 caracteres.');
            $id = (int)deckQuery('INSERT INTO builder_decks(name) VALUES (?) RETURNING id',[$name])->fetchColumn();
            $message = 'Deck criado. Escolha o comandante ou comece explorando cartas.';
        } elseif ($action === 'import_deck') {
            $result=deckImportList((string)($_POST['name']??''),(string)($_POST['decklist']??''));
            $id=(int)$result['id']; $message=$result['matched'].' linhas importadas com as impressões disponíveis na coleção.';
            if($result['unmatched']) $message.=' Não localizadas: '.implode(', ',array_slice($result['unmatched'],0,8)).(count($result['unmatched'])>8?'…':'').'.';
        } elseif ($action === 'import') {
            if (($_FILES['collection']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('Selecione um CSV válido dentro do limite de upload do servidor.');
            $result = deckImport($_FILES['collection']['tmp_name']);
            $message = number_format($result['quantity'],0,',','.') . ' cartas importadas. As quantidades da coleção foram substituídas pelo CSV.';
        } else {
            $deck = deckQuery('SELECT * FROM builder_decks WHERE id=?',[$id])->fetch();
            if (!$deck) throw new RuntimeException('Deck não encontrado.');
            if ($action === 'delete_deck') {
                deckQuery('DELETE FROM builder_decks WHERE id=?',[$id]); $id=0; $message='Deck excluído. Sua coleção foi preservada.';
            } elseif ($action === 'strategy') {
                deckQuery('UPDATE builder_decks SET strategy=?,terms=? WHERE id=?',[substr(trim((string)$_POST['strategy']),0,5000),substr(trim((string)$_POST['terms']),0,1000),$id]);
                $message = 'Estratégia e termos salvos.';
            } elseif ($action === 'sync_edhrec') {
                if(!$deck['commander_id']) throw new RuntimeException('Escolha um comandante antes de buscar recomendações.');
                $commanderCard=deckQuery('SELECT * FROM cards WHERE id=?',[$deck['commander_id']])->fetch();
                $saved=deckSyncEdhrec($commanderCard); $message=$saved.' recomendações do EDHREC atualizadas.';
            } elseif ($action === 'prepare_upgrade') {
                if (!$deck['commander_id']) throw new RuntimeException('Escolha um comandante antes de preparar um upgrade.');
                $currentCount=(int)deckQuery("SELECT COALESCE(SUM(quantity),0) FROM builder_items WHERE deck_id=? AND stage='deck'",[$id])->fetchColumn()+1;
                if ($currentCount!==100) throw new RuntimeException('O deck precisa estar fechado com 100 cartas para preparar um upgrade.');
                $incoming=deckQuery('SELECT stage FROM builder_items WHERE deck_id=? AND card_id=?',[$id,(string)($_POST['card']??'')])->fetch();
                if (!$incoming || $incoming['stage']!=='review') throw new RuntimeException('A carta precisa passar por candidatas e avaliação antes do upgrade.');
                $message='Escolha abaixo qual carta sairá para abrir espaço para o upgrade.';
            } elseif ($action === 'confirm_upgrade') {
                $remove=(string)($_POST['remove_card']??''); $incoming=(string)($_POST['card']??'');
                $currentCount=(int)deckQuery("SELECT COALESCE(SUM(quantity),0) FROM builder_items WHERE deck_id=? AND stage='deck'",[$id])->fetchColumn()+1;
                if ($currentCount!==100) throw new RuntimeException('O deck precisa estar fechado com 100 cartas para confirmar um upgrade.');
                $out=deckQuery("SELECT c.* FROM builder_items i JOIN cards c ON c.id=i.card_id WHERE i.deck_id=? AND i.card_id=? AND i.stage='deck'",[$id,$remove])->fetch();
                $in=deckQuery("SELECT c.* FROM builder_items i JOIN cards c ON c.id=i.card_id WHERE i.deck_id=? AND i.card_id=? AND i.stage='review'",[$id,$incoming])->fetch();
                if(!$out||!$in) throw new RuntimeException('Escolha uma carta do deck para sair e uma carta em avaliação para entrar.');
                if(($out['oracle_id']?:$out['id'])===($in['oracle_id']?:$in['id'])) throw new RuntimeException('As cartas do upgrade precisam ser diferentes.');
                if(deckQuery("SELECT 1 FROM deck_upgrades WHERE deck_id=? AND status='planned' AND (remove_card_id=? OR add_card_id=?) LIMIT 1",[$id,$remove,$incoming])->fetchColumn()) throw new RuntimeException('Uma destas cartas já participa de outro upgrade pendente.');
                deckQuery('INSERT INTO deck_upgrades(deck_id,remove_card_id,add_card_id,reason) VALUES (?,?,?,?)',[$id,$remove,$incoming,substr(trim((string)($_POST['reason']??'')),0,2000)]);
                $message='Upgrade pendente registrado. O deck continua com 100 cartas até você confirmar a troca.';
            } elseif (in_array($action,['apply_upgrade','cancel_upgrade'],true)) {
                $upgradeId=max(0,(int)($_POST['upgrade']??0));
                $plan=deckQuery("SELECT * FROM deck_upgrades WHERE id=? AND deck_id=? AND status='planned'",[$upgradeId,$id])->fetch();
                if(!$plan) throw new RuntimeException('Upgrade pendente não encontrado.');
                if($action==='cancel_upgrade') { deckQuery('DELETE FROM deck_upgrades WHERE id=?',[$upgradeId]); $message='Upgrade cancelado. A seleção foi preservada.'; }
                else {
                    $out=deckQuery("SELECT * FROM builder_items WHERE deck_id=? AND card_id=? AND stage='deck'",[$id,$plan['remove_card_id']])->fetch();
                    $in=deckQuery("SELECT * FROM builder_items WHERE deck_id=? AND card_id=? AND stage='review'",[$id,$plan['add_card_id']])->fetch();
                    if(!$out||!$in) throw new RuntimeException('As cartas do upgrade precisam continuar no deck e em avaliação.');
                    deckQuery("UPDATE builder_items SET stage='review' WHERE deck_id=? AND card_id=?",[$id,$plan['remove_card_id']]);
                    deckQuery("UPDATE builder_items SET stage='deck' WHERE deck_id=? AND card_id=?",[$id,$plan['add_card_id']]);
                    deckQuery("UPDATE deck_upgrades SET status='done' WHERE id=?",[$upgradeId]); $message='Upgrade confirmado. A nova carta entrou e a anterior voltou para avaliação.';
                }
            } elseif (in_array($action,['commander','add','item','remove','move'],true)) {
                $cardId = (string)($_POST['card'] ?? '');
                if (!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/i',$cardId)) throw new RuntimeException('Carta inválida.');
                $card = deckQuery('SELECT * FROM cards WHERE id=?',[$cardId])->fetch();
                if (!$card) throw new RuntimeException('Carta não encontrada no acervo.');
                if ($action === 'commander') {
                    if (!deckQuery('SELECT 1 FROM cards c WHERE c.id=? AND '.deckCommanderSql(),[$cardId])->fetchColumn()) throw new RuntimeException('Escolha uma carta elegível como comandante.');
                    deckQuery('UPDATE builder_decks SET commander_id=? WHERE id=?',[$cardId,$id]);
                    deckQuery('DELETE FROM builder_items i USING cards c WHERE i.card_id=c.id AND i.deck_id=? AND COALESCE(c.oracle_id,c.id)=?::uuid',[$id,$card['oracle_id'] ?: $cardId]);
                    $message = 'Comandante definido. Confira a identidade de cor das cartas já selecionadas.';
                } elseif ($action === 'move') {
                    $stage=(string)($_POST['stage']??'');
                    if(!isset($stages[$stage])) throw new RuntimeException('Etapa inválida.');
                    $currentStage=(string)deckQuery('SELECT stage FROM builder_items WHERE deck_id=? AND card_id=?',[$id,$cardId])->fetchColumn();
                    $allowed=['candidate'=>['candidate','review'],'review'=>['candidate','review','deck'],'deck'=>['deck','review']];
                    if(!in_array($stage,$allowed[$currentStage]??[],true)) throw new RuntimeException('Siga a sequência candidatas → avaliação → deck.');
                    if($stage==='deck' && $currentStage!=='deck') {
                        if(!str_contains((string)$card['type_line'],'Basic') && (int)deckQuery('SELECT quantity FROM builder_items WHERE deck_id=? AND card_id=?',[$id,$cardId])->fetchColumn()>1) throw new RuntimeException('Commander permite apenas 1 cópia de cada carta. Apenas terrenos básicos podem ter quantidade maior.');
                        $currentCount=(int)deckQuery("SELECT COALESCE(SUM(quantity),0) FROM builder_items WHERE deck_id=? AND stage='deck'",[$id])->fetchColumn()+($deck['commander_id']?1:0);
                        if($currentCount>=100) throw new RuntimeException('O deck já tem 100 cartas. Use “Preparar upgrade” para escolher a carta que sairá.');
                    }
                    deckQuery('UPDATE builder_items SET stage=? WHERE deck_id=? AND card_id=?',[$stage,$id,$cardId]);
                    $message='Carta movida para '.$stages[$stage].'.';
                } elseif ($action === 'remove') {
                    deckQuery('DELETE FROM builder_items WHERE deck_id=? AND card_id=?',[$id,$cardId]);
                    $message = 'Carta retirada da seleção.';
                } elseif ($action === 'add') {
                    if(!$deck['commander_id']) throw new RuntimeException('Escolha uma comandante antes de adicionar cartas às candidatas.');
                    $logical = $card['oracle_id'] ?: $cardId;
                    $exists = deckQuery('SELECT 1 FROM builder_items i JOIN cards c ON c.id=i.card_id WHERE i.deck_id=? AND COALESCE(c.oracle_id,c.id)=?::uuid',[$id,$logical])->fetchColumn();
                    $commanderLogical = $deck['commander_id'] ? deckQuery('SELECT COALESCE(oracle_id,id) FROM cards WHERE id=?',[$deck['commander_id']])->fetchColumn() : null;
                    if ($exists || $commanderLogical === $logical) throw new RuntimeException('Essa carta já está no deck ou é o comandante. Ajuste a quantidade na seleção existente.');
                    deckQuery("INSERT INTO builder_items(deck_id,card_id,stage,quantity) VALUES (?,?,'candidate',1)",[$id,$cardId]);
                    $message = 'Carta adicionada às candidatas.';
                } else {
                    $stage = (string)($_POST['stage'] ?? '');
                    $quantity = filter_var($_POST['quantity'] ?? '',FILTER_VALIDATE_INT);
                    if (!isset($stages[$stage]) || !$quantity || $quantity<1 || $quantity>1000) throw new RuntimeException('Etapa ou quantidade inválida.');
                    if ($quantity>1 && !str_contains((string)$card['type_line'],'Basic')) throw new RuntimeException('Commander permite apenas 1 cópia de cada carta. Apenas terrenos básicos podem ter quantidade maior.');
                    $currentStage=(string)deckQuery('SELECT stage FROM builder_items WHERE deck_id=? AND card_id=?',[$id,$cardId])->fetchColumn();
                    $allowed=['candidate'=>['candidate','review'],'review'=>['candidate','review','deck'],'deck'=>['deck','review']];
                    if($stage!==$currentStage && !in_array($stage,$allowed[$currentStage]??[],true)) throw new RuntimeException('Siga a sequência candidatas → avaliação → deck.');
                    if($stage==='deck' && $currentStage!=='deck') {
                        $currentCount=(int)deckQuery("SELECT COALESCE(SUM(quantity),0) FROM builder_items WHERE deck_id=? AND stage='deck'",[$id])->fetchColumn()+($deck['commander_id']?1:0);
                        if($currentCount>=100) throw new RuntimeException('O deck já tem 100 cartas. Use “Preparar upgrade” para escolher a carta que sairá.');
                    }
                    deckQuery('UPDATE builder_items SET stage=?,quantity=?,role=?,notes=? WHERE deck_id=? AND card_id=?',[$stage,$quantity,substr(trim((string)$_POST['role']),0,100),substr(trim((string)$_POST['notes']),0,2000),$id,$cardId]);
                    $message = 'Seleção atualizada.';
                }
            } else throw new RuntimeException('Ação inválida.');
        }
        $_SESSION['builder_message'] = $message;
        $return = '/decks.php' . ($id ? '?deck='.$id : '');
        if ($id && in_array($action,['add','item','remove'],true)) {
            $return .= '&'.http_build_query(['q'=>(string)($_POST['q']??''),'oracle'=>(string)($_POST['oracle']??''),'type'=>(string)($_POST['type']??''),'match'=>(string)($_POST['match']??'all'),'owned'=>(string)($_POST['owned']??''),'colors'=>(string)($_POST['colors']??''),'page'=>max(1,(int)($_POST['page']??1))]);
            $return .= $action==='add' ? '#result-'.rawurlencode($cardId) : '#selection';
        }
        if($id && $view==='selection') {
            $return='/decks.php?deck='.$id.'&view=selection&stage='.urlencode($selectionStage);
            $return.=$action==='prepare_upgrade' ? '&upgrade_card='.rawurlencode((string)($_POST['card']??'')).'#upgrade' : '#selection';
        }
        if($id && ($action==='sync_edhrec' || ($_POST['origin']??'')==='synergy')) $return='/decks.php?deck='.$id.'&outside='.($includeOutside?'1':'0').'&synergy_page='.$synergyPage.'#synergy';
        header('Location: '.$return, true,303); exit;
    } catch (Throwable $e) { $error = $e instanceof RuntimeException && !($e instanceof PDOException) ? $e->getMessage() : 'Não foi possível salvar. Nenhuma seleção foi descartada; tente novamente.'; }
}
session_write_close();
$decks = deckQuery("SELECT d.*,c.name commander,c.id commander_card_id,(SELECT COALESCE(SUM(quantity),0) FROM builder_items WHERE deck_id=d.id AND stage='deck') + CASE WHEN d.commander_id IS NULL THEN 0 ELSE 1 END card_count FROM builder_decks d LEFT JOIN cards c ON c.id=d.commander_id ORDER BY d.id DESC")->fetchAll();
$deck = $id ? deckQuery('SELECT * FROM builder_decks WHERE id=?',[$id])->fetch() : null;
$commander = $deck && $deck['commander_id'] ? deckQuery("SELECT c.* FROM cards chosen JOIN cards c ON COALESCE(c.oracle_id,c.id)=COALESCE(chosen.oracle_id,chosen.id) LEFT JOIN builder_collection bc ON bc.scryfall_id=c.id WHERE chosen.id=? ORDER BY (COALESCE(bc.quantity,0)>0) DESC,(c.lang='en') DESC,(c.local_image IS NOT NULL) DESC,c.released_at DESC NULLS LAST,c.id LIMIT 1",[$deck['commander_id']])->fetch() : null;
$identity = $commander ? (json_decode($commander['color_identity'],true) ?: []) : [];
$collection = deckQuery('SELECT COALESCE(SUM(quantity),0) total,COUNT(*) printings,COUNT(*) FILTER(WHERE c.id IS NULL) unmatched FROM builder_collection o LEFT JOIN cards c ON c.id=o.scryfall_id')->fetch();
$q = substr(trim((string)($_GET['q']??'')),0,200);
$oracle = substr(trim((string)($_GET['oracle']??($deck['terms']??''))),0,1000);
$type = substr(trim((string)($_GET['type']??'')),0,120);
$match = ($_GET['match']??'all')==='any' ? 'any' : 'all';
$ownedOnly = false;
$colorsOnly = ($_GET['colors']??'')==='1';
$commanderColors=array_values(array_intersect((array)($_GET['commander_colors']??[]),['W','U','B','R','G','C']));
$choosingCommander = isset($_GET['choose']);
$commanderOwnedOnly = ($_GET['commander_owned'] ?? '1') === '1';
if($choosingCommander) $discoverMode='catalog';
$page = max(1,min(10000,(int)($_GET['page']??1)));
$terms = array_slice(deckTerms($oracle),0,12);
$highlightTerms=array_merge($terms,deckTerms($type),$q!==''?[$q]:[]);
$filterHidden = function() use($q,$oracle,$type,$match,$ownedOnly,$colorsOnly,$page): void {
    foreach (['q'=>$q,'oracle'=>$oracle,'type'=>$type,'match'=>$match,'owned'=>$ownedOnly?'1':'','colors'=>$colorsOnly?'1':'','page'=>$page] as $k=>$v) echo '<input type="hidden" name="'.h($k).'" value="'.h($v).'">';
};
$tokenFields = function(string $action, ?string $card = null) use($csrf,$id,$view,$selectionStage): void {
    echo '<input type="hidden" name="view" value="'.h($view).'"><input type="hidden" name="selection_stage" value="'.h($selectionStage).'">';
    echo '<input type="hidden" name="csrf" value="'.h($csrf).'"><input type="hidden" name="deck" value="'.$id.'"><input type="hidden" name="action" value="'.h($action).'">';
    if ($card) echo '<input type="hidden" name="card" value="'.h($card).'">';
};
$items = $deck ? deckQuery(deckOwnedSql()."SELECT c.*,i.stage,i.quantity,i.role,i.notes,COALESCE(o.owned,0) owned,COALESCE(bc.quantity,0) owned_printing,
    COALESCE((SELECT SUM(x.quantity)::int FROM (
        SELECT SUM(oi.quantity)::int quantity FROM builder_items oi JOIN cards oc ON oc.id=oi.card_id WHERE oi.stage='deck' AND oi.deck_id<>i.deck_id AND COALESCE(oc.oracle_id,oc.id)=COALESCE(c.oracle_id,c.id)
        UNION ALL SELECT COUNT(*)::int quantity FROM builder_decks od JOIN cards oc ON oc.id=od.commander_id WHERE od.id<>i.deck_id AND COALESCE(oc.oracle_id,oc.id)=COALESCE(c.oracle_id,c.id)
    ) x),0) other_used,
    COALESCE((SELECT COUNT(DISTINCT x.deck_id)::int FROM (
        SELECT oi.deck_id FROM builder_items oi JOIN cards oc ON oc.id=oi.card_id WHERE oi.stage='deck' AND oi.deck_id<>i.deck_id AND COALESCE(oc.oracle_id,oc.id)=COALESCE(c.oracle_id,c.id)
        UNION SELECT od.id FROM builder_decks od JOIN cards oc ON oc.id=od.commander_id WHERE od.id<>i.deck_id AND COALESCE(oc.oracle_id,oc.id)=COALESCE(c.oracle_id,c.id)
    ) x),0) other_decks
    FROM builder_items i JOIN cards c ON c.id=i.card_id LEFT JOIN owned o ON o.logical_id=COALESCE(c.oracle_id,c.id) LEFT JOIN builder_collection bc ON bc.scryfall_id=c.id WHERE i.deck_id=? ORDER BY c.name",[$id])->fetchAll() : [];
$synergy=[]; $synergyCount=0; $synergyPages=1;
if($deck && $commander && $view==='discover'){
    $synergySql=deckOwnedSql().", recommendations AS (
        SELECT DISTINCT ON(COALESCE(source.oracle_id,source.id)) s.metric,s.score,s.source_url,s.synced_at,
        COALESCE(source.oracle_id,source.id) logical_id
        FROM deck_synergy s JOIN cards source ON source.id=s.card_id JOIN cards leader ON leader.id=s.commander_id
        WHERE COALESCE(leader.oracle_id,leader.id)=?::uuid
        ORDER BY COALESCE(source.oracle_id,source.id),s.synced_at DESC,s.score DESC
    ), matched AS (SELECT r.*,COALESCE(o.owned,0) owned FROM recommendations r LEFT JOIN owned o ON o.logical_id=r.logical_id
        WHERE EXISTS (SELECT 1 FROM cards source WHERE COALESCE(source.oracle_id,source.id)=r.logical_id AND COALESCE(source.raw->>'layout','') <> 'art_series' AND COALESCE(source.type_line,'') NOT ILIKE '%Art Card%') ".($includeOutside?'':'AND COALESCE(o.owned,0)>0').") ";
    $leader=$commander['oracle_id']?:$commander['id'];
    $synergyCount=(int)deckQuery($synergySql.'SELECT COUNT(*) FROM matched',[$leader])->fetchColumn();
    $synergyPages=max(1,(int)ceil($synergyCount/18)); $synergyPage=min($synergyPage,$synergyPages);
    $synergy=deckQuery($synergySql."SELECT r.*,c.*,COALESCE(bc.quantity,0) owned_printing FROM matched r
        JOIN LATERAL (SELECT p.* FROM cards p LEFT JOIN builder_collection stock ON stock.scryfall_id=p.id
        WHERE COALESCE(p.oracle_id,p.id)=r.logical_id AND COALESCE(p.raw->>'layout','') <> 'art_series' AND COALESCE(p.type_line,'') NOT ILIKE '%Art Card%' ORDER BY (COALESCE(stock.quantity,0)>0) DESC,COALESCE(p.raw->'games' @> '[\"paper\"]'::jsonb,false) DESC,(p.lang='en') DESC,p.released_at DESC NULLS LAST,p.id LIMIT 1) c ON true
        LEFT JOIN builder_collection bc ON bc.scryfall_id=c.id
        ORDER BY r.score DESC,c.name,c.id LIMIT 18 OFFSET ".(($synergyPage-1)*18),[$leader])->fetchAll();
}
$selectedByLogical = [];
foreach ($items as $selected) $selectedByLogical[(string)($selected['oracle_id'] ?: $selected['id'])] = $selected;
$pendingUpgrade=null; $pendingUpgrades=[]; $upgradeCuts=[];
if($deck) {
    $pendingUpgrades=deckQuery("SELECT u.*,outc.name remove_name,inc.name add_name FROM deck_upgrades u JOIN cards outc ON outc.id=u.remove_card_id JOIN cards inc ON inc.id=u.add_card_id WHERE u.deck_id=? AND u.status='planned' ORDER BY u.created_at DESC",[$id])->fetchAll();
    $pendingUpgrade=$pendingUpgrades[0]??null;
    if($commander) {
        $upgradeCuts=deckQuery("SELECT c.*,i.quantity,s.score cut_score FROM builder_items i JOIN cards c ON c.id=i.card_id LEFT JOIN deck_synergy s ON s.card_id=c.id AND s.commander_id=? WHERE i.deck_id=? AND i.stage='deck' AND c.id<>? ORDER BY (s.score IS NULL) ASC,s.score ASC,c.name",[$commander['id'],$id,$commander['id']])->fetchAll();
    }
}
$finalCount = $commander ? 1 : 0; $landCount=0; $roles=[]; $shopping=[]; $warnings=[]; $pipCounts=array_fill_keys(['W','U','B','R','G'],0); $curveCounts=[]; $curveCards=array_fill(0,11,[]);
foreach ($items as $item) {
    if ($item['stage']!=='deck') continue;
    $quantity=(int)$item['quantity']; $finalCount+=$quantity;
    if (str_contains($item['type_line'],'Land')) $landCount+=$quantity;
    if ($item['role']!=='') $roles[$item['role']]=($roles[$item['role']]??0)+$quantity;
    if (array_diff(json_decode($item['color_identity'],true)?:[],$identity) && $commander) $warnings[]=$item['name'].': fora da identidade de cor do comandante.';
    if ($quantity>1 && !str_contains($item['type_line'],'Basic') && !preg_match('/deck can have (any number|up to)/i',deckText($item))) $warnings[]=$item['name'].': confira a quantidade permitida para Commander.';
    $missing=max(0,$quantity-(int)$item['owned']); if ($missing) $shopping[]=['name'=>$item['name'],'quantity'=>$missing];
    foreach ($pipCounts as $color=>$_) $pipCounts[$color]+=substr_count((string)$item['mana_cost'],$color)*$quantity;
    if (!str_contains($item['type_line'],'Land')) { $cmc=min(10,max(0,(int)floor((float)($item['cmc']??0)))); $curveCounts[$cmc]=($curveCounts[$cmc]??0)+$quantity; $curveCards[$cmc][]=$item; }
}
if ($commander) {
    $commanderOwned=(int)deckQuery(deckOwnedSql().'SELECT COALESCE((SELECT owned FROM owned WHERE logical_id=?::uuid),0)',[$commander['oracle_id']?:$commander['id']])->fetchColumn();
    if (!$commanderOwned) $shopping[]=['name'=>$commander['name'],'quantity'=>1];
    foreach ($pipCounts as $color=>$_) $pipCounts[$color]+=substr_count((string)$commander['mana_cost'],$color);
    $cmc=min(10,max(0,(int)floor((float)($commander['cmc']??0)))); $curveCounts[$cmc]=($curveCounts[$cmc]??0)+1; $curveCards[$cmc][]=$commander;
}
$isComplete = $finalCount === 100;
if ($deck && (($deck['status']==='ready') !== $isComplete)) { deckQuery('UPDATE builder_decks SET status=? WHERE id=?',[$isComplete?'ready':'planning',$id]); $deck['status']=$isComplete?'ready':'planning'; }
$manaTotal=array_sum($pipCounts); $maxCurve=$curveCounts?max($curveCounts):0; ksort($curveCounts);
$recommendedLandTotal=$isComplete?36:null; $landRecommendation=[]; $colorNames=['W'=>'Brancos','U'=>'Azuis','B'=>'Pretos','R'=>'Vermelhos','G'=>'Verdes'];
if($recommendedLandTotal!==null){
    $rankedColors=array_keys($pipCounts); usort($rankedColors,fn($a,$b)=>$pipCounts[$b]<=>$pipCounts[$a]);
    $allocated=0;
    foreach($rankedColors as $color){$amount=$manaTotal?(int)floor($recommendedLandTotal*$pipCounts[$color]/$manaTotal):0; $landRecommendation[$color]=$amount; $allocated+=$amount;}
    for($i=0;$allocated<$recommendedLandTotal;$i++,$allocated++) $landRecommendation[$rankedColors[$i%count($rankedColors)]]++;
}
if ($deck && isset($_GET['export'])) {
    header('Content-Type: text/plain; charset=utf-8'); header('Content-Disposition: attachment; filename="'.($_GET['export']==='shopping'?'compras':'deck').'-'.$id.'.txt"');
    if ($_GET['export']==='shopping') foreach ($shopping as $row) echo $row['quantity'].' '.$row['name']."\n";
    else { if ($commander) echo '1 '.$commander['name']."\n"; foreach($items as $row) if($row['stage']==='deck') echo $row['quantity'].' '.$row['name']."\n"; }
    exit;
}
$results=[]; $hasMore=false;
if ($deck && $view==='discover') {
    $where=[];$params=[];
    // Literal substring search: escaping prevents % and _ acting as wildcards.
    $like=fn($s)=>'%'.str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$s).'%';
    if($q!==''){$where[]='c.name ILIKE ?';$params[]=$like($q);}
    $textExpr="COALESCE(c.oracle_text,'') || ' ' || COALESCE((SELECT string_agg(f->>'oracle_text',' ') FROM jsonb_array_elements(c.card_faces) f),'')";
    $typeConditions=[];
    foreach (array_slice(deckTerms($type),0,12) as $term) {
        $typeConditions[]="(COALESCE(c.type_line,'') || ' ' || {$textExpr}) ILIKE ?";
        $params[]=$like($term);
    }
    if ($typeConditions) $where[]='('.implode(' OR ',$typeConditions).')';
    $conditions=[];foreach($terms as $term){$conditions[]="({$textExpr}) ILIKE ?";$params[]=$like($term);}
    if($conditions)$where[]='('.implode($match==='any'?' OR ':' AND ',$conditions).')';
    if($ownedOnly)$where[]='COALESCE(o.owned,0)>0';
    if($colorsOnly && $commander){$where[]='c.color_identity <@ ?::jsonb';$params[]=json_encode($identity);}
    if($choosingCommander && $commanderColors){$where[]='c.color_identity <@ ?::jsonb';$params[]=json_encode($commanderColors);}
    if($choosingCommander){
        $where[]=deckCommanderSql();
        if($commanderOwnedOnly) $where[]='COALESCE(o.owned,0)>0';
    }
    $whereSql=$where?'WHERE '.implode(' AND ',$where):'';
    $offset=($page-1)*24;
    $synergySelect='NULL::text synergy_metric,NULL::numeric synergy_score'; $synergyJoin=''; $resultParams=$params;
    if($commander){
        $synergySelect='ds.metric synergy_metric,ds.score synergy_score';
        $synergyJoin=" LEFT JOIN LATERAL (SELECT s.metric,s.score FROM deck_synergy s JOIN cards leader ON leader.id=s.commander_id JOIN cards source ON source.id=s.card_id WHERE COALESCE(leader.oracle_id,leader.id)=?::uuid AND COALESCE(source.oracle_id,source.id)=COALESCE(c.oracle_id,c.id) ORDER BY s.synced_at DESC,s.score DESC LIMIT 1) ds ON true";
        $resultParams[]=$leader;
    }
     $commanderOrder = $choosingCommander
         ? "(COALESCE(bc.quantity,0)>0) DESC,(COALESCE(o.owned,0)>0) DESC, NULLIF(c.raw->>'edhrec_rank','')::int ASC NULLS LAST, c.name, c.id"
         : "(COALESCE(bc.quantity,0)>0) DESC,COALESCE(o.owned,0) DESC,(c.lang='en') DESC,c.released_at DESC NULLS LAST,c.id";
     $resultOrder = $choosingCommander
         ? "r.edhrec_rank ASC NULLS LAST,r.owned DESC,r.name,r.id"
         : "r.owned_printing DESC,r.owned DESC,r.released_at DESC NULLS LAST,r.name,r.id";
     $results=deckQuery(deckOwnedSql()."SELECT c.*,r.owned,r.owned_printing,r.edhrec_rank,{$synergySelect} FROM (SELECT DISTINCT ON(COALESCE(c.oracle_id,c.id)) c.id,c.name,c.released_at,COALESCE(o.owned,0) owned,COALESCE(bc.quantity,0) owned_printing,NULLIF(c.raw->>'edhrec_rank','')::int edhrec_rank FROM cards c LEFT JOIN owned o ON o.logical_id=COALESCE(c.oracle_id,c.id) LEFT JOIN builder_collection bc ON bc.scryfall_id=c.id {$whereSql} ORDER BY COALESCE(c.oracle_id,c.id),{$commanderOrder}) r JOIN cards c ON c.id=r.id{$synergyJoin} ORDER BY {$resultOrder} LIMIT 25 OFFSET {$offset}",$resultParams)->fetchAll();
    $hasMore=count($results)>24; $results=array_slice($results,0,24);
}
pageHeader('Meus decks');
?>
<section class="hero"><div><h1><?= h($deck?$deck['name']:'Criar ou planejar decks') ?></h1><p><?= $deck?'Escolha a impressão certa, organize a lista e registre a intenção de cada carta.':'Comece do zero ou importe uma lista pronta. O Deckarium cruza cada carta com as impressões da sua coleção.' ?></p></div><?php if($deck): ?><a class="text-link" href="/decks.php">Voltar aos decks</a><?php endif; ?></section>
<?php if($message): ?><p class="notice ok" role="status"><?= h($message) ?></p><?php endif; ?>
<?php if($error): ?><p class="notice error" role="alert"><?= h($error) ?></p><?php endif; ?>
<?php if(!$deck): ?>
<section class="deck-start-grid"><form method="post" class="panel builder-form deck-start-create"><?php $tokenFields('create'); ?><h2>Planejar do zero</h2><p>Monte aos poucos, filtre pela identidade do comandante e acompanhe o que já existe na coleção.</p><label>Nome do deck<input name="name" required maxlength="160" placeholder="Ex.: Dina — ganho e dreno"></label><button class="primary-link">Criar planejamento</button></form>
<form method="post" class="panel builder-form deck-import-form"><?php $tokenFields('import_deck'); ?><h2>Importar uma lista</h2><p>Cole uma exportação do Moxfield ou uma lista no formato “1 Nome da carta”. Cabeçalhos como Commander e Deck são reconhecidos.</p><label>Nome do deck<input name="name" required maxlength="160" placeholder="Ex.: Hakbal — lista atual"></label><label>Lista de cartas<textarea name="decklist" rows="9" required maxlength="200000" placeholder="Commander&#10;1 Hakbal of the Surging Soul&#10;&#10;Deck&#10;1 Sol Ring&#10;1 Rejuvenating Springs"></textarea></label><button class="primary-link">Importar como finalizado</button></form></section>
<section class="collection-strip"><div><h2>Sua coleção</h2><p><?= number_format((int)$collection['total'],0,',','.') ?> cartas em <?= (int)$collection['printings'] ?> impressões catalogadas.</p></div><a class="secondary-link" href="/collection.php">Gerenciar coleção</a></section>
<section><div class="section-heading"><div><h2>Meus decks</h2><p class="muted">Escolha um deck para continuar.</p></div></div>
<?php if(!$decks): ?><p class="empty-state">Crie ou importe seu primeiro deck acima.</p><?php endif; ?>
<div class="deck-library-rows"><?php foreach($decks as $d): ?>
<article class="deck-library-row" <?php if($d['commander_card_id']): ?>style="--deck-art:url('/image.php?id=<?= h($d['commander_card_id']) ?>')"<?php endif; ?>><a href="?deck=<?= $d['id'] ?>"><span class="deck-library-copy"><strong><?= h($d['name']) ?></strong><span><?= h($d['commander']?:'Comandante a escolher') ?></span></span></a>
<span class="deck-library-count"><?= (int)$d['card_count'] ?> cartas</span><span class="deck-state <?= $d['status']==='ready'?'is-ready':'' ?>"><?= $d['status']==='ready'?'Finalizado':'Em planejamento' ?></span>
<details class="deck-delete"><summary>Excluir</summary><form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="deck" value="<?= $d['id'] ?>"><input type="hidden" name="action" value="delete_deck"><p>Excluir “<?= h($d['name']) ?>” e seus registros de upgrade? Sua coleção permanece salva.</p><button class="builder-remove">Confirmar exclusão</button></form></details></article>
<?php endforeach; ?></div></section>
<?php else: ?>
<nav class="tabs deck-module-nav" aria-label="Módulos de decks"><a href="/decks.php">Biblioteca</a><a href="?deck=<?= $id ?>&view=discover" <?= $view==='discover'?'aria-current="page"':'' ?>>Comandante e descobertas</a><a href="?deck=<?= $id ?>&view=selection" <?= $view==='selection'?'aria-current="page"':'' ?>>Minha seleção · <?= $finalCount ?>/100</a></nav>
<div class="deck-workflow-bar <?= $isComplete?'is-complete':'' ?>"><div><strong><?= $isComplete?'Deck finalizado automaticamente':'Planejamento em andamento' ?></strong><span><?= $isComplete?'100 cartas aprovadas na seleção.':'A seleção é finalizada automaticamente quando chegar a 100 cartas no deck.' ?></span></div><span class="deck-progress"><?= $finalCount ?>/100 cartas</span></div>
<?php if($view==='discover'): ?>
<section id="intent" class="builder-intro">
<div class="panel"><h2>Comandante</h2><?php if($commander): ?><div class="builder-commander"><?php if($src=cardImageUrl($commander)): ?><img src="<?= h($src) ?>" alt="<?= h($commander['name']) ?>" width="146" height="204"><?php endif; ?><div><h3><?= h($commander['name']) ?></h3><p>Identidade: <?= $identity?h(implode(' · ',$identity)):'incolor' ?></p><p><?= nl2br(h(deckText($commander))) ?></p></div></div><?php else: ?><p>Escolha uma criatura lendária para usar a identidade de cor como filtro.</p><?php endif; ?><a href="?deck=<?= $id ?>&choose=1&oracle=#explore">Escolher comandante</a><p class="muted">Esta versão trabalha com um comandante. Combinações de parceiros e Backgrounds ainda precisam de suporte específico.</p></div>
<form method="post" class="panel builder-form"><?php $tokenFields('strategy'); ?><h2>Minha intenção</h2><label>Estratégia e mecânicas<textarea name="strategy" rows="4" placeholder="Plano principal, temas secundários e o que quero evitar"><?= h($deck['strategy']) ?></textarea></label><label>Termos Oracle para explorar<input name="terms" value="<?= h($deck['terms']) ?>" placeholder="sacrifice; land; graveyard"></label><small>Separe palavras ou frases por ponto e vírgula. Estes termos são filtros escolhidos por você, não uma avaliação automática de sinergia.</small><button class="primary-link">Salvar intenção</button></form>
</section>
<?php require __DIR__.'/deck_synergy_view.php'; ?>
<?php if($choosingCommander): ?><div class="commander-picker-intro"><strong>Escolha sua comandante</strong><span>Mostrando apenas cartas elegíveis. Pesquise pelo nome ou percorra a lista.</span></div><?php endif; ?>
<?php if($choosingCommander): ?><fieldset class="commander-color-filter"><legend>Filtros para comandantes</legend><label class="commander-owned-toggle"><input type="checkbox" <?= $commanderOwnedOnly?'checked':'' ?>> Somente comandantes que possuo</label><?php foreach(['W'=>'Branco','U'=>'Azul','B'=>'Preto','R'=>'Vermelho','G'=>'Verde','C'=>'Incolor'] as $color=>$label): ?><label><input type="checkbox" name="commander_colors[]" value="<?= $color ?>" <?= in_array($color,$commanderColors,true)?'checked':'' ?>><?= $label ?></label><?php endforeach; ?><small>Marcado por padrão para priorizar a sua coleção. Desmarque para ver comandantes mais utilizados, mesmo que você ainda não os possua.</small></fieldset><?php endif; ?>
<?php if(!$showSynergy || $choosingCommander): ?>
<section id="explore-catalog" class="section-block <?= $showSynergy?'':'catalog-mode' ?>"><div class="section-heading"><div><h2><?= $showSynergy?'Buscar no catálogo':'Explorar catálogo' ?></h2><p>Os resultados usam o mesmo espaço e a mesma apresentação das recomendações do comandante. Busca literal no Oracle em inglês, incluindo as duas faces.</p></div></div><p class="muted">Em Tipos e temas, separe os termos por ponto e vírgula. Basta um deles aparecer no tipo ou no Oracle: pirate; assassin; vehicle; treasure inclui também cartas que criam Tesouros. Esse grupo é combinado com os demais filtros.</p>
<form class="builder-search builder-form" method="get"><input type="hidden" name="deck" value="<?= $id ?>"><?php if($choosingCommander): ?><input type="hidden" name="choose" value="1"><?php endif; ?><label>Nome<input name="q" value="<?= h($q) ?>" placeholder="Nome da carta"></label><label>Texto Oracle<input name="oracle" value="<?= h($oracle) ?>" placeholder="draw a card; sacrifice"></label><label>Tipos e temas<input name="type" value="<?= h($type) ?>" placeholder="pirate; assassin; vehicle; treasure"></label><label>Combinação do Oracle<select name="match"><option value="all" <?= $match==='all'?'selected':'' ?>>Todos os termos</option><option value="any" <?= $match==='any'?'selected':'' ?>>Qualquer termo</option></select></label><label class="builder-check"><input type="checkbox" name="owned" value="1" <?= $ownedOnly?'checked':'' ?>>Só minha coleção</label><label class="builder-check"><input type="checkbox" name="colors" value="1" <?= $colorsOnly?'checked':'' ?> <?= !$commander?'disabled':'' ?>>Identidade do comandante</label><button class="primary-link">Pesquisar cartas</button><a href="?deck=<?= $id ?>&oracle=#explore">Limpar filtros</a></form>
<?php if(!$results): ?><p class="empty-state">Nenhuma carta corresponde aos filtros. Tente “qualquer termo” ou remova um filtro.</p><?php endif; ?>
<div class="builder-results"><?php foreach($results as $card): ?><article class="builder-result"><?php if($src=cardImageUrl($card)): ?><a href="/card.php?id=<?= h($card['id']) ?>"><img src="<?= h($src) ?>" alt="<?= h($card['name']) ?>" width="146" height="204" loading="lazy"></a><?php endif; ?><div><h3><?= deckHighlight($card['name'],$highlightTerms) ?></h3><p class="builder-stock"><?= (int)$card['owned']>0?'Você possui '.(int)$card['owned'].' cópia(s)':'Falta na coleção' ?></p><p class="muted"><?= deckHighlight($card['type_line'],$highlightTerms) ?> · <?= manaSymbols($card['mana_cost']) ?></p><div class="builder-oracle"><p><?= nl2br(deckHighlight(deckText($card),$highlightTerms)) ?></p><?php foreach($terms as $term): if(stripos(deckText($card),$term)!==false): ?><small>Contém “<?= h($term) ?>” no Oracle.</small><?php endif; endforeach; ?></div><form method="post"><?php $tokenFields($choosingCommander?'commander':'add',$card['id']); $filterHidden(); ?><button class="secondary-link"><?= $choosingCommander?'Usar como comandante':'Adicionar às candidatas' ?></button></form></div></article><?php endforeach; ?></div>
 <nav class="pager" aria-label="Resultados de cartas"><?php $base=$_GET;$base['deck']=$id; if($page>1): $base['page']=$page-1; ?><a href="?<?= h(http_build_query($base)) ?>#explore">Anterior</a><?php endif; ?><span>Página <?= $page ?></span><?php if($hasMore): $base['page']=$page+1; ?><a href="?<?= h(http_build_query($base)) ?>#explore">Próxima</a><?php endif; ?></nav>
 </section>
<?php endif; ?>
<?php else: require __DIR__.'/deck_selection_view.php'; ?>
<section id="balance" class="section-block"><h2>Análise e próximos passos</h2><div class="builder-intro"><div class="panel"><h3>Composição escolhida</h3><p><strong><?= $finalCount ?></strong> cartas contando o comandante · <strong><?= $landCount ?></strong> terrenos</p><p>Referência para o formato: 100 cartas. <?= max(0,100-$finalCount) ?> espaços restantes<?= $finalCount>100?' · '.($finalCount-100).' acima da referência':'' ?>.</p><?php foreach($roles as $role=>$count): ?><p><?= h($role) ?>: <?= $count ?></p><?php endforeach; ?><p class="muted">As funções só contam as cartas aprovadas, com a classificação que você informou.</p><h3>Demanda de mana colorida</h3><p><?= h(implode(' · ',array_map(fn($c)=>$c.': '.$pipCounts[$c],array_keys($pipCounts)))) ?></p><p class="muted">Contagem de símbolos nos custos. Híbridos contam em ambas as cores. Não é uma recomendação de terrenos: custos alternativos, faces, aceleração e turnos de jogo exigem avaliação adicional.</p><?php foreach($warnings as $warning): ?><p class="notice warning"><?= h($warning) ?></p><?php endforeach; ?><p class="muted">Alertas básicos, não uma validação completa de legalidade ou força do deck.</p><a href="?deck=<?= $id ?>&export=deck">Exportar deck em texto</a></div>
<div class="panel"><h3>Disponibilidade das cartas</h3><p>Os indicadores aparecem sobre cada carta aprovada e consideram todas as impressões da mesma carta.</p><div class="inventory-legend"><span><i class="inventory-dot is-available"></i> Disponível na coleção</span><span><i class="inventory-dot is-limited"></i> Quantidade limitada</span><span><i class="inventory-dot is-reserved"></i> Usada em outros decks</span></div><p class="muted">Uma cópia física só pode ser comprometida uma vez. Se você possui duas cópias, a carta pode aparecer em até dois decks. Cartas candidatas não reservam cópias.</p></div></div></section>
<?php endif; endif; if ($deck): ?>
<section id="mana-analysis" class="section-block mana-analysis"><div class="section-heading"><div><h2>Leitura do deck</h2><p class="muted">Uma visão rápida da curva e dos símbolos de mana das cartas aprovadas.</p></div><span class="analysis-total"><?= $finalCount ?>/100 cartas</span></div><div class="analysis-grid"><div class="panel"><h3>Curva de mana</h3><div class="mana-curve" aria-label="Curva de mana"><?php for($cost=0;$cost<=10;$cost++): $count=(int)($curveCounts[$cost]??0); ?><button type="button" class="mana-column" data-mana-cost="<?= $cost ?>" aria-controls="mana-list-<?= $cost ?>" aria-expanded="false"><span><?= $count ?></span><i class="mana-bar" style="height:<?= $maxCurve?max(4,round($count/$maxCurve*110)):4 ?>px"></i><small><?= $cost===10?'10+':$cost ?></small></button><?php endfor; ?></div><?php for($cost=0;$cost<=10;$cost++): ?><div class="mana-card-list" id="mana-list-<?= $cost ?>" data-mana-list="<?= $cost ?>" hidden><h4>Cartas de custo <?= $cost===10?'10 ou mais':$cost ?></h4><?php if(!$curveCards[$cost]): ?><p class="muted">Nenhuma carta final nesse valor.</p><?php else: foreach($curveCards[$cost] as $curveCard): ?><a class="mana-card-item" href="/card.php?id=<?= h($curveCard['id']) ?>"><?php if($src=cardImageUrl($curveCard,'front','small')): ?><img src="<?= h($src) ?>" alt="" loading="lazy"><?php endif; ?><span><strong><?= (int)($curveCard['quantity']??1) ?>× <?= h($curveCard['name']) ?></strong><small><?= h($curveCard['type_line']??'') ?></small></span></a><?php endforeach; endif; ?></div><?php endfor; ?><p class="muted">Cartas não-terreno aprovadas no deck final. Clique em uma barra para ver as cartas daquele valor. Upgrades planejados não entram até serem confirmados.</p></div><div class="panel"><h3>Porcentagem de mana escolhida</h3><div class="mana-distribution"><?php foreach($pipCounts as $color=>$count): $percent=$manaTotal?round($count/$manaTotal*100):0; ?><div><div class="mana-label"><strong><?= $color ?></strong><span><?= $percent ?>% · <?= $count ?> símbolos</span></div><div class="mana-track"><i class="mana-fill mana-<?= $color ?>" style="width:<?= $percent ?>%"></i></div></div><?php endforeach; ?></div><p class="muted">Baseado nos custos das cartas aprovadas e da comandante. Terrenos básicos não entram nesta porcentagem.</p></div></div></section>
<?php if($recommendedLandTotal!==null): ?><section class="section-block land-recommendation"><div class="panel"><h3>Sugestão inicial de terrenos</h3><p>Para este deck finalizado, uma base de aproximadamente <strong><?= $recommendedLandTotal ?> terrenos</strong> é um ponto de partida. A divisão abaixo usa a proporção de símbolos coloridos das cartas aprovadas; revise conforme sua curva, ramp e terrenos não básicos.</p><div class="land-recommendation-grid"><?php foreach($landRecommendation as $color=>$amount): ?><span><strong><?= h($colorNames[$color]) ?></strong><b><?= $amount ?></b></span><?php endforeach; ?></div><p class="muted">Isto é uma recomendação estatística, não uma alteração automática do deck.</p></div></section><?php endif; ?>
<?php $selectionMap=[]; foreach($items as $selected){$selectionMap[(string)$selected['id']]=['stage'=>$selected['stage'],'label'=>deckStageLabel($selected['stage']),'image'=>cardImageUrl($selected,'front','small')];} ?>
<script>window.builderSelection=<?= json_encode($selectionMap,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>;</script>
<script>window.builderHasCommander=<?= $commander?'true':'false' ?>;</script>
<script>window.builderChoosingCommander=<?= $choosingCommander?'true':'false' ?>;</script>
<?php if($view==='discover' && $commander): $exploreSynergy=[]; foreach($results as $exploreCard) if($exploreCard['synergy_score']!==null) $exploreSynergy[(string)$exploreCard['id']]=['metric'=>$exploreCard['synergy_metric'],'score'=>(float)$exploreCard['synergy_score']]; ?>
<script>window.builderExploreSynergy=<?= json_encode($exploreSynergy,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>;</script>
<?php endif; ?>
<?php if($view==='discover'): $exploreSelection=[]; foreach($results as $exploreCard){$logical=(string)($exploreCard['oracle_id']?:$exploreCard['id']); if(isset($selectedByLogical[$logical])) $exploreSelection[(string)$exploreCard['id']]=['stage'=>$selectedByLogical[$logical]['stage'],'label'=>deckStageLabel($selectedByLogical[$logical]['stage'])];} ?>
<script>window.builderExploreSelection=<?= json_encode($exploreSelection,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>;</script>
<?php endif; ?>
<?php endif; pageFooter(); ?>
