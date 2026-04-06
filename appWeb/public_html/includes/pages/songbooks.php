<?php

/**
 * iHymns — Songbooks List Page Template
 *
 * PURPOSE:
 * Displays all available songbooks in a grid layout with song counts
 * and quick navigation. Each songbook card links to the song list
 * for that songbook.
 *
 * Loaded via AJAX: api.php?page=songbooks
 */

declare(strict_types=1);

$songbooks = $songData->getSongbooks();
$stats = $songData->getStats();

?>

<!-- ================================================================
     SONGBOOKS PAGE — Browse all songbooks
     ================================================================ -->
<section class="page-songbooks" aria-label="Songbooks">

    <!-- Page header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h4 mb-0">
            <i class="fa-solid fa-book-open me-2" aria-hidden="true"></i>
            Songbooks
        </h1>
        <span class="badge bg-primary bg-gradient rounded-pill">
            <?= number_format($stats['totalSongs']) ?> songs total
        </span>
    </div>

    <!-- Songbook Grid -->
    <div class="row g-3">
        <?php foreach ($songbooks as $index => $book): ?>
            <?php if (($book['songCount'] ?? 0) > 0): ?>
                <div class="col-12 col-sm-6 col-md-4 col-lg-3">
                    <a href="/songbook/<?= htmlspecialchars($book['id']) ?>"
                       class="card card-songbook h-100 text-decoration-none"
                       data-navigate="songbook"
                       data-songbook-id="<?= htmlspecialchars($book['id']) ?>"
                       aria-label="Open <?= htmlspecialchars($book['name']) ?>">
                        <div class="card-body">
                            <div class="d-flex align-items-start gap-3">
                                <!-- Songbook icon -->
                                <div class="songbook-icon songbook-icon-<?= htmlspecialchars($book['id']) ?> flex-shrink-0">
                                    <i class="fa-solid fa-book" aria-hidden="true"></i>
                                </div>
                                <div class="flex-grow-1">
                                    <h2 class="h6 card-title mb-1">
                                        <?= htmlspecialchars($book['name']) ?>
                                    </h2>
                                    <span class="badge bg-body-secondary me-1">
                                        <?= htmlspecialchars($book['id']) ?>
                                    </span>
                                    <p class="text-muted small mb-0 mt-1">
                                        <?= number_format($book['songCount']) ?> songs
                                    </p>
                                </div>
                                <!-- Arrow indicator -->
                                <i class="fa-solid fa-chevron-right text-muted mt-1" aria-hidden="true"></i>
                            </div>
                        </div>
                    </a>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>

</section>
