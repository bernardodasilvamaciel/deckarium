<?php
declare(strict_types=1);
$advancedOpen=$match==='any' || $rarity!=='' || $setFilter!=='' || $cmcMin!==null || $cmcMax!==null || $commanderColors || $colorsOnly;
$clearUrl='?'.http_build_query(['deck'=>$id]+($choosingCommander?['choose'=>1]:[])).'#explore';
$sortOptions=$choosingCommander
    ? ['popular'=>'Mais populares','relevance'=>'Disponibilidade e relevância','name'=>'Nome','newest'=>'Mais recentes','owned'=>'Mais cópias na coleção']
    : ['synergy'=>'Maior sinergia','relevance'=>'Disponibilidade e relevância','name'=>'Nome','newest'=>'Mais recentes','owned'=>'Mais cópias na coleção'];
?>
<section id="explore" class="section-block discovery-section <?= $choosingCommander?'commander-discovery':'' ?>">
<div class="discovery-heading">
    <div><h2><?= $choosingCommander?'Escolha sua comandante':'Explorar possibilidades' ?></h2><p><?= $choosingCommander?'As cartas elegíveis já estão prontas para escolha. Refine a lista somente se precisar.':'Pesquise todo o catálogo e combine os filtros. A sinergia é apenas uma forma de ordenar os mesmos resultados.' ?></p></div>
    <?php if(!$choosingCommander): ?><form method="post" class="discovery-sync"><?php $tokenFields('sync_edhrec'); ?><button class="secondary-link">Atualizar dados do EDHREC</button></form><?php endif; ?>
</div>
<?php if(!$choosingCommander): ?><details class="info-note synergy-definition"><summary>Como a sinergia é calculada?</summary><p><strong>Sinergia</strong> = (% da carta nos decks deste comandante) − (% da carta em todos os decks da mesma identidade de cor). Ela altera a ordem, sem desativar os outros filtros.</p><p>Quando a fonte retorna <em>lift</em>, ele é mostrado separadamente porque usa outra escala. Associação não é uma nota de força.</p></details><?php endif; ?>

<form class="builder-search builder-form unified-card-filters" method="get" action="/decks.php#explore">
    <input type="hidden" name="deck" value="<?= $id ?>"><?php if($choosingCommander): ?><input type="hidden" name="choose" value="1"><?php endif; ?>
    <div class="filter-primary-row">
        <label>Nome<input type="search" name="q" value="<?= h($q) ?>" placeholder="Nome da carta"></label>
        <label>Texto Oracle<input name="oracle" value="<?= h($oracle) ?>" placeholder="draw a card; sacrifice"></label>
        <label>Tipos e temas<input name="type" value="<?= h($type) ?>" placeholder="pirate; vehicle; treasure"></label>
        <label>Ordenar por<select name="sort" data-builder-sort><?php foreach($sortOptions as $value=>$label): ?><option value="<?= $value ?>" <?= $sort===$value?'selected':'' ?>><?= h($label) ?></option><?php endforeach; ?></select></label>
    </div>
    <div class="filter-quick-row">
        <label class="filter-availability">Disponibilidade<select name="availability"><option value="all" <?= $availability==='all'?'selected':'' ?>>Todas as cartas</option><option value="owned" <?= $availability==='owned'?'selected':'' ?>>Somente minha coleção</option><option value="missing" <?= $availability==='missing'?'selected':'' ?>>Fora da coleção</option></select></label>
        <?php if(!$choosingCommander): ?><label class="builder-check"><input type="checkbox" name="colors" value="1" <?= $colorsOnly?'checked':'' ?>> Somente identidade da comandante</label><?php endif; ?>
        <button class="primary-link">Aplicar filtros</button><a href="<?= h($clearUrl) ?>">Limpar</a>
    </div>
    <details class="filter-advanced" <?= $advancedOpen?'open':'' ?>><summary>Mais filtros</summary>
        <div class="filter-advanced-grid">
            <label>Combinação do Oracle<select name="match"><option value="all" <?= $match==='all'?'selected':'' ?>>Todos os termos</option><option value="any" <?= $match==='any'?'selected':'' ?>>Qualquer termo</option></select></label>
            <label>Raridade<select name="rarity"><option value="">Todas</option><option value="common" <?= $rarity==='common'?'selected':'' ?>>Comum</option><option value="uncommon" <?= $rarity==='uncommon'?'selected':'' ?>>Incomum</option><option value="rare" <?= $rarity==='rare'?'selected':'' ?>>Rara</option><option value="mythic" <?= $rarity==='mythic'?'selected':'' ?>>Mítica</option><option value="special" <?= $rarity==='special'?'selected':'' ?>>Especial</option></select></label>
            <label>Edição<select name="set"><option value="">Todas as edições</option><?php foreach($setOptions as $setOption): ?><option value="<?= h($setOption['set_code']) ?>" <?= $setFilter===$setOption['set_code']?'selected':'' ?>><?= h($setOption['set_name']) ?> (<?= h(strtoupper($setOption['set_code'])) ?>)</option><?php endforeach; ?></select></label>
            <label>Custo mínimo<input type="number" name="cmc_min" min="0" step="1" value="<?= $cmcMin===null?'':h((string)$cmcMin) ?>"></label>
            <label>Custo máximo<input type="number" name="cmc_max" min="0" step="1" value="<?= $cmcMax===null?'':h((string)$cmcMax) ?>"></label>
        </div>
        <?php if($choosingCommander): ?><fieldset class="commander-color-filter"><legend>Cores obrigatórias</legend><?php foreach(['W'=>'Branco','U'=>'Azul','B'=>'Preto','R'=>'Vermelho','G'=>'Verde','C'=>'Incolor'] as $color=>$label): ?><label><input type="checkbox" name="commander_colors[]" value="<?= $color ?>" <?= in_array($color,$commanderColors,true)?'checked':'' ?>><?= $label ?></label><?php endforeach; ?><small>A comandante deve possuir todas as cores marcadas. “Incolor” deve ser usado sozinho.</small></fieldset><?php endif; ?>
    </details>
</form>

<div class="results-heading"><strong><?= $choosingCommander?'Comandantes disponíveis':'Cartas encontradas' ?></strong><span><?= number_format($resultTotal,0,',','.') ?> resultados · Página <?= $page ?> de <?= $totalPages ?></span></div>
<?php if(!$results): ?><p class="empty-state">Nenhuma carta corresponde aos filtros. Remova um filtro ou use “qualquer termo”.</p><?php endif; ?>
<div class="builder-results"><?php foreach($results as $card): $already=$choosingCommander?null:($selectedByLogical[(string)($card['oracle_id']?:$card['id'])]??null); ?><article class="builder-result" id="result-<?= h($card['id']) ?>"><?php if($src=cardImageUrl($card)): ?><a href="/card.php?id=<?= h($card['id']) ?>"><img src="<?= h($src) ?>" alt="<?= h((string)($card['name']??'')) ?>" width="146" height="204" loading="lazy" decoding="async"></a><?php endif; ?><div><h3><?= deckHighlight((string)($card['name']??''),$highlightTerms) ?></h3><?php if(!$choosingCommander && $card['synergy_score']!==null): ?><span class="builder-synergy"><?= $card['synergy_metric']==='lift'?'Lift EDHREC: '.number_format((float)$card['synergy_score'],2,',','.'):'Sinergia EDHREC: '.sprintf('%+.0f',(float)$card['synergy_score']*100).'%' ?></span><?php endif; ?><?php if($already): ?><span class="builder-selection-status">Já está em <?= h(mb_strtolower(deckStageLabel($already['stage']))) ?></span><?php endif; ?><p class="builder-stock"><?= (int)($card['owned']??0)>0?'Você possui '.(int)$card['owned'].' cópia(s)':'Fora da coleção' ?></p><p class="muted"><?= deckHighlight((string)($card['type_line']??''),$highlightTerms) ?> · <?= manaSymbols($card['mana_cost']??null) ?></p><div class="builder-oracle"><p><?= nl2br(deckHighlight(deckText($card),$highlightTerms)) ?></p><?php foreach($terms as $term): if(stripos(deckText($card),$term)!==false): ?><small>Contém “<?= h($term) ?>” no Oracle.</small><?php endif; endforeach; ?></div><form method="post"><?php $tokenFields($choosingCommander?'commander':'add',$card['id']); $filterHidden(); ?><button class="secondary-link" <?= $already?'disabled':'' ?>><?= $choosingCommander?'Escolher comandante':($already?'Já adicionada · '.mb_strtolower(deckStageLabel($already['stage'])):'Adicionar às candidatas') ?></button></form></div></article><?php endforeach; ?></div>
<?php $pagerParams=$_GET;$pagerParams['deck']=$id;if($choosingCommander)$pagerParams['choose']=1;numberedPager($page,$totalPages,$pagerParams,'#explore'); ?>
</section>
