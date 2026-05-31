<?php

declare(strict_types=1);

/**
 * iHymns — Tune public page (#940)
 *
 * PURPOSE:
 * Lists every song that uses a given hymn tune (TuneName). Reached via
 * /tune/<slug> from the song page (`Tune: HYFRYDOL` → click).
 *
 * The slug is the lowercase + hyphen-separated form of the tune name;
 * we recover the canonical TuneName by walking tblSongs and matching
 * any row whose normalised TuneName slug equals the URL slug. This
 * tolerates capitalisation / punctuation drift across imported corpora
 * without a separate tblTunes registry.
 *
 * Pre-tune-registry (today): heuristic match on tblSongs.TuneName.
 * If a future tblTunes registry ships, this page becomes a single
 * SELECT keyed on slug.
 *
 * Loaded via api.php?page=tune&slug=hyfrydol.
 */

if (!isset($songData) || !is_object($songData)) {
    require_once __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'SongData.php';
    $songData = new SongData();
}

$tuneSlug = isset($tuneSlug) ? (string)$tuneSlug : '';
$tuneSlug = strtolower(trim(preg_replace('/[^A-Za-z0-9\-]+/', '-', $tuneSlug), '-'));

$tuneRows         = [];
$canonicalTune    = '';
$tuneRowsByBook   = [];
$tuneTotalSongs   = 0;

if ($tuneSlug !== '') {
    try {
        $tdb = getDbMysqli();
        /* SUBSTRING_INDEX-style normalisation in SQL would let MySQL
           pre-filter, but the slug rule (lowercase + non-alnum → '-' +
           collapse + trim) doesn't have a clean MySQL equivalent that
           handles every Unicode edge case. Pull the distinct TuneName
           list (it's small — a few thousand rows max) and match in PHP. */
        $stmt = $tdb->prepare(
            "SELECT DISTINCT TuneName
               FROM tblSongs
              WHERE TuneName IS NOT NULL AND TuneName <> ''"
        );
        $stmt->execute();
        $allTunes = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'TuneName');
        $stmt->close();

        foreach ($allTunes as $name) {
            $candidate = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', (string)$name), '-'));
            if ($candidate === $tuneSlug) {
                $canonicalTune = (string)$name;
                break;
            }
        }

        if ($canonicalTune !== '') {
            $stmt = $tdb->prepare(
                "SELECT s.SongId, s.Number, s.Title, s.SongbookAbbr, s.SongbookName, s.Language
                   FROM tblSongs s
                  WHERE s.TuneName = ?
                  ORDER BY s.SongbookAbbr ASC, s.Number ASC, s.Title ASC"
            );
            $stmt->bind_param('s', $canonicalTune);
            $stmt->execute();
            $tuneRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            $tuneTotalSongs = count($tuneRows);
            foreach ($tuneRows as $r) {
                $abbr = (string)$r['SongbookAbbr'];
                if (!isset($tuneRowsByBook[$abbr])) {
                    $tuneRowsByBook[$abbr] = [
                        'name'  => (string)$r['SongbookName'],
                        'rows'  => [],
                    ];
                }
                $tuneRowsByBook[$abbr]['rows'][] = $r;
            }
        }
    } catch (\Throwable $e) {
        error_log('[pages/tune.php] lookup failed: ' . $e->getMessage());
    }
}
?>

<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb small">
        <li class="breadcrumb-item"><a href="/" data-navigate="home">Home</a></li>
        <li class="breadcrumb-item active" aria-current="page">
            <i class="fa-solid fa-music me-1" aria-hidden="true"></i>
            Tune: <?= htmlspecialchars($canonicalTune !== '' ? $canonicalTune : $tuneSlug) ?>
        </li>
    </ol>
</nav>

<?php if ($tuneSlug === ''): ?>
    <div class="alert alert-warning" role="alert">
        <i class="fa-solid fa-circle-exclamation me-2" aria-hidden="true"></i>
        No tune specified.
    </div>
    <a href="/" class="btn btn-primary" data-navigate="home">
        <i class="fa-solid fa-arrow-left me-2" aria-hidden="true"></i>Back to Home
    </a>
<?php elseif ($canonicalTune === ''): ?>
    <div class="alert alert-warning" role="alert">
        <i class="fa-solid fa-circle-exclamation me-2" aria-hidden="true"></i>
        No tune named <strong><?= htmlspecialchars($tuneSlug) ?></strong> in the catalogue.
    </div>
    <p class="text-muted small">
        Tune names are imported from songbook metadata; if you expected this tune to be present, it may not have been catalogued under that exact spelling.
    </p>
    <a href="/" class="btn btn-primary" data-navigate="home">
        <i class="fa-solid fa-arrow-left me-2" aria-hidden="true"></i>Back to Home
    </a>
<?php else: ?>
    <header class="mb-4">
        <h1 class="h3 d-flex align-items-baseline gap-2">
            <i class="fa-solid fa-music text-muted" aria-hidden="true"></i>
            <span><?= htmlspecialchars($canonicalTune) ?></span>
            <small class="text-muted ms-1">(<?= $tuneTotalSongs ?> song<?= $tuneTotalSongs === 1 ? '' : 's' ?>)</small>
        </h1>
        <p class="small text-muted mb-0">
            Hymns and worship songs sung to the tune <strong><?= htmlspecialchars($canonicalTune) ?></strong> across the iHymns catalogue.
            Worship leaders can mix-and-match lyrics across hymns that share a melody.
        </p>
    </header>

    <?php foreach ($tuneRowsByBook as $abbr => $book): ?>
        <section class="mb-4">
            <h2 class="h6 text-muted">
                <span class="badge bg-body-secondary text-body-emphasis me-2"><?= htmlspecialchars($abbr) ?></span>
                <?= htmlspecialchars($book['name']) ?>
            </h2>
            <div class="list-group list-group-flush">
                <?php foreach ($book['rows'] as $r): ?>
                    <a class="list-group-item list-group-item-action song-list-item"
                       href="/song/<?= htmlspecialchars($r['SongId']) ?>"
                       data-navigate="song"
                       data-song-id="<?= htmlspecialchars($r['SongId']) ?>">
                        <span class="song-number-badge"><?= (int)$r['Number'] ?: '?' ?></span>
                        <div class="song-info flex-grow-1">
                            <span class="song-title"><?= htmlspecialchars($r['Title']) ?></span>
                            <?php if (!empty($r['Language'])): ?>
                                <small class="text-muted ms-2">
                                    <i class="fa-solid fa-language me-1" aria-hidden="true"></i><?= htmlspecialchars($r['Language']) ?>
                                </small>
                            <?php endif; ?>
                        </div>
                        <i class="fa-solid fa-chevron-right text-muted" aria-hidden="true"></i>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>
<?php endif; ?>
