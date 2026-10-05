<?php
declare(strict_types=1);

/**
 * Formatos de deck: as regras que mudam de um formato para outro ficam aqui, num lugar só.
 *
 * Dois grupos:
 *  - com líder (Commander, Pauper Commander, Brawl, Standard Brawl, Duel Commander, Oathbreaker): uma carta
 *    fica na zona de comando, as outras respeitam a identidade de cor dela e cada carta entra uma vez;
 *  - construídos de 60 cartas (Standard, Pioneer, Modern, Legacy, Vintage, Pauper, Premodern): mínimo de 60,
 *    até 4 cópias, sideboard de até 15.
 *
 * A legalidade de cada carta vem do Scryfall (cards.legalities). As ajudas de montagem usam o EDHREC nos formatos
 * com líder e as listas de torneio publicadas pelo MTGO (deck_meta.php) nos construídos e no Duel Commander.
 * Carregado por deck_library.php.
 */

const DECK_DEFAULT_FORMAT = 'commander';

function deckFormats(): array
{
    static $formats = null;
    if ($formats !== null) return $formats;
    $leader = ['group' => 'leader', 'copies' => 1, 'sideboard' => 0, 'exact' => true, 'players' => 4, 'free_mulligan' => true, 'hand' => 7, 'meta' => null, 'edhrec' => true, 'signature' => false, 'brackets' => false];
    $constructed = ['group' => 'constructed', 'leader' => null, 'copies' => 4, 'sideboard' => 15, 'size' => 60, 'exact' => false, 'life' => 20, 'commander_damage' => 0,
        'players' => 2, 'free_mulligan' => false, 'hand' => 7, 'edhrec' => false, 'signature' => false, 'brackets' => false];
    return $formats = [
        'commander' => [
            'name' => 'Commander', 'short' => 'EDH', 'legality' => 'commander', 'size' => 100, 'life' => 40, 'commander_damage' => 21,
            'leader' => 'commander', 'leader_label' => 'Comandante', 'brackets' => true,
            'leader_rule' => 'Uma criatura lendária (ou uma carta que diga que pode ser sua comandante) e 99 cartas na identidade de cor dela.',
            'summary' => 'O formato mais jogado no papel: 100 cartas, uma de cada, partidas de 3 ou 4 jogadores.',
        ] + $leader,
        'paupercommander' => [
            'name' => 'Pauper Commander', 'short' => 'PDH', 'legality' => 'paupercommander', 'size' => 100, 'life' => 30, 'commander_damage' => 16,
            'leader' => 'pauper', 'leader_label' => 'Comandante',
            'leader_rule' => 'Uma criatura impressa como incomum; as outras 99 cartas precisam ter sido impressas como comuns.',
            'summary' => 'Commander barato: comandante incomum, 99 comuns, 30 de vida.',
        ] + $leader,
        'brawl' => [
            'name' => 'Brawl', 'short' => 'Brawl', 'legality' => 'brawl', 'size' => 100, 'life' => 25, 'life_multiplayer' => 30, 'commander_damage' => 0,
            'leader' => 'brawl', 'leader_label' => 'Comandante',
            'leader_rule' => 'Uma criatura ou um planeswalker lendário, com as cartas do MTG Arena (o antigo Historic Brawl).',
            'summary' => 'O Commander do MTG Arena: 100 cartas, 25 de vida a dois, sem dano de comandante.',
        ] + $leader,
        'standardbrawl' => [
            'name' => 'Standard Brawl', 'short' => 'Std Brawl', 'legality' => 'standardbrawl', 'size' => 60, 'life' => 25, 'life_multiplayer' => 30, 'commander_damage' => 0,
            'leader' => 'brawl', 'leader_label' => 'Comandante',
            'leader_rule' => 'Uma criatura ou um planeswalker lendário, só com cartas do Standard.',
            'summary' => 'Brawl de 60 cartas com a rotação do Standard.',
        ] + $leader,
        'duel' => [
            'name' => 'Duel Commander', 'short' => 'Duel', 'legality' => 'duel', 'size' => 100, 'life' => 20, 'commander_damage' => 0, 'players' => 2, 'free_mulligan' => false,
            'leader' => 'commander', 'leader_label' => 'Comandante', 'meta' => 'duel-commander',
            'leader_rule' => 'As regras de construção do Commander, com lista de banidas própria para partidas a dois e 20 de vida.',
            'summary' => 'Commander competitivo a dois, com torneios no MTGO.',
        ] + $leader,
        'oathbreaker' => [
            'name' => 'Oathbreaker', 'short' => 'OB', 'legality' => 'oathbreaker', 'size' => 60, 'life' => 20, 'commander_damage' => 0,
            'leader' => 'oathbreaker', 'leader_label' => 'Oathbreaker', 'signature' => true, 'edhrec' => false,
            'leader_rule' => 'Um planeswalker como oathbreaker e uma mágica instantânea ou feitiço como feitiço de assinatura, os dois na zona de comando.',
            'summary' => '60 cartas com um planeswalker e um feitiço de assinatura na zona de comando.',
        ] + $leader,
        'standard' => [
            'name' => 'Standard', 'short' => 'STD', 'legality' => 'standard', 'meta' => 'standard',
            'summary' => 'As coleções mais recentes; o formato mais jogado no MTG Arena.',
        ] + $constructed,
        'pioneer' => [
            'name' => 'Pioneer', 'short' => 'PIO', 'legality' => 'pioneer', 'meta' => 'pioneer',
            'summary' => 'Coleções desde Return to Ravnica (2012), sem rotação.',
        ] + $constructed,
        'modern' => [
            'name' => 'Modern', 'short' => 'MOD', 'legality' => 'modern', 'meta' => 'modern',
            'summary' => 'Coleções desde a Oitava Edição (2003); o construído sem rotação mais jogado no papel.',
        ] + $constructed,
        'legacy' => [
            'name' => 'Legacy', 'short' => 'LEG', 'legality' => 'legacy', 'meta' => 'legacy',
            'summary' => 'Quase todas as cartas da história, com lista de banidas.',
        ] + $constructed,
        'vintage' => [
            'name' => 'Vintage', 'short' => 'VIN', 'legality' => 'vintage', 'meta' => 'vintage',
            'summary' => 'Todas as cartas; as restritas entram com uma cópia só.',
        ] + $constructed,
        'pauper' => [
            'name' => 'Pauper', 'short' => 'PAU', 'legality' => 'pauper', 'meta' => 'pauper',
            'summary' => 'Só cartas impressas como comuns: barato e muito jogado no MTGO.',
        ] + $constructed,
        'premodern' => [
            'name' => 'Premodern', 'short' => 'PRE', 'legality' => 'premodern', 'meta' => 'premodern',
            'summary' => 'Cartas de Fourth Edition a Scourge (1995–2003), com a moldura antiga.',
        ] + $constructed,
    ];
}

/** Informações de um formato; chave desconhecida vira Commander. */
function deckFormatInfo(?string $key): array
{
    $formats = deckFormats();
    $key = strtolower(trim((string)$key));
    if (!isset($formats[$key])) $key = DECK_DEFAULT_FORMAT;
    return $formats[$key] + ['key' => $key];
}

function deckFormatOf(?array $deck): array
{
    return deckFormatInfo((string)($deck['format'] ?? DECK_DEFAULT_FORMAT));
}

/**
 * Formato do deck aberto nesta requisição. As funções de pontuação, terrenos e sugestões leem daqui o tamanho,
 * a legalidade e o limite de cópias, sem precisar receber o formato em cada chamada.
 */
function deckCurrentFormat(?array $set = null): array
{
    static $current = null;
    if ($set !== null) $current = $set;
    return $current ?? deckFormatInfo(DECK_DEFAULT_FORMAT);
}

function deckFormatHasLeader(array $format): bool
{
    return $format['leader'] !== null;
}

/** Formatos agrupados para os seletores (criar, importar, trocar). */
function deckFormatGroups(): array
{
    $groups = ['leader' => ['label' => 'Com comandante (zona de comando)', 'formats' => []], 'constructed' => ['label' => 'Construído de 60 cartas', 'formats' => []]];
    foreach (deckFormats() as $key => $format) $groups[$format['group']]['formats'][$key] = $format;
    return $groups;
}

/** Opções <option> agrupadas, com o formato escolhido marcado. */
function deckFormatOptionsHtml(string $selected = DECK_DEFAULT_FORMAT): string
{
    $html = '';
    foreach (deckFormatGroups() as $group) {
        $html .= '<optgroup label="' . h($group['label']) . '">';
        foreach ($group['formats'] as $key => $format) {
            $html .= '<option value="' . h($key) . '"' . ($key === $selected ? ' selected' : '') . ' data-summary="' . h($format['summary']) . '">' . h($format['name']) . ' · ' . ($format['group'] === 'leader' ? $format['size'] . ' cartas' : '60+ e sideboard') . '</option>';
        }
        $html .= '</optgroup>';
    }
    return $html;
}

/** Condição SQL: a carta é legal no formato (o alias é o da tabela cards). "restricted" do Vintage conta como legal. */
function deckFormatLegalSql(array $format, string $alias = 'c'): string
{
    $key = preg_replace('/[^a-z]/', '', (string)$format['legality']);
    return "({$alias}.legalities->>'{$key}') IN ('legal','restricted')";
}

/** Condição SQL da carta que pode ser o líder do formato (comandante, oathbreaker). */
function deckLeaderSql(array $format, string $alias = 'c'): string
{
    $front = "COALESCE({$alias}.raw->'card_faces'->0->>'type_line',{$alias}.type_line,'')";
    $legal = deckFormatLegalSql($format, $alias);
    return match ($format['leader']) {
        // Pauper Commander: criatura que já foi impressa como incomum em papel. O Scryfall marca essas cartas como
        // "not_legal" no formato (não podem estar entre as 99), então a legalidade não entra aqui.
        'pauper' => "({$front} LIKE '%Creature%' AND EXISTS (SELECT 1 FROM cards pu WHERE pu.oracle_id={$alias}.oracle_id AND pu.rarity='uncommon' AND COALESCE(pu.raw->>'digital','false')='false') AND COALESCE({$alias}.legalities->>'paupercommander','')<>'banned')",
        'brawl' => "(({$front} LIKE '%Legendary%' AND ({$front} LIKE '%Creature%' OR {$front} LIKE '%Planeswalker%')) OR {$alias}.commander_eligible) AND {$legal}",
        'oathbreaker' => "({$front} LIKE '%Planeswalker%' AND {$legal})",
        default => "{$alias}.commander_eligible AND {$legal}",
    };
}

/** Condição SQL do feitiço de assinatura do Oathbreaker (instantânea ou feitiço legal). */
function deckSignatureSql(array $format, string $alias = 'c'): string
{
    $front = "COALESCE({$alias}.raw->'card_faces'->0->>'type_line',{$alias}.type_line,'')";
    return "(({$front} LIKE '%Instant%' OR {$front} LIKE '%Sorcery%') AND " . deckFormatLegalSql($format, $alias) . ')';
}

/** Situação da carta no formato: legal, restricted, banned, not_legal. */
function deckFormatLegality(array $card, array $format): string
{
    $legalities = $card['legalities'] ?? '{}';
    if (is_string($legalities)) $legalities = json_decode($legalities, true) ?: [];
    return (string)($legalities[$format['legality']] ?? 'legal');
}

/**
 * Quantas cópias de uma carta o formato permite (null = sem limite).
 * Básicos e cartas como Relentless Rats não têm limite; "up to seven/nine" vale o número escrito;
 * no Vintage, as restritas ficam com uma.
 */
function deckCopyLimit(array $card, array $format): ?int
{
    $type = (string)($card['type_line'] ?? '');
    if (str_contains($type, 'Basic') && str_contains($type, 'Land')) return null;
    $text = function_exists('deckText') ? deckText($card) : (string)($card['oracle_text'] ?? '');
    if (preg_match('/deck can have any number of cards named/i', $text)) return null;
    if (preg_match('/deck can have up to (\w+) cards named/i', $text, $m)) {
        $words = ['one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10];
        return $words[strtolower($m[1])] ?? (int)$m[1] ?: $format['copies'];
    }
    if (deckFormatLegality($card, $format) === 'restricted') return 1;
    return (int)$format['copies'];
}

/** Texto do limite para mensagens ("1 cópia", "até 4 cópias"). */
function deckCopyLimitLabel(array $format): string
{
    return $format['copies'] === 1 ? $format['name'] . ' permite 1 cópia de cada carta (básicos à parte)' : $format['name'] . ' permite até ' . $format['copies'] . ' cópias de cada carta (básicos à parte)';
}

/** Tamanho alvo do deck: o exato dos formatos com líder, o mínimo dos construídos. */
function deckFormatSizeLabel(array $format): string
{
    return $format['exact'] ? (string)$format['size'] : $format['size'] . '+';
}

/**
 * Situação do tamanho: 'ok', 'short' ou 'over' (só para formatos de tamanho exato) e a mensagem.
 * $count inclui o líder e o feitiço de assinatura; $side é o sideboard.
 */
function deckFormatSizeStatus(array $format, int $count, int $side = 0): array
{
    if ($format['exact']) {
        if ($count === $format['size']) return ['ok', $format['size'] . ' cartas: lista completa.'];
        return $count < $format['size'] ? ['short', 'Faltam ' . ($format['size'] - $count) . ' para ' . $format['size'] . '.'] : ['over', ($count - $format['size']) . ' acima de ' . $format['size'] . '.'];
    }
    if ($count < $format['size']) return ['short', 'Faltam ' . ($format['size'] - $count) . ' para o mínimo de ' . $format['size'] . '.'];
    if ($side > $format['sideboard']) return ['over', 'Sideboard com ' . $side . ' cartas: o máximo é ' . $format['sideboard'] . '.'];
    return ['ok', $count . ' cartas no deck' . ($format['sideboard'] ? ' e ' . $side . ' no sideboard' : '') . '.'];
}

/** Vida inicial no formato, com a regra de multijogador do Brawl. */
function deckFormatLife(array $format, int $players = 2): int
{
    return $players > 2 && isset($format['life_multiplayer']) ? (int)$format['life_multiplayer'] : (int)$format['life'];
}

/** Cores de um deck sem comandante: a união da identidade de cor das cartas aprovadas (no deck, não nas candidatas). */
function deckColorsFromItems(array $items): array
{
    $colors = [];
    foreach ($items as $item) {
        if (($item['stage'] ?? 'deck') !== 'deck') continue;
        foreach (json_decode((string)($item['color_identity'] ?? '[]'), true) ?: [] as $color) $colors[$color] = true;
    }
    return array_values(array_intersect(['W', 'U', 'B', 'R', 'G'], array_keys($colors)));
}

/**
 * Confere se uma carta pode ir para o deck ou para o sideboard: limite de cópias do formato, tamanho exato
 * (formatos com líder) e tamanho máximo do sideboard. $same: a carta já está nessa etapa e só a quantidade muda.
 */
function deckCheckPlacement(array $format, int $deckId, array $card, string $stage, bool $same, int $quantity, int $fixedCards, int $previous = 0): void
{
    if (!in_array($stage, ['deck', 'sideboard'], true)) return;
    $limit = deckCopyLimit($card, $format);
    if ($limit !== null && $quantity > $limit) throw new RuntimeException(deckCopyLimitLabel($format) . ($limit !== $format['copies'] ? '; esta carta, ' . $limit . '.' : '.'));
    $current = (int)deckQuery('SELECT COALESCE(SUM(quantity),0) FROM builder_items WHERE deck_id=? AND stage=?', [$deckId, $stage])->fetchColumn() - ($same ? $previous : 0);
    if ($stage === 'deck' && $format['exact'] && $current + $fixedCards + $quantity > (int)$format['size']) {
        throw new RuntimeException('O deck já tem ' . ($current + $fixedCards) . ' de ' . $format['size'] . ' cartas. Use “Preparar upgrade” para escolher a carta que sairá.');
    }
    if ($stage === 'sideboard' && $current + $quantity > (int)$format['sideboard']) {
        throw new RuntimeException('O sideboard tem ' . $current . ' de ' . $format['sideboard'] . ' cartas: não cabe' . ($quantity > 1 ? 'm mais ' . $quantity : ' mais uma') . '.');
    }
}

/** Rótulo do número de associação de uma carta: sinergia/lift do EDHREC ou presença no meta do MTGO. */
function deckSynergyLabel(?string $metric, mixed $score, bool $long = false): string
{
    if ($score === null || $score === '') return '';
    $score = (float)$score;
    return match ($metric) {
        'lift' => 'Lift EDHREC: ' . number_format($score, 2, ',', '.') . ($long ? ' · sinergia não percentual' : ''),
        'meta' => 'Nos decks do meta parecidos: ' . (int)round($score * 100) . '%',
        'share' => 'Em ' . max(1, (int)round($score * 100)) . '% dos decks do formato',
        default => 'Sinergia EDHREC: ' . sprintf('%+.0f', $score * 100) . '%',
    };
}

/** Nomes das combinações de cores usados para batizar os arquétipos do meta. */
function deckColorComboName(array $colors): string
{
    $order = ['W', 'U', 'B', 'R', 'G'];
    $colors = array_values(array_intersect($order, array_map('strtoupper', $colors)));
    $key = implode('', $colors);
    $names = [
        '' => 'Incolor', 'W' => 'Mono-branco', 'U' => 'Mono-azul', 'B' => 'Mono-preto', 'R' => 'Mono-vermelho', 'G' => 'Mono-verde',
        'WU' => 'Azorius', 'UB' => 'Dimir', 'BR' => 'Rakdos', 'RG' => 'Gruul', 'WG' => 'Selesnya', 'WB' => 'Orzhov', 'UR' => 'Izzet', 'BG' => 'Golgari', 'WR' => 'Boros', 'UG' => 'Simic',
        'WUG' => 'Bant', 'WUB' => 'Esper', 'UBR' => 'Grixis', 'BRG' => 'Jund', 'WRG' => 'Naya', 'WBG' => 'Abzan', 'WUR' => 'Jeskai', 'UBG' => 'Sultai', 'WBR' => 'Mardu', 'URG' => 'Temur',
        'UBRG' => 'Sem branco', 'WBRG' => 'Sem azul', 'WURG' => 'Sem preto', 'WUBG' => 'Sem vermelho', 'WUBR' => 'Sem verde', 'WUBRG' => 'Cinco cores',
    ];
    return $names[$key] ?? $key;
}
