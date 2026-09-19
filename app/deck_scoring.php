<?php
declare(strict_types=1);

/**
 * Índice de Encaixe (0–100): mede quanto cada carta da seleção serve ao deck.
 *
 * nota = 100 × multiplicador_de_regras × Σ(peso_i × componente_i) / Σ(peso_i)  (+ bônus, limitado a 0..100)
 *
 * Componentes (0..1): encaixe com a comandante, conexões com o deck, função que falta, curva de mana,
 * exigência de cor, intenção do deck, dados do EDHREC, coleção e orçamento. Regras de Commander
 * (identidade, legalidade, Game Changers, destruição de terrenos em massa, turnos extras, combos de
 * duas cartas) viram bloqueios ou multiplicadores conforme o bracket escolhido para o deck.
 * Carregado por deck_library.php.
 */

const DECK_SCORE_COMPONENTS = [
    'commander' => 'Encaixe com a comandante',
    'deck' => 'Conexões com o deck',
    'roles' => 'Função que falta',
    'curve' => 'Curva de mana',
    'colors' => 'Exigência de cor',
    'terms' => 'Sua intenção',
    'edhrec' => 'Dados do EDHREC',
    'collection' => 'Na sua coleção',
    'price' => 'Orçamento',
];

const DECK_SCORE_ROLES = [
    'lands' => 'Terrenos', 'ramp' => 'Ramp', 'draw' => 'Compra', 'removal' => 'Remoção pontual', 'wipe' => 'Remoção em massa',
    'protection' => 'Proteção', 'recursion' => 'Recursão', 'tutor' => 'Tutores', 'plan' => 'Plano de jogo',
];

function deckScorePresets(): array
{
    return [
        'balanced' => ['label' => 'Equilibrado', 'description' => 'Sinergia e estrutura com o mesmo cuidado.',
            'weights' => ['commander' => 22, 'deck' => 16, 'roles' => 18, 'curve' => 10, 'colors' => 6, 'terms' => 8, 'edhrec' => 10, 'collection' => 6, 'price' => 4], 'bracket' => 3],
        'synergy' => ['label' => 'Sinergia primeiro', 'description' => 'Prioriza cartas que conversam com a comandante e entre si.',
            'weights' => ['commander' => 30, 'deck' => 25, 'roles' => 10, 'curve' => 6, 'colors' => 4, 'terms' => 10, 'edhrec' => 10, 'collection' => 3, 'price' => 2], 'bracket' => 3],
        'foundation' => ['label' => 'Base sólida', 'description' => 'Garante terrenos, ramp, compra, remoções e curva antes do resto.',
            'weights' => ['commander' => 14, 'deck' => 10, 'roles' => 30, 'curve' => 18, 'colors' => 10, 'terms' => 4, 'edhrec' => 8, 'collection' => 4, 'price' => 2], 'bracket' => 3],
        'budget' => ['label' => 'Coleção e orçamento', 'description' => 'Favorece o que você já tem e cartas baratas.',
            'weights' => ['commander' => 18, 'deck' => 12, 'roles' => 16, 'curve' => 8, 'colors' => 5, 'terms' => 5, 'edhrec' => 6, 'collection' => 18, 'price' => 12], 'bracket' => 2],
        'optimized' => ['label' => 'Otimizado', 'description' => 'Peso maior para dados da comunidade e bracket 4.',
            'weights' => ['commander' => 18, 'deck' => 16, 'roles' => 16, 'curve' => 12, 'colors' => 6, 'terms' => 2, 'edhrec' => 28, 'collection' => 0, 'price' => 2], 'bracket' => 4],
    ];
}

function deckScoreDefaultConfig(): array
{
    $preset = deckScorePresets()['balanced'];
    return [
        'preset' => 'balanced',
        'weights' => $preset['weights'],
        // Base comum para Commander: ~37 terrenos, 10 ramp, 10 compra, 8 remoções, 3 wipes.
        'targets' => ['lands' => 37, 'ramp' => 10, 'draw' => 10, 'removal' => 8, 'wipe' => 3, 'protection' => 3, 'recursion' => 2, 'tutor' => 2],
        'curve' => ['1' => 8, '2' => 14, '3' => 14, '4' => 10, '5' => 6, '6' => 4, '7' => 3],
        'bracket' => $preset['bracket'],
        'max_price' => 0,
        'thresholds' => ['advance' => 70, 'review' => 45],
        'targets_mode' => 'auto',
        // Completar com terrenos: meta salva (null = sugestão calculada pelo deck) e se inclui terrenos fora da coleção.
        'land_fill' => ['total' => null, 'buy' => false],
    ];
}

/** Mescla e valida a configuração salva/enviada com os padrões. */
function deckScoreConfig(mixed $raw): array
{
    $config = deckScoreDefaultConfig();
    if (is_string($raw)) $raw = json_decode($raw, true);
    if (!is_array($raw)) return $config;
    if (isset($raw['preset']) && (isset(deckScorePresets()[$raw['preset']]) || $raw['preset'] === 'custom')) $config['preset'] = (string)$raw['preset'];
    foreach (array_keys(DECK_SCORE_COMPONENTS) as $key) {
        if (isset($raw['weights'][$key]) && is_numeric($raw['weights'][$key])) $config['weights'][$key] = max(0, min(50, (int)$raw['weights'][$key]));
    }
    foreach (array_keys($config['targets']) as $key) {
        if (isset($raw['targets'][$key]) && is_numeric($raw['targets'][$key])) $config['targets'][$key] = max(0, min(60, (int)$raw['targets'][$key]));
    }
    foreach (array_keys($config['curve']) as $key) {
        if (isset($raw['curve'][$key]) && is_numeric($raw['curve'][$key])) $config['curve'][$key] = max(0, min(40, (int)$raw['curve'][$key]));
    }
    if (isset($raw['bracket']) && is_numeric($raw['bracket'])) $config['bracket'] = max(1, min(5, (int)$raw['bracket']));
    if (isset($raw['max_price']) && is_numeric($raw['max_price'])) $config['max_price'] = max(0, min(100000, round((float)$raw['max_price'], 2)));
    foreach (['advance', 'review'] as $key) {
        if (isset($raw['thresholds'][$key]) && is_numeric($raw['thresholds'][$key])) $config['thresholds'][$key] = max(0, min(100, (int)$raw['thresholds'][$key]));
    }
    if ($config['thresholds']['review'] > $config['thresholds']['advance']) $config['thresholds']['review'] = $config['thresholds']['advance'];
    if (array_sum($config['weights']) === 0) $config['weights'] = deckScoreDefaultConfig()['weights'];
    // Metas automáticas (calculadas para a comandante) ou personalizadas. Configurações antigas sem o campo
    // continuam automáticas enquanto as metas forem as padrão.
    $defaults = deckScoreDefaultConfig();
    $mode = (string)($raw['targets_mode'] ?? '');
    if (!in_array($mode, ['auto', 'manual'], true)) $mode = ($config['targets'] == $defaults['targets'] && $config['curve'] == $defaults['curve']) ? 'auto' : 'manual';
    $config['targets_mode'] = $mode;
    if (isset($raw['land_fill']['total']) && is_numeric($raw['land_fill']['total'])) $config['land_fill']['total'] = max(20, min(60, (int)$raw['land_fill']['total']));
    $config['land_fill']['buy'] = !empty($raw['land_fill']['buy']);
    return $config;
}

/**
 * Metas de função e curva para uma comandante.
 * 1) EDHREC: média de terrenos, curva média e taxa de inclusão das cartas de cada função nos decks dela;
 * 2) sem EDHREC: base comum ajustada pela leitura do texto e do custo da comandante.
 * Devolve ['targets' => [...], 'curve' => [...], 'source' => 'edhrec'|'text', 'notes' => [função => motivo], 'decks' => int].
 */
function deckDynamicTargets(array $commander): array
{
    static $cache = [];
    $key = (string)($commander['oracle_id'] ?: $commander['id']);
    if (isset($cache[$key])) return $cache[$key];
    $defaults = deckScoreDefaultConfig();
    $targets = $defaults['targets'];
    $curve = $defaults['curve'];
    $notes = [];
    $profile = function_exists('deckRelationProfile') ? deckRelationProfile($commander) : ['provides' => [], 'consumes' => [], 'tribes_cared' => []];
    $consumes = $profile['consumes'];
    $provides = $profile['provides'];
    $mv = (float)($commander['cmc'] ?? 0);
    $text = strtolower(deckText($commander));

    // Leitura do texto: ajustes pequenos sobre a base comum de Commander.
    if ($mv >= 6) { $targets['ramp'] += 3; $targets['lands'] += 1; $notes['ramp'] = 'Comandante de custo ' . (int)$mv . ': mais aceleração para conjurá-la cedo.'; }
    elseif ($mv >= 5) { $targets['ramp'] += 2; $notes['ramp'] = 'Comandante de custo ' . (int)$mv . ': um pouco mais de aceleração.'; }
    elseif ($mv <= 2) { $targets['ramp'] -= 2; $targets['lands'] -= 2; $notes['ramp'] = 'Comandante barata: menos aceleração e terrenos.'; }
    if (isset($consumes['landfall'])) { $targets['lands'] += 2; $targets['ramp'] += 2; $notes['lands'] = 'Ganha quando terrenos entram: mais terrenos e busca de terrenos.'; }
    if (isset($consumes['spells']) || isset($consumes['noncreature'])) { $targets['draw'] += 2; $targets['removal'] += 1; $notes['draw'] = 'Joga muitas mágicas: mais compra para não ficar sem cartas.'; }
    if (isset($consumes['graveyard']) || isset($consumes['death'])) { $targets['recursion'] += 3; $notes['recursion'] = 'Usa o cemitério ou mortes: mais recursão.'; }
    if (isset($consumes['equipment']) || isset($consumes['combat_self']) || preg_match('/equipped|enchanted creature|commander damage/', $text)) { $targets['protection'] += 3; $notes['protection'] = 'Precisa conectar ataques: proteger a comandante vale mais.'; }
    if (isset($provides['tokens']) || isset($consumes['wide']) || isset($consumes['tokens'])) { $targets['wipe'] = max(1, $targets['wipe'] - 1); $notes['wipe'] = 'Monta mesa larga: menos remoções em massa que também atingem você.'; }
    if ($profile['tribes_cared'] ?? []) { $targets['tutor'] = max(1, $targets['tutor'] - 1); }
    if ($mv >= 5) { $curve['2'] += 1; $curve['5'] -= 1; }
    if ($mv <= 3 && (isset($consumes['combat_team']) || isset($consumes['wide']))) { $curve['1'] += 2; $curve['2'] += 2; $curve['5'] -= 2; $curve['6'] -= 1; $curve['7'] -= 1; $notes['curve'] = 'Comandante agressiva e barata: curva mais baixa.'; }
    $source = 'text';
    $decks = 0;

    $insights = function_exists('deckCommanderInsights') ? deckCommanderInsights($commander) : null;
    $estimates = is_array($insights['role_estimates'] ?? null) ? $insights['role_estimates'] : [];
    if ($estimates) {
        $source = 'edhrec';
        $decks = (int)($insights['deck_count'] ?? 0);
        foreach ($estimates as $role => $value) {
            if ($value === null || !isset($targets[$role])) continue;
            // Mistura: a média do EDHREC domina, mas nunca some uma função essencial.
            $floor = ['lands' => 30, 'ramp' => 6, 'draw' => 6, 'removal' => 4, 'wipe' => 1, 'protection' => 1, 'recursion' => 1, 'tutor' => 0][$role];
            $ceiling = ['lands' => 42, 'ramp' => 18, 'draw' => 16, 'removal' => 14, 'wipe' => 6, 'protection' => 10, 'recursion' => 10, 'tutor' => 8][$role];
            $targets[$role] = max($floor, min($ceiling, (int)round((float)$value)));
            $notes[$role] = 'Média das listas desta comandante no EDHREC' . ($decks ? ' (' . number_format($decks, 0, ',', '.') . ' decks)' : '') . '.';
        }
    }
    if (is_array($insights['mana_curve'] ?? null) && array_sum($insights['mana_curve']) > 20) {
        $raw = array_fill_keys(array_keys($curve), 0);
        foreach ($insights['mana_curve'] as $cost => $amount) {
            $bucket = (string)max(1, min(7, (int)$cost));
            $raw[$bucket] += (int)$amount;
        }
        $nonland = 99 - $targets['lands'];
        $total = max(1, array_sum($raw));
        foreach ($raw as $bucket => $amount) $curve[$bucket] = (int)round($amount / $total * $nonland);
        $notes['curve'] = 'Curva média das listas desta comandante no EDHREC.';
        $source = 'edhrec';
    }
    foreach ($targets as $role => $value) $targets[$role] = max(0, min(60, (int)$value));
    foreach ($curve as $bucket => $value) $curve[$bucket] = max(0, min(40, (int)$value));
    return $cache[$key] = ['targets' => $targets, 'curve' => $curve, 'source' => $source, 'notes' => $notes, 'decks' => $decks];
}

/** Configuração do deck com metas automáticas aplicadas quando o modo for "auto". */
function deckScoreConfigFor(array $deck, ?array $commander): array
{
    $config = deckScoreConfig($deck['scoring_config'] ?? null);
    $config['dynamic'] = $commander ? deckDynamicTargets($commander) : null;
    if ($config['dynamic'] && $config['targets_mode'] === 'auto') {
        $config['targets'] = $config['dynamic']['targets'];
        $config['curve'] = $config['dynamic']['curve'];
    }
    return $config;
}

/** Lista oficial de Game Changers (atualização de 9/fev/2026, 53 cartas). */
function deckScoreGameChangers(): array
{
    return ['ad nauseam', 'ancient tomb', 'aura shards', 'biorhythm', "bolas's citadel", 'braids, cabal minion', 'chrome mox', 'coalition victory',
        'consecrated sphinx', 'crop rotation', 'cyclonic rift', 'demonic tutor', 'drannith magistrate', 'enlightened tutor', 'farewell', 'field of the dead',
        'fierce guardianship', 'force of will', "gaea's cradle", 'gamble', 'gifts ungiven', 'glacial chasm', 'grand arbiter augustin iv', 'grim monolith',
        'humility', 'imperial seal', 'intuition', "jeska's will", "lion's eye diamond", 'mana vault', "mishra's workshop", 'mox diamond', 'mystical tutor',
        'narset, parter of veils', 'natural order', 'necropotence', 'notion thief', 'opposition agent', 'orcish bowmasters', 'panoptic mirror', 'rhystic study',
        'seedborn muse', "serra's sanctum", 'smothering tithe', 'survival of the fittest', "teferi's protection", 'tergrid, god of fright',
        "tergrid, god of fright // tergrid's lantern", "thassa's oracle", 'the one ring', 'the tabernacle at pendrell vale', 'underworld breach', 'vampiric tutor', 'worldly tutor'];
}

/** Destruição de terrenos em massa: cartas conhecidas e padrões do texto. */
function deckScoreIsMassLandDenial(array $card, string $text): bool
{
    static $names = null;
    $names ??= array_fill_keys(['armageddon', 'ravages of war', 'jokulhaups', 'obliterate', 'decree of annihilation', 'ruination', 'wildfire', 'sunder',
        'winter orb', 'static orb', 'blood moon', 'magus of the moon', 'back to basics', 'tangle wire', 'rising waters', 'hokori, dust drinker', 'contamination',
        'boom // bust', 'catastrophe', 'epicenter', 'worldfire', 'impending disaster', 'destructive force', 'devastation', 'fall of the thran', 'numot, the devastator',
        'myojin of infinite rage', 'keldon firebombers', 'global ruin', 'thoughts of ruin'], true);
    if (isset($names[strtolower((string)$card['name'])])) return true;
    return (bool)preg_match("/destroy all lands|each player sacrifices (all|\\w+) lands|lands don't untap|nonbasic lands are mountains|lands? (don't|do not) untap during/", $text);
}

/** Recursos do catálogo: raridade das palavras e contagem de tipos de criatura. Cache de 7 dias. */
function deckScoreLexicon(): array
{
    static $lexicon = null;
    if ($lexicon !== null) return $lexicon;
    $load = static function (): array {
        $n = (int)deckQuery("SELECT COUNT(DISTINCT COALESCE(oracle_id,id)) FROM cards WHERE lang='en'")->fetchColumn();
        $rows = deckQuery("SELECT w, COUNT(*)::int AS df FROM (
                SELECT DISTINCT ON (COALESCE(oracle_id,id)) lower(COALESCE(oracle_text,'')) AS t FROM cards WHERE lang='en' ORDER BY COALESCE(oracle_id,id)
            ) x CROSS JOIN LATERAL (SELECT DISTINCT unnest(regexp_split_to_array(x.t,'[^a-z0-9+/]+')) AS w) y
            WHERE length(w) >= 4 GROUP BY w HAVING COUNT(*) BETWEEN 2 AND ?", [max(2, (int)($n / 12))])->fetchAll(PDO::FETCH_KEY_PAIR);
        $types = deckQuery("SELECT lower(t) AS t, COUNT(DISTINCT COALESCE(oracle_id,id))::int AS n FROM cards
            CROSS JOIN LATERAL unnest(string_to_array(trim(split_part(split_part(type_line,' // ',1),'—',2)),' ')) t
            WHERE lang='en' AND (type_line LIKE '%Creature%' OR type_line LIKE '%Kindred%' OR type_line LIKE '%Tribal%') AND t <> ''
            GROUP BY lower(t) HAVING COUNT(*) >= 2")->fetchAll(PDO::FETCH_KEY_PAIR);
        return ['n' => max(1, $n), 'df' => $rows, 'types' => $types];
    };
    return $lexicon = function_exists('catalogCached') ? catalogCached('scoring-lexicon-v1', $load, 604800) : ['n' => 1, 'df' => [], 'types' => []];
}

/** Biblioteca de características: [rótulo, peso, regex "produz", regex "se importa com"]. */
function deckScoreFeatureLibrary(): array
{
    static $library = null;
    return $library ??= [
        'treasure' => ['Tesouros', 1.0, '/create[^.]*\btreasure/', '/treasures? you control|sacrifice (a|an?|x|two|three|five|ten) treasures?|whenever[^.]*\btreasures?\b|for each treasure|ten or more treasures/'],
        'tokens' => ['Fichas', 1.0, '/create[^.]*\btokens?\b/', '/whenever (a|one or more|another)[^.]*\btokens?\b[^.]*(enter|die|leave)|tokens? you control|for each (creature )?token|if (an effect|you) would create[^.]*tokens?|populate/'],
        'death' => ['Criaturas morrendo', 1.2, '/sacrifice (a|an|another|x|one or more|two|three) (other )?(nontoken )?creatures?|destroy (all|each) creatures|each (player|opponent) sacrifices (a|two|x) creatures?/', '/whenever[^.]*\b(creature|creatures|permanent|permanents)\b[^.]*\bdies?\b|whenever[^.]*put into (a|your|an opponent\'s) graveyard from the battlefield/'],
        'sacrifice' => ['Sacrifício', 1.0, '/(^|\n|: |, )sacrifice (a|an|another)\b|as an additional cost to cast this spell, sacrifice/', '/whenever you sacrifice|sacrificed (a|another|this turn)/'],
        'counters' => ['Marcadores +1/+1', 1.0, '/(put|with|enters with|distribute)[^.]*\+1\/\+1 counters?|\bproliferate\b|\bevolve\b|\badapt\b|\bbolster\b/', '/with (a|one or more) \+1\/\+1 counters?|for each \+1\/\+1 counter|whenever[^.]*\+1\/\+1 counters? (is|are) put|\bproliferate\b|double the number of[^.]*counters|counters on (it|each)[^.]*\bequal\b/'],
        'draw' => ['Compra de cartas', 0.6, '/\bdraws? (a|an|one|two|three|four|x|that many|cards equal)\b/', '/whenever you draw|draw your second card|each card you draw/'],
        'discard' => ['Descarte', 0.9, '/\bdiscard (a|an|two|x|your hand|that card|one or more|any number)\b/', '/whenever you discard|\bmadness\b|discarded this turn/'],
        'lifegain' => ['Ganho de vida', 1.0, '/\bgains? (\d+|x|that much|life equal)\b[^.]*\blife\b|\blifelink\b/', '/whenever you gain life|if you would gain life|you gained life this turn|life total is greater/'],
        'drain' => ['Perda de vida dos oponentes', 1.0, '/(each opponent|target (player|opponent)|that player|defending player) loses (\d+|x|that much)|deals? (\d+|x) damage to each opponent/', '/whenever (an opponent|a player) loses life|opponents? (lost|loses) life this turn/'],
        'landfall' => ['Terrenos entrando', 1.2, '/search your library for[^.]*\blands? cards?\b[^.]*onto the battlefield|play (an|two|any number of) additional lands?|put (a|up to \w+|that|those) lands? cards?[^.]*onto the battlefield|return[^.]*lands? cards?[^.]*to the battlefield/', '/\blandfall\b|whenever a land (you control )?enters/'],
        'spells' => ['Instantâneas e feitiços', 1.0, null, '/whenever you (cast|copy) (an|a|your first|your second)? ?(instant|sorcery|noncreature)|instant (or|and) sorcery spells?|\bmagecraft\b|\bprowess\b/'],
        'artifacts' => ['Artefatos', 0.9, '/create[^.]*\b(artifact|treasure|clue|food|blood|map|powerstone|gold) tokens?/', '/whenever (an|another|one or more)[^.]*\bartifacts?\b[^.]*(enter|cast|leave|put into)|artifacts? you control|for each artifact|affinity for artifacts|\bimprovise\b|\bmetalcraft\b/'],
        'enchantments' => ['Encantamentos', 1.0, null, '/whenever (you cast )?(an|another)[^.]*\benchantment\b|enchantments? you control|for each enchantment|\bconstellation\b/'],
        'equipment' => ['Equipamentos e auras', 1.1, null, '/whenever[^.]*becomes? (equipped|enchanted|attached)|(auras?|equipment) you control|for each (aura|equipment)|equipped creatures? (you control )?(get|has|have)/'],
        'vehicles' => ['Veículos', 1.3, null, '/vehicles? you control|whenever[^.]*crews? (a|one or more) vehicles?|becomes crewed|crew abilities/'],
        'etb' => ['Efeitos de entrada', 1.0, '/when (this creature|~|this permanent|this artifact|this enchantment) enters/', '/exile (up to one |another |one or more )?target[^.]*(return (it|that card|those cards|them)|then return)[^.]*battlefield|triggers an additional time|whenever another (nontoken )?creature (you control )?enters/'],
        'graveyard' => ['Cemitério', 1.0, '/\bmills?\b|\bsurveil\b|put (the top|that card|those cards)[^.]*into your graveyard/', '/(from|in) your graveyard|\bflashback\b|\bescape\b|\bunearth\b|\bdelirium\b|\bthreshold\b|\bembalm\b|\beternalize\b|for each (creature )?card in your graveyard/'],
        'combat' => ['Ataque e dano de combate', 0.5, '/\b(flying|menace|trample|haste|double strike|can\'t be blocked)\b|additional combat phase|creatures you control get \+\d/', '/whenever[^.]*deals? combat damage to (a player|an opponent|one or more players)|whenever[^.]*\battacks\b/'],
        'theft' => ['Roubar e usar cartas alheias', 1.3, '/gain control of|exile the top[^.]*of (target|each) (opponent|player)|you may (cast|play)[^.]*(opponent|you don\'t own)/', '/you don\'t own|an opponent owns|spells you cast that you don\'t own/'],
        'goad' => ['Goad', 1.2, '/\bgoads?\b/', '/goaded creatures?|whenever a goaded/'],
        'minus' => ['Marcadores -1/-1', 1.1, '/-1\/-1 counters?/', '/-1\/-1 counters? (is|are) put|with -1\/-1 counters?/'],
        'legendary' => ['Lendárias', 0.8, null, '/legendary (spells?|creatures?|permanents?)|\bhistoric\b/'],
        'poison' => ['Veneno', 1.3, '/\binfect\b|\btoxic\b|poison counters?/', '/poison counters?|\bproliferate\b/'],
        'copy' => ['Cópias', 0.9, '/\bcop(y|ies) (target|it|that|this)|create a token that\'s a copy/', '/whenever you copy|if you would copy|copies? of (spells|permanents)/'],
    ];
}

const DECK_SCORE_STOPWORDS = ['this', 'that', 'with', 'your', 'from', 'into', 'onto', 'each', 'card', 'cards', 'turn', 'until', 'target', 'creature', 'creatures',
    'battlefield', 'control', 'player', 'players', 'opponent', 'opponents', 'mana', 'spell', 'spells', 'ability', 'abilities', 'when', 'whenever', 'then', 'they',
    'them', 'their', 'have', 'gets', 'gain', 'gains', 'deals', 'damage', 'enters', 'other', 'another', 'where', 'there', 'those', 'would', 'instead', 'more',
    'less', 'than', 'equal', 'number', 'only', 'time', 'times', 'library', 'hand', 'graveyard', 'permanent', 'permanents', 'counter', 'counters', 'cost', 'costs'];

/** Extrai o perfil de uma carta: texto normalizado, características, funções, tipos, cores. */
/**
 * Padrões (texto Oracle em minúsculas, sem lembretes) que identificam cada função.
 * Escritos de forma compatível com PCRE e com regex do PostgreSQL (\b vira \y no SQL).
 */
function deckScoreRolePatterns(): array
{
    return [
        'ramp' => ["\\badd (\\{[wubrgc]\\}|one mana|two mana|three mana|mana of any|x mana|\\{c\\}\\{c\\})|search your library for[^.]*\\blands? cards?\\b[^.]*(onto the battlefield|into your hand)|create[^.]*\\btreasure tokens?|play (an|two) additional lands?"],
        'draw' => ["\\bdraws? (two|three|four|x|that many|cards equal|a card for each)\\b|\\bdraw a card\\b[^.]*(whenever|at the beginning)|whenever[^.]*,? (you )?draw a card|\\binvestigate\\b|connives?\\b", "(^|\\n)draw (a|two) cards?"],
        'removal' => ["(destroy|exile) (up to one )?target (creature|artifact|enchantment|planeswalker|nonland permanent|permanent|creature or planeswalker|artifact or enchantment)|deals? (\\d+|x) damage to (target|any target|up to one target)|return target (creature|nonland permanent|permanent|artifact|enchantment)[^.]*to (its|their) owner's hand|target (player|opponent) sacrifices|counter target (spell|noncreature spell|creature spell)|fights? (target|up to one target)"],
        'wipe' => ["(destroy|exile) (all|each) (other )?(creatures|nonland permanents|artifacts|enchantments|permanents)|(all|each) creatures? gets? -\\d|deals? (\\d+|x) damage to each creature|return all (creatures|nonland permanents)|each player sacrifices (all|each)|\\boverload\\b"],
        'protection' => ["(creatures|permanents|commander)[^.]*you control (gain|have|get)[^.]*(hexproof|indestructible|protection|shroud|ward)|target (creature|permanent|artifact)[^.]*you control (gains?|gets?|has)[^.]*(hexproof|indestructible|protection from|shroud)|(equipped|enchanted) (creature|permanent) (has|gains|gets)[^.]*(hexproof|indestructible|protection from|shroud)|phases? out|spells? you control can't be countered|you have hexproof|prevent all (combat )?damage"],
        'recursion' => ["return (target|up to \\w+ target|all|each)[^.]*cards? from your graveyard to (your hand|the battlefield)|\\bregrowth\\b|return[^.]*from your graveyard to the battlefield"],
        'tutor' => ["search your library for (a|an|up to one|up to two|any) (?!basic land|land|forest|island|swamp|plains|mountain)[^.]*card"],
    ];
}

function deckScoreProfile(array $card): array
{
    static $cache = [];
    $key = (string)($card['oracle_id'] ?: $card['id']);
    if (isset($cache[$key])) return $cache[$key];
    $name = strtolower((string)$card['name']);
    $text = strtolower(deckText($card));
    $text = preg_replace('/\([^)]*\)/', '', $text) ?? $text; // remove lembretes
    foreach (array_unique([$name, explode(' // ', $name)[0], explode(',', $name)[0]]) as $alias) {
        if (strlen($alias) >= 3) $text = str_replace($alias, '~', $text);
    }
    $typeLine = strtolower((string)$card['type_line']);
    $frontType = explode(' // ', $typeLine)[0];
    [$superTypes, $subTypes] = array_pad(explode('—', $frontType, 2), 2, '');
    $subtypeList = array_values(array_filter(preg_split('/\s+/', trim($subTypes)) ?: []));

    $produces = [];
    $cares = [];
    foreach (deckScoreFeatureLibrary() as $feature => [$label, $weight, $produceRe, $caresRe]) {
        if ($produceRe && preg_match($produceRe, $text)) $produces[$feature] = true;
        if ($caresRe && preg_match($caresRe, $text)) $cares[$feature] = true;
    }
    if (str_contains($superTypes, 'instant') || str_contains($superTypes, 'sorcery')) $produces['spells'] = true;
    if (str_contains($superTypes, 'artifact')) $produces['artifacts'] = true;
    if (str_contains($superTypes, 'enchantment')) $produces['enchantments'] = true;
    if (in_array('equipment', $subtypeList, true) || in_array('aura', $subtypeList, true)) $produces['equipment'] = true;
    if (in_array('vehicle', $subtypeList, true)) $produces['vehicles'] = true;
    if (str_contains($superTypes, 'legendary')) $produces['legendary'] = true;
    if (str_contains($superTypes, 'land')) $produces['landfall'] = true;

    // Tribal: tipos de criatura que a carta tem e tipos que o texto cita (singular ou plural).
    $isCreatureLike = str_contains($superTypes, 'creature') || str_contains($superTypes, 'kindred') || str_contains($superTypes, 'tribal');
    // Subtipos de artefato/encantamento que aparecem em criaturas-artefato e não são tribos.
    static $notTribes = ['treasure' => 1, 'food' => 1, 'clue' => 1, 'blood' => 1, 'equipment' => 1, 'vehicle' => 1, 'aura' => 1, 'saga' => 1, 'shrine' => 1,
        'cartouche' => 1, 'curse' => 1, 'rune' => 1, 'class' => 1, 'room' => 1, 'background' => 1, 'role' => 1, 'map' => 1, 'gold' => 1, 'powerstone' => 1,
        'incubator' => 1, 'junk' => 1, 'fortification' => 1, 'contraption' => 1, 'attraction' => 1, 'spacecraft' => 1, 'planet' => 1, 'omen' => 1, 'case' => 1, 'lesson' => 1];
    $tribesProduced = [];
    // Relações são calculadas apenas entre as cartas da seleção. Não carregamos mais
    // um índice de todo o catálogo para tentar inferir uma "nota" global.
    if ($isCreatureLike) foreach ($subtypeList as $subtype) if (!isset($notTribes[$subtype])) $tribesProduced[$subtype] = true;
    $changeling = (bool)preg_match('/\bchangeling\b|is every creature type/', strtolower(deckText($card)));
    $words = array_flip(preg_split('/[^a-z0-9+\/-]+/', $text) ?: []);
    $tribesCared = [];
    $irregular = ['elf' => 'elves', 'dwarf' => 'dwarves', 'wolf' => 'wolves', 'werewolf' => 'werewolves', 'mouse' => 'mice', 'ox' => 'oxen', 'fungus' => 'fungi',
        'octopus' => 'octopuses', 'thief' => 'thieves', 'sheep' => 'sheep', 'fish' => 'fish', 'jellyfish' => 'jellyfish', 'cyclops' => 'cyclopes', 'sphinx' => 'sphinxes',
        'fox' => 'foxes', 'lich' => 'liches', 'witch' => 'witches', 'phoenix' => 'phoenixes', 'harpy' => 'harpies', 'fairy' => 'faeries'];

    $roles = [];
    if (str_contains($superTypes, 'land')) $roles['lands'] = true;
    else {
        foreach (deckScoreRolePatterns() as $role => $patterns) {
            foreach ($patterns as $pattern) if (preg_match('/' . $pattern . '/', $text)) { $roles[$role] = true; break; }
        }
        if (!$roles) $roles['plan'] = true;
    }

    $pips = array_fill_keys(['W', 'U', 'B', 'R', 'G'], 0);
    foreach (['W', 'U', 'B', 'R', 'G'] as $color) $pips[$color] = substr_count(strtoupper((string)$card['mana_cost']), $color);


    return $cache[$key] = [
        'text' => $text,
        'produces' => $produces,
        'cares' => $cares,
        'tribes_produced' => $tribesProduced,
        'tribes_cared' => $tribesCared,
        'changeling' => $changeling,
        'roles' => $roles,
        'is_land' => isset($roles['lands']),
        'mv' => (float)($card['cmc'] ?? 0),
        'pips' => $pips,
        'identity' => json_decode((string)($card['color_identity'] ?? '[]'), true) ?: [],
        'keywords' => array_map('strtolower', json_decode((string)($card['keywords'] ?? '[]'), true) ?: []),
        'rare_words' => [],
        'extra_turn' => (bool)preg_match('/take an extra turn|takes an extra turn/', $text),
        'produced_mana' => deckScoreProducedMana($card),
        'enters_tapped' => (bool)preg_match('/~ enters tapped(?! unless)|enters the battlefield tapped(?! unless)/', $text) && !preg_match('/unless|you may pay/', $text),
        'mld' => deckScoreIsMassLandDenial($card, $text),
    ];
}

/**
 * Pontuação direcional entre duas cartas: o que A produz × o que B procura, e vice-versa.
 * Devolve [pontos, motivos legíveis].
 */
function deckScorePair(array $a, array $b, bool $withWords = true): array
{
    $library = deckScoreFeatureLibrary();
    $points = 0.0;
    $reasons = [];
    foreach ($library as $feature => [$label, $weight]) {
        $aToB = isset($a['produces'][$feature]) && isset($b['cares'][$feature]);
        $bToA = isset($b['produces'][$feature]) && isset($a['cares'][$feature]);
        if ($aToB || $bToA) {
            $points += $weight * ($aToB && $bToA ? 1.4 : 1.0);
            $reasons[$feature] = $label;
        }
    }
    $sharedKeywords = array_diff(array_intersect($a['keywords'], $b['keywords']), ['flying', 'haste', 'vigilance', 'trample', 'reach', 'deathtouch', 'lifelink', 'first strike', 'menace', 'flash', 'defender', 'hexproof', 'indestructible', 'ward', 'equip', 'enchant']);
    foreach ($sharedKeywords as $keyword) { $points += 0.6; $reasons['kw:' . $keyword] = ucfirst($keyword); }
    if ($withWords && $a['rare_words'] && $b['rare_words']) {
        $shared = array_intersect_key($a['rare_words'], $b['rare_words']);
        arsort($shared);
        $wordPoints = min(1.5, array_sum($shared) / 12);
        if ($wordPoints > 0.25) {
            $points += $wordPoints;
            $reasons['words'] = 'termos em comum: ' . implode(', ', array_slice(array_keys($shared), 0, 3));
        }
    }
    return [$points, $reasons];
}

/** Relação explicável: o que a primeira carta oferece à segunda (e o inverso). */
function deckCardRelationship(array $from, array $to): array
{
    $offers = [];
    foreach (deckScoreFeatureLibrary() as $feature => [$label]) {
        if (isset($from['produces'][$feature]) && isset($to['cares'][$feature])) $offers[] = $label;
    }
    foreach ($to['tribes_cared'] as $tribe => $_) {
        if (isset($from['tribes_produced'][$tribe]) || $from['changeling']) $offers[] = ucfirst($tribe);
    }
    return array_values(array_unique($offers));
}

function deckScoreProducedMana(array $card): array
{
    $raw = $card['raw'] ?? null;
    if (is_string($raw)) $raw = json_decode($raw, true);
    $produced = is_array($raw) ? (array)($raw['produced_mana'] ?? []) : [];
    return array_values(array_intersect(array_map('strval', $produced), ['W', 'U', 'B', 'R', 'G', 'C']));
}

function deckScoreSaturate(float $raw, float $scale): float
{
    return $raw <= 0 ? 0.0 : 1 - exp(-$raw / $scale);
}

/**
 * Calcula o Índice de Encaixe de todas as cartas da seleção.
 * $items: linhas de builder_items + cards (c.*, stage, quantity, owned). $commander: carta da comandante.
 */
function deckScoreSelection(array $deck, ?array $commander, array $items, array $config): array
{
    $result = ['cards' => [], 'needs' => [], 'bracket' => $config['bracket'],
        'game_changers' => 0, 'open_slots' => 0, 'deck_count' => 0];
    if (!$commander) return $result;

    $gameChangers = array_flip(deckScoreGameChangers());
    $commanderProfile = deckScoreProfile($commander);
    $identity = $commanderProfile['identity'];
    $bracket = (int)$config['bracket'];

    // Estado atual do deck: só cartas aprovadas contam para metas e curva; candidatas são contadas à parte.
    $roleCounts = array_fill_keys(array_keys(DECK_SCORE_ROLES), 0.0);
    $candidateRoleCounts = array_fill_keys(array_keys(DECK_SCORE_ROLES), 0);
    $curveCounts = array_fill_keys(array_keys($config['curve']), 0.0);
    $pipTotals = array_fill_keys(['W', 'U', 'B', 'R', 'G'], 0.0);
    $gcCount = 0;
    $deckCount = 1;
    foreach ($commanderProfile['pips'] as $color => $amount) $pipTotals[$color] += $amount;
    $profiles = [];
    foreach ($items as $item) {
        $profile = deckScoreProfile($item);
        $profiles[$item['id']] = $profile;
        $factor = $item['stage'] === 'deck' ? 1.0 : 0.0;
        $quantity = max(1, (int)$item['quantity']);
        if ($item['stage'] === 'deck') $deckCount += $quantity;
        if ($factor <= 0) { foreach ($profile['roles'] as $role => $_) $candidateRoleCounts[$role]++; continue; }
        foreach ($profile['roles'] as $role => $_) $roleCounts[$role] += $factor * $quantity;
        if (!$profile['is_land']) {
            $bucket = (string)max(1, min(7, (int)round($profile['mv'])));
            $curveCounts[$bucket] += $factor * $quantity;
        }
        foreach ($profile['pips'] as $color => $amount) $pipTotals[$color] += $factor * $amount * $quantity;
        if (isset($gameChangers[strtolower((string)$item['name'])])) $gcCount += $factor >= 1 ? 1 : 0;
    }
    $pipSum = max(1.0, array_sum($pipTotals));
    $openSlots = max(0, 100 - $deckCount);
    $result['open_slots'] = $openSlots;
    $result['deck_count'] = $deckCount;
    $result['game_changers'] = $gcCount;

    $targets = $config['targets'];
    $targets['plan'] = max(0, 99 - array_sum($targets));
    foreach (DECK_SCORE_ROLES as $role => $label) {
        $result['needs'][$role] = ['label' => $label, 'target' => (int)$targets[$role], 'current' => $roleCounts[$role], 'candidates' => $candidateRoleCounts[$role], 'missing' => max(0, (int)ceil($targets[$role] - $roleCounts[$role]))];
    }

    // Não há uma nota nem uma classificação automática: cada relação é mostrada
    // como uma afirmação que o jogador pode aceitar ou ignorar.
    foreach ($items as $item) {
        $profile = $profiles[$item['id']];
        $quantity = max(1, (int)$item['quantity']);
        $notes = [];
        $blocked = null;
        // Regras de Commander continuam como alertas, sem converter a carta numa nota.
        if (array_diff($profile['identity'], $identity)) $blocked = 'Fora da identidade de cor da comandante.';
        $legal = json_decode((string)($item['legalities'] ?? '{}'), true)['commander'] ?? 'legal';
        if (in_array($legal, ['banned', 'not_legal'], true)) $blocked = $legal === 'banned' ? 'Banida no Commander.' : 'Não é legal no Commander.';
        if ($quantity > 1 && !str_contains((string)$item['type_line'], 'Basic') && !preg_match('/deck can have (any number|up to)/i', deckText($item))) $blocked = 'Commander permite apenas 1 cópia desta carta.';
        if (isset($gameChangers[strtolower((string)$item['name'])])) {
            $others = $gcCount - ($item['stage'] === 'deck' ? 1 : 0);
            if ($bracket <= 2) $notes[] = 'Game Changer: fora da proposta do bracket ' . $bracket . '.';
            elseif ($bracket === 3 && $others >= 3) $notes[] = 'Game Changer: o deck já atingiu o limite de 3.';
            elseif ($bracket === 3) $notes[] = $item['stage'] === 'deck' ? 'Game Changer: ocupa 1 dos 3 permitidos no bracket 3.' : 'Game Changer: seria o ' . ($others + 1) . 'º dos 3 permitidos no bracket 3.';
            else $notes[] = 'Game Changer.';
        }
        if ($profile['mld']) {
            if ($bracket <= 3) $notes[] = 'Destruição de terrenos em massa: só a partir do bracket 4.';
            else $notes[] = 'Destruição de terrenos em massa.';
        }
        $result['cards'][$item['id']] = [
            'blocked' => $blocked, 'notes' => $notes, 'stage' => $item['stage'], 'name' => (string)$item['name'], 'relationships' => ['deck' => [], 'candidates' => []],
            'game_changer' => isset($gameChangers[strtolower((string)$item['name'])]),
            'produces' => array_values(array_map(fn($f) => deckScoreFeatureLibrary()[$f][0], array_keys($profile['produces']))),
            'cares' => array_values(array_merge(array_map(fn($f) => deckScoreFeatureLibrary()[$f][0], array_keys($profile['cares'])), array_map('ucfirst', array_keys($profile['tribes_cared'])))),
            'roles' => array_values(array_map(fn($r) => DECK_SCORE_ROLES[$r], array_keys($profile['roles']))),
        ];
    }

    // Relações v2 (deck_relations.php): setas com motivo e trecho do texto de cada lado.
    $graphNodes = [(string)$commander['id'] => $commander + ['stage' => 'commander']];
    foreach ($items as $item) $graphNodes[(string)$item['id']] = $item;
    $graph = deckRelationGraph($graphNodes, $commander);
    $result['tribe'] = $graph['tribe'];
    $features = deckRelationFeatures();
    foreach ($graph['profiles'] as $nodeId => $relationProfile) {
        if (!isset($result['cards'][$nodeId])) continue;
        $result['cards'][$nodeId]['produces'] = array_values(array_unique(array_merge(array_map(fn($f) => $features[$f][0], array_keys($relationProfile['provides'])), array_map('ucfirst', array_keys($relationProfile['tribes'])))));
        $result['cards'][$nodeId]['cares'] = array_values(array_unique(array_merge(array_map(fn($f) => $features[$f][0], array_keys($relationProfile['consumes'])), array_map('ucfirst', array_keys($relationProfile['tribes_cared'])), $relationProfile['chosen_type'] && $graph['tribe'] ? [ucfirst($graph['tribe']) . ' (tipo escolhido)'] : [])));
    }
    $pairs = [];
    foreach ($graph['edges'] as $edge) $pairs[$edge['from']][$edge['to']] = $edge['reasons'];
    $stageOf = static fn(string $nodeId): string => (string)($graphNodes[$nodeId]['stage'] ?? '');
    foreach ($items as $item) {
        $sourceId = (string)$item['id'];
        $partners = array_unique(array_merge(array_keys($pairs[$sourceId] ?? []), array_keys(array_filter($pairs, fn($targets) => isset($targets[$sourceId])))));
        foreach ($partners as $otherId) {
            $otherId = (string)$otherId;
            if ($otherId === $sourceId) continue;
            $otherStage = $stageOf($otherId);
            // Candidatas veem só candidatas; cartas do deck veem deck + comandante e, à parte, as candidatas.
            if ($item['stage'] === 'candidate' && $otherStage !== 'candidate') continue;
            $group = $otherStage === 'candidate' ? 'candidates' : 'deck';
            $gives = $pairs[$sourceId][$otherId] ?? [];
            $takes = $pairs[$otherId][$sourceId] ?? [];
            $result['cards'][$sourceId]['relationships'][$group][] = [
                'id' => $otherId,
                'name' => (string)$graphNodes[$otherId]['name'] . ($otherStage === 'commander' ? ' · comandante' : ''),
                'offers' => array_values(array_unique(array_column($gives, 'label'))), 'receives' => array_values(array_unique(array_column($takes, 'label'))),
                'gives' => $gives, 'takes' => $takes, 'weight' => deckRelationWeight($gives) + deckRelationWeight($takes),
            ];
        }
        foreach (['deck', 'candidates'] as $group) usort($result['cards'][$sourceId]['relationships'][$group], fn($x, $y) => [str_ends_with($y['name'], '· comandante'), $y['weight']] <=> [str_ends_with($x['name'], '· comandante'), $x['weight']]);
    }
    return $result;
}

/** Carrega os itens do deck no formato usado pelo cálculo (para ações fora da renderização). */
function deckScoreLoadItems(int $deckId, int $userId): array
{
    return deckQuery(deckOwnedSql() . "SELECT c.*, i.stage, i.quantity, i.role, i.notes, COALESCE(o.owned,0) owned
        FROM builder_items i JOIN builder_decks d ON d.id=i.deck_id AND d.user_id=? JOIN cards c ON c.id=i.card_id
        LEFT JOIN owned o ON o.logical_id=COALESCE(c.oracle_id,c.id) WHERE i.deck_id=? ORDER BY c.name", [$userId, $deckId])->fetchAll();
}

/** Lê a configuração enviada pelo formulário "Ajustar fórmula". */
function deckScoreConfigFromPost(array $post): array
{
    return deckScoreConfig([
        'preset' => $post['preset'] ?? 'custom',
        'weights' => (array)($post['weights'] ?? []),
        'targets' => (array)($post['targets'] ?? []),
        'curve' => (array)($post['curve'] ?? []),
        'bracket' => $post['bracket'] ?? null,
        'max_price' => $post['max_price'] ?? null,
        'thresholds' => (array)($post['thresholds'] ?? []),
        'targets_mode' => ($post['targets_mode'] ?? 'manual') === 'auto' ? 'auto' : 'manual',
    ]);
}

/** Máscara de bits da identidade de cor (W=1, U=2, B=4, R=8, G=16). */
function deckIdentityMask(array $colors): int
{
    $mask = 0;
    foreach ($colors as $color) $mask |= ['W' => 1, 'U' => 2, 'B' => 4, 'R' => 8, 'G' => 16][strtoupper((string)$color)] ?? 0;
    return $mask;
}

/**
 * Índice do catálogo por função (ramp, compra, remoção…): cartas legais em Commander que cumprem cada função,
 * ordenadas pela popularidade no EDHREC. Varre o catálogo uma vez por sincronização (cache em arquivo).
 * Formato: [função => [[logical_id, nome, máscara de identidade, rank EDHREC|null], ...]].
 */
function deckNeedRoleIndex(): array
{
    return catalogCached('need-role-index-v2', function (): array {
        $patterns = deckScoreRolePatterns();
        $columns = [];
        $params = [];
        foreach ($patterns as $role => $list) {
            $columns[] = '(' . implode(' OR ', array_fill(0, count($list), 't ~ ?')) . ') AS ' . $role;
            foreach ($list as $pattern) $params[] = str_replace('\b', '\y', $pattern);
        }
        $rows = deckQuery("WITH base AS (
                SELECT DISTINCT ON (COALESCE(c.oracle_id,c.id)) COALESCE(c.oracle_id,c.id)::text AS lid, c.name, c.color_identity, c.edhrec_rank_cached AS rank,
                    split_part(COALESCE(c.type_line,''),' // ',1) AS front_type,
                    regexp_replace(lower(COALESCE(c.oracle_text,'') || ' ' || COALESCE((SELECT string_agg(f->>'oracle_text',' ') FROM jsonb_array_elements(c.card_faces) f),'')), '\\([^)]*\\)', '', 'g') AS t
                FROM cards c WHERE c.legalities->>'commander'='legal'
                ORDER BY COALESCE(c.oracle_id,c.id), (c.edhrec_rank_cached IS NULL), c.released_at DESC NULLS LAST)
            SELECT lid, name, color_identity::text AS identity, rank, front_type ILIKE '%Land%' AS is_land, front_type ILIKE '%Basic%' AS is_basic, " . implode(', ', $columns) . " FROM base", $params)->fetchAll();
        $index = array_fill_keys(array_merge(['lands'], array_keys($patterns)), []);
        foreach ($rows as $row) {
            $entry = [$row['lid'], $row['name'], deckIdentityMask(json_decode((string)$row['identity'], true) ?: []), $row['rank'] === null ? null : (int)$row['rank']];
            $isLand = $row['is_land'] === true || $row['is_land'] === 't' || $row['is_land'] === 1;
            if ($isLand) {
                if (!($row['is_basic'] === true || $row['is_basic'] === 't' || $row['is_basic'] === 1)) $index['lands'][] = $entry;
                continue;
            }
            foreach (array_keys($patterns) as $role) if ($row[$role] === true || $row[$role] === 't' || $row[$role] === 1) $index[$role][] = $entry;
        }
        foreach ($index as &$list) usort($list, fn($a, $b) => [$a[3] === null, $a[3]] <=> [$b[3] === null, $b[3]]);
        unset($list);
        return $index;
    }, 30 * 86400);
}

/** IDs lógicos do catálogo que cumprem uma função e cabem na identidade (filtro "Função no deck" da busca). */
function deckNeedRoleIds(string $role, array $identity): array
{
    $mask = deckIdentityMask($identity);
    $ids = [];
    foreach (deckNeedRoleIndex()[$role] ?? [] as [$lid, , $cardMask]) if (($cardMask & ~$mask) === 0) $ids[] = $lid;
    return $ids;
}

/**
 * "O que o deck precisa": para cada função, as metas do Índice de Encaixe e as melhores cartas do catálogo
 * que ainda não estão na seleção — primeiro as com sinergia EDHREC para a comandante, depois as mais jogadas.
 */
function deckNeedSuggestions(array $commander, array $scores, array $items, array $config, int $limit = 6): array
{
    $index = deckNeedRoleIndex();
    $mask = deckIdentityMask(json_decode((string)$commander['color_identity'], true) ?: []);
    $taken = [(string)($commander['oracle_id'] ?: $commander['id']) => true];
    foreach ($items as $item) $taken[(string)($item['oracle_id'] ?: $item['id'])] = true;
    $synergy = deckQuery("SELECT COALESCE(card.oracle_id,card.id)::text, MAX(s.score) FROM deck_synergy s
        JOIN cards leader ON leader.id=s.commander_id JOIN cards card ON card.id=s.card_id
        WHERE COALESCE(leader.oracle_id,leader.id)=?::uuid GROUP BY 1", [(string)($commander['oracle_id'] ?: $commander['id'])])->fetchAll(PDO::FETCH_KEY_PAIR);
    $blockedNames = (int)$config['bracket'] <= 2 ? array_flip(deckScoreGameChangers()) : [];
    $gameChangers = array_flip(deckScoreGameChangers());
    $result = [];
    $wanted = [];
    foreach ($scores['needs'] as $role => $need) {
        if (!isset($index[$role]) || (int)$need['target'] <= 0) continue;
        $pool = [];
        foreach ($index[$role] as $position => [$lid, $name, $cardMask, $rank]) {
            if (($cardMask & ~$mask) !== 0 || isset($taken[$lid]) || isset($blockedNames[strtolower($name)])) continue;
            $pool[] = ['lid' => $lid, 'name' => $name, 'rank' => $rank, 'synergy' => isset($synergy[$lid]) ? (float)$synergy[$lid] : null, 'position' => $position];
        }
        $total = count($pool);
        usort($pool, fn($a, $b) => [($b['synergy'] ?? -1) > 0, $b['synergy'] ?? -1, $a['position']] <=> [($a['synergy'] ?? -1) > 0, $a['synergy'] ?? -1, $b['position']]);
        $picked = array_slice($pool, 0, $limit);
        foreach ($picked as $pick) $wanted[$pick['lid']] = true;
        $result[$role] = $need + ['role' => $role, 'total' => $total, 'picks' => $picked];
    }
    // Uma impressão por carta: a da coleção, senão em inglês e com imagem local.
    $cards = [];
    if ($wanted) {
        $rows = deckQuery("SELECT DISTINCT ON (COALESCE(c.oracle_id,c.id)) COALESCE(c.oracle_id,c.id)::text AS logical_id, c.*, COALESCE(bc.quantity,0) AS owned_printing
            FROM cards c LEFT JOIN " . deckCollectionPrintingSql() . " bc ON bc.scryfall_id=c.id
            WHERE COALESCE(c.oracle_id,c.id) = ANY(?::uuid[])
            ORDER BY COALESCE(c.oracle_id,c.id), (COALESCE(bc.quantity,0)>0) DESC, (c.lang='en') DESC, (c.local_image IS NOT NULL) DESC, c.released_at DESC NULLS LAST",
            ['{' . implode(',', array_keys($wanted)) . '}'])->fetchAll();
        foreach ($rows as $row) $cards[$row['logical_id']] = $row;
    }
    $owned = function_exists('deckOwnedLogicalMap') ? deckOwnedLogicalMap() : [];
    foreach ($result as &$entry) {
        $entry['cards'] = [];
        foreach ($entry['picks'] as $pick) {
            if (!isset($cards[$pick['lid']])) continue;
            $entry['cards'][] = $cards[$pick['lid']] + ['need_synergy' => $pick['synergy'], 'need_owned' => (int)($owned[$pick['lid']] ?? 0), 'need_game_changer' => isset($gameChangers[strtolower($pick['name'])])];
        }
        unset($entry['picks']);
    }
    unset($entry);
    // Funções abaixo da meta primeiro, na ordem de maior falta proporcional.
    uasort($result, fn($a, $b) => [$b['missing'] > 0, $b['missing'] / max(1, $b['target'])] <=> [$a['missing'] > 0, $a['missing'] / max(1, $a['target'])]);
    return $result;
}
