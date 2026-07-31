<?php

declare(strict_types=1);

/**
 * iHymns — behavioural test for the shared unit walker (#1703, #1707)
 * ===================================================================
 *
 * `tests/php/lib/php_source_units.php` had **no test of its own** while already
 * underpinning `test-song-relocate-funnels.php`, `test-song-visibility-guard.php`
 * and `test-component-json-guard.php`. That is the worst possible place for an
 * untested component: a wrong answer here is invisible in every consumer at
 * once, and each consumer reports its own green tick.
 *
 * The walker is a PURE function of its input string, so every one of these is a
 * real behavioural assertion — feed it source, read the unit list. Nothing here
 * inspects the walker's implementation, so a rewrite that preserves behaviour
 * keeps this green (rule #34's "derive from the tree, not from a list you
 * typed" applied to the walker's own contract).
 *
 * THE THREE DEFECTS THIS FILE WAS WRITTEN TO CATCH
 * ------------------------------------------------
 *  A. #1707 — a `match` expression's `default =>` arm tokenizes as `T_DEFAULT`,
 *     identical to a `switch`'s `default:`. The walker minted a phantom
 *     `case default` unit part-way through a real case, SPLITTING that case's
 *     attribution in two.
 *
 *  B. #1703 — a closure with an unqualified typed parameter
 *     (`function (int $b)`) was named `int`, because the naming rule took the
 *     next `T_STRING` after `function` wherever it appeared.
 *
 *  C. #1707 — the same rule named `function (): bool` after its RETURN type.
 *
 * B and C are one root cause: a name must sit BETWEEN `function` and the `(`.
 *
 * WHY THESE MATTERED WHEN THEY WERE "NOT CURRENTLY BITING"
 * --------------------------------------------------------
 * Both issues recorded the defects as latent, and both were right — no live
 * assertion read a unit named `int`, and where a `match` split a case, the SQL
 * and the marker vouching for it happened to land in the SAME fragment.
 *
 * "Happened to" is the whole problem. The failure is DIRECTIONAL: a vouching
 * call separated from its SQL by an intervening `match` produces a **false
 * RED** — a guard failing on correct code — which per rule #34 is precisely how
 * guards get weakened or deleted instead of fixed.
 *
 * And #1703 carries a genuinely nasty one: DECLARATION ORDER decides who wins.
 * A closure whose parameter type shares a name with a real function, declared
 * FIRST, takes that function's unit name outright. An assertion reading
 * `$units['validate']` then reads the closure's body while believing it is
 * reading `validate()`. It does not error. It does not warn. It asserts
 * confidently about the wrong code. See ORDER-REVERSED COLLISION below — that
 * case is the reason this file exists rather than a comment.
 *
 * @see tests/php/lib/php_source_units.php  the unit under test
 * @see https://www.php.net/manual/en/control-structures.match.php
 * @see https://www.php.net/manual/en/functions.arrow.php
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'php_source_units.php';

$pass = 0;
$fail = 0;

/** Assert, printing the real unit list on failure so a red run is diagnosable. */
function u_assert(string $what, bool $ok, array $units = []): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  PASS  $what\n";
        return;
    }
    $fail++;
    echo "  FAIL  $what\n";
    if ($units) {
        echo "        units seen: " . implode(', ', array_map(
            static fn(string $k): string => '"' . $k . '"',
            array_keys($units)
        )) . "\n";
    }
}

/** Every unit name the walker produced, for set comparisons. */
function u_names(array $units): array
{
    return array_keys($units);
}

/* ======================================================================
 * A — `match` arms must NOT mint units (#1707 defect 1)
 * ====================================================================== */

echo "\n== A: match arms vs switch labels ==\n";

/* A real dispatch shape: a top-level switch whose case body contains a match
   with a `default =>` arm. Before the fix this minted `case default`, splitting
   `case 'song_media_upload'` in two. */
$srcMatch = <<<'PHP'
<?php
switch ($action) {
    case 'song_media_upload':
        $kind = match ($ext) {
            'mp3', 'm4a' => 'audio',
            'pdf'        => 'sheet',
            default      => 'other',
        };
        $db->query("INSERT INTO tblSongMedia (Kind) VALUES (?)");
        break;
    case 'song_media_delete':
        $db->query("DELETE FROM tblSongMedia WHERE Id = ?");
        break;
}
PHP;

$m = phpSourceUnits($srcMatch);

u_assert(
    "a match `default =>` arm does NOT mint a `case default` unit",
    !in_array('case default', u_names($m), true),
    $m
);
u_assert(
    "the real case labels are both present",
    in_array("case 'song_media_upload'", u_names($m), true)
        && in_array("case 'song_media_delete'", u_names($m), true),
    $m
);
u_assert(
    "the match does NOT split its case — the INSERT stays with its own label",
    isset($m["case 'song_media_upload'"])
        && str_contains($m["case 'song_media_upload'"]['code'], '@SQL:INSERT:tblSongMedia@'),
    $m
);
u_assert(
    "…and the match's own arm values stayed inside that same unit",
    isset($m["case 'song_media_upload'"])
        && substr_count($m["case 'song_media_upload'"]['code'], 'match') === 1,
    $m
);

/* A genuine `switch` default must STILL mint its unit — the fix must not
   over-correct. A guard too blunt to distinguish these would be worse than the
   bug (rule #34: a guard that fails on correct code gets deleted). */
$srcSwitchDefault = <<<'PHP'
<?php
switch ($action) {
    case 'known':
        doKnown();
        break;
    default:
        $db->query("SELECT * FROM tblFallback");
        break;
}
PHP;

$sd = phpSourceUnits($srcSwitchDefault);
u_assert(
    "a REAL switch `default:` still mints its own unit",
    in_array('case default', u_names($sd), true),
    $sd
);
u_assert(
    "…and owns its own statement",
    isset($sd['case default'])
        && str_contains($sd['case default']['code'], '@SQL:SELECT:tblFallback@'),
    $sd
);

/* Both constructs in one file, match first — proves the match's arm does not
   consume the switch's later real default. */
$srcBoth = <<<'PHP'
<?php
switch ($a) {
    case 'x':
        $v = match ($b) { default => 1 };
        break;
    default:
        $db->query("SELECT * FROM tblReal");
        break;
}
PHP;

$bo = phpSourceUnits($srcBoth);
u_assert(
    "a match arm earlier in the file does not suppress a later real default",
    isset($bo['case default'])
        && str_contains($bo['case default']['code'], '@SQL:SELECT:tblReal@'),
    $bo
);

/* ======================================================================
 * B + C — closure naming (#1703, #1707 defect 2)
 * ====================================================================== */

echo "\n== B/C: closures must never take a type's name ==\n";

$srcClosures = <<<'PHP'
<?php
function realOne(int $x): int { return $x; }
$typedParam  = static function ($a, int $b) { return alpha($a, $b); };
$returnType  = static function ($a): bool { return beta($a); };
$qualified   = static function (\mysqli $db) { return gamma($db); };
$untyped     = static function ($a, $b) { return delta($a, $b); };
PHP;

$c     = phpSourceUnits($srcClosures);
$names = u_names($c);

u_assert(
    "a named function is still named after itself",
    in_array('realOne', $names, true),
    $c
);
u_assert(
    "#1703 — a closure is NOT named after its unqualified PARAMETER type ('int')",
    !in_array('int', $names, true),
    $c
);
u_assert(
    "#1707 — a closure is NOT named after its RETURN type ('bool')",
    !in_array('bool', $names, true),
    $c
);
u_assert(
    "a fully-qualified param type never leaked a name either ('mysqli')",
    !in_array('mysqli', $names, true),
    $c
);

/* All four closures must be present and DISTINCT — the fix must not collapse
   them into one unit, which would re-create the per-file vouching bug #1688
   split them up to fix. */
$closureNames = array_values(array_filter(
    $names,
    static fn(string $k): bool => str_contains($k, 'closure')
));
u_assert(
    "all four closures are present as distinct units (got " . count($closureNames) . ")",
    count($closureNames) === 4,
    $c
);
u_assert(
    "…and each kept its OWN body — no two closures merged",
    (static function (array $c, array $keys): bool {
        $bodies = [];
        foreach ($keys as $k) { $bodies[] = $c[$k]['code']; }
        foreach (['alpha', 'beta', 'gamma', 'delta'] as $needle) {
            $hits = 0;
            foreach ($bodies as $b) { if (str_contains($b, $needle)) { $hits++; } }
            if ($hits !== 1) { return false; }
        }
        return true;
    })($c, $closureNames),
    $c
);

/* ----------------------------------------------------------------------
 * THE ORDER-REVERSED COLLISION — #1703's actual harm
 *
 * A closure whose PARAMETER TYPE shares a name with a real function. With the
 * closure declared FIRST, the closure claimed the name `validate` and the real
 * function was pushed to `validate#1`. An assertion reading $units['validate']
 * then read the CLOSURE's body while believing it read validate().
 *
 * Whether it bit depended only on the order two declarations happened to appear
 * in — something nobody reviewing either declaration would think to check.
 * -------------------------------------------------------------------- */

echo "\n== the order-reversed collision (#1703's real harm) ==\n";

$srcCollision = <<<'PHP'
<?php
$cb = static function (validate $v) { return SENTINEL_CLOSURE_BODY; };
function validate($x) { return SENTINEL_REAL_FUNCTION_BODY; }
PHP;

$col = phpSourceUnits($srcCollision);

u_assert(
    "the unit named 'validate' exists",
    isset($col['validate']),
    $col
);
u_assert(
    "'validate' is the REAL FUNCTION's body, not the closure's",
    isset($col['validate'])
        && str_contains($col['validate']['code'], 'SENTINEL_REAL_FUNCTION_BODY'),
    $col
);
u_assert(
    "…and specifically does NOT contain the closure's body",
    isset($col['validate'])
        && !str_contains($col['validate']['code'], 'SENTINEL_CLOSURE_BODY'),
    $col
);

/* The same source with the declarations the other way round must give the same
   answer. That INVARIANCE is the real contract: unit identity must not depend
   on declaration order. */
$srcCollisionRev = <<<'PHP'
<?php
function validate($x) { return SENTINEL_REAL_FUNCTION_BODY; }
$cb = static function (validate $v) { return SENTINEL_CLOSURE_BODY; };
PHP;

$rev = phpSourceUnits($srcCollisionRev);
u_assert(
    "declaration ORDER does not change which body 'validate' holds",
    isset($rev['validate'])
        && str_contains($rev['validate']['code'], 'SENTINEL_REAL_FUNCTION_BODY')
        && !str_contains($rev['validate']['code'], 'SENTINEL_CLOSURE_BODY'),
    $rev
);

/* ======================================================================
 * D — regressions the fix must not introduce
 * ====================================================================== */

echo "\n== D: shapes the fix must leave alone ==\n";

$srcShapes = <<<'PHP'
<?php
abstract class Thing {
    abstract public function noBody(): string;
    public function withBody(): string { return jaguar(); }
    public static function statically(?int $n = null): void { kestrel($n); }
}
interface Contract { public function alsoNoBody(): int; }
function afterAll() { return zebra(); }
PHP;

$sh = phpSourceUnits($srcShapes);

u_assert(
    "a method with a body is named after itself, not its return type",
    isset($sh['withBody']) && str_contains($sh['withBody']['code'], 'jaguar'),
    $sh
);
u_assert(
    "a static method with a nullable typed param is named after itself",
    isset($sh['statically']) && str_contains($sh['statically']['code'], 'kestrel'),
    $sh
);
u_assert(
    "an abstract/interface declaration with NO body does not swallow what follows",
    isset($sh['afterAll']) && str_contains($sh['afterAll']['code'], 'zebra'),
    $sh
);
u_assert(
    "…and no unit was named after a return type in any of them",
    !array_intersect(['string', 'int', 'void'], u_names($sh)),
    $sh
);

/* Two same-named methods in different classes must still both survive — the
   `#N` disambiguation is load-bearing and predates this fix. */
$srcDupes = <<<'PHP'
<?php
class A { public function run(): void { alphaBody(); } }
class B { public function run(): void { betaBody(); } }
PHP;

$du    = phpSourceUnits($srcDupes);
$runs  = array_values(array_filter(u_names($du), static fn($k) => str_starts_with($k, 'run')));
u_assert(
    "two same-named methods stay as two units, not one",
    count($runs) === 2,
    $du
);
u_assert(
    "…each with its own body",
    (static function (array $du, array $runs): bool {
        $joined = '';
        foreach ($runs as $r) { $joined .= $du[$r]['code']; }
        return str_contains($joined, 'alphaBody') && str_contains($joined, 'betaBody');
    })($du, $runs),
    $du
);

/* An arrow function has no brace body; it must not leave the walker waiting for
   one and swallowing the next real function. */
$srcArrow = <<<'PHP'
<?php
$f = static fn(int $x): int => $x * 2;
function afterArrow() { return ostrich(); }
PHP;

$ar = phpSourceUnits($srcArrow);
u_assert(
    "an arrow function does not swallow the function declared after it",
    isset($ar['afterArrow']) && str_contains($ar['afterArrow']['code'], 'ostrich'),
    $ar
);

/* ======================================================================
 * E — the real tree still parses to a sane shape
 * ====================================================================== */

echo "\n== E: against real source ==\n";

$realFile = dirname(__DIR__, 2)
    . DIRECTORY_SEPARATOR . 'appWeb' . DIRECTORY_SEPARATOR . 'public_html'
    . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'song_soft_delete.php';

if (is_readable($realFile)) {
    $real  = phpSourceUnits((string)file_get_contents($realFile));
    $rn    = u_names($real);
    u_assert(
        "a real file yields its known functions",
        in_array('songSoftDelete', $rn, true) && in_array('songPurge', $rn, true),
        []
    );
    /* No unit may be named after a PHP scalar type — that is the #1703/#1707
       signature, asserted against real source rather than a fixture. */
    $typeNames = array_intersect(
        ['int', 'bool', 'string', 'float', 'void', 'array', 'mixed', 'callable', 'object'],
        $rn
    );
    u_assert(
        "no unit in real source is named after a scalar type"
            . ($typeNames ? ' (found: ' . implode(', ', $typeNames) . ')' : ''),
        $typeNames === []
    );
} else {
    echo "  SKIP  real-source check — song_soft_delete.php not readable\n";
}

/* Scan the WHOLE tree for the signature, derived rather than typed (rule #34).
   If any file anywhere mints a type-named unit, this reports it by name. */
$root    = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'appWeb';
$typeSet = ['int', 'bool', 'string', 'float', 'void', 'array', 'mixed', 'callable', 'object', 'iterable', 'never'];
$offend  = [];
$scanned = 0;

if (is_dir($root)) {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $f) {
        if (!$f->isFile() || strtolower($f->getExtension()) !== 'php') { continue; }
        $scanned++;
        $names = u_names(phpSourceUnits((string)file_get_contents($f->getPathname())));
        foreach (array_intersect($typeSet, $names) as $bad) {
            $offend[] = str_replace($root, 'appWeb', $f->getPathname()) . ' -> "' . $bad . '"';
        }
    }
}

u_assert(
    "no file in appWeb/ ($scanned scanned) mints a type-named unit"
        . ($offend ? "\n        " . implode("\n        ", array_slice($offend, 0, 10)) : ''),
    $offend === []
);

/* ---------------------------------------------------------------------- */

echo "\n";
echo $fail === 0
    ? "All php_source_units assertions passed ($pass).\n"
    : "FAILURES: $fail (passed $pass)\n";

exit($fail === 0 ? 0 : 1);
