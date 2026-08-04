<?php

declare(strict_types=1);

/**
 * iHymns — Content Access Control
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Evaluates content restrictions to determine whether a user on a given
 * platform can access a specific song, songbook, or feature.
 *
 * The system uses a priority-based rule evaluation:
 *   1. Gather all matching restrictions for the entity
 *   2. Sort by Priority (descending) — higher priority overrides lower
 *   3. At equal priority, deny beats allow
 *   4. If no restrictions match, access is granted (open by default)
 *
 * ────────────────────────────────────────────────────────────────────────────
 * THE ONE ENTRY POINT (CLAUDE.md rule #8)
 * ────────────────────────────────────────────────────────────────────────────
 * ELI5: everything that wants to know "is this person allowed to see this
 * song?" asks this one function, so two parts of the site can never disagree.
 *
 * `checkContentAccess()` is the ONLY thing permitted to read
 * `tblContentRestrictions` on a request path. No page, API handler or media
 * route may query that table itself. The reason is the failure mode this
 * codebase keeps producing (rule #35): when two places answer the same
 * question independently they drift, and a gate that hides the button but not
 * the file — or vice versa — is worse than no gate. The live callers are:
 *
 *   - includes/pages/song.php     — the public lyric gate (+ the CCL notice)
 *   - song-media.php              — sheet music / MIDI / MusicXML bytes (#853)
 *   - audio-media.php             — signed-URL audio bytes (#1358)
 *   - api.php `songbook_export`   — per-song EXPORT gate (via checkBulkAccess)
 *   - api.php `bulk_audio`        — offline manifest (via checkBulkAccess)
 *   - api.php `content_access`    — the native-app query endpoint
 *   - includes/song_relocate.php  — rewrites EntityId on a song move, because
 *                                   EntityId is a polymorphic VARCHAR with NO
 *                                   FK: a restriction left on a dead id simply
 *                                   stops applying (#1380 / #1679 M3).
 *
 * ────────────────────────────────────────────────────────────────────────────
 * TWO AXES — this file is only ONE of them
 * ────────────────────────────────────────────────────────────────────────────
 * A request is gated on two INDEPENDENT questions, and neither implies the
 * other (see song-media.php's "two gates" block and DEV_NOTES "content
 * gating"):
 *
 *   1. ENTITY (here)  — "does a tblContentRestrictions rule cover this song?"
 *   2. TIER (elsewhere) — "does the caller's plan permit this KIND of thing?"
 *                         → checkTierAccess() / contentGatingApply() /
 *                           contentGatingMediaAllowed(), driven by the
 *                           TIER_CAPS registry (rule #28). Never re-derive a
 *                           tier decision in here.
 *
 * A song with no restriction row can still hold premium-only audio; a
 * `public`-tier visitor still has to clear the entity gate.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * DORMANT BY DEFAULT — and this file does NOT check the master switch
 * ────────────────────────────────────────────────────────────────────────────
 * The whole gating program is off unless `tblAppSettings.content_gating_enabled
 * = '1'` (rule #28A). That flag is checked by the CALLERS, not here, so the hot
 * song-page path costs zero queries while gating is off — `song.php` and the
 * two bulk api.php sites test the setting before they call in, so the file is
 * merely LOADED (api.php:172), never exercised. Consequence worth knowing:
 * calling this function directly DOES query, flag or no flag. `api.php`
 * `content_access` is the one deliberate caller that always evaluates — it is a
 * "what would happen" query endpoint for the native apps, not a gate.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * FAIL-OPEN / STRICT-SAFE POSTURE
 * ────────────────────────────────────────────────────────────────────────────
 * ELI5: when we are unsure, we show the song rather than break the page.
 *
 * The three docroots share ONE MySQL and migrations are web-run, never
 * auto-applied (rule #19), so a live request can land on a half-migrated
 * schema. mysqli runs under `MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT`
 * (includes/db_mysql.php), so a missing column or table is a THROWN
 * `mysqli_sql_exception`, not a `false` — the #1228 lesson that blanked
 * /manage/duplicate-songs. Hence:
 *
 *   - the optional `AppliesToAction` column is INFORMATION_SCHEMA-probed and
 *     the probe is wrapped, degrading to the pre-#1120 display-only behaviour;
 *   - `getUserEffectiveLicences()` guards its own optional table
 *     (`tblOrganisationLicences`) internally — see licences.php:410, which
 *     notes explicitly that this function calls it with no try/catch of its
 *     own, so an unguarded read there would 500 the public song page;
 *   - `serviceMode_presenceCcliNumber()` likewise catches internally and
 *     returns null (= no grant) rather than throwing;
 *   - the default verdict, at every exit that isn't an explicit rule match, is
 *     ALLOW. The master switch is the real gate; this evaluator only trims
 *     within an already-opted-in deployment.
 *
 * ⚠️ The one read NOT wrapped here is the `tblContentRestrictions` SELECT
 * itself — see the note above it.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * SHAPING ISSUES
 * ────────────────────────────────────────────────────────────────────────────
 *   #853  — the original entity gate + the gated /song-media/<id> route.
 *   #462  — licences resolve through the org ANCESTOR walk, not a flat query.
 *   #939  — the public-domain design intent (see the big block in the switch).
 *   #1090/#1120/#1141 — the per-ACTION axis: CCLI separates display from
 *           reproduction, so `AppliesToAction` lets a rule gate printing or
 *           exporting without gating on-screen viewing.
 *   #1324/#1335 — Service Mode: a physically present congregant rides the
 *           venue org's live CCLI licence for the duration of the service.
 *   #1388 — the entity gate alone is not enough; the tier axis must gate the
 *           BYTES too, not just the affordance.
 *
 * @requires PHP 8.1+ with mysqli (via includes/db_mysql.php)
 * @see appWeb/public_html/manage/restrictions.php — the curator CRUD UI.
 * @see appWeb/.sql/schema.sql — `tblContentRestrictions` column semantics.
 * @see tests/php/test-content-access-action.php — unit tests for the pure
 *      action matcher below (the DB-driven evaluator has no harness).
 */

/* Direct-hit guard. `includes/` lives UNDER the docroot, so
   /includes/content_access.php is a fetchable URL. Executed on its own this
   file defines functions and emits nothing, but returning a blank 200 for a
   real server path tells a prober the file exists; a 403 says nothing.
   Comparing basenames (rather than realpath) keeps it cheap and survives the
   symlinked docroots. Same idiom as the other includes/ modules. */
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'db_mysql.php';

/* Inheritance-aware licence resolver (#462). The `require_licence` branch
   below calls getUserEffectiveLicenceTypes() so a rule saying
   `require_licence: ihymns_pro` passes when the user holds that licence
   directly OR inherits it from any ancestor organisation. */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'licences.php';

/**
 * Does a restriction whose AppliesToAction = $ruleAction govern the requested
 * $action? (#1120) CCLI (and most licences) separate DISPLAY from REPRODUCTION,
 * so a rule can target one action without gating the others:
 *   - 'all' (and the legacy empty/NULL) governs every action;
 *   - 'reproduce' is the umbrella for the non-display reproduction actions
 *     (print / export / translate);
 *   - otherwise an exact action match.
 * Pure (no DB) so it is unit-testable.
 *
 * ELI5: "you may show it on screen" and "you may print it" are different
 * permissions, and a rule says which one it is talking about.
 *
 * WHY the umbrella term exists rather than three rules: a curator writing a
 * CCLI-shaped policy thinks in the licence's own vocabulary ("display" vs
 * "reproduction"), not in this app's verb list. Storing 'reproduce' means
 * adding a fourth reproduction verb later is a change HERE, not a data
 * migration over every existing rule row — the same growable-vocabulary
 * reasoning that makes the column VARCHAR and not ENUM (rule #20).
 *
 * NOTE the asymmetry that makes this correct: 'reproduce' deliberately does
 * NOT govern 'display'. A print-only restriction must never blank the lyrics
 * on screen; that would be the gate over-reaching into the thing CCLI
 * explicitly permits. tests/php/test-content-access-action.php pins exactly
 * this pair of assertions.
 */
function contentAccessActionApplies(string $ruleAction, string $action): bool
{
    /* Two different empties, normalised two different ways. A rule's empty
       string is LEGACY DATA — rows written before #1120 added the column, and
       rows on an install where the DEFAULT 'all' never applied — so it means
       "governs everything". An empty *requested* action is a caller that
       omitted the argument, so it means the original display-time check.
       Getting these the wrong way round would turn every legacy rule into a
       display-only rule and silently un-gate printing. */
    $ruleAction = ($ruleAction === '') ? 'all' : strtolower($ruleAction);
    $action     = strtolower($action) ?: 'display';
    if ($ruleAction === 'all') { return true; }
    if ($ruleAction === $action) { return true; }
    if ($ruleAction === 'reproduce') {
        return in_array($action, ['print', 'export', 'translate'], true);
    }
    return false;
}

/**
 * Check if a user has access to a specific entity (song, songbook, or feature).
 *
 * THE entity gate (rule #8). Never inline a `tblContentRestrictions` query
 * anywhere else; call this.
 *
 * CONTRACT — this function is fail-open by construction. It returns
 * `['allowed' => true, 'reason' => '']` whenever it cannot establish a
 * positive reason to deny: no rules for the entity, no rule that MATCHES the
 * viewer, an un-migrated per-action column, an absent optional licence table.
 * Callers rely on that (`song.php` wraps the call in try/catch and leaves the
 * lyrics untouched on a throw; `audio-media.php:145` documents the same
 * expectation). A deny is always the deliberate consequence of a rule a
 * curator wrote — never of an environment glitch.
 *
 * The `reason` is CURATOR-AUTHORED text (`tblContentRestrictions.Reason`) and
 * is surfaced to the end user by every caller, including in 403 bodies on
 * unauthenticated routes. It must therefore describe the restriction, not the
 * viewer's account state — do not put "your tier is X" in it; the tier axis has
 * its own deliberately generic message (#1388).
 *
 * NOT memoised. Two calls for the same entity in one request run the query
 * twice — deliberate, because the presence token and action can differ between
 * them, and the per-user licence set (the expensive part) already caches inside
 * licences.php.
 *
 * @param string   $entityType    'song', 'songbook', or 'feature'
 * @param string   $entityId      Song ID, songbook abbreviation, or feature name
 * @param int|null $userId        Authenticated user ID (null for anonymous)
 * @param string   $platform      'PWA', 'Apple', or 'Android'
 * @param ?string  $presenceToken Service-Mode presence token (#1335), or null
 * @param string   $action        'display' (default) | 'print' | 'export' | 'translate' —
 *                                 the requested ACTION; restrictions are filtered to those
 *                                 whose AppliesToAction governs it (#1120). Back-compat:
 *                                 omit for the original display-time check.
 * @return array{allowed: bool, reason: string}
 *
 * ⚠️ Two notes on the vocabularies these params accept, both surfaced by the
 * #1158 annotation pass and left unchanged here:
 *
 *  - $entityType — `manage/restrictions.php` lets a curator create 'songbook'
 *    and 'feature' rules, but every content-serving caller passes 'song'. The
 *    only exception is api.php's `content_access` endpoint, which forwards
 *    whatever the client asks. A songbook- or feature-scoped rule is therefore
 *    stored, listed in the admin UI, and consulted by nothing.
 *  - $platform — free text off `?platform=`, matched case-insensitively (see
 *    `block_platform` below). The admin picker offers 'Amazon' and 'Web' as
 *    well as the three named above.
 */
function checkContentAccess(string $entityType, string $entityId, ?int $userId, string $platform = 'PWA', ?string $presenceToken = null, string $action = 'display'): array
{
    $db = getDbMysqli();

    /* Is the per-action axis migrated on THIS env? (#1120 / #1141). Probed ONCE
       per request (static) — a missing column must not throw under STRICT (the
       #1228 lesson); when absent, every rule is treated as AppliesToAction='all'
       so behaviour is byte-identical to the pre-#1120 display-only gate.

       `static` inside a function persists for the whole PHP process, so under
       php-fpm this outlives the request that filled it — which is what makes
       checkBulkAccess()'s N calls cost ONE probe rather than N. It also means a
       migration applied mid-process is not seen until the worker recycles; that
       is acceptable precisely because the un-migrated branch is the SAFE one
       (all rules govern all actions), never a widened grant.

       Probed rather than assumed because migrations are web-run: "it is in
       schema.sql" is not "it exists on alpha" (rule #19). */
    static $hasActionCol = null;
    if ($hasActionCol === null) {
        try {
            $cstmt = $db->prepare(
                "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblContentRestrictions'
                    AND COLUMN_NAME = 'AppliesToAction' LIMIT 1"
            );
            $cstmt->execute();
            $hasActionCol = $cstmt->get_result()->fetch_row() !== null;
            $cstmt->close();
        } catch (\Throwable $e) { $hasActionCol = false; }
    }

    /* Fetch all restrictions for this entity (+ AppliesToAction when present).
       The column list is interpolated, not bound — legitimate under rule #5
       because both halves are hardcoded constants from this source file and the
       only variable is a boolean from the INFORMATION_SCHEMA probe above. The
       two user-supplied values are bound.

       `EntityId = '*'` is the WILDCARD row: a rule targeting every entity of
       the type ("deny the whole catalogue to platform X"). It is unioned in
       here rather than checked separately so a wildcard and a specific rule
       compete on the same Priority ladder — note the ordering does NOT prefer a
       specific rule over a wildcard, so an equal-priority pair is decided by
       Effect alone.

       ⚠️ UNWRAPPED READ — the one exception to this file's fail-open posture.
       `tblContentRestrictions` is treated as always-present, and under mysqli
       STRICT its absence is a THROW, not a false. It can be absent:
       song_relocate.php:1095 documents "a minimal install has no restrictions
       to lose" and probes for the table before touching it. song.php catches;
       song-media.php, audio-media.php and api.php's `content_access` do not.
       Flagged during the #1158 annotation pass, deliberately not changed here.

       ⚠️ `ORDER BY … Effect ASC` sorts 'allow' BEFORE 'deny' (a < d), and the
       loop below returns on the FIRST rule that matches. So at equal Priority a
       matching allow WINS. This file's header, schema.sql:1080 and
       manage/restrictions.php:22 all state the opposite ("deny beats allow at
       equal priority"). Documented here as observed behaviour; also flagged
       during the #1158 pass rather than changed, since flipping it changes live
       verdicts. */
    $cols = 'RestrictionType, TargetType, TargetId, Effect, Priority, Reason'
          . ($hasActionCol ? ', AppliesToAction' : '');
    $stmt = $db->prepare(
        "SELECT {$cols}
         FROM tblContentRestrictions
         WHERE EntityType = ? AND (EntityId = ? OR EntityId = '*')
         ORDER BY Priority DESC, Effect ASC"
    );
    $stmt->bind_param('ss', $entityType, $entityId);
    $stmt->execute();
    $rules = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    /* Drop rules that don't govern the requested action (only when the axis
       exists — otherwise all rules apply, as before). */
    if ($hasActionCol && $action !== 'display') {
        $rules = array_values(array_filter($rules, static fn($r) =>
            contentAccessActionApplies((string)($r['AppliesToAction'] ?? 'all'), $action)));
    } elseif ($hasActionCol) {
        /* action === 'display': keep rules governing display or all (exclude
           reproduction-only rules so a print/export-only restriction never gates
           on-screen viewing). */
        $rules = array_values(array_filter($rules, static fn($r) =>
            contentAccessActionApplies((string)($r['AppliesToAction'] ?? 'all'), 'display')));
    }

    /* Open by default — and the load-bearing early exit, not just a shortcut.
       Nothing below this line runs for an unrestricted entity, which is the
       overwhelming majority of the ~14k-song catalogue: no org query, no
       licence resolution, no presence lookup. It is what keeps
       checkBulkAccess() over a whole songbook to one cheap SELECT per song
       instead of three queries per song. A wildcard ('*') rule defeats that,
       since it matches every entity — worth knowing before writing one. */
    if (empty($rules)) {
        return ['allowed' => true, 'reason' => ''];
    }

    /* Org memberships — still needed for block_org / require_org rules
       which match on direct membership, not inheritance. The licence
       set below handles its own ancestor walk.

       ELI5: "are you in this church?" is a DIRECT question; "do you have a
       licence?" can be answered by a church above yours in the tree.

       The asymmetry is deliberate, not an oversight. A LICENCE is a commercial
       grant a parent body buys once for everything under it, so it must flow
       down (#462). MEMBERSHIP is an identity fact: being in a diocese does not
       make you a member of every parish in it, and a `block_org` rule naming
       one congregation must not sweep up its whole denomination. Both branches
       stay empty for an anonymous caller, so an anonymous request can only ever
       be matched by block_platform / block_user, or fail a require_* rule. */
    $userOrgIds = [];
    $userLicenceTypes = [];

    if ($userId !== null) {
        $stmt = $db->prepare('SELECT OrgId FROM tblOrganisationMembers WHERE UserId = ?');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        /* PDO::FETCH_COLUMN returned a flat array of column 0; mysqli has
           no direct equivalent — pull all rows then array_column. */
        $userOrgIds = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'OrgId');
        $stmt->close();

        /* Effective licence types: direct + via every org the user belongs
           to + every ancestor org (#462). Replaces the old single-level
           query that only looked at direct memberships.

           Cheap to call repeatedly: getUserEffectiveLicences() memoises per
           userId for the request (licences.php:67), so the expensive part of a
           bulk check happens once. It also swallows the absence of the optional
           `tblOrganisationLicences` table internally — licences.php:410 names
           THIS call site as the reason, since an unguarded read there would 500
           the public song page on an un-migrated install. */
        $userLicenceTypes = getUserEffectiveLicenceTypes($userId);
    }

    /* Service-Mode presence unlock (#1335): a congregant physically present in a
       live service (a valid, unexpired presence token on an active session whose
       org holds a LIVE CCLI licence) rides that org's licence for the duration —
       so a `require_licence: ccli` rule passes. Works for ANONYMOUS congregants
       (no userId). Revoked the instant they leave / it expires / the licence
       lapses (serviceMode_presenceCcliNumber re-checks every call). The owner has
       accepted the licensing basis (#1324); the per-song CCL notice is rendered
       by song.php when this grant applies.

       ELI5: if you are sitting in the service, you get to read the words the
       church has paid for — and only while you are sitting in it.

       WHY IT GRANTS A LICENCE TYPE RATHER THAN A VERDICT. The token is folded
       into $userLicenceTypes and then evaluated by the ordinary
       `require_licence` branch below. That keeps ONE evaluator: a congregant is
       still subject to every block_platform / block_user / block_org rule, and
       a curator who later adds a stricter rule does not have to remember Service
       Mode exists. Injecting an early `return allowed` here instead would have
       made presence a bypass rather than an entitlement.

       WHY IT IS PROOF OF PRESENCE. The token is minted only against a
       venue-displayed rotating code, so possession of it IS the attendance
       evidence — rule #26 is explicit that geolocation must never be used for
       this (spoofable, and it would gate a legitimate congregant on a GPS fix
       indoors).

       `serviceMode_channel()` is non-negotiable, not decoration: the three
       docroots share one MySQL and a presence token minted on one must never
       unlock content on another (rule #26). An un-channel-filtered read here
       would be the cross-env leak class.

       Loaded lazily — the vast majority of requests carry no token, and
       service_mode.php pulls in the whole Live-Follow layer. The
       function_exists() guard costs nothing and keeps a partial deployment
       (file absent / feature stripped) at "no grant" rather than a fatal, which
       is this file's fail-open posture applied to an optional dependency; the
       helper itself catches internally and returns null on any DB trouble. */
    if ($presenceToken !== null && $presenceToken !== '') {
        require_once __DIR__ . DIRECTORY_SEPARATOR . 'service_mode.php';
        if (function_exists('serviceMode_presenceCcliNumber')
            && serviceMode_presenceCcliNumber($db, $presenceToken, serviceMode_channel()) !== null) {
            $userLicenceTypes[] = 'ccli';
        }
    }

    /* Evaluate rules in priority order.
     *
     * TWO FAMILIES OF RULE, and they behave differently on purpose:
     *
     *   block_*   — a PREDICATE. Sets $matches; the shared Effect check at the
     *               bottom of the loop turns a match into the verdict, and a
     *               match (either way) STOPS evaluation. A non-match falls
     *               through to the next, lower-priority rule.
     *   require_* — a HURDLE, resolved inline and then `continue 2`. Failing it
     *               denies immediately; PASSING it grants nothing — evaluation
     *               simply moves on to the next rule. That is what lets a
     *               curator stack "must hold a CCLI licence" on top of "and
     *               also not on the Android build".
     *
     * ⚠️ Consequence of that design: `Effect` is IGNORED for require_licence
     * and require_org (both `continue 2` before the check). The admin form in
     * manage/restrictions.php offers the allow/deny dropdown for every
     * restriction type, so a curator can save `require_licence` + Effect=allow
     * and get identical behaviour to Effect=deny with nothing telling them.
     * Observed during the #1158 pass; not changed here.
     *
     * An unrecognised RestrictionType falls out of the switch with
     * $matches = false, i.e. it is ignored rather than fatal — the same
     * fail-open choice as everywhere else in this file, and what keeps a rule
     * written for a newer deployment harmless on an older one.
     */
    foreach ($rules as $rule) {
        $matches = false;

        switch ($rule['RestrictionType']) {
            case 'block_platform':
                /* Case-insensitive because $platform is client-supplied free
                   text (`?platform=` / the native apps' own header) and the
                   stored TargetId is whatever the curator picked. Comparing
                   raw would make a rule silently stop applying to a client that
                   sends "pwa" instead of "PWA" — a gate that fails OPEN on a
                   typo is the worst kind. */
                $matches = (strtoupper($rule['TargetId']) === strtoupper($platform));
                break;

            case 'block_user':
                /* TargetId is VARCHAR(50) across all restriction kinds, so the
                   int id is cast to string for a strict compare rather than the
                   reverse: `(int)'12abc' === 12` would match a malformed target
                   at the wrong user. The null check keeps an anonymous caller
                   from matching a rule whose TargetId happens to be ''. */
                $matches = ($userId !== null && (string)$userId === $rule['TargetId']);
                break;

            case 'block_org':
                /* Both sides normalised to int: TargetId is the VARCHAR store,
                   OrgId comes back from mysqli as a string. Direct memberships
                   only — see the asymmetry note above. */
                $matches = in_array((int)$rule['TargetId'], array_map('intval', $userOrgIds));
                break;

            case 'require_licence':
                /* If user has any matching licence type, they pass */
                if (in_array($rule['TargetId'], $userLicenceTypes)) {
                    $matches = true;
                    /* require_licence with allow effect means "licence found, pass" */
                } else {
                    /* No matching licence — this is a deny */
                    /* Denies on the spot, and because the ORDER BY put the
                       highest Priority first, the message a user sees is the
                       most specific rule the curator wrote. This is also the
                       branch the Service-Mode presence grant above feeds: the
                       congregant's injected 'ccli' is indistinguishable from a
                       held one HERE, which is the point (one evaluator), while
                       remaining revocable at source because the token is
                       re-checked on every single call. */
                    return ['allowed' => false, 'reason' => $rule['Reason'] ?: 'Licence required.'];
                }
                continue 2; /* Skip the effect check below — handled inline */

            case 'require_org':
                /* Two hurdles in one rule, checked in widening-to-narrowing
                   order so the reason text is the accurate one: first "in ANY
                   organisation at all", then "in THIS organisation". An
                   anonymous caller always fails the first (its $userOrgIds is
                   empty by construction). */
                if (empty($userOrgIds)) {
                    return ['allowed' => false, 'reason' => $rule['Reason'] ?: 'Organisation membership required.'];
                }
                /* Empty or '*' TargetId = "any organisation will do" — the rule
                   is a login-plus-affiliation wall, not a named-org wall. Note
                   the wildcard convention is reused from EntityId, but here it
                   is the TARGET rather than the entity. */
                if ($rule['TargetId'] !== '' && $rule['TargetId'] !== '*') {
                    if (!in_array((int)$rule['TargetId'], array_map('intval', $userOrgIds))) {
                        return ['allowed' => false, 'reason' => $rule['Reason'] ?: 'Specific organisation membership required.'];
                    }
                }
                continue 2;

            /* ----------------------------------------------------------------
             * PUBLIC-DOMAIN GATING — design intent (#939)
             *
             * Future RestrictionType values for PD-only tiers MUST treat the
             * lyrics flag and the music flag as INDEPENDENT. The two columns
             * on tblSongs (LyricsPublicDomain, MusicPublicDomain) describe
             * separate concerns:
             *
             *   - LyricsPublicDomain — words are PD (e.g. an old hymn-text
             *     whose author died > 70 years ago).
             *   - MusicPublicDomain  — tune is PD (e.g. a mediaeval melody,
             *     or one whose composer died > 70 years ago).
             *
             * A song can have one without the other (modern lyrics set to a
             * traditional tune, or vice versa). When a tier exposes only PD
             * lyrics, the gate uses LyricsPublicDomain = 1 ALONE. When a tier
             * exposes only PD sheet music / MIDI / MusicXML, the gate uses
             * MusicPublicDomain = 1 ALONE.
             *
             * Do NOT AND the two flags together — that would gate out
             * legitimate PD-lyrics songs whose tune is still in copyright,
             * and vice versa.
             *
             * Suggested future shape:
             *
             *     case 'require_lyrics_pd':
             *         // Allow only when the target song has LyricsPublicDomain = 1.
             *         // Caller is expected to already know the song's PD flags
             *         // (e.g. via SongData::_fetchSongRow); pass them in via
             *         // a fourth checkContentAccess() argument or do a probe
             *         // here with a single-row SELECT keyed on EntityId.
             *         $matches = ($entityType === 'song'
             *                     && (bool)getSongPdFlag($entityId, 'lyrics'));
             *         break;
             *
             *     case 'require_music_pd':
             *         $matches = ($entityType === 'song'
             *                     && (bool)getSongPdFlag($entityId, 'music'));
             *         break;
             *
             * Until those cases are wired, no PD-gating tier is active and
             * songs render unrestricted regardless of their PD flags.
             * ---------------------------------------------------------------- */
        }

        /* The shared Effect check for the block_* family. A match ENDS
           evaluation whichever way it goes: an 'allow' is a deliberate
           carve-out ("everyone is blocked, except this org"), so it must
           short-circuit the lower-priority deny it was written to override.
           Anything other than the literal 'deny' reads as allow — Effect is a
           VARCHAR(5) with no CHECK, so a junk value fails open, consistent with
           the rest of this file. */
        if ($matches) {
            if ($rule['Effect'] === 'deny') {
                /* Falls back to a neutral sentence when the curator left Reason
                   blank. It is shown verbatim to end users (and in 403 bodies
                   on unauthenticated media routes), so it stays generic. */
                return ['allowed' => false, 'reason' => $rule['Reason'] ?: 'Access restricted.'];
            }
            /* Effect is 'allow' — explicitly allowed, stop checking */
            return ['allowed' => true, 'reason' => ''];
        }
    }

    /* No rules matched — default allow.
       This is the posture, not a fallback: content is open unless a curator
       wrote a rule that matches THIS viewer. Reached routinely — e.g. a rule
       blocking the Android build is fetched for a PWA reader, matches nothing,
       and lands here. */
    return ['allowed' => true, 'reason' => ''];
}

/**
 * Bulk check access for multiple entities (used for filtering song lists).
 *
 * Callers: api.php `songbook_export` (action 'export', #1120) and `bulk_audio`
 * (the PWA offline manifest). Both are entity-only — the TIER axis is applied
 * separately alongside, by contentGatingApply() / contentGatingMediaAllowed()
 * respectively, because a per-song entity gate that the bulk endpoint beside it
 * doesn't honour is not a gate (#1388, rule #28).
 *
 * COST. A thin loop, not a batched query, so it is N × checkContentAccess().
 * That is affordable only because the unrestricted case exits after one cheap
 * indexed SELECT (idx_Entity) and the per-user licence set is memoised; a
 * wildcard `EntityId = '*'` rule removes both savings and makes this three
 * queries per song over a whole songbook. Worth revisiting as one grouped
 * `EntityId IN (…)` fetch if gating is ever switched on at scale.
 *
 * ⚠️ NO PRESENCE TOKEN. This forwards `null` for $presenceToken, so the
 * Service-Mode CCLI unlock (#1335) does not apply to anything checked here —
 * a congregant in a live service can be denied by `songbook_export` and
 * `bulk_audio` the very content song.php and /song-media/<id> deliberately
 * grant them. The parameter does not exist on this signature to pass. This is
 * the same shape as the bug #1388 fixed on the TIER axis (song-media.php:190
 * describes it: "passed the 4-arg form and so DENIED a present congregant the
 * very media that song.php … deliberately grant them") — note that api.php's
 * `bulk_audio` reads the presence cookie for its tier gate immediately after
 * calling this, so the token is in hand and simply cannot be forwarded.
 * Surfaced by the #1158 annotation pass; dormant while gating is off.
 *
 * @param string   $entityType  'song' or 'songbook'
 * @param string[] $entityIds   Array of entity IDs to check
 * @param int|null $userId      Authenticated user ID
 * @param string   $platform    Platform identifier
 * @param string   $action      Requested action (default 'display'); see checkContentAccess (#1120)
 * @return array<string, bool>  Map of entityId => allowed
 */
function checkBulkAccess(string $entityType, array $entityIds, ?int $userId, string $platform = 'PWA', string $action = 'display'): array
{
    $result = [];
    foreach ($entityIds as $id) {
        $check = checkContentAccess($entityType, $id, $userId, $platform, null, $action);
        $result[$id] = $check['allowed'];
    }
    return $result;
}
