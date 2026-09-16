// Run with NODE_PATH pointing to a Playwright installation and the app on :8080.
const assert = require('node:assert/strict');
const { chromium } = require('playwright');
(async () => {
  const browser = await chromium.launch({ headless: true, channel: 'chrome' });
  try {
    const page = await browser.newPage({ viewport: { width: 1440, height: 1000 }, reducedMotion: 'reduce' });
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.goto('http://localhost:8080/', { waitUntil: 'domcontentloaded' });
    await page.locator('#dragon-stage.is-ready').waitFor({ timeout: 65000 });
    assert.equal(await page.locator('.sidebar [aria-current="page"]').innerText(), 'Início');
    assert.equal(await page.locator('[data-dragon="pause"]').innerText(), 'Animar');
    await page.locator('[data-dragon="right"]').click();
    await page.locator('[data-dragon="pause"]').click();
    assert.equal(await page.locator('[data-dragon="pause"]').innerText(), 'Pausar');
    await page.locator('[data-dragon="pause"]').click();
    assert.equal(await page.locator('.card-tile').count(), 6);
    await page.locator('.card-tile').last().scrollIntoViewIfNeeded();
    await page.waitForFunction(() => [...document.querySelectorAll('.card-tile img')].every(i => i.complete));
    await page.evaluate(() => window.scrollTo(0, 0));
    await page.screenshot({ path: '.impeccable/review/home-desktop.png', fullPage: true });
    await page.screenshot({ path: '.impeccable/review/home-desktop-viewport.png' });
    await page.setViewportSize({ width: 390, height: 844 });
    await page.screenshot({ path: '.impeccable/review/home-mobile.png', fullPage: true });
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false);
    await page.locator('#home-query').fill('Smaug');
    await Promise.all([page.waitForURL('**/?q=Smaug'), page.locator('.home-search button').click()]);
    assert.equal(await page.locator('#dragon-stage').count(), 0);
    assert.ok(await page.locator('.card-tile').count() > 0);
    await page.goto('http://localhost:8080/?catalog=1#catalogo');
    assert.equal(await page.locator('.card-tile').count(), 36);
    assert.equal(await page.locator('.sidebar [aria-current="page"]').innerText(), 'Catálogo');
    await page.goto('http://localhost:8080/?type=Dragon#catalogo');
    assert.ok(await page.locator('.card-tile').count() > 0);
    assert.equal(await page.locator('#dragon-stage').count(), 0);
    const fallback = await browser.newPage();
    await fallback.route('https://static.sketchfab.com/**', route => route.abort());
    await fallback.goto('http://localhost:8080/');
    await fallback.getByText('O modelo precisa de internet.', { exact: false }).waitFor();
    assert.equal(await fallback.locator('.dragon-fallback').isVisible(), true);
    assert.equal(await fallback.locator('.home-search button').isEnabled(), true);
    assert.deepEqual(errors, []);
    console.log('PASS: home, 3D, reduced motion, controls, mobile overflow, search, catalog, dragon filter and fallback.');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
