<?php

declare(strict_types=1);

/**
 * iHymns — Composable enforcement for admin-configurable feature gating
 * (#1481 P2).
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * WHAT THIS IS:
 * P1 (includes/access_tier_validation.php's tierCapsEffective()) let a
 * Global Admin DEFINE a new capability from the UI with zero code. P2 makes
 * that capability USABLE without code too, by letting an admin CRUD rows in
 * tblGatingRules that map "a capability the tier lacks" to a code-registered
 * BEHAVIOUR KIND — a small, closed catalog of safe, deny-only payload
 * transforms (never a general scripting surface). This file is the catalog
 * + the loop that walks it; manage/feature-gating.php is the CRUD UI;
 * includes/content_gating.php calls gatingRulesApply() once, at the very
 * end of contentGatingApply(), after every built-in trim has already run.
 *
 * THE SAME THREE LOCKED RULES AS content_gating.php (do not relax):
 *
 *   (A) DOUBLE MASTER SWITCH. gatingRulesApply() is a no-op unless BOTH
 *       content_gating_enabled='1' (checked by the caller, contentGatingApply,
 *       BEFORE it ever reaches the rules loop) AND
 *       feature_gating_rules_enabled='1' (checked here). Two flags, nested —
 *       flipping content_gating_enabled alone does NOT turn rules on.
 *
 *   (B) CAPS COME FROM THE REGISTRY. A rule's CapKey is resolved through
 *       capValueForTierAction() (ccli_validator.php) — the SAME
 *       registry-backed resolver the built-in trims use — never a bespoke
 *       lookup. It returns null for anything it can't resolve, and this
 *       loop treats null as "skip" (fail-open), never "deny".
 *
 *   (C) STRICT-SAFE. Every optional read is wrapped; any failure anywhere
 *       in this file degrades to "change nothing" for that rule (or for the
 *       whole loop), never to a thrown exception reaching the caller.
 *
 * v1 RESTRICTION (rule #26/#28 precedent — never re-derive a decision the
 * registry already made): a rule may target a DB-DEFINED capability ONLY —
 * never one of the 7 TIER_CAPS built-ins (CanViewLyrics / CanViewCopyrighted
 * / CanPlayAudio / CanDownloadMidi / CanDownloadPdf / CanOfflineSave /
 * RequiresCcli). Those seven are wrapped in the Service-Mode presence
 * OR-grant and the CCLI-verification gate (content_gating.php:206-221) —
 * a rule silently re-stripping 'CanPlayAudio' would rip audio away from a
 * congregant mid-service even though the presence grant just said yes. This
 * is enforced BOTH at save time (manage/feature-gating.php's rule-create
 * handler rejects a built-in CapKey outright) AND defensively here, so a
 * row that somehow reached the table (a future bulk-import source, a manual
 * INSERT) can never fire.
 *
 * NO-ESCALATION: every enforce callable is a PURE function that only
 * `unset()`s a key, `array_filter()`s an array shorter, or flips a boolean
 * flag to `false` — it can never ADD a key or grow an array. gatingRuleAssertNoEscalation()
 * re-checks this on every rule's output regardless (defence in depth against
 * a future kind that gets it wrong): output keys must be a subset of input
 * keys, and `media` may only shrink. A violation discards that rule's output
 * (the payload reverts to its pre-rule state) and is logged for an operator —
 * it is never allowed to reach the client.
 *
 * COVERAGE: exactly the same three surfaces contentGatingApply() itself
 * covers — the song_detail / song_data / random API payloads. The HTML song
 * page, songbook_export, and the bulk_audio offline manifest are NOT wired
 * to this loop (see .claude/admin-configurable-gating-strategy.md §5).
 *
 * Direct access blocked so this can't be loaded as an endpoint.
 *
 * @see includes/content_gating.php               contentGatingApply() — the one caller
 * @see includes/access_tier_validation.php        TIER_CAPS / tierCapsEffective()
 * @see includes/ccli_validator.php                capValueForTierAction()
 * @see manage/feature-gating.php                  the rules CRUD UI
 * @see appWeb/.sql/migrate-add-gating-registry.php tblGatingRules (schema, shipped in P1)
 * @see .claude/admin-configurable-gating-strategy.md the approved design
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'db_mysql.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'maintenance.php';           /* getAppSetting() */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'access_tier_validation.php'; /* TIER_CAPS */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ccli_validator.php';         /* capValueForTierAction() */

/* ========================================================================
 * Code-owned allow-lists (rule #7 / strategy §7). These are the ONLY
 * vocabularies an admin-defined rule may reference — never a free-form
 * path, never a generic "delete this JSON pointer" surface.
 * ======================================================================== */

/**
 * Payload keys (and two component-level sub-payloads) a `strip_payload_keys`
 * rule may remove from a song_detail/song_data/random payload.
 *
 * Keyed by the exact identifier a rule's `Params.keys[]` entry must match;
 * value is the admin-facing label. A dotted entry ("components.chords")
 * means "walk every item in the top-level `components` array and unset
 * just that one nested key" — NOT a generic dot-path engine (there are only
 * ever these two dotted forms; gatingRuleEnforceStripPayloadKeys() special-
 * cases them by name, it does not interpret arbitrary paths).
 *
 * MUST NEVER include (see GATING_NEVER_STRIP_KEYS below for the guarded
 * negative list, and tests/php/test-gating-rules.php for the CI proof that
 * the two lists never intersect): id/number/title/songbook*, contentRestricted/
 * restrictionReason/offlineAllowed (gating METADATA, not content), or any
 * copyright/CCLI/credit/attribution field — stripping attribution would be
 * the INVERSE compliance failure (a licence requires the notice to STAY).
 *
 * @link https://www.php.net/manual/en/function.unset.php
 */
const GATING_STRIPPABLE_KEYS = [
    'components'         => 'Lyric body — every component (verses, chorus, bridge, …)',
    'components.chords'  => 'Chord charts only (keeps the lyric text, removes just the chords)',
    'components.notes'   => 'Presenter notes only (within each lyric component)',
    'translations'       => 'Whole-song and per-line translations/romanizations',
    'annotations'        => 'Line annotations (Genius-style)',
    'vocalParts'         => 'Vocal-parts breakdown',
];

/**
 * Payload keys a `strip_payload_keys` rule must NEVER be able to name —
 * the "must-never-gate" set (strategy §7). This is a defence-in-depth
 * constant: GATING_STRIPPABLE_KEYS above is the actual allow-list enforced
 * by gatingRuleValidateStripPayloadKeys(), so nothing here can ever be
 * *reached* through the normal validator path. This list exists so a CI
 * test (tests/php/test-gating-rules.php) can assert the two sets stay
 * disjoint FOREVER, even as GATING_STRIPPABLE_KEYS grows — a future PR
 * that accidentally adds 'writers' or 'copyright' to the strippable set
 * fails CI instead of shipping.
 */
const GATING_NEVER_STRIP_KEYS = [
    'id', 'number', 'title', 'songbook', 'songbookName', 'publicId',
    'contentRestricted', 'restrictionReason', 'offlineAllowed',
    'copyright', 'ccli', 'iswc',
    'writers', 'composers', 'arrangers', 'adaptors', 'translators', 'artists',
];

/**
 * Media `kind` values a `drop_media_kinds` rule may filter out of the
 * payload's `media` array. Keyed by the exact tblSongMedia.Kind string;
 * value is the admin-facing label. Mirrors the switch in
 * contentGatingApply()'s built-in media gate (content_gating.php).
 */
const GATING_DROPPABLE_MEDIA_KINDS = [
    'audio'       => 'Audio',
    'midi'        => 'MIDI',
    'sheet-music' => 'Sheet music (PDF)',
    'musicxml'    => 'MusicXML notation',
];

/* ========================================================================
 * The behaviour-kind catalog. Each entry:
 *   [label, description, effectString, validatorCallable, enforceCallable]
 *
 * effectString is a sprintf() template with ONE %s placeholder — the admin
 * UI fills it with a comma-joined list of the rule's params (keys or kinds)
 * to render a plain-English "what this rule actually does" line.
 *
 * validatorCallable(array $params): ?string
 *   Runs at admin SAVE time. Returns an error message, or null when valid.
 *
 * enforceCallable(array $song, array $params): array
 *   Runs in the ENFORCEMENT hot path. Must be a PURE transform — only
 *   unset()/array_filter()/flip-a-flag-false — see the no-escalation
 *   invariant in the file docblock above.
 * ======================================================================== */
const GATING_BEHAVIOR_KINDS = [
    'strip_payload_keys' => [
        'Strip payload keys',
        'Remove specific fields (lyric body, translations, annotations, vocal parts — '
            . 'or just the chords/notes inside each lyric component) from the payload.',
        'Removes: %s',
        'gatingRuleValidateStripPayloadKeys',
        'gatingRuleEnforceStripPayloadKeys',
    ],
    'drop_media_kinds' => [
        'Drop media kinds',
        'Remove media rows of the given kind(s) — audio, MIDI, sheet-music, MusicXML — '
            . 'from the payload\'s media list.',
        'Drops media kinds: %s',
        'gatingRuleValidateDropMediaKinds',
        'gatingRuleEnforceDropMediaKinds',
    ],
];

/* ========================================================================
 * Validators — admin-SAVE-time only. Pure, no I/O.
 * ======================================================================== */

/**
 * Validate `strip_payload_keys` params: `{"keys": ["components", ...]}`.
 *
 * @param array $params Decoded rule params.
 * @return string|null Error message, or null if valid.
 */
function gatingRuleValidateStripPayloadKeys(array $params): ?string
{
    $keys = $params['keys'] ?? null;
    if (!is_array($keys) || empty($keys)) {
        return 'strip_payload_keys requires a non-empty "keys" list.';
    }
    if (count($keys) > count(GATING_STRIPPABLE_KEYS)) {
        return 'Too many keys supplied.';
    }
    $seen = [];
    foreach ($keys as $k) {
        if (!is_string($k)) {
            return 'Every key must be a string.';
        }
        if (!array_key_exists($k, GATING_STRIPPABLE_KEYS)) {
            return "'{$k}' is not an allowed strippable key.";
        }
        if (isset($seen[$k])) {
            return "Duplicate key '{$k}'.";
        }
        $seen[$k] = true;
    }
    return null;
}

/**
 * Validate `drop_media_kinds` params: `{"kinds": ["audio", ...]}`.
 *
 * @param array $params Decoded rule params.
 * @return string|null Error message, or null if valid.
 */
function gatingRuleValidateDropMediaKinds(array $params): ?string
{
    $kinds = $params['kinds'] ?? null;
    if (!is_array($kinds) || empty($kinds)) {
        return 'drop_media_kinds requires a non-empty "kinds" list.';
    }
    if (count($kinds) > count(GATING_DROPPABLE_MEDIA_KINDS)) {
        return 'Too many kinds supplied.';
    }
    $seen = [];
    foreach ($kinds as $k) {
        if (!is_string($k)) {
            return 'Every kind must be a string.';
        }
        if (!array_key_exists($k, GATING_DROPPABLE_MEDIA_KINDS)) {
            return "'{$k}' is not an allowed media kind.";
        }
        if (isset($seen[$k])) {
            return "Duplicate kind '{$k}'.";
        }
        $seen[$k] = true;
    }
    return null;
}

/* ========================================================================
 * Enforce callables — the ENFORCEMENT HOT PATH. Pure transforms only.
 * ======================================================================== */

/**
 * Strip the requested payload keys (and/or component-level chords/notes
 * sub-payloads) from a song array. Defensively re-checks every key against
 * GATING_STRIPPABLE_KEYS (never trusts that validation already ran) so this
 * function is safe to call directly in a test even with a hand-built params
 * array.
 *
 * @param array $song   The song_detail/song_data/random payload so far.
 * @param array $params `{"keys": [...]}`.
 * @return array The same shape, with only the requested keys removed.
 */
function gatingRuleEnforceStripPayloadKeys(array $song, array $params): array
{
    $keys = is_array($params['keys'] ?? null) ? $params['keys'] : [];
    foreach ($keys as $k) {
        if (!is_string($k) || !array_key_exists($k, GATING_STRIPPABLE_KEYS)) {
            continue;   /* defensive re-check — never trust unvalidated input */
        }
        if (!str_contains($k, '.')) {
            if (array_key_exists($k, $song)) {
                unset($song[$k]);
            }
            continue;
        }
        /* Only two dotted forms exist today (components.chords / .notes) —
           both operate on the top-level `components` array. */
        [$parent, $child] = explode('.', $k, 2);
        if ($parent === 'components' && is_array($song['components'] ?? null)) {
            $song['components'] = array_map(
                static function ($component) use ($child) {
                    if (is_array($component) && array_key_exists($child, $component)) {
                        unset($component[$child]);
                    }
                    return $component;
                },
                $song['components']
            );
        }
    }
    return $song;
}

/**
 * Drop media rows of the requested kind(s) from `$song['media']`. Mirrors
 * the shape of the built-in media gate in contentGatingApply() — filters
 * the array, never nulls individual fields, so the affordance disappears
 * entirely rather than leaving a dead link.
 *
 * @param array $song   The song_detail/song_data/random payload so far.
 * @param array $params `{"kinds": [...]}`.
 * @return array The same shape, with matching media rows removed.
 */
function gatingRuleEnforceDropMediaKinds(array $song, array $params): array
{
    $kinds = is_array($params['kinds'] ?? null) ? $params['kinds'] : [];
    $kinds = array_values(array_filter(
        $kinds,
        static fn($k): bool => is_string($k) && array_key_exists($k, GATING_DROPPABLE_MEDIA_KINDS)
    ));
    if (empty($kinds) || empty($song['media']) || !is_array($song['media'])) {
        return $song;
    }
    $kindSet = array_flip($kinds);
    $song['media'] = array_values(array_filter(
        $song['media'],
        static function ($m) use ($kindSet): bool {
            $kind = is_array($m) ? (string)($m['kind'] ?? '') : '';
            return !isset($kindSet[$kind]);
        }
    ));
    return $song;
}

/* ========================================================================
 * Dispatch + the no-escalation guard.
 * ======================================================================== */

/**
 * Run a behaviour kind's registered validator against admin-submitted
 * params. Used by manage/feature-gating.php at rule-create/update time.
 *
 * @param string $kind   A GATING_BEHAVIOR_KINDS key.
 * @param array  $params Decoded rule params.
 * @return string|null Error message, or null if valid / the kind is unknown
 *                      is itself reported as an error (never silently OK).
 */
function gatingRuleValidateParams(string $kind, array $params): ?string
{
    $entry = GATING_BEHAVIOR_KINDS[$kind] ?? null;
    if ($entry === null) {
        return "Unknown behaviour kind '{$kind}'.";
    }
    $validator = $entry[3];
    if (!is_callable($validator)) {
        return "Behaviour kind '{$kind}' has no validator wired up.";
    }
    return $validator($params);
}

/**
 * Run a behaviour kind's registered enforce callable. Throws for an unknown
 * kind or a missing callable so the caller's per-rule try/catch (rule C)
 * treats it exactly like any other enforcement failure — skip this rule,
 * log it, keep the pre-rule payload.
 *
 * @param string $kind   A GATING_BEHAVIOR_KINDS key.
 * @param array  $song   The payload so far.
 * @param array  $params Decoded rule params.
 * @return array The transformed payload.
 * @throws \RuntimeException on an unknown/miswired kind.
 */
function gatingRuleEnforce(string $kind, array $song, array $params): array
{
    $entry = GATING_BEHAVIOR_KINDS[$kind] ?? null;
    if ($entry === null) {
        throw new \RuntimeException("Unknown behaviour kind '{$kind}'.");
    }
    $enforce = $entry[4];
    if (!is_callable($enforce)) {
        throw new \RuntimeException("Behaviour kind '{$kind}' has no enforce callable wired up.");
    }
    return $enforce($song, $params);
}

/**
 * Is a capability key one of the 7 TIER_CAPS built-ins? v1 rules may never
 * target these (file docblock — the Service-Mode presence grant + the CCLI
 * gate wrap them). Exact (not case-insensitive) match: a rule's CapKey is
 * only ever populated from an existing tblGatingCapabilities row, and P1's
 * capability-create validator already rejects a case-insensitive collision
 * with a TIER_CAPS key at THAT layer — this is the defence-in-depth re-check
 * at the rule layer, so it only needs to catch an exact string match.
 *
 * @param string $capKey Candidate capability key.
 * @return bool true when $capKey is one of the 7 built-ins.
 */
function gatingRuleCapKeyIsBuiltIn(string $capKey): bool
{
    return array_key_exists($capKey, TIER_CAPS);
}

/**
 * The no-escalation invariant (strategy §6): a rule's enforce callable may
 * only ever REMOVE — never add a top-level key, never grow the `media`
 * array. Checked after every enforce call regardless of which kind ran, as
 * defence-in-depth against a future kind that gets its pure-transform
 * contract wrong.
 *
 * @param array $before The payload immediately before this rule ran.
 * @param array $after  The payload the enforce callable returned.
 * @return bool true when $after is a safe (non-escalating) narrowing of $before.
 */
function gatingRuleAssertNoEscalation(array $before, array $after): bool
{
    $extraKeys = array_diff(array_keys($after), array_keys($before));
    if (!empty($extraKeys)) {
        return false;
    }
    $beforeMediaCount = is_array($before['media'] ?? null) ? count($before['media']) : 0;
    $afterMediaCount  = is_array($after['media'] ?? null) ? count($after['media']) : 0;
    if ($afterMediaCount > $beforeMediaCount) {
        return false;
    }
    return true;
}

/* ========================================================================
 * Registry I/O — existence-gated, request-cached, fail-open on any error.
 * ======================================================================== */

/**
 * Has tblGatingRules landed yet? Same shape as gatingCapabilitiesTableExists()
 * (access_tier_validation.php) — request-cached, INFORMATION_SCHEMA, any
 * failure degrades to false (not-migrated).
 *
 * @param \mysqli $db Live connection.
 * @return bool true once migrate-add-gating-registry.php has run.
 */
function gatingRulesTableExists(\mysqli $db): bool
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    try {
        $stmt = $db->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'tblGatingRules' LIMIT 1"
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
 * Fetch every Enabled=1 tblGatingRules row, decoded + request-cached.
 *
 * ANY failure (no credentials, un-migrated table, a transient DB error)
 * degrades to an empty list — the caller then applies zero rules, which is
 * the fail-open direction (rule C).
 *
 * @param ?\mysqli $db Optional connection (test seam); null = getDbMysqli().
 * @return array<int,array{CapKey:string,BehaviorKind:string,Params:array}>
 */
function gatingRulesFetchEnabled(?\mysqli $db = null): array
{
    static $cached = null;
    $useCache = ($db === null);
    if ($useCache && $cached !== null) {
        return $cached;
    }

    $out = [];
    try {
        $conn = $db;
        if ($conn === null && function_exists('getDbMysqli')) {
            $conn = getDbMysqli();
        }
        if ($conn instanceof \mysqli && gatingRulesTableExists($conn)) {
            $stmt = $conn->prepare(
                'SELECT CapKey, BehaviorKind, ParamsJson
                   FROM tblGatingRules
                  WHERE Enabled = 1
                  ORDER BY SortOrder ASC, Id ASC'
            );
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            foreach ($rows as $row) {
                $paramsRaw = $row['ParamsJson'] ?? null;
                $decoded   = null;
                if ($paramsRaw !== null && $paramsRaw !== '') {
                    $decoded = is_array($paramsRaw) ? $paramsRaw : json_decode((string)$paramsRaw, true);
                }
                $out[] = [
                    'CapKey'       => (string)($row['CapKey'] ?? ''),
                    'BehaviorKind' => (string)($row['BehaviorKind'] ?? ''),
                    'Params'       => is_array($decoded) ? $decoded : [],
                ];
            }
        }
    } catch (\Throwable $_e) {
        $out = [];   /* fail-open: zero rules applied */
    }

    if ($useCache) {
        $cached = $out;
    }
    return $out;
}

/**
 * Is the SECOND dormancy flag on? Nested under content_gating_enabled — the
 * caller (contentGatingApply) already checked the FIRST flag before this
 * module is even reached, so this function only needs to check its own.
 *
 * @return bool true only when feature_gating_rules_enabled === '1'.
 */
function gatingRulesEnabled(): bool
{
    return function_exists('getAppSetting')
        && getAppSetting('feature_gating_rules_enabled', '0') === '1';
}

/**
 * THE LOOP. Called once by contentGatingApply(), after every built-in trim,
 * immediately before its final `return $song`. No-op unless
 * feature_gating_rules_enabled='1' AND at least one Enabled=1 rule exists.
 *
 * For each rule (in SortOrder order):
 *   1. Skip a rule on a built-in TIER_CAPS key (v1 restriction, defensive —
 *      manage/feature-gating.php already refuses to CREATE one).
 *   2. Skip an unrecognised BehaviorKind.
 *   3. Resolve $v = capValueForTierAction($tier, CapKey).
 *        $v === null → SKIP (fail-open — an un-migrated / unresolvable cap
 *                      changes nothing, exactly like the built-in trims).
 *        $v === true → SKIP (the tier already has this capability — nothing
 *                      to enforce; rules are DENY-only, never an override
 *                      that takes something away from a tier that has it...
 *                      wait, that's backwards — see note below).
 *        $v === false → APPLY the kind's enforce callable.
 *   4. Assert the no-escalation invariant on the callable's output; a
 *      violation discards it (keeps the pre-rule payload) and is logged.
 *   5. Any Throwable anywhere in the per-rule body → skip this rule, log,
 *      keep the pre-rule payload — never let one bad rule blank a response.
 *
 * NOTE on step 3: $v is the tier's value for the cap the rule is guarding.
 * `false` means "this tier does NOT have the capability" → the rule's
 * restriction applies (strip / drop). `true` means "this tier DOES have the
 * capability" → nothing to restrict, skip. This mirrors the built-in gate's
 * `$denyLyricBody = (!$canViewLyrics) || ...` shape exactly (deny when the
 * tier LACKS the capability).
 *
 * @param array  $song The song_detail/song_data/random payload, after every
 *                      built-in trim has already run.
 * @param string $tier The requester's resolved effective tier.
 * @return array The same shape, with any applicable admin-defined rules applied.
 */
function gatingRulesApply(array $song, string $tier): array
{
    if (!gatingRulesEnabled()) {
        return $song;   /* (A) second flag off — untouched */
    }

    try {
        $rules = gatingRulesFetchEnabled();
    } catch (\Throwable $_e) {
        return $song;   /* (C) never let a fetch failure escape */
    }
    if (empty($rules)) {
        return $song;
    }

    foreach ($rules as $rule) {
        $capKey = (string)($rule['CapKey'] ?? '');
        $kind   = (string)($rule['BehaviorKind'] ?? '');
        $params = is_array($rule['Params'] ?? null) ? $rule['Params'] : [];

        if ($capKey === '' || $kind === '') {
            continue;
        }
        if (gatingRuleCapKeyIsBuiltIn($capKey)) {
            /* Defensive — the save-time validator already refuses this. */
            error_log("[gating_rules] rule targets built-in cap '{$capKey}' — skipped (v1 restriction).");
            continue;
        }
        if (!array_key_exists($kind, GATING_BEHAVIOR_KINDS)) {
            error_log("[gating_rules] rule for '{$capKey}' has unknown BehaviorKind '{$kind}' — skipped.");
            continue;
        }

        try {
            $v = capValueForTierAction($tier, $capKey);
        } catch (\Throwable $_e) {
            $v = null;
        }
        if ($v === null) {
            continue;   /* (B)/(C) fail-open — unresolvable cap, change nothing */
        }
        if ($v === true) {
            continue;   /* tier already has this capability — nothing to enforce */
        }

        try {
            $before = $song;
            $after  = gatingRuleEnforce($kind, $song, $params);
            if (!gatingRuleAssertNoEscalation($before, $after)) {
                error_log("[gating_rules] rule '{$capKey}'/'{$kind}' violated the no-escalation invariant — discarded.");
                continue;   /* keep $song exactly as it was before this rule */
            }
            $song = $after;
        } catch (\Throwable $e) {
            error_log("[gating_rules] rule '{$capKey}'/'{$kind}' enforce failed: " . $e->getMessage());
            /* keep $song exactly as it was before this rule */
        }
    }

    return $song;
}
