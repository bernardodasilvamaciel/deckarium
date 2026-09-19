<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require __DIR__ . '/functions.php';
require __DIR__ . '/partials.php';
require __DIR__ . '/deck_library.php';
$authUser=authUser();
$userId=(int)($authUser['id']??0);
$_SESSION['builder_csrf'] ??= bin2hex(random_bytes(24));
$builderCsrf=$_SESSION['builder_csrf']; $cardActionError=''; $cardActionMessage='';

$id = (string)($_GET['id'] ?? '');
if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i', $id)) {
    http_response_code(404);
    pageHeader('Carta não encontrada');
    echo '<div class="empty-state"><h1>Carta não encontrada</h1><p>O endereço não corresponde a uma carta do acervo.</p><a class="primary-link" href="/">Voltar ao catálogo</a></div>';
    pageFooter();
    exit;
}
$stmt = db()->prepare('SELECT * FROM cards WHERE id = :id');
$stmt->execute([':id' => $id]);
$card = $stmt->fetch();
if (!$card) {
    http_response_code(404);
    pageHeader('Carta não encontrada');
    echo '<div class="empty-state"><h1>Carta não encontrada</h1><p>Esta impressão ainda não está no acervo local.</p><a href="/">Voltar ao catálogo</a></div>';
    pageFooter();
    exit;
}
if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        if($userId<1){header('Location: /login.php?next='.rawurlencode('/card.php?id='.$card['id']),true,303);exit;}
        if(!hash_equals($builderCsrf,(string)($_POST['csrf']??''))) throw new RuntimeException('Sessão expirada. Recarregue a página e tente novamente.');
        $action=(string)($_POST['action']??'');
        if($action==='add_to_deck'){
            $deckId=max(0,(int)($_POST['deck']??0)); $deck=deckQuery('SELECT * FROM builder_decks WHERE id=? AND user_id=?',[$deckId,$userId])->fetch();
            if(!$deck) throw new RuntimeException('Escolha um deck válido.');
            $logical=$card['oracle_id']?:$card['id']; $exists=deckQuery('SELECT 1 FROM builder_items i JOIN cards c ON c.id=i.card_id WHERE i.deck_id=? AND COALESCE(c.oracle_id,c.id)=?::uuid',[$deckId,$logical])->fetchColumn();
            $commanderLogical=$deck['commander_id']?deckQuery('SELECT COALESCE(oracle_id,id) FROM cards WHERE id=?',[$deck['commander_id']])->fetchColumn():null;
            if($exists||$commanderLogical===$logical) throw new RuntimeException('Essa carta já está no deck ou é a comandante.');
            deckQuery("INSERT INTO builder_items(deck_id,card_id,stage,quantity) VALUES (?,?,'candidate',1)",[$deckId,$card['id']]);
            header('Location: /decks.php?deck='.$deckId.'&view=selection&stage=candidate#selection',true,303); exit;
        }
        if($action==='create_commander_deck'){
            if(!deckQuery('SELECT 1 FROM cards c WHERE c.id=? AND '.deckCommanderSql(),[$card['id']])->fetchColumn()) throw new RuntimeException('Esta carta não pode ser comandante.');
            $name=trim((string)($_POST['name']??'')); if($name==='') $name=$card['name'].' — planejamento';
            $deckId=(int)deckQuery("INSERT INTO builder_decks(user_id,name,commander_id) VALUES (?,?,?) RETURNING id",[$userId,substr($name,0,160),$card['id']])->fetchColumn();
            deckSchema(); deckRefreshInsightsIfStale($card);
            header('Location: /decks.php?deck='.$deckId.'&view=overview',true,303); exit;
        }
        throw new RuntimeException('Ação inválida.');
    }catch(Throwable $e){$cardActionError=$e instanceof RuntimeException?$e->getMessage():'Não foi possível salvar a ação.';}
}
$deckChoices=$userId?deckQuery('SELECT id,name,status FROM builder_decks WHERE user_id=? ORDER BY name',[$userId])->fetchAll():[];
$isCommander=(bool)deckQuery('SELECT 1 FROM cards c WHERE c.id=? AND '.deckCommanderSql(),[$card['id']])->fetchColumn();

$printings = [];
if (!empty($card['oracle_id'])) {
    // Cópias na coleção por impressão: a grade mostra quais versões você já tem.
    $p = db()->prepare(<<<SQL
SELECT c.id,c.name,c.set_code,c.set_name,c.collector_number,c.released_at,c.lang,c.rarity,c.prices,c.raw,
       c.local_image,c.image_uri,c.oracle_id,
       COALESCE(SUM(b.quantity) FILTER (WHERE NOT b.foil),0)::int AS owned_normal,
       COALESCE(SUM(b.quantity) FILTER (WHERE b.foil),0)::int AS owned_foil
FROM cards c
LEFT JOIN builder_collection b ON b.scryfall_id = c.id AND b.user_id = :user_id
WHERE c.oracle_id = :oracle_id
GROUP BY c.id
ORDER BY (c.lang = 'en') DESC, c.released_at DESC NULLS LAST, c.set_code, c.collector_number
SQL);
    $p->execute([':oracle_id' => $card['oracle_id'], ':user_id' => $userId]);
    $printings = $p->fetchAll();
}

$cardDescription = trim($card['name'] . ' — ' . (string)$card['type_line'] . '. ' . (string)$card['set_name']
    . ' (' . strtoupper((string)$card['set_code']) . ') #' . (string)$card['collector_number'] . '. '
    . mb_substr((string)($card['oracle_text'] ?? ''), 0, 160));
pageHeader($card['name'], $cardDescription, ['image' => cardImageUrl($card, 'front', 'normal') ?: null]);
$front = cardImageUrl($card, 'front', 'normal');
$back = cardImageUrl($card, 'back', 'normal');
?>
<a class="back-link" href="/edition.php?set=<?= h($card['set_code']) ?>">← <?= h($card['set_name']) ?></a>
<?php if($cardActionError): ?><p class="notice error" role="alert"><?= h($cardActionError) ?></p><?php endif; ?>
<?php if(!$userId): ?><section class="card-actions panel" data-card-actions><div><h2>Usar esta carta</h2><p>Entre na sua conta para adicioná-la a um deck ou abrir um planejamento com ela.</p></div><a class="primary-link" href="/login.php?next=<?= h(rawurlencode('/card.php?id='.$card['id'])) ?>">Entrar</a></section><?php else: ?><section class="card-actions panel" data-card-actions><div><h2>Usar esta carta</h2><p>Adicione esta impressão como candidata a um deck existente.</p></div><?php if($deckChoices): ?><form method="post" class="card-action-form"><input type="hidden" name="csrf" value="<?= h($builderCsrf) ?>"><input type="hidden" name="action" value="add_to_deck"><label>Deck<select name="deck"><?php foreach($deckChoices as $choice): ?><option value="<?= (int)$choice['id'] ?>"><?= h($choice['name']) ?> · <?= $choice['status']==='ready'?'finalizado':'em planejamento' ?></option><?php endforeach; ?></select></label><button class="primary-link">Adicionar às candidatas</button></form><?php else: ?><a class="primary-link" href="/decks.php">Criar um deck primeiro</a><?php endif; ?><?php if($isCommander): ?><form method="post" class="card-action-form commander-action"><input type="hidden" name="csrf" value="<?= h($builderCsrf) ?>"><input type="hidden" name="action" value="create_commander_deck"><label>Nome do novo deck<input name="name" value="<?= h($card['name'].' — planejamento') ?>" maxlength="160"></label><button class="secondary-link">Abrir deck com esta comandante</button></form><?php endif; ?></section><?php endif; ?>
<div class="detail">
  <div class="detail-images">
    <?php if ($front): ?><img src="<?= h($front) ?>" alt="<?= h($card['name']) ?>"><?php endif; ?>
    <?php if ($back): ?><img src="<?= h($back) ?>" alt="Verso/segunda face de <?= h($card['name']) ?>"><?php endif; ?>
    <?php if (!$front && !$back): ?><div class="placeholder large"><strong><?= h($card['name']) ?></strong><span>imagem indisponível</span></div><?php endif; ?>
  </div>
  <section class="panel">
   <div class="card-facts" data-card-facts>
    <h1><?= h($card['name']) ?></h1>
    <p class="mana-line"><strong>Custo:</strong> <span class="mana-cost"><?= manaSymbols($card['mana_cost']) ?></span></p>
    <p><strong>Tipo:</strong> <?= h($card['type_line']) ?></p>
    <?php if ($card['oracle_text']): ?>
    <div class="oracle"><?= oracleText($card['oracle_text']) ?></div>
    <?php else: foreach (json_decode($card['card_faces'] ?? '[]', true) ?: [] as $cardFace): ?>
    <div class="oracle"><strong><?= h($cardFace['name'] ?? '') ?></strong><p class="mana-line"><span class="mana-cost"><?= manaSymbols($cardFace['mana_cost'] ?? null) ?></span> · <?= h($cardFace['type_line'] ?? '') ?></p><?= oracleText($cardFace['oracle_text'] ?? '') ?></div>
    <?php endforeach; endif; ?>
    <dl>
      <dt>Edição</dt><dd><?= h($card['set_name']) ?> (<?= h(strtoupper((string)$card['set_code'])) ?>)</dd>
      <dt>Número</dt><dd><?= h($card['collector_number']) ?></dd>
      <dt>Raridade</dt><dd><?= h($card['rarity']) ?></dd>
      <dt>Artista</dt><dd><?= h($card['artist']) ?></dd>
      <dt>Lançamento</dt><dd><?= h(displayDate($card['released_at'])) ?></dd>
      <dt>Preços desta impressão</dt><dd class="printing-price-detail"><?= h(deckPriceVariantsLabel($card)) ?></dd>
    </dl>
    <p class="source-note price-source-note">Fonte: preços USD/EUR desta impressão no Scryfall, convertidos para reais pelo câmbio configurado.</p>
    <?php // Preço de mercado no Brasil: a consulta é feita no site da Liga, em outra aba. ?>
    <a class="secondary-link liga-link" href="https://www.ligamagic.com.br/?view=cards/card&amp;card=<?= h(rawurlencode(explode(' // ', (string)$card['name'])[0])) ?>" target="_blank" rel="noopener noreferrer">Ver preços na LigaMagic <span aria-hidden="true">↗</span></a>
   </div>

  </section>
</div>
<?php if (count($printings) > 1) require __DIR__ . '/card_printings_view.php'; ?>
<?php pageFooter(); ?>
