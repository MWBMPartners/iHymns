<?php

declare(strict_types=1);

/**
 * markdown_lite.php — a tiny, ESCAPE-FIRST markdown-like renderer for
 * static, deploy-time-authored content (#1583 "What's New")
 * ============================================================================
 *
 * WHY THIS FILE EXISTS:
 *   The "What's New" page (`includes/pages/whats-new.php`) renders an
 *   excerpt of CHANGELOG.md that `.github/workflows/deploy.yml` copies into
 *   `data/whats-new.md` at deploy time. CHANGELOG.md is free-text written by
 *   whoever authors a commit — today that's trusted contributors, but a
 *   future typo, a pasted log snippet, or a compromised CI step could all
 *   put raw HTML into that file, and this renderer is the only thing
 *   standing between it and a live PHP response. It supports just enough
 *   markdown to make a changelog readable (headings, bold, inline code,
 *   safe links, bullet lists, paragraphs) without ever becoming a
 *   general-purpose HTML sink.
 *
 * ⚠️ ESCAPE-FIRST IS THE ENTIRE SECURITY MODEL — READ BEFORE EDITING ⚠️
 *   `markdownLiteRender()`'s FIRST operation, on the WHOLE input, is
 *   `htmlspecialchars($md, ENT_QUOTES, 'UTF-8')`. Every transform below
 *   (`## ` → `<h2>`, `**x**` → `<strong>`, …) then runs on ALREADY-ESCAPED
 *   text and re-introduces ONLY the specific tags this file chooses to
 *   emit. A transform-THEN-escape renderer would do the opposite —
 *   interpret markdown syntax into real `<tag>` characters first, then
 *   escape the whole document afterwards — which either mangles the tags
 *   it just inserted (escaping its own `<strong>` into visible text) or,
 *   the moment someone "fixes" that by skipping the tags it just inserted,
 *   opens a live-HTML-injection hole: a raw `<script>` sitting in
 *   CHANGELOG.md would sail straight through untouched. Escape-first means
 *   a literal `<script>alert(1)</script>` in the source markdown ALWAYS
 *   renders as the inert text `&lt;script&gt;alert(1)&lt;/script&gt;` —
 *   it is structurally impossible for it to execute, because by the time
 *   any `<`/`>` this file emits exist, they came from this file's own
 *   fixed set of `<h2>`/`<h3>`/`<strong>`/`<code>`/`<a>`/`<ul>`/`<li>`/`<p>`
 *   tags, never from the input. See `tests/php/test-whats-new-render.php`
 *   for the automated proof (and its "run the opposite way and watch it
 *   fail" note, matching CLAUDE.md's "a test that cannot fail is
 *   worthless" rule).
 *
 * WHY NOT A REAL MARKDOWN LIBRARY:
 *   This codebase has no Composer dependency manager (vanilla PHP
 *   throughout, per `.claude/CLAUDE.md`'s architecture) and this is the
 *   only page that needs markdown at all. The subset below covers exactly
 *   what CHANGELOG.md uses: `## ` / `### ` headings, `**bold**`,
 *   `` `code` ``, `[text](url)` links (http/https only), `- ` bullet runs,
 *   and blank-line-separated paragraphs. Anything else — raw HTML, other
 *   markdown syntax, other URL schemes — is left as plain (already-escaped)
 *   text rather than guessed at.
 *
 * @see appWeb/public_html/includes/pages/whats-new.php  the one consumer
 * @see .github/workflows/deploy.yml                     generates data/whats-new.md at deploy time
 * @see tests/php/test-whats-new-render.php               escape-first regression guard (proven able to fail)
 * @see https://www.php.net/manual/en/function.htmlspecialchars.php
 * @see https://developer.mozilla.org/en-US/docs/Web/Security/Attacks/XSS
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 */

/**
 * Scheme allow-list for `[text](url)` links.
 * ELI5: only links that start with "http://" or "https://" are allowed to
 * become clickable — anything else (like "javascript:") is left as plain
 * text instead.
 * DETAIL: a closed allow-list rather than a deny-list of dangerous schemes
 * (`javascript:`, `data:`, `vbscript:`, a protocol-relative `//host`, …) —
 * an allow-list can't be bypassed by a scheme nobody thought to block yet.
 * Checked case-insensitively (`stripos()`), matching how browsers treat
 * URL schemes.
 */
const MARKDOWN_LITE_ALLOWED_SCHEMES = ['http://', 'https://'];

/**
 * Render a small, safe subset of markdown to an HTML fragment.
 *
 * ELI5: turns a plain-text changelog entry into a nicely formatted page —
 * bold words look bold, `code` looks like code, safe links are clickable —
 * but anything that ISN'T one of those few specific patterns stays exactly
 * as safe, inert text. It can never end up running a script.
 *
 * DETAIL: escape-first (see file doc-block — load-bearing); then a small
 * line-oriented block parser walks the now-escaped lines, grouping them
 * into headings / a run of `- ` bullets / blank-line-separated paragraphs,
 * and `_markdownLiteInline()` applies the inline transforms (bold, code,
 * link) within each block's text. No block or inline rule is recursive —
 * each character of the input is visited by at most one transform, so
 * there is no risk of a later pass re-interpreting a tag an earlier pass
 * inserted.
 *
 * @param string $md Raw markdown-ish text (untrusted — e.g. deploy-time
 *                    CHANGELOG.md excerpt).
 * @return string HTML fragment — safe to echo directly into a page.
 */
function markdownLiteRender(string $md): string
{
    /* Step 1 — ESCAPE FIRST (see the file doc-block; this ordering IS the
       security model). Every byte of the input is neutralised before a
       single markdown rule runs, so nothing downstream can ever emit a
       tag the input itself supplied. */
    $escaped = htmlspecialchars($md, ENT_QUOTES, 'UTF-8');

    /* Step 2 — split into lines and walk them, grouping into blocks.
       preg_split() only returns false on a regex-engine failure (never on
       "no matches"), so the fallback here is defensive, not a real path. */
    $lines = preg_split('/\r\n|\r|\n/', $escaped);
    if ($lines === false) {
        $lines = [$escaped];
    }

    $html      = '';
    $paragraph = [];
    $listItems = [];

    foreach ($lines as $line) {
        $trimmed = trim($line);

        if ($trimmed === '') {
            /* A blank line ends whichever block is currently open —
               mirrors how a real markdown parser treats blank lines as
               block separators. */
            $html = _markdownLiteFlushParagraph($html, $paragraph);
            $html = _markdownLiteFlushList($html, $listItems);
            continue;
        }

        /* `### Heading` → <h3>. Checked BEFORE the `##` rule below purely
           for readability (H3-before-H2, most-specific-first) — it isn't
           load-bearing here, since `^##\s+` requires whitespace
           IMMEDIATELY after the two hashes and a `###` line has a third
           `#` in that position instead, so the `##` rule can never
           accidentally swallow a `###` line even if the order were
           reversed. */
        if (preg_match('/^###\s+(.*)$/', $trimmed, $m) === 1) {
            $html  = _markdownLiteFlushParagraph($html, $paragraph);
            $html  = _markdownLiteFlushList($html, $listItems);
            $html .= '<h3>' . _markdownLiteInline($m[1]) . "</h3>\n";
            continue;
        }

        /* `## Heading` → <h2>. */
        if (preg_match('/^##\s+(.*)$/', $trimmed, $m) === 1) {
            $html  = _markdownLiteFlushParagraph($html, $paragraph);
            $html  = _markdownLiteFlushList($html, $listItems);
            $html .= '<h2>' . _markdownLiteInline($m[1]) . "</h2>\n";
            continue;
        }

        /* `- item` — a run of consecutive bullet lines becomes one <ul>;
           the run only closes on a blank line or a heading (both flush
           $listItems above), so CHANGELOG.md's multi-line bullet runs
           collapse into a single list rather than one <ul> per line. */
        if (preg_match('/^-\s+(.*)$/', $trimmed, $m) === 1) {
            $html        = _markdownLiteFlushParagraph($html, $paragraph);
            $listItems[] = _markdownLiteInline($m[1]);
            continue;
        }

        /* Anything else is paragraph text. Consecutive non-blank lines
           join into ONE <p> (a soft-wrap) rather than one <p> per line —
           CHANGELOG.md's entries are long single logical lines and this
           keeps a hand-wrapped source line from becoming a stray break. */
        $html        = _markdownLiteFlushList($html, $listItems);
        $paragraph[] = _markdownLiteInline($trimmed);
    }

    /* End of input — flush whatever block is still open (matches the
       common case of a file with no trailing blank line). */
    $html = _markdownLiteFlushParagraph($html, $paragraph);
    $html = _markdownLiteFlushList($html, $listItems);

    return $html;
}

/**
 * Close an in-progress paragraph block, appending its `<p>` to `$html`.
 *
 * ELI5: takes whatever plain sentences we've collected so far and wraps
 * them up as one paragraph.
 * DETAIL: takes/returns by value (not by reference) so `markdownLiteRender()`
 * stays a single, easy-to-read top-to-bottom function with no closures
 * capturing mutable state — each call site reassigns `$html`/`$paragraph`
 * from the return values.
 *
 * @param string $html      Accumulated HTML so far.
 * @param array<int,string> $paragraph Buffered, already-inline-transformed
 *                                     paragraph lines; cleared on return.
 * @return string Updated `$html`.
 */
function _markdownLiteFlushParagraph(string $html, array &$paragraph): string
{
    if ($paragraph !== []) {
        $html .= '<p>' . implode(' ', $paragraph) . "</p>\n";
        $paragraph = [];
    }
    return $html;
}

/**
 * Close an in-progress bullet-list block, appending its `<ul>` to `$html`.
 *
 * ELI5: takes whatever bullet points we've collected so far and wraps them
 * up as one list.
 * DETAIL: see `_markdownLiteFlushParagraph()` above for why this is a
 * plain helper rather than a closure.
 *
 * @param string $html      Accumulated HTML so far.
 * @param array<int,string> $listItems Buffered, already-inline-transformed
 *                                     `<li>` contents; cleared on return.
 * @return string Updated `$html`.
 */
function _markdownLiteFlushList(string $html, array &$listItems): string
{
    if ($listItems !== []) {
        $html .= "<ul>\n";
        foreach ($listItems as $item) {
            $html .= '<li>' . $item . "</li>\n";
        }
        $html .= "</ul>\n";
        $listItems = [];
    }
    return $html;
}

/**
 * Apply inline transforms (`` `code` ``, `**bold**`, `[text](url)`) to one
 * already-escaped line/heading/list-item's text.
 *
 * ELI5: looks for the three small patterns markdown uses INSIDE a line of
 * text — backticks, double-stars, and square-bracket-then-parens — and
 * swaps each one for its styled HTML equivalent.
 *
 * DETAIL: all three patterns are matched by ONE `preg_replace_callback()`
 * alternation rather than three sequential `preg_replace()` calls. That
 * matters: `preg_replace_callback()` visits each match in the ORIGINAL
 * string exactly once and does not re-scan text it has already replaced,
 * so text a callback just inserted (e.g. the `<code>` this function adds)
 * can never be re-interpreted by a later rule in the same alternation —
 * three separate sequential calls would not have that guarantee, since
 * each later call re-scans the PREVIOUS call's output including whatever
 * it just inserted.
 * https://www.php.net/manual/en/function.preg-replace-callback.php
 *
 * @param string $escaped Already-`htmlspecialchars()`-escaped text.
 * @return string Text with inline markdown swapped for HTML.
 */
function _markdownLiteInline(string $escaped): string
{
    /* Alternation groups, in match-priority order (left-most-wins per
       position, standard PCRE semantics):
         1 = `` `code` ``          → <code>
         2 = `**bold**`            → <strong>
         3/4 = `[text](url)`       → <a> (scheme-allow-listed) or left as-is */
    $pattern = '/`([^`]+)`|\*\*([^*]+)\*\*|\[([^\]]+)\]\((\S+)\)/';

    $out = preg_replace_callback($pattern, '_markdownLiteInlineReplace', $escaped);
    /* preg_replace_callback() returns null only on a regex-engine error
       (e.g. backtrack-limit exhaustion on pathological input); degrade to
       the still-safe (already-escaped) un-transformed text rather than
       emitting null into the page. */
    return $out ?? $escaped;
}

/**
 * `preg_replace_callback()` handler for `_markdownLiteInline()`'s single
 * alternation — decides which of the three inline rules fired and returns
 * its HTML.
 *
 * DETAIL: with PCRE alternation, only the groups belonging to the
 * alternative that actually matched are populated — earlier groups that
 * didn't match come back as `''`, and groups AFTER the last one that
 * matched are omitted from `$m` entirely (not merely empty), hence the
 * `?? ''` on every lookup rather than a bare `$m[n]`.
 *
 * @param array<int,string> $m preg_replace_callback() match array.
 * @return string Replacement HTML (or the original bracket/paren text,
 *                unchanged, for a link whose scheme isn't allow-listed).
 */
function _markdownLiteInlineReplace(array $m): string
{
    $code = $m[1] ?? '';
    if ($code !== '') {
        return '<code>' . $code . '</code>';
    }

    $bold = $m[2] ?? '';
    if ($bold !== '') {
        return '<strong>' . $bold . '</strong>';
    }

    $linkText = $m[3] ?? '';
    $linkUrl  = $m[4] ?? '';
    if ($linkText !== '' && $linkUrl !== '') {
        foreach (MARKDOWN_LITE_ALLOWED_SCHEMES as $scheme) {
            if (stripos($linkUrl, $scheme) === 0) {
                /* rel="noopener" — the linked page can't reach back into
                   this window via window.opener (reverse tabnabbing);
                   target="_blank" — a changelog link should leave the SPA
                   open in its own tab rather than navigating away from it. */
                return '<a href="' . $linkUrl . '" rel="noopener" target="_blank">' . $linkText . '</a>';
            }
        }
        /* Disallowed scheme (javascript:, data:, vbscript:, …) — render
           the original `[text](url)` text unchanged rather than as a
           link. It is already htmlspecialchars()-escaped (this function
           only ever runs on escaped input), so this is inert either way;
           the point of rejecting it is that it must never become a live,
           clickable `<a href="...">`. */
        return '[' . $linkText . '](' . $linkUrl . ')';
    }

    /* Alternation matched nothing usable (shouldn't happen given the
       regex, but keeps this function total) — return the untouched match. */
    return $m[0];
}
