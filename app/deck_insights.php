<?php
declare(strict_types=1);

/**
 * Guia da comandante: temas, combos, cartas novas e comandantes parecidos do EDHREC,
 * somados às mecânicas encontradas no catálogo local.
 * Carregado por deck_library.php.
 */

const DECK_INSIGHTS_COMBO_LIMIT = 8;
const DECK_INSIGHTS_VERSION = 2;

/** Converte listas do EDHREC (texto ou objetos com description/text/name) em lista de textos. */
function deckInsightTextList(mixed $value): array
{
    $out = [];
    foreach ((array)$value as $item) {
        if (is_array($item)) $item = $item['description'] ?? $item['text'] ?? $item['name'] ?? $item['value'] ?? null;
        if (is_scalar($item)) {
            $text = trim((string)$item);
            if ($text !== '' && $text !== 'Array') $out[] = $text;
        }
    }
    return $out;
}

function deckStoreCommanderInsights(array $commander, array $data, string $source): void
{
    $panels = is_array($data['panels'] ?? null) ? $data['panels'] : [];
    $themes = [];
    foreach (array_slice((array)($panels['taglinks'] ?? []), 0, 12) as $tag) {
        if (!is_array($tag) || trim((string)($tag['value'] ?? '')) === '') continue;
        $themes[] = ['name' => (string)$tag['value'], 'slug' => (string)($tag['slug'] ?? ''), 'count' => (int)($tag['count'] ?? 0)];
    }

    $lists = [];
    $deckCount = 0;
    foreach ((array)($data['container']['json_dict']['cardlists'] ?? []) as $list) {
        if (!is_array($list)) continue;
        $tag = (string)($list['tag'] ?? '');
        foreach ((array)($list['cardviews'] ?? []) as $view) {
            if (is_array($view)) $deckCount = max($deckCount, (int)($view['potential_decks'] ?? 0));
        }
        if ($tag !== '') $lists[$tag] = $list;
    }
    $newCards = [];
    foreach (array_slice((array)($lists['newcards']['cardviews'] ?? []), 0, 12) as $view) {
        if (!is_array($view) || empty($view['name'])) continue;
        $newCards[] = [
            'name' => (string)$view['name'],
            'synergy' => isset($view['synergy']) ? (float)$view['synergy'] : null,
            'num_decks' => (int)($view['num_decks'] ?? 0),
            'potential_decks' => (int)($view['potential_decks'] ?? 0),
            'trend' => isset($view['trend_zscore']) ? (float)$view['trend_zscore'] : null,
        ];
    }

    $similar = [];
    foreach (array_slice((array)($data['similar'] ?? []), 0, 8) as $entry) {
        $name = is_array($entry) ? (string)($entry['name'] ?? '') : (string)$entry;
        if ($name !== '') $similar[] = $name;
    }

    $combos = [];
    $started = microtime(true);
    foreach (array_slice((array)($panels['combocounts'] ?? []), 0, DECK_INSIGHTS_COMBO_LIMIT) as $entry) {
        if (!is_array($entry) || trim((string)($entry['value'] ?? '')) === '') continue;
        $href = (string)($entry['href'] ?? '');
        $combo = [
            'cards' => array_values(array_filter(array_map('trim', explode(' + ', (string)$entry['value'])))),
            'href' => $href !== '' ? 'https://edhrec.com' . $href : '',
            'results' => [], 'prerequisites' => [], 'steps' => [],
            'count' => null, 'percentage' => null, 'bracket' => null,
        ];
        // Detalhes do combo (resultado, pré-requisitos, passos) com orçamento de tempo total.
        if (preg_match('#^/combos/[a-z0-9-]+/[0-9-]+$#', $href) && microtime(true) - $started < 15) {
            $detail = deckHttpJson(deckEdhrecBase() . '/pages' . $href . '.json', 6);
            $info = is_array($detail['combo'] ?? null) ? $detail['combo'] : [];
            $combo['results'] = deckInsightTextList($info['results'] ?? []);
            $combo['prerequisites'] = deckInsightTextList($info['prerequisites'] ?? []);
            $combo['steps'] = deckInsightTextList($info['steps'] ?? []);
            $combo['count'] = isset($info['count']) ? (int)$info['count'] : null;
            $combo['percentage'] = isset($info['percentage']) ? (float)$info['percentage'] : null;
            $bracket = $info['comboVote']['bracket'] ?? $info['bracket'] ?? null;
            $combo['bracket'] = is_scalar($bracket) ? (string)$bracket : null;
        }
        $combos[] = $combo;
    }

    $curve = [];
    foreach ((array)($panels['mana_curve'] ?? []) as $cost => $amount) {
        if (is_numeric($cost) && is_numeric($amount)) $curve[(int)$cost] = (int)$amount;
    }
    ksort($curve);

    $payload = [
        'version' => DECK_INSIGHTS_VERSION,
        'deck_count' => $deckCount,
        'themes' => $themes,
        'combos' => $combos,
        'new_cards' => $newCards,
        'similar' => $similar,
        'mana_curve' => $curve,
    ];
    deckQuery('INSERT INTO deck_commander_insights(commander_id,source_url,payload,synced_at) VALUES (?,?,?::jsonb,now())
        ON CONFLICT (commander_id) DO UPDATE SET source_url=excluded.source_url,payload=excluded.payload,synced_at=now()',
        [$commander['id'], $source, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
}

function deckCommanderInsights(array $commander): ?array
{
    // Qualquer impressão da mesma carta compartilha o guia.
    $row = deckQuery('SELECT i.payload,i.source_url,i.synced_at FROM deck_commander_insights i JOIN cards c ON c.id=i.commander_id
        WHERE COALESCE(c.oracle_id,c.id)=?::uuid ORDER BY i.synced_at DESC LIMIT 1', [$commander['oracle_id'] ?: $commander['id']])->fetch();
    if (!$row) return null;
    $payload = json_decode((string)$row['payload'], true);
    if (!is_array($payload)) return null;
    // Guias salvos pela primeira versão podem conter "Array" no lugar dos textos dos combos.
    foreach ((array)($payload['combos'] ?? []) as $index => $combo) {
        foreach (['results', 'prerequisites', 'steps'] as $field) $payload['combos'][$index][$field] = deckInsightTextList($combo[$field] ?? []);
    }
    return $payload + ['source_url' => $row['source_url'], 'synced_at' => $row['synced_at']];
}

/** Sincroniza o EDHREC ao escolher a comandante, sem bloquear a escolha se a fonte falhar. */
function deckRefreshInsightsIfStale(array $commander, int $maxAgeDays = 7): ?string
{
    $fresh = deckQuery("SELECT 1 FROM deck_commander_insights i JOIN cards c ON c.id=i.commander_id
        WHERE COALESCE(c.oracle_id,c.id)=?::uuid AND i.synced_at > now() - make_interval(days => ?) AND COALESCE((i.payload->>'version')::int,1) >= ?",
        [$commander['oracle_id'] ?: $commander['id'], $maxAgeDays, DECK_INSIGHTS_VERSION])->fetchColumn();
    if ($fresh) return null;
    try {
        $saved = deckSyncEdhrec($commander);
        deckWarmGuide($commander);
        return $saved . ' recomendações e o guia da comandante foram carregados do EDHREC.';
    } catch (Throwable) {
        return deckCommanderInsights($commander)
            ? 'O guia da comandante foi carregado do EDHREC.'
            : 'Não foi possível consultar o EDHREC agora; use “Atualizar dados do EDHREC” mais tarde.';
    }
}

/** Pré-calcula o guia logo após sincronizar, para a próxima abertura da página já vir do cache. */
function deckWarmGuide(array $commander): void
{
    if (!function_exists('catalogCached')) return;
    try {
        deckGuidePlans($commander, deckCommanderInsights($commander));
        deckGuideMechanics($commander);
        if (function_exists('deckNeedRoleIndex')) deckNeedRoleIndex();
    } catch (Throwable) {
        // O cache é só uma otimização.
    }
}

/** Como transformar um tema do EDHREC em busca no catálogo local. */
function deckThemeProfile(string $name, string $slug = ''): array
{
    $key = strtolower($slug !== '' ? $slug : preg_replace('/[^a-z0-9]+/i', '-', $name));
    $map = [
        'aristocrats' => ['Aristocrats', 'Sacrifique criaturas e ganhe valor ou drene a mesa a cada morte.', ['sacrifice', 'dies'], 'oracle'],
        'sacrifice' => ['Sacrifício', 'Transforme permanentes descartáveis em recursos com saídas de sacrifício.', ['sacrifice'], 'oracle'],
        'tokens' => ['Fichas', 'Crie muitas fichas e converta quantidade em pressão ou valor.', ['token'], 'oracle'],
        'treasure' => ['Tesouros', 'Gere fichas de Tesouro para acelerar mana e alimentar efeitos de artefato.', ['treasure'], 'oracle'],
        'theft' => ['Roubo', 'Use as cartas dos oponentes: ganhe controle, exile e conjure o que é deles.', ["gain control", "you don't own", 'an opponent owns'], 'oracle'],
        'aggro' => ['Aggro', 'Pressione cedo com criaturas eficientes e ataques frequentes.', ['haste', 'attacks'], 'oracle'],
        'voltron' => ['Voltron', 'Concentre equipamentos e auras em uma ameaça difícil de parar.', ['equip', 'enchant creature'], 'oracle'],
        'equipment' => ['Equipamentos', 'Equipe suas criaturas para ganhar força e habilidades.', ['equip'], 'oracle'],
        'auras' => ['Auras', 'Encante criaturas para criar uma ameaça poderosa.', ['enchant creature'], 'oracle'],
        'reanimator' => ['Reanimação', 'Coloque ameaças no cemitério e traga-as de volta ao campo.', ['return target creature card from your graveyard', 'graveyard to the battlefield'], 'oracle'],
        'graveyard' => ['Cemitério', 'Use o cemitério como uma segunda mão.', ['graveyard'], 'oracle'],
        'self-mill' => ['Moer a si mesmo', 'Encha o próprio cemitério para abastecer recursão.', ['mill'], 'oracle'],
        'mill' => ['Moer', 'Esvazie a biblioteca dos oponentes.', ['mill'], 'oracle'],
        'landfall' => ['Landfall', 'Gatilhos toda vez que um terreno entra sob seu controle.', ['landfall'], 'oracle'],
        'lands' => ['Terrenos', 'Jogue terrenos extras e recupere-os do cemitério.', ['additional land', 'land card'], 'oracle'],
        'ramp' => ['Ramp', 'Acelere a mana para jogar ameaças grandes antes da mesa.', ['search your library for a basic land', 'add {'], 'oracle'],
        'lifegain' => ['Ganho de vida', 'Ganhe vida repetidamente e transforme isso em vantagem.', ['gain life', 'lifelink'], 'oracle'],
        '1-1-counters' => ['Marcadores +1/+1', 'Distribua e multiplique marcadores +1/+1.', ['+1/+1 counter'], 'oracle'],
        'counters' => ['Marcadores', 'Coloque e multiplique marcadores em permanentes.', ['counter on', 'proliferate'], 'oracle'],
        'proliferate' => ['Proliferar', 'Aumente todos os tipos de marcadores de uma vez.', ['proliferate'], 'oracle'],
        'spellslinger' => ['Spellslinger', 'Conjure muitas mágicas instantâneas e feitiços.', ['instant or sorcery'], 'oracle'],
        'storm' => ['Storm', 'Encadeie várias mágicas no mesmo turno.', ['storm', 'copy target instant or sorcery'], 'oracle'],
        'artifacts' => ['Artefatos', 'Construa em torno de artefatos e seus gatilhos.', ['artifact'], 'oracle'],
        'enchantress' => ['Encantamentos', 'Compre cartas e ganhe valor conjurando encantamentos.', ['enchantment spell'], 'oracle'],
        'blink' => ['Blink', 'Exile e devolva permanentes para repetir efeitos de entrada.', ['exile another target', 'return it to the battlefield', 'return that card to the battlefield'], 'oracle'],
        'flicker' => ['Flicker', 'Exile e devolva permanentes para repetir efeitos de entrada.', ['return it to the battlefield', 'return that card to the battlefield'], 'oracle'],
        'control' => ['Controle', 'Responda às ameaças e vença com vantagem incremental.', ['counter target', 'destroy target', 'exile target'], 'oracle'],
        'stax' => ['Stax', 'Restrinja recursos dos oponentes enquanto seu plano avança.', ["can't cast", "don't untap", 'costs {1} more'], 'oracle'],
        'wheels' => ['Wheels', 'Faça todos descartarem e comprarem novas mãos.', ['discards their hand', 'discard your hand'], 'oracle'],
        'group-hug' => ['Group Hug', 'Distribua recursos para a mesa e conduza a política.', ['each player draws', 'each player may'], 'oracle'],
        'politics' => ['Política', 'Negocie, incentive ataques e escolha seus aliados.', ['each opponent', 'goad', 'vote'], 'oracle'],
        'goad' => ['Goad', 'Force criaturas adversárias a atacarem outros jogadores.', ['goad'], 'oracle'],
        'burn' => ['Dano direto', 'Cause dano aos oponentes sem depender de combate.', ['damage to each opponent', 'damage to any target'], 'oracle'],
        'infect' => ['Infect', 'Vença com marcadores de veneno.', ['infect', 'poison counter'], 'oracle'],
        'poison' => ['Veneno', 'Vença com marcadores de veneno.', ['toxic', 'poison counter', 'infect'], 'oracle'],
        'vehicles' => ['Veículos', 'Tripule veículos para atacar com artefatos resistentes.', ['vehicle', 'crew'], 'type'],
        'go-wide' => ['Go Wide', 'Monte uma mesa larga e fortaleça todas as criaturas.', ['creatures you control get'], 'oracle'],
        'extra-combats' => ['Combates extras', 'Ataque mais de uma vez por turno.', ['additional combat phase'], 'oracle'],
        'extra-turns' => ['Turnos extras', 'Encadeie turnos adicionais.', ['extra turn'], 'oracle'],
        'card-draw' => ['Compra de cartas', 'Mantenha a mão cheia e escolha o melhor plano.', ['draw a card', 'draw two cards'], 'oracle'],
        'cascade' => ['Cascata', 'Ganhe mágicas grátis ao conjurar.', ['cascade'], 'oracle'],
        'cheatin' => ['Colocar em jogo', 'Coloque ameaças grandes no campo sem pagar o custo.', ['put it onto the battlefield', 'onto the battlefield'], 'oracle'],
        'dragons' => ['Dragões', 'Tribal de Dragões voadores e poderosos.', ['dragon'], 'type'],
        'big-mana' => ['Big Mana', 'Gere muita mana e use-a em efeitos grandes.', ['add {c}{c}', 'double the amount of mana'], 'oracle'],
        'exile' => ['Exílio', 'Aproveite cartas exiladas e efeitos que as reutilizam.', ['exile the top', 'cards exiled with'], 'oracle'],
        'pingers' => ['Pingers', 'Cause pequenos danos repetidos.', ['deals 1 damage'], 'oracle'],
        'toughness-matters' => ['Resistência importa', 'Use a resistência das criaturas para causar dano.', ['toughness rather than its power'], 'oracle'],
        'legends' => ['Lendárias', 'Recompense conjurar permanentes lendárias.', ['legendary'], 'type'],
        'historic' => ['Históricas', 'Artefatos, lendárias e sagas disparam seus efeitos.', ['historic'], 'oracle'],
        'sagas' => ['Sagas', 'Aproveite capítulos de sagas e repita-os.', ['saga'], 'type'],
    ];
    // Estratégia local (deckStrategyCatalog) que cada tema já cobre, para não repetir planos.
    $localEquivalent = ['aristocrats' => 'sacrifice', 'sacrifice' => 'sacrifice', 'tokens' => 'tokens', 'go-wide' => 'tokens', 'reanimator' => 'graveyard', 'graveyard' => 'graveyard', 'self-mill' => 'graveyard',
        'ramp' => 'ramp', 'lands' => 'ramp', 'landfall' => 'ramp', 'big-mana' => 'ramp', 'blink' => 'blink', 'flicker' => 'blink', 'voltron' => 'voltron', 'equipment' => 'voltron', 'auras' => 'voltron',
        'control' => 'control', 'stax' => 'control', 'dragons' => 'tribal', 'vehicles' => null];
    if (isset($map[$key])) {
        [$label, $description, $patterns, $mode] = $map[$key];
        return ['label' => $label, 'description' => $description, 'patterns' => $patterns, 'mode' => $mode, 'local' => $localEquivalent[$key] ?? null];
    }
    // Temas não mapeados costumam ser tribos (Pirates, Elves, Zombies).
    $word = strtolower(trim($name));
    $singular = preg_replace('/(ies)$/', 'y', $word);
    if ($singular === $word) $singular = preg_replace('/(ves)$/', 'f', $word);
    if ($singular === $word && strlen($word) > 4 && str_ends_with($word, 's') && !str_ends_with($word, 'ss')) $singular = substr($word, 0, -1);
    return ['label' => $name, 'description' => 'Tema recorrente nas listas desta comandante. Priorize cartas que recompensam ' . $name . '.', 'patterns' => [$singular], 'mode' => 'type', 'local' => 'tribal'];
}

/** Planos de jogo: temas reais do EDHREC primeiro, completados pela leitura local do texto da comandante. */
function deckGuidePlans(array $commander, ?array $insights): array
{
    $plans = [];
    $stamp = (string)($insights['synced_at'] ?? '');
    $themes = (array)($insights['themes'] ?? []);
    $topCount = max(1, (int)($themes[0]['count'] ?? 0));
    $covered = [];
    foreach (array_slice($themes, 0, 6) as $theme) {
        $profile = deckThemeProfile((string)$theme['name'], (string)($theme['slug'] ?? ''));
        if ($profile['local']) $covered[] = $profile['local'];
        $plans[] = $profile + [
            'source' => 'edhrec',
            'count' => (int)$theme['count'],
            'share' => (int)round((int)$theme['count'] / $topCount * 100),
            'cards' => deckGuidePlanCards($commander, $profile['patterns'], $stamp),
        ];
    }
    if (count($plans) < 6) {
        foreach (deckCommanderStrategies($commander) as $strategy) {
            if (count($plans) >= 6) break;
            $label = (string)$strategy['name'];
            if (in_array($strategy['slug'], $covered, true)) continue;
            $plans[] = [
                'label' => $label, 'description' => (string)$strategy['description'], 'patterns' => $strategy['patterns'], 'mode' => 'oracle',
                'local' => $strategy['slug'], 'source' => 'local', 'count' => null, 'share' => null,
                'cards' => deckGuidePlanCards($commander, $strategy['patterns'], $stamp),
            ];
        }
    }
    return $plans;
}

/**
 * Cartas de um plano. A busca no catálogo é pesada (ILIKE em ~120 mil impressões), então o
 * resultado fica em cache por comandante + termos + sincronização do EDHREC; a posse das cartas
 * é aplicada depois, com uma consulta leve à coleção de quem está logado.
 */
function deckGuidePlanCards(array $commander, array $patterns, string $stamp = ''): array
{
    $patterns = array_values(array_filter(array_map('strval', $patterns)));
    if (!$patterns) return [];
    $logical = (string)($commander['oracle_id'] ?: $commander['id']);
    $load = static function () use ($commander, $patterns, $logical): array {
        $params = [];
        $where = [];
        foreach ($patterns as $pattern) {
            $where[] = '(c.oracle_text ILIKE ? OR c.type_line ILIKE ?)';
            $params[] = '%' . $pattern . '%';
            $params[] = '%' . $pattern . '%';
        }
        $identity = json_encode(json_decode((string)$commander['color_identity'], true) ?: []);
        return deckQuery("SELECT * FROM (
                SELECT DISTINCT ON (COALESCE(c.oracle_id,c.id)) c.id, c.oracle_id, c.name, c.type_line, c.image_uri, c.local_image, c.raw->'image_uris' AS image_uris,
                    c.raw->'card_faces' AS card_faces, COALESCE(ds.score,0) AS synergy_score
                FROM cards c
                LEFT JOIN deck_synergy ds ON ds.card_id=c.id AND ds.commander_id IN (SELECT id FROM cards WHERE COALESCE(oracle_id,id)=?::uuid)
                WHERE c.color_identity <@ ?::jsonb AND COALESCE(c.oracle_id,c.id)<>?::uuid AND c.lang='en' AND (" . implode(' OR ', $where) . ")
                ORDER BY COALESCE(c.oracle_id,c.id), (ds.score IS NULL), (c.local_image IS NULL), c.released_at DESC NULLS LAST
            ) picked ORDER BY synergy_score DESC, name LIMIT 8", array_merge([$logical, $identity, $logical], $params))->fetchAll();
    };
    $key = 'guide-plan-v2-' . $logical . '-' . md5(implode('|', $patterns) . '|' . $stamp);
    $cards = function_exists('catalogCached') ? catalogCached($key, $load, 604800) : $load();
    $owned = deckOwnedLogicalMap();
    foreach ($cards as &$card) {
        // cardImageUrl espera "raw" com image_uris/card_faces.
        $card['raw'] = json_encode(['image_uris' => is_string($card['image_uris'] ?? null) ? json_decode($card['image_uris'], true) : ($card['image_uris'] ?? null),
            'card_faces' => is_string($card['card_faces'] ?? null) ? json_decode($card['card_faces'], true) : ($card['card_faces'] ?? null)]);
        $card['owned'] = $owned[(string)($card['oracle_id'] ?: $card['id'])] ?? 0;
    }
    return $cards;
}

/** Quantidade de cópias por carta lógica na coleção de quem está logado (uma consulta por requisição). */
function deckOwnedLogicalMap(): array
{
    static $maps = [];
    $userId = deckOwnerId();
    if ($userId < 1) return [];
    return $maps[$userId] ??= deckQuery('SELECT COALESCE(c.oracle_id,c.id)::text AS logical_id, SUM(o.quantity)::int AS quantity
        FROM builder_collection o JOIN cards c ON c.id=o.scryfall_id WHERE o.user_id=? GROUP BY 1', [$userId])->fetchAll(PDO::FETCH_KEY_PAIR);
}

function deckGuideExploreUrl(int $deckId, array $plan): string
{
    $params = ['deck' => $deckId, 'q' => '', 'colors' => 1];
    if ($plan['mode'] === 'type') $params['type'] = implode(';', $plan['patterns']);
    else { $params['oracle'] = implode(';', $plan['patterns']); $params['match'] = 'any'; }
    return '?' . http_build_query($params) . '#explore';
}

/** Combos populares do EDHREC compatíveis com a identidade, seguidos dos combos do catálogo local. */
function deckGuideCombos(array $commander, ?array $insights): array
{
    $identity = json_decode((string)$commander['color_identity'], true) ?: [];
    $out = [];
    $seen = [];
    foreach ((array)($insights['combos'] ?? []) as $combo) {
        $pieces = [];
        $legal = true;
        foreach ((array)$combo['cards'] as $name) {
            $card = deckFindPrinting((string)$name);
            if ($card && array_diff(json_decode((string)$card['color_identity'], true) ?: [], $identity)) { $legal = false; break; }
            $pieces[] = ['name' => (string)$name, 'card' => $card ?: null];
        }
        if (!$legal || !$pieces) continue;
        $key = implode('|', array_map(fn($piece) => strtolower($piece['name']), $pieces));
        $seen[$key] = true;
        $out[] = $combo + ['pieces' => $pieces, 'source' => 'edhrec'];
    }
    foreach (deckCombosForCommander($commander) as $combo) {
        $pieces = array_map(fn($card) => ['name' => (string)$card['name'], 'card' => $card], (array)$combo['cards_data']);
        $key = implode('|', array_map(fn($piece) => strtolower($piece['name']), $pieces));
        if (isset($seen[$key])) continue;
        $out[] = ['cards' => $combo['cards'], 'pieces' => $pieces, 'results' => [$combo['result']], 'prerequisites' => [], 'steps' => [],
            'count' => null, 'percentage' => null, 'bracket' => null, 'href' => '', 'source' => 'local', 'title' => $combo['name']];
    }
    return $out;
}

function deckKeywordGlossary(): array
{
    static $glossary = null;
    return $glossary ??= [
        'flying' => 'Só pode ser bloqueada por criaturas com voar ou alcance.',
        'reach' => 'Pode bloquear criaturas com voar.',
        'trample' => 'Dano de combate excedente ao necessário para destruir os bloqueadores passa para o jogador ou planeswalker.',
        'haste' => 'Pode atacar e usar habilidades com {T} no turno em que entra.',
        'vigilance' => 'Atacar não faz a criatura virar.',
        'deathtouch' => 'Qualquer quantidade de dano que ela causa a uma criatura é suficiente para destruí-la.',
        'lifelink' => 'O dano causado por ela também faz você ganhar essa quantidade de vida.',
        'first strike' => 'Causa dano de combate antes das criaturas sem iniciativa.',
        'double strike' => 'Causa dano de combate duas vezes: com iniciativa e no dano normal.',
        'menace' => 'Só pode ser bloqueada por duas ou mais criaturas.',
        'hexproof' => 'Não pode ser alvo de mágicas ou habilidades dos oponentes.',
        'indestructible' => 'Não é destruída por dano nem por efeitos que dizem “destruir”.',
        'ward' => 'Quando se torna alvo de um oponente, a mágica ou habilidade é anulada a menos que ele pague o custo indicado.',
        'flash' => 'Pode ser conjurada a qualquer momento em que você poderia conjurar uma mágica instantânea.',
        'defender' => 'Não pode atacar.',
        'protection' => 'Não pode ser bloqueada, alvo, encantada/equipada nem sofrer dano do que tiver a qualidade indicada.',
        'crew' => 'Vire criaturas não viradas com poder total igual ou maior que N: o Veículo vira criatura até o fim do turno.',
        'equip' => 'Pague o custo para anexar o Equipamento a uma criatura sua. Só como feitiço.',
        'islandwalk' => 'Não pode ser bloqueada enquanto o jogador defensor controlar uma Ilha.',
        'landfall' => 'Palavra de habilidade: dispara quando um terreno entra no campo sob seu controle.',
        'proliferate' => 'Escolha qualquer número de permanentes e/ou jogadores e dê a cada um mais um marcador de cada tipo que já possuem.',
        'scry' => 'Olhe as N cartas do topo do grimório e coloque qualquer quantidade no fundo e o resto no topo, em qualquer ordem.',
        'surveil' => 'Olhe as N cartas do topo; coloque qualquer quantidade no cemitério e o resto no topo, em qualquer ordem.',
        'mill' => 'Coloque as N cartas do topo do grimório no cemitério.',
        'explore' => 'Revele a carta do topo: se for terreno, vai para a mão; senão a criatura ganha um marcador +1/+1 e você pode pôr a carta no cemitério.',
        'connive' => 'Compre uma carta e descarte uma carta. Se descartar uma carta que não seja terreno, coloque um marcador +1/+1 na criatura.',
        'convoke' => 'Suas criaturas podem ajudar a pagar a mágica: cada criatura virada paga {1} ou um mana da cor dela.',
        'cascade' => 'Ao conjurar, exile cartas do topo até achar um que não seja terreno com valor de mana menor; você pode conjurá-lo sem pagar.',
        'storm' => 'Ao conjurar, copie a mágica uma vez para cada mágica conjurada antes dela neste turno.',
        'flashback' => 'Pode ser conjurada do cemitério pagando o custo de flashback; depois é exilada.',
        'kicker' => 'Custo adicional opcional que dá um efeito extra.',
        'cycling' => 'Pague o custo e descarte a carta para comprar uma carta.',
        'investigate' => 'Crie uma ficha de Pista: artefato com “{2}, sacrifique: compre uma carta”.',
        'food' => 'Ficha de artefato com “{2}, {T}, sacrifique: ganhe 3 de vida”.',
        'treasure' => 'Ficha de artefato com “{T}, sacrifique: adicione um mana de qualquer cor”.',
        'blitz' => 'Conjure pelo custo de blitz: ganha ímpeto e “quando morrer, compre uma carta”, e é sacrificada no fim do turno.',
        'discover' => 'Exile do topo até achar uma carta que não seja terreno com valor de mana N ou menor; conjure-a grátis ou coloque-a na mão.',
        'offspring' => 'Pague o custo adicional para criar uma ficha 1/1 cópia da criatura quando ela entrar.',
        'partner' => 'Você pode ter duas comandantes se ambas tiverem parceria.',
        'myriad' => 'Ao atacar, crie cópias atacando cada outro oponente; elas são exiladas no fim do combate.',
        'annihilator' => 'Ao atacar, o jogador defensor sacrifica N permanentes.',
        'amass' => 'Coloque N marcadores +1/+1 em um Exército seu; se não tiver, crie uma ficha 0/0 de Exército primeiro.',
        'goad' => 'Até seu próximo turno, a criatura ataca a cada combate e ataca outro jogador que não seja você, se puder.',
        'monarch' => 'O monarca compra uma carta extra no fim do turno; causar dano de combate a ele toma o título.',
        'initiative' => 'Quem tem a iniciativa avança pela Masmorra Subterrânea; causar dano de combate a ele toma a iniciativa.',
        'toxic' => 'Jogadores que sofrem dano de combate dela recebem N marcadores de veneno.',
        'infect' => 'Causa dano a criaturas como marcadores -1/-1 e a jogadores como marcadores de veneno.',
        'backup' => 'Ao entrar, coloque N marcadores +1/+1 em uma criatura; se for outra, ela ganha as habilidades listadas até o fim do turno.',
        'bargain' => 'Custo adicional opcional: sacrifique um artefato, encantamento ou ficha para um efeito melhor.',
        'craft' => 'Exile este permanente e os materiais indicados para transformá-lo. Só como feitiço.',
        'disguise' => 'Conjure com a face para baixo por {3} como criatura 2/2 com resguardo {2}; vire-a pelo custo de disfarce.',
        'plot' => 'Exile da mão pagando o custo; num turno futuro, conjure sem pagar como feitiço.',
        'saddle' => 'Vire criaturas com poder total N ou maior para selar a Montaria até o fim do turno. Só como feitiço.',
        'gift' => 'Você pode prometer um presente a um oponente; se fizer, a mágica ganha o efeito extra.',
        'impending' => 'Conjure pelo custo iminente: entra com marcadores de tempo e só é criatura quando eles acabam.',
        'exhaust' => 'Habilidade que só pode ser ativada uma vez.',
        'station' => 'Vire outra criatura para colocar marcadores de carga iguais ao poder dela; com marcadores suficientes, a Nave ganha habilidades e vira criatura.',
        'max speed' => 'Habilidades que funcionam enquanto sua velocidade está em 4, a máxima.',
        'start your engines!' => 'Você começa a ter velocidade; ela aumenta uma vez por turno quando um oponente perde vida no seu turno.',
        'job select' => 'Ao entrar, crie uma ficha 1/1 de Herói e anexe este Equipamento a ela.',
        'firebending' => 'Ao atacar, adicione {R} igual ao número indicado; esse mana dura até o fim do combate.',
        'airbend' => 'Exile o alvo; o dono pode conjurá-lo depois pagando {2} em vez do custo.',
        'earthbend' => 'Transforme um terreno seu em criatura 0/0 com ímpeto e N marcadores +1/+1; quando morrer ou for exilado, volta virado.',
        'waterbend' => 'Custo que pode ser pago virando artefatos e criaturas seus, cada um valendo {1}.',
        'mobilize' => 'Ao atacar, crie N fichas 1/1 de Guerreiro vermelhas viradas e atacando; sacrifique-as no fim do turno.',
        'endure' => 'Coloque N marcadores +1/+1 nesta criatura ou crie uma ficha 0/0 de Espírito com N marcadores +1/+1.',
        'renew' => 'Exile esta carta do cemitério pagando o custo para aplicar o efeito indicado. Só como feitiço.',
        'harmonize' => 'Conjure do cemitério pagando o custo de harmonizar, reduzido virando uma criatura; depois é exilado.',
        'flurry' => 'Dispara quando você conjura sua segunda mágica em um turno.',
        'eerie' => 'Dispara quando um encantamento entra sob seu controle e quando você destrava completamente uma Sala.',
        'survival' => 'No começo da sua segunda fase principal, se esta criatura estiver virada, aplique o efeito.',
        'manifest dread' => 'Olhe os 2 cartas do topo, manifeste um como criatura 2/2 com a face para baixo e ponha o outro no cemitério.',
        'descend' => 'Conta ou dispara quando cartas de permanente vão para seu cemitério.',
        'collect evidence' => 'Exile cartas do seu cemitério com valor de mana total N ou mais.',
        'forage' => 'Exile três cartas do seu cemitério ou sacrifique uma Comida.',
    ];
}

function deckKeywordReminder(string $keyword): ?string
{
    $load = static function () use ($keyword): array {
        $rows = deckQuery("SELECT oracle_text FROM cards WHERE jsonb_exists(keywords, ?) AND oracle_text ILIKE ? AND lang='en' LIMIT 25", [$keyword, '%' . $keyword . '%(%'])->fetchAll(PDO::FETCH_COLUMN);
        foreach ($rows as $text) {
            if (preg_match('/(?<![\w])' . preg_quote($keyword, '/') . '(?![\w])[^\n(]{0,40}\(([^)]{12,260})\)/i', (string)$text, $m)) return [trim($m[1])];
        }
        return [];
    };
    $value = function_exists('catalogCached') ? catalogCached('keyword-reminder-v1-' . strtolower($keyword), $load, 604800) : $load();
    return $value[0] ?? null;
}

/** Mecânicas da própria comandante e mecânicas lançadas nos últimos 24 meses que cabem na identidade. */
function deckGuideMechanics(array $commander): array
{
    $glossary = deckKeywordGlossary();
    $identity = json_decode((string)$commander['color_identity'], true) ?: [];
    $own = [];
    foreach ((json_decode((string)($commander['keywords'] ?? '[]'), true) ?: []) as $keyword) {
        $keyword = (string)$keyword;
        $own[] = ['name' => $keyword, 'description' => $glossary[strtolower($keyword)] ?? null, 'reminder' => deckKeywordReminder($keyword)];
    }

    $loadRecent = static fn(): array => deckQuery("SELECT kw AS name, MIN(c.released_at)::text AS first_seen,
            (array_agg(c.set_name ORDER BY c.released_at, c.set_name))[1] AS first_set
        FROM cards c CROSS JOIN LATERAL jsonb_array_elements_text(c.keywords) kw
        WHERE c.released_at IS NOT NULL AND c.released_at <= current_date AND c.legalities->>'commander' = 'legal'
        GROUP BY kw
        HAVING MIN(c.released_at) >= current_date - interval '24 months'
        ORDER BY MIN(c.released_at) DESC, kw
        LIMIT 40")->fetchAll();
    $recent = function_exists('catalogCached') ? catalogCached('recent-mechanics-v1', $loadRecent, 604800) : $loadRecent();
    $new = [];
    if ($recent) {
        $names = array_column($recent, 'name');
        $loadCounts = static fn(): array => deckQuery("SELECT kw, COUNT(DISTINCT COALESCE(c.oracle_id,c.id))::int AS cards
            FROM cards c CROSS JOIN LATERAL jsonb_array_elements_text(c.keywords) kw
            WHERE kw IN (SELECT jsonb_array_elements_text(?::jsonb)) AND c.color_identity <@ ?::jsonb AND c.legalities->>'commander' = 'legal'
            GROUP BY kw", [json_encode($names), json_encode($identity)])->fetchAll(PDO::FETCH_KEY_PAIR);
        $identityKey = implode('', $identity) ?: 'C';
        $counts = function_exists('catalogCached') ? catalogCached('recent-mechanics-identity-v1-' . $identityKey, $loadCounts, 604800) : $loadCounts();
        foreach ($recent as $row) {
            $available = (int)($counts[$row['name']] ?? 0);
            if ($available < 2) continue;
            $new[] = [
                'name' => (string)$row['name'],
                'description' => $glossary[strtolower((string)$row['name'])] ?? null,
                'reminder' => deckKeywordReminder((string)$row['name']),
                'first_set' => (string)$row['first_set'],
                'first_seen' => (string)$row['first_seen'],
                'cards' => $available,
            ];
            if (count($new) >= 8) break;
        }
    }
    return ['own' => $own, 'new' => $new];
}

function deckGuideNewCards(array $commander, ?array $insights): array
{
    $identity = json_decode((string)$commander['color_identity'], true) ?: [];
    $out = [];
    foreach ((array)($insights['new_cards'] ?? []) as $entry) {
        $card = deckFindPrinting((string)$entry['name']);
        if ($card && array_diff(json_decode((string)$card['color_identity'], true) ?: [], $identity)) continue;
        $out[] = $entry + ['card' => $card ?: null];
        if (count($out) >= 10) break;
    }
    return $out;
}

function deckGuideSimilar(?array $insights): array
{
    $out = [];
    foreach ((array)($insights['similar'] ?? []) as $name) {
        $out[] = ['name' => (string)$name, 'card' => deckFindPrinting((string)$name) ?: null];
    }
    return $out;
}
