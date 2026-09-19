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
function filterPanelStart(int $active=0, string $label='Filtros e busca'): void {
    echo '<details class="filters-shell"><summary><span class="filters-shell-label">' . h($label) . '</span>'
        . '<span class="filters-shell-count">' . ($active ? $active . ($active === 1 ? ' filtro ativo' : ' filtros ativos') : 'Nenhum filtro ativo') . '</span></summary>'
        . '<div class="filters-shell-body">';
}

function filterPanelEnd(): void {
    echo '</div></details>';
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

function cardFilterForm(array $f,array $sets,string $action,string $view=''): void {
    $advanced=false;foreach($f as $k=>$v)if(!in_array($k,['q','oracle'])&&$v!==''&&$v!==[])$advanced=true;
    filterPanelStart(cardFilterActiveCount($f));
    ?><form class="card-filters" method="get" action="<?= h($action) ?>" role="search">
    <?php if($view): ?><input type="hidden" name="view" value="<?= h($view) ?>"><?php endif; ?>
    <div class="filter-main"><label class="field">Nome da carta<input type="search" name="q" value="<?= h($f['q']) ?>" placeholder="Nome em inglês ou português"></label><label class="field">Texto Oracle<input name="oracle" value="<?= h($f['oracle']) ?>" placeholder="draw a card; sacrifice"></label><button>Buscar cartas</button></div>
    <details <?= $advanced?'open':'' ?>><summary>Filtros avançados</summary><div class="filter-grid">
    <label class="field">Edição<select name="set"><option value="">Todas as edições</option><?php foreach($sets as $set): ?><option value="<?= h($set['set_code']) ?>" <?= $f['set']===$set['set_code']?'selected':'' ?>><?= h($set['set_name'].' · '.strtoupper($set['set_code'])) ?></option><?php endforeach; ?></select></label>
    <label class="field">Tipos e temas<input name="type" value="<?= h($f['type']) ?>" placeholder="pirate; assassin; vehicle; treasure"><small>Qualquer termo no tipo ou Oracle.</small></label>
    <?php foreach(['colors'=>'Cores da carta','identity'=>'Identidade de cor'] as $key=>$label): ?><fieldset><legend><?= $label ?></legend><div class="filter-colors"><?php foreach(['W'=>'Branco','U'=>'Azul','B'=>'Preto','R'=>'Vermelho','G'=>'Verde','C'=>'Incolor'] as $color=>$name): ?><label title="<?= $name ?>"><input type="checkbox" name="<?= $key ?>[]" value="<?= $color ?>" <?= in_array($color,$f[$key],true)?'checked':'' ?> aria-label="<?= $name ?>"><?= manaSymbols('{'.$color.'}') ?></label><?php endforeach; ?></div><select name="<?= $key ?>_mode" aria-label="Combinação: <?= $label ?>"><?php foreach(['all'=>'Contém todas as cores','any'=>'Contém qualquer cor','exact'=>'Exatamente as cores','within'=>'Somente essas cores e incolores'] as $value=>$text): ?><option value="<?= $value ?>" <?= $f[$key.'_mode']===$value?'selected':'' ?>><?= $text ?></option><?php endforeach; ?></select><small>Incolor sozinho busca cartas sem cores.</small></fieldset><?php endforeach; ?>
    <?php foreach(['mv'=>'Valor de mana','power'=>'Poder','toughness'=>'Resistência','loyalty'=>'Lealdade'] as $key=>$label): ?><label class="field"><?= $label ?><span class="filter-number"><select name="<?= $key ?>_op" aria-label="Comparação de <?= $label ?>"><?php foreach(['eq'=>'Igual a','min'=>'Pelo menos','max'=>'No máximo'] as $value=>$text): ?><option value="<?= $value ?>" <?= $f[$key.'_op']===$value?'selected':'' ?>><?= $text ?></option><?php endforeach; ?></select><input type="number" step="any" name="<?= $key ?>" value="<?= h($f[$key]) ?>" aria-label="<?= $label ?>"></span></label><?php endforeach; ?>
    <label class="field">Custo de mana<input name="mana" value="<?= h($f['mana']) ?>" placeholder="{2}{G}{G}"><small>Sequência de símbolos no custo.</small></label>
    <?php foreach(['rarity'=>['Raridade',['common'=>'Comum','uncommon'=>'Incomum','rare'=>'Rara','mythic'=>'Mítica','special'=>'Especial','bonus'=>'Bônus']], 'lang'=>['Idioma',['en'=>'Inglês','pt'=>'Português','es'=>'Espanhol','fr'=>'Francês','de'=>'Alemão','it'=>'Italiano','ja'=>'Japonês','ko'=>'Coreano','ru'=>'Russo','zhs'=>'Chinês simplificado','zht'=>'Chinês tradicional','la'=>'Latim','grc'=>'Grego antigo','ar'=>'Árabe','he'=>'Hebraico','sa'=>'Sânscrito','ph'=>'Phyrexiano']]] as $key=>[$label,$options]): ?><label class="field"><?= $label ?><select name="<?= $key ?>"><option value="">Todos</option><?php foreach($options as $value=>$text): ?><option value="<?= $value ?>" <?= $f[$key]===$value?'selected':'' ?>><?= $text ?></option><?php endforeach; ?></select></label><?php endforeach; ?>
    </div><p class="muted">Oracle: todos os termos separados por ponto e vírgula. Poder, resistência e lealdade: apenas valores numéricos.</p><button>Aplicar filtros</button></details><a href="<?= h($action) ?>">Limpar filtros</a></form><?php
    filterPanelEnd();
}
function numberedPager(int $page,int $pages,array $params,string $anchor=''): void {
    unset($params['page']);$url=fn($n)=>'?'.http_build_query(array_merge($params,['page'=>$n])).$anchor;
    $numbers=array_unique(array_merge([1,$pages],range(max(1,$page-2),min($pages,$page+2))));sort($numbers);
    ?><nav class="pager numbered-pager" aria-label="Paginação"><span>Página <?= $page ?> de <?= $pages ?></span><?php if($page>1): ?><a href="<?= h($url($page-1)) ?>">Anterior</a><?php endif; ?><?php $last=0;foreach($numbers as $n): if($last&&$n>$last+1): ?><span aria-hidden="true">…</span><?php endif; ?><a href="<?= h($url($n)) ?>" aria-label="Página <?= $n ?>" <?= $n===$page?'aria-current="page"':'' ?>><?= $n ?></a><?php $last=$n;endforeach; ?><?php if($page<$pages): ?><a href="<?= h($url($page+1)) ?>">Próxima</a><?php endif; ?><form method="get"><?php foreach($params as $key=>$value): foreach(is_array($value)?$value:[$value] as $v): ?><input type="hidden" name="<?= h($key.(is_array($value)?'[]':'')) ?>" value="<?= h($v) ?>"><?php endforeach;endforeach; ?><label>Ir para <input type="number" name="page" min="1" max="<?= $pages ?>" value="<?= $page ?>" aria-label="Ir para página"></label><button>Ir</button></form></nav><?php
}
