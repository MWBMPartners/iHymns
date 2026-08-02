<?php

declare(strict_types=1);

/**
 * iHymns — /manage/credit-people  (RENAMED → redirect)  (#1741 P2-B)
 *
 * The Credit People registry CRUD this page hosted (#545 and everything
 * built on top of it since) was renamed, wholesale, to **Musicians** as
 * part of the #1741 MusicBrainz-shaped catalogue rework — schema (#1746
 * P2-A), then this app-code half (P2-B). The live page is now
 * /manage/musicians. This stub preserves old bookmarks / deep links /
 * any query-string params (?id=… from the public musician page's Edit
 * button, ?open=… etc.) by redirecting; it is intentionally tiny and does
 * no auth or DB work of its own — /manage/musicians re-checks
 * manage_musicians itself.
 *
 * Query string is forwarded unchanged (rule #33 — a URL parameter
 * another page emits is a contract; the public musician page's Edit
 * button still links here as `/manage/credit-people?id=<id>` from any
 * not-yet-refreshed cached fragment, and external bookmarks may carry
 * any param the old page understood).
 *
 * 302 (not 301) so no sticky browser cache pins the redirect while the
 * rename is still settling in on alpha — mirrors the identical-shape
 * /manage/song-link-suggestions stub from #1215/#1220.
 */

$qs = $_SERVER['QUERY_STRING'] ?? '';
header('Location: /manage/musicians' . ($qs !== '' ? ('?' . $qs) : ''), true, 302);
exit;
