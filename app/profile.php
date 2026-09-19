<?php
declare(strict_types=1);
/**
 * Perfil público de um jogador (/profile.php?u=usuario): capa, foto, nome de exibição, bio e informações.
 * Mostra só os decks públicos e a coleção se ela for pública; o nome completo e o email nunca aparecem.
 */
require __DIR__ . '/functions.php';
require __DIR__ . '/partials.php';
require __DIR__ . '/deck_library.php';
require __DIR__ . '/profile_lib.php';
deckSchema();
profileSchema();
$viewer = authUser();
session_write_close();

$username = is_string($_GET['u'] ?? null) ? mb_substr(trim($_GET['u']), 0, 60) : '';
$profile = $username !== '' ? deckQuery('SELECT * FROM users WHERE lower(username)=lower(?) AND is_active', [$username])->fetch() : null;
if (!$profile) {
    http_response_code(404);
    pageHeader('Perfil não encontrado');
    echo '<section class="empty-state"><h2>Perfil não encontrado</h2><p>Este jogador não existe ou a conta foi desativada.</p><p><a href="/public.php">Ver a comunidade</a></p></section>';
    pageFooter();
    exit;
}
$profileId = (int)$profile['id'];
$isOwner = $viewer && (int)$viewer['id'] === $profileId;
$decks = deckQuery("SELECT d.id,d.name,d.status,c.id commander_card_id,c.name commander,c.color_identity,
        COALESCE(c.raw->'image_uris'->>'art_crop',c.raw->'card_faces'->0->'image_uris'->>'art_crop') commander_art,
        (SELECT COALESCE(SUM(quantity),0) FROM builder_items WHERE deck_id=d.id AND stage='deck') + CASE WHEN d.commander_id IS NULL THEN 0 ELSE 1 END card_count
    FROM builder_decks d LEFT JOIN cards c ON c.id=d.commander_id WHERE d.user_id=? AND d.is_public ORDER BY d.id DESC", [$profileId])->fetchAll();
$privateDecks = $isOwner ? (int)deckQuery('SELECT COUNT(*) FROM builder_decks WHERE user_id=? AND NOT is_public', [$profileId])->fetchColumn() : 0;
$collectionPublic = in_array($profile['collection_public'], [true, 't', 1, '1'], true);
$collection = $collectionPublic ? deckQuery('SELECT COALESCE(SUM(quantity),0) total, COUNT(DISTINCT scryfall_id) printings FROM builder_collection WHERE user_id=?', [$profileId])->fetch() : null;
$colors = json_decode((string)$profile['favorite_colors'], true) ?: [];
$cover = profileCoverUrl($profile);
$avatar = profileAvatarUrl($profile);
$name = profileName($profile);
$website = (string)$profile['website'];

pageHeader($name . ' · Perfil');
?>
<article class="profile-page">
<header class="profile-hero<?= $cover ? ' has-cover' : '' ?><?= $cover && empty($profile['cover_file']) ? ' is-card-art' : '' ?>" style="--cover-y:<?= (int)$profile['cover_position'] ?>%">
    <div class="profile-cover"><?php if ($cover): ?><img src="<?= h($cover) ?>" alt="" fetchpriority="high"><?php endif; ?></div>
    <div class="profile-identity">
        <span class="profile-avatar"><?php if ($avatar): ?><img src="<?= h($avatar) ?>" alt="Foto de <?= h($name) ?>" width="132" height="132"><?php else: ?><span aria-hidden="true"><?= h(profileInitials($profile)) ?></span><?php endif; ?></span>
        <div class="profile-names">
            <h1><?= h($name) ?></h1>
            <p>@<?= h($profile['username']) ?><?php if ($colors): ?> <span class="profile-colors" title="Cores favoritas"><?= manaSymbols(implode('', array_map(fn($color) => '{' . $color . '}', $colors))) ?></span><?php endif; ?></p>
        </div>
        <?php if ($isOwner): ?><a class="secondary-link profile-edit" href="/account.php#perfil-publico">Editar perfil</a><?php endif; ?>
    </div>
</header>

<div class="profile-body">
    <aside class="profile-about">
        <?php if (trim((string)$profile['bio']) !== ''): ?><p class="profile-bio"><?= nl2br(h((string)$profile['bio'])) ?></p><?php elseif ($isOwner): ?><p class="muted">Você ainda não escreveu uma bio. <a href="/account.php#perfil-publico">Escrever agora</a></p><?php endif; ?>
        <ul class="profile-facts">
            <?php if (trim((string)$profile['location']) !== ''): ?><li><span>Local</span><strong><?= h((string)$profile['location']) ?></strong></li><?php endif; ?>
            <?php if ($website !== ''): ?><li><span>Site</span><a href="<?= h($website) ?>" rel="nofollow ugc noopener" target="_blank"><?= h(preg_replace('~^https?://(www\.)?~i', '', rtrim($website, '/'))) ?></a></li><?php endif; ?>
            <li><span>Na Deckarium desde</span><strong><?= h(date('m/Y', strtotime((string)$profile['created_at']))) ?></strong></li>
            <li><span>Decks públicos</span><strong><?= count($decks) ?></strong></li>
            <li><span>Coleção</span><?php if ($collection): ?><a href="/public_collection.php?u=<?= h(rawurlencode((string)$profile['username'])) ?>"><?= number_format((int)$collection['total'], 0, ',', '.') ?> cartas · ver</a><?php else: ?><strong>Privada</strong><?php endif; ?></li>
        </ul>
        <?php if ($isOwner): ?><p class="profile-owner-note">Só você vê esta nota: <?= $privateDecks ?> deck(s) privado(s) e a coleção <?= $collectionPublic ? 'pública' : 'privada' ?>. Cada deck e a coleção têm a própria opção de compartilhar.</p><?php endif; ?>
    </aside>

    <section class="profile-decks" aria-labelledby="profile-decks-title">
        <div class="section-heading"><h2 id="profile-decks-title">Decks públicos <span class="muted"><?= count($decks) ?></span></h2></div>
        <?php if (!$decks): ?><p class="empty-state"><?= $isOwner ? 'Nenhum deck público. Use “Compartilhar” na Visão geral de um deck para mostrá-lo aqui.' : 'Este jogador ainda não tornou nenhum deck público.' ?></p><?php else: ?>
        <div class="profile-deck-grid">
        <?php foreach ($decks as $deck): $identity = json_decode((string)($deck['color_identity'] ?? '[]'), true) ?: []; ?>
            <a class="profile-deck" href="/public_deck.php?id=<?= (int)$deck['id'] ?>"<?php if ($deck['commander_art']): ?> style="--deck-art:url('<?= h($deck['commander_art']) ?>')"<?php endif; ?>>
                <span class="profile-deck-art" aria-hidden="true"></span>
                <span class="profile-deck-copy"><strong><?= h($deck['name']) ?></strong><span><?= h($deck['commander'] ?: 'Comandante a escolher') ?></span><small><?= $identity ? manaSymbols(implode('', array_map(fn($c) => '{' . $c . '}', $identity))) : '' ?> <?= (int)$deck['card_count'] ?>/100 · <?= $deck['status'] === 'ready' ? 'Finalizado' : 'Em planejamento' ?></small></span>
            </a>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </section>
</div>
</article>
<?php pageFooter(); ?>
