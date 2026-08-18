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
 * Direct access is blocked (same guard as publisher_helpers.php /
 * musician_helpers.php) so this file can't be requested as an endpoint via
 * an open Apache config.
 *
 * @link appWeb/public_html/includes/pages/song.php                         the ONE call site today
 * @link appWeb/public_html/manage/editor/v2/metadata-tab.js                the JS twin (ihymnsCopyrightPreview())
 * @link tests/fixtures/copyright-statement-cases.json                     the shared PHP<->JS truth table
 * @link tests/php/test-editor2-metadata-1862.php                          the PHP side of the lockstep guard
 * @link tests/test-copyright-preview-lockstep.js                          the JS side of the lockstep guard
 * @see #1862, epic #1863
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

if (!function_exists('ihymns_copyright_statement')) {
    /**
     * The ONE copyright-statement precedence fold (#1862; #1750/#4 API
     * contract). Byte-identical to the inline logic it replaces
     * (song.php's pre-#1862 `$copyrightSplit`/`$copyrightDisplay` pair).
     *
     * ELI5: if a year or a holder name is set, show those (trimmed,
     * space-joined); otherwise fall back to whatever is in the old
     * free-text field.
     *
     * @param string $years  tblSongs.CopyrightYears (e.g. "1978, 1987").
     * @param string $holder tblSongs.CopyrightHolder (e.g. "Hope Publishing Co.").
     * @param string $legacy tblSongs.Copyright — the pre-#1741-P1 as-printed
     *                       free text, used only when BOTH $years and
     *                       $holder are empty/whitespace-only.
     * @return string The statement to display — '' when all three inputs
     *                are empty.
     */
    function ihymns_copyright_statement(string $years, string $holder, string $legacy): string
    {
        $split = trim(trim($years) . ' ' . trim($holder));
        return $split !== '' ? $split : trim($legacy);
    }
}
