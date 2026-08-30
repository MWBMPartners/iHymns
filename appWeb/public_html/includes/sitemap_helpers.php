<?php

declare(strict_types=1);

/**
 * iHymns — Sitemap pure helpers (dynamic-sitemap hardening, 2026-08-30)
 * ============================================================================
 *
 * ELI5
 * ----
 * `sitemap.xml.php` is a normal *page* — it reads `$_GET`, decides what to
 * send, and ends with `exit;`. That is exactly the shape a test can never
 * safely `require` (the `exit;` would kill the TEST, not just the page). But
 * two small pieces of its logic are pure MATH with no request/response/DB
 * involved at all — "how many 10,000-song pages does N songs need?" and
 * "turn a database date into the first 10 characters, or admit we don't have
 * one" — and a CI guard needs to run those with real numbers, not just read
 * the source text and hope it looks right. So the two pure functions live
 * HERE, in their own tiny file with nothing else in it, and `sitemap.xml.php`
 * calls them. `tests/php/test-sitemap-coverage.php` requires this one small
 * file directly and calls both functions with a real truth table.
 *
 * DETAILED / WHY EXTRACTED AS ONLY THESE TWO
 * ----------------------------------------------------------------------------
 * Every other piece of the sitemap generator (the section registry, the
 * per-entity SQL, the URL-building, the fingerprint/ETag machinery, the XML
 * renderers) stays inside `sitemap.xml.php` itself, on purpose: three
 * existing guards (`tests/test-themes-route.js`, `tests/test-writer-musician-
 * route.js`, `tests/php/test-musician-profile-fields.php`) read
 * `sitemap.xml.php`'s own source text looking for specific literals
 * (`'/musician/'`, `'/tag/'`, `FROM tblMusicians`, `'/themes'`) — moving that
 * code into a second file would silently break those guards' ability to see
 * it (rule #34's "under-reporting is worse than no scanner" applied to an
 * EXISTING guard, not just a new one). Only `sitemapPageCount()` and
 * `sitemapLastmod()` carry no such literal and gain nothing by staying
 * bundled with the request-handling flow — so only they moved.
 *
 * @see appWeb/public_html/sitemap.xml.php   the one runtime consumer
 * @see tests/php/test-sitemap-coverage.php  the one test-time consumer
 * @link .claude/CLAUDE.md rule #34           tree-derived + mutation-proven guards
 */

/* Direct access blocked, matching the house convention for an includes/*.php
   module (db_mysql.php, song_soft_delete.php, config.php, …) — belt-and-
   braces on top of .htaccess's blanket `RewriteRule ^includes/ - [F,L]`.
   Under the PHP CLI test runner $_SERVER['SCRIPT_FILENAME'] is the TEST
   file, never this one, so requiring this file from a test never trips it. */
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

/**
 * How many fixed-size pages does a paginated section need? — PURE.
 *
 * ELI5: if a songbook shelf holds 10,000 songs per box, how many boxes do you
 * need for N songs? Zero songs still needs one (empty) box, so a crawler that
 * fetches page 1 of an empty catalogue gets a valid, empty urlset rather than
 * a 404.
 *
 * @param int $total   Total rows in the section (never negative in practice;
 *                      a stray negative is floored to 0 so the function never
 *                      returns less than one page).
 * @param int $perPage IHYMNS_SITEMAP_PAGE_SIZE, or any positive page size.
 * @return int Always >= 1.
 */
function sitemapPageCount(int $total, int $perPage): int
{
    if ($perPage <= 0) {
        return 1; // a non-positive page size can't paginate anything — one page holds it all
    }
    $total = max(0, $total);
    return max(1, (int)ceil($total / $perPage));
}

/**
 * Turn a raw database date/time string into the W3C date-only `lastmod`
 * shape, or admit we don't have one — PURE.
 *
 * ELI5: `<lastmod>` wants just "2026-08-30", not the full timestamp with
 * hours and seconds — and when we genuinely don't know a date, the honest
 * answer is "say nothing" (the protocol makes `<lastmod>` optional), not
 * invent one. This is the ONE place that decision gets made, so every entity
 * section asks it the same way instead of six near-identical inline checks
 * that could quietly diverge.
 *
 * DETAILED: `$dbDate` wins when it looks like a real date-or-longer string
 * (>= 10 characters — `MIN` valid form is `YYYY-MM-DD`); otherwise `$fallback`
 * (the deploy-commit date, for static pages) is tried on the same rule;
 * otherwise `null` — the caller omits `<lastmod>` entirely rather than print
 * an empty or fabricated element.
 *
 * @param string|null $dbDate   A `TIMESTAMP`/`DATETIME` column's string value
 *                              (session-timezone as returned — irrelevant at
 *                              date-only precision), or null/'' when absent.
 * @param string|null $fallback A secondary ISO 8601 date/time string to fall
 *                              back to (the deploy commit date), or null.
 * @return string|null 10-character `YYYY-MM-DD`, or null (omit `<lastmod>`).
 */
function sitemapLastmod(?string $dbDate, ?string $fallback = null): ?string
{
    $primary = $dbDate !== null ? trim($dbDate) : '';
    if ($primary !== '' && strlen($primary) >= 10) {
        return substr($primary, 0, 10);
    }
    $secondary = $fallback !== null ? trim($fallback) : '';
    if ($secondary !== '' && strlen($secondary) >= 10) {
        return substr($secondary, 0, 10);
    }
    return null; // genuinely unknown — the caller omits <lastmod>, never guesses
}
