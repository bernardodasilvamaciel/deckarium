<?php
declare(strict_types=1);
// Grade de impressões da página da carta: comparar e trocar a versão vendo a arte, o preço e o que você tem.
// A troca continua sem recarregar a página (data-printing-link, em app.js); os filtros são só no navegador.
$printingLangs = ['en'=>'Inglês','pt'=>'Português','es'=>'Espanhol','fr'=>'Francês','de'=>'Alemão','it'=>'Italiano','ja'=>'Japonês','ko'=>'Coreano','ru'=>'Russo','zhs'=>'Chinês simpl.','zht'=>'Chinês trad.','la'=>'Latim','ph'=>'Phyrexiano'];
$printingRarities = ['common'=>'Comum','uncommon'=>'Incomum','rare'=>'Rara','mythic'=>'Mítica','special'=>'Especial','bonus'=>'Bônus'];
$printingOwnedTotal = 0;
$printingLangsPresent = [];
foreach ($printings as $printingRow) {
    $printingOwnedTotal += (int)$printingRow['owned_normal'] + (int)$printingRow['owned_foil'];
    $printingLangsPresent[(string)$printingRow['lang']] = true;
}
?>
<section class="printings-panel" data-printings>
  <div class="printings-head">
    <div>
      <h2>Impressões desta carta</h2>
      <p class="muted"><?= count($printings) ?> versões<?= $printingOwnedTotal ? ' · '.$printingOwnedTotal.($printingOwnedTotal === 1 ? ' cópia sua' : ' cópias suas') : '' ?>. Clique em uma para ver os dados e o preço dela.</p>
    </div>
  </div>

  <div class="printings-toolbar">
    <label class="printings-search">Edição ou número<input type="search" data-printing-filter placeholder="ecl, commander, #123…" autocomplete="off"></label>
    <label>Idioma<select data-printing-lang>
      <option value="">Todos</option>
      <?php foreach ($printingLangs as $langCode=>$langLabel): if(!isset($printingLangsPresent[$langCode])) continue; ?>
        <option value="<?= h($langCode) ?>"><?= h($langLabel) ?></option>
      <?php endforeach; ?>
    </select></label>
    <label>Ordenar<select data-printing-sort>
      <option value="default">Lançamento (mais novas)</option>
      <option value="old">Lançamento (mais antigas)</option>
      <option value="cheap">Preço: menor primeiro</option>
      <option value="expensive">Preço: maior primeiro</option>
      <option value="set">Edição e número</option>
    </select></label>
    <?php if ($printingOwnedTotal): ?><label class="printings-check"><input type="checkbox" data-printing-owned> Só as que eu tenho</label><?php endif; ?>
  </div>

  <div class="printings-grid" data-printing-grid>
    <?php foreach ($printings as $index=>$printing):
        $printingPrices = deckPriceOptions($printing);
        $printingCheapest = array_values(array_filter([$printingPrices['normal'], $printingPrices['foil']], fn($value)=>$value!==null));
        $printingOwned = (int)$printing['owned_normal'] + (int)$printing['owned_foil'];
        $printingYear = $printing['released_at'] ? substr((string)$printing['released_at'], 0, 4) : '';
        $printingIcon = setIconUrl((string)$printing['set_code']);
    ?>
    <a data-printing-link class="printing-tile <?= $printing['id'] === $card['id'] ? 'current' : '' ?>"
       href="/card.php?id=<?= h($printing['id']) ?>"
       data-search="<?= h(mb_strtolower($printing['set_code'].' '.$printing['set_name'].' #'.$printing['collector_number'])) ?>"
       data-lang="<?= h((string)$printing['lang']) ?>"
       data-owned="<?= $printingOwned ?>"
       data-price="<?= $printingCheapest ? h((string)min($printingCheapest)) : '' ?>"
       data-released="<?= h((string)($printing['released_at'] ?? '')) ?>"
       data-order="<?= $index ?>"
       data-set="<?= h(strtoupper((string)$printing['set_code']).str_pad((string)$printing['collector_number'], 8, '0', STR_PAD_LEFT)) ?>">
      <span class="printing-tile-art">
        <?php if ($printingSrc = cardImageUrl($printing, 'front', 'small')): ?>
          <img src="<?= h($printingSrc) ?>" alt="<?= h(strtoupper((string)$printing['set_code']).' #'.$printing['collector_number']) ?>" loading="lazy" width="146" height="204">
        <?php else: ?><span class="printing-tile-noart">sem imagem</span><?php endif; ?>
        <?php if ($printing['id'] === $card['id']): ?><b class="printing-tag is-current">Vendo agora</b><?php endif; ?>
        <?php if ($printingOwned): ?><b class="printing-tag is-owned"><?= $printingOwned ?>× sua<?= (int)$printing['owned_foil'] ? ($printing['owned_normal'] ? ' · foil' : ' foil') : '' ?></b><?php endif; ?>
      </span>
      <span class="printing-tile-body">
        <strong>
          <?php if ($printingIcon): ?><img class="set-icon printing-tile-icon" src="<?= h($printingIcon) ?>" alt="" width="16" height="16" loading="lazy"><?php endif; ?>
          <?= h(strtoupper((string)$printing['set_code'])) ?> #<?= h((string)$printing['collector_number']) ?>
        </strong>
        <span class="printing-tile-set"><?= h((string)$printing['set_name']) ?></span>
        <span class="printing-tile-meta"><?= h($printingYear) ?> · <?= h($printingLangs[(string)$printing['lang']] ?? strtoupper((string)$printing['lang'])) ?> · <?= h($printingRarities[(string)$printing['rarity']] ?? (string)$printing['rarity']) ?></span>
        <span class="printing-tile-price">
          <?php if ($printingPrices['normal'] !== null): ?><b>R$ <?= number_format($printingPrices['normal'], 2, ',', '.') ?></b><?php endif; ?>
          <?php if ($printingPrices['foil'] !== null): ?><i>foil R$ <?= number_format($printingPrices['foil'], 2, ',', '.') ?></i><?php endif; ?>
          <?php if ($printingPrices['normal'] === null && $printingPrices['foil'] === null): ?><i>sem cotação</i><?php endif; ?>
        </span>
      </span>
    </a>
    <?php endforeach; ?>
  </div>
  <p class="printings-empty" data-printing-empty hidden>Nenhuma impressão com esses filtros.</p>
</section>
