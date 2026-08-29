<?php

declare(strict_types=1);

/**
 * test-error-page-coverage.php — every status the app emits has friendly
 * copy, or a documented reason it doesn't (#1704)
 * ============================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * WHAT IS BEING GUARDED
 * ----------------------
 * `includes/error_page.php`'s `errorPageContent()` map is the ONE source of
 * friendly copy for an HTTP error status. #1704 found it covered 7 of the ~15
 * statuses the app actually emits — 405 and 410 (a status #1694 D2 made a
 * DELIBERATE, growing choice) had no entry, so a reader hitting either got
 * whatever the generic fallback (or, pre-#1705, nothing at all) produced.
 *
 * This guard asserts BOTH directions, and derives both sides from the tree
 * rather than typing either list by hand (rule #34):
 *
 *   A (emitted -> mapped)  every 4xx/5xx status found emitted anywhere under
 *                          appWeb/public_html is either an errorPageMap() key,
 *                          or sits in a PATH-PINNED exemption (a status *and*
 *                          the exact file(s) allowed to emit it unmapped). An
 *                          exempted status appearing in a file NOT on its own
 *                          list — above all a new includes/pages/*.php
 *                          fragment — is NOT covered by the exemption and
 *                          must fail. A status-blanket exemption (any file,
 *                          any status) would silently swallow exactly that
 *                          case, so exemptions are always {status => {reason,
 *                          paths}}, never a bare status list.
 *
 *   B (mapped -> emitted)  every errorPageMap() key is actually emitted
 *                          somewhere — a dead map entry (copy nobody's status
 *                          ever triggers) is a maintenance trap, not a
 *                          feature.
 *
 *   C                      error.php CONSUMES errorPageStatuses() rather than
 *                          hand-typing its own whitelist — the two-files-
 *                          must-agree failure rule #35 exists for. Checked
 *                          both ways: the call is present, AND the
 *                          `$allowed = …` assignment itself does not contain
 *                          a hand-typed run of status literals (which would
 *                          mean a hand-typed list was restored ALONGSIDE a
 *                          now-dead call, not instead of it).
 *
 * DERIVING THE EMITTED SET — token_get_all(), NOT A REGEX (per the brief)
 * ------------------------------------------------------------------------
 * `api.php` mentions status numbers constantly in comments and prose ("a 409
 * means the slug is taken"), so a raw regex over source text would count
 * those as emissions. This walks tokens instead, built on the shared
 * `tests/php/lib/php_source_units.php` splitter — its `code` view already has
 * every comment erased and every string literal reduced to an opaque
 * `'@STR@'` marker (with identifier-shaped literals like `'status'` kept
 * verbatim, which is what lets the `'status' => <int>` shape still match) —
 * so a `token_get_all('<?php ' . $unit['code'])` re-tokenisation of that view
 * can recognise the five emitter shapes with NO risk of a comment or a prose
 * string supplying a fake status. See that file's header for why this is
 * per-FUNCTION (and per-switch-case for the two dispatch files), not per-file
 * — vouching at file scope is the #1688 bug this deliberately avoids.
 *
 * The five recognised shapes (walked with PAREN-BALANCED matching, never a
 * fixed-width window — rule #34's "a too-narrow window has bitten this repo
 * twice"):
 *   - `http_response_code(<expr>)`      — every integer literal anywhere in
 *     the argument, so a ternary status (`$rdGone ? 410 : 404`, the exact
 *     shape `includes/pages/song.php` uses) contributes BOTH branches.
 *   - `renderErrorPage(<expr>` / `renderErrorFragment(<expr>` — every integer
 *     literal in the FIRST top-level argument only (bounded at the first
 *     top-level comma) — the SAME ternary handling, deliberately NOT reading
 *     the rest of the call, because `$opts` is a second argument that can
 *     itself contain unrelated integers (`'retryAfter' => 30`) that must
 *     never be mistaken for a status.
 *   - `sendJson(…, <int>)` / `ed2_respond(…, <int>)` — status is the LAST
 *     top-level argument, and is counted ONLY when that whole argument is a
 *     single bare integer-literal token (ignoring whitespace). This is
 *     deliberately narrower than the other three shapes: both functions'
 *     OWN DEFINITIONS (`function sendJson(array $data, int $statusCode =
 *     200)` in api.php; `function ed2_respond(array $payload, int $code =
 *     200)` in api2.php) also match "a T_STRING named sendJson/ed2_respond
 *     followed by `(`" — the "exactly one token" requirement is what stops
 *     `int $statusCode = 200` (four tokens) from being misread as a call
 *     with status 200. It also stops a single-argument call (`sendJson($data)`
 *     — implicit 200, the by-far commonest shape) from having its ENTIRE
 *     data payload treated as "the last argument" and every stray integer
 *     inside it misread as a status — collecting every literal in that span,
 *     the way the other three shapes do, would have flooded the derived set
 *     with false positives from ordinary response data. The one accepted
 *     cost: a non-literal last argument (`ed2_respond($x, $ok ? 200 : 400)`,
 *     which exists once, at api2.php's job-poll endpoint) is silently missed
 *     — acceptable because every status it could carry is independently
 *     emitted elsewhere and asserted by the FLOOR below, so under-reporting
 *     THIS shape cannot hide a genuinely uncovered status.
 *   - `'status' => <int>` array-literal — the shape `includes/song_soft_delete.php`
 *     and several `api.php` helpers use for a value later relayed to
 *     `sendJson()` by their caller.
 *
 * `ed2_respond()` lives in `manage/editor/api2.php`, which this file is
 * explicitly forbidden from EDITING (another agent owns it) — reading it is
 * fine and is exactly what this scan does.
 *
 * THE FLOOR (#1701's lesson)
 * ---------------------------
 * A scanner that silently under-reports is worse than none — its green tick
 * reads as coverage. So the derived set must contain at least
 * `{400,401,403,404,405,409,410,413,416,429,500,503}` (verified once, by
 * hand, against the real tree while writing this guard); if the tokenizer
 * walk breaks, this goes RED rather than quietly reporting a smaller "clean"
 * set. 416 is the deliberately fragile member: on the real tree it is ONLY
 * ever emitted via the `http_response_code(<expr>)` shape (audio-media.php /
 * song-media.php's byte-Range handling) — no `sendJson`/`'status' =>` site
 * emits it anywhere — so it is the floor member a crippled
 * `http_response_code` branch drops first (see the mutation log).
 *
 * A DISCOVERY THAT CORRECTED THE BRIEF (worth recording, not hiding — the
 * #1679 obligation: say so when further investigation undermines the plan)
 * ---------------------------------------------------------------------------
 * #1704's brief carried 502 into error.php's whitelist on the assumption
 * "never PHP-emitted — Apache/reverse-proxy Bad Gateway only". Building THIS
 * guard's derivation found that assumption false: api.php's admin-only
 * snapshot-refresh action (the `setup-database.php`-adjacent outbound-fetch
 * step) emits a genuine PHP 502 via `sendJson()` when the upstream fetch
 * fails (api.php, the `sendJson([...], 502)` call in the source-fetch
 * handler). It is JSON-only with no render surface, so it is tracked here as
 * a Part-A exemption (`502 => [...]`) exactly like 409/412/413/416/422,
 * rather than promoted to a map entry — but the "never PHP-emitted" claim in
 * error.php's comment and in #1704 itself was wrong, and is corrected in
 * both places by this commit.
 *
 * MUTATION LOG (each observed RED against the real tree via a `cp` backup,
 * then restored FROM THE BACKUP — never `git checkout --`, per the brief):
 *   M1  removed the 405 entry from errorPageMap()          -> A went RED
 *       (405 is emitted in 4 files; none is exempted, so all 4 (or the
 *       first found) report as "unmapped, unexempted").
 *   M2  added `http_response_code(409);` to
 *       `includes/pages/home.php`                          -> A went RED
 *       (409 IS exempted, but home.php is not on its path list).
 *   M3  added a bogus `418 => [...]` entry to errorPageMap() -> B went RED
 *       (418 is a map key with zero emissions anywhere in the tree).
 *   M4  reverted error.php's `$allowed` line to the old
 *       hand-typed `[400, 401, 403, 404, 429, 500, 502, 503]`
 *                                                            -> C went RED
 *       (no `errorPageStatuses()` call left in the source).
 *   M5  disabled the `http_response_code` branch inside
 *       `errorPageCoverageScanUnit()`                        -> FLOOR went RED
 *       (416 disappears from the derived set — it is emitted nowhere else).
 *   M6  (fixture self-test, run standalone) — scanned a literal string
 *       containing a real `http_response_code(405)`, a multi-line `sendJson`
 *       ending `, 410)`, an `ed2_respond(…, 422)`, a `'status' => 409`
 *       literal, and TWO decoys — `http_response_code(418)` inside a
 *       `//` comment and inside a double-quoted prose string — and asserted
 *       the derived set is EXACTLY `{405, 409, 410, 422}` (418 excluded both
 *       times). Green on first write (see "green on first run" note below).
 *
 * @see appWeb/public_html/includes/error_page.php   errorPageMap() / errorPageStatuses()
 * @see appWeb/public_html/error.php                 the consumer of errorPageStatuses()
 * @see appWeb/public_html/includes/pages/song.php    the #1694/#1704 410 case
 * @see tests/php/lib/php_source_units.php            the shared per-unit splitter
 * @see tests/test-error-response.js                  the sibling "body is shown" guard (#1705)
 */

$root = dirname(__DIR__, 2);
require_once __DIR__ . '/lib/php_source_units.php';
require_once $root . '/appWeb/public_html/includes/error_page.php';

$passed = 0;
$failed = 0;
$failures = [];

function check(string $name, bool $ok, string $detail = ''): void
{
    global $passed, $failed, $failures;
    if ($ok) {
        $passed++;
        echo "  PASS  $name\n";
    } else {
        $failed++;
        $failures[] = ['name' => $name, 'detail' => $detail];
        echo "  FAIL  $name\n";
        if ($detail !== '') {
            echo "        $detail\n";
        }
    }
}

/* ==========================================================================
 * THE SCANNER — shared by the fixture self-test (section 0) and the real
 * tree walk (section 1). See the file header for the shape-by-shape design.
 * ========================================================================== */

function errCovSkipWs(array $toks, int $i, int $n): int
{
    while ($i < $n && is_array($toks[$i]) && $toks[$i][0] === T_WHITESPACE) {
        $i++;
    }
    return $i;
}

/** Index of the `)` matching the `(` at $open (paren-balanced). */
function errCovMatchParen(array $toks, int $open, int $n): int
{
    $depth = 0;
    for ($i = $open; $i < $n; $i++) {
        $t = $toks[$i];
        if (!is_array($t)) {
            if ($t === '(') {
                $depth++;
            } elseif ($t === ')') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }
    }
    return $n - 1;
}

/** Token indexes of every comma at bracket/paren/brace depth 0 in [start, end). */
function errCovTopLevelCommas(array $toks, int $start, int $end): array
{
    $out = [];
    $depth = 0;
    for ($i = $start; $i < $end; $i++) {
        $t = $toks[$i];
        if (is_array($t)) {
            continue;
        }
        if (in_array($t, ['(', '[', '{'], true)) {
            $depth++;
        } elseif (in_array($t, [')', ']', '}'], true)) {
            $depth--;
        } elseif ($t === ',' && $depth === 0) {
            $out[] = $i;
        }
    }
    return $out;
}

/** Every T_LNUMBER in [start, end) that looks like an HTTP status (100-599). */
function errCovCollectInts(array $toks, int $start, int $end): array
{
    $out = [];
    for ($i = $start; $i < $end; $i++) {
        $t = $toks[$i];
        if (is_array($t) && $t[0] === T_LNUMBER) {
            $v = (int)$t[1];
            if ($v >= 100 && $v <= 599) {
                $out[] = $v;
            }
        }
    }
    return $out;
}

/**
 * Scan one unit's already comment/string-stripped `code` view for the five
 * emitter shapes. Appends found statuses into $found (status => true) and a
 * human-readable trace into $hits (status => list<string>) for FAIL detail.
 */
function errorPageCoverageScanUnit(string $code, array &$found, array &$hits): void
{
    $toks = @token_get_all('<?php ' . $code);
    if (!is_array($toks)) {
        return;
    }
    $n = count($toks);

    for ($i = 0; $i < $n; $i++) {
        $t = $toks[$i];

        /* Shape: 'status' => <int> */
        if (is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING && trim($t[1], "'\"") === 'status') {
            $j = errCovSkipWs($toks, $i + 1, $n);
            if (is_array($toks[$j] ?? null) && $toks[$j][0] === T_DOUBLE_ARROW) {
                $k = errCovSkipWs($toks, $j + 1, $n);
                if (is_array($toks[$k] ?? null) && $toks[$k][0] === T_LNUMBER) {
                    $v = (int)$toks[$k][1];
                    if ($v >= 100 && $v <= 599) {
                        $found[$v] = true;
                        $hits[$v][] = "'status' => $v";
                    }
                }
            }
            continue;
        }

        if (!is_array($t) || $t[0] !== T_STRING) {
            continue;
        }
        $name = strtolower($t[1]);
        if (!in_array($name, ['http_response_code', 'sendjson', 'ed2_respond', 'rendererrorpage', 'rendererrorfragment'], true)) {
            continue;
        }

        /* A DEFINITION (`function sendJson(...)`) also matches "T_STRING
           named sendJson" — skip it, don't read its parameter list as a call.
           Walk back over whitespace/comments to the previous real token. */
        $p = $i - 1;
        while ($p >= 0 && is_array($toks[$p]) && in_array($toks[$p][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            $p--;
        }
        if ($p >= 0 && is_array($toks[$p]) && $toks[$p][0] === T_FUNCTION) {
            continue;
        }

        $j = errCovSkipWs($toks, $i + 1, $n);
        if (($toks[$j] ?? null) !== '(') {
            continue;
        }
        $close = errCovMatchParen($toks, $j, $n);

        if ($name === 'http_response_code') {
            foreach (errCovCollectInts($toks, $j + 1, $close) as $v) {
                $found[$v] = true;
                $hits[$v][] = "http_response_code(.. $v ..)";
            }
            continue;
        }

        if ($name === 'rendererrorpage' || $name === 'rendererrorfragment') {
            $commas = errCovTopLevelCommas($toks, $j + 1, $close);
            $firstArgEnd = $commas[0] ?? $close;
            foreach (errCovCollectInts($toks, $j + 1, $firstArgEnd) as $v) {
                $found[$v] = true;
                $hits[$v][] = "$name($v, ..)";
            }
            continue;
        }

        /* sendJson / ed2_respond — status is the LAST top-level arg, counted
           ONLY when it is a single bare integer-literal token. See the file
           header for why this is deliberately narrower than the other
           shapes (definitions + single-argument calls, both real risks). */
        $commas = errCovTopLevelCommas($toks, $j + 1, $close);
        if (!$commas) {
            continue;  // single-argument call — implicit 200, nothing to read
        }
        $lastArgStart = $commas[count($commas) - 1] + 1;
        $onlyToks = [];
        for ($k = $lastArgStart; $k < $close; $k++) {
            if (is_array($toks[$k]) && $toks[$k][0] === T_WHITESPACE) {
                continue;
            }
            $onlyToks[] = $toks[$k];
        }
        if (count($onlyToks) === 1 && is_array($onlyToks[0]) && $onlyToks[0][0] === T_LNUMBER) {
            $v = (int)$onlyToks[0][1];
            if ($v >= 100 && $v <= 599) {
                $found[$v] = true;
                $hits[$v][] = "$name(.., $v)";
            }
        }
    }
}

/** Scan a whole file's source (every unit), returning [statuses, hits]. */
function errorPageCoverageScanFile(string $src): array
{
    $found = [];
    $hits  = [];
    foreach (phpSourceUnits($src) as $unit) {
        errorPageCoverageScanUnit($unit['code'], $found, $hits);
    }
    return [$found, $hits];
}

/* ==========================================================================
 * SECTION 0 — fixture self-test: prove the scanner works BEFORE trusting it
 * against 300+ real files. Comments and prose strings must never contribute
 * a status; real emitter shapes (including a multi-line one) must.
 * ========================================================================== */

echo "0 — fixture self-test (scanner correctness, not the real tree)\n";

$fixture = <<<'PHPFIX'
<?php

function errCovFixtureHandler()
{
    // A comment mentioning http_response_code(418) — must never count.
    if (true) {
        http_response_code(405);
    }

    $prose = "Please don't call http_response_code(418) in prose either.";

    sendJson([
        'error'  => 'conflict',
        'detail' => $prose,
    ], 410);

    ed2_respond(['ok' => false, 'error' => 'not migrated'], 422);

    return ['ok' => false, 'status' => 409, 'error' => 'already exists'];
}
PHPFIX;

$fixtureFound = [];
$fixtureHits  = [];
foreach (phpSourceUnits($fixture) as $unit) {
    errorPageCoverageScanUnit($unit['code'], $fixtureFound, $fixtureHits);
}
$fixtureStatuses = array_keys($fixtureFound);
sort($fixtureStatuses);

check(
    'fixture derives exactly {405, 409, 410, 422} — decoys excluded',
    $fixtureStatuses === [405, 409, 410, 422],
    'got [' . implode(', ', $fixtureStatuses) . ']; the two 418 decoys (comment + prose string) '
    . 'must never appear, and all four real emitter shapes must'
);

/* ==========================================================================
 * SECTION 1 — derive the emitted set from the REAL tree.
 * ========================================================================== */

echo "\n1 — deriving the emitted 4xx/5xx set from appWeb/public_html\n";

$scanRoot = $root . '/appWeb/public_html';
if (!is_dir($scanRoot)) {
    fwrite(STDERR, "FATAL: $scanRoot not found\n");
    exit(1);
}

$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($scanRoot, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    /** @var SplFileInfo $f */
    if ($f->isFile() && strtolower($f->getExtension()) === 'php') {
        $files[] = $f->getPathname();
    }
}
sort($files);

/** @var array<int, array<string,bool>> $emittedByStatus status => {relFile => true} */
$emittedByStatus = [];
/** @var array<string, array<int,array<string>>> $hitsByFile relFile => {status => [trace,...]} */
$hitsByFile = [];
$scanned = 0;

foreach ($files as $file) {
    $rel = substr($file, strlen($scanRoot) + 1);
    $src = file_get_contents($file);
    if ($src === false) {
        /* Never let an unreadable file present as a silent clean pass. */
        check("$rel is readable", false, 'file_get_contents() failed — treated as scanner failure, not zero findings');
        continue;
    }
    $scanned++;
    [$found, $hits] = errorPageCoverageScanFile($src);
    foreach ($found as $status => $true) {
        if ($status < 400 || $status > 599) {
            continue; // 2xx/3xx (sendJson defaults, etc.) are not this guard's concern
        }
        $emittedByStatus[$status][$rel] = true;
        $hitsByFile[$rel][$status] = $hits[$status] ?? [];
    }
}

check(
    'scanned a non-trivial tree',
    $scanned >= 150,
    "found only $scanned .php files under appWeb/public_html — expected 150+ (189 on 2026-07-31); "
    . 'the directory walk itself may be broken'
);

/* ==========================================================================
 * SECTION 2 — assertion A: every emitted status is mapped or path-pinned
 * exempted.
 * ========================================================================== */

echo "\n2 — A: every emitted 4xx/5xx status is mapped, or exempted for THAT file\n";

$mapKeys = errorPageStatuses();

/* PATH-PINNED exemptions — {status => {reason, paths}}. A status here is
   excused ONLY when found in one of its OWN listed files (relative to
   appWeb/public_html). A NEW file — above all anything under
   includes/pages/ — emitting an exempted status is NOT covered and must
   fail; see mutation M2. Derived by inspecting every current emission site
   while writing this guard (2026-07-31), not guessed. */
$exemptions = [
    409 => [
        'reason' => 'Editor save conflicts, un-migrated-table refusals and duplicate-name refusals — every '
                  . 'site returns JSON (sendJson / ed2_respond, or a return-array a caller relays to sendJson), '
                  . 'never a rendered page.',
        'paths' => [
            'api.php', 'includes/api_keys.php', 'includes/duplicate_song_admin.php',
            'includes/musician_duplicates.php', 'includes/song_link_admin.php',
            'includes/song_soft_delete.php', 'manage/api-keys.php',
            'manage/duplicate-songs.php', 'manage/editor/api.php', 'manage/editor/api2.php',
            'manage/external-link-types.php', 'manage/languages.php', 'manage/musician-duplicates.php',
            'manage/organisations.php', 'manage/setup-database.php', 'manage/songbooks.php', 'manage/tags.php',
        ],
    ],
    412 => [
        'reason' => "setup-database.php's own plain-text migration-console protocol (STATUS:/ACTION:/ERROR: "
                  . 'lines, for a not-yet-configured DB connection) — an admin console response, not a page.',
        'paths' => ['manage/setup-database.php'],
    ],
    413 => [
        'reason' => 'Request/upload size caps (set-list + template payload limits #1661/#1662, editor bulk '
                  . 'import) — JSON refusals, no render surface. Also never Apache-routed (see .htaccess\'s '
                  . 'ERROR HANDLING comment): no LimitRequestBody is configured, and the client\'s connection '
                  . 'is often closed mid-upload before any ErrorDocument body would be seen.',
        'paths' => ['api.php', 'manage/editor/api.php', 'manage/editor/api2.php'],
    ],
    416 => [
        'reason' => 'Static byte-Range requests on the audio/media byte-serving endpoints — the receiver is '
                  . 'always an <audio>/<video> element, which never renders a response body regardless of '
                  . 'what is served.',
        'paths' => ['audio-media.php', 'song-media.php'],
    ],
    422 => [
        'reason' => 'Unprocessable ingest/import payloads, an un-classified soft-delete refusal, and (security '
                  . 'audit F2, 2026-08-29) the wizard-suite\'s repeatable-row DoS caps rejecting an '
                  . 'oversized pattern/licence/member-row batch BEFORE a single row is processed. '
                  . 'organisations.php\'s cap check sits in its JSON-only wizard_create_organisation branch, same '
                  . 'shape as the rest of this exemption. external-link-types.php\'s cap check ALSO fires from its '
                  . 'classic-form save_type_patterns/create_type cases — those set the status code and continue '
                  . "rendering the normal HTML page with \$error inline (not a JSON body), the SAME non-JSON shape "
                  . "that page's own pre-existing 409 duplicate-slug refusal already has in the 409 exemption "
                  . 'above; grouped here rather than invented as a new exemption entry. #2003 (the "Connect a '
                  . 'service" wizard) added TWO more JSON-only 422 sites on configuration.php, same shape as '
                  . "the rest of this exemption: the integration_test branch's unknown-integration guard, and "
                  . 'the additive respond=json envelope passing through the classic save handler\'s OWN '
                  . '(unchanged) validation-error path as a status code instead of the classic $saveError HTML '
                  . 'render — no new render surface, no new validation rule.',
        'paths' => [
            'api.php', 'includes/song_soft_delete.php', 'manage/configuration.php', 'manage/editor/api2.php',
            'manage/external-link-types.php', 'manage/organisations.php',
        ],
    ],
    502 => [
        'reason' => "CORRECTED while building this guard (#1704): the brief assumed 502 was 'never "
                  . "PHP-emitted' (Apache/reverse-proxy Bad Gateway only). api.php's admin-only snapshot-"
                  . 'refresh action (the outbound-fetch step used by setup-database.php) emits a genuine PHP '
                  . '502 via sendJson() when the upstream fetch fails — JSON-only, admin-gated, no render '
                  . 'surface. See error.php\'s $allowed comment for the corrected note. BCP 47 registry plan '
                  . '§3 (M1) added language-registry-refresh.php as a second site: the SAME upstream-fetch '
                  . 'failure, reported via a plain json_encode()+http_response_code(502) (the webhook-drain.php '
                  . 'shape has no sendJson() available), also JSON-only / keyed-caller-only / no render surface.',
        'paths' => ['api.php', 'language-registry-refresh.php'],
    ],
];

$unexplained = [];
foreach ($emittedByStatus as $status => $filesFound) {
    if (in_array($status, $mapKeys, true)) {
        continue; // covered by errorPageMap() — Part A satisfied for every file
    }
    $allowedPaths = $exemptions[$status]['paths'] ?? [];
    foreach (array_keys($filesFound) as $rel) {
        if (!in_array($rel, $allowedPaths, true)) {
            $trace = implode(', ', $hitsByFile[$rel][$status] ?? []);
            $unexplained[] = "$status in $rel  ($trace)";
        }
    }
}
sort($unexplained);

check(
    'every emitted status is a map key or exempted for its own file',
    $unexplained === [],
    "unexplained emission(s):\n        " . implode("\n        ", $unexplained)
);

/* ==========================================================================
 * SECTION 3 — assertion B: every map key is actually emitted somewhere.
 * ========================================================================== */

echo "\n3 — B: every errorPageMap() key is emitted somewhere in the tree\n";

foreach ($mapKeys as $status) {
    check(
        "map key $status is emitted somewhere",
        isset($emittedByStatus[$status]) && $emittedByStatus[$status] !== [],
        "no recognised emitter shape produces $status anywhere under appWeb/public_html — "
        . 'a dead map entry nobody can ever trigger'
    );
}

/* error.php's ONE addition beyond the map, 502, is a documented
   reverse-exemption rather than something this guard demands tree evidence
   for (the Apache/reverse-proxy Bad Gateway case is real regardless of
   whether PHP also happens to emit it) — but as the exemption above records,
   it turns out PHP does too, so this is belt-and-braces rather than
   load-bearing today. */
$reverseExemptions = [502 => 'Apache/reverse-proxy Bad Gateway — real independent of any PHP emission.'];
check(
    "error.php's non-map whitelist addition (502) is documented, not silent",
    isset($reverseExemptions[502]),
    'error.php extends errorPageStatuses() with a bare 502 — this guard expects that documented here'
);

/* ==========================================================================
 * SECTION 4 — assertion C: error.php derives its whitelist, doesn't hand-type it.
 * ========================================================================== */

echo "\n4 — C: error.php consumes errorPageStatuses(), not a hand-typed list\n";

$errorPhpPath = $scanRoot . '/error.php';
$errorPhpSrc  = (string)file_get_contents($errorPhpPath);

check(
    'error.php calls errorPageStatuses()',
    str_contains($errorPhpSrc, 'errorPageStatuses()'),
    'no call found — the whitelist is (once again) hand-typed independently of errorPageMap()'
);

/* Pull the actual `$allowed = …;` statement (may span lines) and confirm it
   is not ALSO carrying a hand-typed run of status literals alongside (or
   instead of) the call — i.e. more than the one expected literal (502). */
if (preg_match('/\$allowed\s*=\s*(.*?);/s', $errorPhpSrc, $m)) {
    $literalCount = preg_match_all('/\b\d{3}\b/', $m[1]);
    check(
        '$allowed assignment carries at most one hand-typed status literal (502)',
        $literalCount <= 1,
        "found $literalCount three-digit literals in: " . trim(preg_replace('/\s+/', ' ', $m[1]))
        . ' — a hand-typed whitelist has crept back in'
    );
} else {
    check('found a $allowed = … assignment in error.php', false, 'the statement shape changed; update this regex');
}

/* ==========================================================================
 * SECTION 5 — the floor: the derived set must be at least this rich, or the
 * scanner itself is broken (#1701 — under-reporting reads as coverage).
 * ========================================================================== */

echo "\n5 — floor: the derived set must contain at least the known baseline\n";

$floor = [400, 401, 403, 404, 405, 409, 410, 413, 416, 429, 500, 503];
$missingFromFloor = [];
foreach ($floor as $status) {
    if (!isset($emittedByStatus[$status]) || $emittedByStatus[$status] === []) {
        $missingFromFloor[] = $status;
    }
}
check(
    'derived set contains the floor {400,401,403,404,405,409,410,413,416,429,500,503}',
    $missingFromFloor === [],
    'missing from the derived set: [' . implode(', ', $missingFromFloor) . '] — either a genuine regression '
    . 'or the tokenizer walk under-reported; 416 is the canary (only ever reached via the '
    . 'http_response_code(<expr>) shape) so a crippled branch shows up here first'
);

/* ==========================================================================
 * VERDICT
 * ========================================================================== */

echo "\n$passed passed, $failed failed\n";
if ($failed > 0) {
    echo "\nFailures:\n";
    foreach ($failures as $f) {
        echo "  - {$f['name']}" . ($f['detail'] !== '' ? "\n    {$f['detail']}" : '') . "\n";
    }
    exit(1);
}
exit(0);
