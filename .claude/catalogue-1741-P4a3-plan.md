# P4a-3 — Writer/person page consolidation: `/writer/<slug>` → `/musician/<slug>` (BUILD SPEC)

**Epic:** #1741 · **Owner decision:** D4 (2026-08-03) — CONSOLIDATE. `/writer/<slug>` 301-redirects to
`/musician/<slug>`; sitemap emits `/musician/` going forward; `/writer/<slug>` stays resolvable forever
(rule #33 — links outlive code). Last dev slice of #1741.
**Branch:** `claude/wave3-fixes` · **Spec status:** ready to implement — all decisions taken below; the
implementer executes, they do not re-decide.

Every file:line in this spec was verified against the working tree on 2026-08-03.

---

## 0. Verified surface map — including corrections to the framing this task arrived with

The routing surface as it ACTUALLY is (several details in the task briefing were stale/wrong):

| Claim in briefing | Reality in the tree |
|---|---|
| "musician.php resolves `params.slug` → **tblCreditPeople**.Slug" | The registry table is **`tblMusicians`** (renamed from Credit People by #1741 P2-B — see `appWeb/.sql/schema.sql`, `CREATE TABLE tblMusicians`, `Slug VARCHAR(255) NULL UNIQUE`). There is **no** `credit_people_helpers.php`; the shared helper file is `appWeb/public_html/includes/musician_helpers.php` (required globally by `api.php:272`). |
| "A `/writer/<name-slug>` does NOT trivially equal a `/musician/<registry-slug>`" | True — **but `/musician/` already receives name-slugs as first-class traffic today.** The song page's credit links are built with the *writer* fold, not the registry slug: `includes/pages/song.php:700` and `:1252` emit `href="/musician/<?= urlencode(strtolower(str_replace(' ', '-', $name))) ?>"`. That is exactly why `includes/pages/musician.php:28-33,103-106` already carries a name-based fallback. So the resolution ladder built here fixes a live gap on `/musician/` too (a punctuated name like "Charles H. Gabriel" folds to `charles-h.-gabriel`, which matches neither the registry slug `charles-h-gabriel` nor anything else — today that renders the bare fallback even though a registry profile exists). One ladder serves both routes (rule #22). |
| "#1679 songbook-move redirect-row mechanism" | The redirect-row mechanism is `includes/song_redirects.php` (`tblSongRedirects`) — that is **#1343/#1689** (song permalinks); #1679 A13b only contributed the strict-probe reasoning (`song_redirects.php:36-55`). It is song-id-scoped and is **not reused here** — see decision D-2 for why no `tblWriterRedirects` table is created. What IS reused from it is the *pure-ladder + injected-lookup* testability pattern (`songRedirectFollow()`, `song_redirects.php:94-117`). |
| Line numbers given (`api.php` ~:669/:680/:1504, `router.js` ~:318/:320/:649, `sitemap.xml.php:155`) | All confirmed accurate: `api.php:669` (`case 'writer'`), `:680` (`case 'musician'` PAGE case), `:1503-1504` (`case 'credit_person'`/`case 'musician'` ACTION case — untouched by this work), `router.js:318-319` / `:320-331` / `:649-650`, `sitemap.xml.php:155`. |

Additional load-bearing facts verified:

- **The 301 precedent already exists**: `index.php:475-493` issues a real `header('Location: … /musician/…', true, 301)` for `/people/<slug>` and `/person/<slug>` top-level hits. `index.php` is only ever reached by genuine top-level HTTP requests (crawler / fresh navigation / shared link) via the `.htaccess` SPA catch-all (`RewriteRule ^ index.php [QSA,L]`); the SPA's fragment fetches go to `/api` and never touch it. This is the mechanism to mirror.
- **The client-side canonicalisation precedent already exists**: `router.js:700-720` — the #1343 `[data-song-redirect]` marker (navigate `{replace:true}`) and the #1343-B `[data-song-canonical]` marker (`history.replaceState`, no reload). This is the mechanism to mirror for the SPA path.
- **Fragment caching**: `writer` and `musician` are both in `$_cacheablePages` (`api.php:584-597`); the ETag is `hash('xxh64', $page.'|'.QUERY_STRING.'|'.$body)` (`api.php:798-811`) — per-URL, content-deterministic. The `page=person|people` → `musician` normalisation happens at `api.php:410-421`, *before* the cache logic.
- **Service worker**: navigations are fetched `cache:'no-store'` and **redirects are deliberately never cached** (`service-worker.js.php` ~:948-957, #140) — a 301 from `index.php` cannot get pinned in the SW cache.
- **AASA** (`.well-known/apple-app-site-association.php:136-137`) claims `/person/*` and `/musician/*` only — **no** `/writer/*` claim, so no AASA change.
- **Nothing inside the app emits `/writer/` links.** Full-tree grep: the only emitter is `sitemap.xml.php:151-160`. (`router.js:318`, `api.php:669` and comments are handlers, not emitters.) External links + crawler indexes are the audience for the redirect.
- **`getRequestPath()` (`includes/config.php:620-633`) does NOT urldecode** — the path arrives percent-encoded. Writer slugs can contain dots and encoded bytes (the sitemap fold at `sitemap.xml.php:153` is `rawurlencode(strtolower(str_replace(' ', '-', $writer)))`, which preserves `.` and non-ASCII). The existing person-301 regex `([a-z0-9\-]+)` would NOT match these — the new writer branch must use `([^/]+)` + `rawurldecode()`.
- **🐛 Pre-existing bug found during this investigation** (file an issue at implementation time, per standing-tasks §2): the `#btn-edit-musician` reveal at `router.js:790-798` sits **inside the `if (page === 'song')` branch** (`:700-:845`), while the element only exists in the musician fragment (`musician.php:575`). The reveal is dead code; the musician page's Edit button never appears for admins. Fixed in step S5 below as part of the same `afterPageLoad` edit.

---

## 1. Design

### 1.1 Where the redirect lives — one mechanism per request path

This SPA has three distinct ways a `/writer/<slug>` request arrives, and each needs its own leg of the
"301" (mirroring how #1343/#1343-B and the person/people rename each did it — never inventing a new
mechanism):

| Path | Mechanism | Precedent mirrored |
|---|---|---|
| **A. Top-level HTTP hit** (crawler, shared link, cold navigation) → `.htaccess` catch-all → `index.php` | New `elseif` branch in `index.php`'s route chain: resolve the writer slug → registry slug via the shared ladder; on success emit a **real HTTP 301** `Location: /musician/<registry-slug>` and `exit`. On no-match: fall through and serve the SPA shell (no redirect — the fragment fallback renders). | `index.php:490-493` (the `/people\|/person` 301) |
| **B. SPA fragment fetch** (`/api?page=writer&id=<slug>` from `router.js`) | `api.php`'s `case 'writer'` keeps its route + `id` param (rule #33) but now serves the **musician fragment**: it sets `$personSlug = $writerId` and requires `includes/pages/musician.php` (which resolves via the same ladder internally). No fragment-level HTTP redirect — a redirect response would fight the SW's no-redirect-caching rule and the ETag tail for zero benefit. | The `case 'request'`/`case 'request-a-song'` alias-to-one-partial shape (`api.php:784-790`) |
| **C. Address-bar canonicalisation in the SPA** (the user is *on* `/writer/<slug>` client-side) | The musician fragment emits `data-musician-canonical="/musician/<registry-slug>"` on `.page-musician` **whenever a registry row rendered**; `router.js`'s `afterPageLoad()` does `history.replaceState` to that path when it differs from `location.pathname`. `replaceState` (not `pushState`/navigate) — no reload, no back-button trap, and no second fetch. This also canonicalises `/musician/<name-slug>` hits from song-page credit links and `/person|/people` client-side navigations for free. | `router.js:709-720` (`[data-song-canonical]`, #1343-B) |

Why a fragment can carry this marker at all: `data-*` on a shared-cache fragment is the ONE sanctioned
channel for fragment→router signalling (rule #30's `home-page.js` pattern); the value depends only on
the request URL, so it is deterministic and shared-cache-safe (rule #6). No inline `<script>`, no nonce.

### 1.2 The shared resolver — `includes/musician_helpers.php`

Three new functions, modelled on `song_redirects.php`'s pure/driver split so the ladder is
unit-testable without a DB (the #1689 lesson: a runtime handle, not source-grepping):

```php
/**
 * PURE — build the lookup plan for a legacy /writer/ (or name-slug /musician/) slug.
 * Inverts the ONE fold every emitter uses — strtolower(str_replace(' ', '-', $name))
 * (sitemap.xml.php:153 historic, song.php:700/:1252 current).
 *
 * @param string $slug ALREADY-URLDECODED slug segment.
 * @return array{slugs: list<string>, names: list<string>}
 *   slugs — ordered candidate registry slugs (exact first, punctuation-fold second);
 *   names — ordered candidate credited/registry names (title-cased spaced, then the
 *           raw slug for single-token hyphenated names like "Smith-Jones"),
 *           deduped case-insensitively (the DB collation is CI).
 */
function musicianLegacySlugPlan(string $slug): array
{
    $slug   = trim($slug);
    $spaced = str_replace('-', ' ', $slug);

    $slugs = [];
    foreach ([$slug, slugifyMusicianName($slug)] as $c) {
        if ($c !== '' && !in_array($c, $slugs, true)) { $slugs[] = $c; }
    }

    $names = [];
    $seen  = [];
    foreach ([mb_convert_case($spaced, MB_CASE_TITLE, 'UTF-8'), $spaced, $slug] as $c) {
        $c = trim($c);
        $k = mb_strtolower($c);
        if ($c === '' || isset($seen[$k])) { continue; }
        $seen[$k] = true;
        $names[]  = $c;
    }
    return ['slugs' => $slugs, 'names' => $names];
}

/**
 * PURE ladder with injected lookups (the songRedirectFollow() pattern, #1689).
 * @param callable(string):?string       $bySlug  registry Slug for an exact-slug candidate, or null.
 * @param callable(list<string>):?string $byName  registry Slug for a Name IN (…) match, or null.
 * @return ?string The canonical tblMusicians.Slug, or null when nothing resolves.
 */
function musicianResolveLegacySlug(callable $bySlug, callable $byName, string $slug): ?string
{
    $plan = musicianLegacySlugPlan($slug);
    foreach ($plan['slugs'] as $cand) {
        $hit = $bySlug($cand);
        if (is_string($hit) && $hit !== '') { return $hit; }
    }
    if ($plan['names'] !== []) {
        $hit = $byName($plan['names']);
        if (is_string($hit) && $hit !== '') { return $hit; }
    }
    return null;
}

/**
 * DB driver. FAIL-OPEN reader (the songRedirectClaimsId() reasoning,
 * song_redirects.php:186-208): a pre-Slug-migration install, an absent table,
 * or a transient probe failure all answer null — the caller then degrades to
 * the name-based fallback exactly as the un-migrated install always has.
 */
function musicianResolveLegacySlugDb(\mysqli $db, string $slug): ?string
{
    try {
        return musicianResolveLegacySlug(
            static function (string $cand) use ($db): ?string {
                $st = $db->prepare('SELECT Slug FROM tblMusicians WHERE Slug = ? LIMIT 1');
                $st->bind_param('s', $cand);
                $st->execute();
                $row = $st->get_result()->fetch_row();
                $st->close();
                return ($row !== null && (string)$row[0] !== '') ? (string)$row[0] : null;
            },
            static function (array $names) use ($db): ?string {
                /* Placeholder string from count() — the rule-#5-sanctioned interpolation. */
                $ph = implode(',', array_fill(0, count($names), '?'));
                $st = $db->prepare(
                    "SELECT Slug FROM tblMusicians
                      WHERE Name IN ($ph) AND Slug IS NOT NULL AND Slug <> ''
                      ORDER BY Id ASC LIMIT 1"
                );
                $st->bind_param(str_repeat('s', count($names)), ...$names);
                $st->execute();
                $row = $st->get_result()->fetch_row();
                $st->close();
                return $row !== null ? (string)$row[0] : null;
            },
            $slug
        );
    } catch (\Throwable $_e) {
        return null;
    }
}
```

Ladder order and why (the load-bearing decisions):

1. **Exact registry slug** — a `/writer/john-newton` whose slug happens to equal the registry slug
   (the overwhelmingly common case for simple names) resolves in one indexed hit; also makes the
   whole ladder idempotent (feeding a resolved slug back through resolves to itself).
2. **Punctuation/diacritic fold** — `slugifyMusicianName()` (`musician_helpers.php:899-908`; lowercases,
   NFKD-strips combining marks, collapses non-letter/digit runs to single hyphens). This is THE
   existing slug fold, the one `generateUniqueMusicianSlug()` and the backfill migration use — reused,
   never re-forked (rule #22). It turns `charles-h.-gabriel` → `charles-h-gabriel` and
   `söderberg` → `soderberg`, closing the writer-fold ↔ registry-fold gap.
3. **Registry Name match** — covers rows whose slug was collision-suffixed (`john-smith-2`) or
   otherwise diverges. Candidates are exactly `writer.php:28-32`'s historic variant set (title-cased
   spaced, spaced, raw slug), CI-deduped. `ORDER BY Id ASC LIMIT 1` for determinism (Name duplicates
   are prevented at add-time by the 409 in `admin_musician_add`, `api.php:14971`, but determinism
   costs nothing).
4. **Aliases are deliberately NOT a rung** — see decision D-4 below.

### 1.3 Fragment consolidation — `includes/pages/writer.php` is **DELETED**

D4 says "fold in", and the fold is genuinely subsuming: `musician.php`'s existing no-registry fallback
(`musician.php:103-106` + the role loop `:229-252`) already renders a bare discography for a name-slug
across **six** credit roles, where `writer.php` covered two (writers + composers via
`SongData::getSongsByCreditName()`, `SongData.php:2293`). Only one behavioural gap exists, and step S3
closes it:

- **The gap:** the fallback's role queries match `WHERE c.Name = ?` with only the title-cased spaced
  name (`_personSlugToName()`, `musician.php:41-45`), while `writer.php` also tried the **raw slug** as
  a name — the variant that matches a credited name which itself contains hyphens ("Smith-Jones" →
  slug `smith-jones` → spaced "Smith Jones" ≠ "Smith-Jones"). Without closing it, a `/writer/smith-jones`
  that used to render a list would 404 — the exact silent breakage D4 forbids.
- **The closure:** when (and only when) **no registry row matched**, the fallback role queries bind
  `Name IN (<musicianLegacySlugPlan($personSlug)['names']>)` instead of `= $personName`. When a registry
  row DID match, the discography stays keyed on the registry `Name` exactly as today (a one-element
  `IN` — one query shape for both branches). Case-insensitivity is already provided by the tables'
  `utf8mb4` CI collation (same property `getSongsByCreditName()` made explicit with `LOWER()`).

Presentation notes (accepted, not to be "fixed"): the old writer page grouped by songbook and showed a
Words/Music badge per song; the musician page groups by role. That layout difference is the point of the
consolidation — the owner chose the musician layout. `writer.php`'s 404 semantics (no credits → themed
404 card) are preserved by `musician.php:396-413` (404 when neither registry row nor any credited song).

Nothing else references `writer.php`: no CSS/JS targets `.page-writer` (verified), no test names the
file (verified — `tests/php/test-tune-lockstep.php`'s `writer.php` is its own fixture file, unrelated;
`test-tag-route.js` mentions it only inside label strings), the orphan allowlist
(`tests/php/fixtures/orphan-allowlist.php`) is DB-object-scoped, and `test-fragment-inline-scripts.php`
globs `includes/pages/` so a deleted file simply leaves the scan set. Deletion is clean.

### 1.4 Sitemap — `sitemap.xml.php`

- **Remove** the `$allWriters` collection (`:120`, `:141-147`) and the whole "Writer pages" block
  (`:151-160`).
- **Add** a "Musician pages" block that iterates the **registry**, not the heuristic name fold:

```php
/* --- Musician pages (#1741 P4a-3, owner decision D4) ---
   Registry-driven: one <loc> per tblMusicians row with a slug. Replaces the
   heuristic per-credited-name /writer/ emission — /writer/<slug> now 301s to
   /musician/<slug> (index.php), and already-indexed /writer/ URLs converge on
   the canonical form through that redirect. Schema-tolerant: a pre-Slug-
   migration install (or the DB-outage static-only path above) simply emits no
   musician URLs rather than fataling. */
try {
    $mus = getDbMysqli()->query(
        "SELECT Slug FROM tblMusicians WHERE Slug IS NOT NULL AND Slug <> '' ORDER BY Slug"
    );
    while ($row = $mus->fetch_assoc()) {
        $urls[] = [
            'loc'        => $baseUrl . '/musician/' . rawurlencode((string)$row['Slug']),
            'lastmod'    => $today,
            'changefreq' => 'monthly',
            'priority'   => '0.5',
        ];
    }
    $mus->close();
} catch (\Throwable $_e) { /* pre-migration / outage — omit musician URLs */ }
```

Place it after the song loop. Guard it in its own try/catch (the file's `$songData` outage path at
`:66-75` leaves `$songbooks = []` but the script continues, so this block must not assume a live DB).
`db_mysql.php` is already required at `sitemap.xml.php:21`.

### 1.5 Decisions taken (do not re-litigate at implementation time)

| # | Decision | Why / rejected alternative |
|---|---|---|
| D-1 | **No fragment-level HTTP redirect** on `/api?page=writer`; the writer case serves the musician fragment directly and the marker canonicalises the address bar. | A 301 from the fragment endpoint would (a) interact with the ETag tail (`api.php:798-811`) which assumes a body, (b) be deliberately uncacheable by the SW (#140), forcing a two-request cost on every SPA visit forever, and (c) still not update the address bar (fetch follows redirects transparently). The real 301 lives where crawlers actually knock: `index.php`. |
| D-2 | **No redirect table** (`tblWriterRedirects` or rows in a generic table). Resolution is computed live from the registry every time. | The writer→musician mapping is *derivable* (a name fold), unlike song merges which destroy information and therefore need `tblSongRedirects`. A materialised row would need a writer on every musician rename/re-slug — a new cross-file-agreement burden (rule #35) for zero gain. No schema change also means no migration card, no `schema.sql` edit (rule #19 trivially satisfied). |
| D-3 | **Sitemap emits registry rows only.** Credited names with no registry row are no longer advertised to crawlers. | Advertising `/writer/<name-slug>` for non-registry names would keep crawlers on a deprecated URL shape serving thin duplicate content. Those URLs remain *resolvable* (leg A falls through to the shell; leg B renders the fallback discography) — rule #33 honoured — they're just not promoted. The curation path to visibility is promoting the name into the registry (`/manage/musicians-bulk-promote` exists precisely for this). |
| D-4 | **Aliases (`tblMusicianAliases`) are NOT a resolution rung.** | An alias-name redirect would land on a profile whose discography (keyed on registry `Name`, `musician.php:231-235`) may not contain the songs credited under the alias spelling — the redirect would *look* like it lost songs. Left for a follow-up issue if wanted; the ladder's structure (one more `$byName` call) makes it a one-rung addition later. |
| D-5 | **Accepted consequence:** when a variant-spelt credited name slug-collides with a registry row's slug (credited "Charles H Gabriel", registry "Charles H. Gabriel" → both fold to `charles-h-gabriel`), the old `/writer/charles-h-gabriel` listed the variant's credits; the new 301 lands on the registry profile, which lists the registry-Name credits. Same person, editorially correct target; the credit-spelling mismatch is a data-hygiene item for the musicians admin's rename/merge tooling, and `/musician/charles-h-gabriel` has behaved this way all along. Do NOT widen the registry-row discography to name variants — that would make the fragment's content differ between the writer-URL and musician-URL cache entries for the same person, which un-does the canonicalisation. |
| D-6 | **`router.js` keeps `case 'writer'` and `updateTitle`'s `'writer'` entry** (`router.js:318-319`, `:649`); `api.php` keeps `'writer'` in `$_cacheablePages` and the `id` param name. | Rule #33: the URL and its param are contracts. The route must parse before it can be canonicalised. |
| D-7 | **`index.php`'s `/musician/<slug>` JSON-LD branch (`:500-562`) is NOT changed** to use the ladder in this slice. | Scope control — it affects only crawler JSON-LD richness for name-slug URLs, which now converge on registry slugs anyway via leg C + the sitemap. File as a follow-up (see §6). |

---

## 2. Adversarial pass — "what would break a real `/writer/` link or a crawler?"

Each scenario traced end-to-end against the design; the resolution column is what the implementation
must actually do.

| # | Scenario | Outcome under this design |
|---|---|---|
| A1 | Crawler hits `/writer/john-newton`, registry row exists (slug `john-newton`) | `.htaccess` catch-all → `index.php` writer branch → ladder rung 1 → **`301 Location: https://<canonical-host>/musician/john-newton`**. Crawler converges on one indexed URL. |
| A2 | Crawler hits `/writer/charles-h.-gabriel` (dot preserved by the old sitemap fold) | Regex `([^/]+)` matches; `rawurldecode` → rung 2 fold → 301 to `/musician/charles-h-gabriel`. **The person-branch regex `[a-z0-9\-]+` must NOT be copied** — it would silently skip every punctuated slug (no match → shell → no redirect → crawler keeps the old URL indexed). |
| A3 | Crawler hits `/writer/<name>` with **no registry row but real credits** | No 301 (nothing to redirect TO). `index.php` falls through → 200 + shell (unchanged from today's behaviour for ALL `/writer/` URLs, which never had a server-rendered body). The SPA fragment renders the six-role fallback discography — richer than the old two-role list. **Not a silent 404.** |
| A4 | `/writer/<garbage>` — no registry row, no credits | Shell 200; fragment answers 404 with the themed error card (`musician.php:396-413`) — same UX class as the old `writer.php:89-108`. |
| A5 | **Redirect loop risk** | Impossible by construction: `/musician/<registry-slug>` never redirects (leg A only fires on the `/writer/` prefix; rung 1 makes the ladder idempotent). Client leg C replaceStates only when the path differs, and the canonical fragment's marker equals its own path → no-op. The SW never caches the 301 (#140). |
| A6 | **Un-migrated install** (no `tblMusicians.Slug` column / no table) | `musicianResolveLegacySlugDb()` catches and returns null on every leg → no 301, fragment falls to name-based fallback (which `musician.php:54-101` already guards with its own try/catch) → behaves exactly like today's writer page. Sitemap block catches → no musician URLs. Nothing throws under STRICT mysqli (the #1228 lesson). |
| A7 | **DB outage** | `index.php`: `new SongData()` at `:276` throws first and the outer catch at `:674` serves the generic shell — the writer branch is never reached (and its own local try/catch covers a mid-request drop). `api.php`: the existing 500/503 fragment path (`:551-565`). Sitemap: static-only mode (`:66-75`) + the new block's own catch. |
| A8 | **Offline PWA** navigates to `/writer/<slug>` | SW serves cached shell → router fetches fragment from SW cache (cache key `/api?page=writer&id=…` — still valid, still served) → leg C canonicalises if the cached fragment carries the marker. Degrades identically to every other offline page. |
| A9 | **Shared-cache poisoning / per-user leakage** | The marker value is a pure function of the request URL; the fragment stays user-independent. `Vary: Cookie, Authorization` unchanged. Nothing per-user enters the writer/musician fragments (rule #6). |
| A10 | **CSP** | No new inline `<script>` anywhere; the only `<script>` in `musician.php` remains the inert `application/ld+json` (`:928-931`), which `test-fragment-inline-scripts.php` exempts. Leg C is wired from `afterPageLoad()` — the rule-#30 pattern. |
| A11 | **Encoded non-ASCII slugs** (`/writer/%E4%B8%BB...`) | Leg A: `([^/]+)` matches the encoded segment, `rawurldecode` restores it, `slugifyMusicianName()` preserves `\p{L}` so CJK slugs fold correctly. Leg B: browser/`URLSearchParams` encoding → PHP `$_GET` decodes once → same input. (The old `writer.php:21` `urldecode()` on already-decoded `$_GET` was a latent double-decode; the new path decodes exactly once per leg — a deliberate, strictly-safer simplification.) |
| A12 | **`/writer/<slug>/` trailing slash** | `getRequestPath()` rtrims (`config.php:628-630`) — leg A unaffected. |
| A13 | Host-header games on the 301 | `getCanonicalUrl()` builds `Location:` from the allow-listed `appCanonicalHost()` (`config.php:651-676`) — same protection the person-301 already has. |
| A14 | The Apple app's Universal Links | AASA claims `/person/*` + `/musician/*` only; `/writer/` was never claimed — no change, nothing breaks. |
| A15 | A future session "cleans up" the writer case/route as dead code | The CI guard (§4) fails: `router.js case 'writer'`, `api.php case 'writer'`, and the `index.php` 301 branch are each individually asserted, and each assertion has been proven RED (§4.3). |
| A16 | `page=writer` with empty `id` | 400 + warning div, unchanged (`api.php:672-676` shape retained). |

---

## 3. Implementation steps

One PR to `alpha` (repo rule: one PR, atomic commits). Suggested commit split: S1-S3 (resolver +
fragment fold), S4-S5 (routing + router), S6 (sitemap), S7 (tests). All new/changed code gets the
project-standard two-register annotations (ELI5 + detailed why, with `#1741` / rule references).

### S1 — `appWeb/public_html/includes/musician_helpers.php`
Append the three functions from §1.2 verbatim (plus full project-standard doc-blocks). Place them near
`slugifyMusicianName()` (`:899`) so the fold family reads together.

### S2 — `appWeb/public_html/includes/pages/musician.php`
1. **Resolve through the ladder before the registry SELECT.** Immediately after `$db = getDbMysqli();`
   (`:49`), insert:
   ```php
   /* #1741 P4a-3 — legacy /writer/<name-slug> requests (api.php case 'writer')
      and name-slug /musician/ credit links (song.php:700/:1252) resolve to the
      registry row through the ONE shared ladder (musician_helpers.php).
      Fail-open: null on an un-migrated install → the name-based fallback below,
      exactly as before. */
   if (!function_exists('musicianResolveLegacySlugDb')) {
       require_once dirname(__DIR__) . '/musician_helpers.php';
   }
   $_resolvedSlug = musicianResolveLegacySlugDb($db, $personSlug);
   if ($_resolvedSlug !== null) { $personSlug = $_resolvedSlug; }
   ```
   The existing `WHERE Slug = ?` lookup (`:80-97`) then runs with the resolved slug, unchanged.
2. **Widen the no-registry fallback to the historic writer variants.** Before the role loop (`:229`),
   compute:
   ```php
   /* #1741 P4a-3 — registry row: exact registry Name (unchanged). No registry
      row: the historic writer.php variant set, so a credited name containing
      hyphens ("Smith-Jones") still lists (the D4 no-silent-404 guarantee).
      One IN() query shape for both branches; CI collation does the case fold. */
   $creditNames = $person !== null
       ? [$personName]
       : musicianLegacySlugPlan($personSlug)['names'];
   ```
   and change the role query (`:231-235`) to `WHERE c.Name IN (<placeholders from count($creditNames)>)`
   with all names bound (`str_repeat('s', count($creditNames))`), keeping the `songVisibleSql()`
   predicate and ordering byte-identical.
3. **Emit the canonical marker.** Change the section open tag (`:479`) to:
   ```php
   <section class="page-musician"<?= $person !== null
       ? ' data-musician-canonical="/musician/' . htmlspecialchars(rawurlencode($personSlug)) . '"'
       : '' ?> aria-label="<?= htmlspecialchars($personName) ?>">
   ```
   (Marker only when a registry row rendered — a fallback page has no canonical musician URL.)

### S3 — delete `appWeb/public_html/includes/pages/writer.php`
`git rm`. Its doc-comment history (the #929 OOM note, the #1705 error-card note) is preserved in git;
`getSongsByCreditName()` (`SongData.php:2293`) loses its only caller — **leave it in place** and note it
in the PR (it is the documented scoped-read exemplar; removing it is a separate simplify decision, not
this slice's).

### S4 — `appWeb/public_html/api.php`
Replace the body of `case 'writer':` (`:669-678`) with:
```php
case 'writer':
    /* #1741 P4a-3 (owner decision D4) — /writer/<name-slug> is consolidated
       into the musician profile page. The route + its `id` param are a
       shipped contract (years of sitemap emissions + external links — rule
       #33), so the case stays forever; it now serves the musician fragment,
       whose internal ladder (musician_helpers.php) maps the name-slug to a
       registry row and whose data-musician-canonical marker lets router.js
       canonicalise the address bar. Top-level HTTP hits get a real 301 in
       index.php instead — this fragment path is only ever fetched by the SPA. */
    $writerId = isset($_GET['id']) ? trim($_GET['id']) : '';
    if ($writerId === '') {
        http_response_code(400);
        echo '<div class="alert alert-warning" role="alert">Writer ID is required.</div>';
        break;
    }
    $personSlug = $writerId;   /* musician.php resolves via the shared ladder */
    require __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'pages' . DIRECTORY_SEPARATOR . 'musician.php';
    break;
```
No other `api.php` change: `'writer'` stays in `$_cacheablePages` (`:586`), the person/people
normalisation (`:419-421`) and the musician ACTION case (`:1503`) are untouched.

### S5 — `appWeb/public_html/index.php` + `appWeb/public_html/js/modules/router.js`
1. **`index.php`** — insert a new `elseif` branch in the route chain immediately BEFORE the
   `/people|/person` 301 branch (`:490`), so the two legacy-redirect branches sit together:
   ```php
   /* #1741 P4a-3 (owner decision D4) — /writer/<name-slug> is retired in favour
      of /musician/<registry-slug>. A resolvable slug gets a REAL 301 (mirrors
      the /people|/person 301 below) so crawlers + old external links converge
      on one canonical URL. An unresolvable slug falls through to the SPA shell
      — the fragment renders the name-based fallback, so no /writer/ URL that
      used to answer ever dies (rule #33). NOTE the pattern: ([^/]+) +
      rawurldecode, NOT the person branch's [a-z0-9\-]+ — historic writer slugs
      carry dots and percent-encoded bytes (sitemap fold, pre-D4). */
   elseif (preg_match('#^/writer/([^/]+)$#', $requestPath, $matches)) {
       $pageType = 'other';
       try {
           require_once __DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'musician_helpers.php';
           $writerResolved = musicianResolveLegacySlugDb(getDbMysqli(), rawurldecode($matches[1]));
       } catch (\Throwable $_e) {
           $writerResolved = null;   /* fail-open: serve the shell, never a 500 */
       }
       if ($writerResolved !== null) {
           header('Location: ' . getCanonicalUrl('/musician/' . rawurlencode($writerResolved)), true, 301);
           exit;
       }
   }
   ```
2. **`router.js`** — in `afterPageLoad()` (`:676`), immediately after the
   `this.app.setList?.renderSongNavigation();` call (`:697`) and BEFORE the `if (page === 'song')`
   branch, add:
   ```js
   /* #1741 P4a-3 — a legacy /writer/<name-slug> (or a name-slug /musician/
      credit link, or a /person|/people alias path) whose fragment resolved to
      a registry musician carries the canonical path on .page-musician.
      Soft-canonicalise the URL bar — the #1343-B data-song-canonical pattern:
      replaceState (no reload, no back-button trap), then retitle as Musician. */
   if (page === 'writer' || page === 'musician') {
       const _mCanon = document.querySelector('.page-musician[data-musician-canonical]');
       if (_mCanon) {
           const _mto = _mCanon.getAttribute('data-musician-canonical');
           if (_mto && _mto !== window.location.pathname) {
               window.history.replaceState({ path: _mto }, '', _mto);
               this.currentPath = _mto;
               this.updateTitle('musician', params);
           }
       }
       /* #<new-issue> — the #btn-edit-musician reveal was stranded inside the
          page === 'song' branch since #1348 and never ran on musician pages;
          it lives here now, where the element actually renders. */
       const editMusicianBtn = document.getElementById('btn-edit-musician');
       if (editMusicianBtn) {
           const role = this.app.userAuth?.getUser()?.role;
           if (userHasEntitlement('manage_musicians', role)) {
               editMusicianBtn.classList.remove('d-none');
           }
       }
   }
   ```
   and **remove** the now-relocated `btn-edit-musician` block from inside the song branch
   (`:790-798`). File the GitHub bug issue for that stranded reveal BEFORE the commit that fixes it
   (repo commit rule), and reference it in the moved comment.
   `parseRoute()`'s `case 'writer'` (`:318-319`) and `updateTitle`'s entries (`:649-650`) are
   deliberately unchanged (D-6).

### S6 — `appWeb/public_html/sitemap.xml.php`
Apply §1.4: remove `:120`, `:141-147`, `:151-160`; add the registry block after the song loop.

### S7 — tests (see §4)
`tests/test-writer-musician-route.js` (node guard) + `tests/php/test-musician-slug-resolve.php`
(runtime ladder test). Both are picked up automatically by the glob runners
(`tools/run-php-tests.php`, `tools/run-node-tests.js`) — no list to edit, by design.

---

## 4. The CI guard — tree-derived and mutation-proven (rule #34)

### 4.1 `tests/test-writer-musician-route.js` (node, structural — modelled on `tests/test-identifier-routes.js` / `test-tag-route.js`)

Uses the same `check()` harness and the case-label→next-label slicing technique
(`test-tag-route.js:63-89`). Assertion groups:

1. **Files** — `includes/pages/musician.php` exists; **`includes/pages/writer.php` no longer exists**
   (the `iswc.php`-gone precedent, `test-identifier-routes.js:59-60`); `includes/musician_helpers.php`
   exists.
2. **TREE-DERIVED: no internal `/writer/` emitters remain.** Recursively walk
   `appWeb/public_html/**/*.{php,js}` (skip `vendor/`, `data/`, `node_modules/`) and fail on any file
   matching the *emitter* patterns `href="/writer/`, `href='/writer/`, `'/writer/' .` or
   `"/writer/" .` (string-concatenated loc/href builds — this is what caught `sitemap.xml.php:155`).
   No allowlist of filenames is typed; the walk IS the list. Handler code (`case 'writer'`, the
   `preg_match('#^/writer/…')` branch) does not match these patterns by construction.
3. **`sitemap.xml.php`** — contains `FROM tblMusicians` and `'/musician/'`; contains **no** `'/writer/'`.
4. **`index.php`** — slice the `elseif (preg_match('#^/writer/` branch; assert it exists, calls
   `musicianResolveLegacySlugDb`, and emits `'/musician/'` with `, true, 301`. Also assert the
   pre-existing `/people|/person` 301 is still present (regression lock on the precedent).
5. **`api.php`** — slice `case 'writer':` → `break;`: requires `musician.php`, contains **no**
   `writer.php` reference, reads `$_GET['id']`; `$_cacheablePages` still contains `'writer'` and
   `'musician'`; the `$page === 'person'` normalisation still present.
6. **`router.js`** — `case 'writer':` still resolves `{ page: 'writer', params: { id: … } }`
   (rule #33 lock); `afterPageLoad` contains `data-musician-canonical` and a `replaceState` within the
   same sliced block; the `btn-edit-musician` reveal is NOT inside the sliced `page === 'song'` block.
7. **One resolver, three consumers (rule #22)** — `musician_helpers.php` defines
   `musicianLegacySlugPlan`, `musicianResolveLegacySlug`, `musicianResolveLegacySlugDb`;
   `index.php` and `musician.php` both call `musicianResolveLegacySlugDb`; no OTHER file under
   `appWeb/public_html` defines a second `slugify`/name-variant fold for musicians (walk-derived:
   fail on `str_replace('-', ' '` + `MB_CASE_TITLE` co-occurring in any `includes/pages/*.php` other
   than `musician.php`).
8. **`musician.php`** — emits `data-musician-canonical="/musician/`; its fallback binds
   `musicianLegacySlugPlan(` names.

### 4.2 `tests/php/test-musician-slug-resolve.php` (PHP, runtime — the ladder actually executes)

Requires `includes/musician_helpers.php` directly (its direct-access guard checks
`SCRIPT_FILENAME` basename, so a test include passes). No DB — injected closures, per the
`test-song-redirect-claim.php` lesson ("source inspection is not evidence for a property that has a
runtime handle"):

- **Plan shape**: `musicianLegacySlugPlan('john-newton')` → slugs `['john-newton']`, names
  `['John Newton', 'john-newton']` (CI-dedupe collapses the spaced-lower variant);
  `('charles-h.-gabriel')` → slugs `['charles-h.-gabriel', 'charles-h-gabriel']`;
  `('söderberg')` → slugs include `'soderberg'` (NFKD fold; skip-with-note if `Normalizer` is absent
  in the CI PHP, mirroring `slugifyMusicianName()`'s own `class_exists` guard);
  `('smith-jones')` → names include the raw `'smith-jones'` (the D4 no-silent-404 variant).
- **Ladder order** via recording spies: a `$bySlug` that logs every candidate and answers only the
  second; assert rung 1 was tried first and `$byName` was never called on a slug hit; assert
  `$byName` receives the full deduped name list only after both slug rungs miss; assert `null` when
  everything misses; assert `''` from a lookup is treated as a miss, not a hit.
- **Idempotence**: resolving a value the spy maps to itself returns it on rung 1.

### 4.3 Mutation-proof protocol — REQUIRED, recorded in the PR description

Rule #34: a guard whose first green was never challenged is presumed wrong. For each mutation below:
apply it, run the guard, confirm **RED**, revert, confirm green. Record the list in the PR body.

| Mutation | Guard that must go red |
|---|---|
| Re-add the `'/writer/' . $slug` emission to `sitemap.xml.php` | 4.1 groups 2 + 3 |
| Comment out the `index.php` `/writer/` elseif branch | 4.1 group 4 |
| Change `case 'writer'`'s require back to `writer.php` (recreate a stub file) | 4.1 groups 1 + 5 |
| Delete `case 'writer':` from `router.js` `parseRoute()` | 4.1 group 6 |
| Remove `data-musician-canonical` from `musician.php`'s section tag | 4.1 group 8 |
| Swap the ladder's rung order (names before slugs) in `musicianResolveLegacySlug` | 4.2 order spies |
| Drop the raw-slug entry from `musicianLegacySlugPlan()`'s name candidates | 4.2 `smith-jones` case |

Also verify the guards are not too blunt (rule #34's second edge): after the real implementation,
both must pass on the first clean run with zero allowlist entries.

---

## 5. Verification plan (the implementer runs all of it)

1. **Syntax**: `php -l` on `api.php`, `index.php`, `sitemap.xml.php`, `includes/musician_helpers.php`,
   `includes/pages/musician.php`, the new PHP test; `node --check` on `js/modules/router.js` and the
   new node test. (Full-tree sweep before the PR per the commit-expectations checklist.)
2. **Suites**: `php tools/run-php-tests.php` → **101 files, 0 failures** (was 100);
   `node tools/run-node-tests.js` → **49 files, 0 failures** (was 48). No existing test may regress —
   pay attention to `test-fragment-inline-scripts.php`, `test-tag-route.js`,
   `test-identifier-routes.js`, `test-openapi-actions-exist.php` (none reference `writer.php` by path;
   verified 2026-08-03).
3. **Mutation runs** per §4.3, recorded in the PR.
4. **Live behavioural probe against the dev MySQL** (reachable via `getDbMysqli()`). Write
   `probe-p4a3.php` in the session scratchpad (NOT committed):
   ```php
   <?php
   declare(strict_types=1);
   require '/home/user/iHymns/appWeb/public_html/includes/config.php';
   require '/home/user/iHymns/appWeb/public_html/includes/db_mysql.php';
   require '/home/user/iHymns/appWeb/public_html/includes/musician_helpers.php';
   require '/home/user/iHymns/appWeb/public_html/includes/SongData.php';      // toTitleCase()
   require '/home/user/iHymns/appWeb/public_html/includes/error_page.php';    // renderErrorFragment()
   $db = getDbMysqli();

   /* P1 — a real registry musician resolves from their writer-style name slug. */
   $row = $db->query("SELECT Slug, Name FROM tblMusicians WHERE Slug <> '' AND Name LIKE '% %' LIMIT 1")->fetch_assoc();
   $writerSlug = strtolower(str_replace(' ', '-', $row['Name']));   // the historic sitemap/song.php fold
   $resolved   = musicianResolveLegacySlugDb($db, $writerSlug);
   echo "P1 resolve {$writerSlug} → " . var_export($resolved, true) . " (expect '{$row['Slug']}')\n";
   assert($resolved === $row['Slug']);

   /* P2 — punctuated name resolves via the slug fold (rung 2). */
   $p = $db->query("SELECT Slug, Name FROM tblMusicians WHERE Slug <> '' AND Name LIKE '%.%' LIMIT 1")->fetch_assoc();
   if ($p) {
       $ws = strtolower(str_replace(' ', '-', $p['Name']));
       assert(musicianResolveLegacySlugDb($db, $ws) === $p['Slug']);
       echo "P2 punctuation fold OK ({$ws} → {$p['Slug']})\n";
   }

   /* P3 — the writer-served fragment renders the musician profile + marker. */
   $personSlug = $writerSlug;
   ob_start(); require '/home/user/iHymns/appWeb/public_html/includes/pages/musician.php';
   $html = ob_get_clean();
   assert(str_contains($html, 'data-musician-canonical="/musician/' . rawurlencode($row['Slug']) . '"'));
   assert(str_contains($html, htmlspecialchars($row['Name'])));
   echo "P3 fragment OK — profile + canonical marker rendered for /writer/{$writerSlug}\n";

   /* P4 — a credited name with NO registry row degrades to the fallback list (not 404). */
   $orphan = $db->query("SELECT w.Name FROM tblSongWriters w
                          WHERE NOT EXISTS (SELECT 1 FROM tblMusicians m WHERE m.Name = w.Name)
                          LIMIT 1")->fetch_assoc();
   if ($orphan) {
       $personSlug = strtolower(str_replace(' ', '-', $orphan['Name']));
       assert(musicianResolveLegacySlugDb($db, $personSlug) === null);
       ob_start(); require '/home/user/iHymns/appWeb/public_html/includes/pages/musician.php';
       $html = ob_get_clean();
       assert(str_contains($html, 'song-list'));                       // the discography rendered
       assert(!str_contains($html, 'data-musician-canonical'));        // no fake canonical
       echo "P4 fallback OK — /writer/{$personSlug} still lists songs, no marker\n";
   }
   echo "ALL PROBES PASSED\n";
   ```
   Run with `php -d zend.assertions=1 -d assert.exception=1 probe-p4a3.php`. Note: `require` of
   `musician.php` twice needs the second include in a separate process OR use `include`; functions in
   `musician.php` (`_personSlugToName` etc.) are defined unconditionally — **run P4 as a second
   process** (`php probe-p4a3.php p4`) to avoid redeclaration fatals. Structure the probe accordingly.
5. **Optional HTTP check** (only if a dev docroot is reachable from the session):
   `curl -sI https://dev.ihymns.app/writer/<P1 slug>` → `HTTP/1.1 301` + `Location: …/musician/<Slug>`;
   `curl -sI …/writer/<P4 slug>` → `200` (shell). Do not block the PR on this if the host is
   unreachable — the CLI probe covers the resolver and fragment; say so explicitly in the handoff
   rather than silently skipping (standing-tasks rule).
6. **Manual SPA spot-check** (if running the app): navigate to `/writer/<P1 slug>` in-app → content
   is the musician profile, address bar reads `/musician/<Slug>` without a reload, Back returns to
   the previous page (no trap), and an admin sees the Edit button (the S5 bug fix).

---

## 6. Out of scope / follow-ups / standing tasks

File these as GitHub issues at implementation time (standing-tasks §2 — at the moment of discovery,
grouped under #1741 where they belong):

1. **Bug (pre-existing, fixed in S5)**: `#btn-edit-musician` reveal stranded inside `router.js`'s
   `page === 'song'` branch since #1348 — dead code; musician-page Edit button never appeared.
   Retrospective issue naming the fixing commit.
2. **Follow-up (D-7)**: `index.php:500-562`'s `/musician/` JSON-LD branch uses only the exact-slug
   lookup — route it through `musicianResolveLegacySlugDb()` so name-slug crawler hits get JSON-LD too.
3. **Follow-up (D-4)**: consider `tblMusicianAliases` as a fourth ladder rung, together with (and
   only together with) widening the registry-row discography to alias names — the two must land as
   one change or redirected alias URLs appear to lose songs.
4. **Consider**: `SongData::getSongsByCreditName()` (`SongData.php:2293`) is now caller-less — keep
   (documented exemplar) or remove via a separate `/simplify`-class decision.

Docs to update in the same PR (standing tasks): `CHANGELOG.md` (route consolidation + 301),
Wiki API/Architecture pages if they list `/writer/`, `.claude/ProjectBrief.md` phase note, and close
the #1741 P4a-3 sub-issue with commit SHAs. `api-docs.yaml` needs no change — it documents no
`page=writer` path (verified; the `writer` hits in it are credit-role enums and prose).

**Hard constraints recap for the implementer:** no schema change (no migration, no `schema.sql`
edit); no new `fetch` override, no inline fragment `<script>`, no per-request nonce in a fragment;
`'writer'` never leaves `$_cacheablePages`, `parseRoute`, or the `api.php` switch; the person/people
301 and normalisation are untouched; do not commit the probe script; do not skip the mutation-proof
runs.
