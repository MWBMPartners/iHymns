<?php

/**
 * iHymns — Settings Page Template
 *
 * PURPOSE:
 * User settings and preferences page. Allows users to configure:
 *   - Theme (light, dark, high contrast, system)
 *   - Motion/animation preferences
 *   - Font size
 *   - Data & cache management
 *   - About information
 *
 * Settings are stored in localStorage and managed by settings.js.
 *
 * Loaded via AJAX: api.php?page=settings
 */

declare(strict_types=1);

?>

<!-- ================================================================
     SETTINGS PAGE — User preferences and app information
     ================================================================ -->
<section class="page-settings" aria-label="Settings">

    <h1 class="h4 mb-4">
        <i class="fa-solid fa-gear me-2" aria-hidden="true"></i>
        Settings
    </h1>

    <!-- ============================================================
         ACCOUNT SECTION — User authentication for cross-device sync
         ============================================================ -->
    <div class="card card-settings mb-3">
        <div class="card-body">
            <h2 class="h6 mb-3">
                <i class="fa-solid fa-user me-2" aria-hidden="true"></i>
                Account
            </h2>

            <!-- Logged-out state -->
            <div id="auth-logged-out">
                <p class="text-muted small mb-3">
                    Sign in to sync your set lists across devices. Your favourites
                    and settings stay on this device.
                </p>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-primary btn-sm" id="btn-auth-login">
                        <i class="fa-solid fa-right-to-bracket me-1" aria-hidden="true"></i>
                        Sign In
                    </button>
                    <button type="button" class="btn btn-outline-primary btn-sm" id="btn-auth-register">
                        <i class="fa-solid fa-user-plus me-1" aria-hidden="true"></i>
                        Create Account
                    </button>
                </div>
            </div>

            <!-- Logged-in state -->
            <div id="auth-logged-in" class="d-none">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <div>
                        <strong id="auth-display-name-text"></strong>
                        <small class="text-muted d-block" id="auth-username-text"></small>
                    </div>
                    <span class="badge bg-success"><i class="fa-solid fa-check me-1" aria-hidden="true"></i>Signed In</span>
                </div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-outline-primary btn-sm" id="btn-auth-sync">
                        <i class="fa-solid fa-arrows-rotate me-1" aria-hidden="true"></i>
                        Sync Set Lists
                    </button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="btn-auth-logout">
                        <i class="fa-solid fa-right-from-bracket me-1" aria-hidden="true"></i>
                        Sign Out
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ============================================================
         SYNC SECTION — Cross-device sync preferences (#284)
         ============================================================ -->
    <div class="card card-settings mb-3" id="settings-sync-card">
        <div class="card-body">
            <h2 class="h6 mb-3">
                <i class="fa-solid fa-arrows-rotate me-2" aria-hidden="true"></i>
                Sync
            </h2>
            <div class="form-check form-switch mb-2">
                <input class="form-check-input"
                       type="checkbox"
                       id="setting-sync-favorites"
                       role="switch"
                       checked
                       aria-label="Sync favourites across devices">
                <label class="form-check-label" for="setting-sync-favorites">
                    <strong>Sync favourites across devices</strong>
                    <small class="form-text text-muted d-block">
                        When signed in, your favourites will be synced to the server.
                    </small>
                </label>
            </div>
        </div>
    </div>

    <!-- ============================================================
         APPEARANCE SECTION
         ============================================================ -->
    <div class="card card-settings mb-3">
        <div class="card-body">
            <h2 class="h6 mb-3">
                <i class="fa-solid fa-palette me-2" aria-hidden="true"></i>
                Appearance
            </h2>

            <!-- Theme selection -->
            <div class="mb-3">
                <label class="form-label fw-semibold">Theme</label>
                <div class="d-flex flex-wrap gap-2" role="radiogroup" aria-label="Theme selection">
                    <button type="button" class="btn btn-theme-option" data-setting-theme="light" aria-pressed="false">
                        <i class="fa-solid fa-sun me-1" aria-hidden="true"></i> Light
                    </button>
                    <button type="button" class="btn btn-theme-option" data-setting-theme="dark" aria-pressed="false">
                        <i class="fa-solid fa-moon me-1" aria-hidden="true"></i> Dark
                    </button>
                    <button type="button" class="btn btn-theme-option" data-setting-theme="high-contrast" aria-pressed="false">
                        <i class="fa-solid fa-eye me-1" aria-hidden="true"></i> High Contrast
                    </button>
                    <button type="button" class="btn btn-theme-option" data-setting-theme="system" aria-pressed="false">
                        <i class="fa-solid fa-desktop me-1" aria-hidden="true"></i> System
                    </button>
                </div>
                <small class="text-muted mt-1 d-block">
                    High Contrast mode provides enhanced visibility for colour vision deficiencies.
                </small>
            </div>

            <!-- Colour vision mode (#319) -->
            <div class="mb-3">
                <label for="setting-cvd-mode" class="form-label fw-semibold">
                    Colour Vision Mode
                </label>
                <select class="form-select" id="setting-cvd-mode" aria-label="Colour vision deficiency correction">
                    <option value="">None (default colours)</option>
                    <option value="protanopia">Protanopia (red-blind)</option>
                    <option value="deuteranopia">Deuteranopia (green-blind)</option>
                    <option value="tritanopia">Tritanopia (blue-blind)</option>
                    <option value="achromatopsia">Achromatopsia (monochrome)</option>
                </select>
                <small class="text-muted mt-1 d-block">
                    Adjusts the colour palette for users with colour vision deficiencies.
                </small>
            </div>

            <!-- Default songbook (#96) -->
            <div class="mb-3">
                <label for="setting-default-songbook" class="form-label fw-semibold">
                    Default Songbook
                </label>
                <select class="form-select" id="setting-default-songbook" aria-label="Default songbook for quick-jump">
                    <option value="">None (ask each time)</option>
                    <?php
                        $settingSongbooks = $songData->getSongbooks();
                        foreach ($settingSongbooks as $book):
                            if (($book['songCount'] ?? 0) > 0):
                    ?>
                        <option value="<?= htmlspecialchars($book['id']) ?>">
                            <?= htmlspecialchars($book['name']) ?> (<?= htmlspecialchars($book['id']) ?>)
                        </option>
                    <?php
                            endif;
                        endforeach;
                    ?>
                </select>
                <small class="text-muted mt-1 d-block">
                    Used for keyboard quick-jump, number search, and shuffle mode.
                </small>
            </div>

            <!-- Numpad live search toggle -->
            <div class="mb-3">
                <div class="form-check form-switch">
                    <input class="form-check-input"
                           type="checkbox"
                           id="setting-numpad-live-search"
                           role="switch"
                           aria-label="Enable live search in number search">
                    <label class="form-check-label" for="setting-numpad-live-search">
                        <strong>Live number search</strong>
                        <small class="text-muted d-block">
                            Show matching songs as you type numbers. When off, press the Go button to navigate.
                        </small>
                    </label>
                </div>
            </div>

            <!-- Font size -->
            <div class="mb-0">
                <label for="setting-font-size" class="form-label fw-semibold">
                    Lyrics Font Size
                </label>
                <div class="d-flex align-items-center gap-3">
                    <span class="small" aria-hidden="true">A</span>
                    <input type="range"
                           class="form-range flex-grow-1"
                           id="setting-font-size"
                           min="14"
                           max="28"
                           step="2"
                           value="18"
                           aria-label="Lyrics font size"
                           aria-valuemin="14"
                           aria-valuemax="28">
                    <span class="h5 mb-0" aria-hidden="true">A</span>
                    <span class="badge bg-body-secondary" id="font-size-value">18px</span>
                </div>
            </div>
        </div>
    </div>

    <!-- ============================================================
         ACCESSIBILITY SECTION
         ============================================================ -->
    <div class="card card-settings mb-3">
        <div class="card-body">
            <h2 class="h6 mb-3">
                <i class="fa-solid fa-universal-access me-2" aria-hidden="true"></i>
                Accessibility
            </h2>

            <!-- Reduce motion toggle -->
            <div class="form-check form-switch mb-3">
                <input class="form-check-input"
                       type="checkbox"
                       id="setting-reduce-motion"
                       role="switch"
                       aria-label="Reduce motion and animations">
                <label class="form-check-label" for="setting-reduce-motion">
                    <strong>Reduce Motion</strong>
                    <small class="text-muted d-block">
                        Disables page transitions and animations. Recommended for users who experience
                        motion sensitivity. Enabled by default.
                    </small>
                </label>
            </div>

            <!-- Page transition style (#106) -->
            <div class="mb-3">
                <label for="setting-transition" class="form-label fw-semibold">
                    Page Transition
                </label>
                <select class="form-select" id="setting-transition" aria-label="Page transition style">
                    <option value="none">None (instant)</option>
                    <option value="fade">Fade</option>
                    <option value="slide">Slide</option>
                    <option value="crossfade">Crossfade</option>
                </select>
                <small class="text-muted mt-1 d-block">
                    Overridden by Reduce Motion when enabled.
                </small>
            </div>

            <!-- Reduce transparency toggle -->
            <div class="form-check form-switch mb-0">
                <input class="form-check-input"
                       type="checkbox"
                       id="setting-reduce-transparency"
                       role="switch"
                       aria-label="Reduce transparency effects">
                <label class="form-check-label" for="setting-reduce-transparency">
                    <strong>Reduce Transparency</strong>
                    <small class="text-muted d-block">
                        Removes glass-like blur effects for improved readability.
                    </small>
                </label>
            </div>
        </div>
    </div>

    <!-- ============================================================
         DATA & STORAGE SECTION
         ============================================================ -->
    <div class="card card-settings mb-3">
        <div class="card-body">
            <h2 class="h6 mb-3">
                <i class="fa-solid fa-database me-2" aria-hidden="true"></i>
                Data &amp; Storage
            </h2>

            <!-- Import/Export (#103) -->
            <div class="mb-3">
                <label class="form-label fw-semibold">Favourites &amp; Set Lists</label>
                <div class="d-flex flex-wrap gap-2">
                    <button type="button" class="btn btn-outline-primary btn-sm" id="export-data-btn">
                        <i class="fa-solid fa-file-export me-1" aria-hidden="true"></i>
                        Export Data
                    </button>
                    <label class="btn btn-outline-secondary btn-sm mb-0" for="import-data-input">
                        <i class="fa-solid fa-file-import me-1" aria-hidden="true"></i>
                        Import Data
                    </label>
                    <input type="file" id="import-data-input" accept=".json" class="d-none"
                           aria-label="Import favourites and set lists from JSON file">
                </div>
                <small class="text-muted mt-1 d-block">
                    Back up or restore your favourites and set lists as a JSON file.
                </small>
            </div>

            <!-- Download songs for offline -->
            <div class="mb-3">
                <label class="form-label fw-semibold">Offline Songs</label>

                <!-- Per-songbook download options (#356, #357) -->
                <div class="mb-2" id="offline-songbook-list">
                    <?php
                        $offlineSongbooks = $songData->getSongbooks();
                        $avgBytesPerSong = 4096; /* ~4 KB per cached song page */
                        $totalSongs = 0;
                        $totalEstBytes = 0;
                        foreach ($offlineSongbooks as $book):
                            $count = (int)($book['songCount'] ?? 0);
                            if ($count > 0):
                                $estBytes = $count * $avgBytesPerSong;
                                $totalSongs += $count;
                                $totalEstBytes += $estBytes;
                                if ($estBytes >= 1048576) {
                                    $estSize = round($estBytes / 1048576, 1) . ' MB';
                                } else {
                                    $estSize = round($estBytes / 1024) . ' KB';
                                }
                    ?>
                    <div class="offline-songbook-row">
                        <span class="badge songbook-badge" data-songbook="<?= htmlspecialchars($book['id']) ?>">
                            <?= htmlspecialchars($book['id']) ?>
                        </span>
                        <div class="offline-songbook-info">
                            <span class="small"><?= htmlspecialchars($book['name']) ?></span>
                            <span class="text-muted small">(<?= $count ?> songs)</span>
                        </div>
                        <span class="text-muted small offline-songbook-size">~<?= $estSize ?></span>
                        <span class="small text-muted offline-songbook-status" data-songbook="<?= htmlspecialchars($book['id']) ?>"></span>
                        <button type="button"
                                class="btn btn-outline-success btn-sm btn-download-songbook"
                                data-songbook-id="<?= htmlspecialchars($book['id']) ?>"
                                aria-label="Download <?= htmlspecialchars($book['name']) ?> for offline use">
                            <i class="fa-solid fa-cloud-arrow-down" aria-hidden="true"></i>
                        </button>
                    </div>
                    <?php
                            endif;
                        endforeach;
                        /* Format total estimate */
                        if ($totalEstBytes >= 1048576) {
                            $totalEstSize = round($totalEstBytes / 1048576, 1) . ' MB';
                        } else {
                            $totalEstSize = round($totalEstBytes / 1024) . ' KB';
                        }
                    ?>
                </div>

                <!-- Download all button -->
                <div class="d-flex flex-wrap align-items-center gap-2 mt-2">
                    <button type="button" class="btn btn-outline-success btn-sm" id="download-all-songs-btn"
                            aria-label="Download all songs for offline use">
                        <i class="fa-solid fa-cloud-arrow-down me-1" aria-hidden="true"></i>
                        Download All Songbooks
                    </button>
                    <span class="text-muted small">~<?= $totalEstSize ?></span>
                    <span id="download-songs-status" class="small text-muted"></span>
                </div>

                <!-- Progress bar (shared across all download operations) -->
                <div class="progress mt-2 d-none progress-thin" id="download-songs-progress">
                    <div class="progress-bar progress-bar-striped progress-bar-animated"
                         id="download-songs-bar" role="progressbar"
                         aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div>
                </div>
                <small class="text-muted mt-1 d-block">
                    Save songs to your device for offline access. Download individual songbooks or all at once.
                </small>

                <!-- Auto-update toggle (#132) -->
                <div class="form-check form-switch mt-2">
                    <input class="form-check-input"
                           type="checkbox"
                           id="setting-auto-update-songs"
                           role="switch"
                           aria-label="Automatically update offline songs">
                    <label class="form-check-label" for="setting-auto-update-songs">
                        <strong>Auto-update offline songs</strong>
                        <small class="text-muted d-block">
                            Automatically download updates for saved songs when connected.
                            When off, you will be notified of available updates.
                        </small>
                    </label>
                </div>
            </div>

            <!-- Cache info -->
            <div class="mb-3">
                <p class="mb-2">
                    <strong>Offline Cache:</strong>
                    <span id="cache-status" class="badge bg-body-secondary">Checking...</span>
                </p>
                <button type="button"
                        class="btn btn-outline-warning btn-sm"
                        id="clear-cache-btn"
                        aria-label="Clear offline cache">
                    <i class="fa-solid fa-broom me-1" aria-hidden="true"></i>
                    Clear Cache
                </button>
            </div>

            <!-- Usage statistics link (#120) -->
            <div>
                <a href="/stats"
                   class="btn btn-outline-info btn-sm"
                   data-navigate="stats"
                   aria-label="View usage statistics">
                    <i class="fa-solid fa-chart-simple me-1" aria-hidden="true"></i>
                    Usage Statistics
                </a>
            </div>

            <!-- Reset settings -->
            <div>
                <button type="button"
                        class="btn btn-outline-danger btn-sm"
                        id="reset-settings-btn"
                        aria-label="Reset all settings to defaults">
                    <i class="fa-solid fa-rotate-left me-1" aria-hidden="true"></i>
                    Reset All Settings
                </button>
            </div>
        </div>
    </div>

    <!-- ============================================================
         PRIVACY SECTION — Analytics consent management
         ============================================================ -->
    <?php
        $settingsHasGa4     = !empty(APP_CONFIG['analytics']['google_analytics_id']);
        $settingsHasClarity = !empty(APP_CONFIG['analytics']['clarity_id']);
        $settingsHasPlausible = !empty(APP_CONFIG['analytics']['plausible_domain']);
        $settingsNeedsConsent = ($settingsHasGa4 || $settingsHasClarity);
    ?>
    <?php if ($settingsNeedsConsent): ?>
    <div class="card card-settings mb-3">
        <div class="card-body">
            <h2 class="h6 mb-3">
                <i class="fa-solid fa-shield-halved me-2" aria-hidden="true"></i>
                Privacy
            </h2>

            <!-- Analytics consent toggle -->
            <div class="form-check form-switch mb-3">
                <input class="form-check-input"
                       type="checkbox"
                       id="setting-analytics-consent"
                       role="switch"
                       aria-label="Allow analytics">
                <label class="form-check-label" for="setting-analytics-consent">
                    <strong>Analytics</strong>
                    <span id="analytics-consent-status" class="privacy-consent-status text-muted ms-2">Not set</span>
                    <small class="text-muted d-block">
                        <?php if ($settingsHasGa4 && $settingsHasClarity): ?>
                            Allow Google Analytics and Microsoft Clarity to collect anonymous usage data.
                        <?php elseif ($settingsHasGa4): ?>
                            Allow Google Analytics to collect anonymous usage data.
                        <?php elseif ($settingsHasClarity): ?>
                            Allow Microsoft Clarity to collect anonymous usage data.
                        <?php endif; ?>
                        No personal information is collected or shared.
                        <?php if ($settingsHasPlausible): ?>
                            <br>Plausible Analytics runs regardless (cookieless and privacy-friendly).
                        <?php endif; ?>
                    </small>
                </label>
            </div>

            <?php if (defined('USER_DNT') && USER_DNT): ?>
            <div class="alert alert-info small mb-3" role="alert">
                <i class="fa-solid fa-eye-slash me-1" aria-hidden="true"></i>
                Your browser's <strong>Do Not Track</strong> setting is active. Analytics tracking is
                automatically restricted regardless of the toggle above.
            </div>
            <?php endif; ?>

            <a href="/privacy" data-navigate="privacy" class="btn btn-outline-secondary btn-sm">
                <i class="fa-solid fa-file-lines me-1" aria-hidden="true"></i>
                Privacy Policy
            </a>
        </div>
    </div>
    <?php endif; ?>

    <!-- ============================================================
         ABOUT SECTION
         ============================================================ -->
    <div class="card card-settings mb-3">
        <div class="card-body">
            <h2 class="h6 mb-3">
                <i class="fa-solid fa-circle-info me-2" aria-hidden="true"></i>
                About
            </h2>
            <dl class="row mb-0 about-list">
                <dt class="col-sm-4">Application</dt>
                <dd class="col-sm-8"><?= htmlspecialchars($app["Application"]["Name"]) ?></dd>

                <dt class="col-sm-4">Version</dt>
                <dd class="col-sm-8">
                    <?= htmlspecialchars($app["Application"]["Version"]["Number"]) ?>
                    <?php if ($app["Application"]["Version"]["Development"]["Status"]): ?>
                        <span class="badge bg-warning text-dark">
                            <?= htmlspecialchars($app["Application"]["Version"]["Development"]["Status"]) ?>
                        </span>
                    <?php endif; ?>
                </dd>

                <dt class="col-sm-4">Developer</dt>
                <dd class="col-sm-8"><?= htmlspecialchars($app["Application"]["Vendor"]["Name"]) ?></dd>

                <dt class="col-sm-4">Licence</dt>
                <dd class="col-sm-8">
                    <?= htmlspecialchars($app["Application"]["License"]["User"]["Type"]) ?>
                    (<?= htmlspecialchars($app["Application"]["License"]["User"]["Cost"]) ?>)
                </dd>

                <?php if ($app["Application"]["Version"]["Repo"]["Commit"]["SHA"]["Short"]): ?>
                    <dt class="col-sm-4">Build</dt>
                    <dd class="col-sm-8">
                        <?php if ($app["Application"]["Version"]["Repo"]["Commit"]["URL"]): ?>
                            <a href="<?= htmlspecialchars($app["Application"]["Version"]["Repo"]["Commit"]["URL"]) ?>"
                               target="_blank"
                               rel="noopener noreferrer">
                                <?= htmlspecialchars($app["Application"]["Version"]["Repo"]["Commit"]["SHA"]["Short"]) ?>
                            </a>
                        <?php else: ?>
                            <?= htmlspecialchars($app["Application"]["Version"]["Repo"]["Commit"]["SHA"]["Short"]) ?>
                        <?php endif; ?>
                    </dd>
                <?php endif; ?>
            </dl>
        </div>
    </div>

</section>
