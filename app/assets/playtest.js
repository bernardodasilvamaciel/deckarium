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
  /* Turno (500.1): cinco fases, nesta ordem; a inicial, a de combate e a final se dividem em etapas. */
  const PHASE_INFO = [
    { key: 'beginning', name: 'Fase inicial', short: 'Inicial', rule: '501' },
    { key: 'main1', name: 'Primeira fase principal', short: 'Principal 1', rule: '505' },
    { key: 'combat', name: 'Fase de combate', short: 'Combate', rule: '506' },
    { key: 'main2', name: 'Segunda fase principal', short: 'Principal 2', rule: '505' },
    { key: 'ending', name: 'Fase final', short: 'Final', rule: '512' },
  ];
  const STEPS = [
    { key: 'untap', phase: 0, name: 'Desvirar', full: 'Etapa de desvirar', rule: '502', hint: 'Suas permanentes desviram. Ninguém recebe prioridade nesta etapa.' },
    { key: 'upkeep', phase: 0, name: 'Manutenção', full: 'Etapa de manutenção', rule: '503', hint: 'Gatilhos “no início da sua manutenção” acontecem agora.' },
    { key: 'draw', phase: 0, name: 'Compra', full: 'Etapa de compra', rule: '504', hint: 'Você compra uma carta.' },
    { key: 'main1', phase: 1, name: 'Principal 1', full: 'Primeira fase principal', rule: '505', hint: 'Jogue um terreno e lance criaturas, feitiços e o que quiser.' },
    { key: 'bcombat', phase: 2, name: 'Início', full: 'Início do combate', rule: '507', hint: 'Último momento antes dos ataques: gatilhos “no início do combate”.' },
    { key: 'attackers', phase: 2, name: 'Atacantes', full: 'Declarar atacantes', rule: '508', hint: 'Clique nas criaturas que vão atacar. Elas viram (menos com vigilância).' },
    { key: 'blockers', phase: 2, name: 'Bloqueadores', full: 'Declarar bloqueadores', rule: '509', hint: 'Quem defende escolhe as criaturas que bloqueiam.' },
    { key: 'damage', phase: 2, name: 'Dano', full: 'Dano de combate', rule: '510', hint: 'Atacantes e bloqueadores causam dano ao mesmo tempo (iniciativa antes).' },
    { key: 'ecombat', phase: 2, name: 'Fim', full: 'Fim do combate', rule: '511', hint: 'As criaturas saem do combate.' },
    { key: 'main2', phase: 3, name: 'Principal 2', full: 'Segunda fase principal', rule: '505', hint: 'Terreno (se ainda não jogou) e mais mágicas.' },
    { key: 'end', phase: 4, name: 'Final', full: 'Etapa final', rule: '513', hint: 'Gatilhos “no início da etapa final”.' },
    { key: 'cleanup', phase: 4, name: 'Limpeza', full: 'Etapa de limpeza', rule: '514', hint: 'Descarte até 7 cartas; o dano marcado e os efeitos “até o fim do turno” acabam.' },
  ];
  const STEP = Object.fromEntries(STEPS.map((step, i) => [step.key, i]));
  const stepTitle = (i) => (STEPS[i] ? (STEPS[i].phase === 1 || STEPS[i].phase === 3 ? STEPS[i].full : `${PHASE_INFO[STEPS[i].phase].name} · ${STEPS[i].full}`) : '');
  const COUNTERS = ['+1/+1', '-1/-1', 'Lealdade', 'Carga', 'Tempo', 'Escudo'];
  // Habilidades que um efeito pode dar (e que a mesa usa: ímpeto, vigilância, vínculo com a vida, golpe duplo, indestrutível).
  const KEYWORDS = { haste: 'Ímpeto', vigilance: 'Vigilância', flying: 'Voar', trample: 'Atropelar', lifelink: 'Vínculo com a vida', deathtouch: 'Toque mortífero',
    'first strike': 'Iniciativa', 'double strike': 'Golpe duplo', indestructible: 'Indestrutível', menace: 'Ameaça', reach: 'Alcance', hexproof: 'Resistência a magia' };
  const FORMAT = data.format;
  const MP = data.table || null;
  const ME = MP ? MP.seat : null;
  const signature = data.library.map((c) => `${c.id}:${c.quantity}`).join(',') + '|' + (data.commander?.id || '') + '|' + (data.signature?.id || '') + '|' + FORMAT.key;
  const storeKey = MP ? `deckarium-playtest-mp3-${MP.token}` : `deckarium-playtest-v3-${data.deck.id}`;

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
  // Um efeito que define força/resistência (“vira uma criatura 3/3”) também torna a permanente uma criatura.
  const isCreature = (inst) => inst.faceDown || /\bCreature\b/.test(typeOf(inst)) || (inst.mods || []).some((mod) => mod.set);
  const isPlaneswalker = (inst) => /\bPlaneswalker\b/.test(typeOf(inst));
  const rowOf = (inst) => (isLand(inst) ? 'land' : isCreature(inst) ? 'creature' : 'other');
  const num = (value) => (/^-?\d+$/.test(String(value ?? '')) ? Number(value) : null);
  /**
   * Força e resistência na ordem das camadas (613.4): a impressa; um efeito que define a base (“vira 4/4”, 7b);
   * depois os modificadores — marcadores +1/+1 e −1/−1 e efeitos como +2/+0 até o fim do turno (7c).
   */
  function ptOf(inst) {
    if (!isCreature(inst)) return null;
    const face = faceOf(inst);
    const printed = { p: num(face.power), t: num(face.toughness) };
    let p = printed.p; let t = printed.t;
    (inst.mods || []).forEach((mod) => { if (mod.set) { p = mod.set.p; t = mod.set.t; } });
    const counters = (inst.counters['+1/+1'] || 0) - (inst.counters['-1/-1'] || 0);
    let dp = counters; let dt = counters;
    (inst.mods || []).forEach((mod) => { dp += mod.p || 0; dt += mod.t || 0; });
    const fp = p === null ? null : p + dp; const ft = t === null ? null : t + dt;
    const up = (fp ?? 0) > (printed.p ?? 0) || (ft ?? 0) > (printed.t ?? 0);
    const down = (fp ?? 0) < (printed.p ?? 0) || (ft ?? 0) < (printed.t ?? 0);
    return { p: fp, t: ft, text: `${fp === null ? face.power ?? '*' : fp}/${ft === null ? face.toughness ?? '*' : ft}`, state: up && down ? 'mixed' : up ? 'up' : down ? 'down' : 'normal', damage: inst.damage || 0 };
  }
  /** Habilidades da carta (do Scryfall) e as que efeitos deram a ela. */
  function keywordsOf(inst) {
    const set = new Set();
    if (!inst.faceDown) (cardOf(inst).keywords || []).forEach((word) => set.add(String(word).toLowerCase()));
    (inst.mods || []).forEach((mod) => (mod.kw || []).forEach((word) => set.add(word)));
    return set;
  }
  /*
   * Ímpeto dado por outra permanente: “Creatures you control have haste” (Fervor, Hammer of Purphoros),
   * “Goblin creatures you control have haste”, “Other … creatures you control have haste” e “All creatures have haste”
   * (que vale também para as criaturas dos outros jogadores). Equipamentos e auras não entram: aí use “Dar ímpeto”.
   */
  let grantMemo = null;
  function hasteGrants() {
    if (grantMemo) return grantMemo;
    const list = [];
    const scan = (text, name, iid, mine) => {
      String(text || '').toLowerCase().split(/(?<=[.\n])/).forEach((sentence) => {
        if (!/\bhaste\b/.test(sentence) || /graveyard|equipped|enchanted|target|until end of turn/.test(sentence)) return;
        if (/\ball creatures have [^.]*\bhaste\b/.test(sentence)) { list.push({ name, iid, all: true }); return; }
        if (!mine) return;
        const m = sentence.match(/(?:^|,\s*)\s*(other\s+)?([a-z' -]*?)\s*creatures you control (?:have|has) [^.]*\bhaste\b/);
        if (!m) return;
        const words = m[2].trim().split(/\s+/).filter((word) => word && !['and', 'each'].includes(word));
        list.push({ name, iid, other: !!m[1], types: words });
      });
    };
    S.zones.battlefield.forEach((iid) => { const inst = S.cards[iid]; if (!inst.faceDown) scan(faceOf(inst).text, nameOf(iid), iid, true); });
    if (MP) opponents().forEach((seat) => (seatState.get(seat)?.battlefield || []).forEach((entry) => { const def = defFor(seat, entry.k); if (def && !entry.fd) scan(def.x, def.n, null, false); }));
    return (grantMemo = list);
  }
  /** De onde vem o ímpeto da criatura: 'a própria carta', o nome da carta que dá ou um efeito; null se não tem. */
  function hasteSource(inst) {
    if (keywordsOf(inst).has('haste')) return (inst.mods || []).some((mod) => (mod.kw || []).includes('haste')) ? 'um efeito' : 'a própria carta';
    const type = typeOf(inst).toLowerCase();
    const grant = hasteGrants().find((g) => g.all || ((!g.other || g.iid !== inst.iid) && g.types.every((word) => type.includes(word.replace(/s$/, '')))));
    return grant ? grant.name : null;
  }
  const hasKeyword = (inst, word) => keywordsOf(inst).has(word) || (word === 'haste' && !!hasteSource(inst));
  // Entrou neste turno (sob o seu controle desde o começo do seu turno mais recente? 302.6).
  const isFresh = (inst) => inst.zone === 'battlefield' && isCreature(inst) && inst.entered === S.turn && S.stage === 'play';
  const isSick = (inst) => isFresh(inst) && !hasteSource(inst);
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
    return { v: 3, sig: signature, seq: 0, cards: {}, zones: { library: [], hand: [], battlefield: [], graveyard: [], exile: [], command: [], lent: [] },
      turn: MP ? 0 : 1, phase: 3, life: FORMAT.life, poison: 0, opponent: FORMAT.life, lands: 0, mulligans: 0, stage: 'mulligan', bottom: [], log: [], custom: [],
      killTurn: null, twoPlayer: false, first: true, order: 0, game: 0, cmd: {}, turnSeen: 0, appliedEvent: 0, conceded: false, discard: 0, cleaned: '', lent: {}, peek: null };
  }
  function newInstance(ref, extra = {}) {
    const iid = `c${++S.seq}`;
    S.cards[iid] = { iid, ref, zone: null, x: 0, z: 0, tapped: false, faceDown: false, flipped: false, counters: {}, mods: [], damage: 0, blocking: null, attacking: false, attackTarget: null, entered: 0, manual: false, token: false, commander: false, casts: 0, order: 0, ...extra };
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
  /* Registro: cada ação tem uma categoria (o ícone na lista) e uma versão pública, sem o que é segredo (as cartas que
     você comprou ou olhou). Na mesa compartilhada a versão pública vai para todos; null = só no seu navegador. */
  const ACTION_CATS = { block: 'combat', mod: 'counter', interact: 'move', cleanup: 'turn', draw: 'draw', play: 'play', drop: 'play', commander: 'play', 'commander-zone': 'move', position: 'move', move: 'move', 'exile-top': 'move', mill: 'move',
    tap: 'tap', untap: 'tap', attack: 'combat', damage: 'combat', adjust: 'life', token: 'token', copy: 'token', counter: 'counter', flip: 'flip', face: 'flip',
    keep: 'hand', mulligan: 'hand', bottom: 'hand', shuffle: 'shuffle', look: 'look', scry: 'look', search: 'search', zone: 'search', reveal: 'reveal',
    turn: 'turn', phase: 'phase', concede: 'concede', incoming: 'damage', undo: 'undo' };
  let currentCat = 'info';
  function log(text, publicText = text, cat = currentCat) {
    S.log.unshift({ turn: S.turn, text, cat });
    if (S.log.length > 250) S.log.length = 250;
    if (MP && publicText) outbox.push({ t: publicText, c: cat });
  }

  /* ---------- Mesa 3D ---------- */
  const status = $('[data-pt-status]');
  let scene = null;
  let LAYOUT = null;
  const pendingFx = [];
  /* ---------- Sua mesa: paisagem, madeira, shield e verso (guardados na conta) e os sons ---------- */
  const PREFS = { environment: 'auto', wood: 'carvalho', sleeve: 'esmeralda', back: 'deckarium', ...(data.prefs || {}) };
  let worldKit = null;
  try { worldKit = await import(data.world); } catch (error) { console.warn(error); }
  const envOf = () => (PREFS.environment === 'auto' || !worldKit?.ENVIRONMENTS[PREFS.environment] ? (worldKit ? worldKit.environmentForColors(data.colorCounts) : 'plains') : PREFS.environment);
  const myStyle = () => ({ sleeve: PREFS.sleeve, back: PREFS.back, art: PREFS.back === 'arte' ? data.backArt || null : null });
  let audio = { play() {}, setAmbient() {}, settings: () => ({ sfx: false, ambient: false, volume: 0, ambientVolume: 0 }), set() {} };
  try { audio = (await import(data.audio)).createAudio(); } catch (error) { console.warn(error); }
  try {
    const module = await import(data.scene);
    scene = module.createPlaytestScene($('[data-pt-stage]'), {
      environment: envOf(),
      wood: PREFS.wood,
      style: myStyle(),
      onSeatCardEnter: () => audio.play('card'),
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
    audio.setAmbient(envOf());
    // O verso escolhido também aparece nas janelas (cartas viradas ao olhar o grimório).
    setTimeout(() => root.style.setProperty('--pt-back', `url("${scene.backImage()}")`), 600);
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
    // Som de entrada: terreno, criatura, mágica no tom das cores, comandante ou ficha.
    const colors = faceOf(inst).colors || ['C'];
    audio.play(inst.token ? 'token' : from === 'command' ? 'commander' : inst.faceDown ? 'card' : isLand(inst) ? 'land' : isCreature(inst) ? 'creature' : 'spell', { colors });
    enterTriggers(inst);
  }
  /** Lembrete dos gatilhos de entrada: o da própria carta e os das suas permanentes que olham quem entra. */
  function enterTriggers(inst) {
    if (inst.faceDown) return;
    const name = nameOf(inst.iid);
    const text = String(faceOf(inst).text || '');
    const own = name.split(' // ')[0].replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    if (new RegExp(`\\bwhen(?:ever)?\\s+(?:this [a-z]+|${own})\\s+enters\\b`, 'i').test(text)) remind(`${name} (ao entrar)`);
    const kind = isCreature(inst) ? 'creature' : isLand(inst) ? 'land' : /Artifact/.test(typeOf(inst)) ? 'artifact' : /Enchantment/.test(typeOf(inst)) ? 'enchantment' : 'permanent';
    S.zones.battlefield.forEach((other) => {
      if (other === inst.iid || S.cards[other].faceDown) return;
      const watch = String(faceOf(S.cards[other]).text || '').match(/whenever (?:another|a|an|one or more)\s+(?:nontoken\s+)?([a-z]+)[^.]*?\benters?\b/i);
      if (watch && (watch[1] === kind || watch[1] === 'permanent' || (watch[1] === 'creatures' && kind === 'creature')) && !(inst.token && /nontoken/i.test(watch[0]))) remind(`${nameOf(other)} (entrou ${{ creature: 'uma criatura', land: 'um terreno', artifact: 'um artefato', enchantment: 'um encantamento' }[kind] || 'uma permanente'})`);
    });
  }
  /* Lembretes de gatilhos: juntados durante a ação e mostrados num aviso só, depois dela. */
  let reminders = [];
  function remind(text) { if (!reminders.includes(text)) reminders.push(text); }
  function flushReminders() {
    if (!reminders.length) return;
    toast(`Gatilhos: ${reminders.join(' · ')}`, { kind: 'trigger', rule: '603' });
    reminders = [];
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
    if (inst.commander && !inst.borrowed && ['graveyard', 'exile', 'hand', 'library'].includes(to) && !opts.decided) {
      askCommander(iid, to);
      return;
    }
    if (from === 'battlefield') {
      scene.depart(iid, inst.token || inst.borrowed ? 'gone' : to);
      // Saindo do campo, a carta vira um objeto novo: sem marcadores, efeitos, dano nem combate (400.7).
      Object.assign(inst, { tapped: false, attacking: false, attackTarget: null, blocking: null, counters: {}, mods: [], damage: 0, faceDown: false, flipped: false, manual: false, walker: false });
      audio.play(to === 'graveyard' ? 'die' : to === 'exile' ? 'exile' : to === 'hand' ? 'hand' : 'card');
    }
    // Carta de outro jogador sob o seu controle: fora do campo, vai para a zona do dono (400.3).
    if (inst.borrowed && to !== 'battlefield') {
      const list = S.zones[from]; list.splice(list.indexOf(iid), 1);
      delete S.cards[iid];
      const owner = inst.borrowed.seat;
      net?.event('interact', { to: owner, op: 'return', iid: inst.borrowed.iid, zone: to, position: opts.position || 'top' }).catch((error) => toast(error.message, { kind: 'warn' }));
      log(`${name} saiu do campo e foi para ${toZone[to] || zoneName(to)} de ${seatName(owner)}, a dona da carta.`);
      toast(`${name} é de ${seatName(owner)}: vai para ${toZone[to] || zoneName(to)} de quem é dono dela.`, { rule: '400.3' });
      return;
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
  const zoneName = (zone, position) => ({ library: position === 'bottom' ? 'fundo do grimório' : position === 'top' ? 'topo do grimório' : 'grimório', hand: 'mão', battlefield: 'campo', graveyard: 'cemitério', exile: 'exílio', command: 'zona de comando', lent: 'controle de outro jogador' }[zone] || zone || '—');
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
        // Dano letal destrói (704.5g), menos com indestrutível; resistência 0 mata mesmo assim.
        const lethal = !dies && pt && pt.t !== null && (inst.damage || 0) >= pt.t && pt.t > 0 && !hasKeyword(inst, 'indestructible');
        const spent = !dies && !lethal && inst.walker && (inst.counters.Lealdade || 0) <= 0;
        if (dies || spent || lethal) {
          toast(dies ? `${nameOf(iid)} foi para o cemitério com resistência ${pt.t}.` : lethal ? `${nameOf(iid)} recebeu dano letal (${inst.damage} com resistência ${pt.t}) e foi destruída.` : `${nameOf(iid)} ficou sem lealdade e foi para o cemitério.`, { rule: dies ? '704.5f' : lethal ? '704.5g' : '704.5i', kind: 'warn' });
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
    history.push({ label, state: JSON.stringify(S) });
    if (history.length > 80) history.shift();
    currentCat = ACTION_CATS[label] || 'info';
    grantMemo = null;
    try { fn(); } finally { currentCat = 'info'; }
    grantMemo = null;
    stateBased();
    render();
    save();
    flushReminders();
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
      audio.play('draw');
      if (!silent) log(drawn === 1 ? `Comprou ${nameOf(S.zones.hand[S.zones.hand.length - 1])}.` : `Comprou ${drawn} cartas.`, drawn === 1 ? 'Comprou 1 carta.' : `Comprou ${drawn} cartas.`);
    }
  }

  function untapAll(silent = false) {
    let count = 0;
    S.zones.battlefield.forEach((iid) => { const inst = S.cards[iid]; if (inst.tapped) { inst.tapped = false; count++; } inst.attacking = false; inst.attackTarget = null; });
    if (count) audio.play('untap');
    if (!silent && count) log(`Desvirou ${count} permanente(s).`);
  }

  // Quem começa uma partida a dois não compra no primeiro turno (103.8a).
  const skipsFirstDraw = () => S.turn === 1 && S.twoPlayer && S.first;
  /* Gatilhos de etapa no texto das suas permanentes: [no seu turno, no turno de outro jogador]. */
  const STEP_TRIGGERS = {
    upkeep: [/at the beginning of (?:your|each|each player's|the) upkeep/i, /at the beginning of (?:each|each player's|each opponent's|the) upkeep/i],
    draw: [/at the beginning of (?:your|each|each player's) draw step/i, /at the beginning of (?:each|each player's|each opponent's) draw step/i],
    bcombat: [/at the beginning of (?:combat on your turn|each combat)/i, /at the beginning of (?:each combat|combat on each opponent's turn)/i],
    ecombat: [/at end of combat/i, null],
    end: [/at the beginning of (?:your|the|each|each player's) end step/i, /at the beginning of (?:each|the|each player's|each opponent's) end step/i],
  };
  function triggersFor(key, mine = true) {
    const pattern = STEP_TRIGGERS[key]?.[mine ? 0 : 1];
    if (!pattern) return [];
    return [...new Set(S.zones.battlefield.filter((iid) => !S.cards[iid].faceDown && pattern.test(faceOf(S.cards[iid]).text || '')).map(nameOf))];
  }
  /** Entra numa etapa: as ações do turno (desvirar 502.3, comprar 504.1, sair do combate 511.3, limpeza 514) e os lembretes. */
  function enterStep(i, { announce = true } = {}) {
    S.phase = i;
    const key = STEPS[i].key;
    if (key === 'untap') { untapAll(); S.lands = 0; }
    if (key === 'draw') { if (skipsFirstDraw()) toast('Quem começa uma partida a dois não compra no primeiro turno.', { rule: '103.8a', kind: 'info' }); else drawCards(1); }
    if (key === 'ecombat') endCombat();
    if (key === 'cleanup') cleanupStep();
    const triggers = triggersFor(key);
    triggers.forEach((name) => remind(`${name} (${STEPS[i].full.toLowerCase()})`));
    if (announce) banner(i, { triggers });
    return triggers;
  }
  function endCombat() { S.zones.battlefield.forEach((iid) => { S.cards[iid].attacking = false; S.cards[iid].attackTarget = null; }); }
  /** 514.2: o dano marcado sai e os efeitos “até o fim do turno” acabam (os bloqueios também). */
  function expireEndOfTurn(who = 'Limpeza') {
    let effects = 0; let hurt = 0;
    S.zones.battlefield.forEach((iid) => {
      const inst = S.cards[iid];
      const before = (inst.mods || []).length;
      inst.mods = (inst.mods || []).filter((mod) => mod.until !== 'eot');
      effects += before - inst.mods.length;
      if (inst.damage) { hurt += 1; inst.damage = 0; }
      inst.blocking = null;
    });
    if (effects || hurt) log(`${who}: ${[effects ? `${effects} efeito(s) “até o fim do turno” acabaram` : '', hurt ? `o dano saiu de ${hurt} criatura(s)` : ''].filter(Boolean).join(' e ')}.`);
    return effects + hurt;
  }
  function cleanupStep() {
    expireEndOfTurn();
    S.cleaned = MP ? `${T.game}:${T.number}` : `solo:${S.turn}`;
  }
  const postStep = () => { if (MP) net.post('phase', { phase: String(S.phase) }).catch((error) => toast(error.message, { kind: 'warn' })); };
  // 508.8: sem atacantes, as etapas de bloqueadores e de dano não acontecem.
  const skipsStep = (i) => (i === STEP.blockers || i === STEP.damage) && !attackers().length;
  function nextStep() {
    if (S.stage !== 'play') { toast('Primeiro, decida a mão inicial.', { kind: 'info' }); return; }
    if (MP && !myTurn()) { toast(`O turno é de ${seatName(T.turn)}. Você ainda pode virar cartas, lançar mágicas instantâneas e mexer nas suas zonas.`, { kind: 'info' }); return; }
    if (S.discard > 0) { toast(`Descarte ${S.discard} carta${S.discard > 1 ? 's' : ''} antes de passar.`, { rule: '514.1', kind: 'warn' }); return; }
    if (S.phase >= STEP.cleanup) { endTurn(); return; }
    let next = S.phase + 1;
    const skipped = skipsStep(next);
    while (next < STEP.cleanup && skipsStep(next)) next += 1;
    act('phase', () => { enterStep(next); log(`${stepTitle(next)}.`); });
    if (skipped) toast('Ninguém atacou: as etapas de bloqueadores e de dano de combate não acontecem.', { rule: '508.8', kind: 'info' });
    postStep();
  }
  /** Clique numa etapa da barra: avança até ela, cumprindo as etapas do caminho (voltar, só com Desfazer). */
  function goToStep(target) {
    if (S.stage !== 'play' || (MP && !myTurn())) { nextStep(); return; }
    if (target <= S.phase) { toast(target === S.phase ? `Você já está em: ${STEPS[target].full}.` : 'O turno não volta: para desfazer uma etapa, use Desfazer (Ctrl+Z).', { kind: 'info' }); return; }
    if (S.discard > 0) return;
    act('phase', () => { for (let i = S.phase + 1; i <= target; i++) if (!skipsStep(i) || i === target) enterStep(i, { announce: i === target }); log(`${stepTitle(target)}.`); });
    postStep();
  }
  /**
   * Passar o turno: as etapas que faltam acontecem em ordem. Para na etapa final se houver gatilhos dela (para você
   * resolver) e na limpeza se a mão passar de 7 (514.1).
   */
  function endTurn() {
    if (S.stage !== 'play') { toast('Primeiro, decida a mão inicial.', { kind: 'info' }); return; }
    if (MP && !myTurn()) { toast(T.status === 'playing' ? `É a vez de ${seatName(T.turn)}.` : 'A partida ainda não começou.', { kind: 'info' }); return; }
    if (S.discard > 0) { toast(`Descarte ${S.discard} carta${S.discard > 1 ? 's' : ''} para terminar o turno (ou ignore o limite).`, { rule: '514.1', kind: 'warn' }); return; }
    if (S.phase < STEP.end && triggersFor('end').length) {
      act('phase', () => { for (let i = S.phase + 1; i <= STEP.end; i++) if (!skipsStep(i)) enterStep(i, { announce: i === STEP.end }); log(`${stepTitle(STEP.end)}.`); });
      postStep();
      toast('Há gatilhos na etapa final: resolva e passe o turno de novo.', { rule: '513.1', kind: 'info' });
      return;
    }
    if (S.phase < STEP.cleanup) {
      act('phase', () => { for (let i = S.phase + 1; i <= STEP.cleanup; i++) if (!skipsStep(i)) enterStep(i, { announce: i === STEP.cleanup }); });
      postStep();
    }
    if (S.zones.hand.length > 7) {
      act('phase', () => { S.discard = S.zones.hand.length - 7; log(`Limpeza: ${S.zones.hand.length} cartas na mão, descarte ${S.discard}.`); });
      audio.play('warn');
      return;
    }
    finishTurn();
  }
  function finishTurn() {
    if (MP) { passTurn(); return; }
    act('turn', () => {
      S.turn += 1;
      log(`— Turno ${S.turn} —`);
      beginTurnSteps();
    });
    audio.play('turn', { mine: true });
    scene.fx.turn(null);
  }
  /** Começo do turno: desvirar, manutenção e compra; para na manutenção ou na compra se houver gatilhos ali. */
  function beginTurnSteps() {
    endCombat();
    S.discard = 0;
    enterStep(STEP.untap);
    if (enterStep(STEP.upkeep).length) { log(`${stepTitle(STEP.upkeep)}: há gatilhos para resolver.`); return; }
    if (enterStep(STEP.draw).length) { log(`${stepTitle(STEP.draw)}: há gatilhos para resolver.`); return; }
    enterStep(STEP.main1);
  }
  const nextTurn = endTurn;

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
    if (!MP) { log('— Turno 1 —'); beginTurnSteps(); audio.play('turn', { mine: true }); }
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
    const playing = S.stage === 'play';
    if (playing && myTurn() && S.phase === STEP.attackers && isCreature(inst) && !isLand(inst)) { toggleAttack(iid); return; }
    if (playing && blockWindow() && isCreature(inst) && !isLand(inst)) { toggleBlock(iid); return; }
    toggleTap(iid);
  }
  function toggleTap(iid) {
    const inst = S.cards[iid];
    if (!inst) return;
    act('tap', () => {
      inst.tapped = !inst.tapped;
      if (inst.tapped && isSick(inst) && !isLand(inst)) toast(`${nameOf(iid)} entrou neste turno e não tem ímpeto: não pode atacar nem usar habilidades com {T}.`, { rule: '302.6', kind: 'info' });
      log(`${inst.tapped ? 'Virou' : 'Desvirou'} ${nameOf(iid)}.`);
    });
    audio.play('tap');
  }
  /* Bloqueios: no turno de outro jogador, enquanto ele ataca você, clique nas suas criaturas para bloquear (509.1). */
  const incomingAttackers = () => (MP && T.turn !== null && T.turn !== ME ? (seatState.get(T.turn)?.battlefield || []).filter((entry) => entry.a && entry.at === ME) : []);
  const blockWindow = () => MP && T.status === 'playing' && T.turn !== ME && (T.phase === STEP.attackers || T.phase === STEP.blockers) && incomingAttackers().length > 0;
  function toggleBlock(iid) {
    const inst = S.cards[iid];
    act('block', () => {
      if (inst.blocking !== null && inst.blocking !== undefined) { inst.blocking = null; log(`${nameOf(iid)} não bloqueia mais.`); return; }
      if (inst.tapped) { toast(`${nameOf(iid)} está virada e não pode bloquear.`, { rule: '509.1a', kind: 'warn' }); return; }
      inst.blocking = T.turn;
      log(`${nameOf(iid)} bloqueia um atacante de ${seatName(T.turn)}.`);
      audio.play('block');
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
      const vigilant = hasKeyword(inst, 'vigilance');
      if (inst.attacking && target === undefined) { inst.attacking = false; inst.attackTarget = null; if (!vigilant) inst.tapped = false; log(`${nameOf(iid)} não ataca mais.`); return; }
      if (inst.tapped && !inst.attacking) { toast(`${nameOf(iid)} está virada e não pode atacar.`, { rule: '508.1a', kind: 'warn' }); return; }
      if (isSick(inst)) toast(`${nameOf(iid)} entrou neste turno e não tem ímpeto: não poderia atacar. Se ganhou ímpeto de outro jeito (equipamento, mágica), use “Dar ímpeto” no menu da carta.`, { rule: '302.6', kind: 'warn' });
      const defender = MP ? (target ?? attackTargetFor()) : null;
      inst.attacking = true;
      inst.attackTarget = defender;
      if (!vigilant) inst.tapped = true;
      log(`${nameOf(iid)} ataca${MP && defender !== null ? ` ${seatName(defender)}` : ''}${vigilant ? ' (vigilância: não vira)' : ''}.`);
      if (!inst.faceDown && /whenever [^.]*\battacks\b/i.test(faceOf(inst).text || '')) remind(`${nameOf(iid)} (ao atacar)`);
    });
    if (S.cards[iid]?.attacking) audio.play('attack');
  }
  function attackers() { return S.zones.battlefield.map((iid) => S.cards[iid]).filter((inst) => inst.attacking); }
  /** Dano de combate de uma criatura: a força (dobrada com golpe duplo). */
  const combatDamageOf = (inst) => { const p = Math.max(0, ptOf(inst)?.p ?? 0); return hasKeyword(inst, 'double strike') ? p * 2 : p; };
  /** Depois do dano, o combate vai até o fim (511): as criaturas saem do combate. */
  function finishCombat() { for (let i = Math.max(S.phase + 1, STEP.blockers); i <= STEP.ecombat; i++) enterStep(i, { announce: i === STEP.ecombat }); }
  function applyDamage() {
    if (MP) { openDamage(); return; }
    const list = attackers();
    const total = list.reduce((sum, inst) => sum + combatDamageOf(inst), 0);
    const lifelink = list.filter((inst) => hasKeyword(inst, 'lifelink')).reduce((sum, inst) => sum + combatDamageOf(inst), 0);
    act('damage', () => {
      S.opponent -= total;
      if (lifelink) S.life += lifelink;
      log(`Dano de combate: ${total} no oponente (${list.map((inst) => nameOf(inst.iid)).join(', ')})${lifelink ? `; vínculo com a vida: +${lifelink} de vida` : ''}.`);
      floatText(`−${total}`, $('[data-pt-meter="opponent"]'));
      finishCombat();
    });
    audio.play('damage', { big: total >= 7 });
    if (lifelink) { audio.play('life', { up: true }); floatText(`+${lifelink}`, $('[data-pt-meter="life"]')); }
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
      ...(blockWindow() && isCreature(inst) ? [{ label: inst.blocking !== null && inst.blocking !== undefined ? 'Não bloquear' : 'Bloquear', key: 'B', action: () => toggleBlock(iid) }] : []),
      { label: 'Marcadores…', key: 'C', action: () => openCounters(iid) },
      { label: 'Adicionar +1/+1', key: '+', action: () => act('counter', () => addCounter(iid, '+1/+1', 1)) },
      ...((inst.counters['+1/+1'] || 0) > 0 ? [{ label: 'Tirar +1/+1', key: '−', action: () => act('counter', () => addCounter(iid, '+1/+1', -1)) }] : []),
      { label: isCreature(inst) ? 'Força/resistência, dano e habilidades…' : 'Virar criatura e outros efeitos…', key: 'P', action: () => openPT(iid) },
      ...(isFresh(inst) && !hasteSource(inst) ? [{ label: 'Dar ímpeto até o fim do turno', key: 'I', action: () => act('mod', () => addMod(iid, { kw: ['haste'], until: 'eot' })) }] : []),
      ...(inst.borrowed ? [{ label: `Devolver o controle para ${seatName(inst.borrowed.seat)}`, action: () => returnControl(iid) }] : []),
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
    log(`Revelou o topo do grimório: ${nameOf(top)}.`, MP ? null : undefined, 'reveal');
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
      { title: playing ? (MP ? turnTitle() : `Turno ${S.turn} · ${stepTitle(S.phase)}`) : 'Mão inicial' },
      { label: 'Comprar', key: 'D', disabled: !playing, action: () => act('draw', () => drawCards(1)) },
      { label: 'Desvirar tudo', key: 'U', disabled: !playing, action: () => act('untap', () => untapAll()) },
      { label: 'Próxima etapa', key: 'Espaço', disabled: !playing || !myTurn(), action: nextStep },
      { label: 'Passar o turno', key: 'N', disabled: !playing || !myTurn(), action: endTurn },
      '-',
      { label: 'Criar fichas…', key: 'K', disabled: !playing, action: openTokens },
      { label: 'Efeito em todas as suas criaturas…', disabled: !playing, action: () => openPT('all') },
      { label: 'Olhar o topo…', key: 'X', disabled: !playing, action: openScry },
      { label: 'Revelar o topo', key: 'R', disabled: !playing, action: revealTopOnce },
      { label: 'Embaralhar', key: 'S', disabled: !playing, action: shuffleLibrary },
      '-',
      ...(MP ? [{ label: 'Ver a mesa toda', key: 'V', action: () => setView('table') }, { label: 'Ver o meu tapete', action: () => setView('me') }, '-'] : []),
      { label: 'Dados e moeda…', action: openDice },
      { label: 'Paisagem, mesa, shield e sons…', action: openSettings },
      { label: 'Desfazer', key: 'Ctrl Z', action: undo },
    ], x, y);
  }

  function playFromHand(iid, at = null) {
    if (S.stage !== 'play') { toast('Primeiro, decida a mão inicial.', { kind: 'info' }); return; }
    act('play', () => move(iid, 'battlefield', { at }));
  }
  function shuffleLibrary() { act('shuffle', () => { shuffleList(S.zones.library); scene.fx.shuffle(); log('Embaralhou o grimório.'); }); audio.play('shuffle'); }
  function mill(n) { act('mill', () => { for (let i = 0; i < n; i++) { const top = S.zones.library[0]; if (!top) break; move(top, 'graveyard'); } }); }
  function addCounter(iid, name, delta) {
    const inst = S.cards[iid];
    inst.counters[name] = Math.max(0, (inst.counters[name] || 0) + delta);
    if (!inst.counters[name]) delete inst.counters[name];
    if (name === 'Lealdade' && delta > 0) inst.walker = inst.walker || isPlaneswalker(inst);
    audio.play('counter');
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
  function closeModal() { modal.hidden = true; modalCard.innerHTML = ''; modalCard.oninput = null; }

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
    if (zone === 'library') {
      if (!S.zones.library.length) { toast('O grimório está vazio.', { kind: 'info' }); return; }
      if (S.stage !== 'play') { toast('Primeiro, decida a mão inicial.', { kind: 'info' }); return; }
      act('search', () => { S.look = { n: S.zones.library.length, from: 'search', ids: [] }; log('Abriu o grimório para buscar (ao fechar, embaralha).'); });
    }
    const title = { library: 'Buscar no grimório', graveyard: 'Cemitério', exile: 'Exílio' }[zone];
    const note = zone === 'library' ? '<p class="pt-modal-note">A mesa vê que você está buscando. Buscar revela a ordem das cartas: ao fechar, a mesa embaralha, como pedem quase todas as buscas.</p>' : '';
    const paint = (filter = '') => { $('[data-zone-list]').innerHTML = zoneRows(zone, filter); };
    openModal(`<h2>${title} <span class="pt-modal-count">${S.zones[zone].length}</span></h2>${note}
      <label class="pt-field"><span class="sr-only">Filtrar pelo nome</span><input type="search" placeholder="Filtrar pelo nome…" data-zone-filter></label>
      <ul class="pt-zone-list" data-zone-list></ul>`, (event) => {
      const button = event.target.closest('[data-move]');
      if (!button) return;
      const iid = button.dataset.iid; const key = button.dataset.move;
      act(zone === 'library' ? 'zone' : 'move', () => (key === 'bottom' ? move(iid, 'library', { position: 'bottom' }) : move(iid, key === 'library' ? 'library' : key)));
      paint($('[data-zone-filter]')?.value || '');
      modalCard.querySelector('.pt-modal-count').textContent = S.zones[zone].length;
    }, () => { if (zone === 'library') finishSearch(); });
    paint();
    $('[data-zone-filter]').addEventListener('input', (event) => paint(event.target.value));
  }

  function finishSearch() {
    if (S.look?.from !== 'search') return;
    act('search', () => { shuffleList(S.zones.library); S.look = null; log('Fechou a busca e embaralhou o grimório.'); });
    scene.fx.shuffle();
  }

  /*
   * Olhar o grimório (vidência, vigiar, "olhe as N do topo"): primeiro escolhe quantas e de onde, sem ver nada.
   * Ao olhar, a mesa é avisada (registro e cartas viradas para baixo subindo do seu grimório) e as cartas aparecem
   * para você, uma a uma. Elas só saem daqui com um destino; fechar deixa todas como estavam — e também vai para o registro.
   */
  function openScry(from = 'top') {
    closeMenu();
    if (S.stage !== 'play') { toast('Primeiro, decida a mão inicial.', { kind: 'info' }); return; }
    if (S.look) { resumeLook(); return; }
    if (!S.zones.library.length) { toast('O grimório está vazio.', { kind: 'info' }); return; }
    const max = Math.min(15, S.zones.library.length);
    openModal(`<h2>Olhar cartas do grimório</h2>
      <p class="pt-modal-note">Quem está na mesa vê que você está olhando e quantas cartas — nunca quais. Depois de olhar, cada carta precisa de um destino; fechar deixa todas como estavam.</p>
      <div class="pt-look-pick">
        <label class="pt-field is-inline">Quantas cartas <input type="number" min="1" max="${max}" value="1" data-look-count></label>
        <span class="pt-chips" role="group" aria-label="De onde"><button type="button" class="pt-chip${from === 'top' ? ' is-on' : ''}" data-look-from="top" aria-pressed="${from === 'top'}">Do topo</button><button type="button" class="pt-chip${from === 'bottom' ? ' is-on' : ''}" data-look-from="bottom" aria-pressed="${from === 'bottom'}">Do fundo</button></span>
      </div>
      <div class="pt-modal-actions"><button type="button" class="pt-btn is-strong" data-look-start>Olhar as cartas</button></div>`, (event) => {
      const pick = event.target.closest('[data-look-from]');
      if (pick) {
        from = pick.dataset.lookFrom;
        modalCard.querySelectorAll('[data-look-from]').forEach((button) => { button.classList.toggle('is-on', button === pick); button.setAttribute('aria-pressed', String(button === pick)); });
        return;
      }
      if (!event.target.closest('[data-look-start]')) return;
      const n = Math.max(1, Math.min(max, Number(modalCard.querySelector('[data-look-count]').value) || 1));
      act('look', () => {
        const ids = from === 'bottom' ? S.zones.library.slice(-n) : S.zones.library.slice(0, n);
        S.look = { n: ids.length, from, ids };
        log(`Olhou ${ids.length === 1 ? 'a carta' : `as ${ids.length} cartas`} do ${from === 'bottom' ? 'fundo' : 'topo'} do grimório.`);
      });
      showLook();
    });
  }
  /** Reabre o que estava pela metade (recarregou a página olhando ou buscando). */
  function resumeLook() {
    if (!S?.look) return;
    if (S.look.from === 'search') finishSearch();
    else showLook();
  }
  function showLook() {
    const look = S.look;
    if (!look) return;
    const keep = look.from === 'bottom' ? 'bottom' : 'top';
    const plan = look.ids.filter((iid) => S.cards[iid]?.zone === 'library').map((iid) => ({ iid, to: keep }));
    let dealt = false;
    const where = { top: 'Topo', bottom: 'Fundo', hand: 'Mão', graveyard: 'Cemitério', exile: 'Exílio' };
    const paint = () => {
      const list = modalCard.querySelector('[data-look-list]');
      list.classList.toggle('is-dealt', dealt);
      list.innerHTML = plan.map((p, i) => {
        const inst = S.cards[p.iid];
        return `<li class="pt-look-card is-${p.to}" style="--d:${i * 110}ms">
          <div class="pt-look-flip"><img class="pt-look-front" src="${esc(faceOf(inst).image || cardOf(inst).image || '')}" alt="${esc(nameOf(p.iid))}" width="488" height="680"><span class="pt-look-back" aria-hidden="true"></span><b class="pt-look-to">${where[p.to]}</b></div>
          <strong>${i + 1}. ${esc(nameOf(p.iid))}</strong>
          <span class="pt-chips">${Object.entries(where).map(([key, label]) => `<button type="button" class="pt-chip${p.to === key ? ' is-on' : ''}" data-look-to="${key}" data-i="${i}" aria-pressed="${p.to === key}">${label}</button>`).join('')}
          <button type="button" class="pt-chip" data-look-up="${i}" aria-label="Antes na ordem" ${i === 0 ? 'disabled' : ''}>←</button><button type="button" class="pt-chip" data-look-down="${i}" aria-label="Depois na ordem" ${i === plan.length - 1 ? 'disabled' : ''}>→</button></span>
        </li>`;
      }).join('');
      dealt = true;
    };
    openModal(`<h2>${look.from === 'bottom' ? 'Do fundo' : 'Do topo'} do grimório <span class="pt-modal-count">${plan.length}</span></h2>
      <p class="pt-modal-note">Escolha o destino de cada carta. As que ficam no topo ou no fundo seguem a ordem da esquerda para a direita (a primeira fica por cima).</p>
      <ul class="pt-look-cards" data-look-list></ul>
      <div class="pt-modal-actions"><button type="button" class="pt-btn is-strong" data-look-apply>Concluir</button></div>`, (event) => {
      const to = event.target.closest('[data-look-to]');
      if (to) { plan[Number(to.dataset.i)].to = to.dataset.lookTo; paint(); return; }
      const up = event.target.closest('[data-look-up]'); const down = event.target.closest('[data-look-down]');
      if (up || down) {
        const i = Number(up ? up.dataset.lookUp : down.dataset.lookDown); const j = up ? i - 1 : i + 1;
        [plan[i], plan[j]] = [plan[j], plan[i]]; paint(); return;
      }
      if (event.target.closest('[data-look-apply]')) finishLook(plan);
    }, () => finishLook(null));
    paint();
  }
  function finishLook(plan) {
    const look = S.look;
    if (!look || look.from === 'search') return;
    closeModal();
    const keep = look.from === 'bottom' ? 'bottom' : 'top';
    const chosen = (plan || look.ids.map((iid) => ({ iid, to: keep }))).filter((p) => S.cards[p.iid]?.zone === 'library');
    act('scry', () => {
      const ids = new Set(chosen.map((p) => p.iid));
      S.zones.library = S.zones.library.filter((iid) => !ids.has(iid));
      chosen.filter((p) => p.to === 'top').reverse().forEach((p) => S.zones.library.unshift(p.iid));
      chosen.filter((p) => p.to === 'bottom').forEach((p) => S.zones.library.push(p.iid));
      chosen.filter((p) => !['top', 'bottom'].includes(p.to)).forEach((p) => { S.cards[p.iid].zone = p.to; S.zones[p.to].push(p.iid); if (p.to === 'hand') freshHand.add(p.iid); });
      S.look = null;
      const origin = `${chosen.length === 1 ? 'Da carta' : `Das ${chosen.length} cartas`} do ${look.from === 'bottom' ? 'fundo' : 'topo'}`;
      const count = (key) => chosen.filter((p) => p.to === key).length;
      const names = (key) => chosen.filter((p) => p.to === key).map((p) => nameOf(p.iid));
      const mine = ['top', 'bottom', 'hand', 'graveyard', 'exile'].filter((key) => count(key)).map((key) => `${{ top: 'no topo', bottom: 'no fundo', hand: 'para a mão', graveyard: 'para o cemitério', exile: 'para o exílio' }[key]}: ${names(key).join(', ')}`);
      const all = [
        ...[['top', 'no topo'], ['bottom', 'no fundo'], ['hand', 'para a mão']].filter(([key]) => count(key)).map(([key, label]) => `${count(key)} ${label}`),
        ...[['graveyard', 'para o cemitério'], ['exile', 'para o exílio']].filter(([key]) => count(key)).map(([key, label]) => `${names(key).join(', ')} ${label}`),
      ];
      log(`${origin}: ${mine.join('; ')}.`, plan ? `${origin}: ${all.join(', ')}.` : `${origin}: deixou como estavam.`);
    });
  }

  /* ---------- Força/resistência sem marcadores, dano marcado e habilidades dadas por efeitos ---------- */
  const signed = (n) => `${n >= 0 ? '+' : '−'}${Math.abs(n)}`;
  const modLabel = (mod) => [mod.set ? `vira ${mod.set.p}/${mod.set.t}` : '', mod.p || mod.t ? `${signed(mod.p || 0)}/${signed(mod.t || 0)}` : '', ...(mod.kw || []).map((word) => KEYWORDS[word] || word)].filter(Boolean).join(', ') + (mod.until === 'eot' ? ' até o fim do turno' : '');
  /** Um efeito contínuo na permanente: { p, t } soma; set define a base; kw dá habilidades; until 'eot' acaba na limpeza. */
  function addMod(iid, mod, by = null) {
    const inst = S.cards[iid];
    if (!inst || inst.zone !== 'battlefield') return;
    const clean = { p: Number(mod.p) || 0, t: Number(mod.t) || 0, set: mod.set || null, kw: mod.kw || [], until: mod.until === 'perm' ? 'perm' : 'eot' };
    inst.mods = [...(inst.mods || []), clean];
    log(`${nameOf(iid)}: ${modLabel(clean)}${by ? ` (efeito de ${by})` : ''}.`, by ? null : undefined);
  }
  function openPT(target) {
    closeMenu();
    const all = target === 'all';
    const ids = () => (all ? S.zones.battlefield.filter((iid) => isCreature(S.cards[iid])) : [target]);
    if (all && !ids().length) { toast('Você não tem criaturas no campo.', { kind: 'info' }); return; }
    let until = 'eot';
    const QUICK = [[1, 1], [2, 2], [3, 3], [1, 0], [2, 0], [3, 0], [0, 1], [0, 2], [-1, -1], [-2, -2], [-3, -3], [-1, 0], [-2, 0], [0, -1]];
    const paint = () => {
      const now = modalCard.querySelector('[data-pt-now]');
      if (!now) return;
      const inst = all ? null : S.cards[target];
      if (!all && (!inst || inst.zone !== 'battlefield')) { closeModal(); return; }
      const pt = inst ? ptOf(inst) : null;
      now.textContent = all ? `Vale para as ${ids().length} criatura(s) que você controla agora.`
        : pt ? `Agora: ${pt.text}${inst.damage ? ` · ${inst.damage} de dano marcado` : ''} (impressa: ${faceOf(inst).power ?? '—'}/${faceOf(inst).toughness ?? '—'}).` : 'Ainda não é uma criatura: defina a força e a resistência para animá-la.';
      modalCard.querySelectorAll('[data-until]').forEach((button) => { button.classList.toggle('is-on', button.dataset.until === until); button.setAttribute('aria-pressed', String(button.dataset.until === until)); });
      if (inst) {
        const words = keywordsOf(inst); const fast = hasteSource(inst);
        modalCard.querySelectorAll('[data-kw]').forEach((button) => button.classList.toggle('is-had', words.has(button.dataset.kw) || (button.dataset.kw === 'haste' && !!fast)));
        modalCard.querySelector('[data-pt-mods]').innerHTML = (inst.mods || []).map((mod, i) => `<li><span>${esc(modLabel(mod))}</span><button type="button" class="pt-chip" data-mod-remove="${i}">Tirar</button></li>`).join('') || '<li class="pt-empty">Nenhum efeito agora.</li>';
        modalCard.querySelector('[data-dmg]').textContent = inst.damage || 0;
        const haste = modalCard.querySelector('[data-pt-haste]');
        if (haste) haste.textContent = isCreature(inst) ? (fast ? `Ímpeto: vem de ${fast}.` : isFresh(inst) ? 'Entrou neste turno e não tem ímpeto: enjoo de invocação (302.6).' : 'Sem enjoo de invocação.') : '';
      }
    };
    const applyMod = (mod) => { act('mod', () => ids().forEach((iid) => addMod(iid, { ...mod, until }))); audio.play('counter'); paint(); };
    const first = all ? null : S.cards[target];
    openModal(`<h2>${all ? 'Efeito em todas as suas criaturas' : `Efeitos · ${esc(nameOf(target))}`}</h2>
      <p class="pt-modal-note" data-pt-now></p>${all ? '' : '<p class="pt-modal-note" data-pt-haste></p>'}
      <div class="pt-chips pt-until" role="group" aria-label="Duração"><span>Duração:</span><button type="button" class="pt-chip" data-until="eot">Até o fim do turno</button><button type="button" class="pt-chip" data-until="perm">Enquanto estiver no campo</button></div>
      <h3>Somar à força e à resistência <small class="pt-rule">613.4c</small></h3>
      <div class="pt-chips">${QUICK.map(([p, t]) => `<button type="button" class="pt-chip" data-quick="${p},${t}">${signed(p)}/${signed(t)}</button>`).join('')}</div>
      <form class="pt-pt-form" data-pt-custom><label class="pt-field is-short">Força<input name="p" type="number" value="0" min="-99" max="99"></label><label class="pt-field is-short">Resist.<input name="t" type="number" value="0" min="-99" max="99"></label><button class="pt-btn">Somar</button></form>
      ${all ? '' : `<h3>Definir a base <small class="pt-rule">613.4b</small></h3><form class="pt-pt-form" data-pt-set><label class="pt-field is-short">Força<input name="p" type="number" value="${num(faceOf(first).power) ?? 0}" min="0" max="99"></label><label class="pt-field is-short">Resist.<input name="t" type="number" value="${num(faceOf(first).toughness) ?? 1}" min="0" max="99"></label><button class="pt-btn">Virar uma criatura com essa força/resistência</button></form>`}
      <h3>Dar uma habilidade</h3>
      <div class="pt-chips">${Object.entries(KEYWORDS).map(([word, label]) => `<button type="button" class="pt-chip" data-kw="${esc(word)}">${esc(label)}</button>`).join('')}</div>
      ${all ? '' : `<h3>Dano marcado</h3><div class="pt-damage-mark"><button type="button" class="pt-round" data-dmg-delta="-1" aria-label="Tirar 1 de dano">−</button><output data-dmg>0</output><button type="button" class="pt-round" data-dmg-delta="1" aria-label="Marcar 1 de dano">+</button><small>Dano igual ou maior que a resistência destrói a criatura <span class="pt-rule">704.5g</span>; ele sai na limpeza <span class="pt-rule">514.2</span>.</small></div>
      <h3>Efeitos agora</h3><ul class="pt-mods" data-pt-mods></ul>`}`, (event) => {
      const u = event.target.closest('[data-until]'); if (u) { until = u.dataset.until; paint(); return; }
      const q = event.target.closest('[data-quick]'); if (q) { const [p, t] = q.dataset.quick.split(',').map(Number); applyMod({ p, t }); return; }
      const k = event.target.closest('[data-kw]'); if (k) { applyMod({ kw: [k.dataset.kw] }); return; }
      const r = event.target.closest('[data-mod-remove]');
      if (r) { act('mod', () => { const inst = S.cards[target]; const [gone] = inst.mods.splice(Number(r.dataset.modRemove), 1); if (gone) log(`${nameOf(target)}: acabou o efeito ${modLabel(gone)}.`); }); paint(); return; }
      const d = event.target.closest('[data-dmg-delta]');
      if (d) { const delta = Number(d.dataset.dmgDelta); act('mod', () => { const inst = S.cards[target]; inst.damage = Math.max(0, (inst.damage || 0) + delta); log(`${nameOf(target)}: ${inst.damage} de dano marcado.`); }); audio.play(delta > 0 ? 'damage' : 'tap'); paint(); }
    });
    modalCard.querySelector('[data-pt-custom]').addEventListener('submit', (event) => { event.preventDefault(); const p = Number(event.target.elements.p.value) || 0; const t = Number(event.target.elements.t.value) || 0; if (p || t) applyMod({ p, t }); });
    modalCard.querySelector('[data-pt-set]')?.addEventListener('submit', (event) => { event.preventDefault(); applyMod({ set: { p: Math.max(0, Number(event.target.elements.p.value) || 0), t: Math.max(0, Number(event.target.elements.t.value) || 0) } }); });
    paint();
  }

  function openCounters(iid) {
    closeMenu();
    const inst = S.cards[iid];
    history.push({ label: 'counter', state: JSON.stringify(S) });
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
      audio.play('dice');
      out.classList.remove('is-rolling'); void out.offsetWidth; out.classList.add('is-rolling');
      out.textContent = result;
      const text = roll === 'coin' ? `Moeda: ${result}.` : `d${roll}: ${result}.`;
      S.log.unshift({ turn: S.turn, text });
      if (MP) net.event('dice', { text }).catch(() => {});
      else { log(text, undefined, 'dice'); renderLog(); save(); }
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
    const cardWidth = Math.min(134, Math.max(92, window.innerWidth * 0.082));
    const free = Math.max(260, root.clientWidth - 2 * (($('[data-pt-log-wrap]')?.offsetWidth || 0) + 28));
    const squeeze = n > 1 ? Math.ceil((n * cardWidth - free) / (n - 1)) : 0;
    handEl.style.setProperty('--overlap', `${Math.min(cardWidth - 26, Math.max(n <= 6 ? 14 : Math.min(92, 14 + (n - 6) * 9), squeeze))}px`);
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
    if (S.discard > 0) { discardAtCleanup(el.dataset.hand); return; }
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
  let lastHover = null;
  function hoverTarget(target) {
    lastHover = target;
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
  /** Limpeza com mais de 7 cartas: cada clique na mão descarta uma; acabando, o turno passa (514.1). */
  function discardAtCleanup(iid) {
    act('move', () => { move(iid, 'graveyard'); S.discard = Math.max(0, S.discard - 1); log(`Descartou ${nameOf(iid)} na limpeza.`); });
    if (!S.discard) finishTurn();
  }
  function toast(text, { rule = null, kind = 'rule', who = null } = {}) {
    const el = document.createElement('div');
    el.className = `pt-toast is-${kind}`;
    if (who !== null) el.style.borderLeftColor = SEAT_COLORS[who % 4];
    el.innerHTML = `<p>${esc(text)}</p>${rule ? `<small class="pt-rule" title="Regra das Comprehensive Rules">${esc(rule)}</small>` : ''}`;
    toasts.prepend(el);
    while (toasts.children.length > 4) toasts.lastChild.remove();
    setTimeout(() => { el.classList.add('is-out'); setTimeout(() => el.remove(), 400); }, kind === 'win' || kind === 'trigger' ? 7500 : 5200);
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

  /* ---------- Aviso de etapa: fase e etapa no centro da mesa, com o que acontece nela e os gatilhos ---------- */
  const bannerEl = document.createElement('div');
  bannerEl.className = 'pt-banner';
  bannerEl.setAttribute('aria-live', 'polite');
  bannerEl.hidden = true;
  root.appendChild(bannerEl);
  const bannerQueue = [];
  let bannerBusy = false;
  let bannerPhase = -1;
  function banner(i, { triggers = [], seat = null } = {}) {
    if (!STEPS[i]) return;
    bannerQueue.push({ i, triggers, seat });
    if (bannerQueue.length > 5) bannerQueue.splice(0, bannerQueue.length - 5);
    if (!bannerBusy) setTimeout(nextBanner, 0);
    bannerBusy = true;
  }
  function nextBanner() {
    const item = bannerQueue.shift();
    if (!item) { bannerBusy = false; bannerEl.classList.remove('is-in'); setTimeout(() => { if (!bannerBusy) bannerEl.hidden = true; }, 380); return; }
    bannerBusy = true;
    const step = STEPS[item.i]; const phase = PHASE_INFO[step.phase];
    const mine = item.seat === null || item.seat === ME;
    const newPhase = step.phase !== bannerPhase || item.i === 0;
    bannerPhase = step.phase;
    const single = step.phase === 1 || step.phase === 3;
    bannerEl.classList.toggle('is-other', !mine);
    bannerEl.style.setProperty('--seat', mine ? '#e3c06a' : SEAT_COLORS[item.seat % 4]);
    bannerEl.innerHTML = `<small>${mine ? (MP ? 'Seu turno' : `Turno ${S.turn}`) : `Vez de ${esc(seatName(item.seat))}`} · ${esc(single ? 'Fase' : phase.name)} <span class="pt-rule">${esc(step.rule)}</span></small>
      <strong>${esc(single ? phase.name : step.full)}</strong><span>${esc(mine ? step.hint : '')}</span>${item.triggers.length ? `<em>Gatilhos: ${esc(item.triggers.join(', '))}</em>` : ''}
      <i class="pt-banner-pips" aria-hidden="true">${STEPS.map((s, k) => `<b class="${k < item.i ? 'is-done' : k === item.i ? 'is-now' : ''}${s.phase !== (STEPS[k - 1]?.phase ?? s.phase) ? ' is-gap' : ''}"></b>`).join('')}</i>`;
    bannerEl.hidden = false;
    bannerEl.classList.remove('is-in'); void bannerEl.offsetWidth; bannerEl.classList.add('is-in');
    audio.play(newPhase ? 'phase' : 'step', { index: newPhase ? step.phase : item.i, quiet: !mine });
    setTimeout(nextBanner, bannerQueue.length ? 700 : item.triggers.length ? 2800 : mine ? 1500 : 1100);
  }

  /** Barra das etapas: as cinco fases; as de várias etapas mostram um ponto por etapa e o nome da atual. */
  function stepsHtml(shown, active, clickable) {
    return PHASE_INFO.map((phase, p) => {
      const steps = STEPS.map((step, i) => ({ step, i })).filter(({ step }) => step.phase === p);
      const now = active && STEPS[shown]?.phase === p;
      const done = active && STEPS[shown] && STEPS[shown].phase > p;
      const button = ({ step, i }, main = false) => `<button type="button" class="pt-step${main ? ' is-main' : ''}${active && i === shown ? ' is-now' : ''}${active && i < shown ? ' is-done' : ''}" data-step="${i}" title="${esc(step.full)} — ${esc(step.hint)} (${step.rule})"${clickable ? '' : ' tabindex="-1"'}><span${main ? '' : ' class="pt-step-name"'}>${esc(main ? phase.short : step.name)}</span></button>`;
      return `<li class="pt-ph${now ? ' is-now' : ''}${done ? ' is-done' : ''}">${steps.length > 1 ? `<span class="pt-ph-name">${esc(phase.short)}</span><span class="pt-ph-steps">${steps.map((s) => button(s)).join('')}</span>` : button(steps[0], true)}</li>`;
    }).join('');
  }
  const turnTitle = () => (T.turn === ME ? `Seu turno · ${stepTitle(S.phase)}` : `Vez de ${seatName(T.turn)} · ${stepTitle(T.phase)}`);
  function renderHud() {
    const playing = S.stage === 'play';
    const shownPhase = MP && !myTurn() ? T.phase : S.phase;
    if (MP) {
      $('[data-pt-turn]').textContent = T.status !== 'playing' ? 'Mesa aberta' : !playing ? 'Mão inicial' : T.turn === ME ? `Seu turno · ${S.turn}º` : `Vez de ${seatName(T.turn)}`;
      $('[data-pt-turn]').title = T.status === 'playing' ? `Turno ${T.number} da mesa` : '';
      $('[data-pt-action="turn"]').disabled = !playing || !myTurn();
      $('[data-pt-action="phase"]').disabled = !playing || !myTurn();
    } else {
      $('[data-pt-turn]').textContent = playing ? `Turno ${S.turn}` : 'Mão inicial';
    }
    const stepsActive = playing && (!MP || T.status === 'playing');
    const stepsEl = $('[data-pt-phases]');
    stepsEl.innerHTML = stepsHtml(shownPhase, stepsActive, stepsActive && myTurn());
    stepsEl.classList.toggle('is-theirs', MP && !myTurn());
    $('[data-pt-action="phase"]').textContent = playing && myTurn() && S.phase >= STEP.cleanup ? 'Terminar o turno' : 'Próxima etapa';
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
    // Mulligan (ou, na limpeza, o descarte até 7).
    if (playing && S.discard > 0) {
      mulliganBar.hidden = false;
      mulliganBar.innerHTML = `<strong>Limpeza: descarte ${S.discard} carta${S.discard > 1 ? 's' : ''}</strong><span>Você está com ${S.zones.hand.length}; o máximo é 7. Clique nas cartas da mão para descartar. <small class="pt-rule">514.1</small></span><button type="button" class="pt-btn" data-pt-action="no-max-hand" title="Para efeitos como Reliquary Tower">Sem limite de mão</button>`;
    } else if (playing || (MP && T.status !== 'playing')) mulliganBar.hidden = true;
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
    // Combate: no seu turno, atacantes, bloqueios e dano; no dos outros, quem ataca você e os seus bloqueios.
    const list = attackers();
    const myCombat = playing && myTurn() && S.phase >= STEP.attackers && S.phase <= STEP.damage;
    const incoming = playing && MP && T.status === 'playing' && !myTurn() && T.phase >= STEP.attackers && T.phase <= STEP.damage ? incomingAttackers() : [];
    combatBar.hidden = !(myCombat || incoming.length);
    combatBar.classList.toggle('is-defending', !myCombat && incoming.length > 0);
    if (myCombat) {
      const total = list.reduce((sum, inst) => sum + combatDamageOf(inst), 0);
      const stepName = STEPS[S.phase].full;
      const forward = S.phase === STEP.attackers && list.length ? `<button type="button" class="pt-btn" data-pt-action="phase">${MP ? 'Esperar bloqueios →' : 'Bloqueios →'}</button>` : '';
      const blockers = MP ? opponents().flatMap((seat) => (seatState.get(seat)?.battlefield || []).filter((entry) => entry.bl === ME).map((entry) => `${seatFace(seat, entry).name} (${seatName(seat)})`)) : [];
      const blockNote = S.phase >= STEP.blockers ? (MP ? (blockers.length ? `Bloqueando: ${blockers.join(', ')}` : 'Ninguém marcou bloqueio ainda.') : 'O oponente imaginário não bloqueia.') : '';
      if (MP) {
        const alive = livingOpponents();
        const target = attackTargetFor();
        const picker = S.phase === STEP.attackers ? (alive.length > 1 ? `<label class="pt-combat-target">Atacar <select data-attack-target>${alive.map((seat) => `<option value="${seat}"${seat === target ? ' selected' : ''}>${esc(seatName(seat))}</option>`).join('')}</select></label>` : `<span>Contra ${esc(seatName(target))}</span>`) : '';
        combatBar.innerHTML = `<strong>${esc(stepName)}</strong>${picker}<span>${list.length ? `${list.length} atacante${list.length > 1 ? 's' : ''} · <b>${total}</b> de dano` : 'Clique nas criaturas para declarar ataque.'}${blockNote ? ` · ${esc(blockNote)}` : ''}</span>${forward}<button type="button" class="pt-btn is-strong" data-pt-action="damage" ${list.length ? '' : 'disabled'}>Causar dano…</button>`;
      } else {
        combatBar.innerHTML = `<strong>${esc(stepName)}</strong><span>${list.length ? `${list.length} atacante${list.length > 1 ? 's' : ''} · <b>${total}</b> de dano` : 'Clique nas criaturas para declarar ataque.'}${blockNote ? ` · ${esc(blockNote)}` : ''}</span>${forward}<button type="button" class="pt-btn is-strong" data-pt-action="damage" ${list.length ? '' : 'disabled'}>Causar ${total} ao oponente</button>`;
      }
    } else if (incoming.length) {
      const power = incoming.reduce((sum, entry) => sum + (parseInt(entry.pt, 10) || 0), 0);
      const mine = S.zones.battlefield.filter((iid) => S.cards[iid].blocking !== null && S.cards[iid].blocking !== undefined).map(nameOf);
      combatBar.innerHTML = `<strong>${esc(seatName(T.turn))} ataca você</strong><span>${incoming.length} criatura${incoming.length > 1 ? 's' : ''} · <b>${power}</b> de força. ${blockWindow() ? 'Clique nas suas criaturas desviradas para bloquear.' : ''} ${mine.length ? `Bloqueando com: ${esc(mine.join(', '))}.` : ''} <small class="pt-rule">509</small></span>`;
    }
  }
  combatBar.addEventListener('change', (event) => { if (event.target.matches('[data-attack-target]')) defaultTarget = Number(event.target.value); });

  const LOG_ICONS = { draw: '🃏', play: '✨', move: '➜', tap: '🔄', combat: '⚔️', life: '❤️', token: '🪙', counter: '🔢', flip: '🔃', hand: '✋', shuffle: '🔀',
    look: '👁️', search: '🔍', reveal: '👀', turn: '⏭️', phase: '▸', concede: '🏳️', undo: '↩️', dice: '🎲', chat: '💬', damage: '💥', poison: '☠️', join: '🚪', leave: '🚪',
    start: '🏁', lobby: '🏁', attack: '⚔️', info: '•' };
  // Ações com carta escondida (olhar, buscar, desfazer) e revelações ficam destacadas para ninguém deixar passar.
  const LOG_ALERT = new Set(['look', 'search', 'undo', 'reveal']);
  function logRow(cat, who, color, text, at) {
    return `<li class="is-${cat}${LOG_ALERT.has(cat) ? ' is-alert' : ''}"><span class="pt-log-icon" aria-hidden="true">${LOG_ICONS[cat] || '•'}</span><span class="pt-log-text">${who ? `<b style="color:${color}">${esc(who)}</b> ` : ''}${esc(text)}</span>${at ? `<small>${esc(at)}</small>` : ''}</li>`;
  }
  function renderLog() {
    const logList = $('[data-pt-log]');
    const atBottom = logList.scrollHeight - logList.scrollTop - logList.clientHeight < 40;
    if (MP) {
      logList.innerHTML = feed.slice(-160).map((entry) => {
        if (entry.kind === 'turn') return `<li class="pt-log-turn"><span>Turno ${esc(entry.payload?.number ?? '')} · ${esc(seatName(entry.payload?.seat))}</span></li>`;
        if (entry.kind === 'start' && entry.payload?.game) return `<li class="pt-log-turn is-start"><span>Partida ${esc(entry.payload.game)} · ${esc(seatName(entry.payload.starting))} começa</span></li>`;
        const cat = entry.cat || (entry.kind === 'log' ? 'info' : entry.kind);
        const who = entry.seat !== null && entry.seat !== undefined ? seatName(entry.seat) : '';
        return logRow(cat, who, entry.seat !== null && entry.seat !== undefined ? SEAT_COLORS[entry.seat % 4] : '', entry.text, entry.at);
      }).join('') || '<li class="pt-log-empty">Tudo o que cada jogador fizer aparece aqui, para todos.</li>';
    } else {
      logList.innerHTML = S.log.slice(0, 120).reverse().map((entry) => (/^— .* —$/.test(entry.text)
        ? `<li class="pt-log-turn"><span>${esc(entry.text.replace(/^— | —$/g, ''))}</span></li>`
        : logRow(entry.cat || 'info', '', '', entry.text, `T${entry.turn}`))).join('') || '<li class="pt-log-empty">O que acontecer na partida aparece aqui.</li>';
    }
    if (atBottom) logList.scrollTop = logList.scrollHeight;
  }

  function sceneView() {
    return {
      look: S.look ? { n: S.look.n, from: S.look.from } : null,
      active: MP ? T.status === 'playing' && T.turn === ME : false,
      battlefield: S.zones.battlefield.map((iid) => {
        const inst = S.cards[iid]; const face = faceOf(inst); const pt = ptOf(inst);
        return {
          iid, x: inst.x, z: inst.z, tapped: inst.tapped, attacking: inst.attacking, token: inst.token, commander: inst.commander,
          image: imageOf(inst), fallback: { name: face.name, type: typeOf(inst), pt: pt?.text }, fallbackKey: `${face.name}|${pt?.text || ''}`,
          counters: Object.entries(inst.counters).filter(([, n]) => n > 0).map(([name, n]) => ({ name, n, short: name.length > 8 ? name.slice(0, 7) + '.' : name })),
          pt, sick: isSick(inst), haste: isFresh(inst) && !!hasteSource(inst), damage: inst.damage || 0, blocking: inst.blocking !== null && inst.blocking !== undefined,
          stack: inst.stack || 1, buried: !!inst.buried,
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
    grantMemo = null;
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
      if (saved && saved.v === 3 && saved.sig === signature) return saved;
    } catch { /* partida salva ilegível: começa outra */ }
    return null;
  }

  /* ---------- Câmera: aproximar, afastar, vista de cima, centralizar ---------- */
  const cameraBar = $('[data-pt-camera]');
  function setTopDown(on) {
    scene.setTopDown(on);
    cameraBar?.querySelector('[data-pt-cam="top"]')?.setAttribute('aria-pressed', String(on));
    try { localStorage.setItem('deckarium-playtest-topdown', on ? '1' : '0'); } catch { /* sem armazenamento: só não lembra */ }
  }
  cameraBar?.addEventListener('click', (event) => {
    const button = event.target.closest('[data-pt-cam]');
    if (!button) return;
    ({ in: () => scene.zoomBy(0.75), out: () => scene.zoomBy(1 / 0.75), top: () => setTopDown(!scene.isTopDown()), reset: () => scene.resetCamera(),
      scenic: () => scene.setScenic(!scene.isScenic()) }[button.dataset.ptCam] || (() => {}))();
    cameraBar.querySelector('[data-pt-cam="scenic"]')?.setAttribute('aria-pressed', String(scene.isScenic()));
  });
  try { if (localStorage.getItem('deckarium-playtest-topdown') === '1') setTopDown(true); } catch { /* idem */ }

  /* ---------- Botões e teclado ---------- */
  root.addEventListener('click', (event) => {
    // Etapa na barra do turno: vai até ela (só no seu turno; para trás, só Desfazer).
    const stepButton = event.target.closest('[data-step]');
    if (stepButton) { goToStep(Number(stepButton.dataset.step)); return; }
    const button = event.target.closest('[data-pt-action]');
    if (button) {
      const action = button.dataset.ptAction;
      const needPlay = ['draw', 'untap', 'turn', 'phase', 'mill', 'token', 'scry', 'search', 'shuffle'].includes(action);
      if (needPlay && S.stage !== 'play') { toast(MP && T.status !== 'playing' ? 'A partida ainda não começou.' : 'Primeiro, decida a mão inicial: manter ou mulligan.', { kind: 'info' }); return; }
      ({
        draw: () => act('draw', () => drawCards(1)),
        untap: () => act('untap', () => untapAll()),
        turn: nextTurn,
        phase: nextStep,
        settings: openSettings,
        scenic: () => { scene.setScenic(!scene.isScenic()); $('[data-pt-cam="scenic"]')?.setAttribute('aria-pressed', String(scene.isScenic())); },
        'no-max-hand': () => { act('phase', () => { S.discard = 0; log('Limpeza: sem tamanho máximo de mão neste turno.'); }); finishTurn(); },
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
        log: () => { const wide = $('[data-pt-log-wrap]').classList.toggle('is-expanded'); button.setAttribute('aria-expanded', String(wide)); button.textContent = wide ? 'Reduzir' : 'Ampliar'; const list = $('[data-pt-log]'); list.scrollTop = list.scrollHeight; },
      }[action] || (() => {}))();
      return;
    }
    const adjust = event.target.closest('[data-pt-adjust]');
    if (adjust) {
      const [key, delta] = adjust.dataset.ptAdjust.split(':');
      const step = Number(delta) * (event.shiftKey ? 5 : 1);
      act('adjust', () => { S[key] = Math.max(key === 'poison' ? 0 : -999, S[key] + step); });
      queueAdjustLog(key, step);
      floatText(`${step > 0 ? '+' : ''}${step}`, adjust.closest('.pt-meter'));
    }
  });

  // Na mesa compartilhada não voltam: o que mostrou cartas escondidas (comprar, olhar, buscar, embaralhar, mulligan, moer)
  // e o que é da mesa (vez, fase, dano recebido, concessão).
  const NO_UNDO_MP = new Set(['draw', 'look', 'scry', 'search', 'zone', 'shuffle', 'mulligan', 'keep', 'bottom', 'mill', 'exile-top', 'reveal', 'turn', 'phase', 'incoming', 'concede', 'interact', 'cleanup']);
  const UNDO_TEXT = { play: 'a carta jogada', drop: 'a carta posta no campo', commander: 'o lançamento da zona de comando', 'commander-zone': 'a ida para a zona de comando', position: 'a mudança de lugar de uma carta',
    move: 'o movimento de uma carta', tap: 'virar/desvirar uma carta', untap: 'desvirar tudo', attack: 'um ataque', damage: 'o dano de combate', adjust: 'a mudança de vida', token: 'as fichas criadas',
    copy: 'a cópia criada', counter: 'os marcadores', flip: 'a transformação', face: 'virar a face', draw: 'a compra', shuffle: 'o embaralhamento', mill: 'a moagem', turn: 'o turno', phase: 'a fase' };
  const adjustLog = {};
  function queueAdjustLog(key, step) {
    const entry = adjustLog[key] ||= { sum: 0, timer: 0 };
    entry.sum += step;
    clearTimeout(entry.timer);
    entry.timer = setTimeout(() => {
      const sum = entry.sum; entry.sum = 0;
      if (!sum) return;
      log(`${{ life: 'Vida', poison: 'Veneno', opponent: 'Oponente' }[key]}: ${sum > 0 ? '+' : ''}${sum} (${S[key]}).`, undefined, key === 'poison' ? 'poison' : 'life');
      renderLog(); save(); publish();
    }, 900);
  }
  function undo() {
    const entry = history[history.length - 1];
    if (!entry) { toast('Nada para desfazer.', { kind: 'info' }); return; }
    if (MP && NO_UNDO_MP.has(entry.label)) {
      toast('Na mesa compartilhada isso não volta: você já viu cartas escondidas ou a ação é da mesa (compra, olhar, busca, embaralhar, mulligan, vez, dano recebido).', { kind: 'warn' });
      return;
    }
    history.pop();
    S = JSON.parse(entry.state);
    closeMenu(); closeModal();
    log(`Desfez ${UNDO_TEXT[entry.label] || 'a última ação'}.`, undefined, 'undo');
    render(); save();
    if (S.look) resumeLook();
    toast(MP ? 'Ação desfeita — fica no registro da mesa.' : 'Ação desfeita.', { kind: 'info' });
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
    // Esc fecha a janela mesmo com o cursor num campo (filtro da busca, quantidade do olhar).
    if (event.key === 'Escape') { if (!modal.hidden) { const onClose = modal.onClose; closeModal(); onClose?.(); } else if (!menu.hidden) closeMenu(); else scene.resetCamera(); return; }
    if (target.closest('input, textarea, select')) return;
    if (!modal.hidden) return;
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'z') { event.preventDefault(); undo(); return; }
    if (event.ctrlKey || event.metaKey || event.altKey) return;
    const key = event.key.toLowerCase();
    if (MP && key === 'v') { event.preventDefault(); cycleView(); return; }
    if (key === 'c') { event.preventDefault(); setTopDown(!scene.isTopDown()); return; }
    // Z: leve zoom na carta sob o mouse (sua ou de um oponente).
    if (key === 'z' && lastHover?.iid) { event.preventDefault(); scene.focusCard(lastHover); return; }
    if (hovered && S.cards[hovered] && S.cards[hovered].zone === 'battlefield') {
      const iid = hovered;
      const cardKeys = { p: () => openPT(iid), i: () => (isFresh(S.cards[iid]) && !hasteSource(S.cards[iid]) ? act('mod', () => addMod(iid, { kw: ['haste'], until: 'eot' })) : null), b: () => (blockWindow() ? toggleBlock(iid) : null), t: () => toggleTap(iid), a: () => (isCreature(S.cards[iid]) ? toggleAttack(iid) : null), g: () => act('move', () => move(iid, 'graveyard')), e: () => act('move', () => move(iid, 'exile')), h: () => act('move', () => move(iid, 'hand')), c: () => openCounters(iid), '+': () => act('counter', () => addCounter(iid, '+1/+1', 1)), '=': () => act('counter', () => addCounter(iid, '+1/+1', 1)), '-': () => act('counter', () => addCounter(iid, (S.cards[iid].counters['+1/+1'] || 0) ? '+1/+1' : '-1/-1', (S.cards[iid].counters['+1/+1'] || 0) ? -1 : 1)) };
      if (cardKeys[key]) { event.preventDefault(); cardKeys[key](); return; }
    }
    if (S.stage !== 'play') return;
    const keys = { d: () => act('draw', () => drawCards(1)), u: () => act('untap', () => untapAll()), n: endTurn, ' ': nextStep, s: shuffleLibrary, x: openScry, f: () => openZone('library'), m: () => mill(1), k: openTokens, r: revealTopOnce };
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

  /* ---------- Sua mesa: paisagem, madeira, shield, verso e sons ---------- */
  let prefsTimer = 0;
  function savePrefs() {
    clearTimeout(prefsTimer);
    prefsTimer = setTimeout(() => {
      const body = new FormData(); body.set('csrf', data.csrf); body.set('action', 'prefs'); body.set('prefs', JSON.stringify(PREFS));
      fetch('/playtest_table.php', { method: 'POST', body, credentials: 'same-origin' }).catch(() => {});
    }, 500);
  }
  function applyPrefs() {
    scene.setEnvironment(envOf());
    audio.setAmbient(envOf());
    scene.setWood(PREFS.wood);
    scene.setStyle(null, myStyle());
    setTimeout(() => root.style.setProperty('--pt-back', `url("${scene.backImage()}")`), 400);
    if (MP) publish();
    savePrefs();
  }
  function openSettings() {
    closeMenu();
    if (!worldKit) { toast('As opções da mesa não carregaram.', { kind: 'warn' }); return; }
    const { ENVIRONMENTS, SLEEVES, BACKS, WOODS, cardBackDataUrl } = worldKit;
    const auto = worldKit.environmentForColors(data.colorCounts);
    const backs = Object.entries(BACKS).filter(([key]) => key !== 'arte' || data.backArt);
    const paint = () => {
      const s = audio.settings();
      modalCard.querySelector('[data-set-body]').innerHTML = `
        <h3>Paisagem em volta da mesa</h3>
        <div class="pt-pick-grid is-env">${[['auto', `Pelas cores do deck (${ENVIRONMENTS[auto].name})`], ...Object.entries(ENVIRONMENTS).map(([key, env]) => [key, env.name])].map(([key, label]) => `<button type="button" class="pt-pick${PREFS.environment === key ? ' is-on' : ''}" data-set-env="${key}" aria-pressed="${PREFS.environment === key}"><i class="pt-env-swatch is-${key === 'auto' ? auto : key}"></i><span>${esc(label)}</span>${key !== 'auto' ? `<small>${esc(ENVIRONMENTS[key].blurb)}</small>` : ''}</button>`).join('')}</div>
        <h3>Madeira da mesa</h3>
        <div class="pt-pick-grid">${Object.entries(WOODS).map(([key, wood]) => `<button type="button" class="pt-pick${PREFS.wood === key ? ' is-on' : ''}" data-set-wood="${key}" aria-pressed="${PREFS.wood === key}"><i class="pt-swatch" style="background:linear-gradient(135deg,${wood.light},${wood.dark})"></i><span>${esc(wood.name)}</span></button>`).join('')}</div>
        <h3>Shield (capa das cartas)</h3>
        <div class="pt-pick-grid is-sleeves">${Object.entries(SLEEVES).map(([key, sleeve]) => `<button type="button" class="pt-pick${PREFS.sleeve === key ? ' is-on' : ''}" data-set-sleeve="${key}" aria-pressed="${PREFS.sleeve === key}"><i class="pt-swatch" style="background:${sleeve.color};box-shadow:inset 0 0 0 3px ${sleeve.accent}"></i><span>${esc(sleeve.name)}</span></button>`).join('')}</div>
        <h3>Verso</h3>
        <div class="pt-pick-grid is-backs">${backs.map(([key, back]) => `<button type="button" class="pt-pick is-back${PREFS.back === key ? ' is-on' : ''}" data-set-back="${key}" aria-pressed="${PREFS.back === key}">${key === 'arte' ? `<span class="pt-back-art" style="border-color:${SLEEVES[PREFS.sleeve]?.color || '#1d5a43'}"><img src="${esc(data.backArt)}" alt=""></span>` : `<img src="${cardBackDataUrl({ sleeve: PREFS.sleeve, back: key }, null, 120)}" alt="" width="120" height="167">`}<span>${esc(back.name)}</span></button>`).join('')}</div>
        <h3>Sons</h3>
        <div class="pt-sound-grid">
          <label class="pt-check"><input type="checkbox" data-set-sfx ${s.sfx ? 'checked' : ''}> Efeitos: cartas, etapas, combate</label>
          <label class="pt-field is-inline">Volume<input type="range" min="0" max="1" step="0.05" value="${s.volume}" data-set-volume></label>
          <label class="pt-check"><input type="checkbox" data-set-ambient ${s.ambient ? 'checked' : ''}> Som ambiente da paisagem</label>
          <label class="pt-field is-inline">Volume<input type="range" min="0" max="1" step="0.05" value="${s.ambientVolume}" data-set-ambient-volume></label>
        </div>`;
    };
    openModal(`<h2>Sua mesa</h2><p class="pt-modal-note">Fica guardado na sua conta e vale para todas as partidas. Na mesa compartilhada, os outros jogadores veem o seu shield e o seu verso.</p>
      <div data-set-body></div>
      <div class="pt-modal-actions"><button type="button" class="pt-btn" data-set-scenic>Ver a paisagem</button><button type="button" class="pt-btn is-strong" data-modal-close>Pronto</button></div>`, (event) => {
      const pick = (attr) => event.target.closest(`[${attr}]`)?.getAttribute(attr);
      const env = pick('data-set-env'); const wood = pick('data-set-wood'); const sleeve = pick('data-set-sleeve'); const back = pick('data-set-back');
      if (env) PREFS.environment = env; else if (wood) PREFS.wood = wood; else if (sleeve) PREFS.sleeve = sleeve; else if (back) PREFS.back = back;
      if (env || wood || sleeve || back) { applyPrefs(); paint(); audio.play(sleeve || back ? 'card' : 'tap'); return; }
      if (event.target.closest('[data-set-scenic]')) { closeModal(); scene.setScenic(true); $('[data-pt-cam="scenic"]')?.setAttribute('aria-pressed', 'true'); }
    });
    modalCard.oninput = (event) => {
      const t = event.target;
      if (t.matches('[data-set-sfx]')) audio.set({ sfx: t.checked });
      else if (t.matches('[data-set-volume]')) audio.set({ volume: Number(t.value) });
      else if (t.matches('[data-set-ambient]')) audio.set({ ambient: t.checked });
      else if (t.matches('[data-set-ambient-volume]')) audio.set({ ambientVolume: Number(t.value) });
      else return;
      if (t.matches('[data-set-sfx], [data-set-volume]')) audio.play('card');
    };
    paint();
  }

  /* ========== Mesa compartilhada ========== */
  let net = null;
  const lobby = $('[data-pt-lobby]');
  const opponentsEl = $('[data-pt-opponents]');
  let lastTurnKey = '';
  let resumeState = null;

  /** Cartas no formato curto que vai para a mesa (só o que o preview e a mesa 3D usam). */
  const defOf = (card) => ({ n: card.name, t: card.type_line || '', i: card.image || null, h: card.thumb || null, p: card.power ?? null, o: card.toughness ?? null, l: card.loyalty ?? null, c: card.colors || ['C'],
    x: String(card.text || '').slice(0, 700), kw: (card.keywords || []).slice(0, 12), b: card.back ? { n: card.back.name, t: card.back.type_line, i: card.back.image, x: String(card.back.text || '').slice(0, 500) } : null });
  /** Carta que chegou de outro jogador (def curta) no formato das cartas da mesa. */
  const cardFromDef = (def) => ({ name: def.n, type_line: def.t, image: def.i, thumb: def.h, power: def.p, toughness: def.o, loyalty: def.l, colors: def.c || ['C'], text: def.x || '',
    keywords: Array.isArray(def.kw) ? def.kw.filter((word) => typeof word === 'string').map((word) => word.toLowerCase()) : [], back: def.b ? { name: def.b.n, type_line: def.b.t, image: def.b.i, text: def.b.x || '' } : null, url: null });
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
          pt: pt ? pt.text : null, ps: pt ? pt.state : null, s: isSick(inst) ? 1 : 0, hs: isFresh(inst) && hasteSource(inst) ? 1 : 0, dm: inst.damage || 0,
          bl: inst.blocking ?? null, md: (inst.mods || []).map(modLabel), bo: inst.borrowed ? inst.borrowed.seat : null };
      }),
      graveyard: S.zones.graveyard.map((iid) => ({ i: iid, k: keyOf(S.cards[iid]) })),
      exile: S.zones.exile.map((iid) => ({ i: iid, k: keyOf(S.cards[iid]) })),
      style: myStyle(),
      peek: S.peek || null,
      command: S.zones.command.map((iid) => ({ k: keyOf(S.cards[iid]), tax: (S.cards[iid].casts || 0) * 2 })),
      reveal: topRevealed() ? keyOf(S.cards[S.zones.library[0]]) : null,
      look: S.look ? { n: S.look.n, from: S.look.from } : null,
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
  /** Shield e verso de outro jogador: só opções conhecidas e arte do próprio site. */
  const cleanStyle = (style) => (style && typeof style === 'object' && worldKit ? { sleeve: worldKit.SLEEVES[style.sleeve] ? style.sleeve : 'esmeralda', back: worldKit.BACKS[style.back] ? style.back : 'deckarium', art: localImage(style.art) } : null);
  /** Entradas do cemitério e do exílio: { i, k } (ou só a chave, em estados antigos). */
  const zoneEntry = (entry) => (entry && typeof entry === 'object' ? entry : { i: null, k: entry });
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
      const flags = [entry.tk ? 'Ficha' : '', entry.cm ? 'Zona de comando' : '', entry.t ? 'Virada' : '', entry.a ? `Atacando${entry.at !== null && entry.at !== undefined ? ` ${seatName(entry.at)}` : ''}` : '',
        entry.bl !== null && entry.bl !== undefined ? `Bloqueando ${seatName(entry.bl)}` : '', entry.s ? 'Enjoo de invocação' : entry.hs ? 'Ímpeto' : '', entry.dm ? `${entry.dm} de dano` : '',
        entry.bo !== null && entry.bo !== undefined ? `Carta de ${seatName(entry.bo)}` : ''].filter(Boolean);
      renderPreview({ face, pt: entry.pt ? { text: entry.pt, state: entry.ps } : null, state: flags, counters: [...counters, ...(Array.isArray(entry.md) ? entry.md.slice(0, 6).map(String) : [])], owner });
      return;
    }
    const zoneKey = target.zone === 'command' ? state.command[target.slot || 0]?.k : target.zone === 'library' ? state.reveal : zoneEntry((state[target.zone] || []).slice(-1)[0]).k;
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
          pt: entry.pt ? { text: entry.pt, state: entry.ps || 'normal' } : null, sick: !!entry.s, haste: !!entry.hs, damage: Number(entry.dm) || 0, blocking: entry.bl !== null && entry.bl !== undefined,
          stack: entry.st || 1, buried: !!entry.b, colors: face.colors,
          targetLabel: entry.a && entry.at !== null && entry.at !== undefined ? seatName(entry.at) : null };
      });
      const topOf = (zone) => { const k = zoneEntry((state?.[zone] || []).slice(-1)[0]).k; const def = defFor(seat, k); return def?.i || (state?.[zone]?.length ? 'back' : null); };
      return {
        seat, index, count: list.length, name: info.player || `Jogador ${seat + 1}`, life: state ? state.life : '—', poison: state?.poison || 0,
        active: T.status === 'playing' && T.turn === seat, eliminated: !!info.eliminated, online: info.online, stage: state?.stage || (T.status === 'playing' ? 'mulligan' : 'play'),
        playmat: info.commander?.image || null, hand: state?.hand || 0, battlefield: bf, look: state?.look || (state?.peek ? { n: state.peek.n, from: state.peek.from } : null), style: cleanStyle(state?.style),
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
    if (target.zone === 'library') { const r = root.getBoundingClientRect(); openSeatLibraryMenu(seat, lastPointer.x || r.left + r.width / 2, lastPointer.y || r.top + r.height / 2); return; }
    // Carta ou tapete de um oponente: a câmera fica parada (Z aproxima a carta; o painel troca a vista).
    if (target.iid || target.mat) return;
  }

  function openSeatMenu(seat, x, y, target = null) {
    if (target?.iid) { openSeatCardMenu(seat, target.iid, x, y); return; }
    if (target?.zone === 'library') { openSeatLibraryMenu(seat, x, y); return; }
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
      { label: 'Grimório: moer, olhar, buscar…', disabled: !alive, action: () => openSeatLibraryMenu(seat, x, y) },
      { label: 'Ver o cemitério…', action: () => openSeatZone(seat, 'graveyard') },
      { label: 'Ver o exílio…', action: () => openSeatZone(seat, 'exile') },
    ], x, y);
  }
  /* ---------- Interagir com o que é dos outros: grimório, permanentes, cemitério ---------- */
  // O pedido vai pela mesa (evento 'interact', visível para todos); quem é dono da carta executa no próprio navegador.
  function interact(seat, op, payload = {}) {
    if (!net || T.status !== 'playing') { toast('A partida ainda não começou.', { kind: 'info' }); return Promise.resolve(false); }
    return net.event('interact', { to: seat, op, ...payload }).then(() => true).catch((error) => { toast(error.message, { kind: 'warn' }); return false; });
  }
  /** Pergunta um número (quantas cartas, quanto dano) numa janela pequena. */
  function askNumber(title, note, value, done, max = 60) {
    closeMenu();
    openModal(`<h2>${esc(title)}</h2><p class="pt-modal-note">${esc(note)}</p><form class="pt-life-form" data-ask-form><label class="pt-field is-short">Quantidade<input name="n" type="number" min="1" max="${max}" value="${value}" required></label><button class="pt-btn is-strong">Confirmar</button></form>`);
    modalCard.querySelector('[data-ask-form]').addEventListener('submit', (event) => {
      event.preventDefault();
      const n = Math.max(1, Math.min(max, Number(event.target.elements.n.value) || 1));
      closeModal();
      done(n);
    });
  }
  function openSeatLibraryMenu(seat, x, y) {
    const name = seatName(seat);
    const state = seatState.get(seat);
    const alive = T.status === 'playing' && !seatInfo.get(seat)?.eliminated && state?.stage === 'play';
    const size = state?.library ?? 0;
    openMenu([
      { title: `Grimório de ${name} · ${size}` },
      { label: 'Moer…', disabled: !alive || !size, action: () => askNumber(`Moer o grimório de ${name}`, 'Quantas cartas do topo vão para o cemitério?', 1, (n) => interact(seat, 'mill', { n }), size) },
      { label: 'Exilar do topo…', disabled: !alive || !size, action: () => askNumber(`Exilar do grimório de ${name}`, 'Quantas cartas do topo vão para o exílio?', 1, (n) => interact(seat, 'exile-top', { n }), size) },
      { label: 'Revelar do topo…', disabled: !alive || !size, action: () => askNumber(`Revelar o grimório de ${name}`, 'Quantas cartas do topo todos veem? Elas continuam no grimório.', 1, (n) => interact(seat, 'reveal-top', { n }), Math.min(size, 15)) },
      { label: 'Olhar as do topo…', disabled: !alive || !size, action: () => askNumber(`Olhar o grimório de ${name}`, 'Só você vê as cartas; a mesa vê quantas. Depois, cada uma ganha um destino.', 1, (n) => requestLook(seat, n, 'top'), Math.min(size, 15)) },
      { label: 'Buscar no grimório…', disabled: !alive || !size, action: () => requestLook(seat, size, 'search') },
      '-',
      { label: 'Fazer comprar…', disabled: !alive || !size, action: () => askNumber(`Fazer ${name} comprar`, 'Quantas cartas?', 1, (n) => interact(seat, 'draw', { n }), size) },
      { label: 'Fazer embaralhar', disabled: !alive, action: () => interact(seat, 'shuffle') },
    ], x, y);
  }
  function openSeatCardMenu(seat, iid, x, y) {
    const entry = (seatState.get(seat)?.battlefield || []).find((row) => row.i === iid);
    if (!entry) return;
    const name = seatFace(seat, entry).name;
    const alive = T.status === 'playing';
    openMenu([
      { title: `${name} · de ${seatName(seat)}` },
      { label: entry.t ? 'Desvirar' : 'Virar', disabled: !alive, action: () => interact(seat, entry.t ? 'untap' : 'tap', { iid }) },
      { label: 'Força/resistência ou habilidade…', disabled: !alive, action: () => openForeignPT(seat, iid, name) },
      { label: 'Marcar dano…', disabled: !alive, action: () => askNumber(`Dano em ${name}`, 'Dano marcado na criatura (com dano letal ela é destruída; sai na limpeza).', 1, (n) => interact(seat, 'damage', { iid, n }), 99) },
      '-',
      { label: 'Destruir (para o cemitério)', disabled: !alive, action: () => interact(seat, 'move', { iid, zone: 'graveyard' }) },
      { label: 'Exilar', disabled: !alive, action: () => interact(seat, 'move', { iid, zone: 'exile' }) },
      { label: 'Devolver para a mão', disabled: !alive, action: () => interact(seat, 'move', { iid, zone: 'hand' }) },
      { label: 'Para o topo do grimório', disabled: !alive, action: () => interact(seat, 'move', { iid, zone: 'library', position: 'top' }) },
      { label: 'Para o fundo do grimório', disabled: !alive, action: () => interact(seat, 'move', { iid, zone: 'library', position: 'bottom' }) },
      '-',
      { label: 'Ganhar o controle', disabled: !alive || entry.bo === ME, action: () => interact(seat, 'control', { iid }) },
      { label: 'Aproximar', key: 'Z', action: () => scene.focusCard({ seat, iid }) },
    ], x, y);
  }
  /** Efeito numa permanente de outro jogador (Giant Growth no bloqueador, −3/−3 de uma remoção, ímpeto…). */
  function openForeignPT(seat, iid, name) {
    closeMenu();
    let until = 'eot';
    const QUICK = [[1, 1], [2, 2], [3, 3], [-1, -1], [-2, -2], [-3, -3], [-4, -4], [-5, -5], [2, 0], [-2, 0], [0, -2]];
    openModal(`<h2>Efeito em ${esc(name)}</h2><p class="pt-modal-note">${esc(seatName(seat))} recebe o efeito na mesa dele.</p>
      <div class="pt-chips pt-until" role="group" aria-label="Duração"><span>Duração:</span><button type="button" class="pt-chip is-on" data-until="eot">Até o fim do turno</button><button type="button" class="pt-chip" data-until="perm">Enquanto estiver no campo</button></div>
      <div class="pt-chips">${QUICK.map(([p, t]) => `<button type="button" class="pt-chip" data-quick="${p},${t}">${signed(p)}/${signed(t)}</button>`).join('')}</div>
      <h3>Dar ou tirar uma habilidade</h3><div class="pt-chips">${Object.entries(KEYWORDS).map(([word, label]) => `<button type="button" class="pt-chip" data-kw="${esc(word)}">${esc(label)}</button>`).join('')}</div>`, (event) => {
      const u = event.target.closest('[data-until]');
      if (u) { until = u.dataset.until; modalCard.querySelectorAll('[data-until]').forEach((b) => b.classList.toggle('is-on', b === u)); return; }
      const q = event.target.closest('[data-quick]');
      const k = event.target.closest('[data-kw]');
      if (!q && !k) return;
      const mod = q ? { p: Number(q.dataset.quick.split(',')[0]), t: Number(q.dataset.quick.split(',')[1]), until } : { kw: [k.dataset.kw], until };
      interact(seat, 'mod', { iid, mod });
      closeModal();
    });
  }
  /* Olhar ou buscar no grimório de outro jogador: ele manda as cartas só para você (whisper); você escolhe o destino. */
  const pendingLooks = new Map();
  function requestLook(seat, n, from) {
    const req = `${ME}-${Date.now().toString(36)}`;
    pendingLooks.set(req, { seat, n, from });
    interact(seat, 'look', { n, from, req }).then((ok) => { if (ok) toast(`Pedindo as cartas a ${seatName(seat)}…`, { kind: 'info' }); });
  }
  function onWhisper(from, payload) {
    if (!payload || payload.kind !== 'cards' || !pendingLooks.has(payload.req)) return;
    const ask = pendingLooks.get(payload.req);
    if (ask.seat !== from) return;
    pendingLooks.delete(payload.req);
    const cards = (Array.isArray(payload.cards) ? payload.cards : []).map((row) => ({ iid: String(row.iid || ''), def: cleanDef(row.def) })).filter((row) => row.iid && row.def);
    audio.play('look');
    openForeignLook(from, payload.req, ask.from, cards);
  }
  function openForeignLook(seat, req, from, cards) {
    closeMenu();
    const search = from === 'search';
    const name = seatName(seat);
    const where = search ? { stay: 'Fica', hand: 'Mão do dono', graveyard: 'Cemitério', exile: 'Exílio', mine: 'Seu campo' } : { top: 'Topo', bottom: 'Fundo', hand: 'Mão do dono', graveyard: 'Cemitério', exile: 'Exílio', mine: 'Seu campo' };
    const plan = cards.map((card) => ({ ...card, to: search ? 'stay' : 'top' }));
    let done = false;
    const finish = (apply) => {
      if (done) return;
      done = true;
      const rows = (apply ? plan : plan.map((row) => ({ ...row, to: search ? 'stay' : 'top' }))).map((row) => ({ iid: row.iid, to: row.to }));
      interact(seat, 'arrange', { req, plan: rows, shuffle: search, from });
      closeModal();
    };
    const paint = (filter = '') => {
      const wanted = filter.trim().toLowerCase();
      const list = modalCard.querySelector('[data-foreign-list]');
      if (!list) return;
      list.innerHTML = plan.map((row, i) => (wanted && !row.def.n.toLowerCase().includes(wanted) ? '' : `<li class="pt-look-card is-${row.to}" style="--d:${search ? 0 : i * 110}ms">
        <div class="pt-look-flip"><img class="pt-look-front" src="${esc(row.def.i || row.def.h || '')}" alt="${esc(row.def.n)}" width="488" height="680"><span class="pt-look-back" aria-hidden="true"></span><b class="pt-look-to">${where[row.to]}</b></div>
        <strong>${search ? '' : `${i + 1}. `}${esc(row.def.n)}</strong>
        <span class="pt-chips">${Object.entries(where).map(([key, label]) => `<button type="button" class="pt-chip${row.to === key ? ' is-on' : ''}" data-to="${key}" data-i="${i}">${label}</button>`).join('')}${search ? '' : `<button type="button" class="pt-chip" data-up="${i}" aria-label="Antes" ${i === 0 ? 'disabled' : ''}>←</button><button type="button" class="pt-chip" data-down="${i}" aria-label="Depois" ${i === plan.length - 1 ? 'disabled' : ''}>→</button>`}</span></li>`)).join('');
      list.classList.add('is-dealt');
    };
    openModal(`<h2>${search ? `Grimório de ${esc(name)}` : `Do topo do grimório de ${esc(name)}`} <span class="pt-modal-count">${plan.length}</span></h2>
      <p class="pt-modal-note">${search ? 'Só você vê. Escolha o que sai e para onde; ao concluir, o grimório é embaralhado (como pedem as buscas).' : 'Só você vê. As que ficam no topo ou no fundo seguem a ordem da esquerda para a direita. “Seu campo” coloca a carta sob o seu controle.'} Cartas para o cemitério, exílio e mão vão para as zonas de ${esc(name)}.</p>
      ${search ? '<label class="pt-field"><span class="sr-only">Filtrar pelo nome</span><input type="search" placeholder="Filtrar pelo nome…" data-foreign-filter></label>' : ''}
      <ul class="pt-look-cards" data-foreign-list></ul>
      <div class="pt-modal-actions"><button type="button" class="pt-btn is-strong" data-foreign-apply>Concluir</button></div>`, (event) => {
      const to = event.target.closest('[data-to]');
      if (to) { plan[Number(to.dataset.i)].to = to.dataset.to; paint(modalCard.querySelector('[data-foreign-filter]')?.value || ''); return; }
      const up = event.target.closest('[data-up]'); const down = event.target.closest('[data-down]');
      if (up || down) { const i = Number(up ? up.dataset.up : down.dataset.down); const j = up ? i - 1 : i + 1; [plan[i], plan[j]] = [plan[j], plan[i]]; paint(); return; }
      if (event.target.closest('[data-foreign-apply]')) finish(true);
    }, () => finish(false));
    if (!search) modalCard.querySelector('[data-foreign-list]').classList.remove('is-dealt');
    paint();
    modalCard.querySelector('[data-foreign-filter]')?.addEventListener('input', (event) => paint(event.target.value));
  }
  function openSeatZone(seat, zone) {
    closeMenu();
    const state = seatState.get(seat);
    const entries = (state?.[zone] || []).map(zoneEntry).slice().reverse();
    const alive = T.status === 'playing';
    const actions = (entry) => (!alive || !entry.i ? '' : `<span class="pt-chips">${zone === 'graveyard' ? `<button type="button" class="pt-chip" data-zone-op="exile" data-iid="${esc(entry.i)}">Exilar</button>` : `<button type="button" class="pt-chip" data-zone-op="graveyard" data-iid="${esc(entry.i)}">Cemitério</button>`}<button type="button" class="pt-chip" data-zone-op="hand" data-iid="${esc(entry.i)}">Mão do dono</button><button type="button" class="pt-chip" data-zone-op="mine" data-iid="${esc(entry.i)}">Seu campo</button></span>`);
    const rows = entries.map((entry) => { const def = defFor(seat, entry.k); return def ? `<li><img src="${esc(def.h || def.i || '')}" alt="" loading="lazy" width="73" height="102"><div><strong>${esc(def.n)}</strong><small>${esc(def.t)}</small>${actions(entry)}</div></li>` : '<li><div><strong>Carta virada para baixo</strong></div></li>'; }).join('');
    openModal(`<h2>${zone === 'graveyard' ? 'Cemitério' : 'Exílio'} de ${esc(seatName(seat))} <span class="pt-modal-count">${entries.length}</span></h2>
      ${alive && zone === 'graveyard' && entries.length ? '<div class="pt-modal-actions"><button type="button" class="pt-btn" data-zone-all>Exilar o cemitério todo</button></div>' : ''}
      <ul class="pt-zone-list">${rows || '<li class="pt-empty">Nenhuma carta.</li>'}</ul>`, (event) => {
      if (event.target.closest('[data-zone-all]')) { interact(seat, 'zone-all', { zone, to: 'exile' }); closeModal(); return; }
      const button = event.target.closest('[data-zone-op]');
      if (!button) return;
      const op = button.dataset.zoneOp;
      interact(seat, op === 'mine' ? 'control' : 'move', { iid: button.dataset.iid, zone: op === 'mine' ? undefined : op, from: zone });
      closeModal();
    });
  }
  /** O que o pedido faz, em palavras (vai para o registro de todos; cada um lê "você" quando é com ele). */
  function interactText(event) {
    const p = event.payload || {};
    const mine = p.to === ME;
    const target = seatName(p.to);
    const lib = mine ? 'o seu grimório' : `o grimório de ${target}`;
    const cardName = () => {
      if (mine) return S.cards[p.iid] ? nameOf(p.iid) : 'uma carta sua';
      const entry = (seatState.get(p.to)?.battlefield || []).find((row) => row.i === p.iid);
      if (entry) return seatFace(p.to, entry).name;
      for (const zone of ['graveyard', 'exile']) { const found = (seatState.get(p.to)?.[zone] || []).map(zoneEntry).find((row) => row.i === p.iid); if (found) return defFor(p.to, found.k)?.n || 'uma carta'; }
      return 'uma carta';
    };
    const card = () => `${cardName()}${mine ? ' (sua)' : ` de ${target}`}`;
    const n = Number(p.n) || 1;
    const cards = `${n} carta${n > 1 ? 's' : ''}`;
    const dest = { graveyard: 'o cemitério', exile: 'o exílio', hand: 'a mão', library: p.position === 'bottom' ? 'o fundo do grimório' : 'o topo do grimório' };
    switch (p.op) {
      case 'mill': return `moeu ${cards} d${lib}.`;
      case 'exile-top': return `exilou ${cards} do topo d${lib}.`;
      case 'reveal-top': return `revelou ${cards} do topo d${lib}.`;
      case 'look': return p.from === 'search' ? `está buscando n${lib}.` : `está olhando ${cards} do topo d${lib}.`;
      case 'arrange': return `terminou de ${p.from === 'search' ? 'buscar n' : 'olhar '}${p.from === 'search' ? lib : lib}.`;
      case 'shuffle': return mine ? 'fez você embaralhar o grimório.' : `fez ${target} embaralhar o grimório.`;
      case 'draw': return mine ? `fez você comprar ${cards}.` : `fez ${target} comprar ${cards}.`;
      case 'tap': return `virou ${card()}.`;
      case 'untap': return `desvirou ${card()}.`;
      case 'move': return `mandou ${card()} para ${dest[p.zone] || p.zone}.`;
      case 'mod': return `deu ${modLabel({ ...p.mod, until: p.mod?.until })} a ${card()}.`;
      case 'damage': return `marcou ${n} de dano em ${card()}.`;
      case 'control': return `ganhou o controle de ${card()}.`;
      case 'zone-all': return mine ? 'exilou o seu cemitério.' : `exilou o cemitério de ${target}.`;
      default: return '';
    }
  }
  /** Executa no seu navegador o que outro jogador fez nas suas cartas (só chega aqui o que é para você). */
  function applyInteract(event, fresh) {
    const p = event.payload || {};
    const by = event.seat;
    const who = seatName(by);
    const n = Math.max(1, Math.min(99, Number(p.n) || 1));
    const mark = () => { S.appliedEvent = Math.max(S.appliedEvent, event.id); };
    const onField = (iid) => S.cards[iid]?.zone === 'battlefield';
    const run = (fn, sound = null) => { act('interact', () => { mark(); fn(); }); if (sound) audio.play(sound); };
    switch (p.op) {
      case 'mill': case 'exile-top': {
        const to = p.op === 'mill' ? 'graveyard' : 'exile';
        run(() => {
          const ids = S.zones.library.slice(0, n);
          ids.forEach((iid) => move(iid, to));
          outbox.splice(outbox.length - ids.length, ids.length);
          log(`${who} ${to === 'graveyard' ? 'moeu' : 'exilou'} ${ids.length} carta(s) do seu grimório: ${ids.map(nameOf).join(', ')}.`, `${to === 'graveyard' ? 'Foram para o cemitério' : 'Foram para o exílio'}: ${ids.map(nameOf).join(', ')}.`);
        }, to === 'graveyard' ? 'die' : 'exile');
        if (fresh) toast(`${who} ${to === 'graveyard' ? 'moeu' : 'exilou'} ${n} carta(s) do seu grimório.`, { kind: 'warn', who: by });
        break;
      }
      case 'reveal-top': {
        const ids = S.zones.library.slice(0, Math.min(15, n));
        run(() => log(`Revelou o topo do grimório a pedido de ${who}: ${ids.map(nameOf).join(', ')}.`, null));
        if (fresh && ids.length) net.event('reveal', { cards: ids.map((iid) => { const card = cardOf(S.cards[iid]); return { n: card.name, t: card.type_line || '', i: card.image || null, h: card.thumb || null }; }), from: `do topo do grimório (a pedido de ${who})` }).catch(() => {});
        break;
      }
      case 'shuffle': run(() => { shuffleList(S.zones.library); scene.fx.shuffle(); log(`${who} fez você embaralhar o grimório.`, null); }, 'shuffle'); break;
      case 'draw': run(() => { drawCards(n, true); log(`Comprou ${n} carta(s) por um efeito de ${who}.`, null); }); if (fresh) toast(`${who} fez você comprar ${n}.`, { kind: 'info', who: by }); break;
      case 'look': {
        if (!fresh) { mark(); break; }
        const ids = p.from === 'search' ? S.zones.library.slice() : p.from === 'bottom' ? S.zones.library.slice(-n) : S.zones.library.slice(0, Math.min(15, n));
        run(() => { S.peek = { by, n: ids.length, from: p.from === 'search' ? 'search' : 'top' }; log(p.from === 'search' ? `${who} está buscando no seu grimório.` : `${who} está olhando ${ids.length} carta(s) do topo do seu grimório.`, null); }, 'look');
        net.whisper(by, { kind: 'cards', req: p.req, from: p.from, cards: ids.map((iid) => ({ iid, def: defOf(cardOf(S.cards[iid])) })) }).catch((error) => toast(error.message, { kind: 'warn' }));
        toast(p.from === 'search' ? `${who} está buscando no seu grimório.` : `${who} está olhando o topo do seu grimório.`, { kind: 'info', who: by });
        break;
      }
      case 'arrange': {
        const plan = (Array.isArray(p.plan) ? p.plan : []).filter((row) => row && S.cards[row.iid]?.zone === 'library');
        const steal = [];
        run(() => {
          const ids = new Set(plan.filter((row) => !['stay'].includes(row.to)).map((row) => row.iid));
          S.zones.library = S.zones.library.filter((iid) => !ids.has(iid));
          plan.filter((row) => row.to === 'top').reverse().forEach((row) => S.zones.library.unshift(row.iid));
          plan.filter((row) => row.to === 'bottom').forEach((row) => S.zones.library.push(row.iid));
          plan.filter((row) => ['graveyard', 'exile', 'hand'].includes(row.to)).forEach((row) => { S.cards[row.iid].zone = row.to; S.zones[row.to].push(row.iid); if (row.to === 'hand') freshHand.add(row.iid); });
          plan.filter((row) => row.to === 'mine').forEach((row) => { S.cards[row.iid].zone = 'lent'; S.zones.lent.push(row.iid); S.lent[row.iid] = by; steal.push(row.iid); });
          if (p.shuffle) shuffleList(S.zones.library);
          S.peek = null;
          const moved = plan.filter((row) => !['top', 'bottom', 'stay'].includes(row.to));
          const label = { graveyard: 'para o seu cemitério', exile: 'para o seu exílio', hand: 'para a sua mão', mine: `para o campo de ${who}` };
          const shown = { graveyard: 'para o cemitério', exile: 'para o exílio', hand: 'para a mão', mine: `para o campo de ${who}` };
          const hidden = (row) => row.to === 'hand';
          log(moved.length ? `${who} terminou e mandou ${moved.map((row) => `${nameOf(row.iid)} ${label[row.to]}`).join(', ')}${p.shuffle ? '; grimório embaralhado' : ''}.` : `${who} terminou de olhar o seu grimório${p.shuffle ? ' (embaralhado)' : ''}.`,
            moved.length ? `Do grimório: ${moved.map((row) => `${hidden(row) ? 'uma carta' : nameOf(row.iid)} ${shown[row.to]}`).join(', ')}${p.shuffle ? '; grimório embaralhado' : ''}.` : null);
        });
        steal.forEach((iid) => lend(iid, by));
        if (p.shuffle) audio.play('shuffle');
        break;
      }
      case 'tap': case 'untap':
        if (!onField(p.iid)) { mark(); break; }
        run(() => { S.cards[p.iid].tapped = p.op === 'tap'; log(`${who} ${p.op === 'tap' ? 'virou' : 'desvirou'} ${nameOf(p.iid)}.`, null); }, 'tap');
        break;
      case 'move': {
        const inst = S.cards[p.iid];
        const zones = ['graveyard', 'exile', 'hand', 'library'];
        if (!inst || !zones.includes(p.zone) || !['battlefield', 'graveyard', 'exile'].includes(inst.zone)) { mark(); break; }
        run(() => { log(`${who} mandou ${nameOf(p.iid)} para ${toZone[p.zone] || zoneName(p.zone, p.position)}.`, null); move(p.iid, p.zone, { position: p.position === 'bottom' ? 'bottom' : 'top' }); });
        if (fresh) toast(`${who} mandou ${S.cards[p.iid] ? nameOf(p.iid) : 'uma carta sua'} para ${toZone[p.zone] || zoneName(p.zone)}.`, { kind: 'warn', who: by });
        break;
      }
      case 'zone-all': {
        if (p.zone !== 'graveyard') { mark(); break; }
        run(() => { const ids = S.zones.graveyard.slice(); ids.forEach((iid) => move(iid, 'exile')); log(`${who} exilou o seu cemitério (${ids.length} cartas).`); }, 'exile');
        break;
      }
      case 'mod':
        if (!onField(p.iid)) { mark(); break; }
        run(() => { const mod = p.mod || {}; addMod(p.iid, { p: mod.p, t: mod.t, kw: (Array.isArray(mod.kw) ? mod.kw : []).filter((word) => KEYWORDS[word]), until: mod.until }, who); }, 'counter');
        if (fresh) toast(`${who} deu um efeito a ${nameOf(p.iid)}.`, { kind: 'info', who: by });
        break;
      case 'damage':
        if (!onField(p.iid)) { mark(); break; }
        run(() => { const inst = S.cards[p.iid]; inst.damage = (inst.damage || 0) + n; log(`${who} marcou ${n} de dano em ${nameOf(p.iid)} (${inst.damage} no total).`, `${nameOf(p.iid)} está com ${inst.damage} de dano.`); }, 'damage');
        break;
      case 'control': {
        const inst = S.cards[p.iid];
        if (!inst || !['battlefield', 'graveyard', 'exile'].includes(inst.zone) || inst.borrowed) { mark(); break; }
        run(() => { log(`${who} ganhou o controle de ${nameOf(p.iid)}.`, null); });
        lend(p.iid, by);
        if (fresh) toast(`${who} ganhou o controle de ${nameOf(p.iid)}.`, { kind: 'warn', who: by });
        break;
      }
      case 'lend': {
        // Uma carta de outro jogador chegou ao seu campo, sob o seu controle.
        const def = cleanDef(p.def);
        if (!def) { mark(); break; }
        run(() => {
          const iid = newInstance(`B${by}:${p.iid}`, { custom: cardFromDef(def), borrowed: { seat: by, iid: String(p.iid) } });
          place(iid, 'battlefield');
          enterBattlefield(S.cards[iid], 'token');
          log(`Agora controla ${def.n}, de ${who}.`);
        });
        break;
      }
      case 'return': {
        // Uma carta sua que estava com outro jogador volta: para o campo (o controle voltou) ou para a sua zona.
        const inst = S.cards[p.iid];
        if (!inst || inst.zone !== 'lent') { mark(); break; }
        const zone = ['battlefield', 'graveyard', 'exile', 'hand', 'library'].includes(p.zone) ? p.zone : 'graveyard';
        run(() => { delete S.lent[p.iid]; const quiet = outbox.length; move(p.iid, zone, { position: p.position === 'bottom' ? 'bottom' : 'top', decided: zone === 'battlefield' }); outbox.splice(quiet); log(`${nameOf(p.iid)} voltou de ${who} para ${zone === 'battlefield' ? 'o seu campo' : toZone[zone] || zoneName(zone)}.`, `${nameOf(p.iid)} voltou para ${zone === 'battlefield' ? 'o campo de quem é dono' : toZone[zone] || zoneName(zone)}.`); });
        break;
      }
      default: mark(); break;
    }
  }
  /** Passa uma carta sua para o controle de outro jogador: ela some do seu campo e aparece no dele. */
  function lend(iid, seat) {
    const inst = S.cards[iid];
    if (!inst) return;
    const def = defOf(inst.flipped && cardOf(inst).back ? { ...cardOf(inst), ...cardOf(inst).back, keywords: cardOf(inst).keywords } : cardOf(inst));
    act('interact', () => {
      if (inst.zone !== 'lent') {
        if (inst.zone === 'battlefield') { scene.depart(iid, 'gone'); Object.assign(inst, { tapped: false, attacking: false, attackTarget: null, blocking: null, counters: {}, mods: [], damage: 0, faceDown: false, flipped: false, manual: false }); }
        const list = S.zones[inst.zone]; if (list) list.splice(list.indexOf(iid), 1);
        inst.zone = 'lent'; S.zones.lent.push(iid);
      }
      S.lent[iid] = seat;
    });
    net.event('interact', { to: seat, op: 'lend', iid, def }).catch((error) => toast(error.message, { kind: 'warn' }));
  }
  /** Devolve o controle de uma carta emprestada: ela volta para o campo do dono. */
  function returnControl(iid) {
    const inst = S.cards[iid];
    if (!inst?.borrowed) return;
    const owner = inst.borrowed.seat;
    act('interact', () => {
      scene.depart(iid, 'gone');
      S.zones.battlefield.splice(S.zones.battlefield.indexOf(iid), 1);
      delete S.cards[iid];
      log(`Devolveu o controle de ${cardOf(inst).name} para ${seatName(owner)}.`);
    });
    net.event('interact', { to: owner, op: 'return', iid: inst.borrowed.iid, zone: 'battlefield' }).catch((error) => toast(error.message, { kind: 'warn' }));
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
      ${(() => { const blockers = (seatState.get(seat)?.battlefield || []).filter((entry) => entry.bl === ME).map((entry) => seatFace(seat, entry).name); return blockers.length ? `<p class="pt-damage-blockers">Bloqueando: ${esc(blockers.join(', '))}</p>` : ''; })()}
      ${group.map((inst) => `<label class="pt-damage-row"><input type="checkbox" data-unblocked="${inst.iid}" checked><span>${esc(nameOf(inst.iid))}${inst.commander ? ' <em>comandante</em>' : ''}${hasKeyword(inst, 'double strike') ? ' <em>golpe duplo</em>' : ''}${hasKeyword(inst, 'lifelink') ? ' <em>vínculo</em>' : ''}</span><input type="number" min="0" max="999" value="${combatDamageOf(inst)}" data-power="${inst.iid}" aria-label="Dano de ${esc(nameOf(inst.iid))}"></label>`).join('')}
      </fieldset>`).join('');
    openModal(`<h2>Dano de combate</h2><p class="pt-modal-note">Desmarque as criaturas bloqueadas e ajuste o dano (atropelar, bloqueio parcial, efeitos). O dano chega na vida de cada defensor${FORMAT.commander_damage ? '; o da comandante também conta como dano de comandante' : ''}.</p>${blocks}
      <div class="pt-modal-actions"><button type="button" class="pt-btn is-strong" data-damage-apply>Causar o dano</button></div>`, (event) => {
      if (!event.target.closest('[data-damage-apply]')) return;
      const sent = [];
      let lifelink = 0;
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
          if (S.cards[iid] && hasKeyword(S.cards[iid], 'lifelink')) lifelink += value;
        });
        if (amount > 0) { net.event('damage', { to: seat, amount, commander, combat: true, sources: names }).catch((error) => toast(error.message, { kind: 'warn' })); sent.push(`${amount} em ${seatName(seat)}`); scene.fx.hit(seat, amount >= 7); }
      });
      closeModal();
      act('damage', () => { if (lifelink) { S.life += lifelink; log(`Vínculo com a vida: +${lifelink} de vida.`); } finishCombat(); if (sent.length) log(`Dano de combate: ${sent.join(', ')}.`, null); });
      postStep();
      if (sent.length) audio.play('damage', { big: sent.length > 1 });
      if (lifelink) { audio.play('life', { up: true }); floatText(`+${lifelink}`, $('[data-pt-meter="life"]')); }
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
    const cards = (Array.isArray(payload.cards) ? payload.cards : [payload.card || {}]).slice(0, 15).map((card) => cleanDef(card) || {});
    const el = document.createElement('div');
    el.className = `pt-reveal${cards.length > 1 ? ' is-many' : ''}`;
    el.style.setProperty('--seat', SEAT_COLORS[seat % 4]);
    el.innerHTML = `<p><b>${esc(seatName(seat))}</b> mostrou ${esc(payload.from || '')}</p><div class="pt-reveal-row">${cards.map((card) => (card.i ? `<img src="${esc(card.i)}" alt="${esc(card.n || '')}" width="488" height="680">` : `<strong>${esc(card.n || '')}</strong>`)).join('')}</div>`;
    el.addEventListener('click', () => el.remove());
    root.appendChild(el);
    audio.play('reveal');
    setTimeout(() => el.classList.add('is-out'), 4200 + cards.length * 600);
    setTimeout(() => el.remove(), 4700 + cards.length * 600);
  }

  /** Começa o seu turno quando a mesa passa a vez para você: desvira, manutenção e compra. */
  function maybeBeginTurn() {
    if (!MP || T.status !== 'playing' || S.stage !== 'play' || T.turn !== ME || S.turnSeen === T.number || S.game !== T.game) return;
    act('turn', () => {
      S.turnSeen = T.number;
      S.turn += 1;
      S.lands = 0;
      endCombat();
      log(`— Seu ${S.turn}º turno (turno ${T.number} da mesa) —`, `Começou o ${S.turn}º turno.`);
      beginTurnSteps();
    });
    scene.fx.turn(null);
    audio.play('turn', { mine: true });
    net.post('phase', { phase: String(S.phase) }).catch(() => {});
  }
  function passTurn() {
    if (!myTurn()) { toast(T.status === 'playing' ? `É a vez de ${seatName(T.turn)}.` : 'A partida ainda não começou.', { kind: 'info' }); return; }
    if (S.stage !== 'play') { toast('Primeiro, decida a mão inicial.', { kind: 'info' }); return; }
    act('turn', () => {
      endCombat();
      S.phase = STEP.cleanup;
      log('Passou o turno.', null);
    });
    net.post('turn', { number: String(T.number) }).catch((error) => toast(error.message, { kind: 'warn' }));
  }

  /** Aplica um evento que chegou da mesa (e registra no feed). */
  function handleEvent(event, fresh) {
    const p = event.payload || {};
    const mine = event.seat === ME;
    const at = event.at;
    const push = (text, kind = event.kind, seat = event.seat, cat = null) => feed.push({ seat, text, at, kind, cat, id: event.id, payload: p });
    switch (event.kind) {
      case 'join': push(`sentou com ${p.deck || 'um deck'}.`); if (fresh && !mine) toast(`${seatName(event.seat)} entrou na mesa.`, { kind: 'info', who: event.seat }); break;
      case 'leave': push(p.kicked ? 'saiu da mesa (retirado por quem abriu).' : 'saiu da mesa.'); break;
      case 'start': push(`começou a partida ${p.game}. ${seatName(p.starting)} joga primeiro.`, 'start'); break;
      case 'lobby': push('encerrou a partida e voltou ao lobby.', 'start'); break;
      case 'turn': push(`passou a vez para ${seatName(p.seat)}.`); if (fresh && p.seat !== ME) { scene.fx.turn(p.seat); audio.play('turn', { mine: false }); } break;
      case 'interact': {
        const text = interactText(event);
        if (text) push(text, 'log', event.seat, p.op === 'look' || p.op === 'arrange' ? 'look' : 'move');
        if (p.to === ME && event.id > S.appliedEvent && S.game === T.game) applyInteract(event, fresh);
        break;
      }
      case 'concede': push(p.left ? 'saiu da partida.' : 'concedeu a partida.'); if (fresh && !mine) toast(`${seatName(event.seat)} ${p.left ? 'saiu' : 'concedeu'}.`, { kind: 'info', who: event.seat }); break;
      case 'log': (p.lines || []).forEach((line) => (typeof line === 'string' ? push(line) : push(String(line.t || ''), 'log', event.seat, line.c || null))); break;
      case 'chat': push(p.text, 'chat'); if (fresh && !mine) { toast(`${seatName(event.seat)}: ${p.text}`, { kind: 'info', who: event.seat }); audio.play('notify'); } break;
      case 'dice': push(p.text); if (fresh && !mine) toast(`${seatName(event.seat)} rolou — ${p.text}`, { kind: 'info', who: event.seat }); break;
      case 'reveal': push(`mostrou ${Array.isArray(p.cards) ? p.cards.map((card) => card?.n || '?').join(', ') : p.card?.n || 'uma carta'} ${p.from || ''}.`); if (fresh && !mine) showReveal(event.seat, p); break;
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
    if (event.id > S.appliedEvent && S.game === T.game && !['damage', 'life', 'poison', 'interact'].includes(event.kind)) S.appliedEvent = event.id;
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
    audio.play(event.kind === 'life' && p.delta > 0 ? 'life' : 'damage', { up: true, big: (p.amount || 0) >= 7 });
    floatText(event.kind === 'poison' ? `☠ +${p.delta}` : `${event.kind === 'life' && p.delta > 0 ? '+' : '−'}${event.kind === 'damage' ? p.amount : Math.abs(p.delta)}`, $(`[data-pt-meter="${event.kind === 'poison' ? 'poison' : 'life'}"]`));
    if (event.kind !== 'life' || p.delta < 0) scene.fx.hit(null, (p.amount || 0) >= 7);
  }

  /** Chegou uma atualização da mesa: assentos, vez, estados públicos e eventos. */
  function onUpdate(update, first) {
    const table = update.table;
    const before = { status: T.status, game: T.game, turn: T.turn, number: T.number, phase: T.phase };
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
      const saved = [resumeState, load()].find((state) => state && state.v === 3 && state.sig === signature && state.game === table.game);
      if (saved) { S = saved; if (!first) toast('Partida retomada.', { kind: 'info' }); setTimeout(resumeLook, 0); }
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
    // Turno de outro jogador: o aviso de cada etapa e os seus gatilhos nela; na troca de turno, a sua parte da limpeza (514.2).
    if (!first && T.status === 'playing' && S && S.game === T.game && S.stage === 'play') {
      const changed = before.turn !== T.turn || before.number !== T.number || before.phase !== T.phase;
      if (changed && T.turn !== ME && STEPS[T.phase]) {
        const theirs = triggersFor(STEPS[T.phase].key, false);
        banner(T.phase, { seat: T.turn, triggers: theirs });
        theirs.forEach((name) => remind(`${name} (${STEPS[T.phase].full.toLowerCase()} de ${seatName(T.turn)})`));
        flushReminders();
      }
      const prevKey = `${T.game}:${before.number}`;
      const stale = S.zones.battlefield.some((iid) => { const inst = S.cards[iid]; return inst.damage || (inst.mods || []).some((mod) => mod.until === 'eot') || (inst.blocking !== null && inst.blocking !== undefined); });
      if (before.number !== T.number && before.game === T.game && S.cleaned !== prevKey && stale) act('cleanup', () => { expireEndOfTurn(`Fim do turno de ${seatName(before.turn)}`); S.cleaned = prevKey; });
      else if (T.phase >= STEP.ecombat && before.phase < STEP.ecombat && S.zones.battlefield.some((iid) => S.cards[iid].blocking !== null && S.cards[iid].blocking !== undefined)) act('cleanup', () => S.zones.battlefield.forEach((iid) => { S.cards[iid].blocking = null; }));
    }
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
      onWhisper: (from, payload) => onWhisper(from, payload),
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
  resumeLook();
}
