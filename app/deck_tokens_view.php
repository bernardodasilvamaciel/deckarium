<?php
// Fichas e marcadores que o deck cria: calculados das cartas aprovadas e da comandante (deckTokenList()).
$tokenKinds = ['creature'=>'Criatura','other'=>'Ficha','copy'=>'Cópia','emblem'=>'Emblema','marker'=>'Marcador'];
$tokenTotal = array_sum(array_column($deckTokens,'suggested'));
?>
<section class="deck-tokens" id="deck-tokens" aria-labelledby="deck-tokens-title">
<div class="section-heading"><div><h3 id="deck-tokens-title">Fichas e marcadores</h3><p>Calculados automaticamente a partir das cartas no deck e da comandante. Atualizam sozinhos quando o deck muda.</p></div><?php if($deckTokens): ?><span class="deck-tokens-total"><?= count($deckTokens) ?> tipo<?= count($deckTokens)===1?'':'s' ?> · ~<?= $tokenTotal ?> à mão</span><?php endif; ?></div>
<?php if(!$deckTokens): ?>
<p class="empty-state compact-empty">Nenhuma carta do deck cria fichas, emblemas ou marcadores.</p>
<?php else: ?>
<ul class="token-grid">
<?php foreach($deckTokens as $token): $tokenCard=$token['card']; $tokenSrc=$tokenCard?cardImageUrl($tokenCard,'front','normal'):null; $tokenRaw=$tokenCard?(is_string($tokenCard['raw'])?json_decode($tokenCard['raw'],true):$tokenCard['raw']):[]; $tokenPt=isset($tokenRaw['power'],$tokenRaw['toughness'])?$tokenRaw['power'].'/'.$tokenRaw['toughness']:''; $shownSources=array_slice($token['sources'],0,4); ?>
<li class="token-card kind-<?= h($token['kind']) ?>">
    <?php if($tokenCard): ?><a class="token-art" href="/card.php?id=<?= h($token['id']) ?>" aria-label="Abrir <?= h($token['name']) ?>"><?php else: ?><span class="token-art"><?php endif; ?>
        <?php if($tokenSrc): ?><img src="<?= h($tokenSrc) ?>" alt="" loading="lazy" width="146" height="204"><?php else: ?><span class="token-placeholder"><strong><?= h($token['name']) ?></strong></span><?php endif; ?>
        <b class="token-need" title="Sugestão de fichas à mão: soma do que cada carta cria de uma vez; efeitos com X contam 3"><?= (int)$token['suggested'] ?>×</b>
    <?php if($tokenCard): ?></a><?php else: ?></span><?php endif; ?>
    <div class="token-copy">
        <span class="token-kind"><?= h($tokenKinds[$token['kind']]) ?><?= $tokenPt!=='' ? ' · '.h($tokenPt) : '' ?></span>
        <strong><?= h($token['name']) ?></strong>
        <small><?= h($token['type_line']) ?></small>
        <p class="token-sources">Criada por <?php foreach($shownSources as $index=>$source): ?><?= $index ? ', ' : '' ?><span<?= $source['commander'] ? ' class="is-commander"' : '' ?>><?= h($source['name']) ?></span><?php endforeach; ?><?= count($token['sources'])>4 ? ' e mais '.(count($token['sources'])-4) : '' ?></p>
        <?php if($token['owned']): ?><span class="token-owned"><?= (int)$token['owned'] ?> na coleção</span><?php endif; ?>
    </div>
</li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
</section>
