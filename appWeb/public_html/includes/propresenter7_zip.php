<?php

declare(strict_types=1);

/**
 * propresenter7_zip.php — tolerant ZIP64 reader for `.probundle` / `.proplaylist` (#1968 P2)
 * ============================================================================================
 *
 * ELI5
 * ----
 * A `.probundle` is ProPresenter's "everything in one file" export: a ZIP containing one or
 * more `.pro` presentations plus the media (images/video) they use. Normal ZIP readers open a
 * file by jumping straight to its END, reading a "central directory" index that lists every
 * entry, then following pointers back into the file. Real ProPresenter-exported bundles write a
 * CORRUPT version of that end-of-file index (a broken "ZIP64" end-of-central-directory record —
 * see the DETAILED section), so `unzip`, Python's `zipfile`, and PHP's `ZipArchive` all refuse to
 * open them. This file reads a ZIP a completely different way: it walks the file from BYTE 0,
 * entry by entry, using only each entry's own little self-contained header (never touching the
 * broken index at the end) — the same trick every ZIP-writing tool must support because it's how
 * `cat a.zip b.zip > combined.zip`-style streaming writers work in the first place.
 *
 * DETAILED / WHY NOT ZipArchive
 * ------------------------------
 * `.claude/propresenter-interop-1968-plan.md` §4.1 documents the owner's own genuine v21.4
 * `.probundle` export as ZIP64 with a broken end-of-central-directory record that both `unzip`
 * and `ZipArchive`/`zipfile` reject outright ("Corrupt zip64 end of central directory record").
 * That file is not committed to this repo (copyrighted lyric content, owner decision D3 still
 * open — see the plan §12.3) so it cannot be replayed here, but the failure mode is real and
 * documented from the owner's own hands-on verification.
 *
 * The two REAL `.probundle` fixtures that ARE committed here
 * (`tests/fixtures/propresenter/bussnet-testbild.probundle` and
 * `…/bussnet-export-from-pp.probundle`) turned out, on inspection during this implementation, to
 * be small enough that they never actually trip the ZIP64-EOCD path at all — neither contains a
 * ZIP64 end-of-central-directory record or locator (no `PK\x06\x06`/`PK\x07\x07` signature
 * anywhere in either file), and PHP's own `\ZipArchive` opens and reads both of them cleanly (this
 * was tested as part of this task — see `tests/php/test-pp7-zip.php`'s
 * `test_ziparchive_oracle_on_real_fixtures()`, which records the result). That is a genuine
 * deviation from the plan's "verify during implementation, likely rejection" prediction, worth
 * flagging plainly rather than silently — but it does NOT change the design decision here:
 *
 *   **This file's shipped reader depends on `\ZipArchive` for NOTHING.** The only reason
 *   `ZipArchive` "worked" on the committed fixtures is that they are small, byte-for-byte
 *   spec-clean archives with a normal (non-ZIP64) EOCD — i.e. they are NOT representative of the
 *   broken files this feature exists to open. Wiring `ZipArchive` in as an "opportunistic fast
 *   path" would mean the code path CI actually exercises (small clean fixtures) is never the code
 *   path production needs (large broken-EOCD real exports) — exactly the "validated against a
 *   circular / non-representative sample, ships broken" failure class this epic's #1 rule exists
 *   to kill (`.claude/propresenter-interop-1968-plan.md` §8 intro; see also this codebase's
 *   repeated "ONE path, not a fork that silently diverges" convention — CLAUDE.md rules #25/#30/
 *   #39/#41 are the same shape applied to other subsystems). `ZipArchive` IS used, but only
 *   inside the test suite, as an independent cross-validation oracle on the two fixtures it
 *   happens to be able to open — mirroring how `propresenter7_decode.php` is cross-validated
 *   against `protobufjs` (an independent decoder) rather than trusted on its own say-so.
 *
 * WHAT THIS READER DOES AND DOES NOT DO
 * ----------------------------------------
 * Walks `PK\x03\x04` ("local file header") signatures sequentially from byte 0. For each entry it
 * reads the fixed 30-byte header (method, compressed/uncompressed size, name length, extra-field
 * length), the filename, and the extra-field block; when the 32-bit size fields read as the ZIP64
 * sentinel `0xFFFFFFFF` it resolves the true 64-bit sizes from the ZIP64 extra field (header id
 * `0x0001`) instead. It NEVER reads a central-directory record or an end-of-central-directory
 * record — those are exactly the structures real ProPresenter exports corrupt, so a reader that
 * depends on them is a reader that cannot open the files this feature is for. Scanning simply
 * STOPS the moment a signature other than `PK\x03\x04` is met (normally the first central
 * directory header) — bytes beyond that point are never even looked at, which is *why* corruption
 * anywhere in the central directory or EOCD is invisible to this reader (see the mutation-proof
 * note in the test file).
 *
 * @see https://pkware.cachefly.net/webdocs/casestudies/APPNOTE.TXT   §4.3.7 (local file header),
 *      §4.5.3 (the ZIP64 extended-information extra field, incl. the field ORDER rule this file's
 *      `_pp7ZipParseZip64Extra()` follows: only the fields that read 0xFFFFFFFF in the fixed
 *      header are present, in the fixed order original-size THEN compressed-size for a LOCAL
 *      header's copy of this extra field — the central-directory copy also carries a relative
 *      offset + disk-start field this reader never needs, since it never reads a central
 *      directory entry).
 * @see .claude/propresenter-interop-1968-plan.md    §4.1 (this file's contract + design rationale)
 * @see appWeb/public_html/includes/propresenter7_decode.php   the sibling `.pro` protobuf decoder
 *      this file's entries feed into (same pure/DB-free/direct-access-guard posture, deliberately
 *      mirrored rather than reinvented)
 * @see tests/php/test-pp7-zip.php   cross-validation against the real committed `.probundle`
 *      fixtures + a `\ZipArchive` oracle + a synthetic-ZIP64 mutation proof
 *
 * PURE / DB-FREE (mirrors `includes/propresenter7_decode.php` / `includes/song_similarity.php`)
 * -------------------------------------------------------------------------------------------------
 * No `$_SERVER`, no session, no database, no filesystem access inside the reader itself (callers
 * pass in already-read bytes — see `pp7ZipListEntries()`'s `string $bytes` parameter). Every
 * function is a deterministic function of its arguments.
 *
 * DEFENSIVE LIMITS
 * ------------------
 *   - total input ≤ `PP7_ZIP_MAX_INPUT_BYTES` (100 MiB — matches the importer's actual
 *     `bulk_import_probundle` upload cap in api.php; #1977/#1968 P4 raised it from 25 MiB, which
 *     was BELOW that upload cap and silently rejected a legitimate 25–100 MiB media bundle AFTER
 *     it had uploaded fine. A bundle carries MEDIA (motion loops are tens of MiB), so its
 *     whole-input cap is larger than the `.pro` decoder's own `PP7_MAX_INPUT_BYTES` (25 MiB) —
 *     and that is fine: the decoder only ever sees an EXTRACTED inner `.pro`, always small, still
 *     independently bounded by its own 25 MiB cap. Kept as this file's OWN constant rather than a
 *     cross-file reference so this file stays independently includable without a load-order
 *     dependency on the decoder. Memory: the reader is whole-buffer, so peak ≈ bundle + one entry
 *     + one staged copy ≈ 3× — one-line-tunable below if a host affords less);
 *   - entry count ≤ `PP7_ZIP_MAX_ENTRIES` (4096);
 *   - a single entry's declared UNCOMPRESSED size ≤ `PP7_ZIP_MAX_INPUT_BYTES` too — the same cap
 *     reused, because no single entry inside an input this small can legitimately need to inflate
 *     past the size of the whole input cap; this is the cheap defence against a "small compressed
 *     stream, gigabytes when inflated" zip-bomb entry (also independently bounded by
 *     `gzinflate()`'s own `$max_length` argument — belt AND braces, never just the belt);
 *   - every offset/length this reader computes is bounds-checked against the actual buffer before
 *     any `substr()` reads it (`pp7ZipListEntries()`'s own arithmetic — never trusts a declared
 *     length blindly, same posture as `pp7WireWalk()` in the sibling decoder);
 *   - general-purpose bit 3 (the "sizes are deferred to a trailing data descriptor, read the
 *     central directory to find them" flag) is UNSUPPORTED and throws immediately — this reader
 *     deliberately never reads a central directory, so it has no way to resolve a deferred size,
 *     and per the plan §4.1 no real ProPresenter export sets this bit anyway;
 *   - a compression method other than STORED (0) or DEFLATE (8) throws — those are the only two
 *     methods ProPresenter bundles use (plan §4).
 * Malformed input (truncated header, a length that runs past the buffer, an unsupported method or
 * wire shape, zero local file headers found at all) always throws `\InvalidArgumentException`
 * naming a byte offset — never a partial/best-effort result, never a silent wrong read, never a
 * loop that could fail to terminate (every iteration advances `$pos` by at least the 30-byte fixed
 * header's worth, so the scan is bounded by `strlen($bytes) / 30` iterations at the very worst).
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 */

/* Direct access prevention (the convention every includes/ library in this repo carries — see
   e.g. includes/propresenter7_decode.php, includes/arrangement.php). This file is a pure
   library; it is never meant to be requested directly by a browser. */
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

if (!defined('IHYMNS_PP7_ZIP_DEFINED')) {
    define('IHYMNS_PP7_ZIP_DEFINED', true);

    /** Whole-input cap — matches the importer's `bulk_import_probundle` upload cap (100 MiB, api.php);
     *  #1977 raised it from 25 MiB, which sat BELOW the upload cap and rejected a legitimate
     *  25–100 MiB media bundle post-upload. See the file-level doc-block's "DEFENSIVE LIMITS" for
     *  why a bundle's whole-input cap is larger than the `.pro` decoder's own 25 MiB (the decoder
     *  only ever sees the small extracted inner `.pro`), and why this file keeps its own copy. */
    define('PP7_ZIP_MAX_INPUT_BYTES', 100 * 1024 * 1024);

    /** Max local-file-header entries this reader will walk before giving up — a legitimate
     *  `.probundle`/`.proplaylist` carries a handful to a few dozen entries (one or more `.pro`
     *  presentations plus their media); 4096 is generous headroom, not a tight fit, and bounds a
     *  maliciously crafted "millions of zero-length entries" input. */
    define('PP7_ZIP_MAX_ENTRIES', 4096);

    /** Cap on a single entry's DECLARED uncompressed size — reuses `PP7_ZIP_MAX_INPUT_BYTES` (see
     *  its own doc-comment above for why that's a sound, cheap zip-bomb defence at this size). */
    define('PP7_ZIP_MAX_ENTRY_BYTES', PP7_ZIP_MAX_INPUT_BYTES);

    /** The 4-byte signature that opens every local file header this reader looks for. Scanning
     *  stops the instant these 4 bytes stop matching (see the file-level doc-block). */
    define('PP7_ZIP_LOCAL_FILE_HEADER_SIG', "PK\x03\x04");
}

/* ============================================================================================
 * INTERNAL HELPERS
 * ============================================================================================ */

if (!function_exists('_pp7ZipParseZip64Extra')) {
    /**
     * Resolve an entry's TRUE 64-bit uncompressed/compressed sizes from its ZIP64 extra field
     * (header id `0x0001`), for an entry whose 32-bit header fields read the ZIP64 sentinel
     * `0xFFFFFFFF`.
     *
     * ELI5: a normal ZIP entry's size fields are only 32 bits (max ~4 GiB). When a file is
     * bigger than that, the writer puts `0xFFFFFFFF` ("see elsewhere") in those fields and
     * tucks the REAL 64-bit numbers into a little side-note ("extra field") glued on after the
     * filename. This function finds that side-note and reads the real numbers back out.
     *
     * DETAILED — field ORDER is not free-form: per APPNOTE §4.5.3, a LOCAL file header's copy of
     * the ZIP64 extra field contains ONLY the fields that were actually `0xFFFFFFFF` in the fixed
     * header, and always in the order **original (uncompressed) size, then compressed size** —
     * never the reverse, and never with a size present that wasn't flagged. (The CENTRAL
     * directory's copy of this same extra-field id can additionally carry a relative-header-offset
     * and disk-start-number field, but this reader never reads a central directory entry, so those
     * two never apply here.)
     *
     * @param string $extra      the entry's raw extra-field bytes (may contain OTHER extra-field
     *                           blocks too — e.g. Info-ZIP UTF-8 name; this walks past those)
     * @param int    $usize      the 32-bit uncompressed size read from the fixed header (already
     *                           known to be `0xFFFFFFFF` by the caller, OR a real value — passed
     *                           through unchanged when not `0xFFFFFFFF`)
     * @param int    $csize      the 32-bit compressed size read from the fixed header (ditto)
     * @param int    $headerOffset  byte offset of the ENTRY (for error messages only)
     * @return array{0:int,1:int} the resolved [usize, csize]
     * @throws \InvalidArgumentException if a size needs resolving but no ZIP64 extra field is
     *         present, or the extra field is shorter than the sizes it claims to carry
     * @see https://pkware.cachefly.net/webdocs/casestudies/APPNOTE.TXT  §4.5.3
     */
    function _pp7ZipParseZip64Extra(string $extra, int $usize, int $csize, int $headerOffset): array
    {
        $needUsize = ($usize === 0xFFFFFFFF);
        $needCsize = ($csize === 0xFFFFFFFF);
        if (!$needUsize && !$needCsize) {
            return [$usize, $csize];
        }

        $extraLen = strlen($extra);
        $p = 0;
        while ($p + 4 <= $extraLen) {
            $head = unpack('vid/vlen', substr($extra, $p, 4));
            $id = $head['id'];
            $len = $head['len'];
            if ($p + 4 + $len > $extraLen) {
                throw new \InvalidArgumentException(
                    "pp7zip: extra field id {$id} (len {$len}) runs past the extra-field block at byte offset {$headerOffset}"
                );
            }
            $data = substr($extra, $p + 4, $len);

            if ($id === 0x0001) {
                $q = 0;
                if ($needUsize) {
                    if ($q + 8 > strlen($data)) {
                        throw new \InvalidArgumentException(
                            "pp7zip: ZIP64 extra field too short for the uncompressed-size sub-field at byte offset {$headerOffset}"
                        );
                    }
                    $v = unpack('Pv', substr($data, $q, 8));
                    $usize = $v['v'];
                    $q += 8;
                }
                if ($needCsize) {
                    if ($q + 8 > strlen($data)) {
                        throw new \InvalidArgumentException(
                            "pp7zip: ZIP64 extra field too short for the compressed-size sub-field at byte offset {$headerOffset}"
                        );
                    }
                    $v = unpack('Pv', substr($data, $q, 8));
                    $csize = $v['v'];
                    $q += 8;
                }
                return [$usize, $csize];
            }

            $p += 4 + $len;
        }

        throw new \InvalidArgumentException(
            "pp7zip: entry declares a 0xFFFFFFFF size sentinel but carries no ZIP64 extra field (id 0x0001) at byte offset {$headerOffset}"
        );
    }
}

/* ============================================================================================
 * PUBLIC API
 * ============================================================================================ */

if (!function_exists('pp7ZipListEntries')) {
    /**
     * Scan `$bytes` from offset 0 for local file headers and return one descriptor per entry, IN
     * FILE ORDER. Never reads a central directory or end-of-central-directory record — see the
     * file-level doc-block for why.
     *
     * ELI5: hands back a list like "here's an entry called X, it's Y bytes, compressed with
     * method Z, and its actual data starts at byte offset W" for every file packed into the ZIP
     * — without ever looking at the (possibly broken) index at the end of the file.
     *
     * DETAILED — scanning stops the moment 4 bytes that are NOT the local-file-header signature
     * `PK\x03\x04` are met (normally the first central-directory header, `PK\x01\x02`); everything
     * from that point to EOF is simply never read. A buffer that never contains even one valid
     * local file header (garbage input, or a genuinely empty ZIP — this reader has no way to tell
     * those apart, and a `.probundle`/`.proplaylist` is never legitimately empty per the format
     * this file targets) is treated as malformed and throws.
     *
     * @param string $bytes the full raw bytes of a `.probundle`/`.proplaylist` (or any ZIP)
     * @return array<int,array{name:string,method:int,size:int,csize:int,offset:int}> entries in
     *         file order. `size` is the entry's UNCOMPRESSED byte count; `csize` is its
     *         COMPRESSED byte count (equal to `size` for a STORED entry); `offset` is the byte
     *         offset of the entry's compressed DATA (i.e. immediately after its header, name, and
     *         extra field) — pass the whole descriptor straight to `pp7ZipReadEntry()`.
     * @throws \InvalidArgumentException on malformed/truncated input, an unsupported
     *         method/flag combination, or any defensive cap being exceeded — always naming a
     *         byte offset
     */
    function pp7ZipListEntries(string $bytes): array
    {
        $bufLen = strlen($bytes);
        if ($bufLen > PP7_ZIP_MAX_INPUT_BYTES) {
            $maxMib = (int)(PP7_ZIP_MAX_INPUT_BYTES / (1024 * 1024));
            throw new \InvalidArgumentException(
                "pp7zip: input exceeds the {$maxMib} MiB cap ({$bufLen} bytes)"
            );
        }

        $entries = [];
        $pos = 0;

        while ($pos + 4 <= $bufLen) {
            if (substr($bytes, $pos, 4) !== PP7_ZIP_LOCAL_FILE_HEADER_SIG) {
                // Not (or no longer) a local file header — this is where the central directory
                // normally begins. Stop here; never read past this point. See file doc-block.
                break;
            }

            if ($pos + 30 > $bufLen) {
                throw new \InvalidArgumentException(
                    "pp7zip: truncated local file header (need 30 bytes) at byte offset {$pos}"
                );
            }

            $fixed = unpack(
                'vversion/vflags/vmethod/vmtime/vmdate/Vcrc/Vcsize/Vusize/vnlen/velen',
                substr($bytes, $pos + 4, 26)
            );
            $flags = $fixed['flags'];
            $method = $fixed['method'];
            $csize = $fixed['csize'];
            $usize = $fixed['usize'];
            $nlen = $fixed['nlen'];
            $elen = $fixed['elen'];

            if (($flags & 0x0008) !== 0) {
                // General-purpose bit 3: sizes are deferred to a trailing data descriptor, only
                // resolvable via the central directory — which this reader deliberately never
                // reads. Per the plan §4.1, no real ProPresenter export sets this bit.
                throw new \InvalidArgumentException(
                    "pp7zip: entry at byte offset {$pos} uses a deferred data descriptor (general-purpose bit 3), which this reader cannot resolve without the central directory"
                );
            }
            if ($method !== 0 && $method !== 8) {
                throw new \InvalidArgumentException(
                    "pp7zip: unsupported compression method {$method} at byte offset {$pos} (only STORED=0 and DEFLATE=8 are supported)"
                );
            }

            $nameStart = $pos + 30;
            if ($nameStart + $nlen + $elen > $bufLen) {
                throw new \InvalidArgumentException(
                    "pp7zip: entry name/extra field runs past the buffer at byte offset {$nameStart}"
                );
            }
            $name = substr($bytes, $nameStart, $nlen);
            $extra = substr($bytes, $nameStart + $nlen, $elen);

            if ($csize === 0xFFFFFFFF || $usize === 0xFFFFFFFF) {
                [$usize, $csize] = _pp7ZipParseZip64Extra($extra, $usize, $csize, $pos);
            }

            if ($method === 0 && $csize !== $usize) {
                // A STORED entry (no compression) must have identical compressed/uncompressed
                // sizes by definition — a mismatch means a corrupt header, not a legitimate file.
                throw new \InvalidArgumentException(
                    "pp7zip: STORED entry '{$name}' declares csize({$csize}) != usize({$usize}) at byte offset {$pos}"
                );
            }
            if ($usize > PP7_ZIP_MAX_ENTRY_BYTES || $csize > PP7_ZIP_MAX_ENTRY_BYTES) {
                $maxMib = (int)(PP7_ZIP_MAX_ENTRY_BYTES / (1024 * 1024));
                throw new \InvalidArgumentException(
                    "pp7zip: entry '{$name}' declares a size over the {$maxMib} MiB per-entry cap at byte offset {$pos}"
                );
            }

            $dataStart = $nameStart + $nlen + $elen;
            if ($dataStart + $csize > $bufLen) {
                throw new \InvalidArgumentException(
                    "pp7zip: entry '{$name}' data (csize {$csize}) runs past the buffer end at byte offset {$dataStart}"
                );
            }

            $entries[] = [
                'name'   => $name,
                'method' => $method,
                'size'   => $usize,
                'csize'  => $csize,
                'offset' => $dataStart,
            ];

            if (count($entries) > PP7_ZIP_MAX_ENTRIES) {
                throw new \InvalidArgumentException(
                    'pp7zip: exceeded max entry count (' . PP7_ZIP_MAX_ENTRIES . ')'
                );
            }

            // Every entry's header + name + extra + data spans at least 30 bytes (the fixed
            // header alone), so $pos strictly advances every iteration — the scan is bounded by
            // $bufLen / 30 iterations at worst; it cannot loop forever on malformed input.
            $pos = $dataStart + $csize;
        }

        if (empty($entries)) {
            throw new \InvalidArgumentException(
                'pp7zip: no local file headers found at byte offset 0 — not a ZIP this reader recognises (or a genuinely empty archive, which a .probundle/.proplaylist is never expected to be)'
            );
        }

        return $entries;
    }
}

if (!function_exists('pp7ZipReadEntry')) {
    /**
     * Return one entry's DECOMPRESSED bytes, given a descriptor from `pp7ZipListEntries()`.
     *
     * ELI5: takes one item from the list `pp7ZipListEntries()` handed back and actually reads
     * its content out of the file — unpacking it first if it was compressed.
     *
     * @param string $bytes the SAME full buffer `$entry` was produced from
     * @param array{name:string,method:int,size:int,csize:int,offset:int} $entry one descriptor
     *        from `pp7ZipListEntries()` (or hand-built with the same shape)
     * @return string the entry's decompressed (or, for STORED, verbatim) bytes — exactly `size`
     *         bytes long on success
     * @throws \InvalidArgumentException if `$entry` is missing a required key, its offset/csize
     *         run past `$bytes`, its declared method is unsupported, or DEFLATE decompression
     *         fails or produces a length that disagrees with the entry's declared `size`
     */
    function pp7ZipReadEntry(string $bytes, array $entry): string
    {
        foreach (['name', 'method', 'size', 'csize', 'offset'] as $key) {
            if (!array_key_exists($key, $entry)) {
                throw new \InvalidArgumentException(
                    "pp7zip: entry array is missing required key '{$key}' (must come from pp7ZipListEntries())"
                );
            }
        }
        $name = $entry['name'];
        $method = $entry['method'];
        $size = $entry['size'];
        $csize = $entry['csize'];
        $offset = $entry['offset'];

        $bufLen = strlen($bytes);
        if ($offset < 0 || $csize < 0 || $offset + $csize > $bufLen) {
            throw new \InvalidArgumentException(
                "pp7zip: entry '{$name}' data [{$offset}, " . ($offset + $csize) . ") is out of bounds for a {$bufLen}-byte buffer"
            );
        }

        $raw = substr($bytes, $offset, $csize);

        if ($method === 0) {
            // STORED — the bytes on disk ARE the content, verbatim.
            return $raw;
        }

        if ($method === 8) {
            // DEFLATE (raw deflate stream, no zlib/gzip wrapper) — gzinflate() is PHP's matching
            // primitive. Cap the output at the entry's declared size (or the per-entry defensive
            // cap if that size somehow reads as 0) so a maliciously crafted small compressed
            // stream cannot inflate to an unbounded amount of memory — belt-and-braces alongside
            // the per-entry size cap already enforced in pp7ZipListEntries().
            $cap = $size > 0 ? $size : PP7_ZIP_MAX_ENTRY_BYTES;
            $out = @gzinflate($raw, $cap);
            if ($out === false) {
                throw new \InvalidArgumentException(
                    "pp7zip: DEFLATE decompression failed for entry '{$name}' at data offset {$offset}"
                );
            }
            if ($size > 0 && strlen($out) !== $size) {
                // Never a silent wrong read: a length mismatch against the entry's OWN declared
                // size means either a corrupt stream or an over-long bomb that got truncated at
                // the cap above — both are "don't trust this", not "return it anyway".
                throw new \InvalidArgumentException(
                    "pp7zip: entry '{$name}' decompressed to " . strlen($out) . " bytes, expected {$size}, at data offset {$offset}"
                );
            }
            return $out;
        }

        // pp7ZipListEntries() already rejects any other method, so a descriptor built by hand
        // with an unsupported method is the only way to reach this.
        throw new \InvalidArgumentException(
            "pp7zip: unsupported compression method {$method} for entry '{$name}' (only STORED=0 and DEFLATE=8 are supported)"
        );
    }
}

if (!function_exists('pp7ZipExtractAll')) {
    /**
     * Convenience wrapper: list every entry and read every one of them, returning a flat
     * `name => bytes` map.
     *
     * ELI5: "just give me everything in this ZIP as a dictionary of filename to file content."
     *
     * DETAILED — this reads and decompresses EVERY entry eagerly, including media files. For the
     * P2 import flow (`.claude/propresenter-interop-1968-plan.md` §4.2), the actual importer
     * reads media entries LAZILY instead (name + size only, bytes read later only if/when
     * needed) — this function is the simple/eager form for callers (and tests) that want the
     * whole bundle's contents in memory at once; it is bounded by the same per-entry and
     * total-input caps `pp7ZipListEntries()`/`pp7ZipReadEntry()` already enforce.
     *
     * @param string $bytes the full raw bytes of a `.probundle`/`.proplaylist` (or any ZIP)
     * @return array<string,string> entry name => decompressed bytes, in file order
     * @throws \InvalidArgumentException — see `pp7ZipListEntries()` / `pp7ZipReadEntry()`
     */
    function pp7ZipExtractAll(string $bytes): array
    {
        $out = [];
        foreach (pp7ZipListEntries($bytes) as $entry) {
            $out[$entry['name']] = pp7ZipReadEntry($bytes, $entry);
        }
        return $out;
    }
}
