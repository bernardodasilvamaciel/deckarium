<?php
declare(strict_types=1);

/**
 * Relações entre cartas (v2): cada relação é uma seta A → B com o motivo e o trecho do texto de cada lado.
 *
 * A carta A "fornece" algo (cria Tesouros, faz criaturas morrerem, é um Merfolk…) e a carta B "aproveita"
 * isso (sacrifica Tesouros, ganha quando criaturas morrem, fortalece Merfolks…). As fontes são:
 *  - texto Oracle, frase por frase (sem lembretes), com o nome da carta trocado por "~";
 *  - fichas que a carta cria (raw.all_parts do Scryfall), inclusive o tipo de criatura da ficha;
 *  - tipos de criatura (tribos), changeling e "escolha um tipo de criatura" (resolvido pela tribo dominante);
 *  - tags de função do Scryfall Tagger, quando sincronizadas (tabela card_tags);
 *  - combos conhecidos (EDHREC e Commander Spellbook), quando as peças estão juntas.
 * Carregado por deck_library.php.
 */

const DECK_RELATION_GROUPS = [
    'resources' => ['Recursos e fichas', '#b7791f'],
    'creatures' => ['Criaturas e mortes', '#9b2c2c'],
    'counters' => ['Marcadores', '#2f855a'],
    'graveyard' => ['Cemitério', '#6b46c1'],
    'spells' => ['Mágicas e permanentes', '#2b6cb0'],
    'lands' => ['Terrenos', '#7b5e2a'],
    'combat' => ['Combate', '#c05621'],
    'life' => ['Vida e cartas', '#2c7a7b'],
    'tribal' => ['Tribo', '#0f766e'],
    'combo' => ['Combo', '#b83280'],
];

/**
 * Biblioteca de características. Cada item:
 * [rótulo, grupo, peso, frase de quem fornece, frase de quem aproveita, regex fornece[], regex aproveita[]]
 * As regex rodam em cada frase (minúsculas, sem lembretes, nome da carta = "~").
 */
function deckRelationFeatures(): array
{
    static $features = null;
    if ($features !== null) return $features;
    $tokenWord = '(a|an|one|two|three|four|five|x|that many|a number of|twice that many|\d+)';
    return $features = [
        'treasure' => ['Tesouros', 'resources', 1.2, 'cria Tesouros', 'usa Tesouros',
            ['/create[^.]*\btreasure tokens?/'],
            ['/sacrifice (a|an|x|two|three|five|ten|one or more|another) treasures?/', '/\btreasures? you control/', '/whenever[^.]*\btreasures?\b[^.]*(enters?|sacrifice|leaves)/', '/for each treasure/']],
        'food' => ['Comida', 'resources', 1.1, 'cria Comida', 'usa Comida',
            ['/create[^.]*\bfood tokens?/'],
            ['/sacrifice (a|an|x|two|three|one or more|another) foods?/', '/\bfoods? you control/', '/whenever[^.]*\bfoods?\b[^.]*(enters?|sacrifice)/']],
        'clue' => ['Pistas', 'resources', 1.1, 'cria Pistas', 'usa Pistas',
            ['/create[^.]*\bclue tokens?/', '/\binvestigates?\b/'],
            ['/sacrifice (a|an|x|two|three|one or more|another) clues?/', '/\bclues? you control/', '/whenever[^.]*\bclues?\b[^.]*(enters?|sacrifice)/', '/whenever you investigate/']],
        'blood' => ['Sangue', 'resources', 1.1, 'cria Sangue', 'usa Sangue',
            ['/create[^.]*\bblood tokens?/'],
            ['/sacrifice (a|an|x|one or more|another) blood tokens?/', '/\bblood tokens? you control/', '/whenever you sacrifice a blood/']],
        'artifact_tokens' => ['Fichas de artefato', 'resources', 0.9, 'cria fichas de artefato', 'aproveita fichas de artefato',
            ['/create[^.]*\b(treasure|clue|food|blood|map|powerstone|gold|junk|incubator|artifact) tokens?/'],
            ['/artifact tokens? you control/', '/whenever[^.]*artifact tokens?[^.]*(enter|sacrifice|leave)/', '/sacrifice (a|an|x|two|three|one or more|another) artifacts?/']],
        'tokens' => ['Fichas de criatura', 'creatures', 1.1, 'cria fichas de criatura', 'aproveita fichas',
            ['/create[^.]*\bcreature tokens?/', '/\bpopulate\b/', '/\bamass\b/', '/\bfabricate\b/', '/\bmyriad\b/', '/\bembalm\b/', '/\beternalize\b/', '/\bencore\b/'],
            ['/\btokens? you control/', '/whenever (a|one or more|another)[^.]*\btokens?\b[^.]*(enters?|dies|leaves)/', '/for each (creature )?token/', '/if an effect would create[^.]*tokens?/', '/\bpopulate\b/', '/create a token that\'s a copy of (a|target) (creature )?token/']],
        'wide' => ['Mesa larga', 'creatures', 0.8, 'aumenta o número de criaturas', 'fica melhor com muitas criaturas',
            ['/create[^.]*\bcreature tokens?/', '/\bpopulate\b/', '/\bamass\b/', '/\bmyriad\b/'],
            ['/(^|[^a-z] )(other )?creatures you control get \+/', '/for each (other )?creature you control/', '/number of creatures you control/', '/\bconvoke\b/', '/tap (an|x|two|three|five) untapped creatures? you control/', '/creatures? you control (have|gain) /']],
        'fodder' => ['Sacrifício', 'creatures', 1.2, 'dá corpos para sacrificar', 'sacrifica permanentes',
            ['/create[^.]*\b(creature|treasure|food|clue|blood) tokens?/', '/when ~ dies/', '/when ~ is put into (a|your) graveyard/', '/\b(persist|undying)\b/'],
            ['/(^|[\n:,.] ?)sacrifice (a|an|another|one or more|x|two) (other )?(nontoken )?(creature|artifact|permanent|token)s?\b[^.]*:/', '/,? sacrifice (a|an|another) (other )?(creature|artifact|permanent)s?:/', '/as an additional cost to cast this spell, sacrifice (a|an|one or more|x)/', '/whenever you sacrifice/']],
        'death' => ['Mortes', 'creatures', 1.2, 'faz criaturas morrerem', 'ganha quando criaturas morrem',
            ['/sacrifice (a|an|another|x|one or more|two|three) (other )?(nontoken )?creatures?\b[^.]*:/', '/(destroy|exile) all (other )?(nonland )?(creatures|permanents)/', '/each (player|opponent) sacrifices (a|two|x|one or more) creatures?/', '/all creatures get -\d/'],
            ['/whenever[^.]*\b(creature|creatures|permanent|permanents|another creature|nontoken creature)\b[^.]*\bdies?\b/', '/whenever[^.]*put into (a|your) graveyard from the battlefield/', '/\bmorbid\b/', '/for each creature that died this turn/']],
        'counters' => ['Marcadores +1/+1', 'counters', 1.1, 'coloca marcadores +1/+1', 'aproveita marcadores +1/+1',
            ['/(put|puts|with|enters with|distribute|additional)[^.]*\+1\/\+1 counters?/', '/\b(evolve|adapt|bolster|support|mentor|riot|outlast|modular|graft|monstrosity|backup|training)\b/', '/\bexplores?\b/'],
            ['/with (a|one or more) \+1\/\+1 counters? on/', '/for each \+1\/\+1 counter/', '/whenever[^.]*\+1\/\+1 counters? (is|are) put/', '/twice that many[^.]*counters/', '/double the number of[^.]*counters/', '/move[^.]*\+1\/\+1 counters?/', '/creatures? you control with \+1\/\+1 counters?/']],
        'proliferate' => ['Marcadores (proliferar)', 'counters', 0.9, 'coloca marcadores', 'multiplica marcadores',
            ['/\bput (a|an|one|two|three|x|that many)[^.]*\b(\+1\/\+1|loyalty|charge|oil|shield|lore|poison|time|quest|experience) counters?/', '/\b(toxic|infect|poisonous)\b/'],
            ['/\bproliferate\b/', '/double the number of each kind of counter/']],
        'minus' => ['Marcadores -1/-1', 'counters', 1.1, 'coloca marcadores -1/-1', 'aproveita marcadores -1/-1',
            ['/-1\/-1 counters? on/', '/\bwither\b/', '/\binfect\b/'],
            ['/whenever[^.]*-1\/-1 counters? (is|are) put/', '/with (a|one or more) -1\/-1 counters? on/', '/for each -1\/-1 counter/']],
        'etb' => ['Efeitos de entrada', 'creatures', 1.2, 'faz permanentes entrarem de novo', 'tem efeito ao entrar',
            ['/exile (up to (one|two) |another |one or more |x )?(other )?target[^.]*(creatures?|permanents?|artifacts?)[^.]*(return|then return)[^.]*battlefield/', '/exile[^.]*,? then return (it|them|that card|those cards) to the battlefield/', '/triggers? an additional time/', '/return (it|that card|target creature you control|another target creature you control) to (its|their) owner\'s hand/'],
            ['/(when|whenever) ~ enters/', '/when ~ enters the battlefield/']],
        'enters' => ['Criaturas entrando', 'creatures', 0.8, 'coloca criaturas no campo', 'ganha quando criaturas entram',
            ['/create[^.]*\bcreature tokens?/', '/put[^.]*creature cards?[^.]*onto the battlefield/', '/return[^.]*creature cards?[^.]*to the battlefield/'],
            ['/whenever (another|a|an|one or more)( other)?( nontoken| non\w+)? (creatures?|permanents?)( you control)?[^.]*\benters?\b/']],
        'explore' => ['Explorar', 'counters', 1.4, 'explora', 'ganha quando criaturas exploram',
            ['/\bexplores?\b/'],
            ['/whenever (a|another) creature you control explores/', '/whenever ~ explores/']],
        'landfall' => ['Terrenos entrando', 'lands', 1.2, 'coloca terrenos no campo', 'ganha quando terrenos entram',
            ['/search your library for[^.]*\blands? cards?[^.]*(onto|put (it|them|those cards) onto) the battlefield/', '/play (an|two|any number of) additional lands?/', '/put (a|up to \w+|that|those|any number of) lands? cards?[^.]*onto the battlefield/', '/return[^.]*lands? cards?[^.]*to the battlefield/', '/you may play lands from your graveyard/', '/put (it|that card) onto the battlefield (tapped )?if it\'s a land/'],
            ['/\blandfall\b/', '/whenever (a|one or more|another) lands?( you control)? enters?/']],
        'graveyard' => ['Cemitério', 'graveyard', 1.0, 'enche o cemitério', 'usa o cemitério',
            ['/\bmills?\b/', '/\bsurveil\b/', '/put (the top|that card|those cards|the rest)[^.]*into your graveyard/', '/\bdredge\b/', '/\bexplores?\b/', '/\bconnives?\b/', '/discard (a|two|x|your hand|one or more)/'],
            ['/(from|in) your graveyard/', '/\b(flashback|escape|unearth|delirium|threshold|embalm|eternalize|disturb|retrace|jump-start|scavenge|encore|undergrowth|descend)\b/', '/cards? in your graveyard/']],
        'discard' => ['Descarte', 'graveyard', 0.9, 'faz descartar', 'aproveita descarte',
            ['/\bdiscards? (a|an|two|x|your hand|one or more|that card|any number)\b/', '/\bconnives?\b/', '/\bcycling\b/'],
            ['/whenever you (cycle or )?discard/', '/\bmadness\b/', '/discarded this turn/']],
        'spells' => ['Instantâneas e feitiços', 'spells', 0.9, 'é uma instantânea ou feitiço', 'ganha com instantâneas e feitiços',
            [],
            ['/whenever you cast[^.]*(instant|sorcery)/', '/instant (and|or) sorcery spells? you (cast|control)/', '/\b(magecraft|prowess)\b/', '/whenever you (cast|copy) an instant or sorcery/']],
        'noncreature' => ['Mágicas não criatura', 'spells', 0.7, 'é uma mágica não criatura', 'ganha com mágicas não criatura',
            [],
            ['/whenever you cast a noncreature spell/', '/noncreature spells? you cast/']],
        'artifacts' => ['Artefatos', 'spells', 0.9, 'é ou cria artefatos', 'ganha com artefatos',
            ['/create[^.]*\b(artifact|treasure|clue|food|blood|map|powerstone|gold) tokens?/'],
            ['/whenever (an|another|one or more)( nontoken)? artifacts?[^.]*(enters?|cast|leaves|put into)/', '/whenever you cast an artifact spell/', '/artifacts? you control/', '/for each artifact/', '/\b(affinity for artifacts|improvise|metalcraft)\b/', '/artifact spells? you cast/']],
        'enchantments' => ['Encantamentos', 'spells', 1.0, 'é um encantamento', 'ganha com encantamentos',
            [],
            ['/whenever (you cast an|an|another)( nontoken)? enchantment/', '/enchantments? you control/', '/for each enchantment/', '/\bconstellation\b/', '/enchantment spells? you cast/']],
        'equipment' => ['Equipamentos e Auras', 'combat', 1.1, 'é um equipamento ou Aura', 'ganha com equipamentos e Auras',
            [],
            ['/whenever[^.]*becomes? (equipped|enchanted|attached)/', '/(auras?|equipment) you control/', '/for each (aura|equipment)/', '/equip abilities/', '/equip costs?/', '/attach (target|an|all) (equipment|auras?)/', '/equipped creatures? you control/']],
        'legendary' => ['Lendárias', 'spells', 0.8, 'é lendária', 'ganha com lendárias',
            [],
            ['/legendary (spells?|creatures?|permanents?) you (cast|control)/', '/\bhistoric\b/', '/whenever you cast a legendary/']],
        'combat_self' => ['Ataque desta carta', 'combat', 0.9, 'ajuda uma criatura a atacar', 'dispara ao atacar',
            ['/equipped creature (has|gets)[^.]*(haste|flying|can\'t be blocked|menace|trample|double strike|shroud|hexproof)/', '/enchanted creature (has|gets)[^.]*(haste|flying|can\'t be blocked|menace|trample|double strike)/', '/target creature (can\'t be blocked|gains (flying|haste|menace|trample))/', '/additional combat phase/', '/creatures you control (have|gain) haste/'],
            ['/whenever ~ attacks/', '/whenever ~ deals combat damage to (a player|an opponent)/', '/whenever equipped creature (attacks|deals combat damage)/']],
        'combat_team' => ['Ataques do time', 'combat', 0.6, 'dá evasão ou ataques extras', 'dispara quando criaturas atacam',
            ['/(other )?(creatures|\w+s) you control (have|gain|get)[^.]*(flying|menace|trample|haste|can\'t be blocked|islandwalk|forestwalk)/', '/additional combat phase/', '/target creature (can\'t be blocked|gains (flying|menace|trample))/'],
            ['/whenever (a|one or more|another)[^.]*creatures? you control[^.]*(attacks|deal combat damage to (a player|an opponent|one or more players))/', '/whenever you attack/', '/of the chosen type[^.]*attacks/']],
        'lifegain' => ['Ganho de vida', 'life', 1.0, 'ganha vida', 'aproveita ganho de vida',
            ['/\byou gain (\d+|x|that much|life equal)/', '/\bgains? (\d+|x) life/', '/\blifelink\b/'],
            ['/whenever you gain life/', '/if you would gain life/', '/you gained life this turn/', '/whenever you gain (\d+ or more )?life/']],
        'drain' => ['Perda de vida dos oponentes', 'life', 1.0, 'faz oponentes perderem vida', 'aproveita perda de vida',
            ['/(each opponent|target (player|opponent)|that player|defending player) loses (\d+|x|that much)/', '/deals? (\d+|x) damage to each opponent/'],
            ['/whenever (an opponent|a player|one or more opponents) loses? life/', '/opponents? (lost|loses) life this turn/']],
        'draw' => ['Compra de cartas', 'life', 0.7, 'compra cartas', 'ganha quando você compra',
            ['/\bdraws? (a|an|one|two|three|four|x|that many|cards equal|a card for each)\b/'],
            ['/whenever you draw/', '/draw your second card/', '/each card you draw/']],
        'theft' => ['Cartas dos oponentes', 'spells', 1.3, 'rouba ou conjura cartas alheias', 'ganha com cartas alheias',
            ['/gain control of/', '/exile the top[^.]*of (target|each) (opponent|player)/', '/you may (cast|play)[^.]*(opponent|you don\'t own)/'],
            ['/you don\'t own/', '/an opponent owns/', '/spells you cast that you don\'t own/']],
        'goad' => ['Goad', 'combat', 1.2, 'força ataques (goad)', 'aproveita criaturas forçadas a atacar',
            ['/\bgoads?\b/'],
            ['/goaded creatures?/', '/whenever a goaded/']],
        'energy' => ['Energia', 'resources', 1.2, 'gera energia', 'gasta energia',
            ['/you get \{e\}/'],
            ['/pay \{e\}/']],
        'poison' => ['Veneno', 'counters', 1.3, 'dá veneno', 'aproveita veneno',
            ['/\b(infect|toxic|poisonous)\b/', '/poison counters?/'],
            ['/\bproliferate\b/', '/(opponent|player) (has|with) (\w+ or more )?poison counters/']],
        'copy' => ['Cópias', 'spells', 0.9, 'copia mágicas ou permanentes', 'ganha com cópias',
            ['/\bcop(y|ies) (target|it|that)/', '/create a token that\'s a copy/'],
            ['/whenever you copy/', '/if you would copy/']],
    ];
}

/** Tags do Scryfall Tagger que reforçam as características (quando a tabela card_tags estiver sincronizada). */
function deckRelationTagMap(): array
{
    return [
        // tag => [características que fornece, características que aproveita]
        'sacrifice-outlet' => [['death'], ['fodder']], 'sac-outlet' => [['death'], ['fodder']], 'free-sacrifice-outlet' => [['death'], ['fodder']],
        'death-trigger' => [[], ['death']], 'death-payoff' => [[], ['death']], 'aristocrat' => [[], ['death']],
        'token-generator' => [['tokens', 'fodder', 'wide'], []], 'creature-token-generator' => [['tokens', 'fodder', 'wide'], []],
        'treasure-generator' => [['treasure', 'artifact_tokens'], []], 'food-generator' => [['food', 'artifact_tokens'], []], 'clue-generator' => [['clue', 'artifact_tokens'], []],
        'token-doubler' => [[], ['tokens']], 'counter-doubler' => [[], ['counters']], 'counter-increaser' => [[], ['counters']],
        'flicker' => [['etb'], []], 'blink' => [['etb'], []], 'etb-doubler' => [['etb'], []],
        'landfall' => [[], ['landfall']], 'extra-land' => [['landfall'], []], 'lands-matter' => [[], ['landfall']],
        'self-mill' => [['graveyard'], []], 'reanimate' => [[], ['graveyard']], 'cast-from-graveyard' => [[], ['graveyard']], 'recursion' => [[], ['graveyard']],
        'discard-outlet' => [['discard', 'graveyard'], []], 'madness-enabler' => [['discard'], []],
        'lifegain' => [['lifegain'], []], 'lifegain-payoff' => [[], ['lifegain']], 'draw-payoff' => [[], ['draw']],
        'evasion' => [['combat_team'], []], 'extra-combat-phase' => [['combat_self', 'combat_team'], []], 'haste-enabler' => [['combat_self'], []],
        'proliferate' => [[], ['proliferate', 'counters']], 'goad' => [['goad'], []], 'spellslinger-payoff' => [[], ['spells']],
    ];
}

function deckRelationSentences(string $text): array
{
    $text = preg_replace('/\([^)]*\)/u', '', $text) ?? $text;
    $parts = preg_split('/(?<=[.!])\s+(?=[A-Z{•+\-−0-9"])|\n+/u', $text) ?: [];
    return array_values(array_filter(array_map('trim', $parts), fn($part) => $part !== ''));
}

/** Tipos de criatura do catálogo (singular => plural), para reconhecer tribos no texto. Cache por sincronização. */
function deckRelationCreatureTypes(): array
{
    static $types = null;
    if ($types !== null) return $types;
    $lexicon = function_exists('deckScoreLexicon') ? deckScoreLexicon() : ['types' => []];
    $irregular = ['elf' => 'elves', 'dwarf' => 'dwarves', 'wolf' => 'wolves', 'werewolf' => 'werewolves', 'mouse' => 'mice', 'ox' => 'oxen', 'fungus' => 'fungi',
        'octopus' => 'octopuses', 'thief' => 'thieves', 'sheep' => 'sheep', 'fish' => 'fish', 'jellyfish' => 'jellyfish', 'cyclops' => 'cyclopes', 'sphinx' => 'sphinxes',
        'fox' => 'foxes', 'lich' => 'liches', 'witch' => 'witches', 'phoenix' => 'phoenixes', 'harpy' => 'harpies', 'faerie' => 'faeries', 'merfolk' => 'merfolk',
        'kor' => 'kor', 'moonfolk' => 'moonfolk', 'kithkin' => 'kithkin', 'fishfolk' => 'fishfolk', 'djinn' => 'djinn', 'efreet' => 'efreets', 'homunculus' => 'homunculi',
        'dryad' => 'dryads', 'ally' => 'allies', 'pony' => 'ponies', 'army' => 'armies', 'mercenary' => 'mercenaries', 'fury' => 'furies', 'sorcery' => 'sorceries'];
    // Palavras que são tipos de criatura mas quase sempre aparecem com outro sentido no texto.
    $ignore = ['will' => 1, 'god' => 1, 'wall' => 1, 'egg' => 1, 'mite' => 1, 'child' => 1, 'citizen' => 1, 'coward' => 1, 'sand' => 1, 'mutant' => 1, 'spawn' => 1,
        'scion' => 1, 'serf' => 1, 'shade' => 1, 'bringer' => 1, 'processor' => 1, 'incarnation' => 1, 'avatar' => 1, 'illusion' => 1, 'nightmare' => 1, 'spirit' => 0,
        'time' => 1, 'lord' => 1, 'bear' => 0, 'noble' => 1, 'performer' => 1, 'employee' => 1, 'detective' => 0, 'hero' => 1, 'role' => 1, 'gamer' => 1, 'survivor' => 1,
        'elemental' => 0, 'construct' => 0, 'human' => 0];
    $types = [];
    foreach ((array)($lexicon['types'] ?? []) as $type => $count) {
        $type = strtolower(trim((string)$type));
        if ($count < 4 || strlen($type) < 3 || !preg_match('/^[a-z\'-]+$/', $type) || !empty($ignore[$type])) continue;
        $plural = $irregular[$type] ?? (preg_match('/(s|x|ch|sh)$/', $type) ? $type . 'es' : (preg_match('/[^aeiou]y$/', $type) ? substr($type, 0, -1) . 'ies' : $type . 's'));
        $types[$type] = $plural;
    }
    return $types;
}

function deckRelationTypeRegex(): ?string
{
    static $regex = false;
    if ($regex !== false) return $regex;
    $forms = [];
    foreach (deckRelationCreatureTypes() as $singular => $plural) { $forms[$plural] = $singular; $forms[$singular] = $singular; }
    if (!$forms) return $regex = null;
    $keys = array_keys($forms);
    usort($keys, fn($a, $b) => strlen($b) <=> strlen($a));
    return $regex = '/(?<![a-z-])(non)?(' . implode('|', array_map(fn($k) => preg_quote($k, '/'), $keys)) . ')(?![a-z])/';
}

function deckRelationTypeSingular(string $form): string
{
    static $map = null;
    if ($map === null) {
        $map = [];
        foreach (deckRelationCreatureTypes() as $singular => $plural) { $map[$plural] = $singular; $map[$singular] = $singular; }
    }
    return $map[$form] ?? $form;
}

/** Tags sincronizadas do Scryfall Tagger por carta lógica (vazio se a tabela não existir). */
function deckRelationTags(array $logicalIds): array
{
    $logicalIds = array_values(array_unique(array_filter($logicalIds)));
    if (!$logicalIds) return [];
    static $tableExists = null;
    try {
        $tableExists ??= (bool)deckQuery("SELECT to_regclass('public.card_tags') IS NOT NULL")->fetchColumn();
        if (!$tableExists) return [];
        $rows = deckQuery('SELECT oracle_id::text, array_agg(tag) FROM card_tags WHERE oracle_id = ANY(?::uuid[]) AND tag = ANY(?::text[]) GROUP BY 1',
            ['{' . implode(',', $logicalIds) . '}', '{' . implode(',', array_keys(deckRelationTagMap())) . '}'])->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (Throwable) {
        return [];
    }
    $out = [];
    foreach ($rows as $id => $tags) $out[$id] = array_filter(explode(',', trim((string)$tags, '{}')));
    return $out;
}

/**
 * Perfil de relações de uma carta. Guarda, por característica, a primeira frase que a justifica.
 * $tags: tags do Scryfall Tagger dessa carta (opcional).
 */
function deckRelationProfile(array $card, array $tags = []): array
{
    static $cache = [];
    $key = (string)(($card['oracle_id'] ?? '') ?: ($card['id'] ?? '')) . '|' . implode(',', $tags);
    if (isset($cache[$key])) return $cache[$key];

    $name = strtolower((string)($card['name'] ?? ''));
    $front = explode(' // ', $name)[0];
    $short = [];
    // Lendárias costumam ser citadas pelo primeiro nome no Oracle ("Whenever Hakbal attacks").
    if (str_contains(strtolower((string)($card['type_line'] ?? '')), 'legendary') && preg_match('/^([a-z\'-]{4,})(,| of | the | from )/', $front, $m)) $short[] = $m[1];
    $aliases = array_unique(array_filter([$name, $front, explode(',', $front)[0], ...array_map('trim', explode(' // ', $name)), ...$short], fn($a) => strlen($a) >= 3));
    usort($aliases, fn($a, $b) => strlen($b) <=> strlen($a));
    $typeLine = (string)($card['type_line'] ?? '');
    $frontType = strtolower(explode(' // ', $typeLine)[0]);
    [$superTypes, $subTypes] = array_pad(explode('—', $frontType, 2), 2, '');
    $allTypes = strtolower($typeLine);
    $subtypeList = array_values(array_filter(preg_split('/\s+/', trim(str_replace('//', ' ', $subTypes))) ?: []));
    $isCreature = str_contains($allTypes, 'creature') || str_contains($allTypes, 'kindred') || str_contains($allTypes, 'tribal');

    $provides = [];
    $consumes = [];
    $features = deckRelationFeatures();
    $typeRegex = deckRelationTypeRegex();
    $tribesCared = [];
    $tribesMade = [];
    $chosenType = false;
    $createsForYou = false;
    $original = deckRelationSentences(deckText($card));
    foreach ($original as $sentence) {
        $lower = strtolower($sentence);
        foreach ($aliases as $alias) $lower = str_replace($alias, '~', $lower);
        $lower = preg_replace('/\bthis (creature|artifact|enchantment|land|permanent|card|vehicle|equipment|aura|spell|token|planeswalker|saga|battle)\b/', '~', $lower) ?? $lower;
        // Fichas que vão para outro jogador (Beast Within, Ravenform…) não contam como algo que você cria.
        $forOthers = (bool)preg_match('/(its controller|their controller|that player|target (player|opponent)|each opponent|an opponent|defending player|each other player) (creates?|may create)/', $lower);
        if (!$forOthers && preg_match('/\bcreates?\b/', $lower)) $createsForYou = true;
        foreach ($features as $feature => [, , , , , $provideRes, $consumeRes]) {
            if ($forOthers && in_array($feature, ['treasure', 'food', 'clue', 'blood', 'artifact_tokens', 'tokens', 'wide', 'fodder', 'enters', 'artifacts'], true)) { /* só aproveita */ }
            elseif (!isset($provides[$feature])) foreach ($provideRes as $re) if (preg_match($re, $lower)) { $provides[$feature] = $sentence; break; }
            if (!isset($consumes[$feature])) foreach ($consumeRes as $re) if (preg_match($re, $lower)) { $consumes[$feature] = $sentence; break; }
        }
        if (preg_match('/choose a creature type|creatures? (you control )?of the chosen type|chosen creature type|(spells?|cards?) of the chosen type/', $lower) && preg_match('/choose a creature type/', strtolower(deckText($card)))) $chosenType = $sentence;
        if ($typeRegex && preg_match_all($typeRegex, $lower, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                if ($match[1] === 'non') continue;
                $tribe = deckRelationTypeSingular($match[2]);
                if (preg_match('/creates?[^.]*\b' . preg_quote($match[2], '/') . '\b[^.]*tokens?/', $lower)) { if (!$forOthers) $tribesMade[$tribe] ??= $sentence; continue; }
                $tribesCared[$tribe] ??= $sentence;
            }
        }
    }
    // Uma instantânea "com fatiamento" (split) pode ter texto em ambas as faces; o tipo decide a característica.
    $typeEvidence = ucfirst(trim(explode(' // ', $typeLine)[0]));
    if (preg_match('/\b(instant|sorcery)\b/', $allTypes)) $provides['spells'] ??= $typeEvidence;
    if (!str_contains($frontType, 'creature') && !str_contains($frontType, 'land') && $frontType !== '') $provides['noncreature'] ??= $typeEvidence;
    if (str_contains($allTypes, 'artifact')) $provides['artifacts'] ??= $typeEvidence;
    if (str_contains($allTypes, 'enchantment')) $provides['enchantments'] ??= $typeEvidence;
    if (preg_match('/\b(equipment|aura)\b/', $allTypes)) {
        $provides['equipment'] ??= $typeEvidence;
        // O próprio equipamento fala de "criatura equipada": isso não é procurar outros equipamentos.
        if (isset($consumes['equipment']) && preg_match('/^(equipped|enchanted) creature/i', (string)$consumes['equipment'])) unset($consumes['equipment']);
    }
    if (str_contains($superTypes, 'legendary')) $provides['legendary'] ??= $typeEvidence;
    $isLand = str_contains($frontType, 'land');
    if ($isLand && !str_contains($frontType, 'basic')) $provides['landfall'] ??= $typeEvidence;
    if ($isLand && str_contains($frontType, 'basic')) $provides['landfall'] ??= $typeEvidence;
    // Fichas criadas (Scryfall all_parts): tipos e nomes exatos.
    $raw = $card['raw'] ?? null;
    if (is_string($raw)) $raw = json_decode($raw, true);
    $tokens = [];
    foreach ((array)($raw['all_parts'] ?? []) as $part) {
        if (($part['component'] ?? '') !== 'token' || !$createsForYou) continue;
        $tokenType = strtolower((string)($part['type_line'] ?? ''));
        $tokenName = (string)($part['name'] ?? '');
        $tokens[] = $tokenName;
        $evidence = 'Cria ficha: ' . $tokenName . ' (' . ($part['type_line'] ?? '') . ')';
        foreach (['treasure' => 'treasure', 'food' => 'food', 'clue' => 'clue', 'blood' => 'blood'] as $word => $feature) {
            if (preg_match('/\b' . $word . '\b/', $tokenType)) { $provides[$feature] ??= $evidence; $provides['artifact_tokens'] ??= $evidence; $provides['fodder'] ??= $evidence; }
        }
        if (str_contains($tokenType, 'artifact')) { $provides['artifact_tokens'] ??= $evidence; $provides['artifacts'] ??= $evidence; }
        if (str_contains($tokenType, 'creature')) {
            $provides['tokens'] ??= $evidence; $provides['fodder'] ??= $evidence; $provides['wide'] ??= $evidence; $provides['enters'] ??= $evidence;
            $tokenSub = trim(explode('—', $tokenType, 2)[1] ?? '');
            foreach (preg_split('/\s+/', $tokenSub) ?: [] as $sub) if ($sub !== '' && isset(deckRelationCreatureTypes()[$sub])) $tribesMade[$sub] ??= $evidence;
        }
    }
    // Tags do Scryfall Tagger complementam o texto (sem sobrescrever a frase encontrada).
    $tagged = [];
    foreach ($tags as $tag) {
        [$give, $take] = deckRelationTagMap()[$tag] ?? [[], []];
        foreach ($give as $feature) if (!isset($provides[$feature])) { $provides[$feature] = 'Tag do Scryfall Tagger: ' . $tag; $tagged[$feature] = true; }
        foreach ($take as $feature) if (!isset($consumes[$feature])) { $consumes[$feature] = 'Tag do Scryfall Tagger: ' . $tag; $tagged[$feature] = true; }
        if ($tag === 'creature-type-matters' && !$tribesCared) $chosenType = $chosenType ?: 'Tag do Scryfall Tagger: creature-type-matters';
    }

    $tribes = [];
    static $notTribes = ['treasure' => 1, 'food' => 1, 'clue' => 1, 'blood' => 1, 'equipment' => 1, 'vehicle' => 1, 'aura' => 1, 'saga' => 1, 'shrine' => 1, 'curse' => 1,
        'class' => 1, 'room' => 1, 'background' => 1, 'role' => 1, 'map' => 1, 'gold' => 1, 'powerstone' => 1, 'incubator' => 1, 'junk' => 1, 'case' => 1, 'lesson' => 1, 'spacecraft' => 1];
    if ($isCreature) foreach ($subtypeList as $subtype) if (!isset($notTribes[$subtype])) $tribes[$subtype] = ucfirst(trim(explode(' // ', $typeLine)[0]));
    $changeling = (bool)preg_match('/\bchangeling\b|is every creature type/i', deckText($card));
    // A própria tribo da carta citada no texto (ex.: "Outros Merfolk") continua sendo interesse; nomes de ficha criada não.
    foreach ($tribesMade as $tribe => $_) if (isset($tribesCared[$tribe]) && $tribesCared[$tribe] === $tribesMade[$tribe]) unset($tribesCared[$tribe]);
    if ($consumes['tokens'] ?? false) unset($tribesCared['token']);

    $roles = [];
    if ($isLand) $roles['lands'] = true;
    elseif (function_exists('deckScoreRolePatterns')) {
        $plain = strtolower(preg_replace('/\([^)]*\)/', '', deckText($card)) ?? '');
        foreach (deckScoreRolePatterns() as $role => $patterns) foreach ($patterns as $pattern) if (preg_match('/' . $pattern . '/', $plain)) { $roles[$role] = true; break; }
    }

    return $cache[$key] = [
        'provides' => $provides, 'consumes' => $consumes, 'tagged' => $tagged,
        'tribes' => $tribes, 'tribes_made' => $tribesMade, 'tribes_cared' => $tribesCared, 'changeling' => $changeling, 'chosen_type' => $chosenType,
        'tokens' => $tokens, 'is_land' => $isLand, 'is_basic' => $isLand && str_contains($frontType, 'basic'), 'is_creature' => $isCreature, 'roles' => array_keys($roles),
    ];
}

/** Tribo dominante da seleção (para cartas "escolha um tipo de criatura"). Prefere as tribos da comandante. */
function deckRelationDominantTribe(array $profiles, ?array $commanderProfile): ?string
{
    $count = [];
    foreach ($profiles as $profile) {
        foreach ($profile['tribes'] as $tribe => $_) $count[$tribe] = ($count[$tribe] ?? 0) + 1;
        foreach ($profile['tribes_made'] as $tribe => $_) $count[$tribe] = ($count[$tribe] ?? 0) + 1;
        foreach ($profile['tribes_cared'] as $tribe => $_) $count[$tribe] = ($count[$tribe] ?? 0) + 0.5;
    }
    if ($commanderProfile) {
        foreach ($commanderProfile['tribes_cared'] as $tribe => $_) $count[$tribe] = ($count[$tribe] ?? 0) + 6;
        foreach ($commanderProfile['tribes'] as $tribe => $_) $count[$tribe] = ($count[$tribe] ?? 0) + 2;
    }
    unset($count['human']);
    if (!$count) return null;
    arsort($count);
    $tribe = (string)array_key_first($count);
    return $count[$tribe] >= 4 ? $tribe : null;
}

/**
 * Motivos para a seta A → B (o que A fornece e B aproveita). Vazio quando não há relação.
 * $context['tribe']: tribo dominante para resolver "tipo escolhido".
 */
function deckRelationReasons(array $a, array $b, array $context = []): array
{
    $reasons = [];
    $features = deckRelationFeatures();
    foreach (array_intersect_key($a['provides'], $b['consumes']) as $feature => $fromText) {
        [$label, $group, $weight, $give, $take] = $features[$feature];
        // Terrenos básicos só "fornecem" terrenos entrando: relação real, mas fraca.
        if ($feature === 'landfall' && ($a['is_basic'] ?? false)) $weight = 0.3;
        $source = (isset($a['tagged'][$feature]) || isset($b['tagged'][$feature])) ? 'tag' : 'text';
        $reasons[] = ['key' => $feature, 'label' => $label, 'group' => $group, 'weight' => $weight, 'give' => $give, 'take' => $take,
            'from_text' => (string)$fromText, 'to_text' => (string)$b['consumes'][$feature], 'source' => $source];
    }
    $tribalWeight = 0.0;
    $tribalReasons = [];
    foreach ($b['tribes_cared'] as $tribe => $toText) {
        $fromText = $a['tribes'][$tribe] ?? $a['tribes_made'][$tribe] ?? ($a['changeling'] ? 'Changeling (todos os tipos de criatura)' : null);
        if ($fromText === null) continue;
        $made = !isset($a['tribes'][$tribe]) && isset($a['tribes_made'][$tribe]);
        $tribalReasons[] = ['key' => 'tribe:' . $tribe, 'label' => ucfirst($tribe), 'group' => 'tribal', 'weight' => 1.3,
            'give' => $made ? 'cria fichas de ' . ucfirst($tribe) : 'é ' . ucfirst($tribe), 'take' => 'fortalece ' . ucfirst($tribe),
            'from_text' => (string)$fromText, 'to_text' => (string)$toText, 'source' => 'tribe'];
        $tribalWeight += 1.3;
    }
    $tribe = $context['tribe'] ?? null;
    if ($tribe && $b['chosen_type'] && !isset($b['tribes_cared'][$tribe])) {
        $fromText = $a['tribes'][$tribe] ?? $a['tribes_made'][$tribe] ?? ($a['changeling'] ? 'Changeling (todos os tipos de criatura)' : null);
        if ($fromText !== null) $tribalReasons[] = ['key' => 'tribe:' . $tribe, 'label' => ucfirst($tribe) . ' (tipo escolhido)', 'group' => 'tribal', 'weight' => 1.1,
            'give' => (!isset($a['tribes'][$tribe]) && isset($a['tribes_made'][$tribe]) ? 'cria fichas de ' : 'é ') . ucfirst($tribe), 'take' => 'usa o tipo escolhido (' . ucfirst($tribe) . ')', 'from_text' => (string)$fromText, 'to_text' => (string)$b['chosen_type'], 'source' => 'tribe'];
    }
    return array_merge($tribalReasons, $reasons);
}

function deckRelationWeight(array $reasons): float
{
    $total = 0.0;
    foreach ($reasons as $reason) $total += (float)$reason['weight'];
    return round($total, 2);
}

/**
 * Grafo de relações de um conjunto de cartas.
 * $nodes: [id => carta (linha de cards com stage opcional)]. Devolve ['edges' => [...], 'tribe' => ?string, 'profiles' => [...]].
 */
function deckRelationGraph(array $nodes, ?array $commander = null): array
{
    $tagsByLogical = deckRelationTags(array_map(fn($card) => (string)(($card['oracle_id'] ?? '') ?: $card['id']), array_values($nodes)));
    $profiles = [];
    foreach ($nodes as $id => $card) $profiles[$id] = deckRelationProfile($card, $tagsByLogical[(string)(($card['oracle_id'] ?? '') ?: $card['id'])] ?? []);
    $commanderProfile = $commander ? ($profiles[$commander['id']] ?? deckRelationProfile($commander)) : null;
    $tribe = deckRelationDominantTribe($profiles, $commanderProfile);
    $edges = [];
    // Índice invertido: para cada característica, quem aproveita. Evita testar todos os pares.
    $consumers = [];
    $tribeCarers = [];
    $chosenCarers = [];
    foreach ($profiles as $id => $profile) {
        foreach ($profile['consumes'] as $feature => $_) $consumers[$feature][] = $id;
        foreach ($profile['tribes_cared'] as $name => $_) $tribeCarers[$name][] = $id;
        if ($profile['chosen_type']) $chosenCarers[] = $id;
    }
    foreach ($profiles as $fromId => $profile) {
        $targets = [];
        foreach ($profile['provides'] as $feature => $_) foreach ($consumers[$feature] ?? [] as $toId) $targets[$toId] = true;
        $fromTribes = $profile['changeling'] ? array_keys($tribeCarers) : array_keys($profile['tribes'] + $profile['tribes_made']);
        foreach ($fromTribes as $name) foreach ($tribeCarers[$name] ?? [] as $toId) $targets[$toId] = true;
        if ($tribe && ($profile['changeling'] || isset($profile['tribes'][$tribe]) || isset($profile['tribes_made'][$tribe]))) foreach ($chosenCarers as $toId) $targets[$toId] = true;
        foreach (array_keys($targets) as $toId) {
            if ((string)$toId === (string)$fromId) continue;
            $reasons = deckRelationReasons($profile, $profiles[$toId], ['tribe' => $tribe]);
            if ($reasons) $edges[] = ['from' => (string)$fromId, 'to' => (string)$toId, 'weight' => deckRelationWeight($reasons), 'reasons' => $reasons];
        }
    }
    return ['edges' => $edges, 'tribe' => $tribe, 'profiles' => $profiles];
}

/** Combos conhecidos cujas peças estão todas entre as cartas: arestas "combo" entre as peças. */
function deckRelationComboEdges(array $nodes, array $combos): array
{
    $byName = [];
    foreach ($nodes as $id => $card) {
        $name = strtolower((string)$card['name']);
        $byName[$name] = (string)$id;
        $byName[strtolower(explode(' // ', $name)[0])] = (string)$id;
    }
    $edges = [];
    $found = [];
    foreach ($combos as $combo) {
        $pieces = [];
        foreach ((array)($combo['cards'] ?? []) as $piece) {
            $key = strtolower(trim((string)$piece));
            if (isset($byName[$key])) $pieces[] = $byName[$key];
        }
        $pieces = array_values(array_unique($pieces));
        $total = count((array)($combo['cards'] ?? []));
        if (count($pieces) < 2 || count($pieces) < $total) continue;
        $result = implode(' ', array_slice(array_map('strval', (array)($combo['results'] ?? [])), 0, 3));
        $found[] = ['cards' => $pieces, 'names' => (array)$combo['cards'], 'result' => $result, 'source' => (string)($combo['source'] ?? 'EDHREC'), 'href' => (string)($combo['href'] ?? '')];
        for ($i = 0; $i < count($pieces); $i++) {
            $from = $pieces[$i];
            $to = $pieces[($i + 1) % count($pieces)];
            if ($from === $to || (count($pieces) === 2 && $i === 1)) continue;
            $edges[] = ['from' => $from, 'to' => $to, 'weight' => 3.0, 'reasons' => [[
                'key' => 'combo', 'label' => 'Combo', 'group' => 'combo', 'weight' => 3.0, 'give' => 'forma um combo com', 'take' => 'completa o combo',
                'from_text' => implode(' + ', (array)$combo['cards']), 'to_text' => $result !== '' ? $result : 'Combo conhecido (' . ($combo['source'] ?? 'EDHREC') . ')', 'source' => 'combo']]];
        }
    }
    return ['edges' => $edges, 'combos' => $found];
}

/** Frase curta em português para uma relação A → B. */
function deckRelationSentence(string $fromName, string $toName, array $reason): string
{
    return $fromName . ' ' . $reason['give'] . ' → ' . $toName . ' ' . $reason['take'] . '.';
}

/**
 * Relações de cartas de fora (ex.: coleção no Explorar) com a seleção atual.
 * Devolve [id da carta de fora => ['score' => float, 'partners' => int, 'links' => [['name','direction','reasons']]]].
 */
function deckRelationPreview(array $outsiders, array $selection, ?array $commander): array
{
    if (!$outsiders || !$selection) return [];
    $all = $selection + $outsiders;
    $tags = deckRelationTags(array_map(fn($card) => (string)(($card['oracle_id'] ?? '') ?: $card['id']), array_values($all)));
    $selProfiles = [];
    foreach ($selection as $id => $card) $selProfiles[$id] = deckRelationProfile($card, $tags[(string)(($card['oracle_id'] ?? '') ?: $card['id'])] ?? []);
    $commanderProfile = $commander ? ($selProfiles[$commander['id']] ?? null) : null;
    $tribe = deckRelationDominantTribe($selProfiles, $commanderProfile);
    $consumers = []; $tribeCarers = []; $chosenCarers = []; $providers = []; $tribeHolders = [];
    foreach ($selProfiles as $id => $profile) {
        foreach ($profile['consumes'] as $feature => $_) $consumers[$feature][] = $id;
        foreach ($profile['provides'] as $feature => $_) $providers[$feature][] = $id;
        foreach ($profile['tribes_cared'] as $name => $_) $tribeCarers[$name][] = $id;
        foreach (array_keys($profile['tribes'] + $profile['tribes_made']) as $name) $tribeHolders[$name][] = $id;
        if ($profile['changeling']) $tribeHolders['*'][] = $id;
        if ($profile['chosen_type']) $chosenCarers[] = $id;
    }
    $out = [];
    foreach ($outsiders as $id => $card) {
        $profile = deckRelationProfile($card, $tags[(string)(($card['oracle_id'] ?? '') ?: $card['id'])] ?? []);
        $partners = [];
        foreach ($profile['provides'] as $feature => $_) foreach ($consumers[$feature] ?? [] as $other) $partners[$other] = true;
        foreach ($profile['consumes'] as $feature => $_) foreach ($providers[$feature] ?? [] as $other) $partners[$other] = true;
        $mine = $profile['changeling'] ? array_keys($tribeCarers) : array_keys($profile['tribes'] + $profile['tribes_made']);
        foreach ($mine as $name) foreach ($tribeCarers[$name] ?? [] as $other) $partners[$other] = true;
        foreach ($profile['tribes_cared'] as $name => $_) { foreach ($tribeHolders[$name] ?? [] as $other) $partners[$other] = true; foreach ($tribeHolders['*'] ?? [] as $other) $partners[$other] = true; }
        if ($tribe && ($profile['changeling'] || isset($profile['tribes'][$tribe]) || isset($profile['tribes_made'][$tribe]))) foreach ($chosenCarers as $other) $partners[$other] = true;
        if ($profile['chosen_type'] && $tribe) foreach ($tribeHolders[$tribe] ?? [] as $other) $partners[$other] = true;
        $links = [];
        $score = 0.0;
        foreach (array_keys($partners) as $other) {
            if ((string)$other === (string)$id) continue;
            $give = deckRelationReasons($profile, $selProfiles[$other], ['tribe' => $tribe]);
            $take = deckRelationReasons($selProfiles[$other], $profile, ['tribe' => $tribe]);
            if (!$give && !$take) continue;
            $weight = deckRelationWeight($give) + deckRelationWeight($take);
            // Relações com a comandante valem mais: ela está sempre em jogo.
            if ($commander && (string)$other === (string)$commander['id']) $weight *= 1.6;
            $score += $weight;
            $links[] = ['id' => (string)$other, 'name' => (string)$selection[$other]['name'], 'stage' => (string)($selection[$other]['stage'] ?? ''), 'weight' => round($weight, 2), 'gives' => $give, 'takes' => $take];
        }
        if (!$links) continue;
        usort($links, fn($x, $y) => $y['weight'] <=> $x['weight']);
        $out[(string)$id] = ['score' => round($score, 2), 'partners' => count($links), 'links' => $links];
    }
    return $out;
}

/** POST JSON com tempo limite; devolve [status, dados|null]. */
function deckHttpPostJson(string $url, array $body, int $timeout = 20): array
{
    $context = stream_context_create(['http' => ['method' => 'POST', 'timeout' => $timeout, 'ignore_errors' => true,
        'header' => "Content-Type: application/json\r\nAccept: application/json\r\nUser-Agent: Deckarium/1.0 (colecao pessoal; creditos a commanderspellbook.com)\r\n",
        'content' => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]]);
    $raw = @file_get_contents($url, false, $context);
    $status = 0;
    foreach (($http_response_header ?? []) as $line) if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) $status = (int)$m[1];
    if ($raw === false) return [$status, null];
    $data = json_decode($raw, true);
    return [$status, is_array($data) ? $data : null];
}

function deckSpellbookBase(): string
{
    return rtrim((string)(getenv('SPELLBOOK_API_BASE') ?: 'https://backend.commanderspellbook.com'), '/');
}

/** Converte uma variante do Commander Spellbook no formato de combo usado pelo quadro. */
function deckSpellbookVariant(array $variant): array
{
    $cards = [];
    foreach ((array)($variant['uses'] ?? []) as $use) {
        $card = $use['card'] ?? null;
        $name = is_array($card) ? (string)($card['name'] ?? '') : (string)($card ?? ($use['name'] ?? ''));
        if ($name !== '') $cards[] = $name;
    }
    $results = [];
    foreach ((array)($variant['produces'] ?? []) as $produce) {
        $feature = $produce['feature'] ?? null;
        $name = is_array($feature) ? (string)($feature['name'] ?? '') : (string)($feature ?? ($produce['name'] ?? ''));
        if ($name !== '') $results[] = $name;
    }
    $id = (string)($variant['id'] ?? '');
    $requires = [];
    foreach ((array)($variant['requires'] ?? []) as $template) {
        $name = is_array($template['template'] ?? null) ? (string)($template['template']['name'] ?? '') : '';
        if ($name !== '') $requires[] = $name;
    }
    return ['id' => $id, 'cards' => $cards, 'requires' => $requires, 'results' => $results, 'steps' => (string)($variant['description'] ?? ''),
        'prerequisites' => trim((string)($variant['notablePrerequisites'] ?? $variant['otherPrerequisites'] ?? '')),
        'bracket' => (string)($variant['bracketTag'] ?? ''), 'popularity' => isset($variant['popularity']) ? (int)$variant['popularity'] : null,
        'href' => $id !== '' ? 'https://commanderspellbook.com/combo/' . rawurlencode($id) . '/' : '', 'source' => 'Commander Spellbook'];
}

/**
 * Consulta o "Find My Combos" do Commander Spellbook para a lista (comandante + cartas) e guarda o resultado por deck.
 * Devolve ['included' => [...], 'almost' => [...], 'synced_at' => string].
 */
function deckSpellbookFindCombos(int $deckId, array $commander, array $cards): array
{
    $main = [];
    foreach ($cards as $card) $main[] = ['card' => (string)$card['name'], 'quantity' => max(1, (int)($card['quantity'] ?? 1))];
    [$status, $data] = deckHttpPostJson(deckSpellbookBase() . '/find-my-combos', ['commanders' => [['card' => (string)$commander['name'], 'quantity' => 1]], 'main' => $main]);
    if ($status === 429) throw new RuntimeException('O Commander Spellbook pediu para esperar um pouco antes da próxima consulta. Tente de novo em 1 minuto.');
    if ($data === null || $status >= 400) throw new RuntimeException('O Commander Spellbook não respondeu agora' . ($status ? ' (HTTP ' . $status . ')' : '') . '. Os combos do EDHREC continuam no quadro.');
    $results = is_array($data['results'] ?? null) ? $data['results'] : $data;
    $pick = static function (array $keys) use ($results): array {
        foreach ($keys as $key) {
            if (!isset($results[$key]) || !is_array($results[$key])) continue;
            return array_values(array_filter(array_map(fn($v) => is_array($v) ? deckSpellbookVariant($v) : null, $results[$key]), fn($v) => $v && $v['cards']));
        }
        return [];
    };
    $payload = ['included' => $pick(['included']), 'almost' => $pick(['almostIncluded', 'almost_included']), 'synced_at' => date(DATE_ATOM)];
    deckQuery('INSERT INTO deck_spellbook_cache(deck_id,payload,synced_at) VALUES (?,?::jsonb,now()) ON CONFLICT (deck_id) DO UPDATE SET payload=excluded.payload,synced_at=now()',
        [$deckId, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    return $payload;
}

function deckSpellbookCached(int $deckId): ?array
{
    try {
        $raw = deckQuery('SELECT payload FROM deck_spellbook_cache WHERE deck_id=?', [$deckId])->fetchColumn();
    } catch (Throwable) {
        return null;
    }
    $payload = $raw ? json_decode((string)$raw, true) : null;
    return is_array($payload) ? $payload : null;
}
