<?php

declare(strict_types=1);

/**
 * iHymns — Work Public Page (#840, extended #1741 P4b)
 *
 * PURPOSE:
 * Public landing page for a `tblWorks` row. Surfaces the canonical
 * title, identifier chips (ISWC/CCLI/BOWI, each cross-linking to the
 * shared `/iswc|/ccli|/bowi` resolver, #1741 P3), subtitle/
 * disambiguation, tune, first-published/copyright line, aggregated
 * writer/composer/arranger credits, parent / child / sibling Works
 * (unlimited nesting), every member song grouped by songbook, the
 * categorised external-links panel (shared partial, #1741 P4b §0.4), and
 * (#1860 Phase 5 Commit 7) a "Contains (medley)" list when this Work is
 * itself a medley (`$work['constituents']`, attached by `getWork()` and
 * gated on `workMedleyReady()` — `[]`/hidden on a non-medley Work or an
 * un-migrated `tblWorkComponents`). The inverse ("part of these
 * medleys") is a tracked follow-up, not v1.
 *
 * Loaded via api.php?page=work&slug=amazing-grace.
 * Expects $workSlug to be set by api.php before inclusion.
 *
 * Pre-migration deployments: getWork() returns null when tblWorks
 * doesn't exist, so the page renders the same friendly 404 as an
 * unknown slug. The nine #1741 P1/D5 "extra" columns (Ccli/Bowi/
 * Subtitle/Disambiguation/TuneName/TuneId/FirstPublishedYear/
 * CopyrightYears/CopyrightHolder) are individually column-gated inside
 * `SongData::getWork()` (its `_worksExtraCols()` probe) — this file
 * never checks column existence itself, it just renders whatever
 * `getWork()` handed back (every key is always present, with an
 * empty-string/null default when the backing column is absent — see
 * that method's doc-block).
 *
 * NO EXECUTABLE INLINE `<script>` (rule #30) — this fragment can be
 * served through api.php's `$_cacheablePages` machinery (the `work`
 * page IS cacheable — plan §0.1), so it must assume it may end up in a
 * shared cache with no per-request CSP nonce. Nothing on this page
 * needs JS beyond the router's existing `data-navigate` click handling.
 *
 * @link .claude/catalogue-1741-P4-plan.md §2.3   the P4b build spec this file implements
 * @see #1741
 */

if (!isset($songData) || !is_object($songData)) {
    require_once __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'SongData.php';
    $songData = new SongData();
}
/* #1786 — ihymns_title_sort_key() for the member-song list's Title sort key. */
require_once __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'sort_helpers.php';

$work = method_exists($songData, 'getWork') ? $songData->getWork($workSlug ?? '') : null;

if ($work === null) {
    http_response_code(404);
    if (function_exists('renderErrorFragment')) {
        echo renderErrorFragment(404, [
            'title'   => 'Work not found',
            'message' => 'We couldn\'t find a work matching "' . (string)($workSlug ?? '') . '". It may have been removed or the link is out of date.',
            'fa'      => 'fa-layer-group',
            'actions' => [
                ['label' => 'Go Home',   'href' => '/',          'navigate' => 'home',      'primary' => true, 'fa' => 'fa-house'],
                ['label' => 'Songbooks', 'href' => '/songbooks', 'navigate' => 'songbooks', 'fa' => 'fa-book-open'],
            ],
        ]);
    } else {
        echo '<div class="alert alert-warning" role="alert">Work not found: <strong>'
           . htmlspecialchars((string)($workSlug ?? '')) . '</strong></div>';
    }
    return;
}

/* Group members by songbook for tidier display. */
$membersByBook = [];
foreach (($work['members'] ?? []) as $m) {
    $abbr = (string)$m['songbook'];
    if (!isset($membersByBook[$abbr])) {
        $membersByBook[$abbr] = [
            'name'    => (string)($m['songbookName'] ?? $abbr),
            'members' => [],
        ];
    }
    $membersByBook[$abbr]['members'][] = $m;
}
ksort($membersByBook);

/* #1741 P4b — Tune row target: prefer the registry link getWork()
   resolved (tune name + real /tune/<slug>); fall back to the
   song.php:719 name-fold of the free-text TuneName mirror so a
   pre-registry / column-gated-absent Tune still points somewhere
   useful rather than disappearing outright. */
$workTuneName = '';
$workTuneSlug = '';
if (!empty($work['tune']['slug'])) {
    $workTuneSlug = (string)$work['tune']['slug'];
    $workTuneName = (string)($work['tune']['name'] ?? $workTuneSlug);
} elseif (!empty($work['tuneName'])) {
    $workTuneName = (string)$work['tuneName'];
    $workTuneSlug = strtolower(trim((string)preg_replace('/[^A-Za-z0-9]+/', '-', $workTuneName), '-'));
}

/* #1741 P4b — First-published / copyright line. Either half renders
   independently, matching the spec's "years-only and holder-only both
   render sensibly" requirement. */
$workPubLineParts = [];
if (!empty($work['firstPublishedYear'])) {
    $workPubLineParts[] = 'First published ' . (int)$work['firstPublishedYear'];
}
$workCopyrightLine = trim(
    (string)($work['copyrightYears'] ?? '') . ' ' . (string)($work['copyrightHolder'] ?? '')
);
if ($workCopyrightLine !== '') {
    $workPubLineParts[] = '© ' . $workCopyrightLine;
}

/* #1741 P4b — "Writers & composers" credit groups, aggregated by
   getWork() from member songs (there is no tblWorkCredits table — the
   escape hatch was accepted-not-built, plan §2.7). Each name links to
   the SAME name-fold /musician/<slug> convention song.php:700 already
   uses for per-song credit rows. */
$workCreditGroups = [
    ['label' => 'Words',       'icon' => 'fa-feather-pointed',      'names' => $work['credits']['writers']   ?? []],
    ['label' => 'Music',       'icon' => 'fa-music',                'names' => $work['credits']['composers'] ?? []],
    ['label' => 'Arrangement', 'icon' => 'fa-wand-magic-sparkles',  'names' => $work['credits']['arrangers'] ?? []],
];
$workHasCredits = false;
foreach ($workCreditGroups as $workCreditGroup) {
    if (!empty($workCreditGroup['names'])) { $workHasCredits = true; break; }
}

?>
<section class="work-page" aria-label="Work — <?= htmlspecialchars($work['title']) ?>">

    <!-- Breadcrumb (#1741 P4b) — identifier.php:116-124 shape. -->
    <nav aria-label="Breadcrumb" class="mb-3">
        <ol class="breadcrumb small">
            <li class="breadcrumb-item"><a href="/" data-navigate="home">Home</a></li>
            <li class="breadcrumb-item active" aria-current="page">
                <i class="fa-solid fa-layer-group me-1" aria-hidden="true"></i>
                <?= htmlspecialchars($work['title']) ?>
            </li>
        </ol>
    </nav>

    <header class="mb-3">
        <h1 class="h3 mb-1">
            <?= htmlspecialchars($work['title']) ?>
            <?php if (!empty($work['disambiguation'])): ?>
                <small class="text-muted fw-normal">(<?= htmlspecialchars($work['disambiguation']) ?>)</small>
            <?php endif; ?>
        </h1>
        <?php if (!empty($work['subtitle'])): ?>
            <p class="text-muted mb-1"><?= htmlspecialchars($work['subtitle']) ?></p>
        <?php endif; ?>

        <!-- Identifier chips (#1741 P4b) — internal cross-links to the
             shared /iswc|/ccli|/bowi resolver (identifier.php, #1741 P3),
             matching song.php's .song-meta-link ISWC chip convention
             (song.php:864-872). Each is its own explicit block (not a
             generic loop over a scheme variable) so the literal
             /iswc//ccli//bowi route prefixes are real bytes in this file,
             not only assembled at request time — the same shape
             song.php's own ISWC/CCLI chips already use. -->
        <?php if (!empty($work['iswc']) || !empty($work['ccli']) || !empty($work['bowi'])): ?>
            <div class="text-muted small d-flex flex-wrap column-gap-4 row-gap-1 mb-1">
                <?php if (!empty($work['iswc'])): ?>
                    <span title="International Standard Musical Work Code">
                        <i class="fa-solid fa-fingerprint me-1" aria-hidden="true"></i>
                        <strong>ISWC:</strong>&nbsp;<a class="song-meta-link"
                           href="/iswc/<?= rawurlencode((string)$work['iswc']) ?>"
                           data-navigate="iswc"
                           title="See everything sharing this ISWC"><?= htmlspecialchars($work['iswc']) ?></a>
                    </span>
                <?php endif; ?>
                <?php if (!empty($work['ccli'])): ?>
                    <span>
                        <i class="fa-solid fa-hashtag me-1" aria-hidden="true"></i>
                        <strong>CCLI Work #:</strong>&nbsp;<a class="song-meta-link"
                           href="/ccli/<?= rawurlencode((string)$work['ccli']) ?>"
                           data-navigate="ccli"
                           title="See everything sharing this CCLI Work Number"><?= htmlspecialchars($work['ccli']) ?></a>
                    </span>
                <?php endif; ?>
                <?php if (!empty($work['bowi'])): ?>
                    <span title="Best Open Work Identifier (Luminate Data)">
                        <i class="fa-solid fa-barcode me-1" aria-hidden="true"></i>
                        <strong>BOWI:</strong>&nbsp;<a class="song-meta-link"
                           href="/bowi/<?= rawurlencode((string)$work['bowi']) ?>"
                           data-navigate="bowi"
                           title="See the work carrying this BOWI"><?= htmlspecialchars($work['bowi']) ?></a>
                    </span>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Tune row (#1741 P4b) -->
        <?php if ($workTuneName !== ''): ?>
            <p class="text-muted small mb-1">
                <i class="fa-solid fa-music me-1" aria-hidden="true"></i>
                <strong>Tune:</strong>
                <?php if ($workTuneSlug !== ''): ?>
                    <a class="song-meta-link" href="/tune/<?= htmlspecialchars($workTuneSlug) ?>"
                       data-navigate="tune"
                       title="See all songs that use this tune"><?= htmlspecialchars($workTuneName) ?></a>
                <?php else: ?>
                    <?= htmlspecialchars($workTuneName) ?>
                <?php endif; ?>
            </p>
        <?php endif; ?>

        <!-- First-published / copyright line (#1741 P4b) -->
        <?php if (!empty($workPubLineParts)): ?>
            <p class="text-muted small mb-1"><?= htmlspecialchars(implode(' · ', $workPubLineParts)) ?></p>
        <?php endif; ?>

        <div class="text-muted small d-flex flex-wrap gap-3">
            <span><i class="fa-solid fa-music me-1" aria-hidden="true"></i>
                  <?= count($work['members']) ?> version<?= count($work['members']) === 1 ? '' : 's' ?> across
                  <?= count($membersByBook) ?> source<?= count($membersByBook) === 1 ? '' : 's' ?></span>
        </div>
    </header>

    <?php if (!empty($work['parent'])): ?>
        <div class="alert alert-secondary py-2 mb-3 d-flex align-items-center gap-2">
            <i class="fa-solid fa-turn-up fa-rotate-270" aria-hidden="true"></i>
            <span>Part of parent work:
                <a href="/work/<?= htmlspecialchars($work['parent']['slug']) ?>"
                   data-navigate="work"
                   data-work-slug="<?= htmlspecialchars($work['parent']['slug']) ?>">
                    <?= htmlspecialchars($work['parent']['title']) ?>
                </a>
            </span>
        </div>
    <?php endif; ?>

    <?php if (!empty($work['notes'])): ?>
        <div class="card mb-3">
            <div class="card-body small">
                <?= nl2br(htmlspecialchars($work['notes'])) ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Writers & composers (#1741 P4b) — aggregated from member songs;
         hidden entirely when none of the three role lists has a name. -->
    <?php if ($workHasCredits): ?>
        <section class="mb-4">
            <h2 class="h6 mb-2 text-muted">
                <i class="fa-solid fa-pen-nib me-1" aria-hidden="true"></i>
                Writers &amp; composers
            </h2>
            <?php foreach ($workCreditGroups as $workCreditGroup): ?>
                <?php if (empty($workCreditGroup['names'])) continue; ?>
                <p class="mb-1 small">
                    <i class="fa-solid <?= htmlspecialchars($workCreditGroup['icon']) ?> me-2 text-muted" aria-hidden="true"></i>
                    <strong><?= htmlspecialchars($workCreditGroup['label']) ?>:</strong>
                    <?php foreach ($workCreditGroup['names'] as $i => $name): ?><a
                           href="/musician/<?= rawurlencode(strtolower(str_replace(' ', '-', $name))) ?>"
                           class="song-meta-link"
                           data-navigate="musician"><?= htmlspecialchars($name) ?></a><?php if ($i < count($workCreditGroup['names']) - 1): ?>;&nbsp;<?php endif; ?><?php endforeach; ?>
                </p>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>

    <?php if (!empty($work['children'])): ?>
        <section class="mb-4">
            <h2 class="h6 mb-2 text-muted">
                <i class="fa-solid fa-sitemap me-1" aria-hidden="true"></i>
                Derivative works (<?= count($work['children']) ?>)
            </h2>
            <div class="list-group" role="list">
                <?php foreach ($work['children'] as $c): ?>
                    <a href="/work/<?= htmlspecialchars($c['slug']) ?>"
                       class="list-group-item list-group-item-action d-flex align-items-center gap-2"
                       data-navigate="work"
                       data-work-slug="<?= htmlspecialchars($c['slug']) ?>"
                       role="listitem">
                        <i class="fa-solid fa-diagram-project text-muted" aria-hidden="true"></i>
                        <div class="flex-grow-1">
                            <?= htmlspecialchars($c['title']) ?>
                            <?php if (!empty($c['iswc'])): ?>
                                <small class="text-muted ms-2"><code><?= htmlspecialchars($c['iswc']) ?></code></small>
                            <?php endif; ?>
                        </div>
                        <i class="fa-solid fa-chevron-right text-muted" aria-hidden="true"></i>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <!-- "Contains (medley)" (#1860 Phase 5 Commit 7) — this Work's own
         medley constituents, attached by SongData::getWork() (gated on
         workMedleyReady()/tblWorkComponents, includes/work_admin.php,
         rule #22). Same list markup as the Derivative-works block above,
         swapping the icon + heading. The INVERSE listing ("part of
         medleys") is a tracked follow-up, not v1 (plan §7 item 3). -->
    <?php if (!empty($work['constituents'])): ?>
        <section class="mb-4">
            <h2 class="h6 mb-2 text-muted">
                <i class="fa-solid fa-layer-group me-1" aria-hidden="true"></i>
                Contains (medley) (<?= count($work['constituents']) ?>)
            </h2>
            <div class="list-group" role="list">
                <?php foreach ($work['constituents'] as $cw): ?>
                    <a href="/work/<?= htmlspecialchars($cw['slug']) ?>"
                       class="list-group-item list-group-item-action d-flex align-items-center gap-2"
                       data-navigate="work"
                       data-work-slug="<?= htmlspecialchars($cw['slug']) ?>"
                       role="listitem">
                        <i class="fa-solid fa-music text-muted" aria-hidden="true"></i>
                        <div class="flex-grow-1">
                            <?= htmlspecialchars($cw['title']) ?>
                        </div>
                        <i class="fa-solid fa-chevron-right text-muted" aria-hidden="true"></i>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <section class="mb-4">
        <h2 class="h6 mb-2 text-muted">
            <i class="fa-solid fa-music me-1" aria-hidden="true"></i>
            Versions across sources (<?= count($work['members']) ?>)
        </h2>
        <?php if (empty($membersByBook)): ?>
            <p class="text-muted small mb-0">This work has no member songs yet.</p>
        <?php else: ?>
            <!-- Sort control (#1786) — Default is the WORK's own curated
                 member order (a movement/version order is meaningful — this
                 is NOT an arbitrary server default like other surfaces'
                 "Number"). One control governs every per-songbook group
                 below (multi-container). -->
            <?php
                $listSortSurface = 'work-songs';
                $listSortDefault = 'Work order';
                $listSortOptions = [
                    'number' => ['label' => 'Number',   'type' => 'number', 'dir' => 'asc'],
                    'title'  => ['label' => 'Title',    'type' => 'text',   'dir' => 'asc'],
                    'book'   => ['label' => 'Songbook', 'type' => 'text',   'dir' => 'asc'],
                ];
                require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'list-sort-control.php';
            ?>
            <?php foreach ($membersByBook as $abbr => $book): ?>
                <div class="mb-3">
                    <div class="d-flex align-items-baseline gap-2 mb-1">
                        <a href="/songbook/<?= htmlspecialchars($abbr) ?>"
                           class="fw-semibold text-decoration-none"
                           data-navigate="songbook"
                           data-songbook="<?= htmlspecialchars($abbr) ?>">
                            <span class="badge bg-body-secondary"><?= htmlspecialchars($abbr) ?></span>
                            <?= htmlspecialchars($book['name']) ?>
                        </a>
                    </div>
                    <div class="list-group song-list" role="list" data-list-sort-list="work-songs">
                        <?php foreach ($book['members'] as $m): ?>
                            <a href="/song/<?= htmlspecialchars($m['songId']) ?>"
                               class="list-group-item list-group-item-action d-flex align-items-center gap-2"
                               data-navigate="song"
                               data-song-id="<?= htmlspecialchars($m['songId']) ?>"
                               role="listitem"
                               <?php if ((int)$m['number'] > 0): ?>data-sort-number="<?= (int)$m['number'] ?>"<?php endif; ?>
                               data-sort-title="<?= htmlspecialchars(ihymns_title_sort_key((string)$m['title'])) ?>"
                               data-sort-book="<?= htmlspecialchars(mb_strtolower($abbr, 'UTF-8')) ?>">
                                <?php if ((int)$m['number'] > 0): ?>
                                    <span class="badge bg-body-secondary">#<?= (int)$m['number'] ?></span>
                                <?php endif; ?>
                                <span class="flex-grow-1"><?= htmlspecialchars(toTitleCase((string)$m['title'])) ?></span>
                                <?php if (!empty($m['memberNote'])): ?>
                                    <small class="text-muted">— <?= htmlspecialchars($m['memberNote']) ?></small>
                                <?php endif; ?>
                                <?php if (!empty($m['isCanonical'])): ?>
                                    <i class="fa-solid fa-star text-warning" role="img" aria-label="Canonical version" title="Canonical version"></i>
                                <?php endif; ?>
                                <i class="fa-solid fa-chevron-right text-muted small" aria-hidden="true"></i>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>

    <?php
        /* #1741 P4b (§0.4) — the categorised external-links panel is now
           the SHARED partial (was inlined here, with its own local
           category-label map — see includes/partials/external-links-panel
           .php's doc-block for why this was extracted and what else
           consumes it). No behaviour change: same category order/labels,
           same markup, one source instead of one-per-page-that-has-links. */
        $panelLinks     = $work['links'] ?? [];
        $panelHeading   = 'Find this work elsewhere';
        $panelAriaLabel = 'Find this work elsewhere';
        require __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR
              . 'partials' . DIRECTORY_SEPARATOR . 'external-links-panel.php';
    ?>

</section>
