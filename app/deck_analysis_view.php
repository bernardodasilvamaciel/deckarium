<?php
// Análise do deck: exibida em Minha seleção › No deck, sobre as cartas aprovadas e a comandante.
$openSlots = max(0, 100 - $finalCount);
?>
<section id="deck-analysis" class="deck-analysis" aria-labelledby="deck-analysis-title">
<div class="section-heading"><div><h2 id="deck-analysis-title">Análise do deck</h2><p class="muted">Considera só as cartas aprovadas no deck e a comandante. Candidatas não entram.</p></div></div>

<dl class="deck-analysis-summary">
    <div><dt>Cartas</dt><dd><?= $finalCount ?><small>/100</small></dd><span><?= $finalCount > 100 ? ($finalCount - 100).' acima da referência' : ($openSlots ? $openSlots.' '.($openSlots === 1 ? 'espaço restante' : 'espaços restantes') : 'Lista completa') ?></span></div>
    <div><dt>Terrenos</dt><dd><?= $landCount ?></dd><span><?= $recommendedLandTotal !== null ? 'Referência: ~'.$recommendedLandTotal : 'Entre as cartas aprovadas' ?></span></div>
    <div><dt>Valor estimado</dt><dd>R$ <?= number_format($deckPriceTotal, 2, ',', '.') ?></dd><span><?= $deckUnpriced ? $deckUnpriced.' sem cotação' : 'Todas com cotação' ?></span></div>
    <div><dt>Símbolos de mana</dt><dd><?= $manaTotal ?></dd><span><?= h(implode(' · ', array_map(fn($c) => $c.' '.$pipCounts[$c], array_keys(array_filter($pipCounts))))) ?: 'Nenhum símbolo colorido' ?></span></div>
</dl>

<?php if($warnings): ?>
<details class="deck-analysis-warnings" open>
    <summary><?= count($warnings) ?> <?= count($warnings) === 1 ? 'alerta' : 'alertas' ?> na lista</summary>
    <?php foreach($warnings as $warning): ?><p class="notice warning"><?= h($warning) ?></p><?php endforeach; ?>
    <p class="muted">Alertas básicos, não uma validação completa de legalidade ou força do deck.</p>
</details>
<?php endif; ?>

<?php if($commander && !$choosingCommander): ?>
<div class="analysis-grid">
    <div class="panel"><h3>Curva de mana</h3><div class="mana-curve" aria-label="Curva de mana"><?php for($cost=0;$cost<=10;$cost++): $count=(int)($curveCounts[$cost]??0); ?><button type="button" class="mana-column" data-mana-cost="<?= $cost ?>" aria-controls="mana-list-<?= $cost ?>" aria-expanded="false"><span><?= $count ?></span><i class="mana-bar" style="height:<?= $maxCurve?max(4,round($count/$maxCurve*110)):4 ?>px"></i><small><?= $cost===10?'10+':$cost ?></small></button><?php endfor; ?></div><?php for($cost=0;$cost<=10;$cost++): ?><div class="mana-card-list" id="mana-list-<?= $cost ?>" data-mana-list="<?= $cost ?>" hidden><h4>Cartas de custo <?= $cost===10?'10 ou mais':$cost ?></h4><?php if(!$curveCards[$cost]): ?><p class="muted">Nenhuma carta final nesse valor.</p><?php else: foreach($curveCards[$cost] as $curveCard): ?><a class="mana-card-item" href="/card.php?id=<?= h($curveCard['id']) ?>"><?php if($src=cardImageUrl($curveCard,'front','small')): ?><img src="<?= h($src) ?>" alt="" loading="lazy"><?php endif; ?><span><strong><?= (int)($curveCard['quantity']??1) ?>× <?= h($curveCard['name']) ?></strong><small><?= h($curveCard['type_line']??'') ?></small></span></a><?php endforeach; endif; ?></div><?php endfor; ?><p class="muted">Cartas não-terreno aprovadas. Clique em uma barra para ver as cartas daquele valor. Upgrades planejados não entram até serem confirmados.</p></div>
    <div class="panel"><h3>Cores dos custos</h3><div class="mana-distribution"><?php foreach($pipCounts as $color=>$count): $percent=$manaTotal?round($count/$manaTotal*100):0; ?><div><div class="mana-label"><strong><?= $color ?></strong><span><?= $percent ?>% · <?= $count ?> símbolos</span></div><div class="mana-track"><i class="mana-fill mana-<?= $color ?>" style="width:<?= $percent ?>%"></i></div></div><?php endforeach; ?></div><p class="muted">Símbolos nos custos das cartas aprovadas e da comandante; híbridos contam nas duas cores. Não é uma recomendação de terrenos: custos alternativos, faces e aceleração exigem avaliação.</p></div>
</div>
<?php endif; ?>

<div class="analysis-grid">
    <div class="panel"><h3>Funções informadas</h3>
        <?php if($roles): arsort($roles); ?><ul class="deck-analysis-roles"><?php foreach($roles as $role=>$count): ?><li><span><?= h($role) ?></span><b><?= $count ?></b></li><?php endforeach; ?></ul>
        <?php else: ?><p class="muted">Nenhuma carta aprovada tem função informada. Abra uma carta e preencha “Função” para acompanhar a divisão aqui.</p><?php endif; ?>
        <p class="muted">Conta a classificação que você escreveu em cada carta aprovada. Para metas calculadas, veja <a href="?deck=<?= $id ?>&amp;view=needs">O que falta</a>.</p>
    </div>
    <?php if($recommendedLandTotal!==null): ?>
    <div class="panel land-recommendation-panel"><h3>Sugestão inicial de terrenos</h3><p>Uma base de aproximadamente <strong><?= $recommendedLandTotal ?> terrenos</strong>, dividida pela proporção de símbolos coloridos das cartas aprovadas.</p><div class="land-recommendation-grid"><?php foreach($landRecommendation as $color=>$amount): ?><span><strong><?= h($colorNames[$color]) ?></strong><b><?= $amount ?></b></span><?php endforeach; ?></div><p class="muted">Recomendação estatística, não uma alteração automática do deck. Revise conforme curva, ramp e terrenos não básicos.</p></div>
    <?php else: ?>
    <div class="panel"><h3>Disponibilidade das cartas</h3><div class="inventory-legend"><span><i class="inventory-dot is-available"></i> Disponível na coleção</span><span><i class="inventory-dot is-limited"></i> Quantidade limitada</span><span><i class="inventory-dot is-reserved"></i> Usada em outros decks</span></div><p class="muted">Os indicadores sobre cada carta consideram todas as impressões dela. Uma cópia física só pode estar em um deck por vez; candidatas não reservam cópias.</p></div>
    <?php endif; ?>
</div>

<div class="deck-analysis-export">
    <h3>Exportar</h3>
    <span class="deck-export-links"><a href="?deck=<?= $id ?>&export=deck">Lista em texto</a><a href="?deck=<?= $id ?>&export=json" title="Deck, candidatas e comandante com todos os dados de cada carta: Scryfall completo, coleção, preços, notas e Índice de Encaixe">JSON completo</a></span>
    <form method="get" class="liga-export"><input type="hidden" name="deck" value="<?= $id ?>"><input type="hidden" name="export" value="liga"><label>CSV para a Liga<select name="liga_scope"><option value="missing">Somente cartas que faltam</option><option value="all">Deck completo, inclusive minha coleção</option></select></label><button class="secondary-link">Baixar CSV</button></form>
</div>
</section>
