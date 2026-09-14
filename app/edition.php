<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require __DIR__ . '/functions.php';
require __DIR__ . '/partials.php';

$set = strtolower(trim((string)($_GET['set'] ?? '')));
$view = (string)($_GET['view'] ?? 'unique');
$view = $view === 'printings' ? 'printings' : 'unique';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 42;
$offset = ($page - 1) * $perPage;

if ($set === '') {
    header('Location: /editions.php');
    exit;
}

$infoStmt = db()->prepare(<<<SQL
SELECT set_code,
       MAX(set_name) AS set_name,
       MIN(released_at) AS first_release,
       MAX(released_at) AS last_release,
       COUNT(*) AS printings,
       COUNT(DISTINCT COALESCE(oracle_id,id)) AS unique_cards,
       NULL::text AS set_type
FROM cards
WHERE set_code = :set
GROUP BY set_code
SQL);
$infoStmt->execute([':set' => $set]);
$edition = $infoStmt->fetch();

if (!$edition) {
    http_response_code(404);
    pageHeader('Edição não encontrada');
    echo '<section class="hero"><h1>Edição não encontrada</h1><p class="muted">Código: ' . h(strtoupper($set)) . '</p></section>';
    pageFooter();
    exit;
}

if ($view === 'unique') {
    $sql = <<<SQL
WITH ranked AS (
    SELECT c.*,
           row_number() OVER (
             PARTITION BY COALESCE(c.oracle_id,c.id)
             ORDER BY
               (c.lang = 'en') DESC,
               (c.image_uri IS NOT NULL) DESC,
               c.collector_number,
               c.id
           ) AS rn
    FROM cards c
    WHERE c.set_code = :set
)
SELECT id,name,mana_cost,type_line,oracle_text,set_code,set_name,collector_number,rarity,released_at,
       local_image,local_image_back,image_uri,image_uri_back,raw,oracle_id
FROM ranked
WHERE rn = 1
ORDER BY
  CASE WHEN collector_number ~ '^[0-9]+$' THEN collector_number::int ELSE 999999 END,
  collector_number,
  name
LIMIT {$perPage} OFFSET {$offset}
SQL;
    $stmt = db()->prepare($sql);
    $stmt->execute([':set' => $set]);
    $cards = $stmt->fetchAll();
    $total = (int)$edition['unique_cards'];
} else {
    $sql = <<<SQL
SELECT id,name,mana_cost,type_line,oracle_text,set_code,set_name,collector_number,rarity,released_at,
       local_image,local_image_back,image_uri,image_uri_back,raw,oracle_id
FROM cards
WHERE set_code = :set
ORDER BY
  CASE WHEN collector_number ~ '^[0-9]+$' THEN collector_number::int ELSE 999999 END,
  collector_number,
  name
LIMIT {$perPage} OFFSET {$offset}
SQL;
    $stmt = db()->prepare($sql);
    $stmt->execute([':set' => $set]);
    $cards = $stmt->fetchAll();
    $total = (int)$edition['printings'];
}

pageHeader($edition['set_name'] . ' - Deckarium');
?>
<section class="hero edition-hero">
  <div>
    <a class="back-link" href="/editions.php">← Todas as edições</a>
    <div class="edition-title-row">
      <span class="set-code-big"><?= h(strtoupper((string)$edition['set_code'])) ?></span>
      <div>
        <h1><?= h($edition['set_name']) ?></h1>
        <p><?= h((string)$edition['first_release']) ?><?= $edition['last_release'] !== $edition['first_release'] ? ' — ' . h((string)$edition['last_release']) : '' ?> · <?= number_format((int)$edition['unique_cards'], 0, ',', '.') ?> cartas únicas · <?= number_format((int)$edition['printings'], 0, ',', '.') ?> impressões</p>
      </div>
    </div>
  </div>
</section>

<div class="view-toggle">
  <a class="<?= $view === 'unique' ? 'active' : '' ?>" href="?set=<?= urlencode($set) ?>&view=unique">Cartas únicas</a>
  <a class="<?= $view === 'printings' ? 'active' : '' ?>" href="?set=<?= urlencode($set) ?>&view=printings">Todas as versões</a>
</div>

<div class="grid">
<?php foreach ($cards as $card): ?>
  <?php cardTile($card); ?>
<?php endforeach; ?>
</div>

<?php if ($total > $perPage): ?>
<nav class="pager">
  <?php if ($page > 1): ?><a href="?set=<?= urlencode($set) ?>&view=<?= urlencode($view) ?>&page=<?= $page-1 ?>">← Anterior</a><?php endif; ?>
  <span>Página <?= $page ?></span>
  <?php if ($offset + $perPage < $total): ?><a href="?set=<?= urlencode($set) ?>&view=<?= urlencode($view) ?>&page=<?= $page+1 ?>">Próxima →</a><?php endif; ?>
</nav>
<?php endif; ?>
<?php pageFooter(); ?>



