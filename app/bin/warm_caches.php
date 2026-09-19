<?php
declare(strict_types=1);
/**
 * Aquece os caches pesados do catálogo depois de uma sincronização, para que a primeira pessoa a abrir
 * Edições, Meus decks ou o Catálogo não espere por eles (índice de funções ~5 s, linha do tempo ~4 s…).
 * O cache é por usuário do sistema (catalog_cache.php): rodando como root, o script passa a www-data.
 *
 * Uso: php bin/warm_caches.php   (chamado no fim de sync_scryfall.php)
 */
if (function_exists('posix_geteuid') && posix_geteuid() === 0 && ($web = posix_getpwnam('www-data'))) {
    posix_setgid((int)$web['gid']);
    posix_setuid((int)$web['uid']);
}
chdir(dirname(__DIR__));
require dirname(__DIR__) . '/db.php';
require dirname(__DIR__) . '/functions.php';
require dirname(__DIR__) . '/catalog_cache.php';
require dirname(__DIR__) . '/deck_library.php';

$steps = [
    'Índice de funções (O que falta, Explorar)' => static fn() => deckNeedRoleIndex(),
    'Léxico do Índice de Encaixe' => static fn() => deckScoreLexicon(),
    'Edições nos filtros' => static fn() => catalogCached('filter-sets', fn() => db()->query('SELECT set_code,MAX(set_name) set_name FROM cards GROUP BY set_code ORDER BY MAX(set_name)')->fetchAll()),
    'Edições no Explorar' => static fn() => catalogCached('deck-filter-sets-v1', fn() => deckQuery("SELECT set_code,MAX(set_name) set_name FROM cards WHERE set_code IS NOT NULL AND set_code<>'' GROUP BY set_code ORDER BY MAX(set_name),set_code")->fetchAll()),
    // A linha do tempo monta os próprios caches ao ser renderizada; roda num processo à parte e a saída é descartada.
    'Linha do tempo das edições' => static function (): void {
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/editions.php') . ' > /dev/null 2>&1', $output, $code);
        if ($code !== 0) throw new RuntimeException('editions.php terminou com código ' . $code);
    },
];
foreach ($steps as $label => $step) {
    $started = microtime(true);
    try {
        $step();
        printf("Cache aquecido: %s (%.1f s)\n", $label, microtime(true) - $started);
    } catch (Throwable $e) {
        printf("Cache não aquecido: %s — %s\n", $label, $e->getMessage());
    }
}
