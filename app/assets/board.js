/* Quadro de relações do Deckarium: layout por forças, setas A → B, foco, filtros e combos. */
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
  const panel = $('[data-board-panel]');
  const tooltip = $('[data-board-tooltip]');
  const storeKey = `deckarium-board-${data.deck.id}`;
  const LAND_GROUP = '__lands__';
  const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const store = {
    read() { try { return JSON.parse(localStorage.getItem(storeKey) || '{}'); } catch { return {}; } },
    write(value) { try { localStorage.setItem(storeKey, JSON.stringify(value)); } catch { /* sem armazenamento: só não lembra */ } },
  };
  const saved = store.read();

  const nodesById = new Map(data.nodes.map((n) => [n.id, { ...n }]));
  const state = {
    stages: new Set(saved.stages || ['deck', 'candidate']),
    groups: new Set(saved.groups || Object.keys(data.groups)),
    groupLands: saved.groupLands !== undefined ? saved.groupLands : true,
    hubs: saved.hubs !== undefined ? saved.hubs : true,
    showIsolated: !!saved.showIsolated,
    focus: null,
    view: { x: 0, y: 0, k: 1 },
    positions: saved.positions || {},
  };

  const HUB_MIN = 7;
  const size = (node) => {
    if (node.stage === 'hub') return { w: Math.max(96, node.name.length * 7.2 + 34), h: 36 };
    if (node.stage === 'commander') return { w: 104, h: 145 };
    if (node.id === LAND_GROUP) return { w: 92, h: 92 };
    return { w: 70, h: 98 };
  };
  const primaryGroup = (edge) => {
    const totals = {};
    edge.reasons.forEach((r) => { if (state.groups.has(r.group)) totals[r.group] = (totals[r.group] || 0) + r.weight; });
    return Object.keys(totals).sort((a, b) => totals[b] - totals[a])[0] || null;
  };

  /* ---------- Grafo visível (filtros + agrupamento de terrenos) ---------- */
  let visible = { nodes: [], edges: [], real: [], byId: new Map(), neighbors: new Map(), partners: new Map() };
  const mapToVisible = new Map(); // id real -> id desenhado (terrenos agrupados apontam para o bloco)
  function computeVisible() {
    const allowed = new Set();
    [...nodesById.keys()].forEach((id) => { if (id === LAND_GROUP || id.startsWith('hub:')) nodesById.delete(id); });
    nodesById.forEach((n) => { if (n.stage === 'commander' || state.stages.has(n.stage)) allowed.add(n.id); });
    const real = data.edges
      .filter((e) => allowed.has(e.from) && allowed.has(e.to))
      .map((e) => ({ ...e, reasons: e.reasons.filter((r) => state.groups.has(r.group)) }))
      .filter((e) => e.reasons.length)
      .map((e) => ({ ...e, weight: e.reasons.reduce((sum, r) => sum + r.weight, 0) }));
    // Parceiros reais de cada carta (para o painel, o número na carta e o destaque).
    const partners = new Map();
    real.forEach((e) => {
      if (!partners.has(e.from)) partners.set(e.from, new Set());
      if (!partners.has(e.to)) partners.set(e.to, new Set());
      partners.get(e.from).add(e.to); partners.get(e.to).add(e.from);
    });
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
    // Temas muito repetidos (ex.: todos os Merfolk → lordes) passam por um quadro do tema.
    const hubNodes = new Map();
    if (state.hubs) {
      const pairsByKey = new Map();
      edges.forEach((e) => e.reasons.forEach((r) => { if (r.group !== 'combo') pairsByKey.set(r.key, (pairsByKey.get(r.key) || 0) + 1); }));
      const hubKeys = new Set([...pairsByKey].filter(([, count]) => count >= HUB_MIN).map(([key]) => key));
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
      // Um tema com o mesmo id de "tribo" e "tipo escolhido" usa um único quadro.
      edges = [...direct, ...hubEdges.values()];
      hubNodes.forEach((hub, id) => { nodesById.set(id, hub); allowed.add(id); });
    }
    const degree = new Map();
    const neighbors = new Map();
    edges.forEach((e) => {
      if (!neighbors.has(e.from)) neighbors.set(e.from, new Set());
      if (!neighbors.has(e.to)) neighbors.set(e.to, new Set());
      neighbors.get(e.from).add(e.to); neighbors.get(e.to).add(e.from);
    });
    allowed.forEach((id) => {
      if (id.startsWith('hub:')) degree.set(id, nodesById.get(id).providers.size + nodesById.get(id).consumers.size);
      else if (id === LAND_GROUP) degree.set(id, neighbors.get(id)?.size || 0);
      else degree.set(id, partners.get(id)?.size || 0);
    });
    const nodes = [...allowed]
      .filter((id) => !members.has(id))
      .map((id) => nodesById.get(id))
      .filter((n) => n && (n.stage === 'commander' || state.showIsolated || degree.get(n.id)));
    nodes.forEach((n) => { n.degree = degree.get(n.id) || 0; });
    const drawn = new Set(nodes.map((n) => n.id));
    visible = { nodes, edges: edges.filter((e) => drawn.has(e.from) && drawn.has(e.to)), real, byId: new Map(nodes.map((n) => [n.id, n])), neighbors, partners };
  }

  /* ---------- Layout por forças ---------- */
  function layout(reset = false) {
    const nodes = visible.nodes;
    const connected = nodes.filter((n) => n.degree > 0 || n.stage === 'commander');
    const isolated = nodes.filter((n) => n.degree === 0 && n.stage !== 'commander');
    const golden = Math.PI * (3 - Math.sqrt(5));
    connected
      .slice()
      .sort((a, b) => (b.stage === 'commander') - (a.stage === 'commander') || b.degree - a.degree)
      .forEach((n, i) => {
        const p = !reset && state.positions[n.id];
        if (p) { n.x = p.x; n.y = p.y; n.fixed = true; return; }
        n.fixed = false;
        if (n.stage === 'commander') { n.x = 0; n.y = 0; return; }
        const r = (n.stage === 'hub' ? 90 : 170) + 58 * Math.sqrt(i);
        n.x = Math.cos(i * golden) * r;
        n.y = Math.sin(i * golden) * r * 0.8;
      });
    const free = connected.filter((n) => !n.fixed);
    if (free.length) {
      const index = new Map(connected.map((n, i) => [n.id, i]));
      const links = visible.edges.filter((e) => index.has(e.from) && index.has(e.to));
      const ticks = 420;
      for (let t = 0; t < ticks; t++) {
        const cool = 1 - t / ticks;
        connected.forEach((n) => { n.vx = 0; n.vy = 0; });
        for (let i = 0; i < connected.length; i++) {
          const a = connected[i];
          for (let j = i + 1; j < connected.length; j++) {
            const b = connected[j];
            let dx = a.x - b.x; let dy = a.y - b.y;
            let d2 = dx * dx + dy * dy;
            if (d2 < 1) { dx = Math.random() - 0.5; dy = Math.random() - 0.5; d2 = 1; }
            const d = Math.sqrt(d2);
            const f = 42000 / d2;
            const sa = size(a); const sb = size(b);
            const minX = (sa.w + sb.w) / 2 + 26; const minY = (sa.h + sb.h) / 2 + 34;
            let push = f;
            if (Math.abs(dx) < minX && Math.abs(dy) < minY) push += 40;
            a.vx += (dx / d) * push; a.vy += (dy / d) * push;
            b.vx -= (dx / d) * push; b.vy -= (dy / d) * push;
          }
        }
        links.forEach((e) => {
          const a = connected[index.get(e.from)]; const b = connected[index.get(e.to)];
          const dx = b.x - a.x; const dy = b.y - a.y;
          const d = Math.sqrt(dx * dx + dy * dy) || 1;
          const hubLink = e.from.startsWith('hub:') || e.to.startsWith('hub:');
          const rest = hubLink ? 150 : 210;
          const f = (d - rest) * 0.045 * Math.min(2, 0.6 + e.weight * 0.3);
          a.vx += (dx / d) * f; a.vy += (dy / d) * f;
          b.vx -= (dx / d) * f; b.vy -= (dy / d) * f;
        });
        connected.forEach((n) => {
          if (n.fixed || n.stage === 'commander') return;
          n.vx -= n.x * 0.012; n.vy -= n.y * 0.016;
          const step = 9 * cool + 0.4;
          const len = Math.sqrt(n.vx * n.vx + n.vy * n.vy) || 1;
          n.x += (n.vx / len) * Math.min(len, step * 6);
          n.y += (n.vy / len) * Math.min(len, step * 6);
        });
      }
    }
    // Remove sobreposições que as forças deixaram (cartas, rótulos e quadros de tema).
    for (let pass = 0; pass < 80; pass++) {
      let moved = false;
      for (let i = 0; i < connected.length; i++) {
        for (let j = i + 1; j < connected.length; j++) {
          const a = connected[i]; const b = connected[j];
          const sa = size(a); const sb = size(b);
          const needX = (sa.w + sb.w) / 2 + 34; const needY = (sa.h + sb.h) / 2 + 30;
          const dx = b.x - a.x; const dy = b.y - a.y;
          const ox = needX - Math.abs(dx); const oy = needY - Math.abs(dy);
          if (ox <= 0 || oy <= 0) continue;
          moved = true;
          const aFixed = a.fixed || a.stage === 'commander'; const bFixed = b.fixed || b.stage === 'commander';
          const share = aFixed && !bFixed ? [0, 1] : bFixed && !aFixed ? [1, 0] : [0.5, 0.5];
          if (ox < oy) { const sgn = dx >= 0 ? 1 : -1; if (!aFixed) a.x -= sgn * ox * share[0]; if (!bFixed) b.x += sgn * ox * share[1]; }
          else { const sgn = dy >= 0 ? 1 : -1; if (!aFixed) a.y -= sgn * oy * share[0]; if (!bFixed) b.y += sgn * oy * share[1]; }
        }
      }
      if (!moved) break;
    }
    // Cartas sem relação ficam numa prateleira abaixo do grafo.
    if (isolated.length) {
      const bottom = Math.max(0, ...connected.map((n) => n.y + size(n).h / 2)) + 110;
      const left = Math.min(0, ...connected.map((n) => n.x - size(n).w / 2));
      const cols = Math.max(6, Math.ceil(Math.sqrt(isolated.length * 2.5)));
      isolated.forEach((n, i) => {
        const p = !reset && state.positions[n.id];
        if (p) { n.x = p.x; n.y = p.y; return; }
        n.x = left + (i % cols) * 92 + 35;
        n.y = bottom + Math.floor(i / cols) * 130;
      });
    }
  }

  /* ---------- Desenho ---------- */
  function ensureMarkers() {
    defs.innerHTML = '';
    Object.entries(data.groups).forEach(([key, g]) => {
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

  function render() {
    edgeLayer.innerHTML = '';
    nodeLayer.innerHTML = '';
    visible.edges.forEach((e, i) => {
      const group = primaryGroup(e) || 'combo';
      const color = data.groups[group]?.color || '#59625f';
      const path = document.createElementNS(SVG, 'path');
      path.setAttribute('class', `board-edge group-${group}`);
      path.setAttribute('d', edgePath(e));
      path.setAttribute('stroke', color);
      path.setAttribute('stroke-width', (1.1 + Math.min(e.weight, 4) * 0.55).toFixed(2));
      path.setAttribute('marker-end', `url(#board-arrow-${group})`);
      if (group === 'combo') path.setAttribute('stroke-dasharray', '7 4');
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
      g.setAttribute('class', `board-node stage-${n.stage}${n.game_changer ? ' is-gc' : ''}`);
      g.setAttribute('transform', `translate(${n.x.toFixed(1)},${n.y.toFixed(1)})`);
      g.setAttribute('tabindex', '0');
      g.setAttribute('role', 'button');
      g.setAttribute('aria-label', `${n.name}: ${n.degree} relações`);
      g.dataset.node = n.id;
      const x = -s.w / 2; const y = -s.h / 2;
      let inner = `<rect class="board-node-frame" x="${x - 3}" y="${y - 3}" width="${s.w + 6}" height="${s.h + 6}" rx="8"></rect>`;
      inner += `<rect class="board-node-bg" x="${x}" y="${y}" width="${s.w}" height="${s.h}" rx="6"></rect>`;
      if (n.stage === 'hub') {
        const color = data.groups[n.group]?.color || '#59625f';
        g.innerHTML = `<rect class="board-hub" x="${x}" y="${y}" width="${s.w}" height="${s.h}" rx="18" style="--c:${color}"></rect><text class="board-hub-label" x="0" y="4" text-anchor="middle" style="--c:${color}">${escapeHtml(n.name)}</text><text class="board-hub-count" x="0" y="${s.h / 2 + 13}" text-anchor="middle">${n.providers.size} → ${n.consumers.size}</text>`;
        nodeLayer.appendChild(g);
        return;
      }
      if (n.id === LAND_GROUP) {
        inner += `<text class="board-node-fallback" x="0" y="-6" text-anchor="middle">Terrenos</text><text class="board-node-fallback is-small" x="0" y="12" text-anchor="middle">${escapeHtml(n.members.reduce((sum, m) => sum + (m.quantity || 1), 0))} cartas</text>`;
      } else {
        inner += `<text class="board-node-fallback" x="0" y="0" text-anchor="middle">${escapeHtml(n.name.slice(0, 12))}</text>`;
        if (n.image) inner += `<image href="${escapeHtml(n.image)}" x="${x}" y="${y}" width="${s.w}" height="${s.h}" preserveAspectRatio="xMidYMid slice" clip-path="url(#board-card-clip)"></image>`;
      }
      const label = n.name.length > 18 ? `${n.name.slice(0, 17)}…` : n.name;
      inner += `<text class="board-node-label" x="0" y="${s.h / 2 + 15}" text-anchor="middle">${escapeHtml(label)}</text>`;
      if (n.degree) inner += `<g class="board-node-degree" transform="translate(${s.w / 2 - 2},${y + 2})"><circle r="10"></circle><text text-anchor="middle" y="4">${n.degree}</text></g>`;
      if (n.stage === 'candidate') inner += `<text class="board-node-stage" x="${x + 4}" y="${y + 13}">cand.</text>`;
      g.innerHTML = inner;
      const img = g.querySelector('image');
      if (img) img.addEventListener('error', () => img.remove(), { once: true });
      nodeLayer.appendChild(g);
    });
    applyFocus();
    updateCounts();
  }

  function refreshEdges() {
    $$('.board-edge, .board-edge-hit', edgeLayer).forEach((p) => p.setAttribute('d', edgePath(visible.edges[Number(p.dataset.edge)])));
  }

  function applyView() {
    viewport.setAttribute('transform', `translate(${state.view.x.toFixed(1)},${state.view.y.toFixed(1)}) scale(${state.view.k.toFixed(3)})`);
    root.classList.toggle('is-zoomed-out', state.view.k < 0.55);
  }

  function fit(ids = null) {
    const nodes = ids ? visible.nodes.filter((n) => ids.has(n.id)) : visible.nodes;
    if (!nodes.length) return;
    const rect = canvas.getBoundingClientRect();
    const xs = nodes.flatMap((n) => [n.x - size(n).w / 2, n.x + size(n).w / 2]);
    const ys = nodes.flatMap((n) => [n.y - size(n).h / 2, n.y + size(n).h / 2 + 22]);
    const minX = Math.min(...xs); const maxX = Math.max(...xs); const minY = Math.min(...ys); const maxY = Math.max(...ys);
    const k = Math.max(0.2, Math.min(1.4, Math.min((rect.width - 60) / (maxX - minX || 1), (rect.height - 60) / (maxY - minY || 1))));
    state.view = { k, x: rect.width / 2 - ((minX + maxX) / 2) * k, y: rect.height / 2 - ((minY + maxY) / 2) * k };
    applyView();
  }

  /* ---------- Foco e painel ---------- */
  function applyFocus() {
    const focus = state.focus && visible.byId.has(state.focus) ? state.focus : null;
    root.classList.toggle('has-focus', !!focus);
    const near = focus ? new Set([focus, ...(visible.neighbors.get(focus) || []), ...[...(visible.partners.get(focus) || [])].map((id) => mapToVisible.get(id) || id)]) : null;
    if (focus && focus.startsWith('hub:')) { const hub = visible.byId.get(focus); [...hub.providers.keys(), ...hub.consumers.keys()].forEach((id) => near.add(id)); }
    $$('.board-node', nodeLayer).forEach((g) => {
      g.classList.toggle('is-focus', g.dataset.node === focus);
      g.classList.toggle('is-near', !!near && near.has(g.dataset.node) && g.dataset.node !== focus);
      g.classList.toggle('is-dim', !!near && !near.has(g.dataset.node));
    });
    $$('.board-edge', edgeLayer).forEach((p) => {
      const e = visible.edges[Number(p.dataset.edge)];
      const on = focus && (e.from === focus || e.to === focus);
      p.classList.toggle('is-on', !!on);
      p.classList.toggle('is-dim', !!focus && !on);
    });
    renderPanel(focus);
  }

  const nodeName = (id) => (visible.byId.get(id) || nodesById.get(id) || { name: id }).name;

  function reasonHtml(reason, fromName, toName) {
    const color = data.groups[reason.group]?.color || '#59625f';
    const tag = reason.source === 'tag' ? '<small class="board-source">Scryfall Tagger</small>' : reason.source === 'combo' ? '<small class="board-source">Combo</small>' : '';
    return `<div class="board-reason" style="--c:${color}"><p><b class="board-chip" style="--c:${color}">${escapeHtml(reason.label)}</b>${escapeHtml(fromName)} ${escapeHtml(reason.give)} <span aria-hidden="true">→</span> ${escapeHtml(toName)} ${escapeHtml(reason.take)} ${tag}</p><q>${escapeHtml(reason.from_text)}</q><q>${escapeHtml(reason.to_text)}</q></div>`;
  }

  function renderPanel(focus) {
    if (!focus) { renderOverview(); return; }
    const n = visible.byId.get(focus);
    if (n.stage === 'hub') { renderHubPanel(n); return; }
    const ids = n.id === LAND_GROUP ? new Set(n.members.map((m) => m.id)) : new Set([n.id]);
    const outgoing = visible.real.filter((e) => ids.has(e.from) && !ids.has(e.to)).sort((a, b) => b.weight - a.weight);
    const incoming = visible.real.filter((e) => ids.has(e.to) && !ids.has(e.from)).sort((a, b) => b.weight - a.weight);
    const stageLabel = { commander: 'Comandante', deck: 'No deck', candidate: 'Candidata', group: 'Grupo' }[n.stage] || '';
    const self = n.id === LAND_GROUP ? 'Terreno' : 'Esta carta';
    const list = (edges, dir) => edges.map((e) => {
      const other = dir === 'out' ? e.to : e.from;
      const origin = n.id === LAND_GROUP ? nodeName(dir === 'out' ? e.from : e.to) : null;
      return `<li><button type="button" class="board-partner" data-board-goto="${escapeHtml(other)}">${escapeHtml(nodeName(other))}</button>${origin ? `<small class="board-origin">de ${escapeHtml(origin)}</small>` : ''}${e.reasons.map((r) => reasonHtml(r, dir === 'out' ? (origin || self) : nodeName(other), dir === 'out' ? nodeName(other) : (origin || 'esta carta'))).join('')}</li>`;
    }).join('');
    const facts = [
      n.provides?.length ? `<p><em>Fornece</em> ${escapeHtml(n.provides.join(', '))}</p>` : '',
      n.consumes?.length ? `<p><em>Aproveita</em> ${escapeHtml(n.consumes.join(', '))}</p>` : '',
      n.roles?.length ? `<p><em>Função</em> ${escapeHtml(n.roles.join(', '))}</p>` : '',
    ].join('');
    panel.innerHTML = `
      <div class="board-panel-head">
        ${n.image ? `<img src="${escapeHtml(n.image)}" alt="" width="92" height="128" loading="lazy">` : ''}
        <div><span class="board-stage stage-${n.stage}">${stageLabel}</span><h2>${escapeHtml(n.name)}</h2><p class="board-type">${escapeHtml(n.type_line || n.type || '')}</p>
        ${n.url && n.id !== LAND_GROUP ? `<a href="${escapeHtml(n.url)}">Abrir carta</a>` : ''}</div>
        <button type="button" class="board-close" data-board-clear aria-label="Fechar detalhes">×</button>
      </div>
      <details class="board-text"${n.id === LAND_GROUP ? ' open' : ''}><summary>${n.id === LAND_GROUP ? 'Terrenos agrupados' : 'Texto da carta'}</summary><p>${escapeHtml(n.text || '').replace(/\n/g, '<br>')}</p></details>
      <div class="board-facts">${facts}</div>
      <section><h3>Fornece para <b>${outgoing.length}</b></h3>${outgoing.length ? `<ul class="board-relations">${list(outgoing, 'out')}</ul>` : '<p class="board-empty">Nenhuma carta visível aproveita o que esta carta fornece.</p>'}</section>
      <section><h3>Aproveita de <b>${incoming.length}</b></h3>${incoming.length ? `<ul class="board-relations">${list(incoming, 'in')}</ul>` : '<p class="board-empty">Nenhuma carta visível fornece o que esta carta aproveita.</p>'}</section>`;
  }

  function renderHubPanel(hub) {
    const color = data.groups[hub.group]?.color || '#59625f';
    const side = (entries, dir) => [...entries].map(([id, r]) => `<li><button type="button" class="board-partner" data-board-goto="${escapeHtml(id)}">${escapeHtml(nodeName(id))}</button><div class="board-reason" style="--c:${color}"><p>${escapeHtml(dir === 'in' ? r.give : r.take)}</p><q>${escapeHtml(dir === 'in' ? r.from_text : r.to_text)}</q></div></li>`).join('');
    panel.innerHTML = `
      <div class="board-panel-head is-hub">
        <div><span class="board-stage" style="background:color-mix(in srgb,${color} 14%,#fff);color:${color}">Tema</span><h2>${escapeHtml(hub.name)}</h2><p class="board-type">${escapeHtml(data.groups[hub.group]?.label || '')} · ${hub.providers.size} fornecem, ${hub.consumers.size} aproveitam</p></div>
        <button type="button" class="board-close" data-board-clear aria-label="Fechar detalhes">×</button>
      </div>
      <p class="board-note">Todas as cartas da esquerda se ligam a todas as da direita por este tema. Desmarque “Agrupar por tema” para ver cada seta.</p>
      <section><h3>Fornecem <b>${hub.providers.size}</b></h3><ul class="board-relations">${side(hub.providers, 'in')}</ul></section>
      <section><h3>Aproveitam <b>${hub.consumers.size}</b></h3><ul class="board-relations">${side(hub.consumers, 'out')}</ul></section>`;
  }

  function comboItem(combo, missing = false) {
    const pieces = combo.cards.map((name) => `<span class="${missing && name === combo.missing ? 'is-missing' : ''}">${escapeHtml(name)}</span>`).join(' + ');
    const results = (Array.isArray(combo.results) ? combo.results : [combo.result]).filter(Boolean).slice(0, 3).map(escapeHtml).join(' · ');
    const owned = missing ? `<small class="${combo.missing_owned ? 'is-owned' : ''}">${combo.missing_owned ? `Você tem ${combo.missing_owned} cópia(s) de ${escapeHtml(combo.missing)}` : `Falta ${escapeHtml(combo.missing)} (fora da coleção)`}</small>` : '';
    return `<li><p class="board-combo-cards">${pieces}</p>${results ? `<p class="board-combo-result">${results}</p>` : ''}${owned}${combo.href ? `<a href="${escapeHtml(combo.href)}" target="_blank" rel="noopener">Ver passo a passo</a>` : ''}</li>`;
  }

  function renderOverview() {
    const counts = {};
    visible.real.forEach((e) => e.reasons.forEach((r) => { counts[r.group] = (counts[r.group] || 0) + 1; }));
    const hubs = visible.nodes.filter((n) => n.id !== LAND_GROUP && n.stage !== 'hub').slice().sort((a, b) => b.degree - a.degree).slice(0, 6);
    const spell = data.spellbook;
    const spellSection = spell
      ? `<p class="board-note">${spell.included.length ? `${spell.included.length} combo(s) completo(s) com estas cartas — listados acima e desenhados com setas tracejadas.` : 'Nenhum combo completo com estas cartas.'}</p>
         ${spell.almost.length ? `<h4>Falta 1 carta</h4><ul class="board-combos">${spell.almost.slice(0, 12).map((c) => comboItem(c, true)).join('')}</ul>` : ''}
         <p class="board-note">Consulta de ${escapeHtml(new Date(spell.synced_at).toLocaleString('pt-BR'))}. Dados do <a href="https://commanderspellbook.com" target="_blank" rel="noopener">Commander Spellbook</a>.</p>`
      : '<p class="board-empty">Ainda não consultado para esta lista.</p>';
    panel.innerHTML = `
      <div class="board-summary">
        <h2>Visão geral</h2>
        <dl><div><dt>Cartas</dt><dd>${visible.nodes.filter((n) => n.stage !== 'hub').length}</dd></div><div><dt>Relações</dt><dd>${visible.real.length}</dd></div><div><dt>Tribo principal</dt><dd>${escapeHtml(data.tribe || '—')}</dd></div></dl>
        <h3>Mais conectadas</h3>
        <ol class="board-hubs">${hubs.map((n) => `<li><button type="button" class="board-partner" data-board-goto="${escapeHtml(n.id)}">${escapeHtml(n.name)}</button><b>${n.degree}</b></li>`).join('')}</ol>
        <h3>Combos</h3>
        ${data.combos.length ? `<ul class="board-combos">${data.combos.map((c) => comboItem({ ...c, cards: c.names })).join('')}</ul>` : '<p class="board-empty">Nenhum combo conhecido do EDHREC com todas as peças aqui.</p>'}
        <div class="board-spellbook" data-board-spellbook>${spellSection}
          <button type="button" class="secondary-link" data-board-spellbook-fetch>${spell ? 'Atualizar no Commander Spellbook' : 'Buscar combos no Commander Spellbook'}</button>
        </div>
        <h3>Como ler</h3>
        <p class="board-note">A seta sai de quem <strong>fornece</strong> e aponta para quem <strong>aproveita</strong>. Setas tracejadas são combos. O número no canto da carta é quantas relações ela tem com o que está visível.</p>
        ${data.tags ? `<p class="board-note">${data.tags.toLocaleString('pt-BR')} tags do Scryfall Tagger complementam o texto.</p>` : '<p class="board-note">Para relações mais completas, sincronize as tags do Scryfall Tagger: <code>php bin/sync_tagger.php</code>.</p>'}
        <p class="board-note">Distribuição: ${Object.entries(counts).sort((a, b) => b[1] - a[1]).map(([g, c]) => `${escapeHtml(data.groups[g]?.label || g)} ${c}`).join(' · ') || '—'}</p>
      </div>`;
  }

  function updateCounts() {
    const count = { deck: 0, candidate: 0 };
    nodesById.forEach((n) => { if (count[n.stage] !== undefined) count[n.stage] += 1; });
    Object.entries(count).forEach(([stage, value]) => { const el = $(`[data-board-count="${stage}"]`); if (el) el.textContent = value; });
    const totals = {};
    data.edges.forEach((e) => e.reasons.forEach((r) => { totals[r.group] = (totals[r.group] || 0) + 1; }));
    const chips = $('[data-board-chips]');
    chips.innerHTML = Object.entries(data.groups).filter(([key]) => totals[key]).map(([key, g]) => `<button type="button" class="board-group-chip${state.groups.has(key) ? ' is-on' : ''}" style="--c:${g.color}" data-board-group="${key}" aria-pressed="${state.groups.has(key)}"><i></i>${escapeHtml(g.label)} <b>${totals[key]}</b></button>`).join('')
      + '<button type="button" class="board-group-all" data-board-group-all>Todas</button>';
  }

  function focusNode(id, center = true) {
    state.focus = id;
    applyFocus();
    if (center && id && visible.byId.has(id)) {
      const n = visible.byId.get(id);
      const rect = canvas.getBoundingClientRect();
      state.view.x = rect.width / 2 - n.x * state.view.k;
      state.view.y = rect.height / 2 - n.y * state.view.k;
      applyView();
    }
  }

  function persist() {
    store.write({ stages: [...state.stages], groups: [...state.groups], groupLands: state.groupLands, hubs: state.hubs, showIsolated: state.showIsolated, positions: state.positions });
  }

  function rebuild(reset = false) {
    computeVisible();
    layout(reset);
    render();
  }

  /* ---------- Interação ---------- */
  let drag = null;
  svg.addEventListener('pointerdown', (event) => {
    const nodeEl = event.target.closest('.board-node');
    svg.setPointerCapture(event.pointerId);
    if (nodeEl) {
      const n = visible.byId.get(nodeEl.dataset.node);
      drag = { type: 'node', node: n, el: nodeEl, startX: event.clientX, startY: event.clientY, ox: n.x, oy: n.y, moved: false };
    } else {
      drag = { type: 'pan', startX: event.clientX, startY: event.clientY, vx: state.view.x, vy: state.view.y, moved: false };
      canvas.classList.add('is-panning');
    }
  });
  svg.addEventListener('pointermove', (event) => {
    if (!drag) { hoverEdge(event); return; }
    const dx = event.clientX - drag.startX; const dy = event.clientY - drag.startY;
    if (Math.abs(dx) + Math.abs(dy) > 4) drag.moved = true;
    if (drag.type === 'pan') {
      state.view.x = drag.vx + dx; state.view.y = drag.vy + dy; applyView();
    } else if (drag.moved) {
      drag.node.x = drag.ox + dx / state.view.k; drag.node.y = drag.oy + dy / state.view.k;
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
    const k = Math.max(0.15, Math.min(2.5, state.view.k * Math.exp(-event.deltaY * 0.0015)));
    state.view.x = mx - ((mx - state.view.x) / state.view.k) * k;
    state.view.y = my - ((my - state.view.y) / state.view.k) * k;
    state.view.k = k;
    applyView();
  }, { passive: false });

  function hoverEdge(event) {
    const hit = event.target.closest('.board-edge-hit');
    if (!hit) { tooltip.hidden = true; return; }
    const e = visible.edges[Number(hit.dataset.edge)];
    if (!e) return;
    const rect = canvas.getBoundingClientRect();
    const text = (r) => (e.hub === 'in' ? r.give : e.hub === 'out' ? r.take : `${r.give} → ${r.take}`);
    tooltip.innerHTML = `<strong>${escapeHtml(nodeName(e.from))} → ${escapeHtml(nodeName(e.to))}</strong>${e.reasons.map((r) => `<span style="--c:${data.groups[r.group]?.color}"><b>${escapeHtml(r.label)}</b> ${escapeHtml(text(r))}</span>`).join('')}`;
    tooltip.hidden = false;
    tooltip.style.left = `${Math.min(rect.width - 280, event.clientX - rect.left + 14)}px`;
    tooltip.style.top = `${Math.min(rect.height - 120, event.clientY - rect.top + 14)}px`;
  }
  svg.addEventListener('pointerleave', () => { tooltip.hidden = true; });

  svg.addEventListener('keydown', (event) => {
    const nodeEl = event.target.closest('.board-node');
    if (nodeEl && (event.key === 'Enter' || event.key === ' ')) { event.preventDefault(); focusNode(nodeEl.dataset.node); }
  });
  document.addEventListener('keydown', (event) => { if (event.key === 'Escape' && state.focus) focusNode(null, false); });

  root.addEventListener('click', async (event) => {
    const go = event.target.closest('[data-board-goto]');
    if (go) { const target = go.dataset.boardGoto; focusNode(visible.byId.has(target) ? target : (mapToVisible.get(target) || target)); return; }
    if (event.target.closest('[data-board-clear]')) { focusNode(null, false); return; }
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
      if (dir === 0) { fit(); return; }
      const rect = canvas.getBoundingClientRect();
      const k = Math.max(0.15, Math.min(2.5, state.view.k * (dir > 0 ? 1.25 : 0.8)));
      state.view.x = rect.width / 2 - ((rect.width / 2 - state.view.x) / state.view.k) * k;
      state.view.y = rect.height / 2 - ((rect.height / 2 - state.view.y) / state.view.k) * k;
      state.view.k = k; applyView(); return;
    }
    if (event.target.closest('[data-board-layout]')) { state.positions = {}; persist(); rebuild(true); fit(); return; }
    const fetchButton = event.target.closest('[data-board-spellbook-fetch]');
    if (fetchButton) {
      fetchButton.disabled = true;
      fetchButton.textContent = 'Consultando o Commander Spellbook…';
      try {
        const body = new URLSearchParams({ csrf: data.csrf, action: 'spellbook', deck: String(data.deck.id) });
        const response = await fetch(window.location.pathname + window.location.search, { method: 'POST', body, headers: { 'X-Requested-With': 'fetch' } });
        const result = await response.json();
        if (!result.ok) throw new Error(result.message || 'Falha na consulta.');
        // Recarrega para incluir as setas dos combos encontrados.
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

  $$('[data-board-stage]').forEach((input) => {
    input.checked = state.stages.has(input.dataset.boardStage);
    input.addEventListener('change', () => {
      if (input.checked) state.stages.add(input.dataset.boardStage); else state.stages.delete(input.dataset.boardStage);
      persist(); rebuild(); fit();
    });
  });
  const hubsToggle = $('[data-board-hubs]');
  hubsToggle.checked = state.hubs;
  hubsToggle.addEventListener('change', () => { state.hubs = hubsToggle.checked; persist(); rebuild(true); fit(); });
  const landsToggle = $('[data-board-lands]');
  landsToggle.checked = state.groupLands;
  landsToggle.addEventListener('change', () => { state.groupLands = landsToggle.checked; persist(); rebuild(); fit(); });
  const isolatedToggle = $('[data-board-isolated]');
  isolatedToggle.checked = state.showIsolated;
  isolatedToggle.addEventListener('change', () => { state.showIsolated = isolatedToggle.checked; persist(); rebuild(); fit(); });
  const search = $('[data-board-search]');
  $('#board-card-names').innerHTML = data.nodes.map((n) => `<option value="${escapeHtml(n.name)}"></option>`).join('');
  search.addEventListener('change', () => {
    const wanted = search.value.trim().toLowerCase();
    const match = data.nodes.find((n) => n.name.toLowerCase() === wanted) || data.nodes.find((n) => n.name.toLowerCase().includes(wanted));
    if (!match) return;
    if (!visible.byId.has(match.id)) { state.showIsolated = true; isolatedToggle.checked = true; if (match.stage !== 'commander') state.stages.add(match.stage); rebuild(); }
    focusNode(match.id);
  });

  ensureMarkers();
  rebuild();
  fit();
  if (data.focus) {
    if (!visible.byId.has(data.focus) && nodesById.has(data.focus)) { state.showIsolated = true; isolatedToggle.checked = true; rebuild(); fit(); }
    focusNode(data.focus);
  }
  window.addEventListener('resize', () => applyView());
})();
