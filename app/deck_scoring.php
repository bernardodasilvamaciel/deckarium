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
function deckScoreProfile(array $card): array
{
    static $cache = [];
    $key = (string)($card['oracle_id'] ?: $card['id']);
    if (isset($cache[$key])) return $cache[$key];
    $lexicon = deckScoreLexicon();
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
    if ($isCreatureLike) foreach ($subtypeList as $subtype) if (isset($lexicon['types'][$subtype]) && !isset($notTribes[$subtype])) $tribesProduced[$subtype] = true;
    $changeling = (bool)preg_match('/\bchangeling\b|is every creature type/', strtolower(deckText($card)));
    $words = array_flip(preg_split('/[^a-z0-9+\/-]+/', $text) ?: []);
    $tribesCared = [];
    $irregular = ['elf' => 'elves', 'dwarf' => 'dwarves', 'wolf' => 'wolves', 'werewolf' => 'werewolves', 'mouse' => 'mice', 'ox' => 'oxen', 'fungus' => 'fungi',
        'octopus' => 'octopuses', 'thief' => 'thieves', 'sheep' => 'sheep', 'fish' => 'fish', 'jellyfish' => 'jellyfish', 'cyclops' => 'cyclopes', 'sphinx' => 'sphinxes',
        'fox' => 'foxes', 'lich' => 'liches', 'witch' => 'witches', 'phoenix' => 'phoenixes', 'harpy' => 'harpies', 'fairy' => 'faeries'];
    foreach ($lexicon['types'] as $type => $count) {
        $type = (string)$type;
        if (isset($notTribes[$type])) continue;
        $plural = $irregular[$type] ?? (str_ends_with($type, 'y') && !preg_match('/[aeiou]y$/', $type) ? substr($type, 0, -1) . 'ies' : $type . 's');
        if ((isset($words[$type]) || isset($words[$plural])) && preg_match('/\b(' . preg_quote($type, '/') . '|' . preg_quote($plural, '/') . ')\b[^.]{0,30}(you control|spells?|cards?|creatures?|get|have|enter|dies|deal)|\b(other|another|each|a|an|target|nontoken|attacking) (' . preg_quote($type, '/') . '|' . preg_quote($plural, '/') . ')\b/', $text)) {
            $tribesCared[$type] = true;
        }
    }

    $roles = [];
    if (str_contains($superTypes, 'land')) $roles['lands'] = true;
    else {
        if (preg_match('/\badd (\{[wubrgc]\}|one mana|two mana|three mana|mana of any|x mana|\{c\}\{c\})|search your library for[^.]*\blands? cards?\b[^.]*(onto the battlefield|into your hand)|create[^.]*\btreasure tokens?|play (an|two) additional lands?/', $text)) $roles['ramp'] = true;
        if (preg_match('/\bdraws? (two|three|four|x|that many|cards equal|a card for each)\b|\bdraw a card\b[^.]*(whenever|at the beginning)|whenever[^.]*,? (you )?draw a card|\binvestigate\b|connives?\b/', $text) || preg_match('/(^|\n)draw (a|two) cards?/', $text)) $roles['draw'] = true;
        if (preg_match('/(destroy|exile) (up to one )?target (creature|artifact|enchantment|planeswalker|nonland permanent|permanent|creature or planeswalker|artifact or enchantment)|deals? (\d+|x) damage to (target|any target|up to one target)|return target (creature|nonland permanent|permanent|artifact|enchantment)[^.]*to (its|their) owner\'s hand|target (player|opponent) sacrifices|counter target (spell|noncreature spell|creature spell)|fights? (target|up to one target)/', $text)) $roles['removal'] = true;
        if (preg_match('/(destroy|exile) (all|each) (other )?(creatures|nonland permanents|artifacts|enchantments|permanents)|(all|each) creatures? gets? -\d|deals? (\d+|x) damage to each creature|return all (creatures|nonland permanents)|each player sacrifices (all|each)|\boverload\b/', $text)) $roles['wipe'] = true;
        if (preg_match('/(creatures|permanents|commander)[^.]*you control (gain|have|get)[^.]*(hexproof|indestructible|protection|shroud|ward)|phases? out|can\'t be countered|\bwards? \{|you have hexproof|prevent all (combat )?damage/', $text)) $roles['protection'] = true;
        if (preg_match('/return (target|up to \w+ target|all|each)[^.]*cards? from your graveyard to (your hand|the battlefield)|\bregrowth\b|return[^.]*from your graveyard to the battlefield/', $text)) $roles['recursion'] = true;
        if (preg_match('/search your library for (a|an|up to one|up to two|any) (?!basic land|land|forest|island|swamp|plains|mountain)[^.]*card/', $text)) $roles['tutor'] = true;
        if (!$roles) $roles['plan'] = true;
    }

    $pips = array_fill_keys(['W', 'U', 'B', 'R', 'G'], 0);
    foreach (['W', 'U', 'B', 'R', 'G'] as $color) $pips[$color] = substr_count(strtoupper((string)$card['mana_cost']), $color);

    $rareWords = [];
    foreach (array_keys($words) as $word) {
        $word = (string)$word;
        if (strlen($word) < 4 || ctype_digit($word) || in_array($word, DECK_SCORE_STOPWORDS, true) || !isset($lexicon['df'][$word])) continue;
        $rareWords[$word] = log($lexicon['n'] / max(1, (int)$lexicon['df'][$word]));
    }

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
        'rare_words' => $rareWords,
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
    $lexicon = deckScoreLexicon();
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
    foreach ([[$a, $b], [$b, $a]] as [$source, $target]) {
        foreach ($target['tribes_cared'] as $tribe => $_) {
            if (isset($source['tribes_produced'][$tribe]) || $source['changeling']) {
                $rarity = 1 - log(max(2, (int)($lexicon['types'][$tribe] ?? 2))) / log(max(3, $lexicon['n']));
                $points += 1.2 + 2.2 * max(0, $rarity);
                $reasons['tribe:' . $tribe] = ucfirst($tribe);
            }
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
    $result = ['cards' => [], 'needs' => [], 'summary' => ['advance' => 0, 'review' => 0, 'hold' => 0, 'blocked' => 0], 'bracket' => $config['bracket'],
        'game_changers' => 0, 'open_slots' => 0, 'deck_count' => 0, 'weakest' => []];
    if (!$commander || !$items) return $result;

    $gameChangers = array_flip(deckScoreGameChangers());
    $commanderProfile = deckScoreProfile($commander);
    $identity = $commanderProfile['identity'];
    $weights = $config['weights'];
    $bracket = (int)$config['bracket'];

    // Estado atual do deck: cartas aprovadas contam inteiras; em avaliação contam metade.
    $roleCounts = array_fill_keys(array_keys(DECK_SCORE_ROLES), 0.0);
    $curveCounts = array_fill_keys(array_keys($config['curve']), 0.0);
    $pipTotals = array_fill_keys(['W', 'U', 'B', 'R', 'G'], 0.0);
    $gcCount = 0;
    $extraTurns = 0;
    $deckCount = 1;
    foreach ($commanderProfile['pips'] as $color => $amount) $pipTotals[$color] += $amount;
    $profiles = [];
    foreach ($items as $item) {
        $profile = deckScoreProfile($item);
        $profiles[$item['id']] = $profile;
        $factor = $item['stage'] === 'deck' ? 1.0 : ($item['stage'] === 'review' ? 0.5 : 0.0);
        $quantity = max(1, (int)$item['quantity']);
        if ($item['stage'] === 'deck') $deckCount += $quantity;
        if ($factor <= 0) continue;
        foreach ($profile['roles'] as $role => $_) $roleCounts[$role] += $factor * $quantity;
        if (!$profile['is_land']) {
            $bucket = (string)max(1, min(7, (int)round($profile['mv'])));
            $curveCounts[$bucket] += $factor * $quantity;
        }
        foreach ($profile['pips'] as $color => $amount) $pipTotals[$color] += $factor * $amount * $quantity;
        if (isset($gameChangers[strtolower((string)$item['name'])])) $gcCount += $factor >= 1 ? 1 : 0;
        if ($profile['extra_turn'] && $factor >= 1) $extraTurns++;
    }
    $pipSum = max(1.0, array_sum($pipTotals));
    $openSlots = max(0, 100 - $deckCount);
    $result['open_slots'] = $openSlots;
    $result['deck_count'] = $deckCount;
    $result['game_changers'] = $gcCount;

    $targets = $config['targets'];
    $targets['plan'] = max(0, 99 - array_sum($targets));
    foreach (DECK_SCORE_ROLES as $role => $label) {
        $result['needs'][$role] = ['label' => $label, 'target' => (int)$targets[$role], 'current' => $roleCounts[$role], 'missing' => max(0, (int)ceil($targets[$role] - $roleCounts[$role]))];
    }

    // Dados do EDHREC para a comandante (qualquer impressão).
    $logicalIds = array_values(array_unique(array_map(fn($item) => (string)($item['oracle_id'] ?: $item['id']), $items)));
    $edhrec = [];
    if ($logicalIds) {
        $placeholders = implode(',', array_fill(0, count($logicalIds), '?::uuid'));
        $rows = deckQuery("SELECT COALESCE(card.oracle_id,card.id)::text AS logical_id, MAX(s.score) AS score, MAX(s.inclusion) AS inclusion, MAX(s.metric) AS metric
            FROM deck_synergy s JOIN cards leader ON leader.id=s.commander_id JOIN cards card ON card.id=s.card_id
            WHERE COALESCE(leader.oracle_id,leader.id)=?::uuid AND COALESCE(card.oracle_id,card.id) IN ({$placeholders})
            GROUP BY 1", array_merge([(string)($commander['oracle_id'] ?: $commander['id'])], $logicalIds))->fetchAll();
        foreach ($rows as $row) $edhrec[$row['logical_id']] = $row;
    }
    $hasEdhrec = (bool)$edhrec;

    // Combos de duas cartas conhecidos (catálogo interno + EDHREC).
    $comboPartners = [];
    $insights = function_exists('deckCommanderInsights') ? deckCommanderInsights($commander) : null;
    // Só combos infinitos contam como "combo de duas cartas" para as regras de bracket; os demais viram observação.
    $comboSources = array_merge(
        array_map(fn($c) => ['cards' => $c['cards'], 'infinite' => true], deckComboCatalog()),
        array_map(fn($c) => ['cards' => (array)$c['cards'], 'infinite' => (bool)preg_grep('/infinite/i', (array)($c['results'] ?? []))], (array)($insights['combos'] ?? []))
    );
    foreach ($comboSources as $combo) {
        $pieces = array_map('strtolower', (array)$combo['cards']);
        if (count($pieces) !== 2) continue;
        $comboPartners[$pieces[0]][$pieces[1]] = ($comboPartners[$pieces[0]][$pieces[1]] ?? false) || $combo['infinite'];
        $comboPartners[$pieces[1]][$pieces[0]] = ($comboPartners[$pieces[1]][$pieces[0]] ?? false) || $combo['infinite'];
    }
    $namesInPlay = [strtolower((string)$commander['name']) => (string)$commander['name']];
    foreach ($items as $item) if ($item['stage'] !== 'candidate') $namesInPlay[strtolower((string)$item['name'])] = (string)$item['name'];

    $terms = array_values(array_filter(array_map(fn($t) => strtolower(trim($t)), explode(';', (string)($deck['terms'] ?? '')))));
    $weightSum = 0;

    foreach ($items as $item) {
        $profile = $profiles[$item['id']];
        $quantity = max(1, (int)$item['quantity']);
        $selfFactor = $item['stage'] === 'deck' ? 1.0 : ($item['stage'] === 'review' ? 0.5 : 0.0);
        $components = [];
        $details = [];
        $notes = [];
        $multiplier = 1.0;
        $blocked = null;
        $bonus = 0;

        // 1. Encaixe com a comandante (terrenos só entram quando têm ligação real, como landfall).
        [$raw, $reasons] = deckScorePair($profile, $commanderProfile, true);
        if (!$profile['is_land'] || $raw > 0.8) $components['commander'] = deckScoreSaturate($raw, 2.2);
        $details['commander'] = $reasons ? 'Conecta por ' . implode(', ', array_slice(array_values($reasons), 0, 3)) . '.' : 'Nenhuma ligação direta com o texto da comandante.';

        // 2. Conexões com as cartas em avaliação e no deck (as 8 melhores ligações).
        $links = [];
        foreach ($items as $other) {
            if ($other['id'] === $item['id'] || $other['stage'] === 'candidate') continue;
            [$pairPoints, $pairReasons] = deckScorePair($profile, $profiles[$other['id']], false);
            if ($pairPoints > 0) $links[] = [$pairPoints, $other['name'], reset($pairReasons)];
        }
        usort($links, fn($x, $y) => $y[0] <=> $x[0]);
        $topLinks = array_slice($links, 0, 8);
        if (!$profile['is_land'] || array_sum(array_column($topLinks, 0)) > 1.5) $components['deck'] = deckScoreSaturate(array_sum(array_column($topLinks, 0)), 5.0);
        $details['deck'] = $links
            ? 'Liga-se a ' . count($links) . ' carta' . (count($links) === 1 ? '' : 's') . ': ' . implode('; ', array_map(fn($l) => $l[1] . ' (' . $l[2] . ')', array_slice($topLinks, 0, 3))) . '.'
            : 'Ainda não conversa com as cartas em avaliação ou no deck.';

        // 3. Função que falta.
        $best = 0.0;
        $roleNotes = [];
        foreach ($profile['roles'] as $role => $_) {
            $target = (float)$targets[$role];
            $current = $roleCounts[$role] - $selfFactor * $quantity;
            $deficit = $target > 0 ? max(0, min(1, ($target - $current) / $target)) : 0.0;
            $best = max($best, $deficit);
            $missing = max(0, (int)ceil($target - $current));
            $roleNotes[] = DECK_SCORE_ROLES[$role] . ($missing > 0 ? ' (faltam ' . $missing . ')' : ' (meta atingida)');
        }
        $extraRoles = count(array_filter(array_keys($profile['roles']), fn($role) => ($targets[$role] ?? 0) > ($roleCounts[$role] - $selfFactor * $quantity)));
        $components['roles'] = min(1.0, $best + 0.12 * max(0, $extraRoles - 1));
        $details['roles'] = implode(' · ', $roleNotes) . '.';

        // 4. Curva de mana (não se aplica a terrenos).
        if (!$profile['is_land']) {
            $bucket = (string)max(1, min(7, (int)round($profile['mv'])));
            $target = (float)($config['curve'][$bucket] ?? 0);
            $current = $curveCounts[$bucket] - $selfFactor * $quantity;
            $components['curve'] = $current < $target ? 1 - 0.3 * ($current / max(1, $target)) : max(0.0, 0.7 - 0.35 * ($current - $target + 1) / max(2, $target));
            $details['curve'] = 'Custo ' . ($bucket === '7' ? '7+' : $bucket) . ': ' . rtrim(rtrim(number_format(max(0, $current), 1, ',', ''), '0'), ',') . ' de ' . (int)$target . ' desejadas.';
        }

        // 5. Exigência de cor; para terrenos, a qualidade da base de mana (cores da identidade e se entra virado).
        if ($profile['is_land']) {
            $identityColors = $identity ?: ['C'];
            $fixes = array_intersect($profile['produced_mana'], $identityColors);
            $coverage = count($fixes) / max(1, count($identityColors));
            $components['colors'] = max(0.0, min(1.0, (0.35 + 0.65 * $coverage) * ($profile['enters_tapped'] ? 0.75 : 1.0)));
            $details['colors'] = ($fixes ? 'Produz ' . implode('', $fixes) . ' (' . count($fixes) . ' de ' . count($identityColors) . ' cores da identidade)' : 'Não produz cores da identidade')
                . ($profile['enters_tapped'] ? ', entra virado.' : '.');
        } else {
            $strain = 0.0;
            foreach ($profile['pips'] as $color => $amount) {
                if ($amount <= 0) continue;
                $share = $pipTotals[$color] / $pipSum;
                $strain += max(0, $amount - 1) * (1 - $share) + ($share < 0.15 ? 0.5 : 0);
            }
            $components['colors'] = 1 / (1 + 0.55 * $strain);
            $heavy = array_keys(array_filter($profile['pips'], fn($amount) => $amount >= 2));
            $details['colors'] = $strain < 0.3 ? 'Custo de cor confortável para a base de mana.' : 'Exige ' . ($heavy ? implode('', $heavy) . ' repetido' : 'uma cor pouco presente') . ' em relação ao restante do deck.';
        }

        // 6. Intenção do deck (termos da "Minha intenção").
        if ($terms) {
            $matched = array_values(array_filter($terms, fn($term) => str_contains($profile['text'], $term) || str_contains(strtolower((string)$item['type_line']), $term)));
            $components['terms'] = $matched ? min(1.0, 0.7 + 0.15 * count($matched)) : 0.0;
            $details['terms'] = $matched ? 'Contém: ' . implode(', ', array_slice($matched, 0, 3)) . '.' : 'Não cita os termos da sua intenção.';
        }

        // 7. EDHREC: sinergia, inclusão e popularidade geral.
        $logical = (string)($item['oracle_id'] ?: $item['id']);
        $rank = (int)($item['edhrec_rank_cached'] ?? 0);
        $popularity = $rank > 0 ? max(0.0, min(1.0, 1 - log10($rank) / log10(40000))) : 0.2;
        if (isset($edhrec[$logical])) {
            $row = $edhrec[$logical];
            $synergy = $row['metric'] === 'lift' ? max(0, min(1, ((float)$row['score'] - 1) / 2 + 0.5)) : max(0, min(1, 0.5 + (float)$row['score']));
            $inclusion = $row['inclusion'] !== null ? min(1.0, (float)$row['inclusion'] * 1.6) : 0.4;
            $components['edhrec'] = 0.35 * $synergy + 0.45 * $inclusion + 0.2 * $popularity;
            $details['edhrec'] = ($row['metric'] === 'lift' ? 'Lift ' . number_format((float)$row['score'], 2, ',', '.') : 'Sinergia ' . sprintf('%+.0f%%', (float)$row['score'] * 100))
                . ($row['inclusion'] !== null ? ' · em ' . number_format((float)$row['inclusion'] * 100, 0, ',', '.') . '% dos decks' : '') . '.';
        } else {
            $components['edhrec'] = $hasEdhrec ? 0.1 + 0.5 * $popularity : 0.2 + 0.6 * $popularity;
            $details['edhrec'] = $hasEdhrec ? 'Não aparece nas listas do EDHREC desta comandante.' : 'Sem dados do EDHREC para esta comandante; usando só a popularidade geral.';
        }

        // 8. Coleção.
        $owned = (int)($item['owned'] ?? 0);
        $components['collection'] = $owned > 0 ? 1.0 : 0.0;
        $details['collection'] = $owned > 0 ? 'Você tem ' . $owned . ' cópia' . ($owned === 1 ? '' : 's') . '.' : 'Precisa ser comprada.';

        // 9. Orçamento.
        $price = deckSelectedPriceBrl($item);
        if ($price !== null) {
            $max = (float)$config['max_price'];
            $components['price'] = $max > 0 ? ($price <= $max ? 1.0 : max(0.0, 1 - ($price - $max) / $max)) : 1 / (1 + $price / 30);
            $details['price'] = 'R$ ' . number_format($price, 2, ',', '.') . ($max > 0 ? ($price <= $max ? ' · dentro do limite' : ' · acima do limite de R$ ' . number_format($max, 2, ',', '.')) : '') . '.';
        }

        // Regras de Commander.
        if (array_diff($profile['identity'], $identity)) $blocked = 'Fora da identidade de cor da comandante.';
        $legal = json_decode((string)($item['legalities'] ?? '{}'), true)['commander'] ?? 'legal';
        if (in_array($legal, ['banned', 'not_legal'], true)) $blocked = $legal === 'banned' ? 'Banida no Commander.' : 'Não é legal no Commander.';
        if ($quantity > 1 && !str_contains((string)$item['type_line'], 'Basic') && !preg_match('/deck can have (any number|up to)/i', deckText($item))) $blocked = 'Commander permite apenas 1 cópia desta carta.';
        if (isset($gameChangers[strtolower((string)$item['name'])])) {
            $others = $gcCount - ($item['stage'] === 'deck' ? 1 : 0);
            if ($bracket <= 2) { $multiplier *= 0.25; $notes[] = 'Game Changer: não entra em decks de bracket ' . $bracket . '.'; }
            elseif ($bracket === 3 && $others >= 3) { $multiplier *= 0.5; $notes[] = 'Game Changer: o deck já tem 3, o limite do bracket 3.'; }
            elseif ($bracket === 3) $notes[] = $item['stage'] === 'deck' ? 'Game Changer: ocupa 1 dos 3 permitidos no bracket 3.' : 'Game Changer: seria o ' . ($others + 1) . 'º dos 3 permitidos no bracket 3.';
            else $notes[] = 'Game Changer.';
        }
        if ($profile['mld']) {
            if ($bracket <= 3) { $multiplier *= 0.2; $notes[] = 'Destruição de terrenos em massa: só a partir do bracket 4.'; }
            else $notes[] = 'Destruição de terrenos em massa.';
        }
        if ($profile['extra_turn']) {
            $others = $extraTurns - ($item['stage'] === 'deck' ? 1 : 0);
            if ($bracket === 1) { $multiplier *= 0.2; $notes[] = 'Turnos extras não combinam com o bracket 1.'; }
            elseif ($bracket === 2 && $others >= 1) { $multiplier *= 0.7; $notes[] = 'Turnos extras devem ser raros no bracket 2.'; }
            elseif ($bracket === 3 && $others >= 3) { $multiplier *= 0.6; $notes[] = 'Já há 3 cartas de turno extra; o bracket 3 sugere no máximo 3.'; }
        }
        $partners = array_intersect_key($comboPartners[strtolower((string)$item['name'])] ?? [], $namesInPlay);
        if ($partners) {
            $infinite = array_keys(array_filter($partners));
            $finite = array_keys(array_filter($partners, fn($flag) => !$flag));
            if ($infinite) {
                $partnerNames = implode(', ', array_map(fn($name) => $namesInPlay[$name], $infinite));
                if ($bracket <= 3) { $multiplier *= 0.5; $notes[] = 'Combo infinito de duas cartas com ' . $partnerNames . ': indicado para bracket 4 ou mais.'; }
                else { $bonus += 8; $notes[] = 'Combo infinito de duas cartas com ' . $partnerNames . '.'; }
            }
            if ($finite) {
                $bonus += 4;
                $notes[] = 'Combo conhecido com ' . implode(', ', array_map(fn($name) => $namesInPlay[$name], $finite)) . '.';
            }
        }

        // Cartas de função (terreno, ramp, compra, remoção…) não precisam de sinergia para valer a vaga:
        // o peso da sinergia diminui e o da função aumenta, voltando ao normal quanto mais sinergia a carta tiver.
        $effective = array_map('floatval', $weights);
        $functional = !isset($profile['roles']['plan']);
        if ($functional) {
            $synergy = max($components['commander'] ?? 0, $components['deck'] ?? 0);
            $scale = 0.35 + 0.65 * $synergy;
            $effective['commander'] *= $scale;
            $effective['deck'] *= $scale;
            $effective['terms'] *= $scale;
            $effective['roles'] *= 1.6;
            $effective['edhrec'] *= 1.8;
            $details['roles'] .= ' Carta de função: aqui a sinergia pesa menos e a função pesa mais.';
        }
        $activeWeights = array_intersect_key($effective, $components);
        $weightSum = array_sum($activeWeights) ?: 1;
        $base = 0.0;
        $breakdown = [];
        foreach (DECK_SCORE_COMPONENTS as $key => $label) {
            if (!isset($components[$key])) continue;
            $weight = (float)($effective[$key] ?? 0);
            $points = 100 * $weight * $components[$key] / $weightSum;
            $base += $points;
            $breakdown[$key] = ['label' => $label, 'points' => $points, 'max' => 100 * $weight / $weightSum, 'value' => $components[$key], 'detail' => $details[$key] ?? ''];
        }
        $score = $blocked ? 0 : (int)max(0, min(100, round($base * $multiplier + $bonus)));
        $band = $blocked ? 'blocked' : ($score >= $config['thresholds']['advance'] ? 'advance' : ($score >= $config['thresholds']['review'] ? 'review' : 'hold'));
        $result['summary'][$band]++;
        $result['cards'][$item['id']] = [
            'score' => $score, 'band' => $band, 'blocked' => $blocked, 'multiplier' => $multiplier, 'bonus' => $bonus,
            'breakdown' => $breakdown, 'notes' => $notes, 'stage' => $item['stage'], 'name' => (string)$item['name'],
            'game_changer' => isset($gameChangers[strtolower((string)$item['name'])]),
            'produces' => array_values(array_map(fn($f) => deckScoreFeatureLibrary()[$f][0], array_keys($profile['produces']))),
            'cares' => array_values(array_merge(array_map(fn($f) => deckScoreFeatureLibrary()[$f][0], array_keys($profile['cares'])), array_map('ucfirst', array_keys($profile['tribes_cared'])))),
            'roles' => array_values(array_map(fn($r) => DECK_SCORE_ROLES[$r], array_keys($profile['roles']))),
        ];
    }
    $deckScores = array_filter($result['cards'], fn($card) => $card['stage'] === 'deck');
    uasort($deckScores, fn($a, $b) => $a['score'] <=> $b['score']);
    $result['weakest'] = array_slice($deckScores, 0, 5, true);
    return $result;
}

function deckScoreBandLabel(string $band): string
{
    return ['advance' => 'Avançar', 'review' => 'Avaliar com calma', 'hold' => 'Segurar', 'blocked' => 'Bloqueada'][$band] ?? $band;
}

/**
 * Sugestões da etapa: candidatas → avaliação (até 1,5× as vagas abertas, descontando quem já está em avaliação)
 * e avaliação → deck (até as vagas abertas). Respeita o limite de Game Changers do bracket durante a escolha.
 */
function deckScoreSuggestions(array $scores, string $stage, array $config): array
{
    if (!in_array($stage, ['candidate', 'review'], true)) return [];
    $reviewCount = count(array_filter($scores['cards'], fn($card) => $card['stage'] === 'review'));
    $capacity = $stage === 'candidate' ? max(0, (int)ceil($scores['open_slots'] * 1.5) - $reviewCount) : $scores['open_slots'];
    $eligible = array_filter($scores['cards'], fn($card) => $card['stage'] === $stage && $card['band'] === 'advance');
    uasort($eligible, fn($a, $b) => $b['score'] <=> $a['score']);
    $gcBudget = $config['bracket'] <= 2 ? 0 : ($config['bracket'] === 3 ? max(0, 3 - $scores['game_changers']) : PHP_INT_MAX);
    $picked = [];
    foreach ($eligible as $id => $card) {
        if (count($picked) >= $capacity) break;
        if ($card['game_changer'] && $stage === 'review') {
            if ($gcBudget <= 0) continue;
            $gcBudget--;
        }
        $picked[$id] = $card;
    }
    return $picked;
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
    ]);
}
