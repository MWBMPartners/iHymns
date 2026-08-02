<?php

declare(strict_types=1);

/**
 * iHymns — /manage/credit-people-bulk-promote  (RENAMED → redirect)  (#1741 P2-B)
 *
 * Companion bulk-promote surface (#846/#1503) for the Credit People
 * registry, renamed alongside its parent page to **Musicians** as part of
 * the #1741 MusicBrainz-shaped catalogue rework (schema #1746 P2-A, this
 * app-code half P2-B). The live page is now /manage/musicians-bulk-promote.
 * This stub preserves old bookmarks / deep links by redirecting; it is
 * intentionally tiny and does no auth or DB work of its own —
 * /manage/musicians-bulk-promote re-checks manage_musicians itself.
 *
 * Query string is forwarded unchanged (rule #33).
 *
 * 302 (not 301) so no sticky browser cache pins the redirect while the
 * rename is still settling in on alpha — mirrors the identical-shape
 * /manage/song-link-suggestions stub from #1215/#1220 and the sibling
 * /manage/credit-people stub above.
 */

$qs = $_SERVER['QUERY_STRING'] ?? '';
header('Location: /manage/musicians-bulk-promote' . ($qs !== '' ? ('?' . $qs) : ''), true, 302);
exit;
