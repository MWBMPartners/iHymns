// ============================================================================
// iHymns — shared helpers for the Playwright browser specs
//
// Copyright (c) 2026 iHymns. All rights reserved.
//
// ELI5: the browser tests (smoke.spec.js, polish.spec.js) both need to do two
// fiddly things the same way: make sure a missing third-party library falls
// back to something harmless instead of the app's own HTML, and tell "a CDN
// we don't control failed to load" apart from "our own code broke". This file
// holds the one copy of each, so the two specs can never disagree.
//
// It is NOT a spec (no `.spec.` in the name), so Playwright never runs it as a
// test — it is only imported. See smoke.spec.js's header ("THIRD-PARTY CDN
// NOTE") for the full background on why these exist.
// ============================================================================

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const REPO_ROOT = path.resolve(__dirname, '..', '..');

/**
 * See smoke.spec.js's "THIRD-PARTY CDN NOTE". Writes a syntactically-valid,
 * inert stub ONLY when the real (tools/download-vendor.sh-generated) file isn't
 * already there — never overwrites a real vendor asset.
 */
export function ensureVendorStubs() {
    const stubs = [
        [
            'appWeb/public_html/vendor/bootstrap/bootstrap.bundle.min.js',
            '/* smoke-test stub — real Bootstrap JS is fetched by tools/download-vendor.sh at deploy\n'
            + '   time. This file exists only so an unreachable CDN falls back to a harmless no-op\n'
            + '   script instead of index.php\'s own HTML (see smoke.spec.js header). */\n',
        ],
        /* The three CDN stylesheets index.php falls back to when the CDN cannot
           be reached. Since that fallback was fixed to actually fire in
           Chromium, a sandbox with no CDN access and no tools/download-vendor.sh
           output would otherwise request these and get a same-origin 404 (a
           console error that is not the app's fault). An empty stylesheet is
           the CSS twin of the inert JS stubs above. Paths: the `css_local`
           values in includes/config.php's APP_CONFIG['libraries']. */
        ...[
            'appWeb/public_html/vendor/bootstrap/bootstrap.min.css',
            'appWeb/public_html/vendor/fontawesome/css/all.min.css',
            'appWeb/public_html/vendor/bootstrap-icons/bootstrap-icons.min.css',
        ].map((rel) => [rel, '/* browser-test stub — the real file is fetched by tools/download-vendor.sh at deploy time. */\n']),
    ];
    for (const [rel, content] of stubs) {
        const abs = path.join(REPO_ROOT, rel);
        if (fs.existsSync(abs)) { continue; }
        fs.mkdirSync(path.dirname(abs), { recursive: true });
        fs.writeFileSync(abs, content);
    }
}

/**
 * Parse the `https://host` origins out of the page's own CSP header — the
 * exact third-party hosts this page is allowed to load from, read from the
 * live response rather than hand-copied (rule #34).
 *
 * @param {string} cspHeader
 * @returns {string[]}
 */
export function thirdPartyHostsFromCsp(cspHeader) {
    if (!cspHeader) { return []; }
    const hosts = new Set();
    for (const m of cspHeader.matchAll(/https:\/\/([a-z0-9.-]+)/gi)) {
        hosts.add(m[1]);
    }
    return [...hosts];
}

/**
 * A THIRD-PARTY CDN resource (one of the hosts the CSP allow-lists) failed to
 * load. That is a network fact about a host we don't control, not a defect in
 * the app. Scoped to those exact hosts, so a SAME-origin failure still counts.
 *
 * @param {import('@playwright/test').ConsoleMessage} msg
 * @param {string[]} thirdPartyHosts from thirdPartyHostsFromCsp()
 * @returns {boolean}
 */
export function isThirdPartyLoadFailure(msg, thirdPartyHosts) {
    const url = msg.location().url || '';
    return /^Failed to load resource:/.test(msg.text()) && thirdPartyHosts.some((h) => url.includes(h));
}

/**
 * The direct, understood consequence of a missing Bootstrap bundle (CDN
 * unreachable and only the inert vendor stub above to fall back to):
 * iHymnsApp.checkDisclaimer() references `window.bootstrap`, and app.js's own
 * try/catch logs it as a caught, non-fatal console.error.
 *
 * @param {import('@playwright/test').ConsoleMessage} msg
 * @returns {boolean}
 */
export function isBootstrapMissingConsequence(msg) {
    const text = msg.text();
    return text.includes('[iHymns] Initialisation error') && text.includes('bootstrap is not defined');
}
