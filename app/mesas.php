<?php
declare(strict_types=1);
/**
 * Jogar: atalho para a Mesa de teste. Mostra as mesas compartilhadas em aberto em que você está sentado (para voltar
 * à partida ou ao lobby) e os seus decks, com "Treinar sozinho" e "Jogar com amigos" (abre uma mesa e leva ao link).
 */
require __DIR__ . '/functions.php';
require __DIR__ . '/partials.php';
require __DIR__ . '/deck_library.php';
require __DIR__ . '/playtest_lib.php';
$authUser = authRequireLogin();
$userId = (int)$authUser['id'];
$_SESSION['builder_csrf'] ??= bin2hex(random_bytes(24));
$csrf = (string)$_SESSION['builder_csrf'];
deckSchema();
playtestSchema();
session_write_close();

$tables = playtestOpenTables($userId);
$decks = deckQuery("SELECT d.id, d.name, d.format, d.commander_id, c.name commander,
        COALESCE(c.raw->'image_uris'->>'art_crop', c.raw->'card_faces'->0->'image_uris'->>'art_crop') commander_art,
        (SELECT COALESCE(SUM(quantity),0) FROM builder_items i WHERE i.deck_id=d.id AND i.stage='deck')::int cards
    FROM builder_decks d LEFT JOIN cards c ON c.id=d.commander_id WHERE d.user_id=? ORDER BY d.id DESC", [$userId])->fetchAll();

pageHeader('Jogar', 'Volte para uma mesa em aberto ou comece uma partida com um dos seus decks, sozinho ou com até 3 amigos.');
?>
<section class="hero"><div><h1>Jogar</h1><p>Volte para uma mesa em aberto ou comece uma partida numa mesa 3D: sozinho, para sentir o deck, ou com até 3 amigos pelo link da mesa.</p></div></section>

<section class="play-section" aria-labelledby="play-open-title">
    <h2 id="play-open-title">Mesas em aberto</h2>
    <?php if ($tables): ?>
    <div class="play-tables"><?= playtestTableCards($tables, $userId) ?></div>
    <?php else: ?>
    <p class="muted">Nenhuma mesa em aberto. Abra uma com “Jogar com amigos” num dos decks abaixo e mande o link — quem entrar escolhe um deck do mesmo formato. Mesas paradas há dois dias são encerradas.</p>
    <?php endif; ?>
</section>

<section class="play-section" aria-labelledby="play-decks-title">
    <h2 id="play-decks-title">Começar uma partida</h2>
    <?php if (!$decks): ?>
    <div class="empty-state"><h3>Nenhum deck ainda</h3><p>Monte ou importe um deck em <a href="/decks.php">Meus decks</a> para jogar.</p></div>
    <?php else: ?>
    <p class="play-error notice error" data-play-error role="alert" hidden></p>
    <div class="play-decks">
        <?php foreach ($decks as $d):
            $format = deckFormatInfo((string)$d['format']);
            $missing = (int)$d['cards'] < 1 ? 'Aprove cartas no deck para jogar.' : (deckFormatHasLeader($format) && !$d['commander_id'] ? 'Escolha ' . ($format['leader'] === 'oathbreaker' ? 'o oathbreaker' : 'a comandante') . ' para jogar.' : '');
            $art = $d['commander_art'] ?: ($d['commander_id'] ? '/image.php?id=' . rawurlencode((string)$d['commander_id']) : null); ?>
        <article class="play-deck<?= $missing ? ' is-blocked' : '' ?>"<?= $art ? ' style="--deck-art:url(\'' . h($art) . '\')"' : '' ?>>
            <div class="play-deck-copy"><strong><?= h($d['name']) ?></strong><span><b class="deck-format-chip"><?= h($format['name']) ?></b> <?= (int)$d['cards'] ?> cartas<?= $d['commander'] ? ' · ' . h($d['commander']) : '' ?></span></div>
            <?php if ($missing): ?>
            <p class="play-deck-missing"><?= h($missing) ?> <a href="/decks.php?deck=<?= (int)$d['id'] ?>">Abrir o deck</a></p>
            <?php else: ?>
            <div class="play-deck-actions"><a class="secondary-link" href="/deck_playtest.php?deck=<?= (int)$d['id'] ?>">Treinar sozinho</a><button type="button" class="primary-link" data-play-create="<?= (int)$d['id'] ?>">Jogar com amigos</button></div>
            <?php endif; ?>
        </article>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</section>
<script>
document.querySelector('.play-decks')?.addEventListener('click', async (event) => {
  const button = event.target.closest('[data-play-create]');
  if (!button) return;
  const error = document.querySelector('[data-play-error]');
  button.disabled = true;
  const body = new FormData();
  body.set('csrf', <?= json_encode($csrf) ?>); body.set('action', 'create'); body.set('deck', button.dataset.playCreate);
  const response = await fetch('/playtest_table.php', { method: 'POST', body, credentials: 'same-origin' }).catch(() => null);
  const data = response ? await response.json().catch(() => null) : null;
  if (data?.ok) { location.href = data.url; return; }
  error.hidden = false; error.textContent = data?.message || 'Não foi possível abrir a mesa agora. Tente de novo.';
  button.disabled = false;
});
</script>
<?php pageFooter(); ?>
