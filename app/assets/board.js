/* Quadro de relações do Deckarium: modelo do grafo, painel, filtros e combos, com duas vistas —
   a constelação 3D (assets/board3d.js, carregada sob demanda) e o plano em SVG com blocos por tema. */
(() => {
  'use strict';
  const root = document.querySelector('[data-board]');
  const dataEl = document.getElementById('board-data');
  if (!root || !dataEl) return;
  const data = JSON.parse(dataEl.textContent);
  const SVG = 'http://www.w3.org/2000/svg';
  const $ = (sel, el = root) => el.querySelector(sel);
  const $$ = (sel, el = root) => Array.from(el.querySelectorAll(sel));
  const svg = $('[data-board-svg]');
  const viewport = $('[data-board-viewport]');
  const edgeLayer = $('[data-board-edges]');
  const nodeLayer = $('[data-board-nodes]');
  const defs = $('[data-board-defs]');
  const canvas = $('[data-board-canvas]');
  const stageEl = $('[data-board-stage]');
  const stageStatus = $('[data-board-stage-status]');
  const body = root.querySelector('.board-body');
  const panel = $('[data-board-panel]');
  const tooltip = $('[data-board-tooltip]');
  const hint = $('[data-board-hint]');
  const clusterLayer = document.createElementNS(SVG, 'g');
  viewport.insertBefore(clusterLayer, viewport.firstChild);
  const storeKey = `deckarium-board-v2-${data.deck.id}`;
  const LAND_GROUP = '__lands__';
  const GHOST_COLOR = '#8f9a94';
  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
  const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const store = {
    read() { try { return JSON.parse(localStorage.getItem(storeKey) || '{}'); } catch { return {}; } },
    write(value) { try { localStorage.setItem(storeKey, JSON.stringify(value)); } catch { /* sem armazenamento: só não lembra */ } },
  };
  const saved = store.read();

  const nodesById = new Map(data.nodes.map((n) => [n.id, { ...n }]));
  const state = {
    allEdges: !!saved.allEdges,
    hover: null,
    groups: new Set(saved.groups || Object.keys(data.groups)),
    groupLands: saved.groupLands !== undefined ? saved.groupLands : true,
    hubs: saved.hubs !== undefined ? saved.hubs : true,
    showIsolated: !!saved.showIsolated,
    view: saved.view === '2d' ? '2d' : '3d',
    panelOpen: saved.panelOpen !== false,
    spin: saved.spin !== false,
    suggest: false,
    suggestions: null,
    added: new Set(),
    theme: null,
    focus: null,
    view2d: { x: 0, y: 0, k: 1 },
    positions: saved.positions || {},
  };

  const HUB_MIN = 7;
  const size = (node) => {
    if (node.stage === 'hub') return { w: Math.max(96, node.name.length * 7.2 + 34), h: 36 };
    if (node.stage === 'commander') return { w: 140, h: 196 };
    if (node.id === LAND_GROUP) return { w: 116, h: 116 };
    return { w: 100, h: 140 };
  };
  const groupColor = (key) => (key === 'ghost' ? GHOST_COLOR : data.groups[key]?.color || '#59625f');
  const primaryGroup = (edge) => {
    const totals = {};
    edge.reasons.forEach((r) => { if (state.groups.has(r.group)) totals[r.group] = (totals[r.group] || 0) + r.weight; });
    return Object.keys(totals).sort((a, b) => totals[b] - totals[a])[0] || null;
  };
  const withGroups = (edges) => edges
    .map((e) => ({ ...e, reasons: e.reasons.filter((r) => state.groups.has(r.group)) }))
    .filter((e) => e.reasons.length)
    .map((e) => ({ ...e, weight: e.reasons.reduce((sum, r) => sum + r.weight, 0) }));

  /* ---------- Grafo visível (filtros, terrenos agrupados, temas e sugestões) ---------- */
  let visible = { nodes: [], edges: [], real: [], ghostReal: [], byId: new Map(), neighbors: new Map(), partners: new Map() };
  const mapToVisible = new Map(); // id real -> id desenhado (terrenos agrupados apontam para o bloco)
  function computeVisible() {
    const allowed = new Set();
    [...nodesById.keys()].forEach((id) => { if (id === LAND_GROUP || id.startsWith('hub:') || nodesById.get(id).stage === 'ghost') nodesById.delete(id); });
    nodesById.forEach((n) => allowed.add(n.id));
    const real = withGroups(data.edges.filter((e) => allowed.has(e.from) && allowed.has(e.to)));
    // Parceiros reais de cada carta (para o painel, o número na carta e o destaque).
    const partners = new Map();
    const link = (map, a, b) => { if (!map.has(a)) map.set(a, new Set()); map.get(a).add(b); };
    real.forEach((e) => { link(partners, e.from, e.to); link(partners, e.to, e.from); });
    mapToVisible.clear();
    let edges = real.map((e) => ({ ...e }));
    // Terrenos cujas únicas relações são "terrenos entrando" viram um só bloco.
    const landMembers = [];
    if (state.groupLands) {
      nodesById.forEach((n) => {
        if (!n.is_land || !allowed.has(n.id)) return;
        const mine = real.filter((e) => e.from === n.id || e.to === n.id);
        if (mine.every((e) => e.from === n.id && e.reasons.every((r) => r.key === 'landfall'))) landMembers.push(n.id);
      });
    }
    const members = new Set(landMembers.length > 1 ? landMembers : []);
    if (members.size) {
      const merged = new Map();
      edges = edges.filter((e) => {
        if (!members.has(e.from)) return true;
        const current = merged.get(e.to) || { from: LAND_GROUP, to: e.to, weight: 0, reasons: [], count: 0 };
        current.count += 1;
        current.weight = Math.min(4, current.weight + e.weight * 0.35);
        if (!current.reasons.length) current.reasons = e.reasons.map((r) => ({ ...r }));
        merged.set(e.to, current);
        return false;
      });
      merged.forEach((e) => { e.reasons = e.reasons.map((r) => ({ ...r, from_text: `${e.count} terrenos entram no campo`, give: 'são terrenos' })); edges.push(e); });
      const landNodes = landMembers.map((id) => nodesById.get(id));
      landMembers.forEach((id) => mapToVisible.set(id, LAND_GROUP));
      nodesById.set(LAND_GROUP, { id: LAND_GROUP, name: `Terrenos (${landNodes.reduce((sum, n) => sum + (n.quantity || 1), 0)})`, stage: 'group', type: 'Terreno', members: landNodes, image: null, text: landNodes.map((n) => `${n.quantity > 1 ? n.quantity + '× ' : ''}${n.name}`).join('\n') });
      allowed.add(LAND_GROUP);
    }
    // Temas muito repetidos (ex.: todos os Merfolk → lordes) passam por um nó do tema.
    if (state.hubs) {
      const pairsByKey = new Map();
      edges.forEach((e) => e.reasons.forEach((r) => { if (r.group !== 'combo') pairsByKey.set(r.key, (pairsByKey.get(r.key) || 0) + 1); }));
      const hubKeys = new Set([...pairsByKey].filter(([, count]) => count >= HUB_MIN).map(([key]) => key));
      const hubNodes = new Map();
      const hubEdges = new Map();
      const addHubEdge = (from, to, reason, side) => {
        const key = `${from}>${to}`;
        const current = hubEdges.get(key) || { from, to, weight: 0, reasons: [], hub: side };
        if (!current.reasons.some((r) => r.key === reason.key)) current.reasons.push(reason);
        current.weight = Math.min(3.5, current.weight + reason.weight * 0.8);
        hubEdges.set(key, current);
      };
      const direct = [];
      edges.forEach((e) => {
        const keep = e.reasons.filter((r) => !hubKeys.has(r.key));
        e.reasons.filter((r) => hubKeys.has(r.key)).forEach((r) => {
          const hubId = `hub:${r.key}`;
          if (!hubNodes.has(hubId)) hubNodes.set(hubId, { id: hubId, name: r.label.replace(/ \(tipo escolhido\)$/, ''), stage: 'hub', group: r.group, key: r.key, providers: new Map(), consumers: new Map() });
          const hub = hubNodes.get(hubId);
          if (!hub.providers.has(e.from)) hub.providers.set(e.from, r);
          if (!hub.consumers.has(e.to)) hub.consumers.set(e.to, r);
          addHubEdge(e.from, hubId, r, 'in');
          addHubEdge(hubId, e.to, r, 'out');
        });
        if (keep.length) direct.push({ ...e, reasons: keep, weight: keep.reduce((sum, r) => sum + r.weight, 0) });
      });
      edges = [...direct, ...hubEdges.values()];
      hubNodes.forEach((hub, id) => { nodesById.set(id, hub); allowed.add(id); });
    }
    // Sugestões da coleção: cartas fantasmas ligadas por linhas próprias, fora dos agrupamentos.
    let ghostReal = [];
    const ghostPartners = new Map();
    if (state.suggest && state.suggestions) {
      ghostReal = withGroups(state.suggestions.flatMap((s) => s.edges).filter((e) => allowed.has(e.from) || allowed.has(e.to)));
      state.suggestions.forEach((s) => { nodesById.set(s.id, { ...s, stage: 'ghost' }); });
      ghostReal.forEach((e) => {
        const ghost = nodesById.get(e.from)?.stage === 'ghost' ? e.from : e.to;
        link(ghostPartners, ghost, ghost === e.from ? e.to : e.from);
      });
      ghostPartners.forEach((_, id) => allowed.add(id));
      edges.push(...ghostReal.map((e) => ({ ...e, ghost: true, from: mapToVisible.get(e.from) || e.from, to: mapToVisible.get(e.to) || e.to })));
    }
    const degree = new Map();
    const neighbors = new Map();
    edges.forEach((e) => { link(neighbors, e.from, e.to); link(neighbors, e.to, e.from); });
    allowed.forEach((id) => {
      if (id.startsWith('hub:')) degree.set(id, nodesById.get(id).providers.size + nodesById.get(id).consumers.size);
      else if (id === LAND_GROUP) degree.set(id, [...(neighbors.get(id) || [])].filter((other) => nodesById.get(other)?.stage !== 'ghost').length);
      else if (ghostPartners.has(id)) degree.set(id, ghostPartners.get(id).size);
      else degree.set(id, partners.get(id)?.size || 0);
    });
    const nodes = [...allowed]
      .filter((id) => !members.has(id))
      .map((id) => nodesById.get(id))
      .filter((n) => n && (n.stage === 'commander' || state.showIsolated || degree.get(n.id)));
    nodes.forEach((n) => { n.degree = degree.get(n.id) || 0; });
    const drawn = new Set(nodes.map((n) => n.id));
    visible = { nodes, edges: edges.filter((e) => drawn.has(e.from) && drawn.has(e.to)), real, ghostReal, byId: new Map(nodes.map((n) => [n.id, n])), neighbors, partners };
  }

  /* ---------- Blocos por tema (usados pelas duas vistas) ---------- */
  function clusterKeyOf(n) {
    if (n.stage === 'commander') return 'commander';
    if (n.stage === 'ghost') return 'ghost';
    if (n.stage === 'hub') return n.group;
    if (!n.degree) return 'none';
    const ids = n.id === LAND_GROUP ? new Set(n.members.map((m) => m.id)) : new Set([n.id]);
    const totals = {};
    visible.real.forEach((e) => {
      if (!ids.has(e.from) && !ids.has(e.to)) return;
      e.reasons.forEach((r) => { totals[r.group] = (totals[r.group] || 0) + r.weight; });
    });
    return Object.keys(totals).sort((a, b) => totals[b] - totals[a])[0] || 'none';
  }

  function flowOf(n) {
    // Positivo = mais fornece do que aproveita.
    const ids = n.id === LAND_GROUP ? new Set(n.members.map((m) => m.id)) : new Set([n.id]);
    let out = 0; let inc = 0;
    visible.real.forEach((e) => { if (ids.has(e.from) && !ids.has(e.to)) out += 1; else if (ids.has(e.to) && !ids.has(e.from)) inc += 1; });
    return out - inc;
  }

  const clusterLabel = (key) => (key === 'commander' ? 'Comandante' : key === 'none' ? 'Sem relação' : key === 'ghost' ? 'Sugestões da coleção' : data.groups[key]?.label || key);

  /** Cartas agrupadas pelo tema principal; dentro de cada bloco, quem fornece vem antes de quem aproveita. */
  function buckets() {
    const map = new Map();
    visible.nodes.forEach((n) => {
      const key = clusterKeyOf(n);
      if (!map.has(key)) map.set(key, { key, hubs: [], cards: [] });
      (n.stage === 'hub' ? map.get(key).hubs : map.get(key).cards).push(n);
    });
    const order = Object.keys(data.groups);
    const rank = (b) => (b.key === 'commander' ? -1 : b.key === 'ghost' ? 1e5 : b.key === 'none' ? 1e6 : 0);
    const list = [...map.values()].sort((a, b) => rank(a) - rank(b) || (b.cards.length + b.hubs.length) - (a.cards.length + a.hubs.length) || order.indexOf(a.key) - order.indexOf(b.key));
    list.forEach((b) => {
      b.cards.forEach((n) => { n.flow = flowOf(n); });
      b.cards.sort((a, c) => (c.stage === 'commander') - (a.stage === 'commander') || c.flow - a.flow || c.degree - a.degree || a.name.localeCompare(c.name));
      b.hubs.sort((a, c) => (c.providers.size + c.consumers.size) - (a.providers.size + a.consumers.size));
      b.label = clusterLabel(b.key);
      b.color = groupColor(b.key);
      b.count = b.cards.reduce((sum, n) => sum + (n.id === LAND_GROUP ? n.members.length : 1), 0);
    });
    return list;
  }

  /* ---------- Vista plano: layout em blocos ---------- */
  const CELL_W = 122;
  const CELL_H = 186;
  const PAD = 22;
  const HEADER = 44;
  const GAP = 56;
  let clusters = [];

  function layout(reset = false) {
    const list = buckets();
    list.forEach((b) => {
      const big = b.key === 'commander';
      b.cols = big ? 1 : Math.max(1, Math.min(7, Math.ceil(Math.sqrt(b.cards.length * 1.5))));
      b.rows = Math.ceil(b.cards.length / b.cols);
      const cellW = big ? 186 : CELL_W; const cellH = big ? 246 : CELL_H;
      b.cellW = cellW; b.cellH = cellH;
      b.hubRows = [];
      let row = []; let rowW = 0;
      const maxHubRow = Math.max(b.cols * cellW, 300);
      b.hubs.forEach((h) => {
        const w = size(h).w;
        if (row.length && rowW + w + 12 > maxHubRow) { b.hubRows.push(row); row = []; rowW = 0; }
        row.push(h); rowW += w + 12;
      });
      if (row.length) b.hubRows.push(row);
      const hubsW = Math.max(0, ...b.hubRows.map((r) => r.reduce((sum, h) => sum + size(h).w + 12, -12)));
      b.w = Math.max(b.cols * cellW, hubsW, 170) + PAD * 2;
      b.h = HEADER + b.hubRows.length * 52 + b.rows * cellH + PAD;
    });
    // Blocos em prateleiras, com largura máxima proporcional à área total.
    const area = list.reduce((sum, b) => sum + (b.w + GAP) * (b.h + GAP), 0);
    const maxRow = Math.max(1400, Math.sqrt(area) * 1.6);
    let x = 0; let y = 0; let rowH = 0;
    list.forEach((b) => {
      if (x > 0 && x + b.w > maxRow) { x = 0; y += rowH + GAP; rowH = 0; }
      b.x = x; b.y = y;
      x += b.w + GAP; rowH = Math.max(rowH, b.h);
    });
    // Posição de cada item dentro do bloco (posições arrastadas têm prioridade).
    list.forEach((b) => {
      let top = b.y + HEADER;
      b.hubRows.forEach((hubRow) => {
        const width = hubRow.reduce((sum, h) => sum + size(h).w + 12, -12);
        let left = b.x + (b.w - width) / 2;
        hubRow.forEach((h) => { h.x = left + size(h).w / 2; h.y = top + 18; left += size(h).w + 12; });
        top += 52;
      });
      const gridW = b.cols * b.cellW;
      const left = b.x + (b.w - gridW) / 2;
      b.cards.forEach((n, i) => {
        n.x = left + (i % b.cols) * b.cellW + b.cellW / 2;
        n.y = top + Math.floor(i / b.cols) * b.cellH + size(n).h / 2 + 4;
      });
      [...b.hubs, ...b.cards].forEach((n) => {
        const p = !reset && state.positions[n.id];
        if (p) { n.x = p.x; n.y = p.y; }
      });
    });
    clusters = list;
  }

  function ensureMarkers() {
    defs.innerHTML = '';
    Object.entries({ ...data.groups, ghost: { color: GHOST_COLOR } }).forEach(([key, g]) => {
      const marker = document.createElementNS(SVG, 'marker');
      marker.setAttribute('id', `board-arrow-${key}`);
      marker.setAttribute('viewBox', '0 0 10 10');
      marker.setAttribute('refX', '8.5'); marker.setAttribute('refY', '5');
      marker.setAttribute('markerWidth', '6'); marker.setAttribute('markerHeight', '6');
      marker.setAttribute('orient', 'auto-start-reverse');
      marker.innerHTML = `<path d="M0,0 L10,5 L0,10 z" fill="${g.color}"></path>`;
      defs.appendChild(marker);
    });
    const clip = document.createElementNS(SVG, 'clipPath');
    clip.setAttribute('id', 'board-card-clip');
    clip.setAttribute('clipPathUnits', 'objectBoundingBox');
    clip.innerHTML = '<rect x="0" y="0" width="1" height="1" rx="0.07" ry="0.05"></rect>';
    defs.appendChild(clip);
    const shadow = document.createElementNS(SVG, 'filter');
    shadow.setAttribute('id', 'board-card-shadow');
    shadow.setAttribute('x', '-20%'); shadow.setAttribute('y', '-20%'); shadow.setAttribute('width', '140%'); shadow.setAttribute('height', '150%');
    shadow.innerHTML = '<feDropShadow dx="0" dy="5" stdDeviation="5" flood-color="#17241c" flood-opacity=".28"></feDropShadow>';
    defs.appendChild(shadow);
  }

  function boundaryPoint(from, to) {
    const s = size(to);
    const dx = from.x - to.x; const dy = from.y - to.y;
    const hw = s.w / 2 + 5; const hh = s.h / 2 + 5;
    const scale = Math.min(Math.abs(dx) > 0.01 ? hw / Math.abs(dx) : Infinity, Math.abs(dy) > 0.01 ? hh / Math.abs(dy) : Infinity);
    return { x: to.x + dx * scale, y: to.y + dy * scale };
  }

  function edgePath(e) {
    const a = visible.byId.get(e.from); const b = visible.byId.get(e.to);
    if (!a || !b) return '';
    const reverse = visible.edges.some((o) => o.from === e.to && o.to === e.from);
    const mx = (a.x + b.x) / 2; const my = (a.y + b.y) / 2;
    const dx = b.x - a.x; const dy = b.y - a.y;
    const len = Math.sqrt(dx * dx + dy * dy) || 1;
    const bend = reverse ? 26 : 10;
    const cx = mx - (dy / len) * bend; const cy = my + (dx / len) * bend;
    const start = boundaryPoint({ x: cx, y: cy }, a);
    const end = boundaryPoint({ x: cx, y: cy }, b);
    return `M${start.x.toFixed(1)},${start.y.toFixed(1)} Q${cx.toFixed(1)},${cy.toFixed(1)} ${end.x.toFixed(1)},${end.y.toFixed(1)}`;
  }

  function render2d() {
    edgeLayer.innerHTML = '';
    nodeLayer.innerHTML = '';
    clusterLayer.innerHTML = clusters.map((b) => {
      const parts = [b.count ? `${b.count} ${b.count === 1 ? 'carta' : 'cartas'}` : '', b.hubs.length ? `${b.hubs.length} ${b.hubs.length === 1 ? 'tema' : 'temas'}` : ''].filter(Boolean).join(' · ');
      return `<g class="board-cluster${b.key === 'ghost' ? ' is-ghost' : ''}" style="--c:${b.color}"><rect x="${b.x}" y="${b.y}" width="${b.w}" height="${b.h}" rx="18"></rect><rect class="board-cluster-band" x="${b.x}" y="${b.y}" width="${b.w}" height="6" rx="3"></rect><text class="board-cluster-label" x="${b.x + PAD}" y="${b.y + 31}">${escapeHtml(b.label)}<tspan class="board-cluster-count" dx="8">${parts}</tspan></text></g>`;
    }).join('');
    visible.edges.forEach((e, i) => {
      const group = e.ghost ? 'ghost' : primaryGroup(e) || 'combo';
      const path = document.createElementNS(SVG, 'path');
      path.setAttribute('class', `board-edge group-${group}`);
      path.setAttribute('d', edgePath(e));
      path.setAttribute('stroke', groupColor(group));
      path.setAttribute('stroke-width', (1.1 + Math.min(e.weight, 4) * 0.55).toFixed(2));
      path.setAttribute('marker-end', `url(#board-arrow-${group})`);
      if (group === 'combo' || e.ghost) path.setAttribute('stroke-dasharray', e.ghost ? '3 4' : '7 4');
      path.dataset.edge = String(i);
      edgeLayer.appendChild(path);
      const hit = document.createElementNS(SVG, 'path');
      hit.setAttribute('class', 'board-edge-hit');
      hit.setAttribute('d', path.getAttribute('d'));
      hit.dataset.edge = String(i);
      edgeLayer.appendChild(hit);
    });
    visible.nodes.forEach((n) => {
      const s = size(n);
      const g = document.createElementNS(SVG, 'g');
      g.setAttribute('class', `board-node stage-${n.stage}${n.game_changer ? ' is-gc' : ''}${state.added.has(n.id) ? ' is-added' : ''}`);
      g.setAttribute('transform', `translate(${n.x.toFixed(1)},${n.y.toFixed(1)})`);
      g.setAttribute('tabindex', '0');
      g.setAttribute('role', 'button');
      g.setAttribute('aria-label', `${n.name}: ${n.degree} relações`);
      g.dataset.node = n.id;
      const x = -s.w / 2; const y = -s.h / 2;
      if (n.stage === 'hub') {
        const color = groupColor(n.group);
        g.innerHTML = `<rect class="board-hub" x="${x}" y="${y}" width="${s.w}" height="${s.h}" rx="18" style="--c:${color}"></rect><text class="board-hub-label" x="0" y="4" text-anchor="middle" style="--c:${color}">${escapeHtml(n.name)}</text><text class="board-hub-count" x="0" y="${s.h / 2 + 13}" text-anchor="middle">${n.providers.size} → ${n.consumers.size}</text>`;
        nodeLayer.appendChild(g);
        return;
      }
      let inner = `<rect class="board-node-frame" x="${x - 3}" y="${y - 3}" width="${s.w + 6}" height="${s.h + 6}" rx="8"></rect>`;
      inner += `<rect class="board-node-bg" x="${x}" y="${y}" width="${s.w}" height="${s.h}" rx="6"></rect>`;
      if (n.id === LAND_GROUP) {
        inner += `<text class="board-node-fallback" x="0" y="-6" text-anchor="middle">Terrenos</text><text class="board-node-fallback is-small" x="0" y="12" text-anchor="middle">${escapeHtml(n.members.reduce((sum, m) => sum + (m.quantity || 1), 0))} cartas</text>`;
      } else {
        inner += `<text class="board-node-fallback" x="0" y="0" text-anchor="middle">${escapeHtml(n.name.slice(0, 12))}</text>`;
        if (n.image) inner += `<image href="${escapeHtml(n.image)}" x="${x}" y="${y}" width="${s.w}" height="${s.h}" preserveAspectRatio="xMidYMid slice" clip-path="url(#board-card-clip)"></image>`;
      }
      const label = n.name.length > 20 ? `${n.name.slice(0, 19)}…` : n.name;
      inner += `<text class="board-node-label" x="0" y="${s.h / 2 + 17}" text-anchor="middle">${escapeHtml(label)}</text>`;
      if (n.degree) inner += `<g class="board-node-degree" transform="translate(${s.w / 2 - 2},${y + 2})"><circle r="10"></circle><text text-anchor="middle" y="4">${n.degree}</text></g>`;
      if (n.stage === 'ghost') inner += `<text class="board-node-stage" x="${x + 5}" y="${y + 14}">${state.added.has(n.id) ? 'candidata' : 'coleção'}</text>`;
      g.innerHTML = inner;
      const img = g.querySelector('image');
      if (img) img.addEventListener('error', () => img.remove(), { once: true });
      nodeLayer.appendChild(g);
    });
  }

  // Com as setas sob demanda, passar o mouse numa carta mostra só as setas dela.
  function applyHover2d() {
    const id = state.hover;
    $$('.board-edge, .board-edge-hit', edgeLayer).forEach((p) => {
      const e = visible.edges[Number(p.dataset.edge)];
      p.classList.toggle('is-hover', !!id && (e.from === id || e.to === id));
    });
    root.classList.toggle('edges-all', state.allEdges);
  }

  function refreshEdges() {
    $$('.board-edge, .board-edge-hit', edgeLayer).forEach((p) => p.setAttribute('d', edgePath(visible.edges[Number(p.dataset.edge)])));
  }

  function applyView2d() {
    viewport.setAttribute('transform', `translate(${state.view2d.x.toFixed(1)},${state.view2d.y.toFixed(1)}) scale(${state.view2d.k.toFixed(3)})`);
    root.classList.toggle('is-zoomed-out', state.view2d.k < 0.42);
  }

  /** Largura do painel aberto sobre o palco (0 quando fechado ou abaixo, no celular). */
  function panelWidth() {
    if (!state.panelOpen || getComputedStyle(panel).position !== 'absolute') return 0;
    return panel.getBoundingClientRect().width + 16;
  }

  function fit2d(ids = null) {
    const nodes = ids ? visible.nodes.filter((n) => ids.has(n.id)) : visible.nodes;
    if (!nodes.length) return;
    const rect = canvas.getBoundingClientRect();
    const width = rect.width - panelWidth();
    const boxes = ids ? [] : clusters;
    const xs = [...nodes.flatMap((n) => [n.x - size(n).w / 2, n.x + size(n).w / 2]), ...boxes.flatMap((b) => [b.x, b.x + b.w])];
    const ys = [...nodes.flatMap((n) => [n.y - size(n).h / 2, n.y + size(n).h / 2 + 22]), ...boxes.flatMap((b) => [b.y, b.y + b.h])];
    const minX = Math.min(...xs); const maxX = Math.max(...xs); const minY = Math.min(...ys); const maxY = Math.max(...ys);
    const k = Math.max(0.2, Math.min(1.4, Math.min((width - 60) / (maxX - minX || 1), (rect.height - 60) / (maxY - minY || 1))));
    state.view2d = { k, x: width / 2 - ((minX + maxX) / 2) * k, y: rect.height / 2 - ((minY + maxY) / 2) * k };
    applyView2d();
  }

  /* ---------- Foco, tema destacado e painel ---------- */
  const nodeName = (id) => (visible.byId.get(id) || nodesById.get(id) || { name: id }).name;
  const visibleId = (id) => (visible.byId.has(id) ? id : mapToVisible.get(id) || id);

  /** Cartas próximas da selecionada (ou as do tema destacado), já com os ids desenhados. */
  function highlightSets() {
    const focus = state.focus && visible.byId.has(state.focus) ? state.focus : null;
    let near = null;
    if (focus) {
      near = new Set([focus, ...(visible.neighbors.get(focus) || []), ...[...(visible.partners.get(focus) || [])].map(visibleId)]);
      if (focus.startsWith('hub:')) { const hub = visible.byId.get(focus); [...hub.providers.keys(), ...hub.consumers.keys()].forEach((id) => near.add(id)); }
    }
    let theme = null;
    const row = state.theme && data.balance.find((b) => b.key === state.theme);
    if (row) {
      theme = new Set([...row.providers, ...row.consumers].map(visibleId));
      visible.nodes.forEach((n) => { if (n.stage === 'hub' && n.key === row.key) theme.add(n.id); });
    }
    return { focus, near, theme, themeKey: row ? row.key : null };
  }

  const edgeTouches = (e, id) => e.from === id || e.to === id;
  const edgeHasTheme = (e, key) => e.reasons.some((r) => r.key === key);

  function applyFocus() {
    const { focus, near, theme, themeKey } = highlightSets();
    const active = near || theme;
    root.classList.toggle('has-focus', !!active);
    $$('.board-node', nodeLayer).forEach((g) => {
      const id = g.dataset.node;
      g.classList.toggle('is-focus', id === focus);
      g.classList.toggle('is-near', !!active && active.has(id) && id !== focus);
      g.classList.toggle('is-dim', !!active && !active.has(id));
    });
    $$('.board-edge, .board-edge-hit', edgeLayer).forEach((p) => {
      const e = visible.edges[Number(p.dataset.edge)];
      const on = focus ? edgeTouches(e, focus) : !!themeKey && edgeHasTheme(e, themeKey);
      p.classList.toggle('is-on', on);
      p.classList.toggle('is-dim', !!active && !on);
    });
    applyHover2d();
    three?.setFocus({ focus, near, theme, themeKey });
    renderPanel(focus);
  }

  function reasonHtml(reason, fromName, toName) {
    const color = groupColor(reason.group);
    const tag = reason.source === 'tag' ? '<small class="board-source">Scryfall Tagger</small>' : reason.source === 'combo' ? '<small class="board-source">Combo</small>' : '';
    return `<div class="board-reason" style="--c:${color}"><p><b class="board-chip" style="--c:${color}">${escapeHtml(reason.label)}</b>${escapeHtml(fromName)} ${escapeHtml(reason.give)} <span aria-hidden="true">→</span> ${escapeHtml(toName)} ${escapeHtml(reason.take)} ${tag}</p><q>${escapeHtml(reason.from_text)}</q><q>${escapeHtml(reason.to_text)}</q></div>`;
  }

  const panelHead = (inner, extra = '') => `<div class="board-panel-head${extra}">${inner}<button type="button" class="board-close" data-board-clear aria-label="Fechar detalhes">×</button></div>`;

  function renderPanel(focus) {
    if (!focus) { renderOverview(); return; }
    const n = visible.byId.get(focus);
    if (n.stage === 'hub') { renderHubPanel(n); return; }
    const ids = n.id === LAND_GROUP ? new Set(n.members.map((m) => m.id)) : new Set([n.id]);
    const pool = [...visible.real, ...visible.ghostReal];
    const outgoing = pool.filter((e) => ids.has(e.from) && !ids.has(e.to)).sort((a, b) => b.weight - a.weight);
    const incoming = pool.filter((e) => ids.has(e.to) && !ids.has(e.from)).sort((a, b) => b.weight - a.weight);
    const stageLabel = { commander: 'Comandante', deck: 'No deck', candidate: 'Candidata', group: 'Grupo', ghost: 'Sugestão da coleção' }[n.stage] || '';
    const self = n.id === LAND_GROUP ? 'Terreno' : 'Esta carta';
    const isGhost = (id) => nodesById.get(id)?.stage === 'ghost';
    const list = (edges, dir) => edges.map((e) => {
      const other = dir === 'out' ? e.to : e.from;
      const origin = n.id === LAND_GROUP ? nodeName(dir === 'out' ? e.from : e.to) : null;
      return `<li${isGhost(other) ? ' class="is-ghost"' : ''}><button type="button" class="board-partner" data-board-goto="${escapeHtml(other)}">${escapeHtml(nodeName(other))}</button>${isGhost(other) ? '<small class="board-origin">sugestão da coleção</small>' : ''}${origin ? `<small class="board-origin">de ${escapeHtml(origin)}</small>` : ''}${e.reasons.map((r) => reasonHtml(r, dir === 'out' ? (origin || self) : nodeName(other), dir === 'out' ? nodeName(other) : (origin || 'esta carta'))).join('')}</li>`;
    }).join('');
    const facts = [
      n.provides?.length ? `<p><em>Fornece</em> ${escapeHtml(n.provides.join(', '))}</p>` : '',
      n.consumes?.length ? `<p><em>Aproveita</em> ${escapeHtml(n.consumes.join(', '))}</p>` : '',
      n.roles?.length ? `<p><em>Função</em> ${escapeHtml(n.roles.join(', '))}</p>` : '',
    ].join('');
    const ghostActions = n.stage === 'ghost'
      ? `<div class="board-ghost-actions"><p>${n.owned ? `Você tem ${n.owned} ${n.owned === 1 ? 'cópia' : 'cópias'} na coleção.` : 'Está na sua coleção.'} Ligaria a ${n.partners} ${n.partners === 1 ? 'carta' : 'cartas'} do deck.</p>
         ${state.added.has(n.id) ? '<p class="board-note is-ok">Adicionada às candidatas. Aprove em Minha seleção para ela entrar no quadro.</p>' : `<button type="button" class="primary-link" data-board-add="${escapeHtml(n.id)}">Adicionar às candidatas</button>`}</div>`
      : '';
    panel.innerHTML = `
      ${panelHead(`${n.image ? `<img src="${escapeHtml(n.image)}" alt="" width="92" height="128" loading="lazy">` : ''}
        <div><span class="board-stage-tag stage-${n.stage}">${stageLabel}</span><h2>${escapeHtml(n.name)}</h2><p class="board-type">${escapeHtml(n.type_line || n.type || '')}</p>
        ${n.url && n.id !== LAND_GROUP ? `<a href="${escapeHtml(n.url)}">Abrir carta</a>` : ''}</div>`)}
      ${ghostActions}
      <details class="board-text"${n.id === LAND_GROUP ? ' open' : ''}><summary>${n.id === LAND_GROUP ? 'Terrenos agrupados' : 'Texto da carta'}</summary><p>${escapeHtml(n.text || '').replace(/\n/g, '<br>')}</p></details>
      <div class="board-facts">${facts}</div>
      <section><h3>Fornece para <b>${outgoing.length}</b></h3>${outgoing.length ? `<ul class="board-relations">${list(outgoing, 'out')}</ul>` : '<p class="board-empty">Nenhuma carta visível aproveita o que esta carta fornece.</p>'}</section>
      <section><h3>Aproveita de <b>${incoming.length}</b></h3>${incoming.length ? `<ul class="board-relations">${list(incoming, 'in')}</ul>` : '<p class="board-empty">Nenhuma carta visível fornece o que esta carta aproveita.</p>'}</section>`;
  }

  function renderHubPanel(hub) {
    const color = groupColor(hub.group);
    const side = (entries, dir) => [...entries].map(([id, r]) => `<li><button type="button" class="board-partner" data-board-goto="${escapeHtml(id)}">${escapeHtml(nodeName(id))}</button><div class="board-reason" style="--c:${color}"><p>${escapeHtml(dir === 'in' ? r.give : r.take)}</p><q>${escapeHtml(dir === 'in' ? r.from_text : r.to_text)}</q></div></li>`).join('');
    panel.innerHTML = `
      ${panelHead(`<div><span class="board-stage-tag" style="--c:${color}">Tema</span><h2>${escapeHtml(hub.name)}</h2><p class="board-type">${escapeHtml(data.groups[hub.group]?.label || '')} · ${hub.providers.size} fornecem, ${hub.consumers.size} aproveitam</p></div>`, ' is-hub')}
      <p class="board-note">Todas as cartas de “Fornecem” se ligam a todas as de “Aproveitam” por este tema. Desligue “Agrupar por tema” em Exibição para ver cada linha.</p>
      <section><h3>Fornecem <b>${hub.providers.size}</b></h3><ul class="board-relations">${side(hub.providers, 'in')}</ul></section>
      <section><h3>Aproveitam <b>${hub.consumers.size}</b></h3><ul class="board-relations">${side(hub.consumers, 'out')}</ul></section>`;
  }

  function comboItem(combo, missing = false) {
    const pieces = combo.cards.map((name) => `<span class="${missing && name === combo.missing ? 'is-missing' : ''}">${escapeHtml(name)}</span>`).join(' + ');
    const results = (Array.isArray(combo.results) ? combo.results : [combo.result]).filter(Boolean).slice(0, 3).map(escapeHtml).join(' · ');
    const owned = missing ? `<small class="${combo.missing_owned ? 'is-owned' : ''}">${combo.missing_owned ? `Você tem ${combo.missing_owned} cópia(s) de ${escapeHtml(combo.missing)}` : `Falta ${escapeHtml(combo.missing)} (fora da coleção)`}</small>` : '';
    return `<li><p class="board-combo-cards">${pieces}</p>${results ? `<p class="board-combo-result">${results}</p>` : ''}${owned}${combo.href ? `<a href="${escapeHtml(combo.href)}" target="_blank" rel="noopener">Ver passo a passo</a>` : ''}</li>`;
  }

  /** Diagnóstico de cada tema: quem fornece × quem aproveita, com as pontas soltas primeiro. */
  function balanceRows() {
    return data.balance.filter((row) => state.groups.has(row.group)).map((row) => {
      const p = row.providers.length; const c = row.consumers.length;
      let status = null;
      if (c >= 1 && p === 0) status = { kind: 'warn', rank: 3, tag: 'Falta fonte', hint: `Falta carta que ${row.give}.` };
      else if (c >= 2 && p === 1) status = { kind: 'warn', rank: 2, tag: 'Fonte única', hint: `Só uma carta ${row.give}; se ela sair, ${c} perdem o apoio.` };
      else if (!row.passive && p >= 3 && c === 0) status = { kind: 'idle', rank: 1, tag: 'Sem retorno', hint: `Falta carta que ${row.take}.` };
      else if (p >= 1 && c >= 1) status = { kind: 'ok', rank: 0, tag: 'Motor', hint: '' };
      return status ? { ...row, p, c, ...status } : null;
    }).filter(Boolean).sort((a, b) => b.rank - a.rank || (a.rank ? b.c + b.p - a.c - a.p : Math.min(b.p, b.c) - Math.min(a.p, a.c)));
  }

  function renderBalance() {
    const rows = balanceRows();
    if (!rows.length) return '<p class="board-empty">Nenhum tema com quem fornece e quem aproveita entre as cartas do deck.</p>';
    const warnings = rows.filter((r) => r.kind !== 'ok');
    const motors = rows.filter((r) => r.kind === 'ok').slice(0, Math.max(4, 8 - warnings.length));
    const max = Math.max(...rows.map((r) => Math.max(r.p, r.c)), 1);
    const item = (r) => `<li><button type="button" class="board-balance-row is-${r.kind}${state.theme === r.key ? ' is-on' : ''}" data-board-theme="${escapeHtml(r.key)}" aria-pressed="${state.theme === r.key}" style="--c:${groupColor(r.group)}">
        <span class="board-balance-head"><span class="board-balance-name">${escapeHtml(r.label)}<em>${r.tag}</em></span><span class="board-balance-nums"><b title="Fornecem">${r.p}</b><span aria-hidden="true">→</span><b title="Aproveitam">${r.c}</b></span></span>
        <span class="board-balance-bars" aria-hidden="true"><i class="is-give" style="--w:${(r.p / max) * 100}%"></i><i class="is-take" style="--w:${(r.c / max) * 100}%"></i></span>
        ${r.hint ? `<small>${escapeHtml(r.hint)}</small>` : ''}</button></li>`;
    return `<p class="board-balance-legend"><span><i class="is-give"></i>fornecem</span><span>clique para destacar</span><span>aproveitam<i class="is-take"></i></span></p>
      <ul class="board-balance">${[...warnings, ...motors].map(item).join('')}</ul>
      ${warnings.length ? `<p class="board-note">Para fechar as pontas soltas, veja <a href="${escapeHtml(data.explore)}">o que encaixa no deck</a> ou ligue as sugestões da coleção.</p>` : ''}`;
  }

  function renderSuggestionsSummary() {
    if (!state.suggestions) {
      return `<p class="board-note">Mostra, em volta do deck, as cartas da sua coleção que mais se ligariam a ele — na identidade da comandante e legais no Commander.</p>
        <button type="button" class="secondary-link" data-board-suggest-inline>${state.suggest ? 'Calculando…' : 'Mostrar sugestões da coleção'}</button>`;
    }
    if (!state.suggestions.length) return '<p class="board-empty">Nenhuma carta da coleção se liga a este deck além das que já estão nele.</p>';
    return `<ol class="board-suggestions">${state.suggestions.slice(0, 8).map((s) => `<li><button type="button" class="board-partner" data-board-goto="${escapeHtml(s.id)}">${escapeHtml(s.name)}</button><b>${s.partners}</b>${state.added.has(s.id) ? '<small>candidata</small>' : ''}</li>`).join('')}</ol>
      ${state.suggest ? '' : '<button type="button" class="secondary-link" data-board-suggest-inline>Mostrar no quadro</button>'}`;
  }

  function renderOverview() {
    const counts = {};
    visible.real.forEach((e) => e.reasons.forEach((r) => { counts[r.group] = (counts[r.group] || 0) + 1; }));
    const deckNodes = visible.nodes.filter((n) => n.stage !== 'hub' && n.stage !== 'ghost');
    const top = deckNodes.filter((n) => n.id !== LAND_GROUP).slice().sort((a, b) => b.degree - a.degree).slice(0, 6);
    const loose = data.nodes.filter((n) => n.stage === 'deck' && !n.is_land && !(visible.partners.get(n.id)?.size));
    const spell = data.spellbook;
    const spellSection = spell
      ? `<p class="board-note">${spell.included.length ? `${spell.included.length} combo(s) completo(s) com estas cartas — listados acima e desenhados com linhas tracejadas.` : 'Nenhum combo completo com estas cartas.'}</p>
         ${spell.almost.length ? `<h4>Falta 1 carta</h4><ul class="board-combos">${spell.almost.slice(0, 12).map((c) => comboItem(c, true)).join('')}</ul>` : ''}
         <p class="board-note">Consulta de ${escapeHtml(new Date(spell.synced_at).toLocaleString('pt-BR'))}. Dados do <a href="https://commanderspellbook.com" target="_blank" rel="noopener">Commander Spellbook</a>.</p>`
      : '<p class="board-empty">Ainda não consultado para esta lista.</p>';
    panel.innerHTML = `
      <div class="board-summary">
        <h2>Visão geral</h2>
        <dl><div><dt>Cartas</dt><dd>${deckNodes.length}</dd></div><div><dt>Relações</dt><dd>${visible.real.length}</dd></div><div><dt>Tribo principal</dt><dd>${escapeHtml(data.tribe || '—')}</dd></div></dl>
        <h3>Equilíbrio dos temas</h3>
        ${renderBalance()}
        <h3>Mais conectadas</h3>
        <ol class="board-hubs">${top.map((n) => `<li><button type="button" class="board-partner" data-board-goto="${escapeHtml(n.id)}">${escapeHtml(n.name)}</button><b>${n.degree}</b></li>`).join('')}</ol>
        ${loose.length ? `<h3>Sem relação <b>${loose.length}</b></h3><p class="board-note">Não fornecem nem aproveitam nada das outras cartas. Podem ser remoção e compra genéricas — ou as primeiras a sair num upgrade.</p>
          <ul class="board-loose">${loose.slice(0, 12).map((n) => `<li><button type="button" class="board-partner" data-board-goto="${escapeHtml(n.id)}">${escapeHtml(n.name)}</button></li>`).join('')}</ul>` : ''}
        <h3>Sugestões da coleção</h3>
        <div data-board-suggest-summary>${renderSuggestionsSummary()}</div>
        <h3>Combos</h3>
        ${data.combos.length ? `<ul class="board-combos">${data.combos.map((c) => comboItem({ ...c, cards: c.names })).join('')}</ul>` : '<p class="board-empty">Nenhum combo conhecido do EDHREC com todas as peças aqui.</p>'}
        <div class="board-spellbook" data-board-spellbook>${spellSection}
          <button type="button" class="secondary-link" data-board-spellbook-fetch>${spell ? 'Atualizar no Commander Spellbook' : 'Buscar combos no Commander Spellbook'}</button>
        </div>
        <h3>Como ler</h3>
        <p class="board-note">A linha sai de quem <strong>fornece</strong> e chega em quem <strong>aproveita</strong>; na constelação, as faíscas correm nesse sentido. Linhas tracejadas no plano são combos. O número na carta é quantas relações ela tem com o que está visível.</p>
        ${data.tags ? `<p class="board-note">${data.tags.toLocaleString('pt-BR')} tags do Scryfall Tagger complementam o texto.</p>` : '<p class="board-note">Para relações mais completas, sincronize as tags do Scryfall Tagger: <code>php bin/sync_tagger.php</code>.</p>'}
        <p class="board-note">Distribuição: ${Object.entries(counts).sort((a, b) => b[1] - a[1]).map(([g, c]) => `${escapeHtml(data.groups[g]?.label || g)} ${c}`).join(' · ') || '—'}</p>
      </div>`;
  }

  function updateChips() {
    const totals = {};
    data.edges.forEach((e) => e.reasons.forEach((r) => { totals[r.group] = (totals[r.group] || 0) + 1; }));
    const chips = $('[data-board-chips]');
    chips.innerHTML = Object.entries(data.groups).filter(([key]) => totals[key]).map(([key, g]) => `<button type="button" class="board-group-chip${state.groups.has(key) ? ' is-on' : ''}" style="--c:${g.color}" data-board-group="${key}" aria-pressed="${state.groups.has(key)}"><i></i>${escapeHtml(g.label)} <b>${totals[key]}</b></button>`).join('')
      + '<button type="button" class="board-group-all" data-board-group-all>Todas</button>';
  }

  function focusNode(id, center = true) {
    state.focus = id;
    if (id && !state.panelOpen) setPanel(true);
    applyFocus();
    if (center && id && visible.byId.has(id) && state.view === '2d') {
      const n = visible.byId.get(id);
      const rect = canvas.getBoundingClientRect();
      state.view2d.x = (rect.width - panelWidth()) / 2 - n.x * state.view2d.k;
      state.view2d.y = rect.height / 2 - n.y * state.view2d.k;
      applyView2d();
    }
  }

  function persist() {
    store.write({ allEdges: state.allEdges, groups: [...state.groups], groupLands: state.groupLands, hubs: state.hubs, showIsolated: state.showIsolated, positions: state.positions, view: state.view, panelOpen: state.panelOpen, spin: state.spin });
  }

  function rebuild(reset = false) {
    computeVisible();
    layout(reset);
    render2d();
    three?.update(reset);
    applyFocus();
    updateChips();
  }

  /* ---------- Constelação 3D (carregada só quando a vista é escolhida) ---------- */
  let three = null;
  let threeLoading = null;
  const api = {
    data,
    state,
    get visible() { return visible; },
    buckets,
    groupColor,
    primaryGroup,
    panelWidth,
    LAND_GROUP,
    reducedMotion: () => reducedMotion.matches,
    select: (id) => focusNode(id, false),
    hover: (id) => { state.hover = id; },
  };

  function ensureThree() {
    if (three) return Promise.resolve(three);
    if (!threeLoading) {
      stageStatus.hidden = false;
      // Os nomes impressos no tapete usam a Spectral: espera a fonte antes de desenhar.
      const font = document.fonts ? document.fonts.load('700 48px Spectral').catch(() => null) : Promise.resolve();
      threeLoading = Promise.all([import(data.three), font])
        .then(([module]) => module.createBoard3D(stageEl, api))
        .then((controller) => { three = controller; stageStatus.hidden = true; return controller; })
        .catch((error) => {
          console.error(error);
          stageStatus.textContent = 'Este navegador não conseguiu desenhar a constelação 3D. O plano continua disponível.';
          threeLoading = null;
          setView('2d', false);
          return null;
        });
    }
    return threeLoading;
  }

  const hints = {
    '3d': 'Arraste para girar · botão direito ou Shift + arrastar para mover · roda do mouse para aproximar · clique numa carta para ver os motivos',
    '2d': 'Passe o mouse ou clique numa carta para ver as setas · arraste o fundo para mover · roda do mouse para zoom',
  };

  function setView(view, remember = true) {
    state.view = view;
    if (remember) persist();
    root.dataset.view = view;
    canvas.hidden = view !== '2d';
    stageEl.hidden = view !== '3d';
    tooltip.hidden = true;
    hint.textContent = hints[view];
    $$('[data-board-view]').forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.boardView === view)));
    $$('[data-board-only]').forEach((el) => { el.hidden = el.dataset.boardOnly !== view; });
    if (view === '3d') {
      ensureThree().then((controller) => { if (controller && state.view === '3d') { controller.setActive(true); applyFocus(); } });
    } else {
      three?.setActive(false);
      fit2d();
    }
  }

  function setPanel(open) {
    state.panelOpen = open;
    root.classList.toggle('panel-closed', !open);
    const toggle = $('[data-board-panel-toggle]');
    toggle.setAttribute('aria-expanded', String(open));
    $('[data-board-panel-label]').textContent = open ? 'Ocultar painel' : 'Mostrar painel';
    persist();
    three?.resize();
  }

  /* ---------- Sugestões da coleção ---------- */
  async function post(url, fields) {
    const response = await fetch(url, { method: 'POST', body: new URLSearchParams(fields), headers: { 'X-Requested-With': 'fetch' } });
    const result = await response.json().catch(() => ({ ok: false, message: 'Resposta inesperada do servidor.' }));
    if (!result.ok) throw new Error(result.message || 'Falha na consulta.');
    return result;
  }

  const suggestButton = $('[data-board-suggest]');
  async function toggleSuggestions(force = null) {
    const on = force ?? !state.suggest;
    state.suggest = on;
    suggestButton.setAttribute('aria-pressed', String(on));
    if (on && !state.suggestions) {
      suggestButton.setAttribute('aria-busy', 'true');
      suggestButton.textContent = 'Calculando sugestões…';
      if (!state.focus) renderOverview();
      try {
        const result = await post(window.location.pathname + window.location.search, { csrf: data.csrf, action: 'suggest', deck: String(data.deck.id) });
        state.suggestions = result.suggestions || [];
      } catch (error) {
        state.suggest = false;
        suggestButton.setAttribute('aria-pressed', 'false');
        suggestButton.removeAttribute('aria-busy');
        suggestButton.textContent = 'Sugestões da coleção';
        if (!state.focus) { renderOverview(); $('[data-board-suggest-summary]')?.insertAdjacentHTML('afterbegin', `<p class="board-note is-error">${escapeHtml(error.message)}</p>`); }
        return;
      }
      suggestButton.removeAttribute('aria-busy');
    }
    suggestButton.textContent = on && state.suggestions ? `Sugestões da coleção · ${state.suggestions.length}` : 'Sugestões da coleção';
    if (!on && state.focus && nodesById.get(state.focus)?.stage === 'ghost') state.focus = null;
    rebuild();
  }

  async function addCandidate(button) {
    const id = button.dataset.boardAdd;
    button.disabled = true;
    button.textContent = 'Adicionando…';
    try {
      await post('/decks.php', { csrf: data.csrf, deck: String(data.deck.id), action: 'add', card: id });
      state.added.add(id);
      render2d();
      three?.markAdded(id);
      applyFocus();
    } catch (error) {
      button.disabled = false;
      button.textContent = 'Tentar de novo';
      button.insertAdjacentHTML('beforebegin', `<p class="board-note is-error">${escapeHtml(error.message)}</p>`);
    }
  }

  /* ---------- Interação no plano ---------- */
  let drag = null;
  svg.addEventListener('pointerdown', (event) => {
    const nodeEl = event.target.closest('.board-node');
    svg.setPointerCapture(event.pointerId);
    if (nodeEl) {
      const n = visible.byId.get(nodeEl.dataset.node);
      drag = { type: 'node', node: n, el: nodeEl, startX: event.clientX, startY: event.clientY, ox: n.x, oy: n.y, moved: false };
    } else {
      drag = { type: 'pan', startX: event.clientX, startY: event.clientY, vx: state.view2d.x, vy: state.view2d.y, moved: false };
      canvas.classList.add('is-panning');
    }
  });
  svg.addEventListener('pointermove', (event) => {
    if (!drag) {
      const over = event.target.closest('.board-node')?.dataset.node || null;
      if (over !== state.hover) { state.hover = over; applyHover2d(); }
      hoverEdge(event);
      return;
    }
    const dx = event.clientX - drag.startX; const dy = event.clientY - drag.startY;
    if (Math.abs(dx) + Math.abs(dy) > 4) drag.moved = true;
    if (drag.type === 'pan') {
      state.view2d.x = drag.vx + dx; state.view2d.y = drag.vy + dy; applyView2d();
    } else if (drag.moved) {
      drag.node.x = drag.ox + dx / state.view2d.k; drag.node.y = drag.oy + dy / state.view2d.k;
      drag.el.setAttribute('transform', `translate(${drag.node.x.toFixed(1)},${drag.node.y.toFixed(1)})`);
      refreshEdges();
    }
  });
  const endDrag = (event) => {
    if (!drag) return;
    canvas.classList.remove('is-panning');
    if (drag.type === 'node') {
      if (drag.moved) { state.positions[drag.node.id] = { x: Math.round(drag.node.x), y: Math.round(drag.node.y) }; persist(); }
      else focusNode(state.focus === drag.node.id ? null : drag.node.id, false);
    } else if (!drag.moved && !event.target.closest('.board-edge-hit')) {
      focusNode(null, false);
    } else if (!drag.moved) {
      const e = visible.edges[Number(event.target.closest('.board-edge-hit').dataset.edge)];
      if (e) focusNode(e.to, false);
    }
    drag = null;
  };
  svg.addEventListener('pointerup', endDrag);
  svg.addEventListener('pointercancel', () => { drag = null; canvas.classList.remove('is-panning'); });
  svg.addEventListener('wheel', (event) => {
    event.preventDefault();
    const rect = canvas.getBoundingClientRect();
    const mx = event.clientX - rect.left; const my = event.clientY - rect.top;
    const k = Math.max(0.15, Math.min(2.5, state.view2d.k * Math.exp(-event.deltaY * 0.0015)));
    state.view2d.x = mx - ((mx - state.view2d.x) / state.view2d.k) * k;
    state.view2d.y = my - ((my - state.view2d.y) / state.view2d.k) * k;
    state.view2d.k = k;
    applyView2d();
  }, { passive: false });

  function hoverEdge(event) {
    const hit = event.target.closest('.board-edge-hit');
    if (!hit) { tooltip.hidden = true; return; }
    const e = visible.edges[Number(hit.dataset.edge)];
    if (!e) return;
    const rect = canvas.getBoundingClientRect();
    const text = (r) => (e.hub === 'in' ? r.give : e.hub === 'out' ? r.take : `${r.give} → ${r.take}`);
    tooltip.innerHTML = `<strong>${escapeHtml(nodeName(e.from))} → ${escapeHtml(nodeName(e.to))}</strong>${e.reasons.map((r) => `<span style="--c:${groupColor(r.group)}"><b>${escapeHtml(r.label)}</b> ${escapeHtml(text(r))}</span>`).join('')}`;
    tooltip.hidden = false;
    tooltip.style.left = `${Math.min(rect.width - 280, event.clientX - rect.left + 14)}px`;
    tooltip.style.top = `${Math.min(rect.height - 120, event.clientY - rect.top + 14)}px`;
  }
  svg.addEventListener('pointerleave', () => { tooltip.hidden = true; if (state.hover) { state.hover = null; applyHover2d(); } });
  svg.addEventListener('keydown', (event) => {
    const nodeEl = event.target.closest('.board-node');
    if (nodeEl && (event.key === 'Enter' || event.key === ' ')) { event.preventDefault(); focusNode(nodeEl.dataset.node); }
  });

  /* ---------- Controles ---------- */
  const display = $('.board-display');
  document.addEventListener('click', (event) => { if (display.open && !display.contains(event.target)) display.open = false; });
  document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    if (display.open) { display.open = false; return; }
    if (state.focus) focusNode(null, false);
    else if (state.theme) { state.theme = null; applyFocus(); }
  });

  // Passar o mouse num nome do painel acende a carta no palco.
  panel.addEventListener('pointerover', (event) => {
    const target = event.target.closest('[data-board-goto]');
    const id = target ? visibleId(target.dataset.boardGoto) : null;
    if (state.view === '3d') three?.setHover(id);
    else if (id !== state.hover) { state.hover = id; applyHover2d(); }
  });
  panel.addEventListener('pointerleave', () => { if (state.view === '3d') three?.setHover(null); });

  root.addEventListener('click', async (event) => {
    const go = event.target.closest('[data-board-goto]');
    if (go) {
      const target = go.dataset.boardGoto;
      if (!visible.byId.has(visibleId(target)) && nodesById.has(target)) { state.showIsolated = true; isolatedToggle.checked = true; persist(); rebuild(); }
      focusNode(visibleId(target));
      return;
    }
    if (event.target.closest('[data-board-clear]')) { focusNode(null, false); return; }
    const view = event.target.closest('[data-board-view]');
    if (view) { setView(view.dataset.boardView); return; }
    const theme = event.target.closest('[data-board-theme]');
    if (theme) { state.theme = state.theme === theme.dataset.boardTheme ? null : theme.dataset.boardTheme; applyFocus(); return; }
    const add = event.target.closest('[data-board-add]');
    if (add) { addCandidate(add); return; }
    if (event.target.closest('[data-board-suggest], [data-board-suggest-inline]')) { toggleSuggestions(event.target.closest('[data-board-suggest-inline]') ? true : null); return; }
    if (event.target.closest('[data-board-panel-toggle]')) { setPanel(!state.panelOpen); return; }
    if (event.target.closest('[data-board-fullscreen]')) { toggleFullscreen(); return; }
    const chip = event.target.closest('[data-board-group]');
    if (chip) {
      const key = chip.dataset.boardGroup;
      if (state.groups.has(key) && state.groups.size === Object.keys(data.groups).length) state.groups = new Set([key]);
      else if (state.groups.has(key)) state.groups.delete(key);
      else state.groups.add(key);
      if (!state.groups.size) state.groups = new Set(Object.keys(data.groups));
      persist(); rebuild(); return;
    }
    if (event.target.closest('[data-board-group-all]')) { state.groups = new Set(Object.keys(data.groups)); persist(); rebuild(); return; }
    const zoom = event.target.closest('[data-board-zoom]');
    if (zoom) {
      const dir = Number(zoom.dataset.boardZoom);
      if (state.view === '3d') { if (dir === 0) three?.fit(); else three?.zoom(dir); return; }
      if (dir === 0) { fit2d(); return; }
      const rect = canvas.getBoundingClientRect();
      const k = Math.max(0.15, Math.min(2.5, state.view2d.k * (dir > 0 ? 1.25 : 0.8)));
      state.view2d.x = rect.width / 2 - ((rect.width / 2 - state.view2d.x) / state.view2d.k) * k;
      state.view2d.y = rect.height / 2 - ((rect.height / 2 - state.view2d.y) / state.view2d.k) * k;
      state.view2d.k = k; applyView2d(); return;
    }
    if (event.target.closest('[data-board-layout]')) { state.positions = {}; persist(); rebuild(true); if (state.view === '3d') three?.fit(); else fit2d(); return; }
    const fetchButton = event.target.closest('[data-board-spellbook-fetch]');
    if (fetchButton) {
      fetchButton.disabled = true;
      fetchButton.textContent = 'Consultando o Commander Spellbook…';
      try {
        await post(window.location.pathname + window.location.search, { csrf: data.csrf, action: 'spellbook', deck: String(data.deck.id) });
        // Recarrega para incluir as linhas dos combos encontrados.
        window.location.reload();
      } catch (error) {
        fetchButton.disabled = false;
        fetchButton.textContent = 'Tentar de novo';
        const note = document.createElement('p');
        note.className = 'board-note is-error';
        note.textContent = error.message;
        fetchButton.before(note);
      }
    }
  });

  // Tela cheia de verdade quando o navegador deixa; senão, o quadro ocupa a janela.
  const fullscreenButton = $('[data-board-fullscreen]');
  function syncFullscreen(on) {
    root.classList.toggle('is-fullscreen', on);
    fullscreenButton.setAttribute('aria-pressed', String(on));
    fullscreenButton.textContent = on ? 'Sair da tela cheia' : 'Tela cheia';
    document.documentElement.classList.toggle('board-fullscreen-lock', on && document.fullscreenElement !== root);
    requestAnimationFrame(() => { three?.resize(); if (state.view === '2d') fit2d(); });
  }
  function toggleFullscreen() {
    if (document.fullscreenElement === root) { document.exitFullscreen(); return; }
    if (root.classList.contains('is-fullscreen')) { syncFullscreen(false); return; }
    if (root.requestFullscreen) root.requestFullscreen().catch(() => syncFullscreen(true));
    else syncFullscreen(true);
  }
  document.addEventListener('fullscreenchange', () => syncFullscreen(document.fullscreenElement === root));

  const allEdgesToggle = $('[data-board-all-edges]');
  allEdgesToggle.checked = state.allEdges;
  allEdgesToggle.addEventListener('change', () => { state.allEdges = allEdgesToggle.checked; persist(); applyHover2d(); });
  const hubsToggle = $('[data-board-hubs]');
  hubsToggle.checked = state.hubs;
  hubsToggle.addEventListener('change', () => { state.hubs = hubsToggle.checked; persist(); rebuild(true); if (state.view === '2d') fit2d(); });
  const landsToggle = $('[data-board-lands]');
  landsToggle.checked = state.groupLands;
  landsToggle.addEventListener('change', () => { state.groupLands = landsToggle.checked; persist(); rebuild(); if (state.view === '2d') fit2d(); });
  const isolatedToggle = $('[data-board-isolated]');
  isolatedToggle.checked = state.showIsolated;
  isolatedToggle.addEventListener('change', () => { state.showIsolated = isolatedToggle.checked; persist(); rebuild(); if (state.view === '2d') fit2d(); });
  const spinToggle = $('[data-board-spin]');
  spinToggle.checked = state.spin;
  spinToggle.addEventListener('change', () => { state.spin = spinToggle.checked; persist(); });
  const search = $('[data-board-search]');
  $('#board-card-names').innerHTML = data.nodes.map((n) => `<option value="${escapeHtml(n.name)}"></option>`).join('');
  search.addEventListener('change', () => {
    const wanted = search.value.trim().toLowerCase();
    if (!wanted) return;
    const match = data.nodes.find((n) => n.name.toLowerCase() === wanted) || data.nodes.find((n) => n.name.toLowerCase().includes(wanted));
    if (!match) return;
    if (!visible.byId.has(visibleId(match.id))) { state.showIsolated = true; isolatedToggle.checked = true; rebuild(); }
    focusNode(visibleId(match.id));
  });

  ensureMarkers();
  root.classList.toggle('panel-closed', !state.panelOpen);
  $('[data-board-panel-toggle]').setAttribute('aria-expanded', String(state.panelOpen));
  $('[data-board-panel-label]').textContent = state.panelOpen ? 'Ocultar painel' : 'Mostrar painel';
  computeVisible();
  layout();
  render2d();
  updateChips();
  if (data.focus && !visible.byId.has(visibleId(data.focus)) && nodesById.has(data.focus)) { state.showIsolated = true; isolatedToggle.checked = true; computeVisible(); layout(); render2d(); }
  setView(state.view, false);
  if (data.focus) focusNode(visibleId(data.focus));
  else applyFocus();
  window.addEventListener('resize', () => { if (state.view === '2d') applyView2d(); });
})();
