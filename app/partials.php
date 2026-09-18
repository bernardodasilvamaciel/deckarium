<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';

function uiIcon(string $name): string
{
    $paths = [
        'home'=>'<path d="m3 10 9-7 9 7v11h-6v-7H9v7H3Z"/>',
        'cards'=>'<rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 4V2M9 8h6M9 12h6M9 16h3"/>',
        'sets'=>'<path d="M12 5C8 2 4 3 2 4v15c3-1 6-1 10 2 4-3 7-3 10-2V4c-2-1-6-2-10 1Zm0 0v16"/>',
        'commanders'=>'<path d="M6 18h12M8 18v-4h8v4M7 5l2 5 3-3 3 3 2-5M9 5h6"/>',
        'status'=>'<path d="M8 5h12M8 12h12M8 19h12M3 5h1M3 12h1M3 19h1"/>',
        'users'=>'<circle cx="9" cy="8" r="3.2"/><path d="M3 20c.6-3.4 3-5.2 6-5.2s5.4 1.8 6 5.2M16 5.2a3 3 0 0 1 0 5.6M18 14.8c1.6.6 2.7 2.3 3 5.2"/>',
        'account'=>'<circle cx="12" cy="8" r="3.6"/><path d="M4.5 20.5c.8-4 3.7-6.2 7.5-6.2s6.7 2.2 7.5 6.2"/>',
        'community'=>'<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.6 3.7 5.6 3.7 9s-1.2 6.4-3.7 9c-2.5-2.6-3.7-5.6-3.7-9S9.5 5.6 12 3Z"/>',
        'menu'=>'<path d="M4 7h16M4 12h16M4 17h16"/>',
        'close'=>'<path d="M6 6l12 12M18 6 6 18"/>',
    ];
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($paths[$name] ?? $paths['cards']) . '</svg>';
}
function pageHeader(string $title): void
{
    $route = basename($_SERVER['SCRIPT_NAME'] ?? 'index.php');
    $section = !empty($GLOBALS['isHome']) ? 'home' : match ($route) { 'editions.php','edition.php'=>'sets', 'commanders.php'=>'commanders', 'collection.php'=>'collection', 'decks.php','upgrades.php','deck_board.php'=>'decks','status.php','sync_history.php'=>'status','public.php','public_deck.php','public_collection.php'=>'community','users.php'=>'users','account.php'=>'account','login.php','register.php'=>'auth',default=>'cards' };
    $user = authUser();
    $version = (string)filemtime(__DIR__ . '/assets/style.css');
    echo '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<meta name="theme-color" content="#171e26"><title>' . h($title) . ' · Deckarium</title>';
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
    $links = [['commanders','/commanders.php','Comandantes'],['cards','/?catalog=1#catalogo','Catálogo'],['sets','/editions.php','Edições'],['collection','/collection.php','Minha coleção'],['decks','/decks.php','Meus decks'],['community','/public.php','Comunidade']];
    if (($user['role'] ?? '') === 'admin') {
        $links[] = ['status','/status.php','Status'];
        $links[] = ['users','/users.php','Usuários'];
    }
    foreach ($links as [$key,$url,$label]) {
        echo '<a href="' . $url . '"' . ($section === $key ? ' aria-current="page"' : '') . '>' . uiIcon($key) . '<span>' . $label . '</span></a>';
    }
    echo '</nav>';
    if ($user) {
        $initials = mb_strtoupper(implode('', array_map(fn($part) => mb_substr($part, 0, 1), array_slice(preg_split('/\s+/u', trim((string)$user['full_name'])) ?: [], 0, 2))));
        echo '<div class="sidebar-account"><a class="account-chip" href="/account.php"' . ($section === 'account' ? ' aria-current="page"' : '') . '><span class="account-avatar" aria-hidden="true">' . h($initials ?: '?') . '</span><span class="account-names"><strong>' . h($user['full_name']) . '</strong><small>@' . h($user['username']) . ($user['role'] === 'admin' ? ' · admin' : '') . '</small></span></a>';
        echo '<form method="post" action="/logout.php" class="account-logout">' . authCsrfField() . '<button type="submit">Sair</button></form></div>';
    } else {
        $next = $section === 'auth' ? '' : '?next=' . rawurlencode((string)($_SERVER['REQUEST_URI'] ?? '/'));
        echo '<div class="sidebar-account is-guest"><span>Guarde sua coleção e seus decks.</span><div><a class="account-login" href="/login.php' . h($next) . '">Entrar</a><a class="account-register" href="/register.php">Criar conta</a></div></div>';
    }
    echo '</header><div class="sidebar-scrim" data-sidebar-scrim hidden></div><div class="app-content"><main id="main" class="wrap">';
}
function pageFooter(): void
{
    echo '</main><footer class="wrap footer"><span>Deckarium</span><span>Dados e imagens do Scryfall. Acervo para consulta pessoal.</span>' . (authIsAdmin() ? '<a href="/status.php">Status do acervo</a>' : '') . '</footer></div></body></html>';
}
