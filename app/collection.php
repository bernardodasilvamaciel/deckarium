<?php
declare(strict_types=1);
require __DIR__ . '/functions.php';
require __DIR__ . '/partials.php';
require __DIR__ . '/deck_library.php';
session_start();
$_SESSION['collection_csrf'] ??= bin2hex(random_bytes(24));
$csrf=$_SESSION['collection_csrf'];
$message=$_SESSION['collection_message']??''; unset($_SESSION['collection_message']);
$error='';
deckSchema();
if(isset($_GET['template']) && $_GET['template']==='csv'){
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="modelo-colecao-deckarium.csv"');
    echo "Name,Scryfall ID,Quantity\n"; exit;
}
if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        if(!hash_equals($csrf,(string)($_POST['csrf']??''))) throw new RuntimeException('Sessão expirada. Recarregue a página e tente novamente.');
        $action=(string)($_POST['action']??'');
        if($action==='import'){
            if(($_FILES['collection']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK) throw new RuntimeException('Selecione um CSV válido dentro do limite de upload do servidor.');
            $replace=($_POST['mode']??'replace')!=='add';
            $result=deckImport($_FILES['collection']['tmp_name'],$replace);
            $message=number_format($result['quantity'],0,',','.').' cartas importadas em '.number_format($result['printings'],0,',','.').' impressões. '.($replace?'A coleção anterior foi substituída.':'As quantidades foram somadas ao acervo atual.');
        }elseif($action==='delete'){
            $cardId=(string)($_POST['card']??'');
            if(!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/i',$cardId)) throw new RuntimeException('Impressão inválida.');
            $deleted=deckQuery('DELETE FROM builder_collection WHERE scryfall_id=? RETURNING name,quantity',[$cardId])->fetch();
            if(!$deleted) throw new RuntimeException('Essa impressão já não está na coleção.');
            $message=(int)$deleted['quantity'].' cópia(s) de '.$deleted['name'].' removida(s) da coleção.';
        }else throw new RuntimeException('Ação inválida.');
        $_SESSION['collection_message']=$message; header('Location: /collection.php',true,303); exit;
    }catch(Throwable $e){$error=$e instanceof RuntimeException&&!($e instanceof PDOException)?$e->getMessage():'Não foi possível atualizar a coleção. Os dados anteriores foram preservados.';}
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
$sortSql = match ($sort) {
    'color' => "COALESCE(c.colors::text,'[]'),c.name,c.set_code,c.collector_number,c.id",
    'price' => "CASE WHEN NULLIF(c.prices->>'usd','') IS NOT NULL THEN (c.prices->>'usd')::numeric WHEN NULLIF(c.prices->>'eur','') IS NOT NULL THEN (c.prices->>'eur')::numeric WHEN NULLIF(c.prices->>'usd_foil','') IS NOT NULL THEN (c.prices->>'usd_foil')::numeric WHEN NULLIF(c.prices->>'eur_foil','') IS NOT NULL THEN (c.prices->>'eur_foil')::numeric END DESC NULLS LAST,c.name,c.set_code,c.collector_number,c.id",
    default => 'c.name,c.set_code,c.collector_number,c.id',
};
$page = max(1, (int)($_GET['page'] ?? 1));
[$whereSql,$params]=cardFilterSql($f);
$from = ' FROM builder_collection o JOIN cards c ON c.id=o.scryfall_id ' . $whereSql;
$count = (int)deckQuery('SELECT COUNT(*)'.$from, $params)->fetchColumn();
$pages = max(1, (int)ceil($count / 36)); $page = min($page, $pages); $offset = ($page-1)*36;
$cards = deckQuery('SELECT c.*,o.quantity'.$from." ORDER BY {$sortSql} LIMIT 36 OFFSET {$offset}", $params)->fetchAll();
$sortParams=$_GET; unset($sortParams['page'],$sortParams['template'],$sortParams['sort']);
$summary = deckQuery('SELECT COALESCE(SUM(quantity),0) total,COUNT(*) printings FROM builder_collection')->fetch();
pageHeader('Minha coleção');
?>
<section class="hero"><div><h1>Minha coleção</h1><p><?= number_format((int)$summary['total'],0,',','.') ?> cartas em <?= number_format((int)$summary['printings'],0,',','.') ?> impressões. Gerencie as versões físicas usadas nos seus decks.</p></div><a href="/decks.php">Criar ou planejar decks</a></section>
<?php if($message):?><p class="notice ok" role="status"><?=h($message)?></p><?php endif;if($error):?><p class="notice error" role="alert"><?=h($error)?></p><?php endif;?>
<details class="collection-manage"><summary>Gerenciar coleção: importar CSV e filtros</summary><section class="collection-import-panel"><div><h2>Importar cartas por CSV</h2><p>Use uma exportação do ManaBox ou um arquivo com exatamente estas colunas:</p><code>Name,Scryfall ID,Quantity</code><p class="muted">O Scryfall ID identifica a impressão exata. Linhas inválidas cancelam toda a operação e preservam a coleção atual.</p><a href="?template=csv">Baixar modelo CSV</a></div><form method="post" enctype="multipart/form-data" class="collection-manage-form"><input type="hidden" name="csrf" value="<?=h($csrf)?>"><input type="hidden" name="action" value="import"><label>Arquivo CSV<input type="file" name="collection" accept=".csv,text/csv" required></label><fieldset><legend>Como importar</legend><label><input type="radio" name="mode" value="replace" checked> Substituir pela coleção completa</label><label><input type="radio" name="mode" value="add"> Somar estas quantidades ao acervo atual</label></fieldset><button class="primary-link">Importar coleção</button></form></section><?php cardFilterForm($f,deckQuery('SELECT c.set_code,MAX(c.set_name) set_name FROM builder_collection o JOIN cards c ON c.id=o.scryfall_id GROUP BY c.set_code ORDER BY MAX(c.set_name)')->fetchAll(),'/collection.php'); ?></details>
<div class="collection-toolbar"><span>Ordenar coleção</span><form method="get"><?php foreach($sortParams as $key=>$value): foreach(is_array($value)?$value:[$value] as $v): ?><input type="hidden" name="<?= h($key.(is_array($value)?'[]':'')) ?>" value="<?= h($v) ?>"><?php endforeach; endforeach; ?><select name="sort" aria-label="Ordenar coleção"><option value="name" <?= $sort==='name'?'selected':'' ?>>Nome</option><option value="color" <?= $sort==='color'?'selected':'' ?>>Cor</option><option value="price" <?= $sort==='price'?'selected':'' ?>>Preço</option></select><button class="secondary-link">Aplicar</button></form></div>
<p class="muted"><?= number_format($count,0,',','.') ?> impressões encontradas. <a href="/collection.php">Limpar filtros</a></p>
<?php if (!$cards): ?><p class="empty-state"><?= $summary['printings'] ? 'Nenhuma carta corresponde à busca. Altere os termos ou limpe os filtros.' : 'Sua coleção ainda está vazia. Importe um CSV acima para começar.' ?></p><?php endif; ?>
<div class="grid collection-grid"><?php foreach ($cards as $card): ?><div class="collection-item"><?php cardTile($card); ?><div class="collection-item-footer"><p class="collection-quantity"><?= (int)$card['quantity'] ?> cópia(s) · <?= h(strtoupper($card['lang'])) ?></p><form method="post" onsubmit="return confirm('Remover todas as cópias desta impressão da coleção?')"><input type="hidden" name="csrf" value="<?=h($csrf)?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="card" value="<?=h($card['id'])?>"><button class="collection-delete" aria-label="Remover <?=h($card['name'])?> desta impressão da coleção">Remover impressão</button></form></div></div><?php endforeach; ?></div>
<?php numberedPager($page,$pages,array_merge($f,['sort'=>$sort])); ?>
<?php pageFooter(); ?>
