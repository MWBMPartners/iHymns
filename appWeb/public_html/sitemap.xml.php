<?php

declare(strict_types=1);

/**
 * iHymns — Dynamic XML Sitemap Generator
 *
 * PURPOSE:
 * Generates a standards-compliant XML sitemap for search engine crawlers.
 * Dynamically includes all songbooks, songs, writers, and static pages
 * from the song database so new content is automatically indexed.
 *
 * ACCESSED VIA:
 *   /sitemap.xml → sitemap.xml.php (rewritten by .htaccess)
 *
 * OUTPUT:
 *   Standard XML sitemap per https://www.sitemaps.org/protocol.html
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'config.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'db_mysql.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'SongData.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'activity_log.php';
/* Mirror every uncaught \Throwable + PHP fatal into tblActivityLog
   — a sitemap generator that 500s breaks search-engine indexing,
   so the failure needs to surface in /manage/activity-log. */
installGlobalActivityLogHandlers('sitemap');

/* =========================================================================
 * CONFIGURATION
 * ========================================================================= */

/** Base URL — validated against a whitelist (#526).
 *
 *  $_SERVER['HTTP_HOST'] is attacker-controllable: a poisoned Host
 *  header would otherwise be rendered into <loc> URLs, allowing
 *  search-engine cache poisoning / SEO-poisoning attacks. Whitelist
 *  is the four canonical channels (production + www + dev + beta).
 *  Anything else falls back to the production domain. */
$scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$_allowedSitemapHosts = [
    'ihymns.app',
    'www.ihymns.app',
    'dev.ihymns.app',
    'beta.ihymns.app',
];
$_requestHost = $_SERVER['HTTP_HOST'] ?? '';
$host = in_array($_requestHost, $_allowedSitemapHosts, true)
    ? $_requestHost
    : 'ihymns.app';
$baseUrl = $scheme . '://' . $host;

/** Today's date in W3C format for lastmod */
$today = date('Y-m-d');

/* =========================================================================
 * LOAD SONG DATA
 * ========================================================================= */

/* DB-direct (WS-J #1020): SongData throws when MySQL is unreachable — there is
   no stale-JSON fallback any more. A 500 sitemap breaks search-engine indexing,
   so on a DB outage we degrade to a still-valid STATIC-pages-only sitemap and
   signal 503 + Retry-After: crawlers keep their cached full sitemap and come
   back, while the core static URLs stay indexable. An empty $songbooks makes
   every DB-dependent loop below a no-op, so $songData is never dereferenced. */
$songData  = null;
$songbooks = [];
try {
    $songData  = new SongData();
    $songbooks = $songData->getSongbooks();
} catch (\Throwable $e) {
    error_log('[sitemap] song data unavailable, emitting static-only sitemap: ' . $e->getMessage());
    http_response_code(503);
    header('Retry-After: 300');
}

/* =========================================================================
 * BUILD SITEMAP ENTRIES
 * ========================================================================= */

$urls = [];

/* --- Static pages --- */
$staticPages = [
    '/'          => ['priority' => '1.0', 'changefreq' => 'daily'],
    '/songbooks' => ['priority' => '0.9', 'changefreq' => 'weekly'],
    '/search'    => ['priority' => '0.7', 'changefreq' => 'monthly'],
    '/favorites' => ['priority' => '0.5', 'changefreq' => 'monthly'],
    '/help'      => ['priority' => '0.4', 'changefreq' => 'monthly'],
    '/settings'  => ['priority' => '0.3', 'changefreq' => 'monthly'],
    '/privacy'   => ['priority' => '0.3', 'changefreq' => 'yearly'],
    '/terms'     => ['priority' => '0.3', 'changefreq' => 'yearly'],
];

foreach ($staticPages as $path => $meta) {
    $urls[] = [
        'loc'        => $baseUrl . $path,
        'lastmod'    => $today,
        'changefreq' => $meta['changefreq'],
        'priority'   => $meta['priority'],
    ];
}

/* --- Songbook pages --- */
foreach ($songbooks as $book) {
    $bookId = $book['id'] ?? '';
    if ($bookId === '') {
        continue;
    }

    $urls[] = [
        'loc'        => $baseUrl . '/songbook/' . rawurlencode($bookId),
        'lastmod'    => $today,
        'changefreq' => 'weekly',
        'priority'   => '0.8',
    ];
}

/* --- Individual song pages --- */
$allWriters = [];
foreach ($songbooks as $book) {
    $bookId = $book['id'] ?? '';
    if ($bookId === '') {
        continue;
    }

    $songs = $songData->getSongs($bookId);
    foreach ($songs as $song) {
        $songId = $song['id'] ?? '';
        if ($songId === '') {
            continue;
        }

        $urls[] = [
            'loc'        => $baseUrl . '/song/' . rawurlencode($songId),
            'lastmod'    => $today,
            'changefreq' => 'monthly',
            'priority'   => '0.6',
        ];

        /* Collect unique writers/composers for writer pages */
        foreach (($song['writers'] ?? []) as $w) {
            $allWriters[$w] = true;
        }
        foreach (($song['composers'] ?? []) as $c) {
            $allWriters[$c] = true;
        }
    }
}

/* --- Writer pages --- */
foreach (array_keys($allWriters) as $writer) {
    $slug = rawurlencode(strtolower(str_replace(' ', '-', $writer)));
    $urls[] = [
        'loc'        => $baseUrl . '/writer/' . $slug,
        'lastmod'    => $today,
        'changefreq' => 'monthly',
        'priority'   => '0.5',
    ];
}

/* =========================================================================
 * OUTPUT XML SITEMAP
 * ========================================================================= */

header('Content-Type: application/xml; charset=utf-8');
header('X-Content-Type-Options: nosniff');

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
<?php foreach ($urls as $url): ?>
    <url>
        <loc><?= htmlspecialchars($url['loc'], ENT_XML1, 'UTF-8') ?></loc>
        <lastmod><?= $url['lastmod'] ?></lastmod>
        <changefreq><?= $url['changefreq'] ?></changefreq>
        <priority><?= $url['priority'] ?></priority>
    </url>
<?php endforeach; ?>
</urlset>
