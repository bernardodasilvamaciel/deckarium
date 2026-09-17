<?php
declare(strict_types=1);
/**
 * Quadro de relações: cartas do deck e das candidatas num quadro branco, com setas A → B
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
    LEFT JOIN owned o ON o.logical_id=COALESCE(c.oracle_id,c.id) WHERE i.deck_id=? ORDER BY c.name", [$id])->fetchAll();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) throw new RuntimeException('Sessão expirada. Recarregue a página.');
        if (($_POST['action'] ?? '') !== 'spellbook') throw new RuntimeException('Ação inválida.');
        if (!$commander) throw new RuntimeException('Escolha a comandante antes de buscar combos.');
        session_write_close();
        $payload = deckSpellbookFindCombos($id, $commander, $items);
        echo json_encode(['ok' => true] + boardCombosPayload($payload, $items, $commander), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'message' => $e instanceof RuntimeException ? $e->getMessage() : 'Não foi possível consultar o Commander Spellbook agora.'], JSON_UNESCAPED_UNICODE);
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

$typeLabels = ['Land' => 'Terreno', 'Creature' => 'Criatura', 'Artifact' => 'Artefato', 'Enchantment' => 'Encantamento', 'Planeswalker' => 'Planeswalker', 'Instant' => 'Instantânea', 'Sorcery' => 'Feitiço', 'Battle' => 'Batalha'];
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
$features = deckRelationFeatures();
foreach ($graphNodes as $nodeId => $card) {
    $category = 'Outra';
    foreach ($typeLabels as $typeKey => $label) if (str_contains(explode(' // ', (string)$card['type_line'])[0], $typeKey)) { $category = $label; break; }
    $profile = $graph['profiles'][$nodeId] ?? null;
    $nodes[] = [
        'id' => (string)$nodeId, 'name' => (string)$card['name'], 'stage' => (string)$card['stage'], 'type' => $category, 'type_line' => (string)$card['type_line'],
        'mana_cost' => (string)($card['mana_cost'] ?? ''), 'cmc' => (float)($card['cmc'] ?? 0), 'text' => deckText($card), 'image' => '/image.php?id=' . rawurlencode((string)$nodeId) . '&size=small',
        'quantity' => (int)($card['quantity'] ?? 1), 'owned' => (int)($card['owned'] ?? 0), 'game_changer' => deckIsGameChanger($card),
        'is_land' => (bool)($profile['is_land'] ?? false), 'is_basic' => (bool)($profile['is_basic'] ?? false),
        'provides' => $profile ? array_values(array_map(fn($f) => $features[$f][0], array_keys($profile['provides']))) : [],
        'consumes' => $profile ? array_values(array_merge(array_map(fn($f) => $features[$f][0], array_keys($profile['consumes'])), array_map('ucfirst', array_keys($profile['tribes_cared'])))) : [],
        'tribes' => $profile ? array_map('ucfirst', array_keys($profile['tribes'])) : [],
        'roles' => array_values(array_map(fn($role) => DECK_SCORE_ROLES[$role] ?? $role, $profile['roles'] ?? [])),
        'url' => '/card.php?id=' . rawurlencode((string)$nodeId),
    ];
}
$tagCount = 0;
try { $tagCount = (int)deckQuery('SELECT COUNT(*) FROM card_tags')->fetchColumn(); } catch (Throwable) {}
$boardData = [
    'deck' => ['id' => (int)$deck['id'], 'name' => (string)$deck['name'], 'commander' => $commander ? (string)$commander['id'] : null],
    'nodes' => $nodes,
    'edges' => array_merge($graph['edges'], $combos['edges']),
    'groups' => array_map(fn($g) => ['label' => $g[0], 'color' => $g[1]], DECK_RELATION_GROUPS),
    'tribe' => $graph['tribe'] ? ucfirst($graph['tribe']) : null,
    'combos' => $combos['combos'],
    'focus' => (string)($_GET['focus'] ?? ''),
    'tags' => $tagCount,
    'csrf' => $csrf,
] + boardCombosPayload($spellbook, $items, $commander);
$assetVersion = static fn(string $file): string => (string)@filemtime(__DIR__ . '/assets/' . $file);
pageHeader('Quadro de relações · ' . $deck['name']);
?>
<link rel="stylesheet" href="/assets/board.css?v=<?= h($assetVersion('board.css')) ?>">
<?= deckSectionNav($id, 'board', (bool)$commander, 1 + array_sum(array_map(fn($row) => $row['stage']==='deck' ? (int)$row['quantity'] : 0, $items)) - ($commander ? 0 : 1)) ?>
<section class="board-hero">
    <div>
        <h1>Quadro de relações · <?= h($deck['name']) ?></h1>
        <p>Cada seta vai da carta que <strong>fornece</strong> algo para a carta que <strong>aproveita</strong>. Clique numa carta para ver os motivos com o texto das duas.</p>
    </div>

</section>
<?php if (!$commander): ?>
<p class="notice warning">Escolha a comandante do deck para montar o quadro. <a href="/decks.php?deck=<?= $id ?>&amp;choose=1">Escolher comandante</a></p>
<?php elseif (count($nodes) < 2): ?>
<p class="empty-state">Adicione cartas às candidatas ou ao deck para ver as relações. <a href="/decks.php?deck=<?= $id ?>&amp;view=explore">Explorar cartas</a></p>
<?php else: ?>
<div class="board-app" data-board>
    <div class="board-toolbar" role="toolbar" aria-label="Controles do quadro">
        <fieldset class="board-stage-filter"><legend class="sr-only">Mostrar</legend>
            <label><input type="checkbox" data-board-stage="deck" checked> No deck <b data-board-count="deck"></b></label>
            <label><input type="checkbox" data-board-stage="candidate" checked> Candidatas <b data-board-count="candidate"></b></label>
        </fieldset>
        <label class="board-toggle" title="Quando muitas cartas compartilham a mesma relação (ex.: todos os Merfolk), elas se ligam a um quadro do tema em vez de setas cruzadas."><input type="checkbox" data-board-hubs checked> Agrupar por tema</label>
        <label class="board-toggle"><input type="checkbox" data-board-lands checked> Agrupar terrenos</label>
        <label class="board-toggle"><input type="checkbox" data-board-isolated> Mostrar cartas sem relação</label>
        <label class="board-search"><span class="sr-only">Encontrar carta</span><input type="search" list="board-card-names" placeholder="Encontrar carta…" data-board-search><datalist id="board-card-names"></datalist></label>
        <div class="board-zoom">
            <button type="button" data-board-zoom="-1" aria-label="Diminuir zoom">−</button>
            <button type="button" data-board-zoom="0" aria-label="Enquadrar tudo">Enquadrar</button>
            <button type="button" data-board-zoom="1" aria-label="Aumentar zoom">+</button>
            <button type="button" data-board-layout>Reorganizar</button>
        </div>
    </div>
    <div class="board-chips" data-board-chips aria-label="Tipos de relação"></div>
    <div class="board-body">
        <div class="board-canvas" data-board-canvas tabindex="-1">
            <svg class="board-svg" data-board-svg role="img" aria-label="Quadro de relações entre as cartas">
                <defs data-board-defs></defs>
                <g data-board-viewport><g data-board-edges></g><g data-board-nodes></g></g>
            </svg>
            <div class="board-tooltip" data-board-tooltip hidden></div>
            <p class="board-hint" data-board-hint>Arraste o fundo para mover · roda do mouse para zoom · arraste as cartas para organizar</p>
        </div>
        <aside class="board-panel" data-board-panel aria-live="polite"></aside>
    </div>
</div>
<script type="application/json" id="board-data"><?= json_encode($boardData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE) ?></script>
<script src="/assets/board.js?v=<?= h($assetVersion('board.js')) ?>" defer></script>
<?php endif; ?>
<?php pageFooter(); ?>
