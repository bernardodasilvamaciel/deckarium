/* Mesa 3D da Mesa de teste: tapete com a arte da comandante, cartas com espessura e sombra, pilhas das zonas,
   marcadores empilhados e os efeitos (mana na cor da carta, onda no tapete, exílio, compra). Só desenho e ponteiro:
   o estado da partida e as regras ficam em playtest.js, que chama sync() depois de cada ação. */
import * as THREE from './vendor/three.module.min.js';

export const CARD_W = 1.42;
export const CARD_H = CARD_W * 680 / 488;
const THICK = 0.028;
const MAT_W = 30;
const MAT_D = 18;
export const LAYOUT = {
  rows: { creature: -4.55, other: -1.85, land: 0.95, land2: 3.25 },
  left: -8.2,
  right: 8.4,
  spacing: 1.66,
  zones: {
    library: { x: 11.2, z: 3.0, label: 'Grimório' },
    graveyard: { x: 11.2, z: -0.1, label: 'Cemitério' },
    exile: { x: 11.2, z: -3.2, label: 'Exílio' },
    command: { x: -11.4, z: -4.1, label: 'Zona de comando' },
  },
};
export const MANA_COLORS = { W: '#fff1c9', U: '#4aa7ff', B: '#b07cff', R: '#ff5b3a', G: '#43d27c', C: '#d5dcd8' };
const COUNTER_COLORS = { '+1/+1': '#43c878', '-1/-1': '#b45ad6', Lealdade: '#e0b54e', Carga: '#4aa7ff', Tempo: '#9fd7e8', Escudo: '#f2f2ec' };

function canvasTexture(width, height, draw, color = true) {
  const canvas = document.createElement('canvas');
  canvas.width = width; canvas.height = height;
  draw(canvas.getContext('2d'), width, height);
  const texture = new THREE.CanvasTexture(canvas);
  if (color) texture.colorSpace = THREE.SRGBColorSpace;
  return texture;
}
function roundRect(ctx, x, y, w, h, r) {
  ctx.beginPath();
  ctx.moveTo(x + r, y); ctx.arcTo(x + w, y, x + w, y + h, r); ctx.arcTo(x + w, y + h, x, y + h, r);
  ctx.arcTo(x, y + h, x, y, r); ctx.arcTo(x, y, x + w, y, r); ctx.closePath();
}
const hash = (text) => { let h = 2166136261; for (let i = 0; i < text.length; i++) { h ^= text.charCodeAt(i); h = Math.imul(h, 16777619); } return ((h >>> 0) % 10000) / 10000; };

export function createPlaytestScene(host, hooks) {
  const renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true, powerPreference: 'high-performance' });
  renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
  renderer.outputColorSpace = THREE.SRGBColorSpace;
  renderer.shadowMap.enabled = true;
  renderer.shadowMap.type = THREE.PCFSoftShadowMap;
  renderer.setClearColor(0x000000, 0);
  const dom = renderer.domElement;
  dom.className = 'pt-webgl';
  host.prepend(dom);
  const maxAniso = renderer.capabilities.getMaxAnisotropy();

  const scene = new THREE.Scene();
  const camera = new THREE.PerspectiveCamera(36, 1, 0.1, 200);
  scene.add(new THREE.HemisphereLight(0xfff4e2, 0x0c140f, 1.15));
  const key = new THREE.DirectionalLight(0xfff0d4, 1.9);
  key.position.set(-7, 22, 9);
  key.castShadow = true;
  key.shadow.mapSize.set(2048, 2048);
  Object.assign(key.shadow.camera, { left: -17, right: 17, top: 12, bottom: -12, near: 1, far: 60 });
  key.shadow.bias = -0.0004;
  key.shadow.radius = 4;
  scene.add(key);

  /* ---------- Texturas ---------- */
  const cardAlpha = canvasTexture(128, 178, (ctx, w, h) => { ctx.fillStyle = '#000'; ctx.fillRect(0, 0, w, h); ctx.fillStyle = '#fff'; roundRect(ctx, 1, 1, w - 2, h - 2, 6); ctx.fill(); }, false);
  const glowTex = canvasTexture(128, 128, (ctx, w) => {
    const g = ctx.createRadialGradient(w / 2, w / 2, 0, w / 2, w / 2, w / 2);
    g.addColorStop(0, 'rgba(255,255,255,1)'); g.addColorStop(0.3, 'rgba(255,255,255,.5)'); g.addColorStop(1, 'rgba(255,255,255,0)');
    ctx.fillStyle = g; ctx.fillRect(0, 0, w, w);
  });
  const frameGlow = canvasTexture(160, 210, (ctx, w, h) => {
    ctx.clearRect(0, 0, w, h);
    ctx.shadowColor = '#fff'; ctx.shadowBlur = 18; ctx.strokeStyle = '#fff'; ctx.lineWidth = 7;
    roundRect(ctx, 16, 16, w - 32, h - 32, 10); ctx.stroke();
  });
  // Verso próprio do Deckarium (não imita o verso oficial das cartas).
  const backTex = canvasTexture(488, 680, (ctx, w, h) => {
    const g = ctx.createLinearGradient(0, 0, w, h);
    g.addColorStop(0, '#1f3a2e'); g.addColorStop(0.5, '#10231b'); g.addColorStop(1, '#0a1611');
    ctx.fillStyle = '#0b0f0d'; ctx.fillRect(0, 0, w, h);
    roundRect(ctx, 18, 18, w - 36, h - 36, 22); ctx.fillStyle = g; ctx.fill();
    ctx.strokeStyle = '#c9a44f'; ctx.lineWidth = 5; ctx.stroke();
    ctx.strokeStyle = 'rgba(201,164,79,.45)'; ctx.lineWidth = 2;
    for (let r = 60; r < 330; r += 34) { ctx.beginPath(); ctx.ellipse(w / 2, h / 2, r * 0.62, r, 0, 0, Math.PI * 2); ctx.stroke(); }
    ctx.fillStyle = '#e9d9a6'; ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
    ctx.font = '700 64px Spectral, Georgia, serif'; ctx.fillText('Deckarium', w / 2, h / 2);
    ctx.font = '600 20px "Segoe UI", sans-serif'; ctx.fillStyle = 'rgba(233,217,166,.7)'; ctx.fillText('MESA DE TESTE', w / 2, h / 2 + 52);
  });
  backTex.anisotropy = maxAniso;
  const edgeTex = canvasTexture(64, 64, (ctx, w, h) => {
    ctx.fillStyle = '#e9e4d6'; ctx.fillRect(0, 0, w, h);
    ctx.fillStyle = 'rgba(80,70,50,.35)'; for (let y = 0; y < h; y += 3) ctx.fillRect(0, y, w, 1);
  });

  const textures = new Map();
  const loader = new THREE.TextureLoader();
  function texture(url, onLoad) {
    if (!url) return null;
    if (textures.has(url)) { const t = textures.get(url); if (t.image) onLoad?.(t); else t.userData.waiting.push(onLoad); return t; }
    const t = loader.load(url, (loaded) => { loaded.userData.waiting.forEach((fn) => fn?.(loaded)); loaded.userData.waiting = []; });
    t.colorSpace = THREE.SRGBColorSpace;
    t.anisotropy = maxAniso;
    t.userData.waiting = onLoad ? [onLoad] : [];
    textures.set(url, t);
    return t;
  }
  const nameTextures = new Map();
  function nameTexture(card) {
    const keyName = `${card.name}|${card.pt || ''}`;
    if (nameTextures.has(keyName)) return nameTextures.get(keyName);
    const tex = canvasTexture(488, 680, (ctx, w, h) => {
      ctx.fillStyle = '#e9e4d6'; ctx.fillRect(0, 0, w, h);
      roundRect(ctx, 22, 22, w - 44, h - 44, 18); ctx.fillStyle = '#1d2a25'; ctx.fill();
      ctx.fillStyle = '#f4f1ea'; ctx.textAlign = 'center';
      ctx.font = '700 44px Spectral, Georgia, serif';
      const words = String(card.name).split(/\s+/); const lines = []; let line = '';
      words.forEach((word) => { const next = line ? `${line} ${word}` : word; if (ctx.measureText(next).width > w - 90 && line) { lines.push(line); line = word; } else line = next; });
      if (line) lines.push(line);
      lines.slice(0, 5).forEach((text, i) => ctx.fillText(text, w / 2, h * 0.36 + i * 52));
      ctx.font = '600 26px "Segoe UI", sans-serif'; ctx.fillStyle = 'rgba(214,226,218,.8)';
      ctx.fillText(String(card.type || '').slice(0, 30), w / 2, h - 120);
      if (card.pt) { ctx.font = '700 54px "Segoe UI", sans-serif'; ctx.fillStyle = '#f4f1ea'; ctx.fillText(card.pt, w / 2, h - 58); }
    });
    nameTextures.set(keyName, tex);
    return tex;
  }

  /* ---------- Tapete: arte da comandante, zonas e fileiras impressas ---------- */
  const matCanvas = document.createElement('canvas');
  matCanvas.width = 2400; matCanvas.height = Math.round(2400 * MAT_D / MAT_W);
  const matTex = new THREE.CanvasTexture(matCanvas);
  matTex.colorSpace = THREE.SRGBColorSpace;
  matTex.anisotropy = maxAniso;
  const toMat = (x, z) => [(x / MAT_W + 0.5) * matCanvas.width, (z / MAT_D + 0.5) * matCanvas.height];
  const perUnit = matCanvas.width / MAT_W;
  function paintMat(art) {
    const ctx = matCanvas.getContext('2d');
    const w = matCanvas.width; const h = matCanvas.height;
    ctx.fillStyle = '#132019'; ctx.fillRect(0, 0, w, h);
    if (art) {
      // Recorte da ilustração da carta normal (moldura moderna), desfocado e escurecido.
      const sx = art.width * 0.08; const sy = art.height * 0.11; const sw = art.width * 0.84; const sh = art.height * 0.44;
      const scale = Math.max(w / sw, h / sh);
      ctx.save();
      ctx.filter = 'blur(14px) saturate(1.15) brightness(.5)';
      ctx.drawImage(art, sx, sy, sw, sh, (w - sw * scale) / 2 - 20, (h - sh * scale) / 2 - 20, sw * scale + 40, sh * scale + 40);
      ctx.restore();
    }
    const vignette = ctx.createRadialGradient(w / 2, h * 0.45, h * 0.2, w / 2, h / 2, w * 0.62);
    vignette.addColorStop(0, 'rgba(10,20,15,.15)'); vignette.addColorStop(1, 'rgba(6,12,9,.82)');
    ctx.fillStyle = vignette; ctx.fillRect(0, 0, w, h);
    ctx.fillStyle = 'rgba(230,238,232,.035)';
    for (let i = 0; i < 9000; i++) ctx.fillRect(hash(`x${i}`) * w, hash(`y${i}`) * h, 2, 2);
    // Costura da borda.
    ctx.strokeStyle = 'rgba(217,180,90,.55)'; ctx.lineWidth = 4; ctx.setLineDash([18, 12]);
    roundRect(ctx, 26, 26, w - 52, h - 52, 60); ctx.stroke(); ctx.setLineDash([]);
    // Fileiras do campo de batalha.
    const rowLabel = (text, z) => {
      const [x0, y] = toMat(LAYOUT.left - 1.25, z);
      const [x1] = toMat(LAYOUT.right + 0.9, z);
      ctx.strokeStyle = 'rgba(214,226,218,.09)'; ctx.lineWidth = 2;
      ctx.beginPath(); ctx.moveTo(x0, y + perUnit * CARD_H * 0.62); ctx.lineTo(x1, y + perUnit * CARD_H * 0.62); ctx.stroke();
      ctx.save(); ctx.translate(x0 - 18, y); ctx.rotate(-Math.PI / 2);
      ctx.font = '600 30px "Segoe UI", sans-serif'; ctx.fillStyle = 'rgba(214,226,218,.32)'; ctx.textAlign = 'center'; ctx.fillText(text.toUpperCase(), 0, 0);
      ctx.restore();
    };
    rowLabel('Criaturas', LAYOUT.rows.creature);
    rowLabel('Outras permanentes', LAYOUT.rows.other);
    rowLabel('Terrenos', (LAYOUT.rows.land + LAYOUT.rows.land2) / 2);
    Object.entries(LAYOUT.zones).forEach(([zone, info]) => {
      const [cx, cy] = toMat(info.x, info.z);
      const bw = perUnit * (zone === 'command' ? CARD_W * 1.55 : CARD_W * 1.22); const bh = perUnit * (zone === 'command' ? CARD_H * 1.45 : CARD_H * 1.16);
      roundRect(ctx, cx - bw / 2, cy - bh / 2, bw, bh, 22);
      ctx.fillStyle = zone === 'command' ? 'rgba(217,180,90,.08)' : 'rgba(0,0,0,.22)'; ctx.fill();
      ctx.strokeStyle = zone === 'command' ? 'rgba(217,180,90,.7)' : zone === 'exile' ? 'rgba(120,190,255,.45)' : 'rgba(214,226,218,.3)';
      ctx.lineWidth = 3; ctx.setLineDash(zone === 'exile' ? [10, 8] : []); ctx.stroke(); ctx.setLineDash([]);
      ctx.font = '700 34px Spectral, Georgia, serif'; ctx.fillStyle = zone === 'command' ? 'rgba(233,210,150,.85)' : 'rgba(214,226,218,.62)';
      ctx.textAlign = 'center'; ctx.fillText(info.label, cx, cy + bh / 2 + 44);
    });
    matTex.needsUpdate = true;
  }
  paintMat(null);
  const mat = new THREE.Mesh(new THREE.PlaneGeometry(MAT_W, MAT_D), new THREE.MeshStandardMaterial({ map: matTex, roughness: 0.96, metalness: 0 }));
  mat.rotation.x = -Math.PI / 2;
  mat.receiveShadow = true;
  scene.add(mat);
  const table = new THREE.Mesh(new THREE.PlaneGeometry(90, 70), new THREE.MeshStandardMaterial({ color: 0x0b120e, roughness: 1 }));
  table.rotation.x = -Math.PI / 2;
  table.position.y = -0.02;
  table.receiveShadow = true;
  scene.add(table);

  /* ---------- Geometrias das cartas ---------- */
  const shape = new THREE.Shape();
  const r = 0.06; const hw = CARD_W / 2; const hh = CARD_H / 2;
  shape.moveTo(-hw + r, -hh); shape.lineTo(hw - r, -hh); shape.quadraticCurveTo(hw, -hh, hw, -hh + r); shape.lineTo(hw, hh - r); shape.quadraticCurveTo(hw, hh, hw - r, hh);
  shape.lineTo(-hw + r, hh); shape.quadraticCurveTo(-hw, hh, -hw, hh - r); shape.lineTo(-hw, -hh + r); shape.quadraticCurveTo(-hw, -hh, -hw + r, -hh);
  const bodyGeo = new THREE.ExtrudeGeometry(shape, { depth: THICK, bevelEnabled: false, curveSegments: 4 });
  bodyGeo.rotateX(-Math.PI / 2);
  const faceGeo = new THREE.PlaneGeometry(CARD_W, CARD_H);
  faceGeo.rotateX(-Math.PI / 2);
  const backGeo = new THREE.PlaneGeometry(CARD_W, CARD_H);
  backGeo.rotateX(Math.PI / 2);
  const glowGeo = new THREE.PlaneGeometry(CARD_W * 1.3, CARD_H * 1.25);
  glowGeo.rotateX(-Math.PI / 2);
  const chipGeo = new THREE.CylinderGeometry(0.2, 0.2, 0.07, 24);
  const ringGeo = new THREE.RingGeometry(0.9, 1, 64);
  ringGeo.rotateX(-Math.PI / 2);
  const bodyMat = new THREE.MeshStandardMaterial({ color: 0x151a17, roughness: 0.6 });

  function faceMaterial(map) {
    return new THREE.MeshStandardMaterial({ map, emissive: 0xffffff, emissiveMap: map, emissiveIntensity: 0.32, roughness: 0.5, metalness: 0, alphaMap: cardAlpha, alphaTest: 0.5, transparent: true });
  }

  function makeCard() {
    const group = new THREE.Group();
    const body = new THREE.Mesh(bodyGeo, bodyMat);
    body.castShadow = true;
    const face = new THREE.Mesh(faceGeo, faceMaterial(backTex));
    face.position.y = THICK + 0.001;
    const back = new THREE.Mesh(backGeo, faceMaterial(backTex));
    back.position.y = -0.001;
    const glow = new THREE.Mesh(glowGeo, new THREE.MeshBasicMaterial({ map: frameGlow, color: 0xffffff, transparent: true, opacity: 0, depthWrite: false, blending: THREE.AdditiveBlending }));
    glow.position.y = THICK + 0.004;
    group.add(body, face, back, glow);
    return { group, body, face, back, glow };
  }

  /* ---------- Pilhas das zonas ---------- */
  const piles = {};
  function makePile(zone) {
    const info = LAYOUT.zones[zone];
    const group = new THREE.Group();
    group.position.set(info.x, 0, info.z);
    const stackMat = [new THREE.MeshStandardMaterial({ map: edgeTex, roughness: 0.8 }), new THREE.MeshStandardMaterial({ map: edgeTex, roughness: 0.8 }), faceMaterial(backTex), bodyMat, new THREE.MeshStandardMaterial({ map: edgeTex, roughness: 0.8 }), new THREE.MeshStandardMaterial({ map: edgeTex, roughness: 0.8 })];
    const stack = new THREE.Mesh(new THREE.BoxGeometry(CARD_W, 1, CARD_H), stackMat);
    stack.castShadow = true; stack.receiveShadow = true;
    stack.userData.zone = zone;
    const glow = new THREE.Mesh(glowGeo, new THREE.MeshBasicMaterial({ map: frameGlow, color: zone === 'exile' ? 0x78beff : 0xffffff, transparent: true, opacity: 0, depthWrite: false, blending: THREE.AdditiveBlending }));
    group.add(stack, glow);
    scene.add(group);
    const count = sprite('', { size: 0.5 });
    count.position.set(0, 0.2, CARD_H / 2 + 0.05);
    group.add(count);
    piles[zone] = { group, stack, glow, count, n: -1, top: null, hover: 0 };
    return piles[zone];
  }
  ['library', 'graveyard', 'exile'].forEach(makePile);
  // Zona de comando: um pedestal com anel dourado.
  const dais = new THREE.Group();
  dais.position.set(LAYOUT.zones.command.x, 0, LAYOUT.zones.command.z);
  const daisBase = new THREE.Mesh(new THREE.CylinderGeometry(1.55, 1.7, 0.16, 48), new THREE.MeshStandardMaterial({ color: 0x1b2620, roughness: 0.4, metalness: 0.3 }));
  daisBase.position.y = 0.08; daisBase.receiveShadow = true; daisBase.castShadow = true;
  const daisRing = new THREE.Mesh(new THREE.TorusGeometry(1.6, 0.035, 12, 80), new THREE.MeshStandardMaterial({ color: 0xd9b45a, emissive: 0xd9b45a, emissiveIntensity: 0.6, metalness: 0.8, roughness: 0.3 }));
  daisRing.rotation.x = Math.PI / 2; daisRing.position.y = 0.16;
  const daisGlow = new THREE.Sprite(new THREE.SpriteMaterial({ map: glowTex, color: 0xd9b45a, transparent: true, opacity: 0.35, depthWrite: false, blending: THREE.AdditiveBlending }));
  daisGlow.scale.set(4.2, 4.2, 1); daisGlow.position.y = 0.3;
  const daisHit = new THREE.Mesh(new THREE.CylinderGeometry(1.6, 1.6, 0.5, 16), new THREE.MeshBasicMaterial({ visible: false }));
  daisHit.userData.zone = 'command';
  const taxLabel = sprite('', { size: 0.46, color: '#e9d296' });
  taxLabel.position.set(0, 0.35, 1.9);
  dais.add(daisBase, daisRing, daisGlow, daisHit, taxLabel);
  scene.add(dais);

  function sprite(text, { size = 0.42, color = '#f4f6f3', bg = 'rgba(9,15,12,.82)', border = null } = {}) {
    const s = new THREE.Sprite(new THREE.SpriteMaterial({ transparent: true, depthWrite: false, depthTest: false }));
    s.renderOrder = 10;
    s.userData = { text: null, size, color, bg, border };
    setSprite(s, text);
    return s;
  }
  function setSprite(s, text, overrides = {}) {
    Object.assign(s.userData, overrides);
    const signature = `${text}|${s.userData.color}|${s.userData.bg}|${s.userData.border}`;
    if (s.userData.text === signature) return;
    s.userData.text = signature;
    s.visible = text !== '';
    if (!text) return;
    const font = '700 44px "Segoe UI", system-ui, sans-serif';
    const measure = document.createElement('canvas').getContext('2d'); measure.font = font;
    const w = Math.ceil(measure.measureText(text).width + 44); const h = 64;
    const tex = canvasTexture(w, h, (ctx) => {
      roundRect(ctx, 2, 2, w - 4, h - 4, 28); ctx.fillStyle = s.userData.bg; ctx.fill();
      if (s.userData.border) { ctx.lineWidth = 3; ctx.strokeStyle = s.userData.border; ctx.stroke(); }
      ctx.font = font; ctx.fillStyle = s.userData.color; ctx.textAlign = 'center'; ctx.textBaseline = 'middle'; ctx.fillText(text, w / 2, h / 2 + 2);
    });
    s.material.map?.dispose();
    s.material.map = tex; s.material.needsUpdate = true;
    s.scale.set(s.userData.size * (w / h), s.userData.size, 1);
  }

  /* ---------- Partículas: mana, exílio, cinzas ---------- */
  const MAX_P = 1600;
  const pPos = new Float32Array(MAX_P * 3); const pCol = new Float32Array(MAX_P * 3);
  const pGeo = new THREE.BufferGeometry();
  pGeo.setAttribute('position', new THREE.BufferAttribute(pPos, 3));
  pGeo.setAttribute('color', new THREE.BufferAttribute(pCol, 3));
  const points = new THREE.Points(pGeo, new THREE.PointsMaterial({ size: 0.26, map: glowTex, vertexColors: true, transparent: true, depthWrite: false, blending: THREE.AdditiveBlending }));
  points.frustumCulled = false;
  scene.add(points);
  const particles = [];
  const tmpColor = new THREE.Color();
  const motionQuery = window.matchMedia('(prefers-reduced-motion: reduce)');
  const still = () => motionQuery.matches;
  function emit(x, y, z, count, colors, { speed = 3.2, up = 3, gravity = -5, life = 1.1, spread = 0.4, rise = 0 } = {}) {
    if (still()) return;
    for (let i = 0; i < count && particles.length < MAX_P; i++) {
      const a = Math.random() * Math.PI * 2; const s = speed * (0.35 + Math.random() * 0.8);
      particles.push({
        x: x + (Math.random() - 0.5) * spread, y, z: z + (Math.random() - 0.5) * spread,
        vx: Math.cos(a) * s, vy: up * (0.4 + Math.random()) + rise, vz: Math.sin(a) * s,
        g: gravity, life: life * (0.6 + Math.random() * 0.6), age: 0,
        color: new THREE.Color(MANA_COLORS[colors[i % colors.length]] || colors[i % colors.length] || '#ffffff'),
      });
    }
  }
  const rings = [];
  function shockwave(x, z, color, size = 3.2) {
    if (still()) return;
    const mesh = new THREE.Mesh(ringGeo, new THREE.MeshBasicMaterial({ color, transparent: true, opacity: 0.9, depthWrite: false, blending: THREE.AdditiveBlending, side: THREE.DoubleSide }));
    mesh.position.set(x, 0.02, z);
    mesh.scale.setScalar(0.3);
    scene.add(mesh);
    rings.push({ mesh, age: 0, life: 0.9, size });
  }

  /* ---------- Cartas no campo ---------- */
  const cards = new Map();
  const spawnFrom = new Map();
  const leaving = [];
  let hoverId = null;
  let dragId = null;
  const fromPoint = (from) => {
    if (typeof from === 'object') return new THREE.Vector3(from.x, 1.2, from.z);
    if (from === 'hand') return new THREE.Vector3(0, 5.5, 10);
    if (from === 'token') return null;
    const zone = LAYOUT.zones[from];
    return zone ? new THREE.Vector3(zone.x, 0.8, zone.z) : new THREE.Vector3(0, 5, 10);
  };

  function applyFace(entry, view) {
    const url = view.image;
    if (entry.faceUrl === url && entry.faceName === view.fallbackKey) return;
    entry.faceUrl = url; entry.faceName = view.fallbackKey;
    if (url === 'back') { entry.parts.face.material.map = backTex; entry.parts.face.material.emissiveMap = backTex; entry.parts.face.material.needsUpdate = true; return; }
    const fallback = nameTexture(view.fallback);
    entry.parts.face.material.map = fallback; entry.parts.face.material.emissiveMap = fallback; entry.parts.face.material.needsUpdate = true;
    if (url) texture(url, (tex) => { if (entry.faceUrl !== url) return; entry.parts.face.material.map = tex; entry.parts.face.material.emissiveMap = tex; entry.parts.face.material.needsUpdate = true; });
  }

  function applyCounters(entry, view) {
    const signature = JSON.stringify([view.counters, view.pt, view.sick, view.buried, view.stack]);
    if (entry.counterSig === signature) return;
    entry.counterSig = signature;
    entry.extras.forEach((o) => { entry.group.remove(o); o.material?.map?.dispose?.(); o.material?.dispose?.(); });
    entry.extras = [];
    if (view.buried) return;
    if (view.stack > 1) {
      const many = sprite(`×${view.stack}`, { size: 0.42, color: '#1a150a', bg: 'rgba(233,210,150,.95)' });
      many.position.set(-CARD_W / 2 + 0.1, THICK + 0.12, CARD_H / 2 - 0.2);
      entry.group.add(many); entry.extras.push(many);
    }
    view.counters.forEach((counter, index) => {
      const color = COUNTER_COLORS[counter.name] || '#e8e2d0';
      const stackX = -CARD_W / 2 + 0.26 + index * 0.46; const stackZ = -CARD_H / 2 + 0.3;
      for (let i = 0; i < Math.min(counter.n, 6); i++) {
        const chip = new THREE.Mesh(chipGeo, new THREE.MeshStandardMaterial({ color, emissive: color, emissiveIntensity: 0.35, roughness: 0.35, metalness: 0.2 }));
        chip.position.set(stackX, THICK + 0.04 + i * 0.075, stackZ);
        chip.castShadow = true;
        entry.group.add(chip); entry.extras.push(chip);
      }
      const label = sprite(`${counter.short} ${counter.n > 1 ? '×' + counter.n : ''}`.trim(), { size: 0.3, border: color });
      label.position.set(stackX, THICK + 0.18 + Math.min(counter.n, 6) * 0.075, stackZ - 0.05);
      entry.group.add(label); entry.extras.push(label);
    });
    if (view.pt) {
      const color = view.pt.state === 'up' ? '#9ff0b8' : view.pt.state === 'down' ? '#ff9c8a' : '#f4f6f3';
      const badge = sprite(view.pt.text, { size: 0.38, color, border: view.pt.state === 'normal' ? null : color });
      badge.position.set(CARD_W / 2 - 0.18, THICK + 0.1, CARD_H / 2 - 0.16);
      entry.group.add(badge); entry.extras.push(badge);
    }
    if (view.sick) {
      const ring = new THREE.Mesh(ringGeo, new THREE.MeshBasicMaterial({ color: 0x9fd7ff, transparent: true, opacity: 0.35, depthWrite: false, blending: THREE.AdditiveBlending }));
      ring.scale.set(1.05, 1, 1.15); ring.position.y = -0.005; ring.userData.sick = true;
      entry.group.add(ring); entry.extras.push(ring);
      const zz = sprite('enjoo', { size: 0.26, color: '#cfe9ff', bg: 'rgba(20,40,60,.8)' });
      zz.position.set(0, THICK + 0.1, -CARD_H / 2 - 0.12);
      entry.group.add(zz); entry.extras.push(zz);
    }
  }

  function sync(view) {
    const seen = new Set();
    view.battlefield.forEach((v, order) => {
      seen.add(v.iid);
      let entry = cards.get(v.iid);
      if (!entry) {
        const parts = makeCard();
        parts.face.userData.iid = v.iid; parts.body.userData.iid = v.iid;
        const start = spawnFrom.has(v.iid) ? fromPoint(spawnFrom.get(v.iid)) : null;
        spawnFrom.delete(v.iid);
        parts.group.position.copy(start || new THREE.Vector3(v.x, 0, v.z));
        if (!start) parts.group.scale.setScalar(0.01);
        scene.add(parts.group);
        entry = { iid: v.iid, parts, group: parts.group, extras: [], rot: 0, lift: 0, scale: start ? 1 : 0.01, pop: !start };
        cards.set(v.iid, entry);
      }
      entry.view = v;
      entry.target = { x: v.x, z: v.z, rot: v.tapped ? -Math.PI / 2 : 0, attack: v.attacking ? 1 : 0 };
      entry.order = order;
      applyFace(entry, v);
      applyCounters(entry, v);
    });
    cards.forEach((entry, iid) => { if (!seen.has(iid) && !leaving.some((l) => l.entry === entry)) { scene.remove(entry.group); cards.delete(iid); } });
    // Pilhas.
    ['library', 'graveyard', 'exile'].forEach((zone) => {
      const pile = piles[zone]; const info = view.piles[zone];
      const height = Math.max(0.004, info.n * 0.011);
      pile.stack.visible = info.n > 0;
      pile.stack.scale.y = height;
      pile.stack.position.y = height / 2;
      pile.glow.position.y = height + 0.005;
      pile.count.position.y = height + 0.25;
      setSprite(pile.count, info.n ? String(info.n) : '');
      const topUrl = zone === 'library' ? null : info.top;
      if (pile.top !== topUrl) {
        pile.top = topUrl;
        const faceMat = pile.stack.material[2];
        const apply = (tex) => { faceMat.map = tex; faceMat.emissiveMap = tex; faceMat.needsUpdate = true; };
        if (!topUrl) apply(backTex); else { apply(backTex); texture(topUrl, (tex) => { if (pile.top === topUrl) apply(tex); }); }
      }
      pile.n = info.n;
    });
    // Zona de comando.
    const cmd = view.piles.command;
    commandCard.visible = !!cmd.image;
    if (cmd.image && commandCard.userData.url !== cmd.image) {
      commandCard.userData.url = cmd.image;
      texture(cmd.image, (tex) => { commandFace.material.map = tex; commandFace.material.emissiveMap = tex; commandFace.material.needsUpdate = true; });
    }
    setSprite(taxLabel, cmd.tax ? `Imposto +${cmd.tax}` : '');
    daisGlow.material.opacity = cmd.image ? 0.45 : 0.15;
  }

  // A comandante na zona de comando, em pé sobre o pedestal.
  const commandParts = makeCard();
  const commandCard = commandParts.group;
  const commandFace = commandParts.face;
  commandParts.face.userData.zone = 'command'; commandParts.body.userData.zone = 'command';
  commandCard.position.set(0, 1.25, 0);
  commandCard.rotation.x = 1.05;
  commandCard.scale.setScalar(1.05);
  commandCard.visible = false;
  dais.add(commandCard);

  /** Anima uma carta do campo até uma pilha e só então a tira da cena. */
  function depart(iid, to) {
    const entry = cards.get(iid);
    if (!entry) return;
    const p = entry.group.position;
    if (to === 'exile') emit(p.x, 0.3, p.z, 60, ['#9fd7ff', '#ffffff', '#4aa7ff'], { speed: 0.8, up: 2.4, gravity: 0.6, life: 1.6, spread: 1.2 });
    if (to === 'graveyard') emit(p.x, 0.2, p.z, 26, ['#6b5a52', '#a0463c', '#3b3330'], { speed: 1.4, up: 1.2, gravity: -2, life: 1, spread: 0.9 });
    if (to === 'gone') emit(p.x, 0.2, p.z, 34, ['#ffffff', '#d5dcd8'], { speed: 1.8, up: 1.5, gravity: -1, life: 0.8, spread: 0.8 });
    leaving.push({ entry, to, age: 0, from: p.clone(), dest: to === 'hand' ? new THREE.Vector3(0, 6, 11) : to === 'gone' ? p.clone().setY(1.4) : (LAYOUT.zones[to] ? new THREE.Vector3(LAYOUT.zones[to].x, 0.6, LAYOUT.zones[to].z) : new THREE.Vector3(0, 6, 11)) });
    cards.delete(iid);
  }

  const flyers = [];
  /** Uma carta de verso para cima sai do grimório e voa até a mão. */
  function drawFx(count = 1) {
    for (let i = 0; i < count; i++) {
      const parts = makeCard();
      const lib = LAYOUT.zones.library;
      parts.group.position.set(lib.x, 0.4, lib.z);
      scene.add(parts.group);
      flyers.push({ parts, age: -i * 0.09, from: new THREE.Vector3(lib.x, 0.4, lib.z), to: new THREE.Vector3(-1 + i * 0.3, 7, 12.5) });
    }
  }

  let shuffleAge = 1;
  const fx = {
    burst(x, z, colors, big = false) {
      const list = colors && colors.length ? colors : ['C'];
      emit(x, 0.25, z, big ? 140 : 70, list, { speed: big ? 4.2 : 3, up: big ? 4 : 3, life: big ? 1.5 : 1.1 });
      list.slice(0, 3).forEach((c, i) => setTimeout(() => shockwave(x, z, MANA_COLORS[c] || '#ffffff', big ? 4.6 : 3.2), i * 90));
    },
    commander() {
      const z = LAYOUT.zones.command;
      emit(z.x, 0.3, z.z, 90, ['#d9b45a', '#fff1c9'], { speed: 1.6, up: 4.5, gravity: -1.5, life: 1.4, spread: 1.6 });
    },
    shuffle() { shuffleAge = 0; },
    draw: drawFx,
    sparkle(x, z, color) { emit(x, 0.4, z, 24, [color], { speed: 1.4, up: 2, gravity: -2, life: 0.8 }); },
  };

  /* ---------- Câmera ---------- */
  // A distância base faz o tapete inteiro caber entre o HUD do topo e a mão; a roda do mouse só multiplica.
  const PITCH = 1.08;
  const view = { yaw: 0, pitch: PITCH, zoom: 1, target: new THREE.Vector3(0.2, 0, -0.5) };
  const goal = { yaw: 0, pitch: PITCH, zoom: 1 };
  let baseDist = 24;
  let width = 1; let height = 1;
  const probe = new THREE.PerspectiveCamera();
  function orbitPosition(out, yaw, pitch, dist) {
    return out.set(view.target.x + dist * Math.cos(pitch) * Math.sin(yaw), dist * Math.sin(pitch), view.target.z + dist * Math.cos(pitch) * Math.cos(yaw));
  }
  function fitDistance() {
    probe.copy(camera);
    // Enquadra o que se usa — zona de comando, fileiras e pilhas —, não o tapete inteiro.
    const left = LAYOUT.zones.command.x - 1.8; const right = LAYOUT.zones.library.x + 1;
    const far = [[left, LAYOUT.rows.creature - CARD_H * 0.75], [right, LAYOUT.rows.creature - CARD_H * 0.75]];
    const near = [[left, LAYOUT.rows.land2 + CARD_H * 0.7], [right, LAYOUT.rows.land2 + CARD_H * 0.7]];
    const top = 1 - (hooks.topInset?.() || 110) / height * 2;
    const bottom = -1 + (hooks.bottomInset?.() || 150) / height * 2;
    const point = new THREE.Vector3();
    let dist = 24;
    for (let pass = 0; pass < 8; pass++) {
      orbitPosition(probe.position, 0, PITCH, dist);
      probe.lookAt(view.target);
      probe.updateMatrixWorld();
      let reach = 0;
      far.forEach(([x, z]) => { point.set(x, 0, z).project(probe); reach = Math.max(reach, Math.abs(point.x) / 0.98, point.y / top); });
      near.forEach(([x, z]) => { point.set(x, 0, z).project(probe); reach = Math.max(reach, Math.abs(point.x) / 0.98, point.y < 0 ? point.y / bottom : 0); });
      dist *= THREE.MathUtils.clamp(reach, 0.7, 1.4);
    }
    return THREE.MathUtils.clamp(dist, 14, 70);
  }
  function resize() {
    const rect = host.getBoundingClientRect();
    width = Math.max(1, rect.width); height = Math.max(1, rect.height);
    renderer.setSize(width, height, false);
    camera.aspect = width / height;
    camera.updateProjectionMatrix();
    if (width > 1) baseDist = fitDistance();
  }
  new ResizeObserver(resize).observe(host);
  function placeCamera(dt, still) {
    const k = still ? 1 : 1 - Math.exp(-dt * 8);
    view.yaw += (goal.yaw - view.yaw) * k; view.pitch += (goal.pitch - view.pitch) * k; view.zoom += (goal.zoom - view.zoom) * k;
    orbitPosition(camera.position, view.yaw, view.pitch, baseDist * view.zoom);
    camera.lookAt(view.target);
  }

  /* ---------- Ponteiro ---------- */
  const raycaster = new THREE.Raycaster();
  const ndc = new THREE.Vector2();
  const plane = new THREE.Plane(new THREE.Vector3(0, 1, 0), 0);
  const hit = new THREE.Vector3();
  function setNdc(clientX, clientY) {
    const rect = dom.getBoundingClientRect();
    ndc.set(((clientX - rect.left) / rect.width) * 2 - 1, -((clientY - rect.top) / rect.height) * 2 + 1);
    raycaster.setFromCamera(ndc, camera);
  }
  function zoneAt(x, z) {
    for (const [zone, info] of Object.entries(LAYOUT.zones)) {
      const w = zone === 'command' ? 1.7 : CARD_W * 0.75; const d = zone === 'command' ? 1.9 : CARD_H * 0.72;
      if (Math.abs(x - info.x) < w && Math.abs(z - info.z) < d) return zone;
    }
    return null;
  }
  function pick(clientX, clientY) {
    setNdc(clientX, clientY);
    const rect = dom.getBoundingClientRect();
    const inside = clientX >= rect.left && clientX <= rect.right && clientY >= rect.top && clientY <= rect.bottom;
    if (!raycaster.ray.intersectPlane(plane, hit)) return { inside, x: 0, z: 0, zone: null };
    const x = THREE.MathUtils.clamp(hit.x, -MAT_W / 2 + 0.8, MAT_W / 2 - 0.8);
    const z = THREE.MathUtils.clamp(hit.z, -MAT_D / 2 + 1, MAT_D / 2 - 1);
    return { inside, x, z, zone: zoneAt(hit.x, hit.z) };
  }
  function pickObject(clientX, clientY) {
    setNdc(clientX, clientY);
    const targets = [];
    cards.forEach((entry) => { targets.push(entry.parts.face, entry.parts.body); });
    ['library', 'graveyard', 'exile'].forEach((zone) => { if (piles[zone].stack.visible) targets.push(piles[zone].stack); });
    targets.push(daisHit, commandParts.face);
    const found = raycaster.intersectObjects(targets, false)[0];
    if (!found) return null;
    return found.object.userData.iid ? { iid: found.object.userData.iid } : { zone: found.object.userData.zone };
  }

  let pointer = null;
  let hoverZone = null;
  let dropZone = null;
  let lastMove = null;
  dom.addEventListener('contextmenu', (event) => event.preventDefault());
  dom.addEventListener('pointerdown', (event) => {
    const target = pickObject(event.clientX, event.clientY);
    dom.setPointerCapture(event.pointerId);
    pointer = { id: event.pointerId, x: event.clientX, y: event.clientY, sx: event.clientX, sy: event.clientY, button: event.button, target, moved: false, yaw: goal.yaw, pitch: goal.pitch };
    if (event.button === 2) {
      // Depois do evento: o clique que fecha menus abertos não pode fechar este.
      const { clientX, clientY } = event;
      setTimeout(() => {
        if (target?.iid) hooks.onCardContext?.(target.iid, clientX, clientY);
        else if (target?.zone) hooks.onPileContext?.(target.zone, clientX, clientY);
      });
      pointer = null;
    }
  });
  dom.addEventListener('pointermove', (event) => {
    lastMove = { x: event.clientX, y: event.clientY };
    if (!pointer) return;
    const dx = event.clientX - pointer.sx; const dy = event.clientY - pointer.sy;
    if (!pointer.moved && Math.hypot(dx, dy) > 6) {
      pointer.moved = true;
      if (pointer.target?.iid && cards.has(pointer.target.iid)) { dragId = pointer.target.iid; hooks.onDragStart?.(dragId); }
    }
    if (!pointer.moved) return;
    if (dragId) {
      const p = pick(event.clientX, event.clientY);
      const entry = cards.get(dragId);
      if (entry) { entry.drag = { x: p.x, z: p.z }; }
      dropZone = p.zone;
    } else if (!pointer.target) {
      goal.yaw = THREE.MathUtils.clamp(pointer.yaw - dx * 0.004, -0.6, 0.6);
      goal.pitch = THREE.MathUtils.clamp(pointer.pitch + dy * 0.003, 0.7, 1.35);
    }
  });
  const release = (event) => {
    if (!pointer) return;
    const p = pointer; pointer = null;
    if (dragId) {
      const id = dragId; dragId = null; dropZone = null;
      const entry = cards.get(id);
      if (entry) entry.drag = null;
      const spot = pick(event.clientX, event.clientY);
      const overHand = event.clientY > host.getBoundingClientRect().bottom - (hooks.handHeight?.() || 150);
      hooks.onCardDrop?.(id, { x: spot.x, z: spot.z, zone: overHand ? 'hand' : spot.zone });
      return;
    }
    if (p.moved || event.type !== 'pointerup' || p.button !== 0) return;
    if (p.target?.iid) hooks.onCardClick?.(p.target.iid, event);
    else if (p.target?.zone) hooks.onPileClick?.(p.target.zone, event);
  };
  dom.addEventListener('pointerup', release);
  dom.addEventListener('pointercancel', release);
  dom.addEventListener('pointerleave', () => { lastMove = null; if (hoverId || hoverZone) { hoverId = null; hoverZone = null; hooks.onHover?.(null); } });
  dom.addEventListener('wheel', (event) => { event.preventDefault(); goal.zoom = THREE.MathUtils.clamp(goal.zoom * Math.exp(event.deltaY * 0.001), 0.5, 1.25); }, { passive: false });
  dom.addEventListener('dblclick', () => { goal.yaw = 0; goal.pitch = PITCH; goal.zoom = 1; });

  /* ---------- Quadro a quadro ---------- */
  let ghost = null;
  let running = true;
  let last = 0;
  let time = 0;
  function tick(now) {
    requestAnimationFrame(tick);
    if (!running) return;
    const dt = Math.min(0.05, (now - (last || now)) / 1000); last = now; time += dt;
    const reduce = still();
    placeCamera(dt, reduce);
    if (lastMove && !pointer) {
      const target = pickObject(lastMove.x, lastMove.y);
      const id = target?.iid || null; const zone = target?.zone || null;
      if (id !== hoverId || zone !== hoverZone) { hoverId = id; hoverZone = zone; hooks.onHover?.(id ? { iid: id } : zone ? { zone } : null); dom.style.cursor = id || zone ? 'pointer' : ''; }
    }
    const ease = reduce ? 1 : 1 - Math.exp(-dt * 12);
    cards.forEach((entry) => {
      const t = entry.target; if (!t) return;
      const dragging = entry.drag;
      const tx = dragging ? dragging.x : t.x; const tz = (dragging ? dragging.z : t.z) - t.attack * 0.7;
      const hovered = entry.iid === hoverId && !dragging;
      const liftTarget = dragging ? 1.3 : hovered ? 0.22 : 0;
      entry.lift += (liftTarget - entry.lift) * ease;
      const p = entry.group.position;
      p.x += (tx - p.x) * ease; p.z += (tz - p.z) * ease; p.y += (entry.lift + entry.order * 0.0006 - p.y) * ease;
      entry.rot += (t.rot - entry.rot) * ease;
      entry.group.rotation.set(dragging ? 0.18 : 0, entry.rot, dragging ? Math.sin(time * 6) * 0.03 : 0);
      if (entry.pop) { entry.scale += (1 - entry.scale) * (reduce ? 1 : 1 - Math.exp(-dt * 10)); entry.group.scale.setScalar(entry.scale); if (entry.scale > 0.995) entry.pop = false; }
      const glow = entry.parts.glow.material;
      const wanted = entry.view.attacking ? 0.9 : hovered || dragging ? 0.7 : entry.view.selected ? 0.8 : 0;
      glow.opacity += (wanted - glow.opacity) * ease;
      glow.color.set(entry.view.attacking ? '#ff6a4d' : entry.view.commander ? '#e9c46a' : entry.view.token ? '#bfe7ff' : '#ffffff');
      entry.extras.forEach((o) => { if (o.userData.sick) o.material.opacity = 0.2 + Math.sin(time * 3) * 0.15; });
    });
    for (let i = leaving.length - 1; i >= 0; i--) {
      const l = leaving[i];
      l.age += dt * (reduce ? 10 : 2.2);
      const u = Math.min(1, l.age);
      const g = l.entry.group;
      g.position.lerpVectors(l.from, l.dest, u);
      g.position.y += Math.sin(u * Math.PI) * 1.4;
      g.rotation.z = l.to === 'graveyard' ? u * 0.4 : 0;
      const fade = l.to === 'exile' || l.to === 'gone' ? 1 - u : 1;
      g.scale.setScalar(Math.max(0.01, fade * (l.to === 'hand' ? 1 - u * 0.4 : 1)));
      if (u >= 1) { scene.remove(g); leaving.splice(i, 1); }
    }
    for (let i = flyers.length - 1; i >= 0; i--) {
      const f = flyers[i];
      f.age += dt * (reduce ? 10 : 2.4);
      if (f.age < 0) continue;
      const u = Math.min(1, f.age);
      f.parts.group.position.lerpVectors(f.from, f.to, u * u);
      f.parts.group.position.y += Math.sin(u * Math.PI) * 2;
      f.parts.group.rotation.set(-u * 1.1, 0, Math.sin(u * Math.PI) * 0.3);
      if (u >= 1) { scene.remove(f.parts.group); flyers.splice(i, 1); }
    }
    // Pilhas: brilho ao passar o mouse ou soltar em cima; o grimório treme ao embaralhar.
    ['library', 'graveyard', 'exile'].forEach((zone) => {
      const pile = piles[zone];
      const on = hoverZone === zone || dropZone === zone;
      const wanted = on ? 0.8 : zone === 'exile' && pile.n > 0 ? 0.25 + Math.sin(time * 2) * 0.12 : 0;
      pile.glow.material.opacity += (wanted - pile.glow.material.opacity) * ease;
    });
    if (shuffleAge < 1) {
      shuffleAge += dt * 1.8;
      piles.library.stack.rotation.y = reduce ? 0 : Math.sin(shuffleAge * 28) * 0.18 * (1 - shuffleAge);
      piles.library.stack.position.x = reduce ? 0 : Math.sin(shuffleAge * 21) * 0.12 * (1 - shuffleAge);
    }
    daisRing.material.emissiveIntensity = 0.45 + Math.sin(time * 1.6) * 0.2 + (hoverZone === 'command' || dropZone === 'command' ? 0.5 : 0);
    commandCard.position.y = 1.25 + (reduce ? 0 : Math.sin(time * 1.3) * 0.06);
    // Partículas.
    for (let i = particles.length - 1; i >= 0; i--) {
      const q = particles[i];
      q.age += dt;
      if (q.age >= q.life) { particles.splice(i, 1); continue; }
      q.vy += q.g * dt; q.x += q.vx * dt; q.y = Math.max(0.03, q.y + q.vy * dt); q.z += q.vz * dt;
      q.vx *= 0.97; q.vz *= 0.97;
    }
    for (let i = 0; i < MAX_P; i++) {
      const q = particles[i];
      if (!q) { pPos[i * 3 + 1] = -50; continue; }
      pPos.set([q.x, q.y, q.z], i * 3);
      tmpColor.copy(q.color).multiplyScalar(1 - q.age / q.life);
      pCol.set([tmpColor.r, tmpColor.g, tmpColor.b], i * 3);
    }
    pGeo.attributes.position.needsUpdate = true; pGeo.attributes.color.needsUpdate = true;
    for (let i = rings.length - 1; i >= 0; i--) {
      const ring = rings[i];
      ring.age += dt;
      const u = ring.age / ring.life;
      ring.mesh.scale.setScalar(0.3 + u * ring.size);
      ring.mesh.material.opacity = 0.9 * (1 - u);
      if (u >= 1) { scene.remove(ring.mesh); ring.mesh.material.dispose(); rings.splice(i, 1); }
    }
    if (ghost) ghost.group.visible = ghost.on;
    renderer.render(scene, camera);
  }

  /** Carta translúcida que segue o ponteiro enquanto se arrasta uma carta da mão. */
  function showGhost(image, clientX, clientY) {
    if (!ghost) {
      const parts = makeCard();
      parts.face.material.opacity = 0.75;
      scene.add(parts.group);
      ghost = { ...parts, url: null, on: false };
    }
    if (ghost.url !== image) { ghost.url = image; texture(image, (tex) => { if (ghost.url === image) { ghost.face.material.map = tex; ghost.face.material.emissiveMap = tex; ghost.face.material.needsUpdate = true; } }); }
    const p = pick(clientX, clientY);
    ghost.on = p.inside;
    ghost.group.position.set(p.x, 1.1, p.z);
    ghost.group.rotation.set(0.15, 0, 0);
    dropZone = p.inside ? p.zone : null;
    return p;
  }
  function hideGhost() { if (ghost) ghost.on = false; dropZone = null; }

  resize();
  requestAnimationFrame(tick);
  new IntersectionObserver(([entry]) => { running = entry.isIntersecting; }).observe(host);

  return {
    sync,
    spawn(iid, from) { spawnFrom.set(iid, from); },
    depart,
    fx,
    pick,
    showGhost,
    hideGhost,
    resize,
    playmat(url) {
      if (!url) return;
      const img = new Image();
      img.onload = () => paintMat(img);
      img.src = url;
    },
    projectToScreen(x, z) {
      const v = new THREE.Vector3(x, 0.5, z).project(camera);
      return { x: (v.x + 1) / 2 * width, y: (1 - v.y) / 2 * height };
    },
  };
}
