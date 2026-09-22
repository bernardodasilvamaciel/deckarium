<?php
declare(strict_types=1);
/**
 * Quadro de relações: comandante e cartas do deck (candidatas ficam de fora, para o quadro ficar leve), com setas A → B
 * explicando o que uma carta fornece e a outra aproveita. Os dados vêm de deck_relations.php;
 * o desenho, o layout e a interação ficam em assets/board.js.
 */
require __DIR__ . '/functions.php';
require __DIR__ . '/partials.php';
require __DIR__ . '/deck_library.php';
require __DIR__ . '/catalog_cache.php';
$authUser = authRequireLogin();
$userId = (int)$authUser['id'];
$_SESSION['builder_csrf'] ??= bin2hex(random_bytes(24));
$csrf = $_SESSION['builder_csrf'];
deckSchema();
$id = max(0, (int)($_GET['deck'] ?? $_POST['deck'] ?? 0));
$deck = $id ? deckQuery('SELECT * FROM builder_decks WHERE id=? AND user_id=?', [$id, $userId])->fetch() : null;
if (!$deck) { http_response_code(404); pageHeader('Quadro de relações'); echo '<p class="empty-state">Deck não encontrado na sua conta. <a href="/decks.php">Voltar aos decks</a></p>'; pageFooter(); exit; }
$commander = $deck['commander_id'] ? deckQuery('SELECT * FROM cards WHERE id=?', [$deck['commander_id']])->fetch() : null;
$items = deckQuery(deckOwnedSql() . "SELECT c.*, i.stage, i.quantity, i.role, COALESCE(o.owned,0) owned FROM builder_items i JOIN cards c ON c.id=i.card_id
    LEFT JOIN owned o ON o.logical_id=COALESCE(c.oracle_id,c.id) WHERE i.deck_id=? AND i.stage='deck' ORDER BY c.name", [$id])->fetchAll();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) throw new RuntimeException('Sessão expirada. Recarregue a página.');
        $action = (string)($_POST['action'] ?? '');
        if (!in_array($action, ['spellbook', 'suggest'], true)) throw new RuntimeException('Ação inválida.');
        if (!$commander) throw new RuntimeException($action === 'spellbook' ? 'Escolha a comandante antes de buscar combos.' : 'Escolha a comandante antes de pedir sugestões.');
        session_write_close();
        $result = $action === 'spellbook'
            ? boardCombosPayload(deckSpellbookFindCombos($id, $commander, $items), $items, $commander)
            : ['suggestions' => boardSuggestions($id, $userId, $commander, $items)];
        echo json_encode(['ok' => true] + $result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    } catch (Throwable $e) {
        http_response_code(422);
        $fallback = ($_POST['action'] ?? '') === 'suggest' ? 'Não foi possível calcular as sugestões agora.' : 'Não foi possível consultar o Commander Spellbook agora.';
        echo json_encode(['ok' => false, 'message' => $e instanceof RuntimeException ? $e->getMessage() : $fallback], JSON_UNESCAPED_UNICODE);
    }
    exit;
}
session_write_close();

/** Combos do Spellbook prontos para o quadro: incluídos (ids das peças) e "falta 1 carta" com posse. */
function boardCombosPayload(?array $payload, array $items, ?array $commander): array
{
    if (!$payload) return ['spellbook' => null];
    $byName = [];
    foreach ($items as $item) { $byName[strtolower((string)$item['name'])] = (string)$item['id']; $byName[strtolower(explode(' // ', (string)$item['name'])[0])] = (string)$item['id']; }
    if ($commander) $byName[strtolower((string)$commander['name'])] = (string)$commander['id'];
    $owned = deckOwnedLogicalMap();
    $almost = [];
    foreach (array_slice((array)($payload['almost'] ?? []), 0, 30) as $combo) {
        $missing = array_values(array_filter($combo['cards'], fn($name) => !isset($byName[strtolower((string)$name)]) && !isset($byName[strtolower(explode(' // ', (string)$name)[0])])));
        if (count($missing) !== 1) continue;
        $card = deckFindPrinting($missing[0]);
        $combo['missing'] = $missing[0];
        $combo['missing_owned'] = $card ? (int)($owned[(string)($card['oracle_id'] ?: $card['id'])] ?? 0) : 0;
        $combo['missing_id'] = $card['id'] ?? null;
        $almost[] = $combo;
    }
    usort($almost, fn($a, $b) => [$b['missing_owned'] > 0, $b['popularity'] ?? 0] <=> [$a['missing_owned'] > 0, $a['popularity'] ?? 0]);
    return ['spellbook' => ['included' => array_values((array)($payload['included'] ?? [])), 'almost' => $almost, 'synced_at' => (string)($payload['synced_at'] ?? '')]];
}

/** Características que vêm do próprio tipo da carta: fornecê-las sem ninguém aproveitar não é um alerta. */
const BOARD_PASSIVE_FEATURES = ['spells', 'noncreature', 'artifacts', 'enchantments', 'equipment', 'legendary', 'landfall'];

/** Dados de uma carta para o quadro: tipo, texto, imagem e o que ela fornece e aproveita. */
function boardNodePayload(string $nodeId, array $card, ?array $profile, array $features): array
{
    static $typeLabels = ['Land' => 'Terreno', 'Creature' => 'Criatura', 'Artifact' => 'Artefato', 'Enchantment' => 'Encantamento', 'Planeswalker' => 'Planeswalker', 'Instant' => 'Instantânea', 'Sorcery' => 'Feitiço', 'Battle' => 'Batalha'];
    $category = 'Outra';
    foreach ($typeLabels as $typeKey => $label) if (str_contains(explode(' // ', (string)$card['type_line'])[0], $typeKey)) { $category = $label; break; }
    return [
        'id' => $nodeId, 'name' => (string)$card['name'], 'stage' => (string)($card['stage'] ?? 'deck'), 'type' => $category, 'type_line' => (string)$card['type_line'],
        'mana_cost' => (string)($card['mana_cost'] ?? ''), 'cmc' => (float)($card['cmc'] ?? 0), 'text' => deckText($card), 'image' => '/image.php?id=' . rawurlencode($nodeId) . '&size=small',
        'quantity' => (int)($card['quantity'] ?? 1), 'owned' => (int)($card['owned'] ?? 0), 'game_changer' => deckIsGameChanger($card),
        'is_land' => (bool)($profile['is_land'] ?? false), 'is_basic' => (bool)($profile['is_basic'] ?? false),
        'provides' => $profile ? array_values(array_map(fn($f) => $features[$f][0], array_keys($profile['provides']))) : [],
        'consumes' => $profile ? array_values(array_merge(array_map(fn($f) => $features[$f][0], array_keys($profile['consumes'])), array_map('ucfirst', array_keys($profile['tribes_cared'])))) : [],
        'tribes' => $profile ? array_map('ucfirst', array_keys($profile['tribes'])) : [],
        'roles' => array_values(array_map(fn($role) => DECK_SCORE_ROLES[$role] ?? $role, $profile['roles'] ?? [])),
        'url' => '/card.php?id=' . rawurlencode($nodeId),
    ];
}

/**
 * Equilíbrio dos temas: para cada característica, quem fornece e quem aproveita dentro do deck.
 * Mostra motores fortes e pontas soltas (quem aproveita algo que quase ninguém fornece, e o contrário).
 */
function boardBalance(array $graph, array $features): array
{
    $sides = [];
    foreach ($graph['profiles'] as $nodeId => $profile) {
        foreach (array_keys($profile['provides']) as $feature) $sides[$feature]['providers'][] = (string)$nodeId;
        foreach (array_keys($profile['consumes']) as $feature) $sides[$feature]['consumers'][] = (string)$nodeId;
    }
    $rows = [];
    foreach ($sides as $feature => $side) {
        if (!isset($features[$feature])) continue;
        [$label, $group, , $give, $take] = $features[$feature];
        $rows[] = ['key' => $feature, 'label' => $label, 'group' => $group, 'give' => $give, 'take' => $take,
            'passive' => in_array($feature, BOARD_PASSIVE_FEATURES, true), 'providers' => $side['providers'] ?? [], 'consumers' => $side['consumers'] ?? []];
    }
    if ($tribe = $graph['tribe']) {
        $providers = $consumers = [];
        foreach ($graph['profiles'] as $nodeId => $profile) {
            if (isset($profile['tribes'][$tribe]) || isset($profile['tribes_made'][$tribe]) || $profile['changeling']) $providers[] = (string)$nodeId;
            if (isset($profile['tribes_cared'][$tribe]) || $profile['chosen_type']) $consumers[] = (string)$nodeId;
        }
        $name = ucfirst($tribe);
        $rows[] = ['key' => 'tribe:' . $tribe, 'label' => $name, 'group' => 'tribal', 'give' => 'é ' . $name, 'take' => 'fortalece ' . $name,
            'passive' => false, 'providers' => $providers, 'consumers' => $consumers];
    }
    return $rows;
}

/**
 * Cartas da coleção que ainda não estão na seleção e mais se ligariam ao deck (a mesma conta do "Encaixa no deck").
 * Cada sugestão traz as setas que ela teria com as cartas do quadro.
 */
function boardSuggestions(int $deckId, int $userId, array $commander, array $items, int $limit = 14): array
{
    $identity = json_decode((string)$commander['color_identity'], true) ?: [];
    $taken = deckQuery('SELECT DISTINCT COALESCE(c.oracle_id,c.id) FROM builder_items i JOIN cards c ON c.id=i.card_id WHERE i.deck_id=?', [$deckId])->fetchAll(PDO::FETCH_COLUMN);
    $taken[] = (string)($commander['oracle_id'] ?: $commander['id']);
    $rows = deckQuery("SELECT * FROM (SELECT DISTINCT ON (COALESCE(c.oracle_id,c.id)) c.*, b.quantity owned_printing
        FROM builder_collection b JOIN cards c ON c.id=b.scryfall_id
        WHERE b.user_id=? AND c.color_identity <@ ?::jsonb AND c.legalities->>'commander'='legal'
          AND COALESCE(c.raw->>'digital','false')='false' AND c.layout <> 'art_series'
          AND COALESCE(c.type_line,'') NOT ILIKE 'Basic Land%' AND COALESCE(c.oracle_id,c.id) <> ALL(?::uuid[])
        ORDER BY COALESCE(c.oracle_id,c.id), b.quantity DESC, (c.lang='en') DESC, c.released_at DESC NULLS LAST) s LIMIT 6000",
        [$userId, json_encode($identity), '{' . implode(',', $taken) . '}'])->fetchAll();
    if (!$rows) return [];
    $selection = [(string)$commander['id'] => $commander + ['stage' => 'commander']];
    foreach ($items as $item) $selection[(string)$item['id']] = $item;
    $outsiders = [];
    foreach ($rows as $row) $outsiders[(string)$row['id']] = $row;
    $preview = deckRelationPreview($outsiders, $selection, $commander);
    uasort($preview, fn($a, $b) => [$b['score'], $b['partners']] <=> [$a['score'], $a['partners']]);
    $owned = deckOwnedLogicalMap();
    $features = deckRelationFeatures();
    $suggestions = [];
    foreach (array_slice($preview, 0, $limit, true) as $cardId => $fit) {
        $card = $outsiders[$cardId] + ['stage' => 'ghost', 'quantity' => 1];
        $card['owned'] = (int)($owned[(string)($card['oracle_id'] ?: $card['id'])] ?? 0);
        $edges = [];
        foreach ($fit['links'] as $link) {
            if ($link['gives']) $edges[] = ['from' => (string)$cardId, 'to' => $link['id'], 'weight' => deckRelationWeight($link['gives']), 'reasons' => $link['gives']];
            if ($link['takes']) $edges[] = ['from' => $link['id'], 'to' => (string)$cardId, 'weight' => deckRelationWeight($link['takes']), 'reasons' => $link['takes']];
        }
        $suggestions[] = boardNodePayload((string)$cardId, $card, deckRelationProfile($card), $features)
            + ['score' => $fit['score'], 'partners' => $fit['partners'], 'edges' => $edges];
    }
    return $suggestions;
}

$features = deckRelationFeatures();
$nodes = [];
$graphNodes = [];
if ($commander) $graphNodes[(string)$commander['id']] = $commander + ['stage' => 'commander', 'quantity' => 1, 'owned' => 0];
foreach ($items as $item) $graphNodes[(string)$item['id']] = $item;
$graph = $graphNodes ? deckRelationGraph($graphNodes, $commander) : ['edges' => [], 'tribe' => null, 'profiles' => []];
$combos = ['edges' => [], 'combos' => []];
$spellbook = deckSpellbookCached($id);
if ($commander) {
    $known = [];
    foreach ((array)(deckCommanderInsights($commander)['combos'] ?? []) as $combo) $known[] = $combo + ['source' => 'EDHREC'];
    foreach ((array)($spellbook['included'] ?? []) as $combo) $known[] = $combo;
    $combos = deckRelationComboEdges($graphNodes, $known);
}
foreach ($graphNodes as $nodeId => $card) $nodes[] = boardNodePayload((string)$nodeId, $card, $graph['profiles'][$nodeId] ?? null, $features);
$tagCount = 0;
try { $tagCount = (int)deckQuery('SELECT COUNT(*) FROM card_tags')->fetchColumn(); } catch (Throwable) {}
$assetVersion = static fn(string $file): string => (string)@filemtime(__DIR__ . '/assets/' . $file);
$boardData = [
    'deck' => ['id' => (int)$deck['id'], 'name' => (string)$deck['name'], 'commander' => $commander ? (string)$commander['id'] : null],
    'nodes' => $nodes,
    'edges' => array_merge($graph['edges'], $combos['edges']),
    'groups' => array_map(fn($g) => ['label' => $g[0], 'color' => $g[1]], DECK_RELATION_GROUPS),
    'tribe' => $graph['tribe'] ? ucfirst($graph['tribe']) : null,
    'combos' => $combos['combos'],
    'balance' => boardBalance($graph, $features),
    'focus' => (string)($_GET['focus'] ?? ''),
    'tags' => $tagCount,
    'csrf' => $csrf,
    'three' => '/assets/board3d.js?v=' . $assetVersion('board3d.js'),
    'explore' => '/decks.php?deck=' . (int)$deck['id'] . '&view=explore&sort=fit',
] + boardCombosPayload($spellbook, $items, $commander);
pageHeader('Quadro de relações · ' . $deck['name']);
?>
<link rel="stylesheet" href="/assets/board.css?v=<?= h($assetVersion('board.css')) ?>">
<?= deckSectionNav($id, 'board', (bool)$commander, 1 + array_sum(array_map(fn($row) => $row['stage']==='deck' ? (int)$row['quantity'] : 0, $items)) - ($commander ? 0 : 1)) ?>
<section class="board-hero">
    <h1>Quadro de relações <span><?= h($deck['name']) ?></span></h1>
    <p>Cada carta flutua junto do tema em que mais se relaciona. As linhas vão de quem <strong>fornece</strong> para quem <strong>aproveita</strong>; clique numa carta para ver os motivos. <?php if (count($nodes) >= 2): ?><span class="board-hero-count">São <?= count($nodes) ?> cartas diferentes, com a comandante; as candidatas ficam de fora.</span><?php endif; ?></p>
</section>
<?php if (!$commander): ?>
<p class="notice warning">Escolha a comandante do deck para montar o quadro. <a href="/decks.php?deck=<?= $id ?>&amp;choose=1">Escolher comandante</a></p>
<?php elseif (count($nodes) < 2): ?>
<p class="empty-state">O quadro mostra só as cartas aprovadas no deck. Aprove candidatas em <a href="/decks.php?deck=<?= $id ?>&amp;view=selection&amp;stage=candidate">Minha seleção</a> para ver as relações.</p>
<?php else: ?>
<div class="board-app" data-board>
    <div class="board-toolbar" role="toolbar" aria-label="Controles do quadro">
        <div class="board-views" role="group" aria-label="Visualização">
            <button type="button" data-board-view="3d" aria-pressed="true">Constelação 3D</button>
            <button type="button" data-board-view="2d" aria-pressed="false">Plano</button>
        </div>
        <label class="board-search"><span class="sr-only">Encontrar carta</span><input type="search" list="board-card-names" placeholder="Encontrar carta…" data-board-search><datalist id="board-card-names"></datalist></label>
        <details class="board-display">
            <summary>Exibição</summary>
            <div class="board-display-menu">
                <label class="board-toggle" title="Quando muitas cartas compartilham a mesma relação (ex.: todos os Merfolk), elas se ligam a um nó do tema em vez de linhas cruzadas."><input type="checkbox" data-board-hubs checked> Agrupar por tema</label>
                <label class="board-toggle"><input type="checkbox" data-board-lands checked> Agrupar terrenos</label>
                <label class="board-toggle"><input type="checkbox" data-board-isolated> Mostrar cartas sem relação</label>
                <label class="board-toggle" data-board-only="2d" title="Desligado: as setas do plano aparecem ao passar o mouse ou ao selecionar uma carta."><input type="checkbox" data-board-all-edges> Todas as setas no plano</label>
                <label class="board-toggle" data-board-only="3d"><input type="checkbox" data-board-spin checked> Girar a constelação devagar</label>
            </div>
        </details>
        <button type="button" class="board-suggest" data-board-suggest aria-pressed="false" title="Cartas da sua coleção, na identidade da comandante, que mais se ligariam a este deck">Sugestões da coleção</button>
        <div class="board-zoom">
            <button type="button" data-board-zoom="-1" aria-label="Afastar">−</button>
            <button type="button" data-board-zoom="0">Enquadrar</button>
            <button type="button" data-board-zoom="1" aria-label="Aproximar">+</button>
            <button type="button" data-board-layout data-board-only="2d" title="Desfaz as posições arrastadas no plano">Reorganizar</button>
            <button type="button" data-board-fullscreen aria-pressed="false">Tela cheia</button>
        </div>
    </div>
    <div class="board-chips" data-board-chips aria-label="Tipos de relação"></div>
    <div class="board-body">
        <div class="board-stage" data-board-stage role="img" aria-label="Constelação 3D das cartas do deck. Pelo teclado, use a busca e o painel ao lado.">
            <div class="board-labels" data-board-labels aria-hidden="true"></div>
            <p class="board-stage-status" data-board-stage-status>Montando a constelação…</p>
        </div>
        <div class="board-canvas" data-board-canvas tabindex="-1" hidden>
            <svg class="board-svg" data-board-svg role="img" aria-label="Quadro de relações entre as cartas">
                <defs data-board-defs></defs>
                <g data-board-viewport><g data-board-edges></g><g data-board-nodes></g></g>
            </svg>
        </div>
        <div class="board-tooltip" data-board-tooltip hidden></div>
        <p class="board-hint" data-board-hint></p>
        <button type="button" class="board-panel-toggle" data-board-panel-toggle aria-expanded="true" aria-controls="board-panel"><span data-board-panel-label>Ocultar painel</span></button>
        <aside class="board-panel" id="board-panel" data-board-panel aria-live="polite"></aside>
    </div>
</div>
<script type="application/json" id="board-data"><?= json_encode($boardData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE) ?></script>
<script src="/assets/board.js?v=<?= h($assetVersion('board.js')) ?>" defer></script>
<?php endif; ?>
<?php pageFooter(); ?>
