@if (@CodeSection == @Batch) @then
@REM ========================================================================
@REM SDAHymnals_SDAHymnal.org.bat
@REM importers/scrapers/SDAHymnals_SDAHymnal.org.bat
@REM
@REM Hymnal Scraper (Windows Batch/JScript Hybrid)
@REM Copyright 2025-2026 MWBM Partners Ltd.
@REM
@REM HYBRID TECHNIQUE EXPLAINED:
@REM   This file is simultaneously valid as a Windows Batch script AND as
@REM   JScript code. The trick relies on the first line:
@REM       @if (@CodeSection == @Batch) @then
@REM
@REM   In Batch: "@if" is treated as a label/command prefix (the @ suppresses
@REM   echo), and the rest is ignored because batch doesn't evaluate JScript
@REM   conditional compilation directives. The batch interpreter runs the
@REM   commands between here and "goto :eof".
@REM
@REM   In JScript: "@if (@CodeSection == @Batch)" is a JScript conditional
@REM   compilation directive that evaluates to false (since @CodeSection is
@REM   undefined and @Batch is undefined, they're not equal). So the JScript
@REM   engine skips everything between @if...@then and @end, jumping straight
@REM   to the actual JScript code below the @end marker.
@REM
@REM   The batch section provides an interactive menu when the .bat file is
@REM   double-clicked or run from a command prompt. It collects user settings
@REM   and then invokes the JScript section via:
@REM       cscript //nologo //e:jscript "%~f0" <args>
@REM   This tells Windows Script Host to run THIS SAME FILE as JScript,
@REM   which skips the batch section (via @if/@end) and executes the
@REM   scraping engine below.
@REM
@REM DEPENDENCIES:
@REM   NONE. This script uses only native Windows components:
@REM   - cmd.exe (batch interpreter) - present on ALL Windows versions
@REM   - cscript.exe (Windows Script Host) - present on ALL Windows versions
@REM   - MSXML2.XMLHTTP (ActiveX) - present since Windows XP SP2
@REM   - Scripting.FileSystemObject (ActiveX) - present since Windows 98
@REM   No Python, no Node.js, no curl, no third-party tools required.
@REM
@REM OVERVIEW:
@REM   Scrapes hymn lyrics from sdahymnal.org (Seventh-day Adventist Hymnal)
@REM   and hymnal.xyz (The Church Hymnal). Both sites share the same HTML
@REM   structure, so one parser handles both. Hymns are saved as plain-text
@REM   files in book-specific subdirectories.
@REM
@REM USAGE:
@REM   Double-click the .bat file for the interactive menu, or run from
@REM   the command line:
@REM       SDAHymnals_SDAHymnal.org.bat                  (interactive menu)
@REM       SDAHymnals_SDAHymnal.org.bat /?                (show help)
@REM       SDAHymnals_SDAHymnal.org.bat /site:sdah        (command-line mode)
@REM       SDAHymnals_SDAHymnal.org.bat /force            (re-download all)
@REM ========================================================================
@echo off

REM -- Enable delayed expansion for variable manipulation inside loops --
setlocal enabledelayedexpansion

REM -- Set console code page to UTF-8 for proper character display --
chcp 65001 >nul 2>&1

REM -- Initialise default settings --
REM These mirror the Python version's defaults exactly.
set "SITE=both"
set "START_HYMN=1"
set "END_HYMN=auto"
set "OUTPUT_DIR=.\hymns"
set "DELAY=1000"
REM -- Force mode: when enabled, re-downloads and overwrites existing hymn files --
REM By default, the scraper skips hymns that already exist in the output directory.
REM The /force flag disables this check, causing all hymns to be re-fetched.
set "FORCE=0"

REM -- Check for command-line help request (/?  or  /help  or  -?) --
if "%~1"=="/?" goto :ShowHelp
if "%~1"=="-?" goto :ShowHelp
if "%~1"=="/help" goto :ShowHelp
if "%~1"=="--help" goto :ShowHelp

REM -- Check for direct command-line arguments (non-interactive mode) --
REM If any /site: /start: /end: /output: /delay: arguments are present,
REM skip the interactive menu and go straight to scraping.
REM /force is a standalone flag (no value) that enables force mode.
set "HAS_ARGS=0"
for %%A in (%*) do (
    echo %%A | findstr /i /c:"/site:" /c:"/start:" /c:"/end:" /c:"/output:" /c:"/delay:" /c:"/force" >nul 2>&1
    if not errorlevel 1 set "HAS_ARGS=1"
)

REM -- Parse command-line arguments if provided --
if "%HAS_ARGS%"=="1" (
    for %%A in (%*) do (
        REM Check for standalone /force flag (no colon/value needed)
        echo %%A | findstr /i /c:"/force" >nul 2>&1
        if not errorlevel 1 set "FORCE=1"
        REM Extract the parameter name and value from /name:value format
        for /f "tokens=1,* delims=:" %%B in ("%%A") do (
            if /i "%%B"=="/site"   set "SITE=%%C"
            if /i "%%B"=="/start"  set "START_HYMN=%%C"
            if /i "%%B"=="/end"    set "END_HYMN=%%C"
            if /i "%%B"=="/output" set "OUTPUT_DIR=%%C"
            if /i "%%B"=="/delay"  set "DELAY=%%C"
        )
    )
    REM Skip the interactive menu and go straight to launching the scraper
    goto :LaunchScraper
)

REM ======================================================================
REM INTERACTIVE MENU — displayed when double-clicked or run with no args
REM ======================================================================

:MainMenu
cls

REM -- Display coloured ASCII banner --
REM Using ANSI escape codes for colour (supported on Windows 10+).
REM On older Windows, the escape codes will display as text but won't break.
echo.
echo  [36m========================================================[0m
echo  [36m  ___  ____    _    _   _                         _     [0m
echo  [36m / __^|^|  _ \  / \  ^| ^| ^| ^|                       ^| ^|    [0m
echo  [36m \__ \^| ^| ^| ^|/ _ \ ^| ^|_^| ^|_   _ _ __ ___  _ __  ^| ^|___ [0m
echo  [36m ___) ^| ^|_^| / ___ \^|  _  ^| ^| ^| ^| '_ ` _ \^| '_ \ ^| / __^|[0m
echo  [36m^|____/^|____/_/   \_\_^| ^|_^|_^| ^|_^| ^| ^| ^| ^|_^| ^| ^|_^|___/[0m
echo  [36m                            ^|__/                        [0m
echo  [36m          SDA Hymnal Scraper (Windows)                  [0m
echo  [36m========================================================[0m
echo.
echo  [33m Copyright 2025-2026 MWBM Partners Ltd.[0m
echo.
echo  Scrapes hymn lyrics from sdahymnal.org (SDAH) and
echo  hymnal.xyz (The Church Hymnal). Both sites share the
echo  same HTML structure. Hymns are saved as plain text.
echo.
echo  [36m--------------------------------------------------------[0m
echo  [32m Current Settings:[0m
echo    Site      : [33m%SITE%[0m
echo    Start Hymn: [33m%START_HYMN%[0m
echo    End Hymn  : [33m%END_HYMN%[0m
echo    Output Dir: [33m%OUTPUT_DIR%[0m
echo    Delay (ms): [33m%DELAY%[0m
if "%FORCE%"=="1" (
    echo    Force Mode: [31mON[0m  ^(will overwrite existing files^)
) else (
    echo    Force Mode: [32mOFF[0m ^(skips existing files^)
)
echo  [36m--------------------------------------------------------[0m
echo.
echo  [32m Options:[0m
echo    [33m1[0m. Start with defaults (both sites, hymn 1, auto-detect end)
echo    [33m2[0m. Choose site (sdah / ch / both)
echo    [33m3[0m. Set start hymn number
echo    [33m4[0m. Set end hymn number
echo    [33m5[0m. Set output directory
echo    [33m6[0m. Set delay between requests (milliseconds)
echo    [33m7[0m. Toggle force mode (re-download and overwrite existing files)
echo    [33m8[0m. Show current settings
echo    [33m9[0m. START scraping with current settings
echo    [33m0[0m. Exit
echo.

REM -- Prompt user for menu choice --
set "CHOICE="
set /p "CHOICE=  Enter choice [0-9]: "

REM -- Validate and route the menu choice --
if "%CHOICE%"=="1" goto :UseDefaults
if "%CHOICE%"=="2" goto :ChooseSite
if "%CHOICE%"=="3" goto :SetStart
if "%CHOICE%"=="4" goto :SetEnd
if "%CHOICE%"=="5" goto :SetOutput
if "%CHOICE%"=="6" goto :SetDelay
if "%CHOICE%"=="7" goto :ToggleForce
if "%CHOICE%"=="8" goto :ShowSettings
if "%CHOICE%"=="9" goto :LaunchScraper
if "%CHOICE%"=="0" goto :Exit

REM -- Invalid input — redisplay the menu --
echo.
echo  [31m  Invalid choice. Please enter a number from 0 to 9.[0m
timeout /t 2 /nobreak >nul
goto :MainMenu


REM ----------------------------------------------------------------------
REM Option 1: Reset all settings to defaults and start scraping
REM ----------------------------------------------------------------------
:UseDefaults
set "SITE=both"
set "START_HYMN=1"
set "END_HYMN=auto"
set "OUTPUT_DIR=.\hymns"
set "DELAY=1000"
set "FORCE=0"
goto :LaunchScraper


REM ----------------------------------------------------------------------
REM Option 2: Choose which site to scrape
REM ----------------------------------------------------------------------
:ChooseSite
echo.
echo  [32m Available sites:[0m
echo    [33msdah[0m  - sdahymnal.org (Seventh-day Adventist Hymnal)
echo    [33mch[0m    - hymnal.xyz (The Church Hymnal)
echo    [33mboth[0m  - Scrape both sites sequentially
echo.
set "SITE_INPUT="
set /p "SITE_INPUT=  Enter site choice (sdah/ch/both): "

REM Validate the input against allowed values
if /i "%SITE_INPUT%"=="sdah" set "SITE=sdah" & goto :MainMenu
if /i "%SITE_INPUT%"=="ch"   set "SITE=ch"   & goto :MainMenu
if /i "%SITE_INPUT%"=="both" set "SITE=both"  & goto :MainMenu

echo  [31m  Invalid site. Choose sdah, ch, or both.[0m
timeout /t 2 /nobreak >nul
goto :ChooseSite


REM ----------------------------------------------------------------------
REM Option 3: Set the starting hymn number
REM ----------------------------------------------------------------------
:SetStart
echo.
set "START_INPUT="
set /p "START_INPUT=  Enter start hymn number [%START_HYMN%]: "

REM If user pressed Enter without typing, keep current value
if "%START_INPUT%"=="" goto :MainMenu

REM Validate that the input is a number (must contain only digits)
echo %START_INPUT%| findstr /r "^[0-9][0-9]*$" >nul 2>&1
if errorlevel 1 (
    echo  [31m  Invalid number. Please enter a positive integer.[0m
    timeout /t 2 /nobreak >nul
    goto :SetStart
)

set "START_HYMN=%START_INPUT%"
goto :MainMenu


REM ----------------------------------------------------------------------
REM Option 4: Set the ending hymn number (or "auto" for auto-detect)
REM ----------------------------------------------------------------------
:SetEnd
echo.
echo  Enter end hymn number, or "auto" to auto-detect the end.
set "END_INPUT="
set /p "END_INPUT=  Enter end hymn number [%END_HYMN%]: "

REM If user pressed Enter without typing, keep current value
if "%END_INPUT%"=="" goto :MainMenu

REM Allow "auto" as a special value
if /i "%END_INPUT%"=="auto" set "END_HYMN=auto" & goto :MainMenu

REM Validate that the input is a number
echo %END_INPUT%| findstr /r "^[0-9][0-9]*$" >nul 2>&1
if errorlevel 1 (
    echo  [31m  Invalid input. Enter a positive integer or "auto".[0m
    timeout /t 2 /nobreak >nul
    goto :SetEnd
)

set "END_HYMN=%END_INPUT%"
goto :MainMenu


REM ----------------------------------------------------------------------
REM Option 5: Set the output directory path
REM ----------------------------------------------------------------------
:SetOutput
echo.
set "OUTPUT_INPUT="
set /p "OUTPUT_INPUT=  Enter output directory path [%OUTPUT_DIR%]: "

REM If user pressed Enter without typing, keep current value
if "%OUTPUT_INPUT%"=="" goto :MainMenu

set "OUTPUT_DIR=%OUTPUT_INPUT%"
goto :MainMenu


REM ----------------------------------------------------------------------
REM Option 6: Set the delay between HTTP requests (in milliseconds)
REM ----------------------------------------------------------------------
:SetDelay
echo.
echo  Delay is in milliseconds (e.g. 1000 = 1 second).
set "DELAY_INPUT="
set /p "DELAY_INPUT=  Enter delay in ms [%DELAY%]: "

REM If user pressed Enter without typing, keep current value
if "%DELAY_INPUT%"=="" goto :MainMenu

REM Validate that the input is a number
echo %DELAY_INPUT%| findstr /r "^[0-9][0-9]*$" >nul 2>&1
if errorlevel 1 (
    echo  [31m  Invalid number. Please enter a positive integer (milliseconds).[0m
    timeout /t 2 /nobreak >nul
    goto :SetDelay
)

set "DELAY=%DELAY_INPUT%"
goto :MainMenu


REM ----------------------------------------------------------------------
REM Option 7: Toggle force mode on/off
REM ----------------------------------------------------------------------
REM Force mode causes the scraper to re-download and overwrite existing hymn
REM files instead of skipping them. Useful when you want to refresh all files
REM (e.g. after a site update or to fix previously corrupted downloads).
:ToggleForce
if "%FORCE%"=="0" (
    set "FORCE=1"
    echo.
    echo  [33m  Force mode: ON[0m — existing files will be overwritten.
) else (
    set "FORCE=0"
    echo.
    echo  [32m  Force mode: OFF[0m — existing files will be skipped.
)
timeout /t 2 /nobreak >nul
goto :MainMenu


REM ----------------------------------------------------------------------
REM Option 8: Display all current settings
REM ----------------------------------------------------------------------
:ShowSettings
cls
echo.
echo  [36m========================================================[0m
echo  [32m             Current Settings[0m
echo  [36m========================================================[0m
echo.
echo    Site          : [33m%SITE%[0m
echo    Start Hymn    : [33m%START_HYMN%[0m
echo    End Hymn      : [33m%END_HYMN%[0m
echo    Output Dir    : [33m%OUTPUT_DIR%[0m
echo    Delay (ms)    : [33m%DELAY%[0m
if "%FORCE%"=="1" (
    echo    Force Mode    : [31mON[0m  ^(will overwrite existing files^)
) else (
    echo    Force Mode    : [32mOFF[0m ^(skips existing files^)
)
echo.
echo  [36m========================================================[0m
echo.
pause
goto :MainMenu


REM ----------------------------------------------------------------------
REM Show help text (when /? or /help is passed)
REM ----------------------------------------------------------------------
:ShowHelp
echo.
echo  SDA Hymnal Scraper (Windows Batch/JScript Hybrid)
echo  Copyright 2025-2026 MWBM Partners Ltd.
echo.
echo  DESCRIPTION:
echo    Scrapes hymn lyrics from sdahymnal.org (Seventh-day Adventist Hymnal)
echo    and hymnal.xyz (The Church Hymnal). Both sites share the same HTML
echo    structure. Hymns are saved as plain-text files organised into
echo    book-specific subdirectories.
echo.
echo    This script requires NO external dependencies — it uses only native
echo    Windows components (cmd.exe, cscript.exe, MSXML2.XMLHTTP, FSO).
echo.
echo  USAGE:
echo    %~nx0                              Interactive menu (double-click)
echo    %~nx0 /?                           Show this help
echo    %~nx0 /site:sdah                   Scrape only sdahymnal.org
echo    %~nx0 /site:ch                     Scrape only hymnal.xyz
echo    %~nx0 /site:both                   Scrape both sites (default)
echo    %~nx0 /start:50                    Start from hymn 50
echo    %~nx0 /start:1 /end:100            Scrape hymns 1 to 100
echo    %~nx0 /output:C:\hymns             Custom output directory
echo    %~nx0 /delay:2000                  2-second delay between requests
echo    %~nx0 /force                       Re-download and overwrite all files
echo    %~nx0 /site:sdah /force            Combine with other flags
echo.
echo  OPTIONS:
echo    /site:VALUE    Which site to scrape: sdah, ch, or both (default: both)
echo    /start:N       First hymn number to scrape (default: 1)
echo    /end:N         Last hymn number, or "auto" for auto-detect (default: auto)
echo    /output:PATH   Output folder path (default: .\hymns)
echo    /delay:MS      Milliseconds between HTTP requests (default: 1000)
echo    /force         Re-download and overwrite existing hymn files (default: off)
echo.
echo  OUTPUT FORMAT:
echo    Files are saved as:
echo      hymns\Seventh-day Adventist Hymnal [SDAH]\001 (SDAH) - Praise To The Lord.txt
echo      hymns\The Church Hymnal [CH]\001 (CH) - Praise To The Lord.txt
echo.
echo    Each file contains:
echo      "Hymn Title"
echo      ^<blank line^>
echo      Verse 1
echo      First line of lyrics...
echo      ^<blank line^>
echo      Chorus
echo      Chorus text...
echo.
echo  FEATURES:
echo    - Resumable: skips hymns already saved in the output directory
echo    - Force mode (/force): override resumability, re-download everything
echo    - Auto-detects end of hymnal (no /end needed)
echo    - Handles rate limiting (pauses 60s, retries)
echo    - Retries on server errors (3 attempts with 3s delay)
echo    - Logs skipped hymns to skipped.log with timestamps
echo    - Stops after 10 consecutive failures (assumes end of hymnal)
echo.
goto :eof


REM ----------------------------------------------------------------------
REM Launch the JScript scraping engine with collected parameters
REM ----------------------------------------------------------------------
:LaunchScraper
echo.
echo  [36m========================================================[0m
echo  [32m  Launching scraper...[0m
echo  [36m========================================================[0m
echo.
echo  Settings:
echo    Site      : %SITE%
echo    Start     : %START_HYMN%
echo    End       : %END_HYMN%
echo    Output    : %OUTPUT_DIR%
echo    Delay (ms): %DELAY%
if "%FORCE%"=="1" (
    echo    Force Mode: ON  ^(will overwrite existing files^)
) else (
    echo    Force Mode: OFF ^(skips existing files^)
)
echo.

REM -- Verify that cscript.exe is available (it should be on all Windows) --
where cscript >nul 2>&1
if errorlevel 1 (
    echo  [31m  ERROR: cscript.exe not found. This is required for the JScript engine.[0m
    echo  [31m  cscript.exe should be present on all Windows installations.[0m
    echo  [31m  Check your PATH or try: C:\Windows\System32\cscript.exe[0m
    pause
    goto :eof
)

REM -- Invoke THIS SAME FILE as JScript via Windows Script Host --
REM   //nologo    - Suppress the WSH banner
REM   //e:jscript - Force JScript engine (overrides .bat extension)
REM   "%~f0"      - Full path to this file (the hybrid .bat/.js file)
REM   Arguments are passed positionally: site start end output delay force
cscript //nologo //e:jscript "%~f0" "%SITE%" "%START_HYMN%" "%END_HYMN%" "%OUTPUT_DIR%" "%DELAY%" "%FORCE%"

echo.
echo  [32m  Scraping complete.[0m
echo.
pause
goto :eof


REM ----------------------------------------------------------------------
REM Exit the script cleanly
REM ----------------------------------------------------------------------
:Exit
echo.
echo  Goodbye.
echo.
endlocal
goto :eof

@end
// ========================================================================
// JSCRIPT SECTION — Hymnal Scraping Engine
// ========================================================================
//
// SDAHymnals_SDAHymnal.org.bat — JScript scraping engine
// importers/scrapers/SDAHymnals_SDAHymnal.org.bat
//
// Copyright 2025-2026 MWBM Partners Ltd.
//
// This section contains the actual scraping logic. It is invoked by the
// batch section above via:
//     cscript //nologo //e:jscript "%~f0" <site> <start> <end> <output> <delay> <force>
//
// The @if/@end conditional compilation block at the top of the file
// causes the JScript engine to skip the entire batch section and start
// executing from this point.
//
// NATIVE WINDOWS OBJECTS USED:
//   - MSXML2.XMLHTTP:  HTTP client (present since Windows XP SP2)
//   - Scripting.FileSystemObject:  File system access (present since Win98)
//   - WScript.Sleep():  Pause execution (native WSH method)
//   - WScript.Echo():   Console output (native WSH method)
//   - WScript.Arguments:  Read command-line arguments
//
// HTML PARSING STRATEGY:
//   Since JScript in WSH doesn't have a DOM parser (no document.createElement),
//   we use regular expressions to extract data from the HTML. The target HTML
//   structure uses consistent CSS class names across both sites, making regex
//   extraction reliable:
//
//   Title:      <div class="block-heading-four">
//                 <h3 class="wedding-heading">
//                   <strong>TITLE HERE</strong>
//                 </h3>
//               </div>
//
//   Indicator:  <div class="block-heading-three">Verse 1</div>
//
//   Lyrics:     <div class="block-heading-five">
//                 Line one<br>Line two<br>
//               </div>
// ========================================================================


// -----------------------------------------------------------------------
// SITE CONFIGURATION
// -----------------------------------------------------------------------
// Both sdahymnal.org and hymnal.xyz are built on the same platform,
// using identical CSS class names and page layouts. This allows a single
// set of regex patterns to parse both sites.

/**
 * Site configuration object.
 * Each site has:
 *   baseUrl  - The hymn page URL (hymn number appended as ?no=N)
 *   homeUrl  - The site homepage (used to detect end-of-hymnal redirects)
 *   label    - Short identifier for filenames (e.g. "SDAH")
 *   subdir   - Human-readable subdirectory name for organised output
 *   lang     - ISO 639-1 language code for the songbook's language (e.g. "en")
 */
var SITES = {
    "sdah": {
        baseUrl:  "https://www.sdahymnal.org/Hymn",
        homeUrl:  "https://www.sdahymnal.org",
        label:    "SDAH",
        subdir:   "Seventh-day Adventist Hymnal [SDAH]",
        lang:     "en"    // ISO 639-1: English
    },
    "ch": {
        baseUrl:  "https://www.hymnal.xyz/Hymn",
        homeUrl:  "https://www.hymnal.xyz",
        label:    "CH",
        subdir:   "The Church Hymnal [CH]",
        lang:     "en"    // ISO 639-1: English
    }
};

// Maximum consecutive skip/failure count before assuming end of hymnal.
// If we hit this many failures in a row, we stop rather than continuing
// to hammer the server with requests for non-existent hymns.
var MAX_CONSEC = 10;


// -----------------------------------------------------------------------
// UTILITY: FileSystemObject — shared instance for all file operations
// -----------------------------------------------------------------------
// Scripting.FileSystemObject is a native COM object available on all
// Windows versions since Windows 98. It provides file/folder creation,
// reading, writing, and path manipulation.
var fso = new ActiveXObject("Scripting.FileSystemObject");


// -----------------------------------------------------------------------
// UTILITY: Pad a number with leading zeros to a specified width
// -----------------------------------------------------------------------
/**
 * Pad a number with leading zeros (e.g. zeroPad(1, 3) => "001").
 *
 * @param {number} num   - The number to pad
 * @param {number} width - The desired total width (default: 3)
 * @returns {string} The zero-padded string
 */
function zeroPad(num, width) {
    // Default width is 3 digits (matches the Python version)
    if (typeof width === "undefined") { width = 3; }
    var s = String(num);
    // Prepend zeros until we reach the desired width
    while (s.length < width) { s = "0" + s; }
    return s;
}


// -----------------------------------------------------------------------
// UTILITY: Sanitize a string for use as a filename
// -----------------------------------------------------------------------
/**
 * Remove characters that are invalid in Windows filenames.
 * Strips: \ / * ? : " < > |
 * These characters are forbidden by the Windows file system (NTFS/FAT).
 *
 * @param {string} name - The raw string to sanitize (typically a hymn title)
 * @returns {string} The sanitized string with invalid characters removed
 */
function sanitize(name) {
    // Replace each forbidden character with an empty string
    // Note: In JScript regex, we need to escape backslash and forward slash
    return name.replace(/[\\\/\*\?:"<>\|]/g, "").replace(/^\s+|\s+$/g, "");
}


// -----------------------------------------------------------------------
// UTILITY: Title Case conversion with proper apostrophe handling
// -----------------------------------------------------------------------
/**
 * Convert a string to Title Case, handling apostrophes correctly.
 *
 * JavaScript's built-in methods (like CSS text-transform or manual splitting)
 * often break on apostrophes, producing "Don'T" instead of "Don't".
 * This function matches whole words (including contractions like don't, it's,
 * o'er) and capitalises the first letter of each word while lowercasing
 * the rest.
 *
 * Regex pattern: [a-zA-Z]+('[a-zA-Z]+)?
 *   - [a-zA-Z]+       : One or more letters (the main word)
 *   - ('[a-zA-Z]+)?   : Optionally an apostrophe + more letters (contraction)
 *
 * @param {string} s - The input string to convert
 * @returns {string} The Title Cased string
 *
 * @example
 *   titleCase("AMAZING GRACE")     => "Amazing Grace"
 *   titleCase("don't let me down") => "Don't Let Me Down"
 *   titleCase("o'er the hills")    => "O'er The Hills"
 *   titleCase("EAGLE\u2019S WINGS") => "Eagle\u2019s Wings"
 *
 * We include Unicode curly/smart apostrophes (\u2019 RIGHT SINGLE QUOTATION
 * MARK and \u2018 LEFT SINGLE QUOTATION MARK) because the HTML entity decoder
 * converts &rsquo; to \u2019. Without this, "Eagle\u2019s" would be split into
 * separate words, producing "Eagle'S" instead of "Eagle's".
 */
function titleCase(s) {
    // Match each word (including apostrophe-contractions) and capitalise it.
    // The character class ['\u2019\u2018] matches ASCII apostrophe, right single
    // quotation mark, and left single quotation mark — covering all apostrophe
    // variants that may appear after HTML entity decoding.
    return s.replace(/[a-zA-Z]+(['\u2019\u2018][a-zA-Z]+)?/g, function(match) {
        // Uppercase first character, lowercase the rest
        return match.charAt(0).toUpperCase() + match.substring(1).toLowerCase();
    });
}


// -----------------------------------------------------------------------
// UTILITY: Decode HTML entities in a string
// -----------------------------------------------------------------------
/**
 * Decode common HTML entities to their plain-text equivalents.
 *
 * Since we're parsing HTML with regex (no DOM available in WSH JScript),
 * we need to manually convert HTML entities that appear in hymn titles
 * and lyrics text. This covers the most common entities found on these
 * hymn sites.
 *
 * Handles:
 *   - Named entities: &amp; &lt; &gt; &quot; &apos; &nbsp;
 *   - Typographic entities: &rsquo; &lsquo; &rdquo; &ldquo; &mdash; &ndash;
 *   - Numeric decimal entities: &#8217; &#160; etc.
 *   - Numeric hex entities: &#x2019; &#xA0; etc.
 *
 * @param {string} text - The HTML string containing entities
 * @returns {string} The string with entities decoded to plain characters
 */
function decodeEntities(text) {
    if (!text) { return ""; }

    // --- Named entity lookup table ---
    // Maps entity names (without & and ;) to their character equivalents
    var namedEntities = {
        "amp":    "&",
        "lt":     "<",
        "gt":     ">",
        "quot":   '"',
        "apos":   "'",
        "nbsp":   " ",           // Non-breaking space -> regular space
        "rsquo":  "\u2019",     // Right single smart quote (')
        "lsquo":  "\u2018",     // Left single smart quote  (')
        "rdquo":  "\u201D",     // Right double smart quote (")
        "ldquo":  "\u201C",     // Left double smart quote  (")
        "mdash":  "\u2014",     // Em dash (—)
        "ndash":  "\u2013"      // En dash (–)
    };

    // Replace named entities: &name; -> character
    text = text.replace(/&([a-zA-Z]+);/g, function(match, name) {
        var lower = name.toLowerCase();
        if (typeof namedEntities[lower] !== "undefined") {
            return namedEntities[lower];
        }
        return match; // Unknown entity — leave as-is
    });

    // Windows-1252 remapping: code points 128-159 are C1 control characters
    // in Unicode, but many legacy web pages use them to mean the Windows-1252
    // characters (smart quotes, dashes, etc.). Web browsers perform this
    // remapping automatically; we do the same here so that &#145; becomes
    // a left single quote, etc.
    // Reference: https://html.spec.whatwg.org/#numeric-character-reference-end-state
    var win1252Map = {
        145: "\u2018",  // left single quotation mark
        146: "\u2019",  // right single quotation mark
        147: "\u201C",  // left double quotation mark
        148: "\u201D",  // right double quotation mark
        150: "\u2013",  // en dash
        151: "\u2014"   // em dash
    };

    // Replace numeric decimal entities: &#8217; -> character
    text = text.replace(/&#(\d+);/g, function(match, digits) {
        var code = parseInt(digits, 10);
        // Check Windows-1252 remapping first (legacy entity codes 128-159)
        if (typeof win1252Map[code] !== "undefined") {
            return win1252Map[code];
        }
        // Safety check: valid Unicode code points range from 0 to 0x10FFFF
        if (code > 0 && code <= 0x10FFFF) {
            return String.fromCharCode(code);
        }
        return match; // Out of range — leave as-is
    });

    // Replace numeric hex entities: &#x2019; -> character
    text = text.replace(/&#x([0-9a-fA-F]+);/g, function(match, hex) {
        var code = parseInt(hex, 16);
        // Check Windows-1252 remapping first
        if (typeof win1252Map[code] !== "undefined") {
            return win1252Map[code];
        }
        if (code > 0 && code <= 0x10FFFF) {
            return String.fromCharCode(code);
        }
        return match; // Out of range — leave as-is
    });

    return text;
}


// -----------------------------------------------------------------------
// UTILITY: Strip all HTML tags from a string
// -----------------------------------------------------------------------
/**
 * Remove all HTML tags from a string, leaving only text content.
 * Converts <br> and <br/> tags to newlines before stripping other tags.
 *
 * @param {string} html - The HTML string to strip
 * @returns {string} Plain text with HTML tags removed
 */
function stripTags(html) {
    if (!html) { return ""; }
    // Convert <br>, <br/>, <br /> tags to newlines (these are line breaks in lyrics)
    var text = html.replace(/<br\s*\/?>/gi, "\n");
    // Remove all remaining HTML tags
    text = text.replace(/<[^>]+>/g, "");
    return text;
}


// -----------------------------------------------------------------------
// UTILITY: Trim whitespace from both ends of a string
// -----------------------------------------------------------------------
/**
 * Trim leading and trailing whitespace from a string.
 * JScript (WSH) doesn't have String.prototype.trim(), so we implement it.
 *
 * @param {string} s - The string to trim
 * @returns {string} The trimmed string
 */
function trim(s) {
    if (!s) { return ""; }
    return s.replace(/^\s+|\s+$/g, "");
}


// -----------------------------------------------------------------------
// UTILITY: Ensure a directory exists (create it and all parents if needed)
// -----------------------------------------------------------------------
/**
 * Recursively create a directory path, similar to Python's os.makedirs().
 * If the directory already exists, this is a no-op.
 *
 * Uses Scripting.FileSystemObject to create each directory level in the
 * path from root to leaf.
 *
 * @param {string} dirPath - The full directory path to create
 */
function ensureDir(dirPath) {
    if (fso.FolderExists(dirPath)) { return; }

    // Get the parent directory path
    var parent = fso.GetParentFolderName(dirPath);

    // Recursively ensure the parent exists first
    if (parent && !fso.FolderExists(parent)) {
        ensureDir(parent);
    }

    // Now create this directory (parent is guaranteed to exist)
    try {
        fso.CreateFolder(dirPath);
    } catch (e) {
        // Ignore errors if the folder was created between our check and create
        // (race condition with concurrent scripts, unlikely but possible)
        if (!fso.FolderExists(dirPath)) { throw e; }
    }
}


// -----------------------------------------------------------------------
// UTILITY: Get the absolute path for a given path string
// -----------------------------------------------------------------------
/**
 * Convert a relative path to an absolute path using FSO.
 * Resolves ".", "..", and relative paths against the current directory.
 *
 * @param {string} path - The path to resolve (may be relative or absolute)
 * @returns {string} The fully resolved absolute path
 */
function getAbsolutePath(path) {
    return fso.GetAbsolutePathName(path);
}


// -----------------------------------------------------------------------
// UTILITY: Get a formatted timestamp string for log entries
// -----------------------------------------------------------------------
/**
 * Return a formatted timestamp string in the format: YYYY-MM-DD HH:MM:SS
 * Used for skipped.log entries to record when each skip occurred.
 *
 * @returns {string} Formatted timestamp
 */
function getTimestamp() {
    var now = new Date();
    var y   = now.getFullYear();
    // Month and day are zero-padded to 2 digits
    var m   = zeroPad(now.getMonth() + 1, 2);  // getMonth() is 0-based
    var d   = zeroPad(now.getDate(), 2);
    var hh  = zeroPad(now.getHours(), 2);
    var mm  = zeroPad(now.getMinutes(), 2);
    var ss  = zeroPad(now.getSeconds(), 2);
    return y + "-" + m + "-" + d + " " + hh + ":" + mm + ":" + ss;
}


// -----------------------------------------------------------------------
// HTTP: Fetch a URL with retry logic
// -----------------------------------------------------------------------
/**
 * Fetch the HTML content of a URL using MSXML2.XMLHTTP (native ActiveX).
 *
 * Includes retry logic for HTTP 500 errors (3 attempts, 3-second delay
 * between retries). This mirrors the Python version's resilience features.
 *
 * MSXML2.XMLHTTP is a native COM object available on all Windows versions
 * since XP SP2. It supports synchronous and asynchronous HTTP requests.
 * We use synchronous mode (third parameter = false) since WSH JScript
 * is single-threaded and we need to wait for each response.
 *
 * @param {string} url - The URL to fetch
 * @returns {object|null} Object with properties:
 *   - status {number}: HTTP status code (200, 404, 500, etc.)
 *   - responseText {string}: The response body as text
 *   - responseUrl {string}: The final URL after redirects (best effort)
 *   - error {string|null}: Error message if the request failed entirely
 *   Returns null if all retry attempts were exhausted.
 */
function httpGet(url) {
    var MAX_RETRIES = 3;     // Number of attempts on HTTP 500
    var RETRY_DELAY = 3000;  // 3 seconds between retries (in milliseconds)

    for (var attempt = 1; attempt <= MAX_RETRIES; attempt++) {
        try {
            // Create a new XMLHTTP object for each attempt
            // (reusing objects can cause issues with some error states)
            var xhr = new ActiveXObject("MSXML2.XMLHTTP");

            // Open a synchronous GET request
            // Parameters: method, url, async (false = synchronous/blocking)
            xhr.open("GET", url, false);

            // Set a polite User-Agent header identifying this scraper
            xhr.setRequestHeader("User-Agent",
                "Mozilla/5.0 (compatible; HymnScraper/2.0; personal use)");

            // Send the request (blocks until response is received)
            xhr.send();

            // Check for HTTP 500 (server error) — these are often transient
            if (xhr.status === 500) {
                if (attempt < MAX_RETRIES) {
                    WScript.Echo("    server error (500), retrying (" +
                                 attempt + "/" + MAX_RETRIES + ")...");
                    WScript.Sleep(RETRY_DELAY);
                    continue;  // Try again
                } else {
                    // All retries exhausted
                    return {
                        status: 500,
                        responseText: "",
                        responseUrl: url,
                        error: "server error (500) after " + MAX_RETRIES + " attempts"
                    };
                }
            }

            // Return the response object with all relevant data
            // Note: MSXML2.XMLHTTP doesn't expose the final URL after
            // redirects, so we attempt to detect redirects by checking
            // the response content instead (see fetchHymn function).
            return {
                status: xhr.status,
                responseText: xhr.responseText,
                responseUrl: url,  // Best we can do — XMLHTTP doesn't expose final URL
                error: null
            };

        } catch (e) {
            // Network errors, timeouts, DNS failures, etc.
            if (attempt < MAX_RETRIES) {
                WScript.Echo("    network error: " + e.message +
                             ", retrying (" + attempt + "/" + MAX_RETRIES + ")...");
                WScript.Sleep(RETRY_DELAY);
                continue;
            } else {
                return {
                    status: 0,
                    responseText: "",
                    responseUrl: url,
                    error: "network error: " + e.message
                };
            }
        }
    }

    // Should not reach here, but just in case
    return null;
}


// -----------------------------------------------------------------------
// PARSER: Extract hymn title from HTML
// -----------------------------------------------------------------------
/**
 * Extract the hymn title from the page HTML using regex.
 *
 * The title is located in the HTML structure:
 *   <div class="block-heading-four">
 *     <h3 class="wedding-heading">
 *       <strong>TITLE TEXT HERE</strong>
 *     </h3>
 *   </div>
 *
 * We use a regex that matches the <strong> tag content within any element
 * that has the "wedding-heading" class. This is more resilient than trying
 * to match the exact nesting structure.
 *
 * Regex breakdown:
 *   block-heading-four  - Match the container div class
 *   [\s\S]*?            - Non-greedy match of anything (including newlines)
 *   wedding-heading     - Match the heading class
 *   [\s\S]*?            - Non-greedy match up to the <strong> tag
 *   <strong[^>]*>       - Match the opening <strong> tag
 *   ([\s\S]*?)          - CAPTURE GROUP: the title text (non-greedy)
 *   <\/strong>          - Match the closing </strong> tag
 *
 * @param {string} html - The full page HTML
 * @returns {string} The extracted hymn title (empty string if not found)
 */
function extractTitle(html) {
    // Regex to find the title within the block-heading-four > wedding-heading > strong path
    var titleRegex = /block-heading-four[\s\S]*?wedding-heading[\s\S]*?<strong[^>]*>([\s\S]*?)<\/strong>/i;
    var match = titleRegex.exec(html);

    if (match && match[1]) {
        // Strip any remaining HTML tags from the title text
        var rawTitle = stripTags(match[1]);
        // Decode HTML entities (e.g. &amp; -> &, &rsquo; -> ')
        rawTitle = decodeEntities(rawTitle);
        // Trim whitespace
        return trim(rawTitle);
    }

    return "";  // Title not found
}


// -----------------------------------------------------------------------
// PARSER: Extract lyrics sections (indicators + lyrics) from HTML
// -----------------------------------------------------------------------
/**
 * Extract all hymn sections (verses, choruses, etc.) from the page HTML.
 *
 * The HTML structure for lyrics sections is:
 *   <div class="block-heading-three">Verse 1</div>   <- indicator
 *   <div class="block-heading-five">                  <- lyrics
 *     First line<br>Second line<br>
 *   </div>
 *
 * Indicators and lyrics blocks appear as sibling elements. An indicator
 * (block-heading-three) is paired with the next lyrics block
 * (block-heading-five) that follows it.
 *
 * Strategy:
 *   1. Find all block-heading-three and block-heading-five divs using regex
 *   2. Process them in order of appearance (by position in the HTML)
 *   3. When we encounter an indicator, store its text
 *   4. When we encounter a lyrics block, pair it with the stored indicator
 *
 * @param {string} html - The full page HTML
 * @returns {Array} Array of {indicator, lyrics} objects, where:
 *   - indicator: string like "Verse 1", "Chorus", or "" if no indicator
 *   - lyrics: the verse/chorus text with \n for line breaks
 */
function extractSections(html) {
    var sections = [];

    // --- Step 1: Find all indicator and lyrics blocks by position ---
    // We'll collect all matches with their position, type, and content,
    // then sort by position to process them in document order.
    var blocks = [];

    // Find all block-heading-three divs (indicators: "Verse 1", "Chorus", etc.)
    // Regex: match <div...class="...block-heading-three..."...>CONTENT</div>
    // The content is captured in group 1.
    var indicatorRegex = /<div[^>]*class="[^"]*block-heading-three[^"]*"[^>]*>([\s\S]*?)<\/div>/gi;
    var m;
    while ((m = indicatorRegex.exec(html)) !== null) {
        blocks.push({
            pos:     m.index,                     // Position in the HTML string
            type:    "indicator",                  // Block type
            content: trim(decodeEntities(stripTags(m[1])))  // Clean text content
        });
    }

    // Find all block-heading-five divs (lyrics blocks)
    // Regex: match <div...class="...block-heading-five..."...>CONTENT</div>
    // Uses a non-greedy match but needs to handle nested divs.
    // Since lyrics blocks typically don't contain nested divs, this is safe.
    var lyricsRegex = /<div[^>]*class="[^"]*block-heading-five[^"]*"[^>]*>([\s\S]*?)<\/div>/gi;
    while ((m = lyricsRegex.exec(html)) !== null) {
        // Process the lyrics text:
        // 1. Convert <br> tags to newlines
        // 2. Strip remaining HTML tags
        // 3. Decode HTML entities
        // 4. Collapse runs of 3+ newlines to 2 (matches Python version behaviour)
        var rawLyrics = m[1];
        rawLyrics = rawLyrics.replace(/<br\s*\/?>/gi, "\n");  // <br> -> \n
        rawLyrics = rawLyrics.replace(/<[^>]+>/g, "");          // Strip other tags
        rawLyrics = decodeEntities(rawLyrics);                   // Decode entities
        rawLyrics = rawLyrics.replace(/\n{3,}/g, "\n\n");       // Collapse excess newlines
        rawLyrics = trim(rawLyrics);                             // Trim whitespace

        blocks.push({
            pos:     m.index,
            type:    "lyrics",
            content: rawLyrics
        });
    }

    // --- Step 2: Sort all blocks by their position in the document ---
    // This ensures we process indicators and lyrics in the correct order,
    // regardless of which regex matched first.
    blocks.sort(function(a, b) { return a.pos - b.pos; });

    // --- Step 3: Pair indicators with their following lyrics blocks ---
    var currentIndicator = "";
    for (var i = 0; i < blocks.length; i++) {
        if (blocks[i].type === "indicator") {
            // Store the indicator text to pair with the next lyrics block
            currentIndicator = blocks[i].content;
        } else if (blocks[i].type === "lyrics") {
            // Pair this lyrics block with the most recent indicator
            sections.push({
                indicator: currentIndicator,
                lyrics:    blocks[i].content
            });
            // Reset indicator (some verses may not have one)
            currentIndicator = "";
        }
    }

    return sections;
}


// -----------------------------------------------------------------------
// SCRAPER: Fetch and parse a single hymn
// -----------------------------------------------------------------------
/**
 * Fetch a single hymn page and extract its title and lyrics sections.
 *
 * This is the main per-hymn function that:
 * 1. Constructs the hymn URL
 * 2. Makes the HTTP request (with retries via httpGet)
 * 3. Checks for rate limiting ("reached limit for today")
 * 4. Checks for homepage redirects (end of hymnal detection)
 * 5. Parses the HTML to extract title and sections
 *
 * Return values:
 *   - Object with {number, title, sections}: Success
 *   - "SKIP": This hymn should be skipped (error, no title, etc.)
 *   - null: Scraping should stop entirely (end of hymnal or persistent rate limit)
 *
 * @param {number} number  - The hymn number to fetch
 * @param {string} baseUrl - The site's hymn page base URL
 * @param {string} homeUrl - The site's homepage URL (for redirect detection)
 * @param {number} delay   - Delay in milliseconds between requests
 * @returns {object|string|null} Hymn data object, "SKIP", or null
 */
function fetchHymn(number, baseUrl, homeUrl, delay) {
    // Construct the hymn page URL using the query parameter format
    var url = baseUrl + "?no=" + number;

    // Make the HTTP request (includes retry logic for 500 errors)
    var resp = httpGet(url);

    // Check if the request failed entirely
    if (!resp || resp.error) {
        var errMsg = resp ? resp.error : "unknown error";
        WScript.Echo("  error: " + errMsg);
        return "SKIP";
    }

    // Check for non-200 HTTP status codes
    if (resp.status !== 200) {
        WScript.Echo("  HTTP " + resp.status + ".");
        return "SKIP";
    }

    var html = resp.responseText;

    // --- Redirect detection ---
    // Since MSXML2.XMLHTTP doesn't expose the final URL after redirects,
    // we check the HTML content for signs that we've been redirected to
    // the homepage. The homepage typically doesn't contain the hymn-specific
    // CSS classes. If "block-heading-four" is absent AND the page contains
    // homepage-specific content, we've reached the end of the hymnal.
    //
    // Additional check: if the response is suspiciously short or doesn't
    // contain hymn content markers, treat it as a redirect.
    if (number > 1) {
        // Check if the page lacks hymn content entirely
        var hasHymnContent = (html.indexOf("block-heading-four") !== -1) ||
                             (html.indexOf("block-heading-five") !== -1);
        if (!hasHymnContent) {
            WScript.Echo("");
            WScript.Echo("  Hymn " + number +
                         ": no hymn content found (likely redirected to home) " +
                         "-- reached end.");
            return null;  // Signal to stop scraping
        }
    }

    // --- Rate limit detection ---
    // Both sites show a "reached limit for today" or "we are sorry" message
    // when too many requests have been made. We check the HTML for these
    // phrases (case-insensitive by converting to lowercase).
    var htmlLower = html.toLowerCase();
    if (htmlLower.indexOf("reached limit for today") !== -1 ||
        htmlLower.indexOf("we are sorry") !== -1) {

        WScript.Echo("  rate limit hit -- pausing 60s...");
        WScript.Sleep(60000);  // 60-second cooldown

        // Retry once after the cooldown
        var resp2 = httpGet(url);
        if (!resp2 || resp2.error || resp2.status !== 200) {
            WScript.Echo("  Still failing after cooldown -- stopping.");
            return null;  // Stop entirely
        }

        // Check if we're still rate limited after waiting
        if (resp2.responseText.toLowerCase().indexOf("reached limit for today") !== -1) {
            WScript.Echo("  Still rate limited -- stopping. Try again tomorrow.");
            return null;  // Stop entirely
        }

        // Rate limit cleared — use the fresh response
        html = resp2.responseText;
    }

    // --- Parse the HTML ---
    var title = extractTitle(html);

    // Validate that a title was found (indicates a valid hymn page)
    if (!title) {
        WScript.Echo("  no title found.");
        return "SKIP";
    }

    var sections = extractSections(html);

    // Return the structured hymn data
    return {
        number:   number,
        title:    title,
        sections: sections
    };
}


// -----------------------------------------------------------------------
// OUTPUT: Format a hymn object into plain text for saving
// -----------------------------------------------------------------------
/**
 * Format a parsed hymn object into a clean plain-text string.
 *
 * Output format (matches the Python version exactly):
 *   "Hymn Title"
 *   <blank line>
 *   Verse 1
 *   First line of lyrics
 *   Second line of lyrics
 *   <blank line>
 *   Chorus
 *   Chorus lyrics here
 *   ...
 *
 * @param {object} hymn - Hymn object with title and sections properties
 * @returns {string} The formatted plain-text hymn content
 */
function formatHymn(hymn) {
    // Start with the quoted title and a blank line
    var lines = ['"' + hymn.title + '"', ""];

    // Append each section (indicator + lyrics) with blank line separators
    for (var i = 0; i < hymn.sections.length; i++) {
        var section = hymn.sections[i];

        if (section.indicator) {
            lines.push(section.indicator);  // e.g. "Verse 1", "Chorus"
        }
        if (section.lyrics) {
            lines.push(section.lyrics);     // The verse/chorus text
        }
        lines.push("");  // Blank line between sections
    }

    // Remove trailing blank lines (cleaner file ending)
    while (lines.length > 0 && lines[lines.length - 1] === "") {
        lines.pop();
    }

    return lines.join("\r\n");  // Use Windows line endings (CRLF)
}


// -----------------------------------------------------------------------
// OUTPUT: Save a hymn to a text file
// -----------------------------------------------------------------------
/**
 * Save a formatted hymn to a plain-text file in the output directory.
 *
 * Creates the output directory if it doesn't exist, formats the hymn,
 * and writes it with the standard naming convention:
 *   {3-digit zero-padded number} ({LABEL}) - {Title Case Title}.txt
 *
 * Uses Scripting.FileSystemObject for file operations, which is available
 * on all Windows versions since Windows 98.
 *
 * @param {object} hymn     - Hymn object with number, title, and sections
 * @param {string} label    - Book label for the filename (e.g. "SDAH")
 * @param {string} outputDir - Directory path where the file should be saved
 * @returns {string} The full file path of the saved file
 */
function saveHymn(hymn, label, outputDir) {
    // Ensure the output directory exists
    ensureDir(outputDir);

    // Zero-pad the hymn number to 3 digits (e.g. 1 -> "001", 42 -> "042")
    var padded = zeroPad(hymn.number, 3);

    // Build the filename: sanitize the title, then convert to Title Case
    var cleanTitle = titleCase(sanitize(hymn.title));
    var filename = padded + " (" + label + ") - " + cleanTitle + ".txt";

    // Construct the full file path
    var filepath = outputDir + "\\" + filename;

    // Write the formatted hymn text to the file
    // Parameters for CreateTextFile: filename, overwrite, unicode
    // We use unicode=true to support UTF-8/Unicode characters in hymn text
    try {
        var file = fso.CreateTextFile(filepath, true, true);
        file.Write(formatHymn(hymn));
        file.Close();
    } catch (e) {
        WScript.Echo("  ERROR writing file: " + e.message);
        WScript.Echo("  Path: " + filepath);
    }

    return filepath;
}


// -----------------------------------------------------------------------
// OUTPUT: Log a skipped hymn to the skip log
// -----------------------------------------------------------------------
/**
 * Record a skipped hymn in skipped.log for later review.
 *
 * Creates a persistent log of hymns that couldn't be scraped, along with
 * the reason and timestamp. This matches the Python version's log format:
 *   [2026-03-13 14:30:00]  SDAH042  --  fetch failed or no title found
 *
 * @param {number} number  - The hymn number that was skipped
 * @param {string} label   - Book label (e.g. "SDAH", "CH")
 * @param {string} reason  - Human-readable explanation for the skip
 * @param {string} bookDir - Directory where the log file should be written
 */
function logSkip(number, label, reason, bookDir) {
    // Ensure the directory exists
    ensureDir(bookDir);

    var logPath   = bookDir + "\\skipped.log";
    var timestamp = getTimestamp();
    var padded    = zeroPad(number, 3);
    var line      = "[" + timestamp + "]  " + label + padded +
                    "  --  " + reason + "\r\n";

    try {
        // Open in append mode (8 = ForAppending, true = create if not exists)
        // The second parameter of OpenTextFile is iomode:
        //   1 = ForReading, 2 = ForWriting, 8 = ForAppending
        // The third parameter is create (true = create if file doesn't exist)
        var file;
        if (fso.FileExists(logPath)) {
            file = fso.OpenTextFile(logPath, 8, false);  // Append
        } else {
            file = fso.CreateTextFile(logPath, false);     // Create new
        }
        file.Write(line);
        file.Close();
    } catch (e) {
        WScript.Echo("  WARNING: Could not write to skip log: " + e.message);
    }
}


// -----------------------------------------------------------------------
// RESUMABILITY: Build set of already-saved hymn numbers
// -----------------------------------------------------------------------
/**
 * Scan the output directory for hymn files that have already been saved.
 *
 * This enables the scraper to resume from where it left off without
 * re-downloading hymns. It looks for files matching the naming pattern:
 *   {number} ({LABEL}) - {title}.txt
 * and extracts the hymn number from each matching filename.
 *
 * Returns a plain object used as a set (keys are hymn numbers as strings,
 * values are true). Check membership with: if (existing[number]) { ... }
 *
 * @param {string} label     - The book label to filter by (e.g. "SDAH")
 * @param {string} outputDir - Path to the directory to scan
 * @returns {object} Object with hymn numbers as keys (used as a set)
 */
function buildExistingSet(label, outputDir) {
    var existing = {};  // Use an object as a set (keys = hymn numbers)
    var prefixTag = "(" + label + ") -";  // Pattern to match in filenames

    // Check if the directory exists
    if (!fso.FolderExists(outputDir)) {
        return existing;  // Empty set — directory doesn't exist yet
    }

    try {
        var folder = fso.GetFolder(outputDir);
        var files  = new Enumerator(folder.Files);

        // Iterate through all files in the directory
        for (; !files.atEnd(); files.moveNext()) {
            var fname = files.item().Name;

            // Check if this file matches our naming pattern
            // (contains the label tag and ends with .txt)
            if (fname.indexOf(prefixTag) !== -1 &&
                fname.substring(fname.length - 4).toLowerCase() === ".txt") {

                // Extract the hymn number from the start of the filename
                // (the zero-padded number before the first space)
                var parts = fname.split(" ");
                if (parts.length > 0) {
                    var numStr = parts[0];
                    // Verify it's all digits
                    if (/^\d+$/.test(numStr)) {
                        existing[parseInt(numStr, 10)] = true;
                    }
                }
            }
        }
    } catch (e) {
        WScript.Echo("  WARNING: Could not scan directory: " + e.message);
    }

    return existing;
}


// -----------------------------------------------------------------------
// MAIN LOOP: Scrape all hymns from a single site
// -----------------------------------------------------------------------
/**
 * Main scraping loop for one site (SDAH or CH).
 *
 * Iterates through hymn numbers, fetches each one, and saves it.
 * Features:
 *   - RESUMABILITY: Skips hymns already saved in the output directory
 *   - AUTO-DETECTION OF END: Stops when no hymn content is found
 *   - CONSECUTIVE SKIP LIMIT: Stops after MAX_CONSEC (10) failures in a row
 *   - RATE LIMITING: Pauses for the configured delay between requests
 *
 * @param {string} siteKey   - Site identifier ("sdah" or "ch")
 * @param {number} start     - First hymn number to scrape
 * @param {number|null} end  - Last hymn number, or null for auto-detect
 * @param {string} outputDir - Base output directory
 * @param {number} delay     - Milliseconds between requests
 * @param {boolean} force    - When true, skip existing file check and overwrite
 * @returns {number} Number of hymns successfully saved
 */
function scrapeSite(siteKey, start, end, outputDir, delay, force) {
    // Look up the site configuration
    var site    = SITES[siteKey];
    var label   = site.label;
    var baseUrl = site.baseUrl;
    var homeUrl = site.homeUrl;

    // Build the book-specific output subdirectory path
    // e.g. ".\hymns\Seventh-day Adventist Hymnal [SDAH]\"
    var bookDir = outputDir + "\\" + site.subdir;

    // Print a banner with configuration details for this scrape run
    WScript.Echo("");
    WScript.Echo("==================================================");
    WScript.Echo("  Scraping: " + baseUrl + "  [" + label + "]");
    WScript.Echo("  Output  : " + getAbsolutePath(bookDir));
    WScript.Echo("  Range   : " + start + " to " + (end ? end : "auto-detect"));
    WScript.Echo("==================================================");
    WScript.Echo("");

    // Scan for already-saved hymns to enable resumability.
    // When force mode is enabled, use an empty set so no hymns are skipped —
    // all hymns will be re-downloaded and existing files will be overwritten.
    var existing;
    if (force) {
        existing = {};  // Empty set — force mode skips the existing file check
        WScript.Echo("  Force mode: will re-download and overwrite existing files.");
        WScript.Echo("");
    } else {
        existing = buildExistingSet(label, bookDir);

        // Count existing files for the summary message
        var existingCount = 0;
        for (var k in existing) {
            if (existing.hasOwnProperty(k)) { existingCount++; }
        }
        if (existingCount > 0) {
            WScript.Echo("  Found " + existingCount + " existing " + label +
                         " hymns -- will skip.");
            WScript.Echo("");
        }
    }

    // Counters for the final summary
    var saved       = 0;     // Successfully saved hymns
    var skipped     = 0;     // Hymns skipped due to errors
    var number      = start; // Current hymn number being processed
    var consecSkip  = 0;     // Consecutive failure counter

    // --- Main scraping loop ---
    while (true) {
        // Check if we've reached the user-specified end hymn
        if (end && number > end) {
            WScript.Echo("");
            WScript.Echo("Reached end hymn (" + end + "). Done.");
            break;
        }

        // Skip hymns that are already saved (detected during initial scan)
        if (existing[number]) {
            WScript.Echo("  Hymn " + padRight(String(number), 4) +
                         ": [SKIP] already exists, skipping.");
            number++;
            continue;  // No delay — no network request was made
        }

        // Fetch and parse the hymn page
        WScript.Echo("  Hymn " + padRight(String(number), 4) + ": fetching...");
        var hymn = fetchHymn(number, baseUrl, homeUrl, delay);

        if (hymn === null) {
            // The site indicated no more hymns — stop scraping
            WScript.Echo("");
            break;
        }

        if (hymn === "SKIP") {
            // This hymn couldn't be scraped — log and continue
            WScript.Echo("    [FAIL] skipped.");
            logSkip(number, label, "fetch failed or no title found", bookDir);
            skipped++;
            consecSkip++;

            // Safety net: too many consecutive failures
            if (consecSkip >= MAX_CONSEC) {
                WScript.Echo("  " + MAX_CONSEC +
                             " consecutive errors -- assuming end of hymnal.");
                break;
            }

            number++;
            WScript.Sleep(delay);  // Rate limit even on failures
            continue;
        }

        // Success — reset consecutive skip counter and save the hymn
        consecSkip = 0;
        var path = saveHymn(hymn, label, bookDir);
        saved++;

        // Extract just the filename from the full path for display
        var savedFilename = fso.GetFileName(path);
        WScript.Echo("    [OK] " + savedFilename);

        number++;
        WScript.Sleep(delay);  // Rate limit between requests
    }

    // Print a summary for this site
    WScript.Echo("");
    WScript.Echo(label + ": " + saved + " hymns saved, " + skipped + " skipped.");
    return saved;
}


// -----------------------------------------------------------------------
// UTILITY: Pad a string on the right to a specified width
// -----------------------------------------------------------------------
/**
 * Pad a string with trailing spaces to reach the specified width.
 * Used for aligning console output (e.g. hymn numbers in the progress log).
 *
 * @param {string} s     - The string to pad
 * @param {number} width - The desired minimum width
 * @returns {string} The padded string
 */
function padRight(s, width) {
    while (s.length < width) { s = " " + s; }
    return s;
}


// -----------------------------------------------------------------------
// MAIN — Parse arguments and orchestrate scraping
// -----------------------------------------------------------------------
/**
 * Main entry point for the JScript scraping engine.
 *
 * Reads command-line arguments passed from the batch section:
 *   Arg 0: site    (sdah, ch, or both)
 *   Arg 1: start   (starting hymn number)
 *   Arg 2: end     (ending hymn number, or "auto")
 *   Arg 3: output  (output directory path)
 *   Arg 4: delay   (milliseconds between requests)
 *   Arg 5: force   ("1" to re-download and overwrite existing files)
 *
 * Then runs the scraping loop for the specified site(s).
 */
function main() {
    var args = WScript.Arguments;

    // Parse arguments from the batch section
    // Default values match the Python version's defaults
    var site      = (args.length > 0) ? args(0) : "both";
    var start     = (args.length > 1) ? parseInt(args(1), 10) : 1;
    var endArg    = (args.length > 2) ? args(2) : "auto";
    var outputDir = (args.length > 3) ? args(3) : ".\\hymns";
    var delay     = (args.length > 4) ? parseInt(args(4), 10) : 1000;

    // Parse force flag — "1" means force mode is enabled
    // When force is true, existing hymn files are overwritten instead of skipped
    var forceArg  = (args.length > 5) ? args(5) : "0";
    var force     = (forceArg === "1");

    // Handle "auto" end value — null means auto-detect
    var end = null;
    if (endArg && endArg.toLowerCase() !== "auto") {
        end = parseInt(endArg, 10);
        if (isNaN(end)) { end = null; }
    }

    // Validate start number
    if (isNaN(start) || start < 1) { start = 1; }

    // Validate delay
    if (isNaN(delay) || delay < 0) { delay = 1000; }

    // Print startup banner
    WScript.Echo("========================================================");
    WScript.Echo("  SDA Hymnal Scraper (Windows Batch/JScript Hybrid)");
    WScript.Echo("  Copyright 2025-2026 MWBM Partners Ltd.");
    WScript.Echo("========================================================");

    // Determine which sites to scrape
    var sitesToRun = [];
    site = site.toLowerCase();
    if (site === "both") {
        sitesToRun = ["sdah", "ch"];  // Scrape SDAH first, then CH
    } else if (site === "sdah" || site === "ch") {
        sitesToRun = [site];
    } else {
        WScript.Echo("ERROR: Invalid site '" + site +
                     "'. Choose sdah, ch, or both.");
        WScript.Quit(1);
    }

    // Run the scraping loop for each site, accumulating the total saved count
    var total = 0;
    for (var i = 0; i < sitesToRun.length; i++) {
        total += scrapeSite(sitesToRun[i], start, end, outputDir, delay, force);
    }

    // Print the final summary
    WScript.Echo("");
    WScript.Echo("All done! " + total + " hymns total saved to: " +
                 getAbsolutePath(outputDir));
}


// -----------------------------------------------------------------------
// ENTRY POINT — Invoke the main function
// -----------------------------------------------------------------------
// Wrap in try/catch to provide a clean error message if something goes
// wrong at the top level (e.g. missing COM objects, permission errors).
try {
    main();
} catch (e) {
    WScript.Echo("");
    WScript.Echo("FATAL ERROR: " + e.message);
    WScript.Echo("Error number: " + (e.number & 0xFFFF));
    WScript.Echo("");
    WScript.Echo("This script requires native Windows components:");
    WScript.Echo("  - cscript.exe (Windows Script Host)");
    WScript.Echo("  - MSXML2.XMLHTTP (ActiveX HTTP client)");
    WScript.Echo("  - Scripting.FileSystemObject (ActiveX file system)");
    WScript.Echo("All of these should be present on Windows XP SP2 and later.");
    WScript.Quit(1);
}
