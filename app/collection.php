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
$whereSql.=($whereSql?' AND ':'WHERE ').'o.user_id=?';$params[]=$userId;
$from = ' FROM builder_collection o JOIN cards c ON c.id=o.scryfall_id ' . $whereSql;
$count = (int)deckQuery('SELECT COUNT(*)'.$from, $params)->fetchColumn();
$pages = max(1, (int)ceil($count / 36)); $page = min($page, $pages); $offset = ($page-1)*36;
$cards = deckQuery('SELECT c.*,o.quantity,o.foil'.$from." ORDER BY {$sortSql},o.foil LIMIT 36 OFFSET {$offset}", $params)->fetchAll();
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
pageHeader('Minha coleção');
?>
<section class="hero"><div><h1>Minha coleção</h1><p><?= number_format((int)$summary['total'],0,',','.') ?> cartas em <?= number_format((int)$summary['finishes'],0,',','.') ?> versões de impressão e acabamento. Gerencie as cópias físicas usadas nos seus decks.</p></div><a href="/decks.php">Criar ou planejar decks</a></section>
<?php if($message):?><p class="notice ok" role="status"><?=h($message)?></p><?php endif;if($error):?><p class="notice error" role="alert"><?=h($error)?></p><?php endif;?>
<?php if(is_array($failedImport) && $failedImport['lines']): $failedShown=array_slice($failedImport['lines'],0,300); ?>
<section class="import-failures" aria-labelledby="import-failures-title">
    <div class="import-failures-head">
        <div><h2 id="import-failures-title"><?= count($failedImport['lines']) ?> <?= count($failedImport['lines'])===1?'linha do CSV não entrou':'linhas do CSV não entraram' ?></h2><p><?= h($failedImport['summary']) ?> A linha 1 é o cabeçalho.</p></div>
        <div class="import-failures-actions"><a class="secondary-link" href="?failed=csv">Baixar essas linhas (CSV)</a><a href="?failed=clear">Dispensar</a></div>
    </div>
    <div class="import-failures-table"><table><thead><tr><th scope="col">Linha</th><th scope="col">Carta</th><th scope="col">Motivo</th><th scope="col">Conteúdo da linha</th></tr></thead><tbody>
    <?php foreach($failedShown as $failedLine): ?><tr><td><?= (int)$failedLine['line'] ?></td><td><?= h($failedLine['name']!==''?$failedLine['name']:'—') ?></td><td><?= h($failedLine['reason']) ?></td><td><code><?= h(mb_substr(implode(',',array_map('strval',(array)$failedLine['row'])),0,160)) ?></code></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <?php if(count($failedImport['lines'])>count($failedShown)): ?><p class="muted">Mostrando as primeiras <?= count($failedShown) ?>. Baixe o CSV para ver todas.</p><?php endif; ?>
</section>
<?php endif; ?>
<details class="collection-manage" <?= is_array($failedImport)?'open':'' ?>><summary>Gerenciar coleção: importar ou subtrair por CSV e filtros</summary><section class="collection-import-panel"><div><h2>Importar ou subtrair cartas por CSV</h2><p>Use uma exportação do ManaBox ou um arquivo com estas colunas:</p><code>Name,Scryfall ID,Quantity,Foil</code><p class="muted">Foil aceita “foil” e “normal”. Sem essa coluna, as cartas são tratadas como normais. Ao <strong>substituir</strong>, qualquer linha com erro cancela tudo; ao <strong>somar</strong> ou <strong>subtrair</strong>, as linhas corretas são aplicadas e as com erro aparecem numa lista com o número da linha.</p><a href="?template=csv">Baixar modelo CSV</a></div><form method="post" enctype="multipart/form-data" class="collection-manage-form"><input type="hidden" name="csrf" value="<?=h($csrf)?>"><input type="hidden" name="action" value="import"><label>Arquivo CSV<input type="file" name="collection" accept=".csv,text/csv" required></label><fieldset><legend>Como importar</legend><label><input type="radio" name="mode" value="replace" checked> Substituir pela coleção completa</label><label><input type="radio" name="mode" value="add"> Somar estas quantidades ao acervo atual</label><label><input type="radio" name="mode" value="subtract"> Subtrair estas quantidades da coleção (vendas, trocas, cartas perdidas)</label></fieldset><button class="primary-link">Enviar CSV</button></form></section><?php cardFilterForm($f,deckQuery('SELECT c.set_code,MAX(c.set_name) set_name FROM builder_collection o JOIN cards c ON c.id=o.scryfall_id GROUP BY c.set_code ORDER BY MAX(c.set_name)')->fetchAll(),'/collection.php'); ?></details>
<div class="collection-toolbar"><span>Organizar coleção</span><form method="get"><?php foreach($sortParams as $key=>$value): if($key==='finish')continue; foreach(is_array($value)?$value:[$value] as $v): ?><input type="hidden" name="<?= h($key.(is_array($value)?'[]':'')) ?>" value="<?= h($v) ?>"><?php endforeach; endforeach; ?><select name="finish" aria-label="Filtrar acabamento"><option value="" <?= $finish===''?'selected':'' ?>>Todos os acabamentos</option><option value="normal" <?= $finish==='normal'?'selected':'' ?>>Somente normais</option><option value="foil" <?= $finish==='foil'?'selected':'' ?>>Somente foil</option></select><select name="sort" aria-label="Ordenar coleção"><option value="name" <?= $sort==='name'?'selected':'' ?>>Nome</option><option value="color" <?= $sort==='color'?'selected':'' ?>>Cor</option><option value="price" <?= $sort==='price'?'selected':'' ?>>Preço</option></select><button class="secondary-link">Aplicar</button></form></div>
<p class="muted"><?= number_format($count,0,',','.') ?> <?= $count===1?'versão encontrada':'versões encontradas' ?>. <a href="/collection.php">Limpar filtros</a></p>
<?php if (!$cards): ?><p class="empty-state"><?= $summary['finishes'] ? 'Nenhuma carta corresponde à busca. Altere os termos ou limpe os filtros.' : 'Sua coleção ainda está vazia. Importe um CSV acima para começar.' ?></p><?php endif; ?>
<div class="grid collection-grid"><?php foreach ($cards as $card): $isFoil=deckIsFoil($card['foil']); ?><div class="collection-item <?= $isFoil?'is-foil':'' ?>"><?php if($isFoil): ?><span class="foil-label">Foil</span><?php endif; ?><?php cardTile($card); ?><div class="collection-item-footer"><p class="collection-quantity"><?= (int)$card['quantity'] ?> cópia(s) · <?= $isFoil?'FOIL':'NORMAL' ?> · <?= h(strtoupper($card['lang'])) ?></p><form method="post" onsubmit="return confirm('Remover todas as cópias desta versão da coleção?')"><input type="hidden" name="csrf" value="<?=h($csrf)?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="card" value="<?=h($card['id'])?>"><input type="hidden" name="foil" value="<?= $isFoil?'1':'0' ?>"><button class="collection-delete" aria-label="Remover <?=h($card['name'])?> <?= $isFoil?'foil':'normal' ?> da coleção">Remover versão</button></form></div></div><?php endforeach; ?></div>
<?php numberedPager($page,$pages,array_merge($f,['sort'=>$sort,'finish'=>$finish])); ?>
<div class="collection-summary-spacer" aria-hidden="true"></div><aside class="collection-summary-bar" aria-label="Resumo da coleção"><div class="collection-summary-value"><span>Valor estimado</span><strong>R$ <?= number_format((float)$summary['total_value'],2,',','.') ?></strong><?php if((int)$summary['unpriced']): ?><small><?= number_format((int)$summary['unpriced'],0,',','.') ?> sem cotação</small><?php endif; ?></div><div class="collection-summary-stats"><?php foreach(['Cartas'=>'total','Foil'=>'foils','Normais'=>'normals','Míticas'=>'mythics','Raras'=>'rares','Incomuns'=>'uncommons','Comuns'=>'commons','Criaturas'=>'creatures','Feitiços'=>'sorceries','Instantâneas'=>'instants','Terrenos'=>'lands','Artefatos'=>'artifacts','Encantamentos'=>'enchantments','Planeswalkers'=>'planeswalkers'] as $label=>$key): ?><span><b><?= number_format((int)$summary[$key],0,',','.') ?></b><?= h($label) ?></span><?php endforeach; ?></div></aside>
<?php pageFooter(); ?>
