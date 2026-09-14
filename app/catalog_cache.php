<?php
declare(strict_types=1);

/** Short-lived, local query cache. A completed Scryfall sync changes every key. */
function catalogCached(string $key, callable $query, int $ttl = 300): array
{
    static $revision;
    $revision ??= (string)db()->query('SELECT COALESCE(max(imported_at)::text, \'empty\') FROM sync_status')->fetchColumn();
    $path = sys_get_temp_dir() . '/mtg-catalog-' . hash('sha256', 'v2' . $revision . $key) . '.json';
    if (is_file($path) && filemtime($path) > time() - $ttl) {
        $value = json_decode((string)file_get_contents($path), true);
        if (is_array($value)) return $value;
    }
    $value = $query();
    $temp = tempnam(sys_get_temp_dir(), 'mtg-query-');
    if ($temp !== false) {
        file_put_contents($temp, json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE), LOCK_EX);
        chmod($temp, 0644);
        rename($temp, $path);
    }
    return $value;
}
