/* Constelação 3D do quadro de relações: as cartas flutuam sobre um tapete de jogo escuro, agrupadas por tema
   em volta da comandante, e arcos de luz ligam quem fornece a quem aproveita. Carregado por board.js sob demanda;
   o grafo, os filtros, o foco e o painel continuam lá — aqui fica só o desenho e a navegação em 3D. */
import * as THREE from './vendor/three.module.min.js';

const CARD_W = 1;
const CARD_H = 680 / 488;
const FLOOR_Y = -1.35;
const SPACING = 1.62;
const GOLDEN = Math.PI * (3 - Math.sqrt(5));
const UP = new THREE.Vector3(0, 1, 0);
const COMMANDER_GLOW = '#d9b45a';
const GHOST_GLOW = '#cfd9d1';

/** Número estável entre 0 e 1 para cada id (alturas e fases diferentes, sem mudar a cada recarga). */
function hash(text) {
  let h = 2166136261;
  for (let i = 0; i < text.length; i++) { h ^= text.charCodeAt(i); h = Math.imul(h, 16777619); }
  return ((h >>> 0) % 10000) / 10000;
}

function canvasTexture(width, height, draw) {
  const canvas = document.createElement('canvas');
  canvas.width = width; canvas.height = height;
  draw(canvas.getContext('2d'), width, height);
  const texture = new THREE.CanvasTexture(canvas);
  texture.colorSpace = THREE.SRGBColorSpace;
  return texture;
}

function roundRect(ctx, x, y, w, h, r) {
  ctx.beginPath();
  ctx.moveTo(x + r, y); ctx.arcTo(x + w, y, x + w, y + h, r); ctx.arcTo(x + w, y + h, x, y + h, r);
  ctx.arcTo(x, y + h, x, y, r); ctx.arcTo(x, y, x + w, y, r); ctx.closePath();
}

export function createBoard3D(host, api) {
  const renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true, powerPreference: 'high-performance' });
  renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
  renderer.outputColorSpace = THREE.SRGBColorSpace;
  renderer.setClearColor(0x000000, 0);
  const dom = renderer.domElement;
  dom.className = 'board-webgl';
  host.prepend(dom);
  const labelsEl = host.querySelector('[data-board-labels]');
  const maxAniso = renderer.capabilities.getMaxAnisotropy();

  const scene = new THREE.Scene();
  scene.fog = new THREE.Fog(0x111c18, 20, 80);
  const camera = new THREE.PerspectiveCamera(40, 1, 0.1, 500);
  const world = new THREE.Group();
  scene.add(world);

  /* ---------- Texturas compartilhadas ---------- */
  const cardAlpha = canvasTexture(128, 178, (ctx, w, h) => { ctx.fillStyle = '#000'; ctx.fillRect(0, 0, w, h); ctx.fillStyle = '#fff'; roundRect(ctx, 1, 1, w - 2, h - 2, 7); ctx.fill(); });
  cardAlpha.colorSpace = THREE.NoColorSpace;
  const glow = canvasTexture(128, 128, (ctx, w) => {
    const g = ctx.createRadialGradient(w / 2, w / 2, 0, w / 2, w / 2, w / 2);
    g.addColorStop(0, 'rgba(255,255,255,1)'); g.addColorStop(0.28, 'rgba(255,255,255,.55)'); g.addColorStop(1, 'rgba(255,255,255,0)');
    ctx.fillStyle = g; ctx.fillRect(0, 0, w, w);
  });
  const shadowTex = canvasTexture(128, 128, (ctx, w) => {
    const g = ctx.createRadialGradient(w / 2, w / 2, 0, w / 2, w / 2, w / 2);
    g.addColorStop(0, 'rgba(0,0,0,.75)'); g.addColorStop(0.55, 'rgba(0,0,0,.25)'); g.addColorStop(1, 'rgba(0,0,0,0)');
    ctx.fillStyle = g; ctx.fillRect(0, 0, w, w);
  });
  const feltTex = canvasTexture(1024, 1024, (ctx, w) => {
    const g = ctx.createRadialGradient(w / 2, w / 2, 0, w / 2, w / 2, w / 2);
    g.addColorStop(0, 'rgba(58,86,74,.95)'); g.addColorStop(0.55, 'rgba(33,52,44,.8)'); g.addColorStop(1, 'rgba(17,28,24,0)');
    ctx.fillStyle = g; ctx.fillRect(0, 0, w, w);
    ctx.fillStyle = 'rgba(214,226,218,.11)';
    for (let y = 16; y < w; y += 32) for (let x = 16; x < w; x += 32) { ctx.beginPath(); ctx.arc(x, y, 1.6, 0, Math.PI * 2); ctx.fill(); }
  });

  const cardGeo = new THREE.PlaneGeometry(CARD_W, CARD_H);
  const edgeGeo = new THREE.PlaneGeometry(CARD_W * 1.035, CARD_H * 1.03);
  const auraGeo = new THREE.PlaneGeometry(CARD_W * 2.1, CARD_H * 1.8);
  const shadowGeo = new THREE.PlaneGeometry(1, 1);
  const coneGeo = new THREE.ConeGeometry(0.07, 0.22, 10);
  coneGeo.translate(0, -0.11, 0);
  const orbGeo = new THREE.IcosahedronGeometry(0.24, 2);
  const hitGeo = new THREE.SphereGeometry(0.55, 10, 8);

  /* ---------- Imagens: miniatura primeiro, imagem normal quando a carta fica grande na tela ---------- */
  const textures = new Map();
  const loader = new THREE.TextureLoader();
  const queue = [];
  let loading = 0;
  function pump() {
    while (loading < 6 && queue.length) {
      const job = queue.shift();
      loading++;
      loader.load(job.url, (texture) => {
        loading--;
        texture.colorSpace = THREE.SRGBColorSpace;
        texture.anisotropy = maxAniso;
        textures.set(job.url, texture);
        job.done.forEach((fn) => fn(texture));
        pump();
      }, undefined, () => { loading--; pump(); });
    }
  }
  function texture(url, done, first = false) {
    if (textures.has(url)) { done(textures.get(url)); return; }
    const pending = queue.find((job) => job.url === url);
    if (pending) { pending.done.push(done); return; }
    queue[first ? 'unshift' : 'push']({ url, done: [done] });
    pump();
  }
  const fallbacks = new Map();
  function fallbackTexture(node) {
    if (fallbacks.has(node.id)) return fallbacks.get(node.id);
    const tex = canvasTexture(256, 357, (ctx, w, h) => {
      ctx.fillStyle = '#1d2a25'; ctx.fillRect(0, 0, w, h);
      ctx.strokeStyle = 'rgba(214,226,218,.35)'; ctx.lineWidth = 3; roundRect(ctx, 12, 12, w - 24, h - 24, 10); ctx.stroke();
      ctx.fillStyle = '#eef1ee'; ctx.textAlign = 'center';
      ctx.font = '700 25px Spectral, Georgia, serif';
      const words = String(node.name).split(/\s+/); const lines = []; let line = '';
      words.forEach((word) => { const next = line ? `${line} ${word}` : word; if (ctx.measureText(next).width > w - 50 && line) { lines.push(line); line = word; } else line = next; });
      if (line) lines.push(line);
      lines.slice(0, 5).forEach((text, i) => ctx.fillText(text, w / 2, h / 2 - (Math.min(lines.length, 5) - 1) * 15 + i * 30));
      ctx.font = '600 15px "Segoe UI", system-ui, sans-serif'; ctx.fillStyle = 'rgba(214,226,218,.7)';
      ctx.fillText(String(node.type || '').slice(0, 26), w / 2, h - 34);
    });
    fallbacks.set(node.id, tex);
    return tex;
  }

  /* ---------- Chão: um tapete de jogo com um território por tema ---------- */
  const floor = new THREE.Mesh(new THREE.PlaneGeometry(1, 1), new THREE.MeshBasicMaterial({ map: feltTex, transparent: true, depthWrite: false, fog: false }));
  floor.rotation.x = -Math.PI / 2;
  floor.position.y = FLOOR_Y - 0.01;
  floor.renderOrder = -3;
  scene.add(floor);
  const dust = (() => {
    const count = 260;
    const positions = new Float32Array(count * 3);
    for (let i = 0; i < count; i++) {
      const r = 6 + Math.pow(hash(`d${i}`), 0.7) * 34; const a = hash(`a${i}`) * Math.PI * 2;
      positions.set([Math.cos(a) * r, FLOOR_Y + 0.5 + hash(`y${i}`) * 9, Math.sin(a) * r], i * 3);
    }
    const geometry = new THREE.BufferGeometry();
    geometry.setAttribute('position', new THREE.BufferAttribute(positions, 3));
    const points = new THREE.Points(geometry, new THREE.PointsMaterial({ size: 0.07, map: glow, color: 0xd8e4dc, transparent: true, opacity: 0.35, depthWrite: false, blending: THREE.AdditiveBlending }));
    scene.add(points);
    return points;
  })();

  /* ---------- Câmera em órbita, com amortecimento ---------- */
  const orbit = { target: new THREE.Vector3(), radius: 26, theta: 0.7, phi: 1.02 };
  const goal = { target: new THREE.Vector3(), radius: 26, theta: 0.7, phi: 1.02 };
  let sceneRadius = 12;
  function placeCamera(dt) {
    const k = api.reducedMotion() ? 1 : 1 - Math.exp(-dt * 7);
    orbit.theta += (goal.theta - orbit.theta) * k;
    orbit.phi += (goal.phi - orbit.phi) * k;
    orbit.radius += (goal.radius - orbit.radius) * k;
    orbit.target.lerp(goal.target, k);
    const s = Math.sin(orbit.phi);
    camera.position.set(orbit.target.x + orbit.radius * s * Math.sin(orbit.theta), orbit.target.y + orbit.radius * Math.cos(orbit.phi), orbit.target.z + orbit.radius * s * Math.cos(orbit.theta));
    camera.lookAt(orbit.target);
    scene.fog.near = orbit.radius * 0.75;
    scene.fog.far = orbit.radius * 2.6 + sceneRadius;
  }

  /** Distância para caber uma esfera de raio r na tela (considera o menor dos dois ângulos de visão). */
  function distanceFor(r) {
    const vfov = THREE.MathUtils.degToRad(camera.fov) / 2;
    const hfov = Math.atan(Math.tan(vfov) * (width / Math.max(1, height)));
    return r / Math.sin(Math.min(vfov, hfov));
  }

  /** Enquadra as cartas (todas ou só as de um conjunto) projetando-as na tela, já descontado o painel aberto. */
  const probe = new THREE.PerspectiveCamera();
  function frame(ids = null) {
    const list = [...objects.values()].filter((o) => !ids || ids.has(o.id));
    if (!list.length) return;
    const box = new THREE.Box3();
    list.forEach((o) => box.expandByPoint(o.base));
    // Inclui os nomes impressos no tapete, na borda voltada para a câmera.
    const rims = ids ? [] : clusterLabels.flatMap((c) => [-0.5, 0.5].map((side) => {
      const reach = c.rim + c.half * 2;
      return new THREE.Vector3(c.center.x + Math.sin(goal.theta) * reach + Math.cos(goal.theta) * side * c.half * 6, FLOOR_Y, c.center.z + Math.cos(goal.theta) * reach - Math.sin(goal.theta) * side * c.half * 6);
    }));
    rims.forEach((p) => box.expandByPoint(p));
    const center = box.getCenter(new THREE.Vector3());
    center.y = Math.max(0.4, center.y);
    const phi = ids ? THREE.MathUtils.clamp(goal.phi, 0.6, 1.1) : 0.92;
    const theta = goal.theta;
    probe.copy(camera);
    let distance = distanceFor(box.getBoundingSphere(new THREE.Sphere()).radius + 1);
    const point = new THREE.Vector3();
    for (let pass = 0; pass < 5; pass++) {
      const s = Math.sin(phi);
      probe.position.set(center.x + distance * s * Math.sin(theta), center.y + distance * Math.cos(phi), center.z + distance * s * Math.cos(theta));
      probe.lookAt(center);
      probe.updateMatrixWorld();
      let reach = 0;
      list.forEach((o) => {
        [-1, 1].forEach((side) => {
          point.copy(o.base).addScaledVector(UP, side * CARD_H * 0.6 * o.scale).project(probe);
          reach = Math.max(reach, Math.abs(point.x) / 0.9, Math.abs(point.y) / 0.84);
        });
      });
      rims.forEach((p) => { point.copy(p).project(probe); reach = Math.max(reach, Math.abs(point.x) / 0.92, Math.abs(point.y) / 0.9); });
      distance *= THREE.MathUtils.clamp(reach, 0.5, 2);
    }
    goal.target.copy(center);
    goal.radius = THREE.MathUtils.clamp(distance, 4.5, 160);
    goal.phi = phi;
  }

  /* ---------- Montagem da cena ---------- */
  const objects = new Map();
  let edges = [];
  let particles = null;
  let clusterLabels = [];
  let pickables = [];
  let hoverId = null;
  let highlight = { focus: null, near: null, theme: null, themeKey: null };
  let firstBuild = true;

  function dispose(object) {
    object.traverse((child) => {
      if (child.geometry && ![cardGeo, edgeGeo, auraGeo, shadowGeo, coneGeo, orbGeo, hitGeo].includes(child.geometry)) child.geometry.dispose();
      if (child.material) (Array.isArray(child.material) ? child.material : [child.material]).forEach((m) => m.dispose());
    });
  }

  function layout(list) {
    const positions = new Map();
    const ring = list.filter((b) => !['commander', 'ghost'].includes(b.key));
    ring.forEach((b) => { b.r3 = b.cards.length ? SPACING * 0.62 * Math.sqrt(b.cards.length) + 0.75 : 1.2; });
    const gap = 1.7;
    const circumference = ring.reduce((sum, b) => sum + 2 * b.r3 + gap, 0);
    const maxR = Math.max(1, ...ring.map((b) => b.r3));
    const R = Math.max(4 + maxR, circumference / (Math.PI * 2));
    let acc = 0;
    ring.forEach((b) => {
      const arc = 2 * b.r3 + gap;
      b.angle = ((acc + arc / 2) / Math.max(circumference, 1)) * Math.PI * 2 - Math.PI / 2;
      acc += arc;
      b.center = new THREE.Vector3(Math.cos(b.angle) * R, 0, Math.sin(b.angle) * R);
      // No miolo do território ficam as cartas com mais relações, um pouco mais altas.
      const cards = b.cards.slice().sort((x, y) => y.degree - x.degree || x.name.localeCompare(y.name));
      cards.forEach((n, i) => {
        const rr = SPACING * 0.62 * Math.sqrt(i + (i ? 0.5 : 0));
        const a = i * GOLDEN + b.angle;
        const t = rr / b.r3;
        positions.set(n.id, new THREE.Vector3(b.center.x + Math.cos(a) * rr, 0.2 + 1.15 * (1 - t * t) + hash(n.id) * 0.35, b.center.z + Math.sin(a) * rr));
      });
      b.hubs.forEach((h, j) => {
        const a = (j / Math.max(1, b.hubs.length)) * Math.PI * 2 + b.angle;
        const spread = b.hubs.length > 1 ? Math.min(b.r3 * 0.7, 1.1 + b.hubs.length * 0.25) : 0;
        positions.set(h.id, new THREE.Vector3(b.center.x + Math.cos(a) * spread, 3.1 + (j % 2) * 0.7, b.center.z + Math.sin(a) * spread));
      });
      b.top = 2.2 + (b.hubs.length ? 1.6 + b.hubs.length * 0.25 : 0);
    });
    const commander = list.find((b) => b.key === 'commander');
    commander?.cards.forEach((n) => positions.set(n.id, new THREE.Vector3(0, 1.85, 0)));
    let radius = R + maxR;
    // Sugestões da coleção: numa órbita externa, na direção das cartas com que se ligariam.
    const ghosts = list.find((b) => b.key === 'ghost');
    if (ghosts) {
      const rg = radius + 3.4;
      const items = ghosts.cards.map((n, i) => {
        const centroid = new THREE.Vector3();
        let count = 0;
        (api.visible.neighbors.get(n.id) || []).forEach((id) => { const p = positions.get(id); if (p) { centroid.add(p); count++; } });
        let angle = count && (Math.abs(centroid.x) + Math.abs(centroid.z)) > 0.01 ? Math.atan2(centroid.z, centroid.x) : (i / ghosts.cards.length) * Math.PI * 2;
        return { n, angle };
      }).sort((a, b) => a.angle - b.angle);
      const minGap = 1.9 / rg;
      for (let pass = 0; pass < 40; pass++) {
        for (let i = 0; i < items.length; i++) {
          const next = items[(i + 1) % items.length];
          let diff = next.angle - items[i].angle;
          if (i === items.length - 1) diff += Math.PI * 2;
          if (items.length > 1 && diff < minGap) { const push = (minGap - diff) / 2; items[i].angle -= push; next.angle += push; }
        }
      }
      items.forEach(({ n, angle }) => positions.set(n.id, new THREE.Vector3(Math.cos(angle) * rg, 2.2 + hash(n.id) * 0.8, Math.sin(angle) * rg)));
      radius = rg + 1;
    }
    return { positions, ring, radius };
  }

  function makeCard(n, position, color) {
    const scale = n.stage === 'commander' ? 2 : n.stage === 'ghost' ? 1.08 : n.id === api.LAND_GROUP ? 1.25 : 1.2;
    const holder = new THREE.Group();
    holder.position.copy(position);
    holder.scale.setScalar(scale);
    const card = new THREE.Group();
    holder.add(card);
    const aura = new THREE.Mesh(auraGeo, new THREE.MeshBasicMaterial({ map: glow, color: n.stage === 'commander' ? COMMANDER_GLOW : n.stage === 'ghost' ? GHOST_GLOW : color, transparent: true, opacity: 0, depthWrite: false, blending: THREE.AdditiveBlending }));
    aura.position.z = -0.05;
    card.add(aura);
    const faces = [];
    const addFace = (image, offset, node) => {
      const back = new THREE.Mesh(edgeGeo, new THREE.MeshBasicMaterial({ color: 0x080d0b, alphaMap: cardAlpha, transparent: true, alphaTest: 0.5 }));
      back.position.set(offset.x, offset.y, offset.z - 0.012);
      const material = new THREE.MeshBasicMaterial({ map: fallbackTexture(node), alphaMap: cardAlpha, transparent: true, alphaTest: 0.5 });
      const face = new THREE.Mesh(cardGeo, material);
      face.position.copy(offset);
      face.userData.id = n.id;
      card.add(back, face);
      faces.push({ face, back, material, image, hi: false, hiLoading: false });
      if (image) texture(image.replace('size=small', n.stage === 'commander' ? 'size=normal' : 'size=small'), (tex) => { material.map = tex; material.needsUpdate = true; }, n.stage === 'commander');
      pickables.push(face);
    };
    if (n.id === api.LAND_GROUP) {
      n.members.slice(0, 3).reverse().forEach((m, i, all) => addFace(m.image, new THREE.Vector3((all.length - 1 - i) * -0.12, (all.length - 1 - i) * 0.1, -(all.length - 1 - i) * 0.03), m));
    } else addFace(n.image, new THREE.Vector3(), n);
    const shadow = new THREE.Mesh(shadowGeo, new THREE.MeshBasicMaterial({ map: shadowTex, transparent: true, depthWrite: false, opacity: 0.55 }));
    shadow.rotation.x = -Math.PI / 2;
    shadow.position.set(position.x, FLOOR_Y + 0.005, position.z);
    shadow.renderOrder = -1;
    const lift = position.y - FLOOR_Y;
    shadow.scale.setScalar(scale * (1.1 + lift * 0.18));
    shadow.userData.baseOpacity = THREE.MathUtils.clamp(0.75 - lift * 0.1, 0.18, 0.6);
    world.add(holder, shadow);
    return { id: n.id, node: n, kind: 'card', holder, card, aura, faces, shadow, base: position.clone(), scale, radius: 0.74 * scale, phase: hash(n.id) * Math.PI * 2, dim: 0, zoom: 1 };
  }

  function makeHub(n, position, color) {
    const holder = new THREE.Group();
    holder.position.copy(position);
    const orb = new THREE.Mesh(orbGeo, new THREE.MeshBasicMaterial({ color, transparent: true }));
    const halo = new THREE.Sprite(new THREE.SpriteMaterial({ map: glow, color, transparent: true, opacity: 0.65, depthWrite: false, blending: THREE.AdditiveBlending }));
    halo.scale.setScalar(1.7);
    const hit = new THREE.Mesh(hitGeo, new THREE.MeshBasicMaterial({ visible: false }));
    hit.userData.id = n.id;
    const plate = hubPlate(n, color);
    plate.userData.id = n.id;
    holder.add(halo, orb, hit, plate);
    pickables.push(hit, plate);
    world.add(holder);
    return { id: n.id, node: n, kind: 'hub', holder, orb, halo, plate, base: position.clone(), scale: 1, radius: 0.42, phase: hash(n.id) * Math.PI * 2, dim: 0, zoom: 1 };
  }

  /** Placa do tema acima do nó: tamanho fixo na tela, mas encoberta pelas cartas que estiverem na frente. */
  function hubPlate(n, color) {
    const count = `${n.providers.size} → ${n.consumers.size}`;
    const measure = document.createElement('canvas').getContext('2d');
    measure.font = '700 40px "Segoe UI", system-ui, sans-serif';
    const nameW = measure.measureText(n.name).width;
    measure.font = '600 32px "Segoe UI", system-ui, sans-serif';
    const countW = measure.measureText(count).width;
    const w = Math.ceil(nameW + countW + 78); const h = 76;
    const fill = new THREE.Color(color).lerp(new THREE.Color('#0b1310'), 0.62).getStyle();
    const stroke = new THREE.Color(color).lerp(new THREE.Color('#ffffff'), 0.35).getStyle();
    const tex = canvasTexture(w, h, (ctx) => {
      roundRect(ctx, 3, 3, w - 6, h - 6, (h - 6) / 2);
      ctx.fillStyle = fill; ctx.fill(); ctx.lineWidth = 3; ctx.strokeStyle = stroke; ctx.stroke();
      ctx.textBaseline = 'middle';
      ctx.font = '700 40px "Segoe UI", system-ui, sans-serif'; ctx.fillStyle = '#f4f6f3'; ctx.fillText(n.name, 28, h / 2 + 1);
      ctx.font = '600 32px "Segoe UI", system-ui, sans-serif'; ctx.fillStyle = 'rgba(244,246,243,.62)'; ctx.fillText(count, 28 + nameW + 22, h / 2 + 2);
    });
    const plate = new THREE.Sprite(new THREE.SpriteMaterial({ map: tex, transparent: true, depthWrite: false, sizeAttenuation: false }));
    plate.center.set(0.5, 0);
    plate.scale.set(0.025 * (w / h), 0.025, 1);
    plate.position.y = 0.42;
    return plate;
  }

  /** Nome do território impresso no tapete, como as zonas de um playmat; gira para ficar de frente para a câmera. */
  function floorLabel(b, parts) {
    const w = 1024; const h = 300;
    const light = new THREE.Color(b.color).lerp(new THREE.Color('#f4f1ea'), 0.5).getStyle();
    const tex = canvasTexture(w, h, (ctx) => {
      ctx.textAlign = 'center';
      let size = 118;
      ctx.font = `700 ${size}px Spectral, Georgia, serif`;
      while (ctx.measureText(b.label).width > w - 60 && size > 64) { size -= 6; ctx.font = `700 ${size}px Spectral, Georgia, serif`; }
      ctx.fillStyle = light; ctx.shadowColor = 'rgba(0,0,0,.55)'; ctx.shadowBlur = 14;
      ctx.fillText(b.label, w / 2, 150);
      ctx.shadowBlur = 0;
      ctx.font = '600 52px "Segoe UI", system-ui, sans-serif'; ctx.fillStyle = 'rgba(196,211,203,.9)';
      ctx.fillText(parts, w / 2, 240);
    });
    tex.anisotropy = maxAniso;
    const width3 = THREE.MathUtils.clamp(b.r3 * 1.6, 4.8, 8.5);
    const mesh = new THREE.Mesh(new THREE.PlaneGeometry(width3, width3 * (h / w)), new THREE.MeshBasicMaterial({ map: tex, transparent: true, depthWrite: false }));
    mesh.rotation.order = 'YXZ';
    mesh.renderOrder = -1;
    mesh.userData.territory = true;
    world.add(mesh);
    return { mesh, center: b.center.clone(), rim: b.r3 + 0.8, half: (width3 * (h / w)) / 2, key: b.key };
  }

  function label(className, html, color) {
    const el = document.createElement('div');
    el.className = `board-label ${className}`;
    el.innerHTML = html;
    if (color) el.style.setProperty('--c', color);
    labelsEl.appendChild(el);
    return el;
  }
  const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  function build() {
    edges.forEach((e) => { world.remove(e.mesh, e.cone); dispose(e.mesh); e.cone.material.dispose(); });
    objects.forEach((o) => { world.remove(o.holder); if (o.shadow) world.remove(o.shadow); dispose(o.holder); if (o.shadow) dispose(o.shadow); });
    world.children.filter((c) => c.userData.territory).forEach((c) => { world.remove(c); dispose(c); });
    if (particles) { world.remove(particles.points); particles.points.geometry.dispose(); particles.points.material.dispose(); particles = null; }
    objects.clear(); edges = []; pickables = [];
    labelsEl.innerHTML = '';
    clusterLabels = [];

    const list = api.buckets();
    const { positions, ring, radius } = layout(list);
    sceneRadius = radius;
    floor.scale.setScalar(radius * 2.9);
    // Territórios: um disco e um contorno no chão para cada tema.
    ring.forEach((b) => {
      const disc = new THREE.Mesh(new THREE.CircleGeometry(b.r3 + 0.55, 64), new THREE.MeshBasicMaterial({ color: b.color, transparent: true, opacity: 0.08, depthWrite: false }));
      const edge = new THREE.Mesh(new THREE.RingGeometry(b.r3 + 0.5, b.r3 + 0.58, 96), new THREE.MeshBasicMaterial({ color: b.color, transparent: true, opacity: 0.55, depthWrite: false }));
      [disc, edge].forEach((m) => { m.rotation.x = -Math.PI / 2; m.position.set(b.center.x, FLOOR_Y, b.center.z); m.renderOrder = -2; m.userData.territory = true; world.add(m); });
      const parts = [b.count ? `${b.count} ${b.count === 1 ? 'carta' : 'cartas'}` : '', b.hubs.length ? `${b.hubs.length} ${b.hubs.length === 1 ? 'tema' : 'temas'}` : ''].filter(Boolean).join(' · ');
      clusterLabels.push(floorLabel(b, parts));
    });
    const commanderBucket = list.find((b) => b.key === 'commander');
    const keyOf = new Map();
    list.forEach((b) => [...b.cards, ...b.hubs].forEach((n) => keyOf.set(n.id, b)));
    api.visible.nodes.forEach((n) => {
      const position = positions.get(n.id);
      if (!position) return;
      const bucket = keyOf.get(n.id) || commanderBucket;
      const color = bucket?.color || '#8f9a94';
      const object = n.stage === 'hub' ? makeHub(n, position, api.groupColor(n.group)) : makeCard(n, position, color);
      if (n.stage !== 'hub') {
        const name = `${escapeHtml(n.name)}${n.stage === 'ghost' ? `<small>${api.state.added.has(n.id) ? 'candidata' : 'da coleção'}</small>` : n.degree ? `<small>${n.degree} ${n.degree === 1 ? 'relação' : 'relações'}</small>` : ''}`;
        object.label = label(`is-card${n.stage === 'ghost' ? ' is-ghost' : ''}${n.stage === 'commander' ? ' is-commander' : ''}`, name, color);
        object.labelOffset = -(CARD_H * object.scale) / 2 - 0.1;
      }
      objects.set(n.id, object);
    });
    buildEdges();
    if (firstBuild) { frame(); orbit.target.copy(goal.target); orbit.radius = goal.radius * 1.35; orbit.phi = goal.phi; firstBuild = false; }
    applyHighlight();
  }

  function buildEdges() {
    const pairs = new Set(api.visible.edges.map((e) => `${e.from}>${e.to}`));
    const flow = [];
    api.visible.edges.forEach((e) => {
      const a = objects.get(e.from); const b = objects.get(e.to);
      if (!a || !b) return;
      const group = e.ghost ? 'ghost' : api.primaryGroup(e) || 'combo';
      const color = new THREE.Color(api.groupColor(group));
      const dist = a.base.distanceTo(b.base);
      const control = a.base.clone().add(b.base).multiplyScalar(0.5);
      control.y += 0.55 + dist * 0.3;
      if (pairs.has(`${e.to}>${e.from}`)) {
        const side = new THREE.Vector3().subVectors(b.base, a.base).cross(UP).normalize().multiplyScalar(0.3 + dist * 0.06);
        control.add(side);
      }
      const curve = new THREE.QuadraticBezierCurve3(a.base, control, b.base);
      const samples = curve.getSpacedPoints(48);
      let start = samples.findIndex((p) => p.distanceTo(a.base) > a.radius);
      let end = samples.length - 1 - [...samples].reverse().findIndex((p) => p.distanceTo(b.base) > b.radius + 0.05);
      if (start < 0 || end <= start + 1) { start = 0; end = samples.length - 1; }
      const points = samples.slice(start, end + 1);
      const path = new THREE.CatmullRomCurve3(points);
      const radius = 0.011 + Math.min(e.weight, 4) * 0.005;
      const material = new THREE.MeshBasicMaterial({ color, transparent: true, opacity: 0.3, depthWrite: false });
      const mesh = new THREE.Mesh(new THREE.TubeGeometry(path, Math.max(12, Math.round(dist * 5)), radius, 5, false), material);
      const cone = new THREE.Mesh(coneGeo, material.clone());
      const tip = points[points.length - 1];
      cone.position.copy(tip);
      cone.quaternion.setFromUnitVectors(UP, tip.clone().sub(points[points.length - 2]).normalize());
      world.add(mesh, cone);
      let length = 0;
      for (let i = 1; i < points.length; i++) length += points[i].distanceTo(points[i - 1]);
      const item = { edge: e, mesh, cone, material, points, color, length, group, opacity: 0.3, glow: 0 };
      edges.push(item);
      const count = e.weight > 2.4 || group === 'combo' ? 2 : 1;
      for (let k = 0; k < count; k++) flow.push({ item, offset: (k / count + hash(`${e.from}${e.to}`)) % 1 });
    });
    if (flow.length) {
      const geometry = new THREE.BufferGeometry();
      geometry.setAttribute('position', new THREE.BufferAttribute(new Float32Array(flow.length * 3), 3));
      geometry.setAttribute('color', new THREE.BufferAttribute(new Float32Array(flow.length * 3), 3));
      const points = new THREE.Points(geometry, new THREE.PointsMaterial({ size: 0.3, map: glow, vertexColors: true, transparent: true, depthWrite: false, blending: THREE.AdditiveBlending }));
      points.frustumCulled = false;
      world.add(points);
      particles = { points, flow };
    }
  }

  /* ---------- Foco, tema e passagem do mouse ---------- */
  function applyHighlight() {
    const { focus, near, theme, themeKey } = highlight;
    const active = near || theme;
    objects.forEach((o) => {
      o.targetDim = active && !active.has(o.id) ? 1 : 0;
      o.targetZoom = o.id === focus ? 1.16 : o.id === hoverId ? 1.1 : 1;
      o.targetAura = o.node.stage === 'commander' ? 0.5 : o.id === focus ? 0.8 : o.id === hoverId ? 0.55 : o.node.stage === 'ghost' ? 0.28 : active && active.has(o.id) && theme ? 0.35 : 0;
      if (!o.label) return;
      // Rótulos só onde ajudam: a carta em foco, a que está sob o mouse e vizinhas quando são poucas.
      const show = o.id === focus || o.id === hoverId || (near && nearShowsNames && near.has(o.id)) || (theme && !near && themeShowsNames && theme.has(o.id));
      o.label.classList.toggle('is-shown', !!show);
      o.label.classList.toggle('is-dim', !!active && !active.has(o.id));
      o.label.classList.toggle('is-focus', o.id === focus);
      o.label.classList.toggle('is-hover', o.id === hoverId);
    });
    edges.forEach((item) => {
      const e = item.edge;
      const touchesHover = hoverId && (e.from === hoverId || e.to === hoverId);
      let on;
      if (focus) on = e.from === focus || e.to === focus;
      else if (themeKey) on = e.reasons.some((r) => r.key === themeKey);
      else on = touchesHover;
      // Linhas das sugestões ficam discretas até alguém olhar para elas.
      const idle = e.ghost ? 0.1 : 0.4;
      item.opacity = on ? 0.92 : focus || themeKey ? 0.035 : hoverId ? 0.08 : idle;
      item.glow = on ? 1 : focus || themeKey ? 0 : hoverId ? 0.12 : e.ghost ? 0.2 : 0.7;
    });
    clusterLabels.forEach((c) => { c.mesh.material.opacity = active ? 0.3 : 1; });
  }
  let themeShowsNames = false;
  let nearShowsNames = false;

  /* ---------- Tamanho do palco ---------- */
  let width = 1; let height = 1;
  function resize() {
    const rect = host.getBoundingClientRect();
    width = Math.max(1, rect.width); height = Math.max(1, rect.height);
    renderer.setSize(width, height, false);
    const offset = Math.min(api.panelWidth(), width * 0.6);
    if (offset > 0) {
      camera.aspect = (width + offset) / height;
      camera.setViewOffset(width + offset, height, offset, 0, width, height);
    } else {
      camera.aspect = width / height;
      camera.clearViewOffset();
    }
    camera.updateProjectionMatrix();
  }
  new ResizeObserver(() => resize()).observe(host);

  /* ---------- Ponteiro: girar, mover, aproximar e escolher cartas ---------- */
  const raycaster = new THREE.Raycaster();
  const pointer = new THREE.Vector2();
  const pointers = new Map();
  let drag = null;
  let pointerInside = false;
  let needsPick = false;
  let lastInteraction = 0;
  function pick() {
    raycaster.setFromCamera(pointer, camera);
    const hit = raycaster.intersectObjects(pickables, false)[0];
    return hit ? hit.object.userData.id : null;
  }
  function setPointer(event) {
    const rect = dom.getBoundingClientRect();
    pointer.set(((event.clientX - rect.left) / rect.width) * 2 - 1, -((event.clientY - rect.top) / rect.height) * 2 + 1);
  }
  function pan(dx, dy) {
    const right = new THREE.Vector3().setFromMatrixColumn(camera.matrix, 0).setY(0).normalize();
    const forward = new THREE.Vector3().crossVectors(UP, right).normalize();
    const s = orbit.radius * 0.0017;
    goal.target.addScaledVector(right, -dx * s).addScaledVector(forward, dy * s);
  }
  dom.addEventListener('contextmenu', (event) => event.preventDefault());
  dom.addEventListener('pointerdown', (event) => {
    dom.setPointerCapture(event.pointerId);
    pointers.set(event.pointerId, { x: event.clientX, y: event.clientY });
    lastInteraction = performance.now();
    if (pointers.size === 1) drag = { x: event.clientX, y: event.clientY, sx: event.clientX, sy: event.clientY, moved: false, mode: event.button === 2 || event.shiftKey || event.ctrlKey || event.metaKey ? 'pan' : 'rotate' };
    else drag = { ...drag, moved: true, pinch: null };
    dom.classList.add('is-dragging');
  });
  dom.addEventListener('pointermove', (event) => {
    setPointer(event);
    if (!pointers.has(event.pointerId)) { needsPick = true; return; }
    const previous = pointers.get(event.pointerId);
    pointers.set(event.pointerId, { x: event.clientX, y: event.clientY });
    lastInteraction = performance.now();
    if (pointers.size === 2) {
      const [p1, p2] = [...pointers.values()];
      const distance = Math.hypot(p1.x - p2.x, p1.y - p2.y);
      const middle = { x: (p1.x + p2.x) / 2, y: (p1.y + p2.y) / 2 };
      if (drag.pinch) {
        goal.radius = THREE.MathUtils.clamp(goal.radius * (drag.pinch.distance / Math.max(1, distance)), 2.5, 200);
        pan(middle.x - drag.pinch.middle.x, middle.y - drag.pinch.middle.y);
      }
      drag.pinch = { distance, middle };
      return;
    }
    if (!drag) return;
    const dx = event.clientX - previous.x; const dy = event.clientY - previous.y;
    if (Math.abs(event.clientX - drag.sx) + Math.abs(event.clientY - drag.sy) > 5) drag.moved = true;
    if (!drag.moved) return;
    if (drag.mode === 'pan') pan(dx, dy);
    else { goal.theta -= dx * 0.0058; goal.phi = THREE.MathUtils.clamp(goal.phi - dy * 0.0048, 0.18, 1.45); }
  });
  const release = (event) => {
    pointers.delete(event.pointerId);
    if (pointers.size) return;
    dom.classList.remove('is-dragging');
    if (drag && !drag.moved && event.type === 'pointerup') {
      setPointer(event);
      const id = pick();
      api.select(id && id === api.state.focus ? null : id);
    }
    drag = null;
  };
  dom.addEventListener('pointerup', release);
  dom.addEventListener('pointercancel', release);
  dom.addEventListener('pointerenter', () => { pointerInside = true; });
  dom.addEventListener('pointerleave', () => { pointerInside = false; if (hoverId) setHover(null); });
  dom.addEventListener('wheel', (event) => {
    event.preventDefault();
    lastInteraction = performance.now();
    goal.radius = THREE.MathUtils.clamp(goal.radius * Math.exp(event.deltaY * 0.0011), 2.5, 200);
  }, { passive: false });

  function setHover(id) {
    if (id === hoverId) return;
    hoverId = id;
    api.hover(id);
    dom.style.cursor = id ? 'pointer' : '';
    applyHighlight();
  }

  /* ---------- Quadro a quadro ---------- */
  const projected = new THREE.Vector3();
  function placeLabel(el, point) {
    projected.copy(point).project(camera);
    const hidden = projected.z > 1 || projected.x < -1.2 || projected.x > 1.2 || projected.y < -1.2 || projected.y > 1.2;
    el.style.visibility = hidden ? 'hidden' : '';
    if (!hidden) el.style.transform = `translate(${((projected.x + 1) / 2 * width).toFixed(1)}px, ${((1 - projected.y) / 2 * height).toFixed(1)}px) translate(-50%, 0)`;
  }

  let time = 0;
  let upgradeClock = 0;
  const color = new THREE.Color();
  const anchor = new THREE.Vector3();
  function tick(dt) {
    const still = api.reducedMotion();
    if (!still) time += dt;
    const spin = api.state.spin && !still && !pointerInside && !highlight.focus && !drag && performance.now() - lastInteraction > 2500;
    if (spin) goal.theta += dt * 0.045;
    placeCamera(dt);
    if (needsPick && !drag) { needsPick = false; setHover(pick()); }
    const ease = still ? 1 : 1 - Math.exp(-dt * 9);
    objects.forEach((o) => {
      const bob = still ? 0 : Math.sin(time * 0.9 + o.phase) * (o.kind === 'hub' ? 0.12 : 0.09);
      o.holder.position.set(o.base.x, o.base.y + bob, o.base.z);
      o.dim += ((o.targetDim ?? 0) - o.dim) * ease;
      o.zoom += ((o.targetZoom ?? 1) - o.zoom) * ease;
      o.holder.scale.setScalar(o.scale * o.zoom);
      const light = 1 - o.dim * 0.72;
      if (o.kind === 'card') {
        o.card.quaternion.copy(camera.quaternion);
        if (!still) { o.card.rotateY(Math.sin(time * 0.55 + o.phase) * 0.09); o.card.rotateX(Math.cos(time * 0.47 + o.phase) * 0.04); }
        const ghost = o.node.stage === 'ghost';
        o.faces.forEach((f) => {
          f.material.color.setScalar(light);
          f.material.opacity = (ghost ? 0.7 : 1) * (1 - o.dim * 0.5);
          f.back.material.opacity = f.material.opacity;
        });
        o.aura.material.opacity += ((o.targetAura ?? 0) * (1 - o.dim) - o.aura.material.opacity) * ease;
        o.shadow.material.opacity = o.shadow.userData.baseOpacity * (1 - o.dim * 0.7) * (1 - bob * 0.8);
      } else {
        o.orb.material.color.set(api.groupColor(o.node.group)).multiplyScalar(light);
        o.halo.material.opacity = 0.65 * (1 - o.dim * 0.85) + (o.id === hoverId || o.id === highlight.focus ? 0.3 : 0);
        o.plate.material.opacity = 1 - o.dim * 0.7;
      }
    });
    edges.forEach((item) => {
      item.material.opacity += (item.opacity - item.material.opacity) * ease;
      item.cone.material.opacity = item.material.opacity;
    });
    if (particles) {
      particles.points.visible = !still;
      const position = particles.points.geometry.attributes.position;
      const colors = particles.points.geometry.attributes.color;
      particles.flow.forEach(({ item, offset }, i) => {
        const u = (time * 1.5 / Math.max(item.length, 0.5) + offset) % 1;
        const f = u * (item.points.length - 1);
        const k = Math.floor(f);
        const a = item.points[k]; const b = item.points[Math.min(k + 1, item.points.length - 1)];
        position.setXYZ(i, a.x + (b.x - a.x) * (f - k), a.y + (b.y - a.y) * (f - k), a.z + (b.z - a.z) * (f - k));
        // Faíscas acendem no meio do caminho e apagam perto das pontas.
        const fade = Math.sin(u * Math.PI) * item.glow;
        color.copy(item.color).multiplyScalar(fade);
        colors.setXYZ(i, color.r, color.g, color.b);
      });
      position.needsUpdate = true;
      colors.needsUpdate = true;
    }
    dust.rotation.y += dt * 0.01;
    // O nome de cada território fica na borda voltada para a câmera, levemente inclinado para ser lido.
    const angle = orbit.theta;
    clusterLabels.forEach((c) => {
      const tilt = 0.75;
      c.mesh.position.set(c.center.x + Math.sin(angle) * (c.rim + c.half), FLOOR_Y + 0.03 + c.half * Math.sin(tilt), c.center.z + Math.cos(angle) * (c.rim + c.half));
      c.mesh.rotation.set(-Math.PI / 2 + tilt, angle, 0);
    });
    // Imagem normal para as cartas que ficaram grandes na tela.
    upgradeClock += dt;
    if (upgradeClock > 0.4) {
      upgradeClock = 0;
      const perUnit = height / (2 * Math.tan(THREE.MathUtils.degToRad(camera.fov) / 2));
      objects.forEach((o) => {
        if (o.kind !== 'card') return;
        const px = (CARD_H * o.scale * perUnit) / camera.position.distanceTo(o.holder.position);
        if (px < 170) return;
        o.faces.forEach((f) => {
          if (!f.image || f.hi || f.hiLoading) return;
          f.hiLoading = true;
          texture(f.image.replace('size=small', 'size=normal'), (tex) => { f.hi = true; f.material.map = tex; f.material.needsUpdate = true; }, true);
        });
      });
    }
  }

  const cameraUp = new THREE.Vector3();
  function updateLabels() {
    cameraUp.set(0, 1, 0).applyQuaternion(camera.quaternion);
    objects.forEach((o) => {
      if (!o.label || !o.label.classList.contains('is-shown')) return;
      anchor.copy(o.holder.position).addScaledVector(cameraUp, o.labelOffset * o.zoom);
      placeLabel(o.label, anchor);
    });
  }

  // Só desenha com a vista 3D ativa e o palco na tela; um enquadramento pedido com o palco oculto espera ele voltar.
  let raf = 0;
  let last = 0;
  let running = false;
  let onScreen = true;
  let pendingFrame = null;
  function loop(now) {
    raf = requestAnimationFrame(loop);
    const dt = Math.min(0.05, (now - (last || now)) / 1000);
    last = now;
    tick(dt);
    renderer.render(scene, camera);
    updateLabels();
  }
  function schedule() {
    cancelAnimationFrame(raf);
    if (running && onScreen) { last = 0; raf = requestAnimationFrame(loop); }
  }
  function requestFrame(ids) {
    if (running && width > 1) frame(ids);
    else pendingFrame = ids || 'all';
  }
  new IntersectionObserver(([entry]) => { onScreen = entry.isIntersecting; schedule(); }).observe(host);

  resize();
  build();

  return {
    update(reset = false) { build(); if (reset) requestFrame(null); },
    setActive(on) {
      if (on === running) return;
      running = on;
      if (on) {
        resize();
        if (pendingFrame) { frame(pendingFrame === 'all' ? null : pendingFrame); pendingFrame = null; }
      }
      schedule();
    },
    setFocus(next) {
      const changedFocus = next.focus !== highlight.focus;
      const changedTheme = next.themeKey !== highlight.themeKey;
      highlight = next;
      themeShowsNames = !!next.theme && next.theme.size <= 12;
      nearShowsNames = !!next.near && next.near.size <= 9;
      applyHighlight();
      if (changedFocus && next.focus && next.near) requestFrame(next.near);
      else if (changedTheme && next.theme && !next.focus) requestFrame(next.theme);
    },
    setHover,
    markAdded(id) {
      const small = objects.get(id)?.label?.querySelector('small');
      if (small) small.textContent = 'candidata';
    },
    fit() { goal.theta = orbit.theta; frame(); },
    zoom(dir) { goal.radius = THREE.MathUtils.clamp(goal.radius * (dir > 0 ? 0.78 : 1.28), 2.5, 200); lastInteraction = performance.now(); },
    resize,
  };
}
