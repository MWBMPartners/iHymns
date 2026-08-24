<?php

declare(strict_types=1);

/**
 * iHymns — Browse-by-Theme A–Z index (#1148)
 *
 * PURPOSE:
 * The searchable, scalable home for the FULL theme vocabulary — the follow-on
 * to the home page's Top-8 "Popular themes" strip. Replaces the old unbounded
 * inline "Browse all themes" chip wall (which re-created the exact failure
 * #1148 was filed about, one click deep) with a real A–Z list + a client-side
 * filter + a letter jump bar. Every row links to the shipped per-theme page
 * `/tag/<slug>` (#1637).
 *
 * SHARED-CACHE-SAFE (rules #6 / #30): this fragment is served by
 * api.php?page=themes and is in $_cacheablePages — it reads no auth state, no
 * cookie, no per-user table, only tblSongTags ⋈ tblSongTagMap ⋈ tblSongs
 * (global). It carries NO executable script. All personalisation (filter text,
 * jump position) is client-side, in-memory, post-load — wired by
 * js/modules/themes-page.js from router.js's afterPageLoad(), reading its
 * inputs DOM-first from the data-* this fragment emits. Any future per-viewer
 * divergence (e.g. "pin my themes") MUST live in a client-side apply step, never
 * in these cached bytes.
 *
 * SCOPED (rule #17): one indexed GROUP BY via the ONE count core
 * (includes/theme_index.php) — never a whole-corpus load. The same core powers
 * ?action=popular_tags and the sitemap, so a row's count here can never disagree
 * with the page it links to.
 *
 * @see includes/theme_index.php (the ONE count core)
 * @see includes/pages/tag.php   (the per-theme destination)
 * @see js/modules/themes-page.js (the filter + jump-bar behaviour)
 * @see .claude/browse-by-theme-1148-plan.md §3.2
 */

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'theme_index.php';

$themeRows = [];
try {
    $tdb = getDbMysqli();
    $themeRows = themeIndexCounts($tdb, null, 'name');   /* all themes, A–Z */
} catch (\Throwable $e) {
    /* Pre-#1152 install / transient DB blip — render the empty state, never a
       500 (the #1228 STRICT-white-screen class). */
    error_log('[pages/themes.php] ' . $e->getMessage());
    $themeRows = [];
}

?>

<!-- ================================================================
     THEMES A–Z INDEX (#1148)
     ================================================================ -->
<section class="page-themes" aria-label="Browse songs by theme">

    <!-- Breadcrumb -->
    <nav aria-label="Breadcrumb" class="mb-3">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="/" data-navigate="home">Home</a></li>
            <li class="breadcrumb-item active" aria-current="page">Themes</li>
        </ol>
    </nav>

<?php if (empty($themeRows)): ?>

    <!-- Empty state — an index with nothing in it is NOT a 404 (contrast
         tag.php's record-not-found); HTTP stays 200. -->
    <div class="card card-song-header mb-4">
        <div class="card-body text-center">
            <h1 class="h4 mb-2"><i class="fa-solid fa-tags me-2" aria-hidden="true"></i>Themes</h1>
            <p class="text-muted mb-0" role="status">
                No themes yet — themes appear here as curators tag songs.
            </p>
        </div>
    </div>

<?php else: ?>

    <?php
    /* Bucket by the first letter of Name (Unicode-aware uppercase); anything
       that isn't a plain A–Z letter (digit, punctuation, non-Latin script)
       goes in the '#' bucket, rendered last. Slugs in the emitted URLs are the
       STORED Slug (UNIQUE, URL-safe by the registry contract) — never re-derived
       from Name. */
    $buckets = [];
    foreach ($themeRows as $row) {
        $name  = (string)$row['name'];
        $first = mb_strtoupper(mb_substr($name, 0, 1, 'UTF-8'), 'UTF-8');
        $letter = (strlen($first) === 1 && $first >= 'A' && $first <= 'Z') ? $first : '#';
        $buckets[$letter][] = $row;
    }
    /* A–Z present-only, then '#' last. */
    $orderedLetters = [];
    for ($c = ord('A'); $c <= ord('Z'); $c++) {
        $L = chr($c);
        if (isset($buckets[$L])) { $orderedLetters[] = $L; }
    }
    if (isset($buckets['#'])) { $orderedLetters[] = '#'; }

    $totalThemes = count($themeRows);
    ?>

    <!-- Header -->
    <div class="card card-song-header mb-3">
        <div class="card-body">
            <h1 class="h4 mb-1"><i class="fa-solid fa-tags me-2" aria-hidden="true"></i>Themes</h1>
            <p class="text-muted mb-0"><?= number_format($totalThemes) ?> theme<?= $totalThemes === 1 ? '' : 's' ?></p>
        </div>
    </div>

    <!-- Filter + jump-bar hosts: shipped `hidden`, revealed by themes-page.js.
         A no-JS visitor (and a crawler) gets the complete A–Z list below with
         zero dead controls (progressive enhancement). -->
    <div class="mb-3" id="themes-filter-block" hidden>
        <label for="themes-filter" class="form-label small text-muted">Filter themes</label>
        <input type="search" id="themes-filter" class="form-control" autocomplete="off"
               placeholder="Type to filter…">
        <p id="themes-filter-count" class="small text-muted mt-1 mb-0" role="status"></p>
    </div>
    <div id="themes-jump-bar" class="themes-jump-bar" hidden></div>

    <!-- A–Z sections -->
    <?php foreach ($orderedLetters as $letter): ?>
        <section id="themes-letter-<?= htmlspecialchars($letter === '#' ? 'hash' : $letter) ?>"
                 data-themes-letter="<?= htmlspecialchars($letter) ?>" class="themes-letter-section mb-4">
            <h2 class="h6 text-muted mb-2"><?= htmlspecialchars($letter) ?></h2>
            <div class="list-group">
                <?php foreach ($buckets[$letter] as $row): ?>
                    <?php
                    $name       = (string)$row['name'];
                    $slug       = (string)$row['slug'];
                    $parentName = $row['parentName'] ?? null;   /* null on a pre-#1152 install → no context span */
                    $useCount   = (int)$row['useCount'];
                    /* Fold = lowercased name + parent (for the client filter);
                       the module NFD-folds this + the query on both sides so
                       diacritics match. */
                    $fold = mb_strtolower($name . ($parentName !== null ? ' ' . $parentName : ''), 'UTF-8');
                    ?>
                    <a href="/tag/<?= htmlspecialchars($slug) ?>" data-navigate="tag"
                       class="list-group-item list-group-item-action d-flex align-items-center theme-index-row"
                       data-theme-fold="<?= htmlspecialchars($fold) ?>">
                        <span class="flex-grow-1">
                            <?= htmlspecialchars($name) ?><?php if ($parentName !== null): ?><span class="text-muted small ms-1">· <?= htmlspecialchars($parentName) ?></span><?php endif; ?>
                        </span>
                        <!-- Count rides INSIDE the link → accessible name is
                             "Easter, 42 songs" (same contract as the home chip). -->
                        <span class="badge rounded-pill text-bg-secondary"><?= number_format($useCount) ?><span class="visually-hidden"> songs</span></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>

<?php endif; ?>

</section>
