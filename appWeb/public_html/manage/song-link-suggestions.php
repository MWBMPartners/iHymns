<?php

declare(strict_types=1);

/**
 * iHymns — /manage/song-link-suggestions  (RETIRED → redirect)  (#1215 / #1220)
 *
 * The cross-book similarity + Link/Dismiss workflow this page hosted (#807/#808)
 * was absorbed into the unified **Duplicate & Counterpart Review** surface at
 * /manage/duplicate-songs (scoring %, Link, Dismiss, Rebuild, plus the
 * same-songbook merge guard). This stub preserves old bookmarks / deep links by
 * redirecting; it is intentionally tiny and does no auth or DB work.
 *
 * The editor's inline "Suggested counterparts" panel is unaffected — it reads
 * the editor API (suggest_song_links), never this page.
 *
 * 302 (not 301) so no sticky browser cache pins the redirect while the unified
 * page is still iterating on alpha.
 */

header('Location: /manage/duplicate-songs', true, 302);
exit;
