(() => {
  const bindPrintingAjax = () => {
    document.querySelectorAll('[data-printing-link]').forEach(link => {
      if (link.dataset.ajaxBound) return;
      link.dataset.ajaxBound = 'true';
      link.addEventListener('click', async event => {
        event.preventDefault();
        if (link.classList.contains('is-loading')) return;
        link.classList.add('is-loading');
        const currentFacts = document.querySelector('[data-card-facts]');
        const currentImages = document.querySelector('.detail-images');
        const currentActions = document.querySelector('[data-card-actions]');
        try {
          const response = await fetch(link.href, { headers: { 'X-Requested-With': 'fetch' }, cache: 'no-store' });
          if (!response.ok) throw new Error('Não foi possível carregar esta impressão.');
          const html = await response.text();
          const parsed = new DOMParser().parseFromString(html, 'text/html');
          const nextFacts = parsed.querySelector('[data-card-facts]');
          const nextImages = parsed.querySelector('.detail-images');
          const nextActions = parsed.querySelector('[data-card-actions]');
          if (!nextFacts || !nextImages) throw new Error('Resposta incompleta.');
          currentFacts?.replaceWith(nextFacts);
          currentImages?.replaceWith(nextImages);
          if (currentActions && nextActions) currentActions.replaceWith(nextActions);
          document.title = parsed.title || document.title;
          history.pushState({}, '', link.href);
          document.querySelectorAll('[data-printing-link]').forEach(item => {
            item.classList.toggle('current', item.href === link.href);
          });
          bindPrintingAjax();
        } catch (_) {
          window.location.href = link.href;
        } finally {
          link.classList.remove('is-loading');
        }
      });
    });
  };
  bindPrintingAjax();

  document.querySelectorAll('[data-mana-cost]').forEach(button => {
    button.addEventListener('click', () => {
      const target = Array.from(document.querySelectorAll('[data-mana-list]')).find(item => item.dataset.manaList === button.dataset.manaCost);
      if (!target) return;
      const wasOpen = !target.hidden;
      document.querySelectorAll('[data-mana-list]').forEach(list => { list.hidden = true; });
      document.querySelectorAll('[data-mana-cost]').forEach(item => {
        item.setAttribute('aria-expanded', 'false');
        item.classList.remove('is-selected');
      });
      if (!wasOpen) {
        target.hidden = false;
        button.setAttribute('aria-expanded', 'true');
        button.classList.add('is-selected');
      }
    });
  });

  document.querySelectorAll('.builder-result,.synergy-card').forEach(card => {
    const trigger = card.querySelector('img');
    if (!trigger) return;
    const preview = document.createElement('img');
    preview.className = 'card-hover-card'; preview.alt = trigger.alt || ''; preview.src = trigger.currentSrc || trigger.src; preview.hidden = true;
    card.append(preview);
    const show = () => preview.hidden = false;
    const hide = () => preview.hidden = true;
    trigger.addEventListener('mouseenter', show);
    trigger.addEventListener('mouseleave', hide);
    trigger.addEventListener('focus', show);
    trigger.addEventListener('blur', hide);
  });

  document.querySelectorAll('.upgrade-stack').forEach(stack => {
    stack.setAttribute('role','button'); stack.setAttribute('tabindex','0'); stack.setAttribute('aria-label','Alternar carta do upgrade');
    const toggle = event => { event.preventDefault(); stack.classList.toggle('show-old'); };
    stack.addEventListener('click', toggle);
    stack.addEventListener('keydown', event => { if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); toggle(); } });
  });
  const previewLinks = document.querySelectorAll('[data-card-preview], .mana-card-item');
  if (previewLinks.length) {
    const preview = document.createElement('img');
    preview.className = 'card-hover-preview'; preview.alt = ''; preview.hidden = true;
    document.body.append(preview);
    const hidePreview = () => { preview.hidden = true; };
    const showPreview = link => {
      const previewSrc = link.dataset.cardPreview || link.querySelector('img')?.src;
      if (!previewSrc) return;
      preview.src = previewSrc;
      const rect = link.getBoundingClientRect();
      const width = Math.min(230, window.innerWidth - 24);
      preview.style.width = width + 'px';
      preview.style.left = Math.max(12, Math.min(rect.right + 14, window.innerWidth - width - 12)) + 'px';
      preview.style.top = Math.max(12, Math.min(rect.top, window.innerHeight - width * 1.4 - 12)) + 'px';
      preview.hidden = false;
    };
    previewLinks.forEach(link => {
      link.addEventListener('mouseenter', () => { if (matchMedia('(hover: hover)').matches) showPreview(link); });
      link.addEventListener('mouseleave', hidePreview);
      link.addEventListener('focus', () => showPreview(link));
      link.addEventListener('blur', hidePreview);
      link.addEventListener('click', event => {
        if (matchMedia('(hover: none)').matches) {
          const editor = link.closest('.selection-card')?.querySelector('.selection-editor');
          if (editor) { event.preventDefault(); hidePreview(); editor.open = !editor.open; }
        }
      });
    });
    document.addEventListener('keydown', event => { if (event.key === 'Escape') hidePreview(); });
    window.addEventListener('scroll', hidePreview, true);
    window.addEventListener('resize', hidePreview);
    preview.addEventListener('error', hidePreview);
  }
  const unavailable = (img) => {
    if (!(img instanceof HTMLImageElement) || img.dataset.failed) return;
    if (img.classList.contains('card-hover-preview')) { img.hidden = true; return; }
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
  document.querySelectorAll('form.builder-search').forEach((form, index) => {
    form.id = form.id || 'builder-search-form-' + index;
    const sort = document.querySelector('[data-builder-sort]');
    if (sort) {
      sort.setAttribute('form', form.id);
      sort.addEventListener('change', () => form.requestSubmit());
    }
    document.querySelectorAll('[data-builder-filter]').forEach(filter => filter.setAttribute('form', form.id));
    form.addEventListener('submit', () => {
    if (!form.querySelector('input[name="mode"]')) {
      const mode = document.createElement('input');
      mode.type = 'hidden'; mode.name = 'mode'; mode.value = 'catalog';
      form.append(mode);
    }
    document.querySelectorAll('.commander-color-filter input[name="commander_colors[]"]:checked').forEach(checkbox => {
      const color = document.createElement('input');
      color.type = 'hidden'; color.name = 'commander_colors[]'; color.value = checkbox.value;
      form.append(color);
    });
    const ownedToggle = document.querySelector('.commander-owned-toggle input');
    if (ownedToggle) {
      const owned = document.createElement('input');
      owned.type = 'hidden'; owned.name = 'commander_owned'; owned.value = ownedToggle.checked ? '1' : '0';
      form.append(owned);
    }
    const popularToggle = document.querySelector('.commander-popular-toggle input');
    if (popularToggle) {
      const popular = document.createElement('input');
      popular.type = 'hidden'; popular.name = 'commander_popular'; popular.value = popularToggle.checked ? '1' : '0';
      form.append(popular);
    }
    const synergyToggle = document.querySelector('.synergy-controls input[name="synergy"]');
    if (synergyToggle && !synergyToggle.checked) {
      const synergy = document.createElement('input');
      synergy.type = 'hidden'; synergy.name = 'synergy'; synergy.value = '0';
      form.append(synergy);
    }
    const excludeOwned = document.querySelector('.catalog-only-toggle input[name="exclude_owned"]');
    if (excludeOwned?.checked) {
      const excluded = document.createElement('input');
      excluded.type = 'hidden'; excluded.name = 'exclude_owned'; excluded.value = '1';
      form.append(excluded);
    }
    });
  });
  document.querySelectorAll('form.synergy-controls').forEach(form => form.addEventListener('submit', () => {
    if (!form.querySelector('input[name="synergy"]:checked') && !form.querySelector('input[name="synergy"][type="hidden"]')) {
      const synergy = document.createElement('input');
      synergy.type = 'hidden'; synergy.name = 'synergy'; synergy.value = '0';
      form.append(synergy);
    }
  }));
  document.querySelectorAll('.builder-results form').forEach(form => form.addEventListener('submit', () => {
    try { sessionStorage.setItem('builder-return-scroll', String(window.scrollY)); } catch (_) {}
  }));
  const selection = window.builderSelection || {};
  if (window.builderHasCommander === false && !window.builderChoosingCommander) {
    document.querySelectorAll('.builder-results article form').forEach(form => {
      form.hidden = true;
    });
    const results = document.querySelector('.builder-results');
    if (results && !document.querySelector('.commander-required-note')) {
      const note = document.createElement('p');
      note.className = 'commander-required-note';
      note.textContent = 'Escolha uma comandante para liberar a adição de cartas às candidatas.';
      note.style.cssText = 'margin:18px 0;padding:12px 14px;border-radius:8px;background:#fff8e9;color:var(--ink);font-weight:700';
      results.before(note);
    }
  }
  const exploreSelection = window.builderExploreSelection || {};
  if (Object.keys(exploreSelection).length) {
    document.querySelectorAll('.builder-results article').forEach(article => {
      const link = article.querySelector('a[href*="/card.php?id="]');
      const id = link?.href.match(/[?&]id=([^&]+)/)?.[1];
      const selected = id && exploreSelection[id];
      if (!selected || article.querySelector('.builder-selection-status')) return;
      const status = document.createElement('span');
      status.className = 'builder-selection-status';
      status.style.cssText = 'display:block;margin:0 0 8px;color:var(--accent);font-size:.78rem;font-weight:800';
      status.textContent = 'Já está em ' + selected.label.toLowerCase();
      article.querySelector('h3')?.after(status);
      const button = article.querySelector('form button');
      if (button) { button.disabled = true; button.textContent = 'Já adicionada · ' + selected.label.toLowerCase(); }
    });
  }
  const exploreSynergy = window.builderExploreSynergy || {};
  if (Object.keys(exploreSynergy).length) {
    document.querySelectorAll('.builder-results article').forEach(article => {
      const link = article.querySelector('a[href*="/card.php?id="]');
      const id = link?.href.match(/[?&]id=([^&]+)/)?.[1];
      const metric = id && exploreSynergy[id];
      if (!metric || article.querySelector('.builder-synergy')) return;
      const badge = document.createElement('span');
      badge.className = 'builder-synergy';
      badge.style.cssText = 'display:inline-flex;margin:0 0 8px;padding:4px 8px;border-radius:999px;background:#e7efe8;color:var(--accent);font-size:.74rem;font-weight:800';
      badge.textContent = metric.metric === 'lift'
        ? 'Lift EDHREC: ' + Number(metric.score).toLocaleString('pt-BR', { maximumFractionDigits: 2 })
        : 'Sinergia EDHREC: ' + (metric.score >= 0 ? '+' : '') + Math.round(Number(metric.score) * 100) + '%';
      article.querySelector('h3')?.after(badge);
    });
  }
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
