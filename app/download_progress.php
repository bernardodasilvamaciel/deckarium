<?php
declare(strict_types=1);
require __DIR__ . '/auth.php';
authRequireAdmin();

$config = require __DIR__ . '/config.php';
$storage = rtrim($config['storage_dir'], '/');
$progressPath = $storage . '/download-progress.json';
$progress = [];

if (is_file($progressPath)) {
    $decoded = json_decode((string)@file_get_contents($progressPath), true);
    $progress = is_array($decoded) ? $decoded : [];
}

require __DIR__ . '/download_state.php';
$state = downloadState($storage,$progress);
$pauseRequested = is_file($storage . '/STOP_DOWNLOAD');
$updatedAt = (int)($progress['updated_at'] ?? 0);
$stale = in_array($state, ['starting', 'running', 'stopping'], true)
    && $updatedAt > 0
    && time() - $updatedAt > 300;

if ($pauseRequested && $state === '') {
    $state = 'stopped_by_user';
}

$total = max(0, (int)($progress['total'] ?? 0));
$processed = max(0, (int)($progress['processed'] ?? ((int)($progress['downloaded'] ?? 0) + (int)($progress['existing'] ?? 0) + (int)($progress['failed'] ?? 0))));
$percent = $total > 0 ? min(100, (int)round(($processed / $total) * 100)) : null;
$labels = [
    '' => 'Nenhum download registrado',
    'starting' => 'Preparando download',
    'running' => 'Download em andamento',
    'completed' => 'Download concluído',
    'completed_with_errors' => 'Concluído com imagens pendentes',
    'disk_full' => 'Pausado: espaço insuficiente',
    'stopped_by_user' => 'Download pausado pelo usuário',
    'stale' => 'Progresso sem atualização recente',
    'interrupted'=>'Download interrompido. Retome para continuar',
    'network_error'=>'Pausado após falhas de conexão',
    'stopping'=>'Pausando download',
];

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
echo json_encode([
    'ok' => true,
    'last_error' => (string)($progress['last_error'] ?? ''),
    'state' => $state,
    'label' => $labels[$state] ?? 'Status desconhecido',
    'active' => in_array($state, ['starting', 'running', 'stopping'], true),
    'stale' => $stale,
    'mode' => (string)($progress['mode'] ?? ''),
    'size' => (string)($progress['size'] ?? 'normal'),
    'downloaded' => (int)($progress['downloaded'] ?? 0),
    'existing' => (int)($progress['existing'] ?? 0),
    'failed' => (int)($progress['failed'] ?? 0),
    'bytes' => (int)($progress['bytes'] ?? 0),
    'processed' => $processed,
    'total' => $total,
    'percent' => $percent,
    'updated_at' => $updatedAt,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
