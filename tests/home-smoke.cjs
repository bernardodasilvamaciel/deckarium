// Run with NODE_PATH pointing to a Playwright installation and the app on :8080.
const assert = require('node:assert/strict');
const { chromium } = require('playwright');
(async () => {
  const browser = await chromium.launch({ headless: true, channel: 'chrome' });
  try {
    const page = await browser.newPage({ viewport: { width: 1440, height: 1000 }, reducedMotion: 'reduce' });
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.goto('http://localhost:8080/?hl=pt-BR', { waitUntil: 'domcontentloaded' });
    assert.equal(await page.locator('.sidebar [aria-current="page"]').innerText(), 'Início');
    assert.equal(await page.locator('.home-steps > li').count(), 4);
    assert.equal(await page.locator('.home-group').count(), 4);
    assert.ok(await page.locator('.home-module').count() >= 10);
    assert.equal(await page.locator('.home-hand-card').count(), 5);
    await page.waitForFunction(() => [...document.querySelectorAll('.home-hand-card img')].every(i => i.complete));
    await page.screenshot({ path: '.impeccable/review/home-desktop.png', fullPage: true });
    await page.screenshot({ path: '.impeccable/review/home-desktop-viewport.png' });
    await page.setViewportSize({ width: 390, height: 844 });
    await page.screenshot({ path: '.impeccable/review/home-mobile.png', fullPage: true });
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false);
    await page.setViewportSize({ width: 1440, height: 1000 });
    await page.locator('#home-query').fill('Smaug');
    await Promise.all([page.waitForURL('**/?q=Smaug'), page.locator('.home-search button').click()]);
    assert.equal(await page.locator('.home-hero').count(), 0);
    assert.ok(await page.locator('.card-tile').count() > 0);
    await page.goto('http://localhost:8080/?catalog=1#catalogo');
    assert.equal(await page.locator('.card-tile').count(), 36);
    assert.equal(await page.locator('.sidebar [aria-current="page"]').innerText(), 'Catálogo');
    await page.goto('http://localhost:8080/?type=Dragon#catalogo');
    assert.ok(await page.locator('.card-tile').count() > 0);
    assert.deepEqual(errors, []);
    console.log('home smoke ok');
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
