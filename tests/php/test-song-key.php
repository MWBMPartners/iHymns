<?php

declare(strict_types=1);

/**
 * iHymns — `songKeyValidate()` and friends BEHAVE correctly (#1671 F3 / #298)
 * ==========================================================================
 *
 * ELI5
 * ----
 * Three things about a song — its key, its speed and its time signature —
 * have to fit exactly into three database columns. One small set of functions
 * decides that. This file CALLS them and checks their answers.
 *
 * WHY THIS FILE READS NO SOURCE
 * -----------------------------
 * Every property asserted here lives in a PURE function, so the guard calls it.
 * The alternative — grepping api.php for `VARCHAR(5)` or for the absence of
 * `$tsOrNull` — would assert the file's VOCABULARY, and vocabulary survives any
 * mutation that keeps the words. That is exactly how
 * `songRelocateIsTransactionFatal()` was guarded twice, kept its words, lost
 * its behaviour, and left the whole suite green (see
 * `tests/php/test-transaction-fatal.php`'s header for the full story). When a
 * property has a runtime handle, use it.
 *
 * THE THREE CRASHES THIS PINS
 * ---------------------------
 * `?action=song_key_save` was dispatched from #298 with no caller anywhere, so
 * nothing ever ran it. It had three guaranteed 500s, each a mismatch between
 * the handler's caps and the column definition, each fatal because mysqli runs
 * under `MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT` and therefore THROWS:
 *
 *   1. NULL bound into `TimeSignature VARCHAR(10) NOT NULL` → error 1048.
 *      This is the important one: it fired on the most ordinary action a
 *      curator can take, saving a key without a time signature.
 *   2. `original_key` capped at 10 for a `VARCHAR(5)` column → error 1406.
 *   3. `(int)`-cast `tempo` into `INT UNSIGNED` → error 1264 on a negative.
 *
 * Each has an assertion below whose failure means the crash is back.
 *
 *   php tests/php/test-song-key.php
 *
 * Exit status 0 = all pass, 1 = a failure.
 *
 * @see appWeb/public_html/includes/song_key.php
 * @see appWeb/.sql/schema.sql — tblSongKeys (#298)
 */

require_once dirname(__DIR__, 2) . '/appWeb/public_html/includes/song_key.php';

$fail = 0;
function ok(string $label, bool $cond, string $detail = ''): void
{
    global $fail;
    echo ($cond ? "  PASS  " : "  FAIL  ") . $label . "\n";
    if (!$cond) {
        $fail++;
        if ($detail !== '') { echo "        $detail\n"; }
    }
}

/** A minimal valid body, so each case below varies exactly one thing. */
function songKeyBody(array $overrides = []): array
{
    return array_merge(['song_id' => 'MP-1008', 'original_key' => 'G'], $overrides);
}

echo "Behaviour of songKeyValidate() / songKeyNormalise*()\n";

/* =========================================================================
 * CRASH 1 — TimeSignature is NOT NULL, so "unspecified" must be '' not null.
 *
 * The distinction the normaliser has to hold is subtle and load-bearing:
 *   '' (empty string) = "the caller did not specify one"  → STORE ''
 *   null              = "the caller sent something invalid" → REJECT
 * Collapsing the two in either direction reinstates the 1048.
 * ========================================================================= */
echo "\n-- CRASH 1: TimeSignature NOT NULL --\n";

$r = songKeyValidate(songKeyBody());
ok('an omitted time_signature validates',
   $r['ok'] === true, 'reason: ' . ($r['reason'] ?? '-'));
ok('…and yields the EMPTY STRING, never null (the column is NOT NULL)',
   ($r['timeSignature'] ?? null) === '',
   'got ' . var_export($r['timeSignature'] ?? null, true)
   . ' — binding null here is MySQL error 1048 and an uncaught 500');
ok('…and it is a string type, not a null that happens to compare loosely',
   is_string($r['timeSignature'] ?? null));

$r = songKeyValidate(songKeyBody(['time_signature' => null]));
ok('an explicit null time_signature also yields ""',
   $r['ok'] === true && $r['timeSignature'] === '');

$r = songKeyValidate(songKeyBody(['time_signature' => '   ']));
ok('a whitespace-only time_signature yields ""',
   $r['ok'] === true && $r['timeSignature'] === '');

ok('songKeyNormaliseTimeSignature(null) === "" (not null)',
   songKeyNormaliseTimeSignature(null) === '');
ok('songKeyNormaliseTimeSignature("") === "" (not null)',
   songKeyNormaliseTimeSignature('') === '');

foreach (['4/4', '3/4', '6/8', '12/8', '2/2', '7/8'] as $ts) {
    $r = songKeyValidate(songKeyBody(['time_signature' => $ts]));
    ok("time_signature $ts is accepted verbatim",
       $r['ok'] === true && $r['timeSignature'] === $ts);
}

foreach ([
    '4-4'      => 'a hyphen is not a solidus',
    '4/'       => 'no denominator',
    '/4'       => 'no numerator',
    '0/4'      => 'zero beats is not a time signature and renders as broken data',
    '4/0'      => 'zero note value, same reasoning',
    '444/4'    => 'three digits — the cap is what keeps the result inside VARCHAR(10)',
    'common'   => 'a word, not a signature',
    "4/4\n5/4" => 'an embedded newline — the anchors are \A and \z, not ^ and $',
] as $bad => $why) {
    ok("time_signature " . json_encode($bad) . " is rejected ($why)",
       songKeyNormaliseTimeSignature($bad) === null);
    $r = songKeyValidate(songKeyBody(['time_signature' => $bad]));
    ok("…and songKeyValidate reports reason=time_signature_invalid",
       $r['ok'] === false && $r['reason'] === 'time_signature_invalid');
}

ok('a non-string time_signature (an array) is rejected, not coerced',
   songKeyNormaliseTimeSignature(['4/4']) === null);
ok('a numeric time_signature is rejected, not coerced',
   songKeyNormaliseTimeSignature(44) === null);

/* =========================================================================
 * CRASH 2 — OriginalKey is VARCHAR(5); the old cap was 10.
 *
 * The normaliser's accepted set is at most THREE characters, so the column can
 * never overflow. Asserted as a LENGTH property over every accepted value, not
 * as "the constant says 5" — a constant is vocabulary.
 * ========================================================================= */
echo "\n-- CRASH 2: OriginalKey VARCHAR(5) --\n";

$acceptedKeys = [];
foreach (str_split('ABCDEFGabcdefg') as $root) {
    foreach (['', '#', 'b'] as $acc) {
        foreach (['', 'm', 'M'] as $qual) {
            $candidate = $root . $acc . $qual;
            $norm = songKeyNormaliseKey($candidate);
            if ($norm !== null) { $acceptedKeys[$candidate] = $norm; }
        }
    }
}
ok('the normaliser accepts a non-trivial set (sanity — an always-null '
   . 'normaliser would make the length assertion below vacuously true)',
   count($acceptedKeys) >= 42,
   'accepted ' . count($acceptedKeys));

$tooLong = [];
foreach ($acceptedKeys as $in => $out) {
    if (mb_strlen($out) > 5) { $tooLong[] = "$in -> $out"; }
}
ok('EVERY accepted key normalises to at most 5 characters (VARCHAR(5))',
   $tooLong === [],
   'these would raise MySQL error 1406: ' . implode(', ', $tooLong));

ok('lowercase roots are upper-cased', songKeyNormaliseKey('g') === 'G');
ok('a flat stays lowercase b', songKeyNormaliseKey('Bb') === 'Bb');
ok('an UPPER-case accidental is REFUSED, not guessed at',
   songKeyNormaliseKey('BB') === null,
   'b means flat and B means the note B — "BB" is two note letters. Folding it '
   . 'to Bb would be a coin toss that silently stores the wrong key');
ok('a sharp minor round-trips', songKeyNormaliseKey('F#m') === 'F#m');
ok('an upper-case M minor marker IS lower-cased (M has no competing meaning)',
   songKeyNormaliseKey('CM') === 'Cm');
ok('surrounding whitespace is trimmed', songKeyNormaliseKey('  Eb  ') === 'Eb');
ok('a trailing newline is trimmed, not refused (a transport artefact, not a key)',
   songKeyNormaliseKey("G\n") === 'G');
ok('an EMBEDDED newline is refused (\A…\z, not ^…$)',
   songKeyNormaliseKey("G\nB") === null);

foreach ([
    'H'        => 'H is not a note in this notation',
    'G major'  => 'a word, not a suffix',
    'Gmin'     => 'only a single m is accepted',
    'G##'      => 'a double sharp',
    'Gbb'      => 'a double flat',
    ''         => 'empty',
    '  '       => 'whitespace only',
    'Gm7'      => 'a chord, not a key',
] as $bad => $why) {
    ok('key ' . json_encode($bad) . " is rejected ($why)",
       songKeyNormaliseKey($bad) === null);
}

$r = songKeyValidate(songKeyBody(['original_key' => 'H']));
ok('songKeyValidate reports reason=key_invalid for a bad key',
   $r['ok'] === false && $r['reason'] === 'key_invalid');
$r = songKeyValidate(songKeyBody(['original_key' => '']));
ok('…and reason=key_required for a missing one',
   $r['ok'] === false && $r['reason'] === 'key_required');
$r = songKeyValidate(['song_id' => 'MP-1']);
ok('…including when the field is absent entirely',
   $r['ok'] === false && $r['reason'] === 'key_required');

/* =========================================================================
 * CRASH 3 — Tempo is INT UNSIGNED; the old code (int)-cast whatever arrived.
 * ========================================================================= */
echo "\n-- CRASH 3: Tempo INT UNSIGNED --\n";

$r = songKeyValidate(songKeyBody(['tempo' => -1]));
ok('a NEGATIVE tempo is REJECTED, not cast (MySQL error 1264 otherwise)',
   $r['ok'] === false && $r['reason'] === 'tempo_invalid');
$r = songKeyValidate(songKeyBody(['tempo' => 0]));
ok('zero BPM is rejected', $r['ok'] === false && $r['reason'] === 'tempo_invalid');
$r = songKeyValidate(songKeyBody(['tempo' => 99999]));
ok('an absurd tempo is rejected', $r['ok'] === false && $r['reason'] === 'tempo_invalid');
$r = songKeyValidate(songKeyBody(['tempo' => 'fast']));
ok('a non-numeric tempo is REJECTED, not silently cast to 0',
   $r['ok'] === false && $r['reason'] === 'tempo_invalid',
   'the old (int) cast turned "fast" into 0, storing a lie rather than refusing');

$r = songKeyValidate(songKeyBody(['tempo' => 120]));
ok('120 is accepted as an int', $r['ok'] === true && $r['tempo'] === 120);
$r = songKeyValidate(songKeyBody(['tempo' => '120']));
ok('"120" as a string is accepted and returned as an INT',
   $r['ok'] === true && $r['tempo'] === 120 && is_int($r['tempo']));
$r = songKeyValidate(songKeyBody(['tempo' => 120.0]));
ok('120.0 (JSON has one number type) is accepted',
   $r['ok'] === true && $r['tempo'] === 120);
$r = songKeyValidate(songKeyBody(['tempo' => 120.5]));
ok('120.5 is rejected — a tempo is a whole number',
   $r['ok'] === false && $r['reason'] === 'tempo_invalid');

$r = songKeyValidate(songKeyBody());
ok('an omitted tempo yields null (the column is NULL-able)',
   $r['ok'] === true && $r['tempo'] === null);
$r = songKeyValidate(songKeyBody(['tempo' => '']));
ok('an empty-string tempo (a blank form field) yields null, not a rejection',
   $r['ok'] === true && $r['tempo'] === null);
$r = songKeyValidate(songKeyBody(['tempo' => null]));
ok('an explicit null tempo yields null',
   $r['ok'] === true && $r['tempo'] === null);

ok('the bounds are inclusive at the bottom',
   songKeyNormaliseTempo(SONG_KEY_TEMPO_MIN) === ['ok' => true, 'value' => SONG_KEY_TEMPO_MIN]);
ok('…and at the top',
   songKeyNormaliseTempo(SONG_KEY_TEMPO_MAX) === ['ok' => true, 'value' => SONG_KEY_TEMPO_MAX]);
ok('one below the floor is rejected',
   songKeyNormaliseTempo(SONG_KEY_TEMPO_MIN - 1)['ok'] === false);
ok('one above the ceiling is rejected',
   songKeyNormaliseTempo(SONG_KEY_TEMPO_MAX + 1)['ok'] === false);
ok('a bool tempo is rejected rather than becoming 1',
   songKeyNormaliseTempo(true)['ok'] === false);
ok('an array tempo is rejected',
   songKeyNormaliseTempo([120])['ok'] === false);

/* =========================================================================
 * CRASH 4 — song_id: the FK throws 1452 for an unknown song, and VARCHAR(20)
 * throws 1406 for a long one. The existence check is in api.php (it needs a
 * DB); the LENGTH refusal is here, where it can be called.
 * ========================================================================= */
echo "\n-- song_id --\n";

$r = songKeyValidate(['original_key' => 'G']);
ok('a missing song_id is refused', $r['ok'] === false && $r['reason'] === 'song_id_required');
$r = songKeyValidate(songKeyBody(['song_id' => '   ']));
ok('a whitespace-only song_id is refused',
   $r['ok'] === false && $r['reason'] === 'song_id_required');
$r = songKeyValidate(songKeyBody(['song_id' => str_repeat('A', 21)]));
ok('a song_id over 20 chars is refused, not truncated (MySQL 1406 otherwise)',
   $r['ok'] === false && $r['reason'] === 'song_id_too_long');
$r = songKeyValidate(songKeyBody(['song_id' => str_repeat('A', 20)]));
ok('exactly 20 chars is accepted (the bound is inclusive)', $r['ok'] === true);
$r = songKeyValidate(songKeyBody(['song_id' => '  MP-1008  ']));
ok('a song_id is trimmed', $r['ok'] === true && $r['songId'] === 'MP-1008');
$r = songKeyValidate(songKeyBody(['song_id' => 1008]));
ok('a non-string song_id is refused rather than coerced',
   $r['ok'] === false && $r['reason'] === 'song_id_required');

/* =========================================================================
 * BODY SHAPE — json_decode returns null for a malformed body, and the old
 * handler then did `trim($body['song_id'] ?? '')` on it.
 * ========================================================================= */
echo "\n-- body shape --\n";

foreach ([null, 'a string', 42, true] as $notAnArray) {
    $r = songKeyValidate($notAnArray);
    ok('a non-array body (' . gettype($notAnArray) . ') is refused with reason=body_invalid',
       $r['ok'] === false && $r['reason'] === 'body_invalid');
}

/* =========================================================================
 * REASON CODES ARE THE CONTRACT (rule #35)
 *
 * The endpoint sends `error` for a human and `reason` for a machine. If every
 * rejection carried the same reason, a caller could not tell "your key is
 * wrong" from "that song does not exist", and would be back to matching prose.
 * ========================================================================= */
echo "\n-- reason codes --\n";

$reasons = [];
foreach ([
    null,
    ['original_key' => 'G'],
    ['song_id' => str_repeat('A', 30), 'original_key' => 'G'],
    ['song_id' => 'MP-1'],
    ['song_id' => 'MP-1', 'original_key' => 'H'],
    ['song_id' => 'MP-1', 'original_key' => 'G', 'tempo' => -5],
    ['song_id' => 'MP-1', 'original_key' => 'G', 'time_signature' => 'nope'],
] as $body) {
    $out = songKeyValidate($body);
    if ($out['ok'] === false) { $reasons[] = $out['reason']; }
}
ok('every distinct rejection has its own reason code',
   count($reasons) === count(array_unique($reasons)) && count($reasons) === 7,
   'reasons: ' . implode(', ', $reasons));
ok('a successful validation carries NO reason key',
   !array_key_exists('reason', songKeyValidate(songKeyBody())));
ok('a successful validation carries NO error key',
   !array_key_exists('error', songKeyValidate(songKeyBody())));
ok('every rejection carries a non-empty human `error` too',
   (function (): bool {
       foreach ([null, ['original_key' => 'G'], ['song_id' => 'x'],
                 ['song_id' => 'x', 'original_key' => 'H']] as $b) {
           $o = songKeyValidate($b);
           if (($o['error'] ?? '') === '') { return false; }
       }
       return true;
   })());

if ($fail === 0) {
    echo "\nAll song-key validation behaviour assertions passed.\n";
    exit(0);
}
fwrite(STDERR, "\n{$fail} assertion(s) failed.\n");
exit(1);
