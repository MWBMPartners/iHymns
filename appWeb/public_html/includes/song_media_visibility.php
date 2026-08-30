<?php

declare(strict_types=1);

/**
 * iHymns — tblSongMedia.Visibility serving gate + vocabulary (#1968 P4)
 * ================================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * A media row can be marked "admin only" (a curator uploaded or imported it but
 * hasn't published it yet). This file is the ONE place that answers "may this be
 * shown/served publicly?" — for a whole list (a SQL filter) AND for one file's
 * bytes (a PHP decision) — so the two grains can never disagree (rule #35).
 *
 * DETAILED — WHY A REAL GATE, NOT JUST A HIDDEN FIELD (rule #28's lesson)
 * ----------------------------------------------------------------------------
 * Owner decision D1 (#1968 P4): ProPresenter-imported media lands `admin` and is
 * served publicly only once a curator publishes it. Stripping a row from a list
 * payload HIDES an affordance; it does NOT protect the file — a URL-addressable
 * asset (`/song-media/<id>`) needs its own byte gate, and the two must resolve
 * through the SAME vocabulary so they cannot diverge. Hence both:
 *
 *   • `songMediaVisibilityPublicFilterSql()` — the LIST gate (a residual SQL
 *     predicate every public `SELECT … FROM tblSongMedia` appends), and
 *   • `songMediaVisibilityRowAllowed()`      — the BYTES gate (the per-row
 *     PHP decision `song-media.php` runs before streaming).
 *
 * INVARIANT — ALWAYS ACTIVE, but a NO-OP for `public` rows and un-migrated
 * installs. Deliberately NOT behind `content_gating_enabled` (D1 must hold on
 * every live env today, gating off — this is an EDITORIAL publish state, not a
 * tier cap; rule #28's caps answer a different, per-viewer×per-kind question and
 * only act when gating is enabled). It is a verified no-op for all CURRENT
 * content because every existing row is `'public'` (the migration's NOT NULL
 * DEFAULT), and it degrades to `''` (no filter) when the column is absent. That
 * degradation is safe because the WRITER refuses to mint a non-`public` row on
 * an un-migrated install (song_importers.php's ingest dormancy gate) — read gate
 * and write gate degrade in LOCKSTEP, the shape of rule #25's
 * lyricLinesMirrorPresent/lyricLinesSyncReady pairing.
 *
 * FAIL-CLOSED ON THE SERVE AXIS: anything NOT recognised as public (any unknown
 * future value a newer channel wrote — `org`, `pending`, …) is treated as
 * NON-public by `songMediaVisibilityRowAllowed()`. This is the deliberate
 * OPPOSITE of the gating module's fail-open, because here the row was explicitly
 * marked non-public by a writer that knew the vocabulary — the licensing-safe
 * direction.
 *
 * VARCHAR, not ENUM (rule #20): the vocabulary lives ONCE in
 * `IHYMNS_SONG_MEDIA_VISIBILITIES`; `org` (org-members-only) and `pending`
 * (a review queue) are each a one-line addition here plus serve-rule code, never
 * an ALTER.
 *
 * Direct access is blocked (same guard as song_media_flags.php /
 * publisher_helpers.php) so this file can't be requested as an endpoint.
 *
 * @see .claude/propresenter-interop-1968-plan.md §6.1, §6.3
 * @see appWeb/public_html/includes/song_media_flags.php   the house pattern this mirrors
 * @see appWeb/public_html/song-media.php                  the bytes-gate consumer
 * @see appWeb/public_html/includes/SongData.php           _songMediaMap() / getSongDetailExtras() list consumers
 * @see #1968 P4, issue #1976
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

if (!defined('IHYMNS_SONG_MEDIA_VISIBILITIES')) {
    /**
     * The ONE publish-state vocabulary (key => friendly label). Growable —
     * `org` / `pending` are reserved future values; adding one is a single line
     * here plus its serve rule, never an ALTER (rule #20). Anything NOT a key of
     * this map is invalid on write and treated as non-public on serve.
     */
    define('IHYMNS_SONG_MEDIA_VISIBILITIES', [
        'public' => 'Public',
        'admin'  => 'Admin only',
    ]);
}

if (!function_exists('songMediaVisibilityColumnExists')) {
    /**
     * Memoised INFORMATION_SCHEMA probe for the `tblSongMedia.Visibility`
     * column (STRICT-safe on un-migrated installs — the
     * `_songMediaFlagsTableExists()` shape). Degrades to "absent" on any probe
     * failure (rule #9's safe direction).
     */
    function songMediaVisibilityColumnExists(\mysqli $db): bool
    {
        static $exists = null;
        if ($exists !== null) { return $exists; }
        try {
            $r = $db->query(
                "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME   = 'tblSongMedia'
                    AND COLUMN_NAME  = 'Visibility'
                  LIMIT 1"
            );
            $exists = $r && $r->fetch_row() !== null;
            if ($r) { $r->close(); }
        } catch (\Throwable $_e) {
            $exists = false;
        }
        return $exists;
    }
}

if (!function_exists('songMediaVisibilityPublicFilterSql')) {
    /**
     * The LIST gate: a residual SQL predicate that restricts a
     * `… FROM tblSongMedia …` SELECT to publicly-servable rows, or `''` on an
     * un-migrated install (so the query is byte-identical to before P4).
     *
     * The literal `'public'` and the caller's `$alias` are HARDCODED CONSTANTS
     * (a fixed column-name prefix from source, never user input) — the only
     * legitimate string interpolation into SQL (rule #5, exception a).
     *
     * @param string $alias e.g. 'm.' when the table is aliased; '' otherwise.
     */
    function songMediaVisibilityPublicFilterSql(\mysqli $db, string $alias = ''): string
    {
        if (!songMediaVisibilityColumnExists($db)) { return ''; }
        return " AND {$alias}Visibility = 'public'";
    }
}

if (!function_exists('songMediaVisibilitySelectFragment')) {
    /**
     * A `', Visibility'` SELECT-list fragment for a FIXED-column SELECT that
     * wants the value (the byte gate, the admin list badge), or `''` when the
     * column is absent — the `SongData::_songbookDisplayAbbrSelect()` precedent.
     *
     * @param string $alias e.g. 'm.' when the table is aliased; '' otherwise.
     */
    function songMediaVisibilitySelectFragment(\mysqli $db, string $alias = ''): string
    {
        if (!songMediaVisibilityColumnExists($db)) { return ''; }
        return ", {$alias}Visibility";
    }
}

if (!function_exists('songMediaVisibilityIsValid')) {
    /**
     * The write-side vocabulary check — never an inline list at a call site.
     */
    function songMediaVisibilityIsValid(string $v): bool
    {
        return array_key_exists($v, IHYMNS_SONG_MEDIA_VISIBILITIES);
    }
}

if (!function_exists('songMediaVisibilityRowAllowed')) {
    /**
     * The BYTES gate: may a viewer with role `$viewerRole` be served a row whose
     * stored `Visibility` is `$rowVisibility`? PURE except for the entitlement
     * lookup.
     *
     *  - NULL / '' / 'public'  → true  (every existing row; anonymous OK).
     *  - anything else ('admin', or any unknown future value) → true ONLY when
     *    the viewer holds the `edit_songs` entitlement (a curator). Fail-closed:
     *    an unknown value never becomes public, and an absent/empty role or an
     *    environment without the entitlements module both deny.
     *
     * Deliberately does NOT require entitlements.php itself (so it can be unit-
     * tested with a stubbed `userHasEntitlement`, and to avoid a load-order
     * dependency); the byte-serving caller loads it.
     *
     * @param ?string $rowVisibility the row's stored Visibility (or NULL/'' pre-migration)
     * @param ?string $viewerRole    the resolved viewer's role, or NULL if anonymous
     */
    function songMediaVisibilityRowAllowed(?string $rowVisibility, ?string $viewerRole): bool
    {
        $v = strtolower(trim((string)$rowVisibility));
        if ($v === '' || $v === 'public') { return true; }
        if ($viewerRole === null || $viewerRole === '') { return false; }
        if (!function_exists('userHasEntitlement')) { return false; } // fail closed
        return userHasEntitlement('edit_songs', $viewerRole);
    }
}
