<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require __DIR__ . '/functions.php';
require __DIR__ . '/partials.php';
require __DIR__ . '/card_filters.php';
require __DIR__ . '/sync_log.php';
require __DIR__ . '/sync_state.php';
authRequireAdmin();

$pdo = db();
syncLogSchema($pdo);
$config = require __DIR__ . '/config.php';
$storage = rtrim((string)$config['storage_dir'], '/');
$syncActive = syncCatalogProgress($storage)['active'];

$runs = $pdo->query('SELECT * FROM sync_runs ORDER BY started_at DESC, id DESC LIMIT 30')->fetchAll();
$runId = (int)($_GET['run'] ?? 0);
if (!$runId && $runs) $runId = (int)$runs[0]['id'];
$runStmt = $pdo->prepare('SELECT * FROM sync_runs WHERE id = ?');
$runStmt->execute([$runId]);
$run = $runStmt->fetch() ?: null;

// Execução sem processo vivo ficou para trás (ex.: container reiniciado no meio da importação).
$runState = static fn(array $row): string => $row['state'] === 'importing' && !$syncActive ? 'interrupted' : (string)$row['state'];
$dateTime = static fn(?string $value): string => $value ? date('d/m/Y H:i', strtotime($value)) : '—';

$query = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']), 0, 120) : '';
$set = is_string($_GET['set'] ?? null) ? mb_substr(trim($_GET['set']), 0, 12) : '';
$where = 'r.run_id = :run';
$params = ['run' => $runId];
if ($query !== '') {
    $where .= ' AND c.name ILIKE :name';
    $params['name'] = '%' . strtr($query, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']) . '%';
}
if ($set !== '') {
    $where .= ' AND c.set_code = :set';
    $params['set'] = $set;
}
$from = "FROM sync_run_cards r JOIN cards c ON c.id = r.card_id WHERE {$where}";
$order = "ORDER BY c.released_at DESC NULLS LAST, c.set_name, NULLIF(regexp_replace(c.collector_number, '[^0-9]', '', 'g'), '')::numeric NULLS LAST, c.collector_number, c.name";

// CSV completo, gerado em streaming para atualizações grandes.
if ($run && ($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="cartas-adicionadas-sync-' . $runId . '.csv"');
    $out = fopen('php://output', 'wb');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Nome', 'Edição', 'Código', 'Número', 'Raridade', 'Idioma', 'Lançamento', 'Tipo', 'Scryfall ID'], ';');
    $stmt = $pdo->prepare("SELECT c.name, c.set_name, c.set_code, c.collector_number, c.rarity, c.lang, c.released_at, c.type_line, c.id {$from} {$order}");
    $stmt->execute($params);
    while ($row = $stmt->fetch(PDO::FETCH_NUM)) fputcsv($out, $row, ';');
    exit;
}

$perPage = 100;
$page = max(1, (int)($_GET['page'] ?? 1));
$total = 0;
$cards = [];
$sets = [];
if ($run) {
    $count = $pdo->prepare("SELECT count(*) {$from}");
    $count->execute($params);
    $total = (int)$count->fetchColumn();
    $pages = max(1, (int)ceil($total / $perPage));
    $page = min($page, $pages);
    $stmt = $pdo->prepare("SELECT c.id, c.name, c.set_name, c.set_code, c.collector_number, c.rarity, c.lang, c.released_at, c.type_line {$from} {$order} LIMIT {$perPage} OFFSET " . (($page - 1) * $perPage));
    $stmt->execute($params);
    $cards = $stmt->fetchAll();
    $setsStmt = $pdo->prepare('SELECT c.set_code, max(c.set_name) AS set_name, max(c.released_at) AS released_at, count(*) AS total FROM sync_run_cards r JOIN cards c ON c.id = r.card_id WHERE r.run_id = ? GROUP BY c.set_code ORDER BY max(c.released_at) DESC NULLS LAST, count(*) DESC');
    $setsStmt->execute([$runId]);
    $sets = $setsStmt->fetchAll();
}
$totalPages = max(1, (int)ceil($total / $perPage));
$hasLocalImage = static function (string $id) use ($storage): bool {
    foreach (['normal', 'small'] as $size) {
        $file = "{$storage}/images/{$size}/{$id}-front.jpg";
        if (is_file($file) && filesize($file) > 0) return true;
    }
    return false;
};
$rarities = ['common' => 'Comum', 'uncommon' => 'Incomum', 'rare' => 'Rara', 'mythic' => 'Mítica', 'special' => 'Especial', 'bonus' => 'Bônus'];

pageHeader('Histórico de atualizações');
?>
<section class="hero"><div><h1>Histórico de atualizações.</h1><p>Cartas que entraram no acervo em cada sincronização com o Scryfall.</p></div><a class="text-link" href="/status.php">&larr; Status do acervo</a></section>

<?php if (!$runs): ?>
<section class="panel"><h2>Nenhuma sincronização registrada ainda</h2><p class="muted">O histórico começa na próxima vez que você usar “Baixar atualização” na página de status. A partir daí, cada carta nova fica registrada aqui.</p></section>
<?php else: ?>
<div class="sync-history">
<aside class="sync-runs" aria-labelledby="sync-runs-title">
    <h2 id="sync-runs-title">Sincronizações</h2>
    <ol>
        <?php foreach ($runs as $row): $state = $runState($row); ?>
        <li><a href="/sync_history.php?run=<?= (int)$row['id'] ?>"<?= (int)$row['id'] === $runId ? ' aria-current="page"' : '' ?>>
            <strong><?= h($dateTime($row['started_at'])) ?></strong>
            <span><?= number_format((int)$row['added'], 0, ',', '.') ?> <?= (int)$row['added'] === 1 ? 'carta nova' : 'cartas novas' ?></span>
            <?php if ($state !== 'completed'): ?><small class="sync-run-state is-<?= h($state) ?>"><?= h(syncRunStateLabel($state)) ?></small><?php endif; ?>
        </a></li>
        <?php endforeach; ?>
    </ol>
</aside>

<section class="sync-run-detail" aria-labelledby="sync-run-title">
<?php if (!$run): ?>
    <p class="muted">Sincronização não encontrada.</p>
<?php else: $state = $runState($run); ?>
    <div class="section-heading">
        <h2 id="sync-run-title">Sincronização de <?= h($dateTime($run['started_at'])) ?></h2>
        <span class="status-tag sync-run-state is-<?= h($state) ?>"><?= h(syncRunStateLabel($state)) ?></span>
    </div>
    <dl class="download-metrics">
        <div><dt>Cartas novas</dt><dd><?= number_format((int)$run['added'], 0, ',', '.') ?></dd></div>
        <div><dt>Edições com novidades</dt><dd><?= number_format(count($sets), 0, ',', '.') ?></dd></div>
        <div><dt>Registros processados</dt><dd><?= number_format((int)$run['processed'], 0, ',', '.') ?></dd></div>
        <div><dt>Publicação do Scryfall</dt><dd><?= h($dateTime($run['remote_updated_at'])) ?></dd></div>
    </dl>
    <?php if ($run['initial_import'] === true || $run['initial_import'] === 't'): ?><p class="status-feedback" data-type="info">Importação inicial: o acervo estava vazio, então todas as cartas contam como novas.</p><?php endif; ?>
    <?php if ($run['error'] !== '' || $state === 'interrupted'): ?><p class="status-feedback" data-type="warning"><?= h($run['error'] ?: 'A sincronização parou antes do fim.') ?> As cartas listadas abaixo já estão no acervo.</p><?php endif; ?>

    <?php if ((int)$run['added'] === 0 && !$sets): ?>
        <p class="muted">Nenhuma carta nova nesta sincronização: apenas preços, legalidades e dados existentes foram atualizados.</p>
    <?php else: ?>
        <?php $setChips = count($sets) <= 24; if ($sets && $setChips): ?>
        <div class="sync-sets" aria-label="Cartas novas por edição">
            <a href="/sync_history.php?<?= h(http_build_query(array_filter(['run' => $runId, 'q' => $query]))) ?>"<?= $set === '' ? ' aria-current="true"' : '' ?>>Todas <span><?= number_format((int)$run['added'], 0, ',', '.') ?></span></a>
            <?php foreach ($sets as $row): ?>
            <a href="/sync_history.php?<?= h(http_build_query(array_filter(['run' => $runId, 'set' => $row['set_code'], 'q' => $query]))) ?>"<?= $set === $row['set_code'] ? ' aria-current="true"' : '' ?>><?= h($row['set_name']) ?> <small><?= h(strtoupper((string)$row['set_code'])) ?></small> <span><?= number_format((int)$row['total'], 0, ',', '.') ?></span></a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <form class="sync-history-search" method="get" action="/sync_history.php" role="search">
            <input type="hidden" name="run" value="<?= $runId ?>">
            <label class="field">Buscar nesta sincronização<input type="search" name="q" value="<?= h($query) ?>" placeholder="Nome da carta"></label>
            <?php if ($setChips): ?>
                <?php if ($set !== ''): ?><input type="hidden" name="set" value="<?= h($set) ?>"><?php endif; ?>
            <?php else: ?>
                <label class="field">Edição<select name="set" data-auto-submit><option value="">Todas as edições (<?= count($sets) ?>)</option><?php foreach ($sets as $row): ?><option value="<?= h($row['set_code']) ?>"<?= $set === $row['set_code'] ? ' selected' : '' ?>><?= h($row['set_name'] . ' · ' . strtoupper((string)$row['set_code']) . ' · ' . number_format((int)$row['total'], 0, ',', '.')) ?></option><?php endforeach; ?></select></label>
            <?php endif; ?>
            <button class="primary-link">Buscar</button>
            <a class="secondary-link" href="/sync_history.php?<?= h(http_build_query(array_filter(['run' => $runId, 'set' => $set, 'q' => $query, 'export' => 'csv']))) ?>">Exportar CSV</a>
        </form>

        <p class="muted"><?= number_format($total, 0, ',', '.') ?> <?= $total === 1 ? 'carta' : 'cartas' ?><?= $set !== '' || $query !== '' ? ' com os filtros atuais' : '' ?><?= $totalPages > 1 ? ' · página ' . $page . ' de ' . $totalPages : '' ?>.</p>
        <?php if ($cards): ?>
        <div class="table-scroll"><table class="sync-cards">
            <thead><tr><th>Carta</th><th>Edição</th><th>Nº</th><th>Raridade</th><th>Idioma</th><th>Lançamento</th><th>Imagem local</th></tr></thead>
            <tbody>
            <?php foreach ($cards as $card): ?>
                <tr>
                    <td><a href="/card.php?id=<?= h(rawurlencode((string)$card['id'])) ?>"><?= h($card['name']) ?></a><small><?= h((string)$card['type_line']) ?></small></td>
                    <td><?= h((string)$card['set_name']) ?> <small><?= h(strtoupper((string)$card['set_code'])) ?></small></td>
                    <td><?= h((string)$card['collector_number']) ?></td>
                    <td><?= h($rarities[$card['rarity']] ?? (string)$card['rarity']) ?></td>
                    <td><?= h(strtoupper((string)$card['lang'])) ?></td>
                    <td><?= h(displayDate($card['released_at'])) ?></td>
                    <td><?= $hasLocalImage((string)$card['id']) ? '<span class="sync-image is-local">Baixada</span>' : '<span class="sync-image">Pendente</span>' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php if ($totalPages > 1) numberedPager($page, $totalPages, array_filter(['run' => $runId, 'set' => $set, 'q' => $query])); ?>
        <?php else: ?><p class="muted">Nenhuma carta encontrada com esses filtros.</p><?php endif; ?>
    <?php endif; ?>
<?php endif; ?>
</section>
</div>
<?php endif; ?>
<?php pageFooter(); ?>
