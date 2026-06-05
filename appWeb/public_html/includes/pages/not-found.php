<?php

/**
 * iHymns — 404 Not Found Page Template
 *
 * PURPOSE:
 * Displayed when a requested page or resource cannot be found.
 * Provides a friendly message and navigation back to the home page.
 *
 * Loaded via AJAX when an unknown page route is requested.
 */

declare(strict_types=1);

/* Unified themed error card (renderErrorFragment) — same look as every other
   in-SPA error state. The class `page-not-found` is preserved (callers/styles
   may reference it) by wrapping. Falls back to a static card if the renderer
   isn't loaded for any reason. */
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'error_page.php';

if (function_exists('renderErrorFragment')) {
    echo '<div class="page-not-found">' . renderErrorFragment(404, [
        'title'   => 'Page not found',
        'message' => "Sorry, the page you're looking for doesn't exist or has been moved.",
        'fa'      => 'fa-map-signs',
        'actions' => [
            ['label' => 'Go Home',         'href' => '/',          'navigate' => 'home',      'primary' => true, 'fa' => 'fa-house'],
            ['label' => 'Browse Songbooks', 'href' => '/songbooks', 'navigate' => 'songbooks', 'fa' => 'fa-book-open'],
        ],
    ]) . '</div>';
    return;
}
?>

<section class="page-not-found page-error text-center py-5" role="alert" aria-label="Page not found">
    <i class="fa-solid fa-map-signs fa-4x mb-4 text-muted opacity-50" aria-hidden="true"></i>
    <h1 class="h4 mb-3">Page not found</h1>
    <p class="text-muted mb-4">Sorry, the page you're looking for doesn't exist or has been moved.</p>
    <div class="d-flex justify-content-center gap-3 flex-wrap">
        <a href="/" class="btn btn-primary" data-navigate="home">
            <i class="fa-solid fa-house me-2" aria-hidden="true"></i>Go Home</a>
        <a href="/songbooks" class="btn btn-outline-secondary" data-navigate="songbooks">
            <i class="fa-solid fa-book-open me-2" aria-hidden="true"></i>Browse Songbooks</a>
    </div>
</section>
