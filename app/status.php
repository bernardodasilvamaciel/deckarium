<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require __DIR__ . '/functions.php';
require __DIR__ . '/partials.php';
require __DIR__ . '/catalog_cache.php';
require __DIR__ . '/sync_log.php';
authRequireAdmin();
$stats = catalogCached('status-counts', fn() => db()->query('SELECT count(*) AS printings, count(DISTINCT COALESCE(oracle_id,id)) AS unique_cards FROM cards')->fetchAll(), 300)[0];
$sync = db()->query('SELECT * FROM sync_status ORDER BY imported_at DESC')->fetchAll();
syncLogSchema(db());
$recentRuns = db()->query('SELECT id, started_at, state, added FROM sync_runs ORDER BY started_at DESC, id DESC LIMIT 3')->fetchAll();
$config = require __DIR__ . '/config.php';
$progress = json_decode((string)@file_get_contents($config['storage_dir'] . '/download-progress.json'), true) ?: [];
$labels = ['starting'=>'Preparando download','running'=>'Download em andamento','completed'=>'Download concluído','completed_with_errors'=>'Concluído com imagens pendentes','disk_full'=>'Pausado: espaço insuficiente','stopped_by_user'=>'Download pausado pelo usuário','stale'=>'Progresso sem atualização recente'];
require __DIR__ . '/download_state.php';
require __DIR__ . '/sync_state.php';
$state = downloadState($config['storage_dir'],$progress);
$labels += ['interrupted'=>'Download interrompido. Retome para continuar','network_error'=>'Pausado após falhas de conexão','stopping'=>'Pausando download'];
$pauseRequested = is_file($config['storage_dir'] . '/STOP_DOWNLOAD');
$stale = in_array($state,['running','starting','stopping'],true) && time()-($progress['updated_at'] ?? 0)>300;
if ($pauseRequested && $state === '') {
    $state = 'stopped_by_user';
}
$progressTotal = max(0, (int)($progress['total'] ?? 0));
$progressProcessed = max(0, (int)($progress['processed'] ?? ((int)($progress['downloaded'] ?? 0) + (int)($progress['existing'] ?? 0) + (int)($progress['failed'] ?? 0))));
$progressPercent = $progressTotal > 0 ? min(100, (int)round(($progressProcessed / $progressTotal) * 100)) : null;
$downloadActive = in_array($state, ['running','starting','stopping'], true);
$syncState = syncCatalogProgress(rtrim($config['storage_dir'], '/'));
pageHeader('Status do acervo');
?>
<section class="hero"><div><h1>Seu acervo local.</h1><p>Dados importados, imagens disponíveis e andamento dos downloads.</p></div><a class="text-link" href="/status.php">Atualizar página</a></section>
<div class="stats">
<div><span>Cartas únicas</span><strong><?= number_format((int)$stats['unique_cards'],0,',','.') ?></strong></div>
<div><span>Impressões no banco</span><strong><?= number_format((int)$stats['printings'],0,',','.') ?></strong></div>
</div>
<section class="panel download-status" data-download-status data-state="<?= h((string)$state) ?>" data-total="<?= $progressTotal ?>">
<div class="section-heading"><h2>Imagens em alta qualidade</h2><span class="status-tag"><?= h($stale ? 'Progresso sem atualização recente' : ($labels[$state] ?? 'Nenhum download registrado')) ?></span></div>
<p data-download-description>Tamanho normal · escolha o escopo e acompanhe a transferência nesta página.</p>
<dl class="download-metrics">
<div><dt>Baixadas nesta passagem</dt><dd data-metric="downloaded"><?= number_format((int)($progress['downloaded'] ?? 0),0,',','.') ?></dd></div>
<div><dt>Já existentes</dt><dd data-metric="existing"><?= number_format((int)($progress['existing'] ?? 0),0,',','.') ?></dd></div>
<div><dt>Falhas nesta passagem</dt><dd data-metric="failed"><?= number_format((int)($progress['failed'] ?? 0),0,',','.') ?></dd></div>
<div><dt>Transferido</dt><dd data-metric="bytes"><?= number_format(($progress['bytes'] ?? 0)/1048576,1,',','.') ?> MB</dd></div>
</dl>
<div class="download-progress" data-progress-wrap<?= ($progressTotal > 0 || $downloadActive) ? '' : ' hidden' ?> role="progressbar" aria-label="Progresso do download" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $progressPercent ?? 0 ?>">
  <div class="progress-meta"><span data-progress-label><?= $progressPercent !== null ? $progressProcessed . ' de ' . $progressTotal . ' arquivos processados' : ($downloadActive && $progressProcessed > 0 ? $progressProcessed . ' arquivos processados' : 'Progresso sendo calculado') ?></span><strong data-progress-percent><?= $progressPercent === null ? '—' : $progressPercent . '%' ?></strong></div>
  <div class="progress-track"><span data-progress-bar style="width:<?= $progressPercent ?? 0 ?>%"></span></div>
</div>
<p class="muted" data-progress-updated>Última atualização: <?= h(date('d/m/Y H:i:s', (int)($progress['updated_at'] ?? time()))) ?> UTC. O download é retomável; arquivos existentes são preservados.</p>
<div class="status-actions">
  <div><h3>Baixar imagens normais</h3><p class="muted">Use cartas únicas para um acervo compacto ou todas as impressões para preservar cada edição.</p></div>
  <div class="status-controls">
    <label class="status-select"><span>Escopo</span><select data-download-mode><option value="all">Todas as impressões</option><option value="unique">Cartas únicas</option></select></label>
    <button class="primary-link" type="button" data-download-start<?= $downloadActive ? ' disabled' : '' ?>><?= $downloadActive ? 'Download em andamento…' : 'Baixar imagens' ?></button>
    <button class="secondary-link" type="button" data-download-pause<?= $downloadActive ? '' : ' hidden' ?>>Pausar</button>
  </div>
</div>
<div class="status-feedback" data-status-feedback role="status" aria-live="polite" hidden></div>
</section>
<section class="panel help-panel sync-panel" data-sync-panel data-state="<?= h($syncState['state']) ?>">
<div class="section-heading"><h2>Catálogo do Scryfall</h2><span class="status-tag" data-sync-label<?= $syncState['state']===''?' hidden':'' ?>><?= h($syncState['label']) ?></span></div>
<div class="sync-actions">
  <p class="muted">Verifique se o Scryfall publicou dados novos e baixe a atualização aqui mesmo. Cartas, preços e legalidades são atualizados; sua coleção e seus decks continuam intactos.</p>
  <div class="status-controls">
    <button class="secondary-link" type="button" data-check-updates>Verificar atualizações</button>
    <button class="primary-link" type="button" data-sync-start<?= $syncState['active'] ? ' disabled' : '' ?>><?= $syncState['active'] ? 'Atualizando…' : 'Baixar atualização' ?></button>
  </div>
</div>
<div class="sync-progress" data-sync-progress<?= $syncState['active'] ? '' : ' hidden' ?>>
  <ol class="sync-steps" aria-label="Etapas da sincronização">
    <li data-sync-step="checking">Consultar</li><li data-sync-step="downloading">Baixar</li><li data-sync-step="importing">Importar</li><li data-sync-step="completed">Concluído</li>
  </ol>
  <div class="download-progress" data-sync-bar-wrap role="progressbar" aria-label="Progresso da sincronização" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= (int)($syncState['percent'] ?? 0) ?>">
    <div class="progress-meta"><span data-sync-detail>Preparando…</span><strong data-sync-percent><?= $syncState['percent'] === null ? '—' : (int)$syncState['percent'] . '%' ?></strong></div>
    <div class="progress-track"><span data-sync-bar style="width:<?= (int)($syncState['percent'] ?? 0) ?>%"></span></div>
  </div>
  <p class="muted sync-note">Pode sair desta página: a sincronização continua em segundo plano. Importar o catálogo completo leva alguns minutos.</p>
</div>
<div class="status-feedback" data-updates-feedback role="status" aria-live="polite" hidden></div>
<div class="sync-force" data-sync-force-wrap hidden><button class="text-button" type="button" data-sync-force>Reimportar mesmo assim</button></div>
<div class="table-scroll"><table><thead><tr><th>Conjunto de dados</th><th>Atualização no Scryfall</th><th>Importação local</th><th>Registros</th></tr></thead><tbody data-sync-rows>
<?php foreach ($sync as $row): ?><tr><td><?= h($row['bulk_type']) ?></td><td><?= h(displayDate($row['scryfall_updated_at'])) ?></td><td><?= h(displayDate($row['imported_at'])) ?></td><td><?= number_format((int)$row['card_count'],0,',','.') ?></td></tr><?php endforeach; ?>
<?php if (!$sync): ?><tr><td colspan="4">Nenhuma sincronização registrada.</td></tr><?php endif; ?>
</tbody></table></div>
<div class="sync-recent">
  <h3>Cartas adicionadas</h3>
  <?php if ($recentRuns): ?>
  <ul>
    <?php foreach ($recentRuns as $run): $runState = $run['state'] === 'importing' && !$syncState['active'] ? 'interrupted' : $run['state']; ?>
    <li><a href="/sync_history.php?run=<?= (int)$run['id'] ?>"><?= h(date('d/m/Y H:i', strtotime((string)$run['started_at']))) ?></a> · <?= number_format((int)$run['added'], 0, ',', '.') ?> <?= (int)$run['added'] === 1 ? 'carta nova' : 'cartas novas' ?><?= $runState !== 'completed' ? ' · ' . h(syncRunStateLabel($runState)) : '' ?></li>
    <?php endforeach; ?>
  </ul>
  <?php else: ?><p class="muted">O registro das cartas adicionadas começa na próxima atualização do catálogo.</p><?php endif; ?>
  <a class="text-link" href="/sync_history.php" data-sync-history-link>Ver histórico completo &rarr;</a>
</div></section>
<?php pageFooter(); ?>

