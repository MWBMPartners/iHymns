<!--
  bindings/php/media-language/README.md
  Copyright (c) 2026 MeedyaSuite. Licensed under the MIT License.
-->

# MWBM-MEDIA-LANG — PHP implementation

This folder holds the one PHP implementation of the
[Media Language & BCP 47 Policy](../../../docs/standards/media-language-bcp47-policy.md)
(policy ID `MWBM-MEDIA-LANG`). It is a single self-contained file,
`MediaLanguagePolicy.php`, with no Composer dependency and **no PHP
extension requirement at all** — not `intl`, not even `ctype` (everything
that could have used it is a plain regular expression instead), because any
of those can be left out of a particular PHP build. It is meant to be
**copied verbatim** into a plain PHP application — today that means
iHymns, iLyricsDB and NetPLAYERapp — the same way the policy document
itself is copied (see the policy's section 8.3, "Copies in other
repositories").

Runs on **PHP 8.1 or later**, with every PHP extension disabled
(`php -n`) if that is how the host runs it.

## What each file is

- `MediaLanguagePolicy.php` — the whole implementation. One namespace,
  `Mwbm\MediaLanguage`, holding a handful of small classes/enums
  (`Policy`, `LanguageTag`, `TagMatch`, `TagKind`, `MatchLevel`,
  `SubtitleMode`, `Role`). Copy this one file into your project; nothing
  else here needs to travel with it.
- `tests/run-conformance.php` — the conformance test runner (see below).
  Not required at runtime; only useful while checking a change to this file
  or to the reference data.

## Taking a copy into another repository

A consuming repository keeps this file (and the policy document, the test
cases and the reference data — see the policy's section 8.3) as an exact
copy, checked by `scripts/media-lang/check_copies.py` (itself copied
verbatim) in that repository's own CI. First-time setup in a new
repository, from its own root:

```bash
python3 scripts/media-lang/check_copies.py --init <core-commit> \
  --file bindings/php/media-language/MediaLanguagePolicy.php=bindings/php/media-language/MediaLanguagePolicy.php \
  --file docs/standards/media-language-bcp47-policy.md=docs/standards/media-language-bcp47-policy.md \
  --file docs/standards/tests/bcp47-language-policy-v1.json=tests/fixtures/bcp47-language-policy-v1.json \
  --file docs/standards/tests/bcp47-language-policy-v1.schema.json=tests/fixtures/bcp47-language-policy-v1.schema.json \
  --file docs/standards/data/bcp47-language-data-v1.json=docs/standards/data/bcp47-language-data-v1.json \
  --file docs/standards/data/bcp47-language-data-v1.schema.json=docs/standards/data/bcp47-language-data-v1.schema.json \
  --file scripts/media-lang/check_copies.py=scripts/media-lang/check_copies.py
```

(`<core-commit>` is the 40-character commit of `MWBMPartners/MeedyaSuite-core`
to take the files from; the left side of each `--file local=master` pair is
wherever the consuming repository wants to keep its local paths, which do
not have to match MeedyaSuite-core's own layout — adjust the local paths
above to suit.) After that, `python3 scripts/media-lang/check_copies.py`
run in ordinary CI checks the copies have not drifted from the master.
**Never hand-edit a copy** — a hand edit is exactly what `check_copies.py`
is there to catch, and it will be overwritten the next time the repository
takes a new version of the policy anyway.

## Using it

```php
<?php
require '/path/to/MediaLanguagePolicy.php';

use Mwbm\MediaLanguage\Policy;
use Mwbm\MediaLanguage\SubtitleMode;

// Load the reference data once (LANG-001's replacements, LANG-002's
// three-letter table, and so on) — do this once per process/request,
// before calling anything else below.
Policy::loadData('/path/to/bcp47-language-data-v1.json');

// Turning something into a canonical tag (LANG-001):
$tag = Policy::canonicalise('EN-gb');       // $tag->tag === 'en-GB'
$tag = Policy::canonicalise('not a tag');   // $tag->kind === TagKind::Malformed

// Reading an old three-letter field, or an operating-system locale name:
Policy::fromLegacyThreeLetter('ger');       // 'de'
Policy::fromPosixLocale('sr_RS@latin');     // 'sr-Latn-RS'

// The ISO 639-2 codes to WRITE, in both forms (TRACK-070). Unlike the
// functions above, this one always returns a concrete pair, never null —
// 'und'/'und' is the answer whenever nothing better applies:
Policy::iso6392CodesForWriting('de');       // ['b' => 'ger', 't' => 'deu']
Policy::iso6392CodesForWriting('yue');      // ['b' => 'und', 't' => 'und'] (no ISO 639-2 code)

// Building and reading sidecar file names (TEXT-030):
Policy::buildSidecarName('Film', 'en-GB', [], 'srt');                  // 'Film.en-GB.srt'
Policy::buildSidecarName('Film', 'EN', ['forced', 'sdh'], 'srt');      // 'Film.en.sdh.forced.srt'
Policy::buildSidecarName('Film', 'en', [], 'srt', 3);                  // 'Film.en.3.srt'
Policy::parseSidecarName('Mr. Robot', 'Mr. Robot.en.sdh.srt');
// ['tag' => 'en', 'unrecognised' => null, 'roles' => ['sdh'], 'number' => null, 'extension' => 'srt']
// A clash-avoiding number has to be one a reader could make sense of - it
// throws \InvalidArgumentException for 1, 0, a negative number, or more
// than nine digits (999999999 is the largest parseSidecarName() will ever
// read back), rather than silently writing a name nothing could parse:
Policy::buildSidecarName('Film', 'en', [], 'srt', 1); // throws \InvalidArgumentException

// Stored (canonical) order — the same for everyone, everywhere (Part A):
$ordered = Policy::sortCanonicalOrder([
    ['id' => 'a', 'tag' => 'fr'],
    ['id' => 'b', 'tag' => 'ja', 'original' => true],
    ['id' => 'c', 'tag' => 'en'],
]); // b, c, a — the original language's group leads (LANG-010)

$orderedTracks = Policy::sortTrackOrder([
    ['id' => 't1', 'type' => 'audio', 'tag' => 'en', 'roles' => ['commentary']],
    ['id' => 't2', 'type' => 'audio', 'tag' => 'en', 'roles' => []],
]); // t2 (main programme) before t1 (commentary) — TRACK-050

// Menu (presentation) order — different per user, per interface language
// (Part B). This file holds no language names and does not use `intl`;
// $groupCompare is how the caller supplies "how do these two languages'
// localised names sort in my interface language" — for example, backed by
// \Collator (from the `intl` extension) if the application has it:
$collator = new \Collator('en_GB');
$groupCompare = static fn (string $a, string $b): int =>
    $collator->compare(localisedLanguageName($a), localisedLanguageName($b));

$menu = Policy::sortPresentation(
    items: [/* ['id' => ..., 'tag' => ..., 'type' => ..., 'roles' => [...], 'original' => ...] */],
    preferences: ['en-GB', 'fr'],
    groupCompare: $groupCompare,
    accessibility: ['audio_description' => true],
);

// A subtitle menu with "Off" first (UI-060). A `null` entry is the "Off" row:
foreach (Policy::sortSubtitleMenu($items, $preferences, $groupCompare) as $entry) {
    if ($entry === null) {
        // render the fixed "Off" row
    } else {
        // render $entry as an ordinary subtitle option
    }
}

// A menu label, built from already-localised parts (UI-070):
Policy::buildLabel('audio', 'English (United Kingdom)', ['audio_description'], [
    'audio_description' => 'Audio Description',
], '5.1');
// "English (United Kingdom) — Audio Description — 5.1"

// Matching a preference against a candidate tag (MATCH-010 to MATCH-040):
$match = Policy::matchTags('en-GB', 'en');
// $match->level === MatchLevel::General, $match->distance === 1

// Automatic selection (AUTO-010 to AUTO-040) — a different decision from
// menu order; the answer never depends on list position, only on the
// track's own 'id' (AUTO-010's identifier order: identifiers made only of
// ASCII digits sort first, as numbers - "9" before "10" - then, when two
// digits-only identifiers are equal as numbers, as plain text - "01"
// before "1"; every other identifier sorts after, as plain text). Every
// track's 'id' MUST be unique - both functions throw
// \InvalidArgumentException immediately if two tracks share one, rather
// than silently picking one of them:
$chosenAudioId = Policy::selectAudioTrack($audioTracks, ['en-GB'], accessibility: []);
$chosenSubtitleId = Policy::selectSubtitleTrack(
    $subtitleTracks,
    $chosenAudioTag,
    ['en-GB'],
    SubtitleMode::Automatic,
);
```

See the doc comment on each `Policy` method for exactly which policy rules
it implements and what it deliberately does not do.

## Running the conformance tests

`tests/run-conformance.php` runs every case in
`tests/fixtures/bcp47-language-policy-v1.json` (268 cases). A case that
carries `"error": true` (a handful of them: two clearly-wrong sidecar
numbers, a negative one, one over nine digits, and two pairs of tracks
sharing an identifier) means the implementation MUST refuse the input
outright — the runner treats an exception as that case passing and a
normal return, of any value, as it failing.

Some cases are also checked a second way, on top of their normal check:
the `auto_select_audio` and `auto_select_subtitle` sections (excluding
their `error: true` cases, since refusing does not depend on list order)
are each run again with their tracks reversed, to check AUTO-010's "the
same answer whatever order the tracks are listed in"; and every
`canonicalise` case whose expected answer is a real tag (not malformed)
has that answer run back through `canonicalise()` a second time, to check
that canonical form is stable — canonicalising an already-canonical tag
must return it unchanged. Two further checks are PHP-specific
implementation behaviour the shared, language-neutral fixture cases have
no way to express: that canonicalising a tag with 20,000 variant subtags
finishes in well under a second (duplicate-variant detection must be
linear in the number of variants, not quadratic — see the class doc
comment on `parseWellFormed()`), and one identifier-ordering case
(`"99"` before `"1abc"`) chosen specifically because plain byte-string
order would get it wrong, unlike the two ordinary fixture cases for the
same rule. 343 checks in total. Plain PHP, no PHPUnit, so it runs the same
way in every consumer:

```bash
# From this repository's own root, using its own copies of the fixtures
# and reference data (the default when no arguments are given):
php bindings/php/media-language/tests/run-conformance.php

# From a repository that has taken a copy of the policy, pointing at its
# own copies instead:
php bindings/php/media-language/tests/run-conformance.php \
  --fixtures docs/standards/tests/bcp47-language-policy-v1.json \
  --data docs/standards/data/bcp47-language-data-v1.json
```

Exit code is `0` only when every case passed AND the number of cases
actually run matches the number declared in the fixture file (a guard
against a section being silently skipped). Every failure is printed with
the case id, what was expected and what was actually returned. Before a
single case runs, the runner also refuses outright — rather than quietly
running a smaller or differently-shaped test — if the fixture file has a
section this runner has never heard of, is missing a section it needs, has
a section with nothing in it, or has a case that is missing a field the
schema requires.

Needing no PHP extension applies here too — `php -n` (every extension
disabled) runs this file exactly the same as an ordinary `php`.

To check the PHP 8.1 floor without installing an old PHP locally:

```bash
docker run --rm -v "$PWD":/app -w /app php:8.1-cli \
  php bindings/php/media-language/tests/run-conformance.php
```
