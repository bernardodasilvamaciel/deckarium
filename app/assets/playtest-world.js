/* Mundo da mesa 3D do Deckarium: as cinco paisagens em volta da mesa (planícies, ilhas, pântanos, montanhas e
   florestas), a mesa de madeira com pés e verniz, a luz e a névoa de cada lugar, e os versos das cartas — o shield
   (a capa colorida) com a estampa escolhida. Tudo é desenhado aqui mesmo, sem imagens de fora: terreno por ruído,
   árvores, pedras e juncos instanciados, água com reflexo e partículas (pólen, vaga-lumes, brasas, folhas).
   Usado pela mesa (playtest-scene.js) e pela vitrine da página inicial. */
import * as THREE from './vendor/three.module.min.js';

/** Altura da mesa em unidades da cena (uma carta tem ~2,5 de altura: 1 unidade ≈ 3,5 cm). */
export const TABLE_HEIGHT = 21;
const GROUND = -TABLE_HEIGHT;

export const ENVIRONMENTS = {
  plains: { name: 'Planícies', mana: 'W', blurb: 'Campos dourados, vento e pólen no ar.' },
  island: { name: 'Ilhas', mana: 'U', blurb: 'Uma praia de areia branca no meio do mar.' },
  swamp: { name: 'Pântanos', mana: 'B', blurb: 'Água parada, árvores mortas e vaga-lumes.' },
  mountain: { name: 'Montanhas', mana: 'R', blurb: 'Rocha vermelha, um vulcão e brasas subindo.' },
  forest: { name: 'Florestas', mana: 'G', blurb: 'Pinheiros altos e folhas caindo.' },
};
const MANA_ENV = { W: 'plains', U: 'island', B: 'swamp', R: 'mountain', G: 'forest' };

/** Ambiente pelas cores do deck: a cor que mais aparece; incolor fica nas planícies. */
export function environmentForColors(counts) {
  let best = 'W'; let most = -1;
  ['W', 'U', 'B', 'R', 'G'].forEach((color) => { const n = counts?.[color] || 0; if (n > most) { most = n; best = color; } });
  return most > 0 ? MANA_ENV[best] : 'plains';
}

/** Shields: a capa da carta (bordas e verso). accent é a cor das linhas da estampa sobre ela. */
export const SLEEVES = {
  esmeralda: { name: 'Esmeralda', color: '#1d5a43', accent: '#d9b45a' },
  obsidiana: { name: 'Obsidiana', color: '#17191d', accent: '#c9a44f' },
  rubi: { name: 'Rubi', color: '#7c1b27', accent: '#f0c46a' },
  safira: { name: 'Safira', color: '#1b457e', accent: '#e7d29a' },
  ametista: { name: 'Ametista', color: '#53307c', accent: '#f0d28a' },
  ambar: { name: 'Âmbar', color: '#a7721e', accent: '#2b1a07' },
  perola: { name: 'Pérola', color: '#e7e1d3', accent: '#7a5b22' },
  gelo: { name: 'Gelo', color: '#9cc3d8', accent: '#183247' },
};
/** Estampas do verso, impressas sobre o shield. 'arte' usa a ilustração da comandante (ou a carta-chave do deck). */
export const BACKS = {
  deckarium: { name: 'Deckarium' },
  constelacao: { name: 'Constelação' },
  runas: { name: 'Runas' },
  escamas: { name: 'Escamas' },
  lisa: { name: 'Lisa' },
  arte: { name: 'Arte do deck' },
};
export const WOODS = {
  carvalho: { name: 'Carvalho', dark: '#6b4423', light: '#b9824a' },
  nogueira: { name: 'Nogueira', dark: '#3a2414', light: '#7a5132' },
  mogno: { name: 'Mogno', dark: '#4a1a10', light: '#94442a' },
};
export const DEFAULT_STYLE = { sleeve: 'esmeralda', back: 'deckarium' };

/* ---------- Ruído ---------- */
function hash2(x, y) {
  let h = (Math.imul(x | 0, 374761393) + Math.imul(y | 0, 668265263)) | 0;
  h = Math.imul(h ^ (h >>> 13), 1274126177);
  return ((h ^ (h >>> 16)) >>> 0) / 4294967295;
}
function noise(x, y) {
  const xi = Math.floor(x); const yi = Math.floor(y);
  const xf = x - xi; const yf = y - yi;
  const u = xf * xf * (3 - 2 * xf); const v = yf * yf * (3 - 2 * yf);
  const a = hash2(xi, yi); const b = hash2(xi + 1, yi); const c = hash2(xi, yi + 1); const d = hash2(xi + 1, yi + 1);
  return a + (b - a) * u + (c - a) * v + (a - b - c + d) * u * v;
}
function fbm(x, y, octaves = 4) {
  let sum = 0; let amp = 0.5; let f = 1; let norm = 0;
  for (let i = 0; i < octaves; i++) { sum += amp * noise(x * f + i * 17.3, y * f - i * 9.1); norm += amp; f *= 2; amp *= 0.5; }
  return sum / norm;
}
const smooth = (a, b, x) => { const t = Math.min(1, Math.max(0, (x - a) / (b - a))); return t * t * (3 - 2 * t); };
let seedState = 7;
const rand = () => { seedState = (Math.imul(seedState, 1664525) + 1013904223) | 0; return ((seedState >>> 0) % 100000) / 100000; };
const reseed = (n) => { seedState = n; };

function canvas(width, height, draw) {
  const el = document.createElement('canvas');
  el.width = width; el.height = height;
  draw(el.getContext('2d'), width, height);
  return el;
}
function roundRect(ctx, x, y, w, h, r) {
  ctx.beginPath();
  ctx.moveTo(x + r, y); ctx.arcTo(x + w, y, x + w, y + h, r); ctx.arcTo(x + w, y + h, x, y + h, r);
  ctx.arcTo(x, y + h, x, y, r); ctx.arcTo(x, y, x + w, y, r); ctx.closePath();
}
const mix = (a, b, t) => a + (b - a) * t;
function hexRgb(hex) { const n = parseInt(hex.slice(1), 16); return [(n >> 16) & 255, (n >> 8) & 255, n & 255]; }
function shade(hex, f) { const [r, g, b] = hexRgb(hex); const c = (v) => Math.max(0, Math.min(255, Math.round(f >= 0 ? v + (255 - v) * f : v * (1 + f)))); return `rgb(${c(r)},${c(g)},${c(b)})`; }

/* ---------- Verso das cartas ---------- */
/**
 * Desenha o verso (shield + estampa) num canvas 2D. Usado pela mesa (textura) e pelas prévias da página.
 * art: uma imagem já carregada para a estampa 'arte' (sem ela, cai na lisa).
 */
export function drawCardBack(ctx, w, h, style = DEFAULT_STYLE, art = null) {
  const sleeve = SLEEVES[style.sleeve] || SLEEVES.esmeralda;
  const design = BACKS[style.back] ? style.back : 'deckarium';
  const base = sleeve.color; const accent = sleeve.accent;
  const u = w / 488;
  ctx.clearRect(0, 0, w, h);
  // Shield: cor sólida com um leve degradê, como plástico fosco.
  const g = ctx.createLinearGradient(0, 0, w, h);
  g.addColorStop(0, shade(base, 0.12)); g.addColorStop(0.55, base); g.addColorStop(1, shade(base, -0.28));
  roundRect(ctx, 0, 0, w, h, 26 * u); ctx.fillStyle = g; ctx.fill();
  const inset = 20 * u;
  const iw = w - inset * 2; const ih = h - inset * 2;
  ctx.save();
  roundRect(ctx, inset, inset, iw, ih, 18 * u); ctx.clip();
  const cx = w / 2; const cy = h / 2;
  if (design === 'arte' && art) {
    const scale = Math.max(iw / art.width, ih / art.height);
    ctx.drawImage(art, cx - art.width * scale / 2, cy - art.height * scale / 2, art.width * scale, art.height * scale);
    const v = ctx.createRadialGradient(cx, cy, ih * 0.2, cx, cy, ih * 0.75);
    v.addColorStop(0, 'rgba(0,0,0,0)'); v.addColorStop(1, 'rgba(0,0,0,.55)');
    ctx.fillStyle = v; ctx.fillRect(0, 0, w, h);
  } else if (design === 'constelacao') {
    const sky = ctx.createRadialGradient(cx, cy * 0.8, 10, cx, cy, ih * 0.7);
    sky.addColorStop(0, shade(base, -0.35)); sky.addColorStop(1, shade(base, -0.75));
    ctx.fillStyle = sky; ctx.fillRect(0, 0, w, h);
    reseed(11);
    for (let i = 0; i < 160; i++) { ctx.fillStyle = `rgba(255,248,225,${0.25 + rand() * 0.6})`; const r = (0.6 + rand() * 1.8) * u; ctx.beginPath(); ctx.arc(inset + rand() * iw, inset + rand() * ih, r, 0, Math.PI * 2); ctx.fill(); }
    const stars = [[0.3, 0.22], [0.42, 0.3], [0.55, 0.27], [0.64, 0.38], [0.58, 0.5], [0.45, 0.56], [0.36, 0.7], [0.5, 0.78], [0.68, 0.72]];
    ctx.strokeStyle = accent; ctx.globalAlpha = 0.7; ctx.lineWidth = 2 * u;
    ctx.beginPath(); stars.forEach(([x, y], i) => { const px = x * w; const py = y * h; if (i) ctx.lineTo(px, py); else ctx.moveTo(px, py); }); ctx.stroke();
    ctx.globalAlpha = 1;
    stars.forEach(([x, y]) => { const glow = ctx.createRadialGradient(x * w, y * h, 0, x * w, y * h, 14 * u); glow.addColorStop(0, '#fff8e0'); glow.addColorStop(1, 'rgba(255,240,200,0)'); ctx.fillStyle = glow; ctx.fillRect(x * w - 14 * u, y * h - 14 * u, 28 * u, 28 * u); });
    ctx.fillStyle = accent; ctx.beginPath(); ctx.arc(w * 0.72, h * 0.16, 30 * u, 0, Math.PI * 2); ctx.fill();
    ctx.fillStyle = shade(base, -0.6); ctx.beginPath(); ctx.arc(w * 0.75, h * 0.145, 27 * u, 0, Math.PI * 2); ctx.fill();
  } else if (design === 'runas') {
    ctx.fillStyle = shade(base, -0.3); ctx.fillRect(0, 0, w, h);
    ctx.strokeStyle = accent; ctx.lineWidth = 3 * u; ctx.globalAlpha = 0.9;
    [150, 118, 64].forEach((r) => { ctx.beginPath(); ctx.arc(cx, cy, r * u, 0, Math.PI * 2); ctx.stroke(); });
    reseed(5);
    for (let i = 0; i < 24; i++) {
      const a = (i / 24) * Math.PI * 2; const r = 134 * u;
      ctx.save(); ctx.translate(cx + Math.cos(a) * r, cy + Math.sin(a) * r); ctx.rotate(a + Math.PI / 2);
      ctx.beginPath(); const s = 9 * u;
      ctx.moveTo(0, -s); ctx.lineTo(0, s);
      if (rand() > 0.4) { ctx.moveTo(0, -s * 0.3); ctx.lineTo(s * 0.8, -s); }
      if (rand() > 0.5) { ctx.moveTo(0, s * 0.2); ctx.lineTo(-s * 0.8, s * 0.7); }
      if (rand() > 0.6) { ctx.moveTo(-s * 0.6, 0); ctx.lineTo(s * 0.6, 0); }
      ctx.stroke(); ctx.restore();
    }
    ctx.beginPath();
    for (let i = 0; i < 6; i++) { const a = -Math.PI / 2 + i * (Math.PI * 2 / 3) * 1; ctx.lineTo(cx + Math.cos(a) * 60 * u, cy + Math.sin(a) * 60 * u); }
    ctx.closePath(); ctx.stroke();
    ctx.beginPath(); for (let i = 0; i < 3; i++) { const a = Math.PI / 2 + i * (Math.PI * 2 / 3); ctx.lineTo(cx + Math.cos(a) * 60 * u, cy + Math.sin(a) * 60 * u); } ctx.closePath(); ctx.stroke();
    ctx.globalAlpha = 0.35; ctx.lineWidth = 1.5 * u;
    for (let r = 200; r < 420; r += 26) { ctx.beginPath(); ctx.arc(cx, cy, r * u, 0, Math.PI * 2); ctx.stroke(); }
    ctx.globalAlpha = 1;
  } else if (design === 'escamas') {
    ctx.fillStyle = shade(base, -0.2); ctx.fillRect(0, 0, w, h);
    const s = 34 * u;
    for (let row = -1; row * s * 0.55 < h + s; row++) {
      for (let col = -1; col * s < w + s; col++) {
        const x = col * s + (row % 2 ? s / 2 : 0); const y = row * s * 0.55;
        const grad = ctx.createLinearGradient(x, y - s / 2, x, y + s / 2);
        grad.addColorStop(0, shade(base, 0.18)); grad.addColorStop(1, shade(base, -0.4));
        ctx.fillStyle = grad; ctx.beginPath(); ctx.arc(x, y, s / 2, 0, Math.PI); ctx.fill();
        ctx.strokeStyle = accent; ctx.globalAlpha = 0.35; ctx.lineWidth = 1.4 * u; ctx.stroke(); ctx.globalAlpha = 1;
      }
    }
  } else if (design === 'lisa' || design === 'arte') {
    const v = ctx.createRadialGradient(cx, cy * 0.85, 20, cx, cy, ih * 0.7);
    v.addColorStop(0, shade(base, 0.06)); v.addColorStop(1, shade(base, -0.22));
    ctx.fillStyle = v; ctx.fillRect(0, 0, w, h);
  } else {
    // Deckarium: anéis concêntricos e o nome, como o verso original da mesa de teste.
    const gg = ctx.createLinearGradient(0, 0, w, h);
    gg.addColorStop(0, shade(base, 0.05)); gg.addColorStop(0.5, shade(base, -0.35)); gg.addColorStop(1, shade(base, -0.6));
    ctx.fillStyle = gg; ctx.fillRect(0, 0, w, h);
    ctx.strokeStyle = accent; ctx.globalAlpha = 0.42; ctx.lineWidth = 2 * u;
    for (let r = 60; r < 330; r += 34) { ctx.beginPath(); ctx.ellipse(cx, cy, r * 0.62 * u, r * u, 0, 0, Math.PI * 2); ctx.stroke(); }
    ctx.globalAlpha = 1;
    ctx.fillStyle = accent; ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
    ctx.font = `700 ${Math.round(64 * u)}px Spectral, Georgia, serif`; ctx.fillText('Deckarium', cx, cy);
    ctx.globalAlpha = 0.7; ctx.font = `600 ${Math.round(19 * u)}px "Segoe UI", sans-serif`; ctx.fillText('M E S A', cx, cy + 50 * u); ctx.globalAlpha = 1;
  }
  ctx.restore();
  // Moldura da estampa e o brilho do plástico.
  roundRect(ctx, inset, inset, iw, ih, 18 * u); ctx.strokeStyle = accent; ctx.lineWidth = 5 * u; ctx.stroke();
  if (design === 'lisa' || (design === 'arte' && !art)) {
    ctx.fillStyle = accent; ctx.globalAlpha = 0.5; ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
    ctx.font = `700 ${Math.round(40 * u)}px Spectral, Georgia, serif`; ctx.fillText('D', cx, cy); ctx.globalAlpha = 1;
    ctx.beginPath(); ctx.arc(cx, cy, 42 * u, 0, Math.PI * 2); ctx.strokeStyle = accent; ctx.globalAlpha = 0.45; ctx.lineWidth = 3 * u; ctx.stroke(); ctx.globalAlpha = 1;
  }
  const gloss = ctx.createLinearGradient(0, 0, w * 0.9, h * 0.6);
  gloss.addColorStop(0, 'rgba(255,255,255,.16)'); gloss.addColorStop(0.35, 'rgba(255,255,255,.03)'); gloss.addColorStop(0.36, 'rgba(255,255,255,0)');
  roundRect(ctx, 0, 0, w, h, 26 * u); ctx.fillStyle = gloss; ctx.fill();
}

/** Prévia do verso como data URL (para as janelas e os botões de escolha). */
export function cardBackDataUrl(style, art = null, width = 244) {
  const el = canvas(width, Math.round(width * 680 / 488), (ctx, w, h) => drawCardBack(ctx, w, h, style, art));
  return el.toDataURL('image/png');
}

/* ---------- Madeira ---------- */
const woodCache = new Map();
function woodTexture(kind, maxAniso) {
  if (woodCache.has(kind)) return woodCache.get(kind);
  const tone = WOODS[kind] || WOODS.carvalho;
  const dark = hexRgb(tone.dark); const light = hexRgb(tone.light);
  const el = canvas(1024, 512, (ctx, W, H) => {
    const img = ctx.createImageData(W, H);
    const planks = 5; const ph = H / planks;
    for (let y = 0; y < H; y++) {
      const plank = Math.floor(y / ph); const local = y - plank * ph;
      const seed = plank * 31.7;
      const plankTone = 0.86 + hash2(plank, 3) * 0.24;
      const joint = Math.floor(hash2(plank, 9) * W);
      for (let x = 0; x < W; x++) {
        const n = fbm((x + seed * 40) * 0.004, y * 0.045, 3);
        const ring = 0.5 + 0.5 * Math.sin((local * 0.75 + n * 34 + seed) * 0.62 + Math.sin(x * 0.0045 + seed) * 2.4);
        const fiber = noise(x * 0.55, y * 0.09 + seed);
        let t = 0.5 * ring + 0.34 * n + 0.16 * fiber;
        t = Math.min(1, Math.max(0, t));
        let f = plankTone;
        if (local < 2.2 || local > ph - 1.2) f *= 0.45;
        const dj = Math.abs(x - joint);
        if (dj < 1.5) f *= 0.5;
        const i = (y * W + x) * 4;
        img.data[i] = Math.min(255, mix(dark[0], light[0], t) * f);
        img.data[i + 1] = Math.min(255, mix(dark[1], light[1], t) * f);
        img.data[i + 2] = Math.min(255, mix(dark[2], light[2], t) * f);
        img.data[i + 3] = 255;
      }
    }
    ctx.putImageData(img, 0, 0);
  });
  const tex = new THREE.CanvasTexture(el);
  tex.colorSpace = THREE.SRGBColorSpace;
  tex.wrapS = tex.wrapT = THREE.RepeatWrapping;
  tex.anisotropy = maxAniso;
  woodCache.set(kind, tex);
  return tex;
}

/* ---------- Céu ---------- */
function skyDome(top, horizon, bottom, sunDir, sunColor, sunSize = 600) {
  const material = new THREE.ShaderMaterial({
    side: THREE.BackSide, depthWrite: false, fog: false,
    uniforms: { top: { value: new THREE.Color(top) }, horizon: { value: new THREE.Color(horizon) }, bottom: { value: new THREE.Color(bottom) },
      sunDir: { value: sunDir.clone().normalize() }, sunColor: { value: new THREE.Color(sunColor) }, sunSize: { value: sunSize } },
    vertexShader: 'varying vec3 vDir; void main(){ vDir = normalize(position); gl_Position = projectionMatrix * modelViewMatrix * vec4(position,1.0); }',
    fragmentShader: `uniform vec3 top; uniform vec3 horizon; uniform vec3 bottom; uniform vec3 sunDir; uniform vec3 sunColor; uniform float sunSize; varying vec3 vDir;
      void main(){ float h = vDir.y; vec3 col = h > 0.0 ? mix(horizon, top, pow(clamp(h,0.0,1.0), 0.55)) : mix(horizon, bottom, pow(clamp(-h,0.0,1.0), 0.35));
        float s = max(dot(normalize(vDir), sunDir), 0.0); col += sunColor * (pow(s, sunSize) * 3.0 + pow(s, 10.0) * 0.22);
        gl_FragColor = vec4(col, 1.0);
        #include <colorspace_fragment>
      }`,
  });
  const mesh = new THREE.Mesh(new THREE.SphereGeometry(800, 32, 16), material);
  mesh.renderOrder = -10;
  mesh.frustumCulled = false;
  return mesh;
}

function softTexture(size, draw) {
  const tex = new THREE.CanvasTexture(canvas(size, size, draw));
  tex.colorSpace = THREE.SRGBColorSpace;
  return tex;
}
const glowSprite = () => softTexture(64, (ctx, w) => {
  const g = ctx.createRadialGradient(w / 2, w / 2, 0, w / 2, w / 2, w / 2);
  g.addColorStop(0, 'rgba(255,255,255,1)'); g.addColorStop(0.35, 'rgba(255,255,255,.45)'); g.addColorStop(1, 'rgba(255,255,255,0)');
  ctx.fillStyle = g; ctx.fillRect(0, 0, w, w);
});
const cloudTexture = () => softTexture(256, (ctx, w) => {
  for (let i = 0; i < 26; i++) {
    const x = w * (0.2 + Math.random() * 0.6); const y = w * (0.4 + Math.random() * 0.25); const r = w * (0.1 + Math.random() * 0.16);
    const g = ctx.createRadialGradient(x, y, 0, x, y, r);
    g.addColorStop(0, 'rgba(255,255,255,.55)'); g.addColorStop(1, 'rgba(255,255,255,0)');
    ctx.fillStyle = g; ctx.fillRect(0, 0, w, w);
  }
});
function detailTexture(maxAniso) {
  const tex = new THREE.CanvasTexture(canvas(256, 256, (ctx, W, H) => {
    const img = ctx.createImageData(W, H);
    for (let y = 0; y < H; y++) for (let x = 0; x < W; x++) {
      // Ruído periódico (o ladrilho se repete sem emenda).
      const v = 0.5 * noise((x % W) / 8, (y % H) / 8) + 0.3 * noise(x / 3, y / 3) + 0.2 * hash2(x, y);
      const c = 200 + v * 55; const i = (y * W + x) * 4;
      img.data[i] = c; img.data[i + 1] = c; img.data[i + 2] = c; img.data[i + 3] = 255;
    }
    ctx.putImageData(img, 0, 0);
  }));
  tex.wrapS = tex.wrapT = THREE.RepeatWrapping;
  tex.repeat.set(90, 90);
  tex.anisotropy = maxAniso;
  tex.colorSpace = THREE.SRGBColorSpace;
  return tex;
}
/** Mapa de normais de ondas (soma de senos com frequências inteiras: o ladrilho fecha sem emenda). */
function waterNormals() {
  const N = 256;
  const waves = [[3, 1, 0.9, 0.2], [-2, 4, 0.6, 1.7], [5, -3, 0.35, 2.9], [1, 6, 0.3, 0.6], [-7, -2, 0.2, 4.1]];
  const tex = new THREE.CanvasTexture(canvas(N, N, (ctx) => {
    const img = ctx.createImageData(N, N);
    for (let y = 0; y < N; y++) for (let x = 0; x < N; x++) {
      let dx = 0; let dy = 0;
      waves.forEach(([kx, ky, a, p]) => { const ph = (2 * Math.PI * (kx * x + ky * y)) / N + p; const c = Math.cos(ph) * a; dx += c * kx; dy += c * ky; });
      const nx = -dx * 0.12; const ny = -dy * 0.12; const len = Math.hypot(nx, ny, 1);
      const i = (y * N + x) * 4;
      img.data[i] = (nx / len * 0.5 + 0.5) * 255; img.data[i + 1] = (ny / len * 0.5 + 0.5) * 255; img.data[i + 2] = (1 / len * 0.5 + 0.5) * 255; img.data[i + 3] = 255;
    }
    ctx.putImageData(img, 0, 0);
  }));
  tex.wrapS = tex.wrapT = THREE.RepeatWrapping;
  tex.repeat.set(60, 60);
  return tex;
}

/* ---------- Paisagens ---------- */
const BIOMES = {
  plains: {
    sky: ['#4f8fd6', '#d8e9f2', '#c8d6b0'], sun: new THREE.Vector3(-0.5, 0.75, -0.6), sunColor: '#fff3cf',
    hemi: ['#fff6e2', '#69803a', 1.15], key: ['#fff1d2', 2.0], fog: ['#d3e2e6', 140, 620], clouds: 16,
    height(x, z, r) { return smooth(60, 420, r) * fbm(x * 0.005, z * 0.005) * 70 + smooth(30, 90, r) * (fbm(x * 0.03, z * 0.03, 3) - 0.4) * 4; },
    color(h, r, n) { const g = Math.min(1, Math.max(0, n * 1.4 - 0.25)); return [mix(0.44, 0.74, g), mix(0.58, 0.66, g), mix(0.22, 0.3, g)]; },
    particles: { count: 160, color: '#fff4d0', size: 0.55, kind: 'pollen' },
  },
  island: {
    sky: ['#2f7fd8', '#bfe6f6', '#8fc6d4'], sun: new THREE.Vector3(0.4, 0.8, -0.5), sunColor: '#fffbe6',
    hemi: ['#eef8ff', '#3c7180', 1.2], key: ['#fff7ea', 2.1], fog: ['#bfe1ef', 160, 700], clouds: 12, water: { level: 1.2, color: '#1f7fa6', far: '#0e4f74' },
    height(x, z, r) {
      let h = 3.2 - smooth(34, 80, r) * 13 + (fbm(x * 0.04, z * 0.04, 3) - 0.5) * 1.6;
      // Ilhas ao longe.
      [[-260, -330, 70, 46], [310, -280, 55, 34], [40, -520, 90, 60], [-430, -120, 60, 30]].forEach(([ix, iz, ir, ih]) => {
        const d = Math.hypot(x - ix, z - iz); h += Math.max(0, 1 - d / ir) ** 1.6 * (ih + 12) * (0.8 + fbm(x * 0.03, z * 0.03) * 0.5);
      });
      return h;
    },
    color(h, r, n) { if (h > 9) return [mix(0.28, 0.36, n), mix(0.5, 0.6, n), 0.26]; return h > 1.0 ? [0.9, 0.83, 0.64] : [0.72, 0.66, 0.5]; },
    particles: { count: 70, color: '#ffffff', size: 0.45, kind: 'spray' },
  },
  swamp: {
    sky: ['#2f3a36', '#5f6e5c', '#3b4636'], sun: new THREE.Vector3(0.6, 0.35, -0.6), sunColor: '#d8e0b0', sunSize: 80,
    hemi: ['#a7b9a0', '#1c2a1d', 0.95], key: ['#dfe6c4', 1.35], fog: ['#566554', 45, 320], clouds: 0, water: { level: -0.2, color: '#273a2c', far: '#1a2a1f' },
    height(x, z, r) { return 0.6 + (fbm(x * 0.025, z * 0.025, 4) - 0.55) * 7 * smooth(26, 50, r) + smooth(120, 500, r) * fbm(x * 0.004, z * 0.004) * 40; },
    color(h, r, n) { return h > 0.4 ? [mix(0.2, 0.3, n), mix(0.22, 0.3, n), mix(0.12, 0.15, n)] : [0.17, 0.17, 0.11]; },
    particles: { count: 120, color: '#d8ff7a', size: 0.7, kind: 'firefly' },
  },
  mountain: {
    sky: ['#3a2a4a', '#e0946a', '#6a4434'], sun: new THREE.Vector3(0.75, 0.22, -0.6), sunColor: '#ffb47a', sunSize: 220,
    hemi: ['#ffd8bc', '#3a2418', 1.05], key: ['#ffc08a', 2.0], fog: ['#b67c5e', 90, 680], clouds: 6,
    height(x, z, r) {
      const ridge = 1 - Math.abs(fbm(x * 0.005, z * 0.005, 5) * 2 - 1);
      let h = smooth(230, 650, r) * ridge ** 2 * 230 + smooth(28, 90, r) * fbm(x * 0.04, z * 0.04, 3) * 9 + smooth(80, 260, r) * fbm(x * 0.012, z * 0.012) * 18;
      const d = Math.hypot(x + 170, z + 300);
      const cone = Math.max(0, 1 - d / 200);
      h = Math.max(h, cone ** 1.3 * 175 - (d < 28 ? (28 - d) * 1.4 : 0));
      return h;
    },
    color(h, r, n) { if (h > 150) return [mix(0.86, 0.95, n), mix(0.82, 0.9, n), mix(0.8, 0.88, n)]; if (h > 95) return [0.3, 0.22, 0.2]; return [mix(0.36, 0.52, n), mix(0.2, 0.27, n), mix(0.15, 0.17, n)]; },
    particles: { count: 200, color: '#ff8a3a', size: 0.5, kind: 'ember' },
    volcano: { x: -170, z: -300 },
  },
  forest: {
    sky: ['#5b8fbf', '#aac3a2', '#4f6a3c'], sun: new THREE.Vector3(-0.35, 0.7, -0.7), sunColor: '#fff0c2',
    hemi: ['#ecf6dc', '#2a3a1c', 1.1], key: ['#fff1cc', 1.75], fog: ['#8aa680', 70, 380], clouds: 8,
    height(x, z, r) { return smooth(40, 380, r) * fbm(x * 0.007, z * 0.007) * 60 + smooth(26, 60, r) * fbm(x * 0.06, z * 0.06, 3) * 3; },
    color(h, r, n) { return [mix(0.18, 0.3, n), mix(0.3, 0.42, n), mix(0.12, 0.16, n)]; },
    particles: { count: 130, color: '#c7d96a', size: 0.6, kind: 'leaf' },
  },
};

/**
 * Cria o mundo em volta da mesa. Devolve: setEnvironment(kind), setWood(kind), fitTable({minX,maxX,minZ,maxZ}),
 * update(dt, time, camera) e as luzes (key projeta as sombras da mesa).
 */
export function createWorld(scene, renderer, { environment = 'plains', wood = 'carvalho', shadows = true } = {}) {
  const maxAniso = renderer.capabilities.getMaxAnisotropy();
  const hemi = new THREE.HemisphereLight(0xffffff, 0x333333, 1);
  const key = new THREE.DirectionalLight(0xffffff, 2);
  key.position.set(-14, 40, 18);
  key.castShadow = shadows;
  key.shadow.mapSize.set(2048, 2048);
  key.shadow.bias = -0.0004;
  key.shadow.normalBias = 0.02;
  key.shadow.radius = 4;
  scene.add(hemi, key, key.target);
  const fill = new THREE.DirectionalLight(0xffffff, 0.35);
  fill.position.set(18, 20, 30);
  scene.add(fill);
  scene.fog = new THREE.Fog(0xcccccc, 100, 600);
  const glowTex = glowSprite();

  /* ---------- Mesa de madeira ---------- */
  const table = new THREE.Group();
  scene.add(table);
  let woodKind = wood;
  const topMaterial = new THREE.MeshPhysicalMaterial({ map: woodTexture(woodKind, maxAniso), roughness: 0.42, metalness: 0, clearcoat: 0.55, clearcoatRoughness: 0.35 });
  const legMaterial = new THREE.MeshStandardMaterial({ map: woodTexture(woodKind, maxAniso), roughness: 0.6 });
  let tableKey = '';
  let tableBox = { minX: -18.2, maxX: 18.2, minZ: -12.2, maxZ: 12.2 };
  function buildTable(box) {
    const key2 = [box.minX, box.maxX, box.minZ, box.maxZ].map((v) => v.toFixed(1)).join(',');
    if (key2 === tableKey) return;
    tableKey = key2;
    tableBox = box;
    table.children.slice().forEach((child) => { table.remove(child); child.geometry?.dispose(); });
    const w = box.maxX - box.minX; const d = box.maxZ - box.minZ;
    const cx = (box.minX + box.maxX) / 2; const cz = (box.minZ + box.maxZ) / 2;
    const radius = Math.min(6, Math.min(w, d) * 0.18);
    const shape = new THREE.Shape();
    const hw = w / 2; const hd = d / 2;
    shape.moveTo(-hw + radius, -hd); shape.lineTo(hw - radius, -hd); shape.quadraticCurveTo(hw, -hd, hw, -hd + radius);
    shape.lineTo(hw, hd - radius); shape.quadraticCurveTo(hw, hd, hw - radius, hd); shape.lineTo(-hw + radius, hd);
    shape.quadraticCurveTo(-hw, hd, -hw, hd - radius); shape.lineTo(-hw, -hd + radius); shape.quadraticCurveTo(-hw, -hd, -hw + radius, -hd);
    const thickness = 1.3;
    const geo = new THREE.ExtrudeGeometry(shape, { depth: thickness, bevelEnabled: true, bevelThickness: 0.35, bevelSize: 0.35, bevelSegments: 3, curveSegments: 10 });
    geo.rotateX(-Math.PI / 2);
    // Mesmos UVs em unidades da cena: a madeira não estica quando a mesa cresce.
    const uv = geo.attributes.uv;
    for (let i = 0; i < uv.count; i++) uv.setXY(i, uv.getX(i) / 34, uv.getY(i) / 34);
    const top = new THREE.Mesh(geo, topMaterial);
    top.position.set(cx, -thickness - 0.36, cz);
    top.receiveShadow = true; top.castShadow = true;
    table.add(top);
    // Saia (moldura sob o tampo) e quatro pés torneados.
    const apronH = 2.2;
    const inset = Math.min(5, Math.min(w, d) * 0.16);
    const apron = new THREE.Mesh(new THREE.BoxGeometry(w - inset * 2, apronH, d - inset * 2), legMaterial);
    apron.position.set(cx, -thickness - 0.36 - apronH / 2, cz);
    apron.castShadow = true;
    table.add(apron);
    const legH = TABLE_HEIGHT - thickness - 0.36;
    const legGeo = new THREE.CylinderGeometry(1.05, 0.72, legH, 14);
    [[-1, -1], [1, -1], [-1, 1], [1, 1]].forEach(([sx, sz]) => {
      const leg = new THREE.Mesh(legGeo, legMaterial);
      leg.position.set(cx + sx * (hw - inset - 0.6), -thickness - 0.36 - legH / 2, cz + sz * (hd - inset - 0.6));
      leg.castShadow = true;
      table.add(leg);
      const foot = new THREE.Mesh(new THREE.CylinderGeometry(1.25, 1.35, 0.6, 14), legMaterial);
      foot.position.set(leg.position.x, GROUND + 0.3, leg.position.z);
      table.add(foot);
    });
    // A sombra da luz principal cobre a mesa inteira.
    const span = Math.max(w, d) / 2 + 6;
    Object.assign(key.shadow.camera, { left: -span, right: span, top: span, bottom: -span, near: 1, far: 140 });
    key.target.position.set(cx, 0, cz);
    key.position.set(cx - 22, 48, cz + 26);
    key.shadow.camera.updateProjectionMatrix();
  }
  function setWood(kind) {
    if (!WOODS[kind]) kind = 'carvalho';
    woodKind = kind;
    const tex = woodTexture(kind, maxAniso);
    topMaterial.map = tex; topMaterial.needsUpdate = true;
    legMaterial.map = tex; legMaterial.needsUpdate = true;
  }
  buildTable(tableBox);

  /* ---------- Paisagem ---------- */
  let land = null;
  let current = null;
  const detail = detailTexture(maxAniso);

  function buildLand(kind) {
    const biome = BIOMES[kind] || BIOMES.plains;
    const group = new THREE.Group();
    const anim = { water: null, particles: null, clouds: [], lava: null };
    reseed(kind.length * 977 + 13);
    group.add(skyDome(biome.sky[0], biome.sky[1], biome.sky[2], biome.sun, biome.sunColor, biome.sunSize || 600));
    anim.sky = group.children[0];

    // Terreno: plano com 180×180 vértices, deslocado pelo ruído; plano perto da mesa.
    const size = 1500; const seg = 180;
    const geo = new THREE.PlaneGeometry(size, size, seg, seg);
    geo.rotateX(-Math.PI / 2);
    const pos = geo.attributes.position;
    const colors = new Float32Array(pos.count * 3);
    for (let i = 0; i < pos.count; i++) {
      const x = pos.getX(i); const z = pos.getZ(i); const r = Math.hypot(x, z);
      const h = biome.height(x, z, r);
      pos.setY(i, GROUND + h);
      const n = fbm(x * 0.02 + 40, z * 0.02 - 13, 3);
      const [cr, cg, cb] = biome.color(h, r, n);
      colors[i * 3] = cr ** 2.2; colors[i * 3 + 1] = cg ** 2.2; colors[i * 3 + 2] = cb ** 2.2;
    }
    geo.setAttribute('color', new THREE.BufferAttribute(colors, 3));
    geo.computeVertexNormals();
    const ground = new THREE.Mesh(geo, new THREE.MeshStandardMaterial({ vertexColors: true, map: detail, roughness: 0.96, metalness: 0, flatShading: kind === 'mountain' }));
    ground.receiveShadow = true;
    group.add(ground);
    const heightAt = (x, z) => GROUND + biome.height(x, z, Math.hypot(x, z));

    if (biome.water) {
      const normals = waterNormals();
      const water = new THREE.Mesh(new THREE.PlaneGeometry(size, size, 1, 1), new THREE.MeshStandardMaterial({ color: biome.water.color, roughness: kind === 'swamp' ? 0.35 : 0.12, metalness: kind === 'swamp' ? 0.1 : 0.35, normalMap: normals, normalScale: new THREE.Vector2(0.6, 0.6), transparent: true, opacity: kind === 'swamp' ? 0.92 : 0.88 }));
      water.rotation.x = -Math.PI / 2;
      water.position.y = GROUND + biome.water.level;
      water.receiveShadow = true;
      group.add(water);
      anim.water = normals;
    }

    const instanced = (geometry, material, count, place, cast = true) => {
      const mesh = new THREE.InstancedMesh(geometry, material, count);
      const m = new THREE.Matrix4(); const q = new THREE.Quaternion(); const s = new THREE.Vector3(); const p = new THREE.Vector3(); const e = new THREE.Euler();
      const color = new THREE.Color();
      let placed = 0;
      for (let i = 0; i < count; i++) {
        const spot = place(i, color);
        if (!spot) continue;
        p.set(spot.x, spot.y, spot.z);
        e.set(spot.rx || 0, spot.ry || 0, spot.rz || 0, spot.order || 'XYZ');
        q.setFromEuler(e);
        s.set(spot.sx ?? spot.s ?? 1, spot.sy ?? spot.s ?? 1, spot.sz ?? spot.s ?? 1);
        m.compose(p, q, s);
        mesh.setMatrixAt(placed, m);
        if (spot.color) mesh.setColorAt(placed, color);
        placed++;
      }
      mesh.count = placed;
      mesh.castShadow = cast; mesh.receiveShadow = true;
      mesh.instanceMatrix.needsUpdate = true;
      if (mesh.instanceColor) mesh.instanceColor.needsUpdate = true;
      group.add(mesh);
      return mesh;
    };
    /** Um ponto ao acaso num anel em volta da mesa (fora do tampo). */
    const ring = (min, max) => { const a = rand() * Math.PI * 2; const r = min + (max - min) * Math.sqrt(rand()); return { x: Math.cos(a) * r, z: Math.sin(a) * r, r }; };
    const clear = (spot) => !(Math.abs(spot.x - (tableBox.minX + tableBox.maxX) / 2) < (tableBox.maxX - tableBox.minX) / 2 + 3 && Math.abs(spot.z - (tableBox.minZ + tableBox.maxZ) / 2) < (tableBox.maxZ - tableBox.minZ) / 2 + 3);

    if (kind === 'plains' || kind === 'forest') {
      // Tufos de capim.
      const grassMat = new THREE.MeshStandardMaterial({ roughness: 0.9, flatShading: true });
      instanced(new THREE.ConeGeometry(0.3, 2.2, 3), grassMat, kind === 'plains' ? 3800 : 1800, (i, color) => {
        const spot = ring(22, kind === 'plains' ? 170 : 120);
        if (!clear(spot)) return null;
        const t = rand();
        color.setRGB(kind === 'plains' ? mix(0.55, 0.86, t) : mix(0.2, 0.36, t), kind === 'plains' ? mix(0.6, 0.72, t) : mix(0.42, 0.52, t), kind === 'plains' ? mix(0.22, 0.3, t) : 0.16, THREE.SRGBColorSpace);
        return { x: spot.x, y: heightAt(spot.x, spot.z) + 0.8, z: spot.z, rx: (rand() - 0.5) * 0.5, rz: (rand() - 0.5) * 0.5, s: 0.7 + rand() * 0.9, color: true };
      }, false);
    }
    if (kind === 'plains') {
      const flowerMat = new THREE.MeshStandardMaterial({ roughness: 0.7, emissive: 0x222222 });
      const palette = ['#ffffff', '#ffe066', '#c9a2ff', '#ff9fb0'];
      instanced(new THREE.IcosahedronGeometry(0.32, 0), flowerMat, 600, (i, color) => {
        const spot = ring(24, 140); if (!clear(spot)) return null;
        color.set(palette[Math.floor(rand() * palette.length)]);
        return { x: spot.x, y: heightAt(spot.x, spot.z) + 1.4, z: spot.z, s: 0.8 + rand() * 0.6, color: true };
      }, false);
      // Árvores sozinhas no campo.
      const trunks = []; reseed(77);
      for (let i = 0; i < 46; i++) { const spot = ring(70, 380); trunks.push({ ...spot, y: heightAt(spot.x, spot.z), s: 0.8 + rand() * 0.9 }); }
      instanced(new THREE.CylinderGeometry(0.6, 0.9, 7, 6), new THREE.MeshStandardMaterial({ color: '#5a4026', roughness: 1 }), trunks.length, (i) => ({ x: trunks[i].x, y: trunks[i].y + 3.5 * trunks[i].s, z: trunks[i].z, s: trunks[i].s }));
      instanced(new THREE.IcosahedronGeometry(4.6, 1), new THREE.MeshStandardMaterial({ color: '#5f8a34', roughness: 0.9, flatShading: true }), trunks.length, (i) => ({ x: trunks[i].x, y: trunks[i].y + 9 * trunks[i].s, z: trunks[i].z, s: trunks[i].s, sy: trunks[i].s * 0.85 }));
    }
    if (kind === 'forest') {
      const trees = []; reseed(311);
      for (let i = 0; i < 900; i++) {
        const spot = ring(30, 460);
        if (!clear(spot)) continue;
        if (spot.r < 60 && rand() < 0.5) continue;
        trees.push({ ...spot, y: heightAt(spot.x, spot.z), s: 0.8 + rand() * 1.1 + smooth(60, 300, spot.r) * 0.8, ry: rand() * 6 });
      }
      instanced(new THREE.CylinderGeometry(0.55, 0.85, 6, 6), new THREE.MeshStandardMaterial({ color: '#4a3220', roughness: 1 }), trees.length, (i) => ({ x: trees[i].x, y: trees[i].y + 3 * trees[i].s, z: trees[i].z, s: trees[i].s }));
      const pine = new THREE.MeshStandardMaterial({ roughness: 0.9, flatShading: true });
      const shadeOf = (i, color) => { const t = hash2(i, 4); color.setRGB(mix(0.1, 0.2, t), mix(0.26, 0.4, t), mix(0.12, 0.18, t), THREE.SRGBColorSpace); };
      instanced(new THREE.ConeGeometry(4.2, 9, 7), pine, trees.length, (i, color) => { shadeOf(i, color); return { x: trees[i].x, y: trees[i].y + 9 * trees[i].s, z: trees[i].z, s: trees[i].s, ry: trees[i].ry, color: true }; });
      instanced(new THREE.ConeGeometry(3, 7.5, 7), pine, trees.length, (i, color) => { shadeOf(i + 9, color); return { x: trees[i].x, y: trees[i].y + 14.5 * trees[i].s, z: trees[i].z, s: trees[i].s, ry: trees[i].ry, color: true }; });
      instanced(new THREE.DodecahedronGeometry(1.2, 0), new THREE.MeshStandardMaterial({ color: '#6c6a5c', roughness: 1, flatShading: true }), 140, () => { const spot = ring(26, 120); if (!clear(spot)) return null; return { x: spot.x, y: heightAt(spot.x, spot.z) + 0.3, z: spot.z, s: 0.5 + rand() * 1.4, ry: rand() * 6, rx: rand() }; });
    }
    if (kind === 'swamp') {
      const trees = []; reseed(51);
      for (let i = 0; i < 220; i++) { const spot = ring(30, 330); if (!clear(spot)) continue; trees.push({ ...spot, y: heightAt(spot.x, spot.z), s: 0.8 + rand() * 1.0, ry: rand() * 6, lean: (rand() - 0.5) * 0.25 }); }
      const bark = new THREE.MeshStandardMaterial({ color: '#2c2a22', roughness: 1, flatShading: true });
      instanced(new THREE.CylinderGeometry(0.35, 1.0, 16, 6), bark, trees.length, (i) => ({ x: trees[i].x, y: trees[i].y + 8 * trees[i].s, z: trees[i].z, s: trees[i].s, rz: trees[i].lean, ry: trees[i].ry }));
      const branch = new THREE.CylinderGeometry(0.12, 0.32, 7, 5);
      [[0.9, 11], [-1.1, 8.5], [2.2, 13.5]].forEach(([tilt, height], k) => instanced(branch, bark, trees.length, (i) => {
        const t = trees[i]; const a = t.ry + k * 2.1;
        return { x: t.x + Math.cos(a) * 2.2 * t.s, y: t.y + height * t.s, z: t.z + Math.sin(a) * 2.2 * t.s, rz: tilt * 0.9, ry: a, s: t.s };
      }));
      instanced(new THREE.ConeGeometry(0.1, 3.4, 3), new THREE.MeshStandardMaterial({ color: '#56603a', roughness: 1 }), 2200, (i, color) => {
        const spot = ring(22, 110); if (!clear(spot)) return null;
        color.setRGB(mix(0.28, 0.42, rand()), mix(0.32, 0.42, rand()), 0.2, THREE.SRGBColorSpace);
        return { x: spot.x, y: heightAt(spot.x, spot.z) + 1.4, z: spot.z, rx: (rand() - 0.5) * 0.4, rz: (rand() - 0.5) * 0.4, s: 0.7 + rand() * 0.8, color: true };
      }, false);
      instanced(new THREE.CircleGeometry(0.9, 9).rotateX(-Math.PI / 2), new THREE.MeshStandardMaterial({ color: '#3f5a2c', roughness: 0.8 }), 260, () => {
        const spot = ring(24, 90); if (!clear(spot)) return null;
        if (heightAt(spot.x, spot.z) > GROUND + biome.water.level - 0.05) return null;
        return { x: spot.x, y: GROUND + biome.water.level + 0.03, z: spot.z, s: 0.6 + rand() * 0.9, ry: rand() * 6 };
      }, false);
    }
    if (kind === 'mountain') {
      const rock = new THREE.MeshStandardMaterial({ roughness: 1, flatShading: true });
      instanced(new THREE.DodecahedronGeometry(1.6, 0), rock, 420, (i, color) => {
        const spot = ring(24, 150); if (!clear(spot)) return null;
        const t = rand(); color.setRGB(mix(0.32, 0.5, t), mix(0.2, 0.28, t), mix(0.16, 0.2, t), THREE.SRGBColorSpace);
        const s = (0.3 + rand() * 1.2) * (1 + smooth(50, 150, spot.r) * 1.5);
        // Meio enterradas no chão: nada flutuando.
        return { x: spot.x, y: heightAt(spot.x, spot.z) + s * 0.15, z: spot.z, s, sy: s * (0.5 + rand() * 0.5), rx: rand() * 3, ry: rand() * 6, color: true };
      });
      // Lava no alto do vulcão.
      const v = biome.volcano;
      const lava = new THREE.Mesh(new THREE.CircleGeometry(26, 24).rotateX(-Math.PI / 2), new THREE.MeshBasicMaterial({ color: '#ff6a1a', fog: false }));
      lava.position.set(v.x, heightAt(v.x, v.z) + 2, v.z);
      group.add(lava);
      const halo = new THREE.Sprite(new THREE.SpriteMaterial({ map: glowTex, color: '#ff7a2a', transparent: true, opacity: 0.85, depthWrite: false, blending: THREE.AdditiveBlending, fog: false }));
      halo.position.set(v.x, heightAt(v.x, v.z) + 30, v.z); halo.scale.set(170, 140, 1);
      group.add(halo);
      anim.lava = halo;
    }
    if (kind === 'island') {
      const palms = []; reseed(23);
      for (let i = 0; i < 18; i++) { const spot = ring(38, 62); if (!clear(spot) || heightAt(spot.x, spot.z) < GROUND + biome.water.level + 0.3) continue; palms.push({ ...spot, y: heightAt(spot.x, spot.z), s: 0.9 + rand() * 0.5, lean: 0.12 + rand() * 0.2, ry: rand() * 6 }); }
      instanced(new THREE.CylinderGeometry(0.4, 0.7, 14, 6), new THREE.MeshStandardMaterial({ color: '#8a6a44', roughness: 1 }), palms.length, (i) => {
        const p = palms[i]; return { x: p.x - Math.sin(p.ry) * p.lean * 7 * p.s, y: p.y + 7 * p.s, z: p.z + Math.cos(p.ry) * p.lean * 7 * p.s, s: p.s, rx: Math.cos(p.ry) * p.lean, rz: Math.sin(p.ry) * p.lean };
      });
      // Folha: larga no meio e fina nas pontas, caída para fora a partir do topo do tronco.
      const frond = new THREE.ConeGeometry(0.9, 7, 4, 1);
      frond.rotateX(Math.PI / 2); frond.scale(1, 0.18, 1); frond.translate(0, 0, 3.4);
      const leaf = new THREE.MeshStandardMaterial({ color: '#2f7a34', roughness: 0.8, flatShading: true });
      for (let k = 0; k < 8; k++) {
        instanced(frond, leaf, palms.length, (i) => {
          const p = palms[i]; const topX = p.x - Math.sin(p.ry) * p.lean * 14 * p.s; const topZ = p.z + Math.cos(p.ry) * p.lean * 14 * p.s;
          return { x: topX, y: p.y + 13.8 * p.s, z: topZ, ry: (k / 8) * Math.PI * 2 + p.ry, rx: 0.55 + (k % 2) * 0.2, s: p.s, order: 'YXZ' };
        });
      }
      instanced(new THREE.DodecahedronGeometry(2, 0), new THREE.MeshStandardMaterial({ color: '#8b8a80', roughness: 1, flatShading: true }), 60, () => {
        const spot = ring(40, 90); return { x: spot.x, y: heightAt(spot.x, spot.z) + 0.6, z: spot.z, s: 0.6 + rand() * 2, ry: rand() * 6 };
      });
    }

    // Nuvens: só aparecem quando a câmera se inclina para o horizonte.
    if (biome.clouds) {
      const tex = cloudTexture();
      for (let i = 0; i < biome.clouds; i++) {
        const cloud = new THREE.Sprite(new THREE.SpriteMaterial({ map: tex, transparent: true, opacity: 0.85, depthWrite: false, fog: false, color: kind === 'mountain' ? '#ffc6a0' : '#ffffff' }));
        const a = rand() * Math.PI * 2; const r = 380 + rand() * 260;
        cloud.position.set(Math.cos(a) * r, 120 + rand() * 120, Math.sin(a) * r);
        const s = 160 + rand() * 160; cloud.scale.set(s, s * 0.5, 1);
        group.add(cloud);
        anim.clouds.push({ sprite: cloud, a, r, speed: 0.002 + rand() * 0.003 });
      }
    }

    // Partículas no ar, em volta da mesa.
    const pc = biome.particles;
    if (pc) {
      const n = pc.count;
      const positions = new Float32Array(n * 3);
      const seeds = [];
      for (let i = 0; i < n; i++) {
        const spot = ring(pc.kind === 'firefly' ? 16 : 20, pc.kind === 'ember' ? 120 : 80);
        const y = pc.kind === 'ember' ? GROUND + rand() * 60 : GROUND + 2 + rand() * (pc.kind === 'spray' ? 6 : 34);
        seeds.push({ x: spot.x, y, z: spot.z, p: rand() * 100, v: 0.5 + rand() });
        positions.set([spot.x, y, spot.z], i * 3);
      }
      const geo2 = new THREE.BufferGeometry();
      geo2.setAttribute('position', new THREE.BufferAttribute(positions, 3));
      const points = new THREE.Points(geo2, new THREE.PointsMaterial({ color: pc.color, size: pc.size, map: glowTex, transparent: true, depthWrite: false, blending: THREE.AdditiveBlending, sizeAttenuation: true, opacity: 0.9 }));
      points.frustumCulled = false;
      group.add(points);
      anim.particles = { points, seeds, kind: pc.kind };
    }

    // Luz e névoa do lugar.
    const [hs, hg, hi] = biome.hemi;
    const [kc, ki] = biome.key;
    const [fc, fn, ff] = biome.fog;
    return { group, anim, box: { ...tableBox }, light: { hs, hg, hi, kc, ki, fc, fn, ff, sun: biome.sun } };
  }

  function disposeGroup(group) {
    group.traverse((object) => {
      object.geometry?.dispose?.();
      const materials = Array.isArray(object.material) ? object.material : object.material ? [object.material] : [];
      materials.forEach((material) => { if (material.map && material.map !== detail && material.map !== glowTex && !woodCache.has(material.map)) material.map.dispose?.(); material.normalMap?.dispose?.(); material.dispose?.(); });
    });
  }

  function setEnvironment(kind) {
    if (!BIOMES[kind]) kind = 'plains';
    if (kind === current) return;
    current = kind;
    if (land) { scene.remove(land.group); disposeGroup(land.group); }
    land = buildLand(kind);
    scene.add(land.group);
    const l = land.light;
    hemi.color.set(l.hs); hemi.groundColor.set(l.hg); hemi.intensity = l.hi;
    key.color.set(l.kc); key.intensity = l.ki;
    fill.color.set(l.hs);
    scene.fog.color.set(l.fc); scene.fog.near = l.fn; scene.fog.far = l.ff;
  }
  setEnvironment(environment);

  function update(dt, time, camera) {
    if (!land) return;
    const a = land.anim;
    if (a.sky && camera) a.sky.position.copy(camera.position);
    if (a.water) { a.water.offset.x = time * 0.006; a.water.offset.y = time * 0.004; }
    a.clouds.forEach((c) => { c.a += c.speed * dt; c.sprite.position.x = Math.cos(c.a) * c.r; c.sprite.position.z = Math.sin(c.a) * c.r; });
    if (a.lava) a.lava.material.opacity = 0.7 + Math.sin(time * 1.7) * 0.15;
    const pp = a.particles;
    if (pp) {
      const arr = pp.points.geometry.attributes.position.array;
      pp.seeds.forEach((s, i) => {
        let x = s.x; let y = s.y; let z = s.z;
        const t = time * s.v + s.p;
        if (pp.kind === 'pollen') { x += Math.sin(t * 0.3) * 3; y += Math.sin(t * 0.7) * 1.2; z += Math.cos(t * 0.25) * 3; }
        else if (pp.kind === 'firefly') { x += Math.sin(t * 0.9) * 2.4; y += Math.sin(t * 1.3) * 1.5; z += Math.cos(t * 0.7) * 2.4; }
        else if (pp.kind === 'ember') { y = GROUND + ((s.y - GROUND + time * 3 * s.v) % 64); x += Math.sin(t) * 1.5; z += Math.cos(t * 0.8) * 1.5; }
        else if (pp.kind === 'leaf') { y = GROUND + 34 - ((34 - (s.y - GROUND) + time * 1.6 * s.v) % 34); x += Math.sin(t * 1.2) * 2.5; z += Math.cos(t * 0.9) * 2; }
        else { y += Math.abs(Math.sin(t * 0.8)) * 1.2; }
        arr[i * 3] = x; arr[i * 3 + 1] = y; arr[i * 3 + 2] = z;
      });
      pp.points.geometry.attributes.position.needsUpdate = true;
      if (pp.kind === 'firefly') pp.points.material.opacity = 0.55 + Math.sin(time * 2.2) * 0.35;
    }
  }

  return {
    setEnvironment,
    environment: () => current,
    setWood,
    wood: () => woodKind,
    /** A mesa cresce para caber todos os tapetes (x/z em unidades da cena). */
    fitTable(box) {
      const margin = 3.2;
      const next = { minX: box.minX - margin, maxX: box.maxX + margin, minZ: box.minZ - margin, maxZ: box.maxZ + margin };
      buildTable(next);
      // Com a mesa maior que a clareira da paisagem, árvores e pedras ficariam atravessando o tampo: refaz a paisagem.
      const cleared = land?.box;
      if (land && current && cleared && (next.minX < cleared.minX || next.maxX > cleared.maxX || next.minZ < cleared.minZ || next.maxZ > cleared.maxZ)) { const kind = current; current = null; setEnvironment(kind); }
    },
    update,
    key,
    hemi,
  };
}
