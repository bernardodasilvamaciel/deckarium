<?php
// Mapa de jogo: cada carta numa matriz função × valor de mana (quando entra em jogo × o que faz).
// Pilhas como na mesa; linhas e colunas mostram contagem e meta, e a faixa de baixo mostra a base de mana.
$mapColumns = ['1'=>['0–1','Turno 1'],'2'=>['2','Turno 2'],'3'=>['3','Turno 3'],'4'=>['4','Turno 4'],'5'=>['5','Turno 5'],'6'=>['6','Turno 6'],'7'=>['7+','Turno 7+']];
$mapLanes = [
    'commander'=>['Comandante','Sempre disponível na zona de comando'],
    'ramp'=>['Ramp','Acelera a mana'],
    'draw'=>['Compra','Mantém a mão cheia'],
    'removal'=>['Remoção pontual','Responde a uma ameaça'],
    'wipe'=>['Remoção em massa','Reinicia a mesa'],
    'protection'=>['Proteção','Segura suas peças'],
    'recursion'=>['Recursão','Traz de volta do cemitério'],
    'tutor'=>['Tutores','Busca a peça certa'],
    'plan'=>['Plano de jogo','Ameaças e peças da estratégia'],
];
$mapRoleWords = ['wipe'=>'/massa|wipe|board ?wipe/i','ramp'=>'/ramp|mana|acelera/i','draw'=>'/compra|draw|vantagem/i','removal'=>'/remo|removal|intera/i','protection'=>'/prote/i','recursion'=>'/recurs|reanima/i','tutor'=>'/tutor|busca/i','plan'=>'/plano|amea|win|finaliz|sinergia/i'];
$mapLaneOf = function(array $entry, bool $isCommander) use($mapRoleWords): string {
    if ($isCommander) return 'commander';
    $role = trim((string)($entry['role'] ?? ''));
    if ($role !== '' && $role !== DECK_AUTO_LAND_ROLE) foreach ($mapRoleWords as $lane => $pattern) if (preg_match($pattern, $role)) return $lane;
    $profileRoles = deckScoreProfile($entry)['roles'];
    foreach (['ramp','removal','wipe','draw','protection','recursion','tutor'] as $lane) if (isset($profileRoles[$lane])) return $lane;
    return 'plan';
};
$mapCells = []; $mapLands = []; $mapLaneCounts = array_fill_keys(array_keys($mapLanes),0); $mapColumnCounts = array_fill_keys(array_keys($mapColumns),0);
foreach ($groups as $category=>$entries) foreach ($entries as $entry) {
    $isCommander = $category==='Comandante';
    if (!$isCommander && str_contains(explode(' // ',(string)$entry['type_line'])[0],'Land')) { $mapLands[] = $entry; continue; }
    $lane = $mapLaneOf($entry, $isCommander);
    $column = (string)max(1, min(7, (int)floor((float)($entry['cmc'] ?? 0))));
    $mapCells[$lane][$column][] = $entry;
    $quantity = (int)($entry['quantity'] ?? 1);
    $mapLaneCounts[$lane] += $quantity;
    if (!$isCommander) $mapColumnCounts[$column] += $quantity;
}
$mapShowTargets = $selectionStage==='deck' && $commander;
$mapTargets = $mapShowTargets ? ($scoreConfig['targets'] ?? []) : [];
$mapCurveTargets = $mapShowTargets ? ($scoreConfig['curve'] ?? []) : [];
$mapColors = $identity ? array_values(array_intersect(['W','U','B','R','G'],$identity)) : ['C'];
$mapSources = array_fill_keys($mapColors,0); $mapLandTotal = 0;
foreach ($mapLands as $land) { $mapLandTotal += (int)$land['quantity']; foreach (deckLandColors($land,$mapColors) as $color) $mapSources[$color] += (int)$land['quantity']; }
$mapCard = function(array $entry, bool $isCommander, int $index) use($selectionStage): void {
    $src = cardImageUrl($entry,'front','normal'); $name = (string)$entry['name']; $quantity=(int)($entry['quantity']??1);
    $attributes = $isCommander ? 'href="/card.php?id='.h($entry['id']).'"' : 'type="button" data-selection-open="selection-card-'.h($entry['id']).'" data-map-card="'.h($entry['id']).'" aria-haspopup="dialog"';
    $tag = $isCommander ? 'a' : 'button'; ?>
    <<?= $tag ?> class="map-card<?= $isCommander?' is-commander':'' ?>" <?= $attributes ?> style="--i:<?= $index ?>" title="<?= h($name.' · '.($entry['type_line']??'')) ?>" aria-label="<?= h(($isCommander?'Comandante: ':'Abrir ').$name) ?>">
        <?php if($src): ?><img src="<?= h($src) ?>" alt="" loading="lazy" width="146" height="204"><?php else: ?><span class="map-card-fallback"><?= h($name) ?></span><?php endif; ?>
        <?php if($quantity>1): ?><b class="map-card-qty"><?= $quantity ?>×</b><?php endif; ?>
    </<?= $tag ?>>
<?php };
?>
<div class="play-map" data-layout-panel="map" hidden>
    <div class="play-map-intro">
        <p><strong>Mapa de jogo.</strong> Cada linha é o que a carta faz; cada coluna, quando ela chega à mesa. Lacunas aparecem sozinhas: uma linha vazia no começo da partida é uma resposta que falta cedo.</p>
        <?php if($mapShowTargets): ?><p class="muted">Números: cartas / meta da comandante. Funções vêm do que você escreveu em “Função” ou da leitura do texto Oracle.</p><?php endif; ?>
    </div>
    <div class="play-map-scroll" tabindex="0" aria-label="Mapa de jogo: role para os lados em telas estreitas">
    <div class="play-map-grid" style="--map-columns:<?= count($mapColumns) ?>">
        <div class="play-map-corner"><span>Função</span><span>Valor de mana →</span></div>
        <?php foreach($mapColumns as $column=>[$label,$turn]): $curveTarget=$mapCurveTargets[$column]??null; $columnCount=$mapColumnCounts[$column]; ?>
        <div class="play-map-colhead<?= $curveTarget!==null && $columnCount<$curveTarget-2 ? ' is-short' : '' ?>"><strong><?= h($label) ?></strong><small><?= h($turn) ?></small><span><?= $columnCount ?><?= $curveTarget!==null ? '<i>/'.(int)$curveTarget.'</i>' : '' ?></span></div>
        <?php endforeach; ?>
        <?php foreach($mapLanes as $lane=>[$laneLabel,$laneHint]): if($lane==='commander' && empty($mapCells['commander'])) continue; $laneTarget=$lane==='plan'||$lane==='commander'?null:($mapTargets[$lane]??null); $laneCount=$mapLaneCounts[$lane]; ?>
        <div class="play-map-lane lane-<?= h($lane) ?><?= $laneTarget!==null && $laneCount<$laneTarget ? ' is-short' : '' ?>">
            <strong><?= h($laneLabel) ?></strong><small><?= h($laneHint) ?></small>
            <span class="play-map-lane-count"><?= $laneCount ?><?= $laneTarget!==null ? '<i>/'.(int)$laneTarget.'</i>' : '' ?><?php if($laneTarget!==null && $laneCount<$laneTarget): ?> <em>faltam <?= $laneTarget-$laneCount ?></em><?php endif; ?></span>
            <?php if($laneTarget): ?><span class="play-map-meter" aria-hidden="true"><i style="width:<?= min(100,(int)round($laneCount/$laneTarget*100)) ?>%"></i></span><?php endif; ?>
        </div>
        <?php foreach($mapColumns as $column=>$_): $pile=$mapCells[$lane][$column]??[]; ?>
        <div class="play-map-cell lane-<?= h($lane) ?>"<?= $pile ? '' : ' data-empty' ?>>
            <?php if($pile): ?><div class="map-pile" style="--n:<?= count($pile) ?>"><?php foreach($pile as $index=>$entry) $mapCard($entry,$lane==='commander',$index); ?></div><?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php endforeach; ?>
    </div>
    </div>
    <section class="play-map-lands" aria-label="Base de mana">
        <div class="play-map-lands-head">
            <div><strong>Base de mana</strong><small><?= $mapLandTotal ?> terreno<?= $mapLandTotal===1?'':'s' ?><?= $mapShowTargets && isset($mapTargets['lands']) ? ' · meta '.(int)$mapTargets['lands'] : '' ?></small></div>
            <div class="play-map-sources">
            <?php foreach($mapColors as $color): $colorPips=$color==='C'?0:(int)($pipCounts[$color]??0); ?>
                <span title="<?= (int)$mapSources[$color] ?> terrenos geram esta cor · <?= $colorPips ?> símbolos nos custos"><img class="mana-symbol" src="https://svgs.scryfall.io/card-symbols/<?= h($color) ?>.svg" alt="<?= h($color) ?>" width="18" height="18"><b><?= (int)$mapSources[$color] ?></b><small>fontes<?= $color==='C'?'':' · '.$colorPips.' símb.' ?></small></span>
            <?php endforeach; ?>
            </div>
        </div>
        <?php if($mapLands): ?>
        <div class="map-fan" style="--n:<?= count($mapLands) ?>"><?php foreach($mapLands as $index=>$land) $mapCard($land,false,$index); ?></div>
        <?php else: ?><p class="muted">Nenhum terreno nesta etapa.<?php if($selectionStage==='deck' && $commander): ?> Use “Completar com terrenos” para montar a base a partir da coleção.<?php endif; ?></p><?php endif; ?>
    </section>
</div>
