<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

function deckSchema(): void {
    db()->exec("ALTER TABLE cards ADD COLUMN IF NOT EXISTS edhrec_rank_cached int GENERATED ALWAYS AS (
            CASE WHEN COALESCE(raw->>'edhrec_rank','') ~ '^[0-9]+$' THEN (raw->>'edhrec_rank')::int END
        ) STORED;
        ALTER TABLE cards ADD COLUMN IF NOT EXISTS commander_eligible boolean GENERATED ALWAYS AS (
            ((COALESCE(raw->'card_faces'->0->>'type_line',type_line,'') LIKE '%Legendary%'
                AND (COALESCE(raw->'card_faces'->0->>'type_line',type_line,'') LIKE '%Creature%'
                    OR ((COALESCE(raw->'card_faces'->0->>'type_line',type_line,'') LIKE '%Vehicle%'
                        OR COALESCE(raw->'card_faces'->0->>'type_line',type_line,'') LIKE '%Spacecraft%')
                        AND COALESCE(raw->'card_faces'->0->>'power',raw->>'power') IS NOT NULL
                        AND COALESCE(raw->'card_faces'->0->>'toughness',raw->>'toughness') IS NOT NULL)))
             OR COALESCE(raw->'card_faces'->0->>'oracle_text',oracle_text,'') ILIKE '%can be your commander%')
        ) STORED");
    db()->exec("CREATE TABLE IF NOT EXISTS builder_decks (
        id bigserial PRIMARY KEY, name text NOT NULL, commander_id uuid REFERENCES cards(id),
        strategy text NOT NULL DEFAULT '', terms text NOT NULL DEFAULT '', status text NOT NULL DEFAULT 'planning', created_at timestamptz DEFAULT now());
        CREATE TABLE IF NOT EXISTS builder_items (
        deck_id bigint REFERENCES builder_decks(id) ON DELETE CASCADE, card_id uuid REFERENCES cards(id),
        stage text NOT NULL CHECK(stage IN ('candidate','review','deck')), quantity int NOT NULL CHECK(quantity > 0),
        role text NOT NULL DEFAULT '', notes text NOT NULL DEFAULT '', PRIMARY KEY(deck_id,card_id));
        CREATE TABLE IF NOT EXISTS builder_collection (
        scryfall_id uuid NOT NULL, name text NOT NULL, quantity int NOT NULL CHECK(quantity > 0),
        foil boolean NOT NULL DEFAULT false, PRIMARY KEY(scryfall_id,foil));
        ALTER TABLE builder_collection ADD COLUMN IF NOT EXISTS foil boolean NOT NULL DEFAULT false;
        ALTER TABLE builder_decks ADD COLUMN IF NOT EXISTS status text NOT NULL DEFAULT 'planning';
        CREATE TABLE IF NOT EXISTS deck_upgrades (
            id bigserial PRIMARY KEY, deck_id bigint NOT NULL REFERENCES builder_decks(id) ON DELETE CASCADE,
            remove_card_id uuid NOT NULL REFERENCES cards(id), add_card_id uuid NOT NULL REFERENCES cards(id),
            reason text NOT NULL DEFAULT '', status text NOT NULL DEFAULT 'planned', created_at timestamptz NOT NULL DEFAULT now(),
            CHECK (status IN ('planned','done')));
        CREATE INDEX IF NOT EXISTS deck_upgrades_deck_idx ON deck_upgrades(deck_id, status, created_at DESC);
        CREATE TABLE IF NOT EXISTS deck_synergy (
            commander_id uuid NOT NULL REFERENCES cards(id) ON DELETE CASCADE, card_id uuid NOT NULL REFERENCES cards(id) ON DELETE CASCADE,
            metric text NOT NULL DEFAULT 'synergy', score numeric NOT NULL, inclusion numeric NULL, deck_count int NULL,
            source_url text NOT NULL, synced_at timestamptz NOT NULL DEFAULT now(), PRIMARY KEY(commander_id,card_id));");
    db()->exec("DO \$\$ BEGIN
        IF EXISTS (SELECT 1 FROM pg_constraint WHERE conrelid='builder_collection'::regclass AND conname='builder_collection_pkey' AND pg_get_constraintdef(oid)<>'PRIMARY KEY (scryfall_id, foil)') THEN
            ALTER TABLE builder_collection DROP CONSTRAINT builder_collection_pkey;
            ALTER TABLE builder_collection ADD PRIMARY KEY(scryfall_id,foil);
        END IF;
    END \$\$");
    db()->exec("CREATE INDEX IF NOT EXISTS builder_items_deck_stage_idx ON builder_items(deck_id,stage);
        CREATE INDEX IF NOT EXISTS builder_items_card_idx ON builder_items(card_id);
        CREATE INDEX IF NOT EXISTS deck_synergy_commander_score_idx ON deck_synergy(commander_id,score DESC);
        CREATE INDEX IF NOT EXISTS cards_commander_picker_idx ON cards(edhrec_rank_cached,lower(name),id) WHERE commander_eligible;
        CREATE INDEX IF NOT EXISTS cards_color_identity_gin_idx ON cards USING gin(color_identity);");
}

function deckCheapestPriceSql(string $alias='c'): string {
    $usd=sprintf('%.6F',(float)(getenv('USD_BRL_RATE')?:5.5));
    $eur=sprintf('%.6F',(float)(getenv('EUR_BRL_RATE')?:6.0));
    $normal="COALESCE(NULLIF({$alias}.prices->>'usd','')::numeric*{$usd},NULLIF({$alias}.prices->>'eur','')::numeric*{$eur})";
    $foil="COALESCE(NULLIF({$alias}.prices->>'usd_foil','')::numeric*{$usd},NULLIF({$alias}.prices->>'eur_foil','')::numeric*{$eur})";
    return "NULLIF(LEAST(COALESCE({$normal},1e18),COALESCE({$foil},1e18)),1e18)";
}

function deckFindPrinting(string $name): ?array {
    $name = trim(preg_replace('/\s+\([A-Z0-9]{2,8}\)\s+[^\s]+(?:\s+\*F\*)?$/i', '', $name) ?? $name);
    return deckQuery("SELECT c.*,COALESCE(o.quantity,0) owned_printing
        FROM cards c LEFT JOIN ".deckCollectionPrintingSql()." o ON o.scryfall_id=c.id
        WHERE lower(c.name)=lower(?) OR lower(split_part(c.name,' // ',1))=lower(?)
        ORDER BY (o.quantity IS NOT NULL) DESC,".deckCheapestPriceSql('c')." ASC NULLS LAST,(c.lang='en') DESC,(c.local_image IS NOT NULL) DESC,c.released_at DESC NULLS LAST LIMIT 1",[$name,$name])->fetch() ?: null;
}

function deckImportList(string $name, string $text): array {
    $name=trim($name); $text=trim($text);
    if ($name==='' || strlen($name)>160) throw new RuntimeException('Informe um nome de até 160 caracteres.');
    if ($text==='' || strlen($text)>200000) throw new RuntimeException('Cole uma lista de até 200.000 caracteres.');
    $entries=[]; $section='deck'; $unmatched=[];
    foreach (preg_split('/\R/u',preg_replace('/^\xEF\xBB\xBF/','',$text)) ?: [] as $line) {
        $line=trim($line); if($line==='' || str_starts_with($line,'//')) continue;
        $heading=strtolower(rtrim($line,':'));
        if(in_array($heading,['commander','commanders','comandante'],true)){ $section='commander'; continue; }
        if(in_array($heading,['deck','mainboard','decklist','lista','sideboard','maybeboard'],true)){ $section='deck'; continue; }
        if(!preg_match('/^(\d+)\s+(?:x\s+)?(.+)$/iu',$line,$m)) continue;
        $qty=max(1,min(1000,(int)$m[1])); $cardName=trim(preg_replace('/\s+\([A-Z0-9]{2,8}\)\s+[^\s]+(?:\s+\*F\*)?$/i','',$m[2]) ?? $m[2]);
        $card=deckFindPrinting($cardName);
        if(!$card){$unmatched[]=$cardName;continue;}
        $entries[]=['section'=>$section,'quantity'=>$qty,'card'=>$card];
        if($section==='commander') $section='deck';
    }
    if(!$entries) throw new RuntimeException('Nenhuma carta da lista foi encontrada no catálogo local. Use o formato “1 Nome da carta”.');
    db()->beginTransaction();
    try {
        $deckId=(int)deckQuery("INSERT INTO builder_decks(name,status) VALUES (?,'ready') RETURNING id",[$name])->fetchColumn();
        $commander=null; $logical=[];
        foreach($entries as $entry){
            $card=$entry['card']; $logicalId=(string)($card['oracle_id'] ?: $card['id']);
            if(!str_contains((string)$card['type_line'],'Basic')) $entry['quantity']=1;
            if($entry['section']==='commander' && $commander===null && deckQuery('SELECT 1 FROM cards c WHERE c.id=? AND '.deckCommanderSql(),[$card['id']])->fetchColumn()){$commander=$card['id'];continue;}
            if(isset($logical[$logicalId])){$logical[$logicalId]['quantity']=min(1000,$logical[$logicalId]['quantity']+$entry['quantity']);continue;}
            $logical[$logicalId]=['card_id'=>$card['id'],'quantity'=>$entry['quantity']];
        }
        if($commander) deckQuery('UPDATE builder_decks SET commander_id=? WHERE id=?',[$commander,$deckId]);
        foreach($logical as $entry) deckQuery("INSERT INTO builder_items(deck_id,card_id,stage,quantity) VALUES (?,?,'deck',?)",[$deckId,$entry['card_id'],$entry['quantity']]);
        db()->commit();
    } catch(Throwable $e){db()->rollBack();throw $e;}
    return ['id'=>$deckId,'matched'=>count($entries),'unmatched'=>array_values(array_unique($unmatched))];
}

function deckEdhrecSlug(string $name): string {
    $name=explode(' // ',$name)[0];
    $ascii=iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$name) ?: $name;
    return trim(preg_replace('/[^a-z0-9]+/','-',strtolower($ascii)) ?? '', '-');
}

function deckEdhrecCards(array $node, array &$cards): void {
    if(isset($node['name']) && (isset($node['synergy']) || isset($node['lift']))){$cards[]=$node;}
    foreach($node as $value) if(is_array($value)) deckEdhrecCards($value,$cards);
}

function deckSyncEdhrec(array $commander): int {
    $slug=deckEdhrecSlug((string)$commander['name']);
    $source='https://edhrec.com/commanders/'.$slug; $jsonUrl='https://json.edhrec.com/pages/commanders/'.$slug.'.json';
    $context=stream_context_create(['http'=>['timeout'=>12,'user_agent'=>'Deckarium/1.0 personal collection app','ignore_errors'=>true]]);
    $raw=@file_get_contents($jsonUrl,false,$context);
    if($raw===false) throw new RuntimeException('O EDHREC não respondeu. Sua cache anterior foi preservada; tente novamente mais tarde.');
    $data=json_decode($raw,true); if(!is_array($data)) throw new RuntimeException('O EDHREC retornou dados inesperados. Sua cache anterior foi preservada.');
    $found=[]; deckEdhrecCards($data,$found); $saved=0; $best=[];
    foreach($found as $row){
        $card=deckFindPrinting((string)$row['name']); if(!$card) continue;
        $metric=array_key_exists('lift',$row)?'lift':'synergy';
        $score=(float)($row[$metric] ?? 0);
        $key=(string)$card['id'];
        if(!isset($best[$key])
            || ($metric==='synergy' && $best[$key]['metric']==='lift')
            || ($metric===$best[$key]['metric'] && $score>$best[$key]['score'])) {
            $best[$key]=['card'=>$card,'row'=>$row,'metric'=>$metric,'score'=>$score];
        }
    }
    db()->beginTransaction();
    try {
        foreach($best as $entry){
            $row=$entry['row']; $card=$entry['card']; $metric=$entry['metric']; $score=$entry['score'];
            $inclusion=isset($row['inclusion'])?(float)$row['inclusion']:(isset($row['num_decks'],$row['potential_decks']) && (int)$row['potential_decks']>0 ? (int)$row['num_decks']/(int)$row['potential_decks'] : null);
            deckQuery('INSERT INTO deck_synergy(commander_id,card_id,metric,score,inclusion,deck_count,source_url,synced_at) VALUES (?,?,?,?,?,?,?,now()) ON CONFLICT(commander_id,card_id) DO UPDATE SET metric=excluded.metric,score=excluded.score,inclusion=excluded.inclusion,deck_count=excluded.deck_count,source_url=excluded.source_url,synced_at=now()',[$commander['id'],$card['id'],$metric,$score,$inclusion,$row['num_decks']??null,$source]);
            $saved++;
        }
        db()->commit();
    }catch(Throwable $e){db()->rollBack();throw $e;}
    if(!$saved) throw new RuntimeException('Nenhuma recomendação compatível com o catálogo local foi encontrada.');
    return $saved;
}
function deckQuery(string $sql, array $params = []): PDOStatement {
    $stmt = db()->prepare($sql); $stmt->execute($params); return $stmt;
}
function deckOwnedSql(?int $excludeDeckId=null): string {
    $sql="WITH owned AS (SELECT COALESCE(c.oracle_id,c.id) logical_id,SUM(o.quantity)::int owned
        FROM builder_collection o JOIN cards c ON c.id=o.scryfall_id GROUP BY COALESCE(c.oracle_id,c.id))";
    if($excludeDeckId!==null){
        $deckId=max(0,$excludeDeckId);
        $sql.=", used AS (SELECT logical_id,SUM(quantity)::int used FROM (
            SELECT COALESCE(c.oracle_id,c.id) logical_id,SUM(i.quantity)::int quantity FROM builder_items i JOIN cards c ON c.id=i.card_id WHERE i.stage='deck' AND i.deck_id<>{$deckId} GROUP BY COALESCE(c.oracle_id,c.id)
            UNION ALL SELECT COALESCE(c.oracle_id,c.id) logical_id,COUNT(*)::int quantity FROM builder_decks d JOIN cards c ON c.id=d.commander_id WHERE d.id<>{$deckId} GROUP BY COALESCE(c.oracle_id,c.id)
        ) reservations GROUP BY logical_id)";
    }
    return $sql.' ';
}
function deckCollectionPrintingSql(): string {
    return "(SELECT scryfall_id,SUM(quantity)::int quantity,
        COALESCE(SUM(quantity) FILTER(WHERE NOT foil),0)::int normal_quantity,
        COALESCE(SUM(quantity) FILTER(WHERE foil),0)::int foil_quantity
        FROM builder_collection GROUP BY scryfall_id)";
}
function deckImport(string $path, bool $replace = true): array {
    $fp = fopen($path, 'r');
    if (!$fp) throw new RuntimeException('Não foi possível ler o CSV.');
    $headers = fgetcsv($fp, 0, ',', '"', '');
    if (!$headers) throw new RuntimeException('CSV vazio.');
    $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]);
    $map = array_flip($headers);
    foreach (['Name','Scryfall ID','Quantity'] as $key) if (!isset($map[$key])) throw new RuntimeException('Use a exportação CSV do ManaBox com Name, Scryfall ID e Quantity.');
    $rows = []; $line = 1;
    while (($row = fgetcsv($fp, 0, ',', '"', '')) !== false) {
        $line++; if ($row === [null]) continue;
        $id = trim($row[$map['Scryfall ID']] ?? ''); $qty = trim($row[$map['Quantity']] ?? '');
        if (!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/i', $id) || !ctype_digit($qty) || (int)$qty < 1 || (int)$qty > 100000) throw new RuntimeException("ID ou quantidade inválida na linha {$line}. A coleção anterior foi preservada.");
        $id = strtolower($id); $foilValue=strtolower(trim((string)($row[$map['Foil']??-1]??'normal')));
        $foilValues=['1','true','yes','sim','foil','etched'];$normalValues=['','0','false','no','nao','não','normal'];
        if(!in_array($foilValue,array_merge($foilValues,$normalValues),true))throw new RuntimeException("Acabamento inválido na linha {$line}. Use normal, foil, 0 ou 1.");
        $foil=in_array($foilValue,$foilValues,true); $key=$id.'|'.($foil?'1':'0');
        if (!isset($rows[$key])) $rows[$key] = ['id'=>$id,'name' => $row[$map['Name']] ?? '', 'quantity' => 0,'foil'=>$foil];
        $rows[$key]['quantity'] += (int)$qty;
    }
    fclose($fp);
    if (!$rows) throw new RuntimeException('Nenhuma carta válida no CSV.');
    db()->beginTransaction();
    try {
        if ($replace) db()->exec('DELETE FROM builder_collection');
        foreach ($rows as $row) {
            $foilSql=$row['foil']?'true':'false';
            if ($replace) deckQuery('INSERT INTO builder_collection(scryfall_id,name,quantity,foil) VALUES (?,?,?,?)', [$row['id'],$row['name'],$row['quantity'],$foilSql]);
            else deckQuery('INSERT INTO builder_collection(scryfall_id,name,quantity,foil) VALUES (?,?,?,?) ON CONFLICT(scryfall_id,foil) DO UPDATE SET name=excluded.name,quantity=builder_collection.quantity+excluded.quantity', [$row['id'],$row['name'],$row['quantity'],$foilSql]);
        }
        db()->commit();
    } catch (Throwable $e) { db()->rollBack(); throw $e; }
    return ['printings'=>count($rows),'quantity'=>array_sum(array_column($rows,'quantity'))];
}
function deckLigaCsv(array $rows): string {
    $fp=fopen('php://temp','w+');
    fputcsv($fp,['Edicao (PTBR)','Edicao (EN)','Edicao (Sigla)','Card (PT)','Card (EN)','Quantidade','Qualidade (M NM SP MP HP D)','Idioma (BR EN DE ES FR IT JP KO RU TW)','Raridade (M R U C)','Cor (W U B R G M A L)','Extras','Card #','Comentario'],',','"','',"\r\n");
    $languages=['pt'=>'BR','en'=>'EN','de'=>'DE','es'=>'ES','fr'=>'FR','it'=>'IT','ja'=>'JP','ko'=>'KO','ru'=>'RU','zht'=>'TW','zhs'=>'TW'];
    $rarities=['mythic'=>'M','rare'=>'R','uncommon'=>'U','common'=>'C'];
    foreach($rows as $row){
        $colors=json_decode((string)($row['colors']??'[]'),true)?:[];
        $color=str_contains((string)($row['type_line']??''),'Land')?'L':(str_contains((string)($row['type_line']??''),'Artifact')?'A':(count($colors)>1?'M':($colors[0]??'')));
        $printed=''; $raw=$row['raw']??[]; if(is_string($raw))$raw=json_decode($raw,true)?:[];
        if(($row['lang']??'en')==='pt')$printed=(string)($raw['printed_name']??'');
        fputcsv($fp,['',(string)($row['set_name']??''),strtolower((string)($row['set_code']??'')),$printed,(string)$row['name'],(int)$row['export_quantity'],'NM',$languages[$row['lang']??'en']??'EN',$rarities[$row['rarity']??'']??'',$color,!empty($row['export_foil'])?'Foil':'',(string)($row['collector_number']??''),'Exportado do Deckarium'],',','"','',"\r\n");
    }
    rewind($fp); $csv=stream_get_contents($fp)?:''; fclose($fp);
    return iconv('UTF-8','Windows-1252//TRANSLIT',$csv)?:$csv;
}
function deckText(array $card): string {
    $faces = json_decode((string)($card['card_faces'] ?? '[]'), true) ?: [];
    return implode("\n", array_unique(array_filter([(string)($card['oracle_text'] ?? ''), ...array_column($faces,'oracle_text')])));
}
function deckTerms(string $value): array {
    return array_values(array_unique(array_filter(array_map('trim', explode(';', $value)), fn($s)=>$s!=='')));
}
function deckHighlight(?string $text, array $terms): string {
    $text = $text ?? '';
    $terms=array_values(array_unique(array_filter($terms,fn($term)=>$term!=='')));
    if(!$terms)return h($text);
    usort($terms,fn($a,$b)=>strlen($b)<=>strlen($a));
    $pattern='/('.implode('|',array_map(fn($term)=>preg_quote($term,'/'),$terms)).')/iu';
    $parts=preg_split($pattern,$text,-1,PREG_SPLIT_DELIM_CAPTURE);
    if($parts===false)return h($text);
    $html='';foreach($parts as $i=>$part)$html.=($i%2)?'<strong class="search-match">'.h($part).'</strong>':h($part);
    return $html;
}
function deckStageLabel(?string $stage): string {
    return ['candidate'=>'Candidatas','review'=>'Em avaliação','deck'=>'No deck'][$stage ?? ''] ?? 'Já selecionada';
}
function deckCommanderSql(): string {
    return 'c.commander_eligible';
}
