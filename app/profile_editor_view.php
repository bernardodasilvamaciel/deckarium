<?php
// Minha conta › Perfil público: prévia ao vivo da capa e da foto, e o formulário do perfil.
$editorCover = profileCoverUrl($publicProfile);
$editorAvatar = profileAvatarUrl($publicProfile);
$editorColors = json_decode((string)$publicProfile['favorite_colors'], true) ?: [];
$editorCoverMode = !empty($publicProfile['cover_file']) ? 'upload' : (!empty($publicProfile['cover_card_id']) ? 'card' : 'none');
$editorCoverCard = '';
if (!empty($publicProfile['cover_card_id'])) { $cardName = db()->prepare('SELECT name FROM cards WHERE id=?'); $cardName->execute([$publicProfile['cover_card_id']]); $editorCoverCard = (string)$cardName->fetchColumn(); }
$editorColorNames = ['W'=>'Branco','U'=>'Azul','B'=>'Preto','R'=>'Vermelho','G'=>'Verde','C'=>'Incolor'];
?>
<section class="panel profile-editor" id="perfil-publico" aria-labelledby="profile-editor-title" data-profile-editor>
  <div class="profile-editor-heading">
    <div><h2 id="profile-editor-title">Perfil público</h2><p class="muted">Todos podem ver seu perfil. Seu nome completo e seu email nunca aparecem nele. Decks e coleção continuam com a visibilidade que você escolheu em cada um.</p></div>
    <a class="secondary-link" href="/profile.php?u=<?= h(rawurlencode((string)$publicProfile['username'])) ?>">Ver meu perfil</a>
  </div>

  <div class="profile-hero is-preview<?= $editorCover ? ' has-cover' : '' ?><?= $editorCoverMode === 'card' ? ' is-card-art' : '' ?>" style="--cover-y:<?= (int)$publicProfile['cover_position'] ?>%" data-profile-preview>
    <div class="profile-cover"><img<?= $editorCover ? ' src="'.h($editorCover).'"' : ' hidden' ?> alt="" data-preview-cover></div>
    <div class="profile-identity">
      <span class="profile-avatar"><img<?= $editorAvatar ? ' src="'.h($editorAvatar).'"' : ' hidden' ?> alt="" data-preview-avatar><span aria-hidden="true" data-preview-initials<?= $editorAvatar ? ' hidden' : '' ?>><?= h(profileInitials($publicProfile)) ?></span></span>
      <div class="profile-names"><strong data-preview-name><?= h(profileName($publicProfile)) ?></strong><p>@<?= h($publicProfile['username']) ?></p></div>
    </div>
  </div>

  <form class="account-form profile-form" method="post" enctype="multipart/form-data">
    <?= authCsrfField() ?><input type="hidden" name="action" value="public_profile"><input type="hidden" name="MAX_FILE_SIZE" value="<?= PROFILE_IMAGE_MAX_BYTES ?>">
    <div class="profile-form-grid">
      <label>Nome de exibição<input name="display_name" value="<?= h((string)$publicProfile['display_name']) ?>" maxlength="60" placeholder="@<?= h($publicProfile['username']) ?>" data-preview-source="name"><small>Vazio mostra seu @usuário.</small></label>
      <label>Local<input name="location" value="<?= h((string)$publicProfile['location']) ?>" maxlength="80" placeholder="Cidade, loja onde joga…"></label>
      <label class="<?= isset($publicErrors['website']) ? 'has-error' : '' ?>">Site ou rede social<input name="website" value="<?= h((string)$publicProfile['website']) ?>" maxlength="200" inputmode="url" placeholder="https://moxfield.com/users/…"><?= $error($publicErrors, 'website') ?></label>
      <fieldset class="profile-colors-field"><legend>Cores favoritas</legend><div><?php foreach ($editorColorNames as $color => $colorName): ?><label class="profile-color-chip"><input type="checkbox" name="favorite_colors[]" value="<?= $color ?>" <?= in_array($color, $editorColors, true) ? 'checked' : '' ?>><img src="https://svgs.scryfall.io/card-symbols/<?= $color ?>.svg" alt="" width="20" height="20"><span><?= h($colorName) ?></span></label><?php endforeach; ?></div></fieldset>
      <label class="profile-bio-field">Bio<textarea name="bio" rows="4" maxlength="<?= PROFILE_BIO_MAX ?>" placeholder="Que decks você gosta de jogar? Desde quando joga Magic?" data-bio-counter><?= h((string)$publicProfile['bio']) ?></textarea><small><span data-bio-count><?= mb_strlen((string)$publicProfile['bio']) ?></span>/<?= PROFILE_BIO_MAX ?> caracteres</small></label>
    </div>

    <div class="profile-media-grid">
      <fieldset class="<?= isset($publicErrors['avatar']) ? 'has-error' : '' ?>"><legend>Foto de perfil</legend>
        <label class="profile-file">Escolher imagem<input type="file" name="avatar" accept="image/jpeg,image/png,image/webp" data-preview-file="avatar"></label>
        <small>JPEG, PNG ou WebP até 2 MB. Aparece recortada em círculo. Localização e dados da câmera são removidos.</small>
        <?php if (!empty($publicProfile['avatar_file'])): ?><label class="builder-check"><input type="checkbox" name="remove_avatar" value="1"> Remover foto atual</label><?php endif; ?>
        <?= $error($publicErrors, 'avatar') ?>
      </fieldset>
      <fieldset class="<?= isset($publicErrors['cover']) || isset($publicErrors['cover_card']) ? 'has-error' : '' ?>"><legend>Capa</legend>
        <div class="profile-cover-modes">
          <label><input type="radio" name="cover_mode" value="upload" <?= $editorCoverMode === 'upload' ? 'checked' : '' ?>> Imagem enviada</label>
          <label><input type="radio" name="cover_mode" value="card" <?= $editorCoverMode === 'card' ? 'checked' : '' ?>> Ilustração de uma carta</label>
          <label><input type="radio" name="cover_mode" value="none" <?= $editorCoverMode === 'none' ? 'checked' : '' ?>> Sem capa</label>
        </div>
        <label class="profile-file" data-cover-mode-only="upload">Escolher imagem<input type="file" name="cover" accept="image/jpeg,image/png,image/webp" data-preview-file="cover"></label>
        <label data-cover-mode-only="card">Nome da carta (em inglês)<input name="cover_card" value="<?= h($editorCoverCard) ?>" maxlength="160" placeholder="Benjamin Sisko, Besieged"><small>Usa só a ilustração da carta, sem moldura.</small></label>
        <label>Enquadramento vertical<input type="range" name="cover_position" min="0" max="100" value="<?= (int)$publicProfile['cover_position'] ?>" data-preview-position><small>Arraste para escolher a parte da imagem que aparece na faixa.</small></label>
        <?= $error($publicErrors, 'cover') ?><?= $error($publicErrors, 'cover_card') ?>
      </fieldset>
    </div>
    <button class="primary-link">Salvar perfil</button>
  </form>
</section>
