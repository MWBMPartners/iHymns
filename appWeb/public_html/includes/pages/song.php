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

/* Fetch the full song data */
$song = $songData->getSongById($songId);

/* Handle song not found */
if ($song === null) {
    http_response_code(404);
    echo '<div class="alert alert-warning" role="alert">';
    echo '<i class="fa-solid fa-circle-exclamation me-2" aria-hidden="true"></i>';
    echo 'Song not found: <strong>' . htmlspecialchars($songId) . '</strong>';
    echo '</div>';
    echo '<a href="/songbooks" class="btn btn-primary" data-navigate="songbooks">';
    echo '<i class="fa-solid fa-arrow-left me-2" aria-hidden="true"></i>Back to Songbooks</a>';
    return;
}

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
$lyricsPublicDomain = !empty($song['lyricsPublicDomain']);
$musicPublicDomain  = !empty($song['musicPublicDomain']);
$fullyPublicDomain  = $lyricsPublicDomain && $musicPublicDomain;

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
$translations = [];
try {
    require_once __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'db_mysql.php';
    $translationsDb = getDbMysqli();
    $sql = '
        /* Outward — this song has translations to other languages */
        SELECT t.TranslatedSongId  AS song_id,
               t.TargetLanguage    AS target_language,
               l.Name              AS language_name,
               l.NativeName        AS native_name,
               l.TextDirection     AS text_direction,
               t.Translator        AS translator,
               t.Verified          AS verified
          FROM tblSongTranslations t
          JOIN tblLanguages l ON l.Code = t.TargetLanguage
         WHERE t.SourceSongId = ? AND l.IsActive = 1
        UNION
        /* Inward — this song IS a translation of another; surface the
           source plus any siblings (other translations of that source). */
        SELECT src.SongId          AS song_id,
               srcLang.Code        AS target_language,
               srcLang.Name        AS language_name,
               srcLang.NativeName  AS native_name,
               srcLang.TextDirection AS text_direction,
               ""                  AS translator,
               1                   AS verified
          FROM tblSongTranslations selfT
          JOIN tblSongs src            ON src.SongId = selfT.SourceSongId
          JOIN tblLanguages srcLang    ON srcLang.Code = src.Language
         WHERE selfT.TranslatedSongId = ? AND srcLang.IsActive = 1
        UNION
        SELECT sibling.TranslatedSongId AS song_id,
               sibling.TargetLanguage   AS target_language,
               l2.Name                  AS language_name,
               l2.NativeName            AS native_name,
               l2.TextDirection         AS text_direction,
               sibling.Translator       AS translator,
               sibling.Verified         AS verified
          FROM tblSongTranslations selfT2
          JOIN tblSongTranslations sibling
               ON sibling.SourceSongId = selfT2.SourceSongId
              AND sibling.TranslatedSongId <> selfT2.TranslatedSongId
          JOIN tblLanguages l2 ON l2.Code = sibling.TargetLanguage
         WHERE selfT2.TranslatedSongId = ? AND l2.IsActive = 1
    ';
    $stmt = $translationsDb->prepare($sql);
    if ($stmt !== false) {
        $sid = (string)($song['id'] ?? '');
        $stmt->bind_param('sss', $sid, $sid, $sid);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) $translations[] = $row;
        $stmt->close();
    }
} catch (\Throwable $_e) {
    /* No translations infrastructure — picker stays hidden. */
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
<article class="page-song" aria-label="<?= htmlspecialchars($songTitle) ?>" data-song-id="<?= htmlspecialchars($song['id']) ?>" data-songbook="<?= htmlspecialchars($songbook) ?>"<?php if ($songbookColour !== ''): ?> data-songbook-color="<?= htmlspecialchars($songbookColour) ?>"<?php endif; ?><?php if ($songNumber !== null): ?> data-song-number="<?= (int)$songNumber ?>"<?php endif; ?><?php if (!empty($song['capo'])): ?> data-capo="<?= (int)$song['capo'] ?>"<?php endif; ?><?php if (!empty($song['key'])): ?> data-key="<?= htmlspecialchars($song['key']) ?>"<?php endif; ?>>

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
                    <h1 class="h4 mb-1"><?= htmlspecialchars($songTitle) ?><?php if (!empty($song['verified'])): ?><span class="verified-badge" title="Verified lyrics" aria-label="Verified lyrics"><svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="10" fill="currentColor" opacity="0.15"/><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="1.5" fill="none"/><path d="M7.5 12.5L10.5 15.5L16.5 9" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></span><?php endif; ?></h1>
                    <p class="text-muted mb-0">
                        <span class="badge bg-body-secondary"><?= htmlspecialchars($songbook) ?></span>
                        <?= htmlspecialchars($bookName) ?>
                    </p>
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
                                   class="writer-link"
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
                 the credits + a smaller dimmer Tune line. The link
                 target `/tune/<slug>` is reserved for a future
                 cross-reference listing (#494); until that ships we
                 still render the value as plain text. -->
            <?php if ($tuneName !== ''): ?>
                <div class="song-meta mb-3">
                    <p class="mb-0 song-credit-row" data-credit-kind="tune">
                        <i class="fa-solid fa-music me-2 text-muted" aria-hidden="true"></i>
                        <strong>Tune:</strong> <?= htmlspecialchars($tuneName) ?>
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
                            <?php if (!empty($ccli)): ?>
                                <span class="small text-muted">
                                    <i class="fa-solid fa-hashtag me-2" aria-hidden="true"></i>
                                    <strong>CCLI Song #</strong>&nbsp;<?= htmlspecialchars($ccli) ?>
                                </span>
                            <?php endif; ?>
                            <?php if ($iswc !== ''): ?>
                                <span class="small text-muted" title="International Standard Musical Work Code">
                                    <i class="fa-solid fa-barcode me-2" aria-hidden="true"></i>
                                    <strong>ISWC:</strong>&nbsp;<?= htmlspecialchars($iswc) ?>
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
                        data-song-id="<?= htmlspecialchars($song['id']) ?>"
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
        ?>
        <?php foreach ($renderOrder as $component): ?>
            <?php
                $type   = $component['type'] ?? 'verse';
                $number = $component['number'] ?? null;
                $lines  = $component['lines'] ?? [];

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
            ?>
            <div class="lyric-component <?= $typeClass ?>" role="group" aria-label="<?= htmlspecialchars($label) ?>">
                <!-- Component type label -->
                <div class="lyric-label" aria-hidden="true">
                    <?= htmlspecialchars($label) ?>
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
                        <?= htmlspecialchars(implode('; ', $rowNames)) ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
            <?php if ($tuneName !== ''): ?>
                <div data-credit-kind="tune">
                    <strong>Tune:</strong> <?= htmlspecialchars($tuneName) ?>
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

    <!-- Report missing song link — points at the dedicated form page (#656)
         rather than dumping the user at the bottom of the long /help
         article where the request form used to live. URL is /request (#658)
         with /request-a-song retained as a back-compat alias. -->
    <div class="mt-3">
        <a href="/request" data-navigate="request" class="text-muted small text-decoration-none">
            <i class="fa-solid fa-flag me-1" aria-hidden="true"></i>
            Report a missing song or suggest a correction
        </a>
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
