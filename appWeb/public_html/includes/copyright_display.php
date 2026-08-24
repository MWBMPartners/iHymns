<?php

declare(strict_types=1);

/**
 * iHymns — Copyright display statement fold (#1862, epic #1863)
 * ================================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * A song's copyright line on the page is built from up to three separate
 * pieces of data: the structured "year(s)" field, the structured "holder"
 * field, and an old free-text field from before those two existed. This file
 * is the ONE place that decides, given those three strings, what sentence to
 * actually print — so the public page, and anything else that ever needs the
 * same sentence, agree with each other by construction instead of by two
 * people copying the same logic correctly.
 *
 * DETAILED — WHY THIS EXISTS, AND WHY THE PRECEDENCE IS UNCHANGED
 * ----------------------------------------------------------------------------
 * `includes/pages/song.php` has computed this exact fold inline since #1741
 * P1 (`$copyrightSplit = trim($copyrightYears . ' ' . $copyrightHolder);
 * $copyrightDisplay = $copyrightSplit !== '' ? $copyrightSplit :
 * trim((string)$copyright);`). #1862 §3 extracted it verbatim into a named,
 * testable function so a SECOND consumer (the Editor2 metadata tab's live
 * "Displayed as: …" preview, #1862 sub-build C) can share the exact same
 * decision instead of re-typing it in JavaScript with no mechanism keeping
 * the two in agreement (rule #35 — "a comment saying keep these in sync is
 * the failure, not the fix"). `tests/fixtures/copyright-statement-cases.json`
 * is the ONE fixture list both the PHP truth-table test
 * (tests/php/test-editor2-metadata-1862.php) and the JS lockstep test
 * (tests/test-copyright-preview-lockstep.js) replay against this function
 * and its JS twin — see metadata-tab.js's exported `ihymnsCopyrightPreview()`.
 *
 * PRECEDENCE (decision D5 in the #1862 spec — deliberately UNCHANGED):
 * the structured split (years + holder) wins whenever EITHER half is
 * non-empty; the legacy free-text `Copyright` column is the fallback used
 * only when BOTH structured fields are empty. This is NOT "free text always
 * overrides" — a curator who wants a genuinely custom statement must leave
 * Years + Holder blank and rely on the override text (metadata-tab.js's
 * "Custom statement (override)" disclosure). Flipping this to
 * override-always-wins would regress the public/native display for every
 * scrape-era song whose curator has since filled the structured fields
 * (legacy `Copyright` is never auto-cleared — the #1741 P1 contract) and
 * would change the #1750/#4 web=native API display contract; that is a
 * scoped follow-up with its own data pass, not this fold.
 *
 * Public/native API emissions of the raw `copyright` / `copyrightYears` /
 * `copyrightHolder` fields are UNCHANGED by this file — this is a DISPLAY
 * fold only, consumed by page renderers, never a mutation of stored data or
 * of what SongData/api.php emit.
 *
 * MULTI-HOLDER EXTENSION (#1900, Wave 4 Commit C7) — ADDITIVE, BYTE-IDENTICAL
 * DEFAULT. `ihymns_copyright_statement()` grows an optional 4th parameter,
 * `array $holders = []`, so the multi-holder registry
 * (`tblSongCopyrightHolders`, `includes/song_copyright_holders.php`) can
 * render "A / B" for a song with more than one holder without a second
 * statement-builder anywhere (rule #22). This is dormant plumbing only:
 * NOTHING calls this function with a non-empty `$holders` yet — that wiring
 * is Wave 4 Commit C8. The 4th parameter defaults to `[]`, and when it is
 * empty the function's behaviour is BYTE-IDENTICAL to the 3-arg form that
 * existed before this change — every existing call site (`song.php`) and
 * the `tests/fixtures/copyright-statement-cases.json` PHP<->JS lockstep
 * fixture (which only ever exercises the 3-arg shape) are therefore
 * unaffected. When `$holders` IS non-empty, its entries (already-resolved
 * display names, trimmed, blanks dropped, order preserved) replace the
 * single `$holder` string in the structured half of the fold, joined with
 * `' / '` — the years-vs-legacy precedence above is otherwise unchanged.
 * The JS twin (`metadata-tab.js`'s `ihymnsCopyrightPreview()`) is
 * deliberately NOT touched by this commit — it still only reads the 3-arg
 * shape, which is exactly what keeps the existing lockstep fixture green
 * without a fixture rewrite; growing the JS twin to accept a holders array
 * is part of C8's UI wiring, not this dormant schema-and-core commit.
 *
 * Direct access is blocked (same guard as publisher_helpers.php /
 * musician_helpers.php) so this file can't be requested as an endpoint via
 * an open Apache config.
 *
 * @link appWeb/public_html/includes/pages/song.php                         the ONE call site today
 * @link appWeb/public_html/manage/editor/v2/metadata-tab.js                the JS twin (ihymnsCopyrightPreview()) — unchanged by #1900 C7
 * @link appWeb/public_html/includes/song_copyright_holders.php             the #1900 multi-holder core that will pass $holders (from C8)
 * @link tests/fixtures/copyright-statement-cases.json                     the shared PHP<->JS truth table (3-arg shape only)
 * @link tests/php/test-editor2-metadata-1862.php                          the PHP side of the lockstep guard
 * @link tests/test-copyright-preview-lockstep.js                          the JS side of the lockstep guard
 * @link tests/php/test-song-copyright-holders.php                         the #1900 guard covering the $holders join
 * @see #1862, epic #1863, #1900
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

if (!function_exists('ihymns_copyright_statement')) {
    /**
     * The ONE copyright-statement precedence fold (#1862; #1750/#4 API
     * contract). Byte-identical to the inline logic it replaces
     * (song.php's pre-#1862 `$copyrightSplit`/`$copyrightDisplay` pair) when
     * `$holders` is omitted or empty — see the file doc-block's "MULTI-HOLDER
     * EXTENSION" section for why that default-preserves-output guarantee
     * matters (#1900 Wave 4 Commit C7).
     *
     * ELI5: if a year or a holder name is set, show those (trimmed,
     * space-joined); otherwise fall back to whatever is in the old
     * free-text field. If the caller instead hands us a LIST of holder
     * names (a song with more than one copyright holder), join them with
     * " / " and use that joined string in place of the single holder name
     * — everything else about the decision stays the same.
     *
     * @param string        $years   tblSongs.CopyrightYears (e.g. "1978, 1987").
     * @param string        $holder  tblSongs.CopyrightHolder (e.g. "Hope Publishing Co.").
     *                                Ignored when $holders is non-empty.
     * @param string        $legacy  tblSongs.Copyright — the pre-#1741-P1 as-printed
     *                                free text, used only when BOTH the years
     *                                half and the resolved holder half are
     *                                empty/whitespace-only.
     * @param array<int,string> $holders  Optional (#1900) ordered list of
     *                                resolved multi-holder display names
     *                                (e.g. from `songCopyrightHoldersList()`).
     *                                Blank entries are dropped, order is
     *                                preserved, surviving entries are joined
     *                                with " / ". DEFAULT `[]` — an empty (or
     *                                all-blank) list falls back to `$holder`
     *                                unchanged, which is what keeps every
     *                                existing 3-arg call site and the
     *                                PHP<->JS lockstep fixture byte-identical.
     * @return string The statement to display — '' when all inputs are empty.
     */
    function ihymns_copyright_statement(string $years, string $holder, string $legacy, array $holders = []): string
    {
        $holderNames = array_values(array_filter(array_map('trim', $holders), static fn(string $h): bool => $h !== ''));
        $holderPart  = $holderNames !== [] ? implode(' / ', $holderNames) : $holder;

        $split = trim(trim($years) . ' ' . trim($holderPart));
        return $split !== '' ? $split : trim($legacy);
    }
}
