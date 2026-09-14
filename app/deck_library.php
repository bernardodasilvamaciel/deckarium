<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

function deckSchema(): void {
    db()->exec("CREATE TABLE IF NOT EXISTS builder_decks (
        id bigserial PRIMARY KEY, name text NOT NULL, commander_id uuid REFERENCES cards(id),
        strategy text NOT NULL DEFAULT '', terms text NOT NULL DEFAULT '', created_at timestamptz DEFAULT now());
        CREATE TABLE IF NOT EXISTS builder_items (
        deck_id bigint REFERENCES builder_decks(id) ON DELETE CASCADE, card_id uuid REFERENCES cards(id),
        stage text NOT NULL CHECK(stage IN ('candidate','review','deck')), quantity int NOT NULL CHECK(quantity > 0),
        role text NOT NULL DEFAULT '', notes text NOT NULL DEFAULT '', PRIMARY KEY(deck_id,card_id));
        CREATE TABLE IF NOT EXISTS builder_collection (
        scryfall_id uuid PRIMARY KEY, name text NOT NULL, quantity int NOT NULL CHECK(quantity > 0));");
}
function deckQuery(string $sql, array $params = []): PDOStatement {
    $stmt = db()->prepare($sql); $stmt->execute($params); return $stmt;
}
function deckOwnedSql(): string {
    return "WITH owned AS (SELECT COALESCE(c.oracle_id,c.id) logical_id,SUM(o.quantity)::int owned
        FROM builder_collection o JOIN cards c ON c.id=o.scryfall_id GROUP BY COALESCE(c.oracle_id,c.id)) ";
}
function deckImport(string $path): array {
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
        $id = strtolower($id);
        if (!isset($rows[$id])) $rows[$id] = ['name' => $row[$map['Name']] ?? '', 'quantity' => 0];
        $rows[$id]['quantity'] += (int)$qty;
    }
    fclose($fp);
    if (!$rows) throw new RuntimeException('Nenhuma carta válida no CSV.');
    db()->beginTransaction();
    try {
        db()->exec('DELETE FROM builder_collection');
        foreach ($rows as $id => $row) deckQuery('INSERT INTO builder_collection VALUES (?,?,?)', [$id,$row['name'],$row['quantity']]);
        db()->commit();
    } catch (Throwable $e) { db()->rollBack(); throw $e; }
    return ['printings'=>count($rows),'quantity'=>array_sum(array_column($rows,'quantity'))];
}
function deckText(array $card): string {
    $faces = json_decode((string)($card['card_faces'] ?? '[]'), true) ?: [];
    return implode("\n", array_unique(array_filter([(string)($card['oracle_text'] ?? ''), ...array_column($faces,'oracle_text')])));
}
function deckTerms(string $value): array {
    return array_values(array_unique(array_filter(array_map('trim', explode(';', $value)), fn($s)=>$s!=='')));
}
function deckHighlight(string $text, array $terms): string {
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
    // Front face only. Legendary Vehicles / Spacecraft: Wizards EOE update bulletin.
    $type="COALESCE(c.raw->'card_faces'->0->>'type_line',c.type_line,'')";
    $oracle="COALESCE(c.raw->'card_faces'->0->>'oracle_text',c.oracle_text,'')";
    $power="COALESCE(c.raw->'card_faces'->0->>'power',c.raw->>'power')";
    $toughness="COALESCE(c.raw->'card_faces'->0->>'toughness',c.raw->>'toughness')";
    return "(({$type} LIKE '%Legendary%' AND ({$type} LIKE '%Creature%' OR (({$type} LIKE '%Vehicle%' OR {$type} LIKE '%Spacecraft%') AND {$power} IS NOT NULL AND {$toughness} IS NOT NULL))) OR {$oracle} ILIKE '%can be your commander%')";
}
