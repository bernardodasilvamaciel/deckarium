<?php
declare(strict_types=1);
/**
 * Mesa de teste: uma partida solitária e manual com as cartas do deck, numa mesa 3D.
 * Aqui só se montam os dados (comandante, as cartas do deck com as quantidades e as fichas que o deck cria);
 * as zonas, as regras e o desenho ficam em assets/playtest.js e assets/playtest-scene.js.
 */
require __DIR__ . '/functions.php';
require __DIR__ . '/partials.php';
require __DIR__ . '/deck_library.php';
require __DIR__ . '/deck_tokens.php';
$authUser = authRequireLogin();
$userId = (int)$authUser['id'];
deckSchema();
session_write_close();
$id = max(0, (int)($_GET['deck'] ?? 0));
$deck = $id ? deckQuery('SELECT * FROM builder_decks WHERE id=? AND user_id=?', [$id, $userId])->fetch() : null;
if (!$deck) { http_response_code(404); pageHeader('Mesa de teste'); echo '<p class="empty-state">Deck não encontrado na sua conta. <a href="/decks.php">Voltar aos decks</a></p>'; pageFooter(); exit; }
$commander = $deck['commander_id'] ? deckQuery('SELECT * FROM cards WHERE id=?', [$deck['commander_id']])->fetch() : null;
$items = deckQuery("SELECT c.*, i.stage, i.quantity FROM builder_items i JOIN cards c ON c.id=i.card_id WHERE i.deck_id=? AND i.stage='deck' ORDER BY c.name", [$id])->fetchAll();

/** O que a mesa precisa saber de uma carta: a face da frente, a de trás (se houver) e o que as regras usam. */
function playtestCard(array $card): array
{
    $raw = is_string($card['raw'] ?? null) ? (json_decode($card['raw'], true) ?: []) : (array)($card['raw'] ?? []);
    $faces = (array)($raw['card_faces'] ?? []);
    $front = $faces[0] ?? $raw;
    $cardId = (string)$card['id'];
    $face = static function (array $face, array $raw, string $fallbackType, string $fallbackText): array {
        $type = (string)($face['type_line'] ?? $fallbackType);
        return [
            'name' => (string)($face['name'] ?? $raw['name'] ?? ''),
            'type_line' => $type,
            'mana_cost' => (string)($face['mana_cost'] ?? ''),
            'text' => (string)($face['oracle_text'] ?? $fallbackText),
            'power' => isset($face['power']) ? (string)$face['power'] : null,
            'toughness' => isset($face['toughness']) ? (string)$face['toughness'] : null,
            'loyalty' => isset($face['loyalty']) ? (string)$face['loyalty'] : null,
        ];
    };
    $data = $face($front + ['power' => $raw['power'] ?? null, 'toughness' => $raw['toughness'] ?? null, 'loyalty' => $raw['loyalty'] ?? null], $raw, (string)$card['type_line'], (string)($card['oracle_text'] ?? ''));
    $data['name'] = (string)($front['name'] ?? $card['name']);
    // Cores do lançamento; terrenos e artefatos incolores usam a mana que produzem, para o efeito de entrada.
    $colors = (array)($front['colors'] ?? $raw['colors'] ?? []);
    if (!$colors) $colors = (array)($raw['produced_mana'] ?? []);
    if (!$colors) $colors = (array)($raw['color_identity'] ?? []);
    $hasBack = count($faces) > 1 && (isset($faces[1]['image_uris']) || !empty($card['image_uri_back']) || !empty($card['local_image_back']));
    return $data + [
        'id' => $cardId,
        'cmc' => (float)($card['cmc'] ?? 0),
        'colors' => array_values(array_intersect(array_map('strval', $colors), ['W', 'U', 'B', 'R', 'G', 'C'])) ?: ['C'],
        'image' => '/image.php?id=' . rawurlencode($cardId) . '&size=normal',
        'thumb' => '/image.php?id=' . rawurlencode($cardId) . '&size=small',
        'back' => $hasBack ? $face($faces[1], $raw, '', '') + ['image' => '/image.php?id=' . rawurlencode($cardId) . '&face=back&size=normal'] : null,
        'haste' => (bool)preg_match('/\bhaste\b/i', (string)($front['oracle_text'] ?? $card['oracle_text'] ?? '')),
        'url' => '/card.php?id=' . rawurlencode($cardId),
    ];
}

$library = [];
foreach ($items as $item) $library[] = playtestCard($item) + ['quantity' => max(1, (int)$item['quantity'])];
$tokens = [];
foreach (deckTokenList($commander, $items) as $token) {
    if ($token['kind'] === 'copy') continue;
    $base = $token['card'] ? playtestCard($token['card']) : ['id' => (string)($token['id'] ?? ''), 'name' => $token['name'], 'type_line' => $token['type_line'], 'mana_cost' => '', 'text' => '', 'power' => null, 'toughness' => null, 'loyalty' => null, 'cmc' => 0, 'colors' => ['C'], 'image' => null, 'thumb' => null, 'back' => null, 'haste' => false, 'url' => null];
    $tokens[] = $base + ['kind' => $token['kind'], 'sources' => array_map(fn($s) => $s['name'], $token['sources']), 'suggested' => $token['suggested']];
}
$deckCount = array_sum(array_column($library, 'quantity'));
$assetVersion = static fn(string $file): string => (string)@filemtime(__DIR__ . '/assets/' . $file);
$playData = [
    'deck' => ['id' => (int)$deck['id'], 'name' => (string)$deck['name']],
    'commander' => $commander ? playtestCard($commander) : null,
    'library' => $library,
    'tokens' => $tokens,
    'scene' => '/assets/playtest-scene.js?v=' . $assetVersion('playtest-scene.js'),
];
pageHeader('Mesa de teste · ' . $deck['name']);
?>
<link rel="stylesheet" href="/assets/playtest.css?v=<?= h($assetVersion('playtest.css')) ?>">
<?= deckSectionNav($id, 'playtest', (bool)$commander, 1 + $deckCount - ($commander ? 0 : 1)) ?>
<section class="pt-hero">
    <h1>Mesa de teste <span><?= h($deck['name']) ?></span></h1>
    <p>Uma partida solitária para sentir o deck: compre, jogue terrenos, lance mágicas, crie fichas e marque contadores. As regras que dá para conferir sozinho — mulligan, imposto do comandante, um terreno por turno, fichas que somem, marcadores que se anulam — a mesa aplica ou avisa.</p>
</section>
<?php if (!$commander): ?>
<p class="notice warning">Escolha a comandante do deck antes de testar. <a href="/decks.php?deck=<?= $id ?>&amp;choose=1">Escolher comandante</a></p>
<?php elseif ($deckCount < 1): ?>
<p class="empty-state">O deck ainda não tem cartas aprovadas. Aprove candidatas em <a href="/decks.php?deck=<?= $id ?>&amp;view=selection&amp;stage=candidate">Minha seleção</a> para testar.</p>
<?php else: ?>
<?php if ($deckCount !== 99): ?><p class="notice pt-count-note">O deck tem <?= 1 + $deckCount ?> cartas com a comandante; a mesa usa as que estão aprovadas, mesmo fora das 100.</p><?php endif; ?>
<div class="pt-app" data-playtest>
    <div class="pt-stage" data-pt-stage aria-label="Mesa 3D. Pelo teclado: D compra, U desvira tudo, N passa o turno, Ctrl+Z desfaz; as cartas da mão são botões.">
        <p class="pt-stage-status" data-pt-status>Arrumando a mesa…</p>
    </div>

    <header class="pt-hud">
        <div class="pt-turn">
            <span class="pt-turn-number" data-pt-turn>Turno 1</span>
            <span class="pt-land-badge" data-pt-lands title="Terrenos jogados neste turno: um por turno, sem efeitos que permitam mais (305.2)">Terreno 0/1</span>
            <ol class="pt-phases" data-pt-phases aria-label="Fases do turno"></ol>
            <button type="button" class="pt-btn is-quiet" data-pt-action="phase" title="Próxima fase (Espaço)">Próxima fase</button>
            <button type="button" class="pt-btn is-strong" data-pt-action="turn" title="Próximo turno: desvira, manutenção e compra (N)">Próximo turno</button>
        </div>
        <div class="pt-meters">
            <div class="pt-meter is-life" data-pt-meter="life"><span>Vida</span><button type="button" data-pt-adjust="life:-1" aria-label="Perder 1 de vida">−</button><output data-pt-value="life">40</output><button type="button" data-pt-adjust="life:1" aria-label="Ganhar 1 de vida">+</button></div>
            <div class="pt-meter" data-pt-meter="poison"><span>Veneno</span><button type="button" data-pt-adjust="poison:-1" aria-label="Tirar 1 veneno">−</button><output data-pt-value="poison">0</output><button type="button" data-pt-adjust="poison:1" aria-label="Receber 1 veneno">+</button></div>
            <div class="pt-meter is-opponent" data-pt-meter="opponent" title="Um oponente imaginário, para medir em que turno o deck mataria"><span>Oponente</span><button type="button" data-pt-adjust="opponent:-1" aria-label="Oponente perde 1">−</button><output data-pt-value="opponent">40</output><button type="button" data-pt-adjust="opponent:1" aria-label="Oponente ganha 1">+</button></div>
        </div>
    </header>

    <nav class="pt-tools" aria-label="Ações da partida">
        <button type="button" class="pt-tool" data-pt-action="draw" title="Comprar uma carta (D)">Comprar <kbd>D</kbd></button>
        <button type="button" class="pt-tool" data-pt-action="untap" title="Desvirar todas as permanentes (U)">Desvirar tudo <kbd>U</kbd></button>
        <button type="button" class="pt-tool" data-pt-action="shuffle" title="Embaralhar o grimório (S)">Embaralhar <kbd>S</kbd></button>
        <button type="button" class="pt-tool" data-pt-action="scry" title="Olhar as cartas do topo: vidência, vigiar (X)">Olhar o topo <kbd>X</kbd></button>
        <button type="button" class="pt-tool" data-pt-action="search" title="Buscar uma carta no grimório (F)">Buscar <kbd>F</kbd></button>
        <button type="button" class="pt-tool" data-pt-action="mill" title="Moer a carta do topo (M)">Moer <kbd>M</kbd></button>
        <button type="button" class="pt-tool" data-pt-action="token" title="Criar fichas (K)">Fichas <kbd>K</kbd></button>
        <button type="button" class="pt-tool" data-pt-action="dice" title="Rolar dados ou jogar uma moeda">Dados</button>
        <button type="button" class="pt-tool" data-pt-action="undo" title="Desfazer a última ação (Ctrl+Z)">Desfazer <kbd>Ctrl Z</kbd></button>
        <button type="button" class="pt-tool is-danger" data-pt-action="restart" title="Embaralhar tudo e começar de novo">Nova partida</button>
        <button type="button" class="pt-tool" data-pt-action="fullscreen" aria-pressed="false">Tela cheia</button>
    </nav>

    <aside class="pt-preview" data-pt-preview hidden></aside>
    <section class="pt-log" data-pt-log-wrap>
        <button type="button" class="pt-log-toggle" data-pt-action="log" aria-expanded="false">Registro da partida</button>
        <ol class="pt-log-list" data-pt-log hidden></ol>
    </section>
    <div class="pt-hand" data-pt-hand aria-label="Mão"></div>
    <div class="pt-toasts" data-pt-toasts role="status" aria-live="polite"></div>
    <div class="pt-menu" data-pt-menu role="menu" hidden></div>
    <div class="pt-modal" data-pt-modal hidden><div class="pt-modal-card" role="dialog" aria-modal="true" data-pt-modal-card></div></div>
</div>
<script type="application/json" id="playtest-data"><?= json_encode($playData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE) ?></script>
<script type="module" src="/assets/playtest.js?v=<?= h($assetVersion('playtest.js')) ?>"></script>
<?php endif; ?>
<?php pageFooter(); ?>
