<?php

/**
 * iHymns — Help Page Template
 *
 * PURPOSE:
 * In-app help and user guide. Provides instructions for using the
 * application, including searching, favourites, themes, PWA install,
 * keyboard shortcuts, and accessibility features.
 *
 * Loaded via AJAX: api.php?page=help
 */

declare(strict_types=1);

?>

<!-- ================================================================
     HELP PAGE — User guide and instructions
     ================================================================ -->
<section class="page-help" aria-label="Help and user guide">

    <h1 class="h4 mb-4">
        <i class="fa-solid fa-circle-question me-2" aria-hidden="true"></i>
        Help &amp; User Guide
    </h1>

    <!-- Accordion-style help sections -->
    <div class="accordion accordion-help" id="help-accordion">

        <!-- Getting Started -->
        <div class="accordion-item">
            <h2 class="accordion-header">
                <button class="accordion-button"
                        type="button"
                        data-bs-toggle="collapse"
                        data-bs-target="#help-getting-started"
                        aria-expanded="true"
                        aria-controls="help-getting-started">
                    <i class="fa-solid fa-rocket me-2" aria-hidden="true"></i>
                    Getting Started
                </button>
            </h2>
            <div id="help-getting-started" class="accordion-collapse collapse show" data-bs-parent="#help-accordion">
                <div class="accordion-body">
                    <p>Welcome to <strong><?= htmlspecialchars($app["Application"]["Name"]) ?></strong> — a collection of Christian hymns and worship songs from multiple songbooks.</p>
                    <ul>
                        <li>Browse songs via the <strong>Songbooks</strong> tab in the bottom navigation</li>
                        <li>Use <strong>Search</strong> to find songs by title, lyrics, writer, or composer</li>
                        <li>Use the <strong>number pad</strong> to jump directly to a song by its hymn number</li>
                        <li>Save songs to <strong>Favourites</strong> for quick access later</li>
                        <li>Use <strong>Shuffle</strong> to discover a random song</li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Searching -->
        <div class="accordion-item">
            <h2 class="accordion-header">
                <button class="accordion-button collapsed"
                        type="button"
                        data-bs-toggle="collapse"
                        data-bs-target="#help-search"
                        aria-expanded="false"
                        aria-controls="help-search">
                    <i class="fa-solid fa-magnifying-glass me-2" aria-hidden="true"></i>
                    Searching for Songs
                </button>
            </h2>
            <div id="help-search" class="accordion-collapse collapse" data-bs-parent="#help-accordion">
                <div class="accordion-body">
                    <h3 class="h6">Text Search</h3>
                    <p>Type in the search bar to find songs by title, lyrics, writer, or composer. Results appear as you type.</p>
                    <p>You can filter results by songbook using the dropdown menu.</p>

                    <h3 class="h6">Number Search</h3>
                    <p>Use the numeric keypad to jump directly to a song by its number:</p>
                    <ol>
                        <li>The songbook defaults to your <strong>Default Songbook</strong> (set in Settings), or select one from the dropdown</li>
                        <li>Enter the song number using the keypad</li>
                        <li>Press the <strong>Go</strong> button to navigate to the song</li>
                    </ol>
                    <p>The number pad is available on the Search page and via the <strong>#</strong> icon in the navigation.</p>
                    <p><strong>Keyboard users:</strong> When the number pad is open, you can type digits on your physical keyboard, press <kbd>Enter</kbd> to go, and <kbd>Backspace</kbd> to delete.</p>
                    <p><strong>Live search</strong> (showing matching songs as you type) can be enabled in Settings under <em>Live number search</em>.</p>
                </div>
            </div>
        </div>

        <!-- External Links & Works (#833 / #840) -->
        <div class="accordion-item">
            <h2 class="accordion-header">
                <button class="accordion-button collapsed"
                        type="button"
                        data-bs-toggle="collapse"
                        data-bs-target="#help-external-links-works"
                        aria-expanded="false"
                        aria-controls="help-external-links-works">
                    <i class="fa-solid fa-link me-2" aria-hidden="true"></i>
                    Find a hymn elsewhere &amp; Works
                </button>
            </h2>
            <div id="help-external-links-works" class="accordion-collapse collapse" data-bs-parent="#help-accordion">
                <div class="accordion-body">
                    <h3 class="h6">Find this song / songbook / person elsewhere</h3>
                    <p>Every song page, songbook page and credit-person page now has a <strong>Find this … elsewhere</strong> panel listing curated links out to the rest of the web — Wikipedia, Hymnary.org, Spotify, Apple Music, YouTube, Internet Archive, IMSLP, MusicBrainz, CCLI SongSelect, VIAF and more — grouped by category (Official, Information, Read, Sheet music, Listen, Watch, Purchase, Authority, Social, Other).</p>
                    <p>A small <i class="fa-solid fa-circle-check text-success" aria-hidden="true"></i> tick beside a link means a curator has personally verified it. Each link opens in a new tab.</p>

                    <h3 class="h6 mt-3">Works — same composition across multiple songbooks</h3>
                    <p>A <strong>Work</strong> groups every version of the same composition that appears across the catalogue. So <em>Amazing Grace</em> — which exists in dozens of hymnals under slightly different titles, with different arrangements — has one Work record, and every individual song entry links back to it.</p>
                    <ul>
                        <li>Visit <code>/work/&lt;slug&gt;</code> to see every version of a Work, grouped by songbook</li>
                        <li>On any song page, the "Part of work" panel lists sibling versions you can jump to</li>
                        <li>Works can be <strong>nested</strong> — an original Work can have child Works for derivative arrangements / translations / choral versions, with unlimited depth</li>
                        <li>The optional <strong>ISWC</strong> (International Standard Musical Work Code) cross-references the Work to external royalty / catalogue platforms</li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Favourites -->
        <div class="accordion-item">
            <h2 class="accordion-header">
                <button class="accordion-button collapsed"
                        type="button"
                        data-bs-toggle="collapse"
                        data-bs-target="#help-favorites"
                        aria-expanded="false"
                        aria-controls="help-favorites">
                    <i class="fa-solid fa-heart me-2" aria-hidden="true"></i>
                    Favourites
                </button>
            </h2>
            <div id="help-favorites" class="accordion-collapse collapse" data-bs-parent="#help-accordion">
                <div class="accordion-body">
                    <p>Save songs you use frequently for quick access:</p>
                    <ul>
                        <li>Tap the <i class="fa-regular fa-heart" aria-hidden="true"></i> heart icon on any song to add it to your favourites</li>
                        <li>Access your favourites from the <strong>Favourites</strong> tab in the bottom navigation</li>
                        <li>Favourites are saved locally on your device and persist between sessions</li>
                        <li>Tap the filled heart <i class="fa-solid fa-heart text-danger" aria-hidden="true"></i> to remove a song from favourites</li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Setlists -->
        <div class="accordion-item">
            <h2 class="accordion-header">
                <button class="accordion-button collapsed"
                        type="button"
                        data-bs-toggle="collapse"
                        data-bs-target="#help-setlists"
                        aria-expanded="false"
                        aria-controls="help-setlists">
                    <i class="fa-solid fa-list-check me-2" aria-hidden="true"></i>
                    Setlists &amp; Sharing
                </button>
            </h2>
            <div id="help-setlists" class="accordion-collapse collapse" data-bs-parent="#help-accordion">
                <div class="accordion-body">
                    <p>
                        A <strong>Setlist</strong> is an ordered list of songs for a service or rehearsal. Build one,
                        share it with the rest of the team, schedule it for a specific date, and walk through the songs
                        in order during the meeting.
                    </p>
                    <h3 class="h6">Building a setlist</h3>
                    <ul>
                        <li>Open the <strong>Setlist</strong> tab from the bottom nav.</li>
                        <li>Tap <strong>+</strong> on any song page to add it to your active setlist (or pick a specific one from the dropdown).</li>
                        <li>Drag the row handles to reorder. Long-press to remove a song.</li>
                    </ul>
                    <h3 class="h6">Sharing</h3>
                    <p>
                        Tap the <i class="fa-solid fa-share-nodes" aria-hidden="true"></i> share icon on any of your setlists. iHymns generates
                        a short share URL that anyone can open — no account required for the recipient. They land
                        on a read-only copy with the same songs and order. You stay the owner; if you reorder
                        or add songs, the next time they open the link they see the updated version.
                    </p>
                    <h3 class="h6">Schedule a setlist for a date</h3>
                    <p>
                        On a setlist's detail view, tap <strong>Schedule</strong> to attach a date and time. The
                        setlist appears in the <strong>Upcoming</strong> list on your home page until that date
                        passes. Useful for planning a few weeks of services in advance and finding your way
                        back to the right one without scrolling.
                    </p>
                    <h3 class="h6">Collaborate on a setlist (signed-in users)</h3>
                    <p>
                        Sign in with an account, then on any setlist's detail page open the <strong>Collaborators</strong>
                        panel. Add other signed-in users by username — each one can re-order, add, and remove
                        songs alongside you. Removed collaborators see the setlist disappear from their
                        Shared list on next open.
                    </p>
                    <p class="small text-muted mb-0">
                        Setlists you create are saved to your account if you're signed in, or to your device
                        otherwise. Signing in later associates any device-only setlists with your account.
                    </p>
                </div>
            </div>
        </div>

        <!-- Language filter -->
        <div class="accordion-item">
            <h2 class="accordion-header">
                <button class="accordion-button collapsed"
                        type="button"
                        data-bs-toggle="collapse"
                        data-bs-target="#help-language-filter"
                        aria-expanded="false"
                        aria-controls="help-language-filter">
                    <i class="fa-solid fa-language me-2" aria-hidden="true"></i>
                    Language Filter
                </button>
            </h2>
            <div id="help-language-filter" class="accordion-collapse collapse" data-bs-parent="#help-accordion">
                <div class="accordion-body">
                    <p>
                        Pick one or more languages to <strong>show</strong> across the catalogue —
                        the rest are hidden. Untagged songbooks and untagged songs are always
                        shown, regardless of what you've selected.
                    </p>
                    <h3 class="h6">Where to set it</h3>
                    <ul>
                        <li><strong>Ad-hoc:</strong> the chip group at the top of the home page and <em>Songbooks</em> page.</li>
                        <li><strong>Persistent:</strong> the <em>Language Preferences</em> section on the <a href="/settings" data-navigate="settings">Settings</a> page.</li>
                    </ul>
                    <h3 class="h6">What it filters</h3>
                    <ul>
                        <li><strong>Songbook tiles</strong> on the home grid + <em>Songbooks</em> page.</li>
                        <li><strong>Search results</strong> — songs in unselected languages don't appear.</li>
                        <li><strong>Song lists</strong> inside a songbook detail page.</li>
                        <li><strong>Popular songs</strong> + <strong>recently viewed</strong> blocks on the home page.</li>
                    </ul>
                    <h3 class="h6">Sync across devices</h3>
                    <p>
                        If you're <a href="/login" data-navigate="login">signed in</a>, your
                        choice saves to your account and follows you to every device — the
                        web app, the iOS / iPadOS / tvOS / Android / Fire OS native apps.
                        Anonymous users get per-device persistence via local storage.
                    </p>
                    <p class="small text-muted mb-0">
                        Matching uses the primary language subtag — picking <code>en</code>
                        matches <code>en</code>, <code>en-GB</code>, <code>en-US</code>, etc.
                        The filter only appears when the catalogue spans at least two
                        distinct languages — until then, it stays hidden because there's
                        nothing to filter.
                    </p>
                </div>
            </div>
        </div>

        <!-- Themes & Accessibility -->
        <div class="accordion-item">
            <h2 class="accordion-header">
                <button class="accordion-button collapsed"
                        type="button"
                        data-bs-toggle="collapse"
                        data-bs-target="#help-themes"
                        aria-expanded="false"
                        aria-controls="help-themes">
                    <i class="fa-solid fa-palette me-2" aria-hidden="true"></i>
                    Themes &amp; Accessibility
                </button>
            </h2>
            <div id="help-themes" class="accordion-collapse collapse" data-bs-parent="#help-accordion">
                <div class="accordion-body">
                    <p>Customise the appearance to suit your needs:</p>
                    <ul>
                        <li><strong>Light Mode:</strong> Clean, bright interface ideal for well-lit environments</li>
                        <li><strong>Dark Mode:</strong> Darker colours, easier on the eyes in low light</li>
                        <li><strong>High Contrast:</strong> Enhanced visibility for colour vision deficiencies — uses patterns and distinct colour pairings</li>
                        <li><strong>System:</strong> Automatically matches your device's light/dark preference</li>
                    </ul>
                    <p>Access themes via the <i class="fa-solid fa-circle-half-stroke" aria-hidden="true"></i> icon in the top bar, or in <strong>Settings</strong>.</p>
                    <p>Additional accessibility options in Settings include adjustable font size, reduced motion, and reduced transparency.</p>
                </div>
            </div>
        </div>

        <!-- Installing as App -->
        <div class="accordion-item">
            <h2 class="accordion-header">
                <button class="accordion-button collapsed"
                        type="button"
                        data-bs-toggle="collapse"
                        data-bs-target="#help-install"
                        aria-expanded="false"
                        aria-controls="help-install">
                    <i class="fa-solid fa-mobile-screen-button me-2" aria-hidden="true"></i>
                    Installing as an App
                </button>
            </h2>
            <div id="help-install" class="accordion-collapse collapse" data-bs-parent="#help-accordion">
                <div class="accordion-body">
                    <p><?= htmlspecialchars($app["Application"]["Name"]) ?> can be installed as a standalone app on your device:</p>
                    <ul>
                        <li><strong>Android (Chrome):</strong> Tap the install banner or use the browser menu &rarr; "Add to Home Screen"</li>
                        <li><strong>iOS (Safari):</strong> Tap the Share button <i class="fa-solid fa-arrow-up-from-bracket" aria-hidden="true"></i> &rarr; "Add to Home Screen"</li>
                        <li><strong>Desktop (Chrome/Edge):</strong> Click the install icon in the address bar or use the browser menu</li>
                    </ul>
                    <p>Once installed, the app opens in its own window and works offline with cached content.</p>
                </div>
            </div>
        </div>

        <!-- Offline Songs -->
        <div class="accordion-item">
            <h2 class="accordion-header">
                <button class="accordion-button collapsed"
                        type="button"
                        data-bs-toggle="collapse"
                        data-bs-target="#help-offline"
                        aria-expanded="false"
                        aria-controls="help-offline">
                    <i class="fa-solid fa-cloud-arrow-down me-2" aria-hidden="true"></i>
                    Offline Songs
                </button>
            </h2>
            <div id="help-offline" class="accordion-collapse collapse" data-bs-parent="#help-accordion">
                <div class="accordion-body">
                    <p>Download songs for offline access so you can use iHymns without an internet connection:</p>
                    <ul>
                        <li>Go to <strong>Settings</strong> &rarr; <strong>Offline Songs</strong></li>
                        <li>Download individual songbooks or tap <strong>Download All Songbooks</strong> (~14 MB total)</li>
                        <li>Downloads continue in the background if you navigate away from Settings</li>
                        <li>Estimated storage sizes are shown for each songbook</li>
                    </ul>
                    <p>Songs you view are also automatically cached for offline use. The <strong>Popular Songs</strong> section on the home page works offline using your local viewing history.</p>
                    <p><strong>Auto-update:</strong> Enable <em>Auto-update offline songs</em> in Settings to keep your saved songs up to date when connected.</p>
                </div>
            </div>
        </div>

        <!-- Default Songbook -->
        <div class="accordion-item">
            <h2 class="accordion-header">
                <button class="accordion-button collapsed"
                        type="button"
                        data-bs-toggle="collapse"
                        data-bs-target="#help-default-songbook"
                        aria-expanded="false"
                        aria-controls="help-default-songbook">
                    <i class="fa-solid fa-book me-2" aria-hidden="true"></i>
                    Default Songbook
                </button>
            </h2>
            <div id="help-default-songbook" class="accordion-collapse collapse" data-bs-parent="#help-accordion">
                <div class="accordion-body">
                    <p>Set a default songbook in <strong>Settings</strong> to streamline navigation:</p>
                    <ul>
                        <li><strong>Number search:</strong> The keypad pre-selects your default songbook automatically</li>
                        <li><strong>Quick-jump:</strong> Type a song number from any page to navigate directly (no songbook picker needed)</li>
                        <li><strong>Shuffle:</strong> Your default songbook is highlighted in the shuffle modal</li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Keyboard Shortcuts -->
        <div class="accordion-item">
            <h2 class="accordion-header">
                <button class="accordion-button collapsed"
                        type="button"
                        data-bs-toggle="collapse"
                        data-bs-target="#help-shortcuts"
                        aria-expanded="false"
                        aria-controls="help-shortcuts">
                    <i class="fa-solid fa-keyboard me-2" aria-hidden="true"></i>
                    Keyboard Shortcuts
                </button>
            </h2>
            <div id="help-shortcuts" class="accordion-collapse collapse" data-bs-parent="#help-accordion">
                <div class="accordion-body">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th scope="col">Shortcut</th>
                                <th scope="col">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr><td><kbd>?</kbd></td><td>Show full keyboard shortcuts overlay</td></tr>
                            <tr><td><kbd>/</kbd> or <kbd>Ctrl+K</kbd></td><td>Open search</td></tr>
                            <tr><td><kbd>#</kbd></td><td>Open number pad</td></tr>
                            <tr><td><kbd>Esc</kbd></td><td>Close search / modal</td></tr>
                            <tr><td><kbd>F</kbd></td><td>Toggle favourite (on song page)</td></tr>
                            <tr><td><kbd>P</kbd></td><td>Presentation mode</td></tr>
                            <tr><td><kbd>&larr;</kbd></td><td>Previous song</td></tr>
                            <tr><td><kbd>&rarr;</kbd></td><td>Next song</td></tr>
                        </tbody>
                    </table>
                    <p class="small text-muted mb-0">
                        Shortcuts can be disabled in <strong>Settings → Accessibility</strong>.
                    </p>
                </div>
            </div>
        </div>

        <!-- Practice Mode -->
        <div class="accordion-item">
            <h2 class="accordion-header">
                <button class="accordion-button collapsed"
                        type="button"
                        data-bs-toggle="collapse"
                        data-bs-target="#help-practice"
                        aria-expanded="false"
                        aria-controls="help-practice">
                    <i class="fa-solid fa-graduation-cap me-2" aria-hidden="true"></i>
                    Practice / Memorisation Mode
                </button>
            </h2>
            <div id="help-practice" class="accordion-collapse collapse" data-bs-parent="#help-accordion">
                <div class="accordion-body">
                    <p>
                        On any song page, tap <strong>Practice</strong> to cycle through
                        three modes for learning hymns by heart:
                    </p>
                    <ul class="mb-0">
                        <li><strong>Full</strong> — normal lyrics.</li>
                        <li><strong>Dimmed</strong> — lyrics faded; hover or tap a line for a quick glance.</li>
                        <li><strong>Hidden</strong> — every line masked; tap individual lines to reveal as hints.</li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Request a song -->
        <div class="accordion-item">
            <h2 class="accordion-header">
                <button class="accordion-button collapsed"
                        type="button"
                        data-bs-toggle="collapse"
                        data-bs-target="#help-request"
                        aria-expanded="false"
                        aria-controls="help-request">
                    <i class="fa-solid fa-lightbulb me-2" aria-hidden="true"></i>
                    Requesting a song
                </button>
            </h2>
            <div id="help-request" class="accordion-collapse collapse" data-bs-parent="#help-accordion">
                <div class="accordion-body">
                    <p>
                        Can't find a hymn? Submit it via
                        <a href="/request" data-navigate="request">Request a Song</a>.
                        You'll get a tracking number; our curators triage submissions in
                        their admin queue.
                    </p>
                </div>
            </div>
        </div>

        <!-- Admin portal overview -->
        <div class="accordion-item">
            <h2 class="accordion-header">
                <button class="accordion-button collapsed"
                        type="button"
                        data-bs-toggle="collapse"
                        data-bs-target="#help-admin"
                        aria-expanded="false"
                        aria-controls="help-admin">
                    <i class="fa-solid fa-user-shield me-2" aria-hidden="true"></i>
                    Admin Portal (editors &amp; admins)
                </button>
            </h2>
            <div id="help-admin" class="accordion-collapse collapse" data-bs-parent="#help-accordion">
                <div class="accordion-body">
                    <p>If you're a <em>Curator/Editor</em>, <em>Admin</em>, or <em>Global Admin</em>, you have access to the portal at <a href="/manage/">/manage/</a> (or the alias <a href="/admin/">/admin/</a>).</p>
                    <ul class="mb-2">
                        <li><strong>Song Editor</strong> — edit lyrics, metadata, tags, arrangement; multi-select bulk delete; auto-saves per song.</li>
                        <li><strong>User Management</strong> — create, edit roles, deactivate.</li>
                        <li><strong>Analytics</strong> — top songs / searches / logins over 7, 30, 90 days; CSV export.</li>
                        <li><strong>Song Requests</strong> — triage user-submitted requests.</li>
                        <li><strong>Entitlements</strong> — grant/revoke per-capability permissions by role.</li>
                        <li><strong>Database Setup</strong> — install schema, migrate, backup, restore.</li>
                        <li><strong>Help &amp; Guides</strong> — a plain-English reference at <a href="/manage/help">/manage/help</a> covering every admin page, including the org-admin surface, the activity log error capture, the bulk migration runner, and the public REST API.</li>
                    </ul>
                    <p class="small text-muted mb-2">
                        Permissions use <strong>entitlements</strong>. Each feature is gated by a
                        named capability (e.g. <code>edit_songs</code>, <code>view_analytics</code>)
                        assigned to roles. A global-admin can reassign capabilities at
                        <a href="/manage/entitlements">/manage/entitlements</a>.
                    </p>
                    <p class="small text-muted mb-0">
                        Native clients (Apple, Android, FireOS) drive the same surfaces via the public
                        REST API documented in <a href="/api-docs.yaml"><code>/api-docs.yaml</code></a> —
                        every admin verb on the web admin has a public-API counterpart, with the same
                        validation rules and audit-log trail.
                    </p>
                </div>
            </div>
        </div>

    </div>

    <!-- ============================================================
         SUGGEST A MISSING SONG — CTA to the dedicated form page (#656)
         The actual submission form lives at /request where it
         has the page to itself, supports offline queueing, and returns
         a tracking-id reference. We keep a card here so help-page
         readers still see the feature exists.
         ============================================================ -->
    <div class="card mb-3 mt-4">
        <div class="card-body">
            <h5>
                <i class="fa-solid fa-paper-plane me-2" aria-hidden="true"></i>
                Suggest a Missing Song
            </h5>
            <p class="text-muted small mb-3">
                Can't find a song? Let us know and we'll try to add it.
                You can also reach this from the &ldquo;Report a missing
                song&rdquo; link at the bottom of any song page.
            </p>
            <a href="/request" data-navigate="request" class="btn btn-primary">
                <i class="fa-solid fa-paper-plane me-1" aria-hidden="true"></i>
                Open the request form
            </a>
        </div>
    </div>

</section>
