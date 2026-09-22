<?php
declare(strict_types=1);
/**
 * Página inicial: o que é o Deckarium, como as partes se encaixam e o que cada módulo faz.
 * Incluída por index.php quando "/" é aberto sem busca nem filtros; os números vêm do acervo real.
 */
$homeUser = authUser();
$homeStats = catalogCached('home-stats-v1', function (): array {
    $row = db()->query("SELECT count(DISTINCT COALESCE(oracle_id,id)) AS cards, count(DISTINCT set_code) AS sets,
        count(DISTINCT COALESCE(oracle_id,id)) FILTER (WHERE commander_eligible AND layout NOT IN ('art_series','token','double_faced_token','emblem') AND COALESCE(raw->>'digital','false') <> 'true') AS commanders
        FROM cards")->fetch();
    return $row ?: [];
}, 21600);
$homeSynced = catalogCached('home-synced-v1', fn(): array => [(string)db()->query('SELECT max(imported_at) FROM sync_status')->fetchColumn()], 3600)[0] ?? '';
// A mão do topo: os comandantes mais jogados no EDHREC, cada um na impressão mais nova com imagem.
$homeHand = catalogCached('home-hand-v1', fn(): array => db()->query("SELECT id, name FROM (
        SELECT DISTINCT ON (COALESCE(c.oracle_id,c.id)) c.id, c.name, c.edhrec_rank_cached AS rank
        FROM cards c
        WHERE c.commander_eligible AND c.edhrec_rank_cached IS NOT NULL AND c.lang = 'en'
          AND c.layout NOT IN ('art_series','token','double_faced_token','emblem','reversible_card')
          AND COALESCE(c.raw->>'digital','false') <> 'true' AND (c.image_uri IS NOT NULL OR c.local_image IS NOT NULL)
        ORDER BY COALESCE(c.oracle_id,c.id), (c.local_image IS NOT NULL) DESC, COALESCE(c.raw->>'promo','false') = 'true', c.released_at DESC NULLS LAST, c.id
    ) ranked ORDER BY rank LIMIT 5")->fetchAll(), 86400);
$homeMine = null;
if ($homeUser) {
    $mine = db()->prepare('SELECT (SELECT COALESCE(sum(quantity),0) FROM builder_collection WHERE user_id=:u) AS cards, (SELECT count(*) FROM builder_decks WHERE user_id=:u) AS decks');
    try { $mine->execute(['u' => (int)$homeUser['id']]); $homeMine = $mine->fetch() ?: null; } catch (Throwable) { $homeMine = null; }
}
$number = static fn($value): string => number_format((int)$value, 0, ',', '.');

// Os quatro passos do site; cada grupo de módulos abaixo segue a mesma ordem.
$homeSteps = [
    ['find', t('Encontre'), t('Pesquise qualquer carta pelo nome ou pelo texto, percorra as edições e escolha uma comandante.')],
    ['keep', t('Guarde'), t('Registre as cópias que você tem, com impressão e acabamento, e anote o que quer comprar ou vender.')],
    ['build', t('Monte'), t('Planeje um deck de Commander a partir da comandante e da sua coleção, com sugestões e análise.')],
    ['share', t('Compartilhe'), t('Deixe decks, coleção e lista de vendas públicos e mande o link para quem quiser.')],
];
$access = ['all' => t('Aberto a todos'), 'login' => t('Com conta'), 'admin' => t('Administração')];
$homeGroups = [
    'find' => [
        'title' => t('Encontrar cartas'),
        'lead' => t('O acervo inteiro do Scryfall, guardado no servidor: busca rápida e imagens nítidas, sem depender do site de fora.'),
        'modules' => [
            ['commanders', t('Comandantes'), '/commanders.php', 'all', t('Todos os comandantes do catálogo, sem repetir reimpressões.'),
                [t('Mais novos, mais populares no EDHREC, nome ou adicionados recentemente'), t('A coluna lateral mostra os mais jogados agora'), t('Na página da carta, abra um deck novo com ela')]],
            ['cards', t('Catálogo'), '/?catalog=1#catalogo', 'all', t('Busca e filtros sobre todas as cartas, uma impressão por carta.'),
                [t('Nome, texto Oracle, tipo, cores, raridade e edição'), t('Cartas únicas ou todas as impressões'), t('Ordem por lançamento, nome ou preço')]],
            ['sets', t('Edições'), '/editions.php', 'all', t('Linha do tempo de todos os lançamentos de Magic.'),
                [t('Cartas novas por ano e a proporção de cada cor'), t('Commander, promos e fichas agrupados na coleção-mãe'), t('Em cada edição, o filtro “Só cartas novas”')]],
            ['cards', t('Página da carta'), null, 'all', t('Arte, texto Oracle, preços e todas as outras impressões da carta.'),
                [t('Troque de edição em “Outras impressões”'), t('Preços do Scryfall em reais e atalho para a LigaMagic'), t('Guarde na coleção ou mande para um deck')]],
        ],
    ],
    'keep' => [
        'title' => t('Guardar o que você tem'),
        'lead' => t('Cada cópia fica registrada com impressão e acabamento. É com ela que os decks sabem o que você já tem.'),
        'modules' => [
            ['collection', t('Minha coleção'), '/collection.php', 'login', t('Suas cartas físicas, com valor estimado e uso em decks.'),
                [t('Importe, some ou subtraia cópias por CSV'), t('Veja quais cópias estão em decks e quais estão livres'), t('Torne a coleção pública, se quiser')]],
            ['wishlist', t('Lista de desejos'), '/wishlist.php', 'login', t('Cartas que você ainda não tem e quer comprar.'),
                [t('Adicione pelo catálogo, pelas edições ou pela página da carta'), t('Marca o que você já comprou, para saber o que ainda falta')]],
            ['trade', t('À venda'), '/trade.php', 'login', t('Uma lista do que você quer vender ou trocar, com link público.'),
                [t('Use as cópias soltas, fora dos decks, ou escolha à mão'), t('Quem abre o link filtra as cartas e ordena por preço')]],
        ],
    ],
    'build' => [
        'title' => t('Montar decks'),
        'lead' => t('A oficina de Commander: da comandante às 100 cartas, sempre olhando a sua coleção primeiro.'),
        'modules' => [
            ['decks', t('Meus decks'), '/decks.php', 'login', t('Biblioteca dos seus decks, planejados do zero ou importados do Moxfield.'),
                [t('Cada deck mostra a comandante, a intenção e quanto falta para 100'), t('Troque cartas com “Preparar upgrade” quando o deck estiver cheio'), t('Exporte em texto, JSON ou CSV da Liga')]],
        ],
    ],
    'share' => [
        'title' => t('Compartilhar'),
        'lead' => t('Nada é público até você decidir. Decks, coleção e lista de vendas têm, cada um, a sua chave.'),
        'modules' => [
            ['community', t('Comunidade'), '/public.php', 'all', t('Decks e coleções que outros jogadores tornaram públicos.'),
                [t('Abra qualquer deck público e veja as cartas'), t('Cada jogador tem um perfil com bio e o que ele publicou')]],
            ['account', t('Perfil e conta'), '/account.php', 'login', t('Seu nome, foto, capa, bio e idioma do site.'),
                [t('Veja onde você está conectado e desconecte o que não reconhecer'), t('O idioma escolhido fica guardado na conta')]],
        ],
    ],
];
// As abas de um deck, na ordem em que aparecem.
$deckTabs = [
    [t('Visão geral'), t('Comandante, a sua intenção para o deck, se ele é público e atalhos para o resto.')],
    [t('Guia da comandante'), t('Planos de jogo, combos, mecânicas e cartas novas que combinam com ela, do EDHREC.')],
    [t('O que falta'), t('Metas por função — ramp, compra, remoção, proteção — e sugestões para cada lacuna.')],
    [t('Explorar'), t('O catálogo filtrado pela identidade da comandante, em ordem de sinergia ou “Encaixa no deck”.')],
    [t('Minha seleção'), t('Candidatas e deck, com análise, mapa de jogo, fichas e terrenos preenchidos sozinhos.')],
    [t('Quadro de relações'), t('A constelação 3D de quem fornece e quem aproveita, com equilíbrio dos temas e sugestões da coleção.')],
];
$homeSources = [
    ['Scryfall', t('cartas, imagens, preços e legalidade')],
    ['EDHREC', t('popularidade, sinergia e guias das comandantes')],
    ['Commander Spellbook', t('combos completos e os que falta uma carta')],
    ['Scryfall Tagger', t('funções das cartas, que completam a leitura do texto')],
];
pageHeader('Início');
echo cardActionNotice();
?>
<section class="home-hero" aria-labelledby="home-title">
  <div class="home-hero-copy">
    <h1 id="home-title"><?= te('Sua coleção de Magic,') ?> <em><?= te('pronta para virar deck.') ?></em></h1>
    <p class="home-lede"><?= te('O Deckarium junta o catálogo completo de Magic, as cartas que você tem e uma oficina para montar decks de Commander. Aqui está o que cada parte do site faz e por onde começar.') ?></p>
    <form class="home-search" action="/" method="get" role="search">
      <label for="home-query"><?= te('Procure uma carta') ?></label>
      <div><input id="home-query" name="q" type="search" placeholder="<?= te('Nome da carta…') ?>" autocomplete="off" required><button type="submit"><?= te('Buscar') ?></button></div>
    </form>
    <div class="home-actions">
      <?php if ($homeUser): ?>
        <a class="primary-link" href="/decks.php"><?= te('Abrir meus decks') ?></a>
        <a class="home-secondary" href="/collection.php"><?= te('Minha coleção') ?></a>
      <?php else: ?>
        <a class="primary-link" href="/register.php"><?= te('Criar conta') ?></a>
        <a class="home-secondary" href="/login.php"><?= te('Já tenho conta') ?></a>
      <?php endif; ?>
    </div>
    <?php if (!empty($homeStats['cards'])): ?>
    <p class="home-facts">
      <span><b><?= $number($homeStats['cards']) ?></b> <?= te('cartas') ?></span>
      <span><b><?= $number($homeStats['sets']) ?></b> <?= te('edições') ?></span>
      <span><b><?= $number($homeStats['commanders']) ?></b> <?= te('comandantes') ?></span>
      <?php if ($homeMine): ?><span><b><?= $number($homeMine['cards']) ?></b> <?= te('na sua coleção') ?> · <b><?= $number($homeMine['decks']) ?></b> <?= (int)$homeMine['decks'] === 1 ? te('deck') : te('decks') ?></span><?php endif; ?>
      <?php if ($homeSynced !== ''): ?><span><?= te('catálogo atualizado em :date às :time', ['date' => date('d/m', strtotime($homeSynced)), 'time' => date('H:i', strtotime($homeSynced))]) ?></span><?php endif; ?>
    </p>
    <?php endif; ?>
  </div>
  <?php if ($homeHand): ?>
  <figure class="home-hand">
    <div class="home-hand-cards">
      <?php foreach ($homeHand as $i => $handCard): ?>
      <a class="home-hand-card" style="--i:<?= $i - intdiv(count($homeHand) - 1, 2) ?>" href="/card.php?id=<?= h(rawurlencode((string)$handCard['id'])) ?>">
        <img src="/image.php?id=<?= h(rawurlencode((string)$handCard['id'])) ?>&amp;size=normal" alt="<?= h($handCard['name']) ?>" width="488" height="680" <?= $i === intdiv(count($homeHand) - 1, 2) ? 'fetchpriority="high"' : 'loading="lazy"' ?>>
      </a>
      <?php endforeach; ?>
    </div>
    <figcaption><?= te('Na mão: os comandantes mais jogados no EDHREC agora.') ?> <a href="/commanders.php?sort=popular"><?= te('Ver todos') ?></a></figcaption>
  </figure>
  <?php endif; ?>
</section>

<section class="home-flow" aria-labelledby="home-flow-title">
  <h2 id="home-flow-title"><?= te('Como funciona') ?></h2>
  <ol class="home-steps">
    <?php foreach ($homeSteps as $index => [$key, $title, $text]): ?>
    <li><a href="#modulo-<?= h($key) ?>"><span class="home-step-number" aria-hidden="true"><?= $index + 1 ?></span><strong><?= h($title) ?></strong><span><?= h($text) ?></span></a></li>
    <?php endforeach; ?>
  </ol>
</section>

<div class="home-modules" aria-label="<?= te('Módulos do site') ?>">
  <?php $groupIndex = 0; foreach ($homeGroups as $key => $group): $groupIndex++; ?>
  <section class="home-group" id="modulo-<?= h($key) ?>" aria-labelledby="home-group-<?= h($key) ?>">
    <header class="home-group-head">
      <span class="home-group-step"><?= te('Passo :n', ['n' => $groupIndex]) ?></span>
      <h2 id="home-group-<?= h($key) ?>"><?= h($group['title']) ?></h2>
      <p><?= h($group['lead']) ?></p>
    </header>
    <div class="home-group-body">
      <div class="home-module-list<?= count($group['modules']) === 1 ? ' is-single' : '' ?>">
        <?php foreach ($group['modules'] as [$icon, $name, $url, $who, $summary, $features]): ?>
        <article class="home-module">
          <span class="home-module-icon" aria-hidden="true"><?= uiIcon($icon) ?></span>
          <div>
            <h3><?php if ($url): ?><a href="<?= h($url) ?>"><?= h($name) ?></a><?php else: ?><?= h($name) ?><?php endif; ?> <span class="home-access is-<?= h($who) ?>"><?= h($access[$who]) ?></span></h3>
            <p><?= h($summary) ?></p>
            <ul><?php foreach ($features as $feature): ?><li><?= h($feature) ?></li><?php endforeach; ?></ul>
          </div>
        </article>
        <?php endforeach; ?>
      </div>
      <?php if ($key === 'build'): ?>
      <div class="home-deck">
        <h3><?= te('Dentro de cada deck') ?></h3>
        <ol class="home-deck-tabs">
          <?php foreach ($deckTabs as [$tab, $text]): ?><li><strong><?= h($tab) ?></strong><span><?= h($text) ?></span></li><?php endforeach; ?>
        </ol>
      </div>
      <figure class="home-spotlight">
        <img src="/assets/home/quadro-constelacao.jpg" alt="<?= te('Quadro de relações em 3D: cartas de um deck de Merfolk flutuando em territórios por tema, ligadas por linhas coloridas.') ?>" width="1200" height="675" loading="lazy">
        <figcaption>
          <strong><?= te('Quadro de relações em 3D') ?></strong>
          <span><?= te('As cartas flutuam agrupadas pelo tema em que mais se relacionam. Cada linha sai de quem fornece algo — Tesouros, mortes, marcadores, uma tribo — e chega em quem aproveita. O painel ao lado aponta temas sem fonte e procura na sua coleção as cartas que fechariam essas pontas.') ?></span>
        </figcaption>
      </figure>
      <?php endif; ?>
    </div>
  </section>
  <?php endforeach; ?>
</div>

<section class="home-backstage" aria-labelledby="home-backstage-title">
  <div>
    <h2 id="home-backstage-title"><?= te('De onde vêm os dados') ?></h2>
    <p><?= te('O catálogo é baixado do Scryfall e fica no servidor. Todo dia, às 06:10 e às 18:10, uma rotina confere se saiu algo novo e, se saiu, atualiza cartas, preços e imagens. Sua coleção e seus decks não são tocados.') ?></p>
    <dl class="home-sources">
      <?php foreach ($homeSources as [$source, $use]): ?><div><dt><?= h($source) ?></dt><dd><?= h($use) ?></dd></div><?php endforeach; ?>
    </dl>
  </div>
  <div class="home-backstage-side">
    <h3><?= te('Idioma') ?></h3>
    <p><?= te('O site existe em português e em inglês. Troque no fim do menu lateral; a escolha fica guardada na sua conta.') ?></p>
    <?php if (authIsAdmin()): ?>
    <h3><?= te('Administração') ?></h3>
    <ul class="home-admin-links">
      <li><a href="/status.php"><?= te('Status do acervo') ?></a> — <?= te('sincronização, imagens e a rotina automática') ?></li>
      <li><a href="/sync_history.php"><?= te('Histórico de atualizações') ?></a> — <?= te('o que entrou em cada importação') ?></li>
      <li><a href="/users.php"><?= te('Usuários') ?></a> — <?= te('contas, papéis e acesso') ?></li>
    </ul>
    <?php endif; ?>
  </div>
</section>
<?php pageFooter(); ?>
