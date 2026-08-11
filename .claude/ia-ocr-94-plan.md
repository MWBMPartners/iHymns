# #94 Phase 1 — Internet Archive OCR reconcile/audit tool (READ-ONLY) — build plan

**Status:** design complete, ready for a builder. Feasibility issue **#1795** concluded Option A:
build Phase 1 as a **read-only reconcile/audit tool first** — it fetches an archive.org item's
OCR full-text, segments it into candidate hymns, scores each candidate against an existing
iHymns songbook, and renders a curator report (exact / strong / review / **GAP** /
not-found-in-OCR). It **never writes song content**. Phase 2 (curator-approved import) is
sketched in §7 and is explicitly OUT OF SCOPE for this build.

Everything below names real files, real functions, real columns. A builder should need no
further design decisions except the DECISIONS FOR OWNER immediately below.

---

## DECISIONS FOR OWNER

### D1 — Persist audit results now (one-pass schema batch) or keep Phase 1 fully ephemeral?

1. **The decision:** whether Phase 1 ships the two IA tables (`tblIaFetchCache` +
   `tblIaImportCandidates`, §3) now, or ships no schema at all and re-fetches/re-computes
   everything on every page run.
2. **Why it needs deciding:** it is a product/data-shape call, not derivable from code. The
   tables are *audit bookkeeping*, not song content — but "read-only tool" could be read
   strictly as "zero DB writes of any kind".
3. **Options:**

   | Option | Consequence |
   |---|---|
   | **A — ship both tables now** (recommended) | One rule-#20 one-pass batch. The fetch cache makes re-runs instant and is courteous to archive.org (a hymnal `_djvu.txt` is 0.5–3 MB and IA rate-limits); the candidates table gives Phase 2 its approve-queue for free and lets a curator's Phase-2 dismissals survive re-runs. Costs one migration card. |
   | **B — fully ephemeral** | No migration at all. Every "Run reconcile" re-downloads from IA (5–20 s, repeated load on IA), results vanish on navigation, and Phase 2 later ships the same tables anyway — which is exactly the dribbled-out-second-migration shape rule #20 forbids once the family is known to need schema. |
   | **Do nothing** | Phase 1 doesn't exist; #94 stays open. |

4. **Recommendation: A.** The feature family (#94) demonstrably needs this schema (Phase 2 is
   already scoped); rule #20 says design the final DDL up front and ship it additive +
   dormant in ONE batch. Phase-1 writes touch ONLY these two new tables — the CI guard (§6)
   mechanically bans any statement against `tblSongs` / `tblSongComponents` / `tblLyricLines`
   / `tblLyrics` from the new files, so "read-only for song content" stays provable.
5. **What I need back:** "A" or "B". **Does not block** starting the build — the IA client,
   canonicaliser, segmenter and page all build identically either way; only the migration
   commit and the two `iaCached*()`/`iaRecPersistRun()` functions toggle. Under B those
   functions are simply not written and the page fetches directly.

### D2 — Page entitlement: `edit_songs` (curator+) or `manage_songbooks` (admin+)?

1. **The decision:** which existing entitlement gates `/manage/ia-reconcile` (view + run).
2. **Why it needs deciding:** the page is read-only but each run triggers an outbound
   server-side fetch to archive.org — a judgement call about who may cause that.
3. **Options:**

   | Option | Consequence |
   |---|---|
   | **`edit_songs`** (recommended) | Editors/curators — the people who will actually work the gap list — can run audits themselves. Matches the `/manage/duplicate-songs` precedent (curator-visible review surface; destructive actions gated separately — and Phase 1 has no destructive actions at all). Outbound risk is bounded: host-bound to archive.org, size-capped, cache-first. |
   | **`manage_songbooks`** | Admin+ only. Curators must ask an admin to run each audit; the tool becomes a bottleneck for exactly its intended users. |

4. **Recommendation: `edit_songs`.** No new entitlement key — the existing map covers it.
5. **What I need back:** one word. **Does not block** — it is one string in two places
   (page gate + `admin-links.php` entry, which `tests/php/test-admin-gate-parity.php` keeps
   in lockstep).

### Defensible defaults taken without asking (all trivially changeable, none blocking)

- **IA linkage column: NONE NEEDED.** `tblSongbooks.InternetArchiveUrl` already exists
  (`VARCHAR(500)`, #672, schema.sql:205 — "Internet Archive page … or bare IA identifier").
  Phase 1 parses the identifier out of it (§2) and also accepts a free-typed identifier, so
  multi-volume books need no schema change. **No tblSongbooks migration.**
- Verdict thresholds (§4.4) ship as code constants in `includes/ia_reconcile.php`
  (`IA_REC_THRESHOLD_STRONG = 0.85`, `IA_REC_THRESHOLD_REVIEW = 0.60`), not settings rows.
- Nav placement: group **`Catalogue`**, icon `bi-archive`, label **"IA Reconcile"** (it is a
  per-songbook audit; sits naturally under Songbooks). Moving it to `Songs` is one line.
- No IA API key in Phase 1. IA metadata + `_djvu.txt` reads are anonymous. The optional
  S3-style keypair (`ia_s3_access_key` / `ia_s3_secret_key`) is *designed for* (§1.2) but NOT
  built: adding it later is two settings rows + one `secretSettingKeys()` line — no migration.
- User-Agent: `iHymns-Reconcile/1.0 (+https://ihymns.app)` (IA asks automated fetchers to
  identify themselves).

---

## §0 File map

**New files**

| Path | What |
|---|---|
| `appWeb/public_html/includes/ia_client.php` | SSRF-hardened HTTP client for archive.org (mirrors `intapps_client.php` / `cuercode_client.php`) + D1-gated fetch cache (D1: only if owner picks A) |
| `appWeb/public_html/includes/ia_reconcile.php` | PURE segmenter + scoring orchestration + persistence. No HTTP, no superglobals — unit-testable with a fixture blob |
| `appWeb/public_html/manage/ia-reconcile.php` | The admin audit page |
| `appWeb/.sql/migrate-ia-reconcile.php` | One-pass additive migration (both tables) — D1 option A only |
| `tests/php/test-ia-reconcile-guards.php` | CI guard (§6) |
| `tests/php/fixtures/ia-fixture-hymnal_djvu.txt` | Synthetic OCR fixture (§6.3) |
| `tests/php/fixtures/ia-fixture-metadata.json` | Synthetic `/metadata/<id>` response for the stub seam |

**Touched files** (each a small additive edit)

| Path | Edit |
|---|---|
| `appWeb/public_html/includes/identifier_normalize.php` | add `ihymns_canonical_ia_identifier()` (§2) |
| `appWeb/public_html/manage/includes/admin-links.php` | one nav row (§5.1) |
| `appWeb/public_html/manage/includes/migration-registry.php` | ONE registry entry (§3.3) — option A only |
| `appWeb/.sql/schema.sql` | byte-identical mirrors of both CREATE TABLEs (§3.2) — option A only |

**Forbidden:** anything under `appWeb/public_html/includes/musician*` or
`appWeb/public_html/manage/musician*` (another agent owns those), and ANY write statement
against song-content tables from the new files (CI-enforced, §6).

---

## §1 The IA client — `includes/ia_client.php`

Modelled line-for-line on the two existing outbound clients. **The builder must read
`includes/intapps_client.php` and `includes/cuercode_client.php` before writing this file**
— copy their shape, not just their spirit: `declare(strict_types=1)`, side-effect-free to
require (functions + constants only, no connection, no HTTP), ELI5 + DETAIL doc-blocks,
returns null/typed-array on ANY failure, **never throws into a page render**.

### 1.1 archive.org read endpoints (put these in the file header comment)

```
Metadata:  GET https://archive.org/metadata/<identifier>
           → JSON: { "metadata": {title, language, ...},
                     "files": [ {name, format, size, sha1|md5, ...}, ... ],
                     "server": "ia601409.us.archive.org",
                     "d1": "...", "d2": "...",       ← fallback datanodes
                     "dir": "/12/items/<identifier>" }
           A NONEXISTENT identifier answers HTTP 200 with body "{}" — treat as null.

Full text: the derived plain-text OCR file, normally named "<identifier>_djvu.txt"
           (files[] entry with format "DjVuTXT"). The friendly URL
           https://archive.org/download/<identifier>/<identifier>_djvu.txt answers
           HTTP 302 to a datanode — and this client NEVER follows redirects (house
           SSRF rule). So the client builds the DIRECT datanode URL from the
           metadata response instead:
               https://{server}{dir}/{fileName}
           and validates {server} against the ".archive.org" host-suffix allowlist
           BEFORE dialling (a hostile/tampered metadata body must not be able to
           steer this server at an arbitrary host — that would be SSRF *via* the
           metadata). d1/d2 are legitimate fallback hosts, same validation.

(Alternative not used in Phase 1: the BookReader search-inside API — per-page hit
coordinates, but paginated + rate-limited; note it for Phase 2's page anchoring.)
```

### 1.2 Settings keys + constants

```php
const IA_SETTING_BASE_URL       = 'ia_base_url';        /* default https://archive.org */
const IA_SETTING_ALLOW_LOOPBACK = 'ia_allow_loopback';  /* '1' only on a local/test install so the
                                                           stub fixture is reachable over http:// —
                                                           the intapps/cuercode carve-out, verbatim */

const IA_DEFAULT_BASE_URL   = 'https://archive.org';
const IA_DATANODE_SUFFIX    = '.archive.org';   /* datanode host allowlist: host must end with this */

/* Timeouts — a DOCUMENTED deviation from the 2s/3s house band (ip_geolocation/intapps/
   cuercode): those bound PUBLIC hot paths; this client is reachable ONLY from an
   admin-triggered, entitlement-gated POST (the §6 guard asserts exactly that, tree-derived),
   and a hymnal _djvu.txt is megabytes. Say all of this in the constants' comment. */
const IA_CURL_CONNECT_TIMEOUT   = 5;
const IA_CURL_TIMEOUT_METADATA  = 15;
const IA_CURL_TIMEOUT_FULLTEXT  = 60;

const IA_MAX_METADATA_BYTES = 2097152;    /* 2 MiB — files[] can be long on multi-file items */
const IA_MAX_FULLTEXT_BYTES = 15728640;   /* 15 MiB — deliberately UNDER MEDIUMTEXT's 16,777,215-byte
                                             cap so a cached payload can never overflow the column */

const IA_METADATA_TTL_SECONDS = 86400;    /* 24 h */
const IA_FULLTEXT_TTL_SECONDS = 2592000;  /* 30 days — a finished scan's OCR is effectively immutable */
```

**No API key in Phase 1** — IA public reads are anonymous (unlike CueRCode). Design-for-later
(comment only, do not build): if authorized fetches are ever needed, add
`ia_s3_access_key` + `ia_s3_secret_key` settings, register **`ia_s3_secret_key`** in
`secretSettingKeys()` (`includes/secret_crypto.php:430` — the `apple_apns_private_key`
comment there is the precedent for registering a not-yet-used key), and send
`Authorization: LOW <access>:<secret>`. Zero schema impact.

### 1.3 Function signatures

```php
/** Identifier charset per IA's own rules: 1–100 chars, [A-Za-z0-9._-], starts alphanumeric. */
function iaIdentifierValid(string $identifier): bool;
// implementation: (bool)preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/', $identifier)

/** PURE SSRF gate — IDENTICAL shape to _cuercodeResolveUrl()/_intappsResolveUrl():
 *  https:// always; http:// only when $allowLoopback AND host ∈ {127.0.0.1, ::1, localhost};
 *  URL rebuilt from parsed scheme/host/port + the caller's fixed $path ONLY.
 *  @return array{0:string,1:string}|null  [$fullUrl, $host] or null if refused. */
function _iaResolveUrl(string $baseUrl, string $path, bool $allowLoopback): ?array;

/** PURE datanode-URL builder — the SECOND SSRF gate, for hosts that arrive in DATA
 *  (the metadata response), not config. Returns null unless $server (lowercased,
 *  no scheme, no port, no slash, no '@', no '..') ends with IA_DATANODE_SUFFIX.
 *  $dir and $fileName are path-sanitised: leading-slash-normalised, rawurlencoded
 *  per segment, '..' segments refused. Always https:// (datanodes are never the
 *  loopback stub — see iaFetchFulltext() for the stub branch). */
function _iaDatanodeUrl(string $server, string $dir, string $fileName): ?string;

/** The ONE HTTP round trip (GET only — this client has no mutating verb at all).
 *  No redirects (CURLOPT_FOLLOWLOCATION => false), SSL_VERIFYPEER true / VERIFYHOST 2,
 *  CURLPROTO pinned to the resolved scheme, aborting size-capped write-callback
 *  (copy cuercode's $writeFn verbatim, including the CURLE_FILESIZE_EXCEEDED note
 *  from intapps if CURLOPT_MAXFILESIZE is also set). Returns null on transport
 *  failure; {status, bytes} otherwise so callers can distinguish 404 from timeout. */
function _iaHttpGet(string $url, int $timeoutSeconds, int $maxBytes): ?array; // {status:int, bytes:string}

/** GET {base}/metadata/{identifier}. null on: invalid identifier, refused URL, no curl,
 *  transport failure, non-200, oversized, body not a JSON object, or the "{}" /
 *  no-'files' nonexistent-item shape. Returns the decoded metadata array. */
function iaFetchMetadata(string $identifier): ?array;

/** Pick the OCR text file out of a metadata response. Prefers the files[] entry named
 *  exactly "<identifier>_djvu.txt"; else the first entry with format === 'DjVuTXT';
 *  else the first name ending "_djvu.txt". Validates server via _iaDatanodeUrl()
 *  (tries server, then d1, then d2). Skips any file whose declared size exceeds
 *  IA_MAX_FULLTEXT_BYTES (fail early, don't start a doomed transfer).
 *  @return array{fileName:string,url:string}|null */
function _iaPickFulltextFile(string $identifier, array $metadata): ?array;

/** Fetch the OCR text. TWO dial branches, both host-bound:
 *   real IA   → the _iaDatanodeUrl() built from metadata (no redirect needed);
 *   loopback  → when the configured base host is a loopback AND the knob is on,
 *               GET {base}/download/{identifier}/{fileName} on the SAME stub base
 *               (the stub serves bytes directly, no 302) — this is the fixture seam
 *               (§6.4); a stub can't mint an .archive.org datanode name.
 *  Post-fetch: strip UTF-8 BOM; sanitise to valid UTF-8 (mb_convert_encoding($t,'UTF-8','UTF-8')
 *  after mb_substitute_character(0xFFFD)) and strip control chars except \n \t \f —
 *  mysqli STRICT + utf8mb4 would otherwise THROW on the first malformed byte at
 *  cache-INSERT time (§8-G).
 *  @return array{text:string,fileName:string,sha256:string,byteSize:int}|null */
function iaFetchFulltext(string $identifier, ?array $metadata = null): ?array;
```

### 1.4 Cache layer (D1 option A only)

Same D1 discipline as intapps (`_intappsTableExists()` + try/catch `\mysqli_sql_exception`
around EVERY table access — migrations are web-run, the 3 docroots share ONE MySQL, so
"enabled but un-migrated" is a normal state, and mysqli STRICT makes a missing-table SELECT
throw):

```php
function _iaCacheTableExists(\mysqli $db): bool;   // memoized INFORMATION_SCHEMA probe, false on ANY error

/** Cache-first metadata. On miss/stale (FetchedAt older than IA_METADATA_TTL_SECONDS) or
 *  $forceRefresh: iaFetchMetadata() then upsert (INSERT … ON DUPLICATE KEY UPDATE) into
 *  tblIaFetchCache. A FAILED fetch never clobbers a previous good payload (the intapps
 *  _intappsCommitFetchResult() invariant). Un-migrated install → straight through to
 *  iaFetchMetadata(), uncached (degrade, never throw). */
function iaCachedMetadata(\mysqli $db, string $identifier, bool $forceRefresh = false): ?array;

/** Cache-first fulltext, same contract.
 *  @return array{text:string,fileName:string,sha256:string,fetchedAt:?string}|null */
function iaCachedFulltext(\mysqli $db, string $identifier, bool $forceRefresh = false): ?array;
```

---

## §2 Identifier canonicaliser — add to `includes/identifier_normalize.php`

One new function beside `ihymns_canonical_ark()` / `ihymns_canonical_openlibrary()` (same
file, same house style — that module is the ONE home for identifier normalisation):

```php
/** Accepts a bare IA identifier OR any of the common archive.org URL shapes
 *  (https://archive.org/details/<id>[/...], /download/<id>/..., /metadata/<id>,
 *  /stream/<id>/..., scheme/www optional) and returns the bare identifier, or null.
 *  Validation = iaIdentifierValid()'s regex, duplicated here as a literal (this module
 *  is deliberately dependency-free; the §6 guard asserts the two regex literals match). */
function ihymns_canonical_ia_identifier(string $raw): ?string;
```

Used by the page to prefill from `tblSongbooks.InternetArchiveUrl` (which by its own COMMENT
may hold either a details URL or a bare identifier) and to normalise the typed input.

---

## §3 Migration — `appWeb/.sql/migrate-ia-reconcile.php` (D1 option A only)

One-pass, additive, idempotent, dormant (rule #19 + #20). Copy the exact operational shape
of `appWeb/.sql/migrate-ingest-review-queue.php` (read it first): CLI/web dual entry,
`IHYMNS_SETUP_DASHBOARD` guard, per-table `_migIaRec_tableExists()` probes with [SKIP]/[OK]
transcript (no `CREATE TABLE IF NOT EXISTS` — the explicit probe IS the operator feedback),
`mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT)`, catch-all that prints
`[ERROR]` and never rethrows. **Create `tblIaFetchCache` FIRST and `tblIaImportCandidates`
SECOND** — the registry probe below goes green only when the SECOND table exists, so a
half-applied run correctly stays pending (the review-queue migration documents this exact
ordering trick in its header; copy the explanation).

### 3.1 DDL (this exact text, byte-identical in migration and schema.sql)

```sql
-- ----------------------------------------------------------------------------
-- Internet Archive OCR reconcile (#94 Phase 1 — read-only audit).
-- tblIaFetchCache caches fetched IA artefacts (metadata JSON / OCR full-text)
-- so repeat audits are instant and courteous to archive.org.
-- Payload is MEDIUMTEXT (16 MiB byte cap); the client caps fetches at 15 MiB
-- (IA_MAX_FULLTEXT_BYTES) so a payload can never overflow the column.
-- Kind is VARCHAR not ENUM (rule #20 — 'metadata' | 'fulltext' today; a later
-- 'djvu-xml' / 'search-inside' kind is an app-level allow-list add, no ALTER).
-- FetchedAt is DATETIME not TIMESTAMP (rule #20 — TTL semantics).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblIaFetchCache (
    Id          INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    Identifier  VARCHAR(120)    NOT NULL COMMENT 'archive.org item identifier (their rules: 1-100 chars [A-Za-z0-9._-], starts alphanumeric; validated by iaIdentifierValid())',
    Kind        VARCHAR(20)     NOT NULL COMMENT 'metadata | fulltext (app-validated VARCHAR vocabulary, never ENUM — rule #20)',
    FileName    VARCHAR(255)    NOT NULL DEFAULT '' COMMENT 'Source file within the item for fulltext rows (e.g. <id>_djvu.txt); empty string (not NULL) for metadata rows so uq_Item_Kind_File stays a real uniqueness',
    HttpStatus  SMALLINT UNSIGNED NULL DEFAULT NULL COMMENT 'Last HTTP status observed for this artefact (diagnostic only)',
    ByteSize    INT UNSIGNED    NOT NULL DEFAULT 0,
    Sha256      CHAR(64)        NULL DEFAULT NULL COMMENT 'sha256 of Payload — the reconcile RunSha for fulltext rows',
    Payload     MEDIUMTEXT      NULL DEFAULT NULL COMMENT 'The cached artefact bytes (UTF-8-sanitised before insert). NULL = a fetch was attempted but never succeeded; a failed refetch NEVER overwrites a previous good payload',
    FetchedAt   DATETIME        NULL DEFAULT NULL COMMENT 'UTC time of the last SUCCESSFUL fetch; NULL = never succeeded. DATETIME not TIMESTAMP (rule #20 TTL semantics)',
    MetaJson    JSON            NULL DEFAULT NULL COMMENT 'Forward-looking per-row facts (rule #20, the #1590 Capabilities-JSON precedent) so an unforeseen bookkeeping need lands without ALTER',
    CreatedAt   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_Item_Kind_File (Identifier, Kind, FileName),
    INDEX idx_FetchedAt (FetchedAt)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Cached archive.org fetch artefacts for the #94 OCR reconcile audit.';

-- ----------------------------------------------------------------------------
-- One row per segmented OCR candidate per (item x target songbook). Phase 1
-- writes score/verdict bookkeeping ONLY (never song content); Phase 2's
-- approve-to-import flow consumes ReviewState (dormant vocabulary until then).
-- SegmentFingerprint (not SegmentIndex) keys the upsert so a re-run on
-- re-OCRed text UPDATES rather than duplicates, and a curator's Phase-2
-- ReviewState survives re-runs (the tblSongLinkSuggestionsDismissed lesson).
-- BestSongId is a SOFT reference (no FK) so a song purge never blocks on, or
-- cascades into, audit bookkeeping.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblIaImportCandidates (
    Id                 INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    Identifier         VARCHAR(120)  NOT NULL COMMENT 'archive.org item identifier this candidate was segmented from',
    SongbookAbbr       VARCHAR(10)   NOT NULL COMMENT 'Target songbook (tblSongbooks.Abbreviation charset) the audit compared against — soft reference, no FK',
    RunSha             CHAR(64)      NOT NULL COMMENT 'sha256 of the fulltext payload this run segmented (= tblIaFetchCache.Sha256); rows with a stale RunSha and ReviewState=none are pruned on the next run',
    SegmentIndex       INT UNSIGNED  NOT NULL COMMENT 'Position of this candidate within its run (display order only — NOT part of the upsert key)',
    SegmentFingerprint CHAR(64)      NOT NULL COMMENT 'sha256(NormTitle + \n + NormFirstLine) — the stable identity of a candidate across re-segmentations; the upsert key',
    HeadingNumber      VARCHAR(20)   NULL DEFAULT NULL COMMENT 'Hymn number as printed in the scan (VARCHAR: 123, 123a, App.7)',
    PageGuess          INT UNSIGNED  NULL DEFAULT NULL COMMENT '1-based page ordinal when the OCR carried form-feed page breaks; NULL otherwise',
    RawTitle           VARCHAR(500)  NOT NULL DEFAULT '' COMMENT 'Title line as OCRed (untrusted text — HTML-escape on render)',
    NormTitle          VARCHAR(500)  NOT NULL DEFAULT '' COMMENT 'ihymns_normalize_title() fold of RawTitle (the EXACT dedup fold — same fold as tblSongs.NormalizedTitle)',
    FirstLine          VARCHAR(500)  NOT NULL DEFAULT '' COMMENT 'First lyric-looking line of the candidate body',
    BodyExcerpt        TEXT          NULL DEFAULT NULL COMMENT 'First ~1000 chars of the candidate body for curator eyeballing. NEVER imported in Phase 1',
    LineStart          INT UNSIGNED  NOT NULL DEFAULT 0 COMMENT '0-based line offsets of the segment within the (normalised) fulltext',
    LineEnd            INT UNSIGNED  NOT NULL DEFAULT 0,
    BestSongId         VARCHAR(20)   NULL DEFAULT NULL COMMENT 'tblSongs.SongId of the best-scoring match; NULL when the verdict is gap/unscorable. SOFT reference, no FK',
    BestScore          DECIMAL(4,3)  NULL DEFAULT NULL COMMENT 'Adjusted composite in [0,1] (ihymns_sim_score() blend rescaled for the absent-authors signal — see includes/ia_reconcile.php iaRecAdjustedScore())',
    Verdict            VARCHAR(20)   NOT NULL COMMENT 'exact | strong | review | gap | unscorable (app-validated VARCHAR vocabulary, never ENUM — rule #20)',
    ReviewState        VARCHAR(20)   NOT NULL DEFAULT 'none' COMMENT 'DORMANT Phase-2 vocabulary: none | approved_for_import | dismissed | imported (app-validated; Phase 1 only ever writes none)',
    ReviewedBy         INT UNSIGNED  NULL DEFAULT NULL COMMENT 'tblUsers.Id (dormant until Phase 2)',
    ReviewedAt         DATETIME      NULL DEFAULT NULL,
    ReviewNote         TEXT          NULL DEFAULT NULL,
    MetaJson           JSON          NULL DEFAULT NULL COMMENT 'Forward-looking per-row facts (rule #20) — e.g. a later per-line OCR-confidence map — so no second ALTER',
    CreatedAt          TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt          TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_Item_Book_Fingerprint (Identifier, SongbookAbbr, SegmentFingerprint),
    INDEX idx_Book_Verdict (SongbookAbbr, Verdict),
    INDEX idx_Item_Run (Identifier, RunSha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Segmented OCR candidates + reconcile verdicts for the #94 audit; Phase-2 import queue (dormant).';
```

Both CREATEs mirrored **byte-identically** (including every COMMENT string) into
`appWeb/.sql/schema.sql`, same commit (rule #19 — `tests/php/test-schema-coverage.php`
enforces). No `@migration-adds` doctags needed (no ALTERs — new tables only).

### 3.2 Why these shapes (rule #20 stress, condensed — full stress in §8)

- Every vocabulary is VARCHAR + app allow-list (`Kind`, `Verdict`, `ReviewState`).
- `FileName NOT NULL DEFAULT ''` — a NULLable column inside a UNIQUE key permits duplicate
  metadata rows (multiple NULLs coexist in MySQL unique indexes); empty-string keeps the
  key honest.
- `(Identifier, SongbookAbbr, …)` keying already supports N:1 both ways: one iHymns book
  audited against several IA volumes AND one IA item audited against several books —
  the multi-volume case needs zero schema change.
- `MetaJson JSON NULL` on both tables is the deliberate no-second-ALTER escape hatch.
- `DECIMAL(4,3)` for scores: exact rendering/sorting, no float-equality surprises.

### 3.3 Registry entry — `manage/includes/migration-registry.php` (append ONE entry; the
four legacy arrays derive from it)

```php
'ia-reconcile' => [
    'script' => 'migrate-ia-reconcile.php',
    'card' => [
        'title'  => 'Internet Archive OCR reconcile (#94 Phase 1)',
        'body'   => 'Creates <code>tblIaFetchCache</code> (cached archive.org metadata +'
                  . ' OCR full-text fetches) and <code>tblIaImportCandidates</code>'
                  . ' (segmented OCR candidates + reconcile verdicts; dormant Phase-2'
                  . ' review vocabulary). Read-only audit bookkeeping — no song-content'
                  . ' tables are touched. Additive + idempotent — safe to re-run.',
        'button' => 'Run IA Reconcile Migration',
    ],
    /* Multi-object OR-probe (rule #19) — never `=> true`. Candidates is created
       SECOND in the script, so a half-applied run keeps the card pending. */
    'probe' => static fn(\mysqli $db) =>
        !_migProbe_tableExists($db, 'tblIaFetchCache')
        || !_migProbe_tableExists($db, 'tblIaImportCandidates'),
],
```

Append at the END of the returned array (order = deployment order; no upstream dependency).
`tests/php/test-migration-registry.php` then covers it automatically.

---

## §4 Reconcile core — `includes/ia_reconcile.php`

Framework-free (no `$_SERVER`/session — the `song_similarity.php` discipline), side-effect-
free to require. Requires: `title_normalize.php`, `song_similarity.php`,
`song_soft_delete.php` (for `songVisibleSql()`), `lyric_lines_read.php`. **Never defines
its own normalise/levenshtein/jaccard — the §6 guard bans it.**

### 4.1 Segmenter — `iaRecSegmentOcr(string $text, array $opts = []): array`

Returns `list<array{index:int, headingNumber:?string, pageGuess:?int, rawTitle:string,
firstLine:string, bodyExcerpt:string, lineStart:int, lineEnd:int}>`.
Deterministic PURE pipeline; every stage below is its own small `_iaRec*()` helper so the
fixture tests can pin each behaviour independently.

1. **Normalise** — `\r\n|\r → \n`; expand tabs; trim trailing spaces per line. If the blob
   contains form feeds (`\f`) treat each as a page boundary (`pageGuess` = running page
   ordinal); IA `_djvu.txt` files vary — some carry `\f`, many separate pages with blank
   runs only, so `pageGuess` is nullable and nothing downstream depends on it.
2. **De-hyphenate** — `preg_replace('/(\p{Ll})-\n(\p{Ll})/u', "$1$2\n", …)` (lowercase
   letter each side; rejoin the word, keep the line count stable by moving the break).
   Accepts imperfection: genuinely hyphenated words rejoined wrongly cost one Levenshtein
   edit, which the scorer absorbs.
3. **Running header/footer removal** — fold each line (`mb_strtolower` + strip digits +
   collapse space); any folded form that is short (< 60 chars) and recurs ≥ 5 times across
   the document is a running header (book title, section name) — blank those lines.
   This is what stops a hymn spanning a page break from being split by the header
   between its verses.
4. **Numbered-heading detection** — a line is a *numbered heading candidate* when it matches
   `/^\s*(?:hymn|no\.?|nr\.?|number)?\s*#?\s*(\d{1,4})\s*([a-z])?\s*[.):\-–]?\s*(\S.*)?$/iu`
   with total line length ≤ 80. Capture 1+2 = `headingNumber`, capture 3 (if any) = inline
   title text.
5. **Sequence filter (the page-number disambiguator)** — hymn numbers form a near-monotonic
   walk; page numbers interleave with it. Greedy pass over the numbered candidates keeping
   each number `n` where `prevKept < n ≤ prevKept + 50` (gap tolerance for OCR misses);
   one reset to a small number is allowed (multi-part books restart numbering). Candidates
   rejected by the walk are demoted to plain text. If < 30 % of numbered candidates survive
   OR fewer than 5 survive in total, the book is treated as **unnumbered** and boundaries
   come from step 6 alone.
6. **Unnumbered boundaries** — a line qualifies as a title-line boundary when: ≥ 3 alpha
   chars, ≤ 60 chars, ≥ 80 % of its letters uppercase (ALL-CAPS titles), **and** it is
   preceded by ≥ 1 blank line; OR any line within 2 lines below it matches the tune/meter
   signature `/\b(?:[CSL]\.? ?M\.?(?:\s?D\.?)?|\d{1,2}(?:[ .,]\s?\d{1,2}){2,})\b/`
   (hymnals print "C.M." / "8 7. 8 7." under titles — a very strong hymn-start signal).
7. **Index/TOC suppression** — any region started by a heading matching
   `/^(general\s+)?(index|contents|table of|first lines|alphabetical|metrical index|topical)/i`
   is excluded until the next accepted numbered heading that RESUMES the sequence, and any
   50-line window where ≥ 60 % of lines end in digits (dot-leader page refs) is excluded
   wholesale. Without this, an "Index of First Lines" fabricates one high-scoring phantom
   candidate per hymn and the report double-counts everything.
8. **Assemble** — text between consecutive accepted boundaries = one candidate.
   `rawTitle` = the heading's inline title if present, else the first following non-blank
   line that is not a tune/meter line and not an author/attribution line (author line
   heuristic: contains a 4-digit year, or `arr\.|alt\.|tr\.|harm\.`, or ends with digits).
   `firstLine` = first line after the title/tune/author block that looks lyric-like
   (≥ 3 words, not ALL-CAPS, doesn't match the author heuristic). `bodyExcerpt` = first
   1000 chars. Numbered hymnals where hymns are *identified by first line* (no separate
   title) naturally yield `rawTitle == firstLine` — harmless, the two-orientation scorer
   (§4.3) covers it.
9. **Sanity bounds** — drop candidates with < 4 non-blank body lines (fragments) or
   > 400 lines (segmentation run-on); hard-cap the run at 2000 candidates (garbage-input
   guard); if the whole blob yields 0 candidates, the page reports "could not segment"
   rather than an empty-but-green table.

**Known failure modes to state in the doc-block (adversarial honesty):** prose front-matter
(prefaces) yields junk candidates (they score `gap` — curator noise, not corruption);
two-column scans OCR interleaved and segment garbage (verdict skew toward `gap`/`unscorable`
is the tell — say so in the page's help text); responsive-verse/psalter books without
numbers or caps segment poorly; Fraktur/long-s OCR ("ſ"→"f") degrades scores but
Levenshtein tolerates scattered errors.

### 4.2 Songbook features — `iaRecSongFeatures(\mysqli $db, string $songbookAbbr): array`

One bound-param query (the `build-song-link-suggestions.php:117` shape, narrowed to one
book — and like that builder, `@disabled-visible` does NOT apply: use `songVisibleSql()`
to exclude soft-deleted songs):

```sql
SELECT s.SongId, s.Number, s.Title, s.NormalizedTitle
  FROM tblSongs s
 WHERE s.SongbookAbbr = ? AND {songVisibleSql($db, 's')}
```

First lines via the ONE read path (rule #25): `lyricLinesMirrorPresent($db)` →
`lyricLinesFirstLineMap($db, $songIds)` (`lyric_lines_read.php:332`, chunked internally);
on an un-migrated install fall back exactly as the builder does (the marked
`lines-json-fallback` correlated-subquery pattern, `build-song-link-suggestions.php:109-114`)
— never a raw ungated `LinesJson` read (`test-component-json-guard.php` enforces).
Per song emit:

```php
['songId', 'number', 'title',
 'normExact'     => $row['NormalizedTitle'] !== '' ? $row['NormalizedTitle']
                                                   : ihymns_normalize_title($row['Title']),
 'normTitle'     => ihymns_sim_normalise($row['Title']),      // FUZZY fold (article-stripping)
 'normFirstLine' => ihymns_sim_normalise($firstLine),
 'authors'       => '']                                        // deliberately unused — see §4.3
```

Keep the TWO normalisers distinct (rule #22): `ihymns_normalize_title()` is the EXACT fold
(equality vs `tblSongs.NormalizedTitle`); `ihymns_sim_normalise()` is the FUZZY fold fed to
the scorer. Never swap them.

### 4.3 Scoring — reuse, never re-fork

```php
/** Rescale ihymns_sim_score()'s composite for the absent-authors case. OCR candidates
 *  carry no author data, and ihymns_sim_authors_jaccard('', $anything) === 0.0, so the
 *  maximum attainable blend is 0.50 + 0.35 = 0.85. This helper divides by 0.85 (clamped
 *  to 1.0) so thresholds keep their intuitive meaning. It is a PRESENTATION rescale of
 *  the shared scorer's output — the blend itself still comes ONLY from ihymns_sim_score()
 *  (rule #22; the §6 guard bans local levenshtein/jaccard). */
function iaRecAdjustedScore(array $simResult): float;   // $simResult['score'] / 0.85, min 1.0

function iaRecScoreCandidates(array $candidates, array $songFeatures, array $opts = []): array;
```

Per candidate, in order:

1. **Exact pass** — `ihymns_normalize_title($cand['rawTitle'])` looked up in a prebuilt
   `normExact → songId` hash map. Hit ⇒ verdict `exact`, score 1.000, done. (Also try the
   candidate's `firstLine` fold against the map — first-line-titled hymnals.)
2. **Unscorable pass** — if BOTH `ihymns_sim_normalise(rawTitle)` and
   `…(firstLine)` fold to `''` (non-Latin script stripped to nothing by the iconv
   TRANSLIT step — §8-B), verdict `unscorable`, no fuzzy work.
3. **Blocking prefilter** — inverted index of word tokens (from each song's
   `normTitle` + `normFirstLine`) → song-id set; a candidate's shortlist = union over its
   own tokens. Empty shortlist ⇒ skip to 5. Keeps the pairwise work at
   O(candidates × shortlist) instead of O(N×M) full Levenshtein (a 700-hymn book vs 700
   candidates is otherwise ~500k scorer calls).
4. **Fuzzy pass** — for each shortlisted song call `ihymns_sim_score()` TWICE (this is
   reuse, not a fork — the same public entry point with swapped fields):
   - straight: `{normTitle: cand.title, normFirstLine: cand.firstLine, authors: ''}` vs song
   - crossed:  `{normTitle: cand.firstLine, normFirstLine: cand.title, authors: ''}` vs song
     (covers "hymnal titles by first line" vs "iHymns titles by title" and vice versa)
   Take the max composite; `iaRecAdjustedScore()` it; keep the best song.
5. **Verdict** (constants; thresholds trivially tunable):
   - `strong` — adjusted ≥ `IA_REC_THRESHOLD_STRONG` (0.85)
   - `review` — adjusted ≥ `IA_REC_THRESHOLD_REVIEW` (0.60)
   - `gap`    — best adjusted < 0.60, or empty shortlist ⇒ **candidate hymn likely absent
     from the songbook** — the audit's primary product.

Also compute the REVERSE coverage: every song whose best incoming adjusted score < 0.60 →
the "in songbook, not found in OCR" list (bad scan / missing pages / segmentation failure
indicator). Return `{candidates: [...with bestSongId/bestScore/verdict], notFoundSongs: [...],
summary: {counts per verdict, coveragePct}}`.

### 4.4 Persistence — `iaRecPersistRun(\mysqli $db, string $identifier, string $songbookAbbr, string $runSha, array $scored): bool`

(D1 option A only.) Table-existence-gated + try/catch (un-migrated ⇒ return false; page
shows an amber "results not persisted — run the IA Reconcile migration card" notice and
still renders everything). In one transaction:

1. Chunked `INSERT … ON DUPLICATE KEY UPDATE` against `uq_Item_Book_Fingerprint`, updating
   `RunSha, SegmentIndex, HeadingNumber, PageGuess, RawTitle, NormTitle, FirstLine,
   BodyExcerpt, LineStart, LineEnd, BestSongId, BestScore, Verdict` — **never**
   `ReviewState/ReviewedBy/ReviewedAt/ReviewNote` (a Phase-2 curator decision must survive
   every re-run).
2. `DELETE … WHERE Identifier=? AND SongbookAbbr=? AND RunSha <> ? AND ReviewState='none'`
   (prune stale unreviewed rows; reviewed rows persist even off-run).

All values bound via `bind_param` (rule #5); placeholder strings built with
`array_fill(0, count($values), '?')` only.

---

## §5 Admin page — `manage/ia-reconcile.php`

Copy the skeleton of `manage/intapps-status.php` (read it first — it is the house template
for a diagnostic admin page): `require includes/auth.php`, `isAuthenticated()` redirect,
entitlement 403, `$activePage = 'ia-reconcile'`, `<html lang="en">` with **no hardcoded
`data-bs-theme`** (rule #16 — `head-libs.php` runs `admin-theme-init.php`),
`head-libs.php` in `<head>`, `admin-nav.php` at top of `<body>`, `admin-footer.php` at the
bottom. No bespoke Bootstrap tags anywhere (rule #36 — head-libs owns them).

Gate (D2; default recommendation shown):

```php
if (!$currentUser || !userHasEntitlement('edit_songs', $currentUser['role'] ?? null)) { /* 403 */ }
```

### 5.1 Nav entry — `manage/includes/admin-links.php` (Catalogue group, after 'songbooks')

```php
/* IA Reconcile (#94 Phase 1) — read-only archive.org OCR audit. Gate MUST equal
   manage/ia-reconcile.php's own check (rule #1587; test-admin-gate-parity.php derives
   this pairing from the tree). */
['ia-reconcile',         '/manage/ia-reconcile',           'bi-archive',         'IA Reconcile',          'edit_songs',                  'Catalogue'  ],
```

### 5.2 Page flow

- **GET** — form card: `<select>` of songbooks (`SELECT Id, Abbreviation, DisplayAbbr, Name,
  InternetArchiveUrl FROM tblSongbooks ORDER BY DisplayOrder, Name`; label via
  `ihymns_songbook_abbr_label()` from `includes/songbook_display.php` — rule #27, never raw
  `Abbreviation` for display) + a text input for the IA identifier, server-prefilled per
  book from `ihymns_canonical_ia_identifier((string)$row['InternetArchiveUrl'])` via a
  `data-ia-identifier` attribute on each `<option>` + ~5 lines of page JS (admin pages are
  NOT under the public nonce CSP — rule #30 governs SPA fragments; the inline
  `<script type="module">` convention here matches `manage/songbooks.php:4605`).
  A "Force re-fetch from archive.org" checkbox. If persisted results exist for the selected
  pair (D1-A), render them below the form on GET too (audits are revisitable).
- **POST `action=run`** — synchronous full-page form submit ⇒ plain `validateCsrf()` is
  correct here, exactly as `intapps-status.php`'s doc-block argues (this is not a long-lived
  AJAX page; rule #29's staleness class doesn't apply). Then:
  1. `$identifier = ihymns_canonical_ia_identifier($_POST['identifier'] ?? '')` — null ⇒
     themed error card. Songbook abbr validated by existence lookup (bound query).
  2. `set_time_limit(300);` (shared hosting: a cold fetch + segment + score of a large
     book can exceed the default 30 s; cached re-runs are seconds).
  3. `iaCachedMetadata()` → `iaCachedFulltext()` (or direct `iaFetch*()` under D1-B).
     Null ⇒ themed amber card: "archive.org did not answer / item has no OCR text file
     (`_djvu.txt`) / response exceeded 15 MiB" — never a white screen, never red for an
     item that simply lacks a derived text file.
  4. `iaRecSegmentOcr()` → `iaRecSongFeatures()` → `iaRecScoreCandidates()` →
     `iaRecPersistRun()` (D1-A; false ⇒ amber not-persisted notice) → render.
  5. `logActivity('ia_reconcile.run', 'songbook', $songbookAbbr, ['identifier' => …,
     'candidates' => …, 'gaps' => …], 'success')` — same convention as intapps-status.
- **NO other POST actions. NO write buttons.** The verdict column may LINK to existing
  read surfaces (`/song/<SongId>`, `/manage/editor/?song=<SongId>`) — links, not actions.

### 5.3 Report rendering

Summary row of count cards (Candidates / Exact / Strong / Review / **Gaps** / Unscorable /
"Songbook coverage n %"), then the main table per rules #13 + #844 (must satisfy
`tests/php/test-admin-tables-sortable.php`):

```html
<div class="table-responsive">
  <table class="table table-sm align-middle cp-sortable admin-table-responsive" id="ia-reconcile-table">
    <thead><tr>
      <th data-col-priority="secondary" data-sort-key="num"     data-sort-type="number">#</th>
      <th data-col-priority="primary"   data-sort-key="title"   data-sort-type="text">OCR candidate title</th>
      <th data-col-priority="tertiary"  data-sort-key="first"   data-sort-type="text">First line</th>
      <th data-col-priority="primary"   data-sort-key="match"   data-sort-type="text">Best iHymns match</th>
      <th data-col-priority="primary"   data-sort-key="score"   data-sort-type="number">Score</th>
      <th data-col-priority="primary"   data-sort-key="verdict" data-sort-type="text">Verdict</th>
    </tr></thead>
    …
```

booted with `import { bootSortableTables } from '/js/modules/admin-table-sort.js?v=…'`
(the `manage/songbooks.php` pattern), default sort `verdict`. Verdict badges use Bootstrap
5.3 theme-aware subtle tokens (`text-bg-success-subtle`-style classes — NEVER hand-rolled
hex; rule #16). Every OCR-derived string is untrusted text:
`htmlspecialchars(…, ENT_QUOTES, 'UTF-8')` on output, no exceptions. A second, smaller
table lists the reverse direction: "In songbook but not found in OCR" (SongId, number,
title — each linking to the song page).

---

## §6 CI guard — `tests/php/test-ia-reconcile-guards.php`

House harness style (self-contained PHP CLI test, exit non-zero on failure, comment-stripped
source scans per the `test-component-json-guard.php` / `test-qr-cuercode.js` precedent).
Tree-derived and **mutation-proven before commit** (rule #34) — the commit message must
record each mutation actually performed and seen red.

1. **SSRF — functional, not just grep** (pure functions make this cheap):
   require `ia_client.php`; assert the `_iaResolveUrl()` truth table
   (`https://archive.org` ✓; `http://archive.org` ✗; `http://127.0.0.1:8099` + knob ✓ /
   − knob ✗; `ftp://…`, garbage ✗) and the `_iaDatanodeUrl()` table
   (`ia601409.us.archive.org` ✓; `archive.org.evil.example` ✗; `evil.com` ✗;
   `ia6.archive.org.` ✗; host with `@`, port, slash or `..` in dir/fileName ✗).
2. **SSRF — source assertions** on comment-stripped `ia_client.php`: exactly zero
   `CURLOPT_FOLLOWLOCATION => true`; ≥ 1 `CURLOPT_FOLLOWLOCATION => false`; every
   occurrence of `CURLOPT_SSL_VERIFYPEER` is `=> true`; zero mutating curl verbs
   (`CURLOPT_POST`, `CURLOPT_CUSTOMREQUEST`) — this client is GET-only by design.
3. **Reuse (no re-fork)** — comment-stripped scan of `includes/ia_reconcile.php` +
   `manage/ia-reconcile.php`: zero raw `levenshtein(` / `similar_text(` / `soundex(` /
   `metaphone(` calls; zero `function` definitions whose name matches
   `/normali[sz]e|jaccard|levensh/i`; ≥ 1 call each of `ihymns_sim_score(`,
   `ihymns_sim_normalise(`, `ihymns_normalize_title(`; both files `require` the two shared
   modules. Also assert `ihymns_canonical_ia_identifier()`'s regex literal equals
   `iaIdentifierValid()`'s (the deliberate duplication of §2, mechanically held in sync —
   rule #35).
4. **Read-only** — comment-stripped scan of the three new PHP files for
   `/\b(INSERT|UPDATE|DELETE|REPLACE|TRUNCATE|ALTER|DROP)\b/i` co-occurring (within the
   same statement string) with `/\btbl(Songs|SongComponents|LyricLines|Lyrics|Songbooks)\b/`
   ⇒ FAIL. Writes naming `tblIaFetchCache` / `tblIaImportCandidates` are the only ones
   permitted, and only in `ia_client.php` / `ia_reconcile.php` (not the page).
5. **Not-public** — tree-derived: grep every file under `appWeb/public_html` for
   `ia_client.php` / `ia_reconcile.php` requires; assert the requiring set ⊆
   `{manage/ia-reconcile.php, includes/ia_reconcile.php, tests/*}`. This is what makes the
   relaxed 60 s timeout band safe: the client is provably unreachable from any public path.
6. **Segmenter behaviour** — feed `tests/php/fixtures/ia-fixture-hymnal_djvu.txt` (hand-built,
   ~250 lines: 6 numbered hymns across 3 `\f` pages, a running header on every page, one
   hymn spanning a page break, interleaved page numbers, one hyphen-broken word, one
   ALL-CAPS unnumbered hymn, and an "INDEX OF FIRST LINES" section) through
   `iaRecSegmentOcr()` and assert: exactly 7 candidates; the span-page-break hymn is ONE
   candidate; no candidate originates in the index region; page numbers appear as no
   candidate's `headingNumber`; the de-hyphenated word is whole in its `firstLine`.
   Then score the candidates against a small in-array feature set (no DB) and assert one
   `exact`, one `gap`, one `unscorable` (a Greek-script row).
7. **Mutation checklist** (perform, watch red, revert, record in the commit body):
   flip `FOLLOWLOCATION` to true → (2) red; weaken `_iaDatanodeUrl` to accept any host →
   (1) red; inline a `levenshtein(` in `ia_reconcile.php` → (3) red; add an
   `UPDATE tblSongs` string to the page → (4) red; `require ia_client.php` from
   `api.php` → (5) red; delete the index-suppression stage → (6) red.

Migration/schema/registry coverage arrives free from the existing
`test-schema-coverage.php` + `test-migration-registry.php`; nav/gate parity from
`test-admin-gate-parity.php`; table sortability from `test-admin-tables-sortable.php`.
Register the new test wherever those PHP tests are wired into CI (mirror how
`test-intapps-guards.php` is registered — same list, same mechanism; do NOT hand-maintain a
second list).

### 6.4 Fixture-injection seam (the sandbox constraint)

This sandbox (and CI) has **no archive.org access**. Two seams, both already precedented:

- **Pure-function tests** (§6.6) need no HTTP at all — the segmenter/scorer take a string.
  This is the primary seam and covers all reconcile logic.
- **Client E2E** (optional, mirrors `tests/php/fixtures/intapps-stub-gateway.php` +
  `test-intapps-stub-e2e.php`): a PHP built-in-server stub serving
  `GET /metadata/<id>` → `ia-fixture-metadata.json` and
  `GET /download/<id>/<id>_djvu.txt` → the fixture blob (200, no redirect); the test sets
  `ia_base_url = http://127.0.0.1:<port>` + `ia_allow_loopback = '1'` and exercises
  `iaFetchMetadata()` / `iaFetchFulltext()`'s loopback branch end-to-end. The datanode
  branch stays covered by `_iaDatanodeUrl()`'s pure truth table — a loopback stub can
  never satisfy the `.archive.org` suffix, by design.

---

## §7 Phase 2 sketch — DESIGN ONLY, DO NOT BUILD

For the record, so Phase-1 shapes provably don't paint it into a corner:

1. Curator opens the persisted audit, flips a `gap` row's `ReviewState` →
   `approved_for_import` (vocab already on `tblIaImportCandidates`; VARCHAR ⇒ no ALTER).
2. Approval enqueues moderation via the EXISTING #1066/#1695 infra: a draft `tblLyrics`
   row + `tblLyricsReviewQueue` entry with `QueuedReason = 'ia_ocr_import'` (that column's
   comment says "app-validated" VARCHAR — adding the reason is an allow-list line, no
   migration), and the verbatim OCR segment lands in `tblLyricsSourceDocuments`
   (`Format='ia-djvu-txt'`, `Source='internet-archive'`, `SourceUrl` the details URL,
   `Sha256` — the #1143 lossless-provenance store, already built).
3. On queue approval, the song is created through the normal editor write core and lyric
   lines flow through **the ONE write path** `lyricLinesWriteComponents()`
   (`includes/lyric_lines_sync.php`, rule #25) — never a bespoke INSERT.
4. Eligibility guard: import offered only where the songbook has `IsPublicDomain = 1`
   (exists, #1765 Feature 2) or per-song PD facts are confirmed — a Phase-2 product
   decision for the owner, flagged now.
5. `ReviewState = 'imported'` + `BestSongId` backfilled closes the loop.

Phase 1 ships none of this — no approve buttons, no queue writes, no lyric writes.

---

## §8 Adversarial stress — what would force a second migration / rework?

- **A. Multi-volume IA items / several items per book** — covered: candidates key on
  `(Identifier, SongbookAbbr)`, N:M by construction; the page accepts a free-typed
  identifier per run; runs accumulate per pair. `InternetArchiveUrl` holding one URL is a
  prefill convenience, not a constraint. *No migration.*
- **B. Non-English / non-Latin OCR** — `ihymns_sim_fold()`'s `iconv(…TRANSLIT…)` strips
  Greek/Cyrillic/CJK to near-nothing ⇒ scores collapse. Handled honestly: the `unscorable`
  verdict (§4.3 step 2) surfaces it instead of fabricating gaps. A future script-aware
  scorer is an `ihymns_sim_*` extension (shared module), not a schema change. *No migration.*
- **C. OCR garbage / hyphenation / long-s / rn→m** — de-hyphenation + Levenshtein tolerance
  + tunable thresholds; systematic garbage skews verdicts toward `gap`, and the REVERSE
  coverage list (songs not found in OCR) is the built-in tell that the scan, not the
  catalogue, is the problem. *No migration.*
- **D. Songs spanning page breaks** — running-header removal (§4.1-3) before segmentation;
  `\f` is a page marker, never a segment boundary by itself. *No migration.*
- **E. Same hymn under variant titles** — two-orientation scoring + article-strip in
  `ihymns_sim_normalise()` + first-line comparison. Residual misses land in `review`, which
  is the verdict built for exactly that. *No migration.*
- **F. Later need for page-precise anchors (djvu.xml / search-inside)** — a new
  `tblIaFetchCache.Kind` value (VARCHAR) + per-candidate coords in `MetaJson`. *No ALTER —
  by design.*
- **G. Payload poisoning / size** — 15 MiB byte-cap < MEDIUMTEXT's 16,777,215; UTF-8
  sanitisation before INSERT (mysqli STRICT + utf8mb4 throws on malformed bytes — the
  sanitise step in `iaFetchFulltext()` is load-bearing, not cosmetic); datanode host
  suffix allowlist defeats SSRF-via-metadata; aborting write-callback defeats
  memory-exhaustion (#929 class).
- **H. Shared-hosting timeouts** — `set_time_limit(300)` + cache-first: the slow path runs
  once per (identifier, 30 days); a timeout mid-run leaves at worst a cache row (upsert,
  self-healing) and zero candidates (transactional §4.4).
- **I. Un-migrated installs (the D1 class)** — every table access INFORMATION_SCHEMA-gated
  + try/catch; page degrades to fetch-direct + not-persisted notice. Never a 500.
- **J. The sandbox/network constraint** — all logic testable via the pure seam (§6.4);
  the ONLY thing that genuinely cannot be verified before deploy is live archive.org
  behaviour (real 302s, real datanode hostnames, real `_djvu.txt` shapes). **File a
  tracked follow-up issue at build time** for a first live smoke-run on alpha
  (fetch one known PD hymnal, e.g. any IA scan of a pre-1929 hymnal, and eyeball the
  report) — the standing-tasks rule: never claim live-verified when it wasn't.
- **What WOULD force rework:** IA retiring the metadata API or `_djvu.txt` derivatives
  (client-only change); a decision to audit at per-PAGE grain (new Kind + MetaJson, still
  no ALTER); merging with a future generic "external source reconcile" for other archives
  (the tables are IA-named — a rename/generalise would be a real second migration; accepted
  consciously: #94 is IA-specific and a speculative `tblExternalSource*` generalisation now
  would be a guessed bridge, which rule #20 also forbids).

---

## §9 Build order (one PR, atomic commits — CLAUDE.md commit rules)

1. `includes/ia_client.php` + `ihymns_canonical_ia_identifier()` (+ its unit rows in the
   guard file as it grows).
2. `migrate-ia-reconcile.php` + `schema.sql` mirror + registry entry **in the same commit**
   (rule #19). [Skip under D1-B.]
3. `includes/ia_reconcile.php` + fixtures.
4. `manage/ia-reconcile.php` + `admin-links.php` row.
5. `tests/php/test-ia-reconcile-guards.php` — with the §6.7 mutation log in the commit body.
6. Audit pass (php -l over appWeb, node --check over js), then the standing-tasks checklist:
   tracking issue updates on #94/#1795, the live-smoke follow-up issue (§8-J), wiki + docs.

Annotation standard applies throughout: ELI5 + detailed doc-blocks with links (PHP manual
curl/levenshtein pages, archive.org metadata-API docs URL
`https://archive.org/developers/metadata-schema/` in the client header, `#94`/`#1795`
issue refs).
