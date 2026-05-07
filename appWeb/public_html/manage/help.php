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
                        <dd>The actual lyrics, broken into sections: verses, choruses, bridges, and so on. Drag to reorder; auto-resizing text areas grow as you type.</dd>
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
                        <li><strong>Import</strong> from JSON or CSV. <strong>Export</strong> the current view as JSON or CSV.</li>
                        <li><strong>History</strong> for the selected song shows a diff of every previous edit and a Restore button.</li>
                    </ul>
                    <div class="gotcha small">
                        <strong>Gotcha:</strong> Closing the tab while there are unsaved changes loses them — auto-save catches most things, but treat Save as the source of truth.
                    </div>
                    <div class="gotcha small">
                        <strong>Gotcha:</strong> A song's ID is set when it's first created and never changes. Renaming the title doesn't rename the ID. Numbering is independent of ID.
                    </div>
                    <div class="gotcha small">
                        <strong>Gotcha:</strong> Deleting a song is permanent. Use <strong>History &rarr; Restore</strong> if you need an old version <em>before</em> you save further edits over it.
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
                        End users in the main app can submit a "Request a song" form. Those submissions land here so an editor can triage them.
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
                        <li>Click a row to open that song in the editor; the History modal there shows the diff and lets you Restore.</li>
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
                        <dt>Colour</dt><dd>Hex code (<code>#RRGGBB</code>) used as the songbook badge colour. Leave blank to inherit a default.</dd>
                        <dt>Publisher / Publication year / Copyright / Affiliation</dt><dd>Optional metadata for filtering and reports.</dd>
                        <dt>Official flag</dt><dd>Marks "real" published books vs. user-curated collections.</dd>
                    </dl>
                    <h3 class="h6">Renaming an abbreviation</h3>
                    <p>
                        Abbreviations are the natural key, so renaming is opt-in: you must tick the <strong>"Also rename song references"</strong> checkbox to cascade the rename to every song that uses it. Without that checkbox, songs keep the old abbreviation and orphan from the renamed songbook.
                    </p>
                    <div class="gotcha small">
                        <strong>Gotcha:</strong> Deleting a songbook does <em>not</em> delete its songs. The UI refuses if any song still references its abbreviation; reassign or delete those songs first.
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
                        Open <a href="#revisions">Revisions Audit</a>, find the song's last edit before the delete, click through to the editor, and use <strong>History &rarr; Restore</strong>. Revisions are kept indefinitely so older deletes are still recoverable.
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
