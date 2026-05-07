<?php
/**
 * MissionPraise.com.php
 * importers/scrapers/MissionPraise.com.php
 *
 * Mission Praise Scraper — scrapes lyrics and downloads files from missionpraise.com.
 * Copyright 2025-2026 MWBM Partners Ltd.
 *
 * Overview:
 *     This scraper authenticates with missionpraise.com (a WordPress-based site
 *     that requires a paid subscription), then crawls the paginated song index
 *     to discover all songs across three hymnbooks:
 *         - Mission Praise (MP)  — ~1000+ songs, 4-digit numbering
 *         - Carol Praise (CP)    — ~300 songs, 3-digit numbering
 *         - Junior Praise (JP)   — ~300 songs, 3-digit numbering
 *
 *     For each song, it:
 *     1. Scrapes the lyrics from the song detail page
 *     2. Optionally downloads associated files (words RTF/DOC, music PDF, audio MP3)
 *     3. Saves everything with a consistent filename convention
 *
 *     The scraper is designed to be resumable: it caches the list of existing
 *     files on startup and skips songs that already have saved lyrics/downloads.
 *
 * Authentication:
 *     The site uses WordPress standard login (wp-login.php) with CSRF nonces
 *     and may be behind a Sucuri WAF (Web Application Firewall). The login
 *     flow handles:
 *     - Extracting hidden form fields (nonces) from the login page
 *     - Setting proper Referer/Origin headers to pass WAF checks
 *     - Detecting WAF blocks, login failures, and ambiguous states
 *     - Cookie-based session management via cURL cookie jar (temp file)
 *
 * File output format:
 *     Lyrics are saved as plain-text files:
 *         {padded_number} ({LABEL}) - {Title Case Title}.txt
 *     Download files use the same base name with type suffixes:
 *         {base}.rtf          (words — primary download, no suffix)
 *         {base}_music.pdf    (music score)
 *         {base}_audio.mp3    (audio recording)
 *
 *     Files are organised into book-specific subdirectories:
 *         hymns/Mission Praise [MP]/
 *         hymns/Carol Praise [CP]/
 *         hymns/Junior Praise [JP]/
 *
 * Dependencies:
 *     PHP 8.4+ with cURL extension (no Composer dependencies).
 *     This is intentional so the script can run on any system with PHP CLI.
 *
 * Usage:
 *     php MissionPraise.com.php --username YOUR_EMAIL --password YOUR_PASSWORD
 *     php MissionPraise.com.php --username YOUR_EMAIL --password YOUR_PASSWORD --output ~/Desktop/hymns
 *     php MissionPraise.com.php --username YOUR_EMAIL --password YOUR_PASSWORD --books mp,cp,jp
 *     php MissionPraise.com.php --username YOUR_EMAIL --password YOUR_PASSWORD --start-page 5
 *     php MissionPraise.com.php --username YOUR_EMAIL --password YOUR_PASSWORD --no-files
 *     php MissionPraise.com.php --username YOUR_EMAIL --password YOUR_PASSWORD --song 584 --books jp
 *     php MissionPraise.com.php --username YOUR_EMAIL --password YOUR_PASSWORD --debug
 *     php MissionPraise.com.php --username YOUR_EMAIL --password YOUR_PASSWORD --force
 *
 * @package iLyricsDB
 * @author  MWBM Partners Ltd
 * @license Proprietary
 */

// Ensure this script is run from the command line only
if (php_sapi_name() !== 'cli') {
    echo "This script must be run from the command line.\n";
    exit(1);
}

// ---------------------------------------------------------------------------
// Constants — URLs, timing, and global configuration
// ---------------------------------------------------------------------------

/** Base URL for the Mission Praise website */
define('BASE', 'https://missionpraise.com');

/** WordPress standard login endpoint */
define('LOGIN_URL', BASE . '/wp-login.php');

/** Paginated song index URL — append page number (e.g. /songs/page/1/) */
define('INDEX_URL', BASE . '/songs/page/');

/**
 * Default delay between HTTP requests (seconds). 1.2s is chosen to be
 * respectful to the server while keeping scraping at a reasonable speed.
 */
define('DEFAULT_DELAY', 1.2);

/** Default output directory (relative to where the script is run) */
define('DEFAULT_OUT', './hymns');

/**
 * Global debug flag — set to true via --debug CLI argument to dump HTML
 * responses for troubleshooting login/parsing issues.
 * We use a global variable rather than a constant because it's set at runtime.
 */
$DEBUG = false;

// ---------------------------------------------------------------------------
// Book configuration — defines the three hymnbooks scraped from the site
// ---------------------------------------------------------------------------
// Each book has:
//   label:   Short identifier used in filenames (e.g. "MP", "CP", "JP")
//   pad:     Number of digits to zero-pad the hymn number to (MP=4, others=3)
//   pattern: Regex to extract the book number from the index page title
//            e.g. "Amazing Grace (MP0023)" → matches "(MP0023)" → group(1) = "0023"
//   subdir:  Human-readable subdirectory name for organised file output
$BOOK_CONFIG = [
    'mp' => ['label' => 'MP', 'pad' => 4, 'pattern' => '/\\(MP(\\d+)\\)/i', 'subdir' => 'Mission Praise [MP]'],
    'cp' => ['label' => 'CP', 'pad' => 3, 'pattern' => '/\\(CP(\\d+)\\)/i', 'subdir' => 'Carol Praise [CP]'],
    'jp' => ['label' => 'JP', 'pad' => 3, 'pattern' => '/\\(JP(\\d+)\\)/i', 'subdir' => 'Junior Praise [JP]'],
];

// ---------------------------------------------------------------------------
// MIME type → file extension mapping for downloaded files
// ---------------------------------------------------------------------------
// When downloading files (words, music, audio), the server's Content-Type
// header tells us what format the file is in. This mapping converts common
// MIME types to their standard file extensions.
// "application/octet-stream" is a generic binary type — we fall back to
// guessing the extension from the URL or magic bytes in that case.
$MIME_TO_EXT = [
    'application/rtf'          => '.rtf',     // Rich Text Format (words)
    'text/rtf'                 => '.rtf',     // Alternate RTF MIME type
    'application/msword'       => '.doc',     // Microsoft Word (legacy)
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => '.docx',
    'application/pdf'          => '.pdf',     // PDF (music scores)
    'audio/midi'               => '.mid',     // MIDI audio
    'audio/x-midi'             => '.mid',     // Alternate MIDI MIME type
    'audio/mpeg'               => '.mp3',     // MP3 audio
    'audio/mp3'                => '.mp3',     // Alternate MP3 MIME type
    'audio/wav'                => '.wav',     // WAV audio
    'audio/x-wav'              => '.wav',     // Alternate WAV MIME type
    'audio/ogg'                => '.ogg',     // Ogg Vorbis audio
    'application/octet-stream' => '',         // Generic binary — fall back to URL/magic
];

// ---------------------------------------------------------------------------
// Magic bytes — file type detection from binary content
// ---------------------------------------------------------------------------
// When the Content-Type header is unhelpful (e.g. "application/octet-stream")
// and the URL doesn't reveal the file type, we can identify the format by
// examining the first few bytes of the file content. Most file formats begin
// with a distinctive "magic number" or signature.
$MAGIC_BYTES = [
    ['%PDF',                       '.pdf'],    // PDF files start with %PDF
    ["PK\x03\x04",                '.docx'],   // ZIP-based formats (docx, xlsx, pptx)
    ["\xd0\xcf\x11\xe0",          '.doc'],    // OLE2 compound files (doc, xls, ppt)
    ["{\\rtf",                     '.rtf'],    // Rich Text Format
    ['MThd',                       '.mid'],    // MIDI music files
    ['ID3',                        '.mp3'],    // MP3 with ID3v2 metadata header
    ["\xff\xfb",                   '.mp3'],    // MP3 frame sync (MPEG1 Layer 3)
    ["\xff\xf3",                   '.mp3'],    // MP3 frame sync (MPEG2 Layer 3)
    ['OggS',                       '.ogg'],    // Ogg Vorbis audio container
    ['RIFF',                       '.wav'],    // WAV audio (RIFF container format)
    ['fLaC',                       '.flac'],   // FLAC lossless audio
];

// ---------------------------------------------------------------------------
// Windows-1252 entity mapping — for numeric character references
// ---------------------------------------------------------------------------
// Some HTML from the site uses Windows-1252 code page numeric entities
// (&#145;, &#146;, &#150; etc.) which are NOT valid Unicode code points.
// These map to the 0x80-0x9F range in Windows-1252 and need special handling.
// Reference: https://en.wikipedia.org/wiki/Windows-1252
$WIN1252_MAP = [
    145 => "\u{2018}", // LEFT SINGLE QUOTATION MARK (')
    146 => "\u{2019}", // RIGHT SINGLE QUOTATION MARK (')
    147 => "\u{201C}", // LEFT DOUBLE QUOTATION MARK (")
    148 => "\u{201D}", // RIGHT DOUBLE QUOTATION MARK (")
    149 => "\u{2022}", // BULLET (•)
    150 => "\u{2013}", // EN DASH (–)
    151 => "\u{2014}", // EM DASH (—)
    152 => "\u{02DC}", // SMALL TILDE (~)
    153 => "\u{2122}", // TRADE MARK SIGN (™)
];


// =========================================================================
// Session / cURL — HTTP session management with cookie support
// =========================================================================

/**
 * Print a labelled debug dump of HTML/text content (only when DEBUG is true).
 *
 * Used during development and troubleshooting to inspect raw HTML responses
 * from the server. Truncates output to $maxChars to avoid flooding the terminal.
 *
 * @param string $label    A descriptive label for what's being dumped
 * @param string $text     The text content to dump
 * @param int    $maxChars Maximum characters to display (default: 3000)
 *
 * @return void
 */
function debugDump(string $label, string $text, int $maxChars = 3000): void
{
    global $DEBUG;
    if (!$DEBUG) {
        return;
    }
    echo "\n" . str_repeat('=', 60) . "\n";
    echo "DEBUG: {$label}\n";
    echo str_repeat('=', 60) . "\n";
    echo mb_substr($text, 0, $maxChars);
    if (mb_strlen($text) > $maxChars) {
        echo "\n... (" . (mb_strlen($text) - $maxChars) . " more chars)";
    }
    echo "\n" . str_repeat('=', 60) . "\n\n";
}


/**
 * Create a cURL handle configured with cookie support and browser-like headers.
 *
 * Returns a cURL handle configured to:
 * 1. Automatically store and send cookies (for session management after login)
 * 2. Send headers that mimic a real browser (to avoid being blocked by WAFs
 *    or bot detection systems)
 *
 * The headers are modelled after a real Chrome/Edge browser on macOS,
 * including Sec-Fetch-* headers that modern WAFs check to distinguish
 * legitimate browser requests from automated scripts.
 *
 * @return array{0: \CurlHandle, 1: string} Tuple of (cURL handle, cookie jar file path)
 */
function makeSession(): array
{
    // Create a temporary file for cookie storage. cURL's CURLOPT_COOKIEJAR
    // and CURLOPT_COOKIEFILE manage cookies automatically across requests.
    $cookieFile = tempnam(sys_get_temp_dir(), 'mp_cookies_');

    $ch = curl_init();

    // Cookie management: store received cookies and send them with subsequent requests
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);

    // Return the response body as a string instead of outputting it
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    // Follow HTTP redirects automatically (up to 10 hops)
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 10);

    // Accept compressed responses (gzip, deflate) — cURL handles decompression
    curl_setopt($ch, CURLOPT_ENCODING, '');

    // Connection timeout (10s) and transfer timeout (30s)
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    // Include the response headers in the output (we'll parse them separately)
    curl_setopt($ch, CURLOPT_HEADER, false);

    // Set browser-like headers to avoid WAF blocks.
    // These headers are sent with every request made through this handle.
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        // User-Agent string mimicking Chrome on macOS
        'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) '
            . 'AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 Edg/145.0.0.0',
        // Accept header indicating we prefer HTML but accept anything
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,'
            . 'image/webp,image/apng,*/*;q=0.8',
        'Accept-Language: en-GB,en;q=0.9',
        'Connection: keep-alive',
        'Upgrade-Insecure-Requests: 1',
        // Sec-Fetch-* headers: modern security headers that WAFs use to verify
        // requests come from a real browser navigation context
        'Sec-Fetch-Dest: document',
        'Sec-Fetch-Mode: navigate',
        'Sec-Fetch-Site: same-origin',
        'Sec-Fetch-User: ?1',
        'Cache-Control: max-age=0',
    ]);

    // SSL verification (use system CA bundle)
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

    return [$ch, $cookieFile];
}


/**
 * Perform a GET request using the cURL session.
 *
 * @param \CurlHandle $ch  cURL handle with cookie support
 * @param string      $url The URL to fetch
 *
 * @return array{0: string|null, 1: string|null} Tuple of (response body, final URL)
 *         Returns (null, null) on any error.
 */
function fetchText(\CurlHandle $ch, string $url): array
{
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HTTPGET, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);

    $body = curl_exec($ch);
    if ($body === false) {
        echo "\n  [!] Error fetching {$url}: " . curl_error($ch) . "\n";
        return [null, null];
    }

    $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    return [$body, $finalUrl];
}


/**
 * Download a binary file (words/music/audio) from a URL.
 *
 * Includes several safety checks:
 * 1. Detects server error pages masquerading as file downloads — some
 *    servers return HTML error pages with a 200 status code when the
 *    actual file is missing or access is denied
 *
 * The error detection heuristic checks if the response:
 * - Starts with common text/HTML bytes ('<', 's', 'e', '{')
 * - Is small enough to be an error page (< 500KB)
 * - Contains error-related keywords when decoded as UTF-8
 *
 * @param \CurlHandle $ch  cURL handle with cookie support
 * @param string      $url The download URL
 *
 * @return array{0: string|null, 1: string|null, 2: string|null}
 *         Tuple of (binary data, content type, final URL) on success,
 *         or (null, null, null) on error.
 */
function fetchBinary(\CurlHandle $ch, string $url): array
{
    global $DEBUG;

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HTTPGET, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $data = curl_exec($ch);
    if ($data === false) {
        echo "\n  [!] Download error {$url}: " . curl_error($ch) . "\n";
        return [null, null, null];
    }

    // Extract the MIME type from Content-Type (strip charset parameter)
    $ct = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    if ($ct !== null) {
        $ct = strtolower(trim(explode(';', $ct)[0]));
    } else {
        $ct = '';
    }

    $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);

    // --- Error page detection ---
    // Some servers return HTML error pages (PHP exceptions, 404 pages, etc.)
    // with a 200 status code instead of the actual file. We detect these by
    // checking if the content looks like text/HTML rather than binary data.
    // The size threshold is 500KB — PHP error dumps with full AWS S3 stack
    // traces can be 200KB+, so the previous 50KB limit missed them.
    $firstByte = substr($data, 0, 1);
    if (in_array($firstByte, ['<', 's', 'e', '{'], true) && strlen($data) < 500000) {
        // Check if it's valid UTF-8 text containing error keywords
        if (mb_check_encoding($data, 'UTF-8')) {
            $textLower = strtolower($data);
            $errorKeywords = ['exception', '<html', '<!doctype', 'error', 'not found', 'string(', 'nosuchkey', 'stacktrace'];
            foreach ($errorKeywords as $kw) {
                if (str_contains($textLower, $kw)) {
                    echo "server error ";
                    if ($DEBUG) {
                        debugDump('Server error in download', substr($data, 0, 500), 500);
                    }
                    return [null, null, null];
                }
            }
        }
    }

    return [$data, $ct, $finalUrl];
}


/**
 * Authenticate with missionpraise.com using WordPress standard login.
 *
 * The login flow is:
 * 1. GET the login page to obtain CSRF nonces (hidden form fields)
 * 2. Brief pause (2s) to appear more human-like
 * 3. POST credentials + nonces with proper Referer/Origin headers
 * 4. Handle various failure modes (WAF blocks, wrong password, etc.)
 * 5. Verify login succeeded by fetching the songs page and checking
 *    for logged-in indicators
 *
 * @param \CurlHandle $ch       cURL handle with cookie support
 * @param string      $username The user's missionpraise.com email address
 * @param string      $password The user's missionpraise.com password
 *
 * @return bool True if login appears successful, false if definitely failed
 */
function login(\CurlHandle $ch, string $username, string $password): bool
{
    global $DEBUG;

    echo "Logging in as {$username}...\n";

    // --- Step 1: GET the login page to extract CSRF nonces ---
    // WordPress login forms contain hidden fields (nonces) that must be
    // submitted with the POST request to prevent CSRF attacks.
    [$htmlText, ] = fetchText($ch, LOGIN_URL);
    if ($htmlText === null) {
        echo "  [!] Could not load login page.\n";
        return false;
    }

    debugDump('Login page HTML', $htmlText);

    // Extract all <input type="hidden"> fields from the login form.
    // These typically include:
    //   - testcookie: WordPress test cookie check
    //   - _wpnonce: WordPress CSRF nonce
    //   - Various plugin-specific nonces
    $hidden = [];
    if (preg_match_all('/<input[^>]+type=["\']hidden["\'][^>]*>/i', $htmlText, $inputMatches)) {
        foreach ($inputMatches[0] as $inputTag) {
            $nm = null;
            $vm = null;
            if (preg_match('/name=["\']([^"\']+)["\']/', $inputTag, $nameMatch)) {
                $nm = $nameMatch[1];
            }
            if (preg_match('/value=["\']([^"\']*)["\']/', $inputTag, $valMatch)) {
                $vm = $valMatch[1];
            }
            if ($nm !== null) {
                $hidden[$nm] = $vm ?? '';
            }
        }
    }

    if ($DEBUG) {
        echo "  DEBUG: Hidden fields: " . json_encode($hidden) . "\n";
    }

    // --- Step 2: Brief pause to appear more human-like ---
    // Some WAFs flag requests that POST to the login form within milliseconds
    // of loading the page, as this is a strong indicator of automated access.
    sleep(2);

    // --- Step 3: POST the login credentials ---
    // Build the login form data with WordPress standard field names
    $payload = array_merge([
        'log'         => $username,              // WordPress username field
        'pwd'         => $password,              // WordPress password field
        'wp-submit'   => 'Log In',               // Submit button value
        'redirect_to' => BASE . '/songs/',        // Where to redirect after login
        'rememberme'  => 'forever',              // Keep the session alive
    ], $hidden);                                  // Include all CSRF nonces

    $postData = http_build_query($payload);

    // Configure cURL for the POST request with proper Referer and Origin headers.
    // The Sucuri WAF checks these headers — missing or incorrect values
    // will result in a 403 block.
    curl_setopt($ch, CURLOPT_URL, LOGIN_URL);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) '
            . 'AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 Edg/145.0.0.0',
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language: en-GB,en;q=0.9',
        'Content-Type: application/x-www-form-urlencoded',
        'Referer: ' . LOGIN_URL,
        'Origin: ' . BASE,
        'Sec-Fetch-Dest: document',
        'Sec-Fetch-Mode: navigate',
        'Sec-Fetch-Site: same-origin',
        'Sec-Fetch-User: ?1',
        'Cache-Control: max-age=0',
    ]);

    $body = curl_exec($ch);
    $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);

    // Handle cURL errors (network failures, timeouts, etc.)
    if ($body === false) {
        echo "  [!] Login request failed: " . curl_error($ch) . "\n";
        return false;
    }

    // Reset to GET mode for subsequent requests
    curl_setopt($ch, CURLOPT_HTTPGET, true);
    // Restore the default headers (POST overrode them with Content-Type)
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) '
            . 'AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 Edg/145.0.0.0',
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,'
            . 'image/webp,image/apng,*/*;q=0.8',
        'Accept-Language: en-GB,en;q=0.9',
        'Connection: keep-alive',
        'Upgrade-Insecure-Requests: 1',
        'Sec-Fetch-Dest: document',
        'Sec-Fetch-Mode: navigate',
        'Sec-Fetch-Site: same-origin',
        'Sec-Fetch-User: ?1',
        'Cache-Control: max-age=0',
    ]);

    if ($DEBUG) {
        echo "  DEBUG: Post-login redirect URL: {$finalUrl}\n";
        debugDump('Post-login body', $body);
    }

    // --- Step 4: Check for various failure modes ---

    // Check for WAF / firewall blocks (e.g. Sucuri CDN/WAF)
    $bodyLower = strtolower($body);
    if (str_contains($bodyLower, 'sucuri') || str_contains($bodyLower, 'access denied')) {
        $reason = 'unknown';
        $ip     = 'unknown';
        if (preg_match('/Block reason:<\/td>\s*<td><span>(.*?)<\/span>/s', $body, $brMatch)) {
            $reason = trim($brMatch[1]);
        }
        if (preg_match('/Your IP:<\/td>\s*<td><span>(.*?)<\/span>/s', $body, $ipMatch)) {
            $ip = trim($ipMatch[1]);
        }
        echo "  [!] BLOCKED by website firewall: {$reason}\n";
        echo "       Your IP: {$ip}\n";
        echo "       Try again later or log in via browser first to whitelist your IP.\n";
        return false;
    }

    // Check for incorrect credentials — WordPress stays on wp-login.php
    // and includes an error message in the response body
    if (str_contains($finalUrl, 'wp-login.php') && str_contains($bodyLower, 'incorrect')) {
        echo "  [!] Login failed — check username/password.\n";
        return false;
    }

    // Check for other WordPress login errors (still on login page after POST)
    if (str_contains($finalUrl, 'wp-login.php')) {
        if ($DEBUG) {
            echo "  DEBUG: Still on login page after POST — login likely failed\n";
        }
        // Look for the WordPress login error div which contains specific messages
        if (preg_match('/<div[^>]*id=["\']login_error["\'][^>]*>(.*?)<\/div>/s', $body, $errMatch)) {
            $errorText = trim(strip_tags($errMatch[1]));
            echo "  [!] Login error: {$errorText}\n";
            return false;
        }
    }

    // --- Step 5: Verify login by fetching the songs page ---
    // Even if the POST didn't show an error, we verify by checking if the
    // songs page shows logged-in indicators.
    [$check, $checkUrl] = fetchText($ch, BASE . '/songs/');

    if ($DEBUG && $check !== null) {
        echo "  DEBUG: Songs page URL: {$checkUrl}\n";
        debugDump('Songs page HTML', $check);
    }

    if ($check !== null) {
        // Look for multiple indicators of being logged in — different WordPress
        // themes show different indicators, so we check several possibilities
        $checkLower = strtolower($check);
        $loggedIn = (
            str_contains($checkLower, 'logout')
            || str_contains($checkLower, 'log out')
            || str_contains($checkLower, 'log-out')
            || str_contains($check, 'Welcome')
            || str_contains($checkLower, 'my-account')
            || str_contains($checkLower, 'wp-admin')
            || str_contains($checkLower, 'logged-in')
        );

        if ($loggedIn) {
            echo "  [OK] Logged in.\n";
            return true;
        }

        // Check if we were redirected back to the login page
        if (str_contains($checkUrl ?? '', 'wp-login') || str_contains($checkUrl ?? '', 'login')) {
            echo "  [!] Login failed — redirected back to login page.\n";
            return false;
        }
    }

    // Login status is ambiguous — proceed anyway
    echo "  [?] Login status unclear — proceeding anyway.\n";
    echo "       (Re-run with --debug to see full HTML responses)\n";
    return true;
}


// =========================================================================
// HTML Parsing — regex-based parsing for index and song pages
// =========================================================================

/**
 * Parse the paginated song index page to discover song links.
 *
 * The index page lists songs as links within heading elements. Each song
 * appears as an <a> tag inside an <h2> or <h3>, with the title text
 * including the book code (e.g. "Amazing Grace (MP0023)").
 *
 * HTML structure being parsed:
 *     <h2>
 *         <a href="/songs/amazing-grace-mp0023/">Amazing Grace (MP0023)</a>
 *     </h2>
 *
 * @param string $html The raw HTML of the index page
 *
 * @return array<int, array{0: string, 1: string}> List of [title, url] pairs
 */
function parseIndex(string $html): array
{
    $songs = [];

    // Match <h2> or <h3> elements containing <a> tags with /songs/ URLs.
    // The regex captures both the href and the link text in one pass.
    // We use a non-greedy match across the heading content to handle
    // nested tags and whitespace variations.
    if (preg_match_all(
        '/<h[23][^>]*>\s*<a\s+[^>]*href=["\']([^"\']*\/songs\/[^"\']*)["\'][^>]*>(.*?)<\/a>/si',
        $html,
        $matches,
        PREG_SET_ORDER
    )) {
        foreach ($matches as $m) {
            $url   = $m[1];
            // Strip any HTML tags from the title text (e.g. <span> wrappers)
            $title = trim(strip_tags($m[2]));
            if ($title !== '' && $url !== '') {
                $songs[] = [$title, $url];
            }
        }
    }

    return $songs;
}


/**
 * Parse a song detail page to extract lyrics, copyright, title, and download links.
 *
 * Uses regex-based HTML parsing with preg_match/preg_match_all instead of
 * HTMLParser. Matches both <div> and <section> tags for song-details,
 * copyright-info, and files/col-sm-4 containers.
 *
 * The parsing handles several quirks of the missionpraise.com HTML:
 * - Unclosed <P> tags acting as verse separators (split on <p> boundaries)
 * - Double <BR><br /> collapse to single line breaks
 * - Windows-1252 entity mapping (&#145;, &#146;, &#150; etc.)
 * - f*I/f*R formatting code stripping (proprietary formatting marks)
 * - <em>/<i> tags indicating chorus lines (italic in the original)
 *
 * @param string $html The raw HTML of the song detail page
 *
 * @return array{title: string, verses: array, copyright: string, downloads: array}
 *         Parsed song data with:
 *         - title: The song title
 *         - verses: List of [text, is_italic] pairs
 *         - copyright: Copyright notice text
 *         - downloads: Map of type (words/music/audio) => URL
 */
function parseSong(string $html): array
{
    global $WIN1252_MAP;

    $title     = '';
    $verses    = [];
    $copyright = '';
    $downloads = [];

    // --- TITLE EXTRACTION ---
    // Look for the entry-title class on any element (div, h1, h2, span, etc.)
    if (preg_match('/<[^>]+class=["\'][^"\']*entry-title[^"\']*["\'][^>]*>(.*?)<\/[^>]+>/si', $html, $titleMatch)) {
        $title = trim(strip_tags($titleMatch[1]));
    }

    // --- DOWNLOAD LINKS ---
    // Download links are in a sidebar container with class "col-sm-4" or "files".
    // Match both <div> and <section> tags for maximum compatibility.
    $filesPattern = '/<(?:div|section)[^>]+class=["\'][^"\']*(?:col-sm-4|files)[^"\']*["\'][^>]*>([\s\S]*?)(?=<\/(?:div|section)>)/i';
    if (preg_match($filesPattern, $html, $filesMatch)) {
        $filesHtml = $filesMatch[1];
        // Extract all <a> tags within the files section
        if (preg_match_all('/<a\s+[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/si', $filesHtml, $linkMatches, PREG_SET_ORDER)) {
            foreach ($linkMatches as $lm) {
                $href  = $lm[1];
                $label = strtolower(trim(strip_tags($lm[2])));
                if ($href !== '' && $href !== '#') {
                    // Match the link to a download type based on its label text
                    foreach (['words', 'music', 'audio'] as $key) {
                        if (str_contains($label, $key)) {
                            $downloads[$key] = $href;
                            break;
                        }
                    }
                }
            }
        }
    }

    // --- COPYRIGHT EXTRACTION ---
    // Match both <div> and <section> tags with class "copyright-info"
    if (preg_match('/<(?:div|section)[^>]+class=["\'][^"\']*copyright-info[^"\']*["\'][^>]*>([\s\S]*?)<\/(?:div|section)>/i', $html, $crMatch)) {
        $copyright = trim(strip_tags($crMatch[1]));
    }

    // --- LYRICS EXTRACTION ---
    // The song-details container holds the lyrics in <p> blocks.
    // Match both <div> and <section> tags.
    $songDetailsPattern = '/<(?:div|section)[^>]+class=["\'][^"\']*song-details[^"\']*["\'][^>]*>([\s\S]*?)<\/(?:div|section)>/i';
    if (preg_match($songDetailsPattern, $html, $sdMatch)) {
        $songHtml = $sdMatch[1];

        // Remove the files/col-sm-4 sidebar from within song-details to prevent
        // download link text from being captured as lyrics
        $songHtml = preg_replace(
            '/<(?:div|section)[^>]+class=["\'][^"\']*(?:col-sm-4|files)[^"\']*["\'][^>]*>[\s\S]*?<\/(?:div|section)>/i',
            '',
            $songHtml
        );

        // --- Strip f*I / f*R formatting codes ---
        // Some songs contain proprietary formatting marks like f*I (start italic)
        // and f*R (reset formatting) that are not HTML. Remove them.
        $songHtml = preg_replace('/f\*[IR]/i', '', $songHtml);

        // --- Windows-1252 entity mapping ---
        // Replace numeric character references for Windows-1252 code points
        // (0x80-0x9F range) with their Unicode equivalents.
        $songHtml = preg_replace_callback(
            '/&#(\d+);/',
            function (array $m) use ($WIN1252_MAP): string {
                $num = (int) $m[1];
                if (isset($WIN1252_MAP[$num])) {
                    return $WIN1252_MAP[$num];
                }
                // For valid Unicode code points, convert to UTF-8 character
                if ($num > 0 && $num < 0x110000) {
                    $char = mb_chr($num, 'UTF-8');
                    return ($char !== false) ? $char : $m[0];
                }
                return $m[0];
            },
            $songHtml
        );

        // Decode standard named HTML entities (&amp;, &rsquo;, etc.)
        $songHtml = html_entity_decode($songHtml, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // --- Double <BR> collapse ---
        // The site often emits <BR><br /> (double break) on each line.
        // Collapse consecutive <br> tags into a single line break marker
        // before splitting on <p> boundaries.
        $songHtml = preg_replace('/(<br\s*\/?>\s*){2,}/i', '<br>', $songHtml);

        // --- Split on <p> boundaries ---
        // On missionpraise.com, each verse/line is a <p> element. Some pages
        // use unclosed <P> tags that don't have matching </p> closers.
        // Splitting on <p> tag boundaries handles both closed and unclosed styles.
        // First, normalize: split on any <p> or </p> tag boundary
        $parts = preg_split('/<\/?p[^>]*>/i', $songHtml);

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                // Empty part = stanza break (from empty <p> or </p><p> gap)
                $verses[] = ['', false];
                continue;
            }

            // --- Detect italic/chorus ---
            // Check if this paragraph contains <em> or <i> tags (chorus indicator)
            $isItalic = (bool) preg_match('/<(?:em|i)\b/i', $part);

            // Strip remaining HTML tags but preserve line breaks
            // Convert <br> to newline before stripping tags
            $text = preg_replace('/<br\s*\/?>/i', "\n", $part);
            $text = strip_tags($text);
            $text = trim($text);

            if ($text !== '') {
                $verses[] = [$text, $isItalic];
            }
        }
    }

    return [
        'title'     => $title,
        'verses'    => $verses,
        'copyright' => $copyright,
        'downloads' => $downloads,
    ];
}


// =========================================================================
// File extension detection — cascade strategy for download files
// =========================================================================

/**
 * Guess the file extension from a URL's path component.
 *
 * Parses the URL to extract the path, then gets the extension from the
 * last path segment. Server-side script extensions (.php, .asp, etc.)
 * are ignored because they indicate dynamic download endpoints rather
 * than actual file types.
 *
 * @param string $url The download URL to extract the extension from
 *
 * @return string The lowercase file extension (e.g. ".rtf") or "" if none found
 */
function extFromUrl(string $url): string
{
    $parsed = parse_url($url);
    $path   = $parsed['path'] ?? '';
    $ext    = strtolower(pathinfo($path, PATHINFO_EXTENSION));

    if ($ext === '') {
        return '';
    }

    // Ignore server-side script extensions — these are dynamic endpoints
    // that serve files, not the actual file extension
    $scriptExts = ['php', 'asp', 'aspx', 'jsp', 'cgi', 'py'];
    if (in_array($ext, $scriptExts, true)) {
        return '';
    }

    return '.' . $ext;
}


/**
 * Detect file type from the first few bytes of binary data (magic bytes).
 *
 * Compares the file's opening bytes against known signatures for common
 * document and audio formats. This is the last-resort detection method
 * when Content-Type and URL are both unhelpful.
 *
 * @param string $data The raw bytes of the downloaded file (at least 4 bytes needed)
 *
 * @return string The detected file extension (e.g. ".pdf") or "" if not recognised
 */
function extFromMagic(string $data): string
{
    global $MAGIC_BYTES;

    if (strlen($data) < 4) {
        return '';
    }

    foreach ($MAGIC_BYTES as [$magic, $ext]) {
        if (str_starts_with($data, $magic)) {
            return $ext;
        }
    }

    return '';
}


/**
 * Determine the correct file extension for a download using a cascade strategy.
 *
 * Tries three methods in order of reliability:
 * 1. MIME type from the Content-Type header (most reliable)
 * 2. File extension from the URL path (fallback)
 * 3. Magic bytes from the file content (last resort)
 *
 * If none of the methods identify the file type, defaults to ".bin" to
 * ensure the file is still saved (can be manually identified later).
 *
 * @param string      $contentType The Content-Type header value (e.g. "application/pdf")
 * @param string      $url         The download URL
 * @param string|null $data        The raw file bytes (optional, for magic byte detection)
 *
 * @return string The file extension including the dot (e.g. ".pdf", ".rtf", ".bin")
 */
function extForDownload(string $contentType, string $url, ?string $data = null): string
{
    global $MIME_TO_EXT;

    // Strategy 1: Check the MIME type lookup table
    $ext = $MIME_TO_EXT[$contentType] ?? '';
    if ($ext === '') {
        // Strategy 2: Extract extension from the URL path
        $ext = extFromUrl($url);
    }
    if ($ext === '' && $data !== null) {
        // Strategy 3: Detect from magic bytes in the file content
        $ext = extFromMagic($data);
    }

    // Fallback: use .bin so the file is still saved for manual inspection
    return ($ext !== '') ? $ext : '.bin';
}


// =========================================================================
// Book helpers — extract and clean book-specific data from song titles
// =========================================================================

/**
 * Extract the hymn number from a song title string for a specific book.
 *
 * Song titles on the index page include the book code and number in
 * parentheses, e.g. "Amazing Grace (MP0023)". This function uses the
 * book-specific regex pattern to extract just the numeric part.
 *
 * @param string $title The full song title from the index page
 * @param string $book  Book identifier key ("mp", "cp", or "jp")
 *
 * @return int|null The hymn number (e.g. 23 from "MP0023") or null if not found
 */
function extractNumber(string $title, string $book): ?int
{
    global $BOOK_CONFIG;
    $cfg = $BOOK_CONFIG[$book];
    if (preg_match($cfg['pattern'], $title, $m)) {
        return (int) $m[1];
    }
    return null;
}


/**
 * Determine which book a song belongs to based on its title.
 *
 * Checks the title against each book's regex pattern (MP, CP, JP)
 * and returns the first match.
 *
 * @param string $title The full song title from the index page
 *
 * @return string|null Book key ("mp", "cp", or "jp") or null if no match
 */
function detectBook(string $title): ?string
{
    global $BOOK_CONFIG;
    foreach (array_keys($BOOK_CONFIG) as $book) {
        if (extractNumber($title, $book) !== null) {
            return $book;
        }
    }
    return null;
}


/**
 * Remove the book code suffix from a song title.
 *
 * Strips the "(MP0023)" or similar suffix from the end of the title,
 * leaving just the human-readable song name for use in filenames
 * and formatted output.
 *
 * @param string $title The full song title (e.g. "Amazing Grace (MP0023)")
 * @param string $book  Book identifier key ("mp", "cp", or "jp")
 *
 * @return string The cleaned title (e.g. "Amazing Grace")
 */
function cleanTitle(string $title, string $book): string
{
    global $BOOK_CONFIG;
    $cfg = $BOOK_CONFIG[$book];
    // The book pattern uses a capturing group for the number: \(MP(\d+)\)
    // We replace (\d+) with \d+ to make it non-capturing for the full-match removal
    $pat = str_replace('(\\d+)', '\\d+', $cfg['pattern']);
    // Remove the leading/trailing delimiters and flags from the pattern for use in preg_replace
    // The pattern is like /\(MP\d+\)/i — we need just the inner regex
    $innerPat = preg_replace('/^\/|\/[a-z]*$/i', '', $pat);
    $result = preg_replace('/\s*' . $innerPat . '\s*$/i', '', $title);
    return trim($result);
}


/**
 * Remove characters that are invalid in filenames across operating systems.
 *
 * Strips characters that are forbidden in Windows filenames and/or could
 * cause issues on other platforms: \ / * ? : " < > |
 *
 * @param string $name The raw string to sanitize (typically a song title)
 *
 * @return string The sanitized string with invalid characters removed
 */
function sanitizeFilename(string $name): string
{
    return trim(preg_replace('/[\\\\\/\*\?:"<>\|]/', '', $name));
}


/**
 * Convert a string to Title Case, with correct handling of apostrophes.
 *
 * PHP's ucwords() and mb_convert_case() don't handle apostrophes correctly
 * in contractions (e.g. "Don't" becomes "Don'T"). This function uses a
 * regex to match whole words (including contractions like "don't", "it's",
 * "o'er") and capitalises each correctly.
 *
 * The regex pattern includes Unicode curly/smart apostrophes (\u{2019} and
 * \u{2018}) because the HTML entity decoder converts &rsquo; to \u{2019}.
 *
 * @param string $s The input string
 *
 * @return string The Title Cased string
 */
function titleCase(string $s): string
{
    // Match words including those with apostrophes (straight and curly)
    // mb_strtolower first, then capitalize each match
    return preg_replace_callback(
        "/[a-zA-Z]+(['\x{2019}\x{2018}][a-zA-Z]+)?/u",
        function (array $m): string {
            // ucfirst + strtolower handles ASCII; for Unicode we use mb_ functions
            $word = mb_strtolower($m[0], 'UTF-8');
            return mb_strtoupper(mb_substr($word, 0, 1, 'UTF-8'), 'UTF-8')
                 . mb_substr($word, 1, null, 'UTF-8');
        },
        $s
    );
}


/**
 * Construct the base filename for a song (without file extension).
 *
 * Generates a consistent filename from the song's book, number, and title.
 * The title is sanitized and converted to Title Case. The number is
 * zero-padded according to the book's configuration (MP=4 digits, CP/JP=3).
 *
 * @param int    $number The song number (e.g. 23)
 * @param string $book   Book identifier key ("mp", "cp", or "jp")
 * @param string $title  The cleaned song title (book code already removed)
 *
 * @return string The base filename, e.g. "0023 (MP) - Amazing Grace"
 */
function baseFilename(int $number, string $book, string $title): string
{
    global $BOOK_CONFIG;
    $cfg    = $BOOK_CONFIG[$book];
    $padded = str_pad((string) $number, $cfg['pad'], '0', STR_PAD_LEFT);
    $label  = $cfg['label'];
    return "{$padded} ({$label}) - " . titleCase(sanitizeFilename($title));
}


// =========================================================================
// File cache and existence checks — for resumable scraping
// =========================================================================

/**
 * Build a set of existing filenames in a directory for quick lookup.
 *
 * Called once at startup to avoid repeated scandir() calls during
 * the scrape. Returns an array for O(1) membership testing.
 *
 * @param string $outputDir Path to the directory to scan
 *
 * @return array<string, true> Associative array of filename => true, or empty array
 */
function buildFileCache(string $outputDir): array
{
    $cache = [];
    if (is_dir($outputDir)) {
        $files = scandir($outputDir);
        if ($files !== false) {
            foreach ($files as $f) {
                if ($f !== '.' && $f !== '..') {
                    $cache[$f] = true;
                }
            }
        }
    }
    return $cache;
}


/**
 * Check if lyrics for a specific song have already been saved.
 *
 * Uses the cached file listing for O(1) lookup instead of checking
 * the filesystem directly. Matches files by their prefix pattern
 * (number + label) and .txt extension.
 *
 * @param int                  $number    The song number
 * @param string               $book      Book identifier key
 * @param array<string, true>  $fileCache Existing filenames from buildFileCache()
 *
 * @return bool True if a matching .txt file exists in the cache
 */
function alreadySavedLyrics(int $number, string $book, array $fileCache): bool
{
    global $BOOK_CONFIG;
    $cfg    = $BOOK_CONFIG[$book];
    $padded = str_pad((string) $number, $cfg['pad'], '0', STR_PAD_LEFT);
    $label  = $cfg['label'];
    $prefix = "{$padded} ({$label}) -";

    foreach (array_keys($fileCache) as $f) {
        if (str_starts_with($f, $prefix) && str_ends_with($f, '.txt')) {
            return true;
        }
    }
    return false;
}


/**
 * Check if a specific download file (words/music/audio) already exists.
 *
 * Words files use the base name directly (base.rtf), while music and
 * audio files add a type suffix (base_music.pdf, base_audio.mp3).
 *
 * @param string               $base      The base filename (from baseFilename())
 * @param string               $dlType    Download type ("words", "music", or "audio")
 * @param array<string, true>  $fileCache Existing filenames
 *
 * @return bool True if a matching file exists in the cache
 */
function alreadySavedDownload(string $base, string $dlType, array $fileCache): bool
{
    // Words files: "base.ext", other types: "base_type.ext"
    $prefix = ($dlType === 'words') ? "{$base}." : "{$base}_{$dlType}.";

    foreach (array_keys($fileCache) as $f) {
        if (str_starts_with($f, $prefix)) {
            return true;
        }
    }
    return false;
}


// =========================================================================
// Format lyrics — the most complex formatting function in the scraper
// =========================================================================

/**
 * Format a parsed song into a clean plain-text string for saving.
 *
 * This is the most complex formatting function in the scraper because
 * the source HTML has several structural quirks that need handling:
 *
 * 1. STANZA GROUPING: On missionpraise.com, each lyric line is its own <p>
 *    element, and stanza breaks are represented by empty <p> tags. The
 *    parser preserves this as empty strings in the verses list, which
 *    this function uses to group lines into stanzas.
 *
 * 2. CHORUS DETECTION: Chorus lines are rendered in italic (<em>/<i>) on
 *    the site. The parser flags each verse with is_italic=true if it
 *    contains any italic text. This function prepends "Chorus:" before
 *    chorus stanzas.
 *
 * 3. ATTRIBUTION DETECTION: After the lyrics, songs may have author/composer
 *    credits like "Words: Stuart Townend" or "Music: Keith Getty". These
 *    are detected by pattern matching and separated from the lyrics with
 *    extra blank lines. Requires a colon, "by", or separator after the keyword
 *    to prevent false positives.
 *
 * 4. STANDALONE AUTHOR LINES: Some songs have short attribution lines like
 *    "Stuart Townend / Keith Getty" that don't follow the "Words:" pattern.
 *    Detected by heuristics (contains "/" or "&", short, no lyric words).
 *
 * 5. MULTI-LINE VERSES: Some verses contain <br> tags (represented as \n
 *    in the parser output), meaning multiple lines in a single <p>.
 *    These are treated as complete stanzas on their own.
 *
 * @param array  $song Parsed song data with title, verses, copyright, downloads
 * @param string $book Book identifier key ("mp", "cp", or "jp")
 *
 * @return string The formatted plain-text song content
 */
function formatLyrics(array $song, string $book): string
{
    $title = cleanTitle($song['title'], $book);
    $lines = ['"' . $title . '"', ''];  // Start with quoted title and blank line

    // --- Stanza/attribution tracking ---
    $authorLines       = [];     // Accumulated attribution lines
    $foundAttribution  = false;  // Flag: have we started seeing attribution lines?
    $stanzaBuf         = [];     // Lines of the current stanza being built
    $stanzaIsChorus    = false;  // Does the current stanza contain chorus (italic) lines?

    /**
     * Flush the accumulated stanza to the output lines list.
     *
     * If the stanza contains italic lines, prepends "Chorus:" label —
     * UNLESS the first line starts with a verse number.
     */
    $flushStanza = function () use (&$lines, &$stanzaBuf, &$stanzaIsChorus): void {
        if (empty($stanzaBuf)) {
            return;
        }
        if ($stanzaIsChorus) {
            $firstLine = trim($stanzaBuf[0]);
            if (!preg_match('/^\d+\s/', $firstLine)) {
                $lines[] = 'Chorus:';
            }
        }
        $lines[] = implode("\n", $stanzaBuf);
        $lines[] = '';  // Blank line between stanzas
        $stanzaBuf      = [];
        $stanzaIsChorus = false;
    };

    // --- Consecutive empty tracking ---
    // On missionpraise.com, single empty <p> elements appear between EVERY
    // lyric line for CSS spacing, not just at actual stanza boundaries.
    $consecutiveEmpties = 0;

    // Process each verse (paragraph) from the parsed HTML
    foreach ($song['verses'] as [$verseText, $isItalic]) {
        $stripped = trim($verseText);

        // --- Empty verse: count but don't flush yet ---
        if ($stripped === '') {
            $consecutiveEmpties++;
            continue;
        }

        // --- Stanza break decision ---
        if (!empty($stanzaBuf) && $consecutiveEmpties > 0) {
            $shouldBreak = (
                $consecutiveEmpties >= 2                             // structural break
                || ($isItalic !== $stanzaIsChorus)                   // verse<->chorus
                || (bool) preg_match('/^\d+\s/', $stripped)          // verse number
            );
            if ($shouldBreak) {
                $flushStanza();
            }
        }
        $consecutiveEmpties = 0;

        // --- Attribution line detection ---
        // Lines starting with "Words:", "Music:", "Arranged:", etc. are
        // author/composer credits, not lyrics. We require a colon, "by",
        // or "and" after the keyword to prevent false positives.
        $isExplicitAttribution = (bool) preg_match(
            '/^(Words|Music|Arranged|Words and music|Based on|Translated|Paraphrase)\s*[:&\-\/by]/i',
            $stripped
        );
        // Also match bare keywords followed by author names
        if (!$isExplicitAttribution) {
            $isExplicitAttribution = (bool) preg_match(
                '/^(Words and music|Words & music)\b/i',
                $stripped
            );
        }
        $isAttribution = (
            $isExplicitAttribution
            || ($foundAttribution
                && !str_contains($stripped, "\n")
                && !preg_match('/^\d+\s/', $stripped)   // Don't swallow numbered verses
                && mb_strlen($stripped) < 120)           // Don't swallow long lyric lines
        );
        if ($isAttribution) {
            $flushStanza();
            $foundAttribution = true;
            $authorLines[] = $stripped;
            continue;
        }

        // If we see a normal lyric line after attribution, reset the cascade.
        if ($foundAttribution && preg_match('/^\d+\s/', $stripped)) {
            $foundAttribution = false;
        }

        // --- Standalone author lines ---
        // Some attribution lines don't follow the "Words:" pattern but are
        // recognisable as author names by containing "/" or "&" separators.
        if (!str_contains($stripped, "\n")
            && !preg_match('/^\d/', $stripped)
            && (str_contains($stripped, '/') || str_contains($stripped, '&'))
            && mb_strlen($stripped) < 120
            && !preg_match('/\b(the|and|you|your|my|our|lord|god|love|sing|praise)\b/i', $stripped)
        ) {
            $flushStanza();
            $authorLines[] = $stripped;
            continue;
        }

        // --- Multi-line verse ---
        // If the verse text contains "\n" (from <br> tags in the HTML),
        // it's a multi-line verse that should be treated as its own stanza.
        if (str_contains($stripped, "\n")) {
            $flushStanza();
            // Clean up each line within the verse (strip whitespace)
            $cleanedLines = array_map('trim', explode("\n", $stripped));
            $cleaned = implode("\n", $cleanedLines);
            if ($isItalic) {
                $firstLine = trim($cleanedLines[0]);
                if (!preg_match('/^\d+\s/', $firstLine)) {
                    $lines[] = 'Chorus:';
                }
            }
            $lines[] = $cleaned;
            $lines[] = '';  // Blank line after the stanza
        } else {
            // --- Single-line verse ---
            if ($isItalic) {
                $stanzaIsChorus = true;
            }
            $stanzaBuf[] = $stripped;
        }
    }

    // Flush any remaining stanza
    $flushStanza();

    // Remove trailing blank lines from the lyrics section
    while (!empty($lines) && $lines[count($lines) - 1] === '') {
        array_pop($lines);
    }

    // Append author attribution lines (separated from lyrics by double blank line)
    if (!empty($authorLines)) {
        $lines[] = '';   // First blank line
        $lines[] = '';   // Second blank line (visual separation)
        foreach ($authorLines as $al) {
            $lines[] = $al;
        }
    }

    // Append copyright notice if available
    if (!empty($song['copyright'])) {
        $lines[] = '';
        $lines[] = $song['copyright'];
    }

    // --- Post-processing: normalise spaced ellipses ---
    // WordPress's text rendering sometimes converts "..." to ". . ." with
    // spaces between the dots. We normalise these back to standard "..."
    $result = implode("\n", $lines);
    $result = preg_replace('/ ?(?:\. ){2,}\./', '...', $result);

    return $result;
}


// =========================================================================
// Save lyrics & download files
// =========================================================================

/**
 * Format and save a song's lyrics to a plain-text file.
 *
 * Creates the output directory if needed, generates the filename,
 * formats the lyrics, and writes the file.
 *
 * @param array  $song      Parsed song data with title, verses, copyright
 * @param int    $number    The song number (e.g. 23)
 * @param string $book      Book identifier key ("mp", "cp", or "jp")
 * @param string $outputDir Directory to save the file in
 *
 * @return string The base filename (without extension) — for download reuse
 */
function saveLyrics(array $song, int $number, string $book, string $outputDir): string
{
    if (!is_dir($outputDir)) {
        mkdir($outputDir, 0755, true);
    }
    $title    = cleanTitle($song['title'], $book);
    $base     = baseFilename($number, $book, $title);
    $filepath = $outputDir . DIRECTORY_SEPARATOR . $base . '.txt';
    file_put_contents($filepath, formatLyrics($song, $book), LOCK_EX);
    return $base;
}


/**
 * Save a downloaded binary file (words, music, or audio) to disk.
 *
 * Naming convention:
 * - Words (primary):  {base}.rtf       (same name as lyrics, different ext)
 * - Music:            {base}_music.pdf  (suffix distinguishes from words)
 * - Audio:            {base}_audio.mp3  (suffix distinguishes from words)
 *
 * @param string $data        Raw bytes of the downloaded file
 * @param string $base        Base filename (from baseFilename())
 * @param string $dlType      Download type ("words", "music", or "audio")
 * @param string $contentType MIME type from the Content-Type header
 * @param string $dlUrl       The download URL (for extension guessing fallback)
 * @param string $outputDir   Directory to save the file in
 *
 * @return string The complete filename (with extension) that was saved
 */
function saveDownload(string $data, string $base, string $dlType, string $contentType, string $dlUrl, string $outputDir): string
{
    $ext = extForDownload($contentType, $dlUrl, $data);

    // Words files share the same base name as lyrics (just different extension):
    //   "0023 (MP) - Amazing Grace.rtf"
    // Music and audio files add a type suffix:
    //   "0023 (MP) - Amazing Grace_music.pdf"
    //   "0023 (MP) - Amazing Grace_audio.mp3"
    if ($dlType === 'words') {
        $filename = $base . $ext;
    } else {
        $filename = $base . "_{$dlType}" . $ext;
    }

    $filepath = $outputDir . DIRECTORY_SEPARATOR . $filename;
    file_put_contents($filepath, $data, LOCK_EX);
    return $filename;
}


// =========================================================================
// Skip logging and diagnostic HTML dumps
// =========================================================================

/**
 * Record a skipped song in the skipped.log file for later review.
 *
 * Creates a persistent log of songs that couldn't be scraped, with
 * timestamps, identifiers, and reasons. Useful for identifying gaps
 * and diagnosing systematic issues.
 *
 * @param string $outputDir Directory to write the log file in
 * @param string $label     Book label (e.g. "MP")
 * @param string $padded    Zero-padded song number string (e.g. "0023")
 * @param string $title     The song title from the index page
 * @param string $url       The song page URL that was attempted
 * @param string $reason    Human-readable explanation of why it was skipped
 *
 * @return void
 */
function logSkip(string $outputDir, string $label, string $padded, string $title, string $url, string $reason): void
{
    if (!is_dir($outputDir)) {
        mkdir($outputDir, 0755, true);
    }
    $logPath   = $outputDir . DIRECTORY_SEPARATOR . 'skipped.log';
    $timestamp = date('Y-m-d H:i:s');
    $entry     = "[{$timestamp}]  {$label}{$padded}  {$title}  —  {$reason}  —  {$url}\n";
    file_put_contents($logPath, $entry, FILE_APPEND | LOCK_EX);
}


/**
 * Write a diagnostic HTML dump for a skipped song so the raw server
 * response can be inspected to determine why the scrape failed.
 *
 * @param string      $bookDir  Output directory for the book
 * @param string      $label    Book label (e.g. "JP")
 * @param string      $padded   Zero-padded song number
 * @param string      $title    Song title from the index page
 * @param string      $url      The song page URL
 * @param string      $reason   Why the song was skipped
 * @param string|null $htmlText Raw HTML response (may be null for empty responses)
 *
 * @return void
 */
function dumpSkipHtml(string $bookDir, string $label, string $padded, string $title, string $url, string $reason, ?string $htmlText): void
{
    global $DEBUG;

    try {
        if (!is_dir($bookDir)) {
            mkdir($bookDir, 0755, true);
        }
        $diagPath = $bookDir . DIRECTORY_SEPARATOR . "_debug_{$label}{$padded}_skipped.html";
        $htmlLen  = ($htmlText !== null) ? strlen($htmlText) : 0;

        $content  = "<!-- SKIPPED: {$label}{$padded} -->\n";
        $content .= "<!-- Song: {$title} -->\n";
        $content .= "<!-- URL: {$url} -->\n";
        $content .= "<!-- Reason: {$reason} -->\n";
        $content .= "<!-- HTML length: {$htmlLen} -->\n\n";
        if ($htmlText !== null) {
            $content .= $htmlText;
        } else {
            $content .= "<!-- (no HTML received — empty/null response) -->\n";
        }

        file_put_contents($diagPath, $content, LOCK_EX);
        echo " [diag: {$diagPath}]";
    } catch (\Throwable $e) {
        if ($DEBUG) {
            echo " [skip diag failed: " . $e->getMessage() . "]";
        }
    }
}


// =========================================================================
// Process a single song — orchestrates scraping + downloading for one song
// =========================================================================

/**
 * Scrape lyrics and download files for a single song.
 *
 * This is the core function that handles one song from start to finish:
 * 1. Extract the song number from the index title
 * 2. Check if lyrics/downloads already exist (skip if so)
 * 3. Fetch the song detail page
 * 4. Parse the HTML to extract lyrics, copyright, and download links
 * 5. Save the lyrics as a .txt file
 * 6. Download and save words/music/audio files (if enabled)
 *
 * The function handles several edge cases:
 * - Songs with no extractable number (logged and skipped)
 * - Login walls (the session expired mid-scrape)
 * - WAF blocks on individual song pages
 * - Failed title parsing (retried once in case of transient errors)
 * - Missing downloads (re-fetches the page if lyrics exist but files don't)
 *
 * @param \CurlHandle         $ch        cURL handle with active login session
 * @param string               $title     Song title from the index page (includes book code)
 * @param string               $url       URL of the song detail page
 * @param string               $book      Book identifier key ("mp", "cp", or "jp")
 * @param string               $outputDir Base output directory
 * @param bool                 $noFiles   If true, skip downloading words/music/audio files
 * @param float                $delay     Seconds to wait between requests
 * @param array<string, true>  &$fileCache Mutable array of existing filenames (updated when new files saved)
 * @param array                $argv      Command-line arguments (for --song detection)
 *
 * @return string "saved" if lyrics were newly saved,
 *                "skipped" if the song couldn't be scraped,
 *                "exists" if the song was already saved from a previous run
 */
function processSong(\CurlHandle $ch, string $title, string $url, string $book, string $outputDir, bool $noFiles, float $delay, array &$fileCache, array $argv): string
{
    global $BOOK_CONFIG, $DEBUG;

    // Extract the hymn number from the title (e.g. "Amazing Grace (MP0023)" -> 23)
    $number = extractNumber($title, $book);
    $cfg    = $BOOK_CONFIG[$book];

    // Route output into a book-specific subdirectory
    $bookDir = $outputDir . DIRECTORY_SEPARATOR . $cfg['subdir'];

    if ($number === null) {
        logSkip($bookDir, '??', '????', $title, $url, 'no book number found in title');
        return 'skipped';
    }

    $padded = str_pad((string) $number, $cfg['pad'], '0', STR_PAD_LEFT);
    $label  = $cfg['label'];

    // --- Check if lyrics already exist ---
    $lyricsExist = alreadySavedLyrics($number, $book, $fileCache);

    // If lyrics exist and we don't need files, skip entirely (no network request)
    if ($lyricsExist && $noFiles) {
        return 'exists';
    }

    // If lyrics exist, check whether downloads also exist
    if ($lyricsExist) {
        $clean = cleanTitle($title, $book);
        $base  = baseFilename($number, $book, $clean);
        // Look for any non-txt file with the same base name
        $hasAnyDownload = false;
        foreach (array_keys($fileCache) as $f) {
            if (!str_ends_with($f, '.txt') && (str_starts_with($f, "{$base}.") || str_starts_with($f, "{$base}_"))) {
                $hasAnyDownload = true;
                break;
            }
        }
        if ($noFiles || $hasAnyDownload) {
            return 'exists';
        }
        // Lyrics exist but no downloads — need to fetch page for download links
        $titleTrunc = mb_substr($title, 0, 55);
        echo "    {$label}{$padded}  " . str_pad($titleTrunc, 55) . " ";
        echo "[re-fetch] fetching missing downloads... ";
    } else {
        // Neither lyrics nor files exist — full scrape needed
        $titleTrunc = mb_substr($title, 0, 55);
        echo "    {$label}{$padded}  " . str_pad($titleTrunc, 55) . " ";
    }

    // --- Fetch the song detail page ---
    [$htmlText, ] = fetchText($ch, $url);

    // Check for login wall (session may have expired during the scrape)
    if ($htmlText === null || str_contains($htmlText, 'Please login to continue') || str_contains($htmlText ?? '', 'loginform')) {
        $reason = ($htmlText === null) ? 'empty response' : 'login wall';
        echo "FAIL ({$reason})\n";
        logSkip($bookDir, $label, $padded, $title, $url, $reason);
        dumpSkipHtml($bookDir, $label, $padded, $title, $url, $reason, $htmlText);
        usleep((int) ($delay * 1000000));
        return 'skipped';
    }

    // Check for subscription paywall (song not included in user's plan)
    if (str_contains($htmlText, 'not part of your subscription')) {
        echo "FAIL (not in subscription)\n";
        logSkip($bookDir, $label, $padded, $title, $url, 'song not part of subscription');
        dumpSkipHtml($bookDir, $label, $padded, $title, $url, 'not in subscription', $htmlText);
        usleep((int) ($delay * 1000000));
        return 'skipped';
    }

    // Check for WAF block on the song page
    $htmlLower = strtolower($htmlText);
    if (str_contains($htmlLower, 'sucuri') || str_contains($htmlLower, 'access denied')) {
        echo "FAIL (blocked by firewall)\n";
        logSkip($bookDir, $label, $padded, $title, $url, 'blocked by WAF/firewall');
        dumpSkipHtml($bookDir, $label, $padded, $title, $url, 'blocked by WAF/firewall', $htmlText);
        usleep((int) ($delay * 1000000));
        return 'skipped';
    }

    // --- Parse the song page HTML ---
    $sp = parseSong($htmlText);

    // If the parser couldn't find a title, retry once — transient issue
    if ($sp['title'] === '') {
        usleep((int) ($delay * 2 * 1000000));
        [$htmlText2, ] = fetchText($ch, $url);
        if ($htmlText2 !== null && !str_contains($htmlText2, 'loginform')) {
            $sp = parseSong($htmlText2);
            $htmlText = $htmlText2;
        }
    }

    // If still no title from entry-title class, try fallbacks:
    // 1. Extract from the HTML <title> tag (strip " – Mission Praise" suffix)
    if ($sp['title'] === '' && $htmlText !== null) {
        if (preg_match('/<title>([^<]+)<\/title>/i', $htmlText, $titleTagMatch)) {
            $rawTitle = html_entity_decode($titleTagMatch[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            // Strip the site name suffix (e.g. " – Mission Praise" or " - Mission Praise")
            $rawTitle = preg_replace('/\s*[\x{2013}\x{2014}\-]\s*Mission Praise\s*$/u', '', $rawTitle);
            $rawTitle = trim($rawTitle);
            if ($rawTitle !== '') {
                $sp['title'] = $rawTitle;
                echo "(title from <title> tag) ";
            }
        }
    }

    // 2. Final fallback: use the title from the index page
    if ($sp['title'] === '') {
        $fallbackTitle = preg_replace('/\s*\([A-Z]+\d+\)\s*$/', '', $title);
        $fallbackTitle = trim($fallbackTitle);
        if ($fallbackTitle !== '') {
            $sp['title'] = $fallbackTitle;
            echo "(title from index) ";
        }
    }

    // If still no title after all fallbacks, skip this song
    if ($sp['title'] === '') {
        echo "FAIL (no title parsed)\n";
        logSkip($bookDir, $label, $padded, $title, $url, 'parser found no title in page HTML');
        dumpSkipHtml($bookDir, $label, $padded, $title, $url, 'no title parsed', $htmlText);
        usleep((int) ($delay * 1000000));
        return 'skipped';
    }

    // Build the structured song data array
    $song = [
        'title'     => $sp['title'],
        'verses'    => $sp['verses'],
        'copyright' => trim($sp['copyright']),
        'downloads' => $sp['downloads'],
    ];

    // --- Verse count validation ---
    // Count actual text lines (not <p> entries) to detect incomplete parses.
    $totalLines = 0;
    foreach ($sp['verses'] as [$v, ]) {
        $v = trim($v);
        if ($v !== '') {
            $totalLines += substr_count($v, "\n") + 1;
        }
    }

    if ($totalLines === 0) {
        echo "WARN (no lyrics found) ";
        logSkip($bookDir, $label, $padded, $title, $url,
            'parser found title but 0 lyric lines — possible HTML structure change');
    } elseif ($totalLines < 4) {
        echo "WARN ({$totalLines} lines — may be incomplete) ";
    }

    if ($DEBUG && !empty($sp['verses'])) {
        $nonEmptyEntries = 0;
        foreach ($sp['verses'] as [$v, ]) {
            if (trim($v) !== '') {
                $nonEmptyEntries++;
            }
        }
        echo "\n  DEBUG: " . count($sp['verses']) . " raw verse entries, "
            . "{$nonEmptyEntries} non-empty, {$totalLines} text lines\n";
    }

    // --- HTML diagnostic dump for incomplete songs ---
    $isSingleSong = in_array('--song', $argv, true);
    if (($totalLines < 8 || $isSingleSong) && $htmlText !== null) {
        $diagPath = $bookDir . DIRECTORY_SEPARATOR . "_debug_{$label}{$padded}_raw.html";
        try {
            if (!is_dir($bookDir)) {
                mkdir($bookDir, 0755, true);
            }
            // Extract the song-details section via regex as a diagnostic cross-check.
            // Match both <div> and <section> tags.
            $sdMatch = null;
            if (preg_match(
                '/<(?:div|section)[^>]+class=["\'][^"\']*song-details[^"\']*["\'][^>]*>'
                . '([\s\S]*?)'
                . '(?=<[^>]+class=["\'][^"\']*(?:copyright-info|col-sm-4|files))/i',
                $htmlText, $sdRegex
            )) {
                $sdMatch = $sdRegex[0];
            } else {
                // Fallback: simpler regex
                if (preg_match(
                    '/<(?:div|section)[^>]+class=["\'][^"\']*song-details[^"\']*["\'][^>]*>'
                    . '([\s\S]*?)<\/(?:div|section)>/i',
                    $htmlText, $sdRegex
                )) {
                    $sdMatch = $sdRegex[0];
                }
            }

            $diagContent  = "<!-- Song: {$title} -->\n";
            $diagContent .= "<!-- URL: {$url} -->\n";
            $diagContent .= "<!-- Parser found {$totalLines} text lines, "
                          . count($sp['verses']) . " verse entries -->\n\n";
            if ($sdMatch !== null) {
                $diagContent .= "<!-- === song-details content (regex extract) === -->\n";
                $diagContent .= $sdMatch . "\n\n";
                $pCount = preg_match_all('/<p[^>]*>/i', $sdMatch);
                $diagContent .= "<!-- Regex found {$pCount} <p> tags in song-details -->\n\n";
            } else {
                $diagContent .= "<!-- WARNING: regex could NOT find song-details div! -->\n\n";
            }
            $diagContent .= "<!-- === Raw verse entries from parser === -->\n";
            foreach ($sp['verses'] as $idx => [$v, $it]) {
                $vRepr = addslashes(mb_substr($v, 0, 200));
                $itStr = $it ? 'true' : 'false';
                $diagContent .= "<!-- Verse[{$idx}] italic={$itStr}: \"{$vRepr}\" -->\n";
            }
            $diagContent .= "\n<!-- === Full page HTML (" . strlen($htmlText) . " chars) === -->\n";
            $diagContent .= $htmlText;

            file_put_contents($diagPath, $diagContent, LOCK_EX);
            echo "[diag: {$diagPath}] ";
        } catch (\Throwable $e) {
            echo "[diag write failed: " . $e->getMessage() . "] ";
        }
    }

    // --- Save lyrics ---
    if ($lyricsExist) {
        // Lyrics already saved — just re-derive the base name for download files
        $base = baseFilename($number, $book, cleanTitle($sp['title'], $book));
    } elseif ($totalLines === 0) {
        // No actual lyrics content — don't save an empty lyrics file
        echo "WARN (empty — lyrics file not saved) ";
        $base = baseFilename($number, $book, cleanTitle($sp['title'], $book));
        logSkip($bookDir, $label, $padded, $title, $url,
            'no lyrics content on page — only title/attribution');
    } else {
        // Save lyrics to .txt file and add to the file cache
        $base = saveLyrics($song, $number, $book, $bookDir);
        $fileCache[$base . '.txt'] = true;
    }

    // --- Download files (words, music, audio) ---
    $fileResults = [];  // Track download results for the status line
    if (!$noFiles && !empty($song['downloads'])) {
        foreach (['words', 'music', 'audio'] as $dlType) {
            $dlUrl = $song['downloads'][$dlType] ?? null;
            if ($dlUrl === null) {
                continue;  // This download type isn't available for this song
            }

            // Skip if already downloaded
            if (alreadySavedDownload($base, $dlType, $fileCache)) {
                $fileResults[] = strtolower($dlType[0]);  // Lowercase = already existed
                continue;
            }

            // Convert relative URLs to absolute
            if (str_starts_with($dlUrl, '/')) {
                $dlUrl = BASE . $dlUrl;
            }

            // Download the file
            [$data, $ct, $finalUrl] = fetchBinary($ch, $dlUrl);
            if ($data !== null) {
                $fname = saveDownload($data, $base, $dlType, $ct, $finalUrl ?? $dlUrl, $bookDir);
                $fileResults[] = strtoupper($dlType[0]);  // Uppercase = newly downloaded
                $fileCache[$fname] = true;  // Add to cache
            }

            // Brief delay between downloads (30% of normal delay)
            usleep((int) ($delay * 0.3 * 1000000));
        }
    }

    // Print the status line with download indicators:
    // [W,M,A] = newly downloaded words/music/audio (uppercase)
    // [w,m,a] = already existed (lowercase)
    $fileStr = !empty($fileResults) ? ' [' . implode(',', $fileResults) . ']' : '';
    echo "OK{$fileStr}\n";
    usleep((int) ($delay * 1000000));  // Rate limit between songs
    return 'saved';
}


// =========================================================================
// Crawl & scrape — paginated index crawling with per-page song processing
// =========================================================================

/**
 * Crawl the paginated song index and scrape each discovered song.
 *
 * The missionpraise.com song index is paginated (10 songs per page).
 * This function:
 * 1. Fetches each index page sequentially
 * 2. Parses the page to discover song links
 * 3. Filters songs to only the requested books (MP, CP, JP)
 * 4. Scrapes each song on the current page before moving to the next
 *
 * End-of-index detection:
 * - "Page not found" in the response
 * - WordPress serving page 1 content for out-of-range page numbers
 * - No song links found on the page
 * - Empty response
 *
 * @param \CurlHandle $ch         cURL handle with active login session
 * @param array       $books      List of book keys to scrape (e.g. ["mp", "cp", "jp"])
 * @param string      $outputDir  Base output directory
 * @param bool        $noFiles    If true, skip file downloads
 * @param int         $startPage  Index page number to start from (default: 1)
 * @param float       $delay      Seconds between requests
 * @param bool        $force      If true, re-download and overwrite existing files
 * @param int|null    $songNumber If set, only scrape this specific song number
 * @param array       $argv       Command-line arguments (for --song detection)
 *
 * @return array{0: int, 1: int, 2: int} Tuple of (saved, skipped, existed)
 */
function crawlAndScrape(\CurlHandle $ch, array $books, string $outputDir, bool $noFiles, int $startPage, float $delay, bool $force, ?int $songNumber, array $argv): array
{
    global $BOOK_CONFIG, $DEBUG;

    $page = $startPage;

    // Build a combined file cache from all book subdirectories.
    // When --force is set, skip the cache entirely so everything is re-downloaded.
    $fileCache = [];
    if (!$force) {
        foreach ($books as $b) {
            $subdir = $outputDir . DIRECTORY_SEPARATOR . $BOOK_CONFIG[$b]['subdir'];
            $fileCache = array_merge($fileCache, buildFileCache($subdir));
        }
    }

    // Counters for the final summary
    $saved   = 0;
    $skipped = 0;
    $existed = 0;

    if ($songNumber !== null) {
        echo "  Single-song mode: looking for song #{$songNumber}\n\n";
        $force = true;  // Always re-download in single-song mode
    }
    if ($force) {
        echo "  Force mode: will re-download and overwrite existing files.\n\n";
    } elseif (!empty($fileCache)) {
        echo "  Found " . count($fileCache) . " existing files across output folders — will skip duplicates.\n\n";
    }

    // --- Main pagination loop ---
    while (true) {
        // Construct the URL for the current index page
        $url = INDEX_URL . $page . '/';
        [$htmlText, ] = fetchText($ch, $url);

        // Detect end of pagination
        if ($htmlText === null || str_contains($htmlText, 'Page not found')) {
            if ($DEBUG) {
                $reason = ($htmlText === null) ? 'empty response' : 'Page not found detected';
                echo "  DEBUG: Page {$page} — {$reason}\n";
            }
            break;
        }

        // Dump the first page's HTML for debugging (only on startPage)
        if ($page === $startPage) {
            debugDump("Index page {$page} HTML", $htmlText);
        }

        // --- Loop detection ---
        // WordPress has a quirk where out-of-range page numbers silently serve
        // page 1 content. We detect this by parsing the "Showing X-Y of Z" counter.
        $countMatch = null;
        if (preg_match('/(\d+)-(\d+)\s+of\s+(\d+)/', $htmlText, $countMatch)) {
            $lo    = (int) $countMatch[1];
            $hi    = (int) $countMatch[2];
            $total = (int) $countMatch[3];
            $totalPages = (int) ceil($total / 10);

            if ($page === $startPage) {
                echo "  {$total} total songs across ~{$totalPages} pages\n\n";
            }

            // Detect loops: lo > hi (impossible) or past page 1 seeing results from 1
            if ($lo > $hi || ($page > 1 && $lo === 1)) {
                break;
            }
        } else {
            // No counter found — if past page 1, assume end of pagination
            if ($page > 1) {
                break;
            }
        }

        // --- Parse the index page for song links ---
        $pageSongsRaw = parseIndex($htmlText);

        if (empty($pageSongsRaw)) {
            if ($DEBUG) {
                echo "  DEBUG: parseIndex found 0 song links on page {$page}\n";
                // Debug: count <a> links with /songs/ for diagnostics
                preg_match_all('/<a[^>]+href=["\']([^"\']*)["\'][^>]*>/i', $htmlText, $allLinks);
                $songLinks = array_filter($allLinks[1] ?? [], function (string $l): bool {
                    return str_contains($l, '/songs/');
                });
                echo "  DEBUG: Total <a> links: " . count($allLinks[1] ?? []) . ", with /songs/: " . count($songLinks) . "\n";
            }
            break;
        }

        // --- Filter songs to requested books ---
        $pageSongs = [];
        foreach ($pageSongsRaw as [$songTitle, $songUrl]) {
            foreach ($books as $bookKey) {
                if (preg_match($BOOK_CONFIG[$bookKey]['pattern'], $songTitle)) {
                    // If --song was specified, filter to only that song number
                    if ($songNumber !== null) {
                        $num = extractNumber($songTitle, $bookKey);
                        if ($num !== $songNumber) {
                            break;  // Skip — wrong number
                        }
                    }
                    $pageSongs[] = [$songTitle, $songUrl, $bookKey];
                    break;  // A song belongs to exactly one book
                }
            }
        }

        // Print page summary with running totals
        if ($songNumber === null) {
            echo "  Page " . str_pad((string) $page, 4, ' ', STR_PAD_LEFT) . "  —  "
                . count($pageSongs) . " matching songs  "
                . "(saved: {$saved}, existed: {$existed}, skipped: {$skipped})\n";
        }

        // --- Scrape each song on this page ---
        $pageExisted = 0;
        foreach ($pageSongs as [$songTitle, $songUrl, $bookKey]) {
            // Convert relative URLs to absolute
            if (str_starts_with($songUrl, '/')) {
                $songUrl = BASE . $songUrl;
            }

            $result = processSong($ch, $songTitle, $songUrl, $bookKey, $outputDir, $noFiles, $delay, $fileCache, $argv);
            if ($result === 'saved') {
                $saved++;
            } elseif ($result === 'skipped') {
                $skipped++;
            } elseif ($result === 'exists') {
                $existed++;
                $pageExisted++;
            }
        }

        // If --song was specified and we found it, stop immediately
        if ($songNumber !== null && ($saved + $skipped) > 0) {
            break;
        }

        // If every song on this page already existed, print a compact summary
        if ($pageExisted === count($pageSongs) && !empty($pageSongs) && $songNumber === null) {
            echo "           >>  all {$pageExisted} songs on this page already exist\n";
        }

        $page++;
        usleep((int) ($delay * 1000000));  // Rate limit between index pages
    }

    // If --song was specified but never found, warn the user
    if ($songNumber !== null && $saved === 0 && $skipped === 0) {
        echo "\n  WARN: Song #{$songNumber} was not found in the index for books: " . implode(', ', $books) . "\n";
    }

    return [$saved, $skipped, $existed];
}


// =========================================================================
// CLI argument parsing — mimics Python's argparse for PHP CLI scripts
// =========================================================================

/**
 * Parse command-line arguments into an associative array.
 *
 * Supports the same argument style as the Python version:
 *   --username VALUE    Named argument with value
 *   --no-files          Boolean flag (no value)
 *   --debug             Boolean flag
 *   -?                  Alias for --help
 *
 * @param array $argv The raw $argv array from PHP
 *
 * @return array Parsed arguments as key => value pairs
 */
function parseArgs(array $argv): array
{
    // Default values matching the Python version
    $args = [
        'username'   => null,
        'password'   => null,
        'output'     => DEFAULT_OUT,
        'books'      => 'mp,cp,jp',
        'start-page' => 1,
        'delay'      => DEFAULT_DELAY,
        'song'       => null,
        'no-files'   => false,
        'debug'      => false,
        'force'      => false,
    ];

    // Support -? as an alias for --help
    if (in_array('-?', $argv, true) || in_array('--help', $argv, true) || in_array('-h', $argv, true)) {
        printUsage();
        exit(0);
    }

    $i = 1;  // Skip argv[0] (the script name)
    $count = count($argv);
    while ($i < $count) {
        $arg = $argv[$i];

        switch ($arg) {
            case '--username':
                $args['username'] = $argv[++$i] ?? null;
                break;
            case '--password':
                $args['password'] = $argv[++$i] ?? null;
                break;
            case '--output':
                $args['output'] = $argv[++$i] ?? DEFAULT_OUT;
                break;
            case '--books':
                $args['books'] = $argv[++$i] ?? 'mp,cp,jp';
                break;
            case '--start-page':
                $args['start-page'] = (int) ($argv[++$i] ?? 1);
                break;
            case '--delay':
                $args['delay'] = (float) ($argv[++$i] ?? DEFAULT_DELAY);
                break;
            case '--song':
                $args['song'] = (int) ($argv[++$i] ?? 0);
                break;
            case '--no-files':
                $args['no-files'] = true;
                break;
            case '--debug':
                $args['debug'] = true;
                break;
            case '--force':
                $args['force'] = true;
                break;
            default:
                echo "Unknown argument: {$arg}\n";
                echo "Use --help or -? for usage information.\n";
                exit(1);
        }

        $i++;
    }

    return $args;
}


/**
 * Print usage information and examples.
 *
 * Mimics the Python argparse --help output with description, arguments,
 * examples, and notes sections.
 *
 * @return void
 */
function printUsage(): void
{
    $script = basename(__FILE__);

    echo <<<USAGE
Mission Praise scraper — MP, CP, JP + file downloads

Usage:
  php {$script} --username EMAIL --password PASS [options]

Options:
  --username EMAIL    missionpraise.com login email (prompted interactively if omitted)
  --password PASS     missionpraise.com password (prompted with hidden input if omitted)
  --output DIR        output folder path (default: ./hymns)
  --books BOOKS       comma-separated books to scrape: mp, cp, jp (default: mp,cp,jp)
  --start-page N      index page number to start from, for resuming (default: 1)
  --delay SECS        seconds to wait between HTTP requests (default: 1.2)
  --song NUM          scrape only this song number (e.g. --song 584 --books jp)
  --no-files          skip downloading words/music/audio files (lyrics only)
  --debug             dump full HTML responses to terminal for troubleshooting
  --force             force re-download of all songs, even if files already exist
  --help, -?, -h      show this help message

Examples:
  php {$script} --username you@email.com --password secret
                                           Scrape all books (MP, CP, JP)
  php {$script} --username you@email.com --password secret --books mp
                                           Scrape only Mission Praise
  php {$script} --username you@email.com --password secret --books mp,cp
                                           Scrape Mission Praise + Carol Praise
  php {$script} --username you@email.com --password secret --no-files
                                           Scrape lyrics only (skip downloads)
  php {$script} --username you@email.com --password secret --start-page 5
                                           Resume from index page 5
  php {$script} --username you@email.com --password secret --output ~/hymns
                                           Save to a custom output folder
  php {$script} --username you@email.com --password secret --song 584
                                           Scrape only song number 584
  php {$script} --username you@email.com --password secret --debug
                                           Dump HTML responses for debugging

Output:
  Lyrics are saved as plain text in book-specific subdirectories:
    hymns/Mission Praise [MP]/0001 (MP) - Abba Father.txt
    hymns/Carol Praise [CP]/001 (CP) - A Great And Mighty Wonder.txt
    hymns/Junior Praise [JP]/001 (JP) - A Boy Gave To Jesus.txt

  Download files use the same base name with type suffixes:
    0001 (MP) - Abba Father.rtf            (words document)
    0001 (MP) - Abba Father_music.pdf      (sheet music)
    0001 (MP) - Abba Father_audio.mp3      (audio recording)

  The scraper is resumable — existing files are detected and skipped.
  Skipped songs are logged to skipped.log in each book's output folder.

Authentication:
  A valid missionpraise.com subscription is required. Credentials can be
  provided via --username/--password flags, or entered interactively at
  the prompt (password input is hidden). The site may be protected by a
  Sucuri WAF — if blocked, try logging in via a browser first.

Notes:
  - No Composer dependencies — uses only PHP built-in extensions (cURL).
  - The scraper crawls the paginated song index (10 songs per page).
  - Downloads include words (RTF/DOC), music (PDF), and audio (MP3/MIDI).
  - Use --no-files to skip downloads and only scrape lyrics text.

USAGE;
}


/**
 * Prompt for hidden password input on CLI (hides characters while typing).
 *
 * Uses stty on Unix/macOS to disable echo, or falls back to visible input
 * if stty is not available (e.g. Windows without specific extensions).
 *
 * @param string $prompt The prompt text to display
 *
 * @return string The entered password
 */
function promptPassword(string $prompt = 'Password: '): string
{
    // Check if we're on a system that supports stty (Unix/macOS)
    if (str_contains(PHP_OS, 'WIN')) {
        // Windows: no easy way to hide input without extensions
        echo $prompt;
        return trim(fgets(STDIN));
    }

    // Unix/macOS: use stty to disable terminal echo
    echo $prompt;
    system('stty -echo');
    $password = trim(fgets(STDIN));
    system('stty echo');
    echo "\n";  // Print newline since the user's Enter wasn't echoed

    return $password;
}


// =========================================================================
// Main — CLI entry point
// =========================================================================

/**
 * Parse command-line arguments, authenticate, and start the scrape.
 *
 * Handles the full lifecycle:
 * 1. Parse CLI arguments (or prompt for missing credentials)
 * 2. Validate the requested books
 * 3. Create an HTTP session and authenticate with the site
 * 4. Print configuration summary
 * 5. Run the crawl-and-scrape process
 * 6. Print final results
 * 7. Clean up resources (cURL handle, cookie file)
 *
 * @return void
 */
function main(): void
{
    global $argv, $DEBUG, $BOOK_CONFIG;

    // Parse CLI arguments
    $args = parseArgs($argv);

    // Set the global debug flag
    $DEBUG = $args['debug'];

    // Prompt for credentials if not provided via CLI arguments
    if ($args['username'] === null) {
        echo "Mission Praise username/email: ";
        $args['username'] = trim(fgets(STDIN));
    }
    if ($args['password'] === null) {
        $args['password'] = promptPassword('Mission Praise password: ');
    }

    // Parse and validate the books argument (filter out invalid entries)
    $books = [];
    foreach (explode(',', $args['books']) as $b) {
        $b = strtolower(trim($b));
        if ($b !== '' && isset($BOOK_CONFIG[$b])) {
            $books[] = $b;
        }
    }
    if (empty($books)) {
        echo "No valid books specified. Use --books mp,cp,jp\n";
        return;
    }

    // Create the HTTP session (cURL handle + cookie jar)
    [$ch, $cookieFile] = makeSession();

    // Authenticate with the site
    if (!login($ch, $args['username'], $args['password'])) {
        // Login failed — error already printed by login()
        curl_close($ch);
        if (file_exists($cookieFile)) {
            unlink($cookieFile);
        }
        return;
    }

    // Print configuration summary
    echo "\nBooks    : " . implode(', ', array_map('strtoupper', $books)) . "\n";
    echo "Output   : " . realpath($args['output']) ?: $args['output'];
    echo "\n";
    echo "Downloads: " . ($args['no-files'] ? 'disabled' : 'enabled (words, music, audio)') . "\n";
    echo "\n";

    // Run the main crawl-and-scrape process
    [$saved, $skipped, $existed] = crawlAndScrape(
        $ch,
        $books,
        $args['output'],
        $args['no-files'],
        $args['start-page'],
        $args['delay'],
        $args['force'],
        $args['song'],
        $argv
    );

    // Print final summary
    echo "\nDone!  {$saved} saved, {$existed} already existed, {$skipped} skipped.\n";
    echo "Output: " . (realpath($args['output']) ?: $args['output']) . "\n";
    if ($skipped > 0) {
        $logPath = $args['output'] . DIRECTORY_SEPARATOR . 'skipped.log';
        echo "Skipped songs logged to: {$logPath}\n";
    }

    // Clean up resources
    curl_close($ch);
    if (file_exists($cookieFile)) {
        unlink($cookieFile);
    }
}

// Run the main function
main();
