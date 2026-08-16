<?php

declare(strict_types=1);

/**
 * iHymns — Publisher Public Page (#93 / epic #1765)
 * ================================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * The public page for one publisher — /publisher/<slug>. Shows who they are
 * (company / person / imprint), their parent publisher, city, industry IDs and
 * aliases, and lists every songbook they published. Linked from the songbook
 * editor's publisher picker, the /manage/publishers admin list, and (once the
 * registry is populated) the songbook page itself.
 *
 * DETAILED / RESOLUTION LADDER + rule #33
 * ----------------------------------------------------------------------------
 * A URL another page emits is a contract — honour it (rule #33). The admin list
 * and the editor picker emit /publisher/<slug> using tblPublishers.Slug, so the
 * primary rung is an exact Slug match. Two fallbacks catch a link built from a
 * name before the slug was known, or an old slug the publisher was renamed away
 * from: (b) a PHP name-fold over every tblPublishers.Name, and (c) the same
 * fold over tblPublisherAliases (a renamed publisher's old name becomes an
 * alias on save, so old links keep resolving). Each rung is try/catch-wrapped
 * and falls through on miss.
 *
 * Loaded via api.php?page=publisher&slug=…. UNCACHED (mirrors the tune/iswc
 * precedent — a cheap indexed anonymous read). Pure static markup — NO
 * EXECUTABLE INLINE <script> (rule #30); the only <script> is an inert
 * application/ld+json block (the rule's documented exemption). Existence-gated
 * on tblPublishers (migrations are web-run, rule #9) → a friendly empty state
 * when the registry hasn't been created yet.
 *
 * @link appWeb/public_html/includes/pages/tune.php     the sibling this mirrors
 * @link appWeb/public_html/includes/publisher_helpers.php  IHYMNS_PUBLISHER_KINDS/_ROLES, slug fold
 * @link appWeb/.sql/schema.sql                          tblPublishers family (#93)
 * @see #93
 */

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'db_mysql.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'songbook_visibility.php';   /* songbookVisibleSql() */
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'sort_helpers.php';   /* #1786 — ihymns_title_sort_key() */
if (!defined('IHYMNS_PUBLISHER_KINDS')) {
    require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'publisher_helpers.php';
}

/* #1860 Phase 4 — dual-addressing pre-step, rung 0 of the ladder, BEFORE
   the lowercasing fold immediately below (run ilidParse() on the RAW
   $publisherSlug — the fold is irrelevant to an IL id's shape, but running
   before it keeps this rung's contract independent of whatever that fold
   does next, matching rule #37's exact->name-fold->alias-fold ordering
   with the IL match as the earliest, most specific rung). An IL internal
   id ('ILP…') resolves to the registry's real Slug, which then flows into
   $pubSlug below exactly as if the curator had typed it. A miss (not an IL
   id, the column doesn't exist yet, or no row carries it) leaves
   $publisherSlug UNCHANGED — the fold + ladder still get their turn.
   try/catch-swallowed + column-probe-gated so this page can never
   white-screen on an un-migrated install (the #1228 lesson). */
try {
    if (!function_exists('ilidParse')) {
        require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'ilyrics_id.php';
    }
    $_ilParsed = ilidParse((string)($publisherSlug ?? ''));
    if ($_ilParsed !== null && $_ilParsed['entityType'] === 'publisher') {
        $_ilDb = getDbMysqli();
        $_ilColProbe = $_ilDb->query(
            "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblPublishers' AND COLUMN_NAME = 'IlId' LIMIT 1"
        );
        $_ilColExists = $_ilColProbe && $_ilColProbe->fetch_row() !== null;
        if ($_ilColProbe) { $_ilColProbe->free(); }
        if ($_ilColExists) {
            $_ilStmt = $_ilDb->prepare('SELECT Slug FROM tblPublishers WHERE IlId = ? LIMIT 1');
            $_ilStmt->bind_param('s', $_ilParsed['canonical']);
            $_ilStmt->execute();
            $_ilRow = $_ilStmt->get_result()->fetch_assoc();
            $_ilStmt->close();
            if ($_ilRow !== null && (string)($_ilRow['Slug'] ?? '') !== '') {
                $publisherSlug = (string)$_ilRow['Slug'];
            }
        }
    }
} catch (\Throwable $_ilE) {
    // dormant-by-design — fall through to the fold + ladder unchanged
}

/* Same fold the admin slug + song.php-style link builders use, so a
   name-derived URL resolves (rule #33). */
$pubSlug = isset($publisherSlug) ? (string)$publisherSlug : '';
$pubSlug = strtolower(trim(preg_replace('/[^A-Za-z0-9\-]+/', '-', $pubSlug), '-'));

/** Local one-shot INFORMATION_SCHEMA table probe (the tune.php idiom). */
if (!function_exists('_publisherTableExists')) {
    function _publisherTableExists(\mysqli $db, string $table): bool
    {
        try {
            $probe = $db->prepare(
                'SELECT 1 FROM INFORMATION_SCHEMA.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
            );
            $probe->bind_param('s', $table);
            $probe->execute();
            $exists = $probe->get_result()->fetch_row() !== null;
            $probe->close();
            return $exists;
        } catch (\Throwable $e) {
            return false;
        }
    }
}

$publisher       = null;   /* tblPublishers row */
$publisherAliases = [];
$publisherBooks  = [];
$parentPublisher = null;   /* [id, name, slug] of the parent, if any */

if ($pubSlug !== '') {
    $pdb = getDbMysqli();
    $hasRegistry = _publisherTableExists($pdb, 'tblPublishers');

    if ($hasRegistry) {
        $rowSelect = 'Id, Name, Slug, Kind, Subtitle, Disambiguation, Ipi, Isni, CityName, ParentId, Notes';

        /* (a) exact slug */
        try {
            $stmt = $pdb->prepare("SELECT {$rowSelect} FROM tblPublishers WHERE Slug = ? AND IsActive = 1 LIMIT 1");
            $stmt->bind_param('s', $pubSlug);
            $stmt->execute();
            $publisher = $stmt->get_result()->fetch_assoc() ?: null;
            $stmt->close();
        } catch (\Throwable $e) {
            error_log('[pages/publisher.php] slug lookup: ' . $e->getMessage());
        }

        /* (b) PHP name-fold over every Name */
        if ($publisher === null) {
            try {
                $stmt = $pdb->prepare('SELECT Id, Name FROM tblPublishers WHERE IsActive = 1');
                $stmt->execute();
                $all = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();
                $matchId = null;
                foreach ($all as $r) {
                    $cand = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', (string)$r['Name']), '-'));
                    if ($cand === $pubSlug) { $matchId = (int)$r['Id']; break; }
                }
                if ($matchId !== null) {
                    $stmt = $pdb->prepare("SELECT {$rowSelect} FROM tblPublishers WHERE Id = ? LIMIT 1");
                    $stmt->bind_param('i', $matchId);
                    $stmt->execute();
                    $publisher = $stmt->get_result()->fetch_assoc() ?: null;
                    $stmt->close();
                }
            } catch (\Throwable $e) {
                error_log('[pages/publisher.php] name-fold lookup: ' . $e->getMessage());
            }
        }

        /* (c) alias fold — a renamed publisher's old name is an alias */
        if ($publisher === null && _publisherTableExists($pdb, 'tblPublisherAliases')) {
            try {
                $stmt = $pdb->prepare(
                    'SELECT a.Name AS Alias, p.Id AS PublisherId
                       FROM tblPublisherAliases a
                       JOIN tblPublishers p ON p.Id = a.PublisherId AND p.IsActive = 1'
                );
                $stmt->execute();
                $all = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();
                $matchId = null;
                foreach ($all as $r) {
                    $cand = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', (string)$r['Alias']), '-'));
                    if ($cand === $pubSlug) { $matchId = (int)$r['PublisherId']; break; }
                }
                if ($matchId !== null) {
                    $stmt = $pdb->prepare("SELECT {$rowSelect} FROM tblPublishers WHERE Id = ? LIMIT 1");
                    $stmt->bind_param('i', $matchId);
                    $stmt->execute();
                    $publisher = $stmt->get_result()->fetch_assoc() ?: null;
                    $stmt->close();
                }
            } catch (\Throwable $e) {
                error_log('[pages/publisher.php] alias lookup: ' . $e->getMessage());
            }
        }

        /* Detail rows for a resolved publisher. */
        if ($publisher !== null) {
            $pid = (int)$publisher['Id'];

            if (!empty($publisher['ParentId'])) {
                try {
                    $stmt = $pdb->prepare('SELECT Id, Name, Slug FROM tblPublishers WHERE Id = ? LIMIT 1');
                    $stmt->bind_param('i', $publisher['ParentId']);
                    $stmt->execute();
                    $parentPublisher = $stmt->get_result()->fetch_assoc() ?: null;
                    $stmt->close();
                } catch (\Throwable $e) { /* parent optional */ }
            }

            if (_publisherTableExists($pdb, 'tblPublisherAliases')) {
                try {
                    $stmt = $pdb->prepare('SELECT Name FROM tblPublisherAliases WHERE PublisherId = ? ORDER BY Name ASC');
                    $stmt->bind_param('i', $pid);
                    $stmt->execute();
                    $publisherAliases = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'Name');
                    $stmt->close();
                } catch (\Throwable $e) { /* aliases optional */ }
            }

            /* Songbooks this publisher published — public visibility predicate
               (hides disabled books, #1765). data-navigate="songbook" links. */
            if (_publisherTableExists($pdb, 'tblSongbookPublishers')) {
                try {
                    $vis = songbookVisibleSql($pdb, 'b');
                    $stmt = $pdb->prepare(
                        "SELECT b.Abbreviation, b.Name, sp.Role
                           FROM tblSongbookPublishers sp
                           JOIN tblSongbooks b ON b.Id = sp.SongbookId
                          WHERE sp.PublisherId = ? AND {$vis}
                          ORDER BY b.Name ASC"
                    );
                    $stmt->bind_param('i', $pid);
                    $stmt->execute();
                    $publisherBooks = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                    $stmt->close();
                } catch (\Throwable $e) {
                    error_log('[pages/publisher.php] books lookup: ' . $e->getMessage());
                }
            }
        }
    }
}

$pubKindLabel = ($publisher !== null && isset(IHYMNS_PUBLISHER_KINDS[$publisher['Kind']]))
    ? IHYMNS_PUBLISHER_KINDS[$publisher['Kind']]
    : ($publisher['Kind'] ?? '');
$pubRoleLabels = IHYMNS_PUBLISHER_ROLES;
?>

<nav aria-label="Breadcrumb" class="mb-3">
    <ol class="breadcrumb small">
        <li class="breadcrumb-item"><a href="/" data-navigate="home">Home</a></li>
        <li class="breadcrumb-item active" aria-current="page">
            <i class="fa-solid fa-building me-1" aria-hidden="true"></i>
            Publisher: <?= htmlspecialchars($publisher !== null ? (string)$publisher['Name'] : $pubSlug) ?>
        </li>
    </ol>
</nav>

<?php if ($pubSlug === ''): ?>
    <div class="alert alert-warning" role="alert">
        <i class="fa-solid fa-circle-exclamation me-2" aria-hidden="true"></i>No publisher specified.
    </div>
    <a href="/" class="btn btn-primary" data-navigate="home">
        <i class="fa-solid fa-arrow-left me-2" aria-hidden="true"></i>Back to Home
    </a>
<?php elseif ($publisher === null): ?>
    <div class="alert alert-warning" role="alert">
        <i class="fa-solid fa-circle-exclamation me-2" aria-hidden="true"></i>
        No publisher named <strong><?= htmlspecialchars($pubSlug) ?></strong> in the catalogue.
    </div>
    <p class="text-muted small">
        Publishers are curated from songbook metadata; if you expected this one, it may not have been
        catalogued under that exact spelling yet.
    </p>
    <a href="/" class="btn btn-primary" data-navigate="home">
        <i class="fa-solid fa-arrow-left me-2" aria-hidden="true"></i>Back to Home
    </a>
<?php else: ?>
    <header class="mb-4">
        <h1 class="h3 d-flex align-items-baseline gap-2 flex-wrap">
            <i class="fa-solid fa-building text-muted" aria-hidden="true"></i>
            <span><?= htmlspecialchars((string)$publisher['Name']) ?></span>
            <?php if (!empty($publisher['Disambiguation'])): ?>
                <small class="text-muted fw-normal">(<?= htmlspecialchars((string)$publisher['Disambiguation']) ?>)</small>
            <?php endif; ?>
            <?php if ($pubKindLabel !== ''): ?>
                <span class="badge bg-body-secondary text-body-emphasis align-middle"><?= htmlspecialchars((string)$pubKindLabel) ?></span>
            <?php endif; ?>
        </h1>
        <?php if (!empty($publisher['Subtitle'])): ?>
            <p class="text-muted mb-1"><?= htmlspecialchars((string)$publisher['Subtitle']) ?></p>
        <?php endif; ?>

        <div class="text-muted small d-flex flex-wrap column-gap-4 row-gap-1 mb-1">
            <?php if ($parentPublisher !== null): ?>
                <span>
                    <i class="fa-solid fa-sitemap me-1" aria-hidden="true"></i>Imprint of
                    <a href="/publisher/<?= htmlspecialchars((string)$parentPublisher['Slug']) ?>" data-navigate="publisher">
                        <?= htmlspecialchars((string)$parentPublisher['Name']) ?></a>
                </span>
            <?php endif; ?>
            <?php if (!empty($publisher['CityName'])): ?>
                <span><i class="fa-solid fa-location-dot me-1" aria-hidden="true"></i><?= htmlspecialchars((string)$publisher['CityName']) ?></span>
            <?php endif; ?>
            <?php if (!empty($publisher['Ipi'])): ?>
                <span><strong>IPI:</strong> <span class="badge bg-body-secondary text-body-emphasis"><?= htmlspecialchars((string)$publisher['Ipi']) ?></span></span>
            <?php endif; ?>
            <?php if (!empty($publisher['Isni'])): ?>
                <span><strong>ISNI:</strong> <span class="badge bg-body-secondary text-body-emphasis"><?= htmlspecialchars((string)$publisher['Isni']) ?></span></span>
            <?php endif; ?>
        </div>

        <?php if ($publisherAliases): ?>
            <p class="text-muted small mb-1">
                <i class="fa-solid fa-tags me-1" aria-hidden="true"></i>Also known as:
                <?= htmlspecialchars(implode(', ', $publisherAliases)) ?>
            </p>
        <?php endif; ?>

        <?php if (!empty($publisher['Notes'])): ?>
            <p class="small mb-0"><?= nl2br(htmlspecialchars((string)$publisher['Notes'])) ?></p>
        <?php endif; ?>
    </header>

    <section aria-labelledby="pub-books-heading">
        <h2 id="pub-books-heading" class="h5 mb-3">
            Songbooks <small class="text-muted">(<?= count($publisherBooks) ?>)</small>
        </h2>
        <?php if (!$publisherBooks): ?>
            <p class="text-muted">No songbooks are currently attributed to this publisher.</p>
        <?php else: ?>
            <!-- Sort control (#1786) — Name. Default IS the server order
                 (b.Name ASC), so this offers a viewer-driven descending flip
                 more than a genuinely different order today. -->
            <?php
                $listSortSurface = 'publisher-books';
                $listSortDefault = 'Name (A–Z)';
                $listSortOptions = [
                    'name' => ['label' => 'Name', 'type' => 'text', 'dir' => 'asc'],
                ];
                require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'list-sort-control.php';
            ?>
            <ul class="list-group song-list" data-list-sort-list="publisher-books">
                <?php foreach ($publisherBooks as $b): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center"
                        data-sort-name="<?= htmlspecialchars(ihymns_title_sort_key((string)$b['Name'])) ?>">
                        <a href="/songbook/<?= htmlspecialchars((string)$b['Abbreviation']) ?>" data-navigate="songbook">
                            <?= htmlspecialchars((string)$b['Name']) ?>
                        </a>
                        <?php $rl = $pubRoleLabels[$b['Role']] ?? (string)$b['Role']; ?>
                        <?php if ($b['Role'] !== 'publisher'): ?>
                            <span class="badge bg-body-secondary text-body-emphasis"><?= htmlspecialchars((string)$rl) ?></span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <?php
    /* JSON-LD (rule #30 exemption — inert application/ld+json). A person-kind
       publisher is a schema.org Person, everything else an Organization. */
    $ld = [
        '@context' => 'https://schema.org',
        '@type'    => ($publisher['Kind'] === 'person') ? 'Person' : 'Organization',
        'name'     => (string)$publisher['Name'],
    ];
    if (!empty($publisher['CityName'])) {
        $ld['address'] = ['@type' => 'PostalAddress', 'addressLocality' => (string)$publisher['CityName']];
    }
    if ($publisherAliases) { $ld['alternateName'] = array_values($publisherAliases); }
    ?>
    <script type="application/ld+json"><?= json_encode($ld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
<?php endif; ?>
