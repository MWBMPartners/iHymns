<?php

declare(strict_types=1);

/**
 * test-php86-deprecations.php — no use of anything PHP 8.6 deprecates, anywhere in the tree
 * ========================================================================================
 *
 * PLAIN-ENGLISH SUMMARY
 * PHP 8.6 marks several functions and habits as "deprecated" (still works, but
 * will be removed). This test reads EVERY .php file under appWeb/, tests/ and
 * tools/ (the file list comes from the folders, never from a typed list) and
 * fails if any of them uses one. It uses PHP's own tokenizer, so words inside
 * comments and strings are ignored, and it also catches:
 *   - a `return` inside a `finally` block,
 *   - a session-handler class that lacks create_sid() or validateId(),
 *   - the SORT_LOCALE_STRING constant,
 *   - define() called with three arguments,
 *   - `new mysqli_stmt(...)` without a query,
 *   - method calls ->get_charset(), ->stmt_init(), ->getCsvControl(), ->setCsvControl().
 *
 * WHICH FUNCTIONS COUNT AS DEPRECATED = a fixed minimum list (from the PHP 8.6
 * UPGRADING notes) UNION every function the running PHP itself reports as
 * deprecated (ReflectionFunction::isDeprecated()). So when a newer PHP
 * deprecates something else, this test finds it with no edit. On PHP 8.6+ every
 * name in the fixed list is also checked to really be deprecated (a stale entry
 * fails); below 8.6 that part prints an explicit SKIP.
 *
 * ALLOW-LIST WITH EXACT COUNTS: third-party code we cannot edit
 * (appWeb/private_html/lib/, the vendored PDF library) is allowed exactly the
 * uses listed in $ALLOWED, and our one deliberate call of the real metaphone()
 * (in includes/phonetic.php). A count that DROPS fails as "stale entry", so the
 * list cleans itself up when the vendor is updated.
 *
 * KNOWN GAPS (stated, rule #34): a function named only inside a string
 * (array_map('is_double', …)) is not seen; neither is code built at run time.
 *
 * SELF-TEST: before scanning the tree, the scanner is run on small in-memory
 * samples and must find every banned form and ignore comments, strings and a
 * method declaration that merely shares a name.
 *
 *   php tests/php/test-php86-deprecations.php
 *
 * @see .claude/php86-readiness-plan.md  section D
 * @see appWeb/public_html/includes/phonetic.php
 * @see https://www.php.net/manual/en/function.token-get-all.php
 * @see tests/php/test-fragment-inline-scripts.php  the same exact-count allow-list idea
 */

$root = dirname(__DIR__, 2);
$passed = 0;
$failed = 0;
$check = static function (string $label, bool $ok, string $detail = '') use (&$passed, &$failed): void {
    if ($ok) { $passed++; echo "  PASS  {$label}\n"; }
    else     { $failed++; echo "  FAIL  {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; }
};
echo "tests/php/test-php86-deprecations.php — nothing PHP 8.6 deprecates is used\n";

/* ---- The minimum list of deprecated functions (PHP 8.6 UPGRADING) ---- */
$FIXED = [
    'metaphone',
    'mb_regex_encoding', 'mb_regex_set_options', 'mb_split', 'mb_ereg', 'mb_eregi',
    'mb_ereg_replace', 'mb_eregi_replace', 'mb_ereg_replace_callback', 'mb_ereg_match',
    'mb_ereg_search', 'mb_ereg_search_pos', 'mb_ereg_search_regs', 'mb_ereg_search_init',
    'mb_ereg_search_getregs', 'mb_ereg_search_getpos', 'mb_ereg_search_setpos',
    'mb_convert_variables',
    'mysqli_get_charset', 'mysqli_stmt_init',
    'spl_object_hash', 'spl_classes',
    'is_double', 'is_long', 'is_integer', 'doubleval', 'strcoll',
];
$BANNED_METHODS = ['get_charset', 'stmt_init', 'getcsvcontrol', 'setcsvcontrol'];

/* ---- Plus everything this PHP itself says is deprecated ---- */
$deprecated = array_fill_keys($FIXED, true);
foreach (get_defined_functions()['internal'] as $fn) {
    try {
        if ((new ReflectionFunction($fn))->isDeprecated()) { $deprecated[strtolower($fn)] = true; }
    } catch (\Throwable $e) { /* a function we cannot inspect is simply not added */ }
}

/**
 * Find banned uses in one file's source.
 * @return list<array{0:string,1:int}> [tag, line] pairs, e.g. ['fn:metaphone', 291]
 */
$scan = static function (string $code, array $deprecated) use ($BANNED_METHODS): array {
    $all = @token_get_all($code);
    /* Drop whitespace and comments (so words in them are never seen), keep lines. */
    $t = [];
    foreach ($all as $tok) {
        if (is_array($tok)) {
            if (in_array($tok[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML], true)) { continue; }
            $t[] = [$tok[0], $tok[1], $tok[2]];
        } else {
            $t[] = [null, $tok, 0];
        }
    }
    /* Give every symbol token the line of the token before it. */
    for ($i = 0, $line = 1, $n = count($t); $i < $n; $i++) {
        if ($t[$i][2] === 0) { $t[$i][2] = $line; } else { $line = $t[$i][2]; }
    }
    $isText = static fn(array $x, string $s): bool => $x[0] === null && $x[1] === $s;
    $nameOf = static function (array $x): ?string {
        if ($x[0] === T_STRING) { return strtolower($x[1]); }
        if ($x[0] === T_NAME_FULLY_QUALIFIED) { return strtolower(ltrim($x[1], '\\')); }
        return null;
    };
    /* Number of top-level arguments of the call whose "(" is at $open. */
    $argCount = static function (int $open) use ($t, $n, $isText): int {
        $depth = 0; $commas = 0; $any = false;
        for ($j = $open; $j < $n; $j++) {
            $x = $t[$j];
            if ($x[0] === null && in_array($x[1], ['(', '[', '{'], true)) { $depth++; if ($depth > 1) { $any = true; } continue; }
            if ($x[0] === null && in_array($x[1], [')', ']', '}'], true)) { $depth--; if ($depth === 0) { break; } continue; }
            if ($depth === 1 && $isText($x, ',')) { $commas++; continue; }
            if ($depth >= 1) { $any = true; }
        }
        return $any ? $commas + 1 : 0;
    };

    $found = [];
    $stack = [];            /* open "{" frames: 'finally' | 'fn' (function/class barrier) | 'other' */
    $pending = null;        /* the kind the NEXT "{" will get */
    $classes = [];          /* open class bodies: [depthAtOpen, implementsSessionHandler, names seen] */
    $pendingClass = null;   /* [implementsSessionHandler] waiting for its "{" */

    for ($i = 0; $i < $n; $i++) {
        [$id, $text, $ln] = $t[$i];
        $prev = $i > 0 ? $t[$i - 1] : [null, '', 0];
        if ($prev[0] === null && $prev[1] === '&' && $i > 1) { $prev = $t[$i - 2]; }   /* function &name() */
        $next = $t[$i + 1] ?? [null, '', 0];

        /* --- brace bookkeeping --- */
        if ($id === T_FINALLY) { $pending = 'finally'; continue; }
        if ($id === T_FUNCTION) { $pending = 'fn'; }
        if ($id === T_CLASS && !($prev[0] === T_DOUBLE_COLON)) {
            $pending = 'fn';
            /* Look ahead to the "{" for an `implements …SessionHandlerInterface`. */
            $impl = false; $inImpl = false;
            for ($j = $i + 1; $j < $n && !$isText($t[$j], '{') && !$isText($t[$j], ';'); $j++) {
                if ($t[$j][0] === T_IMPLEMENTS) { $inImpl = true; }
                elseif ($inImpl && in_array($t[$j][0], [T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED], true)
                    && strcasecmp(basename(str_replace('\\', '/', $t[$j][1])), 'SessionHandlerInterface') === 0) { $impl = true; }
            }
            $pendingClass = [$impl];
        }
        if ($isText($t[$i], ';')) { $pending = null; $pendingClass = null; }
        if ($id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) { $stack[] = 'other'; continue; }
        if ($isText($t[$i], '{')) {
            $stack[] = $pending ?? 'other';
            if ($pendingClass !== null) { $classes[] = [count($stack), $pendingClass[0], []]; $pendingClass = null; }
            $pending = null;
            continue;
        }
        if ($isText($t[$i], '}')) {
            if ($classes !== [] && end($classes)[0] === count($stack)) {
                [, $impl, $names] = array_pop($classes);
                if ($impl) {
                    foreach (['create_sid', 'validateid'] as $need) {
                        if (!isset($names[$need])) { $found[] = ['session-handler-missing:' . $need, $ln]; }
                    }
                }
            }
            array_pop($stack);
            continue;
        }
        /* methods declared in the current class body */
        if ($id === T_FUNCTION && $classes !== [] && ($nm = $nameOf($next)) !== null && count($stack) === end($classes)[0]) {
            $classes[count($classes) - 1][2][$nm] = true;
        }

        /* --- return inside finally --- */
        if ($id === T_RETURN) {
            for ($k = count($stack) - 1; $k >= 0; $k--) {
                if ($stack[$k] === 'fn') { break; }
                if ($stack[$k] === 'finally') { $found[] = ['return-in-finally', $ln]; break; }
            }
        }

        /* --- names --- */
        $nm = $nameOf($t[$i]);
        if ($nm === 'sort_locale_string') { $found[] = ['const:SORT_LOCALE_STRING', $ln]; }
        $isMemberAccess = $prev[0] === T_OBJECT_OPERATOR || $prev[0] === T_NULLSAFE_OBJECT_OPERATOR;
        if ($nm !== null && $isMemberAccess && $isText($next, '(') && in_array($nm, $BANNED_METHODS, true)) {
            $found[] = ['method:' . $nm, $ln];
        }
        $isCallable = $nm !== null && $isText($next, '(')
            && !$isMemberAccess
            && !in_array($prev[0], [T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_CONST, T_NAMESPACE, T_USE], true);
        if ($isCallable && isset($deprecated[$nm])) { $found[] = ['fn:' . $nm, $ln]; }
        if ($isCallable && $nm === 'define' && $argCount($i + 1) >= 3) { $found[] = ['define-3-args', $ln]; }
        if ($nm === 'mysqli_stmt' && $prev[0] === T_NEW) {
            if (!$isText($next, '(') || $argCount($i + 1) < 2) { $found[] = ['new-mysqli_stmt-no-query', $ln]; }
        }
    }
    return $found;
};

/* ---- SELF-TEST: the scanner must find every bad form and ignore look-alikes ---- */
$tags = static fn(string $code): array => array_values(array_unique(array_column($scan("<?php\n" . $code, $deprecated), 0)));
$must = [
    'native call'            => ['metaphone("x");', 'fn:metaphone'],
    'fully qualified call'   => ['$a = \\metaphone("x");', 'fn:metaphone'],
    'mbregex call'           => ['mb_regex_encoding();', 'fn:mb_regex_encoding'],
    'return in finally'      => ['function f() { try { a(); } finally { return 1; } }', 'return-in-finally'],
    'return in nested if'    => ['function f() { try { a(); } finally { if ($x) { return 1; } } }', 'return-in-finally'],
    'handler lacks sid'      => ['class H implements SessionHandlerInterface { function validateId(string $i): bool { return true; } }', 'session-handler-missing:create_sid'],
    'handler lacks validate' => ['class H implements \\SessionHandlerInterface { function create_sid(): string { return "a"; } }', 'session-handler-missing:validateid'],
    'anonymous handler'      => ['$h = new class implements \\SessionHandlerInterface { };', 'session-handler-missing:create_sid'],
    'sort constant'          => ['sort($a, SORT_LOCALE_STRING);', 'const:SORT_LOCALE_STRING'],
    'define 3 args'          => ['define("A", 1, true);', 'define-3-args'],
    'mysqli_stmt no query'   => ['$s = new mysqli_stmt($db);', 'new-mysqli_stmt-no-query'],
    'get_charset method'     => ['$db->get_charset();', 'method:get_charset'],
    'stmt_init method'       => ['$r = $db?->stmt_init();', 'method:stmt_init'],
];
foreach ($must as $label => [$code, $tag]) {
    $check("self-test finds: {$label}", in_array($tag, $tags($code), true), 'got ' . json_encode($tags($code)));
}
$mustNot = [
    'a comment'              => '// metaphone("x"); and return in finally',
    'a block comment'        => '/* mb_regex_encoding(); */',
    'a string'               => '$s = "metaphone(\'x\')"; $t = \'define("A",1,true)\';',
    'a method declaration'   => 'class A { public function metaphone(string $w): string { return $w; } }',
    'a function declaration' => 'function metaphone($w) { return $w; }',
    'a user method call'     => '$this->metaphone("x"); Foo::metaphone("x");',
    'return in a closure inside finally' => 'function f() { try { a(); } finally { $g = function () { return 1; }; } }',
    'return in try'          => 'function f() { try { return 1; } finally { cleanup(); } }',
    'define 2 args'          => 'define("A", 1);',
    'complete handler'       => 'class H implements SessionHandlerInterface { function create_sid(): string { return "a"; } function validateId(string $i): bool { return true; } }',
    'mysqli_stmt with query' => '$s = new mysqli_stmt($db, "SELECT 1");',
];
foreach ($mustNot as $label => $code) {
    $check("self-test ignores: {$label}", $tags($code) === [], 'got ' . json_encode($tags($code)));
}

/* ---- Stale-entry check for the fixed list (8.6+ only) ---- */
if (PHP_VERSION_ID >= 80600) {
    foreach ($FIXED as $fn) {
        if (!function_exists($fn)) { continue; }   /* e.g. mbregex not built in */
        $check("fixed-list entry is really deprecated: {$fn}", (new ReflectionFunction($fn))->isDeprecated());
    }
} else {
    echo "  SKIP  fixed-list stale-entry check — needs PHP 8.6+ (this is " . PHP_VERSION . "); the self-test and tree scan above still ran.\n";
}

/* ---- The allow-list: path (from repo root) => [tag => exact count] ---- */
$ALLOWED = [
    /* Third-party PDF engine (mPDF 8.3.1 / FPDI 2.6.8): mb_regex_encoding is covered by the
       stand-in in includes/pdf_renderer.php; strcoll and spl_object_hash sit on paths iHymns
       never runs (mPDF indexes, FPDI page import). Re-vendor once upstream supports 8.6. */
    'appWeb/private_html/lib/pdf/vendor/mpdf/mpdf/src/Mpdf.php' => ['fn:mb_regex_encoding' => 3, 'fn:strcoll' => 1],
    'appWeb/private_html/lib/pdf/vendor/setasign/fpdi/src/FpdiTrait.php' => ['fn:spl_object_hash' => 1],
    /* The parity test deliberately calls the real function (below 8.6 only), through one helper. */
    'tests/php/test-metaphone-parity.php' => ['fn:metaphone' => 1],
    'appWeb/public_html/includes/phonetic.php' => ['fn:metaphone' => 1],
];

/* ---- The tree scan: every .php under appWeb/, tests/, tools/ ---- */
$files = [];
foreach (['appWeb', 'tests', 'tools'] as $top) {
    if (!is_dir("{$root}/{$top}")) { continue; }
    $it = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator("{$root}/{$top}", FilesystemIterator::SKIP_DOTS),
        static fn(SplFileInfo $f): bool => !in_array($f->getFilename(), ['node_modules', '.git'], true)
    ));
    foreach ($it as $f) {
        if ($f->isFile() && strtolower($f->getExtension()) === 'php') { $files[] = $f->getPathname(); }
    }
}
sort($files);
$check('the tree scan found a believable number of PHP files', count($files) > 500, count($files) . ' found');

$seen = [];        /* relpath => [tag => count] */
$firstLine = [];   /* "rel|tag" => first line, for the message */
foreach ($files as $path) {
    $rel  = str_replace('\\', '/', substr($path, strlen($root) + 1));
    $code = (string)file_get_contents($path);
    foreach ($scan($code, $deprecated) as [$tag, $ln]) {
        $seen[$rel][$tag] = ($seen[$rel][$tag] ?? 0) + 1;
        $firstLine["{$rel}|{$tag}"] ??= $ln;
    }
}

/* Anything found that the allow-list does not cover (or covers with a smaller count). */
$unexpected = [];
foreach ($seen as $rel => $tags_) {
    foreach ($tags_ as $tag => $count) {
        $allowed = $ALLOWED[$rel][$tag] ?? 0;
        if ($count > $allowed) { $unexpected[] = "{$rel}:" . $firstLine["{$rel}|{$tag}"] . " {$tag} ×{$count} (allowed {$allowed})"; }
    }
}
$check('no use of anything PHP 8.6 deprecates outside the allow-list', $unexpected === [], "\n        " . implode("\n        ", $unexpected));

/* Allow-list entries that are no longer needed or whose count dropped = stale. */
$stale = [];
foreach ($ALLOWED as $rel => $tags_) {
    foreach ($tags_ as $tag => $count) {
        $have = $seen[$rel][$tag] ?? 0;
        if ($have !== $count) { $stale[] = "{$rel} {$tag}: list says {$count}, found {$have}"; }
    }
}
$check('every allow-list entry still matches exactly (none stale)', $stale === [], "\n        " . implode("\n        ", $stale));

/* The one deliberate native call must stay behind the version check. */
$ph = (string)@file_get_contents("{$root}/appWeb/public_html/includes/phonetic.php");
$check('phonetic.php keeps its PHP_VERSION_ID >= 80600 switch', str_contains($ph, 'PHP_VERSION_ID') && str_contains($ph, '80600'));

echo "\n  {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
