/**
 * iHymns — PDF payload image-src relativisation guard (#1830)
 *
 * ELI5
 * ----
 * A church's uploaded logo (and the #1767 QR code) is drawn on a printout as
 * an `<img>`. In the browser's own Print dialog that `<img>` points at the
 * FULL web address (`https://host/org-logo.php?…`) because a print pop-up has
 * no "home address" to work a short `/org-logo.php` path out from. But when
 * the same page is turned into a downloadable PDF on the server, the server's
 * safety filter only trusts the SHORT form (`/org-logo.php?…`) and throws the
 * full-address one away — so the logo would silently vanish from the PDF.
 * `pdfRelativiseServerImgSrc()` rewrites just those two image addresses back
 * to the short form for the PDF request, and nothing else. This guard proves
 * it rewrites what it must and leaves everything else — crucially the visible
 * permalink / QR-caption URL TEXT — exactly as it was.
 *
 * WHY A GUARD (rule #34/#35): the sanitiser (`includes/html_sanitizer.php`,
 * `img_src` = `#^/qr\.php\?#` / `#^/org-logo\.php\?#`) and the PDF inliners
 * (`includes/pdf_renderer.php`, `_pdfInlineQrImage()` / `_pdfInlineOrgLogo()`,
 * both keyed on `^/…\.php\?`) both require the ROOT-RELATIVE shape. The client
 * renderer emits the ABSOLUTE shape (browser-Print popups need it). Those two
 * facts must agree via a mechanism, not a comment — this test is the mechanism.
 *
 * MUTATION-PROVEN (rule #34): actually run, not asserted —
 *   - Delete the `.replace(...)` body of `pdfRelativiseServerImgSrc()` (return
 *     `html` unchanged) -> RED on every "…relativised" case. Restored -> green.
 *   - Widen the endpoint group `(?:qr|org-logo)` to `.*` (rewrite ANY path)
 *     -> RED on "a non-endpoint same-origin <img> src is untouched". Restored.
 *   - Drop the leading `(src=")` anchor (match the bare origin anywhere) -> RED
 *     on "a /qr.php URL appearing as visible TEXT … is untouched". NOTE: the
 *     first draft's caption fixture used a `/song/…` URL, which never matches
 *     the endpoint group with OR without the anchor — so it stayed GREEN under
 *     this mutation (the "wrong-but-green" trap rule #34 warns of). A dedicated
 *     fixture that prints a `/qr.php?…` URL as body TEXT was added precisely so
 *     the anchor is provably load-bearing. Restored -> green.
 *   - Remove the origin regex-escaping -> stays green here (the test origins
 *     carry no regex metacharacters), a KNOWN redundancy noted honestly rather
 *     than claimed as covered — escaping matters only for an origin containing
 *     a `.` treated as a wildcard, which no real https origin's host does in a
 *     way that changes THIS match; kept for correctness, not provable here.
 *
 *   node tests/test-print-pdf-img-src.js
 *
 * Exit status 0 = all pass, 1 = at least one failure.
 */

import path from 'node:path';
import { fileURLToPath } from 'node:url';
import assert from 'node:assert/strict';

const __filename = fileURLToPath(import.meta.url);
const __dirname  = path.dirname(__filename);
const MODULE_PATH = path.resolve(
    __dirname, '..', 'appWeb/public_html/js/modules/print.js'
);

const { pdfRelativiseServerImgSrc } = await import(MODULE_PATH);

let passed = 0;
let failed = 0;
function test(name, fn) {
    try {
        fn();
        passed++;
        console.log(`  PASS  ${name}`);
    } catch (err) {
        failed++;
        console.log(`  FAIL  ${name}`);
        console.log(`        ${err.message}`);
    }
}

const ORIGIN = 'https://ihymns.example';

/* ---- The two server-inlined endpoints ARE relativised ---- */
test('an absolute same-origin /org-logo.php img src is relativised', () => {
    const html = `<div class="print-logo"><img class="print-logo-img" src="${ORIGIN}/org-logo.php?org=7&amp;kind=primary&amp;v=abc" alt="Church logo"></div>`;
    const out = pdfRelativiseServerImgSrc(html, ORIGIN);
    assert.ok(out.includes('src="/org-logo.php?org=7&amp;kind=primary&amp;v=abc"'), 'expected root-relative src');
    assert.ok(!out.includes(`${ORIGIN}/org-logo.php`), 'absolute origin must be gone from the src');
});

test('an absolute same-origin /qr.php img src is relativised (the #1767 R precedent this also fixes)', () => {
    const html = `<img class="print-qr-img" src="${ORIGIN}/qr.php?data=x&amp;format=svg&amp;size=512" alt="QR code">`;
    const out = pdfRelativiseServerImgSrc(html, ORIGIN);
    assert.ok(out.includes('src="/qr.php?data=x&amp;format=svg&amp;size=512"'), 'expected root-relative qr src');
});

/* ---- Everything else is left ALONE ---- */
test('the permalink / QR caption\'s visible URL TEXT is untouched (not an src=)', () => {
    const html = `<div class="print-qr-caption">${ORIGIN}/song/MP-1</div>`;
    const out = pdfRelativiseServerImgSrc(html, ORIGIN);
    assert.equal(out, html, 'caption URL text must survive verbatim — it is display text, not a fetch target');
});

test('a /qr.php URL appearing as visible TEXT (not an src=) is untouched — the src=" anchor is load-bearing', () => {
    /* Contrived (no surface emits this today), but it is the ONE case the
       leading `src="` anchor exists for: prove that a qr.php / org-logo.php
       address printed as body text is NOT relativised behind the reader's
       back. Without the anchor the rewrite would silently edit visible text. */
    const html = `<figcaption>Scan target: ${ORIGIN}/qr.php?data=x</figcaption>`;
    const out = pdfRelativiseServerImgSrc(html, ORIGIN);
    assert.equal(out, html, 'a qr.php/org-logo.php URL in visible text must stay verbatim (only src= values are rewritten)');
});

test('a non-endpoint same-origin <img> src is untouched (only qr.php / org-logo.php are rewritten)', () => {
    const html = `<img src="${ORIGIN}/some-other/path.png?x=1">`;
    const out = pdfRelativiseServerImgSrc(html, ORIGIN);
    assert.equal(out, html, 'only the two server-inlined endpoints may be relativised');
});

test('a DIFFERENT-origin /org-logo.php src is untouched (exact-origin match only)', () => {
    const html = `<img src="https://evil.example/org-logo.php?org=1&amp;kind=primary">`;
    const out = pdfRelativiseServerImgSrc(html, ORIGIN);
    assert.equal(out, html, 'a cross-origin absolute URL is not our origin — leave it (the sanitiser will drop it)');
});

test('an already root-relative src is unchanged (idempotent — no double rewrite)', () => {
    const html = `<img src="/org-logo.php?org=7&amp;kind=primary">`;
    assert.equal(pdfRelativiseServerImgSrc(html, ORIGIN), html);
    /* second pass identical to first */
    assert.equal(pdfRelativiseServerImgSrc(pdfRelativiseServerImgSrc(html, ORIGIN), ORIGIN), html);
});

/* ---- Degenerate inputs return the input unchanged ---- */
test('empty origin returns the html unchanged', () => {
    const html = `<img src="${ORIGIN}/qr.php?data=x">`;
    assert.equal(pdfRelativiseServerImgSrc(html, ''), html);
});

test('a non-string html returns the input unchanged', () => {
    assert.equal(pdfRelativiseServerImgSrc(null, ORIGIN), null);
    assert.equal(pdfRelativiseServerImgSrc(undefined, ORIGIN), undefined);
});

/* ---- Realistic mixed document: a logo <img> AND a permalink caption ---- */
test('a mixed document relativises the logo img but keeps the caption URL text', () => {
    const html =
        `<div class="print-logo" style="text-align:center">`
        + `<img class="print-logo-img" src="${ORIGIN}/org-logo.php?org=3&amp;kind=full&amp;v=deadbeef" alt="Grace Church logo" style="max-height:150px;max-width:100%" onerror="this.style.display='none'"></div>`
        + `<div class="print-qr"><img class="print-qr-img" src="${ORIGIN}/qr.php?data=${encodeURIComponent(ORIGIN + '/song/MP-1')}&amp;format=svg&amp;size=512" alt="QR code linking to this song online">`
        + `<div class="print-qr-caption">${ORIGIN}/song/MP-1</div></div>`;
    const out = pdfRelativiseServerImgSrc(html, ORIGIN);
    assert.ok(out.includes('src="/org-logo.php?org=3&amp;kind=full&amp;v=deadbeef"'), 'logo img relativised');
    assert.ok(out.includes('src="/qr.php?data='), 'qr img relativised');
    assert.ok(out.includes(`<div class="print-qr-caption">${ORIGIN}/song/MP-1</div>`), 'caption URL text intact');
});

console.log('');
if (failed) {
    console.log(`FAIL: ${failed} check(s) failed, ${passed} passed.`);
    process.exit(1);
}
console.log(`OK: pdfRelativiseServerImgSrc relativises the two server-inlined image endpoints for the PDF payload and leaves all other content — including visible URL text — untouched (${passed} checks).`);
process.exit(0);
