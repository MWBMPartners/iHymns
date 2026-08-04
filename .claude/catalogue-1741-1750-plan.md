# #1750 — Public song page renders NONE of the #1741 P1 identity fields — build spec

**Status:** ready to implement (Sonnet-executable, no re-deciding needed)
**Branch:** `claude/wave3-fixes`
**Parent epic:** #1741. **Downstream dependency:** **#1752 (native apps)** — §4 of this
document IS the payload contract #1752 consumes. Do not rename any key in §4 without
re-opening #1752.
**Model precedent:** the Work page shipped this exact family in #1741 P4b —
`includes/pages/work.php` + `SongData::getWork()` + `tests/php/test-work-identity-fields.php`.
This spec deliberately mirrors those shapes rather than inventing new ones (rule #22 spirit:
reuse the fold).

---

## §0 Verified current state (all claims re-checked 2026-08-03 against the tree)

| Claim | Verified | Evidence |
|---|---|---|
| `tblSongs` carries the five P1 columns | ✅ | `appWeb/.sql/schema.sql:267-274` — `Subtitle VARCHAR(500) NULL`, `Disambiguation VARCHAR(255) NOT NULL DEFAULT ''`, `CopyrightYears VARCHAR(100) NOT NULL DEFAULT ''`, `CopyrightHolder VARCHAR(255) NOT NULL DEFAULT ''`, `FirstPublishedYear SMALLINT UNSIGNED NULL`. Migration: `appWeb/.sql/migrate-song-identity-fields.php` (doctags lines 68-72). All five COMMENTs carry the literal `#1741 P1` tag — the tree-derivation hook §6 uses. `TuneName` (#497/#1090), `TuneId` (#1090 P4), `Isrc`/`Upc` (#1064) are NOT P1-tagged, so the derived set is exactly these five. |
| `includes/pages/song.php` renders none of them | ✅ | grep for the five names: **0 hits** in the file (1737 lines). Meta extraction block is lines 152-164 (`$tuneName`/`$iswc`/`$copyright`/`$ccli`); copyright block 827-878; footer 1222-1313. |
| The API payload lacks them | ✅ | `SongData::_fetchSongRow()` SELECT (`includes/SongData.php:3766-3780`) selects Copyright/TuneName/Ccli/Iswc but none of the five. `api.php` `song_detail`/`song_data` (api.php:1045-1164) emits `getSongById()` verbatim → the keys are absent from the wire today. |
| The bulk read lacks them too | ✅ | `getSongs()` SELECT (`SongData.php:2167-2183`) — same legacy column list. **This matters:** the `bulk_songs` loop injects `getSongs()` rows into `pages/song.php` (song.php:22-34), so a header-only fix would render the fields on a direct load and silently drop them on the bulk-rendered path. The shape contract in `_fetchSongRow()`'s doc-block (SongData.php:3744-3750) says exactly this: add a key to either without the other and the file drifts. |
| Content gating does NOT gate these fields | ✅ Confirmed | `includes/content_gating.php` strips ONLY: lyric body/`components` + per-line & whole-song translations/annotations (lines 245-261), media rows (266-295), `hasAudio`/`hasSheetMusic` flags, `offlineAllowed` (299). Identity metadata is the same class as `copyright`/`ccli`/`iswc`, which already pass through untouched. **No change to `content_gating.php`; the rule #28A byte-identical no-op invariant (`test-gating-noop.php`) is untouched.** |
| `page=song` is a shared-cache fragment | ✅ | `api.php:584-597` `$_cacheablePages` includes `'song'`. This change adds **zero** client-side behaviour — pure server-rendered markup — so rule #30 is satisfied by adding no `<script>` at all. No new ES module, no router change. |
| Work-page model exists to mirror | ✅ | `work.php:142-144` (disambiguation `<small>` in `<h1>`), 146-148 (subtitle `<p>`), 96-108 (pub/© line builder), 81-94 + 190-203 (tune registry-slug-preferred link). `getWork()` shape-blind always-present defaults: SongData.php:5040-5052; gated tblTunes resolve 5135-5163; `_worksExtraCols()` probe 4755-4788. |
| Editor already writes the five, camelCase agreed | ✅ | `manage/editor/api2.php:410-414` `ED2_META_FIELDS`: `subtitle`, `disambiguation`, `firstPublishedYear`, `copyrightYears`, `copyrightHolder`. §4 reuses these EXACT key spellings so editor wire, public wire and native wire never diverge. Existence gate: `ed2_songIdentityColsPresent()` (api2.php:493) — editor-side only; the read side gets its own instance-cached probe (§1.1), same as `_worksExtraCols()` vs the editor's probe today. |
| `tblSongExternalIds` exists, has no public reader | ✅ | `schema.sql:3440-3459` (key/value store, `uq_Song_Type_Value`). Readers today: editor api2, the #1749 mirror (`includes/song_external_ids.php`), `song_relocate.php`. Nothing public. §4.3 adds an opt-in API block; **public page render is deliberately deferred** (owner flag §8.1). |
| `/tune/<slug>` honours registry slugs | ✅ | `includes/pages/tune.php:186` resolves `tblTunes.Slug = ?` FIRST, then falls back to name-folds. So emitting a registry slug from the song page is rule-#33-safe (the destination reads it) and strictly better than the current name-fold. |
| JSON-LD lives in the shell, not the fragment | ✅ | `index.php:280` fetches `$ogSong = $songData->getSongById(...)` and builds the `MusicComposition` JSON-LD at 346-402 (emitted with the shell's nonce at 969-974). Because it consumes `getSongById()`, §1 lands the new keys there automatically; §5 just wires them into the JSON-LD array. |
| Live dev DB probe | ⚠️ Not possible from this sandbox — `getDbMysqli()` → "Connection refused" (no `db-credentials` file here). Immaterial to correctness: every read is existence-gated (§1.1) and the migration + `schema.sql` are already merged, so both migrated and un-migrated installs are handled. The implementer on an env with DB runs the §7.4 probe. |

---

## §1 Data layer — `includes/SongData.php` (ONE change feeds fragment + API + JSON-LD + OG)

### §1.1 New probe: `_songIdentityCols()` — clone of `_worksExtraCols()` (SongData.php:4755-4788)

Add immediately after `_worksExtraCols()`/its cache property (~line 4788):

- `private ?array $_songIdentityColsCache = null;`
- `private function _songIdentityCols(): array` — byte-for-byte the `_worksExtraCols()` shape:
  hardcoded constant IN-list (rule #5 carve-out) of exactly
  `['Subtitle', 'Disambiguation', 'FirstPublishedYear', 'CopyrightYears', 'CopyrightHolder']`,
  one prepared `INFORMATION_SCHEMA.COLUMNS` query against `TABLE_NAME = 'tblSongs'`, every
  name bound via `bind_param`, wrapped in `try/catch (\Throwable)` → `[]` (rules #5/#9:
  mysqli STRICT throws; migrations are web-run, so an un-migrated install must degrade,
  never white-screen). Returns the present set keyed by column name.
- Two-register annotation (ELI5 + detailed why), `@see #1750`, `@link` to
  `migrate-song-identity-fields.php` and to `_worksExtraCols()` as the pattern source.
- Do NOT reuse api2's `ed2_songIdentityColsPresent()` — different runtime context
  (manage/editor vs public includes); this mirrors the existing `_worksExtraCols()`-vs-editor
  split. Note that in the doc-block so nobody "unifies" them into a cross-context require.

### §1.2 SELECT fragment + normalisation in BOTH readers

Add a tiny private helper next to `_songbookDisplayAbbrSelect()` (line 690) or inline in each
caller — **helper preferred** (one fold, two call sites):

```php
/* returns e.g. ', s.Subtitle AS subtitle, s.FirstPublishedYear AS firstPublishedYear'
   for whichever P1 columns exist; '' when none do. Column names are the probe's own
   hardcoded constants — never request input (rule #5 carve-out). */
private function _songIdentitySelect(): string
```
Alias mapping (fixed, matches api2's `ED2_META_FIELDS` keys exactly):
`Subtitle→subtitle`, `Disambiguation→disambiguation`, `FirstPublishedYear→firstPublishedYear`,
`CopyrightYears→copyrightYears`, `CopyrightHolder→copyrightHolder` (i.e. `lcfirst`).

**Wire it into BOTH SELECTs** (the §0 shape contract):

1. `_fetchSongRow()` — append alongside the existing gated fragments at SongData.php:3761-3765
   (`$idSelect = $this->_songIdentitySelect();` → interpolated after `{$pubSelect}` in the
   SELECT at 3766-3780).
2. `getSongs()` — same, alongside `$arrSelect` at SongData.php:2163, interpolated into the
   SELECT at 2167-2183.

**Post-fetch normalisation — keys ALWAYS present, shape-blind** (mirror `getWork()`'s
absent-column defaults, SongData.php:5040-5052). In `_fetchSongRow()` (with the other
normalisers, ~3791-3805) and in `getSongs()`'s per-row loop (~2193-2213), add identically:

```php
$row['subtitle']           = (string)($row['subtitle'] ?? '');
$row['disambiguation']     = (string)($row['disambiguation'] ?? '');
$row['firstPublishedYear'] = isset($row['firstPublishedYear']) && $row['firstPublishedYear'] !== null
    ? (int)$row['firstPublishedYear'] : null;
$row['copyrightYears']     = (string)($row['copyrightYears'] ?? '');
$row['copyrightHolder']    = (string)($row['copyrightHolder'] ?? '');
```

Rationale (decision taken — do not revisit): always-present keys give #1752's strict native
decoders a stable contract on every install, exactly as `getWork()` chose for work.php.
Additive keys on the wire are back-compat safe (same reasoning as `redirectedFrom`, api.php:1156-1161).

---

## §2 Web render — `includes/pages/song.php` (mirror work.php's shapes exactly)

All additions are plain server-rendered markup: **no `<script>`, no new JS, no `data-*` needed**
(nothing client-side consumes these). Every value through `htmlspecialchars()`. Every block
renders ONLY when non-empty. Annotate each block two-register with `#1750` + the work.php
line it mirrors.

### §2.1 Variable extraction (insert in the block at lines 152-164)

```php
$songSubtitle    = trim((string)($song['subtitle'] ?? ''));           /* #1750 / #1741 P1 */
$songDisambig    = trim((string)($song['disambiguation'] ?? ''));
$firstPubYear    = $song['firstPublishedYear'] ?? null;               /* int|null */
$copyrightYears  = trim((string)($song['copyrightYears'] ?? ''));
$copyrightHolder = trim((string)($song['copyrightHolder'] ?? ''));
/* Split-copyright display line — PREFER the split when either half is present;
   the legacy free-text Copyright stays the fallback denorm (#1741 P1 contract,
   mirrors the CopyrightYears schema COMMENT: legacy Copyright is NOT auto-parsed). */
$copyrightSplit   = trim($copyrightYears . ' ' . $copyrightHolder);
$copyrightDisplay = $copyrightSplit !== '' ? $copyrightSplit : trim((string)$copyright);
```

### §2.2 Disambiguation — parenthetical inside the `<h1>` (mirror work.php:142-144)

In the `<h1>` at song.php:556, immediately after the verified-badge `<?php endif; ?>`:

```php
<?php if ($songDisambig !== ''): ?><small class="text-muted fw-normal"> (<?= htmlspecialchars($songDisambig) ?>)</small><?php endif; ?>
```

(Yes, it inherits the h1's song-language `lang` attribute; work.php accepts the same and
disambiguations are short curator strings — acceptable, noted, not worth a nested `lang` reset.)

### §2.3 Subtitle — muted `<p>` directly under the `<h1>` (mirror work.php:146-148)

Insert between the `</h1>` (end of line 556) and the alt-titles block (line 557):

```php
<?php if ($songSubtitle !== ''): ?>
    <p class="text-muted mb-1"<?php if ($songPrimaryLang !== ''): ?> lang="<?= htmlspecialchars($songPrimaryLang) ?>"<?php if ($songLangDir === 'rtl'): ?> dir="rtl"<?php endif; ?><?php endif; ?>><?= htmlspecialchars($songSubtitle) ?></p>
<?php endif; ?>
```

(Subtitle is song-content, so it carries the song's `lang`/`dir` like the title does — the
#1200 convention already applied to the `<h1>` on the same line.)

### §2.4 First-published + ©-split — extend the existing copyright block (lines 827-878)

- Change the block's outer condition (line 833) to:
  `<?php if ($copyrightDisplay !== '' || $firstPubYear !== null || !empty($ccli) || $iswc !== ''): ?>`
- Replace the legacy copyright `<p>` (835-840) body text `<?= htmlspecialchars($copyright) ?>`
  with `<?= htmlspecialchars($copyrightDisplay) ?>` and its condition with
  `$copyrightDisplay !== ''`. The `fa-regular fa-copyright` icon already supplies the © glyph —
  do NOT prepend a text "©" (work.php:107 does because it has no icon; here we have one).
- Add a First-published line ABOVE the copyright `<p>` inside the same
  `.song-meta-copyright` div:

```php
<?php if ($firstPubYear !== null): ?>
    <p class="mb-1 small text-muted" data-credit-kind="first-published">
        <i class="fa-regular fa-calendar me-2" aria-hidden="true"></i>
        First published <?= (int)$firstPubYear ?>
    </p>
<?php endif; ?>
```

### §2.5 Footer parity (lines 1222-1313) + rights panel

- Footer copyright (1301-1311): replace the `<?= htmlspecialchars($copyright) ?>` at 1309 with
  `$copyrightDisplay` and the `elseif (!empty($copyright))` at 1306 with
  `elseif ($copyrightDisplay !== '')`. (The `$fullyPublicDomain` branch stays first — a PD song
  still says "Public Domain".) First-published is **header-only** (defensible default: the
  footer is the projection copy; publication year isn't a projection credit — trivially
  changeable if the owner wants parity).
- Rights panel (`$rpCopyright`, line 1324): change to
  `$rpCopyright = $copyrightDisplay;` so the "Why you can use this" card shows the split
  when present. (It already handles empty.)
- Footer outer condition (1231-1238): change the `(!$fullyPublicDomain && !empty($copyright))`
  clause to use `$copyrightDisplay !== ''`.

### §2.6 Tune link — prefer the registry slug (mirror work.php:81-94; rule #33-verified in §0)

song.php currently name-folds `TuneName` in TWO places (719 header, 1261 footer). Compute
ONCE near the §2.1 block:

```php
/* #1750 — prefer the tblTunes registry slug (via the existing scoped include-block
   reader) over the name-fold, exactly as work.php does. One extra gated query, only
   on pages that actually have a tune name; getSongDetailExtras' per-block try/catch
   makes it STRICT-safe on installs without tblTunes/TuneId (SongData.php:2567-2578). */
$tuneSlug = '';
if ($tuneName !== '') {
    $tuneExtras = $songData->getSongDetailExtras((string)($song['id'] ?? $songId), ['tune']);
    $tuneSlug   = (string)($tuneExtras['tune']['slug'] ?? '');
    if ($tuneSlug === '') {
        $tuneSlug = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $tuneName), '-'));
    }
}
```

Then at 718-719 and 1258-1261 delete the local `$_tuneSlug`/`$_tuneSlugFooter` folds and use
`$tuneSlug` in both anchors. (No behaviour change on un-migrated installs — the fallback IS
the current fold. `/tune/<registrySlug>` resolves via tune.php:186.)

---

## §3 What NOT to touch

- `content_gating.php` — nothing (§0). Identity metadata is ungated by design.
- No schema change, no migration, no `schema.sql` edit — the columns shipped in
  `migrate-song-identity-fields.php` already (rule #19/#20 satisfied by that batch; PREFER
  no schema change honoured).
- No `js/` change, no `router.js` change, no service-worker change.
- `og-image.php` — its line 398 "Subtitle" is an unrelated drawn caption for songbook cards;
  leave it. (Optional follow-up, not this issue: draw `$song['subtitle']` on the song card.)
- The legacy `copyright` payload key — stays on the wire unchanged (native clients depend on it).

---

## §4 THE API PAYLOAD — the #1752 contract (call-out: this section blocks #1752)

`song_detail` / `song_data` (api.php:1045-1164) emit `getSongById()` verbatim, so §1 alone
puts these on the wire. **The contract #1752 codes against:**

### §4.1 New top-level keys on `song` in `song_detail`/`song_data` (and on every row of
`songbook_export`/`bulk_songs`, which consume `getSongs()`):

| Key | Type | Presence | Default (column absent or empty) |
|---|---|---|---|
| `subtitle` | string | **always** | `""` |
| `disambiguation` | string | **always** | `""` |
| `firstPublishedYear` | int \| null | **always** | `null` |
| `copyrightYears` | string | **always** | `""` |
| `copyrightHolder` | string | **always** | `""` |

- Spellings are byte-identical to the editor's `ED2_META_FIELDS` keys (api2.php:410-414) —
  one vocabulary across editor, public web, and native (rule #35: the mechanism is §6's
  guard deriving both from schema.sql, not a comment).
- **Ungated**: `contentGatingApply()` never strips them (§0) — native renders them for every
  tier, same as `copyright`/`ccli` today.
- **Display precedence rule (part of the contract, so web and native render identically):**
  show `trim(copyrightYears + ' ' + copyrightHolder)` when non-empty, else legacy
  `copyright`. Never concatenate both.

### §4.2 No new endpoint, no new URL param → no rule-#33 surface added.

### §4.3 Opt-in `include=externalIds` block (the P4/P5 recording-ID surfacing, API-only)

Extend the existing #1099 include machinery (never the default payload — wire shape stays
byte-identical for clients that don't ask):

1. `SongData::songDetailIncludeBlocks()` (SongData.php:2530-2536): add `'externalIds'`.
2. New `case 'externalIds':` in `getSongDetailExtras()` (alongside `'royaltyIds'`, ~2605),
   using the existing `_extrasRows()` helper inside the existing per-block `try/catch`
   (table-absent on an un-migrated install → clean omission, the sibling blocks' exact
   pattern):

```php
case 'externalIds':   /* #1750 / #1741 D5 — recording/release external-ID store, opt-in */
    $rows = $this->_extrasRows(
        'SELECT IdScope AS idScope, IdType AS idType, IdValue AS idValue, Source AS source '
      . 'FROM tblSongExternalIds WHERE SongId = ? ORDER BY IdScope, IdType, IdValue',
        's', [$songId]
    );
    if ($rows) { $out['externalIds'] = $rows; }
    break;
```

   `SourceRef` is deliberately NOT exposed (internal idempotency/ownership ref — see
   `includes/song_external_ids.php`'s ownership model). Block omitted when empty, like
   every sibling. Update `api-docs.yaml`'s `include=` enum if it lists the blocks
   (check `song_detail` there; #1099 documented `tune`… — mirror whatever convention it uses).

---

## §5 Structured data / JSON-LD — `index.php` (the shell owns JSON-LD, not the fragment)

In the `MusicComposition` builder (index.php:346-402), after the `isPartOf` block (396-401),
add — each gated on non-empty, annotated `#1750`:

```php
if (!empty($ogSong['subtitle'])) {
    $musicComposition['alternativeHeadline'] = (string)$ogSong['subtitle'];
}
if (!empty($ogSong['disambiguation'])) {
    $musicComposition['disambiguatingDescription'] = (string)$ogSong['disambiguation'];
}
if (!empty($ogSong['firstPublishedYear'])) {
    /* Year-precision ISO-8601 is valid schema.org Date. */
    $musicComposition['datePublished'] = (string)(int)$ogSong['firstPublishedYear'];
}
if (!empty($ogSong['copyrightHolder'])) {
    $musicComposition['copyrightHolder'] = ['@type' => 'Organization', 'name' => (string)$ogSong['copyrightHolder']];
}
if (!empty($ogSong['copyrightYears']) && preg_match('/^\d{4}$/', trim((string)$ogSong['copyrightYears']))) {
    /* schema.org copyrightYear is a Number — emit ONLY when the free-text field is a
       single plain year; multi-year strings ("1978, 1987") fold into copyrightNotice. */
    $musicComposition['copyrightYear'] = (int)trim((string)$ogSong['copyrightYears']);
}
$ldCopyright = trim(trim((string)($ogSong['copyrightYears'] ?? '')) . ' ' . trim((string)($ogSong['copyrightHolder'] ?? '')));
if ($ldCopyright === '') { $ldCopyright = trim((string)($ogSong['copyright'] ?? '')); }
if ($ldCopyright !== '') {
    $musicComposition['copyrightNotice'] = '© ' . $ldCopyright;
}
```

(No change to the fragment's microdata breadcrumb; no nonce concerns — this array is emitted
by the shell's existing nonce'd `<script type="application/ld+json">` loop at 969-974.)
The Work JSON-LD (index.php:615-647) reads raw columns and is out of scope here — if parity
is wanted later it's a separate small issue.

---

## §6 CI guard — `tests/php/test-song-identity-render.php` (tree-derived + mutation-proven)

**Model:** `tests/php/test-work-identity-fields.php` (read it first; reuse its architecture —
pure core functions + in-memory mutation self-tests run on EVERY invocation — but with a
`tsir` prefix; test files are standalone scripts, so prefixes must not collide). The harness
auto-discovers via glob (`tools/run-php-tests.php` — no registration step, by design).

**Derivation (rule #34 — never a hand-typed list):** slice `tblSongs`'s CREATE TABLE block
out of `appWeb/.sql/schema.sql`, extract every column whose COMMENT contains the literal
`#1741 P1` (the `twifSliceCreateTable`/`twifTaggedColumnsInBlock` logic — copy those cores).
Today that yields exactly the five; a sixth P1 column added later is covered automatically.
`camel = lcfirst(column)` — assert that mapping in a self-test.

**One deliberate upgrade over the P4b guard (per this issue's brief): strip comments before
every substring assertion.** Add `tsirStripPhpComments(string $src): string` using
`token_get_all()` (drop `T_COMMENT` + `T_DOC_COMMENT`, concatenate the rest; non-PHP files —
none here, all four targets are .php — would fall back to raw). A `'subtitle'` that survives
only inside a doc-block must NOT satisfy the check.

**Real assertions (each against comment-stripped source):**

1. Each derived Column name appears in the `_songIdentityCols()` method slice
   (method slicer = `twifSliceMethod` core) — the probe's own IN-list.
2. Each Column appears in the `_fetchSongRow()` slice AND the `getSongs()` slice
   (via the `_songIdentitySelect()` helper's slice counting for both IF the helper carries
   the names and both call sites reference `_songIdentitySelect` — assert: names present in
   the helper slice, and the literal `_songIdentitySelect` present in each of the two
   method slices. That is the drift-proof formulation: renaming/dropping either call site
   goes red).
3. Each derived camelCase key, as a quoted string (`'subtitle'` or `"subtitle"`), appears in
   `includes/pages/song.php`.
4. Each derived camelCase key appears in `index.php` (the JSON-LD wiring).
5. `includes/pages/song.php` contains `copyrightDisplay` (the precedence fold exists) and
   does NOT contain the deleted `$_tuneSlugFooter` (the duplicate fold stays dead).
6. `songDetailIncludeBlocks()` slice contains `'externalIds'`, and the `getSongDetailExtras()`
   slice contains `tblSongExternalIds` but NOT `SourceRef` outside... (keep simple: assert the
   `getSongDetailExtras` slice does not contain `SourceRef` — it must never reach the wire).

**Mutation self-tests (run first, in memory, both directions each):** the slicer finds/stops
correctly (two adjacent fixture methods); the tagged-column extractor FAILS-HIGH and
FAILS-LOW; `tsirStripPhpComments` removes a `/* 'subtitle' */` occurrence (fixture where the
needle exists ONLY in a comment must come back missing) and preserves a real one; the
lcfirst mapping fixture.

**One-time tree mutation proof (do it, then restore — record in the PR):**
temporarily delete the `'subtitle'` render in song.php → guard goes red; restore → green.
Temporarily remove `Subtitle` from `_songIdentityCols()`'s IN-list → red; restore.

---

## §7 Verification plan

1. **Syntax:** `php -l` on: `includes/SongData.php`, `includes/pages/song.php`, `api.php`
   (untouched but cheap), `index.php`, `tests/php/test-song-identity-render.php`.
   `node --check` — n/a (no JS touched); run anyway via the suite.
2. **Suites:** `php tools/run-php-tests.php` (expect prior-count + 1 = 102 passing) and
   `node tools/run-node-tests.js` (49, unchanged). Also re-run the neighbours most likely to
   notice: `php tests/php/test-gating-noop.php`, `test-songbook-render-parity.php`,
   `test-fragment-inline-scripts.php`, `test-bulk-songs-hydration.php`.
3. **Mutation proof** per §6 (both directions, restored).
4. **Live behavioural probe** (needs an env with DB — NOT possible in this sandbox,
   `getDbMysqli()` is connection-refused here; run on dev):
   ```sql
   UPDATE tblSongs SET Subtitle='A test subtitle', Disambiguation='test',
     FirstPublishedYear=1873, CopyrightYears='1978, 1987', CopyrightHolder='Test Pub. Co.'
   WHERE SongId='CP-0001';
   ```
   - `curl -s 'https://<dev>/api?page=song&id=CP-0001'` → fragment contains all four renders,
     copyright line shows the SPLIT not the legacy string.
   - `curl -s 'https://<dev>/api?action=song_detail&id=CP-0001'` → the five keys present,
     correct types; `…&include=externalIds` → block present iff rows exist; without
     `include=` the wire is otherwise byte-identical to before (diff against a pre-change
     capture of a DIFFERENT untouched song).
   - A song with all five empty → zero new markup (view-source diff ≈ empty).
   - `view-source:https://<dev>/song/CP-0001` → JSON-LD carries
     `alternativeHeadline`/`disambiguatingDescription`/`datePublished`/`copyrightHolder`/`copyrightNotice`.
   - Gating no-op: with `content_gating_enabled='0'` (default), confirm `song_detail` for the
     test song is unchanged by the gating path (it already is; `test-gating-noop.php` covers it).
   - Revert the test UPDATE.
5. **Shared-cache sanity:** fragment ETag behaviour unchanged (no per-user data added — all
   five fields are global row data, cache-safe per rule #6).

---

## §8 Owner decision points (everything else above is a taken, defensible default)

**8.1 Public rendering of recording external IDs (tblSongExternalIds) — DEFERRED (the only
real product question).** Non-blocking: nothing here depends on it.
- **The decision:** should the public song page grow a "Recording identifiers" row
  (ISRC/Spotify/MusicBrainz chips) or stay metadata-only?
- **Why it's an owner call:** it's product surface area — these are technical recording-grain
  IDs of debatable value to a congregant, and multi-row (several recordings per song).
- **Options:** (a) render nothing publicly, API `include=externalIds` only — chosen default
  (zero clutter, native/#1752 still gets the data); (b) render an ISRC-only chip mirroring
  the ISWC chip; (c) full chip row. Cost of doing nothing: none — additive later.
- **Recommendation:** (a) now; revisit after #1752 shows what native does with the block.
- **Needed back:** "a", "b" or "c" — one word, whenever.

**8.2 (Flagged, default taken, trivially changeable):** JSON-LD `copyrightHolder` `@type` is
`Organization` (publishers dominate hymn copyrights). If the owner prefers person-detection
heuristics or plain `Person`, it's a one-line change; not worth blocking.

---

## §9 Files touched (complete list)

| File | Change |
|---|---|
| `appWeb/public_html/includes/SongData.php` | §1.1 probe + cache, §1.2 select helper + both SELECTs + both normalisers, §4.3 include block (~90 lines incl. annotations) |
| `appWeb/public_html/includes/pages/song.php` | §2.1-2.6 (~60 lines net) |
| `appWeb/public_html/index.php` | §5 (~30 lines) |
| `tests/php/test-song-identity-render.php` | §6 (new, ~350 lines, self-discovering) |
| `api-docs.yaml` | §4.3 `include=` enum mention if present (check first) |
| GitHub | close #1750 with commit SHA + §7 evidence; note the §4 contract on #1752; standing-tasks checklist |

No migration, no schema.sql change, no JS, no CSS, no new endpoint, no new URL param.
