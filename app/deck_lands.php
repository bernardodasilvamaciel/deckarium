<?php
declare(strict_types=1);

/** Função gravada nos terrenos adicionados automaticamente: permite recalcular e remover só esses. */
const DECK_AUTO_LAND_ROLE = 'Terreno automático';
const DECK_BASIC_LANDS = ['W' => 'Plains', 'U' => 'Island', 'B' => 'Swamp', 'R' => 'Mountain', 'G' => 'Forest', 'C' => 'Wastes'];

/**
 * Plano para completar a base de mana do deck.
 *
 * 1. Meta de terrenos: a da comandante (EDHREC ou leitura do texto, em "Metas e regras"), ou a informada.
 *    Terrenos já aprovados por você contam; os automáticos anteriores são recalculados.
 * 2. Demanda de cor: símbolos nos custos das mágicas do deck; os da comandante valem o dobro.
 * 3. Terrenos não básicos: primeiro terrenos que já estão nas candidatas, depois os da coleção com cópia livre
 *    (e, se pedido, os de fora dela), pontuados por cores úteis, entrar virado, desvantagens, utilidade e EDHREC.
 * 4. Básicos completam o restante, divididos pela falta de fontes de cada cor.
 *
 * $options: total (meta de terrenos informada), buy (inclui terrenos fora da coleção), skip (ids lógicos a ignorar).
 */
function deckLandPlan(int $deckId, array $commander, array $items, array $config, array $options = []): array
{
    $identity = array_values(array_intersect(['W', 'U', 'B', 'R', 'G'], json_decode((string)$commander['color_identity'], true) ?: []));
    $colors = $identity ?: ['C'];
    $includeMissing = $options['buy'] ?? (bool)($config['land_fill']['buy'] ?? false);
    // Mínimo de básicos: a fração informada pelo usuário ou a padrão da identidade de cor.
    $basicShareOption = $options['basic_share'] ?? ($config['land_fill']['basic_share'] ?? null);
    $skip = array_flip(array_map('strtolower', (array)($options['skip'] ?? [])));

    $taken = [strtolower((string)($commander['oracle_id'] ?: $commander['id'])) => true];
    $takenPrintings = [];
    $cardCount = 1; $manualLands = 0; $manualBasics = 0; $autoLands = 0; $nonland = 0; $basicFetchers = 0;
    $pips = array_fill_keys($colors, 0.0);
    $sources = array_fill_keys($colors, 0);
    $candidateLands = [];
    $spells = [];
    $addPips = function (array $card, float $weight) use (&$pips): void {
        foreach (array_keys($pips) as $color) if ($color !== 'C') $pips[$color] += substr_count(strtoupper((string)($card['mana_cost'] ?? '')), $color) * $weight;
    };
    $addPips($commander, 2.0);
    foreach ($items as $item) {
        if ($item['role'] !== DECK_AUTO_LAND_ROLE) $takenPrintings[strtolower((string)$item['id'])] = true;
        $isLand = str_contains(explode(' // ', (string)$item['type_line'])[0], 'Land');
        if ($item['stage'] === 'candidate') {
            if ($isLand) $candidateLands[strtolower((string)($item['oracle_id'] ?: $item['id']))] = $item;
            continue;
        }
        if ($item['stage'] !== 'deck') continue;
        $quantity = (int)$item['quantity'];
        if ($item['role'] === DECK_AUTO_LAND_ROLE) { $autoLands += $quantity; continue; }
        $taken[strtolower((string)($item['oracle_id'] ?: $item['id']))] = true;
        $cardCount += $quantity;
        if ($isLand) {
            $manualLands += $quantity;
            if (str_contains((string)$item['type_line'], 'Basic')) $manualBasics += $quantity;
            foreach (deckLandColors($item, $colors) as $color) $sources[$color] += $quantity;
        } else {
            $nonland += $quantity;
            $spells[] = $item;
            $addPips($item, 1.0);
            if (preg_match('/search your library for[^.]*\bbasic (land|forest|island|swamp|mountain|plains)/i', deckText($item))) $basicFetchers++;
        }
    }

    // Meta: a informada agora, senão a salva no deck, senão a sugestão calculada pelas mágicas do deck.
    $suggestion = deckLandSuggestion($commander, $spells, $config);
    $saved = $config['land_fill']['total'] ?? null;
    $targetSource = isset($options['total']) && $options['total'] !== null ? 'request' : ($saved !== null ? 'saved' : 'suggestion');
    $target = max(0, min(60, (int)($targetSource === 'request' ? $options['total'] : ($saved ?? $suggestion['total']))));
    $open = max(0, 100 - $cardCount);
    // Os terrenos só são escolhidos com a parte não terreno fechada: comandante + mágicas = 100 − meta.
    // Assim a divisão de cores e os não básicos são calculados sobre a lista final de mágicas.
    $spellsNeeded = 99 - $target;
    $blocked = null;
    if ($manualLands > $target) $blocked = 'Você já aprovou ' . $manualLands . ' terrenos, mais que a meta de ' . $target . '. Aumente a meta ou devolva terrenos às candidatas.';
    elseif ($nonland < $spellsNeeded) $blocked = 'Faltam ' . ($spellsNeeded - $nonland) . ' mágica(s). Com ' . $target . ' terrenos, o deck precisa de ' . $spellsNeeded . ' mágicas + comandante antes de completar; hoje tem ' . $nonland . '.';
    elseif ($nonland > $spellsNeeded) $blocked = 'Sobram ' . ($nonland - $spellsNeeded) . ' mágica(s). Com ' . $target . ' terrenos, o deck comporta ' . $spellsNeeded . ' mágicas + comandante; hoje tem ' . $nonland . '. Retire mágicas ou reduza a meta para ' . (99 - $nonland) . '.';
    $need = $blocked ? 0 : max(0, $target - $manualLands);
    $notes = [];

    // Demanda de cor: participação de cada cor nos símbolos; sem símbolos, as cores da identidade dividem igualmente.
    $pipTotal = array_sum($pips);
    $share = [];
    foreach ($colors as $color) $share[$color] = $pipTotal > 0 ? $pips[$color] / $pipTotal : 1 / count($colors);
    $demandColors = array_keys(array_filter($share, fn($value) => $value > 0));
    $multicolor = count($identity) >= 2;

    // Terrenos não básicos considerados: candidatas, coleção com cópia livre e (opcional) catálogo.
    // Piso de terrenos básicos. Antes o limite era só um teto de não básicos (até 85% em três cores),
    // o que deixava decks quase sem básicos — ruins para buscas, para Tesouros e para o bolso.
    $defaultShare = match (count($identity)) { 0, 1 => 0.55, 2 => 0.42, 3 => 0.34, 4 => 0.28, default => 0.25 };
    $basicShare = $basicShareOption !== null ? max(0.0, min(1.0, (float)$basicShareOption)) : $defaultShare;
    $totalTarget = $manualLands + max(0, $target - $manualLands);
    $minBasics = min($need, max(0, (int)ceil($basicShare * $totalTarget) - $manualBasics, $basicFetchers * 2));
    $synergy = deckQuery("SELECT COALESCE(card.oracle_id,card.id)::text, MAX(s.score) FROM deck_synergy s
        JOIN cards leader ON leader.id=s.commander_id JOIN cards card ON card.id=s.card_id
        WHERE COALESCE(leader.oracle_id,leader.id)=?::uuid GROUP BY 1", [(string)($commander['oracle_id'] ?: $commander['id'])])->fetchAll(PDO::FETCH_KEY_PAIR);
    // Terrenos que o EDHREC mostra nesta comandante entram na busca mesmo fora do top 6000 geral.
    $landOptions = deckLandOptions($deckId, $commander, $identity, $includeMissing, $includeMissing ? array_keys($synergy) : []);
    $pool = [];
    foreach ($candidateLands as $logical => $item) {
        if (isset($taken[$logical]) || str_contains((string)$item['type_line'], 'Basic')) continue;
        $landOptions[$logical] = $item + ['logical_id' => $logical, 'free' => max(0, (int)($item['owned'] ?? 0) - (int)($item['other_used'] ?? 0)), 'owned' => (int)($item['owned'] ?? 0), 'from_candidate' => true];
    }
    foreach ($landOptions as $logical => $land) {
        if (isset($taken[$logical]) || isset($skip[$logical])) continue;
        $evaluation = deckLandScore($land, $colors, $share, $multicolor, isset($synergy[$logical]) ? (float)$synergy[$logical] : null);
        if ($evaluation === null) continue;
        $availability = !empty($land['from_candidate']) ? 'candidate' : ((int)$land['free'] > 0 ? 'free' : ((int)$land['owned'] > 0 ? 'reserved' : 'missing'));
        if (!$includeMissing && !in_array($availability, ['candidate', 'free'], true)) continue;
        $bonus = ['candidate' => 25, 'free' => 12, 'reserved' => 0, 'missing' => -6][$availability];
        $pool[] = ['card' => $land, 'logical_id' => $logical, 'score' => $evaluation['score'] + $bonus, 'colors' => $evaluation['colors'], 'reasons' => $evaluation['reasons'],
            'availability' => $availability, 'utility_only' => $evaluation['utility_only'], 'quantity' => 1];
    }
    usort($pool, fn($a, $b) => [$b['score'], $a['card']['name']] <=> [$a['score'], $b['card']['name']]);

    // Escolha gulosa: melhor pontuação primeiro, com teto de não básicos e de terrenos que só geram incolor.
    $colorlessCap = $multicolor ? (count($identity) >= 3 ? 2 : 3) : 6;
    $picks = []; $colorlessPicked = 0;
    foreach ($pool as $option) {
        if (count($picks) >= $need - $minBasics) break;
        if ($option['score'] < 18) continue;
        if ($option['utility_only']) { if ($colorlessPicked >= $colorlessCap) continue; $colorlessPicked++; }
        $picks[] = $option;
        foreach ($option['colors'] as $color) $sources[$color]++;
    }

    // Básicos: divididos pela falta de fontes de cada cor em relação à participação dela nos custos.
    $basicCount = $need - count($picks);
    $totalLands = $manualLands + $need;
    $basics = array_fill_keys($colors, 0);
    if ($basicCount > 0) {
        $weights = [];
        foreach ($colors as $color) $weights[$color] = $identity ? max(0.0, $share[$color] * $totalLands - $sources[$color]) : 1.0;
        if (array_sum($weights) <= 0) $weights = $identity ? $share : array_fill_keys($colors, 1.0);
        if (array_sum($weights) <= 0) $weights = array_fill_keys($colors, 1.0);
        $sum = array_sum($weights); $allocated = 0; $remainders = [];
        foreach ($weights as $color => $weight) { $exact = $basicCount * $weight / $sum; $basics[$color] = (int)floor($exact); $allocated += $basics[$color]; $remainders[$color] = $exact - floor($exact); }
        arsort($remainders);
        foreach (array_keys($remainders) as $color) { if ($allocated >= $basicCount) break; $basics[$color]++; $allocated++; }
        // Piso por cor: cada cor pedida nos custos fica com básicos próprios, mesmo quando os não básicos
        // já cobrem a cor. Sem isso o plano chegava a 16 básicos de uma cor só e nenhum da outra,
        // o que trava buscas de básico e mãos iniciais.
        foreach ($demandColors as $color) {
            $floor = min($share[$color] >= 0.15 ? 2 : 1, intdiv($basicCount, max(1, count($demandColors))));
            while ($basics[$color] < $floor) {
                $donor = array_search(max($basics), $basics, true);
                if ($donor === false || $donor === $color || $basics[$donor] <= $floor + 1) break;
                $basics[$donor]--; $basics[$color]++;
            }
        }
        foreach ($basics as $color => $amount) $sources[$color] += $amount;
    }
    $basicRows = deckBasicPrintings(array_keys(array_filter($basics)), $takenPrintings, $deckId);
    $basicPicks = [];
    foreach (array_filter($basics) as $color => $amount) {
        if (!isset($basicRows[$color])) continue;
        $row = $basicRows[$color];
        $basicPicks[] = ['card' => $row, 'color' => $color, 'quantity' => $amount, 'free' => (int)$row['free'], 'missing' => max(0, $amount - (int)$row['free'])];
    }

    return [
        'target' => $target, 'target_source' => $targetSource, 'suggestion' => $suggestion, 'blocked' => $blocked, 'spells_needed' => $spellsNeeded,
        'reference' => (int)($config['targets']['lands'] ?? 37), 'reference_source' => ($config['targets_mode'] ?? 'auto') !== 'auto' ? 'custom' : (($config['dynamic']['source'] ?? '') === 'edhrec' ? 'edhrec' : 'text'),
        'manual' => $manualLands, 'auto_existing' => $autoLands, 'nonland' => $nonland, 'open' => $open, 'need' => $need,
        'share' => $share, 'sources' => $sources, 'colors' => $colors, 'identity' => $identity,
        'picks' => $picks, 'basics' => $basicPicks, 'notes' => $notes, 'include_missing' => $includeMissing,
        'min_basics' => $minBasics, 'basic_share' => $basicShare, 'basic_share_source' => $basicShareOption !== null ? 'custom' : 'auto',
        'manual_basics' => $manualBasics,
        'missing_count' => count(array_filter($picks, fn($pick) => in_array($pick['availability'], ['reserved', 'missing'], true))) + array_sum(array_column($basicPicks, 'missing')),
    ];
}

/**
 * Quantos terrenos o deck pede, pela proposta dele: parte de 37 com curva média 3 e ajusta por
 * curva (≈4 terrenos por ponto de valor de mana médio), aceleração barata (rochas, criaturas que geram mana,
 * busca de terrenos; ~1 terreno a cada 3 peças), compra barata, terrenos importando (landfall) e custo da comandante.
 * Devolve o total (31–42) e cada ajuste com o motivo.
 */
function deckLandSuggestion(array $commander, array $spells, array $config): array
{
    $count = 0; $mvSum = 0.0; $ramp = 0.0; $rampCards = 0; $draw = 0; $landfall = 0;
    foreach ($spells as $spell) {
        $quantity = max(1, (int)($spell['quantity'] ?? 1));
        $mv = (float)($spell['cmc'] ?? 0);
        $count += $quantity; $mvSum += $mv * $quantity;
        $profile = deckScoreProfile($spell);
        if (isset($profile['roles']['ramp']) && $mv <= 3) {
            $text = $profile['text'];
            // Tesouros avulsos aceleram uma vez só: valem metade de uma rocha ou criatura de mana.
            $ramp += preg_match('/\{t\}[^.]*: add|search your library for[^.]*lands?[^.]*onto the battlefield|play (an|two) additional lands?/', $text) ? 1.0 : 0.5;
            $rampCards++;
        }
        if (isset($profile['roles']['draw']) && $mv <= 2) $draw++;
        if (isset($profile['cares']['landfall'])) $landfall++;
    }
    $average = $count ? $mvSum / $count : 3.0;
    $parts = [];
    $total = 37;
    $curve = (int)round(($average - 3.0) * 4);
    $parts[] = ['Curva média ' . number_format($average, 2, ',', '.') . ' (referência 3,00)', $curve];
    $rampDelta = -min(6, (int)round($ramp / 3));
    $parts[] = [$rampCards . ' peça(s) de aceleração até 3 manas', $rampDelta];
    $drawDelta = -min(2, intdiv($draw, 5));
    if ($drawDelta) $parts[] = [$draw . ' carta(s) de compra até 2 manas', $drawDelta];
    $commanderProfile = deckScoreProfile($commander);
    if (isset($commanderProfile['cares']['landfall']) || $landfall >= 5) $parts[] = ['Deck que aproveita terrenos entrando (' . ($landfall + (isset($commanderProfile['cares']['landfall']) ? 1 : 0)) . ' cartas)', 2];
    $commanderMv = (float)($commander['cmc'] ?? 0);
    if ($commanderMv >= 6) $parts[] = ['Comandante de custo ' . (int)$commanderMv, 1];
    elseif ($commanderMv > 0 && $commanderMv <= 2) $parts[] = ['Comandante de custo ' . (int)$commanderMv, -1];
    foreach ($parts as [, $delta]) $total += $delta;
    return ['total' => max(31, min(42, $total)), 'raw' => $total, 'parts' => $parts, 'average' => $average, 'spells' => $count];
}

/** Terrenos não básicos na identidade e legais em Commander: os da coleção e, se pedido, os mais jogados do catálogo. */
function deckLandOptions(int $deckId, array $commander, array $identity, bool $includeMissing, array $extraLogicalIds = []): array
{
    // 1) Quais cartas lógicas considerar: os terrenos da coleção (poucas linhas) e, com compra, os mais jogados do catálogo.
    $landSql = "split_part(COALESCE(c.type_line,''),' // ',1) ILIKE '%Land%' AND COALESCE(c.type_line,'') NOT ILIKE '%Basic%'";
    $ownedRows = deckQuery("SELECT DISTINCT c.id::text AS id, COALESCE(c.oracle_id,c.id)::text AS logical_id FROM builder_collection b JOIN cards c ON c.id=b.scryfall_id WHERE b.user_id=? AND {$landSql}", [deckOwnerId()])->fetchAll();
    $logical = array_column($ownedRows, 'logical_id');
    if ($includeMissing) $logical = array_merge($logical, deckQuery("SELECT DISTINCT COALESCE(c.oracle_id,c.id)::text FROM cards c WHERE c.edhrec_rank_cached <= 6000 AND {$landSql}")->fetchAll(PDO::FETCH_COLUMN));
    $uuidPattern = '/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/i';
    foreach ($extraLogicalIds as $extraId) if (is_string($extraId) && preg_match($uuidPattern, $extraId)) $logical[] = $extraId;
    if (!$logical) return [];
    // 2) Uma impressão por carta (a da coleção primeiro), escolhida só pelo id; sem JOINs com a coleção, que faziam o
    //    PostgreSQL reagrupar a coleção para cada impressão (~100 ms). O registro completo é lido só da escolhida.
    $rows = deckQuery("SELECT pick.logical_id, c.* FROM (
            SELECT DISTINCT ON (COALESCE(c.oracle_id,c.id)) c.id, COALESCE(c.oracle_id,c.id)::text AS logical_id
            FROM cards c
            WHERE COALESCE(c.oracle_id,c.id) = ANY(?::uuid[]) AND {$landSql}
                AND c.legalities->>'commander'='legal' AND c.color_identity <@ ?::jsonb AND c.layout NOT IN ('token','double_faced_token','art_series')
            ORDER BY COALESCE(c.oracle_id,c.id), (c.id = ANY(?::uuid[])) DESC, (c.lang='en') DESC, (c.local_image IS NOT NULL) DESC, c.released_at DESC NULLS LAST
        ) pick JOIN cards c ON c.id = pick.id",
        ['{' . implode(',', array_unique($logical)) . '}', json_encode($identity), '{' . implode(',', array_column($ownedRows, 'id')) . '}'])->fetchAll();
    // 3) Cópias na coleção e em outros decks, por carta lógica.
    $owned = deckOwnedLogicalMap();
    $usage = deckUsageElsewhere($deckId, array_column($rows, 'logical_id'));
    foreach ($rows as &$row) {
        $row['owned'] = (int)($owned[$row['logical_id']] ?? 0);
        $row['other_used'] = (int)($usage[$row['logical_id']]['used'] ?? 0);
        $row['free'] = max(0, $row['owned'] - $row['other_used']);
    }
    unset($row);
    $options = [];
    foreach ($rows as $row) $options[strtolower($row['logical_id'])] = $row;
    return $options;
}

/** Cores (da identidade) que o terreno gera ou busca. Terrenos que buscam básicos contam como as cores que podem trazer. */
function deckLandColors(array $land, array $colors): array
{
    $produced = deckScoreProducedMana($land);
    $text = strtolower(deckText($land));
    if (preg_match('/search your library for (an? |up to \w+ )?(basic land|land) cards?/', $text)) $produced = array_merge($produced, $colors);
    foreach (DECK_BASIC_LANDS as $color => $basic) if ($color !== 'C' && preg_match('/search your library for[^.]*\b' . strtolower($basic) . '\b/', $text)) $produced[] = $color;
    if (preg_match('/any color|mana of any type|any one color/', $text)) $produced = array_merge($produced, $colors);
    return array_values(array_intersect($colors, array_unique($produced)));
}

/**
 * Pontuação de um terreno não básico para este deck (null = não serve).
 * Considera as cores úteis (pela participação nos custos), entrar virado, desvantagens, habilidades além de mana e o EDHREC.
 */
function deckLandScore(array $land, array $colors, array $share, bool $multicolor, ?float $synergy): ?array
{
    $text = strtolower(preg_replace('/\([^)]*\)/', '', deckText($land)) ?? '');
    $landColors = deckLandColors($land, $colors);
    $useful = array_values(array_filter($landColors, fn($color) => ($share[$color] ?? 0) > 0));
    $fix = array_sum(array_map(fn($color) => $share[$color], $useful));
    $reasons = [];
    $score = 0.0;
    if ($multicolor) {
        $score += 40 * $fix + (count($useful) >= 2 ? 16 : 0) + (count($useful) >= 3 ? 6 : 0);
        if (count($useful) >= 2) $reasons[] = 'Gera ' . implode(', ', $useful);
        elseif ($useful) $reasons[] = 'Gera ' . $useful[0];
    } else {
        $score += $useful ? 22 : 0;
        if ($useful) $reasons[] = 'Gera ' . $useful[0];
    }
    $utilityOnly = !$useful;
    $sacrificesLands = (bool)preg_match('/(when|whenever) [^.]*enters[^.]*sacrifice (two|a|an) (other )?lands?|sacrifice two lands/', $text);
    if ($sacrificesLands) return null;
    if (preg_match('/(this land|~) enters tapped\.|enters the battlefield tapped\.|enters tapped and doesn\'t untap/', $text) && !preg_match('/tapped unless|you may pay|if you control/', $text)) {
        $score -= $multicolor ? 10 : 16; $reasons[] = 'Entra virado';
    } elseif (preg_match('/tapped unless|you may pay \d+ life\. if you don\'t, it enters tapped/', $text)) {
        $score -= 3; $reasons[] = 'Entra virado em alguns casos';
    }
    if (preg_match('/return a land you control to its owner\'s hand/', $text)) { $score -= 4; $reasons[] = 'Devolve um terreno'; }
    if (preg_match('/deals 1 damage to you|pay 1 life/', $text)) $score -= 2;
    if (preg_match('/\{\d\}, \{t\}: add \{/', $text) && !preg_match('/\{t\}: add \{c\}/', $text)) { $score -= 6; $reasons[] = 'Precisa de mana para filtrar'; }
    if (preg_match('/doesn\'t untap|don\'t untap/', $text)) $score -= 20;
    // Habilidades além de gerar mana: terrenos utilitários.
    $abilities = array_filter(preg_split('/\n/', $text) ?: [], fn($line) => preg_match('/:/', $line) && !preg_match('/^\{t\}(, pay 1 life)?: add\b|^\{t\}: add\b/', trim($line)));
    if ($abilities || preg_match('/\bcycling\b|\bchannel\b|when (this land|~) enters, (scry|surveil|draw|you gain)/', $text)) { $score += 7; $reasons[] = 'Tem utilidade extra'; }
    if ($utilityOnly && !$abilities && !preg_match('/\bcycling\b|\bchannel\b/', $text)) $score -= 20;
    $rank = isset($land['edhrec_rank_cached']) && $land['edhrec_rank_cached'] !== null ? (int)$land['edhrec_rank_cached'] : null;
    if ($rank !== null) $score += max(0, 12 - $rank / 500);
    if ($synergy !== null && $synergy > 0) { $score += min(15, $synergy * 30); $reasons[] = 'Sinergia EDHREC com a comandante'; }
    if ($utilityOnly && $synergy === null && ($rank === null || $rank > 2500)) $score -= 10;
    return ['score' => round($score, 1), 'colors' => $landColors, 'reasons' => $reasons, 'utility_only' => $utilityOnly];
}

/** Uma impressão de cada básico: a da coleção com mais cópias que ainda não está no deck; senão uma em inglês com imagem. */
function deckBasicPrintings(array $colors, array $takenPrintings, int $deckId): array
{
    if (!$colors) return [];
    $names = array_map(fn($color) => DECK_BASIC_LANDS[$color], $colors);
    $rows = deckQuery(deckOwnedSql($deckId) . "SELECT DISTINCT ON (c.name) c.*, COALESCE(bc.quantity,0) AS owned_printing,
            GREATEST(0, COALESCE(o.owned,0) - COALESCE(u.used,0)) AS free
        FROM cards c
        LEFT JOIN owned o ON o.logical_id=COALESCE(c.oracle_id,c.id)
        LEFT JOIN used u ON u.logical_id=COALESCE(c.oracle_id,c.id)
        LEFT JOIN " . deckCollectionPrintingSql() . " bc ON bc.scryfall_id=c.id
        WHERE c.name = ANY(?::text[]) AND c.type_line ILIKE 'Basic Land%' AND c.type_line NOT ILIKE '%Snow%' AND NOT (c.id::text = ANY(?::text[]))
            AND c.layout='normal' AND c.lang IN ('en','pt')
        ORDER BY c.name, COALESCE(bc.quantity,0) DESC, (c.lang='en') DESC, (c.local_image IS NOT NULL) DESC, c.released_at DESC NULLS LAST",
        ['{' . implode(',', $names) . '}', '{' . implode(',', array_keys($takenPrintings)) . '}'])->fetchAll();
    $byName = [];
    foreach ($rows as $row) $byName[$row['name']] = $row;
    $result = [];
    foreach ($colors as $color) if (isset($byName[DECK_BASIC_LANDS[$color]])) $result[$color] = $byName[DECK_BASIC_LANDS[$color]];
    return $result;
}

/**
 * Opções do plano vindas do formulário (GET na prévia, POST ao aplicar): meta de terrenos, incluir terrenos fora da coleção
 * e terrenos descartados — os já descartados (land_skip) mais os mostrados na prévia e desmarcados (land_shown − land_keep).
 */
function deckLandRequestOptions(array $source): array
{
    $uuid = fn($value) => is_string($value) && preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/i', $value);
    $list = fn(string $key) => array_map('strtolower', array_values(array_filter((array)($source[$key] ?? []), $uuid)));
    $skip = $list('land_skip');
    if (array_key_exists('land_shown', $source)) $skip = array_merge($skip, array_diff($list('land_shown'), $list('land_keep')));
    $total = filter_var($source['land_total'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 20, 'max_range' => 60]]);
    // land_buy_set indica que o formulário mostrou a opção; sem ele, vale a escolha salva no deck.
    $buy = array_key_exists('land_buy_set', $source) || array_key_exists('land_buy', $source) ? ($source['land_buy'] ?? '') === '1' : null;
    // Percentual mínimo de básicos: vazio volta ao automático da identidade de cor.
    $basicShare = null;
    if (array_key_exists('land_basic_share', $source)) {
        $percent = filter_var($source['land_basic_share'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 100]]);
        $basicShare = $percent === false ? null : $percent / 100;
    }
    return ['total' => $total === false ? null : $total, 'buy' => $buy, 'basic_share' => $basicShare,
        'basic_share_set' => array_key_exists('land_basic_share', $source), 'skip' => array_slice(array_values(array_unique($skip)), 0, 200)];
}

/** Aplica o plano: troca os terrenos automáticos anteriores pelos novos, sem passar de 100 cartas. */
function deckApplyLandPlan(int $deckId, array $plan): int
{
    $added = 0;
    db()->beginTransaction();
    try {
        deckQuery("DELETE FROM builder_items WHERE deck_id=? AND stage='deck' AND role=?", [$deckId, DECK_AUTO_LAND_ROLE]);
        foreach ($plan['picks'] as $pick) {
            if ($pick['availability'] === 'candidate') {
                deckQuery("UPDATE builder_items SET stage='deck', quantity=1 WHERE deck_id=? AND card_id=?::uuid AND stage='candidate'", [$deckId, $pick['card']['id']]);
            } else {
                deckQuery("INSERT INTO builder_items(deck_id,card_id,stage,quantity,role) VALUES (?,?,'deck',1,?) ON CONFLICT (deck_id,card_id) DO UPDATE SET stage='deck', quantity=1, role=EXCLUDED.role", [$deckId, $pick['card']['id'], DECK_AUTO_LAND_ROLE]);
            }
            $added++;
        }
        foreach ($plan['basics'] as $basic) {
            deckQuery("INSERT INTO builder_items(deck_id,card_id,stage,quantity,role) VALUES (?,?,'deck',?,?) ON CONFLICT (deck_id,card_id) DO UPDATE SET stage='deck', quantity=EXCLUDED.quantity, role=EXCLUDED.role", [$deckId, $basic['card']['id'], $basic['quantity'], DECK_AUTO_LAND_ROLE]);
            $added += $basic['quantity'];
        }
        $count = (int)deckQuery("SELECT COALESCE(SUM(quantity),0) FROM builder_items WHERE deck_id=? AND stage='deck'", [$deckId])->fetchColumn() + 1;
        if ($count > 100) throw new RuntimeException('Os terrenos passariam de 100 cartas. Recarregue a página e tente de novo.');
        db()->commit();
    } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); throw $e; }
    return $added;
}
