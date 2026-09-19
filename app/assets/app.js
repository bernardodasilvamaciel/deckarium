(() => {
  const sidebar = document.querySelector('[data-sidebar]');
  const sidebarToggle = document.querySelector('[data-sidebar-toggle]');
  const sidebarScrim = document.querySelector('[data-sidebar-scrim]');
  if (sidebar && sidebarToggle) {
    const mobileQuery = window.matchMedia('(max-width: 850px)');
    const readState = () => {
      if (mobileQuery.matches) return true;
      try {
        const saved = localStorage.getItem(mobileQuery.matches ? 'deckarium:sidebar-mobile-collapsed' : 'deckarium:sidebar-desktop-collapsed');
        return saved === null ? mobileQuery.matches : saved === 'true';
      } catch (_) { return mobileQuery.matches; }
    };
    const setCollapsed = (collapsed, persist = true) => {
      sidebar.classList.toggle('is-collapsed', collapsed);
      document.body.classList.toggle('sidebar-collapsed', collapsed);
      sidebarToggle.setAttribute('aria-expanded', String(!collapsed));
      const label = sidebarToggle.querySelector('[data-sidebar-toggle-label]');
      if (label) label.textContent = collapsed ? 'Expandir navegação' : 'Recolher navegação';
      if (sidebarScrim) sidebarScrim.hidden = collapsed || !mobileQuery.matches;
      if (persist && !mobileQuery.matches) try { localStorage.setItem(mobileQuery.matches ? 'deckarium:sidebar-mobile-collapsed' : 'deckarium:sidebar-desktop-collapsed', String(collapsed)); } catch (_) {}
    };
    setCollapsed(readState(), false);
    sidebarToggle.addEventListener('click', () => setCollapsed(!sidebar.classList.contains('is-collapsed')));
    sidebarScrim?.addEventListener('click', () => setCollapsed(true));
    document.addEventListener('keydown', event => {
      if (event.key === 'Escape' && mobileQuery.matches && !sidebar.classList.contains('is-collapsed')) {
        setCollapsed(true);
        sidebarToggle.focus();
      }
    });
    mobileQuery.addEventListener('change', () => setCollapsed(readState(), false));
  }

  // Listas, opções e caixas marcadas com data-auto-submit aplicam o filtro na hora.
  document.querySelectorAll('[data-auto-submit]').forEach(field => {
    field.addEventListener('change', () => field.form?.requestSubmit());
  });

  // Filtros da grade de impressões: só no navegador, sem recarregar a página.
  const printingsPanel = document.querySelector('[data-printings]');
  if (printingsPanel) {
    const grid = printingsPanel.querySelector('[data-printing-grid]');
    const empty = printingsPanel.querySelector('[data-printing-empty]');
    const search = printingsPanel.querySelector('[data-printing-filter]');
    const langSelect = printingsPanel.querySelector('[data-printing-lang]');
    const sortSelect = printingsPanel.querySelector('[data-printing-sort]');
    const ownedOnly = printingsPanel.querySelector('[data-printing-owned]');
    const tiles = [...grid.querySelectorAll('.printing-tile')];
    const price = tile => { const value = parseFloat(tile.dataset.price); return Number.isFinite(value) ? value : null; };
    const apply = () => {
      const term = (search?.value || '').trim().toLowerCase();
      const lang = langSelect?.value || '';
      let shown = 0;
      tiles.forEach(tile => {
        const visible = (!term || tile.dataset.search.includes(term))
          && (!lang || tile.dataset.lang === lang)
          && (!ownedOnly?.checked || Number(tile.dataset.owned) > 0);
        tile.hidden = !visible;
        if (visible) shown++;
      });
      if (empty) empty.hidden = shown > 0;
      const mode = sortSelect?.value || 'default';
      const ordered = [...tiles].sort((a, b) => {
        if (mode === 'cheap' || mode === 'expensive') {
          const first = price(a), second = price(b);
          // Impressões sem cotação ficam no fim nas duas ordens.
          if (first === null || second === null) return (first === null) - (second === null);
          return mode === 'cheap' ? first - second : second - first;
        }
        if (mode === 'set') return a.dataset.set.localeCompare(b.dataset.set);
        if (mode === 'old') return (a.dataset.released || '').localeCompare(b.dataset.released || '');
        return Number(a.dataset.order) - Number(b.dataset.order);
      });
      ordered.forEach(tile => grid.append(tile));
    };
    [search, langSelect, sortSelect, ownedOnly].forEach(field => {
      field?.addEventListener('input', apply);
      field?.addEventListener('change', apply);
    });
  }

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
  document.querySelectorAll('[data-deck-delete]').forEach(trigger => {
    const dialog = document.getElementById(trigger.dataset.deckDelete);
    if (!(dialog instanceof HTMLDialogElement)) return;
    trigger.addEventListener('click', () => dialog.showModal());
    dialog.querySelectorAll('[data-dialog-close]').forEach(button => button.addEventListener('click', () => dialog.close()));
    dialog.addEventListener('click', event => { if (event.target === dialog) dialog.close(); });
  });
  // Formulários em janela (Novo deck, Importar lista): vários gatilhos podem abrir a mesma janela.
  // Sem fechar ao clicar fora, para não perder uma lista colada por acidente.
  document.querySelectorAll('dialog.deck-form-dialog').forEach(dialog => {
    dialog.querySelectorAll('[data-dialog-close]').forEach(button => button.addEventListener('click', () => dialog.close()));
    if (dialog.hasAttribute('data-open-on-load')) dialog.showModal();
  });
  document.querySelectorAll('[data-dialog-open]').forEach(trigger => {
    const dialog = document.getElementById(trigger.dataset.dialogOpen);
    if (!(dialog instanceof HTMLDialogElement)) return;
    trigger.addEventListener('click', () => {
      dialog.showModal();
      dialog.querySelector('input:not([type=hidden]), textarea')?.focus();
    });
  });
  // Copiar o link público (deck ou coleção) com endereço completo.
  document.querySelectorAll('[data-copy-share]').forEach(button => {
    button.addEventListener('click', async () => {
      const path = button.dataset.copyShare || button.closest('.deck-share-link')?.querySelector('[data-share-url]')?.value || '';
      const url = new URL(path, window.location.origin).href;
      try { await navigator.clipboard.writeText(url); } catch (_) { window.prompt('Copie o link:', url); return; }
      const label = button.textContent;
      button.textContent = 'Link copiado';
      window.setTimeout(() => { button.textContent = label; }, 1800);
    });
  });
  document.querySelectorAll('[data-password-toggle]').forEach(toggle => {
    const input = toggle.parentElement?.querySelector('input');
    if (!input) return;
    toggle.setAttribute('aria-label', 'Mostrar senha');
    toggle.addEventListener('click', () => {
      const visible = input.type === 'password';
      input.type = visible ? 'text' : 'password';
      toggle.textContent = visible ? 'Ocultar' : 'Mostrar';
      toggle.setAttribute('aria-pressed', String(visible));
      toggle.setAttribute('aria-label', visible ? 'Ocultar senha' : 'Mostrar senha');
      input.focus({ preventScroll: true });
    });
    input.form?.addEventListener('submit', () => { input.type = 'password'; });
  });
  document.querySelectorAll('[data-commander-guide]').forEach(guide => {
    const tabs = [...guide.querySelectorAll('[data-guide-tab]')];
    const panels = [...guide.querySelectorAll('[data-guide-panel]')];
    if (!tabs.length) return;
    guide.classList.add('js-guide');
    const tabKey = 'deckarium:guide-tab';
    const openKey = 'deckarium:guide-open:' + guide.dataset.commanderGuide;
    const select = (name, focus = false) => {
      tabs.forEach(tab => {
        const active = tab.dataset.guideTab === name;
        tab.setAttribute('aria-selected', String(active));
        tab.tabIndex = active ? 0 : -1;
        if (active && focus) tab.focus();
      });
      panels.forEach(panel => { panel.hidden = panel.dataset.guidePanel !== name; });
      try { localStorage.setItem(tabKey, name); } catch (error) { /* armazenamento indisponível */ }
    };
    let initial = 'plans';
    try {
      const saved = localStorage.getItem(tabKey);
      if (saved && tabs.some(tab => tab.dataset.guideTab === saved)) initial = saved;
      if (localStorage.getItem(openKey) === '0' && !guide.hasAttribute('data-guide-standalone')) guide.open = false;
    } catch (error) { /* armazenamento indisponível */ }
    select(initial);
    tabs.forEach((tab, index) => {
      tab.addEventListener('click', () => select(tab.dataset.guideTab));
      tab.addEventListener('keydown', event => {
        if (!['ArrowRight', 'ArrowLeft', 'Home', 'End'].includes(event.key)) return;
        event.preventDefault();
        const next = event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1 : (index + (event.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length;
        select(tabs[next].dataset.guideTab, true);
      });
    });
    guide.addEventListener('toggle', () => { try { localStorage.setItem(openKey, guide.open ? '1' : '0'); } catch (error) { /* armazenamento indisponível */ } });
  });
  document.querySelectorAll('[data-deck-needs]').forEach(panel => {
    const tabs = [...panel.querySelectorAll('[data-need-tab]')];
    const panels = [...panel.querySelectorAll('[data-need-panel]')];
    if (!tabs.length) return;
    panel.classList.add('js-needs');
    const select = (name, focus = false) => {
      tabs.forEach(tab => {
        const active = tab.dataset.needTab === name;
        tab.setAttribute('aria-selected', String(active));
        tab.tabIndex = active ? 0 : -1;
        if (active && focus) tab.focus();
      });
      panels.forEach(item => { item.hidden = item.dataset.needPanel !== name; });
    };
    select((tabs.find(tab => tab.getAttribute('aria-selected') === 'true') || tabs[0]).dataset.needTab);
    tabs.forEach((tab, index) => {
      tab.addEventListener('click', () => select(tab.dataset.needTab));
      tab.addEventListener('keydown', event => {
        if (!['ArrowRight', 'ArrowLeft', 'Home', 'End'].includes(event.key)) return;
        event.preventDefault();
        const next = event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1 : (index + (event.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length;
        select(tabs[next].dataset.needTab, true);
      });
    });
  });
  document.querySelectorAll('[data-dialog-open]').forEach(trigger => {
    const dialog = document.getElementById(trigger.dataset.dialogOpen);
    if (!(dialog instanceof HTMLDialogElement)) return;
    trigger.addEventListener('click', () => dialog.showModal());
    dialog.querySelectorAll('[data-dialog-close]').forEach(button => button.addEventListener('click', () => dialog.close()));
    dialog.addEventListener('click', event => { if (event.target === dialog) dialog.close(); });
  });
  // Metas automáticas × personalizadas: editar um número passa para "Personalizadas"; voltar ao automático restaura os valores calculados.
  document.querySelectorAll('[data-targets-mode]').forEach(fieldset => {
    const form = fieldset.closest('form');
    if (!form) return;
    const inputs = [...form.querySelectorAll('input[data-auto-value]')];
    const radio = value => fieldset.querySelector(`input[name="targets_mode"][value="${value}"]`);
    inputs.forEach(input => input.addEventListener('input', () => { const manual = radio('manual'); if (manual) manual.checked = true; }));
    radio('auto')?.addEventListener('change', () => inputs.forEach(input => { if (input.dataset.autoValue !== '') input.value = input.dataset.autoValue; }));
  });
  document.querySelectorAll('[data-fit-config]').forEach(form => {
    let presets = {};
    try { presets = JSON.parse(form.dataset.presets || '{}'); } catch (_) {}
    const status = form.querySelector('[data-fit-preview-status]');
    const planOutput = form.querySelector('[data-fit-plan]');
    const workspace = document.querySelector('[data-selection-workspace]');
    const stage = new URLSearchParams(location.search).get('stage') || 'candidate';
    let timer = null;
    let controller = null;
    const syncOutputs = () => {
      form.querySelectorAll('[data-fit-weight]').forEach(input => {
        const output = form.querySelector(`[data-fit-output="${input.dataset.fitWeight}"]`);
        if (output) output.textContent = input.value;
      });
      if (planOutput) {
        const used = [...form.querySelectorAll('input[name^="targets["]')].reduce((sum, input) => sum + (Number(input.value) || 0), 0);
        planOutput.textContent = String(Math.max(0, 99 - used));
      }
    };
    const preview = () => {
      window.clearTimeout(timer);
      timer = window.setTimeout(async () => {
        controller?.abort();
        controller = new AbortController();
        const body = new FormData(form);
        body.set('action', 'score_preview');
        if (status) status.textContent = 'Recalculando…';
        try {
          const response = await fetch(form.getAttribute('action') || location.href, { method: 'POST', body, signal: controller.signal, credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch', Accept: 'application/json' } });
          const data = await response.json();
          if (!response.ok || !data.ok) throw new Error(data.message || 'Não foi possível recalcular.');
          const counts = { advance: 0, review: 0, hold: 0, blocked: 0 };
          Object.entries(data.cards).forEach(([id, card]) => {
            if (card.stage === stage) counts[card.band] = (counts[card.band] || 0) + 1;
            document.querySelectorAll(`[data-fit-card="${id}"]`).forEach(badge => { badge.textContent = card.score; badge.className = 'fit-badge is-' + card.band + ' is-preview'; });
            document.querySelectorAll(`[data-fit-label="${id}"]`).forEach(label => { label.textContent = card.label; label.className = 'fit-band-text is-' + card.band; });
          });
          Object.entries(counts).forEach(([band, count]) => document.querySelectorAll(`[data-fit-count="${band}"]`).forEach(node => { node.textContent = count; }));
          document.querySelectorAll('[data-fit-suggestions]').forEach(node => { node.textContent = data.suggestions; });
          if (status) status.textContent = `Prévia: ${counts.advance} avançar · ${counts.review} avaliar · ${counts.hold} segurar. Salve para manter.`;
          workspace?.classList.add('has-fit-preview');
        } catch (error) {
          if (error.name !== 'AbortError' && status) status.textContent = error.message;
        }
      }, 350);
    };
    form.querySelectorAll('input[name="preset"]').forEach(radio => radio.addEventListener('change', () => {
      const preset = presets[radio.value];
      if (!preset) return;
      Object.entries(preset.weights).forEach(([key, value]) => { const input = form.querySelector(`[data-fit-weight="${key}"]`); if (input) input.value = value; });
      const bracket = form.querySelector('[data-fit-bracket]');
      if (bracket && preset.bracket) bracket.value = preset.bracket;
      syncOutputs();
      preview();
    }));
    form.addEventListener('input', event => {
      if (event.target.matches('[data-fit-weight]')) {
        const custom = form.querySelector('input[name="preset"][value="custom"]');
        if (custom) custom.checked = true;
      }
      if (event.target.name !== 'preset') { syncOutputs(); preview(); }
    });
  });
  // As janelas das cartas ficam fora dos grupos: assim abrem em qualquer visualização (grupo fechado ou oculto).
  document.querySelectorAll('[data-selection-workspace] dialog.selection-dialog').forEach(dialog => dialog.closest('[data-selection-workspace]').append(dialog));
  document.querySelectorAll('[data-selection-open]').forEach(trigger => {
    const dialog = document.getElementById(trigger.dataset.selectionOpen);
    if (!(dialog instanceof HTMLDialogElement)) return;
    trigger.addEventListener('click', () => { dialog.deckariumOpener = trigger; dialog.showModal(); });
    if (dialog.dataset.dialogReady) return;
    dialog.dataset.dialogReady = '1';
    dialog.querySelectorAll('[data-dialog-close]').forEach(button => button.addEventListener('click', () => dialog.close()));
    dialog.addEventListener('click', event => { if (event.target === dialog) dialog.close(); });
    dialog.addEventListener('close', () => dialog.deckariumOpener?.focus({ preventScroll: true }));
  });
  /* Completar com terrenos: a prévia (GET) e a aplicação (POST) compartilham meta, compra e terrenos desmarcados. */
  const landDialog = document.querySelector('[data-land-dialog]');
  if (landDialog instanceof HTMLDialogElement) {
    const previewForm = landDialog.querySelector('[data-land-preview]');
    const applyForm = landDialog.querySelector('[data-land-apply]');
    const carry = (target, name, value) => { const input = document.createElement('input'); input.type = 'hidden'; input.name = name; input.value = value; target.append(input); };
    previewForm?.addEventListener('submit', () => {
      applyForm?.querySelectorAll('input[name="land_shown[]"]').forEach(input => carry(previewForm, 'land_shown[]', input.value));
      applyForm?.querySelectorAll('input[name="land_keep[]"]:checked').forEach(input => carry(previewForm, 'land_keep[]', input.value));
    });
    applyForm?.addEventListener('submit', () => {
      const total = previewForm?.querySelector('input[name="land_total"]');
      const buy = previewForm?.querySelector('input[name="land_buy"]');
      applyForm.querySelectorAll('input[type="hidden"][name="land_total"], input[type="hidden"][name="land_buy"]').forEach(input => input.remove());
      if (total) carry(applyForm, 'land_total', total.value);
      if (buy) { carry(applyForm, 'land_buy_set', '1'); if (buy.checked) carry(applyForm, 'land_buy', '1'); }
    });
    const params = new URLSearchParams(location.search);
    if (location.hash === '#land-fill' || params.has('land_skip[]')) {
      landDialog.deckariumOpener = document.querySelector('[data-selection-open="land-fill"]');
      landDialog.showModal();
    }
  }
  const selectionWorkspace = document.querySelector('[data-selection-workspace]');
  if (selectionWorkspace) {
    /* Visualizações: por tipo (grupos recolhíveis), cartas grandes (tudo aberto) e mapa de jogo. */
    const layoutKey = 'deckarium:selection-layout';
    const layoutButtons = [...selectionWorkspace.querySelectorAll('[data-layout]')];
    const typeGroups = [...selectionWorkspace.querySelectorAll('[data-selection-group]')];
    let savedOpen = null;
    const applyLayout = (layout, remember) => {
      if (!layoutButtons.some(button => button.dataset.layout === layout)) layout = 'types';
      const previous = selectionWorkspace.dataset.layout || 'types';
      if (layout === 'large' && previous !== 'large') { savedOpen = typeGroups.map(group => group.open); typeGroups.forEach(group => { group.open = true; }); }
      if (layout !== 'large' && previous === 'large' && savedOpen) { typeGroups.forEach((group, index) => { group.open = savedOpen[index]; }); savedOpen = null; }
      selectionWorkspace.dataset.layout = layout;
      layoutButtons.forEach(button => button.setAttribute('aria-pressed', String(button.dataset.layout === layout)));
      selectionWorkspace.querySelectorAll('[data-layout-panel]').forEach(panel => { panel.hidden = !panel.dataset.layoutPanel.split(' ').includes(layout); });
      selectionWorkspace.querySelectorAll('[data-layout-only]').forEach(node => { node.hidden = node.dataset.layoutOnly !== layout; });
      if (remember) { try { localStorage.setItem(layoutKey, layout); } catch (error) { /* armazenamento indisponível */ } }
    };
    layoutButtons.forEach(button => button.addEventListener('click', () => applyLayout(button.dataset.layout, true)));

    /* Lista em texto: ordenação e prévia da carta seguindo o mouse. */
    const textPanel = selectionWorkspace.querySelector('[data-selection-text]');
    if (textPanel) {
      const list = textPanel.querySelector('[data-text-list]');
      const sort = textPanel.querySelector('[data-text-sort]');
      const rows = [...list.querySelectorAll('li')];
      const value = (row, name) => row.querySelector('.selection-text-row').dataset[name];
      const price = row => { const parsed = parseFloat(value(row, 'price')); return Number.isFinite(parsed) ? parsed : null; };
      sort?.addEventListener('change', () => {
        const mode = sort.value;
        [...rows].sort((a, b) => {
          if (mode === 'price-desc' || mode === 'price-asc') {
            const first = price(a), second = price(b);
            // Cartas sem cotação ficam no fim das duas ordens.
            if (first === null || second === null) return (first === null) - (second === null);
            return mode === 'price-desc' ? second - first : first - second;
          }
          if (mode === 'cmc') return parseFloat(value(b, 'cmc')) - parseFloat(value(a, 'cmc'));
          if (mode === 'name') return value(a, 'name').localeCompare(value(b, 'name'));
          return Number(value(a, 'order')) - Number(value(b, 'order'));
        }).forEach(row => list.append(row));
      });

      // Uma única prévia, reaproveitada por todas as linhas.
      const preview = document.createElement('img');
      preview.className = 'selection-text-preview';
      preview.alt = '';
      preview.hidden = true;
      document.body.append(preview);
      const place = event => {
        const margin = 16;
        const width = preview.offsetWidth || 244;
        const height = preview.offsetHeight || 340;
        const left = event.clientX + margin + width > window.innerWidth ? event.clientX - margin - width : event.clientX + margin;
        const top = Math.min(Math.max(margin, event.clientY - height / 2), window.innerHeight - height - margin);
        preview.style.transform = `translate(${Math.max(margin, left)}px, ${Math.max(margin, top)}px)`;
      };
      list.addEventListener('pointerover', event => {
        if (event.pointerType !== 'mouse') return;
        const row = event.target.closest('.selection-text-row');
        if (!row || !row.dataset.preview) return;
        preview.src = row.dataset.preview;
        preview.hidden = false;
        place(event);
      });
      list.addEventListener('pointermove', event => { if (!preview.hidden) place(event); });
      list.addEventListener('pointerout', event => {
        if (event.relatedTarget?.closest?.('.selection-text-row')) return;
        preview.hidden = true;
      });
      window.addEventListener('scroll', () => { preview.hidden = true; }, { passive: true });
    }
    // Em "Cartas grandes" os grupos ficam sempre abertos.
    typeGroups.forEach(group => group.querySelector('summary')?.addEventListener('click', event => { if (selectionWorkspace.dataset.layout === 'large') event.preventDefault(); }));
    let storedLayout = 'types';
    try { storedLayout = localStorage.getItem(layoutKey) || 'types'; } catch (error) { storedLayout = 'types'; }

    const groups = [...selectionWorkspace.querySelectorAll('[data-selection-group]')];
    const storageKey = 'deckarium:selection-open:' + selectionWorkspace.dataset.selectionWorkspace;
    let remembered = [];
    try { remembered = JSON.parse(localStorage.getItem(storageKey) || '[]'); } catch (error) { remembered = []; }
    groups.forEach(group => { if (remembered.includes(group.dataset.selectionGroup)) group.open = true; });
    const persist = () => {
      try { localStorage.setItem(storageKey, JSON.stringify(groups.filter(group => group.open).map(group => group.dataset.selectionGroup))); } catch (error) { /* armazenamento indisponível */ }
    };
    groups.forEach(group => group.addEventListener('toggle', () => { if (selectionWorkspace.dataset.layout !== 'large') persist(); }));
    if (layoutButtons.length) applyLayout(storedLayout, false);
    selectionWorkspace.querySelector('[data-selection-expand]')?.addEventListener('click', () => { groups.forEach(group => { group.open = true; }); persist(); });
    selectionWorkspace.querySelector('[data-selection-collapse]')?.addEventListener('click', () => { groups.forEach(group => { group.open = false; }); persist(); });

    /* Movimentação em massa: marca cartas e move todas de uma vez. */
    const bulkForm = selectionWorkspace.querySelector('[data-bulk-bar]');
    const bulkToggle = selectionWorkspace.querySelector('[data-bulk-toggle]');
    if (bulkForm && bulkToggle) {
      const boxes = [...selectionWorkspace.querySelectorAll('[data-bulk-card]')];
      const countNode = bulkForm.querySelector('[data-bulk-count]');
      const warning = bulkForm.querySelector('[data-bulk-warning]');
      const openSlots = Number(bulkForm.dataset.openSlots || 0);
      const groupBoxes = key => boxes.filter(box => box.closest('.selection-type-wrap')?.querySelector('[data-bulk-group]')?.dataset.bulkGroup === key);
      const update = () => {
        const chosen = boxes.filter(box => box.checked);
        const copies = chosen.reduce((sum, box) => sum + Number(box.dataset.quantity || 1), 0);
        boxes.forEach(box => {
          box.closest('.selection-slot')?.classList.toggle('is-picked', box.checked);
          selectionWorkspace.querySelectorAll(`[data-map-card="${box.value}"]`).forEach(card => card.classList.toggle('is-picked', box.checked));
        });
        countNode.textContent = chosen.length ? `${chosen.length} ${chosen.length === 1 ? 'carta marcada' : 'cartas marcadas'}` : 'Nenhuma carta marcada';
        bulkForm.querySelectorAll('[data-bulk-submit]').forEach(button => { button.disabled = !chosen.length; });
        const deckButton = bulkForm.querySelector('[data-bulk-submit="deck"]');
        const overflow = deckButton && copies > openSlots;
        warning.hidden = !overflow;
        if (overflow) warning.textContent = openSlots ? `O deck tem ${openSlots} ${openSlots === 1 ? 'vaga' : 'vagas'}: entram as primeiras na ordem da tela e o restante fica nas candidatas.` : 'O deck já tem 100 cartas. Use “Preparar upgrade” numa carta para trocar.';
        selectionWorkspace.querySelectorAll('[data-bulk-group]').forEach(button => {
          const own = groupBoxes(button.dataset.bulkGroup);
          const all = own.length && own.every(box => box.checked);
          button.classList.toggle('is-active', all);
          button.setAttribute('aria-pressed', String(all));
        });
      };
      const setMode = on => {
        selectionWorkspace.classList.toggle('is-bulk', on);
        bulkForm.hidden = !on;
        bulkToggle.setAttribute('aria-pressed', String(on));
        bulkToggle.textContent = on ? 'Sair da seleção' : 'Selecionar várias';
        if (!on) boxes.forEach(box => { box.checked = false; });
        update();
      };
      bulkToggle.addEventListener('click', () => setMode(!selectionWorkspace.classList.contains('is-bulk')));
      bulkForm.querySelector('[data-bulk-exit]')?.addEventListener('click', () => { setMode(false); bulkToggle.focus(); });
      bulkForm.querySelector('[data-bulk-all]')?.addEventListener('click', () => { boxes.forEach(box => { box.checked = true; }); update(); });
      bulkForm.querySelector('[data-bulk-none]')?.addEventListener('click', () => { boxes.forEach(box => { box.checked = false; }); update(); });
      bulkForm.querySelector('[data-bulk-band]')?.addEventListener('click', event => {
        const band = event.currentTarget.dataset.bulkBand;
        boxes.forEach(box => { box.checked = Boolean(box.closest('.selection-slot')?.querySelector(`.fit-badge.is-${band}`)); });
        update();
      });
      selectionWorkspace.querySelectorAll('[data-bulk-group]').forEach(button => button.addEventListener('click', () => {
        const own = groupBoxes(button.dataset.bulkGroup);
        const check = !own.every(box => box.checked);
        own.forEach(box => { box.checked = check; });
        update();
      }));
      boxes.forEach(box => box.addEventListener('change', update));
      // No modo de seleção, clicar na carta marca/desmarca em vez de abrir o modal.
      selectionWorkspace.addEventListener('click', event => {
        if (!selectionWorkspace.classList.contains('is-bulk')) return;
        const tile = event.target instanceof Element ? event.target.closest('button[data-selection-open]') : null;
        if (!tile || tile.closest('dialog') || tile.dataset.selectionOpen === 'land-fill') return;
        event.preventDefault();
        event.stopPropagation();
        const box = tile.closest('.selection-slot')?.querySelector('[data-bulk-card]') || (tile.dataset.mapCard ? boxes.find(candidate => candidate.value === tile.dataset.mapCard) : null);
        if (box) { box.checked = !box.checked; update(); }
      }, true);
      document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && selectionWorkspace.classList.contains('is-bulk') && !document.querySelector('dialog[open]')) setMode(false);
      });
      bulkForm.addEventListener('submit', event => { if (!boxes.some(box => box.checked)) event.preventDefault(); });
      update();
    }
  }
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
    if (event.target instanceof Element && event.target.closest('[data-profile-preview]')) return;
    unavailable(event.target);
  }, true);
  document.addEventListener('error', event => {
    const image = event.target;
    if (image instanceof HTMLImageElement && image.classList.contains('set-icon')) {
      // Imagens com onerror próprio (ex.: logo do Deckarium no lugar do símbolo) cuidam do próprio erro.
      if (image.dataset.fallbackSrc || image.dataset.genericSrc || image.hasAttribute('onerror')) return;
      image.hidden = true;
      const fallback = image.nextElementSibling;
      if (fallback) fallback.hidden = false;
    }
  }, true);
  document.querySelectorAll('img').forEach(img => {
    if (img.complete && img.naturalWidth === 0 && img.getAttribute('src') && !img.closest('[data-profile-preview]')) unavailable(img);
  });
  document.addEventListener('keydown', event => {
    if (event.key !== '/' || event.ctrlKey || event.metaKey || event.altKey || event.target.closest('input,textarea,select,[contenteditable]')) return;
    const search = document.querySelector('input[type="search"]');
    if (search) { event.preventDefault(); search.focus(); }
  });
  document.querySelectorAll('.view-toggle a.active,.tabs a.active').forEach(link => link.setAttribute('aria-current','true'));
  document.querySelectorAll('form.search').forEach(form => form.addEventListener('submit', () => {
    const button = form.querySelector('button');
    if (button) { button.dataset.label = button.textContent; button.textContent = 'Buscando…'; button.setAttribute('aria-busy','true'); }
  }));
  document.querySelectorAll('form.builder-search').forEach((form, index) => {
    form.id = form.id || 'builder-search-form-' + index;
    const sort = form.querySelector('[data-builder-sort]');
    if (sort) {
      sort.addEventListener('change', () => form.requestSubmit());
    }
    form.addEventListener('submit', () => {
      const button = form.querySelector('button[type="submit"],button:not([type])');
      if (button) { button.dataset.label = button.textContent; button.textContent = 'Aplicando…'; button.setAttribute('aria-busy','true'); }
    });
  });
  document.querySelectorAll('.builder-results form').forEach(form => form.addEventListener('submit', event => {
    if (event.defaultPrevented) return;
    try { sessionStorage.setItem('builder-return-scroll', String(window.scrollY)); } catch (_) {}
  }));

  /* ---------- Carregamento de páginas e ações demoradas ---------- */
  const loadingMessages = {
    commander: ['Definindo a comandante', 'Buscando temas, combos e novidades no EDHREC.'],
    sync_edhrec: ['Consultando o EDHREC', 'Atualizando sinergias, temas e combos da comandante.'],
    import: ['Importando a coleção', 'Conferindo cada impressão com o catálogo local.'],
    import_deck: ['Importando a lista', 'Localizando as cartas e as impressões da sua coleção.'],
    create: ['Criando o deck', ''],
    create_commander_deck: ['Abrindo o deck', 'Buscando temas, combos e novidades no EDHREC.'],
    delete_deck: ['Excluindo o deck', ''],
    move: ['Movendo a carta', ''],
    bulk_move: ['Movendo as cartas marcadas', 'Conferindo vagas do deck e regras de cópia.'],
    item: ['Salvando a carta', ''],
    remove: ['Retirando da seleção', ''],
    strategy: ['Salvando a intenção', ''],
    apply_upgrade: ['Aplicando o upgrade', ''],
    confirm_upgrade: ['Registrando o upgrade', ''],
    prepare_upgrade: ['Preparando o upgrade', ''],
  };
  let loadingTimer = null;
  let loadingShell = null;
  const ensureLoadingShell = () => {
    if (loadingShell) return loadingShell;
    loadingShell = document.createElement('div');
    loadingShell.className = 'page-loading';
    loadingShell.hidden = true;
    loadingShell.innerHTML = '<div class="page-loading-bar" aria-hidden="true"></div><div class="page-loading-card" role="status" aria-live="polite"><div class="page-loading-cards" aria-hidden="true"><i></i><i></i><i></i></div><strong></strong><span></span></div>';
    document.body.append(loadingShell);
    return loadingShell;
  };
  const showLoading = (title = 'Carregando', detail = '', delay = 450) => {
    const shell = ensureLoadingShell();
    shell.querySelector('strong').textContent = title + '…';
    shell.querySelector('span').textContent = detail;
    shell.hidden = false;
    shell.classList.remove('is-visible');
    document.documentElement.classList.add('is-navigating');
    window.clearTimeout(loadingTimer);
    loadingTimer = window.setTimeout(() => shell.classList.add('is-visible'), delay);
  };
  const hideLoading = () => {
    window.clearTimeout(loadingTimer);
    document.documentElement.classList.remove('is-navigating');
    if (loadingShell) { loadingShell.classList.remove('is-visible'); loadingShell.hidden = true; }
  };
  window.addEventListener('pageshow', hideLoading);
  document.addEventListener('submit', event => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || event.defaultPrevented) return;
    const method = (form.getAttribute('method') || 'get').toLowerCase();
    if (method === 'dialog' || form.getAttribute('target') === '_blank' || form.hasAttribute('data-no-loading') || form.querySelector('[name="export"]')) return;
    const submitter = event.submitter;
    const action = (submitter?.name === 'action' ? submitter.value : '') || form.querySelector('input[name="action"]')?.value || '';
    const [title, detail] = loadingMessages[action] || (method === 'post' ? ['Salvando', ''] : ['Carregando', '']);
    showLoading(title, detail, method === 'post' ? 250 : 500);
  });
  document.addEventListener('click', event => {
    const link = event.target instanceof Element ? event.target.closest('a[href]') : null;
    if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    if (link.target && link.target !== '_self' || link.hasAttribute('download') || link.hasAttribute('data-no-loading')) return;
    const url = new URL(link.href, location.href);
    if (url.origin !== location.origin || /[?&](export|template)=/.test(url.search) || url.pathname.match(/\.(csv|txt|json|jpg|png|svg)$/)) return;
    if (url.pathname === location.pathname && url.search === location.search) return;
    showLoading('Carregando', '', 600);
  });

  /* ---------- Avisos rápidos ---------- */
  const toast = (message, type = 'ok', action = null) => {
    let region = document.querySelector('.toast-region');
    if (!region) {
      region = document.createElement('div');
      region.className = 'toast-region';
      region.setAttribute('role', 'status');
      region.setAttribute('aria-live', 'polite');
      document.body.append(region);
    }
    const item = document.createElement('div');
    item.className = 'toast is-' + type;
    const text = document.createElement('span');
    text.textContent = message;
    item.append(text);
    if (action) {
      const link = document.createElement('a');
      link.href = action.href; link.textContent = action.label;
      item.append(link);
    }
    region.append(item);
    window.setTimeout(() => item.classList.add('is-leaving'), 4200);
    window.setTimeout(() => item.remove(), 4700);
  };

  /* ---------- Adicionar às candidatas sem recarregar ---------- */
  document.querySelectorAll('form').forEach(form => {
    if (form.querySelector('input[name="action"]')?.value !== 'add' || !form.querySelector('input[name="card"]')) return;
    form.addEventListener('submit', async event => {
      event.preventDefault();
      const button = event.submitter || form.querySelector('button');
      if (!button || button.disabled || button.getAttribute('aria-busy') === 'true') return;
      const original = button.textContent;
      button.setAttribute('aria-busy', 'true');
      button.classList.add('is-busy');
      button.textContent = 'Adicionando…';
      try {
        // form.action seria o <input name="action">; por isso o atributo é lido diretamente.
        const response = await fetch(form.getAttribute('action') || location.href, {
          method: 'POST', body: new FormData(form), credentials: 'same-origin',
          headers: { 'X-Requested-With': 'fetch', Accept: 'application/json' },
        });
        const data = await response.json().catch(() => null);
        if (!data) throw new Error('fallback');
        if (!response.ok || !data.ok) throw Object.assign(new Error(data.message || 'Não foi possível adicionar a carta.'), { handled: true });
        const label = 'Já adicionada · ' + String(data.label || 'candidatas').toLowerCase();
        document.querySelectorAll('form input[name="action"][value="add"]').forEach(input => {
          const other = input.form;
          if (other?.querySelector('input[name="card"]')?.value !== data.card) return;
          const otherButton = other.querySelector('button');
          if (otherButton) { otherButton.disabled = true; otherButton.textContent = label; otherButton.removeAttribute('aria-busy'); otherButton.classList.remove('is-busy'); }
          const article = other.closest('article');
          if (article && !article.querySelector('.builder-selection-status')) {
            const status = document.createElement('span');
            status.className = 'builder-selection-status';
            status.textContent = 'Já está em ' + String(data.label || 'candidatas').toLowerCase();
            (article.querySelector('h3') || other).after(status);
          }
        });
        const deck = form.querySelector('input[name="deck"]')?.value;
        toast(data.message || 'Carta adicionada às candidatas.', 'ok', deck ? { href: '/decks.php?deck=' + encodeURIComponent(deck) + '&view=selection&stage=candidate#selection', label: 'Ver candidatas' } : null);
      } catch (error) {
        button.removeAttribute('aria-busy');
        button.classList.remove('is-busy');
        button.textContent = original;
        if (error.handled) { toast(error.message, 'error'); return; }
        // Sem resposta JSON (sessão expirada, rede): envia o formulário normalmente.
        HTMLFormElement.prototype.submit.call(form);
      }
    });
  });
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
  if (Object.keys(selection).length) {
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
      button.removeAttribute('aria-busy');
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

  const syncPanel = document.querySelector('[data-sync-panel]');
  if (syncPanel) {
    const checkUpdatesButton = syncPanel.querySelector('[data-check-updates]');
    const startButton = syncPanel.querySelector('[data-sync-start]');
    const forceWrap = syncPanel.querySelector('[data-sync-force-wrap]');
    const forceButton = syncPanel.querySelector('[data-sync-force]');
    const feedback = syncPanel.querySelector('[data-updates-feedback]');
    const label = syncPanel.querySelector('[data-sync-label]');
    const progress = syncPanel.querySelector('[data-sync-progress]');
    const barWrap = syncPanel.querySelector('[data-sync-bar-wrap]');
    const bar = syncPanel.querySelector('[data-sync-bar]');
    const detail = syncPanel.querySelector('[data-sync-detail]');
    const percentText = syncPanel.querySelector('[data-sync-percent]');
    const rows = syncPanel.querySelector('[data-sync-rows]');
    const steps = ['checking', 'downloading', 'importing', 'completed'];
    let wasActive = syncPanel.dataset.state && ['starting', 'checking', 'downloading', 'importing'].includes(syncPanel.dataset.state);
    let syncTimer = null;

    const renderRows = list => {
      if (!rows || !Array.isArray(list)) return;
      rows.replaceChildren(...(list.length ? list.map(row => {
        const tr = document.createElement('tr');
        [row.bulk_type, row.scryfall_updated_at, row.imported_at, row.card_count].forEach(value => { const td = document.createElement('td'); td.textContent = value; tr.append(td); });
        return tr;
      }) : [Object.assign(document.createElement('tr'), { innerHTML: '<td colspan="4">Nenhuma sincronização registrada.</td>' })]));
    };

    const renderSync = data => {
      syncPanel.dataset.state = data.state || '';
      if (label) { label.hidden = !data.state; label.textContent = data.label || ''; }
      const current = data.state === 'starting' ? 'checking' : data.state;
      const currentIndex = steps.indexOf(current);
      syncPanel.querySelectorAll('[data-sync-step]').forEach(step => {
        const index = steps.indexOf(step.dataset.syncStep);
        step.classList.toggle('is-done', currentIndex > index || data.state === 'completed');
        step.classList.toggle('is-current', currentIndex === index && data.state !== 'completed');
      });
      const showProgress = data.active || (wasActive && ['completed', 'error', 'interrupted'].includes(data.state));
      if (progress) progress.hidden = !showProgress;
      const note = syncPanel.querySelector('.sync-note');
      if (note) note.hidden = !data.active;
      const indeterminate = data.active && data.percent == null;
      barWrap?.classList.toggle('is-indeterminate', indeterminate);
      barWrap?.setAttribute('aria-valuenow', String(data.percent ?? 0));
      if (bar) bar.style.width = indeterminate ? '34%' : `${data.percent ?? 0}%`;
      if (percentText) percentText.textContent = data.percent == null ? '—' : `${data.percent}%`;
      if (detail) {
        detail.textContent = {
          starting: 'Iniciando o processo…',
          checking: 'Consultando o manifesto do Scryfall…',
          downloading: data.bytes_total ? `${formatBytes(data.bytes_downloaded)} de ${formatBytes(data.bytes_total)} baixados` : `${formatBytes(data.bytes_downloaded)} baixados`,
          importing: `${formatNumber(data.imported)} cartas processadas · ${formatNumber(data.added)} novas`,
          completed: `${formatNumber(data.imported)} cartas importadas`,
          error: 'Sincronização interrompida por um erro',
          interrupted: `Parou com ${formatNumber(data.imported)} cartas importadas`,
        }[data.state] || '';
      }
      if (startButton) {
        startButton.disabled = Boolean(data.active);
        startButton.textContent = data.active ? 'Atualizando…' : 'Baixar atualização';
      }
      if (checkUpdatesButton) checkUpdatesButton.disabled = Boolean(data.active);
      if (wasActive && !data.active) {
        if (data.state === 'completed') setFeedback(feedback, `Catálogo atualizado: ${formatNumber(data.imported)} registros processados, ${formatNumber(data.added)} ${data.added === 1 ? 'carta nova' : 'cartas novas'}.`, 'success');
        const historyLink = syncPanel.querySelector('[data-sync-history-link]');
        if (historyLink && data.run_id) historyLink.href = `/sync_history.php?run=${data.run_id}`;
        else if (data.last_error) setFeedback(feedback, data.last_error, 'error');
        renderRows(data.rows);
      }
      wasActive = Boolean(data.active);
    };

    const pollSync = async () => {
      window.clearTimeout(syncTimer);
      try {
        const data = await requestJson('/sync_progress.php');
        renderSync(data);
        if (data.active) syncTimer = window.setTimeout(pollSync, 1500);
      } catch (error) {
        setFeedback(feedback, error.message, 'error');
        syncTimer = window.setTimeout(pollSync, 5000);
      }
    };

    const startSync = async force => {
      if (startButton) { startButton.disabled = true; startButton.textContent = force ? 'Iniciando…' : 'Consultando Scryfall…'; }
      if (forceWrap) forceWrap.hidden = true;
      setFeedback(feedback, force ? 'Iniciando a reimportação…' : 'Verificando se há dados novos antes de baixar…');
      try {
        const data = await requestJson('/sync_control.php', { method: 'POST', body: new URLSearchParams({ force: force ? '1' : '0' }) });
        if (!data.started) {
          setFeedback(feedback, data.message, 'success');
          if (forceWrap) forceWrap.hidden = false;
          if (startButton) { startButton.disabled = false; startButton.textContent = 'Baixar atualização'; }
          return;
        }
        setFeedback(feedback, data.message, 'info');
        wasActive = true;
        await pollSync();
      } catch (error) {
        setFeedback(feedback, error.message, 'error');
        if (startButton) { startButton.disabled = false; startButton.textContent = 'Baixar atualização'; }
      }
    };

    startButton?.addEventListener('click', () => startSync(false));
    forceButton?.addEventListener('click', () => startSync(true));
    checkUpdatesButton?.addEventListener('click', async () => {
      checkUpdatesButton.disabled = true;
      checkUpdatesButton.textContent = 'Consultando Scryfall…';
      if (forceWrap) forceWrap.hidden = true;
      setFeedback(feedback, 'Verificando a versão mais recente do acervo…');
      try {
        const data = await requestJson('/check_updates.php');
        const remote = formatDate(data.remote_updated_at);
        setFeedback(feedback, data.has_update ? `Há uma atualização disponível no Scryfall (publicada em ${remote}). Use “Baixar atualização” para importá-la.` : `Seu acervo já está atualizado. Última publicação consultada: ${remote}.`, data.has_update ? 'warning' : 'success');
        if (!data.has_update && forceWrap) forceWrap.hidden = false;
        startButton?.classList.toggle('has-update', Boolean(data.has_update));
      } catch (error) {
        setFeedback(feedback, error.message, 'error');
      } finally {
        checkUpdatesButton.disabled = syncPanel.dataset.state && ['starting', 'checking', 'downloading', 'importing'].includes(syncPanel.dataset.state);
        checkUpdatesButton.textContent = 'Verificar atualizações';
      }
    });
    if (wasActive) pollSync();
  }
})();

/* Minha conta › Perfil público: prévia ao vivo de nome, foto, capa e enquadramento. */
document.querySelectorAll('[data-profile-editor]').forEach(editor => {
  const preview = editor.querySelector('[data-profile-preview]');
  const cover = editor.querySelector('[data-preview-cover]');
  const avatar = editor.querySelector('[data-preview-avatar]');
  const initials = editor.querySelector('[data-preview-initials]');
  const name = editor.querySelector('[data-preview-name]');
  const nameInput = editor.querySelector('[data-preview-source="name"]');
  const fallbackName = nameInput?.placeholder || '';
  nameInput?.addEventListener('input', () => { if (name) name.textContent = nameInput.value.trim() || fallbackName; });
  editor.querySelector('[data-preview-position]')?.addEventListener('input', event => preview?.style.setProperty('--cover-y', event.target.value + '%'));
  editor.querySelectorAll('[data-preview-file]').forEach(input => input.addEventListener('change', () => {
    const file = input.files?.[0];
    if (!file) return;
    if (file.size > 2 * 1024 * 1024) { input.setCustomValidity('A imagem passa de 2 MB.'); input.reportValidity(); input.value = ''; return; }
    input.setCustomValidity('');
    const url = URL.createObjectURL(file);
    if (input.dataset.previewFile === 'avatar' && avatar) { avatar.src = url; avatar.hidden = false; if (initials) initials.hidden = true; }
    if (input.dataset.previewFile === 'cover' && cover) {
      cover.src = url; cover.hidden = false; preview?.classList.add('has-cover'); preview?.classList.remove('is-card-art');
      const upload = editor.querySelector('input[name="cover_mode"][value="upload"]');
      if (upload) { upload.checked = true; syncModes(); }
    }
  }));
  const syncModes = () => {
    const mode = editor.querySelector('input[name="cover_mode"]:checked')?.value || 'none';
    editor.querySelectorAll('[data-cover-mode-only]').forEach(node => { node.hidden = node.dataset.coverModeOnly !== mode; });
  };
  editor.querySelectorAll('input[name="cover_mode"]').forEach(radio => radio.addEventListener('change', syncModes));
  syncModes();
  const bio = editor.querySelector('[data-bio-counter]');
  const count = editor.querySelector('[data-bio-count]');
  bio?.addEventListener('input', () => { if (count) count.textContent = String(bio.value.length); });
});
