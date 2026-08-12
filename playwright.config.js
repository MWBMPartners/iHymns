// ============================================================================
// iHymns — Playwright configuration (browser smoke test)
//
// Copyright (c) 2026 iHymns. All rights reserved.
//
// PURPOSE:
// Minimal config for tests/browser/smoke.spec.js — the runtime safety net
// that proves the real PHP-served SPA shell boots its JS module graph in an
// actual browser, catching the class of failure static checks (php -l,
// ESLint, jsdom unit tests) structurally cannot: a CSP that refuses an
// inline script, an ES module that 404s, a syntax error that only a real
// parser hits. See tests/browser/smoke.spec.js's header for what this does
// and does not cover.
//
// SERVER: `webServer` boots the real app with `php -S`, routed through
// tests/browser/router.php (php -S has no .htaccess / mod_rewrite, so
// without it every clean URL the SPA depends on — starting with `/api`
// itself — would 404 or fall through to the wrong file; see that file's
// header for the full explanation).
//
// BROWSER: Chromium only, for now — this is a smoke test, not a
// cross-browser suite. Locally, PLAYWRIGHT_BROWSERS_PATH may point at a
// pre-installed Chromium build whose revision doesn't match what this
// package.json-pinned @playwright/test version would otherwise try to
// download (see resolvePreinstalledChromium() below) — CI installs its own
// matching build via `npx playwright install --with-deps chromium` and
// never hits that branch.
// ============================================================================

import { defineConfig, devices } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';

/** Fixed, not randomised — a stable port makes a stray leftover server from
 *  a previous crashed run fail loudly (EADDRINUSE) instead of silently
 *  answering with stale content. Overridable for a machine that already has
 *  something on 8973. */
const PORT = Number(process.env.IHYMNS_SMOKE_PORT || 8973);
const BASE_URL = `http://127.0.0.1:${PORT}`;

/**
 * If PLAYWRIGHT_BROWSERS_PATH points at a directory containing a `chromium`
 * launcher (the convenience symlink Playwright's own installer always
 * creates), use it directly instead of letting Playwright resolve a browser
 * by revision. This is what makes the test runnable against a browser that
 * was pre-provisioned outside of `npx playwright install` (this repo's dev
 * container ships one at a pinned path) without needing that revision to
 * exactly match this package.json's @playwright/test version.
 *
 * Returns undefined when there's nothing to override, which lets Playwright
 * fall back to its normal (version-matched, auto-downloaded) resolution —
 * the path CI takes after `npx playwright install --with-deps chromium`.
 *
 * @returns {string|undefined}
 */
function resolvePreinstalledChromium() {
    const browsersPath = process.env.PLAYWRIGHT_BROWSERS_PATH;
    if (!browsersPath) { return undefined; }
    const candidate = path.join(browsersPath, 'chromium');
    try {
        return fs.existsSync(candidate) ? candidate : undefined;
    } catch {
        return undefined;
    }
}

const preinstalledChromium = resolvePreinstalledChromium();

export default defineConfig({
    testDir: 'tests/browser',
    fullyParallel: false,   // one spec, one server — parallelism buys nothing yet
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 1 : 0,   // tolerate a single transient CI hiccup, not a real failure
    workers: 1,
    reporter: process.env.CI ? [['list'], ['github']] : [['list']],
    timeout: 30_000,
    expect: { timeout: 10_000 },

    use: {
        baseURL: BASE_URL,
        headless: true,
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },

    projects: [
        {
            name: 'chromium',
            use: {
                ...devices['Desktop Chrome'],
                ...(preinstalledChromium ? { launchOptions: { executablePath: preinstalledChromium } } : {}),
            },
        },
    ],

    /* Boots the real app via PHP's built-in server. reuseExistingServer is
       true outside CI so a developer can `npm run dev`-style leave a server
       up across repeated local test runs; CI always starts fresh. */
    webServer: {
        command: `php -S 127.0.0.1:${PORT} -t appWeb/public_html tests/browser/router.php`,
        url: BASE_URL + '/',
        reuseExistingServer: !process.env.CI,
        timeout: 20_000,
        stdout: 'pipe',
        stderr: 'pipe',
    },
});
