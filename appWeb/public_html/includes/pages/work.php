<?php

declare(strict_types=1);

/**
 * iHymns — Work Public Page (#840)
 *
 * PURPOSE:
 * Public landing page for a `tblWorks` row. Surfaces the canonical
 * title, optional ISWC, parent / child / sibling Works (unlimited
 * nesting), every member song grouped by songbook, and the categorised
 * external-links panel.
 *
 * Loaded via api.php?page=work&slug=amazing-grace.
 * Expects $workSlug to be set by api.php before inclusion.
 *
 * Pre-migration deployments: getWork() returns null when tblWorks
 * doesn't exist, so the page renders the same friendly 404 as an
 * unknown slug.
 */

if (!isset($songData) || !is_object($songData)) {
    require_once __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'SongData.php';
    $songData = new SongData();
}

$work = method_exists($songData, 'getWork') ? $songData->getWork($workSlug ?? '') : null;

if ($work === null) {
    http_response_code(404);
    echo '<div class="alert alert-warning" role="alert">';
    echo '<i class="fa-solid fa-circle-exclamation me-2" aria-hidden="true"></i>';
    echo 'Work not found: <strong>' . htmlspecialchars((string)($workSlug ?? '')) . '</strong>';
    echo '</div>';
    echo '<a href="/" class="btn btn-primary" data-navigate="home">';
    echo '<i class="fa-solid fa-arrow-left me-2" aria-hidden="true"></i>Back to Home</a>';
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

/* Group external links by category. */
$workLinks    = $work['links'] ?? [];
$wLinksByCat  = [];
foreach ($workLinks as $l) {
    $cat = (string)($l['category'] ?? 'other');
    if (!isset($wLinksByCat[$cat])) $wLinksByCat[$cat] = [];
    $wLinksByCat[$cat][] = $l;
}
$wCatLabels = [
    'official'    => 'Official',
    'information' => 'Information',
    'read'        => 'Read',
    'sheet-music' => 'Sheet music',
    'listen'      => 'Listen',
    'watch'       => 'Watch',
    'purchase'    => 'Purchase',
    'authority'   => 'Authority',
    'social'      => 'Social',
    'other'       => 'Other',
];

?>
<section class="work-page" aria-label="Work — <?= htmlspecialchars($work['title']) ?>">

    <header class="mb-3">
        <h1 class="h3 mb-1"><?= htmlspecialchars($work['title']) ?></h1>
        <div class="text-muted small d-flex flex-wrap gap-3">
            <?php if (!empty($work['iswc'])): ?>
                <span><i class="fa-solid fa-fingerprint me-1" aria-hidden="true"></i>
                      ISWC: <code><?= htmlspecialchars($work['iswc']) ?></code></span>
            <?php endif; ?>
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

    <?php if (!empty($work['children'])): ?>
        <section class="mb-4">
            <h2 class="h6 mb-2 text-muted">
                <i class="fa-solid fa-sitemap me-1" aria-hidden="true"></i>
                Derivative works (<?= count($work['children']) ?>)
            </h2>
            <div class="list-group">
                <?php foreach ($work['children'] as $c): ?>
                    <a href="/work/<?= htmlspecialchars($c['slug']) ?>"
                       class="list-group-item list-group-item-action d-flex align-items-center gap-2"
                       data-navigate="work"
                       data-work-slug="<?= htmlspecialchars($c['slug']) ?>">
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

    <section class="mb-4">
        <h2 class="h6 mb-2 text-muted">
            <i class="fa-solid fa-music me-1" aria-hidden="true"></i>
            Versions across sources (<?= count($work['members']) ?>)
        </h2>
        <?php if (empty($membersByBook)): ?>
            <p class="text-muted small mb-0">This work has no member songs yet.</p>
        <?php else: ?>
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
                    <div class="list-group">
                        <?php foreach ($book['members'] as $m): ?>
                            <a href="/song/<?= htmlspecialchars($m['songId']) ?>"
                               class="list-group-item list-group-item-action d-flex align-items-center gap-2"
                               data-navigate="song"
                               data-song-id="<?= htmlspecialchars($m['songId']) ?>">
                                <?php if ((int)$m['number'] > 0): ?>
                                    <span class="badge bg-body-secondary">#<?= (int)$m['number'] ?></span>
                                <?php endif; ?>
                                <span class="flex-grow-1"><?= htmlspecialchars(toTitleCase((string)$m['title'])) ?></span>
                                <?php if (!empty($m['memberNote'])): ?>
                                    <small class="text-muted">— <?= htmlspecialchars($m['memberNote']) ?></small>
                                <?php endif; ?>
                                <?php if (!empty($m['isCanonical'])): ?>
                                    <i class="fa-solid fa-star text-warning" aria-label="Canonical version" title="Canonical version"></i>
                                <?php endif; ?>
                                <i class="fa-solid fa-chevron-right text-muted small" aria-hidden="true"></i>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>

    <?php if (!empty($workLinks)): ?>
        <section class="mt-4 pt-3 border-top" aria-label="Find this work elsewhere">
            <h2 class="h6 mb-3 d-flex align-items-center gap-2">
                <i class="fa-solid fa-link me-1 text-muted" aria-hidden="true"></i>
                Find this work elsewhere
            </h2>
            <?php foreach ($wCatLabels as $cat => $catLabel): ?>
                <?php if (empty($wLinksByCat[$cat])) continue; ?>
                <div class="mb-2">
                    <div class="text-uppercase small text-muted mb-1"><?= htmlspecialchars($catLabel) ?></div>
                    <div class="d-flex flex-wrap gap-2">
                        <?php foreach ($wLinksByCat[$cat] as $l): ?>
                            <a href="<?= htmlspecialchars($l['url']) ?>"
                               target="_blank" rel="noopener nofollow"
                               class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-2">
                                <?php if (!empty($l['iconClass'])): ?>
                                    <i class="<?= htmlspecialchars($l['iconClass']) ?>" aria-hidden="true"></i>
                                <?php endif; ?>
                                <span><?= htmlspecialchars($l['name']) ?></span>
                                <?php if (!empty($l['note'])): ?>
                                    <span class="text-muted small">— <?= htmlspecialchars($l['note']) ?></span>
                                <?php endif; ?>
                                <?php if (!empty($l['verified'])): ?>
                                    <i class="fa-solid fa-circle-check text-success small" aria-label="Verified" title="Verified"></i>
                                <?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>

</section>
