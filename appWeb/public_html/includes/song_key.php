<?php

declare(strict_types=1);

/**
 * iHymns — musical key / tempo / time-signature validation (#298, wired #1671 F3)
 * =============================================================================
 *
 * ELI5
 * ----
 * One place that decides whether a musical key, a tempo and a time signature
 * are sensible, and turns them into the exact shapes the `tblSongKeys` columns
 * can actually store.
 *
 * WHY THIS FILE EXISTS — three ways `song_key_save` threw a 500
 * -------------------------------------------------------------
 * `?action=song_key_save` has been dispatched since #298 and had no caller
 * anywhere, so nothing ever exercised it. Building the UI for it (#1671 F3)
 * immediately hit three crashes, all of the same species: **the handler's caps
 * did not match the column definitions, and mysqli runs under
 * `MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT` so a rejected write THROWS**
 * (CLAUDE.md's "treats `$db->query()` as returning false" red flag, from the
 * other direction — here the statement throws and nothing catches it, so the
 * caller gets an uncaught 500 rather than a 400 telling them what was wrong):
 *
 *   1. `TimeSignature` is `VARCHAR(10) NOT NULL DEFAULT '4/4'` (schema.sql), but
 *      the handler bound **NULL** whenever the field was empty
 *      (`$tsOrNull = $timeSignature !== '' ? $timeSignature : null`). MySQL
 *      answers a NULL into a NOT NULL column with error 1048 — so saving a key
 *      WITHOUT a time signature, the single most ordinary thing a curator would
 *      do, was a guaranteed 500. A DEFAULT does not rescue an EXPLICIT null.
 *   2. `OriginalKey` is `VARCHAR(5)` but the handler capped input at
 *      `mb_substr(…, 0, 10)`. Six characters → error 1406, another 500.
 *   3. `Tempo` is `INT UNSIGNED` and the handler cast with `(int)`. A negative
 *      number → error 1264 out-of-range, a third 500.
 *
 * None of the three is reachable from the UI this file ships alongside, because
 * that UI offers a closed key list and a bounded number input. They are fixed
 * anyway: a native client posting to a documented public endpoint must get a
 * 400 that names the problem, not a 500 that names nothing. Designing the UI
 * around a server bug is how a bug becomes permanent.
 *
 * WHY THE VALIDATION IS A PURE FUNCTION AND NOT INLINE IN api.php
 * ---------------------------------------------------------------
 * Because a guard has to be able to CALL it. `tests/php/test-song-key.php`
 * constructs bodies, calls `songKeyValidate()` and asserts the values that come
 * back. The alternative — a test that greps api.php for the word 'VARCHAR(5)'
 * or for a `!== null` — asserts the file's VOCABULARY, and vocabulary survives
 * any mutation that keeps the words. That is precisely how
 * `songRelocateIsTransactionFatal()` was guarded twice, kept its words, lost
 * its behaviour, and left the whole suite green (see
 * `tests/php/test-transaction-fatal.php`'s header).
 *
 * REASON CODES, NOT PROSE
 * -----------------------
 * Every rejection carries a stable `reason` alongside its human `error`. The
 * test asserts the reason; the endpoint sends the prose. Reword the sentence
 * and nothing breaks — which is the mechanism rule #35 asks for, rather than a
 * comment saying "keep these in sync".
 *
 * @see appWeb/.sql/schema.sql — tblSongKeys (#298)
 * @see appWeb/public_html/api.php — case 'song_key' / case 'song_key_save'
 */

/**
 * `tblSongs.SongId` / `tblSongKeys.SongId` are both `VARCHAR(20)`.
 *
 * The cap here is not cosmetic: it is what stops a long id reaching MySQL and
 * raising error 1406 instead of a 400.
 */
const SONG_KEY_SONG_ID_MAX = 20;

/**
 * Tempo bounds, in beats per minute.
 *
 * The COLUMN is `INT UNSIGNED`, so the only value MySQL itself refuses is a
 * negative one. These bounds are the app-level sanity check on top: 20 BPM is
 * slower than any hymn is ever taken and 400 is faster than any human can sing,
 * so anything outside is a typo or a unit mix-up (milliseconds-per-beat, say)
 * rather than a tempo. Rejecting it is kinder than storing it.
 */
const SONG_KEY_TEMPO_MIN = 20;
const SONG_KEY_TEMPO_MAX = 400;

/**
 * Validate + normalise a `song_key_save` body.
 *
 * ELI5: check the numbers and letters make sense, and tidy them into the exact
 * form the database column expects.
 *
 * @param mixed $body The decoded JSON body (anything — a non-array is handled).
 * @return array{ok:bool, reason?:string, error?:string,
 *                songId?:string, originalKey?:string,
 *                tempo?:int|null, timeSignature?:string}
 *         On success `timeSignature` is ALWAYS a string, never null — see
 *         failure mode (1) in this file's header.
 */
function songKeyValidate(mixed $body): array
{
    if (!is_array($body)) {
        return [
            'ok'     => false,
            'reason' => 'body_invalid',
            'error'  => 'A JSON object body is required.',
        ];
    }

    /* ---- song_id -------------------------------------------------------- */
    $songId = is_string($body['song_id'] ?? null) ? trim($body['song_id']) : '';
    if ($songId === '') {
        return [
            'ok'     => false,
            'reason' => 'song_id_required',
            'error'  => 'song_id is required.',
        ];
    }
    /* mb_strlen, not strlen: the cap is on CHARACTERS because that is what
       VARCHAR(20) counts. A byte-length check would wrongly reject a
       (hypothetical) multi-byte id and wrongly accept one that overflows. */
    if (mb_strlen($songId) > SONG_KEY_SONG_ID_MAX) {
        return [
            'ok'     => false,
            'reason' => 'song_id_too_long',
            'error'  => 'song_id is too long (max ' . SONG_KEY_SONG_ID_MAX . ' characters).',
        ];
    }

    /* ---- original_key --------------------------------------------------- */
    $rawKey = is_string($body['original_key'] ?? null) ? trim($body['original_key']) : '';
    if ($rawKey === '') {
        return [
            'ok'     => false,
            'reason' => 'key_required',
            'error'  => 'original_key is required.',
        ];
    }
    $key = songKeyNormaliseKey($rawKey);
    if ($key === null) {
        return [
            'ok'     => false,
            'reason' => 'key_invalid',
            'error'  => 'original_key must be a note A-G, optionally with # or b, '
                      . 'optionally followed by m for minor — e.g. G, Bb, F#m.',
        ];
    }

    /* ---- tempo ---------------------------------------------------------- */
    $tempoResult = songKeyNormaliseTempo($body['tempo'] ?? null);
    if ($tempoResult['ok'] !== true) {
        return [
            'ok'     => false,
            'reason' => 'tempo_invalid',
            'error'  => 'tempo must be a whole number between '
                      . SONG_KEY_TEMPO_MIN . ' and ' . SONG_KEY_TEMPO_MAX . ' BPM, or omitted.',
        ];
    }

    /* ---- time_signature -------------------------------------------------- */
    $timeSignature = songKeyNormaliseTimeSignature($body['time_signature'] ?? null);
    if ($timeSignature === null) {
        return [
            'ok'     => false,
            'reason' => 'time_signature_invalid',
            'error'  => 'time_signature must look like 4/4, 3/4 or 6/8, or be omitted.',
        ];
    }

    return [
        'ok'            => true,
        'songId'        => $songId,
        'originalKey'   => $key,
        'tempo'         => $tempoResult['value'],
        'timeSignature' => $timeSignature,
    ];
}

/**
 * Normalise a musical key, or null if it is not one.
 *
 * ELI5: accepts things like `g`, `Bb`, `F#m` and tidies them to `G`, `Bb`,
 * `F#m`.
 *
 * The accepted set is deliberately the same one `js/modules/transpose.js`
 * understands — its `flatKeys` set is exactly `F, Bb, Eb, Ab, Db, Gb` plus
 * their minors. Accepting something transpose.js cannot transpose would store a
 * key that makes the +/- buttons produce nonsense, which is worse than
 * refusing it.
 *
 * CASE IS NOT COSMETIC HERE, and the rules are asymmetric on purpose:
 *   - the ROOT is case-insensitive on input and upper-cased on output, so `g`
 *     and `G` cannot become two stored spellings of one key;
 *   - the ACCIDENTAL is not. `b` means flat and `B` means the note B, so `Bb`
 *     is B-flat while `BB` is two note letters and is REFUSED. Guessing which
 *     one somebody meant would be a coin toss that silently stores the wrong
 *     key; refusing is unambiguous, and the editor's closed select means no
 *     curator can reach the refusal by accident.
 * The quality marker IS case-folded (`CM` → `Cm`) because `M` has no competing
 * meaning in this notation.
 *
 * Surrounding whitespace is trimmed first, so a copy-pasted "G\n" is a valid
 * `G` rather than a rejection — that is a transport artefact, not a different
 * key. (Contrast the namespace names in includes/user_settings.php, which are
 * NOT trimmed because there a trailing newline would mint a second, visually
 * identical identity.)
 *
 * Result is at most 3 characters, comfortably inside `VARCHAR(5)`.
 *
 * @param string $raw
 * @return string|null Normalised key, or null when it is not a key.
 */
function songKeyNormaliseKey(string $raw): ?string
{
    $v = trim($raw);
    if ($v === '') { return null; }
    /* `\A…\z` rather than `^…$`. The trim above already removes a TRAILING
       newline, so this is not what stops "G\n"; what it stops is a value whose
       first line is a valid key and whose remainder is not — `^…$` without the
       /m flag still anchors to the whole string in PHP, but `$` tolerates one
       trailing "\n", and an anchor that tolerates anything is the wrong anchor
       for a validator. Same reasoning as the namespace regex in
       includes/user_settings.php. */
    if (!preg_match('/\A([A-Ga-g])([#b]?)([Mm]?)\z/', $v, $m)) {
        return null;
    }
    return strtoupper($m[1]) . strtolower($m[2]) . strtolower($m[3]);
}

/**
 * Normalise a tempo.
 *
 * ELI5: works out the beats-per-minute number, or says the value was nonsense.
 *
 * Absent / null / empty string all mean "not specified" and yield null, which
 * the column accepts (`Tempo INT UNSIGNED NULL`). Anything present must be a
 * whole number in range — a float, a non-numeric string, a bool or an array is
 * a rejection, not a `(int)` cast. The old `(int)` cast turned `"fast"` into 0
 * and `-1` into a write MySQL refused with error 1264.
 *
 * @param mixed $raw
 * @return array{ok:bool, value:int|null}
 */
function songKeyNormaliseTempo(mixed $raw): array
{
    if ($raw === null || $raw === '') {
        return ['ok' => true, 'value' => null];
    }
    if (is_bool($raw) || is_array($raw) || is_object($raw)) {
        return ['ok' => false, 'value' => null];
    }
    if (is_int($raw)) {
        $n = $raw;
    } elseif (is_string($raw) && preg_match('/\A\d{1,6}\z/', trim($raw))) {
        /* Digits only — no sign, no decimal point, no exponent. `is_numeric`
           would accept "1e3", " 12 " and "0x1A"-adjacent shapes depending on
           version, and a tempo is never any of those. */
        $n = (int)trim($raw);
    } elseif (is_float($raw) && floor($raw) === $raw && is_finite($raw)) {
        /* JSON has one number type, so a client sending 120 may arrive as a
           float. An integral float is a tempo; 120.5 is not. */
        $n = (int)$raw;
    } else {
        return ['ok' => false, 'value' => null];
    }
    if ($n < SONG_KEY_TEMPO_MIN || $n > SONG_KEY_TEMPO_MAX) {
        return ['ok' => false, 'value' => null];
    }
    return ['ok' => true, 'value' => $n];
}

/**
 * Normalise a time signature, or null if it is not one.
 *
 * ELI5: accepts `4/4`, `3/4`, `6/8`; anything else is refused.
 *
 * RETURNS `''` — NEVER null — FOR "not specified". `TimeSignature` is
 * `NOT NULL`, so an empty value has to be stored as the empty string; binding
 * null is failure mode (1) in this file's header. The `null` return is
 * reserved, unambiguously, for "this input was invalid".
 *
 * @param mixed $raw
 * @return string|null '' = not specified, 'n/n' = valid, null = invalid.
 */
function songKeyNormaliseTimeSignature(mixed $raw): ?string
{
    if ($raw === null) { return ''; }
    if (!is_string($raw)) { return null; }
    $v = trim($raw);
    if ($v === '') { return ''; }
    /* Beats over a note value. Both bounded to two digits, which keeps the
       result at most 5 characters — well inside VARCHAR(10) — and rules out a
       string long enough to trip MySQL's 1406. */
    if (!preg_match('/\A\d{1,2}\/\d{1,2}\z/', $v)) {
        return null;
    }
    /* A zero either side is not a time signature; it would also render as
       "0/4" on the song page, which reads as broken data rather than as
       missing data. */
    [$beats, $unit] = explode('/', $v, 2);
    if ((int)$beats < 1 || (int)$unit < 1) {
        return null;
    }
    return $v;
}
