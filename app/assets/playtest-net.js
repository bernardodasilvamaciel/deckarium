/* Conexão da Mesa de teste compartilhada: um WebSocket com o serviço de tempo real (realtime/, em /realtime/mesa).

   Ao conectar, o servidor manda o retrato da mesa; depois, só o que muda — vez, fase, assentos, o estado público de
   cada jogador e os eventos —, na hora em que acontece. Aqui isso vira a mesma atualização que playtest.js entende
   ({ me, table, seats, events }, com o estado só dos assentos que mudaram).
   As ações (vez, fase, lobby, eventos) esperam a resposta do servidor. O estado da própria partida sai agrupado e só
   quando muda: o público (o que os outros veem) na hora; as cartas, uma vez só; o privado (para retomar a partida em
   outro aparelho), no máximo a cada 2 s. Se a conexão cai, reconecta sozinha e pede só os eventos que perdeu. */

const PRIVATE_EVERY_MS = 2000;
const REQUEST_TIMEOUT_MS = 12000;
const PING_EVERY_MS = 20000;
const SILENCE_LIMIT_MS = 50000;

export function createTableClient({ socket, api, token, ticket }, handlers) {
  let currentTicket = ticket;
  let ws = null;
  let generation = 0;
  let ready = false;
  let running = true;
  let attempt = 0;
  let first = true;
  let lastEvent = 0;
  let lastHeard = 0;
  let reconnectTimer = 0;
  let requestId = 0;
  const waiting = new Map();
  let queue = [];
  const mirror = { me: null, table: null, seats: new Map() };

  const socketUrl = () => `${location.protocol === 'https:' ? 'wss' : 'ws'}://${location.host}${socket}`;
  const plainSeat = ({ state, ...meta }) => meta;

  function emit(update, isFirst = false) {
    (update.events || []).forEach((event) => { lastEvent = Math.max(lastEvent, event.id); });
    handlers.onUpdate({ me: mirror.me, table: mirror.table, events: [], ...update }, isFirst);
  }

  function connect() {
    if (!running) return;
    const mine = ++generation;
    ready = false;
    ws = new WebSocket(socketUrl());
    const current = ws;
    const alive = () => mine === generation;
    current.addEventListener('open', () => {
      if (!alive()) return;
      lastHeard = Date.now();
      current.send(JSON.stringify({ type: 'hello', ticket: currentTicket, since: lastEvent, mine: first }));
    });
    current.addEventListener('message', (event) => {
      if (!alive()) return;
      lastHeard = Date.now();
      let message = null;
      try { message = JSON.parse(event.data); } catch { /* mensagem ilegível */ }
      if (message) receive(message);
    });
    current.addEventListener('close', (event) => { if (alive()) lost(event.code); });
  }

  /** Conexão perdida: as ações sem resposta falham (podem ou não ter chegado) e outra conexão é aberta. */
  function lost(code) {
    lastCode = code;
    generation += 1;
    ready = false;
    ws = null;
    waiting.forEach((entry, id) => { if (entry.sent) { clearTimeout(entry.timer); waiting.delete(id); entry.reject(new Error('A conexão com a mesa caiu. Confira se a ação valeu e tente de novo.')); } });
    if (!running || code === 4404) return;
    handlers.onStatus?.('offline');
    clearTimeout(reconnectTimer);
    const delay = Math.min(5000, 300 * 2 ** Math.min(attempt, 5)) + Math.random() * 300;
    attempt += 1;
    reconnectTimer = setTimeout(reconnect, code === 1012 ? 1200 : delay);
  }
  function reconnect() {
    clearTimeout(reconnectTimer);
    if (!running || ws) return;
    (lastCode === 4401 ? refreshTicket() : Promise.resolve()).then(connect);
  }
  let lastCode = 0;

  /** O bilhete venceu (a página ficou aberta mais de 12 h): pede outro ao PHP. */
  async function refreshTicket() {
    const response = await fetch(`${api}?table=${encodeURIComponent(token)}`, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } }).catch(() => null);
    const data = response ? await response.json().catch(() => null) : null;
    if (data?.ok) currentTicket = data.ticket;
    else if (data?.gone) { running = false; handlers.onGone?.(data.message); }
  }

  function receive(message) {
    switch (message.type) {
      case 'snapshot': {
        const isFirst = first;
        first = false;
        ready = true;
        attempt = 0;
        mirror.me = message.me;
        mirror.table = message.table;
        mirror.seats = new Map(message.seats.map((seat) => [seat.seat, plainSeat(seat)]));
        // O servidor pode ter reiniciado: as cartas e o último estado vão de novo.
        sentDefs.clear();
        lastPublic = '';
        handlers.onStatus?.('online');
        emit({ seats: message.seats, events: message.events }, isFirst);
        const pendingQueue = queue; queue = [];
        pendingQueue.forEach((entry) => { entry.sent = true; ws.send(entry.text); });
        flushState();
        break;
      }
      case 'room':
        mirror.table = message.table;
        mirror.seats = new Map(message.seats.map((seat) => [seat.seat, plainSeat(seat)]));
        if (message.reset) { sentDefs.clear(); lastPublic = ''; lastPrivate = ''; latest = null; }
        emit({ seats: message.seats.map((seat) => (message.reset ? { ...seat, state: { public: null } } : seat)), events: message.events || [] });
        break;
      case 'state': {
        const meta = mirror.seats.get(message.seat);
        if (!meta) return;
        meta.version = message.version;
        emit({ seats: [...mirror.seats.values()].map((seat) => (seat.seat === message.seat ? { ...seat, state: { public: message.public } } : seat)) });
        break;
      }
      case 'events':
        emit({ seats: [...mirror.seats.values()], events: message.events });
        break;
      case 'ack': {
        const entry = waiting.get(message.rid);
        if (!entry) return;
        waiting.delete(message.rid);
        clearTimeout(entry.timer);
        if (message.ok || message.stale) entry.resolve(message);
        else entry.reject(new Error(message.message || 'A mesa recusou a ação.'));
        break;
      }
      case 'error': handlers.onError?.(message.message); break;
      case 'gone': running = false; clearTimeout(reconnectTimer); handlers.onGone?.(message.message); break;
      default: break;
    }
  }

  /** Ação com resposta. Feita sem conexão, espera a reconexão (até 12 s). */
  function request(type, fields = {}) {
    return new Promise((resolve, reject) => {
      const id = ++requestId;
      const entry = { resolve, reject, sent: false, text: JSON.stringify({ ...fields, type, rid: id }) };
      entry.timer = setTimeout(() => {
        waiting.delete(id);
        queue = queue.filter((other) => other !== entry);
        reject(new Error('A mesa não respondeu. Confira a conexão.'));
      }, REQUEST_TIMEOUT_MS);
      waiting.set(id, entry);
      if (ready && ws?.readyState === 1) { entry.sent = true; ws.send(entry.text); } else queue.push(entry);
    });
  }

  /* ---------- Estado da própria partida ---------- */
  const sentDefs = new Set();
  let latest = null;
  let lastPublic = '';
  let lastPrivate = '';
  let lastPrivateAt = 0;
  let stateTimer = 0;
  let sentGame = null;

  function flushState(forcePrivate = false) {
    clearTimeout(stateTimer);
    stateTimer = 0;
    if (!latest || !ready || ws?.readyState !== 1) return;
    const { game, publicState, privateState } = latest;
    if (game !== sentGame) { sentGame = game; sentDefs.clear(); lastPublic = ''; lastPrivate = ''; }
    const { defs = {}, ...rest } = publicState;
    const publicJson = JSON.stringify(rest);
    const newDefs = Object.fromEntries(Object.entries(defs).filter(([key]) => !sentDefs.has(key)));
    const publicChanged = publicJson !== lastPublic || Object.keys(newDefs).length > 0;
    const privateJson = JSON.stringify(privateState);
    const privateChanged = privateJson !== lastPrivate;
    const privateDue = privateChanged && (forcePrivate || Date.now() - lastPrivateAt >= PRIVATE_EVERY_MS);
    if (publicChanged || privateDue) {
      const message = { type: 'state', game };
      if (publicChanged) { message.public = { ...rest, defs: newDefs }; lastPublic = publicJson; Object.keys(newDefs).forEach((key) => sentDefs.add(key)); }
      if (privateDue) { message.private = privateState; lastPrivate = privateJson; lastPrivateAt = Date.now(); }
      ws.send(JSON.stringify(message));
    }
    // O privado que ficou para depois sai quando der o tempo.
    if (privateChanged && !privateDue) stateTimer = setTimeout(flushState, PRIVATE_EVERY_MS - (Date.now() - lastPrivateAt));
  }

  /** Chamado a cada desenho da mesa: só sai alguma coisa se o estado mudou. */
  function pushState(game, publicState, privateState) {
    latest = { game, publicState, privateState };
    if (!stateTimer) stateTimer = setTimeout(flushState, 50);
  }

  // Ao sair da página, a última jogada (e o estado para retomar) ainda vão.
  window.addEventListener('pagehide', () => flushState(true));

  // Conexão muda (notebook dormiu, Wi-Fi caiu sem avisar): sem notícia do servidor, abre outra.
  const pinger = setInterval(() => {
    if (!running || !ws || !ready) return;
    if (Date.now() - lastHeard > SILENCE_LIMIT_MS) { const dead = ws; lost(1006); try { dead.close(); } catch { /* já fechada */ } return; }
    ws.send('{"type":"ping"}');
  }, PING_EVERY_MS);
  // A rede do aparelho voltou: tenta na hora, sem esperar a próxima tentativa.
  window.addEventListener('online', () => { attempt = 0; reconnect(); });
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible' && running && ready && Date.now() - lastHeard > PING_EVERY_MS) ws?.send('{"type":"ping"}');
  });

  connect();
  return {
    post: (action, fields = {}) => request(action, fields),
    event: (kind, payload = {}) => request('event', { kind, payload }),
    pushState,
    stop() { running = false; clearInterval(pinger); clearTimeout(reconnectTimer); ws?.close(1000); },
  };
}
