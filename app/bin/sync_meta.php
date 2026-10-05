<?php
declare(strict_types=1);
/**
 * Busca as listas recentes do MTGO de um ou mais formatos e recalcula os arquétipos (deck_meta.php).
 *
 *   php bin/sync_meta.php modern pauper      (sem argumentos: todos os formatos com listas no MTGO)
 *
 * Disparado em segundo plano pela página do deck quando o meta está velho; um lock por formato evita buscas duplicadas.
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

$formats = array_slice($argv, 1);
if (!$formats) $formats = array_keys(array_filter(deckFormats(), fn($format) => ($format['meta'] ?? null) !== null));
$stamp = static fn(): string => date('d/m/Y H:i:s');
foreach ($formats as $formatKey) {
    $lock = @fopen(metaLockPath($formatKey), 'c');
    if (!$lock || !@flock($lock, LOCK_EX | LOCK_NB)) { echo "[{$stamp()}] {$formatKey}: outra busca em andamento.\n"; continue; }
    try {
        echo "[{$stamp()}] {$formatKey}: buscando listas do MTGO…\n";
        $result = metaSync($formatKey, 16, static function (string $text) use ($formatKey, $stamp): void { echo "[{$stamp()}] {$formatKey}: {$text}\n"; });
        printf("[%s] %s: %d evento(s) e %d deck(s) novos; %d decks no total.\n", $stamp(), $formatKey, $result['events'], $result['decks'], $result['total_decks']);
    } catch (Throwable $e) {
        echo "[{$stamp()}] {$formatKey}: falhou — {$e->getMessage()}\n";
        try { deckQuery('UPDATE meta_formats SET message=? WHERE format=?', ['Falhou: ' . mb_substr($e->getMessage(), 0, 200), $formatKey]); } catch (Throwable) {}
    } finally {
        @flock($lock, LOCK_UN);
        fclose($lock);
    }
}
