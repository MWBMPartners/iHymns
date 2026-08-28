<?php

declare(strict_types=1);

/**
 * propresenter7_playlist.php — `.proplaylist` / `.probundle`-container decoder for ProPresenter
 * 7+ (#1968 P3 foundation)
 * ============================================================================================
 *
 * ELI5
 * ----
 * A `.proplaylist` is ProPresenter's "service order" file: a ZIP (the same broken-EOCD kind of
 * ZIP a `.probundle` is — see `includes/propresenter7_zip.php`) holding one entry literally
 * named `data` (a protobuf `rv.data.PlaylistDocument` — the tree of playlists/groups/items),
 * plus one `.pro` presentation per song the playlist references, plus any media those songs use.
 * This file does two things: (1) decode that `data` protobuf into a plain, walkable PHP tree,
 * and (2) a small convenience that opens the WHOLE `.proplaylist` ZIP, finds `data`, decodes it,
 * and lists the `.pro`/media entry names sitting alongside it — so a later importer can resolve
 * "this playlist item points at `Embedded Song One.pro`" to the matching ZIP entry.
 *
 * DETAILED / WHY A NEW FILE (not folded into propresenter7_decode.php)
 * ------------------------------------------------------------------------
 * `includes/propresenter7_decode.php`'s own file-level doc-block scopes itself to ONE bare
 * `rv.data.Presentation` message (a `.pro` file's whole content, P1) and explicitly says it does
 * NOT open a ZIP. `includes/propresenter7_zip.php` is the P2 sibling that opens a ZIP but knows
 * nothing about protobuf. This file is the P3 sibling that needs BOTH — it decodes a DIFFERENT
 * top-level protobuf message (`rv.data.PlaylistDocument`, not `rv.data.Presentation`) that lives
 * INSIDE a ZIP entry, so it `require_once`s both existing pure libraries and composes them,
 * exactly the "one file per phase, reuse the previous phases' pure primitives" shape those two
 * files already established (modularity rule — reuse `pp7WireWalk()`/`_pp7Walk()`/
 * `pp7DecodeUuid()`/`pp7DecodeUrl()`/`pp7DecodeApplicationInfo()`/`pp7ZipListEntries()`/
 * `pp7ZipReadEntry()` rather than re-implementing any of them here).
 *
 * ⚠️ THE OWNER'S #1 RULE FOR THIS FEATURE: "no more false positives — validate against real
 * files, never a circular same-schema round-trip." This file is cross-validated in
 * `tests/php/test-pp7-playlist-decode.php` against `protobufjs` (an INDEPENDENT decoder
 * implementation) decoding the SAME real third-party `.proplaylist` fixtures under
 * `tests/fixtures/propresenter/` — not against anything this codebase wrote.
 *
 * SCOPE OF THIS FILE (P3 FOUNDATION ONLY)
 * ------------------------------------------
 * `pp7DecodePlaylistDocument()` and `pp7ReadPlaylistBundle()` are the whole of it: bytes in, a
 * plain PHP array mirroring the protobuf structure (plus the sibling `.pro`/media entry names)
 * out. This file does NOT resolve a `presentation` item's `documentPath` to an actual `.pro`
 * entry (that match-by-url-decoded-basename step is a later importer's job — the plan's §5.1
 * step 3), does NOT create `tblUserSetlists` rows, and does NOT export. Import→set-list wiring
 * and export are explicitly later tasks (plan §5.1/§5.2) — see the task brief this file was
 * built from.
 *
 * PURE / DB-FREE (mirrors `propresenter7_decode.php` / `propresenter7_zip.php` / `song_similarity.php`)
 * -------------------------------------------------------------------------------------------------
 * No `$_SERVER`, no session, no database, no filesystem access beyond the two `require_once`s
 * below (both themselves pure). Every function here is a deterministic function of its bytes
 * argument.
 *
 * ⚠️ UNCONFIRMED CORNERS (no raw PP7-exported `.proplaylist` obtained — plan §5, task brief)
 * -----------------------------------------------------------------------------------------------
 * The three committed real fixtures (`tests/fixtures/propresenter/bussnet-{testplaylist,
 * empty-playlist,sample-service}.proplaylist`, MIT, from `bussnet/propresenter7-php-lib`'s own
 * reference/example samples — themselves noted upstream as synthetic/hand-built test fixtures,
 * NOT captured from a real PP7 "File > Export > Playlist" run) all share ONE shape: `root_node`
 * (name `"PLAYLIST"`) has exactly ONE nested child Playlist via the `playlists` oneof branch
 * (field 12, wrapped in `Playlist.PlaylistArray`), and that ONE child carries every item directly
 * via its `items` oneof branch (field 13). Verified BYTE-FOR-BYTE against protobufjs reflection
 * decoding the same three files (see this file's test) before writing a line of this decoder.
 * Left UNCONFIRMED, and handled TOLERANTLY rather than assumed away:
 *   1. **Always exactly one child playlist under root?** Not verified against a multi-playlist
 *      real export. `pp7DecodePlaylist()` decodes the `playlists` oneof branch as a REPEATED
 *      list (per the schema — `Playlist.PlaylistArray.playlists` is `repeated rv.data.Playlist`,
 *      playlist.proto:51), so more than one is handled correctly if it occurs; nothing here
 *      hard-assumes a single entry.
 *   2. **How does a nested folder (`Playlist.Type.TYPE_GROUP`) export?** None of the three real
 *      fixtures contain a `TYPE_GROUP` node. `pp7DecodePlaylist()` is written RECURSIVELY and
 *      generically — it does not branch on a node's `type` at all when deciding whether to
 *      recurse, it simply decodes whatever `playlists`/`items`/`children` fields that node
 *      actually carries — so an arbitrarily-nested `TYPE_GROUP` (a playlist-of-playlists, several
 *      levels deep) decodes correctly by construction, not because this file special-cased it.
 *      The existing `_pp7Walk()` depth cap (`PP7_MAX_WALK_DEPTH`, from `propresenter7_decode.php`)
 *      bounds runaway/adversarial nesting the same way it already bounds a `.pro`'s message tree.
 *   3. **The flat `children` field (playlist.proto:30, field 9, `repeated Playlist`) vs. the
 *      oneof `playlists` field (field 12, wrapping `PlaylistArray`).** Both are, structurally, "a
 *      list of child Playlists" — the schema offers TWO mechanisms and none of the three real
 *      fixtures ever populate `children`; all three use the oneof `playlists` branch exclusively.
 *      This decoder reads BOTH and merges their entries into the SAME output `playlists[]` array,
 *      in wire order, rather than guessing which one is "the real one" — see `pp7DecodePlaylist()`'s
 *      own doc-block.
 *   4. **`PlaylistItem.Presentation.arrangement_name` (field 5).** ⚠️ **RESOLVED during #1968 P3
 *      EXPORT** (`.claude/propresenter-interop-1968-plan.md` §5.2, "ADOPT: add the field to
 *      playlist.proto additively and rebuild the bundle + static module; wire-compatible") — it
 *      was ABSENT from the vendored `appWeb/public_html/manage/editor/protos/proto-7.16/
 *      playlist.proto` at P3-IMPORT time (this decoder's own original authoring pass, confirmed
 *      by re-reading that exact file, not assumed from the plan's prose; its `Presentation`
 *      message stopped at field 4, `user_music_key`) but IS present in a newer copy of the same
 *      schema (`bussnet/propresenter7-php-lib`'s own vendored `proto/playlist.proto`, which
 *      declares `string arrangement_name = 5;` on the identical message — a wire-compatible
 *      proto3 field ADDITION, never a renumbering), matching the plan's "Pro19+, may be absent in
 *      older files" note. ⚠️ **CORRECTION, verified during P3-IMPORT**: this field is NOT merely
 *      theoretical — all THREE real presentation items across the three committed fixtures
 *      actually carry it on the wire (`"normal"` on two, `"short"` on one). At P3-IMPORT time the
 *      vendored 7.16 schema didn't declare field 5, so a naive protobufjs decode against it
 *      silently dropped the value as an "unknown field" (invisible via `toObject()`) — this
 *      file's own cross-validation test patched the field onto its independent protobufjs schema
 *      in-memory specifically so the comparison was genuine (see `tools/pp7-gen-playlist-
 *      expected.js`'s "A SECOND, smaller deviation" doc-block section, kept for its historical
 *      record even though the patch is no longer the only route to the value). This decoder reads
 *      field 5 as a plain string unconditionally, unchanged by the P3-EXPORT addition — the same
 *      "reads a known field tolerantly regardless of whether the local vendored copy has caught
 *      up" posture the rest of this codebase already applies to every ProPresenter schema corner
 *      (file-level doc-block of `propresenter7_decode.php`: "a future ProPresenter version keeps
 *      decoding cleanly"). Since P3-EXPORT (`appWeb/public_html/manage/editor/protos/
 *      proto-7.16/playlist.proto:116`), the vendored schema now declares it too — see
 *      `PP7_FIELDS_PLAYLIST_ITEM_PRESENTATION['arrangement_name']` below, now cited like every
 *      other field in its table.
 *   5. **`root_node.type`.** The plan's prose describes the root as `TYPE_ROOT`. All three real
 *      fixtures actually encode it as `TYPE_PLAYLIST` (`bussnet-testplaylist`/`-sample-service`)
 *      OR leave the field entirely unset, i.e. the proto3 zero value (`bussnet-empty-playlist`) —
 *      never `TYPE_ROOT` (4). Recorded here as a genuine, verified deviation from the plan's
 *      prose (never trust prose over bytes — task brief). Harmless to this decoder because
 *      nothing here branches on a node's `type` to decide how to recurse (point 2 above) — `type`
 *      is decoded and returned as a plain int for a LATER consumer to interpret if it ever needs
 *      to, never consulted by this file itself.
 *
 * FIELD-NUMBER TABLES — WHERE THEY COME FROM AND HOW THEY STAY HONEST
 * ----------------------------------------------------------------------
 * Same convention as `propresenter7_decode.php`: every field-number constant below carries an
 * inline `// <file>.proto:<line>` citation into the vendored
 * `appWeb/public_html/manage/editor/protos/proto-7.16/*.proto` schema, re-verified line-by-line
 * against that exact file during this task (see the "= [0-9];" greps run at authoring time — not
 * copied from the plan's prose). `tests/php/test-pp7-playlist-decode.php` re-checks every one of
 * these citations against the live vendored file, mirroring `test-pp7-decode.php`'s lockstep
 * guard for the `.pro` decoder (plan §11.3's mechanism, applied here to a second file). ⚠️ Until
 * #1968 P3-EXPORT, `PP7_FIELDS_PLAYLIST_ITEM_PRESENTATION['arrangement_name']` (field 5,
 * UNCONFIRMED corner #4 above) was the ONE deliberate exception — its trailing comment
 * intentionally did NOT match the `file.proto:line` citation shape, because there was no such
 * line to cite in the vendored 7.16 schema at the time, so the lockstep guard correctly skipped
 * it rather than failing on a citation that could not exist. P3-EXPORT added the field to the
 * vendored schema (playlist.proto:116) so its entry below now carries a real citation like every
 * other field — the exception is retired, not just relocated, and
 * `tests/php/test-pp7-playlist-decode.php`'s own "deliberately uncited" floor moved from 1 to 0
 * to match.
 *
 * @see https://protobuf.dev/programming-guides/encoding/                     protobuf wire format
 * @see .claude/propresenter-interop-1968-plan.md                              §5 (this file's design brief), §11.3 (the lockstep-guard mechanism, applied here to a second file)
 * @see appWeb/public_html/manage/editor/protos/proto-7.16/playlist.proto      Playlist / PlaylistItem
 * @see appWeb/public_html/manage/editor/protos/proto-7.16/propresenter.proto  PlaylistDocument
 * @see includes/propresenter7_decode.php                                     pp7WireWalk()/_pp7Walk()/pp7DecodeUuid()/pp7DecodeUrl()/pp7DecodeApplicationInfo() — reused, not duplicated
 * @see includes/propresenter7_zip.php                                        pp7ZipListEntries()/pp7ZipReadEntry() — the tolerant ZIP64 reader this file's pp7ReadPlaylistBundle() builds on
 * @see tests/php/test-pp7-playlist-decode.php                                cross-validation against protobufjs + the field-table lockstep guard
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 */

/* Direct access prevention (the convention every includes/ library in this repo carries — see
   e.g. includes/propresenter7_decode.php, includes/propresenter7_zip.php). This file is a pure
   library; it is never meant to be requested directly by a browser. */
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

/* The two existing pure libraries this file composes — never re-implement anything they already
   provide (modularity rule). Both are themselves guarded (`function_exists`/`defined` checks), so
   requiring them here is safe even if a caller already required one or both beforehand. */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'propresenter7_decode.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'propresenter7_zip.php';

/* ============================================================================================
 * FIELD-NUMBER CONSTANT TABLES
 * ============================================================================================
 * Same shape/convention as propresenter7_decode.php's own tables (one array per protobuf
 * message, keyed by the message's own snake_case field name, valued by its wire field number,
 * each entry trailing-commented `// file.proto:LINE`). Only fields this decoder actually reads
 * are listed — see that file's identical note.
 * ============================================================================================ */

if (!defined('IHYMNS_PP7_PLAYLIST_FIELDS_DEFINED')) {
    define('IHYMNS_PP7_PLAYLIST_FIELDS_DEFINED', true);

    /** rv.data.PlaylistDocument — the top-level message inside a `.proplaylist`'s `data` ZIP
     *  entry (propresenter.proto). `type` is an enum (TYPE_UNKNOWN=0, TYPE_PRESENTATION=1,
     *  TYPE_MEDIA=2, TYPE_AUDIO=3, propresenter.proto:12-15) — this decoder never branches on
     *  it, only passes the raw int through, so no separate enum-value table is defined (mirrors
     *  propresenter7_decode.php's PP7_FIELDS_URL_LOCAL_RELATIVE_PATH precedent: document the
     *  meaning in prose, only table what is actually branched on). */
    define('PP7_FIELDS_PLAYLIST_DOCUMENT', [
        'application_info' => 1, // propresenter.proto:10
        'type'              => 2, // propresenter.proto:17
        'root_node'         => 3, // propresenter.proto:18
    ]);

    /** rv.data.Playlist (playlist.proto). `type` is an enum (TYPE_UNKNOWN=0, TYPE_PLAYLIST=1,
     *  TYPE_GROUP=2, TYPE_SMART=3, TYPE_ROOT=4, playlist.proto:18-22) — again passed through
     *  raw, never branched on (see UNCONFIRMED corner #2/#5 in the file doc-block for why). */
    define('PP7_FIELDS_PLAYLIST', [
        'uuid'      => 1,  // playlist.proto:15
        'name'      => 2,  // playlist.proto:16
        'type'      => 3,  // playlist.proto:24
        'children'  => 9,  // playlist.proto:30  (repeated Playlist — the FLAT nesting mechanism, UNCONFIRMED corner #3)
        'playlists' => 12, // playlist.proto:40  (oneof ChildrenType branch: PlaylistArray)
        'items'     => 13, // playlist.proto:41  (oneof ChildrenType branch: PlaylistItems)
    ]);

    /** rv.data.Playlist.PlaylistArray (nested in playlist.proto) — the oneof-wrapped repeated
     *  Playlist list. */
    define('PP7_FIELDS_PLAYLIST_ARRAY', [
        'playlists' => 1, // playlist.proto:51
    ]);

    /** rv.data.Playlist.PlaylistItems (nested in playlist.proto) — the oneof-wrapped repeated
     *  PlaylistItem list. */
    define('PP7_FIELDS_PLAYLIST_ITEMS', [
        'items' => 1, // playlist.proto:55
    ]);

    /** rv.data.PlaylistItem (playlist.proto). FIVE `oneof ItemType` branches — header/
     *  presentation/cue/planning_center/placeholder — there is no "announcement" item type in
     *  this schema (the plan explicitly flags this; verified directly against the vendored
     *  file, not assumed). `is_hidden` is a plain bool (wire type 0). */
    define('PP7_FIELDS_PLAYLIST_ITEM', [
        'uuid'            => 1, // playlist.proto:79
        'name'            => 2, // playlist.proto:80
        'tags'            => 7, // playlist.proto:81  (repeated UUID — not decoded, no consumer needs it yet)
        'is_hidden'       => 9, // playlist.proto:82
        'header'          => 3, // playlist.proto:84
        'presentation'    => 4, // playlist.proto:85
        'cue'             => 5, // playlist.proto:86  (inline rv.data.Cue — presence-only, not deep-decoded; see pp7DecodePlaylistItem() doc-block)
        'planning_center' => 6, // playlist.proto:87  (presence-only)
        'placeholder'     => 8, // playlist.proto:88  (presence-only)
    ]);

    /** rv.data.PlaylistItem.Header (nested in playlist.proto) — a "section divider" item.
     *  `color` and `actions` are decoded presence/count-only (mirrors propresenter7_decode.php's
     *  `chord_chart`/`timeline` presence-only convention) — nothing in this file's contract needs
     *  the Color message's four floats or Action's full structure decoded. */
    define('PP7_FIELDS_PLAYLIST_ITEM_HEADER', [
        'color'   => 1, // playlist.proto:93  (presence-only: hasColor)
        'actions' => 2, // playlist.proto:94  (repeated Action — presence/count-only: actionCount)
    ]);

    /** rv.data.PlaylistItem.Presentation (nested in playlist.proto). `content_destination`
     *  (field 3, an enum) and `user_music_key` (field 4, an rv.data.MusicKeyScale message) are
     *  real schema fields but not part of this decoder's contract — left undecoded (unknown to
     *  this table, skipped by wire type, same as any other field this decoder doesn't need).
     *  `arrangement_name` (field 5) is UNCONFIRMED corner #4 — genuinely absent from the vendored
     *  schema at THIS decoder's original (P3-IMPORT) authoring time, hence read tolerantly by
     *  number rather than by any schema declaration; #1968 P3-EXPORT subsequently added it to the
     *  vendored playlist.proto (a wire-compatible proto3 addition, needed so the EXPORT-side
     *  encoder can emit it), so it now carries a real citation like every other entry here. */
    define('PP7_FIELDS_PLAYLIST_ITEM_PRESENTATION', [
        'document_path'    => 1, // playlist.proto:98
        'arrangement'      => 2, // playlist.proto:99
        'arrangement_name' => 5, // playlist.proto:116 (Pro19+ addition; absent when this decoder was first authored — UNCONFIRMED corner #4 — adopted into the vendored schema by #1968 P3-EXPORT)
    ]);
}

/* ============================================================================================
 * PER-MESSAGE DECODERS
 * ============================================================================================ */

if (!function_exists('pp7DecodePlaylistItemHeader')) {
    /** rv.data.PlaylistItem.Header → {hasColor:bool, actionCount:int} (presence/count-only —
     *  see PP7_FIELDS_PLAYLIST_ITEM_HEADER's doc-block for why). */
    function pp7DecodePlaylistItemHeader(string $buf, int $depth): array
    {
        $out = ['hasColor' => false, 'actionCount' => 0];
        foreach (_pp7Walk($buf, $depth) as [$fieldNumber, $wireType, $value]) {
            if ($fieldNumber === PP7_FIELDS_PLAYLIST_ITEM_HEADER['color'] && $wireType === 2) {
                $out['hasColor'] = true;
            } elseif ($fieldNumber === PP7_FIELDS_PLAYLIST_ITEM_HEADER['actions'] && $wireType === 2) {
                $out['actionCount']++;
            }
        }
        return $out;
    }
}

if (!function_exists('pp7DecodePlaylistItemPresentation')) {
    /**
     * rv.data.PlaylistItem.Presentation → {documentPath, arrangement:?string, arrangementName:?string}.
     * `documentPath` reuses `pp7DecodeUrl()` from propresenter7_decode.php verbatim — it is the
     * SAME `rv.data.URL` message a `.pro`'s media references already use, so its
     * {absoluteString, localRoot, localPath} shape is identical here (modularity rule: reuse,
     * don't duplicate the URL decoder). `arrangement` reuses `pp7DecodeUuid()` the same way.
     */
    function pp7DecodePlaylistItemPresentation(string $buf, int $depth): array
    {
        $out = [
            'documentPath'    => ['absoluteString' => null, 'localRoot' => null, 'localPath' => null],
            'arrangement'     => null,
            'arrangementName' => null,
        ];
        foreach (_pp7Walk($buf, $depth) as [$fieldNumber, $wireType, $value]) {
            if ($wireType !== 2) {
                continue; // every field this decoder reads on this message is length-delimited
            }
            if ($fieldNumber === PP7_FIELDS_PLAYLIST_ITEM_PRESENTATION['document_path']) {
                $out['documentPath'] = pp7DecodeUrl((string)$value, $depth + 1);
            } elseif ($fieldNumber === PP7_FIELDS_PLAYLIST_ITEM_PRESENTATION['arrangement']) {
                $uuid = pp7DecodeUuid((string)$value, $depth + 1);
                $out['arrangement'] = $uuid !== '' ? $uuid : null;
            } elseif ($fieldNumber === PP7_FIELDS_PLAYLIST_ITEM_PRESENTATION['arrangement_name']) {
                $out['arrangementName'] = (string)$value;
            }
        }
        return $out;
    }
}

if (!function_exists('pp7DecodePlaylistItem')) {
    /**
     * rv.data.PlaylistItem → {uuid, name, isHidden, itemType, header:?array, presentation:?array}.
     *
     * `itemType` is one of `'header'|'presentation'|'cue'|'planningCenter'|'placeholder'|
     * 'unknown'` (the last only if none of the five oneof branches appeared on the wire at all —
     * malformed/degenerate input, never seen in a real fixture). Only `header` and `presentation`
     * carry a decoded sub-array per the §5's contract; `cue`/`planningCenter`/`placeholder` are
     * real, named branches this decoder correctly RECOGNISES (so a caller can warn "N cue items
     * skipped" honestly) but does not deep-decode — `cue` is an inline `rv.data.Cue` (the SAME
     * message a `.pro`'s own slides use, decodable via `pp7DecodeCue()` if a later phase needs
     * its content; not needed for the P3 foundation's contract) and `planning_center`/
     * `placeholder` carry PCO-integration/linked-item data entirely out of this epic's scope.
     */
    function pp7DecodePlaylistItem(string $buf, int $depth): array
    {
        $out = [
            'uuid'         => '',
            'name'         => '',
            'isHidden'     => false,
            'itemType'     => 'unknown',
            'header'       => null,
            'presentation' => null,
        ];
        foreach (_pp7Walk($buf, $depth) as [$fieldNumber, $wireType, $value]) {
            switch ($fieldNumber) {
                case PP7_FIELDS_PLAYLIST_ITEM['uuid']:
                    if ($wireType === 2) { $out['uuid'] = pp7DecodeUuid((string)$value, $depth + 1); }
                    break;
                case PP7_FIELDS_PLAYLIST_ITEM['name']:
                    if ($wireType === 2) { $out['name'] = (string)$value; }
                    break;
                case PP7_FIELDS_PLAYLIST_ITEM['is_hidden']:
                    if ($wireType === 0) { $out['isHidden'] = ((int)$value) !== 0; }
                    break;
                case PP7_FIELDS_PLAYLIST_ITEM['header']:
                    if ($wireType === 2) {
                        $out['itemType'] = 'header';
                        $out['header'] = pp7DecodePlaylistItemHeader((string)$value, $depth + 1);
                    }
                    break;
                case PP7_FIELDS_PLAYLIST_ITEM['presentation']:
                    if ($wireType === 2) {
                        $out['itemType'] = 'presentation';
                        $out['presentation'] = pp7DecodePlaylistItemPresentation((string)$value, $depth + 1);
                    }
                    break;
                case PP7_FIELDS_PLAYLIST_ITEM['cue']:
                    if ($wireType === 2) { $out['itemType'] = 'cue'; }
                    break;
                case PP7_FIELDS_PLAYLIST_ITEM['planning_center']:
                    if ($wireType === 2) { $out['itemType'] = 'planningCenter'; }
                    break;
                case PP7_FIELDS_PLAYLIST_ITEM['placeholder']:
                    if ($wireType === 2) { $out['itemType'] = 'placeholder'; }
                    break;
                default:
                    // Unknown field (e.g. `tags`) — skip by design, per the shared
                    // forward-compat posture (propresenter7_decode.php's file doc-block).
                    break;
            }
        }
        return $out;
    }
}

if (!function_exists('pp7DecodePlaylistItems')) {
    /** rv.data.Playlist.PlaylistItems → the ordered list of pp7DecodePlaylistItem() results. */
    function pp7DecodePlaylistItems(string $buf, int $depth): array
    {
        $items = [];
        foreach (_pp7Walk($buf, $depth) as [$fieldNumber, $wireType, $value]) {
            if ($fieldNumber === PP7_FIELDS_PLAYLIST_ITEMS['items'] && $wireType === 2) {
                $items[] = pp7DecodePlaylistItem((string)$value, $depth + 1);
            }
        }
        return $items;
    }
}

if (!function_exists('pp7DecodePlaylistArray')) {
    /** rv.data.Playlist.PlaylistArray → the ordered list of pp7DecodePlaylist() results
     *  (mutually recursive with pp7DecodePlaylist() below — a Playlist.PlaylistArray's entries
     *  are themselves Playlist messages, which may nest further). */
    function pp7DecodePlaylistArray(string $buf, int $depth): array
    {
        $playlists = [];
        foreach (_pp7Walk($buf, $depth) as [$fieldNumber, $wireType, $value]) {
            if ($fieldNumber === PP7_FIELDS_PLAYLIST_ARRAY['playlists'] && $wireType === 2) {
                $playlists[] = pp7DecodePlaylist((string)$value, $depth + 1);
            }
        }
        return $playlists;
    }
}

if (!function_exists('pp7DecodePlaylist')) {
    /**
     * rv.data.Playlist → {uuid, name, type:int, playlists:[pp7DecodePlaylist,...], items:[pp7DecodePlaylistItem,...]}.
     *
     * ELI5: one node in the playlist tree — either a "folder" (its `playlists` array holds more
     * nodes like this one) or a "leaf playlist" (its `items` array holds the actual songs/
     * headers/etc.), or, per UNCONFIRMED corner #2, potentially BOTH at once (nothing in the
     * schema forbids a node carrying both a nested `playlists` list AND its own `items` list, so
     * this decoder never assumes only one is populated).
     *
     * DETAILED — the TWO nesting mechanisms (UNCONFIRMED corner #3): a Playlist's child list can
     * arrive via the flat `children` field (9, always-present `repeated Playlist`) OR via the
     * oneof `playlists` branch (12, wrapping a `Playlist.PlaylistArray`). This function reads
     * BOTH, in wire order, and appends every child from either into the SAME `playlists[]`
     * output array — never picks one mechanism and ignores the other. Recursion has no explicit
     * "am I a GROUP?" check: whatever fields a node's own bytes actually contain are decoded,
     * so an arbitrarily-nested `TYPE_GROUP` chain falls out of the recursion for free rather than
     * needing a special case (UNCONFIRMED corner #2).
     *
     * @param string $buf   one Playlist submessage's raw bytes
     * @param int    $depth current recursion/nesting depth (passed through to `_pp7Walk()`,
     *                      whose `PP7_MAX_WALK_DEPTH` cap therefore also bounds how deep a
     *                      pathological/adversarial playlist-of-playlists chain can recurse)
     */
    function pp7DecodePlaylist(string $buf, int $depth): array
    {
        $out = [
            'uuid'      => '',
            'name'      => '',
            'type'      => 0,
            'playlists' => [],
            'items'     => [],
        ];
        foreach (_pp7Walk($buf, $depth) as [$fieldNumber, $wireType, $value]) {
            switch ($fieldNumber) {
                case PP7_FIELDS_PLAYLIST['uuid']:
                    if ($wireType === 2) { $out['uuid'] = pp7DecodeUuid((string)$value, $depth + 1); }
                    break;
                case PP7_FIELDS_PLAYLIST['name']:
                    if ($wireType === 2) { $out['name'] = (string)$value; }
                    break;
                case PP7_FIELDS_PLAYLIST['type']:
                    if ($wireType === 0) { $out['type'] = (int)$value; }
                    break;
                case PP7_FIELDS_PLAYLIST['children']:
                    // The FLAT nesting mechanism (UNCONFIRMED corner #3) — merged into the same
                    // `playlists[]` array as the oneof branch below, never a separate list.
                    if ($wireType === 2) { $out['playlists'][] = pp7DecodePlaylist((string)$value, $depth + 1); }
                    break;
                case PP7_FIELDS_PLAYLIST['playlists']:
                    if ($wireType === 2) {
                        foreach (pp7DecodePlaylistArray((string)$value, $depth + 1) as $child) {
                            $out['playlists'][] = $child;
                        }
                    }
                    break;
                case PP7_FIELDS_PLAYLIST['items']:
                    if ($wireType === 2) {
                        foreach (pp7DecodePlaylistItems((string)$value, $depth + 1) as $item) {
                            $out['items'][] = $item;
                        }
                    }
                    break;
                default:
                    break;
            }
        }
        return $out;
    }
}

if (!function_exists('pp7DecodePlaylistDocument')) {
    /**
     * THE only high-level entry point for `.proplaylist` PROTOBUF decode (the `data` ZIP entry's
     * bytes — NOT the ZIP itself; see `pp7ReadPlaylistBundle()` below for the ZIP-aware
     * convenience wrapper). Decodes one bare `rv.data.PlaylistDocument` message.
     *
     * ELI5: hand this the raw bytes of a `.proplaylist`'s `data` entry, get back a plain PHP
     * array with the playlist tree — every folder, every song reference, every section header —
     * still using ProPresenter's own uuids/paths (resolving a song reference to an actual song
     * is a later importer's job, not this decoder's).
     *
     * @param string $bytes the raw bytes of the `data` ZIP entry (a bare PlaylistDocument
     *                      protobuf message, offset 0 to EOF — never the whole `.proplaylist`
     *                      ZIP; use `pp7ReadPlaylistBundle()` for that)
     * @return array{
     *   applicationInfo: array{platform:int, applicationVersion:?string},
     *   type: int,
     *   root: array{uuid:string,name:string,type:int,playlists:array,items:array},
     *   playlists: array<int,array{uuid:string,name:string,type:int,playlists:array,items:array}>
     * }
     *   `root` is the full decoded root_node tree (name is `"PLAYLIST"` on every real fixture
     *   seen); `playlists` is the CONVENIENCE the task's contract asks for — the root's own
     *   immediate children (each of which may itself recurse further via its own `playlists`/
     *   `items`, per UNCONFIRMED corner #2) — with ONE tolerance fallback: if the root carries no
     *   nested playlists at all but DOES carry its own `items` directly (never observed in a real
     *   fixture, but not forbidden by the schema), the root itself is wrapped as the sole
     *   `playlists[]` entry rather than silently dropping its items.
     * @throws \InvalidArgumentException on malformed input, over the shared 25 MiB cap
     *         (`PP7_MAX_INPUT_BYTES`, from propresenter7_decode.php), or on exceeding the max
     *         nesting depth — always naming a byte offset (except the whole-input size cap)
     */
    function pp7DecodePlaylistDocument(string $bytes): array
    {
        $byteLen = strlen($bytes);
        if ($byteLen > PP7_MAX_INPUT_BYTES) {
            $maxMib = (int)(PP7_MAX_INPUT_BYTES / (1024 * 1024));
            throw new \InvalidArgumentException(
                "pp7playlist: PlaylistDocument exceeds the {$maxMib} MiB import cap ({$byteLen} bytes)"
            );
        }

        $out = [
            'applicationInfo' => ['platform' => 0, 'applicationVersion' => null],
            'type'            => 0,
            'root'            => ['uuid' => '', 'name' => '', 'type' => 0, 'playlists' => [], 'items' => []],
        ];

        foreach (_pp7Walk($bytes, 0) as [$fieldNumber, $wireType, $value]) {
            switch ($fieldNumber) {
                case PP7_FIELDS_PLAYLIST_DOCUMENT['application_info']:
                    if ($wireType === 2) { $out['applicationInfo'] = pp7DecodeApplicationInfo((string)$value, 1); }
                    break;
                case PP7_FIELDS_PLAYLIST_DOCUMENT['type']:
                    if ($wireType === 0) { $out['type'] = (int)$value; }
                    break;
                case PP7_FIELDS_PLAYLIST_DOCUMENT['root_node']:
                    if ($wireType === 2) { $out['root'] = pp7DecodePlaylist((string)$value, 1); }
                    break;
                default:
                    // Unknown field (e.g. `tags`, `live_video_playlist`, `downloads_playlist`) —
                    // skip by design, per the shared forward-compat posture.
                    break;
            }
        }

        $out['playlists'] = $out['root']['playlists'];
        if (empty($out['playlists']) && !empty($out['root']['items'])) {
            // Tolerance fallback for an UNCONFIRMED degenerate shape — see this function's
            // doc-block and the file-level "UNCONFIRMED corners" section.
            $out['playlists'] = [$out['root']];
        }

        return $out;
    }
}

/* ============================================================================================
 * ZIP-AWARE CONVENIENCE WRAPPER
 * ============================================================================================ */

if (!function_exists('pp7ReadPlaylistBundle')) {
    /**
     * Open a WHOLE `.proplaylist` (or a `.probundle` — same ZIP container shape) via the
     * tolerant `pp7ZipListEntries()`/`pp7ZipReadEntry()` reader, find its `data` entry, decode it
     * with `pp7DecodePlaylistDocument()`, and list the sibling `.pro`/media entry NAMES (never
     * their bytes — reading every `.pro`'s content is a later importer's job, matching P2's
     * `_bulkImport_processProbundle()` precedent of deferring media bytes and this function's own
     * narrower P3-foundation scope).
     *
     * ELI5: "open this playlist file, tell me what it says, and what other files (songs, media)
     * are sitting next to it inside the ZIP."
     *
     * DETAILED — entry classification mirrors `_bulkImport_probundleClassifyEntries()`
     * (song_importers.php, P2): a directory placeholder (name ends `/`, no real content) is
     * silently dropped from every bucket; the entry named EXACTLY `data` (case-sensitive — every
     * real fixture uses this exact lowercase name, verified byte-for-byte) is the PlaylistDocument
     * and is excluded from both the `.pro` and media buckets; everything whose name ends `.pro`
     * (case-insensitive, last 4 bytes) is a presentation; everything else with real content is
     * media.
     *
     * @param string $probundleBytes the full raw bytes of a `.proplaylist` (or `.probundle`) ZIP
     * @return array{document: array, proEntries: array<int,string>, mediaEntries: array<int,string>}
     *   `document` is `pp7DecodePlaylistDocument()`'s own return shape; `proEntries`/
     *   `mediaEntries` are entry NAMES only, in ZIP order.
     * @throws \InvalidArgumentException if the ZIP cannot be read at all (see
     *         `pp7ZipListEntries()`), if it contains no entry named `data`, or if the `data`
     *         entry's bytes fail to decode as a PlaylistDocument (see
     *         `pp7DecodePlaylistDocument()`) — always naming a byte offset where applicable
     */
    function pp7ReadPlaylistBundle(string $probundleBytes): array
    {
        $entries = pp7ZipListEntries($probundleBytes);

        $dataEntry = null;
        $proEntries = [];
        $mediaEntries = [];
        foreach ($entries as $entry) {
            $name = $entry['name'] ?? '';
            if ($name === '' || substr($name, -1) === '/') {
                continue; // directory placeholder — not a real file
            }
            if ($name === 'data' && $dataEntry === null) {
                $dataEntry = $entry;
                continue;
            }
            if (strtolower(substr($name, -4)) === '.pro') {
                $proEntries[] = $name;
            } else {
                $mediaEntries[] = $name;
            }
        }

        if ($dataEntry === null) {
            throw new \InvalidArgumentException(
                'pp7playlist: no entry named "data" found in the .proplaylist/.probundle ZIP'
            );
        }

        $dataBytes = pp7ZipReadEntry($probundleBytes, $dataEntry);
        $document = pp7DecodePlaylistDocument($dataBytes);

        return [
            'document'     => $document,
            'proEntries'   => $proEntries,
            'mediaEntries' => $mediaEntries,
        ];
    }
}
