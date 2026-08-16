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
 * DESIGN NOTES — recorded here per the build spec §2.4, NOT implemented in
 * this file. Read before "helpfully" adding either of these.
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
 *
 * MEDLEY WRITE HELPERS (`tblWorkComponents` attach/detach, with the
 * MedleyWorkId !== ComponentWorkId + bounded-depth cycle guards of design
 * §3.6): ship in Phase 5, WITH their `/manage/works` consumer. Schema now
 * (`migrate-work-identity-model.php`), helpers with the editor.
 */
