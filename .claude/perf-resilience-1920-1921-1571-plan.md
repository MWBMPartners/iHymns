# Perf & resilience pack — locked implementation spec (#1920 + #1921 + #1571 safe subset)

**Fable-5 design pass, 2026-08-21.** Wave 3 of `.claude/proposals-program-plan.md` (§ "Wave 3 —
perf & resilience pack", items 6–7). Branch at design time: `claude/ilyrics-identity-work-model`
@ `82d61242`. **No code changed by this pass.**

Three issues, one family (the app's heaviest cold-path reads), one design:

| Issue | Problem | Fix (locked) |
|---|---|---|
| #1920 | `/qr.php` round-trips the external CueRCode service on every server-cold hit — pure waste for an immutable-by-contract image | Additive, dormant `tblQrCache` (one-pass DDL, rule #19/#20) read through the ONE CueRCode client as `cuercodeGenerateCached()`; TTL + row-cap pruning; fail-soft everywhere; unkeyed installs byte-identical |
| #1921 | `songs_index` (the PWA slim index, a few hundred KB) is re-sent in full on every fetch — no ETag, no 304 | A **version-signal** ETag (two cheap aggregate reads — never a payload hash of a freshly materialised index) + `If-None-Match` → 304 that skips the 14.5k-row query entirely; PLUS the service-worker half, without which the PWA would never send a validator at all (the issue missed this — §1.2c) |
| #1571 (safe subset) | `songbook_export` at Mission-Praise scale: shares the `'bulk'` 60/min budget with the offline sync; the client builds the whole `.probundle` in one un-yielding click handler with no confirm and no progress | Own `'export'` rate bucket; an honest large-book confirm on every export surface; `onProgress` + cooperative yield in the ONE bulk-file builder. The **heavy re-architecture is a surfaced owner decision** (§8.1), not built here |

---

## 1. Verified current state (file:line; paths under `appWeb/public_html/` unless noted)

### 1.1 The QR path (#1920)

- `qr.php:55-59` rate-limits (`enforceReadRateLimitKeyed('qr', 240)`, fail-open), `:62-69`
  validates/clamps `data`/`size`/`format`/`ecc`, `:74-76` answers **503-no-body when unkeyed**
  (`cuercodeConfigured()`), `:78` calls `cuercodeGenerate($data, [...])`, `:79-81` maps null →
  503, `:83-88` streams bytes with `Cache-Control: public, max-age=31536000, immutable` (the
  issue's `qr.php:86` cite is exact). **No server-side cache anywhere** — every server-cold hit
  is a CueRCode round trip.
- `includes/cuercode_client.php` — the ONE client (rule #38): config/dormancy `:79-101`, SSRF
  resolve `:119-140`, `cuercodeGenerate()` `:156-251` with the option clamps **inlined** at
  `:173-184` (format/size/ecc/type/fg/bg), bounds constants `:58-68`
  (`CUERCODE_MAX_PAYLOAD_LEN` 1024, `CUERCODE_MAX_RESPONSE_BYTES` 2 MiB), null-on-any-failure,
  never throws, side-effect-free to require.
- **The issue misses a second consumer**: `includes/pdf_renderer.php:277-311`
  `_pdfInlineQrImage()` calls `cuercodeGenerate()` **directly** (`:306`, guarded by
  `function_exists('cuercodeGenerate')` at `:289`) — the server-PDF path deliberately never
  self-requests `/qr.php` over HTTP. A cache bolted into `qr.php` alone (the issue's letter)
  would leave every PDF render round-tripping CueRCode. The read-through therefore lives in the
  client (§3.1), and both call sites adopt it.
- Pruning precedent: `appWeb/.sql/cleanup.php` is the house cron janitor (header `:5-31`,
  Cron example `:28`), already deleting expired tokens + aged `tblLoginAttempts`/`tblActivityLog`
  rows (`:96-184`). Migration + registry house shape: `migrate-add-read-rate-limit.php`
  (existence-guarded CREATE, `@migration-adds` doctag, CLI/dashboard dual mode) mirrored
  byte-identically at `schema.sql:5145-5169`; ONE registry entry per migration in
  `manage/includes/migration-registry.php` (entry shape `:105-117`; new entries append at the
  END — key order IS apply order, tail comment on `'song-copyright-holders'`).
- CI guard today: `tests/test-qr-cuercode.js:83` asserts `/cuercodeGenerate\s*\(/` in `qr.php` —
  switching to `cuercodeGenerateCached(` fails that regex (no `(` immediately after the stem),
  which is both proof the guard can fail and a required guard update (§6.1).

### 1.2 The slim index (#1921)

- `api.php:1810-1816` — `case 'songs_index'`: rate limit (120/min) then
  `sendJson(['songs' => $songData->getSongsSlimIndex()])`. No validator headers of any kind.
  The issue's cites are **all verified exact**: fragment-ETag machinery covers `page=` only
  (`api.php:882-895` — xxh64 over page+query+body, 304 at `:890-893`); `getSongsSlimIndex()`
  (`includes/SongData.php:2286-2356`) applies **no language filter** and emits `language` per
  row for client-side filtering (`:2297`), so a plain shared ETag has no per-user axis. Nothing
  in the case reads the viewer (`getAuthenticatedUser`/`makeLanguageFilterPredicate` absent);
  content gating never touches this action (rule #28 enumerates `song_detail`/`song_data`/
  `random`/`songbook_export`; the slim index carries no gated fields).
- **What DOES vary the bytes** (the axes an ETag must key on):
  1. **Corpus content**: every emitted column lives on `tblSongs`
     (id/number/title/songbook/language/hasAudio/hasSheetMusic/publicId) or `tblSongbooks`
     (`songbookName` via LIVE JOIN, `:2300`; `IsOfficial` drives ordering, `:2313`). Both tables
     carry `UpdatedAt TIMESTAMP … ON UPDATE CURRENT_TIMESTAMP` (`schema.sql:312`, `:228`) — the
     existing signal the task said to find. Visibility flips (soft delete #1694, songbook disable
     #1765) are `UPDATE`s → they bump `UpdatedAt`; row deletion changes `COUNT(*)`. The one
     cascade that does NOT bump `tblSongs.UpdatedAt` (an `Abbreviation` rename via
     `fk_Songs_Songbook ON UPDATE CASCADE`, `schema.sql:365-367` — cascades skip
     `ON UPDATE CURRENT_TIMESTAMP`) bumps `tblSongbooks.UpdatedAt` instead → covered because the
     signal reads BOTH tables.
  2. **Schema state**: `_hasPublicIdColumn()` (`SongData.php:4696-4716`, memoized
     INFORMATION_SCHEMA) adds `publicId`; `_visible()` (`:382-391`) changes with the
     soft-delete/servable migrations.
  3. **Response envelope**: `sendJson()` (`api.php:19373-19392`) wraps v2 clients
     (`X-API-Version: 2` → `{ok:true,data:…}`, `includes/api_envelope.php:54-61,110-118`) — the
     SAME content is different bytes per contract version. `apiFetchJson` sends the header
     (`js/utils/api-client.js:287`).
  4. **Deploys**: the emit shape can change with code (e.g. #1343-B added `publicId`); the
     deploy-injected commit SHA (`includes/infoAppVer.php`,
     `$app["Application"]["Version"]["Repo"]["Commit"]["SHA"]["Short"]`, loaded by api.php at
     `:59`) is the mechanism that invalidates on deploy without a hand-bumped seed.
- **(c) The trap the issue misses — the PWA would never send `If-None-Match`.** The service
  worker intercepts `/api?action=songs_index` (`service-worker.js.php:832-835`) with
  `networkFirstWithCache()` (`:1173-1210`), which fetches with **`cache: 'no-store'`**
  (`:1190`, deliberate — the layered-cache trap documented there), bypassing the browser HTTP
  cache that would otherwise do conditional revalidation automatically. Server-only ETag =
  a **silent no-op for the primary consumer** (the rule #30/#33 failure class: everything looks
  alive, nothing revalidates). The SW must therefore do its own conditional fetch (§3.2c) —
  it already stores the previous 200 (headers included) in the `CACHE_VERSION` bucket (`:1194-1195`).
- Runtime consumers verified: the SW route above; SW best-effort precache
  (`service-worker.js.php:511-513`); `settings.js:1207-1214` (`apiFetch(config.dataUrl)`,
  `config.dataUrl = '/api?action=songs_index'` from `index.php:1906,1954`); native/live-host
  reads (`js/modules/service-broadcast.js:97`). 304-handling precedent to mirror:
  `song-media.php:296-327` (RFC 7232 §6 — If-None-Match takes precedence, `'*'` handled;
  `org-logo.php:116` same family).

### 1.3 `songbook_export` at scale (#1571)

- The issue's line numbers have **drifted** (filed 2026-07-26) but every substantive claim
  verifies:
  - Server: `api.php:1369-1457` — `case 'songbook_export'`:
    `enforceReadRateLimitKeyed('bulk', 60)` at `:1373` (the drifted `api.php:874-877` cite),
    shared with `bulk_songs` (`:2012`), `bulk_audio` (`:2132`) **and `songs`** (`:1761`) — so an
    export click during a PWA offline sync competes for the same 60/min and can 429.
    `SongData::getSongs($abbr)` (`SongData.php:2386-2574`, the drifted `1798-1955` cite) is the
    full hydration: main SELECT + **ten** bulk side-table maps (`:2527-2571` — writers,
    composers, arrangers, adaptors, translators, artists, components, tags, links, altTitles) →
    a multi-MB JSON response for MP's 3,517 songs.
  - Client: `manage/editor/propresenter-export.js` — stored (no-DEFLATE) ZIP writer
    `buildZip()` `:869-958` (the drifted `855-984` cite); `buildBulkFiles()` `:1026-1081`
    encodes every song in a `for` loop whose `await buildPresentation(...)` yields only to
    microtasks (no paint, no input); `exportAllAsZip` `:1084-1097` / `exportAllAsBundle`
    `:1114-1155` assemble everything in memory; `triggerDownload` `:964-975`. **No progress
    hook exists anywhere.** Nuance the issue overstates slightly: the finished ZIP itself is
    modest ("protobuf is already binary-compact … a few-hundred-KB catalogue", `:824-826`); the
    real spike is the multi-MB JSON parse + 3,517 retained hydrated song objects + per-file
    byte arrays — the fix (chunking) is the same either way, so this changes sizing, not shape.
  - Surfaces: public `js/modules/export-ui.js` — the ONE core `exportSongbookAs()` `:207-230`
    (fetch at `:208`), wired by `wireSongbookExportMenu()` `:242-259` (toast → export → toast;
    no confirm, no progress); editor `manage/editor/index.php:1773-1788` (PP7 bundle) and
    `:1867-1882` (text formats), both fetching the session-authed
    `manage/editor/api.php:156-186` sibling (admin-gated, not read-rate-limited — fine).
- Rate-bucket mechanics: `includes/read_rate_limit.php:146-230` — `$scope` is a free VARCHAR
  bucket label ("reserves room for new endpoint limits with NO further migration", `:133`), so
  the split is a one-word change. Fail-open + existence-gated (`:56-72`), STRICT-safe.
- Count sources for an honest pre-fetch confirm: the /songbooks tiles already emit
  `data-songbook-songs` (`includes/pages/songbooks.php:116`); the /songbook fragment emits only
  `data-songbook-abbr` (`includes/pages/songbook.php:166`) but has `count($songs)` free
  (`:107`); the editor holds `songData.songbooks[].songCount` (the field `paddingFor()` already
  reads, `propresenter-export.js:761`). `window.confirm()` is the established public-surface
  pattern (`card-layout.js:351`, `settings.js:513`, et al.).
- Docs pair: `api-docs.yaml:180-196` documents the read-throttle table;
  `:188` is the shared-bulk row this change splits. No CI mechanism currently ties that table
  to the code (the existing `tests/php/test-rate-limit-pairing.php` covers the OTHER limiter
  family, `checkRateLimit`/`recordRateLimitHit`) — §6.5 adds one.

### 1.4 Issue-claim corrections (recorded per the setlist-plan precedent)

- **#1920**: (a) "the cache check goes in qr.php" — wrong seam; `pdf_renderer.php:306` is a
  second direct consumer the issue missed, so the read-through goes in the ONE client (§3.1).
  (b) "columns for Format/Size/Ecc folded into the key" — the key folds the FULL normalised
  option map via one canonical JSON instead of three fixed key columns, because the client
  already supports options the endpoint doesn't yet expose (`fg_color`/`bg_color`,
  `cuercode_client.php:180-184`) and a fixed-column key would be the second migration rule #20
  forbids. Both deviations are letter-not-spirit; the issue's spirit (hash-keyed bytes cache,
  dormant, fail-open, registry+mirror+probe) is implemented exactly.
- **#1921**: all cites verified exact; two design under-shoots corrected: (a) "content-hash
  ETag over the emitted index payload" would materialise + hash the payload on EVERY request —
  legal under rule #17 (it is the slim index, not the corpus) but it forfeits the server-side
  win; the version-signal ETag (§3.2a) makes a 304 skip the 14.5k-row query too. (b) The SW
  `no-store` trap (§1.2c) — without the SW half the headline consumer never revalidates.
- **#1571**: substance fully verified; line numbers drifted as listed in §1.3; the ZIP-size
  claim is refined (the spike is JSON parse + retained objects, not the archive bytes).

---

## 2. Design principles (inherited, not re-argued)

- **Dormant-by-construction, byte-identical when off** (rule #28's discipline applied to perf):
  an un-migrated / unkeyed / signal-failed install takes exactly today's code path.
- **mysqli is STRICT — statements THROW**: every optional-table touch is existence-gated
  (memoized INFORMATION_SCHEMA) AND try/catch'd; a cache/ETag failure degrades to the full
  answer, never a 500 (the #1228 lesson, the `read_rate_limit.php` safety contract mirrored).
- **All SQL values bound** (rule #5); the only interpolations are hardcoded constants.
- **One-pass forward schema, VARCHAR/JSON not ENUM/columns for growable vocab** (rules #19/#20).
- **Status is the contract** (rule #35): the client branches on `304`, `429`, `503` — never on
  prose; cross-file agreements get a CI mechanism, not a comment.
- **One core per concern** (rule #22): one option canonicaliser, one cache key derivation, one
  bulk-file builder, one export core — extended, never forked.
- **Reads stay scoped** (rule #17): nothing here materialises the corpus; the ETag signal is two
  aggregate reads, and the 304 path materialises NOTHING.
- **Annotation to standard**: every new file/section ships with the ELI5 + detailed dual-register
  doc-blocks and official links (RFC 7232, MDN Cache/Fetch, PHP manual, the `#issue`).

---

## 3. Locked design

### 3.1 #1920 — `tblQrCache` read-through in the ONE CueRCode client

**3.1a Schema (one-pass, additive, dormant).** New `appWeb/.sql/migrate-add-qr-cache.php`
(the `migrate-add-read-rate-limit.php` shape: CLI/dashboard dual mode, existence-guarded CREATE,
`@migration-adds tblQrCache.CacheKey` doctag, no docroot-literal require — rule #41 does not
even arise, the script needs no shared include). DDL, **byte-identical** in the migration and
appended to `schema.sql` (after the current tail, with the house comment banner):

```sql
CREATE TABLE IF NOT EXISTS tblQrCache (
    CacheKey     CHAR(64)      NOT NULL COMMENT 'sha256 hex over the canonical payload+normalised-options JSON minted by cuercodeCacheKey() — the ONE key derivation (#1920)',
    Payload      VARCHAR(1024) NOT NULL COMMENT 'the encoded text/URL (bounded by CUERCODE_MAX_PAYLOAD_LEN); informational/debug — CacheKey is authoritative',
    ParamsJson   JSON          NOT NULL COMMENT 'the canonical normalised option map the key was derived from (format/size/ecc/type + optional colours today); a future option lands in the hash + here with NO schema change (rule #20)',
    Mime         VARCHAR(100)  NOT NULL COMMENT 'Content-Type CueRCode answered with (image/svg+xml, image/png)',
    Format       VARCHAR(10)   NOT NULL COMMENT 'svg | png today; VARCHAR not ENUM (rule #20)',
    Bytes        MEDIUMBLOB    NOT NULL COMMENT 'the QR image bytes exactly as CueRCode returned them; served verbatim',
    ByteLength   INT UNSIGNED  NOT NULL COMMENT 'strlen(Bytes), denormed so size accounting never reads blobs',
    CreatedAt    DATETIME      NOT NULL COMMENT 'UTC mint instant; DATETIME not TIMESTAMP (rule #20) so TTL pruning never re-reads through a session zone',
    LastAccessAt DATETIME      NULL DEFAULT NULL COMMENT 'DORMANT (#1920 one-pass, rule #20): reserved for a future LRU policy; v1 writes nothing here — TTL-on-CreatedAt is the shipped eviction',

    PRIMARY KEY (CacheKey),
    INDEX idx_CreatedAt (CreatedAt)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Server-side cache of CueRCode-generated QR images, keyed by payload+options hash (#1920).';
```

Rule #20 adversarial pass ("what forces a second migration?"): a new QR option → hash input +
`ParamsJson`, no ALTER. A new format → `VARCHAR(10)`, no ALTER. LRU eviction wanted →
`LastAccessAt` exists dormant; code starts stamping it and prunes on
`COALESCE(LastAccessAt, CreatedAt)`, no ALTER. A per-surface purge → `Payload` is queryable.
Nothing else is foreseeable; colour-variant multiplicity is already inside the key.

**ONE registry entry** appended at the END of `manage/includes/migration-registry.php`
(no dependencies; key order IS apply order):

```php
'qr-cache' => [
    'script' => 'migrate-add-qr-cache.php',
    'card' => [
        'title'  => 'QR image cache (#1920)',
        'body'   => 'Creates <code>tblQrCache</code> — a server-side cache of CueRCode-generated'
                  . ' QR images keyed by a payload+options hash, so <code>/qr.php</code> and the'
                  . ' server PDF renderer stop round-tripping the CueRCode service for a picture'
                  . ' that never changes. Fail-open: absent table = today\'s behaviour.'
                  . ' Additive, idempotent, dormant. Safe to re-run.',
        'button' => 'Run QR Cache Migration',
    ],
    /* Single-object probe (rule #19) — never `=> true`. */
    'probe' => static fn(\mysqli $db) => !_migProbe_tableExists($db, 'tblQrCache'),
],
```

$scriptMap/$migrationOrder/$migrationCards/$migrationProbes derive from it — nothing else to
touch; `tests/php/test-migration-registry.php` + `test-schema-coverage.php` cover the pairing
automatically (existing mechanism, no new guard needed for THIS half).

**3.1b One canonicaliser, one key** (`includes/cuercode_client.php`). Extract the clamps
currently inlined at `:173-184` into a PURE
`cuercodeNormaliseOptions(array $opts): array` returning the fully-defaulted, clamped, **ksorted**
map (`ecc, fg_color?, bg_color?, format, size, type` — absent colour keys stay absent);
`cuercodeGenerate()` calls it internally so its behaviour is **byte-identical** (same values,
same order of use). Then:

```php
function cuercodeCacheKey(string $payloadUrl, array $normOpts): string
{
    /* ksort + JSON_UNESCAPED_SLASHES makes the serialisation canonical: the
       SAME logical request always mints the SAME key, and any future option
       is automatically in-key (rule #20). The payload rides inside the JSON
       so no delimiter-injection is possible. */
    return hash('sha256', json_encode(
        ['payload' => $payloadUrl, 'opts' => $normOpts],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    ));
}
```

**3.1c The cache module** — NEW `includes/qr_cache.php` (pure DB, side-effect-free to require,
the `read_rate_limit.php` safety contract verbatim):

- `_qrCacheTableExists(\mysqli $db): bool` — memoized INFORMATION_SCHEMA probe, false on ANY
  error (fail-soft).
- `qrCacheFetch(string $key): ?array{bytes,mime,format}` — gate → bound SELECT of
  `Bytes, Mime, Format` by PK → null on miss/any throwable. No access-time write (v1 is
  TTL-only; the read path stays one statement).
- `qrCacheStore(string $key, string $payload, array $normOpts, array $qr): void` — gate →
  refuse when `strlen($qr['bytes']) > CUERCODE_MAX_RESPONSE_BYTES` (belt; the client's aborting
  write-callback already bounds it) → **row-cap belt**: `SELECT COUNT(*)`; at/over
  `QR_CACHE_MAX_ROWS` delete the oldest `CreatedAt` batch
  (`DELETE FROM tblQrCache ORDER BY CreatedAt ASC LIMIT 500`) → bound
  `INSERT … ON DUPLICATE KEY UPDATE CacheKey = CacheKey` (keep-existing — two racing misses
  both generated identical bytes; first write wins, second is a no-op) with
  `CreatedAt = UTC_TIMESTAMP()` SQL-side (one clock, the read-rate-limit doctrine). Every step
  inside ONE try/catch → a store failure is invisible to the caller.
- Constants (code, not settings — no way to misconfigure the fail-soft bound):
  `QR_CACHE_TTL_DAYS = 90`, `QR_CACHE_MAX_ROWS = 20000`, prune batch 500.
- `qrCachePrune(\mysqli $db): int` — bound `DELETE … WHERE CreatedAt < UTC_TIMESTAMP() -
  INTERVAL ? DAY` — called from a new existence-gated block in `appWeb/.sql/cleanup.php`
  (the house janitor, §1.1), NOT from the request path.

**3.1d The read-through composition** — in `cuercode_client.php`, so both consumers share it
(rule #22):

```php
function cuercodeGenerateCached(string $payloadUrl, array $opts = []): ?array
{
    $payloadUrl = trim($payloadUrl);
    if ($payloadUrl === '' || strlen($payloadUrl) > CUERCODE_MAX_PAYLOAD_LEN) { return null; }
    /* DORMANCY FIRST: an unkeyed install answers null (→ qr.php's 503) BEFORE
       the cache is ever consulted — "dormant until keyed" (rule #38) stays a
       property of the INSTALL, not of what the cache happens to hold. */
    if (!cuercodeConfigured()) { return null; }
    $norm = cuercodeNormaliseOptions($opts);
    $key  = cuercodeCacheKey($payloadUrl, $norm);
    if (($hit = qrCacheFetch($key)) !== null) { return $hit; }
    $qr = cuercodeGenerate($payloadUrl, $opts);   /* the untouched HTTP path */
    if ($qr !== null) { qrCacheStore($key, $payloadUrl, $norm, $qr); }  /* never caches a failure */
    return $qr;
}
```

`qr.php:74-78` collapses to the wrapper (`cuercodeConfigured()` pre-check dropped — the wrapper
owns it; headers/validation/rate-limit untouched); `pdf_renderer.php:289,306` switches its
`function_exists` + call to the cached name. `cuercode_client.php` gains a guarded
`require_once` of `qr_cache.php`. **No other caller of `cuercodeGenerate()` exists** (tree grep
§1.1); after this change the raw HTTP function has exactly one caller — the wrapper — and §6.1's
guard bans new direct calls.

### 3.2 #1921 — version-signal ETag + 304 on `songs_index`

**3.2a The signal** — NEW `includes/songs_index_etag.php`, three PURE-ish helpers:

```php
/* ONE statement, four aggregates. No WHERE — deliberately counts hidden/
   soft-deleted rows too: visibility flips are UPDATEs (they bump UpdatedAt),
   hard deletes change COUNT, and a predicate-free scan can never drift from
   the read path's own gated predicates. Over-invalidation (an admin edit to a
   hidden song forces one full 200) is the safe direction; under-invalidation
   is the bug class. ~14.5k + ~40 rows: milliseconds, vs the full slim query +
   JSON encode + ~300 KB transfer it replaces on a hit. */
function songsIndexVersionSignal(\mysqli $db): ?string   // "cnt|max|cnt|max", null on ANY error
function songsIndexEtag(string $signal, int $contractVersion, string $deployRef, string $shapeToken): string
    // '"si' . $contractVersion . '-' . hash('xxh64', $signal.'|'.$deployRef.'|'.$shapeToken) . '"'
function songsIndexEtagMatches(string $ifNoneMatch, string $etag): bool
    // RFC 7232: split on commas, trim, strip an optional W/ prefix, handle '*',
    // exact-compare (the song-media.php:309-314 precedent, comma-list widened)
```

The four fold-ins and why each is load-bearing:
- `signal` — corpus content, both tables (§1.2 axis 1).
- `contractVersion` (`apiContractVersion()`) — v1 bare vs v2 envelope are different bytes; the
  version lives **in the ETag value**, so a cross-version 304 is impossible even where the
  existing `Vary: X-API-Version` (emitted for v2 only, `api.php:19385`) isn't in play.
- `deployRef` — `(string)($app["Application"]["Version"]["Repo"]["Commit"]["SHA"]["Short"] ?? '')`
  (already in api.php scope, `:59`): a deploy that changes the emit shape invalidates by
  mechanism, not by someone remembering a seed bump (rule #35). NULL on local/dev folds as `''`
  — harmless (dev has no long-lived HTTP caches).
- `shapeToken` — NEW tiny public `SongData::slimIndexShapeToken(): string` =
  `hash('xxh64', ((int)$this->_hasPublicIdColumn()) . '|' . $this->_visible())` — the two
  schema-state gates the SQL itself branches on (§1.2 axis 2), read via the class's own memoized
  probes (no second probe path, rule #35).

**3.2b The handler** — `api.php` `case 'songs_index'` becomes:

```php
case 'songs_index':
    enforceReadRateLimitKeyed('songs_index', 120);
    /* #1921 — version-signal conditional revalidation. FAIL-OPEN: any miss,
       throw, or absent signal serves today's full 200 (no ETag). A 304 runs
       NO slim-index query and sends NO body (rule #17: the cheapest read is
       the one that never materialises). */
    try {
        require_once __DIR__ . '/includes/songs_index_etag.php';
        $siSig = songsIndexVersionSignal(getDbMysqli());
        if ($siSig !== null) {
            $siEtag = songsIndexEtag($siSig, apiContractVersion(),
                (string)($app["Application"]["Version"]["Repo"]["Commit"]["SHA"]["Short"] ?? ''),
                $songData->slimIndexShapeToken());
            header('ETag: ' . $siEtag);
            header('Cache-Control: no-cache, must-revalidate');   /* mirror the 200's policy on the 304 (RFC 7232 §4.1) */
            if (songsIndexEtagMatches(trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')), $siEtag)) {
                http_response_code(304);
                exit;
            }
        }
    } catch (\Throwable $_e) { /* fail-open — full payload below */ }
    sendJson(['songs' => $songData->getSongsSlimIndex()]);
    break;
```

(`songsIndexEtagMatches('' , …)` returns false, so the no-header case needs no special branch.
`sendJson()`'s own `Cache-Control: no-cache, must-revalidate` at `:19378` is exactly the
store-but-always-revalidate policy an ETag wants — **no cache-header change to the 200 path at
all**, and uncontrolled contexts (pre-SW first load, hard reload) get browser-native conditional
revalidation for free.)

**3.2c The service-worker half** — a NEW dedicated strategy beside `networkFirstWithCache()`
(NOT a modification of it — that function also serves CDN assets and page fragments; a distinct
behaviour is a distinct function, rule #22's "smallest reusable unit"):

```js
async function networkFirstRevalidated(request) {
    const cache  = await caches.open(CACHE_VERSION);
    const cached = await cache.match(request);
    const etag   = cached ? cached.headers.get('ETag') : null;
    try {
        let req = request;
        if (etag) {
            const h = new Headers(request.headers);   // preserves X-API-Version / X-Preferred-Languages
            h.set('If-None-Match', etag);
            req = new Request(request, { headers: h });
        }
        const response = await fetch(req, { cache: 'no-store' });   // keep the layered-cache fix
        if (response.status === 304 && cached) { return cached; }   // NEVER cache.put a 304 (no body)
        if (response.ok) { cache.put(request, response.clone()); }  // keyed on the ORIGINAL request
        return response;
    } catch (error) {
        if (cached) { return cached; }
        return offlineFallbackResponse();
    }
}
```

Wired at the ONE songs_index branch (`:832-835`). `SW_CACHE_REVISION` is **not** bumped: the
bucket layout and entry meaning are unchanged (`:120-127` defines the bump trigger as SHAPE
changes; a smarter fetch of the same entry is not one). Install-time best-effort precache
(`:511-513`) is untouched — a fresh install has no cached copy, takes the plain-fetch branch.

### 3.3 #1571 — the buildable-now safe subset

**3.3a Own rate bucket.** `api.php:1373`: `enforceReadRateLimitKeyed('bulk', 60)` →
`enforceReadRateLimitKeyed('export', 60)` (+ the comment rewritten to say WHY it is separate:
an export click must never compete with the offline sync's budget). Limit stays 60/min — the
goal is independence, not tightening; nobody who passes today can newly 429. `bulk_songs` /
`bulk_audio` / `songs` keep `'bulk'`. `api-docs.yaml:188` splits into two rows
(`songbook_export` → `export`, 60/min; the bulk pair keeps its combined row). Zero schema
(`Scope` is the reserved-room VARCHAR, §1.3).

**3.3b Honest large-book confirm** — ONE helper in `export-ui.js`:

```js
const LARGE_EXPORT_SONG_THRESHOLD = 500;   // client-only UX affordance: no server
// pair exists, so a local constant is NOT a rule-#35 violation — nothing can drift against it.
function confirmLargeExport(count) {
    if (!Number.isFinite(count) || count < LARGE_EXPORT_SONG_THRESHOLD) { return true; }
    return window.confirm('This songbook has ' + count.toLocaleString() + ' songs. Building the '
        + 'export can take a few minutes and the page may feel busy while it works. Continue?');
}
```

Called **pre-fetch** in `wireSongbookExportMenu()` with the count read DOM-first: the tile's
existing `data-songbook-songs` (/songbooks list) or the NEW
`data-songbook-song-count="<?= count($songs) ?>"` attribute on `.page-songbook`
(`includes/pages/songbook.php:166` — same-for-everyone, so cacheable-fragment-safe under rule
#6, static markup only under rule #30). When neither yields a number (an older cached fragment),
a **post-fetch belt** inside `exportSongbookAs()` checks `songs.length` before the encode starts
— the server work is spent, but the client stall (the bigger hazard) is still consented to.
Declined confirm = quiet return (no error toast). Editor surfaces (`index.php:1773-1788`,
`:1867-1882`) call the same-shape check against `songData.songbooks[].songCount` before their
fetch (five lines each; the constant is repeated there deliberately — the editor bundle is a
separate non-module script world, and a one-number duplication with a cross-reference comment
beats loading an ES module into it; flagged in §6.6's guard so the two literals cannot drift
silently).

**3.3c Progress + cooperative yield** — in the ONE builder `buildBulkFiles()`
(`propresenter-export.js:1026-1081`), which BOTH bulk formats and BOTH surfaces flow through:

- `options.onProgress?: (done, total) => void` — invoked per song inside its own try/catch (a
  UI callback error must never kill an export).
- Every 25 songs: `await new Promise(function (r) { setTimeout(r, 0); });` — a MACROTASK yield
  (an `await` on a resolved promise only reaches microtasks — no paint, no input; this is the
  load-bearing distinction §1.3 verified).
- `export-ui.js` passes an `onProgress` that surfaces coarse milestones (a toast at each 20%
  step for books over the threshold, plus the existing completion toast); the editor's PP7 path
  passes its `notify()` the same way. Text formats (`format-export.js`) get the confirm only —
  they are synchronous string-builders measured in hundreds of ms, and threading progress
  through eight exporters is Wave-5 polish, not this fix (scoping decision recorded here).

**3.3d The heavy tier is NOT built** — chunked fetch / streaming ZIP / server-side ZIP is the
surfaced owner decision in §8.1, exactly as the issue requested ("filed for an owner priority
call").

---

## 4. Dormancy / no-op-safety proofs

| State | #1920 behaviour | Proof obligation |
|---|---|---|
| CueRCode unkeyed (any schema state) | `cuercodeGenerateCached()` nulls at `cuercodeConfigured()` **before** any cache read → qr.php 503-no-body, pdf drops the `<img>` — byte-identical to today | guard 6.1 asserts the config gate precedes the fetch in the wrapper source |
| Keyed, `tblQrCache` absent | `_qrCacheTableExists()` false (memoized) → fetch null, store no-op → the untouched `cuercodeGenerate()` HTTP path runs exactly as today; response bytes/headers identical | truth-table test with the probe forced false |
| Keyed + migrated, miss | one extra PK SELECT + one INSERT around the identical HTTP call; response bytes identical (stored verbatim, served verbatim) | functional round-trip in 6.1 |
| Any DB throwable mid-cache | swallowed → HTTP path (a cache must never take the endpoint down — the `read_rate_limit.php` contract) | structural: every DB touch inside try/catch |

| State | #1921 behaviour |
|---|---|
| Signal query fails / DB flaky | no `ETag` header, full 200 via `sendJson` — today's bytes exactly (the try/catch wraps the whole conditional block) |
| Client sends no `If-None-Match` | full 200 + additive `ETag` header (natives ignore unknown headers) |
| SW has no cached copy (fresh install / purged) | plain `no-store` fetch, identical to today |
| Rogue 304 with no cached copy | structurally unreachable from our SW (INM sent only when a cached copy exists); if an intermediary minted one anyway, the SW returns it and the page's existing `response.ok` checks fail loudly (`settings.js:1210`) — never silent corruption |
| Un-migrated soft-delete/PublicId schema | `slimIndexShapeToken()` folds the same memoized gates the SQL uses — the ETag and the payload change together by construction |

#1571a: `'export'` on an un-migrated `tblReadRateLimit` install fail-opens (unchanged); the
split cannot 429 any request pattern that passes today (same 60/min, now uncontended). The
confirm/progress changes are pure client affordances — a `confirm` accepted or a missing count
attribute reproduces today's flow byte-for-byte on the wire.

---

## 5. §A — Adversarial analysis (what would make each fix wrong)

**A.1 QR cache poisoning / integrity.** The ONLY write path into `Bytes` is a 2xx,
`success:true`, well-formed data-URI answer from the authenticated CueRCode call — user input
can select the key but never the stored bytes. Serving keeps qr.php's exact header set
(`nosniff`, CueRCode's own mime, immutable) — the cache changes where bytes come FROM, nothing
about how they are served. A failure (`null`) is **never** stored, so an outage cannot be
cached into a permanent 503.

**A.2 Cache-fill DoS.** Distinct hostile payloads mint distinct rows, but each row costs the
attacker a real CueRCode round trip inside the 240/min `'qr'` budget, size is bounded by the
client's 2 MiB aborting write-callback (real QRs: tens of KB), and growth is bounded twice —
the 20,000-row belt in `qrCacheStore()` (oldest-first batch delete) and the 90-day TTL prune in
`cleanup.php`. Worst sustained abuse degrades to… today's behaviour (cache misses). Rotating
Service-Mode join-code URLs (a NEW payload per rotation, §rule #26) age out on the same TTL.

**A.3 Key drift between the clamp and the key.** If qr.php's input clamps and the client's
normalisation ever disagreed, two spellings of one request could mint two rows (waste) — but
never a WRONG image, because the key is derived from the SAME normalised map the HTTP body is
built from (`cuercodeNormaliseOptions()` is the one fold, called inside both `cuercodeGenerate()`
and the key derivation). That single-fold property is what guard 6.1 mutation-tests.

**A.4 ETag under-invalidation (the correctness bug class).** Enumerated vectors: (i) cascaded
`Abbreviation` rename skips `tblSongs.UpdatedAt` → caught via `tblSongbooks.UpdatedAt`
(§1.2 axis 1); (ii) a restore/import writing an explicit **backdated** `UpdatedAt` with no
count change → stale 304 until any other row changes — accepted residual: no current funnel
backdates (`ON UPDATE` fires when the app doesn't supply the column), any other corpus change
heals it, and the deploy fold re-keys at least weekly; documented in the helper's doc-block
with this plan cited; (iii) a future server-side language filter on `songs_index` would add a
per-user axis the ETag doesn't key — guard 6.2 trips on any viewer/language read appearing
inside the case (a tripwire, deliberately narrow); (iv) TIMESTAMP session-zone: the signal is
compared only against its own prior output on the same DB pool — a zone change invalidates once
(over-invalidation, safe).

**A.5 ETag over-invalidation (the perf-only bug class).** Admin edits to hidden rows, denorm
`SongCount` recomputes, media-flag maintenance — all bump the signal and cost one full 200.
Deliberate: the predicate-free aggregates can never silently diverge from the read path, which
is worth a few spurious full responses per curation session.

**A.6 The SW half.** Never `cache.put` a 304 (a bodiless entry would poison the offline
fallback — asserted in 6.3); the put is keyed on the ORIGINAL request so the match key never
carries `If-None-Match`; headers are copied from the live request so `X-API-Version` still
reaches the server (a v2 page revalidating a v1-era cached entry sends the v1-tagged ETag → the
version-folded compare fails → full v2 200 replaces it — self-correcting, no Vary gymnastics);
`cache: 'no-store'` is retained so the layered-cache trap (`:1177-1189`) stays fixed.

**A.7 The export subset.** A stale cached fragment's count attribute only mis-gates a CONFIRM,
never correctness (and the post-fetch belt still fires). `onProgress` is try/catch-wrapped so a
UI error cannot kill an export mid-encode. The macrotask yield changes timing, not bytes —
`buildZip()` consumes the same `files` array (determinism asserted by the existing
`test-propresenter-export.js` fixtures). The bucket rename cannot lose counter data
(`tblReadRateLimit` rows are (key, scope, window)-scoped; old `'bulk'` rows for exports simply
age out).

**A.8 What would make each fix wrong, in one line each.** #1920: caching a null; a second
normaliser; the cache consulted before the dormancy gate; an unguarded DB touch. #1921: hashing
the materialised payload (re-introduces the cost the fix exists to remove); a per-user axis
outside the ETag; the SW putting a 304; forgetting the SW entirely (silent no-op for the PWA).
#1571: tightening the export limit while splitting it; a confirm that blocks small books; a
progress loop that still never yields to a macrotask.

---

## 6. CI guards (tree-derived, mutation-proven — rule #34)

Every guard's first run must be proven able to fail (break → red → restore, recorded in the
commit's verification notes). Narrowness is checked against correct code before landing.

1. **NEW `tests/php/test-qr-cache.php`** — functional + structural for #1920.
   - CALL `cuercodeNormaliseOptions()` / `cuercodeCacheKey()` (the client is side-effect-free to
     require): defaults-filled vs explicit-equal opts → SAME key; option order → SAME key; each
     axis varied alone (payload/size/format/ecc/type/colour) → DIFFERENT key.
   - Tree-derived: enumerate every `cuercodeGenerate(` call site under the docroot (grep, not a
     typed list); assert the ONLY one outside `cuercode_client.php` is none — i.e. `qr.php` and
     `pdf_renderer.php` call `cuercodeGenerateCached(` (mutation: revert qr.php to the raw call
     → red).
   - Structural on `qr_cache.php` (comment-stripped via `token_get_all`, the
     test-rate-limit-pairing technique): an INFORMATION_SCHEMA existence probe exists; every
     `prepare(`/`query(` sits inside a `try` block; no `$` interpolation inside any SQL string
     (bound-only); the store function contains the `ON DUPLICATE KEY UPDATE CacheKey = CacheKey`
     keep-existing marker and a `MAX_ROWS` comparison; `cuercodeGenerateCached()`'s
     `cuercodeConfigured()` gate textually precedes its `qrCacheFetch(` (dormancy order).
   - Registry/schema pairing: already covered by the existing `test-migration-registry.php` +
     `test-schema-coverage.php` mechanisms — asserted here only that the `'qr-cache'` slug
     exists (so a dropped registry entry is a named failure, not a diffuse one).
2. **NEW `tests/php/test-songs-index-etag.php`** — #1921 server half.
   - CALL the pure helpers: `songsIndexEtag()` stable across calls / sensitive to each of the
     four folds varied alone; `songsIndexEtagMatches()` truth table (`''`→false, exact→true,
     `W/`-prefixed→true, comma-list containing it→true, `*`→true, other→false).
   - Structural on api.php's case (comment-stripped, case-body-bounded — the widened-window
     lesson of #1675's guard log applied up front): the `304` emit and its `exit` textually
     precede the `getSongsSlimIndex()` call (mutation: swap → red); the ETag call site passes
     `apiContractVersion()` and `slimIndexShapeToken()`; the case body contains NO
     `makeLanguageFilterPredicate` / `resolvePreferredLanguagesForRequest` /
     `getAuthenticatedUser` token (the A.4-iii tripwire — narrow: this case only).
3. **EXTEND `tests/test-offline-cache-policy.js`** — #1921 SW half (the file already exercises
   the songs_index route, `:295`).
   - The songs_index branch dispatches to `networkFirstRevalidated` (not `networkFirstWithCache`);
     the strategy source (extracted from the generated JS, tree-derived) sets `If-None-Match`
     from `cached.headers.get('ETag')`, contains a `status === 304` branch that returns the
     cached response, has NO `cache.put` reachable on that branch (mutation: move the put above
     the 304 check → red), and retains `cache: 'no-store'`.
4. **EXTEND `tests/test-qr-cuercode.js`** — update `:83` to accept the cached wrapper in qr.php
   and ADD the inverse: the raw `cuercodeGenerate(`-immediately-followed-by-`(` shape appears in
   NO file except `cuercode_client.php` (this extension is what §1.1 proved fail-capable by
   construction — the current assertion goes red the moment C3 lands without it).
5. **NEW `tests/php/test-read-rate-limit-docs.php`** — the rule-#35 mechanism for the bucket
   table (#1571a).
   - Targeted: the `songbook_export` case body contains `enforceReadRateLimitKeyed('export'`
     and NOT `('bulk'` (mutation: revert C1 → red).
   - Docs→code direction, tree-derived: parse every `| \`…\` | N / minute |` row out of
     api-docs.yaml's read-throttle table; for each named action, locate its `case '<action>'`
     body in api.php and assert an `enforceReadRateLimitKeyed('…', N)` call with the documented
     N exists in it (a stale docs row goes red). Code→docs is deliberately NOT asserted —
     several real scopes (`setlist_get`, `songs_list`, …) are legitimately undocumented today,
     and a guard that fails on correct code gets deleted (rule #34's second edge; recorded).
6. **EXTEND `tests/test-export-ui.js` + `tests/test-songbook-list-export.js` +
   `tests/test-propresenter-export.js`** — #1571 safe subset.
   - export-ui.js: `confirmLargeExport` defined once, called on both wiring paths; the count is
     read from `data-songbook-songs` / `data-songbook-song-count` (tree-derived attribute grep
     against the two fragment files, so a renamed attribute fails the pair — rule #33); no
     server-prose matching anywhere in the new branches.
   - propresenter-export.js functional (the file already runs under node in this test):
     `buildBulkFiles()` with N stub songs invokes `onProgress` N times with `(done, total)`;
     a `setTimeout(` yield exists inside the loop; `onProgress` throwing does not reject the
     build (mutation: remove the callback try/catch → red).
   - The two `LARGE_EXPORT_SONG_THRESHOLD` literals (module + editor inline) are equal
     (comment-stripped numeric extraction — the lockstep mechanism for §3.3b's deliberate
     duplication).

---

## 7. Commit breakdown (one PR to `alpha`, atomic commits, smallest-safest-first)

**C1 — `perf(api): dedicated 'export' read-rate bucket for songbook_export (#1571)`**
`api.php:1373` one-word scope change + rewritten comment; `api-docs.yaml` table split; guard 5
(both halves, mutation-proven). Nothing else. Verify: `php -l`, full test run, break-red-restore
log. Comments on #1571 (does not close it — §9).

**C2 — `feat(db): tblQrCache one-pass dormant schema + registry card (#1920 prep)`**
`migrate-add-qr-cache.php` + byte-identical `schema.sql` mirror + the ONE `'qr-cache'` registry
entry. Zero readers/writers — provably inert. Existing registry/schema CI covers the pairing.

**C3 — `feat(qr): read-through CueRCode QR cache behind the ONE client (#1920)`**
`includes/qr_cache.php`; `cuercodeNormaliseOptions()`/`cuercodeCacheKey()`/
`cuercodeGenerateCached()` in `cuercode_client.php` (generate() internally re-based on the
extracted normaliser — byte-identical clamps); `qr.php` + `pdf_renderer.php` call-site switch;
`cleanup.php` TTL-prune block (existence-gated). Guards 1 + 4. **Closes #1920** (close comment
records the two deliberate deviations from the issue letter, §1.4, with this plan cited).

**C4 — `perf(api): version-signal ETag + 304 on songs_index (#1921 server half)`**
`includes/songs_index_etag.php`; `SongData::slimIndexShapeToken()`; the case-body conditional
block. Guard 2. Referenced by, does not close, #1921 (the PWA half is C5 — server-only would be
the silent-no-op §1.2c warns about).

**C5 — `perf(sw): conditional songs_index revalidation (#1921 SW half)`**
`networkFirstRevalidated()` + the one-branch rewire in `service-worker.js.php` (no
`SW_CACHE_REVISION` bump — rationale in §3.2c, restated in the commit body). Guard 3.
**Closes #1921** (cites C4+C5; close comment names the SW `no-store` trap the issue missed).

**C6 — `feat(export): large-book confirm + PP7 bundle progress (#1571 safe subset)`**
`confirmLargeExport()` + wiring in `export-ui.js`; `data-songbook-song-count` on
`includes/pages/songbook.php:166`; `onProgress` + macrotask yield in `buildBulkFiles()`;
editor-surface confirm + progress pass-through in `manage/editor/index.php`. Guard 6.
Comment on #1571; the issue **stays open** carrying §8.1.

**C7 — `docs(perf): api-docs + changelog + wiki + close-out sweep`**
api-docs.yaml prose (qr.php cache note under its endpoint doc, the ETag/304 behaviour on
songs_index incl. the `"si<v>-…"` shape being an opaque token clients must echo, never parse);
CHANGELOG; Wiki (API + Architecture pages); `.claude/` docs + handoff; the standing-tasks
sweep. No code.

Ordering rationale: C1 is a one-word server change with its own guard; C2 is inert schema; C3
depends on C2 (registry order) but degrades cleanly even un-migrated; C4 is fail-open
server-side and useful alone (natives + uncontrolled browser contexts revalidate natively); C5
depends on C4 semantically but is harmless without it (no ETag → plain fetch); C6 is
client-only. Every commit reverts cleanly in isolation.

---

## 8. Owner decisions surfaced

### 8.1 #1571 heavy tier — chunking / streaming architecture (THE decision; blocks nothing in this PR)

**The decision:** whether (and how) to re-architect Mission-Praise-scale songbook export beyond
C1/C6's safe subset. **Why it needs deciding:** it trades a new public API protocol and real
engineering (M) against a hazard whose post-C6 severity is unknown — a product-priority call,
not a code-derivable one.

| Option | What ships | Real consequence |
|---|---|---|
| **O0 — nothing further** (cost of doing nothing) | C1 + C6 only | MP export works with consent + progress, but keeps one multi-MB JSON response (server memory + shared-host execution time, `getSongs`' ten bulk maps) and a multi-second-to-minutes client encode; a low-RAM phone can still crash on the parse. One book in the catalogue is at this scale today. |
| **O1 — chunked fetch** | `songbook_export` gains `limit`/`offset`(+`total`) riding the existing bound WHERE in `getSongs()`; client fetches ~250-song pages, encodes + releases per page, real per-page progress | Bounds BOTH server per-request memory and client transient JSON; 3,517 songs = 15 requests, comfortable inside the new 60/min `'export'` bucket; a documented api-docs protocol addition (rule #33 — new params must be honoured everywhere they're emitted). Effort M. |
| **O2 — streaming ZIP (File System Access / SW stream)** | entries stream to disk as encoded | Removes the in-memory archive — which §1.3 showed is NOT the dominant cost; FS Access is Chromium-only, SW-stream fallback is high-complexity, browser-support cliff for a marginal win. Effort M–L. |
| **O3 — server-side ZIP endpoint** | server builds the archive | **Dead end for the headline case**: the `.pro` encoder is client-side JS (protobufjs + the CSP-static schema, #1788); porting it to PHP is a second exporter — the fork rule #39's one-renderer doctrine exists to forbid, in export clothing. Viable only for the cheap text formats, which don't need it. |

**Recommendation: O0 now — this PR ships C1+C6 and stops; adopt O1 if and when a real
MP-scale report survives C6** (the confirm + progress convert today's hypothesised crash into
an observable, reportable slow path). If you would rather pre-empt than wait, O1 is the only
option worth funding — O2 solves the wrong bottleneck and O3 is structurally wrong.
**What I need back:** "O0" or "O1 now" (one word). Non-blocking either way; #1571 stays open
carrying this table until answered.

### 8.2 Defaults picked (each trivially changeable; none block)

1. **QR cache eviction: TTL 90 days + 20,000-row belt; LRU column dormant** (§3.1a/c). One
   constant each. Rationale: correctness never expires (immutable payloads), so eviction is
   purely a disk bound; TTL avoids a write-per-read.
2. **`'export'` bucket stays 60/min** — independence, not tightening; a native offline sync and
   a curator export can now run concurrently at full budget each.
3. **Large-export confirm threshold: 500 songs** — well above every book except MP (3,517) and
   any future full hymnal; a threshold low enough to nag on a 300-song book would train users
   to click through it.
4. **ETag folds the deploy SHA** — costs one extra full 200 per deploy per client; buys
   shape-change safety with zero human process. (Dropping it would be the "comment saying keep
   in sync" anti-pattern, rule #35.)
5. **Text-format exporters get confirm only, not progress** (§3.3c) — they are string-builders;
   threading progress through eight of them is deferred polish. File a `for consideration`
   issue at implementation time if wanted.

---

## 9. Issue actions on landing

- **#1920** — close at C3 (SHAs C2+C3; note the client-seam and JSON-key deviations from the
  issue letter, §1.4, and the cleanup.php prune wiring).
- **#1921** — close at C5 (SHAs C4+C5; name the SW `no-store` trap and why the version-signal
  ETag replaced the issue's payload-hash sketch).
- **#1571** — comment at C1 and C6 with what shipped; **leave open**, relabelled from
  `for consideration` to an explicit owner-decision item carrying §8.1's table verbatim.
- **File at implementation time** (standing-tasks §2 — issues at the moment of discovery):
  (a) retrospective/tracking issue for the new `test-read-rate-limit-docs.php` mechanism noting
  the actions it deliberately leaves undocumented (guard 6.5's narrowness carve-out);
  (b) `for consideration` — progress affordance for the text-format bulk exporters (§8.2.5);
  (c) `for consideration` — `tblReadRateLimit` has **no prune anywhere** (verified §1.1's
  cleanup.php scan: minute/day window rows accumulate forever) — same cleanup.php shape as the
  new tblQrCache block; found during this pass, out of scope here.
