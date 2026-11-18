<?php

declare(strict_types=1);

/**
 * iHymns - ONE writer for the account settings box (A1, epic #2180)
 * ================================================================
 *
 * ELI5
 * ----
 * Each signed-in person has one box of settings on their account row. Several
 * features used to write that box on their own, and two of them writing at the
 * same moment could erase each other's change. Now there is ONE door
 * (`userSettingsMutate()` in `includes/user_settings.php`) that locks the row
 * while it works. This guard walks every PHP file and fails if anything else
 * tries to write the box directly, so a future change cannot quietly add a
 * second, unlocked door.
 *
 * Detailed
 * --------
 * Rule #34: the list of files is DERIVED from the tree (every .php under
 * appWeb/), never typed here. We use PHP's own tokenizer and look only at
 * string pieces, so a comment that merely talks about the pattern cannot trip
 * it. Within those strings we look for `UPDATE tblUsers SET ... Settings =` and
 * `INSERT INTO tblUsers (... Settings ...)`.
 *
 * Allowed, and nothing else:
 *   - includes/user_settings.php    - the one locked writer itself;
 *   - includes/account_lifecycle.php - account erasure, which may only ever
 *                                      set the box to NULL.
 *
 * Known limit, stated so this tick is not over-read: a statement whose column
 * name is built at run time (`"UPDATE tblUsers SET $col = ?"`) cannot be seen.
 * No such statement exists today; the guard's mutation proof (adding a raw
 * UPDATE to card_layout.php turns it red) is recorded in the A1 commit.
 *
 *   php tests/php/test-settings-box-single-writer.php
 *
 * @see appWeb/public_html/includes/user_settings.php  userSettingsMutate()
 */

$root = dirname(__DIR__, 2) . '/appWeb';

/** Every file allowed to write the box, with the one shape it may use. */
$allowed = [
    'public_html/includes/user_settings.php'     => null,            /* the writer: any shape */
    'public_html/includes/account_lifecycle.php' => '/^\s*Settings\s*=\s*NULL\s*$/i',
];

/** Join all string pieces of a PHP file (comments excluded) into one text. */
$stringText = static function (string $path): string {
    $src = (string)file_get_contents($path);
    $out = [];
    foreach (token_get_all($src) as $tok) {
        if (is_array($tok) && in_array($tok[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
            $out[] = $tok[1];
        }
    }
    return implode(' ', $out);
};

$fail = 0;
$files = 0;
$allowedSeen = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if (!$f->isFile() || substr($f->getFilename(), -4) !== '.php') { continue; }
    /* Vendored third-party code is not ours to police and never touches tblUsers. */
    if (strpos($f->getPathname(), '/private_html/lib/') !== false) { continue; }
    $files++;
    $rel = ltrim(substr($f->getPathname(), strlen($root)), '/');
    $text = $stringText($f->getPathname());
    if (stripos($text, 'tblUsers') === false) { continue; }

    $hits = [];
    if (preg_match_all('/UPDATE\s+tblUsers\s+SET\b(.*?)(?:\bWHERE\b|$)/is', $text, $m)) {
        foreach ($m[1] as $setList) {
            /* The SET list is comma separated; look at each assignment. */
            foreach (preg_split('/,(?![^()]*\))/', $setList) ?: [] as $assign) {
                if (preg_match('/^\s*`?Settings`?\s*=/i', $assign)) { $hits[] = trim($assign); }
            }
        }
    }
    if (preg_match_all('/INSERT\s+(?:IGNORE\s+)?INTO\s+tblUsers\s*\(([^)]*)\)/is', $text, $m2)) {
        foreach ($m2[1] as $cols) {
            if (preg_match('/(^|,)\s*`?Settings`?\s*(,|$)/i', $cols)) { $hits[] = 'INSERT column list names Settings'; }
        }
    }
    if (!$hits) { continue; }

    if (array_key_exists($rel, $allowed)) {
        $allowedSeen[$rel] = true;
        $shape = $allowed[$rel];
        foreach ($hits as $h) {
            if ($shape !== null && !preg_match($shape, $h)) {
                echo "  FAIL  $rel writes the settings box in a shape that is not allowed there: $h\n";
                $fail++;
            }
        }
        continue;
    }
    echo "  FAIL  $rel writes tblUsers.Settings directly. Use userSettingsMutate() (includes/user_settings.php).\n";
    $fail++;
}

/* The guard must be able to SEE the two allowed writers, or its scan is blind. */
foreach (array_keys($allowed) as $rel) {
    if (empty($allowedSeen[$rel])) {
        echo "  FAIL  scan did not find the expected writer in $rel (the guard may be blind)\n";
        $fail++;
    }
}
if ($files < 100) {
    echo "  FAIL  only $files PHP files scanned; the tree walk looks broken\n";
    $fail++;
}

echo $fail === 0 ? "  PASS  only the allowed writers touch tblUsers.Settings ($files files scanned)\n" : '';
exit($fail === 0 ? 0 : 1);
