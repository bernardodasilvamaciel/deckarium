<section id="explore" class="section-block">
<div class="section-heading"><div><h2>Explorar possibilidades</h2><p>Comece pelas recomendações do comandante ou busque qualquer carta no catálogo.</p></div>
<?php if($commander): ?><form method="post"><?php $tokenFields('sync_edhrec'); ?><input type="hidden" name="outside" value="<?= $includeOutside?'1':'0' ?>"><button class="secondary-link">Atualizar do EDHREC</button></form><?php endif; ?></div>
<details class="info-note synergy-definition"><summary>Como a sinergia é calculada?</summary><p><strong>Sinergia</strong> = (% da carta nos decks deste comandante) − (% da carta em todos os decks da mesma identidade de cor).</p><p>O valor exibido vem da métrica retornada pelo EDHREC. Quando a fonte retorna <em>lift</em>, ele é mostrado separadamente, pois está em outra escala. Associação não é uma nota de força.</p></details>
<?php if(!$commander): ?><div class="empty-state commander-required-note"><strong>Escolha uma comandante para liberar as recomendações.</strong><a class="secondary-link" href="?deck=<?= $id ?>&choose=1#explore">Abrir lista de comandantes</a></div><?php else: ?>
<form method="get" action="/decks.php#explore" class="synergy-controls <?= $showSynergy?'':'catalog-controls' ?>">
<input type="hidden" name="deck" value="<?= $id ?>"><input type="hidden" name="outside" value="0">
<label><input type="checkbox" name="synergy" value="1" <?= $showSynergy?'checked':'' ?>> Sinergia do comandante</label><?php if($showSynergy): ?><label><input type="checkbox" name="outside" value="1" <?= $includeOutside?'checked':'' ?>> Incluir cartas fora da coleção</label><?php else: ?><label class="catalog-only-toggle"><input type="checkbox" name="exclude_owned" value="1" <?= $excludeOwned?'checked':'' ?>> Não considerar cartas da minha coleção</label><?php endif; ?><button class="secondary-link">Aplicar filtros</button><span><?= $synergyCount ?> cartas · maior sinergia primeiro</span></form>
<?php if($showSynergy && !$synergy): ?><p class="empty-state"><?= $includeOutside?'Ainda não há recomendações em cache. Use “Atualizar do EDHREC”.':'Nenhuma recomendação encontrada na coleção. Marque a opção acima para explorar outras cartas.' ?></p><?php elseif($showSynergy): ?>
<div class="synergy-grid"><?php foreach($synergy as $suggestion):
    $already=$selectedByLogical[(string)($suggestion['oracle_id']?:$suggestion['id'])]??null;
    $isCommander=($suggestion['oracle_id']?:$suggestion['id'])===($commander['oracle_id']?:$commander['id']);
?>
<article class="synergy-card"><a href="/card.php?id=<?= h($suggestion['id']) ?>"><img src="<?= h(cardImageUrl($suggestion)??'') ?>" alt="<?= h($suggestion['name']) ?>" loading="lazy" width="146" height="204"></a><div class="card-hover-details"><strong><?= h($suggestion['name']) ?></strong><span><?= h($suggestion['mana_cost']?:'Sem custo de mana') ?> · <?= h($suggestion['type_line']) ?></span><?php if(($suggestion['power']??'')!=='' || ($suggestion['toughness']??'')!==''): ?><span>Poder/Resistência: <?= h((string)($suggestion['power']??'—')) ?>/<?= h((string)($suggestion['toughness']??'—')) ?></span><?php endif; ?><p><?= nl2br(h(deckText($suggestion) ?: 'Texto Oracle não disponível.')) ?></p></div>
<h3><?= h($suggestion['name']) ?></h3>
<p><strong><?= $suggestion['metric']==='lift'?number_format((float)$suggestion['score'],2,',','.').' lift':sprintf('%+.0f',(float)$suggestion['score']*100).'% sinergia' ?></strong></p>
<small><?= (int)$suggestion['owned']>0?(int)$suggestion['owned'].' na coleção':'Fora da coleção' ?> · <?= h(strtoupper((string)$suggestion['set_code'])) ?> #<?= h($suggestion['collector_number']) ?> · <?= h(deckPriceLabel($suggestion)) ?></small>
<form method="post"><?php $tokenFields('add',$suggestion['id']); ?><input type="hidden" name="origin" value="synergy"><input type="hidden" name="outside" value="<?= $includeOutside?'1':'0' ?>"><input type="hidden" name="synergy_page" value="<?= $synergyPage ?>">
<button class="secondary-link" <?= $already||$isCommander?'disabled':'' ?>><?= $isCommander?'Comandante':($already?'Já em '.mb_strtolower(deckStageLabel($already['stage'])):'Adicionar às candidatas') ?></button></form>
</article><?php endforeach; ?></div>
<nav class="pager" aria-label="Páginas de sinergia">
<?php if($synergyPage>1): ?><a href="?deck=<?= $id ?>&mode=synergy&outside=<?= $includeOutside?'1':'0' ?>&synergy_page=<?= $synergyPage-1 ?>#explore">Anterior</a><?php endif; ?>
<span>Página <?= $synergyPage ?> de <?= $synergyPages ?></span>
<?php if($synergyPage<$synergyPages): ?><a href="?deck=<?= $id ?>&mode=synergy&outside=<?= $includeOutside?'1':'0' ?>&synergy_page=<?= $synergyPage+1 ?>#explore">Próxima</a><?php endif; ?></nav>
<p class="source-note">Fonte: <a href="<?= h($synergy[0]['source_url']) ?>" target="_blank" rel="noreferrer">EDHREC</a> · atualizado em <?= h(date('d/m/Y',strtotime($synergy[0]['synced_at']))) ?>. A fórmula acima explica a leitura conceitual; o app preserva a métrica original recebida.</p>
<?php endif; endif; ?></section>
