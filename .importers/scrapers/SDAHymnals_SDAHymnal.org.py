#!/usr/bin/env python3
"""
SDAHymnals_SDAHymnal.org.py
importers/scrapers/SDAHymnals_SDAHymnal.org.py

Hymnal Scraper — scrapes both sdahymnal.org (SDAH) and hymnal.xyz (CH).
Copyright 2025-2026 MWBM Partners Ltd.

Overview:
    This scraper fetches hymn lyrics from two Seventh-day Adventist hymnal
    websites that share the same underlying codebase (identical HTML structure
    and CSS class names). It iterates through hymn numbers sequentially,
    parses the HTML to extract the title, section indicators (e.g. "Verse 1",
    "Chorus"), and lyrics text, then saves each hymn as a plain-text file.

    The scraper is designed to be resumable: it scans the output directory for
    existing files on startup and skips hymns that have already been saved.
    It also handles rate limiting, server errors, and auto-detects the end
    of the hymnal when the site redirects to the homepage.

Dependencies:
    None — uses only Python standard library modules (no pip install needed).
    This is intentional so the script can run on any system with Python 3.6+.

Output format:
    Each hymn is saved as a plain-text file with the naming convention:
        {number zero-padded to 3 digits} ({LABEL}) - {Title Case Title}.txt
    e.g.: "001 (SDAH) - Praise To The Lord.txt"

    Files are organised into book-specific subdirectories:
        hymns/Seventh-day Adventist Hymnal [SDAH]/
        hymns/The Church Hymnal [CH]/

Usage:
    python3 sdah_scraper.py                         # Scrape both sites from hymn 1
    python3 sdah_scraper.py --site sdah             # Only sdahymnal.org
    python3 sdah_scraper.py --site ch               # Only hymnal.xyz
    python3 sdah_scraper.py --start 50              # Resume from hymn 50
    python3 sdah_scraper.py --start 1 --end 100     # Specific range
    python3 sdah_scraper.py --output ~/Desktop/hymns
"""

# ---------------------------------------------------------------------------
# Standard library imports (no third-party dependencies required)
# ---------------------------------------------------------------------------
import urllib.request   # For making HTTP requests to fetch hymn pages
import urllib.error     # For handling HTTP error responses (404, 500, etc.)
import html.parser      # Base class for our custom HTML parser (no BeautifulSoup needed)
import sys

# Force line-buffered stdout so progress messages appear immediately in the
# terminal, even when output is piped or redirected to a file. Without this,
# Python buffers stdout in block mode when not connected to a TTY, which
# means the user wouldn't see real-time progress updates.
sys.stdout.reconfigure(line_buffering=True)

import os               # File system operations (makedirs, path joining, etc.)
import re               # Regular expressions for text cleaning and parsing
import time             # For rate-limiting delays between requests
import argparse         # Command-line argument parsing


# ---------------------------------------------------------------------------
# Site configuration — both sites share the same HTML structure
# ---------------------------------------------------------------------------
# Both sdahymnal.org and hymnal.xyz are built on the same web platform,
# so they use identical CSS class names and page layouts. The same
# .NET-based codebase also powers a network of multilingual sister
# sites (#699), which means a single parser class (HymnParser) can
# cover them all. Each site entry defines:
#   - base_url:  The hymn page URL template (hymn number passed as ?no=N)
#   - home_url:  The site homepage (used to detect end-of-hymnal redirects)
#   - label:     Short identifier used in output filenames, e.g. "SDAH"
#   - subdir:    Human-readable subdirectory name for organised output
#   - lang:      IETF BCP 47 tag for the songbook's primary language.
#                Plain ISO 639-1 ("en", "fr", "pt") is fine; the picker
#                in the song editor reconciles to a full BCP 47 tag.
#
# #699 Phase B finding: the South-Slavic sister sites (himne.net /
# hristianskipesni.com / hristijanskipesni.com / pesmarica.net /
# pjesme.net) DO use the same parser markers — but their songbook
# index paths (e.g. /HAC-pesmarica) only render a search form. The
# actual hymn-display URL is a separate path discovered by submitting
# the search-by-number form for hymn 1 and observing the redirect:
#
#   himne.net               /Himna?no=N              (SR Latin)
#   hristianskipesni.com    /%D0%9F%D0%B5%D1%81%D0%B5%D0%BD?no=N (BG: Песен)
#   hristijanskipesni.com   /%D0%9F%D0%B5%D1%81%D0%BD%D0%B0?no=N (MK: Песна)
#   pesmarica.net           /%D0%A5%D0%B8%D0%BC%D0%BD%D0%B0?no=N (SR Cyrl: Химна)
#   pjesme.net              /Himna?no=N              (HR)
#
# These five sites are wired in below as keys hac / hp / hjp / pes / pj.
SITES = {
    "sdah": {
        "base_url":  "https://www.sdahymnal.org/Hymn",
        "home_url":  "https://www.sdahymnal.org",
        "label":     "SDAH",
        "subdir":    "Seventh-day Adventist Hymnal [SDAH]",
        "lang":      "en",
    },
    "ch": {
        "base_url":  "https://www.hymnal.xyz/Hymn",
        "home_url":  "https://www.hymnal.xyz",
        "label":     "CH",
        "subdir":    "The Church Hymnal [CH]",
        "lang":      "en",
    },
    # ---- #699 Phase A: multilingual sister sites confirmed to use the
    #      same DOM hooks (block-heading-three/four/five + wedding-heading).
    "ha": {
        "base_url":  "https://www.himnario.net/Himno",
        "home_url":  "https://www.himnario.net",
        "label":     "HA",
        "subdir":    "Himnario Adventista [HA]",
        "lang":      "es",
    },
    "nha": {
        # nuevohimnario.com is the modern Spanish hymnal — distinct from
        # the classic himnario.net (which we map to "ha" above) — so it
        # gets its own label + folder. Curators can run --site ha or
        # --site nha individually as needed.
        "base_url":  "https://www.nuevohimnario.com/Himno",
        "home_url":  "https://www.nuevohimnario.com",
        "label":     "NHA",
        "subdir":    "Nuevo Himnario Adventista [NHA]",
        "lang":      "es",
    },
    "hasd": {
        "base_url":  "https://www.hinarioadventista.com/Hino",
        "home_url":  "https://www.hinarioadventista.com",
        "label":     "HASD",
        "subdir":    "Hinario Adventista do Setimo Dia [HASD]",
        "lang":      "pt",
    },
    "hl": {
        # Note the URL is /Cantique?no=N, NOT /Hymne (which 200s but
        # serves an empty shell). The site's English-translated brand
        # is "Hymns and Songs of Praise" (Hymnes & Louanges).
        "base_url":  "https://www.hymnes.net/Cantique",
        "home_url":  "https://www.hymnes.net",
        "label":     "HL",
        "subdir":    "Hymnes et Louanges [HL]",
        "lang":      "fr",
    },
    "ia": {
        # innarioavventista.com matched 4 of 6 markers on probe — fewer
        # than the canonical 6 but still parseable. If a future probe
        # shows missing fields we may need a parser tweak; flagging this
        # under #699 Phase B for follow-up.
        "base_url":  "https://www.innarioavventista.com/Inno",
        "home_url":  "https://www.innarioavventista.com",
        "label":     "IA",
        "subdir":    "Innario Avventista [IA]",
        "lang":      "it",
    },
    # ---- #699 Phase B: sister sites where the *index* paths
    #      (HAC-pesmarica / Адвентната-песнарка / etc.) only serve a
    #      search form. The actual hymn-display URL is a separate path
    #      (Himna / Песен / Песна / Химна), discovered by submitting
    #      the search-by-number form for hymn 1 and observing the
    #      redirect target. Once you GET that URL with ?no=N you get
    #      the same DOM markers (block-heading-three/four/five +
    #      wedding-heading) the existing parser already handles.
    #
    #      Cyrillic-path sites use percent-encoded URLs in the
    #      base_url so the scraper's `f"{base_url}?no={n}"` doesn't
    #      need to round-trip through urllib's IRI handling.
    "hac": {
        # Serbian/Croatian (Latin script) — Hrišćanske Adventističke
        # himne via himne.net. The site exposes 3 sub-songbooks but
        # /Himna?no=N maps to the default (HAC-pesmarica).
        "base_url":  "https://www.himne.net/Himna",
        "home_url":  "https://www.himne.net",
        "label":     "HAC",
        "subdir":    "HAC pesmarica [HAC]",
        "lang":      "sr-Latn",   # Serbian, Latin script (composite IETF tag)
    },
    "hp": {
        # Bulgarian — Християнски песни via hristianskipesni.com.
        # Display URL is /Песен?no=N (BG: "song"), percent-encoded.
        "base_url":  "https://www.hristianskipesni.com/%D0%9F%D0%B5%D1%81%D0%B5%D0%BD",
        "home_url":  "https://www.hristianskipesni.com",
        "label":     "HP",
        "subdir":    "Hristianski Pesni [HP]",
        "lang":      "bg",
    },
    "hjp": {
        # Macedonian — Христијански песни via hristijanskipesni.com.
        # Display URL is /Песна?no=N.
        "base_url":  "https://www.hristijanskipesni.com/%D0%9F%D0%B5%D1%81%D0%BD%D0%B0",
        "home_url":  "https://www.hristijanskipesni.com",
        "label":     "HJP",
        "subdir":    "Hristijanski Pesni [HJP]",
        "lang":      "mk",
    },
    "pes": {
        # Serbian (Cyrillic) — Песмарица via pesmarica.net.
        # Display URL is /Химна?no=N (SR: "hymn").
        "base_url":  "https://www.pesmarica.net/%D0%A5%D0%B8%D0%BC%D0%BD%D0%B0",
        "home_url":  "https://www.pesmarica.net",
        "label":     "PES",
        "subdir":    "Pesmarica [PES]",
        "lang":      "sr-Cyrl",   # Serbian, Cyrillic script
    },
    "pj": {
        # Croatian — Kršćanske pjesme via pjesme.net.
        # Display URL is /Himna?no=N (HR: "hymn").
        "base_url":  "https://www.pjesme.net/Himna",
        "home_url":  "https://www.pjesme.net",
        "label":     "PJ",
        "subdir":    "Krscanske Pjesme [PJ]",
        "lang":      "hr",
    },
}

# Default output directory (relative to where the script is run from)
DEFAULT_OUTPUT_DIR = "./hymns"

# Delay in seconds between HTTP requests to be respectful to the server
# and avoid triggering rate limits. 1.0s is a reasonable balance between
# speed and politeness.
DELAY              = 1.0


# ---------------------------------------------------------------------------
# HTML Parser
# ---------------------------------------------------------------------------
# Both sdahymnal.org and hymnal.xyz share the same underlying codebase, so
# they use identical CSS class names for their hymn page structure. This
# parser navigates the DOM hierarchy using depth tracking to extract:
#
# 1. TITLE: Found inside a <strong> tag, nested within an <h3> with class
#    "wedding-heading", which is itself inside a <div> with class
#    "block-heading-four". We track this nested path using boolean flags
#    and depth counters.
#
# 2. SECTION INDICATORS: Found in <div class="block-heading-three">.
#    These are labels like "Verse 1", "Chorus", "Verse 2", etc.
#
# 3. LYRICS TEXT: Found in <div class="block-heading-five">.
#    Line breaks within lyrics are represented by <br> tags, which we
#    convert to newline characters.
#
# The parser pairs each indicator with its following lyrics block,
# producing a list of (indicator, lyrics) tuples in self.sections.
# ---------------------------------------------------------------------------

class HymnParser(html.parser.HTMLParser):
    """
    Custom HTML parser for sdahymnal.org / hymnal.xyz hymn pages.

    Extracts the hymn title and lyrics sections from the page HTML by
    tracking specific CSS class names used by the site's template.

    HTML structure being parsed:
        <div class="block-heading-four">        ← title container
            <h3 class="wedding-heading">        ← title wrapper
                <strong>Hymn Title Here</strong> ← actual title text
            </h3>
        </div>
        <div class="block-heading-three">       ← section indicator
            Verse 1                              ← e.g. "Verse 1", "Chorus"
        </div>
        <div class="block-heading-five">        ← lyrics block
            First line of lyrics<br>             ← <br> = line break
            Second line of lyrics<br>
        </div>

    Attributes:
        title (str):    The extracted hymn title (empty string if not found)
        sections (list): List of (indicator, lyrics) tuples where:
                         - indicator is a string like "Verse 1" or "" if none
                         - lyrics is the verse text with \n for line breaks
    """

    def __init__(self):
        super().__init__()
        # --- Public output attributes ---
        self.title      = ""    # The hymn title, extracted from <strong> in title path
        self.sections   = []    # List of (indicator_text, lyrics_text) tuples

        # --- Title extraction state ---
        # The title is deeply nested: div.block-heading-four > *.wedding-heading > strong
        # We need to track each nesting level to know when we've reached the <strong>.
        self._in_bh4    = False  # True when inside a div.block-heading-four element
        self._bh4_depth = 0      # Depth counter for tags inside block-heading-four;
                                  # when this reaches 0 again, we've exited the container
        self._in_wh     = False  # True when inside the .wedding-heading child element
        self._wh_depth  = 0      # Depth counter for tags inside wedding-heading
        self._in_strong = False  # True when inside the <strong> tag that holds the title

        # --- Lyrics / indicator extraction state ---
        # Indicators (div.block-heading-three) and lyrics (div.block-heading-five)
        # are sibling elements. We process them sequentially: when we encounter
        # an indicator, we store its text; when we encounter a lyrics block, we
        # pair it with the most recently seen indicator.
        self._current   = None   # Which section we're currently inside:
                                  #   "indicator" (block-heading-three) or
                                  #   "lyrics"    (block-heading-five) or
                                  #   None        (not inside either)
        self._depth     = 0      # Depth counter for tags inside the current section;
                                  # when this reaches 0 we've exited the section div
        self._buf       = []     # Text accumulation buffer for the current section
        self._indicator = ""     # Stores the most recently parsed indicator text,
                                  # to be paired with the next lyrics block

    def _classes(self, attrs):
        """
        Extract CSS class names from an HTML tag's attribute list.

        Args:
            attrs: List of (name, value) tuples from the HTML parser

        Returns:
            List of class name strings (empty list if no class attribute)

        Example:
            attrs = [("class", "block-heading-four wedding-heading"), ("id", "h1")]
            → ["block-heading-four", "wedding-heading"]
        """
        return dict(attrs).get("class", "").split()

    def _flush(self):
        """
        Drain the text buffer and return its contents as a stripped string.

        This is called when we've finished collecting text for a section
        (title, indicator, or lyrics) and need to extract the accumulated text.

        Returns:
            str: The concatenated and stripped buffer contents
        """
        text = "".join(self._buf).strip()
        self._buf = []
        return text

    def handle_starttag(self, tag, attrs):
        """
        Called by HTMLParser for each opening HTML tag.

        This method handles two independent tracking paths:

        1. TITLE PATH: Tracks entry into div.block-heading-four → *.wedding-heading
           → <strong>, using nested boolean flags and depth counters.

        2. LYRICS PATH: Detects div.block-heading-three (indicators) and
           div.block-heading-five (lyrics), tracking their depth to know
           when we've fully exited each container.

        Args:
            tag:   The HTML tag name (e.g. "div", "strong", "br")
            attrs: List of (attribute_name, attribute_value) tuples
        """
        cls = self._classes(attrs)

        # --- TITLE PATH ---
        # Step 1: Enter the title container (div.block-heading-four)
        if "block-heading-four" in cls:
            self._in_bh4    = True
            self._bh4_depth = 1       # We're 1 level deep (the container itself)
        elif self._in_bh4:
            # We're inside block-heading-four; track depth of nested tags
            self._bh4_depth += 1

            # Step 2: Look for the wedding-heading wrapper inside bh4
            if "wedding-heading" in cls and not self._in_wh:
                self._in_wh    = True
                self._wh_depth = 0    # Will increment as child tags are encountered
            elif self._in_wh:
                # We're inside wedding-heading; track its child depth
                self._wh_depth += 1

                # Step 3: Look for the <strong> tag that contains the actual title
                # Only capture if we haven't already found a title (first match wins)
                if tag == "strong" and not self.title:
                    self._in_strong = True

        # --- LYRICS PATH ---
        # If we're already inside a lyrics or indicator section, just track depth.
        # Also convert <br> tags to newlines within lyrics blocks.
        if self._current:
            self._depth += 1
            # Convert <br> tags to newline characters within lyrics sections
            # (the site uses <br> for line breaks within verses)
            if tag == "br" and self._current == "lyrics":
                self._buf.append("\n")
            return  # Don't check for new sections while inside one

        # Check if this tag opens a new indicator or lyrics section
        if "block-heading-three" in cls:
            # Indicator section: contains text like "Verse 1", "Chorus", etc.
            self._current = "indicator"
            self._depth   = 1
        elif "block-heading-five" in cls:
            # Lyrics section: contains the actual hymn lyrics text
            self._current = "lyrics"
            self._depth   = 1

    def handle_endtag(self, tag):
        """
        Called by HTMLParser for each closing HTML tag.

        Manages depth tracking for both the title path and lyrics path.
        When a section's depth reaches 0, we've fully exited it and can
        process the accumulated text.

        Args:
            tag: The HTML tag name being closed
        """
        # --- TITLE PATH ---
        # When the </strong> tag closes, extract the title text
        if self._in_strong and tag == "strong":
            self._in_strong = False
            if not self.title:
                # First <strong> inside the title path — this is our hymn title
                self.title = self._flush()
            else:
                # Subsequent <strong> tags (shouldn't happen, but be safe) — discard
                self._buf = []

        # Track depth within block-heading-four to detect when we leave it
        if self._in_bh4:
            self._bh4_depth -= 1
            if self._bh4_depth == 0:
                # We've fully exited the block-heading-four container
                self._in_bh4   = False
                self._in_wh    = False
                self._wh_depth = 0
            elif self._in_wh and self._wh_depth > 0:
                # Closing a child tag within wedding-heading
                self._wh_depth -= 1

        # --- LYRICS PATH ---
        if not self._current:
            return  # Not inside any lyrics/indicator section

        self._depth -= 1
        if self._depth == 0:
            # We've fully exited the current section div — process the text
            text = self._flush()

            if self._current == "indicator":
                # Store the indicator text (e.g. "Verse 1") to pair with
                # the next lyrics block that follows it
                self._indicator = text

            elif self._current == "lyrics":
                # Collapse runs of 3+ newlines down to 2 (clean up excessive whitespace
                # that can occur from empty <br> tags in the source HTML)
                text = re.sub(r'\n{3,}', '\n\n', text).strip()
                # Pair this lyrics block with its indicator and add to sections list
                self.sections.append((self._indicator, text))
                # Reset indicator for the next verse (some verses may not have one)
                self._indicator = ""

            # Mark that we've exited the section
            self._current = None

    def handle_data(self, data):
        """
        Called by HTMLParser for plain text content between HTML tags.

        Routes text to the appropriate buffer depending on what we're
        currently tracking (title extraction vs lyrics/indicator content).

        Args:
            data: The text content string
        """
        if self._in_strong and not self.title:
            # We're inside the <strong> tag in the title path — collect title text
            self._buf.append(data)
        elif self._current:
            # We're inside an indicator or lyrics section — collect section text
            self._buf.append(data)

    def _entity_char(self, name=None, num=None):
        """
        Convert an HTML entity to its Unicode character equivalent.

        Handles both named entities (e.g. &amp;, &rsquo;) and numeric
        character references (e.g. &#8217;, &#x2019;). This is needed
        because html.parser doesn't automatically convert all entities
        to characters — we need to handle them manually to preserve
        special characters like smart quotes and em dashes in the lyrics.

        Args:
            name: Named entity string (e.g. "amp", "rsquo") — mutually
                  exclusive with num
            num:  Numeric character code (e.g. 8217 for right single quote)

        Returns:
            str: The corresponding Unicode character, or "" if not recognised
        """
        # Handle numeric character references (e.g. &#8217; or &#x2019;)
        if num is not None:
            # Windows-1252 remapping: code points 128–159 are C1 control
            # characters in Unicode, but many legacy web pages use them to
            # mean the Windows-1252 characters (smart quotes, dashes, etc.).
            # Web browsers perform this remapping automatically; we do the
            # same here so that &#145; becomes a left single quote, etc.
            # Reference: https://html.spec.whatwg.org/#numeric-character-reference-end-state
            _WIN1252_MAP = {
                145: "\u2018",  # left single quotation mark
                146: "\u2019",  # right single quotation mark
                147: "\u201c",  # left double quotation mark
                148: "\u201d",  # right double quotation mark
                150: "\u2013",  # en dash
                151: "\u2014",  # em dash
            }
            if num in _WIN1252_MAP:
                return _WIN1252_MAP[num]
            try:
                return chr(num)
            except (ValueError, OverflowError):
                return ""

        # Handle named entities — map common HTML entities to their characters
        # This covers the most frequently encountered entities in hymn lyrics:
        # standard XML entities, typographic quotes, and dashes
        entities = {
            "amp": "&", "lt": "<", "gt": ">", "quot": '"', "apos": "'",
            "nbsp": " ",                                # non-breaking space → regular space
            "rsquo": "\u2019", "lsquo": "\u2018",      # right/left single smart quotes
            "rdquo": "\u201d", "ldquo": "\u201c",      # right/left double smart quotes
            "mdash": "\u2014", "ndash": "\u2013",      # em dash (—) and en dash (–)
        }
        return entities.get(name, "")

    def handle_entityref(self, name):
        """
        Called by HTMLParser for named HTML entities like &amp; or &rsquo;.

        Converts the entity to a character and appends it to the appropriate
        buffer if we're currently collecting text (title or lyrics section).

        Args:
            name: The entity name without & and ; (e.g. "amp", "rsquo")
        """
        ch = self._entity_char(name=name)
        if ch:
            # Route the character to the correct buffer based on parsing state
            if self._in_strong and not self.title:
                self._buf.append(ch)
            elif self._current:
                self._buf.append(ch)

    def handle_charref(self, name):
        """
        Called by HTMLParser for numeric character references like &#8217; or &#x2019;.

        Parses the numeric value (decimal or hex), converts it to a character,
        and appends it to the appropriate buffer.

        Args:
            name: The character reference without &# and ; (e.g. "8217" or "x2019")
        """
        try:
            # Hex references start with 'x' (e.g. &#x2019;), others are decimal
            num = int(name[1:], 16) if name.startswith("x") else int(name)
        except ValueError:
            return  # Malformed character reference — skip it
        ch = self._entity_char(num=num)
        if ch:
            # Route the character to the correct buffer based on parsing state
            if self._in_strong and not self.title:
                self._buf.append(ch)
            elif self._current:
                self._buf.append(ch)


# ---------------------------------------------------------------------------
# Fetch & parse — HTTP request handling and hymn data extraction
# ---------------------------------------------------------------------------

def fetch_hymn(number, base_url, home_url):
    """
    Fetch a single hymn page from the website and parse it into structured data.

    Makes an HTTP GET request to the hymn URL, handles various error conditions
    (server errors, rate limiting, redirects), and returns parsed hymn data.

    The function includes several resilience features:
    - Retries up to 3 times on HTTP 500 errors (with 3-second delays)
    - Detects rate-limiting pages and pauses 60 seconds before retrying
    - Detects end-of-hymnal by checking if the site redirects to the homepage
    - Falls back to latin-1 encoding if UTF-8 decoding fails

    Args:
        number:   The hymn number to fetch (e.g. 1, 42, 695)
        base_url: The site's hymn page base URL (e.g. "https://www.sdahymnal.org/Hymn")
        home_url: The site's homepage URL (used to detect end-of-hymnal redirects)

    Returns:
        dict:  {"number": int, "title": str, "sections": [(indicator, lyrics), ...]}
               on success
        "SKIP": If the hymn should be skipped (server error, no title found)
        None:   If scraping should stop entirely (reached end of hymnal or
                persistent rate limiting)
    """
    # Construct the hymn page URL using the query parameter format used by both sites
    url = f"{base_url}?no={number}"

    # Create a request with a polite User-Agent identifying the scraper
    req = urllib.request.Request(
        url,
        headers={"User-Agent": "Mozilla/5.0 (compatible; HymnScraper/2.0; personal use)"}
    )

    raw = None  # Will hold the raw response bytes if the request succeeds

    # Retry loop: attempt up to 3 times on HTTP 500 errors
    for attempt in range(1, 4):
        try:
            with urllib.request.urlopen(req, timeout=15) as resp:
                # Check if the server redirected us to the homepage — this means
                # the requested hymn number doesn't exist (we've gone past the
                # end of the hymnal). We only check for number > 1 because
                # hymn 1 should always exist.
                final_url = resp.url.rstrip("/")
                if "Hymn" not in final_url and number > 1:
                    print(f"\n  Hymn {number}: redirected to home — reached end.")
                    return None  # Signal to stop scraping entirely

                raw = resp.read()
            break  # Success — exit the retry loop

        except urllib.error.HTTPError as e:
            if e.code == 500:
                # Server error — may be transient, so retry with backoff
                if attempt < 3:
                    print(f"  Hymn {number}: server error (500), retrying ({attempt}/3)...", end=" ", flush=True)
                    time.sleep(3)  # Wait before retrying
                else:
                    # All 3 attempts failed — skip this hymn
                    print(f"  server error (500) after 3 attempts.")
                    return "SKIP"
            else:
                # Other HTTP errors (404, 403, etc.) — don't retry, just skip
                print(f"  HTTP {e.code}.")
                return "SKIP"

        except Exception as e:
            # Network errors, timeouts, etc. — skip this hymn
            print(f"  error: {e}")
            return "SKIP"

    # If raw is still None, all retry attempts were exhausted without success
    if raw is None:
        return "SKIP"

    # Decode the response bytes to text. Try UTF-8 first (standard for modern
    # websites), fall back to latin-1 if UTF-8 fails (latin-1 never raises
    # errors as every byte maps to a valid character).
    try:
        html_text = raw.decode("utf-8")
    except UnicodeDecodeError:
        html_text = raw.decode("latin-1")

    # Parse the HTML to extract hymn data
    parser = HymnParser()
    parser.feed(html_text)

    # --- Rate limit detection ---
    # Both sites show a "reached limit for today" message when you've made
    # too many requests. If detected, pause for 60 seconds and try once more.
    if "reached limit for today" in html_text.lower() or "we are sorry" in html_text.lower():
        print(f"  rate limit hit — pausing 60s...", flush=True)
        time.sleep(60)

        # One retry after the cooldown period
        try:
            with urllib.request.urlopen(req, timeout=15) as resp:
                raw2 = resp.read()
            html_text2 = raw2.decode("utf-8", errors="replace")

            # Check if we're still rate limited after waiting
            if "reached limit for today" in html_text2.lower():
                print(f"  Still rate limited — stopping. Try again tomorrow.")
                return None   # Stop entirely — no point continuing today

            # Rate limit cleared — re-parse with the fresh response
            html_text = html_text2
            parser = HymnParser()
            parser.feed(html_text)
        except Exception:
            return None  # Network error during retry — stop scraping

    # Validate that the parser found a title (indicates a valid hymn page)
    if not parser.title:
        print(f"  no title found.")
        return "SKIP"

    # Return the structured hymn data
    return {"number": number, "title": parser.title, "sections": parser.sections}


# ---------------------------------------------------------------------------
# Format & save — text formatting and file output
# ---------------------------------------------------------------------------

def format_hymn(hymn):
    """
    Format a parsed hymn dict into a clean plain-text string for saving.

    The output format is:
        "Hymn Title"
                                    ← blank line after title
        Verse 1                     ← indicator (if present)
        First line of lyrics        ← lyrics text
        Second line of lyrics
                                    ← blank line between sections
        Chorus                      ← next indicator
        Chorus lyrics here
        ...

    Args:
        hymn: Dict with keys "title" (str) and "sections" (list of tuples).
              Each section tuple is (indicator_str, lyrics_str).

    Returns:
        str: The formatted plain-text hymn content
    """
    # Start with the quoted title and a blank line
    lines = [f'"{hymn["title"]}"', ""]

    # Append each section (indicator + lyrics) with blank line separators
    for indicator, lyrics in hymn["sections"]:
        if indicator:
            lines.append(indicator)   # e.g. "Verse 1", "Chorus"
        if lyrics:
            lines.append(lyrics)      # The verse/chorus text
        lines.append("")              # Blank line between sections

    # Remove trailing blank lines (cleaner file ending)
    while lines and lines[-1] == "":
        lines.pop()

    return "\n".join(lines)


def sanitize(name):
    """
    Remove characters that are invalid in filenames across operating systems.

    Strips the following characters which are forbidden in Windows filenames
    and/or could cause issues on other platforms:
        \\ / * ? : " < > |

    Args:
        name: The raw string to sanitize (typically a hymn title)

    Returns:
        str: The sanitized string with invalid characters removed and
             leading/trailing whitespace stripped
    """
    return re.sub(r'[\\/*?:"<>|]', "", name).strip()


def title_case(s):
    """
    Convert a string to Title Case, with correct handling of apostrophes.

    Python's built-in str.title() method treats apostrophes as word boundaries,
    producing incorrect results like "Don'T" instead of "Don't". This function
    uses a regex to match whole words (including apostrophe contractions like
    "don't", "it's", "o'er") and capitalises each word correctly.

    The regex matches:
        [a-zA-Z]+              One or more letters (the main word)
        (['\u2019\u2018]       Optionally an apostrophe (ASCII ', right ' or left ')
         [a-zA-Z]+)?           followed by more letters (contraction suffix)

    We include Unicode curly/smart apostrophes (\u2019 RIGHT SINGLE QUOTATION
    MARK and \u2018 LEFT SINGLE QUOTATION MARK) because the HTML entity decoder
    converts &rsquo; to \u2019. Without this, "Eagle\u2019s" would be split into
    separate words, producing "Eagle'S" instead of "Eagle's".

    str.capitalize() is used on each match, which uppercases the first char
    and lowercases the rest — perfect for Title Case.

    Args:
        s: The input string to convert

    Returns:
        str: The Title Cased string

    Examples:
        "AMAZING GRACE"      → "Amazing Grace"
        "don't let me down"  → "Don't Let Me Down"
        "o'er the hills"     → "O'er The Hills"
        "EAGLE\u2019S WINGS" → "Eagle\u2019s Wings"
    """
    return re.sub(r"[a-zA-Z]+(['\u2019\u2018][a-zA-Z]+)?", lambda m: m.group(0).capitalize(), s)


def build_existing_set(label, output_dir):
    """
    Scan the output directory to find hymn numbers that have already been saved.

    This enables the scraper to resume from where it left off without
    re-downloading hymns. It looks for files matching the naming pattern
    "{number} ({label}) - {title}.txt" and extracts the hymn number from
    each matching filename.

    Args:
        label:      The book label to filter by (e.g. "SDAH", "CH")
        output_dir: Path to the directory to scan for existing files

    Returns:
        set: A set of integers representing hymn numbers already saved.
             Empty set if the directory doesn't exist or has no matching files.

    Example:
        If output_dir contains:
            "001 (SDAH) - Praise To The Lord.txt"
            "042 (SDAH) - A Mighty Fortress.txt"
        Returns: {1, 42}
    """
    existing = set()
    if os.path.isdir(output_dir):
        # Build the prefix tag to filter files belonging to this specific book
        prefix_tag = f"({label}) -"
        for fname in os.listdir(output_dir):
            # Match files that contain the book label and have .txt extension
            if prefix_tag in fname and fname.endswith(".txt"):
                # Extract the hymn number from the start of the filename
                # (the zero-padded number before the first space)
                num_str = fname.split()[0]
                if num_str.isdigit():
                    existing.add(int(num_str))
    return existing


def _normalise_for_diff(text):
    """
    Normalise a hymn text for cross-source comparison (#699 Phase C).

    The two sources we compare (SDAHymnal-network sites + ChristInSong.app
    extracts) format the same hymn with small typographic differences:
    - SDAHymnal wraps titles in double quotes; ChristInSong doesn't.
    - SDAHymnal omits trailing whitespace before a comma; ChristInSong
      occasionally inserts one (e.g. "Senhor ," vs "Senhor,").
    - Hyphenation differs ("sem par" vs "sem-par").
    - Unicode normalisation form may differ (NFC vs NFD).

    These differences are real but cosmetic — the underlying lyric is
    identical. The integrity check should flag a song as "identical"
    when only those differences exist, and as "differs" when there's
    a genuine word-level divergence.

    The transformation:
        - NFC-normalise unicode so combining-character vs precomposed
          forms compare equal.
        - Strip leading/trailing double quotes from the title line
          (SDAHymnal-style "..." → bare).
        - Collapse runs of whitespace to a single space.
        - Strip trailing whitespace per line.
        - Normalise hyphen-vs-space inside compound words (treat
          "sem-par" and "sem par" as equivalent).
        - Lower-case for comparison only (the saved file keeps the
          original casing).
    """
    import unicodedata
    text = unicodedata.normalize('NFC', text)
    out_lines = []
    for raw_line in text.splitlines():
        line = raw_line.strip()
        if line.startswith('"') and line.endswith('"') and len(line) >= 2:
            line = line[1:-1].strip()       # strip surrounding quotes from title
        line = re.sub(r'\s+', ' ', line)    # collapse internal whitespace
        line = re.sub(r'\s+([,.!?;:])', r'\1', line)  # drop space-before-punct
        line = line.replace('-', ' ')       # hyphen ≡ space for compound words
        # The hyphen→space substitution can re-introduce double spaces
        # (e.g. "sem-par," → "sem par,"  AND  earlier collapse already
        # ran), so collapse one more time to keep the normal form clean.
        line = re.sub(r'\s+', ' ', line)
        out_lines.append(line.lower())
    return '\n'.join(out_lines).strip()


def _find_existing_hymn_file(number, label, book_dir):
    """
    Look for an already-existing hymn file for this number + label
    in book_dir. Used by the integrity check so the same hymn from a
    second source (e.g. ChristInSong.app extract) gets compared
    rather than overwritten.

    Returns the full path to the first matching file, or None if no
    file exists for this number. The match is on the
    "NNN (LABEL) - " prefix; the title portion can differ between
    sources (e.g. "Ó Deus De Amor" vs "Ó Deus de Amor") and we still
    want them paired up.
    """
    if not os.path.isdir(book_dir):
        return None
    padded = str(number).zfill(3)
    prefix = f"{padded} ({label}) - "
    for name in os.listdir(book_dir):
        if name.startswith(prefix) and name.endswith('.txt'):
            return os.path.join(book_dir, name)
    return None


def _write_integrity_report(book_dir, number, label, status, existing_path,
                            existing_text, new_text):
    """
    Append one entry to {book_dir}/_integrity-check.md describing how the
    fresh-scraped hymn compares to the file already on disk for the same
    number. (#699 Phase C)

    Sections per entry:
        ### NNN — Title (status)
        - existing file: …
        - normalised:    identical | differs (word count delta, first-mismatch
                         location)
        - diff (only when status="differs"): unified diff trimmed to ±3
          lines around each change

    The report is markdown so it renders cleanly on GitHub when the
    output folder is committed for cross-checking.
    """
    import difflib
    report_path = os.path.join(book_dir, '_integrity-check.md')
    is_new_report = not os.path.exists(report_path)

    with open(report_path, 'a', encoding='utf-8') as f:
        if is_new_report:
            f.write(f"# Cross-source integrity check — {label}\n\n")
            f.write(
                "Compares hymns scraped from the SDAHymnal.org network against "
                "files already present in this folder (typically ChristInSong.app "
                "extracts). \"identical\" means the two sources match after "
                "normalisation (NFC unicode, collapsed whitespace, hyphen ≡ space, "
                "case-insensitive). \"differs\" means a real word-level divergence "
                "is present.\n\n"
                "Generated by `SDAHymnals_SDAHymnal.org.py` (#699 Phase C).\n\n"
                "---\n\n"
            )
        # Pull the title from the first non-empty line of either text
        title = (existing_text.splitlines() or [''])[0].strip().strip('"')
        f.write(f"### {str(number).zfill(3)} — {title}  ({status})\n\n")
        f.write(f"- existing file: `{os.path.basename(existing_path)}`\n")

        if status == 'identical':
            f.write("- normalised: **identical** — only cosmetic differences "
                    "(quotes, whitespace, hyphenation)\n\n")
        else:
            # Word-count delta is a quick "how big is this?" signal
            ew = len(existing_text.split())
            nw = len(new_text.split())
            f.write(f"- normalised word counts: existing={ew}, fresh={nw} "
                    f"(delta {nw - ew:+d})\n")
            # Unified diff between normalised forms — keeps the report
            # short while still surfacing what changed
            diff = list(difflib.unified_diff(
                _normalise_for_diff(existing_text).splitlines(),
                _normalise_for_diff(new_text).splitlines(),
                fromfile='existing', tofile='fresh', lineterm='', n=2,
            ))
            f.write("- diff (normalised):\n\n```diff\n")
            f.write('\n'.join(diff[:60]))   # cap at 60 lines per song
            if len(diff) > 60:
                f.write(f"\n... ({len(diff) - 60} more lines truncated)")
            f.write("\n```\n\n")


def save_hymn(hymn, label, output_dir, prefer_source=None):
    """
    Save a parsed hymn to a plain-text file in the output directory.

    Creates the output directory if it doesn't exist, formats the hymn
    content, and writes it to a file with the standard naming convention:
        {number zero-padded to 3} ({label}) - {Title Case Title}.txt

    When `prefer_source` is None (the default), behaves as a plain write:
    creates the file unconditionally. The caller (scrape_site) only
    invokes save_hymn for hymns that aren't already on disk in this
    case, so there is no overwrite risk.

    When `prefer_source` is set (the cross-source integrity-check mode,
    #699 Phase C), and a file for this number+label already exists,
    the fresh scrape is compared against it and a markdown entry is
    appended to `_integrity-check.md`. The handling of the actual
    write then depends on the value:

        'sidebar' — keep the existing file, write the fresh scrape
            with `.sdah-fresh` suffix on the same name so a curator
            can diff manually.

        'sdah' — overwrite the existing file with the fresh scrape.
            Useful for re-syncing a folder where ChristInSong's
            extract is known to be stale.

        'cis' — keep the existing file, do NOT write the fresh scrape
            at all. Useful for an audit-only pass where the curator
            just wants the report.

    Args:
        hymn:          Dict with "number" (int), "title" (str), "sections" (list)
        label:         Book label for the filename (e.g. "SDAH", "CH")
        output_dir:    Directory path where the file should be saved
        prefer_source: None | 'sidebar' | 'sdah' | 'cis' — see above

    Returns:
        str | None: The full file path of the saved file, or None if
        prefer_source='cis' and an existing file was kept (so the
        caller can adjust counters).
    """
    # Ensure the output directory exists (creates parent dirs too if needed)
    os.makedirs(output_dir, exist_ok=True)

    # Zero-pad the hymn number to 3 digits for consistent sorting
    # (e.g. 1 → "001", 42 → "042", 695 → "695")
    padded = str(hymn["number"]).zfill(3)

    # Build the filename: sanitize the title to remove invalid chars,
    # then convert to Title Case for consistent, readable filenames
    filename = f"{padded} ({label}) - {title_case(sanitize(hymn['title']))}.txt"
    filepath = os.path.join(output_dir, filename)
    new_text = format_hymn(hymn)

    # Cross-source integrity check (#699 Phase C). Only runs when the
    # caller explicitly opted in via prefer_source. When prefer_source
    # is None, save_hymn is the plain-write path and we skip the
    # comparison entirely (the call site has its own resumability
    # check). When prefer_source is set, look for an existing file
    # under the same NNN (LABEL) prefix; if one exists, compare and
    # write per the chosen mode.
    if prefer_source is not None:
        existing_path = _find_existing_hymn_file(hymn["number"], label, output_dir)
        if existing_path:
            with open(existing_path, 'r', encoding='utf-8') as ef:
                existing_text = ef.read()
            status = ('identical'
                      if _normalise_for_diff(existing_text) == _normalise_for_diff(new_text)
                      else 'differs')
            _write_integrity_report(
                output_dir, hymn["number"], label, status,
                existing_path, existing_text, new_text,
            )

            if prefer_source == 'sdah':
                with open(existing_path, 'w', encoding='utf-8') as f:
                    f.write(new_text)
                return existing_path
            if prefer_source == 'cis':
                return None
            # 'sidebar': write side-by-side with .sdah-fresh suffix so
            # the curator can pick the survivor manually.
            sidebar = filepath + '.sdah-fresh'
            with open(sidebar, 'w', encoding='utf-8') as f:
                f.write(new_text)
            return sidebar

    # No conflict / no integrity check — straightforward write.
    with open(filepath, "w", encoding="utf-8") as f:
        f.write(new_text)

    return filepath


def log_skip(number, label, reason, book_dir):
    """
    Record a skipped hymn entry in the skipped.log file for later review.

    This creates a persistent log of hymns that couldn't be scraped,
    along with the reason and timestamp. Useful for identifying gaps
    in the collection and diagnosing systematic issues.

    Args:
        number:   The hymn number that was skipped
        label:    Book label (e.g. "SDAH", "CH")
        reason:   Human-readable explanation of why it was skipped
        book_dir: Directory path where the log file should be written

    Log format (one line per skipped hymn):
        [2026-03-13 14:30:00]  SDAH042  —  fetch failed or no title found
    """
    # Ensure the directory exists (in case this is the first file we're writing)
    os.makedirs(book_dir, exist_ok=True)
    log_path  = os.path.join(book_dir, "skipped.log")
    timestamp = time.strftime("%Y-%m-%d %H:%M:%S")
    padded    = str(number).zfill(3)
    with open(log_path, "a", encoding="utf-8") as f:
        f.write(f"[{timestamp}]  {label}{padded}  —  {reason}\n")


# ---------------------------------------------------------------------------
# Per-site scrape loop — the main orchestration logic for one hymnal site
# ---------------------------------------------------------------------------


def scrape_site(site_key, start, end, output_dir, delay, force=False, prefer_source=None):
    """
    Scrape all hymns from a single site (SDAH or CH).

    This is the main loop that iterates through hymn numbers, fetches each
    one, and saves it. It includes several features for robust operation:

    - RESUMABILITY: Scans the output directory for existing files and skips
      hymns that have already been saved (via build_existing_set).

    - AUTO-DETECTION OF END: When the site redirects a hymn request to the
      homepage (fetch_hymn returns None), scraping stops — the hymnal has
      no more hymns beyond this point.

    - CONSECUTIVE SKIP LIMIT: If 10 hymns in a row fail (MAX_CONSEC = 10),
      we assume we've gone past the end of the hymnal and stop. This handles
      the case where the site returns errors rather than redirects for
      non-existent hymns.

    - RATE LIMITING: Pauses for `delay` seconds between each request.

    Args:
        site_key:   Site identifier key from the SITES dict ("sdah" or "ch")
        start:      First hymn number to scrape (inclusive)
        end:        Last hymn number to scrape (inclusive), or None for auto-detect
        output_dir: Base output directory (a book-specific subdir will be created)
        delay:      Seconds to wait between HTTP requests
        force:      If True, re-download and overwrite existing files

    Returns:
        int: Number of hymns successfully saved in this run
    """
    # Look up the site configuration for URLs, label, and subdirectory name
    site     = SITES[site_key]
    label    = site["label"]
    base_url = site["base_url"]
    home_url = site["home_url"]

    # Route output into a book-specific subdirectory within the base output dir
    # e.g. "./hymns/Seventh-day Adventist Hymnal [SDAH]/"
    book_dir = os.path.join(output_dir, site["subdir"])

    # Print a banner with configuration details for this scrape run
    print(f"\n{'='*50}")
    print(f"  Scraping: {base_url}  [{label}]")
    print(f"  Output  : {os.path.abspath(book_dir)}")
    print(f"  Range   : {start} to {end or 'auto-detect'}")
    print(f"{'='*50}\n")

    # Scan the output directory for already-saved hymns to enable resumability.
    # Two opt-outs:
    #   --force                          — re-download + overwrite everything
    #   --prefer-source {sidebar|sdah|cis} — re-download every hymn so the
    #     integrity check can compare against the existing file
    integrity_mode = prefer_source is not None
    existing = set() if (force or integrity_mode) else build_existing_set(label, book_dir)
    if force:
        print(f"  Force mode: will re-download and overwrite existing files.\n")
    elif integrity_mode:
        print(f"  Integrity mode (--prefer-source={prefer_source}): will re-download "
              f"every hymn and compare against existing files in this folder.\n")
    elif existing:
        print(f"  Found {len(existing)} existing {label} hymns — will skip.\n")

    # Counters for the final summary
    saved        = 0     # Successfully saved hymns
    skipped      = 0     # Hymns that were skipped due to errors
    number       = start # Current hymn number being processed

    # Consecutive skip detection: if we encounter MAX_CONSEC failures in a row,
    # we assume we've gone past the end of the hymnal. This is a safety net
    # for sites that don't redirect to the homepage for non-existent hymns.
    MAX_CONSEC   = 10
    consec_skip  = 0

    while True:
        # Check if we've reached the user-specified end hymn
        if end and number > end:
            print(f"\nReached end hymn ({end}). Done.")
            break

        # Skip hymns that are already saved (detected during initial scan)
        if number in existing:
            print(f"  Hymn {number:>4}: ⏭  already exists, skipping.")
            number += 1
            continue  # No delay needed — no network request was made

        # Fetch and parse the hymn page
        print(f"  Hymn {number:>4}: fetching...", end=" ", flush=True)
        hymn = fetch_hymn(number, base_url, home_url)

        if hymn is None:
            # The site redirected to the homepage — we've reached the end of
            # the hymnal. This is the normal termination condition.
            print()
            break

        if hymn == "SKIP":
            # This hymn couldn't be scraped — log it and continue
            print("✗  skipped.")
            log_skip(number, label, "fetch failed or no title found", book_dir)
            skipped     += 1
            consec_skip += 1

            # Safety net: too many consecutive failures suggests we're past
            # the end rather than hitting intermittent errors
            if consec_skip >= MAX_CONSEC:
                print(f"  {MAX_CONSEC} consecutive errors — assuming end of hymnal.")
                break

            number += 1
            time.sleep(delay)   # Rate limit even on failures
            continue

        # Success — reset the consecutive skip counter and save the hymn.
        # save_hymn returns None when prefer_source='cis' kept an
        # existing file; treat that as a successful scrape (the network
        # request happened) but with a different success message.
        consec_skip = 0
        path = save_hymn(hymn, label, book_dir, prefer_source=prefer_source)
        saved += 1
        if path is None:
            print(f"✓  kept existing (prefer_source=cis)")
        elif path.endswith('.sdah-fresh'):
            print(f"✓  side-by-side: {os.path.basename(path)}")
        else:
            print(f"✓  {os.path.basename(path)}")

        number += 1
        time.sleep(delay)  # Rate limit between successful requests

    # Print a summary for this site
    print(f"\n{label}: {saved} hymns saved, {skipped} skipped.")
    return saved


# ---------------------------------------------------------------------------
# Main — CLI entry point and argument parsing
# ---------------------------------------------------------------------------

def main():
    """
    Parse command-line arguments and orchestrate the scraping process.

    Supports scraping one or both sites, specifying a hymn number range,
    custom output directory, and adjustable request delay. If --site is
    "both" (the default), scrapes SDAH first, then CH sequentially.
    """
    # Support -? as an alias for --help (common on Windows and DOS-style CLIs).
    # We intercept it here because argparse doesn't natively support '?' in
    # option strings. Note: users may need to quote or escape -? in their shell
    # (e.g. '-?' or -\?) since ? is a glob wildcard character in most shells.
    if "-?" in sys.argv:
        sys.argv[sys.argv.index("-?")] = "--help"

    site_keys = list(SITES.keys())   # source of truth — one place to add a site
    ap = argparse.ArgumentParser(
        description="Hymnal scraper — SDAHymnal.org network (no pip required)",
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog="""examples:
  python3 %(prog)s                         Scrape every configured site (--site all)
  python3 %(prog)s --site sdah             Only scrape sdahymnal.org (SDAH)
  python3 %(prog)s --site ch               Only scrape hymnal.xyz (CH)
  python3 %(prog)s --site ha               Only scrape himnario.net (HA)
  python3 %(prog)s --site hasd             Only scrape hinarioadventista.com (HASD)
  python3 %(prog)s --site hl               Only scrape hymnes.net (HL)
  python3 %(prog)s --start 50              Resume scraping from hymn 50
  python3 %(prog)s --start 1 --end 100     Scrape a specific range of hymns
  python3 %(prog)s --output ~/Desktop/hymns
                                           Save output to a custom folder
  python3 %(prog)s --delay 2.0             Increase delay to 2s between requests

output:
  Files are saved as plain text in book-specific subdirectories:
    hymns/Seventh-day Adventist Hymnal [SDAH]/001 (SDAH) - Praise To The Lord.txt
    hymns/Himnario Adventista [HA]/001 (HA) - Cantad Al Senor.txt

  The scraper is resumable — existing files are detected and skipped.
  Skipped hymns (errors, missing data) are logged to skipped.log.

notes:
  - No dependencies required — uses only Python standard library modules.
  - Sister sites share the same HTML structure (block-heading-* +
    wedding-heading), so one parser handles them all (#699).
  - The scraper auto-detects the end of the hymnal (no --end needed).
  - Rate limiting is handled automatically (pauses 60s, retries once).""")
    ap.add_argument("--site",   choices=site_keys + ["all", "both"], default="all",
                    help="which site to scrape (default: all). 'both' is an alias "
                         "for 'all' kept for backwards compatibility with prior "
                         "two-site default; new callers should prefer 'all'.")
    ap.add_argument("--start",  type=int,   default=1,
                    help="first hymn number to scrape (default: 1)")
    ap.add_argument("--end",    type=int,   default=None,
                    help="last hymn number to scrape (default: auto-detect end of hymnal)")
    ap.add_argument("--output", type=str,   default=DEFAULT_OUTPUT_DIR,
                    help="output folder path (default: ./hymns)")
    ap.add_argument("--delay",  type=float, default=DELAY,
                    help="seconds to wait between HTTP requests (default: 1.0)")
    ap.add_argument("--force",  action="store_true", default=False,
                    help="force re-download of all hymns, even if files already exist")
    # --prefer-source defaults to None (not passed) so the existing
    # resumability behaviour stays the default for normal scrape runs.
    # When the curator passes the flag, the scraper switches into
    # integrity-check mode: it re-fetches every hymn (bypassing the
    # "already exists, skipping" optimisation) so it can compare
    # against the file already on disk. (#699 Phase C.)
    ap.add_argument("--prefer-source", choices=['sidebar', 'sdah', 'cis'], default=None,
                    dest='prefer_source',
                    help="opt-in cross-source integrity check. When a hymn file "
                         "already exists for this number+label (typically a "
                         "ChristInSong.app extract), the fresh scrape is compared "
                         "against it and a diff is appended to _integrity-check.md. "
                         "'sidebar' — keep existing, write fresh with .sdah-fresh "
                         "suffix. 'sdah' — overwrite existing with fresh. 'cis' — "
                         "keep existing, do not write fresh (report only). Passing "
                         "the flag also disables resumability so every hymn is "
                         "re-fetched for comparison.")
    args = ap.parse_args()

    # Determine which sites to scrape based on the --site argument.
    # 'all' (the new default) walks every configured site; 'both' is
    # the legacy alias from when there were only two sites — kept
    # working so existing scripts / scheduled jobs don't break (#699).
    if args.site in ("all", "both"):
        sites_to_run = site_keys
    else:
        sites_to_run = [args.site]
    total = 0

    # Scrape each site sequentially, accumulating the total saved count
    for site_key in sites_to_run:
        total += scrape_site(site_key, args.start, args.end, args.output,
                             args.delay, args.force,
                             prefer_source=args.prefer_source)

    print(f"\nAll done! {total} hymns total saved to: {os.path.abspath(args.output)}")


if __name__ == "__main__":
    main()
