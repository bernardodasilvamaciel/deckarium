<?php
declare(strict_types=1);

function uiIcon(string $name): string
{
    $paths = [
        'cards'=>'<rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 4V2M9 8h6M9 12h6M9 16h3"/>',
        'sets'=>'<path d="M12 5C8 2 4 3 2 4v15c3-1 6-1 10 2 4-3 7-3 10-2V4c-2-1-6-2-10 1Zm0 0v16"/>',
        'upgrades'=>'<path d="m4 8 8-5 8 5-8 5-8-5Zm0 5 8 5 8-5M4 18l8 5 8-5"/>',
        'status'=>'<path d="M8 5h12M8 12h12M8 19h12M3 5h1M3 12h1M3 19h1"/>',
    ];
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($paths[$name] ?? $paths['cards']) . '</svg>';
}
function pageHeader(string $title): void
{
    $route = basename($_SERVER['SCRIPT_NAME'] ?? 'index.php');
    $section = match ($route) { 'editions.php','edition.php'=>'sets', 'collection.php'=>'collection', 'decks.php'=>'decks', 'upgrades.php'=>'upgrades','status.php'=>'status',default=>'cards' };
    $version = (string)filemtime(__DIR__ . '/assets/style.css');
    echo '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<meta name="theme-color" content="#171e26"><title>' . h($title) . ' · Deckarium</title>';
    echo '<link rel="preload" href="/assets/fonts/spectral-bold.ttf" as="font" type="font/ttf" crossorigin>';
    echo '<link rel="stylesheet" href="/assets/style.css?v=' . h($version) . '">';
    if ($section === 'decks') echo '<link rel="stylesheet" href="/assets/decks.css?v=' . filemtime(__DIR__ . '/assets/decks.css') . '">';
    echo '<script src="/assets/app.js?v=' . h((string)filemtime(__DIR__ . '/assets/app.js')) . '" defer></script></head><body>';
    echo '<a class="skip-link" href="#main">Pular para o conteúdo</a>';
    echo '<header class="sidebar"><a class="brand" href="/"><img class="brand-mark" src="/assets/mtg-mark.svg" alt="" width="28" height="28"><span>Deckarium</span></a>';
    echo '<nav aria-label="Navegação principal">';
    foreach ([['cards','/','Catálogo'],['sets','/editions.php','Edições'],['collection','/collection.php','Minha coleção'],['decks','/decks.php','Meus decks'],['upgrades','/upgrades.php','Upgrades'],['status','/status.php','Status']] as [$key,$url,$label]) {
        echo '<a href="' . $url . '"' . ($section === $key ? ' aria-current="page"' : '') . '>' . uiIcon($key) . '<span>' . $label . '</span></a>';
    }
    echo '</nav><div class="sidebar-note"><span>Seu espaço para Magic.</span><small>Catálogo pessoal · Scryfall</small></div></header><div class="app-content"><main id="main" class="wrap">';
}
function pageFooter(): void
{
    echo '</main><footer class="wrap footer"><span>Deckarium</span><span>Dados e imagens do Scryfall. Acervo para consulta pessoal.</span><a href="/status.php">Status do acervo</a></footer></div></body></html>';
}

