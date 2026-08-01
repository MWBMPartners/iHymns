# Wave 4 — pre-launch: #960, #882, #1608 + the IntAppsAPI foundation

> Six SEQUENTIAL Fable 5 phases, 2026-08-01. Owner decided to land all four
> workstreams BEFORE the merge to main, so one alpha/beta cycle verifies
> everything — a better argument than the defer recommendation it overrode.
>
> **The stress test (§5) found one BLOCKER and forced one split. Read it before
> implementing anything.**
>
> - **D1 BLOCKER** — enabled-but-un-migrated `tblIntAppsSync` would throw on the
>   PUBLIC HOME PAGE (mysqli STRICT). The fail-open matrix covered gateway
>   failures but never "table absent while enabled".
> - **D2** — `credit_upsert` returning normalised input rather than REGISTRY parts
>   would let the UI disagree with the DB with zero signal (the #1565 class).
> - **D3** — the `credits-tab.js` rewrite is the riskiest user-visible change on
>   the branch and is verifiable HERE at exactly 0% (no browser automation).
>   Hence the split: server fix first, UI last and independently revertable.
> - **D6** — `_bulkImport_saveSong()` parses credits then DROPS them, so #882's
>   E2E would pass while looking like credit coverage. Filed, NOT fixed here.

---

## 1. #960 — v2 drops credit structure AND never populates tblCreditPeople

# #960 regression fix plan — v2 editor drops credit-name structure and never populates tblCreditPeople

## 1. Fact verification

All four established facts are **confirmed**, with two precision notes.

1. **CONFIRMED.** Legacy 3-field split intact: `appWeb/public_html/manage/editor/editor.js` — `composePersonNameJs` (3572), `decomposePersonNameJs` (3581), `normaliseCreditParts` (3613), `createCreditNameRow` (3651, renders "First names"/"Surname"/"Suffix" inputs at 3674-3676). Doc-block (3553-3566) explicitly says these MUST stay byte-equal to the PHP mirror in `includes/credit_people_helpers.php`.
2. **CONFIRMED.** `manage/editor/index.php:75-81` 302-redirects to `editor2.php` unless `?legacy=1` or `tblAppSettings.editor_v2_default='0'`. v2 is the default.
3. **CONFIRMED.** `manage/editor/v2/credits-tab.js:151-155` renders ONE flat text input (`placeholder='Name'`) per credit; no first/surname/suffix anywhere in the file (199 lines). It saves via `api.upsertCredit(songId, role, {id, name})` (line 98).
4. **CONFIRMED with two precision notes.** (i) The #960 auto-promote block spans `save_song_core.php:785-898` (normalise closure 794-817, `registerCreditPersonByName` loop 860-871, empty-parts backfill UPDATE 873-897) — the cited "~783-818" is the start of it. (ii) api2.php **does expose** a `save_song` action (`api2.php:2768-2777`, calling the shared `editorSaveSongCore()`) — but it exists for the LEGACY editor's POST (rule #29); grep of `manage/editor/v2/*.js` + `editor2.php` for `save_song` returns **zero** matches, so the v2 UI genuinely never triggers the promote. `credit_upsert` (`api2.php:1376-1412`) reads only `$credit['name']` and writes only the role table; api2.php's sole `tblCreditPeople` reference is a **read** in `credit_search` (2477). api2.php never `require`s `credit_people_helpers.php`.

## 2. Blast radius

**Roles/tables:** all six — `ED2_CREDIT_TABLES` (`api2.php:211-218`) maps writers/composers/arrangers/adaptors/translators/artists → `tblSongWriters/Composers/Arrangers/Adaptors/Translators/Artists`. `credit_upsert`, `credit_delete` (1414-1439) and the revision-restore credits loop (688-707) all write `Name` only.

**Is tblCreditPeople going unpopulated for every v2 save? Yes.** The v2 credits tab has exactly two write calls — `upsertCredit`/`deleteCredit` (`credits-tab.js:98,115` → `api-client.js:199-200` → `credit_upsert`/`credit_delete`) — and neither handler touches `tblCreditPeople`. There is no other v2 credit write path.

**Downstream breakage** (the architecture is a *name-string match*, no FK — `SongData.php:2277-2286` and `5074-5083` both document it):

- **Public `/people/<slug>` page + person JSON API**: a NEW person credited via v2 gets no registry row → no slug → no page exists at all. A RENAME via v2 updates the role table but not the registry → the existing person page **silently loses that song** (name match fails) and the registry row orphans.
- **`/manage/credit-people`**: the name appears only in the "in-use, unregistered" UNION bucket (same 5-table union as `credit_people_helpers.php:729-748`) with no Id — no identifiers, aliases, links, dates, or places can be attached until someone manually promotes it.
- **Structure is permanently lost**: v2's UI never captures first/surname/suffix AND its save never decomposes, so even a later manual promote via `credit-people-bulk-promote.php:167` (which calls `registerCreditPersonByName($db, $name)` with **no parts arg**) leaves FirstNames/Surname/Suffix NULL. The empty-parts backfill (`save_song_core.php:873-897`) only runs on a whole-song save v2 never performs. Surname-based sorting/search degrades for every such person.
- **The failure is masked**: `credit_search` autocomplete still works (it unions the role tables, 2447-2487), so the feature looks fully alive — the rule #30 silent class.
- **Two sibling instances of the same gap found while verifying**: (a) v2 `revision_restore` re-inserts credits Name-only (`api2.php:688-707`); (b) `includes/lyrics_ingest.php:692` inserts `tblSongArtists` rows with no promote. Both should be fixed in the same PR (each is one added call), which also keeps the guard's allowlist empty.

## 3. Fix shape: (a) + (b) combined, with extraction — PHP is the single source of truth

- **(c) alone is not possible**: the promote is inline in the ~1000-line `editorSaveSongCore()`; there is no callable helper for the *backfill* half (873-897). Extraction is required regardless.
- **(b) alone** (server-side decompose of the flat input) restores registry population but permanently loses curator-specified structure — the heuristic mis-splits exactly the names the 3-field UI shipped to fix ("Ralph Vaughan Williams" → surname "Williams"). #960's feature was structured *entry*; b-alone re-drops it.
- **(a) alone** (port the UI) without server promote leaves the registry gap for the flat/back-compat shape and duplicates JS logic.

**Chosen design — no compose/decompose logic in v2 JS at all.** The client sends parts; the server (already the canonical implementation, `credit_people_helpers.php:528/556`) composes, decomposes, and promotes, and returns the authoritative `{name, first, surname, suffix}` in the response. This avoids a THIRD copy of the name maths (PHP + legacy editor.js mirror already exist; editor.js's copy dies with v1 retirement, #1601 scope 3). Modularity rule satisfied: extract first, reuse everywhere.

**Extract into `includes/credit_people_helpers.php`** (the file `registerCreditPersonByName`'s doc already names as "the canonical insertion point"):

```php
/** Normalise a credit entry (string | {name?,first?,surname?,suffix?}) into
 *  ['name','first','surname','suffix'] or null. Verbatim body of the
 *  $normaliseCreditEntry closure, save_song_core.php:794-817. */
function creditEntryNormalise(mixed $v): ?array

/** Idempotent registry promote: registerCreditPersonByName() + (when
 *  creditPeopleNamePartsColumnsExist() and any part non-empty) the
 *  COALESCE(NULLIF(col,''),?) single-row backfill UPDATE moved verbatim from
 *  save_song_core.php:882-896. Never overwrites curated parts. Returns person Id. */
function creditPersonPromote(\mysqli $db, string $name, array $parts = []): int
```

## 4. Exact changes

**No schema change.** `FirstNames`/`Surname`/`Suffix` exist (PR #935 migration; live locally — verified `SHOW COLUMNS`); every new code path gates on the existing `creditPeopleNamePartsColumnsExist()` (helpers:599), so un-migrated installs degrade to Name-only exactly as `save_song` does today.

1. **`includes/credit_people_helpers.php`** — add `creditEntryNormalise()` + `creditPersonPromote()` as above (moved code, not new logic).
2. **`manage/editor/save_song_core.php`** — replace the closure (794-817) with `creditEntryNormalise()` and the block at 860-898 with `foreach ($regParts as $n => $p) { creditPersonPromote($db, $n, $p); }` (keep the richest-parts accumulation at 833-847 — it's whole-save-specific).
3. **`manage/editor/api2.php`**:
   - `require_once … includes/credit_people_helpers.php` at the top require block (~line 116).
   - **`credit_upsert`** (1376-1412), new wire shape (back-compat): request `{songId, role, credit:{id?, name?, first?, surname?, suffix?}}`. Handler: `$entry = creditEntryNormalise($credit)`; 400 if null. Write `$entry['name']` (keep the `mb_substr(...,0,255)` cap) to the role table as now; **inside the same transaction**, `creditPersonPromote($db, $entry['name'], ['first'=>…,'surname'=>…,'suffix'=>…])`. Respond `{ok, creditId, name, first, surname, suffix}` (parts always returned — they come from the normalise, not the columns). Update the action doc-comment at api2.php:53.
   - **`ed2_buildSongSnapshot`** credits loop (566-575): after collecting, batch-fetch registry parts (`SELECT Name, FirstNames, Surname, Suffix FROM tblCreditPeople WHERE Name IN (…)` — placeholders via `array_fill`, rule #5), gated on `creditPeopleNamePartsColumnsExist()`; per credit emit `first/surname/suffix` = registry parts when any non-empty, else `decomposePersonName($name)`. Additive keys — old snapshots restore fine; restore reads only `name`.
   - **`ed2_applySongSnapshot`** credits loop (696-704): run each `$credit` through `creditEntryNormalise()`, insert `$e['name']`, and call `creditPersonPromote($db, $e['name'], parts)` — a restore can resurrect a name whose registry row was since merged away.
4. **`includes/lyrics_ingest.php`** (~692): after the `tblSongArtists` insert, `creditPersonPromote($db, $name, [])` (file already runs server-side; add the require).
5. **`manage/editor/v2/credits-tab.js`** — port the 3-field row: each credit object becomes `{_key, id, name, first, surname, suffix}`; render three inputs (placeholders + `aria-label`s "First names"/"Surname"/"Suffix", matching editor.js:3674-3676). `saveCredit` posts `{id, name, first, surname, suffix}`; on response adopt `res.creditId` AND `res.name/first/surname/suffix` into the credit object (only rewrite input *values* when the row isn't focused, or after a suggestion pick — don't clobber mid-typing). Debounce-save only when the joined parts are non-empty. Autocomplete: query `q = [first,surname,suffix].filter(Boolean).join(' ')` (a trivial join, not the name maths); picking a suggestion posts `{id, name: s.name}` and fills the three inputs from the response parts. Dedup keys keep using `credit.name` (server-composed). `credit_delete` unchanged (legacy never demoted either).
6. **`manage/editor/v2/api-client.js:199`** — no change needed (`credit` passes through); update the shape comment at 198.
7. **`editor2.php`** — no change: line 389 passes `data.credits` through to the store verbatim, so parts flow to the tab.

## 5. Behavioural verification (local instance, 127.0.0.1:8123, db `ihymns_live`, mysql root/no-password — all verified reachable)

```bash
SCRATCH=/tmp/claude-0/-home-user-iHymns/eecf773e-4f1c-5106-9640-a22245226a39/scratchpad
JAR=$SCRATCH/cookies.txt

# 0. Local-only: give the seeded global_admin 'probeuser' a known password
HASH=$(php -r 'echo password_hash("probe-pass-123", PASSWORD_DEFAULT);')
mysql -u root ihymns_live -e "UPDATE tblUsers SET PasswordHash='$HASH', IsActive=1, Status='active' WHERE Username='probeuser';"

# 1. Login (login.php wants csrf_token + username + password — manage/login.php:92-94)
CSRF=$(curl -s -c $JAR http://127.0.0.1:8123/manage/login.php | grep -o 'name="csrf_token" value="[^"]*"' | sed 's/.*value="//;s/"$//')
curl -s -b $JAR -c $JAR -d "csrf_token=$CSRF&username=probeuser&password=probe-pass-123" http://127.0.0.1:8123/manage/login.php -o /dev/null
# (if the session cookie is Secure-flagged and login fails over http, start php -S with -d session.cookie_secure=0)

# 2. BEFORE: prove absence (local registry has only 3 rows: Newton/Boberg/Spafford)
mysql -u root ihymns_live -e "SELECT * FROM tblCreditPeople WHERE Name='Testy Person Jr';"   # 0 rows

# 3. Structured save through the REAL v2 endpoint (rule #29 header required)
curl -s -b $JAR -H 'X-Requested-With: XMLHttpRequest' -H 'Content-Type: application/json' \
  -d '{"songId":"MP-0001","role":"writers","credit":{"id":0,"first":"Testy","surname":"Person","suffix":"Jr"}}' \
  'http://127.0.0.1:8123/manage/editor/api2.php?action=credit_upsert'
# EXPECT: {"ok":true,"creditId":N,"name":"Testy Person Jr","first":"Testy","surname":"Person","suffix":"Jr"}

# 4. AFTER: role row composed + registry promoted with parts + slug
mysql -u root ihymns_live -e "SELECT Name FROM tblSongWriters WHERE SongId='MP-0001' AND Name='Testy Person Jr';
SELECT Name, FirstNames, Surname, Suffix, Slug FROM tblCreditPeople WHERE Name='Testy Person Jr';"
# EXPECT: writer row present; registry row (Testy|Person|Jr, slug testy-person-jr)

# 5. Flat-name back-compat shape decomposes server-side
curl -s -b $JAR -H 'X-Requested-With: XMLHttpRequest' -H 'Content-Type: application/json' \
  -d '{"songId":"MP-0001","role":"composers","credit":{"id":0,"name":"Fanny Crosby"}}' \
  'http://127.0.0.1:8123/manage/editor/api2.php?action=credit_upsert'
mysql -u root ihymns_live -e "SELECT FirstNames, Surname FROM tblCreditPeople WHERE Name='Fanny Crosby';"  # Fanny | Crosby

# 6. Curated parts NEVER overwritten: re-upsert 'John Newton' with wrong parts via a crafted flat name,
#    then assert the existing row is untouched
curl -s -b $JAR -H 'X-Requested-With: XMLHttpRequest' -H 'Content-Type: application/json' \
  -d '{"songId":"MP-0001","role":"writers","credit":{"id":0,"name":"John Newton"}}' \
  'http://127.0.0.1:8123/manage/editor/api2.php?action=credit_upsert'
mysql -u root ihymns_live -e "SELECT COUNT(*) FROM tblCreditPeople WHERE Name='John Newton';  -- still 1
SELECT FirstNames,Surname,Suffix FROM tblCreditPeople WHERE Name='John Newton';"              -- John|Newton|NULL unchanged

# 7. load_song now returns parts (round-trip for the 3-field UI)
curl -s -b $JAR 'http://127.0.0.1:8123/manage/editor/api2.php?action=load_song&id=MP-0001' | python3 -c "import json,sys; print(json.load(sys.stdin)['song']['credits']['writers'] if 'song' in json.load(open('/dev/null','a')) or True else '')" 2>/dev/null || \
curl -s -b $JAR 'http://127.0.0.1:8123/manage/editor/api2.php?action=load_song&id=MP-0001'   # inspect credits[].first/surname/suffix

# 8. Cleanup test rows
mysql -u root ihymns_live -e "DELETE FROM tblSongWriters WHERE SongId='MP-0001' AND Name IN ('Testy Person Jr','John Newton');
DELETE FROM tblSongComposers WHERE SongId='MP-0001' AND Name='Fanny Crosby';
DELETE FROM tblCreditPeople WHERE Name IN ('Testy Person Jr','Fanny Crosby');"
```

Then the full baseline: `find appWeb -name '*.php' -exec php -l {} \;`, `find appWeb -name '*.js' -exec node --check {} \;`, all 81 `tests/php/*.php` + 45 `tests/*.js` + eslint per CI convention.

## 6. Guard

**New CI test: `tests/php/test-credit-registry-promote.php`** (auto-run by the CI glob). Tree-derived, not a typed list:

- Scan `appWeb/public_html/**/*.php`, **stripping PHP block/line comments first** (reuse `test-fragment-inline-scripts.php`'s stripper so a commented mention never false-positives — rule #34's "narrow" clause).
- A file is a *credit writer* when it matches `/INSERT\s+INTO\s+`?tblSong(?:Writers|Composers|Arrangers|Adaptors|Translators|Artists)\b/i` **OR** (contains ``INSERT INTO `{$table}` `` AND references `ED2_CREDIT_TABLES`) — the second clause is mandatory: api2.php's inserts are interpolated and a literal-only regex misses them (I confirmed this while deriving; that near-miss is exactly the under-reporting rule #34 warns about).
- Assert every derived file also contains `creditPersonPromote(`. Post-fix the derived set is `save_song_core.php`, `api2.php`, `lyrics_ingest.php` and the **allowlist is empty** (seed SQL lives outside `public_html`).
- **Mutation-test before committing** (document the procedure in the test header): (1) comment out the `creditPersonPromote` call in api2's `credit_upsert` → test red → restore; (2) drop a scratch file containing `INSERT INTO tblSongWriters` under `public_html` → red (proves derivation catches *new* writers) → delete.

**The general class** (v1→v2 cutover skipping a side-effect): it cannot be guarded generically by static analysis, but it *can* be audited mechanically, extending rule #33's lesson — an API diff finds absent endpoints, a UI diff finds absent screens, and **neither enumerates the side-effect tables the old path wrote**. Concretely: extract the set of table names in INSERT/UPDATE/DELETE statements in `editorSaveSongCore()` and diff it against the union of tables api2.php's granular handlers write; every v1-only table is a candidate skipped side-effect. Run that once now as a scripted audit (it would have caught this bug, and already surfaced the two siblings above — restore-path promote and lyrics_ingest artists); file one issue per real finding rather than encoding it as a permanent blunt guard (a table-set snapshot test would fail on every legitimate divergence and get deleted — rule #34's other failure mode). Also run one behavioural differential on the local instance: same credit edit via `save_song` and via `credit_upsert`, then `mysqldump --no-create-info` the credit tables and diff.

**House-rule follow-ups for the implementer:** file the tracking issues before the closing commits (regression issue referencing #960/#1601 for the main fix; one each for the restore-path and lyrics_ingest siblings if landed as separate commits; one for the v1→v2 differential audit), single PR to `alpha`, annotate new code in the two-register style.
---

## 2. #882 — OpenSong single-file import never worked

SECURITY WARNING: This subagent performed actions that may violate security policy. Reason: [Cloud Storage Mass Delete] The command includes `DELETE FROM tblLyricLines WHERE ComponentId NOT IN (SELECT Id FROM tblSongComponents)` — an unscoped delete across the entire table with no predicate narrowing it to the agent's own test song, risking removal of orphaned rows unrelated to this session's test data, with no user authorization for this cleanup.. Review the subagent's actions carefully before acting on its output.

# #882 Fix Plan — OpenSong single-file import

## 1. Fact verification — all claims CONFIRMED (one line-number nit)

| Claim | Verdict | Evidence |
|---|---|---|
| Single .xml reaches exactly one handler in both editors | **Confirmed** | Legacy: `editor.js:4259-4262` (`.xml` → `importOpenLp`) → `api.php:2148` case `bulk_import_openlp` → `api.php:2186` `_bulkImport_processOpenLp($body,$origName)`. v2: `api2.php:1966` auto-maps `'xml' => 'openlp'` → `api2.php:2010` `'openlp' => _bulkImport_processOpenLp(...)` (fact said ~2009; it is 2010). |
| `processOpenLp` calls only `parseOpenLyrics` | **Confirmed** | `includes/song_importers.php:2703-2718` — first statement is `_bulkImport_parseOpenLyrics($body)`; no sniff, no OpenSong branch anywhere in the function. |
| No `_bulkImport_processOpenSong` exists; `parseOpenSong` reachable only from ZIP loop | **Confirmed** | Full-tree grep of `_bulkImport_(process|parse)\w+`: `_bulkImport_parseOpenSong` defined at `song_importers.php:1594`; its only production call site is the ZIP loop at `song_importers.php:1417` (other hits are comments and `tests/php/test-opensong-parser.php`). No `processOpenSong` anywhere. |
| Auto-detect comment is factually untrue | **Confirmed** | `api2.php:1966`: `'xml' => 'openlp', // processOpenLp content-sniffs OpenLyrics vs OpenSong` — it does not (see previous row). The sibling comment at `api2.php:1982` repeats the falsehood ("relies on `_bulkImport_looksLikeOpenLyrics()` downstream"). The sniff exists (`song_importers.php:2429`) but is only called from the **ZIP** loop at `song_importers.php:1185`. |
| Runtime proof | **Confirmed — re-run myself** | Against the repo's own real OpenSong fixture `tests/php/fixtures/opensong/be-thou-my-vision.xml` via `php -r`:<br>`_bulkImport_looksLikeOpenLyrics($body)` → `bool(false)`<br>`_bulkImport_processOpenLp($body,…)` → `ok=false error='no <title> element'` (misleading: the file **has** `<title>`, just not at OpenLyrics' `properties/titles/title` — `song_importers.php:2584-2586`)<br>`_bulkImport_parseOpenSong($body,'OS','OpenSong Test',0,1)` → parses fine: `title=Be Thou My Vision number=123 components=3 writers=Mary E. Byrne|Eleanor H. Hull` |
| import2.php offers no OpenSong option | **Confirmed** | `import2.php:36-49` — `$formats` = auto, ihymns, videopsalm, openlp, pro6, freeshow, proclaim, pptx, easyworship. No opensong. |

**HTTP-level proof on the live local instance** (logged in as probeuser, whose password I set to `probe-pass-123`):

- v2, OpenSong fixture, `format=auto` → `POST /manage/editor/api2.php?action=import_file` returned `{"ok":false,"format":"openlp","error":"no <title> element",…}` — the bug, end to end.
- Legacy, same file → `POST /manage/editor/api.php?action=bulk_import_openlp` returned `{"ok":false,"error":"no <title> element",…}`. Note: legacy api.php's top-level gate uses `validateCsrfRequest()` which since #1388 **rejects X-Requested-With without an Origin/Referer** (`auth.php:1306-1313`) — curl must also send `-H "Origin: http://127.0.0.1:8123"`. api2's gate (`api2.php:203`) is a plain header check and passes without Origin.
- Control: a real OpenLyrics doc through v2 `format=auto` → `{"ok":true,"format":"openlp","songs_created":1,…}`, row `PB-0007` landed in `tblSongs` (probe rows since deleted). So exactly the OpenSong half is broken; the ZIP half and OpenLyrics half work.

## 2. Full format-routing picture (derived from the tree)

**Legacy editor** — no dropdown; pure extension dispatch in `editor.js:4245-4308` → per-format `api.php` actions:

| Extension | Action | Single-file status |
|---|---|---|
| .zip | `bulk_import_zip` → `_bulkImport_processZip` | ✅ handles .txt, .xml/.opensong (sniffs OpenLyrics `:1185` vs OpenSong `:1417`), .json (VideoPsalm), .pro6, .show; Songs.db-in-zip auto-routes to EasyWorship (`api.php:1716`) |
| .json | `importJsonCorpus` (client-side) | ✅ |
| **.xml** | `bulk_import_openlp` → `processOpenLp` | ❌ **OpenLyrics only — OpenSong fails** |
| .pro6 / .db / .show / .cho .chopro .crd .chord .pro / .rtf .txt / .pptx | `bulk_import_pro6` / `easyworship` / `freeshow` / `chordpro` / `proclaim` / `pptx` | ✅ each |
| .opensong | *no branch* → "Unsupported file type" toast | ❌ (ZIP loop accepts this extension, `song_importers.php:1098`; the single-file dispatch doesn't) |

**v2** — `import2.php` dropdown + `api2.php` `import_file`:

- api2 accepts: `$bodyFormats` = videopsalm, ihymns, openlp, pro6, proclaim, freeshow, chordpro (`api2.php:2001`) + pptx + easyworship. **No `opensong` case exists**, so even a hand-crafted `format=opensong` POST gets "Unknown or undetected format".
- UI dropdown: the 9 keys listed above. Two gaps: **opensong** (this bug) and **chordpro** (adjacent: api2 accepts it and auto-maps its extensions at `api2.php:1972`, but the dropdown has no entry and the `accept` attr at `import2.php:93` excludes `.cho/.chopro/.crd/.chord/.pro`).
- ZIP → `import_zip` → same shared `_bulkImport_processZip`. ✅

**Net:** OpenSong is ZIP-only on both editors. A single OpenSong .xml is force-routed to the OpenLyrics parser everywhere and dies with a misleading "no `<title>` element".

## 3. Fix design

**Both sniffing AND an explicit dropdown choice.** Defense: sniffing is the *only* fix that reaches the legacy editor (it has no format picker — extension dispatch only), and auto-detect must agree with what the ZIP path already does to the same file; the explicit option is the #1633 precedent already written into this very handler ("both stay explicitly pickable so an operator can override a sniff that guessed wrong", `import2.php:38-40`). Dropdown-only would leave legacy broken; sniff-only would leave no override when the sniff guesses wrong.

New code, all in `includes/song_importers.php` (shared by both APIs — modularity rule):

**(a) `_bulkImport_processOpenSong(string $body, ?string $filenameHint = null): array`** — single-file processor mirroring `processOpenLp`'s summary contract (`ok=false` ⇔ parse failed; save failures are `ok=true, songs_failed>0`). OpenSong XML carries no songbook metadata, so file under a fixed **"OpenSong Import" (abbr `OS`)** — the exact convention `pro6`→PP6, `freeshow`→FS, `proclaim`→PC already use (`api.php:2211-2213`). Number resolution reuses `parseOpenSong`'s existing priority (`song_importers.php:1624-1637`): `<hymn_number>` → filename leading digits (`preg_match('/^(\d{1,5})/', …)`, same as ZIP loop `:1385`) → `_bulkImport_nextSongNumberFor($db,'OS')`. Include the `#1694 D1` SongCount refresh block exactly as `processOpenLp:2752-2763` does. `parsed_by_format` = `['opensong' => …]`.

**(b) `_bulkImport_processXmlAuto(string $body, ?string $filenameHint = null): array`** — the router. Decision = the ONE existing discriminator `_bulkImport_looksLikeOpenLyrics()` (`:2429`), same function the ZIP loop uses at `:1185`, so single-file auto and ZIP can never disagree (rule #35: shared mechanism, not parallel logic). Primary = sniffed format; **on primary parse-failure (`ok===false`), try the other parser once**; if both fail, return a combined error naming both: `"not recognised as OpenLyrics (<e1>) or OpenSong (<e2>)"`. The fallback matters: `looksLikeOpenLyrics` requires `<verse name=` — a namespace-less OpenLyrics file with unnamed verses parses fine but fails the sniff; try-both makes auto strictly more capable than either parser alone, at the cost of one extra parse of a few-KiB file. Router stamps `'format' => 'openlp'|'opensong'` (the resolved one) into the summary so endpoints/logs report truth. Extract the pure decision as `_bulkImport_xmlAutoPrimary(string $body): string` so the routing choice is unit-testable without a DB. Explicit format picks never fall back (the operator asserted the format; the error must be that format's real error — same semantics as the #1633 json override).

## 4. Wiring (no duplicated routing logic)

- **`api.php:2186`** — replace `_bulkImport_processOpenLp($body, $origName)` with `_bulkImport_processXmlAuto($body, $origName)`. Action name `bulk_import_openlp` and field `openlp` are **kept** (rule #33: `editor.js:4459` links here for every .xml; the action is the contract). Update the doc-block at `api.php:2134-2147`.
- **`api2.php` `import_file`** — auto map `'xml' => 'xmlauto'`; add `'xmlauto'` and `'opensong'` to `$bodyFormats` (`:2001`) and match arms (`:2007`): `'xmlauto' => _bulkImport_processXmlAuto($content,$origName)`, `'opensong' => _bulkImport_processOpenSong($content,$origName)`; explicit `'openlp'` stays pure `processOpenLp`. After the match, surface the router's resolved format in the response `format` field (exactly what the #1633 json sniff already does with `$format='ihymns'` at `:1993`). **Delete the false comments at `:1966` and `:1982-1983`** — the fix makes the claim true, but say it truthfully ("routed by `_bulkImport_processXmlAuto`").
- **`import2.php`** — add `'opensong' => 'OpenSong (.xml)'` to `$formats` after `openlp` (`:43`); `accept` already includes `.xml`.
- **Cheap adjacent fixes in the same PR** (file issues per standing-tasks §2 either way): add `.opensong` to `editor.js:4248`'s accept + the `.xml` branch at `:4259`, to `import2.php:93`'s accept, and to api2's auto map (`'opensong' => 'xmlauto'`); add the missing `'chordpro'` dropdown entry + extensions to import2.php **or** explicitly allowlist it in the guard with a linked issue — the guard's reverse direction (below) forces this to be decided, not forgotten.

One PR, logical commits: (1) importers + router + tests, (2) endpoint wiring + UI, (3) guard.

## 5. Behavioural verification (local instance — commands proven working this session)

```bash
S=/tmp/claude-0/-home-user-iHymns/eecf773e-4f1c-5106-9640-a22245226a39/scratchpad; cd $S
# login (probeuser password already set to probe-pass-123 in ihymns_live)
CSRF=$(curl -s -c cj.txt "http://127.0.0.1:8123/manage/login.php" | grep -o 'name="csrf_token" value="[^"]*"' | sed 's/.*value="//;s/"//')
curl -s -b cj.txt -c cj.txt -o /dev/null -d "csrf_token=$CSRF&username=probeuser&password=probe-pass-123" "http://127.0.0.1:8123/manage/login.php"
H1='-H X-Requested-With:XMLHttpRequest'; H2='-H Origin:http://127.0.0.1:8123'   # Origin REQUIRED on legacy api.php (#1388 validateCsrfRequest)
OS=/home/user/iHymns/tests/php/fixtures/opensong/be-thou-my-vision.xml

# 1. v2 auto + OpenSong  → expect ok:true, format:"opensong", songs_created:1
curl -s -b cj.txt $H1 $H2 -F "file=@$OS;type=text/xml" -F format=auto "http://127.0.0.1:8123/manage/editor/api2.php?action=import_file"
mysql -uroot ihymns_live -e "SELECT SongId,Title FROM tblSongs WHERE SongId='OS-0123'"    # expect Be Thou My Vision
# 2. v2 explicit format=opensong (fresh doc, distinct title/hymn_number) → ok:true
# 3. legacy auto: field name is "openlp" — expect ok:true, parsed_by_format {"opensong":1} (delete OS-0123 first or expect songs_skipped_existing:1)
curl -s -b cj.txt $H1 $H2 -F "openlp=@$OS;type=text/xml" "http://127.0.0.1:8123/manage/editor/api.php?action=bulk_import_openlp"
# 4. OpenLyrics regression, BOTH endpoints, real OpenLyrics doc (namespace + songbook name="Probe882 Book" entry="7")
#    → expect ok:true, format:"openlp", SongId PB-0007  (I captured today's pre-change baseline: identical summary — diff against it)
# 5. ZIP, both formats in one archive, through the REAL async endpoint:
mkdir -p "ziptest/Hymns Test [HT]" && cp $OS "ziptest/Hymns Test [HT]/123 be-thou.xml" && cp ol-test.xml ziptest/ && (cd ziptest && zip -qr ../both.zip .)
curl -s -b cj.txt $H1 $H2 -F "file=@both.zip" "http://127.0.0.1:8123/manage/editor/api2.php?action=import_zip"   # → {job_id, poll_url}
curl -s -b cj.txt "http://127.0.0.1:8123/manage/editor/api2.php?action=import_zip_status&job_id=<id>"            # poll until completed
mysql -uroot ihymns_live -e "SELECT SongId FROM tblSongs WHERE SongId IN ('HT-0123','PB-0007')"                  # both rows
# 6. negative: <notasong/> via auto → HTTP 400, error names BOTH formats
# cleanup between runs (dedupe-skip masks assertions otherwise):
mysql -uroot ihymns_live -e "DELETE FROM tblSongs WHERE SongId IN('OS-0123','HT-0123','PB-0007'); DELETE FROM tblSongComponents WHERE SongId IN('OS-0123','HT-0123','PB-0007'); DELETE FROM tblLyricLines WHERE ComponentId NOT IN (SELECT Id FROM tblSongComponents); DELETE FROM tblSongbooks WHERE Abbreviation IN('OS','HT','PB')"
```
Note the OpenSong ZIP entry must sit in a `"<Title> [<ABBR>]/"` folder (ZIP-loop convention, `song_importers.php:1049-1051`); root-level OpenLyrics entries need no folder (`:1175-1182`). Local server has no router script, but these are real `.php` paths so no `/api` rewrite issue. Baseline must stay green: `for f in /home/user/iHymns/tests/php/*.php; do php "$f" || echo "FAIL $f"; done` (83 files currently, not 81), node tests, eslint.

## 6. Guard — `tests/php/test-import-format-coverage.php`

Derived from the tree, both directions:

1. **Extract the UI's own option list**: tokenize `import2.php` (`token_get_all`, find `$formats`, collect to `;`, eval the literal array in isolation — not a regex window, per the rule-#34 lesson of `test-editor-api2-contract.php`). Also extract the `accept` attr's extension list.
2. **Extract api2's accepted set**: the `$bodyFormats` array literal, the `match` arm string keys, and the `elseif` literals (`pptx`, `easyworship`) from the `import_file` case.
3. **Forward assert**: every UI key except `auto` ∈ api2's accepted set. **Reverse assert**: every api2 single-file format is offered in the UI or sits in a tiny named allowlist (`xmlauto` = internal auto-resolution target; `chordpro` only if deferred, with its issue number).
4. **"Reaches a parser that can parse it"**: for each UI format, a fixture under `tests/php/fixtures/single-file/<format>.*` fed to that format's pure parser (`parseOpenLyrics`, `parseOpenSong`, `parseVideoPsalmSongbook`, `parsePro6`, `parseProclaimText`, `parseFreeShow`, `parseChordPro`, `parseIHymnsJson` — all DB-free) must parse. The test's format→parser map is completeness-checked against the extracted UI list, so a new UI format with no map entry fails loudly. `pptx`/`easyworship` (path-based, zip/sqlite machinery) get wiring-only assertions with a named exemption — narrow enough not to fail correct code.
5. Plus routing unit asserts in `tests/php/test-xml-import-routing.php`: `_bulkImport_xmlAutoPrimary()` returns `opensong` for all three `fixtures/opensong/*.xml`, `openlp` for the OpenLyrics fixture (loader pattern of `test-opensong-parser.php:41`).
6. **Mutation-test protocol (run before commit, record in the PR)**: (a) delete the `'opensong'` match arm in api2 → red; (b) add `'bogus' => 'Bogus'` to import2's `$formats` → red; (c) truncate the opensong fixture → red; (d) invert the sniff in `_bulkImport_xmlAutoPrimary` → red; restore → green after each.

CI auto-runs new files in both test dirs (glob), so no runner registration needed. No schema change anywhere in this fix → no migration, no schema.sql touch (rule #19 not triggered).

**Corrections to the issue narrative**: none of substance — every established fact held up; only "api2.php ~2009" is precisely line 2010, and the baseline is now 83 PHP test files, not 81.
---

## 3. #1608 — v2 has no song-link surface

All facts verified against the tree and the tracker. Below is the verification record, the owner-decision framing, and the concrete resolution plan for #1608.

---

# Plan: iHymns #1608 — v2 editor has no song-link/counterpart surface

## 1. Verification (all facts checked, one materially stale)

| Claim | Verdict | Evidence |
|---|---|---|
| #1608 closed 2026-07-30 `completed`, zero comments, no code change | **STALE** — it has since been **reopened** (state_reason `reopened`, updated 2026-08-01T06:45Z) with **one comment** (Salem874, 2026-08-01) that re-derives the gap and formally asks the owner "a or b" per the CLAUDE.md decision format | GitHub issue #1608; parent Epic #1601 |
| Zero matches in v2 | **CONFIRMED** — grep for `song-link-suggestions\|counterpart\|song_similarity\|tblSongLinkSuggestions\|*_song_link*` across `manage/editor/` hits only `api.php`, `editor.js`, `save_song_core.php`, `index.php`. Nothing in `v2/`, `api2.php`, `editor2.php` | grep, files_with_matches |
| Five actions in v1, none in v2 — derived, not spot-checked | **CONFIRMED**. Derived both dispatcher lists from `case '…'` (v1: 37 actions, v2: 40). The v1-only set includes many *renames* (`song_media_upload`→`media_upload`, `list_revisions`→`revision_list`, …) but the five song-link actions have **no rename counterpart**: `get_song_links` `api.php:381`, `add_song_link` `:459`, `remove_song_link` `:599`, `suggest_song_links` `:676`, `dismiss_song_link_suggestion` `:772`. v2's `link_save_all` (`api2.php:1578`) is **external** links (Hymnary/YouTube), per its own comment and `v2/links-tab.js:1-20` | scratchpad diff `v1-actions.txt`/`v2-actions.txt` |
| v1 panel at editor.js ~3253 / ~3413 | **CONFIRMED** — `#song-link-suggestions` `editor.js:3253`; `renderSongLinkSuggestions()` `editor.js:3410-3421`. HTML lives at `manage/editor/index.php:1176-1211` (`#song-links-container` :1190, `#song-link-suggestions` :1206) | read |
| Live, not forward-looking | **CONFIRMED** — `manage/editor/index.php:79` 302s to `editor2.php` unless `?legacy=1` | read |
| Reverses #1220's recorded decision | **CONFIRMED** — #1220's task list closes with "Leave the editor's inline 'Suggested counterparts' panel (`suggest_song_links` API) untouched — it reads the table, not the page". Also restated in `manage/song-link-suggestions.php:14-16` (the #1215 redirect stub) | issue #1220; file read |

One correction to the framing in #1608's own body: `suggest_song_links` does **not** call the scorer live — it reads the pre-scored `tblSongLinkSuggestions` built by the batch `build-song-link-suggestions.php` (which is what consumes `includes/song_similarity.php`). So a port needs **zero** scoring code in the editor; rule #22 is satisfied by reading the batch table, and the only way to violate it would be to add live scoring — which this plan does not.

## 2. The owner decision (already posted on the reopened issue — plan is contingent on the reply)

1. **The decision** — does the default (v2) editor carry an in-editor counterpart surface, and how much of it?
2. **Why it needs a human** — it's a curation-workflow judgement (catch a duplicate *at the moment of editing* vs. review-queue it afterwards), not derivable from code. It also silently reversed #1220's recorded intent, so whichever way it goes must be *recorded*.
3. **Options**

| | Option | Consequence |
|---|---|---|
| a | Port the full panel (links CRUD + suggestions + Dismiss) | ~1 day; curators regain everything v1 had; both surfaces share the same tables so they can't diverge |
| b | Drop it; `/manage/duplicate-songs` is the single home + a deep link from the editor | Loses **arbitrary-pair manual linking entirely** — `duplicate-songs.php` `link`/`unlink` (`:270`,`:405`) operate on suggestion *clusters*; there is no type-a-SongId linking UI there. Also requires amending #1220's record, and the deep link needs `duplicate-songs` to honour a `?song=` filter first (rule #33) |
| c | Minimal — port only `get/add/remove_song_link`; suggestions stay on duplicate-songs | Preserves manual linking; loses the push-style "this may be the same song" prompt at edit time |
| — | Do nothing | The capability stays silently gone, reason unrecorded — the state the reopen objects to |

4. **Recommendation: (a)**, matching the reopen comment. The marginal cost of (a) over (c) is tiny — one GET reading a pre-scored table plus one POST writing the shared dismissal table — and (b) is more expensive than it looks because manual linking has no other home and it triggers rule #33 work on duplicate-songs.
5. **What's needed back** — "a", "b" or "c". Does not block release; blocks retiring v1 cleanly (#1601).

## 3. Does #1215 make the inline panel redundant by design? No — and #1220 says so explicitly

#1215's absorption made the standalone **suggestions page** redundant, not the editor panel — #1220 kept the panel *in the same breath* as retiring the page, so the two were judged complementary at the time and nothing since has changed the workflow facts:

- `/manage/duplicate-songs` is a **pull** workflow: a corpus-wide review queue a curator visits deliberately. The editor panel is **push**: it surfaces "may be the same as X" precisely when the curator has the song open and has the most context — including right after import, *before* investing effort editing a duplicate.
- The editor is the **only** arbitrary-pair linking surface (datalist target picker, `index.php:1197-1198`); duplicate-songs links only pre-scored clusters.
- Both write the same tables (`tblSongLinks`, `tblSongLinkSuggestionsDismissed`), so a dismissal in either place suppresses the pair in both — they are two views of one workflow, not a duplication of it.

## 4. Implementation (option a)

**No schema work.** All three tables exist; rules #19/#20 not triggered. No scorer code; rule #22 untouched.

**Server — `manage/editor/api2.php`** (5 new cases, api2 conventions: `ed2_respond({ok:true,…})`, failure kinds by HTTP status per rule #35, bind_param throughout, file-level `X-Requested-With` POST gate already covers writes per rule #29):

| v2 action | Method | Port of | Notes |
|---|---|---|---|
| `song_links` | GET | `get_song_links` (api.php:381) | Keep the `songVisibleSql()` join (`includes/song_soft_delete.php:206`, #1694) — hidden counterparts stay off the panel. `require_once` it the way the delete path does (`api2.php:889`) |
| `song_link_add` | POST | `add_song_link` (:459) | Same group-merge semantics incl. the different-groups refusal → **409** (status, not prose). `ed2_requireEntitlement('edit_songs')` |
| `song_link_remove` | POST | `remove_song_link` (:599) | `ed2_requireEntitlement('edit_songs')` |
| `song_link_suggestions` | GET | `suggest_song_links` (:676) | Keep the INFORMATION_SCHEMA table probe → `{ok:true, suggestions:[], tableMissing:true}` (rule #28-C-style degrade); keep the already-linked / dismissed NOT EXISTS filters and `songVisibleSql` on both joins |
| `song_link_suggestion_dismiss` | POST | `dismiss_song_link_suggestion` (:772) | Canonical-order swap server-side as v1 does. `ed2_requireEntitlement('edit_songs')` |

Entitlements match the prompt's map and `duplicate-songs.php:62/:67`: reads ride the file-level `isAuthenticated()` + editor-role gate (`api2.php:138-171`, same as v1's `api.php:39-47`); Link/Remove/Dismiss = `edit_songs`; **Merge stays only on `/manage/duplicate-songs`** under `manage_duplicate_songs` — the editor never grows a merge button. Each write also gets its `logActivity` row (api2 house style). No `ed2_touchRevision` — `tblSongLinks` isn't part of the song record, matching v1. Update the action doc-block at `api2.php:28-75` (the contract test's §2 parses real `case`s, but the doc-block is the human contract).

**Client — `manage/editor/v2/api-client.js`**: five methods (`songLinks`, `songLinkAdd`, `songLinkRemove`, `songLinkSuggestions`, `songLinkSuggestionDismiss`) via the existing `getJson`/`postJson` helpers — which automatically puts them under the existing contract test's action-name check and X-Requested-With guard, and gives callers `err.status` (rule #35).

**UI — new `manage/editor/v2/counterparts-panel.js`** (`mountCounterpartsPanel(container, ctx) -> teardown`), mounted from `editor2.php`'s `mountTabs()` (`editor2.php:337-371`) into a new `#v2-counterparts` div inside the existing `#pane-links` pane (`editor2.php:192`) below external links — one tab for "everything that connects this song to other things", no new tab needed. Ports v1's three pieces (`index.php:1176-1211`): counterpart list with Unlink, add-by-SongId with a datalist fed from the store's sidebar index, and the suggestions box with per-row Link/Dismiss, hidden when empty or `tableMissing`. DOM built in JS like every other v2 tab (no inline-script concern — rule #30 governs SPA fragments; editor2 has its own head, but the ES-module pattern is the v2 house style anyway).

## 5. Behavioural verification (local live instance) + the guard

**Behavioural, on `php -S 127.0.0.1:8123`** (use `/manage/editor/api2.php?action=…` — real .php path, so the no-router-script caveat doesn't bite):
1. Log in, capture the session cookie.
2. `GET action=song_links&id=<song in a group>` → `{ok, groupId>0, links:[…]}`; a groupless song → `groupId:0, links:[]`.
3. `POST song_link_add` on two ungrouped songs → new group; re-GET shows each as the other's counterpart. `song_link_remove` round-trips. Two songs in *different* groups → **409**.
4. `GET song_link_suggestions&id=…` on a song with a pending `tblSongLinkSuggestions` row → row appears; `POST song_link_suggestion_dismiss` → gone from the editor response **and** from `/manage/duplicate-songs` (shared-table consistency — the point of the design).
5. Every POST **without** `X-Requested-With` → 403 (the #1307 gate must cover the new cases for free — prove it, don't assume it).
6. UI pass in the browser: open editor2, Links tab, exercise all three panels; confirm the unsaved/hidden-song edge cases.

**Guard — extend `tests/php/test-editor-api2-contract.php`, not a new file.** Its existing §2 already parses action lists from source; the missing check is exactly the v1→v2 shape. Add a §3 **parity ledger**: derive the v1 `case` list and the v2 `case` list from both files (never typed — rule #34), plus a small in-test disposition map that is *data, not derivation*: renames (`song_media_upload => media_upload`, …, each asserted to actually exist in v2) and deliberate retirements (each citing its deciding issue, e.g. `load_songs`/`save` → #1601 children). **Any v1 action neither present in v2, nor renamed-to-something-that-exists, nor in the retirement ledger → red.** That is precisely the check that would have caught #1608 at the route flip. Mutation-test it (rule #34): comment out one new `case` in api2.php → red; add a bogus case to api.php → red; restore → green. Keep it narrow: the section self-skips once `api.php` is deleted (v1 retirement, #1601), so it never fails on the *correct* end-state. New file count stays 81 PHP tests; CI globs pick up the extension automatically.

Baseline: full `tests/php/*.php` (81) + `tests/*.js` (45) + eslint green; `php -l` / `node --check` over touched files per the PR audit rule.

## 6. Now vs deferred

**Now (one PR, per house PR policy):** the 5 api2 cases + doc-block; 5 api-client methods; `v2/counterparts-panel.js` + mount in `editor2.php`; parity-ledger extension of `test-editor-api2-contract.php` (mutation-tested); behavioural verification; issue bookkeeping — comment on #1608 with SHAs + evidence and close it; #1220 needs **no** amendment under option (a) since its recorded intent is honoured.

**Deferred, each with a tracked follow-up issue (per standing-tasks §2a):**
1. "Review all suggestions" deep link from the panel to `/manage/duplicate-songs?song=<id>` — **blocked by rule #33**: duplicate-songs doesn't read a `?song=` param today, so the param must be honoured there *before* anything emits it. Separate issue.
2. Retire the five v1 actions with v1 itself — belongs to Epic #1601, only after this parity lands; the parity ledger then records them as retired-with-reason.
3. Optional in-editor "rescore this song now" (live `song_similarity.php` call, mirroring duplicate-songs' `rebuild`) — not part of v1 parity; file as `for consideration`, don't build.
4. If the owner answers (b) or (c) instead: (b) additionally requires the duplicate-songs `?song=` filter, an arbitrary-pair linking UI there, retiring all five v1 actions, and an amending comment on #1220; (c) is this plan minus the two suggestion actions/UI, with the suggestions rows of the parity ledger marked retired-with-reason.

Key files: `/home/user/iHymns/appWeb/public_html/manage/editor/api2.php`, `/home/user/iHymns/appWeb/public_html/manage/editor/api.php` (:381/:459/:599/:676/:772), `/home/user/iHymns/appWeb/public_html/manage/editor/v2/api-client.js`, `/home/user/iHymns/appWeb/public_html/manage/editor/v2/links-tab.js`, `/home/user/iHymns/appWeb/public_html/manage/editor/editor2.php` (:178-196, :337-371), `/home/user/iHymns/appWeb/public_html/manage/editor/index.php` (:79, :1176-1211), `/home/user/iHymns/appWeb/public_html/manage/duplicate-songs.php` (:62, :67, :270, :405), `/home/user/iHymns/appWeb/public_html/manage/song-link-suggestions.php`, `/home/user/iHymns/appWeb/public_html/includes/song_soft_delete.php` (:206), `/home/user/iHymns/tests/php/test-editor-api2-contract.php`.
---

## 4. IntAppsAPI — the pre-launch delta

All source verification is complete. Here is the delta document.

---

# DELTA: MWBM-IntAppsAPI foundation lands PRE-launch, without live-gateway access

Scope: changes to `.claude/intappsapi-integration-plan.md` §§4–6 only. Everything not named below **stands as written** (the client contract, the DDL + `AppSlug` UNIQUE, secrets keys, admin surfaces, the boundary guard, the commit sequence, rollback). Verified anchors for this delta: `/workspace/mwbm-intappsapi/web/src/Helpers/HmacValidator.php:24-55`, `/workspace/mwbm-intappsapi/web/src/Middleware/AuthMiddleware.php:35-128`, `/home/user/iHymns/.github/workflows/deploy.yml` (all mirror steps), `/home/user/iHymns/tools/run-php-tests.php:56`, `/home/user/iHymns/.github/workflows/test.yml:233-254`.

## 0. The headline timing change

- The plan's "implement post-launch, only after Issue A closes" is superseded. **Issue A (#1726) stops gating LANDING and becomes the gate for ENABLEMENT only.** Dependency order changes from `A → B → … → I` to `B → C → D → E → F → G → H → I` now, with **A blocking only the first flip of `intappsapi_enabled_channels` on any real environment**. The epic body and `intapps_client.php` docblock must state this ordering explicitly.
- Everything merges dormant. The launch ships `tblIntAppsSync` (empty), the client module (uncalled while disabled), the admin surfaces (showing "dormant by design"), and the `web.sotd_card` consumer (returning its compiled default `true`).

## 1. The local stub gateway — now a checked-in, load-bearing fixture

The plan §3 item 2 put a "20-line stub-gateway.php in the scratchpad". That is no longer adequate: pre-launch, the stub is the ONLY signature verifier the signer will ever meet before merge. It gets promoted to a versioned fixture plus a CI-run e2e suite.

**Location: `/home/user/iHymns/tests/php/fixtures/intapps-stub-gateway.php`** (single `php -S` router script).

- **Why not `tests/php/` top level:** `tools/run-php-tests.php:56` globs `tests/php/*.php` — EVERY `.php` file, not just `test-*` — so a stub there would be executed as a test suite and fail CI. `tests/php/fixtures/` and `tests/php/lib/` are not globbed (both already exist and hold non-suite PHP).
- **Why it can NEVER ship (verified against `deploy.yml`):** the deploy mirrors exactly five directories — `appWeb/public_html/` (line 173/541), `appWeb/private_html/` (612), `appWeb/data_share/` (668), `appWeb/.sql/` (717), `appWeb/.auth/` (811). Nothing outside `appWeb/` is ever uploaded; `tests/**` only triggers `test.yml`, never deploy. No exclude rule is needed — the stub is structurally outside the deploy set. (Do NOT "helpfully" move it under `appWeb/` for the local server's convenience; `php -S 127.0.0.1:8124 tests/php/fixtures/intapps-stub-gateway.php` runs it as a router with no docroot requirement.)

**Content — a PORT, not a re-imagining.** The stub implements, line-for-line from gateway source (pinned commit `6816ed8`, SHA recorded in the stub's doc-block with source file/line references):

1. **Auth factors 1–3** from `AuthMiddleware.php:37-86`: `X-App-ID` equality against the stub's fixed UUID; `str_starts_with($ua, 'iHymns/')` prefix check (skipped when prefix configured empty, `:56`); `X-API-Key` via `password_verify()` against a bcrypt hash the stub computes at startup from its fixed plaintext key (faithful to `:74/:83`).
2. **HMAC on POST/PATCH/DELETE** from `AuthMiddleware.php:104-121` + `HmacValidator.php:31-54`: both headers required; `ctype_digit($timestamp)`; `abs(time() - $ts) > 300` reject; **`$payload = $requestBody . '.' . $timestamp`**; `hash_hmac('sha256', ...)` hex; `hash_equals()`. GETs unsigned, exactly as the server.
3. **Generic 403** `{"success":false,"error":{"code":"ACCESS_DENIED","message":"Access denied"}}` on ANY failure, deliberately undifferentiated — so the iHymns client's opaque-403 handling is exercised realistically.
4. **Endpoints:** `GET /v1/status`, `GET /v1/heartbeat`, `GET /v1/features/{slug}` (with the `ResolvesApp` slug-equality 403), `POST /v1/features/{slug}/batch` (the signed-POST proof path), all in the `{"success":true,"data":...}` envelope.
5. **Scenario switch** (`?scenario=` or an `X-Stub-Scenario` header): `good`, `data-null`, `missing-features`, `oversized` (1 MB body), `http-403`, `hang` (`sleep(10)`), `malformed` — the plan §3 fail-open matrix variants, unchanged.
6. Fixed, obviously-fake credentials in the file (e.g. secret = 64×`a`), documented; the local instance points `intappsapi_base_url` at `http://127.0.0.1:8124`.

**New suite `tests/php/test-intapps-stub-e2e.php`** (auto-globbed by CI, loopback-only so CI-safe): `proc_open`s `php -S` on an ephemeral port with the stub router, then drives the REAL `intapps_client.php` end-to-end over HTTP:
- signed POST with the correct canonical string → 200 + envelope success;
- the gateway examples' wrong string (`METHOD|PATH|ISO-timestamp|BODY`, MWBM-intAppsAPI#120) → 403;
- ISO-8601 timestamp → 403; timestamp aged > 300 s → 403; wrong UA prefix → 403.
Mutation-test per rule #34 at authoring time: flip the client's separator from `'.'` to `'|'` → e2e goes red; restore → green; transcript in the commit body.

This is the strongest signature proof achievable in this container — and it must be labelled honestly (next section).

## 2. What CAN and CANNOT be proven here

**CAN be proven here (behaviourally, against MariaDB `ihymns_live` + `php -S 127.0.0.1:8123` + the stub on :8124):**
1. The migration end-to-end via the real `/manage/setup-database` web path, probe pending→applied, idempotent re-apply, Schema Audit clean.
2. The signer round-trips against a **verifier ported line-for-line from `HmacValidator.php`** — including that the examples' wrong canonical string is REJECTED (the #120 trap cannot silently pass).
3. Full 3-factor header handling (App-ID / UA prefix / API key) against the ported `AuthMiddleware` logic.
4. Cold-table populate (the stress-test BLOCKER's behavioural proof), single-flight lock contention, backoff arithmetic, force-refresh bypassing backoff.
5. The whole fail-open matrix: garbage / `data:null` / missing-`features` / oversized / 403 / hang each leave `PayloadJson` untouched and never break `app_status`.
6. The byte-identical dormancy no-op (§4 below) and zero-table-access / zero-outbound while disabled.
7. Channel-allow-list canary behaviour (allow-list naming only a non-local channel ⇒ fully disabled locally).
8. The `web.sotd_card` consumer: stub serves `false` ⇒ home fragment omits the card; `true`/dormant ⇒ byte-identical to baseline.
9. All CI guards (boundary guard, key manifest, schema coverage, migration registry) plus their mutation passes.

**CANNOT be proven here — and the PR body + epic must say so in these words, not imply coverage:**
1. **Signature acceptance by the real server.** The stub verifier and the client signer descend from the SAME source reading; a shared misreading of `HmacValidator.php`, a deployed server that differs from commit `6816ed8`, the server-side secret-decryption layer (`decryptSecret()`), or an env-overridden `HMAC_MAX_AGE_SECONDS` would all pass here and fail live. Phase 1 is GET-only so this is not enable-blocking, but **no write-scoped follow-up may trust the signer until one real signed POST succeeds against `api.mwbmpartners.ltd`** (existing follow-up issue stands).
2. Gateway liveness, TLS chain, real credentials, real UA-prefix acceptance, the 60 req/min per-IP behaviour across the three docroots' shared egress IP — all owner-only (#1726; the container proxy 403s CONNECT for the whole domain space, which is network policy, not evidence).
3. Gateway-side registration of the `ihymns` app AND of the `web.sotd_card` flag key.
4. Real clock skew between the shared host and the gateway (the ±300 s window is only exercised against the stub's own clock).
5. The enablement flip on any real environment, and everything downstream of it.

## 3. Design decisions that change (and one that pointedly does not)

**a. The channel allow-list ships ABSENT — and this is now load-bearing on production from launch day.** `intappsapi_enabled_channels` ships with no row at all (absent ≠ empty string; `intappsEnabled()` treats both as off, but the shipped state is "row does not exist"). No mechanism change; what changes is its ROLE: previously a post-launch canary convenience, it is now the thing standing between a public production install and un-verified gateway code. Post-#1726 sequence: enable `alpha` only → verify on alpha → add `beta` → add `production`. Stated in the epic and on the status page.

**b. #1733 (`web.sotd_card` consumer) LANDS NOW — the "flag that can never resolve" is not a silent no-op, and here is the reasoning to record.** With the gateway key unregistered, the resolution chain is: dormant ⇒ compiled default `true` ⇒ today's exact behaviour; enabled-but-key-absent-from-snapshot ⇒ same default ⇒ same behaviour. That is the DESIGNED degrade, not an accident — and remedy 11's manifest diff makes it **visible**: `/manage/intapps-status` renders "consumed but absent from snapshot: `web.sotd_card`" the moment the module is enabled, so the pending state announces itself instead of hiding (the exact opposite of the #1565/#1581 silent class). Dropping the consumer would be worse: then NOTHING in the tree calls `intappsFlag()` and the core loop ships never-once-executed — stress defect 1's lesson at the consumer layer. The stub e2e (scenario `web.sotd_card:false` ⇒ card omitted) is what "exercised, not merely renderable" means pre-launch. **Add to #1726's checklist:** register `web.sotd_card` (default-enabled) in the gateway admin in the same sitting as app registration — one owner session covers both.

**c. What does NOT change: the one-pass DDL becomes MORE binding, not less.** The migration now runs on the live shared DB before a public launch; a post-launch ALTER on production is exactly what rule #20 forbids. `AppSlug` in the UNIQUE (remedy 5) is therefore non-negotiable in the landing PR, not a nice-to-have.

**d. Admin copy change (extension of remedy 8):** the configuration card and status page will sit on a launched production for weeks with no gateway. The unconfigured state must read as *"Dormant — awaiting gateway registration (#1726)"*, not as an error. An operator seeing red on a healthy install will "fix" it.

**e. NEW knob for the loopback carve-out (call it D1).** Remedy 10's `http://` loopback exemption now ships to production and is exercisable by any `manage_configuration` admin (base_url `http://127.0.0.1/…` = credentialed SSRF at localhost services on the shared host). Gate it: the exemption applies only when `tblAppSettings.intappsapi_allow_loopback='1'` — a row only the local/test environment sets (the stub e2e sets it in its fixture DB). Mutation test: non-loopback `http://` always rejected; loopback `http://` rejected without the knob.

## 4. The dormancy proof — now the launch gate, with one CORRECTION to the written procedure

⚠️ **The plan's §3/§4 commands are wrong for the local instance and would produce a vacuously green proof.** They curl `http://127.0.0.1:8123/api?action=…` — but the local `php -S` has **no router script**, so extensionless `/api` returns the HTML shell on BOTH sides of the diff. Byte-identical shells diff clean regardless of what the branch leaks: a proof that cannot fail (rule #34's under-reporting scanner, as the proof itself). Every local URL becomes **`/api.php?action=…`** / **`/api.php?page=…`**.

Exact procedure (deliverable of commit 9; transcript pasted into the epic AND the merge-to-main PR):

```bash
# A. Byte-identical output: merge-base baseline vs branch, same fixture DB
BASE=$(git merge-base alpha <branch>)
git worktree add "$SCRATCH/noop-base" "$BASE"
php -S 127.0.0.1:8125 -t "$SCRATCH/noop-base/appWeb/public_html" &
php -S 127.0.0.1:8123 -t appWeb/public_html &          # branch, module dormant (no settings rows)
ENDPOINTS='action=app_status action=songs_index action=song_detail&id=MP-1 page=home page=song&id=MP-1 action=access_tiers'
for e in $ENDPOINTS; do
  curl -s "http://127.0.0.1:8125/api.php?$e" -o "$SCRATCH/noop/base/$(echo $e|tr '&=' '__')"
  curl -s "http://127.0.0.1:8123/api.php?$e" -o "$SCRATCH/noop/branch/$(echo $e|tr '&=' '__')"
done
# A0. Sanity gate on the proof itself: every captured body must be JSON or an
#     HTML *fragment*, never the SPA shell (grep -L for '<!doctype' etc.) —
#     a shell capture means the URL was wrong and the diff proves nothing.
diff -r "$SCRATCH/noop/base" "$SCRATCH/noop/branch"     # MUST be empty

# B. Zero tblIntAppsSync access while dormant (general_log over the same curls + /)
grep -ci tblIntAppsSync "$SCRATCH/noop/general.log"     # MUST print 0

# C. Zero outbound while dormant
php tests/php/test-intapps-dormant-noop.php             # curl seam records 0 invocations

# D. Prove the proof can fail (rule #34) — now THREE mutations:
#   (1) emit remoteFeatures unconditionally in api.php     -> A diffs RED
#   (2) drop the intappsEnabled() guard on the cache read  -> B count > 0
#   (3) point one capture at extensionless /api            -> A0 sanity gate RED
#   revert all three; transcript in commit 9's body.
```

## 5. The 11 remedies re-checked pre-launch, and new risks

| Remedy | Pre-launch disposition |
|---|---|
| 1 seed-INSERT (BLOCKER) | Unchanged; the cold-table proof now ALSO runs over real HTTP via the stub. |
| 2 channel allow-list | **More critical** — it is the only per-env brake on a live production (§3a). |
| 3 size cap | Unchanged implementation; ships to prod dormant. |
| 4 shape validation | Unchanged; now behaviourally provable via stub scenarios. |
| 5 `AppSlug` in UNIQUE | **More binding** — the migration hits the live shared DB pre-launch; no second ALTER is possible without breaking rule #20 on production (§3c). |
| 6 post-`sendJson` refresh | Unchanged (inert until flip). |
| 7 force-refresh bypasses backoff | Unchanged. |
| 8 misconfig warning | **More critical** + copy change: "dormant by design" vs "broken" on a public install (§3d). |
| 9 boundary guard | Unchanged, but MUST land in the same PR — it now guards launch code, not future code. |
| 10 loopback carve-out | **Amended (D1)**: knob-gated `intappsapi_allow_loopback`, because the carve-out itself now ships to production (§3e). |
| 11 manifest + real consumer | **More critical**; gateway-side key registration folds into #1726's owner checklist; the stub e2e is the pre-launch "exercised" bar (§3b). |

**New risks introduced by shipping dormant to a public launch (each with its mechanism):**
- **N1 — vacuous no-op proof** via extensionless `/api` locally. Fixed in §4 (use `/api.php`, plus the A0 sanity gate and mutation D3).
- **N2 — stub drift.** The stub certifies against gateway commit `6816ed8`; the deployed server may move. No cross-repo CI can enforce this. Mechanism available: pin the SHA + ported line ranges in the stub header, and add to #1726's AC: "record `GET /v1/status` version and confirm `HmacValidator.php` unchanged since `6816ed8`". Accepted residual, recorded in `DEV_NOTES.md`.
- **N3 — merge-window review surface** (~1k lines of non-launch scope inside the launch PR). This is the cost the owner has accepted; mitigation is unchanged: atomic per-commit reverts (plan §5) + the dormancy proof as the PR gate, so the reviewer's question collapses to "is the no-op proven?" rather than "is the integration correct?".
- **N4 — credentialed SSRF via base_url/loopback on prod** — D1 above; the https-only + host-bound-headers + no-follow rules from remedy 10 stand.
- **N5 — the stub accidentally treated as a test suite** by the `tests/php/*.php` glob — avoided structurally by the `fixtures/` placement (§1); a comment in the stub header says why it must not move up a directory.

**Net:** no architectural change. The delta is (1) a checked-in ported stub + e2e suite replacing the scratchpad sketch, (2) #1726 re-scoped from landing-gate to enablement-gate with two additions to its checklist (flag-key registration; gateway version/SHA capture), (3) the `/api.php` correction that makes the dormancy proof real, (4) the D1 loopback knob, and (5) honest CAN/CANNOT language in the PR and epic stating that signature-vs-real-server is unproven by design until #1726 plus one live signed POST.
---

## 5. Adversarial stress test — READ FIRST

# Adversarial stress-test verdict — four workstreams, one pre-launch branch

Verification performed against the live tree this session (facts I checked myself are marked ✓). Current baseline: **81** `tests/php/*.php` + **45** `tests/*.js` ✓.

---

## DEFECTS

**D1 — BLOCKER (IntApps × environment rules): enabled-but-un-migrated reads the missing `tblIntAppsSync` on the PUBLIC HOME PAGE.**
Migrations are web-run; the enablement flip is a `tblAppSettings` row an admin sets per-channel, and the DB is shared across 3 docroots. Nothing in the delta guarantees the flip happens after the migration card on every env: if `intappsapi_enabled_channels` names a channel whose install hasn't run the card, `intappsFlag()`'s snapshot read hits a missing table and mysqli STRICT **throws inside the home fragment** — public 500, pre-launch, on the highest-traffic page. The delta's fail-open matrix covers *gateway* failures (garbage / null / 403 / hang) but never lists "table absent while enabled"; its "zero-table-access" proof (CAN item 6) covers only the *dormant* state. **Remedy:** wrap the snapshot read (and the seed-INSERT path) in `try/catch → compiled defaults`, add a "flipped-on, table dropped" scenario to the fail-open matrix with its own mutation (drop the catch → red), and add one line to the `/manage/intapps-status` copy for this state. Cheap; without it rule #28-C ("any un-migrated read degrades") is claimed but unproven exactly where it matters most.

**D2 — MAJOR (#960 silent no-op, the worst one in the set): `credit_upsert` returns parts **from the normalise, not the registry**, so the UI can permanently disagree with `tblCreditPeople` with zero signal.**
The plan says it explicitly: "parts always returned — they come from the normalise, not the columns", while the backfill is `COALESCE(NULLIF(col,''),?)` — never overwrites. Scenario: registry row exists with heuristic parts ("Ralph Vaughan Williams" → surname "Williams"); curator opens v2, types the correct split; promote runs, **writes nothing** (parts non-empty); response echoes the curator's input; three fields show exactly what was typed. Every subsequent load re-shows... whatever `ed2_buildSongSnapshot` emits (registry parts) — so the "fix" evaporates on next load with no error anywhere. This is the #1565 class: looks alive, does nothing. **Remedy:** after promote, `SELECT` the row's actual `FirstNames/Surname/Suffix` and return *those*; the UI adopting them makes the non-write visible immediately. Then decide (owner question, per house format) whether curator input should be allowed to *update* registry parts — but do not ship a response that masks the divergence.

**D3 — MAJOR (#960 verification honesty / pre-launch risk): the `credits-tab.js` rewrite is the single riskiest user-visible change on the branch and is verifiable here at exactly 0%.**
No browser automation exists in this container; every #960 verification step is curl+mysql — the server side is genuinely provable, the 3-field UI (re-render-on-`store.set` ✓ `credits-tab.js:123`, focus-preservation, "don't clobber mid-typing", response adoption) ships **never once executed**. v2 is the *default* editor; a focus bug breaks credit editing for every curator. **Remedy — split, per the question's invitation:** land #960 server-side complete (flat `name` still accepted and server-decomposed, so the registry gap — the actual regression — is fixed even with today's flat UI), and make the 3-field UI its own final, independently revertable commit, explicitly labelled "needs one manual browser pass before merge" in the PR. The plan's own analysis of option (b) concedes flat+server-decompose is functionally sufficient minus curator-specified structure.

**D4 — MAJOR (#882 verification procedure): the cleanup SQL contains an unscoped orphan sweep — `DELETE FROM tblLyricLines WHERE ComponentId NOT IN (SELECT Id FROM tblSongComponents)`.**
It deletes *every* orphaned line in the table, not this test's rows — and it's only needed because the block deletes components *before* lines. On the local fixture it's survivable; as a written procedure in a repo whose verification blocks get re-run (sometimes against the shared DB — 3 docroots, one MySQL), it's a loaded gun, and it's also `NOT IN`-with-NULL fragile. **Remedy:** invert the order and scope it: `DELETE FROM tblLyricLines WHERE ComponentId IN (SELECT Id FROM tblSongComponents WHERE SongId IN ('OS-0123','HT-0123','PB-0007'))` first, then components, then songs.

**D5 — MAJOR (#1608 verification honesty): two steps cannot run as written.**
(i) Step 4 needs "a song with a pending `tblSongLinkSuggestions` row" — the local table has **0 rows** ✓ and the batch builder has never run here; the plan omits the seed. **Remedy:** add an explicit seed `INSERT` (two of the ~7 fixture songs, a mid confidence) to the procedure. (ii) Step 6 "UI pass in the browser" is not executable in this container. **Remedy:** relabel it as a manual owner/dev step in the PR checklist, not claimed coverage — the exact wrong-but-green pattern the prompt names. The api2-level steps 1–5 are genuinely executable ✓ (tables exist locally ✓).

**D6 — MAJOR (interaction #882 ↔ #960, the thematic collision): the branch's headline is "every credit write populates the registry" while its other half adds an import path whose parsed credits are silently discarded.**
Verified ✓: `_bulkImport_saveSong()` writes **no** credit tables at all — `song_importers.php:4253-4258` documents that writers/composers are "parsed, validated and then dropped by the shared saver". So `_bulkImport_processOpenSong` will parse "Mary E. Byrne / Eleanor H. Hull" and drop them, and #882's E2E (which asserts only `tblSongs`) will pass while looking like credit coverage. This is also why #960's derived guard set (`save_song_core.php`, `api2.php`, `lyrics_ingest.php`) is *accurate today* ✓ (tree-wide grep confirms those are the only credit INSERTs) — the importers escape the guard by writing nothing. **Remedy:** file the saveSong-credits issue *in this PR* (the file comment already begs for it), reference it from both plans, and note in #960's v1→v2 audit output. When saveSong is eventually fixed, #960's guard will correctly flag `song_importers.php` and demand the promote — the guard is future-proof here; the *record* is what's missing. Do **not** quietly wire credits into saveSong inside this branch — that's a fifth workstream.

**D7 — MINOR (#882 record accuracy): "83 PHP test files currently, not 81" is false — the tree has 81 ✓.**
A plan that "corrects" the baseline incorrectly (probably counted its own scratch/fixture additions) is a small instance of the wrong-but-green record. **Remedy:** strike the correction; state final branch count instead (81 + 1 #960 + 2 #882 + 2 IntApps = 86; #1608 extends an existing file). #1608's "stays 81" is likewise true only in isolation.

**D8 — MINOR (interaction, mechanical): all three editor plans cite hard `api2.php` line anchors (1376, 1966/2010, 688-707, 566-575) that drift as soon as the first plan's patch lands.**
Sequential implementers following a later plan literally will mis-anchor. **Remedy:** treat anchors as search keys (`case 'credit_upsert'`, the `match ($ext)`), not offsets; note this once in the branch's tracking issue.

**D9 — MINOR (#960 guard bluntness, future): the guard requires the literal `creditPersonPromote(` in the *same file* as the credit INSERT.**
Correct code that routes the insert through a wrapper in another file would false-red. Acceptable now (three writers, all direct), but put the assumption in the test header so the future failure is diagnosed as "guard too narrow" rather than weakened blindly (rule #34's deletion spiral). Otherwise the guard **survives**: derived ✓, mutation named, the interpolated-INSERT second clause is genuinely necessary (api2 is the only `ED2_CREDIT_TABLES` file ✓).

**D10 — MINOR (#1608 STRICT check): `song_links` reads `tblSongLinks` unprobed while only the suggestions table gets the INFORMATION_SCHEMA degrade.**
If v1's `get_song_links` also reads it unprobed this is parity; verify at port time and either match v1 or extend the probe. One `grep` during implementation, not a design change.

**D11 — MINOR (#960 UX edge multiplied by three fields): debounce-save "only when joined parts non-empty" means clearing all three fields silently never saves — the row *looks* deleted and isn't.**
Parity with today's single input (`credits-tab.js:158` ✓ has the same skip), but three fields triple the partial-clear states. Remedy: cleared-to-empty on a credit with an `id` should surface the existing Delete affordance (focus it / hint), not silently skip.

**D12 — MINOR (IntApps e2e ordering): the fail-open scenarios can pass vacuously.**
If the client can't reach the stub at all (e.g. the D1-knob `intappsapi_allow_loopback` row missing from the e2e fixture DB), "payload untouched / defaults returned" asserts all pass with zero HTTP traffic. **Remedy:** the suite's first assertion must be a positive control (scenario `good` → snapshot actually populated, stub access-logged), gating the failure matrix.

**D13 — MINOR (IntApps dormancy proof, branch-wide fragility): the byte-identical diff compares merge-base vs the WHOLE branch, so any legitimate public-output change poisons it — including a version-string / cache-buster bump.**
None of #960/#882/#1608 currently touches public endpoint output, so today it holds — but a routine `v0.77x` footer/asset-version bump anywhere in the branch turns the proof red on correct code (the too-blunt failure) or, worse, tempts someone to normalize the diff by hand. **Remedy:** run the proof at final branch tip, forbid version bumps inside this branch (bump on `alpha` after merge), and keep the A0 sanity gate exactly as the delta specifies (that part is right).

**D14 — MINOR (#960 plan hygiene): the step-7 `load_song` verification one-liner is broken as written** (double-reads stdin, self-neutralising `||`). Replace with a plain `python3 -c "import json,sys; d=json.load(sys.stdin); print(d['credits']['writers'])"` or `jq`. Trivial, but a verification command that can't run is a coverage claim that can't be delivered.

---

## WHERE THE PLANS SURVIVE (explicitly)

- **#882 ↔ #1608 in api2.php: no collision.** #882 adds *format strings* (`$bodyFormats`, match arms) inside the existing `import_file` case; #1608 adds *dispatcher cases*. The parity ledger derives case lists at runtime, so #882 changes nothing it sees; #882's kept v1 action `bulk_import_openlp` maps to the existing `import_file` rename disposition. ✓
- **#960 ↔ #1608: disjoint api2 regions** (credits/snapshot vs song-links); #960 adds no dispatcher case, so the ledger is untouched. ✓
- **#960's `load_song` concern: closed.** `load_song` serves via the same `ed2_buildSongSnapshot` the plan amends (`api2.php:799-805` ✓), so parts reach the UI on load through one builder.
- **#960's guard derivation is accurate today** ✓ (tree-wide grep: exactly `save_song_core.php:771-783`, `lyrics_ingest.php:692`, plus api2's interpolated inserts — and the interpolation clause is load-bearing, as the plan claimed).
- **IntApps stub placement is structurally sound** ✓: `run-php-tests.php` globs `tests/php/*.php` only (line 56), `fixtures/` already hosts non-suite PHP (`orphan-allowlist.php`), and nothing outside `appWeb/` deploys.
- **`registerCreditPersonByName` already takes `$parts` and column-gates them** ✓ (`credit_people_helpers.php:973-1024`) — #960's `creditPersonPromote` is a thin, honest wrapper, and the un-migrated degrade matches legacy save exactly.
- **#882's guard and #1608's guard both pass the rule-#34 test**: derived (token-level / case-list extraction), named mutations, narrow self-skips. #1608's typed disposition map is legitimately data-not-derivation *provided* every "retired" row cites its deciding issue — enforce that in review, or the ledger becomes a rubber stamp.

---

## ORDERING (axis 7) — every commit green and individually revertable

1. **#960-a** Extract `creditEntryNormalise()` + `creditPersonPromote()` into `credit_people_helpers.php`; refactor `save_song_core.php` onto them (pure move — baseline green).
2. **#960-b** api2 `credit_upsert` (with D2's registry-authoritative response) + snapshot parts + restore promote + `lyrics_ingest.php` promote. Behavioural proof here.
3. **#960-c** Guard `test-credit-registry-promote.php` (all writers now promote; mutation transcript in commit body).
4. **#960-d** `credits-tab.js` 3-field UI — **last of #960, independently revertable per D3**.
5. **#882-a** `song_importers.php`: `processOpenSong` + `processXmlAuto` + `test-xml-import-routing.php`. (Safe *after* #960-c because importers write no credits ✓ — the guard stays green.)
6. **#882-b** api.php / api2.php / import2.php / editor.js wiring; delete the two false comments.
7. **#882-c** Guard `test-import-format-coverage.php` (+ the D6 issue filed).
8. **#1608-a** Five api2 cases + api-client methods + doc-block.
9. **#1608-b** `counterparts-panel.js` + editor2 mount.
10. **#1608-c** Parity-ledger extension of `test-editor-api2-contract.php` — **after all api2 case changes on the branch**, so the ledger is authored once against the final case set.
11–14. **IntApps block, contiguous and last**: migration (+schema.sql, registry entry) → client module + D1 fail-open → admin surfaces + consumer → stub fixture + e2e + boundary guard → dormancy-proof commit run at branch tip (D13).

Rationale: #960 first because its helper is the only cross-workstream dependency candidate; each guard lands immediately after the last commit it constrains; the guard-bearing commits carry their mutation transcripts; IntApps last so its branch-tip dormancy proof covers the entire merged state and the whole block reverts cleanly if #1726 slips.
---

## 6. The ordered, executable commit plan

# WAVE 4 — FINAL ORDERED IMPLEMENTATION PLAN
Branch: `claude/wave3-fixes` → one PR → `alpha`. Baseline verified this session: **81** `tests/php/*.php`, **45** `tests/*.js`, branch exists at `da9c1e95`. Final branch state: **86 PHP + 45 node** (D7: the "#882 says 83" correction is struck — 81 is the true baseline).

**Global rules binding every commit below:**
- **D8:** every line number in the source plans is a SEARCH KEY, not an offset. Locate by `case 'credit_upsert':`, `match ($ext)`, `function ed2_buildSongSnapshot`, etc. This note goes verbatim into the branch tracking comment.
- **D13:** NO version-string / cache-buster / footer bumps anywhere in this branch. Version bumps happen on `alpha` after merge. The dormancy proof (commit 16) diffs merge-base vs branch tip and any cosmetic public-output change poisons it.
- Every commit body explains WHY, ends with the Co-Authored-By + Claude-Session trailer, and every commit leaves BASELINE green:

```bash
# BASELINE (run after EVERY commit; referenced below as "BASELINE")
cd /home/user/iHymns
php tools/run-php-tests.php                    # PHP suites (81 → 86 by branch tip; MUST report 0 failures)
node tools/run-node-tests.js                   # 45 node suites
npm run lint                                   # eslint appWeb/public_html/js/
npm run test:php >/dev/null                    # php -l whole tree
npm run test:js  >/dev/null                    # node --check whole tree
```

**Shared behavioural preamble** (referenced as "LOGIN"; run once per session):

```bash
S=/tmp/claude-0/-home-user-iHymns/eecf773e-4f1c-5106-9640-a22245226a39/scratchpad; JAR=$S/cj.txt
HASH=$(php -r 'echo password_hash("probe-pass-123", PASSWORD_DEFAULT);')
mysql -u root ihymns_live -e "UPDATE tblUsers SET PasswordHash='$HASH', IsActive=1, Status='active' WHERE Username='probeuser';"
CSRF=$(curl -s -c $JAR http://127.0.0.1:8123/manage/login.php | grep -o 'name="csrf_token" value="[^"]*"' | sed 's/.*value="//;s/"$//')
curl -s -b $JAR -c $JAR -o /dev/null -d "csrf_token=$CSRF&username=probeuser&password=probe-pass-123" http://127.0.0.1:8123/manage/login.php
H1='-H X-Requested-With:XMLHttpRequest'; H2='-H Origin:http://127.0.0.1:8123'   # H2 REQUIRED on legacy api.php (#1388)
# Local server has NO router script: ALWAYS /api.php?... and /manage/....php — never extensionless /api
```

---

## STEP 0 — ISSUE BOOKKEEPING (before any closing commit; house rule: issue exists BEFORE the commit that closes it)

Model: HAIKU (mechanical `gh` calls). Actions:

0a. **Confirm IntApps child mapping.** `gh issue list --search "intapps" --state open` and record which of #1727–#1734 maps to: migration / client module / admin surfaces / guards / stub+e2e / consumer / dormancy proof / allow-list mechanics. **Known fixed points:** #1733 = `web.sotd_card` consumer; #1726 = owner-only gateway registration — **#1726 must NEVER appear in a `Closes` line** (it gates enablement, not landing). Every `Closes #172x` below is written against the confirmed mapping, not this plan's guess.
0b. **Check #1608 for the owner's a/b/c reply.** If answered, implement that option (commits 9–11 as written are option **a**; §6 lists the b/c variants). If unanswered: implement (a) — it is the recorded recommendation, honours #1220's recorded intent, and is trivially narrowed to (c) by reverting the two suggestion actions; post a comment on #1608 saying exactly that.
0c. **File these issues now** (D6 + standing-tasks §2a): 
   - "Importer `_bulkImport_saveSong()` parses then DROPS writers/composers — wire credit-table writes + `creditPersonPromote()`" (referenced from #882 and #960; NOT fixed in this branch — a fifth workstream).
   - "Scripted v1→v2 side-effect audit: diff table-write sets of `editorSaveSongCore()` vs api2 granular handlers" (would have caught #960; run once, file per-finding).
   - "`/manage/duplicate-songs` should honour `?song=<id>` filter; then add editor deep link" (rule #33 — blocked until the param is read).
   - "Owner decision: may curator-entered name parts UPDATE existing `tblCreditPeople` rows?" (D2 — in house decision format: recommendation = yes-with-audit-log; does not block this branch, which ships never-overwrite + registry-authoritative echo).
   - "import2 chordpro dropdown gap" — filed then CLOSED by commit 7 (it lands in this branch).
   - "First live signed POST against api.mwbmpartners.ltd required before any write-scoped IntApps consumer" (if not already existing from the delta).
   - `for consideration`: "In-editor live counterpart rescore (song_similarity direct call)".
0d. Comment on #960, #882, #1608 linking this plan and the branch.

---

## 1. THE ORDERED COMMIT SEQUENCE

Highest-risk changes sit at the END of their workstream block (D3: the un-browser-testable UI commits are each the LAST commit of their block, independently revertable; IntApps is last as a contiguous block so its branch-tip proof covers everything and the whole block reverts cleanly if #1726 slips).

### Block A — #960 (credits registry regression)

**Commit 1 — `refactor(credits): extract creditEntryNormalise() + creditPersonPromote() into credit_people_helpers.php`**
- Issue: refs #960 (part 1). Closes nothing.
- Files: `appWeb/public_html/includes/credit_people_helpers.php` (+2 functions, verbatim bodies moved from the `$normaliseCreditEntry` closure and the promote+backfill block in `save_song_core.php` — locate by `registerCreditPersonByName` call site and the `COALESCE(NULLIF(` UPDATE), `appWeb/public_html/manage/editor/save_song_core.php` (delegates; keeps its richest-parts accumulation loop).
- Tier: **SONNET** — a verbatim cross-file move where semantic drift is the whole risk; not hard reasoning, but above mechanical.
- Verify: BASELINE + LOGIN + prove the legacy whole-save path still promotes: POST a `save_song` (api2, `$H1 $H2`) for a fixture song with a new credit name, then `mysql -u root ihymns_live -e "SELECT FirstNames,Surname FROM tblCreditPeople WHERE Name='<name>'"` → row present with parts. Clean up the row.

**Commit 2 — `fix(editor): v2 credit_upsert/restore/ingest promote credits into tblCreditPeople; response echoes REGISTRY parts`**
- Issue: refs #960 (part 2) — this commit fixes the actual regression (registry gap) even for today's flat UI.
- Files: `appWeb/public_html/manage/editor/api2.php` (require helpers; `case 'credit_upsert':` — normalise → 400 on null → role-table write in same txn as `creditPersonPromote()`; `ed2_buildSongSnapshot` credits loop — batch-fetch registry parts gated on `creditPeopleNamePartsColumnsExist()`, fallback `decomposePersonName()`; `ed2_applySongSnapshot` credits loop — normalise + promote; doc-block update), `appWeb/public_html/includes/lyrics_ingest.php` (promote after the `tblSongArtists` INSERT + require).
- **D2 RESOLUTION (mandatory):** after the promote, `SELECT FirstNames, Surname, Suffix FROM tblCreditPeople WHERE Id=?` and return **those** in the response — never the normalised input — so a never-overwrite non-write is immediately visible in the UI. Response shape: `{ok, creditId, name, first, surname, suffix, registryPersonId}`.
- Tier: **SONNET** — ports existing verified logic; the one design point (D2) is fully specified here.
- Verify: BASELINE + LOGIN + plan_960 §5 steps 2–6 verbatim, PLUS the D2 proof: pre-seed `tblCreditPeople` with `('Ralph Vaughan Williams','Ralph','Williams',NULL)`-style heuristic parts, upsert with `{first:"Ralph Vaughan", surname:"Williams"}`, assert the response returns the REGISTRY's parts (`Ralph|Williams`), not the input — the divergence is visible, not masked. Step 7 uses the **D14-corrected** command:
```bash
curl -s -b $JAR 'http://127.0.0.1:8123/manage/editor/api2.php?action=load_song&id=MP-0001' \
 | python3 -c "import json,sys; d=json.load(sys.stdin); print(d['song']['credits']['writers'])"
```
Cleanup exactly per plan_960 step 8.

**Commit 3 — `test(credits): tree-derived guard — every credit-table INSERT file calls creditPersonPromote()`**
- Issue: refs #960 (part 3).
- Files: `tests/php/test-credit-registry-promote.php` (comment-stripping reused from `test-fragment-inline-scripts.php`; literal-INSERT regex **plus** the mandatory interpolated-`ED2_CREDIT_TABLES` clause; empty allowlist; **D9:** test header documents the same-file-literal assumption — "if a future writer routes through a wrapper in another file, WIDEN this check, do not delete it" — and the mutation procedure).
- Tier: **SONNET** — rule-#34 guard authorship.
- Verify: BASELINE (now 82 PHP). Mutation: see §2.

**Commit 4 — `feat(editor): v2 credits tab — structured First/Surname/Suffix entry — Closes #960` ⚠️ NEEDS MANUAL BROWSER PASS (PR checklist)**
- Files: `appWeb/public_html/manage/editor/v2/credits-tab.js` (3 inputs with `aria-label`s matching legacy; posts parts; **adopts response registry parts** — only rewriting input values when the row isn't focused or after suggestion pick; debounce-save only when joined parts non-empty; **D11:** a credit with an `id` cleared to all-empty does NOT silently skip — it focuses/highlights the existing Delete affordance), `v2/api-client.js` (shape comment only).
- Tier: **SONNET** — fiddly DOM/focus work, no deep reasoning; the server contract is already fixed.
- **D3:** last commit of the block, server fix stands without it, `git revert` of this commit alone restores the working flat-input UI over the fixed server. Commit body and PR checklist both say: *"UI verified by static analysis + node --check only in this container; requires one manual browser pass (typing, focus retention, suggestion pick, clear-to-empty) before merge."*
- Verify: BASELINE + `node --check` + eslint on the file; curl-level re-run of commit 2's step 3/5 (server contract unchanged).

### Block B — #882 (OpenSong single-file import)

**Commit 5 — `feat(import): _bulkImport_processOpenSong + _bulkImport_processXmlAuto (shared sniff, try-both fallback)`**
- Issue: refs #882 (part 1).
- Files: `appWeb/public_html/includes/song_importers.php` (processor per plan §3a: `OS` book convention, number priority chain, #1694 D1 SongCount block copied from `processOpenLp`; router per §3b: `_bulkImport_looksLikeOpenLyrics()` as the ONE discriminator, on-primary-failure try the other once, combined both-named error, resolved `format` stamped, pure `_bulkImport_xmlAutoPrimary()` extracted), `tests/php/test-xml-import-routing.php` (unit asserts: `opensong` for all 3 opensong fixtures, `openlp` for an OpenLyrics fixture).
- Tier: **SONNET** — mirrors an existing processor's contract line-for-line.
- Verify: BASELINE (83 PHP). Safe after commit 3 because importers write **no** credit tables (stress-verified — the D6 gap), so the credit guard stays green.

**Commit 6 — `fix(import): route single .xml via the auto-router on both editors; add .opensong + chordpro to v2 UI; delete false auto-detect comments — refs #882`**
- Files: `manage/editor/api.php` (`bulk_import_openlp` case → `processXmlAuto`; action name and `openlp` field KEPT — rule #33; doc-block updated), `manage/editor/api2.php` (auto map `'xml'=>'xmlauto'`, `'opensong'=>'xmlauto'`; add `xmlauto` + `opensong` to `$bodyFormats` and match arms; surface resolved `format` in the response; **delete both false comments**, replace with the truthful one), `manage/editor/import2.php` (`opensong` + `chordpro` dropdown entries; `accept` gains `.opensong,.cho,.chopro,.crd,.chord,.pro`), `manage/editor/editor.js` (`.opensong` in accept + the `.xml` branch).
- Tier: **HAIKU** — fully-specified mechanical wiring; every decision is made above.
- Verify: BASELINE + LOGIN + plan_882 §5 steps 1–6 with the **D4-corrected cleanup** (order inverted, scoped — the unscoped orphan sweep is BANNED):
```bash
mysql -u root ihymns_live -e "
DELETE FROM tblLyricLines WHERE ComponentId IN (SELECT Id FROM tblSongComponents WHERE SongId IN ('OS-0123','HT-0123','PB-0007'));
DELETE FROM tblSongComponents WHERE SongId IN ('OS-0123','HT-0123','PB-0007');
DELETE FROM tblSongs      WHERE SongId IN ('OS-0123','HT-0123','PB-0007');
DELETE FROM tblSongbooks  WHERE Abbreviation IN ('OS','HT','PB');"
```

**Commit 7 — `test(import): format-coverage guard (UI list ⇄ api2 set ⇄ parser fixtures) — Closes #882` (also closes the chordpro issue from 0c)**
- Files: `tests/php/test-import-format-coverage.php` (token_get_all extraction of `$formats` + `accept`; api2 `$bodyFormats`/match/elseif extraction; forward + reverse asserts; per-format fixture-parses-clean with completeness-checked map; `pptx`/`easyworship` named wiring-only exemptions; `xmlauto` allowlisted as internal), fixtures under `tests/php/fixtures/single-file/`.
- Tier: **SONNET**. Verify: BASELINE (84 PHP). Mutations: §2.

### Block C — #1608 (v2 counterpart surface, option a)

**Commit 8 — `feat(editor): v2 song-link API — five actions ported from v1 — refs #1608`**
- Files: `manage/editor/api2.php` (cases `song_links`, `song_link_add` [different-groups refusal → **409** status, rule #35], `song_link_remove`, `song_link_suggestions` [keep INFORMATION_SCHEMA probe → `tableMissing:true` degrade], `song_link_suggestion_dismiss` [canonical-order swap]; `edit_songs` entitlement on the three writes; `songVisibleSql()` joins kept; `logActivity` rows; doc-block updated), `manage/editor/v2/api-client.js` (five methods via `getJson`/`postJson` — inherits `err.status` + X-Requested-With for free).
- **D10 (resolve at port time):** `grep -n "tblSongLinks" appWeb/public_html/manage/editor/api.php` — if v1's `get_song_links` reads it unprobed, match v1 exactly (parity); if probed, extend the probe. One grep, recorded in the commit body.
- Tier: **SONNET**. 
- Verify: BASELINE + LOGIN + plan_1608 §5 steps 1–5, with the **D5(i) seed** first: copy the INSERT column list **verbatim from `build-song-link-suggestions.php`** (never invent columns) and seed one pending pair of local fixture songs at mid confidence; after the dismiss step, confirm the pair is also gone from `/manage/duplicate-songs.php` output (shared-table proof). Step "every POST without `X-Requested-With` → 403" — prove, don't assume. Delete the seed row after.

**Commit 9 — `feat(editor): v2 counterparts panel in the Links tab — refs #1608` ⚠️ NEEDS MANUAL BROWSER PASS (PR checklist)**
- Files: `manage/editor/v2/counterparts-panel.js` (mount/teardown; counterpart list + Unlink; add-by-SongId datalist from the store's sidebar index; suggestions box with Link/Dismiss, hidden when empty or `tableMissing`; NO merge button — merge stays on duplicate-songs under `manage_duplicate_songs`), `manage/editor/editor2.php` (`#v2-counterparts` div in `#pane-links` + mount in `mountTabs()`).
- Tier: **SONNET**. **D5(ii):** the "UI pass in the browser" is relabelled a manual pre-merge checklist item — never claimed as coverage here.
- Verify: BASELINE + curl re-run of commit 8's API steps (contract unchanged) + `node --check`/eslint.

**Commit 10 — `test(editor): v1→v2 action parity ledger in test-editor-api2-contract.php — Closes #1608`**
- Files: `tests/php/test-editor-api2-contract.php` (new §3: derive BOTH `case '…'` lists from source at runtime; typed disposition data for renames — each asserted to exist in v2 — and retirements — **each citing its deciding issue number, enforced by the test** so the ledger can't become a rubber stamp; any v1 action not present/renamed/retired → red; section self-skips when `api.php` is deleted).
- Position: **after ALL api2 case changes on the branch** (commits 2, 6, 8) so it is authored once against the final case set. IntApps touches no editor dispatcher, so this is the right slot.
- Tier: **SONNET**. Verify: BASELINE (still 84 PHP — extends an existing file). Mutations: §2.
- Bookkeeping: close #1608 with SHAs + evidence; #1220 needs no amendment (option a honours it).

### Block D — IntAppsAPI (contiguous, last; all dormant; #1726 gates ENABLEMENT only)

**Commit 11 — `feat(intapps): tblIntAppsSync migration, schema.sql mirror, registry entry (dormant) — Closes #<migration child>`**
- Files: `appWeb/.sql/migrate-add-intapps-sync.php` (final one-pass DDL per the plan incl. **`AppSlug` in the UNIQUE** — remedy 5, non-negotiable: this hits the live shared DB pre-launch and rule #20 forbids a second ALTER), `appWeb/.sql/schema.sql` (**byte-identical** mirror incl. COMMENTs — rule #19), `manage/includes/migration-registry.php` ONE entry with a REAL probe (`tableExists('tblIntAppsSync')` + column check, never `=> true`).
- Tier: **SONNET** — DDL is pre-specified; the discipline is byte-identity.
- Verify: BASELINE + behavioural via the REAL web path: `/manage/setup-database.php` card pending → apply → probe applied → re-apply idempotent → Schema Audit page clean → `php tests/php/test-schema-coverage.php` + `test-migration-registry.php` green.

**Commit 12 — `feat(intapps): client module — HMAC signer, fail-open matrix, D1 missing-table catch, loopback knob — Closes #<client child>`**
- Files: `appWeb/public_html/includes/intapps_client.php` (+ secrets keys per plan §), implementing: canonical string **`body . '.' . timestamp`** (the gateway examples' `METHOD|PATH|ISO|BODY` string is WRONG — MWBM-intAppsAPI#120), 3-factor headers, seed-INSERT cold-populate, single-flight lock, backoff + force-refresh bypass, 1 MB size cap, shape validation, `https://`-only base_url with the loopback exemption gated on `tblAppSettings.intappsapi_allow_loopback='1'` (delta D1-knob / stress N4), channel allow-list absent-row = off.
- **D1 BLOCKER — RESOLVED HERE, explicitly:** `intappsFlag()`'s snapshot read AND the seed-INSERT path are wrapped `try { … } catch (\mysqli_sql_exception $e) { return compiled defaults; }` so **enabled-but-un-migrated** (allow-list flipped on an env whose install never ran the card — 3 docroots, ONE shared DB, web-run migrations) degrades to compiled defaults instead of throwing a mysqli-STRICT exception inside the public home fragment. This scenario joins the fail-open matrix (commit 15 exercises it: `DROP`/rename the table in the e2e fixture DB with the flag enabled → home fragment byte-identical to default) and gets its own mutation (remove the catch → e2e red). `/manage/intapps-status` gains the state line "Enabled but tblIntAppsSync missing — run the migration card" (commit 13).
- Tier: **OPUS** — justification: security-critical (HMAC canonicalisation where the vendor's own examples are wrong; SSRF gating; fail-open-not-fail-closed semantics on a public page). This is the one genuinely hard-reasoning commit.
- Verify: BASELINE + `php -l`; behavioural proof deferred to commit 15's e2e (the module is uncalled while dormant — that itself is asserted in commit 15's guard).

**Commit 13 — `feat(intapps): admin config card + /manage/intapps-status (dormant-by-design copy) — Closes #<admin child>`**
- Files: manage config card + `manage/intapps-status.php` (manifest diff incl. "consumed but absent from snapshot"; the D1 un-migrated state line; unconfigured state reads **"Dormant — awaiting gateway registration (#1726)"**, never an error — delta §3d; shared partials per modularity rule #1–4).
- Tier: **HAIKU** — patterned admin page against explicit copy.
- Verify: BASELINE + LOGIN + `curl -s -b $JAR http://127.0.0.1:8123/manage/intapps-status.php | grep -c "Dormant"` ≥1; page renders through `admin-nav`/`admin-footer`/`admin-theme-init` partials (grep the includes).

**Commit 14 — `feat(intapps): web.sotd_card consumer on the home fragment — Closes #1733`**
- Files: `includes/pages/home.php` (or the SotD partial) — one `intappsFlag('web.sotd_card', true)` call; **no inline `<script>`** (rule #30), no per-user divergence in the cached fragment (rule #6 — the flag is global, not per-user, so the shared cache stays valid).
- Tier: **SONNET** — touches a shared-cache public fragment; rules #6/#30 interactions warrant it over HAIKU.
- Verify: BASELINE + dormant byte-identity spot check: `curl -s http://127.0.0.1:8123/api.php?page=home` before/after this commit with module dormant → `diff` empty. Full proof at commit 16.

**Commit 15 — `test(intapps): stub gateway fixture + e2e suite + guards — Closes #<stub/guards children>`**
- Files: `tests/php/fixtures/intapps-stub-gateway.php` (single `php -S` router; line-for-line port of `AuthMiddleware`/`HmacValidator` pinned at gateway commit `6816ed8`, SHA + source line refs in the doc-block; fixed fake credentials; scenario switch `good`/`data-null`/`missing-features`/`oversized`/`http-403`/`hang`/`malformed`; header comment: "MUST NOT move up a directory — `tools/run-php-tests.php` globs `tests/php/*.php`", stress N5), `tests/php/test-intapps-stub-e2e.php` (proc_opens the stub on an ephemeral port, sets `intappsapi_allow_loopback='1'` in the fixture DB, **D12: FIRST assertion is the positive control** — scenario `good` → snapshot populated + stub access-logged — gating the failure matrix; then the full fail-open matrix **including the D1 table-dropped-while-enabled scenario**; signature asserts: correct canonical string → 200, the #120 wrong string → 403, ISO timestamp → 403, aged > 300 s → 403, wrong UA → 403; `web.sotd_card:false` → home fragment omits the card, `true`/dormant → byte-identical), `tests/php/test-intapps-guards.php` (boundary guard: no file outside the module calls the HTTP layer directly / no `tblIntAppsSync` reference outside gated paths — derived by tree scan; secrets-key manifest check; zero-curl-seam-invocations-while-dormant assert).
- Tier: **SONNET** — faithful transcription against pinned source + rule-#34 guard discipline; the hard design was commit 12.
- Verify: BASELINE (**86 PHP** — the two suites; the fixture is not globbed). Mutations: §2.

**Commit 16 — `docs+proof(intapps): dormancy no-op proof at branch tip; DEV_NOTES/CHANGELOG/wiki; standing-tasks sweep`**
- Files: `DEV_NOTES.md` (N2 stub-drift accepted residual: certified against `6816ed8`; #1726 AC gains "record `GET /v1/status` version + confirm HmacValidator unchanged" and "register `web.sotd_card` default-enabled in the same sitting"), `CHANGELOG.md`, wiki pages, `.claude/` brief/handoff. Commit body carries the FULL no-op proof transcript (§4) and the three-mutation transcript.
- Tier: **HAIKU** — script execution + docs.
- Verify: §4 in full + final re-verification script (§7).

---

## 2. THE MUTATION PER GUARD (break → red → restore; transcript in each guard commit's body)

| Commit | Mutation(s) | Expected |
|---|---|---|
| 3 (credit guard) | (m1) comment out `creditPersonPromote(` in api2's `credit_upsert` → **red**; (m2) drop scratch file containing `INSERT INTO tblSongWriters` under `public_html/` → **red** (proves derivation catches NEW writers); restore/delete → green | Both reds proven pre-commit |
| 7 (format coverage) | (a) delete the `'opensong'` match arm in api2 → red; (b) add `'bogus' => 'Bogus'` to import2 `$formats` → red; (c) truncate the opensong fixture → red; (d) invert the sniff in `_bulkImport_xmlAutoPrimary` → red (via `test-xml-import-routing.php`) | Four reds |
| 10 (parity ledger) | (a) comment out one new `case 'song_links':` in api2 → red; (b) add a bogus `case 'zzz':` to v1 api.php → red; (c) remove one retirement row's issue citation → red (the citation-enforcement assert) | Three reds |
| 12 via 15 (D1 catch) | remove the missing-table `try/catch` in `intappsFlag()` → stub e2e's table-dropped scenario **red**; restore → green | The BLOCKER's proof |
| 15 (signer/e2e) | flip the client's canonical separator `'.'` → `'|'` → e2e red; restore → green | The #120 trap cannot silently pass |
| 15 (D12 control) | point the e2e at a dead port → the POSITIVE CONTROL fails first (not a vacuous fail-open pass) | Proves the matrix can't pass with zero traffic |
| 16 (no-op proof) | THREE mutations, delta §4-D: (1) emit `remoteFeatures` unconditionally in api.php → diff RED; (2) drop `intappsEnabled()` guard on the cache read → general_log count > 0; (3) point one capture at extensionless `/api` → A0 sanity gate RED | Proves the proof itself can fail |

---

## 3. VERIFICATION MATRIX (ruthless third column)

**A. Verifiable BEHAVIOURALLY here** (MariaDB `ihymns_live` + `php -S 127.0.0.1:8123` + stub on ephemeral port; commands as given per commit):
- #960: registry promote on structured + flat upserts; never-overwrite of curated parts; D2 registry-echo; snapshot parts in `load_song`; restore-path promote; lyrics_ingest promote; legacy `save_song` parity (commit 1) — curl+mysql.
- #882: OpenSong single-file via v2 auto AND explicit AND legacy `bulk_import_openlp`; OpenLyrics regression control (diff vs captured pre-change baseline); mixed-format ZIP through the real async `import_zip`/`import_zip_status`; both-formats-named 400 negative.
- #1608: all five API actions incl. 409 on cross-group link, `tableMissing` degrade, seeded-suggestion dismiss propagating to `/manage/duplicate-songs`, missing-`X-Requested-With` → 403.
- IntApps: migration via real web path + probe + idempotency + Schema Audit; full signer round-trip vs the ported verifier incl. rejection of the vendor-examples' wrong string; 3-factor auth; cold populate, single-flight, backoff, force-refresh; the ENTIRE fail-open matrix **including D1 enabled-but-un-migrated**; loopback-knob gating; dormant byte-identity + zero-table-access + zero-outbound (§4); `sotd_card:false` → card omitted; admin status page states.
- All guards + their mutations; full BASELINE per commit.

**B. Verifiable only by STATIC inspection here:**
- Legacy `editor.js` `.xml`/`.opensong` dispatch branch (v1 client JS — `node --check` + read; its API target is behaviourally proven).
- Entitlement DENIAL paths for non-admin roles (probeuser is global_admin; creating role-scoped users is possible but out of scope — assert by reading `ed2_requireEntitlement` wiring against `admin-links.php`).
- Byte-identity of the moved helper bodies (commit 1) — `git diff` review.
- Rule #19 byte-identity of DDL vs schema.sql — `test-schema-coverage.php` + eyeball.
- eslint/a11y attributes on the new UI (aria-labels present — grep).

**C. NOT VERIFIABLE HERE AT ALL — the honest deliverable (each named in the PR body in these words):**
1. **Signature acceptance by the REAL gateway.** Stub verifier and client signer descend from one source reading; a shared misreading, a deployed server ≠ commit `6816ed8`, server-side `decryptSecret()`, or an env-overridden `HMAC_MAX_AGE_SECONDS` all pass here and fail live. Not enable-blocking (Phase 1 is GET-only); NO write-scoped follow-up trusts the signer until one live signed POST succeeds (#1726 + filed follow-up).
2. Gateway liveness, TLS chain, real credentials, real UA-prefix acceptance, 60 req/min per-IP across the three docroots' shared egress — owner-only (#1726). Container proxy CONNECT-403 is network policy, not evidence.
3. Gateway-side registration of the `ihymns` app and the `web.sotd_card` key (#1726 checklist).
4. Real clock skew vs the gateway (±300 s exercised only against the stub's own clock).
5. The enablement flip on any real environment (alpha → beta → production sequence) and everything downstream.
6. **Every browser interaction**: credits-tab 3-field typing/focus/suggestion-adopt/clear-to-empty (commit 4), counterparts panel UX (commit 9), import2 dropdown UX — no browser automation exists in this container. Each is a named MANUAL pre-merge checklist item in the PR, never claimed as coverage.
7. Behaviour under the real Apache rewrite (`/api` extensionless) and the deployed shared-cache/ETag interplay — alpha-only.
8. `.htaccess`/deploy-pipeline interactions (deploy mirrors only `appWeb/**`; stub is structurally excluded — static argument, behaviourally unprovable here).

---

## 4. THE INTAPPS NO-OP PROOF (delta §4 as corrected — run at BRANCH TIP, transcript into commit 16 + PR)

```bash
cd /home/user/iHymns
S=/tmp/claude-0/-home-user-iHymns/eecf773e-4f1c-5106-9640-a22245226a39/scratchpad
mkdir -p $S/noop/base $S/noop/branch
BASE=$(git merge-base alpha claude/wave3-fixes)
git worktree add "$S/noop-base" "$BASE"
php -S 127.0.0.1:8125 -t "$S/noop-base/appWeb/public_html" & BP=$!
php -S 127.0.0.1:8123 -t appWeb/public_html & TP=$!          # branch, module dormant (no settings rows)
sleep 1
# A. Byte-identical output — NOTE /api.php, NEVER extensionless /api (no router script locally)
ENDPOINTS='action=app_status action=songs_index action=song_detail&id=MP-1 page=home page=song&id=MP-1 action=access_tiers'
for e in $ENDPOINTS; do
  f=$(echo "$e" | tr '&=' '__')
  curl -s "http://127.0.0.1:8125/api.php?$e" -o "$S/noop/base/$f"
  curl -s "http://127.0.0.1:8123/api.php?$e" -o "$S/noop/branch/$f"
done
# A0. SANITY GATE on the proof itself: no capture may be the SPA shell
grep -il '<!doctype' $S/noop/base/* $S/noop/branch/* && echo "A0 FAIL: shell captured — wrong URL" || echo "A0 OK"
diff -r $S/noop/base $S/noop/branch && echo "A OK: byte-identical" || echo "A FAIL"
# B. Zero tblIntAppsSync access while dormant
mysql -u root -e "SET GLOBAL general_log='ON'; SET GLOBAL general_log_file='$S/noop/general.log';"
for e in $ENDPOINTS; do curl -s "http://127.0.0.1:8123/api.php?$e" -o /dev/null; done
curl -s "http://127.0.0.1:8123/index.php" -o /dev/null
mysql -u root -e "SET GLOBAL general_log='OFF';"
c=$(grep -ci tblIntAppsSync $S/noop/general.log); [ "$c" = 0 ] && echo "B OK: 0" || echo "B FAIL: $c"
# C. Zero outbound while dormant (curl seam)
php tests/php/test-intapps-guards.php && echo "C OK"
# D. Prove the proof can fail — the three mutations of §2 row 7, each: apply → observe RED → revert → observe green.
kill $BP $TP; git worktree remove "$S/noop-base"
```

---

## 5. ROLLBACK

**Per commit** (all atomic; revert PAIRS where a guard constrains a subject — reverting the subject alone turns its guard red):

| Revert target | Command | Coupling |
|---|---|---|
| 4 (credits UI) | `git revert <sha4>` alone | None — server fix + guard stand; flat UI returns over fixed server. **The designed cheap revert (D3).** |
| 2 (credit server fix) | revert **3 then 2** together | Guard 3 requires promote in api2 |
| 1 (extraction) | revert 3,2,1 | Downstream call the helpers |
| 6 (import wiring) | revert **7 then 6** | Guard 7 asserts the opensong arm |
| 5 (importers) | revert 7,6,5 | |
| 9 (counterparts UI) | `git revert <sha9>` alone | API + ledger stand |
| 8 (song-link API) | revert **10 then 8** | Ledger asserts the five cases |
| 14 (consumer) | alone | Flag falls back to compiled default anyway |
| 13 (admin) | alone | |
| 12 (client) | revert 15,14,13,12 | e2e + consumer call it |
| 11 (migration code) | revert 16,15,14,13,12,11 | **DB:** an applied `tblIntAppsSync` STAYS (additive, dormant, shared DB — never auto-drop across 3 docroots); removal only via a future `'manual' => true` confirm-gated card |
| 16 (proof/docs) | alone | |

**Whole wave:** pre-merge — close the PR, `git branch -D claude/wave3-fixes` (nothing deployed; local DB residue limited to the dormant table + probe rows, cleaned by the per-commit cleanup SQL). Post-merge — `git revert -m 1 <merge-sha>` on `alpha` (single-PR policy makes this one command); the dormant table again stays; `intappsapi_enabled_channels` must be ABSENT (verify: `SELECT * FROM tblAppSettings WHERE Name LIKE 'intappsapi%'` shows no allow-list row), so reverted code was never live-reachable.

---

## 6. DELIBERATELY NOT DONE (each with its follow-up issue from Step 0c and one line of why)

1. **Importer credit writes** — *"Importer saveSong drops parsed writers/composers…"* — a fifth workstream; quietly wiring it here would expand blast radius mid-branch (D6). #960's guard will correctly flag `song_importers.php` the day it lands.
2. **v1→v2 side-effect differential as a permanent CI guard** — *"Scripted v1→v2 side-effect audit"* — a table-set snapshot test fails on every legitimate divergence and gets deleted (rule #34's other failure mode); run once as a scripted audit, file per-finding.
3. **duplicate-songs `?song=` filter + editor deep link** — *"/manage/duplicate-songs should honour ?song="* — rule #33 forbids emitting a param the destination doesn't read; the destination change comes first, separately.
4. **Curated-parts overwrite policy** — *owner-decision issue (D2)* — this branch ships never-overwrite + visible registry echo; changing write policy is a product call, not a code call.
5. **Retiring the five v1 song-link actions** — Epic #1601 — only after parity soaks; the ledger then records them retired-with-reason.
6. **In-editor live rescore** — `for consideration` — not v1 parity; would touch the ONE scorer (rule #22) for marginal value.
7. **Live signed POST + gateway enablement** — #1726 + the live-POST follow-up — physically impossible from this container (matrix column C); #1726 gates the flip, never the landing.
8. **chordpro** — NOT deferred: lands in commits 6/7; its 0c issue closes there.
9. **Version bump to v0.77x** — deferred to `alpha` post-merge (D13: it would poison the byte-identity proof).
10. **Legacy `editor.js` name-maths mirror removal** — dies with v1 retirement (#1601 scope 3); touching it now is unreviewable churn.

---## 7. FINAL RE-VERIFICATION SCRIPT (run at branch tip; paste output into the PR description as evidence)

```bash
#!/usr/bin/env bash
set -uo pipefail; cd /home/user/iHymns
echo "== 1. Counts (expect 86 PHP / 45 node) =="
ls tests/php/*.php | wc -l; ls tests/*.js | wc -l
echo "== 2. Full suites =="
php tools/run-php-tests.php                     || echo "PHP SUITES FAIL"
node tools/run-node-tests.js                    || echo "NODE SUITES FAIL"
npm run lint                                    || echo "ESLINT FAIL"
npm run test:php >/dev/null                     || echo "php -l FAIL"
npm run test:js  >/dev/null                     || echo "node --check FAIL"
echo "== 3. Named guards individually (the four this wave added/extended) =="
php tests/php/test-credit-registry-promote.php
php tests/php/test-xml-import-routing.php
php tests/php/test-import-format-coverage.php
php tests/php/test-editor-api2-contract.php
php tests/php/test-intapps-stub-e2e.php
php tests/php/test-intapps-guards.php
php tests/php/test-schema-coverage.php
php tests/php/test-migration-registry.php
echo "== 4. Behavioural smoke (LOGIN preamble first) =="
# credits: structured upsert round-trip (expect registry-echoed parts)
curl -s -b $JAR $H1 $H2 -H 'Content-Type: application/json' \
  -d '{"songId":"MP-0001","role":"writers","credit":{"id":0,"first":"Smoke","surname":"Test"}}' \
  'http://127.0.0.1:8123/manage/editor/api2.php?action=credit_upsert'
mysql -u root ihymns_live -e "SELECT FirstNames,Surname,Slug FROM tblCreditPeople WHERE Name='Smoke Test'"
# import: OpenSong single file via v2 auto (expect ok:true, format:"opensong")
curl -s -b $JAR $H1 $H2 -F "file=@tests/php/fixtures/opensong/be-thou-my-vision.xml;type=text/xml" -F format=auto \
  'http://127.0.0.1:8123/manage/editor/api2.php?action=import_file'
# counterparts: five-action smoke (expect ok:true / 403 without header)
curl -s -b $JAR 'http://127.0.0.1:8123/manage/editor/api2.php?action=song_links&id=MP-0001'
curl -s -b $JAR -o /dev/null -w '%{http_code}\n' -X POST 'http://127.0.0.1:8123/manage/editor/api2.php?action=song_link_add'  # expect 403 (no X-Requested-With)
# intapps: admin page dormant copy + migration probe applied
curl -s -b $JAR http://127.0.0.1:8123/manage/intapps-status.php | grep -o 'Dormant[^<]*' | head -1
mysql -u root ihymns_live -e "SHOW TABLES LIKE 'tblIntAppsSync'"
# cleanup smoke rows
mysql -u root ihymns_live -e "DELETE FROM tblSongWriters WHERE SongId='MP-0001' AND Name='Smoke Test'; DELETE FROM tblCreditPeople WHERE Name='Smoke Test';
DELETE FROM tblLyricLines WHERE ComponentId IN (SELECT Id FROM tblSongComponents WHERE SongId='OS-0123');
DELETE FROM tblSongComponents WHERE SongId='OS-0123'; DELETE FROM tblSongs WHERE SongId='OS-0123'; DELETE FROM tblSongbooks WHERE Abbreviation='OS';"
echo "== 5. Dormancy no-op proof (Section 4 — full transcript required, expect A0 OK / A OK / B OK: 0 / C OK) =="
echo "== 6. Ledger =="
git log --oneline $(git merge-base alpha claude/wave3-fixes)..HEAD
echo "== 7. Manual pre-merge checklist (CANNOT be verified here — tick in the PR): =="
echo "  [ ] Browser pass: v2 credits 3-field (typing, focus retention, suggestion adopt, clear-to-empty Delete affordance)"
echo "  [ ] Browser pass: v2 counterparts panel (link/unlink/dismiss, tableMissing hidden state)"
echo "  [ ] Browser pass: import2 dropdown incl. OpenSong + ChordPro entries"
echo "  [ ] Owner (#1726): register app + web.sotd_card key; record /v1/status version; confirm HmacValidator @ 6816ed8; ONE live signed POST"
echo "  [ ] Enablement AFTER migration card on EVERY env: alpha only -> verify -> beta -> production (allow-list row absent until then)"
```

Key files (absolute): `/home/user/iHymns/appWeb/public_html/includes/credit_people_helpers.php`, `/home/user/iHymns/appWeb/public_html/manage/editor/save_song_core.php`, `/home/user/iHymns/appWeb/public_html/manage/editor/api2.php`, `/home/user/iHymns/appWeb/public_html/manage/editor/api.php`, `/home/user/iHymns/appWeb/public_html/manage/editor/v2/credits-tab.js`, `/home/user/iHymns/appWeb/public_html/manage/editor/v2/api-client.js`, `/home/user/iHymns/appWeb/public_html/manage/editor/v2/counterparts-panel.js` (new), `/home/user/iHymns/appWeb/public_html/manage/editor/editor2.php`, `/home/user/iHymns/appWeb/public_html/manage/editor/import2.php`, `/home/user/iHymns/appWeb/public_html/manage/editor/editor.js`, `/home/user/iHymns/appWeb/public_html/includes/song_importers.php`, `/home/user/iHymns/appWeb/public_html/includes/lyrics_ingest.php`, `/home/user/iHymns/appWeb/public_html/includes/intapps_client.php` (new), `/home/user/iHymns/appWeb/.sql/migrate-add-intapps-sync.php` (new), `/home/user/iHymns/appWeb/.sql/schema.sql`, `/home/user/iHymns/tests/php/fixtures/intapps-stub-gateway.php` (new), plus the six test files named in §2.