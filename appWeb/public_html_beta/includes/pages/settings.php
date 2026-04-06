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
