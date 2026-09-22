/* Mesa de teste do Deckarium: uma partida solitária e manual com as cartas do deck.
   Aqui ficam o estado, as zonas, as regras que dá para conferir sozinho e a interface; o desenho 3D fica em
   playtest-scene.js. Toda ação passa por act(), que guarda o passo anterior (Desfazer) e salva a partida no navegador. */
const root = document.querySelector('[data-playtest]');
const dataEl = document.getElementById('playtest-data');
if (root && dataEl) start(JSON.parse(dataEl.textContent));

async function start(data) {
  const $ = (sel) => root.querySelector(sel);
  const $$ = (sel) => Array.from(root.querySelectorAll(sel));
  const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const PHASES = ['Desvirar', 'Manutenção', 'Compra', 'Principal 1', 'Combate', 'Principal 2', 'Final'];
  const COUNTERS = ['+1/+1', '-1/-1', 'Lealdade', 'Carga', 'Tempo', 'Escudo'];
  const storeKey = `deckarium-playtest-v1-${data.deck.id}`;
  const signature = data.library.map((c) => `${c.id}:${c.quantity}`).join(',') + '|' + (data.commander?.id || '');

  /* ---------- Cartas ---------- */
  const refs = new Map();
  data.library.forEach((card, i) => refs.set(`L${i}`, card));
  if (data.commander) refs.set('C', data.commander);
  data.tokens.forEach((token, i) => refs.set(`T${i}`, token));
  const cardOf = (inst) => inst.custom || refs.get(inst.ref) || { name: '?', type_line: '', colors: ['C'] };
  function faceOf(inst) {
    const card = cardOf(inst);
    if (inst.faceDown) return { ...card, name: 'Carta virada para baixo', type_line: 'Criatura 2/2', power: '2', toughness: '2', loyalty: null, text: '', image: 'back', colors: ['C'] };
    if (inst.flipped && card.back) return { ...card, ...card.back, colors: card.colors };
    return card;
  }
  const typeOf = (inst) => String(faceOf(inst).type_line || '').split(' // ')[0];
  const isLand = (inst) => /\bLand\b/.test(typeOf(inst));
  const isCreature = (inst) => inst.faceDown || /\bCreature\b/.test(typeOf(inst));
  const isPlaneswalker = (inst) => /\bPlaneswalker\b/.test(typeOf(inst));
  const rowOf = (inst) => (isLand(inst) ? 'land' : isCreature(inst) ? 'creature' : 'other');
  const num = (value) => (/^-?\d+$/.test(String(value ?? '')) ? Number(value) : null);
  function ptOf(inst) {
    if (!isCreature(inst)) return null;
    const face = faceOf(inst);
    const plus = (inst.counters['+1/+1'] || 0) - (inst.counters['-1/-1'] || 0);
    const p = num(face.power); const t = num(face.toughness);
    return { p: p === null ? null : p + plus, t: t === null ? null : t + plus, text: `${p === null ? face.power ?? '*' : p + plus}/${t === null ? face.toughness ?? '*' : t + plus}`, state: plus > 0 ? 'up' : plus < 0 ? 'down' : 'normal' };
  }
  const hasWord = (inst, word) => new RegExp(`\\b${word}\\b`, 'i').test(faceOf(inst).text || '');
  const isSick = (inst) => inst.zone === 'battlefield' && isCreature(inst) && inst.entered === S.turn && !cardOf(inst).haste && S.stage === 'play';
  const imageOf = (inst) => (inst.faceDown ? 'back' : faceOf(inst).image || null);

  /* ---------- Estado ---------- */
  let S = null;
  const history = [];
  function freshState() {
    return { v: 1, sig: signature, seq: 0, cards: {}, zones: { library: [], hand: [], battlefield: [], graveyard: [], exile: [], command: [] },
      turn: 1, phase: 3, life: 40, poison: 0, opponent: 40, lands: 0, casts: 0, mulligans: 0, stage: 'mulligan', bottom: [], log: [], custom: [], killTurn: null, twoPlayer: false, order: 0 };
  }
  function newInstance(ref, extra = {}) {
    const iid = `c${++S.seq}`;
    S.cards[iid] = { iid, ref, zone: null, x: 0, z: 0, tapped: false, faceDown: false, flipped: false, counters: {}, attacking: false, entered: 0, manual: false, token: false, commander: false, order: 0, ...extra };
    return iid;
  }
  function place(iid, zone, position = 'top') {
    const inst = S.cards[iid];
    if (inst.zone) { const list = S.zones[inst.zone]; const at = list.indexOf(iid); if (at >= 0) list.splice(at, 1); }
    inst.zone = zone;
    const list = S.zones[zone];
    if (zone === 'library') { if (position === 'bottom') list.push(iid); else list.unshift(iid); }
    else list.push(iid);
  }
  function shuffleList(list) {
    for (let i = list.length - 1; i > 0; i--) { const j = Math.floor(Math.random() * (i + 1)); [list[i], list[j]] = [list[j], list[i]]; }
  }
  const nameOf = (iid) => faceOf(S.cards[iid]).name;
  function log(text) { S.log.unshift({ turn: S.turn, text }); if (S.log.length > 250) S.log.length = 250; }

  /* ---------- Mesa 3D ---------- */
  const status = $('[data-pt-status]');
  let scene = null;
  let LAYOUT = null;
  const pendingFx = [];
  try {
    const module = await import(data.scene);
    scene = module.createPlaytestScene($('[data-pt-stage]'), {
      onCardClick: (iid) => cardClick(iid),
      onCardContext: (iid, x, y) => openCardMenu(iid, x, y),
      onPileClick: (zone) => pileClick(zone),
      onPileContext: (zone, x, y) => openPileMenu(zone, x, y),
      onCardDrop: (iid, spot) => cardDrop(iid, spot),
      onDragStart: () => hidePreview(),
      onHover: (target) => hoverTarget(target),
      handHeight: () => $('[data-pt-hand]').getBoundingClientRect().height,
      topInset: () => $('.pt-tools').getBoundingClientRect().bottom - root.getBoundingClientRect().top + 8,
      bottomInset: () => $('[data-pt-hand]').getBoundingClientRect().height * 0.7,
    });
    LAYOUT = module.LAYOUT;
    status.hidden = true;
  } catch (error) {
    console.error(error);
    status.textContent = 'Este navegador não conseguiu desenhar a mesa 3D (WebGL). Tente outro navegador ou atualize o driver de vídeo.';
    return;
  }
  if (data.commander) scene.playmat(data.commander.image);
  // A faixa de ferramentas fica logo abaixo do HUD, que muda de altura quando quebra a linha.
  const hud = $('.pt-hud');
  new ResizeObserver(() => { root.style.setProperty('--tools-top', `${hud.offsetHeight + 20}px`); scene.resize(); }).observe(hud);

  /* ---------- Posições no campo ---------- */
  // Terrenos básicos iguais e fichas iguais formam pilhas; o resto ocupa a próxima vaga da fileira.
  function arrange() {
    const rows = { creature: [], other: [], land: [] };
    S.zones.battlefield.forEach((iid) => { S.cards[iid].stack = 1; S.cards[iid].buried = false; });
    S.zones.battlefield.map((iid) => S.cards[iid]).filter((inst) => !inst.manual).sort((a, b) => a.order - b.order).forEach((inst) => rows[rowOf(inst)].push(inst));
    const width = LAYOUT.right - LAYOUT.left;
    const perLine = Math.floor(width / LAYOUT.spacing) + 1;
    Object.entries(rows).forEach(([row, list]) => {
      const slots = [];
      const byKey = new Map();
      list.forEach((inst) => {
        const stackable = inst.token || (row === 'land' && /\bBasic\b/.test(typeOf(inst)));
        const key = stackable ? `${inst.ref}|${inst.custom?.name || ''}|${inst.token}` : inst.iid;
        if (!byKey.has(key)) { byKey.set(key, []); slots.push(byKey.get(key)); }
        byKey.get(key).push(inst);
      });
      // Cada fileira fica centrada na mesa; cheia, as cartas se aproximam em vez de sair do tapete.
      const lines = row === 'land' ? 2 : 1;
      const perThisLine = Math.max(perLine, Math.ceil(slots.length / lines));
      const spacing = perThisLine > perLine ? width / Math.max(1, perThisLine - 1) : LAYOUT.spacing;
      const center = (LAYOUT.left + LAYOUT.right) / 2;
      slots.forEach((slot, index) => {
        const line = Math.floor(index / perThisLine);
        const col = index % perThisLine;
        const inLine = Math.min(perThisLine, slots.length - line * perThisLine);
        const z = row === 'land' ? (line ? LAYOUT.rows.land2 : LAYOUT.rows.land) : LAYOUT.rows[row];
        const x = center + (col - (inLine - 1) / 2) * spacing;
        slot.forEach((inst, k) => { inst.x = x + k * 0.16; inst.z = z + k * 0.14; inst.stack = k === slot.length - 1 ? slot.length : 0; inst.buried = k < slot.length - 1; });
      });
    });
  }

  /* ---------- Movimentos e regras ---------- */
  function enterBattlefield(inst, from, at) {
    inst.entered = S.turn;
    inst.order = ++S.order;
    inst.tapped = false;
    if (at) { inst.x = at.x; inst.z = at.z; inst.manual = true; } else inst.manual = false;
    if (isPlaneswalker(inst) && num(faceOf(inst).loyalty) !== null && !inst.counters.Lealdade) { inst.counters.Lealdade = num(faceOf(inst).loyalty); inst.walker = true; }
    scene.spawn(inst.iid, at && from === 'hand' ? { x: at.x, z: at.z } : from);
    pendingFx.push(() => scene.fx.burst(inst.x, inst.z, faceOf(inst).colors, inst.commander));
  }

  /**
   * Move uma carta entre zonas aplicando as regras de troca de zona:
   * fichas deixam de existir fora do campo (704.5d/111.7), a comandante pode ir para a zona de comando (903.9),
   * o que sai do campo perde marcadores e volta desvirado (400.7).
   */
  function move(iid, to, opts = {}) {
    const inst = S.cards[iid];
    if (!inst) return;
    const from = inst.zone;
    const name = nameOf(iid);
    if (from === to && to !== 'library') return;
    if (inst.commander && ['graveyard', 'exile', 'hand', 'library'].includes(to) && !opts.decided) {
      askCommander(iid, to);
      return;
    }
    if (from === 'battlefield') {
      scene.depart(iid, inst.token ? 'gone' : to);
      Object.assign(inst, { tapped: false, attacking: false, counters: {}, faceDown: false, flipped: false, manual: false, walker: false });
    }
    if (inst.token && to !== 'battlefield') {
      const list = S.zones[from]; list.splice(list.indexOf(iid), 1);
      delete S.cards[iid];
      log(`${name} (ficha) deixou de existir.`);
      toast(`${name} era uma ficha: fora do campo de batalha ela deixa de existir.`, { rule: '704.5d' });
      return;
    }
    place(iid, to, opts.position);
    if (to === 'battlefield') {
      if (opts.faceDown) inst.faceDown = true;
      enterBattlefield(inst, from, opts.at);
      if (from === 'command') {
        S.casts += 1;
        pendingFx.push(() => scene.fx.commander());
        log(`Lançou a comandante ${name} da zona de comando${S.casts > 1 ? ` (imposto pago: +${(S.casts - 1) * 2})` : ''}.`);
      } else log(`${from === 'hand' ? 'Jogou' : 'Colocou no campo'} ${inst.faceDown ? 'uma carta virada para baixo' : name}${from && from !== 'hand' ? ` (de ${zoneName(from)})` : ''}.`);
      if (from === 'hand' && isLand(inst) && !inst.faceDown) {
        S.lands += 1;
        if (S.lands > 1) toast(`Esse é o ${S.lands}º terreno deste turno. Sem um efeito que permita, é só um por turno.`, { rule: '305.2', kind: 'warn' });
      }
    } else {
      log(`${name}: ${zoneName(from)} → ${zoneName(to, to === 'library' ? opts.position || 'top' : undefined)}.`);
    }
  }
  const zoneName = (zone, position) => ({ library: position === 'bottom' ? 'fundo do grimório' : position === 'top' ? 'topo do grimório' : 'grimório', hand: 'mão', battlefield: 'campo', graveyard: 'cemitério', exile: 'exílio', command: 'zona de comando' }[zone] || zone || '—');
  const toZone = { hand: 'a mão', graveyard: 'o cemitério', exile: 'o exílio', command: 'a zona de comando', battlefield: 'o campo' };

  /** Verificações de estado (704): marcadores que se anulam, resistência 0, lealdade 0, derrota. */
  function stateBased() {
    for (let guard = 0; guard < 5; guard++) {
      let changed = false;
      S.zones.battlefield.slice().forEach((iid) => {
        const inst = S.cards[iid];
        const plus = inst.counters['+1/+1'] || 0; const minus = inst.counters['-1/-1'] || 0;
        if (plus && minus) {
          const cancel = Math.min(plus, minus);
          inst.counters['+1/+1'] = plus - cancel; inst.counters['-1/-1'] = minus - cancel;
          toast(`${nameOf(iid)}: ${cancel} marcador(es) +1/+1 e −1/−1 se anularam.`, { rule: '704.5q' });
        }
        const pt = ptOf(inst);
        const dies = pt && pt.t !== null && pt.t <= 0;
        const spent = !dies && inst.walker && (inst.counters.Lealdade || 0) <= 0;
        if (dies || spent) {
          toast(dies ? `${nameOf(iid)} foi para o cemitério com resistência ${pt.t}.` : `${nameOf(iid)} ficou sem lealdade e foi para o cemitério.`, { rule: dies ? '704.5f' : '704.5i', kind: 'warn' });
          if (inst.commander) askCommander(iid, 'graveyard');
          else { move(iid, 'graveyard'); changed = true; }
        }
      });
      if (!changed) break;
    }
    if (S.life <= 0 && !S.lostLife) { S.lostLife = true; toast('Com 0 de vida ou menos, você perderia a partida.', { rule: '704.5a', kind: 'warn' }); }
    if (S.life > 0) S.lostLife = false;
    if (S.poison >= 10 && !S.lostPoison) { S.lostPoison = true; toast('Dez marcadores de veneno: você perderia a partida.', { rule: '704.5c', kind: 'warn' }); }
    if (S.poison < 10) S.lostPoison = false;
    if (S.opponent <= 0 && S.killTurn === null) {
      S.killTurn = S.turn;
      toast(`O oponente imaginário caiu no turno ${S.turn}.`, { kind: 'win' });
      log(`Oponente derrotado no turno ${S.turn}.`);
      pendingFx.push(() => { scene.fx.burst(-3, -3, ['W', 'U', 'B', 'R', 'G'], true); setTimeout(() => scene.fx.burst(3, -2, ['W', 'U', 'B', 'R', 'G'], true), 220); });
    }
  }

  function act(label, fn) {
    closeMenu();
    history.push(JSON.stringify(S));
    if (history.length > 80) history.shift();
    fn();
    stateBased();
    render();
    save();
  }

  function drawCards(n, silent = false) {
    let drawn = 0;
    for (let i = 0; i < n; i++) {
      const iid = S.zones.library[0];
      if (!iid) { toast('O grimório está vazio: comprar agora faria você perder a partida.', { rule: '704.5b', kind: 'warn' }); break; }
      place(iid, 'hand');
      freshHand.add(iid);
      drawn++;
    }
    if (drawn) { scene.fx.draw(Math.min(drawn, 7)); if (!silent) log(drawn === 1 ? `Comprou ${nameOf(S.zones.hand[S.zones.hand.length - 1])}.` : `Comprou ${drawn} cartas.`); }
  }

  function untapAll(silent = false) {
    let count = 0;
    S.zones.battlefield.forEach((iid) => { const inst = S.cards[iid]; if (inst.tapped) { inst.tapped = false; count++; } inst.attacking = false; });
    if (!silent && count) log(`Desvirou ${count} permanente(s).`);
  }

  function enterPhase(phase) {
    S.phase = phase;
    if (phase === 0) untapAll();
    if (phase === 2 && !(S.turn === 1 && S.twoPlayer)) drawCards(1);
    if (phase === 4) toast('Combate: clique nas criaturas para atacar e depois aplique o dano ao oponente.', { kind: 'info' });
    if (phase === 6 && S.zones.hand.length > 7) toast(`Você tem ${S.zones.hand.length} cartas: na limpeza, descarte até ficar com 7.`, { rule: '514.1', kind: 'warn' });
  }
  function endCombat() { S.zones.battlefield.forEach((iid) => { S.cards[iid].attacking = false; }); }
  function nextPhase() {
    if (S.phase === 4) endCombat();
    if (S.phase >= 6) nextTurn();
    else enterPhase(S.phase + 1);
  }
  function nextTurn() {
    endCombat();
    if (S.zones.hand.length > 7) toast(`Você terminou o turno com ${S.zones.hand.length} cartas: o certo seria descartar até 7.`, { rule: '514.1', kind: 'warn' });
    S.turn += 1;
    S.lands = 0;
    log(`— Turno ${S.turn} —`);
    [0, 1, 2, 3].forEach(enterPhase);
  }

  function newGame(twoPlayer = false) {
    S = freshState();
    S.twoPlayer = twoPlayer;
    data.library.forEach((card, i) => { for (let k = 0; k < card.quantity; k++) place(newInstance(`L${i}`), 'library'); });
    shuffleList(S.zones.library);
    if (data.commander) place(newInstance('C', { commander: true }), 'command');
    drawCards(7, true);
    log('Nova partida: grimório embaralhado e 7 cartas na mão.');
    scene.fx.shuffle();
  }

  function keepHand() {
    const toBottom = Math.max(0, S.mulligans - 1);
    if (toBottom && S.bottom.length !== toBottom) return;
    S.bottom.forEach((iid) => place(iid, 'library', 'bottom'));
    if (toBottom) log(`Colocou ${toBottom} carta(s) no fundo do grimório.`);
    S.bottom = [];
    S.stage = 'play';
    log(`Manteve a mão com ${S.zones.hand.length} cartas${S.mulligans ? ` depois de ${S.mulligans} mulligan(s)` : ''}.`);
    log('— Turno 1 —');
    [0, 1, 2, 3].forEach(enterPhase);
  }
  function mulligan() {
    S.zones.hand.slice().forEach((iid) => place(iid, 'library'));
    shuffleList(S.zones.library);
    S.mulligans += 1;
    S.bottom = [];
    drawCards(7, true);
    scene.fx.shuffle();
    log(`Mulligan ${S.mulligans}: nova mão de 7.`);
  }

  function createToken(ref, count, custom = null) {
    for (let i = 0; i < count; i++) {
      const iid = newInstance(ref, { token: true, custom });
      const inst = S.cards[iid];
      place(iid, 'battlefield');
      enterBattlefield(inst, 'token');
    }
    log(`Criou ${count} ficha(s) de ${custom?.name || refs.get(ref)?.name || 'ficha'}.`);
  }

  /* ---------- Interações ---------- */
  let hovered = null;
  function cardClick(iid) {
    const inst = S.cards[iid];
    if (!inst) return;
    if (S.phase === 4 && S.stage === 'play' && isCreature(inst) && !isLand(inst)) { toggleAttack(iid); return; }
    toggleTap(iid);
  }
  function toggleTap(iid) {
    const inst = S.cards[iid];
    if (!inst) return;
    act('tap', () => {
      inst.tapped = !inst.tapped;
      if (inst.tapped && isSick(inst) && !isLand(inst)) toast(`${nameOf(iid)} entrou neste turno: não pode atacar nem usar habilidades com {T} sem ímpeto.`, { rule: '302.6', kind: 'info' });
      log(`${inst.tapped ? 'Virou' : 'Desvirou'} ${nameOf(iid)}.`);
    });
  }
  function toggleAttack(iid) {
    const inst = S.cards[iid];
    act('attack', () => {
      if (inst.attacking) { inst.attacking = false; if (!hasWord(inst, 'vigilance')) inst.tapped = false; log(`${nameOf(iid)} não ataca mais.`); return; }
      if (inst.tapped) { toast(`${nameOf(iid)} está virada e não pode atacar.`, { rule: '508.1a', kind: 'warn' }); return; }
      if (isSick(inst)) toast(`${nameOf(iid)} entrou neste turno: sem ímpeto, não poderia atacar.`, { rule: '302.6', kind: 'warn' });
      inst.attacking = true;
      if (!hasWord(inst, 'vigilance')) inst.tapped = true;
      log(`${nameOf(iid)} ataca${hasWord(inst, 'vigilance') ? ' (vigilância: não vira)' : ''}.`);
    });
  }
  function attackers() { return S.zones.battlefield.map((iid) => S.cards[iid]).filter((inst) => inst.attacking); }
  function applyDamage() {
    const list = attackers();
    const total = list.reduce((sum, inst) => sum + Math.max(0, ptOf(inst)?.p ?? 0), 0);
    act('damage', () => {
      S.opponent -= total;
      list.forEach((inst) => { inst.attacking = false; });
      log(`Dano de combate: ${total} no oponente (${list.map((inst) => nameOf(inst.iid)).join(', ')}).`);
      floatText(`−${total}`, $('[data-pt-meter="opponent"]'));
    });
  }

  function cardDrop(iid, spot) {
    const inst = S.cards[iid];
    if (!inst) return;
    if (spot.zone === 'command' && inst.commander) { act('drop', () => move(iid, 'command', { decided: true })); return; }
    if (spot.zone && spot.zone !== 'command') { act('drop', () => move(iid, spot.zone)); return; }
    act('position', () => { inst.x = spot.x; inst.z = spot.z; inst.manual = true; inst.order = ++S.order; });
  }

  function pileClick(zone) {
    if (zone === 'library') { act('draw', () => drawCards(1)); return; }
    if (zone === 'command') { castCommander(); return; }
    openZone(zone);
  }
  function castCommander() {
    const iid = S.zones.command[0];
    if (!iid) { toast('A comandante não está na zona de comando.', { kind: 'info' }); return; }
    if (S.stage !== 'play') { toast('Primeiro, decida a mão inicial.', { kind: 'info' }); return; }
    act('commander', () => move(iid, 'battlefield'));
  }

  /* ---------- Menus ---------- */
  const menu = $('[data-pt-menu]');
  function openMenu(items, x, y) {
    menu.innerHTML = items.map((item, i) => item === '-' ? '<hr>' : item.title ? `<p class="pt-menu-title">${esc(item.title)}</p>` : `<button type="button" role="menuitem" data-menu-index="${i}"${item.disabled ? ' disabled' : ''}><span>${esc(item.label)}</span>${item.key ? `<kbd>${esc(item.key)}</kbd>` : ''}</button>`).join('');
    menu.hidden = false;
    const rect = root.getBoundingClientRect();
    const box = menu.getBoundingClientRect();
    menu.style.left = `${Math.min(x - rect.left, rect.width - box.width - 8)}px`;
    menu.style.top = `${Math.min(y - rect.top, rect.height - box.height - 8)}px`;
    menu.onclick = (event) => {
      const button = event.target.closest('[data-menu-index]');
      if (!button) return;
      const item = items[Number(button.dataset.menuIndex)];
      closeMenu();
      item.action();
    };
    menu.querySelector('button:not([disabled])')?.focus();
  }
  function closeMenu() { menu.hidden = true; }
  document.addEventListener('pointerdown', (event) => { if (!menu.hidden && !menu.contains(event.target)) closeMenu(); });

  function openCardMenu(iid, x, y) {
    const inst = S.cards[iid];
    if (!inst) return;
    const card = cardOf(inst);
    const pt = ptOf(inst);
    openMenu([
      { title: `${nameOf(iid)}${pt ? ` · ${pt.text}` : ''}` },
      { label: inst.tapped ? 'Desvirar' : 'Virar', key: 'T', action: () => toggleTap(iid) },
      ...(isCreature(inst) ? [{ label: inst.attacking ? 'Cancelar ataque' : 'Atacar', key: 'A', action: () => toggleAttack(iid) }] : []),
      { label: 'Marcadores…', key: 'C', action: () => openCounters(iid) },
      { label: 'Adicionar +1/+1', key: '+', action: () => act('counter', () => addCounter(iid, '+1/+1', 1)) },
      ...((inst.counters['+1/+1'] || 0) > 0 ? [{ label: 'Tirar +1/+1', key: '−', action: () => act('counter', () => addCounter(iid, '+1/+1', -1)) }] : []),
      '-',
      ...(card.back && !inst.faceDown ? [{ label: inst.flipped ? 'Voltar para a frente' : 'Transformar', action: () => act('flip', () => { inst.flipped = !inst.flipped; pendingFx.push(() => scene.fx.sparkle(inst.x, inst.z, '#ffffff')); log(`${card.name} transformou.`); }) }] : []),
      { label: inst.faceDown ? 'Desvirar a face (revelar)' : 'Virar a face para baixo', action: () => act('face', () => { inst.faceDown = !inst.faceDown; log(`${inst.faceDown ? 'Virou para baixo' : 'Revelou'} ${faceOf(inst).name}.`); }) },
      { label: 'Criar cópia (ficha)', action: () => act('copy', () => {
        const copy = newInstance(inst.ref, { token: true, custom: inst.custom || null });
        place(copy, 'battlefield');
        enterBattlefield(S.cards[copy], 'token', inst.manual ? { x: inst.x + 0.6, z: inst.z + 0.3 } : null);
        log(`Criou uma ficha que é cópia de ${nameOf(iid)}.`);
      }) },
      '-',
      { label: 'Para a mão', key: 'H', action: () => act('move', () => move(iid, 'hand')) },
      { label: 'Para o cemitério', key: 'G', action: () => act('move', () => move(iid, 'graveyard')) },
      { label: 'Para o exílio', key: 'E', action: () => act('move', () => move(iid, 'exile')) },
      { label: 'Para o topo do grimório', action: () => act('move', () => move(iid, 'library')) },
      { label: 'Para o fundo do grimório', action: () => act('move', () => move(iid, 'library', { position: 'bottom' })) },
      ...(inst.commander ? [{ label: 'Para a zona de comando', action: () => act('move', () => move(iid, 'command', { decided: true })) }] : []),
      ...(card.url ? ['-', { label: 'Abrir a página da carta ↗', action: () => window.open(card.url, '_blank', 'noopener') }] : []),
    ], x, y);
  }
  function openHandMenu(iid, x, y) {
    if (S.stage === 'bottom') { toggleBottom(iid); return; }
    const inst = S.cards[iid];
    openMenu([
      { title: nameOf(iid) },
      { label: isLand(inst) ? 'Jogar o terreno' : 'Lançar / jogar', key: 'Enter', action: () => playFromHand(iid) },
      { label: 'Jogar virada para baixo', action: () => act('play', () => move(iid, 'battlefield', { faceDown: true })) },
      '-',
      { label: 'Descartar', action: () => act('move', () => move(iid, 'graveyard')) },
      { label: 'Exilar', action: () => act('move', () => move(iid, 'exile')) },
      { label: 'Para o topo do grimório', action: () => act('move', () => move(iid, 'library')) },
      { label: 'Para o fundo do grimório', action: () => act('move', () => move(iid, 'library', { position: 'bottom' })) },
    ], x, y);
  }
  function openPileMenu(zone, x, y) {
    if (zone === 'library') {
      openMenu([
        { title: `Grimório · ${S.zones.library.length}` },
        { label: 'Comprar 1', key: 'D', action: () => act('draw', () => drawCards(1)) },
        { label: 'Comprar 7', action: () => act('draw', () => drawCards(7)) },
        { label: 'Embaralhar', key: 'S', action: shuffleLibrary },
        { label: 'Olhar o topo…', key: 'X', action: () => openScry() },
        { label: 'Buscar uma carta…', key: 'F', action: () => openZone('library') },
        { label: 'Moer 1', key: 'M', action: () => mill(1) },
        { label: 'Exilar o topo', action: () => act('exile-top', () => { const top = S.zones.library[0]; if (top) move(top, 'exile'); }) },
        { label: 'Revelar o topo', action: () => { const top = S.zones.library[0]; if (top) { toast(`Topo do grimório: ${nameOf(top)}.`, { kind: 'info' }); showPreview(S.cards[top]); } } },
      ], x, y);
    } else if (zone === 'command') {
      openMenu([{ title: 'Zona de comando' }, { label: 'Lançar a comandante', action: castCommander, disabled: !S.zones.command.length }], x, y);
    } else {
      openMenu([{ title: `${zone === 'graveyard' ? 'Cemitério' : 'Exílio'} · ${S.zones[zone].length}` }, { label: 'Ver as cartas…', action: () => openZone(zone) }], x, y);
    }
  }
  function playFromHand(iid, at = null) {
    if (S.stage !== 'play') { toast('Primeiro, decida a mão inicial.', { kind: 'info' }); return; }
    act('play', () => move(iid, 'battlefield', { at }));
  }
  function shuffleLibrary() { act('shuffle', () => { shuffleList(S.zones.library); scene.fx.shuffle(); log('Embaralhou o grimório.'); }); }
  function mill(n) { act('mill', () => { for (let i = 0; i < n; i++) { const top = S.zones.library[0]; if (!top) break; move(top, 'graveyard'); } }); }
  function addCounter(iid, name, delta) {
    const inst = S.cards[iid];
    inst.counters[name] = Math.max(0, (inst.counters[name] || 0) + delta);
    if (!inst.counters[name]) delete inst.counters[name];
    if (name === 'Lealdade' && delta > 0) inst.walker = inst.walker || isPlaneswalker(inst);
    log(`${nameOf(iid)}: ${delta > 0 ? '+' : ''}${delta} marcador ${name} (${inst.counters[name] || 0}).`);
  }

  /* ---------- Comandante mudando de zona (903.9) ---------- */
  function askCommander(iid, to) {
    const name = nameOf(iid);
    openModal(`<h2>${esc(name)} iria para ${esc(toZone[to] || zoneName(to))}</h2>
      <p>Quando a comandante iria para o cemitério, o exílio, a mão ou o grimório, você pode mandá-la para a zona de comando em vez disso <small class="pt-rule">903.9</small>. Na próxima vez, lançá-la custa mais {2} por vez que ela já foi lançada de lá.</p>
      <div class="pt-modal-actions"><button type="button" class="pt-btn is-strong" data-choice="command">Zona de comando</button><button type="button" class="pt-btn" data-choice="${esc(to)}">Deixar ir para ${esc(toZone[to] || zoneName(to))}</button></div>`, (event) => {
      const choice = event.target.closest('[data-choice]')?.dataset.choice;
      if (!choice) return;
      closeModal();
      // A pergunta interrompeu o act() original: esta escolha vira a ação que o Desfazer volta.
      act('commander-zone', () => move(iid, choice, { decided: true }));
    });
  }

  /* ---------- Janelas ---------- */
  const modal = $('[data-pt-modal]');
  const modalCard = $('[data-pt-modal-card]');
  function openModal(html, onClick, onClose) {
    modalCard.innerHTML = `<button type="button" class="pt-modal-close" data-modal-close aria-label="Fechar">×</button>${html}`;
    modal.hidden = false;
    modal.onclick = (event) => {
      if (event.target === modal || event.target.closest('[data-modal-close]')) { closeModal(); onClose?.(); return; }
      onClick?.(event);
    };
    modal.onClose = onClose;
    modalCard.querySelector('input, button:not([data-modal-close])')?.focus();
  }
  function closeModal() { modal.hidden = true; modalCard.innerHTML = ''; }

  const destinations = { hand: 'Mão', battlefield: 'Campo', graveyard: 'Cemitério', exile: 'Exílio', library: 'Topo', bottom: 'Fundo' };
  function zoneRows(zone, filter = '') {
    const list = zone === 'graveyard' || zone === 'exile' ? S.zones[zone].slice().reverse() : S.zones[zone].slice();
    const wanted = filter.trim().toLowerCase();
    const rows = (zone === 'library' ? list.slice().sort((a, b) => nameOf(a).localeCompare(nameOf(b))) : list).filter((iid) => !wanted || nameOf(iid).toLowerCase().includes(wanted));
    return rows.map((iid) => {
      const inst = S.cards[iid]; const card = faceOf(inst);
      const buttons = Object.entries(destinations).filter(([key]) => key !== zone && !(zone === 'library' && key === 'bottom')).map(([key, label]) => `<button type="button" class="pt-chip" data-move="${key}" data-iid="${iid}">${label}</button>`).join('');
      return `<li><img src="${esc(cardOf(inst).thumb || card.image || '')}" alt="" loading="lazy" width="73" height="102"><div><strong>${esc(card.name)}</strong><small>${esc(card.type_line)}</small><span class="pt-chips">${buttons}</span></div></li>`;
    }).join('') || '<li class="pt-empty">Nenhuma carta.</li>';
  }
  function openZone(zone) {
    closeMenu();
    const title = { library: 'Buscar no grimório', graveyard: 'Cemitério', exile: 'Exílio' }[zone];
    const note = zone === 'library' ? '<p class="pt-modal-note">Buscar no grimório revela a ordem das cartas: ao fechar, a mesa embaralha, como pedem quase todas as buscas.</p>' : '';
    let searched = false;
    const paint = (filter = '') => { $('[data-zone-list]').innerHTML = zoneRows(zone, filter); };
    openModal(`<h2>${title} <span class="pt-modal-count">${S.zones[zone].length}</span></h2>${note}
      <label class="pt-field"><span class="sr-only">Filtrar pelo nome</span><input type="search" placeholder="Filtrar pelo nome…" data-zone-filter></label>
      <ul class="pt-zone-list" data-zone-list></ul>`, (event) => {
      const button = event.target.closest('[data-move]');
      if (!button) return;
      const iid = button.dataset.iid; const key = button.dataset.move;
      searched = true;
      act('zone', () => (key === 'bottom' ? move(iid, 'library', { position: 'bottom' }) : move(iid, key === 'library' ? 'library' : key)));
      paint($('[data-zone-filter]')?.value || '');
      modalCard.querySelector('.pt-modal-count').textContent = S.zones[zone].length;
    }, () => { if (zone === 'library' && searched !== null) act('shuffle', () => { shuffleList(S.zones.library); scene.fx.shuffle(); log('Embaralhou o grimório depois da busca.'); }); });
    paint();
    $('[data-zone-filter]').addEventListener('input', (event) => paint(event.target.value));
  }

  function openScry() {
    closeMenu();
    let plan = [];
    const reset = (count) => { plan = S.zones.library.slice(0, count).map((iid) => ({ iid, to: 'top' })); };
    const paint = () => {
      $('[data-scry-list]').innerHTML = plan.map((p, i) => {
        const inst = S.cards[p.iid];
        return `<li><img src="${esc(cardOf(inst).thumb || '')}" alt="" width="73" height="102"><div><strong>${i + 1}. ${esc(nameOf(p.iid))}</strong><small>${esc(typeOf(inst))}</small>
          <span class="pt-chips">${[['top', 'Topo'], ['bottom', 'Fundo'], ['graveyard', 'Cemitério'], ['hand', 'Mão'], ['exile', 'Exílio']].map(([key, label]) => `<button type="button" class="pt-chip${p.to === key ? ' is-on' : ''}" data-scry-to="${key}" data-i="${i}" aria-pressed="${p.to === key}">${label}</button>`).join('')}
          <button type="button" class="pt-chip" data-scry-up="${i}" aria-label="Subir na ordem" ${i === 0 ? 'disabled' : ''}>↑</button></span></div></li>`;
      }).join('') || '<li class="pt-empty">O grimório está vazio.</li>';
    };
    openModal(`<h2>Olhar o topo do grimório</h2>
      <p class="pt-modal-note">Escolha o destino de cada carta — vidência, vigiar, “olhe as N do topo”. As que ficam no topo voltam na ordem da lista.</p>
      <label class="pt-field is-inline">Quantas cartas <input type="number" min="1" max="15" value="1" data-scry-count></label>
      <ul class="pt-zone-list" data-scry-list></ul>
      <div class="pt-modal-actions"><button type="button" class="pt-btn is-strong" data-scry-apply>Aplicar</button></div>`, (event) => {
      const to = event.target.closest('[data-scry-to]');
      if (to) { plan[Number(to.dataset.i)].to = to.dataset.scryTo; paint(); return; }
      const up = event.target.closest('[data-scry-up]');
      if (up) { const i = Number(up.dataset.scryUp); [plan[i - 1], plan[i]] = [plan[i], plan[i - 1]]; paint(); return; }
      if (!event.target.closest('[data-scry-apply]')) return;
      const chosen = plan.slice();
      closeModal();
      act('scry', () => {
        const ids = new Set(chosen.map((p) => p.iid));
        S.zones.library = S.zones.library.filter((iid) => !ids.has(iid));
        chosen.filter((p) => p.to === 'top').reverse().forEach((p) => S.zones.library.unshift(p.iid));
        chosen.filter((p) => p.to === 'bottom').forEach((p) => S.zones.library.push(p.iid));
        chosen.filter((p) => !['top', 'bottom'].includes(p.to)).forEach((p) => { S.cards[p.iid].zone = p.to; S.zones[p.to].push(p.iid); if (p.to === 'hand') freshHand.add(p.iid); });
        const moved = ['bottom', 'graveyard', 'hand', 'exile'].map((key) => [key, chosen.filter((p) => p.to === key).length]).filter(([, n]) => n);
        log(`Olhou ${chosen.length} do topo${moved.length ? `: ${moved.map(([key, n]) => `${n} para ${key === 'bottom' ? 'o fundo' : toZone[key]}`).join(', ')}` : ''}.`);
      });
    });
    reset(1);
    paint();
    $('[data-scry-count]').addEventListener('input', (event) => { reset(Math.max(1, Math.min(15, Number(event.target.value) || 1))); paint(); });
  }

  function openCounters(iid) {
    closeMenu();
    const inst = S.cards[iid];
    history.push(JSON.stringify(S));
    const paint = () => {
      const names = [...new Set([...COUNTERS, ...Object.keys(inst.counters)])];
      $('[data-counter-list]').innerHTML = names.map((name) => `<li><span>${esc(name)}</span><button type="button" class="pt-round" data-counter="${esc(name)}" data-delta="-1" aria-label="Tirar ${esc(name)}">−</button><output>${inst.counters[name] || 0}</output><button type="button" class="pt-round" data-counter="${esc(name)}" data-delta="1" aria-label="Pôr ${esc(name)}">+</button></li>`).join('');
      const pt = ptOf(inst);
      $('[data-counter-pt]').textContent = pt ? `Força/resistência agora: ${pt.text}` : '';
    };
    openModal(`<h2>Marcadores · ${esc(nameOf(iid))}</h2><p class="pt-modal-note" data-counter-pt></p>
      <ul class="pt-counter-list" data-counter-list></ul>
      <form class="pt-counter-new" data-counter-new><label class="pt-field">Outro marcador<input name="name" maxlength="24" placeholder="Ex.: Ki, Eco, Verso"></label><button class="pt-btn">Adicionar</button></form>`, (event) => {
      const button = event.target.closest('[data-counter]');
      if (!button) return;
      addCounter(iid, button.dataset.counter, Number(button.dataset.delta));
      stateBased(); render(); save();
      // A carta pode ter morrido (e a comandante abre a própria pergunta no lugar desta janela).
      if (!$('[data-counter-list]')) return;
      if (!S.cards[iid] || S.cards[iid].zone !== 'battlefield') { closeModal(); return; }
      paint();
    });
    paint();
    $('[data-counter-new]').addEventListener('submit', (event) => {
      event.preventDefault();
      const name = event.target.elements.name.value.trim();
      if (!name) return;
      addCounter(iid, name, 1); render(); save(); event.target.reset(); paint();
    });
  }

  function openTokens() {
    closeMenu();
    const list = data.tokens.map((token, i) => {
      const pt = token.power !== null && token.power !== undefined ? `${token.power}/${token.toughness}` : '';
      return `<li><img src="${esc(token.thumb || token.image || '')}" alt="" width="73" height="102" loading="lazy"><div><strong>${esc(token.name)}${pt ? ` <em>${esc(pt)}</em>` : ''}</strong><small>${esc(token.type_line)}</small>
        <small class="pt-token-src">de ${esc(token.sources.slice(0, 3).join(', '))}${token.sources.length > 3 ? '…' : ''}</small>
        <span class="pt-chips"><input type="number" min="1" max="30" value="1" aria-label="Quantidade" data-token-qty="${i}"><button type="button" class="pt-btn is-strong" data-token="${i}">Criar</button></span></div></li>`;
    }).join('');
    openModal(`<h2>Criar fichas</h2>
      ${list ? `<p class="pt-modal-note">As fichas que as cartas deste deck criam, com a arte da impressão.</p><ul class="pt-zone-list is-tokens">${list}</ul>` : '<p class="pt-modal-note">Nenhuma carta do deck cria fichas conhecidas. Use a ficha personalizada.</p>'}
      <form class="pt-token-form" data-token-form>
        <h3>Ficha personalizada</h3>
        <label class="pt-field">Nome<input name="name" required maxlength="40" value="Soldado"></label>
        <label class="pt-field">Tipo<select name="type"><option>Criatura</option><option>Artefato</option><option>Encantamento</option><option>Artefato — Tesouro</option><option>Emblema</option></select></label>
        <label class="pt-field is-short">Força<input name="power" type="number" value="1" min="0" max="99"></label>
        <label class="pt-field is-short">Resist.<input name="toughness" type="number" value="1" min="0" max="99"></label>
        <label class="pt-field">Cor<select name="color"><option value="W">Branca</option><option value="U">Azul</option><option value="B">Preta</option><option value="R">Vermelha</option><option value="G">Verde</option><option value="C" selected>Incolor</option></select></label>
        <label class="pt-field is-short">Qtd.<input name="count" type="number" value="1" min="1" max="30"></label>
        <button class="pt-btn is-strong">Criar</button>
      </form>`, (event) => {
      const button = event.target.closest('[data-token]');
      if (!button) return;
      const i = Number(button.dataset.token);
      const count = Math.max(1, Math.min(30, Number(modalCard.querySelector(`[data-token-qty="${i}"]`).value) || 1));
      closeModal();
      act('token', () => createToken(`T${i}`, count));
    });
    $('[data-token-form]').addEventListener('submit', (event) => {
      event.preventDefault();
      const f = event.target.elements;
      const creature = f.type.value === 'Criatura';
      const custom = { name: f.name.value.trim() || 'Ficha', type_line: creature ? 'Token Creature' : `Token ${f.type.value === 'Artefato — Tesouro' ? 'Artifact — Treasure' : f.type.value === 'Artefato' ? 'Artifact' : f.type.value === 'Encantamento' ? 'Enchantment' : 'Emblem'}`,
        power: creature ? String(f.power.value) : null, toughness: creature ? String(f.toughness.value) : null, colors: [f.color.value], image: null, text: '', haste: false };
      const count = Math.max(1, Math.min(30, Number(f.count.value) || 1));
      closeModal();
      act('token', () => { S.custom.push(custom); createToken(`X${S.custom.length}`, count, custom); });
    });
  }

  function openDice() {
    closeMenu();
    openModal(`<h2>Dados e moeda</h2><div class="pt-dice-result" data-dice-result aria-live="polite">—</div>
      <div class="pt-modal-actions is-center"><button type="button" class="pt-btn" data-roll="6">d6</button><button type="button" class="pt-btn" data-roll="20">d20</button><button type="button" class="pt-btn" data-roll="coin">Moeda</button></div>`, (event) => {
      const roll = event.target.closest('[data-roll]')?.dataset.roll;
      if (!roll) return;
      const out = $('[data-dice-result]');
      const result = roll === 'coin' ? (Math.random() < 0.5 ? 'Cara' : 'Coroa') : String(1 + Math.floor(Math.random() * Number(roll)));
      out.classList.remove('is-rolling'); void out.offsetWidth; out.classList.add('is-rolling');
      out.textContent = result;
      log(roll === 'coin' ? `Moeda: ${result}.` : `d${roll}: ${result}.`);
      renderLog(); save();
    });
  }

  function openRestart() {
    closeMenu();
    openModal(`<h2>Nova partida</h2><p>O grimório é embaralhado de novo, a comandante volta para a zona de comando e você compra 7.</p>
      <label class="pt-check"><input type="checkbox" data-two-player ${S.twoPlayer ? 'checked' : ''}> Partida a dois: quem começa não compra no 1º turno <small class="pt-rule">103.8a</small></label>
      <div class="pt-modal-actions"><button type="button" class="pt-btn is-strong" data-restart>Começar</button></div>`, (event) => {
      if (!event.target.closest('[data-restart]')) return;
      const two = !!modalCard.querySelector('[data-two-player]')?.checked;
      closeModal();
      history.length = 0;
      newGame(two);
      render(); save();
    });
  }

  /* ---------- Mão ---------- */
  const handEl = $('[data-pt-hand]');
  const freshHand = new Set();
  function renderHand() {
    const list = S.zones.hand;
    const n = list.length;
    handEl.style.setProperty('--n', n);
    handEl.style.setProperty('--overlap', `${n <= 6 ? 14 : Math.min(92, 14 + (n - 6) * 9)}px`);
    handEl.innerHTML = list.map((iid, i) => {
      const inst = S.cards[iid]; const card = faceOf(inst);
      const picked = S.bottom.includes(iid);
      return `<button type="button" class="pt-card${freshHand.has(iid) ? ' is-new' : ''}${picked ? ' is-picked' : ''}" data-hand="${iid}" style="--i:${(i - (n - 1) / 2).toFixed(2)};--d:${(freshHand.has(iid) ? [...freshHand].indexOf(iid) : 0) * 70}ms" aria-label="${esc(card.name)}${picked ? ' (vai para o fundo)' : ''}"><img src="${esc(card.image)}" alt="" width="488" height="680" draggable="false"></button>`;
    }).join('');
    freshHand.clear();
  }
  let handDrag = null;
  handEl.addEventListener('pointerdown', (event) => {
    const el = event.target.closest('[data-hand]');
    if (!el || event.button !== 0) return;
    handDrag = { iid: el.dataset.hand, el, x: event.clientX, y: event.clientY, moved: false, pointer: event.pointerId };
  });
  window.addEventListener('pointermove', (event) => {
    if (!handDrag) return;
    if (!handDrag.moved && Math.hypot(event.clientX - handDrag.x, event.clientY - handDrag.y) > 8) {
      if (S.stage !== 'play') { handDrag = null; return; }
      handDrag.moved = true;
      handDrag.el.classList.add('is-dragging');
      hidePreview();
      root.classList.add('is-dragging-card');
    }
    if (!handDrag.moved) return;
    const spot = scene.showGhost(faceOf(S.cards[handDrag.iid]).image, event.clientX, event.clientY);
    const overHand = event.clientY > handEl.getBoundingClientRect().top + 30;
    handDrag.el.style.opacity = spot.inside && !overHand ? '0' : '1';
    handDrag.el.style.translate = `${event.clientX - handDrag.x}px ${event.clientY - handDrag.y}px`;
  });
  window.addEventListener('pointerup', (event) => {
    if (!handDrag) return;
    const drag = handDrag; handDrag = null;
    root.classList.remove('is-dragging-card');
    if (!drag.moved) return;
    drag.el.style.translate = ''; drag.el.style.opacity = ''; drag.el.classList.remove('is-dragging');
    scene.hideGhost();
    const overHand = event.clientY > handEl.getBoundingClientRect().top + 30;
    const spot = scene.pick(event.clientX, event.clientY);
    if (!spot.inside || overHand) return;
    if (spot.zone === 'graveyard' || spot.zone === 'exile') act('move', () => move(drag.iid, spot.zone));
    else if (spot.zone === 'library') act('move', () => move(drag.iid, 'library'));
    else playFromHand(drag.iid, { x: spot.x, z: spot.z });
  });
  handEl.addEventListener('click', (event) => {
    const el = event.target.closest('[data-hand]');
    if (!el) return;
    if (el.classList.contains('is-dragging')) return;
    const rect = el.getBoundingClientRect();
    if (S.stage === 'bottom') { toggleBottom(el.dataset.hand); return; }
    if (event.detail >= 2) { closeMenu(); playFromHand(el.dataset.hand); return; }
    openHandMenu(el.dataset.hand, rect.left + rect.width / 2, rect.top - 8);
  });
  handEl.addEventListener('contextmenu', (event) => {
    const el = event.target.closest('[data-hand]');
    if (!el) return;
    event.preventDefault();
    openHandMenu(el.dataset.hand, event.clientX, event.clientY);
  });
  handEl.addEventListener('keydown', (event) => {
    const el = event.target.closest('[data-hand]');
    if (!el) return;
    if (event.key === 'Enter' && S.stage === 'play') { event.preventDefault(); playFromHand(el.dataset.hand); }
    if (event.key === 'ContextMenu' || (event.shiftKey && event.key === 'F10')) { event.preventDefault(); const r = el.getBoundingClientRect(); openHandMenu(el.dataset.hand, r.left, r.top); }
  });
  handEl.addEventListener('pointerover', (event) => { const el = event.target.closest('[data-hand]'); if (el && !handDrag) showPreview(S.cards[el.dataset.hand], el); });
  handEl.addEventListener('pointerleave', () => hidePreview());
  handEl.addEventListener('focusin', (event) => { const el = event.target.closest('[data-hand]'); if (el) showPreview(S.cards[el.dataset.hand], el); });

  function toggleBottom(iid) {
    const need = Math.max(0, S.mulligans - 1);
    const at = S.bottom.indexOf(iid);
    if (at >= 0) S.bottom.splice(at, 1);
    else if (S.bottom.length < need) S.bottom.push(iid);
    render();
  }

  /* ---------- Pré-visualização ---------- */
  const preview = $('[data-pt-preview]');
  function showPreview(inst, anchor = null) {
    if (!inst || !S.cards[inst.iid]) return;
    const face = faceOf(inst); const card = cardOf(inst);
    const pt = ptOf(inst);
    const counters = Object.entries(inst.counters).filter(([, n]) => n > 0).map(([name, n]) => `${n}× ${name}`);
    const state = [inst.token ? 'Ficha' : '', inst.commander ? 'Comandante' : '', inst.tapped ? 'Virada' : '', inst.attacking ? 'Atacando' : '', isSick(inst) ? 'Enjoo de invocação' : '', inst.faceDown ? 'Virada para baixo' : '', inst.flipped ? 'Transformada' : ''].filter(Boolean);
    const image = face.image === 'back' ? null : face.image;
    preview.innerHTML = `${image ? `<img src="${esc(image)}" alt="${esc(face.name)}" width="488" height="680">` : `<div class="pt-preview-text"><strong>${esc(face.name)}</strong><small>${esc(face.type_line)}</small><p>${esc(face.text || '')}</p></div>`}
      <div class="pt-preview-meta"><strong>${esc(face.name)}</strong>${pt ? `<b class="is-${pt.state}">${esc(pt.text)}</b>` : ''}${state.length ? `<span>${esc(state.join(' · '))}</span>` : ''}${counters.length ? `<span>${esc(counters.join(' · '))}</span>` : ''}${card.back && !inst.faceDown ? `<span>Dupla face — ${inst.flipped ? 'mostrando o verso' : 'use Transformar'}</span>` : ''}</div>`;
    preview.hidden = false;
    const rect = root.getBoundingClientRect();
    const x = anchor ? anchor.getBoundingClientRect().left + anchor.getBoundingClientRect().width / 2 : lastPointer.x;
    preview.classList.toggle('is-left', x > rect.left + rect.width * 0.55);
    preview.classList.toggle('is-right', x <= rect.left + rect.width * 0.55);
  }
  function hidePreview() { preview.hidden = true; }
  const lastPointer = { x: 0, y: 0 };
  root.addEventListener('pointermove', (event) => { lastPointer.x = event.clientX; lastPointer.y = event.clientY; });
  function hoverTarget(target) {
    if (!S) return;
    hovered = target?.iid || null;
    if (target?.iid) showPreview(S.cards[target.iid]);
    else if (target?.zone === 'graveyard' || target?.zone === 'exile') { const list = S.zones[target.zone]; if (list.length) showPreview(S.cards[list[list.length - 1]]); else hidePreview(); }
    else if (target?.zone === 'command' && S.zones.command.length) showPreview(S.cards[S.zones.command[0]]);
    else hidePreview();
  }

  /* ---------- Avisos ---------- */
  const toasts = $('[data-pt-toasts]');
  function toast(text, { rule = null, kind = 'rule' } = {}) {
    const el = document.createElement('div');
    el.className = `pt-toast is-${kind}`;
    el.innerHTML = `<p>${esc(text)}</p>${rule ? `<small class="pt-rule" title="Regra das Comprehensive Rules">${esc(rule)}</small>` : ''}`;
    toasts.prepend(el);
    while (toasts.children.length > 4) toasts.lastChild.remove();
    setTimeout(() => { el.classList.add('is-out'); setTimeout(() => el.remove(), 400); }, kind === 'win' ? 7000 : 5200);
  }
  function floatText(text, anchor) {
    if (!anchor) return;
    const el = document.createElement('span');
    el.className = 'pt-float';
    el.textContent = text;
    const rect = anchor.getBoundingClientRect(); const base = root.getBoundingClientRect();
    el.style.left = `${rect.left - base.left + rect.width / 2}px`; el.style.top = `${rect.bottom - base.top}px`;
    root.appendChild(el);
    setTimeout(() => el.remove(), 1400);
  }

  /* ---------- Desenho da interface ---------- */
  const mulliganBar = document.createElement('div');
  mulliganBar.className = 'pt-mulligan';
  root.appendChild(mulliganBar);
  const combatBar = document.createElement('div');
  combatBar.className = 'pt-combat';
  root.appendChild(combatBar);

  function renderHud() {
    $('[data-pt-turn]').textContent = S.stage === 'play' ? `Turno ${S.turn}` : 'Mão inicial';
    $('[data-pt-phases]').innerHTML = PHASES.map((phase, i) => `<li${i === S.phase && S.stage === 'play' ? ' aria-current="step"' : ''}${i < S.phase ? ' class="is-done"' : ''}>${esc(phase)}</li>`).join('');
    ['life', 'poison', 'opponent'].forEach((key) => { $(`[data-pt-value="${key}"]`).textContent = S[key]; });
    $('[data-pt-meter="life"]').classList.toggle('is-danger', S.life <= 10);
    $('[data-pt-meter="poison"]').classList.toggle('is-danger', S.poison >= 7);
    $('[data-pt-meter="opponent"]').classList.toggle('is-win', S.opponent <= 0);
    const badge = $('[data-pt-lands]');
    badge.textContent = `Terreno ${S.lands}/1`;
    badge.classList.toggle('is-done', S.lands === 1);
    badge.classList.toggle('is-warn', S.lands > 1);
    badge.hidden = S.stage !== 'play';
    $('[data-pt-meter="opponent"] > span').innerHTML = S.killTurn !== null ? `Oponente <em>· caiu no T${S.killTurn}</em>` : 'Oponente';
    // Mulligan.
    if (S.stage === 'play') mulliganBar.hidden = true;
    else {
      mulliganBar.hidden = false;
      const toBottom = Math.max(0, S.mulligans - 1);
      if (S.stage === 'bottom') {
        mulliganBar.innerHTML = `<strong>Escolha ${toBottom} carta${toBottom > 1 ? 's' : ''} para o fundo do grimório</strong><span>Clique nelas na mão (${S.bottom.length}/${toBottom}). <small class="pt-rule">103.5</small></span><button type="button" class="pt-btn is-strong" data-pt-action="confirm-bottom" ${S.bottom.length === toBottom ? '' : 'disabled'}>Confirmar</button>`;
      } else {
        mulliganBar.innerHTML = `<strong>Mão inicial${S.mulligans ? ` · ${S.mulligans} mulligan${S.mulligans > 1 ? 's' : ''}` : ''}</strong><span>${S.mulligans === 0 ? 'Em Commander, o primeiro mulligan é grátis.' : `Ao manter, ${toBottom} carta${toBottom === 1 ? '' : 's'} vai para o fundo.`} <small class="pt-rule">103.5</small></span><button type="button" class="pt-btn is-strong" data-pt-action="keep">Manter</button><button type="button" class="pt-btn" data-pt-action="mulligan">Mulligan</button>`;
      }
    }
    // Combate.
    const list = attackers();
    combatBar.hidden = !(S.stage === 'play' && S.phase === 4);
    if (!combatBar.hidden) {
      const total = list.reduce((sum, inst) => sum + Math.max(0, ptOf(inst)?.p ?? 0), 0);
      combatBar.innerHTML = `<strong>Combate</strong><span>${list.length ? `${list.length} atacante${list.length > 1 ? 's' : ''} · <b>${total}</b> de dano` : 'Clique nas criaturas para declarar ataque.'}</span><button type="button" class="pt-btn is-strong" data-pt-action="damage" ${list.length ? '' : 'disabled'}>Causar ${total} ao oponente</button>`;
    }
  }

  function renderLog() {
    $('[data-pt-log]').innerHTML = S.log.slice(0, 80).map((entry) => `<li><small>T${entry.turn}</small>${esc(entry.text)}</li>`).join('');
  }

  function sceneView() {
    return {
      battlefield: S.zones.battlefield.map((iid) => {
        const inst = S.cards[iid]; const face = faceOf(inst); const pt = ptOf(inst);
        return {
          iid, x: inst.x, z: inst.z, tapped: inst.tapped, attacking: inst.attacking, token: inst.token, commander: inst.commander,
          image: imageOf(inst), fallback: { name: face.name, type: typeOf(inst), pt: pt?.text }, fallbackKey: `${face.name}|${pt?.text || ''}`,
          counters: Object.entries(inst.counters).filter(([, n]) => n > 0).map(([name, n]) => ({ name, n, short: name.length > 8 ? name.slice(0, 7) + '.' : name })),
          pt, sick: isSick(inst), stack: inst.stack || 1, buried: !!inst.buried,
        };
      }),
      piles: {
        library: { n: S.zones.library.length },
        graveyard: { n: S.zones.graveyard.length, top: S.zones.graveyard.length ? imageOf(S.cards[S.zones.graveyard[S.zones.graveyard.length - 1]]) : null },
        exile: { n: S.zones.exile.length, top: S.zones.exile.length ? imageOf(S.cards[S.zones.exile[S.zones.exile.length - 1]]) : null },
        command: { image: S.zones.command.length ? faceOf(S.cards[S.zones.command[0]]).image : null, tax: S.zones.command.length ? S.casts * 2 : 0 },
      },
    };
  }

  function render() {
    arrange();
    scene.sync(sceneView());
    while (pendingFx.length) pendingFx.shift()();
    renderHud();
    renderHand();
    renderLog();
    // A pré-visualização acompanha a carta sob o mouse: marcadores, virada, P/R.
    if (!preview.hidden && hovered) { if (S.cards[hovered]?.zone === 'battlefield') showPreview(S.cards[hovered]); else hidePreview(); }
  }

  /* ---------- Salvar no navegador ---------- */
  let saveTimer = 0;
  function save() {
    clearTimeout(saveTimer);
    saveTimer = setTimeout(() => { try { localStorage.setItem(storeKey, JSON.stringify(S)); } catch { /* sem espaço: só não lembra */ } }, 250);
  }
  function load() {
    try {
      const saved = JSON.parse(localStorage.getItem(storeKey) || 'null');
      if (saved && saved.v === 1 && saved.sig === signature) return saved;
    } catch { /* partida salva ilegível: começa outra */ }
    return null;
  }

  /* ---------- Botões e teclado ---------- */
  root.addEventListener('click', (event) => {
    const button = event.target.closest('[data-pt-action]');
    if (button) {
      const action = button.dataset.ptAction;
      const needPlay = ['draw', 'untap', 'turn', 'phase', 'mill', 'token', 'scry', 'search', 'shuffle'].includes(action);
      if (needPlay && S.stage !== 'play') { toast('Primeiro, decida a mão inicial: manter ou mulligan.', { kind: 'info' }); return; }
      ({
        draw: () => act('draw', () => drawCards(1)),
        untap: () => act('untap', () => untapAll()),
        turn: () => act('turn', nextTurn),
        phase: () => act('phase', nextPhase),
        shuffle: shuffleLibrary,
        scry: openScry,
        search: () => openZone('library'),
        mill: () => mill(1),
        token: openTokens,
        dice: openDice,
        undo,
        restart: openRestart,
        fullscreen: toggleFullscreen,
        keep: () => { if (S.mulligans > 1) { act('bottom', () => { S.stage = 'bottom'; }); } else act('keep', keepHand); },
        'confirm-bottom': () => act('keep', keepHand),
        mulligan: () => act('mulligan', mulligan),
        damage: applyDamage,
        log: () => { const list = $('[data-pt-log]'); list.hidden = !list.hidden; button.setAttribute('aria-expanded', String(!list.hidden)); },
      }[action] || (() => {}))();
      return;
    }
    const adjust = event.target.closest('[data-pt-adjust]');
    if (adjust) {
      const [key, delta] = adjust.dataset.ptAdjust.split(':');
      const step = Number(delta) * (event.shiftKey ? 5 : 1);
      act('adjust', () => { S[key] = Math.max(key === 'poison' ? 0 : -999, S[key] + step); log(`${{ life: 'Vida', poison: 'Veneno', opponent: 'Oponente' }[key]}: ${step > 0 ? '+' : ''}${step} (${S[key]}).`); });
      floatText(`${step > 0 ? '+' : ''}${step}`, adjust.closest('.pt-meter'));
    }
  });

  function undo() {
    const previous = history.pop();
    if (!previous) { toast('Nada para desfazer.', { kind: 'info' }); return; }
    S = JSON.parse(previous);
    closeMenu(); closeModal();
    render(); save();
    toast('Ação desfeita.', { kind: 'info' });
  }

  function toggleFullscreen() {
    const button = $('[data-pt-action="fullscreen"]');
    const set = (on) => { root.classList.toggle('is-fullscreen', on); button.setAttribute('aria-pressed', String(on)); document.documentElement.classList.toggle('pt-lock', on && document.fullscreenElement !== root); requestAnimationFrame(() => scene.resize()); };
    if (document.fullscreenElement === root) { document.exitFullscreen(); return; }
    if (root.classList.contains('is-fullscreen')) { set(false); return; }
    if (root.requestFullscreen) root.requestFullscreen().then(() => set(true)).catch(() => set(true)); else set(true);
    document.onfullscreenchange = () => { if (document.fullscreenElement !== root) set(false); };
  }

  document.addEventListener('keydown', (event) => {
    const target = event.target instanceof Element ? event.target : document.body;
    if (target.closest('input, textarea, select')) return;
    if (event.key === 'Escape') { if (!modal.hidden) { const onClose = modal.onClose; closeModal(); onClose?.(); } else closeMenu(); return; }
    if (!modal.hidden) return;
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'z') { event.preventDefault(); undo(); return; }
    if (event.ctrlKey || event.metaKey || event.altKey) return;
    const key = event.key.toLowerCase();
    if (hovered && S.cards[hovered] && S.cards[hovered].zone === 'battlefield') {
      const iid = hovered;
      const cardKeys = { t: () => toggleTap(iid), a: () => (isCreature(S.cards[iid]) ? toggleAttack(iid) : null), g: () => act('move', () => move(iid, 'graveyard')), e: () => act('move', () => move(iid, 'exile')), h: () => act('move', () => move(iid, 'hand')), c: () => openCounters(iid), '+': () => act('counter', () => addCounter(iid, '+1/+1', 1)), '=': () => act('counter', () => addCounter(iid, '+1/+1', 1)), '-': () => act('counter', () => addCounter(iid, (S.cards[iid].counters['+1/+1'] || 0) ? '+1/+1' : '-1/-1', (S.cards[iid].counters['+1/+1'] || 0) ? -1 : 1)) };
      if (cardKeys[key]) { event.preventDefault(); cardKeys[key](); return; }
    }
    if (S.stage !== 'play') return;
    const keys = { d: () => act('draw', () => drawCards(1)), u: () => act('untap', () => untapAll()), n: () => act('turn', nextTurn), ' ': () => act('phase', nextPhase), s: shuffleLibrary, x: openScry, f: () => openZone('library'), m: () => mill(1), k: openTokens };
    if (key === ' ' && target.closest('button')) return;
    if (keys[key]) { event.preventDefault(); keys[key](); }
  });

  /* ---------- Começo ---------- */
  const saved = load();
  if (saved) { S = saved; toast(`Partida retomada no turno ${S.turn}. “Nova partida” recomeça do zero.`, { kind: 'info' }); }
  else newGame(false);
  render();
  save();
}
