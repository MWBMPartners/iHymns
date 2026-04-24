<#
.SYNOPSIS
    SDAHymnals_SDAHymnal.org.ps1
    importers/scrapers/SDAHymnals_SDAHymnal.org.ps1

    Hymnal Scraper — scrapes both sdahymnal.org (SDAH) and hymnal.xyz (CH).
    Copyright 2025-2026 MWBM Partners Ltd.

.DESCRIPTION
    This scraper fetches hymn lyrics from two Seventh-day Adventist hymnal
    websites that share the same underlying codebase (identical HTML structure
    and CSS class names). It iterates through hymn numbers sequentially,
    parses the HTML to extract the title, section indicators (e.g. "Verse 1",
    "Chorus"), and lyrics text, then saves each hymn as a plain-text file.

    The scraper is designed to be resumable: it scans the output directory for
    existing files on startup and skips hymns that have already been saved.
    It also handles rate limiting, server errors, and auto-detects the end
    of the hymnal when the site redirects to the homepage.

    When double-clicked from Explorer (no CLI arguments), an interactive menu
    is displayed allowing the user to configure parameters before starting.

.PARAMETER Site
    Which site to scrape: "sdah" (sdahymnal.org), "ch" (hymnal.xyz),
    or "both" (default). Both sites share the same HTML structure.

.PARAMETER Start
    First hymn number to scrape (inclusive). Default: 1.

.PARAMETER End
    Last hymn number to scrape (inclusive). Default: auto-detect end of hymnal.
    If not specified, the scraper continues until the site redirects to the
    homepage or 10 consecutive failures are encountered.

.PARAMETER Output
    Output folder path where hymn files will be saved. Default: ./hymns.
    Book-specific subdirectories are created automatically:
      hymns/Seventh-day Adventist Hymnal [SDAH]/
      hymns/The Church Hymnal [CH]/

.PARAMETER Delay
    Seconds to wait between HTTP requests to avoid overwhelming the server
    and triggering rate limits. Default: 1.0.

.PARAMETER Help
    Show usage information and exit (alias for -?).

.PARAMETER Force
    Force mode: re-download and overwrite all hymn files, even if they
    already exist in the output directory. Bypasses the resumability check.

.EXAMPLE
    .\SDAHymnals_SDAHymnal.org.ps1
    # Scrape both sites from hymn 1 (or interactive menu if double-clicked)

.EXAMPLE
    .\SDAHymnals_SDAHymnal.org.ps1 -Site sdah
    # Only scrape sdahymnal.org (SDAH)

.EXAMPLE
    .\SDAHymnals_SDAHymnal.org.ps1 -Site ch
    # Only scrape hymnal.xyz (CH)

.EXAMPLE
    .\SDAHymnals_SDAHymnal.org.ps1 -Start 50
    # Resume scraping from hymn 50

.EXAMPLE
    .\SDAHymnals_SDAHymnal.org.ps1 -Start 1 -End 100
    # Scrape a specific range of hymns

.EXAMPLE
    .\SDAHymnals_SDAHymnal.org.ps1 -Output "$HOME\Desktop\hymns"
    # Save output to a custom folder

.EXAMPLE
    .\SDAHymnals_SDAHymnal.org.ps1 -Delay 2.0
    # Increase delay to 2 seconds between requests

.EXAMPLE
    .\SDAHymnals_SDAHymnal.org.ps1 -Force
    # Re-download all hymns, overwriting existing files

.EXAMPLE
    .\SDAHymnals_SDAHymnal.org.ps1 -Site sdah -Start 1 -End 50 -Force
    # Force re-download of SDAH hymns 1-50

.NOTES
    Dependencies:
        None — uses only PowerShell built-in cmdlets (no modules required).
        Requires PowerShell 5.1+ (Windows PowerShell) or PowerShell 7+ (cross-platform).

    Output format:
        Each hymn is saved as a UTF-8 plain-text file with the naming convention:
            {number zero-padded to 3 digits} ({LABEL}) - {Title Case Title}.txt
        e.g.: "001 (SDAH) - Praise To The Lord.txt"

        Files are organised into book-specific subdirectories:
            hymns/Seventh-day Adventist Hymnal [SDAH]/
            hymns/The Church Hymnal [CH]/

    HTML Parsing:
        Uses regex-based parsing to extract:
        - Title:      div.block-heading-four > *.wedding-heading > strong
        - Indicators: div.block-heading-three (e.g. "Verse 1", "Chorus")
        - Lyrics:     div.block-heading-five (with <br> as line breaks)

    Resumability:
        The scraper scans the output directory for existing files on startup
        and skips hymns that have already been saved.

    Error handling:
        - HTTP 500: Retries up to 3 times with 3-second backoff
        - Rate limiting: Pauses 60 seconds and retries once
        - Homepage redirect: Stops scraping (end of hymnal detected)
        - 10 consecutive failures: Stops scraping (safety net)

    Author: MWBM Partners Ltd.
    Version: 2.0
#>

# ---------------------------------------------------------------------------
# Parameter block — defines the command-line arguments this script accepts.
# These mirror the Python version's argparse arguments exactly.
# CmdletBinding enables -Verbose, -Debug, and other common parameters.
# ---------------------------------------------------------------------------
[CmdletBinding()]
param(
    # Which site to scrape: "sdah" for sdahymnal.org, "ch" for hymnal.xyz,
    # or "both" (default) to scrape both sites sequentially.
    [ValidateSet("sdah", "ch", "both")]
    [string]$Site = "",

    # First hymn number to scrape (inclusive). Default is 1.
    [int]$Start = 0,

    # Last hymn number to scrape (inclusive). 0 means auto-detect the end
    # of the hymnal by watching for homepage redirects.
    [int]$End = 0,

    # Output folder path. Default is ./hymns relative to the current directory.
    [string]$Output = "",

    # Seconds to wait between HTTP requests. Default is 1.0 seconds.
    [double]$Delay = 0,

    # Show help/usage information and exit.
    [Alias("?")]
    [switch]$Help,

    # Force mode: re-download and overwrite existing hymn files instead of
    # skipping them. When set, the existing-hymn scan is bypassed (an empty
    # set is used), so every hymn in the requested range is fetched fresh.
    [switch]$Force
)

# ---------------------------------------------------------------------------
# Site configuration — both sites share the same HTML structure
# ---------------------------------------------------------------------------
# Both sdahymnal.org and hymnal.xyz are built on the same web platform,
# so they use identical CSS class names and page layouts. This allows us
# to use a single parsing function for both sites. Each site entry defines:
#   - base_url:  The hymn page URL template (hymn number passed as ?no=N)
#   - home_url:  The site homepage (used to detect end-of-hymnal redirects)
#   - label:     Short identifier used in output filenames, e.g. "SDAH"
#   - subdir:    Human-readable subdirectory name for organised output
#   - lang:      ISO 639-1 language code for the songbook's language (e.g. "en")
$script:SITES = @{
    "sdah" = @{
        base_url = "https://www.sdahymnal.org/Hymn"
        home_url = "https://www.sdahymnal.org"
        label    = "SDAH"
        subdir   = "Seventh-day Adventist Hymnal [SDAH]"
        lang     = "en"   # ISO 639-1: English
    }
    "ch" = @{
        base_url = "https://www.hymnal.xyz/Hymn"
        home_url = "https://www.hymnal.xyz"
        label    = "CH"
        subdir   = "The Church Hymnal [CH]"
        lang     = "en"   # ISO 639-1: English
    }
}

# Default output directory (relative to where the script is run from)
$script:DEFAULT_OUTPUT_DIR = ".\hymns"

# Default delay in seconds between HTTP requests to be respectful to the
# server and avoid triggering rate limits. 1.0s is a reasonable balance
# between speed and politeness.
$script:DEFAULT_DELAY = 1.0

# Maximum consecutive skips before assuming we've passed the end of the hymnal.
# This is a safety net for sites that don't redirect to the homepage for
# non-existent hymns but instead return errors.
$script:MAX_CONSEC = 10

# User-Agent header identifying the scraper. This is the same polite
# User-Agent used by the Python version.
$script:USER_AGENT = "Mozilla/5.0 (compatible; HymnScraper/2.0; personal use)"


# ---------------------------------------------------------------------------
# HTML Entity decoder — converts HTML entities to Unicode characters
# ---------------------------------------------------------------------------

function ConvertFrom-HtmlEntity {
    <#
    .SYNOPSIS
        Convert an HTML entity string to its Unicode character equivalent.

    .DESCRIPTION
        Handles both named entities (e.g. &amp;, &rsquo;) and numeric
        character references (e.g. &#8217;, &#x2019;). This is needed
        because our regex-based parser encounters raw entity strings that
        need to be resolved to display characters. Covers the most frequently
        encountered entities in hymn lyrics: standard XML entities,
        typographic quotes, non-breaking spaces, and dashes.

    .PARAMETER Entity
        The full entity string including & and ; (e.g. "&amp;", "&#8217;", "&#x2019;").

    .OUTPUTS
        [string] The corresponding Unicode character, or empty string if not recognised.
    #>
    param(
        [string]$Entity
    )

    # Strip the leading & and trailing ; to get the entity body
    $body = $Entity.TrimStart("&").TrimEnd(";")

    # --- Numeric character references (decimal or hex) ---
    if ($body.StartsWith("#")) {
        $numPart = $body.Substring(1)  # Remove the '#' prefix

        try {
            if ($numPart.StartsWith("x") -or $numPart.StartsWith("X")) {
                # Hexadecimal reference: &#x2019; → $numPart = "x2019"
                $codePoint = [Convert]::ToInt32($numPart.Substring(1), 16)
            }
            else {
                # Decimal reference: &#8217; → $numPart = "8217"
                $codePoint = [int]$numPart
            }
            # Windows-1252 remapping: code points 128-159 are C1 control
            # characters in Unicode, but many legacy web pages use them to
            # mean the Windows-1252 characters (smart quotes, dashes, etc.).
            # Web browsers perform this remapping automatically; we do the
            # same here so that &#145; becomes a left single quote, etc.
            # Reference: https://html.spec.whatwg.org/#numeric-character-reference-end-state
            $win1252Map = @{
                145 = [char]0x2018   # left single quotation mark
                146 = [char]0x2019   # right single quotation mark
                147 = [char]0x201C   # left double quotation mark
                148 = [char]0x201D   # right double quotation mark
                150 = [char]0x2013   # en dash
                151 = [char]0x2014   # em dash
            }
            if ($win1252Map.ContainsKey($codePoint)) {
                return $win1252Map[$codePoint]
            }
            # Convert the code point to a Unicode character
            return [char]$codePoint
        }
        catch {
            # Malformed or out-of-range character reference — return empty
            return ""
        }
    }

    # --- Named entities ---
    # Map common HTML entities to their Unicode characters.
    # This covers the most frequently encountered entities in hymn lyrics:
    # standard XML entities, typographic quotes, and dashes.
    $namedEntities = @{
        "amp"    = "&"
        "lt"     = "<"
        "gt"     = ">"
        "quot"   = '"'
        "apos"   = "'"
        "nbsp"   = " "            # non-breaking space → regular space
        "rsquo"  = [char]0x2019   # right single smart quote '
        "lsquo"  = [char]0x2018   # left single smart quote '
        "rdquo"  = [char]0x201D   # right double smart quote "
        "ldquo"  = [char]0x201C   # left double smart quote "
        "mdash"  = [char]0x2014   # em dash —
        "ndash"  = [char]0x2013   # en dash –
    }

    if ($namedEntities.ContainsKey($body)) {
        return $namedEntities[$body]
    }

    # Unknown entity — return empty string
    return ""
}


# ---------------------------------------------------------------------------
# HTML Parser — regex-based extraction of hymn data from page HTML
# ---------------------------------------------------------------------------
# Both sdahymnal.org and hymnal.xyz share the same underlying codebase, so
# they use identical CSS class names for their hymn page structure. This
# function navigates the HTML using regex patterns to extract:
#
# 1. TITLE: Found inside a <strong> tag, nested within an element with class
#    "wedding-heading", which is itself inside a <div> with class
#    "block-heading-four".
#
# 2. SECTION INDICATORS: Found in <div class="block-heading-three">.
#    These are labels like "Verse 1", "Chorus", "Verse 2", etc.
#
# 3. LYRICS TEXT: Found in <div class="block-heading-five">.
#    Line breaks within lyrics are represented by <br> tags, which we
#    convert to newline characters.
#
# The function pairs each indicator with its following lyrics block,
# producing an array of objects with .indicator and .lyrics properties.
# ---------------------------------------------------------------------------

function Parse-HymnHtml {
    <#
    .SYNOPSIS
        Parse hymn HTML to extract title and lyrics sections.

    .DESCRIPTION
        Uses regex patterns to extract the hymn title, section indicators
        (e.g. "Verse 1", "Chorus"), and lyrics text from the HTML of a
        hymn page on sdahymnal.org or hymnal.xyz.

        HTML structure being parsed:
            <div class="block-heading-four">        — title container
                <h3 class="wedding-heading">        — title wrapper
                    <strong>Hymn Title Here</strong> — actual title text
                </h3>
            </div>
            <div class="block-heading-three">       — section indicator
                Verse 1                              — e.g. "Verse 1", "Chorus"
            </div>
            <div class="block-heading-five">        — lyrics block
                First line of lyrics<br>             — <br> = line break
                Second line of lyrics<br>
            </div>

    .PARAMETER Html
        The raw HTML string of the hymn page.

    .OUTPUTS
        [hashtable] with keys:
            title    [string]: The extracted hymn title (empty if not found)
            sections [array]:  Array of objects with .indicator and .lyrics properties
    #>
    param(
        [string]$Html
    )

    # Initialise return data structure
    $result = @{
        title    = ""
        sections = @()
    }

    # -----------------------------------------------------------------------
    # STEP 1: Extract the hymn title
    # -----------------------------------------------------------------------
    # The title is nested: div.block-heading-four > *.wedding-heading > <strong>
    # We use a multi-step regex approach:
    #   1. Find the block-heading-four div and capture its inner content
    #   2. Within that, find the wedding-heading element and its content
    #   3. Within that, find the first <strong> tag and extract the text
    #
    # Pattern explanation for block-heading-four extraction:
    #   <div[^>]*class="[^"]*block-heading-four[^"]*"[^>]*>  — opening div with class
    #   ([\s\S]*?)                                             — non-greedy capture of inner HTML
    #   </div>                                                 — closing div tag
    # Note: [\s\S]*? matches any character including newlines (non-greedy)
    # -----------------------------------------------------------------------

    # Step 1a: Extract the content inside the block-heading-four div
    $bh4Pattern = '<div[^>]*class="[^"]*block-heading-four[^"]*"[^>]*>([\s\S]*?)</div>'
    $bh4Match = [regex]::Match($Html, $bh4Pattern, [System.Text.RegularExpressions.RegexOptions]::IgnoreCase)

    if ($bh4Match.Success) {
        $bh4Content = $bh4Match.Groups[1].Value

        # Step 1b: Within block-heading-four, find the wedding-heading element
        # The wedding-heading class can be on any tag (h3, h2, p, etc.)
        # Pattern: any tag with class containing "wedding-heading", capture inner content
        $whPattern = '<[^>]*class="[^"]*wedding-heading[^"]*"[^>]*>([\s\S]*?)</[^>]+>'
        $whMatch = [regex]::Match($bh4Content, $whPattern, [System.Text.RegularExpressions.RegexOptions]::IgnoreCase)

        if ($whMatch.Success) {
            $whContent = $whMatch.Groups[1].Value

            # Step 1c: Within wedding-heading, find the first <strong> tag
            # Pattern: <strong> followed by any content, captured non-greedy, then </strong>
            $strongPattern = '<strong[^>]*>([\s\S]*?)</strong>'
            $strongMatch = [regex]::Match($whContent, $strongPattern, [System.Text.RegularExpressions.RegexOptions]::IgnoreCase)

            if ($strongMatch.Success) {
                # Extract the raw title text from the <strong> tag
                $rawTitle = $strongMatch.Groups[1].Value

                # Strip any remaining HTML tags from the title (there shouldn't be any,
                # but this is a safety measure for edge cases)
                $rawTitle = [regex]::Replace($rawTitle, '<[^>]+>', '')

                # Decode HTML entities in the title (e.g. &amp; → &, &#8217; → ')
                # Pattern matches: &name; or &#digits; or &#xhex;
                $rawTitle = [regex]::Replace($rawTitle, '&(#?[a-zA-Z0-9]+);', {
                    param($m)
                    $entity = $m.Value
                    $decoded = ConvertFrom-HtmlEntity -Entity $entity
                    if ($decoded) { return $decoded }
                    return $entity  # Return the raw entity if decoding fails
                })

                # Trim whitespace from the title
                $result.title = $rawTitle.Trim()
            }
        }
    }

    # -----------------------------------------------------------------------
    # STEP 2: Extract section indicators and lyrics
    # -----------------------------------------------------------------------
    # Indicators and lyrics are sibling div elements with specific classes.
    # We find all block-heading-three and block-heading-five divs in order,
    # then pair each indicator with the lyrics block that follows it.
    #
    # Strategy: Use a single regex to match both types of blocks, preserving
    # their order of appearance. Each match is tagged as either "indicator"
    # or "lyrics" based on the class name found.
    # -----------------------------------------------------------------------

    # Combined pattern to match both indicator and lyrics div blocks.
    # Uses alternation (|) to match either class, with a named group to
    # identify which type was matched.
    # The [\s\S]*? non-greedy match captures everything inside the div
    # up to the first closing </div> tag.
    $sectionPattern = '<div[^>]*class="[^"]*(?<type>block-heading-three|block-heading-five)[^"]*"[^>]*>([\s\S]*?)</div>'
    $sectionMatches = [regex]::Matches($Html, $sectionPattern, [System.Text.RegularExpressions.RegexOptions]::IgnoreCase)

    # Track the most recently seen indicator text, to pair with the next lyrics block
    $currentIndicator = ""

    # Temporary list to accumulate sections (PowerShell arrays are immutable,
    # so we use an ArrayList for efficient appending)
    $sectionsList = [System.Collections.ArrayList]::new()

    foreach ($sMatch in $sectionMatches) {
        # Determine if this is an indicator or lyrics block based on the class name
        $blockType = $sMatch.Groups["type"].Value
        # The inner content is in capture group 2 (group 1 is the named "type" group)
        $innerHtml = $sMatch.Groups[2].Value

        if ($blockType -eq "block-heading-three") {
            # --- INDICATOR BLOCK ---
            # Contains text like "Verse 1", "Chorus", "Verse 2", etc.

            # Strip HTML tags from the indicator text
            $indicatorText = [regex]::Replace($innerHtml, '<[^>]+>', '')

            # Decode HTML entities in the indicator
            $indicatorText = [regex]::Replace($indicatorText, '&(#?[a-zA-Z0-9]+);', {
                param($m)
                $decoded = ConvertFrom-HtmlEntity -Entity $m.Value
                if ($decoded) { return $decoded }
                return $m.Value
            })

            # Store the indicator text to pair with the next lyrics block
            $currentIndicator = $indicatorText.Trim()
        }
        elseif ($blockType -eq "block-heading-five") {
            # --- LYRICS BLOCK ---
            # Contains the actual hymn lyrics with <br> tags for line breaks

            # Step 2a: Convert <br> and <br/> tags to newline characters
            # The sites use <br> for line breaks within verses
            $lyricsText = [regex]::Replace($innerHtml, '<br\s*/?>', "`n")

            # Step 2b: Strip all remaining HTML tags from the lyrics
            $lyricsText = [regex]::Replace($lyricsText, '<[^>]+>', '')

            # Step 2c: Decode HTML entities in the lyrics
            $lyricsText = [regex]::Replace($lyricsText, '&(#?[a-zA-Z0-9]+);', {
                param($m)
                $decoded = ConvertFrom-HtmlEntity -Entity $m.Value
                if ($decoded) { return $decoded }
                return $m.Value
            })

            # Step 2d: Collapse runs of 3+ newlines down to 2
            # (clean up excessive whitespace from empty <br> tags in the source)
            $lyricsText = [regex]::Replace($lyricsText, '\n{3,}', "`n`n")

            # Trim leading/trailing whitespace
            $lyricsText = $lyricsText.Trim()

            # Pair this lyrics block with its indicator and add to sections list
            [void]$sectionsList.Add([PSCustomObject]@{
                indicator = $currentIndicator
                lyrics    = $lyricsText
            })

            # Reset indicator for the next verse (some verses may not have one)
            $currentIndicator = ""
        }
    }

    # Convert the ArrayList to a standard array for the return value
    $result.sections = @($sectionsList)

    return $result
}


# ---------------------------------------------------------------------------
# Title Case — convert a string to Title Case with correct apostrophe handling
# ---------------------------------------------------------------------------

function ConvertTo-TitleCaseCustom {
    <#
    .SYNOPSIS
        Convert a string to Title Case, with correct handling of apostrophes.

    .DESCRIPTION
        PowerShell's built-in (Get-Culture).TextInfo.ToTitleCase() method and
        similar approaches can treat apostrophes as word boundaries, producing
        incorrect results like "Don'T" instead of "Don't". This function uses
        a regex to match whole words (including apostrophe contractions like
        "don't", "it's", "o'er") and capitalises each word correctly.

        The regex matches:
            [a-zA-Z]+                   One or more letters (the main word)
            (['\u2019\u2018]            Optionally an apostrophe (ASCII, right-curly, or left-curly)
             [a-zA-Z]+)?               followed by more letters (contraction suffix)

        We include Unicode curly/smart apostrophes (\u2019 RIGHT SINGLE QUOTATION
        MARK and \u2018 LEFT SINGLE QUOTATION MARK) because HTML entities like
        &rsquo; decode to \u2019. Without this, "Eagle\u2019s" would be split into
        separate words, producing "Eagle'S" instead of "Eagle's".

        For each match, the first character is uppercased and the rest are
        lowercased — producing correct Title Case for contractions.

    .PARAMETER InputString
        The input string to convert to Title Case.

    .OUTPUTS
        [string] The Title Cased string.

    .EXAMPLE
        ConvertTo-TitleCaseCustom "AMAZING GRACE"
        # Returns: "Amazing Grace"

    .EXAMPLE
        ConvertTo-TitleCaseCustom "don't let me down"
        # Returns: "Don't Let Me Down"

    .EXAMPLE
        ConvertTo-TitleCaseCustom "o'er the hills"
        # Returns: "O'er The Hills"
    #>
    param(
        [string]$InputString
    )

    # Regex pattern matches a "word" which may contain an apostrophe contraction.
    # [a-zA-Z]+ matches one or more letters (the base word).
    # (['\u2019\u2018][a-zA-Z]+)? optionally matches an apostrophe (ASCII or Unicode
    # curly quotes) followed by more letters (e.g. "'t" in "don't", "'er" in "o'er").
    # Unicode \u2019 (RIGHT SINGLE QUOTATION MARK) and \u2018 (LEFT SINGLE QUOTATION
    # MARK) are included because HTML entity &rsquo; decodes to \u2019.
    $pattern = "[a-zA-Z]+(['\u2019\u2018][a-zA-Z]+)?"

    # Use [regex]::Replace with a script block evaluator to capitalise each word.
    # The script block receives each match object, extracts the full matched word,
    # then uppercases the first character and lowercases the rest.
    $result = [regex]::Replace($InputString, $pattern, {
        param($match)
        $word = $match.Value
        if ($word.Length -eq 0) { return $word }

        # Capitalise: first char uppercase, remaining chars lowercase
        # This correctly handles "DON'T" → "Don't" because the entire
        # contraction is treated as one word (not split at the apostrophe)
        return $word.Substring(0, 1).ToUpper() + $word.Substring(1).ToLower()
    })

    return $result
}


# ---------------------------------------------------------------------------
# Sanitize — remove invalid filename characters
# ---------------------------------------------------------------------------

function Get-SanitizedFilename {
    <#
    .SYNOPSIS
        Remove characters that are invalid in filenames across operating systems.

    .DESCRIPTION
        Strips the following characters which are forbidden in Windows filenames
        and/or could cause issues on other platforms:
            \ / * ? : " < > |

    .PARAMETER Name
        The raw string to sanitize (typically a hymn title).

    .OUTPUTS
        [string] The sanitized string with invalid characters removed and
        leading/trailing whitespace stripped.
    #>
    param(
        [string]$Name
    )

    # Remove characters that are invalid in Windows filenames: \ / * ? : " < > |
    # The regex character class includes each forbidden character.
    # Note: the backslash must be escaped in the regex as \\
    $sanitized = [regex]::Replace($Name, '[\\/*?:"<>|]', '')

    # Strip leading/trailing whitespace that may remain after character removal
    return $sanitized.Trim()
}


# ---------------------------------------------------------------------------
# Format hymn — convert parsed hymn data to plain-text output
# ---------------------------------------------------------------------------

function Format-HymnText {
    <#
    .SYNOPSIS
        Format a parsed hymn hashtable into a clean plain-text string for saving.

    .DESCRIPTION
        The output format is:
            "Hymn Title"
                                        — blank line after title
            Verse 1                     — indicator (if present)
            First line of lyrics        — lyrics text
            Second line of lyrics
                                        — blank line between sections
            Chorus                      — next indicator
            Chorus lyrics here
            ...

    .PARAMETER Hymn
        Hashtable with keys:
            title    [string]: The hymn title
            sections [array]:  Array of objects with .indicator and .lyrics properties

    .OUTPUTS
        [string] The formatted plain-text hymn content.
    #>
    param(
        [hashtable]$Hymn
    )

    # Start with the quoted title and a blank line
    $lines = [System.Collections.ArrayList]::new()
    [void]$lines.Add('"' + $Hymn.title + '"')
    [void]$lines.Add("")

    # Append each section (indicator + lyrics) with blank line separators
    foreach ($section in $Hymn.sections) {
        if ($section.indicator) {
            # Add the section indicator (e.g. "Verse 1", "Chorus")
            [void]$lines.Add($section.indicator)
        }
        if ($section.lyrics) {
            # Add the verse/chorus text
            [void]$lines.Add($section.lyrics)
        }
        # Blank line between sections for visual separation
        [void]$lines.Add("")
    }

    # Remove trailing blank lines (cleaner file ending)
    while ($lines.Count -gt 0 -and $lines[$lines.Count - 1] -eq "") {
        $lines.RemoveAt($lines.Count - 1)
    }

    # Join all lines with newline characters and return
    return ($lines -join "`n")
}


# ---------------------------------------------------------------------------
# Build existing set — scan output directory for already-saved hymns
# ---------------------------------------------------------------------------

function Get-ExistingHymnNumbers {
    <#
    .SYNOPSIS
        Scan the output directory to find hymn numbers that have already been saved.

    .DESCRIPTION
        This enables the scraper to resume from where it left off without
        re-downloading hymns. It looks for files matching the naming pattern
        "{number} ({label}) - {title}.txt" and extracts the hymn number from
        each matching filename.

    .PARAMETER Label
        The book label to filter by (e.g. "SDAH", "CH").

    .PARAMETER OutputDir
        Path to the directory to scan for existing files.

    .OUTPUTS
        [System.Collections.Generic.HashSet[int]] A set of hymn numbers already saved.
        Empty set if the directory doesn't exist or has no matching files.

    .EXAMPLE
        If OutputDir contains:
            "001 (SDAH) - Praise To The Lord.txt"
            "042 (SDAH) - A Mighty Fortress.txt"
        Returns a set containing: 1, 42
    #>
    param(
        [string]$Label,
        [string]$OutputDir
    )

    # Use a HashSet for O(1) lookups when checking if a hymn number exists
    $existing = [System.Collections.Generic.HashSet[int]]::new()

    # Check if the output directory exists before trying to scan it
    if (Test-Path -Path $OutputDir -PathType Container) {
        # Build the prefix tag to filter files belonging to this specific book
        # e.g. "(SDAH) -" to match "001 (SDAH) - Praise To The Lord.txt"
        $prefixTag = "($Label) -"

        # Get all .txt files in the directory that contain the book label
        $files = Get-ChildItem -Path $OutputDir -Filter "*.txt" -File -ErrorAction SilentlyContinue

        foreach ($file in $files) {
            # Check if this file belongs to the current book by looking for the label tag
            if ($file.Name.Contains($prefixTag)) {
                # Extract the hymn number from the start of the filename
                # (the zero-padded number before the first space)
                $parts = $file.Name.Split(" ")
                $numStr = $parts[0]

                # Verify it's a valid number and add to the set
                $num = 0
                if ([int]::TryParse($numStr, [ref]$num)) {
                    [void]$existing.Add($num)
                }
            }
        }
    }

    return $existing
}


# ---------------------------------------------------------------------------
# Save hymn — write formatted hymn text to a file
# ---------------------------------------------------------------------------

function Save-Hymn {
    <#
    .SYNOPSIS
        Save a parsed hymn to a plain-text file in the output directory.

    .DESCRIPTION
        Creates the output directory if it doesn't exist, formats the hymn
        content, and writes it to a file with the standard naming convention:
            {number zero-padded to 3} ({label}) - {Title Case Title}.txt

    .PARAMETER Hymn
        Hashtable with "number" (int), "title" (str), and "sections" (array).

    .PARAMETER Label
        Book label for the filename (e.g. "SDAH", "CH").

    .PARAMETER OutputDir
        Directory path where the file should be saved.

    .OUTPUTS
        [string] The full file path of the saved file.
    #>
    param(
        [hashtable]$Hymn,
        [string]$Label,
        [string]$OutputDir
    )

    # Ensure the output directory exists (creates parent dirs too if needed)
    if (-not (Test-Path -Path $OutputDir)) {
        New-Item -ItemType Directory -Path $OutputDir -Force | Out-Null
    }

    # Zero-pad the hymn number to 3 digits for consistent sorting
    # (e.g. 1 → "001", 42 → "042", 695 → "695")
    $padded = $Hymn.number.ToString().PadLeft(3, '0')

    # Build the filename: sanitize the title to remove invalid chars,
    # then convert to Title Case for consistent, readable filenames
    $safeTitle = Get-SanitizedFilename -Name $Hymn.title
    $titleCased = ConvertTo-TitleCaseCustom -InputString $safeTitle
    $filename = "$padded ($Label) - $titleCased.txt"
    $filepath = Join-Path -Path $OutputDir -ChildPath $filename

    # Format the hymn content as plain text
    $content = Format-HymnText -Hymn $Hymn

    # Write the formatted hymn text to the file with UTF-8 encoding (no BOM)
    # Using .NET StreamWriter to ensure UTF-8 without BOM (PowerShell 5.1's
    # Out-File and Set-Content add a BOM by default, which can cause issues)
    $utf8NoBom = [System.Text.UTF8Encoding]::new($false)
    [System.IO.File]::WriteAllText($filepath, $content, $utf8NoBom)

    return $filepath
}


# ---------------------------------------------------------------------------
# Log skip — record skipped hymns to a log file
# ---------------------------------------------------------------------------

function Write-SkipLog {
    <#
    .SYNOPSIS
        Record a skipped hymn entry in the skipped.log file for later review.

    .DESCRIPTION
        Creates a persistent log of hymns that couldn't be scraped, along with
        the reason and timestamp. Useful for identifying gaps in the collection
        and diagnosing systematic issues.

    .PARAMETER Number
        The hymn number that was skipped.

    .PARAMETER Label
        Book label (e.g. "SDAH", "CH").

    .PARAMETER Reason
        Human-readable explanation of why it was skipped.

    .PARAMETER BookDir
        Directory path where the log file should be written.

    .NOTES
        Log format (one line per skipped hymn):
            [2026-03-13 14:30:00]  SDAH042  —  fetch failed or no title found
    #>
    param(
        [int]$Number,
        [string]$Label,
        [string]$Reason,
        [string]$BookDir
    )

    # Ensure the directory exists (in case this is the first file we're writing)
    if (-not (Test-Path -Path $BookDir)) {
        New-Item -ItemType Directory -Path $BookDir -Force | Out-Null
    }

    $logPath   = Join-Path -Path $BookDir -ChildPath "skipped.log"
    $timestamp = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
    $padded    = $Number.ToString().PadLeft(3, '0')
    $logLine   = "[$timestamp]  $Label$padded  —  $Reason"

    # Append the log line to the file (creates the file if it doesn't exist)
    # Use .NET to ensure UTF-8 without BOM and proper newline handling
    $utf8NoBom = [System.Text.UTF8Encoding]::new($false)
    [System.IO.File]::AppendAllText($logPath, "$logLine`n", $utf8NoBom)
}


# ---------------------------------------------------------------------------
# Fetch hymn — HTTP request handling and hymn data extraction
# ---------------------------------------------------------------------------

function Fetch-Hymn {
    <#
    .SYNOPSIS
        Fetch a single hymn page from the website and parse it into structured data.

    .DESCRIPTION
        Makes an HTTP GET request to the hymn URL, handles various error conditions
        (server errors, rate limiting, redirects), and returns parsed hymn data.

        The function includes several resilience features:
        - Retries up to 3 times on HTTP 500 errors (with 3-second delays)
        - Detects rate-limiting pages and pauses 60 seconds before retrying
        - Detects end-of-hymnal by checking if the site redirects to the homepage
        - Falls back to latin-1 encoding if UTF-8 decoding fails
        - 15-second timeout for HTTP requests

    .PARAMETER Number
        The hymn number to fetch (e.g. 1, 42, 695).

    .PARAMETER BaseUrl
        The site's hymn page base URL (e.g. "https://www.sdahymnal.org/Hymn").

    .PARAMETER HomeUrl
        The site's homepage URL (used to detect end-of-hymnal redirects).

    .OUTPUTS
        [hashtable] with keys "number", "title", "sections" on success.
        [string] "SKIP" if the hymn should be skipped (server error, no title).
        $null if scraping should stop entirely (end of hymnal or persistent rate limiting).
    #>
    param(
        [int]$Number,
        [string]$BaseUrl,
        [string]$HomeUrl
    )

    # Construct the hymn page URL using the query parameter format used by both sites
    $url = "$BaseUrl`?no=$Number"

    # Variable to hold the response object and raw content
    $rawContent = $null
    $finalUrl   = $null

    # Retry loop: attempt up to 3 times on HTTP 500 errors
    for ($attempt = 1; $attempt -le 3; $attempt++) {
        try {
            # Make the HTTP request with a polite User-Agent and 15-second timeout.
            # -UseBasicParsing avoids dependency on Internet Explorer COM objects
            # (required for PowerShell 5.1 compatibility on some systems).
            # -MaximumRedirection 5 allows following redirects (needed to detect
            # homepage redirects that signal end-of-hymnal).
            $response = Invoke-WebRequest -Uri $url `
                -UserAgent $script:USER_AGENT `
                -TimeoutSec 15 `
                -UseBasicParsing `
                -MaximumRedirection 5 `
                -ErrorAction Stop

            # Capture the final URL after any redirects
            # Invoke-WebRequest stores the base response URI which reflects redirects
            $finalUrl = $response.BaseResponse.ResponseUri
            if ($null -eq $finalUrl) {
                # PowerShell 7+ uses a different property for the final URI
                # The BaseResponse in PS7 is HttpResponseMessage, which has RequestMessage.RequestUri
                try {
                    $finalUrl = $response.BaseResponse.RequestMessage.RequestUri
                }
                catch {
                    # Fallback: if we can't get the final URL, use the original
                    $finalUrl = $url
                }
            }
            $finalUrlStr = $finalUrl.ToString().TrimEnd("/")

            # Check if the server redirected us to the homepage — this means
            # the requested hymn number doesn't exist (we've gone past the
            # end of the hymnal). We only check for number > 1 because
            # hymn 1 should always exist.
            if (-not $finalUrlStr.Contains("Hymn") -and $Number -gt 1) {
                Write-Host ""
                Write-Host "  Hymn ${Number}: redirected to home — reached end." -ForegroundColor Yellow
                return $null  # Signal to stop scraping entirely
            }

            # Get the raw content as string
            $rawContent = $response.Content
            break  # Success — exit the retry loop
        }
        catch {
            $errorRecord = $_

            # Check if this is an HTTP error with a status code
            $statusCode = 0
            if ($errorRecord.Exception -is [System.Net.WebException]) {
                $webResponse = $errorRecord.Exception.Response
                if ($null -ne $webResponse) {
                    $statusCode = [int]$webResponse.StatusCode
                }
            }
            elseif ($errorRecord.Exception.Message -match '(\d{3})') {
                # PowerShell 7+ may wrap the status code differently;
                # try to extract it from the error message
                $statusCode = [int]$Matches[1]
            }

            if ($statusCode -eq 500) {
                # Server error — may be transient, so retry with backoff
                if ($attempt -lt 3) {
                    Write-Host "  Hymn ${Number}: server error (500), retrying ($attempt/3)..." -NoNewline -ForegroundColor Yellow
                    Start-Sleep -Seconds 3  # Wait before retrying
                }
                else {
                    # All 3 attempts failed — skip this hymn
                    Write-Host "  server error (500) after 3 attempts." -ForegroundColor Red
                    return "SKIP"
                }
            }
            else {
                # Other HTTP errors (404, 403, etc.) or network errors — don't retry, just skip
                $errMsg = if ($statusCode -gt 0) { "HTTP $statusCode" } else { "error: $($errorRecord.Exception.Message)" }
                Write-Host "  $errMsg" -ForegroundColor Red
                return "SKIP"
            }
        }
    }

    # If rawContent is still null, all retry attempts were exhausted without success
    if ($null -eq $rawContent) {
        return "SKIP"
    }

    # Note on encoding: Invoke-WebRequest in PowerShell automatically handles
    # content decoding based on the Content-Type header. The .Content property
    # returns a decoded string. If the server doesn't specify encoding, PowerShell
    # defaults to ISO-8859-1 (latin-1) which is a safe fallback (every byte maps
    # to a valid character). This mirrors the Python version's UTF-8 → latin-1 fallback.
    $htmlText = $rawContent

    # --- Rate limit detection ---
    # Both sites show a "reached limit for today" message when you've made
    # too many requests. If detected, pause for 60 seconds and try once more.
    $htmlLower = $htmlText.ToLower()
    if ($htmlLower.Contains("reached limit for today") -or $htmlLower.Contains("we are sorry")) {
        Write-Host "  rate limit hit — pausing 60s..." -ForegroundColor Magenta
        Start-Sleep -Seconds 60

        # One retry after the cooldown period
        try {
            $retryResponse = Invoke-WebRequest -Uri $url `
                -UserAgent $script:USER_AGENT `
                -TimeoutSec 15 `
                -UseBasicParsing `
                -MaximumRedirection 5 `
                -ErrorAction Stop

            $htmlText2 = $retryResponse.Content

            # Check if we're still rate limited after waiting
            if ($htmlText2.ToLower().Contains("reached limit for today")) {
                Write-Host "  Still rate limited — stopping. Try again tomorrow." -ForegroundColor Red
                return $null  # Stop entirely — no point continuing today
            }

            # Rate limit cleared — use the fresh response
            $htmlText = $htmlText2
        }
        catch {
            # Network error during retry — stop scraping
            return $null
        }
    }

    # Parse the HTML to extract hymn data
    $parsed = Parse-HymnHtml -Html $htmlText

    # Validate that the parser found a title (indicates a valid hymn page)
    if (-not $parsed.title) {
        Write-Host "  no title found." -ForegroundColor Red
        return "SKIP"
    }

    # Return the structured hymn data
    return @{
        number   = $Number
        title    = $parsed.title
        sections = $parsed.sections
    }
}


# ---------------------------------------------------------------------------
# Per-site scrape loop — the main orchestration logic for one hymnal site
# ---------------------------------------------------------------------------

function Start-SiteScrape {
    <#
    .SYNOPSIS
        Scrape all hymns from a single site (SDAH or CH).

    .DESCRIPTION
        This is the main loop that iterates through hymn numbers, fetches each
        one, and saves it. It includes several features for robust operation:

        - RESUMABILITY: Scans the output directory for existing files and skips
          hymns that have already been saved (via Get-ExistingHymnNumbers).

        - AUTO-DETECTION OF END: When the site redirects a hymn request to the
          homepage (Fetch-Hymn returns $null), scraping stops.

        - CONSECUTIVE SKIP LIMIT: If 10 hymns in a row fail (MAX_CONSEC = 10),
          we assume we've gone past the end of the hymnal and stop.

        - RATE LIMITING: Pauses for $Delay seconds between each request.

    .PARAMETER SiteKey
        Site identifier key from the SITES hashtable ("sdah" or "ch").

    .PARAMETER StartNum
        First hymn number to scrape (inclusive).

    .PARAMETER EndNum
        Last hymn number to scrape (inclusive), or 0 for auto-detect.

    .PARAMETER OutputDir
        Base output directory (a book-specific subdir will be created).

    .PARAMETER DelaySeconds
        Seconds to wait between HTTP requests.

    .PARAMETER ForceMode
        When $true, skip building the existing-hymn set and re-download
        all hymns, overwriting any files already on disk.

    .OUTPUTS
        [int] Number of hymns successfully saved in this run.
    #>
    param(
        [string]$SiteKey,
        [int]$StartNum,
        [int]$EndNum,
        [string]$OutputDir,
        [double]$DelaySeconds,
        [bool]$ForceMode = $false
    )

    # Look up the site configuration for URLs, label, and subdirectory name
    $siteConfig = $script:SITES[$SiteKey]
    $label      = $siteConfig.label
    $baseUrl    = $siteConfig.base_url
    $homeUrl    = $siteConfig.home_url

    # Route output into a book-specific subdirectory within the base output dir
    # e.g. "./hymns/Seventh-day Adventist Hymnal [SDAH]/"
    $bookDir = Join-Path -Path $OutputDir -ChildPath $siteConfig.subdir

    # Resolve the full absolute path for display purposes
    $bookDirFull = $ExecutionContext.SessionState.Path.GetUnresolvedProviderPathFromPSPath($bookDir)

    # Print a banner with configuration details for this scrape run
    $separator = "=" * 50
    Write-Host ""
    Write-Host $separator -ForegroundColor Cyan
    Write-Host "  Scraping: $baseUrl  [$label]" -ForegroundColor Cyan
    Write-Host "  Output  : $bookDirFull" -ForegroundColor Cyan
    $endDisplay = if ($EndNum -gt 0) { $EndNum } else { "auto-detect" }
    Write-Host "  Range   : $StartNum to $endDisplay" -ForegroundColor Cyan
    Write-Host $separator -ForegroundColor Cyan
    Write-Host ""

    # Scan the output directory for already-saved hymns to enable resumability.
    # When force mode is active, skip the scan and use an empty set so that
    # every hymn in the range is fetched fresh and existing files are overwritten.
    if ($ForceMode) {
        Write-Host "  Force mode: will re-download and overwrite existing files." -ForegroundColor Magenta
        Write-Host ""
        $existing = [System.Collections.Generic.HashSet[int]]::new()
    }
    else {
        $existing = Get-ExistingHymnNumbers -Label $label -OutputDir $bookDir
        if ($existing.Count -gt 0) {
            Write-Host "  Found $($existing.Count) existing $label hymns — will skip." -ForegroundColor Green
            Write-Host ""
        }
    }

    # Counters for the final summary
    $saved      = 0     # Successfully saved hymns
    $skipped    = 0     # Hymns that were skipped due to errors
    $number     = $StartNum  # Current hymn number being processed

    # Consecutive skip detection: if we encounter MAX_CONSEC failures in a row,
    # we assume we've gone past the end of the hymnal. This is a safety net
    # for sites that don't redirect to the homepage for non-existent hymns.
    $consecSkip = 0

    while ($true) {
        # Check if we've reached the user-specified end hymn
        if ($EndNum -gt 0 -and $number -gt $EndNum) {
            Write-Host ""
            Write-Host "Reached end hymn ($EndNum). Done." -ForegroundColor Green
            break
        }

        # Skip hymns that are already saved (detected during initial scan)
        if ($existing.Contains($number)) {
            Write-Host "  Hymn $($number.ToString().PadLeft(4)): >>  already exists, skipping." -ForegroundColor DarkGray
            $number++
            continue  # No delay needed — no network request was made
        }

        # Fetch and parse the hymn page
        Write-Host "  Hymn $($number.ToString().PadLeft(4)): fetching..." -NoNewline

        $hymn = Fetch-Hymn -Number $number -BaseUrl $baseUrl -HomeUrl $homeUrl

        if ($null -eq $hymn) {
            # The site redirected to the homepage — we've reached the end of
            # the hymnal. This is the normal termination condition.
            Write-Host ""
            break
        }

        if ($hymn -eq "SKIP") {
            # This hymn couldn't be scraped — log it and continue
            Write-Host " X  skipped." -ForegroundColor Red
            Write-SkipLog -Number $number -Label $label -Reason "fetch failed or no title found" -BookDir $bookDir
            $skipped++
            $consecSkip++

            # Safety net: too many consecutive failures suggests we're past
            # the end rather than hitting intermittent errors
            if ($consecSkip -ge $script:MAX_CONSEC) {
                Write-Host "  $($script:MAX_CONSEC) consecutive errors — assuming end of hymnal." -ForegroundColor Yellow
                break
            }

            $number++
            # Rate limit even on failures
            Start-Sleep -Milliseconds ([int]($DelaySeconds * 1000))
            continue
        }

        # Success — reset the consecutive skip counter and save the hymn
        $consecSkip = 0
        $path = Save-Hymn -Hymn $hymn -Label $label -OutputDir $bookDir
        $saved++
        $basename = Split-Path -Path $path -Leaf
        Write-Host " OK  $basename" -ForegroundColor Green

        $number++
        # Rate limit between successful requests
        Start-Sleep -Milliseconds ([int]($DelaySeconds * 1000))
    }

    # Print a summary for this site
    Write-Host ""
    Write-Host "${label}: $saved hymns saved, $skipped skipped." -ForegroundColor Cyan
    return $saved
}


# ---------------------------------------------------------------------------
# Show help — display usage information
# ---------------------------------------------------------------------------

function Show-Help {
    <#
    .SYNOPSIS
        Display usage information and examples to the console.

    .DESCRIPTION
        Prints a formatted help screen with parameter descriptions, usage
        examples, and output format details. Called when -Help is specified
        or from the interactive menu.
    #>

    Write-Host ""
    Write-Host "  SDAHymnals_SDAHymnal.org.ps1" -ForegroundColor White
    Write-Host "  Hymnal Scraper — SDAH + CH (no modules required)" -ForegroundColor Gray
    Write-Host "  Copyright 2025-2026 MWBM Partners Ltd." -ForegroundColor Gray
    Write-Host ""
    Write-Host "  PARAMETERS:" -ForegroundColor Yellow
    Write-Host "    -Site <string>    Which site to scrape: sdah, ch, or both (default: both)"
    Write-Host "    -Start <int>      First hymn number to scrape (default: 1)"
    Write-Host "    -End <int>        Last hymn number to scrape (default: auto-detect)"
    Write-Host "    -Output <string>  Output folder path (default: .\hymns)"
    Write-Host "    -Delay <float>    Seconds between requests (default: 1.0)"
    Write-Host "    -Force            Re-download all hymns, overwriting existing files"
    Write-Host "    -Help / -?        Show this help message"
    Write-Host ""
    Write-Host "  EXAMPLES:" -ForegroundColor Yellow
    Write-Host "    .\SDAHymnals_SDAHymnal.org.ps1                       # Both sites from hymn 1"
    Write-Host "    .\SDAHymnals_SDAHymnal.org.ps1 -Site sdah            # Only sdahymnal.org"
    Write-Host "    .\SDAHymnals_SDAHymnal.org.ps1 -Site ch              # Only hymnal.xyz"
    Write-Host "    .\SDAHymnals_SDAHymnal.org.ps1 -Start 50             # Resume from hymn 50"
    Write-Host "    .\SDAHymnals_SDAHymnal.org.ps1 -Start 1 -End 100    # Specific range"
    Write-Host "    .\SDAHymnals_SDAHymnal.org.ps1 -Output ~\Desktop\hymns"
    Write-Host "    .\SDAHymnals_SDAHymnal.org.ps1 -Delay 2.0           # Slower requests"
    Write-Host "    .\SDAHymnals_SDAHymnal.org.ps1 -Force               # Overwrite existing files"
    Write-Host ""
    Write-Host "  OUTPUT:" -ForegroundColor Yellow
    Write-Host "    Files are saved as plain text in book-specific subdirectories:"
    Write-Host "      hymns\Seventh-day Adventist Hymnal [SDAH]\001 (SDAH) - Praise To The Lord.txt"
    Write-Host "      hymns\The Church Hymnal [CH]\001 (CH) - Praise To The Lord.txt"
    Write-Host ""
    Write-Host "    The scraper is resumable — existing files are detected and skipped."
    Write-Host "    Use -Force to re-download and overwrite existing files."
    Write-Host "    Skipped hymns (errors, missing data) are logged to skipped.log."
    Write-Host ""
    Write-Host "  NOTES:" -ForegroundColor Yellow
    Write-Host "    - No modules required — uses only PowerShell built-in cmdlets."
    Write-Host "    - Both sites share the same HTML structure, so one parser handles both."
    Write-Host "    - The scraper auto-detects the end of the hymnal (no -End needed)."
    Write-Host "    - Rate limiting is handled automatically (pauses 60s, retries once)."
    Write-Host "    - Use -Force to bypass the existing-file check and re-download everything."
    Write-Host ""
}


# ---------------------------------------------------------------------------
# Interactive menu — displayed when the script is double-clicked from Explorer
# ---------------------------------------------------------------------------

function Show-InteractiveMenu {
    <#
    .SYNOPSIS
        Display an interactive menu when the script is run without CLI arguments.

    .DESCRIPTION
        When a PowerShell script is double-clicked from Windows Explorer, it
        runs without any command-line arguments. This function detects that
        scenario and presents a user-friendly menu that:
        1. Shows the script name, description, and copyright
        2. Displays help/usage information
        3. Prompts the user for parameters (site, start, end, output, delay)
        4. Offers an option to accept defaults and start immediately

        Uses Read-Host for input and Write-Host with colors for the menu.
        All input is validated before proceeding.

    .OUTPUTS
        [hashtable] with keys: Site, Start, End, Output, Delay
        Represents the user's chosen configuration parameters.
    #>

    # Clear the screen for a clean presentation
    Clear-Host

    # -----------------------------------------------------------------------
    # Header — script identification and copyright
    # -----------------------------------------------------------------------
    Write-Host ""
    Write-Host "  ================================================================" -ForegroundColor Cyan
    Write-Host "   SDAHymnals_SDAHymnal.org.ps1" -ForegroundColor White
    Write-Host "   Hymnal Scraper — SDAH + CH" -ForegroundColor Gray
    Write-Host "   Copyright 2025-2026 MWBM Partners Ltd." -ForegroundColor Gray
    Write-Host "  ================================================================" -ForegroundColor Cyan
    Write-Host ""
    Write-Host "  This scraper downloads hymn lyrics from sdahymnal.org (SDAH) and" -ForegroundColor White
    Write-Host "  hymnal.xyz (CH) and saves them as plain-text files." -ForegroundColor White
    Write-Host ""
    Write-Host "  Features:" -ForegroundColor Yellow
    Write-Host "    - Resumable: skips already-downloaded hymns"
    Write-Host "    - Auto-detects end of hymnal"
    Write-Host "    - Handles rate limiting and server errors"
    Write-Host "    - Organised output in book-specific subdirectories"
    Write-Host ""

    # -----------------------------------------------------------------------
    # Main menu — offer choices to the user
    # -----------------------------------------------------------------------
    # Track force mode state for the interactive menu (default: off).
    # Only initialise on first call — subsequent recursive calls (e.g. after
    # toggling force mode or showing help) preserve the current value.
    if ($null -eq $script:interactiveForce) {
        $script:interactiveForce = $false
    }

    Write-Host "  CURRENT SETTINGS:" -ForegroundColor Yellow
    $forceDisplay = if ($script:interactiveForce) { "ON" } else { "OFF" }
    Write-Host "    Force mode : $forceDisplay"
    Write-Host ""
    Write-Host "  OPTIONS:" -ForegroundColor Yellow
    Write-Host "    [1] Start with default settings (both sites, from hymn 1)"
    Write-Host "    [2] Configure parameters before starting"
    Write-Host "    [3] Show detailed help"
    Write-Host "    [4] Toggle force mode (currently: $forceDisplay)"
    Write-Host "    [5] Exit"
    Write-Host ""

    $choice = Read-Host "  Enter your choice (1-5)"

    switch ($choice) {
        "3" {
            # Show detailed help, then re-display the menu
            Show-Help
            Write-Host "  Press Enter to return to the menu..." -ForegroundColor Gray
            Read-Host | Out-Null
            return Show-InteractiveMenu
        }
        "4" {
            # Toggle force mode on/off and re-display the menu
            $script:interactiveForce = -not $script:interactiveForce
            $state = if ($script:interactiveForce) { "ON" } else { "OFF" }
            Write-Host ""
            Write-Host "  Force mode toggled: $state" -ForegroundColor Magenta
            Start-Sleep -Seconds 1
            return Show-InteractiveMenu
        }
        "5" {
            # User chose to exit
            Write-Host ""
            Write-Host "  Exiting. Goodbye!" -ForegroundColor Green
            Write-Host ""
            exit 0
        }
        "1" {
            # Accept all defaults — return the default configuration
            return @{
                Site   = "both"
                Start  = 1
                End    = 0
                Output = $script:DEFAULT_OUTPUT_DIR
                Delay  = $script:DEFAULT_DELAY
                Force  = $script:interactiveForce
            }
        }
        "2" {
            # Configure parameters interactively
            Write-Host ""
            Write-Host "  CONFIGURE PARAMETERS" -ForegroundColor Yellow
            Write-Host "  (Press Enter to accept the default shown in brackets)" -ForegroundColor Gray
            Write-Host ""

            # --- Site selection ---
            Write-Host "  Site to scrape:" -ForegroundColor White
            Write-Host "    sdah  = sdahymnal.org (Seventh-day Adventist Hymnal)"
            Write-Host "    ch    = hymnal.xyz (The Church Hymnal)"
            Write-Host "    both  = both sites"
            $siteInput = Read-Host "  Site [both]"
            if ([string]::IsNullOrWhiteSpace($siteInput)) { $siteInput = "both" }
            $siteInput = $siteInput.ToLower().Trim()
            # Validate the site input
            if ($siteInput -notin @("sdah", "ch", "both")) {
                Write-Host "  Invalid site '$siteInput'. Using default: both" -ForegroundColor Red
                $siteInput = "both"
            }

            # --- Start hymn number ---
            $startInput = Read-Host "  Start hymn number [1]"
            if ([string]::IsNullOrWhiteSpace($startInput)) { $startInput = "1" }
            $startNum = 0
            if (-not [int]::TryParse($startInput, [ref]$startNum) -or $startNum -lt 1) {
                Write-Host "  Invalid start number '$startInput'. Using default: 1" -ForegroundColor Red
                $startNum = 1
            }

            # --- End hymn number ---
            $endInput = Read-Host "  End hymn number [auto-detect]"
            $endNum = 0
            if (-not [string]::IsNullOrWhiteSpace($endInput)) {
                if (-not [int]::TryParse($endInput, [ref]$endNum) -or $endNum -lt 1) {
                    Write-Host "  Invalid end number '$endInput'. Using auto-detect." -ForegroundColor Red
                    $endNum = 0
                }
            }

            # --- Output directory ---
            $outputInput = Read-Host "  Output directory [.\hymns]"
            if ([string]::IsNullOrWhiteSpace($outputInput)) { $outputInput = $script:DEFAULT_OUTPUT_DIR }

            # --- Delay ---
            $delayInput = Read-Host "  Delay between requests in seconds [1.0]"
            $delayVal = 0.0
            if ([string]::IsNullOrWhiteSpace($delayInput)) {
                $delayVal = $script:DEFAULT_DELAY
            }
            elseif (-not [double]::TryParse($delayInput, [ref]$delayVal) -or $delayVal -lt 0) {
                Write-Host "  Invalid delay '$delayInput'. Using default: 1.0" -ForegroundColor Red
                $delayVal = $script:DEFAULT_DELAY
            }

            # --- Confirmation ---
            Write-Host ""
            Write-Host "  CONFIGURATION SUMMARY:" -ForegroundColor Yellow
            Write-Host "    Site   : $siteInput"
            Write-Host "    Start  : $startNum"
            $endDisplay = if ($endNum -gt 0) { $endNum } else { "auto-detect" }
            Write-Host "    End    : $endDisplay"
            Write-Host "    Output : $outputInput"
            Write-Host "    Delay  : $delayVal seconds"
            $forceDisplay = if ($script:interactiveForce) { "ON" } else { "OFF" }
            Write-Host "    Force  : $forceDisplay"
            Write-Host ""
            $confirm = Read-Host "  Proceed with these settings? (Y/n)"
            if ($confirm -match "^[nN]") {
                # User declined — re-show the menu
                return Show-InteractiveMenu
            }

            return @{
                Site   = $siteInput
                Start  = $startNum
                End    = $endNum
                Output = $outputInput
                Delay  = $delayVal
                Force  = $script:interactiveForce
            }
        }
        default {
            # Invalid choice — re-show the menu
            Write-Host "  Invalid choice. Please enter 1, 2, 3, 4, or 5." -ForegroundColor Red
            Start-Sleep -Seconds 1
            return Show-InteractiveMenu
        }
    }
}


# ===========================================================================
# MAIN — Entry point: detect interactive vs CLI mode and start scraping
# ===========================================================================

# Show help if -Help switch was specified
if ($Help) {
    Show-Help
    exit 0
}

# ---------------------------------------------------------------------------
# Detect whether the script was invoked with CLI arguments or double-clicked.
# If no meaningful parameters were provided (all at their default "empty"
# values), we check the invocation context to decide whether to show the
# interactive menu or proceed with defaults.
#
# The param block uses "empty" sentinel defaults (empty string for strings,
# 0 for numbers) so we can distinguish "user didn't provide this parameter"
# from "user explicitly set it to the default value".
# ---------------------------------------------------------------------------

# Check if ANY parameter was explicitly provided by the user on the command line
$hasCliArgs = $PSBoundParameters.Count -gt 0

if (-not $hasCliArgs) {
    # No CLI arguments were provided. Determine if we're running interactively
    # (e.g. double-clicked from Explorer) or in a non-interactive context
    # (e.g. piped, scheduled task).
    #
    # [Environment]::UserInteractive checks if the process has a user interface.
    # This is true when double-clicked from Explorer or run from a PowerShell
    # console, but false in non-interactive contexts.
    #
    # We also check if stdin is redirected — if it is, we can't prompt for input.

    $isInteractive = [Environment]::UserInteractive

    # Additional check: if the script was launched directly (not from an existing
    # PowerShell console), the parent process will be Explorer or similar.
    # In that case, we definitely want the interactive menu.
    # If running from a PowerShell console with no args, we still show the menu
    # since the user might have just typed the script name without arguments.

    if ($isInteractive) {
        # Show the interactive menu and get user-chosen parameters
        $config = Show-InteractiveMenu

        # Apply the user's choices (including force mode from the interactive toggle)
        $Site   = $config.Site
        $Start  = $config.Start
        $End    = $config.End
        $Output = $config.Output
        $Delay  = $config.Delay
        $Force  = [switch]$config.Force
    }
    else {
        # Non-interactive context — use all defaults silently
        $Site   = "both"
        $Start  = 1
        $End    = 0
        $Output = $script:DEFAULT_OUTPUT_DIR
        $Delay  = $script:DEFAULT_DELAY
    }
}
else {
    # CLI arguments were provided — apply defaults for any parameters not specified.
    # This handles partial argument sets like: -Site sdah (with Start, End, etc. unset)
    if ([string]::IsNullOrEmpty($Site))   { $Site   = "both" }
    if ($Start -eq 0)                     { $Start  = 1 }
    # $End = 0 is the sentinel for "auto-detect" — no change needed
    if ([string]::IsNullOrEmpty($Output)) { $Output = $script:DEFAULT_OUTPUT_DIR }
    if ($Delay -eq 0)                     { $Delay  = $script:DEFAULT_DELAY }
}

# ---------------------------------------------------------------------------
# Determine which sites to scrape based on the -Site argument
# ---------------------------------------------------------------------------
$sitesToRun = if ($Site -eq "both") {
    @("sdah", "ch")
}
else {
    @($Site)
}

# Accumulator for total hymns saved across all sites
$total = 0

# Scrape each site sequentially, accumulating the total saved count.
# Pass the Force switch value as a boolean to the per-site scrape function.
foreach ($siteKey in $sitesToRun) {
    $total += Start-SiteScrape -SiteKey $siteKey `
        -StartNum $Start `
        -EndNum $End `
        -OutputDir $Output `
        -DelaySeconds $Delay `
        -ForceMode ([bool]$Force)
}

# Resolve the full absolute path for the final summary message
$outputFull = $ExecutionContext.SessionState.Path.GetUnresolvedProviderPathFromPSPath($Output)

# Print the final summary
Write-Host ""
Write-Host "All done! $total hymns total saved to: $outputFull" -ForegroundColor Green
Write-Host ""

# ---------------------------------------------------------------------------
# If the script was double-clicked (no CLI args in an interactive session),
# pause before closing so the user can read the output. Without this, the
# PowerShell window would close immediately after the script finishes.
# ---------------------------------------------------------------------------
if (-not $hasCliArgs -and [Environment]::UserInteractive) {
    Write-Host "Press Enter to exit..." -ForegroundColor Gray
    Read-Host | Out-Null
}
