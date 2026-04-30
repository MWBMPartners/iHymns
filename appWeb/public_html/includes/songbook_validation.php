<?php

declare(strict_types=1);

/**
 * iHymns — Songbook input validators (#719 PR 2a)
 *
 * Single source of truth for songbook field validation. Used by:
 *
 *   - /manage/songbooks.php   (web admin: create + update handlers)
 *   - /api.php                (admin_songbook_* CRUD endpoints)
 *
 * Each validator returns NULL on a valid input or a human-readable
 * error string on failure. Callers compose the result with their own
 * error-display strategy (banner on the web admin, JSON envelope
 * + 400 on the API).
 *
 * Length caps mirror the column widths in `appWeb/.sql/schema.sql`
 * for tblSongbooks. Pattern checks mirror the established
 * conventions: ASCII-alphanumeric abbreviation, #RRGGBB colour,
 * IETF BCP 47 language subtag form per #681.
 *
 * Direct access is blocked so this file can't be loaded as an
 * arbitrary endpoint via an open Apache config.
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

/**
 * Validate a songbook abbreviation. The natural key on tblSongs
 * (every song carries SongbookAbbr); renaming has cascade implications
 * which is why the form is opt-in. Caller decides whether the value
 * is a NEW abbreviation (validate against existing rows) or simply
 * a valid shape — that part stays in the call site since the SQL
 * varies by surface.
 *
 * @param string $abbr Raw abbreviation as typed.
 * @return string|null Error message or null if valid.
 */
function validateSongbookAbbr(string $abbr): ?string
{
    $abbr = trim($abbr);
    if ($abbr === '') {
        return 'Abbreviation is required.';
    }
    if (strlen($abbr) > 10) {
        return 'Abbreviation must be 10 characters or fewer.';
    }
    if (!preg_match('/^[A-Za-z0-9]+$/', $abbr)) {
        return 'Abbreviation must be letters/numbers only (no spaces or punctuation).';
    }
    return null;
}

/**
 * Validate a songbook hex colour. Empty is OK — the auto-pick
 * fallback (#677) chooses a palette tone at render. A non-empty
 * value must be the canonical 7-char `#RRGGBB` form; 3-char
 * shorthand and alpha variants are intentionally rejected so the
 * downstream renderers can assume one shape.
 *
 * @param string $c Raw colour as typed (or '').
 * @return string|null Error message or null if valid.
 */
function validateSongbookColour(string $c): ?string
{
    if ($c === '') {
        return null;
    }
    if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $c)) {
        return 'Colour must be a #RRGGBB hex value (or blank).';
    }
    return null;
}

/**
 * Validate an IETF BCP 47 language tag (#681). Empty is fine
 * (NULL = "not specified" for songbooks). Otherwise must match the
 * v1 grammar: lowercase 2-3 letter language, optional 4-letter
 * Title Case script, optional 2-letter UPPER region or 3-digit
 * numeric area code. Variants / extensions / private-use are out
 * of scope for v1 per the issue brief.
 *
 * Returns null if valid (empty or matching), an error message
 * string otherwise. Caller is responsible for calling mb_substr to
 * cap to the column width regardless — the regex doesn't bound
 * length on its own.
 *
 * @param string $tag Raw tag as typed (or '').
 * @return string|null Error message or null if valid.
 */
function validateSongbookBcp47(string $tag): ?string
{
    if ($tag === '') {
        return null;
    }
    if (strlen($tag) > 35) {
        return 'Language tag must be 35 characters or fewer.';
    }
    if (!preg_match('/^[a-z]{2,3}(-[A-Z][a-z]{3})?(-[A-Z]{2}|-[0-9]{3})?$/', $tag)) {
        return 'Language tag must be a valid IETF BCP 47 form (e.g. en, pt-BR, zh-Hans-CN).';
    }
    return null;
}

/**
 * Best-effort affiliation registry sync (#670). INSERT IGNORE the
 * supplied affiliation name into tblSongbookAffiliations so the
 * typeahead surfaces it on the next save. Silent no-op when:
 *
 *   - $name is null or empty after trim
 *   - tblSongbookAffiliations doesn't exist (pre-migration deployment)
 *
 * Same behaviour the closure in songbooks.php had previously — extracted
 * here so the API CRUD endpoints can run the same registry sync without
 * duplicating the logic.
 *
 * @param mysqli   $db  Database handle.
 * @param ?string  $name Raw affiliation name; null/empty is a no-op.
 */
function registerSongbookAffiliation(\mysqli $db, ?string $name): void
{
    if ($name === null) {
        return;
    }
    $trimmed = trim($name);
    if ($trimmed === '') {
        return;
    }
    /* Cap to the column width so a crafted input can't crash the
       INSERT. mb_substr is unicode-safe for languages whose glyphs
       are multi-byte. */
    $trimmed = mb_substr($trimmed, 0, 120);
    try {
        $stmt = $db->prepare('INSERT IGNORE INTO tblSongbookAffiliations (Name) VALUES (?)');
        $stmt->bind_param('s', $trimmed);
        $stmt->execute();
        $stmt->close();
    } catch (\Throwable $e) {
        /* Most likely cause: the migration hasn't been run yet so the
           table doesn't exist. Best-effort sync; the parent operation
           is unaffected. */
        error_log('[songbook_validation] affiliation registry sync skipped: ' . $e->getMessage());
    }
}
