<?php

declare(strict_types=1);

/**
 * user_settings.php — the ONE namespaced user-preference store (#1671 F5)
 * ============================================================================
 *
 * ELI5
 * ----
 * Every signed-in user has one small box of settings on their account row. Up
 * to now, saving meant "throw the whole box away and put this in instead". That
 * is fine while exactly one program owns the box. The moment a second program
 * (iLyricsDB) shares the same database, whoever saves last wipes the other's
 * settings. So a save may now name a *shelf* inside the box — a namespace — and
 * only that shelf is replaced. Every other shelf is left exactly as it was.
 *
 * WHY THIS FILE EXISTS
 * --------------------
 * Before #1671 F5 there were TWO user-preference stores doing the same job:
 *
 *   - `user_settings`          → `tblUsers.Settings` (a JSON blob on the user
 *                                row). Live: synced across devices, fed by a
 *                                strict whitelist in `js/modules/settings.js`.
 *   - `user_preferences` /
 *     `user_preferences_sync`  → `tblUserPreferences`, a parallel table. An
 *                                un-namespaced duplicate of exactly the above,
 *                                with **no caller anywhere in the tree** (#310,
 *                                verified by file-walk across appWeb, appApple
 *                                and appAndroid — not by `rg`, which cannot see
 *                                `appWeb/.sql/`).
 *
 * Two stores for one concept is a data-integrity trap, not a dormant feature:
 * whichever one a future client picks, the other silently holds stale truth.
 * The duplicate pair was deleted and its table dropped (see
 * `appWeb/.sql/migrate-drop-user-preferences.php`); `user_settings` survives
 * and gains the write contract below.
 *
 * THE WRITE CONTRACT
 * ------------------
 *   POST ?action=user_settings  { "settings": {...} }                → legacy
 *   POST ?action=user_settings  { "namespace": "ilyricsdb",
 *                                 "settings": {...} }                → merged
 *
 * With a `namespace`, the server performs a **root-level** replace of that one
 * subtree: `$settings[$ns] = $incoming`. Sibling namespaces are untouched.
 *
 * ⚠ THE MERGE IS AT THE NAMESPACE ROOT, AND DELIBERATELY NOT RECURSIVE.
 * A deep recursive merge reads well until you try to DELETE a key: the client
 * sends the subtree without it, the deep merge sees "absent" and keeps the old
 * value, and the key becomes permanently un-deletable with no error anywhere.
 * (That is the same shape as `component_upsert`'s `isset()` clear-semantics
 * trap, project-rules §20.5 — a silent no-op, the failure class this codebase
 * keeps paying for.) Replacing the subtree wholesale means "what you POST is
 * what you GET", which is the only rule a client can reason about.
 *
 * WITHOUT a `namespace` the behaviour is byte-for-byte what it has always been:
 * a whole-blob replace, 16 KB cap, same error text. That is load-bearing —
 * `js/modules/settings.js` and the shipped native apps send no namespace and
 * must keep working untouched.
 *
 * ⚠ THE COROLLARY, STATED PLAINLY: while any client still uses the legacy
 * whole-blob path, that client's next save REPLACES the entire blob and so
 * destroys every sibling namespace. The isolation property below therefore
 * holds only once *every* writer is namespaced. The web client migrating to
 * `namespace='ihymns.web'` is the remaining step; until then, treat a
 * namespaced write as safe against other NAMESPACED writers only.
 *
 * THE PROPERTY THIS BUYS
 * ----------------------
 * iLyricsDB's clients write their own subtree with **zero coordination**
 * against iHymns keys: no shared key-collision convention to police, no
 * lockstep client releases, no migration when either product adds a setting.
 *
 * CAPS ARE REJECTIONS, NEVER TRUNCATIONS
 * --------------------------------------
 * A per-namespace 16 KB cap and a whole-row backstop are enforced by REFUSING
 * the write with HTTP 413. Silently storing a truncated version is how #1649 /
 * #1662 destroyed users' set lists: the server amputated the payload and then
 * treated the amputated version as the truth. A refused write leaves the old
 * value intact and tells the caller; a truncated write loses data quietly.
 *
 * The two caps are separate on purpose. The per-namespace cap is what a client
 * can be expected to respect. The total cap exists only so one authenticated
 * account cannot grow its row without bound by inventing endless namespace
 * names — it is deliberately far above any legitimate use (16× the per-
 * namespace cap), because a *tight* shared budget would recreate exactly the
 * coupling namespaces exist to remove: iLyricsDB filling the row would start
 * failing iHymns' saves.
 *
 * EVERYTHING HERE IS PURE
 * -----------------------
 * No database, no superglobals, no I/O. That is deliberate: these are the
 * behaviours worth pinning (isolation, root-not-deep merge, key deletion, the
 * legacy path, the cap refusals), and a pure function can be CALLED by a test
 * rather than pattern-matched in source. Source inspection proves a file
 * contains the right words; only calling it proves the file gives the right
 * answers. See `tests/php/test-user-settings-namespace.php`.
 *
 * @see appWeb/public_html/api.php  case 'user_settings'
 * @see .claude/remediation-plan-2026-07-30.md §4.5
 * @see https://www.php.net/manual/en/function.json-encode.php
 */

/**
 * Maximum encoded size of ONE namespace subtree, in bytes.
 *
 * 16 KB — the same number the legacy whole-blob path has always used, so a
 * client that migrates from the legacy path to a namespace finds no new
 * constraint on the payload it was already sending.
 */
const USER_SETTINGS_NS_MAX_BYTES = 16384;

/**
 * Backstop on the encoded size of the WHOLE settings blob, in bytes.
 *
 * Not a per-client budget — see the file header. 256 KB is 16 namespaces at
 * the full per-namespace cap, i.e. orders of magnitude above any real use,
 * and exists solely to bound the row against an account inventing namespace
 * names in a loop. `tblUsers.Settings` is a MySQL `JSON` column (max 1 GB, and
 * in practice bounded by `max_allowed_packet`), so this is an abuse ceiling
 * rather than a storage limit — nothing here can overflow the column and
 * throw under `MYSQLI_REPORT_STRICT`.
 *
 * @see https://dev.mysql.com/doc/refman/8.0/en/json.html
 */
const USER_SETTINGS_TOTAL_MAX_BYTES = 262144;

/**
 * Encode a settings value the way the store persists it.
 *
 * ONE encoder, so the bytes a cap is measured against are exactly the bytes
 * that get stored. Measuring with different flags than you store with is how a
 * cap check passes and the write then fails (or vice versa) — the two must be
 * the same call. Flags match what `case 'user_settings'` has always used.
 *
 * @param mixed $value
 */
function userSettingsEncode($value): string
{
    return (string)json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/**
 * Is this a well-formed namespace name?
 *
 * Grammar: `^[a-z][a-z0-9_]{1,31}(\.[a-z][a-z0-9_]{1,31})?\z`
 *   - lower-case ASCII only, so a namespace can never differ from another only
 *     by case (`Apple` vs `apple`) — MySQL JSON keys are case-sensitive but
 *     humans are not;
 *   - 2–32 characters per segment, at most one dot, so `ihymns.web`,
 *     `ilyricsdb` and `apple` are all legal and `a`, `A`, `x.y.z`, `-` are not.
 *
 * ⚠ The pattern ends in `\z`, NOT `$`. PHP's `$` also matches immediately
 * before a trailing newline, so `"ilyricsdb\n"` would otherwise validate and
 * then be stored as a DIFFERENT key from `"ilyricsdb"` — two namespaces that
 * look identical in every log and every JSON dump. `\z` is the true end of
 * subject.
 *
 * @see https://www.php.net/manual/en/regexp.reference.escape.php
 */
function userSettingsNamespaceValid(string $ns): bool
{
    return (bool)preg_match('/^[a-z][a-z0-9_]{1,31}(\.[a-z][a-z0-9_]{1,31})?\z/', $ns);
}

/**
 * Decide whether a namespaced write is acceptable.
 *
 * Returns `null` when the write may proceed, otherwise a machine-readable
 * reason code. The reason code — never the sentence — is what the handler maps
 * to an HTTP status, and what a test asserts on. Prose is for humans; status
 * codes are the contract (rule #35 / #1677: a client that regex-matches the
 * server's wording degrades silently the day somebody rewords it).
 *
 * Reason codes:
 *   'invalid_namespace'   → the name does not match the grammar          (400)
 *   'namespace_too_large' → this subtree alone exceeds the 16 KB cap     (413)
 *   'total_too_large'     → the resulting whole blob exceeds the backstop(413)
 *
 * @param array<string,mixed> $current  the blob as stored today
 * @param array<mixed>        $incoming the subtree the caller wants to store
 */
function userSettingsRejectReason(array $current, string $ns, array $incoming): ?string
{
    if (!userSettingsNamespaceValid($ns)) {
        return 'invalid_namespace';
    }
    if (strlen(userSettingsEncode($incoming)) > USER_SETTINGS_NS_MAX_BYTES) {
        return 'namespace_too_large';
    }
    /* Measure the RESULT, not the input: the write is only oversized if the
       blob it produces is. Checking `$current` alone would refuse a write that
       SHRINKS an already-large namespace, leaving the account permanently
       unable to fix itself — a cap that traps you above it is worse than none. */
    $merged = userSettingsMergeNamespace($current, $ns, $incoming);
    if (strlen(userSettingsEncode($merged)) > USER_SETTINGS_TOTAL_MAX_BYTES) {
        return 'total_too_large';
    }
    return null;
}

/**
 * Replace ONE namespace subtree, leaving every sibling untouched.
 *
 * This is the whole feature, and it is one assignment on purpose. See the file
 * header for why the merge is at the root and not recursive.
 *
 * The `[]` → `stdClass` step is not cosmetic. PHP cannot tell an empty map from
 * an empty list, and `json_encode([])` emits `[]`. Storing that means a client
 * that cleared its namespace reads back a JSON *array* where it wrote an
 * object, so `settings.foo = 1` lands on an array and the next GET disagrees
 * with the last POST. Emitting `{}` keeps the round-trip honest. (The
 * unavoidable cost: a caller who genuinely meant the empty LIST `[]` gets `{}`.
 * There is no representation in PHP that distinguishes them, and an empty
 * settings map is overwhelmingly the intended meaning.)
 *
 * @param array<string,mixed> $current
 * @param array<mixed>        $incoming
 * @return array<string,mixed>
 */
function userSettingsMergeNamespace(array $current, string $ns, array $incoming)
{
    $current[$ns] = $incoming === [] ? new \stdClass() : $incoming;
    return $current;
}

/**
 * Read ONE namespace subtree back out.
 *
 * An absent namespace is `{}`, not an error: a client asking for its own
 * settings before it has ever written any is the normal first-run case, and a
 * 404 there would make every client special-case its own cold start.
 *
 * @param array<string,mixed> $current
 * @return mixed the stored subtree, or an empty object when absent
 */
function userSettingsSubtree(array $current, string $ns)
{
    if (!array_key_exists($ns, $current)) {
        return new \stdClass();
    }
    $value = $current[$ns];
    /* A stored `[]` came from an empty write (or a legacy whole-blob write that
       happened to use this key). Emit `{}` for the same round-trip reason as
       above — the write path never stores a meaningful empty list here. */
    return $value === [] ? new \stdClass() : $value;
}
