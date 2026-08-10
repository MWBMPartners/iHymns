# #1785 — Musicians: registry-vs-registry duplicate detection + easier merge UX

> **AS-BUILT (2026-08-10).** Shipped on `claude/issue-sweep-fixes-89`, all ten commits (C1-C10),
> not yet pushed. C1-C5 (schema, shared NAME scorer, merge-core extraction + hardening) landed in
> an earlier session on this branch: `325c4446` (schema), `d4d537eb` (scorer + classifier),
> `aaf474f9` (bulk-promote fork deleted), `b22772c1` (merge core extracted), `5db43165`
> (hardening — six tables, alias/relation carry), `228c3b7a` (G5 guard fix). This session built
> C6-C10: `2a32de8b` (C6 — the scan helper, `includes/musician_duplicates.php`, guard G4),
> `85674bea` (C7 — the `/manage/musician-duplicates` review page), `0cf96ffe` (C8 —
> disambiguation payload in every merge affordance), `f24d6dbb` (C9 — G2-G5 mutation-proven
> against the two new C6/C7 files; found and fixed a genuine gap in G3's file scan, which
> pre-dated those files and so never covered them), plus this doc pass (C10). Built exactly to
> plan — no section below is stale. **Deferred / for consideration** (flagged in the build
> report, not silently dropped): D1 was answered A (six tables everywhere) implicitly by C5's
> hardening landing before this session started; the `tblSongArtists`-cascade-gap and
> alias/relation-cascade-delete bugs this plan's §1/§5.2 identified are CLOSED (C5); the
> `credit_search` third-fork in `manage/editor/api2.php` (discovered by G3's own doc-block) is
> NOT fixed — flagged for a follow-up issue, out of this epic's registry-merge scope; a warn-at-
> create-time duplicate check, and unifying this page with bulk-promote, both stay `for
> consideration` per §11. One PRE-EXISTING, unrelated bug was found (not fixed): `manage/
> musicians.php`'s list-render loop reads `$p['Id']` (should be `$p['registry_id']`) at the
> `<tr id=…>` line — present before this branch touched the file.
>
> **Original plan below, unmodified.** Written 2026-08-10 (deep-planning pass, Fable 5) from a
> full read of the live tree on `claude/issue-sweep-fixes-89`; every `file:line` cite verified
> against the working copy this date, plus two live experiments run against the local MySQL
> (documented §3.1/§3.2 — the uk_Name coexistence matrix and the scoring benchmark).
> **Feeds:** the build pass for #1785. **Parent epic:** #1787. **Follow-up to:** #1784 (the
> stuck-counter fix — invisible-byte reconcile is DONE; genuine registry duplicates are this
> issue's remit). All web paths relative to `appWeb/public_html/` unless rooted.

---

## §0 Executive summary

1. **One migration, one table** (rule #19/#20 one-pass): `tblMusicianDuplicatesDismissed` —
   the per-pair "not the same person" memory, mirroring `tblSongLinkSuggestionsDismissed`
   (schema.sql:2420-2437) exactly, FK-cascaded to `tblMusicians` so a merge auto-cleans its
   dismissals. **Nothing else needs schema.** The scan itself is LIVE-computed per page load
   with token-blocking (§3.3 — benchmarked: unblocked all-pairs at N=5k is ~30-70 s, blocked
   is sub-second); a precompute/suggestions table was considered and REJECTED (§3.4 — no cron
   on this host makes precompute a prod-stale class; the registry is 100× lighter than the
   song corpus that justified `tblSongLinkSuggestions`).
2. **The similarity maths is the ONE scorer, extended not forked** (rule #22): a new "NAME
   similarity" section in `includes/song_similarity.php` (`ihymns_sim_name_normalise()` /
   `ihymns_sim_name_score()`) built ON the existing primitives (`ihymns_sim_text()`,
   `ihymns_sim_authors_jaccard()`), and the existing PRIVATE fork in
   `manage/musicians-bulk-promote.php` (`_musBulkSimilarity()`, :58-115 — it even says
   "Mirrors the scoring shape from build-song-link-suggestions.php") is **deleted and
   re-pointed** at the shared scorer — the #1216 `_bsls_*` extraction re-applied.
3. **The merge core today is TWO byte-similar inline forks** — `manage/musicians.php`
   `case 'merge'` (:1389-1539) and api.php `admin_musician_merge` (:15990-16151). Neither is a
   function. Plan: extract ONE `musicianMergeExecute()` into `includes/musician_helpers.php`
   (behaviour-preserving, fixture-diffed), have both existing surfaces + the new review page
   delegate — then harden it in a separate commit (six-table cascade incl. the currently-missed
   `tblSongArtists`; alias/relation carry-over instead of silent cascade-delete; source-name
   preserved as a target alias).
4. **Disambiguation payload** so "Eddie James → Eddie James" reads sensibly: a pure
   `musicianNameVariantClass()` classifier (NFC / whitespace / case / punctuation ladder built
   on the existing `musicianCanonicalNameBytes()`, #1784) rendered as a badge with the exact
   differing code points, plus per-side Id, lifespan (BirthDate/DeathDate + precision), Type,
   Disambiguation, per-role use-counts, links/identifiers/alias counts. Injected into: the new
   review page, the existing Merge-modal preview, AND the `?action=merge_target_search`
   typeahead payload (additive fields — the datalist currently shows bare names that collide
   visually, musicians.php:4338-4341).
5. **The review page** is a dedicated `/manage/musician-duplicates.php` — mirroring the
   `/manage/duplicate-songs` precedent (#1215: ONE review surface per entity-dedup domain),
   linked from `/manage/musicians` and `/manage/musicians-bulk-promote` (which keeps its
   distinct job: cited-but-UNREGISTERED names). Two sections: §1 "Same name, different bytes"
   (fold-equal groups, high confidence, one-click merge) and §2 "Similar names" (blocked fuzzy
   pairs, score-ranked, Merge / Dismiss / Swap). Keyboard-navigable; survivor direction always
   explicit with a swap control; type-to-confirm reserved for conflicting-lifespan pairs.
6. **10 commits, one PR to `alpha`** (§9). C1 pure-dormant schema; C2-C3 shared maths (pure →
   re-point); C4-C5 merge core (extract → harden); C6-C8 scan + page + disambiguation
   everywhere; C9 mutation-proven guards; C10 docs/standing tasks. Every commit individually
   revertable.

---

## §1 Verified current-state anchors

| Fact | Where |
|---|---|
| Registry = `tblMusicians`: `Name` VARCHAR(255) NOT NULL + **`UNIQUE KEY uk_Name (Name)`** under utf8mb4_unicode_ci; `Slug` unique; `Type`/`Disambiguation` (#1741 P1); `FirstNames/Surname/Suffix` (#934); `BirthDate`/`DeathDate` + `…Precision` VARCHAR(5); `IsSpecialCase`/`IsGroup`; `MusicBrainzArtistMBID` NULL-distinct UNIQUE | schema.sql:532-592 |
| Credit tables store **free-text Name, no FK to the registry** — 5 tables + `tblSongArtists` (SortOrder'd) | schema.sql:405-416 (Writers), :502-515 (Artists); registry doc-block :518-527 |
| **The editor treats SIX role tables as credit tables** (`ED2_CREDIT_TABLES` incl. `'artists' => 'tblSongArtists'`) and `credit_upsert` promotes EVERY role's names into the registry via `musicianPromote()` | api2.php:369-376, :2568-2572 |
| **But every registry-side cascade covers only FIVE tables** — merge (musicians.php:1435-1446), rename, delete usage-count (:1566-1578), api merge (api.php:16039-16049), bulk-promote (`_CP_BULK_CREDIT_TABLES`, :50-56), `musicianReconcileCreditNameBytes` (helpers:1530-1533), `searchMusicianMergeTargets` in-use bucket (:751-770), `musicianCitedUnregisteredNames` (:2734-2750) — **artists are promoted IN but never cascaded** (discovered gap, §5.2 / issue to file) | as cited |
| The 5-table list itself is **forked in ≥6 places** (rule #35 cross-file agreement problem) | same cites |
| Merge execution: re-point 5 tables by `Name = ?` (collation match), migrate ticked links/IPI child rows, DELETE source row, FK cascade eats the rest — **including, silently, `tblMusicianAliases` + `tblMusicianRelations`** (both `ON DELETE CASCADE`, schema.sql:2711-2712, :2758-2760) and the row-borne `MusicBrainzArtistMBID` | musicians.php:1431-1538; api.php:16030-16110 |
| Merge UI: modal with source (readonly) + target typeahead (`<datalist>` of bare names) + per-role re-point preview from `data-person` | musicians.php:2610-2699, :2349 |
| Typeahead endpoint `?action=merge_target_search` returns `{key,id,name,total}` ONLY — registry rows carry `total: 0` and **no disambiguation of any kind**; the member-picker keys a Map on the bare name label | musician_helpers.php:718-783; musicians.php:4321-4344 |
| Bulk-promote scans **cited-but-UNREGISTERED names only** (`NOT EXISTS` against the registry, :283-301); its similarity is the private `_musBulkSimilarity` fork (0.6·token-Jaccard + 0.4·levenshtein-ratio, threshold 0.85, candidate-twin loop capped at 2000, :325-336); CSRF is the stale-prone baked `validateCsrf()` (:119) | musicians-bulk-promote.php as cited |
| #1784 machinery: `musicianCanonicalNameBytes()` (NFC + NBSP/ZWSP/BOM → space + collapse + trim — identity-preserving on purpose) and `musicianReconcileCreditNameBytes()` (adopt / register / **'ambiguous' report** for 2+ collation-matches) | musician_helpers.php:1474-1485, :1526-1613 |
| The ONE scorer file: `ihymns_sim_normalise()` (lowercase → TRANSLIT accent-fold → **leading-article strip** → punct → collapse), `ihymns_sim_text()` (levenshtein ratio, 255 cap), `ihymns_sim_authors_jaccard()` (pipe-token Jaccard), `ihymns_sim_score()` (song blend) | includes/song_similarity.php:48-201 |
| duplicate-songs precedent: live compute on load (cheap grouping pass + bounded heavy pass), JSON POST dispatcher under `validateCsrfRequest()`, per-action entitlement split, `force=1` type-to-confirm for the dangerous class only | manage/duplicate-songs.php:5-43, :105-120 |
| Dismissal precedent: `tblSongLinkSuggestionsDismissed` — normalised pair (A ≤ B), `uk_pair`, `DismissedBy/At`, `Reason`, FK CASCADE both sides | schema.sql:2420-2437 |
| Entitlements: `manage_musicians` → admin/global_admin (page gate, musicians.php:63); `manage_duplicate_songs` → admin/global_admin; api merge gates on raw Role admin check | includes/entitlements.php:159, :194; api.php:15995-15998 |
| musicians.php newer AJAX branches already use `validateCsrfRequest()`; **the big-switch actions (incl. merge) still use the baked token** — the sporadic-CSRF class on a long-open page (rule #29) | musicians.php:308-310, :337, :389, :447, :497, :559 |
| `/manage/musicians?id=<Id>` is a HANDLED deep-link (row highlight + scroll, #1641) — the new page can emit it (rule #33) | musicians.php:3418-3449 |
| Admin-table conventions on both musician pages: `mus-sortable admin-table-responsive` + `data-col-priority` + `bootSortableTables` from `js/modules/admin-table-sort.js` (multi-column sort, #1786) | musicians.php:2238, :3389; bulk-promote:447 |
| Aliases: `tblMusicianAliases` (`uq_musician_name`, `Type` VARCHAR vocab `MUSICIAN_ALIAS_TYPES` incl. `'misspelling'`/`'search-hint'`); existence probes `musicianAliasesTableExists()` / `musicianMembersTableExists()` already exist; `loadMusicianAliasesBulk()` exists | schema.sql:2692-2713; musician_helpers.php:1656-1669, :1676, :1934, :1816 |
| Migration registry: ONE entry (script/card/probe) derives all four setup-database facets; `_migProbe_tableExists/_columnExists` helpers | manage/includes/migration-registry.php:21, :116-199 |
| Test runners are glob-derived (a new file in `tests/php/` / `tests/` cannot be forgotten); `tests/php/test-song-similarity.php` already exercises the scorer, pure, no DB | tools/run-php-tests.php:56; tests/php/test-song-similarity.php:1-60 |

### Scale (measured, not guessed)

- The repo's full-data dump (`appWeb/.sql/.fulldata/ihymns-full.sql`, 2 songbooks) has **4,479
  credit rows over 1,076 distinct credited names**. The live corpus is ~14k songs (rule #27),
  so the live registry is plausibly **O(2,000-6,000) rows** once bulk-promote has been driven
  to zero, with long-term headroom to ~10-20k. (The local `ihymns_live` fixture has only 6
  musician rows — it is a test fixture, not the deployment; the builder should re-measure
  against alpha with `SELECT COUNT(*) FROM tblMusicians` before tuning caps.)

---

## §2 What #1785 asks, mapped to mechanisms

| Owner's words | Mechanism in this plan |
|---|---|
| "it's only finding this one" — bulk-promote never scans registry-vs-registry | §6 scan (`includes/musician_duplicates.php`) + §7 page |
| "which is merging into which? they both have the same name? 😕" | §4.2 `musicianNameVariantClass()` byte-diff badge + §8 payload (id / lifespan / roles / use-count on BOTH sides, explicit source→target arrow) |
| "finding them, and merging is … too cumbersome" | §7 one-click merge from the ranked list, keyboard nav, swap-survivor, dismissals that stay dismissed (§3 table) |

---

## §3 Front-loaded data-model decision (the scan + the one migration)

### 3.1 Experiment 1 — which same-looking duplicates can actually EXIST registry-vs-registry?

Run 2026-08-10 against local MySQL 8, `VARCHAR(255) … UNIQUE KEY uk_Name` under
`utf8mb4_unicode_ci` (the exact tblMusicians shape, schema.sql:534/582):

| Variant pair | Coexists under uk_Name? | Why |
|---|---|---|
| `Eddie James` vs `Eddie James ` (trailing space) | **NO** | PAD SPACE collation |
| `Eddie James` vs trailing NBSP / trailing ZWSP | **NO** | unicode_ci weights fold them |
| `Eddie James` vs `eddie james` (case) | **NO** | `_ci` |
| `Eddie James` vs `Eddie James` (interior NBSP) | **NO** | NBSP ≡ space in unicode_ci |
| `José` (precomposed) vs `Jose´` (combining) | **NO** | equal UCA weights |
| `José` vs `Jose` (accent present/absent) | **NO** | unicode_ci is accent-insensitive |
| **`Eddie James` vs `Eddie  James` (interior DOUBLE space)** | **YES** | distinct weight sequences |
| **`O'Brien` vs `O’Brien` (straight vs curly apostrophe)** | **YES** | distinct punctuation weights |
| **`J. Newton` vs `J Newton` (period present/absent)** | **YES** | ditto |
| **`Anna-Marie` vs `Anna–Marie` (hyphen vs en-dash)** | **YES** | ditto |

**Consequences the design leans on:**

- Registry-vs-registry "same-looking" duplicates are the **whitespace-count + punctuation-
  homoglyph classes** (plus genuinely-similar distinct spellings like `J. Newton` vs
  `John Newton`). The trailing-space/NBSP/case/NFC classes CANNOT exist between two registry
  rows — but they absolutely exist between a CITED name and a registry row (credit tables have
  no unique key), which is why the SAME classifier must serve the bulk-promote/merge-modal
  affordances too (§8), and why #1784's reconcile stays the fix for that side.
- `musicianReconcileCreditNameBytes()`'s case (c) 'ambiguous' (2+ collation matches,
  helpers:1577-1581) is theoretically unreachable while uk_Name is intact (collation equality
  is transitive) — it exists as drift armour. §6's Bucket A is what actually surfaces
  registry duplicates.
- Two same-named DISTINCT people currently cannot both exist (uk_Name); `Disambiguation`
  (schema.sql:555) anticipates that changing someday. Bucket A therefore **excludes** any pair
  where both sides carry differing non-empty `Disambiguation` — dead code today, load-bearing
  the day uk_Name is relaxed, and it costs one `if`.

### 3.2 Experiment 2 — is a live all-pairs scan affordable? (No.)

Benchmark (this host, PHP 8): `levenshtein()` on ~20-char names ≈ **1.1 µs/pair** ⇒ raw
all-pairs at N=5,000 ≈ **13.8 s of levenshtein alone**; the full `ihymns_sim_name_score()`
(normalise memoised, tokenise, Jaccard + levenshtein) lands ~2-5× that ⇒ **30-70 s/page load.
Unacceptable.** With the §3.3 blocking, candidate pairs collapse to O(tens of thousands) ⇒
**well under a second**. This is the maths behind rejecting naive-live AND rejecting "we must
therefore precompute".

### 3.3 The blocking strategy (and its declared miss-set — rule #35, no silent caps)

One `SELECT Id, Name, Disambiguation?, BirthDate?, … FROM tblMusicians` (a few hundred KB at
worst), then in PHP:

- **Precompute per row, O(N)**: `$fold = ihymns_sim_name_normalise(Name)` (§4.1),
  `$tokens = explode(' ', $fold)`, `metaphone(first token)`, `metaphone(last token)`.
- **Bucket A — fold-equal groups** (`$fold` as array key): every group of ≥2 is a
  high-confidence duplicate set (whitespace / punctuation / homoglyph classes per §3.1),
  classified pairwise by `musicianNameVariantClass()`. Minus the Disambiguation exclusion.
  O(N), no scoring needed.
- **Bucket B — fuzzy candidates**: a pair is a candidate iff it shares
  `metaphone(last token)` **OR** `metaphone(first token)` (two block keys per name — the
  first-token block is what catches comma-reversed `"Newton, John"` vs `"John Newton"`, where
  the last tokens differ). All candidate pairs scored with the shared
  `ihymns_sim_name_score()`; keep ≥ threshold (default 0.85, GET-tunable exactly like
  bulk-promote:245). Single-token names bucket on `metaphone(whole)`.
- **Bucket C — alias signal** (gated `musicianAliasesTableExists()`): a registry Name whose
  fold equals ANOTHER row's alias fold (one bulk `SELECT MusicianId, Name FROM
  tblMusicianAliases`) is a candidate pair with signal `'alias-match'` and confidence high —
  the curator already RECORDED that this spelling belongs to that person.
- **Caps, surfaced never silent**: any block bucket over `MUSDUP_BUCKET_CAP` (default 400
  members ⇒ ~80k pairs) is SKIPPED and reported — the scan's return array carries
  `stats: {registryRows, foldGroups, fuzzyPairsScored, skippedBuckets: [{key, size}], elapsedMs}`
  and the page renders it in the stats line (the bulk-promote stats-line precedent, :413-417).
- **Declared misses** (documented in the file doc-block AND the page's help text):
  (a) pairs whose first AND last tokens both differ phonetically — e.g. pseudonym vs real name
  (`Fanny Crosby` vs `Frances van Alstyne`), which no string metric can find (that is what
  Bucket C aliases are for once curated); (b) metaphone is English-phonetics — transliterated
  non-Latin names that fold to very different ASCII may not co-bucket; (c) anything in a
  skipped over-cap bucket (visible in stats). Dismissed pairs are filtered out AFTER candidate
  generation (so stats stay honest) via one read of the §3.5 table.

### 3.4 Live vs precompute vs batch — decision

| Option | Verdict |
|---|---|
| **Naive live all-pairs** | ✗ 30-70 s at projected N (§3.2) |
| **Precompute into a suggestions table (batch builder, `build-song-link-suggestions.php` style)** | ✗ There is NO cron on this host (established in the #1770 plan §4.1 — prunes are piggy-backed for exactly this reason), so a precompute goes stale silently between manual rebuilds — the prod-stale class. Justified for songs only because scoring 14k songs × first-line lyrics is genuinely heavy and the pairs feed OTHER consumers (`tblSongLinkSuggestions.Confidence/Signal`). Musicians have neither property. |
| **Live compute WITH blocking (§3.3)** | ✓ **RECOMMENDED.** Sub-second at projected N, zero staleness, self-heals after every merge/rename, and it is exactly the duplicate-songs shape ("candidate discovery is cheap; scoring is bounded", duplicate-songs.php:13-23). |

Escape hatch documented (not built): if the registry someday exceeds ~20k rows AND the scan
breaches ~2 s, the known shape is a `tblMusicianDupeSuggestions` sibling of
`tblSongLinkSuggestions` rebuilt from a web-triggered "Rebuild" button (the duplicate-songs
`rebuild` action precedent). Deliberately NOT shipped dormant now — see §13's rule-#20
argument (guessed-shape risk beats the one-CREATE saving at a scale we have no evidence of
approaching).

### 3.5 The ONE migration — `tblMusicianDuplicatesDismissed`

`appWeb/.sql/migrate-musician-duplicates-dismissed.php`, idempotent
(`CREATE TABLE IF NOT EXISTS`), byte-identical mirror appended to `appWeb/.sql/schema.sql`
next to the musician-satellites family (rule #19):

```sql
-- ----------------------------------------------------------------------------
-- tblMusicianDuplicatesDismissed (#1785) — a curator's "these two registry
-- rows are NOT the same person" memory for /manage/musician-duplicates.
-- Mirrors tblSongLinkSuggestionsDismissed: pair normalised (IdA < IdB,
-- numeric), UNIQUE per pair, FK-cascaded so merging/deleting either person
-- auto-cleans the dismissal. Scores are deliberately NOT stored — the scan
-- recomputes live (#1785 §3.4), so a stored score could only go stale.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tblMusicianDuplicatesDismissed (
    Id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    MusicianIdA  INT UNSIGNED NOT NULL COMMENT 'Always numerically < MusicianIdB (#1785)',
    MusicianIdB  INT UNSIGNED NOT NULL,
    DismissedBy  INT UNSIGNED NULL DEFAULT NULL COMMENT 'tblUsers.Id; NULL = user since deleted',
    DismissedAt  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    Reason       VARCHAR(255) NOT NULL DEFAULT '',
    UNIQUE KEY uk_pair (MusicianIdA, MusicianIdB),
    KEY idx_B (MusicianIdB),
    CONSTRAINT fk_MusDupDism_A FOREIGN KEY (MusicianIdA) REFERENCES tblMusicians(Id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_MusDupDism_B FOREIGN KEY (MusicianIdB) REFERENCES tblMusicians(Id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Registry entry (ONE, in `manage/includes/migration-registry.php`, appended at the tail):

```php
'musician-duplicates-dismissed' => [
    'script' => 'migrate-musician-duplicates-dismissed.php',
    'card' => [
        'title'  => 'Musicians: duplicate-review dismissals (#1785)',
        'body'   => 'Creates <code>tblMusicianDuplicatesDismissed</code> so "not the same '
                  . 'person" decisions on /manage/musician-duplicates persist. Idempotent.',
        'button' => 'Run Musician Duplicates Migration',
    ],
    'probe' => static fn(\mysqli $db) =>
        !_migProbe_tableExists($db, 'tblMusicianDuplicatesDismissed'),
],
```

Single object ⇒ single-probe is correct (rule #19's OR-probe applies to multi-object batches).
Un-migrated degradation (rule #9): the page probes table existence once
(INFORMATION_SCHEMA, memoised — the `musicianAliasesTableExists()` shape); when absent it
still SCANS and renders, hides Dismiss buttons, and shows the "run the migration" card
(the service-projection precedent). Dismiss POST against an un-migrated install answers 409.

### 3.6 One-pass adversarial stress ("what would force a second migration?")

| Future tweak | Covered without new DDL? |
|---|---|
| Dismiss with a note | ✓ `Reason` ships day one |
| Un-dismiss | ✓ DELETE by pair |
| Pair auto-cleanup on merge/delete/rename | ✓ FK CASCADE (rename doesn't change Id) |
| "Dismiss whole cluster" | ✓ app-level = dismiss every pair (the song-side model, rule #22) |
| Per-pair confirmed-distinct surfaced on the ADD form later | ✓ read of the same table |
| Precompute cache at extreme scale | New TABLE (documented shape §3.4), not an ALTER of this one — this table's contract (curator decisions) is scale-independent |
| Score/confidence snapshot on dismissal | Deliberately excluded (stale-score hazard); if ever wanted it is additive `ADD COLUMN` — accepted residual risk, argued §13 |

---

## §4 Shared maths (rule #22) — extend the ONE scorer, delete the fork

### 4.1 `includes/song_similarity.php` — new NAME section (#1785)

Stays framework-free (file doc-block promise, :32-33). Additions:

- `ihymns_sim_fold(string $s): string` — the common fold extracted from the BODY of
  `ihymns_sim_normalise()` (:48-67): lowercase → `iconv` TRANSLIT accent-fold → punct→space →
  collapse → trim. `ihymns_sim_normalise()` becomes `article-strip(ihymns_sim_fold($s))` —
  **byte-identical output** (the existing `tests/php/test-song-similarity.php` assertions at
  :57-60 must pass UNCHANGED; that is the refactor's proof).
- `ihymns_sim_name_normalise(string $name): string` = `ihymns_sim_fold()` alone. A person
  name must NOT article-strip (`ihymns_sim_normalise('La Trobe')` would drop the surname
  particle; verified against the :61 article list `the|a|an|o|el|la|le|les|der|die|das`).
- `ihymns_sim_name_tokens(string $name): list<string>`.
- `ihymns_sim_name_score(string $a, string $b): float` — the `_musBulkSimilarity` maths
  (bulk-promote:81-115) re-expressed through the shared primitives: fold-equal ⇒ 1.0; edit
  component = `ihymns_sim_text($foldA, $foldB)` (:82-95); token component =
  `ihymns_sim_authors_jaccard(implode('|', $tokensA), implode('|', $tokensB))` (:108-120);
  blend **0.6·token + 0.4·edit** (the bulk-promote weights, kept so its 0.85 threshold
  semantics survive).
  Two documented behaviour deltas vs the fork it replaces: (1) diacritics now fold
  (`José`≈`Jose` scores 1.0 — strictly better); (2) the >255-char path truncates
  (ihymns_sim_text's rule) instead of `similar_text` — unreachable for names.

### 4.2 `includes/musician_helpers.php` — the variant classifier (#1785)

`musicianNameVariantClass(string $a, string $b): ?string` — pure, placed beside
`musicianCanonicalNameBytes()` (:1474). Ladder (first match wins):

1. `$a === $b` → `'identical'` (defensive; a valid pair never is).
2. NFC($a) === NFC($b) → `'unicode-normalisation'` (combining vs precomposed).
3. `musicianCanonicalNameBytes($a) === musicianCanonicalNameBytes($b)` → `'whitespace'`
   (trailing/leading space, NBSP, ZWSP, BOM, interior run-collapse — reuses the #1784 fold,
   never re-implements it).
4. `mb_strtolower(canonical(a)) === mb_strtolower(canonical(b))` → `'case'`.
5. `ihymns_sim_name_normalise($a) === ihymns_sim_name_normalise($b)` → `'punctuation'`
   (period / apostrophe-homoglyph / dash / accent-fold differences).
6. else → `null` (visibly different names — the fuzzy-score case).

UI copy map (ONE PHP array beside it, consumed by every surface): `whitespace` → "differs only
by invisible spacing", `punctuation` → "differs only by punctuation/accents", etc., plus a
`musicianNameVariantDetail()` companion returning the first differing code point pair as
`U+0027 (') vs U+2019 (’)` for the badge tooltip — this is what makes
"Eddie James → Eddie James" legible.

### 4.3 The ONE credit-table map

`const MUSICIAN_CREDIT_ROLE_TABLES = ['writer' => 'tblSongWriters', 'composer' =>
'tblSongComposers', 'arranger' => 'tblSongArrangers', 'adaptor' => 'tblSongAdaptors',
'translator' => 'tblSongTranslators', 'artist' => 'tblSongArtists']` in
`musician_helpers.php` — the six-role truth matching `ED2_CREDIT_TABLES` (api2.php:369-376).
All ≥6 forked five-table lists (§1) re-point to it in C5 (the artists column joins every
use-count/cascade surface — owner decision D1 flags the visible counter consequence). Table
names remain hardcoded-constant interpolations (rule #5's carve-out); names/ids stay bound.

---

## §5 The merge core — extract, then harden

### 5.1 C4 extraction (behaviour-preserving)

`musicianMergeExecute(\mysqli $db, int $sourceId, int $targetId, array $opts = []): array`
in `musician_helpers.php`. `$opts = ['keepLinkIds' => int[], 'keepIpiIds' => int[]]`.
Owns its transaction (both existing call sites do, and none nests). Returns the exact report
array both call sites currently build: `['sourceName','targetName','affected'(per-table),
'linksKept','linksDropped','ipiKept','ipiDropped']`. Refusals THROW typed
`InvalidArgumentException` (missing side / same id) — callers map to their surface's error
shape (page banner vs `sendJson` 400/404; status is the contract, rule #35).

Call sites become thin: musicians.php `case 'merge'` keeps its #626 `registerMusicianByName`
auto-register preamble (:1397-1411) then delegates; api.php `admin_musician_merge` keeps its
gate + JSON envelope then delegates. **Verification is a fixture diff**: on a scratch DB
(local MySQL is available — `ihymns_test`), run the same merge before/after the refactor and
assert identical table states + identical report arrays.

### 5.2 C5 hardening (each item existence-gated, rule #9)

1. **Six-table cascade** via `MUSICIAN_CREDIT_ROLE_TABLES` — closes the discovered
   `tblSongArtists` gap (§1): today a merge/rename strands artist credits on the dead
   spelling even though `credit_upsert` promoted that artist INTO the registry
   (api2.php:2568). Same constant re-points rename + delete-usage-count + the list/Q1 +
   bulk-promote + `searchMusicianMergeTargets` + `musicianCitedUnregisteredNames` +
   `musicianReconcileCreditNameBytes`. (File the gap as its own issue at discovery —
   standing-tasks §2; the commit closes it.)
2. **Alias carry-over** (gated `musicianAliasesTableExists()`): source's alias rows re-point
   to the target with an existence check against `uq_musician_name` (skip collisions) —
   today they are silently cascade-deleted.
3. **Relation carry-over** (gated `musicianMembersTableExists()`): source's
   subject/object relation rows re-point, tolerating `uq_subject_object_rel` collisions —
   today a merged group's memberships vanish.
4. **Source name preserved as a target alias** (gated on the aliases table): INSERT
   `(targetId, sourceName, Type)` where Type = `'misspelling'` when
   `musicianNameVariantClass(source, target) !== null`, else `'search-hint'` (both already in
   `MUSICIAN_ALIAS_TYPES`, helpers:1656-1665); skip on `uq_musician_name` collision. Names
   outlive rows (the rule-#33 instinct applied to data).
5. **NOT built** (filed `for consideration`): COALESCE-filling target's NULL biographical
   fields / MBID from the source. The review page shows both sides' metadata so the curator
   sees what the merge will drop; automated meta-merge is a semantics decision for its own
   issue.

Both existing surfaces inherit 1-4 automatically (one core). The report array grows additive
keys (`aliasesMoved`, `relationsMoved`, `sourceNameAliased`).

---

## §6 The scan helper — `includes/musician_duplicates.php`

New file (the `publisher_admin.php`-beside-`publisher_helpers.php` split precedent, rule #37 —
`musician_helpers.php` is already 2,758 lines), requiring `musician_helpers.php` +
`song_similarity.php`. Framework-free; direct-access blocked (the helpers' :30-33 guard).

`musicianFindRegistryDuplicates(\mysqli $db, array $opts = []): array`
— `$opts`: `threshold` (0.5-1.0, default 0.85), `bucketCap` (default 400),
`includeDismissed` (default false). Implements §3.3 (Buckets A/B/C, Disambiguation exclusion,
dismissal filter, stats). Returns:

```php
[
  'groups' => [ // Bucket A — fold-equal, confidence 'high'
    ['members' => [personPayload, …], 'pairs' => [[idA, idB, variantClass], …]],
  ],
  'pairs'  => [ // Buckets B + C, score DESC
    ['a' => personPayload, 'b' => personPayload, 'score' => float,
     'signal' => 'fuzzy'|'alias-match', 'variant' => ?string,
     'suggestedSurvivor' => 'a'|'b', 'dismissed' => bool],
  ],
  'stats'  => ['registryRows','foldGroups','fuzzyPairsScored','skippedBuckets','elapsedMs'],
]
```

`personPayload` (built by ONE `musicianDisambiguationPayload()` in the same file, reused by
§8): `id, name, slug, type, isGroup, isSpecialCase, disambiguation, born, died` (year strings
honouring the precision columns via the existing gated selects — the musicians.php Q2 idiom
:1687-1729), `useCount` + per-role counts (one 6-table UNION, GROUP BY Name, joined in PHP),
`links, identifiers, aliases` (COUNTs, existence-gated), `hasMbid, createdAt`.

`suggestedSurvivor`: higher `useCount` → richer metadata (count of non-null bio fields +
child rows) → lower Id (older row). Pure function, unit-tested, and only a DEFAULT — the UI
always shows the direction and offers swap.

Confidence: Bucket A and `alias-match` ⇒ 'high'; fuzzy tiers reuse
`ihymns_sim_confidence_tier()` (song_similarity.php:132-146 — signal `''`/`'fuzzy'` maps
through unchanged; no re-fork).

---

## §7 The review page — `manage/musician-duplicates.php`

**Precedent**: `/manage/duplicate-songs` (#1215) — ONE dedicated review surface per
entity-dedup domain. Integration INTO musicians.php (5,103 lines) or bulk-promote (a
different question: unregistered names) was considered and rejected: it would either bloat an
already-huge page or conflate two scans with different candidate sets and different actions.
Bulk-promote keeps its job and gains a cross-link; a later unification of the two musician
surfaces can absorb this page's sections the way duplicate-songs absorbed
song-link-suggestions — noted for consideration, not built.

- **Gates**: page view + Dismiss + Merge all `manage_musicians` (musicians.php:63 — the
  registry's own gate; merge is ALREADY reachable to exactly this set via the modal, so no new
  privilege is created; the nav entry it hangs off advertises the same entitlement,
  admin-links.php:76 — the #1587 parity rule). A `manage_duplicate_songs`-style split is D4
  (default: no).
- **Chrome**: standard partials (`head-libs`, `head-favicon`, `admin-nav` with
  `$activePage = 'musician-duplicates'`, `admin-footer`) — never bespoke (rule #36 / checkpoint
  1-3). Themed error card on load failure (rule #9); the whole detection wrapped try/catch.
- **POST dispatcher**: JSON in/out under `validateCsrfRequest()` + client `X-Requested-With`
  (the duplicate-songs.php:105-115 shape — NEVER the baked-token-only pattern, rule #29).
  Actions: `merge` (delegates to `musicianMergeExecute()`, default keep-all child rows —
  the fuzzy-review flow doesn't re-ask the checkbox question; the modal on musicians.php
  remains the fine-grained path), `dismiss` (+ optional reason), `undismiss`. Activity log:
  `admin.musicians.merge` (the existing key, via the same `$logMusician` closure shape) +
  new `admin.musicians.dupe_dismiss` / `dupe_undismiss`.
- **Layout**:
  - Stats line (registry rows scanned, groups, pairs, skipped buckets, elapsed — §3.3's
    no-silent-caps surfacing).
  - Filters card (GET): `threshold`, `min_uses`, `q` substring — the bulk-promote idiom
    (:245-247, :384-410).
  - **§1 Same name, different bytes** (Bucket A groups + alias-matches): per pair a card
    showing both `personPayload`s side by side, the variant badge + code-point tooltip
    (§4.2), the arrow `source → survivor`, [Swap] [Merge] [Not the same person].
  - **§2 Similar names** (fuzzy, score DESC): `admin-table-responsive` table with
    `data-col-priority` on every column + `mus-sortable` headers via the SHARED
    `bootSortableTables` import (musicians.php:3389 — rule #13, #1786 multi-sort), columns:
    score, name A (with payload chips), name B, signal, actions.
  - `?show=dismissed` view listing dismissed pairs with Undismiss.
- **Every person renders an "open in registry" link** to `/manage/musicians?id=<Id>` — a
  deep-link the destination verifiably handles (musicians.php:3441-3449; rule #33).
- **Merge confirm, proportionate to danger** (the #1218 analogue):
  - Bucket A / `alias-match` / any pair whose `variant !== null`: plain confirm dialog
    (identity-preserving class — the two spellings denote one person by construction).
  - Fuzzy pairs: confirm dialog rendering BOTH payloads + "N credit rows will be re-pointed".
  - **Conflicting-biography pairs** (both sides have a birth OR death date and they differ
    beyond precision overlap): the server requires `force=1` and the client requires typing
    `MERGE` — this is the musician equivalent of the same-official-songbook guard (two
    same-ish-named rows with different lifespans is exactly the "actually two people"
    signature `Disambiguation` exists for).
- **Keyboard nav** (page-bottom script, the musicians.php inline-module precedent — /manage
  pages are NOT nonce-CSP SPA fragments, rule #30 does not bind here): roving focus over
  pair rows (`j`/`k`/arrows), `Enter` = open merge confirm, `d` = dismiss, `s` = swap,
  `?` = shortcut legend; `aria-keyshortcuts` + visible legend + everything equally clickable
  (keys are accelerators, not the only path — WCAG 2.1.1/2.1.4: single-char shortcuts only
  active when a row has focus, not global).
- **Un-migrated dismissals table**: probe once; hide Dismiss/Undismiss; render the
  run-the-migration card; `dismiss` POST → 409 (client branches on status, rule #35).
- **Emitters into this page** (rule #33 in the emit direction): a CTA on
  `/manage/musicians` beside the #846 bulk-promote CTA (:2128-2149) with a live count badge
  (cheap: Bucket A group count only — computed by a `countOnly` fast path, no scoring), and a
  cross-link line on bulk-promote's intro (:369-374). The new page reads NO deep-link params
  in v1 beyond the GET filters it defines — nothing emits any others.

---

## §8 Disambiguation in EVERY merge affordance (problem 2 closed everywhere)

1. **`?action=merge_target_search`** (musicians.php:274; helper :718-783): each candidate
   gains ADDITIVE fields from `musicianDisambiguationPayload()` (registry bucket only; the
   in-use-only bucket keeps name+total and gains `variant` vs the exclude-name):
   `disambiguation, born, died, type, useCount, variant` (the classifier vs the SOURCE name
   when `exclude_name` present). Additive ⇒ the member-picker consumer (:4335-4336 filters on
   `c.id`) is untouched.
2. **The Merge modal** (musicians.php:2610-2699): datalist option labels become
   disambiguated — `Eddie James — #45 · 12 credits · b. 1961` — and the `labelToKey` maps key
   on the FULL label (today two byte-variant names render two visually identical options,
   :4338-4341). On target pick, the credit-preview block (:2668-2681) gains a second column:
   source vs target payload chips + the variant badge when the two names classify — the
   direct answer to "which is merging into which?".
3. **Bulk-promote** (:487-497 best-match cell, :550-563 merge options): option labels gain
   `#id · born · uses`; a `variant` classification against the candidate renders the same
   badge. Its scorer becomes the shared `ihymns_sim_name_score()` (§4.1) and its baked
   `validateCsrf` upgrades to `validateCsrfRequest()` (rule #29; the form still posts the
   token so behaviour is a strict widening).
4. **The new review page** — native (§7).

One render helper for the badge/chips on the PHP side (a small
`manage/includes/musician-disambiguation.php` partial or functions in
`musician_duplicates.php` — builder's choice, but ONE place; the modularity rule).

---

## §9 Commit sequence (ONE PR to `alpha`; each commit atomic + individually revertable)

| # | Commit | Files | Approach | Verification | Dormant? |
|---|---|---|---|---|---|
| C1 | `schema: musician duplicate-dismissals table (#1785)` | `appWeb/.sql/migrate-musician-duplicates-dismissed.php`, `appWeb/.sql/schema.sql`, `manage/includes/migration-registry.php` | §3.5 DDL, byte-identical mirror, ONE registry entry | `php -l`; `php tools/run-php-tests.php` (schema-coverage + migration-registry suites); run the card twice on the local scratch DB (idempotence); probe flips pending→applied | **Pure no-op** — nothing reads it |
| C2 | `similarity: shared NAME scorer + variant classifier (#1785, rule #22)` | `includes/song_similarity.php`, `includes/musician_helpers.php`, `tests/php/test-musician-name-similarity.php` | §4.1 fold refactor + name fns; §4.2 classifier + detail helper; §4.3 six-role constant (defined, not yet consumed) | `php -l`; **existing `test-song-similarity.php` passes UNCHANGED** (fold-refactor proof); new pure test green (G1 fixture matrix, §10) | No consumer changed — verified no-op |
| C3 | `bulk-promote: delete the private similarity fork (#1785)` | `manage/musicians-bulk-promote.php` | `_musBulkNormalise/_musBulkTokens/_musBulkSimilarity` (:58-115) deleted; call sites → `ihymns_sim_name_*`; `require song_similarity.php`; CSRF → `validateCsrfRequest()` | `php -l`; fixture diff: score table over ~20 known name pairs pre/post — identical for ASCII, documented improvement for accented; page renders identically on scratch data | Behaviour delta limited to accented-name scores (improvement, documented in commit body) |
| C4 | `musicians: extract the ONE merge core (#1785)` | `includes/musician_helpers.php`, `manage/musicians.php`, `api.php` | §5.1 `musicianMergeExecute()`; both existing surfaces delegate | `php -l`; scratch-DB fixture diff: identical end-state + report array via BOTH surfaces pre/post refactor | Behaviour-preserving |
| C5 | `musicians: merge-core hardening — six tables, alias/relation carry (#1785)` | `includes/musician_helpers.php`, `manage/musicians.php`, `manage/musicians-bulk-promote.php` | §5.2 items 1-4; all forked five-table lists → `MUSICIAN_CREDIT_ROLE_TABLES` | `php -l`; behavioural on scratch DB: merge a source with artist credits + aliases + a group membership → all land on target; alias-collision skip; un-migrated-alias-table install degrades (probe-gated) | Visible: artists join counts/cascades (owner D1) |
| C6 | `musicians: registry-duplicate scan helper (#1785)` | `includes/musician_duplicates.php`, `tests/php/test-musician-dup-scan.php` | §6; pure candidate-generation split from DB reads so blocking + survivor-suggestion are unit-testable without a DB | `php -l`; unit test: seeded name arrays → expected groups/pairs/misses; skipped-bucket stat fires on an over-cap synthetic bucket | Unused until C7 |
| C7 | `manage: /manage/musician-duplicates review page (#1785)` | `manage/musician-duplicates.php`, `manage/musicians.php` (CTA), `manage/musicians-bulk-promote.php` (cross-link) | §7 in full | `php -l`; behavioural on scratch DB seeded with §3.1's coexisting variants (`Eddie  James`, `O’Brien`, `J Newton`) + a fuzzy pair: groups/pairs render, one-click merge works, dismiss persists + survives reload, undismiss restores, force-gate on a conflicting-lifespan pair, keyboard pass, 409 on dismiss when table absent | Page is additive |
| C8 | `musicians: disambiguation payload in every merge affordance (#1785)` | `manage/musicians.php`, `includes/musician_helpers.php` (`searchMusicianMergeTargets` additive fields), `manage/musicians-bulk-promote.php` | §8.1-8.3 | `php -l`; `node --check` n/a (inline modules); manual: typeahead shows disambiguated labels; modal preview shows both sides + badge; member-picker unaffected (additive-fields check) | Additive |
| C9 | `guards: #1785 invariants (mutation-proven)` | `tests/php/…`, `tests/…` | §10 | each guard broken→red→restored, documented per-guard in the commit body (rule #34) | n/a |
| C10 | `docs + standing tasks (#1785)` | CHANGELOG, DEV_NOTES, `iHymns.wiki/` pages (Admin/Schema; note if no checkout in env — say so, the #1783 precedent), `.claude/ProjectBrief.md`, this file → as-built header; issue updates + new issues (§11) | — | standing-tasks checklist | n/a |

Deploy note: C1's card must be RUN on alpha before dismissals persist there; C6-C8 are safe
deployed first (probe-gated 409/hidden buttons). No other env coupling — this feature has no
channel semantics (shared MySQL, admin-only surface).

---

## §10 Guards (rule #34 — tree-derived, mutation-proven, narrow)

- **G1 `tests/php/test-musician-name-similarity.php`** (pure, C2): fixture matrix —
  scorer: (`J. Newton`,`John Newton`) ≥ threshold-band asserted as a RANGE not an exact float;
  (`Newton, John`,`John Newton`) comma-reversal band; (`José`,`Jose`) = 1.0;
  (`John Newton`,`Charles Wesley`) < 0.5. Classifier: trailing space → `whitespace`;
  NBSP-for-space → `whitespace`; interior double space → `whitespace`; case-only → `case`;
  combining-vs-precomposed `é` → `unicode-normalisation`; `O'Brien`/`O’Brien` → `punctuation`;
  `J. Newton`/`J Newton` → `punctuation`; (`John`,`Jane`) → null. **Mutation-prove**: comment
  out the NFC branch → red; swap the blend weights → red.
- **G2 `tests/php/test-musician-dedup-shared-scorer.php`** (static, comment-stripped,
  tree-derived): derive every PHP file under `appWeb/public_html` whose comment-stripped
  source contains `levenshtein(` or `similar_text(`; assert the set is exactly
  `{includes/song_similarity.php}` plus any pre-existing legitimate users discovered at
  derivation time (derive, don't type — then pin the derived set with a comment explaining
  each). A re-forked private scorer anywhere goes red. **Mutation-prove**: paste a
  `levenshtein(` call into bulk-promote → red.
- **G3 `tests/php/test-musician-credit-tables-single-list.php`** (static, tree-derived):
  find every file whose source contains ≥3 of the six role-table literals within one array or
  UNION (regex over comment-stripped source); assert each such site is either
  `MUSICIAN_CREDIT_ROLE_TABLES` itself, `ED2_CREDIT_TABLES` (the editor's map — asserted
  key-set-equal to the helpers constant so the TWO registries cannot drift, rule #35's
  mechanism), or a consumer referencing one of those constants. **Mutation-prove**: re-inline
  the five-table array in api.php's merge → red; drop `artists` from one constant → red
  (set-equality leg).
- **G4 scan honesty** (rides G1's file or `test-musician-dup-scan.php`, C6): the pure
  candidate-generator, fed a synthetic over-cap bucket, MUST return it in
  `stats.skippedBuckets` (no silent cap); fed §3.1's coexisting-variant fixtures, Bucket A
  finds every pair; fed a differing-Disambiguation same-fold pair, it excludes it.
  **Mutation-prove**: raise the cap check to `> PHP_INT_MAX` → the skipped-bucket assertion
  goes red.
- **G5 merge-core singleness** (static): derive every `DELETE FROM tblMusicians` +
  `UPDATE tblSong… SET Name` statement across `manage/` + `api.php` + `includes/`; assert all
  live inside `musicianMergeExecute()` / `musicianReconcileCreditNameBytes()` / the rename
  handler (derived allow-set with a one-line justification each). **Mutation-prove**: paste an
  inline five-table UPDATE loop back into api.php → red.
- **Riding existing suites**: `test-schema-coverage`, `test-migration-registry` (C1),
  `test-song-similarity` (C2's refactor-proof), `test-csrf-same-origin` (if it derives POST
  handlers, the new page's dispatcher is auto-covered — builder must verify it picks the new
  file up and, if its derivation excludes `manage/`, extend the derivation rather than
  hand-listing), `test-admin-gate-parity` (new page's gate vs its emitting nav surface),
  PHP/JS syntax sweeps. Runners are glob-derived (tools/run-php-tests.php:56) — new test files
  cannot be silently unlisted.

---

## §11 Standing tasks (file at discovery moment — CLAUDE.md §2)

- **#1785**: becomes the implementation tracker; update with commit SHAs per landing.
- **#1787 (epic)**: link this plan file + the commit list.
- **#1784**: cross-reference — reconcile's 'ambiguous' path is drift-armour only (§3.1);
  Bucket A is the real registry-duplicate surface.
- **NEW issue (bug)**: "`tblSongArtists` excluded from every musician-registry cascade &
  count" — evidence §1 row 4; closed by C5.
- **NEW issue (bug/data-loss)**: "Musician merge silently cascade-deletes source aliases,
  relations and MBID" — closed for aliases/relations by C5; MBID + biographical COALESCE-fill
  → **NEW `for consideration` issue** (§5.2 item 5).
- **NEW `for consideration`**: warn at person-CREATE time when the new name fold-matches an
  existing registry row (the prevention side of this detection).
- **NEW `for consideration`**: unify the two musician dedup surfaces (this page +
  bulk-promote) the way #1215 unified the song side — only if the owner finds two pages
  confusing in practice.
- Wiki: Admin-guide page for the new surface + Schema page for the new table (if no
  `iHymns.wiki/` checkout in the build env, say so explicitly in the handoff — the #1783
  precedent).
- CHANGELOG / DEV_NOTES / `ProjectBrief.md` schema-summary delta; sessions sync.

---

## §12 Owner decisions (the required shape)

### D1 — Do artist credits (`tblSongArtists`) join the musician-registry counts and cascades?

1. **The decision**: whether `tblSongArtists` becomes the sixth table in every registry-side
   surface (merge/rename cascades, usage counts, bulk-promote candidates), or joins the
   CASCADES only, leaving counts/candidates at five tables.
2. **Why it needs deciding**: the editor already promotes artist names INTO the registry
   (api2.php:2568), but every registry-side surface ignores the artists table — so a merge
   today strands artist credits on a deleted spelling (a real bug), while *fixing* it fully
   means performing artists (bands, worship collectives) start appearing in the "cited but
   unregistered" counter that #1784 just drove to zero — a visible product change, not a code
   question.
3. **Options**:

| | Consequence |
|---|---|
| **A — six tables everywhere** | Correct + consistent; the unregistered counter jumps by however many artist names exist on alpha (measurable pre-decision: `SELECT COUNT(DISTINCT Name) FROM tblSongArtists WHERE NOT EXISTS (…)`); bulk-promote absorbs them once |
| **B — six in cascades, five in counters** | Fixes the data bug without the counter jump; but the two "what is a credit" definitions diverge permanently — the exact rule-#35 drift class, needing a documented exemption in G3 |
| **Do nothing** | Merges keep stranding artist credits |

4. **Recommendation**: **A.** The registry already contains these names; hiding them from the
   counters just misreports reality, and the counter jump is a one-time bulk-promote session.
   B's split definition is the kind of two-truths arrangement this codebase keeps paying for.
5. **What I need back**: "A" or "B". **Blocks nothing** — C5 lands either way; A↔B is a
   one-line constant/flag change and the plan builds A.

### Sub-decisions taken on defensible defaults (all trivially changeable, none blocking)

- **D2 survivor default**: use-count → metadata richness → lower Id; always visible, always
  swappable. (Pure function + unit test; changing the order is one function.)
- **D3 merge preserves the source name as a target alias** (`misspelling` for
  variant-class pairs, `search-hint` otherwise; skip on unique-collision). Default: yes —
  reversible by deleting the alias row.
- **D4 entitlements**: everything on the new page under the existing `manage_musicians`
  (already the merge gate on both existing surfaces; both candidate entitlements map to
  admin+ today so a split would be indistinguishable). Default: no new entitlement.
- **D5 type-to-confirm trigger**: only conflicting-lifespan pairs (`force=1` + typed MERGE),
  everything else a proportionate confirm dialog. Mirrors #1218's "guard the dangerous class
  only".

---

## §13 Risks & what would force a second migration (rule #20 adversarial)

- **The un-shipped suggestions table.** Rule #20 says one-pass the FAMILY; I am deliberately
  NOT shipping a dormant `tblMusicianDupeSuggestions`. Argument: the family shipping here is
  *curator decisions* (dismissals) — scale-independent, fully stressed in §3.6. A precompute
  cache is a *performance* artefact whose need is speculative (requires ~4× the projected
  registry AND a corpus ~10× today's), whose shape would be guessed against an unmeasured
  workload, and rule #20's own closing clause warns against shipping a guessed bridge. If it
  is ever needed it is a NEW additive table (no ALTER of anything shipped here), i.e. the
  cheap kind of second migration. Recorded so a future session doesn't read the absence as an
  oversight.
- **Score-on-dismissal column.** Excluded on stale-data grounds (§3.5). If an audit
  requirement ever wants "what score did the curator see", that is one additive ADD COLUMN —
  accepted residual.
- **Fold refactor regression risk** (C2): mitigated by requiring the EXISTING
  `test-song-similarity.php` to pass unchanged; any diff in `ihymns_sim_normalise()` output
  is a red build, not a silent drift.
- **Scoring-behaviour drift in bulk-promote** (C3): accented names now match better; the 0.85
  threshold semantics for ASCII names is fixture-diffed identical. Worst case a curator sees a
  few MORE suggestions — the safe direction (suggestions are reviewed, never auto-applied:
  the PR #980 no-data-destruction guarantee is untouched).
- **Merge-core extraction risk** (C4): the two forks differ only in envelope; the fixture
  diff (both surfaces, scratch DB, before/after) is the proof. The api.php surface keeps its
  raw-Role gate — tightening it to `userHasEntitlement('manage_musicians')` is a one-liner the
  builder should include ONLY if `test-admin-gate-parity` demands it; otherwise leave for the
  entitlement-sweep issue (don't widen this PR).
- **datalist label collisions** (§8.2): labels embed `#Id`, so two same-looking names can no
  longer collide in the label→key map — but a curator's stale typed text that matches no label
  must keep failing CLOSED (the existing "pick a suggested person first" alert path,
  musicians.php:4367-4370).
- **Performance regression as the registry grows**: the scan reports `elapsedMs` in its stats
  (visible on-page). If alpha ever shows it climbing toward seconds, that is the trigger for
  the §3.4 escape hatch — a measured decision, not a guess.
- **uk_Name relaxation** (future same-name-different-people support): Bucket A's
  Disambiguation exclusion (§3.1) is already in; the variant classifier is unaffected; the
  merge force-gate on conflicting lifespans is the safety net. No schema shipped here blocks
  that future.
