// Smaug — artist-authored model by J.Kirkwood, presented through Sketchfab's
// official Viewer API. Geometry/textures remain hosted by their author.
// https://sketchfab.com/3d-models/8136889761c84cc1b6815b11aa7f6f74
const MODEL = '8136889761c84cc1b6815b11aa7f6f74';
const SDK = 'https://static.sketchfab.com/api/sketchfab-viewer-1.12.1.js';
// Authored camera positions for this particular model, in its native units.
const CAMERAS = {
  detail: { position: [600, -900, 440], target: [230, -160, 260] },
  full: { position: [1250, -2100, 950], target: [-90, 205, 200] },
};
const stage = document.querySelector('#dragon-stage');
if (stage) mountDragon();

function loadViewerSDK() {
  if (window.Sketchfab) return Promise.resolve(window.Sketchfab);
  return new Promise((resolve, reject) => {
    const script = document.createElement('script');
    const timer = setTimeout(() => { script.remove(); reject(new Error('Viewer timeout')); }, 15000);
    script.src = SDK;
    script.async = true;
    script.onload = () => { clearTimeout(timer); window.Sketchfab ? resolve(window.Sketchfab) : reject(new Error('Viewer unavailable')); };
    script.onerror = () => { clearTimeout(timer); script.remove(); reject(new Error('Viewer offline')); };
    document.head.append(script);
  });
}

async function mountDragon() {
  const controls = stage.querySelector('.dragon-controls');
  const pause = controls.querySelector('[data-dragon="pause"]');
  const hint = document.querySelector('#dragon-hint');
  const retry = stage.querySelector('.dragon-retry');
  const reducedMotion = matchMedia('(prefers-reduced-motion: reduce)');
  let api, frame, ready = false, failed = false, rotating = false, visible = true;
  let timer = 0, loadTimeout = 0, camera = null, startedAt = 0, orbitStart = null;
  let observer;
  stage.setAttribute('aria-busy', 'true');
  hint.textContent = 'Carregando Smaug em 3D…';
  controls.hidden = true;
  retry.hidden = true;
  stage.classList.remove('is-ready', 'has-error');

  function syncPause() {
    pause.textContent = rotating ? 'Pausar' : 'Animar';
    pause.setAttribute('aria-pressed', String(!rotating));
  }
  function clearOrbit() { clearTimeout(timer); timer = 0; }
  function freeze() {
    clearOrbit();
    if (ready) api.getCameraLookAt((err, current) => {
      if (!err && !rotating) { camera = current; api.setCameraLookAt(current.position, current.target, 0); }
    });
  }
  function stopRotation() { rotating = false; freeze(); syncPause(); }
  function showCamera(name, duration = 0) {
    const preset = CAMERAS[name];
    const aspect = stage.clientWidth / Math.max(1, stage.clientHeight - 60);
    const distance = name === 'full' ? Math.max(1, 1.6 / aspect) : 1;
    const position = preset.position.map((n, i) => preset.target[i] + (n - preset.target[i]) * distance);
    api.setCameraLookAt(position, preset.target, duration);
  }
  function step() {
    if (!ready || !rotating || !visible || document.hidden || !orbitStart) return;
    const a = (performance.now() - startedAt) * .00012;
    const [x, y, z] = orbitStart.position.map((n, i) => n - orbitStart.target[i]);
    // Sketchfab uses Z as the vertical axis.
    const position = [x * Math.cos(a) - y * Math.sin(a), x * Math.sin(a) + y * Math.cos(a), z]
      .map((n, i) => n + orbitStart.target[i]);
    api.setCameraLookAt(position, orbitStart.target, .13);
    timer = setTimeout(step, 120);
  }
  function startOrbit() {
    clearOrbit();
    api.getCameraLookAt((err, current) => {
      if (err || !rotating || !visible || document.hidden) return;
      orbitStart = current; startedAt = performance.now(); step();
    });
  }
  function lifecycle() {
    clearOrbit();
    if (!ready) return;
    if (visible && !document.hidden) { api.start(); if (rotating) startOrbit(); }
    else api.stop();
  }
  function fail() {
    if (failed) return;
    failed = true; ready = false;
    clearTimeout(loadTimeout); clearOrbit();
    observer?.disconnect();
    frame?.remove();
    controls.hidden = true; retry.hidden = false;
    stage.classList.remove('is-ready'); stage.classList.add('has-error');
    stage.setAttribute('aria-busy', 'false');
    hint.textContent = 'O modelo precisa de internet. Verifique a conexão e tente novamente.';
  }
  retry.onclick = () => { cleanup(); mountDragon(); };
  function onVisibility() { lifecycle(); }
  function onReducedMotion() { if (reducedMotion.matches) stopRotation(); }
  function onFrameFocus() { if (document.activeElement === frame) stopRotation(); }
  function cleanup() {
    clearTimeout(loadTimeout); clearOrbit(); observer?.disconnect(); frame?.remove();
    document.removeEventListener('visibilitychange', onVisibility);
    reducedMotion.removeEventListener('change', onReducedMotion);
    window.removeEventListener('blur', onFrameFocus);
  }

  try {
    const Sketchfab = await loadViewerSDK();
    if (failed) return;
    frame = document.createElement('iframe');
    frame.className = 'dragon-viewer';
    frame.title = 'Smaug — modelo 3D detalhado de J.Kirkwood. Arraste para girar e use a roda para aproximar.';
    frame.allow = 'fullscreen';
    frame.allowFullscreen = true;
    stage.prepend(frame);
    loadTimeout = setTimeout(fail, 60000);
    const client = new Sketchfab('1.12.1', frame);
    client.init(MODEL, {
      autostart: 1, preload: 1, autospin: 0, animation_autoplay: 0, camera: 0,
      ui_infos: 0, ui_hint: 2, ui_animations: 0, ui_inspector: 0,
      ui_watermark_link: 1, ui_stop: 0,
      success(viewer) {
        if (failed) return;
        api = viewer;
        api.addEventListener('viewerready', () => {
          if (failed || ready) return;
          ready = true; clearTimeout(loadTimeout);
          // Preserve the artist's authored materials, textures and lighting.
          api.pause();
          api.setBackground({ color: [.035, .045, .04] });
          api.setEnableCameraConstraints(false, {});
          api.setCameraEasing('easeLinear');
          api.setFov(42);
          showCamera('detail');
          stage.classList.add('is-ready'); stage.setAttribute('aria-busy', 'false');
          controls.hidden = false; hint.textContent = 'Arraste para girar · role para aproximar';
          syncPause(); lifecycle();
        });
        api.start();
      },
      error: fail,
    });
    controls.onclick = event => {
      const action = event.target.closest('button')?.dataset.dragon;
      if (!action || !ready) return;
      if (action === 'pause') {
        rotating = !rotating; syncPause();
        if (rotating) startOrbit(); else freeze();
        return;
      }
      rotating = false; clearOrbit(); syncPause();
      if (action === 'full' || action === 'detail') { showCamera(action, reducedMotion.matches ? 0 : .6); return; }
      api.getCameraLookAt((err, current) => {
        if (err) return;
        camera = current;
        const a = action === 'left' ? -.3 : .3;
        const [x, y, z] = camera.position.map((n, i) => n - camera.target[i]);
        const position = [x * Math.cos(a) - y * Math.sin(a), x * Math.sin(a) + y * Math.cos(a), z]
          .map((n, i) => n + camera.target[i]);
        api.setCameraLookAt(position, camera.target, reducedMotion.matches ? 0 : .3);
      });
    };
    observer = new IntersectionObserver(([entry]) => { visible = entry.isIntersecting; lifecycle(); });
    observer.observe(stage);
    document.addEventListener('visibilitychange', onVisibility);
    reducedMotion.addEventListener('change', onReducedMotion);
    window.addEventListener('blur', onFrameFocus);
    window.addEventListener('pagehide', cleanup, { once: true });
  } catch { fail(); }
}
