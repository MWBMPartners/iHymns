<?php

/**
 * iHymns — What's New Page Template (#1583)
 *
 * PURPOSE:
 * Shows visitors a short, human-readable excerpt of recent changes —
 * pulled from CHANGELOG.md at DEPLOY time (see `.github/workflows/
 * deploy.yml`'s "Extract What's New from CHANGELOG.md" step), rendered
 * through the escape-first `markdown_lite.php`. There is no database
 * involved and nothing is generated at request time: this page just reads
 * one small static file that CI already produced and prepared for it.
 *
 * Reached from the env-badge dropdown ("What's New") and the footer
 * version number (both in index.php) — see router.js for the `/whats-new`
 * route registration.
 *
 * SHARED-CACHE FRAGMENT (CLAUDE.md rule #6): `whats-new` is in api.php's
 * `$_cacheablePages` — the same excerpt is shown to every visitor (no
 * per-user data), so it's safe to ETag/cache like `help`/`terms`/`privacy`.
 * Never make this page render anything user-specific.
 *
 * Loaded via AJAX: api.php?page=whats-new
 */

declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'markdown_lite.php';

/**
 * Resolve + render a What's New excerpt file, or `null` if there isn't a
 * usable one.
 *
 * ELI5: try to read the "what's new" text file CI wrote; if it's not
 * there (a fresh checkout, or a deploy where CHANGELOG.md itself was
 * missing), just say so — the page never breaks, it just has less to show.
 *
 * DETAIL: `is_file()` (not `file_exists()`) so a directory accidentally
 * named `whats-new.md` can't be misread as content; `is_readable()` guards
 * a permissions edge case. `file_get_contents()` returning `false` (I/O
 * error) and an all-whitespace file are both treated the same as "absent"
 * — the visible fallback card is always preferable to an empty-but-styled
 * card or, worse, a PHP warning. Nothing here ever throws: the function
 * returns `null` and the caller below renders the fallback state, matching
 * the "never an error, never a fatal" requirement for this page.
 *
 * Takes the file path as a PARAMETER (rather than resolving it inline)
 * specifically so it is directly unit-testable against a deliberately
 * nonexistent path — see tests/php/test-whats-new-render.php — without
 * that test depending on whether a real deploy has ever run in this
 * checkout (the real path is git-ignored, deploy-generated, rule #6/#19).
 *
 * @param string $filePath Absolute path to a whats-new.md-shaped file.
 * @return string|null Rendered HTML, or null if there is nothing to show.
 */
function _whatsNewRenderExcerpt(string $filePath): ?string
{
    if (!is_file($filePath) || !is_readable($filePath)) {
        return null;
    }
    $raw = file_get_contents($filePath);
    if ($raw === false || trim($raw) === '') {
        return null;
    }
    return markdownLiteRender($raw);
}

$whatsNewFile = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..'
    . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'whats-new.md';
$whatsNewHtml = _whatsNewRenderExcerpt($whatsNewFile);

?>

<!-- ================================================================
     WHAT'S NEW PAGE — deploy-time CHANGELOG.md excerpt (#1583)
     ================================================================ -->
<section class="page-whats-new" aria-label="What's New">

    <h1 class="h4 mb-4">
        <i class="fa-solid fa-bullhorn me-2" aria-hidden="true"></i>
        What's New
    </h1>

    <?php if ($whatsNewHtml !== null): ?>
        <!-- Rendered excerpt. markdown_lite.php has already escaped the
             source text before interpreting any markdown syntax, so this
             is safe to echo directly — see that file's doc-block for why
             the ordering matters. -->
        <div class="card card-settings mb-3">
            <div class="card-body">
                <?= $whatsNewHtml ?>
            </div>
        </div>
    <?php else: ?>
        <!-- Fallback — no data/whats-new.md yet (fresh checkout, or a
             deploy that ran with no CHANGELOG.md to excerpt). Themed like
             the shared-set-list "no longer shared" fallback
             (includes/pages/setlist-shared.php) rather than an error, since
             an absent excerpt is an expected, non-error state. -->
        <div class="alert alert-secondary" role="alert">
            <i class="fa-solid fa-circle-info me-2" aria-hidden="true"></i>
            Change details are published with each deploy.
        </div>
    <?php endif; ?>

    <a href="/help" data-navigate="help" class="btn btn-outline-primary btn-sm">
        <i class="fa-solid fa-circle-question me-1" aria-hidden="true"></i>
        Back to Help
    </a>

</section>
