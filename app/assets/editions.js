/* Edições: régua do espectrograma presa no topo, ano atual destacado, busca e filtros instantâneos. */
(() => {
  const page = document.querySelector('[data-timeline]');
  if (!page) return;
  const spectro = page.querySelector('[data-spectro]');
  const sentinel = page.querySelector('[data-spectro-sentinel]');
  const years = [...page.querySelectorAll('[data-timeline-year]')];
  const releases = [...page.querySelectorAll('.release')];
  const search = page.querySelector('[data-timeline-search]');
  const filters = [...page.querySelectorAll('[data-timeline-filter]')];
  const count = page.querySelector('[data-timeline-count]');
  const empty = page.querySelector('[data-timeline-empty]');
  const links = new Map([...page.querySelectorAll('[data-spectro-year]')].map((a) => [a.dataset.spectroYear, a]));
  const storeKey = 'deckarium:editions-filters';
  const fold = (text) => text.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();

  // Categorias escolhidas ficam lembradas neste navegador.
  try {
    const saved = JSON.parse(localStorage.getItem(storeKey) || 'null');
    if (Array.isArray(saved)) filters.forEach((input) => { input.checked = saved.includes(input.value); });
  } catch (_) { /* sem armazenamento: usa o padrão da página */ }

  const apply = () => {
    const wanted = new Set(filters.filter((input) => input.checked).map((input) => input.value));
    const query = fold(search.value);
    let shown = 0;
    releases.forEach((li) => {
      const visible = wanted.has(li.dataset.category) && (!query || li.dataset.search.includes(query));
      li.hidden = !visible;
      if (visible) shown += 1;
    });
    years.forEach((section) => {
      const visibleInYear = section.querySelectorAll('.release:not([hidden])').length;
      section.hidden = visibleInYear === 0;
      section.querySelector('[data-year-count]').textContent = `${visibleInYear} ${visibleInYear === 1 ? 'lançamento' : 'lançamentos'}`;
      links.get(section.dataset.timelineYear)?.classList.toggle('is-filtered-out', visibleInYear === 0);
    });
    count.textContent = `${shown.toLocaleString('pt-BR')} de ${releases.length.toLocaleString('pt-BR')} lançamentos`;
    empty.hidden = shown > 0;
  };
  let typing;
  search.addEventListener('input', () => { window.clearTimeout(typing); typing = window.setTimeout(apply, 120); });
  filters.forEach((input) => input.addEventListener('change', () => {
    try { localStorage.setItem(storeKey, JSON.stringify(filters.filter((i) => i.checked).map((i) => i.value))); } catch (_) { /* ignora */ }
    apply();
  }));
  apply();

  // Reimpressões: camada opcional sobre as cartas novas de cada ano (lembrada no navegador).
  const reprints = page.querySelector('[data-spectro-reprints]');
  const reprintKey = 'deckarium:editions-reprints';
  try { reprints.checked = localStorage.getItem(reprintKey) === '1'; } catch (_) { /* padrão: só novas */ }
  const syncReprints = () => spectro.classList.toggle('show-reprints', reprints.checked);
  reprints?.addEventListener('change', () => { syncReprints(); try { localStorage.setItem(reprintKey, reprints.checked ? '1' : '0'); } catch (_) { /* ignora */ } });
  syncReprints();

  // A régua encolhe quando o topo da página sai de vista. A diferença de altura vira margem inferior, aplicada
  // junto com a classe: o conteúdo abaixo não se move, então a rolagem não é "corrigida" pelo navegador.
  // A altura compacta é medida antes, numa cópia invisível; medir a própria régua no meio da troca fazia
  // a página subir por um instante e, perto do topo, a rolagem voltava a 0 (a régua "bugava" ao descer).
  const topbar = () => parseFloat(getComputedStyle(document.documentElement).getPropertyValue('--topbar')) || 0;
  let heights = null;
  const measure = () => {
    const probe = spectro.cloneNode(true);
    probe.classList.add('is-stuck');
    probe.removeAttribute('data-spectro');
    Object.assign(probe.style, { position: 'absolute', visibility: 'hidden', left: '0', top: '0', width: `${spectro.getBoundingClientRect().width}px`, marginBottom: '0', animation: 'none' });
    probe.setAttribute('aria-hidden', 'true');
    spectro.parentNode.append(probe);
    const stuck = probe.offsetHeight;
    probe.remove();
    const wasStuck = spectro.classList.contains('is-stuck');
    const full = wasStuck ? heights.full : spectro.offsetHeight;
    heights = { full, stuck, margin: heights?.margin ?? (parseFloat(getComputedStyle(spectro).marginBottom) || 0) };
    if (wasStuck) spectro.style.marginBottom = `${heights.margin + heights.full - heights.stuck}px`;
  };
  const setStuck = (stuck) => {
    if (stuck === spectro.classList.contains('is-stuck')) return;
    if (!heights) measure();
    spectro.classList.toggle('is-stuck', stuck);
    spectro.style.marginBottom = stuck ? `${heights.margin + heights.full - heights.stuck}px` : '';
  };
  const syncStuck = () => setStuck(sentinel.getBoundingClientRect().top < topbar() + 1);
  window.addEventListener('resize', () => {
    if (spectro.classList.contains('is-stuck')) { setStuck(false); heights = null; measure(); syncStuck(); } else heights = null;
  });

  // Destaca na régua o ano que está no alto da tela.
  let current = null;
  const setCurrent = (year) => {
    if (year === current) return;
    links.get(current)?.removeAttribute('aria-current');
    current = year;
    links.get(year)?.setAttribute('aria-current', 'true');
  };
  const onScroll = () => {
    const line = topbar() + 110;
    let active = null;
    for (const section of years) {
      if (section.hidden) continue;
      if (section.getBoundingClientRect().top <= line) active = section.dataset.timelineYear;
      else break;
    }
    setCurrent(window.scrollY < 40 ? null : active);
  };
  let ticking = false;
  window.addEventListener('scroll', () => {
    if (ticking) return;
    ticking = true;
    window.requestAnimationFrame(() => { syncStuck(); onScroll(); ticking = false; });
  }, { passive: true });
  syncStuck();
  onScroll();
})();
