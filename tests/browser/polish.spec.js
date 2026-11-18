// ============================================================================
// iHymns — browser "polish" checks for the public pages (regression guard)
//
// Copyright (c) 2026 iHymns. All rights reserved.
//
// ELI5
// ----
// smoke.spec.js proves the app starts. This file checks the small things a
// visitor notices in the first few seconds, on every public page that works
// with an empty catalogue: the tab has its own title, the page has a
// description and the tags that make a shared link show a proper preview,
// there is exactly one main heading, no image is missing its text
// alternative, nothing goes red in the browser console, the page does not
// scroll sideways on a phone or tablet, and a made-up address gets a real
// "page not found" (HTTP 404), not a page that pretends to exist.
//
// WHAT IT RUNS AGAINST
// --------------------
// The same server and database as the CI "Browser smoke" job: php -S with
// tests/browser/router.php (see playwright.config.js) and a schema-only
// MariaDB, so there are no songs. That is why the list below holds only pages
// that render with an empty catalogue; song, songbook and other record pages
// need data and are covered by the manual audit instead. Every check here
// passed when it was added — it exists to catch these coming BACK.
//
// HOW IT STAYS FAST
// -----------------
// Service workers are blocked (they would pre-cache the whole app on every
// fresh page and reload it — nothing here tests offline behaviour), and each
// page is loaded once: the phone (320px) and tablet (768px) widths are checked
// by resizing the already-loaded page rather than loading it again.
//
// CONSOLE ERRORS
// --------------
// Third-party CDN failures (hosts read from the page's own CSP header) and
// their one known knock-on message are ignored, exactly as smoke.spec.js does
// (shared via browser-helpers.js), because CI's network is not under our
// control. Nothing else is ignored — in particular NOT the anonymous 401s that
// list-sort.js and setlist.js used to trigger, which this file guards.
// ============================================================================

import { test, expect } from '@playwright/test';
import {
    ensureVendorStubs,
    thirdPartyHostsFromCsp,
    isThirdPartyLoadFailure,
    isBootstrapMissingConsequence,
} from './browser-helpers.js';

/**
 * Public pages that render fully with an EMPTY catalogue (schema-only DB).
 * /login is left out on purpose: it opens the sign-in box over the home page
 * and replaces the address with "/", so it has no page of its own to check.
 */
const PUBLIC_ROUTES = [
    '/',
    '/songbooks',
    '/search',
    '/favorites',
    '/setlist',
    '/settings',
    '/help',
    '/whats-new',
    '/terms',
    '/privacy',
    '/request',
    '/themes',
    '/stats',
    '/link',
];

/** Phone and tablet widths at which nothing may push the page sideways. */
const OVERFLOW_WIDTHS = [320, 768];

test.use({ serviceWorkers: 'block' });

test.beforeAll(() => {
    ensureVendorStubs();
});

test.beforeEach(async ({ page }) => {
    /* The first-visit "Welcome to iHymns" notice is a modal; accept it up
       front so it never sits over the page being measured. Wrapped because
       localStorage can throw in locked-down browser modes. */
    await page.addInitScript(() => {
        try { localStorage.setItem('ihymns_disclaimer_accepted', 'true'); } catch (_e) { /* ignore */ }
    });
});

/**
 * Read the facts this spec checks from the live page.
 *
 * @param {import('@playwright/test').Page} page
 */
async function readPageFacts(page) {
    return page.evaluate(() => {
        const visible = (el) => {
            const s = getComputedStyle(el);
            const r = el.getBoundingClientRect();
            return s.display !== 'none' && s.visibility !== 'hidden' && r.width > 0 && r.height > 0;
        };
        const meta = (sel) => document.querySelector(sel)?.getAttribute('content')?.trim() || '';
        return {
            title: document.title.trim(),
            description: meta('meta[name="description"]'),
            canonical: document.querySelector('link[rel="canonical"]')?.getAttribute('href') || '',
            ogTitle: meta('meta[property="og:title"]'),
            ogImage: meta('meta[property="og:image"]'),
            h1s: [...document.querySelectorAll('h1')].filter(visible)
                .map((h) => h.textContent.replace(/\s+/g, ' ').trim()),
            imagesWithoutAlt: [...document.querySelectorAll('img:not([alt])')]
                .map((img) => img.getAttribute('src') || '(no src)'),
        };
    });
}

test.describe('public pages: titles, share tags, headings, console, layout', () => {
    test('every public page passes the first-impression checks', async ({ page }) => {
        /* One page, loaded fourteen times — roughly 1s each; the default 30s
           per-test budget is too tight on a slow CI runner. */
        test.setTimeout(90_000);

        /** @type {{route: string, msg: import('@playwright/test').ConsoleMessage}[]} */
        const consoleErrors = [];
        /* Each page's own CSP hosts. Looked up when the errors are judged at the
           end, because a page's console errors arrive while it is still loading
           — before its response headers are in hand. */
        /** @type {Map<string, string[]>} */
        const cspHostsByRoute = new Map();
        /** @type {{route: string, error: string}[]} */
        const pageErrors = [];
        /** @type {Map<string, string>} title → first route that used it */
        const titles = new Map();
        let currentRoute = '';

        page.on('console', (msg) => {
            if (msg.type() === 'error') { consoleErrors.push({ route: currentRoute, msg }); }
        });
        page.on('pageerror', (err) => { pageErrors.push({ route: currentRoute, error: String(err) }); });

        for (const route of PUBLIC_ROUTES) {
            await test.step(route, async () => {
                currentRoute = route;
                await page.setViewportSize({ width: 1280, height: 900 });
                const response = await page.goto(route, { waitUntil: 'networkidle' });
                cspHostsByRoute.set(route, thirdPartyHostsFromCsp(response?.headers()['content-security-policy'] || ''));

                expect.soft(response?.status(), `${route} should load with HTTP 200`).toBe(200);

                /* The SPA fills #page-content after boot; wait for its heading
                   rather than a fixed delay. A missing heading is reported by
                   the h1 check below, so a timeout here is not itself fatal. */
                await page.locator('#page-content h1').first()
                    .waitFor({ state: 'visible', timeout: 10_000 })
                    .catch(() => {});

                const facts = await readPageFacts(page);

                expect.soft(facts.title, `${route}: the browser tab title is empty`).not.toBe('');
                if (facts.title !== '') {
                    const firstUser = titles.get(facts.title);
                    expect.soft(firstUser, `${route}: has the same tab title as ${firstUser} ("${facts.title}") — every page should have its own`)
                        .toBeUndefined();
                    if (firstUser === undefined) { titles.set(facts.title, route); }
                }
                expect.soft(facts.description, `${route}: has no meta description (what search results and link previews show)`)
                    .not.toBe('');
                expect.soft(facts.canonical, `${route}: has no canonical link, or it is not a full https/http address`)
                    .toMatch(/^https?:\/\/[^/]+\//);
                expect.soft(facts.ogTitle, `${route}: has no og:title, so a shared link has no preview title`).not.toBe('');
                expect.soft(facts.ogImage, `${route}: og:image is missing or does not point at the generated share image`)
                    .toMatch(/^https?:\/\/[^/]+\/og-image/);
                expect.soft(facts.h1s.length, `${route}: should have exactly one visible main heading (<h1>), found ${facts.h1s.length}: ${JSON.stringify(facts.h1s)}`)
                    .toBe(1);
                expect.soft(facts.imagesWithoutAlt, `${route}: these images have no alt text for screen readers`).toEqual([]);

                /* Layout can only be judged with Bootstrap's stylesheet applied.
                   In CI it comes from the CDN (or, if the CDN fails, the
                   /vendor copy). A sandbox with neither gets the empty stubs
                   from ensureVendorStubs(), and an unstyled page always
                   overflows — so say so in the report rather than blame the
                   app. `--bs-blue` is defined only by bootstrap.min.css. */
                const bootstrapApplied = await page.evaluate(() =>
                    getComputedStyle(document.documentElement).getPropertyValue('--bs-blue').trim() !== '');
                if (!bootstrapApplied) {
                    test.info().annotations.push({
                        type: 'layout-not-checked',
                        description: `${route}: Bootstrap CSS did not load (no CDN access and no tools/download-vendor.sh copy), so the 320px/768px overflow checks were not run`,
                    });
                }
                for (const width of bootstrapApplied ? OVERFLOW_WIDTHS : []) {
                    await page.setViewportSize({ width, height: 800 });
                    const size = await page.evaluate(() => ({
                        page: document.documentElement.scrollWidth,
                        screen: document.documentElement.clientWidth,
                    }));
                    expect.soft(size.page, `${route} at ${width}px wide: the page is ${size.page}px wide, so it scrolls sideways on a ${width <= 320 ? 'phone' : 'tablet'}`)
                        .toBeLessThanOrEqual(size.screen);
                }
            });
        }

        const unexpected = consoleErrors
            .filter(({ route, msg }) => !isThirdPartyLoadFailure(msg, cspHostsByRoute.get(route) || [])
                && !isBootstrapMissingConsequence(msg))
            .map(({ route, msg }) => `${route}: ${msg.text()}  [${msg.location().url || 'page'}]`);
        expect.soft(unexpected, 'Red errors appeared in the browser console on these pages').toEqual([]);
        expect.soft(pageErrors.map(({ route, error }) => `${route}: ${error}`), 'A script crashed (uncaught error) on these pages')
            .toEqual([]);
    });

    test('a made-up address gets a real "page not found"', async ({ page }) => {
        const response = await page.goto('/this-page-does-not-exist-polish-check', { waitUntil: 'networkidle' });

        expect(response?.status(), 'An address that is not a page must answer HTTP 404, not pretend to exist with 200')
            .toBe(404);
        await expect(page.locator('#page-content h1'), 'The "not found" page should say so in its main heading')
            .toHaveText(/not found/i, { timeout: 10_000 });
        await expect(page.locator('#page-content a[href="/"]').first(), 'The "not found" page should offer a way back to the home page')
            .toBeVisible();
        await expect(page, 'The browser tab should say the page was not found').toHaveTitle(/not found/i);
    });
});
