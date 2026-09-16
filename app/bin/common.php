<?php
declare(strict_types=1);
require dirname(__DIR__) . '/db.php';

function cfg(): array
{
    static $config;
    return $config ??= require dirname(__DIR__) . '/config.php';
}

function httpGet(string $url): string
{
    $c = cfg();
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_HTTPHEADER => [
            'User-Agent: ' . $c['scryfall']['user_agent'],
            'Accept: ' . $c['scryfall']['accept'],
        ],
    ]);
    $body = curl_exec($ch);
    if ($body === false) {
        throw new RuntimeException('Falha HTTP: ' . curl_error($ch));
    }
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($code < 200 || $code >= 300) {
        throw new RuntimeException("HTTP {$code} ao acessar {$url}");
    }
    return $body;
}

function downloadFile(string $url, string $target, ?callable $onProgress = null): void
{
    $c = cfg();
    $fp = fopen($target . '.part', 'wb');
    if (!$fp) {
        throw new RuntimeException('Não foi possível criar ' . $target . '.part');
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE => $fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_NOPROGRESS => $onProgress === null,
        CURLOPT_XFERINFOFUNCTION => static function ($ch, int $downloadTotal, int $downloaded) use ($onProgress): int {
            if ($onProgress) $onProgress($downloaded, $downloadTotal);
            return 0;
        },
        CURLOPT_HTTPHEADER => [
            'User-Agent: ' . $c['scryfall']['user_agent'],
            'Accept: ' . $c['scryfall']['accept'],
        ],
    ]);
    $ok = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    fclose($fp);

    if (!$ok || $code < 200 || $code >= 300) {
        @unlink($target . '.part');
        throw new RuntimeException("Falha ao baixar {$url}: HTTP {$code} {$err}");
    }
    rename($target . '.part', $target);
}

function pickImageUris(array $card): array
{
    $front = $card['image_uris']['normal'] ?? $card['image_uris']['large'] ?? null;
    $back = null;

    if (!$front && !empty($card['card_faces']) && is_array($card['card_faces'])) {
        $front = $card['card_faces'][0]['image_uris']['normal'] ?? $card['card_faces'][0]['image_uris']['large'] ?? null;
        $back = $card['card_faces'][1]['image_uris']['normal'] ?? $card['card_faces'][1]['image_uris']['large'] ?? null;
    }
    return [$front, $back];
}
