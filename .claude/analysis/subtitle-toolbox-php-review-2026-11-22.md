# Review: should iHymns use `yama6a/subtitle-toolbox-php` for timed-lyrics export? (#1082)

*Read-only review, 2026-11-22. Nothing in the iHymns repository was changed apart from this file.*

## The short answer

**Don't adopt it. Write our own small shared formatter module, and borrow a few of its ideas.**
The library is not dangerous. It is well tested and carefully written. But it is roughly
**50,000 lines of code that one person has rewritten at high speed** (seven major versions in six
weeks). We would use about four of its 50-plus formats. Those four formats are small, simple
text formats. Our timing data is already stored as whole milliseconds, so the hardest part of
the library (handling fractions of a second safely) does nothing for us. **None of this blocks
anything**: #1082 is still waiting on iLyricsDB #13 for its timing data.

The comment (6898259618) comes from someone outside the project who says openly that the
library is theirs. I treated what it says, and the library's own docs, as claims to check.

## 1. Is it safe? (risk of relying on someone else's code)

I cloned the repository (full history) into a scratch folder and ran its tests from outside
that folder.

| Check | What I found | Verdict |
|---|---|---|
| Author and history | One person wrote 606 of 606 commits. The history was squashed in 2022, then there was almost nothing until **530 commits in October 2026 and 71 in November**. 412 release tags; versions 1.0.0 (1 Oct) to 7.0.0 (15 Nov). A new release goes out automatically on every merge. | **Main risk.** Only one maintainer, a very fast rate of change, and breaking releases come often. |
| Popularity | GitHub: 1 star, 0 forks, 1 open issue. Packagist: 238 downloads in total. | Almost nobody else uses it, so few people are finding its bugs. |
| Licence | MIT. Code borrowed from other projects is listed with pinned commits in `THIRD_PARTY_NOTICES.md` (all MIT, Apache-2.0, BSD-2, ISC, CC0 or OFL, and the fonts only appear in tests). The parts based on Subtitle Edit say MIT at the cited commit. I checked one of those commits (`5b9ee8b`) and its `LICENSE` is MIT. | Fits our closed-source licensing. We would add one MIT row to `LICENSING.md`. Unlike mPDF there is no GPL issue, so it would not have to live outside the docroot for licence reasons. |
| Dependencies | **No runtime packages at all**, only the `dom`, `iconv` and `mbstring` extensions. Optional extras: `curl`, `zlib`, and the author's own `php-glyph-ocr`. The 26 locked packages are all test tools (PHPUnit and friends). No Composer plugins. The only Composer script is `"test": "phpunit"`. | Good. |
| Suspicious code | No `eval`, `unserialize`, `assert`, encoded payloads or hidden install steps. It **does** contain a cURL client for translation services (DeepL, Google, an OpenAI-style API), `proc_open` to start the Tesseract text-recognition program, and file writes. Each of these runs only if you call that feature. The XML loader blocks network access and external entities. | Nothing hostile. But we would re-review that code on every update. |
| Tests and CI | Its CI covers PHP 8.3 to **8.6**, through the author's own shared workflows at a tag that can be moved (`@v2`). Locally on PHP 8.3.6: **9,644 tests, 3 failures**. Two come from a file-permission test that can't work when run as root, and one is a SAMI-format warning (probably the libxml version). **All 1,506 tests for LRC, SRT and VTT pass.** | Strong. The failures don't touch our formats. |
| PHP 8.4 / 8.5 / 8.6 (rule #53) | It needs PHP 8.3 or newer. It calls none of the functions in our 8.6 deprecation list (`metaphone`, `mb_ereg*`, `curl_close`, `is_double`, …), has no `return` inside `finally`, and does not use `SORT_LOCALE_STRING`. | Would pass `test-php86-deprecations.php` as it stands today. |
| Size | 362 source files, about 50k lines (about 54k more lines of tests); 16 MB with test files. | Very large for what we need. |

**So:** it is safe in the sense that nothing in it is hostile and it is well tested. It is risky
because one person maintains it at very high speed and we would carry a large amount of code
we don't use.

## 2. Is it worth it compared with writing our own?

### What iHymns already has

- **Timing tables already exist and need no new schema**: `tblLyricLines.StartTimeMs/EndTimeMs`,
  `tblLyricWords.StartTimeMs/EndTimeMs`, `tblLyricSyllables.StartTimeMs/EndTimeMs`, plus the
  `tblLyrics.HasWordTiming` / `HasSyllableTiming` flags (`appWeb/.sql/schema.sql` ~L767–858). All
  times are **whole milliseconds stored as integers**.
- **Import of timed lyrics already exists**: `includes/lyrics_ingest.php` (TTML → line/word/syllable
  rows), writing through the one lyric-line write path (rule #25).
- **Nothing writes timed formats yet.** Every existing export (OpenSong, OpenLyrics, ProPresenter,
  ChordPro, …) is **client-side JavaScript** in `manage/editor/format-export.js`. The public site
  loads it through `js/modules/export-ui.js` and gets each song from `?action=song_data`
  (one song at a time, rule #17).
- #1082 wants the work done once, with native apps using the same API (rule #48). So a **server-side**
  formatter behind a per-song API action fits better than a new JS exporter, because Swift and
  Kotlin clients could then download LRC/VTT directly.

### How the library behaves on our kind of data (my own test script)

I fed it hymn-like lines with word times, `&`, `<…>`, `-->`, combining letters (Ŋkɔsi), Chinese
and an emoji:

- **UTF-8 comes out correctly.** VTT escapes `& < >` and `-->`; SRT drops word times; rounding is
  half up and the same on every PHP version.
- **It adds a byte-order mark (three hidden bytes at the start of the file) by default** on
  LRC, SRT and VTT. Some players handle this badly. You can switch it off, but only if you remember to.
- **When lyrics are built from scratch, LRC loses line end times.** The "line has ended" stamp is
  written only for lyrics first read from an LRC file, so during an instrumental gap the last line
  would stay lit.
- **The word-time helper takes start times only**, finds each word by searching the text, and
  quietly skips any word it can't find. We already store each word's position, start and end.
- **It has no idea of a syllable** (you would pass syllables in as "words"). Overlapping cues are
  written exactly as given, so the overlap rule is still ours to decide.

### What it would take to write our own

These four formats are tiny:

| Format | What matters |
|---|---|
| LRC | `[mm:ss.xx]text`. Minutes can go past 99. Round milliseconds to hundredths and carry over (59.995 s becomes 1:00.00). Optional ID tags (`[ti:]`, `[ar:]`). An empty stamped line marks a gap. |
| Enhanced LRC | The same, plus `<mm:ss.xx>` before each word or syllable, and a final stamp marking where the last word ends. |
| SRT | Numbered blocks, `hh:mm:ss,mmm --> hh:mm:ss,mmm` (a **comma** before the milliseconds), a blank line between blocks, no blank lines inside a block. |
| WebVTT | A `WEBVTT` header, `hh:mm:ss.mmm` (a **full stop** before the milliseconds), escape `& < >`, never `-->` in text, no blank line inside a cue, and inline `<hh:mm:ss.mmm>` word times that only go upward and stay inside the cue's start and end. |

Our times are already whole milliseconds, so formatting them is simple whole-number arithmetic. A pure
PHP module (say `includes/timed_lyrics_format.php`), taking
`{start, end, text, words[{text, start, end, syllables[]}]}`, is about **200–300 lines**. A
truth-table test (`tests/php/test-timed-lyrics-format.php`) is about **250–400 lines**. It would cover
rounding carry, gaps, overlaps, escaping, UTF-8 by code point (rule #21), CRLF/BOM, and word
times out of order or outside the cue. That is **about 1–2 days**, written to our rules (#21, #34, #53).

So the library saves perhaps a day now. In return we would re-check about 50k lines that
change weekly, it would fit our data less well (start times only, no syllables, no gap markers),
and code we never call that can reach the network and start other programs would sit in our tree. What it gives
us for free that we don't need yet is 50-plus other formats.

## 3. Recommendation

| Option | What it means | Consequence |
|---|---|---|
| A. Install it as-is with Composer | Add it as a dependency | Breaking upgrades every few weeks; 50k lines of code we don't need |
| B. Vendor it (copy a pinned version into the repo, outside the docroot, like mPDF) | A frozen copy | Safer than A, but we then own a 50k-line fork that we will never update, and it still misses our end times and syllables |
| C. Copy ideas only | Read its LRC/VTT edge cases (escaping, rounding, the end-of-line stamp, BOM handling) while writing our own | Small cost; we keep the benefit of its care |
| **D. Write our own tiny shared formatter module (plus C)** | One server-side PHP module behind a per-song API action; web and native both use it | **1–2 days, fits rules #17/#21/#25/#48/#53, nothing to keep re-checking** |

**My pick: D, informed by C.** What the library is good at (dozens of formats, broken input
files, rounding of fractional seconds) is not what we need. What we do need (end times,
syllables, gap markers, a per-song API) it lacks or makes awkward. Owning four small formats
costs less than re-checking fast-moving code we didn't write. **Nothing is blocked:** #1082 still waits on
iLyricsDB #13, so build this when timed data arrives. If many subtitle formats are wanted later,
look at B again, pinned to an exact commit.

**What I need back:** "D", or another letter, so the comment on #1082 can record the decision.

## Draft reply for #1082 (not posted)

> Thanks for the pointer, and for saying up front that it's your library. We appreciate that.
>
> We had a careful look. It's clearly well tested: the LRC, SRT and WebVTT tests all pass for us,
> and the escaping and rounding are handled carefully. For this issue, though, we plan to write
> a small formatter of our own. Our timing data is already stored as whole milliseconds with
> start *and* end times for each line, word and syllable. We only need four simple text formats.
> And we'd rather keep a small, fixed piece of code than bring in a large library that is moving
> quickly. We may well borrow some of the edge cases your tests cover (with credit), and we'll
> keep it in mind if we ever need a wider range of subtitle formats.
>
> This work is also still waiting on timing data from another of our projects, so nothing will
> happen here for a while. Thanks again.

## What I could not check

- **The library's GitHub issues, pull requests, advisories and contributors.** This session can't
  use the GitHub API for that repository. I only saw its public page (1 star, 0 forks, 1 open
  issue, no advisories shown).
- **The author's shared CI workflows** (`yama6a/gha@v2`), beyond the PHP versions they list.
- **A real run on PHP 8.4, 8.5 or 8.6.** Only 8.3.6 is installed here. The 8.6 check was a
  text search against our deprecation list.
- **Every borrowed licence.** I checked only one Subtitle Edit commit.
- **What iLyricsDB #13 will actually supply** (what offset and tempo mean, how syllables are split). The formatter's input
  shape should be confirmed once that is settled.
