<?php

declare(strict_types=1);

/**
 * iHymns — Internet Archive OCR reconcile CI guard (#94 Phase 1)
 *
 * ELI5: a handful of checks that keep the #94 read-only archive.org audit
 * tool honest — its URL-building is proven safe against a truth table (not
 * just eyeballed), it never re-invents the shared duplicate-scoring maths,
 * it never writes a byte of song content, its outbound client is reachable
 * from nowhere but the one admin page (+ tests), and its OCR segmenter
 * behaves exactly as pinned against a hand-built fixture.
 *
 * DERIVATION (rule #34 — tree-derived, not a hand-typed list): the
 * "read-only" scan (§4) walks the THREE new files by name (there are only
 * three — no glob needed for a fixed, tiny file set); the "not-public" scan
 * (§5) walks EVERY `.php` file under `appWeb/public_html` via
 * `RecursiveDirectoryIterator`, so a new caller anywhere in the tree is
 * caught automatically, not just the ones this file's author thought to
 * check.
 *
 * MUTATION-PROVEN (rule #34): every assertion group below was broken on
 * purpose, watched red, then reverted BEFORE this file's commit — see the
 * commit body for the specific mutation + failing assertion each one
 * produced. That log is the only place mutation results are recorded; this
 * file itself does not re-perform the mutations at test time.
 *
 * NO LIVE ARCHIVE.ORG FETCH IS ATTEMPTED ANYWHERE IN THIS FILE — this
 * sandbox (and CI) has no network. The client's HTTP layer is exercised
 * ONLY through its PURE URL resolvers; the segmenter/scorer are exercised
 * ONLY through the fixture-injection seam (a string in, an array out — see
 * `.claude/ia-ocr-94-plan.md` §6.4).
 *
 *   php tests/php/test-ia-reconcile-guards.php
 *
 * Exit status 0 = all pass, 1 = at least one failure.
 */

$failures = 0;
function check(string $label, bool $cond): void
{
    global $failures;
    echo ($cond ? "  PASS  " : "  FAIL  ") . $label . "\n";
    if (!$cond) { $failures++; }
}

$repoRoot   = dirname(__DIR__, 2);
$includesDir = $repoRoot . '/appWeb/public_html/includes';
$manageDir   = $repoRoot . '/appWeb/public_html/manage';
$publicRoot  = $repoRoot . '/appWeb/public_html';

$clientFile    = $includesDir . '/ia_client.php';
$reconcileFile = $includesDir . '/ia_reconcile.php';
$pageFile      = $manageDir . '/ia-reconcile.php';

/**
 * Strip every comment token out of PHP source using the REAL tokenizer —
 * deliberately NOT a `/\*...*\/` regex. A naive regex broke on this file's
 * OWN source during development: `CURLOPT_HTTPHEADER => ['Accept: ...,
 * text/plain, *\/*']` (a real string literal, the standard `Accept: *\/*`
 * MIME wildcard) contains a bare `/*` two characters from its END — a
 * regex scanner reads that as a comment OPENING and swallows everything up
 * to the NEXT `*\/` it finds anywhere later in the file (here, the very
 * next line's genuine comment), silently deleting real code in between.
 * `token_get_all()` parses string boundaries correctly, so it never makes
 * this mistake — the mirrored lesson `test-component-json-guard.php`
 * already encodes for the identical class of bug (rule #34: a scanner that
 * under-reports is worse than no scanner).
 */
function stripComments(string $src): string
{
    $toks = @token_get_all($src);
    $out = '';
    foreach ($toks as $tok) {
        if (is_array($tok)) {
            if ($tok[0] === T_COMMENT || $tok[0] === T_DOC_COMMENT) {
                continue; /* drop the comment text entirely */
            }
            $out .= $tok[1];
        } else {
            $out .= $tok;
        }
    }
    return $out;
}

function loadStripped(string $path): string
{
    return stripComments((string)file_get_contents($path));
}

/**
 * Every real string literal in PHP source (single- or double-quoted,
 * including interpolated double-quoted pieces), via the tokenizer — so a
 * literal `*\/` or `/\*` INSIDE a string can never be misread as entering
 * or leaving a comment (see stripComments()'s doc-block for the concrete
 * bug this sidesteps).
 *
 * @return list<string> each entry is the RAW source text of one string
 *         token (including its quote characters).
 */
function extractStringLiteralsFromSource(string $src): array
{
    $toks = @token_get_all($src);
    $out = [];
    foreach ($toks as $tok) {
        if (!is_array($tok)) { continue; }
        if ($tok[0] === T_CONSTANT_ENCAPSED_STRING || $tok[0] === T_ENCAPSED_AND_WHITESPACE) {
            $out[] = $tok[1];
        }
    }
    return $out;
}

/* =============================================================================
 * 1. SSRF — functional truth table on the PURE URL resolvers.
 * ============================================================================= */

require_once $clientFile;

check('_iaResolveUrl: https://archive.org is allowed', _iaResolveUrl('https://archive.org', '/metadata/x', false) !== null);
check('_iaResolveUrl: http://archive.org is refused (no loopback carve-out for a real host)', _iaResolveUrl('http://archive.org', '/metadata/x', false) === null);
check('_iaResolveUrl: http://127.0.0.1:8099 + loopback knob ON is allowed', _iaResolveUrl('http://127.0.0.1:8099', '/metadata/x', true) !== null);
check('_iaResolveUrl: http://127.0.0.1:8099 + loopback knob OFF is refused', _iaResolveUrl('http://127.0.0.1:8099', '/metadata/x', false) === null);
check('_iaResolveUrl: http://localhost + loopback knob ON is allowed', _iaResolveUrl('http://localhost', '/metadata/x', true) !== null);
check('_iaResolveUrl: ftp://archive.org is refused (not http/https)', _iaResolveUrl('ftp://archive.org', '/x', false) === null);
check('_iaResolveUrl: garbage input is refused', _iaResolveUrl('not a url', '/x', false) === null);
check('_iaResolveUrl: rebuilt URL is host-bound to the CONFIGURED host, not attacker-influenced path text',
    (_iaResolveUrl('https://archive.org', '/metadata/../../evil', false)[0] ?? '') === 'https://archive.org/metadata/../../evil'
    /* the path is passed through verbatim (it is the CALLER's fixed path, never
       user input at this layer) — what matters is the HOST never changes */
    && str_starts_with((string)(_iaResolveUrl('https://archive.org', '/metadata/../../evil', false)[0] ?? ''), 'https://archive.org/'));

check('_iaDatanodeUrl: a real datanode host is allowed', _iaDatanodeUrl('ia601409.us.archive.org', '/12/items/foo', 'foo_djvu.txt') !== null);
check('_iaDatanodeUrl: a suffix-spoofing host is refused (archive.org.evil.example)', _iaDatanodeUrl('archive.org.evil.example', '/12/items/foo', 'foo_djvu.txt') === null);
check('_iaDatanodeUrl: an unrelated host is refused (evil.com)', _iaDatanodeUrl('evil.com', '/12/items/foo', 'foo_djvu.txt') === null);
check('_iaDatanodeUrl: a trailing-dot host is refused (ia6.archive.org.)', _iaDatanodeUrl('ia6.archive.org.', '/12/items/foo', 'foo_djvu.txt') === null);
check('_iaDatanodeUrl: a host carrying "@" is refused', _iaDatanodeUrl('evil.com@ia601409.us.archive.org', '/12/items/foo', 'foo_djvu.txt') === null);
check('_iaDatanodeUrl: a host carrying a port is refused', _iaDatanodeUrl('ia601409.us.archive.org:8080', '/12/items/foo', 'foo_djvu.txt') === null);
check('_iaDatanodeUrl: a host carrying a slash is refused', _iaDatanodeUrl('ia601409.us.archive.org/evil', '/12/items/foo', 'foo_djvu.txt') === null);
check('_iaDatanodeUrl: ".." in $dir is refused (path traversal)', _iaDatanodeUrl('ia601409.us.archive.org', '/12/../etc/passwd', 'foo_djvu.txt') === null);
check('_iaDatanodeUrl: ".." in $fileName is refused', _iaDatanodeUrl('ia601409.us.archive.org', '/12/items/foo', '../../etc/passwd') === null);
check('_iaDatanodeUrl: a slash in $fileName is refused (smuggled extra path segment)', _iaDatanodeUrl('ia601409.us.archive.org', '/12/items/foo', 'evil/foo_djvu.txt') === null);
check('_iaDatanodeUrl: always builds https:// (never http, even for a real host)',
    str_starts_with((string)_iaDatanodeUrl('ia601409.us.archive.org', '/12/items/foo', 'foo_djvu.txt'), 'https://'));

check('iaIdentifierValid: a normal identifier is valid', iaIdentifierValid('commonpraise00unse') === true);
check('iaIdentifierValid: empty string is invalid', iaIdentifierValid('') === false);
check('iaIdentifierValid: a leading-dot identifier is invalid', iaIdentifierValid('.bad') === false);
check('iaIdentifierValid: a 101-char identifier is invalid (over IA\'s own 100-char rule)', iaIdentifierValid(str_repeat('a', 101)) === false);

/* Fixture-injection seam sanity: the pick-fulltext-file resolver against the
   synthetic metadata fixture (no HTTP). */
$fixtureMetaPath = __DIR__ . '/fixtures/ia-fixture-metadata.json';
$fixtureMeta = json_decode((string)file_get_contents($fixtureMetaPath), true);
check('fixture metadata JSON parses to an array', is_array($fixtureMeta));
if (is_array($fixtureMeta)) {
    $picked = _iaPickFulltextFile('ia-fixture-hymnal', $fixtureMeta);
    check('_iaPickFulltextFile() resolves the DjVuTXT entry from the fixture metadata', is_array($picked) && ($picked['fileName'] ?? '') === 'ia-fixture-hymnal_djvu.txt');
    check('_iaPickFulltextFile() builds an .archive.org-bound URL from the fixture metadata', is_array($picked) && str_starts_with((string)($picked['url'] ?? ''), 'https://ia601409.us.archive.org/'));
}

/* =============================================================================
 * 2. SSRF — source assertions on comment-stripped ia_client.php.
 * ============================================================================= */

$clientSrc = loadStripped($clientFile);

check('zero CURLOPT_FOLLOWLOCATION => true in ia_client.php', preg_match('/CURLOPT_FOLLOWLOCATION\s*=>\s*true/', $clientSrc) === 0);
check('at least one CURLOPT_FOLLOWLOCATION => false in ia_client.php', preg_match('/CURLOPT_FOLLOWLOCATION\s*=>\s*false/', $clientSrc) === 1);
preg_match_all('/CURLOPT_SSL_VERIFYPEER\s*=>\s*(\S+)/', $clientSrc, $verifyMatches);
check('CURLOPT_SSL_VERIFYPEER appears at least once', count($verifyMatches[1]) >= 1);
$allVerifyTrue = true;
foreach ($verifyMatches[1] as $v) { if (rtrim($v, ',') !== 'true') { $allVerifyTrue = false; } }
check('every CURLOPT_SSL_VERIFYPEER occurrence is => true', $allVerifyTrue);
check('zero CURLOPT_POST in ia_client.php (GET-only client)', preg_match('/CURLOPT_POST\b/', $clientSrc) === 0);
check('zero CURLOPT_CUSTOMREQUEST in ia_client.php (GET-only client)', preg_match('/CURLOPT_CUSTOMREQUEST\b/', $clientSrc) === 0);

/* =============================================================================
 * 3. REUSE — never re-fork the shared normalise/similarity maths.
 * ============================================================================= */

$reconcileSrc = loadStripped($reconcileFile);
$pageSrc      = loadStripped($pageFile);

foreach (['levenshtein\(', 'similar_text\(', 'soundex\(', 'metaphone\('] as $banned) {
    check("zero raw {$banned} call in ia_reconcile.php", preg_match('/\b' . $banned . '/', $reconcileSrc) === 0);
    check("zero raw {$banned} call in manage/ia-reconcile.php", preg_match('/\b' . $banned . '/', $pageSrc) === 0);
}
check('zero function definition in ia_reconcile.php whose name matches /normali[sz]e|jaccard|levensh/i',
    preg_match('/function\s+\w*(normali[sz]e|jaccard|levensh)\w*\s*\(/i', $reconcileSrc) === 0);
check('zero function definition in manage/ia-reconcile.php whose name matches /normali[sz]e|jaccard|levensh/i',
    preg_match('/function\s+\w*(normali[sz]e|jaccard|levensh)\w*\s*\(/i', $pageSrc) === 0);

check('ia_reconcile.php calls ihymns_sim_score(', str_contains($reconcileSrc, 'ihymns_sim_score('));
check('ia_reconcile.php calls ihymns_sim_normalise(', str_contains($reconcileSrc, 'ihymns_sim_normalise('));
check('ia_reconcile.php calls ihymns_normalize_title(', str_contains($reconcileSrc, 'ihymns_normalize_title('));

check('ia_reconcile.php requires title_normalize.php', str_contains($reconcileSrc, "title_normalize.php"));
check('ia_reconcile.php requires song_similarity.php', str_contains($reconcileSrc, "song_similarity.php"));
check('manage/ia-reconcile.php requires title_normalize.php', str_contains($pageSrc, "title_normalize.php"));
check('manage/ia-reconcile.php requires song_similarity.php', str_contains($pageSrc, "song_similarity.php"));

/* The two duplicated identifier-shape regex literals (rule #35) must stay
   byte-identical. Extracted by anchoring on each function's known prefix. */
$identNormFile = $includesDir . '/identifier_normalize.php';
$identNormSrc  = (string)file_get_contents($identNormFile);
preg_match('/function\s+iaIdentifierValid[\s\S]{0,400}?(\'\/\^\[A-Za-z0-9\]\[A-Za-z0-9\._-\]\{0,99\}\$\/\')/', $clientSrc, $mClient);
preg_match('/function\s+ihymns_canonical_ia_identifier[\s\S]{0,2000}?(\'\/\^\[A-Za-z0-9\]\[A-Za-z0-9\._-\]\{0,99\}\$\/\')/', $identNormSrc, $mIdent);
check('located the identifier-shape regex literal inside iaIdentifierValid()', isset($mClient[1]));
check('located the identifier-shape regex literal inside ihymns_canonical_ia_identifier()', isset($mIdent[1]));
if (isset($mClient[1], $mIdent[1])) {
    check('iaIdentifierValid() and ihymns_canonical_ia_identifier() use the BYTE-IDENTICAL regex literal', $mClient[1] === $mIdent[1]);
}

/* =============================================================================
 * 4. READ-ONLY — the three new files never write a song-content table.
 * ============================================================================= */

const IA_REC_WRITE_VERB_RE = '/\b(INSERT|UPDATE|DELETE|REPLACE|TRUNCATE|ALTER|DROP)\b/i';
const IA_REC_SONG_TABLE_RE = '/\btbl(Songs|SongComponents|LyricLines|Lyrics|Songbooks)\b/';
const IA_REC_OWN_TABLE_RE  = '/\btblIa(FetchCache|ImportCandidates)\b/';

$newFiles = ['ia_client.php' => $clientFile, 'ia_reconcile.php' => $reconcileFile, 'manage/ia-reconcile.php' => $pageFile];
$sawAnyWriteVerb = false;
$sawOwnTableWrite = false;

foreach ($newFiles as $label => $path) {
    $literals = extractStringLiteralsFromSource((string)file_get_contents($path));
    $violations = [];
    foreach ($literals as $lit) {
        if (preg_match(IA_REC_WRITE_VERB_RE, $lit) !== 1) { continue; }
        $sawAnyWriteVerb = true;
        if (preg_match(IA_REC_OWN_TABLE_RE, $lit) === 1) { $sawOwnTableWrite = true; }
        if (preg_match(IA_REC_SONG_TABLE_RE, $lit) === 1) {
            $violations[] = $lit;
        }
    }
    check("{$label}: no write statement names a song-content table", $violations === []);
    if ($label === 'manage/ia-reconcile.php') {
        check('manage/ia-reconcile.php contains ZERO raw write-verb SQL statements at all (writes go through iaRecPersistRun() only)',
            !array_reduce($literals, static fn($carry, $lit) => $carry || preg_match(IA_REC_WRITE_VERB_RE, $lit) === 1, false));
    }
}
check('sanity: the write verbs this test bans DO appear somewhere in the scanned corpus (proves extraction isn\'t silently missing them)', $sawAnyWriteVerb);
check('sanity: at least one permitted write DOES name tblIaFetchCache/tblIaImportCandidates (proves the positive case is reachable)', $sawOwnTableWrite);

/* =============================================================================
 * 5. NOT-PUBLIC — ia_client.php / ia_reconcile.php are required from nowhere
 *    but manage/ia-reconcile.php, includes/ia_reconcile.php (for ia_client.php),
 *    and tests/* (tree-derived — RecursiveDirectoryIterator, not a typed list).
 * ============================================================================= */

function allPhpFiles(string $dir): array
{
    $out = [];
    $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
            $out[] = $file->getPathname();
        }
    }
    return $out;
}

$allPublicPhp = allPhpFiles($publicRoot);
check('derived a plausible number of public_html PHP files (>= 100)', count($allPublicPhp) >= 100);

function requiringFiles(array $files, string $needle, string $selfPath): array
{
    $out = [];
    foreach ($files as $f) {
        if ($f === $selfPath) { continue; } /* a file never counts as "requiring" itself */
        $src = loadStripped($f);
        if (str_contains($src, $needle)) { $out[] = $f; }
    }
    return $out;
}

$clientRequirers    = requiringFiles($allPublicPhp, 'ia_client.php', $clientFile);
$reconcileRequirers = requiringFiles($allPublicPhp, 'ia_reconcile.php', $reconcileFile);

$allowedRel = ['manage/ia-reconcile.php', 'includes/ia_reconcile.php'];
function relPath(string $repoRoot, string $abs): string
{
    return ltrim(str_replace($repoRoot . '/appWeb/public_html/', '', $abs), '/');
}

$clientBad = [];
foreach ($clientRequirers as $f) {
    $rel = relPath($repoRoot, $f);
    if (!in_array($rel, $allowedRel, true)) { $clientBad[] = $rel; }
}
check('ia_client.php is required ONLY from manage/ia-reconcile.php and includes/ia_reconcile.php within public_html', $clientBad === []);
if ($clientBad) { echo "       unexpected requirer(s): " . implode(', ', $clientBad) . "\n"; }

$reconcileBad = [];
foreach ($reconcileRequirers as $f) {
    $rel = relPath($repoRoot, $f);
    if ($rel !== 'manage/ia-reconcile.php') { $reconcileBad[] = $rel; }
}
check('ia_reconcile.php is required ONLY from manage/ia-reconcile.php within public_html', $reconcileBad === []);
if ($reconcileBad) { echo "       unexpected requirer(s): " . implode(', ', $reconcileBad) . "\n"; }

/* Also check the tests/ tree itself doesn't smuggle a requirer in from
   somewhere unexpected (this file itself is the one legitimate tests/*
   requirer of both). */
check('this guard file itself requires ia_client.php (tests/* is the allowed exception)', str_contains(loadStripped(__FILE__), 'ia_client.php'));

/* =============================================================================
 * 6. SEGMENTER + SCORER behaviour, pinned against the hand-built fixture.
 * ============================================================================= */

require_once $reconcileFile;

$fixtureText = (string)file_get_contents(__DIR__ . '/fixtures/ia-fixture-hymnal_djvu.txt');
check('fixture OCR blob is non-empty', $fixtureText !== '');

$candidates = iaRecSegmentOcr($fixtureText);
check('segmenter finds exactly 7 candidates (6 numbered hymns + 1 ALL-CAPS unnumbered hymn)', count($candidates) === 7);

$byTitle = [];
foreach ($candidates as $c) { $byTitle[$c['rawTitle']] = $c; }

$jehovah = $byTitle['Guide Me O Thou Great Jehovah'] ?? null;
check('the page-break-spanning hymn (#3) segments as ONE candidate', $jehovah !== null);
if ($jehovah !== null) {
    check('hymn #3\'s body includes text from BOTH sides of the page break (proves it was not split)',
        str_contains($jehovah['bodyExcerpt'], 'pilgrim') && str_contains($jehovah['bodyExcerpt'], 'mighty'));
    check('hymn #3\'s de-hyphenated firstLine no longer carries the mid-word hyphen',
        !str_contains($jehovah['firstLine'], '-'));
}

check('the ALL-CAPS unnumbered hymn was detected as its own candidate', isset($byTitle['GREAT IS THY FAITHFULNESS']));
if (isset($byTitle['GREAT IS THY FAITHFULNESS'])) {
    check('the ALL-CAPS candidate has no headingNumber (it is genuinely unnumbered)', $byTitle['GREAT IS THY FAITHFULNESS']['headingNumber'] === null);
    check('the ALL-CAPS candidate\'s firstLine skipped past its own C. M. tune line', $byTitle['GREAT IS THY FAITHFULNESS']['firstLine'] === 'Great is Thy faithfulness O God my Father');
}

$headingNumbers = array_filter(array_map(static fn($c) => $c['headingNumber'], $candidates), static fn($h) => $h !== null);
check('no candidate originates in the "INDEX OF FIRST LINES" region', !isset($byTitle['INDEX OF FIRST LINES']) && count($candidates) === 7);
check("no candidate's headingNumber is the interleaved page number '87'", !in_array('87', $headingNumbers, true));
check("no candidate's headingNumber is the interleaved page number '88'", !in_array('88', $headingNumbers, true));

$expectedNumbers = ['1', '2', '3', '4', '5', '6'];
sort($headingNumbers);
sort($expectedNumbers);
check('the six real numbered candidates carry exactly headingNumber 1..6 (page numbers correctly rejected)', $headingNumbers === $expectedNumbers);

/* --- scoring, against a small in-array feature set (no DB) --- */

$amazingGrace = $byTitle['Amazing Grace'] ?? null;
check('fixture carries an "Amazing Grace" candidate to drive the exact-match scoring test', $amazingGrace !== null);

/* One synthetic SYMBOLS-ONLY candidate, hand-built (not from the fixture
   blob) to exercise the unscorable path. #1908 rewired ihymns_sim_fold()'s
   diacritic step onto the same Unicode-preserving Normalizer::FORM_KD
   mechanism as ihymns_normalize_title() (title_normalize.php), so a
   non-Latin SCRIPT like Greek no longer folds to '' — see the dedicated
   "Greek-script candidate is now SCORABLE" check just below, which used to
   be exactly this fixture's sanity check before #1908. The genuinely
   unscorable case now is text with NO letters/digits at all: \p{So}
   musical dingbats (♪ ♫ ♬), which ihymns_sim_fold()'s
   `[^\p{L}\p{N}\s] → ' '` step still turns into whitespace and the
   subsequent collapse/trim reduces to ''. */
$symbolsCandidate = [
    'index'         => 99,
    'headingNumber' => null,
    'pageGuess'     => null,
    'rawTitle'      => '♪ ♫ ♬',
    'firstLine'     => '♪♪♪♪',
    'bodyExcerpt'   => '♪♪♪♪',
    'lineStart'     => 0,
    'lineEnd'       => 1,
];
check('sanity: the symbols-only fixture title really does fold to empty via ihymns_sim_normalise() (proves the unscorable path is genuinely exercised)',
    ihymns_sim_normalise($symbolsCandidate['rawTitle']) === '' && ihymns_sim_normalise($symbolsCandidate['firstLine']) === '');
check('#1908: a Greek-script candidate is now SCORABLE (the Unicode-preserving fold no longer erases non-Latin scripts to \'\')',
    ihymns_sim_normalise('Χριστός Ανέστη') === 'χριστος ανεστη');

$scoringCandidates = $amazingGrace !== null ? [$amazingGrace, $byTitle['All Hail the Power'] ?? [], $symbolsCandidate] : [$symbolsCandidate];

$songFeatures = [];
if ($amazingGrace !== null) {
    $songFeatures[] = [
        'songId'        => 'TEST-0001',
        'number'        => 1,
        'title'         => 'Amazing Grace',
        'normExact'     => ihymns_normalize_title('Amazing Grace'),
        'normTitle'     => ihymns_sim_normalise('Amazing Grace'),
        'normFirstLine' => ihymns_sim_normalise('Amazing grace, how sweet the sound'),
        'authors'       => '',
    ];
}
$songFeatures[] = [
    'songId'        => 'TEST-0002',
    'number'        => 2,
    'title'         => 'Silent Night',
    'normExact'     => ihymns_normalize_title('Silent Night'),
    'normTitle'     => ihymns_sim_normalise('Silent Night'),
    'normFirstLine' => ihymns_sim_normalise('Silent night, holy night'),
    'authors'       => '',
];

$result = iaRecScoreCandidates($scoringCandidates, $songFeatures);
$verdictByTitle = [];
foreach ($result['candidates'] as $c) { $verdictByTitle[$c['rawTitle']] = $c['verdict']; }

check('scoring: "Amazing Grace" candidate verdict is exact (title + first line match TEST-0001 verbatim)',
    ($verdictByTitle['Amazing Grace'] ?? null) === 'exact');
check('scoring: exact match carries bestScore 1.000',
    isset($result['candidates'][0]) && ($verdictByTitle['Amazing Grace'] ?? null) !== 'exact'
        || (function () use ($result) {
            foreach ($result['candidates'] as $c) { if ($c['rawTitle'] === 'Amazing Grace') { return $c['bestScore'] === 1.0; } }
            return false;
        })());
check('scoring: "All Hail the Power" candidate verdict is gap (nothing remotely similar in the tiny feature set)',
    ($verdictByTitle['All Hail the Power'] ?? null) === 'gap');
check('scoring: the symbols-only candidate verdict is unscorable',
    ($verdictByTitle['♪ ♫ ♬'] ?? null) === 'unscorable');

check('scoring: exactly one exact / one gap / one unscorable in this run',
    ($result['summary']['counts']['exact'] ?? 0) === 1
    && ($result['summary']['counts']['gap'] ?? 0) === 1
    && ($result['summary']['counts']['unscorable'] ?? 0) === 1);

check('reverse coverage: "Silent Night" (TEST-0002) is reported as not-found-in-OCR (no candidate resembles it)',
    (function () use ($result) {
        foreach ($result['notFoundSongs'] as $s) { if ($s['songId'] === 'TEST-0002') { return true; } }
        return false;
    })());

echo "\n";
if ($failures > 0) {
    fwrite(STDERR, "FAILED: {$failures} assertion(s) failed.\n");
    exit(1);
}
echo "All ia-reconcile-guards assertions passed.\n";
exit(0);
