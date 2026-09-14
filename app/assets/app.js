(() => {
  const unavailable = (img) => {
    if (!(img instanceof HTMLImageElement) || img.dataset.failed) return;
    if (img.classList.contains('set-icon')) return;
    img.dataset.failed = 'true';
    if (img.classList.contains('mana-symbol') || img.classList.contains('brand-mark')) {
      const text = document.createElement('span');
      text.className = 'icon-text-fallback';
      text.textContent = img.alt || '';
      img.replaceWith(text);
      return;
    }
    const fallback = document.createElement('div');
    fallback.className = 'placeholder image-fallback';
    const name = document.createElement('strong');
    name.textContent = img.alt || 'Carta';
    const message = document.createElement('span');
    message.textContent = 'Imagem indisponível';
    fallback.append(name, message);
    img.replaceWith(fallback);
  };
  document.addEventListener('error', event => {
    if (event.target instanceof HTMLImageElement && event.target.classList.contains('set-icon')) return;
    unavailable(event.target);
  }, true);
  document.addEventListener('error', event => {
    const image = event.target;
    if (image instanceof HTMLImageElement && image.classList.contains('set-icon')) {
      if (image.dataset.fallbackSrc || image.dataset.genericSrc) return;
      image.hidden = true;
      const fallback = image.nextElementSibling;
      if (fallback) fallback.hidden = false;
    }
  }, true);
  document.querySelectorAll('img').forEach(img => {
    if (img.complete && img.naturalWidth === 0) unavailable(img);
  });
  document.addEventListener('keydown', event => {
    if (event.key !== '/' || event.ctrlKey || event.metaKey || event.altKey || event.target.closest('input,textarea,select,[contenteditable]')) return;
    const search = document.querySelector('input[type="search"]');
    if (search) { event.preventDefault(); search.focus(); }
  });
  document.querySelectorAll('.view-toggle a.active,.tabs a.active').forEach(link => link.setAttribute('aria-current','true'));
  document.querySelectorAll('form.search').forEach(form => form.addEventListener('submit', () => {
    const button = form.querySelector('button');
    if (button) { button.dataset.label = button.textContent; button.textContent = 'Buscando…'; button.setAttribute('aria-disabled','true'); }
  }));
  document.querySelectorAll('.builder-results form').forEach(form => form.addEventListener('submit', () => {
    try { sessionStorage.setItem('builder-return-scroll', String(window.scrollY)); } catch (_) {}
  }));
  const selection = window.builderSelection || {};
  if (Object.keys(selection).length) {
    document.querySelectorAll('.builder-results article').forEach(article => {
      const link = article.querySelector('a[href*="/card.php?id="]');
      const id = link?.href.match(/[?&]id=([^&]+)/)?.[1];
      const item = id && selection[id];
      if (!item) return;
      const action = article.querySelector('form button');
      if (action) { action.disabled = true; action.textContent = `Já adicionada · ${item.label}`; action.classList.add('is-selected'); }
      const badge = document.createElement('span'); badge.className = 'builder-selected-badge'; badge.textContent = `Já adicionada · ${item.label}`;
      article.querySelector('h3')?.after(badge);
    });
    document.querySelectorAll('.builder-item').forEach(form => {
      const link = form.querySelector('a[href*="/card.php?id="]');
      const id = link?.href.match(/[?&]id=([^&]+)/)?.[1];
      const item = id && selection[id];
      if (!item || !item.image || form.querySelector('.builder-item-preview')) return;
      const image = document.createElement('img'); image.className='builder-item-preview'; image.src=item.image; image.alt=''; image.loading='lazy'; form.prepend(image);
    });
  }
  try {
    const savedScroll = sessionStorage.getItem('builder-return-scroll');
    if (savedScroll !== null) {
      sessionStorage.removeItem('builder-return-scroll');
      window.requestAnimationFrame(() => window.scrollTo({ top: Number(savedScroll) || 0, behavior: 'instant' }));
    }
  } catch (_) {}
  window.addEventListener('pageshow', () => {
    document.querySelectorAll('button[data-label]').forEach(button => {
      button.textContent = button.dataset.label;
      button.removeAttribute('aria-disabled');
    });
  });

  const formatNumber = value => new Intl.NumberFormat('pt-BR').format(Number(value || 0));
  const formatBytes = value => `${new Intl.NumberFormat('pt-BR', { maximumFractionDigits: 1 }).format(Number(value || 0) / 1048576)} MB`;
  const formatDate = value => {
    if (!value) return 'sem registro';
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? String(value) : new Intl.DateTimeFormat('pt-BR', { dateStyle: 'short', timeStyle: 'short' }).format(date);
  };
  const requestJson = async (url, options = {}) => {
    const response = await fetch(url, { cache: 'no-store', ...options, headers: { Accept: 'application/json', ...(options.headers || {}) } });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok || payload.ok === false) throw new Error(payload.message || 'Não foi possível concluir a operação.');
    return payload;
  };
  const setFeedback = (element, message, type = 'info') => {
    if (!element) return;
    element.hidden = !message;
    element.textContent = message || '';
    element.dataset.type = type;
  };

  const downloadPanel = document.querySelector('[data-download-status]');
  if (downloadPanel) {
    const startButton = downloadPanel.querySelector('[data-download-start]');
    const pauseButton = downloadPanel.querySelector('[data-download-pause]');
    const modeSelect = downloadPanel.querySelector('[data-download-mode]');
    const feedback = downloadPanel.querySelector('[data-status-feedback]');
    const statusTag = downloadPanel.querySelector('.status-tag');
    const description = downloadPanel.querySelector('[data-download-description]');
    const progressWrap = downloadPanel.querySelector('[data-progress-wrap]');
    const progressBar = downloadPanel.querySelector('[data-progress-bar]');
    const progressLabel = downloadPanel.querySelector('[data-progress-label]');
    const progressPercent = downloadPanel.querySelector('[data-progress-percent]');
    const progressUpdated = downloadPanel.querySelector('[data-progress-updated]');
    const metric = name => downloadPanel.querySelector(`[data-metric="${name}"]`);
    let pollTimer = null;
    let polling = false;

    const renderDownload = data => {
      if (statusTag && data.label) statusTag.textContent = data.label;
      downloadPanel.dataset.state = data.state || '';
      if (description) description.textContent = data.mode === 'unique'
        ? 'Tamanho normal · uma imagem por carta lógica, evitando repetir reimpressões.'
        : 'Tamanho normal · todas as impressões, incluindo as faces disponíveis.';
      if (metric('downloaded')) metric('downloaded').textContent = formatNumber(data.downloaded);
      if (metric('existing')) metric('existing').textContent = formatNumber(data.existing);
      if (metric('failed')) metric('failed').textContent = formatNumber(data.failed);
      if (metric('bytes')) metric('bytes').textContent = formatBytes(data.bytes);
      const hasTotal = Number(data.total || 0) > 0;
      const indeterminate = !hasTotal && Boolean(data.active);
      const percent = data.percent == null ? null : Math.max(0, Math.min(100, Number(data.percent)));
      if (progressWrap) {
        progressWrap.hidden = !hasTotal && !indeterminate;
        progressWrap.classList.toggle('is-indeterminate', indeterminate);
        progressWrap.setAttribute('aria-valuenow', String(percent ?? 0));
      }
      if (progressBar) progressBar.style.width = indeterminate ? '34%' : `${percent ?? 0}%`;
      if (progressPercent) progressPercent.textContent = percent == null ? '—' : `${percent}%`;
      if (progressLabel) progressLabel.textContent = hasTotal
        ? `${formatNumber(data.processed)} de ${formatNumber(data.total)} arquivos processados`
        : (indeterminate && data.processed ? `${formatNumber(data.processed)} arquivos processados` : 'Progresso sendo calculado');
      if (progressUpdated && data.updated_at) progressUpdated.textContent = `Última atualização: ${formatDate(data.updated_at * 1000)}. O download é retomável; arquivos existentes são preservados.`;
      if (startButton) {
        startButton.disabled = Boolean(data.active);
        startButton.textContent = data.active ? 'Download em andamento…' : 'Baixar imagens';
      }
      if (pauseButton) { pauseButton.hidden = !data.active; pauseButton.disabled = data.state === 'stopping'; }
      if (data.last_error) setFeedback(feedback, data.last_error, 'error');
      if (modeSelect && data.mode && !data.active) modeSelect.value = data.mode;
    };

    const pollDownload = async () => {
      if (polling) return;
      polling = true;
      try {
        const data = await requestJson('/download_progress.php');
        renderDownload(data);
      } catch (error) {
        setFeedback(feedback, error.message, 'error');
      } finally {
        polling = false;
        window.clearTimeout(pollTimer);
        pollTimer = window.setTimeout(pollDownload, downloadPanel.dataset.state === 'running' ? 1600 : 5000);
      }
    };

    startButton?.addEventListener('click', async () => {
      startButton.disabled = true;
      setFeedback(feedback, 'Iniciando o downloader…');
      try {
        const data = await requestJson('/download_control.php', { method: 'POST', body: new URLSearchParams({ action: 'start', mode: modeSelect?.value || 'all', concurrency: '2' }) });
        setFeedback(feedback, data.message, 'success');
        await pollDownload();
      } catch (error) {
        setFeedback(feedback, error.message, 'error');
        startButton.disabled = false;
      }
    });
    pauseButton?.addEventListener('click', async () => {
      pauseButton.disabled = true;
      setFeedback(feedback, 'Solicitando pausa…');
      try {
        const data = await requestJson('/download_control.php', { method: 'POST', body: new URLSearchParams({ action: 'pause' }) });
        setFeedback(feedback, data.message, 'success');
        await pollDownload();
      } catch (error) {
        setFeedback(feedback, error.message, 'error');
        pauseButton.disabled = false;
      }
    });
    pollDownload();
  }

  const checkUpdatesButton = document.querySelector('[data-check-updates]');
  if (checkUpdatesButton) {
    const feedback = document.querySelector('[data-updates-feedback]');
    checkUpdatesButton.addEventListener('click', async () => {
      checkUpdatesButton.disabled = true;
      checkUpdatesButton.textContent = 'Consultando Scryfall…';
      setFeedback(feedback, 'Verificando a versão mais recente do acervo…');
      try {
        const data = await requestJson('/check_updates.php');
        const remote = formatDate(data.remote_updated_at);
        setFeedback(feedback, data.has_update ? `Há uma atualização disponível no Scryfall (publicada em ${remote}).` : `Seu acervo já está atualizado. Última publicação consultada: ${remote}.`, data.has_update ? 'warning' : 'success');
      } catch (error) {
        setFeedback(feedback, error.message, 'error');
      } finally {
        checkUpdatesButton.disabled = false;
        checkUpdatesButton.textContent = 'Verificar atualizações';
      }
    });
  }
})();
