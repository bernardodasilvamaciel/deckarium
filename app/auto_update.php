<?php
declare(strict_types=1);

/**
 * Rotina automática do acervo.
 *
 * Duas vezes por dia o cron do container chama bin/auto_update.php: ele consulta o
 * Scryfall e, só quando há publicação mais nova que a importação local, importa o
 * catálogo e em seguida baixa as imagens. Cada execução vira uma linha de
 * auto_update_runs — o histórico que aparece na página Status.
 */

const AUTO_UPDATE_DEFAULT_TIMES = '06:10,18:10';

function autoUpdateSchema(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS auto_update_runs (
            id bigserial PRIMARY KEY,
            trigger_source text NOT NULL DEFAULT 'cron',
            state text NOT NULL DEFAULT 'checking',
            started_at timestamptz NOT NULL DEFAULT now(),
            finished_at timestamptz NULL,
            bulk_type text NOT NULL DEFAULT '',
            remote_updated_at timestamptz NULL,
            had_update boolean NOT NULL DEFAULT false,
            sync_run_id bigint NULL,
            cards_imported integer NOT NULL DEFAULT 0,
            cards_added integer NOT NULL DEFAULT 0,
            images_downloaded integer NOT NULL DEFAULT 0,
            images_failed integer NOT NULL DEFAULT 0,
            images_bytes bigint NOT NULL DEFAULT 0,
            error text NOT NULL DEFAULT ''
        );
        CREATE INDEX IF NOT EXISTS auto_update_runs_started_idx ON auto_update_runs (started_at DESC);
    SQL);
}

/** Horários, escopo das imagens e liga/desliga vêm do ambiente — os mesmos valores que o entrypoint usa para montar o cron. */
function autoUpdateConfig(): array
{
    $mode = (string)(getenv('AUTO_UPDATE_IMAGE_MODE') ?: 'all');
    return [
        'enabled' => (string)(getenv('AUTO_UPDATE_ENABLED') ?: '1') !== '0',
        'times' => autoUpdateTimes((string)(getenv('AUTO_UPDATE_TIMES') ?: AUTO_UPDATE_DEFAULT_TIMES)),
        'timezone' => date_default_timezone_get(),
        'image_mode' => in_array($mode, ['unique', 'all'], true) ? $mode : 'all',
        'image_concurrency' => max(1, min(8, (int)(getenv('AUTO_UPDATE_IMAGE_CONCURRENCY') ?: 4))),
    ];
}

/** "06:10, 18:10" → ['06:10','18:10']; entradas inválidas são descartadas e a lista vazia cai no padrão. */
function autoUpdateTimes(string $raw): array
{
    $times = [];
    foreach (preg_split('/[,;\s]+/', trim($raw)) ?: [] as $item) {
        if (!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $item, $match)) continue;
        $times[] = sprintf('%02d:%02d', (int)$match[1], (int)$match[2]);
    }
    $times = array_values(array_unique($times));
    sort($times);
    return $times ?: explode(',', AUTO_UPDATE_DEFAULT_TIMES);
}

function autoUpdateNextRun(array $times, ?int $now = null): ?int
{
    $now ??= time();
    $next = null;
    foreach ($times as $time) {
        [$hour, $minute] = array_map('intval', explode(':', $time));
        foreach ([0, 1] as $dayOffset) {
            $stamp = mktime($hour, $minute, 0, (int)date('n', $now), (int)date('j', $now) + $dayOffset, (int)date('Y', $now));
            if ($stamp !== false && $stamp > $now && ($next === null || $stamp < $next)) $next = $stamp;
        }
    }
    return $next;
}

function autoUpdateStateLabel(string $state): string
{
    return [
        'checking' => 'Verificando o Scryfall',
        'syncing' => 'Importando o catálogo',
        'downloading_images' => 'Baixando imagens',
        'up_to_date' => 'Sem novidades',
        'completed' => 'Concluída',
        'images_pending' => 'Concluída com imagens pendentes',
        'skipped' => 'Adiada: outra tarefa em andamento',
        'error' => 'Falhou',
        'interrupted' => 'Interrompida',
    ][$state] ?? $state;
}

function autoUpdateActive(string $state): bool
{
    return in_array($state, ['checking', 'syncing', 'downloading_images'], true);
}

/** A trava só existe enquanto o processo vive; sem ela, uma execução "em andamento" ficou órfã. */
function autoUpdateLocked(string $storage): bool
{
    $lock = @fopen(rtrim($storage, '/') . '/auto-update.lock', 'c');
    if (!$lock) return false;
    $free = @flock($lock, LOCK_EX | LOCK_NB);
    if ($free) @flock($lock, LOCK_UN);
    @fclose($lock);
    return !$free;
}

/** Fecha execuções que ficaram marcadas como em andamento depois de o processo morrer. */
function autoUpdateReconcile(PDO $pdo, string $storage): void
{
    if (autoUpdateLocked($storage)) return;
    $pdo->exec("UPDATE auto_update_runs SET state = 'interrupted', finished_at = COALESCE(finished_at, now()), "
        . "error = CASE WHEN error = '' THEN 'A rotina parou antes do fim. O que já foi importado ou baixado continua no acervo.' ELSE error END "
        . "WHERE state IN ('checking','syncing','downloading_images')");
}

function autoUpdateRuns(PDO $pdo, int $limit = 10): array
{
    $stmt = $pdo->prepare('SELECT * FROM auto_update_runs ORDER BY started_at DESC, id DESC LIMIT :limit');
    $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function autoUpdateDuration(?string $startedAt, ?string $finishedAt): string
{
    $start = $startedAt ? strtotime($startedAt) : false;
    $end = $finishedAt ? strtotime($finishedAt) : time();
    if ($start === false || $end === false || $end < $start) return '—';
    $seconds = $end - $start;
    if ($seconds < 60) return $seconds . ' s';
    if ($seconds < 3600) return (int)round($seconds / 60) . ' min';
    return number_format($seconds / 3600, 1, ',', '.') . ' h';
}

/** Uma linha do histórico já formatada, usada tanto na página quanto no JSON que a atualiza. */
function autoUpdateRunRow(array $run): array
{
    $state = (string)$run['state'];
    $added = (int)$run['cards_added'];
    $images = (int)$run['images_downloaded'];
    $failed = (int)$run['images_failed'];
    $summary = match ($state) {
        'up_to_date' => 'O Scryfall não publicou dados novos.',
        'skipped' => 'Uma sincronização ou download manual já estava rodando.',
        'error' => (string)$run['error'] ?: 'A rotina falhou.',
        'checking' => 'Consultando o manifesto do Scryfall…',
        'syncing' => 'Importando as cartas publicadas pelo Scryfall…',
        'downloading_images' => number_format($images, 0, ',', '.') . ' imagens baixadas até agora.',
        default => sprintf(
            '%s %s · %s %s baixada%s%s',
            number_format($added, 0, ',', '.'),
            $added === 1 ? 'carta nova' : 'cartas novas',
            number_format($images, 0, ',', '.'),
            $images === 1 ? 'imagem' : 'imagens',
            $images === 1 ? '' : 's',
            $failed > 0 ? ' · ' . number_format($failed, 0, ',', '.') . ' com falha' : ''
        ),
    };
    if (in_array($state, ['interrupted'], true) && $run['error']) $summary = (string)$run['error'];
    return [
        'id' => (int)$run['id'],
        'state' => $state,
        'label' => autoUpdateStateLabel($state),
        'started_at' => date('d/m/Y H:i', (int)strtotime((string)$run['started_at'])),
        'duration' => autoUpdateDuration($run['started_at'] ?? null, $run['finished_at'] ?? null),
        'summary' => $summary,
        'manual' => (string)$run['trigger_source'] === 'manual',
        'sync_run_id' => $run['sync_run_id'] !== null ? (int)$run['sync_run_id'] : null,
        'cards_added' => $added,
        'images_downloaded' => $images,
    ];
}

/** Frase que descreve o agendamento na página Status. */
function autoUpdateScheduleText(array $config): string
{
    $times = $config['times'];
    $last = array_pop($times);
    $list = $times ? implode(', ', $times) . ' e ' . $last : $last;
    $scope = $config['image_mode'] === 'unique' ? 'uma imagem por carta lógica' : 'todas as impressões';
    if (!$config['enabled']) {
        return 'A rotina está desligada (AUTO_UPDATE_ENABLED=0). Ligue-a para que o acervo se atualize sozinho às ' . $list . '.';
    }
    return 'Todos os dias às ' . $list . ' (' . $config['timezone'] . ') o servidor consulta o Scryfall. Havendo publicação nova, '
        . 'o catálogo é importado e, em seguida, as imagens em tamanho normal são baixadas (' . $scope . '). '
        . 'Nada é baixado quando não há novidade.';
}

/** Estado da rotina para a página Status e para o endpoint de atualização contínua. */
function autoUpdateOverview(PDO $pdo, string $storage): array
{
    autoUpdateSchema($pdo);
    autoUpdateReconcile($pdo, $storage);
    $config = autoUpdateConfig();
    $runs = autoUpdateRuns($pdo, 10);
    $current = $runs[0] ?? null;
    $state = $current ? (string)$current['state'] : '';
    $active = $state !== '' && autoUpdateActive($state);
    $lastFinished = null;
    foreach ($runs as $run) {
        if (!autoUpdateActive((string)$run['state'])) { $lastFinished = $run; break; }
    }
    $lastUpdate = null;
    foreach ($runs as $run) {
        if ((bool)$run['had_update'] && in_array((string)$run['state'], ['completed', 'images_pending'], true)) { $lastUpdate = $run; break; }
    }
    $next = $config['enabled'] ? autoUpdateNextRun($config['times']) : null;
    return [
        'enabled' => $config['enabled'],
        'schedule_text' => autoUpdateScheduleText($config),
        'times' => $config['times'],
        'timezone' => $config['timezone'],
        'image_mode' => $config['image_mode'],
        'state' => $state,
        'label' => $active
            ? autoUpdateStateLabel($state)
            : ($config['enabled'] ? 'Agendada' : 'Desligada'),
        'active' => $active,
        'next_run' => $next ? date('d/m/Y H:i', $next) : null,
        'last_check' => $lastFinished ? date('d/m/Y H:i', (int)strtotime((string)$lastFinished['started_at'])) : null,
        'last_check_state' => $lastFinished ? autoUpdateStateLabel((string)$lastFinished['state']) : null,
        'last_update' => $lastUpdate ? date('d/m/Y H:i', (int)strtotime((string)$lastUpdate['started_at'])) : null,
        'last_cards_added' => $lastUpdate ? (int)$lastUpdate['cards_added'] : null,
        'last_images' => $lastUpdate ? (int)$lastUpdate['images_downloaded'] : null,
        'runs' => array_map('autoUpdateRunRow', $runs),
    ];
}
