(*
================================================================================
SDAHymnals_SDAHymnal.org.applescript
importers/scrapers/SDAHymnals_SDAHymnal.org.applescript

Hymnal Scraper (AppleScript) -- scrapes both sdahymnal.org (SDAH) and hymnal.xyz (CH).
Copyright 2025-2026 MWBM Partners Ltd.

Overview:
    This AppleScript is the macOS-native equivalent of the Python scraper
    SDAHymnals_SDAHymnal.org.py. It fetches hymn lyrics from two Seventh-day
    Adventist hymnal websites that share the same underlying codebase (identical
    HTML structure and CSS class names). It iterates through hymn numbers
    sequentially, parses the HTML via shell commands (curl, sed, grep, awk) to
    extract the title, section indicators (e.g. "Verse 1", "Chorus"), and
    lyrics text, then saves each hymn as a plain-text file.

    The scraper is designed to be resumable: it scans the output directory for
    existing files on startup and skips hymns that have already been saved.
    A "Force Re-download" option overrides this behaviour, re-downloading and
    overwriting all files in the range (useful when source data has changed).
    It also handles rate limiting, server errors, and auto-detects the end of
    the hymnal when the site redirects to the homepage.

Dependencies:
    None -- uses only native macOS tools:
    - curl (HTTP requests)
    - sed, grep, awk (HTML parsing / text extraction)
    - AppleScript file I/O and shell integration via "do shell script"

    No Python, pip, Homebrew, or third-party software is required.

How to Run:
    Option A (Script Editor):
        1. Open this file in Script Editor.app (in /Applications/Utilities/)
        2. Click the Run (play) button
        3. Follow the interactive dialogs

    Option B (Save as Application):
        1. Open in Script Editor
        2. File > Export... > File Format: Application
        3. Save to Desktop (or anywhere)
        4. Double-click the .app to run from Finder

Output Format:
    Each hymn is saved as a plain-text file with the naming convention:
        {number zero-padded to 3 digits} ({LABEL}) - {Title Case Title}.txt
    e.g.: "001 (SDAH) - Praise To The Lord.txt"

    Files are organised into book-specific subdirectories:
        hymns/Seventh-day Adventist Hymnal [SDAH]/
        hymns/The Church Hymnal [CH]/

    The file content format is:
        "Hymn Title"

        Verse 1
        lyrics line 1
        lyrics line 2

        Chorus
        chorus line 1
        chorus line 2

Site Configuration:
    SDAH: base_url = https://www.sdahymnal.org/Hymn
          home_url = https://www.sdahymnal.org
          label    = SDAH
          subdir   = Seventh-day Adventist Hymnal [SDAH]
          lang     = en  (ISO 639-1: English)

    CH:   base_url = https://www.hymnal.xyz/Hymn
          home_url = https://www.hymnal.xyz
          label    = CH
          subdir   = The Church Hymnal [CH]
          lang     = en  (ISO 639-1: English)
================================================================================
*)

-- =============================================================================
-- GLOBAL PROPERTIES
-- =============================================================================
-- These properties define the default configuration values and site metadata.
-- They are set at the top level so all handlers can access them.
-- =============================================================================

-- Script metadata displayed in the welcome dialog
property scriptName : "SDA Hymnal Scraper"
property scriptVersion : "2.0"
property scriptCopyright : "Copyright 2025-2026 MWBM Partners Ltd."

-- SDAH site configuration
-- sdahymnal.org hosts the Seventh-day Adventist Hymnal (695 hymns)
property sdahBaseURL : "https://www.sdahymnal.org/Hymn"
property sdahHomeURL : "https://www.sdahymnal.org"
property sdahLabel : "SDAH"
property sdahSubdir : "Seventh-day Adventist Hymnal [SDAH]"
property sdahLang : "en" -- ISO 639-1 language code: English

-- CH site configuration
-- hymnal.xyz hosts The Church Hymnal (same HTML structure as SDAH)
property chBaseURL : "https://www.hymnal.xyz/Hymn"
property chHomeURL : "https://www.hymnal.xyz"
property chLabel : "CH"
property chSubdir : "The Church Hymnal [CH]"
property chLang : "en" -- ISO 639-1 language code: English

-- Default scraping parameters
-- These are used when the user clicks "Start with Defaults"
property defaultStartHymn : 1
property defaultEndHymn : 0 -- 0 means auto-detect (no fixed end)
property defaultDelay : 1.0 -- seconds between HTTP requests
property defaultOutputDir : "" -- will be set to ~/Desktop/hymns at runtime

-- Force re-download flag: when true, the scraper will re-download and
-- overwrite existing hymn files instead of skipping them. This is useful
-- when the source data has been updated or a previous download was corrupted.
-- Default is false (preserve existing files for resumability).
property pForceRedownload : false

-- Maximum consecutive failures before assuming end of hymnal.
-- If 10 hymns in a row fail to scrape, we stop rather than continuing
-- to hammer the server with requests for non-existent hymns.
property maxConsecutiveSkips : 10

-- Maximum retry attempts for HTTP 500 server errors.
-- Transient server errors are common; retrying usually succeeds.
property maxRetries : 3

-- HTTP request timeout in seconds
property httpTimeout : 15

-- User-Agent string for HTTP requests (identifies the scraper politely)
property userAgent : "Mozilla/5.0 (compatible; HymnScraper/2.0)"

-- Progress notification interval: show a macOS notification every N hymns
-- to give the user visual feedback without blocking the script with dialogs
property notifyInterval : 10


-- =============================================================================
-- MAIN ENTRY POINT
-- =============================================================================
-- This is the top-level script block that runs when the script is executed.
-- It displays the welcome dialog, handles user choices (Help, Configure, or
-- Start with Defaults), and then launches the scraping process.
-- =============================================================================

-- Set the default output directory to ~/Desktop/hymns using the shell to
-- reliably resolve the home directory path (AppleScript's "path to desktop"
-- returns an alias, but we need a POSIX path for shell commands)
set defaultOutputDir to (do shell script "echo $HOME") & "/Desktop/hymns"

-- -------------------------------------------------------------------------
-- Welcome Dialog Loop
-- -------------------------------------------------------------------------
-- This loop shows the welcome screen and handles the Help button by
-- returning to the welcome screen after displaying help text.
-- The loop exits when the user clicks "Configure & Start" or
-- "Start with Defaults" (or cancels).
-- -------------------------------------------------------------------------
set userChoice to ""
repeat
	-- Display the welcome dialog with three options:
	-- "Help / ?" shows detailed usage information
	-- "Configure & Start" lets the user customise all settings
	-- "Start with Defaults" uses sensible defaults and begins immediately
	set welcomeResult to display dialog scriptName & " v" & scriptVersion & return & return & scriptCopyright & return & return & "This script scrapes hymn lyrics from sdahymnal.org (SDAH) and hymnal.xyz (CH), saving each hymn as a plain-text file." & return & return & "Choose an option:" buttons {"Help / ?", "Configure & Start", "Start with Defaults"} default button "Start with Defaults" with title scriptName with icon note

	set userChoice to button returned of welcomeResult

	if userChoice is "Help / ?" then
		-- Show detailed help information in a scrollable dialog.
		-- This explains what the script does, output format, available options,
		-- and tips for running the script.
		display dialog "HELP - " & scriptName & " v" & scriptVersion & return & return & "WHAT THIS SCRIPT DOES:" & return & "Scrapes hymn lyrics from two SDA hymnal websites:" & return & "  - sdahymnal.org (Seventh-day Adventist Hymnal)" & return & "  - hymnal.xyz (The Church Hymnal)" & return & "Both sites share the same HTML structure." & return & return & "OUTPUT FORMAT:" & return & "Each hymn is saved as a plain-text (.txt) file:" & return & "  001 (SDAH) - Praise To The Lord.txt" & return & return & "Files are organised in subdirectories:" & return & "  <output>/Seventh-day Adventist Hymnal [SDAH]/" & return & "  <output>/The Church Hymnal [CH]/" & return & return & "File contents:" & return & "  \"Hymn Title\"" & return & "  " & return & "  Verse 1" & return & "  First line of lyrics" & return & "  Second line..." & return & "  " & return & "  Chorus" & return & "  Chorus lyrics..." & return & return & "AVAILABLE OPTIONS:" & return & "  - Site: SDAH only, CH only, or Both" & return & "  - Start hymn number (default: 1)" & return & "  - End hymn number (blank = auto-detect)" & return & "  - Output folder (default: ~/Desktop/hymns)" & return & "  - Delay between requests (default: 1.0s)" & return & "  - Force re-download: overwrite existing files" & return & "    (default: No -- existing hymns are skipped)" & return & return & "FEATURES:" & return & "  - Resumable: skips already-saved hymns" & return & "  - Auto-detects end of hymnal" & return & "  - Handles rate limiting (pauses 60s)" & return & "  - Retries on server errors (3 attempts)" & return & "  - Logs skipped hymns to skipped.log" & return & return & "TIPS:" & return & "  - Save as .app (File > Export > Application)" & return & "    to run by double-clicking from Finder" & return & "  - Progress is shown via macOS notifications" & return & "  - Check Script Editor's log for detailed output" buttons {"OK"} default button "OK" with title scriptName & " - Help" with icon note
		-- After dismissing help, the repeat loop brings us back to the welcome dialog
	else
		-- User chose "Configure & Start" or "Start with Defaults" -- exit the loop
		exit repeat
	end if
end repeat

-- -------------------------------------------------------------------------
-- Configuration
-- -------------------------------------------------------------------------
-- Based on the user's choice, either show configuration dialogs or use
-- defaults. All settings are stored in local variables.
-- -------------------------------------------------------------------------

-- These variables hold the final configuration for the scrape run
set selectedSites to {"sdah", "ch"} -- which sites to scrape (list of site keys)
set startHymn to defaultStartHymn -- first hymn number to scrape
set endHymn to defaultEndHymn -- last hymn number (0 = auto-detect)
set outputDir to defaultOutputDir -- base output directory (POSIX path)
set requestDelay to defaultDelay -- seconds between HTTP requests

if userChoice is "Configure & Start" then
	-- =====================================================================
	-- CONFIGURATION DIALOGS
	-- =====================================================================
	-- Each setting is configured via its own dialog, presented sequentially.
	-- The user can cancel at any point, which stops the script.
	-- =====================================================================

	-- -----------------------------------------------------------------
	-- Dialog 1: Site Selection
	-- -----------------------------------------------------------------
	-- Let the user choose which site(s) to scrape. "Both" is the default
	-- because most users will want the complete collection.
	-- -----------------------------------------------------------------
	set siteChoices to {"SDAH (sdahymnal.org)", "CH (hymnal.xyz)", "Both"}
	set siteSelection to choose from list siteChoices with prompt "Select which site(s) to scrape:" with title scriptName & " - Site Selection" default items {"Both"}

	-- choose from list returns false if the user cancels
	if siteSelection is false then
		-- User cancelled -- exit the script gracefully
		return
	end if

	-- Convert the user's selection to our internal site key format
	set siteChoice to item 1 of siteSelection
	if siteChoice is "SDAH (sdahymnal.org)" then
		set selectedSites to {"sdah"}
	else if siteChoice is "CH (hymnal.xyz)" then
		set selectedSites to {"ch"}
	else
		-- "Both" selected
		set selectedSites to {"sdah", "ch"}
	end if

	-- -----------------------------------------------------------------
	-- Dialog 2: Start Hymn Number
	-- -----------------------------------------------------------------
	-- The user can specify a starting hymn number for resuming or testing.
	-- Default is 1 (start from the beginning).
	-- -----------------------------------------------------------------
	set startResult to display dialog "Start hymn number:" default answer "1" buttons {"Cancel", "OK"} default button "OK" with title scriptName & " - Start Hymn"

	-- Parse the start hymn number, defaulting to 1 if the input is invalid
	try
		set startHymn to (text returned of startResult) as integer
		if startHymn < 1 then set startHymn to 1
	on error
		set startHymn to 1
	end try

	-- -----------------------------------------------------------------
	-- Dialog 3: End Hymn Number
	-- -----------------------------------------------------------------
	-- The user can specify an end hymn number, or leave blank to let the
	-- scraper auto-detect the end (by detecting homepage redirects).
	-- -----------------------------------------------------------------
	set endResult to display dialog "End hymn number:" & return & "(Leave blank to auto-detect the end of the hymnal)" default answer "" buttons {"Cancel", "OK"} default button "OK" with title scriptName & " - End Hymn"

	-- Parse the end hymn number; blank/invalid = 0 = auto-detect
	set endText to text returned of endResult
	if endText is "" then
		set endHymn to 0
	else
		try
			set endHymn to endText as integer
			if endHymn < startHymn then set endHymn to 0
		on error
			set endHymn to 0
		end try
	end if

	-- -----------------------------------------------------------------
	-- Dialog 4: Output Directory
	-- -----------------------------------------------------------------
	-- Let the user choose a folder for output files. The default prompt
	-- points to the Desktop. The user can navigate to any folder.
	-- -----------------------------------------------------------------
	try
		set outputFolder to choose folder with prompt "Select the output folder for hymn files:" default location (path to desktop)
		-- Convert the AppleScript alias to a POSIX path for use in shell commands
		set outputDir to POSIX path of outputFolder
		-- Remove trailing slash if present (for consistency)
		if outputDir ends with "/" then
			set outputDir to text 1 thru -2 of outputDir
		end if
	on error
		-- User cancelled the folder picker -- exit gracefully
		return
	end try

	-- -----------------------------------------------------------------
	-- Dialog 5: Request Delay
	-- -----------------------------------------------------------------
	-- The delay between HTTP requests, in seconds. 1.0 is polite;
	-- lower values risk rate limiting; higher values are slower but safer.
	-- -----------------------------------------------------------------
	set delayResult to display dialog "Delay between requests (seconds):" & return & "(Recommended: 1.0 or higher to avoid rate limiting)" default answer "1.0" buttons {"Cancel", "OK"} default button "OK" with title scriptName & " - Request Delay"

	-- Parse the delay value, defaulting to 1.0 if invalid
	try
		set requestDelay to (text returned of delayResult) as real
		if requestDelay < 0.0 then set requestDelay to 1.0
	on error
		set requestDelay to 1.0
	end try

	-- -----------------------------------------------------------------
	-- Dialog 6: Force Re-download
	-- -----------------------------------------------------------------
	-- Ask whether to force re-download existing files. Normally the
	-- scraper skips hymns that already exist on disk (resumability).
	-- Force mode overrides this, re-downloading and overwriting every
	-- hymn in the range. Useful when source data has changed or files
	-- are suspected to be corrupted/incomplete.
	-- -----------------------------------------------------------------
	set forceResult to display dialog "Force re-download existing files?" buttons {"No", "Yes"} default button "No" with title scriptName & " - Force Re-download"

	if button returned of forceResult is "Yes" then
		set pForceRedownload to true
	else
		set pForceRedownload to false
	end if
end if

-- -------------------------------------------------------------------------
-- Confirmation Dialog
-- -------------------------------------------------------------------------
-- Show a summary of all settings before starting, so the user can verify
-- everything is correct. This prevents wasted time from misconfiguration.
-- -------------------------------------------------------------------------

-- Build a human-readable string for the site selection
set siteDisplay to ""
if selectedSites is {"sdah"} then
	set siteDisplay to "SDAH (sdahymnal.org)"
else if selectedSites is {"ch"} then
	set siteDisplay to "CH (hymnal.xyz)"
else
	set siteDisplay to "Both (SDAH + CH)"
end if

-- Build a human-readable string for the end hymn setting
set endDisplay to ""
if endHymn is 0 then
	set endDisplay to "Auto-detect"
else
	set endDisplay to endHymn as text
end if

-- Build a human-readable string for the force re-download setting
set forceDisplay to "No"
if pForceRedownload then
	set forceDisplay to "Yes (overwrite existing)"
end if

-- Display the confirmation dialog with all settings
set confirmResult to display dialog "Ready to start scraping with these settings:" & return & return & "  Site(s):    " & siteDisplay & return & "  Start:      Hymn " & (startHymn as text) & return & "  End:        " & endDisplay & return & "  Output:     " & outputDir & return & "  Delay:      " & (requestDelay as text) & "s" & return & "  Force:      " & forceDisplay & return & return & "Proceed?" buttons {"Cancel", "Start Scraping"} default button "Start Scraping" with title scriptName & " - Confirm" with icon note

if button returned of confirmResult is not "Start Scraping" then
	return
end if


-- =============================================================================
-- SCRAPING ORCHESTRATION
-- =============================================================================
-- Iterate through the selected sites and scrape each one sequentially.
-- This mirrors the Python version's main() function behavior.
-- =============================================================================

-- Track the total number of hymns saved across all sites
set totalSaved to 0

-- Log the start time for the final summary
set startTime to current date

-- Iterate through each selected site and run the scraping loop
repeat with siteKey in selectedSites
	-- Call the main scraping handler for this site.
	-- It returns the number of hymns successfully saved.
	set siteSaved to my scrapeSite(siteKey as text, startHymn, endHymn, outputDir, requestDelay, pForceRedownload)
	set totalSaved to totalSaved + siteSaved
end repeat

-- Calculate elapsed time for the summary
set elapsedSeconds to (current date) - startTime
set elapsedMinutes to (elapsedSeconds div 60)
set elapsedRemaining to (elapsedSeconds mod 60)

-- -------------------------------------------------------------------------
-- Completion Dialog
-- -------------------------------------------------------------------------
-- Show the final summary to the user with totals and elapsed time.
-- -------------------------------------------------------------------------
display dialog "Scraping complete!" & return & return & "Total hymns saved: " & (totalSaved as text) & return & "Output folder: " & outputDir & return & "Elapsed time: " & (elapsedMinutes as text) & "m " & (elapsedRemaining as text) & "s" buttons {"OK"} default button "OK" with title scriptName & " - Complete" with icon note

-- Also log the summary to Script Editor's log pane
log "===== SCRAPING COMPLETE ====="
log "Total hymns saved: " & (totalSaved as text)
log "Output: " & outputDir
log "Elapsed: " & (elapsedMinutes as text) & "m " & (elapsedRemaining as text) & "s"


-- =============================================================================
-- HANDLERS (FUNCTIONS)
-- =============================================================================
-- AppleScript uses "on ... end" blocks for reusable handlers (functions).
-- These are defined below the main script body but are callable from anywhere
-- in the script via "my handlerName()" syntax.
-- =============================================================================


-- =============================================================================
-- Handler: scrapeSite
-- =============================================================================
-- Main scraping loop for a single site (SDAH or CH).
-- Iterates through hymn numbers, fetches each one, parses the HTML, and
-- saves the result as a plain-text file. Includes resumability (skips
-- existing files), retry logic, rate limit handling, and consecutive
-- failure detection.
--
-- Parameters:
--   siteKey       - Site identifier: "sdah" or "ch"
--   pStartHymn    - First hymn number to scrape (inclusive)
--   pEndHymn      - Last hymn number to scrape (inclusive), or 0 for auto-detect
--   pOutputDir    - Base output directory (POSIX path)
--   pDelay        - Seconds to wait between HTTP requests
--   pForceMode    - Boolean: when true, skip the existing-file check and
--                   re-download/overwrite every hymn in the range
--
-- Returns:
--   Integer - number of hymns successfully saved in this run
-- =============================================================================
on scrapeSite(siteKey, pStartHymn, pEndHymn, pOutputDir, pDelay, pForceMode)
	-- Look up the site configuration based on the site key
	-- Each site has a base URL, home URL, label, subdirectory name, and ISO 639-1 language code
	set baseURL to ""
	set homeURL to ""
	set siteLabel to ""
	set siteSubdir to ""
	set siteLang to "" -- ISO 639-1 language code for this songbook

	if siteKey is "sdah" then
		set baseURL to sdahBaseURL
		set homeURL to sdahHomeURL
		set siteLabel to sdahLabel
		set siteSubdir to sdahSubdir
		set siteLang to sdahLang
	else if siteKey is "ch" then
		set baseURL to chBaseURL
		set homeURL to chHomeURL
		set siteLabel to chLabel
		set siteSubdir to chSubdir
		set siteLang to chLang
	end if

	-- Build the full path to the book-specific output subdirectory
	-- e.g. "/Users/name/Desktop/hymns/Seventh-day Adventist Hymnal [SDAH]"
	set bookDir to pOutputDir & "/" & siteSubdir

	-- Ensure the output directory exists (mkdir -p creates parent dirs too)
	try
		do shell script "mkdir -p " & quoted form of bookDir
	on error errMsg
		log "ERROR: Could not create output directory: " & errMsg
		display dialog "Error: Could not create output directory:" & return & bookDir & return & return & errMsg buttons {"OK"} default button "OK" with title scriptName & " - Error" with icon stop
		return 0
	end try

	-- Log the scraping banner to Script Editor's log pane
	log "=================================================="
	log "  Scraping: " & baseURL & "  [" & siteLabel & "]"
	log "  Output  : " & bookDir
	if pEndHymn > 0 then
		log "  Range   : " & (pStartHymn as text) & " to " & (pEndHymn as text)
	else
		log "  Range   : " & (pStartHymn as text) & " to auto-detect"
	end if
	log "=================================================="

	-- Build the set of hymn numbers that are already saved in the output
	-- directory. This enables resumability -- we skip these hymns.
	-- When force mode is active, we use an empty list instead so that
	-- every hymn is re-downloaded and overwritten regardless of whether
	-- a file already exists on disk.
	if pForceMode then
		set existingHymns to {}
		log "  Force mode: will re-download and overwrite existing files."
	else
		set existingHymns to my buildExistingSet(siteLabel, bookDir)
	end if
	set existingCount to count of existingHymns

	if existingCount > 0 then
		log "  Found " & (existingCount as text) & " existing " & siteLabel & " hymns -- will skip."
	end if

	-- Show a macOS notification that scraping has started for this site
	try
		display notification "Starting " & siteLabel & " scrape from hymn " & (pStartHymn as text) & "..." with title scriptName subtitle "Scraping " & siteLabel
	end try

	-- Initialize counters for the scrape run
	set savedCount to 0 -- number of hymns successfully saved
	set skippedCount to 0 -- number of hymns skipped due to errors
	set consecutiveSkips to 0 -- consecutive failures counter
	set currentHymn to pStartHymn -- current hymn number being processed

	-- =====================================================================
	-- MAIN SCRAPING LOOP
	-- =====================================================================
	-- Iterate through hymn numbers sequentially, fetching and saving each.
	-- The loop exits when:
	--   1. We reach the user-specified end hymn
	--   2. The site redirects to the homepage (end of hymnal detected)
	--   3. We hit maxConsecutiveSkips failures in a row
	--   4. Rate limiting persists after retry
	-- =====================================================================
	repeat
		-- Check if we've reached the user-specified end hymn
		if pEndHymn > 0 and currentHymn > pEndHymn then
			log "  Reached end hymn (" & (pEndHymn as text) & "). Done."
			exit repeat
		end if

		-- Check if this hymn already exists in the output directory.
		-- If so, skip it without making any network request.
		if existingHymns contains currentHymn then
			log "  Hymn " & my padNumber(currentHymn, 4) & ": already exists, skipping."
			set currentHymn to currentHymn + 1
			-- No delay needed -- no network request was made
		else
			-- Fetch the hymn page from the website
			log "  Hymn " & my padNumber(currentHymn, 4) & ": fetching..."

			-- fetchHymn returns a record with keys:
			--   status: "OK", "SKIP", or "STOP"
			--   title: hymn title (only if status is "OK")
			--   sections: list of {indicator, lyrics} records (only if status is "OK")
			set fetchResult to my fetchHymn(currentHymn, baseURL, homeURL)

			set fetchStatus to status of fetchResult

			if fetchStatus is "STOP" then
				-- The site redirected to the homepage or rate limiting persists.
				-- This means we've reached the end of the hymnal.
				log "  Reached end of hymnal or persistent rate limit. Stopping."
				exit repeat

			else if fetchStatus is "SKIP" then
				-- This hymn could not be scraped -- log it and continue
				log "  Hymn " & my padNumber(currentHymn, 4) & ": SKIPPED."
				my logSkip(currentHymn, siteLabel, "fetch failed or no title found", bookDir)
				set skippedCount to skippedCount + 1
				set consecutiveSkips to consecutiveSkips + 1

				-- Safety net: too many consecutive failures suggests we're past
				-- the end rather than hitting intermittent errors
				if consecutiveSkips >= maxConsecutiveSkips then
					log "  " & (maxConsecutiveSkips as text) & " consecutive errors -- assuming end of hymnal."
					exit repeat
				end if

				set currentHymn to currentHymn + 1
				-- Rate limit even on failures (be polite to the server)
				delay pDelay

			else
				-- SUCCESS: hymn was fetched and parsed
				-- Reset the consecutive skip counter
				set consecutiveSkips to 0

				-- Extract the hymn data from the fetch result
				set hymnTitle to hymnTitleText of fetchResult
				set hymnSections to hymnSections of fetchResult

				-- Format the hymn into plain text and save to a file
				set savedPath to my saveHymn(currentHymn, hymnTitle, hymnSections, siteLabel, bookDir)
				set savedCount to savedCount + 1

				-- Log the saved file (just the filename, not the full path)
				set savedFilename to my getFilename(savedPath)
				log "  Hymn " & my padNumber(currentHymn, 4) & ": SAVED - " & savedFilename

				-- Show periodic progress notifications via macOS Notification Center
				-- This gives the user feedback without blocking the script
				if savedCount mod notifyInterval is 0 then
					try
						display notification siteLabel & ": " & (savedCount as text) & " hymns saved so far (hymn " & (currentHymn as text) & ")" with title scriptName subtitle "Progress Update"
					end try
				end if

				set currentHymn to currentHymn + 1
				-- Rate limit between requests (be polite to the server)
				delay pDelay
			end if
		end if
	end repeat

	-- Log the per-site summary
	log ""
	log siteLabel & ": " & (savedCount as text) & " hymns saved, " & (skippedCount as text) & " skipped."
	log ""

	-- Show a notification that this site is complete
	try
		display notification siteLabel & " complete: " & (savedCount as text) & " saved, " & (skippedCount as text) & " skipped." with title scriptName subtitle siteLabel & " Finished"
	end try

	return savedCount
end scrapeSite


-- =============================================================================
-- Handler: fetchHymn
-- =============================================================================
-- Fetch a single hymn page from the website and parse it into structured data.
-- Uses curl for HTTP requests and sed/grep/awk for HTML parsing.
--
-- Includes resilience features:
--   - Retries up to 3 times on HTTP 500 errors (with 3-second delays)
--   - Detects rate-limiting pages and pauses 60 seconds before retrying
--   - Detects end-of-hymnal by checking if the site redirects to homepage
--   - Falls back gracefully on parse errors
--
-- Parameters:
--   hymnNumber - The hymn number to fetch (integer, e.g. 1, 42, 695)
--   baseURL    - The site's hymn page base URL (e.g. "https://www.sdahymnal.org/Hymn")
--   homeURL    - The site's homepage URL (used to detect end-of-hymnal redirects)
--
-- Returns:
--   Record with keys:
--     status: "OK" (success), "SKIP" (skip this hymn), or "STOP" (stop entirely)
--     hymnTitleText: string (only when status is "OK")
--     hymnSections: list of records (only when status is "OK")
-- =============================================================================
on fetchHymn(hymnNumber, baseURL, homeURL)
	-- Construct the hymn page URL using the query parameter format
	-- Both sites use ?no=N to specify the hymn number
	set hymnURL to baseURL & "?no=" & (hymnNumber as text)

	-- =====================================================================
	-- HTTP REQUEST WITH RETRY LOGIC
	-- =====================================================================
	-- Attempt to fetch the page up to maxRetries times on server errors.
	-- We use curl with:
	--   -s        : silent mode (no progress meter)
	--   -L        : follow redirects (important for end-of-hymnal detection)
	--   --max-time: timeout after httpTimeout seconds
	--   -A        : set the User-Agent header
	--   -w        : write out the effective URL after redirects (for detection)
	--   -D        : dump response headers to a temp file (for HTTP status code)
	-- =====================================================================

	set htmlContent to ""
	set effectiveURL to ""
	set httpCode to ""
	set fetchSuccess to false

	repeat with attemptNum from 1 to maxRetries
		try
			-- Execute curl via do shell script.
			-- We use a compound command that:
			-- 1. Fetches the page with curl, writing HTTP code and effective URL
			--    to separate temp files
			-- 2. Outputs the HTML body to stdout (captured by do shell script)
			--
			-- The -w flag appends metadata after the body, separated by a
			-- unique delimiter so we can split them apart.
			--
			-- Using printf for the delimiter avoids issues with newlines in the URL.
			set curlCommand to "curl -s -L --max-time " & (httpTimeout as text) & " -A " & quoted form of userAgent & " -o /tmp/hymn_body.tmp -w '%{http_code}\\n%{url_effective}' " & quoted form of hymnURL

			-- Run curl and capture the status code + effective URL from -w output
			set curlMeta to do shell script curlCommand

			-- Parse the curl -w output: first line is HTTP code, second is effective URL
			-- Split on newline to get both values
			set oldDelims to AppleScript's text item delimiters
			set AppleScript's text item delimiters to {return, linefeed, character id 10}
			set metaParts to text items of curlMeta
			set AppleScript's text item delimiters to oldDelims

			-- Extract HTTP status code (first line of -w output)
			if (count of metaParts) >= 1 then
				set httpCode to item 1 of metaParts
			end if

			-- Extract effective URL (second line of -w output)
			if (count of metaParts) >= 2 then
				set effectiveURL to item 2 of metaParts
			end if

			-- Read the response body from the temp file
			-- Using a temp file avoids issues with binary characters in do shell script
			set htmlContent to do shell script "cat /tmp/hymn_body.tmp 2>/dev/null || echo ''"

			-- Check the HTTP status code for errors
			if httpCode starts with "5" then
				-- Server error (5xx) -- retry with backoff
				if attemptNum < maxRetries then
					log "  Hymn " & (hymnNumber as text) & ": server error (" & httpCode & "), retrying (" & (attemptNum as text) & "/" & (maxRetries as text) & ")..."
					delay 3 -- wait 3 seconds before retrying
				else
					-- All attempts exhausted
					log "  Hymn " & (hymnNumber as text) & ": server error (" & httpCode & ") after " & (maxRetries as text) & " attempts."
					return {status:"SKIP", hymnTitleText:"", hymnSections:{}}
				end if
			else if httpCode starts with "4" then
				-- Client error (4xx, e.g. 404 Not Found) -- don't retry
				log "  Hymn " & (hymnNumber as text) & ": HTTP " & httpCode & "."
				return {status:"SKIP", hymnTitleText:"", hymnSections:{}}
			else
				-- Success (2xx or 3xx handled by -L) -- exit retry loop
				set fetchSuccess to true
				exit repeat
			end if

		on error errMsg
			-- Network error, timeout, or other curl failure
			if attemptNum < maxRetries then
				log "  Hymn " & (hymnNumber as text) & ": error (" & errMsg & "), retrying (" & (attemptNum as text) & "/" & (maxRetries as text) & ")..."
				delay 3
			else
				log "  Hymn " & (hymnNumber as text) & ": error after " & (maxRetries as text) & " attempts: " & errMsg
				return {status:"SKIP", hymnTitleText:"", hymnSections:{}}
			end if
		end try
	end repeat

	-- If we never got a successful response, skip this hymn
	if not fetchSuccess then
		return {status:"SKIP", hymnTitleText:"", hymnSections:{}}
	end if

	-- =====================================================================
	-- REDIRECT DETECTION (End of Hymnal)
	-- =====================================================================
	-- When you request a hymn number that doesn't exist, the site redirects
	-- to the homepage. We detect this by checking if the effective URL
	-- (after following redirects) still contains "Hymn" in the path.
	-- We only check for hymn numbers > 1 because hymn 1 should always exist.
	-- =====================================================================
	if hymnNumber > 1 and effectiveURL does not contain "Hymn" then
		log "  Hymn " & (hymnNumber as text) & ": redirected to home -- reached end."
		return {status:"STOP", hymnTitleText:"", hymnSections:{}}
	end if

	-- =====================================================================
	-- RATE LIMIT DETECTION
	-- =====================================================================
	-- Both sites show a "reached limit for today" or "we are sorry" message
	-- when too many requests have been made. If detected, pause 60 seconds
	-- and retry once.
	-- =====================================================================
	set lowerHTML to my toLower(htmlContent)
	if lowerHTML contains "reached limit for today" or lowerHTML contains "we are sorry" then
		log "  Rate limit hit -- pausing 60 seconds..."
		delay 60

		-- Retry the request after the cooldown
		try
			set retryCommand to "curl -s -L --max-time " & (httpTimeout as text) & " -A " & quoted form of userAgent & " " & quoted form of hymnURL
			set htmlContent to do shell script retryCommand

			-- Check if still rate limited after waiting
			set lowerHTML2 to my toLower(htmlContent)
			if lowerHTML2 contains "reached limit for today" then
				log "  Still rate limited -- stopping. Try again tomorrow."
				return {status:"STOP", hymnTitleText:"", hymnSections:{}}
			end if
		on error
			-- Network error during retry -- stop scraping
			return {status:"STOP", hymnTitleText:"", hymnSections:{}}
		end try
	end if

	-- =====================================================================
	-- HTML PARSING
	-- =====================================================================
	-- Parse the HTML to extract the hymn title and lyrics sections.
	-- Since AppleScript doesn't have a built-in HTML parser, we use shell
	-- commands (sed, grep, awk) to extract the relevant content.
	--
	-- The HTML structure on both sites is:
	--   <div class="block-heading-four">
	--       <h3 class="wedding-heading">
	--           <strong>Hymn Title Here</strong>
	--       </h3>
	--   </div>
	--   <div class="block-heading-three">Verse 1</div>
	--   <div class="block-heading-five">lyrics<br>lyrics<br></div>
	--   ...
	-- =====================================================================

	-- Write the HTML to a temp file so we can process it with shell tools.
	-- Using a file avoids issues with shell escaping of the HTML content.
	try
		do shell script "cat /tmp/hymn_body.tmp > /tmp/hymn_parse.tmp 2>/dev/null"
	on error
		-- If the temp file doesn't exist, write htmlContent directly
		-- We use a heredoc-style approach to safely write arbitrary content
		try
			set tempFileRef to open for access POSIX file "/tmp/hymn_parse.tmp" with write permission
			set eof of tempFileRef to 0
			write htmlContent to tempFileRef as «class utf8»
			close access tempFileRef
		on error
			try
				close access POSIX file "/tmp/hymn_parse.tmp"
			end try
			return {status:"SKIP", hymnTitleText:"", hymnSections:{}}
		end try
	end try

	-- -----------------------------------------------------------------
	-- Extract the hymn title
	-- -----------------------------------------------------------------
	-- The title is inside: <strong>...</strong> within a wedding-heading
	-- class element. We use a multi-step pipeline:
	-- 1. Collapse all HTML to a single line (tr removes newlines)
	-- 2. Find the wedding-heading section
	-- 3. Extract content between <strong> and </strong>
	-- 4. Strip any remaining HTML tags
	-- 5. Decode HTML entities
	-- 6. Trim whitespace
	-- -----------------------------------------------------------------
	set hymnTitle to ""
	try
		-- This sed/grep pipeline extracts the title from the HTML:
		-- Step 1: tr -d '\n' collapses multi-line HTML to a single line
		-- Step 2: sed isolates the wedding-heading block
		-- Step 3: sed extracts content between <strong> and </strong>
		-- Step 4: sed strips remaining HTML tags
		-- Step 5: sed decodes common HTML entities
		-- Step 6: sed trims leading/trailing whitespace
		set titleCommand to "cat /tmp/hymn_parse.tmp | tr -d '\\n\\r' | sed -n 's/.*wedding-heading[^>]*>[[:space:]]*<strong>\\([^<]*\\)<\\/strong>.*/\\1/p' | head -1 | sed 's/<[^>]*>//g' | " & my entityDecodeCommand() & " | sed 's/^[[:space:]]*//;s/[[:space:]]*$//'"
		set hymnTitle to do shell script titleCommand
	on error errMsg
		log "  Title extraction error: " & errMsg
	end try

	-- If the primary extraction failed, try an alternative approach
	-- that handles more complex nesting or whitespace in the HTML
	if hymnTitle is "" then
		try
			set altTitleCommand to "cat /tmp/hymn_parse.tmp | tr -d '\\n\\r' | grep -o 'wedding-heading[^\"]*\"[^>]*>[^<]*<strong>[^<]*</strong>' | head -1 | sed 's/.*<strong>//;s/<\\/strong>.*//' | sed 's/<[^>]*>//g' | " & my entityDecodeCommand() & " | sed 's/^[[:space:]]*//;s/[[:space:]]*$//'"
			set hymnTitle to do shell script altTitleCommand
		on error
			-- Still no title found
			set hymnTitle to ""
		end try
	end if

	-- If we still have no title, try the broadest possible extraction:
	-- look for any <strong> inside a block-heading-four container
	if hymnTitle is "" then
		try
			set broadTitleCommand to "cat /tmp/hymn_parse.tmp | tr -d '\\n\\r' | sed -n 's/.*block-heading-four[^>]*>.*<strong>\\([^<]*\\)<\\/strong>.*/\\1/p' | head -1 | sed 's/<[^>]*>//g' | " & my entityDecodeCommand() & " | sed 's/^[[:space:]]*//;s/[[:space:]]*$//'"
			set hymnTitle to do shell script broadTitleCommand
		on error
			set hymnTitle to ""
		end try
	end if

	-- If no title was found, the page probably isn't a valid hymn page
	if hymnTitle is "" then
		log "  Hymn " & (hymnNumber as text) & ": no title found."
		return {status:"SKIP", hymnTitleText:"", hymnSections:{}}
	end if

	-- -----------------------------------------------------------------
	-- Extract section indicators and lyrics
	-- -----------------------------------------------------------------
	-- We extract indicators (e.g. "Verse 1", "Chorus") and lyrics blocks
	-- separately, then pair them together in order.
	--
	-- Strategy:
	-- 1. Use sed to mark the start of each indicator and lyrics block
	--    with unique delimiters
	-- 2. Process the marked text to extract paired sections
	-- -----------------------------------------------------------------
	set hymnSections to {}

	try
		-- This complex shell pipeline extracts sections from the HTML:
		--
		-- 1. Collapse HTML to a single line
		-- 2. Replace block-heading-three (indicators) with a unique marker: %%INDICATOR%%
		-- 3. Replace block-heading-five (lyrics) with a unique marker: %%LYRICS%%
		-- 4. Insert newlines before each marker so they become separate lines
		-- 5. Process each marker+content line to extract the text
		--
		-- The output format is one line per section:
		--   INDICATOR:Verse 1
		--   LYRICS:First line|Second line|Third line
		--   INDICATOR:Chorus
		--   LYRICS:Chorus line 1|Chorus line 2
		--
		-- where | represents a line break within the lyrics.
		set extractCommand to "cat /tmp/hymn_parse.tmp | tr -d '\\n\\r' " & ¬
			"| sed 's/<div[^>]*class=\"[^\"]*block-heading-three[^\"]*\"[^>]*>/\\n%%INDICATOR%%/g' " & ¬
			"| sed 's/<div[^>]*class=\"[^\"]*block-heading-five[^\"]*\"[^>]*>/\\n%%LYRICS%%/g' " & ¬
			"| sed 's/<\\/div>/\\n%%ENDDIV%%\\n/g' " & ¬
			"| awk '" & ¬
			"BEGIN { mode=\"\"; buf=\"\" } " & ¬
			"/^%%INDICATOR%%/ { mode=\"indicator\"; buf=\"\"; next } " & ¬
			"/^%%LYRICS%%/ { mode=\"lyrics\"; buf=\"\"; next } " & ¬
			"/^%%ENDDIV%%/ { " & ¬
			"  if (mode==\"indicator\") { gsub(/<[^>]*>/,\"\",buf); gsub(/^[ \\t]+|[ \\t]+$/,\"\",buf); print \"INDICATOR:\" buf; } " & ¬
			"  if (mode==\"lyrics\") { gsub(/<br[ \\/]*>/,\"|NEWLINE|\",buf); gsub(/<[^>]*>/,\"\",buf); gsub(/^[ \\t]+|[ \\t]+$/,\"\",buf); print \"LYRICS:\" buf; } " & ¬
			"  mode=\"\"; buf=\"\"; next " & ¬
			"} " & ¬
			"{ if (mode!=\"\") buf = buf $0 }'"
		set extractOutput to do shell script extractCommand

		-- Parse the extracted sections into a list of records.
		-- Each INDICATOR line is followed by a LYRICS line; together they
		-- form one section of the hymn.
		set currentIndicator to ""
		set oldDelims to AppleScript's text item delimiters
		set AppleScript's text item delimiters to {return, linefeed, character id 10}
		set extractLines to text items of extractOutput
		set AppleScript's text item delimiters to oldDelims

		repeat with extractLine in extractLines
			set lineText to extractLine as text

			if lineText starts with "INDICATOR:" then
				-- Extract the indicator text after the "INDICATOR:" prefix
				set currentIndicator to text 11 thru -1 of lineText
				-- Decode HTML entities in the indicator
				try
					set currentIndicator to do shell script "echo " & quoted form of currentIndicator & " | " & my entityDecodeCommand()
				end try

			else if lineText starts with "LYRICS:" then
				-- Extract the lyrics text after the "LYRICS:" prefix
				set lyricsRaw to text 8 thru -1 of lineText

				-- Decode HTML entities in the lyrics
				try
					set lyricsRaw to do shell script "echo " & quoted form of lyricsRaw & " | " & my entityDecodeCommand()
				end try

				-- Convert our |NEWLINE| markers back to actual newlines
				set lyricsText to my replaceText(lyricsRaw, "|NEWLINE|", linefeed)

				-- Collapse runs of 3+ newlines down to 2 (clean up excessive
				-- whitespace from empty <br> tags in the source HTML).
				-- This matches the Python version's re.sub(r'\n{3,}', '\n\n', text)
				try
					set lyricsText to do shell script "echo " & quoted form of lyricsText & " | sed '/^$/{ N; /^\\n$/{ N; s/^\\n\\n$/\\n/; }; }' | sed 's/^[[:space:]]*$//'"
				end try

				-- Trim leading/trailing whitespace from the lyrics
				set lyricsText to my trimText(lyricsText)

				-- Add this section to the hymn sections list
				-- Each section is a record with indicator and lyrics keys
				set end of hymnSections to {sectionIndicator:currentIndicator, sectionLyrics:lyricsText}

				-- Reset the indicator for the next section
				-- (some lyrics blocks may not have a preceding indicator)
				set currentIndicator to ""
			end if
		end repeat
	on error errMsg
		log "  Section extraction error: " & errMsg
		-- If section extraction fails but we have a title, we'll save
		-- what we have (title only, no lyrics). This is better than
		-- losing the title entirely.
	end try

	-- Return the successfully parsed hymn data
	return {status:"OK", hymnTitleText:hymnTitle, hymnSections:hymnSections}
end fetchHymn


-- =============================================================================
-- Handler: entityDecodeCommand
-- =============================================================================
-- Returns a shell command string (sed pipeline) that decodes common HTML
-- entities into their character equivalents. This is used by multiple
-- parsing steps to clean up extracted text.
--
-- Handles:
--   &amp;   -> &
--   &lt;    -> <
--   &gt;    -> >
--   &quot;  -> "
--   &apos;  -> '
--   &nbsp;  -> (space)
--   &rsquo; -> ' (right single quote, but we normalize to ASCII apostrophe)
--   &lsquo; -> ' (left single quote, normalized to ASCII)
--   &rdquo; -> " (right double quote, normalized to ASCII)
--   &ldquo; -> " (left double quote, normalized to ASCII)
--   &mdash; -> -- (em dash, approximated with double hyphen)
--   &ndash; -> - (en dash, approximated with hyphen)
--   &#NNN;  -> decimal numeric character references (common ones)
--   &#145;  -> ' (Windows-1252 left single quote, normalized to ASCII)
--   &#146;  -> ' (Windows-1252 right single quote, normalized to ASCII)
--   &#147;  -> " (Windows-1252 left double quote, normalized to ASCII)
--   &#148;  -> " (Windows-1252 right double quote, normalized to ASCII)
--   &#150;  -> - (Windows-1252 en dash, approximated with hyphen)
--   &#151;  -> -- (Windows-1252 em dash, approximated with double hyphen)
--
-- Returns:
--   String - a sed command pipeline for entity decoding
-- =============================================================================
on entityDecodeCommand()
	-- Build a sed command chain that replaces HTML entities with their
	-- character equivalents. We normalize smart quotes to ASCII equivalents
	-- for maximum compatibility across text editors and operating systems.
	return "sed " & ¬
		"'s/&amp;/\\&/g; " & ¬
		"s/&lt;/</g; " & ¬
		"s/&gt;/>/g; " & ¬
		"s/&quot;/\"/g; " & ¬
		"s/&apos;/'\\''/g; " & ¬
		"s/&nbsp;/ /g; " & ¬
		"s/&rsquo;/'\\''/g; " & ¬
		"s/&lsquo;/'\\''/g; " & ¬
		"s/&rdquo;/\"/g; " & ¬
		"s/&ldquo;/\"/g; " & ¬
		"s/&mdash;/--/g; " & ¬
		"s/&ndash;/-/g; " & ¬
		"s/&#39;/'\\''/g; " & ¬
		"s/&#34;/\"/g; " & ¬
		"s/&#160;/ /g; " & ¬
		"s/&#8217;/'\\''/g; " & ¬
		"s/&#8216;/'\\''/g; " & ¬
		"s/&#8220;/\"/g; " & ¬
		"s/&#8221;/\"/g; " & ¬
		"s/&#8212;/--/g; " & ¬
		"s/&#8213;/--/g; " & ¬
		"s/&#8211;/-/g; " & ¬
		"s/&#145;/'\\''/g; " & ¬
		"s/&#146;/'\\''/g; " & ¬
		"s/&#147;/\"/g; " & ¬
		"s/&#148;/\"/g; " & ¬
		"s/&#150;/-/g; " & ¬
		"s/&#151;/--/g'"
end entityDecodeCommand


-- =============================================================================
-- Handler: saveHymn
-- =============================================================================
-- Format a parsed hymn into plain text and save it to a file in the output
-- directory. Creates the file with UTF-8 encoding.
--
-- Output format matches the Python version:
--   "Hymn Title"
--
--   Verse 1
--   lyrics line 1
--   lyrics line 2
--
--   Chorus
--   chorus line 1
--
-- Parameters:
--   hymnNumber   - The hymn number (integer)
--   hymnTitle    - The hymn title (string)
--   hymnSections - List of records with sectionIndicator and sectionLyrics keys
--   siteLabel    - Book label for the filename (e.g. "SDAH", "CH")
--   bookDir      - Directory path where the file should be saved (POSIX path)
--
-- Returns:
--   String - the full POSIX path to the saved file
-- =============================================================================
on saveHymn(hymnNumber, hymnTitle, hymnSections, siteLabel, bookDir)
	-- Format the hymn content as plain text
	set formattedText to my formatHymn(hymnTitle, hymnSections)

	-- Zero-pad the hymn number to 3 digits for consistent sorting
	-- e.g. 1 -> "001", 42 -> "042", 695 -> "695"
	set paddedNumber to my padNumber(hymnNumber, 3)

	-- Sanitize the title to remove characters invalid in filenames,
	-- then convert to Title Case for consistent, readable filenames
	set cleanTitle to my titleCase(my sanitizeFilename(hymnTitle))

	-- Build the full filename in the standard format:
	-- "{padded} ({LABEL}) - {Title Case Title}.txt"
	set fileName to paddedNumber & " (" & siteLabel & ") - " & cleanTitle & ".txt"

	-- Build the full file path
	set filePath to bookDir & "/" & fileName

	-- Write the formatted text to the file using AppleScript file I/O.
	-- We use the "open for access" approach for reliable UTF-8 writing.
	try
		-- Open the file for writing (create if it doesn't exist)
		set fileRef to open for access POSIX file filePath with write permission
		-- Truncate the file (in case it already exists with different content)
		set eof of fileRef to 0
		-- Write the content as UTF-8 encoded text
		write formattedText to fileRef as «class utf8»
		-- Close the file handle
		close access fileRef
	on error errMsg
		-- Ensure the file handle is closed even on error
		try
			close access POSIX file filePath
		end try
		log "  ERROR saving file: " & errMsg
	end try

	return filePath
end saveHymn


-- =============================================================================
-- Handler: formatHymn
-- =============================================================================
-- Format a hymn title and sections list into a clean plain-text string.
-- The output format matches the Python version exactly.
--
-- Parameters:
--   hymnTitle    - The hymn title string
--   hymnSections - List of records with sectionIndicator and sectionLyrics keys
--
-- Returns:
--   String - the formatted plain-text hymn content
-- =============================================================================
on formatHymn(hymnTitle, hymnSections)
	-- Start with the quoted title and a blank line (matches Python output)
	set outputLines to {"\"" & hymnTitle & "\"", ""}

	-- Append each section (indicator + lyrics) with blank line separators
	repeat with aSection in hymnSections
		set indicator to sectionIndicator of aSection
		set lyrics to sectionLyrics of aSection

		-- Add the indicator line if present (e.g. "Verse 1", "Chorus")
		if indicator is not "" then
			set end of outputLines to indicator
		end if

		-- Add the lyrics text if present
		if lyrics is not "" then
			set end of outputLines to lyrics
		end if

		-- Blank line between sections
		set end of outputLines to ""
	end repeat

	-- Remove trailing blank lines for a cleaner file ending.
	-- This matches the Python version's behavior of stripping trailing empties.
	repeat while (count of outputLines) > 0 and (item -1 of outputLines) is ""
		if (count of outputLines) > 1 then
			set outputLines to items 1 thru -2 of outputLines
		else
			set outputLines to {}
		end if
	end repeat

	-- Join all lines with newline characters to form the final text
	set oldDelims to AppleScript's text item delimiters
	set AppleScript's text item delimiters to linefeed
	set outputText to outputLines as text
	set AppleScript's text item delimiters to oldDelims

	return outputText
end formatHymn


-- =============================================================================
-- Handler: buildExistingSet
-- =============================================================================
-- Scan the output directory to find hymn numbers that have already been saved.
-- This enables the scraper to resume from where it left off without
-- re-downloading hymns.
--
-- Looks for files matching the naming pattern:
--   {number} ({label}) - {title}.txt
-- and extracts the hymn number from each matching filename.
--
-- Parameters:
--   siteLabel - The book label to filter by (e.g. "SDAH", "CH")
--   bookDir   - Path to the directory to scan (POSIX path)
--
-- Returns:
--   List of integers - hymn numbers already saved (empty list if none)
-- =============================================================================
on buildExistingSet(siteLabel, bookDir)
	set existingNumbers to {}

	try
		-- Use ls and grep to find files matching our naming pattern.
		-- The grep pattern matches files containing "({label}) -" and ending in .txt.
		-- We extract just the leading number from each filename.
		--
		-- Pipeline breakdown:
		-- 1. ls: list files in the book directory
		-- 2. grep: filter for files with the correct label and .txt extension
		-- 3. sed: extract the leading digits (hymn number) from each filename
		-- 4. sort -n: sort numerically for consistent ordering
		set prefixTag to "(" & siteLabel & ") -"
		set listCommand to "ls " & quoted form of bookDir & " 2>/dev/null | grep " & quoted form of prefixTag & " | grep '\\.txt$' | sed 's/^[[:space:]]*\\([0-9]*\\).*/\\1/' | sort -n"
		set fileList to do shell script listCommand

		-- Parse the output (one number per line) into a list of integers
		if fileList is not "" then
			set oldDelims to AppleScript's text item delimiters
			set AppleScript's text item delimiters to {return, linefeed, character id 10}
			set numberStrings to text items of fileList
			set AppleScript's text item delimiters to oldDelims

			repeat with numStr in numberStrings
				set numText to numStr as text
				if numText is not "" then
					try
						set end of existingNumbers to (numText as integer)
					end try
				end if
			end repeat
		end if
	on error
		-- Directory doesn't exist or is empty -- return empty list
		-- This is normal for the first run
	end try

	return existingNumbers
end buildExistingSet


-- =============================================================================
-- Handler: logSkip
-- =============================================================================
-- Record a skipped hymn entry in the skipped.log file for later review.
-- Appends a timestamped line to the log file in the book directory.
--
-- Log format (one line per skipped hymn):
--   [2026-03-13 14:30:00]  SDAH042  --  fetch failed or no title found
--
-- Parameters:
--   hymnNumber - The hymn number that was skipped (integer)
--   siteLabel  - Book label (e.g. "SDAH", "CH")
--   reason     - Human-readable explanation of why it was skipped
--   bookDir    - Directory path where the log file should be written (POSIX path)
-- =============================================================================
on logSkip(hymnNumber, siteLabel, reason, bookDir)
	try
		-- Ensure the directory exists
		do shell script "mkdir -p " & quoted form of bookDir

		-- Build the log entry with a timestamp
		-- Using shell date command for consistent formatting
		set paddedNum to my padNumber(hymnNumber, 3)
		set logEntry to "[" & (do shell script "date '+%Y-%m-%d %H:%M:%S'") & "]  " & siteLabel & paddedNum & "  --  " & reason

		-- Append the log entry to skipped.log using shell echo with >>
		-- The >> operator appends to the file (creates it if it doesn't exist)
		set logPath to bookDir & "/skipped.log"
		do shell script "echo " & quoted form of logEntry & " >> " & quoted form of logPath
	on error errMsg
		-- Log file write failure is non-critical -- just note it in the Script Editor log
		log "  WARNING: Could not write to skipped.log: " & errMsg
	end try
end logSkip


-- =============================================================================
-- Handler: padNumber
-- =============================================================================
-- Zero-pad an integer to a specified width for consistent formatting.
-- e.g. padNumber(1, 3) -> "001", padNumber(42, 3) -> "042"
--
-- Parameters:
--   num   - The integer to pad
--   width - The desired total width (number of characters)
--
-- Returns:
--   String - the zero-padded number string
-- =============================================================================
on padNumber(num, width)
	-- Convert the number to a string
	set numStr to num as text

	-- Prepend zeros until we reach the desired width
	repeat while (count of numStr) < width
		set numStr to "0" & numStr
	end repeat

	return numStr
end padNumber


-- =============================================================================
-- Handler: sanitizeFilename
-- =============================================================================
-- Remove characters that are invalid in filenames across operating systems.
-- Strips: \ / * ? : " < > |
-- These are forbidden in Windows filenames and/or could cause issues on
-- other platforms including macOS (which forbids : and / in filenames).
--
-- Parameters:
--   name - The raw string to sanitize (typically a hymn title)
--
-- Returns:
--   String - the sanitized string with invalid characters removed
-- =============================================================================
on sanitizeFilename(name)
	try
		-- Use sed to remove all invalid filename characters in one pass.
		-- The character class [\\/*?:"<>|] matches all forbidden chars.
		-- We also trim leading/trailing whitespace.
		set sanitized to do shell script "echo " & quoted form of name & " | sed 's/[\\\\/\\*?:\"<>|]//g; s/^[[:space:]]*//; s/[[:space:]]*$//'"
		return sanitized
	on error
		-- If sed fails for any reason, do a basic AppleScript cleanup
		return name
	end try
end sanitizeFilename


-- =============================================================================
-- Handler: titleCase
-- =============================================================================
-- Convert a string to Title Case with correct handling of apostrophes.
-- This matches the Python version's behavior where contractions like
-- "don't", "it's", "o'er" are handled correctly (the letter after the
-- apostrophe stays lowercase).
--
-- Uses awk for the conversion because awk splits on whitespace, so words
-- containing any kind of apostrophe (ASCII ' or Unicode curly \u2019/\u2018
-- from decoded HTML entities like &rsquo;) are naturally treated as single
-- tokens — no special character class needed.
--
-- Parameters:
--   s - The input string to convert
--
-- Returns:
--   String - the Title Cased string
--
-- Examples:
--   "AMAZING GRACE"     -> "Amazing Grace"
--   "don't let me down" -> "Don't Let Me Down"
--   "o'er the hills"    -> "O'er The Hills"
-- =============================================================================
on titleCase(s)
	if s is "" then return ""

	try
		-- This awk script processes each word (split by spaces) and converts
		-- it to title case. For words containing apostrophes, it keeps the
		-- word together and only capitalizes the first character.
		--
		-- The approach:
		-- 1. Split each line into words (awk's default behavior)
		-- 2. For each word: lowercase it, then uppercase the first character
		-- 3. Rejoin words with spaces
		--
		-- The tolower() ensures consistent casing before we capitalize,
		-- so "ALL CAPS" becomes "All Caps" rather than "ALL CAPS".
		set titleCommand to "echo " & quoted form of s & " | awk '{for(i=1;i<=NF;i++){w=tolower($i);$i=toupper(substr(w,1,1)) substr(w,2)}}1'"
		set result to do shell script titleCommand
		return result
	on error
		-- Fallback: return the original string if awk fails
		return s
	end try
end titleCase


-- =============================================================================
-- Handler: replaceText
-- =============================================================================
-- Replace all occurrences of a substring within a string.
-- AppleScript doesn't have a built-in string replacement function, so
-- we use text item delimiters to split and rejoin the string.
--
-- Parameters:
--   sourceText  - The original string
--   searchText  - The substring to find
--   replaceWith - The replacement substring
--
-- Returns:
--   String - the modified string with all occurrences replaced
-- =============================================================================
on replaceText(sourceText, searchText, replaceWith)
	-- Save the current text item delimiters to restore them later.
	-- AppleScript's text item delimiters are a global setting, so we must
	-- save and restore to avoid affecting other parts of the script.
	set oldDelims to AppleScript's text item delimiters

	-- Split the string on the search text
	set AppleScript's text item delimiters to searchText
	set textItems to text items of sourceText

	-- Rejoin with the replacement text
	set AppleScript's text item delimiters to replaceWith
	set resultText to textItems as text

	-- Restore original delimiters
	set AppleScript's text item delimiters to oldDelims

	return resultText
end replaceText


-- =============================================================================
-- Handler: trimText
-- =============================================================================
-- Remove leading and trailing whitespace (spaces, tabs, newlines) from a string.
--
-- Parameters:
--   s - The string to trim
--
-- Returns:
--   String - the trimmed string
-- =============================================================================
on trimText(s)
	if s is "" then return ""

	try
		-- Use sed to remove leading and trailing whitespace.
		-- Two sed expressions:
		-- 1. Remove leading whitespace from the first line
		-- 2. Remove trailing whitespace from the last line
		-- We also handle multi-line strings by removing blank leading/trailing lines.
		set trimmed to do shell script "echo " & quoted form of s & " | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//' | sed -e '/./,$!d' -e :a -e '/^\\n*$/{$d;N;ba' -e '}'"
		return trimmed
	on error
		return s
	end try
end trimText


-- =============================================================================
-- Handler: toLower
-- =============================================================================
-- Convert a string to lowercase.
-- Used for case-insensitive string comparisons (e.g. rate limit detection).
--
-- Parameters:
--   s - The string to convert
--
-- Returns:
--   String - the lowercase version of the string
-- =============================================================================
on toLower(s)
	if s is "" then return ""

	try
		-- Use tr to convert uppercase letters to lowercase.
		-- We write the string to a temp file first to avoid issues
		-- with special characters in the shell command.
		--
		-- For short strings we use echo; for long strings (HTML content)
		-- we read from the temp file that was already written.
		if (count of s) > 1000 then
			-- For large strings (like full HTML pages), use the temp file
			-- to avoid "argument list too long" errors in the shell
			return do shell script "cat /tmp/hymn_parse.tmp 2>/dev/null | tr '[:upper:]' '[:lower:]'"
		else
			return do shell script "echo " & quoted form of s & " | tr '[:upper:]' '[:lower:]'"
		end if
	on error
		return s
	end try
end toLower


-- =============================================================================
-- Handler: getFilename
-- =============================================================================
-- Extract just the filename from a full POSIX file path.
-- e.g. "/path/to/folder/file.txt" -> "file.txt"
--
-- Parameters:
--   filePath - A POSIX file path string
--
-- Returns:
--   String - just the filename component
-- =============================================================================
on getFilename(filePath)
	-- Split the path on "/" and take the last component
	set oldDelims to AppleScript's text item delimiters
	set AppleScript's text item delimiters to "/"
	set pathParts to text items of filePath
	set AppleScript's text item delimiters to oldDelims

	if (count of pathParts) > 0 then
		return item -1 of pathParts
	else
		return filePath
	end if
end getFilename
