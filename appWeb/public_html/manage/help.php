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
        'id'    => 'credit-people',
        'icon'  => 'bi-person-vcard',
        'title' => 'Credit People',
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
        'id'    => 'schema-audit',
        'icon'  => 'bi-clipboard2-data',
        'title' => 'Schema Audit',
        'group' => 'Operations',
    ],
    [
        'id'    => 'setup-database',
        'icon'  => 'bi-database-gear',
        'title' => 'Database Setup',
        'group' => 'Operations',
    ],
    [
        'id'    => 'native-api',
        'icon'  => 'bi-broadcast',
        'title' => 'Native API surface',
        'group' => 'Operations',
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
<html lang="en" data-bs-theme="dark">
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
                        the catalogue. It loads every song in the library into your
                        browser, then lets you edit one song or many at once before
                        saving everything back.
                    </p>
                    <h3 class="h6">Working with the catalogue</h3>
                    <ul>
                        <li><strong>Filter</strong> by songbook, search by title, or
                            sort by title / number / songbook+number using the
                            controls above the song list.</li>
                        <li>Click a song in the list to load it into the tabs on the
                            right (Metadata, Structure, Credits, Tags, Preview).</li>
                        <li>Use <strong>Multi-select</strong> mode for bulk operations
                            (verify, tag, move to another songbook, export, delete).</li>
                    </ul>
                    <h3 class="h6">The five tabs</h3>
                    <dl class="actions">
                        <dt>Metadata</dt>
                        <dd>Title, song number, songbook, CCLI number, Tune Name (e.g. <em>HYFRYDOL</em>), ISWC, language, region.</dd>
                        <dt>Structure</dt>
                        <dd>
                            The actual lyrics, broken into sections: verses, choruses, bridges, and so on. Drag to reorder; auto-resizing text areas grow as you type.
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
                        <dt>Tags</dt>
                        <dd>Categorical tags (e.g. <em>Easter</em>, <em>Communion</em>) that drive Browse-by-Theme in the main app and can be used as targets for <a href="#restrictions">Content Restrictions</a>.</dd>
                        <dt>Preview</dt>
                        <dd>Read-only render of the finished song as users will see it. Always check this before saving.</dd>
                    </dl>
                    <h3 class="h6">Saving, importing, exporting</h3>
                    <ul>
                        <li><strong>Save</strong> writes everything to the database. Auto-save runs in the background while you work, but always click Save before navigating away.</li>
                        <li><strong>Validate</strong> runs every song past a quality check (missing required fields, invalid language tags, orphaned references) and lists any problems.</li>
                        <li><strong>Import</strong> from JSON or CSV — small, single-file. For mass onboarding (e.g. a complete new hymnal), see the <strong>Bulk Import ZIP</strong> section below.</li>
                        <li><strong>Export</strong> the current view as JSON or CSV.</li>
                        <li><strong>Revisions</strong> for the selected song shows a diff of every previous edit and a Restore button.</li>
                    </ul>
                    <h3 class="h6">Bulk Import ZIP (#664 / #676)</h3>
                    <p>
                        For onboarding an entire songbook at once. Upload a ZIP whose top-level folders match <code>&lt;Hymnal Name&gt; [&lt;ABBR&gt;]/</code> and whose files match <code>&lt;number&gt; (&lt;ABBR&gt;) - &lt;Title&gt;.txt</code>. The importer creates the songbook on first encounter, then parses + inserts every song.
                    </p>
                    <ul>
                        <li><strong>INSERT-only contract:</strong> if a songbook or song already exists, it's left untouched — never overwritten. The summary reports created vs. existing counts so you can see what landed.</li>
                        <li><strong>Live progress widget:</strong> the upload completes almost immediately; the actual import runs server-side. A small fixed-position card pinned bottom-right polls the job status, shows a progress bar, and survives navigation between admin pages and the public app. Hard-reload the page mid-import and the widget reattaches via localStorage.</li>
                        <li><strong>Notification on completion:</strong> a row is written to <a href="#notifications">Notifications</a> when the worker finishes, and (if you've granted permission) a native browser notification fires.</li>
                        <li><strong>Caps:</strong> 100 MB upload, 100,000 entries per archive, 5 MiB per uncompressed entry, 500 MiB cumulative uncompressed. These are zip-bomb defences (#682) — far above any real bundle.</li>
                    </ul>
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
                        <li>Click a row to open that song in the editor; the Revisions modal there shows the diff and lets you Restore.</li>
                    </ul>
                    <div class="gotcha small">
                        <strong>Gotcha:</strong> Revisions are immutable. Restore creates a <em>new</em> revision rather than rewriting history, so the trail stays honest.
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
                        Pages currently opted in: Credit People, Songbooks, Songbook Series, Works. The convention is documented in <code>DEV_NOTES.md</code>; rolling it forward to the remaining list pages is a per-page cosmetic change with zero CSS work.
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
                        Tiers are bundles of capabilities (view lyrics, view copyrighted lyrics, play audio, download MIDI, download PDF, save offline, requires CCLI). Every user is assigned one tier, which controls what UI controls they see in the main app.
                    </p>
                    <h3 class="h6">Default tiers (seeded at install)</h3>
                    <ul>
                        <li><strong>public</strong> &mdash; lyrics only, public-domain only.</li>
                        <li><strong>free</strong> &mdash; lyrics, no audio, no copyrighted content.</li>
                        <li><strong>ccli</strong> &mdash; lyrics + copyrighted, but only if the user's organisation has a CCLI licence number.</li>
                        <li><strong>premium</strong> &mdash; everything except offline.</li>
                        <li><strong>pro</strong> &mdash; everything.</li>
                    </ul>
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
                        Confirms that MySQL is the authoritative source for every kind of data, and lets you safely <em>disconnect</em> the legacy fallbacks (the original <code>songs.json</code>, the SQLite user database, the file-system setlist share directory) so the app stops checking them.
                    </p>
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
                    <p class="small text-muted">
                        Verb prefix convention: web admin writes <code>&lt;entity&gt;.&lt;verb&gt;</code> (e.g. <code>songbook.create</code>, <code>org.member_add</code>); the public-API surfaces use <code>api.admin.&lt;entity&gt;.&lt;verb&gt;</code> (e.g. <code>api.admin.songbook.create</code>) so timeline readers can tell which surface drove the change.
                    </p>
                    <div class="gotcha small">
                        <strong>Gotcha:</strong> Rows are immutable. There's no edit, no delete &mdash; that's the whole point of an audit log.
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
                        <li><strong>Migrate songs JSON</strong> &mdash; one-time import of <code>data/songs.json</code> into the database.</li>
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
                        <li><strong>Editor</strong> — load / save / save_song / bulk_tag / list_revisions / restore_revision / get_translations / add_translation / remove_translation / song_tags / tag_search / credit_search / user_search / org_search / bulk_import_zip / bulk_import_status (PR 3 docs).</li>
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

                <!-- ====================================================================
                     HELP / TROUBLESHOOTING
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
                        Open <a href="#revisions">Revisions Audit</a>, find the song's last edit before the delete, click through to the editor, and use <strong>Revisions &rarr; Restore</strong>. Revisions are kept indefinitely so older deletes are still recoverable.
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
                        For people, use <a href="#credit-people">Credit People &rarr; Merge</a>. For songs, the editor doesn't have a merge tool yet &mdash; the safest path is to copy any unique data from one into the other, then delete the duplicate.
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
