<?php
declare(strict_types=1);

/**
 * Histórico das sincronizações do catálogo: cada execução registra as cartas
 * que entraram no banco pela primeira vez (inserções, não atualizações).
 */
function syncLogSchema(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS sync_runs (
            id bigserial PRIMARY KEY,
            bulk_type text NOT NULL,
            remote_updated_at timestamptz NULL,
            started_at timestamptz NOT NULL DEFAULT now(),
            finished_at timestamptz NULL,
            state text NOT NULL DEFAULT 'importing',
            processed integer NOT NULL DEFAULT 0,
            added integer NOT NULL DEFAULT 0,
            initial_import boolean NOT NULL DEFAULT false,
            error text NOT NULL DEFAULT ''
        );
        CREATE TABLE IF NOT EXISTS sync_run_cards (
            run_id bigint NOT NULL REFERENCES sync_runs(id) ON DELETE CASCADE,
            card_id uuid NOT NULL,
            PRIMARY KEY (run_id, card_id)
        );
        CREATE INDEX IF NOT EXISTS sync_runs_started_idx ON sync_runs (started_at DESC);
    SQL);
}

/** Grava em lote os IDs novos; chamado dentro da transação da importação. */
function syncLogAddCards(PDO $pdo, int $runId, array $cardIds): void
{
    foreach (array_chunk($cardIds, 1000) as $chunk) {
        $pdo->prepare('INSERT INTO sync_run_cards (run_id, card_id) SELECT ?, unnest(?::uuid[]) ON CONFLICT DO NOTHING')
            ->execute([$runId, '{' . implode(',', $chunk) . '}']);
    }
}

function syncRunStateLabel(string $state): string
{
    return [
        'importing' => 'Em andamento',
        'completed' => 'Concluída',
        'error' => 'Falhou',
        'interrupted' => 'Interrompida',
    ][$state] ?? $state;
}
