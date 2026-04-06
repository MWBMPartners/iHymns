<?php

/**
 * iHymns — Writer/Composer Page Template
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Displays all songs associated with a particular writer or composer.
 * Shows the person's name as heading and lists matching songs grouped
 * by songbook.
 *
 * Loaded via AJAX: api.php?page=writer&id=john-newton
 *
 * Expects $writerId (URL slug) to be set by api.php before inclusion.
 */

declare(strict_types=1);

/* Convert slug back to a displayable name */
$writerSlug = urldecode($writerId);
$writerName = mb_convert_case(str_replace('-', ' ', $writerSlug), MB_CASE_TITLE, 'UTF-8');

/* Find all songs where this person is a writer or composer (case-insensitive) */
$allSongs    = $songData->getSongs();
$matchedSongs = [];

foreach ($allSongs as $song) {
    $isWriter   = false;
    $isComposer = false;

    foreach ($song['writers'] ?? [] as $w) {
        if (mb_strtolower($w) === mb_strtolower($writerName)) {
            $isWriter = true;
            break;
        }
    }
    foreach ($song['composers'] ?? [] as $c) {
        if (mb_strtolower($c) === mb_strtolower($writerName)) {
            $isComposer = true;
            break;
        }
    }

    if ($isWriter || $isComposer) {
        $matchedSongs[] = [
            'song'       => $song,
            'isWriter'   => $isWriter,
            'isComposer' => $isComposer,
        ];
    }
}

/* Handle no results */
if (empty($matchedSongs)) {
    http_response_code(404);
    echo '<div class="alert alert-warning" role="alert">';
    echo '<i class="fa-solid fa-circle-exclamation me-2" aria-hidden="true"></i>';
    echo 'No songs found for: <strong>' . htmlspecialchars($writerName) . '</strong>';
    echo '</div>';
    echo '<a href="/songbooks" class="btn btn-primary" data-navigate="songbooks">';
    echo '<i class="fa-solid fa-arrow-left me-2" aria-hidden="true"></i>Back to Songbooks</a>';
    return;
}

/* Group by songbook */
$grouped = [];
foreach ($matchedSongs as $match) {
    $bookId = $match['song']['songbook'] ?? 'Unknown';
    $grouped[$bookId][] = $match;
}

/* Sort songs within each group by number */
foreach ($grouped as &$group) {
    usort($group, function ($a, $b) {
        return ((int)($a['song']['number'] ?? 0)) - ((int)($b['song']['number'] ?? 0));
    });
}
unset($group);

/* Count totals */
$totalSongs = count($matchedSongs);
$writerCount   = count(array_filter($matchedSongs, fn($m) => $m['isWriter']));
$composerCount = count(array_filter($matchedSongs, fn($m) => $m['isComposer']));

?>

<!-- ================================================================
     WRITER PAGE — Songs by a specific writer/composer
     ================================================================ -->
<section class="page-writer" aria-label="Songs by <?= htmlspecialchars($writerName) ?>">

    <!-- Breadcrumb navigation -->
    <nav aria-label="Breadcrumb" class="mb-3">
        <ol class="breadcrumb">
            <li class="breadcrumb-item">
                <a href="/songbooks" data-navigate="songbooks">Songbooks</a>
            </li>
            <li class="breadcrumb-item active" aria-current="page">
                <?= htmlspecialchars($writerName) ?>
            </li>
        </ol>
    </nav>

    <!-- Writer header -->
    <div class="card card-song-header mb-4">
        <div class="card-body">
            <h1 class="h4 mb-2">
                <i class="fa-solid fa-user-pen me-2" aria-hidden="true"></i>
                <?= htmlspecialchars($writerName) ?>
            </h1>
            <p class="text-muted mb-0">
                <?= $totalSongs ?> song<?= $totalSongs !== 1 ? 's' : '' ?>
                <?php if ($writerCount > 0 && $composerCount > 0): ?>
                    — <?= $writerCount ?> as writer, <?= $composerCount ?> as composer
                <?php elseif ($writerCount > 0): ?>
                    — writer
                <?php else: ?>
                    — composer
                <?php endif; ?>
            </p>
        </div>
    </div>

    <!-- Songs grouped by songbook -->
    <?php foreach ($grouped as $bookId => $matches): ?>
        <?php
            $book = $songData->getSongbook($bookId);
            $bookName = $book['name'] ?? $bookId;
        ?>
        <div class="mb-4">
            <h2 class="h6 mb-2 text-muted">
                <span class="badge bg-body-secondary me-1"><?= htmlspecialchars($bookId) ?></span>
                <?= htmlspecialchars($bookName) ?>
                <small class="text-muted">(<?= count($matches) ?>)</small>
            </h2>
            <div class="list-group song-list" role="list">
                <?php foreach ($matches as $match): ?>
                    <?php $song = $match['song']; ?>
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
                            <small class="text-muted d-block">
                                <?php if ($match['isWriter'] && $match['isComposer']): ?>
                                    <i class="fa-solid fa-pen-fancy me-1" aria-hidden="true"></i>Words &amp; Music
                                <?php elseif ($match['isWriter']): ?>
                                    <i class="fa-solid fa-pen-fancy me-1" aria-hidden="true"></i>Words
                                <?php else: ?>
                                    <i class="fa-solid fa-music me-1" aria-hidden="true"></i>Music
                                <?php endif; ?>
                            </small>
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
        </div>
    <?php endforeach; ?>

</section>
