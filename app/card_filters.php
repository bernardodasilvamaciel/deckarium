<?php
declare(strict_types=1);

function cardFilters(): array {
    $f=[];
    foreach (['q','oracle','set','type','rarity','lang','mana','mv','mv_op','power','power_op','toughness','toughness_op','loyalty','loyalty_op','colors_mode','identity_mode'] as $key) {
        $f[$key]=is_string($_GET[$key]??null) ? substr(trim($_GET[$key]),0,300) : '';
    }
    foreach (['colors','identity'] as $key) $f[$key]=array_values(array_intersect(['W','U','B','R','G','C'],is_array($_GET[$key]??null)?$_GET[$key]:[]));
    return $f;
}
function cardFilterSql(array $f): array {
    $where=[];$params=[];
    $like=fn($s)=>'%'.str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$s).'%';
    $oracle="COALESCE(c.oracle_text,'') || ' ' || COALESCE((SELECT string_agg(face->>'oracle_text',' ') FROM jsonb_array_elements(c.card_faces) face),'')";
    if($f['q']!=='') { $where[]="(c.name ILIKE ? OR c.raw->>'printed_name' ILIKE ?)"; $params[]=$like($f['q']);$params[]=$like($f['q']); }
    foreach(['oracle'=>$oracle,'type'=>"COALESCE(c.type_line,'') || ' ' || ({$oracle})"] as $key=>$expr) {
        $terms=array_slice(array_filter(array_map('trim',explode(';',$f[$key]))),0,12);$parts=[];
        foreach($terms as $term){$parts[]="({$expr}) ILIKE ?";$params[]=$like($term);}
        if($parts)$where[]='('.implode($key==='type'?' OR ':' AND ',$parts).')';
    }
    foreach(['set'=>'set_code','rarity'=>'rarity','lang'=>'lang'] as $key=>$column) if($f[$key]!=='') { $where[]="c.{$column}=?";$params[]=strtolower($f[$key]); }
    if($f['mana']!==''){$where[]='c.mana_cost ILIKE ?';$params[]=$like($f['mana']);}
    foreach(['mv'=>'c.cmc','power'=>"c.raw->>'power'",'toughness'=>"c.raw->>'toughness'",'loyalty'=>"c.raw->>'loyalty'"] as $key=>$expr) {
        if($f[$key]==='' || !is_numeric($f[$key]) || !is_finite((float)$f[$key]))continue;
        $op=['eq'=>'=','min'=>'>=','max'=>'<='][$f[$key.'_op']]??'=';
        if($key!=='mv')$expr="CASE WHEN ({$expr}) ~ '^-?[0-9]+([.][0-9]+)?$' THEN ({$expr})::numeric END";
        $where[]="({$expr}) {$op} ?";$params[]=$f[$key];
    }
    foreach(['colors'=>'colors','identity'=>'color_identity'] as $key=>$column) {
        if(!$f[$key])continue;
        $selected=array_values(array_diff($f[$key],['C']));$mode=$f[$key.'_mode'];
        $expr="COALESCE(c.{$column},'[]'::jsonb)";
        if(!$selected){$where[]="{$expr}='[]'::jsonb";continue;}
        $value=json_encode($selected);
        if($mode==='exact'){$where[]="({$expr} @> ?::jsonb AND {$expr} <@ ?::jsonb)";$params[]=$value;$params[]=$value;}
        elseif($mode==='within'){$where[]="{$expr} <@ ?::jsonb";$params[]=$value;}
        elseif($mode==='any'){
            $parts=[];foreach($selected as $color){$parts[]="{$expr} @> ?::jsonb";$params[]=json_encode([$color]);}
            if(in_array('C',$f[$key],true))$parts[]="{$expr}='[]'::jsonb";
            $where[]='('.implode(' OR ',$parts).')';
        }else{$where[]="{$expr} @> ?::jsonb";$params[]=$value;}
    }
    return [$where?'WHERE '.implode(' AND ',$where):'', $params];
}
/**
 * Recolhe qualquer bloco de filtros atrás de um botão "Filtros".
 * Fechado por padrão em todas as telas: no celular os campos ocupavam
 * quase toda a altura antes dos resultados.
 */
function filterPanelStart(int $active = 0, string $label = '', string $extraHtml = ''): void {
    static $counter = 0;
    if ($label === '') $label = t('Filtros e busca');
    $id = 'filters-dialog-' . (++$counter);
    $resumo = $active
        ? ($active === 1 ? t(':count filtro ativo', ['count' => $active]) : t(':count filtros ativos', ['count' => $active]))
        : t('Nenhum filtro ativo');
    echo '<div class="filters-bar">'
        . '<button type="button" class="filters-open" data-dialog-open="' . $id . '">'
        . '<span class="filters-open-label">' . h($label) . '</span>'
        . '<span class="filters-open-count' . ($active ? ' is-active' : '') . '">' . h($resumo) . '</span>'
        . '</button>'
        . $extraHtml
        . '</div>'
        . '<dialog class="filters-dialog" id="' . $id . '" aria-label="' . h($label) . '">'
        . '<div class="filters-dialog-head"><h2>' . h($label) . '</h2>'
        . '<button type="button" class="filters-dialog-close" data-dialog-close aria-label="' . te('Fechar') . '">&times;</button></div>'
        . '<div class="filters-dialog-body">';
}

function filterPanelEnd(): void {
    echo '</div></dialog>';
}

/** Quantos filtros do formulário compartilhado estão preenchidos. */
function cardFilterActiveCount(array $f): int {
    $active=0;
    foreach ($f as $key=>$value) {
        if (str_ends_with($key,'_op') || str_ends_with($key,'_mode')) continue;
        if ($value!=='' && $value!==[]) $active++;
    }
    return $active;
}

function cardFilterForm(array $f,array $sets,string $action,string $view='',string $extraHtml=''): void {
    $advanced=false;foreach($f as $k=>$v)if(!in_array($k,['q','oracle'])&&$v!==''&&$v!==[])$advanced=true;
    filterPanelStart(cardFilterActiveCount($f), '', $extraHtml);
    ?><form class="card-filters" method="get" action="<?= h($action) ?>" role="search">
    <?php if($view): ?><input type="hidden" name="view" value="<?= h($view) ?>"><?php endif; ?>
    <div class="filter-main"><label class="field"><?= te('Nome da carta') ?><input type="search" name="q" value="<?= h($f['q']) ?>" placeholder="<?= te('Nome em inglês ou português') ?>"></label><label class="field"><?= te('Texto Oracle') ?><input name="oracle" value="<?= h($f['oracle']) ?>" placeholder="draw a card; sacrifice"></label><button><?= te('Buscar cartas') ?></button></div>
    <details open><summary><?= te('Filtros avançados') ?></summary><div class="filter-grid">
    <label class="field"><?= te('Edição') ?><select name="set"><option value=""><?= te('Todas as edições') ?></option><?php foreach($sets as $set): ?><option value="<?= h($set['set_code']) ?>" <?= $f['set']===$set['set_code']?'selected':'' ?>><?= h($set['set_name'].' · '.strtoupper($set['set_code'])) ?></option><?php endforeach; ?></select></label>
    <label class="field"><?= te('Tipos e temas') ?><input name="type" value="<?= h($f['type']) ?>" placeholder="pirate; assassin; vehicle; treasure"><small><?= te('Qualquer termo no tipo ou Oracle.') ?></small></label>
    <?php foreach(['colors'=>t('Cores da carta'),'identity'=>t('Identidade de cor')] as $key=>$label): ?><fieldset><legend><?= $label ?></legend><div class="filter-colors"><?php foreach(['W'=>t('Branco'),'U'=>t('Azul'),'B'=>t('Preto'),'R'=>t('Vermelho'),'G'=>t('Verde'),'C'=>t('Incolor')] as $color=>$name): ?><label title="<?= $name ?>"><input type="checkbox" name="<?= $key ?>[]" value="<?= $color ?>" <?= in_array($color,$f[$key],true)?'checked':'' ?> aria-label="<?= $name ?>"><?= manaSymbols('{'.$color.'}') ?></label><?php endforeach; ?></div><select name="<?= $key ?>_mode" aria-label="Combinação: <?= $label ?>"><?php foreach(['all'=>t('Contém todas as cores'),'any'=>t('Contém qualquer cor'),'exact'=>t('Exatamente as cores'),'within'=>t('Somente essas cores e incolores')] as $value=>$text): ?><option value="<?= $value ?>" <?= $f[$key.'_mode']===$value?'selected':'' ?>><?= $text ?></option><?php endforeach; ?></select><small><?= te('Incolor sozinho busca cartas sem cores.') ?></small></fieldset><?php endforeach; ?>
    <?php foreach(['mv'=>t('Valor de mana'),'power'=>t('Poder'),'toughness'=>t('Resistência'),'loyalty'=>t('Lealdade')] as $key=>$label): ?><label class="field"><?= $label ?><span class="filter-number"><select name="<?= $key ?>_op" aria-label="Comparação de <?= $label ?>"><?php foreach(['eq'=>t('Igual a'),'min'=>t('Pelo menos'),'max'=>t('No máximo')] as $value=>$text): ?><option value="<?= $value ?>" <?= $f[$key.'_op']===$value?'selected':'' ?>><?= $text ?></option><?php endforeach; ?></select><input type="number" step="any" name="<?= $key ?>" value="<?= h($f[$key]) ?>" aria-label="<?= $label ?>"></span></label><?php endforeach; ?>
    <label class="field"><?= te('Custo de mana') ?><input name="mana" value="<?= h($f['mana']) ?>" placeholder="{2}{G}{G}"><small><?= te('Sequência de símbolos no custo.') ?></small></label>
    <?php foreach(['rarity'=>[t('Raridade'),['common'=>t('Comum'),'uncommon'=>t('Incomum'),'rare'=>t('Rara'),'mythic'=>t('Mítica'),'special'=>t('Especial'),'bonus'=>t('Bônus')]], 'lang'=>[t('Idioma'),['en'=>t('Inglês'),'pt'=>t('Português'),'es'=>t('Espanhol'),'fr'=>t('Francês'),'de'=>t('Alemão'),'it'=>t('Italiano'),'ja'=>t('Japonês'),'ko'=>t('Coreano'),'ru'=>t('Russo'),'zhs'=>t('Chinês simplificado'),'zht'=>t('Chinês tradicional'),'la'=>t('Latim'),'grc'=>t('Grego antigo'),'ar'=>t('Árabe'),'he'=>t('Hebraico'),'sa'=>t('Sânscrito'),'ph'=>t('Phyrexiano')]]] as $key=>[$label,$options]): ?><label class="field"><?= $label ?><select name="<?= $key ?>"><option value=""><?= te('Todos') ?></option><?php foreach($options as $value=>$text): ?><option value="<?= $value ?>" <?= $f[$key]===$value?'selected':'' ?>><?= $text ?></option><?php endforeach; ?></select></label><?php endforeach; ?>
    </div><p class="muted"><?= te('Oracle: todos os termos separados por ponto e vírgula. Poder, resistência e lealdade: apenas valores numéricos.') ?></p><button><?= te('Aplicar filtros') ?></button></details><a href="<?= h($action) ?>"><?= te('Limpar filtros') ?></a></form><?php
    filterPanelEnd();
}
function numberedPager(int $page,int $pages,array $params,string $anchor=''): void {
    unset($params['page']);$url=fn($n)=>'?'.http_build_query(array_merge($params,['page'=>$n])).$anchor;
    $numbers=array_unique(array_merge([1,$pages],range(max(1,$page-2),min($pages,$page+2))));sort($numbers);
    ?><nav class="pager numbered-pager" aria-label="<?= te('Paginação') ?>"><span><?= te('Página :page de :pages', ['page' => $page, 'pages' => $pages]) ?></span><?php if($page>1): ?><a href="<?= h($url($page-1)) ?>"><?= te('Anterior') ?></a><?php endif; ?><?php $last=0;foreach($numbers as $n): if($last&&$n>$last+1): ?><span aria-hidden="true">…</span><?php endif; ?><a href="<?= h($url($n)) ?>" aria-label="<?= te('Página :page de :pages', ['page' => $n, 'pages' => $pages]) ?>" <?= $n===$page?'aria-current="page"':'' ?>><?= $n ?></a><?php $last=$n;endforeach; ?><?php if($page<$pages): ?><a href="<?= h($url($page+1)) ?>"><?= te('Próxima') ?></a><?php endif; ?><form method="get"><?php foreach($params as $key=>$value): foreach(is_array($value)?$value:[$value] as $v): ?><input type="hidden" name="<?= h($key.(is_array($value)?'[]':'')) ?>" value="<?= h($v) ?>"><?php endforeach;endforeach; ?><label><?= te('Ir para') ?> <input type="number" name="page" min="1" max="<?= $pages ?>" value="<?= $page ?>" aria-label="<?= te('Ir para a página') ?>"></label><button><?= te('Ir') ?></button></form></nav><?php
}
