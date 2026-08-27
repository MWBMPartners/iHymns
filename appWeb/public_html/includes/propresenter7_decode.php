<?php

declare(strict_types=1);

/**
 * propresenter7_decode.php — server-side proto3 wire decoder for ProPresenter 7+ (#1968 P1)
 * ============================================================================================
 *
 * ELI5
 * ----
 * A ProPresenter `.pro` file is not XML, not JSON — it is raw "protobuf" bytes: a compact,
 * schema-less binary format where every piece of data is tagged "field number N, here are
 * M bytes/a number" with no field NAMES on the wire at all. This file is a small,
 * dependency-free reader for exactly that format, hand-built to understand only the handful
 * of ProPresenter message shapes iHymns' importer needs (song title, section labels, lyric
 * text, arrangement order, CCLI credits) — nothing else in the app can open a `.pro` file
 * without this.
 *
 * DETAILED / WHY A HAND-ROLLED WALKER (not a library)
 * ----------------------------------------------------
 * See `.claude/propresenter-interop-1968-plan.md` §2 for the full options analysis (client
 * decode is CSP-blocked, `google/protobuf` needs Composer which this repo has never used).
 * The short version: proto3's wire format only has 4 shapes we ever see here (varint,
 * 64-bit, length-delimited, 32-bit — https://protobuf.dev/programming-guides/encoding/), our
 * field numbers are byte-verified against the vendored `.proto` schema AND against the
 * owner's real ProPresenter output, and — the load-bearing property — **unknown field
 * numbers are silently skipped**, so a future ProPresenter version that adds fields keeps
 * decoding cleanly rather than erroring.
 *
 * ⚠️ THE OWNER'S #1 RULE FOR THIS FEATURE: "no more false positives — validate against real
 * files, never a circular same-schema round-trip." This file is cross-validated in
 * `tests/php/test-pp7-decode.php` against `protobufjs` (an INDEPENDENT decoder implementation)
 * decoding the SAME real third-party `.pro` fixtures under `tests/fixtures/propresenter/` —
 * not against anything this codebase wrote. See that test + `.claude/propresenter-interop-1968-plan.md`
 * §8 for the full anti-false-positive design.
 *
 * SCOPE OF THIS FILE (PR-1 / P1 only)
 * ------------------------------------
 * `pp7DecodePresentation()` decodes ONE bare `rv.data.Presentation` message — the contents of
 * a single `.pro` file, byte 0 to EOF, no container. It does NOT open `.probundle` (a ZIP) or
 * `.proplaylist` (also a ZIP) — those need a tolerant ZIP64 reader (P2, tracked in the plan's
 * §4/§5) that does not exist yet. It does NOT interpret the decoded structure into iHymns song
 * components (arrangement resolution, section-label mapping, RTF→plain-text) — that is the
 * importer, `_bulkImport_parsePro7()` in `includes/song_importers.php` (also not in this PR;
 * see the plan's §3). This file's ONLY job is: bytes in, a plain PHP array mirroring the
 * protobuf structure out.
 *
 * PURE / DB-FREE (mirrors `includes/song_similarity.php`'s posture)
 * -------------------------------------------------------------------
 * No `$_SERVER`, no session, no database. Every function here is a deterministic function of
 * its bytes argument, which is what lets `tests/php/test-pp7-decode.php` unit-test it directly
 * against committed binary fixtures with no DB fixture at all.
 *
 * FIELD-NUMBER TABLES — WHERE THEY COME FROM AND HOW THEY STAY HONEST
 * ----------------------------------------------------------------------
 * Every field-number constant below carries an inline `// <file>.proto:<line>` citation
 * pointing at the exact line of the vendored schema
 * (`appWeb/public_html/manage/editor/protos/proto-7.16/*.proto`, itself vendored from
 * https://github.com/greyshirtguy/propresenter7-proto, MIT) that field number was read from.
 * These citations are not decoration: `tests/php/test-pp7-decode.php` parses every one of
 * them, re-opens the cited `.proto` file, reads the cited line, and asserts the field name +
 * number declared there still matches this table — so if this table and the vendored schema
 * ever drift (a bad edit here, or the vendored proto being regenerated), CI catches it rather
 * than the walker silently mis-decoding. See plan §11.3 ("decoder field-table lockstep").
 *
 * WIRE TYPES THIS WALKER UNDERSTANDS (proto3 has exactly these four)
 * ---------------------------------------------------------------------
 *   0 = varint              (bool, enum, int32/64, uint32/64 — a variable-length integer)
 *   1 = 64-bit               (fixed64, sfixed64, double)
 *   2 = length-delimited     (string, bytes, embedded message, packed repeated field)
 *   5 = 32-bit               (fixed32, sfixed32, float)
 * (Wire types 3/4 — the deprecated START_GROUP/END_GROUP — are not proto3 and are treated as
 * malformed input; no real ProPresenter file emits them.)
 * @see https://protobuf.dev/programming-guides/encoding/#structure
 *
 * DEFENSIVE LIMITS (plan §2.1)
 * -----------------------------
 *   - total input ≤ PP7_MAX_INPUT_BYTES (25 MiB — matches the importer's upload cap elsewhere
 *     in this codebase, so a file that would be rejected at upload never even reaches here);
 *   - every length-delimited slice is bounds-checked against the buffer it was read from
 *     (`pp7WireWalk()`'s own `$off`/`$len` — never trusts a declared length blindly);
 *   - max recursion depth PP7_MAX_WALK_DEPTH (32) — enforced by `_pp7Walk()`, the internal
 *     depth-counting wrapper every submessage decoder calls instead of `pp7WireWalk()`
 *     directly (`pp7WireWalk()` itself stays the flat 3-argument primitive the plan specifies;
 *     depth bookkeeping lives one layer up, not inside it).
 * Malformed input (truncated varint/slice, a length that runs past the buffer, an unsupported
 * wire type, depth or size over the cap) always throws `\InvalidArgumentException` naming the
 * byte offset — never a partial/best-effort result. The importer that will call this (P1,
 * `_bulkImport_parsePro7()`) turns that into the endpoint's clean 400.
 *
 * UNKNOWN FIELDS ARE NOT AN ERROR
 * ---------------------------------
 * A field number this table does not name is simply skipped (its bytes are still consumed
 * correctly, using its wire type to know how many bytes that is) — this is what lets a file
 * written by a NEWER ProPresenter version (v21.4, v25, …) decode cleanly against this 7.16
 * schema: research confirmed every song-relevant message is wire-identical 7.16.2 → 21.4, all
 * diffs additive (`.claude/propresenter-reference-sources.md` / the PP7-RESEARCH scratchpad).
 *
 * @see https://protobuf.dev/programming-guides/encoding/                     protobuf wire format
 * @see .claude/propresenter-interop-1968-plan.md                              §2.1 (this file's contract), §8 (test strategy), §11.3 (lockstep guard)
 * @see appWeb/public_html/manage/editor/protos/proto-7.16/*.proto             the vendored schema this file mirrors
 * @see tests/php/test-pp7-decode.php                                         cross-validation against protobufjs + the field-table lockstep guard
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 */

/* Direct access prevention (the convention every includes/ library in this repo carries —
   see e.g. includes/arrangement.php). This file is a pure library; it is never meant to be
   requested directly by a browser. */
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

/* ============================================================================================
 * FIELD-NUMBER CONSTANT TABLES
 * ============================================================================================
 * One array per protobuf message, keyed by the message's own (snake_case) field name, valued
 * by its wire field number. Every entry's trailing comment is `<proto file>:<line>`, parsed
 * verbatim by tests/php/test-pp7-decode.php's lockstep guard — keep that format exact
 * (`'field_name' => N, // file.proto:LINE`) if you ever add or renumber an entry.
 *
 * Only fields this decoder actually reads are listed — a message's OTHER real protobuf fields
 * (there are many more on e.g. Cue or Action) are simply unknown fields to this walker and are
 * skipped by wire type, per the file-level doc-block above. Nothing here is a full transcription
 * of the .proto schema; it is exactly the subset the §2.1 contract needs.
 * ============================================================================================ */

if (!defined('IHYMNS_PP7_FIELDS_DEFINED')) {
    define('IHYMNS_PP7_FIELDS_DEFINED', true);

    /** Import safety caps (plan §2.1's "Defensive limits"). */
    define('PP7_MAX_INPUT_BYTES', 25 * 1024 * 1024); // matches the importer's upload cap elsewhere in this codebase
    define('PP7_MAX_WALK_DEPTH', 32);

    /** rv.data.Presentation — the top-level `.pro` message (presentation.proto). */
    define('PP7_FIELDS_PRESENTATION', [
        'application_info'     => 1,  // presentation.proto:18
        'name'                 => 3,  // presentation.proto:20
        'category'             => 6,  // presentation.proto:23
        'notes'                => 7,  // presentation.proto:24
        'chord_chart'          => 9,  // presentation.proto:26  (presence-only: hasChordChart)
        'selected_arrangement' => 10, // presentation.proto:27
        'arrangements'         => 11, // presentation.proto:28
        'cue_groups'           => 12, // presentation.proto:29
        'cues'                 => 13, // presentation.proto:30
        'ccli'                 => 14, // presentation.proto:31
        'timeline'             => 17, // presentation.proto:33  (presence-only: hasTimeline)
    ]);

    /** rv.data.Presentation.Arrangement (nested in presentation.proto). */
    define('PP7_FIELDS_ARRANGEMENT', [
        'uuid'              => 1, // presentation.proto:92
        'name'              => 2, // presentation.proto:93
        'group_identifiers' => 3, // presentation.proto:94
    ]);

    /** rv.data.Presentation.CueGroup (nested in presentation.proto). */
    define('PP7_FIELDS_CUE_GROUP', [
        'group'           => 1, // presentation.proto:98
        'cue_identifiers' => 2, // presentation.proto:99
    ]);

    /** rv.data.Presentation.CCLI (nested in presentation.proto). */
    define('PP7_FIELDS_CCLI', [
        'author'         => 1, // presentation.proto:49
        'artist_credits' => 2, // presentation.proto:50
        'song_title'     => 3, // presentation.proto:51
        'publisher'      => 4, // presentation.proto:52
        'copyright_year' => 5, // presentation.proto:53
        'song_number'    => 6, // presentation.proto:54
    ]);

    /** rv.data.Group — the SECTION LABEL owner (groups.proto). Note: CueGroup itself carries
     *  no uuid of its own; the arrangement↔section link is via THIS message's uuid. */
    define('PP7_FIELDS_GROUP', [
        'uuid' => 1, // groups.proto:10
        'name' => 2, // groups.proto:11
    ]);

    /** rv.data.Cue — one slide (cue.proto). */
    define('PP7_FIELDS_CUE', [
        'uuid'    => 1,  // cue.proto:11
        'actions' => 10, // cue.proto:32
    ]);

    /** rv.data.Action — one cue action (action.proto). `type` picks the oneof branch;
     *  `media`/`slide` are two of that oneof's many branches (only the two this importer
     *  cares about — MEDIA and PRESENTATION_SLIDE — are tabled). */
    define('PP7_FIELDS_ACTION', [
        'type'  => 9,  // action.proto:58
        'media' => 20, // action.proto:64
        'slide' => 23, // action.proto:67
    ]);

    /** rv.data.Action.ActionType enum VALUES (action.proto) — not field numbers, but the same
     *  line-citation + lockstep mechanism applies (a bare `NAME = N;` line parses the same way
     *  a field declaration does). ⚠️ Enum VALUE 23 (ACTION_TYPE_MACRO) and field NUMBER 23
     *  (Action.slide, above) are unrelated numbers in different namespaces — do not conflate. */
    define('PP7_ACTION_TYPE_VALUES', [
        'ACTION_TYPE_MEDIA'              => 2,  // action.proto:33
        'ACTION_TYPE_PRESENTATION_SLIDE' => 11, // action.proto:42
        'ACTION_TYPE_MACRO'              => 23, // action.proto:53
    ]);

    /** rv.data.Action.SlideType (nested in action.proto). Field 1 ("template", a per-slide
     *  theme reference) is reserved/removed in 7.16+ — load-bearing for P5's "no theme
     *  reference exists in a .pro" finding; this walker never looks for it. Only the
     *  `presentation` branch is decoded — `prop` (PropSlide) carries no lyric text. */
    define('PP7_FIELDS_ACTION_SLIDE_TYPE', [
        'presentation' => 2, // action.proto:244
    ]);

    /** rv.data.Action.MediaType (nested in action.proto). */
    define('PP7_FIELDS_ACTION_MEDIA_TYPE', [
        'element' => 5, // action.proto:170
    ]);

    /** rv.data.PresentationSlide (presentationSlide.proto). */
    define('PP7_FIELDS_PRESENTATION_SLIDE', [
        'base_slide' => 1, // presentationSlide.proto:12
    ]);

    /** rv.data.Slide (slide.proto). */
    define('PP7_FIELDS_SLIDE', [
        'elements' => 1, // slide.proto:14
    ]);

    /** rv.data.Slide.Element (nested in slide.proto). `info` is a bitmask:
     *  1 = IS_TEMPLATE_ELEMENT, 2 = IS_TEXT_ELEMENT, 4 = IS_TICKER (research-confirmed; the
     *  vendored .proto declares `info` as a bare uint32, so these bit meanings are not
     *  independently re-verifiable from the schema file itself — only from real decoded
     *  files, where our own export's `info=3` = template|text matches genuine ProPresenter
     *  output). This walker returns the raw int; interpreting the bitmask is the IMPORTER's
     *  job (P1, not this file). */
    define('PP7_FIELDS_SLIDE_ELEMENT', [
        'element' => 1, // slide.proto:23
        'info'    => 4, // slide.proto:26
    ]);

    /** rv.data.Graphics.Element (nested in graphicsData.proto). */
    define('PP7_FIELDS_GRAPHICS_ELEMENT', [
        'text' => 13, // graphicsData.proto:30
    ]);

    /** rv.data.Graphics.Text (nested in graphicsData.proto). `rtf_data` is the lyric text,
     *  dual-dialect RTF (Mac `\cocoartf…` / Windows `\rtf0…` — see the plan §3.6; extracting
     *  plain text from it is the IMPORTER's job, not this file's). */
    define('PP7_FIELDS_GRAPHICS_TEXT', [
        'rtf_data' => 5, // graphicsData.proto:208
    ]);

    /** rv.data.Media (graphicsData.proto, top-level despite living in that file). */
    define('PP7_FIELDS_MEDIA', [
        'url' => 2, // graphicsData.proto:409
    ]);

    /** rv.data.URL (url.proto). `absolute_string` and `local` are each one branch of their
     *  own oneof (Storage / RelativeFilePath) — oneof membership does not change a field's
     *  wire number, so this table is flat like every other. */
    define('PP7_FIELDS_URL', [
        'absolute_string' => 1, // url.proto:15
        'local'            => 4, // url.proto:20
    ]);

    /** rv.data.URL.LocalRelativePath (nested in url.proto). `root` is an enum whose values
     *  this decoder passes through as a raw int (2=USER_HOME, 3=DOCUMENTS, 10=SHOW,
     *  12=CURRENT_RESOURCE per the plan §2.1 — interpreting them is a later phase's job). */
    define('PP7_FIELDS_URL_LOCAL_RELATIVE_PATH', [
        'root' => 1, // url.proto:41
        'path' => 2, // url.proto:42
    ]);

    /** rv.data.ApplicationInfo (applicationInfo.proto). `platform`: 1=macOS, 2=Windows. */
    define('PP7_FIELDS_APPLICATION_INFO', [
        'platform'            => 1, // applicationInfo.proto:13
        'application_version' => 4, // applicationInfo.proto:23
    ]);

    /** rv.data.UUID (uuid.proto) — every UUID in this schema is this one-field wrapper. */
    define('PP7_FIELDS_UUID', [
        'string' => 1, // uuid.proto:7
    ]);

    /** rv.data.Version (version.proto). ⚠️ PLAN DEVIATION: the plan's §2.1 contract describes
     *  `ApplicationInfo.application_version` as a plain string ("4 application_version"). The
     *  vendored schema (confirmed by re-reading applicationInfo.proto + version.proto at
     *  implementation time, per this file's task instructions) actually types it
     *  `rv.data.Version` — a 4-field MESSAGE (major/minor/patch + a separate `build` string),
     *  not a scalar string. `pp7DecodeApplicationInfo()` decodes this submessage and formats
     *  `major.minor[.patch]` into the `applicationVersion` string the §2.1 contract's return
     *  shape promises, so the OUTER shape is unchanged — only the wire-level field type
     *  needed a submessage decode step the plan's prose didn't call out. `build` (a numeric
     *  build string, e.g. "352583705" per the ground-truth samples) is not surfaced; nothing
     *  in P1 consumes it. */
    define('PP7_FIELDS_VERSION', [
        'major_version' => 1, // version.proto:7
        'minor_version' => 2, // version.proto:8
        'patch_version' => 3, // version.proto:9
    ]);
}

/* ============================================================================================
 * LOW-LEVEL WIRE READING
 * ============================================================================================ */

if (!function_exists('pp7ReadVarint')) {
    /**
     * Read one protobuf base-128 varint starting at `$buf[$pos]`.
     *
     * ELI5: protobuf numbers are stored 7 bits at a time — each byte's top bit says "there's
     * more after me", the low 7 bits are the actual digits. This reads bytes until the top bit
     * is finally 0, and glues the digits back together.
     *
     * DETAILED / WHY a 10-byte cap: a varint can encode up to a 64-bit value, and 64 bits at
     * 7 usable bits/byte needs at most 10 bytes (⌈64/7⌉). An 11th continuation byte can only
     * mean malformed or adversarial input, so it throws rather than looping forever.
     *
     * @param string $buf   the buffer being read from (never mutated)
     * @param int    $pos   byte offset to start reading at
     * @param int    $limit exclusive upper bound `$pos` may not reach without a full varint
     *                      (the caller's slice boundary, NOT necessarily `strlen($buf)`)
     * @return array{0:int,1:int} [decoded value, offset just past the varint]
     * @throws \InvalidArgumentException on truncation or a >10-byte varint
     * @see https://protobuf.dev/programming-guides/encoding/#varints
     */
    function pp7ReadVarint(string $buf, int $pos, int $limit): array
    {
        $result = 0;
        $shift = 0;
        $start = $pos;
        $count = 0;
        while (true) {
            if ($pos >= $limit) {
                throw new \InvalidArgumentException("pp7: truncated varint at byte offset {$start}");
            }
            if ($count >= 10) {
                throw new \InvalidArgumentException("pp7: varint longer than 10 bytes at byte offset {$start}");
            }
            $byte = ord($buf[$pos]);
            $pos++;
            $count++;
            $result |= ($byte & 0x7F) << $shift;
            if (($byte & 0x80) === 0) {
                break;
            }
            $shift += 7;
        }
        return [$result, $pos];
    }
}

if (!function_exists('pp7WireWalk')) {
    /**
     * THE low-level proto3 wire reader (plan §2.1's `pp7WireWalk()` contract, verbatim
     * signature). Walks every top-level field inside `$buf[$off .. $off+$len)` and returns
     * them in wire order as `[fieldNumber, wireType, value]` triples — nothing here knows
     * what ANY of these fields mean; that is every caller's job, one layer up.
     *
     * ELI5: hands back "field number 3 said this text", "field 11 said this chunk of bytes",
     * one at a time, for one flat slice of the file — like reading a list of labelled parcels
     * without opening the ones addressed to someone else.
     *
     * DETAILED — the `value` shape depends on wire type:
     *   0 (varint)            → int
     *   1 (64-bit fixed)      → string, exactly 8 raw bytes (unused by this codebase's fields;
     *                            returned raw rather than parsed as a double, since nothing
     *                            here reads a wire-type-1 field)
     *   2 (length-delimited)  → string, the raw sub-buffer (a string/bytes scalar, OR — when
     *                            the caller knows this field is message-typed — the bytes of a
     *                            nested message, ready to hand to `_pp7Walk()` recursively)
     *   5 (32-bit fixed)      → string, exactly 4 raw bytes (ditto: unused, returned raw)
     *
     * Every length-delimited slice is bounds-checked against `$off+$len` — a declared length
     * that would run past the caller's own slice boundary is malformed input, not a bigger
     * buffer to trust.
     *
     * @param string $buf the FULL buffer this offset/length are relative to
     * @param int    $off start of the slice to walk (inclusive)
     * @param int    $len length of the slice to walk
     * @return array<int,array{0:int,1:int,2:int|string}> ordered [fieldNumber, wireType, value] triples
     * @throws \InvalidArgumentException on any malformed/truncated/out-of-bounds read, or an
     *         unsupported (deprecated group) wire type — always naming the byte offset
     * @see https://protobuf.dev/programming-guides/encoding/#structure
     */
    function pp7WireWalk(string $buf, int $off, int $len): array
    {
        $bufLen = strlen($buf);
        if ($off < 0 || $len < 0 || $off + $len > $bufLen) {
            throw new \InvalidArgumentException(
                "pp7: slice [{$off}, " . ($off + $len) . ") is out of bounds for a {$bufLen}-byte buffer"
            );
        }
        $end = $off + $len;
        $pos = $off;
        $out = [];
        while ($pos < $end) {
            $tagStart = $pos;
            [$tag, $pos] = pp7ReadVarint($buf, $pos, $end);
            $fieldNumber = $tag >> 3;
            $wireType = $tag & 0x7;
            if ($fieldNumber <= 0) {
                throw new \InvalidArgumentException("pp7: invalid field number {$fieldNumber} at byte offset {$tagStart}");
            }
            switch ($wireType) {
                case 0: // varint
                    [$value, $pos] = pp7ReadVarint($buf, $pos, $end);
                    break;
                case 1: // 64-bit fixed
                    if ($pos + 8 > $end) {
                        throw new \InvalidArgumentException("pp7: truncated 64-bit field at byte offset {$pos}");
                    }
                    $value = substr($buf, $pos, 8);
                    $pos += 8;
                    break;
                case 2: // length-delimited
                    [$subLen, $pos] = pp7ReadVarint($buf, $pos, $end);
                    if ($subLen < 0 || $pos + $subLen > $end) {
                        throw new \InvalidArgumentException(
                            "pp7: length-delimited field (length {$subLen}) runs past its slice at byte offset {$pos}"
                        );
                    }
                    $value = substr($buf, $pos, $subLen);
                    $pos += $subLen;
                    break;
                case 5: // 32-bit fixed
                    if ($pos + 4 > $end) {
                        throw new \InvalidArgumentException("pp7: truncated 32-bit field at byte offset {$pos}");
                    }
                    $value = substr($buf, $pos, 4);
                    $pos += 4;
                    break;
                default:
                    // Wire types 3/4 are the deprecated proto2 START_GROUP/END_GROUP markers.
                    // No proto3 file (and no real ProPresenter export) emits them; encountering
                    // one means malformed/adversarial input, not a forward-compat unknown field.
                    throw new \InvalidArgumentException(
                        "pp7: unsupported wire type {$wireType} (field {$fieldNumber}) at byte offset {$tagStart}"
                    );
            }
            $out[] = [$fieldNumber, $wireType, $value];
        }
        return $out;
    }
}

if (!function_exists('_pp7Walk')) {
    /**
     * Depth-guarded wrapper around `pp7WireWalk()` for decoding a SUBMESSAGE's own bytes
     * (offset 0, full length) — every `pp7Decode*()` function below calls this, never
     * `pp7WireWalk()` directly, so the max-nesting-depth cap (plan §2.1) is enforced in
     * exactly one place regardless of which message type is recursing.
     *
     * ELI5: before opening yet another parcel-inside-a-parcel, check we haven't already
     * opened 32 in a row — a real ProPresenter file nests about 8 deep at most
     * (Presentation → Cue → Action → PresentationSlide → Slide → Element → Graphics.Element →
     * Graphics.Text), so 32 is generous headroom, not a tight fit; going deeper means a
     * corrupt or adversarial file, not a legitimate one.
     *
     * @param string $buf   one submessage's raw bytes (as returned by a parent's wire walk)
     * @param int    $depth how many submessage levels deep this call is (0 = the top-level
     *                      Presentation message)
     * @throws \InvalidArgumentException if `$depth` exceeds `PP7_MAX_WALK_DEPTH`
     */
    function _pp7Walk(string $buf, int $depth): array
    {
        if ($depth > PP7_MAX_WALK_DEPTH) {
            throw new \InvalidArgumentException('pp7: exceeded max nesting depth (' . PP7_MAX_WALK_DEPTH . ')');
        }
        return pp7WireWalk($buf, 0, strlen($buf));
    }
}

/* ============================================================================================
 * PER-MESSAGE DECODERS (each takes one submessage's raw bytes + the current depth)
 * ============================================================================================ */

if (!function_exists('pp7DecodeUuid')) {
    /**
     * rv.data.UUID{string=1} → the plain UUID string, or '' if absent/empty.
     * ELI5: unwraps the one-field "a UUID is just a string" wrapper every id in this schema uses.
     */
    function pp7DecodeUuid(?string $buf, int $depth): string
    {
        if ($buf === null || $buf === '') {
            return '';
        }
        foreach (_pp7Walk($buf, $depth) as [$fieldNumber, $wireType, $value]) {
            if ($fieldNumber === PP7_FIELDS_UUID['string'] && $wireType === 2) {
                return (string)$value;
            }
        }
        return '';
    }
}

if (!function_exists('pp7DecodeVersionString')) {
    /**
     * rv.data.Version{major_version=1,minor_version=2,patch_version=3} → "major.minor[.patch]".
     * See PP7_FIELDS_VERSION's doc-block for why this submessage decode exists (plan deviation:
     * ApplicationInfo.application_version is a Version message, not a plain string).
     */
    function pp7DecodeVersionString(string $buf, int $depth): ?string
    {
        $major = null;
        $minor = 0;
        $patch = 0;
        foreach (_pp7Walk($buf, $depth) as [$fieldNumber, $wireType, $value]) {
            if ($wireType !== 0) {
                continue;
            }
            if ($fieldNumber === PP7_FIELDS_VERSION['major_version']) {
                $major = (int)$value;
            } elseif ($fieldNumber === PP7_FIELDS_VERSION['minor_version']) {
                $minor = (int)$value;
            } elseif ($fieldNumber === PP7_FIELDS_VERSION['patch_version']) {
                $patch = (int)$value;
            }
        }
        if ($major === null) {
            return null;
        }
        return $patch > 0 ? "{$major}.{$minor}.{$patch}" : "{$major}.{$minor}";
    }
}

if (!function_exists('pp7DecodeApplicationInfo')) {
    /** rv.data.ApplicationInfo → {platform:int, applicationVersion:?string}. */
    function pp7DecodeApplicationInfo(string $buf, int $depth): array
    {
        $platform = 0;
        $applicationVersion = null;
        foreach (_pp7Walk($buf, $depth) as [$fieldNumber, $wireType, $value]) {
            if ($fieldNumber === PP7_FIELDS_APPLICATION_INFO['platform'] && $wireType === 0) {
                $platform = (int)$value;
            } elseif ($fieldNumber === PP7_FIELDS_APPLICATION_INFO['application_version'] && $wireType === 2) {
                $applicationVersion = pp7DecodeVersionString((string)$value, $depth + 1);
            }
        }
        return ['platform' => $platform, 'applicationVersion' => $applicationVersion];
    }
}

if (!function_exists('pp7DecodeCcli')) {
    /** rv.data.Presentation.CCLI → the six fields the §2.1 contract names. */
    function pp7DecodeCcli(string $buf, int $depth): array
    {
        $out = [
            'author'        => '',
            'artistCredits' => '',
            'songTitle'     => '',
            'publisher'     => '',
            'copyrightYear' => null,
            'songNumber'    => null,
        ];
        foreach (_pp7Walk($buf, $depth) as [$fieldNumber, $wireType, $value]) {
            switch ($fieldNumber) {
                case PP7_FIELDS_CCLI['author']:
                    if ($wireType === 2) { $out['author'] = (string)$value; }
                    break;
                case PP7_FIELDS_CCLI['artist_credits']:
                    if ($wireType === 2) { $out['artistCredits'] = (string)$value; }
                    break;
                case PP7_FIELDS_CCLI['song_title']:
                    if ($wireType === 2) { $out['songTitle'] = (string)$value; }
                    break;
                case PP7_FIELDS_CCLI['publisher']:
                    if ($wireType === 2) { $out['publisher'] = (string)$value; }
                    break;
                case PP7_FIELDS_CCLI['copyright_year']:
                    if ($wireType === 0) { $out['copyrightYear'] = (int)$value; }
                    break;
                case PP7_FIELDS_CCLI['song_number']:
                    if ($wireType === 0) { $out['songNumber'] = (int)$value; }
                    break;
            }
        }
        return $out;
    }
}

if (!function_exists('pp7DecodeGroup')) {
    /** rv.data.Group → {uuid, name} — the actual owner of a SECTION LABEL (see PP7_FIELDS_GROUP). */
    function pp7DecodeGroup(string $buf, int $depth): array
    {
        $out = ['uuid' => '', 'name' => ''];
        foreach (_pp7Walk($buf, $depth) as [$fieldNumber, $wireType, $value]) {
            if ($fieldNumber === PP7_FIELDS_GROUP['uuid'] && $wireType === 2) {
                $out['uuid'] = pp7DecodeUuid((string)$value, $depth + 1);
            } elseif ($fieldNumber === PP7_FIELDS_GROUP['name'] && $wireType === 2) {
                $out['name'] = (string)$value;
            }
        }
        return $out;
    }
}

if (!function_exists('pp7DecodeArrangement')) {
    /** rv.data.Presentation.Arrangement → {uuid, name, groupIdentifiers:[uuid,...]}. */
    function pp7DecodeArrangement(string $buf, int $depth): array
    {
        $out = ['uuid' => '', 'name' => '', 'groupIdentifiers' => []];
        foreach (_pp7Walk($buf, $depth) as [$fieldNumber, $wireType, $value]) {
            if ($wireType !== 2) {
                continue;
            }
            if ($fieldNumber === PP7_FIELDS_ARRANGEMENT['uuid']) {
                $out['uuid'] = pp7DecodeUuid((string)$value, $depth + 1);
            } elseif ($fieldNumber === PP7_FIELDS_ARRANGEMENT['name']) {
                $out['name'] = (string)$value;
            } elseif ($fieldNumber === PP7_FIELDS_ARRANGEMENT['group_identifiers']) {
                $out['groupIdentifiers'][] = pp7DecodeUuid((string)$value, $depth + 1);
            }
        }
        return $out;
    }
}

if (!function_exists('pp7DecodeCueGroup')) {
    /**
     * rv.data.Presentation.CueGroup → {groupUuid, groupName, cueIdentifiers:[uuid,...]}.
     * ELI5: a CueGroup is just "here's the Group (which owns the section label) and the
     * ordered list of slide uuids that belong to it" — CueGroup itself has no id of its own.
     */
    function pp7DecodeCueGroup(string $buf, int $depth): array
    {
        $out = ['groupUuid' => '', 'groupName' => '', 'cueIdentifiers' => []];
        foreach (_pp7Walk($buf, $depth) as [$fieldNumber, $wireType, $value]) {
            if ($wireType !== 2) {
                continue;
            }
            if ($fieldNumber === PP7_FIELDS_CUE_GROUP['group']) {
                $g = pp7DecodeGroup((string)$value, $depth + 1);
                $out['groupUuid'] = $g['uuid'];
                $out['groupName'] = $g['name'];
            } elseif ($fieldNumber === PP7_FIELDS_CUE_GROUP['cue_identifiers']) {
                $out['cueIdentifiers'][] = pp7DecodeUuid((string)$value, $depth + 1);
            }
        }
        return $out;
    }
}

if (!function_exists('pp7DecodeUrl')) {
    /** rv.data.URL → {absoluteString:?string, localRoot:?int, localPath:?string}. */
    function pp7DecodeUrl(string $buf, int $depth): array
    {
        $out = ['absoluteString' => null, 'localRoot' => null, 'localPath' => null];
        foreach (_pp7Walk($buf, $depth) as [$fieldNumber, $wireType, $value]) {
            if ($fieldNumber === PP7_FIELDS_URL['absolute_string'] && $wireType === 2) {
                $out['absoluteString'] = (string)$value;
            } elseif ($fieldNumber === PP7_FIELDS_URL['local'] && $wireType === 2) {
                foreach (_pp7Walk((string)$value, $depth + 1) as [$lf, $lw, $lv]) {
                    if ($lf === PP7_FIELDS_URL_LOCAL_RELATIVE_PATH['root'] && $lw === 0) {
                        $out['localRoot'] = (int)$lv;
                    } elseif ($lf === PP7_FIELDS_URL_LOCAL_RELATIVE_PATH['path'] && $lw === 2) {
                        $out['localPath'] = (string)$lv;
                    }
                }
            }
        }
        return $out;
    }
}

if (!function_exists('pp7DecodeMedia')) {
    /** rv.data.Media → the same {absoluteString, localRoot, localPath} shape as pp7DecodeUrl()
     *  (a media reference IS just its URL for this decoder's purposes — resolving it against a
     *  `.probundle` ZIP entry is P2's job, not this file's). */
    function pp7DecodeMedia(string $buf, int $depth): array
    {
        $out = ['absoluteString' => null, 'localRoot' => null, 'localPath' => null];
        foreach (_pp7Walk($buf, $depth) as [$fieldNumber, $wireType, $value]) {
            if ($fieldNumber === PP7_FIELDS_MEDIA['url'] && $wireType === 2) {
                $out = pp7DecodeUrl((string)$value, $depth + 1);
            }
        }
        return $out;
    }
}

if (!function_exists('pp7DecodeActionMediaType')) {
    /** rv.data.Action.MediaType → the resolved media ref, or null if it carries none. */
    function pp7DecodeActionMediaType(string $buf, int $depth): ?array
    {
        foreach (_pp7Walk($buf, $depth) as [$fieldNumber, $wireType, $value]) {
            if ($fieldNumber === PP7_FIELDS_ACTION_MEDIA_TYPE['element'] && $wireType === 2) {
                return pp7DecodeMedia((string)$value, $depth + 1);
            }
        }
        return null;
    }
}

if (!function_exists('pp7DecodeGraphicsText')) {
    /** rv.data.Graphics.Text → the raw rtf_data bytes (still RTF; extracting plain text from
     *  either dialect is the importer's job — see the plan §3.6, not part of this PR). */
    function pp7DecodeGraphicsText(string $buf, int $depth): string
    {
        foreach (_pp7Walk($buf, $depth) as [$fieldNumber, $wireType, $value]) {
            if ($fieldNumber === PP7_FIELDS_GRAPHICS_TEXT['rtf_data'] && $wireType === 2) {
                return (string)$value;
            }
        }
        return '';
    }
}

if (!function_exists('pp7DecodeGraphicsElementRtf')) {
    /** rv.data.Graphics.Element → the rtf_data of its `text` sub-field, or '' when this
     *  element carries no text (e.g. a background shape/image element). */
    function pp7DecodeGraphicsElementRtf(string $buf, int $depth): string
    {
        foreach (_pp7Walk($buf, $depth) as [$fieldNumber, $wireType, $value]) {
            if ($fieldNumber === PP7_FIELDS_GRAPHICS_ELEMENT['text'] && $wireType === 2) {
                return pp7DecodeGraphicsText((string)$value, $depth + 1);
            }
        }
        return '';
    }
}

if (!function_exists('pp7DecodeSlideElement')) {
    /**
     * rv.data.Slide.Element → {info:int, rtf:string}. Returns EVERY element on the slide
     * (not just text elements) with its raw `info` bitmask + its rtf_data (empty string for a
     * non-text element) — selecting which element is the real lyric text (bit 2 = IS_TEXT_ELEMENT,
     * translation-layer elements beyond the first, …) is the importer's job (plan §3.4), not
     * this decoder's.
     */
    function pp7DecodeSlideElement(string $buf, int $depth): array
    {
        $info = 0;
        $rtf = '';
        foreach (_pp7Walk($buf, $depth) as [$fieldNumber, $wireType, $value]) {
            if ($fieldNumber === PP7_FIELDS_SLIDE_ELEMENT['info'] && $wireType === 0) {
                $info = (int)$value;
            } elseif ($fieldNumber === PP7_FIELDS_SLIDE_ELEMENT['element'] && $wireType === 2) {
                $rtf = pp7DecodeGraphicsElementRtf((string)$value, $depth + 1);
            }
        }
        return ['info' => $info, 'rtf' => $rtf];
    }
}

if (!function_exists('pp7DecodeSlide')) {
    /** rv.data.Slide → the ordered list of pp7DecodeSlideElement() results. */
    function pp7DecodeSlide(string $buf, int $depth): array
    {
        $elements = [];
        foreach (_pp7Walk($buf, $depth) as [$fieldNumber, $wireType, $value]) {
            if ($fieldNumber === PP7_FIELDS_SLIDE['elements'] && $wireType === 2) {
                $elements[] = pp7DecodeSlideElement((string)$value, $depth + 1);
            }
        }
        return $elements;
    }
}

if (!function_exists('pp7DecodePresentationSlide')) {
    /** rv.data.PresentationSlide → its base_slide's elements (pp7DecodeSlide()'s output). */
    function pp7DecodePresentationSlide(string $buf, int $depth): array
    {
        $elements = [];
        foreach (_pp7Walk($buf, $depth) as [$fieldNumber, $wireType, $value]) {
            if ($fieldNumber === PP7_FIELDS_PRESENTATION_SLIDE['base_slide'] && $wireType === 2) {
                $elements = pp7DecodeSlide((string)$value, $depth + 1);
            }
        }
        return $elements;
    }
}

if (!function_exists('pp7DecodeActionSlideType')) {
    /** rv.data.Action.SlideType → the `presentation` branch's elements, or [] for a `prop`
     *  slide (props carry no lyric text and are out of scope for song import). */
    function pp7DecodeActionSlideType(string $buf, int $depth): array
    {
        $elements = [];
        foreach (_pp7Walk($buf, $depth) as [$fieldNumber, $wireType, $value]) {
            if ($fieldNumber === PP7_FIELDS_ACTION_SLIDE_TYPE['presentation'] && $wireType === 2) {
                $elements = pp7DecodePresentationSlide((string)$value, $depth + 1);
            }
        }
        return $elements;
    }
}

if (!function_exists('pp7DecodeAction')) {
    /**
     * rv.data.Action → a small internal summary of the ONE thing this decoder cares about a
     * given action for: is it a PRESENTATION_SLIDE (→ its elements) or a MEDIA action (→ its
     * media ref)? Every other action type (MACRO, TIMER, CLEAR, …) is real per the schema but
     * carries nothing this importer reads, so it comes back as `kind:'other'`.
     *
     * @return array{kind:'slide'|'media'|'other', elements:array, media:?array}
     */
    function pp7DecodeAction(string $buf, int $depth): array
    {
        $type = null;
        $elements = [];
        $media = null;
        foreach (_pp7Walk($buf, $depth) as [$fieldNumber, $wireType, $value]) {
            if ($fieldNumber === PP7_FIELDS_ACTION['type'] && $wireType === 0) {
                $type = (int)$value;
            } elseif ($fieldNumber === PP7_FIELDS_ACTION['slide'] && $wireType === 2) {
                $elements = pp7DecodeActionSlideType((string)$value, $depth + 1);
            } elseif ($fieldNumber === PP7_FIELDS_ACTION['media'] && $wireType === 2) {
                $media = pp7DecodeActionMediaType((string)$value, $depth + 1);
            }
        }
        if ($type === PP7_ACTION_TYPE_VALUES['ACTION_TYPE_PRESENTATION_SLIDE']) {
            return ['kind' => 'slide', 'elements' => $elements, 'media' => null];
        }
        if ($type === PP7_ACTION_TYPE_VALUES['ACTION_TYPE_MEDIA']) {
            return ['kind' => 'media', 'elements' => [], 'media' => $media];
        }
        return ['kind' => 'other', 'elements' => [], 'media' => null];
    }
}

if (!function_exists('pp7DecodeCue')) {
    /**
     * rv.data.Cue → {uuid, slideRtf:[string,...], slideElementInfos:[int,...], mediaRefs:[array,...]}
     * (the §2.1 per-cue shape). `slideRtf`/`slideElementInfos` are parallel arrays — index N of
     * one corresponds to index N of the other — flattened across every PRESENTATION_SLIDE
     * action on this cue (in practice there is exactly one).
     */
    function pp7DecodeCue(string $buf, int $depth): array
    {
        $uuid = '';
        $slideRtf = [];
        $slideElementInfos = [];
        $mediaRefs = [];
        foreach (_pp7Walk($buf, $depth) as [$fieldNumber, $wireType, $value]) {
            if ($fieldNumber === PP7_FIELDS_CUE['uuid'] && $wireType === 2) {
                $uuid = pp7DecodeUuid((string)$value, $depth + 1);
            } elseif ($fieldNumber === PP7_FIELDS_CUE['actions'] && $wireType === 2) {
                $action = pp7DecodeAction((string)$value, $depth + 1);
                if ($action['kind'] === 'slide') {
                    foreach ($action['elements'] as $el) {
                        $slideRtf[] = $el['rtf'];
                        $slideElementInfos[] = $el['info'];
                    }
                } elseif ($action['kind'] === 'media' && $action['media'] !== null) {
                    $mediaRefs[] = $action['media'];
                }
            }
        }
        return [
            'uuid'              => $uuid,
            'slideRtf'          => $slideRtf,
            'slideElementInfos' => $slideElementInfos,
            'mediaRefs'         => $mediaRefs,
        ];
    }
}

if (!function_exists('pp7DecodePresentation')) {
    /**
     * THE only high-level entry point for `.pro` decode (plan §2.1). Decodes one bare
     * `rv.data.Presentation` message (the whole content of a `.pro` file, byte 0 to EOF).
     *
     * ELI5: hand this the raw bytes of a `.pro` file, get back a plain PHP array with the
     * song's title, its section palette (arrangements/cueGroups/cues), its lyric text (still
     * as RTF — nobody has stripped the formatting yet) and its CCLI credit block.
     *
     * DETAILED: this function does NOT interpret the result into an iHymns song (no RTF→text,
     * no arrangement-index resolution, no section-label→type mapping) — that is
     * `_bulkImport_parsePro7()`, a later PR. This is purely "decode the protobuf faithfully."
     *
     * @param string $bytes the full raw contents of a `.pro` file
     * @return array{
     *   applicationInfo: array{platform:int, applicationVersion:?string},
     *   name: string, category: string, notes: string,
     *   selectedArrangement: ?string,
     *   arrangements: array<int,array{uuid:string,name:string,groupIdentifiers:array<int,string>}>,
     *   cueGroups: array<int,array{groupUuid:string,groupName:string,cueIdentifiers:array<int,string>}>,
     *   cues: array<int,array{uuid:string,slideRtf:array<int,string>,slideElementInfos:array<int,int>,mediaRefs:array<int,array>}>,
     *   ccli: array{author:string,artistCredits:string,songTitle:string,publisher:string,copyrightYear:?int,songNumber:?int},
     *   hasTimeline: bool, hasChordChart: bool
     * }
     * @throws \InvalidArgumentException on malformed input, over the 25 MiB cap, or on
     *         exceeding the max nesting depth — always naming a byte offset (except the
     *         whole-file size cap, which has no single offset to name)
     */
    function pp7DecodePresentation(string $bytes): array
    {
        $byteLen = strlen($bytes);
        if ($byteLen > PP7_MAX_INPUT_BYTES) {
            $maxMib = (int)(PP7_MAX_INPUT_BYTES / (1024 * 1024));
            throw new \InvalidArgumentException(
                "pp7: ProPresenter document exceeds the {$maxMib} MiB import cap ({$byteLen} bytes)"
            );
        }

        $out = [
            'applicationInfo'     => ['platform' => 0, 'applicationVersion' => null],
            'name'                => '',
            'category'            => '',
            'notes'               => '',
            'selectedArrangement' => null,
            'arrangements'        => [],
            'cueGroups'           => [],
            'cues'                => [],
            'ccli'                => [
                'author' => '', 'artistCredits' => '', 'songTitle' => '', 'publisher' => '',
                'copyrightYear' => null, 'songNumber' => null,
            ],
            'hasTimeline'         => false,
            'hasChordChart'       => false,
        ];

        foreach (_pp7Walk($bytes, 0) as [$fieldNumber, $wireType, $value]) {
            switch ($fieldNumber) {
                case PP7_FIELDS_PRESENTATION['application_info']:
                    if ($wireType === 2) { $out['applicationInfo'] = pp7DecodeApplicationInfo((string)$value, 1); }
                    break;
                case PP7_FIELDS_PRESENTATION['name']:
                    if ($wireType === 2) { $out['name'] = (string)$value; }
                    break;
                case PP7_FIELDS_PRESENTATION['category']:
                    if ($wireType === 2) { $out['category'] = (string)$value; }
                    break;
                case PP7_FIELDS_PRESENTATION['notes']:
                    if ($wireType === 2) { $out['notes'] = (string)$value; }
                    break;
                case PP7_FIELDS_PRESENTATION['chord_chart']:
                    // Presence-only (rv.data.URL submessage) — P6 fodder, not interpreted here.
                    if ($wireType === 2) { $out['hasChordChart'] = true; }
                    break;
                case PP7_FIELDS_PRESENTATION['selected_arrangement']:
                    if ($wireType === 2) {
                        $uuid = pp7DecodeUuid((string)$value, 1);
                        $out['selectedArrangement'] = $uuid !== '' ? $uuid : null;
                    }
                    break;
                case PP7_FIELDS_PRESENTATION['arrangements']:
                    if ($wireType === 2) { $out['arrangements'][] = pp7DecodeArrangement((string)$value, 1); }
                    break;
                case PP7_FIELDS_PRESENTATION['cue_groups']:
                    if ($wireType === 2) { $out['cueGroups'][] = pp7DecodeCueGroup((string)$value, 1); }
                    break;
                case PP7_FIELDS_PRESENTATION['cues']:
                    if ($wireType === 2) { $out['cues'][] = pp7DecodeCue((string)$value, 1); }
                    break;
                case PP7_FIELDS_PRESENTATION['ccli']:
                    if ($wireType === 2) { $out['ccli'] = pp7DecodeCcli((string)$value, 1); }
                    break;
                case PP7_FIELDS_PRESENTATION['timeline']:
                    // Presence-only (rv.data.Presentation.Timeline submessage) — P6 fodder.
                    if ($wireType === 2) { $out['hasTimeline'] = true; }
                    break;
                default:
                    // Unknown field number — skip by design (forward-compat with newer
                    // ProPresenter schema versions; see the file-level doc-block).
                    break;
            }
        }

        return $out;
    }
}
