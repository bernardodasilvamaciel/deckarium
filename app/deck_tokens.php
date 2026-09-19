<?php
declare(strict_types=1);

/**
 * Fichas e marcadores que o deck precisa ter à mão: vêm de raw.all_parts (componente "token") da comandante
 * e das cartas aprovadas no deck. Cartas que criam cópias ganham uma entrada genérica "Cópia".
 * Devolve uma lista ordenada (mais fontes primeiro) com a impressão sugerida, as cartas que criam cada ficha,
 * uma estimativa de quantas fichas ter à mão e quantas já estão na coleção.
 */
function deckTokenList(?array $commander, array $items): array
{
    $sources = [];
    if ($commander) $sources[] = $commander + ['quantity' => 1, 'is_commander' => true];
    foreach ($items as $item) if (($item['stage'] ?? '') === 'deck') $sources[] = $item;

    $tokens = [];
    $copyMakers = [];
    foreach ($sources as $source) {
        $raw = $source['raw'] ?? null;
        if (is_string($raw)) $raw = json_decode($raw, true);
        $amount = deckTokenAmount($source);
        foreach ((array)($raw['all_parts'] ?? []) as $part) {
            if (($part['component'] ?? '') !== 'token' || empty($part['id'])) continue;
            $partId = strtolower((string)$part['id']);
            $tokens[$partId] ??= ['part_id' => $partId, 'name' => (string)($part['name'] ?? 'Ficha'), 'type_line' => (string)($part['type_line'] ?? ''), 'sources' => []];
            $tokens[$partId]['sources'][(string)$source['id']] = ['id' => (string)$source['id'], 'name' => (string)$source['name'], 'amount' => $amount, 'commander' => !empty($source['is_commander'])];
        }
        if (preg_match('/\btoken(s)? that\'?s? (is )?a copy|copy of[^.]*\btoken\b|create[^.]*\bcop(y|ies)\b[^.]*token|token cop(y|ies)\b|\bmyriad\b|\bencore\b|\bembalm\b|\beternalize\b|\bpopulate\b/i', deckText($source))) {
            $copyMakers[(string)$source['id']] = ['id' => (string)$source['id'], 'name' => (string)$source['name'], 'amount' => $amount, 'commander' => !empty($source['is_commander'])];
        }
    }

    // A mesma ficha aparece com ids diferentes (uma por edição): agrupa pela identidade Oracle da ficha.
    $rows = $tokens ? deckQuery("SELECT id::text AS part_id, COALESCE(oracle_id,id)::text AS logical_id FROM cards WHERE id = ANY(?::uuid[])", ['{' . implode(',', array_keys($tokens)) . '}'])->fetchAll(PDO::FETCH_KEY_PAIR) : [];
    $groups = [];
    foreach ($tokens as $partId => $token) {
        $key = $rows[$partId] ?? ('name:' . strtolower($token['name'] . '|' . $token['type_line']));
        $groups[$key] ??= ['logical_id' => isset($rows[$partId]) ? $key : null, 'part_id' => $partId, 'name' => $token['name'], 'type_line' => $token['type_line'], 'sources' => []];
        $groups[$key]['sources'] += $token['sources'];
    }

    // Uma impressão por ficha: a da coleção, senão em inglês e com imagem.
    $logicalIds = array_values(array_filter(array_column($groups, 'logical_id')));
    $printings = [];
    if ($logicalIds) {
        foreach (deckQuery("SELECT DISTINCT ON (COALESCE(c.oracle_id,c.id)) COALESCE(c.oracle_id,c.id)::text AS logical_id, c.*
            FROM cards c LEFT JOIN " . deckCollectionPrintingSql() . " bc ON bc.scryfall_id=c.id
            WHERE COALESCE(c.oracle_id,c.id) = ANY(?::uuid[])
            ORDER BY COALESCE(c.oracle_id,c.id), (COALESCE(bc.quantity,0)>0) DESC, (c.lang='en') DESC, (c.local_image IS NOT NULL OR c.image_uri IS NOT NULL) DESC, c.released_at DESC NULLS LAST", ['{' . implode(',', $logicalIds) . '}'])->fetchAll() as $row) {
            $printings[$row['logical_id']] = $row;
        }
    }
    $owned = function_exists('deckOwnedLogicalMap') ? deckOwnedLogicalMap() : [];

    $list = [];
    foreach ($groups as $group) {
        $typeLine = $group['type_line'];
        $kind = match (true) {
            str_starts_with($typeLine, 'Emblem') => 'emblem',
            (bool)preg_match('/^(Card|Dungeon)\b/', $typeLine) => 'marker',
            str_contains($typeLine, 'Creature') => 'creature',
            default => 'other',
        };
        $sourcesList = array_values($group['sources']);
        $list[] = [
            'card' => $group['logical_id'] ? ($printings[$group['logical_id']] ?? null) : null,
            'id' => $group['logical_id'] && isset($printings[$group['logical_id']]) ? (string)$printings[$group['logical_id']]['id'] : $group['part_id'],
            'name' => $group['name'],
            'type_line' => preg_replace('/^Token /', '', str_replace(' // Token ', ' // ', $typeLine)),
            'kind' => $kind,
            'sources' => $sourcesList,
            'suggested' => $kind === 'creature' || $kind === 'other' ? deckTokenSuggested($sourcesList) : 1,
            'owned' => $group['logical_id'] ? (int)($owned[$group['logical_id']] ?? 0) : 0,
        ];
    }
    if ($copyMakers) {
        $makers = array_values($copyMakers);
        $list[] = ['card' => null, 'id' => null, 'name' => 'Cópia de uma permanente', 'type_line' => 'Ficha genérica (qualquer carta de ficha ou marcador de cópia)', 'kind' => 'copy', 'sources' => $makers, 'suggested' => deckTokenSuggested($makers), 'owned' => 0];
    }
    $kindOrder = ['creature' => 0, 'other' => 1, 'copy' => 2, 'emblem' => 3, 'marker' => 4];
    usort($list, fn($a, $b) => [$kindOrder[$a['kind']], -count($a['sources']), $a['name']] <=> [$kindOrder[$b['kind']], -count($b['sources']), $b['name']]);
    return $list;
}

/** Quantas fichas a carta cria de uma vez, lido do texto: "create two" = 2; X, "that many" ou "for each" = variável (null). */
function deckTokenAmount(array $card): ?int
{
    $text = strtolower(preg_replace('/\([^)]*\)/', '', deckText($card)) ?? '');
    if (!preg_match_all('/\bcreates? ([^.]*?)\btokens?\b/', $text, $matches)) return 1;
    $numbers = ['a' => 1, 'an' => 1, 'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10];
    $best = 1;
    foreach ($matches[1] as $clause) {
        if (preg_match('/^(x|that many|a number of|any number of)\b/', $clause) || preg_match('/\bfor each\b/', $clause)) return null;
        if (preg_match('/^(\d+)\b/', $clause, $digit)) $best = max($best, (int)$digit[1]);
        elseif (preg_match('/^(\w+)\b/', $clause, $word) && isset($numbers[$word[1]])) $best = max($best, $numbers[$word[1]]);
    }
    return $best;
}

/** Sugestão de fichas à mão: soma do que cada fonte cria de uma vez; efeitos variáveis contam 3. */
function deckTokenSuggested(array $sources): int
{
    $total = 0;
    foreach ($sources as $source) $total += $source['amount'] ?? 3;
    return max(1, min(20, $total));
}
