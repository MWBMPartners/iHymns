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

/**
 * #1969 (API-coverage batch 1, C4) — the whole resolution + detail-rows
 * block that used to live inline here (IL-id pre-step, the fold, the
 * (a)/(b)/(c) lookup ladder, parent/aliases/songbooks detail rows) is now
 * `publisherResolveDisplayData()` in `includes/publisher_helpers.php` —
 * extracted verbatim so the new `?action=publisher_detail` JSON endpoint
 * (api.php) can share this EXACT read path instead of forking a second
 * copy of rule #37's resolution ladder (rule #22). See that function's
 * doc-block for the full per-rung rationale, not repeated here (rule #35).
 */
$pdb = getDbMysqli();
$_publisherResolved = publisherResolveDisplayData($pdb, (string)($publisherSlug ?? ''));

$pubSlug          = $_publisherResolved['slug'];
$publisher        = $_publisherResolved['publisher'];
$publisherAliases = $_publisherResolved['aliases'];
$publisherBooks   = $_publisherResolved['books'];
$parentPublisher  = $_publisherResolved['parentPublisher'];

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
    <?php /* SECURITY: JSON_HEX_TAG|_AMP|_APOS|_QUOT so a DB publisher name, city
             or alias containing </script> (or &, ", ') cannot break out of this
             public <script> element and inject HTML (stored XSS). Mirrors the same
             fix on musician.php:1128; both are guarded by
             tests/php/test-jsonld-escaping.php. See the 2026-08-30 security audit. */ ?>
    <script type="application/ld+json"><?= json_encode(
        $ld,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    ) ?></script>
<?php endif; ?>
