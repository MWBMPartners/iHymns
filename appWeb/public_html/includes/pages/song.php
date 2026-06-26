<?php

/**
 * iHymns — Song Lyrics Page Template
 *
 * PURPOSE:
 * Displays the full lyrics and metadata for a single song.
 * Includes song title, songbook info, writers/composers, lyrics
 * formatted by component type (verse, chorus, etc.), and action
 * buttons for favouriting, sharing, and audio/sheet music.
 *
 * Loaded via AJAX: api.php?page=song&id=CP-0001
 *
 * Expects $songId to be set by api.php before inclusion.
 */

declare(strict_types=1);

/* #1328 — hide the songbook abbreviation badge when it just repeats the name. */
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'songbook_display.php';

/* Fetch the full song data */
$song = $songData->getSongById($songId);

/* Handle song not found. */
if ($song === null) {
    /* #1343 — a merged/deleted/renamed permalink should RESOLVE, not 404. Try the
       redirect layer before giving up. A server fragment can't usefully 301 (the
       Location would point at the /api?page=song fragment URL, not the SPA route),
       so on a live replacement we emit a [data-song-redirect] marker the SPA
       router reads in afterPageLoad('song') and navigates to (history-replaced). */
    require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'song_redirects.php';
    $rd = songRedirectResolve(getDbMysqli(), (string)$songId);
    if ($rd['redirected'] && $rd['target'] !== null && $songData->getSongById($rd['target']) !== null) {
        $rdTarget = '/song/' . rawurlencode((string)$rd['target']);
        echo '<div data-song-redirect="' . htmlspecialchars($rdTarget, ENT_QUOTES) . '" class="text-secondary small p-4 text-center">'
           . '<i class="fa-solid fa-arrow-right-arrow-left me-2" aria-hidden="true"></i>This song moved — taking you to its current page…</div>';
        return;
    }

    /* No live target — a tombstone (removed/merged with no replacement) reads as
       "removed" (410 Gone) rather than the generic "not found" (404). */
    $rdGone = (bool)$rd['redirected'];
    http_response_code($rdGone ? 410 : 404);
    if (function_exists('renderErrorFragment')) {
        echo renderErrorFragment(404, [
            'title'   => $rdGone ? 'Song removed' : 'Song not found',
            'message' => $rdGone
                ? 'This song has been removed — it may have been a duplicate that was merged, or withdrawn. Try a search for the title.'
                : 'We couldn\'t find a song with the ID "' . $songId . '". It may have been removed, or the link is out of date.',
            'fa'      => 'fa-music',
            'actions' => [
                ['label' => 'Browse Songbooks', 'href' => '/songbooks', 'navigate' => 'songbooks', 'primary' => true, 'fa' => 'fa-book-open'],
                ['label' => 'Search',           'href' => '/search',     'navigate' => 'search',    'fa' => 'fa-magnifying-glass'],
            ],
        ]);
    } else {
        echo '<div class="alert alert-warning" role="alert">'
           . ($rdGone ? 'Song removed: ' : 'Song not found: ') . '<strong>'
           . htmlspecialchars($songId) . '</strong></div>';
    }
    return;
}

/* #1343-B — the canonical permalink is the opaque PublicId (if backfilled). When
   the visitor arrived via a NON-canonical id (a legacy SongId, a padding alias, or
   a resolved redirect), emit a [data-song-canonical] marker so the SPA router
   history-REPLACES the URL to /song/<PublicId> (soft canonicalise, same vehicle as
   the #1343-A redirect marker). No marker when already canonical or un-backfilled. */
$songPublicId  = (string)($song['publicId'] ?? '');
$songCanonical = ($songPublicId !== '' && strtoupper((string)$songId) !== strtoupper($songPublicId))
    ? ('/song/' . rawurlencode($songPublicId))
    : '';

/* Extract metadata for convenience — Number is NULL for Misc songs and
   for any custom-songbook entry that wasn't given a position (#392, #797).
   Treat null, '', '0' and 0 as equivalent — the canonical "unnumbered"
   value is NULL, but legacy rows and JS payloads sometimes carry 0 or '0'
   and the rest of the page must treat them the same way. */
$rawSongNumber = $song['number'] ?? null;
$songNumber    = ($rawSongNumber === null || $rawSongNumber === '' || (int)$rawSongNumber <= 0)
    ? null
    : (int)$rawSongNumber;
$songTitle   = toTitleCase($song['title'] ?? 'Untitled');
$songbook    = $song['songbook'] ?? '';
$bookName    = $song['songbookName'] ?? '';
/* Songbook colour for the reading-progress bar (#109). Fetched here
   instead of leaving it to a CSS variable lookup so custom songbooks
   created via /manage/songbooks (whose abbreviation isn't in the
   hardcoded --songbook-{ABBR} CSS-var set) still get their assigned
   colour on the bar. Empty string means "let the bar fall back to
   the default accent". */
$songbookColour = '';
$songbookParent = null;   /* #782 phase D — nested array shape: id, abbreviation, name, relationship */
if ($songbook !== '') {
    $bookData = $songData->getSongbook($songbook);
    if (is_array($bookData)) {
        if (!empty($bookData['colour'])) {
            $songbookColour = trim((string)$bookData['colour']);
        }
        if (!empty($bookData['parent']) && is_array($bookData['parent'])) {
            $songbookParent = $bookData['parent'];
        }
    }
}

/* #782 phase D — if the songbook has a parent (translation / edition /
   abridgement of a canonical source), try to deep-link to the parent's
   same-numbered song. Falls back to the parent songbook's index when
   the parent doesn't carry that number. Skipped silently on unnumbered
   songs (the parent-link only makes sense at hymn-number granularity). */
$parentSongLinkUrl  = '';
$parentSongLinkType = ''; /* 'song' | 'songbook' | '' */
if ($songbookParent !== null
    && $songNumber !== null
    && (string)($songbookParent['abbreviation'] ?? '') !== ''
) {
    $parentAbbr = (string)$songbookParent['abbreviation'];
    $parentSid  = $songData->findSongIdByNumber($parentAbbr, $songNumber);
    if ($parentSid !== null) {
        $parentSongLinkUrl  = '/song/' . $parentSid;
        $parentSongLinkType = 'song';
    } else {
        $parentSongLinkUrl  = '/songbook/' . $parentAbbr;
        $parentSongLinkType = 'songbook';
    }
}
$writers     = $song['writers']     ?? [];
$composers   = $song['composers']   ?? [];
$arrangers   = $song['arrangers']   ?? [];   /* #497 */
$adaptors    = $song['adaptors']    ?? [];   /* #497 */
$translators = $song['translators'] ?? [];   /* #497 */
$artists     = $song['artists']     ?? [];   /* #587 — recording / release artist */
$tuneName    = $song['tuneName']    ?? '';   /* #497 */
$iswc        = $song['iswc']        ?? '';   /* #497 */
$copyright   = $song['copyright']   ?? '';
$ccli        = $song['ccli']        ?? '';
$hasAudio    = !empty($song['hasAudio']);
$hasSheet    = !empty($song['hasSheetMusic']);
$components  = $song['components'] ?? [];

/* #1200 — song-language tagging. The page <html lang> stays the UI language;
   song-language content (the TITLE here, and each lyric component below) carries
   its OWN BCP 47 tag + dir for RTL scripts, so screen readers, browser
   hyphenation, translators and search engines treat it correctly even when the
   song's language differs from the UI. resolveLanguageMeta() resolves the
   direction from tblLanguages (schema-probed + cached). Script subtags
   (zh-Hans, sr-Cyrl, ja-Latn, …) pass straight through from the stored tag. */
require_once __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'language_names.php';
$songPrimaryLang = trim((string)($song['language'] ?? ''));
$songLangDir     = $songPrimaryLang !== '' ? (resolveLanguageMeta($songPrimaryLang)['dir'] ?? 'ltr') : 'ltr';
$lyricsPublicDomain = !empty($song['lyricsPublicDomain']);
$musicPublicDomain  = !empty($song['musicPublicDomain']);
$fullyPublicDomain  = $lyricsPublicDomain && $musicPublicDomain;

/* Content gating for lyrics (forward-looking — e.g. gating copyrighted lyrics).
   Does NOTHING unless the content_gating_enabled flag is ON, so there is zero
   cost (no tblContentRestrictions query) on the hot song-page path by default.
   When a restriction matches the viewer, the lyrics are replaced with the
   themed "Lyrics protected" card (renderContentGatedFragment).
   NOTE: ?page= renders are currently anonymous (router.loadPage doesn't send
   the bearer token), so a signed-in ENTITLED user is treated as anonymous
   until (a) loadPage forwards auth and (b) the song page is excluded from the
   shared ETag cache when gated — both small follow-ups for when gating is
   actually switched on. */
$lyricsGated = false;
$gateReason  = '';
$serviceCcliNumber = null;   /* #1335 — set when a present congregant rides the org's CCLI licence; drives the per-song CCL notice. */
if (function_exists('getAppSetting') && getAppSetting('content_gating_enabled', '0') === '1'
    && function_exists('checkContentAccess')) {
    try {
        $gateViewer = function_exists('getAuthenticatedUser') ? getAuthenticatedUser() : null;
        /* #1335 — a congregant following a live service carries an opaque presence
           token (set as a same-origin cookie by service-follow.js on join). It
           lets them ride the org's live CCLI licence for gated lyrics while
           present, and is revoked the moment they leave / it expires. */
        $presenceTok = '';
        if (isset($_COOKIE['ihymns_sf_presence_token'])
            && preg_match('/^[A-Za-z0-9_\-]{43}$/', (string)$_COOKIE['ihymns_sf_presence_token'])) {
            $presenceTok = (string)$_COOKIE['ihymns_sf_presence_token'];
        }
        $gateAccess = checkContentAccess(
            'song', (string)$songId,
            isset($gateViewer['Id']) ? (int)$gateViewer['Id'] : null,
            'PWA',
            $presenceTok !== '' ? $presenceTok : null
        );
        if (empty($gateAccess['allowed'])) {
            $lyricsGated = true;
            $gateReason  = (string)($gateAccess['reason'] ?? '');
        } elseif ($presenceTok !== '' && !$fullyPublicDomain && function_exists('serviceMode_presenceCcliNumber')) {
            /* Allowed + a present congregant viewing a copyrighted song → the CCL
               requires the licence-holder's copyright notice on each device. */
            $serviceCcliNumber = serviceMode_presenceCcliNumber(
                getDbMysqli(), $presenceTok,
                function_exists('serviceMode_channel') ? serviceMode_channel() : 'production'
            );
        }

        /* #1357 — TIER gate, composed with the entity model above so the web page
           (and the offline bundle, which renders THROUGH this file) gate consistently
           with the song_detail API (#1353). The two axes are independent:
             • ENTITY (require_licence rows) — the per-song legal restriction, handled
               above ($lyricsGated from checkContentAccess); a denial here is authoritative.
             • TIER — the viewer's PLAN axis, which always governs COPYRIGHTED lyrics.
           A valid Service-Mode presence unlock ($serviceCcliNumber) overrides the tier
           (the rule #26 in-service exception). So a copyrighted song is additionally
           gated when the viewer's tier can't view copyrighted AND there is no presence
           unlock. Still entirely dormant behind content_gating_enabled; fail-open via the
           catch below (a thrown tier lookup leaves the entity verdict untouched). */
        if (!$lyricsGated && !$lyricsPublicDomain && $serviceCcliNumber === null) {
            require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'content_gating.php';
            if (function_exists('resolveEffectiveTier') && function_exists('checkTierAccess')) {
                $tierViewerId  = isset($gateViewer['Id']) ? (int)$gateViewer['Id'] : null;
                $viewerTier    = ($tierViewerId === null) ? 'public' : (resolveEffectiveTier($tierViewerId) ?: 'public');
                $viewerHasCcli = function_exists('contentGating_userHasCcli')
                    ? contentGating_userHasCcli($tierViewerId)
                    : false;
                $tierVerdict   = checkTierAccess($viewerTier ?: 'public', 'view_copyrighted', $viewerHasCcli);
                if (empty($tierVerdict['allowed'])) {
                    $lyricsGated = true;
                    $gateReason  = 'A higher access tier is required to view these lyrics.';
                }
            }
        }
    } catch (\Throwable $_e) {
        /* Gating must never break a song render — fail open (show lyrics). */
        error_log('[song.php] content-gate check failed: ' . $_e->getMessage());
    }
}

/* ===================================================================
 * Translations (#281) — list of other-language versions of this song
 *
 * Looks both "outward" (translations OF this song) and "inward"
 * (this song IS a translation of another), then unions them so the
 * picker shows every related language version regardless of which
 * side of the relationship the current page is on.
 *
 * Result shape: [{ song_id, target_language, language_name,
 *                   native_name, text_direction, translator, verified }]
 *
 * Wrapped in try/catch so a missing table during early setup or a
 * DB hiccup simply hides the picker rather than blanking the page.
 * =================================================================== */
/* #1206 — the cluster query now lives in SongData::getSongTranslations() so the
   song page picker AND the hreflang alternates (emitted in index.php's <head>)
   share ONE definition (modularity rule). Still wrapped so a hiccup hides the
   picker rather than blanking the page. */
$translations = [];
try {
    require_once __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'SongData.php';
    $translations = (new SongData())->getSongTranslations((string)($song['id'] ?? ''));
} catch (\Throwable $_e) {
    $translations = [];
}

/* Title each translation by its native name if present, falling back
   to the English name — lets a Spanish reader see "Español" rather
   than "Spanish". */
foreach ($translations as &$_t) {
    $_t['display_label'] = ($_t['native_name'] !== '' && $_t['native_name'] !== null)
        ? (string)$_t['native_name']
        : (string)$_t['language_name'];
}
unset($_t);

/* ===================================================================
 * Cross-book counterparts (#807) — same hymn appearing in different
 * songbooks at unrelated numbers.
 *
 * Distinct from the translations list above (different language)
 * and from the songbook-level parent link (#782 phase D, which only
 * fires when the parent songbook carries the same hymn number).
 *
 * Probes for tblSongLinks first so deployments that haven't run the
 * migration silently skip the panel rather than 500ing.
 * =================================================================== */
$songLinks = [];
try {
    if (!isset($translationsDb)) {
        require_once __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'db_mysql.php';
        $translationsDb = getDbMysqli();
    }
    $probe = $translationsDb->query(
        "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME   = 'tblSongLinks' LIMIT 1"
    );
    $hasLinksTable = $probe && $probe->fetch_row() !== null;
    if ($probe) $probe->close();

    if ($hasLinksTable) {
        $sid = (string)($song['id'] ?? '');
        $stmt = $translationsDb->prepare(
            'SELECT s.SongId       AS song_id,
                    s.Title        AS title,
                    s.Number       AS number,
                    s.SongbookAbbr AS songbook,
                    sb.Name        AS songbook_name,
                    s.Language     AS language
               FROM tblSongLinks self
               JOIN tblSongLinks other ON other.GroupId = self.GroupId
                                     AND other.SongId <> self.SongId
               JOIN tblSongs s         ON s.SongId = other.SongId
               JOIN tblSongbooks sb    ON sb.Abbreviation = s.SongbookAbbr
              WHERE self.SongId = ?
              ORDER BY s.SongbookAbbr ASC, s.Number ASC'
        );
        if ($stmt !== false) {
            $stmt->bind_param('s', $sid);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $row['number'] = ($row['number'] === null || $row['number'] === '' || (int)$row['number'] <= 0)
                    ? null
                    : (int)$row['number'];
                $songLinks[] = $row;
            }
            $stmt->close();
        }
    }
} catch (\Throwable $_e) {
    /* Table missing or DB hiccup — hide the panel rather than block
       the page render. The panel is decorative; the song still loads. */
    $songLinks = [];
}

?>

<!-- ================================================================
     SONG PAGE — Full lyrics and metadata
     ================================================================ -->
<article class="page-song" aria-label="<?= htmlspecialchars($songTitle) ?>" data-song-id="<?= htmlspecialchars($song['id']) ?>"<?php if ($songPublicId !== ''): ?> data-song-public-id="<?= htmlspecialchars($songPublicId) ?>"<?php endif; ?><?php if ($songCanonical !== ''): ?> data-song-canonical="<?= htmlspecialchars($songCanonical, ENT_QUOTES) ?>"<?php endif; ?> data-songbook="<?= htmlspecialchars($songbook) ?>"<?php if ($songbookColour !== ''): ?> data-songbook-color="<?= htmlspecialchars($songbookColour) ?>"<?php endif; ?><?php if ($songNumber !== null): ?> data-song-number="<?= (int)$songNumber ?>"<?php endif; ?><?php if (!empty($song['capo'])): ?> data-capo="<?= (int)$song['capo'] ?>"<?php endif; ?><?php if (!empty($song['key'])): ?> data-key="<?= htmlspecialchars($song['key']) ?>"<?php endif; ?>>

    <!-- Breadcrumb navigation with schema.org markup (#151) -->
    <nav aria-label="Breadcrumb" class="mb-3">
        <ol class="breadcrumb" itemscope itemtype="https://schema.org/BreadcrumbList">
            <li class="breadcrumb-item" itemprop="itemListElement" itemscope itemtype="https://schema.org/ListItem">
                <a href="/songbooks" data-navigate="songbooks" itemprop="item">
                    <span itemprop="name">Songbooks</span>
                </a>
                <meta itemprop="position" content="1">
            </li>
            <li class="breadcrumb-item" itemprop="itemListElement" itemscope itemtype="https://schema.org/ListItem">
                <a href="/songbook/<?= htmlspecialchars($songbook) ?>"
                   data-navigate="songbook"
                   data-songbook-id="<?= htmlspecialchars($songbook) ?>"
                   itemprop="item">
                    <span itemprop="name"><?= htmlspecialchars($bookName) ?></span>
                </a>
                <meta itemprop="position" content="2">
            </li>
            <li class="breadcrumb-item active" aria-current="page" itemprop="itemListElement" itemscope itemtype="https://schema.org/ListItem">
                <?php /* Unnumbered songs (Misc, custom collections without a
                         position) fall back to the song title so the final
                         crumb is meaningful (#797) — "#0" was the bug we
                         saw in alpha. */ ?>
                <span itemprop="name"><?= $songNumber !== null ? '#' . (int)$songNumber : htmlspecialchars($songTitle) ?></span>
                <meta itemprop="position" content="3">
            </li>
        </ol>
    </nav>

    <!-- Song header card -->
    <div class="card card-song-header mb-4">
        <div class="card-body">
            <!-- Song number and title — the coloured badge is rendered only
                 for songs that actually have a songbook position. Unnumbered
                 songs (Misc, custom collections without a position) drop the
                 badge entirely so the title sits flush left (#797). -->
            <div class="d-flex align-items-start gap-3 mb-3">
                <?php if ($songNumber !== null): ?>
                <span class="song-number-badge-lg" data-songbook="<?= htmlspecialchars($songbook) ?>"
                      aria-label="Song number <?= (int)$songNumber ?>">
                    <?= (int)$songNumber ?>
                </span>
                <?php endif; ?>
                <div class="flex-grow-1">
                    <h1 class="h4 mb-1"<?php if ($songPrimaryLang !== ''): ?> lang="<?= htmlspecialchars($songPrimaryLang) ?>"<?php if ($songLangDir === 'rtl'): ?> dir="rtl"<?php endif; ?><?php endif; ?>><?= htmlspecialchars($songTitle) ?><?php if (!empty($song['verified'])): ?><span class="verified-badge" role="img" title="Verified lyrics" aria-label="Verified lyrics"><svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="10" fill="currentColor" opacity="0.15"/><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="1.5" fill="none"/><path d="M7.5 12.5L10.5 15.5L16.5 9" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></span><?php endif; ?></h1>
                    <?php
                        /* #832 — "Also known as …" line. Hidden when this
                           song has no alt titles (or pre-migration). Per-row
                           Note appears in muted parentheses; per-row Language
                           tag appears as a small badge (e.g. "es") so a user
                           can see which alt belongs to which language variant. */
                        $altTitles = $song['alternativeTitles'] ?? [];
                        if (!empty($altTitles)):
                    ?>
                        <p class="text-muted small mb-1">
                            <span class="me-1">Also known as:</span>
                            <?php foreach ($altTitles as $i => $a): ?>
                                <?php if ($i > 0): ?>, <?php endif; ?>
                                <em><?= htmlspecialchars($a['title']) ?></em>
                                <?php if (!empty($a['language'])): ?>
                                    <?php
                                        /* #856 — tooltip resolves the IETF tag to
                                           the full language name. Lazy-require here
                                           because most songs have no alt titles and
                                           we don't want a SELECT for every page. */
                                        require_once __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'language_names.php';
                                    ?>
                                    <span class="badge bg-secondary text-light small"
                                          title="<?= htmlspecialchars(resolveLanguageName($a['language'])) ?>"><?= htmlspecialchars($a['language']) ?></span>
                                <?php endif; ?>
                                <?php if (!empty($a['note'])): ?>
                                    <span class="text-muted">(<?= htmlspecialchars($a['note']) ?>)</span>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </p>
                    <?php endif; ?>
                    <p class="text-muted mb-0">
                        <?php $sbAbbr = ihymns_songbook_abbr_label($songbook, (is_array($bookData ?? null) ? ($bookData['displayAbbr'] ?? null) : null)); ?>
                        <?php if (ihymns_songbook_show_abbr($bookName, $sbAbbr)): ?>
                        <span class="badge bg-body-secondary"><?= htmlspecialchars($sbAbbr) ?></span>
                        <?php endif; ?>
                        <?= htmlspecialchars($bookName) ?>
                    </p>
                    <?php if ($serviceCcliNumber !== null): /* #1335 — CCL copyright notice for a present congregant riding the org licence. */ ?>
                        <p class="small text-muted fst-italic mb-0 mt-1">
                            <i class="bi bi-shield-check me-1" aria-hidden="true"></i>
                            Shown under your church’s CCLI Copyright Licence<?= $serviceCcliNumber !== '' ? ' #' . htmlspecialchars($serviceCcliNumber) : '' ?> while you follow the service.
                        </p>
                    <?php endif; ?>
                    <?php if ($songbookParent !== null && $parentSongLinkUrl !== ''):
                        /* #782 phase D — canonical-source link. Renders only
                           when the current songbook declares a parent AND we
                           have an absolute number to deep-link with. The icon
                           varies by ParentRelationship — translate / bookmark
                           (edition) / scissors (abridgement) — matching the
                           admin-side Parent column in /manage/songbooks. The
                           prose stays neutral ("Original …") so the badge
                           reads naturally regardless of relationship type. */
                        $rel    = (string)($songbookParent['relationship'] ?? '');
                        $relLbl = match ($rel) {
                            'translation' => 'Translation of',
                            'edition'     => 'Edition of',
                            'abridgement' => 'Abridgement of',
                            default       => 'Original',
                        };
                        $relIcn = match ($rel) {
                            'translation' => 'fa-language',
                            'edition'     => 'fa-bookmark',
                            'abridgement' => 'fa-scissors',
                            default       => 'fa-link',
                        };
                        $parentName = (string)($songbookParent['name']         ?? '');
                        $parentAbbr = (string)($songbookParent['abbreviation'] ?? '');
                    ?>
                    <p class="small mt-2 mb-0">
                        <a href="<?= htmlspecialchars($parentSongLinkUrl) ?>"
                           class="text-decoration-none"
                           data-navigate="<?= htmlspecialchars($parentSongLinkType) ?>"
                           <?php if ($parentSongLinkType === 'song'): ?>data-song-id="<?= htmlspecialchars(basename($parentSongLinkUrl)) ?>"<?php endif; ?>
                           <?php if ($parentSongLinkType === 'songbook'): ?>data-songbook-id="<?= htmlspecialchars($parentAbbr) ?>"<?php endif; ?>
                           title="View this hymn in its canonical source">
                            <i class="fa-solid <?= htmlspecialchars($relIcn) ?> me-1" aria-hidden="true"></i>
                            <?= htmlspecialchars($relLbl) ?>
                            <span class="badge bg-body-secondary ms-1"><?= htmlspecialchars($parentAbbr) ?></span>
                            <?= htmlspecialchars($parentName) ?>
                            <?php if ($parentSongLinkType === 'song'): ?>
                                <span class="text-muted">— hymn #<?= (int)$songNumber ?></span>
                            <?php endif; ?>
                        </a>
                    </p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Credits block (#497).
                 Five collections now: Writers · Composers · Arrangers ·
                 Adaptors · Translators. Each is a many-to-one list from
                 its own MySQL table; we render a row per non-empty
                 collection and join names with "; " (per #495) since
                 surname-first hymnal citations can legitimately contain
                 commas inside a single name. -->
            <?php
            /* Combine Words + Music into a single "Words & Music:" row
               when the two sets are identical (#603) — common for
               contemporary songs where the songwriter is also the
               composer (e.g. Graham Kendrick, Stuart Townend). Set
               equality, not list equality, so order-of-names doesn't
               break the combine. */
            $_writersNorm = array_values(array_unique(array_filter(
                array_map(static fn($n) => trim((string)$n), $writers ?: [])
            )));
            $_composersNorm = array_values(array_unique(array_filter(
                array_map(static fn($n) => trim((string)$n), $composers ?: [])
            )));
            sort($_writersNorm);
            sort($_composersNorm);
            $_combineWordsMusic = !empty($_writersNorm)
                && $_writersNorm === $_composersNorm;

            if ($_combineWordsMusic) {
                $_creditRows = [
                    ['words-music', 'Words & Music', 'fa-solid fa-pen-fancy', $writers],
                    ['arranged',    'Arranged by',   'fa-solid fa-sliders',     $arrangers],
                    ['adapted',     'Adapted by',    'fa-solid fa-compact-disc',$adaptors],
                    ['translated',  'Translated by', 'fa-solid fa-language',    $translators],
                    ['artist',      'Artist',        'fa-solid fa-microphone',  $artists],         /* #587 */
                ];
            } else {
                $_creditRows = [
                    ['words',       'Words',       'fa-solid fa-pen-fancy',   $writers],
                    ['music',       'Music',       'fa-solid fa-music',       $composers],
                    ['arranged',    'Arranged by', 'fa-solid fa-sliders',     $arrangers],
                    ['adapted',     'Adapted by',  'fa-solid fa-compact-disc',$adaptors],
                    ['translated',  'Translated by','fa-solid fa-language',   $translators],
                    ['artist',      'Artist',      'fa-solid fa-microphone',  $artists],          /* #587 */
                ];
            }
            $_hasAnyCredit = false;
            foreach ($_creditRows as $row) { if (!empty($row[3])) { $_hasAnyCredit = true; break; } }
            ?>
            <?php if ($_hasAnyCredit): ?>
                <div class="song-meta mb-3">
                    <?php foreach ($_creditRows as $rowIdx => $row): ?>
                        <?php [$rowId, $rowLabel, $rowIcon, $rowNames] = $row; ?>
                        <?php if (empty($rowNames)) continue; ?>
                        <p class="mb-<?= $rowIdx === count($_creditRows) - 1 || empty(array_slice($_creditRows, $rowIdx + 1, null, true)) ? '0' : '1' ?> song-credit-row" data-credit-kind="<?= htmlspecialchars($rowId) ?>">
                            <i class="<?= htmlspecialchars($rowIcon) ?> me-2 text-muted" aria-hidden="true"></i>
                            <strong><?= htmlspecialchars($rowLabel) ?>:</strong>
                            <?php foreach ($rowNames as $i => $name): ?><a href="/people/<?= htmlspecialchars(urlencode(strtolower(str_replace(' ', '-', $name)))) ?>"
                                   class="song-meta-link"
                                   data-navigate="person"><?= htmlspecialchars($name) ?></a><?php if ($i < count($rowNames) - 1): ?>;&nbsp;<?php endif; ?><?php endforeach; ?>
                        </p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- Tune name (#497). Rendered when set so the viewer sees
                 "Tune: HYFRYDOL" — a meaningful pointer for hymnbook
                 users. Layout matches the people-credit rows above
                 (#599) — same wrapper class, same icon styling, same
                 bold-label-then-value pattern — so the song header
                 reads as a single consistent credit block instead of
                 the credits + a smaller dimmer Tune line.
                 #940 — tune is now a link to `/tune/<slug>` listing
                 every song that uses that tune; lets a worship leader
                 mix-n-match lyrics across hymns with the same melody. -->
            <?php if ($tuneName !== ''):
                $_tuneSlug = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $tuneName), '-'));
                ?>
                <div class="song-meta mb-3">
                    <p class="mb-0 song-credit-row" data-credit-kind="tune">
                        <i class="fa-solid fa-music me-2 text-muted" aria-hidden="true"></i>
                        <strong>Tune:</strong>
                        <?php if ($_tuneSlug !== ''): ?>
                            <a href="/tune/<?= htmlspecialchars($_tuneSlug) ?>"
                               class="song-meta-link"
                               data-navigate="tune"
                               title="See all songs that use this tune"><?= htmlspecialchars($tuneName) ?></a>
                        <?php else: ?>
                            <?= htmlspecialchars($tuneName) ?>
                        <?php endif; ?>
                    </p>
                </div>
            <?php endif; ?>

            <!-- ============================================================
                 Translations picker (#281) — only rendered when at least
                 one related-language version exists. A Bootstrap dropdown
                 keyed on the song-id so the SPA router can navigate
                 without a full page reload.
                 ============================================================ -->
            <?php if (!empty($translations)): ?>
                <div class="song-translations mb-3">
                    <div class="dropdown">
                        <button type="button"
                                class="btn btn-sm btn-outline-secondary dropdown-toggle"
                                data-bs-toggle="dropdown"
                                aria-expanded="false"
                                aria-label="Available translations">
                            <i class="fa-solid fa-language me-1" aria-hidden="true"></i>
                            Also in
                            <?php if (count($translations) === 1): ?>
                                <?= htmlspecialchars($translations[0]['display_label']) ?>
                            <?php else: ?>
                                <?= count($translations) ?> languages
                            <?php endif; ?>
                        </button>
                        <ul class="dropdown-menu">
                            <?php foreach ($translations as $t): ?>
                                <li>
                                    <a class="dropdown-item"
                                       href="/song/<?= htmlspecialchars($t['song_id']) ?>"
                                       data-navigate="song"
                                       hreflang="<?= htmlspecialchars($t['target_language']) ?>"
                                       lang="<?= htmlspecialchars($t['target_language']) ?>"
                                       dir="<?= htmlspecialchars($t['text_direction'] ?: 'ltr') ?>">
                                        <span class="fw-semibold"><?= htmlspecialchars($t['display_label']) ?></span>
                                        <?php if (!empty($t['translator'])): ?>
                                            <small class="text-muted ms-1">— tr. <?= htmlspecialchars($t['translator']) ?></small>
                                        <?php endif; ?>
                                        <?php if (!empty($t['verified'])): ?>
                                            <i class="fa-solid fa-circle-check text-success ms-1 small"
                                               title="Verified translation" aria-hidden="true"></i>
                                        <?php endif; ?>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            <?php endif; ?>

            <!-- ============================================================
                 Cross-book counterparts (#807) — "Also appears in" dropdown.
                 Shown when this song shares a tblSongLinks.GroupId with one
                 or more other songs (same hymn, different songbook, often
                 same language). Sits beside the translations dropdown so
                 the two are visually parallel but semantically distinct.
                 ============================================================ -->
            <?php if (!empty($songLinks)): ?>
                <div class="song-cross-book-links mb-3">
                    <div class="dropdown">
                        <button type="button"
                                class="btn btn-sm btn-outline-secondary dropdown-toggle"
                                data-bs-toggle="dropdown"
                                aria-expanded="false"
                                aria-label="Also appears in other songbooks">
                            <i class="fa-solid fa-link me-1" aria-hidden="true"></i>
                            Also appears in
                            <?php if (count($songLinks) === 1): ?>
                                <?= htmlspecialchars($songLinks[0]['songbook']) ?>
                            <?php else: ?>
                                <?= count($songLinks) ?> songbooks
                            <?php endif; ?>
                        </button>
                        <ul class="dropdown-menu">
                            <?php foreach ($songLinks as $sl): ?>
                                <li>
                                    <a class="dropdown-item"
                                       href="/song/<?= htmlspecialchars($sl['song_id']) ?>"
                                       data-navigate="song"
                                       data-song-id="<?= htmlspecialchars($sl['song_id']) ?>">
                                        <span class="badge bg-body-secondary me-2"><?= htmlspecialchars($sl['songbook']) ?></span>
                                        <span class="fw-semibold"><?= htmlspecialchars($sl['songbook_name']) ?></span>
                                        <?php if ($sl['number'] !== null): ?>
                                            <small class="text-muted ms-1">— hymn #<?= (int)$sl['number'] ?></small>
                                        <?php endif; ?>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Copyright, CCLI Song Number, ISWC in song header (#497).
                 ID labels (CCLI / ISWC) are bold with a definite gap
                 between label and value (#600). The two ID rows sit
                 in a flex row that wraps: side-by-side at wide widths,
                 stacked at narrow. Copyright stays on its own line
                 above — it's prose, not a labelled field. -->
            <?php if (!empty($copyright) || !empty($ccli) || $iswc !== ''): ?>
                <div class="song-meta-copyright mb-3">
                    <?php if (!empty($copyright)): ?>
                        <p class="mb-1 small text-muted">
                            <i class="fa-regular fa-copyright me-2" aria-hidden="true"></i>
                            <?= htmlspecialchars($copyright) ?>
                        </p>
                    <?php endif; ?>
                    <?php if (!empty($ccli) || $iswc !== ''): ?>
                        <div class="song-id-row d-flex flex-wrap column-gap-4 row-gap-1">
                            <?php if (!empty($ccli)):
                                /* #940 — link the CCLI number itself to SongSelect.
                                   Per the spec the link opens in a new tab. */
                                $_ccliEnc = rawurlencode((string)$ccli);
                                ?>
                                <span class="small text-muted">
                                    <i class="fa-solid fa-hashtag me-2" aria-hidden="true"></i>
                                    <strong>CCLI Song #</strong>&nbsp;<a
                                        href="https://songselect.ccli.com/songs/<?= htmlspecialchars($_ccliEnc) ?>"
                                        class="song-meta-link"
                                        target="_blank"
                                        rel="noopener noreferrer external"
                                        title="View on CCLI SongSelect (opens in new tab)"><?= htmlspecialchars($ccli) ?></a>
                                </span>
                            <?php endif; ?>
                            <?php if ($iswc !== ''):
                                /* #940 — link the ISWC to an internal page listing
                                   every song that shares this code. Internal rather
                                   than external to ISWCnet because catalogue
                                   navigation is more useful than a search-result
                                   redirect. The route is /iswc/<encoded-iswc>. */
                                $_iswcEnc = rawurlencode((string)$iswc);
                                ?>
                                <span class="small text-muted" title="International Standard Musical Work Code">
                                    <i class="fa-solid fa-barcode me-2" aria-hidden="true"></i>
                                    <strong>ISWC:</strong>&nbsp;<a
                                        href="/iswc/<?= htmlspecialchars($_iswcEnc) ?>"
                                        class="song-meta-link"
                                        data-navigate="iswc"
                                        title="See all songs sharing this ISWC"><?= htmlspecialchars($iswc) ?></a>
                                </span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- Song key display and transpose buttons (#298) -->
            <div id="song-key-container" class="d-inline-flex align-items-center gap-2 mb-2" style="display:none !important">
                <span class="badge bg-secondary" id="song-key-badge" title="Song key"></span>
                <div class="btn-group btn-group-sm">
                    <button class="btn btn-outline-secondary" id="btn-transpose-down" title="Transpose down">
                        <i class="fa-solid fa-minus"></i>
                    </button>
                    <button class="btn btn-outline-secondary" id="btn-transpose-up" title="Transpose up">
                        <i class="fa-solid fa-plus"></i>
                    </button>
                </div>
                <small class="text-muted" id="song-key-info"></small>
            </div>

            <!-- Action buttons row -->
            <div class="d-flex flex-wrap gap-2">
                <!-- Favourite toggle -->
                <button type="button"
                        class="btn btn-outline-secondary btn-sm song-toolbar-btn btn-favourite"
                        data-song-id="<?= htmlspecialchars($song['id']) ?>"
                        data-song-title="<?= htmlspecialchars($songTitle) ?>"
                        aria-label="Add to favourites"
                        aria-pressed="false">
                    <i class="fa-regular fa-heart me-1" aria-hidden="true"></i>
                    <span>Favourite</span>
                </button>

                <!-- Share button -->
                <button type="button"
                        class="btn btn-outline-secondary btn-sm song-toolbar-btn btn-share"
                        data-song-id="<?= htmlspecialchars($song['id']) ?>"<?php if ($songPublicId !== ''): ?>
                        data-song-public-id="<?= htmlspecialchars($songPublicId) ?>"<?php endif; ?>
                        data-song-title="<?= htmlspecialchars($songTitle) ?>"
                        aria-label="Share this song">
                    <i class="fa-solid fa-share-nodes me-1" aria-hidden="true"></i>
                    Share
                </button>

                <!-- Audio button (if available) -->
                <?php if ($hasAudio): ?>
                    <button type="button"
                            class="btn btn-outline-secondary btn-sm song-toolbar-btn btn-audio"
                            data-song-id="<?= htmlspecialchars($song['id']) ?>"
                            aria-label="Play audio">
                        <i class="fa-solid fa-headphones me-1" aria-hidden="true"></i>
                        Audio
                    </button>
                <?php endif; ?>

                <!-- Sheet music button (if available) -->
                <?php if ($hasSheet): ?>
                    <button type="button"
                            class="btn btn-outline-secondary btn-sm song-toolbar-btn btn-sheet-music"
                            data-song-id="<?= htmlspecialchars($song['id']) ?>"
                            aria-label="View sheet music">
                        <i class="fa-solid fa-file-pdf me-1" aria-hidden="true"></i>
                        Sheet Music
                    </button>
                <?php endif; ?>

                <!-- Add to set list (#94) -->
                <button type="button"
                        class="btn btn-outline-secondary btn-sm song-toolbar-btn btn-add-to-setlist"
                        aria-label="Add to set list">
                    <i class="fa-solid fa-list-ol me-1" aria-hidden="true"></i>
                    Set List
                </button>

                <!-- Compare with another song (#102) -->
                <button type="button"
                        class="btn btn-outline-secondary btn-sm song-toolbar-btn btn-compare"
                        aria-label="Compare with another song">
                    <i class="fa-solid fa-columns me-1" aria-hidden="true"></i>
                    Compare
                </button>

                <!-- Save offline — consolidated into the harmonised cloud
                     button (#453, #454, #456). The offline-ui module
                     handles feature detection, cached-state, disabled
                     tooltip, and click; the legacy .btn-save-offline
                     handler still runs too, so either wire path works. -->
                <button type="button"
                        class="btn btn-outline-secondary btn-sm song-toolbar-btn btn-save-offline"
                        data-song-id="<?= htmlspecialchars($song['id']) ?>"
                        data-song-download="<?= htmlspecialchars($song['id']) ?>"
                        aria-label="Save this song for offline use"
                        title="Save this song for offline use">
                    <i class="fa-solid fa-cloud-arrow-down me-1" aria-hidden="true"></i>
                    <span>Save Offline</span>
                </button>

                <!-- Presentation mode (#297) -->
                <button type="button"
                        class="btn btn-outline-secondary btn-sm song-toolbar-btn"
                        id="btn-present"
                        title="Presentation mode"
                        aria-label="Enter presentation mode">
                    <i class="fa-solid fa-display me-1" aria-hidden="true"></i>
                    Present
                </button>

                <!-- Print button -->
                <button type="button"
                        class="btn btn-outline-secondary btn-sm song-toolbar-btn btn-print"
                        aria-label="Print this song"
                        data-action="print">
                    <i class="fa-solid fa-print me-1" aria-hidden="true"></i>
                    Print
                </button>

                <!-- Export to a worship-presentation format (#1166). The dropdown
                     items are wired by export-ui.js (initSongExport), which
                     lazy-loads the export libs (format-export.js) on first use. -->
                <div class="btn-group song-toolbar-btn">
                    <button type="button"
                            class="btn btn-sm btn-outline-secondary dropdown-toggle btn-export-song"
                            data-bs-toggle="dropdown" aria-expanded="false"
                            aria-label="Export this song to a worship-presentation format">
                        <i class="fa-solid fa-file-export me-1" aria-hidden="true"></i>
                        Export
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end song-export-menu">
                        <li><button type="button" class="dropdown-item" data-export-format="openSong">OpenSong</button></li>
                        <li><button type="button" class="dropdown-item" data-export-format="openLyrics">OpenLyrics / OpenLP</button></li>
                        <li><button type="button" class="dropdown-item" data-export-format="proPresenter6">ProPresenter 6</button></li>
                        <li><button type="button" class="dropdown-item" data-export-format="proPresenter7">ProPresenter 7+</button></li>
                        <li><button type="button" class="dropdown-item" data-export-format="videoPsalm">VideoPsalm</button></li>
                        <li><button type="button" class="dropdown-item" data-export-format="freeShow">FreeShow</button></li>
                        <li><button type="button" class="dropdown-item" data-export-format="proclaim">Proclaim</button></li>
                        <li><button type="button" class="dropdown-item" data-export-format="chordPro">ChordPro</button></li>
                    </ul>
                </div>

                <!-- Chord charts toggle (#299) -->
                <button class="btn btn-sm btn-outline-secondary" id="btn-toggle-chords" style="display:none" title="Show/hide chord charts">
                    <i class="fa-solid fa-guitar me-1" aria-hidden="true"></i>Chords
                </button>

                <!-- Edit in Song Editor (#407). Hidden by default; revealed
                     by JS when the signed-in user has the `edit_songs`
                     entitlement (editor / admin / global_admin). -->
                <a class="btn btn-sm btn-outline-secondary song-toolbar-btn d-none"
                   id="btn-edit-song"
                   href="/manage/editor/?song=<?= urlencode($song['id'] ?? '') ?>"
                   title="Edit this song in the Song Editor">
                    <i class="fa-solid fa-pen-to-square me-1" aria-hidden="true"></i>
                    Edit
                </a>

                <!-- Practice / memorisation mode (#402). Cycles through
                     Full → Dimmed → Hidden; tap an individual hidden line
                     to reveal it as a hint. -->
                <button class="btn btn-sm btn-outline-secondary" id="btn-practice-mode"
                        data-practice-level="0"
                        title="Practice mode — hide lyrics progressively for memorisation">
                    <i class="fa-solid fa-graduation-cap me-1" aria-hidden="true"></i>
                    <span id="btn-practice-label">Practice</span>
                </button>
            </div>

            <!-- Song tags display (#288) -->
            <div id="song-tags-container" class="mt-2 mb-3" style="display:none">
                <small class="text-muted"><i class="fa-solid fa-tags me-1"></i>Tags:</small>
                <span id="song-tags-list"></span>
            </div>
        </div>
    </div>

    <!-- Song lyrics (#160: arrangement-aware rendering) -->
    <div class="song-lyrics" role="region" aria-label="Song lyrics">
        <?php
            /* Use arrangement order if present, otherwise display sequentially */
            $arrangement = $song['arrangement'] ?? null;
            $renderOrder = $arrangement
                ? array_map(fn($i) => $components[$i] ?? null, $arrangement)
                : $components;
            $renderOrder = array_filter($renderOrder);
            /* Gated: suppress the lyric components; the card is shown instead. */
            if ($lyricsGated) { $renderOrder = []; }
        ?>
        <?php if ($lyricsGated && function_exists('renderContentGatedFragment')): ?>
            <?= renderContentGatedFragment($gateReason) ?>
        <?php endif; ?>
        <?php
            /* #858 — collect the union of song-level + per-component
               languages so we can extend the JSON-LD MusicComposition
               with a multi-valued inLanguage further down. Tracked in
               $songLanguageUnion (lowercase BCP 47 tags). */
            $songLanguageUnion = [];
            $songPrimaryLang = (string)($song['language'] ?? '');
            if ($songPrimaryLang !== '') {
                $songLanguageUnion[] = strtolower($songPrimaryLang);
            }
        ?>
        <?php foreach ($renderOrder as $component): ?>
            <?php
                $type   = $component['type'] ?? 'verse';
                $number = $component['number'] ?? null;
                $lines  = $component['lines'] ?? [];
                /* #858 — per-component language override. NULL / empty
                   means "inherit from the song"; an explicit value
                   sets lang="…" on this <div> so screen readers /
                   browser hyphenation switch locales correctly, and
                   surfaces a small badge so a reader can see that
                   "this verse is in Spanish" at a glance. */
                $compLangRaw = $component['language'] ?? null;
                $compLang    = ($compLangRaw && trim((string)$compLangRaw) !== '')
                             ? trim((string)$compLangRaw)
                             : '';
                $effectiveLang = $compLang !== ''
                                ? $compLang
                                : ($songPrimaryLang !== '' ? $songPrimaryLang : 'en');
                if ($compLang !== '' && !in_array(strtolower($compLang), $songLanguageUnion, true)) {
                    $songLanguageUnion[] = strtolower($compLang);
                }

                /* Build a human-readable label for the component.
                   "refrain" is an alias for "chorus" — display as Chorus.
                   The editor stores `number: 0` as a sentinel for "this is the
                   only one of its kind" (issue #795). Treat any non-positive
                   or non-numeric value as "no number" so single-component songs
                   render as plain "Verse" / "Chorus" rather than "Verse 0". */
                $displayType = ($type === 'refrain') ? 'chorus' : $type;
                $label = ucfirst($displayType);
                if (is_numeric($number) && (int)$number > 0) {
                    $label .= ' ' . (int)$number;
                }

                /* CSS class for styling different component types */
                $typeClass = 'lyric-' . htmlspecialchars($type);

                /* Badge shows the resolved name only when the component
                   override DIFFERS from the song's primary language —
                   no point flagging "this English verse is English". */
                $showLangBadge = $compLang !== ''
                              && strtolower($compLang) !== strtolower($songPrimaryLang);
                if ($showLangBadge) {
                    require_once __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'language_names.php';
                }
            ?>
            <?php $effDir = resolveLanguageMeta($effectiveLang)['dir'] ?? 'ltr'; ?>
            <div class="lyric-component <?= $typeClass ?>"
                 lang="<?= htmlspecialchars($effectiveLang) ?>"<?php if ($effDir === 'rtl'): ?> dir="rtl"<?php endif; ?>
                 role="group" aria-label="<?= htmlspecialchars($label) ?>">
                <!-- Component type label -->
                <div class="lyric-label" aria-hidden="true">
                    <?= htmlspecialchars($label) ?>
                    <?php if ($showLangBadge): ?>
                        <span class="badge bg-info text-dark ms-2"
                              style="font-size: 0.65rem; vertical-align: middle;"
                              title="<?= htmlspecialchars(resolveLanguageName($compLang)) ?>">
                            <?= htmlspecialchars(strtoupper(preg_replace('/-.*$/', '', $compLang) ?: $compLang)) ?>
                        </span>
                    <?php endif; ?>
                </div>
                <!-- Lyrics lines -->
                <div class="lyric-lines">
                    <?php foreach ($lines as $line): ?>
                        <p class="lyric-line mb-1"><?= htmlspecialchars($line) ?></p>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Credits + copyright footer at end of lyrics (#601). Mirrors the
         hymnal / projection convention: a small right-aligned block
         after the last verse listing Words / Music / Adapted by /
         Translated by / Tune, then the copyright line. The same data
         is already rendered in the header, but the footer is the copy
         users see when projecting or when they have scrolled past the
         masthead. The .song-credits-footer class is kept distinct from
         the older .song-copyright (used only by print.css) so the
         right-aligned layout doesn't bleed into print rules. */ -->
    <?php if (
        !empty($_creditRows) && $_hasAnyCredit
        || $tuneName !== ''
        || !empty($ccli)
        || $iswc !== ''
        || (!$fullyPublicDomain && !empty($copyright))
        || $fullyPublicDomain
    ): ?>
        <footer class="song-credits-footer text-end small text-muted mt-4 pt-3 border-top" role="contentinfo">
            <?php if ($_hasAnyCredit): ?>
                <?php foreach ($_creditRows as $row): ?>
                    <?php [$rowId, $rowLabel, , $rowNames] = $row; ?>
                    <?php if (empty($rowNames)) continue; ?>
                    <div data-credit-kind="<?= htmlspecialchars($rowId) ?>">
                        <strong><?= htmlspecialchars($rowLabel) ?>:</strong>
                        <?php /* #951 — credits-block author / composer / etc. now
                                 click through to the same /people/<slug> page the
                                 header credits do. Same .song-meta-link styling so
                                 the footer reads as a muted parity copy of the
                                 header, not a separate visual treatment. */
                              foreach ($rowNames as $i => $name): ?><a
                            href="/people/<?= htmlspecialchars(urlencode(strtolower(str_replace(' ', '-', $name)))) ?>"
                            class="song-meta-link"
                            data-navigate="person"><?= htmlspecialchars($name) ?></a><?php if ($i < count($rowNames) - 1): ?>;&nbsp;<?php endif; ?><?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
            <?php if ($tuneName !== ''):
                /* #940 — same link as the header, mirrored in the
                   after-lyrics credits block for parity. */
                $_tuneSlugFooter = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $tuneName), '-'));
                ?>
                <div data-credit-kind="tune">
                    <strong>Tune:</strong>
                    <?php if ($_tuneSlugFooter !== ''): ?>
                        <a href="/tune/<?= htmlspecialchars($_tuneSlugFooter) ?>"
                           class="song-meta-link"
                           data-navigate="tune"
                           title="See all songs that use this tune"><?= htmlspecialchars($tuneName) ?></a>
                    <?php else: ?>
                        <?= htmlspecialchars($tuneName) ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <?php if (!empty($ccli)):
                /* #940 — CCLI / ISWC are surfaced in the credits block
                   too so a viewer who's scrolled past the masthead can
                   still see (and click) the catalogue identifiers. */
                $_ccliEncFooter = rawurlencode((string)$ccli);
                ?>
                <div data-credit-kind="ccli">
                    <strong>CCLI Song #</strong>
                    <a href="https://songselect.ccli.com/songs/<?= htmlspecialchars($_ccliEncFooter) ?>"
                       class="song-meta-link"
                       target="_blank"
                       rel="noopener noreferrer external"
                       title="View on CCLI SongSelect (opens in new tab)"><?= htmlspecialchars($ccli) ?></a>
                </div>
            <?php endif; ?>
            <?php if ($iswc !== ''):
                $_iswcEncFooter = rawurlencode((string)$iswc);
                ?>
                <div data-credit-kind="iswc" title="International Standard Musical Work Code">
                    <strong>ISWC:</strong>
                    <a href="/iswc/<?= htmlspecialchars($_iswcEncFooter) ?>"
                       class="song-meta-link"
                       data-navigate="iswc"
                       title="See all songs sharing this ISWC"><?= htmlspecialchars($iswc) ?></a>
                </div>
            <?php endif; ?>
            <?php if ($fullyPublicDomain): ?>
                <div class="mt-1" data-credit-kind="public-domain">
                    <i class="fa-regular fa-copyright me-1" aria-hidden="true"></i>
                    Public Domain
                </div>
            <?php elseif (!empty($copyright)): ?>
                <div class="mt-1" data-credit-kind="copyright">
                    <i class="fa-regular fa-copyright me-1" aria-hidden="true"></i>
                    <?= htmlspecialchars($copyright) ?>
                </div>
            <?php endif; ?>
        </footer>
    <?php endif; ?>

    <?php
    /* "Why you can use this" rights panel (#1098 P1a) — pure surfacing of the
       INDEPENDENT lyrics-PD vs music-PD flags + copyright + CCLI. The two PD
       axes are reported as a combined verdict for the reader, but are never
       AND-ed for gating (#939). Helps worship leaders judge project/print/use. */
    $rpLyricsPd = !empty($song['lyricsPublicDomain']);
    $rpMusicPd  = !empty($song['musicPublicDomain']);
    $rpCcli     = trim((string)($song['ccli'] ?? ''));
    $rpIswc     = trim((string)($song['iswc'] ?? ''));
    $rpCopyright = trim((string)($song['copyright'] ?? ''));
    if ($rpLyricsPd && $rpMusicPd) {
        $rpClass = 'success'; $rpIcon = 'fa-circle-check';
        $rpTitle = 'Public domain';
        $rpMsg   = 'Both the words and the music are in the public domain — free to project, print, and translate.';
    } elseif ($rpLyricsPd || $rpMusicPd) {
        $rpClass = 'warning'; $rpIcon = 'fa-circle-half-stroke';
        $rpTitle = 'Partly public domain';
        $rpMsg   = $rpLyricsPd
            ? 'The lyrics are public domain, but the music / tune may still be under copyright — check before reproducing the music.'
            : 'The tune is public domain, but the lyrics may still be under copyright — check before reproducing the words.';
    } else {
        $rpClass = 'secondary'; $rpIcon = 'fa-shield-halved';
        $rpTitle = 'Under copyright';
        $rpMsg   = $rpCcli !== ''
            ? 'Likely covered by your church CCLI licence — remember to report this song under CCLI #' . htmlspecialchars($rpCcli) . '.'
            : 'Check your licence (e.g. CCLI) before projecting, printing, or translating.';
    }
    ?>
    <section class="song-rights mt-4 pt-3 border-top" aria-label="Usage and rights">
        <h2 class="h6 mb-2 d-flex align-items-center gap-2">
            <i class="fa-solid fa-scale-balanced text-muted" aria-hidden="true"></i>Can you use this?
        </h2>
        <div class="alert alert-<?= $rpClass ?> py-2 px-3 mb-2 small d-flex align-items-start gap-2" role="note">
            <i class="fa-solid <?= $rpIcon ?> mt-1" aria-hidden="true"></i>
            <span><strong><?= htmlspecialchars($rpTitle) ?>.</strong> <?= $rpMsg ?></span>
        </div>
        <ul class="list-inline small text-muted mb-0">
            <li class="list-inline-item">Lyrics: <strong><?= $rpLyricsPd ? 'Public domain' : 'Copyright' ?></strong></li>
            <li class="list-inline-item">·</li>
            <li class="list-inline-item">Music: <strong><?= $rpMusicPd ? 'Public domain' : 'Copyright' ?></strong></li>
            <?php if ($rpCcli !== ''): ?>
                <li class="list-inline-item">·</li>
                <li class="list-inline-item">CCLI <a href="https://songselect.ccli.com/Search/Results?SongNumber=<?= rawurlencode($rpCcli) ?>" target="_blank" rel="noopener" class="song-meta-link">#<?= htmlspecialchars($rpCcli) ?></a></li>
            <?php endif; ?>
            <?php if ($rpIswc !== ''): ?>
                <li class="list-inline-item">·</li>
                <li class="list-inline-item">ISWC <?= htmlspecialchars($rpIswc) ?></li>
            <?php endif; ?>
        </ul>
        <p class="text-muted fst-italic mb-0 mt-1" style="font-size:.75rem">Guidance only — confirm rights with your licence provider.</p>
    </section>

    <!-- Report a missing song (→ /request page #656/#658) + suggest a structured
         correction for THIS song (#1092 — posts {songId, field, proposed} to
         song_correction_submit; the server reads the current value itself). -->
    <div class="mt-3 small">
        <a href="/request" data-navigate="request" class="text-muted text-decoration-none me-3">
            <i class="fa-solid fa-flag me-1" aria-hidden="true"></i>Report a missing song
        </a>
        <a class="text-muted text-decoration-none" role="button" data-bs-toggle="collapse"
           href="#song-correction-form" aria-expanded="false" aria-controls="song-correction-form">
            <i class="fa-solid fa-pen-to-square me-1" aria-hidden="true"></i>Suggest a correction
        </a>
    </div>
    <div class="collapse mt-2" id="song-correction-form">
        <form id="correction-form" class="card card-body" data-song-id="<?= htmlspecialchars((string)$songId) ?>" novalidate>
            <p class="small text-muted mb-2">Spotted an error in this song? Tell us what should change &mdash; a curator will review it.</p>
            <div class="row g-2">
                <div class="col-sm-4">
                    <label class="form-label small mb-1" for="correction-field">What needs correcting?</label>
                    <select class="form-select form-select-sm" id="correction-field" required>
                        <option value="title">Title</option>
                        <option value="lyrics">Lyrics</option>
                        <option value="author">Author / writer</option>
                        <option value="composer">Composer</option>
                        <option value="copyright">Copyright</option>
                        <option value="tune">Tune name</option>
                        <option value="ccli">CCLI number</option>
                        <option value="iswc">ISWC</option>
                        <option value="language">Language</option>
                        <option value="other">Something else</option>
                    </select>
                </div>
                <div class="col-sm-8">
                    <label class="form-label small mb-1" for="correction-email">Your email <span class="text-muted">(optional, for follow-up)</span></label>
                    <input type="email" class="form-control form-control-sm" id="correction-email" autocomplete="email" maxlength="255">
                </div>
            </div>
            <div class="mt-2">
                <label class="form-label small mb-1" for="correction-proposed">What should it say?</label>
                <textarea class="form-control form-control-sm" id="correction-proposed" rows="3" required maxlength="5000"></textarea>
            </div>
            <!-- honeypot: real users leave this blank -->
            <input type="text" id="correction-website" name="website" tabindex="-1" autocomplete="off"
                   class="position-absolute" style="left:-9999px" aria-hidden="true">
            <div class="mt-2 d-flex align-items-center gap-2">
                <button type="submit" class="btn btn-sm btn-primary">Submit correction</button>
                <span id="correction-feedback" class="small" role="status" aria-live="polite"></span>
            </div>
        </form>
    </div>

    <!-- Song translations (#352) — populated client-side from API -->
    <section id="song-translations" class="song-translations mt-4 pt-3 border-top d-none" aria-label="Translations">
        <h2 class="h6 mb-3 d-flex align-items-center gap-2" role="button" data-bs-toggle="collapse" data-bs-target="#song-translations-list" aria-expanded="true" aria-controls="song-translations-list">
            <i class="fa-solid fa-language me-1 text-muted" aria-hidden="true"></i>
            Translations
            <i class="fa-solid fa-chevron-down ms-auto small text-muted" aria-hidden="true"></i>
        </h2>
        <div class="collapse show" id="song-translations-list">
            <div class="list-group list-group-flush" id="song-translations-items" role="list">
                <!-- Rendered by JS -->
            </div>
        </div>
    </section>

    <?php
        /* #853 — accompanying media (audio + sheet PDF + MIDI + MusicXML).
           Reads $song['media'] attached by SongData::_songMediaMap.
           Audio gets an inline <audio> player (HTML5 native, supports
           HTTP Range so the streaming endpoint's 206 response lets the
           seek-bar work). Sheet music / MIDI / MusicXML get download
           buttons. Annotations render as a per-row caption. Hidden
           when no media is attached. */
        $songMedia = $song['media'] ?? [];
        if (!empty($songMedia)):
            $mediaByKind = [
                'audio' => [], 'sheet-music' => [], 'midi' => [], 'musicxml' => [],
            ];
            foreach ($songMedia as $m) {
                $k = (string)($m['kind'] ?? '');
                if (isset($mediaByKind[$k])) $mediaByKind[$k][] = $m;
            }
    ?>
    <section id="song-media" class="song-media mt-4 pt-3 border-top" aria-label="Recordings &amp; resources">
        <h2 class="h6 mb-3 d-flex align-items-center gap-2">
            <i class="fa-solid fa-music me-1 text-muted" aria-hidden="true"></i>
            Recordings &amp; resources
        </h2>

        <?php if (!empty($mediaByKind['audio'])): ?>
            <div class="song-media-audio mb-3">
                <?php foreach ($mediaByKind['audio'] as $m): ?>
                    <div class="mb-2">
                        <div class="small text-muted mb-1">
                            <?= htmlspecialchars($m['fileName']) ?>
                            <?php if (!empty($m['annotation'])): ?>
                                — <em><?= htmlspecialchars($m['annotation']) ?></em>
                            <?php endif; ?>
                        </div>
                        <audio controls preload="none" class="w-100"
                               src="<?= htmlspecialchars($m['streamUrl']) ?>"
                               type="<?= htmlspecialchars($m['mimeType']) ?>">
                            Your browser doesn't support the audio element.
                            <a href="<?= htmlspecialchars($m['streamUrl']) ?>">Download <?= htmlspecialchars($m['fileName']) ?></a>.
                        </audio>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php
            /* Non-audio kinds render as a chip-row of download buttons
               so the section stays compact when a song has 1 PDF + 1
               MIDI + 1 MusicXML. */
            $downloadKinds = [
                'sheet-music' => ['label' => 'Sheet music',  'icon' => 'fa-file-pdf'],
                'midi'        => ['label' => 'MIDI',         'icon' => 'fa-music'],
                'musicxml'    => ['label' => 'MusicXML',     'icon' => 'fa-file-code'],
            ];
            $hasDownloads = false;
            foreach ($downloadKinds as $k => $_) {
                if (!empty($mediaByKind[$k])) { $hasDownloads = true; break; }
            }
        ?>
        <?php if ($hasDownloads): ?>
            <?php foreach ($downloadKinds as $kind => $kMeta): ?>
                <?php if (empty($mediaByKind[$kind])) continue; ?>
                <div class="mb-2">
                    <div class="text-uppercase small text-muted mb-1"><?= htmlspecialchars($kMeta['label']) ?></div>
                    <div class="d-flex flex-wrap gap-2">
                        <?php foreach ($mediaByKind[$kind] as $m): ?>
                            <a href="<?= htmlspecialchars($m['streamUrl']) ?>"
                               class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-2"
                               download="<?= htmlspecialchars($m['fileName']) ?>">
                                <i class="fa-solid <?= htmlspecialchars($kMeta['icon']) ?>" aria-hidden="true"></i>
                                <span><?= htmlspecialchars($m['fileName']) ?></span>
                                <?php if (!empty($m['annotation'])): ?>
                                    <span class="text-muted small">— <?= htmlspecialchars($m['annotation']) ?></span>
                                <?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <?php
        /* #840 — "Part of work" panel. Reads $song['works'] attached by
           SongData::_worksMap (#840). Lists each Work this song belongs
           to with its sibling members ("other versions of this work")
           grouped under it. Hidden when empty + when the schema isn't
           applied. */
        $songWorks = $song['works'] ?? [];
        if (!empty($songWorks)):
    ?>
    <section id="song-works" class="song-works mt-4 pt-3 border-top" aria-label="Part of work">
        <h2 class="h6 mb-3 d-flex align-items-center gap-2">
            <i class="fa-solid fa-diagram-project me-1 text-muted" aria-hidden="true"></i>
            Part of work
        </h2>
        <?php foreach ($songWorks as $w): ?>
            <div class="mb-3 song-work-block">
                <div class="d-flex flex-wrap align-items-baseline gap-2 mb-1">
                    <a href="/work/<?= htmlspecialchars($w['slug']) ?>"
                       class="fw-semibold"
                       data-navigate="work"
                       data-work-slug="<?= htmlspecialchars($w['slug']) ?>">
                        <?= htmlspecialchars($w['title']) ?>
                    </a>
                    <?php if (!empty($w['iswc'])): ?>
                        <span class="text-muted small">ISWC: <code><?= htmlspecialchars($w['iswc']) ?></code></span>
                    <?php endif; ?>
                    <?php if (!empty($w['isCanonical'])): ?>
                        <span class="badge bg-success-subtle text-success-emphasis">Canonical version</span>
                    <?php endif; ?>
                </div>
                <?php
                    $siblings = array_values(array_filter(
                        $w['members'] ?? [],
                        static fn($m) => (string)$m['songId'] !== (string)$song['id']
                    ));
                ?>
                <?php if (!empty($siblings)): ?>
                    <div class="text-uppercase small text-muted mb-1">Other versions of this work</div>
                    <div class="list-group list-group-flush song-list">
                        <?php foreach ($siblings as $m): ?>
                            <a href="/song/<?= htmlspecialchars($m['songId']) ?>"
                               class="list-group-item list-group-item-action song-list-item d-flex align-items-center gap-2"
                               data-navigate="song"
                               data-song-id="<?= htmlspecialchars($m['songId']) ?>">
                                <span class="badge bg-body-secondary"><?= htmlspecialchars($m['songbook']) ?></span>
                                <?php if ((int)$m['number'] > 0): ?>
                                    <span class="text-muted small">#<?= (int)$m['number'] ?></span>
                                <?php endif; ?>
                                <span class="flex-grow-1"><?= htmlspecialchars(toTitleCase((string)$m['title'])) ?></span>
                                <?php if (!empty($m['isCanonical'])): ?>
                                    <i class="fa-solid fa-star text-warning small" aria-label="Canonical version" title="Canonical version"></i>
                                <?php endif; ?>
                                <i class="fa-solid fa-chevron-right text-muted small" aria-hidden="true"></i>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </section>
    <?php endif; ?>

    <?php
        /* #833 — "Find this hymn elsewhere" panel. Reads tblSongExternalLinks
           via the `links` array attached by SongData::_externalLinksMap('song'),
           groups by Category, hides when empty. Each link opens in a new
           tab with rel="noopener nofollow" so search-engine PageRank
           doesn't leak. */
        $songLinksRows = $song['links'] ?? [];
        if (!empty($songLinksRows)):
            $sLinksByCat = [];
            foreach ($songLinksRows as $l) {
                $cat = (string)($l['category'] ?? 'other');
                if (!isset($sLinksByCat[$cat])) $sLinksByCat[$cat] = [];
                $sLinksByCat[$cat][] = $l;
            }
            $sCatLabels = [
                'official'    => 'Official',
                'information' => 'Information',
                'read'        => 'Read',
                'sheet-music' => 'Sheet music',
                'listen'      => 'Listen',
                'watch'       => 'Watch',
                'purchase'    => 'Purchase',
                'authority'   => 'Authority',
                'social'      => 'Social',
                'other'       => 'Other',
            ];
    ?>
    <section id="song-external-links" class="song-external-links mt-4 pt-3 border-top" aria-label="Find this hymn elsewhere">
        <h2 class="h6 mb-3 d-flex align-items-center gap-2">
            <i class="fa-solid fa-link me-1 text-muted" aria-hidden="true"></i>
            Find this hymn elsewhere
        </h2>
        <?php foreach ($sCatLabels as $cat => $catLabel): ?>
            <?php if (empty($sLinksByCat[$cat])) continue; ?>
            <div class="mb-2">
                <div class="text-uppercase small text-muted mb-1"><?= htmlspecialchars($catLabel) ?></div>
                <div class="d-flex flex-wrap gap-2">
                    <?php foreach ($sLinksByCat[$cat] as $l): ?>
                        <a href="<?= htmlspecialchars($l['url']) ?>"
                           target="_blank" rel="noopener nofollow"
                           class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-2">
                            <?php if (!empty($l['iconClass'])): ?>
                                <i class="<?= htmlspecialchars($l['iconClass']) ?>" aria-hidden="true"></i>
                            <?php endif; ?>
                            <span><?= htmlspecialchars($l['name']) ?></span>
                            <?php if (!empty($l['note'])): ?>
                                <span class="text-muted small">— <?= htmlspecialchars($l['note']) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($l['verified'])): ?>
                                <i class="fa-solid fa-circle-check text-success small" aria-label="Verified" title="Verified"></i>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </section>
    <?php endif; ?>

    <!-- Related songs (#118) — populated client-side from songs.json -->
    <section id="related-songs" class="related-songs mt-4 pt-3 border-top d-none" aria-label="Related songs">
        <h2 class="h6 mb-3 d-flex align-items-center gap-2" role="button" data-bs-toggle="collapse" data-bs-target="#related-songs-list" aria-expanded="true" aria-controls="related-songs-list">
            <i class="fa-solid fa-music me-1 text-muted" aria-hidden="true"></i>
            Related Songs
            <i class="fa-solid fa-chevron-down ms-auto small text-muted related-songs-chevron" aria-hidden="true"></i>
        </h2>
        <div class="collapse show" id="related-songs-list">
            <div class="list-group list-group-flush" id="related-songs-items" role="list">
                <!-- Rendered by JS -->
            </div>
        </div>
    </section>

    <!-- Previous/Next navigation -->
    <?php
        /* Find previous and next songs in the same songbook */
        $bookSongs = $songData->getSongs($songbook);
        $prevSong = null;
        $nextSong = null;
        foreach ($bookSongs as $i => $s) {
            if ($s['id'] === $song['id']) {
                $prevSong = $bookSongs[$i - 1] ?? null;
                $nextSong = $bookSongs[$i + 1] ?? null;
                break;
            }
        }
    ?>
    <nav class="song-navigation mt-4 pt-3 border-top" aria-label="Song navigation">
        <div class="d-flex justify-content-between">
            <?php if ($prevSong): ?>
                <a href="/song/<?= htmlspecialchars($prevSong['id']) ?>"
                   class="btn btn-outline-secondary btn-sm song-toolbar-btn"
                   data-navigate="song"
                   data-song-id="<?= htmlspecialchars($prevSong['id']) ?>"
                   aria-label="Previous song: <?= htmlspecialchars(toTitleCase($prevSong['title'])) ?>">
                    <i class="fa-solid fa-chevron-left me-1" aria-hidden="true"></i>
                    #<?= (int)$prevSong['number'] ?>
                </a>
            <?php else: ?>
                <span></span>
            <?php endif; ?>

            <?php if ($nextSong): ?>
                <a href="/song/<?= htmlspecialchars($nextSong['id']) ?>"
                   class="btn btn-outline-secondary btn-sm song-toolbar-btn"
                   data-navigate="song"
                   data-song-id="<?= htmlspecialchars($nextSong['id']) ?>"
                   aria-label="Next song: <?= htmlspecialchars(toTitleCase($nextSong['title'])) ?>">
                    #<?= (int)$nextSong['number'] ?>
                    <i class="fa-solid fa-chevron-right ms-1" aria-hidden="true"></i>
                </a>
            <?php endif; ?>
        </div>
    </nav>

</article>

<!-- Export-to-format wiring (#1166). The SPA re-runs injected inline scripts,
     so this binds the Export dropdown after the fragment loads. -->
<script>
(function () {
    if (!document.querySelector('.btn-export-song')) { return; }
    var songId = <?= json_encode($song['id'] ?? '', JSON_UNESCAPED_SLASHES) ?>;
    import('/js/modules/export-ui.js')
        .then(function (m) { m.initSongExport(songId); })
        .catch(function () { /* export is best-effort; never block the page */ });
})();
</script>

<!-- Presentation mode JS (#297) -->
<script>
(function() {
    const btnPresent = document.getElementById('btn-present');
    if (!btnPresent) return;

    btnPresent.addEventListener('click', () => {
        /* Collect all song components from the rendered page */
        const comps = document.querySelectorAll('.lyric-component');
        if (comps.length === 0) return;

        const slides = [];
        comps.forEach(comp => {
            const label = comp.querySelector('.lyric-label')?.textContent?.trim() || '';
            const lines = Array.from(comp.querySelectorAll('.lyric-line')).map(l => l.textContent);
            slides.push({ label, text: lines.join('\n') });
        });

        let current = 0;

        /* Create overlay */
        const overlay = document.createElement('div');
        overlay.className = 'presentation-overlay';
        overlay.innerHTML = `
            <button class="present-close" aria-label="Close presentation">&times;</button>
            <div class="present-label"></div>
            <div class="present-lyrics"></div>
            <div class="present-nav">
                <button class="present-prev" aria-label="Previous"><i class="fa-solid fa-chevron-left me-1"></i>Prev</button>
                <button class="present-counter"></button>
                <button class="present-next" aria-label="Next">Next<i class="fa-solid fa-chevron-right ms-1"></i></button>
            </div>
        `;

        const labelEl = overlay.querySelector('.present-label');
        const lyricsEl = overlay.querySelector('.present-lyrics');
        const counterEl = overlay.querySelector('.present-counter');
        const prevBtn = overlay.querySelector('.present-prev');
        const nextBtn = overlay.querySelector('.present-next');

        function render() {
            const slide = slides[current];
            labelEl.textContent = slide.label;
            lyricsEl.textContent = slide.text;
            counterEl.textContent = (current + 1) + ' / ' + slides.length;
            prevBtn.disabled = current === 0;
            nextBtn.disabled = current === slides.length - 1;
        }

        function close() {
            if (document.fullscreenElement) {
                document.exitFullscreen().catch(() => {});
            }
            overlay.remove();
        }

        function next() { if (current < slides.length - 1) { current++; render(); } }
        function prev() { if (current > 0) { current--; render(); } }

        /* Navigation events */
        overlay.querySelector('.present-close').addEventListener('click', close);
        prevBtn.addEventListener('click', (e) => { e.stopPropagation(); prev(); });
        nextBtn.addEventListener('click', (e) => { e.stopPropagation(); next(); });
        counterEl.addEventListener('click', (e) => e.stopPropagation());

        /* Click on lyrics area advances */
        lyricsEl.addEventListener('click', next);

        /* Keyboard navigation */
        function onKey(e) {
            if (e.key === 'Escape') { close(); document.removeEventListener('keydown', onKey); }
            else if (e.key === 'ArrowRight' || e.key === ' ') { e.preventDefault(); next(); }
            else if (e.key === 'ArrowLeft') { e.preventDefault(); prev(); }
        }
        document.addEventListener('keydown', onKey);

        /* Touch swipe support */
        let touchStartX = 0;
        overlay.addEventListener('touchstart', (e) => { touchStartX = e.changedTouches[0].screenX; }, { passive: true });
        overlay.addEventListener('touchend', (e) => {
            const diff = e.changedTouches[0].screenX - touchStartX;
            if (Math.abs(diff) > 50) {
                if (diff < 0) next(); else prev();
            }
        }, { passive: true });

        /* Cleanup on removal */
        overlay.addEventListener('remove', () => document.removeEventListener('keydown', onKey));

        render();
        document.body.appendChild(overlay);

        /* Enter fullscreen if available */
        if (overlay.requestFullscreen) {
            overlay.requestFullscreen().catch(() => {});
        }
    });
})();
</script>
