<?php

declare(strict_types=1);

/**
 * iHymns — no catch block inside a save's transaction may swallow an error
 * that has already ended it (#2137 review round 8)
 * ==========================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * Some database errors throw away a whole transaction: a deadlock (1213), a
 * lock wait timeout (1205, on servers set to roll back on one) and MariaDB's
 * "record has changed since last read" (1020). If any code that runs inside
 * the transaction catches one of those, writes a log line and carries on, the
 * rest of the save runs on its own, outside any transaction, and the editor is
 * told "saved" over a half-saved song. So every catch block that can run
 * inside one of the transactions listed below must START by handing those
 * errors back:
 *
 *     } catch (\Throwable $e) {
 *         if (songRelocateIsTransactionFatal($e)) { throw $e; }
 *         ...
 *
 * This file reads the code with PHP's own tokenizer, works out every catch
 * block that can run inside those transactions, and FAILS if one of them does
 * not start that way — unless it is on the short allow-list below, where each
 * entry says, by hand, why that catch is safe.
 *
 * WHICH TRANSACTIONS
 * ------------------
 * The ones opened by the song save (`editorSaveSongCore()`), the v2 editor
 * (`manage/editor/api2.php`), the works admin page (`manage/works.php`), the
 * songbooks admin page (`manage/songbooks.php`) and the importers
 * (`includes/song_importers.php`, `includes/lyrics_ingest.php`) — every
 * `begin_transaction()` in those files, and every one in a function that code
 * in those files can call, however indirectly (so `songSoftDelete()`,
 * `workAutolinkSafe()` and the rest are covered without being named).
 *
 * HOW "CAN RUN INSIDE" IS WORKED OUT
 * ----------------------------------
 * A transaction runs from its `begin_transaction()` to the last `commit()`
 * after it in the same block (stopping at the next `begin_transaction()` in
 * that block); with no `commit()` there, to the end of the block. Every catch
 * written inside that stretch counts. So does every catch in anything the
 * stretch can call, followed as far as it goes:
 *   - a function, by its name;
 *   - a method, by its method name, in EVERY class that has one (this
 *     over-counts rather than misses);
 *   - `new Something` — that class's constructor;
 *   - a function or method named in a string ('workPersistExtraFields',
 *     [$this, 'save'], 'Class::method') — a string that is the name of a
 *     function defined in this code base counts as a call to it;
 *   - a closure (`function () use (...) {}`) or an arrow function
 *     (`fn () => ...`) written in a reachable piece of code;
 *   - `$name(...)` — every closure or arrow function assigned to `$name` in
 *     the same file;
 *   - `require` / `include` — the included file's top-level code.
 * These are the widenings the seventh independent review's own audit made over
 * round 7's (round 7 followed names only and placed one closure by hand); they
 * are kept here so that this test sees at least what that audit saw.
 *
 * WHAT THIS CANNOT SEE
 * --------------------
 * A call it cannot resolve from the text alone: a function whose name is built
 * at run time ("save" . $kind), a callable held in an array or an object
 * property, or a closure passed in from another file. A transaction opened in
 * a file that none of the files above can reach is not checked. It does not
 * judge whether an allow-listed catch's reason is still true — that is what
 * the reason is written down for. It proves the shape of the code; the
 * all-or-nothing behaviour itself is proven by running the save, in
 * test-song-save-whole-rollback.php.
 *
 * HOW IT WAS PROVEN TO WORK (#2137 review round 8)
 * ------------------------------------------------
 * The seventh review removed eight of these guards one at a time
 * (`slideAuthTokenExpiry()`, `publisherResolvePickedOrCreate()`,
 * `ed2_touchRevision()`, `workMedleyReplace()`, `songRedirectsTableReady()`,
 * `generateUniqueMusicianSlug()`, `adoptApiTokenSession()`'s sliding expiry,
 * `pickAutoSongbookColour()`) and no test noticed. Each of the eight turns this
 * file red, and so does a new catch with no guard added to a helper the save
 * calls. (Since round 9 `slideAuthTokenExpiry()`'s catch has no guard and is
 * on the allow-list instead: it now writes only when no transaction is open.)
 *
 *   php tests/php/test-transaction-catch-audit.php
 *
 * No database is needed. Exit status 0 = all pass, 1 = a failure.
 *
 * @see appWeb/public_html/includes/transaction_fatal.php  songRelocateIsTransactionFatal()
 * @see DEV_NOTES.md  "Every catch a save can reach from inside its transaction"
 * @see https://www.php.net/manual/en/class.phptoken.php
 */

$repoRoot = dirname(__DIR__, 2);
$passed = 0;
$failed = 0;
$check = static function (string $label, bool $ok, string $detail = '') use (&$passed, &$failed): void {
    if ($ok) { $passed++; echo "  PASS  {$label}\n"; }
    else     { $failed++; echo "  FAIL  {$label}" . ($detail !== '' ? "\n        {$detail}" : '') . "\n"; }
};
echo "tests/php/test-transaction-catch-audit.php — every catch inside a save's transaction passes back an error that has ended it\n";

/* Reading every PHP file's tokens takes more memory than PHP's usual 128 MB
   default on some machines; each file's tokens are thrown away as soon as it
   has been read, but give the run some room rather than fail for a reason that
   has nothing to do with the code being checked. */
$limit = (string)ini_get('memory_limit');
if ($limit !== '-1') {
    $bytes = (int)$limit * match (strtoupper(substr($limit, -1))) { 'G' => 1 << 30, 'M' => 1 << 20, 'K' => 1 << 10, default => 1 };
    if ($bytes < (512 << 20)) { ini_set('memory_limit', '512M'); }
}

/* ------------------------------------------------------------------ what is audited */

/** The files whose transactions are audited (see "WHICH TRANSACTIONS" above). */
const TCA_ENTRY_FILES = [
    'appWeb/public_html/manage/editor/save_song_core.php',
    'appWeb/public_html/manage/editor/api2.php',
    'appWeb/public_html/manage/works.php',
    'appWeb/public_html/manage/songbooks.php',
    'appWeb/public_html/includes/song_importers.php',
    'appWeb/public_html/includes/lyrics_ingest.php',
];

/**
 * The only exception types that can be (or carry, as their cause) a database
 * error. `mysqli_sql_exception` is a final class extending RuntimeException,
 * so catching any of these four can catch one. A catch of anything else is
 * still checked (it needs the guard or an allow-list entry): an app could
 * wrap a database error in, say, an InvalidArgumentException, and the shared
 * check looks down the whole chain of causes.
 */
const TCA_DB_CATCHERS = ['throwable', 'exception', 'runtimeexception', 'mysqli_sql_exception'];

/**
 * THE ALLOW-LIST — catches that may run inside an audited transaction and do
 * NOT start with the guard, each checked by hand. An entry names the file, the
 * function (or `Class::method`, or `{top}` for a file's top-level code, or
 * `{closure in NAME}`), and which catch it is in that function counting from
 * the top (1 = the first). An entry that no longer matches a catch this test
 * can reach FAILS the test too, so the list cannot quietly go stale: a moved or
 * rewritten catch has to be looked at again.
 *
 * Keep it short. A catch that simply logs and carries on belongs to the rule,
 * not here: give it the guard.
 */
const TCA_ALLOWED = [
    /* Pass the error back already, in their own way. */
    ['appWeb/public_html/includes/work_admin.php', 'workFindOrLinkByIdentifier', 1,
        'Re-throws every database error except a duplicate key (1062), which it answers by finding the row that '
        . 'won the race. A duplicate key fails one statement; it never ends a transaction.'],
    ['appWeb/public_html/includes/musician_helpers.php', 'musicianReapOrphanedAutoRow', 1,
        'The catch around each reference probe in its own transaction: re-throws every error except a missing '
        . 'table (1146) on a server that has not run that migration. A missing table fails one statement; it '
        . 'never ends a transaction.'],
    ['appWeb/public_html/includes/song_translations_sync.php', 'songTranslationsSaveLinksAllOrNothing', 3,
        'The catch around a failed undo of the translation links: it ALWAYS throws, carrying the original error '
        . 'as its cause, so songRelocateIsTransactionFatal() still finds a 1213/1205/1020 down the chain.'],

    /* Pass it back only when a transaction was open — decided before the write (#2137 review round 8). */
    ['appWeb/public_html/includes/activity_log.php', 'logActivity', 3,
        'Its main catch starts `if (songRelocateIsTransactionFatal($e) && $transactionOpen !== false) { throw $e; }`: '
        . 'it passes such an error back whenever a transaction was open (or it could not tell) when the call began '
        . '— asked with dbTransactionIsOpen() BEFORE the first statement, because afterwards the server has already '
        . 'ended the transaction. Outside a transaction (a log row written after the work committed) such an error '
        . 'has ended nothing but that row, so it logs and carries on (the lead\'s decision 3). Proven against a real '
        . 'database by test-activity-log-outside-transaction.php (both sides) and test-song-save-whole-rollback.php (B4).'],

    /* Write only when no transaction is open: they return before their try whenever dbTransactionIsOpen()
       does not answer false (a transaction is open, or that cannot be told), so nothing their catch walks past
       can have ended a transaction (#2137 review round 9, the lead's decision 1). */
    ['appWeb/public_html/includes/ip_geolocation.php', 'ihymnsGeoCachePut', 1,
        'The geo-cache write runs only outside a transaction (the early return above its try), so a deadlock or a '
        . 'lock wait timeout on it has ended nothing but that one cache row: it logs every error and carries on. '
        . 'Passing such an error back cost logActivity() its activity row and failed the admin geolocate request. '
        . 'Proven by test-activity-log-outside-transaction.php (C3, C4, C5).'],
    ['appWeb/public_html/api.php', 'slideAuthTokenExpiry', 1,
        'The sign-in token\'s sliding expiry runs only outside a transaction (the early return above its try), so a '
        . 'deadlock or a lock wait timeout on it has ended nothing but that one token row: it logs every error and '
        . 'carries on. Passing such an error back made getAuthenticatedUser() throw and cost logActivity() its '
        . 'activity row. Proven by test-activity-log-outside-transaction.php (Part D).'],

    /* Handle a transaction of their own, opened only after the caller's own work has committed. */
    ['appWeb/public_html/includes/work_admin.php', 'workAutolinkSafe', 1,
        'Its own-transaction mode ($ownTransaction = true): rolls back ITS OWN transaction, opened just above, '
        . 'and answers null. Every caller using that mode calls it after its own write has committed '
        . '(api2.php: duplicate_song, the identifier field save, revision restore). Inside a caller\'s '
        . 'transaction it is called with false and takes its third catch, which has the guard.'],
    ['appWeb/public_html/includes/work_admin.php', 'workAutolinkSafe', 2,
        'The catch around that own-transaction rollback: a rollback that fails leaves nothing more to undo.'],
    ['appWeb/public_html/includes/song_copyright_holders.php', 'songCopyrightHoldersReplace', 1,
        'Rolls back its OWN transaction and answers write_failed when it opened that transaction; when it is '
        . 'running inside the caller\'s transaction it re-throws everything.'],

    /* Run by PHP after the request's own code has finished, or as it dies. They are written in code a
       transaction can reach (auth.php's top level installs them), but PHP calls them only at the end of the
       script (register_shutdown_function) or when an exception has escaped everything (set_exception_handler)
       — no save code runs after them, so swallowing there cannot let a save carry on. Giving them the guard
       would instead turn a failed end-of-request log row into an error after the response. */
    ['appWeb/public_html/includes/activity_log.php', '{closure 1 in installRequestActivityLogger}', 1,
        'The end-of-request "request.*" row, written by a shutdown function.'],
    ['appWeb/public_html/includes/activity_log.php', '{closure 2 in installGlobalActivityLogHandlers}', 1,
        'The uncaught-exception handler\'s own log row.'],
    ['appWeb/public_html/includes/activity_log.php', '{closure 2 in installGlobalActivityLogHandlers}', 2,
        'The uncaught-exception handler handing the error on to the handler that was there before it.'],
    ['appWeb/public_html/includes/activity_log.php', '{closure 3 in installGlobalActivityLogHandlers}', 1,
        'The "fatal.php_error" row, written by a shutdown function after a PHP fatal error.'],
];

/* ------------------------------------------------------------------ reading the code */

/** The next token that is not whitespace or a comment, in direction $dir; -1 if none. */
function tcaSig(array $t, int $i, int $dir = 1): int
{
    $n = count($t);
    for ($i += $dir; $i >= 0 && $i < $n; $i += $dir) {
        if (!in_array($t[$i]->id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            return $i;
        }
    }
    return -1;
}

/** Does this token open a `{ }` block (including `{$x}` and `${x}` inside strings)? */
function tcaOpens(PhpToken $tok): bool
{
    return $tok->text === '{' || $tok->id === T_CURLY_OPEN || $tok->id === T_DOLLAR_OPEN_CURLY_BRACES;
}

/** A name without its namespace and in lower case (PHP function and class names ignore case). */
function tcaShort(string $name): string
{
    $name = strtolower(ltrim($name, '\\'));
    $p = strrpos($name, '\\');
    return $p === false ? $name : substr($name, $p + 1);
}

/** Is token $i a call of the method $name on an object — `->name(` or `?->name(` (any case)? */
function tcaMethodCall(array $t, int $i, string $name): bool
{
    if ($t[$i]->id !== T_STRING || strtolower($t[$i]->text) !== $name) { return false; }
    $p = tcaSig($t, $i, -1);
    $q = tcaSig($t, $i);
    return $p >= 0 && in_array($t[$p]->id, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)
        && $q >= 0 && $t[$q]->text === '(';
}

/** Is token $i a call of the plain function $name — `name(` or `\name(`, not a method or a definition? */
function tcaFunctionCall(array $t, int $i, string $name): bool
{
    if (!in_array($t[$i]->id, [T_STRING, T_NAME_FULLY_QUALIFIED], true) || tcaShort($t[$i]->text) !== $name) { return false; }
    $p = tcaSig($t, $i, -1);
    $q = tcaSig($t, $i);
    return $q >= 0 && $t[$q]->text === '('
        && !($p >= 0 && in_array($t[$p]->id, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_CONST], true));
}

/**
 * The repository path an include statement names, when it is built only from
 * __DIR__, __FILE__, dirname(…[, levels]), DIRECTORY_SEPARATOR and quoted text
 * joined with `.`; null when it uses anything else (a variable, a call).
 *
 * @param list<PhpToken> $parts the statement's tokens after the keyword, without whitespace
 */
function tcaIncludePath(array $parts, string $dir): ?string
{
    $pos = 0;
    $term = static function () use (&$term, &$pos, $parts, $dir): ?string {
        $tok = $parts[$pos] ?? null;
        if ($tok === null) { return null; }
        if ($tok->text === '(') {
            $pos++;
            /* a bracketed path: ( a . b ) */
            $acc = '';
            while (true) {
                $v = $term();
                if ($v === null) { return null; }
                $acc .= $v;
                if (($parts[$pos] ?? null)?->text === '.') { $pos++; continue; }
                break;
            }
            if (($parts[$pos] ?? null)?->text !== ')') { return null; }
            $pos++;
            return $acc;
        }
        if ($tok->id === T_DIR) { $pos++; return $dir; }
        if ($tok->id === T_FILE) { $pos++; return $dir . '/{file}'; }
        if ($tok->id === T_CONSTANT_ENCAPSED_STRING) { $pos++; return str_replace('\\', '/', substr($tok->text, 1, -1)); }
        if ($tok->id === T_STRING && $tok->text === 'DIRECTORY_SEPARATOR') { $pos++; return '/'; }
        if ($tok->id === T_STRING && strtolower($tok->text) === 'dirname' && ($parts[$pos + 1] ?? null)?->text === '(') {
            $pos += 2;
            $inner = $term();
            if ($inner === null) { return null; }
            $levels = 1;
            if (($parts[$pos] ?? null)?->text === ',') {
                $lv = $parts[$pos + 1] ?? null;
                if ($lv === null || $lv->id !== T_LNUMBER) { return null; }
                $levels = (int)$lv->text;
                $pos += 2;
            }
            if (($parts[$pos] ?? null)?->text !== ')') { return null; }
            $pos++;
            for ($k = 0; $k < $levels; $k++) { $inner = dirname($inner); }
            return $inner;
        }
        return null;
    };
    $acc = '';
    while (true) {
        $v = $term();
        if ($v === null) { return null; }
        $acc .= $v;
        if (($parts[$pos] ?? null)?->text === '.') { $pos++; continue; }
        break;
    }
    if ($pos !== count($parts) && ($parts[$pos] ?? null)?->text !== ')') { return null; }
    /* Tidy `a/./b`, `a//b` and `a/x/../b`. */
    $out = [];
    foreach (explode('/', $acc) as $seg) {
        if ($seg === '' || $seg === '.') { continue; }
        if ($seg === '..') { array_pop($out); continue; }
        $out[] = $seg;
    }
    return implode('/', $out);
}

/**
 * What token $i calls, if anything, as an edge key:
 *   f:name, m:name, ctor:class, s:string (a string that may name a function),
 *   inc:file.php, var:FILE|$name, node:ID (a closure or arrow function written here).
 *
 * @param array<int,string> $fnAt  token index of a `function`/`fn` keyword => the node it starts
 */
function tcaEdgeAt(array $t, int $i, string $rel, ?string $class, array $fnAt): ?string
{
    $tok = $t[$i];
    switch ($tok->id) {
        case T_STRING:
        case T_NAME_QUALIFIED:
        case T_NAME_FULLY_QUALIFIED:
        case T_STATIC:
            $p = tcaSig($t, $i, -1);
            $pt = $p >= 0 ? $t[$p] : null;
            if ($pt !== null && $pt->id === T_NEW) {
                $c = tcaShort($tok->text);
                if (in_array($c, ['self', 'static', 'parent'], true)) { $c = $class ?? ''; }
                return $c !== '' ? 'ctor:' . $c : null;
            }
            if ($tok->id === T_STATIC) { return null; }
            $q = tcaSig($t, $i);
            if ($q < 0 || $t[$q]->text !== '(') { return null; }
            if ($pt !== null && in_array($pt->id, [T_FUNCTION, T_CONST], true)) { return null; }
            if ($pt !== null && $pt->text === '&') {
                $pp = tcaSig($t, $p, -1);
                if ($pp >= 0 && $t[$pp]->id === T_FUNCTION) { return null; }
            }
            $isMethod = $pt !== null && in_array($pt->id, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true);
            return ($isMethod ? 'm:' : 'f:') . tcaShort($tok->text);
        case T_CONSTANT_ENCAPSED_STRING:
            /* A string handed to function_exists() and the like only asks whether
               something exists; it calls nothing. */
            $p = tcaSig($t, $i, -1);
            $pp = $p >= 0 && $t[$p]->text === '(' ? tcaSig($t, $p, -1) : -1;
            if ($pp >= 0 && in_array(strtolower($t[$pp]->text), ['function_exists', 'method_exists', 'class_exists', 'interface_exists', 'defined'], true)) {
                return null;
            }
            $s = strtolower(trim(substr($tok->text, 1, -1)));
            $pos = strrpos($s, '::');
            if ($pos !== false) { $s = substr($s, $pos + 2); }
            return preg_match('/^[a-z_][a-z0-9_]*$/', $s) === 1 ? 's:' . $s : null;
        case T_REQUIRE:
        case T_REQUIRE_ONCE:
        case T_INCLUDE:
        case T_INCLUDE_ONCE:
            /* The included file: worked out from the path when it is built from
               __DIR__, dirname(), DIRECTORY_SEPARATOR and quoted text (as nearly
               every include here is); otherwise every file with that name. */
            $parts = [];
            for ($j = $i + 1, $n = count($t); $j < $n && $t[$j]->text !== ';'; $j++) {
                if (!in_array($t[$j]->id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) { $parts[] = $t[$j]; }
            }
            $path = tcaIncludePath($parts, dirname($rel));
            if ($path !== null) { return 'inc:' . $path; }
            $lit = null;
            foreach ($parts as $pt) {
                if ($pt->id === T_CONSTANT_ENCAPSED_STRING && preg_match('/([A-Za-z0-9_.\-]+\.php)/', $pt->text, $m) === 1) {
                    $lit = strtolower($m[1]);
                }
            }
            return $lit !== null ? 'incb:' . $lit : null;
        case T_VARIABLE:
            $q = tcaSig($t, $i);
            $p = tcaSig($t, $i, -1);
            if ($q >= 0 && $t[$q]->text === '('
                && !($p >= 0 && in_array($t[$p]->id, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW], true))) {
                return 'var:' . $rel . '|' . $tok->text;
            }
            return null;
        case T_FUNCTION:
        case T_FN:
            return isset($fnAt[$i]) ? 'node:' . $fnAt[$i] : null;
    }
    return null;
}

/**
 * Read one file: its functions, methods, closures and arrow functions (each a
 * "node" with the calls written directly in it), its catch blocks, and every
 * transaction it opens (with what is written inside that transaction).
 */
function tcaScanFile(string $rel, string $src): array
{
    $t = PhpToken::tokenize($src);
    $n = count($t);

    /* Matching braces, and for each `{` the `{` that encloses it. */
    $match = [];
    $stack = [];
    for ($i = 0; $i < $n; $i++) {
        if (tcaOpens($t[$i])) { $stack[] = $i; }
        elseif ($t[$i]->text === '}') {
            $o = array_pop($stack);
            if ($o !== null) { $match[$o] = $i; $match[$i] = $o; }
        }
    }

    /* Class bodies, for naming methods. */
    $classes = [];
    for ($i = 0; $i < $n; $i++) {
        if (!in_array($t[$i]->id, [T_CLASS, T_TRAIT, T_ENUM, T_INTERFACE], true)) { continue; }
        $p = tcaSig($t, $i, -1);
        if ($p >= 0 && $t[$p]->id === T_DOUBLE_COLON) { continue; }   // Foo::class
        $nm = tcaSig($t, $i);
        $cname = ($nm >= 0 && $t[$nm]->id === T_STRING) ? strtolower($t[$nm]->text) : '{anonymous}';
        for ($j = $i; $j < $n && $t[$j]->text !== '{'; $j++);
        if ($j < $n && isset($match[$j])) { $classes[] = [$j, $match[$j], $cname]; }
    }
    $classAt = static function (int $i) use ($classes): ?string {
        $best = null; $size = PHP_INT_MAX;
        foreach ($classes as [$a, $b, $c]) {
            if ($i > $a && $i < $b && $b - $a < $size) { $best = $c; $size = $b - $a; }
        }
        return $best;
    };

    /* Nodes. The file's top level is one; each named function, method,
       closure and arrow function is another. */
    $top = 'top|' . $rel;
    $nodes = [$top => ['kind' => 'top', 'name' => '{top}', 'line' => 1, 'a' => 0, 'b' => $n - 1, 'class' => null, 'var' => null]];
    $fnAt = [];
    for ($i = 0; $i < $n; $i++) {
        if ($t[$i]->id !== T_FUNCTION && $t[$i]->id !== T_FN) { continue; }
        $j = tcaSig($t, $i);
        if ($j >= 0 && $t[$j]->text === '&') { $j = tcaSig($t, $j); }
        $named = $t[$i]->id === T_FUNCTION && $j >= 0 && $t[$j]->id === T_STRING;
        /* The parameter list, then the body. */
        $depth = 0;
        for ($k = $named ? $j + 1 : $i + 1; $k < $n; $k++) {
            $x = $t[$k]->text;
            if ($x === '(') { $depth++; }
            elseif ($x === ')') { $depth--; }
            elseif ($depth === 0 && ($x === '{' || $x === ';' || $t[$k]->id === T_DOUBLE_ARROW)) { break; }
        }
        if ($k >= $n) { continue; }
        $class = $classAt($i);
        /* The variable a closure or arrow function is assigned to: `$x = function`, `$x = static fn`. */
        $p = tcaSig($t, $i, -1);
        if ($p >= 0 && $t[$p]->id === T_STATIC) { $p = tcaSig($t, $p, -1); }
        $var = null;
        if (!$named && $p >= 0 && $t[$p]->text === '=') {
            $q = tcaSig($t, $p, -1);
            if ($q >= 0 && $t[$q]->id === T_VARIABLE) { $var = $t[$q]->text; }
        }
        if ($t[$i]->id === T_FN) {
            if ($t[$k]->id !== T_DOUBLE_ARROW) { continue; }
            /* An arrow function's body is one expression: up to the first `;`, `,`, `)`, `]` or `}` outside brackets. */
            $d = 0;
            for ($e = $k + 1; $e < $n; $e++) {
                $x = $t[$e]->text;
                if ($x === '(' || $x === '[' || tcaOpens($t[$e])) { $d++; }
                elseif ($x === ')' || $x === ']' || $x === '}') { if ($d === 0) { break; } $d--; }
                elseif ($d === 0 && ($x === ';' || $x === ',')) { break; }
            }
            $id = "a|{$rel}|{$t[$i]->line}|{$i}";
            $nodes[$id] = ['kind' => 'arrow', 'name' => 'fn', 'line' => $t[$i]->line, 'a' => $k + 1, 'b' => $e - 1, 'class' => $class, 'var' => $var];
            $fnAt[$i] = $id;
            continue;
        }
        if ($t[$k]->text !== '{' || !isset($match[$k])) { continue; }   // an abstract or interface method
        if ($named) {
            $name = $t[$j]->text;
            $id = ($class !== null ? 'm|' . $class . '::' : 'f|') . strtolower($name) . "|{$rel}|{$t[$i]->line}";
            $nodes[$id] = ['kind' => $class !== null ? 'method' : 'function', 'name' => $class !== null ? "{$class}::{$name}" : $name,
                'line' => $t[$i]->line, 'a' => $k, 'b' => $match[$k], 'class' => $class, 'var' => null, 'short' => strtolower($name)];
        } else {
            $id = "c|{$rel}|{$t[$i]->line}|{$i}";
            $nodes[$id] = ['kind' => 'closure', 'name' => 'closure', 'line' => $t[$i]->line, 'a' => $k, 'b' => $match[$k], 'class' => $class, 'var' => $var];
            $fnAt[$i] = $id;
        }
    }

    /* Which node each token belongs to: the smallest one around it. */
    $owner = array_fill(0, $n, $top);
    $order = array_keys($nodes);
    usort($order, static fn(string $x, string $y): int => ($nodes[$y]['b'] - $nodes[$y]['a']) <=> ($nodes[$x]['b'] - $nodes[$x]['a']));
    foreach ($order as $id) {
        if ($id === $top) { continue; }
        for ($i = $nodes[$id]['a']; $i <= $nodes[$id]['b']; $i++) { $owner[$i] = $id; }
    }
    /* A closure or arrow function's name, for reports and the allow-list:
       "{closure 2 in NAME}" is the second closure written directly in NAME
       (counting from the top), so two closures in one function never share a
       name. Parents are named before their children (source order). */
    $seen = [];
    $byStart = array_keys($nodes);
    usort($byStart, static fn(string $x, string $y): int => $nodes[$x]['a'] <=> $nodes[$y]['a']);
    foreach ($byStart as $id) {
        $nd = &$nodes[$id];
        if ($nd['kind'] === 'closure' || $nd['kind'] === 'arrow') {
            $parentId = $owner[max(0, $nd['a'] - 1)];
            $word = $nd['kind'] === 'closure' ? 'closure' : 'fn';
            $seen[$parentId][$word] = ($seen[$parentId][$word] ?? 0) + 1;
            $nd['name'] = '{' . $word . ' ' . $seen[$parentId][$word] . ' in ' . ($nodes[$parentId]['name'] ?? '?') . '}';
        }
        unset($nd);
    }

    /* The calls written directly in each node. */
    $edges = array_fill_keys(array_keys($nodes), []);
    for ($i = 0; $i < $n; $i++) {
        $e = tcaEdgeAt($t, $i, $rel, $nodes[$owner[$i]]['class'], $fnAt);
        if ($e !== null) { $edges[$owner[$i]][$e] = true; }
    }
    /* An arrow function's body is also the parent's (its tokens are painted with the arrow's id, so add them back). */
    foreach ($nodes as $id => $nd) {
        if ($nd['kind'] !== 'arrow') { continue; }
        $parent = $owner[max(0, $nd['a'] - 1)];
        if ($parent !== $id) { $edges[$parent]['node:' . $id] = true; }
    }

    /* Catch blocks. */
    $catches = [];
    $nth = [];
    for ($i = 0; $i < $n; $i++) {
        if ($t[$i]->id !== T_TRY) { continue; }
        $a = tcaSig($t, $i);
        if ($a < 0 || $t[$a]->text !== '{' || !isset($match[$a])) { continue; }
        $k = tcaSig($t, $match[$a]);
        while ($k >= 0 && $t[$k]->id === T_CATCH) {
            $p = tcaSig($t, $k);           // (
            $q = tcaSig($t, $p);
            $types = [];
            $var = null;
            while ($q >= 0 && $t[$q]->text !== ')') {
                if ($t[$q]->id === T_VARIABLE) { $var = $t[$q]->text; }
                elseif ($t[$q]->text !== '|') { $types[] = tcaShort($t[$q]->text); }
                $q = tcaSig($t, $q);
            }
            $bo = tcaSig($t, $q);
            $bc = $match[$bo] ?? $bo;
            /* The first statement, as significant tokens. */
            $seq = [];
            for ($r = tcaSig($t, $bo); $r >= 0 && $r < $bc && count($seq) < 12; $r = tcaSig($t, $r)) {
                $seq[] = $t[$r]->id === T_STRING || $t[$r]->id === T_NAME_FULLY_QUALIFIED ? tcaShort($t[$r]->text) : $t[$r]->text;
            }
            $guard = $var !== null && (
                array_slice($seq, 0, 12) === ['if', '(', 'songrelocateistransactionfatal', '(', $var, ')', ')', '{', 'throw', $var, ';', '}']
                || array_slice($seq, 0, 10) === ['if', '(', 'songrelocateistransactionfatal', '(', $var, ')', ')', 'throw', $var, ';']
            );
            $catches["{$rel}:{$t[$k]->line}:{$k}"] = [
                'file' => $rel, 'line' => $t[$k]->line, 'tok' => $k, 'node' => $owner[$k], 'nth' => 0,
                'types' => $types, 'var' => $var, 'guard' => $guard,
            ];
            $k = tcaSig($t, $bc);
        }
    }
    /* Number each node's catches in the order they are written (1 = the first
       `catch` in that function, wherever its `try` began). */
    uasort($catches, static fn(array $x, array $y): int => $x['tok'] <=> $y['tok']);
    foreach ($catches as &$c) {
        $nth[$c['node']] = ($nth[$c['node']] ?? 0) + 1;
        $c['nth'] = $nth[$c['node']];
    }
    unset($c);

    /* Transactions: where each starts, where it ends, and what is written in between. */
    $begins = [];
    $commits = [];
    for ($i = 0; $i < $n; $i++) {
        if (tcaMethodCall($t, $i, 'begin_transaction') || tcaFunctionCall($t, $i, 'mysqli_begin_transaction')) {
            $begins[] = $i;
        } elseif ($t[$i]->id === T_CONSTANT_ENCAPSED_STRING
            && preg_match('/^.\s*(START\s+TRANSACTION|BEGIN(\s+WORK)?\s*;?\s*.$)/i', $t[$i]->text) === 1) {
            /* A query('START TRANSACTION') — only as a query's argument, never any string that says "begin". */
            for ($j = tcaSig($t, $i, -1), $hops = 0; $j >= 0 && $hops < 6; $j = tcaSig($t, $j, -1), $hops++) {
                if ($t[$j]->id === T_STRING && in_array(strtolower($t[$j]->text), ['query', 'real_query', 'multi_query', 'execute_query', 'mysqli_query', 'mysqli_real_query'], true)) {
                    $begins[] = $i;
                    break;
                }
                if ($t[$j]->text === ';' || tcaOpens($t[$j]) || $t[$j]->text === '}') { break; }
            }
        } elseif (tcaMethodCall($t, $i, 'commit') || tcaFunctionCall($t, $i, 'mysqli_commit')) {
            $commits[] = $i;
        }
    }
    $regions = [];
    foreach ($begins as $bi) {
        /* The innermost block around the begin. */
        $blockOpen = -1;
        for ($j = $bi; $j >= 0; $j--) {
            if (tcaOpens($t[$j]) && isset($match[$j]) && $match[$j] > $bi) { $blockOpen = $j; break; }
        }
        $blockEnd = $blockOpen >= 0 ? $match[$blockOpen] : $n - 1;
        /* …up to the next begin in that block, and within that, to the last commit. */
        $stop = $blockEnd;
        foreach ($begins as $other) {
            if ($other > $bi && $other < $stop) { $stop = $other; }
        }
        $end = $stop;
        foreach ($commits as $ci) {
            if ($ci > $bi && $ci < $stop) { $end = $ci; }
        }
        $seeds = [];
        $inside = [];
        for ($i = $bi; $i <= $end; $i++) {
            $e = tcaEdgeAt($t, $i, $rel, $nodes[$owner[$i]]['class'], $fnAt);
            if ($e !== null) { $seeds[$e] = true; }
        }
        foreach ($catches as $cid => $c) {
            if ($c['tok'] > $bi && $c['tok'] < $end) { $inside[] = $cid; }
        }
        $regions[] = ['file' => $rel, 'line' => $t[$bi]->line, 'node' => $owner[$bi], 'endLine' => $t[$end]->line,
            'seeds' => array_keys($seeds), 'inside' => $inside];
    }

    foreach ($nodes as $id => &$nd) { unset($nd['a'], $nd['b']); $nd['file'] = $rel; }
    unset($nd);
    return ['nodes' => $nodes, 'edges' => array_map('array_keys', $edges), 'catches' => $catches, 'regions' => $regions];
}

/* ------------------------------------------------------------------ read every file */

$nodes = [];
$edges = [];
$catches = [];
$regions = [];
$byFunc = [];
$byMethod = [];
$ctorByClass = [];
$topByBase = [];
$topByPath = [];
$closuresByVar = [];
$fileCount = 0;
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($repoRoot . '/appWeb', FilesystemIterator::SKIP_DOTS));
$paths = [];
foreach ($it as $f) {
    $p = (string)$f;
    if (!str_ends_with($p, '.php') || str_contains($p, '/vendor/') || str_contains($p, '/node_modules/')) { continue; }
    $paths[] = $p;
}
sort($paths);
foreach ($paths as $p) {
    $rel = str_replace('\\', '/', substr($p, strlen($repoRoot) + 1));
    $r = tcaScanFile($rel, (string)file_get_contents($p));
    $fileCount++;
    foreach ($r['nodes'] as $id => $nd) {
        $nodes[$id] = $nd;
        $edges[$id] = $r['edges'][$id];
        if ($nd['kind'] === 'function') { $byFunc[$nd['short']][] = $id; }
        if ($nd['kind'] === 'method') {
            $byMethod[$nd['short']][] = $id;
            if ($nd['short'] === '__construct') { $ctorByClass[$nd['class']][] = $id; }
        }
        if ($nd['kind'] === 'top') { $topByBase[strtolower(basename($rel))][] = $id; $topByPath[$rel] = [$id]; }
        if ($nd['var'] !== null) { $closuresByVar[$rel . '|' . $nd['var']][] = $id; }
    }
    $catches += $r['catches'];
    foreach ($r['regions'] as $rg) { $regions[] = $rg; }
}

/** Turn an edge key into the nodes it reaches. */
$resolve = static function (string $e) use ($byFunc, $byMethod, $ctorByClass, $topByBase, $topByPath, $closuresByVar): array {
    [$kind, $what] = explode(':', $e, 2);
    return match ($kind) {
        'f'    => $byFunc[$what] ?? [],
        'm'    => $byMethod[$what] ?? [],
        'ctor' => $ctorByClass[$what] ?? [],
        'inc'  => $topByPath[$what] ?? [],
        'incb' => $topByBase[$what] ?? [],
        'var'  => $closuresByVar[$what] ?? [],
        'node' => [$what],
        /* A string counts as a call when it is the name of a function, or of a method (longer than three
           letters — shorter method names are too often ordinary words in strings). */
        's'    => array_merge($byFunc[$what] ?? [], strlen($what) > 3 ? ($byMethod[$what] ?? []) : []),
        default => [],
    };
};

/* Which transactions are audited: those in the entry files, and those in
   anything code in the entry files can reach (anywhere, not only inside a
   transaction). */
$reach = [];
$queue = [];
foreach ($nodes as $id => $nd) {
    if (in_array($nd['file'], TCA_ENTRY_FILES, true)) { $queue[] = $id; }
}
while ($queue !== []) {
    $c = array_pop($queue);
    if (isset($reach[$c])) { continue; }
    $reach[$c] = true;
    foreach ($edges[$c] as $e) {
        foreach ($resolve($e) as $to) {
            if (!isset($reach[$to])) { $queue[] = $to; }
        }
    }
}
$roots = array_values(array_filter($regions, static fn(array $rg): bool => isset($reach[$rg['node']])));

/* Every catch each audited transaction can reach, with one way it gets there. */
$found = [];   // catch id => "how it is reached"
foreach ($roots as $rg) {
    $label = "{$rg['file']}:{$rg['line']} ({$nodes[$rg['node']]['name']})";
    foreach ($rg['inside'] as $cid) { $found[$cid] ??= "{$label} > written inside the transaction"; }
    $via = [];
    $queue = [];
    foreach ($rg['seeds'] as $e) {
        foreach ($resolve($e) as $to) {
            if (!isset($via[$to])) { $via[$to] = $nodes[$to]['name']; $queue[] = $to; }
        }
    }
    for ($qi = 0; $qi < count($queue); $qi++) {
        $c = $queue[$qi];
        foreach ($edges[$c] as $e) {
            foreach ($resolve($e) as $to) {
                if (!isset($via[$to])) { $via[$to] = $via[$c] . ' > ' . $nodes[$to]['name']; $queue[] = $to; }
            }
        }
    }
    foreach ($catches as $cid => $ct) {
        if (isset($via[$ct['node']])) { $found[$cid] ??= "{$label} > {$via[$ct['node']]}"; }
    }
}

/* ------------------------------------------------------------------ the verdicts */

echo "\nWhat was read\n";
$rootFiles = array_count_values(array_column($roots, 'file'));
ksort($rootFiles);
echo "  {$fileCount} PHP files; " . count($roots) . " audited transactions (" . implode(', ', array_map(
    static fn(string $f, int $c): string => basename($f) . " {$c}", array_keys($rootFiles), $rootFiles)) . "); " . count($found) . " catch blocks reachable inside them\n";

/* Floors, so a change to the reading itself (or to the code's layout) that
   makes it see far less cannot pass as "nothing to report". */
$check('the song save\'s own transaction is found', count(array_filter($roots,
    static fn(array $rg): bool => $rg['file'] === 'appWeb/public_html/manage/editor/save_song_core.php'
        && str_starts_with($nodes[$rg['node']]['name'], 'editorSaveSongCore'))) === 1);
$check('every audited file\'s own transactions are found (the v2 editor has at least 45; the songbooks page 5; the works page, the bulk importer and the lyrics importer at least 1)',
    ($rootFiles['appWeb/public_html/manage/editor/api2.php'] ?? 0) >= 45
    && ($rootFiles['appWeb/public_html/manage/songbooks.php'] ?? 0) >= 5
    && ($rootFiles['appWeb/public_html/manage/works.php'] ?? 0) >= 1
    && ($rootFiles['appWeb/public_html/includes/song_importers.php'] ?? 0) >= 1
    && ($rootFiles['appWeb/public_html/includes/lyrics_ingest.php'] ?? 0) >= 1,
    json_encode($rootFiles));
$check('at least 60 audited transactions and 90 reachable catch blocks', count($roots) >= 60 && count($found) >= 90,
    count($roots) . ' transactions, ' . count($found) . ' catches');

/* The catches the seventh review's planted faults showed no test was
   watching, and the two funnels the sixth review's case went through: each
   must still be seen as reachable (and so be checked), whatever else changes. */
$mustReach = [
    ['appWeb/public_html/api.php', 'slideAuthTokenExpiry'],
    ['appWeb/public_html/includes/publisher_helpers.php', 'publisherResolvePickedOrCreate'],
    ['appWeb/public_html/includes/publisher_helpers.php', 'publisherFindOrCreateByName'],
    ['appWeb/public_html/includes/tune_helpers.php', 'tuneFindOrCreateByName'],
    ['appWeb/public_html/manage/editor/api2.php', 'ed2_touchRevision'],
    ['appWeb/public_html/includes/work_admin.php', 'workMedleyReplace'],
    ['appWeb/public_html/includes/song_redirects.php', 'songRedirectsTableReady'],
    ['appWeb/public_html/includes/musician_helpers.php', 'generateUniqueMusicianSlug'],
    ['appWeb/public_html/manage/includes/auth.php', 'adoptApiTokenSession'],
    ['appWeb/public_html/manage/includes/songbook-palette.php', 'pickAutoSongbookColour'],
    ['appWeb/public_html/includes/activity_log.php', 'logActivity'],
];
$reachedIn = [];
foreach ($found as $cid => $_) { $reachedIn[$catches[$cid]['file'] . '|' . strtolower($nodes[$catches[$cid]['node']]['name'])] = true; }
$missing = array_values(array_filter($mustReach, static fn(array $m): bool => !isset($reachedIn[$m[0] . '|' . strtolower($m[1])])));
$check('the catches in ' . count($mustReach) . ' named helpers (the seventh review\'s eight, the tune and publisher funnels, logActivity()) are among those checked',
    $missing === [], 'not reached: ' . json_encode($missing));

$allowedUsed = [];
$bad = [];
$counts = ['guarded' => 0, 'allowed' => 0];
foreach ($found as $cid => $how) {
    $ct = $catches[$cid];
    if ($ct['guard']) { $counts['guarded']++; continue; }
    $where = $nodes[$ct['node']]['name'];
    $hit = null;
    foreach (TCA_ALLOWED as $k => [$file, $in, $nth]) {
        if ($file === $ct['file'] && strcasecmp($in, $where) === 0 && $nth === $ct['nth']) { $hit = $k; break; }
    }
    if ($hit !== null) { $counts['allowed']++; $allowedUsed[$hit] = true; continue; }
    $bad[] = sprintf('%s:%d in %s (catch #%d there, catching %s%s) — reached from %s',
        $ct['file'], $ct['line'], $where, $ct['nth'], implode('|', $ct['types']),
        $ct['var'] === null ? ', with no variable to re-throw' : '', $how);
}
echo "  {$counts['guarded']} start with the guard; {$counts['allowed']} are on the allow-list\n";
$check('every catch block reachable inside an audited transaction starts with if (songRelocateIsTransactionFatal($e)) { throw $e; } or is on the allow-list',
    $bad === [], count($bad) . " do not:\n        - " . implode("\n        - ", $bad));
$stale = [];
foreach (TCA_ALLOWED as $k => [$file, $in, $nth]) {
    if (!isset($allowedUsed[$k])) { $stale[] = "{$file} {$in} catch #{$nth}"; }
}
$check('every allow-list entry still matches a reachable catch without the guard (a stale entry must be looked at again)',
    $stale === [], implode('; ', $stale));

echo "\n  {$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
