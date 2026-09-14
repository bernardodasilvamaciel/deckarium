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
            } elseif (in_array($action,['commander','add','item','remove'],true)) {
                $cardId = (string)($_POST['card'] ?? '');
                if (!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/i',$cardId)) throw new RuntimeException('Carta inválida.');
                $card = deckQuery('SELECT * FROM cards WHERE id=?',[$cardId])->fetch();
                if (!$card) throw new RuntimeException('Carta não encontrada no acervo.');
                if ($action === 'commander') {
                    if (!deckQuery('SELECT 1 FROM cards c WHERE c.id=? AND '.deckCommanderSql(),[$cardId])->fetchColumn()) throw new RuntimeException('Escolha uma carta elegível como comandante.');
                    deckQuery('UPDATE builder_decks SET commander_id=? WHERE id=?',[$cardId,$id]);
                    deckQuery('DELETE FROM builder_items i USING cards c WHERE i.card_id=c.id AND i.deck_id=? AND COALESCE(c.oracle_id,c.id)=?::uuid',[$id,$card['oracle_id'] ?: $cardId]);
                    $message = 'Comandante definido. Confira a identidade de cor das cartas já selecionadas.';
                } elseif ($action === 'remove') {
                    deckQuery('DELETE FROM builder_items WHERE deck_id=? AND card_id=?',[$id,$cardId]);
                    $message = 'Carta retirada da seleção.';
                } elseif ($action === 'add') {
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
        header('Location: '.$return, true,303); exit;
    } catch (Throwable $e) { $error = $e instanceof RuntimeException && !($e instanceof PDOException) ? $e->getMessage() : 'Não foi possível salvar. Nenhuma seleção foi descartada; tente novamente.'; }
}
session_write_close();
$decks = deckQuery('SELECT d.*,c.name commander FROM builder_decks d LEFT JOIN cards c ON c.id=d.commander_id ORDER BY d.id DESC')->fetchAll();
$deck = $id ? deckQuery('SELECT * FROM builder_decks WHERE id=?',[$id])->fetch() : null;
$commander = $deck && $deck['commander_id'] ? deckQuery('SELECT * FROM cards WHERE id=?',[$deck['commander_id']])->fetch() : null;
$identity = $commander ? (json_decode($commander['color_identity'],true) ?: []) : [];
$collection = deckQuery('SELECT COALESCE(SUM(quantity),0) total,COUNT(*) printings,COUNT(*) FILTER(WHERE c.id IS NULL) unmatched FROM builder_collection o LEFT JOIN cards c ON c.id=o.scryfall_id')->fetch();
$q = substr(trim((string)($_GET['q']??'')),0,200);
$oracle = substr(trim((string)($_GET['oracle']??($deck['terms']??''))),0,1000);
$type = substr(trim((string)($_GET['type']??'')),0,120);
$match = ($_GET['match']??'all')==='any' ? 'any' : 'all';
$ownedOnly = ($_GET['owned']??'')==='1';
$colorsOnly = ($_GET['colors']??'')==='1';
$choosingCommander = isset($_GET['choose']);
$page = max(1,min(10000,(int)($_GET['page']??1)));
$terms = array_slice(deckTerms($oracle),0,12);
$highlightTerms=array_merge($terms,deckTerms($type),$q!==''?[$q]:[]);
$filterHidden = function() use($q,$oracle,$type,$match,$ownedOnly,$colorsOnly,$page): void {
    foreach (['q'=>$q,'oracle'=>$oracle,'type'=>$type,'match'=>$match,'owned'=>$ownedOnly?'1':'','colors'=>$colorsOnly?'1':'','page'=>$page] as $k=>$v) echo '<input type="hidden" name="'.h($k).'" value="'.h($v).'">';
};
$tokenFields = function(string $action, ?string $card = null) use($csrf,$id): void {
    echo '<input type="hidden" name="csrf" value="'.h($csrf).'"><input type="hidden" name="deck" value="'.$id.'"><input type="hidden" name="action" value="'.h($action).'">';
    if ($card) echo '<input type="hidden" name="card" value="'.h($card).'">';
};
$items = $deck ? deckQuery(deckOwnedSql().'SELECT c.*,i.stage,i.quantity,i.role,i.notes,COALESCE(o.owned,0) owned FROM builder_items i JOIN cards c ON c.id=i.card_id LEFT JOIN owned o ON o.logical_id=COALESCE(c.oracle_id,c.id) WHERE i.deck_id=? ORDER BY c.name',[$id])->fetchAll() : [];
$selectedByLogical = [];
foreach ($items as $selected) $selectedByLogical[(string)($selected['oracle_id'] ?: $selected['id'])] = $selected;
$finalCount = $commander ? 1 : 0; $landCount=0; $roles=[]; $shopping=[]; $warnings=[]; $pipCounts=array_fill_keys(['W','U','B','R','G'],0);
foreach ($items as $item) {
    if ($item['stage']!=='deck') continue;
    $quantity=(int)$item['quantity']; $finalCount+=$quantity;
    if (str_contains($item['type_line'],'Land')) $landCount+=$quantity;
    if ($item['role']!=='') $roles[$item['role']]=($roles[$item['role']]??0)+$quantity;
    if (array_diff(json_decode($item['color_identity'],true)?:[],$identity) && $commander) $warnings[]=$item['name'].': fora da identidade de cor do comandante.';
    if ($quantity>1 && !str_contains($item['type_line'],'Basic') && !preg_match('/deck can have (any number|up to)/i',deckText($item))) $warnings[]=$item['name'].': confira a quantidade permitida para Commander.';
    $missing=max(0,$quantity-(int)$item['owned']); if ($missing) $shopping[]=['name'=>$item['name'],'quantity'=>$missing];
    foreach ($pipCounts as $color=>$_) $pipCounts[$color]+=substr_count((string)$item['mana_cost'],$color)*$quantity;
}
if ($commander) {
    $commanderOwned=(int)deckQuery(deckOwnedSql().'SELECT COALESCE((SELECT owned FROM owned WHERE logical_id=?::uuid),0)',[$commander['oracle_id']?:$commander['id']])->fetchColumn();
    if (!$commanderOwned) $shopping[]=['name'=>$commander['name'],'quantity'=>1];
    foreach ($pipCounts as $color=>$_) $pipCounts[$color]+=substr_count((string)$commander['mana_cost'],$color);
}
if ($deck && isset($_GET['export'])) {
    header('Content-Type: text/plain; charset=utf-8'); header('Content-Disposition: attachment; filename="'.($_GET['export']==='shopping'?'compras':'deck').'-'.$id.'.txt"');
    if ($_GET['export']==='shopping') foreach ($shopping as $row) echo $row['quantity'].' '.$row['name']."\n";
    else { if ($commander) echo '1 '.$commander['name']."\n"; foreach($items as $row) if($row['stage']==='deck') echo $row['quantity'].' '.$row['name']."\n"; }
    exit;
}
$results=[]; $hasMore=false;
if ($deck) {
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
    if($choosingCommander)$where[]=deckCommanderSql();
    $whereSql=$where?'WHERE '.implode(' AND ',$where):'';
    $offset=($page-1)*24;
    $results=deckQuery(deckOwnedSql()."SELECT c.*,r.owned FROM (SELECT DISTINCT ON(COALESCE(c.oracle_id,c.id)) c.id,c.name,c.released_at,COALESCE(o.owned,0) owned FROM cards c LEFT JOIN owned o ON o.logical_id=COALESCE(c.oracle_id,c.id) {$whereSql} ORDER BY COALESCE(c.oracle_id,c.id),(COALESCE(o.owned,0)>0) DESC,(c.lang='en') DESC,c.released_at DESC NULLS LAST,c.id) r JOIN cards c ON c.id=r.id ORDER BY r.owned DESC,r.released_at DESC NULLS LAST,r.name,r.id LIMIT 25 OFFSET {$offset}",$params)->fetchAll();
    $hasMore=count($results)>24; $results=array_slice($results,0,24);
}
pageHeader('Construir decks');
?>
<section class="hero"><div><h1><?= h($deck?$deck['name']:'Sua oficina de decks') ?></h1><p>Explore sua coleção, compare possibilidades e decida cada carta.</p></div><?php if($deck): ?><a href="/decks.php">Todos os decks</a><?php endif; ?></section>
<?php if($message): ?><p class="notice ok" role="status"><?= h($message) ?></p><?php endif; ?>
<?php if($error): ?><p class="notice error" role="alert"><?= h($error) ?></p><?php endif; ?>
<?php if(!$deck): ?>
<section class="builder-intro"><form method="post" class="panel builder-form"><?php $tokenFields('create'); ?><h2>Começar um deck</h2><label>Nome do deck<input name="name" required maxlength="160" placeholder="Ex.: Terrenos e sacrifícios"></label><button class="primary-link">Criar deck Commander</button></form>
<form method="post" enctype="multipart/form-data" class="panel builder-form"><?php $tokenFields('import'); ?><h2>Sua coleção</h2><a href="/collection.php">Ver cartas da minha coleção</a><p><?= number_format((int)$collection['total'],0,',','.') ?> cartas · <?= (int)$collection['printings'] ?> impressões</p><label>Exportação do ManaBox<input type="file" name="collection" accept=".csv,text/csv" required></label><p class="muted">Cada importação substitui as quantidades da coleção pelo arquivo completo. Os decks permanecem salvos.</p><button class="primary-link">Importar coleção</button><?php if($collection['unmatched']): ?><p class="notice warning"><?= (int)$collection['unmatched'] ?> impressões não encontradas no catálogo local. Foram preservadas na coleção, mas não entram nos cálculos de disponibilidade.</p><?php endif; ?></form></section>
<section><h2>Meus decks</h2><?php if(!$decks): ?><p class="empty-state">Crie o primeiro deck para abrir a bancada de seleção.</p><?php endif; ?><div class="builder-deck-list"><?php foreach($decks as $d): ?><a href="?deck=<?= $d['id'] ?>"><strong><?= h($d['name']) ?></strong><span><?= h($d['commander']?:'Comandante a escolher') ?></span></a><?php endforeach; ?></div></section>
<?php else: ?>
<nav class="tabs" aria-label="Etapas de construção"><a href="#intent">Comandante e estratégia</a><a href="#explore">Explorar cartas</a><a href="#selection">Minha seleção (<?= count($items) ?>)</a><a href="#balance">Análise e compras</a></nav>
<section id="intent" class="builder-intro">
<div class="panel"><h2>Comandante</h2><?php if($commander): ?><div class="builder-commander"><?php if($src=cardImageUrl($commander)): ?><img src="<?= h($src) ?>" alt="<?= h($commander['name']) ?>" width="146" height="204"><?php endif; ?><div><h3><?= h($commander['name']) ?></h3><p>Identidade: <?= $identity?h(implode(' · ',$identity)):'incolor' ?></p><p><?= nl2br(h(deckText($commander))) ?></p></div></div><?php else: ?><p>Escolha uma criatura lendária para usar a identidade de cor como filtro.</p><?php endif; ?><a href="?deck=<?= $id ?>&choose=1&oracle=#explore">Escolher comandante</a><p class="muted">Esta versão trabalha com um comandante. Combinações de parceiros e Backgrounds ainda precisam de suporte específico.</p></div>
<form method="post" class="panel builder-form"><?php $tokenFields('strategy'); ?><h2>Minha intenção</h2><label>Estratégia e mecânicas<textarea name="strategy" rows="4" placeholder="Plano principal, temas secundários e o que quero evitar"><?= h($deck['strategy']) ?></textarea></label><label>Termos Oracle para explorar<input name="terms" value="<?= h($deck['terms']) ?>" placeholder="sacrifice; land; graveyard"></label><small>Separe palavras ou frases por ponto e vírgula. Estes termos são filtros escolhidos por você, não uma avaliação automática de sinergia.</small><button class="primary-link">Salvar intenção</button></form>
</section>
<section id="explore" class="section-block"><h2><?= $choosingCommander?'Escolher comandante':'Explorar possibilidades' ?></h2><p>Busca literal no Oracle em inglês, incluindo as duas faces. As cartas que você possui aparecem primeiro.</p><p class="muted">Em Tipos e temas, separe os termos por ponto e vírgula. Basta um deles aparecer no tipo ou no Oracle: pirate; assassin; vehicle; treasure inclui também cartas que criam Tesouros. Esse grupo é combinado com os demais filtros.</p>
<form class="builder-search builder-form" method="get"><input type="hidden" name="deck" value="<?= $id ?>"><?php if($choosingCommander): ?><input type="hidden" name="choose" value="1"><?php endif; ?><label>Nome<input name="q" value="<?= h($q) ?>" placeholder="Nome da carta"></label><label>Texto Oracle<input name="oracle" value="<?= h($oracle) ?>" placeholder="draw a card; sacrifice"></label><label>Tipos e temas<input name="type" value="<?= h($type) ?>" placeholder="pirate; assassin; vehicle; treasure"></label><label>Combinação do Oracle<select name="match"><option value="all" <?= $match==='all'?'selected':'' ?>>Todos os termos</option><option value="any" <?= $match==='any'?'selected':'' ?>>Qualquer termo</option></select></label><label class="builder-check"><input type="checkbox" name="owned" value="1" <?= $ownedOnly?'checked':'' ?>>Só minha coleção</label><label class="builder-check"><input type="checkbox" name="colors" value="1" <?= $colorsOnly?'checked':'' ?> <?= !$commander?'disabled':'' ?>>Identidade do comandante</label><button class="primary-link">Pesquisar cartas</button><a href="?deck=<?= $id ?>&oracle=#explore">Limpar filtros</a></form>
<?php if(!$results): ?><p class="empty-state">Nenhuma carta corresponde aos filtros. Tente “qualquer termo” ou remova um filtro.</p><?php endif; ?>
<div class="builder-results"><?php foreach($results as $card): ?><article class="builder-result"><?php if($src=cardImageUrl($card)): ?><a href="/card.php?id=<?= h($card['id']) ?>"><img src="<?= h($src) ?>" alt="<?= h($card['name']) ?>" width="146" height="204" loading="lazy"></a><?php endif; ?><div><h3><?= deckHighlight($card['name'],$highlightTerms) ?></h3><p class="builder-stock"><?= (int)$card['owned']>0?'Você possui '.(int)$card['owned'].' cópia(s)':'Falta na coleção' ?></p><p class="muted"><?= deckHighlight($card['type_line'],$highlightTerms) ?> · <?= manaSymbols($card['mana_cost']) ?></p><div class="builder-oracle"><p><?= nl2br(deckHighlight(deckText($card),$highlightTerms)) ?></p><?php foreach($terms as $term): if(stripos(deckText($card),$term)!==false): ?><small>Contém “<?= h($term) ?>” no Oracle.</small><?php endif; endforeach; ?></div><form method="post"><?php $tokenFields($choosingCommander?'commander':'add',$card['id']); $filterHidden(); ?><button class="secondary-link"><?= $choosingCommander?'Usar como comandante':'Adicionar às candidatas' ?></button></form></div></article><?php endforeach; ?></div>
<nav class="pager" aria-label="Resultados de cartas"><?php $base=$_GET;$base['deck']=$id; if($page>1): $base['page']=$page-1; ?><a href="?<?= h(http_build_query($base)) ?>#explore">Anterior</a><?php endif; ?><span>Página <?= $page ?></span><?php if($hasMore): $base['page']=$page+1; ?><a href="?<?= h(http_build_query($base)) ?>#explore">Próxima</a><?php endif; ?></nav>
</section>
<section id="selection" class="section-block"><h2>Minha seleção</h2><p>Mova cada carta conforme sua decisão. Funções e observações são definidas por você.</p>
<?php foreach($stages as $stage=>$label): $group=array_filter($items,fn($c)=>$c['stage']===$stage); ?><details class="builder-stage" open><summary><?= h($label) ?> · <?= count($group) ?> cartas diferentes</summary><?php if(!$group): ?><p class="muted">Nenhuma carta nesta etapa.</p><?php endif; ?><?php foreach($group as $item): ?><form method="post" class="builder-item builder-form"><?php $tokenFields('item',$item['id']);$filterHidden(); ?><div><a href="/card.php?id=<?= h($item['id']) ?>"><strong><?= h($item['name']) ?></strong></a><small>Na coleção: <?= (int)$item['owned'] ?></small></div><label>Etapa<select name="stage"><?php foreach($stages as $value=>$text): ?><option value="<?= h($value) ?>" <?= $value===$stage?'selected':'' ?>><?= h($text) ?></option><?php endforeach; ?></select></label><label>Quantidade<input type="number" name="quantity" min="1" max="1000" value="<?= (int)$item['quantity'] ?>" required></label><label>Função<input name="role" value="<?= h($item['role']) ?>" placeholder="Compra, ramp, proteção…" maxlength="100"></label><label>Minha avaliação<input name="notes" value="<?= h($item['notes']) ?>" placeholder="Por que entra? Qual alternativa?" maxlength="2000"></label><button class="secondary-link">Salvar</button><button type="submit" name="action" value="remove" class="builder-remove">Retirar</button></form><?php endforeach; ?></details><?php endforeach; ?></section>
<section id="balance" class="section-block"><h2>Análise e próximos passos</h2><div class="builder-intro"><div class="panel"><h3>Composição escolhida</h3><p><strong><?= $finalCount ?></strong> cartas contando o comandante · <strong><?= $landCount ?></strong> terrenos</p><p>Referência para o formato: 100 cartas. <?= max(0,100-$finalCount) ?> espaços restantes<?= $finalCount>100?' · '.($finalCount-100).' acima da referência':'' ?>.</p><?php foreach($roles as $role=>$count): ?><p><?= h($role) ?>: <?= $count ?></p><?php endforeach; ?><p class="muted">As funções só contam as cartas aprovadas, com a classificação que você informou.</p><h3>Demanda de mana colorida</h3><p><?= h(implode(' · ',array_map(fn($c)=>$c.': '.$pipCounts[$c],array_keys($pipCounts)))) ?></p><p class="muted">Contagem de símbolos nos custos. Híbridos contam em ambas as cores. Não é uma recomendação de terrenos: custos alternativos, faces, aceleração e turnos de jogo exigem avaliação adicional.</p><?php foreach($warnings as $warning): ?><p class="notice warning"><?= h($warning) ?></p><?php endforeach; ?><p class="muted">Alertas básicos, não uma validação completa de legalidade ou força do deck.</p><a href="?deck=<?= $id ?>&export=deck">Exportar deck em texto</a></div>
<div class="panel"><h3>O que falta adquirir</h3><p>Somente cartas aprovadas e comandante. Candidatas não viram compras.</p><?php if(!$shopping): ?><p><?= $finalCount?'Você possui as cartas aprovadas até agora.':'A lista aparece conforme você aprova cartas.' ?></p><?php endif; ?><ul><?php foreach($shopping as $row): ?><li><?= $row['quantity'] ?> × <?= h($row['name']) ?></li><?php endforeach; ?></ul><a href="?deck=<?= $id ?>&export=shopping">Exportar lista de compras</a><p class="muted">Qualquer impressão da mesma carta conta. Cartas usadas em outros decks ainda não são reservadas automaticamente. Valores pagos no CSV não são preços atuais.</p></div></div></section>
<?php endif; if ($deck): $selectionMap=[]; foreach($items as $selected){$selectionMap[(string)$selected['id']]=['stage'=>$selected['stage'],'label'=>deckStageLabel($selected['stage']),'image'=>cardImageUrl($selected,'front','small')];} ?>
<script>window.builderSelection=<?= json_encode($selectionMap,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>;</script>
<?php endif; pageFooter(); ?>
