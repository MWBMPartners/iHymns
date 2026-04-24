@if (@CodeSection == @Batch) @then
@echo off
REM ============================================================================
REM MissionPraise.com.bat
REM importers/scrapers/MissionPraise.com.bat
REM
REM Mission Praise Scraper (Windows Batch/JScript Hybrid)
REM Scrapes lyrics and downloads files from missionpraise.com.
REM Copyright 2025-2026 MWBM Partners Ltd.
REM
REM ============================================================================
REM HYBRID TECHNIQUE EXPLANATION
REM ============================================================================
REM
REM This file is simultaneously a valid Windows Batch script and a valid JScript
REM file. The trick works because:
REM
REM   1. The first line "@if (@CodeSection == @Batch) @then" is:
REM      - In Batch: "@if" runs the "if" command. Since @CodeSection and @Batch
REM        are undefined environment variables, they evaluate to empty strings,
REM        making the condition false. The @then part is ignored. The "@" prefix
REM        suppresses echo. Batch then falls through to the next line.
REM      - In JScript: This is a conditional compilation directive. @CodeSection
REM        is undefined (evaluates to NaN), @Batch is also undefined, so the
REM        condition NaN == NaN is false. Everything between @if and @end is
REM        skipped by the JScript engine.
REM
REM   2. The Batch section runs between "@then" and "@end". It handles the
REM      interactive menu, collects user input, validates settings, and then
REM      launches the JScript section by calling:
REM        cscript //nologo //e:jscript "%~f0" [arguments...]
REM      This tells Windows Script Host (cscript.exe) to interpret THIS SAME
REM      FILE as JScript code. The JScript engine ignores the Batch section
REM      (wrapped in @if/@end) and executes only the JScript code below @end.
REM
REM   3. The JScript section (after @end) contains the actual scraping engine
REM      using native Windows COM objects:
REM        - WinHttp.WinHttpRequest.5.1  for HTTP requests (with cookie support)
REM        - ADODB.Stream                for binary file I/O
REM        - Scripting.FileSystemObject  for filesystem operations
REM        - WScript.Shell               for environment access
REM
REM AUTHENTICATION FLOW:
REM   1. GET wp-login.php to extract CSRF nonces (hidden form fields)
REM   2. 2-second pause to appear human-like
REM   3. POST credentials + nonces with browser-like headers
REM   4. Detect: WAF blocks (Sucuri), incorrect credentials, login errors
REM   5. Verify login by fetching /songs/ and checking for logged-in indicators
REM
REM DEPENDENCIES: None. Uses only native Windows components:
REM   - cscript.exe (Windows Script Host) -- present on all Windows versions
REM   - WinHttp.WinHttpRequest.5.1 -- native HTTP client since Windows XP SP3
REM   - ADODB.Stream -- native since Windows 2000 (part of MDAC/ADO)
REM   - Scripting.FileSystemObject -- native since Windows 98
REM
REM OUTPUT FORMAT:
REM   Lyrics saved as plain text:
REM     {padded_number} ({LABEL}) - {Title Case Title}.txt
REM   Downloads use the same base name with type suffixes:
REM     {base}.rtf            (words document)
REM     {base}_music.pdf      (sheet music)
REM     {base}_audio.mp3      (audio recording)
REM   Files organised into book-specific subdirectories:
REM     hymns\Mission Praise [MP]\
REM     hymns\Carol Praise [CP]\
REM     hymns\Junior Praise [JP]\
REM
REM ============================================================================

REM === INITIALIZE DEFAULT SETTINGS ===
REM These variables hold the current configuration. They are modified by the
REM interactive menu and passed as arguments to the JScript scraping engine.

setlocal enabledelayedexpansion

REM Credentials (initially empty -- must be set by the user before scraping)
set "MP_USERNAME="
set "MP_PASSWORD="

REM Books to scrape: mp, cp, jp, or all (comma-separated)
set "MP_BOOKS=all"

REM Output directory (default: "hymns" subfolder in the current directory)
set "MP_OUTPUT=.\hymns"

REM Start page for the paginated song index (1 = beginning)
set "MP_STARTPAGE=1"

REM Whether to download files (words/music/audio): yes or no
set "MP_DOWNLOAD=yes"

REM Delay between HTTP requests in milliseconds (1200ms = 1.2 seconds)
set "MP_DELAY=1200"

REM Debug mode: dumps full HTML responses for troubleshooting
set "MP_DEBUG=no"

REM Force mode: re-download and overwrite all files, ignoring existing file cache.
REM When enabled, the scraper skips building the file existence cache and treats
REM every song as new, downloading lyrics and files even if they already exist.
set "MP_FORCE=no"

REM ============================================================================
REM CHECK FOR HELP FLAG
REM ============================================================================
REM If the user passes /? or no arguments, display usage information.

if "%~1"=="/?" goto :showhelp
if "%~1"=="-?" goto :showhelp
if "%~1"=="--help" goto :showhelp
if "%~1"=="-h" goto :showhelp

REM Check for /force and /song:NNN command-line flags.
REM These allow force mode and single-song mode to be activated directly
REM from the command line, bypassing the interactive menu. Checks all
REM positional arguments so flags can appear anywhere on the command line.
set "MP_SONG="
for %%A in (%*) do (
    if /i "%%~A"=="/force" set "MP_FORCE=yes"
    for /f "tokens=1,2 delims=:" %%B in ("%%~A") do (
        if /i "%%B"=="/song" set "MP_SONG=%%C"
    )
)
REM If /song is specified, auto-enable force mode so the song is re-downloaded
if defined MP_SONG set "MP_FORCE=yes"

REM ============================================================================
REM ASCII BANNER
REM ============================================================================
:banner
cls
echo.
echo  ============================================================================
echo  #     # ###  #####   #####  ###  #####  #   #    ####  ####   ###  ###  ##### #####
echo  ##   ##  #  #       #        #  #     # ##  #    #   # #   # #   #  #  #     #
echo  # # # #  #   ####    ####    #  #     # # # #    ####  ####  #####  #   ####  ####
echo  #  #  #  #       #       #   #  #     # #  ##    #     #  #  #   #  #       # #
echo  #     # ### #####   #####   ###  #####  #   #    #     #   # #   # ### #####  #####
echo.
echo   Mission Praise Scraper - Windows Batch/JScript Hybrid
echo   Copyright 2025-2026 MWBM Partners Ltd.
echo  ============================================================================
echo.

REM ============================================================================
REM MAIN MENU LOOP
REM ============================================================================
REM Display the interactive numbered menu and process user choices.
REM The menu loops until the user selects option S (START) or 0 (Exit).

:menu
echo   Current Settings:
echo   -----------------

REM Display username (show as "not set" if empty)
if defined MP_USERNAME (
    echo   Username    : !MP_USERNAME!
) else (
    echo   Username    : [not set]
)

REM Display password (masked for security -- show asterisks if set)
if defined MP_PASSWORD (
    echo   Password    : ********
) else (
    echo   Password    : [not set]
)

REM Display books selection
if "!MP_BOOKS!"=="all" (
    echo   Books       : ALL ^(MP, CP, JP^)
) else (
    echo   Books       : !MP_BOOKS!
)

echo   Output Dir  : !MP_OUTPUT!
echo   Start Page  : !MP_STARTPAGE!
echo   Downloads   : !MP_DOWNLOAD!

REM Convert delay from milliseconds to seconds for display (integer division)
set /a "MP_DELAY_SEC=!MP_DELAY! / 1000"
echo   Delay       : !MP_DELAY_SEC!s ^(!MP_DELAY!ms^)
echo   Debug Mode  : !MP_DEBUG!
echo   Force Mode  : !MP_FORCE!
echo.
echo   -----------------
echo   Menu:
echo     1. Enter credentials (username + password)
echo     2. Choose books (mp/cp/jp/all)
echo     3. Set output directory
echo     4. Set start page
echo     5. Toggle file downloads (currently: !MP_DOWNLOAD!)
echo     6. Set delay between requests
echo     7. Toggle debug mode (currently: !MP_DEBUG!)
echo     8. Toggle force mode (currently: !MP_FORCE!)
echo     9. Show current settings (refresh)
echo     S. START scraping
echo     0. Exit
echo.
set "CHOICE="
set /p "CHOICE=  Enter choice [0-9/S]: "

REM --- Process menu choice ---

if "!CHOICE!"=="1" goto :set_credentials
if "!CHOICE!"=="2" goto :set_books
if "!CHOICE!"=="3" goto :set_output
if "!CHOICE!"=="4" goto :set_startpage
if "!CHOICE!"=="5" goto :toggle_downloads
if "!CHOICE!"=="6" goto :set_delay
if "!CHOICE!"=="7" goto :toggle_debug
if "!CHOICE!"=="8" goto :toggle_force
if "!CHOICE!"=="9" goto :banner
if /i "!CHOICE!"=="S" goto :start_scrape
if "!CHOICE!"=="0" goto :exit_script

REM Invalid input -- redisplay menu
echo.
echo   [!] Invalid choice. Please enter a number from 0-9 or S.
echo.
goto :menu

REM ============================================================================
REM MENU OPTION HANDLERS
REM ============================================================================

REM --- Option 1: Enter Credentials ---
:set_credentials
echo.
set /p "MP_USERNAME=  Enter username/email: "

REM NOTE ON PASSWORD INPUT:
REM Windows Batch (cmd.exe) does not support hiding input natively.
REM The "set /p" command will display the password as the user types it.
REM This is a known limitation of the Batch environment. For truly hidden
REM input, use the Python version of this scraper instead.
REM
REM Alternative approaches that were considered but rejected:
REM   - PowerShell's Read-Host -AsSecureString: Requires PowerShell, adds
REM     complexity, and the secure string can't easily be passed back to Batch.
REM   - VBScript InputBox with password masking: Opens a GUI dialog which
REM     breaks the console-only workflow.
REM   - Reading from a file: Less secure than typing (file persists on disk).
echo.
echo   NOTE: Password will be visible as you type (Batch limitation).
echo   For hidden input, use the Python version of this scraper.
set /p "MP_PASSWORD=  Enter password: "
echo.
goto :banner

REM --- Option 2: Choose Books ---
:set_books
echo.
echo   Available books:
echo     mp  - Mission Praise (~1000+ songs, 4-digit numbering)
echo     cp  - Carol Praise (~300 songs, 3-digit numbering)
echo     jp  - Junior Praise (~300 songs, 3-digit numbering)
echo     all - All three books (mp,cp,jp)
echo.
echo   Enter one or more, comma-separated (e.g. mp,cp):
set /p "MP_BOOKS=  Books: "
REM Convert to lowercase for consistency
REM (Batch doesn't have a built-in lowercase function, but the JScript
REM section handles case-insensitive comparison, so mixed case is fine)
echo.
goto :banner

REM --- Option 3: Set Output Directory ---
:set_output
echo.
echo   Current output directory: !MP_OUTPUT!
set /p "MP_OUTPUT=  Enter new output directory: "
echo.
goto :banner

REM --- Option 4: Set Start Page ---
:set_startpage
echo.
echo   Current start page: !MP_STARTPAGE!
echo   (Use this to resume scraping from a specific index page)
set /p "MP_STARTPAGE=  Enter start page number: "

REM Validate that the input is a positive integer
REM The "set /a" trick: if the input isn't numeric, it evaluates to 0
set /a "VALIDATE=!MP_STARTPAGE!" 2>nul
if !VALIDATE! LEQ 0 (
    echo   [!] Invalid page number. Reset to 1.
    set "MP_STARTPAGE=1"
)
echo.
goto :banner

REM --- Option 5: Toggle File Downloads ---
:toggle_downloads
if "!MP_DOWNLOAD!"=="yes" (
    set "MP_DOWNLOAD=no"
    echo.
    echo   File downloads DISABLED (lyrics only).
) else (
    set "MP_DOWNLOAD=yes"
    echo.
    echo   File downloads ENABLED (words, music, audio).
)
echo.
goto :banner

REM --- Option 6: Set Delay ---
:set_delay
echo.
echo   Current delay: !MP_DELAY!ms
echo   Recommended: 1200ms (1.2 seconds) -- be respectful to the server.
echo   Enter delay in milliseconds (e.g. 1200 for 1.2s):
set /p "MP_DELAY=  Delay (ms): "

REM Validate that the input is a positive integer
set /a "VALIDATE=!MP_DELAY!" 2>nul
if !VALIDATE! LEQ 0 (
    echo   [!] Invalid delay. Reset to 1200ms.
    set "MP_DELAY=1200"
)
echo.
goto :banner

REM --- Option 7: Toggle Debug Mode ---
:toggle_debug
if "!MP_DEBUG!"=="yes" (
    set "MP_DEBUG=no"
    echo.
    echo   Debug mode DISABLED.
) else (
    set "MP_DEBUG=yes"
    echo.
    echo   Debug mode ENABLED -- HTML responses will be dumped to console.
)
echo.
goto :banner

REM --- Option 8: Toggle Force Mode ---
REM Force mode skips the file existence cache and re-downloads everything,
REM overwriting any existing files. Useful when lyrics or files have been
REM updated on the server and you want to refresh your local copies.
:toggle_force
if "!MP_FORCE!"=="yes" (
    set "MP_FORCE=no"
    echo.
    echo   Force mode DISABLED -- existing files will be skipped.
) else (
    set "MP_FORCE=yes"
    echo.
    echo   Force mode ENABLED -- will re-download and overwrite existing files.
)
echo.
goto :banner

REM ============================================================================
REM START SCRAPING -- Validate settings and launch JScript engine
REM ============================================================================
:start_scrape
echo.

REM Validate that credentials have been entered
if not defined MP_USERNAME (
    echo   [!] ERROR: Username is required. Use option 1 to enter credentials.
    echo.
    goto :menu
)
if not defined MP_PASSWORD (
    echo   [!] ERROR: Password is required. Use option 1 to enter credentials.
    echo.
    goto :menu
)

REM Validate books selection
if "!MP_BOOKS!"=="" (
    echo   [!] ERROR: No books selected. Use option 2 to choose books.
    echo.
    goto :menu
)

REM Display final configuration before starting
echo   ============================================================================
echo   STARTING SCRAPE
echo   ============================================================================
echo   Username   : !MP_USERNAME!
echo   Books      : !MP_BOOKS!
echo   Output     : !MP_OUTPUT!
echo   Start Page : !MP_STARTPAGE!
echo   Downloads  : !MP_DOWNLOAD!
echo   Delay      : !MP_DELAY!ms
echo   Debug      : !MP_DEBUG!
echo   Force      : !MP_FORCE!
echo   ============================================================================
echo.

REM Launch the JScript section of THIS SAME FILE using cscript.exe.
REM cscript.exe is the console-mode Windows Script Host, present on all
REM Windows versions since Windows 98. The //nologo flag suppresses the
REM WSH version banner. The //e:jscript flag forces JScript interpretation.
REM "%~f0" expands to the full path of this batch file.
REM
REM Arguments are passed positionally:
REM   arg0 = username
REM   arg1 = password
REM   arg2 = books (comma-separated or "all")
REM   arg3 = output directory path
REM   arg4 = start page number
REM   arg5 = download files flag ("yes" or "no")
REM   arg6 = delay in milliseconds
REM   arg7 = debug flag ("yes" or "no")
REM   arg8 = force flag ("yes" or "no") -- skip file cache, re-download everything
REM   arg9 = song number (optional) -- scrape only this song number

cscript //nologo //e:jscript "%~f0" "!MP_USERNAME!" "!MP_PASSWORD!" "!MP_BOOKS!" "!MP_OUTPUT!" "!MP_STARTPAGE!" "!MP_DOWNLOAD!" "!MP_DELAY!" "!MP_DEBUG!" "!MP_FORCE!" "!MP_SONG!"

echo.
echo   ============================================================================
echo   Scraping complete. Press any key to return to the menu.
echo   ============================================================================
pause >nul
goto :banner

REM ============================================================================
REM HELP / USAGE
REM ============================================================================
:showhelp
echo.
echo  MissionPraise.com.bat -- Mission Praise Scraper (Windows Batch/JScript Hybrid)
echo  Copyright 2025-2026 MWBM Partners Ltd.
echo.
echo  DESCRIPTION:
echo    Scrapes lyrics and downloads files from missionpraise.com.
echo    Authenticates with WordPress login, crawls the paginated song index,
echo    and saves lyrics as plain text with optional file downloads.
echo.
echo  USAGE:
echo    MissionPraise.com.bat           Launch interactive menu
echo    MissionPraise.com.bat /force    Launch with force mode (re-download all)
echo    MissionPraise.com.bat /song:123 Scrape only song number 123
echo    MissionPraise.com.bat /?        Show this help
echo.
echo  FLAGS:
echo    /force      Skip the file existence cache and re-download everything,
echo                overwriting existing files. Useful when server content has
echo                been updated. Can also be toggled from the interactive menu.
echo    /song:NNN   Scrape only the specified song number. Automatically enables
echo                force mode. Stops after finding and processing the song.
echo.
echo  SUPPORTED BOOKS:
echo    MP  - Mission Praise   (~1000+ songs, 4-digit numbering)
echo    CP  - Carol Praise     (~300 songs, 3-digit numbering)
echo    JP  - Junior Praise    (~300 songs, 3-digit numbering)
echo.
echo  OUTPUT FORMAT:
echo    Lyrics:   0001 (MP) - Abba Father.txt
echo    Words:    0001 (MP) - Abba Father.rtf
echo    Music:    0001 (MP) - Abba Father_music.pdf
echo    Audio:    0001 (MP) - Abba Father_audio.mp3
echo.
echo  EXAMPLES (using interactive menu):
echo    1. Run MissionPraise.com.bat
echo    2. Enter credentials (option 1)
echo    3. Choose books (option 2) -- default is ALL
echo    4. Start scraping (option S)
echo.
echo  REQUIREMENTS:
echo    - Windows XP SP3 or later (uses WinHttp + ADODB)
echo    - Valid missionpraise.com subscription credentials
echo    - No Python or other dependencies required
echo.
echo  NOTES:
echo    - This is a Batch/JScript hybrid -- no external tools needed
echo    - The scraper is resumable: existing files are detected and skipped
echo    - Use /force to override resume behaviour and re-download everything
echo    - Password input is visible (Batch limitation)
echo    - For hidden password input, use the Python version instead
echo.
goto :exit_script

REM ============================================================================
REM EXIT
REM ============================================================================
:exit_script
endlocal
exit /b 0

@end
// =============================================================================
// JSCRIPT SECTION -- Mission Praise Scraping Engine
// =============================================================================
//
// This section contains the full scraping engine implemented in JScript (the
// Microsoft implementation of ECMAScript 3, native to all Windows versions).
//
// It is invoked by the Batch section above via:
//   cscript //nologo //e:jscript "%~f0" [arguments...]
//
// The JScript engine skips the Batch section (wrapped in @if/@end conditional
// compilation directives) and executes only this code.
//
// COM Objects Used:
//   - WinHttp.WinHttpRequest.5.1: HTTP client with automatic cookie handling,
//     SSL/TLS support, and redirect following. Native since Windows XP SP3.
//   - ADODB.Stream: Binary and text stream I/O. Used for saving downloaded
//     binary files (RTF, PDF, MP3) and reading/writing text with encoding
//     control. Native since Windows 2000.
//   - Scripting.FileSystemObject: File system operations (create directories,
//     check file existence, enumerate directory contents). Native since Win98.
//   - WScript.Shell: Access to environment variables and process execution.
//
// Copyright 2025-2026 MWBM Partners Ltd.
// =============================================================================


// ---------------------------------------------------------------------------
// Argument parsing -- extract settings passed from the Batch section
// ---------------------------------------------------------------------------
// Arguments are passed positionally from the Batch section's cscript call.
// WScript.Arguments provides access to command-line arguments.

var args = WScript.Arguments;

// Validate that all required arguments were provided
if (args.length < 8) {
    WScript.Echo("ERROR: Insufficient arguments. This script should be launched from the Batch menu.");
    WScript.Echo("Usage: cscript //e:jscript MissionPraise.com.bat username password books output startpage download delay debug [force]");
    WScript.Quit(1);
}

// Extract arguments into named variables for clarity
var USERNAME   = args(0);            // missionpraise.com login email
var PASSWORD   = args(1);            // missionpraise.com password
var BOOKS_ARG  = args(2);            // Books to scrape: "all" or comma-separated "mp,cp,jp"
var OUTPUT_DIR = args(3);            // Base output directory path
var START_PAGE = parseInt(args(4), 10) || 1;  // Index page to start from
var DO_DOWNLOAD = (args(5).toLowerCase() === "yes");  // Whether to download files
var DELAY_MS   = parseInt(args(6), 10) || 1200;       // Delay between requests in ms
var DEBUG      = (args(7).toLowerCase() === "yes");    // Debug mode flag

// Force mode flag (arg8, optional for backward compatibility).
// When true, the file existence cache is skipped entirely -- every song is
// treated as new, and lyrics/downloads are re-downloaded and overwritten.
// This is useful when content on the server has been corrected or updated.
var FORCE      = (args.length > 8 && args(8).toLowerCase() === "yes");

// Single-song mode (arg9, optional). When set to a song number, only that
// song will be scraped. Force mode is auto-enabled and the scraper stops
// after finding the song.
var SONG_FILTER = 0;  // 0 = disabled (scrape all songs)
if (args.length > 9 && args(9) !== "") {
    SONG_FILTER = parseInt(args(9), 10) || 0;
}


// ---------------------------------------------------------------------------
// Constants -- URLs, patterns, and configuration
// ---------------------------------------------------------------------------

/** Base URL for the Mission Praise website */
var BASE_URL = "https://missionpraise.com";

/** WordPress standard login endpoint */
var LOGIN_URL = BASE_URL + "/wp-login.php";

/** Paginated song index URL -- append page number (e.g. /songs/page/1/) */
var INDEX_URL = BASE_URL + "/songs/page/";

/**
 * Browser-like User-Agent string (Chrome on Windows).
 * Modern WAFs (Sucuri, Cloudflare, etc.) check this header to distinguish
 * real browsers from automated scripts. An empty or generic UA will be blocked.
 */
var USER_AGENT = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) " +
    "AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 Edg/145.0.0.0";


// ---------------------------------------------------------------------------
// Book configuration -- defines the three hymnbooks scraped from the site
// ---------------------------------------------------------------------------
// Each book has:
//   label:   Short identifier used in filenames (e.g. "MP", "CP", "JP")
//   pad:     Number of digits to zero-pad the hymn number to (MP=4, others=3)
//   pattern: Regex to extract the book number from the index page title
//            e.g. "Amazing Grace (MP0023)" -> match group(1) = "0023"
//   subdir:  Human-readable subdirectory name for organised file output

var BOOK_CONFIG = {
    "mp": { label: "MP", pad: 4, pattern: /\(MP(\d+)\)/i, subdir: "Mission Praise [MP]" },
    "cp": { label: "CP", pad: 3, pattern: /\(CP(\d+)\)/i, subdir: "Carol Praise [CP]" },
    "jp": { label: "JP", pad: 3, pattern: /\(JP(\d+)\)/i, subdir: "Junior Praise [JP]" }
};


// ---------------------------------------------------------------------------
// MIME type -> file extension mapping for downloaded files
// ---------------------------------------------------------------------------
// When downloading files (words, music, audio), the server's Content-Type
// header tells us what format the file is in. This mapping converts common
// MIME types to their standard file extensions.

var MIME_TO_EXT = {
    "application/rtf":          ".rtf",
    "text/rtf":                 ".rtf",
    "application/msword":       ".doc",
    "application/vnd.openxmlformats-officedocument.wordprocessingml.document": ".docx",
    "application/pdf":          ".pdf",
    "audio/midi":               ".mid",
    "audio/x-midi":             ".mid",
    "audio/mpeg":               ".mp3",
    "audio/mp3":                ".mp3",
    "audio/wav":                ".wav",
    "audio/x-wav":              ".wav",
    "audio/ogg":                ".ogg",
    "application/octet-stream": ""    // Generic binary -- fall back to URL/magic bytes
};


// ---------------------------------------------------------------------------
// Magic bytes -- file type detection from binary content
// ---------------------------------------------------------------------------
// When Content-Type header is unhelpful (e.g. "application/octet-stream")
// and the URL doesn't reveal the file type, we identify the format by
// examining the first few bytes. Most file formats begin with a distinctive
// signature ("magic number").
//
// Note: In JScript, we compare byte values from ADODB.Stream reads because
// direct binary string comparison is unreliable with extended characters.
// We store signatures as arrays of decimal byte values for clarity.

var MAGIC_BYTES = [
    { sig: [0x25, 0x50, 0x44, 0x46],             ext: ".pdf"  },  // %PDF
    { sig: [0x50, 0x4B, 0x03, 0x04],             ext: ".docx" },  // PK.. (ZIP/docx)
    { sig: [0xD0, 0xCF, 0x11, 0xE0],             ext: ".doc"  },  // OLE2 compound
    { sig: [0x7B, 0x5C, 0x72, 0x74, 0x66],       ext: ".rtf"  },  // {\rtf
    { sig: [0x4D, 0x54, 0x68, 0x64],             ext: ".mid"  },  // MThd (MIDI)
    { sig: [0x49, 0x44, 0x33],                   ext: ".mp3"  },  // ID3 (MP3)
    { sig: [0xFF, 0xFB],                         ext: ".mp3"  },  // MPEG1 Layer3
    { sig: [0xFF, 0xF3],                         ext: ".mp3"  },  // MPEG2 Layer3
    { sig: [0x4F, 0x67, 0x67, 0x53],             ext: ".ogg"  },  // OggS
    { sig: [0x52, 0x49, 0x46, 0x46],             ext: ".wav"  },  // RIFF (WAV)
    { sig: [0x66, 0x4C, 0x61, 0x43],             ext: ".flac" }   // fLaC
];


// ---------------------------------------------------------------------------
// Parse books argument -- determine which books to scrape
// ---------------------------------------------------------------------------
// The BOOKS_ARG comes from the Batch menu: "all" means mp,cp,jp; otherwise
// it's a comma-separated list like "mp,cp" or just "mp".

var booksToScrape = [];

if (BOOKS_ARG.toLowerCase() === "all") {
    // Scrape all three books
    booksToScrape = ["mp", "cp", "jp"];
} else {
    // Parse the comma-separated list and validate each entry
    var parts = BOOKS_ARG.toLowerCase().split(",");
    for (var i = 0; i < parts.length; i++) {
        var b = parts[i].replace(/^\s+|\s+$/g, "");  // Trim whitespace
        if (BOOK_CONFIG[b]) {
            booksToScrape.push(b);
        }
    }
}

// Abort if no valid books were selected
if (booksToScrape.length === 0) {
    WScript.Echo("ERROR: No valid books specified. Use mp, cp, jp, or all.");
    WScript.Quit(1);
}


// ---------------------------------------------------------------------------
// Global COM objects -- created once and reused throughout the script
// ---------------------------------------------------------------------------

/** Scripting.FileSystemObject for all file/directory operations */
var fso = new ActiveXObject("Scripting.FileSystemObject");


// ---------------------------------------------------------------------------
// Cookie management -- manual cookie jar for WinHttpRequest
// ---------------------------------------------------------------------------
// WinHttp.WinHttpRequest.5.1 does NOT automatically manage cookies across
// requests (unlike browser or Python's cookiejar). We must manually:
//   1. Extract Set-Cookie headers from responses
//   2. Store them in our cookie jar
//   3. Send them as Cookie headers on subsequent requests
//
// This is essential for WordPress login session management.

/** Global cookie storage: maps cookie names to their full "name=value" strings */
var cookieJar = {};

/**
 * Extract cookies from a Set-Cookie response header and store them in the jar.
 *
 * Set-Cookie headers have the format:
 *   name=value; path=/; expires=...; HttpOnly; Secure
 * We only need the "name=value" part for sending back.
 * Multiple Set-Cookie headers may be present (separated by commas or newlines
 * depending on the server/proxy configuration).
 *
 * @param {string} setCookieHeader - The raw Set-Cookie header value
 */
function extractCookies(setCookieHeader) {
    if (!setCookieHeader) return;

    // Split on newlines (WinHttp may concatenate multiple Set-Cookie headers
    // with newlines). Also handle comma-separated cookies, but be careful
    // not to split on commas within cookie values (e.g. expires=Thu, 01 Jan...).
    var lines = setCookieHeader.split(/\n/);
    for (var i = 0; i < lines.length; i++) {
        var line = lines[i].replace(/^\s+|\s+$/g, "");
        if (!line) continue;

        // Sometimes multiple cookies are concatenated with comma + space.
        // However, "expires=Thu, 01 Jan 2030" also contains commas.
        // Strategy: split on ", " only if followed by a token containing "="
        // For safety, we just take the first cookie from each line.

        // Extract just the name=value portion (before the first semicolon)
        var nameValue = line.split(";")[0].replace(/^\s+|\s+$/g, "");
        if (nameValue.indexOf("=") > 0) {
            var eqPos = nameValue.indexOf("=");
            var name = nameValue.substring(0, eqPos);
            cookieJar[name] = nameValue;
        }
    }
}

/**
 * Build the Cookie header string from all stored cookies.
 *
 * Concatenates all "name=value" pairs with "; " separators, which is the
 * standard format for the Cookie request header.
 *
 * @returns {string} The Cookie header value, or empty string if no cookies
 */
function getCookieHeader() {
    var parts = [];
    for (var name in cookieJar) {
        if (cookieJar.hasOwnProperty(name)) {
            parts.push(cookieJar[name]);
        }
    }
    return parts.join("; ");
}


// ---------------------------------------------------------------------------
// HTTP helper functions -- request/response utilities
// ---------------------------------------------------------------------------

/**
 * Perform an HTTP GET request and return the response body as text.
 *
 * Uses WinHttp.WinHttpRequest.5.1 which handles SSL/TLS, redirects, and
 * response decompression natively. We add browser-like headers to avoid
 * WAF blocks and manually manage cookies.
 *
 * @param {string} url - The URL to fetch
 * @returns {Object|null} Object with { text, finalUrl, status } or null on error
 */
function httpGet(url) {
    try {
        var http = new ActiveXObject("WinHttp.WinHttpRequest.5.1");

        // Open a GET request. The third parameter (true/false) controls async;
        // we use synchronous (false) for simplicity.
        http.Open("GET", url, false);

        // Set browser-like headers to pass WAF checks
        http.SetRequestHeader("User-Agent", USER_AGENT);
        http.SetRequestHeader("Accept", "text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8");
        http.SetRequestHeader("Accept-Language", "en-GB,en;q=0.9");
        http.SetRequestHeader("Connection", "keep-alive");
        http.SetRequestHeader("Upgrade-Insecure-Requests", "1");
        // Sec-Fetch-* headers: modern security headers checked by WAFs
        http.SetRequestHeader("Sec-Fetch-Dest", "document");
        http.SetRequestHeader("Sec-Fetch-Mode", "navigate");
        http.SetRequestHeader("Sec-Fetch-Site", "same-origin");
        http.SetRequestHeader("Sec-Fetch-User", "?1");
        http.SetRequestHeader("Cache-Control", "max-age=0");

        // Send stored cookies with the request
        var cookies = getCookieHeader();
        if (cookies) {
            http.SetRequestHeader("Cookie", cookies);
        }

        // Configure timeouts: resolve=5s, connect=10s, send=15s, receive=30s
        // These prevent the script from hanging on unresponsive servers
        http.SetTimeouts(5000, 10000, 15000, 30000);

        // Allow automatic redirect following (up to 10 redirects)
        http.Option(6, false);  // WinHttpRequestOption_EnableRedirects = 6

        http.Send();

        // Extract and store any cookies from the response
        try {
            var setCookie = http.GetResponseHeader("Set-Cookie");
            extractCookies(setCookie);
        } catch (e) {
            // No Set-Cookie header -- that's fine, not all responses set cookies
        }

        // Return the response text, final URL, and status code
        return {
            text: http.ResponseText,
            // WinHttpRequest follows redirects automatically; the final URL
            // can be read from the response URL option
            finalUrl: url,  // WinHttp doesn't expose final URL directly
            status: http.Status
        };
    } catch (e) {
        WScript.Echo("  [!] HTTP GET error for " + url + ": " + e.message);
        return null;
    }
}

/**
 * Perform an HTTP POST request (used for WordPress login).
 *
 * Sends URL-encoded form data with proper Content-Type and headers.
 * The Referer and Origin headers are critical for passing Sucuri WAF checks.
 *
 * @param {string} url - The URL to POST to
 * @param {string} postData - URL-encoded form data string
 * @param {string} referer - The Referer header value (usually the login page URL)
 * @returns {Object|null} Object with { text, status } or null on error
 */
function httpPost(url, postData, referer) {
    try {
        var http = new ActiveXObject("WinHttp.WinHttpRequest.5.1");

        http.Open("POST", url, false);

        // Set browser-like headers
        http.SetRequestHeader("User-Agent", USER_AGENT);
        http.SetRequestHeader("Accept", "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8");
        http.SetRequestHeader("Accept-Language", "en-GB,en;q=0.9");
        http.SetRequestHeader("Connection", "keep-alive");
        // Content-Type for URL-encoded form submission
        http.SetRequestHeader("Content-Type", "application/x-www-form-urlencoded");
        // Referer and Origin are checked by Sucuri WAF to prevent CSRF
        http.SetRequestHeader("Referer", referer);
        http.SetRequestHeader("Origin", BASE_URL);
        http.SetRequestHeader("Upgrade-Insecure-Requests", "1");
        // Sec-Fetch headers for form submission context
        http.SetRequestHeader("Sec-Fetch-Dest", "document");
        http.SetRequestHeader("Sec-Fetch-Mode", "navigate");
        http.SetRequestHeader("Sec-Fetch-Site", "same-origin");
        http.SetRequestHeader("Sec-Fetch-User", "?1");

        // Send stored cookies with the request
        var cookies = getCookieHeader();
        if (cookies) {
            http.SetRequestHeader("Cookie", cookies);
        }

        // Configure timeouts
        http.SetTimeouts(5000, 10000, 15000, 30000);

        http.Send(postData);

        // Extract and store cookies from the response
        try {
            var setCookie = http.GetResponseHeader("Set-Cookie");
            extractCookies(setCookie);
        } catch (e) {
            // No Set-Cookie header
        }

        return {
            text: http.ResponseText,
            status: http.Status
        };
    } catch (e) {
        WScript.Echo("  [!] HTTP POST error for " + url + ": " + e.message);
        return null;
    }
}

/**
 * Download a binary file from a URL using WinHttpRequest + ADODB.Stream.
 *
 * WinHttpRequest.ResponseBody returns a VBArray of bytes, which can be
 * written directly to an ADODB.Stream in binary mode. This handles all
 * binary formats (PDF, RTF, MP3, etc.) without corruption.
 *
 * Includes safety checks for:
 *   - Gzip-compressed responses (uncommon for file downloads but possible)
 *   - Server error pages masquerading as file downloads (HTML with 200 status)
 *
 * @param {string} url - The download URL
 * @returns {Object|null} { body (VBArray), contentType (string), status } or null
 */
function httpGetBinary(url) {
    try {
        var http = new ActiveXObject("WinHttp.WinHttpRequest.5.1");

        http.Open("GET", url, false);

        http.SetRequestHeader("User-Agent", USER_AGENT);
        http.SetRequestHeader("Accept", "*/*");
        http.SetRequestHeader("Accept-Language", "en-GB,en;q=0.9");
        http.SetRequestHeader("Connection", "keep-alive");

        var cookies = getCookieHeader();
        if (cookies) {
            http.SetRequestHeader("Cookie", cookies);
        }

        http.SetTimeouts(5000, 10000, 15000, 60000);  // Longer receive timeout for large files

        http.Send();

        // Extract cookies from download responses too (session maintenance)
        try {
            var setCookie = http.GetResponseHeader("Set-Cookie");
            extractCookies(setCookie);
        } catch (e) {}

        // Get the Content-Type header, stripping any charset parameter
        var ct = "";
        try {
            ct = http.GetResponseHeader("Content-Type");
            ct = ct.split(";")[0].replace(/^\s+|\s+$/g, "").toLowerCase();
        } catch (e) {}

        // Check for server error pages in the response.
        // Some servers return HTML error pages (PHP exceptions, 404 pages) with
        // a 200 status code instead of the actual file.
        var respText = "";
        try {
            respText = http.ResponseText;
        } catch (e) {}

        // Heuristic: if the response starts with HTML-like characters and is
        // small enough to be an error page, check for error keywords
        if (respText.length > 0 && respText.length < 500000) {
            var firstChar = respText.charAt(0);
            if (firstChar === "<" || firstChar === "s" || firstChar === "e" || firstChar === "{") {
                var lower = respText.toLowerCase();
                if (lower.indexOf("exception") >= 0 || lower.indexOf("<html") >= 0 ||
                    lower.indexOf("<!doctype") >= 0 || lower.indexOf("error") >= 0 ||
                    lower.indexOf("not found") >= 0 || lower.indexOf("string(") >= 0 ||
                    lower.indexOf("nosuchkey") >= 0 || lower.indexOf("stacktrace") >= 0) {
                    WScript.StdOut.Write("server error ");
                    if (DEBUG) {
                        debugDump("Server error in download", respText.substring(0, 500));
                    }
                    return null;
                }
            }
        }

        return {
            body: http.ResponseBody,   // VBArray of bytes for binary saving
            contentType: ct,
            status: http.Status,
            responseText: respText
        };
    } catch (e) {
        WScript.Echo("  [!] Download error " + url + ": " + e.message);
        return null;
    }
}


// ---------------------------------------------------------------------------
// Debug helper -- dump HTML content for troubleshooting
// ---------------------------------------------------------------------------

/**
 * Print a labelled debug dump of HTML/text content (only when DEBUG is true).
 *
 * Used during development and troubleshooting to inspect raw HTML responses.
 * Truncates output to maxChars to avoid flooding the console.
 *
 * @param {string} label - A descriptive label for what's being dumped
 * @param {string} text - The text content to dump
 * @param {number} [maxChars=3000] - Maximum characters to display
 */
function debugDump(label, text, maxChars) {
    if (!DEBUG) return;
    if (typeof maxChars === "undefined") maxChars = 3000;

    var sep = "============================================================";
    WScript.Echo("\n" + sep);
    WScript.Echo("DEBUG: " + label);
    WScript.Echo(sep);
    WScript.Echo(text.substring(0, maxChars));
    if (text.length > maxChars) {
        WScript.Echo("... (" + (text.length - maxChars) + " more chars)");
    }
    WScript.Echo(sep + "\n");
}


// ---------------------------------------------------------------------------
// URL encoding -- encode form data for POST requests
// ---------------------------------------------------------------------------

/**
 * URL-encode a string for use in POST form data.
 *
 * Encodes special characters as %XX hex sequences. This is equivalent to
 * Python's urllib.parse.quote() or JavaScript's encodeURIComponent().
 *
 * JScript's built-in encodeURIComponent() handles most cases, but we also
 * need to encode some characters it misses (!, ', (, ), *) per RFC 3986.
 *
 * @param {string} str - The string to encode
 * @returns {string} The URL-encoded string
 */
function urlEncode(str) {
    // encodeURIComponent handles most special characters correctly
    var encoded = encodeURIComponent(str);
    // Encode additional characters that encodeURIComponent misses
    // but that are required for form data encoding
    encoded = encoded.replace(/!/g, "%21");
    encoded = encoded.replace(/'/g, "%27");
    encoded = encoded.replace(/\(/g, "%28");
    encoded = encoded.replace(/\)/g, "%29");
    encoded = encoded.replace(/\*/g, "%2A");
    return encoded;
}

/**
 * Build a URL-encoded form data string from a key-value object.
 *
 * Produces the standard "key1=value1&key2=value2" format used by HTML forms
 * and expected by WordPress login.
 *
 * @param {Object} params - Key-value pairs to encode
 * @returns {string} The URL-encoded form data string
 */
function buildFormData(params) {
    var parts = [];
    for (var key in params) {
        if (params.hasOwnProperty(key)) {
            parts.push(urlEncode(key) + "=" + urlEncode(params[key]));
        }
    }
    return parts.join("&");
}


// ---------------------------------------------------------------------------
// HTML entity decoding -- convert HTML entities to plain text
// ---------------------------------------------------------------------------

/**
 * Decode HTML entities in a string to their Unicode character equivalents.
 *
 * Handles three types of entities:
 *   1. Named entities: &amp; &lt; &gt; &quot; &apos; &nbsp; &rsquo; etc.
 *   2. Decimal numeric: &#8217; &#169; etc.
 *   3. Hexadecimal numeric: &#x2019; &#xA9; etc.
 *
 * Common typographic entities found in song lyrics include smart quotes
 * (rsquo, lsquo, rdquo, ldquo) and dashes (mdash, ndash).
 *
 * @param {string} str - The HTML string containing entities
 * @returns {string} The decoded plain text string
 */
function decodeEntities(str) {
    if (!str) return "";

    // Map of common named HTML entities to their character equivalents
    var entities = {
        "amp": "&", "lt": "<", "gt": ">", "quot": '"', "apos": "'",
        "nbsp": " ",
        "rsquo": "\u2019", "lsquo": "\u2018",   // Right/left single quotes
        "rdquo": "\u201D", "ldquo": "\u201C",   // Right/left double quotes
        "mdash": "\u2014", "ndash": "\u2013",   // Em dash, en dash
        "hellip": "\u2026",                       // Horizontal ellipsis
        "copy": "\u00A9",                         // Copyright symbol
        "reg": "\u00AE",                          // Registered trademark
        "trade": "\u2122"                         // Trademark
    };

    // Replace named entities: &name; -> character
    str = str.replace(/&([a-zA-Z]+);/g, function(match, name) {
        var lower = name.toLowerCase();
        if (entities[lower]) {
            return entities[lower];
        }
        return match;  // Unknown entity -- leave as-is
    });

    // Windows-1252 entity mapping -- the site uses &#145;-&#151; which are
    // Windows-1252 code points, NOT Unicode. String.fromCharCode(146) etc.
    // produce control characters, not the intended typographic glyphs.
    // We map them explicitly BEFORE the generic decimal decoder runs.
    // Reference: https://en.wikipedia.org/wiki/Windows-1252#Character_set
    var win1252Map = {
        145: "\u2018",  // Left single quote
        146: "\u2019",  // Right single quote / apostrophe
        147: "\u201C",  // Left double quote
        148: "\u201D",  // Right double quote
        150: "\u2013",  // En dash
        151: "\u2014"   // Em dash
    };

    // Replace decimal numeric entities: &#NNN; -> character
    str = str.replace(/&#(\d+);/g, function(match, num) {
        var code = parseInt(num, 10);
        // Check Windows-1252 mapping first
        if (win1252Map[code]) { return win1252Map[code]; }
        try { return String.fromCharCode(code); }
        catch (e) { return ""; }
    });

    // Replace hexadecimal numeric entities: &#xHHH; -> character
    str = str.replace(/&#x([0-9a-fA-F]+);/g, function(match, hex) {
        var code = parseInt(hex, 16);
        try { return String.fromCharCode(code); }
        catch (e) { return ""; }
    });

    return str;
}


// ---------------------------------------------------------------------------
// HTML parsing -- regex-based parsing for index and song pages
// ---------------------------------------------------------------------------
// Note: JScript (ECMAScript 3) does not have a built-in DOM parser. We use
// regex-based parsing which is adequate for the structured HTML on
// missionpraise.com. The patterns are designed to handle the specific HTML
// structure of the site's index and song pages.

/**
 * Parse the song index page to extract song links.
 *
 * The index page lists songs as links within heading elements (h2/h3).
 * Each song appears as:
 *   <h2><a href="/songs/amazing-grace-mp0023/">Amazing Grace (MP0023)</a></h2>
 *
 * We use a two-pass approach:
 *   1. Find all <h2> and <h3> blocks
 *   2. Extract <a> tags with /songs/ in the href from within those blocks
 *
 * @param {string} html - The raw HTML of an index page
 * @returns {Array} Array of { title, url } objects for each song found
 */
function parseIndexPage(html) {
    var songs = [];

    // Strategy: find all <a> tags inside <h2> or <h3> elements that link to /songs/
    // We use a simplified approach: find all <a> tags with /songs/ in href,
    // then check if they appear within heading context by looking at surrounding HTML.
    //
    // Regex breakdown:
    //   <h[23][^>]*>   -- opening h2 or h3 tag (with optional attributes)
    //   ([\s\S]*?)     -- minimal match of any content between the tags
    //   <\/h[23]>      -- closing h2 or h3 tag
    var headingPattern = /<h[23][^>]*>([\s\S]*?)<\/h[23]>/gi;
    var match;

    while ((match = headingPattern.exec(html)) !== null) {
        var headingContent = match[1];

        // Within the heading, find <a> tags that link to song pages
        // Regex breakdown:
        //   <a[^>]+        -- opening <a> tag with attributes
        //   href="([^"]*)" -- capture the href value (in double quotes)
        //   [^>]*>         -- any remaining attributes, then close the tag
        //   ([\s\S]*?)     -- capture the link text (may contain child elements)
        //   <\/a>          -- closing </a> tag
        var linkPattern = /<a[^>]+href=["']([^"']*\/songs\/[^"']*)["'][^>]*>([\s\S]*?)<\/a>/i;
        var linkMatch = linkPattern.exec(headingContent);

        if (linkMatch) {
            var href = linkMatch[1];
            // Strip HTML tags from the link text to get the plain title
            var title = linkMatch[2].replace(/<[^>]+>/g, "").replace(/^\s+|\s+$/g, "");
            title = decodeEntities(title);

            // Convert relative URLs to absolute
            if (href.charAt(0) === "/") {
                href = BASE_URL + href;
            }

            songs.push({ title: title, url: href });
        }
    }

    return songs;
}

/**
 * Parse a song detail page to extract lyrics, copyright, and download links.
 *
 * The song page structure on missionpraise.com:
 *   - Title: element with class "entry-title"
 *   - Lyrics: <p> elements inside a div with class "song-details"
 *     - Each <p> is a lyric line or stanza break (empty <p>)
 *     - <em>/<i> tags indicate chorus lines (italic)
 *     - <br> tags represent line breaks within a verse
 *   - Copyright: element with class "copyright-info"
 *   - Downloads: <a> tags in a sidebar with class "col-sm-4"
 *
 * @param {string} html - The raw HTML of a song detail page
 * @returns {Object} { title, verses[], copyright, downloads{} }
 *   where verses is an array of { text, isItalic } objects
 *   and downloads is a map of type ("words"/"music"/"audio") -> URL
 */
function parseSongPage(html) {
    var result = {
        title: "",
        verses: [],
        copyright: "",
        downloads: {}
    };

    // --- TITLE ---
    // Extract the song title from the element with class "entry-title".
    // Pattern matches: <ANY_TAG class="...entry-title...">CONTENT</ANY_TAG>
    // Uses [\s\S]*? for minimal match across newlines.
    var titleMatch = html.match(/<[^>]+class=["'][^"']*entry-title[^"']*["'][^>]*>([\s\S]*?)<\/[^>]+>/i);
    if (titleMatch) {
        result.title = titleMatch[1].replace(/<[^>]+>/g, "").replace(/^\s+|\s+$/g, "");
        result.title = decodeEntities(result.title);
    }

    // --- LYRICS ---
    // Extract the lyrics section from the div with class "song-details".
    // First, find the entire song-details block.
    var songMatch = html.match(/<(?:div|section)[^>]+class=["'][^"']*song-details[^"']*["'][^>]*>([\s\S]*?)<\/(?:div|section)>/i);
    if (songMatch) {
        var songHtml = songMatch[1];

        // Split on <p> boundaries to handle unclosed <p> tags.
        // The site uses bare <P> tags as verse separators without closing
        // the previous <p>, so matching <p>...</p> pairs misses earlier verses.
        var pBlocks = songHtml.split(/<p[^>]*>/i);
        for (var pi = 0; pi < pBlocks.length; pi++) {
            var pContent = pBlocks[pi].replace(/<\/p>\s*$/i, "");

            // Check if this paragraph contains italic text (chorus indicator).
            // Italic is marked by <em> or <i> tags on the site.
            var hasItalic = (/<(em|i)[\s>]/i).test(pContent);

            // Also check if this <p> is wrapped inside an outer <em>/<i> element.
            // Some pages wrap entire stanzas in <em>...</em> around multiple <p> tags,
            // so the italic tag is not inside the <p> content but in the surrounding HTML.
            // We detect this by counting unclosed <em>/<i> opens vs closes before this block.
            if (!hasItalic) {
                var preceding = pBlocks.slice(0, pi).join("");
                var emOpens = (preceding.match(/<(em|i)[\s>]/gi) || []).length;
                var emCloses = (preceding.match(/<\/(em|i)>/gi) || []).length;
                if (emOpens > emCloses) { hasItalic = true; }
            }

            // Convert <br> tags to newline characters (line breaks within a verse)
            // The site emits <BR><br /> (both uppercase and lowercase) on every
            // line, which produces double line breaks. We match one or more
            // consecutive <br> tags (with optional whitespace between) as a
            // single newline to avoid double-spacing.
            pContent = pContent.replace(/(?:<br\s*\/?>[\s]*)+/gi, "\n");

            // Strip all remaining HTML tags to get plain text
            var text = pContent.replace(/<[^>]+>/g, "");

            // Decode HTML entities in the text
            text = decodeEntities(text);

            // Trim each line individually (handles multi-line verses from <br> tags)
            var lines = text.split("\n");
            var trimmedLines = [];
            for (var i = 0; i < lines.length; i++) {
                trimmedLines.push(lines[i].replace(/^\s+|\s+$/g, ""));
            }
            text = trimmedLines.join("\n").replace(/^\s+|\s+$/g, "");

            // Add the verse to the result (preserving empty strings as stanza break markers)
            result.verses.push({ text: text, isItalic: hasItalic });
        }
    }

    // --- COPYRIGHT ---
    // Extract copyright notice from the element with class "copyright-info"
    var copyrightMatch = html.match(/<[^>]+class=["'][^"']*copyright-info[^"']*["'][^>]*>([\s\S]*?)<\/[^>]+>/i);
    if (copyrightMatch) {
        result.copyright = copyrightMatch[1].replace(/<[^>]+>/g, "").replace(/^\s+|\s+$/g, "");
        result.copyright = decodeEntities(result.copyright);
    }

    // --- DOWNLOAD LINKS ---
    // Download links are in a sidebar div with class "col-sm-4" or "files".
    // Each download type (words, music, audio) is an <a> tag with descriptive text.
    var sidebarMatch = html.match(/<div[^>]+class=["'][^"']*(?:col-sm-4|files)[^"']*["'][^>]*>([\s\S]*?)<\/div>/i);
    if (sidebarMatch) {
        var sidebarHtml = sidebarMatch[1];

        // Find all <a> tags within the sidebar
        var aPattern = /<a[^>]+href=["']([^"']+)["'][^>]*>([\s\S]*?)<\/a>/gi;
        var aMatch;

        while ((aMatch = aPattern.exec(sidebarHtml)) !== null) {
            var aHref = aMatch[1];
            var aText = aMatch[2].replace(/<[^>]+>/g, "").toLowerCase().replace(/^\s+|\s+$/g, "");

            // Skip placeholder links
            if (aHref === "#") continue;

            // Determine the download type from the link text
            if (aText.indexOf("words") >= 0) {
                result.downloads["words"] = aHref;
            } else if (aText.indexOf("music") >= 0) {
                result.downloads["music"] = aHref;
            } else if (aText.indexOf("audio") >= 0) {
                result.downloads["audio"] = aHref;
            }
        }
    }

    return result;
}


// ---------------------------------------------------------------------------
// Book helpers -- extract and clean book-specific data from song titles
// ---------------------------------------------------------------------------

/**
 * Extract the hymn number from a song title for a specific book.
 *
 * Song titles on the index page include the book code and number in
 * parentheses, e.g. "Amazing Grace (MP0023)". This function uses the
 * book-specific regex pattern to extract just the numeric part.
 *
 * @param {string} title - The full song title from the index page
 * @param {string} book - Book identifier key ("mp", "cp", or "jp")
 * @returns {number|null} The hymn number (e.g. 23) or null if not found
 */
function extractNumber(title, book) {
    var cfg = BOOK_CONFIG[book];
    var m = cfg.pattern.exec(title);
    // Reset lastIndex since we reuse the regex (it may have the global flag in future)
    cfg.pattern.lastIndex = 0;
    if (m) {
        return parseInt(m[1], 10);
    }
    return null;
}

/**
 * Determine which book a song belongs to based on its title.
 *
 * Checks the title against each book's regex pattern (MP, CP, JP)
 * and returns the first match.
 *
 * @param {string} title - The full song title from the index page
 * @returns {string|null} Book key ("mp", "cp", or "jp") or null
 */
function detectBook(title) {
    for (var book in BOOK_CONFIG) {
        if (BOOK_CONFIG.hasOwnProperty(book)) {
            if (extractNumber(title, book) !== null) {
                return book;
            }
        }
    }
    return null;
}

/**
 * Remove the book code suffix from a song title.
 *
 * Strips the "(MP0023)" or similar suffix from the end of the title,
 * leaving just the human-readable song name for use in filenames.
 *
 * @param {string} title - The full song title (e.g. "Amazing Grace (MP0023)")
 * @param {string} book - Book identifier key ("mp", "cp", or "jp")
 * @returns {string} The cleaned title (e.g. "Amazing Grace")
 */
function cleanTitle(title, book) {
    var cfg = BOOK_CONFIG[book];
    // Build a pattern that matches the entire book code suffix.
    // We construct it from the book label (MP, CP, JP) to avoid
    // having to maintain separate non-capturing patterns.
    var label = cfg.label;
    // Match optional whitespace + opening paren + label + digits + closing paren
    // at the end of the string
    var suffixPattern = new RegExp("\\s*\\(" + label + "\\d+\\)\\s*$", "i");
    return title.replace(suffixPattern, "").replace(/^\s+|\s+$/g, "");
}


// ---------------------------------------------------------------------------
// Text formatting helpers -- title case, sanitization, filename generation
// ---------------------------------------------------------------------------

/**
 * Convert a string to Title Case, with correct handling of apostrophes.
 *
 * Standard title casing treats apostrophes as word boundaries, producing
 * incorrect results like "Don'T" instead of "Don't". This function treats
 * contractions as single words.
 *
 * @param {string} s - The input string
 * @returns {string} The Title Cased string
 *
 * @example titleCase("AMAZING GRACE")     -> "Amazing Grace"
 * @example titleCase("don't let me down") -> "Don't Let Me Down"
 * @example titleCase("EAGLE\u2019S WINGS") -> "Eagle\u2019s Wings"
 *
 * We include Unicode curly/smart apostrophes (\u2019 RIGHT SINGLE QUOTATION
 * MARK and \u2018 LEFT SINGLE QUOTATION MARK) because the HTML entity decoder
 * converts &rsquo; to \u2019. Without this, "Eagle\u2019s" would produce "Eagle'S".
 */
function titleCase(s) {
    // Match whole words including contractions (e.g. "don't", "it's", "o'er").
    // The character class ['\u2019\u2018] matches ASCII apostrophe and Unicode
    // curly quotes — covering all apostrophe variants after HTML entity decoding.
    return s.replace(/[a-zA-Z]+(['\u2019\u2018][a-zA-Z]+)?/g, function(word) {
        // Capitalize first character, lowercase the rest
        return word.charAt(0).toUpperCase() + word.substring(1).toLowerCase();
    });
}

/**
 * Remove characters that are invalid in filenames across operating systems.
 *
 * Strips characters forbidden in Windows filenames: \ / * ? : " < > |
 * Also trims leading/trailing whitespace.
 *
 * @param {string} name - The raw string to sanitize (typically a song title)
 * @returns {string} The sanitized string
 */
function sanitize(name) {
    return name.replace(/[\\\/\*\?:"<>|]/g, "").replace(/^\s+|\s+$/g, "");
}

/**
 * Zero-pad a number to a specified width.
 *
 * Prepends leading zeros to ensure the string representation has at least
 * 'width' characters. Used for consistent hymn numbering (MP=4 digits, CP/JP=3).
 *
 * @param {number} num - The number to pad
 * @param {number} width - The desired minimum width
 * @returns {string} The zero-padded number string
 *
 * @example zeroPad(23, 4) -> "0023"
 * @example zeroPad(7, 3)  -> "007"
 */
function zeroPad(num, width) {
    var s = String(num);
    while (s.length < width) {
        s = "0" + s;
    }
    return s;
}

/**
 * Construct the base filename for a song (without file extension).
 *
 * Generates a consistent filename from the song's book, number, and title.
 * Format: "{padded_number} ({LABEL}) - {Title Case Title}"
 *
 * @param {number} number - The song number (e.g. 23)
 * @param {string} book - Book identifier key ("mp", "cp", or "jp")
 * @param {string} title - The cleaned song title (book code already removed)
 * @returns {string} The base filename, e.g. "0023 (MP) - Amazing Grace"
 */
function baseFilename(number, book, title) {
    var cfg = BOOK_CONFIG[book];
    var padded = zeroPad(number, cfg.pad);
    return padded + " (" + cfg.label + ") - " + titleCase(sanitize(title));
}


// ---------------------------------------------------------------------------
// Lyrics formatting -- convert parsed song data to clean plain text
// ---------------------------------------------------------------------------

/**
 * Format a parsed song into a clean plain-text string for saving.
 *
 * This is the most complex formatting function because the source HTML has
 * several structural quirks:
 *
 *   1. STANZA GROUPING: Each lyric line is its own <p>, stanza breaks are
 *      empty <p> tags. We group lines into stanzas.
 *
 *   2. CHORUS DETECTION: Chorus lines are in italic (<em>/<i>). We prepend
 *      "Chorus:" before chorus stanzas.
 *
 *   3. ATTRIBUTION DETECTION: Lines starting with "Words:", "Music:", etc.
 *      are author credits, separated from lyrics with extra blank lines.
 *
 *   4. STANDALONE AUTHOR LINES: Short lines with "/" or "&" (name separators)
 *      that don't start with "Words:" etc. are treated as attribution.
 *
 *   5. MULTI-LINE VERSES: Verses with \n (from <br> tags) are treated as
 *      complete stanzas on their own.
 *
 * Output format:
 *   "Song Title"
 *   (blank line)
 *   First verse line
 *   Second verse line
 *   (blank line between stanzas)
 *   Chorus:
 *   Chorus line one
 *   (blank line)
 *   (double blank line before attribution)
 *   Words: Author Name
 *   (blank line)
 *   Copyright notice
 *
 * @param {Object} song - Parsed song data with title, verses, copyright
 * @param {string} book - Book identifier key
 * @returns {string} The formatted plain-text song content
 */
function formatLyrics(song, book) {
    var title = cleanTitle(song.title, book);
    // Start with quoted title and blank line (matching Python output format)
    var lines = ['"' + title + '"', ""];

    var authorLines = [];           // Accumulated attribution lines
    var foundAttribution = false;   // Flag: have we started seeing attribution?
    var stanzaBuf = [];             // Lines of the current stanza being built
    var stanzaIsChorus = false;     // Does current stanza contain chorus (italic)?
    var consecutiveEmpties = 0;     // Count of consecutive empty <p> elements seen

    /**
     * Write the accumulated stanza to the output lines array.
     * If the stanza contains italic lines, prepends "Chorus:" label.
     * Adds a blank line after the stanza for separation.
     */
    function flushStanza() {
        if (stanzaBuf.length === 0) return;
        if (stanzaIsChorus) {
            // Guard: Do not add "Chorus:" if the first line starts with a digit
            // followed by whitespace (e.g., "2 Lord, I come..."). This prevents
            // false positives from verse numbers wrapped in <em> tags for styling.
            var firstLine = stanzaBuf[0] ? stanzaBuf[0].replace(/^\s+|\s+$/g, '') : '';
            if (!/^\d+\s/.test(firstLine)) {
                lines.push("Chorus:");
            }
        }
        lines.push(stanzaBuf.join("\n"));
        lines.push("");  // Blank line between stanzas
        stanzaBuf = [];
        stanzaIsChorus = false;
    }

    // Pattern for detecting attribution lines (Words:, Music:, etc.)
    // Require a colon, separator, or "by" after the keyword to prevent
    // false positives (e.g. sidebar text "Music file" or lyrics like "Words of...").
    var attributionPattern = /^(Words|Music|Arranged|Words and music|Based on|Translated|Paraphrase)\s*[:&\-\/by]/i;
    // Also match bare "Words and music" / "Words & music" phrases
    var attributionPatternBare = /^(Words and music|Words & music)\b/i;

    // Process each verse (paragraph) from the parsed HTML
    for (var i = 0; i < song.verses.length; i++) {
        var verse = song.verses[i];
        var stripped = verse.text.replace(/^\s+|\s+$/g, "");

        // --- Empty verse: count but don't flush yet ---
        // On missionpraise.com, single empty <p> elements appear between EVERY
        // lyric line as CSS spacing.  They are NOT stanza boundaries.  If we
        // flushed on every empty we would double-space the entire output.
        // Instead we count consecutive empties and decide at the NEXT non-empty
        // line whether the gap constitutes a real stanza break.
        if (!stripped) {
            consecutiveEmpties++;
            continue;
        }

        // --- Stanza break decision (smart detection) ---
        // We only treat a gap as a real stanza boundary when there is strong
        // structural evidence, not merely a single CSS-spacer empty <p>.
        //
        // Evidence of a real break:
        //   1. consecutiveEmpties >= 2  -- Multiple empty <p> elements in a
        //      row indicate a genuine structural gap in the source HTML, not
        //      the single-<p> CSS spacing that appears between every line.
        //   2. Italic state change      -- The site uses <em>/<i> to mark
        //      chorus lines.  A transition from italic to non-italic (or vice
        //      versa) signals a verse/chorus boundary.
        //   3. Leading verse number     -- A line starting with a digit
        //      followed by whitespace (e.g. "2 Lord, I come…") marks the
        //      beginning of a new numbered verse.
        //
        // When none of these conditions are met, the empty was just a
        // single CSS spacer between consecutive lines of the SAME stanza,
        // so we continue accumulating into the current stanza buffer.
        if (stanzaBuf.length > 0 && consecutiveEmpties > 0) {
            var isItalic = verse.isItalic;
            var shouldBreak = (
                consecutiveEmpties >= 2 ||           // structural break (multiple empties)
                isItalic !== stanzaIsChorus ||        // verse ↔ chorus transition
                /^\d+\s/.test(stripped)               // new numbered verse
            );
            if (shouldBreak) {
                flushStanza();
            }
        }
        consecutiveEmpties = 0;

        // --- Attribution line detection ---
        // Lines starting with "Words:", "Music:", "Arranged:", etc. are
        // author/composer credits, not lyrics. We require a colon/separator
        // after the keyword to prevent false positives.
        var isAttribution = attributionPattern.test(stripped) || attributionPatternBare.test(stripped);
        if (!isAttribution && foundAttribution && stripped.indexOf("\n") < 0 &&
            !(/^\d+\s/).test(stripped) && stripped.length < 120) {
            // Once we've found the first attribution line, subsequent short
            // single-line entries are also treated as attribution — but NOT
            // if they look like numbered verses.
            isAttribution = true;
        }

        if (isAttribution) {
            flushStanza();
            foundAttribution = true;
            authorLines.push(stripped);
            continue;
        }

        // If we see a normal lyric line after attribution, reset the cascade.
        // This prevents a single false-positive from consuming remaining lyrics.
        if (foundAttribution && (/^\d+\s/).test(stripped)) {
            foundAttribution = false;
        }

        // --- Standalone author lines ---
        // Some attribution lines don't follow the "Words:" pattern but are
        // recognisable as author names by containing "/" or "&" separators.
        // Heuristics: single line, doesn't start with digit, contains / or &,
        // short, and not a typical lyric pattern.
        if (stripped.indexOf("\n") < 0 &&
            !(/^\d/).test(stripped) &&
            (stripped.indexOf("/") >= 0 || stripped.indexOf("&") >= 0) &&
            stripped.length < 120 &&
            !(/\b(the|and|you|your|my|our|lord|god|love|sing|praise)\b/i).test(stripped)) {
            flushStanza();
            authorLines.push(stripped);
            continue;
        }

        // --- Multi-line verse ---
        // If the verse text contains "\n" (from <br> tags), it's a multi-line
        // verse that should be treated as its own stanza.
        if (stripped.indexOf("\n") >= 0) {
            flushStanza();
            // Clean up each line within the verse
            var multiLines = stripped.split("\n");
            var cleaned = [];
            for (var j = 0; j < multiLines.length; j++) {
                cleaned.push(multiLines[j].replace(/^\s+|\s+$/g, ""));
            }
            if (verse.isItalic) {
                // Guard: Do not add "Chorus:" if the first line starts with a digit
                // followed by whitespace (e.g., "2 Lord, I come..."). This prevents
                // false positives from verse numbers wrapped in <em> tags for styling.
                var firstLine = cleaned[0] ? cleaned[0].replace(/^\s+|\s+$/g, '') : '';
                if (!/^\d+\s/.test(firstLine)) {
                    lines.push("Chorus:");
                }
            }
            lines.push(cleaned.join("\n"));
            lines.push("");  // Blank line after the stanza
        } else {
            // --- Single-line verse ---
            // Accumulate into the current stanza buffer.
            if (verse.isItalic) {
                stanzaIsChorus = true;
            }
            stanzaBuf.push(stripped);
        }
    }

    // Flush any remaining stanza that wasn't terminated by an empty verse
    flushStanza();

    // Remove trailing blank lines from the lyrics section
    while (lines.length > 0 && lines[lines.length - 1] === "") {
        lines.pop();
    }

    // Append author attribution lines (separated from lyrics by double blank line)
    if (authorLines.length > 0) {
        lines.push("");   // First blank line
        lines.push("");   // Second blank line (visual separation)
        for (var k = 0; k < authorLines.length; k++) {
            lines.push(authorLines[k]);
        }
    }

    // Append copyright notice if available
    if (song.copyright) {
        lines.push("");
        lines.push(song.copyright);
    }

    // Post-processing: normalise spaced ellipses
    // WordPress sometimes converts "..." to ". . ." with spaces.
    // We normalise these back to standard "..."
    var result = lines.join("\n");
    result = result.replace(/ ?(?:\. ){2,}\./g, "...");

    // Strip legacy formatting codes f*I (italic start) and f*R (format reset)
    // that appear as data-entry artefacts in some song texts
    result = result.replace(/f\*I/g, "");
    result = result.replace(/f\*R/g, "");

    // Collapse 3+ consecutive newlines down to 2 (one blank line maximum)
    result = result.replace(/\n{3,}/g, "\n\n");

    return result;
}


// ---------------------------------------------------------------------------
// File extension detection -- cascade strategy for download file types
// ---------------------------------------------------------------------------

/**
 * Guess the file extension from a URL's path component.
 *
 * Parses the URL to extract the path, then gets the extension from the
 * last path segment. Server-side script extensions (.php, .asp, etc.)
 * are ignored because they indicate dynamic download endpoints.
 *
 * @param {string} url - The download URL
 * @returns {string} The lowercase file extension (e.g. ".rtf") or ""
 */
function extFromUrl(url) {
    // Parse the URL path (strip query string and fragment)
    var path = url.split("?")[0].split("#")[0];
    // Get the filename from the last path segment
    var lastSlash = path.lastIndexOf("/");
    var filename = (lastSlash >= 0) ? path.substring(lastSlash + 1) : path;
    // Get the extension
    var lastDot = filename.lastIndexOf(".");
    if (lastDot < 0) return "";
    var ext = filename.substring(lastDot).toLowerCase();
    // Ignore server-side script extensions
    var scriptExts = { ".php": 1, ".asp": 1, ".aspx": 1, ".jsp": 1, ".cgi": 1, ".py": 1 };
    if (scriptExts[ext]) return "";
    return ext;
}

/**
 * Detect file type from the first few bytes of binary data (magic bytes).
 *
 * Reads the beginning of the file using ADODB.Stream and compares against
 * known file format signatures. This is the last-resort detection method.
 *
 * Note: In JScript, we can't directly access individual bytes of a VBArray.
 * Instead, we write the data to a temporary ADODB.Stream, reposition to the
 * start, switch to text mode, and read the first few characters for comparison.
 * For reliable byte-level comparison, we use a binary-to-byte helper.
 *
 * @param {string} firstBytes - The first few characters of the file as a string
 * @returns {string} The detected file extension (e.g. ".pdf") or ""
 */
function extFromMagic(firstBytes) {
    if (!firstBytes || firstBytes.length < 4) return "";

    // Check each known magic byte signature
    for (var i = 0; i < MAGIC_BYTES.length; i++) {
        var sig = MAGIC_BYTES[i].sig;
        var match = true;

        for (var j = 0; j < sig.length; j++) {
            if (j >= firstBytes.length) { match = false; break; }
            // Compare the character code of each byte
            if (firstBytes.charCodeAt(j) !== sig[j]) {
                match = false;
                break;
            }
        }

        if (match) {
            return MAGIC_BYTES[i].ext;
        }
    }
    return "";
}

/**
 * Determine the correct file extension for a download using a cascade strategy.
 *
 * Tries three methods in order of reliability:
 *   1. MIME type from Content-Type header (most reliable)
 *   2. File extension from the URL path (fallback)
 *   3. Magic bytes from the file content (last resort)
 *
 * @param {string} contentType - The Content-Type header value
 * @param {string} url - The download URL
 * @param {string} [firstBytes] - First few bytes of file content (for magic detection)
 * @returns {string} The file extension including dot (e.g. ".pdf", ".rtf", ".bin")
 */
function extForDownload(contentType, url, firstBytes) {
    // Strategy 1: MIME type lookup
    var ext = MIME_TO_EXT[contentType] || "";
    if (!ext) {
        // Strategy 2: URL extension
        ext = extFromUrl(url);
    }
    if (!ext && firstBytes) {
        // Strategy 3: Magic bytes detection
        ext = extFromMagic(firstBytes);
    }
    // Fallback: .bin so the file is still saved for manual inspection
    return ext || ".bin";
}


// ---------------------------------------------------------------------------
// File system operations -- directory creation, file existence checks
// ---------------------------------------------------------------------------

/**
 * Create a directory path, including all intermediate directories.
 *
 * Equivalent to Python's os.makedirs(path, exist_ok=True).
 * Splits the path into segments and creates each level that doesn't exist.
 *
 * @param {string} path - The full directory path to create
 */
function makedirs(path) {
    // Normalise path separators to backslash (Windows convention)
    path = path.replace(/\//g, "\\");

    // Remove trailing backslash if present
    if (path.charAt(path.length - 1) === "\\") {
        path = path.substring(0, path.length - 1);
    }

    // If the directory already exists, nothing to do
    if (fso.FolderExists(path)) return;

    // Split into segments and build path progressively
    var parts = path.split("\\");
    var current = "";
    for (var i = 0; i < parts.length; i++) {
        if (i === 0) {
            current = parts[0];
            // Handle drive letter (e.g. "C:")
            if (current.match(/^[A-Za-z]:$/)) {
                continue;  // Don't try to create the drive root
            }
        } else {
            current += "\\" + parts[i];
        }

        if (current && !fso.FolderExists(current)) {
            try {
                fso.CreateFolder(current);
            } catch (e) {
                // Folder may have been created by another process (race condition)
                if (!fso.FolderExists(current)) {
                    throw e;  // Re-throw if it genuinely failed
                }
            }
        }
    }
}

/**
 * Build a set (object) of existing filenames in a directory for quick lookup.
 *
 * Called once at startup per book to avoid repeated directory scans.
 * Returns an object where keys are filenames for O(1) membership testing.
 *
 * @param {string} dirPath - Path to the directory to scan
 * @returns {Object} Object with filename keys (values are true), or empty object
 */
function buildFileCache(dirPath) {
    var cache = {};
    dirPath = dirPath.replace(/\//g, "\\");

    if (!fso.FolderExists(dirPath)) return cache;

    var folder = fso.GetFolder(dirPath);
    var files = new Enumerator(folder.Files);

    for (; !files.atEnd(); files.moveNext()) {
        var f = files.item();
        cache[f.Name] = true;
    }

    return cache;
}

/**
 * Merge one file cache object into another (union operation).
 *
 * Copies all keys from source into target. Since filenames include the book
 * label (MP, CP, JP), they're unique across books, making merging safe.
 *
 * @param {Object} target - The target cache to merge into
 * @param {Object} source - The source cache to merge from
 */
function mergeCache(target, source) {
    for (var key in source) {
        if (source.hasOwnProperty(key)) {
            target[key] = true;
        }
    }
}

/**
 * Check if lyrics for a specific song have already been saved.
 *
 * Matches files by their prefix pattern (number + label) and .txt extension.
 *
 * @param {number} number - The song number
 * @param {string} book - Book identifier key
 * @param {Object} fileCache - File cache object from buildFileCache()
 * @returns {boolean} True if a matching .txt file exists
 */
function alreadySavedLyrics(number, book, fileCache) {
    var cfg = BOOK_CONFIG[book];
    var padded = zeroPad(number, cfg.pad);
    var prefix = padded + " (" + cfg.label + ") -";

    for (var f in fileCache) {
        if (fileCache.hasOwnProperty(f)) {
            if (f.indexOf(prefix) === 0 && f.indexOf(".txt", f.length - 4) >= 0) {
                return true;
            }
        }
    }
    return false;
}

/**
 * Check if a specific download file (words/music/audio) already exists.
 *
 * Words files use the base name directly (base.ext), while music and
 * audio files add a type suffix (base_music.ext, base_audio.ext).
 *
 * @param {string} base - The base filename
 * @param {string} dlType - Download type ("words", "music", or "audio")
 * @param {Object} fileCache - File cache object
 * @returns {boolean} True if a matching file exists
 */
function alreadySavedDownload(base, dlType, fileCache) {
    // Words files: "base.ext", other types: "base_type.ext"
    var prefix = (dlType === "words") ? (base + ".") : (base + "_" + dlType + ".");

    for (var f in fileCache) {
        if (fileCache.hasOwnProperty(f)) {
            if (f.indexOf(prefix) === 0) {
                return true;
            }
        }
    }
    return false;
}


// ---------------------------------------------------------------------------
// File saving operations -- write lyrics and binary downloads to disk
// ---------------------------------------------------------------------------

/**
 * Save formatted lyrics text to a UTF-8 encoded plain-text file.
 *
 * Uses ADODB.Stream in text mode with UTF-8 charset. This ensures proper
 * handling of Unicode characters (smart quotes, em dashes, etc.) that
 * commonly appear in song lyrics.
 *
 * @param {string} text - The formatted lyrics text
 * @param {string} filePath - The full path to save the file
 */
function saveTextFile(text, filePath) {
    try {
        var stream = new ActiveXObject("ADODB.Stream");
        stream.Type = 2;           // adTypeText = 2 (text mode)
        stream.Charset = "UTF-8";  // Use UTF-8 encoding for Unicode support
        stream.Open();
        stream.WriteText(text);

        // ADODB.Stream with UTF-8 charset writes a BOM (Byte Order Mark) at
        // the start of the file. While this doesn't cause problems for most
        // programs, the Python version doesn't write a BOM, so we strip it
        // for consistency.
        // To strip BOM: reposition to byte 3 (skip the 3-byte UTF-8 BOM),
        // copy the rest to a new stream, and save that instead.
        stream.Position = 0;
        stream.Type = 1;  // Switch to binary mode to strip BOM
        stream.Position = 3;  // Skip 3-byte UTF-8 BOM (EF BB BF)
        var bomless = new ActiveXObject("ADODB.Stream");
        bomless.Type = 1;  // Binary
        bomless.Open();
        bomless.Write(stream.Read());  // Copy everything after BOM
        bomless.SaveToFile(filePath, 2);  // 2 = adSaveCreateOverWrite
        bomless.Close();
        stream.Close();
    } catch (e) {
        WScript.Echo("  [!] Error saving text file " + filePath + ": " + e.message);
    }
}

/**
 * Save binary data (downloaded file) to disk using ADODB.Stream.
 *
 * ADODB.Stream in binary mode (Type=1) can write VBArray byte data
 * directly to a file without corruption. This handles all binary formats
 * (RTF, PDF, MP3, MIDI, etc.).
 *
 * @param {Object} binaryData - VBArray of bytes from WinHttpRequest.ResponseBody
 * @param {string} filePath - The full path to save the file
 * @returns {boolean} True on success, false on error
 */
function saveBinaryFile(binaryData, filePath) {
    try {
        var stream = new ActiveXObject("ADODB.Stream");
        stream.Type = 1;  // adTypeBinary = 1 (binary mode)
        stream.Open();
        stream.Write(binaryData);               // Write the binary data
        stream.SaveToFile(filePath, 2);          // 2 = adSaveCreateOverWrite
        stream.Close();
        return true;
    } catch (e) {
        WScript.Echo("  [!] Error saving binary file " + filePath + ": " + e.message);
        return false;
    }
}

/**
 * Get the first few bytes of binary data as a string for magic byte detection.
 *
 * Writes the binary data to a temporary ADODB.Stream, repositions to the start,
 * and reads the first N bytes as a binary string. The character codes of the
 * string correspond to the byte values, enabling magic number comparison.
 *
 * @param {Object} binaryData - VBArray of bytes from WinHttpRequest.ResponseBody
 * @param {number} [numBytes=16] - Number of bytes to read
 * @returns {string} The first bytes as a string (charCode = byte value)
 */
function getFirstBytes(binaryData, numBytes) {
    if (typeof numBytes === "undefined") numBytes = 16;
    try {
        // Write to a temporary stream in binary mode
        var tempStream = new ActiveXObject("ADODB.Stream");
        tempStream.Type = 1;  // Binary
        tempStream.Open();
        tempStream.Write(binaryData);

        // Reposition to start and read as text in binary-compatible encoding
        tempStream.Position = 0;
        tempStream.Type = 2;  // Switch to text
        tempStream.Charset = "iso-8859-1";  // Single-byte encoding: charCode = byte value
        var text = tempStream.ReadText(numBytes);
        tempStream.Close();
        return text;
    } catch (e) {
        return "";
    }
}


// ---------------------------------------------------------------------------
// Skip logging -- record skipped songs for later review
// ---------------------------------------------------------------------------

/**
 * Record a skipped song in the skipped.log file.
 *
 * Creates a persistent log of songs that couldn't be scraped, with
 * timestamps, identifiers, and reasons.
 *
 * Log format:
 *   [2026-03-13 14:30:00]  MP0023  Amazing Grace  --  login wall  --  https://...
 *
 * @param {string} outputDir - Directory to write the log file in
 * @param {string} label - Book label (e.g. "MP")
 * @param {string} padded - Zero-padded song number string
 * @param {string} title - The song title
 * @param {string} url - The song page URL
 * @param {string} reason - Why the song was skipped
 */
function logSkip(outputDir, label, padded, title, url, reason) {
    makedirs(outputDir);
    var logPath = outputDir.replace(/\//g, "\\") + "\\skipped.log";

    // Build timestamp in ISO-ish format: YYYY-MM-DD HH:MM:SS
    var now = new Date();
    var timestamp = now.getFullYear() + "-" +
        zeroPad(now.getMonth() + 1, 2) + "-" +
        zeroPad(now.getDate(), 2) + " " +
        zeroPad(now.getHours(), 2) + ":" +
        zeroPad(now.getMinutes(), 2) + ":" +
        zeroPad(now.getSeconds(), 2);

    var line = "[" + timestamp + "]  " + label + padded + "  " + title +
               "  --  " + reason + "  --  " + url + "\n";

    try {
        // Open file for appending (8 = ForAppending, true = create if missing)
        var file = fso.OpenTextFile(logPath, 8, true);
        file.Write(line);
        file.Close();
    } catch (e) {
        // Non-fatal: log failure shouldn't stop the scrape
        WScript.Echo("  [!] Warning: could not write to skipped.log: " + e.message);
    }
}


// ---------------------------------------------------------------------------
// Skip diagnostic HTML dump -- save raw HTML for debugging skipped songs
// ---------------------------------------------------------------------------

/**
 * Write raw HTML to a diagnostic file when a song is skipped.
 *
 * Creates a debug file containing the raw HTML response that caused the skip,
 * enabling post-mortem analysis of why a song was skipped. Files are saved
 * alongside the song files in the book output directory.
 *
 * Output filename format:
 *   _debug_{LABEL}{NUM}_skipped.html
 *   e.g. _debug_MP0023_skipped.html
 *
 * @param {string} bookDir - Book output directory path
 * @param {string} label - Book label (e.g. "MP")
 * @param {string} padded - Zero-padded song number string
 * @param {string} title - The song title
 * @param {string} url - The song page URL
 * @param {string} reason - Why the song was skipped (used in header comment)
 * @param {string} htmlText - The raw HTML response to dump
 */
function dumpSkipHtml(bookDir, label, padded, title, url, reason, htmlText) {
    if (!htmlText) return;
    try {
        makedirs(bookDir);
        var debugPath = bookDir.replace(/\//g, "\\") + "\\_debug_" + label + padded + "_skipped.html";
        var header = "<!-- Skip diagnostic dump -->\n" +
                     "<!-- Song: " + label + padded + " - " + title + " -->\n" +
                     "<!-- URL: " + url + " -->\n" +
                     "<!-- Reason: " + reason + " -->\n" +
                     "<!-- Date: " + (new Date()).toISOString() + " -->\n\n";
        var file = fso.CreateTextFile(debugPath, true);  // true = overwrite
        file.Write(header + htmlText);
        file.Close();
    } catch (e) {
        // Non-fatal: diagnostic dump failure shouldn't stop the scrape
        if (DEBUG) {
            WScript.Echo("  [!] Warning: could not write skip diagnostic HTML: " + e.message);
        }
    }
}


// ---------------------------------------------------------------------------
// Authentication -- WordPress login with CSRF nonce extraction
// ---------------------------------------------------------------------------

/**
 * Authenticate with missionpraise.com using WordPress standard login.
 *
 * The login flow:
 *   1. GET the login page to obtain CSRF nonces (hidden form fields)
 *   2. Brief pause (2s) to appear more human-like
 *   3. POST credentials + nonces with proper Referer/Origin headers
 *   4. Handle failure modes: WAF blocks, wrong password, login errors
 *   5. Verify login by fetching /songs/ and checking for logged-in indicators
 *
 * @returns {boolean} True if login appears successful, false if failed
 */
function doLogin() {
    WScript.Echo("Logging in as " + USERNAME + "...");

    // --- Step 1: GET the login page to extract CSRF nonces ---
    var loginPage = httpGet(LOGIN_URL);
    if (!loginPage) {
        WScript.Echo("  [!] Could not reach login page.");
        return false;
    }

    debugDump("Login page HTML", loginPage.text);

    // Extract all <input type="hidden"> fields from the login form.
    // These typically include testcookie, _wpnonce, and plugin-specific nonces.
    var hiddenFields = {};
    var hiddenPattern = /<input[^>]+type=["']hidden["'][^>]*>/gi;
    var hiddenMatch;

    while ((hiddenMatch = hiddenPattern.exec(loginPage.text)) !== null) {
        var tag = hiddenMatch[0];
        // Extract the name attribute
        var nameMatch = tag.match(/name=["']([^"']+)["']/);
        // Extract the value attribute
        var valueMatch = tag.match(/value=["']([^"']*)["']/);

        if (nameMatch) {
            hiddenFields[nameMatch[1]] = valueMatch ? valueMatch[1] : "";
        }
    }

    if (DEBUG) {
        var fieldNames = [];
        for (var fn in hiddenFields) {
            if (hiddenFields.hasOwnProperty(fn)) fieldNames.push(fn);
        }
        WScript.Echo("  DEBUG: Hidden fields: " + fieldNames.join(", "));
        var cookieNames = [];
        for (var cn in cookieJar) {
            if (cookieJar.hasOwnProperty(cn)) cookieNames.push(cn);
        }
        WScript.Echo("  DEBUG: Cookies after GET login page: " + cookieNames.join(", "));
    }

    // --- Step 2: Brief pause to appear more human-like ---
    // Some WAFs flag requests that POST to the login form within milliseconds
    // of loading the page, as this indicates automated access.
    WScript.Sleep(2000);

    // --- Step 3: POST the login credentials ---
    // Build the login form data with WordPress standard field names
    var payload = {
        "log":         USERNAME,           // WordPress username field
        "pwd":         PASSWORD,           // WordPress password field
        "wp-submit":   "Log In",           // Submit button value
        "redirect_to": BASE_URL + "/songs/",  // Redirect target after login
        "rememberme":  "forever"           // Keep the session alive
    };

    // Merge in all hidden fields (CSRF nonces)
    for (var field in hiddenFields) {
        if (hiddenFields.hasOwnProperty(field)) {
            payload[field] = hiddenFields[field];
        }
    }

    var postData = buildFormData(payload);
    var loginResp = httpPost(LOGIN_URL, postData, LOGIN_URL);

    if (!loginResp) {
        WScript.Echo("  [!] Login POST request failed.");
        return false;
    }

    if (DEBUG) {
        WScript.Echo("  DEBUG: Post-login status: " + loginResp.status);
        var postCookies = [];
        for (var pcn in cookieJar) {
            if (cookieJar.hasOwnProperty(pcn)) postCookies.push(pcn);
        }
        WScript.Echo("  DEBUG: Cookies after POST login: " + postCookies.join(", "));
        debugDump("Post-login body", loginResp.text);
    }

    var body = loginResp.text;

    // --- Step 4: Check for various failure modes ---

    // Check for WAF / firewall blocks (e.g. Sucuri CDN/WAF)
    var bodyLower = body.toLowerCase();
    if (bodyLower.indexOf("sucuri") >= 0 || bodyLower.indexOf("access denied") >= 0) {
        // Extract block reason and IP if available
        var reasonMatch = body.match(/Block reason:<\/td>\s*<td><span>(.*?)<\/span>/);
        var ipMatch = body.match(/Your IP:<\/td>\s*<td><span>(.*?)<\/span>/);
        var reason = reasonMatch ? reasonMatch[1].replace(/^\s+|\s+$/g, "") : "unknown";
        var ip = ipMatch ? ipMatch[1].replace(/^\s+|\s+$/g, "") : "unknown";
        WScript.Echo("  [!] BLOCKED by website firewall: " + reason);
        WScript.Echo("       Your IP: " + ip);
        WScript.Echo("       Try again later or log in via browser first to whitelist your IP.");
        return false;
    }

    // Check for incorrect credentials -- WordPress stays on wp-login.php
    if (bodyLower.indexOf("wp-login.php") >= 0 && bodyLower.indexOf("incorrect") >= 0) {
        WScript.Echo("  [!] Login failed -- check username/password.");
        return false;
    }

    // Check for other WordPress login errors (still on login page after POST)
    if (bodyLower.indexOf("wp-login.php") >= 0) {
        // Look for the WordPress login error div
        var errorMatch = body.match(/<div[^>]*id=["']login_error["'][^>]*>([\s\S]*?)<\/div>/i);
        if (errorMatch) {
            var errorText = errorMatch[1].replace(/<[^>]+>/g, "").replace(/^\s+|\s+$/g, "");
            WScript.Echo("  [!] Login error: " + errorText);
            return false;
        }
        if (DEBUG) {
            WScript.Echo("  DEBUG: Still on login page after POST -- login likely failed");
        }
    }

    // --- Step 5: Verify login by fetching the songs page ---
    var songsResp = httpGet(BASE_URL + "/songs/");
    if (!songsResp) {
        WScript.Echo("  [?] Could not verify login (songs page unreachable).");
        return true;  // Proceed anyway -- the login POST may have worked
    }

    if (DEBUG) {
        debugDump("Songs page HTML", songsResp.text);
    }

    var checkLower = songsResp.text.toLowerCase();

    // Look for multiple indicators of being logged in
    var loggedIn = (
        checkLower.indexOf("logout") >= 0 ||
        checkLower.indexOf("log out") >= 0 ||
        checkLower.indexOf("log-out") >= 0 ||
        songsResp.text.indexOf("Welcome") >= 0 ||
        checkLower.indexOf("my-account") >= 0 ||
        checkLower.indexOf("wp-admin") >= 0 ||
        checkLower.indexOf("logged-in") >= 0
    );

    if (loggedIn) {
        WScript.Echo("  [OK] Logged in.");
        return true;
    }

    // Check if redirected back to login page
    if (checkLower.indexOf("wp-login") >= 0 || checkLower.indexOf("loginform") >= 0) {
        WScript.Echo("  [!] Login failed -- redirected back to login page.");
        return false;
    }

    // Login status is ambiguous -- proceed anyway
    WScript.Echo("  [?] Login status unclear -- proceeding anyway.");
    WScript.Echo("       (Re-run with debug mode enabled to see full HTML responses)");
    return true;
}


// ---------------------------------------------------------------------------
// Song processing -- scrape lyrics and downloads for a single song
// ---------------------------------------------------------------------------

/**
 * Scrape lyrics and download files for a single song.
 *
 * This is the core function that handles one song from start to finish:
 *   1. Extract the song number from the index title
 *   2. Check if lyrics/downloads already exist (skip if so)
 *   3. Fetch the song detail page
 *   4. Parse HTML to extract lyrics, copyright, and download links
 *   5. Save lyrics as a .txt file
 *   6. Download and save words/music/audio files (if enabled)
 *
 * Handles edge cases:
 *   - Songs with no extractable number
 *   - Login walls (session expired mid-scrape)
 *   - WAF blocks on individual song pages
 *   - Failed title parsing (retried once for transient errors)
 *   - Missing downloads (re-fetches page if lyrics exist but files don't)
 *
 * @param {string} indexTitle - Song title from the index page (includes book code)
 * @param {string} url - URL of the song detail page
 * @param {string} book - Book identifier key ("mp", "cp", or "jp")
 * @param {string} outputDir - Base output directory
 * @param {Object} fileCache - Mutable file cache object (updated on save)
 * @returns {string} "saved", "skipped", or "exists"
 */
function processSong(indexTitle, url, book, outputDir, fileCache) {
    var number = extractNumber(indexTitle, book);
    var cfg = BOOK_CONFIG[book];

    // Route output into a book-specific subdirectory
    var bookDir = outputDir.replace(/\//g, "\\") + "\\" + cfg.subdir;

    if (number === null) {
        // Title doesn't contain a recognisable book code
        logSkip(bookDir, "??", "????", indexTitle, url, "no book number found in title");
        return "skipped";
    }

    var padded = zeroPad(number, cfg.pad);
    var label = cfg.label;

    // --- Check if lyrics already exist ---
    var lyricsExist = alreadySavedLyrics(number, book, fileCache);

    // If lyrics exist and we don't need files, skip entirely (no network request)
    if (lyricsExist && !DO_DOWNLOAD) {
        return "exists";
    }

    // If lyrics exist, check whether downloads also exist
    if (lyricsExist) {
        var cleanT = cleanTitle(indexTitle, book);
        var base = baseFilename(number, book, cleanT);
        var hasAnyDownload = false;

        for (var f in fileCache) {
            if (fileCache.hasOwnProperty(f) && !f.match(/\.txt$/i)) {
                if (f.indexOf(base + ".") === 0 || f.indexOf(base + "_") === 0) {
                    hasAnyDownload = true;
                    break;
                }
            }
        }

        if (!DO_DOWNLOAD || hasAnyDownload) {
            return "exists";
        }

        // Lyrics exist but no downloads -- need to fetch page for download links
        WScript.StdOut.Write("    " + label + padded + "  " +
            padRight(indexTitle.substring(0, 55), 55) + " ");
        WScript.StdOut.Write("[refetch for downloads] ");
    } else {
        // Neither lyrics nor files exist -- full scrape needed
        WScript.StdOut.Write("    " + label + padded + "  " +
            padRight(indexTitle.substring(0, 55), 55) + " ");
    }

    // --- Fetch the song detail page ---
    var pageResp = httpGet(url);

    if (!pageResp || !pageResp.text) {
        WScript.Echo("X (empty response)");
        dumpSkipHtml(bookDir, label, padded, indexTitle, url, "empty_response", pageResp ? pageResp.text : "");
        logSkip(bookDir, label, padded, indexTitle, url, "empty response");
        WScript.Sleep(DELAY_MS);
        return "skipped";
    }

    var htmlText = pageResp.text;

    // Check for login wall (session may have expired during the scrape)
    if (htmlText.indexOf("Please login to continue") >= 0 ||
        htmlText.indexOf("loginform") >= 0) {
        WScript.Echo("X (login wall)");
        dumpSkipHtml(bookDir, label, padded, indexTitle, url, "login_wall", htmlText);
        logSkip(bookDir, label, padded, indexTitle, url, "login wall");
        WScript.Sleep(DELAY_MS);
        return "skipped";
    }

    // Check for subscription paywall
    if (htmlText && htmlText.indexOf("not part of your subscription") >= 0) {
        WScript.Echo("X (not in subscription)");
        dumpSkipHtml(bookDir, label, padded, indexTitle, url, "subscription_paywall", htmlText);
        logSkip(bookDir, label, padded, indexTitle, url, "song not part of subscription");
        WScript.Sleep(DELAY_MS);
        return "skipped";
    }

    // Check for WAF block on the song page
    var htmlLower = htmlText.toLowerCase();
    if (htmlLower.indexOf("sucuri") >= 0 || htmlLower.indexOf("access denied") >= 0) {
        WScript.Echo("X (blocked by firewall)");
        dumpSkipHtml(bookDir, label, padded, indexTitle, url, "waf_block", htmlText);
        logSkip(bookDir, label, padded, indexTitle, url, "blocked by WAF/firewall");
        WScript.Sleep(DELAY_MS);
        return "skipped";
    }

    // --- Parse the song page HTML ---
    var song = parseSongPage(htmlText);

    // If the parser couldn't find a title, retry once
    if (!song.title) {
        WScript.Sleep(DELAY_MS * 2);  // Longer delay before retry
        pageResp = httpGet(url);
        if (pageResp && pageResp.text && pageResp.text.indexOf("loginform") < 0) {
            song = parseSongPage(pageResp.text);
        }
    }

    // If still no title after retry, try fallbacks before skipping
    if (!song.title) {
        // Fallback 1: Extract from HTML <title> tag, strip " – Mission Praise" suffix
        // The site uses &#8211; (en dash) or a literal – character before "Mission Praise"
        var htmlTitleMatch = (pageResp && pageResp.text) ?
            pageResp.text.match(/<title[^>]*>([\s\S]*?)<\/title>/i) : null;
        if (htmlTitleMatch) {
            var htmlTitle = htmlTitleMatch[1].replace(/<[^>]+>/g, "").replace(/^\s+|\s+$/g, "");
            htmlTitle = decodeEntities(htmlTitle);
            // Strip " – Mission Praise" or " - Mission Praise" suffix (&#8211; decodes to \u2013)
            htmlTitle = htmlTitle.replace(/\s*[\u2013\u2014\-]\s*Mission Praise\s*$/i, "").replace(/^\s+|\s+$/g, "");
            if (htmlTitle) {
                song.title = htmlTitle;
            }
        }
    }

    if (!song.title) {
        // Fallback 2: Use the index page title, stripping the book code like "(MP1270)"
        var fallbackTitle = indexTitle.replace(/\s*\([A-Z]{2}\d+\)\s*$/i, "").replace(/^\s+|\s+$/g, "");
        if (fallbackTitle) {
            song.title = fallbackTitle;
        }
    }

    // If STILL no title after all fallbacks, skip this song
    if (!song.title) {
        WScript.Echo("X (no title parsed)");
        dumpSkipHtml(bookDir, label, padded, indexTitle, url, "no_title", htmlText);
        logSkip(bookDir, label, padded, indexTitle, url, "parser found no title in page HTML");
        WScript.Sleep(DELAY_MS);
        return "skipped";
    }

    // --- Verse count validation ---
    // Count actual text lines (not <p> entries) to detect incomplete parses.
    // Many songs use a few large <p> blocks with <br> line breaks inside,
    // so counting entries would produce false "incomplete" warnings.
    var totalLines = 0;
    for (var vi = 0; vi < song.verses.length; vi++) {
        var vText = song.verses[vi].text;
        if (vText && vText.replace(/^\s+|\s+$/g, "")) {
            totalLines += vText.replace(/^\s+|\s+$/g, "").split("\n").length;
        }
    }
    if (totalLines === 0) {
        WScript.Echo(" WARNING (no lyrics found)");
        dumpSkipHtml(bookDir, label, padded, indexTitle, url, "no_lyrics", htmlText);
        logSkip(bookDir, label, padded, indexTitle, url, "parser found title but 0 lyric lines");
    } else if (totalLines < 4) {
        WScript.Echo(" WARNING (" + totalLines + " lines - may be incomplete)");
    }

    // --- Save lyrics ---
    var base;
    if (lyricsExist) {
        // Lyrics already saved -- just derive the base name for downloads
        base = baseFilename(number, book, cleanTitle(song.title, book));
    } else {
        // Save lyrics to .txt file
        makedirs(bookDir);
        var cleanedTitle = cleanTitle(song.title, book);
        base = baseFilename(number, book, cleanedTitle);
        var lyricsPath = bookDir + "\\" + base + ".txt";
        var formattedLyrics = formatLyrics(song, book);
        saveTextFile(formattedLyrics, lyricsPath);
        fileCache[base + ".txt"] = true;  // Add to cache
    }

    // --- Download files (words, music, audio) ---
    var fileResults = [];  // Track download results for the status line

    if (DO_DOWNLOAD) {
        var dlTypes = ["words", "music", "audio"];
        for (var d = 0; d < dlTypes.length; d++) {
            var dlType = dlTypes[d];
            var dlUrl = song.downloads[dlType];

            if (!dlUrl) continue;  // Download type not available for this song

            // Skip if already downloaded
            if (alreadySavedDownload(base, dlType, fileCache)) {
                fileResults.push(dlType.charAt(0).toLowerCase());  // lowercase = existed
                continue;
            }

            // Convert relative URLs to absolute
            if (dlUrl.charAt(0) === "/") {
                dlUrl = BASE_URL + dlUrl;
            }

            // Download the file
            var dlResp = httpGetBinary(dlUrl);
            if (dlResp && dlResp.body) {
                // Determine the file extension using the cascade strategy
                var firstBytes = getFirstBytes(dlResp.body);
                var ext = extForDownload(dlResp.contentType, dlUrl, firstBytes);

                // Build the filename with type suffix for music/audio
                var fname;
                if (dlType === "words") {
                    fname = base + ext;
                } else {
                    fname = base + "_" + dlType + ext;
                }

                var dlPath = bookDir + "\\" + fname;
                if (saveBinaryFile(dlResp.body, dlPath)) {
                    fileResults.push(dlType.charAt(0).toUpperCase());  // UPPERCASE = new
                    fileCache[fname] = true;  // Add to cache
                }
            }

            // Brief delay between downloads (30% of normal delay)
            WScript.Sleep(Math.floor(DELAY_MS * 0.3));
        }
    }

    // Print the status line with download indicators
    var fileStr = (fileResults.length > 0) ? " [" + fileResults.join(",") + "]" : "";
    WScript.Echo("OK" + fileStr);

    WScript.Sleep(DELAY_MS);  // Rate limit between songs
    return "saved";
}


// ---------------------------------------------------------------------------
// Utility -- string padding
// ---------------------------------------------------------------------------

/**
 * Pad a string to a minimum width by appending spaces.
 *
 * Used for aligning console output in columnar format.
 *
 * @param {string} s - The string to pad
 * @param {number} width - The desired minimum width
 * @returns {string} The padded string
 */
function padRight(s, width) {
    while (s.length < width) {
        s += " ";
    }
    return s;
}


// ---------------------------------------------------------------------------
// Main crawl-and-scrape loop -- paginated index crawling
// ---------------------------------------------------------------------------

/**
 * Crawl the paginated song index and scrape each discovered song.
 *
 * The missionpraise.com song index is paginated (10 songs per page).
 * This function:
 *   1. Fetches each index page sequentially
 *   2. Parses the page to discover song links
 *   3. Filters songs to only the requested books
 *   4. Scrapes each song on the current page before moving to the next
 *
 * End-of-index detection:
 *   - "Page not found" in the response
 *   - WordPress serving page 1 content for out-of-range pages (loop detection)
 *   - No song links found on the page
 *   - Empty response
 *
 * @returns {Object} { saved, skipped, existed } counters
 */
function crawlAndScrape() {
    var page = START_PAGE;

    // Build a combined file cache from all book subdirectories.
    // In force mode, we skip cache building entirely and use an empty object,
    // so every song is treated as new and all files are re-downloaded/overwritten.
    var fileCache = {};

    if (FORCE) {
        WScript.Echo("  Force mode: will re-download and overwrite existing files.\n");
    } else {
        for (var bi = 0; bi < booksToScrape.length; bi++) {
            var bk = booksToScrape[bi];
            var subdir = OUTPUT_DIR.replace(/\//g, "\\") + "\\" + BOOK_CONFIG[bk].subdir;
            mergeCache(fileCache, buildFileCache(subdir));
        }

        // Count existing files for the startup message
        var cacheCount = 0;
        for (var ck in fileCache) {
            if (fileCache.hasOwnProperty(ck)) cacheCount++;
        }

        if (cacheCount > 0) {
            WScript.Echo("  Found " + cacheCount + " existing files across output folders -- will skip duplicates.\n");
        }
    }

    // Counters for the final summary
    var saved = 0;
    var skipped = 0;
    var existed = 0;

    // Build lookup set for books to scrape (for efficient membership testing)
    var bookSet = {};
    for (var bs = 0; bs < booksToScrape.length; bs++) {
        bookSet[booksToScrape[bs]] = true;
    }

    // --- Main pagination loop ---
    while (true) {
        // Construct the URL for the current index page
        var indexUrl = INDEX_URL + page + "/";
        var indexResp = httpGet(indexUrl);

        // Detect end of pagination: empty response or "Page not found"
        if (!indexResp || !indexResp.text) {
            if (DEBUG) {
                WScript.Echo("  DEBUG: Page " + page + " -- empty response");
            }
            break;
        }

        if (indexResp.text.indexOf("Page not found") >= 0) {
            if (DEBUG) {
                WScript.Echo("  DEBUG: Page " + page + " -- Page not found detected");
            }
            break;
        }

        // Dump the first page's HTML for debugging
        if (page === START_PAGE) {
            debugDump("Index page " + page + " HTML", indexResp.text);
        }

        // --- Loop detection ---
        // WordPress silently serves page 1 content for out-of-range pages.
        // We detect this by parsing the "Showing X-Y of Z" counter.
        var countMatch = indexResp.text.match(/(\d+)-(\d+)\s+of\s+(\d+)/);
        if (countMatch) {
            var lo = parseInt(countMatch[1], 10);
            var hi = parseInt(countMatch[2], 10);
            var total = parseInt(countMatch[3], 10);
            var totalPages = Math.ceil(total / 10);

            // Print total count on the first page
            if (page === START_PAGE) {
                WScript.Echo("  " + total + " total songs across ~" + totalPages + " pages\n");
            }

            // Detect loops: lo > hi (impossible) or past page 1 but seeing results from 1
            if (lo > hi || (page > 1 && lo === 1)) {
                break;
            }
        } else {
            // If no counter found and past page 1, assume end of index
            if (page > 1) {
                break;
            }
        }

        // --- Parse the index page for song links ---
        var allSongs = parseIndexPage(indexResp.text);

        if (allSongs.length === 0) {
            if (DEBUG) {
                WScript.Echo("  DEBUG: IndexParser found 0 song links on page " + page);
            }
            break;
        }

        // --- Filter songs to requested books ---
        var pageSongs = [];
        for (var si = 0; si < allSongs.length; si++) {
            var songEntry = allSongs[si];
            for (var bk2 in bookSet) {
                if (bookSet.hasOwnProperty(bk2)) {
                    if (extractNumber(songEntry.title, bk2) !== null) {
                        pageSongs.push({
                            title: songEntry.title,
                            url: songEntry.url,
                            book: bk2
                        });
                        break;  // A song belongs to exactly one book
                    }
                }
            }
        }

        // Print page summary with running totals
        WScript.Echo("  Page " + padRight(String(page), 4) + "  --  " +
            pageSongs.length + " matching songs  " +
            "(saved: " + saved + ", existed: " + existed + ", skipped: " + skipped + ")");

        // --- Scrape each song on this page ---
        var pageExisted = 0;
        var songFilterFound = false;
        for (var pi = 0; pi < pageSongs.length; pi++) {
            var ps = pageSongs[pi];

            // If /song:NNN was specified, skip songs that don't match
            if (SONG_FILTER > 0) {
                var songNum = extractNumber(ps.title, ps.book);
                if (songNum !== SONG_FILTER) {
                    continue;
                }
                songFilterFound = true;
            }

            var result = processSong(ps.title, ps.url, ps.book, OUTPUT_DIR, fileCache);

            if (result === "saved") {
                saved++;
            } else if (result === "skipped") {
                skipped++;
            } else if (result === "exists") {
                existed++;
                pageExisted++;
            }

            // If /song:NNN was specified and we found it, stop after processing
            if (SONG_FILTER > 0 && songFilterFound) {
                WScript.Echo("\n  Single-song mode: song " + SONG_FILTER + " processed. Stopping.");
                return { saved: saved, skipped: skipped, existed: existed };
            }
        }

        // If every song on this page already existed, print a compact summary
        if (pageExisted === pageSongs.length && pageSongs.length > 0) {
            WScript.Echo("           >>  all " + pageExisted + " songs on this page already exist");
        }

        page++;
        WScript.Sleep(DELAY_MS);  // Rate limit between index pages
    }

    return { saved: saved, skipped: skipped, existed: existed };
}


// ---------------------------------------------------------------------------
// MAIN -- Entry point for the JScript scraping engine
// ---------------------------------------------------------------------------

// Print startup banner
WScript.Echo("============================================================");
WScript.Echo("  Mission Praise Scraper (Windows Batch/JScript Hybrid)");
WScript.Echo("  Copyright 2025-2026 MWBM Partners Ltd.");
WScript.Echo("============================================================");
WScript.Echo("");

// --- Authenticate with the site ---
if (!doLogin()) {
    WScript.Echo("\n[!] Authentication failed. Exiting.");
    WScript.Quit(1);
}

// --- Print configuration summary ---
var bookLabels = [];
for (var li = 0; li < booksToScrape.length; li++) {
    bookLabels.push(booksToScrape[li].toUpperCase());
}

// Resolve the output directory to an absolute path for display
var absOutput = OUTPUT_DIR;
try {
    absOutput = fso.GetAbsolutePathName(OUTPUT_DIR);
} catch (e) {}

WScript.Echo("");
WScript.Echo("Books    : " + bookLabels.join(", "));
WScript.Echo("Output   : " + absOutput);
WScript.Echo("Downloads: " + (DO_DOWNLOAD ? "enabled (words, music, audio)" : "disabled"));
if (SONG_FILTER > 0) {
    WScript.Echo("Song     : " + SONG_FILTER + " (single-song mode, force enabled)");
}
WScript.Echo("");

// --- Run the main crawl-and-scrape process ---
var results = crawlAndScrape();

// --- Print final summary ---
WScript.Echo("");
WScript.Echo("Done!  " + results.saved + " saved, " +
    results.existed + " already existed, " +
    results.skipped + " skipped.");
WScript.Echo("Output: " + absOutput);

if (results.skipped > 0) {
    WScript.Echo("Skipped songs logged to: skipped.log in each book's output folder.");
}
