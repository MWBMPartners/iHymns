<?php

declare(strict_types=1);

/**
 * iHymns — ISWC public page (#940)
 *
 * PURPOSE:
 * Lists every song in the catalogue that shares an ISWC code (the
 * International Standard Musical Work Code, format T-NNN.NNN.NNN-N).
 * Reached via /iswc/<encoded-code> from the song page.
 *
 * Two songs that share an ISWC are by definition the same composition
 * — different songbook entries, possibly different translations, but
 * the same Work in CISAC's registry. This is the fastest cross-link
 * available because the ISWC is canonical (vs. titles which drift
 * across hymnals).
 *
 * Loaded via api.php?page=iswc&code=T-926.352.268-5.
 */

if (!isset($songData) || !is_object($songData)) {
    require_once __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'SongData.php';
    $songData = new SongData();
}

$iswcCode    = isset($iswcCode) ? (string)$iswcCode : '';
/* Defensive normalisation: keep T, digits, dots, hyphens — anything
   else is an injection attempt or a URL-encoding artefact. */
$iswcCode    = preg_replace('/[^T0-9.\-]/i', '', $iswcCode) ?? '';
$iswcCode    = strtoupper($iswcCode);

$iswcRows         = [];
$iswcTotalSongs   = 0;
$iswcRowsByBook   = [];
$iswcWorkSlug     = '';
$iswcWorkTitle    = '';

if ($iswcCode !== '') {
    try {
        $idb = getDbMysqli();
        $stmt = $idb->prepare(
            "SELECT s.SongId, s.Number, s.Title, s.SongbookAbbr, sb.Name AS SongbookName, s.Language
               FROM tblSongs s
               LEFT JOIN tblSongbooks sb ON sb.Abbreviation = s.SongbookAbbr
              WHERE s.Iswc = ?
              ORDER BY s.SongbookAbbr ASC, s.Number ASC, s.Title ASC"
        );
        $stmt->bind_param('s', $iswcCode);
        $stmt->execute();
        $iswcRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $iswcTotalSongs = count($iswcRows);
        foreach ($iswcRows as $r) {
            $abbr = (string)$r['SongbookAbbr'];
            if (!isset($iswcRowsByBook[$abbr])) {
                $iswcRowsByBook[$abbr] = [
                    'name' => (string)$r['SongbookName'],
                    'rows' => [],
                ];
            }
            $iswcRowsByBook[$abbr]['rows'][] = $r;
        }

        /* If a tblWorks row exists keyed on this ISWC, link to it as
           the canonical Work page — that surface carries richer
           metadata (composers / arrangers / external links / member
           grouping) that this list-view doesn't try to replicate. */
        try {
            $r = $idb->query(
                "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblWorks' LIMIT 1"
            );
            $hasWorks = $r && $r->fetch_row() !== null;
            if ($r) $r->close();
            if ($hasWorks) {
                $stmt = $idb->prepare('SELECT Slug, Title FROM tblWorks WHERE Iswc = ? LIMIT 1');
                $stmt->bind_param('s', $iswcCode);
                $stmt->execute();
                $w = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($w) {
                    $iswcWorkSlug  = (string)$w['Slug'];
                    $iswcWorkTitle = (string)$w['Title'];
                }
            }
        } catch (\Throwable $_e) { /* tblWorks missing — silent skip */ }
    } catch (\Throwable $e) {
        error_log('[pages/iswc.php] lookup failed: ' . $e->getMessage());
    }
}
?>

<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb small">
        <li class="breadcrumb-item"><a href="/" data-navigate="home">Home</a></li>
        <li class="breadcrumb-item active" aria-current="page">
            <i class="fa-solid fa-barcode me-1" aria-hidden="true"></i>
            ISWC: <?= htmlspecialchars($iswcCode !== '' ? $iswcCode : '?') ?>
        </li>
    </ol>
</nav>

<?php if ($iswcCode === ''): ?>
    <div class="alert alert-warning" role="alert">
        <i class="fa-solid fa-circle-exclamation me-2" aria-hidden="true"></i>
        No ISWC specified.
    </div>
    <a href="/" class="btn btn-primary" data-navigate="home">
        <i class="fa-solid fa-arrow-left me-2" aria-hidden="true"></i>Back to Home
    </a>
<?php elseif ($iswcTotalSongs === 0): ?>
    <div class="alert alert-warning" role="alert">
        <i class="fa-solid fa-circle-exclamation me-2" aria-hidden="true"></i>
        No songs in the catalogue carry the ISWC <strong><?= htmlspecialchars($iswcCode) ?></strong>.
    </div>
    <p class="text-muted small">
        ISWC values are populated by curators and bulk imports. Even if a song uses this composition,
        it may not yet have its ISWC tagged.
    </p>
    <a href="/" class="btn btn-primary" data-navigate="home">
        <i class="fa-solid fa-arrow-left me-2" aria-hidden="true"></i>Back to Home
    </a>
<?php else: ?>
    <header class="mb-4">
        <h1 class="h3 d-flex align-items-baseline gap-2">
            <i class="fa-solid fa-barcode text-muted" aria-hidden="true"></i>
            <span><?= htmlspecialchars($iswcCode) ?></span>
            <small class="text-muted ms-1">(<?= $iswcTotalSongs ?> song<?= $iswcTotalSongs === 1 ? '' : 's' ?>)</small>
        </h1>
        <p class="small text-muted mb-2">
            International Standard Musical Work Code — songs sharing this code are by definition the same composition,
            even when the title or songbook differs.
        </p>
        <?php if ($iswcWorkSlug !== ''): ?>
            <p class="small mb-0">
                <i class="fa-solid fa-diagram-project me-1 text-muted" aria-hidden="true"></i>
                Linked Work:
                <a href="/work/<?= htmlspecialchars($iswcWorkSlug) ?>" data-navigate="work">
                    <?= htmlspecialchars($iswcWorkTitle) ?>
                </a>
            </p>
        <?php endif; ?>
    </header>

    <?php foreach ($iswcRowsByBook as $abbr => $book): ?>
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
