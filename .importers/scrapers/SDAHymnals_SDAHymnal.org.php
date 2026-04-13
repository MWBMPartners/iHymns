#!/usr/bin/env php
<?php
/**
 * SDAHymnals_SDAHymnal.org.php
 * importers/scrapers/SDAHymnals_SDAHymnal.org.php
 *
 * Hymnal Scraper — scrapes both sdahymnal.org (SDAH) and hymnal.xyz (CH).
 * Copyright 2025-2026 MWBM Partners Ltd.
 *
 * Overview:
 *     This scraper fetches hymn lyrics from two Seventh-day Adventist hymnal
 *     websites that share the same underlying codebase (identical HTML structure
 *     and CSS class names). It iterates through hymn numbers sequentially,
 *     parses the HTML to extract the title, section indicators (e.g. "Verse 1",
 *     "Chorus"), and lyrics text, then saves each hymn as a plain-text file.
 *
 *     The scraper is designed to be resumable: it scans the output directory for
 *     existing files on startup and skips hymns that have already been saved.
 *     It also handles rate limiting, server errors, and auto-detects the end
 *     of the hymnal when the site redirects to the homepage.
 *
 * Dependencies:
 *     PHP 8.4+ with the cURL extension (ext-curl). No Composer packages required.
 *     This is intentional so the script can run on any system with PHP CLI.
 *
 * Output format:
 *     Each hymn is saved as a plain-text file with the naming convention:
 *         {number zero-padded to 3 digits} ({LABEL}) - {Title Case Title}.txt
 *     e.g.: "001 (SDAH) - Praise To The Lord.txt"
 *
 *     Files are organised into book-specific subdirectories:
 *         hymns/Seventh-day Adventist Hymnal [SDAH]/
 *         hymns/The Church Hymnal [CH]/
 *
 * Usage:
 *     php SDAHymnals_SDAHymnal.org.php                         # Scrape both sites from hymn 1
 *     php SDAHymnals_SDAHymnal.org.php --site sdah             # Only sdahymnal.org
 *     php SDAHymnals_SDAHymnal.org.php --site ch               # Only hymnal.xyz
 *     php SDAHymnals_SDAHymnal.org.php --start 50              # Resume from hymn 50
 *     php SDAHymnals_SDAHymnal.org.php --start 1 --end 100     # Specific range
 *     php SDAHymnals_SDAHymnal.org.php --output ~/Desktop/hymns
 *     php SDAHymnals_SDAHymnal.org.php --force                 # Re-download all
 *     php SDAHymnals_SDAHymnal.org.php --debug                 # Dump HTML for debugging
 *
 * @package iLyricsDB
 * @author  MWBM Partners Ltd
 * @license Proprietary
 */

// ===========================================================================
// Ensure this script is run from the command line, not via a web server.
// Running a long-lived scraper via HTTP would time out and is not intended.
// ===========================================================================
if (php_sapi_name() !== 'cli') {
    echo "This script must be run from the command line.\n";
    exit(1);
}

// Force output to be flushed immediately so progress messages appear in
// real-time. Without this, PHP buffers CLI output and the user wouldn't
// see progress updates until the buffer fills.
ob_implicit_flush(true);


// ===========================================================================
// Site configuration — both sites share the same HTML structure
// ===========================================================================
// Both sdahymnal.org and hymnal.xyz are built on the same web platform,
// so they use identical CSS class names and page layouts. This allows us
// to use a single parsing function for both sites. Each site entry defines:
//   - base_url:  The hymn page URL template (hymn number passed as ?no=N)
//   - home_url:  The site homepage (used to detect end-of-hymnal redirects)
//   - label:     Short identifier used in output filenames, e.g. "SDAH"
//   - subdir:    Human-readable subdirectory name for organised output
//   - lang:      ISO 639-1 language code for the songbook's language (e.g. "en")
const SITES = [
    'sdah' => [
        'base_url' => 'https://www.sdahymnal.org/Hymn',
        'home_url' => 'https://www.sdahymnal.org',
        'label'    => 'SDAH',
        'subdir'   => 'Seventh-day Adventist Hymnal [SDAH]',
        'lang'     => 'en',   // ISO 639-1: English
    ],
    'ch' => [
        'base_url' => 'https://www.hymnal.xyz/Hymn',
        'home_url' => 'https://www.hymnal.xyz',
        'label'    => 'CH',
        'subdir'   => 'The Church Hymnal [CH]',
        'lang'     => 'en',   // ISO 639-1: English
    ],
];

// Default output directory (relative to where the script is run from)
const DEFAULT_OUTPUT_DIR = './hymns';

// Delay in seconds between HTTP requests to be respectful to the server
// and avoid triggering rate limits. 1.0s is a reasonable balance between
// speed and politeness.
const DEFAULT_DELAY = 1.0;


// ===========================================================================
// Global debug flag — set to true via --debug CLI argument to dump HTML
// ===========================================================================
$DEBUG = false;


// ===========================================================================
// Helper functions
// ===========================================================================

/**
 * Print a labelled debug dump of HTML/text content (only when $DEBUG is true).
 *
 * When debugging is enabled, this prints a truncated preview of the given
 * text with a clear header and footer for easy identification in the output.
 * Useful for inspecting raw HTML responses from the server.
 *
 * @param string $label     A descriptive label for this dump (e.g. "Hymn 42 HTML")
 * @param string $text      The text content to dump
 * @param int    $maxChars  Maximum number of characters to display (default 3000)
 *
 * @return void
 */
function debugDump(string $label, string $text, int $maxChars = 3000): void
{
    global $DEBUG;

    if (!$DEBUG) {
        return;
    }

    $preview = mb_substr($text, 0, $maxChars);
    echo "\n--- DEBUG: {$label} ---\n";
    echo $preview;
    if (mb_strlen($text) > $maxChars) {
        echo "\n... (truncated, " . mb_strlen($text) . " total chars)";
    }
    echo "\n--- END DEBUG ---\n\n";
}


/**
 * Convert a string to Title Case, with correct handling of apostrophes.
 *
 * PHP's ucwords() and mb_convert_case(MB_CASE_TITLE) treat apostrophes as
 * word boundaries, producing incorrect results like "Don'T" instead of
 * "Don't". This function uses a regex to match whole words (including
 * apostrophe contractions like "don't", "it's", "o'er") and capitalises
 * each word correctly.
 *
 * The regex matches:
 *     [a-zA-Z]+              One or more letters (the main word)
 *     (['\x{2019}\x{2018}]   Optionally an apostrophe (ASCII ', right ' or left ')
 *      [a-zA-Z]+)?           followed by more letters (contraction suffix)
 *
 * We include Unicode curly/smart apostrophes (\u2019 RIGHT SINGLE QUOTATION
 * MARK and \u2018 LEFT SINGLE QUOTATION MARK) because HTML entities like
 * &rsquo; decode to \u2019. Without this, "Eagle\u2019s" would be split into
 * separate words, producing "Eagle'S" instead of "Eagle's".
 *
 * ucfirst(strtolower(...)) is used on each match, which uppercases the first
 * char and lowercases the rest — perfect for Title Case.
 *
 * @param string $s The input string to convert
 *
 * @return string The Title Cased string
 *
 * Examples:
 *     "AMAZING GRACE"      => "Amazing Grace"
 *     "don't let me down"  => "Don't Let Me Down"
 *     "o'er the hills"     => "O'er The Hills"
 *     "EAGLE\u2019S WINGS" => "Eagle\u2019s Wings"
 */
function titleCase(string $s): string
{
    return preg_replace_callback(
        "/[a-zA-Z]+(['\x{2019}\x{2018}][a-zA-Z]+)?/u",
        function (array $m): string {
            // ucfirst(strtolower()) capitalises the first letter and
            // lowercases the rest, matching Python's str.capitalize()
            return ucfirst(strtolower($m[0]));
        },
        $s
    );
}


/**
 * Remove characters that are invalid in filenames across operating systems.
 *
 * Strips the following characters which are forbidden in Windows filenames
 * and/or could cause issues on other platforms:
 *     \ / * ? : " < > |
 *
 * @param string $name The raw string to sanitize (typically a hymn title)
 *
 * @return string The sanitized string with invalid characters removed and
 *                leading/trailing whitespace stripped
 */
function sanitizeFilename(string $name): string
{
    return trim(preg_replace('/[\\\\\\/*?:"<>|]/', '', $name));
}


// ===========================================================================
// HTML Parsing — regex-based extraction of hymn data
// ===========================================================================
// Both sdahymnal.org and hymnal.xyz share the same underlying codebase, so
// they use identical CSS class names for their hymn page structure. We parse
// using regex to extract:
//
// 1. TITLE: Found inside a <strong> tag, nested within an <h3> with class
//    "wedding-heading", which is itself inside a <div> with class
//    "block-heading-four". We use a nested regex pattern to navigate
//    this hierarchy.
//
// 2. SECTION INDICATORS: Found in <div class="block-heading-three">.
//    These are labels like "Verse 1", "Chorus", "Verse 2", etc.
//
// 3. LYRICS TEXT: Found in <div class="block-heading-five">.
//    Line breaks within lyrics are represented by <br> tags, which we
//    convert to newline characters.
//
// The parser pairs each indicator with its following lyrics block,
// producing a list of [indicator, lyrics] arrays.
// ===========================================================================

/**
 * Parse hymn HTML to extract the title and lyrics sections.
 *
 * Uses regex patterns to navigate the specific CSS class structure used by
 * sdahymnal.org and hymnal.xyz. The HTML structure being parsed:
 *
 *     <div class="block-heading-four">        <- title container
 *         <h3 class="wedding-heading">        <- title wrapper
 *             <strong>Hymn Title Here</strong> <- actual title text
 *         </h3>
 *     </div>
 *     <div class="block-heading-three">       <- section indicator
 *         Verse 1                              <- e.g. "Verse 1", "Chorus"
 *     </div>
 *     <div class="block-heading-five">        <- lyrics block
 *         First line of lyrics<br>             <- <br> = line break
 *         Second line of lyrics<br>
 *     </div>
 *
 * @param string $html The full HTML source of the hymn page
 *
 * @return array{title: string, sections: list<array{0: string, 1: string}>}
 *               An associative array with:
 *               - 'title': The extracted hymn title (empty string if not found)
 *               - 'sections': Array of [indicator, lyrics] pairs where:
 *                   - indicator is a string like "Verse 1" or "" if none
 *                   - lyrics is the verse text with \n for line breaks
 */
function parseHymnHtml(string $html): array
{
    $title    = '';
    $sections = [];

    // --- TITLE EXTRACTION ---
    // Navigate the nested structure: div.block-heading-four > *.wedding-heading > strong
    // Using a single regex with the 's' (DOTALL) flag to match across newlines.
    // The regex captures the innermost <strong> content within the expected hierarchy.
    if (preg_match(
        '/<div[^>]*class="[^"]*\bblock-heading-four\b[^"]*"[^>]*>.*?'
        . '<[^>]*class="[^"]*\bwedding-heading\b[^"]*"[^>]*>.*?'
        . '<strong[^>]*>(.*?)<\/strong>/si',
        $html,
        $titleMatch
    )) {
        // Decode HTML entities (e.g. &amp; => &, &rsquo; => ', &#8217; => ')
        // strip_tags removes any inline formatting tags within the title
        $title = trim(strip_tags(html_entity_decode($titleMatch[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    // --- SECTION EXTRACTION ---
    // We need to find all block-heading-three (indicators) and block-heading-five
    // (lyrics) divs in document order, then pair them up. We use a single regex
    // that matches both types, with a capture group to identify which type was matched.
    //
    // Strategy: find all occurrences of either class in order, extract their content,
    // then pair indicators with the lyrics blocks that follow them.

    // Match all block-heading-three and block-heading-five divs.
    // We use a regex that captures the class name and the inner content.
    // The inner content extraction handles nested divs by using a non-greedy match
    // up to the closing </div>, but since these content divs typically don't contain
    // nested divs, this approach works reliably.
    $pattern = '/<div[^>]*class="[^"]*\b(block-heading-three|block-heading-five)\b[^"]*"[^>]*>(.*?)<\/div>/si';

    if (preg_match_all($pattern, $html, $matches, PREG_SET_ORDER)) {
        $currentIndicator = '';

        foreach ($matches as $match) {
            $blockType    = $match[1];  // "block-heading-three" or "block-heading-five"
            $innerContent = $match[2];  // The raw HTML content inside the div

            if ($blockType === 'block-heading-three') {
                // This is a section indicator (e.g. "Verse 1", "Chorus")
                // Strip HTML tags and decode entities to get clean text
                $currentIndicator = trim(strip_tags(html_entity_decode($innerContent, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            } else {
                // This is a lyrics block (block-heading-five)
                // Convert <br> tags to newlines before stripping other HTML tags
                $lyricsText = preg_replace('/<br\s*\/?>/i', "\n", $innerContent);

                // Decode HTML entities to get proper Unicode characters
                // (e.g. &rsquo; => right single quotation mark)
                $lyricsText = html_entity_decode($lyricsText, ENT_QUOTES | ENT_HTML5, 'UTF-8');

                // Strip any remaining HTML tags (e.g. <em>, <span>, etc.)
                $lyricsText = strip_tags($lyricsText);

                // Collapse runs of 3+ newlines down to 2 (clean up excessive whitespace
                // that can occur from empty <br> tags in the source HTML)
                $lyricsText = preg_replace("/\n{3,}/", "\n\n", $lyricsText);
                $lyricsText = trim($lyricsText);

                // Pair this lyrics block with its indicator and add to sections list
                $sections[] = [$currentIndicator, $lyricsText];

                // Reset indicator for the next verse (some verses may not have one)
                $currentIndicator = '';
            }
        }
    }

    return ['title' => $title, 'sections' => $sections];
}


// ===========================================================================
// Fetch & parse — HTTP request handling and hymn data extraction
// ===========================================================================

/**
 * Fetch a single hymn page from the website using cURL and parse it.
 *
 * Makes an HTTP GET request to the hymn URL, handles various error conditions
 * (server errors, rate limiting, redirects), and returns parsed hymn data.
 *
 * The function includes several resilience features:
 * - Retries up to 3 times on HTTP 500 errors (with 3-second delays)
 * - Detects rate-limiting pages and pauses 60 seconds before retrying
 * - Detects end-of-hymnal by checking if the site redirects to the homepage
 * - Falls back gracefully on encoding issues via mb_convert_encoding
 *
 * @param int    $number  The hymn number to fetch (e.g. 1, 42, 695)
 * @param string $baseUrl The site's hymn page base URL (e.g. "https://www.sdahymnal.org/Hymn")
 * @param string $homeUrl The site's homepage URL (used to detect end-of-hymnal redirects)
 *
 * @return array|string|null
 *     - array:  ['number' => int, 'title' => str, 'sections' => [...]] on success
 *     - 'SKIP': If the hymn should be skipped (server error, no title found)
 *     - null:   If scraping should stop entirely (reached end of hymnal or
 *               persistent rate limiting)
 */
function fetchHymn(int $number, string $baseUrl, string $homeUrl): array|string|null
{
    // Construct the hymn page URL using the query parameter format used by both sites
    $url = $baseUrl . '?no=' . $number;

    $raw = null;  // Will hold the raw response body if the request succeeds

    // Retry loop: attempt up to 3 times on HTTP 500 errors
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        // Initialise a cURL handle for this request
        $ch = curl_init();

        // Set cURL options for a standard GET request
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,          // Return response as string instead of outputting
            CURLOPT_FOLLOWLOCATION => true,           // Follow HTTP redirects automatically
            CURLOPT_MAXREDIRS      => 5,              // Maximum number of redirects to follow
            CURLOPT_TIMEOUT        => 15,             // Total request timeout in seconds
            CURLOPT_CONNECTTIMEOUT => 10,             // Connection timeout in seconds
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; HymnScraper/2.0; personal use)',
            CURLOPT_ENCODING       => '',             // Accept all encodings (gzip, deflate, etc.)
            CURLOPT_SSL_VERIFYPEER => true,           // Verify SSL certificates
        ]);

        // Execute the request
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        // Get the effective (final) URL after any redirects — used to detect
        // end-of-hymnal redirects where the site sends us back to the homepage
        $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);

        $curlError = curl_error($ch);
        curl_close($ch);

        // Check for cURL-level errors (network failures, DNS resolution, timeouts)
        if ($response === false) {
            echo "  error: {$curlError}\n";
            return 'SKIP';
        }

        // Check if the server redirected us to the homepage — this means
        // the requested hymn number doesn't exist (we've gone past the
        // end of the hymnal). We only check for number > 1 because
        // hymn 1 should always exist.
        $cleanEffective = rtrim($effectiveUrl, '/');
        if (strpos($cleanEffective, 'Hymn') === false && $number > 1) {
            echo "\n  Hymn {$number}: redirected to home — reached end.\n";
            return null;  // Signal to stop scraping entirely
        }

        // Handle HTTP status codes
        if ($httpCode === 200) {
            // Success — store the response and exit the retry loop
            $raw = $response;
            break;
        } elseif ($httpCode === 500) {
            // Server error — may be transient, so retry with backoff
            if ($attempt < 3) {
                echo "  Hymn {$number}: server error (500), retrying ({$attempt}/3)... ";
                sleep(3);  // Wait before retrying
            } else {
                // All 3 attempts failed — skip this hymn
                echo "  server error (500) after 3 attempts.\n";
                return 'SKIP';
            }
        } else {
            // Other HTTP errors (404, 403, etc.) — don't retry, just skip
            echo "  HTTP {$httpCode}.\n";
            return 'SKIP';
        }
    }

    // If raw is still null, all retry attempts were exhausted without success
    if ($raw === null) {
        return 'SKIP';
    }

    // Ensure the response is valid UTF-8. If it contains invalid sequences
    // (e.g. latin-1 encoded bytes), convert them. mb_detect_encoding with
    // strict mode helps identify the actual encoding.
    if (!mb_check_encoding($raw, 'UTF-8')) {
        $raw = mb_convert_encoding($raw, 'UTF-8', 'ISO-8859-1');
    }

    // Dump raw HTML when debug mode is active
    debugDump("Hymn {$number} raw HTML", $raw);

    // Parse the HTML to extract hymn data
    $parsed = parseHymnHtml($raw);

    // --- Rate limit detection ---
    // Both sites show a "reached limit for today" message when you've made
    // too many requests. If detected, pause for 60 seconds and try once more.
    $htmlLower = strtolower($raw);
    if (strpos($htmlLower, 'reached limit for today') !== false || strpos($htmlLower, 'we are sorry') !== false) {
        echo "  rate limit hit — pausing 60s...\n";
        sleep(60);

        // One retry after the cooldown period
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; HymnScraper/2.0; personal use)',
            CURLOPT_ENCODING       => '',
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response2 = curl_exec($ch);
        curl_close($ch);

        if ($response2 === false) {
            return null;  // Network error during retry — stop scraping
        }

        // Check if we're still rate limited after waiting
        if (strpos(strtolower($response2), 'reached limit for today') !== false) {
            echo "  Still rate limited — stopping. Try again tomorrow.\n";
            return null;  // Stop entirely — no point continuing today
        }

        // Rate limit cleared — re-parse with the fresh response
        if (!mb_check_encoding($response2, 'UTF-8')) {
            $response2 = mb_convert_encoding($response2, 'UTF-8', 'ISO-8859-1');
        }
        $parsed = parseHymnHtml($response2);
    }

    // Validate that the parser found a title (indicates a valid hymn page)
    if (empty($parsed['title'])) {
        echo "  no title found.\n";
        return 'SKIP';
    }

    // Return the structured hymn data
    return [
        'number'   => $number,
        'title'    => $parsed['title'],
        'sections' => $parsed['sections'],
    ];
}


// ===========================================================================
// Format & save — text formatting and file output
// ===========================================================================

/**
 * Format a parsed hymn array into a clean plain-text string for saving.
 *
 * The output format is:
 *     "Hymn Title"
 *                                 <- blank line after title
 *     Verse 1                     <- indicator (if present)
 *     First line of lyrics        <- lyrics text
 *     Second line of lyrics
 *                                 <- blank line between sections
 *     Chorus                      <- next indicator
 *     Chorus lyrics here
 *     ...
 *
 * @param array $hymn Associative array with keys:
 *                    - 'title'    (string): The hymn title
 *                    - 'sections' (array):  List of [indicator, lyrics] pairs
 *
 * @return string The formatted plain-text hymn content
 */
function formatHymn(array $hymn): string
{
    // Start with the quoted title and a blank line
    $lines = ['"' . $hymn['title'] . '"', ''];

    // Append each section (indicator + lyrics) with blank line separators
    foreach ($hymn['sections'] as $section) {
        $indicator = $section[0];
        $lyrics    = $section[1];

        if (!empty($indicator)) {
            $lines[] = $indicator;   // e.g. "Verse 1", "Chorus"
        }
        if (!empty($lyrics)) {
            $lines[] = $lyrics;      // The verse/chorus text
        }
        $lines[] = '';               // Blank line between sections
    }

    // Remove trailing blank lines (cleaner file ending)
    while (!empty($lines) && $lines[array_key_last($lines)] === '') {
        array_pop($lines);
    }

    return implode("\n", $lines);
}


/**
 * Scan the output directory to find hymn numbers that have already been saved.
 *
 * This enables the scraper to resume from where it left off without
 * re-downloading hymns. It looks for files matching the naming pattern
 * "{number} ({label}) - {title}.txt" and extracts the hymn number from
 * each matching filename.
 *
 * @param string $label     The book label to filter by (e.g. "SDAH", "CH")
 * @param string $outputDir Path to the directory to scan for existing files
 *
 * @return array<int, bool> An associative array mapping hymn numbers (int) to true.
 *                          Empty array if the directory doesn't exist or has no matching files.
 *
 * Example:
 *     If outputDir contains:
 *         "001 (SDAH) - Praise To The Lord.txt"
 *         "042 (SDAH) - A Mighty Fortress.txt"
 *     Returns: [1 => true, 42 => true]
 */
function buildExistingSet(string $label, string $outputDir): array
{
    $existing = [];

    if (!is_dir($outputDir)) {
        return $existing;
    }

    // Build the prefix tag to filter files belonging to this specific book
    $prefixTag = "({$label}) -";

    // Scan the directory for matching files
    $files = scandir($outputDir);
    if ($files === false) {
        return $existing;
    }

    foreach ($files as $fname) {
        // Match files that contain the book label and have .txt extension
        if (str_contains($fname, $prefixTag) && str_ends_with($fname, '.txt')) {
            // Extract the hymn number from the start of the filename
            // (the zero-padded number before the first space)
            $parts  = explode(' ', $fname, 2);
            $numStr = $parts[0];
            if (ctype_digit($numStr)) {
                $existing[(int) $numStr] = true;
            }
        }
    }

    return $existing;
}


/**
 * Save a parsed hymn to a plain-text file in the output directory.
 *
 * Creates the output directory if it doesn't exist, formats the hymn
 * content, and writes it to a file with the standard naming convention:
 *     {number zero-padded to 3} ({label}) - {Title Case Title}.txt
 *
 * @param array  $hymn      Associative array with 'number', 'title', 'sections'
 * @param string $label     Book label for the filename (e.g. "SDAH", "CH")
 * @param string $outputDir Directory path where the file should be saved
 *
 * @return string The full file path of the saved file
 */
function saveHymn(array $hymn, string $label, string $outputDir): string
{
    // Ensure the output directory exists (creates parent dirs too if needed)
    if (!is_dir($outputDir)) {
        mkdir($outputDir, 0755, true);
    }

    // Zero-pad the hymn number to 3 digits for consistent sorting
    // (e.g. 1 => "001", 42 => "042", 695 => "695")
    $padded = str_pad((string) $hymn['number'], 3, '0', STR_PAD_LEFT);

    // Build the filename: sanitize the title to remove invalid chars,
    // then convert to Title Case for consistent, readable filenames
    $safeTitle = titleCase(sanitizeFilename($hymn['title']));
    $filename  = "{$padded} ({$label}) - {$safeTitle}.txt";
    $filepath  = $outputDir . DIRECTORY_SEPARATOR . $filename;

    // Write the formatted hymn text to the file
    file_put_contents($filepath, formatHymn($hymn), LOCK_EX);

    return $filepath;
}


/**
 * Record a skipped hymn entry in the skipped.log file for later review.
 *
 * This creates a persistent log of hymns that couldn't be scraped,
 * along with the reason and timestamp. Useful for identifying gaps
 * in the collection and diagnosing systematic issues.
 *
 * @param int    $number  The hymn number that was skipped
 * @param string $label   Book label (e.g. "SDAH", "CH")
 * @param string $reason  Human-readable explanation of why it was skipped
 * @param string $bookDir Directory path where the log file should be written
 *
 * @return void
 *
 * Log format (one line per skipped hymn):
 *     [2026-03-13 14:30:00]  SDAH042  --  fetch failed or no title found
 */
function logSkip(int $number, string $label, string $reason, string $bookDir): void
{
    // Ensure the directory exists (in case this is the first file we're writing)
    if (!is_dir($bookDir)) {
        mkdir($bookDir, 0755, true);
    }

    $logPath   = $bookDir . DIRECTORY_SEPARATOR . 'skipped.log';
    $timestamp = date('Y-m-d H:i:s');
    $padded    = str_pad((string) $number, 3, '0', STR_PAD_LEFT);

    // Append the skip entry to the log file
    file_put_contents(
        $logPath,
        "[{$timestamp}]  {$label}{$padded}  —  {$reason}\n",
        FILE_APPEND | LOCK_EX
    );
}


// ===========================================================================
// Per-site scrape loop — the main orchestration logic for one hymnal site
// ===========================================================================

/**
 * Scrape all hymns from a single site (SDAH or CH).
 *
 * This is the main loop that iterates through hymn numbers, fetches each
 * one, and saves it. It includes several features for robust operation:
 *
 * - RESUMABILITY: Scans the output directory for existing files and skips
 *   hymns that have already been saved (via buildExistingSet).
 *
 * - AUTO-DETECTION OF END: When the site redirects a hymn request to the
 *   homepage (fetchHymn returns null), scraping stops — the hymnal has
 *   no more hymns beyond this point.
 *
 * - CONSECUTIVE SKIP LIMIT: If 10 hymns in a row fail (MAX_CONSEC = 10),
 *   we assume we've gone past the end of the hymnal and stop. This handles
 *   the case where the site returns errors rather than redirects for
 *   non-existent hymns.
 *
 * - RATE LIMITING: Pauses for $delay seconds between each request.
 *
 * @param string    $siteKey   Site identifier key from the SITES array ("sdah" or "ch")
 * @param int       $start     First hymn number to scrape (inclusive)
 * @param int|null  $end       Last hymn number to scrape (inclusive), or null for auto-detect
 * @param string    $outputDir Base output directory (a book-specific subdir will be created)
 * @param float     $delay     Seconds to wait between HTTP requests
 * @param bool      $force     If true, re-download and overwrite existing files
 *
 * @return int Number of hymns successfully saved in this run
 */
function scrapeSite(string $siteKey, int $start, ?int $end, string $outputDir, float $delay, bool $force = false): int
{
    // Look up the site configuration for URLs, label, and subdirectory name
    $site    = SITES[$siteKey];
    $label   = $site['label'];
    $baseUrl = $site['base_url'];
    $homeUrl = $site['home_url'];

    // Route output into a book-specific subdirectory within the base output dir
    // e.g. "./hymns/Seventh-day Adventist Hymnal [SDAH]/"
    $bookDir = $outputDir . DIRECTORY_SEPARATOR . $site['subdir'];

    // Print a banner with configuration details for this scrape run
    $separator = str_repeat('=', 50);
    echo "\n{$separator}\n";
    echo "  Scraping: {$baseUrl}  [{$label}]\n";
    echo "  Output  : " . realpath($outputDir) . DIRECTORY_SEPARATOR . $site['subdir'] . "\n";
    echo "  Range   : {$start} to " . ($end !== null ? $end : 'auto-detect') . "\n";
    echo "{$separator}\n\n";

    // Scan the output directory for already-saved hymns to enable resumability.
    // When --force is set, we skip this check and re-download everything.
    $existing = $force ? [] : buildExistingSet($label, $bookDir);
    if ($force) {
        echo "  Force mode: will re-download and overwrite existing files.\n\n";
    } elseif (!empty($existing)) {
        echo "  Found " . count($existing) . " existing {$label} hymns — will skip.\n\n";
    }

    // Counters for the final summary
    $saved      = 0;     // Successfully saved hymns
    $skipped    = 0;     // Hymns that were skipped due to errors
    $number     = $start; // Current hymn number being processed

    // Consecutive skip detection: if we encounter MAX_CONSEC failures in a row,
    // we assume we've gone past the end of the hymnal. This is a safety net
    // for sites that don't redirect to the homepage for non-existent hymns.
    $maxConsec  = 10;
    $consecSkip = 0;

    while (true) {
        // Check if we've reached the user-specified end hymn
        if ($end !== null && $number > $end) {
            echo "\nReached end hymn ({$end}). Done.\n";
            break;
        }

        // Skip hymns that are already saved (detected during initial scan)
        if (isset($existing[$number])) {
            echo "  Hymn " . str_pad((string) $number, 4, ' ', STR_PAD_LEFT) . ": >>  already exists, skipping.\n";
            $number++;
            continue;  // No delay needed — no network request was made
        }

        // Fetch and parse the hymn page
        echo "  Hymn " . str_pad((string) $number, 4, ' ', STR_PAD_LEFT) . ": fetching... ";

        $hymn = fetchHymn($number, $baseUrl, $homeUrl);

        if ($hymn === null) {
            // The site redirected to the homepage — we've reached the end of
            // the hymnal. This is the normal termination condition.
            echo "\n";
            break;
        }

        if ($hymn === 'SKIP') {
            // This hymn couldn't be scraped — log it and continue
            echo "X  skipped.\n";
            logSkip($number, $label, 'fetch failed or no title found', $bookDir);
            $skipped++;
            $consecSkip++;

            // Safety net: too many consecutive failures suggests we're past
            // the end rather than hitting intermittent errors
            if ($consecSkip >= $maxConsec) {
                echo "  {$maxConsec} consecutive errors — assuming end of hymnal.\n";
                break;
            }

            $number++;
            usleep((int) ($delay * 1_000_000));  // Rate limit even on failures
            continue;
        }

        // Success — reset the consecutive skip counter and save the hymn
        $consecSkip = 0;
        $path       = saveHymn($hymn, $label, $bookDir);
        $saved++;
        echo "OK  " . basename($path) . "\n";

        $number++;
        usleep((int) ($delay * 1_000_000));  // Rate limit between successful requests
    }

    // Print a summary for this site
    echo "\n{$label}: {$saved} hymns saved, {$skipped} skipped.\n";
    return $saved;
}


// ===========================================================================
// CLI argument parsing
// ===========================================================================

/**
 * Parse command-line arguments into a structured options array.
 *
 * Supports the following arguments:
 *     --site {sdah|ch|both}  Which site to scrape (default: both)
 *     --start {N}            First hymn number (default: 1)
 *     --end {N}              Last hymn number (default: auto-detect)
 *     --output {path}        Output folder (default: ./hymns)
 *     --delay {N}            Seconds between requests (default: 1.0)
 *     --force                Re-download existing files
 *     --debug                Dump HTML responses for debugging
 *     --help / -h / -?       Show usage information
 *
 * @param array $argv The raw CLI arguments array (global $argv)
 *
 * @return array Associative array of parsed options with keys:
 *               site, start, end, output, delay, force, debug
 */
function parseArgs(array $argv): array
{
    // Default option values
    $options = [
        'site'   => 'both',
        'start'  => 1,
        'end'    => null,
        'output' => DEFAULT_OUTPUT_DIR,
        'delay'  => DEFAULT_DELAY,
        'force'  => false,
        'debug'  => false,
    ];

    // Check for help flags first — display usage and exit
    if (in_array('--help', $argv, true) || in_array('-h', $argv, true) || in_array('-?', $argv, true)) {
        printUsage();
        exit(0);
    }

    // Parse arguments by iterating through the argv array.
    // Skip $argv[0] which is the script name itself.
    $i = 1;
    while ($i < count($argv)) {
        $arg = $argv[$i];

        switch ($arg) {
            case '--site':
                $i++;
                if ($i >= count($argv)) {
                    fwrite(STDERR, "Error: --site requires a value (sdah, ch, or both)\n");
                    exit(1);
                }
                $value = strtolower($argv[$i]);
                if (!in_array($value, ['sdah', 'ch', 'both'], true)) {
                    fwrite(STDERR, "Error: --site must be one of: sdah, ch, both\n");
                    exit(1);
                }
                $options['site'] = $value;
                break;

            case '--start':
                $i++;
                if ($i >= count($argv) || !ctype_digit($argv[$i])) {
                    fwrite(STDERR, "Error: --start requires a positive integer\n");
                    exit(1);
                }
                $options['start'] = (int) $argv[$i];
                break;

            case '--end':
                $i++;
                if ($i >= count($argv) || !ctype_digit($argv[$i])) {
                    fwrite(STDERR, "Error: --end requires a positive integer\n");
                    exit(1);
                }
                $options['end'] = (int) $argv[$i];
                break;

            case '--output':
                $i++;
                if ($i >= count($argv)) {
                    fwrite(STDERR, "Error: --output requires a path\n");
                    exit(1);
                }
                $options['output'] = $argv[$i];
                break;

            case '--delay':
                $i++;
                if ($i >= count($argv) || !is_numeric($argv[$i])) {
                    fwrite(STDERR, "Error: --delay requires a numeric value\n");
                    exit(1);
                }
                $options['delay'] = (float) $argv[$i];
                break;

            case '--force':
                $options['force'] = true;
                break;

            case '--debug':
                $options['debug'] = true;
                break;

            default:
                fwrite(STDERR, "Unknown argument: {$arg}\n");
                fwrite(STDERR, "Use --help to see available options.\n");
                exit(1);
        }

        $i++;
    }

    return $options;
}


/**
 * Print the usage/help information for the script.
 *
 * Displays a comprehensive help message including all available options,
 * usage examples, output format description, and operational notes.
 *
 * @return void
 */
function printUsage(): void
{
    $scriptName = basename(__FILE__);
    echo <<<USAGE
Hymnal scraper — SDAH + CH (no Composer required)

Usage:
  php {$scriptName} [OPTIONS]

Options:
  --site {sdah|ch|both}   Which site to scrape (default: both)
  --start {N}             First hymn number to scrape (default: 1)
  --end {N}               Last hymn number to scrape (default: auto-detect)
  --output {path}         Output folder path (default: ./hymns)
  --delay {N}             Seconds between HTTP requests (default: 1.0)
  --force                 Force re-download of all hymns, even if files exist
  --debug                 Dump raw HTML responses for debugging
  --help, -h, -?          Show this help message

Examples:
  php {$scriptName}                         Scrape both sites from hymn 1
  php {$scriptName} --site sdah             Only scrape sdahymnal.org (SDAH)
  php {$scriptName} --site ch               Only scrape hymnal.xyz (CH)
  php {$scriptName} --start 50              Resume scraping from hymn 50
  php {$scriptName} --start 1 --end 100     Scrape a specific range of hymns
  php {$scriptName} --output ~/Desktop/hymns
                                            Save output to a custom folder
  php {$scriptName} --delay 2.0             Increase delay to 2s between requests

Output:
  Files are saved as plain text in book-specific subdirectories:
    hymns/Seventh-day Adventist Hymnal [SDAH]/001 (SDAH) - Praise To The Lord.txt
    hymns/The Church Hymnal [CH]/001 (CH) - Praise To The Lord.txt

  The scraper is resumable — existing files are detected and skipped.
  Skipped hymns (errors, missing data) are logged to skipped.log.

Notes:
  - No Composer dependencies — uses only built-in PHP extensions (cURL, mbstring).
  - Both sites share the same HTML structure, so one parser handles both.
  - The scraper auto-detects the end of the hymnal (no --end needed).
  - Rate limiting is handled automatically (pauses 60s, retries once).

USAGE;
}


// ===========================================================================
// Main — CLI entry point and orchestration
// ===========================================================================

/**
 * Main function: parse CLI arguments and orchestrate the scraping process.
 *
 * Supports scraping one or both sites, specifying a hymn number range,
 * custom output directory, and adjustable request delay. If --site is
 * "both" (the default), scrapes SDAH first, then CH sequentially.
 *
 * @return void
 */
function main(): void
{
    global $argv, $DEBUG;

    // Verify that the cURL extension is available (required for HTTP requests)
    if (!function_exists('curl_init')) {
        fwrite(STDERR, "Error: PHP cURL extension is required but not available.\n");
        fwrite(STDERR, "Install it with: sudo apt install php-curl (Linux) or enable in php.ini\n");
        exit(1);
    }

    // Verify that the mbstring extension is available (required for encoding handling)
    if (!function_exists('mb_check_encoding')) {
        fwrite(STDERR, "Error: PHP mbstring extension is required but not available.\n");
        fwrite(STDERR, "Install it with: sudo apt install php-mbstring (Linux) or enable in php.ini\n");
        exit(1);
    }

    // Parse command-line arguments
    $options = parseArgs($argv);

    // Set the global debug flag
    $DEBUG = $options['debug'];

    // Determine which sites to scrape based on the --site argument
    if ($options['site'] === 'both') {
        $sitesToRun = ['sdah', 'ch'];
    } else {
        $sitesToRun = [$options['site']];
    }

    $total = 0;

    // Scrape each site sequentially, accumulating the total saved count
    foreach ($sitesToRun as $siteKey) {
        $total += scrapeSite(
            $siteKey,
            $options['start'],
            $options['end'],
            $options['output'],
            $options['delay'],
            $options['force']
        );
    }

    // Resolve the output path for the summary message
    $absOutput = realpath($options['output']);
    if ($absOutput === false) {
        $absOutput = $options['output'];
    }
    echo "\nAll done! {$total} hymns total saved to: {$absOutput}\n";
}


// ---------------------------------------------------------------------------
// Script entry point — only execute when run directly (not when included)
// ---------------------------------------------------------------------------
// PHP doesn't have a direct equivalent of Python's `if __name__ == "__main__"`,
// but we can check if the current file is the main script being executed by
// comparing realpath(__FILE__) with the script that was invoked. This allows
// the file to be included/required by other scripts for testing without
// triggering the main scrape loop.
// ---------------------------------------------------------------------------
if (realpath(__FILE__) === realpath($argv[0] ?? '')) {
    main();
}
