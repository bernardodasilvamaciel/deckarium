<?php
declare(strict_types=1);
// Included by index.php after the shared catalog data is loaded.
?>
<section class="home-intro" aria-labelledby="home-title">
  <div class="home-copy">
    <h1 id="home-title">Grandes decks. <br>Começam com<br><em>uma descoberta.</em></h1>
    <p>Encontre a próxima peça do seu deck. Explore cartas, organize sua coleção e dê espaço a novas estratégias.</p>
    <form class="home-search" action="/" method="get">
      <label for="home-query">Qual carta você procura?</label>
      <div><input id="home-query" name="q" type="search" placeholder="Nome da carta…" required><button type="submit">Buscar</button></div>
    </form>
    <div class="home-explore"><span>Comece por</span><a href="/?type=Dragon#catalogo">Dragões</a><a href="/?type=Legendary#catalogo">Lendárias</a><a href="/?catalog=1#catalogo">Todo o catálogo</a></div>
  </div>
</section>
<nav class="home-destinations" aria-label="Seu espaço no Deckarium">
  <a href="/collection.php"><span><strong>Minha coleção</strong><small>Suas cartas, bem organizadas.</small></span><span aria-hidden="true">↗</span></a>
  <a href="/decks.php"><span><strong>Meus decks</strong><small>Da primeira ideia à próxima partida.</small></span><span aria-hidden="true">↗</span></a>
  <?php if (authIsAdmin()): ?><a href="/status.php"><span><strong>Meu acervo local</strong><small>Consulte a sincronização e as imagens.</small></span><span aria-hidden="true">↗</span></a><?php elseif (!authUser()): ?><a href="/register.php"><span><strong>Criar sua conta</strong><small>Sua coleção e seus decks, só seus.</small></span><span aria-hidden="true">↗</span></a><?php endif; ?>
</nav>
<section class="home-editions" aria-labelledby="home-editions-title">
  <div class="section-heading"><h2 id="home-editions-title">Novos mundos para explorar</h2><a href="/editions.php">Todas as edições →</a></div>
  <div class="edition-mini-grid">
    <?php foreach ($recentSets as $edition): ?>
    <a class="edition-mini" href="/edition.php?set=<?= urlencode((string)$edition['set_code']) ?>"><strong><?= h($edition['set_name']) ?></strong><span><?= h(strtoupper((string)$edition['set_code'])) ?> · <?= h(date('d/m/Y', strtotime((string)$edition['released_at']))) ?></span><small><?= number_format((int)$edition['unique_cards'], 0, ',', '.') ?> cartas únicas</small></a>
    <?php endforeach; ?>
    <?php if (!$recentSets): ?><p class="muted">As edições aparecerão após a sincronização do catálogo. <?php if (authIsAdmin()): ?><a href="/status.php">Consultar status</a><?php endif; ?></p><?php endif; ?>
  </div>
</section>
<div class="home-catalog-heading"><h2>Encontre sua próxima carta</h2><p>Pesquise no acervo e refine os resultados pelos filtros abaixo.</p></div>
