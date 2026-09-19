<?php
declare(strict_types=1);
require __DIR__ . '/functions.php';
require __DIR__ . '/partials.php';
require __DIR__ . '/deck_library.php';
$authUser=authRequireLogin();
$userId=(int)$authUser['id'];
$_SESSION['collection_csrf'] ??= bin2hex(random_bytes(24));
$csrf=$_SESSION['collection_csrf'];
$message=$_SESSION['collection_message']??''; unset($_SESSION['collection_message']);
$error='';
// Linhas do último CSV que não entraram (mostradas na página e disponíveis para baixar e corrigir).
$failedImport=$_SESSION['collection_failed']??null;
if(isset($_GET['failed']) && $_GET['failed']==='csv' && is_array($failedImport)){
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="linhas-com-erro.csv"');
    $out=fopen('php://output','w');
    fputcsv($out,array_merge(['Linha do arquivo','Motivo'],$failedImport['headers']),',','"','');
    foreach($failedImport['lines'] as $failedLine) fputcsv($out,array_merge([$failedLine['line'],$failedLine['reason']],array_map('strval',(array)$failedLine['row'])),',','"','');
    fclose($out); exit;
}
if(isset($_GET['failed']) && $_GET['failed']==='clear'){ unset($_SESSION['collection_failed']); header('Location: /collection.php',true,303); exit; }
deckSchema();
if(isset($_GET['template']) && $_GET['template']==='csv'){
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="modelo-colecao-deckarium.csv"');
    echo "Name,Scryfall ID,Quantity,Foil\n"; exit;
}
if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        if(!hash_equals($csrf,(string)($_POST['csrf']??''))) throw new RuntimeException('Sessão expirada. Recarregue a página e tente novamente.');
        $action=(string)($_POST['action']??'');
        if($action==='import'){
            if(($_FILES['collection']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK) throw new RuntimeException('Selecione um CSV válido dentro do limite de upload do servidor.');
            $mode=in_array(($_POST['mode']??'replace'),['replace','add','subtract'],true)?(string)$_POST['mode']:'replace';
            unset($_SESSION['collection_failed']);
            $result=deckImport($_FILES['collection']['tmp_name'],$mode);
            $amount=number_format($result['quantity'],0,',','.').($result['quantity']===1?' cópia em ':' cópias em ').number_format($result['printings'],0,',','.').($result['printings']===1?' versão de impressão e acabamento':' versões de impressão e acabamento');
            $amountVerb=$result['quantity']===1?['removida','somada','importada']:['removidas','somadas','importadas'];
            $message=match($mode){'subtract'=>$amount.' '.$amountVerb[0].' da coleção.','add'=>$amount.' '.$amountVerb[1].' ao acervo atual.',default=>$amount.' '.$amountVerb[2].'. A coleção anterior foi substituída.'};
            if($result['failed']){
                $_SESSION['collection_failed']=['lines'=>$result['failed'],'headers'=>$result['headers'],'mode'=>$mode,'summary'=>count($result['failed']).(count($result['failed'])===1?' linha não foi aplicada':' linhas não foram aplicadas').'; as demais foram processadas.'];
                $message.=' Atenção: '.$_SESSION['collection_failed']['summary'];
            }
        }elseif($action==='visibility'){
            $public=($_POST['public']??'')==='1';
            deckQuery('UPDATE users SET collection_public=? WHERE id=?',[$public?'true':'false',$userId]);
            $message=$public?'Coleção pública: qualquer pessoa com o link pode ver suas cartas.':'Coleção privada: só você pode ver.';
        }elseif($action==='delete'){
            $cardId=(string)($_POST['card']??'');
            if(!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/i',$cardId)) throw new RuntimeException('Impressão inválida.');
            $foil=($_POST['foil']??'0')==='1';
            $deleted=deckQuery('DELETE FROM builder_collection WHERE user_id=? AND scryfall_id=? AND foil=? RETURNING name,quantity,foil',[$userId,$cardId,$foil?'true':'false'])->fetch();
            if(!$deleted) throw new RuntimeException('Essa impressão já não está na coleção.');
            $message=(int)$deleted['quantity'].' cópia(s) '.(deckIsFoil($deleted['foil'])?'foil ':'').'de '.$deleted['name'].' removida(s) da coleção.';
        }else throw new RuntimeException('Ação inválida.');
        $_SESSION['collection_message']=$message; header('Location: /collection.php',true,303); exit;
    }catch(Throwable $e){
        $error=$e instanceof RuntimeException&&!($e instanceof PDOException)?$e->getMessage():'Não foi possível atualizar a coleção. Os dados anteriores foram preservados.';
        if($e instanceof CollectionImportException && $e->lines){ $failedImport=['lines'=>$e->lines,'headers'=>$e->headers,'mode'=>(string)($_POST['mode']??'replace'),'summary'=>$error]; $_SESSION['collection_failed']=$failedImport; }
    }
}
session_write_close();
require __DIR__ . '/card_filters.php';
$f=cardFilters();
$q = substr(trim((string)($_GET['q'] ?? '')), 0, 200);
$oracle = substr(trim((string)($_GET['oracle'] ?? '')), 0, 200);
$requestedSort = $_GET['sort'] ?? 'name';
$requestedSort = is_string($requestedSort) ? $requestedSort : 'name';
$sort = match ($requestedSort) {
    'color' => 'color',
    'price' => 'price',
    default => 'name',
};
$usdRate=(float)(getenv('USD_BRL_RATE')?:5.5);$eurRate=(float)(getenv('EUR_BRL_RATE')?:6.0);
$normalPrice="COALESCE(NULLIF(c.prices->>'usd','')::numeric*{$usdRate},NULLIF(c.prices->>'eur','')::numeric*{$eurRate})";
$foilPrice="COALESCE(NULLIF(c.prices->>'usd_foil','')::numeric*{$usdRate},NULLIF(c.prices->>'eur_foil','')::numeric*{$eurRate})";
$finishPrice="CASE WHEN o.foil THEN {$foilPrice} ELSE {$normalPrice} END";
$sortSql = match ($sort) {
    'color' => "COALESCE(c.colors::text,'[]'),c.name,c.set_code,c.collector_number,c.id",
    'price' => "{$finishPrice} DESC NULLS LAST,c.name,c.set_code,c.collector_number,c.id",
    default => 'c.name,c.set_code,c.collector_number,c.id',
};
$page = max(1, (int)($_GET['page'] ?? 1));
[$whereSql,$params]=cardFilterSql($f);
$finish=in_array(($_GET['finish']??''),['foil','normal'],true)?(string)$_GET['finish']:'';
if($finish!==''){$whereSql.=($whereSql?' AND ':'WHERE ').'o.foil=?';$params[]=$finish==='foil'?'true':'false';}
// Uso em decks: conta todas as impressões da mesma carta (oracle), como nas reservas dos decks.
$usage=in_array(($_GET['usage']??''),['in_decks','not_in_decks','free'],true)?(string)$_GET['usage']:'';
$usageFilter=['in_decks'=>'COALESCE(u.used,0)>0','not_in_decks'=>'COALESCE(u.used,0)=0','free'=>'COALESCE(ol.owned,0)>COALESCE(u.used,0)'][$usage]??'';
if($usageFilter!==''){$whereSql.=($whereSql?' AND ':'WHERE ').$usageFilter;}
$whereSql.=($whereSql?' AND ':'WHERE ').'o.user_id=?';$params[]=$userId;
$from = ' FROM builder_collection o JOIN cards c ON c.id=o.scryfall_id LEFT JOIN '.deckUsageSql($userId).' u ON u.logical_id=COALESCE(c.oracle_id,c.id)'
    .' LEFT JOIN (SELECT COALESCE(oc.oracle_id,oc.id) logical_id,SUM(oo.quantity)::int owned FROM builder_collection oo JOIN cards oc ON oc.id=oo.scryfall_id WHERE oo.user_id='.$userId.' GROUP BY 1) ol ON ol.logical_id=COALESCE(c.oracle_id,c.id) ' . $whereSql;
// Exportação em CSV com os mesmos filtros; as 4 primeiras colunas podem ser reimportadas.
if(($_GET['export']??'')==='csv'){
    $rows=deckQuery('SELECT c.id,c.name,c.set_name,c.set_code,c.collector_number,c.lang,c.rarity,o.quantity,o.foil,COALESCE(u.used,0) used,COALESCE(ol.owned,0) owned'.$from." ORDER BY {$sortSql},o.foil",$params);
    $suffix=['in_decks'=>'-em-decks','not_in_decks'=>'-fora-de-decks','free'=>'-com-copia-livre'][$usage]??'';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="colecao'.$suffix.'-'.date('Y-m-d').'.csv"');
    $out=fopen('php://output','w');
    fwrite($out,"\xEF\xBB\xBF");
    fputcsv($out,['Name','Scryfall ID','Quantity','Foil','Edição','Código','Número','Idioma','Raridade','Cópias da carta na coleção','Cópias em decks','Decks'],',','"','');
    $batch=[];
    $flush=function() use(&$batch,$out): void {
        if(!$batch) return;
        $usageByLogical=deckUsageElsewhere(0,array_map(fn($r)=>$r['logical'],$batch));
        foreach($batch as $r){
            $names=implode(' | ',array_map(fn($d)=>$d['name'],$usageByLogical[$r['logical']]['decks']??[]));
            fputcsv($out,[$r['name'],$r['id'],$r['quantity'],deckIsFoil($r['foil'])?'foil':'normal',$r['set_name'],strtoupper((string)$r['set_code']),$r['collector_number'],$r['lang'],$r['rarity'],$r['owned'],$r['used'],$names],',','"','');
        }
        $batch=[];
    };
    $logicalOf=deckQuery('SELECT o.scryfall_id::text id,COALESCE(c.oracle_id,c.id)::text logical'.$from,$params)->fetchAll(PDO::FETCH_KEY_PAIR);
    while($r=$rows->fetch()){ $r['logical']=$logicalOf[$r['id']]??$r['id']; $batch[]=$r; if(count($batch)>=500) $flush(); }
    $flush(); fclose($out); exit;
}
$count = (int)deckQuery('SELECT COUNT(*)'.$from, $params)->fetchColumn();
$pages = max(1, (int)ceil($count / 36)); $page = min($page, $pages); $offset = ($page-1)*36;
$cards = deckQuery('SELECT c.*,o.quantity,o.foil,COALESCE(u.used,0) used_in_decks,COALESCE(ol.owned,0) owned_logical'.$from." ORDER BY {$sortSql},o.foil LIMIT 36 OFFSET {$offset}", $params)->fetchAll();
$cardUsage=deckUsageElsewhere(0,array_map(fn($row)=>(string)($row['oracle_id']?:$row['id']),$cards));
$sortParams=$_GET; unset($sortParams['page'],$sortParams['template'],$sortParams['sort']);
$summary = deckQuery("SELECT COALESCE(SUM(o.quantity),0) total,COUNT(*) finishes,
    COALESCE(SUM(o.quantity*({$finishPrice})),0) total_value,
    COALESCE(SUM(o.quantity) FILTER(WHERE o.foil),0) foils,COALESCE(SUM(o.quantity) FILTER(WHERE NOT o.foil),0) normals,
    COALESCE(SUM(o.quantity) FILTER(WHERE c.rarity='mythic'),0) mythics,COALESCE(SUM(o.quantity) FILTER(WHERE c.rarity='rare'),0) rares,
    COALESCE(SUM(o.quantity) FILTER(WHERE c.rarity='uncommon'),0) uncommons,COALESCE(SUM(o.quantity) FILTER(WHERE c.rarity='common'),0) commons,
    COALESCE(SUM(o.quantity) FILTER(WHERE c.type_line ILIKE '%Creature%'),0) creatures,
    COALESCE(SUM(o.quantity) FILTER(WHERE c.type_line ILIKE '%Sorcery%'),0) sorceries,
    COALESCE(SUM(o.quantity) FILTER(WHERE c.type_line ILIKE '%Instant%'),0) instants,
    COALESCE(SUM(o.quantity) FILTER(WHERE c.type_line ILIKE '%Land%'),0) lands,
    COALESCE(SUM(o.quantity) FILTER(WHERE c.type_line ILIKE '%Artifact%'),0) artifacts,
    COALESCE(SUM(o.quantity) FILTER(WHERE c.type_line ILIKE '%Enchantment%'),0) enchantments,
    COALESCE(SUM(o.quantity) FILTER(WHERE c.type_line ILIKE '%Planeswalker%'),0) planeswalkers,
    COALESCE(SUM(o.quantity) FILTER(WHERE ({$finishPrice}) IS NULL),0) unpriced
    FROM builder_collection o LEFT JOIN cards c ON c.id=o.scryfall_id WHERE o.user_id=?",[$userId])->fetch();
$collectionPublic=(bool)deckQuery('SELECT collection_public FROM users WHERE id=?',[$userId])->fetchColumn();
$publicCollectionUrl='/public_collection.php?u='.rawurlencode((string)$authUser['username']);
pageHeader('Minha coleção');
?>
<section class="hero"><div><h1><?= te('Minha coleção') ?></h1><p><?= number_format((int)$summary['total'],0,',','.') ?> <?= te('cartas em') ?> <?= number_format((int)$summary['finishes'],0,',','.') ?> <?= te('versões de impressão e acabamento. Gerencie as cópias físicas usadas nos seus decks.') ?></p></div><a href="/decks.php"><?= te('Criar ou planejar decks') ?></a></section>
<section class="collection-share <?= $collectionPublic?'is-public':'' ?>" aria-label="<?= te('Compartilhar coleção') ?>"><p><strong><?= $collectionPublic?te('Coleção pública'):te('Coleção privada') ?></strong> <?= $collectionPublic?te('Qualquer pessoa com o link vê suas cartas e quantidades, sem os decks em que estão.'):te('Só você vê suas cartas.') ?><?php if($collectionPublic): ?> <a href="<?= h($publicCollectionUrl) ?>"><?= te('Ver página pública') ?></a> · <button type="button" class="text-button" data-copy-share="<?= h($publicCollectionUrl) ?>"><?= te('Copiar link') ?></button><?php endif; ?></p><form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="visibility"><input type="hidden" name="public" value="<?= $collectionPublic?'0':'1' ?>"><button class="<?= $collectionPublic?'secondary-link':'primary-link' ?>"><?= $collectionPublic?te('Tornar privada'):te('Tornar pública') ?></button></form></section>
<?php if($message):?><p class="notice ok" role="status"><?=h($message)?></p><?php endif;if($error):?><p class="notice error" role="alert"><?=h($error)?></p><?php endif;?>
<?php if(is_array($failedImport) && $failedImport['lines']): $failedShown=array_slice($failedImport['lines'],0,300); ?>
<section class="import-failures" aria-labelledby="import-failures-title">
    <div class="import-failures-head">
        <div><h2 id="import-failures-title"><?= count($failedImport['lines']) ?> <?= count($failedImport['lines'])===1?t('linha do CSV não entrou'):t('linhas do CSV não entraram') ?></h2><p><?= h($failedImport['summary']) ?> A linha 1 é o cabeçalho.</p></div>
        <div class="import-failures-actions"><a class="secondary-link" href="?failed=csv"><?= te('Baixar essas linhas (CSV)') ?></a><a href="?failed=clear"><?= te('Dispensar') ?></a></div>
    </div>
    <div class="import-failures-table"><table><thead><tr><th scope="col"><?= te('Linha') ?></th><th scope="col"><?= te('Carta') ?></th><th scope="col"><?= te('Motivo') ?></th><th scope="col"><?= te('Conteúdo da linha') ?></th></tr></thead><tbody>
    <?php foreach($failedShown as $failedLine): ?><tr><td><?= (int)$failedLine['line'] ?></td><td><?= h($failedLine['name']!==''?$failedLine['name']:'—') ?></td><td><?= h($failedLine['reason']) ?></td><td><code><?= h(mb_substr(implode(',',array_map('strval',(array)$failedLine['row'])),0,160)) ?></code></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <?php if(count($failedImport['lines'])>count($failedShown)): ?><p class="muted"><?= te('Mostrando as primeiras :count. Baixe o CSV para ver todas.', ['count' => count($failedShown)]) ?></p><?php endif; ?>
</section>
<?php endif; ?>
<details class="collection-manage" <?= is_array($failedImport)?'open':'' ?>><summary><?= te('Gerenciar coleção: importar ou subtrair por CSV e filtros') ?></summary><section class="collection-import-panel"><div><h2><?= te('Importar ou subtrair cartas por CSV') ?></h2><p><?= te('Use uma exportação do ManaBox ou um arquivo com estas colunas:') ?></p><code>Name,Scryfall ID,Quantity,Foil</code><p class="muted"><?= te('Foil aceita “foil” e “normal”. Sem essa coluna, as cartas são tratadas como normais.') ?> <?= te('Ao substituir, qualquer linha com erro cancela tudo; ao somar ou subtrair, as linhas corretas são aplicadas e as com erro aparecem numa lista com o número da linha.') ?></p><a href="?template=csv"><?= te('Baixar modelo CSV') ?></a></div><form method="post" enctype="multipart/form-data" class="collection-manage-form"><input type="hidden" name="csrf" value="<?=h($csrf)?>"><input type="hidden" name="action" value="import"><label><?= te('Arquivo CSV') ?><input type="file" name="collection" accept=".csv,text/csv" required></label><fieldset><legend><?= te('Como importar') ?></legend><label><input type="radio" name="mode" value="replace" checked> <?= te('Substituir pela coleção completa') ?></label><label><input type="radio" name="mode" value="add"> <?= te('Somar estas quantidades ao acervo atual') ?></label><label><input type="radio" name="mode" value="subtract"> <?= te('Subtrair estas quantidades da coleção (vendas, trocas, cartas perdidas)') ?></label></fieldset><button class="primary-link"><?= te('Enviar CSV') ?></button></form></section><?php cardFilterForm($f,deckQuery('SELECT c.set_code,MAX(c.set_name) set_name FROM builder_collection o JOIN cards c ON c.id=o.scryfall_id GROUP BY c.set_code ORDER BY MAX(c.set_name)')->fetchAll(),'/collection.php'); ?></details>
<div class="collection-toolbar"><span><?= te('Organizar coleção') ?></span><form method="get"><?php foreach($sortParams as $key=>$value): if(in_array($key,['finish','usage','export'],true))continue; foreach(is_array($value)?$value:[$value] as $v): ?><input type="hidden" name="<?= h($key.(is_array($value)?'[]':'')) ?>" value="<?= h($v) ?>"><?php endforeach; endforeach; ?><select name="finish" aria-label="<?= te('Filtrar acabamento') ?>"><option value="" <?= $finish===''?'selected':'' ?>><?= te('Todos os acabamentos') ?></option><option value="normal" <?= $finish==='normal'?'selected':'' ?>><?= te('Somente normais') ?></option><option value="foil" <?= $finish==='foil'?'selected':'' ?>><?= te('Somente foil') ?></option></select><select name="usage" aria-label="<?= te('Filtrar por uso em decks') ?>"><option value="" <?= $usage===''?'selected':'' ?>><?= te('Em decks ou não') ?></option><option value="in_decks" <?= $usage==='in_decks'?'selected':'' ?>><?= te('Usadas em decks') ?></option><option value="not_in_decks" <?= $usage==='not_in_decks'?'selected':'' ?>><?= te('Fora de qualquer deck') ?></option><option value="free" <?= $usage==='free'?'selected':'' ?>><?= te('Com cópia livre') ?></option></select><select name="sort" aria-label="<?= te('Ordenar coleção') ?>"><option value="name" <?= $sort==='name'?'selected':'' ?>><?= te('Nome') ?></option><option value="color" <?= $sort==='color'?'selected':'' ?>><?= te('Cor') ?></option><option value="price" <?= $sort==='price'?'selected':'' ?>><?= te('Preço') ?></option></select><button class="secondary-link"><?= te('Aplicar') ?></button></form></div>
<?php $exportParams=array_filter(array_merge($f,['sort'=>$sort,'finish'=>$finish,'usage'=>$usage,'export'=>'csv']),fn($v)=>$v!==''&&$v!==[]); ?>
<div class="collection-results-bar"><p class="muted"><?= number_format($count,0,',','.') ?> <?= $count===1?t('versão encontrada'):t('versões encontradas') ?>. <a href="/collection.php"><?= te('Limpar filtros') ?></a></p><?php if($count): ?><a class="secondary-link" href="/collection.php?<?= h(http_build_query($exportParams)) ?>" download><?= te('Exportar') ?> <?= $count===(int)$summary['finishes']?te('coleção'):te('resultado') ?> (CSV)</a><?php endif; ?></div>
<?php if (!$cards): ?><p class="empty-state"><?= $summary['finishes'] ? te('Nenhuma carta corresponde à busca. Altere os termos ou limpe os filtros.') : te('Sua coleção ainda está vazia. Importe um CSV acima para começar.') ?></p><?php endif; ?>
<div class="grid collection-grid"><?php foreach ($cards as $card): $isFoil=deckIsFoil($card['foil']); ?><div class="collection-item <?= $isFoil?'is-foil':'' ?>"><?php if($isFoil): ?><span class="foil-label">Foil</span><?php endif; ?><?php cardTile($card); ?><div class="collection-item-footer"><p class="collection-quantity"><?= (int)$card['quantity'] ?> cópia(s) · <?= $isFoil?'FOIL':'NORMAL' ?> · <?= h(strtoupper($card['lang'])) ?></p><?php $itemUsage=$cardUsage[(string)($card['oracle_id']?:$card['id'])]['decks']??[]; $itemFree=max(0,(int)$card['owned_logical']-(int)$card['used_in_decks']); ?><p class="collection-usage <?= !$itemUsage?'is-free':($itemFree?'is-partial':'is-used') ?>"><?php if(!$itemUsage): ?><?= te('Fora de decks') ?><?php else: ?><?= $itemFree?$itemFree.' livre'.($itemFree===1?'':'s').' · ':'Sem cópia livre · ' ?>em <?= implode(', ',array_map(fn($d)=>'<a href="/decks.php?deck='.$d['id'].'&amp;view=selection&amp;stage=deck">'.h($d['name']).'</a>',$itemUsage)) ?><?php endif; ?></p><form method="post" onsubmit="return confirm('Remover todas as cópias desta versão da coleção?')"><input type="hidden" name="csrf" value="<?=h($csrf)?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="card" value="<?=h($card['id'])?>"><input type="hidden" name="foil" value="<?= $isFoil?'1':'0' ?>"><button class="collection-delete" aria-label="<?= te('Remover :name (:finish) da coleção', ['name' => $card['name'], 'finish' => $isFoil ? t('foil') : t('normal')]) ?>"><?= te('Remover versão') ?></button></form></div></div><?php endforeach; ?></div>
<?php numberedPager($page,$pages,array_merge($f,['sort'=>$sort,'finish'=>$finish,'usage'=>$usage])); ?>
<div class="collection-summary-spacer" aria-hidden="true"></div><aside class="collection-summary-bar" aria-label="<?= te('Resumo da coleção') ?>"><div class="collection-summary-value"><span><?= te('Valor estimado') ?></span><strong>R$ <?= number_format((float)$summary['total_value'],2,',','.') ?></strong><?php if((int)$summary['unpriced']): ?><small><?= number_format((int)$summary['unpriced'],0,',','.') ?> sem cotação</small><?php endif; ?></div><div class="collection-summary-stats"><?php foreach([t('Cartas')=>'total',t('Foil')=>'foils',t('Normais')=>'normals',t('Míticas')=>'mythics',t('Raras')=>'rares',t('Incomuns')=>'uncommons',t('Comuns')=>'commons',t('Criaturas')=>'creatures',t('Feitiços')=>'sorceries',t('Instantâneas')=>'instants',t('Terrenos')=>'lands',t('Artefatos')=>'artifacts',t('Encantamentos')=>'enchantments',t('Planeswalkers')=>'planeswalkers'] as $label=>$key): ?><span><b><?= number_format((int)$summary[$key],0,',','.') ?></b><?= h($label) ?></span><?php endforeach; ?></div></aside>
<?php pageFooter(); ?>
