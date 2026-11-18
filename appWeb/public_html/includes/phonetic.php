<?php

declare(strict_types=1);

/**
 * iHymns — "sounds-like" code for a word (a safe stand-in for PHP's metaphone()).
 *
 * PLAIN-ENGLISH SUMMARY
 * PHP's built-in `metaphone()` turns a word into a short code that is the same
 * for words that sound alike ("Knight" and "Night" both give "NFT"/"NT"-style
 * codes). PHP 8.6 marks that built-in as outdated (it is scheduled for
 * removal later). This file is the ONE place the rest of iHymns asks for such
 * a code, so that:
 *   - on PHP 8.4 and 8.5 we still call PHP's own function (nothing changes);
 *   - on PHP 8.6 and later we use a PHP copy of the same method instead, so no
 *     "deprecated" warning appears and the answers stay identical.
 *
 * WHY A COPY AND NOT A DIFFERENT METHOD
 * The only user is the "might be the same person" name grouping in
 * includes/musician_duplicates.php. Those codes are only compared with each
 * other inside one request; they are never stored. Even so, switching to a
 * different sound-alike method would change which names get grouped together
 * between 8.5 and 8.6, so the copy follows the original exactly.
 *
 * HOW THE COPY WAS MADE
 * It is a line-for-line port of PHP's own C source, with the "traditional"
 * setting PHP always uses:
 *   https://github.com/php/php-src/blob/master/ext/standard/metaphone.c
 * Letters are looked at one BYTE at a time and only plain ASCII letters count
 * (the same as PHP's own function in the default locale), so accented or
 * non-Latin letters are skipped, exactly as before.
 *
 * HOW WE KNOW IT MATCHES
 * tests/php/test-metaphone-parity.php compares this copy against PHP's own
 * metaphone() on every run on PHP 8.4/8.5 (hand-picked tricky words plus 5,000
 * seeded random strings), and on every version against a frozen answer table
 * (tests/php/fixtures/metaphone-frozen.json). If you change a rule here, that
 * test goes red.
 *
 * @see appWeb/public_html/includes/musician_duplicates.php  the only caller
 * @see https://www.php.net/manual/en/function.metaphone.php
 * @see tests/php/test-php86-deprecations.php  keeps native metaphone() calls
 *      confined to this one file
 */

/**
 * Sounds-like code for a word: PHP's own function below 8.6, the PHP copy from 8.6.
 *
 * ELI5: give it a name, get back a short code that matches other names which
 * sound the same. Use this instead of calling `metaphone()` directly.
 *
 * @param string $word Any text; only A-Z / a-z letters take part.
 * @return string The sound-alike code (empty string if there are no letters).
 */
function ihymns_metaphone(string $word): string
{
    /* Detailed: PHP_VERSION_ID 80600 = 8.6.0. Below it the native function is
       neither deprecated nor missing, and it is the reference the copy is
       tested against, so we keep using it there (owner decision 3: old way on
       8.4/8.5, new way on 8.6). Syntax cannot be switched this way, but a
       function call can. */
    if (PHP_VERSION_ID < 80600) {
        return \metaphone($word);
    }
    return ihymns_metaphone_userland($word);
}

/**
 * The PHP copy of PHP's metaphone() (traditional mode, no length limit).
 *
 * ELI5: it walks the word letter by letter and writes down how each letter
 * sounds, skipping silent ones, the same way PHP's own function does.
 *
 * @param string $word Any text. Reading stops at the first zero byte, as in C.
 * @return string The sound-alike code.
 */
function ihymns_metaphone_userland(string $word): string
{
    /* Detailed: the C code works on a zero-terminated string, so an embedded
       zero byte ends the word. Cut there to behave the same. */
    $nul = strpos($word, "\0");
    if ($nul !== false) {
        $word = substr($word, 0, $nul);
    }
    $word = (string)$word;
    $len  = strlen($word);

    /* Letter-class table from the C source: one number per letter A..Z, where
       the bits mean 1 = vowel (AEIOU), 2 = passes through unchanged (FJLMNR),
       4 = can combine with H (CGPST), 8 = makes C/G soft (EIY),
       16 = stops "GH" turning into F (BDH). */
    static $codes = [
        1, 16, 4, 16, 9, 2, 4, 16, 9, 2, 0, 2, 2, 2, 1, 4, 0, 2, 4, 4, 1, 0, 0, 0, 8, 0,
    ];

    /* Upper-cased byte at a position, or '' beyond either end. ASCII only. */
    $at = static function (int $i) use ($word, $len): string {
        return ($i >= 0 && $i < $len) ? strtoupper($word[$i]) : '';
    };
    $isAlpha = static fn(string $c): bool => $c !== '' && ctype_alpha($c) && ord($c) < 128;
    $bits    = static function (string $c) use ($codes, $isAlpha): int {
        return $isAlpha($c) ? $codes[ord($c) - 65] : 0;
    };
    $isVowel    = static fn(string $c): bool => ($bits($c) & 1) !== 0;
    $affectH    = static fn(string $c): bool => ($bits($c) & 4) !== 0;
    $makeSoft   = static fn(string $c): bool => ($bits($c) & 8) !== 0;
    $noGhToF    = static fn(string $c): bool => ($bits($c) & 16) !== 0;

    $out = '';
    $w   = 0;

    /* Skip anything before the first letter. */
    while ($w < $len && !$isAlpha($at($w))) {
        $w++;
    }
    if ($w >= $len) {
        return '';
    }

    /* Special starts: AE -> E, GN/KN/PN -> N, WR -> R, WH/W+vowel -> W,
       a leading X -> S, a leading vowel is kept. */
    $cur  = $at($w);
    $next = $at($w + 1);
    switch ($cur) {
        case 'A':
            if ($next === 'E') { $out .= 'E'; $w += 2; }
            else               { $out .= 'A'; $w++; }
            break;
        case 'G': case 'K': case 'P':
            if ($next === 'N') { $out .= 'N'; $w += 2; }
            break;
        case 'W':
            if ($next === 'R') { $out .= 'R'; $w += 2; }
            elseif ($next === 'H' || $isVowel($next)) { $out .= 'W'; $w += 2; }
            break;
        case 'X':
            $out .= 'S'; $w++;
            break;
        case 'E': case 'I': case 'O': case 'U':
            $out .= $cur; $w++;
            break;
    }

    /* The main pass: one letter at a time. */
    for (; $w < $len; $w++) {
        $cur = $at($w);
        if (!$isAlpha($cur)) {
            continue;
        }
        $last = $w >= 1 ? $at($w - 1) : '';
        /* Doubled letters count once, except CC. */
        if ($cur === $last && $cur !== 'C') {
            continue;
        }
        $next      = $at($w + 1);
        $afterNext = $next !== '' ? $at($w + 2) : '';
        $skip      = 0;

        switch ($cur) {
            case 'B':
                /* B is silent after M ("dumb"). */
                if ($last !== 'M') { $out .= 'B'; }
                break;
            case 'C':
                if ($makeSoft($next)) {
                    if ($afterNext === 'A' && $next === 'I') { $out .= 'X'; }       /* CIA */
                    elseif ($last === 'S') { /* SCI/SCE/SCY: dropped */ }
                    else { $out .= 'S'; }
                } elseif ($next === 'H') {
                    $out .= 'X';                                                    /* traditional mode: CH is always X */
                    $skip++;
                } else {
                    $out .= 'K';
                }
                break;
            case 'D':
                if ($next === 'G' && $makeSoft($afterNext)) { $out .= 'J'; $skip++; }
                else { $out .= 'T'; }
                break;
            case 'G':
                if ($next === 'H') {
                    if (!($noGhToF($at($w - 3)) || $at($w - 4) === 'H')) {
                        $out .= 'F'; $skip++;
                    }
                } elseif ($next === 'N') {
                    $isBreak = !$isAlpha($afterNext);
                    if ($isBreak || ($afterNext === 'E' && $at($w + 3) === 'D')) {
                        /* GN / GNED at the end: dropped */
                    } else {
                        $out .= 'K';
                    }
                } elseif ($makeSoft($next) && $last !== 'G') {
                    $out .= 'J';
                } else {
                    $out .= 'K';
                }
                break;
            case 'H':
                if ($isVowel($next) && !$affectH($last)) { $out .= 'H'; }
                break;
            case 'K':
                if ($last !== 'C') { $out .= 'K'; }
                break;
            case 'P':
                $out .= ($next === 'H') ? 'F' : 'P';
                break;
            case 'Q':
                $out .= 'K';
                break;
            case 'S':
                if ($next === 'I' && ($afterNext === 'O' || $afterNext === 'A')) { $out .= 'X'; }
                elseif ($next === 'H') { $out .= 'X'; $skip++; }
                else { $out .= 'S'; }
                break;
            case 'T':
                if ($next === 'I' && ($afterNext === 'O' || $afterNext === 'A')) { $out .= 'X'; }
                elseif ($next === 'H') { $out .= '0'; $skip++; }
                elseif (!($next === 'C' && $afterNext === 'H')) { $out .= 'T'; }
                break;
            case 'V':
                $out .= 'F';
                break;
            case 'W':
                if ($isVowel($next)) { $out .= 'W'; }
                break;
            case 'X':
                $out .= 'KS';
                break;
            case 'Y':
                if ($isVowel($next)) { $out .= 'Y'; }
                break;
            case 'Z':
                $out .= 'S';
                break;
            case 'F': case 'J': case 'L': case 'M': case 'N': case 'R':
                $out .= $cur;
                break;
            default:
                /* vowels after the first letter, and anything else: silent */
                break;
        }
        $w += $skip;
    }
    return $out;
}
