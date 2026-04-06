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
                        <li>Select a songbook from the dropdown</li>
                        <li>Enter the song number using the keypad</li>
                        <li>Matching songs appear as you type</li>
                        <li>Tap a result or press the arrow button to go to the song</li>
                    </ol>
                    <p>The number pad is available on the Search page, the Home page, and from within any songbook.</p>
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
                            <tr><td><kbd>/</kbd> or <kbd>Ctrl+K</kbd></td><td>Open search</td></tr>
                            <tr><td><kbd>#</kbd></td><td>Open number pad</td></tr>
                            <tr><td><kbd>Esc</kbd></td><td>Close search / modal</td></tr>
                            <tr><td><kbd>F</kbd></td><td>Toggle favourite (on song page)</td></tr>
                            <tr><td><kbd>&larr;</kbd></td><td>Previous song</td></tr>
                            <tr><td><kbd>&rarr;</kbd></td><td>Next song</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>

</section>
