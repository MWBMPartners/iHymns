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

/* Extract metadata for convenience — Number is null for Misc songs (#392) */
$rawSongNumber = $song['number'] ?? null;
$songNumber    = ($rawSongNumber === null || $rawSongNumber === '') ? null : (int)$rawSongNumber;
$songTitle   = toTitleCase($song['title'] ?? 'Untitled');
$songbook    = $song['songbook'] ?? '';
$bookName    = $song['songbookName'] ?? '';
$writers     = $song['writers']     ?? [];
$composers   = $song['composers']   ?? [];
$arrangers   = $song['arrangers']   ?? [];   /* #497 */
$adaptors    = $song['adaptors']    ?? [];   /* #497 */
$translators = $song['translators'] ?? [];   /* #497 */
$tuneName    = $song['tuneName']    ?? '';   /* #497 */
$iswc        = $song['iswc']        ?? '';   /* #497 */
$copyright   = $song['copyright']   ?? '';
$ccli        = $song['ccli']        ?? '';
$hasAudio    = !empty($song['hasAudio']);
$hasSheet    = !empty($song['hasSheetMusic']);
$components  = $song['components'] ?? [];

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

?>

<!-- ================================================================
     SONG PAGE — Full lyrics and metadata
     ================================================================ -->
<article class="page-song" aria-label="<?= htmlspecialchars($songTitle) ?>" data-song-id="<?= htmlspecialchars($song['id']) ?>" data-songbook="<?= htmlspecialchars($songbook) ?>" data-song-number="<?= (int)$songNumber ?>"<?php if (!empty($song['capo'])): ?> data-capo="<?= (int)$song['capo'] ?>"<?php endif; ?><?php if (!empty($song['key'])): ?> data-key="<?= htmlspecialchars($song['key']) ?>"<?php endif; ?>>

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
                <span itemprop="name">#<?= $songNumber ?></span>
                <meta itemprop="position" content="3">
            </li>
        </ol>
    </nav>

    <!-- Song header card -->
    <div class="card card-song-header mb-4">
        <div class="card-body">
            <!-- Song number and title -->
            <div class="d-flex align-items-start gap-3 mb-3">
                <span class="song-number-badge-lg" data-songbook="<?= htmlspecialchars($songbook) ?>"
                      aria-label="<?= $songNumber === null ? 'Unnumbered song' : 'Song number ' . (int)$songNumber ?>">
                    <?= $songNumber === null ? '' : (int)$songNumber ?>
                </span>
                <div class="flex-grow-1">
                    <h1 class="h4 mb-1"><?= htmlspecialchars($songTitle) ?><?php if (!empty($song['verified'])): ?><span class="verified-badge" title="Verified lyrics" aria-label="Verified lyrics"><svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="10" fill="currentColor" opacity="0.15"/><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="1.5" fill="none"/><path d="M7.5 12.5L10.5 15.5L16.5 9" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></span><?php endif; ?></h1>
                    <p class="text-muted mb-0">
                        <span class="badge bg-body-secondary"><?= htmlspecialchars($songbook) ?></span>
                        <?= htmlspecialchars($bookName) ?>
                    </p>
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
            $_creditRows = [
                ['words',       'Words',       'fa-solid fa-pen-fancy',   $writers],
                ['music',       'Music',       'fa-solid fa-music',       $composers],
                ['arranged',    'Arranged by', 'fa-solid fa-sliders',     $arrangers],
                ['adapted',     'Adapted by',  'fa-solid fa-compact-disc',$adaptors],
                ['translated',  'Translated by','fa-solid fa-language',   $translators],
            ];
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
                            <?php foreach ($rowNames as $i => $name): ?><a href="/writer/<?= htmlspecialchars(urlencode(strtolower(str_replace(' ', '-', $name)))) ?>"
                                   class="writer-link"
                                   data-navigate="writer"><?= htmlspecialchars($name) ?></a><?php if ($i < count($rowNames) - 1): ?>;&nbsp;<?php endif; ?><?php endforeach; ?>
                        </p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- Tune name (#497). Rendered when set so the viewer sees
                 "Tune: HYFRYDOL" — a meaningful pointer for hymnbook
                 users. The link target `/tune/<slug>` is reserved for
                 a future cross-reference listing (#494); until that
                 ships we still render the label as plain text. -->
            <?php if ($tuneName !== ''): ?>
                <p class="song-meta-tune small text-muted mb-2">
                    <i class="fa-solid fa-music-note-list me-2" aria-hidden="true"></i>
                    <strong>Tune:</strong> <?= htmlspecialchars($tuneName) ?>
                </p>
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

            <!-- Copyright, CCLI Song Number, ISWC in song header (#497). -->
            <?php if (!empty($copyright) || !empty($ccli) || $iswc !== ''): ?>
                <div class="song-meta-copyright mb-3">
                    <?php if (!empty($copyright)): ?>
                        <p class="mb-1 small text-muted">
                            <i class="fa-regular fa-copyright me-2" aria-hidden="true"></i>
                            <?= htmlspecialchars($copyright) ?>
                        </p>
                    <?php endif; ?>
                    <?php if (!empty($ccli)): ?>
                        <p class="mb-<?= $iswc !== '' ? '1' : '0' ?> small text-muted">
                            <i class="fa-solid fa-hashtag me-2" aria-hidden="true"></i>
                            CCLI Song #<?= htmlspecialchars($ccli) ?>
                        </p>
                    <?php endif; ?>
                    <?php if ($iswc !== ''): ?>
                        <p class="mb-0 small text-muted" title="International Standard Musical Work Code">
                            <i class="fa-solid fa-barcode me-2" aria-hidden="true"></i>
                            ISWC <?= htmlspecialchars($iswc) ?>
                        </p>
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
                   "refrain" is an alias for "chorus" — display as Chorus. */
                $displayType = ($type === 'refrain') ? 'chorus' : $type;
                $label = ucfirst($displayType);
                if ($number !== null) {
                    $label .= ' ' . $number;
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

    <!-- Copyright notice -->
    <?php if (!empty($copyright) || !empty($ccli)): ?>
        <div class="song-copyright mt-4 pt-3 border-top" role="contentinfo">
            <?php if (!empty($copyright)): ?>
                <p class="text-muted small mb-1">
                    <i class="fa-regular fa-copyright me-1" aria-hidden="true"></i>
                    <?= htmlspecialchars($copyright) ?>
                </p>
            <?php endif; ?>
            <?php if (!empty($ccli)): ?>
                <p class="text-muted small mb-0">
                    <i class="fa-solid fa-hashtag me-1" aria-hidden="true"></i>
                    CCLI Song #<?= htmlspecialchars($ccli) ?>
                </p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- Report missing song link -->
    <div class="mt-3">
        <a href="/help" data-navigate="help" class="text-muted small text-decoration-none">
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
