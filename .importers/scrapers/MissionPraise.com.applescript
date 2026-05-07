(*
	MissionPraise.com.applescript
	importers/scrapers/MissionPraise.com.applescript

	Mission Praise Scraper for macOS — scrapes lyrics and downloads files from missionpraise.com.
	Copyright 2025-2026 MWBM Partners Ltd.

	Overview:
		This AppleScript authenticates with missionpraise.com (a WordPress-based site
		that requires a paid subscription), then crawls the paginated song index
		to discover all songs across three hymnbooks:
			- Mission Praise (MP)  — ~1000+ songs, 4-digit numbering
			- Carol Praise (CP)    — ~300 songs, 3-digit numbering
			- Junior Praise (JP)   — ~300 songs, 3-digit numbering

		For each song, it:
		1. Scrapes the lyrics from the song detail page
		2. Optionally downloads associated files (words RTF/DOC, music PDF, audio MP3)
		3. Saves everything with a consistent filename convention

		The scraper is designed to be resumable: it checks the output directory for
		existing files on startup and skips songs that already have saved lyrics/downloads.

	Authentication:
		The site uses WordPress standard login (wp-login.php) with CSRF nonces
		and may be behind a Sucuri WAF (Web Application Firewall). The login
		flow handles:
		- Extracting hidden form fields (nonces) from the login page via shell regex
		- Setting proper Referer/Origin headers to pass WAF checks
		- Detecting WAF blocks, login failures, and ambiguous states
		- Cookie-based session management via a temp cookie jar file

	How to use:
		1. Open this file in Script Editor (double-click it)
		2. Click the "Run" (play) button
		3. Follow the interactive dialogs to configure and start
		-OR-
		1. In Script Editor, choose File > Export... > File Format: Application
		2. Save as "Mission Praise Scraper.app"
		3. Double-click the .app from Finder to run

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

	Dependencies:
		None — uses only macOS built-in tools (curl, grep, sed, awk, file, xxd).
		Requires macOS 10.12+ for full compatibility.

	Notes:
		- All HTTP requests are made via `do shell script` calling curl
		- HTML parsing is performed via grep/sed/awk shell pipelines
		- Progress is shown via `display notification` and Script Editor's log
		- Passwords are entered with `with hidden answer` to mask input
		- All temp files (cookie jar, helper scripts) are cleaned up on exit
		- Use `quoted form of` for all shell arguments to prevent injection
*)


-- ==========================================================================
-- PROPERTIES — Script-level constants and configuration
-- ==========================================================================

-- Script metadata (displayed in welcome dialog)
property scriptName : "Mission Praise Scraper"
property scriptVersion : "1.0.0"
property scriptCopyright : "Copyright 2025-2026 MWBM Partners Ltd."

-- Base URL for the Mission Praise website
property baseURL : "https://missionpraise.com"

-- WordPress standard login endpoint
property loginURL : "https://missionpraise.com/wp-login.php"

-- Paginated song index URL base — append page number and trailing slash
property indexURL : "https://missionpraise.com/songs/page/"

-- Default delay between HTTP requests (seconds). 1.2s is chosen to be
-- respectful to the server while keeping scraping at a reasonable speed.
property defaultDelay : 1.2

-- Force re-download: when true, skips the file cache check and re-downloads
-- all songs even if they already exist on disk. Useful for refreshing lyrics
-- after upstream corrections or re-downloading files that may be corrupt.
property defaultForceRedownload : false

-- User-Agent string mimicking Chrome on macOS (avoids WAF blocks)
property userAgent : "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 Edg/145.0.0.0"


-- ==========================================================================
-- MAIN ENTRY POINT — Show welcome dialog and branch to user's choice
-- ==========================================================================

-- Wrap the entire script in a try block so we can clean up temp files on error
-- The cookieJar variable is declared here so it is accessible in the on error block
set cookieJar to ""

try
	-- ======================================================================
	-- WELCOME DIALOG — Introduction, with Help and Start options
	-- ======================================================================
	-- The welcome dialog gives the user three choices:
	--   "Help / ?" — show detailed usage information
	--   "Configure & Start" — show all configuration dialogs
	--   "Quick Start" — use defaults, only prompt for credentials
	set welcomeMsg to scriptName & " v" & scriptVersion & return & return & ¬
		"Scrapes lyrics and downloads files from missionpraise.com." & return & ¬
		"Supports Mission Praise (MP), Carol Praise (CP), and Junior Praise (JP)." & return & return & ¬
		scriptCopyright

	set welcomeResult to display dialog welcomeMsg with title scriptName buttons {"Help / ?", "Configure & Start", "Quick Start"} default button "Quick Start" with icon note
	set welcomeChoice to button returned of welcomeResult

	-- ======================================================================
	-- HELP DIALOG — Detailed usage information (shown if user clicks Help)
	-- ======================================================================
	if welcomeChoice is "Help / ?" then
		set helpMsg to "MISSION PRAISE SCRAPER — HELP" & return & return & ¬
			"This script scrapes lyrics and optionally downloads files (words, music, audio) " & ¬
			"from missionpraise.com. A valid paid subscription is required." & return & return & ¬
			"AUTHENTICATION:" & return & ¬
			"  - Enter your missionpraise.com email and password when prompted" & return & ¬
			"  - The script authenticates via WordPress login (wp-login.php)" & return & ¬
			"  - Cookies are stored in a temporary file and deleted on exit" & return & ¬
			"  - The site may use a Sucuri WAF — if blocked, log in via browser first" & return & return & ¬
			"BOOKS:" & return & ¬
			"  - Mission Praise (MP): ~1000+ songs, 4-digit numbering" & return & ¬
			"  - Carol Praise (CP): ~300 songs, 3-digit numbering" & return & ¬
			"  - Junior Praise (JP): ~300 songs, 3-digit numbering" & return & return & ¬
			"OUTPUT FORMAT:" & return & ¬
			"  Lyrics: {number} ({BOOK}) - {Title}.txt" & return & ¬
			"  Words:  {number} ({BOOK}) - {Title}.rtf" & return & ¬
			"  Music:  {number} ({BOOK}) - {Title}_music.pdf" & return & ¬
			"  Audio:  {number} ({BOOK}) - {Title}_audio.mp3" & return & return & ¬
			"FILES ARE SAVED INTO SUBDIRECTORIES:" & return & ¬
			"  hymns/Mission Praise [MP]/" & return & ¬
			"  hymns/Carol Praise [CP]/" & return & ¬
			"  hymns/Junior Praise [JP]/" & return & return & ¬
			"RESUMABILITY:" & return & ¬
			"  The scraper checks for existing files and skips them." & return & ¬
			"  Use 'Start Page' to resume from a specific index page." & return & ¬
			"  Use 'Force Re-download' to overwrite all existing files." & return & return & ¬
			"DEPENDENCIES: None — uses only macOS built-in tools."

		display dialog helpMsg with title scriptName & " — Help" buttons {"OK"} default button "OK" with icon note

		-- After showing help, return to welcome dialog by re-running
		-- (AppleScript doesn't have goto, so we show the config dialog)
		set welcomeChoice to "Configure & Start"
	end if

	-- ======================================================================
	-- CONFIGURATION DIALOGS — Gather all settings from the user
	-- ======================================================================

	-- Variables for all configuration values (set defaults first)
	set mpUsername to ""
	set mpPassword to ""
	set selectedBooks to {"mp", "cp", "jp"}
	set outputDir to ""
	set startPage to 1
	set singleSongNumber to 0
	set requestDelay to defaultDelay
	set downloadFiles to true
	set forceRedownload to defaultForceRedownload
	set debugMode to false

	if welcomeChoice is "Configure & Start" then
		-- ================================================================
		-- USERNAME — Prompt for missionpraise.com email address
		-- ================================================================
		set usernameResult to display dialog "Mission Praise email:" with title scriptName default answer "" buttons {"Cancel", "OK"} default button "OK" with icon note
		set mpUsername to text returned of usernameResult

		-- Validate that username is not empty
		if mpUsername is "" then
			display dialog "Error: Username cannot be empty." with title scriptName buttons {"OK"} default button "OK" with icon stop
			return
		end if

		-- ================================================================
		-- PASSWORD — Prompt with hidden input (masks characters)
		-- The `with hidden answer` option hides the password as it is typed
		-- ================================================================
		set passwordResult to display dialog "Password:" with title scriptName default answer "" buttons {"Cancel", "OK"} default button "OK" with icon note with hidden answer
		set mpPassword to text returned of passwordResult

		-- Validate that password is not empty
		if mpPassword is "" then
			display dialog "Error: Password cannot be empty." with title scriptName buttons {"OK"} default button "OK" with icon stop
			return
		end if

		-- ================================================================
		-- BOOK SELECTION — Choose which hymnbooks to scrape
		-- ================================================================
		set bookChoices to {"Mission Praise (MP)", "Carol Praise (CP)", "Junior Praise (JP)", "All"}
		set bookResult to choose from list bookChoices with title scriptName with prompt "Select books to scrape:" default items {"All"} with multiple selections allowed

		-- Handle user cancellation
		if bookResult is false then
			return
		end if

		-- Convert the human-readable book names to internal keys
		set selectedBooks to {}
		repeat with bookItem in bookResult
			set bookItem to bookItem as text
			if bookItem is "Mission Praise (MP)" then
				set end of selectedBooks to "mp"
			else if bookItem is "Carol Praise (CP)" then
				set end of selectedBooks to "cp"
			else if bookItem is "Junior Praise (JP)" then
				set end of selectedBooks to "jp"
			else if bookItem is "All" then
				set selectedBooks to {"mp", "cp", "jp"}
				exit repeat
			end if
		end repeat

		-- ================================================================
		-- OUTPUT DIRECTORY — Let the user pick a folder with Finder dialog
		-- ================================================================
		try
			set outputFolder to choose folder with prompt "Select output directory for scraped files:" default location (path to desktop)
			set outputDir to POSIX path of outputFolder
			-- Remove trailing slash if present (we add it ourselves later)
			if outputDir ends with "/" and (count of outputDir) > 1 then
				set outputDir to text 1 thru -2 of outputDir
			end if
		on error
			-- User cancelled the folder picker
			return
		end try

		-- ================================================================
		-- START PAGE — For resuming from a specific index page
		-- ================================================================
		set startPageResult to display dialog "Start page (for resuming):" with title scriptName default answer "1" buttons {"Cancel", "OK"} default button "OK" with icon note
		try
			set startPage to (text returned of startPageResult) as integer
			if startPage < 1 then set startPage to 1
		on error
			set startPage to 1
		end try

		-- ================================================================
		-- SONG NUMBER — Scrape a single song by number (optional)
		-- When set, only this song number will be scraped. Force mode
		-- is automatically enabled and scraping stops after the song
		-- is found. Leave blank (0) to scrape all songs.
		-- ================================================================
		set songNumResult to display dialog "Single song number (0 = all songs):" & return & "(auto-enables force mode when set)" with title scriptName default answer "0" buttons {"Cancel", "OK"} default button "OK" with icon note
		try
			set singleSongNumber to (text returned of songNumResult) as integer
			if singleSongNumber < 0 then set singleSongNumber to 0
		on error
			set singleSongNumber to 0
		end try

		-- Auto-enable force mode when targeting a single song
		if singleSongNumber > 0 then
			set forceRedownload to true
		end if

		-- ================================================================
		-- REQUEST DELAY — Seconds between HTTP requests
		-- ================================================================
		set delayResult to display dialog "Delay between requests (seconds):" with title scriptName default answer "1.2" buttons {"Cancel", "OK"} default button "OK" with icon note
		try
			set requestDelay to (text returned of delayResult) as real
			if requestDelay < 0.3 then set requestDelay to 0.3
		on error
			set requestDelay to defaultDelay
		end try

		-- ================================================================
		-- DOWNLOAD FILES — Whether to download words/music/audio files
		-- ================================================================
		set dlResult to display dialog "Download associated files (words, music, audio)?" with title scriptName buttons {"No", "Yes"} default button "Yes" with icon note
		if button returned of dlResult is "No" then
			set downloadFiles to false
		else
			set downloadFiles to true
		end if

		-- ================================================================
		-- FORCE RE-DOWNLOAD — Whether to overwrite existing files
		-- When enabled, the file cache is bypassed and all songs are
		-- re-scraped and re-downloaded regardless of what already exists.
		-- ================================================================
		set forceResult to display dialog "Force re-download existing files?" with title scriptName buttons {"No", "Yes"} default button "No" with icon note
		if button returned of forceResult is "Yes" then
			set forceRedownload to true
		else
			set forceRedownload to false
		end if

		-- ================================================================
		-- DEBUG MODE — Whether to dump HTML responses for troubleshooting
		-- ================================================================
		set debugResult to display dialog "Enable debug mode? (Dumps HTML to Script Editor log)" with title scriptName buttons {"No", "Yes"} default button "No" with icon note
		if button returned of debugResult is "Yes" then
			set debugMode to true
		else
			set debugMode to false
		end if

	else if welcomeChoice is "Quick Start" then
		-- ================================================================
		-- QUICK START — Only prompt for credentials, use all defaults
		-- ================================================================

		-- Username
		set usernameResult to display dialog "Mission Praise email:" with title scriptName default answer "" buttons {"Cancel", "OK"} default button "OK" with icon note
		set mpUsername to text returned of usernameResult
		if mpUsername is "" then
			display dialog "Error: Username cannot be empty." with title scriptName buttons {"OK"} default button "OK" with icon stop
			return
		end if

		-- Password (hidden input)
		set passwordResult to display dialog "Password:" with title scriptName default answer "" buttons {"Cancel", "OK"} default button "OK" with icon note with hidden answer
		set mpPassword to text returned of passwordResult
		if mpPassword is "" then
			display dialog "Error: Password cannot be empty." with title scriptName buttons {"OK"} default button "OK" with icon stop
			return
		end if

		-- Default output directory: ~/Desktop/hymns
		set outputDir to (POSIX path of (path to desktop)) & "hymns"

		-- All other settings use defaults defined above
	end if

	-- ======================================================================
	-- CONFIRMATION DIALOG — Show all settings before starting
	-- ======================================================================

	-- Build a human-readable books string for display
	set booksDisplay to ""
	repeat with i from 1 to count of selectedBooks
		set bk to item i of selectedBooks
		if bk is "mp" then
			set booksDisplay to booksDisplay & "Mission Praise (MP)"
		else if bk is "cp" then
			set booksDisplay to booksDisplay & "Carol Praise (CP)"
		else if bk is "jp" then
			set booksDisplay to booksDisplay & "Junior Praise (JP)"
		end if
		if i < (count of selectedBooks) then
			set booksDisplay to booksDisplay & ", "
		end if
	end repeat

	-- Format the download option for display
	if downloadFiles then
		set dlDisplay to "Yes (words, music, audio)"
	else
		set dlDisplay to "No (lyrics only)"
	end if

	-- Format the force re-download option for display
	if forceRedownload then
		set forceDisplay to "Yes (overwrite existing)"
	else
		set forceDisplay to "No (skip existing)"
	end if

	-- Format the debug option for display
	if debugMode then
		set debugDisplay to "Yes"
	else
		set debugDisplay to "No"
	end if

	-- Format the song number option for display
	if singleSongNumber > 0 then
		set songDisplay to (singleSongNumber as text) & " (force mode auto-enabled)"
	else
		set songDisplay to "All"
	end if

	set confirmMsg to "Please confirm your settings:" & return & return & ¬
		"Username:     " & mpUsername & return & ¬
		"Password:     " & "********" & return & ¬
		"Books:        " & booksDisplay & return & ¬
		"Output:       " & outputDir & return & ¬
		"Start Page:   " & (startPage as text) & return & ¬
		"Song:         " & songDisplay & return & ¬
		"Delay:        " & (requestDelay as text) & "s" & return & ¬
		"Downloads:    " & dlDisplay & return & ¬
		"Force:        " & forceDisplay & return & ¬
		"Debug:        " & debugDisplay

	set confirmResult to display dialog confirmMsg with title scriptName & " — Confirm" buttons {"Cancel", "Start Scraping"} default button "Start Scraping" with icon note

	if button returned of confirmResult is "Cancel" then
		return
	end if

	-- ======================================================================
	-- SETUP — Create temp files and output directories
	-- ======================================================================

	-- Create a temporary cookie jar file for curl session management.
	-- mktemp creates a unique temp file that we delete when done.
	set cookieJar to do shell script "mktemp /tmp/mp_cookies.XXXXXX"
	log "Cookie jar: " & cookieJar

	-- Create the base output directory if it doesn't exist
	do shell script "mkdir -p " & quoted form of outputDir

	-- Create book-specific subdirectories for each selected book
	repeat with bk in selectedBooks
		set subdir to getBookSubdir(bk)
		set bookPath to outputDir & "/" & subdir
		do shell script "mkdir -p " & quoted form of bookPath
	end repeat

	-- ======================================================================
	-- AUTHENTICATION — Log in to missionpraise.com via WordPress login
	-- ======================================================================

	log "Logging in as " & mpUsername & "..."
	display notification "Logging in to missionpraise.com..." with title scriptName

	-- Step 1: GET the login page to extract CSRF nonces (hidden form fields).
	-- WordPress login forms contain hidden fields (nonces) that must be
	-- submitted with the POST request to prevent CSRF attacks.
	-- We use curl with cookie jar to store any initial cookies.
	set loginPageHTML to do shell script "curl -s -L " & ¬
		"-c " & quoted form of cookieJar & " " & ¬
		"-b " & quoted form of cookieJar & " " & ¬
		"-A " & quoted form of userAgent & " " & ¬
		"-H 'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8' " & ¬
		"-H 'Accept-Language: en-GB,en;q=0.9' " & ¬
		"-H 'Accept-Encoding: identity' " & ¬
		"-H 'Connection: keep-alive' " & ¬
		"-H 'Upgrade-Insecure-Requests: 1' " & ¬
		"-H 'Sec-Fetch-Dest: document' " & ¬
		"-H 'Sec-Fetch-Mode: navigate' " & ¬
		"-H 'Sec-Fetch-Site: none' " & ¬
		"-H 'Sec-Fetch-User: ?1' " & ¬
		"-H 'Cache-Control: max-age=0' " & ¬
		quoted form of loginURL

	if debugMode then
		log "DEBUG: Login page HTML (first 3000 chars):"
		log text 1 thru (min(3000, count of loginPageHTML)) of loginPageHTML
	end if

	-- Step 2: Extract hidden form fields from the login page.
	-- These typically include testcookie, _wpnonce, and various plugin-specific nonces.
	-- We use grep/sed to find all <input type="hidden"> fields and extract name=value pairs.
	set hiddenFields to extractHiddenFields(loginPageHTML)

	if debugMode then
		log "DEBUG: Hidden fields found: " & hiddenFields
	end if

	-- Step 3: Brief pause to appear more human-like.
	-- Some WAFs flag requests that POST to the login form within milliseconds
	-- of loading the page, as this is a strong indicator of automated access.
	delay 2

	-- Step 4: POST the login credentials with proper headers.
	-- Build the form data string including WordPress standard fields and all nonces.
	-- We URL-encode the username and password to handle special characters.
	set encodedUsername to urlEncode(mpUsername)
	set encodedPassword to urlEncode(mpPassword)

	-- Construct the POST payload: WordPress standard fields + hidden nonces
	set postData to "log=" & encodedUsername & "&pwd=" & encodedPassword & "&wp-submit=Log+In&redirect_to=" & urlEncode(baseURL & "/songs/") & "&rememberme=forever"

	-- Append hidden form fields to the POST data
	if hiddenFields is not "" then
		set postData to postData & "&" & hiddenFields
	end if

	-- Execute the login POST request with curl.
	-- -L follows redirects (WordPress redirects after successful login).
	-- -D - dumps response headers so we can check the redirect URL.
	-- We write the full response (headers + body) for analysis.
	set loginResult to do shell script "curl -s -L -w '\\n__HTTP_CODE__:%{http_code}\\n__FINAL_URL__:%{url_effective}' " & ¬
		"-c " & quoted form of cookieJar & " " & ¬
		"-b " & quoted form of cookieJar & " " & ¬
		"-A " & quoted form of userAgent & " " & ¬
		"-H 'Referer: " & loginURL & "' " & ¬
		"-H 'Origin: " & baseURL & "' " & ¬
		"-H 'Content-Type: application/x-www-form-urlencoded' " & ¬
		"-H 'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8' " & ¬
		"-H 'Accept-Language: en-GB,en;q=0.9' " & ¬
		"-H 'Accept-Encoding: identity' " & ¬
		"-H 'Sec-Fetch-Dest: document' " & ¬
		"-H 'Sec-Fetch-Mode: navigate' " & ¬
		"-H 'Sec-Fetch-Site: same-origin' " & ¬
		"-H 'Sec-Fetch-User: ?1' " & ¬
		"-d " & quoted form of postData & " " & ¬
		quoted form of loginURL

	if debugMode then
		log "DEBUG: Login POST result (first 3000 chars):"
		log text 1 thru (min(3000, count of loginResult)) of loginResult
	end if

	-- Step 5: Extract the final URL and HTTP status code from curl output
	set finalURL to extractCurlMetadata(loginResult, "__FINAL_URL__:")
	set httpCode to extractCurlMetadata(loginResult, "__HTTP_CODE__:")

	if debugMode then
		log "DEBUG: Post-login final URL: " & finalURL
		log "DEBUG: Post-login HTTP code: " & httpCode
	end if

	-- Step 6: Check for various failure modes
	set loginBody to loginResult
	set loginBodyLower to toLower(loginBody)

	-- Check for WAF / firewall blocks (e.g. Sucuri CDN/WAF)
	if loginBodyLower contains "sucuri" or loginBodyLower contains "access denied" then
		display dialog "BLOCKED by website firewall." & return & return & ¬
			"Your request was blocked by the site's security system (Sucuri WAF)." & return & ¬
			"Try logging in via a web browser first to whitelist your IP." with title scriptName buttons {"OK"} default button "OK" with icon stop
		cleanupCookieJar(cookieJar)
		return
	end if

	-- Check for incorrect credentials (WordPress stays on wp-login.php with error)
	if finalURL contains "wp-login.php" and loginBodyLower contains "incorrect" then
		display dialog "Login failed — check username/password." with title scriptName buttons {"OK"} default button "OK" with icon stop
		cleanupCookieJar(cookieJar)
		return
	end if

	-- Check for other WordPress login errors (still on login page after POST)
	if finalURL contains "wp-login.php" then
		-- Try to extract the WordPress login error message
		set wpError to extractWPLoginError(loginBody)
		if wpError is not "" then
			display dialog "Login error: " & wpError with title scriptName buttons {"OK"} default button "OK" with icon stop
			cleanupCookieJar(cookieJar)
			return
		end if
	end if

	-- Step 7: Verify login by fetching the songs page and checking for logged-in indicators
	set songsCheckHTML to do shell script "curl -s -L " & ¬
		"-c " & quoted form of cookieJar & " " & ¬
		"-b " & quoted form of cookieJar & " " & ¬
		"-A " & quoted form of userAgent & " " & ¬
		"-H 'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8' " & ¬
		"-H 'Accept-Encoding: identity' " & ¬
		"-H 'Sec-Fetch-Dest: document' " & ¬
		"-H 'Sec-Fetch-Mode: navigate' " & ¬
		"-H 'Sec-Fetch-Site: same-origin' " & ¬
		"-w '\\n__FINAL_URL__:%{url_effective}' " & ¬
		quoted form of (baseURL & "/songs/")

	set songsCheckURL to extractCurlMetadata(songsCheckHTML, "__FINAL_URL__:")
	set songsCheckLower to toLower(songsCheckHTML)

	if debugMode then
		log "DEBUG: Songs page URL: " & songsCheckURL
		log "DEBUG: Songs page HTML (first 2000 chars):"
		log text 1 thru (min(2000, count of songsCheckHTML)) of songsCheckHTML
	end if

	-- Look for multiple indicators of being logged in
	set loggedIn to false
	if songsCheckLower contains "logout" or ¬
		songsCheckLower contains "log out" or ¬
		songsCheckLower contains "log-out" or ¬
		songsCheckHTML contains "Welcome" or ¬
		songsCheckLower contains "my-account" or ¬
		songsCheckLower contains "wp-admin" or ¬
		songsCheckLower contains "logged-in" then
		set loggedIn to true
	end if

	if loggedIn then
		log "Logged in successfully."
		display notification "Logged in successfully." with title scriptName
	else if songsCheckURL contains "wp-login" or songsCheckURL contains "login" then
		-- Redirected back to login page means session was not established
		display dialog "Login failed — redirected back to login page." & return & ¬
			"Check your credentials and try again." with title scriptName buttons {"OK"} default button "OK" with icon stop
		cleanupCookieJar(cookieJar)
		return
	else
		-- Login status is ambiguous — proceed anyway
		log "Login status unclear — proceeding anyway."
		log "(Re-run with Debug mode enabled to see full HTML responses)"
	end if

	-- ======================================================================
	-- BUILD FILE CACHE — Scan existing files for resumability
	-- ======================================================================

	-- Build a list of existing filenames across all book subdirectories.
	-- This allows O(1) lookup to skip songs that have already been saved.
	-- When force re-download is enabled, we skip building the cache entirely
	-- so that every song is re-scraped and overwritten on disk.
	set fileCache to {}

	if forceRedownload then
		-- Force mode: use empty cache so nothing is considered "existing"
		log "Force mode: will re-download and overwrite existing files."
	else
		repeat with bk in selectedBooks
			set subdir to getBookSubdir(bk)
			set bookPath to outputDir & "/" & subdir
			try
				set existingFiles to do shell script "ls -1 " & quoted form of bookPath & " 2>/dev/null || true"
				if existingFiles is not "" then
					set existingFiles to splitString(existingFiles, linefeed)
					repeat with ef in existingFiles
						set end of fileCache to ef as text
					end repeat
				end if
			end try
		end repeat

		set fileCacheCount to count of fileCache
		if fileCacheCount > 0 then
			log "Found " & (fileCacheCount as text) & " existing files across output folders — will skip duplicates."
		end if
	end if

	-- ======================================================================
	-- MAIN CRAWL AND SCRAPE — Paginated index crawling
	-- ======================================================================

	-- Print configuration summary to log
	log ""
	log "Books    : " & booksDisplay
	log "Output   : " & outputDir
	if downloadFiles then
		log "Downloads: enabled (words, music, audio)"
	else
		log "Downloads: disabled (lyrics only)"
	end if
	log ""

	-- Counters for the final summary
	set savedCount to 0
	set skippedCount to 0
	set existedCount to 0

	-- Current page number (starts at user-specified start page)
	set currentPage to startPage

	-- Track the last page's first song URL to detect loops
	set lastFirstSongURL to ""

	-- Main pagination loop — fetch each index page and process songs
	repeat
		-- Construct the URL for the current index page
		set pageURL to indexURL & (currentPage as text) & "/"

		log "Fetching index page " & (currentPage as text) & "..."

		-- Fetch the index page HTML
		set indexHTML to ""
		try
			set indexHTML to do shell script "curl -s -L " & ¬
				"-c " & quoted form of cookieJar & " " & ¬
				"-b " & quoted form of cookieJar & " " & ¬
				"-A " & quoted form of userAgent & " " & ¬
				"-H 'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8' " & ¬
				"-H 'Accept-Encoding: identity' " & ¬
				"-H 'Sec-Fetch-Dest: document' " & ¬
				"-H 'Sec-Fetch-Mode: navigate' " & ¬
				"-H 'Sec-Fetch-Site: same-origin' " & ¬
				quoted form of pageURL
		on error errMsg
			log "Error fetching page " & (currentPage as text) & ": " & errMsg
			exit repeat
		end try

		-- Check for empty response or "Page not found"
		if indexHTML is "" or indexHTML contains "Page not found" then
			if debugMode then
				if indexHTML is "" then
					log "DEBUG: Page " & (currentPage as text) & " — empty response"
				else
					log "DEBUG: Page " & (currentPage as text) & " — Page not found detected"
				end if
			end if
			exit repeat
		end if

		-- Debug dump of the first page
		if currentPage is startPage and debugMode then
			log "DEBUG: Index page " & (currentPage as text) & " HTML (first 3000 chars):"
			log text 1 thru (min(3000, count of indexHTML)) of indexHTML
		end if

		-- Loop detection: Check the X-Y of Z counter.
		-- WordPress has a quirk where out-of-range page numbers silently serve
		-- page 1 content instead of returning a 404.
		set counterInfo to extractPageCounter(indexHTML)
		set counterLo to item 1 of counterInfo
		set counterHi to item 2 of counterInfo
		set counterTotal to item 3 of counterInfo

		if counterTotal > 0 then
			-- Print total count on the first page
			if currentPage is startPage then
				set totalPages to (counterTotal + 9) div 10
				log counterTotal & " total songs across ~" & (totalPages as text) & " pages"
				log ""
			end if

			-- Detect loops: impossible counter or WordPress served page 1 again
			if counterLo > counterHi or (currentPage > 1 and counterLo is 1) then
				log "Loop detected at page " & (currentPage as text) & " — stopping."
				exit repeat
			end if
		else
			-- If we can't find the counter and we're past page 1,
			-- assume we've gone beyond the last page
			if currentPage > 1 then
				exit repeat
			end if
		end if

		-- Parse the index page for song links using grep/sed.
		-- Songs appear as <a> tags inside <h2> or <h3> elements with /songs/ URLs.
		set songLinks to extractSongLinks(indexHTML)

		if (count of songLinks) is 0 then
			-- No song links found — we've reached the end of the index
			if debugMode then
				log "DEBUG: No song links found on page " & (currentPage as text)
			end if
			exit repeat
		end if

		-- Loop detection via first song URL: if the first song on this page
		-- is the same as the first song on the previous page, we're looping
		if (count of songLinks) > 0 then
			set firstSongURL to item 2 of item 1 of songLinks
			if firstSongURL is lastFirstSongURL and currentPage > startPage then
				log "Loop detected (same first song as previous page) — stopping."
				exit repeat
			end if
			set lastFirstSongURL to firstSongURL
		end if

		-- Filter songs to only the requested books
		set pageSongs to {}
		repeat with songItem in songLinks
			set songTitle to item 1 of songItem
			set songURL to item 2 of songItem

			-- Check which book this song belongs to
			repeat with bk in selectedBooks
				set bkText to bk as text
				set bookPattern to getBookPattern(bkText)
				try
					-- Use grep to test if the title matches the book pattern
					do shell script "echo " & quoted form of songTitle & " | grep -iE " & quoted form of bookPattern & " > /dev/null 2>&1"
					-- If grep succeeds (no error), the song matches this book
					set end of pageSongs to {songTitle, songURL, bkText}
					exit repeat
				on error
					-- grep returned non-zero = no match, try next book
				end try
			end repeat
		end repeat

		-- If targeting a single song number, filter pageSongs to only that number
		if singleSongNumber > 0 then
			set filteredSongs to {}
			repeat with songItem in pageSongs
				set sTitle to item 1 of songItem
				set sBook to item 3 of songItem
				set sNum to extractSongNumber(sTitle, sBook)
				if sNum is singleSongNumber then
					set end of filteredSongs to (songItem as list)
				end if
			end repeat
			set pageSongs to filteredSongs
		end if

		-- Print page summary with running totals
		set pageMatchCount to count of pageSongs
		log "  Page " & rightPad(currentPage as text, 4) & "  —  " & (pageMatchCount as text) & " matching songs  (saved: " & (savedCount as text) & ", existed: " & (existedCount as text) & ", skipped: " & (skippedCount as text) & ")"

		-- Show notification every 10 pages for progress feedback
		if currentPage mod 10 is 0 then
			display notification "Processing page " & (currentPage as text) & "... (saved: " & (savedCount as text) & ")" with title scriptName
		end if

		-- Process each song on this page
		set pageExisted to 0
		repeat with songItem in pageSongs
			set songTitle to item 1 of songItem
			set songURL to item 2 of songItem
			set songBook to item 3 of songItem

			-- Process the individual song (scrape lyrics, download files)
			set result to processSong(songTitle, songURL, songBook, outputDir, downloadFiles, requestDelay, debugMode, cookieJar, fileCache)

			if result is "saved" then
				set savedCount to savedCount + 1
				-- Show notification every 10 saved songs
				if savedCount mod 10 is 0 then
					display notification (savedCount as text) & " songs saved so far..." with title scriptName
				end if
			else if result is "skipped" then
				set skippedCount to skippedCount + 1
			else if result is "exists" then
				set existedCount to existedCount + 1
				set pageExisted to pageExisted + 1
			end if
		end repeat

		-- If every song on this page already existed, print a compact summary
		if pageExisted is pageMatchCount and pageMatchCount > 0 then
			log "           >>  all " & (pageExisted as text) & " songs on this page already exist"
		end if

		-- If targeting a single song and we found it, stop immediately
		if singleSongNumber > 0 and savedCount > 0 then
			log "Single song " & (singleSongNumber as text) & " found and saved — stopping."
			exit repeat
		end if

		-- Move to the next page
		set currentPage to currentPage + 1

		-- Rate limit between index pages
		delay requestDelay
	end repeat

	-- ======================================================================
	-- FINAL SUMMARY — Show results and clean up
	-- ======================================================================

	log ""
	log "Done!  " & (savedCount as text) & " saved, " & (existedCount as text) & " already existed, " & (skippedCount as text) & " skipped."
	log "Output: " & outputDir

	-- Show a final dialog with the results
	set summaryMsg to "Scraping complete!" & return & return & ¬
		"Saved:          " & (savedCount as text) & return & ¬
		"Already existed: " & (existedCount as text) & return & ¬
		"Skipped:        " & (skippedCount as text) & return & return & ¬
		"Output: " & outputDir

	if skippedCount > 0 then
		set summaryMsg to summaryMsg & return & return & "Skipped songs have been logged to skipped.log in each book's output folder."
	end if

	display dialog summaryMsg with title scriptName & " — Complete" buttons {"Open Output Folder", "OK"} default button "OK" with icon note

	if button returned of result is "Open Output Folder" then
		-- Open the output folder in Finder
		do shell script "open " & quoted form of outputDir
	end if

	-- Clean up the temporary cookie jar file
	cleanupCookieJar(cookieJar)

on error errMsg number errNum
	-- Global error handler: display the error and clean up temp files
	if errNum is -128 then
		-- User cancelled — not an error, just clean up silently
		log "User cancelled."
	else
		log "ERROR: " & errMsg & " (error " & (errNum as text) & ")"
		try
			display dialog "An error occurred:" & return & return & errMsg & return & return & "(Error " & (errNum as text) & ")" with title scriptName & " — Error" buttons {"OK"} default button "OK" with icon stop
		end try
	end if

	-- Clean up the cookie jar file even on error
	cleanupCookieJar(cookieJar)
end try


-- ==========================================================================
-- HANDLER: processSong — Scrape lyrics and download files for a single song
-- ==========================================================================
-- Orchestrates the full scraping pipeline for one song:
-- 1. Extract the song number from the index title
-- 2. Check if lyrics/downloads already exist (skip if so)
-- 3. Fetch the song detail page
-- 4. Parse the HTML to extract lyrics, copyright, and download links
-- 5. Save the lyrics as a .txt file
-- 6. Download and save words/music/audio files (if enabled)
--
-- Parameters:
--   songTitle     — Song title from the index page (includes book code)
--   songURL       — URL of the song detail page
--   songBook      — Book identifier key ("mp", "cp", or "jp")
--   outputDir     — Base output directory path
--   downloadFiles — Boolean: whether to download words/music/audio files
--   requestDelay  — Seconds to wait between requests
--   debugMode     — Boolean: whether to dump HTML for debugging
--   cookieJar     — Path to the temp cookie jar file
--   fileCache     — List of existing filenames for skip-detection
--
-- Returns:
--   "saved"   — Lyrics were newly saved
--   "skipped" — Song couldn't be scraped (logged to skipped.log)
--   "exists"  — Song was already saved from a previous run

on processSong(songTitle, songURL, songBook, outputDir, downloadFiles, requestDelay, debugMode, cookieJar, fileCache)
	-- Get book configuration values
	set bookLabel to getBookLabel(songBook)
	set bookPad to getBookPad(songBook)
	set bookSubdir to getBookSubdir(songBook)
	set bookDir to outputDir & "/" & bookSubdir

	-- Extract the hymn number from the title (e.g. "Amazing Grace (MP0023)" -> 23)
	set songNumber to extractSongNumber(songTitle, songBook)

	if songNumber is 0 then
		-- Title doesn't contain a recognisable book code — can't save this song
		logSkip(bookDir, "??", "????", songTitle, songURL, "no book number found in title")
		return "skipped"
	end if

	-- Zero-pad the number according to the book's configuration
	set paddedNumber to zeroPad(songNumber, bookPad)

	-- Check if lyrics already exist in the file cache
	set lyricsPrefix to paddedNumber & " (" & bookLabel & ") -"
	set lyricsExist to false
	repeat with cachedFile in fileCache
		set cf to cachedFile as text
		if cf starts with lyricsPrefix and cf ends with ".txt" then
			set lyricsExist to true
			exit repeat
		end if
	end repeat

	-- If lyrics exist and we don't need files, skip entirely (no network request)
	if lyricsExist and not downloadFiles then
		return "exists"
	end if

	-- If lyrics exist, check whether downloads also exist
	if lyricsExist then
		-- Derive the base filename for download comparison
		set cleanedTitle to cleanSongTitle(songTitle, songBook)
		set baseFile to buildBaseFilename(songNumber, songBook, cleanedTitle)

		-- Look for any non-txt file with the same base name
		set hasAnyDownload to false
		repeat with cachedFile in fileCache
			set cf to cachedFile as text
			if (cf starts with (baseFile & ".") or cf starts with (baseFile & "_")) and cf does not end with ".txt" then
				set hasAnyDownload to true
				exit repeat
			end if
		end repeat

		if not downloadFiles or hasAnyDownload then
			return "exists"
		end if

		-- Lyrics exist but no downloads — need to fetch page for download links
		log "    " & bookLabel & paddedNumber & "  " & (text 1 thru (min(55, count of songTitle)) of songTitle) & "  >> fetching missing downloads..."
	else
		-- Neither lyrics nor files exist — full scrape needed
		log "    " & bookLabel & paddedNumber & "  " & (text 1 thru (min(55, count of songTitle)) of songTitle)
	end if

	-- Make the song URL absolute if it starts with /
	set fullSongURL to songURL
	if songURL starts with "/" then
		set fullSongURL to baseURL & songURL
	else if songURL does not start with "http" then
		set fullSongURL to baseURL & "/" & songURL
	end if

	-- Fetch the song detail page
	set songHTML to ""
	try
		set songHTML to do shell script "curl -s -L " & ¬
			"-c " & quoted form of cookieJar & " " & ¬
			"-b " & quoted form of cookieJar & " " & ¬
			"-A " & quoted form of userAgent & " " & ¬
			"-H 'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8' " & ¬
			"-H 'Accept-Encoding: identity' " & ¬
			"-H 'Sec-Fetch-Dest: document' " & ¬
			"-H 'Sec-Fetch-Mode: navigate' " & ¬
			"-H 'Sec-Fetch-Site: same-origin' " & ¬
			"--max-time 20 " & ¬
			quoted form of fullSongURL
	on error errMsg
		log "    Error fetching song: " & errMsg
		logSkip(bookDir, bookLabel, paddedNumber, songTitle, fullSongURL, "fetch error: " & errMsg)
		delay requestDelay
		return "skipped"
	end try

	-- Check for login wall (session may have expired during the scrape)
	if songHTML is "" or songHTML contains "Please login to continue" or songHTML contains "loginform" then
		set reason to "empty response"
		if songHTML contains "Please login to continue" or songHTML contains "loginform" then
			set reason to "login wall"
		end if
		log "    FAILED (" & reason & ")"
		logSkip(bookDir, bookLabel, paddedNumber, songTitle, fullSongURL, reason)
		if debugMode and songHTML is not "" then
			writeDebugHTML(bookDir, bookLabel, paddedNumber, songHTML, reason)
		end if
		delay requestDelay
		return "skipped"
	end if

	-- Check for subscription paywall (song is behind a higher-tier subscription)
	-- This happens when the user is logged in but the song is not included
	-- in their subscription plan. The page shows a "not part of your subscription"
	-- message instead of the lyrics.
	set songHTMLLower to toLower(songHTML)
	if songHTMLLower contains "not part of your subscription" then
		log "    FAILED (subscription paywall)"
		logSkip(bookDir, bookLabel, paddedNumber, songTitle, fullSongURL, "subscription paywall")
		if debugMode then
			writeDebugHTML(bookDir, bookLabel, paddedNumber, songHTML, "paywall")
		end if
		delay requestDelay
		return "skipped"
	end if

	-- Check for WAF block on the song page
	if songHTMLLower contains "sucuri" or songHTMLLower contains "access denied" then
		log "    FAILED (blocked by firewall)"
		logSkip(bookDir, bookLabel, paddedNumber, songTitle, fullSongURL, "blocked by WAF/firewall")
		if debugMode then
			writeDebugHTML(bookDir, bookLabel, paddedNumber, songHTML, "waf")
		end if
		delay requestDelay
		return "skipped"
	end if

	if debugMode then
		log "DEBUG: Song page HTML (first 2000 chars):"
		log text 1 thru (min(2000, count of songHTML)) of songHTML
	end if

	-- Parse the song page to extract title, lyrics, copyright, and download links.
	-- We write the HTML to a temp file and process it with a shell script
	-- to handle the complex parsing that AppleScript's string handling can't do well.
	set parsedSong to parseSongPage(songHTML, debugMode)

	set parsedTitle to item 1 of parsedSong
	set parsedLyrics to item 2 of parsedSong
	set parsedCopyright to item 3 of parsedSong
	set parsedDownloads to item 4 of parsedSong

	-- If the parser couldn't find a title, retry once
	if parsedTitle is "" then
		delay (requestDelay * 2)
		try
			set songHTML to do shell script "curl -s -L " & ¬
				"-c " & quoted form of cookieJar & " " & ¬
				"-b " & quoted form of cookieJar & " " & ¬
				"-A " & quoted form of userAgent & " " & ¬
				"-H 'Accept-Encoding: identity' " & ¬
				"--max-time 20 " & ¬
				quoted form of fullSongURL

			if songHTML is not "" and songHTML does not contain "loginform" then
				set parsedSong to parseSongPage(songHTML, debugMode)
				set parsedTitle to item 1 of parsedSong
				set parsedLyrics to item 2 of parsedSong
				set parsedCopyright to item 3 of parsedSong
				set parsedDownloads to item 4 of parsedSong
			end if
		end try
	end if

	-- If still no title after retry, try fallback strategies before giving up
	if parsedTitle is "" then
		-- Fallback 1: Extract from HTML <title> tag and strip site name suffix
		try
			set parsedTitle to do shell script "echo " & quoted form of songHTML & " | sed -n 's/.*<title>\\([^<]*\\)<\\/title>.*/\\1/p' | sed 's/ – Mission Praise$//' | sed 's/^[[:space:]]*//;s/[[:space:]]*$//' | head -1"
		end try

		-- Fallback 2: Use the song title from the index page, strip book code like "(MP1270)"
		if parsedTitle is "" then
			try
				set parsedTitle to do shell script "echo " & quoted form of songTitle & " | sed 's/[[:space:]]*([A-Z]*[0-9]*)[[:space:]]*$//' | sed 's/^[[:space:]]*//;s/[[:space:]]*$//'"
			end try
		end if

		-- Only skip if STILL no title after all fallbacks
		if parsedTitle is "" then
			log "    FAILED (no title parsed)"
			logSkip(bookDir, bookLabel, paddedNumber, songTitle, fullSongURL, "parser found no title in page HTML")
			if debugMode then
				writeDebugHTML(bookDir, bookLabel, paddedNumber, songHTML, "notitle")
			end if
			delay requestDelay
			return "skipped"
		end if
	end if

	-- Verse count validation — count actual text lines (not paragraph entries)
	-- to detect incomplete parses without false positives from multi-line <p> blocks
	set totalLines to 0
	repeat with verseEntry in parsedLyrics
		set vText to item 1 of verseEntry
		if vText is not "" then
			-- Count lines by counting linefeeds + 1
			set lfCount to 0
			repeat with c in (characters of vText)
				if c is (ASCII character 10) then set lfCount to lfCount + 1
			end repeat
			set totalLines to totalLines + lfCount + 1
		end if
	end repeat
	if totalLines is 0 then
		log "    WARNING (no lyrics found)"
		logSkip(bookDir, bookLabel, paddedNumber, songTitle, fullSongURL, "parser found title but 0 lyric lines")
		if debugMode then
			writeDebugHTML(bookDir, bookLabel, paddedNumber, songHTML, "nolyrics")
		end if
	else if totalLines < 4 then
		log "    WARNING (" & totalLines & " lines - may be incomplete)"
	end if

	-- Save lyrics to a .txt file
	if not lyricsExist then
		set cleanedTitle to cleanSongTitle(parsedTitle, songBook)
		set baseFile to buildBaseFilename(songNumber, songBook, cleanedTitle)

		-- Format the lyrics using the same format as the Python scraper
		set formattedLyrics to formatLyrics(cleanedTitle, parsedLyrics, parsedCopyright, songBook)

		-- Write the lyrics file
		set lyricsPath to bookDir & "/" & baseFile & ".txt"
		writeTextFile(lyricsPath, formattedLyrics)

		-- Add to file cache
		set end of fileCache to baseFile & ".txt"

		log "    OK - saved " & baseFile & ".txt"
	else
		-- Re-derive base filename from the parsed title for downloads
		set cleanedTitle to cleanSongTitle(parsedTitle, songBook)
		set baseFile to buildBaseFilename(songNumber, songBook, cleanedTitle)
	end if

	-- Download files (words, music, audio) if enabled
	set fileResults to {}
	if downloadFiles and (count of parsedDownloads) > 0 then
		repeat with dlItem in parsedDownloads
			set dlType to item 1 of dlItem
			set dlURL to item 2 of dlItem

			-- Make download URL absolute if needed
			if dlURL starts with "/" then
				set dlURL to baseURL & dlURL
			end if

			-- Check if this download type already exists in cache
			set dlPrefix to baseFile & "."
			if dlType is not "words" then
				set dlPrefix to baseFile & "_" & dlType & "."
			end if

			set dlExists to false
			repeat with cachedFile in fileCache
				set cf to cachedFile as text
				if cf starts with dlPrefix then
					set dlExists to true
					exit repeat
				end if
			end repeat

			if dlExists then
				-- Already exists — record lowercase indicator
				set end of fileResults to (text 1 of dlType)
			else
				-- Download the file
				set dlResult to downloadFile(dlURL, baseFile, dlType, bookDir, cookieJar, debugMode)
				if dlResult is not "" then
					set end of fileResults to toUpper(text 1 of dlType)
					set end of fileCache to dlResult
				end if

				-- Brief delay between downloads (30% of normal delay)
				delay (requestDelay * 0.3)
			end if
		end repeat
	end if

	-- Print status line with download indicators
	if (count of fileResults) > 0 then
		set fileStr to " [" & joinList(fileResults, ",") & "]"
	else
		set fileStr to ""
	end if

	if not lyricsExist then
		log "    OK" & fileStr
	else
		log "    OK (downloads)" & fileStr
	end if

	-- Rate limit between songs
	delay requestDelay
	return "saved"
end processSong


-- ==========================================================================
-- HANDLER: parseSongPage — Parse a song detail page's HTML
-- ==========================================================================
-- Extracts the title, lyrics, copyright, and download links from a song page.
-- Uses a shell script with sed/grep/awk for the heavy HTML parsing because
-- AppleScript's native string handling is too limited for complex HTML.
--
-- Parameters:
--   htmlContent — The full HTML of the song detail page
--   debugMode   — Boolean: whether to log debug info
--
-- Returns:
--   A list: {title, lyrics, copyright, downloads}
--   Where downloads is a list of {type, url} pairs

on parseSongPage(htmlContent, debugMode)
	-- Write HTML to a temp file for shell processing
	set tmpHTML to do shell script "mktemp /tmp/mp_song.XXXXXX"

	try
		-- Write the HTML content to the temp file using a shell heredoc approach
		-- We pipe the content via stdin to avoid shell escaping issues with the HTML
		do shell script "cat > " & quoted form of tmpHTML & " << 'EOFHTMLINPUT'\n" & htmlContent & "\nEOFHHTMLINPUT" without altering line endings
	on error
		-- If heredoc fails (e.g., HTML contains the delimiter), try write via Python
		try
			-- Use base64 encoding to safely pass the HTML to the file
			-- First encode to base64, then decode to file
			set b64Content to do shell script "echo " & quoted form of htmlContent & " | base64"
			do shell script "echo " & quoted form of b64Content & " | base64 -d > " & quoted form of tmpHTML
		on error
			-- Last resort: write chunks
			writeTextFile(tmpHTML, htmlContent)
		end try
	end try

	-- Extract title from .entry-title element
	set songTitle to ""
	try
		set songTitle to do shell script "sed -n 's/.*class=\"[^\"]*entry-title[^\"]*\"[^>]*>\\([^<]*\\)<.*/\\1/p' " & quoted form of tmpHTML & " | head -1 | sed 's/^[[:space:]]*//;s/[[:space:]]*$//'"
	end try
	-- Fallback: try with single quotes in class attribute
	if songTitle is "" then
		try
			set songTitle to do shell script "grep -oP '(?<=class=\"[^\"]*entry-title[^\"]*\">)[^<]+' " & quoted form of tmpHTML & " 2>/dev/null | head -1 | sed 's/^[[:space:]]*//;s/[[:space:]]*$//'"
		end try
	end if
	-- Fallback: try broader pattern with <h1> or <h2> containing entry-title
	if songTitle is "" then
		try
			set songTitle to do shell script "grep -i 'entry-title' " & quoted form of tmpHTML & " | sed 's/<[^>]*>//g' | sed 's/^[[:space:]]*//;s/[[:space:]]*$//' | head -1"
		end try
	end if

	-- Decode HTML entities in the title
	if songTitle is not "" then
		set songTitle to decodeHTMLEntities(songTitle)
	end if

	-- Extract lyrics from .song-details section.
	-- The lyrics are in <p> elements inside a div with class "song-details".
	-- We extract the content between the song-details opening tag and its closing tag,
	-- then process each <p> element to extract text and detect chorus (italic) lines.
	--
	-- IMPORTANT: Chorus detection must handle TWO patterns of italic markup:
	--   Pattern A (inner): <p><em>chorus text</em></p>  — <em>/<i> is INSIDE the <p>
	--   Pattern B (outer): <em><p>chorus text</p></em>   — <p> is INSIDE an outer <em>/<i>
	-- The awk pre-processor below detects Pattern B by tracking whether there is an
	-- unclosed <em>/<i> tag before each <p>. If so, it injects __EM_START__ / __EM_END__
	-- markers around the <p> content so the downstream processing treats it identically
	-- to Pattern A.
	set songLyrics to ""
	try
		-- Use a comprehensive awk script to extract and format lyrics.
		-- The awk script does two things:
		--   1. Extracts content between song-details div tags
		--   2. Detects outer <em>/<i> wrapping <p> elements (Pattern B) by counting
		--      opens vs closes of <em>/<i> tags. When a <p> appears while an <em>/<i>
		--      is open, it injects __EM_START__ after the <p> tag and __EM_END__ before
		--      the </p> tag so downstream sed/grep treats it as italic.
		set songLyrics to do shell script "awk '
		BEGIN { inside=0; buf=\"\" }
		/class=\"[^\"]*song-details/ { inside=1; next }
		inside && (/<\\/div>/ || /<\\/section>/) {
			# Check if this is the closing div/section for song-details
			# by tracking depth (simplified: first </div> or </section> after entry)
			inside=0
			print buf
			next
		}
		inside {
			# Accumulate lines
			buf = buf $0 \"\\n\"
		}
		' " & quoted form of tmpHTML & " | \
		awk '
		# Pre-processor: detect outer <em>/<i> wrapping <p> elements.
		# Track the nesting depth of <em> and <i> tags. When a <p> tag
		# appears while em_depth > 0, inject italic markers so the
		# downstream pipeline recognises it as chorus/italic text.
		BEGIN { em_depth = 0 }
		{
			line = $0
			# Count opening <em> and <i> tags (case-insensitive via tolower)
			tmp = tolower(line)
			# Count <em> opens (but not </em>)
			n = gsub(/<em[> ]/, \"&\", tmp)
			c = gsub(/<\\/em>/, \"&\", tmp)
			# Re-scan for <i> opens (but not </i>, and not <img, <input, etc.)
			tmp2 = tolower(line)
			n2 = gsub(/<i[> ]/, \"&\", tmp2)
			c2 = gsub(/<\\/i>/, \"&\", tmp2)
			em_depth = em_depth + n - c + n2 - c2
			if (em_depth < 0) em_depth = 0

			# If we are inside an outer <em>/<i> and this line contains <p>,
			# inject __EM_START__ after each <p...> and __EM_END__ before </p>
			if (em_depth > 0) {
				gsub(/<p[^>]*>/, \"&\\n__EM_START__\\n\", line)
				gsub(/<\\/p>/, \"\\n__EM_END__\\n&\", line)
			}
			print line
		}
		' | \
		sed -E 's/(<br[[:space:]]*\\/?>[[:space:]]*)+/\\n/gi' | \
		sed 's/<p[^>]*>/\\n<\\/p>\\n&\\n/gi' | \
		sed 's/<\\/p>/\\n__PARA_BREAK__\\n/gi' | \
		sed 's/<p[^>]*>/\\n__PARA_START__\\n/gi' | \
		sed 's/<em>/\\n__EM_START__\\n/gi; s/<\\/em>/\\n__EM_END__\\n/gi' | \
		sed 's/<i>/\\n__EM_START__\\n/gi; s/<\\/i>/\\n__EM_END__\\n/gi' | \
		sed 's/<[^>]*>//g' | \
		sed \"s/&#145;/$(printf '\\xe2\\x80\\x98')/g; s/&#146;/$(printf '\\xe2\\x80\\x99')/g; s/&#147;/$(printf '\\xe2\\x80\\x9c')/g; s/&#148;/$(printf '\\xe2\\x80\\x9d')/g; s/&#150;/$(printf '\\xe2\\x80\\x93')/g; s/&#151;/$(printf '\\xe2\\x80\\x94')/g\" | \
		sed 's/&amp;/\\&/g; s/&lt;/</g; s/&gt;/>/g; s/&quot;/\"/g; s/&#8217;/'\"'\"'/g; s/&#8216;/'\"'\"'/g; s/&#8220;/\"/g; s/&#8221;/\"/g; s/&rsquo;/'\"'\"'/g; s/&lsquo;/'\"'\"'/g; s/&rdquo;/\"/g; s/&ldquo;/\"/g; s/&mdash;/—/g; s/&ndash;/–/g; s/&nbsp;/ /g; s/&#160;/ /g' | \
		sed 's/^[[:space:]]*//;s/[[:space:]]*$//' | \
		grep -v '^$'"
	end try

	-- If the awk approach didn't work, try a simpler grep-based extraction
	if songLyrics is "" then
		try
			-- Simpler approach: find all text within song-details class.
			-- Also includes the outer <em>/<i> detection pre-processor (Pattern B)
			-- to ensure chorus detection works regardless of which extraction path runs.
			set songLyrics to do shell script "sed -n '/song-details/,/<\\/div>\\|<\\/section>/p' " & quoted form of tmpHTML & " | \
			awk '
			BEGIN { em_depth = 0 }
			{
				line = $0
				tmp = tolower(line)
				n = gsub(/<em[> ]/, \"&\", tmp)
				c = gsub(/<\\/em>/, \"&\", tmp)
				tmp2 = tolower(line)
				n2 = gsub(/<i[> ]/, \"&\", tmp2)
				c2 = gsub(/<\\/i>/, \"&\", tmp2)
				em_depth = em_depth + n - c + n2 - c2
				if (em_depth < 0) em_depth = 0
				if (em_depth > 0) {
					gsub(/<p[^>]*>/, \"&\\n__EM_START__\\n\", line)
					gsub(/<\\/p>/, \"\\n__EM_END__\\n&\", line)
				}
				print line
			}
			' | \
			sed -E 's/(<br[[:space:]]*\\/?>[[:space:]]*)+/\\n/gi' | \
			sed 's/<p[^>]*>/\\n<\\/p>\\n&\\n/gi' | \
			sed 's/<\\/p>/\\n__PARA_BREAK__\\n/gi' | \
			sed 's/<p[^>]*>/__PARA_START__/gi' | \
			sed 's/<em>/__EM_START__/gi; s/<\\/em>/__EM_END__/gi' | \
			sed 's/<i>/__EM_START__/gi; s/<\\/i>/__EM_END__/gi' | \
			sed 's/<[^>]*>//g' | \
			sed \"s/&#145;/$(printf '\\xe2\\x80\\x98')/g; s/&#146;/$(printf '\\xe2\\x80\\x99')/g; s/&#147;/$(printf '\\xe2\\x80\\x9c')/g; s/&#148;/$(printf '\\xe2\\x80\\x9d')/g; s/&#150;/$(printf '\\xe2\\x80\\x93')/g; s/&#151;/$(printf '\\xe2\\x80\\x94')/g\" | \
			sed 's/&amp;/\\&/g; s/&lt;/</g; s/&gt;/>/g; s/&quot;/\"/g; s/&#8217;/'\"'\"'/g; s/&rsquo;/'\"'\"'/g; s/&mdash;/—/g; s/&ndash;/–/g; s/&nbsp;/ /g' | \
			sed 's/^[[:space:]]*//;s/[[:space:]]*$//' | \
			grep -v '^$'"
		end try
	end if

	-- Extract copyright from .copyright-info element
	set songCopyright to ""
	try
		set songCopyright to do shell script "sed -n '/copyright-info/,/<\\/div>/p' " & quoted form of tmpHTML & " | sed 's/<[^>]*>//g' | sed 's/^[[:space:]]*//;s/[[:space:]]*$//' | grep -v '^$' | tr '\\n' ' ' | sed 's/^[[:space:]]*//;s/[[:space:]]*$//'"
	end try
	if songCopyright is "" then
		try
			set songCopyright to do shell script "grep -i 'copyright-info' " & quoted form of tmpHTML & " | sed 's/<[^>]*>//g' | sed 's/^[[:space:]]*//;s/[[:space:]]*$//' | head -1"
		end try
	end if
	-- Decode HTML entities in copyright
	if songCopyright is not "" then
		set songCopyright to decodeHTMLEntities(songCopyright)
	end if

	-- Extract download links from .col-sm-4 sidebar or .files section.
	-- Links in this section with text containing "words", "music", or "audio"
	-- are captured as download URLs.
	set downloadLinks to {}
	try
		-- Extract all <a> tags from the col-sm-4 or files section
		set dlSection to do shell script "sed -n '/col-sm-4\\|class=\"[^\"]*files/,/<\\/div>/p' " & quoted form of tmpHTML

		-- Find all href values and their link text
		set dlAnchors to do shell script "echo " & quoted form of dlSection & " | grep -ioE '<a[^>]+href=\"[^\"]+\"[^>]*>[^<]*</a>' | while read -r line; do
			href=$(echo \"$line\" | sed 's/.*href=\"\\([^\"]*\\)\".*/\\1/')
			text=$(echo \"$line\" | sed 's/<[^>]*>//g' | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')
			echo \"$href|$text\"
		done"

		if dlAnchors is not "" then
			set dlLines to splitString(dlAnchors, linefeed)
			repeat with dlLine in dlLines
				set dlText to dlLine as text
				if dlText contains "|" then
					set dlParts to splitString(dlText, "|")
					if (count of dlParts) >= 2 then
						set dlHref to item 1 of dlParts
						set dlLabel to toLower(item 2 of dlParts)

						-- Skip placeholder links
						if dlHref is not "#" and dlHref is not "" then
							if dlLabel contains "words" then
								set end of downloadLinks to {"words", dlHref}
							else if dlLabel contains "music" then
								set end of downloadLinks to {"music", dlHref}
							else if dlLabel contains "audio" then
								set end of downloadLinks to {"audio", dlHref}
							end if
						end if
					end if
				end if
			end repeat
		end if
	end try

	-- Clean up temp file
	try
		do shell script "rm -f " & quoted form of tmpHTML
	end try

	if debugMode then
		log "DEBUG: Parsed title: " & songTitle
		log "DEBUG: Parsed lyrics length: " & ((count of songLyrics) as text)
		log "DEBUG: Parsed copyright: " & songCopyright
		log "DEBUG: Download links found: " & ((count of downloadLinks) as text)
	end if

	return {songTitle, songLyrics, songCopyright, downloadLinks}
end parseSongPage


-- ==========================================================================
-- HANDLER: formatLyrics — Format parsed lyrics into clean plain text
-- ==========================================================================
-- Converts the raw parsed lyrics (with __PARA_BREAK__, __EM_START__, etc.
-- markers) into a clean plain-text format matching the Python scraper output.
--
-- Output format:
--   "Song Title"
--   (blank line)
--   First verse line
--   Second verse line
--   (blank line)
--   Chorus:
--   Chorus line one
--   Chorus line two
--   (blank line)
--   ...
--   (double blank line)
--   Words: Author Name
--   (blank line)
--   Copyright notice
--
-- Parameters:
--   cleanTitle     — The cleaned song title (book code removed)
--   rawLyrics      — Raw lyrics string with paragraph/italic markers
--   copyrightText  — Copyright notice string
--   songBook       — Book identifier key
--
-- Returns:
--   Formatted plain-text string ready to save

on formatLyrics(cleanTitle, rawLyrics, copyrightText, songBook)
	-- Start with quoted title and blank line
	set outputLines to {"\"" & cleanTitle & "\"", ""}

	-- Split the raw lyrics into lines for processing
	set lyricLines to splitString(rawLyrics, linefeed)

	-- State tracking for stanza grouping and chorus detection
	set stanzaBuf to {}
	set stanzaIsChorus to false
	set authorLines to {}
	set foundAttribution to false
	set inItalic to false

	-- Smart stanza break detection: track consecutive empty paragraphs.
	--
	-- BACKGROUND — WHY SINGLE EMPTIES ARE NOT STANZA BREAKS:
	-- On missionpraise.com, every lyric line is wrapped in its own <p> tag,
	-- and the site uses CSS margin/padding on <p> elements for visual spacing.
	-- This means there is a single empty <p> between EVERY line — these are
	-- purely cosmetic spacers inserted by WordPress for CSS styling purposes,
	-- NOT structural stanza boundaries.
	--
	-- BUG (pre-fix): The old code flushed the stanza buffer on every
	-- __PARA_BREAK__ marker, which treated every single empty <p> as a
	-- stanza break. This produced double-spaced output — a blank line after
	-- every single lyric line — which is incorrect.
	--
	-- FIX: We count consecutive empties and only treat them as a real stanza
	-- break when structural signals are present:
	--   1. Two or more consecutive empties (a true structural gap in the HTML)
	--   2. An italic state change (verse -> chorus or chorus -> verse transition)
	--   3. A verse number at the start of the next non-empty line (e.g. "2 Amazing...")
	-- A single empty between lines of the same stanza type is just CSS spacing
	-- and is silently absorbed.
	set consecutiveEmpties to 0

	-- Process each line from the parsed lyrics
	repeat with lyricLine in lyricLines
		set lineText to lyricLine as text

		-- Handle paragraph markers (potential stanza breaks).
		-- IMPORTANT: We do NOT flush the stanza buffer here. Instead we
		-- increment the consecutiveEmpties counter. The actual decision to
		-- flush is deferred to when we encounter the next non-empty content
		-- line, at which point we can inspect structural signals to determine
		-- whether a genuine stanza boundary exists (see smart detection below).
		if lineText is "__PARA_BREAK__" then
			set consecutiveEmpties to consecutiveEmpties + 1

		else if lineText is "__PARA_START__" then
			-- Start of a new paragraph — handled implicitly

		else if lineText is "__EM_START__" then
			-- Entering italic (chorus) text
			set inItalic to true
			set stanzaIsChorus to true

		else if lineText is "__EM_END__" then
			-- Exiting italic text
			set inItalic to false

		else
			-- Regular text line — strip whitespace
			set strippedLine to trimString(lineText)

			if strippedLine is not "" then
				-- ============================================================
				-- SMART STANZA BREAK DETECTION
				-- ============================================================
				-- We have a non-empty line and there are pending empties from
				-- __PARA_BREAK__ markers. Decide whether the gap represents a
				-- real stanza boundary or just CSS inter-line spacing.
				--
				-- A single empty <p> is just CSS spacing between lines within
				-- the same stanza — we skip it silently (no flush).
				--
				-- We flush (start a new stanza) when ANY of these are true:
				--   (a) consecutiveEmpties >= 2 — a structural HTML gap that
				--       goes beyond normal inter-line CSS spacing
				--   (b) Italic state changed — the current line's italic state
				--       differs from the stanza's chorus flag, indicating a
				--       verse-to-chorus or chorus-to-verse transition
				--   (c) Line starts with a digit followed by a space — this
				--       is a numbered verse marker (e.g. "2 O worship the King")
				--       indicating the start of a new numbered verse
				-- ============================================================
				if consecutiveEmpties > 0 and (count of stanzaBuf) > 0 then
					set shouldFlush to false

					-- (a) Multiple consecutive empties = structural break
					-- Two or more empty <p> elements in a row is a deliberate
					-- gap in the HTML, not just inter-line CSS spacing.
					if consecutiveEmpties >= 2 then
						set shouldFlush to true
					end if

					-- (b) Italic state change = verse/chorus transition
					-- If the current line is italic but stanza is not chorus,
					-- or the current line is non-italic but stanza is chorus,
					-- we have a transition that warrants a new stanza.
					if not shouldFlush then
						if inItalic is not equal to stanzaIsChorus then
							set shouldFlush to true
						end if
					end if

					-- (c) Line starts with a digit followed by a space = numbered verse
					-- This detects patterns like "2 Amazing grace" or "10 Praise the Lord"
					-- indicating the beginning of a new numbered verse/stanza.
					if not shouldFlush then
						set startsWithVerseNumber to false
						if (count of strippedLine) >= 2 then
							set firstChar to character 1 of strippedLine
							if firstChar is in {"0", "1", "2", "3", "4", "5", "6", "7", "8", "9"} then
								-- Use grep to confirm the pattern is "digits followed by space"
								-- (not just a line that happens to start with a number)
								try
									set digitCheck to do shell script "echo " & quoted form of strippedLine & " | grep -c '^[[:digit:]][[:digit:]]* '"
									if digitCheck is not "0" then set startsWithVerseNumber to true
								end try
							end if
						end if
						if startsWithVerseNumber then
							set shouldFlush to true
						end if
					end if

					-- Flush the current stanza if a break signal was detected
					if shouldFlush then
						if stanzaIsChorus then
							-- Guard: Do NOT add "Chorus:" if the stanza's first line starts
							-- with a verse number (digit followed by space). Some websites
							-- wrap verse-numbered stanzas in <em> for styling, which would
							-- cause a false-positive chorus detection.
							set firstLine to first item of stanzaBuf
							set startsWithDigit to 0
							try
								set startsWithDigit to (do shell script "echo " & quoted form of firstLine & " | grep -c '^[[:digit:]][[:digit:]]* '") as integer
							end try
							if startsWithDigit is 0 then
								set end of outputLines to "Chorus:"
							end if
						end if
						set end of outputLines to joinList(stanzaBuf, linefeed)
						set end of outputLines to ""
						set stanzaBuf to {}
						set stanzaIsChorus to false
					end if
				end if

				-- Reset the consecutive empties counter now that we have content
				set consecutiveEmpties to 0
				-- Check for attribution lines (Words:, Music:, etc.)
				-- Require a colon, separator, or "by" after the keyword to prevent
				-- false positives (e.g. sidebar text "Music file" or lyrics like "Words of...").
				set isAttribution to false
				set isExplicitAttribution to 0
				try
					set isExplicitAttribution to (do shell script "echo " & quoted form of strippedLine & " | grep -c -i '^\\(Words\\|Music\\|Arranged\\|Words and music\\|Based on\\|Translated\\|Paraphrase\\)[[:space:]]*[:&\\-/by]'") as integer
				end try
				if isExplicitAttribution is 0 then
					try
						set isExplicitAttribution to (do shell script "echo " & quoted form of strippedLine & " | grep -c -i '^\\(Words and music\\|Words & music\\)'") as integer
					end try
				end if
				if isExplicitAttribution > 0 then
					set isAttribution to true
				else if foundAttribution and strippedLine does not contain (linefeed) then
					-- Only cascade if line doesn't look like a numbered verse and is short
					set startsWithVerseNum to 0
					try
						set startsWithVerseNum to (do shell script "echo " & quoted form of strippedLine & " | grep -c '^[[:digit:]][[:digit:]]* '") as integer
					end try
					if startsWithVerseNum is 0 and (count of strippedLine) < 120 then
						set isAttribution to true
					end if
				end if

				if isAttribution then
					-- Flush any pending stanza
					if (count of stanzaBuf) > 0 then
						if stanzaIsChorus then
							-- Guard: skip Chorus label if first line starts with a verse number
							set firstLine to first item of stanzaBuf
							set startsWithDigit to 0
							try
								set startsWithDigit to (do shell script "echo " & quoted form of firstLine & " | grep -c '^[[:digit:]][[:digit:]]* '") as integer
							end try
							if startsWithDigit is 0 then
								set end of outputLines to "Chorus:"
							end if
						end if
						set end of outputLines to joinList(stanzaBuf, linefeed)
						set end of outputLines to ""
						set stanzaBuf to {}
						set stanzaIsChorus to false
					end if
					set foundAttribution to true
					set end of authorLines to strippedLine

				else
					-- If we see a numbered verse after attribution, reset the cascade
					-- to prevent a single false positive from consuming remaining lyrics.
					if foundAttribution then
						set startsWithVerseNum2 to 0
						try
							set startsWithVerseNum2 to (do shell script "echo " & quoted form of strippedLine & " | grep -c '^[[:digit:]][[:digit:]]* '") as integer
						end try
						if startsWithVerseNum2 > 0 then
							set foundAttribution to false
						end if
					end if

					if strippedLine does not start with (ASCII character 10) and ¬
						(strippedLine contains "/" or strippedLine contains "&") and ¬
						(count of strippedLine) < 120 then
						-- Standalone author line (e.g. "Stuart Townend / Keith Getty")
						-- Check it doesn't start with a digit and doesn't contain common lyric words
						set firstChar to text 1 of strippedLine
						set hasLyricWords to 0
						try
							set hasLyricWords to (do shell script "echo " & quoted form of strippedLine & " | grep -c -i '\\b\\(the\\|and\\|you\\|your\\|my\\|our\\|lord\\|god\\|love\\|sing\\|praise\\)\\b'") as integer
						end try
						if firstChar is not in {"0", "1", "2", "3", "4", "5", "6", "7", "8", "9"} and hasLyricWords is 0 then
							-- Flush stanza
							if (count of stanzaBuf) > 0 then
								if stanzaIsChorus then
									-- Guard: skip Chorus label if first line starts with a verse number
									set firstLine to first item of stanzaBuf
									set startsWithDigit to 0
									try
										set startsWithDigit to (do shell script "echo " & quoted form of firstLine & " | grep -c '^[[:digit:]][[:digit:]]* '") as integer
									end try
									if startsWithDigit is 0 then
										set end of outputLines to "Chorus:"
									end if
								end if
								set end of outputLines to joinList(stanzaBuf, linefeed)
								set end of outputLines to ""
								set stanzaBuf to {}
								set stanzaIsChorus to false
							end if
							set end of authorLines to strippedLine
						else
							-- Starts with digit or contains lyric words — treat as lyric
							if inItalic then set stanzaIsChorus to true
							set end of stanzaBuf to strippedLine
						end if
					else
						-- Regular lyric line — add to current stanza buffer
						if inItalic then set stanzaIsChorus to true
						set end of stanzaBuf to strippedLine
					end if
				end if
			end if
		end if
	end repeat

	-- Flush any remaining stanza
	if (count of stanzaBuf) > 0 then
		if stanzaIsChorus then
			-- Guard: skip Chorus label if first line starts with a verse number.
			-- Prevents false positives from verse-numbered stanzas wrapped in <em>
			-- for styling on the website (e.g. "2 Lord, I come to You...").
			set firstLine to first item of stanzaBuf
			set startsWithDigit to 0
			try
				set startsWithDigit to (do shell script "echo " & quoted form of firstLine & " | grep -c '^[[:digit:]][[:digit:]]* '") as integer
			end try
			if startsWithDigit is 0 then
				set end of outputLines to "Chorus:"
			end if
		end if
		set end of outputLines to joinList(stanzaBuf, linefeed)
		set end of outputLines to ""
	end if

	-- Remove trailing blank lines from the lyrics section
	repeat while (count of outputLines) > 0 and item -1 of outputLines is ""
		set outputLines to items 1 thru -2 of outputLines
	end repeat

	-- Append author attribution lines (separated from lyrics by double blank line)
	if (count of authorLines) > 0 then
		set end of outputLines to ""
		set end of outputLines to ""
		repeat with al in authorLines
			set end of outputLines to al as text
		end repeat
	end if

	-- Append copyright notice if available
	if copyrightText is not "" then
		set end of outputLines to ""
		set end of outputLines to copyrightText
	end if

	-- Join all output lines with newlines
	set formattedResult to joinList(outputLines, linefeed)

	-- Post-processing: normalise spaced ellipses
	-- WordPress sometimes renders "..." as ". . ." with spaces between dots
	try
		set formattedResult to do shell script "echo " & quoted form of formattedResult & " | sed 's/ *\\(\\. \\)\\{2,\\}\\./\\.\\.\\./'g"
	end try

	-- Strip legacy formatting codes f*I (italic start) and f*R (format reset)
	-- that appear as data-entry artefacts in some song texts
	try
		set formattedResult to do shell script "echo " & quoted form of formattedResult & " | sed 's/f\\*I//g; s/f\\*R//g'"
	end try

	-- Collapse 3+ consecutive newlines down to 2 (one blank line maximum)
	try
		set formattedResult to do shell script "echo " & quoted form of formattedResult & " | perl -0pe 's/\\n{3,}/\\n\\n/g'"
	end try

	return formattedResult
end formatLyrics


-- ==========================================================================
-- HANDLER: downloadFile — Download a binary file (words/music/audio)
-- ==========================================================================
-- Downloads a file via curl with the authenticated session, detects the
-- file type from Content-Type header, URL extension, or magic bytes,
-- and saves it with the correct extension and naming convention.
--
-- Parameters:
--   dlURL      — Download URL
--   baseFile   — Base filename (from buildBaseFilename)
--   dlType     — Download type ("words", "music", or "audio")
--   bookDir    — Directory to save the file in
--   cookieJar  — Path to the temp cookie jar file
--   debugMode  — Boolean: whether to log debug info
--
-- Returns:
--   The saved filename (for adding to file cache), or "" on failure

on downloadFile(dlURL, baseFile, dlType, bookDir, cookieJar, debugMode)
	-- Create a temp file for the download
	set tmpFile to do shell script "mktemp /tmp/mp_dl.XXXXXX"

	try
		-- Download with curl, capturing Content-Type and HTTP code
		-- -w outputs metadata after the file data
		-- -o writes the file to our temp path
		set dlMeta to do shell script "curl -s -L " & ¬
			"-o " & quoted form of tmpFile & " " & ¬
			"-w '%{content_type}\\n%{http_code}\\n%{url_effective}' " & ¬
			"-c " & quoted form of cookieJar & " " & ¬
			"-b " & quoted form of cookieJar & " " & ¬
			"-A " & quoted form of userAgent & " " & ¬
			"-H 'Accept: */*' " & ¬
			"-H 'Sec-Fetch-Dest: document' " & ¬
			"-H 'Sec-Fetch-Mode: navigate' " & ¬
			"--max-time 30 " & ¬
			quoted form of dlURL

		-- Parse the metadata output (content_type, http_code, url_effective)
		set metaLines to splitString(dlMeta, linefeed)
		set contentType to ""
		set dlHttpCode to ""
		set effectiveURL to ""

		if (count of metaLines) >= 1 then set contentType to item 1 of metaLines
		if (count of metaLines) >= 2 then set dlHttpCode to item 2 of metaLines
		if (count of metaLines) >= 3 then set effectiveURL to item 3 of metaLines

		-- Extract just the MIME type (strip charset and other parameters)
		if contentType contains ";" then
			set contentType to text 1 thru ((offset of ";" in contentType) - 1) of contentType
		end if
		set contentType to trimString(toLower(contentType))

		if debugMode then
			log "DEBUG: Download content-type: " & contentType
			log "DEBUG: Download HTTP code: " & dlHttpCode
		end if

		-- Check for HTTP errors
		if dlHttpCode is not "200" then
			log "    Download failed (HTTP " & dlHttpCode & ")"
			do shell script "rm -f " & quoted form of tmpFile
			return ""
		end if

		-- Check file size and detect error pages masquerading as downloads
		set fileSize to do shell script "stat -f '%z' " & quoted form of tmpFile
		set fileSizeNum to fileSize as integer

		if fileSizeNum is 0 then
			log "    Download failed (empty file)"
			do shell script "rm -f " & quoted form of tmpFile
			return ""
		end if

		-- Error page detection: check if content looks like HTML error page.
		-- Threshold raised to 500KB to catch larger error pages (e.g. styled
		-- server error pages with embedded CSS/JS can exceed 50KB).
		if fileSizeNum < 500000 then
			try
				set firstBytes to do shell script "head -c 100 " & quoted form of tmpFile
				set firstBytesLower to toLower(firstBytes)
				if firstBytes starts with "<" or firstBytes starts with "s" or firstBytes starts with "e" or firstBytes starts with "{" then
					if firstBytesLower contains "exception" or firstBytesLower contains "<html" or firstBytesLower contains "<!doctype" or firstBytesLower contains "error" or firstBytesLower contains "not found" or firstBytesLower contains "string(" or firstBytesLower contains "nosuchkey" or firstBytesLower contains "stacktrace" then
						log "    Download failed (server error page)"
						if debugMode then
							log "DEBUG: Error page content: " & firstBytes
						end if
						do shell script "rm -f " & quoted form of tmpFile
						return ""
					end if
				end if
			end try
		end if

		-- Determine the file extension using a cascade strategy:
		-- 1. MIME type from Content-Type header
		-- 2. File extension from the URL
		-- 3. Magic bytes detection from the file content
		set fileExt to extFromMIME(contentType)

		if fileExt is "" then
			set fileExt to extFromURL(effectiveURL)
		end if

		if fileExt is "" then
			-- Use magic bytes detection via the `file` command
			set fileExt to extFromMagic(tmpFile)
		end if

		-- Fallback to .bin if no extension could be determined
		if fileExt is "" then set fileExt to ".bin"

		-- Build the final filename using the naming convention:
		-- Words: base.ext (no suffix)
		-- Music: base_music.ext
		-- Audio: base_audio.ext
		if dlType is "words" then
			set finalFilename to baseFile & fileExt
		else
			set finalFilename to baseFile & "_" & dlType & fileExt
		end if

		-- Move the temp file to the final destination
		set finalPath to bookDir & "/" & finalFilename
		do shell script "mv " & quoted form of tmpFile & " " & quoted form of finalPath

		return finalFilename

	on error errMsg
		log "    Download error: " & errMsg
		-- Clean up temp file on error
		try
			do shell script "rm -f " & quoted form of tmpFile
		end try
		return ""
	end try
end downloadFile


-- ==========================================================================
-- HANDLER: extractHiddenFields — Extract hidden form fields from HTML
-- ==========================================================================
-- Parses the login page HTML to find all <input type="hidden"> fields
-- and returns them as a URL-encoded query string (name=value&name=value).
-- These are CSRF nonces needed for the login POST request.
--
-- Parameters:
--   htmlText — The HTML content of the login page
--
-- Returns:
--   URL-encoded string of hidden field name=value pairs

on extractHiddenFields(htmlText)
	try
		-- Write HTML to a temp file for grep processing
		set tmpFile to do shell script "mktemp /tmp/mp_hidden.XXXXXX"
		-- Use printf to write the HTML to avoid echo interpretation issues
		do shell script "cat > " & quoted form of tmpFile & " << 'EOFHIDDENINPUT'\n" & htmlText & "\nEOFHIDDENINPUT" without altering line endings

		-- Extract hidden field name/value pairs using grep and sed.
		-- Pattern: <input type="hidden" name="..." value="...">
		-- The name and value attributes can appear in any order.
		set fieldsStr to do shell script "grep -ioE '<input[^>]+type=[\"'\"'\"']hidden[\"'\"'\"'][^>]*>' " & quoted form of tmpFile & " | while read -r line; do
			name=$(echo \"$line\" | grep -oE 'name=[\"'\"'\"'][^\"'\"'\"']+[\"'\"'\"']' | sed 's/name=[\"'\"'\"']//;s/[\"'\"'\"']$//')
			value=$(echo \"$line\" | grep -oE 'value=[\"'\"'\"'][^\"'\"'\"']*[\"'\"'\"']' | sed 's/value=[\"'\"'\"']//;s/[\"'\"'\"']$//')
			if [ -n \"$name\" ]; then
				# URL-encode the value for safe inclusion in POST data
				encoded_value=$(python3 -c \"import urllib.parse; print(urllib.parse.quote('$value', safe=''))\" 2>/dev/null || echo \"$value\")
				echo \"${name}=${encoded_value}\"
			fi
		done | tr '\\n' '&' | sed 's/&$//'"

		-- Clean up temp file
		do shell script "rm -f " & quoted form of tmpFile

		return fieldsStr
	on error errMsg
		log "Warning: Failed to extract hidden fields: " & errMsg
		return ""
	end try
end extractHiddenFields


-- ==========================================================================
-- HANDLER: extractCurlMetadata — Extract a metadata line from curl output
-- ==========================================================================
-- curl's -w option appends metadata to the output. This handler finds
-- a line starting with the given prefix and returns its value.
--
-- Parameters:
--   curlOutput — The full output from curl (including -w metadata)
--   prefix     — The metadata prefix to search for (e.g. "__FINAL_URL__:")
--
-- Returns:
--   The metadata value, or "" if not found

on extractCurlMetadata(curlOutput, prefix)
	set outputLines to splitString(curlOutput, linefeed)
	repeat with outputLine in outputLines
		set lineText to outputLine as text
		if lineText starts with prefix then
			return text ((count of prefix) + 1) thru -1 of lineText
		end if
	end repeat
	return ""
end extractCurlMetadata


-- ==========================================================================
-- HANDLER: extractWPLoginError — Extract WordPress login error message
-- ==========================================================================
-- Looks for the WordPress login error div in the HTML and extracts
-- the error text, stripping HTML tags.
--
-- Parameters:
--   htmlText — The login page HTML
--
-- Returns:
--   The error message text, or "" if none found

on extractWPLoginError(htmlText)
	try
		set errorText to do shell script "echo " & quoted form of htmlText & " | grep -oiE '<div[^>]*id=[\"'\"'\"']login_error[\"'\"'\"'][^>]*>.*?</div>' | sed 's/<[^>]*>//g' | sed 's/^[[:space:]]*//;s/[[:space:]]*$//' | head -1"
		return errorText
	on error
		return ""
	end try
end extractWPLoginError


-- ==========================================================================
-- HANDLER: extractPageCounter — Extract X-Y of Z counter from index page
-- ==========================================================================
-- The index page contains a counter like "1-10 of 1523" that tells us
-- how many songs exist and enables loop detection.
--
-- Parameters:
--   htmlText — The index page HTML
--
-- Returns:
--   A list: {lo, hi, total} where lo and hi are the current range
--   and total is the overall count. Returns {0, 0, 0} if not found.

on extractPageCounter(htmlText)
	try
		set counterLine to do shell script "echo " & quoted form of htmlText & " | grep -oE '[0-9]+-[0-9]+[[:space:]]+of[[:space:]]+[0-9]+' | head -1"
		if counterLine is not "" then
			set counterParts to do shell script "echo " & quoted form of counterLine & " | sed 's/^\\([0-9]*\\)-\\([0-9]*\\)[[:space:]]*of[[:space:]]*\\([0-9]*\\)/\\1 \\2 \\3/'"
			set counterNums to splitString(counterParts, " ")
			if (count of counterNums) >= 3 then
				set lo to (item 1 of counterNums) as integer
				set hi to (item 2 of counterNums) as integer
				set total to (item 3 of counterNums) as integer
				return {lo, hi, total}
			end if
		end if
	end try
	return {0, 0, 0}
end extractPageCounter


-- ==========================================================================
-- HANDLER: extractSongLinks — Parse song links from an index page
-- ==========================================================================
-- Extracts all song links from the index page HTML. Songs appear as
-- <a> tags inside <h2> or <h3> elements with /songs/ URLs.
--
-- Parameters:
--   htmlText — The index page HTML
--
-- Returns:
--   A list of {title, url} pairs

on extractSongLinks(htmlText)
	set songLinks to {}

	try
		-- Write HTML to temp file for processing
		set tmpFile to do shell script "mktemp /tmp/mp_index.XXXXXX"
		try
			do shell script "cat > " & quoted form of tmpFile & " << 'EOFINDEXINPUT'\n" & htmlText & "\nEOFINDEXINPUT" without altering line endings
		on error
			writeTextFile(tmpFile, htmlText)
		end try

		-- Extract song links: find <a> tags with /songs/ in href that are inside headings.
		-- We use a multi-step approach:
		-- 1. Flatten the HTML to single lines per link
		-- 2. Find <a> tags with /songs/ in href
		-- 3. Extract the href and link text
		set linksOutput to do shell script "cat " & quoted form of tmpFile & " | \
		tr '\\n' ' ' | \
		sed 's/<h[23]/\\n<h/g' | \
		grep -i '<h[23]' | \
		grep -ioE '<a[[:space:]]+[^>]*href=[\"'\"'\"'][^\"'\"'\"']*\\/songs\\/[^\"'\"'\"']*[\"'\"'\"'][^>]*>[^<]*<\\/a>' | \
		while read -r line; do
			href=$(echo \"$line\" | sed 's/.*href=[\"'\"'\"']\\([^\"'\"'\"']*\\)[\"'\"'\"'].*/\\1/')
			text=$(echo \"$line\" | sed 's/<[^>]*>//g' | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')
			if [ -n \"$href\" ] && [ -n \"$text\" ]; then
				echo \"$text||$href\"
			fi
		done"

		-- Clean up temp file
		do shell script "rm -f " & quoted form of tmpFile

		-- Parse the output into a list of {title, url} pairs
		if linksOutput is not "" then
			set linkLines to splitString(linksOutput, linefeed)
			repeat with linkLine in linkLines
				set linkText to linkLine as text
				if linkText contains "||" then
					set delimPos to offset of "||" in linkText
					set linkTitle to text 1 thru (delimPos - 1) of linkText
					set linkURL to text (delimPos + 2) thru -1 of linkText
					-- Decode HTML entities in the title
					set linkTitle to decodeHTMLEntities(linkTitle)
					-- Only include actual song links (not navigation, etc.)
					if linkURL contains "/songs/" and linkURL is not "/songs/" and linkURL is not "/songs" then
						set end of songLinks to {linkTitle, linkURL}
					end if
				end if
			end repeat
		end if
	on error errMsg
		log "Warning: Error extracting song links: " & errMsg
	end try

	return songLinks
end extractSongLinks


-- ==========================================================================
-- HANDLER: extractSongNumber — Extract hymn number from title for a book
-- ==========================================================================
-- Song titles include the book code and number in parentheses:
--   "Amazing Grace (MP0023)" -> 23
--
-- Parameters:
--   songTitle — Full song title from the index page
--   songBook  — Book identifier key ("mp", "cp", or "jp")
--
-- Returns:
--   The hymn number as an integer, or 0 if not found

on extractSongNumber(songTitle, songBook)
	set bookPattern to getBookPattern(songBook)
	try
		-- Use grep to extract the number from the book pattern
		set numStr to do shell script "echo " & quoted form of songTitle & " | grep -ioE " & quoted form of bookPattern & " | grep -oE '[0-9]+' | head -1"
		if numStr is not "" then
			return numStr as integer
		end if
	end try
	return 0
end extractSongNumber


-- ==========================================================================
-- HANDLER: cleanSongTitle — Remove the book code suffix from a title
-- ==========================================================================
-- Strips the "(MP0023)" or similar suffix from the end of the title,
-- leaving just the human-readable song name for use in filenames.
--
-- Parameters:
--   songTitle — Full song title (e.g. "Amazing Grace (MP0023)")
--   songBook  — Book identifier key
--
-- Returns:
--   Cleaned title (e.g. "Amazing Grace")

on cleanSongTitle(songTitle, songBook)
	set bookLabel to getBookLabel(songBook)
	-- Remove the book code suffix: strip "(MP0023)" or similar from end
	try
		set cleanedTitle to do shell script "echo " & quoted form of songTitle & " | sed 's/[[:space:]]*(" & bookLabel & "[0-9]*)[[:space:]]*$//' | sed 's/^[[:space:]]*//;s/[[:space:]]*$//'"
		if cleanedTitle is not "" then
			return cleanedTitle
		end if
	end try
	return songTitle
end cleanSongTitle


-- ==========================================================================
-- HANDLER: buildBaseFilename — Construct the base filename for a song
-- ==========================================================================
-- Generates a consistent filename from the song's book, number, and title.
-- The title is sanitized and converted to Title Case.
--
-- Format: "{paddedNumber} ({LABEL}) - {Title Case Title}"
--
-- Parameters:
--   songNumber — The song number (integer)
--   songBook   — Book identifier key
--   cleanTitle — The cleaned song title (book code already removed)
--
-- Returns:
--   The base filename string (without extension)

on buildBaseFilename(songNumber, songBook, cleanTitle)
	set bookLabel to getBookLabel(songBook)
	set bookPad to getBookPad(songBook)
	set paddedNumber to zeroPad(songNumber, bookPad)
	set sanitizedTitle to sanitizeFilename(toTitleCase(cleanTitle))
	return paddedNumber & " (" & bookLabel & ") - " & sanitizedTitle
end buildBaseFilename


-- ==========================================================================
-- HANDLER: logSkip — Record a skipped song in the skipped.log file
-- ==========================================================================
-- Creates a persistent log of songs that couldn't be scraped, with
-- timestamps, identifiers, and reasons.
--
-- Log format:
--   [2026-03-13 14:30:00]  MP0023  Amazing Grace  --  login wall  --  https://...
--
-- Parameters:
--   bookDir   — Directory to write the log file in
--   label     — Book label (e.g. "MP")
--   padded    — Zero-padded song number string
--   songTitle — The song title
--   songURL   — The song URL that was attempted
--   reason    — Human-readable explanation of why it was skipped

on logSkip(bookDir, label, padded, songTitle, songURL, reason)
	try
		-- Ensure the directory exists
		do shell script "mkdir -p " & quoted form of bookDir

		-- Get the current timestamp
		set timestamp to do shell script "date '+%Y-%m-%d %H:%M:%S'"

		-- Append to skipped.log
		set logPath to bookDir & "/skipped.log"
		set logLine to "[" & timestamp & "]  " & label & padded & "  " & songTitle & "  --  " & reason & "  --  " & songURL
		do shell script "echo " & quoted form of logLine & " >> " & quoted form of logPath
	on error errMsg
		log "Warning: Failed to write skip log: " & errMsg
	end try
end logSkip


-- ==========================================================================
-- BOOK CONFIGURATION HANDLERS
-- ==========================================================================
-- These handlers return book-specific configuration values.
-- Equivalent to the Python BOOK_CONFIG dictionary.

-- Get the display label for a book (e.g. "mp" -> "MP")
on getBookLabel(bookKey)
	if bookKey is "mp" then
		return "MP"
	else if bookKey is "cp" then
		return "CP"
	else if bookKey is "jp" then
		return "JP"
	end if
	return "??"
end getBookLabel

-- Get the zero-padding width for a book's numbers (MP=4, CP/JP=3)
on getBookPad(bookKey)
	if bookKey is "mp" then
		return 4
	else if bookKey is "cp" then
		return 3
	else if bookKey is "jp" then
		return 3
	end if
	return 4
end getBookPad

-- Get the regex pattern to match a book code in a title
-- These are POSIX extended regex patterns for use with grep -E
on getBookPattern(bookKey)
	if bookKey is "mp" then
		return "\\(MP[0-9]+\\)"
	else if bookKey is "cp" then
		return "\\(CP[0-9]+\\)"
	else if bookKey is "jp" then
		return "\\(JP[0-9]+\\)"
	end if
	return ""
end getBookPattern

-- Get the human-readable subdirectory name for a book
on getBookSubdir(bookKey)
	if bookKey is "mp" then
		return "Mission Praise [MP]"
	else if bookKey is "cp" then
		return "Carol Praise [CP]"
	else if bookKey is "jp" then
		return "Junior Praise [JP]"
	end if
	return "Unknown"
end getBookSubdir


-- ==========================================================================
-- MIME TYPE HANDLERS
-- ==========================================================================

-- Map a MIME type to a file extension
-- Equivalent to the Python MIME_TO_EXT dictionary
on extFromMIME(mimeType)
	if mimeType is "application/rtf" or mimeType is "text/rtf" then
		return ".rtf"
	else if mimeType is "application/msword" then
		return ".doc"
	else if mimeType is "application/vnd.openxmlformats-officedocument.wordprocessingml.document" then
		return ".docx"
	else if mimeType is "application/pdf" then
		return ".pdf"
	else if mimeType is "audio/midi" or mimeType is "audio/x-midi" then
		return ".mid"
	else if mimeType is "audio/mpeg" or mimeType is "audio/mp3" then
		return ".mp3"
	else if mimeType is "audio/wav" or mimeType is "audio/x-wav" then
		return ".wav"
	else if mimeType is "audio/ogg" then
		return ".ogg"
	else if mimeType is "application/octet-stream" then
		-- Generic binary — fall back to other methods
		return ""
	end if
	return ""
end extFromMIME


-- ==========================================================================
-- HANDLER: extFromURL — Guess file extension from a URL's path
-- ==========================================================================
-- Parses the URL to extract the path, then gets the extension from the
-- last path segment. Server-side script extensions are ignored.
--
-- Parameters:
--   fileURL — The download URL
--
-- Returns:
--   The lowercase file extension (e.g. ".rtf") or "" if none found

on extFromURL(fileURL)
	try
		-- Extract the path from the URL and get the extension
		set fileExt to do shell script "echo " & quoted form of fileURL & " | sed 's/[?#].*//' | grep -oE '\\.[a-zA-Z0-9]+$' | tr 'A-Z' 'a-z'"
		-- Ignore server-side script extensions
		if fileExt is in {".php", ".asp", ".aspx", ".jsp", ".cgi", ".py"} then
			return ""
		end if
		return fileExt
	on error
		return ""
	end try
end extFromURL


-- ==========================================================================
-- HANDLER: extFromMagic — Detect file type from magic bytes
-- ==========================================================================
-- Uses the macOS `file` command to detect the file type from its
-- content (magic bytes), then maps the result to a file extension.
--
-- Parameters:
--   filePath — Path to the file to check
--
-- Returns:
--   The detected file extension or "" if not recognised

on extFromMagic(filePath)
	try
		-- Use the `file` command for magic byte detection
		set fileType to do shell script "file -b --mime-type " & quoted form of filePath
		set fileType to trimString(toLower(fileType))

		-- Map the detected MIME type to an extension
		set ext to extFromMIME(fileType)
		if ext is not "" then return ext

		-- Additional detection via raw magic bytes for common formats
		set magicHex to do shell script "xxd -p -l 4 " & quoted form of filePath

		-- %PDF -> PDF
		if magicHex starts with "25504446" then return ".pdf"
		-- PK (ZIP-based, e.g. docx) -> .docx
		if magicHex starts with "504b0304" then return ".docx"
		-- OLE2 (doc, xls) -> .doc
		if magicHex starts with "d0cf11e0" then return ".doc"
		-- {\\rtf -> RTF
		if magicHex starts with "7b5c7274" then return ".rtf"
		-- MThd -> MIDI
		if magicHex starts with "4d546864" then return ".mid"
		-- ID3 -> MP3
		if magicHex starts with "49443303" or magicHex starts with "49443302" then return ".mp3"
		-- MP3 frame sync
		if magicHex starts with "fffb" or magicHex starts with "fff3" then return ".mp3"
		-- OggS -> Ogg
		if magicHex starts with "4f676753" then return ".ogg"
		-- RIFF -> WAV
		if magicHex starts with "52494646" then return ".wav"
		-- fLaC -> FLAC
		if magicHex starts with "664c6143" then return ".flac"

	on error
		-- file command failed — return empty
	end try
	return ""
end extFromMagic


-- ==========================================================================
-- STRING UTILITY HANDLERS
-- ==========================================================================

-- Split a string into a list using a delimiter
-- Equivalent to Python's str.split()
on splitString(theString, theDelimiter)
	set oldDelimiters to AppleScript's text item delimiters
	set AppleScript's text item delimiters to theDelimiter
	set theItems to text items of theString
	set AppleScript's text item delimiters to oldDelimiters
	return theItems
end splitString

-- Join a list into a string using a delimiter
-- Equivalent to Python's str.join()
on joinList(theList, theDelimiter)
	set oldDelimiters to AppleScript's text item delimiters
	set AppleScript's text item delimiters to theDelimiter
	set theString to theList as text
	set AppleScript's text item delimiters to oldDelimiters
	return theString
end joinList

-- Trim whitespace from both ends of a string
on trimString(theString)
	try
		set trimmed to do shell script "echo " & quoted form of theString & " | sed 's/^[[:space:]]*//;s/[[:space:]]*$//'"
		return trimmed
	on error
		return theString
	end try
end trimString

-- Convert a string to lowercase
-- Uses a shell command for reliable Unicode-aware lowercasing
on toLower(theString)
	try
		return do shell script "echo " & quoted form of theString & " | tr 'A-Z' 'a-z'"
	on error
		return theString
	end try
end toLower

-- Convert a string to uppercase
on toUpper(theString)
	try
		return do shell script "echo " & quoted form of theString & " | tr 'a-z' 'A-Z'"
	on error
		return theString
	end try
end toUpper

-- Convert a string to Title Case.
-- Handles apostrophes correctly (e.g. "don't" -> "Don't", not "Don'T")
-- Includes Unicode curly/smart apostrophes (\u2019 RIGHT SINGLE QUOTATION
-- MARK and \u2018 LEFT SINGLE QUOTATION MARK) because HTML entities like
-- &rsquo; decode to \u2019. Without this, "Eagle\u2019s" would produce "Eagle'S".
-- Equivalent to the Python title_case() function.
on toTitleCase(theString)
	try
		-- Use awk for reliable Title Case with apostrophe handling.
		-- awk splits on whitespace, so words containing any kind of apostrophe
		-- (ASCII ' or Unicode curly \u2019/\u2018) are naturally treated as
		-- single tokens — no special character class needed.
		return do shell script "echo " & quoted form of theString & " | awk '{for(i=1;i<=NF;i++){w=tolower($i);$i=toupper(substr(w,1,1)) substr(w,2)}}1'"
	on error
		-- Fallback: use simple sed-based title case
		try
			return do shell script "echo " & quoted form of theString & " | sed 's/.*/\\L&/' | sed 's/\\b./\\u&/g'"
		on error
			return theString
		end try
	end try
end toTitleCase

-- Remove characters that are invalid in filenames
-- Strips: \ / * ? : " < > |
on sanitizeFilename(theString)
	try
		return do shell script "echo " & quoted form of theString & " | sed 's/[\\\\/\\*\\?:\"<>|]//g' | sed 's/^[[:space:]]*//;s/[[:space:]]*$//'"
	on error
		return theString
	end try
end sanitizeFilename

-- Zero-pad a number to a specified width
-- e.g. zeroPad(23, 4) -> "0023"
on zeroPad(theNumber, theWidth)
	set numStr to theNumber as text
	repeat while (count of numStr) < theWidth
		set numStr to "0" & numStr
	end repeat
	return numStr
end zeroPad

-- Right-pad a string to a minimum width with spaces
-- Used for aligned log output
on rightPad(theString, minWidth)
	set padded to theString
	repeat while (count of padded) < minWidth
		set padded to padded & " "
	end repeat
	return padded
end rightPad

-- URL-encode a string for use in HTTP POST data
-- Encodes special characters to their percent-encoded equivalents
on urlEncode(theString)
	try
		return do shell script "python3 -c \"import urllib.parse; print(urllib.parse.quote('" & theString & "', safe=''))\" 2>/dev/null || echo " & quoted form of theString
	on error
		-- Fallback: use a basic encoding for common special characters
		try
			return do shell script "echo " & quoted form of theString & " | sed 's/%/%25/g;s/ /%20/g;s/!/%21/g;s/#/%23/g;s/\\$/%24/g;s/&/%26/g;s/(/%28/g;s/)/%29/g;s/+/%2B/g;s/=/%3D/g;s/@/%40/g'"
		on error
			return theString
		end try
	end try
end urlEncode

-- Decode common HTML entities in a string
-- Handles both named entities (&amp;) and numeric references (&#8217;)
on decodeHTMLEntities(theString)
	try
		-- Windows-1252 entity mapping: the site uses &#145;-&#151; which are
		-- Windows-1252 code points, NOT Unicode. chr(146) etc. produce control
		-- characters, not the intended typographic glyphs. Map them explicitly
		-- BEFORE the generic entity handling.
		-- Reference: https://en.wikipedia.org/wiki/Windows-1252#Character_set
		return do shell script "echo " & quoted form of theString & " | \
		sed \"s/&#145;/$(printf '\\xe2\\x80\\x98')/g\" | \
		sed \"s/&#146;/$(printf '\\xe2\\x80\\x99')/g\" | \
		sed \"s/&#147;/$(printf '\\xe2\\x80\\x9c')/g\" | \
		sed \"s/&#148;/$(printf '\\xe2\\x80\\x9d')/g\" | \
		sed \"s/&#150;/$(printf '\\xe2\\x80\\x93')/g\" | \
		sed \"s/&#151;/$(printf '\\xe2\\x80\\x94')/g\" | \
		sed 's/&amp;/\\&/g' | \
		sed 's/&lt;/</g' | \
		sed 's/&gt;/>/g' | \
		sed \"s/&quot;/\\\"/g\" | \
		sed \"s/&#8217;/'/g\" | \
		sed \"s/&#8216;/'/g\" | \
		sed 's/&#8220;/\"/g' | \
		sed 's/&#8221;/\"/g' | \
		sed \"s/&rsquo;/'/g\" | \
		sed \"s/&lsquo;/'/g\" | \
		sed 's/&rdquo;/\"/g' | \
		sed 's/&ldquo;/\"/g' | \
		sed 's/&mdash;/—/g' | \
		sed 's/&ndash;/–/g' | \
		sed 's/&nbsp;/ /g' | \
		sed 's/&#160;/ /g'"
	on error
		return theString
	end try
end decodeHTMLEntities

-- Write text content to a file (UTF-8)
-- Creates the file if it doesn't exist, overwrites if it does
on writeTextFile(filePath, textContent)
	try
		-- Use printf for safer writing (handles special characters better than echo)
		do shell script "printf '%s' " & quoted form of textContent & " > " & quoted form of filePath
	on error
		-- Fallback: use a temp file approach
		try
			set tmpWrite to do shell script "mktemp /tmp/mp_write.XXXXXX"
			do shell script "cat > " & quoted form of tmpWrite & " << 'EOFWRITECONTENT'\n" & textContent & "\nEOFWRITECONTENT" without altering line endings
			do shell script "mv " & quoted form of tmpWrite & " " & quoted form of filePath
		on error errMsg
			log "Warning: Failed to write file " & filePath & ": " & errMsg
		end try
	end try
end writeTextFile


-- ==========================================================================
-- HANDLER: writeDebugHTML — Dump raw HTML to a debug file for skip diagnosis
-- ==========================================================================
-- When a song is skipped, this handler writes the raw HTML response to a
-- diagnostic file so the developer can inspect exactly what the server
-- returned. The file is saved alongside the lyrics in the book directory.
--
-- Filename format: _debug_{LABEL}{NUM}_skipped.html
--   e.g. _debug_MP0023_skipped.html
--
-- Parameters:
--   bookDir  — Directory to save the debug file in
--   label    — Book label (e.g. "MP")
--   padded   — Zero-padded song number string
--   htmlContent — The raw HTML response from the server
--   reason   — Short label for the skip reason (used in log only)

on writeDebugHTML(bookDir, label, padded, htmlContent, reason)
	try
		set debugPath to bookDir & "/_debug_" & label & padded & "_skipped.html"
		writeTextFile(debugPath, htmlContent)
		log "    DEBUG: raw HTML saved to _debug_" & label & padded & "_skipped.html (" & reason & ")"
	on error errMsg
		log "    Warning: failed to write debug HTML: " & errMsg
	end try
end writeDebugHTML


-- ==========================================================================
-- HANDLER: cleanupCookieJar — Delete the temp cookie jar file
-- ==========================================================================
-- Called on normal exit and in the error handler to ensure temp files
-- are always cleaned up, even when the script encounters an error.
--
-- Parameters:
--   jarPath — Path to the cookie jar temp file

on cleanupCookieJar(jarPath)
	if jarPath is not "" then
		try
			do shell script "rm -f " & quoted form of jarPath
			log "Cleaned up cookie jar: " & jarPath
		on error
			-- Silently ignore cleanup errors
		end try
	end if
end cleanupCookieJar


-- ==========================================================================
-- HANDLER: min — Return the smaller of two numbers
-- ==========================================================================
on min(a, b)
	if a < b then
		return a
	else
		return b
	end if
end min
