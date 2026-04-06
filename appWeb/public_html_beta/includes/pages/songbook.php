<?php

/**
 * iHymns — Single Songbook Page Template
 *
 * PURPOSE:
 * Displays the list of songs within a specific songbook.
 * Shows the songbook name, song count, and a scrollable list
 * of songs with number, title, and quick-action buttons.
 *
 * Loaded via AJAX: api.php?page=songbook&id=CP
 *
 * Expects $bookId to be set by api.php before inclusion.
 */

declare(strict_types=1);

/* Fetch songbook and its songs */
$book = $songData->getSongbook($bookId);
$songs = $songData->getSongs($bookId);

/* Handle invalid songbook ID */
if ($book === null) {
    http_response_code(404);
    echo '<div class="alert alert-warning" role="alert">';
    echo '<i class="fa-solid fa-circle-exclamation me-2" aria-hidden="true"></i>';
    echo 'Songbook not found: <strong>' . htmlspecialchars($bookId) . '</strong>';
    echo '</div>';
    echo '<a href="/songbooks" class="btn btn-primary" data-navigate="songbooks">';
    echo '<i class="fa-solid fa-arrow-left me-2" aria-hidden="true"></i>Back to Songbooks</a>';
    return;
}

?>

<!-- ================================================================
     SONGBOOK PAGE — Song list for a specific songbook
     ================================================================ -->
<section class="page-songbook" aria-label="<?= htmlspecialchars($book['name']) ?>">

    <!-- Breadcrumb navigation -->
    <nav aria-label="Breadcrumb" class="mb-3">
        <ol class="breadcrumb">
            <li class="breadcrumb-item">
                <a href="/songbooks" data-navigate="songbooks">Songbooks</a>
            </li>
            <li class="breadcrumb-item active" aria-current="page">
                <?= htmlspecialchars($book['name']) ?>
            </li>
        </ol>
    </nav>

    <!-- Songbook header -->
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div>
            <h1 class="h4 mb-1">
                <i class="fa-solid fa-book me-2" aria-hidden="true"></i>
                <?= htmlspecialchars($book['name']) ?>
                <span class="badge bg-body-secondary ms-1"><?= htmlspecialchars($book['id']) ?></span>
            </h1>
            <p class="text-muted mb-0"><?= number_format($book['songCount']) ?> songs</p>
        </div>
        <div class="d-flex gap-2">
            <!-- Number pad search for this songbook -->
            <button type="button"
                    class="btn btn-outline-primary btn-sm"
                    data-action="open-numpad"
                    data-numpad-book="<?= htmlspecialchars($book['id']) ?>"
                    aria-label="Search by number in <?= htmlspecialchars($book['name']) ?>">
                <i class="fa-solid fa-hashtag me-1" aria-hidden="true"></i>
                By Number
            </button>
            <!-- Shuffle within this songbook -->
            <button type="button"
                    class="btn btn-outline-secondary btn-sm"
                    data-action="shuffle-book"
                    data-shuffle-book="<?= htmlspecialchars($book['id']) ?>"
                    aria-label="Random song from <?= htmlspecialchars($book['name']) ?>">
                <i class="fa-solid fa-shuffle me-1" aria-hidden="true"></i>
                Shuffle
            </button>
        </div>
    </div>

    <!-- Song list -->
    <div class="list-group song-list" role="list">
        <?php foreach ($songs as $song): ?>
            <a href="/song/<?= htmlspecialchars($song['id']) ?>"
               class="list-group-item list-group-item-action song-list-item"
               data-navigate="song"
               data-song-id="<?= htmlspecialchars($song['id']) ?>"
               role="listitem"
               aria-label="Song <?= (int)$song['number'] ?>: <?= htmlspecialchars($song['title']) ?>">
                <!-- Song number badge -->
                <span class="song-number-badge" data-songbook="<?= htmlspecialchars($bookId) ?>" aria-hidden="true">
                    <?= (int)$song['number'] ?>
                </span>
                <!-- Song info -->
                <div class="song-info flex-grow-1">
                    <span class="song-title"><?= htmlspecialchars($song['title']) ?></span>
                    <?php if (!empty($song['writers'])): ?>
                        <small class="song-writers text-muted d-block">
                            <?= htmlspecialchars(implode(', ', $song['writers'])) ?>
                        </small>
                    <?php endif; ?>
                </div>
                <!-- Indicators -->
                <div class="song-indicators">
                    <?php if (!empty($song['hasAudio'])): ?>
                        <i class="fa-solid fa-headphones text-muted" aria-label="Has audio" title="Audio available"></i>
                    <?php endif; ?>
                    <?php if (!empty($song['hasSheetMusic'])): ?>
                        <i class="fa-solid fa-file-pdf text-muted" aria-label="Has sheet music" title="Sheet music available"></i>
                    <?php endif; ?>
                    <i class="fa-solid fa-chevron-right text-muted" aria-hidden="true"></i>
                </div>
            </a>
        <?php endforeach; ?>
    </div>

</section>
