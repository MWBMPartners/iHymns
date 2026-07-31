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
        'title' => 'Revisions Audit',
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
        'id'    => 'credit-people',
        'icon'  => 'bi-person-vcard',
        'title' => 'Credit People',
        'group' => 'Content',
    ],
    [
        'id'    => 'duplicate-songs',
        'icon'  => 'bi-files',
        'title' => 'Duplicate & Counterpart Songs',
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
        'title' => 'Access Tiers',
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
        'title' => 'Entitlements',
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
        'title' => 'Schema Audit',
        'group' => 'Operations',
    ],
    [
        'id'    => 'diagnostics',
        'icon'  => 'bi-terminal',
        'title' => 'SQL Diagnostics',
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
                        the entitlement for it. Ask a global admin to grant you the
                        relevant role or entitlement (see
                        <a href="#roles">Roles</a> and
                        <a href="#entitlements">Entitlements</a> below).
                    </p>
                    <p class="small text-muted mb-0">
                        Not sure which environment you're testing on? The public site's
                        header carries a colour-distinct badge next to the version number
                        &mdash; amber for <strong>Alpha</strong>, blue for <strong>Beta</strong>
                        &mdash; and that same version number links through to the
                        <strong>What's New</strong> page (#1583). Confirming this with a
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
                            Missing Numbers report, and manage the Credit People
                            registry. Cannot see Users, Organisations, or Operations
                            pages.
                        </dd>

                        <dt>Admin <span class="badge bg-warning text-dark">admin</span></dt>
                        <dd>
                            Everything an editor can do, plus: manage Users (create,
                            update, change role up to admin), manage Songbooks,
                            Organisations, User Groups, Access Tiers, Content
                            Restrictions, see Analytics, Activity Log, CCLI Report.
                            Cannot manage Entitlements or run Schema Audit /
                            Database Setup.
                        </dd>

                        <dt>Global Admin <span class="badge bg-danger">global_admin</span></dt>
                        <dd>
                            Everything. Including the safety-critical operations
                            pages (Database Setup, Schema Audit, Data Health,
                            Entitlements). Use sparingly — actions on these pages
                            can affect every user.
                        </dd>
                    </dl>

                    <p class="text-secondary small mb-0">
                        Roles are assigned on the <a href="#users">Users</a> page.
                        Role-vs-entitlement mappings can be customised on
                        <a href="#entitlements">Entitlements</a>, but the defaults
                        match the rules above.
                    </p>
                </section>

                <section id="dashboard" class="help-section card-admin mb-4">
                    <h2><i class="bi bi-speedometer2 me-2"></i>Dashboard</h2>
                    <p class="role-badges">
                        <span class="badge bg-primary">editor</span>
                        <span class="badge bg-warning text-dark">admin</span>
                        <span class="badge bg-danger">global_admin</span>
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
                        <strong>Role-gated sections (#641):</strong> the dashboard
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
                        <span class="badge bg-danger">global_admin</span>
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
                            (verify, tag, move to another songbook, export, delete).
                            <br><strong>Deleting is now recoverable</strong> (#1694). A deleted song
                            disappears from the app, from search and from this sidebar, but it is not
                            destroyed &mdash; it moves to <a href="#deleted-songs">Deleted Songs</a>,
                            keeping its lyrics, credits, media and full revision history, and one click
                            puts it back. Permanent removal is a separate, deliberately harder step on
                            that page. Deleting needs <code>delete_songs</code> &mdash; editors and
                            above (#1695).</li>
                    </ul>
                    <h3 class="h6">The eight tabs</h3>
                    <dl class="actions">
                        <dt>Metadata</dt>
                        <dd>Title, song number, songbook, CCLI number, Tune Name (e.g. <em>HYFRYDOL</em>), ISWC, language, region.
                            <p class="mt-2 mb-0"><strong>Musical key</strong> (#298) — the original key,
                            tempo in BPM and time signature. These show as a badge on the public song
                            page and give Transpose its starting point, so a musician can see what a
                            song is actually in before they play it. Tempo accepts 20–400 BPM; the key
                            and time-signature lists are fixed, so a typo cannot be saved.</p></dd>
                        <dt>Structure</dt>
                        <dd>
                            The actual lyrics, broken into sections: verses, choruses, bridges, and so on. Drag to reorder; auto-resizing text areas grow as you type.
                            <p class="mt-2 mb-2">
                                <strong>Paste &amp; Reflow</strong> (#1043) — the <em>Paste &amp; Reflow</em> button opens a modal where you paste a whole lyrics block; it auto-splits the text into classified sections (verse / chorus / bridge…) ProPresenter-style, and <strong>Apply</strong> turns them into components in one go — far quicker than adding each section by hand.
                            </p>
                            <p class="mb-2">
                                <strong>Chords</strong> — each section has a collapsible Chords box (it opens automatically once a section already has chords). Enter one line of chord symbols per lyric line, in reading order (e.g. <code>G  Em  C  D</code>); leave a section's box empty if it has none. Chords carry through to chord-chart export formats like ChordPro.
                            </p>
                            <p class="mb-2">
                                <strong>Per-line language, translations &amp; annotations</strong> (#1088 / #1235) — expand the per-line panel under the section to attach, line by line, a <em>translation</em> or <em>transliteration</em> (romanization) of a lyric line and Genius-style <em>annotations</em> (explanation / reference / scripture / history / trivia). These anchor to the individual lyric line, not the section's text blob, and are saved as you add them (save the song first so each line has an ID).
                            </p>
                            <p class="mb-2">
                                <strong>Arrangement</strong> — below the section list, the Arrangement panel sets the song's actual running order for playback and export: which sections play, in what sequence, and how many times each repeats (e.g. Verse 1, Chorus, Verse 2, Chorus, Bridge, Chorus). Add sections from the pool and reorder them with the move-left/move-right buttons, or start from a quick-action preset ("Verses only", "Chorus after each verse", …) and adjust from there. Leaving it empty plays the sections in the order they're listed above.
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
                        <dd>Writer, composer, arranger, adaptor, translator, copyright holder. Names autocomplete from the <a href="#credit-people">Credit People</a> registry so you don't get duplicate spellings.</dd>
                        <dt>Links</dt>
                        <dd>External-website links for this song (Hymnary.org, Internet Archive scans, Wikipedia, YouTube performances, Spotify, etc.). Paste a URL and the provider auto-detects; see <a href="#external-links">External Links</a> for how the shared editor works (#833 / #841).</dd>
                        <dt>Tags</dt>
                        <dd>Categorical tags (e.g. <em>Easter</em>, <em>Communion</em>) that drive Browse-by-Theme in the main app and can be used as targets for <a href="#restrictions">Content Restrictions</a>.</dd>
                        <dt>Media</dt>
                        <dd>Accompanying files for the song (#853) — audio recordings, sheet-music PDFs, MIDI sequences and MusicXML notation. Files inherit the song's content-access rules, so a gated song gates its media automatically. Audio is stored on disk and served via the gated <code>/song-media/&lt;id&gt;</code> route; sheet music / MIDI / MusicXML live in the database.</dd>
                        <dt>Preview</dt>
                        <dd>Read-only render of the finished song as users will see it.</dd>
                        <dt>Revisions</dt>
                        <dd>
                            Every previous edit to this song, newest first, showing what kind of change it was, when, and by whom, with a <strong>Restore</strong> button on each row. <strong>Restore puts the song back to the state that row's edit left it in</strong> (i.e. it re-applies that historical change) &mdash; if you're used to the previous editor, note this lands one step further forward than its Restore did, which undid the change instead. There is no side-by-side comparison here yet, so if you are unsure which row you want, restore the most likely one and check the song: Restore always creates a new revision rather than rewriting history, so nothing is destroyed either way and you can step again from wherever you land.
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
                    <h3 class="h6">Bulk Import ZIP (#664 / #676 / #882)</h3>
                    <p>
                        For onboarding an entire songbook at once. Upload a ZIP whose top-level folders match <code>&lt;Hymnal Name&gt; [&lt;ABBR&gt;]/</code> and whose files are one of:
                    </p>
                    <ul>
                        <li><strong>Plain text</strong> — <code>&lt;number&gt; (&lt;ABBR&gt;) - &lt;Title&gt;.txt</code> with the canonical title / blank / section-marker / lyric-block layout the scrapers emit. The number in the filename and folder ABBR cross-check; mismatches are reported per-entry.</li>
                        <li><strong>OpenSong XML</strong> (#882) — <code>.xml</code> or <code>.opensong</code> files anywhere inside the hymnal folder. The song number comes from the <code>&lt;hymn_number&gt;</code> element first, then any leading digits in the filename, then a per-songbook auto-increment. <code>&lt;author&gt;</code> splits on <code>/</code>, <code>&amp;</code>, <code>,</code>, <code>;</code> into the writers list. Chord rows (lines beginning with <code>.</code>) and comment rows (lines beginning with <code>;</code>) are stripped from the lyrics.</li>
                    </ul>
                    <p>
                        Both file kinds may be mixed in the same archive — the importer dispatches per entry by extension. The summary's <code>parsed_by_format</code> counter shows how many of each landed.
                    </p>
                    <ul>
                        <li><strong>INSERT-only contract:</strong> if a songbook or song already exists, it's left untouched — never overwritten. The summary reports created vs. existing counts so you can see what landed.</li>
                        <li><strong>Live progress widget:</strong> the upload completes almost immediately; the actual import runs server-side. A small fixed-position card pinned bottom-right polls the job status, shows a progress bar, and survives navigation between admin pages and the public app. Hard-reload the page mid-import and the widget reattaches via localStorage.</li>
                        <li><strong>Notification on completion:</strong> a row is written to <a href="#notifications">Notifications</a> when the worker finishes, and (if you've granted permission) a native browser notification fires.</li>
                        <li><strong>Caps:</strong> 100 MB upload, 100,000 entries per archive, 5 MiB per uncompressed entry, 500 MiB cumulative uncompressed. These are zip-bomb defences (#682) — far above any real bundle.</li>
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
                        <li><strong>ChordPro</strong> (<code>.cho</code> / <code>.pro</code> / <code>.chopro</code> / <code>.crd</code> / <code>.chord</code>) — import + export (#1264). One ChordPro document is one song (OnSong / OpenSong / WorshipTools interop). On import it files under a <code>ChordPro Import</code> (CHORDPRO) songbook unless the filename uses the <code>&lt;#&gt; (&lt;ABBR&gt;) - &lt;Title&gt;</code> shape; the lyrics-only exporter round-trips with it.</li>
                        <li><strong class="text-warning">EasyWorship</strong> (SQLite <code>Songs.db</code> + <code>SongWords.db</code>) — import + export. <span class="badge bg-warning text-dark">beta</span>
                            <div class="alert alert-warning small mt-1 mb-1">
                                <i class="bi bi-exclamation-triangle me-1"></i>
                                <strong>EasyWorship import/export is a beta, unverified feature.</strong> It reads/writes the core EasyWorship SQLite schema (the <code>song</code> + <code>word</code>[RTF] tables) and round-trips correctly within iHymns, but it has <em>not</em> been verified against a live EasyWorship install — a real EasyWorship may expect additional index/FTS tables when reading an exported <code>Songs.db</code>. Treat results as provisional and check them in EasyWorship before relying on them. (#1058 / #1059)
                            </div>
                        </li>
                    </ul>
                    <p>
                        <strong>Dedupe on import</strong> (#1051): tick <em>"Skip existing (by title)"</em> next to the Import button to skip any incoming song whose title already exists in the same songbook (matched ignoring case, punctuation and accents) — catches duplicates that carry a different number. Imports are always INSERT-only; existing rows are never overwritten.
                    </p>
                    <p>
                        <strong>Lines per slide</strong> (#1065): the <em>"Lines/slide"</em> box next to the export dropdowns caps how many lyric lines land on each slide when exporting to the presentation formats (ProPresenter 6 / FreeShow / OpenLP / OpenSong / VideoPsalm) — useful for lower-third layouts. <code>0</code> keeps each verse whole. Your value is remembered as your default for next time.
                    </p>

                    <h3 class="h6">Language tagging (IETF BCP 47, #240 / #281 / #681 / #687)</h3>
                    <p>
                        The Metadata tab's Language field is a composite IETF picker — three sub-fields that compose into a single saved tag:
                    </p>
                    <ul>
                        <li><strong>Language</strong> (required) — e.g. <em>English</em> (<code>en</code>) or <em>Portuguese</em> (<code>pt</code>).</li>
                        <li><strong>Script</strong> (optional) — only when the script differs from the language default. e.g. <em>Simplified Chinese</em> for Mandarin written in Hans, or <em>Latin</em> for Serbian written Latn instead of the default Cyrl.</li>
                        <li><strong>Region</strong> (optional) — e.g. <em>United Kingdom</em> for British English (<code>en-GB</code>) vs. <em>United States</em> for American English (<code>en-US</code>).</li>
                    </ul>
                    <p>
                        The "IETF tag:" line below the picker shows the composed tag live as you type, with a human-readable rendering next to it (e.g. <em>"Spanish (Mexico)"</em> for <code>es-MX</code>). The full ISO 639 / ISO 15924 / ISO 3166-1 vocabulary is loaded from <code>tblLanguages</code> + <code>tblLanguageScripts</code> + <code>tblRegions</code> + <code>tblLanguageVariants</code> — every IANA-registered subtag — so the picker stays in sync with the songbook editor's identical picker. One source of truth across both surfaces. (#681 / #738)
                    </p>
                    <p class="small text-muted mb-2">
                        The full IANA Language Subtag Registry plus CLDR English display names ship as bundled snapshots in <code>appWeb/.sql/data/</code>. <a href="/manage/setup-database#bcp47">Database Setup → "Refresh BCP 47 reference data"</a> has a live-fetch button if you need to pull the latest IANA / CLDR updates.
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
                        <span class="badge bg-danger">global_admin</span>
                    </p>
                    <p>
                        End users in the main app can submit a song request via the dedicated <a href="/request">/request</a> page &mdash; reachable from the &ldquo;Report a missing song or suggest a correction&rdquo; link at the bottom of every song page, the &ldquo;Suggest a Missing Song&rdquo; CTA on <a href="/help">/help</a>, and a deep-link from the editor's missing-numbers tool with the songbook + number prefilled. Submissions queue offline and replay automatically when the user is back online; each submission also returns a tracking ID the user can quote when following up. All paths land in this triage list.
                    </p>
                    <h3 class="h6">Key actions</h3>
                    <ul>
                        <li><strong>Filter</strong> by status: Pending, Reviewed, Added, Declined, or All.</li>
                        <li>Change a request's status inline.</li>
                        <li>Add an admin <strong>note</strong> (e.g. "merged with #1234", "no copyright clearance").</li>
                        <li>If the request was fulfilled by an existing song, paste its ID into <strong>Resolved Song ID</strong>.</li>
                        <li>Click <strong>Start editing</strong> to open the editor pre-loaded with a draft song matching the request, with a back-link to the request.</li>
                    </ul>
                    <div class="gotcha small">
                        <strong>Gotcha:</strong> There's no bulk update. Long queues take time. Filter by Pending and work top-down.
                    </div>
                </section>

                <section id="revisions" class="help-section card-admin mb-4">
                    <h2><i class="bi bi-clock-history me-2"></i>Revisions Audit</h2>
                    <p class="role-badges">
                        <span class="badge bg-warning text-dark">admin</span>
                        <span class="badge bg-danger">global_admin</span>
                    </p>
                    <p>
                        A read-only audit trail of every song edit, ever. Useful for "who changed Amazing Grace last Tuesday?" and as the entry point for restoring an older version.
                    </p>
                    <h3 class="h6">Key actions</h3>
                    <ul>
                        <li>Filter by user, song ID (partial match works), action (create / edit / restore / delete), and time range (7 / 30 / 90 / 365 days).</li>
                        <li>Click <strong>Open in editor</strong> on a row to jump straight into that song with the Revisions tab already open, listing every previous edit with a Restore button on each.</li>
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
                        <span class="badge bg-danger">global_admin</span>
                    </p>
                    <p>
                        Deleting a song no longer destroys it. Since <a href="https://github.com/MWBMPartners/iHymns/issues/1694">#1694</a> a delete is
                        <strong>recoverable</strong>: the song is hidden from every public and editorial
                        surface &mdash; the app, search, songbook lists, the editor sidebar, exports &mdash;
                        but the record itself is untouched. Its components, credits, media links, tags and
                        complete revision history are all still there. This page is where those songs wait.
                    </p>
                    <h3 class="h6">Key actions</h3>
                    <dl class="actions">
                        <dt>Restore</dt>
                        <dd>
                            Puts the song straight back exactly as it was. Because deleting writes nothing
                            else anywhere &mdash; no redirects, no cascades &mdash; there is nothing to
                            repair on the way back: favourites, set lists, Work membership and revision
                            history were never touched.
                        </dd>
                        <dt>Purge</dt>
                        <dd>
                            The old permanent delete, and the <em>only</em> way to reach it. A live song can
                            no longer be destroyed in one step by anybody &mdash; it has to be deleted
                            first, then purged from this page. Purge takes the revision history with it and
                            cannot be undone, so it asks you to type the song's ID to confirm. You can
                            optionally point the purged ID at a surviving song, so anyone following an old
                            link or bookmark lands somewhere sensible instead of a dead end.
                        </dd>
                    </dl>
                    <h3 class="h6">Who can do what</h3>
                    <ul>
                        <li>Seeing this page and using <strong>Restore</strong> needs
                            <code>delete_songs</code> &mdash; the same privilege as deleting in the first
                            place, which since <a href="https://github.com/MWBMPartners/iHymns/issues/1695">#1695</a>
                            is <strong>editors and above</strong>. Deletion was briefly restricted to
                            admins only while it was still permanent; now that it is recoverable, that
                            restriction has been lifted.</li>
                        <li><strong>Purge</strong> needs its own separate privilege,
                            <code>purge_songs</code>, which stays with admins and global admins. This is
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
                        <span class="badge bg-danger">global_admin</span>
                    </p>
                    <p>
                        For every songbook, shows the highest number assigned, the count of songs present, and the gaps in numbering. Long gaps are collapsed into ranges (e.g. "<code>#400&ndash;#500 &middot; 101 missing</code>") so the page stays readable.
                    </p>
                    <h3 class="h6">When to use it</h3>
                    <p class="small">
                        Spot songs that haven't been added yet, find renumbering gaps after deletions, or verify that a freshly-imported songbook is complete.
                    </p>
                    <div class="gotcha small">
                        <strong>Gotcha:</strong> The report is read-only. Use the Song Editor to actually fill the gaps.
                    </div>
                </section>

                <section id="songbooks" class="help-section card-admin mb-4">
                    <h2><i class="bi bi-book me-2"></i>Songbooks</h2>
                    <p class="role-badges">
                        <span class="badge bg-warning text-dark">admin</span>
                        <span class="badge bg-danger">global_admin</span>
                    </p>
                    <p>
                        Songbooks are the top-level container — every song lives in exactly one. This page is where you create, name, colour, and order them.
                    </p>
                    <h3 class="h6">Per-songbook fields</h3>
                    <dl class="actions">
                        <dt>Abbreviation</dt><dd>Short identifier (e.g. <code>HYM</code>). Unique. Max 10 chars, alphanumeric. This is the natural key referenced by every song.</dd>
                        <dt>Name</dt><dd>Friendly name (e.g. "Methodist Hymnal").</dd>
                        <dt>Display order</dt><dd>Numeric sort key — lower numbers appear first in the app.</dd>
                        <dt>Colour</dt><dd>Hex code (<code>#RRGGBB</code>) used as the songbook tile colour on the home page. <strong>Leave blank</strong> to let the system auto-pick a tone from the current theme palette (#677) — the result is consistent with the rest of the UI and changes with the user's chosen theme.</dd>
                        <dt>Official flag</dt><dd>Marks "real" published hymnals vs. user-curated collections / pseudo-songbooks. Used by the home-page filter to separate the two surfaces.</dd>
                        <dt>Publisher / Publication year / Copyright</dt><dd>Issuing body, year of publication, and the copyright statement. Optional, surface in search and reports.</dd>
                        <dt>Affiliation</dt><dd>Issuing organisation, drawn from a curated registry (#670). Type to search; new affiliations get added on save. Use this rather than free-text Publisher when the same organisation issues multiple songbooks.</dd>
                        <dt>Language (IETF BCP 47)</dt><dd>The songbook's primary language as a composite IETF tag (#673 / #681) — same picker as the song editor, with three sub-fields: <strong>Language</strong> (required), <strong>Script</strong> (optional — only when the script differs from the language default), and <strong>Region</strong> (optional — e.g. <code>en-GB</code> vs <code>en-US</code>). Leave blank for multi-lingual collections.</dd>
                        <dt>Online links — Official website / Internet Archive / Wikipedia (#672)</dt><dd>Free-text URLs. Used as outbound references on the songbook detail page so users can verify the source.</dd>
                        <dt>Authority identifiers — WikiData ID, OCLC, OCN, LCP, ISBN, ARK, ISNI, VIAF, LCCN, LC Class (#672)</dt><dd>Standard cataloguing identifiers from major library and authority systems. All optional. Useful for cross-referencing and de-duplicating against external catalogues.</dd>
                    </dl>
                    <h3 class="h6">Renaming an abbreviation</h3>
                    <p>
                        Abbreviations are the natural key, so renaming is opt-in: you must tick the <strong>"Also rename song references"</strong> checkbox to cascade the rename to every song that uses it. Without that checkbox, songs keep the old abbreviation and orphan from the renamed songbook.
                    </p>
                    <h3 class="h6">Colour picker</h3>
                    <p>
                        The <strong>Colour</strong> field accepts a 7-char <code>#RRGGBB</code> hex value (#715). The browser-native colour picker writes the canonical lower-case hex back into the text field when you confirm a swatch — handy if you want to copy the value into another tool. Leave the field blank to let the system auto-pick a tone the catalogue isn't already using; the next save fills the field in for you.
                    </p>
                    <h3 class="h6">Auto-colour bulk action</h3>
                    <p>
                        Two destructive-but-recoverable buttons live at the top of the songbook list (#716):
                    </p>
                    <dl class="actions">
                        <dt>Auto-fill blank colours</dt>
                        <dd>Walks every songbook; rows with NULL or non-<code>#RRGGBB</code> colours get a fresh palette pick. Existing valid hex values are left alone — idempotent, safe to re-run.</dd>
                        <dt>Reassign every colour</dt>
                        <dd>Overwrites every <code>Colour</code> value, regardless of whether it was set already. Gated by typing the literal phrase <strong>REASSIGN ALL</strong> — defence-in-depth so a stray click never re-themes the whole catalogue.</dd>
                    </dl>
                    <h3 class="h6">Cascade delete</h3>
                    <p>
                        The default Delete refuses if any song still references the songbook abbreviation. Admin / global_admin can use <strong>Cascade delete</strong> instead, which removes the songbook AND every song in it AND every credit / tag / chord / translation that referenced those songs (#706). Server-side typed-confirmation gate: the curator must type the songbook abbreviation exactly. The FK chain handles the rest atomically.
                    </p>
                    <div class="gotcha small">
                        <strong>Gotcha:</strong> Deleting a songbook does <em>not</em> delete its songs unless you use Cascade delete. The standard UI refuses if any song still references its abbreviation; reassign or delete those songs first.
                    </div>
                    <div class="gotcha small">
                        <strong>Tip:</strong> The home-page tile grid (#678) shows official hymnals first, with a language filter (#679 / #736 v2) that lets users pick which languages to <em>show</em> across both songbook tiles AND individual song listings (search, popular, recently-viewed). Multi-select is supported; signed-in users get the choice persisted to their account and synced across devices. The <strong>Misc</strong> pseudo-songbook is always pinned to the bottom of the grid (#717) regardless of <code>DisplayOrder</code> — it's a catch-all and should never out-rank a curated hymnal. Songbooks AND songs without a Language field always show, regardless of the filter — useful for catch-all collections.
                    </div>
                </section>

                <section id="songbook-series" class="help-section card-admin mb-4">
                    <h2><i class="bi bi-collection-fill me-2"></i>Songbook Series</h2>
                    <p class="role-badges">
                        <span class="badge bg-warning text-dark">admin</span>
                        <span class="badge bg-danger">global_admin</span>
                    </p>
                    <p>
                        A <strong>Series</strong> groups songbooks that belong together as equals &mdash; volumes of one collection (e.g. <em>Songs of Fellowship</em> 1 / 2 / 3 / 4) or a themed compilation where no single book is the &ldquo;root.&rdquo; It's the peer-to-peer counterpart to the parent-songbook hierarchy (#782): use a parent FK when one book contains another, use a Series when several books simply sit side by side. Gated by <code>manage_songbooks</code> (the same entitlement as the Songbooks page).
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
                        <span class="badge bg-danger">global_admin</span>
                    </p>
                    <p>
                        The editor for the block-based layouts the song <em>Print</em> path uses (#1350). A template is an ordered list of blocks (title, lyrics, credits, copyright, etc.) plus page options, stored in <code>tblPrintTemplates</code>. Curated/global templates have no owner and are offered to everyone. Gated by <code>manage_songbooks</code>.
                    </p>
                    <h3 class="h6">Key actions</h3>
                    <ul>
                        <li><strong>Create / Edit</strong> a template by arranging blocks and setting page options.</li>
                        <li><strong>Live preview</strong> renders through the <em>same</em> renderer the print path uses, so what you see is byte-identical to the printed page &mdash; one source of truth, no &ldquo;looked fine in the editor, broke on paper.&rdquo;</li>
                        <li><strong>Delete</strong> a template you no longer want offered.</li>
                    </ul>
                    <div class="gotcha small">
                        <strong>Gotcha:</strong> The block model and the renderer live in <code>js/modules/print.js</code>; this page only writes the rows. Add a new block <em>type</em> in code, not here.
                    </div>
                </section>

                <section id="credit-people" class="help-section card-admin mb-4">
                    <h2><i class="bi bi-person-vcard me-2"></i>Credit People</h2>
                    <p class="role-badges">
                        <span class="badge bg-warning text-dark">admin</span>
                        <span class="badge bg-danger">global_admin</span>
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
                    <h3 class="h6 mt-3">Bulk promote (#846)</h3>
                    <p>
                        When a fresh deployment has hundreds of typed credit names that haven't been registered, click <strong>Bulk promote with fuzzy-match</strong> on the Credit People page header. The bulk page surfaces every name cited on at least one song that doesn't have a registry row, scores each against the existing registry rows (and against other candidates), and lets you pick per-row: <em>Register as new</em>, <em>Merge into existing</em> (re-points every credit on every song to the canonical row's name), or <em>Skip</em>. The whole submit runs in a single transaction with one <code>bulk_run_id</code> on the audit log so you can review the run as a unit.
                    </p>
                </section>

                <section id="duplicate-songs" class="help-section card-admin mb-4">
                    <h2><i class="bi bi-files me-2"></i>Duplicate &amp; Counterpart Songs</h2>
                    <p class="role-badges">
                        <span class="badge bg-primary">editor</span>
                        <span class="badge bg-warning text-dark">admin</span>
                        <span class="badge bg-danger">global_admin</span>
                    </p>
                    <p>
                        The unified review surface for songs that look like the same hymn appearing in more than one place (#1215). It folds together exact duplicates (same normalised title) and fuzzy counterparts scored across books. The old <code>/manage/song-link-suggestions</code> page was absorbed here and now just redirects to this one.
                    </p>
                    <h3 class="h6">How candidates are found</h3>
                    <p class="small">
                        Scoring is the shared similarity engine in <code>includes/song_similarity.php</code> (normalise &rarr; Levenshtein &rarr; Jaccard &rarr; blend) &mdash; the same maths the editor's inline panel and the batch builder use. A nightly batch (<code>build-song-link-suggestions.php</code>) writes scored pairs with a confidence and signal into <code>tblSongLinkSuggestions</code>; exact-title clusters are detected live.
                    </p>
                    <h3 class="h6">Per-action what-it-does</h3>
                    <dl class="actions">
                        <dt>Link <span class="badge bg-primary">edit_songs</span></dt><dd>Records that two songs in <em>different</em> books are the same hymn (writes <code>tblSongLinks</code>) so the public site can cross-reference them. Non-destructive.</dd>
                        <dt>Dismiss <span class="badge bg-primary">edit_songs</span></dt><dd>Hides a pair you've judged <em>not</em> a duplicate. A cluster only disappears once <em>all</em> its pairs are dismissed.</dd>
                        <dt>Merge <span class="badge bg-warning text-dark">manage_duplicate_songs</span></dt><dd>The destructive one: collapses two song records into one. Merging two songs that share the <em>same official songbook</em> additionally requires a type-to-confirm guard (<code>force=1</code>) because that's the riskiest case.</dd>
                    </dl>
                    <div class="gotcha small">
                        <strong>Gotcha:</strong> Link and Dismiss are reversible bookkeeping; Merge is not. Use Link when both copies should stay (e.g. the same hymn in two hymnals); reserve Merge for genuine accidental duplicates.
                    </div>
                </section>

                <section id="works" class="help-section card-admin mb-4">
                    <h2><i class="bi bi-diagram-3 me-2"></i>Works</h2>
                    <p class="role-badges">
                        <span class="badge bg-warning text-dark">admin</span>
                        <span class="badge bg-danger">global_admin</span>
                    </p>
                    <p>
                        A <strong>Work</strong> groups multiple <code>tblSongs</code> rows that represent the same underlying composition across different songbooks / arrangements / translations &mdash; mirrors the <a href="https://musicbrainz.org/doc/Work" target="_blank" rel="noopener noreferrer">MusicBrainz Work</a> &harr; Recording relationship. So <em>Amazing Grace</em>, which appears in dozens of hymnals under slightly different titles, lives as one Work with each songbook entry as a member.
                    </p>
                    <h3 class="h6">Key actions</h3>
                    <dl class="actions">
                        <dt>Create</dt><dd>Title + slug (auto from title) + optional ISWC + optional parent Work + optional notes. Members are added via the Edit modal once the row exists.</dd>
                        <dt>Edit</dt><dd>Add / remove member songs (typeahead over the whole catalogue), mark one as <em>canonical</em>, set sort order, attach external links (the provider dropdown auto-detects from the URL).</dd>
                        <dt>Delete</dt><dd>Memberships and external links cascade away with the Work. Child Works (if any) <strong>orphan</strong> &mdash; their <code>ParentWorkId</code> goes to <code>NULL</code> &mdash; rather than cascade-delete.</dd>
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
                        <span class="badge bg-danger">global_admin</span>
                    </p>
                    <p>
                        The registry of the categorical tags (e.g. <em>Easter</em>, <em>Communion</em>, <em>Grace</em>) that drive Browse-by-Theme in the main app and can be used as targets for <a href="#restrictions">Content Restrictions</a>. Curators add, rename, and merge tags here; the editor's Tags tab just attaches them to songs. Gated by <code>manage_tags</code>.
                    </p>
                    <h3 class="h6">Standard theme vocabulary (#1152)</h3>
                    <p>
                        The page seeds the CCLI / SongSelect theme taxonomy (the OpenLyrics <code>themelist.txt</code>) into the tag list via <code>migrate-seed-theme-vocabulary.php</code> &mdash; a two-level Parent/Child hierarchy (<code>ParentId</code> self-FK) with each tag carrying a <code>Source</code> of either <code>curator</code> or <code>ccli-openlyrics</code>. Never hand-type a theme list or re-seed ad-hoc; grow the standard vocabulary through that migration.
                    </p>
                    <h3 class="h6">Canonicalisation (#1222)</h3>
                    <p>
                        Curator-typed variants (e.g. <em>Xmas</em> vs <em>Christmas</em>) are folded into the standard themes by the <strong>canonicalisation suggestions</strong> on this page, which reuse the shared <code>includes/song_similarity.php</code> scorer. Picking a suggestion runs the same irreversible <strong>Merge</strong> as Credit People &mdash; the variant becomes the source and is deleted, the standard theme is the survivor and every song re-points to it.
                    </p>
                    <div class="gotcha small">
                        <strong>Gotcha:</strong> Merge is atomic and irreversible. Confirm the survivor is the canonical theme before you click through.
                    </div>
                </section>

                <section id="languages" class="help-section card-admin mb-4">
                    <h2><i class="bi bi-translate me-2"></i>Languages</h2>
                    <p class="role-badges">
                        <span class="badge bg-warning text-dark">admin</span>
                        <span class="badge bg-danger">global_admin</span>
                    </p>
                    <p>
                        The registry of IETF BCP 47 language codes (<code>tblLanguages</code>) that backs the Language picker in the Song Editor and Songbooks, and the home-page language filter. Most rows are seeded from the IANA Language Subtag Registry by a migration and are correct out of the box &mdash; this page is the manual escape hatch for the handful of cases the bundled data doesn't cover. Gated by <code>manage_languages</code>.
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
                        <span class="badge bg-danger">global_admin</span>
                    </p>
                    <p>
                        A <strong>Collection</strong> is a curated, cross-songbook grouping of songs &mdash; a themed selection (e.g. &ldquo;Carols for Christmas Eve&rdquo;) that draws from any number of songbooks without changing where each song <em>lives</em>. Gated by <code>manage_songbooks</code> (the same entitlement as the Songbooks page).
                    </p>
                    <h3 class="h6">Naming note (#1223)</h3>
                    <p class="small">
                        The user-facing label is <strong>&ldquo;Collection&rdquo;</strong>, but this is presentation copy only &mdash; internally the feature is still <em>catalogue</em>: the route is <code>/manage/catalogues</code>, the tables are <code>tblCatalogues</code> / <code>tblCatalogueSongs</code>, and the audit log keys are <code>admin.catalogues.*</code>. So a developer reading logs and a curator reading the UI are looking at the same thing under two names.
                    </p>
                    <div class="gotcha small">
                        <strong>Gotcha:</strong> A Collection never moves a song. Each song's home is still its songbook (<code>tblSongs.SongbookAbbr</code>); a Collection just references it. Deleting a Collection leaves every member song untouched.
                    </div>
                </section>

                <section id="external-links" class="help-section card-admin mb-4">
                    <h2><i class="bi bi-link-45deg me-2"></i>External Links</h2>
                    <p class="role-badges">
                        <span class="badge bg-warning text-dark">admin</span>
                        <span class="badge bg-danger">global_admin</span>
                    </p>
                    <p>
                        Songs, Songbooks, Credit People and Works all support a <strong>card-list editor</strong> for external links &mdash; controlled-vocabulary providers (Wikipedia, Hymnary.org, Spotify, IMSLP, MusicBrainz, etc.) backed by <code>tblExternalLinkTypes</code>. Each link carries an optional Note and a curator-set Verified flag.
                    </p>
                    <h3 class="h6">URL auto-detect (#841)</h3>
                    <p>
                        Paste a URL into the URL field of any external-link row and the provider dropdown auto-selects the matching registry entry &mdash; Wikipedia detects Wikipedia, YouTube detects YouTube, Spotify detects Spotify, etc. The detector respects manual choices: if you pick a provider before pasting, your choice wins.
                    </p>
                    <p>
                        The detector lives in a single global module &mdash; <code>js/modules/external-link-detect.js</code> &mdash; loaded on every <code>/manage/*</code> page. Every consumer (Songbook editor, Works editor, Credit People editor as it's added) inherits automatically.
                    </p>
                    <h3 class="h6 mt-3">URL patterns (#845)</h3>
                    <p>
                        Provider rules live in the <code>tblExternalLinkPatterns</code> table &mdash; curator-editable at <a href="/manage/external-link-types">/manage/external-link-types</a>. Add a new provider, sub-domain or path-prefix-discriminated rule (e.g. <code>musicbrainz.org/work/</code>) at any time without a code deploy. Lower priority numbers win, so put more-specific patterns first. The JS module falls back to a bundled rule list on pre-migration deployments so behaviour stays consistent during rollout.
                    </p>
                    <h3 class="h6">Categories</h3>
                    <p>Links group on the public site under: <em>Official, Information, Read, Sheet music, Listen, Watch, Purchase, Authority, Social, Other</em>. The seeded type registry decides which category each provider belongs to; curators don't pick the category &mdash; it's derived from the type.</p>
                </section>

                <section id="mobile-admin" class="help-section card-admin mb-4">
                    <h2><i class="bi bi-phone me-2"></i>Mobile admin (responsive list views)</h2>
                    <p>
                        Admin list pages opt into a column-priority responsive convention (#842). Tag the table <code>.admin-table-responsive</code>, then mark each <code>&lt;th&gt;</code> + <code>&lt;td&gt;</code> with <code>data-col-priority="primary"</code>, <code>"secondary"</code>, or <code>"tertiary"</code>. Below 992px tertiary columns hide; below 768px secondary columns hide too. Primary columns are always visible.
                    </p>
                    <p>
                        Pages currently opted in include Credit People, Songbooks, Songbook Series, Works and several other admin lists. The convention is documented in <code>DEV_NOTES.md</code>; rolling it forward to the remaining list pages is a per-page cosmetic change with zero CSS work.
                    </p>
                </section>

                <section id="restrictions" class="help-section card-admin mb-4">
                    <h2><i class="bi bi-shield-lock me-2"></i>Content Restrictions</h2>
                    <p class="role-badges">
                        <span class="badge bg-warning text-dark">admin</span>
                        <span class="badge bg-danger">global_admin</span>
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
                        <li><strong>Hide a song from a specific platform</strong> &mdash; <em>block_platform</em> with target = platform name.</li>
                        <li><strong>Restrict copyrighted songs to CCLI holders</strong> &mdash; <em>require_licence</em> with target = <code>ccli</code>.</li>
                        <li><strong>Allow only one user to see a draft song</strong> &mdash; <em>block_user</em> for everyone (low priority) plus an Allow rule (high priority) for that user.</li>
                    </ul>
                    <div class="gotcha small">
                        <strong>Gotcha:</strong> Rules are evaluated <em>at request time</em>. Changes take effect on the next page load &mdash; but data already cached on a user's device may lag a few minutes.
                    </div>
                </section>

                <section id="tiers" class="help-section card-admin mb-4">
                    <h2><i class="bi bi-stars me-2"></i>Access Tiers</h2>
                    <p class="role-badges">
                        <span class="badge bg-danger">global_admin</span>
                    </p>
                    <p>
                        Tiers are bundles of capabilities. The seven original caps are view lyrics, view copyrighted lyrics, play audio, download MIDI, download PDF, save offline, and requires CCLI. Every user is assigned one tier, which controls what UI controls they see in the main app &mdash; and, when content gating is switched on (see below), what the API will actually send them.
                    </p>
                    <h3 class="h6">Caps are extensible (#1352)</h3>
                    <p>
                        The capability list is <strong>not</strong> a fixed seven &mdash; it's a registry (<code>TIER_CAPS</code> in <code>includes/access_tier_validation.php</code>). The seven originals each have their own column for back-compatibility with the native-app API contract; <em>new</em> caps are stored in a single <code>Capabilities</code> JSON column, so adding one needs no schema change to <code>tblAccessTiers</code>. The tier checkbox grid on this page, both API CRUD endpoints, the public <code>access_tiers</code> API emit and the content-gating enforcement all derive their cap list from that one registry.
                    </p>
                    <div class="gotcha small" style="border-left-color: var(--bs-info);">
                        <strong>How to add a new gateable feature:</strong>
                        <ol class="mb-0">
                            <li>Add <strong>one line</strong> to <code>TIER_CAPS</code> in <code>includes/access_tier_validation.php</code> &mdash; e.g. <code>'CanRequestSongs' =&gt; ['Requests', 'Submit song requests', 'json', 0]</code> (label, description, storage, default).</li>
                            <li>Run the JSON-backed tier-capabilities migration card on <a href="#setup-database">Database Setup</a> (it adds the <code>Capabilities</code> column the first time). Migrations are web-run and are <em>not</em> auto-applied on deploy.</li>
                            <li>That's it &mdash; the admin checkbox here, both API CRUD endpoints (<code>admin_tier_create</code> / <code>admin_tier_update</code>), the <code>access_tiers</code> API emit (as <code>canRequestSongs</code>) and content gating (<code>checkTierAccess</code>) all pick it up with no further schema or per-surface change.</li>
                        </ol>
                    </div>
                    <h3 class="h6">Default tiers (seeded at install)</h3>
                    <ul>
                        <li><strong>public</strong> &mdash; lyrics only, public-domain only.</li>
                        <li><strong>free</strong> &mdash; lyrics, no audio, no copyrighted content.</li>
                        <li><strong>ccli</strong> &mdash; lyrics + copyrighted, but only if the user's organisation has a CCLI licence number.</li>
                        <li><strong>premium</strong> &mdash; everything except offline.</li>
                        <li><strong>pro</strong> &mdash; everything.</li>
                    </ul>
                    <h3 class="h6">Tiers are only enforced when content gating is on</h3>
                    <p>
                        Until you switch it on, tiers are <em>advisory</em>: the API emits full song data and the apps are trusted to self-limit. The enforcement point is the <code>content_gating_enabled</code> flag, toggled from <a href="/manage/configuration#feature-gating">Configuration &rarr; Feature gating</a> (#1481) &mdash; <strong>not</strong> an entitlement and not something on the <a href="#entitlements">Entitlements</a> page. With the flag at <code>'1'</code>, the server strips fields a tier may not see (the lyric body for copyrighted songs, gated media) from the <code>song_detail</code> / <code>song_data</code> / random API payloads and from the offline manifest, resolving each cap from the live tier row. At its default <code>'0'</code> the whole mechanism is a verified no-op. A second, nested flag &mdash; <code>feature_gating_rules_enabled</code>, same panel &mdash; additionally turns on any admin-defined <a href="/manage/feature-gating">enforcement rule</a> (#1481 P2); it has no effect unless content gating is ALSO on.
                    </p>
                    <div class="gotcha small">
                        <strong>Gotcha:</strong> A tier's machine name is set on creation and never changes. To &ldquo;rename&rdquo; a tier, create a new one, reassign users to it, then delete the old one.
                    </div>
                </section>

                <!-- ====================================================================
                     PEOPLE
                     ==================================================================== -->

                <section id="users" class="help-section card-admin mb-4">
                    <h2><i class="bi bi-people me-2"></i>Users</h2>
                    <p class="role-badges">
                        <span class="badge bg-warning text-dark">admin</span>
                        <span class="badge bg-danger">global_admin</span>
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
                        <li><strong>Delete</strong> &mdash; permanent. Their setlists, song revisions, and activity entries remain (linked by user ID) for audit reasons.</li>
                    </ul>
                    <div class="gotcha small">
                        <strong>Gotcha:</strong> You cannot deactivate <em>your own</em> account.
                    </div>
                    <div class="gotcha small">
                        <strong>Gotcha:</strong> Username is set on creation and is immutable. Display name can be changed any time.
                    </div>
                    <div class="gotcha small">
                        <strong>Gotcha:</strong> Users can also delete <em>their own</em> account
                        self-service from the native Apple app (#1477, re-auth required), separately
                        from the admin-initiated Delete above. A self-service delete also revokes
                        any linked Sign in with Apple grant and clears their API tokens.
                    </div>
                </section>

                <section id="groups" class="help-section card-admin mb-4">
                    <h2><i class="bi bi-people-fill me-2"></i>User Groups</h2>
                    <p class="role-badges">
                        <span class="badge bg-warning text-dark">admin</span>
                        <span class="badge bg-danger">global_admin</span>
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
                        <strong>Role vs Group (#642):</strong> these names sound similar but they're independent concepts.
                        <ul class="small mb-0">
                            <li><strong>User Role</strong> (Curator / Admin / Global Admin) controls which Manage pages a user can <em>access</em>. The four roles are hard-coded today; new roles need a code change.</li>
                            <li><strong>User Group</strong> controls which release channel a user sees on the public site (Alpha / Beta / RC / RTW). Group membership is freely admin-managed via this page.</li>
                        </ul>
                        Don't expect adding a User Group to grant Manage access — for that, change the user's Role from User Management. Issue #642 tracks the rationalisation.
                    </div>
                </section>

                <section id="organisations" class="help-section card-admin mb-4">
                    <h2><i class="bi bi-building me-2"></i>Organisations</h2>
                    <p class="role-badges">
                        <span class="badge bg-warning text-dark">admin</span>
                        <span class="badge bg-danger">global_admin</span>
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
                        <span class="badge bg-danger">global_admin</span>
                    </p>
                    <p>
                        The org-admin surface (#707, #726). Visible to anyone who holds an <code>admin</code> or <code>owner</code> row in <code>tblOrganisationMembers</code> for at least one organisation — they don't need system-admin role to see this page. System admins see it too, scoped to every org.
                    </p>
                    <h3 class="h6">What it does</h3>
                    <ul>
                        <li>Lists every organisation the current user can manage.</li>
                        <li>For each, shows the member roster with role badges and the licence rows on file.</li>
                        <li>Inline forms for the six edit actions described below — you don't need <code>/manage/organisations</code> for routine org-admin work.</li>
                    </ul>
                    <h3 class="h6">Member actions</h3>
                    <dl class="actions">
                        <dt>Add member</dt><dd>Free-text identifier — type a username OR an email, the server resolves to a <code>tblUsers.Id</code>. New member rows pick a sub-role (<em>member</em> / <em>admin</em> / <em>owner</em>).</dd>
                        <dt>Change member role</dt><dd>Inline picker per row.</dd>
                        <dt>Remove member</dt><dd>You can't remove yourself unless you're also a system admin — prevents accidental org lock-out. Ask a co-admin.</dd>
                    </dl>
                    <h3 class="h6">Licence actions</h3>
                    <dl class="actions">
                        <dt>Add licence</dt><dd>Per-row licence types: <code>ccli</code>, <code>mrl</code>, <code>ihymns_basic</code>, <code>ihymns_pro</code>, <code>custom</code>. INSERT-on-conflict-UPDATE so re-adding the same type updates number / expiry / notes in place.</dd>
                        <dt>Change licence</dt><dd>Edit number, expiry date, active flag, notes. Type is immutable on a row — to switch types, remove and re-add.</dd>
                        <dt>Remove licence</dt><dd>Drops the row. Belt-and-braces ownership check on the server.</dd>
                    </dl>
                    <h3 class="h6">Row-level gate</h3>
                    <p>
                        Every action runs <code>userCanActOnOrg($userId, $orgId)</code> server-side before any mutation, regardless of whether the call came from the form or a crafted POST. A licence_id from one org can never be edited via an org_id you happen to admin elsewhere.
                    </p>
                    <div class="gotcha small">
                        <strong>Gotcha:</strong> System admins (<em>admin</em> / <em>global_admin</em>) bypass the row-level gate by default. The audit log records the action under <code>org_admin.&lt;verb&gt;</code> regardless, so the timeline reads as one surface.
                    </div>
                </section>

                <section id="entitlements" class="help-section card-admin mb-4">
                    <h2><i class="bi bi-key me-2"></i>Entitlements</h2>
                    <p class="role-badges">
                        <span class="badge bg-danger">global_admin</span>
                    </p>
                    <p>
                        The fine-grained permission map. Every admin action is gated by an <em>entitlement</em> (e.g. <code>edit_songs</code>, <code>verify_songs</code>, <code>manage_songbooks</code>). This page lets you change which roles hold which entitlements, overriding the hard-coded defaults.
                    </p>
                    <h3 class="h6">When to use it</h3>
                    <ul>
                        <li>Promote a single privilege to a role that doesn't normally have it (e.g. let editors run the CCLI report).</li>
                        <li>Demote a privilege you want to lock down (e.g. take <code>delete_songs</code> away from admins, leave it with global admins only).</li>
                        <li>Reset to defaults if you've gone too far &mdash; the &ldquo;Reset&rdquo; button restores the hard-coded baseline.</li>
                    </ul>
                    <div class="gotcha small">
                        <strong>Gotcha:</strong> This is a nuclear tool. A bad change is global, immediate, and visible to everyone on next page load. Make small changes and verify before moving on.
                    </div>
                    <div class="gotcha small">
                        <strong>Gotcha:</strong> The page enforces one safety guard: <code>manage_entitlements</code> itself can never be removed from <code>global_admin</code>, so you can't lock yourself out of the page that re-grants access.
                    </div>
                </section>

                <section id="service-mode" class="help-section card-admin mb-4">
                    <h2><i class="bi bi-broadcast-pin me-2"></i>Service Mode (congregation Live-Follow)</h2>
                    <p class="role-badges">
                        <span class="badge bg-secondary">org admin</span>
                        <span class="badge bg-secondary">org owner</span>
                        <span class="badge bg-warning text-dark">admin</span>
                        <span class="badge bg-danger">global_admin</span>
                    </p>
                    <div class="alert alert-info small">
                        <i class="bi bi-info-circle me-1"></i>
                        <strong>Dormant by default.</strong> The whole feature is gated off behind <code>content_gating_enabled = '0'</code> and additionally needs <code>require_licence:ccli</code> restriction rows before it does anything. Turn it on deliberately when your organisation is ready (#1323 / #1335).
                    </div>
                    <p>
                        Service Mode lets a congregation follow the songs of a live service in sync on their own devices. The leader drives which song is showing; everyone who has joined sees it change in real time. Joining is by a <strong>rotating code</strong> shown at the venue &mdash; no account or sign-in required for a congregant.
                    </p>
                    <h3 class="h6">Setting up (org admin / owner)</h3>
                    <dl class="actions">
                        <dt>Venues &amp; Service Times <span class="badge bg-secondary">/manage/venues</span></dt><dd>Define your organisation's physical venues (name, address, timezone) and their recurring service schedules (day, start time, duration). These anchor a live session to a place and an occurrence so a join code can expire when the service ends.</dd>
                        <dt>Service Projection <span class="badge bg-secondary">/manage/service-projection.php</span></dt><dd>The big-screen / projector view. Displays the current song and the rotating join code (plus QR) for the congregation to scan or type. One of the two broadcaster front-ends &mdash; song-nav here sets the current song for everyone.</dd>
                        <dt>Service Lead <span class="badge bg-secondary">/manage/service-lead.php</span></dt><dd>The leader's own device: connect to a session and drive it from your phone or tablet, without needing to stand at the projector.</dd>
                    </dl>
                    <h3 class="h6">How a congregant follows</h3>
                    <p class="small">
                        A congregant opens the join link / scans the QR / types the code shown at the venue. The client (<code>js/modules/service-follow.js</code>) mints an opaque presence token, polls for the current song, and follows along. Presence is rate-limited <em>per token</em> (not per IP) so a whole congregation behind one venue Wi-Fi NAT isn't throttled as a single client.
                    </p>
                    <h3 class="h6">CCLI unlock (Phase 3)</h3>
                    <p class="small">
                        When the dormant gate is enabled, a valid presence token whose session belongs to an org holding a <em>live</em> CCLI licence unlocks the copyrighted-lyrics view for the duration of the service &mdash; the congregant sees the per-song CCL copyright notice. The owner has accepted the licensing basis for this (#1324).
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
                        <span class="badge bg-danger">global_admin</span>
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
                        <span class="badge bg-danger">global_admin</span>
                    </p>
                    <p>
                        Compliance report for CCLI licensees. Pick a date range and get a sortable table of every CCLI-numbered song with its view count and copyright info, ready to upload to your CCLI reporting portal.
                    </p>
                    <h3 class="h6">Tips</h3>
                    <ul>
                        <li>The CSV export is the column shape your CCLI portal expects (title, CCLI number, copyright, count).</li>
                        <li>Tick &ldquo;Show all&rdquo; to also include songs without a CCLI number assigned &mdash; useful for spotting gaps in the metadata.</li>
                        <li>The view-count is per occurrence: a user opening the same song twice counts as two views.</li>
                    </ul>
                </section>

                <section id="data-health" class="help-section card-admin mb-4">
                    <h2><i class="bi bi-activity me-2"></i>Data Health</h2>
                    <p class="role-badges">
                        <span class="badge bg-danger">global_admin</span>
                    </p>
                    <p>
                        Confirms that MySQL is the authoritative source for every kind of data, and lets you safely <em>disconnect</em> the remaining legacy fallbacks (the SQLite user database, the file-system setlist share directory) so the app stops checking them.
                    </p>
                    <div class="alert alert-info small">
                        <i class="bi bi-info-circle me-1"></i>
                        Song reads are now <strong>live MySQL, full stop</strong> (the DB-direct rewrite, #1010 / #1020). There is no longer a <code>songs.json</code> corpus cache or JSON read fallback to disconnect &mdash; reads are scoped (<code>?action=songs_index</code> for the slim index, <code>?action=song_detail</code> for one record, the editor's <code>?action=songbook_export</code> for one book). If the database is unreachable the app returns a themed 503 maintenance page (#1021), <em>never</em> stale JSON. The old <code>songs.json</code> survives only as a one-time migration <em>input</em>, not a runtime source.
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
                        <span class="badge bg-danger">global_admin</span>
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
                    <h3 class="h6">Error capture (#695)</h3>
                    <p>
                        Server-side exceptions raised by admin POST handlers (the &ldquo;Database error — check server logs&rdquo; banner) are mirrored into the activity log with <code>Result='error'</code> and the exception message + class in the <code>Details</code> column. The viewer's <strong>Result = error</strong> filter is a one-click triage list — you no longer need SSH to see why a save failed.
                    </p>
                    <ul>
                        <li><strong>Client errors (#1582):</strong> browser-side crashes now appear here too, as <code>client.jserror</code> rows — one row per deduplicated error, not one per occurrence. When a tester says &ldquo;the button does nothing,&rdquo; filter <strong>Action</strong> to <code>client.jserror</code> and check here first, before asking for logs or a screen-share.</li>
                    </ul>
                    <p class="small text-muted">
                        Verb prefix convention: web admin writes <code>&lt;entity&gt;.&lt;verb&gt;</code> (e.g. <code>songbook.create</code>, <code>org.member_add</code>); the public-API surfaces use <code>api.admin.&lt;entity&gt;.&lt;verb&gt;</code> (e.g. <code>api.admin.songbook.create</code>) so timeline readers can tell which surface drove the change.
                    </p>
                    <div class="gotcha small">
                        <strong>Gotcha:</strong> Rows are immutable. There's no edit, no delete &mdash; that's the whole point of an audit log.
                    </div>
                </section>

                <section id="notifications" class="help-section card-admin mb-4">
                    <h2><i class="bi bi-bell me-2"></i>Notifications</h2>
                    <p class="role-badges">
                        <span class="badge bg-danger">global_admin</span>
                    </p>
                    <p>
                        Compose and broadcast in-app notifications (#813) &mdash; the rows that drive the header bell every signed-in user sees. Gated by <code>manage_notifications</code>.
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
                    <h2><i class="bi bi-clipboard2-data me-2"></i>Schema Audit</h2>
                    <p class="role-badges">
                        <span class="badge bg-danger">global_admin</span>
                    </p>
                    <p>
                        Diagnostic page that compares three things: the schema your code expects (<code>schema.sql</code>), the schema your live database actually has, and the migration scripts that bridge the two. Surfaces drift before it bites.
                    </p>
                    <h3 class="h6">Status meanings</h3>
                    <dl class="actions">
                        <dt>OK</dt><dd>In code <em>and</em> in DB. Nothing to do.</dd>
                        <dt>Missing (amber)</dt><dd>In code, not in DB, but a migration covers it. Run that migration on <a href="#setup-database">Database Setup</a>.</dd>
                        <dt>Uncovered (red)</dt><dd>In code, not in DB, and no migration covers it. This is a real bug &mdash; an existing install will never get this column. File an issue and write a migration.</dd>
                        <dt>Orphan</dt><dd>In DB, not in code. Almost always informational &mdash; a column you removed from <code>schema.sql</code> is still around in the database. Safe to leave alone.</dd>
                    </dl>
                    <div class="gotcha small">
                        <strong>Gotcha:</strong> Schema Audit is read-only. It tells you what's wrong; it never runs ALTER statements itself.
                    </div>
                </section>

                <section id="diagnostics" class="help-section card-admin mb-4">
                    <h2><i class="bi bi-terminal me-2"></i>SQL Diagnostics</h2>
                    <p class="role-badges">
                        <span class="badge bg-danger">global_admin</span>
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
                        <span class="badge bg-danger">global_admin</span>
                    </p>
                    <p>
                        The most powerful page in the admin area. Configures the database connection, installs the schema, runs migrations, takes backups, restores from backup. Treat with respect.
                    </p>
                    <h3 class="h6">Setup workflow on a new install</h3>
                    <ol>
                        <li><strong>Credentials</strong> &mdash; fill in host, port, database name, username, password, table prefix. Click <strong>Test connection</strong>; only save once it goes green.</li>
                        <li><strong>Install schema</strong> &mdash; creates every table from <code>schema.sql</code>. Idempotent &mdash; re-running is safe.</li>
                        <li><strong>Migrate users / setlists</strong> &mdash; one-time import from the legacy SQLite + JSON setlist share dir.</li>
                        <li>Run remaining migrations (Account Sync, Songbook Metadata, Credit Fields, Credit People, User Features Catch-up, Activity Log Expand) <em>in the order they appear on the dashboard</em>. Each is idempotent.</li>
                    </ol>
                    <h3 class="h6">Setup workflow on an existing install</h3>
                    <p>
                        Re-run <strong>Install schema</strong> if anything changed in <code>schema.sql</code> (it's safe), then run only the migrations that <a href="#schema-audit">Schema Audit</a> flagged as missing. Migrations are idempotent &mdash; running one that's already been applied just reports &ldquo;[skip]&rdquo; for everything.
                    </p>
                    <h3 class="h6">Apply all pending migrations (#577)</h3>
                    <p>
                        The <strong>Apply all pending migrations</strong> button runs every <code>migrate-*.php</code> script in deployment order. Each script is already idempotent, so re-running the bulk action after some have been applied is safe — they no-op individually.
                    </p>
                    <p class="small">
                        If a migration fails mid-run, the dashboard captures the first-failing step and surfaces it in a prominent banner <em>above</em> the (sometimes long, scrollable) output panel (#720), so you don't miss the FAILED line in the noise. Fix the underlying issue and re-run — the steps that succeeded earlier no-op the second time.
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
                        <span class="badge bg-danger">global_admin</span>
                    </p>
                    <p>
                        A card on <a href="/manage/configuration">Configuration</a> that tells the site where each
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
                    <h3 class="h6">Apple native app &amp; Sign in with Apple credentials (#1401/#1402/#1470)</h3>
                    <p>
                        The same <a href="/manage/configuration">Configuration</a> page has an
                        &ldquo;Apple native app&rdquo; card, below the Feature Gating card, holding the
                        Apple Developer <strong>Team ID</strong> (for Universal Links), the
                        <strong>Sign in with Apple</strong> key (Key ID + <code>.p8</code> private key
                        &mdash; only needed for the refresh-token exchange and Apple-side revoke on
                        account deletion; the native app's sign-in itself works before these are set),
                        and the separate <strong>Services ID</strong> + channel allow-list that turns on
                        Sign in with Apple for the <em>web</em> app. Every field on this card is optional
                        and dormant until filled in &mdash; each has its own inline guidance and a
                        set/not-set badge.
                    </p>
                </section>

                <section id="native-api" class="help-section card-admin mb-4">
                    <h2><i class="bi bi-broadcast me-2"></i>Native API surface</h2>
                    <p class="role-badges">
                        <span class="badge bg-warning text-dark">admin</span>
                        <span class="badge bg-danger">global_admin</span>
                    </p>
                    <p>
                        Every admin verb on this site is also reachable via the public REST API at <code>/api.php?action=&lt;verb&gt;</code> (#719). Native clients (Apple, Android, FireOS) and tooling clients (CI, monitoring, dashboards) can drive the same surfaces the web admin uses without a webview or a separate auth flow.
                    </p>
                    <h3 class="h6">Auth</h3>
                    <p>
                        Bearer-token auth via the <code>Authorization: Bearer &lt;token&gt;</code> header. Tokens are issued by the existing email-magic-link or password login flows and live on <code>tblApiTokens</code>. POSTs also need <code>X-Requested-With: XMLHttpRequest</code> as a CSRF defence (#293). Same role gates as the web admin — <code>admin</code> / <code>global_admin</code> for system-wide write verbs, plus the row-level <code>userCanActOnOrg()</code> check for org-admin endpoints.
                    </p>
                    <h3 class="h6">What's covered</h3>
                    <ul>
                        <li><strong>Songbooks</strong> — create / update / delete / cascade-delete / reorder / auto-colour fill / auto-colour reassign (PR 2a).</li>
                        <li><strong>Users + Groups + Tiers</strong> — full CRUD plus role / activate / password-reset / member-add-remove (PR 2b).</li>
                        <li><strong>Organisations + My Organisations</strong> — system-admin updates plus the six org-admin verbs from this surface (PR 2c).</li>
                        <li><strong>Credit People</strong> — add / update / rename / merge / delete with the same cascade and confirmation gates (PR 2d).</li>
                        <li><strong>Analytics + Diagnostics</strong> — top searches, data health snapshot, schema-audit report, per-migration applied/partial/pending status (PR 2d).</li>
                        <li><strong>Editor</strong> — load / save / save_song / bulk_tag / list_revisions / restore_revision / song_tags / tag_search / credit_search / user_search / org_search / bulk_import_zip / bulk_import_status (PR 3 docs). (The v1 <code>get_translations</code> / <code>add_translation</code> / <code>remove_translation</code> trio was dead code — no caller ever existed — and was removed 2026-07-30; translation links are now the public, live <code>song_translations</code> action.)</li>
                    </ul>
                    <h3 class="h6">OpenAPI spec</h3>
                    <p>
                        Every endpoint is documented in <a href="/api-docs.yaml"><code>/api-docs.yaml</code></a> as a single OpenAPI 3.0 file. Swagger UI / Stoplight / Redoc all render it cleanly. The spec is the source of truth for the request / response shapes — the web admin uses the helpers underneath, the native clients hit the documented endpoints, both stay in sync because the validators live in shared <code>includes/</code> files.
                    </p>
                    <h3 class="h6">Activity-log surface prefix</h3>
                    <p class="small">
                        API-driven changes write under <code>api.admin.&lt;entity&gt;.&lt;verb&gt;</code> (e.g. <code>api.admin.songbook.create</code>, <code>api.org_admin.licence_change</code>). The <a href="#activity-log">Activity Log</a> viewer can show both surfaces side-by-side; the prefix tells you which.
                    </p>
                    <div class="gotcha small">
                        <strong>Gotcha:</strong> Status codes follow REST: 400 (validation), 401, 403 (role gate or row-level refusal), 404, 405 (wrong method), 409 (duplicate key), 422 (cannot delete because dependents exist). Native UIs can render the right toast without parsing the error string.
                    </div>
                </section>

                <section id="api-keys" class="help-section card-admin mb-4">
                    <h2><i class="bi bi-key-fill me-2"></i>API Keys</h2>
                    <p class="role-badges">
                        <span class="badge bg-danger">global_admin</span>
                    </p>
                    <p>
                        Mint, list and revoke the <strong>machine-to-machine</strong> API keys that external services use to call the public API <em>without</em> a user session (#1064). Distinct from the <a href="#native-api">Native API surface</a>, which uses a per-user Bearer login token &mdash; an API key is a long-lived, scoped credential for a system (e.g. the MeedyaDL lyrics-ingest pipeline). Gated by <code>manage_api_keys</code>.
                    </p>
                    <h3 class="h6">How a key works</h3>
                    <ul>
                        <li><strong>Shown once.</strong> The raw key is generated server-side and displayed a single time in the create response &mdash; copy it then. iHymns stores only its SHA-256 hash plus a short non-secret prefix, so it can never show you the key again. Lost it? Revoke and mint a new one.</li>
                        <li><strong>Scoped.</strong> Each key carries one or more space-separated scopes that limit what it can do, checked on every call. Today: <code>lyrics:ingest</code> (write &mdash; the lyrics-ingest endpoint) and <code>catalogue:read</code> (read &mdash; lets a trusted integrator read the public catalogue at the key's <em>own</em> rate limit instead of the tighter anonymous limit; it does <strong>not</strong> unlock gated/copyrighted content). More scopes can be added later without re-issuing existing keys.</li>
                        <li><strong>Revocable.</strong> Toggle a key <em>inactive</em> to suspend it, or delete it outright. Both actions are written to the <a href="#activity-log">Activity Log</a>.</li>
                    </ul>
                    <h3 class="h6">Rate limits</h3>
                    <p>
                        API-key calls are rate-limited per key against a windowed usage counter, so one integration can't starve the others. (This is separate from the per-token / per-IP limiter that protects the heavy <em>public</em> reads &mdash; <code>song_detail</code>, search, the slim index, related songs and bulk endpoints each carry a generous per-minute ceiling and answer <code>429</code> with a <code>Retry-After</code> header when a single requester floods them. Real clients never trip it; it fails open if its counter table isn't present.)
                    </p>
                    <h3 class="h6">Usage &amp; per-key limits</h3>
                    <p>
                        The key list shows each key's <strong>requests today</strong> and its <strong>per-minute&nbsp;&middot;&nbsp;per-day</strong> ceilings. Click <em>Limits</em> on a key to set them (blank&nbsp;=&nbsp;no limit). A key over its window gets a <code>429</code> + <code>Retry-After</code>. Setting a daily cap also makes that key's usage visible here.
                    </p>
                    <h3 class="h6">Developer quickstart</h3>
                    <ul>
                        <li><strong>Authenticate</strong> &mdash; send the key as <code>Authorization: Bearer ihk_live_&hellip;</code> (or the <code>X-API-Key</code> header) on every request.</li>
                        <li><strong>Read the catalogue</strong> &mdash; a <code>catalogue:read</code> key calling <code>/api?action=song_detail&amp;id=&hellip;</code> (or <code>songs_index</code>, <code>search</code>, <code>songs_list</code>, <code>bulk_songs</code>) is metered against the key's own limit; without a key the same reads work but on the anonymous per-IP limit.</li>
                        <li><strong>Watch the headers</strong> &mdash; rate-limited responses carry <code>X-RateLimit-Limit</code>, <code>X-RateLimit-Remaining</code>, <code>X-RateLimit-Window</code>; a <code>429</code> adds <code>Retry-After</code> (seconds). Honour them to self-throttle.</li>
                        <li><strong>Idempotency</strong> &mdash; for writes, send an <code>Idempotency-Key</code> header so a retried request isn't applied twice.</li>
                        <li><strong>Explore live</strong> &mdash; the <a href="/manage/api-docs">API docs (Swagger UI)</a> have <em>Try it out</em> enabled; the full OpenAPI spec is at <code>/api-docs.yaml</code>.</li>
                    </ul>
                    <div class="gotcha small">
                        <strong>Gotcha:</strong> Treat a minted key like a password &mdash; it grants its scope to anyone holding it, with no user behind it. If a key leaks, revoke it immediately and re-issue; never paste a raw key into a ticket, chat or log.
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
                        <span class="badge bg-danger">global_admin</span>
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
                        Both the sidebar link and the page itself are gated on the
                        <code>view_api_docs</code> entitlement (editor, admin, global_admin). They used to
                        disagree &mdash; the page admitted any Curator-and-above while the link checked the
                        entitlement, so a Curator saw no link but could still open the page by typing the
                        address. Fixed in #1587.
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
                        You don't have the entitlement for it. The menu only shows pages
                        you can use. Ask a global admin to grant you the role or
                        entitlement you need (see <a href="#entitlements">Entitlements</a>).
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
                        Open <a href="#deleted-songs">Deleted Songs</a> and click <strong>Restore</strong>. The song comes back exactly as it was, with its lyrics, credits, media, tags and revision history intact &mdash; deleting hid it, it never destroyed anything (#1694).
                    </p>
                    <p class="small">
                        This page previously told you to recover a deleted song through <a href="#revisions">Revisions Audit &rarr; Restore</a>. <strong>That advice did not work</strong>, and had not for as long as it was written: the old delete removed the song's revision rows along with the song, so by the time you went looking for them there was nothing left to restore from. Revisions Audit is for undoing a bad <em>edit</em>; Deleted Songs is for undoing a bad <em>delete</em>.
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
                        <li>Their <strong>access tier</strong> on <a href="#users">Users</a> &mdash; controls capability gating.</li>
                        <li>Their <strong>organisation membership</strong> &mdash; org licence bubbles down to members.</li>
                        <li><a href="#restrictions">Content Restrictions</a> &mdash; rules can block or require things on a per-user / per-org / per-platform basis.</li>
                    </ol>

                    <h3 class="h6">&ldquo;Two songs / two people / two anything look like duplicates.&rdquo;</h3>
                    <p class="small">
                        For people, use <a href="#credit-people">Credit People &rarr; Merge</a>. For songs, use <a href="#duplicate-songs">Duplicate &amp; Counterpart Songs</a> &mdash; that page is the dedicated song-merge surface: it scores likely duplicates for you and lets you <strong>Link</strong> (keep both, cross-reference), <strong>Dismiss</strong> (not a duplicate), or <strong>Merge</strong> (collapse into one). For themes/tags, use <a href="#tags">Tags &amp; Themes &rarr; Merge</a>.
                    </p>

                    <h3 class="h6">&ldquo;Activity Log shows an action I don't recognise. What is it?&rdquo;</h3>
                    <p class="small">
                        Activity Log entries follow a <code>verb_noun</code> shape (e.g. <code>create_user</code>, <code>edit_song</code>, <code>delete_organisation_member</code>). The <strong>Entity type</strong> column tells you what was acted on, the <strong>Entity ID</strong> is its primary key. Click around &mdash; most actions can be reverse-engineered from the noun.
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
