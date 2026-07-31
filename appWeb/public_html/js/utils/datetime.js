/**
 * iHymns — server-timestamp parsing (#1671 Batch 8)
 *
 * ELI5: turns the date-and-time strings our server sends into something
 * JavaScript can do arithmetic with, whichever of the two shapes it used.
 *
 * ============================================================================
 * WHY THIS EXISTS AS ITS OWN FILE
 * ============================================================================
 *
 * Two shapes come out of this API and they do not parse the same way:
 *
 *   - MySQL `TIMESTAMP` / `DATETIME` columns render as `2026-07-28 09:00:00` —
 *     a SPACE separator and NO zone. That is not an ISO-8601 string, so
 *     `Date.parse` of it is **implementation-defined**: V8 accepts it, and
 *     other engines historically have not. Worse, an engine that does accept
 *     it may read it as LOCAL time, which silently shifts every "3 days ago"
 *     by the reader's UTC offset.
 *   - Values the PHP side builds with `gmdate('c')` are proper ISO-8601 with a
 *     `Z` or an explicit offset.
 *
 * `devices.js` and `my-song-requests.js` both need this and were about to grow
 * a copy each, which is the duplication the modularity rule exists to stop —
 * two copies of a date normaliser drift into two different notions of "when".
 *
 * ============================================================================
 * WHY THE NORMALISED STRING IS EXPORTED, NOT JUST THE NUMBER
 * ============================================================================
 *
 * Because otherwise the normalisation has NO RUNTIME HANDLE and cannot be
 * guarded. Asserting the parsed epoch proves nothing on V8, which parses the
 * un-normalised string just as happily — a mutation removing the whole
 * normalisation step stayed GREEN when this logic lived inline in `devices.js`
 * and only the epoch was asserted. Exporting the intermediate string makes the
 * property assertable in the one engine the test suite actually runs
 * (`tests/test-batch8-ui-helpers.js` calls it), instead of relying on a
 * browser this container does not have.
 *
 * That is the same lesson as `tests/php/test-transaction-fatal.php`: if a
 * property matters, give it a function that returns it.
 *
 * @see https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/Date/parse
 * @see https://dev.mysql.com/doc/refman/8.0/en/datetime.html
 */

/**
 * Turn a server timestamp into a definitely-ISO-8601 string, or null.
 *
 * The rule, in order:
 *   1. non-string / blank            → null
 *   2. a space separator             → replaced with `T`
 *   3. already carries `Z` or ±HH:MM → left alone (re-appending `Z` would
 *                                      corrupt an offset-bearing value)
 *   4. otherwise                     → `Z` appended, i.e. read as UTC
 *
 * Step 4 is the load-bearing one. Every naked timestamp this API emits comes
 * from a UTC-configured MySQL, so reading it as UTC is correct; reading it as
 * local (which is what an engine does with a zone-less string) makes every age
 * wrong by the reader's offset — a bug that is invisible in London and an hour
 * out in Paris.
 *
 * @param {*} value
 * @returns {string|null} An ISO-8601 string, or null if unusable.
 */
export function normaliseServerTimestamp(value) {
    if (typeof value !== 'string') return null;
    const trimmed = value.trim();
    if (trimmed === '') return null;
    const iso = trimmed.replace(' ', 'T');
    if (iso.endsWith('Z') || /[+-]\d\d:?\d\d$/.test(iso)) return iso;
    return iso + 'Z';
}

/**
 * Parse a server timestamp to epoch milliseconds, or null.
 *
 * Returns null rather than NaN so callers can use a plain `=== null` check.
 * NaN is the value that leaks "Invalid Date" and "NaN days ago" onto a page,
 * because every comparison with it is false and so every guard written as
 * `if (ms > 0)` quietly falls through.
 *
 * @param {*} value
 * @returns {number|null}
 */
export function serverTimestampToMs(value) {
    const iso = normaliseServerTimestamp(value);
    if (iso === null) return null;
    const ms = Date.parse(iso);
    return Number.isFinite(ms) ? ms : null;
}
