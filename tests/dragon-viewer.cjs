const assert = require('node:assert/strict');
const { chromium } = require('playwright');
const fs = require('node:fs');
const out = '.impeccable/review/dragon';
fs.mkdirSync(out, { recursive: true });
(async () => {
  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  try {
    const page = await browser.newPage({ viewport: { width: 1440, height: 1000 }, reducedMotion: 'reduce' });
    // Capture the documented public Viewer API only in this test context.
    await page.addInitScript(() => {
      let SDK;
      Object.defineProperty(window, 'Sketchfab', { configurable: true, get: () => SDK, set: Original => {
        SDK = new Proxy(Original, { construct(Target, args) {
          const client = new Target(...args), init = client.init.bind(client);
          client.init = (id, options) => {
            const success = options.success;
            options.success = api => { window.testDragonAPI = api; success(api); };
            return init(id, options);
          };
          return client;
        } });
      } });
    });
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.goto('http://localhost:8080/', { waitUntil: 'domcontentloaded' });
    await page.locator('#dragon-stage.is-ready').waitFor({ timeout: 65000 });
    const camera = () => page.evaluate(() => new Promise((resolve, reject) => testDragonAPI.getCameraLookAt((err, value) => err ? reject(err) : resolve(value))));
    const distance = (a, b) => Math.hypot(...a.map((value, index) => value - b[index]));
    // Wait for the actual preset, not just the API initialization event.
    await page.waitForFunction(() => new Promise(resolve => testDragonAPI.getCameraLookAt((err, c) => resolve(!err && Math.abs(c.position[0] - 600) < 1))));
    assert.equal(await page.locator('[data-dragon=pause]').innerText(), 'Animar');
    const first = await camera();
    await page.locator('[data-dragon=left]').focus();
    await page.keyboard.press('Enter');
    await page.waitForTimeout(350);
    const left = await camera();
    assert.ok(distance(first.position, left.position) > 100, 'Keyboard rotation must move the real camera');
    await page.locator('[data-dragon=pause]').click();
    await page.waitForTimeout(800);
    const animated = await camera();
    assert.ok(distance(left.position, animated.position) > 20, 'Animate must rotate');
    await page.locator('[data-dragon=pause]').click();
    await page.waitForTimeout(300);
    const stopped = await camera();
    await page.waitForTimeout(300);
    assert.ok(distance(stopped.position, (await camera()).position) < 1, 'Pause must stop the camera');
    await page.locator('[data-dragon=detail]').click();
    await page.waitForTimeout(1200);
    await page.screenshot({ path: out + '/desktop.png' });
    await page.locator('.dragon-exhibit').screenshot({ path: out + '/detail.png' });
    await page.locator('[data-dragon=full]').click();
    await page.waitForTimeout(400);
    assert.ok(distance((await camera()).position, first.position) > 1000, 'Full body must zoom out');
    await page.locator('.dragon-exhibit').screenshot({ path: out + '/full-body.png' });
    await page.setViewportSize({ width: 390, height: 844 });
    await page.locator('.dragon-exhibit').scrollIntoViewIfNeeded();
    await page.locator('[data-dragon=detail]').click();
    await page.waitForTimeout(600);
    await page.locator('.dragon-exhibit').screenshot({ path: out + '/mobile-detail.png' });
    await page.locator('[data-dragon=full]').click();
    await page.waitForTimeout(400);
    await page.locator('.dragon-exhibit').screenshot({ path: out + '/mobile-full.png' });
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false);
    const bounds = await page.locator('.dragon-controls').boundingBox();
    assert.ok(bounds.x >= 0 && bounds.x + bounds.width <= 390);
    const fallback = await browser.newPage();
    await fallback.route('https://static.sketchfab.com/**', route => route.abort());
    await fallback.goto('http://localhost:8080/', { waitUntil: 'domcontentloaded' });
    await fallback.locator('#dragon-stage.has-error').waitFor();
    assert.equal(await fallback.locator('.dragon-fallback').isVisible(), true);
    assert.equal(await fallback.locator('.dragon-retry').isVisible(), true);
    assert.equal(await fallback.locator('.home-search button').isEnabled(), true);
    await fallback.unroute('https://static.sketchfab.com/**');
    await fallback.locator('.dragon-retry').click();
    await fallback.locator('#dragon-stage.is-ready').waitFor({ timeout: 65000 });
    assert.equal(await fallback.locator('.dragon-viewer').count(), 1);
    assert.deepEqual(errors, []);
    console.log('PASS: real model ready, detail/full camera positions, keyboard orbit, animation, pause, mobile fit, SDK failure and retry.');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
