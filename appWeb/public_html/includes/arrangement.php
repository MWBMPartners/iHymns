<?php

declare(strict_types=1);

/**
 * iHymns — the ONE ArrangementJson validator (#1627 item 2, extracted #1618/#161)
 *
 * ELI5
 * ----
 * A song's "arrangement" is just a running order — a list of positions saying
 * "play section 0, then 1, then 1 again, then 2". This file is the single place
 * that decides whether such a list is valid, so the editor that WRITES one and
 * the cutover gate that AUDITS one can never disagree about it.
 *
 * WHY IT EXISTS
 * -------------
 * The same ordinal maths was written twice, in two places that must agree:
 *
 *   includes/song_importers.php::_sanitiseArrangement()   — the WRITE side
 *       (save_song_core.php:303 and the ZIP importer both go through it)
 *   appWeb/.sql/verify-lyrics-cutover.php  gate G4 (#1618) — the AUDIT side
 *
 * That is the "two lists that must agree with nothing enforcing it" shape this
 * codebase keeps rediscovering. It is worse than usual here because of what
 * disagreement costs: if the writer's bound is looser than the gate's, curators
 * quietly persist arrangements the gate rejects, and the #1618 cutover
 * verification goes red on real data — blocking the destructive C6 drop that
 * epic #1601 exists to unblock. The editor would be manufacturing its own
 * blocker, months later, with no obvious link back.
 *
 * THE ASYMMETRY IS DELIBERATE — DO NOT "TIDY" IT AWAY
 * --------------------------------------------------
 * The two callers were not identical, and collapsing them into one rule would
 * break one of them:
 *
 *   _sanitiseArrangement() accepts an int OR a digit-string ("3"), because it
 *   handles INPUT — JSON bodies and imported files legitimately carry numeric
 *   strings. It coerces, so what reaches storage is always ints.
 *
 *   G4 requires a strict int, because it judges what is ALREADY STORED. After
 *   coercion a stored "3" would mean something upstream bypassed the writer,
 *   which is exactly the anomaly the gate should report.
 *
 * So coercion lives ONLY in arrangementSanitise(); strictness lives ONLY in
 * arrangementViolations(). Same bounds, different entry points.
 *
 * WHAT IS NOT CHECKED, ON PURPOSE
 * -------------------------------
 * Repetition is legal and expected — [0,1,1,2,3] is the schema's own example
 * (a verse/chorus/chorus/… running order is the entire point). Neither
 * uniqueness nor full coverage of the section list is required: an arrangement
 * may use a subset, and may repeat freely.
 *
 * @see appWeb/public_html/includes/song_importers.php   the write-side delegate
 * @see appWeb/.sql/verify-lyrics-cutover.php            gate G4
 * @see tests/php/test-arrangement-validator.php         incl. the "both agree" fixtures
 */

/* Direct access prevention. */
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

if (!function_exists('arrangementViolations')) {

    /**
     * Every reason a STORED arrangement is invalid. Empty array = valid.
     *
     * ELI5: hands back a list of what is wrong with a running order, so the
     * caller can report each problem individually.
     *
     * Detail: returns violations rather than a bool because gate G4 reports
     * per-ordinal detail (`position`, `value`, `componentCount`) and needs to
     * keep emitting exactly that. A bool would have forced the gate to keep its
     * own loop to build the message — i.e. keep the duplicate this extraction
     * exists to remove.
     *
     * STRICT `is_int` — see the asymmetry note in the file header. This judges
     * stored data, where a digit-string means something bypassed the writer.
     *
     * @param mixed $ordinals       Decoded arrangement (expected: list of ints).
     * @param int   $componentCount Bound: every ordinal must satisfy 0 <= i < n.
     * @return array<int, array{reason:string, position?:int, value?:mixed}>
     */
    function arrangementViolations($ordinals, int $componentCount): array
    {
        if (!is_array($ordinals)) {
            return [['reason' => 'malformed']];
        }

        $out = [];
        foreach ($ordinals as $pos => $val) {
            if (!is_int($val)) {
                $out[] = ['reason' => 'not-int', 'position' => (int)$pos, 'value' => $val];
                continue;
            }
            if ($val < 0 || $val >= $componentCount) {
                $out[] = ['reason' => 'out-of-range', 'position' => (int)$pos, 'value' => $val];
            }
        }
        return $out;
    }
}

if (!function_exists('arrangementSanitise')) {

    /**
     * Coerce + validate INPUT, returning storage-ready JSON or null.
     *
     * ELI5: takes whatever the browser or an import file sent, and gives back
     * either a clean list ready to save, or nothing at all.
     *
     * Detail: behaviour is byte-identical to the original
     * `_sanitiseArrangement()` — deliberately so, because that function's output
     * is already in the database and a subtly different rule would make existing
     * rows unreproducible. All-or-nothing on failure (a single bad ordinal
     * discards the whole arrangement rather than silently dropping one entry,
     * which would reorder the song without telling anyone).
     *
     * Returns a JSON string, not an array, so a caller can bind it straight into
     * mysqli without a second encode step — the shape every existing caller
     * already expects.
     *
     * @param mixed $raw            Decoded `arrangement` field (int|digit-string list).
     * @param int   $componentCount Bound — every index must be 0 <= i < n.
     * @return string|null          JSON int array, or null when missing / empty /
     *                              malformed / out-of-range / n <= 0.
     */
    function arrangementSanitise($raw, int $componentCount): ?string
    {
        if (!is_array($raw) || $componentCount <= 0) {
            return null;
        }

        /* Coercion pass — the ONLY place digit-strings are accepted. */
        $clean = [];
        foreach ($raw as $idx) {
            if (!is_int($idx) && !(is_string($idx) && ctype_digit($idx))) {
                return null;
            }
            $clean[] = (int)$idx;
        }

        if ($clean === []) {
            return null;
        }

        /* Validate the COERCED list with the same rule the gate applies, so a
           value this function stores can never fail G4 later. */
        if (arrangementViolations($clean, $componentCount) !== []) {
            return null;
        }

        return json_encode(array_values($clean), JSON_UNESCAPED_UNICODE);
    }
}

if (!function_exists('arrangementColumnExists')) {

    /**
     * Is `tblSongs.ArrangementJson` present on this install?
     *
     * ELI5: checks the column exists before anything tries to read or write it.
     *
     * Detail: migrations are web-run, not applied on deploy, and the three
     * docroots share one MySQL — so "it is in schema.sql" is not "it exists
     * here". mysqli runs under MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT, so
     * touching a missing column THROWS rather than returning false, and an
     * uncaught throw white-screens the page (the #1228 class, in the red-flag
     * list). Probe first.
     *
     * Memoised per request: callers may ask once per song in a loop, and this is
     * an INFORMATION_SCHEMA round-trip. The `try` swallows to false so an
     * un-migrated install degrades to "no arrangement support" rather than an
     * error page.
     */
    function arrangementColumnExists(\mysqli $db): bool
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        $cached = false;
        try {
            $probe = $db->prepare(
                "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME   = 'tblSongs'
                    AND COLUMN_NAME  = 'ArrangementJson' LIMIT 1"
            );
            $probe->execute();
            $cached = $probe->get_result()->fetch_row() !== null;
            $probe->close();
        } catch (\Throwable $_e) {
            /* default false */
        }
        return $cached;
    }
}
