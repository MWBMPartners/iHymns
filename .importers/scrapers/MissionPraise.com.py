#!/usr/bin/env python3
"""
MissionPraise.com.py
importers/scrapers/MissionPraise.com.py

Mission Praise Scraper — scrapes lyrics and downloads files from missionpraise.com.
Copyright 2025-2026 MWBM Partners Ltd.

Overview:
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

Authentication:
    The site uses WordPress standard login (wp-login.php) with CSRF nonces
    and may be behind a Sucuri WAF (Web Application Firewall). The login
    flow handles:
    - Extracting hidden form fields (nonces) from the login page
    - Setting proper Referer/Origin headers to pass WAF checks
    - Detecting WAF blocks, login failures, and ambiguous states
    - Cookie-based session management via http.cookiejar

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
    None — uses only Python standard library modules (no pip install needed).
    This is intentional so the script can run on any system with Python 3.6+.

Usage:
    python3 mp_scraper.py --username YOUR_EMAIL --password YOUR_PASSWORD
    python3 mp_scraper.py --username YOUR_EMAIL --password YOUR_PASSWORD --output ~/Desktop/hymns
    python3 mp_scraper.py --username YOUR_EMAIL --password YOUR_PASSWORD --books mp,cp,jp
    python3 mp_scraper.py --username YOUR_EMAIL --password YOUR_PASSWORD --start-page 5
    python3 mp_scraper.py --username YOUR_EMAIL --password YOUR_PASSWORD --no-files
"""

# ---------------------------------------------------------------------------
# Standard library imports (no third-party dependencies required)
# ---------------------------------------------------------------------------
import urllib.request    # For making HTTP requests (GET and POST)
import urllib.parse      # For URL encoding of form data and URL parsing
import urllib.error      # For handling HTTP error responses
import sys

# Force line-buffered stdout so progress messages appear immediately in the
# terminal, even when output is piped or redirected. Without this, Python
# uses block buffering when stdout is not a TTY, which delays progress output.
sys.stdout.reconfigure(line_buffering=True)

import http.cookiejar   # Cookie management for maintaining login session
import html.parser       # Base class for custom HTML parsers (no BeautifulSoup)
import gzip              # For decompressing gzip-encoded HTTP responses
import os                # File system operations (makedirs, path joining, etc.)
import re                # Regular expressions for HTML parsing and text processing
import time              # For rate-limiting delays between requests
import argparse          # Command-line argument parsing
import getpass           # Secure password input (hides characters while typing)


# ---------------------------------------------------------------------------
# Constants — URLs, timing, and global configuration
# ---------------------------------------------------------------------------

# Base URL for the Mission Praise website
BASE        = "https://missionpraise.com"

# WordPress standard login endpoint
LOGIN_URL   = f"{BASE}/wp-login.php"

# Paginated song index URL — append page number (e.g. /songs/page/1/)
INDEX_URL   = f"{BASE}/songs/page/"

# Default delay between HTTP requests (seconds). 1.2s is chosen to be
# respectful to the server while keeping scraping at a reasonable speed.
DELAY       = 1.2

# Default output directory (relative to where the script is run)
DEFAULT_OUT = "./hymns"

# Global debug flag — set to True via --debug CLI argument to dump HTML
# responses for troubleshooting login/parsing issues
DEBUG       = False

# HTML5 void elements — these tags have no closing tag, so they must NOT
# affect depth tracking. Without this exclusion, each <br> (etc.) inflates
# the depth counter by +1 with no matching -1, preventing proper detection
# of when the parser has exited a container element like <div class="song-details">.
# Reference: https://html.spec.whatwg.org/multipage/syntax.html#void-elements
VOID_ELEMENTS = frozenset([
    "area", "base", "br", "col", "embed", "hr", "img", "input",
    "link", "meta", "param", "source", "track", "wbr",
])

# ---------------------------------------------------------------------------
# Book configuration — defines the three hymnbooks scraped from the site
# ---------------------------------------------------------------------------
# Each book has:
#   label:   Short identifier used in filenames (e.g. "MP", "CP", "JP")
#   pad:     Number of digits to zero-pad the hymn number to (MP=4, others=3)
#   pattern: Regex to extract the book number from the index page title
#            e.g. "Amazing Grace (MP0023)" → matches "(MP0023)" → group(1) = "0023"
#   subdir:  Human-readable subdirectory name for organised file output
BOOK_CONFIG = {
    "mp": {"label": "MP", "pad": 4, "pattern": r'\(MP(\d+)\)', "subdir": "Mission Praise [MP]"},
    "cp": {"label": "CP", "pad": 3, "pattern": r'\(CP(\d+)\)', "subdir": "Carol Praise [CP]"},
    "jp": {"label": "JP", "pad": 3, "pattern": r'\(JP(\d+)\)', "subdir": "Junior Praise [JP]"},
}

# ---------------------------------------------------------------------------
# MIME type → file extension mapping for downloaded files
# ---------------------------------------------------------------------------
# When downloading files (words, music, audio), the server's Content-Type
# header tells us what format the file is in. This mapping converts common
# MIME types to their standard file extensions.
# "application/octet-stream" is a generic binary type — we fall back to
# guessing the extension from the URL or magic bytes in that case.
MIME_TO_EXT = {
    "application/rtf":          ".rtf",     # Rich Text Format (words)
    "text/rtf":                 ".rtf",     # Alternate RTF MIME type
    "application/msword":       ".doc",     # Microsoft Word (legacy)
    "application/vnd.openxmlformats-officedocument.wordprocessingml.document": ".docx",
    "application/pdf":          ".pdf",     # PDF (music scores)
    "audio/midi":               ".mid",     # MIDI audio
    "audio/x-midi":             ".mid",     # Alternate MIDI MIME type
    "audio/mpeg":               ".mp3",     # MP3 audio
    "audio/mp3":                ".mp3",     # Alternate MP3 MIME type
    "audio/wav":                ".wav",     # WAV audio
    "audio/x-wav":              ".wav",     # Alternate WAV MIME type
    "audio/ogg":                ".ogg",     # Ogg Vorbis audio
    "application/octet-stream": "",         # Generic binary — fall back to URL/magic
}

# Mapping of download type keys to human-readable labels (used in logging)
TYPE_LABEL = {
    "words": "words",
    "music": "music",
    "audio": "audio",
}


# ---------------------------------------------------------------------------
# Session / opener — HTTP session management with cookie support
# ---------------------------------------------------------------------------

def debug_dump(label, text, max_chars=3000):
    """
    Print a labelled debug dump of HTML/text content (only when DEBUG is True).

    Used during development and troubleshooting to inspect raw HTML responses
    from the server. Truncates output to max_chars to avoid flooding the terminal.

    Args:
        label:     A descriptive label for what's being dumped
        text:      The text content to dump
        max_chars: Maximum characters to display (default: 3000)
    """
    if not DEBUG:
        return
    print(f"\n{'='*60}")
    print(f"DEBUG: {label}")
    print(f"{'='*60}")
    print(text[:max_chars])
    if len(text) > max_chars:
        print(f"... ({len(text) - max_chars} more chars)")
    print(f"{'='*60}\n")


def make_opener():
    """
    Create an HTTP opener with cookie support and browser-like headers.

    Returns a urllib opener configured to:
    1. Automatically store and send cookies (for session management after login)
    2. Send headers that mimic a real browser (to avoid being blocked by WAFs
       or bot detection systems)

    The headers are modelled after a real Chrome/Edge browser on macOS,
    including Sec-Fetch-* headers that modern WAFs check to distinguish
    legitimate browser requests from automated scripts.

    Returns:
        tuple: (opener, jar) where:
            opener: urllib.request.OpenerDirector configured with cookies
            jar:    http.cookiejar.CookieJar instance for inspecting cookies
    """
    # CookieJar stores all cookies received from the server and automatically
    # sends them back on subsequent requests (essential for login sessions)
    jar    = http.cookiejar.CookieJar()
    opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))

    # Set default headers that mimic a real browser to avoid WAF blocks.
    # These headers are sent with every request made through this opener.
    opener.addheaders = [
        # User-Agent string mimicking Chrome on macOS
        ("User-Agent",
         "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) "
         "AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 Edg/145.0.0.0"),
        # Accept header indicating we prefer HTML but accept anything
        ("Accept",
         "text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,"
         "image/webp,image/apng,*/*;q=0.8"),
        ("Accept-Language", "en-GB,en;q=0.9"),
        ("Accept-Encoding", "gzip, deflate, br"),      # We handle gzip decompression
        ("Connection", "keep-alive"),                    # Reuse TCP connections
        ("Upgrade-Insecure-Requests", "1"),              # Standard browser header
        # Sec-Fetch-* headers: modern security headers that WAFs use to verify
        # requests come from a real browser navigation context
        ("Sec-Fetch-Dest", "document"),     # We're requesting a document
        ("Sec-Fetch-Mode", "navigate"),     # This is a navigation request
        ("Sec-Fetch-Site", "same-origin"),  # Request is to the same site
        ("Sec-Fetch-User", "?1"),           # User-initiated request
        ("Cache-Control", "max-age=0"),     # Don't serve cached responses
    ]
    return opener, jar


def login(opener, jar, username, password):
    """
    Authenticate with missionpraise.com using WordPress standard login.

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

    Args:
        opener:   urllib opener with cookie support (from make_opener)
        jar:      CookieJar instance for cookie inspection during debugging
        username: The user's missionpraise.com email address
        password: The user's missionpraise.com password

    Returns:
        bool: True if login appears successful, False if definitely failed
    """
    print(f"Logging in as {username}...")

    # --- Step 1: GET the login page to extract CSRF nonces ---
    # WordPress login forms contain hidden fields (nonces) that must be
    # submitted with the POST request to prevent CSRF attacks.
    with opener.open(LOGIN_URL) as resp:
        html_text = _decode_response(resp)

    debug_dump("Login page HTML", html_text)

    # Extract all <input type="hidden"> fields from the login form.
    # These typically include:
    #   - testcookie: WordPress test cookie check
    #   - _wpnonce: WordPress CSRF nonce
    #   - Various plugin-specific nonces
    hidden = {}
    for m in re.finditer(r'<input[^>]+type=["\']hidden["\'][^>]*>', html_text, re.I):
        nm = re.search(r'name=["\']([^"\']+)["\']',  m.group())
        vm = re.search(r'value=["\']([^"\']*)["\']', m.group())
        if nm:
            hidden[nm.group(1)] = vm.group(1) if vm else ""

    if DEBUG:
        print(f"  DEBUG: Hidden fields: {hidden}")
        print(f"  DEBUG: Cookies after GET login page: {[c.name for c in jar]}")

    # --- Step 2: Brief pause to appear more human-like ---
    # Some WAFs flag requests that POST to the login form within milliseconds
    # of loading the page, as this is a strong indicator of automated access.
    time.sleep(2)

    # --- Step 3: POST the login credentials ---
    # Build the login form data with WordPress standard field names
    payload = {
        "log":         username,              # WordPress username field
        "pwd":         password,              # WordPress password field
        "wp-submit":   "Log In",              # Submit button value
        "redirect_to": f"{BASE}/songs/",      # Where to redirect after login
        "rememberme":  "forever",             # Keep the session alive
        **hidden,                             # Include all CSRF nonces
    }
    data = urllib.parse.urlencode(payload).encode()

    # Build a POST request with proper Referer and Origin headers.
    # The Sucuri WAF checks these headers — missing or incorrect values
    # will result in a 403 block.
    login_req = urllib.request.Request(LOGIN_URL, data=data)
    login_req.add_header("Referer", LOGIN_URL)      # Must match the login page URL
    login_req.add_header("Origin", BASE)             # Must match the site origin
    login_req.add_header("Content-Type", "application/x-www-form-urlencoded")

    try:
        with opener.open(login_req) as resp:
            final_url = resp.url       # Where did the server redirect us?
            body      = _decode_response(resp)
    except urllib.error.HTTPError as e:
        # Some servers return HTTP errors (403, etc.) for blocked login attempts.
        # We still need to read the response body to check for WAF messages.
        final_url = getattr(e, "url", "") or ""
        raw = e.read()
        # Decompress gzip if needed (error responses can be compressed too)
        if raw[:2] == b'\x1f\x8b':
            raw = gzip.decompress(raw)
        body = raw.decode("utf-8", errors="replace")

    if DEBUG:
        print(f"  DEBUG: Post-login redirect URL: {final_url}")
        print(f"  DEBUG: Cookies after POST login: {[c.name for c in jar]}")
        debug_dump("Post-login body", body)

    # --- Step 4: Check for various failure modes ---

    # Check for WAF / firewall blocks (e.g. Sucuri CDN/WAF)
    # Sucuri blocks show a distinctive page with "Access Denied" and details
    # about the block reason and the client's IP address.
    if "sucuri" in body.lower() or "access denied" in body.lower():
        block_reason = re.search(r'Block reason:</td>\s*<td><span>(.*?)</span>', body, re.S)
        block_ip     = re.search(r'Your IP:</td>\s*<td><span>(.*?)</span>', body, re.S)
        reason = block_reason.group(1).strip() if block_reason else "unknown"
        ip     = block_ip.group(1).strip() if block_ip else "unknown"
        print(f"  [!] BLOCKED by website firewall: {reason}")
        print(f"       Your IP: {ip}")
        print("       Try again later or log in via browser first to whitelist your IP.")
        return False

    # Check for incorrect credentials — WordPress stays on wp-login.php
    # and includes an error message in the response body
    if "wp-login.php" in final_url and "incorrect" in body.lower():
        print("  [!] Login failed — check username/password.")
        return False

    # Check for other WordPress login errors (still on login page after POST)
    if "wp-login.php" in final_url:
        if DEBUG:
            print(f"  DEBUG: Still on login page after POST — login likely failed")
        # Look for the WordPress login error div which contains specific messages
        # like "Unknown username", "Incorrect password", etc.
        error_m = re.search(r'<div[^>]*id=["\']login_error["\'][^>]*>(.*?)</div>', body, re.S)
        if error_m:
            # Strip HTML tags from the error message for clean display
            error_text = re.sub(r'<[^>]+>', '', error_m.group(1)).strip()
            print(f"  [!] Login error: {error_text}")
            return False

    # --- Step 5: Verify login by fetching the songs page ---
    # Even if the POST didn't show an error, we verify by checking if the
    # songs page shows logged-in indicators (some sites silently fail login).
    with opener.open(f"{BASE}/songs/") as resp:
        check     = _decode_response(resp)
        check_url = resp.url

    if DEBUG:
        print(f"  DEBUG: Songs page URL: {check_url}")
        print(f"  DEBUG: Cookies when checking songs: {[c.name for c in jar]}")
        debug_dump("Songs page HTML", check)

    # Look for multiple indicators of being logged in — different WordPress
    # themes show different indicators, so we check several possibilities
    check_lower = check.lower()
    logged_in = any([
        "logout" in check_lower,           # "Log out" link in header/nav
        "log out" in check_lower,           # Alternate spelling
        "log-out" in check_lower,           # Hyphenated version
        "Welcome" in check,                 # "Welcome, Username" greeting
        "my-account" in check_lower,        # Account menu link
        "wp-admin" in check_lower,          # Admin bar (for admin users)
        'logged-in' in check_lower,         # CSS class on <body> tag
    ])

    if logged_in:
        print("  ✓ Logged in.")
        return True

    # Check if we were redirected back to the login page (session not established)
    if "wp-login" in check_url or "login" in check_url:
        print("  [!] Login failed — redirected back to login page.")
        return False

    # Login status is ambiguous — proceed anyway (some themes don't show
    # obvious logged-in indicators). The user can re-run with --debug to
    # investigate if songs fail to load.
    print("  [?] Login status unclear — proceeding anyway.")
    print("       (Re-run with --debug to see full HTML responses)")
    return True


# ---------------------------------------------------------------------------
# HTML parsers — custom parsers for the site's index and song pages
# ---------------------------------------------------------------------------

class IndexParser(html.parser.HTMLParser):
    """
    Parser for the paginated song index page at missionpraise.com/songs/page/N/.

    The index page lists songs as links within heading elements. Each song
    appears as an <a> tag inside an <h2> or <h3>, with the title text
    including the book code (e.g. "Amazing Grace (MP0023)").

    HTML structure being parsed:
        <h2>
            <a href="/songs/amazing-grace-mp0023/">Amazing Grace (MP0023)</a>
        </h2>

    Attributes:
        songs: List of (title_text, url) tuples discovered on the page
    """
    def __init__(self):
        super().__init__()
        self.songs   = []      # Output: list of (title, url) tuples
        self._in_h   = False   # True when inside an <h2> or <h3> element
        self._a_href = None    # The href of the current <a> tag (if inside heading)
        self._buf    = []      # Text buffer for accumulating link text

    def handle_starttag(self, tag, attrs):
        """Track entry into heading elements and capture song links within them."""
        # Enter heading context (songs are listed inside <h2> or <h3> tags)
        if tag in ("h2", "h3"):
            self._in_h = True

        # When we find an <a> tag inside a heading with a /songs/ URL,
        # start capturing its text content
        if self._in_h and tag == "a":
            href = dict(attrs).get("href", "")
            if "/songs/" in href:
                self._a_href = href
                self._buf    = []

    def handle_endtag(self, tag):
        """Capture the completed song entry when the link tag closes."""
        if tag == "a" and self._a_href:
            # We've reached the end of a song link — save the title and URL
            self.songs.append(("".join(self._buf).strip(), self._a_href))
            self._a_href = None
            self._buf    = []
        if tag in ("h2", "h3"):
            # Exit heading context
            self._in_h = False

    def handle_data(self, data):
        """Accumulate text content when inside a song link."""
        if self._a_href:
            self._buf.append(data)


class SongParser(html.parser.HTMLParser):
    """
    Parser for individual song detail pages on missionpraise.com.

    Each song page contains:
    - A title in an element with class "entry-title"
    - Lyrics in <p> blocks inside a div with class "song-details"
    - Copyright info in an element with class "copyright-info"
    - Download links in a sidebar with class "col-sm-4"

    The parser uses depth tracking to know when it has fully exited each
    section's container element.

    Lyrics detection:
    - Each <p> inside .song-details represents a line or stanza break
    - <em>/<i> tags indicate chorus lines (italic in the original)
    - Empty <p> tags represent stanza breaks between verses
    - <br> tags represent line breaks within a verse

    Download link detection:
    - Links in the .col-sm-4 sidebar with text containing "words", "music",
      or "audio" are captured as download URLs

    Attributes:
        title (str):      The song title
        verses (list):    List of (text, is_italic) tuples
        copyright (str):  Copyright notice text
        downloads (dict): Map of type ("words"/"music"/"audio") → URL
    """
    def __init__(self):
        super().__init__()
        # --- Public output attributes ---
        self.title     = ""            # Song title from .entry-title
        self.verses    = []            # List of (text, is_italic) tuples
        self.copyright = ""            # Copyright notice text
        self.downloads = {}            # Download links: type → URL

        # --- Section tracking state ---
        # The parser tracks which content section we're currently inside:
        # "title", "song", or "copyright" (or None when outside all sections).
        # Depth tracking tells us when we've exited the section's container.
        self._section    = None        # Current section: "title"/"song"/"copyright"/None
        self._depth      = 0           # Depth counter within current section
        self._in_p       = False       # True when inside a <p> tag in the song section
        self._buf        = []          # Text buffer for title and copyright sections
        self._verse_buf  = []          # Text buffer for lyrics within a <p> tag

        # --- Italic (chorus) tracking ---
        # On missionpraise.com, chorus lines are rendered in italic (<em>/<i>).
        # We track whether any italic text appears in the current <p> to mark
        # entire verses as chorus lines in the output.
        self._in_em        = False     # True when inside an <em> or <i> tag
        self._verse_has_em = False     # True if current verse contains any italic text

        # --- Download link tracking ---
        # Download links are in a sidebar div with class "col-sm-4".
        # We track entry into this container and capture <a> tags within it.
        self._in_files   = False       # True when inside the download sidebar
        self._files_depth = 0          # Depth counter within the sidebar
        self._in_a       = False       # True when inside an <a> tag in the sidebar
        self._a_href     = None        # The href of the current download link
        self._a_buf      = []          # Text buffer for the link label

    def _classes(self, attrs):
        """Extract CSS class names from an HTML tag's attribute list."""
        return dict(attrs).get("class", "").split()

    def _flush(self):
        """Drain the text buffer and return its contents as a stripped string."""
        t = "".join(self._buf).strip()
        self._buf = []
        return t

    def handle_starttag(self, tag, attrs):
        """
        Called for each opening HTML tag. Routes processing based on the
        current parsing state (download sidebar, title, song, or copyright).

        Args:
            tag:   HTML tag name
            attrs: List of (name, value) attribute tuples
        """
        cls  = self._classes(attrs)
        attr = dict(attrs)

        # --- DOWNLOAD SIDEBAR ---
        # The download links (words/music/audio) are in a Bootstrap column
        # with class "col-sm-4" in the page sidebar. On some pages the
        # sidebar uses class "files" instead (inside a <section> tag).
        if "col-sm-4" in cls or "files" in cls:
            self._in_files    = True
            self._files_depth = 1
        elif self._in_files:
            # Track depth inside the sidebar to know when we exit.
            # Skip void elements — same rationale as section depth tracking.
            if tag not in VOID_ELEMENTS:
                self._files_depth += 1
            # Capture <a> tags within the sidebar that have href attributes
            if tag == "a" and attr.get("href"):
                self._in_a   = True
                self._a_href = attr["href"]
                self._a_buf  = []

        # --- CONTENT SECTIONS ---
        # Detect entry into the three content sections by their CSS classes.
        # Each section uses depth tracking (self._depth) to know when we've
        # fully exited the container element.
        if "entry-title" in cls:
            self._section = "title";  self._depth = 1;  return
        if "song-details" in cls:
            self._section = "song";   self._depth = 1;  return
        if "copyright-info" in cls:
            self._section = "copyright"; self._depth = 1; return

        # If we're inside a section, track nested tag depth.
        # IMPORTANT: void elements (<br>, <img>, <hr>, etc.) must NOT
        # increment depth because they have no closing tag — without
        # this guard, each <br> inflates the counter by +1 with no
        # matching -1 in handle_endtag, preventing the parser from
        # detecting when it has exited the section container.
        if self._section:
            if tag not in VOID_ELEMENTS:
                self._depth += 1

            if self._section == "song" and not self._in_files:
                # --- Song lyrics section (outside download sidebar) ---
                # Guard: skip verse capture when inside the col-sm-4
                # download sidebar to prevent sidebar link text (e.g.
                # "Music file") from being captured as lyrics, which
                # can trigger false attribution detection downstream.
                if tag == "p":
                    # IMPLICIT CLOSE: if a <p> opens while another is already
                    # open (common in missionpraise.com HTML where bare <P> tags
                    # separate verses without closing the previous one), flush
                    # the current verse buffer before starting the new paragraph.
                    # Without this, only the last paragraph's content is captured
                    # because earlier buffers are silently overwritten.
                    if self._in_p and self._verse_buf:
                        verse = "".join(self._verse_buf).strip()
                        self.verses.append((verse, self._verse_has_em))

                    # Starting a new paragraph — each <p> is either a lyric line,
                    # a stanza break (empty <p>), or an attribution line
                    self._in_p        = True
                    self._verse_buf   = []
                    # Inherit the outer <em> state. On some songs, the chorus
                    # is wrapped as <em><p>line</p><p>line</p></em> rather than
                    # <p><em>line</em></p>. In that case, _in_em is already True
                    # when the <p> starts, so we propagate it here. If instead
                    # the <em> appears inside this <p>, handle_starttag("em")
                    # below will set _verse_has_em = True as before.
                    self._verse_has_em = self._in_em

                if tag in ("em", "i") and self._in_p:
                    # Italic text within a verse indicates a chorus line
                    self._in_em        = True
                    self._verse_has_em = True

                if tag == "br" and self._in_p:
                    # <br> within a <p> represents a line break within a verse.
                    # Guard: the site often emits <BR><br /> (double break) on
                    # each line — collapse consecutive newlines to avoid blanks.
                    if not self._verse_buf or self._verse_buf[-1] != "\n":
                        self._verse_buf.append("\n")

            # Handle <br> in title and copyright sections
            if tag == "br" and self._section in ("title", "copyright"):
                self._buf.append("\n")

    def handle_endtag(self, tag):
        """
        Called for each closing HTML tag. Handles extraction of completed
        content when sections and subsections close.

        Args:
            tag: HTML tag name being closed
        """
        # --- DOWNLOAD SIDEBAR ---
        if self._in_files:
            if tag == "a" and self._in_a:
                # The download link has closed — determine its type from the
                # link text (which contains "words", "music", or "audio")
                label = "".join(self._a_buf).strip().lower()
                href  = self._a_href
                if href and href != "#":
                    # Match the link to a download type based on its label text
                    for key in ("words", "music", "audio"):
                        if key in label:
                            self.downloads[key] = href
                            break
                self._in_a   = False
                self._a_href = None
                self._a_buf  = []

            # Track depth to detect when we've exited the sidebar container.
            # Ignore void element close tags (shouldn't appear, but be safe).
            if tag not in VOID_ELEMENTS:
                self._files_depth -= 1
                if self._files_depth <= 0:
                    self._in_files = False

        # --- CONTENT SECTIONS ---
        if not self._section:
            return

        # Ignore void element close tags for section depth tracking.
        # Void elements should never appear as </br> etc., but malformed
        # HTML can include them — ignoring prevents depth counter underflow.
        if tag in VOID_ELEMENTS:
            return

        # Track italic tag closure within song lyrics
        if self._section == "song" and tag in ("em", "i"):
            self._in_em = False

        # Handle verse completion when a <p> tag closes inside the song section
        if self._section == "song" and tag == "p" and self._in_p:
            verse = "".join(self._verse_buf).strip()
            # We keep empty strings as stanza-break markers so that format_lyrics()
            # can distinguish between "lines within a stanza" (which should be joined)
            # and "stanza gaps" (which should be separated by blank lines).
            # Each verse is stored as a (text, is_italic) tuple where italic = chorus.
            self.verses.append((verse, self._verse_has_em))
            self._in_p     = False
            self._verse_buf = []

        # Track section depth — when we reach 0, we've exited the section container
        self._depth -= 1
        if self._depth == 0:
            text = self._flush()
            if self._section == "title" and not self.title:
                self.title = text
            elif self._section == "copyright" and not self.copyright:
                self.copyright = text
            self._section = None

    def handle_startendtag(self, tag, attrs):
        """
        Called for self-closing tags like <br/> or <img ... />.

        The default HTMLParser implementation calls handle_starttag() then
        handle_endtag(), which would double-count depth for void elements.
        Since we now skip void elements in both handlers, we only need to
        call handle_starttag() to process the tag's content effects (e.g.
        appending "\\n" for <br/> in lyrics) without any depth side effects.
        """
        self.handle_starttag(tag, attrs)

    def handle_data(self, data):
        """
        Called for plain text content between HTML tags. Routes text to
        the appropriate buffer based on current parsing state.

        Args:
            data: Text content string
        """
        # Download link text (to determine the type: words/music/audio)
        if self._in_a:
            self._a_buf.append(data)
        # Lyrics text within a <p> in the song section.
        # Guard: exclude text inside the download sidebar (col-sm-4) to prevent
        # sidebar link labels (e.g. "Music file") from being captured as verse
        # content, which would trigger false attribution detection in format_lyrics().
        if self._section == "song" and self._in_p and not self._in_files:
            self._verse_buf.append(data)
        # Title or copyright text
        elif self._section in ("title", "copyright"):
            self._buf.append(data)

    def _entity(self, name=None, num=None):
        """
        Convert an HTML entity to its Unicode character.

        Handles both named entities (e.g. &amp;, &rsquo;) and numeric
        character references (e.g. &#8217;). Common typographic entities
        found in song lyrics include smart quotes and dashes.

        Args:
            name: Named entity (e.g. "rsquo") — mutually exclusive with num
            num:  Numeric character code (e.g. 8217)

        Returns:
            str: The Unicode character, or "" if not recognised
        """
        if num is not None:
            try: return chr(num)
            except: return ""
        return {"amp":"&","lt":"<","gt":">","quot":'"',"apos":"'","nbsp":" ",
                "rsquo":"\u2019","lsquo":"\u2018","rdquo":"\u201d","ldquo":"\u201c",
                "mdash":"\u2014","ndash":"\u2013"}.get(name,"")

    def handle_entityref(self, name):
        """Handle named HTML entities like &amp; or &rsquo;."""
        ch = self._entity(name=name)
        self._push(ch)

    def handle_charref(self, name):
        """Handle numeric character references like &#8217; or &#x2019;."""
        try: num = int(name[1:],16) if name.startswith("x") else int(name)
        except: return
        self._push(self._entity(num=num))

    def _push(self, ch):
        """
        Route a character to the appropriate buffer(s) based on parsing state.

        Note: download link text and section text can overlap (a download link
        could technically appear inside a section), so we check multiple
        conditions independently rather than using elif.

        Args:
            ch: The character to append to the buffer(s)
        """
        if not ch: return
        if self._in_a:                                                self._a_buf.append(ch)
        if self._section == "song" and self._in_p and not self._in_files:  self._verse_buf.append(ch)
        elif self._section in ("title","copyright"):                  self._buf.append(ch)


# ---------------------------------------------------------------------------
# Fetch helpers — HTTP request and response handling utilities
# ---------------------------------------------------------------------------

def _decode_response(resp):
    """
    Read an HTTP response, decompress it if gzip-encoded, and decode to text.

    Many web servers (including WordPress/Sucuri) compress responses with gzip.
    This function handles both cases:
    1. Server sets Content-Encoding: gzip header
    2. Response body starts with gzip magic bytes (0x1f 0x8b) regardless of header

    The second check is a defensive fallback — some servers/proxies don't set
    the header correctly even when the content is compressed.

    Args:
        resp: An HTTP response object (from urllib.request.urlopen)

    Returns:
        str: The decoded text content of the response
    """
    raw = resp.read()
    encoding = resp.headers.get("Content-Encoding", "").lower()
    # Check both the header and the magic bytes — defensive against
    # misconfigured servers that compress without setting the header
    if encoding == "gzip" or raw[:2] == b'\x1f\x8b':
        raw = gzip.decompress(raw)
    return raw.decode("utf-8", errors="replace")


def fetch_text(opener, url):
    """
    Fetch a URL and return its text content, handling errors gracefully.

    Args:
        opener: urllib opener with cookie/session support
        url:    The URL to fetch

    Returns:
        tuple: (text_content, final_url) on success
               (None, None) on any error
    """
    try:
        with opener.open(url, timeout=20) as resp:
            return _decode_response(resp), resp.url
    except Exception as e:
        print(f"\n  [!] Error fetching {url}: {e}")
        return None, None


def fetch_binary(opener, url):
    """
    Download a binary file (words/music/audio) from a URL.

    Includes several safety checks:
    1. Decompresses gzip responses (some servers compress file downloads)
    2. Detects server error pages masquerading as file downloads — some
       servers return HTML error pages with a 200 status code when the
       actual file is missing or access is denied

    The error detection heuristic checks if the response:
    - Starts with common text/HTML bytes ('<', 's', 'e', '{')
    - Is small enough to be an error page (< 50KB)
    - Contains error-related keywords when decoded as UTF-8

    Args:
        opener: urllib opener with cookie/session support
        url:    The download URL

    Returns:
        tuple: (bytes_data, content_type, final_url) on success
               (None, None, None) on any error
    """
    try:
        with opener.open(url, timeout=30) as resp:
            # Extract the MIME type from Content-Type (strip charset parameter)
            ct  = resp.headers.get("Content-Type", "").split(";")[0].strip().lower()
            raw = resp.read()

            # Decompress gzip if the server compressed the download
            encoding = resp.headers.get("Content-Encoding", "").lower()
            if encoding == "gzip" or raw[:2] == b'\x1f\x8b':
                raw = gzip.decompress(raw)

            # --- Error page detection ---
            # Some servers return HTML error pages (PHP exceptions, 404 pages, etc.)
            # with a 200 status code instead of the actual file. We detect these by
            # checking if the content looks like text/HTML rather than binary data.
            # The size threshold is 500KB — PHP error dumps with full AWS S3 stack
            # traces can be 200KB+, so the previous 50KB limit missed them.
            if raw[:1] in (b'<', b's', b'e', b'{') and len(raw) < 500000:
                try:
                    text = raw.decode("utf-8", errors="strict")
                    if any(kw in text.lower() for kw in ("exception", "<html", "<!doctype", "error", "not found", "string(", "nosuchkey", "stacktrace")):
                        print(f"server error", end=" ")
                        if DEBUG:
                            debug_dump("Server error in download", text, max_chars=500)
                        return None, None, None
                except UnicodeDecodeError:
                    pass  # Not valid UTF-8 → genuinely binary data, carry on

            return raw, ct, resp.url
    except Exception as e:
        print(f"\n  [!] Download error {url}: {e}")
        return None, None, None


def ext_from_url(url):
    """
    Guess the file extension from a URL's path component.

    Parses the URL to extract the path, then gets the extension from the
    last path segment. Server-side script extensions (.php, .asp, etc.)
    are ignored because they indicate dynamic download endpoints rather
    than actual file types.

    Args:
        url: The download URL to extract the extension from

    Returns:
        str: The lowercase file extension (e.g. ".rtf") or "" if none found

    Example:
        "https://example.com/download.php?id=123"  → "" (php is ignored)
        "https://example.com/files/song.rtf"        → ".rtf"
    """
    path = urllib.parse.urlparse(url).path
    _, ext = os.path.splitext(path)
    ext = ext.lower()
    # Ignore server-side script extensions — these are dynamic endpoints
    # that serve files, not the actual file extension
    if ext in (".php", ".asp", ".aspx", ".jsp", ".cgi", ".py"):
        return ""
    return ext if ext else ""


# ---------------------------------------------------------------------------
# Magic bytes — file type detection from binary content
# ---------------------------------------------------------------------------
# When the Content-Type header is unhelpful (e.g. "application/octet-stream")
# and the URL doesn't reveal the file type, we can identify the format by
# examining the first few bytes of the file content. Most file formats begin
# with a distinctive "magic number" or signature.
MAGIC_BYTES = [
    (b"%PDF",                       ".pdf"),    # PDF files start with %PDF
    (b"PK\x03\x04",                ".docx"),   # ZIP-based formats (docx, xlsx, pptx)
    (b"\xd0\xcf\x11\xe0",          ".doc"),    # OLE2 compound files (doc, xls, ppt)
    (b"{\\rtf",                     ".rtf"),    # Rich Text Format
    (b"MThd",                       ".mid"),    # MIDI music files
    (b"ID3",                        ".mp3"),    # MP3 with ID3v2 metadata header
    (b"\xff\xfb",                   ".mp3"),    # MP3 frame sync (MPEG1 Layer 3)
    (b"\xff\xf3",                   ".mp3"),    # MP3 frame sync (MPEG2 Layer 3)
    (b"OggS",                       ".ogg"),    # Ogg Vorbis audio container
    (b"RIFF",                       ".wav"),    # WAV audio (RIFF container format)
    (b"fLaC",                       ".flac"),   # FLAC lossless audio
]


def ext_from_magic(data):
    """
    Detect file type from the first few bytes of binary data (magic bytes).

    Compares the file's opening bytes against known signatures for common
    document and audio formats. This is the last-resort detection method
    when Content-Type and URL are both unhelpful.

    Args:
        data: The raw bytes of the downloaded file (at least 4 bytes needed)

    Returns:
        str: The detected file extension (e.g. ".pdf") or "" if not recognised
    """
    if not data or len(data) < 4:
        return ""
    for magic, ext in MAGIC_BYTES:
        if data[:len(magic)] == magic:
            return ext
    return ""


def ext_for_download(content_type, url, data=None):
    """
    Determine the correct file extension for a download using a cascade strategy.

    Tries three methods in order of reliability:
    1. MIME type from the Content-Type header (most reliable)
    2. File extension from the URL path (fallback)
    3. Magic bytes from the file content (last resort)

    If none of the methods identify the file type, defaults to ".bin" to
    ensure the file is still saved (can be manually identified later).

    Args:
        content_type: The Content-Type header value (e.g. "application/pdf")
        url:          The download URL
        data:         The raw file bytes (optional, for magic byte detection)

    Returns:
        str: The file extension including the dot (e.g. ".pdf", ".rtf", ".bin")
    """
    # Strategy 1: Check the MIME type lookup table
    ext = MIME_TO_EXT.get(content_type, "")
    if not ext:
        # Strategy 2: Extract extension from the URL path
        ext = ext_from_url(url)
    if not ext and data:
        # Strategy 3: Detect from magic bytes in the file content
        ext = ext_from_magic(data)
    # Fallback: use .bin so the file is still saved for manual inspection
    return ext if ext else ".bin"


# ---------------------------------------------------------------------------
# Book helpers — extract and clean book-specific data from song titles
# ---------------------------------------------------------------------------

def extract_number(title, book):
    """
    Extract the hymn number from a song title string for a specific book.

    Song titles on the index page include the book code and number in
    parentheses, e.g. "Amazing Grace (MP0023)". This function uses the
    book-specific regex pattern to extract just the numeric part.

    Args:
        title: The full song title from the index page
        book:  Book identifier key ("mp", "cp", or "jp")

    Returns:
        int:  The hymn number (e.g. 23 from "MP0023")
        None: If no matching book code was found in the title
    """
    cfg = BOOK_CONFIG[book]
    m   = re.search(cfg["pattern"], title, re.I)
    return int(m.group(1)) if m else None


def detect_book(title):
    """
    Determine which book a song belongs to based on its title.

    Checks the title against each book's regex pattern (MP, CP, JP)
    and returns the first match.

    Args:
        title: The full song title from the index page

    Returns:
        str:  Book key ("mp", "cp", or "jp") if a match is found
        None: If the title doesn't match any known book pattern
    """
    for book in BOOK_CONFIG:
        if extract_number(title, book) is not None:
            return book
    return None


def clean_title(title, book):
    """
    Remove the book code suffix from a song title.

    Strips the "(MP0023)" or similar suffix from the end of the title,
    leaving just the human-readable song name for use in filenames
    and formatted output.

    Args:
        title: The full song title (e.g. "Amazing Grace (MP0023)")
        book:  Book identifier key ("mp", "cp", or "jp")

    Returns:
        str: The cleaned title (e.g. "Amazing Grace")

    How it works:
        The book's regex pattern captures the number with a group: (MP(\\d+))
        We replace the capture group with a non-capturing \\d+ so the regex
        matches the entire suffix "(MP0023)" without needing the exact number.
        Then we strip that match (plus any surrounding whitespace) from the
        end of the title string.
    """
    cfg = BOOK_CONFIG[book]
    # Convert the capturing group pattern to a simple match pattern
    # e.g. r'\(MP(\d+)\)' → r'\(MP\d+\)' (no longer captures, just matches)
    pat = cfg["pattern"].replace(r'(\d+)', r'\d+')
    # Remove the book code suffix from the end of the title
    t = re.sub(r'\s*' + pat + r'\s*$', '', title, flags=re.I).strip()
    return t


# ---------------------------------------------------------------------------
# Format, save lyrics & download files
# ---------------------------------------------------------------------------

def format_lyrics(song, book):
    """
    Format a parsed song into a clean plain-text string for saving.

    This is the most complex formatting function in the scraper because
    the source HTML has several structural quirks that need handling:

    1. STANZA GROUPING: On missionpraise.com, each lyric line is its own <p>
       element, and stanza breaks are represented by empty <p> tags. The
       parser preserves this as empty strings in the verses list, which
       this function uses to group lines into stanzas.

    2. CHORUS DETECTION: Chorus lines are rendered in italic (<em>/<i>) on
       the site. The parser flags each verse with is_italic=True if it
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

    5. MULTI-LINE VERSES: Some verses contain <br> tags (represented as \\n
       in the parser output), meaning multiple lines in a single <p>.
       These are treated as complete stanzas on their own.

    Output format:
        "Song Title"
                                ← blank line
        First verse line        ← single-line verses grouped into stanzas
        Second verse line
                                ← blank line between stanzas
        Chorus:                 ← prefix for italic stanzas
        Chorus line one
        Chorus line two
                                ← blank line
        ...
                                ← double blank line before attribution
        Words: Author Name
        Music: Composer Name
                                ← blank line
        Copyright notice

    Args:
        song: Dict with "title", "verses" (list of (text, is_italic) tuples),
              "copyright" (str), and "downloads" (dict)
        book: Book identifier key ("mp", "cp", or "jp")

    Returns:
        str: The formatted plain-text song content
    """
    title = clean_title(song["title"], book)
    lines = [f'"{title}"', ""]  # Start with quoted title and blank line

    # --- Stanza/attribution tracking ---
    # We need to separate actual lyrics from author/attribution lines that
    # appear after the last verse. We also need to group single-line verses
    # into stanzas (separated by empty <p> elements in the source).
    author_lines = []              # Accumulated attribution lines
    found_attribution = False      # Flag: have we started seeing attribution lines?
    stanza_buf = []                # Lines of the current stanza being built
    stanza_is_chorus = False       # Does the current stanza contain chorus (italic) lines?

    def flush_stanza():
        """
        Write the accumulated stanza to the output lines list.

        If the stanza contains italic lines, prepends "Chorus:" label —
        UNLESS the first line starts with a verse number (e.g. "2 Lord, I
        come..."), which indicates a numbered verse that happens to contain
        a small italic element (like the verse number itself in <em>).

        Adds a blank line after the stanza for separation.
        Uses nonlocal to modify the enclosing function's stanza_buf
        and stanza_is_chorus variables.
        """
        nonlocal stanza_buf, stanza_is_chorus
        if not stanza_buf:
            return
        if stanza_is_chorus:
            # Guard against false positives: if the stanza's first line starts
            # with a digit (verse number), it's a numbered verse, not a chorus.
            # Some songs wrap verse numbers in <em> for styling, which triggers
            # the italic flag incorrectly (e.g. "<em>2</em> Lord, I come...").
            first_line = stanza_buf[0].strip()
            if not re.match(r'^\d+\s', first_line):
                lines.append("Chorus:")
        lines.append("\n".join(stanza_buf))
        lines.append("")                        # Blank line between stanzas
        stanza_buf = []
        stanza_is_chorus = False

    # --- Consecutive empty tracking ---
    # On missionpraise.com, single empty <p> elements appear between EVERY
    # lyric line for CSS spacing, not just at actual stanza boundaries. If we
    # flushed on every empty <p>, each line would become its own "stanza" with
    # a blank line after it (double-spaced output). Instead, we count
    # consecutive empties and only flush when there's strong evidence of a
    # real stanza boundary:
    #   - 2+ consecutive empties (structural break in the HTML)
    #   - Italic state change (verse ↔ chorus transition)
    #   - Verse number at start of the next line (new numbered verse)
    consecutive_empties = 0

    # Process each verse (paragraph) from the parsed HTML
    for verse_text, is_italic in song["verses"]:
        stripped = verse_text.strip()

        # --- Empty verse: count but don't flush yet ---
        # We defer the stanza-break decision until we see the next content
        # line, so we can use content-based heuristics to decide whether
        # the empty <p> was a true stanza break or just a line spacer.
        if not stripped:
            consecutive_empties += 1
            continue

        # --- Stanza break decision ---
        # Now that we have a non-empty verse, decide whether the preceding
        # empty <p> element(s) represent a real stanza break.
        if stanza_buf and consecutive_empties > 0:
            should_break = (
                consecutive_empties >= 2                              # structural break
                or is_italic != stanza_is_chorus                     # verse↔chorus
                or bool(re.match(r'^\d+\s', stripped))               # verse number
            )
            if should_break:
                flush_stanza()
        consecutive_empties = 0

        # --- Attribution line detection ---
        # Lines starting with "Words:", "Music:", "Arranged:", etc. are
        # author/composer credits, not lyrics. We require a colon, "by",
        # or "and" after the keyword to prevent false positives (e.g.
        # sidebar text "Music file" or lyrics starting with "Words of...").
        # Once we find a genuine attribution line, subsequent short lines
        # are also treated as attribution — but ONLY if they don't look
        # like lyrics (i.e. they don't start with a digit/verse number).
        is_explicit_attribution = bool(re.match(
            r'^(Words|Music|Arranged|Words and music|Based on|Translated|Paraphrase)\s*[:&\-/by]',
            stripped, re.I
        ))
        # Also match bare keywords followed by author names (e.g. "Words and Music Stuart Townend")
        if not is_explicit_attribution:
            is_explicit_attribution = bool(re.match(
                r'^(Words and music|Words & music)\b', stripped, re.I
            ))
        is_attribution = (
            is_explicit_attribution
            or (found_attribution
                and "\n" not in stripped
                and not re.match(r'^\d+\s', stripped)   # Don't swallow numbered verses
                and len(stripped) < 120)                 # Don't swallow long lyric lines
        )
        if is_attribution:
            flush_stanza()              # Ensure any pending stanza is written
            found_attribution = True
            author_lines.append(stripped)
            continue

        # If we see a normal lyric line after attribution, reset the cascade.
        # This prevents a single false-positive attribution from consuming
        # all remaining lyrics in the song.
        if found_attribution and re.match(r'^\d+\s', stripped):
            found_attribution = False

        # --- Standalone author lines ---
        # Some attribution lines don't follow the "Words:" pattern but are
        # recognisable as author names by containing "/" or "&" separators
        # (e.g. "Stuart Townend / Keith Getty"). We use several heuristics:
        # - Single line (no internal line breaks)
        # - Doesn't start with a digit (not a verse number)
        # - Contains "/" or "&" (name separators)
        # - Short enough to be a name, not lyrics (< 120 chars)
        # - Not a typical lyric pattern (doesn't contain common verse words)
        if ("\n" not in stripped
                and not re.match(r'^\d', stripped)
                and ("/" in stripped or "&" in stripped)
                and len(stripped) < 120
                and not re.search(r'\b(the|and|you|your|my|our|lord|god|love|sing|praise)\b', stripped, re.I)):
            flush_stanza()
            author_lines.append(stripped)
            continue

        # --- Multi-line verse ---
        # If the verse text contains "\n" (from <br> tags in the HTML),
        # it's a multi-line verse that should be treated as its own stanza.
        if "\n" in stripped:
            flush_stanza()  # Flush any single-line stanza we were building
            # Clean up each line within the verse (strip whitespace)
            cleaned = "\n".join(l.strip() for l in stripped.split("\n"))
            if is_italic:
                # Guard against false positives: skip "Chorus:" if the first
                # line starts with a digit (numbered verse, not chorus)
                first_line = cleaned.split("\n")[0].strip()
                if not re.match(r'^\d+\s', first_line):
                    lines.append("Chorus:")
            lines.append(cleaned)
            lines.append("")  # Blank line after the stanza
        else:
            # --- Single-line verse ---
            # Accumulate into the current stanza buffer. The stanza will be
            # flushed when we encounter an empty verse (stanza break) or a
            # different type of content.
            if is_italic:
                stanza_is_chorus = True
            stanza_buf.append(stripped)

    # Flush any remaining stanza that wasn't terminated by an empty verse
    flush_stanza()

    # Remove trailing blank lines from the lyrics section
    while lines and lines[-1] == "":
        lines.pop()

    # Append author attribution lines (separated from lyrics by double blank line)
    if author_lines:
        lines.append("")   # First blank line
        lines.append("")   # Second blank line (visual separation)
        for al in author_lines:
            lines.append(al)

    # Append copyright notice if available
    if song.get("copyright"):
        lines.append("")
        lines.append(song["copyright"])

    # --- Post-processing: normalise spaced ellipses ---
    # WordPress's text rendering sometimes converts "..." to ". . ." with
    # spaces between the dots. We normalise these back to standard "..."
    # The regex matches patterns like ". . ." or " . . ." with 2+ dots.
    result = "\n".join(lines)
    result = re.sub(r' ?(?:\. ){2,}\.', '...', result)

    return result


def sanitize(name):
    """
    Remove characters that are invalid in filenames across operating systems.

    Strips characters that are forbidden in Windows filenames and/or could
    cause issues on other platforms: \\ / * ? : " < > |

    Args:
        name: The raw string to sanitize (typically a song title)

    Returns:
        str: The sanitized string with invalid characters removed
    """
    return re.sub(r'[\\/*?:"<>|]', "", name).strip()


def title_case(s):
    """
    Convert a string to Title Case, with correct handling of apostrophes.

    Python's built-in str.title() treats apostrophes as word boundaries,
    producing incorrect results like "Don'T" instead of "Don't". This
    function uses a regex to match whole words (including contractions
    like "don't", "it's", "o'er") and capitalises each correctly.

    The regex pattern [a-zA-Z]+(['\u2019\u2018][a-zA-Z]+)? matches:
    - One or more letters (the main word)
    - Optionally an apostrophe followed by more letters (contraction)

    We include Unicode curly/smart apostrophes (\u2019 RIGHT SINGLE QUOTATION
    MARK and \u2018 LEFT SINGLE QUOTATION MARK) because the HTML entity decoder
    converts &rsquo; to \u2019. Without this, "Eagle\u2019s" would be split into
    separate words, producing "Eagle'S" instead of "Eagle's".

    str.capitalize() uppercases the first character and lowercases the rest.

    Args:
        s: The input string

    Returns:
        str: The Title Cased string

    Examples:
        "AMAZING GRACE"      → "Amazing Grace"
        "don't let me down"  → "Don't Let Me Down"
        "EAGLE\u2019S WINGS" → "Eagle\u2019s Wings"
    """
    return re.sub(r"[a-zA-Z]+(['\u2019\u2018][a-zA-Z]+)?", lambda m: m.group(0).capitalize(), s)


def base_filename(number, book, title):
    """
    Construct the base filename for a song (without file extension).

    Generates a consistent filename from the song's book, number, and title.
    The title is sanitized and converted to Title Case. The number is
    zero-padded according to the book's configuration (MP=4 digits, CP/JP=3).

    Args:
        number: The song number (e.g. 23)
        book:   Book identifier key ("mp", "cp", or "jp")
        title:  The cleaned song title (book code already removed)

    Returns:
        str: The base filename, e.g. "0023 (MP) - Amazing Grace"
    """
    cfg    = BOOK_CONFIG[book]
    padded = str(number).zfill(cfg["pad"])   # Zero-pad: 23 → "0023" (for MP)
    label  = cfg["label"]                     # Book label: "MP", "CP", or "JP"
    return f"{padded} ({label}) - {title_case(sanitize(title))}"


def build_file_cache(output_dir):
    """
    Build a set of existing filenames in a directory for quick lookup.

    Called once at startup to avoid repeated os.listdir() calls during
    the scrape. Returns a set for O(1) membership testing.

    Args:
        output_dir: Path to the directory to scan

    Returns:
        set: Set of filename strings (not full paths), or empty set if
             the directory doesn't exist
    """
    if os.path.isdir(output_dir):
        return set(os.listdir(output_dir))
    return set()


def already_saved_lyrics(number, book, file_cache):
    """
    Check if lyrics for a specific song have already been saved.

    Uses the cached file listing for O(1) lookup instead of checking
    the filesystem directly. Matches files by their prefix pattern
    (number + label) and .txt extension.

    Args:
        number:     The song number
        book:       Book identifier key
        file_cache: Set of existing filenames (from build_file_cache)

    Returns:
        bool: True if a matching .txt file exists in the cache
    """
    cfg    = BOOK_CONFIG[book]
    padded = str(number).zfill(cfg["pad"])
    label  = cfg["label"]
    # Build the prefix that all files for this song start with
    prefix = f"{padded} ({label}) -"
    return any(f.startswith(prefix) and f.endswith(".txt") for f in file_cache)


def already_saved_download(base, dl_type, file_cache):
    """
    Check if a specific download file (words/music/audio) already exists.

    Words files use the base name directly (base.rtf), while music and
    audio files add a type suffix (base_music.pdf, base_audio.mp3).

    Args:
        base:       The base filename (from base_filename())
        dl_type:    Download type ("words", "music", or "audio")
        file_cache: Set of existing filenames

    Returns:
        bool: True if a matching file exists in the cache
    """
    # Words files: "base.ext", other types: "base_type.ext"
    prefix = f"{base}." if dl_type == "words" else f"{base}_{dl_type}."
    return any(f.startswith(prefix) for f in file_cache)


def save_lyrics(song, number, book, output_dir):
    """
    Format and save a song's lyrics to a plain-text file.

    Creates the output directory if needed, generates the filename,
    formats the lyrics, and writes the file.

    Args:
        song:       Parsed song dict with "title", "verses", "copyright"
        number:     The song number (e.g. 23)
        book:       Book identifier key ("mp", "cp", or "jp")
        output_dir: Directory to save the file in

    Returns:
        str: The base filename (without extension) — returned so that
             download files can reuse the same base name
    """
    os.makedirs(output_dir, exist_ok=True)
    title    = clean_title(song["title"], book)
    base     = base_filename(number, book, title)
    filepath = os.path.join(output_dir, base + ".txt")
    with open(filepath, "w", encoding="utf-8") as f:
        f.write(format_lyrics(song, book))
    return base   # Return base so file downloads can reuse the same name


def save_download(data, base, dl_type, content_type, dl_url, output_dir):
    """
    Save a downloaded binary file (words, music, or audio) to disk.

    Naming convention:
    - Words (primary):  {base}.rtf       (same name as lyrics, different ext)
    - Music:            {base}_music.pdf  (suffix distinguishes from words)
    - Audio:            {base}_audio.mp3  (suffix distinguishes from words)

    The extension is determined by ext_for_download() using a cascade of
    Content-Type → URL extension → magic bytes detection.

    Args:
        data:         Raw bytes of the downloaded file
        base:         Base filename (from base_filename())
        dl_type:      Download type ("words", "music", or "audio")
        content_type: MIME type from the Content-Type header
        dl_url:       The download URL (for extension guessing fallback)
        output_dir:   Directory to save the file in

    Returns:
        str: The complete filename (with extension) that was saved
    """
    ext      = ext_for_download(content_type, dl_url, data)
    # Map type to a suffix — but words files use no suffix (they're the primary)
    suffix   = {"words": "_words", "music": "_music", "audio": "_audio"}.get(dl_type, f"_{dl_type}")
    # Words files share the same base name as lyrics (just different extension):
    #   "0023 (MP) - Amazing Grace.rtf"
    # Music and audio files add a type suffix to avoid extension conflicts:
    #   "0023 (MP) - Amazing Grace_music.pdf"
    #   "0023 (MP) - Amazing Grace_audio.mp3"
    if dl_type == "words":
        filename = base + ext
    else:
        filename = base + f"_{dl_type}" + ext
    filepath = os.path.join(output_dir, filename)
    with open(filepath, "wb") as f:
        f.write(data)
    return filename


# ---------------------------------------------------------------------------
# Process a single song — orchestrates scraping + downloading for one song
# ---------------------------------------------------------------------------

def log_skip(output_dir, label, padded, title, url, reason):
    """
    Record a skipped song in the skipped.log file for later review.

    Creates a persistent log of songs that couldn't be scraped, with
    timestamps, identifiers, and reasons. Useful for identifying gaps
    and diagnosing systematic issues.

    Args:
        output_dir: Directory to write the log file in
        label:      Book label (e.g. "MP")
        padded:     Zero-padded song number string (e.g. "0023")
        title:      The song title from the index page
        url:        The song page URL that was attempted
        reason:     Human-readable explanation of why it was skipped

    Log format:
        [2026-03-13 14:30:00]  MP0023  Amazing Grace  —  login wall  —  https://...
    """
    os.makedirs(output_dir, exist_ok=True)
    log_path = os.path.join(output_dir, "skipped.log")
    timestamp = time.strftime("%Y-%m-%d %H:%M:%S")
    with open(log_path, "a", encoding="utf-8") as f:
        f.write(f"[{timestamp}]  {label}{padded}  {title}  —  {reason}  —  {url}\n")


def _dump_skip_html(book_dir, label, padded, title, url, reason, html_text):
    """
    Write a diagnostic HTML dump for a skipped song so the raw server
    response can be inspected to determine why the scrape failed.

    Args:
        book_dir:  Output directory for the book
        label:     Book label (e.g. "JP")
        padded:    Zero-padded song number
        title:     Song title from the index page
        url:       The song page URL
        reason:    Why the song was skipped
        html_text: Raw HTML response (may be None for empty responses)
    """
    try:
        os.makedirs(book_dir, exist_ok=True)
        diag_path = os.path.join(book_dir, f"_debug_{label}{padded}_skipped.html")
        with open(diag_path, "w", encoding="utf-8") as f:
            f.write(f"<!-- SKIPPED: {label}{padded} -->\n")
            f.write(f"<!-- Song: {title} -->\n")
            f.write(f"<!-- URL: {url} -->\n")
            f.write(f"<!-- Reason: {reason} -->\n")
            f.write(f"<!-- HTML length: {len(html_text) if html_text else 0} -->\n\n")
            if html_text:
                f.write(html_text)
            else:
                f.write("<!-- (no HTML received — empty/null response) -->\n")
        print(f" [diag: {diag_path}]", end="", flush=True)
    except Exception as e:
        if DEBUG:
            print(f" [skip diag failed: {e}]", end="", flush=True)


def process_song(opener, title, url, book, output_dir, no_files, delay, file_cache):
    """
    Scrape lyrics and download files for a single song.

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

    Args:
        opener:     urllib opener with active login session
        title:      Song title from the index page (includes book code)
        url:        URL of the song detail page
        book:       Book identifier key ("mp", "cp", or "jp")
        output_dir: Base output directory
        no_files:   If True, skip downloading words/music/audio files
        delay:      Seconds to wait between requests
        file_cache: Mutable set of existing filenames (updated when new files saved)

    Returns:
        str: "saved" if lyrics were newly saved
             "skipped" if the song couldn't be scraped
             "exists" if the song was already saved from a previous run
    """
    # Extract the hymn number from the title (e.g. "Amazing Grace (MP0023)" → 23)
    number = extract_number(title, book)
    cfg    = BOOK_CONFIG[book]

    # Route output into a book-specific subdirectory
    book_dir = os.path.join(output_dir, cfg["subdir"])

    if number is None:
        # Title doesn't contain a recognisable book code — can't save this song
        log_skip(book_dir, "??", "????", title, url, "no book number found in title")
        return "skipped"

    padded = str(number).zfill(cfg["pad"])
    label  = cfg["label"]

    # --- Check if lyrics already exist ---
    lyrics_exist = already_saved_lyrics(number, book, file_cache)

    # If lyrics exist and we don't need files, skip entirely (no network request)
    if lyrics_exist and no_files:
        return "exists"

    # If lyrics exist, check whether downloads also exist
    if lyrics_exist:
        clean = clean_title(title, book)
        base  = base_filename(number, book, clean)
        # Look for any non-txt file with the same base name
        has_any_download = any(
            f.startswith(f"{base}.") or f.startswith(f"{base}_")
            for f in file_cache if not f.endswith(".txt")
        )
        if no_files or has_any_download:
            return "exists"
        # Lyrics exist but no downloads — need to fetch page for download links
        print(f"    {label}{padded}  {title[:55]:<55}", end=" ", flush=True)
        print("↻ fetching missing downloads...", end=" ", flush=True)
    else:
        # Neither lyrics nor files exist — full scrape needed
        print(f"    {label}{padded}  {title[:55]:<55}", end=" ", flush=True)

    # --- Fetch the song detail page ---
    html_text, _ = fetch_text(opener, url)

    # Check for login wall (session may have expired during the scrape)
    if not html_text or "Please login to continue" in html_text or "loginform" in (html_text or ""):
        reason = "login wall" if html_text else "empty response"
        print(f"✗ ({reason})")
        log_skip(book_dir, label, padded, title, url, reason)
        _dump_skip_html(book_dir, label, padded, title, url, reason, html_text)
        time.sleep(delay)
        return "skipped"

    # Check for subscription paywall (song not included in user's plan)
    if "not part of your subscription" in html_text:
        print("✗ (not in subscription)")
        log_skip(book_dir, label, padded, title, url, "song not part of subscription")
        _dump_skip_html(book_dir, label, padded, title, url, "not in subscription", html_text)
        time.sleep(delay)
        return "skipped"

    # Check for WAF block on the song page (can happen on individual pages)
    if "sucuri" in html_text.lower() or "access denied" in html_text.lower():
        print("✗ (blocked by firewall)")
        log_skip(book_dir, label, padded, title, url, "blocked by WAF/firewall")
        _dump_skip_html(book_dir, label, padded, title, url, "blocked by WAF/firewall", html_text)
        time.sleep(delay)
        return "skipped"

    # --- Parse the song page HTML ---
    sp = SongParser()
    sp.feed(html_text)

    # If the parser couldn't find a title, retry once — this may be a transient
    # issue like a session hiccup or a partially-loaded page
    if not sp.title:
        time.sleep(delay * 2)  # Longer delay before retry
        html_text, _ = fetch_text(opener, url)
        if html_text and "loginform" not in html_text:
            sp = SongParser()
            sp.feed(html_text)

    # If still no title from entry-title class, try fallbacks:
    # 1. Extract from the HTML <title> tag (strip " – Mission Praise" suffix)
    # 2. Use the index page title (already available as the 'title' parameter)
    if not sp.title and html_text:
        title_match = re.search(r'<title>([^<]+)</title>', html_text, re.I)
        if title_match:
            import html as html_mod
            raw_title = html_mod.unescape(title_match.group(1))
            # Strip the site name suffix (e.g. " – Mission Praise" or " - Mission Praise")
            raw_title = re.sub(r'\s*[\u2013\u2014\-]\s*Mission Praise\s*$', '', raw_title).strip()
            if raw_title:
                sp.title = raw_title
                print("(title from <title> tag)", end=" ", flush=True)

    # Final fallback: use the title from the index page
    if not sp.title:
        # Extract just the song name from the index title (strip book code like "(MP1270)")
        fallback_title = re.sub(r'\s*\([A-Z]+\d+\)\s*$', '', title).strip()
        if fallback_title:
            sp.title = fallback_title
            print("(title from index)", end=" ", flush=True)

    # If still no title after all fallbacks, skip this song
    if not sp.title:
        print("✗ (no title parsed)")
        log_skip(book_dir, label, padded, title, url, "parser found no title in page HTML")
        _dump_skip_html(book_dir, label, padded, title, url, "no title parsed", html_text)
        time.sleep(delay)
        return "skipped"

    # Build the structured song data dict
    song = {"title": sp.title, "verses": sp.verses,
            "copyright": sp.copyright.strip(), "downloads": sp.downloads}

    # --- Verse count validation ---
    # Count actual text lines (not <p> entries) to detect incomplete parses.
    # Many songs use a few large <p> blocks with <br> line breaks inside,
    # so counting entries would produce false "incomplete" warnings. Instead,
    # we count the total lines of text across all verse entries.
    total_lines = sum(v.strip().count("\n") + 1 for v, _ in sp.verses if v.strip())
    if total_lines == 0:
        print("⚠ (no lyrics found)", end=" ", flush=True)
        log_skip(book_dir, label, padded, title, url,
                 f"parser found title but 0 lyric lines — possible HTML structure change")
    elif total_lines < 4:
        print(f"⚠ ({total_lines} lines — may be incomplete)", end=" ", flush=True)
    if DEBUG and sp.verses:
        non_empty_entries = sum(1 for v, _ in sp.verses if v.strip())
        print(f"\n  DEBUG: {len(sp.verses)} raw verse entries, "
              f"{non_empty_entries} non-empty, {total_lines} text lines", flush=True)

    # --- HTML diagnostic dump for incomplete songs ---
    # When the parser captures suspiciously few lines, or when running in
    # single-song mode (--song), dump the raw HTML surrounding the song-details
    # section to a diagnostic file. This helps identify HTML structure changes.
    # Single-song mode always dumps because it's inherently a debugging operation.
    is_single_song = (len(sys.argv) > 1 and "--song" in " ".join(sys.argv))
    if (total_lines < 8 or is_single_song) and html_text:
        diag_path = os.path.join(book_dir, f"_debug_{label}{padded}_raw.html")
        try:
            os.makedirs(book_dir, exist_ok=True)
            # Extract the song-details section via regex as a diagnostic cross-check.
            # Match both <div> and <section> tags — the site uses both inconsistently.
            sd_match = re.search(
                r'<(?:div|section)[^>]+class=["\x27][^"\x27]*song-details[^"\x27]*["\x27][^>]*>'
                r'([\s\S]*?)'
                r'(?=<[^>]+class=["\x27][^"\x27]*(?:copyright-info|col-sm-4|files))',
                html_text, re.I)
            if not sd_match:
                # Fallback: simpler regex
                sd_match = re.search(
                    r'<(?:div|section)[^>]+class=["\x27][^"\x27]*song-details[^"\x27]*["\x27][^>]*>'
                    r'([\s\S]*?)</(?:div|section)>',
                    html_text, re.I)
            with open(diag_path, "w", encoding="utf-8") as f:
                f.write(f"<!-- Song: {title} -->\n")
                f.write(f"<!-- URL: {url} -->\n")
                f.write(f"<!-- Parser found {total_lines} text lines, "
                        f"{len(sp.verses)} verse entries -->\n\n")
                if sd_match:
                    f.write("<!-- === song-details content (regex extract) === -->\n")
                    f.write(sd_match.group(0) + "\n\n")
                    # Also count <p> tags in the regex extract as cross-check
                    p_count = len(re.findall(r'<p[^>]*>', sd_match.group(0), re.I))
                    f.write(f"<!-- Regex found {p_count} <p> tags in song-details -->\n\n")
                else:
                    f.write("<!-- WARNING: regex could NOT find song-details div! -->\n\n")
                f.write("<!-- === Raw verse entries from HTMLParser === -->\n")
                for idx, (v, it) in enumerate(sp.verses):
                    f.write(f"<!-- Verse[{idx}] italic={it}: {repr(v[:200])} -->\n")
                f.write(f"\n<!-- === Full page HTML ({len(html_text)} chars) === -->\n")
                f.write(html_text)
            print(f"[diag: {diag_path}]", end=" ", flush=True)
        except Exception as e:
            print(f"[diag write failed: {e}]", end=" ", flush=True)

    # --- Save lyrics ---
    if lyrics_exist:
        # Lyrics already saved — just re-derive the base name for download files
        base = base_filename(number, book, clean_title(sp.title, book))
    elif total_lines == 0:
        # No actual lyrics content — don't save an empty lyrics file.
        # The debug raw HTML file (if generated above) is kept for reference.
        print("⚠ (empty — lyrics file not saved)", end=" ", flush=True)
        base = base_filename(number, book, clean_title(sp.title, book))
        log_skip(book_dir, label, padded, title, url,
                 "no lyrics content on page — only title/attribution")
    else:
        # Save lyrics to .txt file and add to the file cache
        base = save_lyrics(song, number, book, book_dir)
        file_cache.add(base + ".txt")

    # --- Download files (words, music, audio) ---
    file_results = []  # Track download results for the status line
    if not no_files and song["downloads"]:
        for dl_type in ("words", "music", "audio"):
            dl_url = song["downloads"].get(dl_type)
            if not dl_url:
                continue  # This download type isn't available for this song

            # Skip if already downloaded
            if already_saved_download(base, dl_type, file_cache):
                file_results.append(dl_type[0].lower())  # Lowercase = already existed
                continue

            # Convert relative URLs to absolute
            if dl_url.startswith("/"):
                dl_url = BASE + dl_url

            # Download the file
            data, ct, final_url = fetch_binary(opener, dl_url)
            if data:
                fname = save_download(data, base, dl_type, ct, final_url or dl_url, book_dir)
                file_results.append(dl_type[0].upper())  # Uppercase = newly downloaded
                file_cache.add(fname)  # Add to cache so we don't re-download

            # Brief delay between downloads (30% of normal delay)
            time.sleep(delay * 0.3)

    # Print the status line with download indicators:
    # [W,M,A] = newly downloaded words/music/audio (uppercase)
    # [w,m,a] = already existed (lowercase)
    file_str = f" [{','.join(file_results)}]" if file_results else ""
    print(f"✓{file_str}")
    time.sleep(delay)  # Rate limit between songs
    return "saved"


# ---------------------------------------------------------------------------
# Crawl & scrape — paginated index crawling with per-page song processing
# ---------------------------------------------------------------------------

def crawl_and_scrape(opener, books, output_dir, no_files, start_page=1, delay=DELAY, force=False, song_number=None):
    """
    Crawl the paginated song index and scrape each discovered song.

    The missionpraise.com song index is paginated (10 songs per page).
    This function:
    1. Fetches each index page sequentially
    2. Parses the page to discover song links
    3. Filters songs to only the requested books (MP, CP, JP)
    4. Scrapes each song on the current page before moving to the next

    End-of-index detection:
    - "Page not found" in the response → no more pages
    - WordPress serving page 1 content for out-of-range page numbers
      (detected by checking the "X-Y of Z" counter in the HTML)
    - No song links found on the page
    - Empty response

    The function is designed for resumability:
    - Builds a file cache of existing files on startup
    - Supports --start-page for resuming from a specific index page
    - Each song is individually checked for existing files

    Args:
        opener:      urllib opener with active login session
        books:       List of book keys to scrape (e.g. ["mp", "cp", "jp"])
        output_dir:  Base output directory
        no_files:    If True, skip file downloads
        start_page:  Index page number to start from (default: 1)
        delay:       Seconds between requests
        force:       If True, re-download and overwrite existing files
        song_number: If set, only scrape this specific song number (e.g. 584)

    Returns:
        tuple: (saved_count, skipped_count, existed_count)
    """
    page       = start_page

    # Pre-compile book pattern regexes for efficient matching
    book_pats  = {b: re.compile(BOOK_CONFIG[b]["pattern"], re.I) for b in books}

    # Build a combined file cache from all book subdirectories.
    # Filenames are unique across books because they include the book label,
    # so merging into one set is safe and simplifies lookup logic.
    # When --force is set, skip the cache entirely so everything is re-downloaded.
    file_cache = set()
    if not force:
        for b in books:
            subdir = os.path.join(output_dir, BOOK_CONFIG[b]["subdir"])
            file_cache |= build_file_cache(subdir)  # Union with existing cache

    # Counters for the final summary
    saved      = 0   # Songs newly saved in this run
    skipped    = 0   # Songs that couldn't be scraped
    existed    = 0   # Songs that already existed from previous runs

    if song_number:
        print(f"  Single-song mode: looking for song #{song_number}\n")
        force = True  # Always re-download in single-song mode
    if force:
        print(f"  Force mode: will re-download and overwrite existing files.\n")
    elif file_cache:
        print(f"  Found {len(file_cache)} existing files across output folders — will skip duplicates.\n")

    # --- Main pagination loop ---
    while True:
        # Construct the URL for the current index page
        url  = f"{INDEX_URL}{page}/"
        html_text, _ = fetch_text(opener, url)

        # Detect end of pagination
        if not html_text or "Page not found" in html_text:
            if DEBUG:
                print(f"  DEBUG: Page {page} — {'empty response' if not html_text else 'Page not found detected'}")
            break

        # Dump the first page's HTML for debugging (only on start_page)
        if page == start_page:
            debug_dump(f"Index page {page} HTML", html_text)

        # --- Loop detection ---
        # WordPress has a quirk where out-of-range page numbers silently serve
        # page 1 content (instead of returning a 404). We detect this by parsing
        # the "Showing X-Y of Z" counter in the HTML.
        count_m = re.search(r'(\d+)-(\d+)\s+of\s+(\d+)', html_text)
        if count_m:
            lo, hi, total = int(count_m.group(1)), int(count_m.group(2)), int(count_m.group(3))
            total_pages   = (total + 9) // 10  # Ceiling division: songs ÷ 10 per page

            # Print total count on the first page
            if page == start_page:
                print(f"  {total} total songs across ~{total_pages} pages\n")

            # Detect loops: if lo > hi (impossible) or we're past page 1 but
            # seeing results starting from 1 (WordPress served page 1 again)
            if lo > hi or (page > 1 and lo == 1):
                break
        else:
            # If we can't find the counter and we're past page 1, assume we've
            # gone beyond the last page (page 1 might legitimately lack the counter)
            if page > 1:
                break

        # --- Parse the index page for song links ---
        p = IndexParser()
        p.feed(html_text)

        if not p.songs:
            # No song links found — we've reached the end of the index
            if DEBUG:
                print(f"  DEBUG: IndexParser found 0 song links on page {page}")
                # Debug: dump all <a> links to help diagnose parser issues
                links = re.findall(r'<a[^>]+href=["\']([^"\']*)["\'][^>]*>', html_text)
                song_links = [l for l in links if "/songs/" in l]
                print(f"  DEBUG: Total <a> links: {len(links)}, with /songs/: {len(song_links)}")
                for sl in song_links[:10]:
                    print(f"         {sl}")
            break

        # --- Filter songs to requested books ---
        # Each song title includes a book code like "(MP0023)" — we match
        # against the patterns for the books the user wants to scrape
        page_songs = []
        for title, song_url in p.songs:
            for book, pat in book_pats.items():
                if pat.search(title):
                    # If --song was specified, filter to only that song number
                    if song_number is not None:
                        num = extract_number(title, book)
                        if num != song_number:
                            break  # Skip — wrong number
                    page_songs.append((title, song_url, book))
                    break  # A song belongs to exactly one book

        # Print page summary with running totals
        if not song_number:
            print(f"  Page {page:4d}  —  {len(page_songs)} matching songs  "
                  f"(saved: {saved}, existed: {existed}, skipped: {skipped})")

        # --- Scrape each song on this page ---
        page_existed = 0
        for title, song_url, book in page_songs:
            result = process_song(opener, title, song_url, book, output_dir, no_files, delay, file_cache)
            if result == "saved":
                saved += 1
            elif result == "skipped":
                skipped += 1
            elif result == "exists":
                existed += 1
                page_existed += 1

        # If --song was specified and we found it, stop immediately
        if song_number is not None and (saved + skipped) > 0:
            break

        # If every song on this page already existed, print a compact summary
        # instead of per-song output (reduces noise during resumed scrapes)
        if page_existed == len(page_songs) and page_songs and not song_number:
            print(f"           ⏭  all {page_existed} songs on this page already exist")

        page += 1
        time.sleep(delay)   # Rate limit between index pages

    # If --song was specified but never found, warn the user
    if song_number is not None and saved == 0 and skipped == 0:
        print(f"\n  ⚠ Song #{song_number} was not found in the index for books: {', '.join(books)}")

    return saved, skipped, existed


# ---------------------------------------------------------------------------
# Main — CLI entry point and argument parsing
# ---------------------------------------------------------------------------

def main():
    """
    Parse command-line arguments, authenticate, and start the scrape.

    Handles the full lifecycle:
    1. Parse CLI arguments (or prompt for missing credentials)
    2. Validate the requested books
    3. Create an HTTP session and authenticate with the site
    4. Print configuration summary
    5. Run the crawl-and-scrape process
    6. Print final results
    """
    # Support -? as an alias for --help (common on Windows and DOS-style CLIs).
    # We intercept it here because argparse doesn't natively support '?' in
    # option strings. Note: users may need to quote or escape -? in their shell
    # (e.g. '-?' or -\?) since ? is a glob wildcard character in most shells.
    if "-?" in sys.argv:
        sys.argv[sys.argv.index("-?")] = "--help"

    ap = argparse.ArgumentParser(
        description="Mission Praise scraper — MP, CP, JP + file downloads",
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog="""examples:
  python3 %(prog)s --username you@email.com --password secret
                                           Scrape all books (MP, CP, JP)
  python3 %(prog)s --username you@email.com --password secret --books mp
                                           Scrape only Mission Praise
  python3 %(prog)s --username you@email.com --password secret --books mp,cp
                                           Scrape Mission Praise + Carol Praise
  python3 %(prog)s --username you@email.com --password secret --no-files
                                           Scrape lyrics only (skip downloads)
  python3 %(prog)s --username you@email.com --password secret --start-page 5
                                           Resume from index page 5
  python3 %(prog)s --username you@email.com --password secret --output ~/hymns
                                           Save to a custom output folder
  python3 %(prog)s --username you@email.com --password secret --song 584
                                           Scrape only song number 584
  python3 %(prog)s --username you@email.com --password secret --debug
                                           Dump HTML responses for debugging

output:
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

authentication:
  A valid missionpraise.com subscription is required. Credentials can be
  provided via --username/--password flags, or entered interactively at
  the prompt (password input is hidden). The site may be protected by a
  Sucuri WAF — if blocked, try logging in via a browser first.

notes:
  - No dependencies required — uses only Python standard library modules.
  - The scraper crawls the paginated song index (10 songs per page).
  - Downloads include words (RTF/DOC), music (PDF), and audio (MP3/MIDI).
  - Use --no-files to skip downloads and only scrape lyrics text.""")
    ap.add_argument("--username",   default=None,
                    help="missionpraise.com login email (prompted interactively if omitted)")
    ap.add_argument("--password",   default=None,
                    help="missionpraise.com password (prompted with hidden input if omitted)")
    ap.add_argument("--output",     default=DEFAULT_OUT,
                    help="output folder path (default: ./hymns)")
    ap.add_argument("--books",      default="mp,cp,jp",
                    help="comma-separated books to scrape: mp, cp, jp (default: mp,cp,jp)")
    ap.add_argument("--start-page", type=int, default=1,
                    help="index page number to start from, for resuming (default: 1)")
    ap.add_argument("--delay",      type=float, default=DELAY,
                    help="seconds to wait between HTTP requests (default: 1.2)")
    ap.add_argument("--song",       type=int, default=None,
                    help="scrape only this song number (e.g. --song 584 --books jp)")
    ap.add_argument("--no-files",   action="store_true",
                    help="skip downloading words/music/audio files (lyrics only)")
    ap.add_argument("--debug",      action="store_true",
                    help="dump full HTML responses to terminal for troubleshooting")
    ap.add_argument("--force",      action="store_true",
                    help="force re-download of all songs, even if files already exist")
    args = ap.parse_args()

    # Set the global debug flag (used by debug_dump and various functions)
    global DEBUG
    DEBUG = args.debug

    # Prompt for credentials if not provided via CLI arguments.
    # getpass.getpass() hides the password as the user types it.
    if not args.username:
        args.username = input("Mission Praise username/email: ").strip()
    if not args.password:
        args.password = getpass.getpass("Mission Praise password: ")

    # Parse and validate the books argument (filter out invalid entries)
    books = [b.strip().lower() for b in args.books.split(",") if b.strip().lower() in BOOK_CONFIG]
    if not books:
        print("No valid books specified. Use --books mp,cp,jp")
        return

    # Create the HTTP session (cookie jar + browser-like headers)
    opener, jar = make_opener()

    # Authenticate with the site
    if not login(opener, jar, args.username, args.password):
        return  # Login failed — error already printed by login()

    # Print configuration summary
    print(f"\nBooks    : {', '.join(b.upper() for b in books)}")
    print(f"Output   : {os.path.abspath(args.output)}")
    print(f"Downloads: {'disabled' if args.no_files else 'enabled (words, music, audio)'}")
    print()

    # Run the main crawl-and-scrape process
    saved, skipped, existed = crawl_and_scrape(
        opener, books, args.output, args.no_files, args.start_page, args.delay, args.force,
        song_number=args.song)

    # Print final summary
    print(f"\nDone!  {saved} saved, {existed} already existed, {skipped} skipped.")
    print(f"Output: {os.path.abspath(args.output)}")
    if skipped:
        log_path = os.path.join(args.output, "skipped.log")
        print(f"Skipped songs logged to: {log_path}")


if __name__ == "__main__":
    main()
