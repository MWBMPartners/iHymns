# Full non-Latin Unicode support — #1908 Implementation Spec

**Status: LOCKED SPEC (sequential planning pass, 2026-08-21) — execute verbatim, commit by commit, on a new branch `claude/unicode-nonlatin-1908` off `alpha`, ONE PR.**
Validates + tightens the 8-agent Unicode audit (task `wo96prhb3`); every file:line below was re-verified against the working tree at commit `1aa1b84b`. The audit's factual base holds with the corrections in §0.3. This document is the ONLY spec the implementer needs; do not re-derive.

---

## §0. Validated ground truth (what's solid vs the gaps)

**Solid — do NOT touch (audit-confirmed, spot-re-verified):** storage is utf8mb4 end-to-end (154 tables, every migration DDL, every connection path, one uniform `utf8mb4_unicode_ci` collation); transport is UTF-8 throughout (charset headers, `JSON_UNESCAPED_UNICODE`); the Apple/Android/browser-JS layers are independently clean. Nothing corrupts data at rest. Nothing in this epic touches storage or transport.

**The five confirmed gaps** (all at search/dedup time and at print/import/export boundaries):

| # | Gap | Where (verified) | Commit |
|---|-----|------------------|--------|
| A | Non-Latin titles/lyrics fold to `''` — dedup key + folded-search arm dead for CJK/Cyrillic/Greek/Thai/…; Latin degrades under musl/C-locale | `includes/title_normalize.php:31`; same mechanism `includes/song_similarity.php:71` | 1–3 |
| B | Space-less scripts (CJK/Thai/Lao/Khmer/Burmese) unsearchable at 3+ chars — FULLTEXT can't segment, no LIKE fallback above `mb_strlen < 3` | `includes/SongData.php:3174` | 5 |
| C | Server PDF renders NO glyphs for CJK/Arabic/Hebrew/Thai/Indic — mPDF autofont off, body forced to DejaVu | `includes/pdf_renderer.php:683-690` (constructor), `:185-194` (font map) | 7 |
| D | 4 of 6 CSV exporters omit the Excel UTF-8 BOM | see §4 table | 4 |
| E | TXT importer silently mojibakes UTF-16 uploads; 3 JSON importers hard-reject them | `includes/song_importers.php:267-283`, `:2099-2101`, `:4163`, `:4569` | 6 |

## §0.1 Locked decisions (settled — encode, do not re-open)

| # | Decision |
|---|---|
| **D1** | The new exact fold = **port the CLIENT's own fold server-side**. `js/utils/text.js:54-89` (`foldSearchText` + `FOLD_SPECIAL`) ALREADY implements the target design: `lowercase → Unicode-normalize → strip \p{M} → 10-entry special-letter map (ł ø đ æ œ ħ ß ð þ ı) → [^\p{L}\p{N}\s] strip → whitespace collapse`. PHP uses `Normalizer::FORM_KD` (the `slugifyMusicianName()` precedent, `includes/musician_helpers.php:1023-1032`); the JS mirror is upgraded `'NFD'`→`'NFKD'` for parity (one token). All target outputs are runtime-verified in §1.2. |
| **D2** | The iconv fallback (intl-absent hosts only) is kept but its acceptance guard changes: **accept the iconv result only when it introduces no new `'?'` characters** (`substr_count($folded,'?') === substr_count($t,'?')`). Verified mechanism: glibc `//TRANSLIT//IGNORE` turns `耶稣爱我` into `"????"` (4 chars — NOT `''`, so the current `!== ''` guard never fires), and the `[^\p{L}\p{N}\s]` strip then erases it. The new guard rejects that wholesale and keeps the original code points. |
| **D3** | **`ihymns_sim_fold()` IS fixed too, in its own commit (3).** Evidence: `song_similarity.php:71` has the identical iconv→`"????"`→strip mechanism. Today it FAILS SAFE (`ihymns_sim_text('','')` returns `0.0` at `:120-122` — no false positives, just zero scoring for non-Latin), so it is lower urgency than the exact fold, but fixing it enables non-Latin fuzzy dedup. The two folds stay DISTINCT functions with their own regexes (rule #22: title fold DELETES punctuation, sim fold SPACES it — `test-song-similarity.php:57` asserts `church s one foundation`); only the diacritic-strip step is modernised in both, sharing the ONE `IHYMNS_FOLD_SPECIAL` map. **`ihymns_sim_text()`'s byte-cap bug must be fixed in the same commit** — preserved CJK makes `mb_substr(…,0,255)` (chars) exceed levenshtein's 255-BYTE cap, so the latent `-1 → similarity > 1.0` bug goes live otherwise. |
| **D4** | **The "empty NormalizedTitle must never equality-match" guard ALREADY EXISTS at every consumer** — this pass VERIFIED it (resolving the audit's `needsRuntimeCheck` item by code read): `manage/duplicate-songs.php:735-737` (recompute-live fallback, then `continue` on `''`), `includes/ia_reconcile.php:706` + `:734/:738` (`!== ''` before `exactMap`), `includes/lyrics_ingest.php:523` (whole title-match block inside `if ($norm !== '')`), `includes/song_importers.php:818-820` + `:837-839` (`return []` / `continue`). **No new runtime guard code is needed**; commit 1 instead adds the PROPERTY test that protects them all: the fold never returns `''` for input containing a letter or digit. Note the real live consequence of the old fold: `lyrics_ingest.php:527`'s raw exact-`Title` fast path sits INSIDE the `$norm !== ''` block, so a re-ingest of a non-Latin song never even tries the raw match → **duplicate-mint on every re-ingest**. The fold fix alone repairs this. |
| **D5** | Backfill = a **NEW data-recompute registry card** (`migrate-refold-search-columns.php`, sentinel-probed via `tblAppSettings.search_fold_version='2'` — the `email-login-token-hashing` probe idiom at `manage/includes/migration-registry.php:1418-1426`). **No schema.sql change** (zero DDL). The interim state (fold fixed, backfill not yet run) is SAFE by D4: stale `''` rows never match, grouping/reconcile recompute live with the new fold; only the folded FULLTEXT/LIKE arms stay non-Latin-blind until the card runs (raw `Title`/`LyricsText` arms work regardless). |
| **D6** | `NormalizedTitle` writes get an **`mb_substr(…, 0, 500)` cap** (the column is `VARCHAR(500)`, `schema.sql:275`; `Title` is also `VARCHAR(500)` and NFKD can EXPAND — Hangul syllables decompose to 2–3 jamo — so an uncapped write can throw under STRICT). Cap at every write site (§1.4), never inside the shared fold (it also folds whole `LyricsText` → `MEDIUMTEXT`, which must not truncate). |
| **D7** | Commit 5 (CJK search) = the **cheap parser-independent LIKE arm**: when a 3+-char query contains a space-less-script code point, run `_searchByLike()` FIRST, fall through to the untouched FULLTEXT ladder only if it found nothing. The `WITH PARSER ngram` index alternative (schema change on 6 FULLTEXT indexes + MySQL config + shared-host risk) is the **deferred heavier option** — file it as a follow-up issue, do NOT build it. |
| **D8** | Commit 7 (mPDF autofont) is the LAST commit, clearly flagged **deploy-time-verify**. The needed fonts ARE vendored (verified: `appWeb/private_html/lib/pdf/vendor/mpdf/mpdf/ttfonts/` ships `Sun-ExtA/B` (CJK), `XB Riyaz*` + `LateefRegOT` (Arabic), `TaameyDavidCLM-Medium` (Hebrew), `UnBatang_0613` (Korean), `Garuda*` (Thai), `KhmerOS`, `Padauk-book`, 88 MB total) — the fix is NOT inert. CI gives a real behavioural canary (`test-print-pdf-batch.php` Part B runs the REAL vendored engine and asserts exact page counts), but the `@page`/font-substitution page-metrics-runaway interaction (`pdf_renderer.php:196-221`) and the visual glyph/RTL check can only be finally proven on the real host. |
| **D9** | The UTF-16 sniff helper is a NEW framework-free `includes/text_encoding.php` (one shared module, rule #22) used by the TXT parser and the three JSON parsers. XML importers are OUT of scope (libxml honours in-document encoding declarations); the other BOM-strip sites (`:1806/:2783/:3492/:3994`) are XML/ChordPro and stay untouched. |
| **D10** | OUT OF SCOPE, file as separate issues under epic #1908: (i) `ihymns_tune_slugify()` / `ihymns_publisher_slugify()` non-Latin slugs — both already have a DOCUMENTED, collision-suffixed degenerate fallback (`'tune'`/`'publisher'`, `tune_helpers.php:105-112`, `publisher_helpers.php:77-85`) so nothing breaks, but non-Latin names all share generic slugs; (ii) the `WITH PARSER ngram` upgrade (D7); (iii) `mb_internal_encoding` bootstrap pin + the remaining audit `enhancements` items; (iv) the PRE-EXISTING rule-#19 comment drift between `schema.sql:275` and `migrate-song-normalized-title.php:145` (their COMMENT texts already differ — not ours, do not "fix" in this epic, and do NOT update either comment's stale "iconv ASCII//TRANSLIT" wording: changing schema.sql alone would surface as live-DB divergence on the Schema Audit page). |

## §0.2 Load-bearing rules (re-read before each commit)

- **#19** — the backfill card: ONE entry in `manage/includes/migration-registry.php` (order/cards/probes all derive from it, `:809-810`, `:1024`); probe detects REAL completion (sentinel), never `=> true`. No DDL ⇒ no schema.sql edit, no `@migration-adds`.
- **#22** — `ihymns_normalize_title()` (exact dedup fold) and `ihymns_sim_fold()`/`ihymns_sim_normalise()` (fuzzy fold) stay DISTINCT; the shared piece is only the new `IHYMNS_FOLD_SPECIAL` map + the Normalizer step shape. Never merge them; never fork a third fold.
- **#34** — every new guard/test: tree-derived where it enumerates sites, and **mutation-proven** (break the thing → red → restore) before commit. Each commit's verification section names its mutation.
- **#35** — PHP↔JS fold agreement gets a MECHANISM: the fold test parses `js/utils/text.js`'s `FOLD_SPECIAL` and compares it to PHP's `IHYMNS_FOLD_SPECIAL` (§1.5).
- **#5 / #9** — all new SQL bound; the migration gates every column touch on existence probes; STRICT mysqli throws, so no false-check dead code.
- **CI**: PHP suites are ALL auto-run (`.github/workflows/test.yml:233` globs `tests/php/*.php` — "a suite cannot exist without being run"); JS via `node tools/run-node-tests.js`. New test files are picked up automatically.

## §0.3 Corrections to the audit (facts this pass established)

1. The fold-to-empty MECHANISM is `iconv → "????" → strip`, not iconv returning `''`/`false` — which is why the existing `!== ''` / `!== false` guards at `title_normalize.php:32` and `song_similarity.php:72` never save it. The fix's fallback guard must therefore be the no-new-`'?'` rule (D2), not an empty check.
2. The audit's "two exporters that DO emit the BOM" are `manage/editor/api2.php:5353` and **`manage/editor/api.php:2961`** (not the public `api.php`).
3. `tests/php/test-ia-reconcile-guards.php:358-370` **asserts the Greek fixture folds to `''`** (a sanity check for its `unscorable` path) — commit 3 MUST update that fixture or the suite goes red.
4. The Thai fold over-strips vowels: Thai vowel signs are `\p{Mn}`, so `พระเยซู → พระเยซ`. This is CONSISTENT on both the stored and query side (matching works) and mirrors the deliberate Arabic-harakat insensitivity — document it in the fold's doc-block, do not special-case it.
5. `test-print-pdf-batch.php` Part B **executes the real vendored mPDF in CI** and asserts exact page counts — the page-metrics-runaway class has a CI canary; commit 7 extends the same pattern.

---

## §1. Commit 1 — the Unicode-preserving exact fold (`title_normalize.php`) + write caps + JS parity

**Goal:** non-Latin titles survive `ihymns_normalize_title()`/`ihymns_search_fold()`; Latin outputs are byte-identical to today's glibc behaviour and become locale-INDEPENDENT (fixes `Miłość→mio` on musl/C-locale).

### §1.1 The change — `appWeb/public_html/includes/title_normalize.php`

- Add, above the functions (guarded `if (!defined(...))` — PHP array constant):
  `define('IHYMNS_FOLD_SPECIAL', ['ł'=>'l','ø'=>'o','đ'=>'d','æ'=>'ae','œ'=>'oe','ħ'=>'h','ß'=>'ss','ð'=>'d','þ'=>'th','ı'=>'i']);`
  — **byte-identical keys/values to `js/utils/text.js:58-61` `FOLD_SPECIAL`** (the lockstep test in §1.5 enforces this).
- Rewrite the body of `ihymns_normalize_title()` (currently `:27-41`) to this normative shape (implementer adds the house-style ELI5/detailed annotations + doc links):

```php
$t = trim($title);
if ($t === '') { return ''; }
if (class_exists('Normalizer')) {
    /* Deterministic, locale-independent (ext-intl / ICU). FORM_KD also folds
       full-width Latin (Ａ→A) and the U+3000 ideographic space to ASCII space. */
    $n = \Normalizer::normalize($t, \Normalizer::FORM_KD);
    if (is_string($n)) { $t = $n; }
    $t = (string)preg_replace('/\p{M}+/u', '', $t);
} else {
    /* intl-absent fallback: best-effort iconv, ACCEPTED only when it minted no
       new '?' — glibc TRANSLIT substitutes '?' per untransliterable char
       ("耶稣爱我" → "????"), which the strip below would erase to ''. */
    $folded = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $t);
    if (is_string($folded) && $folded !== ''
        && substr_count($folded, '?') === substr_count($t, '?')) { $t = $folded; }
}
$t = mb_strtolower($t, 'UTF-8');
$t = strtr($t, IHYMNS_FOLD_SPECIAL);
$t = (string)preg_replace('/[^\p{L}\p{N}\s]+/u', '', $t);
$t = (string)preg_replace('/\s+/', ' ', $t);
return trim($t);
```

- Update the file + function doc-blocks: the fold now PRESERVES non-Latin scripts (state fact #4 from §0.3 about Thai/Arabic mark-stripping; state the Hangul NFKD-jamo note: `예수` stores as 4 decomposed jamo — self-consistent because both sides fold identically). `ihymns_search_fold()` (`:65-68`) stays an untouched alias.

### §1.2 Runtime-verified truth table (this pass executed all of these against PHP 8 + ICU — assert them verbatim in tests)

`Café→cafe · Noël→noel · José→jose · Miłość→milosc · Bjørn→bjorn · Niño→nino · aren’t→arent · ’Tis→tis · Ris'n→risn · Amazing Grace→amazing grace · Ａｍａｚｉｎｇ→amazing · 耶稣爱我→耶稣爱我 · Иисус→иисус · Αγάπη→αγαπη · Χριστός Ανέστη→χριστος ανεστη · พระเยซู→พระเยซ · 예수→예수 (4 jamo) · イエス→イエス · ♪ ♫ ♬→'' (legitimately — no letters)`. Idempotence holds for all.

### §1.3 JS mirror parity — `appWeb/public_html/js/utils/text.js`

- `:84`: `'NFD'` → `'NFKD'`; adjust the `:69-75` comment (the mirror is now step-identical, though still compare-time-only for the offline path — consumer `js/modules/search.js` folds both sides, no stored contract).

### §1.4 Write-site caps (D6) — one line each

- `includes/search_fold.php:121`: `$normTitle = mb_substr(ihymns_search_fold($title), 0, 500);` (leave `$foldedText` uncapped).
- `manage/editor/api2.php:522-530` `ed2_normalizeTitle()`: wrap the return in `mb_substr(…, 0, 500)` (it only ever feeds the column).
- `appWeb/.sql/migrate-song-normalized-title.php:199` and `appWeb/.sql/migrate-search-synonyms.php:140` (the `$nt` title fold only, `:141` lyrics fold stays uncapped): same cap — these legacy backfills now emit the NEW fold on any future/fresh run and must not overflow.

### §1.5 Tests — extend `tests/php/test-search-fold.php` Half 1

1. Existing unconditional assertions (`:78-97`) — **must stay green unchanged** (verified they hold in §1.2).
2. The two capability GATES (`:101`, `:112`) become `class_exists('Normalizer')` gates; when intl is present the accent + special-letter classes assert UNCONDITIONALLY (they are now deterministic); `skip()` only on an intl-absent host.
3. NEW non-Latin preservation table (from §1.2): CJK, Cyrillic, Greek, Thai, Hangul, Katakana, full-width — each folds non-empty and idempotent; plus the **universal property test** protecting every D4 guard site: for every fixture containing `\p{L}` or `\p{N}`, the fold is `!== ''` — including under the FALLBACK branch (test the guard logic by asserting `substr_count` acceptance rejects a `"????"`-shaped candidate).
4. NEW rule-#35 lockstep: read `appWeb/public_html/js/utils/text.js`, extract the `FOLD_SPECIAL = {...}` object literal (regex on the comment-stripped source), parse its pairs, assert set-equality with `IHYMNS_FOLD_SPECIAL` keys AND values, and assert `text.js` contains `normalize('NFKD')`.
5. Halves 2–3 of the file (funnel guard, DB half) need no change — Half 2 builds its fixtures through the live fold (`:280-281`), so it self-adjusts.

**Verification:** `php tests/php/test-search-fold.php`; full `tests/php/*.php` sweep green (notably `test-song-similarity.php` — untouched by this commit since `song_similarity.php` is unchanged; `test-ia-reconcile-guards.php` — still green because commit 3 hasn't changed the sim fold yet); `find appWeb -name '*.php' -exec php -l {} \;`; `node --check` on text.js. **Mutation proofs:** (a) revert the `strtr` map line → Miłość assert red; (b) change one JS map value → lockstep red; (c) re-introduce the old `!== ''` iconv guard in the fallback branch → property-test red.

## §2. Commit 2 — the refold backfill card (`migrate-refold-search-columns.php` + ONE registry entry)

**Goal:** recompute the STORED `tblSongs.NormalizedTitle` + `tblSongs.LyricsTextFolded` with the new fold so the folded FULLTEXT/LIKE search arms work for non-Latin content (the stored rows currently carry `''` for every non-Latin song — MATCH/LIKE against them is a permanent miss until re-folded).

- **New** `appWeb/.sql/migrate-refold-search-columns.php`, modelled on `migrate-song-normalized-title.php` (Id-keyed batches, progress tick every 500, `_mig*_output` helper, runner-aware include per rule #41 — it needs `title_normalize.php`, so resolve via `IHYMNS_INCLUDES_DIR` with the `/public_html/includes/` literal ONLY as the standalone/CLI repo fallback; NEVER a column-0 unconditional literal require, `test-deploy-paths.php` bans it).
  - Gate: `NormalizedTitle` column exists, else `[SKIP] run the NormalizedTitle card first` and **exit WITHOUT writing the sentinel** (probe stays pending — correct, per rule #19).
  - Recompute `NormalizedTitle = mb_substr(ihymns_search_fold(Title),0,500)` for ALL rows; recompute `LyricsTextFolded = ihymns_search_fold(LyricsText)` only when THAT column exists (existence-probed; if it arrives later, `migrate-search-synonyms.php`'s own backfill folds it with the new fold anyway).
  - Finish: `INSERT INTO tblAppSettings (SettingKey, SettingValue) VALUES ('search_fold_version','2') ON DUPLICATE KEY UPDATE SettingValue = '2'`. Idempotent + re-runnable by design.
- **ONE** registry entry `'refold-search-columns'` in `manage/includes/migration-registry.php`, positioned AFTER `'search-synonyms'`; card body says what it recomputes and why (fold v2, #1908); probe = the sentinel idiom at `:1418-1426`: pending unless `SettingValue === '2'`. NOT `'manual'` — safe for "Apply all".
- **No schema.sql change** (no DDL). No `@migration-adds`.
- Ops note (goes in the PR body + the card body): after the card runs, **re-run `includes/tools/build-song-link-suggestions.php`** so `tblSongLinkSuggestions` scores refresh once commit 3 lands (stale scores are human-reviewed suggestions — harmless meanwhile). `tblIaFetchCache`-family stored `NormTitle` rows are deliberately NOT refolded: the scorer recomputes live from `RawTitle` (`ia_reconcile.php:733/:737`); stored values refresh on the next reconcile run.

**Verification:** `php tests/php/test-migration-registry.php` (new probe registered, not always-true) + `test-schema-coverage.php` + `test-deploy-paths.php` green; run the script standalone against a scratch DB with a seeded non-Latin row, assert the stored value becomes the §1.2 fold and the sentinel lands; re-run → `[SKIP]`s + same end state. **Mutation proof:** flip the probe to compare `'1'` → registry test/dashboard shows applied-while-pending mismatch (probe test red or card visibly wrong); restore.

## §3. Commit 3 — the fuzzy fold (`song_similarity.php`) + the byte-cap fix + fixture updates

**Goal:** non-Latin text becomes fuzzy-scorable (duplicate suggestions, IA reconcile, musician-name similarity) without altering any existing Latin score.

- `includes/song_similarity.php` `ihymns_sim_fold()` (`:64-80`): `require_once __DIR__ . '/title_normalize.php'` (for the map), then replace ONLY the iconv step (`:68-74`) with the SAME Normalizer-else-guarded-iconv block as §1.1 plus `$s = strtr($s, IHYMNS_FOLD_SPECIAL);` — **keep this fold's own regexes untouched** (`[^\p{L}\p{N}\s] → ' '` space-replacement at `:76`, collapse at `:78`; that punctuation-to-SPACE behaviour is load-bearing: `test-song-similarity.php:57` asserts `church s one foundation`).
- `ihymns_sim_text()` (`:118-131`): replace `mb_substr($a,0,255)`/`mb_substr($b,0,255)` (`:123-124` — a CHAR cap; 255 CJK chars = 765 bytes) with `mb_strcut($a,0,255)`/`mb_strcut($b,0,255)` (BYTE cap at a UTF-8 boundary), and add `if ($d < 0) { return 0.0; }` after `levenshtein()` — the audit-flagged `-1 → similarity > 1.0` overflow becomes reachable once CJK survives the fold.
- `tests/php/test-ia-reconcile-guards.php:355-370` (§0.3 fact 3): the Greek candidate now folds to `χριστος ανεστη` — replace the fixture's `rawTitle`/`firstLine`/`bodyExcerpt` with a symbols-only pair (e.g. `'♪ ♫ ♬'` / `'♪♪♪♪'` — `\p{So}`, folds to `''` under the NEW fold, runtime-verified §1.2), update the `:369` sanity-check label + the `:411` verdict lookup key to the new title. Counts assertion (`:414-417`) then holds unchanged. ADD one new check: a Greek-script candidate is now SCORABLE (`ihymns_sim_normalise('Χριστός Ανέστη') === 'χριστος ανεστη'`).
- `tests/php/test-song-similarity.php`: existing three normalise fixtures (`:57-61`) hold verbatim (verified). ADD: a non-Latin pair scores > 0 (e.g. two near-identical CJK titles), an identical non-Latin pair scores 1.0, and a >255-byte CJK pair returns a value `<= 1.0` (the `-1` guard).

**Verification:** `test-song-similarity.php`, `test-ia-reconcile-guards.php`, `test-musician-name-similarity.php`, `test-musician-dedup-shared-scorer.php`, `test-musician-dup-scan.php` all green. **Mutation proofs:** (a) drop the `$d < 0` guard with a 300-byte CJK fixture → red; (b) revert the fold step → the new Greek-scorable check red.

## §4. Commit 4 — CSV UTF-8 BOM: ONE shared emitter + tree-derived guard

**Goal:** all six CSV exporters emit the Excel BOM through one helper so they can never diverge again (rule #22).

- `includes/csv_safe.php`: add `ihymns_csv_output_begin()` — opens `php://output` (`'wb'`), `fwrite`s `"\xEF\xBB\xBF"`, returns the stream. Doc-block: WHY (Excel-on-Windows ignores the HTTP charset for downloaded .csv; decodes system-ANSI without a BOM).
- Route all six sites through it (each currently verified):

| Site | Today | Change |
|---|---|---|
| `manage/activity-log.php:588` | `fopen`, no BOM | `$out = ihymns_csv_output_begin();` |
| `manage/analytics.php:61` | `fopen`, no BOM | same (`$fp`) |
| `includes/ccli_report.php:434` | `fopen('…','wb')`, no BOM | same |
| `api.php:10421` | `fopen`, no BOM | same + local `require_once __DIR__ . '/includes/csv_safe.php';` in the `case 'csv':` (api.php does not currently include it; the helper is `function_exists`-guarded) |
| `manage/editor/api2.php:5353-5354` | `echo BOM` + `fopen` | replace BOTH lines with the helper (no double BOM) |
| `manage/editor/api.php:2961-2962` | `echo BOM` + `fopen` | same |

- Do NOT touch `api.php:10424`'s raw `fputcsv` (the public export's formula-escape posture is a separate, deliberate question — note it in the PR, file under #1908 if the owner wants it revisited).
- **New guard** `tests/php/test-csv-bom.php` (tree-derived, comment-stripped via the `token_get_all` idiom from `test-search-fold.php`): enumerate every `.php` under `appWeb/public_html` whose stripped source contains `text/csv`; assert each contains `ihymns_csv_output_begin(`; assert NO file except `includes/csv_safe.php` contains `fopen('php://output'` or `echo "\xEF\xBB\xBF"`. Functional half: ob_start + call the helper + assert the first three bytes.

**Verification:** suite green. **Mutation proofs:** (a) revert one site to raw `fopen` → red; (b) re-add the old `echo BOM` beside a helper call → red (double-BOM ban).

## §5. Commit 5 — space-less-script search arm (`SongData.php`)

**Goal:** 3+-char CJK/Thai/Lao/Khmer/Burmese queries return results (today: `searchSongs()` routes them to `MATCH … AGAINST`, which cannot segment a space-less run → zero hits; 1–2-char queries already work via `_searchByLike`).

- `includes/title_normalize.php`: add framework-free predicate `ihymns_contains_spaceless_script(string $q): bool` → `(bool)preg_match('/[\p{Han}\p{Hiragana}\p{Katakana}\p{Thai}\p{Lao}\p{Khmer}\p{Myanmar}]/u', $q)`. Doc-block: exactly the scripts MySQL's whitespace tokenizer cannot segment; Hangul/Cyrillic/Greek/Arabic/Hebrew/Devanagari are space-delimited and deliberately EXCLUDED (their FULLTEXT path works).
- `includes/SongData.php` `searchSongs()` — inside the `else` branch at `:3176`, BEFORE the existing `$primary` build (`:3183`), insert:

```php
if (ihymns_contains_spaceless_script($query)) {
    /* #1908 — space-less scripts can't be segmented by the whitespace FULLTEXT
       parser; the substring path already works for them (it is the <3-char route). */
    $results = $this->_searchByLike($query, $songbookId, $limit, $offset, $includeLyrics, $langSubtags, $sortSpec);
}
if (empty($results)) {
    …existing steps 1–2 unchanged (:3183-3218)…
}
```

  (`title_normalize.php` is already `require_once`'d at `:3165`.) The Latin path is byte-identical (predicate false → zero change). Mixed Latin+CJK queries: LIKE first (contiguous match), FULLTEXT loose-arm fallback can still catch the Latin tokens. Downstream `_attachSearchResultCredits`/`_attachLyricsSnippets` (`:3223-3230`) already handle `_searchByLike` rows (the <3-char route uses them today), and the post-commit-1 fold preserves CJK so the folded snippet needle (`:3706`) works.
- **New** `tests/php/test-search-spaceless-script.php`: (a) predicate truth table — `耶稣爱我`/`イエス`/`ひらがな`/`พระเยซู`/`ខ្មែរ`/`မြန်မာ`/`ລາວ` true; `Amazing`/`Иисус`/`Αγάπη`/`예수`/`שלום`/`العربية` false; (b) static, comment-stripped: `searchSongs()`'s `else` branch calls `ihymns_contains_spaceless_script(` and the call appears textually BEFORE the first `_runFulltextSearch(` (the `test-print-pdf-batch.php:227-234` position-assert idiom).

**Verification:** suite green (no existing search test asserts the 3+ branch shape). **Mutation proofs:** (a) remove `\p{Thai}` from the predicate → truth-table red; (b) move the LIKE arm after the FULLTEXT steps → position assert red.

## §6. Commit 6 — UTF-16 detection: `includes/text_encoding.php` + importer wiring

**Goal:** a Windows "Unicode" (UTF-16LE) `.txt` imports correctly instead of silently storing mojibake (`中` = bytes `2D 4E` read as `-N`, valid UTF-8, NO error today); UTF-16 JSON exports from worship tools import instead of dying on a generic `json_decode` error.

- **New** `includes/text_encoding.php` (framework-free): `ihymnsTextToUtf8(string $raw): ?string` — detection ladder, each rung `mb_convert_encoding` + `mb_check_encoding($out,'UTF-8')` re-validation, returned text always BOM-free:
  1. `''` → `''`.
  2. UTF-32 BOMs first (they contain the 16LE BOM as a prefix): `FF FE 00 00` → UTF-32LE; `00 00 FE FF` → UTF-32BE.
  3. `FF FE` → UTF-16LE; `FE FF` → UTF-16BE (convert `substr($raw, 2)`).
  4. `EF BB BF` → strip, validate as UTF-8.
  5. No BOM + `mb_check_encoding($raw,'UTF-8')` → return as-is.
  6. No BOM + invalid UTF-8 → interleaved-NUL heuristic over the first 1024 bytes: >40 % of ODD offsets NUL and evens mostly non-NUL → UTF-16LE; the converse → UTF-16BE; convert + re-validate.
  7. Otherwise → `null` (caller rejects with a CLEAR error — never import garbage).
- Wire four parsers in `includes/song_importers.php`, each at its head, each `require_once`-ing the helper and mapping `null` to ITS OWN existing failure shape (they differ — respect each):
  - `_bulkImport_parseTxt` (`:267`): `[null, 'file is not UTF-8 (or UTF-16) text — re-save it as UTF-8']`; the now-redundant per-line BOM strip at `:283` may stay (harmless) — do not restructure the title walk.
  - `_bulkImport_parseVideoPsalmSongbook` (`:2099-2101`): replace the BOM-strip line; fail shape `[null, null, $msg]`.
  - `_bulkImport_parseFreeShow` (`:4163`): replace; fail shape `[null, $msg]`.
  - iHymns interchange (`:4569`): replace; fail via the local `$fail($msg)` (NOTE: keep the `_BULK_IMPORT_IHYMNS_MAX_BYTES` size check BEFORE conversion — it is a memory bound on the raw bytes).
- **New** `tests/php/test-text-encoding.php`: functional truth table (UTF-16LE/BE with BOM, BOM-less UTF-16LE via the heuristic, UTF-32LE with BOM, UTF-8 with/without BOM, plain ASCII, CJK UTF-8 passthrough BYTE-IDENTICAL, `random_bytes` garbage → `null`, `''` → `''` — build fixtures with `mb_convert_encoding` in the test, no binary files) + a tree-derived wiring half: comment-stripped `song_importers.php` contains exactly the four `ihymnsTextToUtf8(` call sites, one inside each named function (balanced-brace body extraction, the `test-print-pdf-batch.php` A5 idiom).

**Verification:** `test-videopsalm-parser.php`, `test-freeshow-parser.php`, `test-ihymns-json-import.php`, `test-import-format-coverage.php` stay green (their fixtures are UTF-8 → rung 4/5 passthrough, byte-identical). **Mutation proofs:** (a) remove the heuristic rung → BOM-less fixture red; (b) drop one wiring call → wiring half red; (c) make rung 5 return converted-instead-of-identical bytes → passthrough assert red.

## §7. Commit 7 — mPDF autofont (⚠️ DEPLOY-TIME VERIFY) — `pdf_renderer.php`

**Goal:** server PDFs render CJK/Arabic/Hebrew/Thai/Indic glyphs (today: blank — body forced to DejaVu, which has no coverage there; browser-Print unaffected).

- `includes/pdf_renderer.php:683-690`: add to the `new \Mpdf\Mpdf([...])` constructor array, after `'format'`:
  `'autoScriptToLang' => true,` `'autoLangToFont' => true,`
  with an annotation block stating: (i) mPDF then per-run script-detects and resolves its OWN shipped fonts (D8's verified list) — no new font files, no custom `fontDir`; (ii) ⚠️ this changes font resolution, which INTERACTS with the `_pdfAdaptCss()` `@page`-strip workaround (`:196-221`) — that strip MUST stay, and any future relaxation re-runs the page-count check; (iii) first non-Latin render generates ttfontdata caches in `tempDir` (one-time cost, Sun-ExtA is large).
- **New** `tests/php/test-pdf-nonlatin-fonts.php`, three parts mirroring `test-print-pdf-batch.php`'s shape:
  - **A (static, always runs, comment-stripped):** the constructor array contains both flags; the `@page`-strip `preg_replace` at `:221` still exists.
  - **B (behavioural, SKIP with a message when the vendored engine is absent):** render a 1-doc body containing `耶稣爱我` + `עִבְרִית` + `العربية` + Latin chrome → assert `%PDF-` prefix, **page count === 1** (reuse Part B's page-count technique — this is the page-metrics-runaway canary in CI), and **font-embed evidence**: the PDF bytes reference a non-DejaVu font whereas a Latin-only control render of the same template embeds ONLY DejaVu. Implementer: dump the real embedded `/BaseFont` names once (e.g. mPDF subset tags like `MPDFAA+Sun-ExtA` — the exact string may drop the hyphen) and pin what is actually there; do NOT guess the string.
  - Mutation proof for B: remove the two flags → the font-evidence assert goes red (only DejaVu present). That same run doubles as proof the assertion detects the pre-fix state.
- **DEPLOY-TIME VERIFY (mandatory, cannot be closed from CI — say so in the issue/PR):** on the real host, via `manage/print-pdf.php`: (1) a CJK song renders visible glyphs; (2) an Arabic/Hebrew song renders with correct RTL shaping/direction; (3) a 1-page Latin song WITH a custom template + custom CSS still renders 1 page (the `:196-221` runaway class, re-checked with autofont live); (4) render time/memory acceptable after the first cache-warming render. Only after this does the commit's issue close.

**Verification:** `test-print-pdf-batch.php`, `test-print-one-renderer.php`, `test-print-custom-layout.php`, `test-print-usage-ccli-gate.php`, `test-print-pdf-img-src.js` all stay green (their Part A scans don't assert constructor contents; their Part B page-count asserts are exactly the canary we want exercised WITH the flags on).

---

## §8. Adversarial review — what could regress, and the specific defence

1. **Latin-fold fixtures** — every existing unconditional assertion in `test-search-fold.php:78-97` and `test-song-similarity.php:57-61` was pre-verified against the new fold (§1.2); the two capability-gated classes become stronger, not different. The ONE test that asserts the OLD broken behaviour (`test-ia-reconcile-guards.php:369` Greek→`''`) is explicitly rewritten in commit 3 — commits 1–2 do not touch `ihymns_sim_normalise()`, so the suite is green at EVERY commit boundary.
2. **Stored `NormalizedTitle` vs the immediate guard** — interim state (commit 1 live, card not run) verified safe: all five equality consumers guard/recompute (D4); the folded search arms simply stay non-Latin-blind until the card runs, and `MATCH('' …)`/`'' LIKE '%q%'` can never false-match. The reverse skew (card run on the shared DB while an older docroot still folds with iconv) is the real cross-channel hazard: **deploy commit 1 to all three docroots before running the card** — same discipline as rule #25's C-phases; say so on the card body.
3. **The Latin FULLTEXT path** — commit 5's arm is entered only via the new predicate; a Latin/Cyrillic/Greek/Hangul query takes a byte-identical route (mutation-tested position assert). The folded-arm SQL shape is untouched in every commit.
4. **NFKD expansion overflow** — Hangul/ligature/full-width folds can EXPAND; `NormalizedTitle` writes are capped `mb_substr(…,0,500)` at every site (§1.4). Residual: a >500-char-fold title compares stored-capped vs live-uncapped in `duplicate-songs.php:736` — a grouping MISS (never a false match) on an implausible input; accepted.
5. **Hangul decomposed storage** — `NormalizedTitle` for Korean holds NFKD jamo (display-odd if ever rendered). It is a dedup/search key, never rendered anywhere (verified: consumers are MATCH/LIKE/equality only). Documented in the fold doc-block.
6. **mPDF page-metrics runaway** — the known trigger (`@page` + font substitution) is already stripped (`:221`) and STAYS; commit 7's Part B page-count assert plus the existing batch test's exact-page-count asserts are CI canaries; the real-host check is mandatory before close (D8). If the runaway reappears WITH autofont, the commit is a clean one-line revert (two flags).
7. **Double-BOM** — the two exporters that already emit a BOM have their `echo` REPLACED by the helper in the same commit, and the guard bans the literal `echo "\xEF\xBB\xBF"` outside `csv_safe.php` (§4 mutation b).
8. **UTF-16 false positives** — every heuristic conversion is re-validated with `mb_check_encoding`; valid UTF-8 short-circuits at rung 5 BEFORE the heuristic, and the CJK passthrough test pins byte-identity, so no existing UTF-8 upload can be mis-converted.
9. **intl-absent host** — every Normalizer use is `class_exists`-gated (the `musician_helpers.php:1026` precedent); the fallback's no-new-`'?'` guard means even WITHOUT intl, non-Latin now survives (un-accented rather than erased) and both store + query fold on the SAME host, so matching stays internally consistent.
10. **Suggestion-score skew** — `tblSongLinkSuggestions` rows scored under the old fold coexist with new-fold live scoring until the builder re-runs (ops step, §2). They are human-reviewed suggestions with a Dismiss path — no destructive action keys off them.

## §9. Definition of done

- [ ] 7 commits on `claude/unicode-nonlatin-1908`, each individually revertable, each leaving the FULL PHP+JS test suite green; one PR to `alpha`.
- [ ] `php -l` sweep + `node --check` sweep clean; every new test mutation-proven (the proof runs noted in each commit message).
- [ ] The refold card visible on `/manage/setup-database`, pending until run, applied after; run ONCE per shared DB **after** all three docroots carry commit 1.
- [ ] Behavioural proof captured in the PR: search for `耶稣爱我` returns the seeded song (post-card); a UTF-16LE `.txt` imports with correct text; a CSV from each of the four fixed exporters opens un-mojibaked in Excel (or at minimum `hexdump` shows the BOM); the commit-7 CI Part B font evidence.
- [ ] Deploy-time verify list from §7 executed on the real host and recorded on the PDF commit's issue before it closes.
- [ ] Issues filed (rule: every identified task, at discovery): the epic #1908 sub-issues per commit; the D10 follow-ups (slugifiers, ngram option, mb bootstrap pin + remaining audit enhancements, the pre-existing schema-comment drift, `api.php:10424` raw `fputcsv`); `.claude/` docs + handoff updated per `standing-tasks.md`.
