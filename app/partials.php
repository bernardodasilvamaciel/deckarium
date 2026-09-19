<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/i18n.php';

function uiIcon(string $name): string
{
    $paths = [
        'home'=>'<path d="m3 10 9-7 9 7v11h-6v-7H9v7H3Z"/>',
        'cards'=>'<path d="M13 20H6a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v4M7 7h6M7 10.5h3"/><circle cx="16" cy="15" r="3.5"/><path d="m18.6 17.6 2.9 2.9"/>',
        'collection'=>'<rect x="5" y="3" width="15" height="18" rx="2"/><path d="M12.5 3v18M5 12h15M3 7.5h2M3 16.5h2"/>',
        'decks'=>'<mask id="ui-icon-decks-front"><rect width="24" height="24" fill="#fff"/><rect x="10" y="4" width="10" height="14" rx="1.8" transform="rotate(10 15 11)" fill="#000" stroke="#000" stroke-width="3.2"/></mask><g mask="url(#ui-icon-decks-front)"><rect x="3.2" y="5.8" width="10" height="14" rx="1.8" transform="rotate(-14 8.2 12.8)"/></g><rect x="10" y="4" width="10" height="14" rx="1.8" transform="rotate(10 15 11)"/><path d="m15 8.6 2 2.4-2 2.4-2-2.4Z" transform="rotate(10 15 11)"/>',
        'sets'=>'<path d="M12 5C8 2 4 3 2 4v15c3-1 6-1 10 2 4-3 7-3 10-2V4c-2-1-6-2-10 1Zm0 0v16"/>',
        'commanders'=>'<path d="M3.5 19.5h17M5 19.5V6l4.5 3.5L12 4l2.5 5.5L19 6v13.5M5 15h14"/>',
        'status'=>'<path d="M8 5h12M8 12h12M8 19h12M3 5h1M3 12h1M3 19h1"/>',
        'users'=>'<circle cx="9" cy="8" r="3.2"/><path d="M3 20c.6-3.4 3-5.2 6-5.2s5.4 1.8 6 5.2M16 5.2a3 3 0 0 1 0 5.6M18 14.8c1.6.6 2.7 2.3 3 5.2"/>',
        'account'=>'<circle cx="12" cy="8" r="3.6"/><path d="M4.5 20.5c.8-4 3.7-6.2 7.5-6.2s6.7 2.2 7.5 6.2"/>',
        'community'=>'<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.6 3.7 5.6 3.7 9s-1.2 6.4-3.7 9c-2.5-2.6-3.7-5.6-3.7-9S9.5 5.6 12 3Z"/>',
        'menu'=>'<path d="M4 7h16M4 12h16M4 17h16"/>',
        'close'=>'<path d="M6 6l12 12M18 6 6 18"/>',
    ];
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($paths[$name] ?? $paths['cards']) . '</svg>';
}
/**
 * Cabeçalho das páginas.
 *
 * $description alimenta o resumo que aparece nos buscadores e no compartilhamento;
 * $meta aceita 'image' (imagem do compartilhamento) e 'noindex' (fora da busca).
 * Áreas de conta e de administração já saem com noindex por conta da rota.
 */
function pageHeader(string $title, string $description = '', array $meta = []): void
{
    $route = basename($_SERVER['SCRIPT_NAME'] ?? 'index.php');
    $section = !empty($GLOBALS['isHome']) ? 'home' : match ($route) { 'editions.php','edition.php'=>'sets', 'commanders.php'=>'commanders', 'collection.php'=>'collection', 'decks.php','upgrades.php','deck_board.php'=>'decks','status.php','sync_history.php'=>'status','public.php','public_deck.php','public_collection.php','profile.php'=>'community','users.php'=>'users','account.php'=>'account','login.php','register.php'=>'auth',default=>'cards' };
    $user = authUser();
    $version = (string)filemtime(__DIR__ . '/assets/style.css');
    echo '<!doctype html><html lang="' . h(appLocale()) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<meta name="theme-color" content="#171e26"><title>' . te($title) . ' · Deckarium</title>';

    // Resumo, endereço canônico e cartão de compartilhamento.
    $descriptions = [
        'home' => 'Catálogo completo de Magic em português, com edições, comandantes, preços e oficina de decks de Commander.',
        'cards' => 'Pesquise qualquer carta de Magic pelo nome, texto Oracle, tipo, cores, raridade e edição.',
        'sets' => 'Todas as edições de Magic em linha do tempo, com cartas novas, reimpressões e proporção de cores.',
        'commanders' => 'Todos os comandantes do catálogo, com as cartas mais jogadas e as sinergias de cada um.',
        'community' => 'Decks e coleções que outros jogadores tornaram públicos no Deckarium.',
    ];
    $pageDescription = t($description !== '' ? $description : ($descriptions[$section] ?? 'Deckarium: catálogo de Magic, oficina de decks de Commander e controle da sua coleção.'));
    $pageDescription = mb_substr(trim(preg_replace('/\s+/u', ' ', $pageDescription) ?? ''), 0, 300);
    $privateRoutes = ['login.php','register.php','logout.php','account.php','collection.php','decks.php','deck_board.php','upgrades.php','users.php','status.php','sync_history.php'];
    $noindex = $meta['noindex'] ?? in_array($route, $privateRoutes, true);
    $scheme = (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'http') ? 'http' : 'https';
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'deckarium.bernas.shop');
    // No canônico ficam só os parâmetros que identificam a página; filtros e buscas
    // gerariam um endereço diferente a cada combinação, com o mesmo conteúdo.
    $canonicalParams = ['card.php'=>['id'],'edition.php'=>['set','view','page'],'editions.php'=>['page'],'public_deck.php'=>['id'],
        'public_collection.php'=>['u','page'],'profile.php'=>['u'],'public.php'=>['u'],'index.php'=>['view','page'],'commanders.php'=>['page']];
    if (isset($meta['canonical'])) {
        $canonical = (string)$meta['canonical'];
    } else {
        $path = strtok((string)($_SERVER['REQUEST_URI'] ?? '/'), '?') ?: '/';
        $kept = array_intersect_key($_GET, array_flip($canonicalParams[$route] ?? []));
        $canonical = $path . ($kept ? '?' . http_build_query($kept) : '');
    }
    $canonical = $scheme . '://' . $host . $canonical;
    $image = $meta['image'] ?? '/assets/deckarium-logo.png';
    if ($image !== '' && $image[0] === '/') $image = $scheme . '://' . $host . $image;

    echo '<meta name="description" content="' . h($pageDescription) . '">';
    if ($noindex) echo '<meta name="robots" content="noindex,nofollow">';
    else echo '<link rel="canonical" href="' . h($canonical) . '">';
    echo '<meta property="og:site_name" content="Deckarium"><meta property="og:type" content="website">';
    echo '<meta property="og:title" content="' . te($title) . ' · Deckarium">';
    echo '<meta property="og:description" content="' . h($pageDescription) . '">';
    echo '<meta property="og:url" content="' . h($canonical) . '"><meta property="og:image" content="' . h($image) . '">';
    echo '<meta property="og:locale" content="' . (appLocale() === 'en' ? 'en_US' : 'pt_BR') . '"><meta name="twitter:card" content="summary_large_image">';
    echo '<link rel="icon" type="image/png" href="/assets/deckarium-favicon.png"><link rel="apple-touch-icon" href="/assets/deckarium-favicon.png">';
    echo '<link rel="preload" href="/assets/fonts/spectral-bold.ttf" as="font" type="font/ttf" crossorigin>';
    echo '<link rel="stylesheet" href="/assets/style.css?v=' . h($version) . '">';
    if ($section === 'home') echo '<link rel="stylesheet" href="/assets/home.css?v=' . filemtime(__DIR__ . '/assets/home.css') . '">';
    if ($section === 'commanders') echo '<link rel="stylesheet" href="/assets/commanders.css?v=' . filemtime(__DIR__ . '/assets/commanders.css') . '">';
    if ($route === 'editions.php') echo '<link rel="stylesheet" href="/assets/editions.css?v=' . filemtime(__DIR__ . '/assets/editions.css') . '">';
    if ($section==='decks') echo '<link rel="stylesheet" href="/assets/decks.css?v=' . filemtime(__DIR__ . '/assets/decks.css') . '">';
    echo '<script src="/assets/app.js?v=' . h((string)filemtime(__DIR__ . '/assets/app.js')) . '" defer></script></head><body>';
    echo '<a class="skip-link" href="#main">Pular para o conteúdo</a>';
    echo '<header class="sidebar" data-sidebar><div class="sidebar-header"><a class="brand" href="/commanders.php" aria-label="Deckarium — início"><img class="brand-mark" src="/assets/deckarium-logo.png" alt="Deckarium" width="512" height="512"></a><button type="button" class="sidebar-toggle" data-sidebar-toggle aria-expanded="true"><span class="sr-only" data-sidebar-toggle-label>Recolher navegação</span><span class="sidebar-toggle-open">' . uiIcon('menu') . '</span><span class="sidebar-toggle-close">' . uiIcon('close') . '</span></button></div>';
    echo '<nav aria-label="Navegação principal">';
    $links = [['commanders','/commanders.php',t('Comandantes')],['cards','/?catalog=1#catalogo',t('Catálogo')],['sets','/editions.php',t('Edições')],['collection','/collection.php',t('Minha coleção')],['decks','/decks.php',t('Meus decks')],['community','/public.php',t('Comunidade')]];
    if (($user['role'] ?? '') === 'admin') {
        $links[] = ['status','/status.php',t('Status')];
        $links[] = ['users','/users.php',t('Usuários')];
    }
    foreach ($links as [$key,$url,$label]) {
        echo '<a href="' . $url . '"' . ($section === $key ? ' aria-current="page"' : '') . '>' . uiIcon($key) . '<span>' . $label . '</span></a>';
    }
    echo '</nav>';
    if ($user) {
        $initials = mb_strtoupper(implode('', array_map(fn($part) => mb_substr($part, 0, 1), array_slice(preg_split('/\s+/u', trim((string)$user['full_name'])) ?: [], 0, 2))));
        echo '<div class="sidebar-account"><a class="account-chip" href="/account.php"' . ($section === 'account' ? ' aria-current="page"' : '') . '><span class="account-avatar" aria-hidden="true">' . (!empty($user['avatar_file']) ? '<img src="/profile_image.php?u=' . (int)$user['id'] . '&amp;kind=avatar&amp;v=' . h(rawurlencode((string)$user['avatar_file'])) . '" alt="">' : h($initials ?: '?')) . '</span><span class="account-names"><strong>' . h($user['full_name']) . '</strong><small>@' . h($user['username']) . ($user['role'] === 'admin' ? ' · admin' : '') . '</small></span></a>';
        echo '<form method="post" action="/logout.php" class="account-logout">' . authCsrfField() . '<button type="submit">' . te('Sair') . '</button></form></div>';
    } else {
        $next = $section === 'auth' ? '' : '?next=' . rawurlencode((string)($_SERVER['REQUEST_URI'] ?? '/'));
        echo '<div class="sidebar-account is-guest"><span>' . te('Guarde sua coleção e seus decks.') . '</span><div><a class="account-login" href="/login.php' . h($next) . '">' . te('Entrar') . '</a><a class="account-register" href="/register.php">' . te('Criar conta') . '</a></div></div>';
    }
    // Troca de idioma: mantém a página e os filtros, mudando só ?lang=.
    echo '<div class="sidebar-lang" role="group" aria-label="' . te('Idioma do site') . '">';
    foreach (APP_LOCALES as $localeCode => $localeLabel) {
        $current = appLocale() === $localeCode;
        echo '<a href="' . h(appLocaleUrl($localeCode)) . '"' . ($current ? ' aria-current="true"' : '') . ' lang="' . h($localeCode) . '">' . h($localeLabel) . '</a>';
    }
    echo '</div>';
    echo '</header><div class="sidebar-scrim" data-sidebar-scrim hidden></div><div class="app-content"><main id="main" class="wrap">';
}
function pageFooter(): void
{
    echo '</main><footer class="wrap footer"><span>Deckarium</span><span>Dados e imagens do Scryfall. Acervo para consulta pessoal.</span>' . (authIsAdmin() ? '<a href="/status.php">' . te('Status do acervo') . '</a>' : '') . '</footer></div></body></html>';
}
