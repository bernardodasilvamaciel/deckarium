/* Deckarium · tempo real da Mesa de teste compartilhada.

   Um servidor WebSocket (Node.js + ws) em /realtime/mesa. O navegador abre uma conexão por mesa e, nela, manda e
   recebe tudo da partida: estados, eventos, vez, fase e o lobby. O site em PHP continua abrindo a mesa e sentando os
   jogadores (a escolha do deck); quando muda algo, avisa este servidor com NOTIFY playtest.

   Autenticação: a página da mesa (deck_playtest.php) entrega um bilhete assinado com HMAC-SHA256 — usuário, mesa e
   validade — com o segredo guardado em app_secrets. A primeira mensagem do socket é {type:'hello', ticket}.

   Em desenvolvimento e em produção o Apache repassa /realtime/ para cá (mod_proxy_wstunnel); o cloudflared também pode
   mandar /realtime/ direto para a porta deste serviço. */

import http from 'node:http';
import { createHmac, timingSafeEqual } from 'node:crypto';
import pg from 'pg';
import { WebSocketServer } from 'ws';
import { Room, TableError } from './rooms.js';

const PORT = Number(process.env.REALTIME_PORT || 8090);
const HOST = process.env.REALTIME_HOST || '0.0.0.0';
const PATH = '/realtime/mesa';
const HELLO_TIMEOUT_MS = 10000;
const HEARTBEAT_MS = 30000;
const RATE_WINDOW_MS = 10000;
const RATE_LIMIT = 400;

const dbConfig = {
  host: process.env.DB_HOST || '127.0.0.1', port: Number(process.env.DB_PORT || 5432), database: process.env.DB_NAME || 'mtg',
  user: process.env.DB_USER || 'mtg', password: process.env.DB_PASSWORD || '', application_name: 'deckarium-realtime',
};

const log = (scope, detail = '') => console.log(`${new Date().toISOString()} [${scope}] ${detail instanceof Error ? detail.stack || detail.message : detail}`);
const db = new pg.Pool({ ...dbConfig, max: 10, idleTimeoutMillis: 30000 });
db.on('error', (error) => log('db', error));

/* ---------- Bilhetes ---------- */

let secret = null;
async function loadSecret() {
  try {
    const { rows } = await db.query("SELECT value FROM app_secrets WHERE name='realtime'");
    secret = rows[0]?.value || null;
  } catch (error) {
    log('secret', error.message);
  }
  return secret;
}

/** Confere o bilhete; se não bate, relê o segredo uma vez (pode ter sido criado depois que o serviço subiu). */
async function verifyTicket(ticket) {
  if (typeof ticket !== 'string' || ticket.length > 1000) return null;
  const [body, signature] = ticket.split('.');
  if (!body || !signature) return null;
  const given = Buffer.from(signature, 'base64url');
  for (const retry of [false, true]) {
    if (retry || !secret) await loadSecret();
    if (!secret) return null;
    const expected = createHmac('sha256', secret).update(body).digest();
    if (given.length !== expected.length || !timingSafeEqual(given, expected)) continue;
    try {
      const data = JSON.parse(Buffer.from(body, 'base64url').toString('utf8'));
      if (Number.isInteger(data.u) && typeof data.t === 'string' && data.e > Date.now() / 1000) return { userId: data.u, token: data.t };
    } catch { /* bilhete ilegível */ }
    return null;
  }
  return null;
}

/* ---------- Salas ---------- */

const rooms = new Map();
const roomOptions = { db, log, onClose: (room) => { if (rooms.get(room.token) === room) rooms.delete(room.token); } };

async function getRoom(token) {
  let room = rooms.get(token);
  if (!room) {
    room = new Room(token, roomOptions);
    rooms.set(token, room);
    room.ready = room.run(() => room.load());
  }
  const found = await room.ready.catch((error) => { log('load', error); return false; });
  if (!found) { roomOptions.onClose(room); return null; }
  return room;
}

/* ---------- Conexões ---------- */

const send = (ws, message) => { if (ws.readyState === 1) ws.send(JSON.stringify(message)); };

async function hello(ws, message) {
  const auth = await verifyTicket(message.ticket);
  if (!auth) { send(ws, { type: 'denied', message: 'O acesso à mesa expirou.' }); ws.close(4401, 'ticket'); return; }
  ws.userId = auth.userId;
  const room = /^[A-Za-z0-9]{12}$/.test(auth.token) ? await getRoom(auth.token) : null;
  if (ws.readyState !== 1) return;
  if (!room) { send(ws, { type: 'gone', message: 'A mesa foi encerrada.' }); ws.close(4404, 'gone'); return; }
  await room.run(async () => {
    const seat = room.seatOfUser(ws.userId);
    if (seat === null) { send(ws, { type: 'gone', message: 'Você não está sentado nesta mesa.' }); ws.close(4404, 'gone'); return; }
    if (ws.readyState !== 1) return;
    ws.seat = seat;
    ws.room = room;
    const arrived = room.arrive(seat);
    const snapshot = await room.snapshot(ws, Math.max(0, Number(message.since) || 0), message.mine === true);
    room.clients.add(ws);
    ws.authed = true;
    send(ws, snapshot);
    if (arrived) room.broadcast(room.roomMessage(), ws);
  });
}

async function dispatch(ws, message) {
  const room = ws.room;
  if (!room) return;
  const rid = typeof message.rid === 'number' ? message.rid : null;
  try {
    const result = await room.run(() => room.handle(ws, message));
    if (rid !== null) send(ws, { type: 'ack', rid, ok: true, ...(result || {}) });
  } catch (error) {
    const readable = error instanceof TableError;
    if (!readable) log(`action ${message.type}`, error);
    const text = readable ? error.message : 'Não foi possível falar com a mesa agora.';
    send(ws, rid !== null ? { type: 'ack', rid, ok: false, message: text } : { type: 'error', message: text });
  }
}

const wss = new WebSocketServer({
  noServer: true,
  maxPayload: 1_200_000,
  // O estado privado (para retomar a partida) é um JSON grande e repetitivo: comprimido, vai em poucos KB.
  perMessageDeflate: { threshold: 1024, zlibDeflateOptions: { level: 3 }, serverNoContextTakeover: true, clientNoContextTakeover: true },
});

wss.on('connection', (ws) => {
  ws.isAlive = true;
  ws.authed = false;
  ws.room = null;
  ws.seat = null;
  ws.rate = { start: Date.now(), count: 0 };
  const helloTimer = setTimeout(() => { if (!ws.authed) ws.close(4400, 'hello'); }, HELLO_TIMEOUT_MS);
  let greeting = null;

  ws.on('pong', () => { ws.isAlive = true; });
  ws.on('message', (data, isBinary) => {
    ws.isAlive = true;
    const now = Date.now();
    if (now - ws.rate.start > RATE_WINDOW_MS) ws.rate = { start: now, count: 0 };
    if (++ws.rate.count > RATE_LIMIT) { ws.close(1008, 'rate'); return; }
    let message;
    try { message = isBinary ? null : JSON.parse(data.toString('utf8')); } catch { message = null; }
    if (!message || typeof message.type !== 'string') return;
    if (message.type === 'ping') { send(ws, { type: 'pong' }); return; }
    if (message.type === 'hello') {
      if (greeting) return;
      greeting = hello(ws, message).catch((error) => { log('hello', error); ws.close(1011, 'error'); }).finally(() => clearTimeout(helloTimer));
      return;
    }
    // O cliente espera o snapshot antes de mandar o resto; se algo chegar antes, entra na fila depois do hello.
    (greeting || Promise.resolve()).then(() => {
      if (ws.authed) dispatch(ws, message);
      else if (typeof message.rid === 'number') send(ws, { type: 'ack', rid: message.rid, ok: false, message: 'Ainda conectando à mesa.' });
    });
  });
  ws.on('close', () => {
    clearTimeout(helloTimer);
    ws.room?.leave(ws);
  });
  ws.on('error', (error) => log('socket', error.message));
});

// Conexões que pararam de responder (notebook dormiu, rede caiu) são encerradas; o navegador reconecta.
const heartbeat = setInterval(() => {
  for (const ws of wss.clients) {
    if (!ws.isAlive) { ws.terminate(); continue; }
    ws.isAlive = false;
    ws.ping();
  }
}, HEARTBEAT_MS);

const server = http.createServer((req, res) => {
  const path = (req.url || '').split('?')[0];
  if (path === '/realtime/health' || path === '/health') {
    res.writeHead(200, { 'Content-Type': 'application/json', 'Cache-Control': 'no-store' });
    res.end(JSON.stringify({ ok: true, rooms: rooms.size, connections: wss.clients.size }));
    return;
  }
  res.writeHead(426, { 'Content-Type': 'text/plain; charset=utf-8' });
  res.end('Use WebSocket em /realtime/mesa.');
});

server.on('upgrade', (req, socket, head) => {
  if ((req.url || '').split('?')[0] !== PATH) { socket.destroy(); return; }
  wss.handleUpgrade(req, socket, head, (ws) => wss.emit('connection', ws, req));
});

/* ---------- Avisos do PHP (NOTIFY playtest, com o código da mesa) ---------- */

let listener = null;
async function listen() {
  const client = new pg.Client(dbConfig);
  let restarted = false;
  const restart = () => {
    if (restarted) return;
    restarted = true;
    listener = null;
    client.end().catch(() => {});
    setTimeout(listen, 2000);
  };
  client.on('error', (error) => { log('listen', error.message); restart(); });
  client.on('end', restart);
  client.on('notification', (notice) => {
    const room = rooms.get(notice.payload);
    if (room) room.run(() => room.reload()).catch((error) => log('reload', error));
  });
  try {
    await client.connect();
    await client.query('LISTEN playtest');
    // Avisos podem ter se perdido enquanto a conexão estava fora: relê as salas abertas.
    if (listener === null && rooms.size) rooms.forEach((room) => room.run(() => room.reload()).catch((error) => log('reload', error)));
    listener = client;
  } catch (error) {
    log('listen', error.message);
    restart();
  }
}

// last_seen das pessoas conectadas, para o resto do site saber quem está na mesa.
const presence = setInterval(() => {
  rooms.forEach((room) => { if (!room.closed) room.touchSeen([...room.present]); });
}, 30000);

server.listen(PORT, HOST, () => log('start', `ouvindo em ${HOST}:${PORT}${PATH}`));
listen();
loadSecret();

/* ---------- Desligar sem perder jogadas ---------- */

async function shutdown(signal) {
  log('stop', signal);
  clearInterval(heartbeat);
  clearInterval(presence);
  server.close();
  await Promise.allSettled([...rooms.values()].map((room) => room.run(() => room.flush())));
  // 1012: o serviço está reiniciando; o navegador reconecta sozinho.
  for (const ws of wss.clients) ws.close(1012, 'restart');
  await listener?.end().catch(() => {});
  await db.end().catch(() => {});
  process.exit(0);
}
process.on('SIGTERM', () => shutdown('SIGTERM'));
process.on('SIGINT', () => shutdown('SIGINT'));
