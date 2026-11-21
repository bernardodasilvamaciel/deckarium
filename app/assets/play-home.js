/* Página inicial (play_home.php): a vitrine 3D da mesa, a troca de paisagem, "Recebeu um link de mesa?", os botões
   "Jogar com amigos" dos decks e o painel "Sua mesa" (paisagem, madeira, shield e verso, guardados na conta). */
const dataEl = document.getElementById('play-home-data');
const data = dataEl ? JSON.parse(dataEl.textContent) : null;
const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

/* ---------- Entrar numa mesa pelo link ou pelo código ---------- */
document.querySelector('[data-ph-join]')?.addEventListener('submit', (event) => {
  event.preventDefault();
  const form = event.currentTarget;
  const value = form.elements.code.value.trim();
  const code = (value.match(/mesa=([A-Za-z0-9]{12})/) || value.match(/^([A-Za-z0-9]{12})$/) || [])[1];
  const error = form.querySelector('[data-ph-join-error]');
  if (!code) { error.hidden = false; error.textContent = 'Cole o link que recebeu (…/deck_playtest.php?mesa=…) ou o código de 12 letras.'; return; }
  location.href = `/deck_playtest.php?mesa=${code}`;
});

/* ---------- Jogar com amigos: abre a mesa com o deck e leva ao link ---------- */
document.querySelector('.play-decks')?.addEventListener('click', async (event) => {
  const button = event.target.closest('[data-play-create]');
  if (!button || !data) return;
  const error = document.querySelector('[data-play-error]');
  button.disabled = true;
  const body = new FormData();
  body.set('csrf', data.csrf); body.set('action', 'create'); body.set('deck', button.dataset.playCreate);
  const response = await fetch('/playtest_table.php', { method: 'POST', body, credentials: 'same-origin' }).catch(() => null);
  const result = response ? await response.json().catch(() => null) : null;
  if (result?.ok) { location.href = result.url; return; }
  error.hidden = false; error.textContent = result?.message || 'Não foi possível abrir a mesa agora. Tente de novo.';
  button.disabled = false;
});

if (data) start();

async function start() {
  const host = document.querySelector('[data-showcase]');
  const envButtons = [...document.querySelectorAll('[data-ph-env]')];
  let world = null;
  try { world = await import(data.world); } catch (error) { console.warn(error); }
  let scene = null;
  let current = data.environment;
  const prefs = { ...(data.prefs || {}) };

  /* ---------- Vitrine 3D ---------- */
  const webgl = (() => { try { const c = document.createElement('canvas'); return !!(c.getContext('webgl2') || c.getContext('webgl')); } catch { return false; } })();
  if (host && webgl) {
    try {
      const module = await import(data.scene);
      scene = module.createPlaytestScene(host, { environment: current, wood: data.wood, style: data.style, commandZone: true, showcase: true, topInset: () => 40, bottomInset: () => 40 });
      const L = module.LAYOUT;
      const cards = data.cards || [];
      if (cards[0]) scene.playmat(cards[0].image);
      const row = cards.slice(1, 5);
      scene.sync({
        look: null,
        battlefield: [
          ...row.map((card, i) => ({ iid: `s${i}`, x: L.left + 3 + i * 2.6, z: L.rows.creature, tapped: i === 2, attacking: false, token: false, commander: false, image: card.image,
            fallback: { name: card.name, type: '', pt: null }, fallbackKey: card.name, counters: i === 1 ? [{ name: '+1/+1', n: 2, short: '+1/+1' }] : [], pt: null, sick: false, stack: 1, buried: false })),
        ],
        piles: { library: { n: 62 }, graveyard: { n: 4, top: cards[4]?.image || null }, exile: { n: 0, top: null }, command: cards[0] ? [{ image: cards[0].image, tax: 0 }] : [] },
      });
      setTimeout(() => { scene.setScenic(true); scene.zoomBy(0.74); }, 3600);
    } catch (error) {
      console.warn(error);
      host.classList.add('is-static');
    }
  } else host?.classList.add('is-static');

  /* ---------- Paisagens: clique para trocar; sem clique, elas se revezam ---------- */
  const order = envButtons.map((button) => button.dataset.phEnv);
  let touched = false;
  function showEnv(kind) {
    current = kind;
    envButtons.forEach((button) => { const on = button.dataset.phEnv === kind; button.classList.toggle('is-on', on); button.setAttribute('aria-pressed', String(on)); });
    scene?.setEnvironment(kind);
    document.querySelector('.ph-hero')?.setAttribute('data-env', kind);
  }
  document.querySelector('.ph-hero')?.setAttribute('data-env', current);
  envButtons.forEach((button) => button.addEventListener('click', () => { touched = true; showEnv(button.dataset.phEnv); }));
  const still = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (!still && scene) setInterval(() => { if (!touched && !document.hidden && host.getBoundingClientRect().bottom > 0) showEnv(order[(order.indexOf(current) + 1) % order.length]); }, 14000);

  /* ---------- Sua mesa: paisagem, madeira, shield e verso ---------- */
  const panel = document.querySelector('[data-ph-prefs]');
  if (!panel || !world || !data.prefs) return;
  const { ENVIRONMENTS, WOODS, SLEEVES, BACKS, cardBackDataUrl } = world;
  let saveTimer = 0;
  function save() {
    clearTimeout(saveTimer);
    saveTimer = setTimeout(async () => {
      const body = new FormData(); body.set('csrf', data.csrf); body.set('action', 'prefs'); body.set('prefs', JSON.stringify(prefs));
      const response = await fetch('/playtest_table.php', { method: 'POST', body, credentials: 'same-origin' }).catch(() => null);
      const note = panel.querySelector('[data-ph-saved]');
      if (note) { note.textContent = response?.ok ? 'Guardado na sua conta.' : 'Não foi possível guardar agora.'; note.classList.add('is-on'); setTimeout(() => note.classList.remove('is-on'), 1800); }
    }, 400);
  }
  const group = (title, items) => `<div class="ph-pref"><h3>${esc(title)}</h3><div class="ph-pref-grid">${items}</div></div>`;
  function paint() {
    panel.innerHTML = [
      group('Paisagem', [['auto', 'Pelas cores do deck', 'Cada deck na paisagem da cor que mais aparece nele'], ...Object.entries(ENVIRONMENTS).map(([key, env]) => [key, env.name, env.blurb])]
        .map(([key, name, blurb]) => `<button type="button" class="ph-pick${prefs.environment === key ? ' is-on' : ''}" data-pref="environment" data-value="${key}" aria-pressed="${prefs.environment === key}"><i class="ph-env-swatch is-${key}"></i><strong>${esc(name)}</strong><small>${esc(blurb)}</small></button>`).join('')),
      group('Madeira da mesa', Object.entries(WOODS).map(([key, wood]) => `<button type="button" class="ph-pick${prefs.wood === key ? ' is-on' : ''}" data-pref="wood" data-value="${key}" aria-pressed="${prefs.wood === key}"><i class="ph-swatch" style="background:linear-gradient(135deg,${wood.light},${wood.dark})"></i><strong>${esc(wood.name)}</strong></button>`).join('')),
      group('Shield', Object.entries(SLEEVES).map(([key, sleeve]) => `<button type="button" class="ph-pick${prefs.sleeve === key ? ' is-on' : ''}" data-pref="sleeve" data-value="${key}" aria-pressed="${prefs.sleeve === key}"><i class="ph-swatch" style="background:${sleeve.color};box-shadow:inset 0 0 0 3px ${sleeve.accent}"></i><strong>${esc(sleeve.name)}</strong></button>`).join('')),
      group('Verso', Object.entries(BACKS).map(([key, back]) => `<button type="button" class="ph-pick is-back${prefs.back === key ? ' is-on' : ''}" data-pref="back" data-value="${key}" aria-pressed="${prefs.back === key}"><img src="${cardBackDataUrl({ sleeve: prefs.sleeve, back: key }, null, 120)}" alt="" width="120" height="167"><strong>${esc(back.name)}</strong>${key === 'arte' ? '<small>A ilustração da comandante de cada deck</small>' : ''}</button>`).join('')),
      '<p class="ph-saved" data-ph-saved aria-live="polite"></p>',
    ].join('');
  }
  panel.addEventListener('click', (event) => {
    const button = event.target.closest('[data-pref]');
    if (!button) return;
    prefs[button.dataset.pref] = button.dataset.value;
    paint();
    save();
    if (!scene) return;
    if (button.dataset.pref === 'environment' && button.dataset.value !== 'auto') { touched = true; showEnv(button.dataset.value); }
    if (button.dataset.pref === 'wood') scene.setWood(prefs.wood);
    if (button.dataset.pref === 'sleeve' || button.dataset.pref === 'back') scene.setStyle(null, { sleeve: prefs.sleeve, back: prefs.back === 'arte' ? 'deckarium' : prefs.back });
  });
  paint();
}
