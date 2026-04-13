<#
.SYNOPSIS
    MissionPraise.com.ps1
    importers/scrapers/MissionPraise.com.ps1

    Mission Praise Scraper — scrapes lyrics and downloads files from missionpraise.com.
    Copyright 2025-2026 MWBM Partners Ltd.

.DESCRIPTION
    This scraper authenticates with missionpraise.com (a WordPress-based site
    that requires a paid subscription), then crawls the paginated song index
    to discover all songs across three hymnbooks:
        - Mission Praise (MP)  — ~1000+ songs, 4-digit numbering
        - Carol Praise (CP)    — ~300 songs, 3-digit numbering
        - Junior Praise (JP)   — ~300 songs, 3-digit numbering

    For each song, it:
    1. Scrapes the lyrics from the song detail page
    2. Optionally downloads associated files (words RTF/DOC, music PDF, audio MP3)
    3. Saves everything with a consistent filename convention

    The scraper is designed to be resumable: it caches the list of existing
    files on startup and skips songs that already have saved lyrics/downloads.

    When double-clicked or run with no arguments, the script presents an
    interactive GUI menu using Write-Host with colours. It prompts for
    credentials (password masked via Read-Host -AsSecureString), offers
    sensible defaults for all other options, and lets the user accept
    defaults or customise each setting before starting.

.PARAMETER Username
    missionpraise.com login email. Prompted interactively if omitted.

.PARAMETER Password
    missionpraise.com password. Prompted securely (masked) if omitted.

.PARAMETER Output
    Output folder path. Default: ./hymns

.PARAMETER Books
    Comma-separated books to scrape: mp, cp, jp. Default: mp,cp,jp

.PARAMETER StartPage
    Index page number to start from, for resuming. Default: 1

.PARAMETER Delay
    Seconds to wait between HTTP requests. Default: 1.2

.PARAMETER NoFiles
    Skip downloading words/music/audio files (lyrics only).

.PARAMETER Debug
    Dump full HTML responses to terminal for troubleshooting.

.PARAMETER Force
    Bypass the file cache and re-download/overwrite all files, even if they
    already exist on disk. Useful when lyrics have been corrected upstream or
    when a previous run saved corrupt/incomplete files. When set, the scraper
    treats every song as new and will overwrite any existing .txt, .rtf, .pdf,
    .mp3, etc. without prompting.

.PARAMETER Song
    Scrape a single song by its number (e.g. "1270" for MP1270). When specified,
    Force mode is automatically enabled and the scraper stops after finding the
    target song. Useful for re-scraping or debugging a specific song.

.PARAMETER Help
    Show help information and exit.

.NOTES
    Authentication:
        The site uses WordPress standard login (wp-login.php) with CSRF nonces
        and may be behind a Sucuri WAF (Web Application Firewall). The login
        flow handles:
        - Extracting hidden form fields (nonces) from the login page
        - Setting proper Referer/Origin headers to pass WAF checks
        - Detecting WAF blocks, login failures, and ambiguous states
        - Cookie-based session management via Invoke-WebRequest -SessionVariable

    Dependencies:
        None — uses only built-in PowerShell cmdlets (no external modules).
        Requires PowerShell 5.1+ (Windows) or PowerShell 7+ (cross-platform).

    File output format:
        Lyrics are saved as plain-text files:
            {padded_number} ({LABEL}) - {Title Case Title}.txt
        Download files use the same base name with type suffixes:
            {base}.rtf          (words — primary download, no suffix)
            {base}_music.pdf    (music score)
            {base}_audio.mp3    (audio recording)

        Files are organised into book-specific subdirectories:
            hymns/Mission Praise [MP]/
            hymns/Carol Praise [CP]/
            hymns/Junior Praise [JP]/

.EXAMPLE
    .\MissionPraise.com.ps1 -Username you@email.com -Password secret
    Scrape all books (MP, CP, JP) with defaults.

.EXAMPLE
    .\MissionPraise.com.ps1 -Username you@email.com -Password secret -Books mp
    Scrape only Mission Praise.

.EXAMPLE
    .\MissionPraise.com.ps1 -Username you@email.com -Password secret -Books mp,cp
    Scrape Mission Praise + Carol Praise.

.EXAMPLE
    .\MissionPraise.com.ps1 -Username you@email.com -Password secret -NoFiles
    Scrape lyrics only (skip downloads).

.EXAMPLE
    .\MissionPraise.com.ps1 -Username you@email.com -Password secret -StartPage 5
    Resume from index page 5.

.EXAMPLE
    .\MissionPraise.com.ps1 -Username you@email.com -Password secret -Output ~/hymns
    Save to a custom output folder.

.EXAMPLE
    .\MissionPraise.com.ps1 -Username you@email.com -Password secret -Debug
    Dump HTML responses for debugging.

.EXAMPLE
    .\MissionPraise.com.ps1 -Username you@email.com -Password secret -Force
    Re-download and overwrite ALL files, ignoring the existing-file cache.

.EXAMPLE
    .\MissionPraise.com.ps1 -Username you@email.com -Password secret -Force -Books mp
    Force re-scrape Mission Praise only.

.EXAMPLE
    .\MissionPraise.com.ps1 -Username you@email.com -Password secret -Song 1270 -Books mp
    Scrape only song MP1270 (auto-enables Force mode, stops after finding it).
#>

# ---------------------------------------------------------------------------
# Parameter block — PowerShell named parameters with defaults
# ---------------------------------------------------------------------------
# CmdletBinding enables -Verbose, -ErrorAction, etc. automatically.
# The "Help" parameter is handled manually to provide a custom menu.
[CmdletBinding()]
param(
    [string]$Username,          # missionpraise.com login email (prompted if omitted)
    [string]$Password,          # missionpraise.com password (prompted securely if omitted)
    [string]$Output = "./hymns",# Output folder path (default: ./hymns)
    [string]$Books = "mp,cp,jp",# Comma-separated books to scrape
    [int]$StartPage = 1,        # Index page to start from (for resuming)
    [double]$Delay = 1.2,       # Seconds between HTTP requests
    [switch]$NoFiles,           # Skip downloading words/music/audio files
    [switch]$Debug,             # Dump HTML responses for troubleshooting
    [switch]$Force,             # Bypass file cache — re-download and overwrite all existing files
    [string]$Song,              # Scrape a single song by number (e.g. "1270") — auto-enables Force mode
    [Alias("?")]
    [switch]$Help               # Show help and exit
)

# ---------------------------------------------------------------------------
# Force TLS 1.2 — required for HTTPS connections on older PowerShell versions
# ---------------------------------------------------------------------------
# PowerShell 5.1 on Windows may default to TLS 1.0/1.1 which most modern
# servers reject. Force TLS 1.2 to ensure secure connections work.
[System.Net.ServicePointManager]::SecurityProtocol = [System.Net.SecurityProtocolType]::Tls12

# ---------------------------------------------------------------------------
# Constants — URLs, timing, and global configuration
# ---------------------------------------------------------------------------

# Base URL for the Mission Praise website
$script:BASE = "https://missionpraise.com"

# WordPress standard login endpoint
$script:LOGIN_URL = "$($script:BASE)/wp-login.php"

# Paginated song index URL — append page number (e.g. /songs/page/1/)
$script:INDEX_URL = "$($script:BASE)/songs/page/"

# Default delay between HTTP requests (seconds). 1.2s is chosen to be
# respectful to the server while keeping scraping at a reasonable speed.
$script:DEFAULT_DELAY = 1.2

# Default output directory (relative to where the script is run)
$script:DEFAULT_OUT = "./hymns"

# Global debug flag — set to $true via -Debug parameter to dump HTML
# responses for troubleshooting login/parsing issues
$script:DEBUG_MODE = $false

# Global web session variable — holds cookies for authenticated requests.
# Initialised during login via Invoke-WebRequest -SessionVariable.
$script:WebSession = $null

# ---------------------------------------------------------------------------
# Book configuration — defines the three hymnbooks scraped from the site
# ---------------------------------------------------------------------------
# Each book has:
#   Label:   Short identifier used in filenames (e.g. "MP", "CP", "JP")
#   Pad:     Number of digits to zero-pad the hymn number to (MP=4, others=3)
#   Pattern: Regex to extract the book number from the index page title
#            e.g. "Amazing Grace (MP0023)" -> matches "(MP0023)" -> group(1) = "0023"
#   Subdir:  Human-readable subdirectory name for organised file output
$script:BOOK_CONFIG = @{
    "mp" = @{ Label = "MP"; Pad = 4; Pattern = '\(MP(\d+)\)'; Subdir = "Mission Praise [MP]" }
    "cp" = @{ Label = "CP"; Pad = 3; Pattern = '\(CP(\d+)\)'; Subdir = "Carol Praise [CP]" }
    "jp" = @{ Label = "JP"; Pad = 3; Pattern = '\(JP(\d+)\)'; Subdir = "Junior Praise [JP]" }
}

# ---------------------------------------------------------------------------
# MIME type -> file extension mapping for downloaded files
# ---------------------------------------------------------------------------
# When downloading files (words, music, audio), the server's Content-Type
# header tells us what format the file is in. This mapping converts common
# MIME types to their standard file extensions.
# "application/octet-stream" is a generic binary type — we fall back to
# guessing the extension from the URL or magic bytes in that case.
$script:MIME_TO_EXT = @{
    "application/rtf"          = ".rtf"     # Rich Text Format (words)
    "text/rtf"                 = ".rtf"     # Alternate RTF MIME type
    "application/msword"       = ".doc"     # Microsoft Word (legacy)
    "application/vnd.openxmlformats-officedocument.wordprocessingml.document" = ".docx"
    "application/pdf"          = ".pdf"     # PDF (music scores)
    "audio/midi"               = ".mid"     # MIDI audio
    "audio/x-midi"             = ".mid"     # Alternate MIDI MIME type
    "audio/mpeg"               = ".mp3"     # MP3 audio
    "audio/mp3"                = ".mp3"     # Alternate MP3 MIME type
    "audio/wav"                = ".wav"     # WAV audio
    "audio/x-wav"              = ".wav"     # Alternate WAV MIME type
    "audio/ogg"                = ".ogg"     # Ogg Vorbis audio
    "application/octet-stream" = ""         # Generic binary — fall back to URL/magic
}

# ---------------------------------------------------------------------------
# Magic bytes — file type detection from binary content
# ---------------------------------------------------------------------------
# When the Content-Type header is unhelpful (e.g. "application/octet-stream")
# and the URL doesn't reveal the file type, we can identify the format by
# examining the first few bytes of the file content. Most file formats begin
# with a distinctive "magic number" or signature.
$script:MAGIC_BYTES = @(
    @{ Bytes = [byte[]]@(0x25, 0x50, 0x44, 0x46);                     Ext = ".pdf"  }  # %PDF
    @{ Bytes = [byte[]]@(0x50, 0x4B, 0x03, 0x04);                     Ext = ".docx" }  # PK (ZIP-based: docx, xlsx, pptx)
    @{ Bytes = [byte[]]@(0xD0, 0xCF, 0x11, 0xE0);                     Ext = ".doc"  }  # OLE2 compound (doc, xls, ppt)
    @{ Bytes = [byte[]]@(0x7B, 0x5C, 0x72, 0x74, 0x66);               Ext = ".rtf"  }  # {\rtf — Rich Text Format
    @{ Bytes = [byte[]]@(0x4D, 0x54, 0x68, 0x64);                     Ext = ".mid"  }  # MThd — MIDI
    @{ Bytes = [byte[]]@(0x49, 0x44, 0x33);                           Ext = ".mp3"  }  # ID3 — MP3 with ID3v2 tag
    @{ Bytes = [byte[]]@(0xFF, 0xFB);                                 Ext = ".mp3"  }  # MP3 frame sync (MPEG1 Layer 3)
    @{ Bytes = [byte[]]@(0xFF, 0xF3);                                 Ext = ".mp3"  }  # MP3 frame sync (MPEG2 Layer 3)
    @{ Bytes = [byte[]]@(0x4F, 0x67, 0x67, 0x53);                     Ext = ".ogg"  }  # OggS — Ogg Vorbis
    @{ Bytes = [byte[]]@(0x52, 0x49, 0x46, 0x46);                     Ext = ".wav"  }  # RIFF — WAV audio
    @{ Bytes = [byte[]]@(0x66, 0x4C, 0x61, 0x43);                     Ext = ".flac" }  # fLaC — FLAC lossless audio
)

# ---------------------------------------------------------------------------
# Browser-like headers — sent with every request to avoid WAF blocks
# ---------------------------------------------------------------------------
# The headers are modelled after a real Chrome/Edge browser on macOS,
# including Sec-Fetch-* headers that modern WAFs check to distinguish
# legitimate browser requests from automated scripts.
$script:BrowserHeaders = @{
    "User-Agent"               = "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 Edg/145.0.0.0"
    "Accept"                   = "text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8"
    "Accept-Language"          = "en-GB,en;q=0.9"
    "Accept-Encoding"          = "gzip, deflate, br"
    "Connection"               = "keep-alive"
    "Upgrade-Insecure-Requests"= "1"
    "Sec-Fetch-Dest"           = "document"
    "Sec-Fetch-Mode"           = "navigate"
    "Sec-Fetch-Site"           = "same-origin"
    "Sec-Fetch-User"           = "?1"
    "Cache-Control"            = "max-age=0"
}


# ===========================================================================
# INTERACTIVE GUI MENU — shown when script is double-clicked or run with
# no CLI arguments (no Username/Password provided and not piped)
# ===========================================================================

function Show-InteractiveMenu {
    <#
    .SYNOPSIS
        Display a colourful interactive menu when the script is launched with
        no arguments (e.g. by double-clicking in Windows Explorer).

    .DESCRIPTION
        Presents the script name, description, copyright, and usage info,
        then prompts the user for each configurable parameter. Uses
        Write-Host with -ForegroundColor for visual appeal. Passwords are
        collected securely via Read-Host -AsSecureString.

    .OUTPUTS
        Hashtable with keys: Username, Password, Output, Books, StartPage,
        Delay, NoFiles, Debug — ready to pass to the main scraping logic.
    #>

    Clear-Host

    # --- Header banner ---
    Write-Host ""
    Write-Host "  ============================================================" -ForegroundColor Cyan
    Write-Host "   Mission Praise Scraper" -ForegroundColor Yellow
    Write-Host "   missionpraise.com — MP, CP, JP lyrics and file downloads" -ForegroundColor White
    Write-Host "  ============================================================" -ForegroundColor Cyan
    Write-Host ""
    Write-Host "  Copyright 2025-2026 MWBM Partners Ltd." -ForegroundColor DarkGray
    Write-Host "  Part of iLyricsDB — International Lyrics Database" -ForegroundColor DarkGray
    Write-Host ""

    # --- Description ---
    Write-Host "  ABOUT:" -ForegroundColor Green
    Write-Host "    This scraper authenticates with missionpraise.com and crawls" -ForegroundColor White
    Write-Host "    the paginated song index to scrape lyrics and download files" -ForegroundColor White
    Write-Host "    (words RTF/DOC, sheet music PDF, audio MP3) for:" -ForegroundColor White
    Write-Host ""
    Write-Host "      - Mission Praise (MP)  ~1000+ songs, 4-digit numbering" -ForegroundColor White
    Write-Host "      - Carol Praise   (CP)  ~300 songs,   3-digit numbering" -ForegroundColor White
    Write-Host "      - Junior Praise  (JP)  ~300 songs,   3-digit numbering" -ForegroundColor White
    Write-Host ""

    # --- Usage help ---
    Write-Host "  USAGE (command-line):" -ForegroundColor Green
    Write-Host "    .\MissionPraise.com.ps1 -Username you@email.com -Password secret" -ForegroundColor Gray
    Write-Host "    .\MissionPraise.com.ps1 -Username you@email.com -Password secret -Books mp" -ForegroundColor Gray
    Write-Host "    .\MissionPraise.com.ps1 -Username you@email.com -Password secret -NoFiles" -ForegroundColor Gray
    Write-Host "    .\MissionPraise.com.ps1 -Username you@email.com -Password secret -StartPage 5" -ForegroundColor Gray
    Write-Host "    .\MissionPraise.com.ps1 -Username you@email.com -Password secret -Force" -ForegroundColor Gray
    Write-Host ""

    # --- Credentials prompt (required) ---
    Write-Host "  ============================================================" -ForegroundColor Cyan
    Write-Host "   Configuration" -ForegroundColor Yellow
    Write-Host "  ============================================================" -ForegroundColor Cyan
    Write-Host ""
    Write-Host "  A valid missionpraise.com subscription is required." -ForegroundColor White
    Write-Host ""

    # Username — plain text input
    Write-Host "  Username/email: " -ForegroundColor Yellow -NoNewline
    $menuUsername = Read-Host
    if ([string]::IsNullOrWhiteSpace($menuUsername)) {
        Write-Host "  [!] Username is required. Exiting." -ForegroundColor Red
        Write-Host ""
        Write-Host "  Press any key to exit..." -ForegroundColor DarkGray
        $null = $Host.UI.RawUI.ReadKey("NoEcho,IncludeKeyDown")
        exit 1
    }

    # Password — masked input via Read-Host -AsSecureString
    Write-Host "  Password: " -ForegroundColor Yellow -NoNewline
    $securePass = Read-Host -AsSecureString
    # Convert SecureString back to plain text for use with Invoke-WebRequest
    # (PowerShell's web cmdlets need plain text for form POST bodies)
    $bstr = [System.Runtime.InteropServices.Marshal]::SecureStringToBSTR($securePass)
    $menuPassword = [System.Runtime.InteropServices.Marshal]::PtrToStringAuto($bstr)
    [System.Runtime.InteropServices.Marshal]::ZeroFreeBSTR($bstr)

    if ([string]::IsNullOrWhiteSpace($menuPassword)) {
        Write-Host "  [!] Password is required. Exiting." -ForegroundColor Red
        Write-Host ""
        Write-Host "  Press any key to exit..." -ForegroundColor DarkGray
        $null = $Host.UI.RawUI.ReadKey("NoEcho,IncludeKeyDown")
        exit 1
    }

    Write-Host ""

    # --- Optional settings with defaults ---
    Write-Host "  Accept defaults for remaining settings? (Y/n): " -ForegroundColor Yellow -NoNewline
    $acceptDefaults = Read-Host

    # Initialise with defaults
    $menuOutput    = $script:DEFAULT_OUT
    $menuBooks     = "mp,cp,jp"
    $menuStartPage = 1
    $menuDelay     = $script:DEFAULT_DELAY
    $menuNoFiles   = $false
    $menuDebug     = $false
    $menuForce     = $false

    if ($acceptDefaults -match "^[Nn]") {
        # User wants to customise settings — prompt for each one
        Write-Host ""

        # Books selection
        Write-Host "  Books to scrape [mp,cp,jp]: " -ForegroundColor Yellow -NoNewline
        $input_books = Read-Host
        if (-not [string]::IsNullOrWhiteSpace($input_books)) {
            $menuBooks = $input_books
        }

        # Output directory
        Write-Host "  Output directory [./hymns]: " -ForegroundColor Yellow -NoNewline
        $input_output = Read-Host
        if (-not [string]::IsNullOrWhiteSpace($input_output)) {
            $menuOutput = $input_output
        }

        # Start page
        Write-Host "  Start page [1]: " -ForegroundColor Yellow -NoNewline
        $input_start = Read-Host
        if (-not [string]::IsNullOrWhiteSpace($input_start)) {
            $menuStartPage = [int]$input_start
        }

        # Delay
        Write-Host "  Delay between requests (seconds) [1.2]: " -ForegroundColor Yellow -NoNewline
        $input_delay = Read-Host
        if (-not [string]::IsNullOrWhiteSpace($input_delay)) {
            $menuDelay = [double]$input_delay
        }

        # No-files toggle
        Write-Host "  Skip file downloads (lyrics only)? (y/N): " -ForegroundColor Yellow -NoNewline
        $input_nofiles = Read-Host
        if ($input_nofiles -match "^[Yy]") {
            $menuNoFiles = $true
        }

        # Debug toggle
        Write-Host "  Enable debug output? (y/N): " -ForegroundColor Yellow -NoNewline
        $input_debug = Read-Host
        if ($input_debug -match "^[Yy]") {
            $menuDebug = $true
        }

        # Force toggle — re-download everything, ignoring existing files
        Write-Host "  Force re-download (overwrite existing)? (y/N): " -ForegroundColor Yellow -NoNewline
        $input_force = Read-Host
        if ($input_force -match "^[Yy]") {
            $menuForce = $true
        }
    }

    # --- Confirm and start ---
    Write-Host ""
    Write-Host "  ============================================================" -ForegroundColor Cyan
    Write-Host "   Configuration Summary" -ForegroundColor Yellow
    Write-Host "  ============================================================" -ForegroundColor Cyan
    Write-Host "    Username  : $menuUsername" -ForegroundColor White
    Write-Host "    Password  : ********" -ForegroundColor White
    Write-Host "    Books     : $($menuBooks.ToUpper())" -ForegroundColor White
    Write-Host "    Output    : $menuOutput" -ForegroundColor White
    Write-Host "    Start page: $menuStartPage" -ForegroundColor White
    Write-Host "    Delay     : $menuDelay seconds" -ForegroundColor White
    Write-Host "    Downloads : $(if ($menuNoFiles) { 'disabled' } else { 'enabled (words, music, audio)' })" -ForegroundColor White
    Write-Host "    Force     : $(if ($menuForce) { 'ON -- will overwrite existing files' } else { 'off' })" -ForegroundColor $(if ($menuForce) { 'Red' } else { 'White' })
    Write-Host "    Debug     : $(if ($menuDebug) { 'enabled' } else { 'disabled' })" -ForegroundColor White
    Write-Host ""
    Write-Host "  Press ENTER to start scraping, or Ctrl+C to cancel..." -ForegroundColor Green -NoNewline
    Read-Host
    Write-Host ""

    # Return the configuration as a hashtable
    return @{
        Username  = $menuUsername
        Password  = $menuPassword
        Output    = $menuOutput
        Books     = $menuBooks
        StartPage = $menuStartPage
        Delay     = $menuDelay
        NoFiles   = $menuNoFiles
        Debug     = $menuDebug
        Force     = $menuForce
    }
}


# ===========================================================================
# DEBUG HELPER — conditional HTML dump for troubleshooting
# ===========================================================================

function Write-DebugDump {
    <#
    .SYNOPSIS
        Print a labelled debug dump of HTML/text content (only when DEBUG_MODE is $true).

    .DESCRIPTION
        Used during development and troubleshooting to inspect raw HTML responses
        from the server. Truncates output to MaxChars to avoid flooding the terminal.

    .PARAMETER Label
        A descriptive label for what's being dumped.

    .PARAMETER Text
        The text content to dump.

    .PARAMETER MaxChars
        Maximum characters to display. Default: 3000.
    #>
    param(
        [string]$Label,
        [string]$Text,
        [int]$MaxChars = 3000
    )

    # Only output when debug mode is active
    if (-not $script:DEBUG_MODE) { return }

    Write-Host ""
    Write-Host ("=" * 60)
    Write-Host "DEBUG: $Label"
    Write-Host ("=" * 60)

    if ($Text.Length -le $MaxChars) {
        Write-Host $Text
    }
    else {
        Write-Host $Text.Substring(0, $MaxChars)
        Write-Host "... ($($Text.Length - $MaxChars) more chars)"
    }

    Write-Host ("=" * 60)
    Write-Host ""
}


# ===========================================================================
# HTML ENTITY DECODING — convert &amp; &rsquo; &#8217; etc. to characters
# ===========================================================================

function ConvertFrom-HtmlEntities {
    <#
    .SYNOPSIS
        Decode HTML entities in a string to their Unicode character equivalents.

    .DESCRIPTION
        Handles both named entities (e.g. &amp;, &rsquo;, &nbsp;) and numeric
        character references (e.g. &#8217;, &#x2019;). Common typographic
        entities found in song lyrics include smart quotes and dashes.

        Uses .NET's System.Net.WebUtility for standard entities and adds
        manual handling for entities that .NET may miss.

    .PARAMETER Text
        The HTML text containing entities to decode.

    .OUTPUTS
        String with HTML entities replaced by their Unicode characters.
    #>
    param([string]$Text)

    if ([string]::IsNullOrEmpty($Text)) { return $Text }

    # Windows-1252 entity mapping — the site uses &#145;–&#151; which are
    # Windows-1252 code points, NOT Unicode. chr(146) etc. produce control
    # characters, not the intended typographic glyphs. We must map them
    # explicitly BEFORE the generic .NET decoder runs.
    # Reference: https://en.wikipedia.org/wiki/Windows-1252#Character_set
    $Text = $Text -replace '&#145;', [char]0x2018   # Left single quote  (')
    $Text = $Text -replace '&#146;', [char]0x2019   # Right single quote (') / apostrophe
    $Text = $Text -replace '&#147;', [char]0x201C   # Left double quote  (")
    $Text = $Text -replace '&#148;', [char]0x201D   # Right double quote (")
    $Text = $Text -replace '&#150;', [char]0x2013   # En dash (–)
    $Text = $Text -replace '&#151;', [char]0x2014   # Em dash (—)

    # Use .NET's built-in HTML decoder for standard entities
    # This handles &amp; &lt; &gt; &quot; &#8217; &#x2019; etc.
    $decoded = [System.Net.WebUtility]::HtmlDecode($Text)

    # Manual fallback for entities that .NET's decoder may not handle
    # (some WordPress-specific or less common entities)
    $decoded = $decoded -replace '&rsquo;', [char]0x2019   # Right single quote
    $decoded = $decoded -replace '&lsquo;', [char]0x2018   # Left single quote
    $decoded = $decoded -replace '&rdquo;', [char]0x201D   # Right double quote
    $decoded = $decoded -replace '&ldquo;', [char]0x201C   # Left double quote
    $decoded = $decoded -replace '&mdash;', [char]0x2014   # Em dash
    $decoded = $decoded -replace '&ndash;', [char]0x2013   # En dash
    $decoded = $decoded -replace '&nbsp;',  ' '             # Non-breaking space

    return $decoded
}


# ===========================================================================
# LOGIN — WordPress authentication with CSRF nonce extraction
# ===========================================================================

function Invoke-Login {
    <#
    .SYNOPSIS
        Authenticate with missionpraise.com using WordPress standard login.

    .DESCRIPTION
        The login flow is:
        1. GET the login page to obtain CSRF nonces (hidden form fields)
        2. Brief pause (2s) to appear more human-like
        3. POST credentials + nonces with proper Referer/Origin headers
        4. Handle various failure modes (WAF blocks, wrong password, etc.)
        5. Verify login succeeded by fetching the songs page and checking
           for logged-in indicators

        The function is defensive about error detection because:
        - The Sucuri WAF can block requests that look automated
        - WordPress login failures manifest in different ways (redirect
          back to login, error div, etc.)
        - The login page structure may vary between WordPress versions

    .PARAMETER Username
        The user's missionpraise.com email address.

    .PARAMETER Password
        The user's missionpraise.com password.

    .OUTPUTS
        Boolean: $true if login appears successful, $false if definitely failed.
    #>
    param(
        [string]$Username,
        [string]$Password
    )

    Write-Host "Logging in as $Username..."

    # --- Step 1: GET the login page to extract CSRF nonces ---
    # WordPress login forms contain hidden fields (nonces) that must be
    # submitted with the POST request to prevent CSRF attacks.
    try {
        $loginPageResponse = Invoke-WebRequest -Uri $script:LOGIN_URL `
            -Headers $script:BrowserHeaders `
            -SessionVariable "loginSession" `
            -UseBasicParsing `
            -MaximumRedirection 5 `
            -ErrorAction Stop
    }
    catch {
        Write-Host "  [!] Failed to load login page: $_" -ForegroundColor Red
        return $false
    }

    # Store the session for subsequent requests (holds all cookies)
    $script:WebSession = $loginSession
    $loginHtml = $loginPageResponse.Content

    Write-DebugDump -Label "Login page HTML" -Text $loginHtml

    # Extract all <input type="hidden"> fields from the login form.
    # These typically include:
    #   - testcookie: WordPress test cookie check
    #   - _wpnonce: WordPress CSRF nonce
    #   - Various plugin-specific nonces
    $hiddenFields = @{}
    $hiddenMatches = [regex]::Matches($loginHtml, '<input[^>]+type=["\x27]hidden["\x27][^>]*>', [System.Text.RegularExpressions.RegexOptions]::IgnoreCase)
    foreach ($match in $hiddenMatches) {
        $tag = $match.Value
        # Extract the name attribute
        $nameMatch = [regex]::Match($tag, 'name=["\x27]([^"\x27]+)["\x27]')
        # Extract the value attribute
        $valueMatch = [regex]::Match($tag, 'value=["\x27]([^"\x27]*)["\x27]')
        if ($nameMatch.Success) {
            $fieldName  = $nameMatch.Groups[1].Value
            $fieldValue = if ($valueMatch.Success) { $valueMatch.Groups[1].Value } else { "" }
            $hiddenFields[$fieldName] = $fieldValue
        }
    }

    if ($script:DEBUG_MODE) {
        Write-Host "  DEBUG: Hidden fields: $($hiddenFields | ConvertTo-Json -Compress)"
    }

    # --- Step 2: Brief pause to appear more human-like ---
    # Some WAFs flag requests that POST to the login form within milliseconds
    # of loading the page, as this is a strong indicator of automated access.
    Start-Sleep -Seconds 2

    # --- Step 3: POST the login credentials ---
    # Build the login form data with WordPress standard field names
    $formData = @{
        "log"         = $Username                  # WordPress username field
        "pwd"         = $Password                  # WordPress password field
        "wp-submit"   = "Log In"                   # Submit button value
        "redirect_to" = "$($script:BASE)/songs/"   # Where to redirect after login
        "rememberme"  = "forever"                  # Keep the session alive
    }

    # Merge hidden fields (CSRF nonces) into the form data
    foreach ($key in $hiddenFields.Keys) {
        $formData[$key] = $hiddenFields[$key]
    }

    # Build additional POST headers — Referer and Origin are critical for
    # passing the Sucuri WAF check
    $postHeaders = $script:BrowserHeaders.Clone()
    $postHeaders["Referer"]      = $script:LOGIN_URL
    $postHeaders["Origin"]       = $script:BASE
    $postHeaders["Content-Type"] = "application/x-www-form-urlencoded"

    # Perform the POST request
    $body = $null
    $finalUrl = ""
    try {
        $postResponse = Invoke-WebRequest -Uri $script:LOGIN_URL `
            -Method POST `
            -Body $formData `
            -Headers $postHeaders `
            -WebSession $script:WebSession `
            -UseBasicParsing `
            -MaximumRedirection 10 `
            -ErrorAction Stop

        $finalUrl = $postResponse.BaseResponse.ResponseUri.ToString()
        $body = $postResponse.Content
    }
    catch {
        # Some servers return HTTP errors (403, etc.) for blocked login attempts.
        # We still need to read the response body to check for WAF messages.
        $finalUrl = ""
        if ($_.Exception.Response) {
            try {
                $stream = $_.Exception.Response.GetResponseStream()
                $reader = New-Object System.IO.StreamReader($stream)
                $body = $reader.ReadToEnd()
                $reader.Close()
                $stream.Close()
            }
            catch {
                $body = ""
            }
        }
        else {
            $body = ""
        }
    }

    if ($script:DEBUG_MODE) {
        Write-Host "  DEBUG: Post-login redirect URL: $finalUrl"
        Write-DebugDump -Label "Post-login body" -Text $body
    }

    # --- Step 4: Check for various failure modes ---

    # Check for WAF / firewall blocks (e.g. Sucuri CDN/WAF)
    # Sucuri blocks show a distinctive page with "Access Denied" and details
    # about the block reason and the client's IP address.
    $bodyLower = $body.ToLower()
    if ($bodyLower.Contains("sucuri") -or $bodyLower.Contains("access denied")) {
        $blockReason = [regex]::Match($body, 'Block reason:</td>\s*<td><span>(.*?)</span>', [System.Text.RegularExpressions.RegexOptions]::Singleline)
        $blockIp     = [regex]::Match($body, 'Your IP:</td>\s*<td><span>(.*?)</span>', [System.Text.RegularExpressions.RegexOptions]::Singleline)
        $reason = if ($blockReason.Success) { $blockReason.Groups[1].Value.Trim() } else { "unknown" }
        $ip     = if ($blockIp.Success)     { $blockIp.Groups[1].Value.Trim() }     else { "unknown" }
        Write-Host "  [!] BLOCKED by website firewall: $reason" -ForegroundColor Red
        Write-Host "       Your IP: $ip" -ForegroundColor Red
        Write-Host "       Try again later or log in via browser first to whitelist your IP." -ForegroundColor Yellow
        return $false
    }

    # Check for incorrect credentials — WordPress stays on wp-login.php
    # and includes an error message in the response body
    if ($finalUrl.Contains("wp-login.php") -and $bodyLower.Contains("incorrect")) {
        Write-Host "  [!] Login failed -- check username/password." -ForegroundColor Red
        return $false
    }

    # Check for other WordPress login errors (still on login page after POST)
    if ($finalUrl.Contains("wp-login.php")) {
        if ($script:DEBUG_MODE) {
            Write-Host "  DEBUG: Still on login page after POST -- login likely failed"
        }
        # Look for the WordPress login error div which contains specific messages
        # like "Unknown username", "Incorrect password", etc.
        $errorMatch = [regex]::Match($body, '<div[^>]*id=["\x27]login_error["\x27][^>]*>(.*?)</div>', [System.Text.RegularExpressions.RegexOptions]::Singleline)
        if ($errorMatch.Success) {
            # Strip HTML tags from the error message for clean display
            $errorText = [regex]::Replace($errorMatch.Groups[1].Value, '<[^>]+>', '').Trim()
            Write-Host "  [!] Login error: $errorText" -ForegroundColor Red
            return $false
        }
    }

    # --- Step 5: Verify login by fetching the songs page ---
    # Even if the POST didn't show an error, we verify by checking if the
    # songs page shows logged-in indicators (some sites silently fail login).
    try {
        $checkResponse = Invoke-WebRequest -Uri "$($script:BASE)/songs/" `
            -Headers $script:BrowserHeaders `
            -WebSession $script:WebSession `
            -UseBasicParsing `
            -MaximumRedirection 5 `
            -ErrorAction Stop

        $checkHtml = $checkResponse.Content
        $checkUrl  = $checkResponse.BaseResponse.ResponseUri.ToString()
    }
    catch {
        Write-Host "  [!] Failed to verify login -- could not load songs page." -ForegroundColor Red
        return $false
    }

    if ($script:DEBUG_MODE) {
        Write-Host "  DEBUG: Songs page URL: $checkUrl"
        Write-DebugDump -Label "Songs page HTML" -Text $checkHtml
    }

    # Look for multiple indicators of being logged in — different WordPress
    # themes show different indicators, so we check several possibilities
    $checkLower = $checkHtml.ToLower()
    $loggedIn = (
        $checkLower.Contains("logout") -or
        $checkLower.Contains("log out") -or
        $checkLower.Contains("log-out") -or
        $checkHtml.Contains("Welcome") -or
        $checkLower.Contains("my-account") -or
        $checkLower.Contains("wp-admin") -or
        $checkLower.Contains("logged-in")
    )

    if ($loggedIn) {
        Write-Host "  [+] Logged in." -ForegroundColor Green
        return $true
    }

    # Check if we were redirected back to the login page (session not established)
    if ($checkUrl.Contains("wp-login") -or $checkUrl.Contains("login")) {
        Write-Host "  [!] Login failed -- redirected back to login page." -ForegroundColor Red
        return $false
    }

    # Login status is ambiguous — proceed anyway (some themes don't show
    # obvious logged-in indicators). The user can re-run with -Debug to investigate.
    Write-Host "  [?] Login status unclear -- proceeding anyway." -ForegroundColor Yellow
    Write-Host "       (Re-run with -Debug to see full HTML responses)" -ForegroundColor Yellow
    return $true
}


# ===========================================================================
# FETCH HELPERS — HTTP request and response handling utilities
# ===========================================================================

function Invoke-FetchText {
    <#
    .SYNOPSIS
        Fetch a URL and return its text content, handling errors gracefully.

    .PARAMETER Url
        The URL to fetch.

    .OUTPUTS
        Array of two elements: [string]$Content, [string]$FinalUrl
        Returns $null, $null on any error.
    #>
    param([string]$Url)

    try {
        $response = Invoke-WebRequest -Uri $Url `
            -Headers $script:BrowserHeaders `
            -WebSession $script:WebSession `
            -UseBasicParsing `
            -TimeoutSec 20 `
            -MaximumRedirection 5 `
            -ErrorAction Stop

        $content  = $response.Content
        $finalUrl = $response.BaseResponse.ResponseUri.ToString()

        return @($content, $finalUrl)
    }
    catch {
        Write-Host "`n  [!] Error fetching ${Url}: $_" -ForegroundColor Red
        return @($null, $null)
    }
}


function Invoke-FetchBinary {
    <#
    .SYNOPSIS
        Download a binary file (words/music/audio) from a URL.

    .DESCRIPTION
        Includes several safety checks:
        1. Detects server error pages masquerading as file downloads — some
           servers return HTML error pages with a 200 status code when the
           actual file is missing or access is denied.

        The error detection heuristic checks if the response:
        - Starts with common text/HTML bytes ('<', 's', 'e', '{')
        - Is small enough to be an error page (< 500KB)
        - Contains error-related keywords when decoded as UTF-8

    .PARAMETER Url
        The download URL.

    .OUTPUTS
        Array of three elements: [byte[]]$Data, [string]$ContentType, [string]$FinalUrl
        Returns $null, $null, $null on any error.
    #>
    param([string]$Url)

    try {
        # Use Invoke-WebRequest to download raw bytes
        $response = Invoke-WebRequest -Uri $Url `
            -Headers $script:BrowserHeaders `
            -WebSession $script:WebSession `
            -UseBasicParsing `
            -TimeoutSec 30 `
            -MaximumRedirection 5 `
            -ErrorAction Stop

        # Extract the MIME type from Content-Type (strip charset parameter)
        $ct = ""
        $contentTypeHeader = $response.Headers["Content-Type"]
        if ($contentTypeHeader) {
            # Handle both string and string[] (PowerShell version differences)
            $ctRaw = if ($contentTypeHeader -is [array]) { $contentTypeHeader[0] } else { $contentTypeHeader }
            $ct = $ctRaw.Split(";")[0].Trim().ToLower()
        }

        # Get the raw bytes of the response
        $raw = $response.Content
        # Invoke-WebRequest may return string for text content types; convert if needed
        if ($raw -is [string]) {
            $raw = [System.Text.Encoding]::UTF8.GetBytes($raw)
        }

        $finalUrl = $response.BaseResponse.ResponseUri.ToString()

        # --- Error page detection ---
        # Some servers return HTML error pages (PHP exceptions, 404 pages, etc.)
        # with a 200 status code instead of the actual file. We detect these by
        # checking if the content looks like text/HTML rather than binary data.
        if ($raw.Length -gt 0 -and $raw.Length -lt 500000) {
            $firstByte = $raw[0]
            # Check if first byte looks like text: '<' (0x3C), 's' (0x73), 'e' (0x65), '{' (0x7B)
            if ($firstByte -eq 0x3C -or $firstByte -eq 0x73 -or $firstByte -eq 0x65 -or $firstByte -eq 0x7B) {
                try {
                    $textContent = [System.Text.Encoding]::UTF8.GetString($raw)
                    $textLower = $textContent.ToLower()
                    if ($textLower.Contains("exception") -or $textLower.Contains("<html") -or
                        $textLower.Contains("<!doctype") -or $textLower.Contains("error") -or
                        $textLower.Contains("not found") -or $textLower.Contains("string(") -or
                        $textLower.Contains("nosuchkey") -or $textLower.Contains("stacktrace")) {
                        Write-Host "server error " -NoNewline
                        if ($script:DEBUG_MODE) {
                            Write-DebugDump -Label "Server error in download" -Text $textContent -MaxChars 500
                        }
                        return @($null, $null, $null)
                    }
                }
                catch {
                    # Not valid UTF-8 -> genuinely binary data, carry on
                }
            }
        }

        return @($raw, $ct, $finalUrl)
    }
    catch {
        Write-Host "`n  [!] Download error ${Url}: $_" -ForegroundColor Red
        return @($null, $null, $null)
    }
}


# ===========================================================================
# FILE EXTENSION DETECTION — cascade of Content-Type, URL, magic bytes
# ===========================================================================

function Get-ExtFromUrl {
    <#
    .SYNOPSIS
        Guess the file extension from a URL's path component.

    .DESCRIPTION
        Parses the URL to extract the path, then gets the extension from the
        last path segment. Server-side script extensions (.php, .asp, etc.)
        are ignored because they indicate dynamic download endpoints rather
        than actual file types.

    .PARAMETER Url
        The download URL to extract the extension from.

    .OUTPUTS
        String: The lowercase file extension (e.g. ".rtf") or "" if none found.
    #>
    param([string]$Url)

    try {
        $uri  = [System.Uri]::new($Url)
        $path = $uri.AbsolutePath
        $ext  = [System.IO.Path]::GetExtension($path).ToLower()

        # Ignore server-side script extensions — these are dynamic endpoints
        # that serve files, not the actual file extension
        if ($ext -in @(".php", ".asp", ".aspx", ".jsp", ".cgi", ".py")) {
            return ""
        }
        return $ext
    }
    catch {
        return ""
    }
}


function Get-ExtFromMagic {
    <#
    .SYNOPSIS
        Detect file type from the first few bytes of binary data (magic bytes).

    .DESCRIPTION
        Compares the file's opening bytes against known signatures for common
        document and audio formats. This is the last-resort detection method
        when Content-Type and URL are both unhelpful.

    .PARAMETER Data
        The raw bytes of the downloaded file (at least 4 bytes needed).

    .OUTPUTS
        String: The detected file extension (e.g. ".pdf") or "" if not recognised.
    #>
    param([byte[]]$Data)

    # Need at least 4 bytes for reliable magic number matching
    if (-not $Data -or $Data.Length -lt 4) {
        return ""
    }

    # Compare file header bytes against known magic byte signatures
    foreach ($magic in $script:MAGIC_BYTES) {
        $magicBytes = $magic.Bytes
        $matchLen = $magicBytes.Length

        # Ensure we have enough bytes to compare
        if ($Data.Length -lt $matchLen) { continue }

        # Compare each byte in the signature
        $isMatch = $true
        for ($i = 0; $i -lt $matchLen; $i++) {
            if ($Data[$i] -ne $magicBytes[$i]) {
                $isMatch = $false
                break
            }
        }

        if ($isMatch) {
            return $magic.Ext
        }
    }

    return ""
}


function Get-ExtForDownload {
    <#
    .SYNOPSIS
        Determine the correct file extension for a download using a cascade strategy.

    .DESCRIPTION
        Tries three methods in order of reliability:
        1. MIME type from the Content-Type header (most reliable)
        2. File extension from the URL path (fallback)
        3. Magic bytes from the file content (last resort)

        If none of the methods identify the file type, defaults to ".bin" to
        ensure the file is still saved (can be manually identified later).

    .PARAMETER ContentType
        The Content-Type header value (e.g. "application/pdf").

    .PARAMETER Url
        The download URL.

    .PARAMETER Data
        The raw file bytes (optional, for magic byte detection).

    .OUTPUTS
        String: The file extension including the dot (e.g. ".pdf", ".rtf", ".bin").
    #>
    param(
        [string]$ContentType,
        [string]$Url,
        [byte[]]$Data = $null
    )

    # Strategy 1: Check the MIME type lookup table
    $ext = ""
    if ($script:MIME_TO_EXT.ContainsKey($ContentType)) {
        $ext = $script:MIME_TO_EXT[$ContentType]
    }

    # Strategy 2: Extract extension from the URL path
    if (-not $ext) {
        $ext = Get-ExtFromUrl -Url $Url
    }

    # Strategy 3: Detect from magic bytes in the file content
    if (-not $ext -and $Data) {
        $ext = Get-ExtFromMagic -Data $Data
    }

    # Fallback: use .bin so the file is still saved for manual inspection
    if (-not $ext) { $ext = ".bin" }

    return $ext
}


# ===========================================================================
# INDEX PAGE PARSER — extract song links from paginated index pages
# ===========================================================================

function Parse-IndexPage {
    <#
    .SYNOPSIS
        Parse a paginated song index page for song links.

    .DESCRIPTION
        The index page lists songs as links within heading elements. Each song
        appears as an <a> tag inside an <h2> or <h3>, with the title text
        including the book code (e.g. "Amazing Grace (MP0023)").

        Uses regex to match the HTML structure:
            <h2><a href="/songs/amazing-grace-mp0023/">Amazing Grace (MP0023)</a></h2>

        This is equivalent to the Python IndexParser class but implemented
        with regex instead of an HTML parser state machine.

    .PARAMETER Html
        The raw HTML content of the index page.

    .OUTPUTS
        Array of PSCustomObjects with Title and Url properties.
    #>
    param([string]$Html)

    $songs = @()

    # Match <h2> or <h3> elements containing <a> tags with /songs/ in the href.
    # The regex captures:
    #   Group 1: The href URL
    #   Group 2: The link text (song title)
    # The [\s\S]*? allows for attributes between the h tag and the a tag.
    $pattern = '<h[23][^>]*>[\s\S]*?<a[^>]+href=["\x27]([^"\x27]*?/songs/[^"\x27]*?)["\x27][^>]*>([\s\S]*?)</a>[\s\S]*?</h[23]>'
    $matches = [regex]::Matches($Html, $pattern, [System.Text.RegularExpressions.RegexOptions]::IgnoreCase)

    foreach ($m in $matches) {
        $href  = $m.Groups[1].Value.Trim()
        # Strip any nested HTML tags from the title text (e.g. <span> wrappers)
        $title = [regex]::Replace($m.Groups[2].Value, '<[^>]+>', '').Trim()
        # Decode HTML entities in the title (e.g. &amp; -> &)
        $title = ConvertFrom-HtmlEntities -Text $title

        if ($href -and $title) {
            $songs += [PSCustomObject]@{
                Title = $title
                Url   = $href
            }
        }
    }

    return $songs
}


# ===========================================================================
# SONG PAGE PARSER — extract title, lyrics, copyright, download links
# ===========================================================================

function Parse-SongPage {
    <#
    .SYNOPSIS
        Parse an individual song detail page for lyrics, copyright, and downloads.

    .DESCRIPTION
        Each song page contains:
        - A title in an element with class "entry-title"
        - Lyrics in <p> blocks inside a div with class "song-details"
        - Copyright info in an element with class "copyright-info"
        - Download links in a sidebar with class "col-sm-4"

        Lyrics detection:
        - Each <p> inside .song-details represents a line or stanza break
        - <em>/<i> tags indicate chorus lines (italic in the original)
        - Empty <p> tags represent stanza breaks between verses
        - <br> tags represent line breaks within a verse

        Download link detection:
        - Links in the .col-sm-4 sidebar with text containing "words", "music",
          or "audio" are captured as download URLs

        This is equivalent to the Python SongParser class but uses regex.

    .PARAMETER Html
        The raw HTML content of the song page.

    .OUTPUTS
        PSCustomObject with Title, Verses (array of [text, is_italic] pairs),
        Copyright, and Downloads (hashtable) properties.
    #>
    param([string]$Html)

    # --- Extract title from .entry-title ---
    $title = ""
    $titleMatch = [regex]::Match($Html, '<[^>]+class=["\x27][^"\x27]*entry-title[^"\x27]*["\x27][^>]*>([\s\S]*?)</[^>]+>', [System.Text.RegularExpressions.RegexOptions]::IgnoreCase)
    if ($titleMatch.Success) {
        # Strip HTML tags from the title content
        $title = [regex]::Replace($titleMatch.Groups[1].Value, '<[^>]+>', '').Trim()
        $title = ConvertFrom-HtmlEntities -Text $title
    }

    # --- Extract lyrics from .song-details ---
    # First, extract the entire song-details container.
    # We use a greedy [\s\S]* with a last-resort </div> to capture the full
    # container contents even if it contains nested <div> elements.
    # The approach: find the opening tag, then capture everything up to the
    # matching closing </div>. We use a balanced-group approach by finding
    # the section boundary via the next major section (copyright-info or col-sm-4).
    # Fallback: greedy match up to the last </div> before copyright-info.
    $verses = @()
    # Try to extract from song-details to the next known section boundary
    $songDetailsMatch = [regex]::Match($Html, '<(?:div|section)[^>]+class=["\x27][^"\x27]*song-details[^"\x27]*["\x27][^>]*>([\s\S]*?)(?=<[^>]+class=["\x27][^"\x27]*(?:copyright-info|col-sm-4|files))', [System.Text.RegularExpressions.RegexOptions]::IgnoreCase)
    # Fallback: simpler regex if no section boundary found
    if (-not $songDetailsMatch.Success) {
        $songDetailsMatch = [regex]::Match($Html, '<(?:div|section)[^>]+class=["\x27][^"\x27]*song-details[^"\x27]*["\x27][^>]*>([\s\S]*?)</(?:div|section)>', [System.Text.RegularExpressions.RegexOptions]::IgnoreCase)
    }
    if ($songDetailsMatch.Success) {
        $songDetailsHtml = $songDetailsMatch.Groups[1].Value

        # Split on <p> boundaries to handle unclosed <p> tags.
        # The site inconsistently uses bare <P> tags as verse separators
        # without closing the previous <p>, so matching <p>...</p> pairs
        # only captures the last verse. Splitting on <p> handles both cases.
        $pBlocks = [regex]::Split($songDetailsHtml, '<p[^>]*>', [System.Text.RegularExpressions.RegexOptions]::IgnoreCase)
        # Also find the positions of each <p> tag so we can reconstruct
        # the "preceding HTML" for outer <em>/<i> wrapper detection.
        $pTagMatches = [regex]::Matches($songDetailsHtml, '<p[^>]*>', [System.Text.RegularExpressions.RegexOptions]::IgnoreCase)
        $blockIndex = 0
        foreach ($pBlock in $pBlocks) {
            # Strip any trailing </p> tag and trim
            $pContent = [regex]::Replace($pBlock, '</p>\s*$', '', [System.Text.RegularExpressions.RegexOptions]::IgnoreCase)

            # Detect italic text (chorus indicator) — check for <em> or <i> tags
            # INSIDE this <p> element's content
            $hasItalic = [regex]::IsMatch($pContent, '<(em|i)[\s>]', [System.Text.RegularExpressions.RegexOptions]::IgnoreCase)

            # Also check if this <p> is INSIDE an outer <em>/<i> wrapper.
            # On some songs, the chorus is wrapped as <em><p>line</p><p>line</p></em>
            # rather than <p><em>line</em></p>. In that case, <em> opens before the
            # <p> and closes after it. We detect this by counting unclosed <em>/<i>
            # tags in the HTML preceding this <p> match.
            if (-not $hasItalic -and $blockIndex -gt 0 -and $blockIndex -le $pTagMatches.Count) {
                # Use the position of the <p> tag that started this block
                $preceding = $songDetailsHtml.Substring(0, $pTagMatches[$blockIndex - 1].Index)
                $emOpens  = [regex]::Matches($preceding, '<(em|i)[\s>]', [System.Text.RegularExpressions.RegexOptions]::IgnoreCase).Count
                $emCloses = [regex]::Matches($preceding, '</(em|i)>', [System.Text.RegularExpressions.RegexOptions]::IgnoreCase).Count
                if ($emOpens -gt $emCloses) {
                    $hasItalic = $true
                }
            }

            # Replace <br> and <br/> tags with newline characters
            # (represents line breaks within a verse/stanza)
            # The site emits <BR><br /> (both uppercase and lowercase) on every
            # line, which produces double line breaks. We match one or more
            # consecutive <br> tags (with optional whitespace between) as a
            # single newline to avoid double-spacing.
            $text = [regex]::Replace($pContent, '(?:<br\s*/?>[\s]*)+', "`n", [System.Text.RegularExpressions.RegexOptions]::IgnoreCase)

            # Strip all remaining HTML tags
            $text = [regex]::Replace($text, '<[^>]+>', '')

            # Decode HTML entities
            $text = ConvertFrom-HtmlEntities -Text $text

            # Trim the text (but keep empty strings as stanza-break markers)
            $text = $text.Trim()

            # Store as [text, is_italic] pair — matching Python's tuple format
            $verses += ,@($text, $hasItalic)
            $blockIndex++
        }
    }

    # --- Extract copyright from .copyright-info ---
    $copyright = ""
    $copyrightMatch = [regex]::Match($Html, '<[^>]+class=["\x27][^"\x27]*copyright-info[^"\x27]*["\x27][^>]*>([\s\S]*?)</[^>]+>', [System.Text.RegularExpressions.RegexOptions]::IgnoreCase)
    if ($copyrightMatch.Success) {
        $copyright = [regex]::Replace($copyrightMatch.Groups[1].Value, '<[^>]+>', '').Trim()
        $copyright = ConvertFrom-HtmlEntities -Text $copyright
    }

    # --- Extract download links from .col-sm-4 sidebar ---
    $downloads = @{}
    $sidebarMatch = [regex]::Match($Html, '<div[^>]+class=["\x27][^"\x27]*(?:col-sm-4|files)[^"\x27]*["\x27][^>]*>([\s\S]*?)</div>', [System.Text.RegularExpressions.RegexOptions]::IgnoreCase)
    if ($sidebarMatch.Success) {
        $sidebarHtml = $sidebarMatch.Groups[1].Value

        # Find all <a> tags within the sidebar
        $linkMatches = [regex]::Matches($sidebarHtml, '<a[^>]+href=["\x27]([^"\x27]+)["\x27][^>]*>([\s\S]*?)</a>', [System.Text.RegularExpressions.RegexOptions]::IgnoreCase)

        foreach ($lm in $linkMatches) {
            $href  = $lm.Groups[1].Value.Trim()
            $label = [regex]::Replace($lm.Groups[2].Value, '<[^>]+>', '').Trim().ToLower()

            # Skip empty or placeholder links
            if (-not $href -or $href -eq "#") { continue }

            # Match the link to a download type based on its label text
            foreach ($key in @("words", "music", "audio")) {
                if ($label.Contains($key)) {
                    $downloads[$key] = $href
                    break
                }
            }
        }
    }

    return [PSCustomObject]@{
        Title     = $title
        Verses    = $verses
        Copyright = $copyright
        Downloads = $downloads
    }
}


# ===========================================================================
# BOOK HELPERS — extract and clean book-specific data from song titles
# ===========================================================================

function Get-HymnNumber {
    <#
    .SYNOPSIS
        Extract the hymn number from a song title string for a specific book.

    .DESCRIPTION
        Song titles on the index page include the book code and number in
        parentheses, e.g. "Amazing Grace (MP0023)". This function uses the
        book-specific regex pattern to extract just the numeric part.

    .PARAMETER Title
        The full song title from the index page.

    .PARAMETER Book
        Book identifier key ("mp", "cp", or "jp").

    .OUTPUTS
        Int: The hymn number (e.g. 23 from "MP0023"), or -1 if not found.
    #>
    param(
        [string]$Title,
        [string]$Book
    )

    $cfg = $script:BOOK_CONFIG[$Book]
    $m = [regex]::Match($Title, $cfg.Pattern, [System.Text.RegularExpressions.RegexOptions]::IgnoreCase)

    if ($m.Success) {
        return [int]$m.Groups[1].Value
    }
    return -1
}


function Get-DetectedBook {
    <#
    .SYNOPSIS
        Determine which book a song belongs to based on its title.

    .DESCRIPTION
        Checks the title against each book's regex pattern (MP, CP, JP)
        and returns the first match.

    .PARAMETER Title
        The full song title from the index page.

    .OUTPUTS
        String: Book key ("mp", "cp", or "jp") if found, or $null if no match.
    #>
    param([string]$Title)

    foreach ($book in $script:BOOK_CONFIG.Keys) {
        $num = Get-HymnNumber -Title $Title -Book $book
        if ($num -ge 0) {
            return $book
        }
    }
    return $null
}


function Get-CleanTitle {
    <#
    .SYNOPSIS
        Remove the book code suffix from a song title.

    .DESCRIPTION
        Strips the "(MP0023)" or similar suffix from the end of the title,
        leaving just the human-readable song name for use in filenames
        and formatted output.

    .PARAMETER Title
        The full song title (e.g. "Amazing Grace (MP0023)").

    .PARAMETER Book
        Book identifier key ("mp", "cp", or "jp").

    .OUTPUTS
        String: The cleaned title (e.g. "Amazing Grace").
    #>
    param(
        [string]$Title,
        [string]$Book
    )

    $cfg = $script:BOOK_CONFIG[$Book]
    # Convert the capturing group pattern to a simple match pattern
    # e.g. '\(MP(\d+)\)' -> '\(MP\d+\)' (no longer captures, just matches)
    $pat = $cfg.Pattern -replace '\(\\d\+\)', '\d+'
    # Remove the book code suffix from the end of the title
    $cleaned = [regex]::Replace($Title, "\s*$pat\s*$", '', [System.Text.RegularExpressions.RegexOptions]::IgnoreCase).Trim()
    return $cleaned
}


# ===========================================================================
# TITLE CASE & SANITIZE — filename-safe title formatting
# ===========================================================================

function ConvertTo-TitleCase {
    <#
    .SYNOPSIS
        Convert a string to Title Case, with correct handling of apostrophes.

    .DESCRIPTION
        PowerShell's (Get-Culture).TextInfo.ToTitleCase() and Python's str.title()
        both treat apostrophes as word boundaries, producing incorrect results like
        "Don'T" instead of "Don't". This function uses a regex to match whole words
        (including contractions like "don't", "it's", "o'er") and capitalises each correctly.

        The regex pattern [a-zA-Z]+(['\u2019\u2018][a-zA-Z]+)? matches:
        - One or more letters (the main word)
        - Optionally an apostrophe (ASCII or Unicode curly) followed by more letters

        We include Unicode curly/smart apostrophes (\u2019 RIGHT SINGLE QUOTATION
        MARK and \u2018 LEFT SINGLE QUOTATION MARK) because HTML entities like
        &rsquo; decode to \u2019. Without this, "Eagle\u2019s" would produce "Eagle'S".

        The capitalize equivalent: uppercase first char, lowercase the rest of the match.

    .PARAMETER Text
        The input string.

    .OUTPUTS
        String: The Title Cased string.
    #>
    param([string]$Text)

    # Use regex with a script block evaluator to capitalise each word correctly.
    # [a-zA-Z]+(['\u2019\u2018][a-zA-Z]+)? matches words and contractions, including
    # Unicode curly apostrophes (\u2019/\u2018) from decoded HTML entities like &rsquo;.
    $result = [regex]::Replace($Text, "[a-zA-Z]+(['\u2019\u2018][a-zA-Z]+)?", {
        param($m)
        $word = $m.Value
        # Capitalize: uppercase first char, lowercase the rest
        if ($word.Length -eq 1) {
            return $word.ToUpper()
        }
        return $word.Substring(0,1).ToUpper() + $word.Substring(1).ToLower()
    })

    return $result
}


function Invoke-Sanitize {
    <#
    .SYNOPSIS
        Remove characters that are invalid in filenames across operating systems.

    .DESCRIPTION
        Strips characters that are forbidden in Windows filenames and/or could
        cause issues on other platforms: \ / * ? : " < > |

    .PARAMETER Name
        The raw string to sanitize (typically a song title).

    .OUTPUTS
        String: The sanitized string with invalid characters removed.
    #>
    param([string]$Name)

    return ([regex]::Replace($Name, '[\\/*?:"<>|]', '')).Trim()
}


function Get-BaseFilename {
    <#
    .SYNOPSIS
        Construct the base filename for a song (without file extension).

    .DESCRIPTION
        Generates a consistent filename from the song's book, number, and title.
        The title is sanitized and converted to Title Case. The number is
        zero-padded according to the book's configuration (MP=4 digits, CP/JP=3).

    .PARAMETER Number
        The song number (e.g. 23).

    .PARAMETER Book
        Book identifier key ("mp", "cp", or "jp").

    .PARAMETER Title
        The cleaned song title (book code already removed).

    .OUTPUTS
        String: The base filename, e.g. "0023 (MP) - Amazing Grace".
    #>
    param(
        [int]$Number,
        [string]$Book,
        [string]$Title
    )

    $cfg    = $script:BOOK_CONFIG[$Book]
    $padded = $Number.ToString().PadLeft($cfg.Pad, '0')   # Zero-pad: 23 -> "0023" (for MP)
    $label  = $cfg.Label                                    # Book label: "MP", "CP", or "JP"
    $safeTitle = ConvertTo-TitleCase -Text (Invoke-Sanitize -Name $Title)
    return "$padded ($label) - $safeTitle"
}


# ===========================================================================
# LYRICS FORMATTING — convert parsed song data to plain text
# ===========================================================================

function Format-Lyrics {
    <#
    .SYNOPSIS
        Format a parsed song into a clean plain-text string for saving.

    .DESCRIPTION
        This is the most complex formatting function in the scraper because
        the source HTML has several structural quirks that need handling:

        1. STANZA GROUPING: On missionpraise.com, each lyric line is its own <p>
           element, and stanza breaks are represented by empty <p> tags. The
           parser preserves this as empty strings in the verses list, which
           this function uses to group lines into stanzas.

        2. CHORUS DETECTION: Chorus lines are rendered in italic (<em>/<i>) on
           the site. The parser flags each verse with is_italic=$true if it
           contains any italic text. This function prepends "Chorus:" before
           chorus stanzas.

        3. ATTRIBUTION DETECTION: After the lyrics, songs may have author/composer
           credits like "Words: Stuart Townend" or "Music: Keith Getty". These
           are detected by pattern matching and separated from the lyrics with
           extra blank lines.

        4. STANDALONE AUTHOR LINES: Some songs have short attribution lines like
           "Stuart Townend / Keith Getty" that don't start with "Words:" or
           "Music:". These are detected by heuristics (contains "/" or "&",
           short length, doesn't start with a digit).

        5. MULTI-LINE VERSES: Some verses contain <br> tags (represented as \n
           in the parser output), meaning multiple lines in a single <p>.
           These are treated as complete stanzas on their own.

    .PARAMETER Song
        PSCustomObject with Title, Verses, Copyright, and Downloads properties.

    .PARAMETER Book
        Book identifier key ("mp", "cp", or "jp").

    .OUTPUTS
        String: The formatted plain-text song content.
    #>
    param(
        [PSCustomObject]$Song,
        [string]$Book
    )

    $cleanedTitle = Get-CleanTitle -Title $Song.Title -Book $Book
    # Start with quoted title and blank line
    $lines = [System.Collections.ArrayList]::new()
    [void]$lines.Add("`"$cleanedTitle`"")
    [void]$lines.Add("")

    # --- Stanza/attribution tracking ---
    # We need to separate actual lyrics from author/attribution lines that
    # appear after the last verse. We also need to group single-line verses
    # into stanzas (separated by empty <p> elements in the source).
    $authorLines     = [System.Collections.ArrayList]::new()
    $foundAttribution = $false
    $stanzaBuf       = [System.Collections.ArrayList]::new()
    $stanzaIsChorus  = $false

    # --- Helper: flush the accumulated stanza to the output lines list ---
    # (Implemented inline since PowerShell closures over ArrayList need care)

    # --- Consecutive empty tracking ---
    # On missionpraise.com, single empty <p> elements appear between EVERY
    # lyric line for CSS spacing, not just at actual stanza boundaries. If we
    # flushed on every empty <p>, each line would become its own "stanza" with
    # a blank line after it (double-spaced output). Instead, we count
    # consecutive empties and only flush when there's strong evidence of a
    # real stanza boundary:
    #   - 2+ consecutive empties (structural break in the HTML)
    #   - Italic state change (verse <-> chorus transition)
    #   - Verse number at start of the next line (new numbered verse)
    $consecutiveEmpties = 0

    # Process each verse (paragraph) from the parsed HTML
    foreach ($versePair in $Song.Verses) {
        $verseText = $versePair[0]
        $isItalic  = $versePair[1]
        $stripped   = $verseText.Trim()

        # --- Empty verse: count but don't flush yet ---
        # We defer the stanza-break decision until we see the next content
        # line, so we can use content-based heuristics to decide.
        if ([string]::IsNullOrEmpty($stripped)) {
            $consecutiveEmpties++
            continue
        }

        # --- Stanza break decision ---
        # Now that we have a non-empty verse, decide whether the preceding
        # empty <p> element(s) represent a real stanza break.
        if ($stanzaBuf.Count -gt 0 -and $consecutiveEmpties -gt 0) {
            $shouldBreak = (
                $consecutiveEmpties -ge 2 -or                             # structural break
                $isItalic -ne $stanzaIsChorus -or                         # verse<->chorus
                [regex]::IsMatch($stripped, '^\d+\s')                     # verse number
            )
            if ($shouldBreak) {
                # Flush stanza
                if ($stanzaBuf.Count -gt 0) {
                    if ($stanzaIsChorus) {
                        $firstBufLine = if ($stanzaBuf.Count -gt 0) { $stanzaBuf[0].Trim() } else { "" }
                        if (-not [regex]::IsMatch($firstBufLine, '^\d+\s')) {
                            [void]$lines.Add("Chorus:")
                        }
                    }
                    [void]$lines.Add(($stanzaBuf -join "`n"))
                    [void]$lines.Add("")
                    $stanzaBuf.Clear()
                    $stanzaIsChorus = $false
                }
            }
        }
        $consecutiveEmpties = 0

        # --- Attribution line detection ---
        # Lines starting with "Words:", "Music:", "Arranged:", etc. are
        # author/composer credits, not lyrics. We require a colon, "by",
        # or separator after the keyword to prevent false positives (e.g.
        # sidebar text "Music file" or lyrics starting with "Words of...").
        # Once we find a genuine attribution line, subsequent short lines
        # are also treated as attribution — but ONLY if they don't look
        # like lyrics (i.e. they don't start with a digit/verse number).
        $isAttribution = $false
        if ([regex]::IsMatch($stripped, '^(Words|Music|Arranged|Words and music|Based on|Translated|Paraphrase)\s*[:&\-/by]', [System.Text.RegularExpressions.RegexOptions]::IgnoreCase)) {
            $isAttribution = $true
        }
        elseif ([regex]::IsMatch($stripped, '^(Words and music|Words & music)\b', [System.Text.RegularExpressions.RegexOptions]::IgnoreCase)) {
            $isAttribution = $true
        }
        elseif ($foundAttribution -and (-not $stripped.Contains("`n")) -and (-not [regex]::IsMatch($stripped, '^\d+\s')) -and ($stripped.Length -lt 120)) {
            $isAttribution = $true
        }

        if ($isAttribution) {
            # Flush stanza before adding attribution
            if ($stanzaBuf.Count -gt 0) {
                if ($stanzaIsChorus) {
                    # Guard: don't label as Chorus if stanza starts with verse number
                    $firstBufLine = if ($stanzaBuf.Count -gt 0) { $stanzaBuf[0].Trim() } else { "" }
                    if (-not [regex]::IsMatch($firstBufLine, '^\d+\s')) {
                        [void]$lines.Add("Chorus:")
                    }
                }
                [void]$lines.Add(($stanzaBuf -join "`n"))
                [void]$lines.Add("")
                $stanzaBuf.Clear()
                $stanzaIsChorus = $false
            }
            $foundAttribution = $true
            [void]$authorLines.Add($stripped)
            continue
        }

        # If we see a normal lyric line after attribution, reset the cascade.
        # This prevents a single false-positive attribution from consuming
        # all remaining lyrics in the song.
        if ($foundAttribution -and [regex]::IsMatch($stripped, '^\d+\s')) {
            $foundAttribution = $false
        }

        # --- Standalone author lines ---
        # Some attribution lines don't follow the "Words:" pattern but are
        # recognisable as author names by containing "/" or "&" separators
        # (e.g. "Stuart Townend / Keith Getty"). We use several heuristics:
        # - Single line (no internal line breaks)
        # - Doesn't start with a digit (not a verse number)
        # - Contains "/" or "&" (name separators)
        # - Short enough to be a name, not lyrics (< 120 chars)
        # - Not a typical lyric pattern (doesn't contain common verse words)
        if ((-not $stripped.Contains("`n")) -and
            (-not [regex]::IsMatch($stripped, '^\d')) -and
            ($stripped.Contains("/") -or $stripped.Contains("&")) -and
            ($stripped.Length -lt 120) -and
            (-not [regex]::IsMatch($stripped, '\b(the|and|you|your|my|our|lord|god|love|sing|praise)\b', [System.Text.RegularExpressions.RegexOptions]::IgnoreCase))) {
            # Flush stanza
            if ($stanzaBuf.Count -gt 0) {
                if ($stanzaIsChorus) {
                    # Guard: don't label as Chorus if stanza starts with verse number
                    $firstBufLine = if ($stanzaBuf.Count -gt 0) { $stanzaBuf[0].Trim() } else { "" }
                    if (-not [regex]::IsMatch($firstBufLine, '^\d+\s')) {
                        [void]$lines.Add("Chorus:")
                    }
                }
                [void]$lines.Add(($stanzaBuf -join "`n"))
                [void]$lines.Add("")
                $stanzaBuf.Clear()
                $stanzaIsChorus = $false
            }
            [void]$authorLines.Add($stripped)
            continue
        }

        # --- Multi-line verse ---
        # If the verse text contains "\n" (from <br> tags in the HTML),
        # it's a multi-line verse that should be treated as its own stanza.
        if ($stripped.Contains("`n")) {
            # Flush any single-line stanza we were building
            if ($stanzaBuf.Count -gt 0) {
                if ($stanzaIsChorus) {
                    # Guard: don't label as Chorus if stanza starts with verse number
                    $firstBufLine = if ($stanzaBuf.Count -gt 0) { $stanzaBuf[0].Trim() } else { "" }
                    if (-not [regex]::IsMatch($firstBufLine, '^\d+\s')) {
                        [void]$lines.Add("Chorus:")
                    }
                }
                [void]$lines.Add(($stanzaBuf -join "`n"))
                [void]$lines.Add("")
                $stanzaBuf.Clear()
                $stanzaIsChorus = $false
            }
            # Clean up each line within the verse (strip whitespace)
            $cleaned = ($stripped.Split("`n") | ForEach-Object { $_.Trim() }) -join "`n"
            if ($isItalic) {
                # Guard: don't label as Chorus if first line starts with verse number
                $firstCleanedLine = ($cleaned -split "`n")[0].Trim()
                if (-not [regex]::IsMatch($firstCleanedLine, '^\d+\s')) {
                    [void]$lines.Add("Chorus:")
                }
            }
            [void]$lines.Add($cleaned)
            [void]$lines.Add("")   # Blank line after the stanza
        }
        else {
            # --- Single-line verse ---
            # Accumulate into the current stanza buffer. The stanza will be
            # flushed when we encounter an empty verse (stanza break) or a
            # different type of content.
            if ($isItalic) {
                $stanzaIsChorus = $true
            }
            [void]$stanzaBuf.Add($stripped)
        }
    }

    # Flush any remaining stanza that wasn't terminated by an empty verse
    if ($stanzaBuf.Count -gt 0) {
        if ($stanzaIsChorus) {
            # Guard: don't label as Chorus if stanza starts with verse number
            $firstBufLine = if ($stanzaBuf.Count -gt 0) { $stanzaBuf[0].Trim() } else { "" }
            if (-not [regex]::IsMatch($firstBufLine, '^\d+\s')) {
                [void]$lines.Add("Chorus:")
            }
        }
        [void]$lines.Add(($stanzaBuf -join "`n"))
        [void]$lines.Add("")
        $stanzaBuf.Clear()
        $stanzaIsChorus = $false
    }

    # Remove trailing blank lines from the lyrics section
    while ($lines.Count -gt 0 -and $lines[$lines.Count - 1] -eq "") {
        $lines.RemoveAt($lines.Count - 1)
    }

    # Append author attribution lines (separated from lyrics by double blank line)
    if ($authorLines.Count -gt 0) {
        [void]$lines.Add("")   # First blank line
        [void]$lines.Add("")   # Second blank line (visual separation)
        foreach ($al in $authorLines) {
            [void]$lines.Add($al)
        }
    }

    # Append copyright notice if available
    if (-not [string]::IsNullOrWhiteSpace($Song.Copyright)) {
        [void]$lines.Add("")
        [void]$lines.Add($Song.Copyright)
    }

    # --- Post-processing: normalise spaced ellipses ---
    # WordPress's text rendering sometimes converts "..." to ". . ." with
    # spaces between the dots. We normalise these back to standard "..."
    # The regex matches patterns like ". . ." or " . . ." with 2+ dots.
    $result = $lines -join "`n"
    $result = [regex]::Replace($result, ' ?(?:\. ){2,}\.', '...')

    # Strip legacy formatting codes f*I (italic start) and f*R (format reset)
    # that appear as data-entry artefacts in some song texts
    $result = $result -replace 'f\*I', ''
    $result = $result -replace 'f\*R', ''

    # Collapse 3+ consecutive newlines down to 2 (one blank line maximum)
    $result = [regex]::Replace($result, '(\r?\n){3,}', "`n`n")

    return $result
}


# ===========================================================================
# FILE CACHE — resumability support via cached directory listings
# ===========================================================================

function Build-FileCache {
    <#
    .SYNOPSIS
        Build a set of existing filenames in a directory for quick lookup.

    .DESCRIPTION
        Called once at startup to avoid repeated directory scans during
        the scrape. Returns a HashSet for O(1) membership testing.

    .PARAMETER OutputDir
        Path to the directory to scan.

    .OUTPUTS
        HashSet[string]: Set of filename strings (not full paths), or empty set
        if the directory doesn't exist.
    #>
    param([string]$OutputDir)

    $cache = [System.Collections.Generic.HashSet[string]]::new()
    if (Test-Path $OutputDir -PathType Container) {
        Get-ChildItem -Path $OutputDir -File | ForEach-Object {
            [void]$cache.Add($_.Name)
        }
    }
    return $cache
}


function Test-LyricsExist {
    <#
    .SYNOPSIS
        Check if lyrics for a specific song have already been saved.

    .DESCRIPTION
        Uses the cached file listing for O(1) lookup instead of checking
        the filesystem directly. Matches files by their prefix pattern
        (number + label) and .txt extension.

    .PARAMETER Number
        The song number.

    .PARAMETER Book
        Book identifier key.

    .PARAMETER FileCache
        HashSet of existing filenames (from Build-FileCache).

    .OUTPUTS
        Boolean: $true if a matching .txt file exists in the cache.
    #>
    param(
        [int]$Number,
        [string]$Book,
        [System.Collections.Generic.HashSet[string]]$FileCache
    )

    $cfg    = $script:BOOK_CONFIG[$Book]
    $padded = $Number.ToString().PadLeft($cfg.Pad, '0')
    $label  = $cfg.Label
    # Build the prefix that all files for this song start with
    $prefix = "$padded ($label) -"

    foreach ($f in $FileCache) {
        if ($f.StartsWith($prefix) -and $f.EndsWith(".txt")) {
            return $true
        }
    }
    return $false
}


function Test-DownloadExists {
    <#
    .SYNOPSIS
        Check if a specific download file (words/music/audio) already exists.

    .DESCRIPTION
        Words files use the base name directly (base.rtf), while music and
        audio files add a type suffix (base_music.pdf, base_audio.mp3).

    .PARAMETER Base
        The base filename (from Get-BaseFilename).

    .PARAMETER DlType
        Download type ("words", "music", or "audio").

    .PARAMETER FileCache
        HashSet of existing filenames.

    .OUTPUTS
        Boolean: $true if a matching file exists in the cache.
    #>
    param(
        [string]$Base,
        [string]$DlType,
        [System.Collections.Generic.HashSet[string]]$FileCache
    )

    # Words files: "base.ext", other types: "base_type.ext"
    $prefix = if ($DlType -eq "words") { "$Base." } else { "${Base}_${DlType}." }

    foreach ($f in $FileCache) {
        if ($f.StartsWith($prefix)) {
            return $true
        }
    }
    return $false
}


# ===========================================================================
# SAVE FUNCTIONS — write lyrics and downloads to disk
# ===========================================================================

function Save-Lyrics {
    <#
    .SYNOPSIS
        Format and save a song's lyrics to a plain-text file.

    .DESCRIPTION
        Creates the output directory if needed, generates the filename,
        formats the lyrics, and writes the file with UTF-8 encoding.

    .PARAMETER Song
        Parsed song PSCustomObject with Title, Verses, Copyright.

    .PARAMETER Number
        The song number (e.g. 23).

    .PARAMETER Book
        Book identifier key ("mp", "cp", or "jp").

    .PARAMETER OutputDir
        Directory to save the file in.

    .OUTPUTS
        String: The base filename (without extension) — returned so that
        download files can reuse the same base name.
    #>
    param(
        [PSCustomObject]$Song,
        [int]$Number,
        [string]$Book,
        [string]$OutputDir
    )

    # Create the output directory if it doesn't exist
    if (-not (Test-Path $OutputDir)) {
        New-Item -Path $OutputDir -ItemType Directory -Force | Out-Null
    }

    $cleanedTitle = Get-CleanTitle -Title $Song.Title -Book $Book
    $base     = Get-BaseFilename -Number $Number -Book $Book -Title $cleanedTitle
    $filepath = Join-Path $OutputDir "$base.txt"

    # Format the lyrics and write to file (UTF-8 without BOM for cross-platform compatibility)
    $content = Format-Lyrics -Song $Song -Book $Book
    [System.IO.File]::WriteAllText($filepath, $content, [System.Text.UTF8Encoding]::new($false))

    return $base
}


function Save-Download {
    <#
    .SYNOPSIS
        Save a downloaded binary file (words, music, or audio) to disk.

    .DESCRIPTION
        Naming convention:
        - Words (primary):  {base}.rtf       (same name as lyrics, different ext)
        - Music:            {base}_music.pdf  (suffix distinguishes from words)
        - Audio:            {base}_audio.mp3  (suffix distinguishes from words)

        The extension is determined by Get-ExtForDownload using a cascade of
        Content-Type -> URL extension -> magic bytes detection.

    .PARAMETER Data
        Raw bytes of the downloaded file.

    .PARAMETER Base
        Base filename (from Get-BaseFilename).

    .PARAMETER DlType
        Download type ("words", "music", or "audio").

    .PARAMETER ContentType
        MIME type from the Content-Type header.

    .PARAMETER DlUrl
        The download URL (for extension guessing fallback).

    .PARAMETER OutputDir
        Directory to save the file in.

    .OUTPUTS
        String: The complete filename (with extension) that was saved.
    #>
    param(
        [byte[]]$Data,
        [string]$Base,
        [string]$DlType,
        [string]$ContentType,
        [string]$DlUrl,
        [string]$OutputDir
    )

    $ext = Get-ExtForDownload -ContentType $ContentType -Url $DlUrl -Data $Data

    # Words files share the same base name as lyrics (just different extension):
    #   "0023 (MP) - Amazing Grace.rtf"
    # Music and audio files add a type suffix to avoid extension conflicts:
    #   "0023 (MP) - Amazing Grace_music.pdf"
    #   "0023 (MP) - Amazing Grace_audio.mp3"
    if ($DlType -eq "words") {
        $filename = "$Base$ext"
    }
    else {
        $filename = "${Base}_${DlType}$ext"
    }

    $filepath = Join-Path $OutputDir $filename
    [System.IO.File]::WriteAllBytes($filepath, $Data)

    return $filename
}


# ===========================================================================
# SKIP DIAGNOSTIC HTML DUMP — write raw HTML for skipped songs to aid debugging
# ===========================================================================

function Write-SkipHtmlDump {
    <#
    .SYNOPSIS
        Write the raw HTML of a skipped song page to a debug file for inspection.

    .DESCRIPTION
        When a song is skipped (login wall, WAF block, subscription paywall,
        no title parsed), the raw HTML is dumped to a file named
        _debug_{LABEL}{NUM}_skipped.html in the output directory.
        This allows post-run diagnosis of why songs were skipped without
        needing to re-run the scraper in debug mode.

    .PARAMETER OutputDir
        Directory to write the debug file in.

    .PARAMETER Label
        Book label (e.g. "MP").

    .PARAMETER Padded
        Zero-padded song number string (e.g. "0023").

    .PARAMETER Html
        The raw HTML content of the page that triggered the skip.
    #>
    param(
        [string]$OutputDir,
        [string]$Label,
        [string]$Padded,
        [string]$Html
    )

    if (-not $Html) {
        return   # Nothing to dump if the response was empty
    }

    # Create the output directory if it doesn't exist
    if (-not (Test-Path $OutputDir)) {
        New-Item -Path $OutputDir -ItemType Directory -Force | Out-Null
    }

    $filename = "_debug_${Label}${Padded}_skipped.html"
    $filepath = Join-Path $OutputDir $filename
    [System.IO.File]::WriteAllText($filepath, $Html, [System.Text.Encoding]::UTF8)
}


# ===========================================================================
# SKIP LOGGING — record skipped songs for later review
# ===========================================================================

function Write-SkipLog {
    <#
    .SYNOPSIS
        Record a skipped song in the skipped.log file for later review.

    .DESCRIPTION
        Creates a persistent log of songs that couldn't be scraped, with
        timestamps, identifiers, and reasons. Useful for identifying gaps
        and diagnosing systematic issues.

    .PARAMETER OutputDir
        Directory to write the log file in.

    .PARAMETER Label
        Book label (e.g. "MP").

    .PARAMETER Padded
        Zero-padded song number string (e.g. "0023").

    .PARAMETER Title
        The song title from the index page.

    .PARAMETER Url
        The song page URL that was attempted.

    .PARAMETER Reason
        Human-readable explanation of why it was skipped.
    #>
    param(
        [string]$OutputDir,
        [string]$Label,
        [string]$Padded,
        [string]$Title,
        [string]$Url,
        [string]$Reason
    )

    # Create the output directory if it doesn't exist
    if (-not (Test-Path $OutputDir)) {
        New-Item -Path $OutputDir -ItemType Directory -Force | Out-Null
    }

    $logPath   = Join-Path $OutputDir "skipped.log"
    $timestamp = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
    $logEntry  = "[$timestamp]  ${Label}${Padded}  $Title  --  $Reason  --  $Url"

    # Append to the log file (create if it doesn't exist)
    Add-Content -Path $logPath -Value $logEntry -Encoding UTF8
}


# ===========================================================================
# PROCESS SONG — orchestrate scraping + downloading for a single song
# ===========================================================================

function Invoke-ProcessSong {
    <#
    .SYNOPSIS
        Scrape lyrics and download files for a single song.

    .DESCRIPTION
        This is the core function that handles one song from start to finish:
        1. Extract the song number from the index title
        2. Check if lyrics/downloads already exist (skip if so)
        3. Fetch the song detail page
        4. Parse the HTML to extract lyrics, copyright, and download links
        5. Save the lyrics as a .txt file
        6. Download and save words/music/audio files (if enabled)

        The function handles several edge cases:
        - Songs with no extractable number (logged and skipped)
        - Login walls (the session expired mid-scrape)
        - WAF blocks on individual song pages
        - Failed title parsing (retried once in case of transient errors)
        - Missing downloads (re-fetches the page if lyrics exist but files don't)

    .PARAMETER Title
        Song title from the index page (includes book code).

    .PARAMETER Url
        URL of the song detail page.

    .PARAMETER Book
        Book identifier key ("mp", "cp", or "jp").

    .PARAMETER OutputDir
        Base output directory.

    .PARAMETER NoFiles
        If $true, skip downloading words/music/audio files.

    .PARAMETER Delay
        Seconds to wait between requests.

    .PARAMETER FileCache
        Mutable HashSet of existing filenames (updated when new files saved).

    .OUTPUTS
        String: "saved" if lyrics were newly saved,
                "skipped" if the song couldn't be scraped,
                "exists" if the song was already saved from a previous run.
    #>
    param(
        [string]$Title,
        [string]$Url,
        [string]$Book,
        [string]$OutputDir,
        [bool]$NoFiles,
        [double]$Delay,
        [System.Collections.Generic.HashSet[string]]$FileCache
    )

    # Extract the hymn number from the title (e.g. "Amazing Grace (MP0023)" -> 23)
    $number = Get-HymnNumber -Title $Title -Book $Book
    $cfg    = $script:BOOK_CONFIG[$Book]

    # Route output into a book-specific subdirectory
    $bookDir = Join-Path $OutputDir $cfg.Subdir

    if ($number -lt 0) {
        # Title doesn't contain a recognisable book code — can't save this song
        Write-SkipLog -OutputDir $bookDir -Label "??" -Padded "????" -Title $Title -Url $Url -Reason "no book number found in title"
        return "skipped"
    }

    $padded = $number.ToString().PadLeft($cfg.Pad, '0')
    $label  = $cfg.Label

    # --- Check if lyrics already exist ---
    $lyricsExist = Test-LyricsExist -Number $number -Book $Book -FileCache $FileCache

    # If lyrics exist and we don't need files, skip entirely (no network request)
    if ($lyricsExist -and $NoFiles) {
        return "exists"
    }

    # If lyrics exist, check whether downloads also exist
    if ($lyricsExist) {
        $clean = Get-CleanTitle -Title $Title -Book $Book
        $base  = Get-BaseFilename -Number $number -Book $Book -Title $clean
        # Look for any non-txt file with the same base name
        $hasAnyDownload = $false
        foreach ($f in $FileCache) {
            if (-not $f.EndsWith(".txt") -and ($f.StartsWith("$base.") -or $f.StartsWith("${base}_"))) {
                $hasAnyDownload = $true
                break
            }
        }
        if ($NoFiles -or $hasAnyDownload) {
            return "exists"
        }
        # Lyrics exist but no downloads — need to fetch page for download links
        $displayTitle = $Title.Substring(0, [Math]::Min(55, $Title.Length)).PadRight(55)
        Write-Host "    $label$padded  $displayTitle " -NoNewline
        Write-Host ">> fetching missing downloads... " -NoNewline
    }
    else {
        # Neither lyrics nor files exist — full scrape needed
        $displayTitle = $Title.Substring(0, [Math]::Min(55, $Title.Length)).PadRight(55)
        Write-Host "    $label$padded  $displayTitle " -NoNewline
    }

    # --- Fetch the song detail page ---
    $result = Invoke-FetchText -Url $Url
    $htmlText = $result[0]

    # Check for login wall (session may have expired during the scrape)
    if (-not $htmlText -or $htmlText.Contains("Please login to continue") -or $htmlText.Contains("loginform")) {
        $reason = if ($htmlText) { "login wall" } else { "empty response" }
        Write-Host "X ($reason)" -ForegroundColor Red
        Write-SkipHtmlDump -OutputDir $bookDir -Label $label -Padded $padded -Html $htmlText
        Write-SkipLog -OutputDir $bookDir -Label $label -Padded $padded -Title $Title -Url $Url -Reason $reason
        Start-Sleep -Seconds $Delay
        return "skipped"
    }

    # Check for subscription paywall (song exists but isn't included in the
    # user's subscription tier — the page loads but content is gated)
    if ($htmlText -and $htmlText.Contains("not part of your subscription")) {
        Write-Host "X (not in subscription)" -ForegroundColor Red
        Write-SkipHtmlDump -OutputDir $bookDir -Label $label -Padded $padded -Html $htmlText
        Write-SkipLog -OutputDir $bookDir -Label $label -Padded $padded -Title $Title -Url $Url -Reason "song not part of subscription"
        Start-Sleep -Seconds $Delay
        return "skipped"
    }

    # Check for WAF block on the song page (can happen on individual pages)
    $htmlLower = $htmlText.ToLower()
    if ($htmlLower.Contains("sucuri") -or $htmlLower.Contains("access denied")) {
        Write-Host "X (blocked by firewall)" -ForegroundColor Red
        Write-SkipHtmlDump -OutputDir $bookDir -Label $label -Padded $padded -Html $htmlText
        Write-SkipLog -OutputDir $bookDir -Label $label -Padded $padded -Title $Title -Url $Url -Reason "blocked by WAF/firewall"
        Start-Sleep -Seconds $Delay
        return "skipped"
    }

    # --- Parse the song page HTML ---
    $sp = Parse-SongPage -Html $htmlText

    # If the parser couldn't find a title, retry once — this may be a transient
    # issue like a session hiccup or a partially-loaded page
    if ([string]::IsNullOrWhiteSpace($sp.Title)) {
        Start-Sleep -Seconds ($Delay * 2)   # Longer delay before retry
        $result = Invoke-FetchText -Url $Url
        $htmlText = $result[0]
        if ($htmlText -and (-not $htmlText.Contains("loginform"))) {
            $sp = Parse-SongPage -Html $htmlText
        }
    }

    # --- Title fallback: try extracting from HTML <title> tag ---
    # If Parse-SongPage couldn't find the title via its normal selectors,
    # try the HTML <title> element as a fallback (usually "Song Name - Mission Praise")
    if ([string]::IsNullOrWhiteSpace($sp.Title) -and $htmlText) {
        $titleTagMatch = [regex]::Match($htmlText, '<title>([^<]+)</title>', [System.Text.RegularExpressions.RegexOptions]::IgnoreCase)
        if ($titleTagMatch.Success) {
            $fallbackTitle = $titleTagMatch.Groups[1].Value.Trim()
            # Strip the site name suffix (e.g. " - Mission Praise" or " – Mission Praise")
            $fallbackTitle = [regex]::Replace($fallbackTitle, '\s*[\-\x{2013}\x{2014}]\s*Mission\s+Praise.*$', '', [System.Text.RegularExpressions.RegexOptions]::IgnoreCase).Trim()
            if ($fallbackTitle -and $fallbackTitle.Length -gt 0) {
                $sp.Title = $fallbackTitle
            }
        }
    }

    # --- Title fallback: use the index page title (strip book code) ---
    # Last resort: use the title from the song index, stripping the book code
    # suffix like "(MP1270)" from the end
    if ([string]::IsNullOrWhiteSpace($sp.Title) -and -not [string]::IsNullOrWhiteSpace($Title)) {
        $indexFallback = [regex]::Replace($Title, '\s*\([A-Z]{2}\d+\)\s*$', '').Trim()
        if ($indexFallback -and $indexFallback.Length -gt 0) {
            $sp.Title = $indexFallback
        }
    }

    # If still no title after retry and fallbacks, skip this song
    if ([string]::IsNullOrWhiteSpace($sp.Title)) {
        Write-Host "X (no title parsed)" -ForegroundColor Red
        Write-SkipHtmlDump -OutputDir $bookDir -Label $label -Padded $padded -Html $htmlText
        Write-SkipLog -OutputDir $bookDir -Label $label -Padded $padded -Title $Title -Url $Url -Reason "parser found no title in page HTML"
        Start-Sleep -Seconds $Delay
        return "skipped"
    }

    # Build the structured song data (already a PSCustomObject from Parse-SongPage)
    $song = $sp

    # --- Verse count validation ---
    # Count actual text lines (not <p> entries) to detect incomplete parses.
    # Many songs use a few large <p> blocks with <br> line breaks inside,
    # so counting entries would produce false "incomplete" warnings.
    $totalLines = 0
    foreach ($v in $sp.Verses) {
        $vText = $v[0]
        if ($vText -and $vText.Trim()) {
            $totalLines += ($vText.Trim().Split("`n")).Count
        }
    }
    if ($totalLines -eq 0) {
        Write-Host " WARNING (no lyrics found)" -NoNewline -ForegroundColor Yellow
        Log-Skip -OutputDir $bookDir -Label $label -Padded $padded -Title $title -Url $url -Reason "parser found title but 0 lyric lines"
    }
    elseif ($totalLines -lt 4) {
        Write-Host " WARNING ($totalLines lines - may be incomplete)" -NoNewline -ForegroundColor Yellow
    }

    # --- Save lyrics ---
    if ($lyricsExist) {
        # Lyrics already saved — just re-derive the base name for download files
        $base = Get-BaseFilename -Number $number -Book $Book -Title (Get-CleanTitle -Title $sp.Title -Book $Book)
    }
    else {
        # Save lyrics to .txt file and add to the file cache
        $base = Save-Lyrics -Song $song -Number $number -Book $Book -OutputDir $bookDir
        [void]$FileCache.Add("$base.txt")
    }

    # --- Download files (words, music, audio) ---
    $fileResults = @()   # Track download results for the status line
    if (-not $NoFiles -and $song.Downloads.Count -gt 0) {
        foreach ($dlType in @("words", "music", "audio")) {
            $dlUrl = $song.Downloads[$dlType]
            if (-not $dlUrl) {
                continue   # This download type isn't available for this song
            }

            # Skip if already downloaded
            if (Test-DownloadExists -Base $base -DlType $dlType -FileCache $FileCache) {
                $fileResults += $dlType.Substring(0,1).ToLower()   # Lowercase = already existed
                continue
            }

            # Convert relative URLs to absolute
            if ($dlUrl.StartsWith("/")) {
                $dlUrl = "$($script:BASE)$dlUrl"
            }

            # Download the file
            $dlResult = Invoke-FetchBinary -Url $dlUrl
            $data     = $dlResult[0]
            $ct       = $dlResult[1]
            $dlFinalUrl = $dlResult[2]

            if ($data) {
                $effectiveUrl = if ($dlFinalUrl) { $dlFinalUrl } else { $dlUrl }
                $fname = Save-Download -Data $data -Base $base -DlType $dlType `
                    -ContentType $ct -DlUrl $effectiveUrl -OutputDir $bookDir
                $fileResults += $dlType.Substring(0,1).ToUpper()   # Uppercase = newly downloaded
                [void]$FileCache.Add($fname)   # Add to cache so we don't re-download
            }

            # Brief delay between downloads (30% of normal delay)
            Start-Sleep -Seconds ($Delay * 0.3)
        }
    }

    # Print the status line with download indicators:
    # [W,M,A] = newly downloaded words/music/audio (uppercase)
    # [w,m,a] = already existed (lowercase)
    $fileStr = if ($fileResults.Count -gt 0) { " [$($fileResults -join ',')]" } else { "" }
    Write-Host "[+]$fileStr" -ForegroundColor Green
    Start-Sleep -Seconds $Delay   # Rate limit between songs
    return "saved"
}


# ===========================================================================
# CRAWL & SCRAPE — paginated index crawling with per-page song processing
# ===========================================================================

function Invoke-CrawlAndScrape {
    <#
    .SYNOPSIS
        Crawl the paginated song index and scrape each discovered song.

    .DESCRIPTION
        The missionpraise.com song index is paginated (10 songs per page).
        This function:
        1. Fetches each index page sequentially
        2. Parses the page to discover song links
        3. Filters songs to only the requested books (MP, CP, JP)
        4. Scrapes each song on the current page before moving to the next

        End-of-index detection:
        - "Page not found" in the response -> no more pages
        - WordPress serving page 1 content for out-of-range page numbers
          (detected by checking the "X-Y of Z" counter in the HTML)
        - No song links found on the page
        - Empty response

        The function is designed for resumability:
        - Builds a file cache of existing files on startup
        - Supports -StartPage for resuming from a specific index page
        - Each song is individually checked for existing files

    .PARAMETER Books
        Array of book keys to scrape (e.g. @("mp", "cp", "jp")).

    .PARAMETER OutputDir
        Base output directory.

    .PARAMETER NoFiles
        If $true, skip file downloads.

    .PARAMETER StartPage
        Index page number to start from. Default: 1.

    .PARAMETER Delay
        Seconds between requests.

    .PARAMETER Force
        When $true, skip building the file cache so every song is treated as
        new. Existing files on disk will be overwritten without checking.

    .PARAMETER Song
        When specified, scrape only the song with this number. Auto-enables
        Force mode and stops after the song is found.

    .OUTPUTS
        Array of three integers: [saved, skipped, existed].
    #>
    param(
        [string[]]$Books,
        [string]$OutputDir,
        [bool]$NoFiles,
        [int]$StartPage = 1,
        [double]$Delay = 1.2,
        [bool]$Force = $false,
        [string]$Song = ""
    )

    # When -Song is specified, auto-enable Force mode so the file cache
    # doesn't cause the target song to be skipped as "already exists"
    if ($Song) {
        $Force = $true
    }

    $page = $StartPage

    # Pre-compile book pattern regexes for efficient matching
    $bookPats = @{}
    foreach ($b in $Books) {
        $bookPats[$b] = [regex]::new($script:BOOK_CONFIG[$b].Pattern, [System.Text.RegularExpressions.RegexOptions]::IgnoreCase)
    }

    # Build a combined file cache from all book subdirectories.
    # Filenames are unique across books because they include the book label,
    # so merging into one set is safe and simplifies lookup logic.
    # When -Force is active, we deliberately use an empty cache so that every
    # song is treated as new, causing lyrics and downloads to be re-fetched
    # and any existing files on disk to be overwritten.
    $fileCache = [System.Collections.Generic.HashSet[string]]::new()
    if ($Force) {
        Write-Host "  Force mode: will re-download and overwrite existing files.`n" -ForegroundColor Yellow
    }
    else {
        foreach ($b in $Books) {
            $subdir = Join-Path $OutputDir $script:BOOK_CONFIG[$b].Subdir
            $bookCache = Build-FileCache -OutputDir $subdir
            foreach ($f in $bookCache) {
                [void]$fileCache.Add($f)
            }
        }
    }

    # Counters for the final summary
    $saved   = 0   # Songs newly saved in this run
    $skipped = 0   # Songs that couldn't be scraped
    $existed = 0   # Songs that already existed from previous runs

    if (-not $Force -and $fileCache.Count -gt 0) {
        Write-Host "  Found $($fileCache.Count) existing files across output folders -- will skip duplicates.`n"
    }

    # --- Main pagination loop ---
    while ($true) {
        # Construct the URL for the current index page
        $url = "$($script:INDEX_URL)$page/"
        $result = Invoke-FetchText -Url $url
        $htmlText = $result[0]

        # Detect end of pagination
        if (-not $htmlText -or $htmlText.Contains("Page not found")) {
            if ($script:DEBUG_MODE) {
                $reason = if (-not $htmlText) { "empty response" } else { "Page not found detected" }
                Write-Host "  DEBUG: Page $page -- $reason"
            }
            break
        }

        # Dump the first page's HTML for debugging (only on StartPage)
        if ($page -eq $StartPage) {
            Write-DebugDump -Label "Index page $page HTML" -Text $htmlText
        }

        # --- Loop detection ---
        # WordPress has a quirk where out-of-range page numbers silently serve
        # page 1 content (instead of returning a 404). We detect this by parsing
        # the "Showing X-Y of Z" counter in the HTML.
        $countMatch = [regex]::Match($htmlText, '(\d+)-(\d+)\s+of\s+(\d+)')
        if ($countMatch.Success) {
            $lo    = [int]$countMatch.Groups[1].Value
            $hi    = [int]$countMatch.Groups[2].Value
            $total = [int]$countMatch.Groups[3].Value
            # Ceiling division: songs / 10 per page
            $totalPages = [math]::Ceiling($total / 10)

            # Print total count on the first page
            if ($page -eq $StartPage) {
                Write-Host "  $total total songs across ~$totalPages pages`n"
            }

            # Detect loops: if lo > hi (impossible) or we're past page 1 but
            # seeing results starting from 1 (WordPress served page 1 again)
            if ($lo -gt $hi -or ($page -gt 1 -and $lo -eq 1)) {
                break
            }
        }
        else {
            # If we can't find the counter and we're past page 1, assume we've
            # gone beyond the last page (page 1 might legitimately lack the counter)
            if ($page -gt 1) {
                break
            }
        }

        # --- Parse the index page for song links ---
        $indexSongs = Parse-IndexPage -Html $htmlText

        if (-not $indexSongs -or $indexSongs.Count -eq 0) {
            # No song links found — we've reached the end of the index
            if ($script:DEBUG_MODE) {
                Write-Host "  DEBUG: IndexParser found 0 song links on page $page"
                # Debug: dump all <a> links to help diagnose parser issues
                $allLinks = [regex]::Matches($htmlText, '<a[^>]+href=["\x27]([^"\x27]*)["\x27][^>]*>')
                $songLinks = $allLinks | Where-Object { $_.Groups[1].Value -match '/songs/' }
                Write-Host "  DEBUG: Total <a> links: $($allLinks.Count), with /songs/: $($songLinks.Count)"
                $songLinks | Select-Object -First 10 | ForEach-Object {
                    Write-Host "         $($_.Groups[1].Value)"
                }
            }
            break
        }

        # --- Filter songs to requested books ---
        # Each song title includes a book code like "(MP0023)" — we match
        # against the patterns for the books the user wants to scrape
        $pageSongs = @()
        foreach ($songEntry in $indexSongs) {
            foreach ($bookKey in $bookPats.Keys) {
                if ($bookPats[$bookKey].IsMatch($songEntry.Title)) {
                    $pageSongs += [PSCustomObject]@{
                        Title = $songEntry.Title
                        Url   = $songEntry.Url
                        Book  = $bookKey
                    }
                    break   # A song belongs to exactly one book
                }
            }
        }

        # --- Filter to a specific song number when -Song is specified ---
        # Match by extracting the numeric part from each song's book code
        $targetFound = $false
        if ($Song) {
            $filteredSongs = @()
            foreach ($ps in $pageSongs) {
                $cfg = $script:BOOK_CONFIG[$ps.Book]
                $m = [regex]::Match($ps.Title, $cfg.Pattern, [System.Text.RegularExpressions.RegexOptions]::IgnoreCase)
                if ($m.Success) {
                    $songNum = [int]$m.Groups[1].Value
                    if ($songNum -eq [int]$Song) {
                        $filteredSongs += $ps
                        $targetFound = $true
                    }
                }
            }
            $pageSongs = $filteredSongs
        }

        # Print page summary with running totals
        $pageStr = $page.ToString().PadLeft(4)
        Write-Host "  Page $pageStr  --  $($pageSongs.Count) matching songs  (saved: $saved, existed: $existed, skipped: $skipped)"

        # --- Scrape each song on this page ---
        $pageExisted = 0
        foreach ($ps in $pageSongs) {
            # Build absolute URL if the link is relative
            $songUrl = $ps.Url
            if ($songUrl.StartsWith("/")) {
                $songUrl = "$($script:BASE)$songUrl"
            }
            elseif (-not $songUrl.StartsWith("http")) {
                $songUrl = "$($script:BASE)/$songUrl"
            }

            $songResult = Invoke-ProcessSong -Title $ps.Title -Url $songUrl -Book $ps.Book `
                -OutputDir $OutputDir -NoFiles $NoFiles -Delay $Delay -FileCache $fileCache

            switch ($songResult) {
                "saved"   { $saved++;   break }
                "skipped" { $skipped++; break }
                "exists"  { $existed++; $pageExisted++; break }
            }
        }

        # If every song on this page already existed, print a compact summary
        # instead of per-song output (reduces noise during resumed scrapes)
        if ($pageExisted -eq $pageSongs.Count -and $pageSongs.Count -gt 0) {
            Write-Host "           >>  all $pageExisted songs on this page already exist" -ForegroundColor DarkGray
        }

        # When targeting a specific song and it was found, stop crawling
        if ($Song -and $targetFound) {
            Write-Host "`n  Target song $Song found -- stopping." -ForegroundColor Cyan
            break
        }

        $page++
        Start-Sleep -Seconds $Delay   # Rate limit between index pages
    }

    return @($saved, $skipped, $existed)
}


# ===========================================================================
# MAIN — entry point: interactive menu or CLI parameter processing
# ===========================================================================

function Invoke-Main {
    <#
    .SYNOPSIS
        Parse parameters, authenticate, and start the scrape.

    .DESCRIPTION
        Handles the full lifecycle:
        1. Detect whether to show interactive menu (no args) or use CLI params
        2. Validate the requested books
        3. Create an HTTP session and authenticate with the site
        4. Print configuration summary
        5. Run the crawl-and-scrape process
        6. Print final results
    #>

    # --- Handle -Help / -? ---
    if ($Help) {
        Get-Help $PSCommandPath -Detailed
        return
    }

    # --- Detect interactive mode ---
    # If neither Username nor Password was provided via CLI params, show
    # the interactive GUI menu (typical when script is double-clicked).
    $isInteractive = [string]::IsNullOrWhiteSpace($Username) -and [string]::IsNullOrWhiteSpace($Password)

    if ($isInteractive) {
        $config = Show-InteractiveMenu

        # Unpack the menu configuration into local variables
        $effectiveUsername  = $config.Username
        $effectivePassword  = $config.Password
        $effectiveOutput    = $config.Output
        $effectiveBooks     = $config.Books
        $effectiveStartPage = $config.StartPage
        $effectiveDelay     = $config.Delay
        $effectiveNoFiles   = $config.NoFiles
        $effectiveDebug     = $config.Debug
        $effectiveForce     = $config.Force
        $effectiveSong      = ""   # Interactive mode doesn't support -Song
    }
    else {
        # CLI mode — use the parameters as provided
        $effectiveUsername  = $Username
        $effectivePassword  = $Password
        $effectiveOutput    = $Output
        $effectiveBooks     = $Books
        $effectiveStartPage = $StartPage
        $effectiveDelay     = $Delay
        $effectiveNoFiles   = [bool]$NoFiles
        $effectiveDebug     = [bool]$Debug
        $effectiveForce     = [bool]$Force
        $effectiveSong      = $Song

        # Prompt for credentials if not provided via CLI arguments
        if ([string]::IsNullOrWhiteSpace($effectiveUsername)) {
            $effectiveUsername = Read-Host "Mission Praise username/email"
        }
        if ([string]::IsNullOrWhiteSpace($effectivePassword)) {
            $securePass = Read-Host "Mission Praise password" -AsSecureString
            $bstr = [System.Runtime.InteropServices.Marshal]::SecureStringToBSTR($securePass)
            $effectivePassword = [System.Runtime.InteropServices.Marshal]::PtrToStringAuto($bstr)
            [System.Runtime.InteropServices.Marshal]::ZeroFreeBSTR($bstr)
        }
    }

    # Set the global debug flag
    $script:DEBUG_MODE = $effectiveDebug

    # Parse and validate the books argument (filter out invalid entries)
    $bookList = @()
    foreach ($b in $effectiveBooks.Split(",")) {
        $trimmed = $b.Trim().ToLower()
        if ($script:BOOK_CONFIG.ContainsKey($trimmed)) {
            $bookList += $trimmed
        }
    }

    if ($bookList.Count -eq 0) {
        Write-Host "No valid books specified. Use -Books mp,cp,jp" -ForegroundColor Red
        return
    }

    # Authenticate with the site
    $loginSuccess = Invoke-Login -Username $effectiveUsername -Password $effectivePassword
    if (-not $loginSuccess) {
        # Login failed — error already printed by Invoke-Login
        if ($isInteractive) {
            Write-Host ""
            Write-Host "  Press any key to exit..." -ForegroundColor DarkGray
            $null = $Host.UI.RawUI.ReadKey("NoEcho,IncludeKeyDown")
        }
        return
    }

    # Print configuration summary
    $absOutput = (Resolve-Path -Path $effectiveOutput -ErrorAction SilentlyContinue)
    if (-not $absOutput) {
        $absOutput = [System.IO.Path]::GetFullPath($effectiveOutput)
    }
    Write-Host ""
    Write-Host "Books    : $(($bookList | ForEach-Object { $_.ToUpper() }) -join ', ')"
    Write-Host "Output   : $absOutput"
    Write-Host "Downloads: $(if ($effectiveNoFiles) { 'disabled' } else { 'enabled (words, music, audio)' })"
    if ($effectiveSong) {
        Write-Host "Song     : $effectiveSong (single-song mode, force enabled)" -ForegroundColor Cyan
    }
    elseif ($effectiveForce) {
        Write-Host "Force    : ON -- overwriting existing files" -ForegroundColor Yellow
    }
    Write-Host ""

    # Run the main crawl-and-scrape process
    $results = Invoke-CrawlAndScrape -Books $bookList -OutputDir $effectiveOutput `
        -NoFiles $effectiveNoFiles -StartPage $effectiveStartPage -Delay $effectiveDelay `
        -Force $effectiveForce -Song $effectiveSong

    $savedCount   = $results[0]
    $skippedCount = $results[1]
    $existedCount = $results[2]

    # Print final summary
    Write-Host ""
    Write-Host "Done!  $savedCount saved, $existedCount already existed, $skippedCount skipped." -ForegroundColor Green
    Write-Host "Output: $absOutput"
    if ($skippedCount -gt 0) {
        $logPath = Join-Path $effectiveOutput "skipped.log"
        Write-Host "Skipped songs logged to: $logPath"
    }

    # Keep the window open if running interactively (double-clicked)
    if ($isInteractive) {
        Write-Host ""
        Write-Host "  Press any key to exit..." -ForegroundColor DarkGray
        $null = $Host.UI.RawUI.ReadKey("NoEcho,IncludeKeyDown")
    }
}


# ---------------------------------------------------------------------------
# Script entry point — call the main function
# ---------------------------------------------------------------------------
Invoke-Main
