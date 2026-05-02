#!/usr/bin/env python3
"""
ChristInSong.app.py
.importers/scrapers/ChristInSong.app.py

Hymnal scraper — pulls every hymnal published by christinsong.app and
writes each hymn as a plain-text file under .SourceSongData/, using the
same naming convention as the existing scrapers in this folder.

Copyright 2025-2026 MWBM Partners Ltd.

Overview:
    christinsong.app is a React SPA — there is no scrapeable HTML to
    parse from the page itself. The app fetches its content from the
    open-source data repo at:

        https://github.com/TinasheMzondiwa/cis-hymnals

    The repo's `v2/config.json` lists every hymnal the app supports
    (key + display title + language + optional refrain label). Each
    hymnal's data is published as a single JSON file under
    `v2/<directory>/<key>.json`. Each hymn entry has the shape:

        {
            "index":   "001",
            "number":  1,
            "title":   "Watchman Blow The Gospel Trumpet.",
            "lyrics": [
                {"type": "verse",   "index": 1, "lines": ["...", "..."]},
                {"type": "refrain",             "lines": ["...", "..."]},
                ...
            ],
            "revision": 1
        }

    Going to the JSON source directly means we don't need an HTML
    parser, we don't need to deal with a SPA build, and we get every
    hymnal at once — Christ in Song (English) plus 22 translations.

    The CIS dataset also publishes the SDA Hymnal under key 'sdah',
    but we deliberately exclude it here (#663): the existing
    SDAHymnals_SDAHymnal.org.py scraper is the canonical SDAH source,
    and two scrapers writing into the SDAH folder is one source of
    truth too many.

Output format (matches the existing SDAH scraper on-disk convention):

        Title

        1
        First line of verse 1
        Second line of verse 1

        Refrain
        First line of refrain
        Second line of refrain

        2
        First line of verse 2
        ...

    - Title is plain (no quotes), trailing punctuation stripped.
    - Verse markers are bare numbers ("1", "2", "3", ...).
    - Refrain marker uses the refrain_label from config.json when the
      source hymnal sets one (e.g. Tonga uses "Ciindululo", Spanish
      uses "Coro", Russian uses "Pripev"); falls back to "Refrain".
    - Sections separated by a single blank line.

File naming follows the existing convention used by the other scrapers:

    .SourceSongData/<Hymnal Name> [<ABBREV>]/<padded#> (<ABBREV>) - <Title>.txt

Dependencies:
    Standard library only. Same constraint as the SDAH and MissionPraise
    scrapers — runs on any system with Python 3.6+ without pip install.

Usage:
    python3 ChristInSong.app.py                            # all hymnals
    python3 ChristInSong.app.py --hymnal english           # one hymnal
    python3 ChristInSong.app.py --hymnal english,sdah      # two by name
    python3 ChristInSong.app.py --output ~/Desktop/hymns
    python3 ChristInSong.app.py --zip cis-bundle.zip       # also write a
                                                            # single zip
                                                            # for batch
                                                            # import
    python3 ChristInSong.app.py --force                    # overwrite

Note on the SDA Hymnal entry (#663):
    The CIS dataset publishes the SDA Hymnal under key 'sdah', but we
    deliberately omit it from this scraper. The dedicated
    SDAHymnals_SDAHymnal.org.py scraper is the canonical source for
    the SDAH on this codebase — having two scrapers write into the
    same songbook folder leads to silent divergence. If a curator
    wants to compare the two sources by hand, fetch the CIS sdah JSON
    directly from the upstream repo.
"""

# ---------------------------------------------------------------------------
# Standard library imports (no third-party dependencies required)
# ---------------------------------------------------------------------------
import argparse
import json
import os
import re
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
import zipfile

# Force line-buffered stdout so progress messages appear immediately in the
# terminal, even when output is piped or redirected to a file.
sys.stdout.reconfigure(line_buffering=True)


# ---------------------------------------------------------------------------
# Source URLs
# ---------------------------------------------------------------------------
# The cis-hymnals repo publishes raw JSON via the standard raw.githubusercontent
# CDN. We hit it directly — no GitHub API, no auth, no rate limit (for
# anonymous reads at this volume).
RAW_BASE   = "https://raw.githubusercontent.com/TinasheMzondiwa/cis-hymnals/main/v2"
CONFIG_URL = f"{RAW_BASE}/config.json"

# Polite User-Agent string that identifies what we're doing.
USER_AGENT = "Mozilla/5.0 (compatible; iHymnsScraper/1.0; https://github.com/MWBMPartners/iHymns)"

# Default output directory — matches the layout used by the other scrapers.
DEFAULT_OUTPUT_DIR = "./.SourceSongData"

# Delay between hymnal fetches. The data is already chunked into one JSON
# per hymnal (~700KB to ~1.4MB each) so we make at most 24 outbound
# requests for a full run — a small delay between them is plenty.
DELAY_SECONDS = 0.5


# Language-name lookup for the output-folder suffix (#780). Each entry
# in HYMNALS carries a `lang` field (BCP 47 primary subtag) and we
# compose the subdir as "<Title> [<ABBR>]_<LanguageName>-<lang>" so
# a curator (and the bulk-import handler) can read the language
# straight off disk without a separate manifest lookup. Fallback for
# an unknown code is the code itself uppercased — keeps the suffix
# non-empty rather than degrading to the legacy shape.
LANG_NAMES = {
    "en":  "English",     "es":  "Spanish",     "pt":  "Portuguese",
    "fr":  "French",      "it":  "Italian",     "de":  "German",
    "nl":  "Dutch",       "ru":  "Russian",     "uk":  "Ukrainian",
    "pl":  "Polish",      "bg":  "Bulgarian",   "mk":  "Macedonian",
    "hr":  "Croatian",    "sl":  "Slovenian",   "sk":  "Slovak",
    "cs":  "Czech",       "ro":  "Romanian",    "el":  "Greek",
    "tr":  "Turkish",     "ar":  "Arabic",      "he":  "Hebrew",
    "fa":  "Persian",     "hi":  "Hindi",       "bn":  "Bengali",
    "ta":  "Tamil",       "ml":  "Malayalam",   "te":  "Telugu",
    "zh":  "Chinese",     "ja":  "Japanese",    "ko":  "Korean",
    "vi":  "Vietnamese",  "th":  "Thai",        "id":  "Indonesian",
    "ms":  "Malay",       "tl":  "Tagalog",     "sw":  "Swahili",
    "rw":  "Kinyarwanda", "to":  "Tonga",       "tn":  "Tswana",
    "st":  "Sotho",       "ny":  "Chichewa",    "sn":  "Shona",
    "ve":  "Venda",       "nd":  "Northern Ndebele",
    "xh":  "Xhosa",       "ts":  "Xitsonga",    "ki":  "Kikuyu",
    "guz": "Gusii",       "luo": "Luo",         "tum": "Tumbuka",
    "nso": "Sepedi",      "bem": "Bemba",       "tw":  "Twi",
    "yo":  "Yoruba",      "ig":  "Igbo",        "ha":  "Hausa",
    "am":  "Amharic",     "mg":  "Malagasy",
}


def language_label(code):
    """
    Look up the English display name for a BCP 47 primary subtag.
    Returns the code itself uppercased when unknown — better to keep
    the folder suffix non-empty than to silently degrade. (#780)
    """
    if not code:
        return ""
    return LANG_NAMES.get(code, code.upper())


def compose_subdir(title, abbrev, lang_code):
    """
    Build the output sub-directory name (#780):

        "<Title> [<ABBR>]_<LanguageName>-<lang>"

    e.g. "Christ in Song [CIS]_English-en"
         "Himnario Adventista [HA]_Spanish-es"

    When lang_code is empty/None we fall back to the legacy shape so
    a manifest entry without a language stays valid.
    """
    base = f"{title} [{abbrev}]"
    if not lang_code:
        return base
    label = language_label(lang_code)
    return f"{base}_{label}-{lang_code}"


# ---------------------------------------------------------------------------
# Hymnal manifest — maps each CIS hymnal key to the iHymns-side metadata
# we need (display name, abbreviation for filenames, ISO language code,
# directory inside the cis-hymnals repo).
#
# `directory` is the folder inside `v2/` that holds the hymnal's JSON.
# The cis-hymnals layout groups hymnals by language, so:
#   - english/english.json + english/sdah.json  → both live in english/
#   - isiXhosa/xhosa.json                       → key 'xhosa' under isiXhosa/
#   - français/dg.json, español/es.json, etc.   → unicode dir names
# This lookup is hard-coded so we can avoid hitting the GitHub Contents
# API on every run.
#
# `abbrev` is the short label used in filenames + folder names. Hand-picked
# to be unique and reasonably memorable.
#
# The CIS dataset's `sdah` hymnal is intentionally absent (#663): the
# dedicated SDAHymnals_SDAHymnal.org.py scraper is the canonical SDAH
# source on this codebase, so we don't duplicate it here.
# ---------------------------------------------------------------------------
HYMNALS = {
    # key            display title                                  abbrev      lang   directory
    "english":     {"title": "Christ in Song",                     "abbrev": "CIS",      "lang": "en",  "directory": "english"},
    "tswana":      {"title": "Keresete Mo Kopelong",               "abbrev": "KMK",      "lang": "tn",  "directory": "tswana"},
    "sotho":       {"title": "Keresete Pineng",                    "abbrev": "KP",       "lang": "st",  "directory": "sotho"},
    "chichewa":    {"title": "Khristu Mu Nyimbo",                  "abbrev": "KMN",      "lang": "ny",  "directory": "chichewa"},
    "tonga":       {"title": "Kristu Mu Nyimbo (Tonga)",           "abbrev": "TKMN",     "lang": "to",  "directory": "tonga",       "refrain_label": "Ciindululo"},
    "shona":       {"title": "Kristu MuNzwiyo",                    "abbrev": "KMNz",     "lang": "sn",  "directory": "shona"},
    "venda":       {"title": "Ngosha YaDzingosha",                 "abbrev": "NYD",      "lang": "ve",  "directory": "venda"},
    "swahili":     {"title": "Nyimbo Za Kristo",                   "abbrev": "NZK",      "lang": "sw",  "directory": "swahili"},
    "ndebele":     {"title": "UKrestu Esihlabelelweni",            "abbrev": "UKE",      "lang": "nd",  "directory": "ndebele"},
    "xhosa":       {"title": "UKristu Engomeni",                   "abbrev": "UKEng",    "lang": "xh",  "directory": "isiXhosa"},
    "xitsonga":    {"title": "Risima Ra Vuyimbeleri",              "abbrev": "RRV",      "lang": "ts",  "directory": "xitsonga"},
    "gikuyu":      {"title": "Nyimbo cia Agendi",                  "abbrev": "NCA",      "lang": "ki",  "directory": "kikuyu"},
    "abagusii":    {"title": "Ogotera kw'ogotogia Nyasae",         "abbrev": "OKON",     "lang": "guz", "directory": "abagusii"},
    "dholuo":      {"title": "Wende Nyasaye",                      "abbrev": "WN",       "lang": "luo", "directory": "dholuo"},
    "kinyarwanda": {"title": "Indirimbo Zo Guhimbaza Imana",       "abbrev": "IZGI",     "lang": "rw",  "directory": "kinyarwanda", "refrain_label": "Gusubiramo"},
    "pt":          {"title": "Hinario Adventista do Setimo Dia",   "abbrev": "HASD",     "lang": "pt",  "directory": "portuguese",  "refrain_label": "Coro"},
    "es":          {"title": "Himnario Adventista",                "abbrev": "HA",       "lang": "es",  "directory": "español",     "refrain_label": "Coro"},
    "dg":          {"title": "Donnez-Lui Gloire",                  "abbrev": "DLG",      "lang": "fr",  "directory": "français",    "refrain_label": "Refrain"},
    "ru":          {"title": "Gimn Adventistov Sedmogo Dnya",      "abbrev": "GASD",     "lang": "ru",  "directory": "russian",     "refrain_label": "Pripev"},
    "tumbuka":     {"title": "Nyimbo za Mpingo wa SDA",            "abbrev": "NMSDA",    "lang": "tum", "directory": "tumbuka"},
    "sepedi":      {"title": "Kreste Ka Kopelo",                   "abbrev": "KKK",      "lang": "nso", "directory": "sepedi",      "refrain_label": "Pušulošo"},
    "icibemba":    {"title": "Kristu Mu Nyimbo (Bemba)",           "abbrev": "BKMN",     "lang": "bem", "directory": "icibemba",    "refrain_label": "Cibwekesho"},
    "twi":         {"title": "SDA Twi Hymnal",                     "abbrev": "TWI",      "lang": "tw",  "directory": "twi",         "refrain_label": "Nnyeso"},
}


# ---------------------------------------------------------------------------
# HTTP fetch with retry/backoff
# ---------------------------------------------------------------------------

def fetch_json(url, attempts=3, timeout=30):
    """
    Fetch a JSON document over HTTPS with retry/backoff.

    Args:
        url:      Absolute https URL to fetch
        attempts: Total attempt count (including the first try)
        timeout:  Per-attempt socket timeout in seconds

    Returns:
        Parsed JSON value (typically a dict or list). Raises the last
        exception if every attempt fails.
    """
    req = urllib.request.Request(url, headers={"User-Agent": USER_AGENT})
    last_err = None
    for attempt in range(1, attempts + 1):
        try:
            with urllib.request.urlopen(req, timeout=timeout) as resp:
                raw = resp.read()
            return json.loads(raw.decode("utf-8"))
        except (urllib.error.URLError, urllib.error.HTTPError, TimeoutError, OSError) as e:
            last_err = e
            if attempt < attempts:
                # Linear backoff is fine for github raw — 2s, 4s.
                wait = attempt * 2
                print(f"    fetch failed ({e}); retrying in {wait}s ({attempt}/{attempts})...", flush=True)
                time.sleep(wait)
    raise last_err


# ---------------------------------------------------------------------------
# Filename / formatting helpers
# ---------------------------------------------------------------------------

# Characters that are forbidden in filenames on Windows and/or that cause
# friction on macOS/Linux. Matches the same set used by the other scrapers
# in this folder.
_INVALID_FILENAME_CHARS_RE = re.compile(r'[\\/*?:"<>|]')

# Regex for title-casing while preserving apostrophe contractions.
# Matches a run of letters optionally followed by an apostrophe + more
# letters, so "don't" / "o'er" / "Eagle's" capitalise to "Don't" / "O'er"
# / "Eagle's" rather than "Don'T" / "O'Er" / "Eagle'S".
#
# Uses [^\W\d_] (Unicode letters only — every \w character that isn't a
# digit or underscore) so non-ASCII letters stay inside the matched
# token. Without this, "Señor" would tokenise as "Se" + "ñOr" (the
# regex would skip ñ, treat the leading "or" as a fresh word, and
# capitalise it) producing "SeñOr" instead of "Señor".
#
# Includes Unicode curly quotes (’ right, ‘ left) inside the optional
# contraction-suffix group because the CIS data uses them throughout.
_TITLECASE_TOKEN_RE = re.compile(r"[^\W\d_]+(['’‘][^\W\d_]+)?", re.UNICODE)


def sanitize_filename(name):
    """
    Strip filesystem-unsafe characters from a string used in a filename.

    Args:
        name: Raw string (typically a hymn title)

    Returns:
        Sanitised string with invalid chars removed and whitespace
        trimmed. May still contain non-ASCII characters — modern
        filesystems (APFS, ext4, NTFS) all handle Unicode filenames
        natively, and the other scrapers in this folder follow the
        same policy.
    """
    cleaned = _INVALID_FILENAME_CHARS_RE.sub("", name).strip()
    # Collapse runs of whitespace to a single space — title strings can
    # come in with newlines / tabs from the source data.
    cleaned = re.sub(r"\s+", " ", cleaned)
    return cleaned


def title_case(s):
    """
    Title-case an ASCII string without breaking apostrophe contractions.

    Python's str.title() splits on apostrophes ("don't" → "Don'T"); this
    helper preserves contractions. For non-ASCII titles (e.g. Russian
    Cyrillic, Spanish accents) the regex matches only ASCII letters, so
    the rest of the string passes through unchanged.

    The hymn titles in the CIS dataset are already supplied in mixed
    case — we still pass them through this function to normalise any
    sources that arrive in ALL CAPS or all lowercase.
    """
    return _TITLECASE_TOKEN_RE.sub(lambda m: m.group(0).capitalize(), s)


def clean_title(raw_title):
    """
    Normalise a hymn title from the CIS source into the form used in
    output filenames + the first line of each text file.

    The CIS dataset frequently terminates titles with a trailing period
    or comma ("Watchman Blow The Gospel Trumpet.") which we strip. We
    also collapse internal whitespace and apply ASCII title-casing.
    """
    t = (raw_title or "").strip()
    # Strip trailing punctuation that shows up in the dataset.
    t = re.sub(r"[\s.,;]+$", "", t)
    # Collapse internal whitespace (some titles have stray double-spaces).
    t = re.sub(r"\s+", " ", t)
    # ASCII title-case (no-op for non-ASCII titles; safe for English).
    return title_case(t)


def clean_line(line):
    """
    Trim a single lyric line for output. Source data contains stray
    double-spaces and zero-width spaces in places — collapse runs of
    whitespace to a single space and strip endpoints.
    """
    if line is None:
        return ""
    cleaned = line.replace("​", "")          # strip zero-width spaces
    cleaned = re.sub(r"[ \t]+", " ", cleaned)     # collapse internal runs
    return cleaned.rstrip()                        # preserve leading-space-free


# ---------------------------------------------------------------------------
# Hymn → text formatter
# ---------------------------------------------------------------------------

def format_hymn(hymn, refrain_label):
    """
    Render a single CIS hymn dict as a plain-text string matching the
    existing on-disk format used by other scrapers in this folder.

    Output structure:

        Title

        1
        ...verse 1 lines...

        Refrain
        ...refrain lines...

        2
        ...verse 2 lines...

    Args:
        hymn:           One entry from the hymnal JSON (must have
                        'title' and 'lyrics').
        refrain_label:  Label used for refrain sections — comes from
                        the source hymnal's `refrain_label` field if
                        present, otherwise "Refrain".

    Returns:
        Plain-text string ready to be written to disk.
    """
    title  = clean_title(hymn.get("title", ""))
    pieces = [title, ""]   # title line followed by blank separator

    for section in hymn.get("lyrics", []):
        # Section header — bare verse number for verses, label for
        # refrains. Anything else (defensive: future section types
        # like 'chorus' or 'bridge') gets a Title-Cased version of
        # the type name.
        stype = section.get("type", "")
        if stype == "verse":
            idx = section.get("index")
            header = str(idx) if idx else ""
        elif stype == "refrain":
            header = refrain_label
        else:
            header = stype.capitalize() if stype else ""

        if header:
            pieces.append(header)

        # Lyric lines — one per output line, trimmed of stray whitespace.
        for line in section.get("lines", []):
            pieces.append(clean_line(line))

        # Blank line between sections.
        pieces.append("")

    # Drop trailing blank lines so the file ends with the last lyric.
    while pieces and pieces[-1] == "":
        pieces.pop()

    return "\n".join(pieces)


def format_filename(number, abbrev, title, pad_width):
    """
    Build the output filename used for a single hymn:

        "001 (CIS) - Watchman Blow The Gospel Trumpet.txt"

    Padding width matches what the other scrapers in this folder do
    (3-digit zero-pad — sufficient for hymnals up to 999 hymns).
    """
    padded = str(int(number)).zfill(pad_width)
    safe   = sanitize_filename(title)
    return f"{padded} ({abbrev}) - {safe}.txt"


# ---------------------------------------------------------------------------
# Per-hymnal scrape
# ---------------------------------------------------------------------------

def existing_numbers_in(book_dir, abbrev):
    """
    Scan an existing book directory and collect the set of hymn numbers
    that already have a saved .txt file. Used to make the script
    resumable — re-running after a partial run does not re-download
    every hymn.
    """
    if not os.path.isdir(book_dir):
        return set()
    prefix_tag = f"({abbrev}) -"
    found = set()
    for name in os.listdir(book_dir):
        if prefix_tag in name and name.endswith(".txt"):
            head = name.split()[0]
            if head.isdigit():
                found.add(int(head))
    return found


def scrape_hymnal(key, output_dir, force, refrain_override=None, pad_width=3):
    """
    Pull one hymnal's data + write each hymn to its own .txt file.

    Args:
        key:              Hymnal key from HYMNALS / config.json.
        output_dir:       Base output directory (book subdirs are
                          created underneath this).
        force:            If True, overwrite existing files instead of
                          skipping them.
        refrain_override: If non-empty, use this string as the refrain
                          label instead of whatever the source config
                          declares (or "Refrain" if it doesn't).
        pad_width:        Zero-pad width for hymn numbers in filenames.

    Returns:
        Tuple (saved, skipped, total) for this hymnal.
    """
    if key not in HYMNALS:
        print(f"  ! Unknown hymnal key: {key} — skipping.")
        return (0, 0, 0)

    info       = HYMNALS[key]
    title      = info["title"]
    abbrev     = info["abbrev"]
    directory  = info["directory"]

    # The remote JSON URL. Directory names can include unicode characters
    # (français, español) so we URL-encode that segment specifically.
    enc_dir    = urllib.parse.quote(directory, safe="")
    json_url   = f"{RAW_BASE}/{enc_dir}/{key}.json"

    # Output folder name now embeds the language (#780):
    #   "<Hymnal Name> [<abbrev>]_<LanguageName>-<lang>"
    # e.g. "Himnario Adventista [HA]_Spanish-es"
    # Falls through to the legacy "<Title> [<abbrev>]" shape if lang is empty.
    book_dir   = os.path.join(output_dir, compose_subdir(title, abbrev, info.get("lang", "")))
    os.makedirs(book_dir, exist_ok=True)

    print(f"\n{'='*60}")
    print(f"  Hymnal : {title} [{abbrev}]")
    print(f"  Source : {json_url}")
    print(f"  Output : {os.path.abspath(book_dir)}")
    print(f"{'='*60}")

    # Load the hymnal JSON. Network errors propagate up so the runner
    # can decide whether to abort the whole run or continue with the
    # next hymnal.
    try:
        hymns = fetch_json(json_url)
    except Exception as e:
        print(f"  ✗ Could not fetch hymnal JSON: {e}")
        return (0, 0, 0)

    if not isinstance(hymns, list):
        print(f"  ✗ Unexpected JSON shape — expected list, got {type(hymns).__name__}.")
        return (0, 0, 0)

    # Skip-list of hymn numbers already on disk. Recomputed up-front so
    # a single os.listdir is enough for the whole pass.
    existing = set() if force else existing_numbers_in(book_dir, abbrev)
    if force:
        print(f"  Force mode — will overwrite existing files.")
    elif existing:
        print(f"  Found {len(existing)} existing hymns — will skip.")

    # Refrain label resolution: explicit CLI override > source config
    # value > default "Refrain". The CIS config.json carries
    # `refrain_label` for non-English hymnals (e.g. Tonga "Ciindululo",
    # Spanish "Coro") so the section header reads naturally in-language.
    refrain_label = refrain_override or info.get("refrain_label") or "Refrain"

    saved   = 0
    skipped = 0

    for hymn in hymns:
        try:
            number = int(hymn.get("number"))
        except (TypeError, ValueError):
            # Hymns with no number can't be filed — log + skip.
            print(f"  ! hymn missing 'number': {hymn.get('title', '?')!r} — skipped.")
            skipped += 1
            continue

        if number in existing and not force:
            # Resumable behaviour: don't re-write what's already there.
            skipped += 1
            continue

        # Render to text + write to disk.
        title_clean = clean_title(hymn.get("title", ""))
        filename    = format_filename(number, abbrev, title_clean, pad_width)
        filepath    = os.path.join(book_dir, filename)
        body        = format_hymn(hymn, refrain_label)

        try:
            # Use newline="\n" so the file ends up with LF line endings
            # regardless of platform — matches the other scrapers.
            with open(filepath, "w", encoding="utf-8", newline="\n") as f:
                f.write(body)
                # Trailing newline keeps git happy and matches the
                # convention seen in the existing SDAH files on disk.
                if not body.endswith("\n"):
                    f.write("\n")
            saved += 1
        except OSError as e:
            print(f"  ! could not write {filename}: {e}")
            skipped += 1

    total = len(hymns)
    print(f"  {abbrev}: saved {saved}, skipped {skipped}, total {total}.")
    return (saved, skipped, total)


# ---------------------------------------------------------------------------
# Optional: bundle output into a zip
# ---------------------------------------------------------------------------

def write_family_manifest(output_dir, attempted_keys, failed_keys, parent_key="english"):
    """
    Emit `_family-manifest.json` at the top of the output dir describing
    the parent/child relationships among the hymnals just scraped.

    The admin's `/manage/songbooks` page accepts this file via an "Apply
    family manifest" upload (#782 phase E) and uses it to bulk-set the
    `ParentSongbookId` + `ParentRelationship` columns on the imported
    songbooks — so a curator who scrapes + bulk-imports the Christ in
    Song catalogue doesn't have to hand-link 22 vernaculars to CIS one
    at a time afterwards.

    Manifest schema (versioned for future-proofing):

        {
            "schema_version": 1,
            "scraper":         "ChristInSong.app.py",
            "scraper_version": "1.0",
            "generated_at":    "<ISO-8601 UTC>",
            "families": [
                {
                    "parent": {
                        "abbreviation": "CIS",
                        "name":         "Christ in Song",
                        "language":     "en"
                    },
                    "children": [
                        {"abbreviation": "HA", "name": "Himnario Adventista",
                         "language": "es", "relationship": "translation"},
                        ...
                    ]
                }
            ]
        }

    Args:
        output_dir:       Where to write the manifest (same root that
                          holds the per-hymnal folders).
        attempted_keys:   Hymnal keys passed through the run (input arg).
        failed_keys:      Subset that errored out — excluded from
                          `children` so we don't link a non-existent
                          songbook.
        parent_key:       HYMNALS key to treat as the parent. Defaults
                          to 'english' (the CIS canonical edition);
                          exposed for future flexibility.

    Returns:
        Tuple `(manifest_path, child_count)`. child_count is the number
        of vernacular hymnals that landed in the `children` list — zero
        means nothing to write (parent only / parent failed) and the
        manifest is skipped.
    """
    if parent_key not in HYMNALS:
        return (None, 0)
    if parent_key in failed_keys:
        # Parent itself failed — there's nothing to attach children to.
        return (None, 0)
    if parent_key not in attempted_keys:
        # Run was scoped to a subset that didn't include the parent —
        # children would dangle. Skip the manifest; the curator can
        # link by hand via /manage/songbooks if they want.
        return (None, 0)

    parent_info = HYMNALS[parent_key]
    children = []
    for k in attempted_keys:
        if k == parent_key or k in failed_keys:
            continue
        info = HYMNALS.get(k)
        if not info:
            continue
        children.append({
            "abbreviation": info["abbrev"],
            "name":         info["title"],
            "language":     info.get("lang", ""),
            # Every CIS vernacular is a translation of the English original
            # by definition. Future scrapers (Mission Praise editions, …)
            # can vary the relationship per child.
            "relationship": "translation",
        })

    if not children:
        return (None, 0)

    from datetime import datetime, timezone
    manifest = {
        "schema_version": 1,
        "scraper":         "ChristInSong.app.py",
        "scraper_version": "1.0",
        "generated_at":    datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
        "families": [
            {
                "parent": {
                    "abbreviation": parent_info["abbrev"],
                    "name":         parent_info["title"],
                    "language":     parent_info.get("lang", ""),
                },
                "children": children,
            },
        ],
    }
    manifest_path = os.path.join(output_dir, "_family-manifest.json")
    with open(manifest_path, "w", encoding="utf-8", newline="\n") as f:
        json.dump(manifest, f, ensure_ascii=False, indent=2, sort_keys=False)
        f.write("\n")
    return (manifest_path, len(children))


def zip_output(output_dir, zip_path):
    """
    Bundle every .txt file under `output_dir` into a single zip file.

    This is an optional packaging step for batch import workflows —
    upload the zip to whatever import endpoint the backend exposes and
    let it walk the archive (each top-level entry is a hymnal folder
    named "<Title> [<ABBREV>]"). If no import-from-zip endpoint exists
    yet, this is still a convenient way to ship the dataset between
    machines or attach it to a GitHub release.
    """
    output_dir_abs = os.path.abspath(output_dir)
    written        = 0

    with zipfile.ZipFile(zip_path, "w", compression=zipfile.ZIP_DEFLATED) as zf:
        for root, _dirs, files in os.walk(output_dir_abs):
            for name in files:
                if not name.endswith(".txt"):
                    continue
                full = os.path.join(root, name)
                # Use relative paths inside the archive so the zip
                # mirrors the layout under .SourceSongData/.
                arc  = os.path.relpath(full, output_dir_abs)
                zf.write(full, arcname=arc)
                written += 1

    return (zip_path, written)


# ---------------------------------------------------------------------------
# Main / CLI
# ---------------------------------------------------------------------------

def parse_hymnal_arg(value):
    """
    Resolve the --hymnal argument into a list of hymnal keys.

    Accepts:
      - "all"          → every key in HYMNALS (default)
      - "english"      → single key
      - "english,sdah" → comma-separated list
      - Any unknown key raises ArgumentTypeError so argparse renders
        a clean error rather than silently skipping.
    """
    if not value or value == "all":
        return list(HYMNALS.keys())
    keys = [k.strip() for k in value.split(",") if k.strip()]
    bad  = [k for k in keys if k not in HYMNALS]
    if bad:
        valid = ", ".join(sorted(HYMNALS.keys()))
        raise argparse.ArgumentTypeError(
            f"Unknown hymnal key(s): {', '.join(bad)}. Valid keys: {valid}"
        )
    return keys


def main():
    # Support `-?` as a help alias on Windows-style shells, like the
    # other scrapers in this folder do. Argparse doesn't allow `?` in
    # option names so we patch it on argv before parsing.
    if "-?" in sys.argv:
        sys.argv[sys.argv.index("-?")] = "--help"

    ap = argparse.ArgumentParser(
        description="Scrape every hymnal published by christinsong.app.",
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog="""examples:
  python3 %(prog)s
      Scrape every hymnal under .SourceSongData/.

  python3 %(prog)s --hymnal english
      Just the English Christ in Song hymnal.

  python3 %(prog)s --hymnal english,sdah --output ~/Desktop/hymns
      Two hymnals into a custom folder.

  python3 %(prog)s --zip cis-bundle.zip
      Scrape everything and also pack it into a zip ready for batch
      import via the backend's import API.

  python3 %(prog)s --force
      Overwrite existing files instead of skipping them.

output:
  Files are saved as plain text under:
    <output>/<Hymnal Name> [<ABBREV>]/<padded#> (<ABBREV>) - <Title>.txt

  e.g.:
    .SourceSongData/Christ in Song [CIS]/001 (CIS) - Watchman Blow The Gospel Trumpet.txt
    .SourceSongData/Himnario Adventista [HA]/045 (HA) - Cantad Al Senor.txt

  The script is resumable — files already present are skipped unless
  --force is passed.

notes:
  - Source data: https://github.com/TinasheMzondiwa/cis-hymnals (the
    same dataset christinsong.app fetches at runtime).
  - The CIS dataset's SDA Hymnal entry is intentionally skipped here
    — the dedicated SDAHymnals_SDAHymnal.org.py scraper is the
    canonical SDAH source on this codebase (#663).
  - No third-party Python packages required."""
    )
    ap.add_argument(
        "--hymnal", type=parse_hymnal_arg, default="all",
        help='Comma-separated list of hymnal keys, or "all" (default).'
    )
    ap.add_argument(
        "--output", default=DEFAULT_OUTPUT_DIR,
        help=f"Output directory (default: {DEFAULT_OUTPUT_DIR})"
    )
    ap.add_argument(
        "--delay", type=float, default=DELAY_SECONDS,
        help=f"Seconds to wait between hymnal fetches (default: {DELAY_SECONDS})"
    )
    ap.add_argument(
        "--force", action="store_true",
        help="Overwrite existing files instead of skipping them."
    )
    ap.add_argument(
        "--pad-width", type=int, default=3,
        help="Zero-pad width for hymn numbers in filenames (default: 3)."
    )
    ap.add_argument(
        "--refrain-label", default=None,
        help="Override the refrain section label across every hymnal "
             "(default: per-hymnal value from the source config, "
             "falling back to 'Refrain')."
    )
    ap.add_argument(
        "--zip", dest="zip_path", default=None,
        help="After scraping, also write a single .zip of the output "
             "tree to this path (useful for batch import via an upload "
             "endpoint that accepts a hymnal archive)."
    )
    ap.add_argument(
        "--list", action="store_true",
        help="Print the hymnal manifest and exit."
    )
    ap.add_argument(
        "--no-manifest", action="store_true",
        help="Skip writing _family-manifest.json (the file the admin "
             "uses to bulk-link vernacular hymnals to CIS via "
             "/manage/songbooks → 'Apply family manifest')."
    )
    args = ap.parse_args()

    if args.list:
        # Print the in-script manifest as a quick reference. We don't
        # bother fetching the live config.json here — the in-script
        # mapping is what the rest of the run actually uses.
        print(f"{'KEY':<14}  {'ABBREV':<10}  {'LANG':<6}  TITLE")
        print(f"{'-'*14}  {'-'*10}  {'-'*6}  {'-'*40}")
        for k, v in HYMNALS.items():
            print(f"{k:<14}  {v['abbrev']:<10}  {v['lang']:<6}  {v['title']}")
        return 0

    # Resolve hymnal keys (parse_hymnal_arg returned a list).
    keys = args.hymnal if isinstance(args.hymnal, list) else parse_hymnal_arg(args.hymnal)
    if not keys:
        print("No hymnals selected.")
        return 1

    output_dir = os.path.abspath(args.output)
    os.makedirs(output_dir, exist_ok=True)

    print(f"Scraping {len(keys)} hymnal(s) from christinsong.app data source.")
    print(f"Output  : {output_dir}\n")

    grand_saved   = 0
    grand_skipped = 0
    grand_total   = 0
    failed_keys   = []

    for i, key in enumerate(keys):
        try:
            saved, skipped, total = scrape_hymnal(
                key, output_dir, args.force,
                refrain_override=args.refrain_label,
                pad_width=args.pad_width,
            )
        except KeyboardInterrupt:
            # Let ^C bubble up — partial results stay on disk.
            print("\n  Interrupted by user.")
            raise
        except Exception as e:
            # Don't let one failing hymnal abort the whole batch.
            print(f"  ✗ {key} failed: {e}")
            failed_keys.append(key)
            continue

        grand_saved   += saved
        grand_skipped += skipped
        grand_total   += total

        # Polite delay between remote fetches, except after the last.
        if i + 1 < len(keys):
            time.sleep(args.delay)

    print(f"\n{'='*60}")
    print(f"  Total : {grand_saved} saved, {grand_skipped} skipped, "
          f"{grand_total} hymns inspected across {len(keys)} hymnal(s).")
    if failed_keys:
        print(f"  Failed hymnals: {', '.join(failed_keys)}")
    print(f"{'='*60}")

    # Family manifest (#782 phase E). Lists every successful vernacular
    # under the English CIS parent so the admin's "Apply family manifest"
    # uploader can bulk-link them post-import. Skipped when the parent
    # itself wasn't part of the run, when the parent failed, or when
    # --no-manifest is passed.
    if not args.no_manifest:
        manifest_path, child_count = write_family_manifest(
            output_dir, keys, failed_keys
        )
        if manifest_path:
            print(f"\nFamily manifest → {manifest_path}")
            print(f"  {child_count} vernacular hymnal(s) listed under CIS.")
            print( "  Upload this file via /manage/songbooks → "
                   "'Apply family manifest' to bulk-link them.")
        else:
            print( "\nFamily manifest skipped — parent CIS was not part "
                   "of this run (or failed). Use --hymnal english,… to "
                   "include it.")

    # Optional zip bundle.
    if args.zip_path:
        zip_path = os.path.abspath(args.zip_path)
        print(f"\nBundling output → {zip_path}")
        path, count = zip_output(output_dir, zip_path)
        size_mb = os.path.getsize(path) / (1024 * 1024)
        print(f"  {count} files packed, {size_mb:.2f} MB on disk.")

    return 0 if not failed_keys else 2


if __name__ == "__main__":
    sys.exit(main())
