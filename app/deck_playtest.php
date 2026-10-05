<?php
declare(strict_types=1);
/**
 * Mesa de teste: uma partida manual com as cartas do deck, numa mesa 3D — sozinho (?deck=ID) ou com até 3 amigos
 * numa mesa compartilhada (?mesa=CÓDIGO: playtest_table.php abre a mesa e senta; a partida corre no WebSocket de realtime/).
 * Aqui só se montam os dados (formato, zona de comando, as cartas do deck com as quantidades e as fichas que o deck
 * cria); as zonas, as regras e o desenho ficam em assets/playtest.js, playtest-net.js e playtest-scene.js.
 */
require __DIR__ . '/functions.php';
require __DIR__ . '/partials.php';
require __DIR__ . '/deck_library.php';
require __DIR__ . '/deck_tokens.php';
require __DIR__ . '/playtest_lib.php';
$authUser = authRequireLogin();
$userId = (int)$authUser['id'];
$_SESSION['builder_csrf'] ??= bin2hex(random_bytes(24));
$csrf = (string)$_SESSION['builder_csrf'];
deckSchema();
playtestSchema();
session_write_close();
$assetVersion = static fn(string $file): string => (string)@filemtime(__DIR__ . '/assets/' . $file);

/* ---------- Mesa compartilhada: quem entra pelo link escolhe o deck ---------- */
$tableToken = (string)($_GET['mesa'] ?? '');
$table = null; $seat = null;
if ($tableToken !== '') {
    $table = playtestTable($tableToken);
    if (!$table) { http_response_code(404); pageHeader('Mesa de teste'); echo '<section class="empty-state"><h2>Mesa não encontrada</h2><p>O link pode ter expirado: mesas paradas há dois dias são encerradas. Peça um link novo para quem abriu a mesa.</p><p><a href="/decks.php">Ir para Meus decks</a></p></section>'; pageFooter(); exit; }
    $tableFormat = deckFormatInfo((string)$table['format']);
    $seat = deckQuery('SELECT * FROM playtest_seats WHERE table_id=? AND user_id=?', [$tableToken, $userId])->fetch() ?: null;
    if (!$seat) {
        $seated = playtestSeats($tableToken);
        $eligible = playtestEligibleDecks($userId, $tableFormat);
        $host = null;
        foreach ($seated as $row) if ((int)$row['user_id'] === (int)$table['host_user_id']) $host = $row;
        pageHeader('Mesa de ' . $tableFormat['name']);
        ?>
        <link rel="stylesheet" href="/assets/playtest.css?v=<?= h($assetVersion('playtest.css')) ?>">
        <section class="pt-join">
            <div class="pt-join-card">
                <p class="pt-join-kicker">Convite para a mesa de teste</p>
                <h1>Mesa de <?= h($tableFormat['name']) ?><?= $host ? ' de ' . h($host['player']) : '' ?></h1>
                <ul class="pt-join-seats">
                    <?php for ($i = 0; $i < PLAYTEST_MAX_SEATS; $i++): $row = null; foreach ($seated as $candidate) if ((int)$candidate['seat'] === $i) $row = $candidate; ?>
                    <li class="<?= $row ? 'is-taken' : 'is-free' ?>"><?php if ($row && $row['commander_id']): ?><img src="/image.php?id=<?= h(rawurlencode((string)$row['commander_id'])) ?>&amp;size=small" alt="" width="73" height="102"><?php else: ?><span class="pt-join-empty"><?= $row ? '♦' : '+' ?></span><?php endif; ?><span><strong><?= $row ? h($row['player']) : 'Lugar livre' ?></strong><small><?= $row ? h($row['deck_name']) : 'Pode ser você' ?></small></span></li>
                    <?php endfor; ?>
                </ul>
                <?php if ($table['status'] !== 'lobby'): ?>
                    <p class="notice warning">A partida já começou. Quando quem abriu a mesa voltar ao lobby, você pode sentar.</p>
                <?php elseif (count($seated) >= PLAYTEST_MAX_SEATS): ?>
                    <p class="notice warning">A mesa já tem <?= PLAYTEST_MAX_SEATS ?> jogadores.</p>
                <?php elseif (!$eligible): ?>
                    <p class="notice warning">Para sentar você precisa de um deck de <strong><?= h($tableFormat['name']) ?></strong> na sua conta<?= deckFormatHasLeader($tableFormat) ? ', com comandante e cartas aprovadas' : ', com cartas aprovadas' ?>.</p>
                    <p class="pt-join-actions"><a class="primary-link" href="/decks.php">Criar ou importar um deck</a><a class="secondary-link" href="?mesa=<?= h($tableToken) ?>">Já criei, atualizar</a></p>
                <?php else: ?>
                    <form class="pt-join-form" data-pt-join>
                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="join"><input type="hidden" name="table" value="<?= h($tableToken) ?>">
                        <fieldset><legend>Escolha seu deck de <?= h($tableFormat['name']) ?></legend>
                            <?php foreach ($eligible as $index => $option): ?><label class="pt-join-deck"><input type="radio" name="deck" value="<?= (int)$option['id'] ?>" <?= $index === 0 ? 'checked' : '' ?>><span><strong><?= h($option['name']) ?></strong><small><?= $option['commander'] ? h($option['commander']) . ' · ' : '' ?><?= (int)$option['cards'] ?> cartas</small></span></label><?php endforeach; ?>
                        </fieldset>
                        <p class="pt-join-error" data-pt-join-error role="alert" hidden></p>
                        <button class="primary-link">Sentar à mesa</button>
                    </form>
                <?php endif; ?>
            </div>
        </section>
        <script>
        document.querySelector('[data-pt-join]')?.addEventListener('submit', async (event) => {
          event.preventDefault();
          const form = event.currentTarget; const error = form.querySelector('[data-pt-join-error]');
          form.querySelector('button').disabled = true;
          const response = await fetch('/playtest_table.php', { method: 'POST', body: new FormData(form), credentials: 'same-origin' }).catch(() => null);
          const data = response ? await response.json().catch(() => null) : null;
          if (data?.ok) { location.reload(); return; }
          error.hidden = false; error.textContent = data?.message || 'Não foi possível sentar agora. Tente de novo.';
          form.querySelector('button').disabled = false;
        });
        </script>
        <?php
        pageFooter();
        exit;
    }
    $id = (int)$seat['deck_id'];
    $deck = deckQuery('SELECT * FROM builder_decks WHERE id=? AND user_id=?', [$id, $userId])->fetch();
} else {
    $id = max(0, (int)($_GET['deck'] ?? 0));
    $deck = $id ? deckQuery('SELECT * FROM builder_decks WHERE id=? AND user_id=?', [$id, $userId])->fetch() : null;
}
if (!$deck) { http_response_code(404); pageHeader('Mesa de teste'); echo '<p class="empty-state">Deck não encontrado na sua conta. <a href="/decks.php">Voltar aos decks</a></p>'; pageFooter(); exit; }
$fmt = deckFormatOf($deck);
deckCurrentFormat($fmt);
$hasLeader = deckFormatHasLeader($fmt);
$commander = $hasLeader && $deck['commander_id'] ? deckQuery('SELECT * FROM cards WHERE id=?', [$deck['commander_id']])->fetch() : null;
$signature = $fmt['signature'] && $deck['signature_id'] ? deckQuery('SELECT * FROM cards WHERE id=?', [$deck['signature_id']])->fetch() : null;
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
foreach (deckTokenList($commander, array_merge($items, $signature ? [$signature + ['stage' => 'deck', 'quantity' => 1]] : [])) as $token) {
    if ($token['kind'] === 'copy') continue;
    $base = $token['card'] ? playtestCard($token['card']) : ['id' => (string)($token['id'] ?? ''), 'name' => $token['name'], 'type_line' => $token['type_line'], 'mana_cost' => '', 'text' => '', 'power' => null, 'toughness' => null, 'loyalty' => null, 'cmc' => 0, 'colors' => ['C'], 'image' => null, 'thumb' => null, 'back' => null, 'haste' => false, 'url' => null];
    $tokens[] = $base + ['kind' => $token['kind'], 'sources' => array_map(fn($s) => $s['name'], $token['sources']), 'suggested' => $token['suggested']];
}
$deckCount = array_sum(array_column($library, 'quantity'));
$zoneCount = ($commander ? 1 : 0) + ($signature ? 1 : 0);
// Arte do tapete: a comandante; sem ela, a carta mais cara do deck (a mesma que ilustra o deck na biblioteca).
$matArt = $commander ? playtestCard($commander)['image'] : null;
if (!$matArt && $items) {
    $key = $items[0];
    foreach ($items as $item) if (!str_contains(explode(' // ', (string)$item['type_line'])[0], 'Land') && (float)$item['cmc'] >= (float)($key['cmc'] ?? 0)) $key = $item;
    $matArt = '/image.php?id=' . rawurlencode((string)$key['id']) . '&size=normal';
}
$playData = [
    'deck' => ['id' => (int)$deck['id'], 'name' => (string)$deck['name']],
    'format' => ['key' => $fmt['key'], 'name' => $fmt['name'], 'life' => (int)$fmt['life'], 'life_multiplayer' => (int)($fmt['life_multiplayer'] ?? $fmt['life']),
        'commander_damage' => (int)$fmt['commander_damage'], 'free_mulligan' => (bool)$fmt['free_mulligan'], 'players' => (int)$fmt['players'],
        'leader' => $fmt['leader'], 'leader_label' => (string)($fmt['leader_label'] ?? ''), 'size' => (int)$fmt['size']],
    'commander' => $commander ? playtestCard($commander) : null,
    'signature' => $signature ? playtestCard($signature) : null,
    'playmat' => $matArt,
    'library' => $library,
    'tokens' => $tokens,
    'scene' => '/assets/playtest-scene.js?v=' . $assetVersion('playtest-scene.js'),
    'net' => '/assets/playtest-net.js?v=' . $assetVersion('playtest-net.js'),
    'csrf' => $csrf,
    'table' => $table ? ['token' => $tableToken, 'api' => '/playtest_table.php', 'socket' => '/realtime/mesa', 'ticket' => playtestTicket($userId, $tableToken),
        'seat' => (int)$seat['seat'], 'url' => '/deck_playtest.php?mesa=' . $tableToken, 'me' => (string)(($authUser['display_name'] ?? '') ?: $authUser['username'])] : null,
];
pageHeader(($table ? 'Mesa compartilhada' : 'Mesa de teste') . ' · ' . $deck['name']);
?>
<link rel="stylesheet" href="/assets/playtest.css?v=<?= h($assetVersion('playtest.css')) ?>">
<?php if (!$table): ?><?= deckSectionNav($id, 'playtest', $commander || !$hasLeader, $zoneCount + $deckCount, $fmt) ?><?php endif; ?>
<section class="pt-hero">
    <h1><?= $table ? 'Mesa compartilhada' : 'Mesa de teste' ?> <span><?= h($deck['name']) ?> · <?= h($fmt['name']) ?></span></h1>
    <?php if ($table): ?>
    <p>Cada jogador controla o próprio deck; a mesa mostra o campo, os cemitérios, a vida e o dano de comandante de todos em tempo real. Dano entre jogadores, revelações, dados e mensagens chegam para todos.</p>
    <?php else: ?>
    <p>Uma partida para sentir o deck: compre, jogue terrenos, lance mágicas, crie fichas e marque contadores. As regras que dá para conferir sozinho — mulligan, <?= $hasLeader ? 'imposto do comandante, ' : '' ?>um terreno por turno, fichas que somem, marcadores que se anulam — a mesa aplica ou avisa. Para jogar com amigos, abra uma mesa compartilhada e mande o link.</p>
    <?php endif; ?>
</section>
<?php if ($hasLeader && !$commander): ?>
<p class="notice warning">Escolha <?= $fmt['leader'] === 'oathbreaker' ? 'o oathbreaker' : 'a comandante' ?> do deck antes de testar. <a href="/decks.php?deck=<?= $id ?>&amp;choose=1">Escolher</a></p>
<?php elseif ($deckCount < 1): ?>
<p class="empty-state">O deck ainda não tem cartas aprovadas. Aprove candidatas em <a href="/decks.php?deck=<?= $id ?>&amp;view=selection&amp;stage=candidate">Minha seleção</a> para testar.</p>
<?php else: ?>
<?php if (!$table && ($fmt['exact'] ? $zoneCount + $deckCount !== (int)$fmt['size'] : $deckCount < (int)$fmt['size'])): ?><p class="notice pt-count-note">O deck tem <?= $zoneCount + $deckCount ?> cartas<?= $zoneCount ? ' com a zona de comando' : '' ?>; a mesa usa as que estão aprovadas, mesmo fora das <?= deckFormatSizeLabel($fmt) ?> do <?= h($fmt['name']) ?>.</p><?php endif; ?>
<div class="pt-app<?= $table ? ' is-shared' : '' ?>" data-playtest>
    <div class="pt-stage" data-pt-stage aria-label="Mesa 3D. Pelo teclado: D compra, U desvira tudo, N passa o turno, Ctrl+Z desfaz, Z aproxima a carta sob o mouse, C liga a vista de cima; as cartas da mão são botões.">
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
            <div class="pt-meter is-life" data-pt-meter="life"><span>Vida</span><button type="button" data-pt-adjust="life:-1" aria-label="Perder 1 de vida">−</button><output data-pt-value="life"><?= (int)$fmt['life'] ?></output><button type="button" data-pt-adjust="life:1" aria-label="Ganhar 1 de vida">+</button></div>
            <div class="pt-meter" data-pt-meter="poison"><span>Veneno</span><button type="button" data-pt-adjust="poison:-1" aria-label="Tirar 1 veneno">−</button><output data-pt-value="poison">0</output><button type="button" data-pt-adjust="poison:1" aria-label="Receber 1 veneno">+</button></div>
            <?php if (!$table): ?><div class="pt-meter is-opponent" data-pt-meter="opponent" title="Um oponente imaginário, para medir em que turno o deck mataria"><span>Oponente</span><button type="button" data-pt-adjust="opponent:-1" aria-label="Oponente perde 1">−</button><output data-pt-value="opponent"><?= (int)$fmt['life'] ?></output><button type="button" data-pt-adjust="opponent:1" aria-label="Oponente ganha 1">+</button></div><?php endif; ?>
            <?php if ($table): ?><div class="pt-opponents" data-pt-opponents aria-label="Oponentes"></div><?php endif; ?>
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
        <?php if ($table): ?>
        <button type="button" class="pt-tool is-share" data-pt-action="table" title="Link da mesa, jogadores e partida">Mesa</button>
        <?php else: ?>
        <button type="button" class="pt-tool is-danger" data-pt-action="restart" title="Embaralhar tudo e começar de novo">Nova partida</button>
        <button type="button" class="pt-tool is-share" data-pt-action="share" title="Abrir uma mesa para jogar com até 3 amigos">Jogar com amigos</button>
        <?php endif; ?>
        <button type="button" class="pt-tool" data-pt-action="fullscreen" aria-pressed="false">Tela cheia</button>
    </nav>

    <div class="pt-camera" data-pt-camera role="group" aria-label="Câmera">
        <button type="button" data-pt-cam="in" title="Aproximar (roda do mouse: aproxima onde o ponteiro está)" aria-label="Aproximar">+</button>
        <button type="button" data-pt-cam="out" title="Afastar" aria-label="Afastar">−</button>
        <button type="button" data-pt-cam="top" aria-pressed="false" title="Vista de cima: a câmera trava olhando a mesa de cima; arrastar move a mesa (C)">De cima</button>
        <button type="button" data-pt-cam="reset" title="Voltar ao enquadramento (Esc ou duplo clique na mesa)">Centralizar</button>
    </div>
    <aside class="pt-preview" data-pt-preview hidden></aside>
    <section class="pt-log" data-pt-log-wrap aria-label="<?= $table ? 'Registro da mesa: o que cada jogador faz, para todos' : 'Registro da partida' ?>">
        <div class="pt-log-head"><strong><?= $table ? 'Registro da mesa' : 'Registro da partida' ?></strong><button type="button" class="pt-log-size" data-pt-action="log" aria-expanded="false">Ampliar</button></div>
        <ol class="pt-log-list" data-pt-log></ol>
        <?php if ($table): ?><form class="pt-chat" data-pt-chat><label class="sr-only" for="pt-chat-input">Mensagem para a mesa</label><input id="pt-chat-input" name="text" maxlength="280" autocomplete="off" placeholder="Mensagem para a mesa…"><button class="pt-btn">Enviar</button></form><?php endif; ?>
    </section>
    <div class="pt-hand" data-pt-hand aria-label="Mão"></div>
    <div class="pt-toasts" data-pt-toasts role="status" aria-live="polite"></div>
    <div class="pt-menu" data-pt-menu role="menu" hidden></div>
    <div class="pt-modal" data-pt-modal hidden><div class="pt-modal-card" role="dialog" aria-modal="true" data-pt-modal-card></div></div>
    <?php if ($table): ?><div class="pt-lobby" data-pt-lobby hidden></div><?php endif; ?>
</div>
<script type="application/json" id="playtest-data"><?= json_encode($playData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE) ?></script>
<script type="module" src="/assets/playtest.js?v=<?= h($assetVersion('playtest.js')) ?>"></script>
<?php endif; ?>
<?php pageFooter(); ?>
