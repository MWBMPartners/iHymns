/**
 * iHymns — "is this error response worth showing?" (#1705)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * When a page request comes back as an error, the server sometimes sends a
 * proper explanation ("this song was removed, try searching for the title") and
 * sometimes sends nothing useful at all. This decides which happened, so the app
 * can show the real explanation instead of guessing.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS EXISTS AS A SEPARATE, PURE FUNCTION
 * ---------------------------------------------------------------------------
 * `router.js` used to do this:
 *
 *     if (!response.ok) { throw new Error(`HTTP ${response.status}`); }
 *
 * — never reading the body — and the catch block then rendered
 * "Failed to load page. Please check your connection and try again."
 *
 * Six surfaces send a themed, accessible error card with a helpful message and
 * action buttons, and a truthful status: `pages/song.php` (410 for a removed
 * song, 404 otherwise), `songbook.php`, `person.php`, `work.php`, `tag.php`, and
 * `maintenance.php`'s 503. **Not one of them had ever been seen by a user.** They
 * render perfectly in curl and were discarded by the client every time.
 *
 * The fallback was not merely generic, it was WRONG: it blamed the reader's
 * network for a server that had answered clearly. Somebody following an old link
 * to a merged song was told to check their wifi.
 *
 * That is the #1565 / #1533 silent class — a correct, well-built server side
 * quietly thrown away by the client, with nothing anywhere going red.
 *
 * ---------------------------------------------------------------------------
 * PURE, BECAUSE THE PROPERTY HAS A RUNTIME HANDLE
 * ---------------------------------------------------------------------------
 * The question "should this body be injected?" is a pure function of the status,
 * the content type and whether there is a body at all. So it is written as one,
 * and `tests/test-error-response.js` CALLS it across the whole space rather than
 * grepping `router.js` for the right words.
 *
 * That is not tidiness. Five consecutive rounds on this branch shipped a guard
 * that was green while the thing it guarded was broken, every one of them because
 * source inspection was used as evidence for something a test could simply have
 * called (`tests/php/test-transaction-fatal.php` documents the regress).
 *
 * @see appWeb/public_html/js/modules/router.js   the one consumer
 * @see appWeb/public_html/includes/error_page.php  what the server actually sends
 * @link https://developer.mozilla.org/en-US/docs/Web/API/Response/ok
 */

/**
 * Should the SPA render this errored response's body as page content?
 *
 * ELI5: "did the server actually explain itself, or did it just fail?"
 *
 * TRUE only when all three hold:
 *
 *   1. The status is a real HTTP error (400–599). A 2xx/3xx never reaches this —
 *      the caller handles success — and a bogus status is not trusted.
 *   2. The content type is HTML. This is the load-bearing condition: an errored
 *      **JSON** response must NOT be injected into the DOM. Dumping a JSON error
 *      body into the page would show a reader `{"error":"..."}` — worse than the
 *      generic alert it replaced. Fragments are `text/html`; API errors are
 *      `application/json`; the two are told apart by the header the server
 *      already sets, never by sniffing the body.
 *   3. There is actually a body. A zero-length 404 carries no explanation, so the
 *      generic alert is the honest answer for it.
 *
 * A network failure never gets here at all — `fetch()` rejects, so there is no
 * response to ask about, and the caller's catch keeps showing "check your
 * connection", which for a genuine network failure is exactly right.
 *
 * NOTE the deliberate absence: this does NOT look at the body's text. Branching
 * on prose is the rule #35 anti-pattern — reword a server string and the
 * behaviour silently changes with nothing going red. Status and content-type are
 * the contract.
 *
 * @param {number}      status      HTTP status from the Response.
 * @param {string|null} contentType The raw `Content-Type` header, or null.
 * @param {number}      bodyLength  Length of the body text already read.
 * @returns {boolean}
 */
export function shouldRenderErrorBody(status, contentType, bodyLength) {
    /* Number.isFinite, not a truthiness test: a status of 0 (opaque response) and
       NaN (a header that failed to parse) must both answer false, and `!status`
       would also reject a legitimate... well, nothing — but the explicit range
       check below is what actually decides, and it needs a real number. */
    if (!Number.isFinite(status) || status < 400 || status > 599) {
        return false;
    }
    if (typeof bodyLength !== 'number' || bodyLength <= 0) {
        return false;
    }
    if (typeof contentType !== 'string' || contentType === '') {
        return false;
    }
    /* Case-insensitive and parameter-tolerant: a real header is
       `text/html; charset=UTF-8`, and some servers capitalise the type. Matching
       the prefix of the MIME type only — `indexOf` rather than equality — is what
       makes the charset parameter harmless. */
    return contentType.toLowerCase().indexOf('text/html') === 0;
}
