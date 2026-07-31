<?php

/**
 * iHymns — Help Page Template
 *
 * PURPOSE:
 * In-app help and user guide. Provides instructions for using the
 * application, including accounts &amp; signing in (email, password,
 * Sign in with Apple, signed-in devices), searching, reading a song
 * (musical key/tempo/time signature, transpose/chords, sheet music,
 * audio, compare versions, Presentation mode), sharing &amp; exporting
 * songs to projection software, favourites, setlists (incl. templates
 * &amp; service plans), collections/series, Song of the Day, personal
 * stats, themes, PWA install, offline songs, following a live service
 * (Service Mode, for a congregant), Go Live (Live Follow — any
 * signed-in user hosting their own live session), notifications
 * (Web Push), requesting a song (incl. tracking your own requests),
 * keyboard shortcuts, and accessibility.
 *
 * Loaded via AJAX: api.php?page=help
 *
 * Last updated: 2026-07-31 — covered five features that shipped with
 * server support but had no in-app help: musical key / tempo / time
 * signature (#298, wired #1671 F3) added to Reading a Song; setlist
 * templates & service plans (#301, wired #1671 F4) added under
 * Setlists & Sharing; a new Notifications topic for Web Push (#311,
 * wired #1671 F6) — written as instructions for once a site operator
 * switches it on, since no push has yet been delivered to a real
 * device; tracking your own song requests (#280, wired #1671 F2)
 * added under Requesting a song; and Signed-in devices (#1409/#1511,
 * wired #1671 F1) added under Account & Signing In. Also corrected the
 * Admin Portal topic: song deletion is now Admin/Global Admin only
 * (#1692 stage 1), not a Curator/Editor capability.
 * Previous update 2026-07-29 (v0.4001.0) — added a "Playing through a
 * setlist" topic under Setlists & Sharing (#1533): tap any song in a
 * setlist, your own or a shared one, to start a bottom playback bar
 * with Prev/Next, position, next-song title, arrow-key navigation, and
 * an exit control.
 * Previous update 2026-07-28 (v0.4000.0) added the "Sharing & exporting
 * songs" topic (#1586), which had zero coverage despite the public
 * Export ▾ menu shipping on both the song and songbook pages; also
 * pointed "Getting Started" at the footer's What's New link (#1583).
 * Previous update 2026-07-26 split the single "Following a Live
 * Service" item into two adjacent items so Service Mode (church admin
 * console, rotating venue code) and Live Follow (any signed-in user,
 * fixed personal code) can no longer be confused for one another
 * (#1577).
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
                    <p class="small text-muted mb-0">
                        Curious what changed since your last visit? The version number in
                        the footer links to the <strong>What&rsquo;s New</strong> page.
                    </p>
                </div>
            </div>
        </div>

        <!-- Account & Signing In (#1402/#1470/#1477/#1479) -->
        <div class="accordion-item">
            <h2 class="accordion-header">
                <button class="accordion-button collapsed"
                        type="button"
                        data-bs-toggle="collapse"
                        data-bs-target="#help-account"
                        aria-expanded="false"
                        aria-controls="help-account">
                    <i class="fa-solid fa-right-to-bracket me-2" aria-hidden="true"></i>
                    Account &amp; Signing In
                </button>
            </h2>
            <div id="help-account" class="accordion-collapse collapse" data-bs-parent="#help-accordion">
                <div class="accordion-body">
                    <p>
                        Creating an account is <strong>optional</strong> — you can browse, search, and use
                        offline features anonymously. Signing in lets your favourites, tags, set lists,
                        language and theme preferences, and personal stats follow you to every device.
                    </p>

                    <h3 class="h6">Ways to sign in</h3>
                    <ul>
                        <li><strong>Email sign-in (no password):</strong> enter your email address and we'll send a one-tap sign-in link and a 6-digit code to your inbox — use whichever arrives first, no password to remember.</li>
                        <li><strong>Username &amp; password:</strong> if you've set a password, sign in with your username and password instead. Use <em>Forgot password?</em> on the sign-in screen to reset it.</li>
                        <li><strong>Sign in with Apple:</strong> always available in the iHymns app for iPhone, iPad, and Apple TV, and on the web when the site operator has switched it on. Tap <strong>Sign in with Apple</strong> and authenticate with Face&nbsp;ID, Touch&nbsp;ID, or your device passcode — Apple lets you choose whether to share your real email address or a private, forwarding &ldquo;Hide My Email&rdquo; address.</li>
                    </ul>

                    <h3 class="h6 mt-3">Linking &amp; unlinking Apple</h3>
                    <p>
                        On the web, open <strong>Settings &rarr; Account &amp; Profile</strong>. If Sign in
                        with Apple is available on this site, a <strong>Connected accounts</strong> card lets
                        you <strong>link</strong> your Apple ID to your existing account or <strong>unlink</strong>
                        it later. You can't unlink your only way of signing back in — set a password or add a
                        verified email address first if Apple is currently your sole sign-in method.
                    </p>

                    <h3 class="h6 mt-3">Signed-in devices</h3>
                    <p>
                        Open <strong>Settings &rarr; Account &amp; Profile</strong> to see a
                        <strong>Signed-in devices</strong> card listing every device currently
                        signed in to your account — handy for spotting a borrowed laptop or a
                        shared church computer you forgot to sign out of. Each row shows the
                        device's name (or platform, if it never sent one) and roughly how long
                        ago it was last used; the device you're reading this on carries a
                        <strong>This device</strong> badge and has no button of its own.
                    </p>
                    <p class="small text-muted mb-0">
                        Tap <strong>Sign out</strong> beside any other device to end its
                        session immediately — it will need to sign in again next time. To end
                        your <em>own</em> current session, use the ordinary Sign Out button
                        above instead.
                    </p>

                    <h3 class="h6 mt-3">Deleting your account</h3>
                    <p>
                        In the iHymns app for iPhone, iPad, or Apple TV, open <strong>Account &rarr; Danger
                        Zone &rarr; Delete Account</strong>. You'll be asked to confirm it's really you (your
                        password, or a one-time code emailed to you) before anything is removed. Deleting your
                        account permanently removes your account, favourites, and set lists from our servers,
                        revokes any linked Sign in with Apple grant, and cannot be undone.
                    </p>
                    <p class="small text-muted mb-0">
                        A self-service delete option in the web app is planned; until then, contact us via
                        <a href="<?= htmlspecialchars($app["Application"]["Repo"]["Issues"]["URL"]) ?>"
                           target="_blank" rel="noopener noreferrer">our GitHub repository</a>
                        to request deletion of a web-only account.
                    </p>
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

        <!-- Reading a song -->
        <div class="accordion-item">
            <h2 class="accordion-header">
                <button class="accordion-button collapsed"
                        type="button"
                        data-bs-toggle="collapse"
                        data-bs-target="#help-reading"
                        aria-expanded="false"
                        aria-controls="help-reading">
                    <i class="fa-solid fa-music me-2" aria-hidden="true"></i>
                    Reading a Song
                </button>
            </h2>
            <div id="help-reading" class="accordion-collapse collapse" data-bs-parent="#help-accordion">
                <div class="accordion-body">
                    <p>A song page gives you several ways to view and work with the music:</p>
                    <ul>
                        <li><strong>Musical key, tempo &amp; time signature:</strong> where a curator has recorded it, a badge near the top of the page shows the song's key, tempo and time signature (e.g. &ldquo;Key: Bb &middot; 96 BPM &middot; 4/4&rdquo;). If nothing appears, nobody has recorded a key for that song yet.</li>
                        <li><strong>Transpose &amp; chords:</strong> where chord data is available, raise or lower the key and the chords above the lyrics shift to match — the key badge names the key you've transposed <em>into</em>, not just an offset. Toggle chords on or off entirely.</li>
                        <li><strong>Sheet music:</strong> songs that have notation can switch to a <strong>sheet-music view</strong> to read the score.</li>
                        <li><strong>Audio playback:</strong> when a recording or tune is linked, play it back from the song page.</li>
                        <li><strong>Compare versions:</strong> when the same song appears in more than one songbook, open <strong>Compare versions</strong> to view the variants side by side.</li>
                        <li><strong>Presentation mode:</strong> tap <strong>Presentation</strong> (or press <kbd>P</kbd>) for a large, full-screen, one-stanza-at-a-time view suitable for projecting or reading at a distance. Use the arrow keys to move between sections.</li>
                        <li><strong>Previous / next:</strong> step through the songbook in number order with the <kbd>&larr;</kbd> and <kbd>&rarr;</kbd> arrow keys.</li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Sharing & exporting songs (#1586) — the public Export ▾ menu
             (includes/partials/export-menu.php) ships on both the song
             page and the songbook page, but had zero help-page coverage
             until now. -->
        <div class="accordion-item">
            <h2 class="accordion-header">
                <button class="accordion-button collapsed"
                        type="button"
                        data-bs-toggle="collapse"
                        data-bs-target="#help-export"
                        aria-expanded="false"
                        aria-controls="help-export">
                    <i class="fa-solid fa-file-export me-2" aria-hidden="true"></i>
                    Sharing &amp; Exporting Songs
                </button>
            </h2>
            <div id="help-export" class="accordion-collapse collapse" data-bs-parent="#help-accordion">
                <div class="accordion-body">
                    <p>
                        On any song page — or a whole songbook page — open <strong>Export
                        &#9662;</strong> to download the words in the format your projection
                        software uses: ProPresenter 6, ProPresenter 7+, OpenLyrics / OpenLP,
                        OpenSong, VideoPsalm, FreeShow, Proclaim, or ChordPro (a plain
                        chord-sheet file). Your browser downloads a file that you then open
                        or import in that program.
                    </p>
                    <p class="small text-muted mb-0">
                        If the menu opens but nothing downloads, refresh the page once — you
                        may be running an older cached version of iHymns.
                    </p>
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
                        <li>Favourites are kept on your device and persist between sessions &mdash; and when you&rsquo;re <strong>signed in</strong> they also sync automatically across all your devices, so a song you favourite on your phone shows up on your tablet too</li>
                        <li><strong>Organise with tags:</strong> add your own labels (e.g. &ldquo;Communion&rdquo;, &ldquo;Christmas&rdquo;) to a favourite from the tag <i class="fa-solid fa-tags" aria-hidden="true"></i> button, then filter the list by tag. Tags sync across devices when signed in too</li>
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
                    <h3 class="h6">Templates &amp; service plans</h3>
                    <p>
                        A service usually has a shape before it has songs — welcome, songs,
                        a reading, the sermon, a closing. Save that shape as a
                        <strong>template</strong> and reuse it every week.
                    </p>
                    <ul>
                        <li>Tap <strong>From Template</strong> next to New Set List, then a template's name, to start a new setlist with those rows already labelled.</li>
                        <li>Open the new setlist's <strong>Service plan</strong> card and use each row's dropdown to choose which of your songs fills it. Rows that aren't songs (a reading, a prayer, the sermon) show as a plain label with nothing to pick.</li>
                        <li>To make your own: open a setlist you've built and tap the <i class="fa-solid fa-file-lines" aria-hidden="true"></i> &ldquo;Save as template&rdquo; icon. Each song becomes a labelled row — the row keeps the song's <em>title</em>, not the song itself, so applying the template again starts you with an empty slot rather than repeating the same song.</li>
                        <li><strong>Manage templates&hellip;</strong> in the same dropdown lets you rename or delete templates you created. You can apply a template someone has shared with you, but only its creator can change or remove it.</li>
                    </ul>
                    <h3 class="h6">Sharing</h3>
                    <p>
                        Tap the <i class="fa-solid fa-share-nodes" aria-hidden="true"></i> share icon on any of your setlists. iHymns generates
                        a short share URL that anyone can open — no account required for the recipient. They land
                        on a read-only copy with the same songs and order. You stay the owner; if you reorder
                        or add songs, the next time they open the link they see the updated version.
                    </p>
                    <h3 class="h6">Playing through a setlist</h3>
                    <p>
                        Tap any song in a setlist — your own, or one someone has shared with you — to
                        start working through it in order. A bar appears at the bottom of the screen
                        with <strong>Prev</strong> / <strong>Next</strong> buttons, your position (e.g.
                        &ldquo;3 of 12&rdquo;), and the title of the next song. The <kbd>&larr;</kbd> and
                        <kbd>&rarr;</kbd> arrow keys move you between songs too.
                    </p>
                    <p class="small text-muted mb-0">
                        Tap the <i class="fa-solid fa-xmark" aria-hidden="true"></i> on the bar to stop.
                        Your place is remembered if you reload the page — handy if the browser stumbles
                        mid-service — but starts over once you close the tab or app.
                    </p>
                    <h3 class="h6 mt-3">Schedule a setlist for a date</h3>
                    <p>
                        On a setlist's detail view, tap <strong>Schedule</strong> to attach a date and time. The
                        setlist appears in the <strong>Upcoming</strong> list on your home page until that date
                        passes. Useful for planning a few weeks of services in advance and finding your way
                        back to the right one without scrolling.
                    </p>
                    <h3 class="h6">Collaborate on a setlist (signed-in users)</h3>
                    <p>
                        Sign in with an account, then on any setlist's detail page open the <strong>Collaborators</strong>
                        panel. Invite another signed-in user by email and choose whether they can
                        <strong>view</strong> the setlist or also <strong>edit</strong> it (re-order, add, and remove
                        songs) — a view-only collaborator can follow along but can't change anything. They get a
                        notification linking straight to it, and it also appears under <strong>Shared with me</strong>
                        on your Setlist tab. Removed collaborators see the setlist disappear from their Shared list
                        on next open.
                    </p>
                    <p class="small text-muted mb-0">
                        Setlists you create are saved to your account if you're signed in, or to your device
                        otherwise. Signing in later associates any device-only setlists with your account.
                    </p>
                </div>
            </div>
        </div>

        <!-- Notifications (#311, wired #1671 F6). Dormant until an operator
             generates a VAPID keypair on /manage/notifications — the card
             itself explains that state, so this topic is written as
             instructions for once it's switched on rather than a claim that
             it works today. -->
        <div class="accordion-item">
            <h2 class="accordion-header">
                <button class="accordion-button collapsed"
                        type="button"
                        data-bs-toggle="collapse"
                        data-bs-target="#help-notifications"
                        aria-expanded="false"
                        aria-controls="help-notifications">
                    <i class="fa-solid fa-bell me-2" aria-hidden="true"></i>
                    Notifications
                </button>
            </h2>
            <div id="help-notifications" class="accordion-collapse collapse" data-bs-parent="#help-accordion">
                <div class="accordion-body">
                    <p>
                        Sign in, then open <strong>Settings &rarr; Account &amp; Profile</strong>.
                        If your site has notifications switched on, a <strong>Notifications</strong>
                        card lets you turn them on for this device and choose which kinds you
                        want — for example <em>Announcements</em> (news, new songbooks and service
                        updates) or <em>Test notifications</em>. This is per device: turning it on
                        here only affects the device you're using.
                    </p>
                    <p class="small text-muted mb-0">
                        This is a new feature. If the card says notifications aren't switched on
                        for this site yet, your site's administrator hasn't finished setting it
                        up — there's nothing to turn on until they have. If your browser has
                        previously blocked notifications for this site, use the padlock or
                        &ldquo;i&rdquo; icon in the address bar to allow them again.
                    </p>
                </div>
            </div>
        </div>

        <!-- Following a Live Service (Service Mode) — church admin console,
             rotating venue code (#1323/#1335). Kept anchor #help-service-mode
             to preserve deep links. This item and "Go Live" immediately below
             it are DELIBERATELY split into two adjacent items (#1577) — the
             two features share a join dialog and have been mistaken for one
             another in every failed test of this feature to date. Do not
             merge them back into one item. -->
        <div class="accordion-item">
            <h2 class="accordion-header">
                <button class="accordion-button collapsed"
                        type="button"
                        data-bs-toggle="collapse"
                        data-bs-target="#help-service-mode"
                        aria-expanded="false"
                        aria-controls="help-service-mode">
                    <i class="fa-solid fa-tower-broadcast me-2" aria-hidden="true"></i>
                    Following a Live Service (Service Mode)
                </button>
            </h2>
            <div id="help-service-mode" class="accordion-collapse collapse" data-bs-parent="#help-accordion">
                <div class="accordion-body">
                    <p>
                        If your church runs <strong>Service Mode</strong>, a short code appears on the
                        venue's screen and changes every half-minute or so — the latest one always
                        works. Tap <strong>Join a live service</strong> on the home page, enter it, and
                        your screen follows the service: as the leader moves through songs and verses,
                        yours moves too. This is different from <strong>Live Follow</strong> below —
                        that code comes from a person, this one comes from the screen.
                    </p>
                    <ul>
                        <li>No account is required to join — a green <strong>Following the service live</strong> bar appears at the bottom of the screen while you're connected.</li>
                        <li>Tap <strong>Leave</strong> on that bar to stop following at any time.</li>
                        <li>Following ends automatically once the service is over, or if the code's session expires.</li>
                        <li>If the code is refused, read the <strong>current</strong> code off the screen and try again — and make sure you're on the same iHymns site address as your church.</li>
                    </ul>
                    <p class="small text-muted mb-0">
                        Leading the service? Start and project the code from
                        <a href="/manage/service-projection">Service Projection</a>, or drive it from
                        your phone at <a href="/manage/service-lead">Lead a Service</a> — both in the
                        admin console under Venues.
                    </p>
                </div>
            </div>
        </div>

        <!-- Go Live — Lead Your Own Live Session (Live Follow) — any signed-in
             user, no church setup (#1268). NEW anchor, sits immediately after
             Service Mode above (#1577) so the two are read together and
             contrasted, not encountered in isolation. -->
        <div class="accordion-item">
            <h2 class="accordion-header">
                <button class="accordion-button collapsed"
                        type="button"
                        data-bs-toggle="collapse"
                        data-bs-target="#help-live-follow"
                        aria-expanded="false"
                        aria-controls="help-live-follow">
                    <i class="fa-solid fa-tower-cell me-2" aria-hidden="true"></i>
                    Go Live &mdash; Lead Your Own Live Session (Live Follow)
                </button>
            </h2>
            <div id="help-live-follow" class="accordion-collapse collapse" data-bs-parent="#help-accordion">
                <div class="accordion-body">
                    <p>
                        <strong>Live Follow</strong> lets any signed-in user broadcast the songs they
                        open, to any device that joins with their code — no church setup, no venue,
                        nothing to configure. It's ideal for a band rehearsal, a home group, or a
                        leader sharing with the AV desk.
                    </p>

                    <h3 class="h6">To lead</h3>
                    <ol>
                        <li>Sign in, open any song.</li>
                        <li>Tap <strong>Go Live</strong> in the song's button row — you'll get a
                            six-character code (this one doesn't change).</li>
                        <li>Share it; then open songs as normal — everyone following switches with
                            you.</li>
                        <li>Tap <strong>End</strong> when done.</li>
                    </ol>

                    <h3 class="h6 mt-3">To follow</h3>
                    <ol class="mb-3">
                        <li>Tap <strong>Join Live</strong> on any song page (or <strong>Join a live
                            service</strong> on the home page — it accepts either kind of code).</li>
                        <li>Enter the code — capitals, spaces and dashes don't matter.</li>
                        <li>A blue <strong>Following &hellip; live</strong> bar appears; tap
                            <strong>Leave</strong> to stop.</li>
                    </ol>

                    <p class="small text-muted mb-0">
                        Keep the leader's screen awake &mdash; if it sleeps for more than a few
                        minutes the session pauses and followers may need to re-join. Everyone must
                        be on the same iHymns site address. Signing out, or going live again (which
                        makes a new code), ends the previous session.
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
                        <li>Download individual songbooks or tap <strong>Download All Songbooks</strong> to save the whole catalogue at once</li>
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
                    <h3 class="h6">Tracking your requests</h3>
                    <p class="mb-0">
                        Signed in when you submit? A <strong>Your requests</strong> section
                        appears below the form listing everything you've sent, each with a
                        status (<em>Pending</em>, <em>Reviewed</em>, <em>Added</em>, or
                        <em>Declined</em>) and, once a song has been added, a link straight
                        to it. Signed out, you'll see a sign-in prompt there instead — a
                        request sent while signed out can't be linked back to your account
                        afterwards, even if you sign in later.
                    </p>
                </div>
            </div>
        </div>

        <!-- Song of the Day -->
        <div class="accordion-item">
            <h2 class="accordion-header">
                <button class="accordion-button collapsed"
                        type="button"
                        data-bs-toggle="collapse"
                        data-bs-target="#help-song-of-the-day"
                        aria-expanded="false"
                        aria-controls="help-song-of-the-day">
                    <i class="fa-solid fa-calendar-day me-2" aria-hidden="true"></i>
                    Song of the Day
                </button>
            </h2>
            <div id="help-song-of-the-day" class="accordion-collapse collapse" data-bs-parent="#help-accordion">
                <div class="accordion-body">
                    <p>
                        The home page features a <strong>Song of the Day</strong> — a single hymn highlighted
                        for everyone each day. It's a quick way to discover something outside your usual
                        favourites. Tap it to open the full song.
                    </p>
                </div>
            </div>
        </div>

        <!-- Collections, Series & related browsing -->
        <div class="accordion-item">
            <h2 class="accordion-header">
                <button class="accordion-button collapsed"
                        type="button"
                        data-bs-toggle="collapse"
                        data-bs-target="#help-browsing"
                        aria-expanded="false"
                        aria-controls="help-browsing">
                    <i class="fa-solid fa-layer-group me-2" aria-hidden="true"></i>
                    Collections, Series &amp; Related Pages
                </button>
            </h2>
            <div id="help-browsing" class="accordion-collapse collapse" data-bs-parent="#help-accordion">
                <div class="accordion-body">
                    <h3 class="h6">Collections</h3>
                    <p>
                        A <strong>Collection</strong> is a curated grouping of songs drawn from across
                        different songbooks — for example a themed set for a season or topic. Browse the
                        collections to find ready-made groupings rather than hunting song by song.
                    </p>

                    <h3 class="h6 mt-3">Songbook Series &amp; parent songbooks</h3>
                    <p>
                        Related songbooks can be grouped into a <strong>Series</strong>, and a songbook can
                        have a <strong>parent</strong> songbook (for example, a regional edition under a
                        master hymnal). These relationships let you move between related books, and
                        editions of the same hymnal stay grouped together.
                    </p>
                    <p>
                        Some songbooks are flagged <strong>unofficial</strong> — curated groupings rather
                        than published hymnals. They appear alongside official songbooks with an
                        <em>Unofficial</em> badge beside their abbreviation so you can tell them apart.
                    </p>

                    <h3 class="h6 mt-3">Tune, ISWC, and person pages</h3>
                    <ul class="mb-0">
                        <li><strong>Tune pages</strong> (<code>/tune/&lt;slug&gt;</code>) list every hymn that shares a particular tune, so you can find different texts sung to the same melody.</li>
                        <li><strong>ISWC pages</strong> (<code>/iswc/&lt;code&gt;</code>) gather all entries that share an International Standard Musical Work Code — the same underlying composition across songbooks.</li>
                        <li><strong>Person pages</strong> show a writer's or composer's biography and every song they wrote, composed, translated, or arranged. Tap a credited name on any song to open their page.</li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Personal Stats -->
        <div class="accordion-item">
            <h2 class="accordion-header">
                <button class="accordion-button collapsed"
                        type="button"
                        data-bs-toggle="collapse"
                        data-bs-target="#help-stats"
                        aria-expanded="false"
                        aria-controls="help-stats">
                    <i class="fa-solid fa-chart-simple me-2" aria-hidden="true"></i>
                    Personal Stats
                </button>
            </h2>
            <div id="help-stats" class="accordion-collapse collapse" data-bs-parent="#help-accordion">
                <div class="accordion-body">
                    <p>
                        Visit <a href="/stats" data-navigate="stats">/stats</a> to see your own listening
                        and viewing activity — songs you've viewed most, songbooks you reach for, and how
                        your usage has built up over time. Your stats are personal to you and, when you're
                        signed in, follow you across devices.
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
                        <li><strong>Song Editor</strong> — edit lyrics, chords, arrangement, metadata, tags; multi-select bulk verify and tag; auto-saves as you go. Deleting a song is <strong>Admin</strong> / <strong>Global Admin</strong> only — it's permanent, so a Curator/Editor can't do it.</li>
                        <li><strong>User Management</strong> — create, edit roles, deactivate.</li>
                        <li><strong>Analytics</strong> — top songs / searches / logins over 7, 30, 90 days; CSV export.</li>
                        <li><strong>Song Requests</strong> — triage user-submitted requests.</li>
                        <li><strong>Entitlements</strong> — grant/revoke per-capability permissions by role.</li>
                        <li><strong>Database Setup</strong> — install schema, migrate, backup, restore.</li>
                        <li><strong>Help &amp; Guides</strong> — a plain-English reference at <a href="/manage/help">/manage/help</a> covering the admin surfaces, including the org-admin area, the activity log error capture, the bulk migration runner, and the public REST API.</li>
                    </ul>
                    <p class="small text-muted mb-2">
                        Permissions use <strong>entitlements</strong>. Each feature is gated by a
                        named capability (e.g. <code>edit_songs</code>, <code>view_analytics</code>)
                        assigned to roles. A global-admin can reassign capabilities at
                        <a href="/manage/entitlements">/manage/entitlements</a>.
                    </p>
                    <p class="small text-muted mb-0">
                        The public REST API is documented in <a href="/api-docs.yaml"><code>/api-docs.yaml</code></a>,
                        with the same validation rules and audit-log trail as the web admin. The native
                        clients (Apple, Android, Fire OS) are in-progress scaffolds that will consume the
                        same API as they mature.
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
