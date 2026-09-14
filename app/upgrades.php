<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require __DIR__ . '/functions.php';
require __DIR__ . '/partials.php';

$deck = trim((string)($_GET['deck'] ?? ''));
$decks = db()->query('SELECT deck_slug, min(deck_name) AS deck_name FROM upgrade_items GROUP BY deck_slug ORDER BY min(deck_name)')->fetchAll();

$params = [];
$where = '';
if ($deck !== '') {
    $where = 'WHERE u.deck_slug = :deck';
    $params[':deck'] = $deck;
}

// Busca separadamente a carta que entra e a carta que sai.
// Também aceita o nome da primeira face de cartas dupla-face/adventure.
$sql = <<<SQL
SELECT
    u.*,

    addc.id AS add_card_id,
    addc.name AS add_card_name,
    addc.type_line AS add_type_line,
    addc.mana_cost AS add_mana_cost,
    addc.oracle_text AS add_oracle_text,
    addc.set_code AS add_set_code,
    addc.collector_number AS add_collector_number,
    addc.local_image AS add_local_image,
    addc.local_image_back AS add_local_image_back,
    addc.image_uri AS add_image_uri,
    addc.image_uri_back AS add_image_uri_back,
    addc.raw AS add_raw,

    remc.id AS remove_card_id,
    remc.name AS remove_card_name,
    remc.type_line AS remove_type_line,
    remc.mana_cost AS remove_mana_cost,
    remc.oracle_text AS remove_oracle_text,
    remc.set_code AS remove_set_code,
    remc.collector_number AS remove_collector_number,
    remc.local_image AS remove_local_image,
    remc.local_image_back AS remove_local_image_back,
    remc.image_uri AS remove_image_uri,
    remc.image_uri_back AS remove_image_uri_back,
    remc.raw AS remove_raw

FROM upgrade_items u

LEFT JOIN LATERAL (
    SELECT c2.*
    FROM cards c2
    WHERE lower(c2.name) = lower(u.add_name)
       OR lower(split_part(c2.name, ' // ', 1)) = lower(u.add_name)
    ORDER BY
        (c2.lang = 'en') DESC,
        (COALESCE(c2.raw->>'digital', 'false') = 'false') DESC,
        (c2.local_image IS NOT NULL) DESC,
        (c2.image_uri IS NOT NULL) DESC,
        c2.released_at DESC NULLS LAST
    LIMIT 1
) addc ON true

LEFT JOIN LATERAL (
    SELECT c3.*
    FROM cards c3
    WHERE lower(c3.name) = lower(u.remove_name)
       OR lower(split_part(c3.name, ' // ', 1)) = lower(u.remove_name)
    ORDER BY
        (c3.lang = 'en') DESC,
        (COALESCE(c3.raw->>'digital', 'false') = 'false') DESC,
        (c3.local_image IS NOT NULL) DESC,
        (c3.image_uri IS NOT NULL) DESC,
        c3.released_at DESC NULLS LAST
    LIMIT 1
) remc ON true

{$where}
ORDER BY u.deck_name, u.sort_order
SQL;

$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$pendingImages = 0;
foreach ($rows as $r) {
    if (empty($r['remove_card_id'])) $pendingImages++;
    if (empty($r['add_card_id'])) $pendingImages++;
}

$imgStatus = trim((string)($_GET['img_status'] ?? ''));
$imgMessage = trim((string)($_GET['img_message'] ?? ''));

pageHeader('Upgrades');
?>
<section class="hero">
  <h1>Uma troca. Novas possibilidades.</h1>
  <p>Compare as cartas que entram e saem dos seus decks — e entenda o que cada troca acrescenta.</p>
</section>

<div class="tabs">
  <a class="<?= $deck === '' ? 'active' : '' ?>" href="/upgrades.php">Todos</a>
  <?php foreach ($decks as $d): ?>
    <a class="<?= $deck === $d['deck_slug'] ? 'active' : '' ?>" href="/upgrades.php?deck=<?= urlencode($d['deck_slug']) ?>"><?= h($d['deck_name']) ?></a>
  <?php endforeach; ?>
</div>

<?php if ($imgMessage !== ''): ?>
  <div class="notice <?= h(in_array($imgStatus, ['ok','warning','error'], true) ? $imgStatus : 'ok') ?>">
    <?= h($imgMessage) ?>
  </div>
<?php endif; ?>

<div class="upgrade-toolbar">
  <div>
    <strong><?= $pendingImages ?></strong> carta(s) ainda não localizada(s) neste filtro.
    <span class="muted">Imagens em tamanho normal para comparar cada detalhe.</span>
  </div>
  <?php if ($pendingImages > 0): ?>
    <form method="post" action="/download_upgrade_images.php" onsubmit="this.querySelector('button').disabled=true; this.querySelector('button').textContent='Baixando…';">
      <input type="hidden" name="deck" value="<?= h($deck) ?>">
      <button class="download-all-btn" type="submit">↓ Buscar cartas faltantes</button>
    </form>
  <?php endif; ?>
</div>

<div class="upgrade-grid">
<?php foreach ($rows as $row): ?>
  <article class="upgrade-card upgrade-card-wide">
    <div class="swap-images">
      <div class="swap-image-block remove-card-block">
        <div class="swap-badge minus-badge">SAI</div>
        <?php if (!empty($row['remove_card_id'])): ?>
          <a href="/card.php?id=<?= h($row['remove_card_id']) ?>">
            <img decoding="async" loading="lazy" src="<?= h(cardImageUrl(['id'=>$row['remove_card_id'],'raw'=>$row['remove_raw'],'image_uri'=>$row['remove_image_uri'],'image_uri_back'=>$row['remove_image_uri_back']], 'front', 'normal') ?? '') ?>" alt="<?= h($row['remove_name']) ?>">
          </a>
        <?php else: ?>
          <div class="placeholder compact">
            <strong><?= h($row['remove_name']) ?></strong>
            <span>carta não localizada no banco</span>
            <form class="image-download-form" method="post" action="/download_card_image.php">
              <input type="hidden" name="card_id" value="<?= h($row['remove_card_id'] ?? '') ?>">
              <input type="hidden" name="card_name" value="<?= h($row['remove_name']) ?>">
              <input type="hidden" name="deck" value="<?= h($deck) ?>">
              <button type="submit">Buscar no Scryfall</button>
            </form>
          </div>
        <?php endif; ?>
        <div class="swap-image-name"><?= h($row['remove_name']) ?></div>
      </div>

      <div class="swap-arrow" aria-hidden="true">→</div>

      <div class="swap-image-block add-card-block">
        <div class="swap-badge plus-badge">ENTRA</div>
        <?php if (!empty($row['add_card_id'])): ?>
          <a href="/card.php?id=<?= h($row['add_card_id']) ?>">
            <img decoding="async" loading="lazy" src="<?= h(cardImageUrl(['id'=>$row['add_card_id'],'raw'=>$row['add_raw'],'image_uri'=>$row['add_image_uri'],'image_uri_back'=>$row['add_image_uri_back']], 'front', 'normal') ?? '') ?>" alt="<?= h($row['add_name']) ?>">
          </a>
        <?php else: ?>
          <div class="placeholder compact">
            <strong><?= h($row['add_name']) ?></strong>
            <span>carta não localizada no banco</span>
            <form class="image-download-form" method="post" action="/download_card_image.php">
              <input type="hidden" name="card_id" value="<?= h($row['add_card_id'] ?? '') ?>">
              <input type="hidden" name="card_name" value="<?= h($row['add_name']) ?>">
              <input type="hidden" name="deck" value="<?= h($deck) ?>">
              <button type="submit">Buscar no Scryfall</button>
            </form>
          </div>
        <?php endif; ?>
        <div class="swap-image-name"><?= h($row['add_name']) ?></div>
      </div>
    </div>

    <div class="upgrade-body">
      <div class="deck-label"><?= h($row['deck_name']) ?></div>
      <h2><?= h($row['remove_name']) ?> <span class="title-arrow">→</span> <?= h($row['add_name']) ?></h2>
      <p class="swap"><span class="minus">− sai</span> <?= h($row['remove_name']) ?></p>
      <p class="swap"><span class="plus">+ entra</span> <?= h($row['add_name']) ?></p>
      <p><?= h($row['reason']) ?></p>

      <?php if (!empty($row['add_oracle_text']) || !empty($row['remove_oracle_text'])): ?>
        <details>
          <summary>Comparar textos Oracle</summary>
          <div class="oracle-compare">
            <?php if (!empty($row['remove_oracle_text'])): ?>
              <div>
                <strong class="minus">Sai — <?= h($row['remove_name']) ?></strong>
                <div class="oracle small"><?= nl2br(h($row['remove_oracle_text'])) ?></div>
              </div>
            <?php endif; ?>
            <?php if (!empty($row['add_oracle_text'])): ?>
              <div>
                <strong class="plus">Entra — <?= h($row['add_name']) ?></strong>
                <div class="oracle small"><?= nl2br(h($row['add_oracle_text'])) ?></div>
              </div>
            <?php endif; ?>
          </div>
        </details>
      <?php endif; ?>
    </div>
  </article>
<?php endforeach; ?>
</div>

<?php pageFooter(); ?>


