<?php

declare(strict_types=1);

/**
 * text_encoding.php — shared raw-bytes-to-UTF-8 detector/converter (#1908 Commit 6)
 * ===================================================================================
 *
 * ELI5: a text file some worship-tool exported might secretly be written in
 * "Windows Unicode" (UTF-16) instead of the UTF-8 the rest of iHymns expects.
 * Read a UTF-16 file as if it were UTF-8 and every character comes out
 * mangled ("中" — one Chinese character — turns into the two bytes `2D 4E`,
 * which just so happen to ALSO be valid UTF-8 for the two ASCII characters
 * "-N", so PHP never even notices anything is wrong). This file is the ONE
 * place that sniffs what a raw upload's bytes actually are and, if it can
 * tell, converts them into real UTF-8 before anything else touches them.
 *
 * DETAILED — the detection ladder, in the order it must run:
 *   1. Empty input is trivially already "UTF-8" (there's nothing to decode).
 *   2. UTF-32 byte-order marks (BOMs) are checked FIRST, not third, even
 *      though UTF-32 is rare in the wild. The UTF-32LE BOM (`FF FE 00 00`)
 *      starts with the EXACT SAME two bytes as the UTF-16LE BOM (`FF FE`) —
 *      it's a UTF-16LE BOM followed by a UTF-16LE-encoded U+0000. Checking
 *      UTF-16 first would misdetect every UTF-32LE file as "UTF-16LE, first
 *      character is NUL", so the 4-byte match MUST be tried before the
 *      2-byte one falls through to it.
 *   3. UTF-16 BOMs (`FF FE` little-endian / `FE FF` big-endian) — strip the
 *      2-byte BOM, convert the remainder.
 *   4. A UTF-8 BOM (`EF BB BF`) is stripped and the remainder is validated
 *      (no conversion needed — it already IS UTF-8, just BOM-prefixed).
 *   5. No BOM at all, and the bytes are ALREADY valid UTF-8: returned
 *      completely UNCHANGED — byte-for-byte identical to the input. This is
 *      the hot path every existing UTF-8 fixture/importer takes today, and
 *      it MUST stay byte-identical: the four callers wired up in
 *      includes/song_importers.php have years of UTF-8 fixtures whose exact
 *      bytes downstream code (title parsing, JSON decoding, DB writes) is
 *      already proven correct against — converting them "through" UTF-8
 *      anyway (even losslessly) would be needless risk for zero benefit.
 *   6. No BOM, and the bytes are NOT valid UTF-8 — last resort: an
 *      interleaved-NUL heuristic. A "Windows Unicode" (UTF-16, no BOM) save
 *      of mostly-ASCII/Latin text has a very distinctive fingerprint: every
 *      OTHER byte is `\x00` (each 16-bit code unit is `<ascii-byte><0x00>`
 *      in little-endian order, or `<0x00><ascii-byte>` in big-endian order).
 *      Sampling the first 1024 bytes and counting how often EACH byte
 *      parity (odd/even offset) is NUL distinguishes the three cases: LOTS
 *      of NULs on odd offsets and few on even ⇒ UTF-16LE; the mirror image
 *      ⇒ UTF-16BE; neither pattern ⇒ genuinely un-decodable, give up.
 *   7. Nothing matched: return `null`. The caller is expected to REJECT the
 *      file with a clear error — silently importing whatever `mb_convert_
 *      encoding()` produces from a wrong guess would be exactly the
 *      mojibake-import bug this file exists to prevent, just moved one step
 *      later. Never invent a "best guess" fallback here.
 *
 * Every rung that performs an actual byte conversion re-validates its OWN
 * output with `mb_check_encoding($out, 'UTF-8')` before returning it. This
 * matters because `mb_convert_encoding()` does not throw on malformed input
 * for a text encoding — misreading a byte stream in the wrong 16/32-bit
 * encoding can still produce a technically-valid (but semantically garbage)
 * UTF-8 string via substitution characters. Re-validating means a rung that
 * guessed wrong (or hit truly corrupt bytes) is caught HERE — the caller
 * only ever sees real, valid UTF-8, or an honest `null`. This function never
 * hands back garbage silently.
 *
 * Framework-free (no $_SERVER / session / DB reads) so it is safe to
 * `require_once` from any importer, the public API, or a CLI migration
 * script alike — the same posture as title_normalize.php.
 *
 * @see appWeb/public_html/includes/song_importers.php  the four parsers this
 *      is wired into: _bulkImport_parseTxt(), _bulkImport_parseVideoPsalmSongbook(),
 *      _bulkImport_parseFreeShow(), _bulkImport_parseIHymnsJson()
 * @see tests/php/test-text-encoding.php  functional truth table + tree-derived wiring guard
 * @link https://www.php.net/manual/en/function.mb-convert-encoding.php
 * @link https://www.php.net/manual/en/function.mb-check-encoding.php
 * @link https://en.wikipedia.org/wiki/Byte_order_mark
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 */

if (!function_exists('ihymnsTextToUtf8')) {
    /**
     * Detect a raw byte string's text encoding and return it as valid UTF-8,
     * or `null` when it cannot be confidently identified as text at all.
     *
     * ELI5: hand this function whatever bytes came off the wire/disk — it
     * hands you back either clean UTF-8 text, or `null` meaning "I can't
     * tell what this is, don't guess."
     *
     * @param string $raw The raw, un-decoded byte string exactly as read
     *                     from the upload (fread()/file_get_contents() —
     *                     NOT already assumed to be any particular encoding).
     * @return string|null The text as UTF-8 (BOM always stripped — a BOM
     *                      is a detection SIGNAL, never content, so it never
     *                      survives into the returned value), or `null` when
     *                      no rung of the ladder could confidently decode it.
     *                      `''` in, `''` out (rung 1) is the one case that
     *                      returns a non-null empty string.
     */
    function ihymnsTextToUtf8(string $raw): ?string
    {
        // Rung 1 — nothing to detect, nothing to convert.
        if ($raw === '') {
            return '';
        }

        /* The one shared "attempt a conversion, then prove it worked" step
           every BOM-bearing / heuristic rung below funnels through. `@` is
           defensive only — mb_convert_encoding() does not throw for
           malformed input, it substitutes, so the REAL safety net is the
           mb_check_encoding() re-validation of its output (see the
           file-level doc-block for why that matters). A rung that converts
           to invalid UTF-8 falls through to null here, never returning it. */
        $convert = static function (string $bytes, string $fromEncoding): ?string {
            $out = @mb_convert_encoding($bytes, 'UTF-8', $fromEncoding);
            if (!is_string($out) || !mb_check_encoding($out, 'UTF-8')) {
                return null;
            }
            return $out;
        };

        // Rung 2 — UTF-32 BOMs, checked BEFORE UTF-16 (see doc-block WHY).
        if (substr($raw, 0, 4) === "\xFF\xFE\x00\x00") {
            return $convert(substr($raw, 4), 'UTF-32LE');
        }
        if (substr($raw, 0, 4) === "\x00\x00\xFE\xFF") {
            return $convert(substr($raw, 4), 'UTF-32BE');
        }

        // Rung 3 — UTF-16 BOMs. Strip the 2-byte BOM before converting.
        if (substr($raw, 0, 2) === "\xFF\xFE") {
            return $convert(substr($raw, 2), 'UTF-16LE');
        }
        if (substr($raw, 0, 2) === "\xFE\xFF") {
            return $convert(substr($raw, 2), 'UTF-16BE');
        }

        // Rung 4 — UTF-8 BOM: strip it, no conversion needed, just validate.
        if (substr($raw, 0, 3) === "\xEF\xBB\xBF") {
            $stripped = substr($raw, 3);
            return mb_check_encoding($stripped, 'UTF-8') ? $stripped : null;
        }

        /* Rung 5 — no BOM, already valid UTF-8: return byte-identical. This
           is the hot path for every existing fixture/importer; do not route
           it through mb_convert_encoding() even as a UTF-8 -> UTF-8 no-op —
           that would risk a substitution-character round-trip that is no
           longer byte-for-byte identical to $raw. */
        if (mb_check_encoding($raw, 'UTF-8')) {
            return $raw;
        }

        /* Rung 6 — no BOM, NOT valid UTF-8: the interleaved-NUL heuristic.
           Sample only the first 1024 bytes (a worship-song file is at most
           a few KB of lyrics; sampling bounds the cost on a pathological
           multi-MB upload without changing the answer for real files). */
        $sample = substr($raw, 0, 1024);
        $len    = strlen($sample);
        $oddNul = 0; $oddTotal = 0;
        $evenNul = 0; $evenTotal = 0;
        for ($i = 0; $i < $len; $i++) {
            if (($i % 2) === 1) {
                $oddTotal++;
                if ($sample[$i] === "\x00") { $oddNul++; }
            } else {
                $evenTotal++;
                if ($sample[$i] === "\x00") { $evenNul++; }
            }
        }
        $oddNulFraction  = $oddTotal  > 0 ? ($oddNul  / $oddTotal)  : 0.0;
        $evenNulFraction = $evenTotal > 0 ? ($evenNul / $evenTotal) : 0.0;

        /* UTF-16LE = <ascii-byte><0x00> pairs -> NULs land on ODD offsets;
           UTF-16BE = <0x00><ascii-byte> pairs -> NULs land on EVEN offsets.
           Both thresholds must hold (the dominant parity is clearly NUL-
           heavy AND the other parity is clearly NOT) so genuinely random /
           corrupt bytes — which have only a ~0.4% chance of any given byte
           being NUL — never cross the 40% bar on EITHER parity and fall
           through to rung 7 instead of being mis-converted. */
        if ($oddNulFraction > 0.40 && $evenNulFraction <= 0.40) {
            return $convert($raw, 'UTF-16LE');
        }
        if ($evenNulFraction > 0.40 && $oddNulFraction <= 0.40) {
            return $convert($raw, 'UTF-16BE');
        }

        // Rung 7 — nothing matched. Reject; never guess, never return garbage.
        return null;
    }
}
