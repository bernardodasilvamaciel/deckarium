<?php
declare(strict_types=1);
/**
 * Página pública de um deck: somente leitura, aberta a quem tiver o link (mesmo sem conta).
 * Mostra comandante, intenção e a lista aprovada. Coleção, preços, candidatas e anotações ficam de fora.
 */
require __DIR__ . '/functions.php';
require __DIR__ . '/partials.php';
require __DIR__ . '/deck_library.php';
deckSchema();
$viewer = authUser();
session_write_close();

$id = max(0, (int)($_GET['id'] ?? 0));
$deck = $id ? deckQuery('SELECT d.*,u.username FROM builder_decks d JOIN users u ON u.id=d.user_id WHERE d.id=? AND u.is_active', [$id])->fetch() : null;
$isOwner = $deck && $viewer && (int)$viewer['id'] === (int)$deck['user_id'];
$isPublic = $deck && in_array($deck['is_public'], [true, 't', 1, '1'], true);
if (!$deck || (!$isPublic && !$isOwner)) {
    http_response_code(404);
    pageHeader('Deck não encontrado');
    echo '<section class="empty-state"><h2>Deck não encontrado</h2><p>Este deck não existe ou é privado.</p><p><a href="/public.php">Ver decks da comunidade</a></p></section>';
    pageFooter();
    exit;
}
$commander = $deck['commander_id'] ? deckQuery('SELECT * FROM cards WHERE id=?', [$deck['commander_id']])->fetch() : null;
$items = deckQuery("SELECT c.*,i.quantity FROM builder_items i JOIN cards c ON c.id=i.card_id WHERE i.deck_id=? AND i.stage='deck' ORDER BY c.cmc,c.name", [$id])->fetchAll();

// Lista em texto, no formato aceito por Moxfield e pela importação do Deckarium.
if (($_GET['export'] ?? '') === 'txt') {
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-z0-9]+/i', '-', (string)$deck['name']) . '.txt"');
    if ($commander) echo "Commander\n1 " . $commander['name'] . "\n\nDeck\n";
    foreach ($items as $item) echo (int)$item['quantity'] . ' ' . $item['name'] . "\n";
    exit;
}

$typeLabels = ['Creature' => 'Criaturas', 'Planeswalker' => 'Planeswalkers', 'Instant' => 'Instantâneas', 'Sorcery' => 'Feitiços', 'Artifact' => 'Artefatos', 'Enchantment' => 'Encantamentos', 'Battle' => 'Batalhas', 'Land' => 'Terrenos'];
$groups = [];
$total = $commander ? 1 : 0;
$curve = array_fill(0, 8, 0);
foreach ($items as $item) {
    $front = explode(' // ', (string)$item['type_line'])[0];
    $label = 'Outras';
    foreach ($typeLabels as $key => $name) if (str_contains($front, $key)) { $label = $name; break; }
    $groups[$label][] = $item;
    $total += (int)$item['quantity'];
    if (!str_contains($front, 'Land')) $curve[min(7, (int)floor((float)$item['cmc']))] += (int)$item['quantity'];
}
uksort($groups, fn($a, $b) => array_search($a, array_values($typeLabels) + [99 => 'Outras']) <=> array_search($b, array_values($typeLabels) + [99 => 'Outras']));
$identity = $commander ? (json_decode((string)$commander['color_identity'], true) ?: []) : [];
$maxCurve = max(1, ...$curve);

pageHeader($deck['name'] . ' · deck público', 'Deck de Commander "' . $deck['name'] . '"' . ($commander ? ' com ' . $commander['name'] : '') . ', publicado por @' . $deck['username'] . ' no Deckarium.');
?>
<div class="public-page">
<?php if (!$isPublic): ?><p class="notice warning">Pré-visualização: este deck é privado. Torne-o público na <a href="/decks.php?deck=<?= $id ?>&amp;view=overview">Visão geral</a> para compartilhar o link.</p><?php endif; ?>
<section class="public-deck-hero">
    <?php if ($commander && ($src = cardImageUrl($commander))): ?><a class="public-deck-commander" href="/card.php?id=<?= h(rawurlencode((string)$commander['id'])) ?>"><img src="<?= h($src) ?>" alt="<?= h($commander['name']) ?>" width="244" height="340"></a><?php endif; ?>
    <div>
        <p class="public-kicker">Deck de <a href="/profile.php?u=<?= h(rawurlencode((string)$deck['username'])) ?>">@<?= h($deck['username']) ?></a></p>
        <h1><?= h($deck['name']) ?></h1>
        <p class="public-deck-meta"><?= $commander ? 'Comandante: <strong>' . h($commander['name']) . '</strong>' : 'Sem comandante definida' ?><?= $identity ? ' · ' . manaSymbols(implode('', array_map(fn($c) => '{' . $c . '}', $identity))) : '' ?> · <?= $total ?>/100 cartas</p>
        <?php if (trim((string)$deck['strategy']) !== ''): ?><div class="public-deck-strategy"><h2>Intenção do deck</h2><p><?= nl2br(h((string)$deck['strategy'])) ?></p></div><?php endif; ?>
        <p class="public-actions"><a class="secondary-link" href="?id=<?= $id ?>&amp;export=txt">Baixar lista (.txt)</a><?php if ($isOwner): ?><a href="/decks.php?deck=<?= $id ?>">Abrir no meu planejamento</a><?php endif; ?></p>
    </div>
    <div class="public-curve" aria-label="Curva de mana">
        <h2>Curva de mana</h2>
        <div class="public-curve-bars"><?php foreach ($curve as $cost => $count): ?><span><b><?= $count ?></b><i style="height:<?= max(3, round($count / $maxCurve * 90)) ?>px"></i><small><?= $cost === 7 ? '7+' : $cost ?></small></span><?php endforeach; ?></div>
    </div>
</section>

<?php if (!$items): ?><p class="empty-state">Este deck ainda não tem cartas aprovadas.</p><?php endif; ?>
<?php foreach ($groups as $label => $cards): $count = array_sum(array_map(fn($c) => (int)$c['quantity'], $cards)); ?>
<section class="public-group">
    <h2><?= h($label) ?> <span><?= $count ?></span></h2>
    <div class="public-card-grid">
        <?php foreach ($cards as $card): $src = cardImageUrl($card, 'front', 'small'); ?>
        <a class="public-card" href="/card.php?id=<?= h(rawurlencode((string)$card['id'])) ?>">
            <span class="public-card-art"><?php if ($src): ?><img src="<?= h($src) ?>" alt="" loading="lazy" width="146" height="204"><?php endif; ?><?php if ((int)$card['quantity'] > 1): ?><b><?= (int)$card['quantity'] ?>×</b><?php endif; ?></span>
            <span class="public-card-name"><?= h($card['name']) ?></span>
        </a>
        <?php endforeach; ?>
    </div>
</section>
<?php endforeach; ?>
</div>
<?php pageFooter(); ?>
