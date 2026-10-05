/* Mesa 3D da Mesa de teste: tapete com a arte da comandante, cartas com espessura e sombra, pilhas das zonas,
   marcadores empilhados e os efeitos (mana na cor da carta, onda no tapete, exílio, compra). Numa mesa compartilhada,
   os tapetes dos oponentes ficam em arco à frente do seu, virados para você, com as cartas deles espelhadas (terrenos
   longe, criaturas perto do centro) e legíveis. Só desenho e ponteiro: o estado da partida e as regras ficam em
   playtest.js, que chama sync() e syncSeats() depois de cada mudança. */
import * as THREE from './vendor/three.module.min.js';

// Cartas grandes em relação ao tapete (cerca de 1/17 da largura), como num playmat de verdade.
export const CARD_W = 1.78;
export const CARD_H = CARD_W * 680 / 488;
const THICK = 0.028;
const MAT_W = 30;
const MAT_D = 18;
export const LAYOUT = {
  rows: { creature: -5.1, other: -2.4, land: 0.3, land2: 3.0 },
  left: -8.2,
  right: 8.4,
  spacing: 2.0,
  zones: {
    library: { x: 11.5, z: 3.2, label: 'Grimório' },
    graveyard: { x: 11.5, z: 0, label: 'Cemitério' },
    exile: { x: 11.5, z: -3.2, label: 'Exílio' },
    command: { x: -11.6, z: -4.3, label: 'Zona de comando' },
  },
};
export const MANA_COLORS = { W: '#fff1c9', U: '#4aa7ff', B: '#b07cff', R: '#ff5b3a', G: '#43d27c', C: '#d5dcd8' };
/** Cor de cada assento na mesa compartilhada (moldura, nome, feixes de ataque). */
export const SEAT_COLORS = ['#e0b54e', '#5fb3ff', '#ff7a5c', '#6fd08c'];
const COUNTER_COLORS = { '+1/+1': '#43c878', '-1/-1': '#b45ad6', Lealdade: '#e0b54e', Carga: '#4aa7ff', Tempo: '#9fd7e8', Escudo: '#f2f2ec' };
// Arco dos oponentes: ângulo de cada assento (da esquerda para a direita), escala do tapete e raio a partir do centro.
const SEAT_ARCS = { 1: { angles: [0], scale: 0.85, radius: 25 }, 2: { angles: [-0.7, 0.7], scale: 0.72, radius: 24 }, 3: { angles: [-1.05, 0, 1.05], scale: 0.62, radius: 23 } };
const ARC_CENTER = { x: 0, z: 7 };

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
  // Formatos sem comandante (construídos): sem pedestal nem zona de comando pintada.
  const hasCommand = hooks.commandZone !== false;

  const scene = new THREE.Scene();
  // near = 1: com 0.1, de longe (mesa toda) a face da carta e o corpo dela disputavam o mesmo pixel e piscavam.
  const camera = new THREE.PerspectiveCamera(36, 1, 1, 400);
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
  // Moldura de um tapete inteiro: brilha em volta do tapete de quem está jogando o turno.
  const matFrame = canvasTexture(640, 400, (ctx, w, h) => {
    ctx.clearRect(0, 0, w, h);
    ctx.shadowColor = '#fff'; ctx.shadowBlur = 22; ctx.strokeStyle = '#fff'; ctx.lineWidth = 9;
    roundRect(ctx, 20, 20, w - 40, h - 40, 26); ctx.stroke();
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

  /**
   * Arte do tapete: a ilustração recortada (art_crop, 626 px) em vez de um pedaço da carta normal ampliado seis vezes.
   * crossOrigin: sem a imagem no cache local, o image.php redireciona para o Scryfall, e uma imagem de outra origem sem
   * CORS "contamina" o canvas — o WebGL recusa a textura e o tapete fica preto ou quebrado.
   */
  function loadArt(url, onLoad) {
    const img = new Image();
    img.crossOrigin = 'anonymous';
    img.decoding = 'async';
    const crop = url.includes('size=normal') ? url.replace('size=normal', 'size=art_crop') : null;
    img.onload = () => { img.dataset.crop = img.src.includes('size=art_crop') ? '1' : ''; onLoad(img); };
    img.onerror = () => { if (crop && img.src.includes('size=art_crop')) img.src = url; };
    img.src = crop || url;
  }

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

  /* ---------- Tapetes: arte do deck, zonas e fileiras impressas ---------- */
  /**
   * Pinta um tapete. mirror: zonas do outro lado (tapete de oponente, visto de frente para você); label: nome no tapete.
   */
  function paintMatCanvas(canvas, art, { mirror = false, label = '', accent = '#d9b45a', rows = true, command = hasCommand } = {}) {
    const ctx = canvas.getContext('2d');
    const w = canvas.width; const h = canvas.height;
    // Medidas pensadas para 2400 px de largura; o tapete aparece com 400–900 px na tela, então o texto precisa ser grande.
    const u = w / 2400;
    const perUnit = w / MAT_W;
    const toMat = (x, z) => [((mirror ? -x : x) / MAT_W + 0.5) * w, ((mirror ? -z : z) / MAT_D + 0.5) * h];
    ctx.fillStyle = '#132019'; ctx.fillRect(0, 0, w, h);
    if (art) {
      // art_crop: a ilustração inteira. Carta normal (sem o recorte): só a janela da ilustração (moldura moderna).
      const crop = art.dataset?.crop === '1';
      const sx = crop ? 0 : art.width * 0.08; const sy = crop ? 0 : art.height * 0.11;
      const sw = crop ? art.width : art.width * 0.84; const sh = crop ? art.height : art.height * 0.44;
      const scale = Math.max(w / sw, h / sh);
      ctx.save();
      ctx.filter = `blur(${Math.max(1, Math.round((crop ? 3 : 8) * u))}px) saturate(1.1) brightness(.6)`;
      ctx.drawImage(art, sx, sy, sw, sh, (w - sw * scale) / 2 - 12 * u, (h - sh * scale) / 2 - 12 * u, sw * scale + 24 * u, sh * scale + 24 * u);
      ctx.restore();
    }
    const vignette = ctx.createRadialGradient(w / 2, h * 0.45, h * 0.2, w / 2, h / 2, w * 0.62);
    vignette.addColorStop(0, 'rgba(10,20,15,.12)'); vignette.addColorStop(1, 'rgba(6,12,9,.8)');
    ctx.fillStyle = vignette; ctx.fillRect(0, 0, w, h);
    ctx.fillStyle = 'rgba(230,238,232,.035)';
    for (let i = 0; i < 9000 * u ** 2; i++) ctx.fillRect(hash(`x${i}`) * w, hash(`y${i}`) * h, 2, 2);
    // Costura da borda, na cor do assento.
    ctx.strokeStyle = accent; ctx.globalAlpha = 0.6; ctx.lineWidth = Math.max(2, w / 600); ctx.setLineDash([w / 133, w / 200]);
    roundRect(ctx, w / 92, w / 92, w - w / 46, h - w / 46, w / 40); ctx.stroke(); ctx.setLineDash([]); ctx.globalAlpha = 1;
    /** Texto com uma faixa escura atrás, para ler sobre qualquer arte. align: lado em que o texto encosta em x. */
    const tag = (text, x, y, size, { color = 'rgba(236,241,237,.92)', family = 'Spectral, Georgia, serif', align = 'center', border = null } = {}) => {
      ctx.font = `700 ${Math.round(size * u)}px ${family}`;
      ctx.textBaseline = 'middle';
      const tw = ctx.measureText(text).width; const ph = size * u * 1.5; const pad = size * u * 0.55; const pw = tw + pad * 2;
      const left = align === 'left' ? x : align === 'right' ? x - pw : x - pw / 2;
      roundRect(ctx, left, y - ph / 2, pw, ph, ph / 2);
      ctx.fillStyle = 'rgba(4,9,7,.62)'; ctx.fill();
      if (border) { ctx.strokeStyle = border; ctx.lineWidth = Math.max(2, size * u * 0.06); ctx.stroke(); }
      ctx.fillStyle = color; ctx.textAlign = 'left'; ctx.fillText(text, left + pad, y + size * u * 0.04);
    };
    if (rows) {
      // Linha embaixo de cada fileira, com o nome na ponta direita (as cartas entram pela esquerda).
      const rowLabel = (text, z) => {
        const [x0, y] = toMat(LAYOUT.left - 1.25, z);
        const [x1] = toMat(LAYOUT.right + 0.9, z);
        const lineY = y + perUnit * CARD_H * 0.62;
        ctx.strokeStyle = 'rgba(214,226,218,.14)'; ctx.lineWidth = Math.max(2, 3 * u);
        ctx.beginPath(); ctx.moveTo(x0, lineY); ctx.lineTo(x1, lineY); ctx.stroke();
        ctx.font = `700 ${Math.round(34 * u)}px "Segoe UI", system-ui, sans-serif`;
        ctx.fillStyle = 'rgba(226,235,229,.62)'; ctx.textAlign = 'right'; ctx.textBaseline = 'bottom';
        ctx.fillText(text.toUpperCase(), x1, lineY - 10 * u);
      };
      rowLabel('Criaturas', LAYOUT.rows.creature);
      rowLabel('Outras permanentes', LAYOUT.rows.other);
      rowLabel('Terrenos', LAYOUT.rows.land2);
    }
    Object.entries(LAYOUT.zones).forEach(([zone, info]) => {
      if (zone === 'command' && !command) return;
      const [cx, cy] = toMat(info.x, info.z);
      const bw = perUnit * (zone === 'command' ? CARD_W * 1.55 : CARD_W * 1.22); const bh = perUnit * (zone === 'command' ? CARD_H * 1.45 : CARD_H * 1.16);
      roundRect(ctx, cx - bw / 2, cy - bh / 2, bw, bh, w / 110);
      ctx.fillStyle = zone === 'command' ? 'rgba(217,180,90,.1)' : 'rgba(0,0,0,.28)'; ctx.fill();
      ctx.strokeStyle = zone === 'command' ? 'rgba(217,180,90,.8)' : zone === 'exile' ? 'rgba(120,190,255,.6)' : 'rgba(214,226,218,.45)';
      ctx.lineWidth = Math.max(2, 4 * u); ctx.setLineDash(zone === 'exile' ? [14 * u, 10 * u] : []); ctx.stroke(); ctx.setLineDash([]);
      // Nome da zona: a de comando embaixo da caixa; as pilhas na vertical, do lado de fora (entre elas não cabe texto).
      const color = zone === 'command' ? 'rgba(240,218,160,.95)' : zone === 'exile' ? 'rgba(190,225,255,.95)' : 'rgba(236,241,237,.92)';
      if (zone === 'command') { tag(info.label, cx, cy + bh / 2 + 44 * u, 44, { color }); return; }
      ctx.save();
      ctx.translate(cx + (mirror ? -1 : 1) * (bw / 2 + 46 * u), cy);
      ctx.rotate(-Math.PI / 2);
      tag(info.label.toUpperCase(), 0, 0, 34, { color, family: '"Segoe UI", system-ui, sans-serif' });
      ctx.restore();
    });
    // Nome do dono do tapete, na borda mais perto do centro da mesa (de frente para você).
    if (label) tag(label, w / 2, h - 96 * u, 104, { color: '#f4f1e8', border: accent });
  }
  const matCanvas = document.createElement('canvas');
  matCanvas.width = 2400; matCanvas.height = Math.round(2400 * MAT_D / MAT_W);
  const matTex = new THREE.CanvasTexture(matCanvas);
  matTex.colorSpace = THREE.SRGBColorSpace;
  matTex.anisotropy = maxAniso;
  let matArt = null;
  const paintMat = (art) => { matArt = art; paintMatCanvas(matCanvas, art); matTex.needsUpdate = true; };
  paintMat(null);
  // Os nomes do tapete usam a Spectral do site: se ela chegou depois da primeira pintura, pinta de novo.
  document.fonts?.load('700 40px Spectral').then(() => { paintMat(matArt); seats.forEach((board) => { board.painted = ''; paintSeat(board, board.name); }); }).catch(() => {});
  const mat = new THREE.Mesh(new THREE.PlaneGeometry(MAT_W, MAT_D), new THREE.MeshStandardMaterial({ map: matTex, roughness: 0.96, metalness: 0 }));
  mat.rotation.x = -Math.PI / 2;
  mat.receiveShadow = true;
  scene.add(mat);
  const myFrame = new THREE.Mesh(new THREE.PlaneGeometry(MAT_W + 1.4, MAT_D + 1.4), new THREE.MeshBasicMaterial({ map: matFrame, color: 0xd9b45a, transparent: true, opacity: 0, depthWrite: false, blending: THREE.AdditiveBlending }));
  myFrame.rotation.x = -Math.PI / 2; myFrame.position.y = 0.012;
  scene.add(myFrame);
  const table = new THREE.Mesh(new THREE.PlaneGeometry(200, 160), new THREE.MeshStandardMaterial({ color: 0x0b120e, roughness: 1 }));
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

  // Os cantos arredondados são recorte (alphaTest), não transparência: material opaco não depende da ordem de desenho,
  // e o polygonOffset garante a face na frente da tampa do corpo da carta, mesmo de longe.
  function faceMaterial(map) {
    return new THREE.MeshStandardMaterial({ map, emissive: 0xffffff, emissiveMap: map, emissiveIntensity: 0.32, roughness: 0.5, metalness: 0, alphaMap: cardAlpha, alphaTest: 0.5,
      polygonOffset: true, polygonOffsetFactor: -1, polygonOffsetUnits: -4 });
  }

  function makeCard(shadow = true) {
    const group = new THREE.Group();
    const body = new THREE.Mesh(bodyGeo, bodyMat);
    body.castShadow = shadow;
    const face = new THREE.Mesh(faceGeo, faceMaterial(backTex));
    face.position.y = THICK + 0.003;
    const back = new THREE.Mesh(backGeo, faceMaterial(backTex));
    back.position.y = -0.003;
    const glow = new THREE.Mesh(glowGeo, new THREE.MeshBasicMaterial({ map: frameGlow, color: 0xffffff, transparent: true, opacity: 0, depthWrite: false, blending: THREE.AdditiveBlending }));
    glow.position.y = THICK + 0.004;
    group.add(body, face, back, glow);
    return { group, body, face, back, glow };
  }

  /* ---------- Pilhas das zonas ---------- */
  function createPile(parent, zone, x, z, { counterSize = 0.5, shadow = true } = {}) {
    const group = new THREE.Group();
    group.position.set(x, 0, z);
    const stackMat = [new THREE.MeshStandardMaterial({ map: edgeTex, roughness: 0.8 }), new THREE.MeshStandardMaterial({ map: edgeTex, roughness: 0.8 }), faceMaterial(backTex), bodyMat, new THREE.MeshStandardMaterial({ map: edgeTex, roughness: 0.8 }), new THREE.MeshStandardMaterial({ map: edgeTex, roughness: 0.8 })];
    const stack = new THREE.Mesh(new THREE.BoxGeometry(CARD_W, 1, CARD_H), stackMat);
    stack.castShadow = shadow; stack.receiveShadow = true;
    stack.userData.zone = zone;
    const glow = new THREE.Mesh(glowGeo, new THREE.MeshBasicMaterial({ map: frameGlow, color: zone === 'exile' ? 0x78beff : 0xffffff, transparent: true, opacity: 0, depthWrite: false, blending: THREE.AdditiveBlending }));
    group.add(stack, glow);
    parent.add(group);
    const count = sprite('', { size: counterSize });
    count.position.set(0, 0.2, CARD_H / 2 + 0.05);
    group.add(count);
    return { group, stack, glow, count, n: -1, top: null, hover: 0, height: 0 };
  }
  function updatePile(pile, info, zone) {
    const height = Math.max(0.004, info.n * 0.011);
    pile.stack.visible = info.n > 0;
    pile.stack.scale.y = height;
    pile.stack.position.y = height / 2;
    pile.height = info.n ? height : 0;
    pile.glow.position.y = height + 0.005;
    pile.count.position.y = height + 0.25;
    setSprite(pile.count, info.n ? String(info.n) : '');
    const topUrl = zone === 'library' ? null : info.top;
    if (pile.top !== topUrl) {
      pile.top = topUrl;
      const faceMat = pile.stack.material[2];
      const apply = (tex) => { faceMat.map = tex; faceMat.emissiveMap = tex; faceMat.needsUpdate = true; };
      apply(backTex);
      if (topUrl && topUrl !== 'back') texture(topUrl, (tex) => { if (pile.top === topUrl) apply(tex); });
    }
    pile.n = info.n;
  }
  const piles = {};
  ['library', 'graveyard', 'exile'].forEach((zone) => { piles[zone] = createPile(scene, zone, LAYOUT.zones[zone].x, LAYOUT.zones[zone].z); });
  // Zona de comando: um pedestal com anel dourado.
  const dais = new THREE.Group();
  dais.position.set(LAYOUT.zones.command.x, 0, LAYOUT.zones.command.z);
  const daisBase = new THREE.Mesh(new THREE.CylinderGeometry(1.85, 2.0, 0.16, 48), new THREE.MeshStandardMaterial({ color: 0x1b2620, roughness: 0.4, metalness: 0.3 }));
  daisBase.position.y = 0.08; daisBase.receiveShadow = true; daisBase.castShadow = true;
  const daisRing = new THREE.Mesh(new THREE.TorusGeometry(1.9, 0.04, 12, 90), new THREE.MeshStandardMaterial({ color: 0xd9b45a, emissive: 0xd9b45a, emissiveIntensity: 0.6, metalness: 0.8, roughness: 0.3 }));
  daisRing.rotation.x = Math.PI / 2; daisRing.position.y = 0.16;
  const daisGlow = new THREE.Sprite(new THREE.SpriteMaterial({ map: glowTex, color: 0xd9b45a, transparent: true, opacity: 0.35, depthWrite: false, blending: THREE.AdditiveBlending }));
  daisGlow.scale.set(5, 5, 1); daisGlow.position.y = 0.3;
  const daisHit = new THREE.Mesh(new THREE.CylinderGeometry(1.9, 1.9, 0.5, 16), new THREE.MeshBasicMaterial({ visible: false }));
  daisHit.userData.zone = 'command';
  const taxLabel = sprite('', { size: 0.46, color: '#e9d296' });
  taxLabel.position.set(0, 0.35, 2.25);
  dais.add(daisBase, daisRing, daisGlow, daisHit, taxLabel);
  dais.visible = hasCommand;
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
    const signature = `${text}|${s.userData.color}|${s.userData.bg}|${s.userData.border}|${s.userData.size}`;
    if (s.userData.text === signature) return;
    s.userData.text = signature;
    s.visible = text !== '';
    if (!text) return;
    // Textura com o dobro da resolução: o texto continua nítido quando a câmera chega perto.
    const font = '700 88px "Segoe UI", system-ui, sans-serif';
    const measure = document.createElement('canvas').getContext('2d'); measure.font = font;
    const w = Math.ceil(measure.measureText(text).width + 88); const h = 128;
    const tex = canvasTexture(w, h, (ctx) => {
      roundRect(ctx, 4, 4, w - 8, h - 8, 56); ctx.fillStyle = s.userData.bg; ctx.fill();
      if (s.userData.border) { ctx.lineWidth = 6; ctx.strokeStyle = s.userData.border; ctx.stroke(); }
      ctx.font = font; ctx.fillStyle = s.userData.color; ctx.textAlign = 'center'; ctx.textBaseline = 'middle'; ctx.fillText(text, w / 2, h / 2 + 4);
    });
    tex.anisotropy = maxAniso;
    s.material.map?.dispose();
    s.material.map = tex; s.material.needsUpdate = true;
    s.scale.set(s.userData.size * (w / h), s.userData.size, 1);
  }

  /* ---------- Partículas: mana, exílio, cinzas, ataques ---------- */
  const MAX_P = 2200;
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
  let hoverSeat = null;
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
    const signature = JSON.stringify([view.counters, view.pt, view.sick, view.buried, view.stack, view.targetLabel]);
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
    if (view.targetLabel) {
      const tag = sprite(`→ ${view.targetLabel}`, { size: 0.32, color: '#fff', bg: 'rgba(160,40,24,.9)' });
      tag.position.set(0, THICK + 0.3, -CARD_H / 2 - 0.2);
      entry.group.add(tag); entry.extras.push(tag);
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

  // Zona de comando: até duas cartas em pé no pedestal (comandante e, no Oathbreaker, o feitiço de assinatura).
  const commandSlots = [0, 1].map((i) => {
    const parts = makeCard();
    parts.face.userData.zone = 'command'; parts.body.userData.zone = 'command'; parts.face.userData.slot = i; parts.body.userData.slot = i;
    parts.group.position.set(i ? 0.95 : 0, 1.25, i ? 0.35 : 0);
    parts.group.rotation.x = 1.05;
    parts.group.scale.setScalar(i ? 0.8 : 1.05);
    parts.group.visible = false;
    parts.group.userData.url = null;
    dais.add(parts.group);
    return parts;
  });

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
    ['library', 'graveyard', 'exile'].forEach((zone) => updatePile(piles[zone], view.piles[zone], zone));
    // Zona de comando.
    const commands = Array.isArray(view.piles.command) ? view.piles.command : (view.piles.command.image ? [view.piles.command] : []);
    commandSlots.forEach((slot, i) => {
      const cmd = commands[i];
      slot.group.visible = !!cmd?.image;
      if (cmd?.image && slot.group.userData.url !== cmd.image) {
        slot.group.userData.url = cmd.image;
        texture(cmd.image, (tex) => { if (slot.group.userData.url !== cmd.image) return; slot.face.material.map = tex; slot.face.material.emissiveMap = tex; slot.face.material.needsUpdate = true; });
      }
    });
    const tax = commands.map((cmd) => cmd.tax || 0);
    setSprite(taxLabel, tax.some(Boolean) ? `Imposto ${tax.map((t) => `+${t}`).join(' · ')}` : '');
    daisGlow.material.opacity = commands.length ? 0.45 : 0.15;
    if (view.active !== undefined) activeMe = !!view.active;
  }

  // Topo revelado: uma carta vira em cima do grimório e se ergue, inclinada para a câmera.
  const revealParts = makeCard();
  revealParts.face.userData.zone = 'library'; revealParts.body.userData.zone = 'library';
  revealParts.group.visible = false;
  piles.library.group.add(revealParts.group);
  const reveal = { want: null, url: null, open: 0 };
  function setRevealFace(url) {
    const material = revealParts.face.material;
    const apply = (tex) => { material.map = tex; material.emissiveMap = tex; material.needsUpdate = true; };
    apply(backTex);
    texture(url, (tex) => { if (reveal.url === url) apply(tex); });
  }

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

  /* ---------- Assentos dos oponentes ---------- */
  const seats = new Map();
  let activeMe = false;
  let seatLayoutKey = '';
  const handBackGeo = new THREE.PlaneGeometry(CARD_W, CARD_H);
  const handBackMat = new THREE.MeshStandardMaterial({ map: backTex, roughness: 0.6, side: THREE.DoubleSide, alphaMap: cardAlpha, alphaTest: 0.5 });
  const seatArc = (count) => SEAT_ARCS[Math.max(1, Math.min(3, count))] || SEAT_ARCS[1];
  function seatPlacement(index, count) {
    const arc = seatArc(count);
    const theta = arc.angles[index] ?? 0;
    return { theta, scale: arc.scale, x: ARC_CENTER.x + arc.radius * Math.sin(theta), z: ARC_CENTER.z - arc.radius * Math.cos(theta) };
  }
  /** Coordenada do tapete do oponente (as dele) para a local do assento: espelhada, terrenos longe de você. */
  const mirror = (x, z) => ({ x: -x, z: -z });

  function createSeat(info) {
    const group = new THREE.Group();
    scene.add(group);
    const canvas = document.createElement('canvas');
    canvas.width = 1600; canvas.height = Math.round(1600 * MAT_D / MAT_W);
    const tex = new THREE.CanvasTexture(canvas);
    tex.colorSpace = THREE.SRGBColorSpace; tex.anisotropy = maxAniso;
    const accent = SEAT_COLORS[info.seat % SEAT_COLORS.length];
    const matMesh = new THREE.Mesh(new THREE.PlaneGeometry(MAT_W, MAT_D), new THREE.MeshStandardMaterial({ map: tex, roughness: 0.96 }));
    matMesh.rotation.x = -Math.PI / 2; matMesh.receiveShadow = true;
    matMesh.userData.seatMat = info.seat;
    const frame = new THREE.Mesh(new THREE.PlaneGeometry(MAT_W + 1.4, MAT_D + 1.4), new THREE.MeshBasicMaterial({ map: matFrame, color: accent, transparent: true, opacity: 0, depthWrite: false, blending: THREE.AdditiveBlending }));
    frame.rotation.x = -Math.PI / 2; frame.position.y = 0.012;
    group.add(matMesh, frame);
    const seatPiles = {};
    ['library', 'graveyard', 'exile'].forEach((zone) => {
      const p = mirror(LAYOUT.zones[zone].x, LAYOUT.zones[zone].z);
      seatPiles[zone] = createPile(group, zone, p.x, p.z, { counterSize: 0.9, shadow: false });
      seatPiles[zone].stack.userData.seat = info.seat;
    });
    const cmdPos = mirror(LAYOUT.zones.command.x, LAYOUT.zones.command.z);
    const commands = [0, 1].map((i) => {
      const parts = makeCard(false);
      parts.face.userData = { seat: info.seat, zone: 'command', slot: i }; parts.body.userData = { seat: info.seat, zone: 'command', slot: i };
      parts.group.position.set(cmdPos.x + (i ? 1.1 : 0), 1.2, cmdPos.z + (i ? 0.3 : 0));
      parts.group.rotation.x = 1.0;
      parts.group.scale.setScalar(i ? 0.85 : 1.1);
      parts.group.visible = false;
      parts.group.userData.url = null;
      group.add(parts.group);
      return parts;
    });
    const taxSprite = sprite('', { size: 0.8, color: '#e9d296' });
    taxSprite.position.set(cmdPos.x, 0.4, cmdPos.z + 2.4);
    group.add(taxSprite);
    // Mão: os versos em leque na borda de trás do tapete.
    const hand = new THREE.Group();
    hand.position.set(0, 0, -MAT_D / 2 - 0.6);
    group.add(hand);
    const plate = sprite('', { size: 1.25, color: '#fff', bg: 'rgba(9,15,12,.86)', border: accent });
    plate.position.set(0, 3.2, -MAT_D / 2 - 1.2);
    group.add(plate);
    const status = sprite('', { size: 0.95, color: '#1a150a', bg: 'rgba(233,210,150,.95)' });
    status.position.set(0, 1.4, 0);
    group.add(status);
    const board = { seat: info.seat, group, canvas, tex, mat: matMesh, frame, accent, piles: seatPiles, commands, taxSprite, hand, handCards: [], plate, status,
      cards: new Map(), fading: [], art: null, artUrl: null, painted: '', placement: null, active: false, eliminated: false, reveal: null };
    seats.set(info.seat, board);
    return board;
  }
  function paintSeat(board, name) {
    const signature = `${board.artUrl}|${name}|${!!board.art}`;
    if (board.painted === signature) return;
    board.painted = signature;
    paintMatCanvas(board.canvas, board.art, { mirror: true, label: name, accent: board.accent, rows: false });
    board.tex.needsUpdate = true;
  }
  function removeSeat(board) {
    scene.remove(board.group);
    seats.delete(board.seat);
  }

  /**
   * Oponentes na mesa: [{ seat, index, count, name, life, poison, active, eliminated, online, stage, playmat, hand,
   *   battlefield: [views como as de sync()], piles: { library: {n}, graveyard: {n, top}, exile: {n, top}, command: [{image, tax}] }, reveal }]
   */
  function syncSeats(list) {
    const seen = new Set();
    list.forEach((info) => {
      seen.add(info.seat);
      const board = seats.get(info.seat) || createSeat(info);
      const place = seatPlacement(info.index, info.count);
      board.placement = place;
      board.group.position.set(place.x, 0, place.z);
      board.group.rotation.y = -place.theta;
      board.group.scale.setScalar(place.scale);
      board.active = !!info.active; board.eliminated = !!info.eliminated;
      board.name = info.name;
      if (info.playmat && board.artUrl !== info.playmat) {
        board.artUrl = info.playmat;
        loadArt(info.playmat, (img) => { if (board.artUrl === info.playmat) { board.art = img; paintSeat(board, board.name); } });
      }
      paintSeat(board, info.name);
      board.mat.material.color.set(info.eliminated ? 0x555555 : 0xffffff);
      setSprite(board.plate, `${info.name}${info.online === false ? ' (fora)' : ''} · ♥ ${info.life}${info.poison ? ` · ☠ ${info.poison}` : ''}`);
      setSprite(board.status, info.eliminated ? 'Fora da partida' : info.stage && info.stage !== 'play' ? 'Escolhendo a mão' : '');
      // Cartas no campo.
      const cardSeen = new Set();
      info.battlefield.forEach((v, order) => {
        cardSeen.add(v.iid);
        let entry = board.cards.get(v.iid);
        const p = mirror(v.x, v.z);
        if (!entry) {
          const parts = makeCard(false);
          parts.face.userData = { seat: info.seat, iid: v.iid }; parts.body.userData = { seat: info.seat, iid: v.iid };
          parts.group.position.set(p.x, 0, p.z);
          parts.group.scale.setScalar(0.01);
          board.group.add(parts.group);
          entry = { iid: v.iid, parts, group: parts.group, extras: [], rot: 0, lift: 0, scale: 0.01, pop: true };
          board.cards.set(v.iid, entry);
          if (!still()) {
            const world = board.group.localToWorld(new THREE.Vector3(p.x, 0.2, p.z));
            emit(world.x, 0.25, world.z, 30, v.colors || ['C'], { speed: 2, up: 2.4, life: 0.9 });
          }
        }
        entry.view = v;
        entry.target = { x: p.x, z: p.z, rot: v.tapped ? -Math.PI / 2 : 0, attack: v.attacking ? 1 : 0 };
        entry.order = order;
        applyFace(entry, v);
        applyCounters(entry, v);
      });
      board.cards.forEach((entry, iid) => {
        if (cardSeen.has(iid)) return;
        board.cards.delete(iid);
        board.fading.push({ entry, age: 0 });
      });
      ['library', 'graveyard', 'exile'].forEach((zone) => updatePile(board.piles[zone], info.piles[zone], zone));
      board.commands.forEach((slot, i) => {
        const cmd = info.piles.command[i];
        slot.group.visible = !!cmd?.image;
        if (cmd?.image && slot.group.userData.url !== cmd.image) {
          slot.group.userData.url = cmd.image;
          texture(cmd.image, (tex) => { if (slot.group.userData.url !== cmd.image) return; slot.face.material.map = tex; slot.face.material.emissiveMap = tex; slot.face.material.needsUpdate = true; });
        }
      });
      const taxes = info.piles.command.map((cmd) => cmd.tax || 0);
      setSprite(board.taxSprite, taxes.some(Boolean) ? `Imposto ${taxes.map((t) => `+${t}`).join(' · ')}` : '');
      // Mão em leque (só os versos: a mão é segredo de quem joga).
      const n = Math.min(12, info.hand || 0);
      while (board.handCards.length > n) board.hand.remove(board.handCards.pop());
      while (board.handCards.length < n) { const m = new THREE.Mesh(handBackGeo, handBackMat); board.hand.add(m); board.handCards.push(m); }
      board.handCards.forEach((m, i) => {
        const offset = i - (n - 1) / 2;
        m.position.set(offset * 0.62, 0.9 + Math.abs(offset) * -0.03, -Math.abs(offset) * 0.05);
        m.rotation.set(-0.35, 0, -offset * 0.06);
        m.scale.setScalar(0.72);
      });
      board.revealWant = info.reveal || null;
    });
    seats.forEach((board, seat) => { if (!seen.has(seat)) removeSeat(board); });
    // Só reenquadra quando a mesa muda de forma (alguém entrou ou saiu), para não desfazer o giro de quem está olhando.
    const layoutKey = list.map((info) => `${info.seat}:${info.index}/${info.count}`).join(',');
    if (layoutKey !== seatLayoutKey) { seatLayoutKey = layoutKey; if (camMode !== 'me') refreshView(); }
  }

  /* ---------- Feixes de ataque ---------- */
  // { from: { seat: null|n, iid }, to: null|n } — null é o seu tapete.
  let beams = [];
  const beamColor = new THREE.Color('#ff6a4d');
  function entryWorld(from, out) {
    const entry = from.seat === null ? cards.get(from.iid) : seats.get(from.seat)?.cards.get(from.iid);
    if (!entry) return null;
    return entry.group.getWorldPosition(out);
  }
  function seatCenter(seat, out) {
    if (seat === null) return out.set(0, 0, -1);
    const board = seats.get(seat);
    return board ? out.copy(board.group.position) : null;
  }
  const beamA = new THREE.Vector3(); const beamB = new THREE.Vector3(); const beamM = new THREE.Vector3();
  // Cada ataque vira um arco luminoso (tubo) da criatura até o tapete, refeito só quando as pontas se mexem.
  const beamMeshes = [];
  function updateBeamMeshes(reduce) {
    while (beamMeshes.length > beams.length) { const m = beamMeshes.pop(); scene.remove(m.mesh); m.mesh.geometry.dispose(); m.mesh.material.dispose(); scene.remove(m.head); }
    beams.forEach((beam, i) => {
      const a = entryWorld(beam.from, new THREE.Vector3()); const b = seatCenter(beam.to, new THREE.Vector3());
      let slot = beamMeshes[i];
      if (!slot) {
        const mesh = new THREE.Mesh(new THREE.BufferGeometry(), new THREE.MeshBasicMaterial({ transparent: true, opacity: 0.7, depthWrite: false, blending: THREE.AdditiveBlending }));
        const head = new THREE.Mesh(ringGeo, new THREE.MeshBasicMaterial({ transparent: true, opacity: 0.8, depthWrite: false, blending: THREE.AdditiveBlending, side: THREE.DoubleSide }));
        scene.add(mesh, head);
        slot = beamMeshes[i] = { mesh, head, key: '' };
      }
      slot.mesh.visible = slot.head.visible = !!(a && b);
      if (!a || !b) return;
      a.y = 0.35;
      const key = `${a.x.toFixed(1)},${a.z.toFixed(1)}>${b.x.toFixed(1)},${b.z.toFixed(1)}`;
      if (slot.key !== key) {
        slot.key = key;
        const mid = a.clone().lerp(b, 0.5); mid.y = 2 + a.distanceTo(b) * 0.12;
        const end = b.clone(); end.y = 0.3;
        slot.mesh.geometry.dispose();
        slot.mesh.geometry = new THREE.TubeGeometry(new THREE.QuadraticBezierCurve3(a, mid, end), 32, 0.07, 6, false);
        slot.head.position.set(b.x, 0.04, b.z);
      }
      const color = beam.color || '#ff6a4d';
      slot.mesh.material.color.set(color); slot.head.material.color.set(color);
      slot.mesh.material.opacity = reduce ? 0.6 : 0.45 + Math.sin(time * 5 + i) * 0.25;
      const pulse = reduce ? 0.5 : (time * 0.8 + i * 0.3) % 1;
      slot.head.scale.setScalar(1 + pulse * 2.5);
      slot.head.material.opacity = reduce ? 0.5 : 0.8 * (1 - pulse);
    });
  }

  /* ---------- Efeitos ---------- */
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
    /** Dano chegando num tapete (null = o seu): onda vermelha e faíscas. */
    hit(seat = null, big = false) {
      const c = seatCenter(seat, new THREE.Vector3());
      if (!c) return;
      emit(c.x, 0.4, c.z, big ? 160 : 90, ['#ff5b3a', '#ffb199', '#ffffff'], { speed: big ? 6 : 4.5, up: 2.5, gravity: -4, life: 1.1, spread: 3 });
      shockwave(c.x, c.z, '#ff5b3a', big ? 12 : 8);
      if (seat === null) shake = 0.5;
    },
    /** Começo de turno: um anel dourado varre o tapete de quem vai jogar. */
    turn(seat = null) {
      const c = seatCenter(seat, new THREE.Vector3());
      if (!c) return;
      shockwave(c.x, c.z, '#d9b45a', seat === null ? 16 : 11);
      emit(c.x, 0.3, c.z, 50, ['#d9b45a', '#fff1c9'], { speed: 5, up: 1.4, gravity: -1, life: 1.2, spread: 4 });
    },
  };
  let shake = 0;

  /* ---------- Câmera ---------- */
  // Modos: 'me' (seu tapete entre o HUD e a mão), 'table' (todos os tapetes) ou o número de um assento (o tapete dele).
  const PITCH = 1.08;
  const view = { yaw: 0, pitch: PITCH, zoom: 1, dist: 24, target: new THREE.Vector3(0.2, 0, -0.5) };
  const goal = { yaw: 0, pitch: PITCH, zoom: 1, dist: 24, target: new THREE.Vector3(0.2, 0, -0.5) };
  const base = { yaw: 0, pitch: PITCH };
  const baseTarget = new THREE.Vector3(0.2, 0, -0.5);
  let camMode = 'me';
  // Vista de cima: a câmera trava olhando a mesa de cima (arrastar move a mesa em vez de girar).
  const TOP_PITCH = 1.47;
  let topDown = false;
  let focusKey = null;
  let width = 1; let height = 1;
  const probe = new THREE.PerspectiveCamera();
  function orbitPosition(out, target, yaw, pitch, dist) {
    return out.set(target.x + dist * Math.cos(pitch) * Math.sin(yaw), dist * Math.sin(pitch), target.z + dist * Math.cos(pitch) * Math.cos(yaw));
  }
  /** Distância em que todos os pontos cabem entre o HUD (topo) e a mão (base). */
  function fitPoints(pointsList, target, yaw, pitch) {
    probe.copy(camera);
    const top = 1 - (hooks.topInset?.() || 110) / height * 2;
    const bottom = -1 + (hooks.bottomInset?.() || 150) / height * 2;
    // Painel à direita (vida e oponentes): na faixa de altura dele, os pontos precisam ficar à esquerda.
    const panel = hooks.rightPanel?.() || null;
    const panelEdge = panel ? 1 - panel.width / width * 2 : 1;
    const panelBottom = panel ? 1 - panel.bottom / height * 2 : 1;
    const point = new THREE.Vector3();
    let dist = 30;
    for (let pass = 0; pass < 10; pass++) {
      orbitPosition(probe.position, target, yaw, pitch, dist);
      probe.lookAt(target);
      probe.updateMatrixWorld();
      let reach = 0;
      pointsList.forEach((p) => {
        point.copy(p).project(probe);
        reach = Math.max(reach, Math.abs(point.x) / 0.97, point.y > 0 ? point.y / top : point.y / bottom);
        if (panel && camMode !== 'me' && point.y > panelBottom && point.x > 0) reach = Math.max(reach, point.x / Math.max(0.3, panelEdge));
      });
      dist *= THREE.MathUtils.clamp(reach, 0.7, 1.4);
    }
    return THREE.MathUtils.clamp(dist, 10, 160);
  }
  const myPoints = () => {
    const left = hasCommand ? LAYOUT.zones.command.x - 2.3 : LAYOUT.left - CARD_W * 1.2; const right = LAYOUT.zones.library.x + 1.4;
    const far = LAYOUT.rows.creature - CARD_H * 0.75; const near = LAYOUT.rows.land2 + CARD_H * 0.7;
    return [[left, far], [right, far], [left, near], [right, near]].map(([x, z]) => new THREE.Vector3(x, 0, z));
  };
  function seatPoints(board) {
    const pts = [];
    [[-MAT_W / 2, -MAT_D / 2 - 1.6], [MAT_W / 2, -MAT_D / 2 - 1.6], [-MAT_W / 2, MAT_D / 2], [MAT_W / 2, MAT_D / 2]].forEach(([x, z]) => pts.push(board.group.localToWorld(new THREE.Vector3(x, 0, z))));
    // A plaquinha com nome e vida, acima da borda de trás do tapete, também entra no quadro.
    pts.push(board.plate.getWorldPosition(new THREE.Vector3()).add(new THREE.Vector3(0, 0.8, 0)));
    return pts;
  }
  function refreshView(instant = false) {
    const target = new THREE.Vector3();
    let yaw = 0; let pitch = PITCH; let dist = 24;
    const board = typeof camMode === 'number' ? seats.get(camMode) : null;
    if (typeof camMode === 'number' && !board) camMode = 'table';
    if (camMode === 'me' || typeof camMode === 'number' && !board || (camMode === 'table' && !seats.size)) {
      // Sem oponentes ainda, a "mesa toda" é o seu tapete; o modo continua valendo para quando eles chegarem.
      target.set(0.2, 0, -0.5);
      pitch = topDown ? TOP_PITCH : PITCH;
      dist = fitPoints(myPoints(), target, 0, pitch);
    } else if (camMode === 'table') {
      // Do seu tapete basta até a última fileira de terrenos: a borda de baixo fica sob a mão e as cartas crescem na tela.
      const near = LAYOUT.rows.land2 + CARD_H * 0.8;
      const pts = [new THREE.Vector3(-MAT_W / 2, 0, near), new THREE.Vector3(MAT_W / 2, 0, near)];
      seats.forEach((b) => { b.group.updateMatrixWorld(); pts.push(...seatPoints(b)); });
      const box = new THREE.Box3().setFromPoints(pts);
      box.getCenter(target); target.y = 0;
      // Mais de cima: a perspectiva encolhe menos os tapetes do fundo.
      pitch = topDown ? TOP_PITCH : 1.2;
      dist = fitPoints(pts, target, 0, pitch);
    } else {
      board.group.updateMatrixWorld();
      target.copy(board.group.position);
      yaw = -board.placement.theta;
      pitch = topDown ? TOP_PITCH : 1.02;
      dist = fitPoints(seatPoints(board), target, yaw, pitch);
    }
    base.yaw = yaw; base.pitch = pitch;
    baseTarget.copy(target);
    focusKey = null;
    goal.yaw = yaw; goal.pitch = pitch; goal.dist = dist; goal.target.copy(target); goal.zoom = 1;
    if (instant) { view.yaw = yaw; view.pitch = pitch; view.dist = dist; view.target.copy(target); view.zoom = 1; }
  }
  function resize() {
    const rect = host.getBoundingClientRect();
    width = Math.max(1, rect.width); height = Math.max(1, rect.height);
    renderer.setSize(width, height, false);
    camera.aspect = width / height;
    camera.updateProjectionMatrix();
    if (width > 1) refreshView(!resized);
    resized = true;
  }
  let resized = false;
  new ResizeObserver(resize).observe(host);
  function placeCamera(dt, reduce) {
    const k = reduce ? 1 : 1 - Math.exp(-dt * 6);
    view.yaw += (goal.yaw - view.yaw) * k; view.pitch += (goal.pitch - view.pitch) * k; view.zoom += (goal.zoom - view.zoom) * k;
    view.dist += (goal.dist - view.dist) * k;
    view.target.lerp(goal.target, k);
    orbitPosition(camera.position, view.target, view.yaw, view.pitch, view.dist * view.zoom);
    if (shake > 0 && !reduce) { camera.position.x += (Math.random() - 0.5) * shake; camera.position.y += (Math.random() - 0.5) * shake * 0.5; shake = Math.max(0, shake - dt * 1.4); }
    camera.lookAt(view.target);
  }

  const MIN_ZOOM = 0.28;
  /** O alvo da câmera não sai da área da mesa. */
  function clampTarget() {
    const limit = seats.size ? 34 : 14;
    goal.target.x = THREE.MathUtils.clamp(goal.target.x, baseTarget.x - limit, baseTarget.x + limit);
    goal.target.z = THREE.MathUtils.clamp(goal.target.z, baseTarget.z - limit, baseTarget.z + limit * 0.8);
  }
  function resetCamera() {
    focusKey = null;
    goal.yaw = base.yaw; goal.pitch = base.pitch; goal.zoom = 1; goal.target.copy(baseTarget);
  }
  /** Leve zoom numa carta (sua ou de um oponente); de novo na mesma carta, volta. Devolve true se aproximou. */
  function focusCard(target) {
    const entry = target.seat === undefined || target.seat === null ? cards.get(target.iid) : seats.get(target.seat)?.cards.get(target.iid);
    if (!entry) return false;
    const key = `${target.seat ?? 'me'}:${target.iid}`;
    if (focusKey === key) { resetCamera(); return false; }
    focusKey = key;
    const p = entry.group.getWorldPosition(new THREE.Vector3());
    goal.target.set(p.x, 0, p.z);
    goal.zoom = Math.min(goal.zoom, seats.size ? 0.42 : 0.55);
    return true;
  }
  function zoomBy(factor) {
    goal.zoom = THREE.MathUtils.clamp(goal.zoom * factor, MIN_ZOOM, 1.3);
    if (factor > 1 && goal.zoom > 0.98) goal.target.copy(baseTarget);
  }
  function setTopDown(on) {
    topDown = !!on;
    refreshView();
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
      if (zone === 'command' && !hasCommand) continue;
      const w = zone === 'command' ? 2.0 : CARD_W * 0.75; const d = zone === 'command' ? 2.2 : CARD_H * 0.72;
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
    if (revealParts.group.visible) targets.push(revealParts.face, revealParts.body);
    if (hasCommand) targets.push(daisHit);
    commandSlots.forEach((slot) => { if (slot.group.visible) targets.push(slot.face); });
    seats.forEach((board) => {
      board.cards.forEach((entry) => { targets.push(entry.parts.face, entry.parts.body); });
      ['library', 'graveyard', 'exile'].forEach((zone) => { if (board.piles[zone].stack.visible) targets.push(board.piles[zone].stack); });
      board.commands.forEach((slot) => { if (slot.group.visible) targets.push(slot.face); });
      targets.push(board.mat);
    });
    const found = raycaster.intersectObjects(targets, false)[0];
    if (!found) return null;
    const data = found.object.userData;
    if (data.seatMat !== undefined) return { seat: data.seatMat, mat: true };
    if (data.seat !== undefined) return data.iid ? { seat: data.seat, iid: data.iid } : { seat: data.seat, zone: data.zone, slot: data.slot };
    return data.iid ? { iid: data.iid } : { zone: data.zone, slot: data.slot };
  }

  let pointer = null;
  let hoverZone = null;
  let dropZone = null;
  let lastMove = null;
  dom.addEventListener('contextmenu', (event) => event.preventDefault());
  dom.addEventListener('pointerdown', (event) => {
    const target = pickObject(event.clientX, event.clientY);
    dom.setPointerCapture(event.pointerId);
    pointer = { id: event.pointerId, x: event.clientX, y: event.clientY, sx: event.clientX, sy: event.clientY, button: event.button, target, moved: false, yaw: goal.yaw, pitch: goal.pitch, from: goal.target.clone() };
    if (event.button === 2) {
      // Depois do evento: o clique que fecha menus abertos não pode fechar este.
      const { clientX, clientY } = event;
      setTimeout(() => {
        if (target?.seat !== undefined) hooks.onSeatContext?.(target.seat, target, clientX, clientY);
        else if (target?.iid) hooks.onCardContext?.(target.iid, clientX, clientY);
        else if (target?.zone) hooks.onPileContext?.(target.zone, clientX, clientY, target.slot);
        else hooks.onTableContext?.(clientX, clientY);
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
      if (pointer.target?.iid && pointer.target.seat === undefined && cards.has(pointer.target.iid)) { dragId = pointer.target.iid; hooks.onDragStart?.(dragId); }
    }
    if (!pointer.moved) return;
    if (dragId) {
      const p = pick(event.clientX, event.clientY);
      const entry = cards.get(dragId);
      if (entry) { entry.drag = { x: p.x, z: p.z }; }
      dropZone = p.zone;
    } else if (topDown) {
      // Vista de cima: a mesa acompanha o ponteiro.
      const perPixel = 2 * view.dist * view.zoom * Math.tan(THREE.MathUtils.degToRad(camera.fov / 2)) / height;
      const right = new THREE.Vector3().setFromMatrixColumn(camera.matrixWorld, 0).setY(0).normalize();
      const up = new THREE.Vector3().setFromMatrixColumn(camera.matrixWorld, 1).setY(0).normalize();
      goal.target.copy(pointer.from).addScaledVector(right, -dx * perPixel).addScaledVector(up, dy * perPixel);
      clampTarget();
    } else if (!pointer.target || pointer.target.mat || pointer.target.seat !== undefined) {
      goal.yaw = THREE.MathUtils.clamp(pointer.yaw - dx * 0.004, base.yaw - 0.6, base.yaw + 0.6);
      goal.pitch = THREE.MathUtils.clamp(pointer.pitch + dy * 0.003, 0.6, 1.35);
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
    if (p.target?.seat !== undefined) hooks.onSeatClick?.(p.target.seat, p.target, event);
    else if (p.target?.iid) hooks.onCardClick?.(p.target.iid, event);
    else if (p.target?.zone) hooks.onPileClick?.(p.target.zone, event, p.target.slot);
  };
  dom.addEventListener('pointerup', release);
  dom.addEventListener('pointercancel', release);
  dom.addEventListener('pointerleave', () => { lastMove = null; if (hoverId || hoverZone || hoverSeat !== null) { hoverId = null; hoverZone = null; hoverSeat = null; hooks.onHover?.(null); } });
  dom.addEventListener('wheel', (event) => {
    event.preventDefault();
    const before = goal.zoom;
    goal.zoom = THREE.MathUtils.clamp(goal.zoom * Math.exp(event.deltaY * 0.0012), MIN_ZOOM, 1.3);
    // Aproxima na direção do ponteiro (o ponto sob o mouse fica parado); afastando até o fim, volta ao centro.
    setNdc(event.clientX, event.clientY);
    if (goal.zoom < before && raycaster.ray.intersectPlane(plane, hit)) {
      const f = 1 - goal.zoom / before;
      goal.target.x += (hit.x - goal.target.x) * f; goal.target.z += (hit.z - goal.target.z) * f;
      clampTarget();
    } else if (goal.zoom > before) {
      const f = Math.min(1, (goal.zoom - before) / Math.max(0.05, 1 - before));
      goal.target.lerp(baseTarget, f);
    }
    focusKey = null;
  }, { passive: false });
  dom.addEventListener('dblclick', resetCamera);

  /* ---------- Quadro a quadro ---------- */
  let ghost = null;
  let running = true;
  let last = 0;
  let time = 0;
  function animateEntry(entry, ease, reduce, dt, attackDir = -1) {
    const t = entry.target; if (!t) return;
    const dragging = entry.drag;
    const tx = dragging ? dragging.x : t.x; const tz = (dragging ? dragging.z : t.z) + attackDir * t.attack * 0.7;
    const hovered = (entry === hoverEntry) && !dragging;
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
  }
  let hoverEntry = null;
  function tick(now) {
    requestAnimationFrame(tick);
    if (!running) return;
    const dt = Math.min(0.05, (now - (last || now)) / 1000); last = now; time += dt;
    const reduce = still();
    placeCamera(dt, reduce);
    if (lastMove && !pointer) {
      const target = pickObject(lastMove.x, lastMove.y);
      const id = target?.iid || null; const zone = target?.zone || null; const seat = target?.seat ?? null;
      if (id !== hoverId || zone !== hoverZone || seat !== hoverSeat) {
        hoverId = id; hoverZone = zone; hoverSeat = seat;
        hoverEntry = id ? (seat === null ? cards.get(id) : seats.get(seat)?.cards.get(id)) || null : null;
        hooks.onHover?.(target && !target.mat ? target : null);
        dom.style.cursor = (id || zone || target?.mat) ? 'pointer' : '';
      }
    }
    const ease = reduce ? 1 : 1 - Math.exp(-dt * 12);
    cards.forEach((entry) => animateEntry(entry, ease, reduce, dt, -1));
    seats.forEach((board) => {
      board.cards.forEach((entry) => animateEntry(entry, ease, reduce, dt, 1));
      for (let i = board.fading.length - 1; i >= 0; i--) {
        const f = board.fading[i];
        f.age += dt * (reduce ? 10 : 3);
        const u = Math.min(1, f.age);
        f.entry.group.scale.setScalar(Math.max(0.01, 1 - u));
        f.entry.group.position.y = u * 1.2;
        if (u >= 1) { board.group.remove(f.entry.group); board.fading.splice(i, 1); }
      }
      const wanted = board.active ? 0.55 + Math.sin(time * 2.4) * 0.25 : 0;
      board.frame.material.opacity += (wanted - board.frame.material.opacity) * ease;
      board.commands.forEach((slot, i) => { slot.group.position.y = 1.2 + (reduce ? 0 : Math.sin(time * 1.3 + i) * 0.06); });
      ['library', 'graveyard', 'exile'].forEach((zone) => {
        const pile = board.piles[zone];
        const on = hoverSeat === board.seat && hoverZone === zone;
        pile.glow.material.opacity += ((on ? 0.8 : 0) - pile.glow.material.opacity) * ease;
      });
    });
    myFrame.material.opacity += ((activeMe && seats.size ? 0.5 + Math.sin(time * 2.4) * 0.22 : 0) - myFrame.material.opacity) * ease;
    // Feixes de ataque: arco luminoso e brasas correndo da criatura até o tapete atacado.
    updateBeamMeshes(reduce);
    if (!reduce) beams.forEach((beam) => {
      const a = entryWorld(beam.from, beamA); const b = seatCenter(beam.to, beamB);
      if (!a || !b) return;
      const color = beam.color || '#ff6a4d';
      for (let n = 0; n < 4 && particles.length < MAX_P; n++) {
        const u = Math.random();
        beamM.lerpVectors(a, b, u);
        const lift = Math.sin(u * Math.PI) * (2 + a.distanceTo(b) * 0.08);
        particles.push({ x: beamM.x, y: 0.4 + lift, z: beamM.z, vx: (b.x - a.x) * 0.05, vy: 0, vz: (b.z - a.z) * 0.05, g: 0, life: 0.5, age: 0, color: beamColor.clone().set(color) });
      }
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
      const on = (hoverSeat === null && hoverZone === zone) || dropZone === zone;
      const wanted = on ? 0.8 : zone === 'exile' && pile.n > 0 ? 0.25 + Math.sin(time * 2) * 0.12 : 0;
      pile.glow.material.opacity += (wanted - pile.glow.material.opacity) * ease;
    });
    if (shuffleAge < 1) {
      shuffleAge += dt * 1.8;
      piles.library.stack.rotation.y = reduce ? 0 : Math.sin(shuffleAge * 28) * 0.18 * (1 - shuffleAge);
      piles.library.stack.position.x = reduce ? 0 : Math.sin(shuffleAge * 21) * 0.12 * (1 - shuffleAge);
    }
    // Topo revelado: abre virando pelo verso; ao trocar de carta, fecha, troca a face e abre de novo.
    if (reveal.want !== reveal.url && reveal.open < 0.03) { reveal.url = reveal.want; if (reveal.url) setRevealFace(reveal.url); }
    const openTarget = reveal.want && reveal.want === reveal.url ? 1 : 0;
    reveal.open += (openTarget - reveal.open) * (reduce ? 1 : 1 - Math.exp(-dt * 6));
    if (reduce && reveal.want !== reveal.url) reveal.open = 0;
    const o = reveal.open;
    revealParts.group.visible = o > 0.005 && piles.library.height > 0;
    revealParts.group.position.set(-o * 0.15, (piles.library.height || 0) + 0.02 + o * 1.45 + (reduce ? 0 : Math.sin(time * 1.4) * 0.04 * o), -o * 0.2);
    revealParts.group.rotation.set(o * 0.95, 0, Math.PI * (1 - o));
    revealParts.group.scale.setScalar(1 + o * 0.32);
    daisRing.material.emissiveIntensity = 0.45 + Math.sin(time * 1.6) * 0.2 + ((hoverSeat === null && hoverZone === 'command') || dropZone === 'command' ? 0.5 : 0);
    commandSlots.forEach((slot, i) => { slot.group.position.y = 1.25 + (reduce ? 0 : Math.sin(time * 1.3 + i) * 0.06); });
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
      parts.face.material.transparent = true;
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
    syncSeats,
    setBeams(list) { beams = list || []; },
    /** Troca a câmera: 'me', 'table' ou o número de um assento. */
    view(mode) { camMode = mode; refreshView(); },
    viewMode: () => camMode,
    focusCard,
    resetCamera,
    zoomBy,
    setTopDown,
    isTopDown: () => topDown,
    isFocused: () => focusKey !== null || goal.zoom < 0.98,
    spawn(iid, from) { spawnFrom.set(iid, from); },
    revealTop(url) {
      const next = url || null;
      if (next && next !== reveal.want) {
        const lib = LAYOUT.zones.library;
        emit(lib.x, (piles.library.height || 0) + 0.6, lib.z, 26, ['#fff1c9', '#d9b45a'], { speed: 1.2, up: 2.2, gravity: -1.5, life: 0.9, spread: 0.8 });
      }
      reveal.want = next;
    },
    depart,
    fx,
    pick,
    showGhost,
    hideGhost,
    resize,
    playmat(url) {
      if (url) loadArt(url, paintMat);
    },
    projectToScreen(x, z) {
      const v = new THREE.Vector3(x, 0.5, z).project(camera);
      return { x: (v.x + 1) / 2 * width, y: (1 - v.y) / 2 * height };
    },
  };
}
