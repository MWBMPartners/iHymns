<?php

declare(strict_types=1);

/**
 * iHymns — Theme (Tag) Public Page (#1637)
 *
 * PURPOSE:
 * Lists every song carrying a given theme/tag (tblSongTags <-> tblSongTagMap,
 * #1152's CCLI/OpenLyrics theme vocabulary). Reached from the home page's
 * "Browse by theme" chips (js/modules/home-page.js renderThemeChip(), which
 * already emits `<a href="/tag/<slug>" data-navigate="tag">` — this file,
 * the `tag` case in api.php's page switch, and router.js's `tag` route were
 * the three missing pieces; the chips themselves were correct all along).
 *
 * Loaded via api.php?page=tag&slug=grace. Expects $tagSlug to be set by
 * api.php before inclusion (may be '' — see the empty/unknown handling
 * below, both are the caller's responsibility per rule #17: this file
 * never falls back to a corpus scan to "find" a tag).
 *
 * Data path deliberately mirrors api.php's existing `songs_by_tag` action
 * (fully built, zero callers before this) rather than adding a third copy
 * of the same two bounded queries to SongData — same tag lookup, same
 * TagId-scoped join through tblSongTagMap. See songbook.php for the
 * single-songbook version of this "filtered song list" shape; this page
 * follows writer.php instead where they differ, because a theme (like a
 * writer's credits) spans many songbooks rather than living inside one.
 */

if (!isset($songData) || !is_object($songData)) {
    require_once __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'SongData.php';
    $songData = new SongData();
}

$tagSlug = isset($tagSlug) ? trim((string)$tagSlug) : '';

$tagInfo  = null;
$tagSongs = [];

if ($tagSlug !== '') {
    try {
        $tdb = getDbMysqli();

        /* Tag lookup — identical shape to api.php's songs_by_tag action. */
        $stmt = $tdb->prepare(
            'SELECT Id AS id, Name AS name, Slug AS slug, Description AS description
               FROM tblSongTags
              WHERE Slug = ?'
        );
        $stmt->bind_param('s', $tagSlug);
        $stmt->execute();
        $tagInfo = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($tagInfo) {
            $tagInfo['id'] = (int)$tagInfo['id'];

            /* Songs for this tag — same bounded join as songs_by_tag,
               scoped by TagId (never a whole-corpus scan, rule #17).
               SongbookAbbr rides along so the list can group by source
               book below, the same cross-songbook shape writer.php uses
               for the same reason (one theme spans many hymnals). */
            $stmt = $tdb->prepare(
                'SELECT s.SongId AS id, s.Title AS title,
                        s.SongbookAbbr AS songbook, s.Number AS number
                   FROM tblSongTagMap tm
                   JOIN tblSongs s ON s.SongId = tm.SongId
                  WHERE tm.TagId = ?
                  ORDER BY s.SongbookAbbr ASC, s.Number ASC, s.Title ASC'
            );
            $stmt->bind_param('i', $tagInfo['id']);
            $stmt->execute();
            $tagSongs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        }
    } catch (\Throwable $e) {
        /* Covers a pre-#1152 deployment where tblSongTags/tblSongTagMap
           don't exist yet, same defensive posture as iswc.php's tblWorks
           probe — render the friendly "not found" state below rather
           than a 500. */
        error_log('[pages/tag.php] lookup failed: ' . $e->getMessage());
        $tagInfo  = null;
        $tagSongs = [];
    }
}

/* #1637 requirement 4 — handle BOTH the empty slug (/tag/ with nothing
   after it) and the unknown slug (mistyped / renamed / removed theme)
   with one themed card rather than a blank page or a PHP notice. This is
   deliberately the SAME renderErrorFragment 404 pattern songbook.php /
   work.php / person.php use for "record not found" — tblSongTags is a
   real registry table (unlike tune.php's heuristic no-table match), so a
   tag is exactly as "found or not found" as a songbook/work/person is. */
if ($tagInfo === null) {
    http_response_code(404);
    if (function_exists('renderErrorFragment')) {
        echo renderErrorFragment(404, [
            'title'   => 'Theme not found',
            'message' => $tagSlug !== ''
                ? 'No songs for this theme — "' . $tagSlug . '" doesn\'t match a theme in the catalogue. It may have been renamed or removed.'
                : 'No theme was specified.',
            'fa'      => 'fa-tags',
            'actions' => [
                ['label' => 'Go Home', 'href' => '/',       'navigate' => 'home',   'primary' => true, 'fa' => 'fa-house'],
                ['label' => 'Search',  'href' => '/search', 'navigate' => 'search', 'fa' => 'fa-magnifying-glass'],
            ],
        ]);
    } else {
        echo '<div class="alert alert-warning" role="alert">No theme found matching "' . htmlspecialchars($tagSlug) . '".</div>';
    }
    return;
}

/* Group songs by songbook for tidier display — a theme spans many books,
   same established pattern as writer.php's cross-songbook song list. */
$tagSongsByBook = [];
foreach ($tagSongs as $s) {
    $abbr = (string)$s['songbook'];
    if (!isset($tagSongsByBook[$abbr])) {
        $bookRow = $songData->getSongbook($abbr);
        $tagSongsByBook[$abbr] = [
            'name' => $bookRow['name'] ?? $abbr,
            'rows' => [],
        ];
    }
    $tagSongsByBook[$abbr]['rows'][] = $s;
}

$tagTotalSongs = count($tagSongs);

?>

<!-- ================================================================
     TAG PAGE — Songs carrying a given theme
     ================================================================ -->
<section class="page-tag" aria-label="Theme — <?= htmlspecialchars($tagInfo['name']) ?>" data-tag-slug="<?= htmlspecialchars($tagInfo['slug']) ?>">

    <!-- Breadcrumb navigation -->
    <nav aria-label="Breadcrumb" class="mb-3">
        <ol class="breadcrumb">
            <li class="breadcrumb-item">
                <a href="/" data-navigate="home">Home</a>
            </li>
            <li class="breadcrumb-item active" aria-current="page">
                <?= htmlspecialchars($tagInfo['name']) ?>
            </li>
        </ol>
    </nav>

    <!-- Theme header -->
    <div class="card card-song-header mb-4">
        <div class="card-body">
            <h1 class="h4 mb-2">
                <i class="fa-solid fa-tag me-2" aria-hidden="true"></i>
                <?= htmlspecialchars($tagInfo['name']) ?>
            </h1>
            <p class="text-muted mb-0">
                <?= number_format($tagTotalSongs) ?> song<?= $tagTotalSongs === 1 ? '' : 's' ?>
            </p>
            <?php if (!empty($tagInfo['description'])): ?>
                <p class="text-muted small mb-0 mt-1"><?= htmlspecialchars($tagInfo['description']) ?></p>
            <?php endif; ?>
        </div>
    </div>

    <?php if (empty($tagSongsByBook)): ?>
        <!-- A real theme with no songs tagged yet — not an error, just empty
             (mirrors work.php's "This work has no member songs yet."). -->
        <p class="text-muted" role="status">No songs are currently tagged with this theme.</p>
    <?php else: ?>
        <!-- Songs grouped by songbook -->
        <?php foreach ($tagSongsByBook as $abbr => $book): ?>
            <div class="mb-4">
                <h2 class="h6 mb-2 text-muted">
                    <span class="badge bg-body-secondary me-1"><?= htmlspecialchars($abbr) ?></span>
                    <?= htmlspecialchars($book['name']) ?>
                    <small class="text-muted">(<?= count($book['rows']) ?>)</small>
                </h2>
                <div class="list-group song-list" role="list">
                    <?php foreach ($book['rows'] as $song): ?>
                        <a href="/song/<?= htmlspecialchars($song['id']) ?>"
                           class="list-group-item list-group-item-action song-list-item"
                           data-navigate="song"
                           data-song-id="<?= htmlspecialchars($song['id']) ?>"
                           role="listitem"
                           aria-label="<?= (int)$song['number'] > 0 ? 'Song ' . (int)$song['number'] . ': ' : '' ?><?= htmlspecialchars(toTitleCase((string)$song['title'])) ?>">
                            <!-- Song number badge — left empty when the song
                                 has no songbook position; `.song-number-badge:
                                 empty::before` renders a book glyph fallback
                                 (#392), same as songbook.php. -->
                            <span class="song-number-badge" data-songbook="<?= htmlspecialchars($abbr) ?>" aria-hidden="true"><?php
                                if ((int)$song['number'] > 0) {
                                    echo (int)$song['number'];
                                }
                            ?></span>
                            <!-- Song info -->
                            <div class="song-info flex-grow-1">
                                <span class="song-title"><?= htmlspecialchars(toTitleCase((string)$song['title'])) ?></span>
                            </div>
                            <div class="song-indicators">
                                <i class="fa-solid fa-chevron-right text-muted" aria-hidden="true"></i>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

</section>
