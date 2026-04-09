/**
 * iHymns — Song Data Parser
 *
 * Copyright © 2026 MWBM Partners Ltd. All rights reserved.
 * This software is proprietary. Unauthorized copying, modification, or
 * distribution is strictly prohibited.
 *
 * Third-party components retain their respective licenses.
 *
 * PURPOSE:
 * Parses raw song text files from .SourceSongData/ into a structured JSON
 * database file (data/songs.json). This is the single source of truth for
 * all song data used across Web, Apple, and Android platforms.
 *
 * USAGE:
 *   node tools/parse-songs.js
 *   — or —
 *   npm run parse-songs
 *
 * INPUT:  .SourceSongData/<Songbook Name> [<Abbreviation>]/<song files>
 * OUTPUT: data/songs.json
 */

/* =========================================================================
 * IMPORTS
 * ========================================================================= */

/* Import Node.js built-in 'fs' module for file system operations (read/write) */
import fs from 'node:fs';

/* Import Node.js built-in 'path' module for cross-platform file path handling */
import path from 'node:path';

/* Import 'fileURLToPath' to convert import.meta.url to a usable file path */
import { fileURLToPath } from 'node:url';

/* =========================================================================
 * CONSTANTS & CONFIGURATION
 * ========================================================================= */

/* __filename and __dirname are not available in ES modules by default,
   so we derive them from import.meta.url */
const __filename = fileURLToPath(import.meta.url);

/* __dirname gives us the directory this script lives in (tools/) */
const __dirname = path.dirname(__filename);

/* PROJECT_ROOT is the top-level project directory (one level up from tools/) */
const PROJECT_ROOT = path.resolve(__dirname, '..');

/* SOURCE_DIR is the directory containing all raw song text files */
const SOURCE_DIR = path.join(PROJECT_ROOT, '.SourceSongData');

/* OUTPUT_FILE is where the parsed JSON database will be written */
const OUTPUT_FILE = path.join(PROJECT_ROOT, 'data', 'songs.json');

/**
 * SONGBOOK_CONFIG maps each songbook folder name to its metadata.
 * The folder names in .SourceSongData/ follow the pattern:
 *   "<Songbook Name> [<Abbreviation>]"
 *
 * Each entry contains:
 *   - id:   Short abbreviation used as a unique identifier
 *   - name: Full human-readable songbook name
 */
const SONGBOOK_CONFIG = {
  'Carol Praise [CP]': {
    id: 'CP',
    name: 'Carol Praise'
  },
  'Junior Praise [JP]': {
    id: 'JP',
    name: 'Junior Praise'
  },
  'Mission Praise [MP]': {
    id: 'MP',
    name: 'Mission Praise'
  },
  'Seventh-day Adventist Hymnal [SDAH]': {
    id: 'SDAH',
    name: 'Seventh-day Adventist Hymnal'
  },
  'The Church Hymnal [CH]': {
    id: 'CH',
    name: 'The Church Hymnal'
  },
  'Miscellaneous [Misc]': {
    id: 'Misc',
    name: 'Miscellaneous'
  }
};

/**
 * COMPONENT_LABELS defines the keywords that indicate a song component type.
 * When a line matches one of these (case-insensitive), it signals the start
 * of that component type. "Refrain" is kept for import compatibility but
 * displays as "Chorus" in the UI (alias).
 */
const COMPONENT_LABELS = [
  'refrain',
  'chorus',
  'bridge',
  'pre-chorus',
  'tag',
  'coda',
  'intro',
  'outro',
  'interlude'
];

/* =========================================================================
 * PARSER FUNCTIONS
 * ========================================================================= */

/**
 * extractSongNumber()
 *
 * Extracts the numeric song number from a filename.
 * Filenames follow patterns like:
 *   "003 (CH) - Come, Thou Almighty King.txt"
 *   "0001 (MP) - A New Commandment.txt"
 *
 * The number is always at the start of the filename, possibly zero-padded.
 *
 * @param {string} filename - The song filename (e.g., "003 (CH) - Title.txt")
 * @returns {number} The song number as an integer (e.g., 3)
 */
function extractSongNumber(filename) {
  /* Match one or more digits at the very start of the filename */
  const match = filename.match(/^(\d+)/);

  /* If a match is found, parse it as base-10 integer; otherwise return 0 */
  if (match) {
    return parseInt(match[1], 10);
  }

  /* Return 0 as a fallback if no number is found (should not happen with valid data) */
  return 0;
}

/**
 * extractTitleFromFilename()
 *
 * Extracts the song title from the filename as a fallback when the file
 * content doesn't have a clear title on line 1.
 *
 * Filename pattern: "<number> (<abbrev>) - <Title>.txt"
 * We extract the part after " - " and before ".txt"
 *
 * @param {string} filename - The song filename
 * @returns {string} The extracted title, or 'Unknown Title' if extraction fails
 */
function extractTitleFromFilename(filename) {
  /* Match everything after " - " up to the file extension */
  const match = filename.match(/ - (.+)\.txt$/i);

  /* Return the captured group (the title) if found */
  if (match) {
    return match[1].trim();
  }

  /* Fallback: return the filename without extension */
  return filename.replace(/\.txt$/i, '').trim() || 'Unknown Title';
}

/**
 * extractTitleFromContent()
 *
 * Extracts the song title from the first line of the file content.
 * Titles are typically enclosed in double quotes (e.g., "Amazing Grace")
 * or are simply the first non-empty line (e.g., SDAH format).
 *
 * @param {string} firstLine - The first non-empty line of the song file
 * @returns {string} The cleaned title text
 */
function extractTitleFromContent(firstLine) {
  /* Try to match a title enclosed in double quotes (standard format) */
  const quotedMatch = firstLine.match(/^"(.+)"$/);

  /* If the title is in quotes, return the inner text */
  if (quotedMatch) {
    return quotedMatch[1].trim();
  }

  /* Otherwise, return the entire first line trimmed (SDAH format, etc.) */
  return firstLine.trim();
}

/**
 * isVerseNumber()
 *
 * Checks whether a trimmed line represents a standalone verse number.
 * Verse numbers appear as a single digit or number on their own line,
 * sometimes with leading/trailing whitespace.
 *
 * Valid examples: "1", "2", "3", "10", "  3  "
 * Invalid examples: "1 Come, Thou", "Verse 1", "Refrain"
 *
 * Also handles the format where the verse number is followed by two spaces
 * and then lyrics on the same line (e.g., "3  Then all worshipped Jesus").
 * In that case, this function returns false — the caller handles it.
 *
 * @param {string} line - A trimmed line from the song file
 * @returns {boolean} True if the line is just a verse number
 */
function isVerseNumber(line) {
  /* Match a line that consists of only digits (1-3 digits max) */
  return /^\d{1,3}$/.test(line.trim());
}

/**
 * isComponentLabel()
 *
 * Checks whether a trimmed line is a song component label like
 * "Refrain", "Chorus", "Bridge", etc.
 *
 * @param {string} line - A trimmed line from the song file
 * @returns {string|null} The normalised component type if matched, or null
 */
function isComponentLabel(line) {
  /* Convert to lowercase for case-insensitive comparison */
  const lower = line.trim().toLowerCase();

  /* Check each known component label */
  for (const label of COMPONENT_LABELS) {
    /* Match the label exactly, or with a trailing colon (e.g., "Chorus:") */
    if (lower === label || lower === label + ':') {
      return label;
    }
  }

  /* No match found */
  return null;
}

/**
 * isCreditsLine()
 *
 * Detects whether a line is part of the writer/composer/copyright credits
 * that appear at the end of some song files (especially MP, JP, CP).
 *
 * Credit lines typically start with:
 *   - "Words and music by ..."
 *   - "Words by ..."
 *   - "Music by ..."
 *   - "Music arranged by ..."
 *   - "Copyright © ..."
 *   - "? ..." (some files use ? instead of ©)
 *   - "Arr. ..." or "Arranged by ..."
 *   - "© ..."
 *   - "Based on ..."
 *   - "From ..."
 *   - "Translated by ..."
 *   - "Paraphrased by ..."
 *
 * @param {string} line - A trimmed line from the song file
 * @returns {boolean} True if the line appears to be a credits/attribution line
 */
function isCreditsLine(line) {
  /* Normalise the line: trim whitespace */
  const trimmed = line.trim();

  /* If the line is empty, it's not a credits line */
  if (trimmed.length === 0) {
    return false;
  }

  /* Define regex patterns that match common credit line prefixes */
  const creditPatterns = [
    /^words\s+(and\s+music\s+)?by\s/i,          /* "Words by" or "Words and music by" */
    /^music\s+(arranged\s+)?by\s/i,              /* "Music by" or "Music arranged by" */
    /^arranged?\s+by\s/i,                        /* "Arr. by" or "Arranged by" */
    /^arr\.\s/i,                                 /* "Arr. ..." */
    /^copyright\s/i,                             /* "Copyright ..." */
    /^©\s/i,                                     /* "© ..." */
    /^\?\s/i,                                    /* "? ..." (used in some files instead of ©) */
    /^based\s+on\s/i,                            /* "Based on ..." */
    /^from\s+(the\s+)?/i,                        /* "From ..." or "From the ..." */
    /^translated?\s+by\s/i,                      /* "Translated by" or "Translate by" */
    /^paraphrased?\s+by\s/i,                     /* "Paraphrased by" */
    /^adapted\s+by\s/i,                          /* "Adapted by" */
    /^used\s+by\s+permission/i,                  /* "Used by Permission" */
    /^adm\.?\s+by\s/i,                           /* "Adm. by" */
    /^administered\s+by\s/i,                     /* "Administered by" */
    /^all\s+rights\s+reserved/i,                 /* "All rights reserved" */
    /^ccli\s/i,                                  /* "CCLI ..." */
    /^original\s+\w+\s+by\s/i,                  /* "Original words by" etc. */
    /^additional\s+\w+\s+by\s/i,                /* "Additional words by" etc. */
    /^verse\s+\d+\s+by\s/i                      /* "Verse 3 by ..." */
  ];

  /* Test each pattern against the line */
  for (const pattern of creditPatterns) {
    if (pattern.test(trimmed)) {
      return true;
    }
  }

  /* Return false if no credit pattern matched */
  return false;
}

/**
 * extractWritersFromCredits()
 *
 * Parses the credits lines at the end of a song file to extract
 * writer and composer names.
 *
 * @param {string[]} creditLines - Array of credit/attribution lines
 * @returns {{ writers: string[], composers: string[], copyright: string }}
 */
function extractWritersFromCredits(creditLines) {
  /* Initialise empty arrays for writers and composers */
  const writers = [];
  const composers = [];

  /* Initialise empty string for copyright info */
  let copyright = '';

  /* Process each credit line */
  for (const line of creditLines) {
    /* Trim the line for clean matching */
    const trimmed = line.trim();

    /* Skip empty lines */
    if (trimmed.length === 0) {
      continue;
    }

    /* Check for "Words and music by <name>" — adds to both writers and composers */
    const wordsAndMusicMatch = trimmed.match(/^words\s+and\s+music\s+by\s+(.+)$/i);
    if (wordsAndMusicMatch) {
      /* Extract the name and add to both lists */
      const name = wordsAndMusicMatch[1].trim();
      writers.push(name);
      composers.push(name);
      continue;
    }

    /* Check for "Words by <name>" — adds to writers only */
    const wordsByMatch = trimmed.match(/^words\s+by\s+(.+)$/i);
    if (wordsByMatch) {
      writers.push(wordsByMatch[1].trim());
      continue;
    }

    /* Check for "Music by <name>" or "Music arranged by <name>" — adds to composers */
    const musicByMatch = trimmed.match(/^music\s+(?:arranged\s+)?by\s+(.+)$/i);
    if (musicByMatch) {
      composers.push(musicByMatch[1].trim());
      continue;
    }

    /* Check for "Arranged by <name>" or "Arr. <name>" — adds to composers */
    const arrangedByMatch = trimmed.match(/^(?:arranged?|arr\.?)\s+by\s+(.+)$/i);
    if (arrangedByMatch) {
      composers.push(arrangedByMatch[1].trim());
      continue;
    }

    /* Check for copyright lines — store as copyright string */
    const copyrightMatch = trimmed.match(/^(?:copyright\s+|©\s*|\?\s*)(.*)/i);
    if (copyrightMatch) {
      /* Append to copyright, joining multiple copyright lines with ' | ' */
      if (copyright.length > 0) {
        copyright += ' | ';
      }
      copyright += copyrightMatch[1].trim() || trimmed;
      continue;
    }
  }

  /* Return the extracted data */
  return { writers, composers, copyright };
}

/**
 * parseSongFile()
 *
 * Parses a single song text file into a structured song object.
 * This is the main parsing function that handles all songbook formats.
 *
 * The parser works by reading the file line-by-line and detecting:
 *   1. Title (line 1, usually in quotes)
 *   2. Verse numbers (standalone digits on a line)
 *   3. Component labels ("Refrain", "Chorus", etc.)
 *   4. Lyrics lines (everything else in the body)
 *   5. Credits (writer/composer/copyright at the end)
 *
 * @param {string} filePath       - Full path to the song .txt file
 * @param {string} filename       - Just the filename (for number/title extraction)
 * @param {object} songbookConfig - The songbook's { id, name } config
 * @returns {object} A structured song object
 */
function parseSongFile(filePath, filename, songbookConfig) {
  /* Read the entire file content as a UTF-8 string */
  const rawContent = fs.readFileSync(filePath, 'utf-8');

  /* Split the content into individual lines */
  const allLines = rawContent.split(/\r?\n/);

  /* Extract the song number from the filename (e.g., "003" → 3) */
  const songNumber = extractSongNumber(filename);

  /* Generate a unique song ID: "<SONGBOOK_ID>-<ZERO_PADDED_NUMBER>" */
  const songId = `${songbookConfig.id}-${String(songNumber).padStart(4, '0')}`;

  /* -----------------------------------------------------------------------
   * STEP 1: Extract the title
   * The title is typically on the first non-empty line, often in quotes.
   * ----------------------------------------------------------------------- */

  /* Find the first non-empty line (skip any leading blank lines) */
  let titleLineIndex = 0;
  while (titleLineIndex < allLines.length && allLines[titleLineIndex].trim() === '') {
    titleLineIndex++;
  }

  /* Extract the title from the first non-empty line */
  let title = '';
  if (titleLineIndex < allLines.length) {
    title = extractTitleFromContent(allLines[titleLineIndex]);
  }

  /* If the title is empty or looks like a verse number, fall back to filename */
  if (!title || /^\d{1,3}$/.test(title)) {
    title = extractTitleFromFilename(filename);
  }

  /* -----------------------------------------------------------------------
   * STEP 2: Parse the body into components (verses, chorus, refrain, etc.)
   * We process lines after the title line.
   * ----------------------------------------------------------------------- */

  /* Start processing from the line after the title */
  const bodyLines = allLines.slice(titleLineIndex + 1);

  /* Array to hold all song components (verse, chorus, refrain, etc.) */
  const components = [];

  /* Array to collect credit/attribution lines found at the end */
  const creditLines = [];

  /* Current component being built */
  let currentComponent = null;

  /* Flag: once we detect credits, all remaining lines are credits */
  let inCreditsSection = false;

  /* Track verse numbers we've seen (to avoid duplicating the refrain) */
  let verseCounter = 0;

  /**
   * flushComponent()
   * Saves the current component (if it has any lines) to the components array,
   * then resets the current component to null.
   */
  function flushComponent() {
    if (currentComponent && currentComponent.lines.length > 0) {
      components.push(currentComponent);
    }
    currentComponent = null;
  }

  /* Process each line in the body */
  for (let i = 0; i < bodyLines.length; i++) {
    /* Get the raw line and a trimmed version */
    const rawLine = bodyLines[i];
    const trimmedLine = rawLine.trim();

    /* --- Check if we've entered the credits section --- */
    if (!inCreditsSection && isCreditsLine(trimmedLine)) {
      /* Flush any component we were building */
      flushComponent();

      /* Mark that we're now in the credits section */
      inCreditsSection = true;

      /* Add this line to credits */
      creditLines.push(trimmedLine);
      continue;
    }

    /* If we're already in the credits section, collect all remaining lines */
    if (inCreditsSection) {
      if (trimmedLine.length > 0) {
        creditLines.push(trimmedLine);
      }
      continue;
    }

    /* --- Skip empty lines (they act as separators between components) --- */
    if (trimmedLine === '') {
      continue;
    }

    /* --- Check for a standalone verse number (e.g., "1", "2", "3") --- */
    if (isVerseNumber(trimmedLine)) {
      /* Flush the previous component (if any) */
      flushComponent();

      /* Parse the verse number */
      verseCounter = parseInt(trimmedLine, 10);

      /* Start a new verse component */
      currentComponent = {
        type: 'verse',
        number: verseCounter,
        lines: []
      };

      continue;
    }

    /* --- Check for inline verse number + lyrics (e.g., "3  Then all...") --- */
    const inlineVerseMatch = trimmedLine.match(/^(\d{1,3})\s{2,}(.+)$/);
    if (inlineVerseMatch) {
      /* Flush the previous component */
      flushComponent();

      /* Extract the verse number and the first line of lyrics */
      verseCounter = parseInt(inlineVerseMatch[1], 10);
      const lyricsLine = inlineVerseMatch[2].trim();

      /* Start a new verse component with the lyrics line already added */
      currentComponent = {
        type: 'verse',
        number: verseCounter,
        lines: [lyricsLine]
      };

      continue;
    }

    /* --- Check for a component label (Refrain, Chorus, Bridge, etc.) --- */
    const componentType = isComponentLabel(trimmedLine);
    if (componentType) {
      /* Flush the previous component */
      flushComponent();

      /* Start a new component of the detected type (no number for these) */
      currentComponent = {
        type: componentType,
        number: null,
        lines: []
      };

      continue;
    }

    /* --- Otherwise, this is a lyrics line --- */

    /* If we don't have a current component, start an implicit verse/section */
    if (!currentComponent) {
      /* If we haven't seen any verses yet, this might be a song with no structure
         (e.g., some MP songs that are just continuous text). Start verse 1. */
      if (components.length === 0 && verseCounter === 0) {
        verseCounter = 1;
        currentComponent = {
          type: 'verse',
          number: verseCounter,
          lines: []
        };
      } else {
        /* Start a generic continuation section */
        currentComponent = {
          type: 'verse',
          number: null,
          lines: []
        };
      }
    }

    /* Add the trimmed lyrics line to the current component */
    currentComponent.lines.push(trimmedLine);
  }

  /* Flush the last component (if any remains) */
  flushComponent();

  /* -----------------------------------------------------------------------
   * STEP 3: Extract writer/composer credits
   * ----------------------------------------------------------------------- */

  /* Parse the collected credit lines into structured data */
  const { writers, composers, copyright } = extractWritersFromCredits(creditLines);

  /* -----------------------------------------------------------------------
   * STEP 4: Check for companion files (audio MIDI and sheet music PDF)
   * ----------------------------------------------------------------------- */

  /* Build the base filename without extension for checking companion files */
  const baseName = filename.replace(/\.txt$/i, '');

  /* Construct expected paths for MIDI audio and PDF sheet music */
  const songDir = path.dirname(filePath);
  const audioPath = path.join(songDir, `${baseName}_audio.mid`);
  const sheetMusicPath = path.join(songDir, `${baseName}_music.pdf`);

  /* Check if the companion files exist */
  const hasAudio = fs.existsSync(audioPath);
  const hasSheetMusic = fs.existsSync(sheetMusicPath);

  /* -----------------------------------------------------------------------
   * STEP 5: Generate song arrangement (#160)
   *
   * If the song has a refrain or chorus, auto-generate an arrangement
   * that interleaves it after each verse. When absent, the renderer
   * falls back to sequential component order.
   * ----------------------------------------------------------------------- */
  let arrangement = null;
  const refrainIndex = components.findIndex(
    c => c.type === 'refrain' || c.type === 'chorus'
  );
  if (refrainIndex !== -1) {
    arrangement = [];
    for (let i = 0; i < components.length; i++) {
      const comp = components[i];
      if (comp.type === 'verse') {
        arrangement.push(i);
        arrangement.push(refrainIndex);
      } else if (i !== refrainIndex) {
        /* Non-verse, non-refrain components (bridge, pre-chorus, etc.) */
        arrangement.push(i);
      }
    }
  }

  /* -----------------------------------------------------------------------
   * STEP 6: Determine copyright / public domain status (#225)
   *
   * A song is only considered public domain when the copyright field
   * explicitly contains a recognised PD designation (case-insensitive):
   *   "Public Domain", "PD", "PublicDomain", "PubDomain", "Pub Domain"
   * An empty copyright field does NOT imply public domain — it simply
   * means the copyright holder is unknown or unrecorded.
   * Both lyrics and music share the same heuristic for now — Phase 2
   * may split them with per-field metadata in the source files.
   * ----------------------------------------------------------------------- */
  const copyrightLower = (copyright || '').trim().toLowerCase();
  const isPublicDomain = copyrightLower.includes('public domain')
    || copyrightLower.includes('publicdomain')
    || copyrightLower.includes('pubdomain')
    || copyrightLower.includes('pub domain')
    || copyrightLower === 'pd';

  /* -----------------------------------------------------------------------
   * STEP 7: Construct and return the structured song object
   * ----------------------------------------------------------------------- */
  const song = {
    id: songId,
    number: songNumber,
    title: title,
    songbook: songbookConfig.id,
    songbookName: songbookConfig.name,
    language: 'en',
    writers: writers,
    composers: composers,
    copyright: copyright,
    ccli: '',
    verified: false,
    lyricsPublicDomain: isPublicDomain,
    musicPublicDomain: isPublicDomain,
    hasAudio: hasAudio,
    hasSheetMusic: hasSheetMusic,
    components: components
  };
  if (arrangement) {
    song.arrangement = arrangement;
  }
  return song;
}

/**
 * parseSongbook()
 *
 * Parses all song text files within a single songbook directory.
 *
 * @param {string} songbookFolder - The folder name (e.g., "The Church Hymnal [CH]")
 * @param {object} config         - The songbook's { id, name } config
 * @returns {object[]} Array of parsed song objects, sorted by song number
 */
function parseSongbook(songbookFolder, config) {
  /* Build the full path to the songbook directory */
  const songbookPath = path.join(SOURCE_DIR, songbookFolder);

  /* Read all files in the songbook directory */
  const allFiles = fs.readdirSync(songbookPath);

  /* Filter to only .txt files (ignore .mid, .pdf, .DS_Store, etc.) */
  const songFiles = allFiles.filter(file => file.toLowerCase().endsWith('.txt'));

  /* Log progress to the console */
  console.log(`  📖 Parsing ${config.name} (${config.id}): ${songFiles.length} songs...`);

  /* Parse each song file into a structured object */
  const songs = songFiles.map(filename => {
    /* Build the full file path */
    const filePath = path.join(songbookPath, filename);

    /* Parse the song file and return the structured object */
    return parseSongFile(filePath, filename, config);
  });

  /* Sort songs by their number (ascending order) */
  songs.sort((a, b) => a.number - b.number);

  /* Return the array of parsed songs */
  return songs;
}

/* =========================================================================
 * MAIN EXECUTION
 * ========================================================================= */

/**
 * main()
 *
 * The main entry point. Iterates over all configured songbooks,
 * parses their songs, and writes the unified JSON database.
 */
function main() {
  /* Print a header to the console */
  console.log('');
  console.log('📖 iHymns Song Data Parser');
  console.log('══════════════════════════════════════════════════');
  console.log(`  Source: ${SOURCE_DIR}`);
  console.log(`  Output: ${OUTPUT_FILE}`);
  console.log('');

  /* Check that the source directory exists */
  if (!fs.existsSync(SOURCE_DIR)) {
    console.error(`❌ ERROR: Source directory not found: ${SOURCE_DIR}`);
    process.exit(1);
  }

  /* Build the songbooks metadata array */
  const songbooks = [];

  /* Accumulate all songs from all songbooks */
  const allSongs = [];

  /* Track statistics for the summary */
  const stats = {
    totalSongs: 0,
    totalWithAudio: 0,
    totalWithSheetMusic: 0,
    totalWithWriters: 0,
    totalWithCopyright: 0,
    songbookCounts: {}
  };

  /* Iterate over each configured songbook */
  for (const [folderName, config] of Object.entries(SONGBOOK_CONFIG)) {
    /* Check that the songbook folder exists */
    const folderPath = path.join(SOURCE_DIR, folderName);
    if (!fs.existsSync(folderPath)) {
      console.warn(`  ⚠️  Songbook folder not found, skipping: ${folderName}`);
      continue;
    }

    /* Parse all songs in this songbook */
    const songs = parseSongbook(folderName, config);

    /* Add songbook metadata */
    songbooks.push({
      id: config.id,
      name: config.name,
      songCount: songs.length
    });

    /* Add parsed songs to the master list */
    allSongs.push(...songs);

    /* Update statistics */
    stats.songbookCounts[config.id] = songs.length;
    stats.totalSongs += songs.length;
    stats.totalWithAudio += songs.filter(s => s.hasAudio).length;
    stats.totalWithSheetMusic += songs.filter(s => s.hasSheetMusic).length;
    stats.totalWithWriters += songs.filter(s => s.writers.length > 0).length;
    stats.totalWithCopyright += songs.filter(s => s.copyright.length > 0).length;
  }

  /* -----------------------------------------------------------------------
   * Build the final JSON structure
   * ----------------------------------------------------------------------- */
  const outputData = {
    /* Metadata about the generated file */
    meta: {
      generatedAt: new Date().toISOString(),
      generatorVersion: '1.0.0',
      totalSongs: stats.totalSongs,
      totalSongbooks: songbooks.length
    },

    /* Array of songbook metadata objects */
    songbooks: songbooks,

    /* Array of all parsed song objects */
    songs: allSongs
  };

  /* -----------------------------------------------------------------------
   * Write the JSON file
   * ----------------------------------------------------------------------- */

  /* Ensure the output directory exists */
  const outputDir = path.dirname(OUTPUT_FILE);
  if (!fs.existsSync(outputDir)) {
    fs.mkdirSync(outputDir, { recursive: true });
  }

  /* Write the JSON file with 2-space indentation for readability */
  fs.writeFileSync(OUTPUT_FILE, JSON.stringify(outputData, null, 2), 'utf-8');

  /* -----------------------------------------------------------------------
   * Print summary statistics
   * ----------------------------------------------------------------------- */
  console.log('');
  console.log('══════════════════════════════════════════════════');
  console.log('✅ Parse complete!');
  console.log('');
  console.log('📊 Statistics:');
  console.log(`  Total songs:       ${stats.totalSongs.toLocaleString()}`);
  console.log(`  With audio (MIDI): ${stats.totalWithAudio.toLocaleString()}`);
  console.log(`  With sheet music:  ${stats.totalWithSheetMusic.toLocaleString()}`);
  console.log(`  With writers:      ${stats.totalWithWriters.toLocaleString()}`);
  console.log(`  With copyright:    ${stats.totalWithCopyright.toLocaleString()}`);
  console.log('');
  console.log('📚 Per songbook:');
  for (const [id, count] of Object.entries(stats.songbookCounts)) {
    console.log(`  ${id.padEnd(6)} ${count.toLocaleString()} songs`);
  }
  console.log('');
  console.log(`💾 Output: ${OUTPUT_FILE}`);

  /* Calculate and display file size */
  const fileSizeBytes = fs.statSync(OUTPUT_FILE).size;
  const fileSizeMB = (fileSizeBytes / (1024 * 1024)).toFixed(2);
  console.log(`📦 File size: ${fileSizeMB} MB`);
  console.log('');
}

/* Run the main function */
main();
