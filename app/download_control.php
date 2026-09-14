<?php
declare(strict_types=1);

function downloadJson(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function terminateDownloadProcesses(): int
{
    $terminated = 0;
    $currentPid = function_exists('getmypid') ? (int)getmypid() : 0;
    foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $cmdlinePath) {
        $parts = explode('/', $cmdlinePath);
        $pid = (int)($parts[2] ?? 0);
        if ($pid <= 1 || $pid === $currentPid) continue;
        $command = str_replace("\0", ' ', (string)@file_get_contents($cmdlinePath));
        if ($command === '' || !str_contains($command, 'bin/download_images.php')) continue;
        if (function_exists('posix_kill') && @posix_kill($pid, defined('SIGTERM') ? SIGTERM : 15)) {
            $terminated++;
        }
    }
    if ($terminated === 0 && function_exists('proc_open')) {
        $descriptors = [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = @proc_open(['ps', '-eo', 'pid=,args='], $descriptors, $pipes);
        if (is_resource($process)) {
            $listing = (string)@stream_get_contents($pipes[1]);
            foreach ($pipes as $pipe) if (is_resource($pipe)) @fclose($pipe);
            @proc_close($process);
            foreach (preg_split('/\r?\n/', $listing) ?: [] as $line) {
                if (!preg_match('/^\s*(\d+)\s+.*bin\/download_images\.php/', $line, $match)) continue;
                $pid = (int)$match[1];
                if ($pid <= 1 || $pid === $currentPid) continue;
                if (function_exists('posix_kill') && @posix_kill($pid, defined('SIGTERM') ? SIGTERM : 15)) {
                    $terminated++;
                    continue;
                }
                $kill = @proc_open(['/bin/kill', '-TERM', (string)$pid], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'ab'], 2 => ['file', '/dev/null', 'ab']], $killPipes);
                if (is_resource($kill)) {
                    foreach ($killPipes as $pipe) if (is_resource($pipe)) @fclose($pipe);
                    if (@proc_close($kill) === 0) $terminated++;
                }
            }
        }
    }
    if ($terminated === 0 && function_exists('exec')) {
        $exitCode = 1;
        @exec('pkill -TERM -f ' . escapeshellarg('[/]bin/download_images.php'), $output, $exitCode);
        if ($exitCode === 0) $terminated = 1;
    }
    if ($terminated === 0 && function_exists('proc_open')) {
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', '/dev/null', 'ab'],
            2 => ['file', '/dev/null', 'ab'],
        ];
        $process = @proc_open(['pkill', '-TERM', '-f', 'bin/download_images[.]php'], $descriptors, $pipes);
        if (is_resource($process)) {
            foreach ($pipes as $pipe) if (is_resource($pipe)) @fclose($pipe);
            $terminated = @proc_close($process) === 0 ? 1 : 0;
        }
    }
    return $terminated;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    downloadJson(['ok' => false, 'message' => 'Método não permitido.'], 405);
}

$config = require __DIR__ . '/config.php';
$storage = rtrim($config['storage_dir'], '/');
@mkdir($storage, 0775, true);
$action = (string)($_POST['action'] ?? '');
$processMetaPath = $storage . '/download-process.json';

if ($action === 'pause') {
    file_put_contents($storage . '/STOP_DOWNLOAD', (string)time());
    $terminated = false;
    $meta = json_decode((string)@file_get_contents($processMetaPath), true);
    $pid = is_array($meta) ? (int)($meta['pid'] ?? 0) : 0;
    if ($pid > 1 && function_exists('posix_kill')) {
        $terminated = @posix_kill($pid, defined('SIGTERM') ? SIGTERM : 15);
    } elseif ($pid > 1 && function_exists('exec')) {
        $exitCode = 1;
        @exec('kill -TERM ' . $pid, $output, $exitCode);
        $terminated = $exitCode === 0;
    }
    $terminatedCount = terminateDownloadProcesses();
    $terminated = $terminated || $terminatedCount > 0;
    if ($terminated) {
        $progressPath = $storage . '/download-progress.json';
        $progress = json_decode((string)@file_get_contents($progressPath), true);
        if (is_array($progress)) {
            $progress['state'] = 'stopped_by_user';
            $progress['updated_at'] = time();
            @file_put_contents($progressPath . '.tmp', json_encode($progress, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            @rename($progressPath . '.tmp', $progressPath);
        }
    }
    downloadJson([
        'ok' => true,
        'message' => $terminated
            ? 'Download cancelado imediatamente. Os arquivos concluídos foram preservados para a retomada.'
            : 'Cancelamento solicitado. Os arquivos concluídos serão preservados para a retomada.',
        'terminated' => $terminated,
    ]);
}

if ($action !== 'start') {
    downloadJson(['ok' => false, 'message' => 'Ação de download inválida.'], 400);
}

$mode = (string)($_POST['mode'] ?? 'all');
if (!in_array($mode, ['unique', 'all'], true)) {
    $mode = 'all';
}
$concurrency = max(1, min(4, (int)($_POST['concurrency'] ?? 2)));
$previousProgress = json_decode((string)@file_get_contents($storage . '/download-progress.json'), true);

$lockPath = $storage . '/download-normal.lock';
$lock = @fopen($lockPath, 'c');
if (!$lock || !@flock($lock, LOCK_EX | LOCK_NB)) {
    if (is_resource($lock)) @fclose($lock);
    $meta = json_decode((string)@file_get_contents($processMetaPath), true);
    $pid = is_array($meta) ? (int)($meta['pid'] ?? 0) : 0;
    $processAlive = $pid > 1 && function_exists('posix_kill') && @posix_kill($pid, 0);
    if (!$processAlive) {
        @unlink($lockPath); @unlink($processMetaPath);
        $lock = @fopen($lockPath, 'c');
        if (!$lock || !@flock($lock, LOCK_EX | LOCK_NB)) downloadJson(['ok'=>false,'message'=>'A trava do downloader não pôde ser recuperada.'],409);
    } else {
        downloadJson(['ok' => false, 'message' => 'Já existe um download em andamento.'], 409);
    }
}
@flock($lock, LOCK_UN);
@fclose($lock);
@unlink($storage . '/STOP_DOWNLOAD');
@unlink($processMetaPath);

$resume = is_array($previousProgress)
    && in_array((string)($previousProgress['state'] ?? ''), ['running', 'starting', 'stopped_by_user', 'stale', 'disk_full', 'completed_with_errors'], true)
    && (string)($previousProgress['mode'] ?? '') === $mode;

$progress = [
    'state' => 'starting',
    'mode' => $mode,
    'size' => 'normal',
    'downloaded' => 0,
    'failed' => 0,
    'existing' => 0,
    'selected' => 0,
    'processed' => 0,
    'total' => 0,
    'bytes' => 0,
    'resumed' => $resume,
    'started_at' => time(),
    'updated_at' => time(),
];
$progressPath = $storage . '/download-progress.json';
@file_put_contents($progressPath . '.tmp', json_encode($progress, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
@rename($progressPath . '.tmp', $progressPath);

$command = [PHP_BINDIR . '/php', __DIR__ . '/bin/download_images.php', $mode, 'normal', (string)$concurrency];
$logPath = $storage . '/download.log';
$descriptors = [
    0 => ['file', '/dev/null', 'r'],
    1 => ['pipe', 'w'],
    2 => ['file', $logPath, 'ab'],
];
$shell = 'nohup ' . implode(' ', array_map('escapeshellarg', $command)) . ' >> ' . escapeshellarg($logPath) . ' 2>&1 < /dev/null & echo $!';
$process = @proc_open(['/bin/sh','-c',$shell], $descriptors, $pipes, __DIR__);
if (!is_resource($process)) {
    downloadJson(['ok' => false, 'message' => 'Não foi possível iniciar o downloader.'], 500);
}
$pid = (int)trim((string)stream_get_contents($pipes[1]));
foreach ($pipes as $pipe) {
    if (is_resource($pipe)) @fclose($pipe);
}
@proc_close($process);
if ($pid > 1) {
    @file_put_contents($processMetaPath . '.tmp', json_encode(['pid' => $pid, 'mode' => $mode, 'size' => 'normal', 'started_at' => time()], JSON_PRETTY_PRINT));
    @rename($processMetaPath . '.tmp', $processMetaPath);
}

downloadJson([
    'ok' => true,
    'message' => $resume
        ? 'Download retomado. Arquivos já concluídos serão ignorados automaticamente.'
        : 'Download iniciado. O progresso será atualizado automaticamente.',
    'mode' => $mode,
    'resumed' => $resume,
]);
