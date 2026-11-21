<?php
declare(strict_types=1);
/**
 * Página inicial do Deckarium: o jogo vem primeiro. Uma vitrine 3D da mesa (mesa de madeira no meio das planícies,
 * ilhas, pântanos, montanhas ou florestas), "Traga seu deck" (cola a lista e já senta à mesa), as mesas em aberto,
 * os seus decks prontos para jogar, a sua mesa (paisagem, madeira, shield e verso) e, por fim, o resto do site.
 * Incluída por index.php quando "/" é aberto sem busca nem filtros. O guia antigo de módulos está em /sobre.php.
 */
require_once __DIR__ . '/deck_library.php';
require_once __DIR__ . '/playtest_lib.php';

$playUser = authUser();
$playUserId = (int)($playUser['id'] ?? 0);
$_SESSION['builder_csrf'] ??= bin2hex(random_bytes(24));
$playCsrf = (string)$_SESSION['builder_csrf'];
$playError = (string)($_SESSION['play_error'] ?? '');
$playForm = (array)($_SESSION['play_form'] ?? []);
unset($_SESSION['play_error'], $_SESSION['play_form']);
$assetVersion = static fn(string $file): string => (string)@filemtime(__DIR__ . '/assets/' . $file);

$playTables = [];
$playDecks = [];
$playPrefs = null;
if ($playUserId) {
    deckSchema();
    playtestSchema();
    $playTables = playtestOpenTables($playUserId);
    $playDecks = deckQuery("SELECT d.id, d.name, d.format, d.commander_id, c.name commander,
            COALESCE(c.raw->'image_uris'->>'art_crop', c.raw->'card_faces'->0->'image_uris'->>'art_crop') commander_art,
            (SELECT COALESCE(SUM(quantity),0) FROM builder_items i WHERE i.deck_id=d.id AND i.stage='deck')::int cards
        FROM builder_decks d LEFT JOIN cards c ON c.id=d.commander_id WHERE d.user_id=? ORDER BY d.id DESC LIMIT 24", [$playUserId])->fetchAll();
    $playPrefs = playtestPrefs($playUserId);
}
session_write_close();

// Vitrine: os comandantes mais jogados no EDHREC na mesa de demonstração (o mesmo cache da página Sobre).
$showcaseCards = [];
try {
    $showcaseCards = catalogCached('home-hand-v1', fn(): array => db()->query("SELECT id, name FROM (
            SELECT DISTINCT ON (COALESCE(c.oracle_id,c.id)) c.id, c.name, c.edhrec_rank_cached AS rank
            FROM cards c
            WHERE c.commander_eligible AND c.edhrec_rank_cached IS NOT NULL AND c.lang = 'en'
              AND c.layout NOT IN ('art_series','token','double_faced_token','emblem','reversible_card')
              AND COALESCE(c.raw->>'digital','false') <> 'true' AND (c.image_uri IS NOT NULL OR c.local_image IS NOT NULL)
            ORDER BY COALESCE(c.oracle_id,c.id), (c.local_image IS NOT NULL) DESC, COALESCE(c.raw->>'promo','false') = 'true', c.released_at DESC NULLS LAST, c.id
        ) ranked ORDER BY rank LIMIT 5")->fetchAll(), 86400);
} catch (Throwable) { $showcaseCards = []; }
$showcase = [
    'scene' => '/assets/playtest-scene.js?v=' . $assetVersion('playtest-scene.js'),
    'world' => '/assets/playtest-world.js?v=' . $assetVersion('playtest-world.js'),
    'environment' => $playPrefs && $playPrefs['environment'] !== 'auto' ? $playPrefs['environment'] : 'plains',
    'wood' => $playPrefs['wood'] ?? 'carvalho',
    'style' => ['sleeve' => $playPrefs['sleeve'] ?? 'esmeralda', 'back' => ($playPrefs['back'] ?? 'deckarium') === 'arte' ? 'deckarium' : ($playPrefs['back'] ?? 'deckarium')],
    'cards' => array_map(fn($card) => ['id' => (string)$card['id'], 'name' => (string)$card['name'], 'image' => '/image.php?id=' . rawurlencode((string)$card['id']) . '&size=normal'], $showcaseCards),
    'prefs' => $playPrefs,
    'csrf' => $playCsrf,
    'loggedIn' => (bool)$playUserId,
];
$environments = ['plains' => ['Planícies', 'Campos dourados, vento e pólen no ar'], 'island' => ['Ilhas', 'Areia branca no meio do mar'], 'swamp' => ['Pântanos', 'Névoa, árvores mortas e vaga-lumes'],
    'mountain' => ['Montanhas', 'Rocha vermelha, vulcão e brasas'], 'forest' => ['Florestas', 'Uma clareira entre pinheiros']];
$formatName = static fn(string $key): string => deckFormatInfo($key)['name'];

pageHeader(t('Jogar'), t('Traga o seu deck de Magic e jogue numa mesa 3D de madeira, no meio das planícies, ilhas, pântanos, montanhas ou florestas — sozinho ou com até 3 amigos.'));
?>
<section class="ph-hero" aria-labelledby="ph-title">
  <div class="ph-stage" data-showcase aria-hidden="true"></div>
  <div class="ph-hero-copy">
    <p class="ph-kicker"><?= te('Mesa de Magic em 3D') ?></p>
    <h1 id="ph-title"><?= te('Traga seu deck.') ?> <em><?= te('Sente à mesa.') ?></em></h1>
    <p class="ph-lede"><?= te('Cole a lista do Moxfield ou do Arena e jogue em segundos, sozinho ou com até 3 amigos pelo link da mesa. Turno em fases e etapas, sons, efeitos até o fim do turno, ímpeto e interação com o grimório dos oponentes — numa mesa de madeira no meio da paisagem que você escolher.') ?></p>
    <div class="ph-actions">
      <?php if ($playUserId): ?>
        <a class="ph-btn is-gold" href="#trazer"><?= te('Trazer um deck') ?></a>
        <?php if ($playDecks): ?><a class="ph-btn" href="#jogar"><?= te('Jogar com meus decks') ?></a><?php endif; ?>
      <?php else: ?>
        <a class="ph-btn is-gold" href="/register.php"><?= te('Criar conta e jogar') ?></a>
        <a class="ph-btn" href="/login.php?next=%2F"><?= te('Já tenho conta') ?></a>
      <?php endif; ?>
    </div>
    <form class="ph-join" data-ph-join>
      <label for="ph-join-code"><?= te('Recebeu um link de mesa?') ?></label>
      <div><input id="ph-join-code" name="code" placeholder="<?= te('Cole o link ou o código da mesa') ?>" autocomplete="off" spellcheck="false"><button class="ph-btn is-small"><?= te('Entrar') ?></button></div>
      <p class="ph-join-error" data-ph-join-error role="alert" hidden></p>
    </form>
  </div>
  <div class="ph-envs" role="group" aria-label="<?= te('Paisagem da vitrine') ?>">
    <?php foreach ($environments as $key => [$name, $blurb]): ?>
      <button type="button" class="ph-env is-<?= h($key) ?><?= $showcase['environment'] === $key ? ' is-on' : '' ?>" data-ph-env="<?= h($key) ?>" aria-pressed="<?= $showcase['environment'] === $key ? 'true' : 'false' ?>" title="<?= h(t($blurb)) ?>"><i></i><span><?= te($name) ?></span></button>
    <?php endforeach; ?>
  </div>
</section>

<?php if ($playUserId): ?>
<?php if ($playTables): ?>
<section class="ph-section" aria-labelledby="ph-tables-title">
  <div class="ph-section-head"><h2 id="ph-tables-title"><?= te('Mesas em aberto') ?></h2><p><?= te('Volte para a partida ou para o lobby. Mesas paradas há dois dias são encerradas.') ?></p></div>
  <div class="play-tables"><?= playtestTableCards($playTables, $playUserId) ?></div>
</section>
<?php endif; ?>

<section class="ph-section" id="jogar" aria-labelledby="ph-decks-title">
  <div class="ph-section-head"><h2 id="ph-decks-title"><?= te('Seus decks') ?></h2><p><?= te('Treine sozinho para sentir o deck ou abra uma mesa e mande o link para até 3 amigos.') ?> <a href="/decks.php"><?= te('Oficina de decks') ?></a></p></div>
  <?php if (!$playDecks): ?>
    <p class="ph-empty"><?= te('Você ainda não tem decks. Traga um abaixo: basta colar a lista.') ?></p>
  <?php else: ?>
  <p class="play-error notice error" data-play-error role="alert" hidden></p>
  <div class="play-decks">
    <?php foreach ($playDecks as $d):
        $format = deckFormatInfo((string)$d['format']);
        $missing = (int)$d['cards'] < 1 ? t('Aprove cartas no deck para jogar.') : (deckFormatHasLeader($format) && !$d['commander_id'] ? ($format['leader'] === 'oathbreaker' ? t('Escolha o oathbreaker para jogar.') : t('Escolha a comandante para jogar.')) : '');
        $art = $d['commander_art'] ?: ($d['commander_id'] ? '/image.php?id=' . rawurlencode((string)$d['commander_id']) : null); ?>
    <article class="play-deck<?= $missing ? ' is-blocked' : '' ?>"<?= $art ? ' style="--deck-art:url(\'' . h($art) . '\')"' : '' ?>>
      <div class="play-deck-copy"><strong><?= h($d['name']) ?></strong><span><b class="deck-format-chip"><?= h($format['name']) ?></b> <?= (int)$d['cards'] ?> <?= te('cartas') ?><?= $d['commander'] ? ' · ' . h($d['commander']) : '' ?></span></div>
      <?php if ($missing): ?>
      <p class="play-deck-missing"><?= h($missing) ?> <a href="/decks.php?deck=<?= (int)$d['id'] ?>"><?= te('Abrir o deck') ?></a></p>
      <?php else: ?>
      <div class="play-deck-actions"><a class="secondary-link" href="/deck_playtest.php?deck=<?= (int)$d['id'] ?>"><?= te('Treinar sozinho') ?></a><button type="button" class="primary-link" data-play-create="<?= (int)$d['id'] ?>"><?= te('Jogar com amigos') ?></button></div>
      <?php endif; ?>
    </article>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</section>

<section class="ph-section ph-bring" id="trazer" aria-labelledby="ph-bring-title">
  <div class="ph-section-head"><h2 id="ph-bring-title"><?= te('Traga seu deck') ?></h2><p><?= te('Uma carta por linha (“1 Sol Ring”). Exportações do Moxfield e do MTG Arena funcionam como estão; o cabeçalho “Commander” marca a comandante.') ?></p></div>
  <?php if ($playError !== ''): ?><p class="notice error" role="alert"><?= h($playError) ?></p><?php endif; ?>
  <form class="ph-bring-form" method="post" action="/play_import.php">
    <input type="hidden" name="csrf" value="<?= h($playCsrf) ?>">
    <div class="ph-bring-fields">
      <label><?= te('Nome do deck') ?><input name="name" required maxlength="160" placeholder="<?= te('Ex.: Elfos do Ezuri') ?>" value="<?= h((string)($playForm['name'] ?? '')) ?>"></label>
      <label><?= te('Formato') ?><select name="format" data-format-select><?= deckFormatOptionsHtml((string)($playForm['format'] ?? DECK_DEFAULT_FORMAT)) ?></select></label>
      <label data-leader-field><?= te('Comandante (opcional)') ?><input name="commander" maxlength="200" placeholder="<?= te('Ex.: Ezuri, Renegade Leader') ?>" autocomplete="off" spellcheck="false" value="<?= h((string)($playForm['commander'] ?? '')) ?>"></label>
    </div>
    <label class="ph-bring-list"><?= te('Lista') ?><textarea name="decklist" rows="10" required maxlength="200000" placeholder="Commander&#10;1 Ezuri, Renegade Leader&#10;&#10;Deck&#10;1 Llanowar Elves&#10;1 Sol Ring&#10;35 Forest"><?= h((string)($playForm['decklist'] ?? '')) ?></textarea></label>
    <div class="ph-bring-actions">
      <button class="primary-link" name="mode" value="solo"><?= te('Importar e treinar') ?></button>
      <button class="secondary-link" name="mode" value="friends"><?= te('Importar e abrir mesa com amigos') ?></button>
    </div>
  </form>
</section>

<section class="ph-section ph-mine" id="sua-mesa" aria-labelledby="ph-mine-title">
  <div class="ph-section-head"><h2 id="ph-mine-title"><?= te('Sua mesa') ?></h2><p><?= te('Vale para todas as partidas. Na mesa compartilhada, os outros jogadores veem o seu shield e o seu verso. Também dá para mudar durante o jogo, em “Personalizar”.') ?></p></div>
  <div class="ph-prefs" data-ph-prefs></div>
</section>
<?php else: ?>
<section class="ph-section ph-start" aria-labelledby="ph-start-title">
  <div class="ph-section-head"><h2 id="ph-start-title"><?= te('Do deck à mesa em três passos') ?></h2></div>
  <ol class="ph-steps">
    <li><b>1</b><strong><?= te('Crie sua conta') ?></strong><span><?= te('Grátis. Seus decks e sua mesa ficam guardados nela.') ?></span></li>
    <li><b>2</b><strong><?= te('Cole a lista do deck') ?></strong><span><?= te('Moxfield, Arena ou texto: Commander, Modern, Pauper e outros 10 formatos.') ?></span></li>
    <li><b>3</b><strong><?= te('Jogue') ?></strong><span><?= te('Sozinho, para testar, ou com até 3 amigos pelo link da mesa.') ?></span></li>
  </ol>
</section>
<?php endif; ?>

<section class="ph-section" aria-labelledby="ph-rules-title">
  <div class="ph-section-head"><h2 id="ph-rules-title"><?= te('Uma mesa que conhece as regras') ?></h2><p><?= te('Você joga com as mãos; a mesa acompanha o turno, avisa e aplica o que dá para conferir.') ?></p></div>
  <ul class="ph-features">
    <li><strong><?= te('Turno em fases e etapas') ?></strong><span><?= te('Inicial, principal, combate, principal e final, com cada etapa anunciada e com som: desvirar, manutenção, compra, atacantes, bloqueadores, dano, limpeza.') ?></span></li>
    <li><strong><?= te('Gatilhos lembrados') ?></strong><span><?= te('“No início da sua manutenção”, “quando entra”, “sempre que ataca”: a mesa para na etapa e lembra as cartas que disparam.') ?></span></li>
    <li><strong><?= te('Efeitos sem marcadores') ?></strong><span><?= te('+3/+3 até o fim do turno, +1/+0 enquanto estiver no campo, “vira uma criatura 4/4”, dano marcado e habilidades dadas — e a limpeza tira o que acaba.') ?></span></li>
    <li><strong><?= te('Ímpeto de verdade') ?></strong><span><?= te('O enjoo de invocação some quando a criatura tem ímpeto, por ela mesma ou por outra carta como Fervor; e dá para dar ímpeto por um efeito.') ?></span></li>
    <li><strong><?= te('O grimório dos outros') ?></strong><span><?= te('Moer, exilar, revelar, olhar e buscar no grimório de um oponente — só você vê o que olhou — e ganhar o controle das cartas dele.') ?></span></li>
    <li><strong><?= te('Shield e verso') ?></strong><span><?= te('Oito cores de shield e seis estampas de verso, incluindo a arte da sua comandante. A paisagem e a madeira da mesa também são suas.') ?></span></li>
  </ul>
</section>

<section class="ph-section ph-more" aria-labelledby="ph-more-title">
  <div class="ph-section-head"><h2 id="ph-more-title"><?= te('Também no Deckarium') ?></h2><p><?= te('Para montar e cuidar dos decks fora da mesa.') ?> <a href="/sobre.php"><?= te('Conheça cada parte do site') ?></a></p></div>
  <ul class="ph-modules">
    <li><a href="/decks.php"><?= uiIcon('decks') ?><strong><?= te('Oficina de decks') ?></strong><span><?= te('13 formatos, sugestões, análise e o meta') ?></span></a></li>
    <li><a href="/?catalog=1#catalogo"><?= uiIcon('cards') ?><strong><?= te('Catálogo') ?></strong><span><?= te('Todas as cartas, com busca e filtros') ?></span></a></li>
    <li><a href="/commanders.php"><?= uiIcon('commanders') ?><strong><?= te('Comandantes') ?></strong><span><?= te('Os mais novos e os mais jogados') ?></span></a></li>
    <li><a href="/collection.php"><?= uiIcon('collection') ?><strong><?= te('Minha coleção') ?></strong><span><?= te('O que você tem e onde está') ?></span></a></li>
    <li><a href="/editions.php"><?= uiIcon('sets') ?><strong><?= te('Edições') ?></strong><span><?= te('Linha do tempo de todos os lançamentos') ?></span></a></li>
    <li><a href="/public.php"><?= uiIcon('community') ?><strong><?= te('Comunidade') ?></strong><span><?= te('Decks e coleções públicos') ?></span></a></li>
  </ul>
</section>

<script type="importmap"><?= json_encode(['imports' => [
    '/assets/playtest-world.js' => '/assets/playtest-world.js?v=' . $assetVersion('playtest-world.js'),
    '/assets/vendor/three.module.min.js' => '/assets/vendor/three.module.min.js?v=' . $assetVersion('vendor/three.module.min.js'),
]], JSON_UNESCAPED_SLASHES) ?></script>
<script type="application/json" id="play-home-data"><?= json_encode($showcase, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script type="module" src="/assets/play-home.js?v=<?= h($assetVersion('play-home.js')) ?>"></script>
<?php pageFooter(); ?>
