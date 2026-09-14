<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require __DIR__ . '/functions.php';
require __DIR__ . '/partials.php';

$id = (string)($_GET['id'] ?? '');
if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i', $id)) {
    http_response_code(404);
    pageHeader('Carta não encontrada');
    echo '<div class="empty-state"><h1>Carta não encontrada</h1><p>O endereço não corresponde a uma carta do acervo.</p><a class="primary-link" href="/">Voltar ao catálogo</a></div>';
    pageFooter();
    exit;
}
$stmt = db()->prepare('SELECT * FROM cards WHERE id = :id');
$stmt->execute([':id' => $id]);
$card = $stmt->fetch();
if (!$card) {
    http_response_code(404);
    pageHeader('Carta não encontrada');
    echo '<div class="empty-state"><h1>Carta não encontrada</h1><p>Esta impressão ainda não está no acervo local.</p><a href="/">Voltar ao catálogo</a></div>';
    pageFooter();
    exit;
}

$printings = [];
if (!empty($card['oracle_id'])) {
    $p = db()->prepare(<<<SQL
SELECT id,name,set_code,set_name,collector_number,released_at,lang,rarity
FROM cards
WHERE oracle_id = :oracle_id
ORDER BY (lang = 'en') DESC, released_at DESC NULLS LAST, set_code, collector_number
SQL);
    $p->execute([':oracle_id' => $card['oracle_id']]);
    $printings = $p->fetchAll();
}

pageHeader($card['name']);
$front = cardImageUrl($card, 'front', 'normal');
$back = cardImageUrl($card, 'back', 'normal');
?>
<a class="back-link" href="/edition.php?set=<?= h($card['set_code']) ?>">← <?= h($card['set_name']) ?></a>
<div class="detail">
  <div class="detail-images">
    <?php if ($front): ?><img src="<?= h($front) ?>" alt="<?= h($card['name']) ?>"><?php endif; ?>
    <?php if ($back): ?><img src="<?= h($back) ?>" alt="Verso/segunda face de <?= h($card['name']) ?>"><?php endif; ?>
    <?php if (!$front && !$back): ?><div class="placeholder large"><strong><?= h($card['name']) ?></strong><span>imagem indisponível</span></div><?php endif; ?>
  </div>
  <section class="panel">
    <h1><?= h($card['name']) ?></h1>
    <p class="mana-line"><strong>Custo:</strong> <span class="mana-cost"><?= manaSymbols($card['mana_cost']) ?></span></p>
    <p><strong>Tipo:</strong> <?= h($card['type_line']) ?></p>
    <?php if ($card['oracle_text']): ?>
    <div class="oracle"><?= nl2br(h($card['oracle_text'])) ?></div>
    <?php else: foreach (json_decode($card['card_faces'] ?? '[]', true) ?: [] as $cardFace): ?>
    <div class="oracle"><strong><?= h($cardFace['name'] ?? '') ?></strong><p class="mana-line"><span class="mana-cost"><?= manaSymbols($cardFace['mana_cost'] ?? null) ?></span> · <?= h($cardFace['type_line'] ?? '') ?></p><?= nl2br(h($cardFace['oracle_text'] ?? '')) ?></div>
    <?php endforeach; endif; ?>
    <dl>
      <dt>Edição</dt><dd><?= h($card['set_name']) ?> (<?= h(strtoupper((string)$card['set_code'])) ?>)</dd>
      <dt>Número</dt><dd><?= h($card['collector_number']) ?></dd>
      <dt>Raridade</dt><dd><?= h($card['rarity']) ?></dd>
      <dt>Artista</dt><dd><?= h($card['artist']) ?></dd>
      <dt>Lançamento</dt><dd><?= h(displayDate($card['released_at'])) ?></dd>
    </dl>

    <?php if (count($printings) > 1): ?>
      <details class="printings" open>
        <summary>Outras impressões (<?= count($printings) ?>)</summary>
        <div class="printing-list">
          <?php foreach ($printings as $printing): ?>
            <a class="<?= $printing['id'] === $card['id'] ? 'current' : '' ?>" href="/card.php?id=<?= h($printing['id']) ?>">
              <strong><?= h(strtoupper((string)$printing['set_code'])) ?> #<?= h($printing['collector_number']) ?></strong>
              <span><?= h($printing['set_name']) ?> · <?= h($printing['released_at']) ?></span>
            </a>
          <?php endforeach; ?>
        </div>
      </details>
    <?php endif; ?>
  </section>
</div>
<?php pageFooter(); ?>
