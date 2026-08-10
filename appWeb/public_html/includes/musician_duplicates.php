<?php

declare(strict_types=1);

/**
 * iHymns — Musician registry-duplicate scan (#1785 C6)
 * ======================================================================
 *
 * ELI5: this is the "spot two entries in the Musicians list that are
 * probably the same person" engine. It reads every registry row once,
 * groups the ones that look like the exact same spelling (just an extra
 * space, a curly-vs-straight apostrophe, …), and separately flags pairs
 * whose WORDS are similar enough to be worth a curator's look ("J.
 * Newton" vs "John Newton"). It never merges anything itself — it only
 * produces the candidate list the review page (`/manage/musician-
 * duplicates`, #1785 §7) renders.
 *
 * DETAILED / WHY THIS SHAPE (plan §3, §6 — .claude/musicians-dedup-1785-
 * plan.md)
 * ----------------------------------------------------------------------
 * A naive all-pairs comparison over the whole registry is too slow to run
 * on every page load (§3.2's benchmark: ~30-70s projected at N≈5,000), and
 * a precomputed suggestions table (the tblSongLinkSuggestions shape) was
 * REJECTED for this domain (§3.4) — there is no cron on this host, so a
 * precompute table goes stale silently between manual rebuilds, and the
 * registry is two orders of magnitude lighter than the song corpus that
 * justified paying that staleness cost for songs. Instead this file uses
 * BLOCKING (§3.3): cheap keys (an exact fold, or a metaphone of the
 * first/last name token) group rows into small buckets, and only pairs
 * that share a bucket are ever scored with the real similarity maths —
 * collapsing candidate pairs from O(N²) to O(tens of thousands), which
 * benchmarks sub-second at the registry's projected scale (§0 point 1).
 *
 * THREE BUCKETS, one purpose each:
 *   A — fold-equal groups. Two rows whose ihymns_sim_name_normalise()
 *       fold is byte-identical are the SAME spelling under a different
 *       set of invisible bytes (extra space, curly apostrophe, accent
 *       mark, …) — confidence 'high', no scoring needed. §3.1 of the plan
 *       verified experimentally that these are the ONLY classes that can
 *       coexist as two distinct rows under tblMusicians' own uk_Name
 *       UNIQUE KEY (utf8mb4_unicode_ci already folds case/whitespace-run/
 *       NFC-form for two rows to coexist at all).
 *   B — fuzzy candidates. A pair sharing a first- or last-token metaphone
 *       block (or a whole-name metaphone block, for single-token names)
 *       is scored with the shared ihymns_sim_name_score() (rule #22 — the
 *       ONE scorer, includes/song_similarity.php, #1785 C2/C3) and kept
 *       when it clears the threshold.
 *   C — alias signal. A registry row whose fold matches ANOTHER row's
 *       tblMusicianAliases entry is a candidate too — the curator already
 *       recorded that spelling belongs to a specific person, so this is
 *       treated as confidence 'high' like Bucket A. Existence-gated on
 *       musicianAliasesTableExists() (rule #9).
 *
 * NO SILENT CAPS (rule #35): any block whose membership exceeds
 * MUSDUP_DEFAULT_BUCKET_CAP is skipped rather than scored (a 400-member
 * bucket is already ~80k pairs) — and the skip is reported in
 * `stats.skippedBuckets`, never swallowed. `tests/php/
 * test-musician-dup-scan.php` (G4) feeds a synthetic over-cap bucket and
 * asserts it appears there.
 *
 * DECLARED MISSES (documented here AND in the review page's help text —
 * a scanner that under-reports its own blind spots is worse than one that
 * states them, rule #34):
 *   (a) a pseudonym vs a real name (Fanny Crosby vs Frances van Alstyne)
 *       — no string metric finds this; Bucket C (curated aliases) is the
 *       intended fix once a curator records the link.
 *   (b) metaphone() is English-phonetics only — a transliterated non-Latin
 *       name may fold to ASCII that doesn't co-bucket with its own
 *       variants.
 *   (c) anything inside a skipped over-cap bucket — always visible in
 *       `stats.skippedBuckets`, never silently dropped.
 *
 * PURE vs DB-TOUCHING SPLIT (so the maths is unit-testable without a
 * database, mirroring includes/song_similarity.php's own framework-free
 * posture):
 *   - musicianDuplicatesFindCandidates()   — PURE. Takes plain arrays of
 *     {id,name,disambiguation} rows (+ optional alias rows), returns
 *     id-keyed groups/pairs/stats. No \mysqli anywhere in its call graph.
 *   - musicianDuplicatesSuggestSurvivor()  — PURE. Given two already-
 *     hydrated payloads, picks a DEFAULT survivor side; the review page
 *     always shows the direction explicitly and offers a swap (plan D2).
 *   - musicianDisambiguationPayloadBulk()  — DB. Bulk-hydrates a small set
 *     of ids into the rich "why do these look alike / which one should
 *     survive" payload (lifespan, per-role use-counts, link/alias/
 *     identifier counts, …) reused verbatim by #1785 §8 (the merge-target
 *     typeahead, the Merge modal preview, bulk-promote) — ONE payload
 *     builder, never re-forked per surface (the modularity rule).
 *   - musicianFindRegistryDuplicates()     — DB. The orchestrator: reads
 *     the registry + aliases + dismissed-pairs, calls the pure scan,
 *     hydrates only the ids actually referenced by a candidate, and
 *     applies the dismissal filter (§3.5 — a stored score would only go
 *     stale, so dismissals are TEXT decisions re-applied to a freshly
 *     scored pair every time, never a frozen snapshot).
 *
 * Direct access is blocked so this file can't be loaded as an arbitrary
 * endpoint via an open Apache config (the same guard every other
 * includes/*.php helper file in this codebase carries).
 *
 * @see .claude/musicians-dedup-1785-plan.md §3, §6
 * @see includes/musician_helpers.php        MUSICIAN_CREDIT_ROLE_TABLES, musicianNameVariantClass()
 * @see includes/song_similarity.php         ihymns_sim_name_normalise() / ihymns_sim_name_score()
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

/* Pulls in song_similarity.php transitively (musician_helpers.php already
   require_once's it) — idempotent, safe even if a caller already loaded
   either file this request. */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'musician_helpers.php';

/** Default fuzzy-match threshold (Bucket B) — mirrors the bulk-promote
 *  default this scan's scorer was extracted from (#1785 C2/C3), GET-tunable
 *  exactly like that page's own threshold param. */
const MUSDUP_DEFAULT_THRESHOLD = 0.85;

/** Default per-block membership cap before a bucket is SKIPPED rather than
 *  scored (rule #35 — the skip is reported, never silent). 400 members is
 *  already ~80k pairs at O(n²)/2. */
const MUSDUP_DEFAULT_BUCKET_CAP = 400;

/**
 * iHymns — schema-tolerant probe for tblMusicianDuplicatesDismissed
 * (#1785 C1). Mirrors musicianAliasesTableExists()'s cached
 * INFORMATION_SCHEMA idiom exactly (includes/musician_helpers.php) — an
 * un-migrated install (rule #9: migrations are web-run, not auto-applied)
 * must degrade rather than STRICT-throw on a missing table.
 */
function musicianDuplicatesDismissedTableExists(\mysqli $db): bool
{
    static $cached = null;
    if ($cached !== null) return $cached;
    try {
        $stmt = $db->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME   = 'tblMusicianDuplicatesDismissed' LIMIT 1"
        );
        $stmt->execute();
        $cached = $stmt->get_result()->fetch_row() !== null;
        $stmt->close();
    } catch (\Throwable $_e) {
        $cached = false;
    }
    return $cached;
}

/**
 * iHymns — every dismissed pair, as a `"minId|maxId"` lookup set (#1785).
 *
 * ELI5: the list of "a curator already looked at this pair and said
 * these are two different people" — read once per scan so every pair the
 * scorer finds can be checked against it in O(1).
 *
 * Returns [] on an un-migrated install (rule #9) — the scan still runs,
 * it just has nothing to suppress yet.
 */
function musicianDuplicatesFetchDismissedPairs(\mysqli $db): array
{
    $out = [];
    if (!musicianDuplicatesDismissedTableExists($db)) {
        return $out;
    }
    $res = $db->query('SELECT MusicianIdA, MusicianIdB FROM tblMusicianDuplicatesDismissed');
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $a = (int)$row['MusicianIdA'];
            $b = (int)$row['MusicianIdB'];
            $out[$a < $b ? "{$a}|{$b}" : "{$b}|{$a}"] = true;
        }
        $res->close();
    }
    return $out;
}

/**
 * iHymns — normalise a candidate pair id order into ONE lookup key,
 * matching tblMusicianDuplicatesDismissed's own (MusicianIdA < MusicianIdB)
 * convention (#1785 C1's schema doc-block). Kept as a tiny shared helper so
 * every pair-key computation in this file (and the review page) agrees.
 */
function musicianDuplicatesPairKey(int $a, int $b): string
{
    return $a < $b ? "{$a}|{$b}" : "{$b}|{$a}";
}

/**
 * iHymns — a partial-date-aware display label for the scan's payload
 * (#1785 §6). Reuses the SAME BirthDatePrecision/DeathDatePrecision
 * vocabulary ('year'|'month'|'day') the rest of the musicians surface
 * already writes (includes/partial_date.php family) — never re-derives
 * its own date-formatting rule.
 *
 * ELI5: "1823" for a year-only birth date, "1823-03" for a month-known
 * one, the full date for a day-known one, null when there's no date at
 * all.
 */
function musicianDuplicatesDateLabel(?string $date, ?string $precision): ?string
{
    if ($date === null || $date === '') {
        return null;
    }
    if ($precision === 'day') {
        return $date;
    }
    if ($precision === 'month') {
        return substr($date, 0, 7);
    }
    return substr($date, 0, 4);
}

/**
 * iHymns — PURE candidate-generator (#1785 §3.3/§6). NO \mysqli anywhere
 * in this function's call graph — every dependency (ihymns_sim_name_*(),
 * musicianNameVariantClass()) is itself pure — so `tests/php/
 * test-musician-dup-scan.php` (G4) can feed it plain synthetic arrays and
 * assert exact groups/pairs/misses without a database.
 *
 * ELI5: given a plain list of "id + name" rows (plus, optionally, a list
 * of recorded alias rows), find every pair that's probably the same
 * person, split into "definitely the same spelling" (Bucket A) and
 * "similar enough to review" (Bucket B/C) — see the file doc-block above
 * for what each bucket means and why blocking (not naive all-pairs)
 * makes this cheap enough to run on every page load.
 *
 * @param list<array{id:int,name:string,disambiguation?:string}> $rows
 * @param list<array{musicianId:int,name:string}> $aliasRows Bucket C source;
 *        pass [] to skip alias-signal detection entirely (e.g. an
 *        un-migrated install, or a unit test that only exercises A/B).
 * @param array{threshold?:float,bucketCap?:int,groupsOnly?:bool} $opts
 *        `groupsOnly` (default false): skip Bucket B/C entirely — the cheap
 *        fast path musicianDuplicatesCountBucketA() uses for the
 *        /manage/musicians CTA badge (plan §7's "count-only, no scoring").
 * @return array{
 *     groups: list<array{members:list<int>,pairs:list<array{0:int,1:int,2:?string}>}>,
 *     pairs:  list<array{aId:int,bId:int,score:float,signal:string,variant:?string}>,
 *     stats:  array{registryRows:int,foldGroups:int,fuzzyPairsScored:int,skippedBuckets:list<array{key:string,size:int}>,elapsedMs:float}
 * }
 */
function musicianDuplicatesFindCandidates(array $rows, array $aliasRows = [], array $opts = []): array
{
    $start = microtime(true);

    $threshold = isset($opts['threshold']) ? (float)$opts['threshold'] : MUSDUP_DEFAULT_THRESHOLD;
    if ($threshold < 0.5) { $threshold = 0.5; }
    if ($threshold > 1.0) { $threshold = 1.0; }
    $bucketCap = isset($opts['bucketCap']) ? (int)$opts['bucketCap'] : MUSDUP_DEFAULT_BUCKET_CAP;
    if ($bucketCap < 1) { $bucketCap = 1; }
    $groupsOnly = !empty($opts['groupsOnly']);

    /* Index + de-duplicate by id (defensive — a caller-supplied fixture
       could repeat an id; the live orchestrator never will). */
    $byId = [];
    foreach ($rows as $r) {
        $id = (int)($r['id'] ?? 0);
        if ($id <= 0 || isset($byId[$id])) { continue; }
        $byId[$id] = [
            'id'             => $id,
            'name'           => (string)($r['name'] ?? ''),
            'disambiguation' => trim((string)($r['disambiguation'] ?? '')),
        ];
    }
    $registryRows = count($byId);

    /* A pair whose BOTH sides carry differing non-empty Disambiguation is
       excluded from every bucket — that column exists precisely to let
       two same-named DISTINCT people coexist (plan §3.1); today's uk_Name
       UNIQUE KEY on tblMusicians makes that combination unreachable in
       practice, but the exclusion is one `if` and stays correct the day
       uk_Name is ever relaxed. Kept as a tiny shared predicate so Buckets
       A/B/C apply the SAME rule rather than three near-identical copies. */
    $pairAllowed = static function (array $a, array $b): bool {
        $da = $a['disambiguation']; $db_ = $b['disambiguation'];
        return !($da !== '' && $db_ !== '' && $da !== $db_);
    };

    /* Per-row precompute: fold + tokens + block keys (§3.3). */
    $fold      = [];
    $metaFirst = [];
    $metaLast  = [];
    $metaWhole = [];
    foreach ($byId as $id => $r) {
        $f = ihymns_sim_name_normalise($r['name']);
        $fold[$id] = $f;
        $tokens = $f === '' ? [] : array_values(array_filter(explode(' ', $f), static fn(string $t): bool => $t !== ''));
        if (count($tokens) >= 2) {
            $metaFirst[$id] = (string)metaphone($tokens[0]);
            $metaLast[$id]  = (string)metaphone($tokens[count($tokens) - 1]);
        } elseif (count($tokens) === 1) {
            $metaWhole[$id] = (string)metaphone($tokens[0]);
        }
    }

    $skippedBuckets = [];
    $seenPairs      = []; // pairKey => true, across ALL buckets — keeps §1/§2 mutually exclusive.
    $pairKey        = static fn(int $a, int $b): string => $a < $b ? "{$a}|{$b}" : "{$b}|{$a}";

    /* ---------------------------------------------------------------
     * Bucket A — fold-equal groups.
     * ------------------------------------------------------------- */
    $foldBuckets = [];
    foreach ($fold as $id => $f) {
        if ($f === '') { continue; }
        $foldBuckets[$f][] = $id;
    }
    $foldGroupCount = 0;
    $groups = [];
    foreach ($foldBuckets as $f => $ids) {
        if (count($ids) < 2) { continue; }
        if (count($ids) > $bucketCap) {
            $skippedBuckets[] = ['key' => 'fold:' . $f, 'size' => count($ids)];
            continue;
        }
        $foldGroupCount++;

        /* Union-find WITHIN this fold clique, skipping an excluded
           (differing-Disambiguation) edge so a legitimate future
           same-name-different-people pair never gets unioned together. */
        $parent = [];
        foreach ($ids as $id) { $parent[$id] = $id; }
        $find = function (int $x) use (&$parent, &$find): int {
            if ($parent[$x] !== $x) { $parent[$x] = $find($parent[$x]); }
            return $parent[$x];
        };
        $pairsInGroup = [];
        $n = count($ids);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $a = $ids[$i]; $b = $ids[$j];
                if (!$pairAllowed($byId[$a], $byId[$b])) { continue; }
                $ra = $find($a); $rb = $find($b);
                if ($ra !== $rb) { $parent[$ra] = $rb; }
                $variant = musicianNameVariantClass($byId[$a]['name'], $byId[$b]['name']);
                $pairsInGroup[] = [$a, $b, $variant];
                $seenPairs[$pairKey($a, $b)] = true;
            }
        }
        $components = [];
        foreach ($ids as $id) { $components[$find($id)][] = $id; }
        foreach ($components as $members) {
            if (count($members) < 2) { continue; } // singleton after exclusion — nothing to group
            $memberSet = array_flip($members);
            $memberPairs = array_values(array_filter(
                $pairsInGroup,
                static fn(array $p): bool => isset($memberSet[$p[0]], $memberSet[$p[1]])
            ));
            if (!$memberPairs) { continue; }
            $groups[] = ['members' => $members, 'pairs' => $memberPairs];
        }
    }

    $fuzzyPairsScored = 0;
    $pairs = [];

    if (!$groupsOnly) {
        /* -----------------------------------------------------------
         * Bucket B — fuzzy candidates via metaphone blocking.
         *
         * ONE combined dictionary for two-token-plus names, populated
         * under BOTH each name's first-token key AND its last-token key
         * — NOT two separate first-only / last-only dictionaries.
         * That's what actually catches a comma-reversed pair: "Newton,
         * John" tokenises to [newton, john] (first=newton, last=john)
         * while "John Newton" tokenises to [john, newton] (first=john,
         * last=newton) — the two names' OWN first/last never match each
         * other positionally, but "Newton, John"'s first token
         * metaphone equals "John Newton"'s LAST token metaphone (and
         * vice-versa), so only a SHARED key space finds them.
         * Single-token names get their own separate metaWhole
         * dictionary (a name with only one token has no first/last
         * split to cross-match against).
         * ----------------------------------------------------------- */
        $blockToken = [];
        $blockWhole = [];
        foreach ($metaFirst as $id => $m) { if ($m !== '') { $blockToken[$m][] = $id; } }
        foreach ($metaLast  as $id => $m) { if ($m !== '') { $blockToken[$m][] = $id; } }
        foreach ($blockToken as $m => $ids) { $blockToken[$m] = array_values(array_unique($ids)); }
        foreach ($metaWhole as $id => $m) { if ($m !== '') { $blockWhole[$m][] = $id; } }

        $scoreBlock = function (array $ids, string $blockLabel) use (
            &$skippedBuckets, $bucketCap, &$seenPairs, $pairKey, &$fuzzyPairsScored,
            &$pairs, $byId, $threshold, $pairAllowed
        ): void {
            if (count($ids) < 2) { return; }
            if (count($ids) > $bucketCap) {
                $skippedBuckets[] = ['key' => $blockLabel, 'size' => count($ids)];
                return;
            }
            $n = count($ids);
            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $a = $ids[$i]; $b = $ids[$j];
                    $key = $pairKey($a, $b);
                    if (isset($seenPairs[$key])) { continue; } // already a Bucket A pair, or scored via another block
                    if (!$pairAllowed($byId[$a], $byId[$b])) { continue; }
                    $seenPairs[$key] = true; // mark BEFORE scoring so overlapping blocks (first+last token) never double-count
                    $fuzzyPairsScored++;
                    $score = ihymns_sim_name_score($byId[$a]['name'], $byId[$b]['name']);
                    if ($score < $threshold) { continue; }
                    $pairs[] = [
                        'aId'    => $a,
                        'bId'    => $b,
                        'score'  => $score,
                        'signal' => 'fuzzy',
                        'variant' => musicianNameVariantClass($byId[$a]['name'], $byId[$b]['name']),
                    ];
                }
            }
        };
        foreach ($blockToken as $m => $ids) { $scoreBlock($ids, 'metaToken:' . $m); }
        foreach ($blockWhole as $m => $ids) { $scoreBlock($ids, 'metaWhole:' . $m); }

        /* -----------------------------------------------------------
         * Bucket C — alias signal (gated by the CALLER passing
         * $aliasRows; the live orchestrator only does so when
         * musicianAliasesTableExists() is true, rule #9).
         * ----------------------------------------------------------- */
        if ($aliasRows) {
            $aliasByFold = [];
            foreach ($aliasRows as $al) {
                $ownerId = (int)($al['musicianId'] ?? 0);
                $name    = (string)($al['name'] ?? '');
                if ($ownerId <= 0 || $name === '' || !isset($byId[$ownerId])) { continue; }
                $f = ihymns_sim_name_normalise($name);
                if ($f === '') { continue; }
                $aliasByFold[$f][] = $ownerId;
            }
            foreach ($aliasByFold as $f => $ownerIds) {
                $matchIds = $foldBuckets[$f] ?? [];
                if (!$matchIds) { continue; }
                $candidateIds = array_values(array_unique(array_merge($matchIds, $ownerIds)));
                if (count($candidateIds) > $bucketCap) {
                    $skippedBuckets[] = ['key' => 'alias:' . $f, 'size' => count($candidateIds)];
                    continue;
                }
                foreach ($ownerIds as $ownerId) {
                    foreach ($matchIds as $matchId) {
                        if ($matchId === $ownerId) { continue; } // the alias IS this row's own name — not a signal
                        $key = $pairKey($ownerId, $matchId);
                        if (isset($seenPairs[$key])) { continue; }
                        if (!$pairAllowed($byId[$ownerId], $byId[$matchId])) { continue; }
                        $seenPairs[$key] = true;
                        $pairs[] = [
                            'aId'     => $ownerId,
                            'bId'     => $matchId,
                            'score'   => 1.0,
                            'signal'  => 'alias-match',
                            'variant' => musicianNameVariantClass($byId[$ownerId]['name'], $byId[$matchId]['name']),
                        ];
                    }
                }
            }
        }
    }

    return [
        'groups' => $groups,
        'pairs'  => $pairs,
        'stats'  => [
            'registryRows'     => $registryRows,
            'foldGroups'       => $foldGroupCount,
            'fuzzyPairsScored' => $fuzzyPairsScored,
            'skippedBuckets'   => $skippedBuckets,
            'elapsedMs'        => round((microtime(true) - $start) * 1000, 2),
        ],
    ];
}

/**
 * iHymns — PURE default-survivor picker (#1785 §6 plan D2). Given two
 * already-hydrated `personPayload`s (musicianDisambiguationPayloadBulk()'s
 * shape), decide which side a merge should default to KEEPING. This is
 * ONLY a default — the review page always shows the direction explicitly
 * and offers a swap control, so getting this "wrong" for an edge case is
 * a UX inconvenience, never a data-safety issue.
 *
 * Ladder: higher song-credit use-count wins outright (the person actually
 * cited on songs is the one worth keeping); a tie falls to whichever side
 * carries richer metadata (a non-empty lifespan/disambiguation/MBID, or
 * any links/identifiers/aliases); a further tie falls to the lower id
 * (the OLDER registry row, on the theory that an earlier-created entry is
 * more likely to be the one other data already points at).
 */
function musicianDuplicatesSuggestSurvivor(array $a, array $b): string
{
    $useA = (int)($a['useCount'] ?? 0);
    $useB = (int)($b['useCount'] ?? 0);
    if ($useA !== $useB) {
        return $useA > $useB ? 'a' : 'b';
    }

    $richness = static function (array $p): int {
        $score = 0;
        if (!empty($p['born']))           { $score++; }
        if (!empty($p['died']))           { $score++; }
        if (!empty($p['disambiguation'])) { $score++; }
        if (!empty($p['hasMbid']))        { $score++; }
        if ((int)($p['links'] ?? 0) > 0)       { $score++; }
        if ((int)($p['identifiers'] ?? 0) > 0) { $score++; }
        if ((int)($p['aliases'] ?? 0) > 0)     { $score++; }
        return $score;
    };
    $richA = $richness($a);
    $richB = $richness($b);
    if ($richA !== $richB) {
        return $richA > $richB ? 'a' : 'b';
    }

    $idA = (int)($a['id'] ?? 0);
    $idB = (int)($b['id'] ?? 0);
    return $idA <= $idB ? 'a' : 'b';
}

/**
 * iHymns — bulk-hydrate a small set of tblMusicians ids into the rich
 * "disambiguation payload" every #1785 merge affordance renders (#1785
 * §6/§8): id, slug, type, lifespan, per-role use-counts, link/identifier/
 * alias counts, MBID presence, created-at. ONE builder, reused verbatim
 * by the registry-duplicate scan (this file), the merge-target typeahead
 * and Merge-modal preview (manage/musicians.php, #1785 C8), and
 * bulk-promote's candidate labels (manage/musicians-bulk-promote.php,
 * #1785 C8) — never re-forked per surface (the modularity rule).
 *
 * Every optional column is existence-gated (rule #9) via the SAME cached
 * probes the rest of this musicians family already uses
 * (musicianProfileColumnsExist() / musicianDatePrecisionColumnsExist() /
 * musicianSlugColumnExists() / musicianFlagsColumnsExist() /
 * musicianAliasesTableExists(), includes/musician_helpers.php) — a
 * partly-migrated install degrades to sensible defaults ('person' type,
 * no lifespan, no aliases-count) rather than a STRICT "Unknown column"
 * throw.
 *
 * @param list<int> $ids tblMusicians.Id values to hydrate. A caller may
 *        pass a subset of the whole registry — the scan only ever
 *        hydrates ids that actually appear in a candidate group/pair, so
 *        a registry of thousands only ever pays for the handful under
 *        review on a given page load.
 * @return array<int,array{
 *     id:int,name:string,slug:?string,type:string,isGroup:bool,
 *     isSpecialCase:bool,disambiguation:string,born:?string,died:?string,
 *     useCount:int,roles:array<string,int>,links:int,identifiers:int,
 *     aliases:int,hasMbid:bool,createdAt:string
 * }> Keyed by Id; an id with no matching row is simply absent.
 */
function musicianDisambiguationPayloadBulk(\mysqli $db, array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $v): bool => $v > 0)));
    if (!$ids) { return []; }

    $ph    = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));

    $profileCols      = musicianProfileColumnsExist($db);
    $hasDatePrecision = musicianDatePrecisionColumnsExist($db);
    $hasSlug          = musicianSlugColumnExists($db);
    $hasFlags         = musicianFlagsColumnsExist($db);

    /* Column fragments are either a real column reference or a hardcoded
       literal fallback — both are fixed PHP-source strings (never user
       input), so interpolating them into the SELECT list is the rule #5
       carve-out; every bound VALUE below still goes through bind_param(). */
    $typeCol      = $profileCols['Type']          ? 'p.Type'           : "'person'";
    $disambigCol  = $profileCols['Disambiguation'] ? 'p.Disambiguation' : "''";
    $slugCol      = $hasSlug   ? 'p.Slug'          : 'NULL';
    $isGroupCol   = $hasFlags  ? 'p.IsGroup'       : '0';
    $isSpecialCol = $hasFlags  ? 'p.IsSpecialCase' : '0';
    $birthPrecCol = $hasDatePrecision ? 'p.BirthDatePrecision' : 'NULL';
    $deathPrecCol = $hasDatePrecision ? 'p.DeathDatePrecision' : 'NULL';

    $stmt = $db->prepare(
        "SELECT p.Id, p.Name, {$slugCol} AS Slug, {$typeCol} AS Type,
                {$isGroupCol} AS IsGroup, {$isSpecialCol} AS IsSpecialCase,
                {$disambigCol} AS Disambiguation,
                p.BirthDate, {$birthPrecCol} AS BirthDatePrecision,
                p.DeathDate, {$deathPrecCol} AS DeathDatePrecision,
                p.MusicBrainzArtistMBID, p.CreatedAt
           FROM tblMusicians p
          WHERE p.Id IN ($ph)"
    );
    $stmt->bind_param($types, ...$ids);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (!$rows) { return []; }

    $nameById = [];
    foreach ($rows as $r) { $nameById[(int)$r['Id']] = (string)$r['Name']; }
    /* Safe because Name carries tblMusicians' own uk_Name UNIQUE KEY — one
       name can only ever belong to one id in this batch. */
    $idByName = array_flip($nameById);
    $names    = array_values($nameById);

    /* Per-role song-credit use counts — the SAME six-table union every
       other registry-side surface reads (rule #22 — MUSICIAN_CREDIT_ROLE_
       TABLES, includes/musician_helpers.php), scoped to just this batch's
       names rather than the whole corpus. */
    $useCounts = [];
    foreach (array_keys($nameById) as $id) {
        $useCounts[$id] = array_fill_keys(array_keys(MUSICIAN_CREDIT_ROLE_TABLES), 0) + ['total' => 0];
    }
    if ($names) {
        $namePh    = implode(',', array_fill(0, count($names), '?'));
        $nameTypes = str_repeat('s', count($names));
        foreach (MUSICIAN_CREDIT_ROLE_TABLES as $role => $tbl) {
            $ustmt = $db->prepare("SELECT Name, COUNT(*) AS cnt FROM {$tbl} WHERE Name IN ($namePh) GROUP BY Name");
            $ustmt->bind_param($nameTypes, ...$names);
            $ustmt->execute();
            foreach ($ustmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
                $id = $idByName[(string)$r['Name']] ?? null;
                if ($id === null) { continue; }
                $cnt = (int)$r['cnt'];
                $useCounts[$id][$role]  = $cnt;
                $useCounts[$id]['total'] += $cnt;
            }
            $ustmt->close();
        }
    }

    $linkCounts  = array_fill_keys(array_keys($nameById), 0);
    $idCounts    = array_fill_keys(array_keys($nameById), 0);
    $aliasCounts = array_fill_keys(array_keys($nameById), 0);

    $lstmt = $db->prepare("SELECT MusicianId, COUNT(*) AS cnt FROM tblMusicianExternalLinks WHERE MusicianId IN ($ph) GROUP BY MusicianId");
    $lstmt->bind_param($types, ...$ids);
    $lstmt->execute();
    foreach ($lstmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) { $linkCounts[(int)$r['MusicianId']] = (int)$r['cnt']; }
    $lstmt->close();

    $istmt = $db->prepare("SELECT MusicianId, COUNT(*) AS cnt FROM tblMusicianIdentifiers WHERE MusicianId IN ($ph) GROUP BY MusicianId");
    $istmt->bind_param($types, ...$ids);
    $istmt->execute();
    foreach ($istmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) { $idCounts[(int)$r['MusicianId']] = (int)$r['cnt']; }
    $istmt->close();

    if (musicianAliasesTableExists($db)) {
        $astmt = $db->prepare("SELECT MusicianId, COUNT(*) AS cnt FROM tblMusicianAliases WHERE MusicianId IN ($ph) GROUP BY MusicianId");
        $astmt->bind_param($types, ...$ids);
        $astmt->execute();
        foreach ($astmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) { $aliasCounts[(int)$r['MusicianId']] = (int)$r['cnt']; }
        $astmt->close();
    }

    $out = [];
    foreach ($rows as $r) {
        $id = (int)$r['Id'];
        $out[$id] = [
            'id'             => $id,
            'name'           => (string)$r['Name'],
            'slug'           => $r['Slug'] !== null ? (string)$r['Slug'] : null,
            'type'           => (string)$r['Type'],
            'isGroup'        => (int)$r['IsGroup'] === 1,
            'isSpecialCase'  => (int)$r['IsSpecialCase'] === 1,
            'disambiguation' => (string)$r['Disambiguation'],
            'born'           => musicianDuplicatesDateLabel($r['BirthDate'] !== null ? (string)$r['BirthDate'] : null, $r['BirthDatePrecision'] ?? null),
            'died'           => musicianDuplicatesDateLabel($r['DeathDate'] !== null ? (string)$r['DeathDate'] : null, $r['DeathDatePrecision'] ?? null),
            'useCount'       => $useCounts[$id]['total'] ?? 0,
            'roles'          => array_intersect_key($useCounts[$id] ?? [], MUSICIAN_CREDIT_ROLE_TABLES),
            'links'          => $linkCounts[$id] ?? 0,
            'identifiers'    => $idCounts[$id] ?? 0,
            'aliases'        => $aliasCounts[$id] ?? 0,
            'hasMbid'        => trim((string)($r['MusicBrainzArtistMBID'] ?? '')) !== '',
            'createdAt'      => (string)$r['CreatedAt'],
        ];
    }
    return $out;
}

/**
 * iHymns — the registry-duplicate scan orchestrator (#1785 §6). Reads the
 * live registry + aliases + dismissed-pairs, runs the PURE candidate
 * generator, hydrates only the ids a candidate actually references, and
 * applies the dismissal filter.
 *
 * ELI5: this is the function `/manage/musician-duplicates` (#1785 §7)
 * calls on every page load — "go find the probable duplicates right now".
 *
 * @param array{threshold?:float,bucketCap?:int,includeDismissed?:bool} $opts
 *        `includeDismissed` (default false): when false, a group/pair
 *        whose every internal pair is already dismissed is DROPPED from
 *        the result entirely. When true, every candidate is returned
 *        regardless, each carrying an accurate `dismissed` flag — the
 *        `?show=dismissed` review-page view passes true and filters to
 *        dismissed-only itself, so callers get both "what's active" and
 *        "what was dismissed" from the SAME scan without a second pass.
 * @return array{
 *     groups: list<array{members:list<array>,pairs:list<array{0:int,1:int,2:?string}>,dismissed:bool}>,
 *     pairs:  list<array{a:array,b:array,score:float,signal:string,variant:?string,suggestedSurvivor:string,dismissed:bool}>,
 *     stats:  array{registryRows:int,foldGroups:int,fuzzyPairsScored:int,skippedBuckets:array,elapsedMs:float}
 * }
 */
function musicianFindRegistryDuplicates(\mysqli $db, array $opts = []): array
{
    $includeDismissed = !empty($opts['includeDismissed']);

    $profileCols = musicianProfileColumnsExist($db);
    $disambigCol = $profileCols['Disambiguation'] ? 'Disambiguation' : "'' AS Disambiguation";
    $stmt = $db->prepare("SELECT Id, Name, {$disambigCol} FROM tblMusicians");
    $stmt->execute();
    $rawRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $rows = [];
    foreach ($rawRows as $r) {
        $rows[] = [
            'id'             => (int)$r['Id'],
            'name'           => (string)$r['Name'],
            'disambiguation' => (string)($r['Disambiguation'] ?? ''),
        ];
    }

    $aliasRows = [];
    if (musicianAliasesTableExists($db)) {
        $ares = $db->query('SELECT MusicianId, Name FROM tblMusicianAliases');
        if ($ares) {
            while ($row = $ares->fetch_assoc()) {
                $aliasRows[] = ['musicianId' => (int)$row['MusicianId'], 'name' => (string)$row['Name']];
            }
            $ares->close();
        }
    }

    $candidates = musicianDuplicatesFindCandidates($rows, $aliasRows, $opts);

    $dismissedPairs = musicianDuplicatesFetchDismissedPairs($db);

    $neededIds = [];
    foreach ($candidates['groups'] as $g) {
        foreach ($g['members'] as $id) { $neededIds[$id] = true; }
    }
    foreach ($candidates['pairs'] as $p) {
        $neededIds[$p['aId']] = true;
        $neededIds[$p['bId']] = true;
    }
    $payloads = $neededIds ? musicianDisambiguationPayloadBulk($db, array_keys($neededIds)) : [];

    $groupsOut = [];
    foreach ($candidates['groups'] as $g) {
        $allDismissed = true;
        foreach ($g['pairs'] as [$a, $b]) {
            if (!isset($dismissedPairs[musicianDuplicatesPairKey($a, $b)])) { $allDismissed = false; break; }
        }
        if ($allDismissed && !$includeDismissed) { continue; }
        $members = array_values(array_filter(array_map(
            static fn(int $id) => $payloads[$id] ?? null,
            $g['members']
        )));
        if (count($members) < 2) { continue; } // defensive — a payload failed to hydrate
        $groupsOut[] = [
            'members'   => $members,
            'pairs'     => $g['pairs'],
            'dismissed' => $allDismissed,
        ];
    }

    $pairsOut = [];
    foreach ($candidates['pairs'] as $p) {
        $dismissed = isset($dismissedPairs[musicianDuplicatesPairKey($p['aId'], $p['bId'])]);
        if ($dismissed && !$includeDismissed) { continue; }
        $pa = $payloads[$p['aId']] ?? null;
        $pb = $payloads[$p['bId']] ?? null;
        if (!$pa || !$pb) { continue; } // defensive — a payload failed to hydrate
        $pairsOut[] = [
            'a'                 => $pa,
            'b'                 => $pb,
            'score'             => $p['score'],
            'signal'            => $p['signal'],
            'variant'           => $p['variant'],
            'suggestedSurvivor' => musicianDuplicatesSuggestSurvivor($pa, $pb),
            'dismissed'         => $dismissed,
        ];
    }
    usort($pairsOut, static fn(array $x, array $y): int => $y['score'] <=> $x['score']);

    return [
        'groups' => $groupsOut,
        'pairs'  => $pairsOut,
        'stats'  => $candidates['stats'],
    ];
}

/**
 * iHymns — PURE lifespan-conflict check (#1785 §7). Given two hydrated
 * payloads, does their recorded lifespan actively DISAGREE — a signal
 * that the pair might be two genuinely different people who happen to
 * share (a variant of) a name, not one person spelled two ways?
 *
 * ELI5: if both sides have a birth year on file and the years are
 * different, that's a red flag worth a curator's extra "are you sure" —
 * this is the musician-registry sibling of the #1218 same-official-
 * songbook merge guard on `/manage/duplicate-songs`.
 *
 * DETAILED: compares at YEAR granularity (the coarsest precision either
 * side might carry — `musicianDuplicatesDateLabel()`'s year-only output
 * is already just the first 4 characters of ANY precision level), and
 * only when BOTH sides actually have a value for that field — a blank
 * side is "unknown", never treated as a conflict. Checked for birth AND
 * death independently; either disagreeing is a conflict. Applied
 * uniformly to EVERY merge action (byte-variant groups and fuzzy pairs
 * alike) — the review page's server-side POST handler re-checks this
 * regardless of which section a candidate rendered in, never trusting
 * the client's own force=1 flag alone (defense in depth for a
 * destructive, irreversible action).
 */
function musicianDuplicatesLifespanConflict(array $a, array $b): bool
{
    $bornA = $a['born'] ?? null; $bornB = $b['born'] ?? null;
    if ($bornA && $bornB && substr((string)$bornA, 0, 4) !== substr((string)$bornB, 0, 4)) {
        return true;
    }
    $diedA = $a['died'] ?? null; $diedB = $b['died'] ?? null;
    if ($diedA && $diedB && substr((string)$diedA, 0, 4) !== substr((string)$diedB, 0, 4)) {
        return true;
    }
    return false;
}

/**
 * iHymns — the CHEAP CTA-badge count (#1785 §7): "how many high-
 * confidence duplicate groups exist right now", WITHOUT paying for the
 * fuzzy-scoring pass. Bucket A only (`groupsOnly`), dismissal-filtered.
 * Used by the `/manage/musicians` "Musician duplicates" CTA badge — the
 * same "count only, don't render" idiom as that page's own #846
 * bulk-promote CTA badge, so a curator sees a non-zero number invite
 * them in without the full scan running on every load of a DIFFERENT
 * page.
 */
function musicianDuplicatesCountBucketA(\mysqli $db): int
{
    $profileCols = musicianProfileColumnsExist($db);
    $disambigCol = $profileCols['Disambiguation'] ? 'Disambiguation' : "'' AS Disambiguation";
    $stmt = $db->prepare("SELECT Id, Name, {$disambigCol} FROM tblMusicians");
    $stmt->execute();
    $rawRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $rows = [];
    foreach ($rawRows as $r) {
        $rows[] = [
            'id'             => (int)$r['Id'],
            'name'           => (string)$r['Name'],
            'disambiguation' => (string)($r['Disambiguation'] ?? ''),
        ];
    }

    $candidates = musicianDuplicatesFindCandidates($rows, [], ['groupsOnly' => true]);
    if (!$candidates['groups']) {
        return 0;
    }

    $dismissedPairs = musicianDuplicatesFetchDismissedPairs($db);
    $count = 0;
    foreach ($candidates['groups'] as $g) {
        $allDismissed = true;
        foreach ($g['pairs'] as [$a, $b]) {
            if (!isset($dismissedPairs[musicianDuplicatesPairKey($a, $b)])) { $allDismissed = false; break; }
        }
        if (!$allDismissed) { $count++; }
    }
    return $count;
}
