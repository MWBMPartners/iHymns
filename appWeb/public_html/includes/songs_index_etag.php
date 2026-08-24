<?php

declare(strict_types=1);

/**
 * iHymns — songs_index version-signal ETag (#1921 server half)
 * ============================================================================
 *
 * ELI5: the PWA re-downloads the WHOLE song catalogue index (id/number/
 * title/songbook/… for every song — a few hundred KB) on every visit, even
 * when nothing at all changed since last time. This file computes a short
 * fingerprint of "has the catalogue changed" from two cheap COUNT/MAX
 * queries — never by re-building the actual index and hashing IT (that would
 * cost exactly what this feature exists to avoid) — so the server can answer
 * "nothing changed, you already have it" (HTTP 304) without ever running the
 * ~14.5k-row query at all.
 *
 * DETAIL — the four folds that make up the ETag, and why each is load-bearing:
 *
 *   1. `signal` (songsIndexVersionSignal()) — CORPUS CONTENT. One statement,
 *      four aggregates: COUNT(*) + MAX(UpdatedAt) over BOTH tblSongs AND
 *      tblSongbooks. No WHERE — deliberately counts hidden/soft-deleted rows
 *      too: a visibility flip is an UPDATE (bumps UpdatedAt), a hard delete
 *      changes COUNT, and a predicate-free scan can never drift from the
 *      read path's own gated predicates (over-invalidation — one spurious
 *      full 200 — is the SAFE direction; under-invalidation is the bug
 *      class, #1921 §A.4). Both tables are read because one cascade
 *      (an Abbreviation rename via `ON UPDATE CASCADE`) skips
 *      `tblSongs.UpdatedAt`'s own `ON UPDATE CURRENT_TIMESTAMP` — it bumps
 *      `tblSongbooks.UpdatedAt` instead, so reading only one table would
 *      miss that class of change.
 *   2. `contractVersion` (apiContractVersion()) — v1 (bare array) and v2
 *      (envelope-wrapped) are DIFFERENT BYTES for the same content; folding
 *      the version INTO the ETag value (not just a `Vary` header) makes a
 *      cross-version 304 structurally impossible.
 *   3. `deployRef` (the deploy-injected commit SHA) — a deploy that changes
 *      the EMIT SHAPE (a new column added to the slim index, say) invalidates
 *      by MECHANISM, never by someone remembering to bump a seed by hand
 *      (rule #35).
 *   4. `shapeToken` (SongData::slimIndexShapeToken()) — the two SCHEMA-STATE
 *      gates the slim-index SQL itself branches on (PublicId column
 *      presence, the visibility predicate) — read via the class's OWN
 *      memoized probes, never a second probe path (rule #35).
 *
 * FAIL-OPEN (rule #28's discipline applied to perf): `songsIndexVersionSignal()`
 * returns null on ANY error (a missing table on some exotic partial install,
 * a transient DB hiccup) — the case in api.php treats null as "don't emit an
 * ETag at all", so the caller gets today's full 200 exactly as before this
 * feature existed. A 304 emits NO body — the cheapest possible read (rule
 * #17: nothing here ever materialises the slim index just to decide whether
 * to send it).
 *
 * @see appWeb/public_html/api.php                 the `songs_index` case
 * @see appWeb/public_html/includes/SongData.php    slimIndexShapeToken()
 * @see appWeb/public_html/song-media.php:296-327   the RFC 7232 §6 precedent this mirrors
 * @see https://www.rfc-editor.org/rfc/rfc7232      HTTP Conditional Requests
 * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/ETag
 * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/If-None-Match
 */

/**
 * ELI5: "has anything in the catalogue changed since we last looked?" — a
 * cheap yes/no-shaped fingerprint, NOT the catalogue itself.
 * WHY one round trip: four scalar sub-selects in a SINGLE statement (one
 * network round trip either way) rather than four separate queries.
 * No bound parameters (no user input reaches this SQL at all), so a plain
 * `query()` is used — the getSongsSlimIndex() unscoped-branch precedent for
 * a statement with nothing to bind.
 *
 * @param  \mysqli $db
 * @return string|null "songsCount|songsMaxUpdated|booksCount|booksMaxUpdated",
 *                       or null on ANY error (fail-open — caller skips the ETag).
 */
function songsIndexVersionSignal(\mysqli $db): ?string
{
    try {
        /* @disabled-visible: @deleted-visible: deliberately predicate-free
           (#1921 §A.4/§A.5) — this is a VERSION SIGNAL, not a content read,
           so it must count hidden/soft-deleted/disabled rows TOO: a
           visibility flip (soft delete #1694, songbook disable #1765) is an
           UPDATE (bumps UpdatedAt) and a hard delete changes COUNT, so
           excluding those rows with the usual songVisibleSql()/
           songServableSql() predicates would make the ETag blind to exactly
           the changes it exists to detect (under-invalidation is the bug
           class here, never over-invalidation — one spurious full 200 for
           an admin-only edit to a hidden song is the safe direction). */
        $res = $db->query(
            "SELECT
                (SELECT COUNT(*) FROM tblSongs) AS songsCount,
                (SELECT COALESCE(MAX(UpdatedAt), '') FROM tblSongs) AS songsMaxUpdated,
                (SELECT COUNT(*) FROM tblSongbooks) AS booksCount,
                (SELECT COALESCE(MAX(UpdatedAt), '') FROM tblSongbooks) AS booksMaxUpdated"
        );
        $row = ($res instanceof \mysqli_result) ? $res->fetch_assoc() : null;
        if ($row === null) {
            return null;
        }
        return $row['songsCount'] . '|' . $row['songsMaxUpdated'] . '|'
             . $row['booksCount'] . '|' . $row['booksMaxUpdated'];
    } catch (\Throwable $_e) {
        return null; // fail-open — the case in api.php serves today's full 200
    }
}

/**
 * ELI5: turn the version signal + the three OTHER things that can change the
 * bytes (contract version / deploy / schema shape) into ONE opaque ETag
 * string a client can round-trip back to us via `If-None-Match` — never
 * something a client should try to parse.
 *
 * @param  string $signal          songsIndexVersionSignal()'s non-null result.
 * @param  int    $contractVersion apiContractVersion()'s result (1 or 2).
 * @param  string $deployRef       The deploy-injected short commit SHA (or '' locally).
 * @param  string $shapeToken      SongData::slimIndexShapeToken()'s result.
 * @return string A quoted ETag value, e.g. `"si1-1a2b3c4d5e6f7890"`.
 */
function songsIndexEtag(string $signal, int $contractVersion, string $deployRef, string $shapeToken): string
{
    return '"si' . $contractVersion . '-' . hash('xxh64', $signal . '|' . $deployRef . '|' . $shapeToken) . '"';
}

/**
 * ELI5: "does the browser's `If-None-Match` header say it already has THIS
 * exact version?" — RFC 7232 §3.2's comparison rules, in miniature.
 * WHY these specific rules: mirrors song-media.php:296-327's precedent
 * (exact-compare + `'*'`), widened to the full RFC shape a real
 * intermediary/cache can send: a COMMA-separated list of validators (any one
 * matching is enough) and an optional `W/` weak-validator prefix on each.
 * Our own ETag is always sent as a strong validator, but honouring `W/` on
 * the INCOMING header costs nothing and avoids a needless full 200 for a
 * client/proxy that weakens it in transit.
 *
 * @param  string $ifNoneMatch The raw `If-None-Match` request header value.
 * @param  string $etag        This response's OWN current ETag (quoted).
 * @return bool True when the client already has this version (send 304).
 */
function songsIndexEtagMatches(string $ifNoneMatch, string $etag): bool
{
    $ifNoneMatch = trim($ifNoneMatch);
    if ($ifNoneMatch === '') {
        return false; // no validator sent -> nothing to match -> full 200
    }
    if ($ifNoneMatch === '*') {
        return true; // RFC 7232 §3.2 — "*" matches any current representation
    }
    foreach (explode(',', $ifNoneMatch) as $candidate) {
        $candidate = trim($candidate);
        if ($candidate === '') {
            continue;
        }
        if (str_starts_with($candidate, 'W/')) {
            $candidate = trim(substr($candidate, 2)); // weak-validator prefix stripped, value compared as-is
        }
        if ($candidate === $etag) {
            return true;
        }
    }
    return false;
}
