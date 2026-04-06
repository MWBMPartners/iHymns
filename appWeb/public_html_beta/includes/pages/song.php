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

/* Extract metadata for convenience */
$songNumber  = (int)$song['number'];
$songTitle   = $song['title'] ?? 'Untitled';
$songbook    = $song['songbook'] ?? '';
$bookName    = $song['songbookName'] ?? '';
$writers     = $song['writers'] ?? [];
$composers   = $song['composers'] ?? [];
$copyright   = $song['copyright'] ?? '';
$ccli        = $song['ccli'] ?? '';
$hasAudio    = !empty($song['hasAudio']);
$hasSheet    = !empty($song['hasSheetMusic']);
$components  = $song['components'] ?? [];

?>

<!-- ================================================================
     SONG PAGE — Full lyrics and metadata
     ================================================================ -->
<article class="page-song" aria-label="<?= htmlspecialchars($songTitle) ?>" data-song-id="<?= htmlspecialchars($song['id']) ?>" data-songbook="<?= htmlspecialchars($songbook) ?>" data-song-number="<?= (int)$songNumber ?>"<?php if (!empty($song['capo'])): ?> data-capo="<?= (int)$song['capo'] ?>"<?php endif; ?><?php if (!empty($song['key'])): ?> data-key="<?= htmlspecialchars($song['key']) ?>"<?php endif; ?>>

    <!-- Breadcrumb navigation -->
    <nav aria-label="Breadcrumb" class="mb-3">
        <ol class="breadcrumb">
            <li class="breadcrumb-item">
                <a href="/songbooks" data-navigate="songbooks">Songbooks</a>
            </li>
            <li class="breadcrumb-item">
                <a href="/songbook/<?= htmlspecialchars($songbook) ?>"
                   data-navigate="songbook"
                   data-songbook-id="<?= htmlspecialchars($songbook) ?>">
                    <?= htmlspecialchars($bookName) ?>
                </a>
            </li>
            <li class="breadcrumb-item active" aria-current="page">
                #<?= $songNumber ?>
            </li>
        </ol>
    </nav>

    <!-- Song header card -->
    <div class="card card-song-header mb-4">
        <div class="card-body">
            <!-- Song number and title -->
            <div class="d-flex align-items-start gap-3 mb-3">
                <span class="song-number-badge-lg" data-songbook="<?= htmlspecialchars($songbook) ?>" aria-label="Song number <?= $songNumber ?>">
                    <?= $songNumber ?>
                </span>
                <div class="flex-grow-1">
                    <h1 class="h4 mb-1"><?= htmlspecialchars($songTitle) ?></h1>
                    <p class="text-muted mb-0">
                        <span class="badge bg-body-secondary"><?= htmlspecialchars($songbook) ?></span>
                        <?= htmlspecialchars($bookName) ?>
                    </p>
                </div>
            </div>

            <!-- Writers and composers -->
            <?php if (!empty($writers) || !empty($composers)): ?>
                <div class="song-meta mb-3">
                    <?php if (!empty($writers)): ?>
                        <p class="mb-1">
                            <i class="fa-solid fa-pen-fancy me-2 text-muted" aria-hidden="true"></i>
                            <strong>Words:</strong>
                            <?= htmlspecialchars(implode(', ', $writers)) ?>
                        </p>
                    <?php endif; ?>
                    <?php if (!empty($composers)): ?>
                        <p class="mb-0">
                            <i class="fa-solid fa-music me-2 text-muted" aria-hidden="true"></i>
                            <strong>Music:</strong>
                            <?= htmlspecialchars(implode(', ', $composers)) ?>
                        </p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- Action buttons row -->
            <div class="d-flex flex-wrap gap-2">
                <!-- Favourite toggle -->
                <button type="button"
                        class="btn btn-outline-danger btn-sm btn-favourite"
                        data-song-id="<?= htmlspecialchars($song['id']) ?>"
                        data-song-title="<?= htmlspecialchars($songTitle) ?>"
                        aria-label="Add to favourites"
                        aria-pressed="false">
                    <i class="fa-regular fa-heart me-1" aria-hidden="true"></i>
                    <span>Favourite</span>
                </button>

                <!-- Share button -->
                <button type="button"
                        class="btn btn-outline-primary btn-sm btn-share"
                        data-song-id="<?= htmlspecialchars($song['id']) ?>"
                        data-song-title="<?= htmlspecialchars($songTitle) ?>"
                        aria-label="Share this song">
                    <i class="fa-solid fa-share-nodes me-1" aria-hidden="true"></i>
                    Share
                </button>

                <!-- Audio button (if available) -->
                <?php if ($hasAudio): ?>
                    <button type="button"
                            class="btn btn-outline-secondary btn-sm btn-audio"
                            data-song-id="<?= htmlspecialchars($song['id']) ?>"
                            aria-label="Play audio">
                        <i class="fa-solid fa-headphones me-1" aria-hidden="true"></i>
                        Audio
                    </button>
                <?php endif; ?>

                <!-- Sheet music button (if available) -->
                <?php if ($hasSheet): ?>
                    <button type="button"
                            class="btn btn-outline-secondary btn-sm btn-sheet-music"
                            data-song-id="<?= htmlspecialchars($song['id']) ?>"
                            aria-label="View sheet music">
                        <i class="fa-solid fa-file-pdf me-1" aria-hidden="true"></i>
                        Sheet Music
                    </button>
                <?php endif; ?>

                <!-- Add to set list (#94) -->
                <button type="button"
                        class="btn btn-outline-secondary btn-sm btn-add-to-setlist"
                        aria-label="Add to set list">
                    <i class="fa-solid fa-list-ol me-1" aria-hidden="true"></i>
                    Set List
                </button>

                <!-- Compare with another song (#102) -->
                <button type="button"
                        class="btn btn-outline-secondary btn-sm btn-compare"
                        aria-label="Compare with another song">
                    <i class="fa-solid fa-columns me-1" aria-hidden="true"></i>
                    Compare
                </button>

                <!-- Save offline button -->
                <button type="button"
                        class="btn btn-outline-secondary btn-sm btn-save-offline"
                        data-song-id="<?= htmlspecialchars($song['id']) ?>"
                        aria-label="Save this song for offline use">
                    <i class="fa-solid fa-download me-1" aria-hidden="true"></i>
                    <span>Save Offline</span>
                </button>

                <!-- Print button -->
                <button type="button"
                        class="btn btn-outline-secondary btn-sm btn-print"
                        aria-label="Print this song"
                        onclick="window.print()">
                    <i class="fa-solid fa-print me-1" aria-hidden="true"></i>
                    Print
                </button>
            </div>
        </div>
    </div>

    <!-- Song lyrics -->
    <div class="song-lyrics" role="region" aria-label="Song lyrics">
        <?php foreach ($components as $component): ?>
            <?php
                $type   = $component['type'] ?? 'verse';
                $number = $component['number'] ?? null;
                $lines  = $component['lines'] ?? [];

                /* Build a human-readable label for the component */
                $label = ucfirst($type);
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
                    CCLI: <?= htmlspecialchars($ccli) ?>
                </p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

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
                   class="btn btn-outline-secondary btn-sm"
                   data-navigate="song"
                   data-song-id="<?= htmlspecialchars($prevSong['id']) ?>"
                   aria-label="Previous song: <?= htmlspecialchars($prevSong['title']) ?>">
                    <i class="fa-solid fa-chevron-left me-1" aria-hidden="true"></i>
                    #<?= (int)$prevSong['number'] ?>
                </a>
            <?php else: ?>
                <span></span>
            <?php endif; ?>

            <?php if ($nextSong): ?>
                <a href="/song/<?= htmlspecialchars($nextSong['id']) ?>"
                   class="btn btn-outline-secondary btn-sm"
                   data-navigate="song"
                   data-song-id="<?= htmlspecialchars($nextSong['id']) ?>"
                   aria-label="Next song: <?= htmlspecialchars($nextSong['title']) ?>">
                    #<?= (int)$nextSong['number'] ?>
                    <i class="fa-solid fa-chevron-right ms-1" aria-hidden="true"></i>
                </a>
            <?php endif; ?>
        </div>
    </nav>

</article>
