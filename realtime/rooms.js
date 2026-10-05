/* Salas da Mesa de teste compartilhada: uma por mesa (playtest_tables), em memória enquanto alguém está conectado.

   Cada navegador continua dono da própria partida (mão, grimório, campo, vida) e manda o que é público; a sala guarda
   quem está sentado, de quem é a vez, a fase, o último estado público de cada jogador e repassa tudo na hora para os
   outros. O que muda vai para o PostgreSQL: a mesa, os assentos e os eventos na hora; o estado de cada jogador, agrupado
   a cada PERSIST_MS (é só para retomar a partida depois). Tudo o que mexe numa sala passa por room.run(), em fila, para
   que os eventos saiam na ordem em que foram gravados. */

import { randomInt } from 'node:crypto';

export const MAX_SEATS = 4;
const STATE_LIMIT = 400000;
const EVENT_KINDS = new Set(['log', 'chat', 'damage', 'life', 'poison', 'reveal', 'dice', 'attack', 'concede']);
const PERSIST_MS = 800;
const OFFLINE_GRACE_MS = 4000;
const IDLE_ROOM_MS = 120000;
const MAX_DEFS = 900;

/** Erro que o jogador deve ler (vai no ack); os outros viram "Não foi possível falar com a mesa agora." */
export class TableError extends Error {}

const clockFormat = new Intl.DateTimeFormat('pt-BR', { hour: '2-digit', minute: '2-digit', hour12: false, timeZone: process.env.TZ || 'America/Sao_Paulo' });
const plainObject = (value) => value !== null && typeof value === 'object' && !Array.isArray(value);
const toInt = (value, fallback = 0) => { const n = Number.parseInt(value, 10); return Number.isFinite(n) ? n : fallback; };

function tableRow(row) {
  return {
    host: Number(row.host_user_id), format: row.format, status: row.status, game: Number(row.game),
    starting: row.starting_seat === null ? null : Number(row.starting_seat), turn: row.turn_seat === null ? null : Number(row.turn_seat),
    number: Number(row.turn_number), phase: Number(row.phase), version: Number(row.version),
  };
}

function eventRow(row) {
  return { id: Number(row.id), seat: row.seat === null ? null : Number(row.seat), kind: row.kind, payload: row.payload || {}, at: clockFormat.format(row.created_at) };
}

const SEAT_SQL = `SELECT s.seat, s.user_id, s.deck_id, s.state_version, s.eliminated,
    COALESCE(NULLIF(u.display_name,''), NULLIF(split_part(u.full_name,' ',1),''), u.username) AS player, u.username,
    d.name AS deck_name, c.id AS commander_id, c.name AS commander_name %STATES%
  FROM playtest_seats s JOIN users u ON u.id=s.user_id JOIN builder_decks d ON d.id=s.deck_id LEFT JOIN cards c ON c.id=d.commander_id
  WHERE s.table_id=$1 ORDER BY s.seat`;

export class Room {
  constructor(token, { db, log, onClose }) {
    this.token = token;
    this.db = db;
    this.log = log;
    this.onClose = onClose;
    this.table = null;
    this.seats = new Map();      // assento → jogador (dados do deck, estado público, privado)
    this.clients = new Set();    // sockets autenticados nesta mesa
    this.present = new Set();    // assentos conectados (com uma folga para recarregar a página)
    this.offlineTimers = new Map();
    this.queue = Promise.resolve();
    this.dirty = new Set();
    this.persistTimer = null;
    this.idleTimer = null;
    this.eventCursor = 0;        // último evento lido do banco (os do PHP chegam por NOTIFY)
    this.sent = new Set();       // eventos gravados aqui e já repassados
    this.closed = false;
  }

  /** Enfileira uma tarefa da sala; o erro volta para quem chamou, sem travar a fila. */
  run(task) {
    const result = this.queue.then(() => (this.closed ? null : task()));
    this.queue = result.catch(() => {});
    return result;
  }

  async load() {
    const { rows } = await this.db.query('SELECT * FROM playtest_tables WHERE id=$1', [this.token]);
    if (!rows.length) return false;
    this.table = tableRow(rows[0]);
    await this.loadSeats(true);
    const last = await this.db.query('SELECT COALESCE(MAX(id),0) AS id FROM playtest_events WHERE table_id=$1', [this.token]);
    this.eventCursor = Number(last.rows[0].id);
    return true;
  }

  async loadSeats(withStates) {
    const states = withStates ? ', s.public_state, s.private_state::text AS private_state' : '';
    const { rows } = await this.db.query(SEAT_SQL.replace('%STATES%', states), [this.token]);
    const seen = new Set();
    for (const row of rows) {
      const seat = Number(row.seat);
      seen.add(seat);
      const meta = {
        seat, userId: Number(row.user_id), deckId: Number(row.deck_id), player: row.player, username: row.username, deck: row.deck_name,
        commander: row.commander_id ? { name: row.commander_name, image: `/image.php?id=${encodeURIComponent(row.commander_id)}&size=normal` } : null,
        eliminated: row.eliminated === true,
      };
      const current = this.seats.get(seat);
      if (current && current.userId === meta.userId) { Object.assign(current, meta); continue; }
      // Assento novo (ou de outra pessoa): o estado vem do banco só na primeira carga.
      const saved = withStates && plainObject(row.public_state) ? row.public_state : null;
      const { defs = {}, ...publicState } = saved || {};
      this.seats.set(seat, { ...meta, version: withStates ? Number(row.state_version) : 0, public: saved ? publicState : null,
        defs: plainObject(defs) ? defs : {}, privateJson: withStates ? row.private_state || null : null });
    }
    for (const seat of [...this.seats.keys()]) if (!seen.has(seat)) this.seats.delete(seat);
  }

  /** O PHP mudou a mesa (alguém sentou): relê mesa, assentos e eventos novos e avisa todos. */
  async reload() {
    const { rows } = await this.db.query('SELECT * FROM playtest_tables WHERE id=$1', [this.token]);
    if (!rows.length) { this.close('A mesa foi encerrada.'); return; }
    this.table = tableRow(rows[0]);
    await this.loadSeats(false);
    for (const ws of [...this.clients]) {
      const seat = this.seatOfUser(ws.userId);
      if (seat === null) this.drop(ws, 'Você não está mais sentado nesta mesa.');
      else ws.seat = seat;
    }
    const fresh = await this.db.query('SELECT id, seat, kind, payload, created_at FROM playtest_events WHERE table_id=$1 AND id>$2 ORDER BY id', [this.token, this.eventCursor]);
    const events = fresh.rows.map(eventRow).filter((event) => !this.sent.has(event.id));
    if (fresh.rows.length) this.eventCursor = Number(fresh.rows[fresh.rows.length - 1].id);
    for (const id of this.sent) if (id <= this.eventCursor) this.sent.delete(id);
    this.broadcast(this.roomMessage({ events }));
  }

  seatOfUser(userId) {
    for (const seat of this.seats.values()) if (seat.userId === userId) return seat.seat;
    return null;
  }

  hostSeat() { return this.seatOfUser(this.table.host); }

  isOnline(seat) { return this.present.has(seat); }

  tableJson() {
    const t = this.table;
    return { status: t.status, game: t.game, starting: t.starting, turn: t.turn, number: t.number, phase: t.phase, version: t.version, host: this.hostSeat(), format: t.format };
  }

  seatJson(seat) {
    return { seat: seat.seat, player: seat.player, username: seat.username, deck: seat.deck, commander: seat.commander, version: seat.version, online: this.isOnline(seat.seat), eliminated: seat.eliminated };
  }

  seatsJson() { return [...this.seats.values()].sort((a, b) => a.seat - b.seat).map((seat) => this.seatJson(seat)); }

  roomMessage(extra = {}) { return { type: 'room', table: this.tableJson(), seats: this.seatsJson(), ...extra }; }

  /** Tudo o que um jogador precisa ao conectar: a mesa, os estados públicos, o próprio estado privado e os eventos. */
  async snapshot(ws, since, mine) {
    const seats = this.seatsJson().map((meta) => {
      const seat = this.seats.get(meta.seat);
      const state = { public: seat.public ? { ...seat.public, defs: seat.defs } : null };
      if (mine && seat.userId === ws.userId && seat.privateJson) state.private = JSON.parse(seat.privateJson);
      return { ...meta, state };
    });
    const query = since > 0
      ? this.db.query('SELECT id, seat, kind, payload, created_at FROM playtest_events WHERE table_id=$1 AND id>$2 ORDER BY id LIMIT 400', [this.token, since])
      : this.db.query('SELECT * FROM (SELECT id, seat, kind, payload, created_at FROM playtest_events WHERE table_id=$1 ORDER BY id DESC LIMIT 150) recent ORDER BY id', [this.token]);
    const { rows } = await query;
    return { type: 'snapshot', me: ws.seat, table: this.tableJson(), seats, events: rows.map(eventRow) };
  }

  send(ws, message) {
    if (ws.readyState === 1) ws.send(typeof message === 'string' ? message : JSON.stringify(message));
  }

  broadcast(message, except = null) {
    const text = JSON.stringify(message);
    for (const ws of this.clients) if (ws !== except) this.send(ws, text);
  }

  /* ---------- Presença ---------- */

  /** Marca o assento como conectado; devolve true se ele estava fora (os outros precisam saber). */
  arrive(seat) {
    clearTimeout(this.idleTimer);
    clearTimeout(this.offlineTimers.get(seat));
    this.offlineTimers.delete(seat);
    const arrived = !this.present.has(seat);
    this.present.add(seat);
    this.touchSeen([seat]);
    return arrived;
  }

  leave(ws) {
    if (!this.clients.delete(ws)) return;
    const seat = ws.seat;
    if (seat !== null && ![...this.clients].some((other) => other.seat === seat)) {
      clearTimeout(this.offlineTimers.get(seat));
      this.offlineTimers.set(seat, setTimeout(() => {
        this.offlineTimers.delete(seat);
        if ([...this.clients].some((other) => other.seat === seat) || !this.present.delete(seat)) return;
        this.touchSeen([seat]);
        if (!this.closed) this.broadcast(this.roomMessage());
      }, OFFLINE_GRACE_MS));
    }
    if (!this.clients.size) {
      clearTimeout(this.idleTimer);
      this.idleTimer = setTimeout(() => this.run(async () => { if (!this.clients.size) { await this.flush(); this.dispose(); } }).catch((error) => this.log('idle', error)), IDLE_ROOM_MS);
    }
  }

  touchSeen(seats) {
    if (seats.length) this.db.query('UPDATE playtest_seats SET last_seen=now() WHERE table_id=$1 AND seat = ANY($2::smallint[])', [this.token, seats]).catch((error) => this.log('last_seen', error));
  }

  /** Tira um socket da mesa com uma mensagem (mesa encerrada, retirado, saiu em outra aba). */
  drop(ws, message) {
    this.send(ws, { type: 'gone', message });
    this.clients.delete(ws);
    ws.room = null;
    ws.close(4404, 'gone');
  }

  close(message) {
    for (const ws of [...this.clients]) this.drop(ws, message);
    this.dispose();
  }

  dispose() {
    this.closed = true;
    clearTimeout(this.persistTimer);
    clearTimeout(this.idleTimer);
    this.offlineTimers.forEach((timer) => clearTimeout(timer));
    this.onClose(this);
  }

  /* ---------- Banco ---------- */

  async addEvent(seat, kind, payload = {}) {
    const { rows } = await this.db.query(`WITH e AS (INSERT INTO playtest_events(table_id,seat,kind,payload) VALUES ($1,$2,$3,$4::jsonb) RETURNING id, seat, kind, payload, created_at),
        t AS (UPDATE playtest_tables SET updated_at=now() WHERE id=$1) SELECT * FROM e`, [this.token, seat, kind, JSON.stringify(payload)]);
    const event = eventRow(rows[0]);
    this.sent.add(event.id);
    return event;
  }

  /** Muda campos da mesa (sempre sobe a versão). As chaves vêm do código, nunca do cliente. */
  async updateTable(fields = {}) {
    const keys = Object.keys(fields);
    const sets = keys.map((key, index) => `${key}=$${index + 2}`).concat('version=version+1', 'updated_at=now()').join(', ');
    const { rows } = await this.db.query(`UPDATE playtest_tables SET ${sets} WHERE id=$1 RETURNING *`, [this.token, ...keys.map((key) => fields[key])]);
    if (rows[0]) this.table = tableRow(rows[0]);
  }

  async setEliminated(seat, value) {
    await this.db.query('UPDATE playtest_seats SET eliminated=$3 WHERE table_id=$1 AND seat=$2', [this.token, seat, value]);
    const row = this.seats.get(seat);
    if (row) row.eliminated = value;
  }

  markDirty(seat) {
    this.dirty.add(seat);
    if (!this.persistTimer) this.persistTimer = setTimeout(() => { this.run(() => this.flush()).catch((error) => this.log('persist', error)); }, PERSIST_MS);
  }

  /** Grava o estado de quem jogou desde a última gravação. */
  async flush() {
    clearTimeout(this.persistTimer);
    this.persistTimer = null;
    const seats = [...this.dirty];
    this.dirty.clear();
    for (const number of seats) {
      const seat = this.seats.get(number);
      if (!seat) continue;
      await this.db.query(`UPDATE playtest_seats SET public_state=$3::jsonb, private_state=COALESCE($4::jsonb, private_state), state_version=$5, last_seen=now()
        WHERE table_id=$1 AND seat=$2`, [this.token, number, seat.public ? JSON.stringify({ ...seat.public, defs: seat.defs }) : null, seat.privateJson, seat.version]);
    }
  }

  /** Próximo assento vivo depois de from, na ordem da mesa. */
  async passTurn(from) {
    const alive = [...this.seats.values()].filter((seat) => !seat.eliminated).map((seat) => seat.seat).sort((a, b) => a - b);
    if (!alive.length) return [];
    const next = alive.find((seat) => seat > from) ?? alive[0];
    await this.updateTable({ turn_seat: next, turn_number: this.table.number + 1, phase: 0 });
    return [await this.addEvent(from, 'turn', { seat: next, number: this.table.number })];
  }

  /* ---------- O que os jogadores pedem ---------- */

  async handle(ws, message) {
    const action = ACTIONS[message.type];
    if (!action) throw new TableError('Ação inválida.');
    const seat = this.seats.get(ws.seat);
    if (!seat || seat.userId !== ws.userId) throw new TableError('Você não está sentado nesta mesa.');
    return action.call(this, ws, seat, message);
  }

  needPlaying() {
    if (this.table.status !== 'playing') throw new TableError('A partida ainda não começou.');
  }

  needHost(ws, what) {
    if (this.table.host !== ws.userId) throw new TableError(`Só quem abriu a mesa pode ${what}.`);
  }
}

const ACTIONS = {
  /** O estado público vai na hora para os outros (as cartas, defs, só as que ainda não tinham ido); o privado só é
      guardado, para o dono retomar a partida. Pode chegar um sem o outro. */
  state(ws, seat, message) {
    if (this.table.status !== 'playing' || toInt(message.game, -1) !== this.table.game) return { stale: true };
    const privateJson = plainObject(message.private) ? JSON.stringify(message.private) : null;
    if (privateJson && privateJson.length > STATE_LIMIT) throw new TableError('Estado da partida grande demais.');
    if (privateJson) seat.privateJson = privateJson;
    if (message.public === undefined) {
      if (privateJson) this.markDirty(seat.seat);
      return null;
    }
    if (!plainObject(message.public)) throw new TableError('Estado público inválido.');
    const { defs, ...publicState } = message.public;
    const newDefs = plainObject(defs) ? defs : {};
    if (Object.keys(seat.defs).length + Object.keys(newDefs).length > MAX_DEFS) seat.defs = {};
    Object.assign(seat.defs, newDefs);
    seat.public = publicState;
    seat.version += 1;
    this.markDirty(seat.seat);
    this.broadcast({ type: 'state', seat: seat.seat, version: seat.version, public: { ...publicState, defs: newDefs } }, ws);
    return null;
  },

  async event(ws, seat, message) {
    const kind = String(message.kind || '');
    if (!EVENT_KINDS.has(kind)) throw new TableError('Evento desconhecido.');
    let payload = plainObject(message.payload) ? message.payload : {};
    if (JSON.stringify(payload).length > 20000) throw new TableError('Evento inválido.');
    if (kind === 'chat') {
      payload = { ...payload, text: Array.from(String(payload.text || '').trim()).slice(0, 280).join('') };
      if (!payload.text) throw new TableError('Mensagem vazia.');
    }
    if (kind !== 'concede') {
      const event = await this.addEvent(seat.seat, kind, payload);
      this.broadcast({ type: 'events', events: [event] });
      return { id: event.id };
    }
    this.needPlaying();
    await this.setEliminated(seat.seat, true);
    const events = [await this.addEvent(seat.seat, kind, payload)];
    if (this.table.turn === seat.seat) events.push(...await this.passTurn(seat.seat));
    else await this.updateTable();
    this.broadcast(this.roomMessage({ events }));
    return { id: events[0].id };
  },

  async turn(ws, seat, message) {
    this.needPlaying();
    // Pedido de um turno que já passou (clique duplo, duas abas): nada a fazer.
    if (message.number !== undefined && toInt(message.number, -1) !== this.table.number) return { stale: true };
    const active = this.table.turn;
    // Quem abriu a mesa pode pular a vez de quem caiu.
    if (active !== seat.seat && !(this.table.host === ws.userId && !this.isOnline(active))) throw new TableError('Só quem está jogando o turno pode passá-lo.');
    const events = await this.passTurn(active);
    this.broadcast(this.roomMessage({ events }));
    return null;
  },

  async phase(ws, seat, message) {
    this.needPlaying();
    if (this.table.turn !== seat.seat) throw new TableError('A fase é de quem está jogando o turno.');
    const phase = Math.max(0, Math.min(6, toInt(message.phase)));
    if (phase === this.table.phase) return null;
    await this.updateTable({ phase });
    this.broadcast(this.roomMessage());
    return null;
  },

  async start(ws, seat) {
    this.needHost(ws, 'começar a partida');
    const seats = [...this.seats.keys()].sort((a, b) => a - b);
    if (seats.length < 2) throw new TableError('Chame pelo menos mais uma pessoa pelo link da mesa.');
    // Quem começa é sorteado (103.1: em Magic, decide-se ao acaso).
    const starting = seats[randomInt(seats.length)];
    clearTimeout(this.persistTimer);
    this.persistTimer = null;
    this.dirty.clear();
    const { rows } = await this.db.query(`UPDATE playtest_tables SET status='playing', game=game+1, starting_seat=$2, turn_seat=$2, turn_number=1, phase=0,
        version=version+1, updated_at=now() WHERE id=$1 RETURNING *`, [this.token, starting]);
    this.table = tableRow(rows[0]);
    await this.db.query('UPDATE playtest_seats SET public_state=NULL, private_state=NULL, state_version=0, eliminated=false WHERE table_id=$1', [this.token]);
    for (const row of this.seats.values()) Object.assign(row, { public: null, defs: {}, privateJson: null, version: 0, eliminated: false });
    const event = await this.addEvent(seat.seat, 'start', { game: this.table.game, starting, seats });
    this.broadcast(this.roomMessage({ reset: true, events: [event] }));
    return { game: this.table.game };
  },

  async lobby(ws, seat) {
    this.needHost(ws, 'encerrar a partida');
    await this.updateTable({ status: 'lobby', turn_seat: null, turn_number: 0, phase: 0 });
    await this.db.query('UPDATE playtest_seats SET eliminated=false WHERE table_id=$1', [this.token]);
    for (const row of this.seats.values()) row.eliminated = false;
    const event = await this.addEvent(seat.seat, 'lobby');
    this.broadcast(this.roomMessage({ events: [event] }));
    return null;
  },

  async kick(ws, seat, message) {
    this.needHost(ws, 'tirar alguém');
    if (this.table.status !== 'lobby') throw new TableError('Durante a partida, peça para a pessoa sair ou volte ao lobby.');
    const target = toInt(message.seat, -1);
    if (target === seat.seat) throw new TableError('Para sair da sua própria mesa, use “Sair da mesa”.');
    const kicked = this.seats.get(target);
    if (!kicked) return null;
    await this.db.query('DELETE FROM playtest_seats WHERE table_id=$1 AND seat=$2', [this.token, target]);
    this.seats.delete(target);
    this.present.delete(target);
    const event = await this.addEvent(target, 'leave', { kicked: true });
    await this.updateTable();
    this.broadcast(this.roomMessage({ events: [event] }));
    for (const other of [...this.clients]) if (other.userId === kicked.userId) this.drop(other, 'Quem abriu a mesa tirou você dela.');
    return null;
  },

  /** Sair: no lobby libera o lugar; durante a partida, conta como conceder (o lugar fica, fora da ordem de turno). */
  async leave(ws, seat) {
    const events = [];
    const playing = this.table.status === 'playing';
    if (playing) {
      await this.setEliminated(seat.seat, true);
      events.push(await this.addEvent(seat.seat, 'concede', { left: true }));
      if (this.table.turn === seat.seat) events.push(...await this.passTurn(seat.seat));
    } else {
      await this.db.query('DELETE FROM playtest_seats WHERE table_id=$1 AND seat=$2', [this.token, seat.seat]);
      this.seats.delete(seat.seat);
      this.present.delete(seat.seat);
      events.push(await this.addEvent(seat.seat, 'leave'));
    }
    // Quem abriu a mesa saiu: a próxima pessoa sentada passa a cuidar dela; sem ninguém, a mesa acaba.
    if (this.table.host === ws.userId) {
      const heir = [...this.seats.values()].filter((row) => row.userId !== ws.userId).sort((a, b) => (a.eliminated - b.eliminated) || (a.seat - b.seat))[0];
      if (!heir) {
        await this.db.query('DELETE FROM playtest_tables WHERE id=$1', [this.token]);
        setImmediate(() => this.close('A mesa foi encerrada.'));
        return null;
      }
      await this.updateTable({ host_user_id: heir.userId });
    } else await this.updateTable();
    this.broadcast(this.roomMessage({ events }));
    // Outras abas da mesma pessoa saem junto (depois da resposta a esta).
    if (!playing) setImmediate(() => { for (const other of [...this.clients]) if (other.userId === ws.userId && other !== ws) this.drop(other, 'Você saiu da mesa.'); });
    return null;
  },
};
