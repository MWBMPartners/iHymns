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

    <!-- Breadcrumb navigation with schema.org markup (#151) -->
    <nav aria-label="Breadcrumb" class="mb-3">
        <ol class="breadcrumb" itemscope itemtype="https://schema.org/BreadcrumbList">
            <li class="breadcrumb-item" itemprop="itemListElement" itemscope itemtype="https://schema.org/ListItem">
                <a href="/songbooks" data-navigate="songbooks" itemprop="item">
                    <span itemprop="name">Songbooks</span>
                </a>
                <meta itemprop="position" content="1">
            </li>
            <li class="breadcrumb-item active" aria-current="page" itemprop="itemListElement" itemscope itemtype="https://schema.org/ListItem">
                <span itemprop="name"><?= htmlspecialchars($book['name']) ?></span>
                <meta itemprop="position" content="2">
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
            <?php
                /* #831 — "Compiled by …" line. Each compiler links to
                   their /people/<slug> page when one exists; falls back
                   to a plain name span otherwise. Multiple compilers
                   joined with " · " for visual lightness. Hidden when
                   the songbook has no compilers attached (or on
                   pre-migration deployments where SongData returns an
                   empty list). */
                $compilers = $book['compilers'] ?? [];
                if (!empty($compilers)):
            ?>
                <p class="text-muted small mb-0 mt-1">
                    <i class="fa-solid fa-pen-nib me-1" aria-hidden="true"></i>
                    Compiled by
                    <?php foreach ($compilers as $i => $c): ?>
                        <?php if ($i > 0): ?> &middot; <?php endif; ?>
                        <?php if (!empty($c['slug'])): ?>
                            <a href="/people/<?= rawurlencode($c['slug']) ?>"
                               data-navigate="person"
                               class="text-reset text-decoration-underline"><?= htmlspecialchars($c['name']) ?></a>
                        <?php else: ?>
                            <span><?= htmlspecialchars($c['name']) ?></span>
                        <?php endif; ?>
                        <?php if (!empty($c['note'])): ?>
                            <span class="text-muted">(<?= htmlspecialchars($c['note']) ?>)</span>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </p>
            <?php endif; ?>
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
               aria-label="Song <?= (int)$song['number'] ?>: <?= htmlspecialchars(toTitleCase($song['title'])) ?>">
                <!-- Song number badge -->
                <span class="song-number-badge" data-songbook="<?= htmlspecialchars($bookId) ?>" aria-hidden="true">
                    <?= (int)$song['number'] ?>
                </span>
                <!-- Song info -->
                <div class="song-info flex-grow-1">
                    <span class="song-title"><?= htmlspecialchars(toTitleCase($song['title'])) ?><?php if (!empty($song['verified'])): ?><span class="verified-badge" title="Verified lyrics" aria-label="Verified lyrics"><svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="10" fill="currentColor" opacity="0.15"/><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="1.5" fill="none"/><path d="M7.5 12.5L10.5 15.5L16.5 9" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></span><?php endif; ?></span>
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
