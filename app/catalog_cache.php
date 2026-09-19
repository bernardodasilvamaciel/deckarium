<?php
declare(strict_types=1);

/** Short-lived, local query cache. A completed Scryfall sync changes every key. */
function catalogCached(string $key, callable $query, int $ttl = 300): array
{
    static $revision;
    $revision ??= (string)db()->query('SELECT COALESCE(max(imported_at)::text, \'empty\') FROM sync_status')->fetchColumn();
    // Um arquivo por usuário do sistema: em /tmp (sticky) só o dono pode substituir o arquivo, e comandos rodados
    // como root (docker compose exec) não devem travar o cache do Apache (www-data).
    $owner = function_exists('posix_geteuid') ? (string)posix_geteuid() : get_current_user();
    $path = sys_get_temp_dir() . '/mtg-catalog-' . hash('sha256', 'v2' . $revision . $key) . '-' . $owner . '.json';
    if (is_file($path) && filemtime($path) > time() - $ttl) {
        $value = json_decode((string)file_get_contents($path), true);
        if (is_array($value)) return $value;
    }
    $value = $query();
    $temp = tempnam(sys_get_temp_dir(), 'mtg-query-');
    if ($temp !== false) {
        file_put_contents($temp, json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE), LOCK_EX);
        chmod($temp, 0644);
        // Sem permissão para trocar o arquivo, o resultado continua valendo nesta requisição; só não fica em cache.
        if (!@rename($temp, $path)) @unlink($temp);
    }
    return $value;
}
