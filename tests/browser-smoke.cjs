/**
 * Optional Playwright smoke test. Requires Playwright installed in the parent
 * workspace (the repository root) or globally:
 *
 *   cd /path/to/workspace
 *   NODE_PATH=$PWD/node_modules node hajkic/tests/browser-smoke.cjs
 *
 * Optional env:
 *   HAJKIC_TEST_BASE=http://127.0.0.1:8099
 */
const assert = require('node:assert/strict');
const { chromium } = require('@playwright/test');

const BASE = (process.env.HAJKIC_TEST_BASE || 'http://127.0.0.1:8099').replace(/\/$/, '');

async function main() {
    const browser = await chromium.launch({
        headless: true,
        args: ['--use-fake-ui-for-media-stream', '--use-fake-device-for-media-stream'],
    });

    try {
        const context = await browser.newContext({
            viewport: { width: 1440, height: 900 },
            permissions: ['camera', 'geolocation'],
            geolocation: { latitude: 44.0, longitude: 20.8, accuracy: 15 },
        });
        const page = await context.newPage();
        const errors = [];
        page.on('pageerror', (error) => errors.push(error.message));
        page.on('console', (message) => {
            if (message.type() === 'error') errors.push(message.text());
        });

        await page.goto(`${BASE}/`, { waitUntil: 'domcontentloaded' });
        await page.waitForSelector('.trail-card', { timeout: 20000 });
        await page.waitForTimeout(700);

        assert.ok(await page.locator('.trail-card').count() > 10, 'trail cards should render');
        assert.ok(await page.locator('.leaflet-tile').count() > 0, 'map tiles should render');
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false, 'no horizontal overflow');

        const target = await page.evaluate(async () => {
            const index = await fetch(`${window.HAJKIC.base}data/trails-index.json`).then((r) => r.json());
            const trail = index.trails.find((item) => Array.isArray(item.start_point) && item.gpx?.available);
            return trail ? { slug: trail.slug, start: trail.start_point } : null;
        });
        assert.ok(target, 'a trail with GPX and start point should exist');

        await context.setGeolocation({ latitude: target.start[1], longitude: target.start[0], accuracy: 10 });
        await page.goto(`${BASE}/?trail=${encodeURIComponent(target.slug)}`, { waitUntil: 'domcontentloaded' });
        await page.waitForSelector('#trail-panel:not([hidden])', { timeout: 20000 });
        await page.waitForTimeout(700);

        assert.equal(await page.locator('#trail-panel').isHidden(), false, 'deep-linked trail panel should open');
        assert.ok(await page.locator('.profile-chart svg').count() > 0, 'selected trail should have a profile');
        assert.ok(await page.locator('.leaflet-overlay-pane svg path').count() >= 2, 'selected geometry should be drawn');

        await page.click('[data-locate], #locate-nearby');
        await page.waitForTimeout(1200);
        assert.equal(await page.locator('#nearby-panel').isHidden(), false, 'nearby panel should open after locating');

        await page.click('[data-action="ar"]');
        await page.waitForTimeout(2200);
        assert.equal(await page.locator('#ar-view').count(), 1, 'AR overlay should open');
        assert.equal(await page.locator('#ar-fatal').isHidden(), true, 'fake camera should start');
        const video = await page.evaluate(() => {
            const element = document.querySelector('#ar-video');
            return element ? { width: element.videoWidth, height: element.videoHeight, readyState: element.readyState } : null;
        });
        assert.ok(video && video.width > 0 && video.readyState >= 2, 'AR camera video should be playing');
        assert.ok(await page.locator('#ar-target-list li').count() > 0, 'AR should show targets');
        await page.click('#ar-close');
        await page.waitForTimeout(300);
        assert.equal(await page.locator('#ar-view').count(), 0, 'AR overlay should close and stop the camera');

        // Server-rendered trail page.
        const trailPage = await context.newPage();
        await trailPage.goto(`${BASE}/staza/${encodeURIComponent(target.slug)}/`, { waitUntil: 'domcontentloaded' });
        await trailPage.waitForSelector('h1', { timeout: 10000 });
        assert.ok((await trailPage.locator('h1').innerText()).length > 2, 'trail page should have an h1');
        assert.ok(await trailPage.locator('.trail-statbar__cell').count() >= 4, 'trail page should show stats');
        assert.ok(await trailPage.locator('script[type="application/ld+json"]').count() > 0, 'trail page should have JSON-LD');
        await trailPage.close();

        // Mobile layout + filter sheet.
        const mobile = await browser.newContext({
            viewport: { width: 390, height: 844 },
            isMobile: true,
            hasTouch: true,
        });
        const mobilePage = await mobile.newPage();
        await mobilePage.goto(`${BASE}/`, { waitUntil: 'domcontentloaded' });
        await mobilePage.waitForSelector('.leaflet-tile', { timeout: 20000 });
        await mobilePage.waitForTimeout(700);
        assert.ok(await mobilePage.evaluate(() => document.querySelector('#map').clientHeight > 500), 'mobile map should have height');
        assert.equal(await mobilePage.evaluate(() => document.documentElement.scrollWidth > innerWidth), false, 'mobile has no horizontal overflow');
        await mobilePage.click('#mobile-filter-btn');
        await mobilePage.waitForTimeout(250);
        const filterRect = await mobilePage.evaluate(() => {
            const panel = document.querySelector('#filter-panel');
            const rect = panel.getBoundingClientRect();
            return { hidden: panel.hidden, width: rect.width, height: rect.height };
        });
        assert.equal(filterRect.hidden, false);
        assert.ok(filterRect.width >= 380 && filterRect.height >= 800, 'mobile filters should cover the viewport');
        await mobilePage.click('#filter-close');
        await mobile.close();

        assert.deepEqual(errors, [], `console errors: ${errors.join(' | ')}`);
        console.log('PASS: map, search state, deep link, profile, locate, AR, SEO page and mobile layout');
    } finally {
        await browser.close();
    }
}

main().then(() => {
    process.exit(0);
}).catch((error) => {
    console.error('FAIL:', error.message);
    process.exit(1);
});
