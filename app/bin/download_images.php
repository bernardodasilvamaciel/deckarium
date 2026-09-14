<?php
declare(strict_types=1);
require __DIR__ . '/common.php';

/*
 * Downloader paralelo, deduplicado e com uso constante de memoria.
 *
 * Uso:
 *   php bin/download_images.php unique small 8
 *   php bin/download_images.php upgrades small 8
 *   php bin/download_images.php unique normal 8 5000
 *   php bin/download_images.php all normal 8
 *
 * Modos:
 *   unique   = 1 impressao representativa por Oracle ID (recomendado)
 *   upgrades = somente cartas usadas na pagina de upgrades
 *   all      = TODAS as impressoes
 *
 * Diferencas desta versao:
 *   - nao usa fetchAll();
 *   - nao carrega a coluna raw inteira no PHP;
 *   - extrai as URLs small/normal diretamente no PostgreSQL;
 *   - grava o corpo HTTP direto em .part, sem CURLOPT_RETURNTRANSFER;
 *   - mantem apenas "concurrency" downloads na memoria ao mesmo tempo.
 */

$mode = $argv[1] ?? 'unique';
$size = $argv[2] ?? 'small';
$concurrency = isset($argv[3]) ? max(1, min(16, (int)$argv[3])) : 8;
$limit = isset($argv[4]) ? max(1, (int)$argv[4]) : null;

if (!in_array($mode, ['unique', 'upgrades', 'all'], true)) {
    fwrite(STDERR, "Modo invalido. Use unique, upgrades ou all.\n");
    exit(1);
}
if (!in_array($size, ['small', 'normal'], true)) {
    fwrite(STDERR, "Tamanho invalido. Use small ou normal.\n");
    exit(1);
}

$pdo = db();
$config = cfg();
$storage = $config['storage_dir'];
$subdir = $size === 'small' ? 'images/small' : 'images/normal';
$imgDir = $storage . '/' . $subdir;
@mkdir($imgDir, 0775, true);
$lock = fopen($storage . '/download-' . $size . '.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Ja existe um download deste tamanho em andamento.\n");
    exit(3);
}
if (disk_free_space($storage) < 1024 * 1024 * 1024) {
    fwrite(STDERR, "Menos de 1 GB livre. Libere espaco e retome o download.\n");
    exit(4);
}
$processMetaPath = $storage . '/download-process.json';
$previousProgress = json_decode((string)@file_get_contents($storage . '/download-progress.json'), true);
$resumed = is_array($previousProgress)
    && (bool)($previousProgress['resumed'] ?? false)
    || (is_array($previousProgress)
        && in_array((string)($previousProgress['state'] ?? ''), ['running', 'starting', 'stopped_by_user', 'stale', 'disk_full', 'completed_with_errors'], true)
        && (string)($previousProgress['mode'] ?? '') === $mode);
@file_put_contents($processMetaPath . '.tmp', json_encode(['pid' => getmypid(), 'mode' => $mode, 'size' => $size, 'started_at' => time()], JSON_PRETTY_PRINT));
@rename($processMetaPath . '.tmp', $processMetaPath);
$clearProcessMeta = static function () use ($processMetaPath): void {
    $meta = json_decode((string)@file_get_contents($processMetaPath), true);
    if (is_array($meta) && (int)($meta['pid'] ?? 0) === getmypid()) {
        @unlink($processMetaPath);
    }
};

// O tamanho vem de uma whitelist acima; por isso pode ser interpolado na expressao JSON.
$sizeKey = $size;
$frontExpr = "COALESCE(c.raw->'image_uris'->>'{$sizeKey}', c.raw->'card_faces'->0->'image_uris'->>'{$sizeKey}', c.image_uri)";
$backExpr  = "COALESCE(c.raw->'card_faces'->1->'image_uris'->>'{$sizeKey}', c.image_uri_back)";

if ($mode === 'upgrades') {
    $sql = <<<SQL
WITH wanted AS (
    SELECT add_name AS wanted_name FROM upgrade_items
    UNION
    SELECT remove_name AS wanted_name FROM upgrade_items
), matched AS (
    SELECT DISTINCT ON (lower(w.wanted_name))
        c.id,c.oracle_id,c.name,c.lang,c.released_at,
        {$frontExpr} AS front_url,
        {$backExpr} AS back_url,
        c.local_image,c.local_image_back
    FROM wanted w
    JOIN cards c
      ON lower(c.name) = lower(w.wanted_name)
      OR lower(split_part(c.name, ' // ', 1)) = lower(w.wanted_name)
    ORDER BY lower(w.wanted_name),
             (c.lang = 'en') DESC,
             (COALESCE(c.raw->>'digital','false') = 'false') DESC,
             ({$frontExpr} IS NOT NULL) DESC,
             c.released_at DESC NULLS LAST,
             c.id
)
SELECT * FROM matched ORDER BY name
SQL;
} elseif ($mode === 'unique') {
    $sql = <<<SQL
SELECT DISTINCT ON (COALESCE(c.oracle_id, c.id))
       c.id,c.oracle_id,c.name,c.lang,c.released_at,
       {$frontExpr} AS front_url,
       {$backExpr} AS back_url,
       c.local_image,c.local_image_back
FROM cards c
WHERE {$frontExpr} IS NOT NULL OR {$backExpr} IS NOT NULL
ORDER BY COALESCE(c.oracle_id, c.id),
         (c.lang = 'en') DESC,
         (COALESCE(c.raw->>'digital','false') = 'false') DESC,
         ({$frontExpr} IS NOT NULL) DESC,
         c.released_at DESC NULLS LAST,
         c.id
SQL;
} else {
    $sql = <<<SQL
SELECT c.id,c.oracle_id,c.name,c.lang,c.released_at,
       {$frontExpr} AS front_url,
       {$backExpr} AS back_url,
       c.local_image,c.local_image_back
FROM cards c
WHERE {$frontExpr} IS NOT NULL OR {$backExpr} IS NOT NULL
ORDER BY c.released_at DESC NULLS LAST, c.name, c.id
SQL;
}

if ($limit) {
    $sql .= "\nLIMIT " . (int)$limit;
}

$totalTasks = 0;
try {
    $totalTasks = (int)$pdo->query("SELECT COALESCE(SUM((front_url IS NOT NULL)::int + (back_url IS NOT NULL)::int), 0) FROM ({$sql}) image_rows")->fetchColumn();
} catch (Throwable $e) {
    // O progresso continua útil mesmo se o banco não conseguir estimar o total.
}

$stmt = $pdo->query($sql);
if (!$stmt) {
    $clearProcessMeta();
    fwrite(STDERR, "Falha ao consultar cartas.\n");
    exit(2);
}

$updateFront = $pdo->prepare('UPDATE cards SET local_image = :path WHERE id = :id');
$updateBack = $pdo->prepare('UPDATE cards SET local_image_back = :path WHERE id = :id');

$already = 0;
$selectedCards = 0;
$queued = 0;
$done = 0;
$failed = 0;
$bytes = 0;
$startedAt = microtime(true);
$progressAt = 0;
$writeProgress = function (string $state) use (&$progressAt, &$done, &$failed, &$already, &$bytes, &$selectedCards, $startedAt, $storage, $mode, $size, $totalTasks, $resumed): void {
    if ($state === 'running' && time() - $progressAt < 2) return;
    $progressAt = time();
    $path = $storage . '/download-progress.json';
    $processed = $done + $failed + $already;
    file_put_contents($path . '.tmp', json_encode(['state' => $state, 'mode' => $mode, 'size' => $size, 'downloaded' => $done, 'failed' => $failed, 'existing' => $already, 'selected' => $selectedCards, 'processed' => $processed, 'total' => $totalTasks, 'bytes' => $bytes, 'resumed' => $resumed, 'started_at' => (int)$startedAt, 'updated_at' => time()], JSON_PRETTY_PRINT));
    rename($path . '.tmp', $path);
};
$writeProgress('starting');

/**
 * Entrega a proxima tarefa sem montar uma lista gigante em memoria.
 */
$pendingFaces = [];
$nextTask = function () use ($stmt, &$pendingFaces, &$already, &$selectedCards, &$queued, $imgDir, $subdir, $storage, $writeProgress): ?array {
    while (true) {
        if (is_file($storage.'/STOP_DOWNLOAD')) return null;
        $writeProgress('running');
        if ($pendingFaces) {
            $task = array_shift($pendingFaces);
            $target = $task['target'];
            if (is_file($target) && filesize($target) > 0) {
                $already++;
                continue;
            }
            $queued++;
            return $task;
        }

        $card = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($card === false) {
            return null;
        }
        $selectedCards++;

        foreach ([
            'front' => $card['front_url'] ?? null,
            'back' => $card['back_url'] ?? null,
        ] as $face => $url) {
            if (!$url) {
                continue;
            }
            $target = $imgDir . '/' . $card['id'] . '-' . $face . '.jpg';
            $pendingFaces[] = [
                'id' => (string)$card['id'],
                'name' => (string)$card['name'],
                'face' => $face,
                'url' => (string)$url,
                'target' => $target,
                'rel' => $subdir . '/' . $card['id'] . '-' . $face . '.jpg',
            ];
        }
    }
};

$multi = curl_multi_init();
$active = [];
$sourceExhausted = false;
$retryQueue=[];
$failureStreak=0;
$lastError='';
$stopRequested = false;
if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, static function () use (&$stopRequested): void { $stopRequested = true; });
    pcntl_signal(SIGINT, static function () use (&$stopRequested): void { $stopRequested = true; });
}
$cancelAndExit = function () use (&$active, $multi, $stmt, $storage, $writeProgress, $clearProcessMeta): never {
    foreach ($active as $key => $entry) {
        $fp = $entry['fp'] ?? null;
        if (is_resource($fp)) {
            @fflush($fp);
            @fclose($fp);
        }
        $handle = $entry['handle'] ?? null;
        if ($handle) {
            @curl_multi_remove_handle($multi, $handle);
            @curl_close($handle);
        }
        @unlink((string)($entry['tmp'] ?? ''));
        unset($active[$key]);
    }
    @curl_multi_close($multi);
    @$stmt->closeCursor();
    @unlink($storage . '/STOP_DOWNLOAD');
    $clearProcessMeta();
    $writeProgress('stopped_by_user');
    fwrite(STDERR, "Download cancelado pelo usuario.\n");
    exit(0);
};

function addDownloadHandle($multi, array $task, array &$active, array $config): bool
{
    @mkdir(dirname($task['target']), 0775, true);
    $tmp = $task['target'] . '.part-' . bin2hex(random_bytes(4));
    $fp = fopen($tmp, 'wb');
    if (!$fp) {
        return false;
    }

    $ch = curl_init($task['url']);
    if ($ch === false) {
        fclose($fp);
        @unlink($tmp);
        return false;
    }

    curl_setopt_array($ch, [
        CURLOPT_FILE => $fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_LOW_SPEED_LIMIT => 512,
        CURLOPT_LOW_SPEED_TIME => 15,
        CURLOPT_ENCODING => '',
        CURLOPT_HTTPHEADER => [
            'User-Agent: ' . $config['scryfall']['user_agent'],
            'Accept: image/avif,image/webp,image/apng,image/*,*/*;q=0.8',
        ],
    ]);

    curl_multi_add_handle($multi, $ch);
    $active[spl_object_id($ch)] = [
        'handle' => $ch,
        'task' => $task,
        'fp' => $fp,
        'tmp' => $tmp,
    ];
    return true;
}

echo "Modo: {$mode} | tamanho: {$size} | concorrencia: {$concurrency}\n";
echo "Leitura em streaming: ativa (sem fetchAll e sem carregar JSON bruto).\n\n";

while (!$sourceExhausted || $active || $retryQueue) {
    if ($stopRequested || is_file($storage . '/STOP_DOWNLOAD')) {
        $cancelAndExit();
    }
    $writeProgress('running');
    if (disk_free_space($storage) < 1024 * 1024 * 1024) {
        $writeProgress('disk_full');
        $clearProcessMeta();
        fwrite(STDERR, "Download interrompido: menos de 1 GB livre. Retome apos liberar espaco.\n");
        exit(4);
    }
    while (count($active) < $concurrency) {
        if ($retryQueue && $retryQueue[0]['retry_at'] <= microtime(true)) $task=array_shift($retryQueue);
        elseif (!$sourceExhausted && !$retryQueue) $task = $nextTask();
        else break;
        if ($task === null) {
            $sourceExhausted = true;
            break;
        }

        if (!addDownloadHandle($multi, $task, $active, $config)) {
            $failed++;
            echo sprintf("[%d] ERRO inicializacao %s\n", $done + $failed, $task['name']);
        }
    }

    if (!$active) {
        usleep(100000);
        continue;
    }

    do {
        $status = curl_multi_exec($multi, $running);
    } while ($status === CURLM_CALL_MULTI_PERFORM);

    if ($stopRequested || is_file($storage . '/STOP_DOWNLOAD')) {
        $cancelAndExit();
    }

    while ($info = curl_multi_info_read($multi)) {
        $ch = $info['handle'];
        $key = spl_object_id($ch);
        $entry = $active[$key] ?? null;

        if (!$entry) {
            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);
            continue;
        }

        $task = $entry['task'];
        $fp = $entry['fp'];
        $tmp = $entry['tmp'];
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlOk = $info['result'] === CURLE_OK && $code >= 200 && $code < 300;

        fflush($fp);
        fclose($fp);

        $fileOk = $curlOk && is_file($tmp) && filesize($tmp) > 0 && @getimagesize($tmp) !== false;
        if ($fileOk && @rename($tmp, $task['target'])) {
            $failureStreak=0;
            $done++;
            $bytes += (int)filesize($task['target']);

            if ($size === 'normal') {
                if ($task['face'] === 'front') {
                    $updateFront->execute([':path' => $task['rel'], ':id' => $task['id']]);
                } else {
                    $updateBack->execute([':path' => $task['rel'], ':id' => $task['id']]);
                }
            }

            $label = $task['face'] === 'back' ? ' (verso)' : '';
            $elapsed = max(0.001, microtime(true) - $startedAt);
            $rate = ($bytes / 1024 / 1024) / $elapsed;
            echo sprintf("[%d] baixada  %s%s | %.2f MB/s\n", $done + $failed, $task['name'], $label, $rate);
        } else {
            $err = curl_error($ch);
            @unlink($tmp);
            $lastError="HTTP {$code}: ".$task['name'].($err!==''?' — '.$err:'');
            // CDN 403s are often temporary when a large batch is being throttled.
            $retryable=$code===0 || $code===403 || $code===429 || $code>=500;
            $maxAttempts=$code===403 ? 1 : 2;
            if($retryable && ($task['attempt']??0)<$maxAttempts) {
                $task['attempt']=($task['attempt']??0)+1;
                $task['retry_at']=microtime(true)+($code===403?12:($code===429?30:pow(2,$task['attempt'])));
                $retryQueue[]=$task;
            } else { $failed++;$failureStreak++; }
            echo sprintf("[%d] ERRO HTTP %d %s %s\n", $done + $failed, $code, $task['name'], $err);
        }

        curl_multi_remove_handle($multi, $ch);
        curl_close($ch);
        unset($active[$key]);
        if($failureStreak>=5) {
            foreach($active as $remaining){curl_multi_remove_handle($multi,$remaining['handle']);fclose($remaining['fp']);@unlink($remaining['tmp']);}
            $writeProgress('network_error');
            $path=$storage.'/download-progress.json';
            $data=json_decode((string)file_get_contents($path),true);
            $data['last_error']='Download interrompido após 5 falhas consecutivas. Última falha: '.$lastError.'. Retome mais tarde; arquivos concluídos serão preservados.';
            file_put_contents($path.'.tmp',json_encode($data));rename($path.'.tmp',$path);
            $clearProcessMeta();exit(2);
        }
    }

if ($running > 0) {
        $selected = curl_multi_select($multi, 1.0);
        if ($selected === -1) {
            usleep(10000);
        }
    }
}

curl_multi_close($multi);
$stmt->closeCursor();
$clearProcessMeta();

$elapsed = max(0.001, microtime(true) - $startedAt);
$mb = $bytes / 1024 / 1024;
$rate = $mb / $elapsed;

echo "\nConcluido.\n";
echo "Cartas selecionadas: {$selectedCards}\n";
echo "Downloads considerados: {$queued}\n";
echo "Novas: {$done} | Falhas: {$failed} | Ja locais: {$already}\n";
echo sprintf("Transferido: %.2f MB | Tempo: %.1f min | Media: %.2f MB/s\n", $mb, $elapsed / 60, $rate);
$writeProgress($failed ? 'completed_with_errors' : 'completed');
exit($failed ? 2 : 0);
