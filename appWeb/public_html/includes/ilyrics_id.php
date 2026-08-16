<?php

declare(strict_types=1);

/**
 * iHymns — iLyrics internal ID (`IL*`) allocator core (#1860 Phase 1)
 * ================================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * Every song, songbook, musician, tune, publisher, collection, work and
 * media file is eventually going to get its OWN permanent catalogue
 * number — something like `ILS0000012345` — that never changes even if
 * the song moves to a different book or gets renamed. This file is the
 * ONE place that knows how to build and hand out those numbers. Nothing
 * in the live app calls it yet: Phase 1 (this file) is schema + this
 * dormant core only; Phase 2 wires it into the create funnels.
 *
 * DETAILED
 * --------
 * `IHYMNS_ILID_TYPES` is the ONE prefix -> table registry (rule #22/#35):
 * every function below derives its behaviour from this map — there is no
 * second hardcoded list of prefixes or entity types anywhere else in the
 * app, and the strict validator's character class (`ilidPattern()`) is
 * BUILT from the map at runtime rather than typed as a literal. Only the
 * test file's independent oracle regex is allowed to type the strict
 * form a second time, deliberately, so a map edit is a conscious
 * two-place change (rule #34).
 *
 * Format: `<PFX><digits>` where `<PFX>` is `IL` + one entity letter
 * (`ILS`, `ILW`, …) and `<digits>` is a 10-digit zero-padded decimal —
 * NO separator (design doc §2.2, owner sign-off 2026-08-16:
 * `ILS0000012345`). The missing hyphen is deliberate: the public SongId
 * grammar is always `<letters>-<digits>` (`MP-1008`), so an IL id can
 * never be mistaken for one — the two namespaces are provably disjoint
 * by grammar alone, which is what lets a future dual-addressing resolver
 * (Phase 4) branch on a single discriminator instead of guessing.
 *
 * `ilidAllocate()` is the mint. It MUST be called inside the CALLER's own
 * transaction (it issues no BEGIN/COMMIT itself) and reads
 * `tblIlyricsIdSequence` with `SELECT … FOR UPDATE` so two concurrent
 * mints of the same entity type serialise cleanly — see MySQL's own docs
 * on locking reads:
 * https://dev.mysql.com/doc/refman/8.0/en/innodb-locking-reads.html
 * The counter it reads is a SEED, not the set of ids already claimed — a
 * restored backup or a hand-edited counter could rewind it behind live
 * data, so every candidate is claim-checked against the entity table's
 * `uq_IlId` UNIQUE key before being handed back — the exact "the counter
 * is not the claim set" lesson `songRelocateIdTaken()` encodes for
 * SongIds (`includes/song_relocate.php`).
 *
 * PHASE 1 SCOPE: this file ships with ZERO production callers — the only
 * caller anywhere in the tree is `tests/php/test-ilyrics-ids.php`. No
 * resolver reads `IlId` yet, nothing mints on create yet — that is
 * Phase 2 (design doc §4).
 *
 * @link appWeb/.sql/migrate-ilyrics-internal-ids.php  creates + seeds tblIlyricsIdSequence and the 8 IlId columns this file reads/writes
 * @link appWeb/public_html/includes/songbook_validation.php  consumes ilidAbbrIsReserved() for the IL* abbreviation reservation (rule #27)
 * @see .claude/ilyrics-internal-ids-work-model-plan.md §2.2/§2.3  the full design this file implements
 * @see https://www.php.net/manual/en/function.str-pad.php  str_pad(), used by ilidFormat()
 * @see #1860
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

/**
 * THE ONE prefix -> table registry (rule #20/#22). A ninth entity later is
 * one line here plus one seed row (re-run the migration card) — no
 * ALTER, no change anywhere else. The internal EntityType key stays
 * `catalogue`, never `collection` (rule #24 — "Collection" is UI copy
 * only for `tblCatalogues`; the `ILC` prefix letter still reads
 * "Collection" in the user-facing taxonomy).
 */
const IHYMNS_ILID_TYPES = [
    'song'      => ['prefix' => 'ILS', 'table' => 'tblSongs'],
    'work'      => ['prefix' => 'ILW', 'table' => 'tblWorks'],
    'musician'  => ['prefix' => 'ILM', 'table' => 'tblMusicians'],
    'tune'      => ['prefix' => 'ILT', 'table' => 'tblTunes'],
    'publisher' => ['prefix' => 'ILP', 'table' => 'tblPublishers'],
    'catalogue' => ['prefix' => 'ILC', 'table' => 'tblCatalogues'],   // rule #24: internal type is catalogue, UI copy is Collection
    'songbook'  => ['prefix' => 'ILB', 'table' => 'tblSongbooks'],
    'document'  => ['prefix' => 'ILD', 'table' => 'tblSongMedia'],
];

/**
 * ELI5: "what three letters does this kind of thing's id start with?"
 * Detail: a straight map lookup; throws on an unknown $entityType so a
 * typo'd caller fails loud at the call site instead of silently minting
 * a malformed id.
 *
 * @throws \InvalidArgumentException on a key miss.
 */
function ilidPrefixForType(string $entityType): string
{
    if (!isset(IHYMNS_ILID_TYPES[$entityType])) {
        throw new \InvalidArgumentException("Unknown IL entity type: {$entityType}");
    }
    return IHYMNS_ILID_TYPES[$entityType]['prefix'];
}

/**
 * ELI5: "which database table holds this kind of thing?"
 * Detail: same shape as ilidPrefixForType() — see its doc-comment.
 *
 * @throws \InvalidArgumentException on a key miss.
 */
function ilidTableForType(string $entityType): string
{
    if (!isset(IHYMNS_ILID_TYPES[$entityType])) {
        throw new \InvalidArgumentException("Unknown IL entity type: {$entityType}");
    }
    return IHYMNS_ILID_TYPES[$entityType]['table'];
}

/**
 * ELI5: turn an entity type + a plain number into the printed id, e.g.
 * `ilidFormat('song', 12345)` -> `'ILS0000012345'`.
 * Detail: `str_pad($n, 10, '0', STR_PAD_LEFT)` left-pads the decimal
 * representation to exactly 10 digits (PHP manual:
 * https://www.php.net/manual/en/function.str-pad.php); no separator is
 * ever inserted (design doc §2.2 — this is what keeps the format
 * grammatically disjoint from a public SongId, which always contains a
 * hyphen).
 *
 * @throws \InvalidArgumentException if $entityType is unknown or $n is
 *         outside the representable 1..9,999,999,999 range (10 digits).
 */
function ilidFormat(string $entityType, int $n): string
{
    if ($n < 1 || $n > 9999999999) {
        throw new \InvalidArgumentException("IL id number out of range (1..9999999999): {$n}");
    }
    return ilidPrefixForType($entityType) . str_pad((string)$n, 10, '0', STR_PAD_LEFT);
}

/**
 * ELI5: builds the "does this look like a real IL id?" pattern by reading
 * the registry, so the rule lives in ONE place instead of being typed
 * out by hand everywhere it's needed.
 * Detail: with today's map this is equivalent to `^IL[SWMTPCBD]\d{10}$`,
 * but production code never types it that literal way — the character
 * class is `implode('|', …prefixes…)` over `IHYMNS_ILID_TYPES` so a
 * future 9th entity needs no second edit (rule #35). Only the test
 * file's independent oracle regex is allowed to type the strict form a
 * second time, deliberately (rule #34).
 */
function ilidPattern(): string
{
    $prefixes = implode('|', array_column(IHYMNS_ILID_TYPES, 'prefix'));
    return '/^(?:' . $prefixes . ')\d{10}$/';
}

/**
 * ELI5: "is this string a real, correctly-shaped IL id?"
 * Detail: strict — exactly 10 digits after a known prefix, uppercase, no
 * hyphen. This is the discriminator that keeps the IL namespace and the
 * public SongId namespace (which always contains `-`) provably disjoint
 * (design doc §2.2).
 */
function ilidIsValid(string $id): bool
{
    return (bool)preg_match(ilidPattern(), $id);
}

/**
 * ELI5: a tolerant human-input parser — takes whatever a person typed
 * (maybe lowercase, maybe missing leading zeros) and, if it's plausibly
 * an IL id, hands back the canonical form plus which entity type it is.
 * Detail: uppercases + trims, then matches a LOOSE `[A-Z]{3}\d{1,10}`
 * shape and requires the 3-letter prefix to be a REAL entry in the
 * registry — never throws, returns null on anything malformed. This is
 * the shared normaliser Phase 4's dual-addressing resolvers will reuse,
 * mirroring `normalizeSongId()`'s pass-through-on-mismatch contract in
 * `js/modules/router.js`. `'ILS12345'` -> canonical `'ILS0000012345'`;
 * `'MP-1008'` -> `null` (the hyphen is the namespace discriminator).
 *
 * @return array{entityType:string,prefix:string,n:int,canonical:string}|null
 */
function ilidParse(string $id): ?array
{
    $id = strtoupper(trim($id));
    if (!preg_match('/^([A-Z]{3})(\d{1,10})$/', $id, $m)) {
        return null;
    }
    [, $prefix, $digits] = $m;

    $entityType = null;
    foreach (IHYMNS_ILID_TYPES as $type => $def) {
        if ($def['prefix'] === $prefix) {
            $entityType = $type;
            break;
        }
    }
    if ($entityType === null) {
        return null;
    }

    $n = (int)$digits;
    try {
        $canonical = ilidFormat($entityType, $n);
    } catch (\InvalidArgumentException $e) {
        return null;
    }

    return [
        'entityType' => $entityType,
        'prefix'     => $prefix,
        'n'          => $n,
        'canonical'  => $canonical,
    ];
}

/**
 * ELI5: "is this songbook abbreviation one of the ones we're keeping for
 * ourselves?" — used so a curator can never create/rename a songbook to
 * `IL`, `ILS`, `ILW`, etc.
 * Detail: rule #27's reservation (design doc §2.2). Matches bare `IL`
 * and `IL` + exactly one letter (2 or 3 chars total) case-insensitively —
 * covers every CURRENT and FUTURE entity letter with no edit needed
 * here. Deliberately narrow (rule #34: a guard must never fail on
 * correct input) — longer `IL…` names like `ILSONGS` stay legal, and
 * `IL` + a DIGIT does not match (`[A-Z]?`, not `[A-Z0-9]?`).
 */
function ilidAbbrIsReserved(string $abbr): bool
{
    return (bool)preg_match('/^IL[A-Z]?$/', strtoupper(trim($abbr)));
}

/**
 * ELI5: "has the allocator's table been created yet?" — every future
 * caller of `ilidAllocate()` checks this first so an un-migrated install
 * degrades to "no id minted" instead of a fatal error.
 * Detail: one INFORMATION_SCHEMA existence probe, memoised per request
 * in a `static` local (the `orgLogoTableExists()` / `tuneTunesTableExists()`
 * idiom used elsewhere in this codebase). try/catch swallows to false on
 * any DB hiccup — a dormant-by-design surface never throws.
 */
function ilidSequenceReady(\mysqli $db): bool
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    try {
        $stmt = $db->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblIlyricsIdSequence' LIMIT 1"
        );
        $stmt->execute();
        $cached = ($stmt->get_result()->fetch_row() !== null);
        $stmt->close();
    } catch (\Throwable $e) {
        $cached = false;
    }
    return $cached;
}

/**
 * ELI5: hand out the NEXT permanent id for one kind of thing (a song, a
 * musician, …), making sure two people creating things at the same
 * moment never get handed the same number.
 *
 * Detail — CONTRACT (design doc §2.3):
 *   1. The caller must already be inside a transaction; this function
 *      issues no BEGIN/COMMIT itself, mirroring `songRelocate()`'s
 *      contract. A rolled-back save rolls the counter back too (no
 *      number burned); the type's row lock is held until the caller
 *      commits, an acceptable trade at curator create volume.
 *   2. `SELECT NextValue … FOR UPDATE` on the entity type's row — MySQL
 *      locking-read docs:
 *      https://dev.mysql.com/doc/refman/8.0/en/innodb-locking-reads.html
 *      A missing row means the migration hasn't been run — fail LOUD,
 *      never silently mint a malformed or duplicate id.
 *   3. Bounded claim-check loop (8 attempts — the `ed2_allocateSongId()`
 *      shape at `manage/editor/api2.php`): the counter is a SEED, not
 *      the claim set (a restored/rewound counter could propose an
 *      already-taken id — the `songRelocateIdTaken()` lesson), so every
 *      candidate is probed against the entity table's `uq_IlId` before
 *      being accepted.
 *   4. On acceptance, advance `NextValue` past the accepted candidate
 *      and return it.
 *
 * mysqli runs STRICT (`MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT`) — a
 * failing statement THROWS `mysqli_sql_exception`; this function never
 * checks a query/prepare result for `=== false` (that check is dead
 * code under STRICT mode, red-flag list).
 *
 * PHASE 1: this function has NO production callers — only
 * `tests/php/test-ilyrics-ids.php` exercises its structure (via source
 * inspection; it does not require a live database).
 *
 * @throws \RuntimeException if the sequence row is missing, or if 8
 *         claim-check attempts are all reported taken.
 */
function ilidAllocate(\mysqli $db, string $entityType): string
{
    $table = ilidTableForType($entityType); // throws on an unknown type

    $seedStmt = $db->prepare(
        'SELECT NextValue FROM tblIlyricsIdSequence WHERE EntityType = ? FOR UPDATE'
    );
    $seedStmt->bind_param('s', $entityType);
    $seedStmt->execute();
    $row = $seedStmt->get_result()->fetch_assoc();
    $seedStmt->close();

    if ($row === null) {
        throw new \RuntimeException(
            "tblIlyricsIdSequence has no row for '{$entityType}' — run the ilyrics-internal-ids migration card."
        );
    }

    $n = (int)$row['NextValue'];

    /* Table name comes from the const map — a hardcoded PHP-source
       constant, the ONE legitimate interpolation class per checkpoint #5;
       the VALUE ($candidate) is always bound. */
    $claimStmt = $db->prepare("SELECT 1 FROM {$table} WHERE IlId = ? LIMIT 1");

    for ($attempt = 0; $attempt < 8; $attempt++) {
        $candidate = ilidFormat($entityType, $n);

        $claimStmt->bind_param('s', $candidate);
        $claimStmt->execute();
        $taken = $claimStmt->get_result()->fetch_row() !== null;

        if (!$taken) {
            $next = $n + 1;
            $updateStmt = $db->prepare(
                'UPDATE tblIlyricsIdSequence SET NextValue = ? WHERE EntityType = ?'
            );
            $updateStmt->bind_param('is', $next, $entityType);
            $updateStmt->execute();
            $updateStmt->close();
            $claimStmt->close();
            return $candidate;
        }

        $n++;
    }

    $claimStmt->close();
    throw new \RuntimeException("Could not allocate a unique {$entityType} IL id.");
}
