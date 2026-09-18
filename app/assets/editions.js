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

  // A régua encolhe quando o topo da página sai de vista.
  const topbar = () => parseFloat(getComputedStyle(document.documentElement).getPropertyValue('--topbar')) || 0;
  new IntersectionObserver(([entry]) => {
    spectro.classList.toggle('is-stuck', !entry.isIntersecting && entry.boundingClientRect.top < topbar() + 1);
  }, { rootMargin: `-${topbar() + 1}px 0px 0px 0px` }).observe(sentinel);

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
    window.requestAnimationFrame(() => { onScroll(); ticking = false; });
  }, { passive: true });
  onScroll();
})();
