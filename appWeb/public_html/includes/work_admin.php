<?php

declare(strict_types=1);

/**
 * iHymns — Work identity write core: find-or-link by ISWC/CCLI (#1860 Phase 3)
 * ================================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * A curator types a CCLI number or an ISWC into a song's Metadata tab. This
 * file answers "does a `tblWorks` row for that composition already exist? If
 * so, link this song to it (and tidy up the family tree if needed). If not,
 * make one." — the ONE place that decision gets made, the same way
 * `tune_helpers.php`'s `tuneFindOrCreateByName()` is the ONE place a tune
 * name resolves to a `tblTunes` row. File naming mirrors
 * `publisher_admin.php` / `org_logo_admin.php` (rule #22 — one shared core,
 * every caller delegates, nobody re-forks the lookup).
 *
 * DETAILED / WHY (design doc `.claude/ilyrics-internal-ids-work-model-plan.md`
 * §3.3/§3.4, build spec `work-model-spec.md` §2)
 * ----------------------------------------------------------------------------
 * ISWC identifies a COMPOSITION; CCLI (SongSelect) numbers a specific
 * ARRANGEMENT/EDITION of it — so "Amazing Grace" (the composition, one ISWC)
 * and "Amazing Grace (My Chains Are Gone)" (a distinct CCLI-numbered
 * arrangement) are legitimately two `tblWorks` rows, linked by
 * `ParentWorkId`. The precedence rule the owner set (design §3.3, §5.4):
 * a hard ISWC match ALWAYS wins the parent slot; a CCLI work is always a
 * CHILD of its ISWC parent, never the reverse ("adopt-first-CCLI-onto-the-
 * ISWC-parent" was explicitly rejected) — because adopting whichever CCLI
 * arrived FIRST would make the resulting graph depend on save ORDER, and
 * the owner's requirement is that it must not: any entry sequence for the
 * same facts converges to the identical graph (`workLinkPlan()`'s T13 test
 * proves this by executing three different orders and diffing the result).
 *
 * The decision logic is split PURE-from-I/O, the same shape
 * `song_relocate.php`'s `songRelocateCascadeGaps()` / `…Verdict()` split
 * uses: `workLinkPlan()` below takes "what the DB currently holds" (two
 * possibly-null `tblWorks` rows + the song's current memberships) and
 * returns "the writes to perform" as a plain op list — no `\mysqli` inside
 * it anywhere, so the §3.3 decision table can be CALLED in a test rather
 * than read out of the source (rule #34;
 * `tests/php/test-work-link-plan.php`). `workFindOrLinkByIdentifier()` is
 * the I/O wrapper: read state → `workLinkPlan()` → execute the op list
 * inside the CALLER's transaction (this file opens no BEGIN/COMMIT
 * anywhere — the `songRelocate()` contract, `song_relocate.php:159-166`)
 * → re-read the truth from what was actually stored before answering (rule
 * #35 — a client renders its badge from the SERVER's truth, never from
 * what it sent).
 *
 * SIGNATURE DELTA FROM THE DESIGN DOC AS FIRST DRAFTED (recorded here per
 * the build spec §8, and in the design doc's own §3.4): `workLinkPlan()`
 * carries an ADDITIONAL, optional 5th `$songMemberships` parameter, plus a
 * `unlink_song` "refinement" op the decision table's row D describes. Why:
 * design §3.3.5's "the song links to the MOST SPECIFIC work only, never
 * double-linked" and the order-independence invariant are UNSATISFIABLE
 * without it. Concretely — a song saved with ONLY an ISWC first links to
 * the ISWC parent; if a CCLI is added on a LATER save, the correct outcome
 * is the song ends up linked to the CHILD ONLY (the parent is still
 * reachable by `ParentWorkId` traversal) — exactly as if both identifiers
 * had been entered in the SAME save. A plan that only ever ADDS links (a
 * bare `INSERT … ON DUPLICATE KEY UPDATE`) would leave the song
 * double-linked to BOTH the parent and the child, and — because a
 * both-at-once save never creates that parent membership in the first
 * place — the two entry orders would produce DIFFERENT final graphs. The
 * refinement is scoped narrowly on purpose: it only ever removes a DIRECT
 * membership in the immediate parent of a work the song was JUST linked to
 * as a child, never walks further up the tree, and never touches a
 * membership in an unrelated work — this is a SPECIFICITY MOVE (the song
 * is still "part of" the parent work by traversal), not the auto-unlink
 * design §3.3.1 forbids (that rule is about identifier CLEARING, a
 * different case entirely — clearing never removes ANY membership, rows 1
 * and the D-row's own scoping both hold that line).
 *
 * THE CONFLICT BRANCH (design §3.3 row 3's third bullet) is intentionally
 * CONSERVATIVE: when the CCLI work is already parented under some work that
 * is NOT the entered ISWC's parent — whether that other parent is itself
 * ISWC-keyed or a hand-curated suite with no ISWC at all — this core
 * refuses to re-home it AND refuses to mint an orphan ISWC parent for
 * nothing. It still links the song to the CCLI work (the identifier match
 * is still real) and reports `conflict` as a STRING FIELD on the 200
 * response, never an HTTP failure (design §3.3 preamble) — a work-link
 * ambiguity must never cost a curator their song save.
 *
 * WHAT THIS FILE DOES **NOT** DO (build spec §5 — explicit non-goals of
 * this commit; do not "helpfully" add any of these here):
 *   - No corpus backfill, no importer-funnel wiring (song_importers.php /
 *     lyrics_ingest.php) — those are later phases that call the SAME core.
 *   - No `tblWorkExternalIds` reader/writer — dormant until a
 *     WORK_IDENTIFIER_TYPES storage entry flips to it.
 *   - No medley (`tblWorkComponents`) attach/detach or cycle-guard helpers
 *     — Phase 5, shipping WITH the `/manage/works` constituent editor that
 *     consumes them (see the design note at the bottom of this file).
 *   - No `SourceWorkId` writer — Phase 5, with the Structure-tab picker.
 *   - No auto-unlink of any kind on identifier CLEARING (design §3.3.1).
 *     The refinement above is the ONLY membership removal the auto path
 *     may plan, and only ever direct-parent → child.
 *
 * Direct HTTP access is blocked (the `tune_helpers.php` / `media_identifiers.php`
 * idiom) so this file can't be requested as an endpoint via an open Apache
 * config.
 *
 * @link .claude/ilyrics-internal-ids-work-model-plan.md §3.3/§3.4/§3.5/§3.6/§3.6b  the design this file implements
 * @link appWeb/public_html/includes/tune_helpers.php  the find-or-create model this mirrors (tuneFindOrCreateByName())
 * @link appWeb/public_html/includes/song_relocate.php  the pure-decision-core / caller-owns-the-transaction model this mirrors
 * @link appWeb/public_html/includes/ilyrics_id.php  ilidAllocate() — the ONE IL-id allocator, reused (never re-forked) to mint ILW ids
 * @link appWeb/public_html/manage/editor/api2.php  work_search / song_work_autolink / song_work_set — the three callers
 * @see #1860
 */

/* Direct-hit guard: this is a library, never a page (mirrors tune_helpers.php). */
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'identifier_normalize.php';   // ihymns_canonical_iswc() / ihymns_canonical_ccli() — rule #22, never re-fork
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ilyrics_id.php';            // ilidAllocate() / ilidSequenceReady() — rule #22, never re-fork
/* #1988 (API-coverage — Works "extras") — the four side-effect-free libs
   the extras/origin-city write cores below need. Guarded/idempotent like
   every other require_once in this file; none of the four define an
   auto-run side effect on inclusion (rule #22 — reuse, never re-fork). */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'media_identifiers.php';     // mediaIdentifierWorkValidate() — workParseExtraFields()'s CCLI/MBID shape check
require_once __DIR__ . DIRECTORY_SEPARATOR . 'tune_helpers.php';          // tuneFindOrCreateByName() — TuneName<->TuneId lockstep in workPersistExtraFields()
require_once __DIR__ . DIRECTORY_SEPARATOR . 'publisher_helpers.php';     // publisherResolvePickedOrCreate() — CopyrightHolder<->CopyrightHolderId lockstep
require_once __DIR__ . DIRECTORY_SEPARATOR . 'places.php';                // placeColumnExists() — the generic INFORMATION_SCHEMA column probe, used for OriginCityId

/**
 * ELI5: "has this install applied the migrations this file needs?" — asked
 * once per request and remembered, the same `static`-memoised idiom
 * `tuneTunesTableExists()` uses.
 *
 * DETAILED: `tblWorks` alone is not enough — the plumbing here also needs
 * `tblWorks.Ccli` (added by the SEPARATE `works-identity` migration card;
 * design §3.1 correction 1) and `tblWorkSongs` (created alongside
 * `tblWorks` by the `works` card). Migrations are web-run, never
 * auto-applied on deploy, so an install can genuinely have `tblWorks`
 * without `Ccli` — this is the ONE not-ready gate every public function
 * below funnels through before touching the database.
 *
 * @param \mysqli $db
 * @return bool
 */
function workAdminReady(\mysqli $db): bool
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    try {
        $stmt = $db->prepare(
            "SELECT
                (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblWorks')      AS HasWorks,
                (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblWorks'
                    AND COLUMN_NAME = 'Ccli')                                       AS HasCcli,
                (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblWorkSongs')  AS HasWorkSongs"
        );
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $cached = $row !== null
            && (int)$row['HasWorks'] > 0
            && (int)$row['HasCcli'] > 0
            && (int)$row['HasWorkSongs'] > 0;
    } catch (\Throwable $e) {
        $cached = false;
    }
    return $cached;
}

/**
 * ELI5: "does `tblWorks.IlId` exist yet on this install?" — Phase 1
 * (`migrate-ilyrics-internal-ids.php`) and Phase 3 (this file's callers,
 * `works`/`works-identity`) are independent migration cards that can be
 * applied in EITHER order, so create-a-work here cannot assume the `IlId`
 * column is present just because `workAdminReady()` passed.
 *
 * @param \mysqli $db
 * @return bool
 */
function _workAdminIlIdColumnExists(\mysqli $db): bool
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    try {
        $stmt = $db->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblWorks' AND COLUMN_NAME = 'IlId' LIMIT 1"
        );
        $stmt->execute();
        $cached = $stmt->get_result()->fetch_row() !== null;
        $stmt->close();
    } catch (\Throwable $e) {
        $cached = false;
    }
    return $cached;
}

/**
 * Title -> URL-safe slug fold. EXTRACTED from `manage/works.php`'s
 * `$slugFor` closure — byte-equivalent output, so a Work minted by the
 * auto-linker and a Work minted by hand through `/manage/works` land on
 * identical slugs for identical titles. `manage/works.php`'s own
 * `$slugFor` now DELEGATES to this function (the exact `$validateIswc` ->
 * `ihymns_canonical_iswc()` precedent already in that file, rule #22).
 *
 * ELI5: turns a work title into something safe to put in a URL — lowercase,
 * punctuation and spaces become hyphens.
 *
 * DETAILED: deliberately does NOT apply a fallback for a degenerate
 * (all-punctuation / all-non-ASCII) title that folds to `''` — that
 * matches `$slugFor`'s original behaviour, where `works.php`'s manual
 * create/update paths treat an empty result as a hard validation error the
 * curator must fix by hand. The AUTO path (`workFindOrLinkByIdentifier()`
 * below) applies its OWN `'work'` fallback at the call site instead,
 * mirroring `ihymns_tune_slugify()`'s `'tune'` fallback — an unattended
 * auto-link must never fail a song save over an unlucky title.
 *
 * @param string $title
 * @return string May be `''` for a degenerate input — see above.
 */
function workSlugify(string $title): string
{
    return trim(strtolower((string)preg_replace('/[^A-Za-z0-9]+/u', '-', $title)), '-');
}

/**
 * Collision-suffix loop for `tblWorks.Slug` (`VARCHAR(80)`) — the
 * `tuneSlugEnsureUnique()` shape (`tune_helpers.php:366-389`), never a
 * second copy (rule #22). Tries the bare slug, then `-2`, `-3`, … until
 * `uq_slug` isn't violated.
 *
 * @param \mysqli  $db
 * @param string   $base      Already-slugified candidate (`workSlugify()`), NOT re-slugified here.
 * @param int|null $excludeId `tblWorks.Id` to exclude (a row re-saving its own slug), or null.
 * @return string A slug free of `uq_slug` collisions at the moment this returns
 *                (a genuine concurrent-insert race is still possible — see the
 *                1062-retry note on `workFindOrLinkByIdentifier()`).
 */
function workSlugEnsureUnique(\mysqli $db, string $base, ?int $excludeId = null): string
{
    $slug = $base;
    $n    = 1;
    $stmt = $excludeId !== null
        ? $db->prepare('SELECT 1 FROM tblWorks WHERE Slug = ? AND Id <> ? LIMIT 1')
        : $db->prepare('SELECT 1 FROM tblWorks WHERE Slug = ? LIMIT 1');
    while (true) {
        if ($excludeId !== null) {
            $stmt->bind_param('si', $slug, $excludeId);
        } else {
            $stmt->bind_param('s', $slug);
        }
        $stmt->execute();
        $taken = $stmt->get_result()->fetch_row() !== null;
        if (!$taken) {
            break;
        }
        $n++;
        /* Slug is VARCHAR(80); 74 + '-99999' (6 chars) fits exactly. */
        $slug = substr($base, 0, 74) . '-' . $n;
    }
    $stmt->close();
    return $slug;
}

/* ============================================================================
 * THE PURE DECISION CORE
 * ========================================================================== */

/**
 * The §3.3 decision table, implemented EXACTLY (plus the §3.4-delta
 * refinement, row D) — PURE, no `\mysqli` anywhere in this function, the
 * `songRelocateCascadeVerdict()` discipline: the decision is CALLED in a
 * test (`tests/php/test-work-link-plan.php`), never read out of the
 * source.
 *
 * ELI5: "given what's already in the database, and what identifiers this
 * song just got, what writes should happen?" — this function only ever
 * PLANS those writes; it never talks to MySQL itself.
 *
 * DETAILED — the op vocabulary the I/O executor (`workFindOrLinkByIdentifier()`
 * below) resolves in ORDER (a `create_work` op earlier in the list mints
 * the id a later op's `parentRef`/`targetRef` points at):
 *
 *   ['op'=>'create_work', 'ref'=>'parent', 'iswc'=>string]
 *   ['op'=>'create_work', 'ref'=>'child', 'ccli'=>string,
 *        'parentRef'=>'parent'|null, 'parentId'=>?int]
 *   ['op'=>'set_parent', 'workId'=>int, 'parentRef'=>'parent'|null, 'parentId'=>?int]
 *   ['op'=>'link_song', 'targetRef'=>'parent'|'child'|null, 'targetId'=>?int]
 *   ['op'=>'unlink_song', 'workId'=>int]   -- refinement ONLY (row D), never identifier-clearing
 *
 * THE INVARIANT this table yields (design §3.3, verbatim requirement): the
 * plan NEVER writes a `Ccli` onto an ISWC-keyed parent row, and NEVER
 * writes an `Iswc` onto a CCLI-keyed child row — one work row per distinct
 * CCLI, one parent row per distinct ISWC, and any entry SEQUENCE for the
 * same eventual facts converges to the identical graph (memberships
 * included) — proven by execution, not by reading the source, in
 * `tests/php/test-work-link-plan.php`'s T13.
 *
 * @param ?array $byIswc  tblWorks row found by Iswc = $iswc (keys: Id, ParentWorkId,
 *                        Iswc, Ccli, Title, Slug), or null.
 * @param ?array $byCcli  tblWorks row found by Ccli = $ccli (same keys), or null.
 * @param string $ccli    Canonical CCLI ('' = absent) — already through ihymns_canonical_ccli().
 * @param string $iswc    Canonical ISWC ('' = absent) — already through ihymns_canonical_iswc();
 *                        a malformed input (null from the fold) is resolved by the CALLER to
 *                        '' + an iswcInvalid flag, so this function never sees null.
 * @param array  $songMemberships Current tblWorkSongs rows for the song, each
 *                        ['WorkId'=>int, 'ParentWorkId'=>?int] (ParentWorkId of the member
 *                        WORK, joined by the caller). Default [] = plan without refinement.
 * @return array{ops: list<array>, conflict: ?string}
 */
function workLinkPlan(?array $byIswc, ?array $byCcli, string $ccli, string $iswc, array $songMemberships = []): array
{
    /* member(x): is the song already a DIRECT tblWorkSongs member of work x? */
    $member = static function (int $workId, array $memberships): bool {
        foreach ($memberships as $m) {
            if ((int)($m['WorkId'] ?? 0) === $workId) {
                return true;
            }
        }
        return false;
    };
    /* covered(x): member(x), OR the song is already a member of some MORE
       SPECIFIC child of x (a membership row whose OWN ParentWorkId is x) —
       a more-specific membership already represents the song under x, so
       linking directly to x too would be a redundant, less-specific link. */
    $covered = static function (int $workId, array $memberships) use ($member): bool {
        if ($member($workId, $memberships)) {
            return true;
        }
        foreach ($memberships as $m) {
            if (isset($m['ParentWorkId']) && (int)$m['ParentWorkId'] === $workId) {
                return true;
            }
        }
        return false;
    };
    /* Row D — refinement: after linking the song to a CHILD whose parent is
       $parentId, drop any DIRECT membership the song still holds in that
       EXACT parent (a specificity move, not an unlink — §3.3.1 is about
       identifier CLEARING and is untouched by this). $parentId is null
       whenever the parent was JUST minted in this very plan (a 'parent' ref
       with no numeric id yet) — such an id cannot possibly appear in
       $songMemberships (rows read from the DB before this plan ran), so the
       loop is then structurally a no-op; kept uniform rather than
       special-cased so every row that ends in a child link (5/6/8/9) shares
       one code path. Direct parent only: never walks further up, never
       touches a membership in an unrelated work. */
    $refine = static function (?int $parentId, array $memberships) use ($member): array {
        if ($parentId === null) {
            return [];
        }
        return $member($parentId, $memberships) ? [['op' => 'unlink_song', 'workId' => $parentId]] : [];
    };

    /* Row 1 — both empty: no-op. The auto-linker NEVER auto-unlinks on
       cleared identifiers (§3.3.1). */
    if ($iswc === '' && $ccli === '') {
        return ['ops' => [], 'conflict' => null];
    }

    /* Rows 2/3 — ISWC only. */
    if ($ccli === '') {
        if ($byIswc === null) {
            return [
                'ops' => [
                    ['op' => 'create_work', 'ref' => 'parent', 'iswc' => $iswc],
                    ['op' => 'link_song', 'targetRef' => 'parent', 'targetId' => null],
                ],
                'conflict' => null,
            ];
        }
        $pId = (int)$byIswc['Id'];
        $ops = $covered($pId, $songMemberships)
            ? []
            : [['op' => 'link_song', 'targetRef' => null, 'targetId' => $pId]];
        return ['ops' => $ops, 'conflict' => null];
    }

    /* Rows 10/11 — CCLI only. */
    if ($iswc === '') {
        if ($byCcli === null) {
            return [
                'ops' => [
                    ['op' => 'create_work', 'ref' => 'child', 'ccli' => $ccli, 'parentRef' => null, 'parentId' => null],
                    ['op' => 'link_song', 'targetRef' => 'child', 'targetId' => null],
                ],
                'conflict' => null,
            ];
        }
        $cId = (int)$byCcli['Id'];
        $ops = $member($cId, $songMemberships)
            ? []
            : [['op' => 'link_song', 'targetRef' => null, 'targetId' => $cId]];
        return ['ops' => $ops, 'conflict' => null];
    }

    /* Both ISWC and CCLI present — rows 4-9. */
    if ($byCcli !== null) {
        $cId     = (int)$byCcli['Id'];
        $cParent = $byCcli['ParentWorkId'] !== null ? (int)$byCcli['ParentWorkId'] : null;

        /* Row 4 — one hand-curated work carries BOTH ids. No create, no set_parent. */
        if ($byIswc !== null && $cId === (int)$byIswc['Id']) {
            $ops = $member($cId, $songMemberships)
                ? []
                : [['op' => 'link_song', 'targetRef' => null, 'targetId' => $cId]];
            return ['ops' => $ops, 'conflict' => null];
        }

        /* Row 5 — C exists standalone: RE-HOME it under P (minting P first
           if it doesn't exist yet). Covers "CCLI first, ISWC later" —
           precedence holds regardless of entry order. */
        if ($cParent === null) {
            $ops = [];
            $parentRef = null;
            $parentId  = null;
            if ($byIswc === null) {
                $ops[] = ['op' => 'create_work', 'ref' => 'parent', 'iswc' => $iswc];
                $parentRef = 'parent';
            } else {
                $parentId = (int)$byIswc['Id'];
            }
            $ops[] = ['op' => 'set_parent', 'workId' => $cId, 'parentRef' => $parentRef, 'parentId' => $parentId];
            if (!$member($cId, $songMemberships)) {
                $ops[] = ['op' => 'link_song', 'targetRef' => null, 'targetId' => $cId];
            }
            $ops = array_merge($ops, $refine($parentId, $songMemberships));
            return ['ops' => $ops, 'conflict' => null];
        }

        /* Row 6 — already correctly parented under P. */
        if ($byIswc !== null && $cParent === (int)$byIswc['Id']) {
            $ops = $member($cId, $songMemberships)
                ? []
                : [['op' => 'link_song', 'targetRef' => null, 'targetId' => $cId]];
            $ops = array_merge($ops, $refine((int)$byIswc['Id'], $songMemberships));
            return ['ops' => $ops, 'conflict' => null];
        }

        /* Row 7 — CONFLICT: C is parented under some OTHER work (an ISWC
           parent that isn't P, or a hand-curated parent when P doesn't even
           exist). Conservative on purpose: C's existing parent may be a
           hand-curated suite with no ISWC of its own — re-homing it, or
           minting an orphan ISWC parent for nothing, would destroy curator
           structure. Still links the song to C (the identifier match is
           real); no set_parent, no create_work. */
        $ops = $member($cId, $songMemberships)
            ? []
            : [['op' => 'link_song', 'targetRef' => null, 'targetId' => $cId]];
        $conflict = sprintf(
            'Work #%d (CCLI %s) is already parented under work #%d, which does not match the entered ISWC %s. Not re-homed — review at /manage/works.',
            $cId,
            $ccli,
            (int)$cParent,
            $iswc
        );
        return ['ops' => $ops, 'conflict' => $conflict];
    }

    /* $byCcli === null from here. */

    /* Row 8 — CCLI missing, ISWC parent exists: create the child under it. */
    if ($byIswc !== null) {
        $pId = (int)$byIswc['Id'];
        $ops = [
            ['op' => 'create_work', 'ref' => 'child', 'ccli' => $ccli, 'parentRef' => null, 'parentId' => $pId],
            ['op' => 'link_song', 'targetRef' => 'child', 'targetId' => null],
        ];
        $ops = array_merge($ops, $refine($pId, $songMemberships));
        return ['ops' => $ops, 'conflict' => null];
    }

    /* Row 9 — neither exists: mint both, link to the CHILD only (§3.3.5 —
       most-specific-only); parent-level listings derive by ParentWorkId
       traversal. */
    $ops = [
        ['op' => 'create_work', 'ref' => 'parent', 'iswc' => $iswc],
        ['op' => 'create_work', 'ref' => 'child', 'ccli' => $ccli, 'parentRef' => 'parent', 'parentId' => null],
        ['op' => 'link_song', 'targetRef' => 'child', 'targetId' => null],
    ];
    /* Refinement D against the freshly-minted parent: structurally always a
       no-op today (see $refine's doc-comment above) — kept for uniformity. */
    $ops = array_merge($ops, $refine(null, $songMemberships));
    return ['ops' => $ops, 'conflict' => null];
}

/* ============================================================================
 * THE I/O EXECUTOR + WRAPPER
 * ========================================================================== */

/**
 * INSERT-or-UPDATE the `tblWorkSongs` membership row (idempotent — the
 * `(WorkId, SongId)` PK, `schema.sql:3200`, makes a re-save a structural
 * no-op). The ONE place this exact statement is written — both
 * `workLinkPlan()`'s executed `link_song` ops and the manual
 * `song_work_set` "link to an existing work" path (api2.php) call this,
 * never a second copy.
 *
 * #1860 go-live — ALSO the ONE place canonical-member promotion happens:
 * the first member of a canonical-less work becomes `IsCanonical = 1`.
 * Extracted from the legacy save-path block (pre-#1860 `save_song_core.php`,
 * which did this inline for its ISWC-only fork and nowhere else) into this
 * shared writer so EVERY route that links a song to a Work — the auto-linker
 * via `workLinkPlan()`, AND the manual `song_work_set` picker — gets the
 * same sensible default: a one-member work always has a canonical member.
 * Real readers ORDER BY `IsCanonical` (`SongData.php` :5366/:5412/
 * :5650-5656`, `manage/works.php` :844-859`) for "Part of work" display
 * order — losing this would regress that ordering. Promoting only on ONE
 * entry route (the save path, as the legacy block did) would leave a
 * manually-linked work with no canonical member at all; an invariant either
 * holds at the ONE writer or it isn't an invariant.
 *
 * @param \mysqli $db
 * @param int     $workId
 * @param string  $songId
 */
function workLinkSongRow(\mysqli $db, int $workId, string $songId): void
{
    $ins = $db->prepare(
        'INSERT INTO tblWorkSongs (WorkId, SongId) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE WorkId = WorkId'
    );
    $ins->bind_param('is', $workId, $songId);
    $ins->execute();
    $ins->close();

    /* First member of a canonical-less work becomes canonical (#1860
       go-live — readers ORDER BY IsCanonical, see this function's
       doc-block). A membership row for THIS song already exists by this
       point (the INSERT above, idempotent on a re-link) — the promotion
       only fires when NO row on the work is canonical yet, so a work that
       already has a canonical member (however it got one) is left alone. */
    $canon = $db->prepare('SELECT 1 FROM tblWorkSongs WHERE WorkId = ? AND IsCanonical = 1 LIMIT 1');
    $canon->bind_param('i', $workId);
    $canon->execute();
    $hasCanon = $canon->get_result()->fetch_row() !== null;
    $canon->close();
    if (!$hasCanon) {
        $promote = $db->prepare('UPDATE tblWorkSongs SET IsCanonical = 1 WHERE WorkId = ? AND SongId = ?');
        $promote->bind_param('is', $workId, $songId);
        $promote->execute();
        $promote->close();
    }
}

/**
 * DELETE one `tblWorkSongs` membership row. The ONE place this exact
 * statement is written — both `workLinkPlan()`'s refinement `unlink_song`
 * ops and the manual `song_work_set` unlink action (the only manual-unlink
 * surface, §3.3.1) call this.
 *
 * @param \mysqli $db
 * @param int     $workId
 * @param string  $songId
 * @return int Rows actually deleted (0 or 1) — an idempotent double-click
 *             reports 0, not an error.
 */
function workUnlinkSongRow(\mysqli $db, int $workId, string $songId): int
{
    $del = $db->prepare('DELETE FROM tblWorkSongs WHERE WorkId = ? AND SongId = ?');
    $del->bind_param('is', $workId, $songId);
    $del->execute();
    $deleted = (int)$del->affected_rows;
    $del->close();
    return $deleted;
}

/**
 * Does `tblWorks.Id = $workId` exist? Used by `song_work_set`'s "link to an
 * existing work" mode to answer 404 before writing anything.
 *
 * @param \mysqli $db
 * @param int     $workId
 * @return bool
 */
function workExists(\mysqli $db, int $workId): bool
{
    $stmt = $db->prepare('SELECT 1 FROM tblWorks WHERE Id = ? LIMIT 1');
    $stmt->bind_param('i', $workId);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
    return $exists;
}

/**
 * Fresh `{workId, workTitle, workSlug, songCount}` snapshot, read back from
 * the database AFTER a write (rule #35 — the client renders its badge from
 * what the server actually stored, never from what it sent). `songCount`
 * is DIRECT memberships only (`tblWorkSongs` rows on this exact work);
 * aggregating a parent's children's counts is the work PAGE's job, not
 * this core's.
 *
 * @param \mysqli $db
 * @param int     $workId
 * @return array{workId:int, workTitle:string, workSlug:string, songCount:int}|null null if the work no longer exists.
 */
function workSnapshot(\mysqli $db, int $workId): ?array
{
    $sel = $db->prepare('SELECT Title, Slug FROM tblWorks WHERE Id = ? LIMIT 1');
    $sel->bind_param('i', $workId);
    $sel->execute();
    $row = $sel->get_result()->fetch_assoc();
    $sel->close();
    if ($row === null) {
        return null;
    }

    $cnt = $db->prepare('SELECT COUNT(*) AS n FROM tblWorkSongs WHERE WorkId = ?');
    $cnt->bind_param('i', $workId);
    $cnt->execute();
    $songCount = (int)$cnt->get_result()->fetch_assoc()['n'];
    $cnt->close();

    return [
        'workId'    => $workId,
        'workTitle' => (string)$row['Title'],
        'workSlug'  => (string)$row['Slug'],
        'songCount' => $songCount,
    ];
}

/**
 * Find an existing `tblWorks` row by EXACT (collation-folded) title, or
 * create one with no identifiers — the manual "Part of work" picker's
 * find-or-create for identifier-less hymns (design §3.7.2). Mirrors
 * `tuneFindOrCreateByName()`'s shape: lookup by name, then
 * slug-ensure-unique + mint on a miss (rule #22 — reuses `workSlugify()` /
 * `workSlugEnsureUnique()` / `ilidAllocate()`, never a second copy).
 *
 * @param \mysqli $db
 * @param string  $title Curator-typed title, trimmed here.
 * @return array{id:int, created:bool}|null null for an empty/whitespace-only title.
 */
function workFindOrCreateByTitle(\mysqli $db, string $title): ?array
{
    $title = trim($title);
    if ($title === '') {
        return null;
    }
    $title = mb_substr($title, 0, 255);

    $stmt = $db->prepare('SELECT Id FROM tblWorks WHERE Title = ? LIMIT 1');
    $stmt->bind_param('s', $title);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row !== null) {
        return ['id' => (int)$row['Id'], 'created' => false];
    }

    $base = workSlugify($title);
    $slug = workSlugEnsureUnique($db, $base === '' ? 'work' : $base);

    $hasIlId = _workAdminIlIdColumnExists($db) && ilidSequenceReady($db);
    $ilId    = $hasIlId ? ilidAllocate($db, 'work') : null;

    if ($hasIlId) {
        $ins = $db->prepare('INSERT INTO tblWorks (Title, Slug, IlId) VALUES (?, ?, ?)');
        $ins->bind_param('sss', $title, $slug, $ilId);
    } else {
        $ins = $db->prepare('INSERT INTO tblWorks (Title, Slug) VALUES (?, ?)');
        $ins->bind_param('ss', $title, $slug);
    }
    $ins->execute();
    $newId = (int)$db->insert_id;
    $ins->close();

    return ['id' => $newId, 'created' => true];
}

/**
 * Mint a `tblWorks` row for a `create_work` plan op. Shared by BOTH branches
 * (`ref === 'parent'` gets an Iswc + no Ccli; `ref === 'child'` gets a Ccli
 * + no Iswc — the invariant that keeps a parent row from ever carrying a
 * CCLI, and a child row from ever carrying an ISWC) so there is exactly one
 * INSERT statement shape, not two drifting copies.
 *
 * ELI5: "make a new tblWorks row for this identifier, using the song's own
 * title as a starting point."
 *
 * @param \mysqli    $db
 * @param string     $songTitle
 * @param ?string    $iswc     Set for a 'parent' op, null for a 'child' op.
 * @param ?string    $ccli     Set for a 'child' op, null for a 'parent' op.
 * @param ?int       $parentId ParentWorkId for a 'child' op (null = standalone/parent).
 * @return int The new tblWorks.Id.
 */
function _workAdminCreateWork(\mysqli $db, string $songTitle, ?string $iswc, ?string $ccli, ?int $parentId): int
{
    $title = mb_substr($songTitle, 0, 255);
    $base  = workSlugify($title);
    $slug  = workSlugEnsureUnique($db, $base === '' ? 'work' : $base);

    $hasIlId = _workAdminIlIdColumnExists($db) && ilidSequenceReady($db);
    $ilId    = $hasIlId ? ilidAllocate($db, 'work') : null;

    if ($hasIlId) {
        $ins = $db->prepare(
            'INSERT INTO tblWorks (Title, Slug, Iswc, Ccli, ParentWorkId, IlId) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $ins->bind_param('ssssis', $title, $slug, $iswc, $ccli, $parentId, $ilId);
    } else {
        $ins = $db->prepare(
            'INSERT INTO tblWorks (Title, Slug, Iswc, Ccli, ParentWorkId) VALUES (?, ?, ?, ?, ?)'
        );
        $ins->bind_param('ssssi', $title, $slug, $iswc, $ccli, $parentId);
    }
    $ins->execute();
    $newId = (int)$db->insert_id;
    $ins->close();
    return $newId;
}

/**
 * Execute a `workLinkPlan()` op list against the live database, inside the
 * CALLER's transaction. Resolves `parentRef`/`targetRef` against the ids
 * `create_work` ops mint EARLIER in the same list (op order is load-bearing
 * — `workLinkPlan()` always emits a `create_work` before anything that
 * references its ref).
 *
 * @param \mysqli $db
 * @param string  $songId
 * @param string  $songTitle
 * @param list<array> $ops
 * @return array{refIds: array{parent:?int, child:?int}, created:bool, createdParent:bool, rehomed:bool, refined:bool}
 */
function _workAdminExecutePlan(\mysqli $db, string $songId, string $songTitle, array $ops): array
{
    $refIds        = ['parent' => null, 'child' => null];
    $created       = false;
    $createdParent = false;
    $rehomed       = false;
    $refined       = false;

    $resolve = static function (?int $id, ?string $ref, array $refIds): ?int {
        if ($id !== null) {
            return $id;
        }
        return $ref !== null ? ($refIds[$ref] ?? null) : null;
    };

    foreach ($ops as $op) {
        switch ($op['op']) {
            case 'create_work': {
                $ref = $op['ref'];
                if ($ref === 'parent') {
                    $newId = _workAdminCreateWork($db, $songTitle, $op['iswc'], null, null);
                    $createdParent = true;
                } else {
                    $parentId = $resolve($op['parentId'] ?? null, $op['parentRef'] ?? null, $refIds);
                    $newId = _workAdminCreateWork($db, $songTitle, null, $op['ccli'], $parentId);
                    $created = true;
                }
                $refIds[$ref] = $newId;
                break;
            }
            case 'set_parent': {
                $workId   = (int)$op['workId'];
                $parentId = $resolve($op['parentId'] ?? null, $op['parentRef'] ?? null, $refIds);
                $upd = $db->prepare('UPDATE tblWorks SET ParentWorkId = ? WHERE Id = ?');
                $upd->bind_param('ii', $parentId, $workId);
                $upd->execute();
                $upd->close();
                $rehomed = true;
                break;
            }
            case 'link_song': {
                $targetId = $resolve($op['targetId'] ?? null, $op['targetRef'] ?? null, $refIds);
                if ($targetId !== null) {
                    workLinkSongRow($db, $targetId, $songId);
                }
                break;
            }
            case 'unlink_song': {
                workUnlinkSongRow($db, (int)$op['workId'], $songId);
                $refined = true;
                break;
            }
        }
    }

    return [
        'refIds'        => $refIds,
        'created'       => $created,
        'createdParent' => $createdParent,
        'rehomed'       => $rehomed,
        'refined'       => $refined,
    ];
}

/**
 * Read current DB state, plan, and execute — the unit that gets re-run ONCE
 * on a 1062 race (see `workFindOrLinkByIdentifier()`'s doc-comment).
 *
 * @param \mysqli $db
 * @param string  $songId
 * @param string  $songTitle
 * @param string  $ccli Canonical, already normalised.
 * @param string  $iswc Canonical, already normalised.
 * @return array The shape `workFindOrLinkByIdentifier()` returns, minus `ready`/`iswcInvalid`.
 */
function _workAdminReadPlanExecute(\mysqli $db, string $songId, string $songTitle, string $ccli, string $iswc): array
{
    $byIswc = null;
    if ($iswc !== '') {
        $stmt = $db->prepare('SELECT Id, ParentWorkId, Iswc, Ccli, Title, Slug FROM tblWorks WHERE Iswc = ? LIMIT 1');
        $stmt->bind_param('s', $iswc);
        $stmt->execute();
        $byIswc = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
    }

    $byCcli = null;
    if ($ccli !== '') {
        $stmt = $db->prepare('SELECT Id, ParentWorkId, Iswc, Ccli, Title, Slug FROM tblWorks WHERE Ccli = ? LIMIT 1');
        $stmt->bind_param('s', $ccli);
        $stmt->execute();
        $byCcli = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
    }

    $memberships = [];
    $stmt = $db->prepare(
        'SELECT ws.WorkId, w.ParentWorkId
           FROM tblWorkSongs ws
           JOIN tblWorks w ON w.Id = ws.WorkId
          WHERE ws.SongId = ?'
    );
    $stmt->bind_param('s', $songId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $memberships[] = [
            'WorkId'       => (int)$row['WorkId'],
            'ParentWorkId' => $row['ParentWorkId'] !== null ? (int)$row['ParentWorkId'] : null,
        ];
    }
    $stmt->close();

    $plan = workLinkPlan($byIswc, $byCcli, $ccli, $iswc, $memberships);
    $exec = _workAdminExecutePlan($db, $songId, $songTitle, $plan['ops']);

    /* Resolve the FINAL link target independent of whether an op actually
       ran for it — a fully idempotent replay (Row 6's "ops: []" case, Row
       3's covered() omission) still has a real, already-correct target the
       response must report (rule #35's "read from what was actually
       stored", not from what this call happened to write). Priority
       mirrors §3.3.5 (most-specific-only): the CCLI work wins whenever a
       CCLI was supplied at all (matches even the Row 7 conflict branch,
       which still links to C), else the ISWC work. */
    $targetId = null;
    if ($ccli !== '') {
        $targetId = $exec['refIds']['child'] ?? ($byCcli['Id'] ?? null);
        $targetId = $targetId !== null ? (int)$targetId : null;
    } elseif ($iswc !== '') {
        $targetId = $exec['refIds']['parent'] ?? ($byIswc['Id'] ?? null);
        $targetId = $targetId !== null ? (int)$targetId : null;
    }

    $snap = $targetId !== null ? workSnapshot($db, $targetId) : null;

    return [
        'linked'        => $snap !== null,
        'workId'        => $snap['workId'] ?? null,
        'workTitle'     => $snap['workTitle'] ?? null,
        'workSlug'      => $snap['workSlug'] ?? null,
        'songCount'     => $snap['songCount'] ?? 0,
        'created'       => $exec['created'],
        'createdParent' => $exec['createdParent'],
        'rehomed'       => $exec['rehomed'],
        'refined'       => $exec['refined'],
        'conflict'      => $plan['conflict'],
    ];
}

/**
 * THE find-or-link entry point every caller uses (api2.php's
 * `song_work_autolink` / `song_work_set`; later, the importer funnels).
 *
 * ELI5: "here's a song and the CCLI/ISWC it just got — go make sure it's
 * linked to the right Work, minting one if nobody's used this identifier
 * before."
 *
 * DETAILED — the full contract:
 *   1. Gate: `!workAdminReady($db)` -> the typed not-ready shape (`ready:false`,
 *      everything else zero/null/false) — NEVER a throw that would cost a
 *      curator their save (the `tuneFindOrCreateByName()` degrade posture).
 *   2. Normalise: `ihymns_canonical_ccli()` / `ihymns_canonical_iswc()` — the
 *      ONE fold (rule #22), never a second inline ISWC/CCLI regex anywhere
 *      in this file. A malformed ISWC (`null` from the fold) degrades to
 *      `''` + `iswcInvalid:true` — non-blocking; the CCLI branch still runs.
 *   3. Read state, PLAN (`workLinkPlan()`), EXECUTE — inside the CALLER's
 *      transaction (this function opens no BEGIN/COMMIT of its own).
 *   4. 1062 RACE (two curators minting the SAME new identifier at once): the
 *      whole read+plan+execute unit is caught and re-run ONCE — byte-for-byte
 *      the TOCTOU discipline `tuneFindOrCreateByName()` documents
 *      (`tune_helpers.php:172-180`). A SECOND 1062 rethrows — that is a
 *      genuinely unexpected failure, not the race this retry exists for.
 *   5. Returns what was ACTUALLY STORED (rule #35), never what the caller sent.
 *
 * @param \mysqli $db
 * @param string  $songId  Must already be known to exist — the CALLER 404s
 *                         before invoking this (the `ed2_songExists()` gate
 *                         in api2.php). This function throws
 *                         \InvalidArgumentException as a belt, not the primary defence.
 * @param string  $ccliRaw Curator/importer-typed CCLI, any formatting.
 * @param string  $iswcRaw Curator/importer-typed ISWC, any formatting.
 * @return array{ready:bool, linked:bool, workId:?int, workTitle:?string, workSlug:?string,
 *               songCount:int, created:bool, createdParent:bool, rehomed:bool, refined:bool,
 *               conflict:?string, iswcInvalid:bool}
 * @throws \InvalidArgumentException if $songId does not exist in tblSongs.
 */
function workFindOrLinkByIdentifier(\mysqli $db, string $songId, string $ccliRaw, string $iswcRaw): array
{
    $notReady = [
        'ready' => false, 'linked' => false, 'workId' => null, 'workTitle' => null,
        'workSlug' => null, 'songCount' => 0, 'created' => false, 'createdParent' => false,
        'rehomed' => false, 'refined' => false, 'conflict' => null, 'iswcInvalid' => false,
    ];
    if (!workAdminReady($db)) {
        return $notReady;
    }

    $ccli      = ihymns_canonical_ccli($ccliRaw);
    $iswcCanon = ihymns_canonical_iswc($iswcRaw);
    $iswcInvalid = ($iswcCanon === null);
    $iswc = $iswcInvalid ? '' : $iswcCanon;

    /* @deleted-visible: existence + title read (#1860) — the CALLER already
       confirmed this SongId exists before invoking this function (api2.php's
       ed2_songExists() gate, which is itself deliberately visible to a
       soft-deleted row — "a write into it is harmless and restore-
       preserving"). Re-reading the same row's Title here to seed a NEW
       Work's title, or to plan a link for a song an editor is actively
       working on, carries the identical reasoning: it is never a public
       listing.
       @disabled-visible: same reasoning, one predicate over (#1765) — a song
       in a publicly-disabled book is still a real row the admin editor can
       act on; this read must see it too. */
    $songStmt = $db->prepare('SELECT Title FROM tblSongs WHERE SongId = ? LIMIT 1');
    $songStmt->bind_param('s', $songId);
    $songStmt->execute();
    $songRow = $songStmt->get_result()->fetch_assoc();
    $songStmt->close();
    if ($songRow === null) {
        throw new \InvalidArgumentException("workFindOrLinkByIdentifier: unknown SongId '{$songId}'.");
    }
    $songTitle = (string)$songRow['Title'];

    try {
        $result = _workAdminReadPlanExecute($db, $songId, $songTitle, $ccli, $iswc);
    } catch (\mysqli_sql_exception $e) {
        if ((int)$e->getCode() !== 1062) {
            throw $e;
        }
        /* TOCTOU race — re-SELECT, re-plan, re-execute ONCE. */
        $result = _workAdminReadPlanExecute($db, $songId, $songTitle, $ccli, $iswc);
    }

    $result['ready']       = true;
    $result['iswcInvalid'] = $iswcInvalid;
    return $result;
}

/**
 * THE ONE fail-safe wrapper every save/import funnel calls instead of the
 * bare `workFindOrLinkByIdentifier()` (#1860 go-live). The two `song_work_*`
 * API endpoints keep their own explicit txn + rethrow shape — an ENDPOINT
 * should 500 honestly on a genuine failure. A SAVE FUNNEL must not: this
 * wrapper is what makes an auto-link failure invisible to the curator saving
 * a song, the P0 contract this whole increment exists to protect.
 *
 * ELI5: "try to link this song's Work by its CCLI/ISWC, but if ANYTHING goes
 * wrong, just quietly give up — never let a Works-linking hiccup cost
 * someone their song save." The one exception: if the database says the
 * whole transaction is already dead (a deadlock), this function has to say
 * so loudly, because staying quiet there would make the caller falsely
 * report success.
 *
 * DETAILED — CONTRACT (build spec §2.1):
 *   1. FAST NO-OP: both identifiers empty after `trim()` -> `null`,
 *      WITHOUT touching the database at all (design §3.3.1 — the auto-
 *      linker never auto-unlinks on cleared identifiers, and this keeps a
 *      zero-identifier save at literally zero added cost).
 *   2. `$ownTransaction === true` (the funnel's OWN write already committed
 *      — metadata_field_update's per-field save, duplicate_song,
 *      revision_restore): opens its OWN `begin_transaction()` /
 *      `workFindOrLinkByIdentifier()` / `commit()`. `catch (\Throwable $e)`
 *      -> `rollback()` (itself try/catch-swallowed — a rollback failing on
 *      an already-dead connection must not throw OUT of this "never
 *      rethrows" mode), `error_log`, return `null`. This mode NEVER
 *      rethrows — its rollback is self-contained, so there is nothing for a
 *      caller to catch even if it wanted to.
 *   3. `$ownTransaction === false` (the DEFAULT — inside a save/import
 *      funnel's ALREADY-OPEN transaction, e.g. `editorSaveSongCore()`):
 *      calls `workFindOrLinkByIdentifier()` directly, no txn of its own.
 *      `catch (\Throwable $e)` -> `songRelocateIsTransactionFatal($e)`
 *      (`includes/song_relocate.php`) decides: a transaction-fatal
 *      deadlock/lock-wait-timeout (1213/1205, cause-chain-walking) means the
 *      CALLER's transaction is already rolled back by MySQL, so this
 *      RE-THROWS — swallowing it here would let the caller's `commit()`
 *      succeed trivially and report `ok:true` for a save that wrote
 *      nothing, the exact false-success class that predicate's own
 *      doc-block names. Every other throwable is logged and swallowed.
 *      This is BYTE-IDENTICAL policy to the legacy inline block's own catch
 *      (`save_song_core.php`, pre-#1860) — extracted here, not reinvented.
 *   4. Every logged failure carries the fixed, greppable tag
 *      `'[work autolink] '`.
 *   5. Adds NO schema probes of its own — `workFindOrLinkByIdentifier()`
 *      already degrades to the typed not-ready shape on an un-migrated
 *      install (`workAdminReady()` gate, above).
 *
 * @param \mysqli $db
 * @param string  $songId         Must already exist in tblSongs — the same
 *                                 precondition `workFindOrLinkByIdentifier()`
 *                                 documents; a funnel calling this has
 *                                 already inserted/confirmed the row.
 * @param string  $ccliRaw        Curator/importer-typed CCLI, any formatting.
 * @param string  $iswcRaw        Curator/importer-typed ISWC, any formatting.
 * @param bool    $ownTransaction false (default) = run inside the caller's
 *                                 already-open transaction; true = open/
 *                                 commit/rollback its own small one.
 * @return ?array The workFindOrLinkByIdentifier() result, or null when the
 *                link attempt was swallowed (empty identifiers, or a
 *                non-fatal failure) — the save must never notice either way.
 * @throws \Throwable Only in `$ownTransaction === false` mode, and only when
 *         `songRelocateIsTransactionFatal()` says the caller's transaction
 *         is already dead.
 */
function workAutolinkSafe(\mysqli $db, string $songId, string $ccliRaw, string $iswcRaw, bool $ownTransaction = false): ?array
{
    if (trim($ccliRaw) === '' && trim($iswcRaw) === '') {
        return null; // nothing to link — zero DB cost (design §3.3.1)
    }

    if ($ownTransaction) {
        $db->begin_transaction();
        try {
            $result = workFindOrLinkByIdentifier($db, $songId, $ccliRaw, $iswcRaw);
            $db->commit();
            return $result;
        } catch (\Throwable $e) {
            try {
                $db->rollback();
            } catch (\Throwable $_rollbackErr) {
                // already-dead connection — nothing more to do; this mode never rethrows
            }
            error_log('[work autolink] ' . $songId . ': ' . $e->getMessage());
            return null;
        }
    }

    try {
        return workFindOrLinkByIdentifier($db, $songId, $ccliRaw, $iswcRaw);
    } catch (\Throwable $e) {
        require_once __DIR__ . DIRECTORY_SEPARATOR . 'song_relocate.php';
        if (songRelocateIsTransactionFatal($e)) {
            throw $e; // caller's transaction is already dead — must propagate, never swallow
        }
        error_log('[work autolink] ' . $songId . ': ' . $e->getMessage());
        return null;
    }
}

/* ============================================================================
 * DESIGN NOTE — recorded here per the build spec §2.4, NOT implemented in
 * this file. Read before "helpfully" adding it.
 * ========================================================================== */

/*
 * CREDIT-FORK GUARDRAIL (NOT this core): a hard ISWC/CCLI match is
 * AUTHORITATIVE. When the identifier matches, a differing credit set
 * between the song and the matched work is a RECONCILIATION FLAG for
 * curator review, NEVER a reason to fork a second work row. Credit-fork
 * logic applies only to NAME/TITLE-similarity matches (no hard identifier),
 * which live in the song-save-link/backfill phase (design §3.8, #1872), not
 * here. This core matches on identifiers ONLY, so it structurally cannot
 * fork — this note exists so a future backfill author doesn't "fix" that
 * into forking.
 */

/* ============================================================================
 * MEDLEY WRITE CORE — `tblWorkComponents` (#1860 Phase 5, §5.1 of
 * `.claude/medley-component-work-1860-phase5-plan.md`; design doc §3.6/§3.6b)
 * ==============================================================================
 *
 * ELI5
 * ----
 * A medley is a song stitched together from OTHER songs/works — verse from
 * one hymn, chorus from another. `tblWorkComponents` is the guest list: "this
 * medley Work CONTAINS these constituent Works". These five functions are the
 * ONE place anything reads or writes that guest list (rule #22) — both the
 * `component_upsert` lockstep below (§5.2) and the future `/manage/works`
 * constituent editor (Commit 6) call THESE, never a second copy of the INSERT
 * or the cycle check.
 *
 * DETAILED / WHY
 * ---------------
 * `tblWorkComponents` is a plain M:N join (`MedleyWorkId`, `ComponentWorkId`)
 * — DELIBERATELY NOT `ParentWorkId` (design §3.2: `ParentWorkId` means
 * *is-a-variant-of*; this table means *contains*). The DB cannot express
 * "never a work medley-of-itself" or "never a containment cycle" — those are
 * app-level guards every writer here funnels through:
 *
 *   - `$medleyId !== $componentId` (no self-containment);
 *   - both ids must resolve via `workExists()` (no dangling FK attempt —
 *     mirrors `song_work_set`'s existence check, `workExists()` above);
 *   - `workMedleyWouldCycle()` — a bounded-depth BFS over the constituent
 *     graph FROM the work about to become a constituent, refusing an attach
 *     that would make the medley reachable from its own new component (A
 *     contains B contains A). Mirrors `tblSongRedirects`' own
 *     "transitive + cycle-guarded" posture (design §3.6b BFS note).
 *
 * `workMedleyAttach()` is the ONE write primitive (an idempotent upsert —
 * `INSERT … ON DUPLICATE KEY UPDATE MedleyWorkId = MedleyWorkId`, the EXACT
 * no-op-on-duplicate shape `workLinkSongRow()` above already uses for
 * `tblWorkSongs`) that both consumers build on:
 *
 *   - the `component_upsert` LOCKSTEP (§5.2, design §3.6b.2/SD2) calls it
 *     per work-membership, ADDITIVE ONLY — it NEVER updates an existing row's
 *     `SortOrder`/`Note` (a curator's own `/manage/works` ordering must never
 *     be silently overwritten by an unrelated section save) and NEVER
 *     deletes on a section's link being cleared (design §3.3.1's "never
 *     auto-unlink" posture, reapplied to this table);
 *   - `workMedleyReplace()` (Commit 6's works.php full-form reconcile) is
 *     built ENTIRELY out of it too — DELETE the medley's current rows, then
 *     re-`workMedleyAttach()` each validated row, so the self-link/exists/
 *     cycle guards are enforced exactly once, not duplicated into a second
 *     INSERT statement (rule #22).
 *
 * Every public function here is FRAMEWORK-FREE and runs inside the CALLER's
 * transaction (this file opens no BEGIN/COMMIT anywhere — the
 * `song_relocate.php` contract this file already follows above, restated at
 * §5.1's own top). None of them throw for an ordinary guard failure (not
 * ready / self-link / missing work / would-cycle) — they answer `false`/`0`
 * and let the caller decide whether that is worth surfacing; `workMedleyReady()`
 * memoises like `workAdminReady()` right above it, catching down to `false`
 * on ANY probe failure rather than letting an un-migrated install's missing
 * table surface as an uncaught STRICT-mode throw (rule #19).
 *
 * @link .claude/ilyrics-internal-ids-work-model-plan.md §3.6/§3.6b  the design this implements
 * @link .claude/medley-component-work-1860-phase5-plan.md §5  the locked implementation spec
 * @link appWeb/public_html/manage/editor/api2.php  component_upsert — the §5.2 lockstep consumer
 * @link appWeb/.sql/schema.sql  tblWorkComponents — PRIMARY KEY (MedleyWorkId, ComponentWorkId)
 * @see #1860
 * ========================================================================== */

/**
 * ELI5: "has this install run the migration that creates the medley table
 * yet?" — asked once per request and remembered, the exact `static`-memoised
 * idiom `workAdminReady()` uses above (`:138`).
 *
 * DETAILED: `tblWorkComponents` (`migrate-work-identity-model.php`) is a
 * SEPARATE migration card from the `tblWorks`/`tblWorkSongs` core
 * `workAdminReady()` gates, applied web-run and independently orderable
 * (rule #41's "migrations are not auto-applied" applies here too) — so an
 * install can have `workAdminReady() === true` (songs already linking to
 * works) while `tblWorkComponents` still does not exist. Every medley
 * function below funnels through THIS gate before touching the table.
 *
 * @param \mysqli $db
 * @return bool
 */
function workMedleyReady(\mysqli $db): bool
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    try {
        $stmt = $db->prepare(
            "SELECT COUNT(*) AS n FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblWorkComponents'"
        );
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $cached = $row !== null && (int)$row['n'] > 0;
    } catch (\Throwable $e) {
        $cached = false;
    }
    return $cached;
}

/**
 * A medley Work's constituent list, display-ready and in curator order.
 * Feeds the read-only "Medley of: A, B, C" line (Editor2 Commit 9, public
 * song/work pages Commit 7) and the `/manage/works` edit-form hydration
 * (Commit 6) for a SINGLE medley.
 *
 * ELI5: "what does this medley contain, in the order it should be shown?"
 *
 * @param \mysqli $db
 * @param int     $medleyWorkId
 * @return list<array{workId:int, title:string, slug:string, sortOrder:int, note:?string}>
 *         `[]` on an un-migrated install (rule #19 — never a bare query that
 *         would throw under STRICT) or a medley with no constituents.
 */
function workMedleyConstituents(\mysqli $db, int $medleyWorkId): array
{
    if (!workMedleyReady($db)) {
        return [];
    }
    $stmt = $db->prepare(
        'SELECT wc.ComponentWorkId AS WorkId, w.Title AS Title, w.Slug AS Slug,
                wc.SortOrder AS SortOrder, wc.Note AS Note
           FROM tblWorkComponents wc
           JOIN tblWorks w ON w.Id = wc.ComponentWorkId
          WHERE wc.MedleyWorkId = ?
          ORDER BY wc.SortOrder, w.Title'
    );
    $stmt->bind_param('i', $medleyWorkId);
    $stmt->execute();
    $res = $stmt->get_result();
    $out = [];
    while ($row = $res->fetch_assoc()) {
        $out[] = [
            'workId'    => (int)$row['WorkId'],
            'title'     => (string)$row['Title'],
            'slug'      => (string)$row['Slug'],
            'sortOrder' => (int)$row['SortOrder'],
            'note'      => $row['Note'] !== null ? (string)$row['Note'] : null,
        ];
    }
    $stmt->close();
    return $out;
}

/**
 * Bulk variant of `workMedleyConstituents()` for a batch of medleys in ONE
 * query — the shape `_worksMap()` (Commit 7) and `ed2_buildSongSnapshot()`
 * (Commit 9) need: several works resolved in a single request, several of
 * which might themselves be medleys, so a per-row `workMedleyConstituents()`
 * call in a loop would be an N+1 (the `SongData.php` IN()-list precedent this
 * mirrors, e.g. `:1096`).
 *
 * ELI5: "for ALL of these medleys at once, what does each one contain?"
 *
 * @param \mysqli    $db
 * @param list<int>  $medleyWorkIds
 * @return array<int, list<array{workId:int, title:string, slug:string, sortOrder:int, note:?string}>>
 *         Keyed by `medleyWorkId`. A medley id with no constituent rows is
 *         simply ABSENT from the returned array (never an empty-array key —
 *         callers use `$map[$id] ?? []`, matching every other bulk-map
 *         helper in this codebase). `[]` on an un-migrated install or an
 *         empty/all-invalid `$medleyWorkIds`.
 */
function workMedleyConstituentsMap(\mysqli $db, array $medleyWorkIds): array
{
    $ids = array_values(array_unique(array_filter(
        array_map('intval', $medleyWorkIds),
        static fn(int $id): bool => $id > 0
    )));
    if (!workMedleyReady($db) || $ids === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare(
        "SELECT wc.MedleyWorkId AS MedleyWorkId, wc.ComponentWorkId AS WorkId,
                w.Title AS Title, w.Slug AS Slug, wc.SortOrder AS SortOrder, wc.Note AS Note
           FROM tblWorkComponents wc
           JOIN tblWorks w ON w.Id = wc.ComponentWorkId
          WHERE wc.MedleyWorkId IN ($placeholders)
          ORDER BY wc.MedleyWorkId, wc.SortOrder, w.Title"
    );
    $types = str_repeat('i', count($ids));
    $stmt->bind_param($types, ...$ids);
    $stmt->execute();
    $res = $stmt->get_result();
    $out = [];
    while ($row = $res->fetch_assoc()) {
        $mid = (int)$row['MedleyWorkId'];
        $out[$mid][] = [
            'workId'    => (int)$row['WorkId'],
            'title'     => (string)$row['Title'],
            'slug'      => (string)$row['Slug'],
            'sortOrder' => (int)$row['SortOrder'],
            'note'      => $row['Note'] !== null ? (string)$row['Note'] : null,
        ];
    }
    $stmt->close();
    return $out;
}

/**
 * Would attaching `$componentId` as a constituent of `$medleyId` close a
 * containment loop? Bounded-depth BFS starting FROM `$componentId`, walking
 * its OWN constituents (treating it as a medley in turn) outward — the
 * question is "can you get back to `$medleyId` by repeatedly asking 'what
 * does this contain'?", because that is exactly the cycle a fresh
 * `(MedleyWorkId=$medleyId, ComponentWorkId=$componentId)` row would close
 * (design §3.6's bounded-depth guard; mirrors `tblSongRedirects`'
 * "transitive + cycle-guarded" posture).
 *
 * ELI5: "if I make B a piece of medley A, does B (however many layers down)
 * already contain A?" — if yes, attaching would make A contain B contain …
 * contain A, a loop with no meaningful "what does this medley play" answer.
 *
 * DETAILED: a plain BFS with a visited-set (never re-queries the same work
 * twice, so a diamond-shaped graph — two different medleys sharing one
 * constituent — costs one query per distinct node, not one per PATH) plus a
 * hard `$maxDepth` (default 8, matching `workAdminReady()`-gated callers'
 * everyday medley depth many times over) so a pathological/corrupted graph
 * cannot turn one `component_upsert` save into an unbounded query storm.
 * Self-gated on `workMedleyReady()` (returns `false` — "no cycle to find" —
 * on an un-migrated install, rule #19) even though every caller already
 * gates before reaching here; a helper that trusts its own safety, not only
 * its callers', is the `workMedleyConstituents()` posture repeated.
 *
 * @param \mysqli $db
 * @param int     $medleyId     The work that WOULD contain `$componentId`.
 * @param int     $componentId  The work about to become a constituent.
 * @param int     $maxDepth     BFS hop cap (default 8).
 * @return bool `true` when `$medleyId` is reachable from `$componentId` —
 *              attaching would create a cycle.
 */
function workMedleyWouldCycle(\mysqli $db, int $medleyId, int $componentId, int $maxDepth = 8): bool
{
    if (!workMedleyReady($db)) {
        return false;
    }

    $visited = [$componentId => true];
    $queue   = [[$componentId, 0]];

    while ($queue !== []) {
        [$current, $depth] = array_shift($queue);
        if ($depth >= $maxDepth) {
            continue;   // depth cap — stop expanding this branch, keep draining the queue
        }

        $stmt = $db->prepare('SELECT ComponentWorkId FROM tblWorkComponents WHERE MedleyWorkId = ?');
        $stmt->bind_param('i', $current);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $child = (int)$row['ComponentWorkId'];
            if ($child === $medleyId) {
                $stmt->close();
                return true;   // $medleyId is reachable from $componentId -> attaching would close the loop
            }
            if (!isset($visited[$child])) {
                $visited[$child] = true;
                $queue[] = [$child, $depth + 1];
            }
        }
        $stmt->close();
    }

    return false;
}

/**
 * Attach `$componentId` as a constituent of medley `$medleyId` — the ONE
 * write primitive both the §5.2 lockstep and `workMedleyReplace()` build on
 * (rule #22; NEVER re-forked into a second INSERT statement).
 *
 * Guards, in order (short-circuit — the FIRST failing guard skips every DB
 * call after it, so a self-link or not-ready install costs at most the
 * `workMedleyReady()` probe):
 *   1. `workMedleyReady($db)` — un-migrated install, refuse.
 *   2. `$medleyId !== $componentId` — never a work medley-of-itself.
 *   3. both ids resolve via `workExists()` — never a dangling FK attempt.
 *   4. `!workMedleyWouldCycle($db, $medleyId, $componentId)` — never close a
 *      containment loop.
 *
 * Then an IDEMPOTENT upsert: `INSERT … ON DUPLICATE KEY UPDATE
 * MedleyWorkId = MedleyWorkId` — the exact no-op-on-duplicate shape
 * `workLinkSongRow()` (`:517-544`) already uses for `tblWorkSongs`. **This is
 * SD2's load-bearing property**: a row that already exists is left
 * COMPLETELY untouched — `$sortOrder`/`$note` on THIS call are silently
 * discarded for an existing row, never applied as an update. That is
 * deliberate, not an oversight: the §5.2 lockstep calls this on every
 * `component_upsert` whose section still carries a `sourceWorkId` (whether
 * newly set or merely preserved from a previous save), so re-attaching an
 * already-linked pair must be a true no-op — otherwise an unrelated section
 * save would silently overwrite a curator's own `/manage/works` ordering.
 *
 * @param \mysqli     $db
 * @param int         $medleyId    The work that would CONTAIN `$componentId`.
 * @param int         $componentId The work becoming a constituent.
 * @param int         $sortOrder   Seed value for a NEW row only (SD2);
 *                                 clamped to >= 0 (the column is UNSIGNED).
 * @param string|null $note        Seed value for a NEW row only; trimmed and
 *                                 capped to the column's 255 chars, empty
 *                                 folds to `null`.
 * @return bool Whether a `(MedleyWorkId, ComponentWorkId)` row exists AFTER
 *              this call — read back from the database (rule #35), not
 *              inferred from `affected_rows` (which the `ON DUPLICATE KEY`
 *              no-op path reports as `0`, indistinguishable from "refused").
 */
function workMedleyAttach(\mysqli $db, int $medleyId, int $componentId, int $sortOrder = 0, ?string $note = null): bool
{
    if (!workMedleyReady($db)) {
        return false;
    }
    if ($medleyId === $componentId) {
        return false;   // never a work medley-of-itself
    }
    if (!workExists($db, $medleyId) || !workExists($db, $componentId)) {
        return false;
    }
    if (workMedleyWouldCycle($db, $medleyId, $componentId)) {
        return false;
    }

    $sortOrder = max(0, $sortOrder);   // SortOrder is INT UNSIGNED — a negative would throw under STRICT
    $note      = ($note !== null && trim($note) !== '') ? mb_substr(trim($note), 0, 255) : null;

    $ins = $db->prepare(
        'INSERT INTO tblWorkComponents (MedleyWorkId, ComponentWorkId, SortOrder, Note)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE MedleyWorkId = MedleyWorkId'
    );
    $ins->bind_param('iiis', $medleyId, $componentId, $sortOrder, $note);
    $ins->execute();
    $ins->close();

    $chk = $db->prepare('SELECT 1 FROM tblWorkComponents WHERE MedleyWorkId = ? AND ComponentWorkId = ? LIMIT 1');
    $chk->bind_param('ii', $medleyId, $componentId);
    $chk->execute();
    $exists = $chk->get_result()->fetch_row() !== null;
    $chk->close();

    return $exists;
}

/**
 * The `/manage/works` full-form reconcile (Commit 6): replace medley
 * `$medleyId`'s ENTIRE constituent list with `$rows` in one curator save —
 * DELETE what is there, then re-`workMedleyAttach()` each validated row.
 * Deliberately built OUT OF `workMedleyAttach()` rather than a second INSERT
 * (rule #22): every guard (self-link / exists / cycle) applies identically
 * to a hand-edited list as to the automatic lockstep, with no second place
 * those checks could drift apart.
 *
 * ELI5: "here is the medley's whole ingredient list now — replace whatever
 * was there before with exactly this."
 *
 * DETAILED: an invalid row (missing/non-positive `workId`, or a row
 * `workMedleyAttach()` itself refuses — self-link, unknown work, cycle) is
 * SKIPPED with `error_log()`, never a thrown exception — a single bad row
 * in a curator's edit must not cost them the whole save (mirrors
 * `workAutolinkSafe()`'s "the save must never notice" posture, `:1031-1064`,
 * applied to a form submit instead of an autolink). The DELETE runs even
 * when `$rows` is empty (a curator emptying the list is a valid, deliberate
 * edit — not the §3.3.1 "never auto-unlink" posture, which is about
 * SourceWorkId identifier clearing on a SONG, an entirely different write
 * path from a curator explicitly editing a WORK's own constituent list here).
 *
 * @param \mysqli $db
 * @param int     $medleyId
 * @param list<array{workId?:mixed, sortOrder?:mixed, note?:mixed}> $rows
 * @return int Count of rows actually inserted (i.e. `workMedleyAttach()`
 *             returned `true`) — NOT `count($rows)`, since invalid/refused
 *             rows are silently skipped.
 */
function workMedleyReplace(\mysqli $db, int $medleyId, array $rows): int
{
    if (!workMedleyReady($db)) {
        return 0;
    }

    $del = $db->prepare('DELETE FROM tblWorkComponents WHERE MedleyWorkId = ?');
    $del->bind_param('i', $medleyId);
    $del->execute();
    $del->close();

    $inserted = 0;
    foreach ($rows as $row) {
        if (!is_array($row)) {
            error_log('[work medley] workMedleyReplace: skipped a non-array row for medley ' . $medleyId);
            continue;
        }
        $workId = isset($row['workId']) ? (int)$row['workId'] : 0;
        if ($workId <= 0) {
            error_log('[work medley] workMedleyReplace: skipped an invalid workId for medley ' . $medleyId);
            continue;
        }
        $sortOrder = isset($row['sortOrder']) ? (int)$row['sortOrder'] : 0;
        $note      = isset($row['note']) && $row['note'] !== null ? (string)$row['note'] : null;

        try {
            if (workMedleyAttach($db, $medleyId, $workId, $sortOrder, $note)) {
                $inserted++;
            } else {
                error_log('[work medley] workMedleyReplace: attach refused for medley ' . $medleyId . ' -> ' . $workId);
            }
        } catch (\Throwable $e) {
            error_log('[work medley] workMedleyReplace: ' . $e->getMessage());
        }
    }
    return $inserted;
}

/* ============================================================================
 * EXTRAS / ORIGIN-CITY / MEMBERSHIP WRITE CORES (#1988, API-coverage epic
 * #1983) — extracted verbatim (page-byte-identical bodies) from the private
 * closures + inline blocks `manage/works.php` has carried since #1741 P4b /
 * the places-adoption pass, so `admin_work_create`/`admin_work_update`
 * (`api.php`) can delegate to the SAME cores the page's own create/update
 * actions now one-line-delegate to (rule #22 — the `$slugFor` ->
 * `workSlugify()` precedent a few hundred lines above, applied to six more
 * closures/blocks). Nothing here changes BEHAVIOUR versus the pre-#1988
 * inline code — every guard, every column-gate, every lockstep write
 * (TuneName<->TuneId, CopyrightHolder<->CopyrightHolderId) is unchanged,
 * only its HOME moved so a second caller (the API) can reuse it instead of
 * re-typing a drifting copy.
 *
 * `workSongsReplace()` is the one exception worth flagging: it owns ONLY
 * the DELETE-then-INSERT SQL shape (`tblWorkSongs`), not the parse/clamp of
 * the posted rows — manage/works.php's own `member_song_ids`/`member_sort`/
 * `member_note` parallel-array parsing (trim/cap-20/dedupe/sort-default/
 * note-cap) stays exactly where it was, page-local, and the new
 * `admin_work_members_replace` API action (api.php) does its OWN equivalent
 * parse over its JSON row-array shape — both callers hand this function an
 * already-clamped `list<array{songId,isCanonical,sortOrder,note}>`, mirroring
 * how `workMedleyReplace()` above takes already-shaped rows too.
 *
 * @link .claude/api-coverage-2026-08-28.md          the API-coverage program this closes a gap in
 * @link appWeb/public_html/manage/works.php          every one-line delegate this section backs
 * @link appWeb/public_html/api.php                   admin_work_create/_update/_members_replace/_external_links_replace
 * @see #1988 #1983
 * ========================================================================== */

/**
 * Cached, per-column INFORMATION_SCHEMA presence probe for the 11
 * `tblWorks` "extra" columns — the 9 #1741 P1/D5 identity/descriptive
 * fields (Subtitle/Disambiguation/Ccli/Bowi/TuneName/TuneId/
 * FirstPublishedYear/CopyrightYears/CopyrightHolder) PLUS the two later
 * riders `manage/works.php` already probes in the SAME query
 * (MusicBrainzWorkMBID, #1066; CopyrightHolderId, #1864). Extracted
 * VERBATIM from that page's inline `$worksExtraCols` probe (its own
 * doc-comment explains why Bowi is checked independently despite sharing
 * this query — a different migration card than the other ten).
 *
 * Deliberately a SEPARATE probe from `SongData::_worksExtraCols()` (which
 * covers only the 9 #1741 P1/D5 names, for a DIFFERENT reason — see that
 * method's own doc-block and `tests/test-native-identity-contract.js`,
 * which derives its Work-identity-key floor from that exact 9-name array
 * literal; folding MusicBrainzWorkMBID/CopyrightHolderId into it would
 * both misattribute two non-#1741 columns to the P4b family AND corrupt
 * that guard's derived camelCase key list). This probe is for the
 * WRITE side only — `manage/works.php` create/update and the new
 * `admin_work_*` API actions — never the read side.
 *
 * ELI5: "of these 11 optional Work columns, which ones actually exist on
 * THIS database right now?" — static-memoised per request, the
 * `workAdminReady()` idiom above (`:138`), so neither caller re-asks
 * INFORMATION_SCHEMA the same question twice in one request.
 *
 * @param \mysqli $db
 * @return array<string,true> present column names as keys.
 */
function workExtraColumnsPresent(\mysqli $db): array
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $present = [];
    try {
        $extraColNames = [
            'Subtitle', 'Disambiguation', 'Ccli', 'Bowi', 'TuneName', 'TuneId',
            'FirstPublishedYear', 'CopyrightYears', 'CopyrightHolder', 'MusicBrainzWorkMBID',
            'CopyrightHolderId', /* #1864 — registry FK; column-gated everywhere TuneId is */
        ];
        $ph    = implode(',', array_fill(0, count($extraColNames), '?'));
        $stmt  = $db->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblWorks'
                AND COLUMN_NAME IN ($ph)"
        );
        $types = str_repeat('s', count($extraColNames));
        $stmt->bind_param($types, ...$extraColNames);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $present[(string)$row['COLUMN_NAME']] = true;
        }
        $stmt->close();
    } catch (\Throwable $e) {
        error_log('[work_admin] extra-cols probe failed: ' . $e->getMessage());
        $present = [];
    }
    return $cached = $present;
}

/**
 * Extract + shape-validate the 9 #1741 P1/D5 "extra" `tblWorks` fields plus
 * the MusicBrainzWorkMBID rider from a POST-or-JSON-body-shaped array —
 * extracted VERBATIM from `manage/works.php`'s `$parseWorkExtraFields`
 * closure (rule #22). Shared by `manage/works.php`'s create/update AND
 * (#1988) `admin_work_create`/`admin_work_update` in api.php — the request
 * body key names (`tune_name`, `first_published_year`, …) are IDENTICAL
 * between the page's `$_POST` shape and the API's JSON body shape, so the
 * same function reads both without translation.
 *
 * CCLI validates through the shared `mediaIdentifierWorkValidate()`
 * (includes/media_identifiers.php) — NO inline `preg_match()` here
 * (rule #35). BOWI has no documented shape, length-checked only.
 *
 * @param array<string,mixed> $post
 * @return array{0: array<string,mixed>, 1: ?string} [$fields, $error] —
 *         $error is null on success; $fields is [] (unusable) on failure.
 */
function workParseExtraFields(array $post): array
{
    $subtitle        = mb_substr(trim((string)($post['subtitle'] ?? '')), 0, 255);
    $disambiguation  = mb_substr(trim((string)($post['disambiguation'] ?? '')), 0, 255);
    $ccliIn          = trim((string)($post['ccli'] ?? ''));
    $bowiIn          = mb_substr(trim((string)($post['bowi'] ?? '')), 0, 30);
    $tuneNameIn      = mb_substr(trim((string)($post['tune_name'] ?? '')), 0, 120);
    $firstPubIn      = trim((string)($post['first_published_year'] ?? ''));
    $copyrightYears  = mb_substr(trim((string)($post['copyright_years'] ?? '')), 0, 100);
    $copyrightHolder = mb_substr(trim((string)($post['copyright_holder'] ?? '')), 0, 255);
    /* #1864 — the Copyright Holder picker's hidden id, same "cast-or-null"
       idiom as origin_city_id — 0/absent means "nothing was picked", not
       "picked publisher #0". Verified server-side by
       publisherResolvePickedOrCreate() before being trusted (rule #37). */
    $copyrightHolderIdIn = (int)($post['copyright_holder_id'] ?? 0) ?: null;
    $mbWorkIn        = trim((string)($post['musicbrainz_work_mbid'] ?? ''));

    if ($ccliIn !== '' && !mediaIdentifierWorkValidate('ccli', $ccliIn)) {
        return [[], 'CCLI Work Number must be numeric.'];
    }
    if ($mbWorkIn !== '' && !mediaIdentifierWorkValidate('musicbrainz-work', $mbWorkIn)) {
        return [[], 'MusicBrainz Work MBID must look like a UUID (8-4-4-4-12 hex digits).'];
    }
    $firstPublishedYear = null;
    if ($firstPubIn !== '') {
        /* SMALLINT UNSIGNED on the schema side (schema.sql:3049) —
           500..2100 keeps the same generous historical floor the column's
           own comment documents ("hymn works predate" MySQL YEAR's 1901
           floor) while still rejecting obvious typos. */
        if (!ctype_digit($firstPubIn) || (int)$firstPubIn < 500 || (int)$firstPubIn > 2100) {
            return [[], 'First published year must be a year between 500 and 2100.'];
        }
        $firstPublishedYear = (int)$firstPubIn;
    }

    return [[
        'subtitle'            => $subtitle,
        'disambiguation'      => $disambiguation,
        'ccli'                => $ccliIn,
        'bowi'                => $bowiIn,
        'tuneName'            => $tuneNameIn,
        'firstPublishedYear'  => $firstPublishedYear,
        'copyrightYears'      => $copyrightYears,
        'copyrightHolder'     => $copyrightHolder,
        'copyrightHolderId'   => $copyrightHolderIdIn,
        'musicBrainzWorkMbid' => $mbWorkIn,
    ], null];
}

/**
 * Ccli/Bowi/MusicBrainzWorkMBID uniqueness, gated per-column — extracted
 * VERBATIM from `manage/works.php`'s `$checkWorkExtraUniqueness` closure
 * (rule #22). `$excludeId` is null on create, the row's own Id on update.
 *
 * @param \mysqli              $db
 * @param array<string,true>   $worksExtraCols
 * @param array<string,mixed>  $fields
 * @param ?int                 $excludeId
 * @return string|null a user-facing error, or null when clear.
 */
function workCheckExtraUniqueness(\mysqli $db, array $worksExtraCols, array $fields, ?int $excludeId): ?string
{
    /* Hardcoded (col,val,label) triples from fixed PHP source — the ONLY
       interpolated identifier below (`{$col}`) is drawn from this literal
       array, never from request input (rule #5's carve-out). */
    $checks = [
        ['col' => 'Ccli', 'val' => (string)$fields['ccli'], 'label' => 'CCLI Work Number'],
        ['col' => 'Bowi', 'val' => (string)$fields['bowi'], 'label' => 'BOWI'],
        ['col' => 'MusicBrainzWorkMBID', 'val' => (string)$fields['musicBrainzWorkMbid'], 'label' => 'MusicBrainz Work MBID'],
    ];
    foreach ($checks as $check) {
        $col = $check['col'];
        $val = $check['val'];
        if ($val === '' || !isset($worksExtraCols[$col])) continue;
        if ($excludeId !== null) {
            $stmt = $db->prepare("SELECT Id FROM tblWorks WHERE {$col} = ? AND Id <> ?");
            $stmt->bind_param('si', $val, $excludeId);
        } else {
            $stmt = $db->prepare("SELECT Id FROM tblWorks WHERE {$col} = ?");
            $stmt->bind_param('s', $val);
        }
        $stmt->execute();
        $used = $stmt->get_result()->fetch_row() !== null;
        $stmt->close();
        if ($used) {
            return "{$check['label']} '{$val}' is already on another Work.";
        }
    }
    return null;
}

/**
 * ONE separate column-gated UPDATE persisting the 9 #1741 P1/D5 extra
 * fields + the MusicBrainzWorkMBID rider — extracted VERBATIM from
 * `manage/works.php`'s `$persistWorkExtraFields` closure (rule #22). Each
 * field drops out of the SET list independently when its own column is
 * absent. TuneName + TuneId are ALWAYS written TOGETHER via
 * `tuneFindOrCreateByName()`; CopyrightHolder + CopyrightHolderId are
 * ALWAYS written together via `publisherResolvePickedOrCreate()` — never
 * one half of either lockstep alone (that would silently link the wrong
 * tune/publisher page).
 *
 * @param \mysqli              $db
 * @param array<string,true>   $worksExtraCols
 * @param int                  $workId
 * @param array<string,mixed>  $fields
 */
function workPersistExtraFields(\mysqli $db, array $worksExtraCols, int $workId, array $fields): void
{
    $sets  = [];
    $types = '';
    $vals  = [];

    if (isset($worksExtraCols['Subtitle'])) {
        $sets[] = 'Subtitle = ?'; $types .= 's';
        $vals[] = $fields['subtitle'] !== '' ? $fields['subtitle'] : null;
    }
    if (isset($worksExtraCols['Disambiguation'])) {
        $sets[] = 'Disambiguation = ?'; $types .= 's';
        $vals[] = $fields['disambiguation'];
    }
    if (isset($worksExtraCols['Ccli'])) {
        $sets[] = 'Ccli = ?'; $types .= 's';
        $vals[] = $fields['ccli'] !== '' ? $fields['ccli'] : null;
    }
    if (isset($worksExtraCols['Bowi'])) {
        $sets[] = 'Bowi = ?'; $types .= 's';
        $vals[] = $fields['bowi'] !== '' ? $fields['bowi'] : null;
    }
    if (isset($worksExtraCols['FirstPublishedYear'])) {
        $sets[] = 'FirstPublishedYear = ?'; $types .= 'i';
        $vals[] = $fields['firstPublishedYear'];
    }
    if (isset($worksExtraCols['CopyrightYears'])) {
        $sets[] = 'CopyrightYears = ?'; $types .= 's';
        $vals[] = $fields['copyrightYears'];
    }
    if (isset($worksExtraCols['CopyrightHolder'])) {
        $sets[] = 'CopyrightHolder = ?'; $types .= 's';
        $vals[] = $fields['copyrightHolder'];
        /* #1864 — CopyrightHolder + CopyrightHolderId are ALWAYS written
           TOGETHER, the exact TuneName/TuneId lockstep shape below applied
           to the publisher registry (rule #37/#43): trusts the picker's
           claimed id only after verifying it against the typed name, else
           falls back to publisherFindOrCreateByName(). */
        if (isset($worksExtraCols['CopyrightHolderId'])) {
            $sets[] = 'CopyrightHolderId = ?'; $types .= 'i';
            $vals[] = publisherResolvePickedOrCreate($db, $fields['copyrightHolder'], $fields['copyrightHolderId'] ?? null);
        }
    }
    if (isset($worksExtraCols['MusicBrainzWorkMBID'])) {
        $sets[] = 'MusicBrainzWorkMBID = ?'; $types .= 's';
        $vals[] = $fields['musicBrainzWorkMbid'] !== '' ? $fields['musicBrainzWorkMbid'] : null;
    }
    if (isset($worksExtraCols['TuneName'])) {
        $sets[] = 'TuneName = ?'; $types .= 's';
        $vals[] = $fields['tuneName'] !== '' ? $fields['tuneName'] : null;
        if (isset($worksExtraCols['TuneId'])) {
            $sets[] = 'TuneId = ?'; $types .= 'i';
            $vals[] = tuneFindOrCreateByName($db, $fields['tuneName']);
        }
    }

    if (!$sets) return;
    $stmt = $db->prepare('UPDATE tblWorks SET ' . implode(', ', $sets) . ' WHERE Id = ?');
    $types .= 'i';
    $vals[] = $workId;
    $stmt->bind_param($types, ...$vals);
    $stmt->execute();
    $stmt->close();
}

/**
 * Persist the places-adoption composition-origin columns
 * (`OriginCity`/`OriginCityId`) — extracted VERBATIM from the identical
 * inline block `manage/works.php`'s create AND update actions each carried
 * (rule #22 — one write, not two copies). No-op (never throws) on an
 * install that hasn't run the places-adoption migration yet.
 *
 * @param \mysqli $db
 * @param int     $workId
 * @param ?string $city
 * @param ?int    $cityId
 */
function workPersistOriginCity(\mysqli $db, int $workId, ?string $city, ?int $cityId): void
{
    if (!placeColumnExists($db, 'tblWorks', 'OriginCityId')) {
        return;
    }
    $stmt = $db->prepare(
        'UPDATE tblWorks
            SET OriginCity = ?, OriginCityId = ?
          WHERE Id = ?'
    );
    $stmt->bind_param('sii', $city, $cityId, $workId);
    $stmt->execute();
    $stmt->close();
}

/**
 * Verify `$candidateParent` isn't a descendant of `$workId` — prevents
 * cycle creation when re-parenting. Consolidated (#1988, rule #22) from
 * TWO byte-identical copies: `manage/works.php`'s `$cycleSafe` closure and
 * `api.php`'s own `$workCycleSafe` closure, which had independently grown
 * the exact same walk. Both call sites now delegate here instead.
 *
 * Walks the parent chain until null, giving up after `$maxDepth`
 * iterations as a hard stop in case the table has somehow already become
 * inconsistent.
 *
 * @param \mysqli  $db
 * @param int      $workId
 * @param ?int     $candidateParent
 * @return bool true when `$candidateParent` is safe to set (no cycle).
 */
function workParentCycleSafe(\mysqli $db, int $workId, ?int $candidateParent): bool
{
    if ($candidateParent === null) return true;
    if ($candidateParent === $workId) return false;
    $cur = $candidateParent;
    $maxDepth = 64;
    while ($cur !== null && $maxDepth-- > 0) {
        if ($cur === $workId) return false;
        $stmt = $db->prepare('SELECT ParentWorkId FROM tblWorks WHERE Id = ?');
        $stmt->bind_param('i', $cur);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) return true;
        $cur = $row['ParentWorkId'] !== null ? (int)$row['ParentWorkId'] : null;
    }
    return false;
}

/**
 * Replace a Work's ENTIRE `tblWorkSongs` membership list: DELETE-then-
 * INSERT, extracted VERBATIM (rule #22) from `manage/works.php`'s update
 * action (the exact `NULLIF(?, "")` Note shape included). Owns ONLY the SQL
 * shape — the parse/clamp of posted rows (trim/cap-20 SongId, dedupe,
 * sort-default `idx*10` clamped 0..65535, note cap 255) stays in EACH
 * caller (manage/works.php's own parallel-array parsing; the new
 * `admin_work_members_replace` API action's JSON-row-array parsing) —
 * mirrors `workMedleyReplace()`'s own division of labour (this file's
 * medley section above), just without a per-row guard-refusal loop since
 * `tblWorkSongs` has no self-link/cycle concept to guard.
 *
 * The DELETE always runs, even for an empty `$rows` — clearing a Work's
 * membership is a valid, deliberate edit (mirrors `workMedleyReplace()`'s
 * own "empty list = clear" posture).
 *
 * @param \mysqli $db
 * @param int     $workId
 * @param list<array{songId:string,isCanonical:bool,sortOrder:int,note:?string}> $rows
 * @return int Rows inserted (== count($rows) — every row here is assumed
 *             already validated/clamped by the caller, unlike
 *             workMedleyReplace() which itself guard-refuses rows).
 */
function workSongsReplace(\mysqli $db, int $workId, array $rows): int
{
    $stmt = $db->prepare('DELETE FROM tblWorkSongs WHERE WorkId = ?');
    $stmt->bind_param('i', $workId);
    $stmt->execute();
    $stmt->close();

    $inserted = 0;
    if ($rows) {
        $stmt = $db->prepare(
            'INSERT INTO tblWorkSongs
                (WorkId, SongId, IsCanonical, SortOrder, Note)
             VALUES (?, ?, ?, ?, NULLIF(?, ""))'
        );
        foreach ($rows as $row) {
            $sid     = (string)$row['songId'];
            $isCanon = !empty($row['isCanonical']) ? 1 : 0;
            $sort    = (int)$row['sortOrder'];
            $note    = (string)($row['note'] ?? '');
            $stmt->bind_param('isiis', $workId, $sid, $isCanon, $sort, $note);
            $stmt->execute();
            $inserted++;
        }
        $stmt->close();
    }
    return $inserted;
}
