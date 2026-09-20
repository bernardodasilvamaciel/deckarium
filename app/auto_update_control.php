<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require __DIR__ . '/auto_update.php';
require __DIR__ . '/auth.php';
authRequireAdmin();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

function autoUpdateJson(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    autoUpdateJson(['ok' => false, 'message' => 'Método não permitido.'], 405);
}

$config = require __DIR__ . '/config.php';
$storage = rtrim((string)$config['storage_dir'], '/');
@mkdir($storage, 0775, true);

if (autoUpdateLocked($storage)) {
    autoUpdateJson(['ok' => false, 'message' => 'A rotina automática já está em andamento.'], 409);
}

// Mesma rotina do cron, só que disparada pela página e registrada como execução manual.
$logPath = $storage . '/auto-update.log';
$command = [PHP_BINDIR . '/php', __DIR__ . '/bin/auto_update.php', '--source=manual'];
$shell = 'nohup ' . implode(' ', array_map('escapeshellarg', $command)) . ' >> ' . escapeshellarg($logPath) . ' 2>&1 < /dev/null & echo $!';
$process = @proc_open(['/bin/sh', '-c', $shell], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $logPath, 'ab']], $pipes, __DIR__ . '/bin');
if (!is_resource($process)) {
    autoUpdateJson(['ok' => false, 'message' => 'Não foi possível iniciar a rotina automática.'], 500);
}
$pid = (int)trim((string)stream_get_contents($pipes[1]));
foreach ($pipes as $pipe) if (is_resource($pipe)) @fclose($pipe);
@proc_close($process);

autoUpdateJson([
    'ok' => true,
    'pid' => $pid,
    'message' => 'Rotina iniciada. Se o Scryfall tiver dados novos, o catálogo e as imagens serão baixados em seguida — pode sair desta página.',
]);
