<?php

declare(strict_types=1);

/**
 * iHymns — Manage / Admin Help & Guides
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * Plain-English in-app reference for everyone who works in the
 * /manage/ surface — curators/editors, admins, and global admins.
 *
 * Visible to every signed-in admin-surface user (no entitlement
 * gate). Sections covering global-admin-only pages still appear so
 * that lower-privileged users can see what those pages are for; the
 * pages themselves remain entitlement-gated.
 *
 * Wire point: appears as the LAST entry in admin-links.php (the
 * shared link registry consumed by admin-nav.php's offcanvas + the
 * sidebar) so it renders below every other admin destination in
 * both surfaces.
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth.php';

requireAuth();
$currentUser = getCurrentUser();
$activePage  = 'help';

/* ------------------------------------------------------------------
 * Section registry — drives both the table of contents and the body.
 * One entry per /manage/ destination, ordered to mirror the sidebar.
 * Each section's body is HTML; keep paragraphs short and bullet
 * lists concrete (no jargon) so a worship leader can act on it
 * without a developer present.
 * ------------------------------------------------------------------ */

$sections = [
    [
        'id'    => 'getting-started',
        'icon'  => 'bi-rocket-takeoff',
        'title' => 'Getting started',
        'group' => 'Overview',
    ],
    [
        'id'    => 'roles',
        'icon'  => 'bi-person-badge',
        'title' => 'Roles & what each one can do',
        'group' => 'Overview',
    ],
    [
        'id'    => 'dashboard',
        'icon'  => 'bi-speedometer2',
        'title' => 'Dashboard',
        'group' => 'Overview',
    ],
    [
        'id'    => 'editor',
        'icon'  => 'bi-pencil-square',
        'title' => 'Song Editor',
        'group' => 'Content',
    ],
    [
        'id'    => 'requests',
        'icon'  => 'bi-lightbulb',
        'title' => 'Song Requests',
        'group' => 'Content',
    ],
    [
        'id'    => 'revisions',
        'icon'  => 'bi-clock-history',
        'title' => 'Edit History',
        'group' => 'Content',
    ],
    /* #1694 — the soft-delete queue. Sits directly after Revisions Audit
       because the two are the recovery pair a curator reaches for in the same
       breath: Revisions restores a bad EDIT, Deleted Songs restores a bad
       DELETE. Icon + group mirror the admin-links.php nav entry so the help
       TOC and the sidebar read the same. */
    [
        'id'    => 'deleted-songs',
        'icon'  => 'bi-trash3',
        'title' => 'Deleted Songs',
        'group' => 'Content',
    ],
    [
        'id'    => 'missing-numbers',
        'icon'  => 'bi-binoculars',
        'title' => 'Missing Numbers',
        'group' => 'Content',
    ],
    [
        'id'    => 'songbooks',
        'icon'  => 'bi-book',
        'title' => 'Songbooks',
        'group' => 'Content',
    ],
    /* #94 IA-reconcile: a read-only audit tool that fetches an archive.org
       item's OCR text and scores it against a songbook. Icon mirrors the
       admin-links.php nav entry ('bi-archive', Catalogue group). */
    [
        'id'    => 'ia-reconcile',
        'icon'  => 'bi-archive',
        'title' => 'Scan Import',
        'group' => 'Content',
    ],
    [
        'id'    => 'songbook-series',
        'icon'  => 'bi-collection-fill',
        'title' => 'Songbook Series',
        'group' => 'Content',
    ],
    [
        'id'    => 'print-templates',
        'icon'  => 'bi-printer',
        'title' => 'Print Templates',
        'group' => 'Content',
    ],
    [
        'id'    => 'musicians',
        'icon'  => 'bi-person-vcard',
        'title' => 'Musicians',
        'group' => 'Content',
    ],
    /* #1748 Tunes registry. Icon + placement mirror the admin-links.php nav
       entry ('bi-music-note-beamed', manage_tunes), sitting next to
       Musicians/Publishers — the other named-entity registries a song's
       metadata references. */
    [
        'id'    => 'tunes',
        'icon'  => 'bi-music-note-beamed',
        'title' => 'Tunes',
        'group' => 'Content',
    ],
    /* #1748 Tunes registry. Icon + placement mirror the admin-links.php nav
       entry ('bi-music-note-beamed', manage_tunes), sitting next to
       Musicians/Publishers — the other named-entity registries a song's
       metadata references. */
    /* #93 Publishers registry (part of epic #1765). Icon + Catalogue placement
       mirror the admin-links.php nav entry ('bi-building', manage_publishers). */
    [
        'id'    => 'publishers',
        'icon'  => 'bi-building',
        'title' => 'Publishers',
        'group' => 'Content',
    ],
    [
        'id'    => 'duplicate-songs',
        'icon'  => 'bi-files',
        'title' => 'Find Duplicates',
        'group' => 'Content',
    ],
    [
        'id'    => 'works',
        'icon'  => 'bi-diagram-3',
        'title' => 'Works',
        'group' => 'Content',
    ],
    [
        'id'    => 'tags',
        'icon'  => 'bi-tags',
        'title' => 'Tags & Themes',
        'group' => 'Content',
    ],
    [
        'id'    => 'languages',
        'icon'  => 'bi-translate',
        'title' => 'Languages',
        'group' => 'Content',
    ],
    [
        'id'    => 'catalogues',
        'icon'  => 'bi-collection',
        'title' => 'Collections (Catalogues)',
        'group' => 'Content',
    ],
    [
        'id'    => 'external-links',
        'icon'  => 'bi-link-45deg',
        'title' => 'External Links',
        'group' => 'Content',
    ],
    [
        'id'    => 'restrictions',
        'icon'  => 'bi-shield-lock',
        'title' => 'Content Restrictions',
        'group' => 'Content',
    ],
    [
        'id'    => 'tiers',
        'icon'  => 'bi-stars',
        'title' => 'Membership Tiers',
        'group' => 'Content',
    ],
    /* #1769 P1 — the licence-type vocabulary registry (tblLicenceTypes). Icon +
       Access placement mirror the admin-links.php nav entry
       ('bi-patch-check', manage_licence_types). */
    [
        'id'    => 'licence-types',
        'icon'  => 'bi-patch-check',
        'title' => 'Licence Types',
        'group' => 'Content',
    ],
    /* #1769 — the gating family hub + activation-readiness checklist. Icon +
       Access placement mirror the admin-links.php nav entry
       ('bi-shield-shaded', manage_configuration). */
    [
        'id'    => 'gating',
        'icon'  => 'bi-shield-shaded',
        'title' => 'Content Access',
        'group' => 'Content',
    ],
    [
        'id'    => 'users',
        'icon'  => 'bi-people',
        'title' => 'Users',
        'group' => 'People',
    ],
    [
        'id'    => 'groups',
        'icon'  => 'bi-people-fill',
        'title' => 'User Groups',
        'group' => 'People',
    ],
    [
        'id'    => 'organisations',
        'icon'  => 'bi-building',
        'title' => 'Organisations',
        'group' => 'People',
    ],
    [
        'id'    => 'my-organisations',
        'icon'  => 'bi-building-check',
        'title' => 'My Organisations',
        'group' => 'People',
    ],
    [
        'id'    => 'entitlements',
        'icon'  => 'bi-key',
        'title' => 'Role Permissions',
        'group' => 'People',
    ],
    /* #1325 Venues & service times — the where/when foundation Service Mode
       (immediately below) builds on. Icon mirrors the admin-links.php nav
       entry ('bi-geo-alt', manage_organisations). */
    [
        'id'    => 'venues',
        'icon'  => 'bi-geo-alt',
        'title' => 'Venues & Service Times',
        'group' => 'People',
    ],
    [
        'id'    => 'service-mode',
        'icon'  => 'bi-broadcast-pin',
        'title' => 'Service Mode (Live-Follow)',
        'group' => 'People',
    ],
    [
        'id'    => 'analytics',
        'icon'  => 'bi-graph-up',
        'title' => 'Analytics',
        'group' => 'Operations',
    ],
    [
        'id'    => 'ccli-report',
        'icon'  => 'bi-receipt',
        'title' => 'CCLI Usage Report',
        'group' => 'Operations',
    ],
    /* #1861 My CCLI Report — the org-scoped self-serve sibling. Kept right
       next to the system-wide report above (same page family) even though
       admin-links.php nav-groups it under People; the plain-English
       relabel (#1822) put ccli-report's own sibling here too. */
    [
        'id'    => 'my-ccli-report',
        'icon'  => 'bi-receipt-cutoff',
        'title' => 'My CCLI Report',
        'group' => 'Operations',
    ],
    [
        'id'    => 'data-health',
        'icon'  => 'bi-activity',
        'title' => 'Data Health',
        'group' => 'Operations',
    ],
    [
        'id'    => 'activity-log',
        'icon'  => 'bi-journal-text',
        'title' => 'Activity Log',
        'group' => 'Operations',
    ],
    [
        'id'    => 'notifications',
        'icon'  => 'bi-bell',
        'title' => 'Notifications',
        'group' => 'Operations',
    ],
    [
        'id'    => 'schema-audit',
        'icon'  => 'bi-clipboard2-data',
        'title' => 'Database Structure Check',
        'group' => 'Operations',
    ],
    [
        'id'    => 'diagnostics',
        'icon'  => 'bi-terminal',
        'title' => 'Database Query Tool',
        'group' => 'Operations',
    ],
    [
        'id'    => 'setup-database',
        'icon'  => 'bi-database-gear',
        'title' => 'Database Setup',
        'group' => 'Operations',
    ],
    /* ELI5: this tells the site "here's where to find our app on the App
       Store / Google Play / Amazon Appstore" — once you fill it in,
       visitors on that platform see a "Get the app" banner instead of
       the browser's PWA install prompt.
       DETAILED (#1462): documents /manage/configuration's "Native app
       stores" card, which moved native_app_ios / native_app_android /
       native_app_amazon out of the APP_CONFIG['native_apps'] code
       constant into tblAppSettings (commit 8c5dda87) so an admin can
       set/change store IDs without a deploy. */
    [
        'id'    => 'configuration',
        'icon'  => 'bi-phone',
        'title' => 'Native app stores & Apple Sign-In',
        'group' => 'Operations',
    ],
    [
        'id'    => 'native-api',
        'icon'  => 'bi-broadcast',
        'title' => 'Native API surface',
        'group' => 'Operations',
    ],
    [
        'id'    => 'api-keys',
        'icon'  => 'bi-key-fill',
        'title' => 'API Keys',
        'group' => 'Operations',
    ],
    [
        'id'    => 'webhooks',
        'icon'  => 'bi-broadcast',
        'title' => 'Webhooks',
        'group' => 'Operations',
    ],
    [
        'id'    => 'mobile-admin',
        'icon'  => 'bi-phone',
        'title' => 'Mobile admin (responsive lists)',
        'group' => 'Operations',
    ],
    [
        'id'    => 'api-docs',
        'icon'  => 'bi-file-earmark-code',
        'title' => 'API Docs (Swagger UI)',
        'group' => 'Help',
    ],
    [
        'id'    => 'troubleshooting',
        'icon'  => 'bi-life-preserver',
        'title' => 'Troubleshooting & FAQs',
        'group' => 'Help',
    ],
];

/* Pre-group sections for the sidebar TOC. */
$grouped = [];
foreach ($sections as $s) {
    $grouped[$s['group']][] = $s;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Help &amp; Guides — iHymns Admin</title>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-libs.php'; ?>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'head-favicon.php'; ?>
    <style>
        /* Help-specific layout: narrow content column with a sticky TOC
           on lg+. Below lg the TOC stacks above the content. */
        .help-toc {
            position: sticky;
            top: 1rem;
            max-height: calc(100vh - 2rem);
            overflow-y: auto;
        }
        .help-toc .nav-link {
            padding: 0.25rem 0.5rem;
            font-size: 0.9rem;
        }
        .help-toc .toc-group-heading {
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            opacity: 0.6;
            margin-top: 1rem;
            margin-bottom: 0.25rem;
        }
        .help-toc .toc-group-heading:first-child { margin-top: 0; }
        .help-section {
            scroll-margin-top: 5rem; /* keep heading clear of the sticky topbar on anchor jumps */
        }
        .help-section h2 {
            border-bottom: 1px solid var(--bs-border-color);
            padding-bottom: 0.5rem;
            margin-top: 2.5rem;
        }
        .help-section h2:first-of-type { margin-top: 0.5rem; }
        .help-section .role-badges .badge { margin-right: 0.25rem; }
        .help-section .gotcha {
            border-left: 3px solid var(--bs-warning);
            padding-left: 0.75rem;
            margin-bottom: 0.5rem;
        }
        .help-section dl.actions dt { font-weight: 600; }
        .help-section dl.actions dd { margin-bottom: 0.6rem; }
    </style>
</head>
<body>
    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-nav.php'; ?>

    <div class="container-admin py-4">
        <header class="mb-4">
            <h1 class="h4 mb-1">
                <i class="bi bi-life-preserver me-2" aria-hidden="true"></i>
                Help &amp; Guides
            </h1>
            <p class="text-secondary small mb-0">
                A plain-English reference for every page in the iHymns admin
                area. Skim the table of contents to find the page you're
                working in, or read straight through the first time so you
                know what's where.
            </p>
        </header>

        <div class="row g-4">

            <!-- ========================== TABLE OF CONTENTS ========================== -->
            <aside class="col-lg-3 d-none d-lg-block">
                <nav class="help-toc" aria-label="Help sections">
                    <?php foreach ($grouped as $group => $items): ?>
                        <div class="toc-group-heading"><?= htmlspecialchars($group) ?></div>
                        <ul class="nav flex-column">
                            <?php foreach ($items as $s): ?>
                                <li class="nav-item">
                                    <a class="nav-link" href="#<?= htmlspecialchars($s['id']) ?>">
                                        <i class="<?= htmlspecialchars($s['icon']) ?> me-1" aria-hidden="true"></i>
                                        <?= htmlspecialchars($s['title']) ?>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endforeach; ?>
                </nav>
            </aside>

            <!-- ============================ MAIN CONTENT ============================ -->
            <main class="col-lg-9">

                <!-- Mobile-only TOC accordion (lg- viewports) -->
                <details class="d-lg-none mb-4 card-admin">
                    <summary class="fw-semibold">Jump to a section</summary>
                    <div class="mt-2">
                        <?php foreach ($grouped as $group => $items): ?>
                            <div class="toc-group-heading"><?= htmlspecialchars($group) ?></div>
                            <ul class="list-unstyled small mb-2">
                                <?php foreach ($items as $s): ?>
                                    <li><a href="#<?= htmlspecialchars($s['id']) ?>"><?= htmlspecialchars($s['title']) ?></a></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endforeach; ?>
                    </div>
                </details>

                <!-- ====================================================================
                     OVERVIEW
                     ==================================================================== -->

                <section id="getting-started" class="help-section card-admin mb-4">
                    <h2><i class="bi bi-rocket-takeoff me-2"></i>Getting started</h2>
                    <p>
                        The iHymns admin area lives at <code>/manage/</code> and is the
                        place where curators, editors, admins, and global admins do
                        everything that isn't &ldquo;use the app&rdquo;: adding songs,
                        building songbooks, managing user accounts, reviewing requests,
                        running reports.
                    </p>
                    <p>
                        Every page in the admin area follows the same shape:
                    </p>
                    <ul>
                        <li>The <strong>top bar</strong> always shows the iHymns brand,
                            your name, and a hamburger menu (on small screens) or the
                            <strong>sidebar</strong> (on wide screens) listing every page
                            you have access to.</li>
                        <li>The <strong>title at the top</strong> tells you what page
                            you're on; below it sits the page's main controls.</li>
                        <li>Buttons that perform <strong>destructive or irreversible
                            actions</strong> (delete, drop, disconnect) always ask for
                            confirmation. Read the prompt before clicking through.</li>
                        <li>Pages you don't have permission for are simply <em>not
                            shown</em> in the menu — there's nothing to click that you
                            can't actually use.</li>
                    </ul>
                    <p>
                        If a page mentions an action that you can't see, you don't have
                        the permission for it. Ask a global admin to grant you the
                        relevant role or permission (see
                        <a href="#roles">Roles</a> and
                        <a href="#entitlements">Role Permissions</a> below).
                    </p>
                    <p class="small text-muted mb-0">
                        Not sure which environment you're testing on? The public site's
                        header carries a colour-distinct badge next to the version number
                        &mdash; amber for <strong>Alpha</strong>, blue for <strong>Beta</strong>
                        &mdash; and that same version number links through to the
                        <strong>What's New</strong> page. Confirming this with a
                        tester before you dig into a bug report is worth it: a Live Follow
                        or Service Mode session hosted on one site can never be joined from
                        another, and &ldquo;works on my machine&rdquo; is often just two
                        people on two different environments.
                    </p>
                </section>

                <section id="roles" class="help-section card-admin mb-4">
                    <h2><i class="bi bi-person-badge me-2"></i>Roles &amp; what each one can do</h2>
                    <p>iHymns has four account roles, in order of increasing access:</p>

                    <dl class="actions">
                        <dt>User <span class="badge bg-secondary">user</span></dt>
                        <dd>
                            The default for everyone signing up. No access to
                            <code>/manage/</code> at all. Can use the main app, save
                            favourites, and build setlists.
                        </dd>

                        <dt>Curator / Editor <span class="badge bg-primary">editor</span></dt>
                        <dd>
                            Can add and edit songs in the Song Editor, see the
                            Dashboard, see &amp; act on Song Requests, run the
                            Missing Numbers report, and manage the Musicians
                            registry. Cannot see Users, Organisations, or Operations
                            pages.
                        </dd>

                        <dt>Admin <span class="badge bg-warning text-dark">admin</span></dt>
                        <dd>
                            Everything an editor can do, plus: manage Users (create,
                            update, change role up to admin), manage Songbooks,
                            Organisations, User Groups, Membership Tiers, Content
                            Restrictions, see Analytics, Activity Log, CCLI Report.
                            Cannot manage Role Permissions or run Database Structure Check /
                            Database Setup.
                        </dd>

                        <dt>Global Admin <span class="badge bg-danger">global admin</span></dt>
                        <dd>
                            Everything. Including the safety-critical operations
                            pages (Database Setup, Database Structure Check, Data Health,
                            Role Permissions). Use sparingly — actions on these pages
                            can affect every user.
                        </dd>
                    </dl>

                    <p class="text-secondary small mb-0">
                        Roles are assigned on the <a href="#users">Users</a> page.
                        Which permissions go with which role can be customised on
                        <a href="#entitlements">Role Permissions</a>, but the defaults
                        match the rules above.
                    </p>
                </section>

                <section id="dashboard" class="help-section card-admin mb-4">
                    <h2><i class="bi bi-speedometer2 me-2"></i>Dashboard</h2>
                    <p class="role-badges">
                        <span class="badge bg-primary">editor</span>
                        <span class="badge bg-warning text-dark">admin</span>
                        <span class="badge bg-danger">Global Admin</span>
                    </p>
                    <p>
                        The first page you see after signing in. Shows a snapshot of
                        the library and a quick-link card for every other admin
                        page you can access.
                    </p>
                    <h3 class="h6">Key actions</h3>
                    <ul>
                        <li>See library stats (songs, songbooks, setlists, pending requests).</li>
                        <li>See activity (active users, logins in the last 24 h, song views).</li>
                        <li>For admins: see a list of the 10 most recently created users.</li>
                        <li><strong>Customise card layout</strong> — drag cards to reorder them, hide cards you never use, save your layout, or (global admins only) save your layout as the site-wide default for new users.</li>
                        <li>Click any card to jump straight into that admin page.</li>
                    </ul>
                    <h3 class="h6">How it connects</h3>
                    <p class="small">
                        Stats are live counts from the database — refresh the page to
                        see the latest. Hidden cards stay hidden until you re-show
                        them in <strong>Settings &rarr; Profile</strong>.
                    </p>
                    <div class="gotcha small">
                        <strong>Gotcha:</strong> Card customisation is per-user. A
                        global admin saving their layout as the site-wide default
                        affects only new users — existing users keep whatever they
                        already had.
                    </div>
                    <div class="gotcha small">
                        <strong>Role-gated sections:</strong> the dashboard
                        renders different bottom-of-page cards depending on your
                        role. Curators / Editors / Admins see a lightweight
                        <em>Your session</em> card with their role + username.
                        <strong>Global Admins</strong> see a richer <em>System Info</em>
                        card carrying PHP version, database driver and the
                        connected DB name — useful for triage but not relevant
                        to curators, so it's deliberately hidden from lower roles.
                    </div>
                </section>

                <!-- ====================================================================
                     CONTENT
                     ==================================================================== -->

                <section id="editor" class="help-section card-admin mb-4">
                    <h2><i class="bi bi-pencil-square me-2"></i>Song Editor</h2>
                    <p class="role-badges">
                        <span class="badge bg-primary">editor</span>
                        <span class="badge bg-warning text-dark">admin</span>
                        <span class="badge bg-danger">Global Admin</span>
                    </p>
                    <p>
                        The big one. The Song Editor is where you author and maintain
                        the catalogue. It loads a lightweight <em>index</em> of the
                        whole library (id / number / title / songbook) so the list and
                        filters are instant, then fetches each full song record
                        <strong>on demand</strong> from the database as you open it &mdash;
                        nothing materialises the entire corpus in your browser. Edit one
                        song or many at once and save straight back to MySQL.
                    </p>
                    <div class="alert alert-info small mb-3">
                        <i class="bi bi-info-circle me-1"></i>
                        <strong>This is the redesigned Song Editor</strong> &mdash; it's
                        now what opens by default. Every save applies the moment you make
                        it, rather than waiting for one big "Save" at the end, and this
                        page describes it throughout, including the Chords box and the
                        Arrangement editor covered below. If you ever need the previous
                        editor &mdash; for example to compare behaviour while you get used
                        to the new one &mdash; add <code>?legacy=1</code> to the Song
                        Editor's web address. It isn't going away yet, but it's no longer
                        the one you land on by default.
                    </div>
                    <h3 class="h6">Working with the catalogue</h3>
                    <ul>
                        <li><strong>Filter</strong> by songbook, search by title, or
                            sort by title / number / songbook+number using the
                            controls above the song list.</li>
                        <li>Click a song in the list to load it into the tabs on the
                            right (Metadata, Structure, Credits, Links, Tags, Media,
                            Preview).</li>
                        <li>Use <strong>Multi-select</strong> mode for bulk operations
                            (verify, tag, move to another songbook, export, delete) &mdash;
                            each action reports a per-song result, so a batch that partly
                            fails (e.g. moving ten songs when one is already at the target
                            songbook) still tells you exactly which songs succeeded.
                            <br><strong>Deleting is recoverable.</strong> A deleted song
                            disappears from the app, from search and from this sidebar, but it is not
                            destroyed &mdash; it moves to <a href="#deleted-songs">Deleted Songs</a>,
                            keeping its lyrics, credits, media and full revision history, and one click
                            puts it back. Permanent removal is a separate, deliberately harder step on
                            that page. Deleting is available to editors and above.</li>
                    </ul>
                    <h3 class="h6">The eight tabs</h3>
                    <dl class="actions">
                        <dt>Metadata</dt>
                        <dd>Title, song number, songbook, CCLI number, Tune Name (e.g. <em>HYFRYDOL</em> — a find-or-create picker into the <a href="#tunes">Tunes</a> registry, so typing a new tune name creates its registry row automatically), ISWC, language, region.
                            <p class="mt-2 mb-0"><strong>Musical key</strong> — the original key,
                            tempo in BPM and time signature. These show as a badge on the public song
                            page and give Transpose its starting point, so a musician can see what a
                            song is actually in before they play it. Tempo accepts 20–400 BPM; the key
                            and time-signature lists are fixed, so a typo cannot be saved.</p>
                            <p class="mt-2 mb-0"><strong>Copyright &amp; public domain (fills itself in)</strong> — type
                            <em>Copyright year(s)</em> and add <em>Copyright Holders</em> as an ordered list — a
                            find-or-create picker into the publisher registry (same as a songbook's Publisher
                            field) lets you add, remove and reorder as many holders as the song genuinely has;
                            the first-listed holder is the one the displayed copyright line uses. A live
                            <em>&ldquo;Displayed as: &hellip;&rdquo;</em> line shows exactly what will print on the
                            public page — no separate step, no free-text line to keep in sync by hand. Only if
                            you need a genuinely custom statement does a <em>&ldquo;Custom statement
                            (override)&rdquo;</em> disclosure let you type one directly; leaving it uses the
                            derived line. Two <strong>Public Domain</strong> checkboxes (lyrics / music) are never
                            auto-ticked, but when a credited writer's/composer's death date on file makes the
                            work look public domain (or, lacking that, when the publication year is old enough
                            — the threshold is an admin setting, see <a href="#configuration">Settings</a>) a
                            one-click <strong>Use</strong> hint appears next to the checkbox; you still decide.
                            Ticking both replaces the copyright line with &ldquo;Public domain&rdquo; and disables
                            the year/holder fields.</p></dd>
                        <dt>Structure</dt>
                        <dd>
                            The actual lyrics, broken into sections: verses, choruses, bridges, and so on. Drag to reorder; auto-resizing text areas grow as you type.
                            <p class="mt-2 mb-2">
                                <strong>Paste &amp; Reflow</strong> — the <em>Paste &amp; Reflow</em> button opens a modal where you paste a whole lyrics block; it auto-splits the text into classified sections (verse / chorus / bridge…) ProPresenter-style, and <strong>Apply</strong> turns them into song sections in one go — far quicker than adding each section by hand.
                            </p>
                            <p class="mb-2">
                                <strong>Chords</strong> — each section has a collapsible Chords box (it opens automatically once a section already has chords). Enter one line of chord symbols per lyric line, in reading order (e.g. <code>G  Em  C  D</code>); leave a section's box empty if it has none. Chords carry through to chord-chart export formats like ChordPro.
                            </p>
                            <p class="mb-2">
                                <strong>Per-line language, translations &amp; annotations</strong> — expand the per-line panel under the section to attach, line by line, a <em>translation</em> or <em>transliteration</em> (romanization) of a lyric line and Genius-style <em>annotations</em> (explanation / reference / scripture / history / trivia). These attach to the individual lyric line, not the section as a whole, and are saved as you add them (save the song first so each line is ready to attach to).
                            </p>
                            <p class="mb-2">
                                <strong>Custom section name</strong> — each section has a per-section <em>language</em> box and, beside it, a <em>Label</em> box. Type a Label to show a custom heading ("Kyrie", or a language name for a multilingual song) instead of the automatic "Verse 1 / Chorus"; the <em>Use language name</em> button fills it from the section's language in one click. It's display-only &mdash; the app still treats the section as its underlying type (verse, chorus…) for highlighting and for every export format, so nothing downstream breaks. Leave the Label empty to keep the automatic heading.
                            </p>
                            <p class="mb-2">
                                <strong>Source work (medleys)</strong> — for a medley, each section can point at the <a href="#works">Work</a> it excerpts, using the per-section "Source work" search-picker. This records which part of the medley came from which work; it only links to works that already exist (manage the works themselves on the <a href="#works">Works</a> page).
                            </p>
                            <p class="mb-2">
                                <strong>Arrangement</strong> — below the section list, the Arrangement panel sets the song's actual running order for playback and export: which sections play, in what sequence, and how many times each repeats (e.g. Verse 1, Chorus, Verse 2, Chorus, Bridge, Chorus). Add sections from the pool and reorder them with the move-left/move-right buttons, or start from a quick-action preset ("Verses only", "Chorus after each verse", …) and adjust from there. Leaving it empty plays the sections in the order they're listed above.
                            </p>
                            <p class="mb-2 small text-muted">
                                Each section's <strong>Type</strong> dropdown (verse / chorus / bridge / &hellip;) is sourced from a live registry rather than a fixed list, so a new section kind a curator needs (e.g. <em>Vamp</em>, <em>Ad-Lib</em>) doesn't require a code change to add.
                            </p>
                            <details class="mt-2">
                                <summary class="small text-muted" style="cursor: pointer;">Verse-1-acts-as-chorus convention (e.g. SDAH-93 "All Things Bright and Beautiful")</summary>
                                <div class="small text-muted mt-1">
                                    Some hymns open with a stanza that's structurally a refrain — the song repeats it after every verse — but the hymnal still numbers it as <em>Verse 1</em>. To set these up:
                                    <ol class="mb-0">
                                        <li>Set the first component's <strong>Type</strong> to <strong>Refrain</strong>, leaving its number as <code>1</code>.</li>
                                        <li>Click <strong>Chorus after each verse</strong> in the Arrangement quick-actions. Because the refrain comes before any verse, the arrangement starts <em>and</em> ends each cycle with the refrain — exactly the SDAH-93 playback pattern.</li>
                                    </ol>
                                    On the public song page, "Refrain" displays as "Chorus" via the standing alias so existing styling and screen-reader cues stay consistent.
                                </div>
                            </details>
                        </dd>
                        <dt>Credits</dt>
                        <dd>Writer, composer, arranger, adaptor, translator. Names autocomplete from the <a href="#musicians">Musicians</a> registry so you don't get duplicate spellings. (Copyright Holder lives on the <strong>Metadata</strong> tab, next to Copyright year(s) — see above.)</dd>
                        <dt>Links</dt>
                        <dd>External-website links for this song (Hymnary.org, Internet Archive scans, Wikipedia, YouTube performances, Spotify, etc.). Paste a URL and the provider auto-detects; see <a href="#external-links">External Links</a> for how the shared editor works.</dd>
                        <dt>Tags</dt>
                        <dd>Categorical tags (e.g. <em>Easter</em>, <em>Communion</em>) that drive Browse-by-Theme in the main app and can be used as targets for <a href="#restrictions">Content Restrictions</a>.</dd>
                        <dt>Media</dt>
                        <dd>Accompanying files for the song — audio recordings, sheet-music PDFs, MIDI sequences and MusicXML notation. Files inherit the song's content-access rules, so a gated song gates its media automatically, and each audio file has its own protected link so it can't be reached by guessing a web address.
                            <p class="mt-2 mb-0 small text-muted">The Metadata tab's &ldquo;Audio: yes/no &middot; Sheet music: yes/no&rdquo; line is <strong>read-only</strong> and fills itself in from whatever's actually attached here — there's no separate checkbox to remember to tick or untick; attach or remove a file on this tab and the line updates itself.</p></dd>
                        <dt>Preview</dt>
                        <dd>Read-only render of the finished song as users will see it.</dd>
                        <dt>Revisions</dt>
                        <dd>
                            Every previous edit to this song, newest first, showing what kind of change it was, when, and by whom, with a <strong>Restore</strong> button on each row. <strong>Restore puts the song back to the state that row's edit left it in</strong> (i.e. it re-applies that historical change) &mdash; if you're used to the previous editor, note this lands one step further forward than its Restore did, which undid the change instead. <strong>Click a row to see what changed</strong> &mdash; a field-by-field summary of exactly what that edit added, removed or altered (lyrics sections, credits, metadata and more), so you know what you're restoring before you commit to it. Restore always creates a new revision rather than rewriting history, so nothing is destroyed either way and you can step again from wherever you land.
                        </dd>
                    </dl>
                    <h3 class="h6">Saving, importing, exporting</h3>
                    <ul>
                        <li><strong>Save</strong> happens automatically as you go &mdash; each change saves itself the moment you make it (a few fields debounce briefly), so there's no single Save button and nothing to lose by navigating away.</li>
                        <li><strong>Validate</strong> runs every song past a quality check (missing required fields, invalid language tags, orphaned references) and lists any problems.</li>
                        <li><strong>Import</strong> from JSON or CSV — small, single-file. For mass onboarding (e.g. a complete new hymnal), see the <strong>Bulk Import ZIP</strong> section below.</li>
                        <li><strong>Export</strong> the current view as JSON or CSV.</li>
                        <li><strong>Revisions</strong> is now its own tab (see above) rather than a separate history button.</li>
                    </ul>
                    <h3 class="h6">Bulk Import ZIP</h3>
                    <p>
                        For onboarding an entire songbook at once. Upload a ZIP whose top-level folders match <code>&lt;Hymnal Name&gt; [&lt;ABBR&gt;]/</code> and whose files are one of:
                    </p>
                    <ul>
                        <li><strong>Plain text</strong> — <code>&lt;number&gt; (&lt;ABBR&gt;) - &lt;Title&gt;.txt</code> with the canonical title / blank / section-marker / lyric-block layout the scrapers emit. The number in the filename and folder ABBR cross-check; mismatches are reported per-entry.</li>
                        <li><strong>OpenSong XML</strong> — <code>.xml</code> or <code>.opensong</code> files anywhere inside the hymnal folder. The song number comes from the song's own number field first, then any leading digits in the filename, then a per-songbook auto-increment. The author field splits on <code>/</code>, <code>&amp;</code>, <code>,</code>, <code>;</code> into the writers list. Chord rows (lines beginning with <code>.</code>) and comment rows (lines beginning with <code>;</code>) are stripped from the lyrics.</li>
                    </ul>
                    <p>
                        Both file kinds may be mixed in the same archive — the importer dispatches per entry by extension. The summary shows how many of each format landed.
                    </p>
                    <ul>
                        <li><strong>Preview only (dry run):</strong> tick <em>"Preview only"</em> before uploading to see what the import would create or skip — songbooks, songs, matches — without saving anything, so you can sanity-check an unfamiliar bundle before committing to it.</li>
                        <li><strong>INSERT-only contract:</strong> if a songbook or song already exists, it's left untouched — never overwritten. The summary reports created vs. existing counts so you can see what landed.</li>
                        <li><strong>Live progress widget:</strong> the upload completes almost immediately; the actual import runs in the background. A small fixed-position card pinned bottom-right polls the job status, shows a progress bar, and survives navigation between admin pages and the public app. Hard-reload the page mid-import and the widget reattaches.</li>
                        <li><strong>Notification on completion:</strong> a row is written to <a href="#notifications">Notifications</a> when the worker finishes, and (if you've granted permission) a native browser notification fires.</li>
                        <li><strong>Caps:</strong> 100 MB upload, 100,000 entries per archive, 5 MiB per uncompressed entry, 500 MiB cumulative uncompressed. These are safety limits to stop an oversized upload from overwhelming the server — far above any real bundle.</li>
                    </ul>

                    <h3 class="h6">Worship-software import / export</h3>
                    <p>
                        Beyond the <code>.SourceSongData</code> / OpenSong ZIP layout above, the editor reads and writes the common church-presentation formats. <strong>Import</strong> is via the same <em>Import</em> button (a single file or a ZIP of them); <strong>export</strong> is via the per-format dropdowns next to it (this song, or the whole filtered songbook).
                    </p>
                    <ul>
                        <li><strong>OpenLP / OpenLyrics</strong> (<code>.xml</code>, <code>.osz</code>) — import + export. OpenLyrics carries its own songbook (<code>&lt;songbook name entry&gt;</code>), so single files don't need the folder convention.</li>
                        <li><strong>ProPresenter 6</strong> (<code>.pro6</code>) — import + export. Slide text is base64 RTF; round-trips both ways.</li>
                        <li><strong>FreeShow</strong> (<code>.show</code>) — import + export.</li>
                        <li><strong>VideoPsalm</strong> (<code>.json</code>) — import + export (a whole songbook is one JSON).</li>
                        <li><strong>OpenSong</strong> (<code>.xml</code>) — import (in ZIPs) + export.</li>
                        <li><strong>Proclaim</strong> (<code>.txt</code> / <code>.rtf</code>) — import + export (plain text, one song per file).</li>
                        <li><strong>ChordPro</strong> (<code>.cho</code> / <code>.pro</code> / <code>.chopro</code> / <code>.crd</code> / <code>.chord</code>) — import + export. One ChordPro document is one song (OnSong / OpenSong / WorshipTools interop). On import it files under a "ChordPro Import" songbook unless the filename uses the <code>&lt;#&gt; (&lt;ABBR&gt;) - &lt;Title&gt;</code> shape; the lyrics-only exporter round-trips with it.</li>
                        <li><strong class="text-warning">EasyWorship</strong> (its own song database format) — import + export. <span class="badge bg-warning text-dark">beta</span>
                            <div class="alert alert-warning small mt-1 mb-1">
                                <i class="bi bi-exclamation-triangle me-1"></i>
                                <strong>EasyWorship import/export is a beta, unverified feature.</strong> It reads and writes EasyWorship's own song file format and round-trips correctly within iHymns, but it has <em>not</em> been verified against a live EasyWorship install — a real EasyWorship may expect extra data when reading a file iHymns exported. Treat results as provisional and check them in EasyWorship before relying on them.
                            </div>
                        </li>
                    </ul>
                    <p>
                        <strong>Dedupe on import</strong>: tick <em>"Skip existing (by title)"</em> next to the Import button to skip any incoming song whose title already exists in the same songbook (matched ignoring case, punctuation and accents) — catches duplicates that carry a different number. Imports are always INSERT-only; existing rows are never overwritten.
                    </p>
                    <p>
                        <strong>Rights &amp; identifiers carry over</strong>: whatever copyright line, CCLI number, ISWC and public-domain markings a source file supplies are now kept on import &mdash; for every format &mdash; instead of being silently dropped. Imported songs therefore show up correctly in the <a href="#ccli-report">CCLI Usage Report</a> and can match themselves to an existing <a href="#works">Work</a> by their identifier.
                    </p>
                    <p>
                        <strong>Lines per slide</strong>: the <em>"Lines/slide"</em> box next to the export dropdowns caps how many lyric lines land on each slide when exporting to the presentation formats (ProPresenter 6 / FreeShow / OpenLP / OpenSong / VideoPsalm) — useful for lower-third layouts. <code>0</code> keeps each verse whole. Your value is remembered as your default for next time.
                    </p>

                    <h3 class="h6">Language tagging (IETF BCP 47)</h3>
                    <p>
                        The Metadata tab's Language field is a composite IETF picker — three sub-fields that compose into a single saved tag:
                    </p>
                    <ul>
                        <li><strong>Language</strong> (required) — e.g. <em>English</em> (<code>en</code>) or <em>Portuguese</em> (<code>pt</code>).</li>
                        <li><strong>Script</strong> (optional) — only when the script differs from the language default. e.g. <em>Simplified Chinese</em> for Mandarin written in Hans, or <em>Latin</em> for Serbian written Latn instead of the default Cyrl.</li>
                        <li><strong>Region</strong> (optional) — e.g. <em>United Kingdom</em> for British English (<code>en-GB</code>) vs. <em>United States</em> for American English (<code>en-US</code>).</li>
                    </ul>
                    <p>
                        The "IETF tag:" line below the picker shows the composed tag live as you type, with a human-readable rendering next to it (e.g. <em>"Spanish (Mexico)"</em> for <code>es-MX</code>). The full ISO 639 / ISO 15924 / ISO 3166-1 vocabulary — every IANA-registered subtag — is loaded from the same reference data the songbook editor's identical picker uses, so both stay in sync.
                    </p>
                    <p class="small text-muted mb-2">
                        The full IANA Language Subtag Registry plus CLDR English display names ship bundled with the app. <a href="/manage/setup-database#bcp47">Database Setup → "Refresh BCP 47 reference data"</a> has a live-fetch button if you need to pull the latest IANA / CLDR updates.
                    </p>
                    <div class="gotcha small">
                        <strong>Gotcha:</strong> Closing the tab while there are unsaved changes loses them — auto-save catches most things, but treat Save as the source of truth.
                    </div>
                    <div class="gotcha small">
                        <strong>Gotcha:</strong> A song's ID is set when it's first created and never changes. Renaming the title doesn't rename the ID. Numbering is independent of ID.
                    </div>
                    <div class="gotcha small">
                        <strong>Gotcha:</strong> Deleting a song is permanent. Use <strong>Revisions &rarr; Restore</strong> if you need an old version <em>before</em> you save further edits over it.
                    </div>
                </section>

                <section id="requests" class="help-section card-admin mb-4">
                    <h2><i class="bi bi-lightbulb me-2"></i>Song Requests</h2>
                    <p class="role-badges">
                        <span class="badge bg-primary">editor</span>
                        <span class="badge bg-warning text-dark">admin</span>
                        <span class="badge bg-danger">Global Admin</span>
                    </p>
                    <p>
                        End users in the main app can submit a song request via the dedicated <a href="/request">/request</a> page &mdash; reachable from the &ldquo;Report a missing song or suggest a correction&rdquo; link at the bottom of every song page, the &ldquo;Suggest a Missing Song&rdquo; CTA on <a href="/help">/help</a>, and a deep-link from the editor's missing-numbers tool with the songbook + number prefilled. Submissions queue offline and replay automatically when the user is back online; each submission also returns a tracking ID the user can quote when following up. All paths land in this triage list.
                    </p>
                    <h3 class="h6">Key actions</h3>
                    <ul>
                        <li><strong>Filter</strong> by status: Pending, Reviewed, Added, Declined, or All.</li>
                        <li>Change a request's status inline.</li>
                        <li>Add an admin <strong>note</strong> (e.g. "merged with #1234", "no copyright clearance").</li>
                        <li>If the request was fulfilled by an existing song, use <strong>Resolved Song ID</strong> &mdash; a live search-select picker (type a title, pick the song) rather than a free-text box to paste an ID into, so you can't typo a SongId that doesn't exist.</li>
                        <li>Click <strong>Start editing</strong> to open the editor pre-loaded with a draft song matching the request, with a back-link to the request.</li>
                    </ul>
                    <div class="gotcha small">
                        <strong>Gotcha:</strong> There's no bulk update. Long queues take time. Filter by Pending and work top-down.
                    </div>
                </section>

                <section id="revisions" class="help-section card-admin mb-4">
                    <h2><i class="bi bi-clock-history me-2"></i>Edit History</h2>
                    <p class="role-badges">
                        <span class="badge bg-warning text-dark">admin</span>
                        <span class="badge bg-danger">Global Admin</span>
                    </p>
                    <p>
                        A read-only audit trail of every song edit, ever. Useful for "who changed Amazing Grace last Tuesday?" and as the entry point for restoring an older version.
                    </p>
                    <h3 class="h6">Key actions</h3>
                    <ul>
                        <li>Filter by user, song ID (partial match works), action (create / edit / restore / delete), and time range (7 / 30 / 90 / 365 days).</li>
                        <li>Click <strong>Open in editor</strong> on a row to jump straight into that song with the Revisions tab already open, listing every previous edit with a Restore button on each.</li>
                        <li>Inside the editor's Revisions tab, a <strong>Field history</strong> view sits alongside the time-ordered list: one row <em>per field</em> showing who last changed it and when, so &ldquo;who edited this copyright line?&rdquo; no longer means reading through unrelated edits. Each changed field also gets a per-field <strong>Revert</strong> that undoes just that one field &mdash; written as a normal new edit, never rewriting history &mdash; without discarding the other changes made since.</li>
                    </ul>
                    <div class="gotcha small">
                        <strong>Gotcha:</strong> Revisions are immutable. Restore creates a <em>new</em> revision rather than rewriting history, so the trail stays honest.
                    </div>
                </section>

                <section id="deleted-songs" class="help-section card-admin mb-4">
                    <h2><i class="bi bi-trash3 me-2"></i>Deleted Songs</h2>
                    <p class="role-badges">
                        <span class="badge bg-primary">editor</span>
                        <span class="badge bg-warning text-dark">admin</span>
                        <span class="badge bg-danger">Global Admin</span>
                    </p>
                    <p>
                        Deleting a song no longer destroys it. A delete is
                        <strong>recoverable</strong>: the song is hidden from every public and editorial
                        surface &mdash; the app, search, songbook lists, the editor sidebar, exports &mdash;
                        but the record itself is untouched. Its lyrics, credits, media links, tags and
                        complete edit history are all still there. This page is where those songs wait.
                    </p>
                    <h3 class="h6">Key actions</h3>
                    <dl class="actions">
                        <dt>Restore</dt>
                        <dd>
                            Puts the song straight back exactly as it was. Because deleting writes nothing
                            else anywhere &mdash; no redirects, no cascades &mdash; there is nothing to
                            repair on the way back: favourites, set lists, Work membership and edit
                            history were never touched.
                        </dd>
                        <dt>Purge</dt>
                        <dd>
                            The old permanent delete, and the <em>only</em> way to reach it. A live song can
                            no longer be destroyed in one step by anybody &mdash; it has to be deleted
                            first, then purged from this page. Purge takes the edit history with it and
                            cannot be undone, so it asks you to type the song's ID to confirm. You can
                            optionally point the purged ID at a surviving song, so anyone following an old
                            link or bookmark lands somewhere sensible instead of a dead end.
                        </dd>
                    </dl>
                    <h3 class="h6">Who can do what</h3>
                    <ul>
                        <li>Seeing this page and using <strong>Restore</strong> is available to
                            <strong>editors and above</strong> &mdash; the same permission needed to delete
                            a song in the first place. Deletion was briefly restricted to
                            admins only while it was still permanent; now that it is recoverable, that
                            restriction has been lifted.</li>
                        <li><strong>Purge</strong> needs its own separate, higher permission,
                            which stays with admins and global admins. This is
                            exactly why the two were split: recoverable deletion could widen to editors
                            without the irreversible one coming along for the ride.</li>
                        <li>Every deletion and restore <strong>notifies everyone who can purge</strong>,
                            so nothing sits in here unnoticed. You are not notified about your own
                            actions.</li>
                    </ul>
                    <div class="gotcha small">
                        <strong>Gotcha:</strong> A deleted song still holds its number.
                        <a href="#missing-numbers">Missing Numbers</a> deliberately counts hidden songs as
                        present, so it will not offer you the slot &mdash; if it did, you could fill the gap
                        and then find you had two songs on one number the moment somebody hit Restore.
                        Restore or purge the song to genuinely free its number.
                    </div>
                </section>

                <section id="missing-numbers" class="help-section card-admin mb-4">
                    <h2><i class="bi bi-binoculars me-2"></i>Missing Numbers</h2>
                    <p class="role-badges">
                        <span class="badge bg-primary">editor</span>
                        <span class="badge bg-warning text-dark">admin</span>
                        <span class="badge bg-danger">Global Admin</span>
                    </p>
                    <p>
                        For every songbook, shows the highest number assigned, the count of songs present, and the gaps in numbering. Long gaps are collapsed into ranges (e.g. "<code>#400&ndash;#500 &middot; 101 missing</code>") so the page stays readable.
                    </p>
                    <h3 class="h6">When to use it</h3>
                    <p class="small">
                        Spot songs that haven't been added yet, find renumbering gaps after deletions, or verify that a freshly-imported songbook is complete.
                    </p>
                    <h3 class="h6">Numbers held by hidden songs</h3>
                    <p>
                        The page also highlights numbers whose only song has been deleted (and so is hidden rather than gone — see <a href="#deleted-songs">Deleted Songs</a>). A &ldquo;Held by hidden songs&rdquo; panel, a count tile, and a badge on each affected songbook point you to these, with a link straight through to Deleted Songs so you can decide whether to restore the song or remove it for good. A number that still has at least one visible song is never flagged here.
                    </p>
                    <div class="gotcha small">
                        <strong>Gotcha:</strong> A hidden song's number is deliberately counted as <em>present</em>, not offered as a gap. If it were offered, you could fill the gap with a new song and then find you had two songs on one number the moment somebody restored the hidden one.
                    </div>
                    <div class="gotcha small">
                        <strong>Gotcha:</strong> The report is read-only. Use the Song Editor to actually fill the gaps.
                    </div>
                </section>

                <section id="songbooks" class="help-section card-admin mb-4">
                    <h2><i class="bi bi-book me-2"></i>Songbooks</h2>
                    <p class="role-badges">
                        <span class="badge bg-warning text-dark">admin</span>
                        <span class="badge bg-danger">Global Admin</span>
                    </p>
                    <p>
                        Songbooks are the top-level container — every song lives in exactly one. This page is where you create, name, colour, and order them.
                    </p>
                    <h3 class="h6">Per-songbook fields</h3>
                    <dl class="actions">
                        <dt>Abbreviation</dt><dd>Short identifier (e.g. <code>HYM</code>). Unique. Max 10 chars, alphanumeric. This is the natural key referenced by every song.</dd>
                        <dt>Name</dt><dd>Friendly name (e.g. "Methodist Hymnal").</dd>
                        <dt>Display order</dt><dd>Numeric sort key — lower numbers appear first in the app.</dd>
                        <dt>Colour</dt><dd>Hex code (<code>#RRGGBB</code>) used as the songbook tile colour on the home page. <strong>Leave blank</strong> to let the system auto-pick a tone from the current theme palette — the result is consistent with the rest of the UI and changes with the user's chosen theme.</dd>
                        <dt>Official flag</dt><dd>Marks "real" published hymnals vs. user-curated collections / pseudo-songbooks. Used by the home-page filter to separate the two surfaces.</dd>
                        <dt>Publisher / Publication year / Copyright</dt><dd>Issuing body, year of publication, and the copyright statement. Optional, surface in search and reports.</dd>
                        <dt>Affiliation</dt><dd>Issuing organisation, drawn from a curated registry. Type to search; new affiliations get added on save. Use this rather than free-text Publisher when the same organisation issues multiple songbooks.</dd>
                        <dt>Language (IETF BCP 47)</dt><dd>The songbook's primary language as a composite IETF tag — same picker as the song editor, with three sub-fields: <strong>Language</strong> (required), <strong>Script</strong> (optional — only when the script differs from the language default), and <strong>Region</strong> (optional — e.g. <code>en-GB</code> vs <code>en-US</code>). Leave blank for multi-lingual collections.</dd>
                        <dt>Online links — Official website / Internet Archive / Wikipedia</dt><dd>Free-text URLs. Used as outbound references on the songbook detail page so users can verify the source.</dd>
                        <dt>Authority identifiers — WikiData ID, OCLC, OCN, LCP, ISBN, ARK, ISNI, VIAF, LCCN, LC Class</dt><dd>Standard cataloguing identifiers from major library and authority systems. All optional. Useful for cross-referencing and de-duplicating against external catalogues.</dd>
                    </dl>
                    <h3 class="h6">Renaming an abbreviation</h3>
                    <p>
                        The abbreviation is what every song references, so renaming is opt-in: you must tick the <strong>"Also rename song references"</strong> checkbox to cascade the rename to every song that uses it. Without that checkbox, songs keep the old abbreviation and orphan from the renamed songbook.
                    </p>
                    <h3 class="h6">Colour picker</h3>
                    <p>
                        The <strong>Colour</strong> field accepts a 7-char <code>#RRGGBB</code> hex value. The browser-native colour picker writes the canonical lower-case hex back into the text field when you confirm a swatch — handy if you want to copy the value into another tool. Leave the field blank to let the system auto-pick a tone the catalogue isn't already using; the next save fills the field in for you.
                    </p>
                    <h3 class="h6">Auto-colour bulk action</h3>
                    <p>
                        Two destructive-but-recoverable buttons live at the top of the songbook list:
                    </p>
                    <dl class="actions">
                        <dt>Auto-fill blank colours</dt>
                        <dd>Walks every songbook; rows with a blank or invalid colour get a fresh palette pick. Existing valid colours are left alone — safe to run more than once.</dd>
                        <dt>Reassign every colour</dt>
                        <dd>Overwrites every songbook's colour, regardless of whether it was set already. Protected by typing the literal phrase <strong>REASSIGN ALL</strong> — an extra safeguard so a stray click never re-themes the whole catalogue.</dd>
                    </dl>
                    <h3 class="h6">Cascade delete</h3>
                    <p>
                        The default Delete refuses if any song still references the songbook abbreviation. Admins and above can use <strong>Cascade delete</strong> instead, which removes the songbook AND every song in it AND every credit / tag / chord / translation that referenced those songs. Server-side typed-confirmation gate: the curator must type the songbook abbreviation exactly. The database handles the rest cleanly in one step.
                    </p>
                    <div class="gotcha small">
                        <strong>Gotcha:</strong> Deleting a songbook does <em>not</em> delete its songs unless you use Cascade delete. The standard UI refuses if any song still references its abbreviation; reassign or delete those songs first.
                    </div>
                    <div class="gotcha small">
                        <strong>Tip:</strong> The home-page tile grid shows official hymnals first, with a language filter that lets users pick which languages to <em>show</em> across both songbook tiles AND individual song listings (search, popular, recently-viewed). Multi-select is supported; signed-in users get the choice persisted to their account and synced across devices. The <strong>Misc</strong> pseudo-songbook is always pinned to the bottom of the grid regardless of its position setting — it's a catch-all and should never out-rank a curated hymnal. Songbooks AND songs without a Language field always show, regardless of the filter — useful for catch-all collections.
                    </div>
                </section>

                <section id="ia-reconcile" class="help-section card-admin mb-4">
                    <h2><i class="bi bi-archive me-2"></i>Scan Import</h2>
                    <p class="role-badges">
                        <span class="badge bg-primary">editor</span>
                        <span class="badge bg-warning text-dark">admin</span>
                        <span class="badge bg-danger">Global Admin</span>
                    </p>
                    <div class="alert alert-info small">
                        <i class="bi bi-info-circle me-1"></i>
                        <strong>Read-only.</strong> This tool <em>never</em> adds, edits, or deletes a song. It only produces a report you read. A curator-approved import path is a separate, later phase.
                    </div>
                    <p>
                        Pick a songbook and an <a href="https://archive.org" target="_blank" rel="noopener noreferrer">archive.org</a> scan, then <strong>Run reconcile</strong>. iHymns fetches the item's scanned-text version, segments it into likely hymns, and scores each against the songs already in that songbook. Available to editors and above — the curators who work the gap list.
                    </p>
                    <h3 class="h6">What the report shows</h3>
                    <dl class="actions">
                        <dt>Exact / Strong</dt><dd>A hymn in the scan that clearly matches a song already in iHymns.</dd>
                        <dt>Review</dt><dd>A plausible-but-uncertain match for a human to judge.</dd>
                        <dt>Gap</dt><dd>The whole point of the tool: a hymn that appears in the scan but seems to be <em>missing</em> from your songbook.</dd>
                        <dt>Unmatched in book</dt><dd>A song in the songbook that was never found in the scan — maybe bad scan quality, maybe missing pages.</dd>
                    </dl>
                    <div class="gotcha small">
                        <strong>Gotcha:</strong> Results persist between visits so you don't re-fetch the same large scan — but they are audit bookkeeping, not song content. The archive.org fetch is locked to that one site and hardened against misuse.
                    </div>
                </section>

                <section id="songbook-series" class="help-section card-admin mb-4">
                    <h2><i class="bi bi-collection-fill me-2"></i>Songbook Series</h2>
                    <p class="role-badges">
                        <span class="badge bg-warning text-dark">admin</span>
                        <span class="badge bg-danger">Global Admin</span>
                    </p>
                    <p>
                        A <strong>Series</strong> groups songbooks that belong together as equals &mdash; volumes of one collection (e.g. <em>Songs of Fellowship</em> 1 / 2 / 3 / 4) or a themed compilation where no single book is the &ldquo;root.&rdquo; It's the peer-to-peer counterpart to the parent-songbook hierarchy: link a songbook to a parent when one book contains another, use a Series when several books simply sit side by side. Available to the same admins who manage Songbooks.
                    </p>
                    <h3 class="h6">Key actions</h3>
                    <dl class="actions">
                        <dt>Create / Edit</dt><dd>Name the series, optionally describe it, and set a display order. Membership is a two-pane picker over every songbook &mdash; add or remove member books at will.</dd>
                        <dt>Delete</dt><dd>Removes the series and its membership rows. The member <em>songbooks</em> (and their songs) are untouched &mdash; a series only references them.</dd>
                    </dl>
                    <div class="gotcha small">
                        <strong>Gotcha:</strong> A series never owns songs and never moves them. It's a presentation grouping over songbooks; deleting it changes nothing about where any song lives.
                    </div>
                </section>

                <section id="print-templates" class="help-section card-admin mb-4">
                    <h2><i class="bi bi-printer me-2"></i>Print Templates</h2>
                    <p class="role-badges">
                        <span class="badge bg-warning text-dark">admin</span>
                        <span class="badge bg-danger">Global Admin</span>
                    </p>
                    <p>
                        The editor for the block-based layouts the song <em>Print</em> path uses. A template is an ordered list of blocks (title, lyrics, credits, copyright, etc.) plus page options. Curated/global templates have no owner and are offered to everyone. Available to admins who manage Songbooks.
                    </p>
                    <h3 class="h6">Key actions</h3>
                    <ul>
                        <li><strong>Create / Edit</strong> a template by arranging blocks and setting page options.</li>
                        <li><strong>Live preview</strong> renders through the <em>same</em> renderer the print path uses, so what you see is byte-identical to the printed page &mdash; one source of truth, no &ldquo;looked fine in the editor, broke on paper.&rdquo;</li>
                        <li><strong>Clone</strong> a template to start from an existing one; <strong>import / export</strong> a template as JSON to move it between installs; pick a <strong>system default</strong> offered when no other template is chosen.</li>
                        <li><strong>Delete</strong> a template you no longer want offered.</li>
                        <li><strong>Organisation logo</strong> block — add your organisation's logo to a template and pick which shape to use, or leave it on <strong>Automatic</strong> to use the best one available; see <a href="#organisations">Organisations</a> for how to upload one. If the chosen shape hasn't been uploaded, nothing prints there — never a broken image.</li>
                    </ul>
                    <h3 class="h6 mt-3">Org-scoped templates</h3>
                    <p>
                        A template can be owned by an <strong>organisation</strong> so a church's own layouts appear only to its members, alongside the curated/global ones. Leave the owner blank for a global template.
                    </p>
                    <h3 class="h6 mt-3">Download PDF</h3>
                    <p>
                        Signed-in users get a <strong>Download PDF</strong> button beside Print. Instead of relying on the browser's print dialog, the server renders the same layout to a real PDF. If that isn't available the app quietly falls back to browser Print &mdash; never an error page.
                    </p>
                    <h3 class="h6 mt-3">Custom full-page HTML layouts</h3>
                    <p>
                        Beyond the block list, a template can be a <strong>full-page HTML layout</strong> you upload. It passes through a safety check on save <em>and</em> at render time: only safe markup and inline styles survive &mdash; scripts, event handlers, embedded frames, forms, and any fetch of an outside image, stylesheet or font are all stripped. Design for print, not interactivity.
                    </p>
                    <h3 class="h6 mt-3">CCLI print-usage logging</h3>
                    <p>
                        When the signed-in user's organisation holds a CCLI licence and the song carries a CCLI number, printing or downloading prompts for a <strong>copies count</strong>, logs it against the org's <a href="#ccli-report">CCLI Usage Report</a>, and adds the required CCL notice to the printed <strong>footer</strong> automatically. The licence is re-resolved on the server &mdash; the client can't claim the count for a song it isn't entitled to.
                    </p>
                    <div class="gotcha small">
                        <strong>Gotcha:</strong> Browser Print, the server PDF, the batch set-list PDF, and this page's live preview all go through the <em>same</em> renderer, so what you design here is exactly what every one of those paths produces.
                    </div>
                </section>

                <section id="musicians" class="help-section card-admin mb-4">
                    <h2><i class="bi bi-person-vcard me-2"></i>Musicians</h2>
                    <p class="role-badges">
                        <span class="badge bg-warning text-dark">admin</span>
                        <span class="badge bg-danger">Global Admin</span>
                    </p>
                    <p>
                        A registry of every named person credited on a song — writers, composers, arrangers, adaptors, translators. Lets you fix the &ldquo;<em>J. Newton</em> vs <em>John Newton</em>&rdquo; problem in one place instead of in every song that mentions them.
                    </p>
                    <h3 class="h6">Key actions</h3>
                    <dl class="actions">
                        <dt>Add</dt><dd>Pre-register a canonical spelling (with optional birth/death info, IPI numbers, external links) so it appears in the editor's autocomplete from day one.</dd>
                        <dt>Edit</dt><dd>Update biographical info, links, IPI numbers. Editing a name applies to all songs that cite it.</dd>
                        <dt>Rename</dt><dd>Change the canonical name and cascade the change atomically across every song that cites it.</dd>
                        <dt>Merge</dt><dd>Collapse two registry entries into one. Pick which row survives; all credits on songs are re-pointed to the survivor and the duplicate is deleted.</dd>
                        <dt>Delete</dt><dd>Remove from the registry. Refuses by default if any song still cites them; force-delete is available behind a confirmation.</dd>
                        <dt>View Songs</dt><dd>Modal showing every song that cites a person, grouped by role (writer, composer, &hellip;).</dd>
                    </dl>
                    <div class="gotcha small">
                        <strong>Gotcha:</strong> Rename and Merge are atomic — either every credit on every song updates, or none does. Half-finished states are not possible.
                    </div>
                    <h3 class="h6 mt-3">Bulk promote</h3>
                    <p>
                        When a fresh deployment has hundreds of typed credit names that haven't been registered, click <strong>Bulk promote with fuzzy-match</strong> on the Musicians page header. The bulk page surfaces every name cited on at least one song that doesn't have a registry row, scores each against the existing registry rows (and against other candidates), and lets you pick per-row: <em>Register as new</em>, <em>Merge into existing</em> (re-points every credit on every song to the canonical row's name), or <em>Skip</em>. The whole submit runs as one batch, logged as a single run so you can review it as a unit.
                    </p>
                    <h3 class="h6 mt-3">Musician Duplicates review</h3>
                    <p>
                        From the Musicians header, <strong>Review musician duplicates &rarr;</strong> opens a review surface that finds registry rows that look like the same person (e.g. <em>J. Newton</em> vs <em>John Newton</em>) using the shared similarity scorer, and lets you <strong>Merge</strong> them or <strong>Dismiss</strong> a pair as &ldquo;different person&rdquo; (dismissals persist). Available to the same admins as this page.
                    </p>
                </section>

                <section id="tunes" class="help-section card-admin mb-4">
                    <h2><i class="bi bi-music-note-beamed me-2"></i>Tunes</h2>
                    <p class="role-badges">
                        <span class="badge bg-warning text-dark">admin</span>
                        <span class="badge bg-danger">Global Admin</span>
                    </p>
                    <p>
                        A registry of hymn <strong>tunes</strong> as first-class entities &mdash; <em>HYFRYDOL</em>, <em>OLD HUNDREDTH</em> &mdash; separate from any one song's lyrics. The same tune is often set to several different texts across hymnals, so this fixes the &ldquo;same melody, five spellings&rdquo; problem the same way the Musicians and Publishers registries do for their own kinds of entity.
                    </p>
                    <h3 class="h6">Per-tune fields</h3>
                    <dl class="actions">
                        <dt>Name, Subtitle, Disambiguation</dt><dd>The tune's canonical name, an optional subtitle, and a short disambiguator for two different tunes that happen to share a name.</dd>
                        <dt>Meter Code</dt><dd>The hymn-metre pattern (e.g. <code>8.7.8.7 D</code>) that tells you which texts can be sung to this tune.</dd>
                        <dt>Aliases</dt><dd>Alternate names the same tune is known by; any of them resolves to this registry row.</dd>
                        <dt>Credits</dt><dd>Composer, arranger, harmoniser, source &mdash; each optionally linked to a <a href="#musicians">Musicians</a> registry row instead of a bare typed name.</dd>
                        <dt>External links &amp; identifiers</dt><dd>Hymnary.org / MusicBrainz Work / other reference links, auto-detected from a pasted URL the same way every other registry's link picker works.</dd>
                    </dl>
                    <h3 class="h6">Key actions</h3>
                    <dl class="actions">
                        <dt>Create / Edit</dt><dd>The song and songbook editors' Tune field is itself a find-or-create picker into this same registry &mdash; typing a new tune name there and saving creates the registry row automatically, same as typing a new Musician name does.</dd>
                        <dt>Merge</dt><dd>Collapse two tune rows into one; every song and Work pointing at the old row re-points to the survivor.</dd>
                        <dt>Delete</dt><dd>Refuses by default while any song or Work still cites the tune.</dd>
                    </dl>
                    <div class="gotcha small">
                        <strong>Public page:</strong> every tune has a page at <code>/tune/&lt;slug&gt;</code> listing every song that uses it &mdash; handy for finding every hymn set to a familiar melody.
                    </div>
                </section>

                <section id="publishers" class="help-section card-admin mb-4">
                    <h2><i class="bi bi-building me-2"></i>Publishers</h2>
                    <p class="role-badges">
                        <span class="badge bg-warning text-dark">admin</span>
                        <span class="badge bg-danger">Global Admin</span>
                    </p>
                    <p>
                        A registry of songbook <strong>publishers</strong> — persons <em>and</em> companies — promoted from the old free-text songbook field. Like the Musicians registry, it fixes the &ldquo;same publisher, five spellings&rdquo; problem in one place. Available to admins who manage Publishers.
                    </p>
                    <h3 class="h6">Per-publisher fields</h3>
                    <dl class="actions">
                        <dt>Name &amp; Kind</dt><dd>The publisher's name and whether it's a person, company, imprint, etc. (a curated <em>Kind</em> vocabulary).</dd>
                        <dt>Parent</dt><dd>Optional self-reference for an <strong>imprint</strong> under a parent house, or a catalogue grouping.</dd>
                        <dt>Linked musician</dt><dd>If the publisher is also a credited person, link the existing <a href="#musicians">Musicians</a> row instead of duplicating them.</dd>
                        <dt>City, identifiers, aliases</dt><dd>Optional place, standard identifiers (IPI / ISNI), and alternate spellings (aliases) that resolve to this publisher.</dd>
                    </dl>
                    <h3 class="h6">Key actions</h3>
                    <dl class="actions">
                        <dt>Rename</dt><dd>Cascades the new name across everything that cites it, atomically.</dd>
                        <dt>Merge</dt><dd>Collapse two publishers into one survivor; references re-point and the duplicate is removed.</dd>
                        <dt>Delete</dt><dd>Refuses by default while a songbook still cites the publisher.</dd>
                    </dl>
                    <p class="small">
                        The songbook editor's <strong>multi-publisher picker</strong> lets a book credit several publishers (each with a role). On save it updates the book's displayed publisher text to match the <em>primary</em> (first-listed) publisher — the registry is the source of truth; the displayed text just follows it.
                    </p>
                    <div class="gotcha small">
                        <strong>Public page:</strong> every publisher has a page at <code>/publisher/&lt;slug&gt;</code> listing the books it published; the admin list and the editor picker emit that link.
                    </div>
                </section>

                <section id="duplicate-songs" class="help-section card-admin mb-4">
                    <h2><i class="bi bi-files me-2"></i>Find Duplicates</h2>
                    <p class="role-badges">
                        <span class="badge bg-primary">editor</span>
                        <span class="badge bg-warning text-dark">admin</span>
                        <span class="badge bg-danger">Global Admin</span>
                    </p>
                    <p>
                        The unified review surface for songs that look like the same hymn appearing in more than one place. It folds together exact duplicates (same normalised title) and fuzzy counterparts scored across books. This page also absorbed the older, separate suggestions list, so it's the one place to review both.
                    </p>
                    <h3 class="h6">How candidates are found</h3>
                    <p class="small">
                        Scoring uses the same shared matching engine everywhere it's needed in iHymns — the editor's inline panel and this page's background scoring both use it, comparing titles for closeness after normalising them. A nightly background job scores likely pairs across the whole catalogue and adds them to this list automatically; exact-title clusters are detected live.
                    </p>
                    <h3 class="h6">Per-action what-it-does</h3>
                    <dl class="actions">
                        <dt>Link <span class="badge bg-primary">editors and above</span></dt><dd>Records that two songs in <em>different</em> books are the same hymn so the public site can cross-reference them. Non-destructive.</dd>
                        <dt>Dismiss <span class="badge bg-primary">editors and above</span></dt><dd>Hides a pair you've judged <em>not</em> a duplicate. A cluster only disappears once <em>all</em> its pairs are dismissed.</dd>
                        <dt>Merge <span class="badge bg-warning text-dark">admins and above</span></dt><dd>The destructive one: collapses two song records into one. Merging two songs that share the <em>same official songbook</em> additionally requires typing a confirmation phrase because that's the riskiest case.</dd>
                    </dl>
                    <div class="gotcha small">
                        <strong>Gotcha:</strong> Link and Dismiss are reversible bookkeeping; Merge is not. Use Link when both copies should stay (e.g. the same hymn in two hymnals); reserve Merge for genuine accidental duplicates.
                    </div>
                </section>

                <section id="works" class="help-section card-admin mb-4">
                    <h2><i class="bi bi-diagram-3 me-2"></i>Works</h2>
                    <p class="role-badges">
                        <span class="badge bg-warning text-dark">admin</span>
                        <span class="badge bg-danger">Global Admin</span>
                    </p>
                    <p>
                        A <strong>Work</strong> groups multiple songs that represent the same underlying composition across different songbooks / arrangements / translations &mdash; mirrors the <a href="https://musicbrainz.org/doc/Work" target="_blank" rel="noopener noreferrer">MusicBrainz Work</a> &harr; Recording relationship. So <em>Amazing Grace</em>, which appears in dozens of hymnals under slightly different titles, lives as one Work with each songbook entry as a member.
                    </p>
                    <h3 class="h6">Key actions</h3>
                    <dl class="actions">
                        <dt>Create</dt><dd>Title + slug (auto from title) + optional ISWC + optional parent Work + optional notes. Members are added via the Edit modal once the row exists.</dd>
                        <dt>Edit</dt><dd>Add / remove member songs (typeahead over the whole catalogue), mark one as <em>canonical</em>, set sort order, attach external links (the provider dropdown auto-detects from the URL).</dd>
                        <dt>Constituent works (medley)</dt><dd>Define a Work as an ordered list of other Works &mdash; a medley. In the Edit modal, the <strong>Constituent works (medley)</strong> section takes a work-search typeahead; add each constituent and set its order. This is a &ldquo;contains&rdquo; relationship (distinct from the parent/variant Nesting below): the song page and public work page then show a read-only &ldquo;Medley of: A, B, C&rdquo; line, and a song's individual sections can point at the constituent they excerpt (the <a href="#editor">Song Editor</a> Structure tab's per-section &ldquo;Source work&rdquo; picker).</dd>
                        <dt>Delete</dt><dd>Memberships and external links are removed along with the Work. Child Works (if any) become independent rather than being deleted too.</dd>
                    </dl>
                    <h3 class="h6">Nesting</h3>
                    <p>
                        Works can be nested without limit: an original Work can have child Works for derivative arrangements, translations, choral versions, etc., each of which can in turn have its own children. Cycles are blocked server-side at update time (no Work can become its own ancestor).
                    </p>
                    <h3 class="h6">ISWC</h3>
                    <p>
                        The ISWC (<code>T-NNN.NNN.NNN-C</code>) is the international identifier for a musical composition, registered with CISAC societies (BMI, ASCAP, PRS, &hellip;). It's optional &mdash; many traditional hymns predate the system, and many newer compositions haven't been registered. When supplied, the field shape-validates and canonicalises to the standard format.
                    </p>
                    <div class="gotcha small">
                        <strong>Gotcha:</strong> The same song <em>can</em> belong to multiple Works (e.g. a medley arrangement that quotes two compositions), but it's rare and usually a misclassification. The list view's "Members" column is the quickest sanity check.
                    </div>
                </section>

                <section id="tags" class="help-section card-admin mb-4">
                    <h2><i class="bi bi-tags me-2"></i>Tags &amp; Themes</h2>
                    <p class="role-badges">
                        <span class="badge bg-warning text-dark">admin</span>
                        <span class="badge bg-danger">Global Admin</span>
                    </p>
                    <p>
                        The registry of the categorical tags (e.g. <em>Easter</em>, <em>Communion</em>, <em>Grace</em>) that drive Browse-by-Theme in the main app and can be used as targets for <a href="#restrictions">Content Restrictions</a>. Curators add, rename, and merge tags here; the editor's Tags tab just attaches them to songs. Available to admins who manage Tags &amp; Themes.
                    </p>
                    <h3 class="h6">Standard theme vocabulary</h3>
                    <p>
                        The page seeds the CCLI / SongSelect standard theme list into the tag list as a two-level parent/child hierarchy, each tag marked as either curator-added or from that standard list. Never hand-type a theme list or re-seed ad-hoc; grow the standard vocabulary through the proper setup step instead.
                    </p>
                    <h3 class="h6">Merging similar tags</h3>
                    <p>
                        Curator-typed variants (e.g. <em>Xmas</em> vs <em>Christmas</em>) are folded into the standard themes by the <strong>canonicalisation suggestions</strong> on this page, using the same shared matching used elsewhere in iHymns. Picking a suggestion runs the same irreversible <strong>Merge</strong> as Musicians &mdash; the variant becomes the source and is deleted, the standard theme is the survivor and every song re-points to it.
                    </p>
                    <div class="gotcha small">
                        <strong>Gotcha:</strong> Merge is immediate and irreversible. Confirm the survivor is the canonical theme before you click through.
                    </div>
                </section>

                <section id="languages" class="help-section card-admin mb-4">
                    <h2><i class="bi bi-translate me-2"></i>Languages</h2>
                    <p class="role-badges">
                        <span class="badge bg-warning text-dark">admin</span>
                        <span class="badge bg-danger">Global Admin</span>
                    </p>
                    <p>
                        The registry of IETF BCP 47 language codes that backs the Language picker in the Song Editor and Songbooks, and the home-page language filter. Most rows are seeded from the IANA Language Subtag Registry during setup and are correct out of the box &mdash; this page is the manual escape hatch for the handful of cases the bundled data doesn't cover. Available to admins who manage Languages.
                    </p>
                    <h3 class="h6">When to use it</h3>
                    <ul>
                        <li><strong>Add a private-use code</strong> (e.g. <code>qwx</code>) for a hymnal in a language IANA doesn't list.</li>
                        <li><strong>Fix a native name</strong> the bundled CLDR data got wrong or didn't carry.</li>
                    </ul>
                    <div class="gotcha small">
                        <strong>Gotcha:</strong> You rarely need this. The IANA seed covers nearly everything; reach for the manual editor only when a code is genuinely missing or a native name is wrong. Re-running the seed migration won't clobber your manual rows.
                    </div>
                </section>

                <section id="catalogues" class="help-section card-admin mb-4">
                    <h2><i class="bi bi-collection me-2"></i>Collections (Catalogues)</h2>
                    <p class="role-badges">
                        <span class="badge bg-warning text-dark">admin</span>
                        <span class="badge bg-danger">Global Admin</span>
                    </p>
                    <p>
                        A <strong>Collection</strong> is a curated, cross-songbook grouping of songs &mdash; a themed selection (e.g. &ldquo;Carols for Christmas Eve&rdquo;) that draws from any number of songbooks without changing where each song <em>lives</em>. Available to admins who manage Songbooks.
                    </p>
                    <h3 class="h6">Naming note</h3>
                    <p class="small">
                        Collections used to be called &ldquo;Catalogues&rdquo; in the admin area; you may still see that older name in a few places while the rename finishes rolling out. It's the same feature under either name.
                    </p>
                    <div class="gotcha small">
                        <strong>Gotcha:</strong> A Collection never moves a song. Each song's home is still its songbook; a Collection just references it. Deleting a Collection leaves every member song untouched.
                    </div>
                </section>

                <section id="external-links" class="help-section card-admin mb-4">
                    <h2><i class="bi bi-link-45deg me-2"></i>External Links</h2>
                    <p class="role-badges">
                        <span class="badge bg-warning text-dark">admin</span>
                        <span class="badge bg-danger">Global Admin</span>
                    </p>
                    <p>
                        Songs, Songbooks, Musicians and Works all support a <strong>card-list editor</strong> for external links &mdash; controlled-vocabulary providers (Wikipedia, Hymnary.org, Spotify, IMSLP, MusicBrainz, etc.). Each link carries an optional Note and a curator-set Verified flag.
                    </p>
                    <h3 class="h6">URL auto-detect</h3>
                    <p>
                        Paste a URL into the URL field of any external-link row and the provider dropdown auto-selects the matching registry entry &mdash; Wikipedia detects Wikipedia, YouTube detects YouTube, Spotify detects Spotify, etc. The detector respects manual choices: if you pick a provider before pasting, your choice wins.
                    </p>
                    <p>
                        The detector is a single shared piece used on every admin page. Every consumer (Songbook editor, Works editor, Musicians editor as it's added) inherits automatically.
                    </p>
                    <h3 class="h6 mt-3">URL patterns</h3>
                    <p>
                        Provider rules are curator-editable at <a href="/manage/external-link-types">Link Types</a>. Add a new provider, sub-domain or path-prefix rule (e.g. <code>musicbrainz.org/work/</code>) at any time without a code deploy. Lower priority numbers win, so put more-specific patterns first.
                    </p>
                    <h3 class="h6">Categories</h3>
                    <p>Links group on the public site under: <em>Official, Information, Read, Sheet music, Listen, Watch, Purchase, Authority, Social, Other</em>. The seeded type registry decides which category each provider belongs to; curators don't pick the category &mdash; it's derived from the type.</p>
                </section>

                <section id="mobile-admin" class="help-section card-admin mb-4">
                    <h2><i class="bi bi-phone me-2"></i>Mobile admin (responsive list views)</h2>
                    <p>
                        Several admin list pages — including Musicians, Songbooks, Songbook Series and Works — automatically simplify their tables on a narrow screen: the most important columns always stay visible, and less-important ones tuck away as the screen gets smaller, so the list stays usable on a phone or tablet instead of turning into a tiny, unreadable grid.
                    </p>
                </section>

                <section id="restrictions" class="help-section card-admin mb-4">
                    <h2><i class="bi bi-shield-lock me-2"></i>Content Restrictions</h2>
                    <p class="role-badges">
                        <span class="badge bg-warning text-dark">admin</span>
                        <span class="badge bg-danger">Global Admin</span>
                    </p>
                    <p>
                        Rule-based access control. Each rule says "for this <em>thing</em>, on this <em>platform</em> / for this <em>user</em> / when this <em>licence</em> is or isn't held, <em>allow</em> or <em>deny</em>."
                    </p>
                    <h3 class="h6">Anatomy of a rule</h3>
                    <dl class="actions">
                        <dt>Entity</dt><dd>What's being restricted: a single song, an entire songbook, or a feature like audio playback.</dd>
                        <dt>Restriction type</dt><dd>How: block by platform / block by user / block by org / require a licence / require an org membership.</dd>
                        <dt>Target</dt><dd>The thing on the other side of the rule: a platform name, user ID, org ID, or licence type.</dd>
                        <dt>Effect</dt><dd>Allow or Deny when this rule fires.</dd>
                        <dt>Priority (0&ndash;1000)</dt><dd>Higher beats lower. At equal priority, Deny beats Allow.</dd>
                        <dt>Reason</dt><dd>Free-text note. Strongly recommended &mdash; future-you will thank present-you.</dd>
                    </dl>
                    <h3 class="h6">Common patterns</h3>
                    <ul>
                        <li><strong>Hide a song from a specific platform</strong> &mdash; set Restriction type to block by platform, with the target set to that platform's name.</li>
                        <li><strong>Restrict copyrighted songs to CCLI holders</strong> &mdash; set Restriction type to require a licence, with the target set to CCLI.</li>
                        <li><strong>Allow only one user to see a draft song</strong> &mdash; block by user for everyone (low priority) plus an Allow rule (high priority) for that one user.</li>
                    </ul>
                    <div class="gotcha small">
                        <strong>Gotcha:</strong> Rules are evaluated <em>at request time</em>. Changes take effect on the next page load &mdash; but data already cached on a user's device may lag a few minutes.
                    </div>
                </section>

                <section id="tiers" class="help-section card-admin mb-4">
                    <h2><i class="bi bi-stars me-2"></i>Membership Tiers</h2>
                    <p class="role-badges">
                        <span class="badge bg-danger">Global Admin</span>
                    </p>
                    <p>
                        Tiers are bundles of capabilities. The seven original ones are view lyrics, view copyrighted lyrics, play audio, download MIDI, download PDF, save offline, and requires CCLI. Every user is assigned one tier, which controls what controls they see in the main app &mdash; and, when content locking is switched on (see below), what the app will actually send them.
                    </p>
                    <h3 class="h6">The capability list can grow</h3>
                    <p>
                        The capability list is <strong>not</strong> fixed at seven &mdash; new capabilities can be added over time as new features are built, each becoming a checkbox on this page automatically, with no other setup needed. This page, the public app, and content locking all stay in sync automatically because they read from the same underlying list.
                    </p>
                    <h3 class="h6">Default tiers (seeded at install)</h3>
                    <ul>
                        <li><strong>public</strong> &mdash; lyrics only, public-domain only.</li>
                        <li><strong>free</strong> &mdash; lyrics, no audio, no copyrighted content.</li>
                        <li><strong>ccli</strong> &mdash; lyrics + copyrighted, but only if the user's organisation has a CCLI licence number.</li>
                        <li><strong>premium</strong> &mdash; everything except offline.</li>
                        <li><strong>pro</strong> &mdash; everything.</li>
                    </ul>
                    <h3 class="h6">Tiers are only enforced when content locking is on</h3>
                    <p>
                        Until you switch it on, tiers are <em>advisory</em>: the app sends full song data and each app is trusted to self-limit what it shows. The switch itself lives on <a href="/manage/configuration#feature-gating">Settings &rarr; Feature Access</a> &mdash; <strong>not</strong> a permission and not something on the <a href="#entitlements">Role Permissions</a> page. With it turned on, the server holds back fields a tier isn't allowed to see (the lyric body for copyrighted songs, restricted media) from what it sends the app, and from the offline download manifest, based on the tier's live settings. At its default, off, the whole mechanism does nothing at all. A second, nested switch on the same panel additionally turns on any admin-defined <a href="/manage/feature-gating">enforcement rule</a>; it has no effect unless content locking is ALSO on.
                    </p>
                    <div class="gotcha small">
                        <strong>Gotcha:</strong> A tier's machine name is set on creation and never changes. To &ldquo;rename&rdquo; a tier, create a new one, reassign users to it, then delete the old one.
                    </div>
                </section>

                <section id="licence-types" class="help-section card-admin mb-4">
                    <h2><i class="bi bi-patch-check me-2"></i>Licence Types</h2>
                    <p class="role-badges">
                        <span class="badge bg-danger">Global Admin</span>
                    </p>
                    <p>
                        The <strong>licence vocabulary</strong> — CCLI, MRL, iHymns Basic / Pro, custom — and what each one <em>legally covers</em> (lyric display / print, music reproduction, audio playback) plus any access <a href="#tiers">tier</a> it confers. Available to global admins.
                    </p>
                    <h3 class="h6">Per-licence fields</h3>
                    <dl class="actions">
                        <dt>Licence key</dt><dd>The stable machine name (e.g. <code>ccli</code>). <strong>Immutable</strong> once created — everything references it.</dd>
                        <dt>Covers</dt><dd>Which rights the licence grants (display, print, audio, &hellip;), used when deciding what a licence-holder can see.</dd>
                        <dt>Confers tier</dt><dd>Optionally, holding this licence bumps a member to a given tier.</dd>
                        <dt>Enabled</dt><dd>Turn a licence type on or off in the picker.</dd>
                    </dl>
                    <div class="gotcha small" style="border-left-color: var(--bs-warning);">
                        <strong>Acts immediately:</strong> editing a licence's <em>Confers-tier</em> or <em>Enabled</em> flag changes members' effective tier <strong>at once — even while content locking is off</strong>. Change it deliberately.
                    </div>
                    <div class="gotcha small">
                        <strong>Gotcha:</strong> System licence rows (the built-in CCLI / MRL / iHymns ones) can't be deleted — other data depends on them. Disable one instead if you don't want it offered.
                    </div>
                </section>

                <section id="gating" class="help-section card-admin mb-4">
                    <h2><i class="bi bi-shield-shaded me-2"></i>Content Access</h2>
                    <p class="role-badges">
                        <span class="badge bg-danger">Global Admin</span>
                    </p>
                    <div class="alert alert-info small">
                        <i class="bi bi-info-circle me-1"></i>
                        <strong>Dormant by default.</strong> The whole content-locking program ships switched <em>off</em> and does nothing until you deliberately turn it on. Until then, the app sends full song data and each app self-limits what it shows.
                    </div>
                    <p>
                        One page that answers &ldquo;what is locked, for whom, and is it safe to turn on?&rdquo; without visiting every separate content-access page. It gathers the family — <a href="#restrictions">Content Restrictions</a>, <a href="#tiers">Membership Tiers</a>, <a href="#licence-types">Licence Types</a>, Feature Access (below), <a href="#entitlements">Role Permissions</a> — behind a <strong>readiness checklist</strong> and shows whether the master switch is on or off. Available to global admins.
                    </p>
                    <div class="gotcha small">
                        <strong>Read-only by design:</strong> this hub does <em>not</em> own the master switch. Turning enforcement on is the one action in the program that changes anything — it changes what every reader sends at once — so it stays a deliberate human act on <a href="/manage/configuration#feature-gating">Settings &rarr; Feature Access</a>, with exactly one place to make the change. The hub reads the state, runs the checklist, and links you to the switch.
                    </div>

                    <h3 class="h6 mt-3">Feature Access <span class="text-muted small fw-normal">(<code>/manage/feature-gating</code>)</span></h3>
                    <p class="small">
                        Where the seven built-in capabilities (view lyrics, view copyrighted lyrics, play audio, download MIDI/PDF, save offline, requires-CCLI) are fixed in code, this page lets a Global Admin define <strong>additional</strong> gateable capabilities with no code change — each one automatically grows a column on the <a href="#tiers">Membership Tiers</a> matrix the moment it's defined. A second panel, <strong>Enforcement rules</strong>, maps a defined capability to a built-in behaviour (strip certain payload fields, or drop certain media kinds) so it actually does something once a tier's matrix says no.
                    </p>
                    <div class="gotcha small">
                        <strong>Gotcha:</strong> a rule can only target a capability <em>you</em> defined here — the seven built-ins are protected from being re-targeted, so this page can add gating, never quietly change how the built-in seven already behave.
                    </div>

                    <h3 class="h6 mt-3">Content-Gating No-Op Verifier <span class="text-muted small fw-normal">(<code>/manage/gating-noop-verify</code>, Global Admin only)</span></h3>
                    <p class="small">
                        A pre-flight safety check for the master switch above. While gating is <strong>off</strong> (the live state everywhere today), this page renders a fixed sample of songs both ways — as a public page and as the API payload — hashes each result, and lets you prove nothing changed by re-running the hash later and diffing. A green &ldquo;byte-identical&rdquo; result across every sample song is what makes it safe to say the whole program is a true no-op until someone deliberately flips the switch. Refuses to run at all once gating is switched on, since the baseline is only meaningful as the OFF reference.
                    </p>
                </section>

                <!-- ====================================================================
                     PEOPLE
                     ==================================================================== -->

                <section id="users" class="help-section card-admin mb-4">
                    <h2><i class="bi bi-people me-2"></i>Users</h2>
                    <p class="role-badges">
                        <span class="badge bg-warning text-dark">admin</span>
                        <span class="badge bg-danger">Global Admin</span>
                    </p>
                    <p>
                        Manage every user account: create accounts, assign roles, deactivate, reset passwords, change tiers.
                    </p>
                    <h3 class="h6">Key actions</h3>
                    <ul>
                        <li><strong>Create</strong> &mdash; username (min 3 chars, lowercase letters / digits / dot / hyphen / underscore), password (min 8 chars), display name, role, tier.</li>
                        <li><strong>Edit profile</strong> &mdash; display name, email, tier.</li>
                        <li><strong>Change role</strong> &mdash; admins can only assign roles at or below their own; only a global admin can promote someone to admin or global admin.</li>
                        <li><strong>Activate / Deactivate</strong> &mdash; deactivated users cannot sign in.</li>
                        <li><strong>Reset password</strong> &mdash; sets a new password directly (the user is not auto-signed-out from existing sessions).</li>
                        <li><strong>Delete</strong> &mdash; permanent. Their setlists, song edit history, and activity entries remain (linked to their account) for audit reasons.</li>
                    </ul>
                    <div class="gotcha small">
                        <strong>Gotcha:</strong> You cannot deactivate <em>your own</em> account.
                    </div>
                    <div class="gotcha small">
                        <strong>Gotcha:</strong> Username is set on creation and is immutable. Display name can be changed any time.
                    </div>
                    <div class="gotcha small">
                        <strong>Gotcha:</strong> Users can also delete <em>their own</em> account
                        self-service from the native Apple app (re-auth required), separately
                        from the admin-initiated Delete above. A self-service delete also revokes
                        any linked Sign in with Apple grant and clears their API access.
                    </div>
                </section>

                <section id="groups" class="help-section card-admin mb-4">
                    <h2><i class="bi bi-people-fill me-2"></i>User Groups</h2>
                    <p class="role-badges">
                        <span class="badge bg-warning text-dark">admin</span>
                        <span class="badge bg-danger">Global Admin</span>
                    </p>
                    <p>
                        Logical buckets of users for shared release-channel access (Alpha / Beta / RC / RTW). Useful for &ldquo;who sees pre-release content?&rdquo; without managing flags per-user.
                    </p>
                    <h3 class="h6">Key actions</h3>
                    <ul>
                        <li>Create a group with a name, description, and four channel flags.</li>
                        <li>Two-pane membership editor: drag users between &ldquo;Available&rdquo; and &ldquo;In group.&rdquo;</li>
                    </ul>
                    <div class="gotcha small">
                        <strong>Gotcha:</strong> One group per user. Re-assigning moves them; there's no &ldquo;in two groups at once.&rdquo;
                    </div>
                    <div class="gotcha small">
                        <strong>Role vs Group:</strong> these names sound similar but they're independent concepts.
                        <ul class="small mb-0">
                            <li><strong>User Role</strong> (Curator / Admin / Global Admin) controls which Manage pages a user can <em>access</em>. The four roles are fixed today; new roles need development work.</li>
                            <li><strong>User Group</strong> controls which release channel a user sees on the public site (Alpha / Beta / RC / RTW). Group membership is freely admin-managed via this page.</li>
                        </ul>
                        Don't expect adding a User Group to grant Manage access — for that, change the user's Role from Users.
                    </div>
                </section>

                <section id="organisations" class="help-section card-admin mb-4">
                    <h2><i class="bi bi-building me-2"></i>Organisations</h2>
                    <p class="role-badges">
                        <span class="badge bg-warning text-dark">admin</span>
                        <span class="badge bg-danger">Global Admin</span>
                    </p>
                    <p>
                        Churches, denominations, schools. Group users together and attach a single licence (CCLI / iHymns Pro / iHymns Basic) to that group instead of to each user.
                    </p>
                    <h3 class="h6">Per-org fields</h3>
                    <dl class="actions">
                        <dt>Slug</dt><dd>URL-safe identifier (auto-derived from name; you can override). Unique.</dd>
                        <dt>Parent org</dt><dd>Optional; lets you build a denomination &rarr; diocese &rarr; church chain. Licences inherit downward.</dd>
                        <dt>Licence type / number</dt><dd><code>none</code>, <code>ihymns_basic</code>, <code>ihymns_pro</code>, or <code>ccli</code>. CCLI requires a licence number for audit.</dd>
                        <dt>Active flag</dt><dd>Inactive orgs are kept in the database but their licence stops counting toward members.</dd>
                    </dl>
                    <h3 class="h6">Membership</h3>
                    <p>Two-pane picker, same shape as User Groups. Each member also gets a sub-role: <em>member</em> (no extra perms), <em>admin</em> (can manage other members), or <em>owner</em> (full control of the org).</p>
                    <h3 class="h6">Logo</h3>
                    <p>
                        Upload your organisation's logo here (org admins can also do this from <a href="#my-organisations">My Organisations</a>). You can add several shapes — a main logo, wide and stacked layouts, a symbol on its own, a name-only version, single-colour and light-on-dark versions, and an app icon — so the right one is available wherever it's needed. <strong>SVG</strong> looks sharpest in print; <strong>PNG</strong> also works. Uploaded SVG files are tidied automatically for safety, so decorative extras like animation or embedded pictures aren't kept; keep the file reasonably small. Once uploaded, a <a href="#print-templates">Print Template</a> can include an <strong>Organisation logo</strong> block set to a specific shape or to <strong>Automatic</strong> (uses the best one available); if the shape you chose hasn't been uploaded, nothing prints there — never a broken image.
                    </p>
                    <div class="gotcha small">
                        <strong>Gotcha:</strong> An org cannot be its own parent (and we block circular chains in general).
                    </div>
                </section>

                <section id="my-organisations" class="help-section card-admin mb-4">
                    <h2><i class="bi bi-building-check me-2"></i>My Organisations</h2>
                    <p class="role-badges">
                        <span class="badge bg-secondary">org admin</span>
                        <span class="badge bg-secondary">org owner</span>
                        <span class="badge bg-warning text-dark">admin</span>
                        <span class="badge bg-danger">Global Admin</span>
                    </p>
                    <p>
                        The org-admin surface. Visible to anyone who holds an admin or owner role for at least one organisation — they don't need a system-admin role to see this page. System admins see it too, scoped to every org.
                    </p>
                    <h3 class="h6">What it does</h3>
                    <ul>
                        <li>Lists every organisation the current user can manage.</li>
                        <li>For each, shows the member roster with role badges and the licence rows on file.</li>
                        <li>Inline forms for the six edit actions described below — you don't need the main Organisations page for routine org-admin work.</li>
                    </ul>
                    <h3 class="h6">Member actions</h3>
                    <dl class="actions">
                        <dt>Add member</dt><dd>Free-text identifier — type a username OR an email, and the server finds the matching account. New member rows pick a sub-role (<em>member</em> / <em>admin</em> / <em>owner</em>).</dd>
                        <dt>Change member role</dt><dd>Inline picker per row.</dd>
                        <dt>Remove member</dt><dd>You can't remove yourself unless you're also a system admin — prevents accidental org lock-out. Ask a co-admin.</dd>
                    </dl>
                    <h3 class="h6">Licence actions</h3>
                    <dl class="actions">
                        <dt>Add licence</dt><dd>Per-row licence types: CCLI, MRL, iHymns Basic, iHymns Pro, custom. Re-adding the same type updates its number / expiry / notes in place rather than creating a duplicate.</dd>
                        <dt>Change licence</dt><dd>Edit number, expiry date, active flag, notes. Type is immutable on a row — to switch types, remove and re-add.</dd>
                        <dt>Remove licence</dt><dd>Drops the row. Double-checked against organisation ownership on the server.</dd>
                    </dl>
                    <h3 class="h6">Organisation policies</h3>
                    <dl class="actions">
                        <dt>Set-list edit links</dt><dd>Choose how much freedom your members have when they share an <strong>editable</strong> set-list link: <em>No preference</em> (each member decides per link), <em>Default to signed-in</em> (new edit links start locked to signed-in editors, changeable), or <em>Require signed-in</em> (every edit link the org's members create is clamped to signed-in-only — members can't loosen it). Applies to the &ldquo;anyone with the link&rdquo; vs &ldquo;must sign in&rdquo; choice in the share dialog.</dd>
                        <dt>Live idle-timeout</dt><dd>Set an organisation-wide default for how long a member's <a href="#service-mode">Live Follow</a> session may sit idle before it auto-closes, and optionally <strong>enforce</strong> it so members can't override it upward. This setting sits below the app-wide default and above each member's own preference.</dd>
                        <dt>Members' live sessions — extend on behalf</dt><dd>A <strong>Members' live sessions</strong> panel lists your members' currently-live sessions; an org admin can <strong>Extend</strong> one on the leader's behalf (e.g. the leader's phone died mid-service). Same effect as the leader's own Extend control.</dd>
                    </dl>
                    <h3 class="h6">Who can act on what</h3>
                    <p>
                        Every action checks, on the server, that the person making the change is actually allowed to manage that specific organisation before anything is saved — a form can't be tricked into editing a different organisation than the one it claims to.
                    </p>
                    <div class="gotcha small">
                        <strong>Gotcha:</strong> System admins (Admin / Global Admin roles) bypass that per-organisation check by default. The activity log records the action either way, so the timeline reads as one surface.
                    </div>
                </section>

                <section id="entitlements" class="help-section card-admin mb-4">
                    <h2><i class="bi bi-key me-2"></i>Role Permissions</h2>
                    <p class="role-badges">
                        <span class="badge bg-danger">Global Admin</span>
                    </p>
                    <p>
                        The fine-grained permission map. Every admin action is gated by a permission (e.g. editing songs, verifying songs, managing songbooks). This page lets you change which roles hold which permissions, overriding the built-in defaults.
                    </p>
                    <h3 class="h6">When to use it</h3>
                    <ul>
                        <li>Promote a single privilege to a role that doesn't normally have it (e.g. let editors run the CCLI report).</li>
                        <li>Demote a privilege you want to lock down (e.g. take song-deletion away from admins, leave it with global admins only).</li>
                        <li>Reset to defaults if you've gone too far &mdash; the &ldquo;Reset&rdquo; button restores the built-in baseline.</li>
                    </ul>
                    <div class="gotcha small">
                        <strong>Gotcha:</strong> This is a nuclear tool. A bad change is global, immediate, and visible to everyone on next page load. Make small changes and verify before moving on.
                    </div>
                    <div class="gotcha small">
                        <strong>Gotcha:</strong> The page enforces one safety guard: the permission that controls this very page can never be removed from Global Admins, so you can't lock yourself out of the page that re-grants access.
                    </div>
                </section>

                <section id="venues" class="help-section card-admin mb-4">
                    <h2><i class="bi bi-geo-alt me-2"></i>Venues &amp; Service Times</h2>
                    <p class="role-badges">
                        <span class="badge bg-secondary">org admin</span>
                        <span class="badge bg-secondary">org owner</span>
                        <span class="badge bg-warning text-dark">admin</span>
                        <span class="badge bg-danger">Global Admin</span>
                    </p>
                    <p>
                        Lets an organisation record the <strong>where and when</strong> of its meetings: physical venues (name, address, map pin, timezone) and each venue's recurring service times (day of week, start time, duration) or one-off occurrences. This is metadata in its own right, and it's also the foundation the <a href="#service-mode">Service Mode</a> feature below builds on &mdash; a live session anchors to a venue and an occurrence so its join code can expire when the service actually ends.
                    </p>
                    <h3 class="h6">Per-venue fields</h3>
                    <dl class="actions">
                        <dt>Name &amp; address</dt><dd>What the congregation calls the place, and its street address.</dd>
                        <dt>Map pin &amp; radius</dt><dd>A convenience geofence for display purposes only &mdash; it is <em>not</em> how Service Mode proves someone is present. Presence proof is the venue-displayed rotating code, deliberately, because location can be spoofed or simply inaccurate indoors.</dd>
                        <dt>Timezone</dt><dd>Used to resolve a recurring schedule's local start time into the correct UTC moment, including across daylight-saving changes.</dd>
                    </dl>
                    <h3 class="h6">Service times</h3>
                    <p>
                        Each venue can have several recurring service times (e.g. Sunday 10:00am, Wednesday 7:00pm), each with a title, day of week, start time and duration &mdash; or a one-off occurrence for a special service that doesn't repeat.
                    </p>
                    <div class="gotcha small">
                        <strong>Gotcha:</strong> Venues + service times are useful org metadata on their own, even if you never turn on Service Mode. Setting them up doesn't switch anything else on.
                    </div>
                </section>

                <section id="service-mode" class="help-section card-admin mb-4">
                    <h2><i class="bi bi-broadcast-pin me-2"></i>Service Mode (congregation Live-Follow)</h2>
                    <p class="role-badges">
                        <span class="badge bg-secondary">org admin</span>
                        <span class="badge bg-secondary">org owner</span>
                        <span class="badge bg-warning text-dark">admin</span>
                        <span class="badge bg-danger">Global Admin</span>
                    </p>
                    <div class="alert alert-info small">
                        <i class="bi bi-info-circle me-1"></i>
                        <strong>Dormant by default.</strong> The whole feature is switched off by default and additionally needs a CCLI-licence content rule set up before it does anything. Turn it on deliberately when your organisation is ready.
                    </div>
                    <p>
                        Service Mode lets a congregation follow the songs of a live service in sync on their own devices. The leader drives which song is showing; everyone who has joined sees it change in real time. Joining is by a <strong>rotating code</strong> shown at the venue &mdash; no account or sign-in required for a congregant.
                    </p>
                    <h3 class="h6">Setting up (org admin / owner)</h3>
                    <dl class="actions">
                        <dt><a href="#venues">Venues</a></dt><dd>Define your organisation's physical venues (name, address, timezone) and their recurring service schedules (day, start time, duration) &mdash; see the <a href="#venues">Venues &amp; Service Times</a> section above. These anchor a live session to a place and an occurrence so a join code can expire when the service ends.</dd>
                        <dt>Projector Screen</dt><dd>The big-screen / projector view. Displays the current song and the rotating join code (plus QR) for the congregation to scan or type. One of the two broadcaster front-ends &mdash; song-nav here sets the current song for everyone.</dd>
                        <dt>Lead a Service</dt><dd>The leader's own device: connect to a session and drive it from your phone or tablet, without needing to stand at the projector.</dd>
                    </dl>
                    <p class="small">
                        <strong>Finding these in the menu:</strong> organisation admins and owners now see the <strong>Projector Screen</strong> and <strong>Lead a Service</strong> links in the admin menu directly. They were always allowed to open these pages &mdash; only the menu had been hiding the links, so previously you had to reach them by their web address.
                    </p>
                    <h3 class="h6">How a congregant follows</h3>
                    <p class="small">
                        A congregant opens the join link / scans the QR / types the code shown at the venue. The app mints an anonymous presence pass on their device, checks in for the current song, and follows along. That check-in is limited <em>per device</em>, not per network connection, so a whole congregation on one venue Wi-Fi isn't throttled as if it were a single visitor.
                    </p>
                    <h3 class="h6">CCLI unlock (Phase 3)</h3>
                    <p class="small">
                        When the dormant switch is turned on, a valid congregant whose session belongs to an org holding a <em>live</em> CCLI licence unlocks the copyrighted-lyrics view for the duration of the service &mdash; the congregant sees the per-song CCL copyright notice. The owner has accepted the licensing basis for this.
                    </p>
                    <h3 class="h6">Presentation-app control</h3>
                    <p class="small">
                        Projector Screen carries a <strong>Presentation-app control</strong> card that mints a <strong>driver key</strong>. An external presentation app (ProPresenter and the like) can then drive which song is current using that key — writing through the <em>same</em> mechanism the operator console uses. Mint, list, and revoke keys from that card.
                    </p>
                    <h3 class="h6">The join QR</h3>
                    <p class="small">
                        The projection screen shows a scannable <strong>QR of the join URL</strong> beside the typed code. If the QR service isn't available, the QR image simply doesn't appear and the always-visible <strong>typed code</strong> is the fallback — nothing breaks.
                    </p>
                    <div class="gotcha small">
                        <strong>Gotcha:</strong> Proof-of-presence is the venue-displayed rotating code, deliberately &mdash; <em>not</em> geolocation (which is spoofable). Don't expect a GPS check; the code rotating at the venue is what proves someone is actually there.
                    </div>
                </section>

                <!-- ====================================================================
                     OPERATIONS
                     ==================================================================== -->

                <section id="analytics" class="help-section card-admin mb-4">
                    <h2><i class="bi bi-graph-up me-2"></i>Analytics</h2>
                    <p class="role-badges">
                        <span class="badge bg-warning text-dark">admin</span>
                        <span class="badge bg-danger">Global Admin</span>
                    </p>
                    <p>
                        Read-only dashboard of usage metrics: top songs, top songbooks, search queries, login counts, user growth. Pick a window (7 / 30 / 90 days) and read off the panels.
                    </p>
                    <h3 class="h6">Exporting</h3>
                    <p class="small">Each panel has a CSV download button that respects the current window.</p>
                    <div class="gotcha small">
                        <strong>Gotcha:</strong> Search-query tracking is optional. If your install doesn't log search terms, that panel will be empty. (No personally identifying information is logged either way.)
                    </div>
                </section>

                <section id="ccli-report" class="help-section card-admin mb-4">
                    <h2><i class="bi bi-receipt me-2"></i>CCLI Usage Report</h2>
                    <p class="role-badges">
                        <span class="badge bg-warning text-dark">admin</span>
                        <span class="badge bg-danger">Global Admin</span>
                    </p>
                    <p>
                        Compliance report for CCLI licensees, covering the <strong>whole site</strong>. Pick a date range and get a sortable table of every CCLI-numbered song with its view count and copyright info, ready to upload to your CCLI reporting portal.
                    </p>
                    <h3 class="h6">Tips</h3>
                    <ul>
                        <li>The CSV export is the column shape your CCLI portal expects (title, CCLI number, copyright, count).</li>
                        <li>Tick &ldquo;Show all&rdquo; to also include songs without a CCLI number assigned &mdash; useful for spotting gaps in the metadata.</li>
                        <li>The view-count is per occurrence: a user opening the same song twice counts as two views.</li>
                        <li>Songs shown to a congregation during a live service (see <a href="#service-mode">Service Mode</a>) now count as uses too &mdash; including whole set-lists driven through the projector &mdash; so projected worship isn't undercounted.</li>
                    </ul>
                    <h3 class="h6 mt-3">Filtering by organisation (#1861)</h3>
                    <p>
                        The <strong>Organisation</strong> dropdown narrows the report to one organisation's usage &mdash; or to <em>&ldquo;Unattributed&rdquo;</em>, the views that carry no organisation at all (a signed-in user with no org membership, or an anonymous view). Leave it on &ldquo;All organisations&rdquo; for the whole-site figure. If you're looking for one organisation's <em>own</em> admin-facing report rather than this system-wide view, see <a href="#my-ccli-report">My CCLI Report</a> below.
                    </p>
                    <div class="gotcha small">
                        <strong>Gotcha (attribution):</strong> when a view could be credited to either a personal CCLI number or an organisation's licence, iHymns now prefers the <strong>organisation's</strong> licence &mdash; a signed-in user who also belongs to a licensed organisation has their usage counted under the org, not left as a personal/unattributed view. This fixed real under-counting for organisations whose members hold their own personal CCLI numbers too.
                    </div>
                </section>

                <section id="my-ccli-report" class="help-section card-admin mb-4">
                    <h2><i class="bi bi-receipt-cutoff me-2"></i>My CCLI Report</h2>
                    <p class="role-badges">
                        <span class="badge bg-secondary">org admin</span>
                        <span class="badge bg-secondary">org owner</span>
                    </p>
                    <p>
                        The <strong>self-serve</strong> sibling of <a href="#ccli-report">CCLI Usage Report</a> above. Where that page is a system-wide view for site admins, this one shows an organisation admin exactly <strong>their own</strong> organisation's printed-copy usage for their annual CCLI return &mdash; and nothing else. If you administer more than one organisation, a selector lets you switch between the ones you actually hold an admin/owner role on.
                    </p>
                    <h3 class="h6">Who sees it</h3>
                    <p>
                        Open to every signed-in role by default, but the report itself is empty unless you hold an <strong>admin or owner</strong> role on at least one organisation (the same membership check <a href="#my-organisations">My Organisations</a> uses). A user with no such role sees a friendly &ldquo;not an organisation admin&rdquo; message instead of an empty report &mdash; with a pointer to the system-wide report if they also happen to hold that separate permission.
                    </p>
                    <div class="gotcha small">
                        <strong>Gotcha:</strong> this is deliberately narrower than <a href="#my-organisations">My Organisations</a> &mdash; there is no &ldquo;see every organisation&rdquo; shortcut here even for a system admin. A system admin who wants every organisation's figures uses <a href="#ccli-report">CCLI Usage Report</a> instead; this page always scopes strictly to organisations you personally administer.
                    </div>
                </section>

                <section id="data-health" class="help-section card-admin mb-4">
                    <h2><i class="bi bi-activity me-2"></i>Data Health</h2>
                    <p class="role-badges">
                        <span class="badge bg-danger">Global Admin</span>
                    </p>
                    <p>
                        Confirms that MySQL is the authoritative source for every kind of data, and lets you safely <em>disconnect</em> the remaining legacy fallbacks (the SQLite user database, the file-system setlist share directory) so the app stops checking them.
                    </p>
                    <div class="alert alert-info small">
                        <i class="bi bi-info-circle me-1"></i>
                        Song reads are now <strong>live database, full stop</strong>. There is no longer a whole-catalogue cache file to disconnect &mdash; every read pulls just what it needs, scoped to a lightweight index, a single record, or a single songbook. If the database is unreachable the app shows a themed maintenance page, <em>never</em> stale data.
                    </div>
                    <h3 class="h6">Workflow</h3>
                    <ol>
                        <li>Read the row counts at the top to confirm MySQL has the data you expect.</li>
                        <li>Click <strong>Disconnect</strong> next to a fallback. The file is <em>renamed</em> to <code>.disabled</code>, not deleted &mdash; you can restore it manually if needed.</li>
                        <li>Reload the main app to confirm everything still works without the fallback.</li>
                    </ol>
                    <div class="gotcha small">
                        <strong>Gotcha:</strong> One-way lever. There's no &ldquo;Reconnect&rdquo; button &mdash; restoring a fallback means renaming the <code>.disabled</code> file back by hand on the server.
                    </div>
                </section>

                <section id="activity-log" class="help-section card-admin mb-4">
                    <h2><i class="bi bi-journal-text me-2"></i>Activity Log</h2>
                    <p class="role-badges">
                        <span class="badge bg-warning text-dark">admin</span>
                        <span class="badge bg-danger">Global Admin</span>
                    </p>
                    <p>
                        Every action that mutates data (creates, edits, deletes, sign-ins, sign-outs, role changes, etc.) writes a row here. Searchable, filterable, exportable.
                    </p>
                    <h3 class="h6">Filters</h3>
                    <ul>
                        <li><strong>User</strong> &mdash; username or email substring.</li>
                        <li><strong>Action</strong> / <strong>Result</strong> / <strong>Entity type</strong> &mdash; pick from a list.</li>
                        <li><strong>Entity ID</strong> &mdash; the thing that was acted on.</li>
                        <li><strong>Request ID</strong> &mdash; trace every row that came from a single browser request (useful when debugging a multi-step action).</li>
                        <li><strong>Time window</strong> &mdash; 1 / 7 / 30 / 90 / 365 days.</li>
                        <li><strong>Free text</strong> &mdash; matches the action name and entity ID. Use the specific filters for everything else.</li>
                    </ul>
                    <h3 class="h6">CSV export</h3>
                    <p class="small">Respects every active filter; capped at 10 000 rows per download.</p>
                    <h3 class="h6">Error capture</h3>
                    <p>
                        Server-side errors raised while saving (the &ldquo;Database error — check server logs&rdquo; banner) are mirrored into the activity log with the failure details attached. The viewer's <strong>Result = error</strong> filter is a one-click triage list — you no longer need server access to see why a save failed.
                    </p>
                    <ul>
                        <li><strong>Client errors:</strong> browser-side crashes now appear here too — one row per distinct error, not one per occurrence. When a tester says &ldquo;the button does nothing,&rdquo; check here first, before asking for logs or a screen-share.</li>
                    </ul>
                    <p class="small text-muted">
                        Naming convention: an entry written from a web admin action reads slightly differently from the same kind of action taken through the public API, so timeline readers can tell which surface drove the change.
                    </p>
                    <div class="gotcha small">
                        <strong>Gotcha:</strong> Rows are immutable. There's no edit, no delete &mdash; that's the whole point of an audit log.
                    </div>
                </section>

                <section id="notifications" class="help-section card-admin mb-4">
                    <h2><i class="bi bi-bell me-2"></i>Notifications</h2>
                    <p class="role-badges">
                        <span class="badge bg-danger">Global Admin</span>
                    </p>
                    <p>
                        Compose and broadcast in-app notifications &mdash; the rows that drive the header bell every signed-in user sees. Available to global admins.
                    </p>
                    <h3 class="h6">Who you can target</h3>
                    <ul>
                        <li><strong>A single user</strong> &mdash; resolved by username, email, or ID.</li>
                        <li><strong>A role</strong> &mdash; every signed-in user holding that role.</li>
                        <li><strong>Broadcast</strong> &mdash; everyone.</li>
                    </ul>
                    <p class="small">
                        One row per recipient is inserted on send, and the action is written to the <a href="#activity-log">Activity Log</a> so the trail can answer &ldquo;who broadcast what to whom, when.&rdquo; This is also where the <a href="#editor">Bulk Import ZIP</a> worker posts its completion message.
                    </p>
                    <div class="gotcha small">
                        <strong>Gotcha:</strong> A broadcast fans out to one row per recipient &mdash; on a large install that's a lot of rows. Compose carefully; there's no &ldquo;unsend.&rdquo;
                    </div>
                </section>

                <section id="schema-audit" class="help-section card-admin mb-4">
                    <h2><i class="bi bi-clipboard2-data me-2"></i>Database Structure Check</h2>
                    <p class="role-badges">
                        <span class="badge bg-danger">Global Admin</span>
                    </p>
                    <p>
                        Diagnostic page that compares three things: the database structure your code expects, the structure your live database actually has, and the setup steps that bridge the two. Surfaces drift before it bites.
                    </p>
                    <h3 class="h6">Status meanings</h3>
                    <dl class="actions">
                        <dt>OK</dt><dd>Expected by the code <em>and</em> present in the database. Nothing to do.</dd>
                        <dt>Missing (amber)</dt><dd>Expected by the code, not yet in the database, but a setup step covers it. Run that step on <a href="#setup-database">Database Setup</a>.</dd>
                        <dt>Uncovered (red)</dt><dd>Expected by the code, not in the database, and no setup step covers it. This is a real bug &mdash; an existing install will never get this column. Report it.</dd>
                        <dt>Orphan</dt><dd>In the database but not expected by the code. Almost always informational &mdash; a column that was retired from the code is still around in the database. Safe to leave alone.</dd>
                    </dl>
                    <div class="gotcha small">
                        <strong>Gotcha:</strong> Database Structure Check is read-only. It tells you what's wrong; it never changes the database structure itself.
                    </div>
                </section>

                <section id="diagnostics" class="help-section card-admin mb-4">
                    <h2><i class="bi bi-terminal me-2"></i>Database Query Tool</h2>
                    <p class="role-badges">
                        <span class="badge bg-danger">Global Admin</span>
                    </p>
                    <p>
                        A read-only SQL console for the "what's actually in the database right now?" questions the per-entity grids don't answer. Type a query, hit <strong>Run query</strong>, read the rows. A few preset starters (table sizes, record counts, recent activity) sit above the box.
                    </p>
                    <h3 class="h6">What's allowed</h3>
                    <dl class="actions">
                        <dt>SELECT / SHOW / EXPLAIN / DESCRIBE</dt><dd>Anything else is rejected before it reaches the database.</dd>
                        <dt>One statement</dt><dd>No semicolon-chaining; a single trailing <code>;</code> is fine.</dd>
                        <dt>Up to 1,000 rows</dt><dd>Results are capped; add a <code>LIMIT</code> and a tight <code>WHERE</code> for a focused slice.</dd>
                    </dl>
                    <div class="gotcha small">
                        <strong>Gotcha:</strong> this runs against the <strong>production</strong> connection. Writes (<code>INSERT</code>/<code>UPDATE</code>/<code>DELETE</code>/<code>ALTER</code>…), <code>SELECT … INTO OUTFILE</code>, and the <code>mysql</code>/<code>performance_schema</code>/<code>sys</code> schemas are all blocked, and every run is logged to the <a href="#activity-log">Activity Log</a> — but a heavy join can still load the server.
                    </div>
                </section>

                <section id="setup-database" class="help-section card-admin mb-4">
                    <h2><i class="bi bi-database-gear me-2"></i>Database Setup</h2>
                    <p class="role-badges">
                        <span class="badge bg-danger">Global Admin</span>
                    </p>
                    <p>
                        The most powerful page in the admin area. Configures the database connection, installs the schema, runs migrations, takes backups, restores from backup. Treat with respect.
                    </p>
                    <h3 class="h6">Setup workflow on a new install</h3>
                    <ol>
                        <li><strong>Credentials</strong> &mdash; fill in host, port, database name, username, password, table prefix. Click <strong>Test connection</strong>; only save once it goes green.</li>
                        <li><strong>Install schema</strong> &mdash; creates every table the app needs. Safe to re-run.</li>
                        <li><strong>Migrate users / setlists</strong> &mdash; one-time import from the legacy user database and setlist-sharing files, if you're upgrading from a very old install.</li>
                        <li>Run the remaining setup steps <em>in the order they appear on the dashboard</em> — some cards keep their original historical names even after a later feature rename, but each is safe to run more than once.</li>
                    </ol>
                    <h3 class="h6">Setup workflow on an existing install</h3>
                    <p>
                        Re-run <strong>Install schema</strong> if anything changed (it's safe), then run only the steps that <a href="#schema-audit">Database Structure Check</a> flagged as missing. Every step is safe to re-run &mdash; running one that's already been applied just reports &ldquo;[skip]&rdquo; for everything.
                    </p>
                    <h3 class="h6">Apply all pending migrations</h3>
                    <p>
                        The <strong>Apply all pending migrations</strong> button runs every outstanding setup step in the right order. Each one is already safe to re-run, so re-running the bulk action after some have been applied is safe — the ones already done simply do nothing the second time.
                    </p>
                    <p class="small">
                        If a step fails mid-run, the dashboard captures the first-failing step and surfaces it in a prominent banner <em>above</em> the (sometimes long, scrollable) output panel, so you don't miss the failure in the noise. Fix the underlying issue and re-run — the steps that succeeded earlier do nothing the second time.
                    </p>
                    <h3 class="h6">Backups</h3>
                    <ul>
                        <li><strong>Backup</strong> downloads a SQL dump of the entire database. Keep at least one before running unfamiliar migrations.</li>
                        <li><strong>Restore</strong> uploads a previously downloaded dump and replays it &mdash; <em>completely overwrites</em> the current database. Read the warning before you click.</li>
                    </ul>
                    <h3 class="h6">Cleanup</h3>
                    <p class="small">Deletes expired API tokens, old login attempts (&gt;30 days), expired email-login codes, etc. Safe to run any time.</p>
                    <div class="gotcha small">
                        <strong>Gotcha:</strong> Migrations are not reversible. <strong>Always</strong> take a backup before running any migration on production.
                    </div>
                    <div class="gotcha small">
                        <strong>Gotcha:</strong> The <strong>table prefix</strong> is fixed at install time and cannot be changed afterwards. Pick once, live with it.
                    </div>
                    <div class="gotcha small">
                        <strong>Gotcha:</strong> Restore overwrites everything. There is no &ldquo;merge&rdquo; option.
                    </div>
                </section>

                <?php /* ELI5: this section explains the form fields that
                         tell the site where to find our app on each store.
                         DETAILED (#1462): documents /manage/configuration's
                         "Native app stores" card (commit 8c5dda87), which
                         moved native_app_ios / native_app_android /
                         native_app_amazon out of the code-level
                         APP_CONFIG['native_apps'] constant into
                         tblAppSettings so a global admin can set/change
                         store IDs without a deploy. Deliberately silent on
                         store availability — the Apple app is still
                         in-progress and unpublished, so this card is
                         commonly left blank. */ ?>
                <section id="configuration" class="help-section card-admin mb-4">
                    <h2><i class="bi bi-phone me-2"></i>Native app stores &amp; Apple Sign-In</h2>
                    <p class="role-badges">
                        <span class="badge bg-danger">Global Admin</span>
                    </p>
                    <p>
                        A card on <a href="/manage/configuration">Settings</a> that tells the site where each
                        platform's native app is listed &mdash; Apple App Store, Google Play, and the Amazon
                        Appstore (Fire OS). It's just an address book entry: it doesn't publish anything, it only
                        tells the site what to link to once an app <em>is</em> published.
                    </p>
                    <h3 class="h6">What to paste in each field</h3>
                    <ul>
                        <li><strong>Apple App Store</strong> &mdash; the numeric App Store ID, or the full <code>apps.apple.com</code> URL. One listing covers the universal app across iOS/iPadOS/macOS/tvOS/watchOS/visionOS.</li>
                        <li><strong>Google Play</strong> &mdash; the Android package name, or the full Play Store URL.</li>
                        <li><strong>Amazon Appstore</strong> &mdash; the 10-character ASIN, or the full Amazon Appstore URL.</li>
                    </ul>
                    <p class="small">
                        Either form works &mdash; paste a bare ID/package/ASIN or the whole store URL, whichever you have to hand. The save handler parses out the canonical ID either way, so what's stored is always consistent.
                    </p>
                    <h3 class="h6">What changes once a field is set</h3>
                    <p>
                        The public site shows a platform-aware native-app download banner &mdash; on that platform only &mdash; in place of the browser's PWA "Add to Home Screen" prompt, and emits the matching app-store meta tag.
                    </p>
                    <div class="gotcha small">
                        <strong>Gotcha:</strong> it's safe &mdash; and normal &mdash; to leave every field blank until an app is actually live on that store. Blank falls back to the ordinary PWA install prompt; nothing breaks and no banner claims an app exists before it does.
                    </div>
                    <h3 class="h6">Apple native app &amp; Sign in with Apple credentials</h3>
                    <p>
                        The same <a href="/manage/configuration">Settings</a> page has an
                        &ldquo;Apple native app&rdquo; card, below the Feature Access card, holding the
                        Apple Developer <strong>Team ID</strong> (for Universal Links), the
                        <strong>Sign in with Apple</strong> key (Key ID + <code>.p8</code> private key
                        &mdash; only needed for the refresh-token exchange and Apple-side revoke on
                        account deletion; the native app's sign-in itself works before these are set),
                        and the separate <strong>Services ID</strong> + channel allow-list that turns on
                        Sign in with Apple for the <em>web</em> app. Every field on this card is optional
                        and dormant until filled in &mdash; each has its own inline guidance and a
                        set/not-set badge.
                    </p>
                    <h3 class="h6">Public-domain suggestion threshold (#1862)</h3>
                    <p>
                        One number: <strong>Publication-year fallback threshold</strong> (default <strong>1900</strong>,
                        range 500&ndash;2100). This is the ONE knob behind the Song Editor's
                        &ldquo;looks public domain&rdquo; hint (see <a href="#editor">Song Editor</a> above) for the
                        case where no credited writer/composer has a death date on file: a song first published
                        before this year is <em>suggested</em> public domain. The life-plus-70-years basis used
                        when a death date IS on file is a fixed code constant, not configurable here. Either way
                        it's only ever a suggestion &mdash; nothing auto-ticks the Public Domain checkbox.
                    </p>
                </section>

                <section id="native-api" class="help-section card-admin mb-4">
                    <h2><i class="bi bi-broadcast me-2"></i>Native API surface</h2>
                    <p class="role-badges">
                        <span class="badge bg-warning text-dark">admin</span>
                        <span class="badge bg-danger">Global Admin</span>
                    </p>
                    <p>
                        Every admin action on this site is also reachable via the public API. Native clients (Apple, Android, FireOS) and tooling clients (automated testing, monitoring, dashboards) can drive the same surfaces the web admin uses without a webview or a separate sign-in flow.
                    </p>
                    <h3 class="h6">Auth</h3>
                    <p>
                        Token-based auth via a standard Bearer authorization header. Tokens are issued by the existing email-magic-link or password login flows. Write requests also need a same-origin request header as a cross-site-request defence. Same role gates as the web admin — admin / global admin for system-wide write actions, plus a per-organisation check for org-admin endpoints.
                    </p>
                    <h3 class="h6">What's covered</h3>
                    <ul>
                        <li><strong>Songbooks</strong> — create / update / delete / cascade-delete / reorder / auto-colour fill / auto-colour reassign.</li>
                        <li><strong>Users + Groups + Tiers</strong> — full management plus role changes / activate / password-reset / member-add-remove.</li>
                        <li><strong>Organisations + My Organisations</strong> — system-admin updates plus the six org-admin actions from this surface.</li>
                        <li><strong>Musicians</strong> — add / update / rename / merge / delete with the same cascade and confirmation gates.</li>
                        <li><strong>Analytics + the Database Query Tool</strong> — top searches, data health snapshot, database structure report, per-setup-step status.</li>
                        <li><strong>Editor</strong> — load / save / bulk tag / list and restore edit history / song tags / tag, credit, user and org search / bulk import and its status.</li>
                    </ul>
                    <h3 class="h6">OpenAPI spec</h3>
                    <p>
                        Every endpoint is documented in <a href="/api-docs.yaml">a single OpenAPI 3.0 file</a>. Swagger UI / Stoplight / Redoc all render it cleanly. The spec is the source of truth for the request / response shapes — the web admin and the native clients both stay in sync with it because they share the same underlying validation.
                    </p>
                    <h3 class="h6">Activity-log surface prefix</h3>
                    <p class="small">
                        API-driven changes are logged distinctly from changes made through the web admin, so the <a href="#activity-log">Activity Log</a> viewer can show both surfaces side-by-side and you can tell which one drove a given change.
                    </p>
                    <div class="gotcha small">
                        <strong>Gotcha:</strong> Status codes follow REST: 400 (validation), 401, 403 (role gate or row-level refusal), 404, 405 (wrong method), 409 (duplicate key), 422 (cannot delete because dependents exist). Native UIs can render the right toast without parsing the error string.
                    </div>

                    <h3 class="h6 mt-3">Connected Apps <span class="text-muted small fw-normal">(<code>/manage/intapps-status</code>)</span></h3>
                    <p class="small">
                        A read-only status dashboard for the (dormant by default) IntAppsAPI gateway integration &mdash; a separate MWBM platform iHymns can optionally connect to. Shows whether it's on, what it last heard from the gateway, and whether that went well, plus two buttons: <strong>test the connection now</strong> (rings the gateway and shows exactly what came back) and <strong>force a refresh</strong> of the cached feature-flag snapshot. Credentials themselves are set on <a href="#configuration">Settings</a>, not here &mdash; this page only reads status and makes on-demand calls.
                    </p>
                </section>

                <section id="api-keys" class="help-section card-admin mb-4">
                    <h2><i class="bi bi-key-fill me-2"></i>API Keys</h2>
                    <p class="role-badges">
                        <span class="badge bg-danger">Global Admin</span>
                    </p>
                    <p>
                        Mint, list and revoke the <strong>machine-to-machine</strong> API keys that external services use to call the public API <em>without</em> a user session. Distinct from the <a href="#native-api">Native API surface</a>, which uses a per-user login token &mdash; an API key is a long-lived, scoped credential for a system (e.g. an external lyrics-ingest pipeline). Available to global admins.
                    </p>
                    <h3 class="h6">How a key works</h3>
                    <ul>
                        <li><strong>Shown once.</strong> The raw key is generated server-side and displayed a single time in the create response &mdash; copy it then. iHymns stores only a scrambled fingerprint plus a short non-secret prefix, so it can never show you the key again. Lost it? Revoke and mint a new one.</li>
                        <li><strong>Scoped.</strong> Each key carries one or more scopes that limit what it can do, checked on every call — for example, a write scope for lyrics ingestion, or a read scope that lets a trusted integrator read the public catalogue at the key's <em>own</em> rate limit instead of the tighter anonymous limit (it does <strong>not</strong> unlock gated/copyrighted content). More scopes can be added later without re-issuing existing keys.</li>
                        <li><strong>Revocable.</strong> Toggle a key <em>inactive</em> to suspend it, or delete it outright. Both actions are written to the <a href="#activity-log">Activity Log</a>.</li>
                    </ul>
                    <h3 class="h6">Rate limits</h3>
                    <p>
                        API-key calls are rate-limited per key, so one integration can't starve the others. This is separate from the limiter that protects the heavy <em>public</em> reads &mdash; song lookups, search, the lightweight index, related songs and bulk reads each carry a generous per-minute ceiling and ask a flooding requester to slow down and retry shortly. Real clients never trip it.
                    </p>
                    <h3 class="h6">Usage &amp; per-key limits</h3>
                    <p>
                        The key list shows each key's <strong>requests today</strong> and its <strong>per-minute&nbsp;&middot;&nbsp;per-day</strong> ceilings. Click <em>Limits</em> on a key to set them (blank&nbsp;=&nbsp;no limit). A key over its window is asked to slow down and retry shortly. Setting a daily cap also makes that key's usage visible here.
                    </p>
                    <h3 class="h6">Developer quickstart</h3>
                    <ul>
                        <li><strong>Authenticate</strong> &mdash; send the key as a Bearer credential on every request.</li>
                        <li><strong>Read the catalogue</strong> &mdash; a read-scoped key reading song or catalogue data is metered against the key's own limit; without a key the same reads work but on the anonymous limit.</li>
                        <li><strong>Watch the headers</strong> &mdash; rate-limited responses carry limit / remaining / window headers, and tell you how long to wait before retrying. Honour them to self-throttle.</li>
                        <li><strong>Idempotency</strong> &mdash; for writes, send a retry-safe request ID so a retried request isn't applied twice.</li>
                        <li><strong>Explore live</strong> &mdash; the <a href="/manage/api-docs">API docs (Swagger UI)</a> have <em>Try it out</em> enabled.</li>
                    </ul>
                    <div class="gotcha small">
                        <strong>Gotcha:</strong> Treat a minted key like a password &mdash; it grants its scope to anyone holding it, with no user behind it. If a key leaks, revoke it immediately and re-issue; never paste a raw key into a ticket, chat or log.
                    </div>
                </section>

                <section id="webhooks" class="help-section card-admin mb-4">
                    <h2><i class="bi bi-broadcast me-2"></i>Webhooks</h2>
                    <p class="role-badges">
                        <span class="badge bg-danger">Global Admin</span>
                    </p>
                    <p>
                        Let an external system be <strong>told</strong> when something changes in iHymns &mdash; a song is created, updated or deleted, a songbook changes, a set list is shared, or a live service starts or ends. Unlike an <a href="#api-keys">API key</a> (which lets a partner <em>ask</em> us for data), a webhook lets us <em>push</em> a small signed notification to a URL the partner controls, the moment the event happens.
                    </p>
                    <h3 class="h6">The one rule about what a webhook contains</h3>
                    <p>
                        A webhook payload carries <strong>identity and metadata only &mdash; never content</strong>: an id, a title, which fields changed (by name, never their values), a link. It never contains lyrics, media, a share token or a service join code. A partner that needs the actual content fetches it through the read API, where licensing and access rules still apply. This is deliberate: a webhook is a doorbell, not a delivery van.
                    </p>
                    <h3 class="h6">Registering an endpoint</h3>
                    <ul>
                        <li><strong>Add a subscription</strong> &mdash; give it a label, the partner's <code>https://</code> URL, and tick which events it wants (a single event, a whole family like <em>every song event</em>, or everything).</li>
                        <li><strong>Verify it.</strong> A new or URL-changed subscription starts <em>pending</em> and receives nothing until it passes a one-off <strong>verification</strong>: we send a signed challenge, and the endpoint must echo it back. This proves the partner really controls that URL &mdash; so a subscription can't be pointed at some unsuspecting third party and then used to spray it with traffic.</li>
                        <li><strong>Signing secret.</strong> Each subscription has a secret used to sign every delivery (an <code>X-iHymns-Signature</code> header the receiver checks). It is shown once on create; you can <em>Reveal</em> it (logged) or <em>Rotate</em> it &mdash; rotation keeps the old secret valid for 24&nbsp;hours so the partner can roll over without downtime.</li>
                    </ul>
                    <h3 class="h6">Delivery, retries and dead-letters</h3>
                    <p>
                        The first attempt is near-instant. A failed delivery is retried on a widening schedule for about <strong>2.2 days</strong> (1&nbsp;min &rarr; 5&nbsp;min &rarr; &hellip; &rarr; 24&nbsp;hours); after that it is marked <em>dead</em> and shown in the delivery log, where you can <strong>re-drive</strong> it once the partner is healthy again. A subscription that keeps failing for days is auto-disabled with a red badge (re-enable is one click). Delivery is <em>at-least-once</em> &mdash; a receiver that occasionally sees the same event twice should de-duplicate on the event id in the header.
                    </p>
                    <h3 class="h6">Turning it on (and the drain job)</h3>
                    <p>
                        The whole feature is <strong>dormant</strong> until an admin enables it per channel on <a href="/manage/configuration">Configuration</a>. That card also holds the <strong>drain key</strong>: retries are pushed along by a tiny endpoint a scheduled job (cPanel cron or an uptime monitor) pokes every minute &mdash; <code>curl "/webhook-drain?key=&lt;drain key&gt;"</code>. Without that job, retries still make progress whenever the site has traffic, just more slowly; the Configuration card shows when the drain last ran so you can tell whether the job is wired.
                    </p>
                    <div class="gotcha small">
                        <strong>Gotcha:</strong> a subscription is walled to the environment (alpha / beta / production) it was created on &mdash; the three share one database but never each other's webhook traffic. Test on alpha first; a production partner should be registered on production.
                    </div>
                </section>

                <!-- ====================================================================
                     HELP
                     ==================================================================== -->

                <section id="api-docs" class="help-section card-admin mb-4">
                    <h2><i class="bi bi-file-earmark-code me-2"></i>API Docs (Swagger UI)</h2>
                    <p class="role-badges">
                        <span class="badge bg-primary">editor</span>
                        <span class="badge bg-warning text-dark">admin</span>
                        <span class="badge bg-danger">Global Admin</span>
                    </p>
                    <p>
                        <a href="/manage/api-docs">API Docs</a> is a browsable rendering of the project's OpenAPI
                        spec (<a href="/api-docs.yaml"><code>/api-docs.yaml</code></a>) via Swagger UI &mdash;
                        every public REST endpoint with its request / response shape, in one searchable page,
                        instead of reading the raw YAML file. It's the same spec the <a href="#native-api">Native
                        API surface</a> and <a href="#api-keys">API Keys</a> sections describe; this page is just
                        a friendlier way to browse it.
                    </p>
                    <p class="small text-muted">
                        Both the sidebar link and the page itself are available to editors and above.
                    </p>
                    <h3 class="h6">Authorize dialog</h3>
                    <p>
                        Click <strong>Authorize</strong> near the top of the page and paste a Bearer token (the
                        same kind described under <a href="#native-api">Native API surface</a>) to let Swagger UI
                        attach it to every request you try from here on. You don't need to re-enter it between
                        endpoints in the same visit.
                    </p>
                    <div class="gotcha small">
                        <strong>Gotcha:</strong> <em>Try it out</em> is enabled and fires <strong>real requests
                        against this environment</strong> &mdash; it is not a sandbox. A <em>Try it out</em> call
                        on a write endpoint (create, update, delete) really creates, updates, or deletes the row,
                        and is written to the <a href="#activity-log">Activity Log</a> exactly like any other
                        admin action. Read-only endpoints are safe to explore freely; think before you click
                        <em>Execute</em> on anything that writes.
                    </div>
                </section>

                <!-- ====================================================================
                     TROUBLESHOOTING
                     ==================================================================== -->

                <section id="troubleshooting" class="help-section card-admin mb-4">
                    <h2><i class="bi bi-life-preserver me-2"></i>Troubleshooting &amp; FAQs</h2>

                    <h3 class="h6">&ldquo;A page that should be in the menu isn't there.&rdquo;</h3>
                    <p class="small">
                        You don't have the permission for it. The menu only shows pages
                        you can use. Ask a global admin to grant you the role or
                        permission you need (see <a href="#entitlements">Role Permissions</a>).
                    </p>

                    <h3 class="h6">&ldquo;I clicked Save in the editor but my changes aren't there after a refresh.&rdquo;</h3>
                    <p class="small">
                        Two common causes:
                    </p>
                    <ul class="small">
                        <li>The save was rejected by validation. Look for an error toast at the top right of the editor.</li>
                        <li>You're editing on one device and viewing on another, with one of them having a service-worker cache that hasn't refreshed yet. A hard refresh (Cmd/Ctrl-Shift-R) typically clears it.</li>
                    </ul>

                    <h3 class="h6">&ldquo;I deleted a song by mistake.&rdquo;</h3>
                    <p class="small">
                        Open <a href="#deleted-songs">Deleted Songs</a> and click <strong>Restore</strong>. The song comes back exactly as it was, with its lyrics, credits, media, tags and edit history intact &mdash; deleting hid it, it never destroyed anything.
                    </p>
                    <p class="small">
                        Recovering a deleted song is <em>not</em> done through <a href="#revisions">Edit History &rarr; Restore</a> &mdash; that only undoes a bad <em>edit</em>. Deleted Songs is the page for undoing a bad <em>delete</em>.
                    </p>

                    <h3 class="h6">&ldquo;The dashboard / a /manage page is blank.&rdquo;</h3>
                    <p class="small">
                        This is rare. Try a hard refresh first. If still blank, append <code>?_debug=1&amp;_dev=1</code> to the URL on Alpha or Beta &mdash; the server will print any underlying PHP fatal at the bottom of the response. Capture that and pass it to a developer.
                    </p>

                    <h3 class="h6">&ldquo;A user reports the main app is showing the wrong songs / locking out features.&rdquo;</h3>
                    <p class="small">
                        Check three places, in order:
                    </p>
                    <ol class="small">
                        <li>Their <strong>access tier</strong> on <a href="#users">Users</a> &mdash; controls what they're allowed to see.</li>
                        <li>Their <strong>organisation membership</strong> &mdash; org licence bubbles down to members.</li>
                        <li><a href="#restrictions">Content Restrictions</a> &mdash; rules can block or require things on a per-user / per-org / per-platform basis.</li>
                    </ol>

                    <h3 class="h6">&ldquo;Two songs / two people / two anything look like duplicates.&rdquo;</h3>
                    <p class="small">
                        For people, use <a href="#musicians">Musicians &rarr; Merge</a>. For songs, use <a href="#duplicate-songs">Find Duplicates</a> &mdash; that page is the dedicated song-merge surface: it scores likely duplicates for you and lets you <strong>Link</strong> (keep both, cross-reference), <strong>Dismiss</strong> (not a duplicate), or <strong>Merge</strong> (collapse into one). For themes/tags, use <a href="#tags">Tags &amp; Themes &rarr; Merge</a>.
                    </p>

                    <h3 class="h6">&ldquo;Activity Log shows an action I don't recognise. What is it?&rdquo;</h3>
                    <p class="small">
                        Activity Log entries name the action and what it was done to in plain terms. The <strong>Entity type</strong> column tells you what kind of thing was acted on, the <strong>Entity ID</strong> tells you exactly which one. Click around &mdash; most actions are self-explanatory once you see the row.
                    </p>

                    <h3 class="h6">&ldquo;Where do I report a bug or request a feature?&rdquo;</h3>
                    <p class="small">
                        Either through your usual channel into the iHymns team, or by e-mail to your iHymns administrator. If you have GitHub access, file an issue at the project repository.
                    </p>
                </section>

            </main>
        </div>
    </div>

    <?php require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin-footer.php'; ?>
</body>
</html>
