/* Sons da mesa do Deckarium, sintetizados na hora com Web Audio (nenhum arquivo de áudio):
   carta entrando (terreno, criatura, mágica no tom da cor, comandante, ficha), compra, embaralhar, virar,
   cada etapa e cada fase do turno, começo de turno, ataque, dano, vida, cemitério, exílio, dados e marcadores.
   Também o som ambiente de cada paisagem: vento e pássaros nas planícies, ondas nas ilhas, sapos e grilos nos
   pântanos, ventania e trovoadas nas montanhas, folhas e pássaros na floresta.
   O navegador só libera o áudio depois de um clique ou tecla; até lá, os sons são ignorados. */
const STORE = 'deckarium-audio-v1';
const NOTE = (n) => 440 * 2 ** ((n - 69) / 12);

export function createAudio() {
  const settings = { sfx: true, volume: 0.7, ambient: true, ambientVolume: 0.45 };
  try { Object.assign(settings, JSON.parse(localStorage.getItem(STORE) || '{}')); } catch { /* sem armazenamento: padrão */ }
  let ctx = null; let master = null; let sfxBus = null; let ambBus = null; let wet = null; let noiseBuf = null;
  let ambientKind = null; let ambient = null;
  let lastPlay = new Map();

  function makeNoise(seconds = 3) {
    const buffer = ctx.createBuffer(1, ctx.sampleRate * seconds, ctx.sampleRate);
    const data = buffer.getChannelData(0);
    for (let i = 0; i < data.length; i++) data[i] = Math.random() * 2 - 1;
    return buffer;
  }
  function makeReverb() {
    const len = ctx.sampleRate * 1.8;
    const ir = ctx.createBuffer(2, len, ctx.sampleRate);
    for (let ch = 0; ch < 2; ch++) { const d = ir.getChannelData(ch); for (let i = 0; i < len; i++) d[i] = (Math.random() * 2 - 1) * (1 - i / len) ** 2.6; }
    const conv = ctx.createConvolver(); conv.buffer = ir;
    const gain = ctx.createGain(); gain.gain.value = 0.32;
    conv.connect(gain); gain.connect(sfxBus);
    return conv;
  }
  function applyVolumes() {
    if (!ctx) return;
    const now = ctx.currentTime;
    sfxBus.gain.setTargetAtTime(settings.sfx ? settings.volume : 0, now, 0.05);
    ambBus.gain.setTargetAtTime(settings.ambient ? settings.ambientVolume * 0.7 : 0, now, 0.4);
  }
  function ensure() {
    if (!ctx) {
      const AC = window.AudioContext || window.webkitAudioContext;
      if (!AC) return null;
      ctx = new AC();
      master = ctx.createDynamicsCompressor();
      master.threshold.value = -14; master.ratio.value = 4;
      master.connect(ctx.destination);
      sfxBus = ctx.createGain(); ambBus = ctx.createGain();
      sfxBus.connect(master); ambBus.connect(master);
      noiseBuf = makeNoise();
      wet = makeReverb();
      applyVolumes();
      if (ambientKind) startAmbient(ambientKind);
    }
    if (ctx.state === 'suspended') ctx.resume().catch(() => {});
    return ctx;
  }
  const unlock = () => { ensure(); };
  window.addEventListener('pointerdown', unlock, { passive: true });
  window.addEventListener('keydown', unlock);
  const ready = () => ctx && ctx.state === 'running';

  /* ---------- Peças ---------- */
  function tone({ freq = 440, freqEnd = null, type = 'sine', at = 0, dur = 0.3, gain = 0.3, attack = 0.005, dest = null, reverb = 0, detune = 0 }) {
    const t = ctx.currentTime + at;
    const osc = ctx.createOscillator(); osc.type = type; osc.frequency.setValueAtTime(freq, t); osc.detune.value = detune;
    if (freqEnd) osc.frequency.exponentialRampToValueAtTime(Math.max(20, freqEnd), t + dur);
    const g = ctx.createGain();
    g.gain.setValueAtTime(0.0001, t); g.gain.exponentialRampToValueAtTime(gain, t + attack); g.gain.exponentialRampToValueAtTime(0.0001, t + dur);
    osc.connect(g); g.connect(dest || sfxBus);
    if (reverb) { const r = ctx.createGain(); r.gain.value = reverb; g.connect(r); r.connect(wet); }
    osc.start(t); osc.stop(t + dur + 0.05);
  }
  function hiss({ at = 0, dur = 0.1, gain = 0.3, type = 'bandpass', freq = 2000, freqEnd = null, q = 1, attack = 0.003, dest = null, reverb = 0 }) {
    const t = ctx.currentTime + at;
    const src = ctx.createBufferSource(); src.buffer = noiseBuf;
    const f = ctx.createBiquadFilter(); f.type = type; f.frequency.setValueAtTime(freq, t); f.Q.value = q;
    if (freqEnd) f.frequency.exponentialRampToValueAtTime(freqEnd, t + dur);
    const g = ctx.createGain();
    g.gain.setValueAtTime(0.0001, t); g.gain.exponentialRampToValueAtTime(gain, t + attack); g.gain.exponentialRampToValueAtTime(0.0001, t + dur);
    src.connect(f); f.connect(g); g.connect(dest || sfxBus);
    if (reverb) { const r = ctx.createGain(); r.gain.value = reverb; g.connect(r); r.connect(wet); }
    src.start(t, Math.random() * 2); src.stop(t + dur + 0.05);
  }
  const slap = (at = 0, gain = 0.5) => { hiss({ at, dur: 0.075, gain, freq: 2600, freqEnd: 1100, q: 0.7 }); tone({ at, freq: 150, freqEnd: 70, dur: 0.1, gain: gain * 0.6 }); };
  // Escala de cada cor para o brilho da mágica (notas MIDI).
  const COLOR_NOTES = { W: [84, 88, 91, 96], U: [74, 81, 86, 88], B: [57, 60, 64, 63], R: [76, 80, 83, 88], G: [67, 71, 74, 79], C: [72, 79, 84, 91] };
  const COLOR_WAVE = { W: 'sine', U: 'sine', B: 'triangle', R: 'square', G: 'triangle', C: 'sine' };
  function chime(colors = ['C'], at = 0, gain = 0.12) {
    const list = colors.length ? colors.slice(0, 2) : ['C'];
    list.forEach((c, k) => (COLOR_NOTES[c] || COLOR_NOTES.C).forEach((n, i) => tone({ at: at + i * 0.055 + k * 0.03, freq: NOTE(n), type: COLOR_WAVE[c] || 'sine', dur: 0.55, gain: COLOR_WAVE[c] === 'square' ? gain * 0.35 : gain, reverb: 0.6 })));
  }

  const SOUNDS = {
    card: () => slap(),
    land: () => { tone({ freq: 96, freqEnd: 46, dur: 0.28, gain: 0.6 }); hiss({ dur: 0.14, gain: 0.32, type: 'lowpass', freq: 900 }); hiss({ at: 0.01, dur: 0.05, gain: 0.2, freq: 2600 }); },
    creature: ({ colors } = {}) => { slap(0, 0.45); tone({ at: 0.02, freq: 190, freqEnd: 85, type: 'triangle', dur: 0.24, gain: 0.3 }); chime(colors, 0.06, 0.07); },
    spell: ({ colors } = {}) => { slap(0, 0.35); chime(colors, 0.02, 0.12); hiss({ at: 0.02, dur: 0.5, gain: 0.07, type: 'highpass', freq: 5000, freqEnd: 9000, reverb: 0.5 }); },
    commander: ({ colors } = {}) => { slap(0, 0.5); [48, 55, 60, 64, 67].forEach((n, i) => tone({ at: 0.03 + i * 0.07, freq: NOTE(n + 12), type: 'triangle', dur: 1.6, gain: 0.12, reverb: 0.9 })); chime(colors, 0.4, 0.06); },
    token: () => { tone({ freq: 520, freqEnd: 980, dur: 0.09, gain: 0.22, type: 'triangle' }); tone({ at: 0.06, freq: 1300, dur: 0.12, gain: 0.08 }); },
    draw: () => hiss({ dur: 0.16, gain: 0.22, type: 'bandpass', freq: 2500, freqEnd: 5500, q: 0.9 }),
    shuffle: () => { for (let i = 0; i < 16; i++) hiss({ at: i * 0.028 + Math.random() * 0.01, dur: 0.03, gain: 0.18 + Math.random() * 0.1, freq: 2800 + Math.random() * 1500, q: 1.4 }); hiss({ at: 0.48, dur: 0.12, gain: 0.25, freq: 1400 }); },
    tap: () => { hiss({ dur: 0.035, gain: 0.25, freq: 1900, q: 2 }); tone({ freq: 380, dur: 0.05, gain: 0.08, type: 'triangle' }); },
    untap: () => hiss({ dur: 0.18, gain: 0.16, freq: 1200, freqEnd: 3800, q: 1.2 }),
    // Etapa: um bloco de madeira afinado pela posição no turno; fase: um sino.
    step: ({ index = 0, quiet = false } = {}) => { const n = [60, 62, 64, 67, 69, 72, 74, 76, 79, 81, 84, 86][index % 12]; tone({ freq: NOTE(n), type: 'triangle', dur: 0.16, gain: quiet ? 0.08 : 0.2 }); hiss({ dur: 0.02, gain: quiet ? 0.05 : 0.12, freq: 3200, q: 3 }); },
    phase: ({ index = 0, quiet = false } = {}) => { const base = NOTE([72, 76, 79, 76, 67][index % 5]); [1, 2.76, 5.4].forEach((m, i) => tone({ freq: base * m, dur: 1.4 - i * 0.3, gain: (quiet ? 0.05 : 0.14) / (i + 1), reverb: 0.7 })); },
    turn: ({ mine = true } = {}) => { const f = mine ? 98 : 131; [1, 2.02, 2.98, 4.1].forEach((m, i) => tone({ freq: f * m, dur: 2.6 - i * 0.4, gain: (mine ? 0.3 : 0.14) / (i + 1), attack: 0.01, reverb: 0.8 })); hiss({ dur: 0.6, gain: mine ? 0.06 : 0.03, type: 'highpass', freq: 6000, reverb: 0.6 }); },
    attack: () => { hiss({ dur: 0.38, gain: 0.3, freq: 380, freqEnd: 1800, q: 0.9 }); tone({ at: 0.2, freq: 82, freqEnd: 50, dur: 0.3, gain: 0.5 }); },
    damage: ({ big = false } = {}) => { hiss({ dur: big ? 0.4 : 0.22, gain: 0.5, type: 'lowpass', freq: 1400, freqEnd: 300 }); tone({ freq: 130, freqEnd: 38, dur: big ? 0.55 : 0.35, gain: 0.6 }); },
    life: ({ up = true } = {}) => (up ? [72, 76, 79] : [67, 63, 60]).forEach((n, i) => tone({ at: i * 0.06, freq: NOTE(n + 12), type: up ? 'sine' : 'triangle', dur: 0.35, gain: 0.12, reverb: 0.4 })),
    die: () => { tone({ freq: 320, freqEnd: 70, type: 'triangle', dur: 0.55, gain: 0.22 }); hiss({ at: 0.05, dur: 0.5, gain: 0.12, type: 'lowpass', freq: 700, freqEnd: 200 }); },
    exile: () => { hiss({ dur: 0.7, gain: 0.16, type: 'highpass', freq: 1500, freqEnd: 7000, reverb: 0.8 }); tone({ freq: 1100, freqEnd: 2600, dur: 0.6, gain: 0.07, reverb: 0.8 }); },
    hand: () => hiss({ dur: 0.14, gain: 0.18, freq: 1800, freqEnd: 900 }),
    dice: () => { for (let i = 0; i < 7; i++) { hiss({ at: i * 0.06 + Math.random() * 0.03, dur: 0.035, gain: 0.25, freq: 1500 + Math.random() * 2500, q: 4 }); } },
    counter: () => { tone({ freq: 1850, dur: 0.18, gain: 0.1 }); tone({ freq: 2780, dur: 0.12, gain: 0.06 }); },
    reveal: () => { tone({ freq: NOTE(91), dur: 0.9, gain: 0.1, reverb: 0.9 }); tone({ at: 0.08, freq: NOTE(96), dur: 0.9, gain: 0.08, reverb: 0.9 }); },
    look: () => { hiss({ dur: 0.3, gain: 0.08, freq: 3000, freqEnd: 1500, q: 0.8 }); tone({ freq: NOTE(79), dur: 0.4, gain: 0.05, reverb: 0.6 }); },
    notify: () => { tone({ freq: 880, freqEnd: 1320, dur: 0.09, gain: 0.1 }); tone({ at: 0.09, freq: 1320, dur: 0.12, gain: 0.07 }); },
    warn: () => { tone({ freq: 233, dur: 0.12, gain: 0.12, type: 'triangle' }); tone({ at: 0.14, freq: 196, dur: 0.16, gain: 0.12, type: 'triangle' }); },
    block: () => { tone({ freq: 220, freqEnd: 180, dur: 0.18, gain: 0.25, type: 'square' }); hiss({ dur: 0.1, gain: 0.2, freq: 900 }); },
    win: () => [60, 64, 67, 72, 76, 79, 84].forEach((n, i) => tone({ at: i * 0.08, freq: NOTE(n + 12), type: 'triangle', dur: 0.6, gain: 0.12, reverb: 0.7 })),
  };

  /** Toca um som. Sons iguais disparados juntos (desvirar dez terrenos) tocam uma vez só. */
  function play(name, options = {}) {
    if (!settings.sfx || !ready()) return;
    const fn = SOUNDS[name];
    if (!fn) return;
    const now = performance.now();
    if (now - (lastPlay.get(name) || 0) < 45) return;
    lastPlay.set(name, now);
    try { fn(options); } catch { /* áudio indisponível agora */ }
  }

  /* ---------- Ambiente ---------- */
  function loopNoise(filterType, freq, q, gain, lfoRate = 0, lfoDepth = 0) {
    const src = ctx.createBufferSource(); src.buffer = noiseBuf; src.loop = true;
    const f = ctx.createBiquadFilter(); f.type = filterType; f.frequency.value = freq; f.Q.value = q;
    const g = ctx.createGain(); g.gain.value = gain;
    src.connect(f); f.connect(g); g.connect(ambBus);
    const nodes = [src];
    if (lfoRate) {
      const lfo = ctx.createOscillator(); lfo.frequency.value = lfoRate;
      const depth = ctx.createGain(); depth.gain.value = gain * lfoDepth;
      lfo.connect(depth); depth.connect(g.gain); lfo.start(); nodes.push(lfo);
    }
    src.start();
    return nodes;
  }
  function chirp(base = 2600, notes = 3) {
    for (let i = 0; i < notes; i++) {
      const f = base * (0.9 + Math.random() * 0.35);
      tone({ at: i * (0.09 + Math.random() * 0.05), freq: f, freqEnd: f * (1.2 + Math.random() * 0.4), dur: 0.08, gain: 0.03 + Math.random() * 0.02, dest: ambBus });
    }
  }
  const AMBIENTS = {
    plains: { loops: [['bandpass', 520, 0.6, 0.11, 0.09, 0.6], ['highpass', 4200, 0.5, 0.012, 0.2, 0.5]], events: [[2500, 6500, () => chirp(2800, 2 + Math.floor(Math.random() * 3))]] },
    island: { loops: [['lowpass', 520, 0.7, 0.22, 0.11, 0.85], ['highpass', 3200, 0.4, 0.03, 0.11, 0.9]], events: [[9000, 18000, () => { const f = 950; tone({ freq: f, freqEnd: 1350, dur: 0.18, gain: 0.025, dest: ambBus }); tone({ at: 0.2, freq: 1300, freqEnd: 800, dur: 0.26, gain: 0.02, dest: ambBus }); }]] },
    swamp: { loops: [['lowpass', 260, 0.5, 0.08, 0.05, 0.4], ['bandpass', 4600, 12, 0.02, 7, 1]], events: [[1800, 5200, () => { for (let i = 0; i < 2 + Math.floor(Math.random() * 3); i++) tone({ at: i * 0.17, freq: 120 + Math.random() * 40, freqEnd: 95, type: 'square', dur: 0.12, gain: 0.03, dest: ambBus }); }]] },
    mountain: { loops: [['bandpass', 300, 0.4, 0.2, 0.07, 0.75], ['bandpass', 1150, 9, 0.02, 0.13, 0.9]], events: [[9000, 20000, () => { hiss({ dur: 2.6, gain: 0.09, type: 'lowpass', freq: 90, attack: 0.6, dest: ambBus }); }]] },
    forest: { loops: [['highpass', 2600, 0.4, 0.03, 0.22, 0.7], ['bandpass', 420, 0.5, 0.04, 0.06, 0.5]], events: [[1200, 4200, () => chirp(2200 + Math.random() * 1800, 2 + Math.floor(Math.random() * 4))]] },
  };
  function stopAmbient() {
    if (!ambient) return;
    ambient.nodes.forEach((node) => { try { node.stop(); } catch { /* já parado */ } });
    ambient.timers.forEach((timer) => clearTimeout(timer));
    ambient = null;
  }
  function startAmbient(kind) {
    stopAmbient();
    const def = AMBIENTS[kind];
    if (!def || !ctx) return;
    ambient = { nodes: [], timers: [] };
    def.loops.forEach((args) => ambient.nodes.push(...loopNoise(...args)));
    def.events.forEach(([min, max, fn]) => {
      const schedule = () => {
        const timer = setTimeout(() => { if (!ambient) return; if (settings.ambient && ready()) fn(); schedule(); }, min + Math.random() * (max - min));
        ambient?.timers.push(timer);
      };
      schedule();
    });
  }
  function setAmbient(kind) {
    if (kind === ambientKind && ambient) return;
    ambientKind = kind;
    if (ctx) startAmbient(kind);
  }

  return {
    play,
    setAmbient,
    unlock,
    settings: () => ({ ...settings }),
    /** Muda as preferências de som (lembradas neste navegador). */
    set(partial) {
      Object.assign(settings, partial);
      try { localStorage.setItem(STORE, JSON.stringify(settings)); } catch { /* sem armazenamento: só não lembra */ }
      applyVolumes();
    },
  };
}
