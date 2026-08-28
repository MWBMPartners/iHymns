<?php

declare(strict_types=1);

/**
 * iHymns — Access-tier shared validators (#719 PR 2b)
 *
 * Single source of truth for the `tblAccessTiers` capability set and
 * the tier-name grammar. Used by:
 *
 *   - /manage/tiers.php       (web admin: create + update handlers,
 *                              capability table headers)
 *   - /api.php                (admin_tier_* CRUD endpoints + the public
 *                              `access_tiers` emit — the native-app
 *                              contract)
 *
 * TO ADD A GATED FEATURE: add ONE line to TIER_CAPS with storage 'json'
 * — no schema change. The admin UI, the API CRUD, the API emit and
 * content gating all derive the cap list from TIER_CAPS, so a json-backed
 * cap is picked up everywhere with zero per-surface edits. The 7 original
 * caps keep storage 'column' because the native-app API contract (the
 * camelCase keys in /api.php's `access_tiers` emit) reads them straight
 * off their own TINYINT columns — never re-home an existing column cap
 * into JSON (it would break that contract). New caps go to 'json', which
 * lands inside the single tblAccessTiers.Capabilities JSON column added
 * by migrate-add-tier-capabilities-json.php (rule #20: a growable
 * vocabulary is JSON, never an ENUM or one-column-per-feature).
 *
 * Tuple shape (4 elements, plus an optional 5th):
 *   [short_label, full_description, storage, default, enforce?]
 *   - short_label  : table column header on /manage/tiers.php
 *   - full_description : tooltip / checkbox hint
 *   - storage      : 'column' (own TINYINT column) | 'json'
 *                    (key inside tblAccessTiers.Capabilities)
 *   - default      : 0 | 1 — value assumed when the json key is absent
 *                    or the Capabilities column hasn't been migrated yet
 *   - enforce      : OPTIONAL descriptor (#1769 P2) telling the content
 *                    pipeline WHAT this cap gates — an assoc array with any
 *                    of: 'payload' => [payload keys to strip],
 *                    'media' => [media kinds to withhold],
 *                    'actions' => [action names it governs],
 *                    'requiresCoverage' => a licence coverage kind
 *                    (licence_registry LICENCE_COVERAGE_KINDS). ADDITIVE:
 *                    absent on the frozen 7 built-in caps and on every
 *                    tuple today; a DB-defined cap gains it only when its
 *                    tblGatingCapabilities.EnforceJson decodes to a
 *                    shape-valid descriptor (tierCapEnforceShapeValid()).
 *                    Read via tierCapEnforce(); ZERO production callers in
 *                    P2 (inert until an emitter adopts it in P3+).
 *
 * BACK-COMPAT: tiers.php destructures `[$lbl, $hint]` (2 elements) from
 * each tuple. PHP list-destructuring binds only the first two and ignores
 * the rest — so widening to a 4- or 5-tuple is a no-op for those call
 * sites (verified). The helpers below read storage / default / enforce /
 * per-tier values without touching that pattern.
 *
 * Direct access is blocked so this file can't be loaded as an
 * arbitrary endpoint via an open Apache config.
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

/**
 * Tier capabilities on tblAccessTiers, mapped to their UI labels +
 * storage + default.
 *
 * Keyed by exact capability key. For storage 'column' the key is the
 * exact tblAccessTiers column name; for storage 'json' it's the key
 * inside the Capabilities JSON column. Both forms drive the same
 * `cap_<Key>` POST field on the web admin and `caps.<key>` JSON path
 * on the API.
 *
 * Tuple shape: [short_label, full_description, storage, default].
 *   storage: 'column' = own TINYINT column (the 7 originals — the
 *            native-app API contract reads these);
 *            'json'   = a named key in tblAccessTiers.Capabilities
 *            (every NEW gated cap — no schema change, rule #20).
 *   default: 0|1 assumed when a json key is absent or the Capabilities
 *            column hasn't been migrated yet.
 */
if (!defined('IHYMNS_TIER_CAPS_DEFINED')) {
    define('IHYMNS_TIER_CAPS_DEFINED', true);
    define('TIER_CAPS', [
        /* The 7 originals — storage 'column' (their own TINYINT). Never
           re-home one of these into JSON: the camelCase keys they emit
           are the native-app API contract. */
        'CanViewLyrics'      => ['Lyrics',       'View song lyrics',                         'column', 1],
        'CanViewCopyrighted' => ['Copyrighted',  'View copyrighted songs',                   'column', 0],
        'CanPlayAudio'       => ['Audio',        'Play MIDI / audio in-app',                 'column', 0],
        'CanDownloadMidi'    => ['MIDI',         'Download MIDI files',                       'column', 0],
        'CanDownloadPdf'     => ['PDF',          'Download sheet-music PDFs',                 'column', 0],
        'CanOfflineSave'     => ['Offline',      'Save songs for offline use',               'column', 0],
        'RequiresCcli'       => ['Needs CCLI',   'Tier requires a valid CCLI licence number', 'column', 0],
        /* NEW code-registered caps go BELOW, storage 'json' — one line, no
           ALTER. Example (commented until needed):
           'CanRequestSongs' => ['Requests', 'Submit song requests', 'json', 0]. */
        'CanPlayVideo'       => ['Video',        'Play video media',                          'json', 0],   /* #1968 P4 */
    ]);
}

/**
 * Grammar every admin-defined capability CapKey must satisfy (#1481 P1).
 *
 * PascalCase, starts 'Can' + an uppercase letter, 3–33 chars total
 * ('Can' + 1 + 1..29). Shared by the tblGatingCapabilities merge (below) and
 * the /manage/feature-gating.php create/update validators, so the grammar
 * lives in exactly one place.
 */
if (!defined('GATING_CAP_KEY_PATTERN')) {
    define('GATING_CAP_KEY_PATTERN', '/^Can[A-Z][A-Za-z0-9]{1,29}$/');
}

/**
 * Does $capKey collide, case-insensitively, with a code-registered TIER_CAPS
 * key (#1481 P1)?
 *
 * A single case-INSENSITIVE compare already covers "incl. camelCase form":
 * strcasecmp() ignores case at every position, so 'CanViewLyrics',
 * 'canViewLyrics', and 'CANVIEWLYRICS' all compare equal to each other — a
 * separate lcfirst()-based comparison would be redundant (verified: PHP's
 * strcasecmp doesn't special-case the first character).
 *
 * Shared by tierCapsMergeUnion() (below — silently excludes a colliding DB
 * row from the union, code wins) and /manage/feature-gating.php's create
 * validator (rejects the submission with a themed error, never silently
 * drops — the two callers want different FAILURE handling but the same
 * collision TEST, so the test lives in exactly one place per the repo's
 * modularity rule).
 *
 * @param string $capKey Candidate capability key.
 * @return bool true if it collides with an existing code key.
 */
if (!function_exists('tierCapKeyCollidesWithCode')) {
    function tierCapKeyCollidesWithCode(string $capKey): bool
    {
        foreach (array_keys(TIER_CAPS) as $codeKey) {
            if (strcasecmp($codeKey, $capKey) === 0) {
                return true;
            }
        }
        return false;
    }
}

/**
 * PURE merge of TIER_CAPS with a set of already-fetched, already-Enabled=1
 * tblGatingCapabilities rows (#1481 P1). No I/O whatsoever — this is the
 * DB-free test seam for tierCapsEffective()'s union/collision logic (mirrors
 * the secretCryptoInjectKeyset() injection-hook pattern used by #1466's
 * DB-free CI tests: the I/O shell is thin, the interesting logic is pure and
 * directly testable without a live \mysqli).
 *
 * A DB row is IGNORED (never enters the union) when:
 *   - its CapKey is empty or fails GATING_CAP_KEY_PATTERN (never trust a
 *     malformed key out of the database into a registry other code indexes
 *     by string key), or
 *   - its CapKey collides, case-insensitively, with a code-registered
 *     TIER_CAPS key OR that key's camelCase (lcfirst) emit form — e.g. a DB
 *     row named "canViewLyrics" or "CANVIEWLYRICS" would otherwise shadow
 *     the built-in CanViewLyrics. Code always wins; the collision is
 *     reported back to the caller (who logs it) rather than silently eaten.
 *     (In practice /manage/feature-gating.php's create validator already
 *     REJECTS a colliding CapKey up front — reusing tierCapKeyCollidesWithCode()
 *     — so this branch is defence-in-depth against a row that reached the
 *     table some other way, e.g. a future bulk-import source.)
 *
 * @param array $dbRows Rows shaped like the tblGatingCapabilities SELECT
 *                       (assoc keys: CapKey, Label, Description,
 *                       DefaultValue, EmitInApi), pre-filtered to Enabled=1.
 * @return array{union: array<string,array>, emit: array<string,bool>, collisions: string[]}
 */
if (!function_exists('tierCapsMergeUnion')) {
    function tierCapsMergeUnion(array $dbRows): array
    {
        $union      = TIER_CAPS;
        $emit       = [];
        $collisions = [];

        foreach ($dbRows as $row) {
            $capKey = trim((string)($row['CapKey'] ?? ''));
            if ($capKey === '' || !preg_match(GATING_CAP_KEY_PATTERN, $capKey)) {
                continue;   /* malformed — never let it into the union */
            }

            if (tierCapKeyCollidesWithCode($capKey)) {
                $collisions[] = $capKey;
                continue;   /* code wins — caller logs this */
            }

            /* Same 4-tuple shape as every TIER_CAPS entry — storage is
               ALWAYS 'json' for a DB-defined cap (rule #20/#28: a growable
               vocabulary never gets its own tblAccessTiers column). */
            $tuple = [
                (string)($row['Label'] ?? $capKey),
                (string)($row['Description'] ?? ''),
                'json',
                ((int)($row['DefaultValue'] ?? 0)) === 1 ? 1 : 0,
            ];
            /* Optional 5th `enforce` element (#1769 P2) — appended ONLY when the
               row carries an EnforceJson that decodes to a shape-valid
               descriptor. A NULL/empty/malformed/shape-invalid EnforceJson
               leaves the tuple a plain 4-tuple, byte-identical to pre-#1769, so
               a DB row without enforce is indistinguishable from before this
               change (the frozen 7 code caps are not DB rows and never reach
               here). ADDITIVE: nothing writes EnforceJson in P2, so this branch
               is inert on every live install today. */
            if (array_key_exists('EnforceJson', $row)
                && $row['EnforceJson'] !== null && $row['EnforceJson'] !== '') {
                $decodedEnforce = json_decode((string)$row['EnforceJson'], true);
                if (is_array($decodedEnforce) && tierCapEnforceShapeValid($decodedEnforce)) {
                    $tuple[4] = $decodedEnforce;
                }
            }
            $union[$capKey] = $tuple;
            $emit[$capKey] = ((int)($row['EmitInApi'] ?? 1)) === 1;
        }

        return ['union' => $union, 'emit' => $emit, 'collisions' => $collisions];
    }
}

/**
 * Has tblGatingCapabilities landed yet? Same existence-gate shape as
 * tierCapsColumnExists() — request-cached, INFORMATION_SCHEMA with a bound
 * param, any failure degrades to false (not-migrated).
 *
 * @param \mysqli $db Live connection.
 * @return bool true once migrate-add-gating-registry.php has run.
 */
if (!function_exists('gatingCapabilitiesTableExists')) {
    function gatingCapabilitiesTableExists(\mysqli $db): bool
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        try {
            $stmt = $db->prepare(
                "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = 'tblGatingCapabilities' LIMIT 1"
            );
            $stmt->execute();
            $cached = $stmt->get_result()->fetch_row() !== null;
            $stmt->close();
        } catch (\Throwable $_e) {
            $cached = false;
        }
        return $cached;
    }
}

/**
 * Has the EnforceJson column landed on tblGatingCapabilities yet (#1769 P2)?
 *
 * Same existence-gate shape as gatingCapabilitiesTableExists() /
 * tierCapsColumnExists() — request-cached, INFORMATION_SCHEMA with a bound
 * param, any throw ⇒ false (degrade to "no enforce descriptor"). mysqli runs
 * STRICT app-wide (includes/db_mysql.php), so _tierCapsUnionAndEmit() MUST gate
 * the `, EnforceJson` SELECT clause on this — SELECTing a non-existent column
 * throws rather than returning false. Dormant: the column is brand-new and
 * unwritten in P2, so this is inert until a curator (P3+) populates it.
 *
 * @param \mysqli $db Live connection from getDbMysqli().
 * @return bool true once migrate-add-gating-facts-and-licence-types.php has run.
 */
if (!function_exists('gatingCapEnforceColumnExists')) {
    function gatingCapEnforceColumnExists(\mysqli $db): bool
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        try {
            $stmt = $db->prepare(
                "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = 'tblGatingCapabilities'
                    AND COLUMN_NAME = 'EnforceJson' LIMIT 1"
            );
            $stmt->execute();
            $cached = $stmt->get_result()->fetch_row() !== null;
            $stmt->close();
        } catch (\Throwable $_e) {
            $cached = false;
        }
        return $cached;
    }
}

/**
 * Thin I/O shell around tierCapsMergeUnion(): fetches the enabled
 * tblGatingCapabilities rows (existence-gated, request-static cached) and
 * returns BOTH the merged union and the DB-cap EmitInApi map in one place, so
 * tierCapsEffective() and tierCapEmitInApi() share a single cached fetch
 * instead of hitting the DB twice.
 *
 * ANY failure (no DB credentials, un-migrated table, a transient DB error)
 * degrades to `['union' => TIER_CAPS, 'emit' => []]` — byte-identical to
 * pre-#1481 behaviour. This is what "keep the degrade path safe when $db is
 * null" means in practice: passing null makes this call getDbMysqli()
 * itself (wrapped in the same try/catch), so every existing call site that
 * doesn't know about $db keeps working unchanged.
 *
 * Caching is keyed on "was $db explicitly supplied": production call sites
 * omit it and get one cached fetch per request (mirrors
 * tierCapsColumnExists()); tests that want to exercise multiple DB states in
 * one process pass $db explicitly, which bypasses the cache — the deliberate
 * test seam (mirrors #1466's secretCryptoInjectKeyset() pattern).
 *
 * @param ?\mysqli $db Optional connection (test seam); null = getDbMysqli().
 * @return array{union: array<string,array>, emit: array<string,bool>}
 */
if (!function_exists('_tierCapsUnionAndEmit')) {
    function _tierCapsUnionAndEmit(?\mysqli $db = null): array
    {
        static $cached = null;
        $useCache = ($db === null);
        if ($useCache && $cached !== null) {
            return $cached;
        }

        $merged = null;
        try {
            $conn = $db;
            if ($conn === null && function_exists('getDbMysqli')) {
                $conn = getDbMysqli();
            }
            if ($conn instanceof \mysqli && gatingCapabilitiesTableExists($conn)) {
                /* Append `, EnforceJson` ONLY when the column exists (#1769 P2).
                   The clause is a hardcoded constant string chosen by an
                   existence probe — NOT interpolated user input (rule #5) — and
                   mysqli STRICT would THROW on selecting a non-existent column,
                   so an un-migrated docroot must omit it. Dormant: with the
                   column present but unwritten, every row's EnforceJson is NULL
                   and tierCapsMergeUnion() leaves the tuple a 4-tuple. */
                $enforceCol = gatingCapEnforceColumnExists($conn) ? ', EnforceJson' : '';
                $stmt = $conn->prepare(
                    'SELECT CapKey, Label, Description, DefaultValue, EmitInApi' . $enforceCol . '
                       FROM tblGatingCapabilities
                      WHERE Enabled = 1
                      ORDER BY SortOrder ASC, CapKey ASC'
                );
                $stmt->execute();
                $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();

                $merged = tierCapsMergeUnion($rows);
                foreach ($merged['collisions'] as $capKey) {
                    error_log(
                        "[tierCapsEffective] DB gating-capability key '{$capKey}' collides with a"
                        . ' code-registered TIER_CAPS key (case-insensitive, incl. camelCase) —'
                        . ' ignored, the code-registered cap wins.'
                    );
                }
            }
        } catch (\Throwable $_e) {
            /* ANY failure — no credentials, un-migrated table, transient DB
               error — degrades to the code registry alone. */
            $merged = null;
        }

        $out = [
            'union' => $merged['union'] ?? TIER_CAPS,
            'emit'  => $merged['emit']  ?? [],
        ];
        if ($useCache) {
            $cached = $out;
        }
        return $out;
    }
}

/**
 * The effective capability registry: TIER_CAPS ∪ enabled tblGatingCapabilities
 * rows (#1481 P1). This is the ONE registry every surface should consult
 * going forward — the /manage/tiers matrix, the admin_tier_create/update +
 * access_tiers/tier_check API surfaces, and checkTierAccess()'s cap-key
 * resolution (capValueForTierAction()) all read this instead of the bare
 * TIER_CAPS constant, so a Global-Admin-defined capability is picked up
 * everywhere with zero further code changes (CANONICAL HOW-TO for rule #28,
 * now UI-driven as well as one-line-of-code-driven).
 *
 * Dormant + additive: with tblGatingCapabilities empty or absent (an
 * un-migrated docroot), this returns TIER_CAPS unchanged — every consumer
 * behaves byte-identically to before #1481.
 *
 * @param ?\mysqli $db Optional connection (test seam) — see
 *                      _tierCapsUnionAndEmit(). Production call sites omit
 *                      this; it degrades to getDbMysqli() internally.
 * @return array<string,array> Same 4-tuple shape as TIER_CAPS.
 */
if (!function_exists('tierCapsEffective')) {
    function tierCapsEffective(?\mysqli $db = null): array
    {
        return _tierCapsUnionAndEmit($db)['union'];
    }
}

/**
 * Should this capability be surfaced on the public access_tiers API emit?
 *
 * Code-registered caps (TIER_CAPS) are ALWAYS emitted — that's today's
 * contract, unchanged. A DB-defined cap is emitted only when its
 * EmitInApi=1 (the default); an unknown key defaults to true (matches the
 * column default, and never hides a cap the caller didn't know was
 * DB-gated).
 *
 * @param string   $capKey Exact TIER_CAPS / tblGatingCapabilities key.
 * @param ?\mysqli $db     Optional connection (test seam).
 * @return bool
 */
if (!function_exists('tierCapEmitInApi')) {
    function tierCapEmitInApi(string $capKey, ?\mysqli $db = null): bool
    {
        if (array_key_exists($capKey, TIER_CAPS)) {
            return true;
        }
        $emit = _tierCapsUnionAndEmit($db)['emit'];
        return $emit[$capKey] ?? true;
    }
}

/**
 * Storage backing for a capability key: 'column' or 'json'.
 * Unknown keys default to 'json' (the safe additive home) so a typo'd
 * lookup never tries to interpolate a non-existent column name into SQL.
 *
 * @param string $k Capability key (e.g. 'CanViewLyrics').
 * @return string 'column' | 'json'.
 */
if (!function_exists('tierCapStorage')) {
    function tierCapStorage(string $k): string
    {
        /* ELI5: ask the effective registry (code + admin-defined) where this
           cap lives. WHY: re-pointed to tierCapsEffective() (#1481) so a
           Global-Admin-defined capability resolves here too — when
           tblGatingCapabilities is empty/absent, tierCapsEffective() ===
           TIER_CAPS so this is byte-identical to the pre-#1481 lookup. The
           4-tuple's 3rd slot is the storage; default to 'json' for unknown
           keys so we never build SQL around a bad column. */
        return (string)(tierCapsEffective()[$k][2] ?? 'json');
    }
}

/**
 * Default 0/1 for a capability key when its value is absent (json key
 * missing, or the Capabilities column not yet migrated).
 *
 * @param string $k Capability key.
 * @return int 0 | 1.
 */
if (!function_exists('tierCapDefault')) {
    function tierCapDefault(string $k): int
    {
        /* ELI5: what value should we assume if this cap was never set?
           WHY: re-pointed to tierCapsEffective() (#1481) so an admin-defined
           cap's DefaultValue resolves here too. The 4-tuple's 4th slot is
           the default; coerce to 0/1 so an un-migrated install reads every
           json cap at a sane baseline. */
        return ((int)(tierCapsEffective()[$k][3] ?? 0)) === 1 ? 1 : 0;
    }
}

/**
 * Is $e a well-formed `enforce` descriptor (#1769 P2)?
 *
 * The optional 5th TIER_CAPS tuple element tells the content pipeline WHAT a
 * cap gates. A valid descriptor is a NON-EMPTY assoc array whose keys are all
 * drawn from the four recognised ones — any unknown key rejects the WHOLE
 * descriptor (a typo must never silently pass into the registry) — and whose
 * present values are correctly typed:
 *   - 'payload'  => string[]  (payload keys to strip; each non-empty)
 *   - 'media'    => string[]  (media kinds to withhold; each non-empty)
 *   - 'actions'  => string[]  (action names it governs; each non-empty)
 *   - 'requiresCoverage' => a licence coverage kind that
 *                    licenceCoverageKindValid() accepts (LICENCE_COVERAGE_KINDS
 *                    in includes/licence_registry.php).
 *
 * Anything else — a non-array, an empty array, an unknown key, a non-string
 * list member, or an unrecognised coverage kind — is INVALID, so
 * tierCapsMergeUnion() drops it and the tuple stays a plain 4-tuple (the
 * byte-identical additive default). Pure + I/O-free except a lazy require of
 * licence_registry.php ONLY when a 'requiresCoverage' key is actually present,
 * so this adds no file-scope dependency to the widely-loaded validators and
 * stays inert (no production caller in P2).
 *
 * @param array $e Candidate descriptor.
 * @return bool
 */
if (!function_exists('tierCapEnforceShapeValid')) {
    function tierCapEnforceShapeValid(array $e): bool
    {
        if ($e === []) {
            return false;   /* an empty descriptor conveys nothing */
        }
        $known = ['payload' => 1, 'media' => 1, 'actions' => 1, 'requiresCoverage' => 1];
        foreach (array_keys($e) as $k) {
            if (!isset($known[$k])) {
                return false;   /* unknown key ⇒ reject the whole descriptor */
            }
        }
        foreach (['payload', 'media', 'actions'] as $listKey) {
            if (array_key_exists($listKey, $e)) {
                if (!is_array($e[$listKey])) {
                    return false;
                }
                foreach ($e[$listKey] as $v) {
                    if (!is_string($v) || $v === '') {
                        return false;
                    }
                }
            }
        }
        if (array_key_exists('requiresCoverage', $e)) {
            $cov = $e['requiresCoverage'];
            if (!is_string($cov) || $cov === '') {
                return false;
            }
            /* Lazy require — only reached when a descriptor names a coverage
               kind, which no descriptor does in P2. Keeps licence_registry.php
               off the file-scope dependency graph of every access_tier_validation
               consumer. */
            if (!function_exists('licenceCoverageKindValid')) {
                require_once __DIR__ . DIRECTORY_SEPARATOR . 'licence_registry.php';
            }
            if (!licenceCoverageKindValid($cov)) {
                return false;
            }
        }
        return true;
    }
}

/**
 * The `enforce` descriptor for a capability key, or null (#1769 P2).
 *
 * Reads the effective registry's optional 5th tuple element and returns it only
 * when it is shape-valid; otherwise null. The frozen 7 built-in caps carry no
 * enforce element, so this returns null for every one of them — a DB-defined
 * cap gains one only through a shape-valid tblGatingCapabilities.EnforceJson.
 *
 * ZERO production callers in P2 — the resolver/pipeline that will consume this
 * is wired in P3+. Kept here now so the registry is the ONE place enforcement
 * intent is read (rule #28: never a second matrix).
 *
 * @param string $k Capability key.
 * @return array|null The validated descriptor, or null when absent/invalid.
 */
if (!function_exists('tierCapEnforce')) {
    function tierCapEnforce(string $k): ?array
    {
        $e = tierCapsEffective()[$k][4] ?? null;
        return (is_array($e) && tierCapEnforceShapeValid($e)) ? $e : null;
    }
}

/**
 * The capability keys backed by their own tblAccessTiers TINYINT column
 * (the 7 originals). These drive the existing dynamic column SQL.
 *
 * @return string[] Exact column names, in TIER_CAPS order.
 */
if (!function_exists('tierCapColumnKeys')) {
    function tierCapColumnKeys(): array
    {
        /* ELI5: list the caps that have their own DB column.
           WHY: the create/update SQL binds these as real columns; the
           emit selects them as the contract's camelCase keys. Iterates
           tierCapsEffective() (#1481) for symmetry with tierCapJsonKeys() —
           in practice every admin-defined cap is storage 'json' (rule #28),
           so this set is always exactly the 7 built-in column caps, but
           reading through the union keeps this function honest about where
           the list actually comes from. */
        $out = [];
        foreach (tierCapsEffective() as $k => $_t) {
            if (tierCapStorage($k) === 'column') { $out[] = $k; }
        }
        return $out;
    }
}

/**
 * The capability keys stored inside the Capabilities JSON column (every
 * cap added since the json migration). Empty until the first json cap
 * is registered — so the surfaces behave exactly as before until then.
 *
 * @return string[] JSON-backed capability keys, in TIER_CAPS order.
 */
if (!function_exists('tierCapJsonKeys')) {
    function tierCapJsonKeys(): array
    {
        /* ELI5: list the caps that live inside the JSON column.
           WHY: create/update assemble these into one json_encode() bind;
           the emit decodes them and adds each as a camelCase key. Iterates
           tierCapsEffective() (#1481) so an admin-defined capability's key
           is included automatically — no new value storage: it rides the
           SAME tblAccessTiers.Capabilities JSON column every code-registered
           json cap already used (#1352). Dormant: empty/absent
           tblGatingCapabilities ⇒ tierCapsEffective() === TIER_CAPS ⇒ this
           set is unchanged from before #1481. */
        $out = [];
        foreach (tierCapsEffective() as $k => $_t) {
            if (tierCapStorage($k) === 'json') { $out[] = $k; }
        }
        return $out;
    }
}

/**
 * Read a capability's effective 0/1 value off a fetched tier row.
 *
 * Column-backed caps read straight off the row's matching key.
 * JSON-backed caps decode tblAccessTiers.Capabilities and read the key,
 * falling back to the TIER_CAPS default when absent / unmigrated / the
 * JSON is malformed. Used by /manage/tiers.php to render every checkbox's
 * current state regardless of where the cap is stored.
 *
 * @param array  $tierRow A row from tblAccessTiers (assoc).
 * @param string $k       Capability key.
 * @return int 0 | 1.
 */
if (!function_exists('tierCapRead')) {
    function tierCapRead(array $tierRow, string $k): int
    {
        if (tierCapStorage($k) === 'column') {
            /* ELI5: column-backed cap — read its own field if present.
               WHY: keeps the 7 originals identical to today; if a caller
               selected without the column we degrade to the default. */
            return array_key_exists($k, $tierRow)
                ? ((int)$tierRow[$k] === 1 ? 1 : 0)
                : tierCapDefault($k);
        }
        /* ELI5: json-backed cap — decode the Capabilities blob, read key.
           WHY: one JSON column holds every future cap; a NULL/absent
           column or malformed JSON gracefully yields the cap's default
           (https://www.php.net/manual/en/function.json-decode.php). */
        $raw = $tierRow['Capabilities'] ?? null;
        if ($raw === null || $raw === '') {
            return tierCapDefault($k);
        }
        $decoded = is_array($raw) ? $raw : json_decode((string)$raw, true);
        if (!is_array($decoded) || !array_key_exists($k, $decoded)) {
            return tierCapDefault($k);
        }
        return !empty($decoded[$k]) ? 1 : 0;
    }
}

/**
 * Has the Capabilities JSON column been migrated onto tblAccessTiers yet?
 *
 * The CRUD writers + the public emit MUST gate every read/write of the
 * Capabilities column on this — mysqli runs under STRICT mode app-wide
 * (includes/db_mysql.php), so SELECTing / INSERTing a non-existent column
 * THROWS rather than returning false. INFORMATION_SCHEMA is queried with
 * a bound param (rule #5). Result is request-cached so repeated calls in
 * one request (e.g. the emit looping tiers) cost a single round-trip.
 *
 * @param \mysqli $db Live connection from getDbMysqli().
 * @return bool true once migrate-add-tier-capabilities-json.php has run.
 */
if (!function_exists('tierCapsColumnExists')) {
    function tierCapsColumnExists(\mysqli $db): bool
    {
        /* ELI5: remember the answer for the rest of this request.
           WHY: the column either exists or it doesn't for a whole request;
           caching avoids re-hitting INFORMATION_SCHEMA per tier row. */
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        try {
            $stmt = $db->prepare(
                "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = 'tblAccessTiers'
                    AND COLUMN_NAME = 'Capabilities' LIMIT 1"
            );
            $stmt->execute();
            $cached = $stmt->get_result()->fetch_row() !== null;
            $stmt->close();
        } catch (\Throwable $_e) {
            /* ELI5: if the probe itself fails, assume not-migrated.
               WHY: false is the safe degrade — writers skip the column,
               readers fall back to defaults; never let the probe throw up. */
            $cached = false;
        }
        return $cached;
    }
}

/**
 * Validate a tier name. Permits the curator's chosen casing — the
 * Username column on tblAccessTiers is utf8mb4_unicode_ci so the
 * uniqueness check stays case-insensitive without us lowercasing.
 *
 * Letters / digits / hyphen / underscore (#639). 30-char cap.
 *
 * @param string $name Raw tier-name as typed.
 * @return string|null Error message or null if valid.
 */
function validateTierName(string $name): ?string
{
    $name = trim($name);
    if ($name === '') {
        return 'Name is required.';
    }
    if (strlen($name) > 30) {
        return 'Name must be 30 characters or fewer.';
    }
    if (!preg_match('/^[A-Za-z0-9_-]+$/', $name)) {
        return 'Name must be letters, digits, hyphen or underscore.';
    }
    return null;
}

/**
 * Validate the tier `Level` integer. The level governs how the
 * tier_check / licence_check helpers compare two tiers — higher
 * level = more permissions. Bounded to [0, 1000] to prevent a typo
 * (e.g. an extra zero) from making one tier silently outrank
 * everything else in the catalogue.
 *
 * @param int $level Raw level value.
 * @return string|null Error message or null if valid.
 */
function validateTierLevel(int $level): ?string
{
    if ($level < 0 || $level > 1000) {
        return 'Level must be between 0 and 1000.';
    }
    return null;
}
