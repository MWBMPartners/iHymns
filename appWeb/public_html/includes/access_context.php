<?php

declare(strict_types=1);

/**
 * iHymns — Access viewer-context resolver (#1769 P2 Commit E)
 * ==========================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * Before deciding what a request may see, gather EVERYTHING we know about the
 * requester ONCE into a plain struct: their tier, their per-action caps, whether
 * they hold CCLI, which licences they carry, whether a live-service presence
 * token unlocks anything, and whether a `content:gated` API key bypasses gating
 * entirely. `access_resolver.php` then makes every field-level decision from
 * this struct alone — so the "who is asking?" resolution lives in ONE place and
 * the "what may they see?" logic in another (rule #28: never a second matrix).
 *
 * DETAILED / #1769 MODEL-2 + BYTE-IDENTICAL NO-OP
 * -----------------------------------------------
 * This is the "one resolver" half of Model 2 (facts × grants × ONE resolver ×
 * ONE pipeline). It REPLACES the inline tier/ccli/presence resolution that used
 * to live at the top of contentGatingApply()/contentGatingMediaAllowed(). The
 * fields and their resolution order are lifted VERBATIM from that code so the
 * pipeline is a proven byte-identical no-op:
 *
 *   - Throw semantics match the old delegates EXACTLY. bypass + tier resolution
 *     may THROW — they are NOT internally caught here, so the throw propagates to
 *     the delegate's try/catch which fail-opens (returns the song/serves the
 *     bytes), identical to today. hasCcli (contentGating_userHasCcli) and the
 *     presence block are internally caught (deny-on-error), as before. The
 *     licences read is a NEW read this path didn't do before, so it is caught
 *     internally → [] — a throw there must NOT change the apply outcome (else the
 *     song would fail-open differently than today).
 *   - caps are resolved through the shared registry-backed checkTierAccess()
 *     (rule #28B), never a local tier matrix, and WITHOUT the presence OR-grant —
 *     the OR is a per-decision refinement the resolver applies, so the raw tier
 *     verdict is what the struct carries.
 *   - The pure seam accessViewerAssemble() stamps the struct with NO I/O, so the
 *     equivalence test (test-gating-equivalence.php) can build viewers over a
 *     synthetic matrix and replay the frozen goldens DB-free.
 *
 * @link .claude/gating-p2-design.md  §"includes/access_context.php" — the design
 * @see  includes/access_resolver.php  the consumer (accessApplySong / accessMediaAllowed / accessResolve)
 * @see  includes/ccli_validator.php   resolveEffectiveTier / checkTierAccess / TIER_ACTION_CAP_MAP
 * @see  includes/content_gating.php   the delegates that build a viewer via accessViewerContext()
 * @see  tests/php/test-gating-equivalence.php  the byte-identical replay guard
 */

/* Library, never a page — block direct HTTP access (mirrors content_gating.php). */
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'db_mysql.php';        /* getDbMysqli() */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ccli_validator.php';  /* resolveEffectiveTier / checkTierAccess / TIER_ACTION_CAP_MAP */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'content_gating.php';  /* contentGatingEnabled / contentGating_userHasCcli */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'licences.php';        /* getUserEffectiveLicenceTypes() */

/**
 * The exact keys of a viewer-context struct, in order. Every reader indexes the
 * struct by these literals; the guard in test-gating-noop.php asserts assemble
 * emits precisely this set in this order, so a stray key or a rename is caught.
 *
 *   gatingEnabled  bool   — the in-band master switch (rule A). accessApplySong /
 *                           accessMediaAllowed no-op immediately when false.
 *   userId         ?int   — the authenticated user id (null = anonymous).
 *   platform       string — informational only; NO P2 decision reads it.
 *   tier           string — resolveEffectiveTier(); '' and anonymous → 'public'.
 *   caps           array<string,bool> — the six TIER_ACTION_CAP_MAP actions via
 *                           checkTierAccess(); NO presence OR (the resolver adds it).
 *   hasCcli        bool   — contentGating_userHasCcli() (deny-on-error).
 *   licences       string[] — getUserEffectiveLicenceTypes() + presence 'ccli'
 *                           (deny-on-error → []). Consumed only by accessResolve()
 *                           (unwired in P2); inert in the apply/media paths.
 *   presenceToken  ?string — the Service-Mode presence token as PASSED (never
 *                           read from $_COOKIE here — the caller owns that).
 *   presenceCcli   bool   — the token resolved to a live CCLI-licensed session.
 *   bypass         bool   — a vetted `content:gated` API key: read gated content
 *                           unstripped. Always false on the media path (which
 *                           passes apiKeyScopes=[] — today's byte gate has no
 *                           bypass branch).
 */
if (!defined('ACCESS_VIEWER_KEYS')) {
    define('ACCESS_VIEWER_KEYS', [
        'gatingEnabled', 'userId', 'platform', 'tier', 'caps',
        'hasCcli', 'licences', 'presenceToken', 'presenceCcli', 'bypass',
    ]);
}

/**
 * Build the viewer-context struct for a request. The ONE place "who is asking?"
 * is resolved. Statement order and throw semantics are lifted verbatim from the
 * pre-P2 contentGatingApply() top-matter (see the file doc-block).
 *
 * @param int|null    $userId        Authenticated user id, or null for anonymous.
 * @param string      $platform      Requesting platform tag (informational).
 * @param string|null $presenceToken Service-Mode presence token (#1335), or null.
 * @param array|null  $apiKeyScopes  Bypass-scope control:
 *                      null  = self-resolve the `content:gated` key from the
 *                              request (the payload path — verbatim CG:214–218);
 *                      []    = do NOT resolve; ZERO header reads / side-effects
 *                              (the media path — today's byte gate has no bypass);
 *                      list  = pre-verified scopes (bypass ⇔ 'content:gated' ∈ list).
 * @return array The viewer struct, keyed by ACCESS_VIEWER_KEYS.
 */
function accessViewerContext(?int $userId, string $platform = 'PWA', ?string $presenceToken = null, ?array $apiKeyScopes = null): array
{
    /* (1) bypass — a `content:gated` API key reads gated content unstripped.
       NOT internally caught: a throw here (getDbMysqli / apiKeyVerify) propagates
       to the delegate's try/catch which fail-opens — identical to CG:214–220. */
    $bypass = false;
    if ($apiKeyScopes === null) {
        /* Self-resolve from the request (payload path). */
        if (function_exists('apiKeyFromRequest') && function_exists('apiKeyVerify')) {
            $gatedKeyRaw = apiKeyFromRequest();
            if ($gatedKeyRaw !== null && strncmp($gatedKeyRaw, 'ihk_', 4) === 0
                && apiKeyVerify(getDbMysqli(), $gatedKeyRaw, 'content:gated') !== null) {
                $bypass = true;
            }
        }
    } elseif ($apiKeyScopes === []) {
        /* Media path — explicitly NO bypass resolution (no header reads). */
        $bypass = false;
    } else {
        /* Pre-verified scope list. */
        $bypass = in_array('content:gated', $apiKeyScopes, true);
    }

    /* The in-band master switch. function_exists guard keeps this safe even if
       content_gating.php were somehow not loaded (degrades to off = no-op). */
    $gatingEnabled = function_exists('contentGatingEnabled') && contentGatingEnabled();

    /* Bypass short-circuit → a NEUTRAL struct: caps FAIL-CLOSED (all false),
       no ccli, no presence, no licences. accessApplySong short-circuits on
       bypass before reading caps, so these all-false caps are defence-in-depth. */
    if ($bypass) {
        return accessViewerAssemble($gatingEnabled, $userId, $platform, 'public', false, [], $presenceToken, false, true);
    }

    /* (3) Effective tier: anonymous → 'public'; else highest of personal/org.
       NOT internally caught (a throw propagates to the delegate → fail-open),
       matching CG:222–224. */
    $tier = ($userId === null) ? 'public' : resolveEffectiveTier($userId);
    if ($tier === '') { $tier = 'public'; }

    /* (4) hasCcli — the resolver is itself deny-on-error (never throws). */
    $hasCcli = contentGating_userHasCcli($userId);

    /* (6) presenceCcli — verbatim CG:238–253, including the inner try/catch that
       denies the unlock (the safe direction) on a missing helper / un-migrated
       table / DB blip. */
    $presenceCcli = false;
    if ($presenceToken !== null && $presenceToken !== '') {
        try {
            require_once __DIR__ . DIRECTORY_SEPARATOR . 'service_mode.php';
            if (function_exists('serviceMode_presenceCcliNumber') && function_exists('serviceMode_channel')) {
                $presenceCcli = (serviceMode_presenceCcliNumber(
                    getDbMysqli(),
                    $presenceToken,
                    serviceMode_channel()
                ) !== null);
            }
        } catch (\Throwable $_e) {
            $presenceCcli = false;
        }
    }

    /* (5) licences — a NEW read this path didn't do before P2, so it MUST be
       caught internally → []: a throw here cannot be allowed to change the apply
       outcome (which would be a different fail-open than today). Presence 'ccli'
       is injected by assemble (the ONE injection point), so raw licences pass. */
    try {
        $licences = getUserEffectiveLicenceTypes($userId);
        if (!is_array($licences)) { $licences = []; }
    } catch (\Throwable $_e) {
        $licences = [];
    }

    /* (7) caps — computed inside assemble via checkTierAccess (the pure seam). */
    return accessViewerAssemble($gatingEnabled, $userId, $platform, $tier, $hasCcli, $licences, $presenceToken, $presenceCcli, false);
}

/**
 * The PURE, I/O-free seam that stamps a viewer struct: it resolves the six
 * per-action caps via checkTierAccess(), injects the presence 'ccli' licence,
 * and returns the struct keyed by ACCESS_VIEWER_KEYS. No DB reads of its own
 * beyond checkTierAccess() (which the equivalence test drives through its matrix
 * fallback DB-free) — so the golden-replay guard can build viewers directly.
 *
 * Presence-'ccli' injection lives HERE and only here (accessViewerContext passes
 * raw licences): ONE injection point avoids the double-append that would result
 * from injecting in both. Unconditional append mirrors content_access.php:420.
 *
 * @param bool        $gatingEnabled In-band master switch.
 * @param int|null    $userId
 * @param string      $platform
 * @param string      $tier
 * @param bool        $hasCcli
 * @param string[]    $licenceTypes  Raw effective licence types (pre presence-inject).
 * @param string|null $presenceToken
 * @param bool        $presenceCcli
 * @param bool        $bypass        When true, caps are FAIL-CLOSED (all false).
 * @return array Keyed by ACCESS_VIEWER_KEYS.
 */
function accessViewerAssemble(bool $gatingEnabled, ?int $userId, string $platform, string $tier, bool $hasCcli, array $licenceTypes, ?string $presenceToken, bool $presenceCcli, bool $bypass): array
{
    /* The six gateable actions — from the ONE map (rule #28B). */
    $caps = [];
    foreach (array_keys(TIER_ACTION_CAP_MAP) as $action) {
        if ($bypass) {
            /* Neutral / FAIL-CLOSED — accessApplySong never consults these
               (it short-circuits on bypass), but a false is the safe stamp. */
            $caps[$action] = false;
        } else {
            $r = checkTierAccess($tier, $action, $hasCcli);
            $caps[$action] = !empty($r['allowed']);
        }
    }

    /* Presence 'ccli' — the ONE injection point (unconditional append, mirroring
       the entitlement evaluator in content_access.php). */
    if ($presenceCcli) {
        $licenceTypes[] = 'ccli';
    }

    return [
        'gatingEnabled' => $gatingEnabled,
        'userId'        => $userId,
        'platform'      => $platform,
        'tier'          => $tier,
        'caps'          => $caps,
        'hasCcli'       => $hasCcli,
        'licences'      => $licenceTypes,
        'presenceToken' => $presenceToken,
        'presenceCcli'  => $presenceCcli,
        'bypass'        => $bypass,
    ];
}
