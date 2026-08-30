<?php

declare(strict_types=1);

/**
 * iHymns — Per-channel search-engine visibility control (#2024/#2025)
 * ============================================================================
 *
 * ELI5
 * ----
 * iHymns runs as three separate copies of the same site — the real one
 * (`ihymns.app`), a beta preview (`beta.ihymns.app`), and a dev/testing copy
 * (`dev.ihymns.app`). Search engines like Google don't know that the preview
 * and dev copies are "not the real thing" — left alone, they will happily
 * list an in-progress song page from the dev site right next to the real
 * one. This file is the ONE place that answers "should THIS copy of the site
 * show up in search results?" — every other file that needs to know (the
 * sitemap, the new robots.txt, every public page) asks this file, so they
 * can never disagree with each other.
 *
 * DETAILED — HOW "OFF" WORKS (owner decision, #2024/#2025 plan §2.1)
 * ----------------------------------------------------------------------------
 * Switching a channel OFF is a "full search-engine hide", made of three
 * pieces that all point the same way:
 *   1. Every response on that channel carries `X-Robots-Tag: noindex` (and
 *      the SPA shell adds the matching `<meta name="robots" content=
 *      "noindex">`) — "you may read this page, but never list it".
 *   2. That channel's `/sitemap.xml` (and every child) answers a plain 404 —
 *      stop actively inviting search engines to a channel we've asked them
 *      not to list.
 *   3. That channel's `robots.txt` drops its `Sitemap:` line.
 *
 * On purpose, OFF does **not** add `Disallow: /` to robots.txt. That would
 * be a DIFFERENT, stronger instruction — "never even fetch this site" — and
 * it would defeat the noindex signal above: a search engine that is told not
 * to fetch a page can never see the noindex on it, so a URL it already knows
 * about (an old link, a stray backlink) can keep appearing in results as a
 * bare "known page" entry. Staying crawlable is what lets noindex actually
 * work. See `robots.txt.php` for where this is enforced.
 *
 * DEFAULT-WHEN-ABSENT (the locked owner decision — no admin action needed on
 * a fresh install, no database migration): production is listed, beta and
 * alpha (dev) are hidden. See SEARCH_VISIBILITY_DEFAULT_CSV below.
 *
 * WHY ONE STORED ROW, CSV-SHAPED (rule #22 — reuse an existing storage
 * convention rather than inventing a third one)
 * ----------------------------------------------------------------------------
 * This codebase already stores "which channels is X switched on for?" this
 * exact way, twice: `webhooks_enabled_channels` (includes/webhooks.php) and
 * `intappsapi_enabled_channels` (includes/intapps_client.php) — a
 * comma-separated subset of {alpha, beta, production} in one tblAppSettings
 * row, shared by all three docroots (they share one MySQL database), parsed
 * by the ONE shared fold `ihymns_parse_channels_csv()` (includes/
 * environment.php) and checked against `ihymns_environment()` — never the
 * request's Host header, which an attacker can forge; the channel is always
 * derived from the DOCROOT this code is actually running from.
 *
 * The ONE deliberate difference from the webhooks precedent: an admin
 * unticking every channel stores the literal string `'none'`, not an empty
 * string. `setAppSetting()`'s own house convention treats `''` as "unset",
 * so a stored `''` here would be one future "normalise empty to default"
 * cleanup away from silently turning "everything hidden" back into
 * "production visible" — the opposite of what the admin asked for. `'none'`
 * is not a real channel token, so the parser naturally yields an empty list,
 * and the stored value stays self-describing.
 *
 * NOT A SECRET — this setting is never registered in secretSettingKeys();
 * there is nothing here worth encrypting at rest.
 *
 * @see appWeb/public_html/includes/environment.php   ihymns_environment(), ihymns_parse_channels_csv()
 * @see appWeb/public_html/includes/maintenance.php    getAppSetting()/setAppSetting()
 * @see appWeb/public_html/includes/webhooks.php        the CSV-per-channel storage precedent (#1909)
 * @see appWeb/public_html/sitemap.xml.php              the sitemap 404 gate consumer
 * @see appWeb/public_html/robots.txt.php               the dynamic robots.txt consumer
 * @see appWeb/public_html/manage/configuration.php     the admin card + save handler
 * @link .claude/CLAUDE.md rule #35   "cross-file agreement needs a mechanism, not a comment"
 */

/* Direct access blocked, matching the house convention for an includes/*.php
   module (db_mysql.php, sitemap_helpers.php, config.php, …) — belt-and-
   braces on top of .htaccess's blanket `RewriteRule ^includes/ - [F,L]`.
   Under the PHP CLI test runner $_SERVER['SCRIPT_FILENAME'] is the TEST
   file, never this one, so requiring this file from a test never trips it. */
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'environment.php'; /* ihymns_environment(), ihymns_parse_channels_csv() */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'db_mysql.php';    /* getDbMysqli() — getAppSetting() needs it available */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'maintenance.php'; /* getAppSetting() — DB-safe, returns the default on ANY error */

/* =========================================================================
 * SETTINGS KEY — defined ONCE (rule #35) so configuration.php's read + save
 * handler, and every consumer of this file, never re-type the literal and
 * drift apart. The single-source-of-truth guard (tests/php/test-search-
 * visibility.php PASS 2) asserts the quoted string below appears in exactly
 * this one file.
 * ========================================================================= */
const SEARCH_VISIBILITY_SETTING_KEY = 'search_visibility_channels';

/** Default-when-absent CSV — the locked owner decision (#2024/#2025): a
 *  fresh install (no row stored yet) lists production and hides beta/alpha,
 *  with no admin action and no database migration required. Also the value
 *  `getAppSetting()` returns on any database error, so a DB outage degrades
 *  to this SAME safe default rather than an undefined state. */
const SEARCH_VISIBILITY_DEFAULT_CSV = 'production';

/**
 * PURE core: does the given CSV list this channel as visible? No DB, no
 * superglobals — safe to call directly from a CI truth table.
 *
 * ELI5: "is the word for this channel somewhere in this comma list?" —
 * the search-visibility equivalent of `_intappsChannelAllowedCore()` /
 * webhooks' inline channel check, minus the `'all'` shortcut those two
 * accept: there are only three fixed channels here, so spelling out
 * `production,beta,alpha` says "all" just fine, and a second accepted
 * grammar is one more thing that could quietly drift from the other two.
 *
 * @param string $csv     Raw setting value (comma/whitespace separated).
 * @param string $channel The channel to check — normally `ihymns_environment()`'s
 *                          return value ('alpha'|'beta'|'production').
 * @return bool True when $channel appears in the parsed $csv.
 */
function searchVisibilityAllows(string $csv, string $channel): bool
{
    return in_array(strtolower(trim($channel)), ihymns_parse_channels_csv($csv), true);
}

/**
 * ELI5: is THIS copy of the site (the one currently running this code)
 * allowed to show up in search engines right now?
 *
 * WHY MEMOIZED: mirrors `webhooksEnabled()` (includes/webhooks.php) — the
 * setting cannot change mid-request, so every one of the several call sites
 * a single request can reach (the maintenance-style bootstrap gate, the
 * noindex header emitter, the meta-tag flag) shares ONE database read.
 *
 * @return bool True when search engines may index this channel right now.
 */
function searchEngineVisibleHere(): bool
{
    static $visible = null;
    if ($visible !== null) {
        return $visible;
    }
    $csv = (string)(getAppSetting(SEARCH_VISIBILITY_SETTING_KEY, SEARCH_VISIBILITY_DEFAULT_CSV) ?? SEARCH_VISIBILITY_DEFAULT_CSV);
    return $visible = searchVisibilityAllows($csv, ihymns_environment());
}

/**
 * Emit `X-Robots-Tag: noindex` when — and only when — this channel is
 * currently hidden from search engines. A no-op (no header at all) on a
 * visible channel, and a no-op if headers were already sent (defensive; no
 * current call site can reach that state, but a header-emit helper that
 * could fatal on a late call would be a worse failure than silently skipping
 * it).
 *
 * ELI5: one line, dropped into any public endpoint, that quietly tells
 * search engines "don't list this" exactly when the admin has asked for
 * that — and does nothing at all otherwise.
 *
 * Plain `noindex`, not `noindex, nofollow` (owner decision, sub-decision b
 * of the plan): on a hidden channel every link on the page points at the
 * same hidden host anyway, so `nofollow` adds nothing structural.
 *
 * @return void
 */
function searchVisibilityEmitNoindexHeader(): void
{
    if (!searchEngineVisibleHere() && !headers_sent()) {
        header('X-Robots-Tag: noindex');
    }
}
