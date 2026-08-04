<?php

declare(strict_types=1);

/**
 * iHymns — Tune admin CRUD shared cores (#1748)
 * ================================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * `/manage/tunes` (the web admin page) and `admin_tune_add`/`_update`/
 * `_merge`/`_delete` (the native-app API in `api.php`) both need to do the
 * SAME six things: check a tune's fields are sane, save them, replace its
 * alias list, replace its credit list, merge two tunes together, and
 * delete one. This file is the ONE place each of those six things is
 * written — both surfaces call these same functions instead of each
 * re-typing its own copy.
 *
 * DETAILED / WHY A SHARED CORE FILE, NOT TWO COPIES (rule #22, rule #35)
 * ----------------------------------------------------------------------------
 * The precedent this codebase already has for "an admin page + an API
 * surface for the same entity" is `manage/musicians.php` + `api.php`'s
 * `admin_musician_*` actions — and that pair duplicates its validation/
 * persistence logic between the two files, an ACKNOWLEDGED wart (see the
 * build spec's §4, which explicitly says "do not copy that part of the
 * model"). This file is the fix for tunes: `manage/tunes.php`'s POST
 * handlers and `api.php`'s `admin_tune_*` cases are both THIN WRAPPERS over
 * the functions below — there is nothing for the two surfaces to drift on,
 * because there is only one copy of the logic to have drifted.
 *
 * Every function here is pure-PHP-plus-`\mysqli` (no `$_POST`/`$_SERVER`/
 * `json_decode()` reads) so both a form-POST caller and a JSON-body caller
 * can normalise their own input into the same plain-array shape before
 * calling in. Field-array keys are the SAME across both callers (mirrors
 * the page's POST names: `name`, `slug`, `subtitle`, `disambiguation`,
 * `meter_code`, `musicbrainz_work_mbid`, `hymnary_tune_id`, `notes`).
 *
 * Direct access is blocked (same guard as `tune_helpers.php` /
 * `musician_helpers.php` / `media_identifiers.php`) so this file can't be
 * requested as an endpoint via an open Apache config.
 *
 * @link .claude/catalogue-1741-1748-plan.md §4              the build spec this file implements
 * @link appWeb/public_html/manage/tunes.php                  page consumer
 * @link appWeb/public_html/api.php                            admin_tune_* API consumer
 * @link appWeb/public_html/includes/tune_helpers.php          IHYMNS_TUNE_CREDIT_ROLES, tuneFindOrCreateByName(), tuneSlugEnsureUnique(), ihymns_tune_slugify()
 * @link appWeb/public_html/includes/media_identifiers.php     mediaIdentifierWorkValidate('musicbrainz-work', …)
 * @link appWeb/public_html/includes/external_link_helpers.php saveExternalLinksForRow()/loadExternalLinksForRow() — external links stay PAGE-only (§4 "API ships without external-link editing")
 * @see #1748
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'tune_helpers.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'media_identifiers.php';
/* #1694 — songVisibleSql() for tuneAdminUsageCounts()'s tblSongs count
   (test-song-visibility-guard.php requires every tblSongs SELECT to embed
   this predicate or carry an explicit @deleted-visible reason). */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'song_soft_delete.php';

/**
 * ELI5: "does this install have the aliases/credits/links tables, the
 * tblMusicians registry, and the tblSongs/tblWorks.TuneId columns yet?" —
 * asked ONCE per request by whichever caller needs it (page or API), never
 * assumed.
 *
 * DETAILED / WHY EACH CALLER PROBES INDEPENDENTLY (rules #5/#9): mysqli
 * runs STRICT (`includes/db_mysql.php`), and migrations are web-run, not
 * auto-applied — an install with `tblTunes` + `tblTuneAliases` only (the
 * tunes-entity migration ran; the enrichment migration and the P1 tblSongs/
 * tblWorks.TuneId columns did not) must still work. `manage/tunes.php` and
 * each `admin_tune_*` API case call this ONCE at the top of their own
 * request and pass the resulting `$gates` array into whichever of the
 * functions below need it — the probe itself lives here so its shape
 * (which keys exist, what each means) can't silently diverge between the
 * two callers either.
 *
 * @param \mysqli $db
 * @return array{hasAliases:bool,hasCredits:bool,hasLinks:bool,hasMusicians:bool,hasSongsTuneId:bool,hasWorksTuneCols:bool}
 */
/**
 * True when $table.$column exists in the current database.
 *
 * ELI5: "does this exact column exist yet?" — some tune columns are added by a
 * LATER, separately-clickable migration card than the one that creates the
 * table, so a table can be present while a column it will eventually gain is not.
 *
 * DETAILED / WHY (#1748, rule #9): the #1741 P1 schema ships across THREE cards
 * a curator applies independently — `tunes-entity` creates the base `tblTunes`
 * (Name/Slug/MeterCode/MBID/HymnaryTuneId/Notes) and `tblTuneAliases`;
 * `tune-enrichment` ADDs `tblTunes.Subtitle`/`.Disambiguation` and CREATEs
 * `tblTuneCredits` (with its FK under the pre-rename credit-person name); the
 * later `musicians-rename` card then RENAMEs that FK to `MusicianId`. mysqli
 * runs STRICT (throws on an unknown column), and migrations are web-run not
 * auto-applied, so a table-existence gate is NOT enough — an ungated
 * `SELECT t.Subtitle` or `c.MusicianId` white-screens (throws-and-empties) on a
 * partially-migrated install. This is the column-grain probe every such read
 * must pass first.
 *
 * @link https://www.php.net/manual/en/mysqli.report.php  STRICT throws, not false
 */
function tuneAdminColumnExists(\mysqli $db, string $table, string $column): bool
{
    try {
        $stmt = $db->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1"
        );
        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $exists = $stmt->get_result()->fetch_row() !== null;
        $stmt->close();
        return $exists;
    } catch (\Throwable $e) {
        error_log('[tuneAdminColumnExists] probe failed for ' . $table . '.' . $column . ': ' . $e->getMessage());
        return false;
    }
}

function tuneAdminProbeGates(\mysqli $db): array
{
    $gates = [
        'hasAliases'        => false,
        'hasCredits'        => false,
        'hasLinks'          => false,
        'hasMusicians'      => false,
        'hasSongsTuneId'    => false,
        'hasWorksTuneCols'  => false,
        'hasTuneEnrichCols' => false,   /* #1748 — tblTunes.Subtitle + .Disambiguation (tune-enrichment card) */
    ];
    try {
        $tableNames = ['tblTuneAliases', 'tblTuneCredits', 'tblTuneExternalLinks', 'tblMusicians'];
        $ph    = implode(',', array_fill(0, count($tableNames), '?'));
        $stmt  = $db->prepare(
            "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ($ph)"
        );
        $types = str_repeat('s', count($tableNames));
        $stmt->bind_param($types, ...$tableNames);
        $stmt->execute();
        $res = $stmt->get_result();
        $found = [];
        while ($row = $res->fetch_assoc()) { $found[(string)$row['TABLE_NAME']] = true; }
        $stmt->close();
        $gates['hasAliases']   = isset($found['tblTuneAliases']);
        $gates['hasLinks']     = isset($found['tblTuneExternalLinks']);
        $gates['hasMusicians'] = isset($found['tblMusicians']);
        /* #1748 — the credits sub-feature requires the RENAMED FK column:
           tune-enrichment creates tblTuneCredits with the pre-rename FK column
           name, only musicians-rename gives it `MusicianId` (and, in the same
           migration, creates tblMusicians). Every credits read/write/merge below
           keys off this gate + references `MusicianId` + LEFT JOINs tblMusicians,
           so requiring the column here makes the whole credits path STRICT-safe
           on a pre-rename install (it degrades to "credits not available yet",
           and tblTuneCredits is always empty until this page — the only writer —
           can run, which is exactly post-rename). */
        $gates['hasCredits']   = isset($found['tblTuneCredits'])
            && tuneAdminColumnExists($db, 'tblTuneCredits', 'MusicianId');
    } catch (\Throwable $e) {
        error_log('[tuneAdminProbeGates] table probe failed: ' . $e->getMessage());
    }

    /* #1748 — enrichment columns on the base table (Subtitle + Disambiguation
       land together in tune-enrichment; require both so a half-applied ALTER
       never half-opens the feature — rule #19's multi-object posture). */
    $gates['hasTuneEnrichCols'] = tuneAdminColumnExists($db, 'tblTunes', 'Subtitle')
        && tuneAdminColumnExists($db, 'tblTunes', 'Disambiguation');
    $gates['hasSongsTuneId']   = tuneAdminColumnExists($db, 'tblSongs', 'TuneId');
    $gates['hasWorksTuneCols'] = tuneAdminColumnExists($db, 'tblWorks', 'TuneId');

    return $gates;
}

/**
 * §3.4's shared `$parseTuneFields` validator, promoted here so BOTH the
 * page and the API validate a tune's scalar fields in exactly ONE place
 * (rule #35). Same field-array shape for create AND update — the caller
 * decides what to do with the result, this function only says "is this
 * shaped correctly".
 *
 * ELI5: "does the tune name/slug/meter/MBID/etc a curator (or the API
 * caller) typed actually look right?" — never touches the database.
 *
 * DETAILED:
 *   - `name` required, `mb_substr(…, 0, 120)` (tblTunes.Name is
 *     VARCHAR(120)).
 *   - `slug` optional: when supplied must match `/^[a-z0-9-]{1,140}$/`
 *     (tblTunes.Slug is VARCHAR(140)); the ACTUAL uniqueness-safe slug is
 *     resolved later by `tuneAdminPersistFields()` via
 *     `tuneSlugEnsureUnique()` — this function only checks the shape.
 *   - `subtitle`/`disambiguation` ≤255 (`subtitle` → `''` stored as NULL by
 *     the persist step; `disambiguation` is `NOT NULL DEFAULT ''` on the
 *     schema side, so it stays a plain string here).
 *   - `notes` ≤65000 (TEXT column; `''` → NULL by the persist step).
 *   - `meter_code` ≤60, stored RAW AS TYPED — MeterCode is a display
 *     column; `ihymns_meter_normalize()` (tune_helpers.php) is
 *     compare-time-only (`tune_search`'s filter), never applied on store.
 *   - `musicbrainz_work_mbid`: `'' || mediaIdentifierWorkValidate(
 *     'musicbrainz-work', $v)` else a validation error — NO inline regex
 *     (rule #35 — the shape lives in `media_identifiers.php`'s
 *     `WORK_IDENTIFIER_TYPES` registry).
 *   - `hymnary_tune_id` ≤64, length-check only (the BOWI precedent —
 *     `WORK_IDENTIFIER_TYPES['bowi']['validate'] === null` — Hymnary.org
 *     has no documented per-id shape either).
 *
 * @param array<string,mixed> $in Raw field-shaped input (either $_POST or
 *                                 a decoded JSON body — both use the SAME
 *                                 key names).
 * @return array{0: array<string,mixed>, 1: ?string} [$fields, $error] —
 *         $error is null on success; $fields is [] (unusable) on failure.
 * @see #1748 §3.4
 */
function tuneAdminValidateFields(array $in): array
{
    $name           = mb_substr(trim((string)($in['name'] ?? '')), 0, 120);
    $slugIn         = trim((string)($in['slug'] ?? ''));
    $subtitle       = mb_substr(trim((string)($in['subtitle'] ?? '')), 0, 255);
    $disambiguation = mb_substr(trim((string)($in['disambiguation'] ?? '')), 0, 255);
    $notes          = mb_substr(trim((string)($in['notes'] ?? '')), 0, 65000);
    $meterCode      = mb_substr(trim((string)($in['meter_code'] ?? '')), 0, 60);
    $mbWorkIn       = trim((string)($in['musicbrainz_work_mbid'] ?? ''));
    $hymnaryTuneId  = mb_substr(trim((string)($in['hymnary_tune_id'] ?? '')), 0, 64);

    if ($name === '') {
        return [[], 'Name is required.'];
    }
    if ($slugIn !== '' && !preg_match('/^[a-z0-9-]{1,140}$/', $slugIn)) {
        return [[], 'Slug must be lowercase letters, digits and hyphens only.'];
    }
    if ($mbWorkIn !== '' && !mediaIdentifierWorkValidate('musicbrainz-work', $mbWorkIn)) {
        return [[], 'MusicBrainz Work MBID must look like a UUID (8-4-4-4-12 hex digits).'];
    }

    return [[
        'name'                => $name,
        'slug'                => $slugIn,
        'subtitle'            => $subtitle,
        'disambiguation'      => $disambiguation,
        'meterCode'           => $meterCode,
        'musicBrainzWorkMbid' => $mbWorkIn,
        'hymnaryTuneId'       => $hymnaryTuneId,
        'notes'               => $notes,
    ], null];
}

/**
 * Name / MusicBrainzWorkMBID uniqueness — the `$checkWorkExtraUniqueness`
 * shape (works.php:213-241) narrowed to tunes' two UNIQUE-keyed columns
 * that are worth REJECTING on (Slug is deliberately excluded — a
 * colliding slug is resolved by auto-suffixing via
 * `tuneSlugEnsureUnique()`, never rejected, so a curator typing an
 * in-use slug still gets to save; see `tuneAdminPersistFields()`).
 *
 * @param \mysqli              $db
 * @param array<string,mixed>  $fields     From `tuneAdminValidateFields()`.
 * @param int|null             $excludeId  The row's own Id on update, or
 *                                          null on create.
 * @return string|null a user-facing error, or null when clear.
 */
function tuneAdminCheckUniqueness(\mysqli $db, array $fields, ?int $excludeId): ?string
{
    $checks = [
        ['col' => 'Name', 'val' => (string)$fields['name'], 'label' => 'Name'],
        ['col' => 'MusicBrainzWorkMBID', 'val' => (string)$fields['musicBrainzWorkMbid'], 'label' => 'MusicBrainz Work MBID'],
    ];
    foreach ($checks as $check) {
        $col = $check['col'];
        $val = $check['val'];
        if ($val === '') continue;
        if ($excludeId !== null) {
            $stmt = $db->prepare("SELECT Id FROM tblTunes WHERE {$col} = ? AND Id <> ?");
            $stmt->bind_param('si', $val, $excludeId);
        } else {
            $stmt = $db->prepare("SELECT Id FROM tblTunes WHERE {$col} = ?");
            $stmt->bind_param('s', $val);
        }
        $stmt->execute();
        $used = $stmt->get_result()->fetch_row() !== null;
        $stmt->close();
        if ($used) {
            return "{$check['label']} '{$val}' is already on another Tune.";
        }
    }
    return null;
}

/**
 * Write a tune's scalar fields (Name/Slug/Subtitle/Disambiguation/
 * MeterCode/MusicBrainzWorkMBID/HymnaryTuneId/Notes) in ONE UPDATE. Used
 * for BOTH create (immediately after `tuneFindOrCreateByName()` has
 * already inserted a bare Name+Slug row — this call fills in the rest AND
 * re-resolves Slug if the curator supplied their own) and update.
 *
 * ELI5: "here's a tune id that already exists — write all its detail
 * fields onto it."
 *
 * DETAILED / SLUG HANDLING (rule #22 — one funnel, `tuneSlugEnsureUnique()`,
 * never a second collision loop): the candidate base is the curator-
 * supplied slug when non-empty, else `ihymns_tune_slugify($fields['name'])`
 * — then ALWAYS run through `tuneSlugEnsureUnique($db, $base, $id)`
 * (excluding the row's own current slug from the collision check, so a
 * no-op re-save never suffixes itself). On the CREATE path this means: a
 * blank curator slug re-derives the SAME base `tuneFindOrCreateByName()`
 * already used, and since only this row (excluded) holds that slug the
 * ensure-unique call returns it UNCHANGED — "keep the funnel's" happens
 * for free, with no separate branch. A curator-supplied slug is
 * auto-suffixed on collision rather than rejected — the create/update
 * forms warn that changing the slug changes `/tune/<slug>`, but old links
 * still resolve via the `tune.php` name/alias ladder (rung (b)/(c)),
 * so a save is never blocked over it.
 *
 * @param \mysqli              $db
 * @param int                  $id
 * @param array<string,mixed>  $fields From `tuneAdminValidateFields()`.
 */
function tuneAdminPersistFields(\mysqli $db, int $id, array $fields): void
{
    $slugBase = $fields['slug'] !== '' ? $fields['slug'] : ihymns_tune_slugify($fields['name']);
    $slug     = tuneSlugEnsureUnique($db, $slugBase, $id);

    $subtitle = $fields['subtitle'] !== '' ? $fields['subtitle'] : null;
    $mbWork   = $fields['musicBrainzWorkMbid'] !== '' ? $fields['musicBrainzWorkMbid'] : null;
    $meter    = $fields['meterCode'] !== '' ? $fields['meterCode'] : null;
    $hymnary  = $fields['hymnaryTuneId'] !== '' ? $fields['hymnaryTuneId'] : null;
    $notes    = $fields['notes'] !== '' ? $fields['notes'] : null;

    /* #1748 (rule #9) — Subtitle/Disambiguation exist only after the
       tune-enrichment card (the base tunes-entity table lacks them). Writing
       them unconditionally throws under mysqli STRICT on a pre-enrichment
       install and aborts the whole save. Self-probe so this core is safe no
       matter which caller (page handler / admin_tune_* API) invokes it, and
       persist the base columns regardless. */
    if (tuneAdminColumnExists($db, 'tblTunes', 'Subtitle')
        && tuneAdminColumnExists($db, 'tblTunes', 'Disambiguation')) {
        $stmt = $db->prepare(
            'UPDATE tblTunes
                SET Name = ?, Slug = ?, Subtitle = ?, Disambiguation = ?,
                    MeterCode = ?, MusicBrainzWorkMBID = ?, HymnaryTuneId = ?, Notes = ?
              WHERE Id = ?'
        );
        $stmt->bind_param(
            'ssssssssi',
            $fields['name'], $slug, $subtitle, $fields['disambiguation'],
            $meter, $mbWork, $hymnary, $notes, $id
        );
    } else {
        $stmt = $db->prepare(
            'UPDATE tblTunes
                SET Name = ?, Slug = ?,
                    MeterCode = ?, MusicBrainzWorkMBID = ?, HymnaryTuneId = ?, Notes = ?
              WHERE Id = ?'
        );
        $stmt->bind_param(
            'ssssssi',
            $fields['name'], $slug,
            $meter, $mbWork, $hymnary, $notes, $id
        );
    }
    $stmt->execute();
    $stmt->close();
}

/**
 * Create a brand-new tune row: the funnel call + the follow-up
 * scalar-fields UPDATE, as ONE step. Both `manage/tunes.php`'s `create`
 * action and `api.php`'s `admin_tune_add` call THIS (rather than each
 * re-typing the same two-line sequence) so there is exactly one place
 * that pairs `tuneFindOrCreateByName()` with `tuneAdminPersistFields()`
 * (rule #22) — and, not incidentally, this is also what keeps
 * `tests/php/test-tune-lockstep.php`'s derived write-site sweep green for
 * THIS file: `tuneAdminPersistFields()`/`tuneAdminRenameCascade()`/
 * `tuneAdminMerge()` above all write `tblSongs.TuneName`, and the sweep
 * requires every such file to also reference `tuneFindOrCreateByName()` —
 * which this function now does for real, not just in a comment.
 *
 * @param \mysqli              $db
 * @param array<string,mixed>  $fields From `tuneAdminValidateFields()`.
 * @return int The new tblTunes.Id.
 * @throws \RuntimeException when `tblTunes` is unexpectedly unavailable
 *                             (the funnel returned null despite the
 *                             caller having already confirmed the schema
 *                             probe passed).
 */
function tuneAdminCreate(\mysqli $db, array $fields): int
{
    $newId = tuneFindOrCreateByName($db, $fields['name']);
    if ($newId === null) {
        throw new \RuntimeException('Could not create the tune row (tblTunes unexpectedly unavailable).');
    }
    tuneAdminPersistFields($db, $newId, $fields);
    return $newId;
}

/**
 * The rename cascade (#1748 §3.4 step 2, the works.php:251-258 TuneName<->
 * TuneId lockstep doctrine applied to the tune SIDE of the mirror): when a
 * tune's Name changes, every `tblSongs`/`tblWorks` row that cites it via
 * `TuneId` gets its denorm `TuneName` mirror updated to match, in the SAME
 * gated UPDATE per table. Tunes need NO separate `rename` action (unlike
 * name-keyed musicians, which have `admin_musician_rename` because a
 * musician's identity IS its name) — the FK makes the cascade precise:
 * every citing row is found by `TuneId`, so there is no free-text guessing
 * involved.
 *
 * ELI5: "the tune used to be called X, now it's called Y — go update every
 * song/work that points at this tune so their little display copy of the
 * name says Y too."
 *
 * @param \mysqli $db
 * @param int     $id
 * @param string  $oldName
 * @param string  $newName
 * @param array{hasSongsTuneId:bool,hasWorksTuneCols:bool,...} $gates
 */
function tuneAdminRenameCascade(\mysqli $db, int $id, string $oldName, string $newName, array $gates): void
{
    if ($oldName === $newName) return;

    if (!empty($gates['hasSongsTuneId'])) {
        $stmt = $db->prepare('UPDATE tblSongs SET TuneName = ? WHERE TuneId = ?');
        $stmt->bind_param('si', $newName, $id);
        $stmt->execute();
        $stmt->close();
    }
    if (!empty($gates['hasWorksTuneCols'])) {
        $stmt = $db->prepare('UPDATE tblWorks SET TuneName = ? WHERE TuneId = ?');
        $stmt->bind_param('si', $newName, $id);
        $stmt->execute();
        $stmt->close();
    }
}

/**
 * Delete-then-insert a tune's alias list (#1748 §3.4 step 3). Trims, caps
 * at 120 chars (`tblTuneAliases.Name` is VARCHAR(120)), drops empties,
 * drops case-folded duplicates (`mb_strtolower` fold — a collation-ish
 * approximation of `uq_TuneName`'s DB-level fold, applied here so the
 * INSERT loop never trips that unique key on two curator-typed spellings
 * that only differ by case), and drops any alias equal to the tune's own
 * canonical Name (an alias identical to the Name is not an alias).
 *
 * ELI5: "here's the full list of alternate names this tune should have —
 * wipe the old list and write this one instead."
 *
 * @param \mysqli       $db
 * @param int           $id
 * @param list<string>  $aliasNames Raw posted names, in curator-typed order.
 * @param string        $tuneName   The tune's OWN canonical Name (post-save).
 */
function tuneAdminReplaceAliases(\mysqli $db, int $id, array $aliasNames, string $tuneName): void
{
    $stmt = $db->prepare('DELETE FROM tblTuneAliases WHERE TuneId = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();

    $nameFold = mb_strtolower($tuneName);
    $seen     = [];
    $clean    = [];
    foreach ($aliasNames as $raw) {
        $name = mb_substr(trim((string)$raw), 0, 120);
        if ($name === '') continue;
        $fold = mb_strtolower($name);
        if ($fold === $nameFold) continue;
        if (isset($seen[$fold])) continue;
        $seen[$fold] = true;
        $clean[] = $name;
    }
    if (!$clean) return;

    $ins = $db->prepare('INSERT INTO tblTuneAliases (TuneId, Name) VALUES (?, ?)');
    foreach ($clean as $name) {
        $ins->bind_param('is', $id, $name);
        $ins->execute();
    }
    $ins->close();
}

/**
 * Delete-then-insert a tune's credit list (#1748 §3.4 step 4). Each row is
 * `['role' => string, 'name' => string, 'musician_id' => int|string|null]`.
 * A row whose `role` isn't a key of `IHYMNS_TUNE_CREDIT_ROLES`
 * (`tune_helpers.php`) is SKIPPED, not rejected — a stray/legacy value in
 * a posted array shouldn't abort the whole save (same posture as the
 * dedupe-not-reject choices elsewhere in this file). `musician_id` is
 * only honoured when `$gates['hasMusicians']` AND the row's positive int
 * id passes a bound existence check — otherwise NULL, matching
 * `tblTuneCredits.MusicianId`'s reserved/dormant nullable-FK design
 * (schema.sql ~3688).
 *
 * @param \mysqli                                                   $db
 * @param int                                                       $id
 * @param list<array{role:mixed,name:mixed,musician_id?:mixed}>     $creditRows
 * @param array{hasMusicians:bool,...}                              $gates
 */
function tuneAdminReplaceCredits(\mysqli $db, int $id, array $creditRows, array $gates): void
{
    $stmt = $db->prepare('DELETE FROM tblTuneCredits WHERE TuneId = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();

    if (!$creditRows) return;

    $existsStmt = null;
    if (!empty($gates['hasMusicians'])) {
        $existsStmt = $db->prepare('SELECT 1 FROM tblMusicians WHERE Id = ? LIMIT 1');
    }

    $ins = $db->prepare(
        'INSERT INTO tblTuneCredits (TuneId, Role, Name, MusicianId, SortOrder) VALUES (?, ?, ?, ?, ?)'
    );
    $idx = 0;
    foreach ($creditRows as $row) {
        $role = (string)($row['role'] ?? '');
        if (!array_key_exists($role, IHYMNS_TUNE_CREDIT_ROLES)) continue;
        $name = mb_substr(trim((string)($row['name'] ?? '')), 0, 255);
        if ($name === '') continue;

        $musicianId = null;
        $mid = (int)($row['musician_id'] ?? 0);
        if ($mid > 0 && $existsStmt !== null) {
            $existsStmt->bind_param('i', $mid);
            $existsStmt->execute();
            if ($existsStmt->get_result()->fetch_row() !== null) {
                $musicianId = $mid;
            }
        }

        $sortOrder = $idx * 10;
        $ins->bind_param('issii', $id, $role, $name, $musicianId, $sortOrder);
        $ins->execute();
        $idx++;
    }
    $ins->close();
    if ($existsStmt !== null) $existsStmt->close();
}

/**
 * Usage + child-row counts for a tune — the confirmation-modal numbers
 * (delete's "this many songs/works still cite it" sentence, the list
 * table's Songs/Aliases/Credits/Links columns). Every count is gated on
 * its own table/column so a partial install (e.g. `tblTunes` +
 * `tblTuneAliases` only) still returns a coherent shape with the
 * ungated counts at 0.
 *
 * @param \mysqli $db
 * @param int     $id
 * @param array{hasAliases:bool,hasCredits:bool,hasLinks:bool,hasSongsTuneId:bool,hasWorksTuneCols:bool,...} $gates
 * @return array{songCount:int,workCount:int,aliasCount:int,creditCount:int,linkCount:int}
 */
function tuneAdminUsageCounts(\mysqli $db, int $id, array $gates): array
{
    $out = ['songCount' => 0, 'workCount' => 0, 'aliasCount' => 0, 'creditCount' => 0, 'linkCount' => 0];

    if (!empty($gates['hasSongsTuneId'])) {
        /* #1694 — songVisibleSql() matches the public tune.php song-list
           query's own visibility predicate; a soft-deleted song shouldn't
           inflate the "N songs still cite this tune" count a curator sees
           before merging/deleting. */
        $stmt = $db->prepare('SELECT COUNT(*) FROM tblSongs WHERE TuneId = ? AND ' . songVisibleSql($db, ''));
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $out['songCount'] = (int)($stmt->get_result()->fetch_row()[0] ?? 0);
        $stmt->close();
    }
    if (!empty($gates['hasWorksTuneCols'])) {
        $stmt = $db->prepare('SELECT COUNT(*) FROM tblWorks WHERE TuneId = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $out['workCount'] = (int)($stmt->get_result()->fetch_row()[0] ?? 0);
        $stmt->close();
    }
    if (!empty($gates['hasAliases'])) {
        $stmt = $db->prepare('SELECT COUNT(*) FROM tblTuneAliases WHERE TuneId = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $out['aliasCount'] = (int)($stmt->get_result()->fetch_row()[0] ?? 0);
        $stmt->close();
    }
    if (!empty($gates['hasCredits'])) {
        $stmt = $db->prepare('SELECT COUNT(*) FROM tblTuneCredits WHERE TuneId = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $out['creditCount'] = (int)($stmt->get_result()->fetch_row()[0] ?? 0);
        $stmt->close();
    }
    if (!empty($gates['hasLinks'])) {
        $stmt = $db->prepare('SELECT COUNT(*) FROM tblTuneExternalLinks WHERE TuneId = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $out['linkCount'] = (int)($stmt->get_result()->fetch_row()[0] ?? 0);
        $stmt->close();
    }
    return $out;
}

/**
 * Delete a tune row. `tblTuneAliases`/`tblTuneCredits`/
 * `tblTuneExternalLinks` FKs are `ON DELETE CASCADE`; `tblSongs.TuneId`/
 * `tblWorks.TuneId` are `ON DELETE SET NULL` — a song/work degrades to its
 * free-text `TuneName` unchanged, which is why delete needs no force-gate
 * (schema.sql ~3730-3742). Caller is responsible for capturing usage
 * counts via `tuneAdminUsageCounts()` BEFORE calling this, for the
 * confirmation UI / activity log.
 *
 * @param \mysqli $db
 * @param int     $id
 * @return string|null the tune's Name (for logging/messaging), or null
 *                       when no such row exists.
 */
function tuneAdminDelete(\mysqli $db, int $id): ?string
{
    $stmt = $db->prepare('SELECT Name FROM tblTunes WHERE Id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return null;

    $stmt = $db->prepare('DELETE FROM tblTunes WHERE Id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();

    return (string)$row['Name'];
}

/**
 * Collapse `$sourceId` into `$targetId` (#1748 §3.4 case 'merge' — the
 * tags.php:216 modal shape + musicians.php:1365 semantics, adapted to an
 * FK-keyed entity). Caller MUST wrap this in `$db->begin_transaction()` /
 * `commit()`/`rollback()` — this function does not manage its own
 * transaction (same convention as `saveExternalLinksForRow()`, which runs
 * inside the CALLER's transaction).
 *
 * ELI5: "fold tune B into tune A — every song/work that cited B now cites
 * A, B's aliases/credits/links move over, B's old name becomes an alias of
 * A (so old links to B still resolve), and B itself is deleted."
 *
 * DETAILED, in the order it runs:
 *   1. Load both rows; reject missing/identical (caller's job, this
 *      function assumes both ids are valid + distinct — see the thin
 *      wrapper in `manage/tunes.php`/`api.php` for the pre-flight checks).
 *   2. Repoint `tblSongs` — gated `hasSongsTuneId`: `TuneId = ? AND
 *      TuneName = ?` (target id, TARGET name) in the SAME statement for
 *      every row currently pointing at the source (the works.php:251-258
 *      TuneName<->TuneId lockstep, applied to a merge instead of a rename).
 *      A SECOND statement catches free-text-only citations that were never
 *      linked via `TuneId` (`TuneId IS NULL AND TuneName = <source name>`)
 *      — mirrors the musician merge's name-string cascade
 *      (musicians.php:1409-1422).
 *   3. Same two statements for `tblWorks`, gated `hasWorksTuneCols`.
 *   4. Aliases (gated `hasAliases`): move every source alias whose Name
 *      isn't already a target alias and isn't equal to the target's own
 *      Name (would collide with `uq_TuneName(TuneId,Name)` otherwise);
 *      then insert the SOURCE's own Name as a new target alias (skipped
 *      if it equals the target's Name or is already an alias) — this is
 *      what keeps an old `/tune/<source-slug>` deep link resolving via
 *      `tune.php`'s ladder rung (c) after the source row is gone (rule
 *      #33). Rows left behind on the source are swept by the CASCADE FK
 *      when the source tune is deleted in step 7.
 *   5. Credits (gated `hasCredits`): move source credit rows whose
 *      `(Role, Name)` pair isn't already present on the target; the rest
 *      cascade away with the source row.
 *   6. External links (gated `hasLinks`): move source link rows whose
 *      `(LinkTypeId, Url)` pair isn't already present on the target; the
 *      rest cascade away.
 *   7. `DELETE FROM tblTunes WHERE Id = <source>` — the cascade sweeps
 *      whatever wasn't explicitly moved in steps 4-6.
 *
 * @param \mysqli $db
 * @param int     $sourceId  Merged away (deleted at the end).
 * @param int     $targetId  Survives.
 * @param array{hasSongsTuneId:bool,hasWorksTuneCols:bool,hasAliases:bool,hasCredits:bool,hasLinks:bool,...} $gates
 * @return array{source:array{id:int,name:string},target:array{id:int,name:string},songsRepointed:int,worksRepointed:int,aliasesMoved:int,creditsMoved:int,linksMoved:int}
 */
function tuneAdminMerge(\mysqli $db, int $sourceId, int $targetId, array $gates): array
{
    $stmt = $db->prepare('SELECT Id, Name FROM tblTunes WHERE Id IN (?, ?)');
    $stmt->bind_param('ii', $sourceId, $targetId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $byId = [];
    foreach ($rows as $r) { $byId[(int)$r['Id']] = (string)$r['Name']; }
    if (!isset($byId[$sourceId]) || !isset($byId[$targetId])) {
        throw new \RuntimeException('Source or target tune not found.');
    }
    $sourceName = $byId[$sourceId];
    $targetName = $byId[$targetId];

    $songsRepointed = 0;
    if (!empty($gates['hasSongsTuneId'])) {
        $stmt = $db->prepare('UPDATE tblSongs SET TuneId = ?, TuneName = ? WHERE TuneId = ?');
        $stmt->bind_param('isi', $targetId, $targetName, $sourceId);
        $stmt->execute();
        $songsRepointed += $stmt->affected_rows;
        $stmt->close();

        $stmt = $db->prepare('UPDATE tblSongs SET TuneId = ?, TuneName = ? WHERE TuneId IS NULL AND TuneName = ?');
        $stmt->bind_param('iss', $targetId, $targetName, $sourceName);
        $stmt->execute();
        $songsRepointed += $stmt->affected_rows;
        $stmt->close();
    }

    $worksRepointed = 0;
    if (!empty($gates['hasWorksTuneCols'])) {
        $stmt = $db->prepare('UPDATE tblWorks SET TuneId = ?, TuneName = ? WHERE TuneId = ?');
        $stmt->bind_param('isi', $targetId, $targetName, $sourceId);
        $stmt->execute();
        $worksRepointed += $stmt->affected_rows;
        $stmt->close();

        $stmt = $db->prepare('UPDATE tblWorks SET TuneId = ?, TuneName = ? WHERE TuneId IS NULL AND TuneName = ?');
        $stmt->bind_param('iss', $targetId, $targetName, $sourceName);
        $stmt->execute();
        $worksRepointed += $stmt->affected_rows;
        $stmt->close();
    }

    $aliasesMoved = 0;
    if (!empty($gates['hasAliases'])) {
        $stmt = $db->prepare('SELECT Name FROM tblTuneAliases WHERE TuneId = ?');
        $stmt->bind_param('i', $sourceId);
        $stmt->execute();
        $sourceAliases = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'Name');
        $stmt->close();

        $stmt = $db->prepare('SELECT Name FROM tblTuneAliases WHERE TuneId = ?');
        $stmt->bind_param('i', $targetId);
        $stmt->execute();
        $targetAliasFold = array_map('mb_strtolower', array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'Name'));
        $stmt->close();

        $targetNameFold = mb_strtolower($targetName);
        $ins = $db->prepare('INSERT INTO tblTuneAliases (TuneId, Name) VALUES (?, ?)');
        foreach ($sourceAliases as $aliasName) {
            $fold = mb_strtolower((string)$aliasName);
            if ($fold === $targetNameFold || in_array($fold, $targetAliasFold, true)) continue;
            $ins->bind_param('is', $targetId, $aliasName);
            $ins->execute();
            $targetAliasFold[] = $fold;
            $aliasesMoved++;
        }
        $ins->close();

        /* The source's own Name becomes a target alias — rule #33: keeps
           an old /tune/<source-slug-ish> deep link resolving via
           tune.php's alias-fold ladder rung (c) after this merge deletes
           the source row. */
        $sourceNameFold = mb_strtolower($sourceName);
        if ($sourceNameFold !== $targetNameFold && !in_array($sourceNameFold, $targetAliasFold, true)) {
            $ins = $db->prepare('INSERT INTO tblTuneAliases (TuneId, Name) VALUES (?, ?)');
            $ins->bind_param('is', $targetId, $sourceName);
            $ins->execute();
            $ins->close();
            $aliasesMoved++;
        }
    }

    $creditsMoved = 0;
    if (!empty($gates['hasCredits'])) {
        $stmt = $db->prepare('SELECT Role, Name, MusicianId, SortOrder FROM tblTuneCredits WHERE TuneId = ?');
        $stmt->bind_param('i', $sourceId);
        $stmt->execute();
        $sourceCredits = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $stmt = $db->prepare('SELECT Role, Name FROM tblTuneCredits WHERE TuneId = ?');
        $stmt->bind_param('i', $targetId);
        $stmt->execute();
        $targetPairs = [];
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $tc) {
            $targetPairs[$tc['Role'] . "\0" . $tc['Name']] = true;
        }
        $stmt->close();

        $ins = $db->prepare(
            'INSERT INTO tblTuneCredits (TuneId, Role, Name, MusicianId, SortOrder) VALUES (?, ?, ?, ?, ?)'
        );
        foreach ($sourceCredits as $c) {
            $key = $c['Role'] . "\0" . $c['Name'];
            if (isset($targetPairs[$key])) continue;
            $role       = (string)$c['Role'];
            $name       = (string)$c['Name'];
            $musicianId = $c['MusicianId'] !== null ? (int)$c['MusicianId'] : null;
            $sortOrder  = (int)$c['SortOrder'];
            $ins->bind_param('issii', $targetId, $role, $name, $musicianId, $sortOrder);
            $ins->execute();
            $targetPairs[$key] = true;
            $creditsMoved++;
        }
        $ins->close();
    }

    $linksMoved = 0;
    if (!empty($gates['hasLinks'])) {
        $stmt = $db->prepare('SELECT LinkTypeId, Url, Note, SortOrder, Verified FROM tblTuneExternalLinks WHERE TuneId = ?');
        $stmt->bind_param('i', $sourceId);
        $stmt->execute();
        $sourceLinks = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $stmt = $db->prepare('SELECT LinkTypeId, Url FROM tblTuneExternalLinks WHERE TuneId = ?');
        $stmt->bind_param('i', $targetId);
        $stmt->execute();
        $targetPairs = [];
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $tl) {
            $targetPairs[$tl['LinkTypeId'] . "\0" . $tl['Url']] = true;
        }
        $stmt->close();

        $ins = $db->prepare(
            'INSERT INTO tblTuneExternalLinks (TuneId, LinkTypeId, Url, Note, SortOrder, Verified) VALUES (?, ?, ?, ?, ?, ?)'
        );
        foreach ($sourceLinks as $l) {
            $key = $l['LinkTypeId'] . "\0" . $l['Url'];
            if (isset($targetPairs[$key])) continue;
            $linkTypeId = (int)$l['LinkTypeId'];
            $url        = (string)$l['Url'];
            $note       = $l['Note'] !== null ? (string)$l['Note'] : null;
            $sortOrder  = (int)$l['SortOrder'];
            $verified   = (int)$l['Verified'];
            $ins->bind_param('iissii', $targetId, $linkTypeId, $url, $note, $sortOrder, $verified);
            $ins->execute();
            $targetPairs[$key] = true;
            $linksMoved++;
        }
        $ins->close();
    }

    $stmt = $db->prepare('DELETE FROM tblTunes WHERE Id = ?');
    $stmt->bind_param('i', $sourceId);
    $stmt->execute();
    $stmt->close();

    return [
        'source'         => ['id' => $sourceId, 'name' => $sourceName],
        'target'         => ['id' => $targetId, 'name' => $targetName],
        'songsRepointed' => $songsRepointed,
        'worksRepointed' => $worksRepointed,
        'aliasesMoved'   => $aliasesMoved,
        'creditsMoved'   => $creditsMoved,
        'linksMoved'     => $linksMoved,
    ];
}
