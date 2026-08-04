<?php

declare(strict_types=1);

/**
 * iHymns — the ONE "is this identifier safe to backtick?" gate (#1698)
 * ====================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * You cannot ask MySQL to fill in a table name for you the way you can a value —
 * `WHERE x = ?` works, `DELETE FROM ?` does not. So any code that has to name a
 * table it only learned about at run time must paste that name into the query
 * text. This file is the single check that says "that name is boring enough to
 * paste".
 *
 * WHY IT IS A FILE OF ITS OWN
 * ---------------------------
 * The check was written once, for the songbook move's child-row probe
 * (`songRelocateIdentifierSafe()`, #1690). The account-erasure core (#1698)
 * needs exactly the same property for exactly the same reason — it enumerates
 * the foreign keys referencing `tblUsers(Id)` from `INFORMATION_SCHEMA` and then
 * has to write `DELETE FROM \`<that table>\`` — and CLAUDE.md's modularity rule
 * is explicit that the second occurrence of a piece of logic is an extraction,
 * not a copy. A second regex would be a second thing to get subtly wrong, and
 * the way THIS one was subtly wrong is instructive (see `\z` below).
 *
 * `songRelocateIdentifierSafe()` now delegates here and keeps its name, because
 * `tests/php/test-song-relocate-cascade-verdict.php` calls it by name and a
 * rename would have been a change to Piece 2 rather than a reuse of it.
 *
 * RULE #5, THE TWO CLAUSES — which one applies is the caller's job to state
 * -----------------------------------------------------------------------
 * CLAUDE.md rule #5 permits exactly two kinds of interpolation into SQL:
 *   (a) hardcoded constants from PHP source — a column name out of a fixed
 *       `$mappings` array, a `?,?,?` string built by `array_fill()`;
 *   (b) a value that has FIRST been validated against an exact allow-list.
 * An identifier read back from `INFORMATION_SCHEMA` is clause (b). It is server
 * metadata rather than user input, but "not user input" has never been a licence
 * to concatenate: a table created by some other tool, a restored backup, a
 * hand-run `CREATE TABLE`, all land in `INFORMATION_SCHEMA` without passing
 * through this codebase. Every caller must therefore validate, backtick, and say
 * in a comment WHICH clause it is relying on.
 *
 * @see appWeb/public_html/includes/song_relocate.php     clause (a) + (b), #1690
 * @see appWeb/public_html/includes/account_lifecycle.php clause (b), #1698
 * @link https://dev.mysql.com/doc/refman/8.0/en/identifiers.html
 * @link https://www.php.net/manual/en/regexp.reference.anchors.php
 */

/* Direct access blocked so this can't be loaded as an endpoint. */
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

/**
 * Is this identifier safe to interpolate between backticks? — PURE.
 *
 * ELI5: letters, digits, underscore and `$`, and at least one of them. Anything
 * else — a space, a backtick, a semicolon, an accent — is refused.
 *
 * Detail: the charset is the UNQUOTED-identifier set MySQL itself documents.
 * It is deliberately NARROWER than what MySQL will accept inside backticks (a
 * quoted identifier may contain almost anything), because the job here is not
 * "could MySQL parse this" but "can I be certain that quoting it changes
 * nothing". A backtick inside the value is the one character that could end the
 * quoting early, and refusing the whole non-ASCII range costs nothing this
 * codebase uses — every table and column in `schema.sql` is ASCII.
 *
 * `\z`, NOT `$`. PCRE's `$` also matches immediately BEFORE a final newline, so
 * `"tblSongs\n"` passed the first version of this function — a defect caught by
 * `tests/php/test-song-relocate-cascade-verdict.php` on its very first run. The
 * consequence there was a broken query rather than an injection, but "the value
 * I validated is the value I interpolated" is the entire property this function
 * claims, and a validator that is not exact does not have it. `D`
 * (PCRE_DOLLAR_ENDONLY) would do the same job; `\z` says so at the point it
 * matters.
 *
 * Declared `function_exists`-guarded rather than plain, because both this file
 * and `song_relocate.php` may be reached through several `require_once` paths in
 * one request (api.php, the editor APIs, a CLI test) and a redeclaration is a
 * fatal, not a warning.
 */
if (!function_exists('ihymnsSqlIdentifierSafe')) {
    function ihymnsSqlIdentifierSafe(string $identifier): bool
    {
        return $identifier !== '' && preg_match('/\A[A-Za-z0-9_$]+\z/', $identifier) === 1;
    }
}
