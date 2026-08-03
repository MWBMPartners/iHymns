# #1741 follow-ups (small) — build spec: #1749 resolver UNION · #1751 ingest mirror · #1754 P4a-3 trio

**Status:** ready to implement · **Branch:** `claude/wave3-fixes` · **Epic:** #1741
**Scope:** three small follow-ups, ONE PR, one commit per issue (CLAUDE.md "one PR per piece of work").
**Written:** 2026-08-03, against live code on this branch — every file:line below was read and verified this session.

This is a decision-bearing contract: every choice is made here. The implementer executes; they do
not re-decide. Where an issue's claim was stale, the correction is stated inline and the spec
follows the LIVE code, not the issue.

---

## 0. Verified ground truth + stale-claim corrections

| Claim (from issue / brief) | Verdict | Live evidence |
|---|---|---|
| #1749 dual-write half already done (P5d) | **TRUE** | `songExternalIdMirrorIsrc()` exists (`includes/song_external_ids.php:180`), called from `manage/editor/api2.php:1134` (snapshot restore) and `:1629` (`metadata_field_update`) |
| #1749 resolver reads only `tblSongs.Isrc` | **TRUE** | `_ihymns_resolve_songs($db,'Isrc',$canonical)` at `includes/identifier_resolve.php:341`; query at `:194-200` touches `tblSongs.Isrc` only |
| `/isrc/` page already renders a multi-song list | **TRUE — confirmed** | `includes/pages/identifier.php:189-247`: the `iswc/ccli/isrc` branch groups by songbook and renders the full list. **No fragment/markup change is needed for #1749** — only the resolver's data source widens |
| #1751: two un-mirrored write sites at ~:611-621 and ~:679 | **TRUE** | `includes/lyrics_ingest.php:612/:618` (INSERT in `lyricsIngest_createSong()`), `:679` (COALESCE UPDATE in `lyricsIngest_storeExternalIds()`) |
| #1751: "wire both sites inside their existing transactions" | **HALF-STALE** | Site 1 IS inside a transaction (`begin_transaction` `:564` → `commit` `:653`). Site 2 has **NO existing transaction**: `api.php:1732` calls `lyricsIngest_storeExternalIds()` *after* `lyricsIngest_writeToDb()` has already committed. See §2.3 for the taken default |
| #1751: neither ingest site canonicalises the ISRC | **TRUE (extra finding)** | `:561` and `:674` are bare `trim()`; `ihymns_canonical_isrc()` (`includes/identifier_normalize.php:223`) is never required by `lyrics_ingest.php` |
| #1754(1): index.php JSON-LD branch is exact-slug only | **TRUE** | `index.php:522-583`: `SELECT … FROM tblMusicians WHERE Slug = ?` at `:529` with no ladder. Note `$canonicalUrl` at `:525` is also computed from the RAW slug — must be recomputed after resolution (§3.1) |
| #1754(2): aliases deliberately not a ladder rung | **TRUE** | `musician_helpers.php:1010-1011` ("Aliases are deliberately NOT a rung here — decision D-4"); registry-branch discography is `[$personName]` only (`includes/pages/musician.php:308-310`) |
| #1754(3): `SongData::getSongsByCreditName()` is caller-less | **TRUE** | Only the declaration (`SongData.php:2293`) and a doc-comment mention (`musician.php:302`) exist; its own doc-block still names the deleted `includes/pages/writer.php` as "the caller" — stale, fixed by §3.3 |
| Both existing guards pass on this branch | **TRUE** | `test-song-external-id-mirror.php` and `test-musician-slug-resolve.php` both PASS (run this session) |

---

## 1. #1749 — `/isrc/` resolves from `tblSongs.Isrc` OR the `tblSongExternalIds` store

### 1.1 The change

An ISRC that exists ONLY as a store row (`IdType='isrc'` in `tblSongExternalIds` — e.g. a
curator-entered second-recording row, `Source='manual'`, `SourceRef IS NULL`) currently never
resolves at `/isrc/<code>`. Widen the song lookup to the union of both sources.

> **Update 2026-08-03 (owner escalation):** option (3) — full read-path unification, making
> `tblSongExternalIds` the single authority and `tblSongs.Isrc` a synced denorm — is **no longer
> deferred**. The owner asked for it directly ("do full unifications now also"); it landed in
> `8f2f3a4f`. See **`.claude/catalogue-1741-1749-unification-plan.md`** for that design and
> `test-song-external-ids-reconcile.php` for its behavioural guard. The union arm below (Phase-1,
> `e65b92a4`) is the foundation it builds on.

**Mechanism decision: an `OR … IN (subquery)` on the single existing `tblSongs` scan, not a SQL
`UNION`.** One pass over `tblSongs` dedupes SongIds by construction, keeps the SELECT columns /
LEFT JOIN / ORDER BY byte-identical (so the multi-match song-list rendering in `identifier.php`
is untouched), and keeps the visibility predicate (`songVisibleSql`, #1694) applied once to every
row regardless of which source matched it.

### 1.2 Exact edits — `appWeb/public_html/includes/identifier_resolve.php`

**(a) New PURE SQL builder** (insert immediately above `_ihymns_resolve_songs()`, ~line 158). Pure
= no `\mysqli`, no I/O — this is the testable seam (the `musicianResolveLegacySlug()` /
`test-musician-slug-resolve.php` precedent: "a property a test could simply have CALLED").
Two-register annotations + `#1749` throughout, per project standard.

```php
/**
 * ELI5: build the songs-by-identifier SELECT text — optionally widened so a
 * code held only in the tblSongExternalIds store still finds its song.
 * DETAILED: pure so tests/php/test-identifier-resolve-union.php can call it
 * directly (rule #34). $column is allow-listed by the caller; $visibleSql is
 * songVisibleSql()'s output (or '1=1' in tests); $withStore=true emits the
 * #1749 union arm. The OR pair is PARENTHESISED so the visibility predicate
 * applies to BOTH arms (the SongData.php:2312 #1694 lesson). 1 placeholder
 * without the store, 3 with (value, IdType, value) — the caller binds.
 * @see #1749
 */
function _ihymns_resolve_songs_sql(string $column, string $visibleSql, bool $withStore): string
{
    $extraGuard = ($column === 'Ccli') ? " AND s.{$column} <> ''" : '';
    $match = $withStore
        ? "(s.{$column} = ?{$extraGuard}
            OR s.SongId IN (SELECT e.SongId FROM tblSongExternalIds e
                             WHERE e.IdType = ? AND e.IdValue = ?))"
        : "s.{$column} = ?{$extraGuard}";
    return "SELECT s.SongId, s.Number, s.Title, s.SongbookAbbr, sb.Name AS SongbookName, s.Language
              FROM tblSongs s
              LEFT JOIN tblSongbooks sb ON sb.Abbreviation = s.SongbookAbbr
             WHERE {$match} AND {$visibleSql}
             ORDER BY s.SongbookAbbr ASC, s.Number ASC, s.Title ASC";
}
```

**(b) Rework `_ihymns_resolve_songs()` (:181-210)** to consume the builder:

```php
function _ihymns_resolve_songs(\mysqli $db, string $column, string $canonical, ?string $storeIdType = null): array
{
    if (!in_array($column, ['Iswc', 'Ccli', 'Isrc'], true)) { return []; }
    try {
        /* #1749 — existence-gated (rules #5/#9: mysqli STRICT throws on a
           missing table; the probe reuses this file's own helper, never a
           second INFORMATION_SCHEMA idiom). Un-migrated install ⇒ exactly
           the pre-#1749 single-column query. */
        $useStore = $storeIdType !== null && _ihymns_table_exists($db, 'tblSongExternalIds');
        $stmt = $db->prepare(_ihymns_resolve_songs_sql($column, songVisibleSql($db, 's'), $useStore));
        if ($useStore) { $stmt->bind_param('sss', $canonical, $storeIdType, $canonical); }
        else           { $stmt->bind_param('s',   $canonical); }
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    } catch (\Throwable $e) {
        error_log('[identifier_resolve] tblSongs lookup failed (' . $column . '): ' . $e->getMessage());
        return [];
    }
}
```

Preserve the existing `:187-193` Ccli-guard comment (move it onto the builder). Everything —
column names, `'isrc'` at the call site — remains PHP-source constants; the only bound values are
`$canonical` and `$storeIdType` (rule #5).

**(c) Wire the `isrc` case (:340-341):**

```php
case 'isrc':
    /* #1749 — union with the tblSongExternalIds store, so an ISRC held
       only as a store row (manual second recording) still resolves. */
    $result['songs'] = _ihymns_resolve_songs($db, 'Isrc', $canonical, 'isrc');
    break;
```

`iswc`/`ccli` call sites are untouched (no companion store column — passing `null` implicitly).
**No change to `includes/pages/identifier.php`** (§0: the multi-song list already renders).

**Known, accepted limitation (document in the builder doc-block):** the store lookup is an exact
match on the CANONICAL code, same as the `tblSongs.Isrc` arm already is — a legacy raw-separator
value copied verbatim by the #1747 backfill won't match a canonical query, exactly as it doesn't
today via the column arm. Not a regression; not this issue's job to fix.

### 1.3 CI guard — NEW `tests/php/test-identifier-resolve-union.php`

`tools/run-php-tests.php` globs `tests/php/*.php`, so the file is picked up with no registration
(that IS the tree-derived mechanism for "which tests run"). Model the file on
`test-song-external-id-mirror.php`: pure-core functions → in-memory mutation self-tests FIRST →
real assertions. No DB.

1. **Direct-call assertions** (the point of the pure builder; `identifier_resolve.php` verified
   this session to `require` standalone):
   - `_ihymns_resolve_songs_sql('Isrc','1=1',false)`: contains NO `tblSongExternalIds`; exactly
     **1** `?` (count via `substr_count`).
   - `_ihymns_resolve_songs_sql('Isrc','1=1',true)`: contains `tblSongExternalIds`; exactly
     **3** `?`; the union arm is inside the parenthesised match group **before** ` AND 1=1`
     (assert `strpos($sql,'OR s.SongId IN') < strpos($sql,'AND 1=1')` and that the char at the
     match-group open is `(` — the #1694 parenthesisation property).
   - `_ihymns_resolve_songs_sql('Ccli','1=1',false)`: still carries `<> ''`.
2. **Source-scan assertions, comment-stripped first** (rule #34: a comment must never satisfy a
   code assertion). Strip with `token_get_all()`, dropping `T_COMMENT`/`T_DOC_COMMENT`, before
   scanning; then:
   - the sliced `_ihymns_resolve_songs` function (reuse the `seimSliceFunction` regex shape —
     copy it locally, tests are standalone) references `_ihymns_table_exists` with
     `'tblSongExternalIds'` (the existence gate is in the RUNTIME path, not just the builder);
   - the sliced `case 'isrc':` region of `ihymns_resolve_identifier()` passes a 4th argument
     `'isrc'` (bounded window ≤300 chars — the #1676/#1680 lesson: generous bounded windows,
     never "to end of line").
3. **Mutation self-tests, in memory:** fixtures proving the slicer finds/doesn't-bleed
   (fails-high/fails-low pair), and one fixture where `tblSongExternalIds` appears ONLY in a
   comment — the stripped scan must NOT count it (this is the assertion that proves the
   comment-stripping is load-bearing).
4. **Manual mutation proof before commit** (record in the commit body): (a) delete the
   `$useStore` gate → test red; (b) change `'isrc'` 4th arg to nothing → red; (c) restore → green.

---

## 2. #1751 — wire `lyrics_ingest.php`'s ISRC writes through the mirror

Decision already taken (the issue's own recommendation, adopted here): optional `$source`
parameter, default `'ihymns-mirror'`; ingest passes `'ihymns-ingest'`; `SourceRef` stays
`'tblSongs.Isrc'` — **`SourceRef` is the ownership key and is provenance-independent; `Source` is
provenance only** (this sentence goes in the doc-block).

### 2.1 Edit — `appWeb/public_html/includes/song_external_ids.php`

Signature at `:180`:

```php
function songExternalIdMirrorIsrc(\mysqli $db, string $songId, ?string $canonicalIsrc, string $source = 'ihymns-mirror'): void
```

Delete the local `$source = 'ihymns-mirror';` at `:206` (the parameter replaces it; the
`bind_param('ssssss', …)` block is otherwise unchanged). Update the doc-block: `@param string
$source` (provenance label only — NEVER part of the DELETE's ownership predicate, which stays
`SongId + IdType + SourceRef`), `@see #1751`. The two `api2.php` call sites pass no 4th argument
and keep `'ihymns-mirror'` — no edit there.

### 2.2 Edit — site 1: `lyricsIngest_createSong()` (`includes/lyrics_ingest.php:553-659`)

**(a) Canonicalise at `:561`** (replace `$isrc = trim((string)($payload['isrc'] ?? '')) ?: null;`):

```php
/* #1751 — ONE fold (rule #22): the same ihymns_canonical_isrc() the editor
   funnel uses, so tblSongs.Isrc and the store's IdValue can never diverge
   by formatting. '' folds to null (nullable column, matches prior shape). */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'identifier_normalize.php';
$isrc = ihymns_canonical_isrc((string)($payload['isrc'] ?? '')) ?: null;
```

(`ihymns_canonical_isrc()` cleans, never rejects — `identifier_normalize.php:213-216` — so no
previously-accepted payload starts failing. `$upc` at `:562` is untouched.)

**(b) Mirror after the INSERT** — insert after `$ins->close();` (`:624`), still INSIDE the open
transaction:

```php
/* #1751 — dual-write mirror, inside the SAME transaction as the tblSongs
   INSERT: a throw here propagates to the catch below and rolls back the
   whole create (the mirror is deliberately UNSWALLOWED — a half-mirrored
   pair is worse than the ingest failing outright; see song_external_ids.php).
   'ihymns-ingest' = provenance; ownership stays SourceRef='tblSongs.Isrc'. */
if ($isrc !== null) {
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'song_external_ids.php';
    songExternalIdMirrorIsrc($db, $songId, $isrc, 'ihymns-ingest');
}
```

### 2.3 Edit — site 2: `lyricsIngest_storeExternalIds()` (`:669-683`)

**Correction to the brief:** this function runs with **no transaction open** (`api.php:1732`,
after `lyricsIngest_writeToDb()`'s own commit). **Taken default: do NOT introduce one.** A
`begin_transaction()` inside a helper silently commits any future caller's outer transaction (the
implicit-commit class of bug); the function is documented add-if-absent idempotent, and the
re-runnable #1747 backfill card self-heals the crash window between the UPDATE and the mirror.
State this in the site comment.

**The read-back subtlety (load-bearing — do not "simplify" it away):** the `:679` UPDATE is a
COALESCE **fill-if-blank**. When the column already held a curator's value, the payload value did
NOT land — mirroring the payload value would write a store row whose `IdValue` ≠ `tblSongs.Isrc`,
breaking the mirror invariant (store row `SourceRef='tblSongs.Isrc'` must equal the column
byte-for-byte). So: mirror the **read-back column value**, whatever it now is. This also
self-heals a never-mirrored pre-existing column value, and matches the backfill's copy-verbatim
semantics for legacy raw values.

Replace the `:673-683` block with:

```php
/* ISRC / UPC → fill blank columns only (don't clobber a curator's value). */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'identifier_normalize.php';
$isrc = ihymns_canonical_isrc((string)($payload['isrc'] ?? ''));   /* #1751 — one fold */
$upc  = trim((string)($payload['upc'] ?? ''));
if ($isrc !== '' || $upc !== '') {
    $i = $isrc !== '' ? $isrc : null;
    $u = $upc !== '' ? $upc : null;
    $st = $db->prepare('UPDATE tblSongs SET Isrc = COALESCE(NULLIF(Isrc, ""), ?), Upc = COALESCE(NULLIF(Upc, ""), ?) WHERE SongId = ?');
    $st->bind_param('sss', $i, $u, $songId);
    $st->execute();
    $st->close();

    /* #1751 — mirror the READ-BACK value, not the payload: the COALESCE
       fill may have kept an existing curator value, and the store's copy
       must equal the COLUMN byte-for-byte (the mirror's ownership
       invariant). No transaction here by design — see this spec §2.3 /
       the re-runnable backfill card is the crash-window catch-all. */
    if ($isrc !== '') {
        $rb = $db->prepare('SELECT Isrc FROM tblSongs WHERE SongId = ? LIMIT 1');
        $rb->bind_param('s', $songId);
        $rb->execute();
        $rbRow = $rb->get_result()->fetch_row();
        $rb->close();
        $storedIsrc = $rbRow !== null ? trim((string)($rbRow[0] ?? '')) : '';
        if ($storedIsrc !== '') {
            require_once __DIR__ . DIRECTORY_SEPARATOR . 'song_external_ids.php';
            songExternalIdMirrorIsrc($db, $songId, $storedIsrc, 'ihymns-ingest');
        }
    }
}
```

(Gated on `$isrc !== ''` so a UPC-only enrich pays no extra query. Annotate two-register per
standard; the code above shows the detailed register — add the ELI5 lines when writing.)

### 2.4 CI guard — edit `tests/php/test-song-external-id-mirror.php`

1. **Remove the exemption** (`:341-351`, the `'includes/lyrics_ingest.php' => …` entry). Keep
   `$exempt = [];` with a short comment: *"empty — its last entry, lyrics_ingest.php,
   self-cleaned when #1751 wired the ingest sites to the mirror"* (mirrors the
   `test-fragment-inline-scripts.php` empty-allowlist convention). The derived
   `seimFindIsrcWriteSites()` sweep (`:362-372`) then REQUIRES `lyrics_ingest.php` to reference
   the mirror — which it now does. The `:356-360` exemption-format loop stays (iterates nothing).
2. **Two new literal-agreement assertions** (rule #35 — mechanism, not comment), placed after the
   existing block 5, with matching in-memory mutation fixtures added to the self-test section:
   - the `songExternalIdMirrorIsrc` function slice matches
     `/function\s+songExternalIdMirrorIsrc\s*\([^)]{0,220}\$source\s*=\s*'ihymns-mirror'/` —
     the default that keeps the two `api2.php` call sites emitting `'ihymns-mirror'` unchanged;
   - `lyrics_ingest.php` contains **≥ 2** matches of
     `/songExternalIdMirrorIsrc\s*\([^;]{0,220}'ihymns-ingest'/` (`preg_match_all`) — both ingest
     sites pass the ingest provenance.
3. **Manual mutation proof before commit:** (a) comment out the site-1 call → red (derived sweep
   still green on file-presence, but assertion 2's count drops to 1 → red — this is why the count
   is `>= 2`, not `>= 1`); (b) change site 2's `'ihymns-ingest'` to `'ihymns-mirror'` → red;
   (c) re-add the old exemption entry with the calls present → still green (exemptions are
   allowed to be stale-generous; the sweep is the floor) — then remove it again; (d) restore all
   → green.

---

## 3. #1754 — P4a-3 follow-up trio

### 3.1 Item 1 — index.php `/musician/` JSON-LD branch through the ladder

**Edit `appWeb/public_html/index.php:522-528`.** Today a crawler hitting a name-slug or alias URL
(`/musician/fanny-crosby` when the registry slug is `frances-jane-van-alstyne`, or any
`song.php`-emitted credit-name slug) gets NO JSON-LD and a `<link rel=canonical>` pointing at the
non-canonical slug. Route the slug through the ONE ladder and recompute `$canonicalUrl` after:

```php
elseif (preg_match('#^/musician/([a-z0-9\-]+)$#', $requestPath, $matches)) {
    $pageType = 'other';
    $personSlug = $matches[1];
    /* #1754 — resolve name-slug / legacy-slug input through the ONE ladder
       (rule #22) BEFORE the exact lookup, so crawler hits on non-canonical
       slugs get JSON-LD too. Fail-open: a null answer leaves the slug as
       arrived and the branch behaves exactly as pre-#1754. No rawurldecode
       needed — this route's charset is [a-z0-9-] (unlike /writer/'s, :484).
       NOTE $canonicalUrl is recomputed AFTER resolution so <link rel=canonical>
       / og:url agree with the fragment's data-musician-canonical marker —
       both now derive from the same resolver (rule #35). */
    try {
        if (!function_exists('musicianResolveLegacySlugDb')) {
            require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'musician_helpers.php';
        }
        $musicianResolved = musicianResolveLegacySlugDb(getDbMysqli(), $personSlug);
        if ($musicianResolved !== null) { $personSlug = $musicianResolved; }
    } catch (\Throwable $_e) { /* getDbMysqli() outage — serve the shell, never a 500 */ }
    $canonicalUrl = getCanonicalUrl('/musician/' . rawurlencode($personSlug));
    try {
        $personDb = getDbMysqli();
        …(rest of the branch from :528 unchanged)…
```

No 301 here (deliberate — leg C of the P4a-3 design: `/musician/` soft-canonicalises via the
fragment's `data-musician-canonical` + `history.replaceState`; only `/writer/` hard-301s, `:484`).
The driver itself never throws (`musician_helpers.php:1103-1105`); the try/catch guards
`getDbMysqli()` only.

### 3.2 Item 2 — alias rung + discography widening: **IMPLEMENT, both halves, ONE commit**

**Decision: implement now, not defer.** Rationale: the pure-ladder seam (`musicianResolveLegacySlug()`)
and the already-shipped, schema-tolerant alias loaders make both halves ~40 lines with an existing
spy-test harness to extend — the pairing hazard the issue names ("a redirected alias URL appears
to lose songs") is eliminated *by construction* when both land in one commit, and each half is
independently assertable. Deferring would buy nothing but a fourth visit to the same three files.

**Non-blocking heads-up for the owner (not a decision request):** with half (b), every registry
page that HAS aliases shows a larger discography everywhere (songs credited under pseudonyms/
misspellings now attach) — that is the feature working as designed; trivially reversible by
reverting the one `$creditNames` merge if the owner dislikes it in practice.

**(a) Pure ladder — `musician_helpers.php:1027-1039`.** Add an optional 4th rung:

```php
function musicianResolveLegacySlug(callable $bySlug, callable $byName, string $slug, ?callable $byAliasName = null): ?string
{
    $plan = musicianLegacySlugPlan($slug);
    foreach ($plan['slugs'] as $cand) {
        $hit = $bySlug($cand);
        if (is_string($hit) && $hit !== '') { return $hit; }
    }
    if ($plan['names'] !== []) {
        $hit = $byName($plan['names']);
        if (is_string($hit) && $hit !== '') { return $hit; }
        /* #1754 — rung 4: tblMusicianAliases name match, tried ONLY after
           every registry rung misses (an alias must never outrank the
           registry's own slug/name). Null = rung absent (un-migrated
           install / caller opt-out) — ladder behaves exactly as pre-#1754. */
        if ($byAliasName !== null) {
            $hit = $byAliasName($plan['names']);
            if (is_string($hit) && $hit !== '') { return $hit; }
        }
    }
    return null;
}
```

Rewrite the doc-block's ladder list: rung 4 replaces the `:1010-1011` "deliberately NOT a rung —
D-4" bullet; cite #1754 and the pairing constraint ("lands only together with the discography
widening in musician.php — see that file's `$creditNames`"). Same `list<string>`-in-one-call
contract as `$byName`.

**Wait — placement subtlety:** put the rung-4 call INSIDE the `if ($plan['names'] !== [])` block
as shown (aliases key off the same name-candidate list; an empty plan has nothing to ask).

**(b) DB driver — `musicianResolveLegacySlugDb()` (`:1072-1106`).** Build the third closure only
when `musicianAliasesTableExists($db)` (`:1461`, already cached + schema-tolerant), else `null`:

```php
$byAlias = musicianAliasesTableExists($db)
    ? static function (array $names) use ($db): ?string {
        /* Placeholder string from count() — rule #5's sanctioned shape. */
        $ph = implode(',', array_fill(0, count($names), '?'));
        $st = $db->prepare(
            "SELECT m.Slug FROM tblMusicianAliases a
               JOIN tblMusicians m ON m.Id = a.MusicianId
              WHERE a.Name IN ($ph) AND m.Slug IS NOT NULL AND m.Slug <> ''
              ORDER BY m.Id ASC, a.Id ASC LIMIT 1"
        );
        $st->bind_param(str_repeat('s', count($names)), ...$names);
        $st->execute();
        $row = $st->get_result()->fetch_row();
        $st->close();
        return $row !== null ? (string)$row[0] : null;
    }
    : null;
return musicianResolveLegacySlug(/* existing $bySlug */, /* existing $byName */, $slug, $byAlias);
```

Column names verified against `replaceMusicianAliases()`/`loadMusicianAliases()`
(`:1534/:1541-1544/:1575`): `tblMusicianAliases(MusicianId, Name, …)`. Case-fold is free (CI
collation, same note as `musician.php:296-303`). Deterministic tie-break `ORDER BY m.Id, a.Id`
mirrors the name rung's `ORDER BY Id ASC LIMIT 1` posture. Everything inside the existing
`:1074` try/catch → fail-open preserved. This automatically upgrades ALL three ladder consumers
(index.php `/writer/` 301 at `:488`, index.php `/musician/` from §3.1, `musician.php:98`).

**(c) Discography widening — `includes/pages/musician.php:308-310`.** `$personAliases` is loaded
at `:162-172`, safely before this point:

```php
/* #1754 — the registry branch widens to canonical Name + EVERY alias name
   (all types, including search-hint/misspelling: they are match keys
   against how credits were actually typed, not display strings — taken
   default). Pairs with the ladder's rung 4: an alias URL now resolves to
   this registry page, so this list must cover at least what the old
   name-fallback listed for that alias, or the redirect "loses songs". */
$creditNames = $person !== null
    ? array_values(array_unique(array_merge(
          [$personName],
          array_map(static fn(array $a): string => (string)$a['Name'], $personAliases)
      )))
    : musicianLegacySlugPlan($personSlug)['names'];
```

Also update the now-stale `:296-303` comment sentence "a registry hit binds a one-element list".
The downstream `:315-339` loop already binds an N-element list (the fallback branch always did) —
no other change. `$totalSongs` dedupes by SongId (`:334`) so overlap across names never
double-counts.

**(d) Test extension — `tests/php/test-musician-slug-resolve.php`.** Add section 3 (after `:223`):
- `aliasSpy(array $rows, array &$calls)` mirroring `nameSpy()` (`:135-144`), plus a shared
  `$sequence` log appended to by both spies so ORDER (not just presence) is asserted.
- Assertions: (1) rung-4 hit resolves when rungs 1-3 all miss, called ONCE with the FULL deduped
  name list; (2) sequence shows `$byName` consulted strictly before `$byAliasName`; (3) a
  `$byName` HIT never consults `$byAliasName`; (4) omitting the 4th arg (3-arg call) still
  resolves/misses exactly as the existing r1-r6 assertions prove (add one explicit
  `musicianResolveLegacySlug($s,$n,'x')` call to pin the back-compat signature); (5) an
  empty-string `''` alias answer is a MISS → overall `null`.
- New source-scan sub-section, **comment-stripped via `token_get_all()`** (rule #34): slice
  index.php's `/musician/` elseif block (bounded: from `preg_match('#^/musician/` to the next
  `elseif (preg_match`) and `musician.php`'s pre-lookup region (file head through the
  `WHERE Slug = ?` prepare) and assert each references `musicianResolveLegacySlugDb`. **Why not a
  tree-derived "every `tblMusicians WHERE Slug = ?` caller" sweep:** verified this session that
  admin/API sites (`api2.php`, `migration-registry.php`, `SongData.php` …) also do exact-slug
  lookups where ladder resolution would be WRONG (admin lookups must be exact) — a sweep would
  flag correct code, the rule-#34 "guard so blunt it gets weakened" anti-pattern. The two public
  head-of-request entry points are structural, not a growing list; scoping to them is the
  narrow-enough guard. Include slicer mutation fixtures (fails-high/fails-low + the
  comment-only-mention fixture).
- **Manual mutation proof:** remove the §3.1 index.php call → red; reorder rung 4 before rung 3
  in the pure fn → red; restore → green.

### 3.3 Item 3 — `SongData::getSongsByCreditName()`: **KEEP + doc-note** (taken default)

No code path change. Edit the doc-block at `SongData.php:2266-2292` only:
- Correct the stale "the caller (`includes/pages/writer.php`)" sentence (that file was deleted in
  P4a-3; the function is caller-less as of #1754).
- Add: *"Caller-less since #1741 P4a-3 retired writer.php. RETAINED DELIBERATELY (#1754) as the
  documented scoped-read exemplar of rule #17 — credit-name → bounded id set → per-record
  `getSongById()` hydration, never a corpus materialisation — and as the ready-made read for a
  future credits API action. Do not resurrect a whole-corpus scan instead of reusing this; do not
  delete without an issue."*
- No CI guard (a "must have callers" assertion would fail on correct code — rule #34's
  narrow-guard clause — and dead-code tracking is what the issue itself is for).

---

## 4. Combined CI-guard summary (one strategy, three issues)

| Issue | Guard | Kind |
|---|---|---|
| #1749 | NEW `tests/php/test-identifier-resolve-union.php` | direct pure-builder calls + comment-stripped source scan + in-memory mutation self-tests; auto-run via the runner's glob |
| #1751 | EXISTING `tests/php/test-song-external-id-mirror.php` | exemption removed → the already-tree-derived write-site sweep now REQUIRES the ingest calls; + 2 literal-agreement assertions with mutation fixtures |
| #1754 | EXISTING `tests/php/test-musician-slug-resolve.php` | rung-4 spy assertions (order, back-compat, miss semantics) + comment-stripped entry-point scan with slicer fixtures |

Shared rules honoured everywhere: lists derived from the tree or from direct invocation (never
hand-typed rosters); every regex proven able to fail via in-memory fixtures run on EVERY
invocation; comments stripped before any source scan so a comment can never satisfy an assertion;
cross-file literals (`'ihymns-mirror'`/`'ihymns-ingest'`, the `'isrc'` IdType) held in agreement
by parsed-and-compared assertions, not "keep in sync" comments.

## 5. Verification plan (run in this order; record results in the PR body)

1. **Syntax:** `php -l` on the 6 touched PHP source files + 3 test files; `node --check` not
   needed (no JS touched — `test-identifier-routes.js` is unaffected: no route/page names change).
2. **Suites:** `php tools/run-php-tests.php` (expect **102** files passing — 101 + the new union
   test) and `node tools/run-node-tests.js` (expect **49**, unchanged).
3. **Mutation proofs:** the manual break-it/watch-red/restore steps in §1.3-4, §2.4-3, §3.2(d) —
   note each in the relevant commit body.
4. **Live behavioural probes** (dev MySQL via `getDbMysqli()`; run from a scratch script in the
   scratchpad, never committed):
   - **#1749:** `begin_transaction()`; `INSERT` a store-only row (`SongId` = any real visible
     song, `IdType='isrc'`, `IdValue='ZZZTEST9900001'`, `Source='manual'`, `SourceRef=NULL`);
     call `ihymns_resolve_identifier($db,'isrc','ZZZTEST9900001')` → assert that song is in
     `['songs']`; also resolve one REAL column-held ISRC (`SELECT Isrc FROM tblSongs WHERE Isrc
     IS NOT NULL LIMIT 1`) → still resolves (no regression); `rollback()`.
   - **#1751:** `begin_transaction()`; pick a real SongId with blank Isrc; call
     `lyricsIngest_storeExternalIds($db,$songId,['isrc'=>'us-zzz-99-00001'])` → assert
     `tblSongs.Isrc = 'USZZZ9900001'` AND a store row `(IdType='isrc', IdValue='USZZZ9900001',
     Source='ihymns-ingest', SourceRef='tblSongs.Isrc')`; repeat on a SongId whose Isrc is
     already set → assert the store row's IdValue equals the COLUMN value, not the payload;
     `rollback()`. (The create-site path is exercised transactionally the same way only if a
     scratch songbook abbr exists — otherwise the mirror-call equivalence is covered by the
     store-site probe plus the guard's call-site assertions; say which was done.)
   - **#1754:** `SELECT a.Name, m.Slug FROM tblMusicianAliases a JOIN tblMusicians m ON m.Id=a.MusicianId LIMIT 1`;
     feed `strtolower(str_replace(' ','-',Name))` through `musicianResolveLegacySlugDb()` →
     asserts rung 4 resolves to `m.Slug` (skip-with-note if the dev DB has zero alias rows);
     then fetch `/api?page=person&slug=<that-alias-slug>` shape by including the fragment in a
     CLI harness OR simply assert the widened `$creditNames` query returns ⊇ the rows the alias
     name alone returns.
5. **Regression sweep:** `php tests/php/test-song-external-id-mirror.php`,
   `test-musician-slug-resolve.php`, `test-identifier-normalize.php`,
   `node tests/test-identifier-routes.js` individually green.

## 6. Owner-decision points

**None blocking.** Everything above is a taken, defensible default, called out inline where taken
(§2.3 no-new-transaction + read-back; §3.2 all-alias-types widening; §3.3 keep). The single
owner-VISIBLE (not owner-decision) item: §3.2's discography counts grow on alias-bearing registry
pages — flagged as a heads-up in the PR body, reversible by reverting one hunk.

## 7. Files touched

| File | Issues |
|---|---|
| `appWeb/public_html/includes/identifier_resolve.php` | #1749 |
| `tests/php/test-identifier-resolve-union.php` (new) | #1749 |
| `appWeb/public_html/includes/song_external_ids.php` | #1751 |
| `appWeb/public_html/includes/lyrics_ingest.php` | #1751 |
| `tests/php/test-song-external-id-mirror.php` | #1751 |
| `appWeb/public_html/index.php` | #1754(1) |
| `appWeb/public_html/includes/musician_helpers.php` | #1754(2) |
| `appWeb/public_html/includes/pages/musician.php` | #1754(2) |
| `appWeb/public_html/includes/SongData.php` (doc-only) | #1754(3) |
| `tests/php/test-musician-slug-resolve.php` | #1754 |

No schema change anywhere (preferred and achieved); no migration, no schema.sql edit, no new
endpoint, no fragment `<script>` (rule #30 untouched — `identifier.php` stays markup-only), no
new URL params emitted (rule #33 untouched). Standing tasks (issue comments with commit SHAs,
closing #1749/#1751/#1754, CHANGELOG, handoff) run after implementation per
`.claude/standing-tasks.md`.
