/* Mesa de teste do Deckarium: uma partida manual com as cartas do deck — sozinho ou numa mesa compartilhada com até
   três amigos (data.table). Aqui ficam o estado, as zonas, as regras que dá para conferir e a interface; o desenho 3D
   fica em playtest-scene.js e a conversa com o servidor em playtest-net.js. Toda ação passa por act(), que guarda o
   passo anterior (Desfazer), salva a partida no navegador e, na mesa compartilhada, publica o que é público. */
const root = document.querySelector('[data-playtest]');
const dataEl = document.getElementById('playtest-data');
if (root && dataEl) start(JSON.parse(dataEl.textContent));

async function start(data) {
  const $ = (sel) => root.querySelector(sel);
  const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const PHASES = ['Desvirar', 'Manutenção', 'Compra', 'Principal 1', 'Combate', 'Principal 2', 'Final'];
  const COUNTERS = ['+1/+1', '-1/-1', 'Lealdade', 'Carga', 'Tempo', 'Escudo'];
  const FORMAT = data.format;
  const MP = data.table || null;
  const ME = MP ? MP.seat : null;
  const signature = data.library.map((c) => `${c.id}:${c.quantity}`).join(',') + '|' + (data.commander?.id || '') + '|' + (data.signature?.id || '') + '|' + FORMAT.key;
  const storeKey = MP ? `deckarium-playtest-mp-${MP.token}` : `deckarium-playtest-v2-${data.deck.id}`;

  /* ---------- Cartas ---------- */
  const refs = new Map();
  data.library.forEach((card, i) => refs.set(`L${i}`, card));
  if (data.commander) refs.set('C', data.commander);
  if (data.signature) refs.set('S', data.signature);
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

  /* ---------- Mesa compartilhada: assentos e estado da mesa ---------- */
  const T = { status: MP ? 'lobby' : 'solo', game: 0, starting: null, turn: null, number: 0, phase: 0, host: null, version: 0 };
  const seatInfo = new Map();   // assento → { player, deck, commander, online, eliminated }
  const seatState = new Map();  // assento → estado público mais recente
  const seatDefs = new Map();   // assento → cartas já vistas daquele jogador
  const feed = [];
  const SEAT_COLORS = ['#e0b54e', '#5fb3ff', '#ff7a5c', '#6fd08c'];
  const seatName = (seat) => (seat === ME ? 'Você' : seatInfo.get(seat)?.player || `Jogador ${seat + 1}`);
  const opponents = () => [...seatInfo.keys()].filter((seat) => seat !== ME).sort((a, b) => ((a - ME + 4) % 4) - ((b - ME + 4) % 4));
  const livingOpponents = () => opponents().filter((seat) => !seatInfo.get(seat)?.eliminated);
  const playerCount = () => (MP ? Math.max(2, seatInfo.size) : (S?.twoPlayer ? 2 : FORMAT.players));
  const startingLife = () => (playerCount() > 2 ? FORMAT.life_multiplayer || FORMAT.life : FORMAT.life);
  const myTurn = () => !MP || (T.status === 'playing' && T.turn === ME);
  // Primeiro mulligan grátis em partidas com 3 ou mais jogadores e no Brawl (103.5c); nas outras, só na mesa solo de Commander.
  const freeMulligan = () => (MP ? playerCount() > 2 || /brawl/.test(FORMAT.key) : FORMAT.free_mulligan);

  /* ---------- Estado ---------- */
  let S = null;
  const history = [];
  function freshState() {
    return { v: 2, sig: signature, seq: 0, cards: {}, zones: { library: [], hand: [], battlefield: [], graveyard: [], exile: [], command: [] },
      turn: MP ? 0 : 1, phase: 3, life: FORMAT.life, poison: 0, opponent: FORMAT.life, lands: 0, mulligans: 0, stage: 'mulligan', bottom: [], log: [], custom: [],
      killTurn: null, twoPlayer: false, first: true, order: 0, game: 0, cmd: {}, turnSeen: 0, appliedEvent: 0, conceded: false };
  }
  function newInstance(ref, extra = {}) {
    const iid = `c${++S.seq}`;
    S.cards[iid] = { iid, ref, zone: null, x: 0, z: 0, tapped: false, faceDown: false, flipped: false, counters: {}, attacking: false, attackTarget: null, entered: 0, manual: false, token: false, commander: false, casts: 0, order: 0, ...extra };
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
  // Registro. Na mesa compartilhada, o texto público (sem o que é segredo) vai para todos.
  let outbox = [];
  function log(text, publicText = text) {
    S.log.unshift({ turn: S.turn, text });
    if (S.log.length > 250) S.log.length = 250;
    if (MP && publicText) outbox.push(publicText);
  }

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
      onPileClick: (zone, event, slot) => pileClick(zone, slot),
      onPileContext: (zone, x, y, slot) => openPileMenu(zone, x, y, slot),
      onCardDrop: (iid, spot) => cardDrop(iid, spot),
      onDragStart: () => hidePreview(),
      onTableContext: (x, y) => openTableMenu(x, y),
      onHover: (target) => hoverTarget(target),
      onSeatClick: (seat, target) => seatClick(seat, target),
      onSeatContext: (seat, target, x, y) => openSeatMenu(seat, x, y, target),
      commandZone: !!(data.commander || FORMAT.leader),
      rightPanel: () => { if (!MP) return null; const box = $('.pt-meters').getBoundingClientRect(); const stage = root.getBoundingClientRect(); return { width: stage.right - box.left + 8, bottom: box.bottom - stage.top + 8 }; },
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
  if (data.playmat) scene.playmat(data.playmat);
  // A faixa de ferramentas fica logo abaixo do HUD, que muda de altura quando quebra a linha.
  // A barra do turno e as ferramentas param antes do painel da direita, que cresce com os oponentes.
  const hud = $('.pt-hud');
  const meters = $('.pt-meters');
  const hudObserver = new ResizeObserver(() => {
    root.style.setProperty('--meters-w', `${meters.offsetWidth + 14}px`);
    root.style.setProperty('--tools-top', `${$('.pt-turn').offsetHeight + 20}px`);
    scene.resize();
  });
  hudObserver.observe(hud);
  hudObserver.observe(meters);

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

  const hidden = (zone) => zone === 'hand' || zone === 'library';
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
      Object.assign(inst, { tapped: false, attacking: false, attackTarget: null, counters: {}, faceDown: false, flipped: false, manual: false, walker: false });
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
        inst.casts = (inst.casts || 0) + 1;
        pendingFx.push(() => scene.fx.commander());
        log(`Lançou ${inst.signature ? 'o feitiço de assinatura' : 'a comandante'} ${name} da zona de comando${inst.casts > 1 ? ` (imposto pago: +${(inst.casts - 1) * 2})` : ''}.`);
      } else {
        const shown = inst.faceDown ? 'uma carta virada para baixo' : name;
        log(`${from === 'hand' ? 'Jogou' : 'Colocou no campo'} ${shown}${from && from !== 'hand' ? ` (de ${zoneName(from)})` : ''}.`);
      }
      if (from === 'hand' && isLand(inst) && !inst.faceDown) {
        S.lands += 1;
        if (S.lands > 1) toast(`Esse é o ${S.lands}º terreno deste turno. Sem um efeito que permita, é só um por turno.`, { rule: '305.2', kind: 'warn' });
      }
    } else {
      const text = `${name}: ${zoneName(from)} → ${zoneName(to, to === 'library' ? opts.position || 'top' : undefined)}.`;
      // Entre mão e grimório a carta continua escondida para os outros.
      log(text, hidden(from) && hidden(to) ? `Uma carta: ${zoneName(from)} → ${zoneName(to, to === 'library' ? opts.position || 'top' : undefined)}.` : text);
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
    if (S.life <= 0 && !S.lostLife) { S.lostLife = true; toast(`Com 0 de vida ou menos, você perderia a partida.${MP ? ' Se foi isso, conceda em “Mesa”.' : ''}`, { rule: '704.5a', kind: 'warn' }); }
    if (S.life > 0) S.lostLife = false;
    if (S.poison >= 10 && !S.lostPoison) { S.lostPoison = true; toast('Dez marcadores de veneno: você perderia a partida.', { rule: '704.5c', kind: 'warn' }); }
    if (S.poison < 10) S.lostPoison = false;
    const limit = FORMAT.commander_damage;
    if (limit && MP) Object.entries(S.cmd).forEach(([seat, amount]) => {
      const flag = `lostCmd${seat}`;
      if (amount >= limit && !S[flag]) { S[flag] = true; toast(`${amount} de dano de combate da comandante de ${seatName(Number(seat))}: com ${limit} ou mais você perderia a partida.`, { rule: '704.6c', kind: 'warn' }); }
    });
    if (!MP && S.opponent <= 0 && S.killTurn === null) {
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
    if (drawn) {
      scene.fx.draw(Math.min(drawn, 7));
      if (!silent) log(drawn === 1 ? `Comprou ${nameOf(S.zones.hand[S.zones.hand.length - 1])}.` : `Comprou ${drawn} cartas.`, drawn === 1 ? 'Comprou 1 carta.' : `Comprou ${drawn} cartas.`);
    }
  }

  function untapAll(silent = false) {
    let count = 0;
    S.zones.battlefield.forEach((iid) => { const inst = S.cards[iid]; if (inst.tapped) { inst.tapped = false; count++; } inst.attacking = false; inst.attackTarget = null; });
    if (!silent && count) log(`Desvirou ${count} permanente(s).`);
  }

  // Quem começa uma partida a dois não compra no primeiro turno (103.8a).
  const skipsFirstDraw = () => S.turn === 1 && S.twoPlayer && S.first;
  function enterPhase(phase) {
    S.phase = phase;
    if (phase === 0) untapAll();
    if (phase === 2) { if (skipsFirstDraw()) toast('Quem começa uma partida a dois não compra no primeiro turno.', { rule: '103.8a', kind: 'info' }); else drawCards(1); }
    if (phase === 4) toast(MP ? 'Combate: clique nas criaturas para atacar; escolha o alvo na faixa de combate e depois aplique o dano.' : 'Combate: clique nas criaturas para atacar e depois aplique o dano ao oponente.', { kind: 'info' });
    if (phase === 6 && S.zones.hand.length > 7) toast(`Você tem ${S.zones.hand.length} cartas: na limpeza, descarte até ficar com 7.`, { rule: '514.1', kind: 'warn' });
  }
  function endCombat() { S.zones.battlefield.forEach((iid) => { S.cards[iid].attacking = false; S.cards[iid].attackTarget = null; }); }
  function nextPhase() {
    if (MP && !myTurn()) { toast(`A fase é de ${seatName(T.turn)}. Você ainda pode virar cartas, lançar mágicas e mexer nas suas zonas.`, { kind: 'info' }); return; }
    if (S.phase === 4) endCombat();
    if (S.phase >= 6) { nextTurn(); return; }
    act('phase', () => enterPhase(S.phase + 1));
    if (MP) net.post('phase', { phase: String(S.phase) }).catch((error) => toast(error.message, { kind: 'warn' }));
  }
  function nextTurn() {
    if (MP) { passTurn(); return; }
    act('turn', () => {
      endCombat();
      if (S.zones.hand.length > 7) toast(`Você terminou o turno com ${S.zones.hand.length} cartas: o certo seria descartar até 7.`, { rule: '514.1', kind: 'warn' });
      S.turn += 1;
      S.lands = 0;
      log(`— Turno ${S.turn} —`);
      [0, 1, 2, 3].forEach(enterPhase);
    });
  }

  function newGame(twoPlayer = false) {
    S = freshState();
    S.twoPlayer = twoPlayer;
    S.life = startingLife();
    S.opponent = startingLife();
    data.library.forEach((card, i) => { for (let k = 0; k < card.quantity; k++) place(newInstance(`L${i}`), 'library'); });
    shuffleList(S.zones.library);
    if (data.commander) place(newInstance('C', { commander: true }), 'command');
    if (data.signature) place(newInstance('S', { commander: true, signature: true }), 'command');
    drawCards(7, true);
    log('Nova partida: grimório embaralhado e 7 cartas na mão.');
    scene.fx.shuffle();
  }

  const toBottomCount = () => Math.max(0, S.mulligans - (freeMulligan() ? 1 : 0));
  function keepHand() {
    const toBottom = toBottomCount();
    if (toBottom && S.bottom.length !== toBottom) return;
    S.bottom.forEach((iid) => place(iid, 'library', 'bottom'));
    if (toBottom) log(`Colocou ${toBottom} carta(s) no fundo do grimório.`);
    S.bottom = [];
    S.stage = 'play';
    log(`Manteve a mão com ${S.zones.hand.length} cartas${S.mulligans ? ` depois de ${S.mulligans} mulligan(s)` : ''}.`);
    if (!MP) { log('— Turno 1 —'); [0, 1, 2, 3].forEach(enterPhase); }
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
  let defaultTarget = null;
  function cardClick(iid) {
    const inst = S.cards[iid];
    if (!inst) return;
    if (S.phase === 4 && S.stage === 'play' && myTurn() && isCreature(inst) && !isLand(inst)) { toggleAttack(iid); return; }
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
  function attackTargetFor() {
    const alive = livingOpponents();
    if (defaultTarget !== null && alive.includes(defaultTarget)) return defaultTarget;
    return alive[0] ?? null;
  }
  function toggleAttack(iid, target) {
    const inst = S.cards[iid];
    act('attack', () => {
      if (inst.attacking && target === undefined) { inst.attacking = false; inst.attackTarget = null; if (!hasWord(inst, 'vigilance')) inst.tapped = false; log(`${nameOf(iid)} não ataca mais.`); return; }
      if (inst.tapped && !inst.attacking) { toast(`${nameOf(iid)} está virada e não pode atacar.`, { rule: '508.1a', kind: 'warn' }); return; }
      if (isSick(inst)) toast(`${nameOf(iid)} entrou neste turno: sem ímpeto, não poderia atacar.`, { rule: '302.6', kind: 'warn' });
      const defender = MP ? (target ?? attackTargetFor()) : null;
      inst.attacking = true;
      inst.attackTarget = defender;
      if (!hasWord(inst, 'vigilance')) inst.tapped = true;
      log(`${nameOf(iid)} ataca${MP && defender !== null ? ` ${seatName(defender)}` : ''}${hasWord(inst, 'vigilance') ? ' (vigilância: não vira)' : ''}.`);
    });
  }
  function attackers() { return S.zones.battlefield.map((iid) => S.cards[iid]).filter((inst) => inst.attacking); }
  function applyDamage() {
    if (MP) { openDamage(); return; }
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

  function pileClick(zone, slot) {
    if (zone === 'library') { if (S.stage === 'play') act('draw', () => drawCards(1)); else toast('Primeiro, decida a mão inicial.', { kind: 'info' }); return; }
    if (zone === 'command') { castCommander(S.zones.command[slot || 0]); return; }
    openZone(zone);
  }
  function castCommander(iid = S.zones.command[0]) {
    if (!iid || !S.cards[iid]) { toast('Não há nada na zona de comando.', { kind: 'info' }); return; }
    if (S.stage !== 'play') { toast('Primeiro, decida a mão inicial.', { kind: 'info' }); return; }
    if (S.cards[iid].signature && !S.zones.battlefield.some((id) => S.cards[id].commander && !S.cards[id].signature)) toast('O feitiço de assinatura só pode ser lançado com o oathbreaker no campo.', { kind: 'warn' });
    act('commander', () => move(iid, 'battlefield'));
  }

  /* ---------- Menus ---------- */
  const menu = $('[data-pt-menu]');
  function openMenu(items, x, y) {
    menu.innerHTML = items.map((item, i) => item === '-' ? '<hr>' : item.title ? `<p class="pt-menu-title">${esc(item.title)}</p>` : `<button type="button" role="menuitem" data-menu-index="${i}"${item.disabled ? ' disabled' : ''}><span>${esc(item.label)}</span>${item.key ? `<kbd>${esc(item.key)}</kbd>` : ''}</button>`).join('');
    menu.hidden = false;
    const rect = root.getBoundingClientRect();
    const box = menu.getBoundingClientRect();
    menu.style.left = `${Math.max(8, Math.min(x - rect.left, rect.width - box.width - 8))}px`;
    menu.style.top = `${Math.max(8, Math.min(y - rect.top, rect.height - box.height - 8))}px`;
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
    const attackItems = isCreature(inst)
      ? (MP && livingOpponents().length > 1 && !inst.attacking
        ? livingOpponents().map((seat) => ({ label: `Atacar ${seatName(seat)}`, action: () => toggleAttack(iid, seat) }))
        : [{ label: inst.attacking ? 'Cancelar ataque' : 'Atacar', key: 'A', action: () => toggleAttack(iid) }])
      : [];
    openMenu([
      { title: `${nameOf(iid)}${pt ? ` · ${pt.text}` : ''}` },
      { label: inst.tapped ? 'Desvirar' : 'Virar', key: 'T', action: () => toggleTap(iid) },
      ...attackItems,
      { label: 'Marcadores…', key: 'C', action: () => openCounters(iid) },
      { label: 'Adicionar +1/+1', key: '+', action: () => act('counter', () => addCounter(iid, '+1/+1', 1)) },
      ...((inst.counters['+1/+1'] || 0) > 0 ? [{ label: 'Tirar +1/+1', key: '−', action: () => act('counter', () => addCounter(iid, '+1/+1', -1)) }] : []),
      '-',
      ...(card.back && !inst.faceDown ? [{ label: inst.flipped ? 'Voltar para a frente' : 'Transformar', action: () => act('flip', () => { inst.flipped = !inst.flipped; pendingFx.push(() => scene.fx.sparkle(inst.x, inst.z, '#ffffff')); log(`${card.name} transformou.`); }) }] : []),
      { label: inst.faceDown ? 'Desvirar a face (revelar)' : 'Virar a face para baixo', action: () => act('face', () => { inst.faceDown = !inst.faceDown; log(`${inst.faceDown ? 'Virou para baixo' : 'Revelou'} ${faceOf(inst).name}.`, inst.faceDown ? 'Virou uma permanente para baixo.' : `Revelou ${faceOf(inst).name}.`); }) },
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
      ...(MP ? [{ label: 'Mostrar para a mesa', action: () => revealToTable(inst, 'da mão') }] : []),
      '-',
      { label: 'Descartar', action: () => act('move', () => move(iid, 'graveyard')) },
      { label: 'Exilar', action: () => act('move', () => move(iid, 'exile')) },
      { label: 'Para o topo do grimório', action: () => act('move', () => move(iid, 'library')) },
      { label: 'Para o fundo do grimório', action: () => act('move', () => move(iid, 'library', { position: 'bottom' })) },
    ], x, y);
  }
  function openPileMenu(zone, x, y, slot = 0) {
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
        { label: 'Revelar o topo', key: 'R', action: revealTopOnce },
        { label: S.revealTop ? 'Esconder o topo' : 'Jogar com o topo revelado', action: toggleRevealTop },
      ], x, y);
    } else if (zone === 'command') {
      const items = S.zones.command.map((iid) => ({ label: `Lançar ${nameOf(iid)}${S.cards[iid].casts ? ` (+${S.cards[iid].casts * 2})` : ''}`, action: () => castCommander(iid) }));
      openMenu([{ title: 'Zona de comando' }, ...(items.length ? items : [{ label: 'Nada na zona de comando', disabled: true, action: () => {} }])], x, y);
    } else {
      openMenu([{ title: `${zone === 'graveyard' ? 'Cemitério' : 'Exílio'} · ${S.zones[zone].length}` }, { label: 'Ver as cartas…', action: () => openZone(zone) }], x, y);
    }
  }
  /* ---------- Topo revelado ---------- */
  // Avulso: a carta do topo vira sobre o grimório por alguns segundos (ou até o topo mudar).
  // Sempre revelado: para cartas como Courser of Kruphix e Future Sight, o topo fica virado e acompanha as compras.
  let revealOnce = null;
  let revealTimer = 0;
  function revealTopOnce() {
    closeMenu();
    const top = S.zones.library[0];
    if (!top) { toast('O grimório está vazio.', { kind: 'info' }); return; }
    revealOnce = top;
    clearTimeout(revealTimer);
    revealTimer = setTimeout(() => { revealOnce = null; syncReveal(); render(); }, 6000);
    log(`Revelou o topo do grimório: ${nameOf(top)}.`);
    if (MP) { sendReveal(S.cards[top], 'do topo do grimório'); flushOutbox(); }
    syncReveal(); renderLog(); save(); publish();
    showPreview(S.cards[top], null, 'left');
  }
  function toggleRevealTop() {
    act('reveal', () => { S.revealTop = !S.revealTop; log(S.revealTop ? 'Passou a jogar com o topo do grimório revelado.' : 'O topo do grimório voltou a ficar escondido.'); });
  }
  const topRevealed = () => { const top = S.zones.library[0]; return !!top && (S.revealTop || revealOnce === top); };
  function syncReveal() {
    const top = S.zones.library[0];
    if (revealOnce && revealOnce !== top) revealOnce = null;
    scene.revealTop(topRevealed() ? imageOf(S.cards[top]) : null);
  }

  /** Botão direito no tapete, fora das cartas e das pilhas: as ações da partida. */
  function openTableMenu(x, y) {
    const playing = S.stage === 'play';
    openMenu([
      { title: playing ? (MP ? turnTitle() : `Turno ${S.turn} · ${PHASES[S.phase]}`) : 'Mão inicial' },
      { label: 'Comprar', key: 'D', disabled: !playing, action: () => act('draw', () => drawCards(1)) },
      { label: 'Desvirar tudo', key: 'U', disabled: !playing, action: () => act('untap', () => untapAll()) },
      { label: 'Próxima fase', key: 'Espaço', disabled: !playing || !myTurn(), action: nextPhase },
      { label: MP ? 'Passar o turno' : 'Próximo turno', key: 'N', disabled: !playing || !myTurn(), action: nextTurn },
      '-',
      { label: 'Criar fichas…', key: 'K', disabled: !playing, action: openTokens },
      { label: 'Olhar o topo…', key: 'X', disabled: !playing, action: openScry },
      { label: 'Revelar o topo', key: 'R', disabled: !playing, action: revealTopOnce },
      { label: 'Embaralhar', key: 'S', disabled: !playing, action: shuffleLibrary },
      '-',
      ...(MP ? [{ label: 'Ver a mesa toda', key: 'V', action: () => setView('table') }, { label: 'Ver o meu tapete', action: () => setView('me') }, '-'] : []),
      { label: 'Dados e moeda…', action: openDice },
      { label: 'Desfazer', key: 'Ctrl Z', action: undo },
    ], x, y);
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
      <p>Quando ${S.cards[iid].signature ? 'o feitiço de assinatura' : 'a comandante'} iria para o cemitério, o exílio, a mão ou o grimório, você pode mandá-lo para a zona de comando em vez disso <small class="pt-rule">903.9</small>. Na próxima vez, lançá-lo custa mais {2} por vez que ele já foi lançado de lá.</p>
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
        const shown = chosen.filter((p) => ['graveyard', 'exile'].includes(p.to)).map((p) => nameOf(p.iid));
        log(`Olhou ${chosen.length} do topo${moved.length ? `: ${moved.map(([key, n]) => `${n} para ${key === 'bottom' ? 'o fundo' : toZone[key]}`).join(', ')}` : ''}.${shown.length ? ` (${shown.join(', ')})` : ''}`);
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
      <div class="pt-modal-actions is-center"><button type="button" class="pt-btn" data-roll="6">d6</button><button type="button" class="pt-btn" data-roll="20">d20</button><button type="button" class="pt-btn" data-roll="coin">Moeda</button></div>
      ${MP ? '<p class="pt-modal-note">O resultado aparece para toda a mesa.</p>' : ''}`, (event) => {
      const roll = event.target.closest('[data-roll]')?.dataset.roll;
      if (!roll) return;
      const out = $('[data-dice-result]');
      const result = roll === 'coin' ? (Math.random() < 0.5 ? 'Cara' : 'Coroa') : String(1 + Math.floor(Math.random() * Number(roll)));
      out.classList.remove('is-rolling'); void out.offsetWidth; out.classList.add('is-rolling');
      out.textContent = result;
      const text = roll === 'coin' ? `Moeda: ${result}.` : `d${roll}: ${result}.`;
      S.log.unshift({ turn: S.turn, text });
      if (MP) net.event('dice', { text }).catch(() => {});
      renderLog(); save();
    });
  }

  function openRestart() {
    closeMenu();
    const two = FORMAT.players === 2;
    openModal(`<h2>Nova partida</h2><p>O grimório é embaralhado de novo, ${data.commander ? 'a zona de comando volta ao começo' : 'o campo é limpo'} e você compra 7.</p>
      <label class="pt-check"><input type="checkbox" data-two-player ${S.twoPlayer || (two && !S.log.length) ? 'checked' : ''}> Partida a dois: quem começa não compra no 1º turno <small class="pt-rule">103.8a</small></label>
      <div class="pt-modal-actions"><button type="button" class="pt-btn is-strong" data-restart>Começar</button></div>`, (event) => {
      if (!event.target.closest('[data-restart]')) return;
      const twoPlayer = !!modalCard.querySelector('[data-two-player]')?.checked;
      closeModal();
      history.length = 0;
      newGame(twoPlayer);
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
    const need = toBottomCount();
    const at = S.bottom.indexOf(iid);
    if (at >= 0) S.bottom.splice(at, 1);
    else if (S.bottom.length < need) S.bottom.push(iid);
    render();
  }

  /* ---------- Pré-visualização ---------- */
  const preview = $('[data-pt-preview]');
  /** info: { face, card, pt, state: [], counters: [], owner } */
  function renderPreview(info, anchorX = null, side = null) {
    const face = info.face;
    const image = face.image === 'back' ? null : face.image;
    preview.innerHTML = `${image ? `<img src="${esc(image)}" alt="${esc(face.name)}" width="488" height="680">` : `<div class="pt-preview-text"><strong>${esc(face.name)}</strong><small>${esc(face.type_line || '')}</small><p>${esc(face.text || '')}</p></div>`}
      <div class="pt-preview-meta">${info.owner ? `<span class="pt-preview-owner">${esc(info.owner)}</span>` : ''}<strong>${esc(face.name)}</strong>${info.pt ? `<b class="is-${info.pt.state || 'normal'}">${esc(info.pt.text)}</b>` : ''}${info.state.length ? `<span>${esc(info.state.join(' · '))}</span>` : ''}${info.counters.length ? `<span>${esc(info.counters.join(' · '))}</span>` : ''}${info.back ? `<span>${esc(info.back)}</span>` : ''}</div>`;
    preview.hidden = false;
    const rect = root.getBoundingClientRect();
    const x = anchorX ?? lastPointer.x;
    const left = side ? side === 'left' : x > rect.left + rect.width * 0.55;
    preview.classList.toggle('is-left', left);
    preview.classList.toggle('is-right', !left);
  }
  function showPreview(inst, anchor = null, side = null) {
    if (!inst || !S.cards[inst.iid]) return;
    const face = faceOf(inst); const card = cardOf(inst);
    const counters = Object.entries(inst.counters).filter(([, n]) => n > 0).map(([name, n]) => `${n}× ${name}`);
    const state = [inst.token ? 'Ficha' : '', inst.commander ? (inst.signature ? 'Feitiço de assinatura' : FORMAT.leader_label || 'Comandante') : '', inst.tapped ? 'Virada' : '', inst.attacking ? `Atacando${MP && inst.attackTarget !== null ? ` ${seatName(inst.attackTarget)}` : ''}` : '', isSick(inst) ? 'Enjoo de invocação' : '', inst.faceDown ? 'Virada para baixo' : '', inst.flipped ? 'Transformada' : ''].filter(Boolean);
    const anchorX = anchor ? anchor.getBoundingClientRect().left + anchor.getBoundingClientRect().width / 2 : null;
    renderPreview({ face, pt: ptOf(inst), state, counters, back: card.back && !inst.faceDown ? `Dupla face — ${inst.flipped ? 'mostrando o verso' : 'use Transformar'}` : '' }, anchorX, side);
  }
  function hidePreview() { preview.hidden = true; }
  const lastPointer = { x: 0, y: 0 };
  root.addEventListener('pointermove', (event) => { lastPointer.x = event.clientX; lastPointer.y = event.clientY; });
  function hoverTarget(target) {
    if (!S) return;
    if (target && target.seat !== undefined) { hovered = null; previewSeat(target.seat, target); return; }
    hovered = target?.iid || null;
    if (target?.iid) showPreview(S.cards[target.iid]);
    else if (target?.zone === 'graveyard' || target?.zone === 'exile') { const list = S.zones[target.zone]; if (list.length) showPreview(S.cards[list[list.length - 1]]); else hidePreview(); }
    else if (target?.zone === 'command' && S.zones.command.length) showPreview(S.cards[S.zones.command[target.slot || 0]] || S.cards[S.zones.command[0]]);
    else if (target?.zone === 'library' && topRevealed()) showPreview(S.cards[S.zones.library[0]]);
    else hidePreview();
  }

  /* ---------- Avisos ---------- */
  const toasts = $('[data-pt-toasts]');
  function toast(text, { rule = null, kind = 'rule', who = null } = {}) {
    const el = document.createElement('div');
    el.className = `pt-toast is-${kind}`;
    if (who !== null) el.style.borderLeftColor = SEAT_COLORS[who % 4];
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

  const turnTitle = () => (T.turn === ME ? `Seu turno · ${PHASES[S.phase]}` : `Vez de ${seatName(T.turn)} · ${PHASES[T.phase] || ''}`);
  function renderHud() {
    const playing = S.stage === 'play';
    const shownPhase = MP && !myTurn() ? T.phase : S.phase;
    if (MP) {
      $('[data-pt-turn]').textContent = T.status !== 'playing' ? 'Mesa aberta' : !playing ? 'Mão inicial' : T.turn === ME ? `Seu turno · ${S.turn}º` : `Vez de ${seatName(T.turn)}`;
      $('[data-pt-turn]').title = T.status === 'playing' ? `Turno ${T.number} da mesa` : '';
      $('[data-pt-action="turn"]').textContent = 'Passar o turno';
      $('[data-pt-action="turn"]').disabled = !playing || !myTurn();
      $('[data-pt-action="phase"]').disabled = !playing || !myTurn();
    } else {
      $('[data-pt-turn]').textContent = playing ? `Turno ${S.turn}` : 'Mão inicial';
    }
    $('[data-pt-phases]').innerHTML = PHASES.map((phase, i) => `<li${i === shownPhase && playing && (!MP || T.status === 'playing') ? ' aria-current="step"' : ''}${i < shownPhase ? ' class="is-done"' : ''}>${esc(phase)}</li>`).join('');
    ['life', 'poison', 'opponent'].forEach((key) => { const out = $(`[data-pt-value="${key}"]`); if (out) out.textContent = S[key]; });
    $('[data-pt-meter="life"]').classList.toggle('is-danger', S.life <= 10);
    $('[data-pt-meter="poison"]').classList.toggle('is-danger', S.poison >= 7);
    const opponentMeter = $('[data-pt-meter="opponent"]');
    if (opponentMeter) {
      opponentMeter.classList.toggle('is-win', S.opponent <= 0);
      opponentMeter.querySelector('span').innerHTML = S.killTurn !== null ? `Oponente <em>· caiu no T${S.killTurn}</em>` : 'Oponente';
    }
    const badge = $('[data-pt-lands]');
    badge.textContent = `Terreno ${S.lands}/1`;
    badge.classList.toggle('is-done', S.lands === 1);
    badge.classList.toggle('is-warn', S.lands > 1);
    badge.hidden = !playing || !myTurn();
    // Mulligan.
    if (playing || (MP && T.status !== 'playing')) mulliganBar.hidden = true;
    else {
      mulliganBar.hidden = false;
      const toBottom = toBottomCount();
      if (S.stage === 'bottom') {
        mulliganBar.innerHTML = `<strong>Escolha ${toBottom} carta${toBottom > 1 ? 's' : ''} para o fundo do grimório</strong><span>Clique nelas na mão (${S.bottom.length}/${toBottom}). <small class="pt-rule">103.5</small></span><button type="button" class="pt-btn is-strong" data-pt-action="confirm-bottom" ${S.bottom.length === toBottom ? '' : 'disabled'}>Confirmar</button>`;
      } else {
        const free = freeMulligan();
        const hint = S.mulligans === 0 ? (free ? `Aqui o primeiro mulligan é grátis${MP ? ' (partida com 3 ou mais, ou Brawl)' : ''}.` : 'A cada mulligan, uma carta a mais vai para o fundo ao manter.') : `Ao manter, ${toBottom} carta${toBottom === 1 ? '' : 's'} vai para o fundo.`;
        const who = MP && T.starting !== null ? ` <span>${T.starting === ME ? 'Você começa.' : `${esc(seatName(T.starting))} começa.`}</span>` : '';
        mulliganBar.innerHTML = `<strong>Mão inicial${S.mulligans ? ` · ${S.mulligans} mulligan${S.mulligans > 1 ? 's' : ''}` : ''}</strong><span>${hint} <small class="pt-rule">103.5</small></span>${who}<button type="button" class="pt-btn is-strong" data-pt-action="keep">Manter</button><button type="button" class="pt-btn" data-pt-action="mulligan">Mulligan</button>`;
      }
    }
    // Combate.
    const list = attackers();
    combatBar.hidden = !(playing && S.phase === 4 && myTurn());
    if (!combatBar.hidden) {
      const total = list.reduce((sum, inst) => sum + Math.max(0, ptOf(inst)?.p ?? 0), 0);
      if (MP) {
        const alive = livingOpponents();
        const target = attackTargetFor();
        const picker = alive.length > 1 ? `<label class="pt-combat-target">Atacar <select data-attack-target>${alive.map((seat) => `<option value="${seat}"${seat === target ? ' selected' : ''}>${esc(seatName(seat))}</option>`).join('')}</select></label>` : `<span>Contra ${esc(seatName(target))}</span>`;
        combatBar.innerHTML = `<strong>Combate</strong>${picker}<span>${list.length ? `${list.length} atacante${list.length > 1 ? 's' : ''} · <b>${total}</b> de dano` : 'Clique nas criaturas para declarar ataque.'}</span><button type="button" class="pt-btn is-strong" data-pt-action="damage" ${list.length ? '' : 'disabled'}>Causar dano…</button>`;
      } else {
        combatBar.innerHTML = `<strong>Combate</strong><span>${list.length ? `${list.length} atacante${list.length > 1 ? 's' : ''} · <b>${total}</b> de dano` : 'Clique nas criaturas para declarar ataque.'}</span><button type="button" class="pt-btn is-strong" data-pt-action="damage" ${list.length ? '' : 'disabled'}>Causar ${total} ao oponente</button>`;
      }
    }
  }
  combatBar.addEventListener('change', (event) => { if (event.target.matches('[data-attack-target]')) defaultTarget = Number(event.target.value); });

  let unread = 0;
  const logOpen = () => !$('[data-pt-log]').hidden;
  function renderLogToggle() {
    const toggle = $('[data-pt-action="log"]');
    if (!MP || !toggle) return;
    toggle.innerHTML = `Mesa e mensagens${unread ? ` <b class="pt-unread">${unread}</b>` : ''}`;
  }
  function renderLog() {
    const list = $('[data-pt-log]');
    if (MP) {
      list.innerHTML = feed.slice(-120).reverse().map((entry) => `<li class="is-${entry.kind}"><small>${esc(entry.at || '')}</small>${entry.seat !== null && entry.seat !== undefined ? `<b style="color:${SEAT_COLORS[entry.seat % 4]}">${esc(seatName(entry.seat))}</b> ` : ''}${esc(entry.text)}</li>`).join('') || '<li>Sem mensagens ainda.</li>';
      return;
    }
    list.innerHTML = S.log.slice(0, 80).map((entry) => `<li><small>T${entry.turn}</small>${esc(entry.text)}</li>`).join('');
  }

  function sceneView() {
    return {
      active: MP ? T.status === 'playing' && T.turn === ME : false,
      battlefield: S.zones.battlefield.map((iid) => {
        const inst = S.cards[iid]; const face = faceOf(inst); const pt = ptOf(inst);
        return {
          iid, x: inst.x, z: inst.z, tapped: inst.tapped, attacking: inst.attacking, token: inst.token, commander: inst.commander,
          image: imageOf(inst), fallback: { name: face.name, type: typeOf(inst), pt: pt?.text }, fallbackKey: `${face.name}|${pt?.text || ''}`,
          counters: Object.entries(inst.counters).filter(([, n]) => n > 0).map(([name, n]) => ({ name, n, short: name.length > 8 ? name.slice(0, 7) + '.' : name })),
          pt, sick: isSick(inst), stack: inst.stack || 1, buried: !!inst.buried,
          targetLabel: MP && inst.attacking && inst.attackTarget !== null && livingOpponents().length > 1 ? seatName(inst.attackTarget) : null,
        };
      }),
      piles: {
        library: { n: S.zones.library.length },
        graveyard: { n: S.zones.graveyard.length, top: S.zones.graveyard.length ? imageOf(S.cards[S.zones.graveyard[S.zones.graveyard.length - 1]]) : null },
        exile: { n: S.zones.exile.length, top: S.zones.exile.length ? imageOf(S.cards[S.zones.exile[S.zones.exile.length - 1]]) : null },
        command: S.zones.command.map((iid) => ({ image: faceOf(S.cards[iid]).image, tax: (S.cards[iid].casts || 0) * 2 })),
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
    syncReveal();
    if (MP) { renderSeats(); publish(); }
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
      if (saved && saved.v === 2 && saved.sig === signature) return saved;
    } catch { /* partida salva ilegível: começa outra */ }
    return null;
  }

  /* ---------- Botões e teclado ---------- */
  root.addEventListener('click', (event) => {
    const button = event.target.closest('[data-pt-action]');
    if (button) {
      const action = button.dataset.ptAction;
      const needPlay = ['draw', 'untap', 'turn', 'phase', 'mill', 'token', 'scry', 'search', 'shuffle'].includes(action);
      if (needPlay && S.stage !== 'play') { toast(MP && T.status !== 'playing' ? 'A partida ainda não começou.' : 'Primeiro, decida a mão inicial: manter ou mulligan.', { kind: 'info' }); return; }
      ({
        draw: () => act('draw', () => drawCards(1)),
        untap: () => act('untap', () => untapAll()),
        turn: nextTurn,
        phase: nextPhase,
        shuffle: shuffleLibrary,
        scry: openScry,
        search: () => openZone('library'),
        mill: () => mill(1),
        token: openTokens,
        dice: openDice,
        undo,
        restart: openRestart,
        share: openShare,
        table: openTablePanel,
        fullscreen: toggleFullscreen,
        keep: () => { if (toBottomCount() > 0) { act('bottom', () => { S.stage = 'bottom'; }); } else { act('keep', keepHand); maybeBeginTurn(); } },
        'confirm-bottom': () => { act('keep', keepHand); maybeBeginTurn(); },
        mulligan: () => act('mulligan', mulligan),
        damage: applyDamage,
        log: () => { const list = $('[data-pt-log]'); list.hidden = !list.hidden; button.setAttribute('aria-expanded', String(!list.hidden)); if (!list.hidden) { unread = 0; renderLogToggle(); } },
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
    if (MP && key === 'v') { event.preventDefault(); cycleView(); return; }
    if (hovered && S.cards[hovered] && S.cards[hovered].zone === 'battlefield') {
      const iid = hovered;
      const cardKeys = { t: () => toggleTap(iid), a: () => (isCreature(S.cards[iid]) ? toggleAttack(iid) : null), g: () => act('move', () => move(iid, 'graveyard')), e: () => act('move', () => move(iid, 'exile')), h: () => act('move', () => move(iid, 'hand')), c: () => openCounters(iid), '+': () => act('counter', () => addCounter(iid, '+1/+1', 1)), '=': () => act('counter', () => addCounter(iid, '+1/+1', 1)), '-': () => act('counter', () => addCounter(iid, (S.cards[iid].counters['+1/+1'] || 0) ? '+1/+1' : '-1/-1', (S.cards[iid].counters['+1/+1'] || 0) ? -1 : 1)) };
      if (cardKeys[key]) { event.preventDefault(); cardKeys[key](); return; }
    }
    if (S.stage !== 'play') return;
    const keys = { d: () => act('draw', () => drawCards(1)), u: () => act('untap', () => untapAll()), n: nextTurn, ' ': nextPhase, s: shuffleLibrary, x: openScry, f: () => openZone('library'), m: () => mill(1), k: openTokens, r: revealTopOnce };
    if (key === ' ' && target.closest('button')) return;
    if (keys[key]) { event.preventDefault(); keys[key](); }
  });

  root.addEventListener('contextmenu', (event) => {
    if (!(event.target instanceof Element) || !event.target.closest('input, textarea, select')) event.preventDefault();
  });

  /* ---------- Jogar com amigos (mesa solo) ---------- */
  function openShare() {
    closeMenu();
    openModal(`<h2>Jogar com amigos</h2>
      <p>Abra uma mesa compartilhada com este deck e mande o link. Cada pessoa entra com a própria conta e escolhe um deck de <strong>${esc(FORMAT.name)}</strong> dela; até 4 jogadores. Cada um controla o próprio deck e todos veem o campo, os cemitérios, a vida e o dano de comandante de todos.</p>
      <div class="pt-modal-actions"><button type="button" class="pt-btn is-strong" data-create-table>Abrir mesa</button></div><p class="pt-modal-note" data-share-error hidden></p>`, async (event) => {
      if (!event.target.closest('[data-create-table]')) return;
      event.target.disabled = true;
      const body = new FormData(); body.set('csrf', data.csrf); body.set('action', 'create'); body.set('deck', String(data.deck.id));
      const response = await fetch('/playtest_table.php', { method: 'POST', body, credentials: 'same-origin' }).catch(() => null);
      const result = response ? await response.json().catch(() => null) : null;
      if (result?.ok) { location.href = result.url; return; }
      const error = modalCard.querySelector('[data-share-error]'); error.hidden = false; error.textContent = result?.message || 'Não foi possível abrir a mesa agora.';
      event.target.disabled = false;
    });
  }

  /* ========== Mesa compartilhada ========== */
  let net = null;
  const lobby = $('[data-pt-lobby]');
  const opponentsEl = $('[data-pt-opponents]');
  let lastTurnKey = '';
  let resumeState = null;

  /** Cartas no formato curto que vai para a mesa (só o que o preview e a mesa 3D usam). */
  const defOf = (card) => ({ n: card.name, t: card.type_line || '', i: card.image || null, h: card.thumb || null, p: card.power ?? null, o: card.toughness ?? null, l: card.loyalty ?? null, c: card.colors || ['C'],
    x: String(card.text || '').slice(0, 700), b: card.back ? { n: card.back.name, t: card.back.type_line, i: card.back.image, x: String(card.back.text || '').slice(0, 500) } : null });
  function publicView() {
    const defs = {};
    const keyOf = (inst) => { if (inst.faceDown) return null; defs[inst.ref] ??= defOf(cardOf(inst)); return inst.ref; };
    const view = {
      life: S.life, poison: S.poison, cmd: S.cmd, stage: S.stage, mulligans: S.mulligans, turns: S.turn, lands: S.lands,
      hand: S.zones.hand.length, library: S.zones.library.length, conceded: S.conceded,
      battlefield: S.zones.battlefield.map((iid) => {
        const inst = S.cards[iid]; const pt = ptOf(inst);
        return { i: iid, k: keyOf(inst), x: +inst.x.toFixed(2), z: +inst.z.toFixed(2), t: inst.tapped ? 1 : 0, a: inst.attacking ? 1 : 0, at: inst.attacking ? inst.attackTarget : null,
          fd: inst.faceDown ? 1 : 0, fl: inst.flipped ? 1 : 0, c: inst.counters, tk: inst.token ? 1 : 0, cm: inst.commander ? 1 : 0, st: inst.stack || 1, b: inst.buried ? 1 : 0,
          pt: pt ? pt.text : null, ps: pt ? pt.state : null, s: isSick(inst) ? 1 : 0 };
      }),
      graveyard: S.zones.graveyard.map((iid) => keyOf(S.cards[iid])),
      exile: S.zones.exile.map((iid) => keyOf(S.cards[iid])),
      command: S.zones.command.map((iid) => ({ k: keyOf(S.cards[iid]), tax: (S.cards[iid].casts || 0) * 2 })),
      reveal: topRevealed() ? keyOf(S.cards[S.zones.library[0]]) : null,
    };
    view.defs = defs;
    return view;
  }
  function publish() {
    if (!MP || !net || T.status !== 'playing' || S.game !== T.game) return;
    flushOutbox();
    net.pushState(T.game, publicView(), S);
  }
  let outboxTimer = 0;
  function flushOutbox() {
    clearTimeout(outboxTimer);
    if (!outbox.length || !net || T.status !== 'playing') { outbox = []; return; }
    const lines = outbox.splice(0, 12); outbox = [];
    net.event('log', { lines }).catch(() => {});
  }

  // As cartas dos outros jogadores chegam do navegador deles: imagem só do próprio site (nada de endereço externo).
  const localImage = (url) => (typeof url === 'string' && /^\/image\.php\?[\w=&%.-]+$/.test(url) ? url : null);
  const cleanDef = (def) => (def && typeof def === 'object' ? { ...def, i: localImage(def.i), h: localImage(def.h), b: def.b && typeof def.b === 'object' ? { ...def.b, i: localImage(def.b.i) } : null } : null);
  const defFor = (seat, key) => (key ? cleanDef(seatDefs.get(seat)?.[key]) : null);
  function seatFace(seat, entry) {
    const def = defFor(seat, entry.k);
    if (entry.fd || !def) return { name: entry.fd ? 'Carta virada para baixo' : 'Carta', type_line: entry.fd ? 'Criatura 2/2' : '', image: 'back', text: '' };
    const face = entry.fl && def.b ? { ...def, ...def.b } : def;
    return { name: face.n, type_line: face.t, image: face.i, text: face.x, colors: def.c, loyalty: def.l };
  }
  function previewSeat(seat, target) {
    const state = seatState.get(seat);
    if (!state) { hidePreview(); return; }
    const owner = `${seatInfo.get(seat)?.player || 'Oponente'} · ${seatInfo.get(seat)?.deck || ''}`;
    if (target.iid) {
      const entry = state.battlefield.find((row) => row.i === target.iid);
      if (!entry) { hidePreview(); return; }
      const face = seatFace(seat, entry);
      const counters = Object.entries(entry.c || {}).filter(([, n]) => n > 0).map(([name, n]) => `${n}× ${name}`);
      const flags = [entry.tk ? 'Ficha' : '', entry.cm ? 'Zona de comando' : '', entry.t ? 'Virada' : '', entry.a ? `Atacando${entry.at !== null && entry.at !== undefined ? ` ${seatName(entry.at)}` : ''}` : '', entry.s ? 'Enjoo de invocação' : ''].filter(Boolean);
      renderPreview({ face, pt: entry.pt ? { text: entry.pt, state: entry.ps } : null, state: flags, counters, owner });
      return;
    }
    const zoneKey = target.zone === 'command' ? state.command[target.slot || 0]?.k : target.zone === 'library' ? state.reveal : (state[target.zone] || []).slice(-1)[0];
    const def = defFor(seat, zoneKey);
    if (!def) { hidePreview(); return; }
    renderPreview({ face: { name: def.n, type_line: def.t, image: def.i, text: def.x }, pt: null, state: [target.zone === 'library' ? 'Topo do grimório (revelado)' : zoneName(target.zone)], counters: [], owner });
  }

  /** Oponentes para a mesa 3D, o painel e os feixes de ataque. */
  function renderSeats() {
    renderOpponents();
    const list = opponents();
    const views = list.map((seat, index) => {
      const info = seatInfo.get(seat) || {};
      const state = seatState.get(seat);
      const bf = (state?.battlefield || []).map((entry) => {
        const face = seatFace(seat, entry);
        return { iid: entry.i, x: entry.x, z: entry.z, tapped: !!entry.t, attacking: !!entry.a, token: !!entry.tk, commander: !!entry.cm,
          image: entry.fd ? 'back' : face.image, fallback: { name: face.name, type: face.type_line, pt: entry.pt }, fallbackKey: `${face.name}|${entry.pt || ''}`,
          counters: Object.entries(entry.c || {}).filter(([, n]) => n > 0).map(([name, n]) => ({ name, n, short: name.length > 8 ? name.slice(0, 7) + '.' : name })),
          pt: entry.pt ? { text: entry.pt, state: entry.ps || 'normal' } : null, sick: !!entry.s, stack: entry.st || 1, buried: !!entry.b, colors: face.colors,
          targetLabel: entry.a && entry.at !== null && entry.at !== undefined ? seatName(entry.at) : null };
      });
      const topOf = (zone) => { const k = (state?.[zone] || []).slice(-1)[0]; const def = defFor(seat, k); return def?.i || (state?.[zone]?.length ? 'back' : null); };
      return {
        seat, index, count: list.length, name: info.player || `Jogador ${seat + 1}`, life: state ? state.life : '—', poison: state?.poison || 0,
        active: T.status === 'playing' && T.turn === seat, eliminated: !!info.eliminated, online: info.online, stage: state?.stage || (T.status === 'playing' ? 'mulligan' : 'play'),
        playmat: info.commander?.image || null, hand: state?.hand || 0, battlefield: bf,
        piles: { library: { n: state?.library || 0 }, graveyard: { n: state?.graveyard?.length || 0, top: topOf('graveyard') }, exile: { n: state?.exile?.length || 0, top: topOf('exile') },
          command: (state?.command || []).map((row) => ({ image: defFor(seat, row.k)?.i || null, tax: row.tax || 0 })) },
      };
    });
    scene.syncSeats(views);
    // Feixes: os seus atacantes e os dos outros, cada um na cor de quem ataca.
    const beams = [];
    S.zones.battlefield.forEach((iid) => { const inst = S.cards[iid]; if (inst.attacking && inst.attackTarget !== null && inst.attackTarget !== undefined) beams.push({ from: { seat: null, iid }, to: inst.attackTarget, color: SEAT_COLORS[ME % 4] }); });
    list.forEach((seat) => (seatState.get(seat)?.battlefield || []).forEach((entry) => { if (entry.a && entry.at !== null && entry.at !== undefined) beams.push({ from: { seat, iid: entry.i }, to: entry.at === ME ? null : entry.at, color: SEAT_COLORS[seat % 4] }); }));
    scene.setBeams(beams);
  }

  function renderOpponents() {
    if (!opponentsEl) return;
    const view = scene.viewMode();
    const rows = opponents().map((seat) => {
      const info = seatInfo.get(seat) || {};
      const state = seatState.get(seat);
      const cmdToMe = S.cmd[seat] || 0;
      const flags = [info.eliminated ? 'fora da partida' : '', info.online === false ? 'desconectado' : '', state && state.stage !== 'play' && T.status === 'playing' ? 'escolhendo a mão' : ''].filter(Boolean);
      return `<div class="pt-opp${T.turn === seat && T.status === 'playing' ? ' is-active' : ''}${info.eliminated ? ' is-out' : ''}" style="--seat:${SEAT_COLORS[seat % 4]}" data-opp="${seat}">
        <button type="button" class="pt-opp-main" data-opp-view="${seat}" title="Ver o tapete de ${esc(info.player || '')}" aria-pressed="${view === seat}">
          ${info.commander?.image ? `<img src="${esc(info.commander.image.replace('size=normal', 'size=small'))}" alt="" width="36" height="50">` : '<span class="pt-opp-dot"></span>'}
          <span class="pt-opp-name"><strong>${esc(info.player || `Jogador ${seat + 1}`)}</strong><small>${esc(flags.join(' · ') || `${state?.hand ?? 0} na mão · ${state?.library ?? 0} no grimório`)}</small></span>
          <span class="pt-opp-life"><b>${esc(state ? state.life : '—')}</b>${state?.poison ? `<i title="Veneno">☠ ${state.poison}</i>` : ''}</span>
        </button>
        ${FORMAT.commander_damage ? `<span class="pt-opp-cmd${cmdToMe >= FORMAT.commander_damage ? ' is-lethal' : ''}" title="Dano de combate da comandante de ${esc(info.player || '')} em você">${cmdToMe}/${FORMAT.commander_damage}</span>` : ''}
        <button type="button" class="pt-opp-more" data-opp-menu="${seat}" aria-label="Ações com ${esc(info.player || '')}">⋯</button>
      </div>`;
    }).join('');
    opponentsEl.innerHTML = rows ? `<div class="pt-opp-views" role="group" aria-label="Câmera"><button type="button" data-set-view="table" aria-pressed="${view === 'table'}">Mesa toda</button><button type="button" data-set-view="me" aria-pressed="${view === 'me'}">Meu tapete</button></div>${rows}` : '';
  }
  opponentsEl?.addEventListener('click', (event) => {
    const viewButton = event.target.closest('[data-set-view]');
    if (viewButton) { setView(viewButton.dataset.setView); return; }
    const seatButton = event.target.closest('[data-opp-view]');
    if (seatButton) { const seat = Number(seatButton.dataset.oppView); setView(scene.viewMode() === seat ? 'table' : seat); return; }
    const more = event.target.closest('[data-opp-menu]');
    if (more) { const r = more.getBoundingClientRect(); openSeatMenu(Number(more.dataset.oppMenu), r.left, r.bottom); }
  });
  function setView(mode) { scene.view(mode); renderOpponents(); }
  function cycleView() {
    const order = ['table', 'me', ...opponents()];
    const at = order.indexOf(scene.viewMode());
    setView(order[(at + 1) % order.length]);
  }
  function seatClick(seat, target) {
    if (target.zone === 'graveyard' || target.zone === 'exile') { openSeatZone(seat, target.zone); return; }
    if (target.mat) setView(scene.viewMode() === seat ? 'table' : seat);
  }

  function openSeatMenu(seat, x, y) {
    const info = seatInfo.get(seat) || {};
    const alive = !info.eliminated && T.status === 'playing';
    openMenu([
      { title: `${info.player || 'Oponente'} · ${info.deck || ''}` },
      { label: scene.viewMode() === seat ? 'Voltar para a mesa toda' : 'Ver o tapete', key: 'V', action: () => setView(scene.viewMode() === seat ? 'table' : seat) },
      '-',
      { label: 'Causar dano…', disabled: !alive, action: () => openLifeChange(seat, 'damage') },
      { label: 'Mudar a vida…', disabled: !alive, action: () => openLifeChange(seat, 'life') },
      { label: 'Dar 1 veneno', disabled: !alive, action: () => sendToSeat(seat, 'poison', { delta: 1 }, `deu 1 veneno para ${seatName(seat)}`) },
      '-',
      { label: 'Ver o cemitério…', action: () => openSeatZone(seat, 'graveyard') },
      { label: 'Ver o exílio…', action: () => openSeatZone(seat, 'exile') },
    ], x, y);
  }
  function openSeatZone(seat, zone) {
    closeMenu();
    const state = seatState.get(seat);
    const keys = (state?.[zone] || []).slice().reverse();
    const rows = keys.map((k) => { const def = defFor(seat, k); return def ? `<li><img src="${esc(def.h || def.i || '')}" alt="" loading="lazy" width="73" height="102"><div><strong>${esc(def.n)}</strong><small>${esc(def.t)}</small></div></li>` : '<li><div><strong>Carta virada para baixo</strong></div></li>'; }).join('');
    openModal(`<h2>${zone === 'graveyard' ? 'Cemitério' : 'Exílio'} de ${esc(seatName(seat))} <span class="pt-modal-count">${keys.length}</span></h2><ul class="pt-zone-list">${rows || '<li class="pt-empty">Nenhuma carta.</li>'}</ul>`);
  }
  function sendToSeat(seat, kind, payload) {
    net.event(kind, { to: seat, ...payload }).catch((error) => toast(error.message, { kind: 'warn' }));
  }
  function openLifeChange(seat, mode) {
    closeMenu();
    const myCommanders = S.zones.battlefield.filter((iid) => S.cards[iid].commander && !S.cards[iid].signature && isCreature(S.cards[iid]));
    openModal(`<h2>${mode === 'damage' ? 'Causar dano a' : 'Mudar a vida de'} ${esc(seatName(seat))}</h2>
      <form class="pt-life-form" data-life-form>
        <label class="pt-field is-short">${mode === 'damage' ? 'Dano' : 'Vida (+ ou −)'}<input name="amount" type="number" value="${mode === 'damage' ? 1 : -1}" ${mode === 'damage' ? 'min="1"' : ''} max="999" required></label>
        ${mode === 'damage' && FORMAT.commander_damage && myCommanders.length ? `<label class="pt-check"><input type="checkbox" name="commander"> É dano de combate da comandante <small class="pt-rule">704.6c</small></label>` : ''}
        <button class="pt-btn is-strong">${mode === 'damage' ? 'Causar' : 'Aplicar'}</button>
      </form>`);
    modalCard.querySelector('[data-life-form]').addEventListener('submit', (event) => {
      event.preventDefault();
      const amount = Number(event.target.elements.amount.value) || 0;
      if (!amount) return;
      closeModal();
      if (mode === 'damage') sendToSeat(seat, 'damage', { amount: Math.abs(amount), commander: event.target.elements.commander?.checked ? Math.abs(amount) : 0, combat: false }, `causou ${Math.abs(amount)} de dano em ${seatName(seat)}`);
      else sendToSeat(seat, 'life', { delta: amount }, `${amount > 0 ? 'deu' : 'tirou'} ${Math.abs(amount)} de vida ${amount > 0 ? 'para' : 'de'} ${seatName(seat)}`);
      pendingFx.push(() => scene.fx.hit(seat));
      while (pendingFx.length) pendingFx.shift()();
    });
  }

  /** Dano de combate: por defensor, com os atacantes que passaram (os bloqueados ficam de fora). */
  function openDamage() {
    const list = attackers();
    if (!list.length) return;
    const byTarget = new Map();
    list.forEach((inst) => { const to = inst.attackTarget ?? attackTargetFor(); if (!byTarget.has(to)) byTarget.set(to, []); byTarget.get(to).push(inst); });
    const blocks = [...byTarget.entries()].map(([seat, group]) => `<fieldset class="pt-damage-block" data-damage-seat="${seat}"><legend>${esc(seatName(seat))}</legend>
      ${group.map((inst) => `<label class="pt-damage-row"><input type="checkbox" data-unblocked="${inst.iid}" checked><span>${esc(nameOf(inst.iid))}${inst.commander ? ' <em>comandante</em>' : ''}</span><input type="number" min="0" max="999" value="${Math.max(0, ptOf(inst)?.p ?? 0)}" data-power="${inst.iid}" aria-label="Dano de ${esc(nameOf(inst.iid))}"></label>`).join('')}
      </fieldset>`).join('');
    openModal(`<h2>Dano de combate</h2><p class="pt-modal-note">Desmarque as criaturas bloqueadas e ajuste o dano (atropelar, bloqueio parcial, efeitos). O dano chega na vida de cada defensor${FORMAT.commander_damage ? '; o da comandante também conta como dano de comandante' : ''}.</p>${blocks}
      <div class="pt-modal-actions"><button type="button" class="pt-btn is-strong" data-damage-apply>Causar o dano</button></div>`, (event) => {
      if (!event.target.closest('[data-damage-apply]')) return;
      const sent = [];
      modalCard.querySelectorAll('[data-damage-seat]').forEach((block) => {
        const seat = Number(block.dataset.damageSeat);
        let amount = 0; let commander = 0; const names = [];
        block.querySelectorAll('[data-unblocked]').forEach((check) => {
          if (!check.checked) return;
          const iid = check.dataset.unblocked;
          const value = Math.max(0, Number(block.querySelector(`[data-power="${iid}"]`).value) || 0);
          if (!value) return;
          amount += value; names.push(nameOf(iid));
          if (S.cards[iid]?.commander) commander += value;
        });
        if (amount > 0) { net.event('damage', { to: seat, amount, commander, combat: true, sources: names }).catch((error) => toast(error.message, { kind: 'warn' })); sent.push(`${amount} em ${seatName(seat)}`); scene.fx.hit(seat, amount >= 7); }
      });
      closeModal();
      act('damage', () => { endCombat(); if (sent.length) log(`Dano de combate: ${sent.join(', ')}.`, null); });
    });
  }

  /** Mostra uma carta para a mesa inteira (da mão ou do topo do grimório). */
  function sendReveal(inst, from) {
    net.event('reveal', { card: defOf(faceOf(inst).image === 'back' ? cardOf(inst) : { ...cardOf(inst), ...(inst.flipped && cardOf(inst).back ? cardOf(inst).back : {}) }), from }).catch(() => {});
  }
  function revealToTable(inst, from) {
    sendReveal(inst, from);
    toast(`Você mostrou ${faceOf(inst).name} para a mesa.`, { kind: 'info' });
  }
  function showReveal(seat, payload) {
    const card = payload.card || {};
    const el = document.createElement('div');
    el.className = 'pt-reveal';
    el.style.setProperty('--seat', SEAT_COLORS[seat % 4]);
    el.innerHTML = `<p><b>${esc(seatName(seat))}</b> mostrou ${esc(payload.from || '')}</p>${card.i ? `<img src="${esc(card.i)}" alt="${esc(card.n || '')}" width="488" height="680">` : `<strong>${esc(card.n || '')}</strong>`}`;
    el.addEventListener('click', () => el.remove());
    root.appendChild(el);
    setTimeout(() => el.classList.add('is-out'), 4200);
    setTimeout(() => el.remove(), 4700);
  }

  /** Começa o seu turno quando a mesa passa a vez para você: desvira, manutenção e compra. */
  function maybeBeginTurn() {
    if (!MP || T.status !== 'playing' || S.stage !== 'play' || T.turn !== ME || S.turnSeen === T.number || S.game !== T.game) return;
    act('turn', () => {
      S.turnSeen = T.number;
      S.turn += 1;
      S.lands = 0;
      endCombat();
      log(`— Seu ${S.turn}º turno (turno ${T.number} da mesa) —`, `— Começou o ${S.turn}º turno —`);
      [0, 1, 2, 3].forEach(enterPhase);
    });
    scene.fx.turn(null);
    net.post('phase', { phase: String(S.phase) }).catch(() => {});
  }
  function passTurn() {
    if (!myTurn()) { toast(T.status === 'playing' ? `É a vez de ${seatName(T.turn)}.` : 'A partida ainda não começou.', { kind: 'info' }); return; }
    if (S.stage !== 'play') { toast('Primeiro, decida a mão inicial.', { kind: 'info' }); return; }
    act('turn', () => {
      endCombat();
      S.phase = 6;
      if (S.zones.hand.length > 7) toast(`Você terminou o turno com ${S.zones.hand.length} cartas: o certo seria descartar até 7.`, { rule: '514.1', kind: 'warn' });
      log('Passou o turno.', null);
    });
    net.post('turn', { number: String(T.number) }).catch((error) => toast(error.message, { kind: 'warn' }));
  }

  /** Aplica um evento que chegou da mesa (e registra no feed). */
  function handleEvent(event, fresh) {
    const p = event.payload || {};
    const mine = event.seat === ME;
    const at = event.at;
    const push = (text, kind = event.kind, seat = event.seat) => feed.push({ seat, text, at, kind });
    switch (event.kind) {
      case 'join': push(`sentou com ${p.deck || 'um deck'}.`); if (fresh && !mine) toast(`${seatName(event.seat)} entrou na mesa.`, { kind: 'info', who: event.seat }); break;
      case 'leave': push(p.kicked ? 'saiu da mesa (retirado por quem abriu).' : 'saiu da mesa.'); break;
      case 'start': push(`começou a partida ${p.game}. ${seatName(p.starting)} joga primeiro.`, 'start'); break;
      case 'lobby': push('encerrou a partida e voltou ao lobby.', 'start'); break;
      case 'turn': push(`passou a vez para ${seatName(p.seat)}.`); if (fresh && p.seat !== ME) scene.fx.turn(p.seat); break;
      case 'concede': push(p.left ? 'saiu da partida.' : 'concedeu a partida.'); if (fresh && !mine) toast(`${seatName(event.seat)} ${p.left ? 'saiu' : 'concedeu'}.`, { kind: 'info', who: event.seat }); break;
      case 'log': (p.lines || []).forEach((line) => push(line)); break;
      case 'chat': push(p.text, 'chat'); if (fresh && !mine) toast(`${seatName(event.seat)}: ${p.text}`, { kind: 'info', who: event.seat }); break;
      case 'dice': push(p.text); if (fresh && !mine) toast(`${seatName(event.seat)} rolou — ${p.text}`, { kind: 'info', who: event.seat }); break;
      case 'reveal': push(`mostrou ${p.card?.n || 'uma carta'} ${p.from || ''}.`); if (fresh && !mine) showReveal(event.seat, { ...p, card: cleanDef(p.card) }); break;
      case 'damage': case 'life': case 'poison': {
        const target = p.to;
        const verb = event.kind === 'damage' ? `causou ${p.amount} de dano${p.combat ? ' de combate' : ''}${p.commander ? ` (${p.commander} da comandante)` : ''} em` : event.kind === 'life' ? `${p.delta > 0 ? 'deu' : 'tirou'} ${Math.abs(p.delta)} de vida ${p.delta > 0 ? 'para' : 'de'}` : `deu ${p.delta} veneno para`;
        push(`${verb} ${seatName(target)}${p.sources?.length ? ` (${p.sources.join(', ')})` : ''}.`);
        if (fresh && !mine && target !== ME) scene.fx.hit(target, (p.amount || 0) >= 7);
        if (target === ME && event.id > S.appliedEvent && S.game === T.game) applyIncoming(event);
        break;
      }
      default: break;
    }
    if (event.id > S.appliedEvent && S.game === T.game && event.kind !== 'damage' && event.kind !== 'life' && event.kind !== 'poison') S.appliedEvent = event.id;
  }
  /** Dano, vida e veneno que outro jogador aplicou em você entram na sua partida (e podem ser desfeitos). */
  function applyIncoming(event) {
    const p = event.payload;
    const from = seatName(event.seat);
    act('incoming', () => {
      S.appliedEvent = Math.max(S.appliedEvent, event.id);
      if (event.kind === 'damage') {
        S.life -= p.amount;
        if (p.commander) S.cmd[event.seat] = (S.cmd[event.seat] || 0) + p.commander;
        log(`Recebeu ${p.amount} de dano de ${from}${p.commander ? ` (${p.commander} da comandante; total ${S.cmd[event.seat]})` : ''}.`, null);
      } else if (event.kind === 'life') {
        S.life += p.delta;
        log(`${from} ${p.delta > 0 ? 'deu' : 'tirou'} ${Math.abs(p.delta)} de vida (${S.life}).`, null);
      } else {
        S.poison = Math.max(0, S.poison + p.delta);
        log(`${from} deu ${p.delta} veneno (${S.poison}).`, null);
      }
    });
    toast(event.kind === 'damage' ? `${from} causou ${p.amount} de dano em você${p.commander ? ` (${p.commander} de comandante)` : ''}.` : event.kind === 'life' ? `${from} mudou sua vida em ${p.delta > 0 ? '+' : ''}${p.delta}.` : `${from} deu ${p.delta} veneno para você.`, { kind: 'warn', who: event.seat });
    floatText(event.kind === 'poison' ? `☠ +${p.delta}` : `${event.kind === 'life' && p.delta > 0 ? '+' : '−'}${event.kind === 'damage' ? p.amount : Math.abs(p.delta)}`, $(`[data-pt-meter="${event.kind === 'poison' ? 'poison' : 'life'}"]`));
    if (event.kind !== 'life' || p.delta < 0) scene.fx.hit(null, (p.amount || 0) >= 7);
  }

  /** Chegou uma atualização da mesa: assentos, vez, estados públicos e eventos. */
  function onUpdate(update, first) {
    const table = update.table;
    const before = { status: T.status, game: T.game, turn: T.turn, number: T.number };
    Object.assign(T, { status: table.status, game: table.game, starting: table.starting, turn: table.turn, number: table.number, phase: table.phase, host: table.host, version: table.version });
    seatInfo.clear();
    update.seats.forEach((seat) => {
      seatInfo.set(seat.seat, { player: seat.player, deck: seat.deck, commander: seat.commander, online: seat.online, eliminated: seat.eliminated });
      if (seat.state) {
        if (seat.seat === ME) { if (seat.state.private) resumeState = seat.state.private; }
        else if (seat.state.public) { seatState.set(seat.seat, seat.state.public); seatDefs.set(seat.seat, { ...(seatDefs.get(seat.seat) || {}), ...(seat.state.public.defs || {}) }); }
        else seatState.delete(seat.seat);
      }
    });
    [...seatState.keys()].forEach((seat) => { if (!seatInfo.has(seat)) seatState.delete(seat); });
    // Partida nova (ou a primeira carga): retoma a sua do servidor ou do navegador, senão embaralha uma nova.
    if (table.status === 'playing' && (!S || S.game !== table.game)) {
      const saved = [resumeState, load()].find((state) => state && state.v === 2 && state.sig === signature && state.game === table.game);
      if (saved) { S = saved; if (!first) toast('Partida retomada.', { kind: 'info' }); }
      else {
        newGame(Math.max(2, seatInfo.size) === 2);
        S.game = table.game;
        S.first = table.starting === ME;
        const startEvent = update.events.filter((e) => e.kind === 'start' && e.payload?.game === table.game).slice(-1)[0];
        S.appliedEvent = startEvent ? startEvent.id : Math.max(0, ...update.events.map((e) => e.id));
        history.length = 0;
        seatState.clear();
        if (!first || before.game) toast(`Partida ${table.game} começou: ${table.starting === ME ? 'você joga primeiro' : `${seatName(table.starting)} joga primeiro`}. Decida sua mão.`, { kind: 'win' });
        setView('table');
      }
      resumeState = null;
    }
    if (!S) { newGame(false); S.game = -1; }
    if (first && table.status === 'playing') setView('table');
    update.events.forEach((event) => handleEvent(event, !first));
    if (!first && update.events.length && !logOpen()) { unread += update.events.filter((event) => event.seat !== ME && ['log', 'chat', 'dice', 'reveal', 'damage', 'life', 'poison', 'concede', 'join'].includes(event.kind)).length; renderLogToggle(); }
    // Vez: quando passa para você, o turno começa sozinho (desvirar, manutenção, compra).
    const turnKey = `${T.game}:${T.number}`;
    if (T.status === 'playing' && turnKey !== lastTurnKey) { lastTurnKey = turnKey; if (!first && T.turn === ME && T.number > 1) toast('Sua vez!', { kind: 'win' }); }
    renderLobby();
    render();
    maybeBeginTurn();
    save();
    // Ataques contra você: um aviso quando começam.
    opponents().forEach((seat) => {
      const incoming = (seatState.get(seat)?.battlefield || []).filter((entry) => entry.a && entry.at === ME);
      const keyName = `atk${seat}`;
      if (incoming.length && !onUpdate[keyName]) { const power = incoming.reduce((sum, entry) => sum + (parseInt(entry.pt, 10) || 0), 0); toast(`${seatName(seat)} está atacando você com ${incoming.length} criatura${incoming.length > 1 ? 's' : ''} (${power} de força).`, { kind: 'warn', who: seat }); }
      onUpdate[keyName] = incoming.length > 0;
    });
  }

  function renderLobby() {
    if (!lobby) return;
    const inLobby = T.status !== 'playing';
    lobby.hidden = !inLobby;
    root.classList.toggle('is-lobby', inLobby);
    if (!inLobby) return;
    const link = new URL(MP.url, location.origin).href;
    const isHost = T.host === ME;
    const seated = [...seatInfo.entries()];
    lobby.innerHTML = `<div class="pt-lobby-card">
      <p class="pt-lobby-kicker">Mesa compartilhada · ${esc(FORMAT.name)}</p>
      <h2>${seated.length < 2 ? 'Chame até 3 amigos' : `${seated.length} jogadores na mesa`}</h2>
      <p class="pt-lobby-text">Mande o link: quem abrir entra com a própria conta e escolhe um deck de ${esc(FORMAT.name)}. ${isHost ? 'Quando todos estiverem sentados, comece a partida — quem joga primeiro é sorteado.' : `Aguardando ${esc(seatName(T.host))} começar a partida.`}</p>
      <div class="pt-lobby-link"><input type="text" readonly value="${esc(link)}" aria-label="Link da mesa" data-lobby-link><button type="button" class="pt-btn is-strong" data-copy-link>Copiar link</button></div>
      <ul class="pt-lobby-seats">${[0, 1, 2, 3].map((seat) => { const info = seatInfo.get(seat); return `<li class="${info ? 'is-taken' : 'is-free'}" style="--seat:${SEAT_COLORS[seat]}">${info?.commander?.image ? `<img src="${esc(info.commander.image)}" alt="" width="146" height="204">` : `<span class="pt-lobby-empty">${info ? '♦' : '+'}</span>`}<span><strong>${info ? esc(info.player) + (seat === ME ? ' (você)' : '') : 'Lugar livre'}</strong><small>${info ? esc(info.deck) : 'Pelo link'}</small>${info && seat === T.host ? '<em>abriu a mesa</em>' : ''}${info && info.online === false ? '<em>desconectado</em>' : ''}</span>${isHost && info && seat !== ME ? `<button type="button" class="pt-lobby-kick" data-kick="${seat}" aria-label="Tirar ${esc(info.player)} da mesa">×</button>` : ''}</li>`; }).join('')}</ul>
      <div class="pt-lobby-actions">${isHost ? `<button type="button" class="pt-btn is-strong" data-lobby-start ${seated.length < 2 ? 'disabled' : ''}>Começar partida</button>` : ''}<button type="button" class="pt-btn" data-lobby-leave>Sair da mesa</button><a class="pt-btn is-quiet" href="/deck_playtest.php?deck=${data.deck.id}">Treinar sozinho</a></div>
    </div>`;
  }
  lobby?.addEventListener('click', async (event) => {
    if (event.target.closest('[data-copy-link]')) {
      const input = lobby.querySelector('[data-lobby-link]');
      try { await navigator.clipboard.writeText(input.value); toast('Link copiado.', { kind: 'info' }); } catch { input.select(); document.execCommand?.('copy'); }
      return;
    }
    const kick = event.target.closest('[data-kick]');
    try {
      if (kick) await net.post('kick', { seat: kick.dataset.kick });
      if (event.target.closest('[data-lobby-start]')) await net.post('start');
      if (event.target.closest('[data-lobby-leave]')) { await net.post('leave'); location.href = `/deck_playtest.php?deck=${data.deck.id}`; }
    } catch (error) { toast(error.message, { kind: 'warn' }); }
  });

  /** Painel "Mesa" durante a partida: link, jogadores e o que fazer com a partida. */
  function openTablePanel() {
    const isHost = T.host === ME;
    const link = new URL(MP.url, location.origin).href;
    openModal(`<h2>Mesa compartilhada</h2>
      <p class="pt-modal-note">Partida ${T.game} · turno ${T.number} da mesa · ${esc(FORMAT.name)}.</p>
      <div class="pt-lobby-link is-light"><input type="text" readonly value="${esc(link)}" aria-label="Link da mesa"><button type="button" class="pt-btn" data-copy-link>Copiar link</button></div>
      <ul class="pt-table-seats">${[...seatInfo.entries()].map(([seat, info]) => `<li style="--seat:${SEAT_COLORS[seat]}"><b>${esc(info.player)}${seat === ME ? ' (você)' : ''}</b><span>${esc(info.deck)}</span><span>${seat === ME ? `♥ ${S.life}` : `♥ ${seatState.get(seat)?.life ?? '—'}`}</span>${info.eliminated ? '<em>fora</em>' : ''}${T.turn === seat ? '<em>na vez</em>' : ''}</li>`).join('')}</ul>
      <div class="pt-modal-actions">
        ${isHost ? '<button type="button" class="pt-btn" data-table-lobby>Encerrar e voltar ao lobby</button>' : ''}
        ${isHost && T.turn !== ME && seatInfo.get(T.turn)?.online === false ? `<button type="button" class="pt-btn" data-table-skip>Pular a vez de ${esc(seatName(T.turn))}</button>` : ''}
        <button type="button" class="pt-btn" data-table-concede ${seatInfo.get(ME)?.eliminated ? 'disabled' : ''}>Conceder a partida</button>
        <button type="button" class="pt-btn" data-table-leave>Sair da mesa</button>
      </div>`, async (event) => {
      try {
        if (event.target.closest('[data-copy-link]')) { await navigator.clipboard.writeText(link).catch(() => {}); toast('Link copiado.', { kind: 'info' }); return; }
        if (event.target.closest('[data-table-lobby]')) { closeModal(); await net.post('lobby'); return; }
        if (event.target.closest('[data-table-skip]')) { closeModal(); await net.post('turn', { number: String(T.number) }); return; }
        if (event.target.closest('[data-table-concede]')) { closeModal(); act('concede', () => { S.conceded = true; log('Concedeu a partida.', null); }); await net.event('concede'); return; }
        if (event.target.closest('[data-table-leave]')) { await net.post('leave'); location.href = `/deck_playtest.php?deck=${data.deck.id}`; }
      } catch (error) { toast(error.message, { kind: 'warn' }); }
    });
  }

  if (MP) {
    const chat = $('[data-pt-chat]');
    chat?.addEventListener('submit', (event) => {
      event.preventDefault();
      const input = chat.elements.text;
      const text = input.value.trim();
      if (!text) return;
      input.value = '';
      net.event('chat', { text }).catch((error) => toast(error.message, { kind: 'warn' }));
    });
    const netModule = await import(data.net);
    net = netModule.createTableClient({ socket: MP.socket, api: MP.api, token: MP.token, ticket: MP.ticket }, {
      onUpdate,
      onError: (message) => toast(message, { kind: 'warn' }),
      onStatus: (state) => root.classList.toggle('is-offline', state === 'offline'),
      onGone: (message) => { toast(message || 'A mesa foi encerrada.', { kind: 'warn' }); setTimeout(() => { location.href = `/deck_playtest.php?deck=${data.deck.id}`; }, 2500); },
    });
    // Até a primeira resposta da mesa, a partida local fica parada.
    S = freshState(); S.game = -1;
    render();
    return;
  }

  /* ---------- Começo (mesa solo) ---------- */
  const saved = load();
  if (saved) { S = saved; toast(`Partida retomada no turno ${S.turn}. “Nova partida” recomeça do zero.`, { kind: 'info' }); }
  else newGame(FORMAT.players === 2);
  render();
  save();
}
