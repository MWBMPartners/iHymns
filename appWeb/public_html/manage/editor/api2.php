<?php

declare(strict_types=1);

/**
 * iHymns — Song Editor v2 API (#1200)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * A clean, purpose-built, GRANULAR editor backend — every edit is its own
 * atomic, CSRF-guarded, audited MySQL write. This replaces the legacy
 * whole-song `save_song` model (and the auto/manual save RACE that corrupted
 * songs, #1178). It is deliberately NOT bolted onto the 7,800-line legacy
 * `api.php`; the owner approved a clean editor API, with the broader public/PWA
 * API + OpenAPI docs to be redone next (tracked follow-up).
 *
 * Wire format: `action` via query string (GET reads) or JSON body (POST writes).
 * Every POST requires the `X-Requested-With: XMLHttpRequest` header — the same
 * same-origin AJAX CSRF defence as the public api.php (#293), chosen over a
 * per-form session token because the embedded token went stale / absent under
 * the cross-subdomain token-adopted admin session and 403'd every POST (#1307).
 * All values are bound (`bind_param`), every
 * mutation writes a `logActivity` row + a coalesced `tblSongRevisions` snapshot,
 * and every response is `{ ok, ... }` with the TRUE result (never a false
 * success — the lesson from the client-only `deleteSong()` that lied).
 *
 * Actions:
 *   GET  load_index                                         -> { ok, songs, songbooks }
 *                               songs = the slim sidebar index; songbooks =
 *                               [{abbr,name}] for EVERY book incl. empty ones
 *                               (#1679 A2 — the move target list cannot come
 *                               from the song index, or a new book is unreachable)
 *   GET  easyworship_export?id=<SongId>|abbr=<BOOK>[&maxLinesPerSlide=N] -> streams an EasyWorship Songs.db (#1059/#1678)
 *   POST bulk_verify            { songIds:[...], verified? }  -> { ok, count, verified }
 *   POST bulk_tag_attach        { songIds:[...], name }       -> { ok, tag, attached, count }
 *   POST bulk_move   { songIds:[...], targetAbbr }  -> { ok, moved:[{oldId,newId}], failed:[{id,error,status}] }
 *                               #1628 item 3 — each song re-keyed through the SAME
 *                               songRelocate() core the single-song
 *                               metadata_field_update(field=songbook) branch uses
 *                               (#1679 option B). PER-SONG verdicts — one bad row
 *                               never aborts the batch. `targetAbbr` is validated
 *                               ONCE up front (422 on an unknown book, before the
 *                               loop); every OTHER per-song failure lands in
 *                               `failed`, never a 500 for the whole request.
 *                               `moved` carries the NEW ids — option B means every
 *                               selected id is stale the instant this returns.
 *   POST bulk_delete { songIds:[...], reason?, note? } -> { ok, deleted:[ids], failed:[{id,error,status}] }
 *                               #1628 item 3 — each song SOFT-deleted through the
 *                               SAME songSoftDelete() core the single-song
 *                               delete_song case uses (#1694); restorable from
 *                               /manage/deleted-songs. Entitlement is
 *                               `delete_songs` (the single-delete gate), not
 *                               `bulk_edit_songs` — a bulk delete repeats the same
 *                               destructive act N times.
 *   GET  load_song?id=<SongId>                              -> { ok, song, components, credits, tags, links, media, … }
 *                               credits[role][] now carries first/surname/suffix
 *                               alongside {id,name} (#960 plan §4 item 3) — the
 *                               registry's curated parts when known, else a
 *                               server-side decomposePersonName() fallback; never
 *                               decomposed client-side (musicianNamePartsColumnsExist()-
 *                               gated, so an un-migrated install still gets {id,name} only).
 *   POST create_song            { songbook, title? }        -> { ok, songId }
 *   POST delete_song            { songId, reason?, note? }  -> { ok, deleted, softDeleted }
 *                               SOFT delete since #1694 — hides the song
 *                               (restorable at /manage/deleted-songs); the
 *                               destructive purge is songPurge(), admin-only,
 *                               reachable only from the deleted state.
 *                               redirectTo is accepted-and-ignored (relink
 *                               happens at purge). 409 = un-migrated /
 *                               already deleted; 422 = unknown reason.
 *   POST metadata_field_update  { songId, field, value }    -> { ok }
 *                               field=songbook RE-KEYS the SongId (#1679) and
 *                               answers { ok, field, songId, previousId }
 *                               field=isrc canonicalises + shape-validates the
 *                               value (#1741 P5a; 422 on a bad shape) and, in
 *                               the SAME transaction, dual-writes the canonical
 *                               form into tblSongExternalIds (#1749 P5d mirror,
 *                               includes/song_external_ids.php). The five
 *                               #1741 P1 identity columns (subtitle/
 *                               disambiguation/firstPublishedYear/
 *                               copyrightYears/copyrightHolder) answer 409 on
 *                               an install that hasn't applied that migration
 *                               card yet (ed2_songIdentityColsPresent()).
 *                               field=tuneName is RETIRED-BY-ALIAS (#1741 P5c,
 *                               rule #33 — the key stays live for a stale
 *                               Service-Worker-cached client) into the SAME
 *                               ed2_songTuneApply() core song_tune_set (below)
 *                               uses, so TuneName can never again be written
 *                               without TuneId; answers { ok, field:'tuneName', tuneId }.
 *   POST component_upsert       { songId, component:{id?,type,number,sortOrder,lines[],chords?,language?,label?,sourceWorkId?} }
 *                               -> { ok, componentId, label, sourceWorkId, sourceWorkIdIgnored }
 *                               #1860 Phase 5 §3.2 — `label`/`sourceWorkId` are PRESENCE-gated
 *                               (an absent key preserves the stored value, never wipes it — the
 *                               "provided-else-preserve" contract §3's three layers exist for).
 *                               D1/rule #27: a `label` equal to the derived "Type Number" heading
 *                               folds to NULL server-side (hide-when-equal), so the client never
 *                               has to duplicate that comparison. SD1: an unresolvable
 *                               `sourceWorkId` is coerced to NULL — never a 422 — with
 *                               `sourceWorkIdIgnored:true` in the response so the client can toast;
 *                               the response is read BACK from the stored row (rule #35), not
 *                               echoed from the request.
 *   POST component_delete       { songId, componentId }     -> { ok }
 *   POST component_reorder      { songId, order:[id,...] }  -> { ok }
 *   POST components_replace     { songId, components:[...], mode? } -> { ok, count, components }
 *   POST credit_upsert          { songId, role, credit:{id?, name?, first?, surname?, suffix?} }
 *                               -> { ok, creditId, name, first, surname, suffix, registryPersonId }
 *                               Accepts EITHER the legacy flat {name} shape (what the current
 *                               UI still sends) OR the structured {first,surname,suffix} shape
 *                               (creditEntryNormalise() reassembles/decomposes either way).
 *                               Promotes the name into tblMusicians in the SAME transaction
 *                               as the role-table write (#960) — the response echoes back the
 *                               REGISTRY's parts, never the caller's input (D2: the backfill
 *                               never overwrites a curated value, so echoing input would show a
 *                               silent no-op as if it had saved).
 *   POST credit_delete          { songId, role, creditId }  -> { ok }
 *   GET  credit_search?q=&kind=&limit=                       -> { ok, suggestions }
 *   GET  user_search?q=&limit=                                -> { ok, suggestions }  (#1629 — Content Restrictions picker, NOT an editor feature; ported off v1)
 *   GET  org_search?q=&limit=                                 -> { ok, suggestions }  (#1629 — same picker, "organisation" source)
 *   GET  tag_list?id=<SongId>                                -> { ok, tags }
 *   GET  tag_search?q=&limit=                                -> { ok, suggestions }
 *   POST tag_attach             { songId, name }             -> { ok, tag, attached }
 *   POST tag_detach             { songId, tagId }            -> { ok, removed }
 *   POST link_save_all          { songId, links:[{typeId,url,note?,verified?}] } -> { ok, count, links }
 *   GET  song_links?id=<SongId>                              -> { ok, groupId, links }
 *                               Cross-book counterpart group ("same hymn, different
 *                               songbook, different number") — ported from the
 *                               legacy get_song_links (#1608). groupId=0 means the
 *                               song isn't grouped yet; `links` lists every OTHER
 *                               member of the group.
 *   POST song_link_add          { sourceSongId, targetSongId, note? }
 *                               -> { ok, groupId, created? | extended? | noop? }
 *                               Mints a new group / extends an existing one / no-ops
 *                               if already linked. 409 when the two songs are
 *                               already in DIFFERENT groups (unlink one first, or
 *                               use the Merge tool at /manage/duplicate-songs, which
 *                               stays the ONLY merge surface — this endpoint never
 *                               grows one). Entitlement: edit_songs.
 *   POST song_link_remove       { id?, songId? }              -> { ok, deleted }
 *                               Drops one song from its counterpart group; a
 *                               resulting singleton group is cleaned up too.
 *                               Already-gone -> {ok:true, deleted:0}, not an error
 *                               (idempotent double-click, matches v1). Entitlement:
 *                               edit_songs.
 *   GET  song_link_suggestions?id=<SongId>                   -> { ok, suggestions, tableMissing? }
 *                               Up to 5 highest-scoring PENDING pairs involving this
 *                               song, read from the pre-scored tblSongLinkSuggestions
 *                               table the offline batch build-song-link-suggestions.php
 *                               fills — that batch is the ONLY consumer of
 *                               includes/song_similarity.php's scorer; this endpoint
 *                               never scores live (CLAUDE.md rule #22).
 *                               tableMissing:true on an un-migrated install (empty
 *                               suggestions, not a 500).
 *   POST song_link_suggestion_dismiss { songIdA, songIdB, reason? } -> { ok }
 *                               Canonicalises (SongIdA < SongIdB) server-side, records
 *                               a permanent dismissal in tblSongLinkSuggestionsDismissed
 *                               — the SAME table /manage/duplicate-songs writes, so a
 *                               dismissal from either surface suppresses the pair in
 *                               both — and drops the matching pending suggestion row.
 *                               Entitlement: edit_songs.
 *   GET  song_external_ids?id=<SongId>                       -> { ok, externalIds, tableMissing? }
 *                               #1741 P5b — tblSongExternalIds' FIRST UI read path.
 *                               Row shape { id, idType, idValue, scope, source, label, url } —
 *                               label/url come from the RECORDING_EXTERNAL_ID_TYPES registry
 *                               (media_identifiers.php), never re-typed here. tableMissing:true
 *                               on an un-migrated install (empty list, not a 500) — the same
 *                               probe the #1749 P5d mirror already uses (songExternalIdsTableExists(),
 *                               includes/song_external_ids.php), never a second one.
 *   POST song_external_id_add   { songId, idType, idValue }  -> { ok, externalId, created }
 *                               400 missing params; 404 song; 409 table missing; 422 unknown
 *                               idType or a value that fails the registry's documented shape.
 *                               idType='isrc' is canonicalised via ihymns_canonical_isrc()
 *                               FIRST (the same fold metadata_field_update's ISRC branch uses).
 *                               IdScope is SERVER-DERIVED from idType, never a client param.
 *                               `Source='manual'`/`SourceRef=NULL` — a curator-entered row,
 *                               distinct from the #1749 mirror's `Source='ihymns-mirror'` rows
 *                               and the #1747 backfill's `Source='ihymns-backfill'` rows.
 *                               INSERT IGNORE — a duplicate (SongId,IdType,IdValue) re-selects
 *                               the existing row so the echo is always canonical; `created`
 *                               tells the client whether a NEW row was actually written. No
 *                               ed2_touchRevision — external IDs sit outside the content
 *                               snapshot, the same posture as tblSongLinks (see that case's
 *                               comment above) and media file metadata.
 *   POST song_external_id_delete { songId, id }               -> { ok, deleted }
 *                               `SongId` is part of the WHERE (cross-song defence-in-depth,
 *                               not just `Id`); already-gone -> { ok:true, deleted:0 }, the
 *                               same idempotent-double-click posture as song_link_remove.
 *                               409 table missing. Deleting the P5d mirror row is harmless —
 *                               the next ISRC save re-mints it.
 *   GET  song_alt_titles?id=<SongId>                          -> { ok, altTitles, tableMissing? }
 *                               #1669 (epic #832) — tblSongAlternativeTitles' FIRST UI write
 *                               path (the read half — SongData::_songAltTitlesMap(), the
 *                               public song page, the OG image, the #832 search boost — has
 *                               been live for a while; this is what finally lets a curator
 *                               CREATE the first row). Row shape { id, title, language, note,
 *                               sortOrder }, ordered SortOrder ASC, Title ASC — the SAME
 *                               ordering the read half uses. tableMissing:true on an
 *                               un-migrated install (empty list, not a 500), the same
 *                               convention song_external_ids above uses. Per-song FREE TEXT
 *                               (rule #43 does NOT apply — an alt title is a title string, not
 *                               a reference to a registry entity).
 *   POST song_alt_title_add     { songId, title, language?, note? } -> { ok, altTitle, created }
 *                               404 unknown song; 409 table missing (names the
 *                               'alternative-titles' migration card); 422 empty/over-length
 *                               title, an unrecognised language tag, or the title being just
 *                               the song's own main title again ("That is already the song's
 *                               main title."). INSERT IGNORE on uq_song_title
 *                               (SongId,Title) — a duplicate re-selects the existing row, so
 *                               the echo is always canonical; `created` tells the client
 *                               whether a NEW row actually landed. No ed2_touchRevision —
 *                               alt titles sit outside the content snapshot, the SAME posture
 *                               tblSongExternalIds/tblSongLinks take above.
 *   POST song_alt_title_delete  { songId, id }               -> { ok, deleted }
 *                               `SongId` is part of the WHERE (cross-song defence-in-depth,
 *                               not just `id`); already-gone -> { ok:true, deleted:0 }, the
 *                               same idempotent-double-click posture as song_external_id_delete.
 *                               409 table missing.
 *   GET  tune_search?q=&limit=&meter=                          -> { ok, suggestions, tableMissing? }
 *                               #1741 P5c — typeahead over the tblTunes registry (mirror of
 *                               tag_search above). Alias-JOINed (tblTuneAliases, when present)
 *                               so a spelling variant surfaces its CANONICAL tune;
 *                               `suggestions[].matchedAlias` is non-null only when an ALIAS
 *                               (not the tune's own Name) matched. `meter` folds both sides
 *                               through ihymns_meter_normalize() (tune_helpers.php) and
 *                               filters PHP-side — MeterCode is stored display-form, the fold
 *                               cannot run in SQL. tableMissing:true on an un-migrated (no
 *                               tblTunes) install, same degrade convention as
 *                               song_link_suggestions/song_external_ids above.
 *   POST song_tune_set          { songId, tuneName }          -> { ok, field, tuneId, tuneName, slug, meterCode }
 *                               #1741 P5c — the ONE tune write, via the shared
 *                               ed2_songTuneApply() core (also used by metadata_field_update's
 *                               `tuneName` alias branch below): TuneName + TuneId are ALWAYS
 *                               written in the SAME statement (tuneFindOrCreateByName(), P4b),
 *                               never TuneName alone — that is the drift this endpoint retires.
 *                               400 when `tuneName` key is absent; an EMPTY string is a legal
 *                               clear (both columns -> NULL). 404 unknown song.
 *   GET  media_list?id=<SongId>                              -> { ok, media }
 *   POST media_upload  (MULTIPART: songId, kind, annotation?, file) -> { ok, media }
 *   POST media_update           { mediaId, annotation }      -> { ok, mediaId }
 *   POST media_delete           { mediaId }                  -> { ok, deleted, songId }
 *   POST media_reorder          { songId, kind, ids:[...] }  -> { ok, reordered }
 *   POST import_file   (MULTIPART: file, format=auto|videopsalm|openlp|opensong|pro6|pro7|probundle|proclaim|freeshow|chordpro|pptx|easyworship, dedupeMode?, dryRun?) -> { ok, songs_created, ..., dry_run }
 *     format=auto on a .xml/.opensong upload resolves via the shared XML
 *     auto-router (_bulkImport_processXmlAuto(), #882) — it sniffs
 *     OpenLyrics vs OpenSong and tries the other parser once on a primary
 *     parse failure; the response's top-level `format` echoes back the
 *     format that actually parsed.
 *     format=auto on a .pro upload resolves via the shared 3-way sniff
 *     (_bulkImport_sniffProDialect(), epic #1968) — binary -> ProPresenter
 *     7+ ('pro7'), XML/<RVPresentationDocument> -> a mis-extensioned
 *     ProPresenter 6 ('pro6'), else -> genuine ChordPro ('chordpro'); 'pro7'
 *     is also explicitly pickable from the format dropdown.
 *     format=auto on a .probundle upload resolves straight to 'probundle'
 *     (epic #1968 P2) — a bundle is unambiguously ProPresenter's own ZIP
 *     container (no ChordPro/Pro6 ambiguity the way bare .pro has), so no
 *     content sniff is needed; _bulkImport_processProbundle() imports every
 *     `.pro` entry it contains and reports any media entries as warnings
 *     (media ingest is a later phase, plan §6). 'probundle' is also
 *     explicitly pickable from the format dropdown.
 *     #1674 — dryRun="1" runs every real pre-flight decision (existence +
 *     title-dedupe) but writes nothing; the response echoes `dry_run` (a
 *     KEY, not prose) so the client can brand the summary as a preview.
 *     `songs_failed` under dry-run reflects parse/mapping failures only —
 *     DB-level failures are unreproducible without actually writing.
 *   POST import_zip    (MULTIPART: file=.zip, dedupeMode?)    -> { ok, async, job_id, poll_url } (async) | { ok, songs_created, ... } (sync fallback / EasyWorship)
 *     #1674 — dryRun="1" is REFUSED with 422 (ZIP dry-run is deferred: the
 *     async job has no spare column for the flag, which needs a migration).
 *     Import a single file to preview instead.
 *   GET  import_zip_status?job_id=<n>                         -> { ok, job }
 *   GET  import_zip_skipped_csv?job_id=<n>                    -> text/csv
 *   GET  revision_list?songId=<id>&limit=                     -> { ok, revisions }
 *   GET  revision_snapshots?songId=<id>&limit=                -> { ok, revisions[], base, baseSource, truncated, fieldMap, noRollback }
 *                               #1122 — the RAW decoded NewData of EVERY
 *                               revision for one song (newest first) + the window
 *                               base (the oldest row's own PreviousData, NO
 *                               ladder — blame's chain is the consecutive NewData
 *                               rows) + the ED2_META_FIELDS-derived fieldMap + the
 *                               noRollback field-key list, so the client computes
 *                               per-field BLAME by walking the whole history with
 *                               the ONE shipped normaliser (diffSnapshots(), rule
 *                               #22). A NULL/undecodable NewData is a ROW-LEVEL
 *                               null, not a 409 (a whole list renders around it).
 *   GET  revision_get?revisionId=<id>[&songId=<id>]           -> { ok, revision, after, before, beforeSource }
 *                               #1628 item 4 — the before/after snapshot PAIR
 *                               for one revision, so the client can render a
 *                               diff before committing to Restore. `after` is
 *                               the decoded NewData; `before` resolves through
 *                               a server-side LADDER — this row's own
 *                               PreviousData when decodable (the #1743-C2
 *                               chain, f18c54ac, populates it for every
 *                               revision written since), else the
 *                               immediately-older revision's own NewData
 *                               (one extra bound SELECT, same
 *                               (CreatedAt, Id) DESC ordering revision_list
 *                               uses), else null. `beforeSource` names which
 *                               rung answered — 'previousData' |
 *                               'priorRevision' | 'none' (rule #20 — a
 *                               vocabulary string, never a boolean pair) — so
 *                               the client never re-implements the fallback
 *                               chain (rule #35). 409 when THIS revision's own
 *                               NewData is NULL/undecodable (the same
 *                               "no snapshot" semantics revision_restore
 *                               uses). A legacy bare-tblSongs-row snapshot
 *                               (the pre-#1743 v1 shape) is returned AS-IS —
 *                               shape normalisation is the client's rendering
 *                               concern, not this endpoint's.
 *   POST revision_restore       { revisionId }                -> { ok, songId }
 *   GET  work_search?q=&limit=                                -> { ok, suggestions, tableMissing? }
 *                               #1860 Phase 3 — typeahead over the tblWorks registry (mirror of
 *                               tune_search above). tableMissing:true on an un-migrated (no
 *                               tblWorks) install, never a 500. `ccli` is included only when the
 *                               column exists (boolean-gated SQL fragment, rule #5 carve-out) —
 *                               a pre-works-identity install still returns ISWC-based rows.
 *   POST song_work_autolink     { songId }                    -> { ok, linked, workId, workTitle,
 *                               workSlug, songCount, created, createdParent, rehomed, refined,
 *                               conflict, iswcInvalid }
 *                               #1860 Phase 3 — the commit-time hook Editor2 fires after a
 *                               CCLI/ISWC field save lands (§7, a FOLLOW-UP build). Server-
 *                               authoritative: reads the song's STORED Ccli/Iswc from tblSongs,
 *                               never a client-sent value (rule #35's read-back posture). A
 *                               work-link CONFLICT is a field on the 200 body, never an HTTP
 *                               failure (a work-link ambiguity must never fail the song save).
 *                               404 unknown song; 409 when the work-identity migration cards
 *                               (`works` + `works-identity` + `work-identity-model`) haven't
 *                               all been applied yet. Delegates entirely to
 *                               workFindOrLinkByIdentifier() (includes/work_admin.php) — no
 *                               decision logic lives here.
 *   POST song_work_set          { songId, workId } -> link to an existing work
 *                               { songId, title }  -> the manual picker's find-or-create for
 *                                                     identifier-less hymns (§3.7.2)
 *                               { songId, workId, unlink:true } -> the ONLY manual-unlink
 *                                                     surface (§3.3.1 reserves unlink to humans)
 *                               -> { ok, linked, workId, workTitle, workSlug, songCount, created,
 *                                    createdParent:false, rehomed:false, refined:false,
 *                                    conflict:null, iswcInvalid:false } (link / create-by-title)
 *                               -> { ok, unlinked:true, deleted } (unlink; already-gone -> deleted:0,
 *                                    the idempotent-double-click posture of song_link_remove above)
 *                               #1860 Phase 3 — exactly one mode per request (400 otherwise);
 *                               empty/whitespace title -> 400. 404 unknown song, or an unknown
 *                               workId on the link/unlink modes. 409 un-migrated install. All
 *                               writes delegate to includes/work_admin.php (workExists() /
 *                               workFindOrCreateByTitle() / workLinkSongRow() /
 *                               workUnlinkSongRow()) — never a second inline INSERT/DELETE.
 *
 * tblSongRevisions NewData is the FULL hydrated record (ed2_buildSongSnapshot) —
 * the same shape load_song returns (minus media) — so a revision restores in full.
 *
 * load_song additionally returns `tags` (registry-backed), `links` (the
 * {typeId,url,note,verified,sortOrder} shape the shared external-links-editor
 * consumes), and `media` (file metadata, never bytes), so the Tags / Links /
 * Media tabs hydrate from the one load.
 *
 * @requires PHP 8.4+ — project targets PHP 8.5; 8.4 supported for backward-compat
 *           (no implicit-nullable params, no 8.x-removed funcs). mysqli. Auth: editor+.
 */

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'db_mysql.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'activity_log.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'webhooks.php';   /* #1909 — webhookEmit() for songbook.import_completed (ONE summary event per import, dormant no-op) */
/* The ONE in-app notification writer (#1638) — notifyUser(). Replaces this
   file's hand-rolled INSERT INTO tblNotifications, which was one of three
   drifting copies. */
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'notifications.php';
require_once dirname(dirname(__DIR__)) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'csv_safe.php'; // ihymns_fputcsv() — CSV formula-injection neutraliser
/* Shared external-link helpers (#833/#845) — the SAME loader + save +
   reconcile the songbook / work surfaces use, so the song editor never
   forks the external-links code. Provides loadExternalLinkTypesFor(),
   loadExternalLinksForRow(), saveExternalLinksForRow(). */
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'external_link_helpers.php';
/* Song media storage layer (#853) — kind→backend routing, MIME-sniff
   validation, FS/DB staging, the same class the streaming route uses. */
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'SongMediaStorage.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'song_media_visibility.php';   /* #1968 P4 — visibility vocabulary + Visibility select fragment for the media tab */
/* Shared song importers (#1200 Phase 4b) — the SAME bulk-import parsers +
   universal saver the legacy api.php uses (extracted to a shared include so v2
   reuses, never forks, them). Provides _bulkImport_process*() + _bulkImport_dedupeMode(). */
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'song_importers.php';
/* #1235 P1b — the shared tblLyricLines projector (transitional dual-write). */
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'lyric_lines_sync.php';
/* #1235 P3 / #1088 — shared per-line enrichment (translations / annotations)
   write+read layer; the SAME contract the future native API (#1201) reuses. */
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'line_enrichment.php';
/* #1627 — the ONE ArrangementJson rule, shared with gate G4 (#1618) and the
   write side. arrangement_update below must never persist ordinals the gate
   would reject; sharing the validator is what makes that structurally true
   rather than a thing two files happen to agree on today. */
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'arrangement.php';
/* #1679 — the songbook-move re-key helper, PLUS the two cross-cutting predicates
   this file needs OUTSIDE the move branch: songRelocateIdTaken() (the shared
   "is this id claimed by tblSongs OR a live redirect?" check ed2_allocateSongId
   uses, A9) and songRelocateIsTransactionFatal() (the ONE list of MySQL errors
   that have already rolled the caller's transaction back, A1). Loaded at module
   scope rather than lazily inside the move case, because both are wanted by code
   paths that have nothing to do with a move. */
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'song_relocate.php';
/* #1742 — songbookRecomputeSongCount(): the ONE shared tblSongbooks.SongCount
   recompute (includes/songbook_count.php). create_song below is the funnel
   that was missing it entirely — on iHymns' shared-hosting deployment target
   (no CREATE TRIGGER privilege) that left a brand-new songbook's home tile
   permanently hidden behind a stale SongCount = 0. */
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'songbook_count.php';
/* #960 — creditEntryNormalise() / musicianPromote(): the shared
   normalise-and-registry-promote pair every credit write path (this file's
   credit_upsert + revision_restore, the legacy whole-song save, and
   lyrics_ingest.php) now delegates to, so v2's granular credit saves stop
   silently skipping tblMusicians (the #960 regression). */
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'musician_helpers.php';
/* #1741 P5a / #1749 P5d / #1741 P5b — the canonical-identifier fold
   (ihymns_canonical_isrc()) and the recording/release/product IdType
   vocabulary (RECORDING_EXTERNAL_ID_TYPES, mediaIdentifierIdTypeValid(),
   mediaIdentifierValidateValue(), mediaIdentifierScopeForType()) the
   metadata_field_update ISRC branch below needs to write the SAME canonical
   form `/isrc/`'s resolver indexes on, plus the dual-write mirror funnel
   (songExternalIdMirrorIsrc()) that keeps tblSongExternalIds from drifting
   once a curator edits tblSongs.Isrc through this editor. P5b's three
   song_external_id* cases reuse the SAME two requires (never a second
   IdType/IdScope vocabulary, never a second table probe —
   songExternalIdsTableExists() is the ONE probe, reused verbatim). Loaded at
   module scope — like song_relocate.php above — rather than lazily inside one
   branch, because ed2_applySongSnapshot() (a DIFFERENT function, the
   revision-restore path) needs the same fold + mirror for the #1749 §4.3
   restore funnel. */
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'identifier_normalize.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'media_identifiers.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'song_external_ids.php';
/* #1669 — songAltTitlesTableExists()/songAltTitlesList()/songAltTitleAdd()/
   songAltTitleDelete()/songAltTitleIsRedundant() (includes/song_alt_titles.php):
   the ONE tblSongAlternativeTitles write core (rule #22), mirroring
   song_external_ids.php immediately above byte-for-byte in shape. The
   song_alt_titles* cases below are its first UI write path — the table's
   read half (SongData::_songAltTitlesMap(), the #832 search boost) has been
   live for a while; only the ADD/DELETE side was missing. */
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'song_alt_titles.php';
/* #1741 P5c — tuneFindOrCreateByName() (P4b's find-or-create funnel, the
   TuneName<->TuneId lockstep every write path here consumes, never re-forks
   — rule #22) + ihymns_meter_normalize() (this phase's addition, tune_search's
   `meter` filter). Loaded at module scope like the identifier/media-id pair
   above: song_tune_set, metadata_field_update's TuneName alias branch,
   tune_search AND ed2_applySongSnapshot()'s revision-restore all reach the one
   tune write core (ed2_songTuneApply()) from different points in this file. */
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'tune_helpers.php';
/* #1862 (epic #1863) — publisherSearchRows() / publisherResolvePickedOrCreate()
   / publisherFindOrCreateByName() (#1864 cores, rule #22 — never re-forked):
   the Copyright Holder picker's write core (ed2_songCopyrightHolderApply())
   and its typeahead (publisher_search) both delegate here, mirroring the
   tune_helpers.php require immediately above. */
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'publisher_helpers.php';
/* #1900 Wave 4 C8 — songCopyrightHoldersTableExists() / …List() / …Replace():
   the ONE tblSongCopyrightHolders read+write core (rule #22). On a migrated
   install this is now the SOLE writer of the CopyrightHolder/CopyrightHolderId
   denorm too (ed2_songCopyrightHolderApply() below delegates to it), so the
   single-pick field and the multi-pick chip list can never diverge. Loaded at
   module scope like publisher_helpers.php immediately above — every action
   this file serves that might touch a song's copyright holders (the two new
   song_copyright_holders* cases, PLUS song_copyright_holder_set and the
   metadata_field_update CopyrightHolder alias, both further down) needs it. */
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'song_copyright_holders.php';
/* #1862 — songMediaRecomputeFlags() (HasAudio/HasSheetMusic derivation,
   consumed by the media_upload/media_delete hooks + the metadata_field_update
   alias branch below) and pdRecomputeForSong() (public-domain suggestion
   denorm, consumed by credit_upsert/credit_delete + save_song_core.php's
   post-commit tail). Loaded at module scope like every other shared core on
   this page — never a lazy per-branch require for a hook every relevant
   action needs. */
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'song_media_flags.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'pd_suggest.php';
/* #1860 Phase 3 — workFindOrLinkByIdentifier() / workLinkPlan() / workAdminReady()
   / workSlugify() / workExists() / workSnapshot() / workFindOrCreateByTitle() /
   workLinkSongRow() / workUnlinkSongRow() — the ONE shared work-identity write
   core (rule #22, mirrors the tune_helpers.php require immediately above).
   Consumers below: work_search, song_work_autolink, song_work_set. */
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'work_admin.php';
/* #1860 go-live — ilidStampNewRow() for create_song / duplicate_song /
   media_upload / the pending-duplicates songbook ensure below; work_admin.php
   already pulls this in transitively, but every mint call site requires it
   explicitly (rule #22 — never rely on an implicit transitive load). */
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'ilyrics_id.php';

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

/** Emit a JSON response + exit. */
function ed2_respond(array $payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---------------------------------------------------------------- Guard ---- */
if (!isAuthenticated()) {
    ed2_respond(['ok' => false, 'error' => 'Authentication required.'], 401);
}
$currentUser = getCurrentUser();
if (!$currentUser || !hasRole((string)($currentUser['role'] ?? ''), 'editor')) {
    ed2_respond(['ok' => false, 'error' => 'Editor access required.'], 403);
}
$ed2UserId  = isset($currentUser['id']) ? (int)$currentUser['id'] : null;
$ed2IsAdmin = hasRole((string)($currentUser['role'] ?? ''), 'admin');

/**
 * Refuse the request unless the signed-in role holds $key (#1590, E1).
 *
 * ELI5: /manage/entitlements has checkboxes for "Delete songs" and "Bulk-edit
 * songs". Nothing used to read them, so ticking or unticking one did nothing.
 * This is the function that makes those two checkboxes real on this API.
 *
 * WHY A HELPER RATHER THAN FOUR INLINE `if`s: four copies of a gate are four
 * chances for one of them to drift (CLAUDE.md's modularity rule; the same
 * reasoning that produced ed2_respond()). One place to read, one place to fix.
 *
 * EQUIVALENCE — this adds NO live restriction. The guard above already requires
 * `hasRole($role, 'editor')`, i.e. exactly {editor, admin, global_admin}, and
 * the default maps for both `delete_songs` and `bulk_edit_songs` are now that
 * same set (aligned to reality this pass — see includes/entitlements.php). The
 * only new behaviour is that an operator's REVOCATION is finally honoured.
 *
 * @param string $key Entitlement key, e.g. 'delete_songs'
 * @see appWeb/public_html/includes/entitlements.php
 */
function ed2_requireEntitlement(string $key): void
{
    global $currentUser;
    if (!userHasEntitlement($key, $currentUser['role'] ?? null)) {
        ed2_respond(['ok' => false, 'error' => 'The ' . $key . ' entitlement is required.'], 403);
    }
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string)($_REQUEST['action'] ?? '');
$body   = [];
if ($method === 'POST') {
    $raw  = file_get_contents('php://input') ?: '';
    $body = json_decode($raw, true);
    if (!is_array($body)) { $body = []; }
    /* CSRF defence — aligned with the public api.php policy (#293), NOT a
       per-form session token (#1307).
       WHY THE CHANGE: the previous gate validated $_SESSION['csrf_token']
       against the X-CSRF-Token header. That session token is baked into the
       editor page HTML (window.IHYMNS_EDITOR_CSRF) at load time, so it goes
       stale the moment the PHP session's token rotates (re-login / session
       regeneration / GC) under a long-lived editor tab — or is simply absent
       when the admin is authed via the adopted cross-subdomain `ihymns_auth`
       token rather than a persistent /manage/ PHP session. Either case made
       hash_equals() fail and 403'd EVERY api2 POST (delete_song, the line
       enrichment upserts, …), e.g. "Here to Stay" #1289.
       THE DEFENCE STACK (identical to api.php, which is why save_song over
       there always worked): (1) this endpoint already requires isAuthenticated()
       + the editor role above; the auth cookies are SameSite (manage session =
       Strict, ihymns_auth = Lax) so a cross-site POST carries no identity → 401.
       (2) X-Requested-With: XMLHttpRequest is a forbidden header name a cross-
       origin <form>/navigation cannot set without a CORS preflight we never
       honour — so only same-origin `fetch()` (editor.js ed2EnrichApi, which
       already sends it) reaches here. That eliminates the classic CSRF surface
       without depending on a fragile embedded token. */
    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest') {
        ed2_respond(['ok' => false, 'error' => 'Cross-site POST blocked: missing or invalid X-Requested-With header.'], 403);
    }
}

/* ------------------------------------------------------------- Constants --- */

/** Credit role -> child table. The only valid roles; anything else 400s. */
const ED2_CREDIT_TABLES = [
    'writers'     => 'tblSongWriters',
    'composers'   => 'tblSongComposers',
    'arrangers'   => 'tblSongArrangers',
    'adaptors'    => 'tblSongAdaptors',
    'translators' => 'tblSongTranslators',
    'artists'     => 'tblSongArtists',
];

/** #1783 — the hidden staging songbook a duplicated song lands in until the
 *  curator assigns it a real book + number. A song's id IS `<Abbr>-<n>`
 *  (rule #27), so a duplicate cannot be truly bookless; it lives here
 *  (IsOfficial=0, IsDisabled=1 → hidden from every public read via
 *  songbookVisibleSql, still editable under /manage) and the editor PRESENTS
 *  its Songbook + Number fields as empty. The ONE definition — every client
 *  surface receives it from a server response (`load_index.pendingSongbook`),
 *  never re-types the literal (rule #35). */
const ED2_PENDING_SONGBOOK = 'PENDING';

/** Editable scalar field -> [column, bind-type]. Allow-list (CLAUDE.md #5): the
 *  column name is the only non-bound SQL fragment and comes from this constant,
 *  never from input. */
const ED2_META_FIELDS = [
    'title'              => ['Title', 's'],
    'number'             => ['Number', 'i'],
    'songbook'           => ['SongbookAbbr', 's'],
    'language'           => ['Language', 's'],
    'copyright'          => ['Copyright', 's'],
    'ccli'               => ['Ccli', 's'],
    'iswc'               => ['Iswc', 's'],
    /* #1741 P5a — isrc predates the P1 batch (#1064: Isrc VARCHAR(15) NULL is
       already on every install), so it needs no existence gate — but it DOES
       get its own coercion branch below (canonicalise + shape-validate +
       #1749 P5d mirror), never the plain trim-or-empty-to-null generic path. */
    'isrc'               => ['Isrc', 's'],
    /* #1741 P5c — 'tuneName' is RETIRED-BY-ALIAS (rule #33): the KEY stays
       here so a stale Service-Worker-cached metadata-tab.js can still send
       it, but metadata_field_update no longer lets it reach the generic
       coercion + UPDATE below — a dedicated branch (mirroring the
       SongbookAbbr branch's shape) delegates to the SAME shared lockstep
       core (ed2_songTuneApply()) song_tune_set uses, so TuneName can never
       again be written without TuneId (the drift this phase retires). */
    'tuneName'           => ['TuneName', 's'],
    /* #1741 P1 — the five identity columns below may not exist on an
       un-migrated install (the "song-identity-fields" migration card);
       metadata_field_update 409s via ed2_songIdentityColsPresent() before
       writing any of them, and ed2_applySongSnapshot() silently skips a
       restore of one that isn't there. Kept in ED2_META_FIELDS unconditionally
       (not behind a runtime check) because the ALLOW-LIST itself is static —
       it is the WRITE that is gated, exactly like every other existence-gated
       column probe in this file (rule #5). */
    'subtitle'           => ['Subtitle', 's'],
    'disambiguation'     => ['Disambiguation', 's'],
    'firstPublishedYear' => ['FirstPublishedYear', 'i'],
    'copyrightYears'     => ['CopyrightYears', 's'],
    'copyrightHolder'    => ['CopyrightHolder', 's'],
    'originCity'         => ['OriginCity', 's'],
    'originCityId'       => ['OriginCityId', 'i'],   // FK to tblPlaces (nullable int, NOT a flag)
    'verified'           => ['Verified', 'i'],
    'lyricsPublicDomain' => ['LyricsPublicDomain', 'i'],
    'musicPublicDomain'  => ['MusicPublicDomain', 'i'],
    'hasAudio'           => ['HasAudio', 'i'],
    'hasSheetMusic'      => ['HasSheetMusic', 'i'],
    /* #1769 P4 — per-song RIGHTS FACTS (the licence a song's lyrics / music are
       covered by). Both columns ship dormant in the P1 gating-facts batch and
       may be absent on an un-migrated install, so — like the identity columns
       above — the KEY stays in this static allow-list while the WRITE is gated
       (ed2_rightsColsPresent() 409, metadata_field_update below). They take a
       DEDICATED validation branch (value must be '' → NULL or a live licence
       key, else 422) rather than the generic string coercion, and land their
       own audit key (admin.song.rights_set). Nothing ENFORCES on them until P6
       — see .claude/gating-p4-design.md. */
    'lyricsRightsLicenceKey' => ['LyricsRightsLicenceKey', 's'],
    'musicRightsLicenceKey'  => ['MusicRightsLicenceKey', 's'],
];

/* --------------------------------------------------------------- Helpers --- */

/** App-maintained NormalizedTitle fold (best-effort; '' if the normalizer
 *  isn't loadable, matching the column's NOT NULL DEFAULT ''). #1908 D6: capped
 *  to the column width — NFKD can EXPAND a title (Hangul decomposes to 2-3
 *  jamo per syllable), so an uncapped write of a long non-Latin title could
 *  exceed VARCHAR(500) and throw under STRICT mysqli. */
function ed2_normalizeTitle(string $t): string {
    static $loaded = null;
    if ($loaded === null) {
        $p = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'title_normalize.php';
        $loaded = is_file($p);
        if ($loaded) { require_once $p; }
    }
    $folded = ($loaded && function_exists('ihymns_normalize_title')) ? ihymns_normalize_title($t) : '';
    return mb_substr($folded, 0, 500);
}

/** Canonicalise a tag name — uses the SAME normalisation rules as the legacy
 *  bulk_tag normaliser (#762): trim, collapse internal whitespace, Title-Case,
 *  cap at 50 (the tblSongTags.Name VARCHAR(50) length). Identical rules mean
 *  'worship' / 'WORSHIP' / 'Worship' all resolve to the SAME canonical row, so a
 *  tag added through the v2 API never double-stores against one added through the
 *  legacy path. Returns '' for empty/whitespace input (the legacy closure
 *  returns null + filters it; tag_attach rejects '' explicitly — same effect). */
function ed2_normalizeTag(string $name): string {
    $trimmed = preg_replace('/\s+/u', ' ', trim($name));
    if ($trimmed === null || $trimmed === '') { return ''; }
    return mb_substr(mb_convert_case($trimmed, MB_CASE_TITLE_SIMPLE, 'UTF-8'), 0, 50);
}

/** URL-safe slug for a tag name — byte-identical to the legacy generator (#762):
 *  lowercase, every non-alphanumeric run → '-', trimmed of leading/trailing '-'. */
function ed2_tagSlug(string $name): string {
    return trim(strtolower((string)preg_replace('/[^a-z0-9]+/i', '-', $name)), '-');
}

/** True if the tblSongMedia table is present (gracefully degrades pre-migration,
 *  like the legacy _songMedia_tableExists). Memoised — cheap to call per request. */
function ed2_songMediaTableExists(\mysqli $db): bool {
    static $exists = null;
    if ($exists !== null) { return $exists; }
    try {
        $r = $db->query(
            "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblSongMedia' LIMIT 1"
        );
        $exists = $r && $r->fetch_row() !== null;
        if ($r) { $r->close(); }
    } catch (\Throwable $_e) {
        $exists = false;
    }
    return $exists;
}

/**
 * #1783 — find-or-create the hidden staging songbook (ED2_PENDING_SONGBOOK) a
 * duplicated song lives in until the curator assigns it a real book. Idempotent
 * + self-healing across the three docroots (a DATA row, not DDL — schema.sql is
 * untouched, so rule #19 imposes nothing; mirrors the tuneFindOrCreateByName
 * precedent). `IsOfficial=0` always; `IsDisabled=1` only when that column exists
 * (#1765) — a pre-#1765 install degrades to a visible staging book, acceptable
 * because all three docroots share the one migrated DB in practice. Called in
 * autocommit (before the duplicate's own transaction) so the staging book is a
 * durable fixture regardless of whether a given duplicate commits or rolls back.
 */
function ed2_ensurePendingSongbook(\mysqli $db): void {
    static $done = false;
    if ($done) { return; }
    $abbr = ED2_PENDING_SONGBOOK;
    /* @disabled-visible: this find-or-create existence probe MUST see the hidden
       staging book — it is deliberately IsDisabled=1 (#1783), so filtering it out
       via songbookVisibleSql() would make this re-INSERT a duplicate PENDING book
       on every duplicate. It is an admin-only fixture, never a public read. */
    $q = $db->prepare('SELECT 1 FROM tblSongbooks WHERE Abbreviation = ? LIMIT 1');
    $q->bind_param('s', $abbr);
    $q->execute();
    $present = $q->get_result()->fetch_row() !== null;
    $q->close();
    if ($present) { $done = true; return; }

    $hasDisabled = false;
    try {
        $r = $db->query(
            "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblSongbooks'
                AND COLUMN_NAME = 'IsDisabled' LIMIT 1"
        );
        $hasDisabled = $r && $r->fetch_row() !== null;
        if ($r) { $r->close(); }
    } catch (\Throwable $_e) { $hasDisabled = false; }

    $name = 'Pending duplicates';
    if ($hasDisabled) {
        $ins = $db->prepare('INSERT INTO tblSongbooks (Abbreviation, Name, IsOfficial, IsDisabled) VALUES (?, ?, 0, 1)');
    } else {
        $ins = $db->prepare('INSERT INTO tblSongbooks (Abbreviation, Name, IsOfficial) VALUES (?, ?, 0)');
    }
    $ins->bind_param('ss', $abbr, $name);
    $ins->execute();
    $newSongbookId = (int)$db->insert_id;
    $ins->close();
    /* #1860 go-live — mint this fixture songbook's permanent IL-id (ILB…);
       autocommit here (no open transaction — see the comment above this
       function's call site), which ilidStampNewRow() tolerates. */
    ilidStampNewRow($db, 'songbook', $newSongbookId);
    $done = true;
}

/**
 * Per-column present-map for the #1741 P1 tblSongs identity columns — ONE
 * INFORMATION_SCHEMA.COLUMNS query (IN-list of hardcoded constants, rule #5),
 * memoised like ed2_songMediaTableExists() just above. `Isrc` (#1064) is
 * deliberately NOT in this map — it predates the P1 batch and every install
 * already has it; see the dedicated ISRC branch inside metadata_field_update
 * instead of this gate.
 *
 * ELI5: "which of the five new song-identity columns does THIS install
 * actually have yet?" — an install that hasn't run the migration card gets
 * `false` for all five; metadata_field_update uses that to 409 instead of
 * throwing a raw mysqli_sql_exception under STRICT (rule #5's "never treat a
 * query as returning false on error" also cuts the other way: check BEFORE
 * writing to an absent column, don't rely on the throw).
 *
 * @return array<string,bool> e.g. ['Subtitle'=>true, 'Disambiguation'=>false, …]
 * @link .claude/catalogue-1741-P5-plan.md §1.2 item 3
 */
function ed2_songIdentityColsPresent(\mysqli $db): array {
    static $presence = null;
    if ($presence !== null) { return $presence; }
    $cols = ['Subtitle', 'Disambiguation', 'FirstPublishedYear', 'CopyrightYears', 'CopyrightHolder'];
    $presence = array_fill_keys($cols, false);
    try {
        $r = $db->query(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblSongs'
                AND COLUMN_NAME IN ('Subtitle','Disambiguation','FirstPublishedYear','CopyrightYears','CopyrightHolder')"
        );
        if ($r) {
            while ($row = $r->fetch_row()) { $presence[$row[0]] = true; }
            $r->close();
        }
    } catch (\Throwable $_e) {
        /* Degrade to "not migrated" (all false) on any probe failure — the
           safe direction, matching every other existence probe in this file. */
    }
    return $presence;
}

/**
 * Memoised probe: do the per-song RIGHTS-FACT columns exist on this install
 * (#1769 P4 / P1 gating-facts batch)? A dedicated sibling of
 * ed2_songIdentityColsPresent() — same shape, same degrade-to-false-on-failure
 * posture — so metadata_field_update can 409 a rights write (and the restore
 * loop can skip a rights restore) on an un-migrated install rather than throw a
 * raw mysqli_sql_exception under STRICT.
 *
 * @return array<string,bool> column name => present
 */
function ed2_rightsColsPresent(\mysqli $db): array {
    static $presence = null;
    if ($presence !== null) { return $presence; }
    $presence = ['LyricsRightsLicenceKey' => false, 'MusicRightsLicenceKey' => false];
    try {
        $r = $db->query(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblSongs'
                AND COLUMN_NAME IN ('LyricsRightsLicenceKey','MusicRightsLicenceKey')"
        );
        if ($r) {
            while ($row = $r->fetch_row()) { $presence[$row[0]] = true; }
            $r->close();
        }
    } catch (\Throwable $_e) {
        /* Degrade to "not migrated" — the safe direction (rule #9). */
    }
    return $presence;
}

/**
 * The songbook's default rights-fact keys, as a PREFILL HINT for the editor's
 * rights panel (#1769 P4, D4: a hint the curator may adopt, NEVER an automatic
 * write). Returns `['lyrics'=>?key, 'music'=>?key]` when the tblSongbooks
 * default columns exist, or `null` on an un-migrated install so the client can
 * simply omit the hint. Existence-gated (rule #9) + try/caught.
 *
 * @return array{lyrics:?string,music:?string}|null
 */
function ed2_songbookRightsDefaults(\mysqli $db, string $abbr): ?array {
    if ($abbr === '') { return null; }
    static $colsPresent = null;
    if ($colsPresent === null) {
        $colsPresent = false;
        try {
            $r = $db->query(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblSongbooks'
                    AND COLUMN_NAME IN ('DefaultLyricsRightsLicenceKey','DefaultMusicRightsLicenceKey')"
            );
            if ($r) { $colsPresent = ((int)($r->fetch_row()[0] ?? 0)) === 2; $r->close(); }
        } catch (\Throwable $_e) { $colsPresent = false; }
    }
    if (!$colsPresent) { return null; }
    try {
        /* @disabled-visible: editor admin surface (#1765) — the song editor must
           read a songbook's default rights even when the book is disabled/hidden
           from the public site, so a curator can still edit its songs. Never a
           public read (api2 is the authenticated editor API). */
        $s = $db->prepare(
            'SELECT DefaultLyricsRightsLicenceKey, DefaultMusicRightsLicenceKey
               FROM tblSongbooks WHERE Abbreviation = ? LIMIT 1'
        );
        $s->bind_param('s', $abbr);
        $s->execute();
        $row = $s->get_result()->fetch_assoc();
        $s->close();
        if (!$row) { return null; }
        return [
            'lyrics' => ($row['DefaultLyricsRightsLicenceKey'] ?? '') !== '' ? (string)$row['DefaultLyricsRightsLicenceKey'] : null,
            'music'  => ($row['DefaultMusicRightsLicenceKey']  ?? '') !== '' ? (string)$row['DefaultMusicRightsLicenceKey']  : null,
        ];
    } catch (\Throwable $_e) {
        return null;
    }
}

/**
 * Memoised probe: does `tblSongs.TuneId` exist on this install (#1090 tune-
 * registry migration)? A dedicated, SELF-CONTAINED probe — rather than
 * reaching for `includes/places.php`'s generic `placeColumnExists()` — so
 * this file doesn't pull that include in at module scope just for one
 * column (same reasoning as `ed2_songIdentityColsPresent()` just above, P5
 * plan §1.3 item 3). `save_song_core.php` already loads places.php for its
 * own OriginCityId gate, so IT reuses `placeColumnExists()` for its own
 * TuneId lockstep block (P5c §3.4) instead of duplicating this probe.
 *
 * ELI5: "does this install have the TuneId column yet?" — an install that
 * hasn't run the #1090 tune-registry migration card gets `false`, and every
 * tune write in this file then degrades to a TuneName-only UPDATE (the same
 * asymmetry `manage/works.php`'s lockstep block documents: TuneName-only is
 * safe ONLY because there is then no TuneId column left to strand).
 *
 * @link .claude/catalogue-1741-P5-plan.md §3.2 / §3.3
 */
function ed2_tuneIdColumnExists(\mysqli $db): bool {
    static $exists = null;
    if ($exists !== null) { return $exists; }
    try {
        $r = $db->query(
            "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblSongs' AND COLUMN_NAME = 'TuneId' LIMIT 1"
        );
        $exists = $r && $r->fetch_row() !== null;
        if ($r) { $r->close(); }
    } catch (\Throwable $_e) {
        $exists = false;
    }
    return $exists;
}

/**
 * Memoised probe: is `tblTuneAliases` present (#1090 P4's spelling-variant
 * table — "aka" names a tune is also known by)? `tune_search`'s alias JOIN
 * is gated on this; an install without it (pre-migration, or one that ran
 * only the base tblTunes card) just gets exact/LIKE matches on
 * `tblTunes.Name` alone. Same shape as `ed2_songMediaTableExists()` above.
 */
function ed2_tuneAliasesTableExists(\mysqli $db): bool {
    static $exists = null;
    if ($exists !== null) { return $exists; }
    try {
        $r = $db->query(
            "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblTuneAliases' LIMIT 1"
        );
        $exists = $r && $r->fetch_row() !== null;
        if ($r) { $r->close(); }
    } catch (\Throwable $_e) {
        $exists = false;
    }
    return $exists;
}

/**
 * Memoised probe: does `tblWorks` exist at all (#1860 Phase 3)? Deliberately
 * NOT `workAdminReady()` (which also requires `tblWorks.Ccli` + `tblWorkSongs`
 * and answers a different question — "is the WRITE core usable") — a
 * pre-works-identity install still has plain `tblWorks` with `Iswc` only, and
 * `work_search` should keep returning ISWC-based results on such an install
 * rather than degrading to `tableMissing`. Same `static`-memoised idiom as
 * `ed2_tuneAliasesTableExists()` immediately above.
 */
function ed2_worksTableExists(\mysqli $db): bool {
    static $exists = null;
    if ($exists !== null) { return $exists; }
    try {
        $r = $db->query(
            "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblWorks' LIMIT 1"
        );
        $exists = $r && $r->fetch_row() !== null;
        if ($r) { $r->close(); }
    } catch (\Throwable $_e) {
        $exists = false;
    }
    return $exists;
}

/**
 * Memoised probe: does `tblWorks.Ccli` exist (the 'works-identity' card,
 * #1741 P1)? `work_search` gates its `Ccli` SELECT fragment on this — the
 * same boolean-gated-SQL-fragment shape `tune_search` uses for
 * `tblTuneAliases` (rule #5 carve-out: the interpolated text is a hardcoded
 * literal chosen by a boolean, never request input).
 */
function ed2_worksCcliColumnExists(\mysqli $db): bool {
    static $exists = null;
    if ($exists !== null) { return $exists; }
    try {
        $r = $db->query(
            "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblWorks' AND COLUMN_NAME = 'Ccli' LIMIT 1"
        );
        $exists = $r && $r->fetch_row() !== null;
        if ($r) { $r->close(); }
    } catch (\Throwable $_e) {
        $exists = false;
    }
    return $exists;
}

/**
 * The ONE tblSongs tune write: TuneName + TuneId in lockstep (#1741 P5c,
 * parent plan §3B). Consumes `tuneFindOrCreateByName()` (P4b, rule #22 —
 * never a second lookup fork). `case 'song_tune_set'`, the `TuneName` alias
 * branch inside `metadata_field_update`, AND `ed2_applySongSnapshot()`'s
 * revision-restore all delegate here, so there is exactly ONE place in this
 * editor a song's tune can be written from — mirroring `manage/works.php`'s
 * :307-313 Work-side lockstep.
 *
 * ELI5: a curator types (or picks) a tune name; this function finds or
 * creates that tune's registry row and writes BOTH the display name and
 * the registry link onto the song in one go, so the two columns can never
 * fall out of sync the way a bare `UPDATE ... SET TuneName = ?` would let
 * them (tune.php's page would then link the WRONG tune, or none at all).
 *
 * DETAILED: empty/whitespace `$rawName` clears both columns — a legal "no
 * tune set", not an error. A non-empty name is capped to 120 chars
 * (`tblSongs.TuneName` is VARCHAR(120), schema.sql:280 — the SAME cap
 * `tuneFindOrCreateByName()` applies internally, tune_helpers.php:185, so
 * this is belt-and-braces rather than load-bearing). When `tblSongs.TuneId`
 * doesn't exist yet (pre-#1090 install), the write degrades to a
 * TuneName-only UPDATE — safe ONLY because there is then no id column to
 * strand (the works.php asymmetry, P4 plan §2.5's last row). On a resolved
 * id, one extra bound SELECT fetches the tune's Slug + MeterCode so the
 * caller can update its meter affordance without a second round-trip.
 *
 * @param \mysqli $db
 * @param string  $songId
 * @param string  $rawName Curator-typed or picked tune name, any whitespace.
 * @return array{tuneId:?int, tuneName:?string, slug:?string, meterCode:?string}
 * @link .claude/catalogue-1741-P5-plan.md §3.3
 * @link appWeb/public_html/manage/works.php:307-313 the sibling Work-side lockstep this mirrors
 */
function ed2_songTuneApply(\mysqli $db, string $songId, string $rawName): array {
    $name = trim($rawName);
    if ($name !== '') { $name = mb_substr($name, 0, 120); }
    $tuneName = $name === '' ? null : $name;
    $tuneId   = $name === '' ? null : tuneFindOrCreateByName($db, $name);

    if (ed2_tuneIdColumnExists($db)) {
        $u = $db->prepare('UPDATE tblSongs SET TuneName = ?, TuneId = ? WHERE SongId = ?');
        $u->bind_param('sis', $tuneName, $tuneId, $songId);
        $u->execute();
        $u->close();
    } else {
        $u = $db->prepare('UPDATE tblSongs SET TuneName = ? WHERE SongId = ?');
        $u->bind_param('ss', $tuneName, $songId);
        $u->execute();
        $u->close();
    }

    $slug = null;
    $meterCode = null;
    if ($tuneId !== null) {
        $s = $db->prepare('SELECT Slug, MeterCode FROM tblTunes WHERE Id = ? LIMIT 1');
        $s->bind_param('i', $tuneId);
        $s->execute();
        $row = $s->get_result()->fetch_assoc();
        $s->close();
        if ($row) {
            $slug      = (string)$row['Slug'];
            $meterCode = $row['MeterCode'] !== null ? (string)$row['MeterCode'] : null;
        }
    }

    return ['tuneId' => $tuneId, 'tuneName' => $tuneName, 'slug' => $slug, 'meterCode' => $meterCode];
}

/**
 * Memoised probe: does `tblSongs.CopyrightHolderId` exist on this install
 * (#1864's dormant column, activated by #1862)? Same shape as
 * `ed2_tuneIdColumnExists()` just above — a dedicated, self-contained probe
 * rather than reaching into publisher_helpers.php for a generic one, since
 * that file's own probes are about `tblPublishers` existing, not this
 * specific `tblSongs` column.
 */
function ed2_copyrightHolderIdColPresent(\mysqli $db): bool {
    static $exists = null;
    if ($exists !== null) { return $exists; }
    try {
        $r = $db->query(
            "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblSongs' AND COLUMN_NAME = 'CopyrightHolderId' LIMIT 1"
        );
        $exists = $r && $r->fetch_row() !== null;
        if ($r) { $r->close(); }
    } catch (\Throwable $_e) {
        $exists = false;
    }
    return $exists;
}

/**
 * The ONE tblSongs copyright-holder write: CopyrightHolder + CopyrightHolderId
 * in lockstep (#1862, activating the #1864 dormant column). Mirrors
 * `ed2_songTuneApply()` immediately above — same shape, same reasoning:
 * `case 'song_copyright_holder_set'` and the `CopyrightHolder` alias branch
 * inside `metadata_field_update` both delegate here, so there is exactly ONE
 * place in this editor a song's copyright holder can be written from.
 *
 * ELI5: a curator types (or picks) a publisher name; this finds or creates
 * that publisher's registry row and writes BOTH the display name and the
 * registry link onto the song in one go — the same TuneName/TuneId shape,
 * applied to publishers.
 *
 * #1900 WAVE 4 C8 — SINGLE-WRITER UNIFICATION (A.7). On a MIGRATED install
 * (`tblSongCopyrightHolders` exists), this function no longer writes
 * `tblSongs.CopyrightHolder`/`CopyrightHolderId` itself — it hands the ONE
 * curator-typed/picked name off to the multi-holder core
 * (`songCopyrightHoldersReplace()`, includes/song_copyright_holders.php) as a
 * single-row list `[{name, publisherId, role:'holder'}]` (or `[]` to clear).
 * That core is now the SOLE writer of the denorm pair — it re-syncs
 * `CopyrightHolder`/`CopyrightHolderId` to the FIRST-listed holder (rule #37)
 * after every write, single-pick or multi-pick, so the two UI surfaces
 * (this function's single text field, and metadata-tab.js's chip list
 * calling `song_copyright_holders_set` directly) can never disagree about
 * what the denorm mirror should hold. `$ownTransaction=false` is passed
 * because EVERY caller of this function already holds an open transaction
 * (+ its own `ed2_touchRevision()` snapshot) — the core borrows it and
 * RE-THROWS on a write failure, so this function's own caller's `catch` rolls
 * back the whole unit (see `songCopyrightHoldersReplace()`'s doc-block).
 *
 * On an UN-MIGRATED install (no `tblSongCopyrightHolders` yet), this keeps
 * the pre-#1900 behaviour byte-for-byte: a direct `tblSongs` UPDATE, so a
 * single-holder save never regresses on an install that has run the #1864
 * `CopyrightHolderId` column migration but not yet the #1900
 * `tblSongCopyrightHolders` one.
 *
 * DETAILED: empty/whitespace `$rawName` clears BOTH columns — a legal "no
 * holder set", not an error (`CopyrightHolder` is `NOT NULL DEFAULT ''`, so
 * it's set to `''`, never left NULL). A non-empty name is capped to 255
 * chars (`tblSongs.CopyrightHolder` is VARCHAR(255) — the same cap
 * `publisherResolvePickedOrCreate()`/`publisherFindOrCreateByName()` apply
 * internally, belt-and-braces). `$claimedId` is TRUSTED-BUT-VERIFIED —
 * `publisherResolvePickedOrCreate()` on the un-migrated path, or the core's
 * own equivalent resolution on the migrated path — never a raw
 * client-supplied id written unverified (rule #43's find-or-create
 * contract). When `tblSongs.CopyrightHolderId` doesn't exist yet (pre-#1864
 * install), the un-migrated branch degrades to a CopyrightHolder-only
 * UPDATE — safe ONLY because there is then no id column to strand (the
 * works.php / ed2_songTuneApply() asymmetry, restated for publishers).
 *
 * @param \mysqli  $db
 * @param string   $songId
 * @param string   $rawName   Curator-typed or picked holder name, any whitespace.
 * @param ?int     $claimedId The picker's claimed `tblPublishers.Id`, or null
 *                            when nothing was picked (free-typed / cleared).
 * @param ?int     $userId    The signed-in curator (`$ed2UserId`) — threaded
 *                            through to `songCopyrightHoldersReplace()`'s own
 *                            reserved `$userId` param (rule #44 — not read by
 *                            anything yet, but this call site is where a
 *                            future audit-log wiring would need it, so the
 *                            plumbing goes in now rather than as a second
 *                            signature change later).
 * @return array{holderName: string, publisherId: ?int}
 * @link appWeb/public_html/includes/publisher_helpers.php        publisherResolvePickedOrCreate() (un-migrated path)
 * @link appWeb/public_html/includes/song_copyright_holders.php   songCopyrightHoldersReplace() (migrated path, THE single writer)
 * @link appWeb/public_html/manage/works.php:294-360               the sibling Work-side lockstep this mirrors
 */
function ed2_songCopyrightHolderApply(\mysqli $db, string $songId, string $rawName, ?int $claimedId, ?int $userId = null): array {
    $name = trim($rawName);
    if ($name !== '') { $name = mb_substr($name, 0, 255); }

    /* MIGRATED install — the #1900 multi-holder core is the ONE denorm
       writer (A.7). A single-holder set collapses to exactly this one row
       (or an empty list, to clear); the core resolves + re-syncs the denorm
       from its OWN read-back, so we must not also write CopyrightHolder/
       CopyrightHolderId here — that would be a second writer, the exact
       drift rule #37 exists to forbid. */
    if (function_exists('songCopyrightHoldersTableExists') && songCopyrightHoldersTableExists($db)) {
        $rows  = $name === '' ? [] : [['name' => $name, 'publisherId' => $claimedId, 'role' => 'holder']];
        $res   = songCopyrightHoldersReplace($db, $songId, $rows, $userId, false);
        $first = $res['holders'][0] ?? null;
        return [
            'holderName'  => (string)($first['name'] ?? ''),
            'publisherId' => $first['publisherId'] ?? null,
        ];
    }

    /* UN-MIGRATED install — the pre-#1900 direct denorm UPDATE, unchanged. */
    if (ed2_copyrightHolderIdColPresent($db)) {
        $publisherId = $name === '' ? null : publisherResolvePickedOrCreate($db, $name, $claimedId);
        $u = $db->prepare('UPDATE tblSongs SET CopyrightHolder = ?, CopyrightHolderId = ? WHERE SongId = ?');
        $u->bind_param('sis', $name, $publisherId, $songId);
        $u->execute();
        $u->close();
    } else {
        $publisherId = null;
        $u = $db->prepare('UPDATE tblSongs SET CopyrightHolder = ? WHERE SongId = ?');
        $u->bind_param('ss', $name, $songId);
        $u->execute();
        $u->close();
    }

    return ['holderName' => $name, 'publisherId' => $publisherId];
}

/** Shape a tblSongMedia row for the v2 client (camelCase, like the other slices).
 *  NEVER returns the file bytes — playback is via the gated /song-media/<id>
 *  stream route (#853), the only way to the content. */
function ed2_mediaRowShape(array $r): array {
    return [
        'id'             => (int)$r['Id'],
        'kind'           => (string)$r['Kind'],
        'fileName'       => (string)$r['FileName'],
        'mimeType'       => (string)$r['MimeType'],
        'sizeBytes'      => (int)$r['SizeBytes'],
        'annotation'     => (string)($r['Annotation'] ?? ''),
        'sortOrder'      => (int)$r['SortOrder'],
        'storageBackend' => (string)($r['StorageBackend'] ?? ''),
        'uploadedAt'     => (string)($r['UploadedAt'] ?? ''),
        'streamUrl'      => '/song-media/' . (int)$r['Id'],
        /* #1968 P4 — the curator surface BADGES admin-only rows (it never hides
           them); 'public' when the probe-gated Visibility select was absent. */
        'visibility'     => (string)($r['Visibility'] ?? 'public'),
    ];
}

/** Memoised probe: is tblBulkImportJobs present (async import job tracking, #676)? */
function ed2_bulkJobsTableExists(\mysqli $db): bool {
    static $exists = null;
    if ($exists !== null) { return $exists; }
    try {
        $r = $db->query("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblBulkImportJobs' LIMIT 1");
        $exists = $r && $r->fetch_row() !== null;
        if ($r) { $r->close(); }
    } catch (\Throwable $_e) {
        $exists = false;
    }
    return $exists;
}

/** Memoised probe: does tblBulkImportJobs.DryRun exist (#1911 — ZIP dry-run
 *  preview, migrate-bulk-import-dryrun.php)? Column-existence-gated so an
 *  un-migrated install keeps the honest pre-#1911 422 refusal (rule #33)
 *  instead of a silently-ignored flag or a STRICT-mode throw the moment the
 *  status poll's SELECT tries to read a column that isn't there. */
function ed2_bulkJobsDryRunColumnExists(\mysqli $db): bool {
    static $exists = null;
    if ($exists !== null) { return $exists; }
    try {
        $r = $db->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblBulkImportJobs' AND COLUMN_NAME = 'DryRun' LIMIT 1");
        $exists = $r && $r->fetch_row() !== null;
        if ($r) { $r->close(); }
    } catch (\Throwable $_e) {
        $exists = false;
    }
    return $exists;
}

/** Best-effort songbook maintenance after an import created songs (cache regen +
 *  stale-prefix fixup, #932). Guarded + lazy-required; never throws to the caller. */
function ed2_runSongbookMaintenance(\mysqli $db, string $context): void {
    $sm = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'songbook_maintenance.php';
    if (!is_file($sm)) { return; }
    try {
        require_once $sm;
        if (function_exists('songbookMaintenanceRun')) { songbookMaintenanceRun($db, $context); }
    } catch (\Throwable $_e) {
        /* best-effort — maintenance must not fail the import */
    }
}

/** True if the SongId exists. */
function ed2_songExists(\mysqli $db, string $songId): bool {
    /* @deleted-visible: write-path existence check (#1694) — "does the row
       exist?" is an FK/identity question; a soft-deleted row exists, and a
       write into it is harmless and restore-preserving. */
    /* @disabled-visible: same reasoning, one predicate over (#1765) — existence
       is an identity question; a song in a publicly-disabled book still exists
       and the admin editor still writes to it. */
    $s = $db->prepare('SELECT 1 FROM tblSongs WHERE SongId = ? LIMIT 1');
    $s->bind_param('s', $songId);
    $s->execute();
    $exists = (bool)$s->get_result()->fetch_row();
    $s->close();
    return $exists;
}

/**
 * Shape a `tblSongExternalIds` row for the v2 client (#1741 P5b) — camelCase,
 * like every other row-shape helper in this file (ed2_mediaRowShape's
 * sibling), PLUS a friendly `label` and a per-id `url` DERIVED from the
 * RECORDING_EXTERNAL_ID_TYPES registry (media_identifiers.php) — never
 * hand-typed here, and never invented for a provider whose registry entry
 * carries `url === null` (the "do NOT invent a deep link" contract that
 * file's own doc-block states; a bare id cannot resolve a working page for
 * those providers regardless of curator input).
 *
 * ELI5: turn one raw database row into `{ id, idType, idValue, scope,
 * source, label, url }` — the exact shape both `song_external_ids` (GET) and
 * `song_external_id_add`'s echo (POST) hand back to the panel, so the panel
 * never has to build a URL itself.
 *
 * @param array<string,mixed> $r A row selected with at least Id/IdScope/IdType/IdValue/Source.
 * @return array{id:int,idType:string,idValue:string,scope:string,source:string,label:string,url:?string}
 * @link .claude/catalogue-1741-P5-plan.md §2.2 item 1
 * @link appWeb/public_html/includes/media_identifiers.php RECORDING_EXTERNAL_ID_TYPES's `url`/`label` contract
 */
function ed2_songExternalIdRowShape(array $r): array {
    $idType  = (string)$r['IdType'];
    $idValue = (string)$r['IdValue'];
    $reg     = RECORDING_EXTERNAL_ID_TYPES[$idType] ?? null;
    $url     = ($reg !== null && $reg['url'] !== null)
        ? sprintf($reg['url'], rawurlencode($idValue))
        : null;
    return [
        'id'      => (int)$r['Id'],
        'idType'  => $idType,
        'idValue' => $idValue,
        'scope'   => (string)$r['IdScope'],
        'source'  => (string)$r['Source'],
        'label'   => $reg['label'] ?? $idType,
        'url'     => $url,
    ];
}

/**
 * Allocate a server-owned canonical SongId for a NEW numberless song:
 * `<ABBR>-<NNNNNN>` (6-digit per-songbook sequence; fits VARCHAR(20); same
 * grammar as official `<ABBR>-<NNNN>`). Call INSIDE a transaction — the source
 * of truth is the live data (no counter table), locked FOR UPDATE.
 */
function ed2_allocateSongId(\mysqli $db, string $abbr): string {
    /* @disabled-visible: id-allocation (#1765) — the MAX(SongId) scan must span
       every song in the book regardless of public disabled state so a newly
       minted id never collides with an existing (possibly hidden) song. */
    $abbr = strtoupper(trim($abbr));
    /* Allow-list: abbreviation is [A-Z0-9]{1,10}; this validates the only value
       that ends up in the REGEXP fragment below. */
    if (!preg_match('/^[A-Z0-9]{1,10}$/', $abbr)) {
        throw new \RuntimeException('Invalid songbook abbreviation for id allocation.');
    }
    $prefix    = $abbr . '-';
    $regex     = '^' . $abbr . '-[0-9]+$';
    $tailStart = strlen($prefix) + 1;   // 1-based SUBSTRING position

    /* @deleted-visible: id MINT SEED (#1694) — a soft-deleted song keeps its
       SongId reserved (the row still holds the UNIQUE key), so the sequence
       seed must see it or the mint would propose a taken id and 500 on the
       duplicate key. songRelocateIdTaken() below is unfiltered for the same
       reason. */
    $stmt = $db->prepare(
        'SELECT SongId FROM tblSongs
          WHERE SongbookAbbr = ? AND SongId REGEXP ?
          ORDER BY CAST(SUBSTRING(SongId, ?) AS UNSIGNED) DESC
          LIMIT 1 FOR UPDATE'
    );
    $stmt->bind_param('ssi', $abbr, $regex, $tailStart);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $next = ($row && isset($row['SongId'])) ? ((int)substr((string)$row['SongId'], strlen($prefix)) + 1) : 1;

    /* Skip any already-taken value (a numbered song could occupy the range).
       #1679 A9 — "taken" is the SHARED songRelocateIdTaken(), which also asks
       tblSongRedirects. This loop used to probe tblSongs only, so a brand-new
       song could be handed an id that a live redirect still forwards away from;
       getSongById() matches exactly before it consults the redirect layer, so an
       old bookmark then resolves to a DIFFERENT song — 200 OK with the wrong
       content, which is worse than the 404 the redirect existed to prevent.
       The 6-digit tail here (vs the mint's 4) is why the CHECK is shared and the
       loop is not. */
    for ($i = 0; $i < 8; $i++) {
        $candidate = sprintf('%s-%06d', $abbr, $next);
        if (!songRelocateIdTaken($db, $candidate)) { return $candidate; }
        $next++;
    }
    throw new \RuntimeException('Could not allocate a unique SongId.');
}

/** Recompute tblSongs.LyricsText (the FULLTEXT mirror) from the song's lines, in
 *  display order. Called after any component mutation.
 *
 *  #1235 P4/C5 — sourced from the AUTHORITATIVE tblLyricLines (drop-safe), NOT
 *  tblSongComponents.LinesJson. The v2 mutators now write the lines themselves via
 *  the shared inverted write path (lyricLinesWriteComponents), so this is purely the
 *  text-mirror rebuild — there is NO lyricLinesProjectSong() reproject here anymore
 *  (it re-read LinesJson, which is not drop-safe). LinesJson survives only as the
 *  un-migrated-install fallback. */
function ed2_rebuildLyricsText(\mysqli $db, string $songId): void {
    require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'lyric_lines_read.php';
    $lines = [];
    if (lyricLinesMirrorPresent($db)) {
        foreach (lyricLinesFetchPrimary($db, $songId) as $r) { $lines[] = (string)$r['text']; }
    } else {
        /* lines-json-fallback (#1235 P4): un-migrated install (no mirror) — concat the
           component LinesJson, which provably still exists pre-mirror. */
        $s = $db->prepare('SELECT LinesJson FROM tblSongComponents WHERE SongId = ? ORDER BY SortOrder ASC, Id ASC');
        $s->bind_param('s', $songId);
        $s->execute();
        $res = $s->get_result();
        while ($r = $res->fetch_assoc()) {
            $arr = json_decode((string)$r['LinesJson'], true);
            if (is_array($arr)) { foreach ($arr as $ln) { $lines[] = (string)$ln; } }
        }
        $s->close();
    }
    $text = implode("\n", $lines);
    $u = $db->prepare('UPDATE tblSongs SET LyricsText = ? WHERE SongId = ?');
    $u->bind_param('ss', $text, $songId);
    $u->execute();
    $u->close();

    /* #1039 Part A — keep the diacritic-folded search mirror in lockstep with
       the LyricsText we just rebuilt (and repair NormalizedTitle). The rebuild
       only has $songId, so read back the CURRENT Title for the fold; every v2
       title change funnels through its own NormalizedTitle write, so this
       Title is authoritative at this instant. Dormant + fail-open no-op on an
       un-migrated install. */
    require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'search_fold.php';
    /* Gated so an un-migrated install does the ONE memoised readiness probe and
       nothing more — no extra Title read-back until the feature is live. */
    if (searchFoldReady($db)) {
        /* @deleted-visible: editor LyricsText rebuild (#1039) — this runs after a
           component mutation on the song the curator is actively editing; a
           soft-deleted song under review is still edited (restore-first), so its
           fold mirror must be kept current too.
           @disabled-visible: same reasoning, one predicate over (#1765) — a song in
           a publicly-disabled book is still edited in the admin editor; this is a
           write-support read-back of the CURRENT Title, not a public read. */
        $ts = $db->prepare('SELECT Title FROM tblSongs WHERE SongId = ?');
        $ts->bind_param('s', $songId);
        $ts->execute();
        $trow = $ts->get_result()->fetch_row();
        $ts->close();
        searchFoldSyncSong($db, $songId, $trow ? (string)$trow[0] : '', $text);
    }
}

/**
 * Build a validated, null-padded LanguagesJson string (#1235 P3 / #1253) from a
 * per-line `languages` array (parallel to a component's lines), or null when no
 * line carries a real override. Each entry is validated as BCP 47 via the shared
 * validator (canonical _ietfBcp47Validate when loaded); empty/invalid → null
 * (that line inherits the component language). Returning null when there are no
 * overrides keeps the column NULL rather than storing an all-null array.
 *
 * @param mixed $languages  raw per-line languages (expected list aligned to lines)
 * @param int   $lineCount  the component's line count (the array is padded to it)
 */
function ed2_buildLanguagesJson(mixed $languages, int $lineCount): ?string {
    /* Thin wrapper over the shared builder (line_enrichment.php) so api.php +
       api2.php store per-line language identically. */
    return lineEnrichmentBuildLanguagesJson($languages, $lineCount);
}

/** Write a component's LanguagesJson (#1235 P3) — a no-op when the column is not
 *  migrated yet, so per-line language degrades gracefully to component language. */
function ed2_writeComponentLanguages(\mysqli $db, int $compId, string $songId, ?string $languagesJson): void {
    if ($compId <= 0 || !lyricLinesComponentsLangReady($db)) { return; }
    $u = $db->prepare('UPDATE tblSongComponents SET LanguagesJson = ? WHERE Id = ? AND SongId = ?');
    $u->bind_param('sis', $languagesJson, $compId, $songId);
    $u->execute();
    $u->close();
}

/**
 * #1235 P4/C5 — a song's components in the editor shape
 * ({id,type,number,sortOrder,lines,chords,language,languages,label,sourceWorkId}),
 * drop-safely. The ONE read for the v2 mutators' read-modify-write +
 * ed2_buildSongSnapshot. Sourced from the authoritative tblLyricLines (assembler)
 * when the mirror exists; the legacy LinesJson read is the un-migrated-install
 * fallback only. Since #1860 Phase 5 (SD4), the fallback branch ALSO reads
 * Label/SourceWorkId (gated per-column, mirroring the LanguagesJson `$langCol`
 * treatment) — without this, an install with the Label column but no
 * tblLyricLines mirror yet would silently no-op the Structure-tab Label input
 * (rule #30's silent-partial class).
 *
 * @return list<array<string,mixed>>
 */
function ed2_currentComponents(\mysqli $db, string $songId): array {
    require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'lyric_lines_read.php';
    if (lyricLinesMirrorPresent($db)) {
        return lyricLinesEditableComponents($db, $songId);
    }
    /* lines-json-fallback (#1235 P4): un-migrated install — LanguagesJson optional
       (hardcoded column name, never input — rule #5). #1860 Phase 5 §2.4 (SD4):
       Label/SourceWorkId are gated the SAME way, independently of each other and
       of LanguagesJson. */
    $out = [];
    $langCol    = lyricLinesComponentsLangReady($db) ? 'LanguagesJson' : 'NULL AS LanguagesJson';
    $extras     = lyricLinesComponentExtrasPresent($db);
    $labelCol   = $extras['Label'] ? 'Label' : 'NULL AS Label';
    $srcWorkCol = $extras['SourceWorkId'] ? 'SourceWorkId' : 'NULL AS SourceWorkId';
    $cs = $db->prepare("SELECT Id, Type, Number, SortOrder, LinesJson, ChordsJson, Language, {$langCol}, {$labelCol}, {$srcWorkCol}
                          FROM tblSongComponents WHERE SongId = ? ORDER BY SortOrder ASC, Id ASC");
    $cs->bind_param('s', $songId);
    $cs->execute();
    $cr = $cs->get_result();
    /* lines-json-fallback (#1235 P4) continued from above — LinesJson/ChordsJson/
       LanguagesJson here are the SAME column-existence-gated un-migrated-install
       read the doc-block + $langCol above describe. */
    while ($row = $cr->fetch_assoc()) {
        $out[] = [
            'id'        => (int)$row['Id'],
            'type'      => (string)$row['Type'],
            'number'    => (int)$row['Number'],
            'sortOrder' => (int)$row['SortOrder'],
            'lines'     => is_array($d = json_decode((string)$row['LinesJson'], true)) ? $d : [],
            'chords'    => $row['ChordsJson'] !== null ? json_decode((string)$row['ChordsJson'], true) : null,
            'language'  => $row['Language'],
            'languages' => $row['LanguagesJson'] !== null ? json_decode((string)$row['LanguagesJson'], true) : null,
            'label'        => ($row['Label'] !== null && $row['Label'] !== '') ? (string)$row['Label'] : null,
            'sourceWorkId' => $row['SourceWorkId'] !== null ? (int)$row['SourceWorkId'] : null,
        ];
    }
    $cs->close();
    return $out;
}

/**
 * #1235 P4/C5 — persist a FULL component set (editor shape, in display order) for a song
 * and rebuild the LyricsText mirror. The ONE write for every v2 component mutation. When
 * the tblLyricLines mirror exists, delegates to the shared inverted write path
 * (lyricLinesWriteComponents): lines become authoritative, the JSON columns are
 * shadow-written from the same payload while they exist, and component rows are upserted
 * Id-stably (drop-safe + revertable). The legacy DELETE+reinsert is the un-migrated-only
 * fallback. SortOrder is the array position; pass components already in display order.
 *
 * @param list<array<string,mixed>> $components
 */
function ed2_persistComponents(\mysqli $db, string $songId, array $components): void {
    require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'lyric_lines_sync.php';
    $components = array_values(array_filter($components, 'is_array'));
    if (lyricLinesSyncReady($db)) {
        lyricLinesWriteComponents($db, $songId, $components);
    } else {
        /* lines-json-fallback (#1235 P4): un-migrated install (no mirror) — legacy
           DELETE + reinsert of the JSON columns, which provably still exist here. */
        $del = $db->prepare('DELETE FROM tblSongComponents WHERE SongId = ?');
        $del->bind_param('s', $songId);
        $del->execute();
        $del->close();
        /* #1860 Phase 5 §3.5 (SD4) — Label/SourceWorkId are appended to the legacy
           INSERT ONLY when each column exists (the ONE shared probe, rule #35),
           independently of the tblLyricLines mirror itself: an install can have run
           the Label/SourceWorkId migration cards without yet running the mirror
           migration, and this branch is exactly what runs there. Without this gate,
           an install with the column but still on the legacy write path would
           silently drop every Label/SourceWorkId write (rule #30's silent-partial
           class) — the very install SD4 exists to protect. */
        require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'lyric_lines_read.php';
        $extras     = lyricLinesComponentExtrasPresent($db);
        $extraCols  = [];
        $extraTypes = '';
        if ($extras['Label'])        { $extraCols[] = 'Label';        $extraTypes .= 's'; }
        if ($extras['SourceWorkId']) { $extraCols[] = 'SourceWorkId'; $extraTypes .= 'i'; }
        /* lines-json-fallback (#1235 P4) continued from above — LinesJson/
           ChordsJson below are the SAME un-migrated-install columns named in
           this branch's opening comment. */
        $insCols  = array_merge(['SongId', 'Type', 'Number', 'SortOrder', 'LinesJson', 'ChordsJson', 'Language'], $extraCols);
        $insPlace = implode(',', array_fill(0, count($insCols), '?'));
        $insTypes = 'ssiisss' . $extraTypes;
        $ins = $db->prepare('INSERT INTO tblSongComponents (' . implode(',', $insCols) . ") VALUES ({$insPlace})");
        foreach ($components as $i => $comp) {
            $type      = mb_substr(trim((string)($comp['type'] ?? 'verse')), 0, 20) ?: 'verse';
            $number    = max(0, (int)($comp['number'] ?? 0));
            $sortOrder = (int)$i;
            $lines     = is_array($comp['lines'] ?? null) ? array_values(array_map('strval', $comp['lines'])) : [];
            $linesJson = json_encode($lines, JSON_UNESCAPED_UNICODE);
            $chordsJson = (isset($comp['chords']) && is_array($comp['chords'])) ? json_encode($comp['chords'], JSON_UNESCAPED_UNICODE) : null;
            $language  = (isset($comp['language']) && trim((string)$comp['language']) !== '') ? trim((string)$comp['language']) : null;
            $vals = [$songId, $type, $number, $sortOrder, $linesJson, $chordsJson, $language];
            if ($extras['Label']) {
                $vals[] = (isset($comp['label']) && trim((string)$comp['label']) !== '')
                    ? mb_substr(trim((string)$comp['label']), 0, 100)
                    : null;
            }
            if ($extras['SourceWorkId']) {
                $vals[] = (isset($comp['sourceWorkId']) && (int)$comp['sourceWorkId'] > 0) ? (int)$comp['sourceWorkId'] : null;
            }
            $ins->bind_param($insTypes, ...$vals);
            $ins->execute();
            ed2_writeComponentLanguages($db, (int)$db->insert_id, $songId, ed2_buildLanguagesJson($comp['languages'] ?? null, count($lines)));
        }
        $ins->close();
    }
    ed2_rebuildLyricsText($db, $songId);
}

/**
 * #1860 Phase 5 Commit 9 (D6, design §3.7 items 1-3) — a song's Work
 * membership(s) in the LEAN shape Editor2's "Part of work" line needs:
 * {id,title,slug,iswc,isCanonical,songCount,constituents}. Deliberately NOT
 * `SongData::_worksMap()`'s heavier public-page shape (sibling members +
 * work-level external links, `SongData.php:5650`) — this consumer only ever
 * renders the badge text + a read-only "Medley of: A, B, C" line, never the
 * member list, so pulling that extra data here would be dead weight on
 * every editor load. `songCount` mirrors `work_search`'s `UsageCount`
 * (`:3612` above) — a live count of every song the Work is linked to, not
 * just "1" for the row just fetched.
 *
 * ELI5: "which Work(s), if any, does this song belong to, and what does
 * each contain if it's a medley?" — just enough to draw one line per Work.
 *
 * Gated on `ed2_worksTableExists()` (mirrors `SongData::_hasWorksSchema()`'s
 * bare-`tblWorks`-existence probe, `SongData.php:5406`) — `tblWorkSongs` is
 * created BY THE SAME "works" migration card as `tblWorks`
 * (`work_admin.php`'s `workAdminReady()` doc-block), so the one probe is
 * enough to guarantee the JOIN below won't throw under STRICT on an
 * un-migrated install; `[]` there, same as `_worksMap()`. Constituents are
 * attached via the SHARED bulk `workMedleyConstituentsMap()` core the
 * public song page (Commit 7) and `/manage/works` (Commit 6) both use
 * (rule #22 — never a second inline medley query), itself gated on
 * `workMedleyReady()` (a SEPARATE migration card from `tblWorks`/
 * `tblWorkSongs` — an install can have one without the other).
 *
 * @return list<array{id:int,title:string,slug:string,iswc:?string,
 *                     isCanonical:bool,songCount:int,
 *                     constituents:list<array{id:int,title:string,slug:string,sortOrder:int}>}>
 */
function ed2_songWorksLean(\mysqli $db, string $songId): array {
    if (!ed2_worksTableExists($db)) { return []; }

    $wq = $db->prepare(
        'SELECT w.Id AS Id, w.Title AS Title, w.Slug AS Slug, w.Iswc AS Iswc,
                ws.IsCanonical AS IsCanonical,
                (SELECT COUNT(*) FROM tblWorkSongs ws2 WHERE ws2.WorkId = w.Id) AS SongCount
           FROM tblWorkSongs ws
           JOIN tblWorks w ON w.Id = ws.WorkId
          WHERE ws.SongId = ?
          ORDER BY w.Title ASC'
    );
    $wq->bind_param('s', $songId);
    $wq->execute();
    $wr = $wq->get_result();
    $works   = [];   // Id -> shaped row, so step 2 below can key back in by id
    $workIds = [];
    while ($row = $wr->fetch_assoc()) {
        $wid = (int)$row['Id'];
        $workIds[] = $wid;
        $works[$wid] = [
            'id'           => $wid,
            'title'        => (string)$row['Title'],
            'slug'         => (string)$row['Slug'],
            'iswc'         => $row['Iswc'] !== null ? (string)$row['Iswc'] : null,
            'isCanonical'  => (bool)$row['IsCanonical'],
            'songCount'    => (int)$row['SongCount'],
            'constituents' => [],
        ];
    }
    $wq->close();
    if (!$works) { return []; }

    /* Step 2 — medley constituents, batched across every Work surfaced above
       in ONE query (the same N+1-avoidance the bulk map's own doc-block
       names, `work_admin.php:1230-1236`). A medley id with no constituent
       rows is simply absent from the map — `?? []` below leaves that
       Work's `constituents` at the [] it was seeded with. */
    if (workMedleyReady($db)) {
        $constMap = workMedleyConstituentsMap($db, $workIds);
        foreach ($constMap as $mid => $list) {
            if (!isset($works[$mid])) { continue; }
            $works[$mid]['constituents'] = array_map(static fn(array $c): array => [
                'id'        => $c['workId'],
                'title'     => $c['title'],
                'slug'      => $c['slug'],
                'sortOrder' => $c['sortOrder'],
            ], $list);
        }
    }

    return array_values($works);
}

/**
 * Build the full editable song record — { song, components, credits, tags, links }
 * — in the SAME shapes load_song returns (minus media, which is a separate file
 * lifecycle). The single source for BOTH the load_song hydration and the
 * tblSongRevisions snapshot, so a restored snapshot re-hydrates the editor
 * identically. Returns null if the song is gone.
 *
 * `$song['works']` (#1860 Phase 5 Commit 9) mirrors `SongData::getSongById()`'s
 * OWN exact attach convention (`$row['works'] = $worksMap[$songId] ?? [];`,
 * `SongData.php:4442-4443`) rather than a new sibling top-level snapshot key —
 * so `store.set('song', data.song)` (editor2.php's existing `loadSong()`,
 * unchanged) already carries it to the client with no new store wiring, and
 * metadata-tab.js's existing `store.subscribe('song', render)` already
 * re-renders on it. RESTORE-SAFETY: `ed2_applySongSnapshot()` below writes
 * `tblSongs` scalars ONLY for the columns named in `ED2_META_FIELDS`
 * (`:464-` above) via `array_key_exists($column, $songRow)` per named
 * column — 'works' is not, and never will be, one of those keys, so this
 * extra array on `$songRow` is silently ignored on every restore/duplicate
 * path (`ed2_applySongSnapshot()`, `duplicate_song`) exactly like the
 * un-plumbed `IsDeleted`/every other non-ED2_META_FIELDS column already is.
 */
function ed2_buildSongSnapshot(\mysqli $db, string $songId): ?array {
    /* @deleted-visible: editor load + revision snapshot (#1694) — a curator
       repairing or reviewing a hidden song's record must still be able to
       load it by direct id; discovery goes dark instead (the sidebar's
       getSongsSlimIndex() is filtered — restore-first workflow). */
    /* @disabled-visible: same reasoning, one predicate over (#1765) — a curator
       loads a song by direct id for repair regardless of its book's public
       disabled state; discovery (the filtered sidebar index) goes dark. */
    $s = $db->prepare('SELECT * FROM tblSongs WHERE SongId = ? LIMIT 1');
    $s->bind_param('s', $songId);
    $s->execute();
    $song = $s->get_result()->fetch_assoc();
    $s->close();
    if (!$song) { return null; }

    /* #1235 P4/C5 — components in the editor/snapshot shape from the AUTHORITATIVE
       tblLyricLines (drop-safe), so the revision NewData + v2 load are line-sourced. */
    $components = ed2_currentComponents($db, $songId);

    /* #1860 Phase 5 Commit 9 (D6) — this song's Work membership(s), read-only
       in Editor2's "Part of work" line. See ed2_songWorksLean()'s doc-block
       for the shape + gating; see THIS function's own doc-block for why it
       is attached onto $song rather than as a new sibling snapshot key. */
    $song['works'] = ed2_songWorksLean($db, $songId);

    $credits = [];
    $creditNames = [];   // distinct names credited on this song, any role
    foreach (ED2_CREDIT_TABLES as $role => $table) {
        $credits[$role] = [];
        $q = $db->prepare("SELECT Id, Name FROM `{$table}` WHERE SongId = ? ORDER BY Id ASC");
        $q->bind_param('s', $songId);
        $q->execute();
        $qr = $q->get_result();
        while ($row = $qr->fetch_assoc()) {
            $credits[$role][] = ['id' => (int)$row['Id'], 'name' => (string)$row['Name']];
            $creditNames[(string)$row['Name']] = true;
        }
        $q->close();
    }

    /* #960 (plan §4 item 3, closing the gap left by e430dfbc) — attach
       first/surname/suffix to every credit BEFORE it reaches the client.
       ELI5: when the editor opens a song, each Writer/Composer/etc. name
       needs to arrive already split into its three boxes — the browser is
       never allowed to guess the split itself.
       DETAILED / WHY: credit_upsert (the SAVE path) has returned the
       registry's authoritative parts since e430dfbc, but this function
       (the LOAD path — load_song AND the revision-snapshot builder) still
       emitted {id, name} only. A v2 UI built to "never decompose a name in
       JS, only display what the server sends" (the explicit #960 design —
       see musician_helpers.php's doc-block) would therefore render
       every EXISTING credit's First/Surname/Suffix fields blank on open,
       which is indistinguishable from data loss to a curator. One batch
       SELECT covers every distinct name credited on this song (rule #5:
       placeholders built via array_fill, never string-interpolated
       values); a registry row with any non-empty curated part wins,
       otherwise decomposePersonName() — the SAME heuristic
       creditEntryNormalise() already applies to a flat-name credit_upsert
       — supplies a same-shaped fallback split, never a second drifting
       client-side copy of that maths. Gated on
       musicianNamePartsColumnsExist() so an un-migrated install keeps
       emitting {id, name} exactly as before this change. */
    if ($creditNames && musicianNamePartsColumnsExist($db)) {
        $names        = array_keys($creditNames);
        $placeholders = implode(',', array_fill(0, count($names), '?'));
        $rp = $db->prepare("SELECT Name, FirstNames, Surname, Suffix FROM tblMusicians WHERE Name IN ({$placeholders})");
        $rp->bind_param(str_repeat('s', count($names)), ...$names);
        $rp->execute();
        $rpr = $rp->get_result();
        $registryParts = [];
        while ($row = $rpr->fetch_assoc()) {
            $registryParts[(string)$row['Name']] = [
                (string)($row['FirstNames'] ?? ''),
                (string)($row['Surname']    ?? ''),
                (string)($row['Suffix']     ?? ''),
            ];
        }
        $rp->close();
        foreach ($credits as $role => $list) {
            foreach ($list as $i => $c) {
                $reg = $registryParts[$c['name']] ?? null;
                [$first, $surname, $suffix] = ($reg !== null && ($reg[0] !== '' || $reg[1] !== '' || $reg[2] !== ''))
                    ? $reg
                    : decomposePersonName($c['name']);
                $credits[$role][$i]['first']   = $first;
                $credits[$role][$i]['surname'] = $surname;
                $credits[$role][$i]['suffix']  = $suffix;
            }
        }
    }

    $tags = [];
    $tg = $db->prepare('SELECT t.Id, t.Name, t.Slug, t.Description
                          FROM tblSongTagMap m JOIN tblSongTags t ON t.Id = m.TagId
                         WHERE m.SongId = ? ORDER BY t.Name ASC');
    $tg->bind_param('s', $songId);
    $tg->execute();
    $tgr = $tg->get_result();
    while ($row = $tgr->fetch_assoc()) {
        $tags[] = ['id' => (int)$row['Id'], 'name' => (string)$row['Name'], 'slug' => (string)$row['Slug'], 'description' => (string)($row['Description'] ?? '')];
    }
    $tg->close();

    $links = loadExternalLinksForRow($db, 'tblSongExternalLinks', 'SongId', $songId);

    return ['song' => $song, 'components' => $components, 'credits' => $credits, 'tags' => $tags, 'links' => $links];
}

/**
 * Apply a full song snapshot (a revision's NewData) onto the live song —
 * scalars + components + credits + tags + links — replacing each. The caller
 * owns the transaction. Tolerates an old scalar-only snapshot (the bare tblSongs
 * row with no 'song' key) by restoring scalars only. Mirrors the relational
 * write paths so a restore lands identical to having re-typed everything.
 *
 * ONE DELIBERATE EXCLUSION — `SongbookAbbr` (#1679).
 *
 * ELI5: restoring an old version of the words must not silently pick the song up
 * and drop it back into a songbook it left.
 *
 * Detail: the abbreviation IS the SongId prefix (rule #27). A restore writes
 * SCALARS only — it cannot re-key the id, cascade the ~25 FK children or leave a
 * permalink redirect — so restoring an old `SongbookAbbr` would recreate exactly
 * the SongId↔SongbookAbbr mismatch #1679 exists to kill, as a SIDE EFFECT of an
 * action the curator asked for on a completely different field. A restore
 * therefore keeps the song's CURRENT home; moving books stays an explicit act
 * (`metadata_field_update` with field=songbook → songRelocate()).
 *
 * TUNE IS RESTORED IN LOCKSTEP (#1741 P5c) — `TuneName` is NOT restored by the
 * generic scalar loop (writing it alone would strand `TuneId`, the drift P5c
 * retires); it funnels through `ed2_songTuneApply()`, the same ONE write core
 * the live edit paths use, so a restore re-links the tune registry row exactly
 * as re-typing the name would (re-resolving via find-or-create, never trusting
 * a snapshot's stale id).
 */
function ed2_applySongSnapshot(\mysqli $db, string $songId, array $snap): void {
    /* A v2 full snapshot has a 'song' key; an old scalar-only snapshot IS the row. */
    $songRow = is_array($snap['song'] ?? null) ? $snap['song'] : $snap;

    /* Scalars — only the allow-listed editable columns (same coercion as
       metadata_field_update). */
    $ed2IdentityPresence = ed2_songIdentityColsPresent($db);   // #1741 P1 gate
    $ed2RightsPresence   = ed2_rightsColsPresent($db);          // #1769 P4 gate
    $ed2WroteIsrc = false;
    /* #1741 P5c — captured in the scalar loop, restored AFTER it through the
       ONE tune write core so TuneId lands in lockstep with TuneName. */
    $ed2TuneRestore    = false;
    $ed2TuneRestoreRaw = null;
    foreach (ED2_META_FIELDS as $field => [$column, $type]) {
        /* #1679 — never restore the songbook (see the doc-block above). */
        if ($column === 'SongbookAbbr') { continue; }
        /* #1741 P5c — TuneName is NOT a scalar restore: writing it alone here
           would strand TuneId (the exact drift this phase retires). Capture
           the snapshot's value (only when the key is present — an old
           scalar-only snapshot without it leaves the current tune untouched)
           and skip the generic write; ed2_songTuneApply() restores BOTH
           columns in lockstep after the loop (gated there: an un-migrated
           install with no TuneId column degrades to a TuneName-only UPDATE,
           byte-identical to the pre-P5c restore behaviour). */
        if ($column === 'TuneName') {
            if (array_key_exists($column, $songRow)) {
                $ed2TuneRestore    = true;
                $ed2TuneRestoreRaw = $songRow[$column];
            }
            continue;
        }
        /* #1741 P1 — silently skip an absent identity column so the REST of
           the snapshot still restores; never abort a whole restore over one
           optional field on an un-migrated install (mirrors works.php's
           partial-apply posture, P4 plan §2 gating note). */
        if (array_key_exists($column, $ed2IdentityPresence) && !$ed2IdentityPresence[$column]) { continue; }
        /* #1769 P4 — same partial-apply posture for the rights-fact columns:
           skip a restore of one that doesn't exist on this un-migrated install
           rather than throw under STRICT (the identity-column precedent above).
           No key VALIDATION on restore — the snapshot's value was valid when
           saved and a stale registry mustn't block a whole revision restore. */
        if (array_key_exists($column, $ed2RightsPresence) && !$ed2RightsPresence[$column]) { continue; }
        if (!array_key_exists($column, $songRow)) { continue; }
        $raw = $songRow[$column];
        if ($type === 'i') {
            $value = ($field === 'number' || $field === 'originCityId')
                ? (($raw === null || $raw === '' || (int)$raw <= 0) ? null : (int)$raw)
                : (int)((bool)$raw);
        } else {
            $value = $raw === null ? '' : trim((string)$raw);
            /* 'TuneName' deliberately absent (#1741 P5c) — it is captured and
               skipped above, then restored via ed2_songTuneApply() after the
               loop; it can never reach this generic scalar path. The two #1769
               P4 rights-fact columns are nullable too ('' → NULL = no fact). */
            if (in_array($column, ['Iswc', 'OriginCity', 'Isrc', 'Subtitle', 'LyricsRightsLicenceKey', 'MusicRightsLicenceKey'], true) && $value === '') { $value = null; }
        }
        $u = $db->prepare("UPDATE tblSongs SET `{$column}` = ? WHERE SongId = ?");
        if ($value === null) { $np = null; $u->bind_param('ss', $np, $songId); }
        else { $u->bind_param($type . 's', $value, $songId); }
        $u->execute();
        $u->close();
        if ($column === 'Isrc') { $ed2WroteIsrc = true; }
    }
    if (array_key_exists('Title', $songRow)) {
        $norm = ed2_normalizeTitle((string)$songRow['Title']);
        $un = $db->prepare('UPDATE tblSongs SET NormalizedTitle = ? WHERE SongId = ?');
        $un->bind_param('ss', $norm, $songId);
        $un->execute();
        $un->close();
    }
    /* #1749 P5d / full unification — a revision restore is an Isrc write
       funnel too: when the scalar loop actually wrote Isrc (i.e. the
       snapshot carried the key — Isrc is never gate-skipped, it predates
       P1), mirror the SAME canonical value into tblSongExternalIds so the
       store never drifts from a restored tblSongs.Isrc. Canonicalise again
       here rather than trusting the snapshot's stored text is already
       canonical — an OLD revision (pre-#1741 P5a) could carry a
       pre-canonicalisation raw value. #1749 — this ALSO now HEALS the 0.10
       class of pre-cutover drift: the scalar loop above wrote the
       snapshot's RAW Isrc text into the column a few lines up, but this
       mirror call's embedded store->column sync (songExternalIdSyncIsrcDenorm(),
       its "last word") re-projects the CANONICAL fold back over it — so an
       old `US-ABC-…`-shaped snapshot restore no longer leaves the column and
       the store's marker row disagreeing. */
    if ($ed2WroteIsrc) {
        $isrcRestoreRaw   = $songRow['Isrc'] === null ? '' : (string)$songRow['Isrc'];
        $isrcRestoreCanon = ihymns_canonical_isrc($isrcRestoreRaw);
        songExternalIdMirrorIsrc($db, $songId, $isrcRestoreCanon === '' ? null : $isrcRestoreCanon);
    }
    /* #1741 P5c — restore the tune through the ONE write core (the same
       ed2_songTuneApply() metadata_field_update's TuneName branch and
       song_tune_set delegate to), so TuneId is restored in lockstep with
       TuneName. An OLD snapshot may carry TuneName with no TuneId, or a TuneId
       since merged away — funnelling through the find-or-create core re-resolves
       the CURRENT registry row rather than trusting a stale id. Only runs when
       the snapshot actually carried TuneName (captured in the scalar loop
       above); an empty/whitespace value clears both columns, as elsewhere. */
    if ($ed2TuneRestore) {
        ed2_songTuneApply($db, $songId, $ed2TuneRestoreRaw === null ? '' : (string)$ed2TuneRestoreRaw);
    }

    /* Components — replace the whole set (only if the snapshot carries them). #1235
       P4/C5 — restore through the shared write path (drop-safe; lines authoritative +
       JSON shadow). Works for OLD revisions too — their NewData carries
       components[].lines/chords/languages. Order by the snapshot's sortOrder so the
       position-keyed write lands them in their saved display order. */
    if (isset($snap['components']) && is_array($snap['components'])) {
        $snapComps = array_values(array_filter($snap['components'], 'is_array'));
        usort($snapComps, static fn($a, $b) => ((int)($a['sortOrder'] ?? 0)) <=> ((int)($b['sortOrder'] ?? 0)));
        ed2_persistComponents($db, $songId, $snapComps);

        /* ArrangementJson — restore it TOO (#1627).
         *
         * ELI5: the running order is part of the song, so restoring an old
         * version has to bring the running order back with it.
         *
         * Detail: this column is not in ED2_META_FIELDS (nothing in v2 could
         * write it until arrangement_update, above), so the scalar loop skips
         * it — yet the snapshot row is a `SELECT *` and has carried the value
         * all along. Restore therefore rebuilt every section and silently left
         * the arrangement pointing at the CURRENT set. Harmless while no v2
         * editor could create one; a live data-loss bug the moment one can.
         *
         * Deliberately inside the components branch: ordinals index the section
         * list, so restoring an order without restoring the sections it indexes
         * is how you get ordinals pointing past the end. Re-validated against
         * the snapshot's own component count through the shared rule rather
         * than trusted — an old revision may predate a section being deleted,
         * and an out-of-range ordinal must clear to NULL, never be written back
         * for gate G4 to find later. */
        if (arrangementColumnExists($db)) {
            require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'arrangement.php';
            $arrRaw = $songRow['ArrangementJson'] ?? null;
            $arrOrd = is_string($arrRaw) ? json_decode($arrRaw, true) : $arrRaw;
            $arrJson = arrangementSanitise($arrOrd, count($snapComps));
            $ua = $db->prepare('UPDATE tblSongs SET ArrangementJson = ? WHERE SongId = ?');
            $ua->bind_param('ss', $arrJson, $songId);
            $ua->execute();
            $ua->close();
        }
    }

    /* Credits — replace each role table from the snapshot. */
    if (isset($snap['credits']) && is_array($snap['credits'])) {
        foreach (ED2_CREDIT_TABLES as $role => $table) {
            $d = $db->prepare("DELETE FROM `{$table}` WHERE SongId = ?");
            $d->bind_param('s', $songId);
            $d->execute();
            $d->close();
            $roleList = is_array($snap['credits'][$role] ?? null) ? $snap['credits'][$role] : [];
            if ($roleList) {
                $ci = $db->prepare("INSERT INTO `{$table}` (SongId, Name) VALUES (?, ?)");
                foreach ($roleList as $credit) {
                    /* #960 — normalise + promote, same as every other credit
                       write path (credit_upsert, save_song). A restore can
                       resurrect a name whose registry row was since merged
                       or deleted away; without this the role table comes
                       back but the person page/registry stays gone. */
                    $entry = creditEntryNormalise($credit);
                    if ($entry === null) { continue; }
                    $name = mb_substr($entry['name'], 0, 255);
                    $ci->bind_param('ss', $songId, $name);
                    $ci->execute();
                    musicianPromote($db, $name, [
                        'first'   => $entry['first'],
                        'surname' => $entry['surname'],
                        'suffix'  => $entry['suffix'],
                    ]);
                }
                $ci->close();
            }
        }
    }

    /* Tags — replace the map from the snapshot's tag ids (the tags themselves
       are global registry rows; INSERT IGNORE skips any since-deleted tag). */
    if (isset($snap['tags']) && is_array($snap['tags'])) {
        $dt = $db->prepare('DELETE FROM tblSongTagMap WHERE SongId = ?');
        $dt->bind_param('s', $songId);
        $dt->execute();
        $dt->close();
        $it = $db->prepare('INSERT IGNORE INTO tblSongTagMap (SongId, TagId) VALUES (?, ?)');
        foreach ($snap['tags'] as $tag) {
            $tid = (int)($tag['id'] ?? 0);
            if ($tid <= 0) { continue; }
            $it->bind_param('si', $songId, $tid);
            $it->execute();
        }
        $it->close();
    }

    /* Links — reconcile via the shared helper (DELETE-then-INSERT). */
    if (isset($snap['links']) && is_array($snap['links'])) {
        $typeIds = []; $urls = []; $notes = []; $verified = [];
        foreach ($snap['links'] as $ln) {
            if (!is_array($ln)) { continue; }
            $typeIds[]  = (int)($ln['typeId'] ?? 0);
            $urls[]     = (string)($ln['url'] ?? '');
            $notes[]    = (string)($ln['note'] ?? '');
            $verified[] = !empty($ln['verified']) ? 1 : 0;
        }
        saveExternalLinksForRow($db, 'tblSongExternalLinks', 'SongId', $songId, $typeIds, $urls, $notes, $verified);
    }
}

/**
 * Write a COALESCED revision snapshot (#400): one row per song per ~15s burst,
 * not one per keystroke. NewData is the FULL hydrated record (ed2_buildSongSnapshot)
 * so a revision can be restored in full. $force=true bypasses the coalesce window
 * (used for restores, which must always land in the audit trail). Best-effort —
 * a revision failure never breaks the edit; the precise per-edit trail lives in
 * tblActivityLog via logActivity().
 *
 * ELI5: every time we save a snapshot of the song, we now also remember what the
 * PREVIOUS snapshot looked like, so "undo" has something real to go back to.
 *
 * DETAILED — #1743 THE CHAIN RULE: revision N's PreviousData := revision N-1's
 * NewData, copied verbatim, in WHATEVER shape that prior row's NewData happens to
 * be stored in (editor-payload lowercase-keys, the v2 full-snapshot {song:{...}},
 * or a bare tblSongs-row — see api.php's restore_revision, #1743-C3, for the
 * consumer that tolerates all three). This function never inspects or reshapes
 * the prior value — it is a pure "what came immediately before" pointer, one link
 * in the chain. Because this function COALESCES writes into ~15s bursts (the
 * check just above), "the previous revision's NewData" is not literally the
 * state one keystroke ago — it IS the last state that was actually audited, i.e.
 * the correct pre-state at the audit trail's own granularity. A song that has no
 * prior row in tblSongRevisions yet (legacy data saved before the revision table
 * existed, or this song's genuine first save) legitimately keeps PreviousData
 * NULL — there is nothing before it to chain to, and that NULL is itself
 * meaningful downstream (api.php's restore_revision reads a NULL PreviousData as
 * "this is the initial create, there is nothing to restore back to").
 */
function ed2_touchRevision(\mysqli $db, string $songId, ?int $userId, string $actionTag, bool $force = false): void {
    try {
        if (!$force) {
            $chk = $db->prepare(
                'SELECT 1 FROM tblSongRevisions
                  WHERE SongId = ? AND CreatedAt > (NOW() - INTERVAL 15 SECOND)
                  LIMIT 1'
            );
            $chk->bind_param('s', $songId);
            $chk->execute();
            $recent = (bool)$chk->get_result()->fetch_row();
            $chk->close();
            if ($recent) { return; }
        }

        /* #1743 — chain PreviousData from the immediately preceding revision row's
           NewData (verbatim, whatever shape it is stored in — see the doc-block
           above). NULL when there is no prior row for this song at all, OR when
           the prior row's own NewData was itself NULL (fetch_row() returning a
           row whose single column is null already collapses to that). */
        $prev = $db->prepare(
            'SELECT NewData FROM tblSongRevisions
              WHERE SongId = ?
              ORDER BY Id DESC
              LIMIT 1'
        );
        $prev->bind_param('s', $songId);
        $prev->execute();
        $prevRow = $prev->get_result()->fetch_row();
        $prev->close();
        $previousData = $prevRow !== null ? $prevRow[0] : null;

        $snapshot = ed2_buildSongSnapshot($db, $songId);
        $newData  = $snapshot !== null ? json_encode($snapshot, JSON_UNESCAPED_UNICODE) : null;

        $rev = $db->prepare(
            'INSERT INTO tblSongRevisions (SongId, UserId, Action, PreviousData, NewData, Status)
             VALUES (?, ?, ?, ?, ?, "approved")'
        );
        $rev->bind_param('sisss', $songId, $userId, $actionTag, $previousData, $newData);
        $rev->execute();
        $rev->close();
    } catch (\Throwable $_e) {
        /* #1679 A1 — the ONE exception this must NOT swallow. Every caller runs
           this INSIDE its own transaction, immediately before commit(); a MySQL
           error that already rolled that transaction back (deadlock victim) turns
           the swallow into a FALSE SUCCESS — commit() then succeeds trivially and
           the endpoint answers ok:true naming a songId that does not exist. The
           songbook-move case is the sharpest instance: songRelocate() carefully
           re-throws those codes from its own best-effort step, and this catch,
           two lines later, ate them again. The test is the shared predicate, not
           a copy of the code list (rule #35). */
        if (songRelocateIsTransactionFatal($_e)) { throw $_e; }
        /* swallow — auditing must not break the edit */
    }
}

/* -------------------------------------------------------------- Dispatch --- */

$db = getDbMysqli();

try {
    switch ($action) {

    /* ---- load_song (GET) — purpose-built v2 payload, DB-direct. Returns the
           song scalars + components + credits each WITH their row Id, because
           the granular API keys every update/delete on the Id (the legacy
           getSongById shape is index-based and not guaranteed to carry ids). ---- */
    case 'load_song': {
        $songId = trim((string)($_GET['id'] ?? ''));
        if ($songId === '') { ed2_respond(['ok' => false, 'error' => 'id is required.'], 400); }

        /* song + components + credits + tags + links — the same builder the
           revision snapshot uses, so a restore re-hydrates the editor identically. */
        $snapshot = ed2_buildSongSnapshot($db, $songId);
        if ($snapshot === null) { ed2_respond(['ok' => false, 'error' => 'Song not found.'], 404); }

        /* Media — file metadata only (never bytes); a separate file lifecycle,
           so it is NOT part of the content snapshot. [] pre-migration. */
        $media = [];
        if (ed2_songMediaTableExists($db)) {
            $ms = $db->prepare(
                'SELECT Id, Kind, StorageBackend, FileName, MimeType, SizeBytes,
                        Annotation, SortOrder, UploadedBy, UploadedAt'
                 . songMediaVisibilitySelectFragment($db) . '
                   FROM tblSongMedia WHERE SongId = ?
                  ORDER BY Kind ASC, SortOrder ASC, Id ASC'
            );
            $ms->bind_param('s', $songId);
            $ms->execute();
            $mr = $ms->get_result();
            while ($row = $mr->fetch_assoc()) { $media[] = ed2_mediaRowShape($row); }
            $ms->close();
        }

        /* #1235 P3 — per-line enrichment (translations + annotations), keyed to
           tblLyricLines.Id (which load_song's components now expose as lineIds).
           Empty arrays on an un-migrated install. A separate concern from the
           component-content snapshot, like media. */
        $enrichment = lineEnrichmentForSong($db, $songId);

        /* #1769 P4 — the song's songbook default rights keys, a PREFILL HINT
           for the rights panel (D4: a hint the curator may adopt, never an
           automatic write). null on an un-migrated install so the client omits
           the hint. Read off the snapshot's SongbookAbbr — not the song fact
           columns, so a revision restore never carries it. */
        $songbookAbbr = (string)($snapshot['song']['SongbookAbbr'] ?? '');
        $songbookRightsDefaults = ed2_songbookRightsDefaults($db, $songbookAbbr);

        ed2_respond(array_merge(['ok' => true], $snapshot, [
            'media'            => $media,
            'lineTranslations' => $enrichment['translations'],
            'lineAnnotations'  => $enrichment['annotations'],
            'songbookRightsDefaults' => $songbookRightsDefaults,
            /* #1783 — true when this is a not-yet-assigned duplicate (lives in
               the hidden staging book). The Metadata tab then renders the
               Songbook + Number fields EMPTY (the "Assign to songbook" panel).
               Added HERE, in the case, not in ed2_buildSongSnapshot(), so a
               revision snapshot never carries it. */
            'isPendingDuplicate' => ($songbookAbbr === ED2_PENDING_SONGBOOK),
        ]));
        break;
    }

    /* ---- create_song (POST) — server-owned canonical id ---- */
    case 'create_song': {
        /* @disabled-visible: admin editor API (#1765) — the target-songbook
           existence check must accept a publicly-disabled book (still a valid
           admin create target). */
        $abbr  = strtoupper(trim((string)($body['songbook'] ?? '')));
        if ($abbr === '') { $abbr = 'MISC'; }
        $title = trim((string)($body['title'] ?? ''));
        if ($title === '') { $title = 'New Song'; }
        $title = mb_substr($title, 0, 500);

        $db->begin_transaction();
        try {
            $sb = $db->prepare('SELECT Abbreviation FROM tblSongbooks WHERE Abbreviation = ? LIMIT 1');
            $sb->bind_param('s', $abbr);
            $sb->execute();
            $sbOk = (bool)$sb->get_result()->fetch_row();
            $sb->close();
            if (!$sbOk) { $db->rollback(); ed2_respond(['ok' => false, 'error' => "Songbook '{$abbr}' not found."], 400); }

            $songId = ed2_allocateSongId($db, $abbr);
            $norm   = ed2_normalizeTitle($title);
            /* #1343-B — mint the opaque PublicId permalink at create (gated; an
               un-migrated env omits the column and the backfill fills it later). */
            require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'song_public_id.php';
            if (songPublicId_columnReady($db)) {
                $pubId = songPublicId_mintUnique($db);
                $ins = $db->prepare('INSERT INTO tblSongs (SongId, PublicId, Title, NormalizedTitle, SongbookAbbr) VALUES (?, ?, ?, ?, ?)');
                $ins->bind_param('sssss', $songId, $pubId, $title, $norm, $abbr);
            } else {
                $ins = $db->prepare('INSERT INTO tblSongs (SongId, Title, NormalizedTitle, SongbookAbbr) VALUES (?, ?, ?, ?)');
                $ins->bind_param('ssss', $songId, $title, $norm, $abbr);
            }
            $ins->execute();
            $ins->close();
            /* #1860 go-live — mint this song's permanent IL-id (ILS…). */
            ilidStampNewRow($db, 'song', $songId, 'SongId');
            ed2_touchRevision($db, $songId, $ed2UserId, 'create');
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }

        /* #1742 — recompute the book's cached tblSongbooks.SongCount now the new
           song is committed. On a shared host with no CREATE TRIGGER privilege
           (iHymns' deployment target) this app-side recompute is the ONLY thing
           that keeps the songbook tile's count correct; on trigger-capable hosts
           it is a harmless idempotent re-count. Best-effort and post-commit: the
           song already exists, so a recount failure self-heals on the next pass
           and must never fail the create. */
        try {
            songbookRecomputeSongCount($db, $abbr);
        } catch (\Throwable $_e) {
            error_log('[editor create_song] SongCount recompute failed: ' . $_e->getMessage());
        }

        logActivity('song.create', 'song', $songId, ['title' => $title, 'songbook' => $abbr]);
        ed2_respond(['ok' => true, 'songId' => $songId, 'title' => $title, 'songbook' => $abbr]);
        break;
    }

    /* ---- duplicate_song (POST, #1783) — copy an existing song into the hidden
           staging book (ED2_PENDING_SONGBOOK) as a starting point for a NEW
           songbook. The duplicate opens in the editor with EMPTY Songbook +
           Number (presented over the staging home); the curator assigns a real
           book + number, which re-keys it (songRelocate, #1679) into a brand-new
           song. Copy machinery is the revision-restore engine
           ed2_applySongSnapshot() — NO second copy loop (rule #22). ---- */
    case 'duplicate_song': {
        ed2_requireEntitlement('edit_songs');
        $sourceId = trim((string)($body['sourceId'] ?? ''));
        if ($sourceId === '') { ed2_respond(['ok' => false, 'error' => 'sourceId is required.'], 400); }

        /* Same builder load_song + a revision snapshot use — so the duplicate is
           exactly as faithful as a restore, via the same funnel. */
        $snap = ed2_buildSongSnapshot($db, $sourceId);
        if ($snap === null) { ed2_respond(['ok' => false, 'error' => 'Source song not found.'], 404); }
        /* Restore-first (#1694): a soft-deleted source is under review — the
           curator restores it before copying. Status IS the contract (rule #35). */
        if ((int)($snap['song']['IsDeleted'] ?? 0) === 1) {
            ed2_respond(['ok' => false, 'error' => 'Cannot duplicate a deleted song — restore it first.'], 409);
        }

        $title = mb_substr(trim((string)($snap['song']['Title'] ?? 'New Song')), 0, 500);
        if ($title === '') { $title = 'New Song'; }

        /* Reset identity/lifecycle on the snapshot copy BEFORE apply
           (ed2_applySongSnapshot writes ED2_META_FIELDS scalars from $snap['song']):
             Number NULL         — the owner's "empty song number" (set at assign)
             Verified 0          — not reviewed in its new context (D4)
             Isrc NULL           — recording-level id tied to media we don't copy (D2)
             HasAudio/HasSheet 0 — media rows are NOT copied (D3); flags must not
                                   claim media that isn't there.
           SongbookAbbr needs no reset — apply never writes it (#1679 exclusion). */
        $snap['song']['Number']        = null;
        $snap['song']['Verified']      = 0;
        $snap['song']['Isrc']          = null;
        $snap['song']['HasAudio']      = 0;
        $snap['song']['HasSheetMusic'] = 0;

        require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'song_public_id.php';

        /* Staging book is a durable fixture — ensure it in autocommit, before tx. */
        ed2_ensurePendingSongbook($db);
        $pendingAbbr = ED2_PENDING_SONGBOOK;

        $db->begin_transaction();
        try {
            $newId = ed2_allocateSongId($db, $pendingAbbr);
            $norm  = ed2_normalizeTitle($title);
            if (songPublicId_columnReady($db)) {
                $pubId = songPublicId_mintUnique($db);
                $ins = $db->prepare('INSERT INTO tblSongs (SongId, PublicId, Title, NormalizedTitle, SongbookAbbr) VALUES (?, ?, ?, ?, ?)');
                $ins->bind_param('sssss', $newId, $pubId, $title, $norm, $pendingAbbr);
            } else {
                $ins = $db->prepare('INSERT INTO tblSongs (SongId, Title, NormalizedTitle, SongbookAbbr) VALUES (?, ?, ?, ?)');
                $ins->bind_param('ssss', $newId, $title, $norm, $pendingAbbr);
            }
            $ins->execute();
            $ins->close();
            /* #1860 go-live — mint the duplicate's OWN permanent IL-id (ILS…),
               never copied from the source (a duplicate is a distinct row). */
            ilidStampNewRow($db, 'song', $newId, 'SongId');

            /* The bulk content copy: scalars (Ccli/Iswc kept, Isrc/Verified/media
               flags reset above), components + lyric lines + per-line chords,
               ArrangementJson, all six credit roles (+musicianPromote), tags, and
               external links — all through the ONE apply engine. */
            ed2_applySongSnapshot($db, $newId, $snap);

            /* Extra content NOT carried by ED2_META_FIELDS / the snapshot, copied
               explicitly. Each guarded so a missing optional table (un-migrated
               install) is skipped, never aborting the duplicate — the failed
               statement has no effect and the tx stays valid. */
            foreach ([
                'INSERT INTO tblSongKeys (SongId, OriginalKey, Tempo, TimeSignature) '
                    . 'SELECT ?, OriginalKey, Tempo, TimeSignature FROM tblSongKeys WHERE SongId = ?',
                'INSERT INTO tblSongAlternativeTitles (SongId, Title, Language, SortOrder, Note) '
                    . 'SELECT ?, Title, Language, SortOrder, Note FROM tblSongAlternativeTitles WHERE SongId = ?',
            ] as $copySql) {
                try {
                    $cp = $db->prepare($copySql);
                    $cp->bind_param('ss', $newId, $sourceId);
                    $cp->execute();
                    $cp->close();
                } catch (\Throwable $_ce) {
                    error_log('[editor duplicate_song] optional copy skipped: ' . $_ce->getMessage());
                }
            }
            /* Genre / IsExplicit / Availability — import-populated tblSongs columns
               (not in ED2_META_FIELDS). Best-effort UPDATE from the source row. */
            try {
                $g = $db->prepare('UPDATE tblSongs t JOIN tblSongs s ON s.SongId = ? '
                    . 'SET t.Genre = s.Genre, t.IsExplicit = s.IsExplicit, t.Availability = s.Availability '
                    . 'WHERE t.SongId = ?');
                $g->bind_param('ss', $sourceId, $newId);
                $g->execute();
                $g->close();
            } catch (\Throwable $_ge) {
                error_log('[editor duplicate_song] Genre/Explicit/Availability copy skipped: ' . $_ge->getMessage());
            }

            /* #1783 commit 4 — per-line enrichment (translations + annotations,
               #1088) and scripture refs (#1350) re-anchored onto the NEW lines.
               These three tables anchor on tblLyricLines.Id (rule #21/#1350), so
               a naive INSERT…SELECT would point the copies at the SOURCE's lines.
               ed2_applySongSnapshot() has already written the new song's lines
               from the SAME component payload, so the source and new primary line
               lists are same-length, same-order (lyricLinesFetchPrimary both in
               global SortOrder) — build a positional srcLineId → newLineId map and
               remap the copies. Best-effort: an un-migrated optional table
               (#1088/#1350) throws on its first statement → caught → the whole
               enrichment copy is skipped, never aborting the duplicate (a
               statement error does not roll back the tx; same contract as the
               $copySql block above). Any single-row mapping miss skips that row. */
            try {
                require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes'
                    . DIRECTORY_SEPARATOR . 'lyric_lines_read.php';
                $srcLines = lyricLinesFetchPrimary($db, $sourceId);
                $newLines = lyricLinesFetchPrimary($db, $newId);
                if ($srcLines && count($srcLines) === count($newLines)) {
                    /* Positional map + the NEW song's single ihymns LyricsId (the
                       denorm both enrichment tables carry — derived from the line,
                       never copied from the source, per the schema COMMENT). */
                    $lineMap = [];
                    $n = count($srcLines);
                    for ($i = 0; $i < $n; $i++) {
                        $lineMap[(int)$srcLines[$i]['line_id']] = (int)$newLines[$i]['line_id'];
                    }
                    $newLyricsId = null;
                    $lq = $db->prepare("SELECT Id FROM tblLyrics WHERE SongId = ? AND Source = 'ihymns' LIMIT 1");
                    $lq->bind_param('s', $newId);
                    $lq->execute();
                    $lr = $lq->get_result()->fetch_row();
                    $lq->close();
                    if ($lr) { $newLyricsId = (int)$lr[0]; }

                    $srcIds = array_keys($lineMap);
                    /* Generic remap-copy: INSERT one row per source row, columns
                       hardcoded (rule #5), FK/anchor columns remapped by $remap,
                       everything else verbatim. All-'s' bind types — mysqli
                       coerces ints and binds PHP null as SQL NULL. */
                    $copyRemapped = function (string $selSql, array $selBind, string $table, array $cols, callable $remap) use ($db) {
                        $sel = $db->prepare($selSql);
                        if ($selBind) { $sel->bind_param(str_repeat('s', count($selBind)), ...$selBind); }
                        $sel->execute();
                        $rows = $sel->get_result()->fetch_all(MYSQLI_ASSOC);
                        $sel->close();
                        if (!$rows) { return 0; }
                        $ph  = implode(',', array_fill(0, count($cols), '?'));
                        $ins = $db->prepare('INSERT INTO ' . $table . ' (' . implode(',', $cols) . ") VALUES ({$ph})");
                        $done = 0;
                        foreach ($rows as $row) {
                            $vals = $remap($row);
                            if ($vals === null) { continue; }   // mapping miss → skip this row
                            $ordered = array_map(static fn($c) => $vals[$c] ?? null, $cols);
                            $ins->bind_param(str_repeat('s', count($cols)), ...$ordered);
                            $ins->execute();
                            $done++;
                        }
                        $ins->close();
                        return $done;
                    };

                    if ($newLyricsId !== null && $srcIds) {
                        $inPh = implode(',', array_fill(0, count($srcIds), '?'));
                        $srcIdStr = array_map('strval', $srcIds);

                        /* (a) per-line translations / romanizations. */
                        $copyRemapped(
                            "SELECT LineId,Kind,TargetLanguage,TranslationType,Text,SortOrder,Source,SourceUrl,SourceRef,IsPrimary,IsAutoGenerated,Status,SubmittedBy,ApprovedBy,ApprovedAt,MetaJson FROM tblLyricLineTranslations WHERE LineId IN ({$inPh})",
                            $srcIdStr, 'tblLyricLineTranslations',
                            ['LineId','LyricsId','Kind','TargetLanguage','TranslationType','Text','SortOrder','Source','SourceUrl','SourceRef','IsPrimary','IsAutoGenerated','Status','SubmittedBy','ApprovedBy','ApprovedAt','MetaJson'],
                            function (array $r) use ($lineMap, $newLyricsId) {
                                $new = $lineMap[(int)$r['LineId']] ?? null;
                                if ($new === null) { return null; }
                                $r['LineId'] = $new; $r['LyricsId'] = $newLyricsId;
                                return $r;
                            }
                        );

                        /* (b) Genius-style annotations (span StartLineId + nullable
                           EndLineId; offsets are code-point indices into identical
                           text — copied verbatim, rule #21). */
                        $copyRemapped(
                            "SELECT StartLineId,EndLineId,StartOffset,EndOffset,AnnotationType,LanguageCode,Body,BodyFormat,SortOrder,Source,SourceUrl,SourceRef,Status,SubmittedBy,ApprovedBy,ApprovedAt,IsVerified,VerifiedBy,VerifiedAt,MetaJson FROM tblLyricLineAnnotations WHERE StartLineId IN ({$inPh})",
                            $srcIdStr, 'tblLyricLineAnnotations',
                            ['StartLineId','EndLineId','StartOffset','EndOffset','LyricsId','AnnotationType','LanguageCode','Body','BodyFormat','SortOrder','Source','SourceUrl','SourceRef','Status','SubmittedBy','ApprovedBy','ApprovedAt','IsVerified','VerifiedBy','VerifiedAt','MetaJson'],
                            function (array $r) use ($lineMap, $newLyricsId) {
                                $start = $lineMap[(int)$r['StartLineId']] ?? null;
                                if ($start === null) { return null; }
                                $r['StartLineId'] = $start;
                                $r['EndLineId']   = ($r['EndLineId'] !== null) ? ($lineMap[(int)$r['EndLineId']] ?? null) : null;
                                $r['LyricsId']    = $newLyricsId;
                                return $r;
                            }
                        );
                    }

                    /* (c) scripture refs — SongId re-pointed; a line-anchored ref
                       (StartLineId non-NULL) is remapped through $lineMap, a
                       whole-song ref (StartLineId NULL) copies as-is. Gated only
                       on the table (not on $newLyricsId — scripture refs carry no
                       LyricsId), but still inside the count-matched-lines block:
                       a source with zero lyric lines can carry no line map, and a
                       whole-song-only ref on a lyric-less song is a marginal case
                       we deliberately don't chase here. */
                    $copyRemapped(
                        'SELECT StartLineId,Book,Chapter,VerseStart,VerseEnd,OsisRef,Source,SortOrder FROM tblSongScriptureRefs WHERE SongId = ?',
                        [$sourceId], 'tblSongScriptureRefs',
                        ['SongId','StartLineId','Book','Chapter','VerseStart','VerseEnd','OsisRef','Source','SortOrder'],
                        function (array $r) use ($lineMap, $newId) {
                            $r['SongId']      = $newId;
                            $r['StartLineId'] = ($r['StartLineId'] !== null) ? ($lineMap[(int)$r['StartLineId']] ?? null) : null;
                            return $r;
                        }
                    );
                }
            } catch (\Throwable $_ee) {
                error_log('[editor duplicate_song] enrichment/scripture copy skipped: ' . $_ee->getMessage());
            }

            /* One forced 'duplicate' revision — a fresh trail; the source's
               revisions are never copied (rule: id-derived state). */
            ed2_touchRevision($db, $newId, $ed2UserId, 'duplicate', true);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }

        /* #1860 go-live — Works auto-link for the duplicate's OWN row.
           Post-commit (ownTransaction=true — the duplicate itself is
           already safely committed above); re-READ the stored Ccli/Iswc
           rather than trusting $snap (rule #35's read-back posture — the
           snapshot is the INPUT to ed2_applySongSnapshot(), not proof of
           what actually landed). Linking the duplicate to the SAME work as
           its source is correct — two renderings of one work.
           @deleted-visible: identifier read (#1860) — $newId is the row this
           very request just inserted and committed above; it cannot be
           soft-deleted, but the read is by direct SongId (editor-write-path
           posture), not a listing, so no filter is needed either way.
           @disabled-visible: same reasoning, one predicate over (#1765) —
           a song in the hidden staging book is still fully editable here. */
        $dupIdStmt = $db->prepare('SELECT Ccli, Iswc FROM tblSongs WHERE SongId = ? LIMIT 1');
        $dupIdStmt->bind_param('s', $newId);
        $dupIdStmt->execute();
        $dupIdRow = $dupIdStmt->get_result()->fetch_assoc() ?: ['Ccli' => '', 'Iswc' => null];
        $dupIdStmt->close();
        workAutolinkSafe($db, $newId, (string)($dupIdRow['Ccli'] ?? ''), (string)($dupIdRow['Iswc'] ?? ''), true);

        /* #1862 — the snapshot copy above (ed2_applySongSnapshot()) carried
           the source's full credit set onto $newId; the duplicate could
           already qualify for a PD suggestion the instant it exists.
           Post-commit, own failure boundary. */
        pdRecomputeForSong($db, $newId);

        /* Post-commit, best-effort (parity with create_song, #1742). */
        try { songbookRecomputeSongCount($db, $pendingAbbr); }
        catch (\Throwable $_e) { error_log('[editor duplicate_song] SongCount recompute failed: ' . $_e->getMessage()); }

        logActivity('song.duplicate', 'song', $newId, ['source' => $sourceId, 'title' => $title]);
        ed2_respond(['ok' => true, 'songId' => $newId, 'sourceId' => $sourceId, 'title' => $title]);
        break;
    }

    /* ---- delete_song (POST) — SOFT delete since #1694 commit 4. The old
           hard-delete cascade body moved VERBATIM into songPurge()
           (includes/song_soft_delete.php), reachable only from the deleted
           state on /manage/deleted-songs. This endpoint now hides the song:
           IsDeleted = 1 + who/when/why, restorable by an admin. ---- */
    case 'delete_song': {
        ed2_requireEntitlement('delete_songs');
        require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'song_soft_delete.php';
        $songId = trim((string)($body['songId'] ?? $body['id'] ?? ''));
        if ($songId === '') { ed2_respond(['ok' => false, 'error' => 'songId is required.'], 400); }
        /* `redirectTo` is ACCEPTED AND IGNORED (#1694, deliberately — not
           silently dropped: the response says so below). A soft delete writes
           NOTHING to tblSongRedirects, because a songRedirectRepoint() cannot
           be un-written and restore must be a no-op on redirect state (the
           #1679 stranded-chain class). The relink happens at PURGE, where the
           old, well-tested redirect dance runs unchanged. */
        $redirectTo = trim((string)($body['redirectTo'] ?? ''));
        /* Optional vocabulary the UI may grow into: a songDeleteReasons() key
           + a free-text note. Unknown reasons refuse with 422 (rule #20's
           allow-list; status is the contract, rule #35). */
        $reason = trim((string)($body['reason'] ?? ''));
        $note   = trim((string)($body['note'] ?? ''));

        $verdict = songSoftDelete($db, $songId, $ed2UserId, $reason === '' ? null : $reason, $note);
        if (!$verdict['ok']) {
            /* 400 empty id / 404 absent / 409 un-migrated or already deleted /
               422 unknown reason — relayed as-is; clients branch on STATUS. */
            ed2_respond(['ok' => false, 'error' => $verdict['error'], 'deleted' => 0], $verdict['status']);
        }
        logActivity('song.soft_delete', 'song', $songId, [
            'title'    => (string)($verdict['title'] ?? ''),
            'songbook' => (string)($verdict['songbook'] ?? ''),
            'reason'   => $reason === '' ? null : $reason,
            'note'     => $note,
        ]);
        ed2_respond([
            'ok'          => true,
            'deleted'     => 1,               /* back-compat: the one song left the catalogue */
            'softDeleted' => true,            /* NEW — restorable from /manage/deleted-songs */
            'songId'      => $songId,
            'redirectTo'  => null,            /* no redirect is ever written at soft-delete time */
        ] + ($redirectTo !== '' ? [
            'notice' => 'redirectTo is ignored on a soft delete — choose the relink target when the song is purged from /manage/deleted-songs (#1694).',
        ] : []));
        break;
    }

    /* ---- metadata_field_update (POST) — one scalar tblSongs field ---- */
    case 'metadata_field_update': {
        $songId = trim((string)($body['songId'] ?? ''));
        $field  = (string)($body['field'] ?? '');
        /* #1847 — split the old combined "songId + a known field are required"
           400 into two DISTINCT, self-explaining messages so a future
           occurrence is diagnosable at a glance (rule #35: the message is a
           real diagnostic, not decoration). An EMPTY songId reaching here is
           the confusing one — the current editor shell can never send it (every
           metadata_field_update carries the loaded song's id), so if a client
           does, it is running a build behind this one or a stale-cached editor
           module; the message tells the curator how to self-recover. */
        if ($songId === '') {
            ed2_respond(['ok' => false, 'error' => 'No song id was sent — reload the song (hard-refresh: Ctrl/Cmd+Shift+R) and try again.'], 400);
        }
        if (!isset(ED2_META_FIELDS[$field])) {
            ed2_respond(['ok' => false, 'error' => 'That field is not editable here (unrecognised metadata field).'], 400);
        }
        if (!ed2_songExists($db, $songId)) { ed2_respond(['ok' => false, 'error' => 'Song not found.'], 404); }

        [$column, $type] = ED2_META_FIELDS[$field];
        $raw = $body['value'] ?? null;

        /* ---- #1741 P1 existence gate ---------------------------------------
           Five of the identity columns above may not exist yet on an install
           that hasn't run the "song-identity-fields" migration card. `Isrc`
           (#1064) is NOT in this map (it predates P1), so it always falls
           through. A write to an absent column would otherwise throw a raw
           mysqli_sql_exception under STRICT (500) instead of the clear,
           branchable 409 rule #35 asks for. */
        $ed2IdentityPresence = ed2_songIdentityColsPresent($db);
        if (array_key_exists($column, $ed2IdentityPresence) && !$ed2IdentityPresence[$column]) {
            ed2_respond(['ok' => false, 'error' => 'This install has not applied the song-identity-fields migration card yet (run it at /manage/setup-database).'], 409);
        }

        /* ---- #1741 P5a / #1749 P5d — ISRC: canonicalise + shape-validate --
           ISRC is a recording-grain code the app must normalise BEFORE it is
           stored, never the curator's raw text — `/isrc/`'s indexed exact
           match (identifier_resolve.php) and the P1 canonical backfill both
           assume every stored value already went through
           ihymns_canonical_isrc(). Re-assigning the canonical form into $raw
           feeds it through the SAME generic coercion + UPDATE + transaction
           every other field uses below (incl. the empty-string → NULL fold),
           rather than forking a second UPDATE/transaction/response for one
           field. The dual-write mirror (§4.3) hooks into that shared
           transaction further down, right after the UPDATE executes. */
        if ($column === 'Isrc') {
            $isrcCanon = ihymns_canonical_isrc((string)($raw ?? ''));
            if ($isrcCanon !== '' && !mediaIdentifierValidateValue('isrc', $isrcCanon)) {
                /* A decided default (P5 plan §1.2-2), trivially loosenable if a
                   curator ever hits a genuine nonstandard code — the RESOLVE
                   path (identifier_resolve.php) stays tolerant by design; only
                   the WRITE is strict. Status is the contract, not this prose
                   (rule #35). */
                ed2_respond(['ok' => false, 'error' => 'ISRC must be a 12-character code (2-letter country + 3-character registrant + 7 digits), e.g. USABC1234567.'], 422);
            }
            $raw = $isrcCanon;
        }

        /* ---- #1679 — SONGBOOK MOVE is not a scalar update ----------------
           `tblSongbooks.Abbreviation` IS the SongId prefix (rule #27), so
           writing SongbookAbbr through the generic UPDATE below would leave the
           id claiming the OLD book forever. Branch out to the shared re-key
           helper instead: it mints the new id, cascades every child row, clears
           Number, rewrites BOTH non-FK soft references (the content-restriction
           rows and the tblSongbookEntries home row — #1679 F3 found the second
           one; this comment named only the first until A13d) and leaves a
           tblSongRedirects row so old permalinks keep resolving.
           The generic path opens no transaction (a single UPDATE needs none) —
           a move is several statements, so it gets its own. */
        if ($column === 'SongbookAbbr') {
            $targetAbbr = trim((string)($raw ?? ''));
            if ($targetAbbr === '') {
                ed2_respond(['ok' => false, 'error' => 'A target songbook abbreviation is required.'], 400);
            }
            require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'song_relocate.php';
            $db->begin_transaction();
            try {
                $rel = songRelocate($db, $songId, $targetAbbr, $ed2UserId);
                ed2_touchRevision($db, $rel['songId'], $ed2UserId, 'metadata');
                $db->commit();
            } catch (\InvalidArgumentException $e) {
                /* The abbreviation is a free-text field, so a typo is ordinary
                   user input, not a server fault — 422, with the reason. Caught
                   SEPARATELY from \Throwable because mysqli_sql_exception is a
                   RuntimeException: a single broad catch would report a real
                   database failure as "you typed the wrong book". Clients branch
                   on the STATUS, never on this sentence (rule #35). */
                $db->rollback();
                ed2_respond(['ok' => false, 'error' => $e->getMessage()], 422);
            } catch (\Throwable $e) {
                $db->rollback();
                throw $e;
            }
            logActivity('song.metadata', 'song', $rel['songId'], [
                'field'         => $field,
                'songbook'      => $targetAbbr,
                'previous_book' => $rel['previousSongbookAbbr'],
                'previous_id'   => $rel['previousId'],
                'renamed'       => $rel['renamed'],
            ]);
            /* `previousId` !== `songId` is the client's signal to re-open the song
               under its new id (the shell re-keys its in-memory id + the ?song=
               URL). Always present so the client has one thing to compare, and
               equal on a no-op move. */
            ed2_respond([
                'ok'         => true,
                'field'      => $field,
                'songId'     => $rel['songId'],
                'previousId' => $rel['previousId'],
            ]);
        }

        /* ---- #1741 P5c — TUNE is not a scalar update either ---------------
           Writing TuneName through the generic coercion + UPDATE below would
           strand TuneId — the exact drift this phase retires (§3.3-3 of the
           plan). `tuneName` stays a valid wire-contract KEY (rule #33: a
           stale Service-Worker-cached metadata-tab.js may still send it) but
           is now an ALIAS into the SAME shared lockstep core `song_tune_set`
           uses (`ed2_songTuneApply()`), never the bare column write. Mirrors
           the SongbookAbbr branch immediately above in shape: own
           transaction, own response, nothing below it runs for this column. */
        if ($column === 'TuneName') {
            $db->begin_transaction();
            try {
                $tuneResult = ed2_songTuneApply($db, $songId, (string)($raw ?? ''));
                ed2_touchRevision($db, $songId, $ed2UserId, 'metadata');
                $db->commit();
            } catch (\Throwable $e) {
                $db->rollback();
                throw $e;
            }
            logActivity('song.metadata', 'song', $songId, ['field' => $field, 'tuneId' => $tuneResult['tuneId']]);
            ed2_respond(['ok' => true, 'field' => 'tuneName', 'tuneId' => $tuneResult['tuneId']]);
        }

        /* ---- #1862 — COPYRIGHT HOLDER is not a generic scalar update either ----
           Writing CopyrightHolder through the generic coercion + UPDATE below
           would leave CopyrightHolderId stranded — the exact TuneId-stranding
           drift class rule #33 names. `copyrightHolder` stays a valid
           wire-contract KEY (a stale Service-Worker-cached metadata-tab.js may
           still send the OLD plain field) but is now an ALIAS into the SAME
           shared lockstep core `song_copyright_holder_set` uses
           (`ed2_songCopyrightHolderApply()`), never the bare column write.
           Mirrors the TuneName branch immediately above in shape — but never a
           picker-claimed publisherId here: a stale client sending this KEY
           never carried one, so this always resolves via the name-only
           find-or-create funnel (ed2_songCopyrightHolderApply()'s own
           $claimedId=null path — a genuine picker pick goes through
           song_copyright_holder_set instead, which DOES carry the claimed id). */
        if ($column === 'CopyrightHolder') {
            $db->begin_transaction();
            try {
                $holderResult = ed2_songCopyrightHolderApply($db, $songId, (string)($raw ?? ''), null, $ed2UserId);
                ed2_touchRevision($db, $songId, $ed2UserId, 'metadata');
                $db->commit();
            } catch (\Throwable $e) {
                $db->rollback();
                throw $e;
            }
            logActivity('song.metadata', 'song', $songId, ['field' => $field, 'publisherId' => $holderResult['publisherId']]);
            ed2_respond(['ok' => true, 'field' => 'copyrightHolder', 'value' => $holderResult['holderName'], 'publisherId' => $holderResult['publisherId']]);
        }

        /* ---- #1862 — HasAudio / HasSheetMusic are DERIVED, never curator-set --
           The manual checkboxes were removed from the Editor2 client (rule #44
           — a value the app can already derive gets no editable control), but
           the two keys STAY in ED2_META_FIELDS (rule #33 — a stale cached
           client may still send one). This branch IGNORES whatever value the
           client sent, recomputes the honest UNION
           (songMediaRecomputeFlags(), includes/song_media_flags.php) and
           echoes the DERIVED truth back — so a stale checkbox click "saves"
           nothing but a snap-back to reality, never a lie the client typed. */
        if ($column === 'HasAudio' || $column === 'HasSheetMusic') {
            songMediaRecomputeFlags($db, $songId);
            /* Column name comes from the ED2_META_FIELDS constant only. */
            $derived = $db->prepare("SELECT `{$column}` FROM tblSongs WHERE SongId = ? LIMIT 1");
            $derived->bind_param('s', $songId);
            $derived->execute();
            $derivedRow = $derived->get_result()->fetch_assoc() ?: [$column => 0];
            $derived->close();
            ed2_respond(['ok' => true, 'field' => $field, 'value' => (int)$derivedRow[$column]]);
        }

        /* ---- #1769 P4 — RIGHTS FACTS are not a generic scalar update -------
           A per-song rights key must be either cleared ('' → NULL) or a licence
           key that EXISTS in the live registry — never arbitrary free text (the
           generic path would happily store a typo). Self-contained like the
           SongbookAbbr / TuneName branches above: own existence gate (409 on an
           un-migrated install, rule #9/#35), own entitlement (edit_songs — the
           PD-flag class, P4 D3; equivalence-neutral at the default entitlement
           map, which is exactly this file's editor-role gate), own validation
           (422), own before/after audit key (admin.song.rights_set), own
           response. Nothing ENFORCES on the stored fact until P6. */
        if ($column === 'LyricsRightsLicenceKey' || $column === 'MusicRightsLicenceKey') {
            $ed2RightsPresence = ed2_rightsColsPresent($db);
            if (empty($ed2RightsPresence[$column])) {
                ed2_respond(['ok' => false, 'error' => 'This install has not applied the gating-facts migration card yet (run it at /manage/setup-database).'], 409);
            }
            ed2_requireEntitlement('edit_songs');
            require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'licence_registry.php';
            $rightsKey = trim((string)($raw ?? ''));
            if ($rightsKey !== '' && !in_array($rightsKey, licenceTypeKeys($db), true)) {
                /* Status is the contract (rule #35) — the panel branches on 422. */
                ed2_respond(['ok' => false, 'error' => 'Unknown licence key "' . $rightsKey . '". Pick one defined on /manage/licence-types.'], 422);
            }
            $rightsValue = $rightsKey === '' ? null : $rightsKey;

            /* Before/after for the audit — read the current value first.
               @deleted-visible: editor before/after audit read (#1694 / #1769 P4)
               — the curator is editing THIS exact song by direct id (the same
               editor-context rationale as ed2_buildSongSnapshot's load read); a
               soft-deleted song's rights value must still be readable to record
               the change, and this reads only the one rights column, never lists.
               @disabled-visible: same editor-context rationale for #1765 — a song
               in a disabled songbook is still editable by direct id in the admin
               editor; this single-song by-id read must not filter on book visibility. */
            $prev = null;
            $ps = $db->prepare("SELECT `{$column}` FROM tblSongs WHERE SongId = ? LIMIT 1");
            $ps->bind_param('s', $songId);
            $ps->execute();
            $prevRow = $ps->get_result()->fetch_assoc();
            $ps->close();
            if ($prevRow) { $prev = $prevRow[$column]; }

            $db->begin_transaction();
            try {
                $u = $db->prepare("UPDATE tblSongs SET `{$column}` = ? WHERE SongId = ?");
                if ($rightsValue === null) { $np = null; $u->bind_param('ss', $np, $songId); }
                else { $u->bind_param('ss', $rightsValue, $songId); }
                $u->execute();
                $u->close();
                ed2_touchRevision($db, $songId, $ed2UserId, 'metadata');
                $db->commit();
            } catch (\Throwable $e) {
                $db->rollback();
                throw $e;
            }
            logActivity('admin.song.rights_set', 'song', $songId, [
                'field'  => $field,
                'column' => $column,
                'from'   => ($prev === '' ? null : $prev),
                'to'     => $rightsValue,
            ]);
            ed2_respond(['ok' => true, 'field' => $field, 'value' => $rightsValue]);
        }

        /* Coerce per the allow-listed type; numberless/empty → NULL where the
           column allows it (Number/originCityId/firstPublishedYear/
           Iswc/OriginCity/Isrc/Subtitle are nullable; TuneName is handled by
           the dedicated branch above and never reaches here). */
        if ($type === 'i') {
            if ($field === 'number' || $field === 'originCityId') {
                /* nullable ints (song number / place FK): empty or <=0 → NULL */
                $value = ($raw === null || $raw === '' || (int)$raw <= 0) ? null : (int)$raw;
            } elseif ($field === 'firstPublishedYear') {
                /* #1741 P1 — SMALLINT UNSIGNED, nullable; empty/<=0 → NULL,
                   else a 500..2100 range check (mirrors works.php's identical
                   SMALLINT-not-YEAR bounds, P4 plan §2.4.3 — YEAR starts 1901
                   and hymns predate it). Status is the contract (rule #35). */
                $value = ($raw === null || $raw === '' || (int)$raw <= 0) ? null : (int)$raw;
                if ($value !== null && ($value < 500 || $value > 2100)) {
                    ed2_respond(['ok' => false, 'error' => 'First published year must be a year between 500 and 2100.'], 422);
                }
            } else {
                $value = (int)((bool)$raw);   // flags
            }
        } else {
            $value = $raw === null ? '' : trim((string)$raw);
            /* 'TuneName' deliberately absent from this list (#1741 P5c) — the
               dedicated branch above always returns before this line runs
               for that column; leaving it here would imply the generic path
               can still write TuneName alone, which is exactly the drift
               this phase retires. */
            if (in_array($column, ['Iswc', 'OriginCity', 'Isrc', 'Subtitle'], true) && $value === '') { $value = null; }
        }

        $db->begin_transaction();
        /* #1749 full unification — the mirror's PROJECTED echo (only set on
           the Isrc branch below); null everywhere else so the response
           builder after the try/catch can branch on isset() without a
           second flag. See that branch's own comment for why this exists. */
        $isrcFinal = null;
        try {
            /* Column name comes from the ED2_META_FIELDS constant only. */
            $u = $db->prepare("UPDATE tblSongs SET `{$column}` = ? WHERE SongId = ?");
            if ($value === null) {
                $nullParam = null;
                $u->bind_param('ss', $nullParam, $songId);   // mysqli sends NULL for a null var
            } else {
                $u->bind_param($type . 's', $value, $songId);
            }
            $u->execute();
            $u->close();
            /* #1749 P5d / full unification — dual-write the canonical ISRC
               into tblSongExternalIds, INSIDE this same transaction as the
               tblSongs UPDATE above (a rollback must never leave the mirror
               row alone with a stale tblSongs.Isrc, or vice versa).
               Table-absent is already a no-op inside the helper (its own
               memoised probe) — unswallowed here, so a genuine DB fault still
               rolls the whole save back honestly. #1749 (D-1) — the mirror
               now RETURNS the store's projected value (its embedded
               songExternalIdSyncIsrcDenorm() call has "the last word"), which
               can legitimately differ from $value: clearing the field while
               a manual second-recording row still exists in the store
               PROMOTES that row's value back into the column (§2.1) — the
               echo below is how the editor shows that immediately instead of
               only on next page load. */
            if ($column === 'Isrc') {
                $isrcFinal = songExternalIdMirrorIsrc($db, $songId, $value);
            }
            /* Title drives NormalizedTitle too. */
            if ($field === 'title') {
                $norm = ed2_normalizeTitle((string)$value);
                $un = $db->prepare('UPDATE tblSongs SET NormalizedTitle = ? WHERE SongId = ?');
                $un->bind_param('ss', $norm, $songId);
                $un->execute();
                $un->close();
            }
            ed2_touchRevision($db, $songId, $ed2UserId, 'metadata');
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }

        /* #1860 go-live — a committed CCLI/ISWC write re-runs the work
           auto-link server-side (fail-safe, own txn, additive response
           key). Without this hook go-live would only cover the legacy
           whole-song save; Editor2 saves these fields per-field via THIS
           action, so its songs would stay unlinked until the Phase-5 client
           badge ships (rule #35 — the invariant must not depend on future
           client wiring). The Phase-5 badge will call song_work_autolink
           for the same result; both routes hit the ONE core
           (workAutolinkSafe -> workFindOrLinkByIdentifier) so they cannot
           diverge. own-transaction mode (true): THIS field's own txn is
           already committed above, so a swallowed link failure here still
           leaves the field save fully intact. */
        $workAutolink = null;
        if ($column === 'Ccli' || $column === 'Iswc') {
            /* @deleted-visible / @disabled-visible: identifier read (#1860)
               — the exact SELECT song_work_autolink uses (this action has
               already confirmed the song exists, via ed2_songExists() near
               the top of this case), same editor-write-path posture. */
            $idStmt = $db->prepare('SELECT Ccli, Iswc FROM tblSongs WHERE SongId = ? LIMIT 1');
            $idStmt->bind_param('s', $songId);
            $idStmt->execute();
            $idRow = $idStmt->get_result()->fetch_assoc() ?: ['Ccli' => '', 'Iswc' => null];
            $idStmt->close();
            $workAutolink = workAutolinkSafe(
                $db,
                $songId,
                (string)($idRow['Ccli'] ?? ''),
                (string)($idRow['Iswc'] ?? ''),
                true
            );
        }

        logActivity('song.metadata', 'song', $songId, ['field' => $field]);
        /* #1749 — for field=isrc, echo the STORE's projected value (never
           the caller's raw $value): a clear-with-manual promotion (§2.1) is
           then visible in the very response the client is already awaiting,
           instead of only on next page load. $isrcFinal stays null for every
           OTHER field, so `?? $value` degrades to exactly the pre-#1749
           echo — additive-only, no response-shape change for non-isrc
           fields. */
        ed2_respond(['ok' => true, 'field' => $field, 'value' => $isrcFinal ?? $value]
            + ($workAutolink !== null ? ['workAutolink' => $workAutolink] : []));
        break;
    }

    /* ---- song_tune_set (POST) — the ONE tune write (#1741 P5c). Same
           shared core (ed2_songTuneApply()) `metadata_field_update`'s
           `tuneName` alias branch delegates to, so there is exactly one
           write path regardless of which action a caller (current or
           stale-cached) uses. `tuneName` MAY be '' — that is a legal clear,
           distinct from the key being absent entirely (a caller mistake). ---- */
    case 'song_tune_set': {
        $songId = trim((string)($body['songId'] ?? ''));
        if ($songId === '' || !array_key_exists('tuneName', $body)) {
            ed2_respond(['ok' => false, 'error' => 'songId + tuneName (may be empty, to clear) are required.'], 400);
        }
        if (!ed2_songExists($db, $songId)) { ed2_respond(['ok' => false, 'error' => 'Song not found.'], 404); }

        $db->begin_transaction();
        try {
            $tuneResult = ed2_songTuneApply($db, $songId, (string)$body['tuneName']);
            ed2_touchRevision($db, $songId, $ed2UserId, 'metadata');
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
        logActivity('song.metadata', 'song', $songId, ['field' => 'tune', 'tuneId' => $tuneResult['tuneId']]);
        ed2_respond([
            'ok'        => true,
            'field'     => 'tune',
            'tuneId'    => $tuneResult['tuneId'],
            'tuneName'  => $tuneResult['tuneName'],
            'slug'      => $tuneResult['slug'],
            'meterCode' => $tuneResult['meterCode'],
        ]);
        break;
    }

    /* ---- song_copyright_holder_set (POST) — the ONE copyright-holder write
           (#1862, activating the #1864 dormant tblSongs.CopyrightHolderId).
           Same shared core (ed2_songCopyrightHolderApply()) the
           `CopyrightHolder` alias branch inside metadata_field_update
           delegates to — mirrors song_tune_set immediately above in every
           respect: `name` MAY be '' (a legal clear), `publisherId` is the
           picker's CLAIMED id (trust-but-verify inside the write core —
           never written unverified), and the existence gate 409s on the
           SAME #1741 P1 identity-column presence map CopyrightHolder
           already uses (ed2_songIdentityColsPresent(), so this endpoint
           can't 500 under STRICT on an un-migrated install). ---- */
    case 'song_copyright_holder_set': {
        $songId = trim((string)($body['songId'] ?? ''));
        if ($songId === '' || !array_key_exists('name', $body)) {
            ed2_respond(['ok' => false, 'error' => 'songId + name (may be empty, to clear) are required.'], 400);
        }
        if (!ed2_songExists($db, $songId)) { ed2_respond(['ok' => false, 'error' => 'Song not found.'], 404); }
        $ed2IdentityPresence = ed2_songIdentityColsPresent($db);
        if (!$ed2IdentityPresence['CopyrightHolder']) {
            ed2_respond(['ok' => false, 'error' => 'This install has not applied the song-identity-fields migration card yet (run it at /manage/setup-database).'], 409);
        }
        $claimedId = isset($body['publisherId']) && $body['publisherId'] !== null && $body['publisherId'] !== ''
            ? (int)$body['publisherId'] : null;

        $db->begin_transaction();
        try {
            $holderResult = ed2_songCopyrightHolderApply($db, $songId, (string)$body['name'], $claimedId, $ed2UserId);
            ed2_touchRevision($db, $songId, $ed2UserId, 'metadata');
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
        logActivity('song.metadata', 'song', $songId, ['field' => 'copyrightHolder', 'publisherId' => $holderResult['publisherId']]);
        ed2_respond([
            'ok'          => true,
            'field'       => 'copyrightHolder',
            'holderName'  => $holderResult['holderName'],
            'publisherId' => $holderResult['publisherId'],
        ]);
        break;
    }

    /* ---- song_copyright_holders (GET) — the ordered multi-holder list
           (#1900 Wave 4 C8). Read counterpart of song_copyright_holders_set
           immediately below; delegates entirely to the ONE #1900 core
           (songCopyrightHoldersList(), includes/song_copyright_holders.php)
           — no local SQL. 409 (never an empty-list 200) on an un-migrated
           install, the SAME honest-409 posture song_copyright_holder_set
           above already uses for its OWN (different) migration gate — this
           is a DIFFERENT table (tblSongCopyrightHolders) from that
           endpoint's song-identity-fields column check, so it needs its own
           presence probe. metadata-tab.js's chip list feature-detects by
           THIS status (err.status === 409), never by the error sentence
           (rule #35). ---- */
    case 'song_copyright_holders': {
        $songId = trim((string)($_GET['id'] ?? ''));
        if ($songId === '') { ed2_respond(['ok' => false, 'error' => 'id is required.'], 400); }
        if (!ed2_songExists($db, $songId)) { ed2_respond(['ok' => false, 'error' => 'Song not found.'], 404); }

        if (!songCopyrightHoldersTableExists($db)) {
            ed2_respond(['ok' => false, 'error' => 'This install has not applied the multi-holder-copyright migration card yet (run it at /manage/setup-database).'], 409);
        }

        ed2_respond(['ok' => true, 'holders' => songCopyrightHoldersList($db, $songId)]);
        break;
    }

    /* ---- song_copyright_holders_set (POST) — replace the FULL ordered
           holder list (#1900 Wave 4 C8). The multi-pick sibling of
           song_copyright_holder_set above: that endpoint (and
           metadata_field_update's CopyrightHolder alias branch) write ONE
           holder through ed2_songCopyrightHolderApply(), which — on a
           migrated install — now itself delegates to the SAME core this
           case calls directly (songCopyrightHoldersReplace()), so there is
           exactly ONE write path into tblSongCopyrightHolders + the
           tblSongs denorm mirror regardless of which UI surface (the
           single field or the chip list) a curator used (rule #22/#35 — no
           second resolve/write path). `$ownTransaction=false` — THIS case
           owns the transaction (+ the revision touch); the core borrows it
           and RE-THROWS on a write failure, so the catch below rolls back
           both the holder rows and the (not-yet-taken) revision snapshot
           together, never one without the other. A `bad_role` rejection is
           422 (a curator/client mistake — an unknown role string), never
           500; the response always carries the machine-readable `reason`
           so the client branches on THAT, not on prose. ---- */
    case 'song_copyright_holders_set': {
        $songId = trim((string)($body['songId'] ?? ''));
        if ($songId === '') { ed2_respond(['ok' => false, 'error' => 'songId is required.'], 400); }
        if (!ed2_songExists($db, $songId)) { ed2_respond(['ok' => false, 'error' => 'Song not found.'], 404); }
        if (!songCopyrightHoldersTableExists($db)) {
            ed2_respond(['ok' => false, 'error' => 'This install has not applied the multi-holder-copyright migration card yet (run it at /manage/setup-database).'], 409);
        }

        /* Defensive shape coercion — never trust the client's array
           structure blindly. A non-array entry is dropped outright; the
           core's own normalisation pass (_songCopyHolders_normalizeRows())
           handles de-dup/ordering once these are shape-safe. `publisherId`
           uses the SAME `!== null && !== ''` guard song_copyright_holder_set
           above uses for its single `publisherId`. */
        $rawHolders = is_array($body['holders'] ?? null) ? $body['holders'] : [];
        $rows = [];
        foreach ($rawHolders as $h) {
            if (!is_array($h)) { continue; }
            $rows[] = [
                'name'        => isset($h['name']) ? (string)$h['name'] : '',
                'publisherId' => isset($h['publisherId']) && $h['publisherId'] !== null && $h['publisherId'] !== ''
                               ? (int)$h['publisherId'] : null,
                'role'        => isset($h['role']) ? (string)$h['role'] : 'holder',
            ];
        }

        $db->begin_transaction();
        try {
            $res = songCopyrightHoldersReplace($db, $songId, $rows, $ed2UserId, false);
            if (!$res['ok']) {
                /* A validation rejection (bad_role) — nothing was written.
                   Roll back (no-op for the DB, but keeps this path
                   symmetrical with the exception path below) and respond
                   WITHOUT falling through to ed2_touchRevision()/commit() —
                   rule #35: never 200 a failed write. */
                $db->rollback();
                ed2_respond(
                    ['ok' => false, 'error' => 'Could not save copyright holders.', 'reason' => $res['reason'], 'holders' => $res['holders']],
                    $res['reason'] === 'bad_role' ? 422 : 500
                );
            }
            ed2_touchRevision($db, $songId, $ed2UserId, 'metadata');
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
        logActivity('song.metadata', 'song', $songId, ['field' => 'copyrightHolders', 'count' => count($res['holders'])]);
        ed2_respond(['ok' => true, 'field' => 'copyrightHolders', 'holders' => $res['holders']]);
        break;
    }

    /* ---- song_work_autolink (POST) — Editor2's post-save CCLI/ISWC commit
           hook (#1860 Phase 3, design §3.3/§3.7; the client wiring itself is
           a FOLLOW-UP build — this endpoint exists now so that build has a
           contract to code against). Server-authoritative: reads the song's
           STORED Ccli/Iswc from tblSongs, never a client-sent identifier
           value (rule #35's read-back posture) — the request carries only
           songId. A work-link CONFLICT is a field on the 200 body, never an
           HTTP failure (design §3.3 preamble — a work-link ambiguity must
           never cost a curator their song save). No ed2_touchRevision() —
           work membership sits outside the content snapshot, the same
           posture as tblSongLinks / song_external_id_add above. ---- */
    case 'song_work_autolink': {
        $songId = trim((string)($body['songId'] ?? ''));
        if ($songId === '') { ed2_respond(['ok' => false, 'error' => 'songId is required.'], 400); }
        if (!ed2_songExists($db, $songId)) { ed2_respond(['ok' => false, 'error' => 'Song not found.'], 404); }
        if (!workAdminReady($db)) {
            ed2_respond(['ok' => false, 'error' => 'This install has not applied the work-identity migration cards yet (run them at /manage/setup-database).'], 409);
        }

        /* @deleted-visible: identifier read (#1860) — ed2_songExists() just
           above already confirmed this SongId exists (deliberately visible
           to a soft-deleted row, by that function's own reasoning); reading
           its CCLI/ISWC to plan a work link is the same editor-write-path
           posture, never a public listing.
           @disabled-visible: same reasoning, one predicate over (#1765) —
           a song in a publicly-disabled book is still fully editable here. */
        $idStmt = $db->prepare('SELECT Ccli, Iswc FROM tblSongs WHERE SongId = ? LIMIT 1');
        $idStmt->bind_param('s', $songId);
        $idStmt->execute();
        $idRow = $idStmt->get_result()->fetch_assoc() ?: ['Ccli' => '', 'Iswc' => null];
        $idStmt->close();

        $db->begin_transaction();
        try {
            $result = workFindOrLinkByIdentifier(
                $db,
                $songId,
                (string)($idRow['Ccli'] ?? ''),
                (string)($idRow['Iswc'] ?? '')
            );
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }

        logActivity('song.work_autolink', 'song', $songId, [
            'workId'   => $result['workId'],
            'created'  => $result['created'],
            'rehomed'  => $result['rehomed'],
            'conflict' => $result['conflict'],
        ]);
        ed2_respond([
            'ok'            => true,
            'linked'        => $result['linked'],
            'workId'        => $result['workId'],
            'workTitle'     => $result['workTitle'],
            'workSlug'      => $result['workSlug'],
            'songCount'     => $result['songCount'],
            'created'       => $result['created'],
            'createdParent' => $result['createdParent'],
            'rehomed'       => $result['rehomed'],
            'refined'       => $result['refined'],
            'conflict'      => $result['conflict'],
            'iswcInvalid'   => $result['iswcInvalid'],
        ]);
        break;
    }

    /* ---- song_work_set (POST) — manual "Part of work" picker (#1860 Phase
           3, design §3.7.2). Exactly one mode per request: link to an
           existing work ({songId, workId}), find-or-create by title for
           identifier-less hymns ({songId, title}), or unlink ({songId,
           workId, unlink:true} — the ONLY manual-unlink surface, §3.3.1
           reserves unlink to humans). No ed2_touchRevision() — same posture
           as song_work_autolink above. ---- */
    case 'song_work_set': {
        $songId = trim((string)($body['songId'] ?? ''));
        if ($songId === '') { ed2_respond(['ok' => false, 'error' => 'songId is required.'], 400); }
        if (!ed2_songExists($db, $songId)) { ed2_respond(['ok' => false, 'error' => 'Song not found.'], 404); }
        if (!workAdminReady($db)) {
            ed2_respond(['ok' => false, 'error' => 'This install has not applied the work-identity migration cards yet (run them at /manage/setup-database).'], 409);
        }

        $hasWorkId = array_key_exists('workId', $body) && (int)$body['workId'] > 0;
        $hasTitle  = array_key_exists('title', $body) && trim((string)$body['title']) !== '';
        $unlink    = !empty($body['unlink']);

        if ($unlink) {
            if (!$hasWorkId) { ed2_respond(['ok' => false, 'error' => 'workId is required to unlink.'], 400); }
            $workId = (int)$body['workId'];
            $db->begin_transaction();
            try {
                $deleted = workUnlinkSongRow($db, $workId, $songId);
                $db->commit();
            } catch (\Throwable $e) {
                $db->rollback();
                throw $e;
            }
            logActivity('song.work_unlink', 'song', $songId, ['workId' => $workId, 'deleted' => $deleted]);
            ed2_respond(['ok' => true, 'unlinked' => true, 'deleted' => $deleted]);
            break;
        }

        if ($hasWorkId === $hasTitle) {
            /* Neither, or both, supplied — exactly one mode is required. */
            ed2_respond(['ok' => false, 'error' => 'Provide exactly one of workId or title.'], 400);
        }

        $created = false;
        $db->begin_transaction();
        try {
            if ($hasWorkId) {
                $workId = (int)$body['workId'];
                if (!workExists($db, $workId)) {
                    $db->rollback();
                    ed2_respond(['ok' => false, 'error' => 'Work not found.'], 404);
                }
            } else {
                $found = workFindOrCreateByTitle($db, (string)$body['title']);
                /* $found is never null here — $hasTitle already proved the
                   trimmed title is non-empty. */
                $workId  = $found['id'];
                $created = $found['created'];
            }
            workLinkSongRow($db, $workId, $songId);
            $snap = workSnapshot($db, $workId);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }

        logActivity('song.work_set', 'song', $songId, ['workId' => $workId, 'created' => $created]);
        ed2_respond([
            'ok'            => true,
            'linked'        => true,
            'workId'        => $snap['workId']    ?? $workId,
            'workTitle'     => $snap['workTitle'] ?? null,
            'workSlug'      => $snap['workSlug']  ?? null,
            'songCount'     => $snap['songCount'] ?? 0,
            'created'       => $created,
            'createdParent' => false,
            'rehomed'       => false,
            'refined'       => false,
            'conflict'      => null,
            'iswcInvalid'   => false,
        ]);
        break;
    }

    /* ---- component_upsert (POST) ---- */
    case 'component_upsert': {
        $songId = trim((string)($body['songId'] ?? ''));
        $comp   = is_array($body['component'] ?? null) ? $body['component'] : [];
        if ($songId === '' || !$comp) { ed2_respond(['ok' => false, 'error' => 'songId + component are required.'], 400); }
        if (!ed2_songExists($db, $songId)) { ed2_respond(['ok' => false, 'error' => 'Song not found.'], 404); }

        $compId    = isset($comp['id']) ? (int)$comp['id'] : 0;
        $type      = mb_substr(trim((string)($comp['type'] ?? 'verse')), 0, 20) ?: 'verse';
        $number    = max(0, (int)($comp['number'] ?? 0));
        $sortOrder = isset($comp['sortOrder']) ? (int)$comp['sortOrder'] : $number;
        $lines     = is_array($comp['lines'] ?? null) ? array_values(array_map('strval', $comp['lines'])) : [];
        $language  = isset($comp['language']) && trim((string)$comp['language']) !== '' ? trim((string)$comp['language']) : null;

        /* #1860 Phase 5 §3.2 — accept `label` (REQ 3b) + `sourceWorkId` (REQ 2).
           KEY-PRESENT = intent (array_key_exists, not isset): an explicit `label:null`
           clears a set label, while an OMITTED key means "leave it alone" — the
           target-preserve block below reads the stored value back onto $entry for
           exactly that omitted case, so the handler never wipes a label/link the
           caller didn't mention (§3's silent-wipe defence, layer 1 of 3). */
        $hasLabel   = array_key_exists('label', $comp);
        $labelIn    = $hasLabel ? mb_substr(trim((string)($comp['label'] ?? '')), 0, 100) : '';
        /* D1 / rule #27 — server-side hide-when-equal: fold a label equal to the
           derived display name back to NULL so no funnel can store the redundancy.
           Compare against the ALIASED display derivation (refrain renders "Chorus"),
           case-insensitively. */
        $derived = ucfirst($type === 'refrain' ? 'chorus' : $type) . ($number > 0 ? ' ' . $number : '');
        $label   = ($labelIn !== '' && mb_strtolower($labelIn) !== mb_strtolower($derived)) ? $labelIn : null;
        $hasSrcWork    = array_key_exists('sourceWorkId', $comp);
        $srcWorkIn     = $hasSrcWork ? (int)($comp['sourceWorkId'] ?? 0) : 0;
        $sourceWorkId  = $srcWorkIn > 0 ? $srcWorkIn : null;
        $srcWorkIgnored = false;
        /* SD1 — an unresolvable sourceWorkId is COERCED to null, never a 422: a
           work-link problem must not fail the section save. work_admin.php is
           already `require_once`d at module scope (:339) for the other work
           endpoints on this file, so workExists()/workAdminReady() are always
           defined here — the function_exists guard is belt-and-braces only, so
           this accept path never fatals even if that include set ever changes. */
        if ($sourceWorkId !== null && function_exists('workExists') && workAdminReady($db) && !workExists($db, $sourceWorkId)) {
            $sourceWorkId = null; $srcWorkIgnored = true;   /* SD1 — coerce, never fail the save */
        }

        $db->begin_transaction();
        try {
            /* #1235 P4/C5 — read-modify-write the WHOLE component set through the shared
               drop-safe path (lines authoritative + JSON shadow), instead of a single-row
               LinesJson upsert. The new/updated component lands at its sortOrder; PF1/R1 —
               an UPDATE that OMITS `chords`/`languages` preserves the stored arrays. */
            $comps = ed2_currentComponents($db, $songId);
            $entry = [
                'type'      => $type,
                'number'    => $number,
                'sortOrder' => $sortOrder,
                'lines'     => $lines,
                'chords'    => (isset($comp['chords'])    && is_array($comp['chords']))    ? array_values($comp['chords'])    : null,
                'language'  => $language,
                'languages' => (isset($comp['languages']) && is_array($comp['languages'])) ? array_values($comp['languages']) : null,
                '_target'   => true,   // marker to resolve the resulting Id (stripped by the writer)
            ];
            /* #1860 Phase 5 §3.2 — key-present-only: an omitted key leaves $entry
               without it here, so the target-preserve block below (which DOES run
               for every matched existing component) is what actually fills it from
               the stored row; a brand-new component with no explicit label/work
               link simply has neither key, which the writer treats as "not
               provided" -> NULL on INSERT (exactly right for a fresh section). */
            if ($hasLabel)   { $entry['label']        = $label; }
            if ($hasSrcWork) { $entry['sourceWorkId'] = $sourceWorkId; }
            $found = false;
            foreach ($comps as $idx => $c) {
                if ($compId > 0 && (int)($c['id'] ?? 0) === $compId) {
                    if (!isset($comp['chords']))    { $entry['chords']    = $c['chords'] ?? null; }
                    if (!isset($comp['languages'])) { $entry['languages'] = $c['languages'] ?? null; }
                    /* #1860 Phase 5 §3.2 — target-preserve, layer 1 of §3's three-layer
                       silent-wipe defence: an omitted key on an UPDATE reads the CURRENT
                       stored value straight off the pre-write row ($c, from
                       ed2_currentComponents()) rather than letting it default to null. */
                    if (!$hasLabel)   { $entry['label']        = $c['label']        ?? null; }
                    if (!$hasSrcWork) { $entry['sourceWorkId'] = $c['sourceWorkId'] ?? null; }
                    $comps[$idx] = $entry;
                    $found = true;
                    break;
                }
            }
            /* New component → APPEND at the end (never mid-list insert): the shared
               writer upserts existing thin rows BY POSITION, so a mid-list insert would
               shift every downstream component onto the wrong row (churning ComponentId +
               misrouting the returned id — the C5 review finding). Keeping $comps in the
               existing display order (target replaced in place, new appended) keeps the
               position-match Id-stable. A new component lands last; reorder via
               component_reorder. */
            if (!$found) { $comps[] = $entry; }
            ed2_persistComponents($db, $songId, $comps);
            /* Resolve the resulting componentId from the target's position. */
            $targetPos = 0;
            foreach ($comps as $idx => $c) { if (!empty($c['_target'])) { $targetPos = $idx; break; } }
            $after  = ed2_currentComponents($db, $songId);
            $compId = isset($after[$targetPos]['id']) ? (int)$after[$targetPos]['id'] : $compId;

            /* #1860 §3.6b.2 — additive work-grain lockstep (SD2,
               `.claude/medley-component-work-1860-phase5-plan.md` §5.2).
               Setting a section's source work on a song that belongs
               (tblWorkSongs) to other work(s) upserts the matching
               (MedleyWorkId, ComponentWorkId) rows on `tblWorkComponents` —
               "a section sourcing a DIFFERENT work than its song's own
               membership IS the stitching evidence" (design §3.6b). ADDITIVE
               ONLY: never removes on a section link being CLEARED (§3.3.1's
               never-auto-unlink posture, reapplied to this table);
               `workMedleyAttach()` itself never overwrites an existing row's
               SortOrder/Note (SD2 — a curator's own /manage/works ordering
               must never be silently touched by an unrelated section save).
               Runs under component_upsert's OWN file-level editor gate +
               X-Requested-With CSRF gate (rule #29) — deliberately NOT
               clamped to manage_works: this is an ADDITIVE CONSEQUENCE of an
               edit the curator can already make (setting a section's source
               work), not the destructive/orderly medley editing that stays
               behind manage_works on works.php (§6). Flagged in the PR body
               for owner veto per the spec.

               NON-BLOCKING (own try/catch, NOT the outer one): this sits
               INSIDE the handler's existing transaction, so an uncaught
               throw here would propagate to the outer catch and roll back
               the WHOLE section save over a work-grain concern — exactly
               what "the section save must never fail on a work concern"
               forbids. Every ordinary guard failure inside workMedleyAttach()
               (not ready / self-link / missing work / would-cycle) already
               returns false rather than throwing, so this catch is a
               belt-and-braces net for a genuine DB hiccup — mirrors
               `ed2_touchRevision()`'s own catch immediately below and
               `workAutolinkSafe()`'s $ownTransaction=false mode
               (work_admin.php `:1054-1063`): re-throw ONLY when
               songRelocateIsTransactionFatal() says the caller's transaction
               is already dead (a deadlock/lock-wait-timeout victim — #1688
               A1), because swallowing THAT would let $db->commit() below
               succeed trivially over a rolled-back transaction (a false
               "ok:true"). Every other throwable is logged and swallowed. */
            if ($sourceWorkId !== null && workAdminReady($db) && workMedleyReady($db)) {
                try {
                    $memberStmt = $db->prepare('SELECT WorkId FROM tblWorkSongs WHERE SongId = ?');
                    $memberStmt->bind_param('s', $songId);
                    $memberStmt->execute();
                    $memberRes = $memberStmt->get_result();
                    $memberWorkIds = [];
                    while ($memberRow = $memberRes->fetch_assoc()) {
                        $memberWorkIds[] = (int)$memberRow['WorkId'];
                    }
                    $memberStmt->close();
                    foreach ($memberWorkIds as $mw) {
                        if ($mw !== $sourceWorkId) {
                            workMedleyAttach($db, $mw, $sourceWorkId, $sortOrder /* the section's */, null);
                        }
                    }
                } catch (\Throwable $e) {
                    if (songRelocateIsTransactionFatal($e)) { throw $e; }
                    error_log('[work medley lockstep] ' . $songId . ': ' . $e->getMessage());
                    /* swallow — a work-grain hiccup must never fail the section save */
                }
            }

            ed2_touchRevision($db, $songId, $ed2UserId, 'component');
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
        logActivity('song.component', 'song', $songId, ['componentId' => $compId, 'type' => $type]);
        /* #1860 Phase 5 §3.2 — rule #35 read-back: the response reflects what
           $after (the freshly re-read stored row) actually holds, never the raw
           request — so the client's own D1 fold + SD1 coercion always agree with
           the server, and a stale/omitted client value is corrected visibly. */
        ed2_respond([
            'ok'                  => true,
            'componentId'         => $compId,
            'label'               => $after[$targetPos]['label']        ?? null,
            'sourceWorkId'        => $after[$targetPos]['sourceWorkId'] ?? null,
            'sourceWorkIdIgnored' => $srcWorkIgnored,
        ]);
        break;
    }

    /* ---- component_delete (POST) ---- */
    case 'component_delete': {
        $songId = trim((string)($body['songId'] ?? ''));
        $compId = (int)($body['componentId'] ?? 0);
        if ($songId === '' || $compId <= 0) { ed2_respond(['ok' => false, 'error' => 'songId + componentId are required.'], 400); }
        $db->begin_transaction();
        try {
            /* #1235 P4/C5 — read-modify-write the whole set (drop-safe): drop the target
               component, persist the remainder through the shared write path (which also
               cascade-removes its lines from tblLyricLines + the JSON shadow). */
            $comps   = ed2_currentComponents($db, $songId);
            $before  = count($comps);
            $comps   = array_values(array_filter($comps, static fn($c) => (int)($c['id'] ?? 0) !== $compId));
            $deleted = $before - count($comps);
            ed2_persistComponents($db, $songId, $comps);
            ed2_touchRevision($db, $songId, $ed2UserId, 'component');
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
        logActivity('song.component.delete', 'song', $songId, ['componentId' => $compId]);
        ed2_respond(['ok' => true, 'deleted' => (int)$deleted]);
        break;
    }

    /* ---- component_reorder (POST) — { order: [componentId, ...] } ---- */
    case 'component_reorder': {
        $songId = trim((string)($body['songId'] ?? ''));
        $order  = is_array($body['order'] ?? null) ? array_values(array_map('intval', $body['order'])) : [];
        if ($songId === '' || !$order) { ed2_respond(['ok' => false, 'error' => 'songId + order[] are required.'], 400); }
        $db->begin_transaction();
        try {
            /* #1235 P4/C5 — read-modify-write the whole set in the requested order
               (drop-safe). Components named in order[] come first (in that order); any
               not named are appended in their current order. The shared write path then
               reassigns contiguous SortOrder + re-stamps each line's component. */
            $comps = ed2_currentComponents($db, $songId);
            $byId  = [];
            foreach ($comps as $c) { $byId[(int)($c['id'] ?? 0)] = $c; }
            $reordered = [];
            foreach ($order as $cid) {
                $cid = (int)$cid;
                if ($cid > 0 && isset($byId[$cid])) { $reordered[] = $byId[$cid]; unset($byId[$cid]); }
            }
            foreach ($byId as $c) { $reordered[] = $c; }   // any not listed → appended (stable)
            ed2_persistComponents($db, $songId, $reordered);
            ed2_touchRevision($db, $songId, $ed2UserId, 'reorder');
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
        logActivity('song.component.reorder', 'song', $songId, ['count' => count($order)]);
        ed2_respond(['ok' => true, 'count' => count($order)]);
        break;
    }

    /* ---- arrangement_update (POST) — the song's running order (#161 / #1627).
     *
     * ELI5: saves "play section 0, then 1, then 1 again, then 2".
     *
     * Body: { songId, arrangement: [0,1,1,2] }   — null or [] CLEARS it.
     * ->    { ok, songId, arrangement }          — the stored value, echoed back.
     *
     * WHY THIS EXISTS
     * v1 persisted ArrangementJson through its whole-song save (save_song_core.php:303).
     * v2 has no such save — every edit is granular — and ED2_META_FIELDS cannot
     * write this column, so no v2 endpoint touched it at all. #1601 would have
     * made existing arrangements READ-ONLY FOSSIL DATA: the public render and
     * every export format keep consuming them, but nobody could ever create or
     * change one again. The admin dashboard advertises "arrangements" throughout.
     *
     * VALIDATION IS THE SHARED ONE, deliberately. #1618's gate G4 audits stored
     * ordinals; this endpoint uses the SAME includes/arrangement.php rule, so the
     * editor cannot manufacture data that later fails the cutover verification
     * and blocks the C6 drop.
     *
     * THE 422 IS THE SUBTLE PART. G4 — and the public render — index the
     * ASSEMBLED component list, while the editor indexes the EDITABLE one. Those
     * are equal except when a zero-line component exists (reachable via
     * components_replace with lines: []), where the editable list is longer. So
     * ordinals are validated against the ASSEMBLED count, and while the two
     * counts diverge we refuse to store a non-null arrangement at all rather
     * than write ordinals that mean one thing to the editor and another to the
     * renderer. Clearing is always allowed — you must be able to get out of that
     * state.
     *
     * CSRF: covered by the file-level same-origin POST gate above (#1307). Auth:
     * the file-level isAuthenticated() + editor role, exactly what protected
     * v1's save_song write of this same column — no new entitlement invented. ---- */
    case 'arrangement_update': {
        $songId = trim((string)($body['songId'] ?? ''));
        if ($songId === '') { ed2_respond(['ok' => false, 'error' => 'songId is required.'], 400); }
        if (!ed2_songExists($db, $songId)) { ed2_respond(['ok' => false, 'error' => 'Song not found.'], 404); }

        /* Migrations are web-run, not applied on deploy, and mysqli is STRICT —
           touching a missing column throws. Probe, don't assume (rule #19). */
        if (!arrangementColumnExists($db)) {
            ed2_respond(['ok' => false, 'error' => 'The ArrangementJson migration has not been run on this install.'], 409);
        }

        $raw = $body['arrangement'] ?? null;
        if ($raw !== null && !is_array($raw)) {
            ed2_respond(['ok' => false, 'error' => 'arrangement must be an array of section indexes, or null to clear.'], 400);
        }

        $json = null;
        if ($raw !== null && $raw !== []) {
            /* lyric_lines_read.php is required LAZILY inside ed2_currentComponents()
               and its sibling, not at the top of this file. Calling
               ed2_currentComponents() first happens to load it before
               lyricLinesMirrorPresent() is reached below — but depending on that
               ordering is the kind of thing a later refactor breaks silently, with
               a fatal "undefined function" only on the branch that reorders. State
               the dependency; require_once is idempotent and cheap. */
            require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'lyric_lines_read.php';

            $nEditable  = count(ed2_currentComponents($db, $songId));
            $nAssembled = lyricLinesMirrorPresent($db)
                ? count(lyricLinesAssembleComponents($db, $songId))
                : $nEditable;

            if ($nAssembled !== $nEditable) {
                ed2_respond([
                    'ok'    => false,
                    'error' => 'This song has empty sections, so section numbering differs between the '
                             . 'editor and the published song. Remove them before setting an arrangement.',
                ], 422);
            }

            $viol = arrangementViolations(array_values($raw), $nAssembled);
            if ($viol !== []) {
                $first = $viol[0];
                $msg = $first['reason'] === 'malformed'
                    ? 'arrangement must be an array of section indexes.'
                    : 'arrangement[' . ($first['position'] ?? 0) . '] = '
                      . json_encode($first['value'] ?? null)
                      . ' is not a valid section index (this song has ' . $nAssembled . ' sections).';
                ed2_respond(['ok' => false, 'error' => $msg], 400);
            }

            $json = arrangementSanitise(array_values($raw), $nAssembled);
        }

        $db->begin_transaction();
        try {
            $up = $db->prepare('UPDATE tblSongs SET ArrangementJson = ? WHERE SongId = ?');
            $up->bind_param('ss', $json, $songId);
            $up->execute();
            $up->close();
            ed2_touchRevision($db, $songId, $ed2UserId, 'arrangement');
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }

        logActivity('song.arrangement', 'song', $songId, ['length' => $json === null ? 0 : count(json_decode($json, true) ?: [])]);
        /* Echo the STORED value, not the request, so the client's local copy can
           only ever hold what the server actually kept. */
        ed2_respond(['ok' => true, 'songId' => $songId, 'arrangement' => $json === null ? null : json_decode($json, true)]);
        break;
    }

    /* ---- line_translation_upsert (POST) — per-line translation / transliteration
           (#1235 P3 / #1088). Body: { songId, translation:{ id?, lineId, kind,
           targetLanguage, text, translationType?, isPrimary?, sortOrder?, status? } }.
           The shared layer derives LyricsId from the line + enforces the line
           belongs to this song; enrichment survives later edits via the Id-
           preserving diff. NOT a component-content change → no revision touch. ---- */
    case 'line_translation_upsert': {
        $songId = trim((string)($body['songId'] ?? ''));
        if ($songId === '') { ed2_respond(['ok' => false, 'error' => 'songId is required.'], 400); }
        if (!ed2_songExists($db, $songId)) { ed2_respond(['ok' => false, 'error' => 'Song not found.'], 404); }
        if (!lineEnrichmentTablesReady($db)) { ed2_respond(['ok' => false, 'error' => 'Line-enrichment tables are not migrated.'], 409); }
        $input = is_array($body['translation'] ?? null) ? $body['translation'] : [];
        $db->begin_transaction();
        try {
            $row = lineEnrichmentUpsertTranslation($db, $songId, $input, $ed2UserId);
            $db->commit();
        } catch (\InvalidArgumentException $e) {
            $db->rollback();
            ed2_respond(['ok' => false, 'error' => $e->getMessage()], 400);
        } catch (\RuntimeException $e) {
            $db->rollback();
            ed2_respond(['ok' => false, 'error' => $e->getMessage()], 404);
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
        logActivity('song.lineTranslation', 'song', $songId, ['id' => (int)($row['id'] ?? 0), 'lineId' => (int)($row['lineId'] ?? 0)]);
        ed2_respond(['ok' => true, 'translation' => $row]);
        break;
    }

    /* ---- line_translation_delete (POST) — { songId, id } ---- */
    case 'line_translation_delete': {
        $songId = trim((string)($body['songId'] ?? ''));
        $id     = (int)($body['id'] ?? 0);
        if ($songId === '' || $id <= 0) { ed2_respond(['ok' => false, 'error' => 'songId + id are required.'], 400); }
        if (!lineEnrichmentTablesReady($db)) { ed2_respond(['ok' => false, 'error' => 'Line-enrichment tables are not migrated.'], 409); }
        $db->begin_transaction();
        try {
            $removed = lineEnrichmentDeleteTranslation($db, $songId, $id);
            $db->commit();
        } catch (\RuntimeException $e) {
            $db->rollback();
            ed2_respond(['ok' => false, 'error' => $e->getMessage()], 404);
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
        logActivity('song.lineTranslation.delete', 'song', $songId, ['id' => $id]);
        ed2_respond(['ok' => true, 'deleted' => $removed ? 1 : 0]);
        break;
    }

    /* ---- line_annotation_upsert (POST) — per-line / per-span annotation
           (#1235 P3 / #1088). Body: { songId, annotation:{ id?, startLineId,
           endLineId?, startOffset?, endOffset?, annotationType, body, bodyFormat?,
           languageCode?, sortOrder?, status? } }. Offsets are code-point indices. ---- */
    case 'line_annotation_upsert': {
        $songId = trim((string)($body['songId'] ?? ''));
        if ($songId === '') { ed2_respond(['ok' => false, 'error' => 'songId is required.'], 400); }
        if (!ed2_songExists($db, $songId)) { ed2_respond(['ok' => false, 'error' => 'Song not found.'], 404); }
        if (!lineEnrichmentTablesReady($db)) { ed2_respond(['ok' => false, 'error' => 'Line-enrichment tables are not migrated.'], 409); }
        $input = is_array($body['annotation'] ?? null) ? $body['annotation'] : [];
        $db->begin_transaction();
        try {
            $row = lineEnrichmentUpsertAnnotation($db, $songId, $input, $ed2UserId);
            $db->commit();
        } catch (\InvalidArgumentException $e) {
            $db->rollback();
            ed2_respond(['ok' => false, 'error' => $e->getMessage()], 400);
        } catch (\RuntimeException $e) {
            $db->rollback();
            ed2_respond(['ok' => false, 'error' => $e->getMessage()], 404);
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
        logActivity('song.lineAnnotation', 'song', $songId, ['id' => (int)($row['id'] ?? 0), 'startLineId' => (int)($row['startLineId'] ?? 0)]);
        ed2_respond(['ok' => true, 'annotation' => $row]);
        break;
    }

    /* ---- line_annotation_delete (POST) — { songId, id } ---- */
    case 'line_annotation_delete': {
        $songId = trim((string)($body['songId'] ?? ''));
        $id     = (int)($body['id'] ?? 0);
        if ($songId === '' || $id <= 0) { ed2_respond(['ok' => false, 'error' => 'songId + id are required.'], 400); }
        if (!lineEnrichmentTablesReady($db)) { ed2_respond(['ok' => false, 'error' => 'Line-enrichment tables are not migrated.'], 409); }
        $db->begin_transaction();
        try {
            $removed = lineEnrichmentDeleteAnnotation($db, $songId, $id);
            $db->commit();
        } catch (\RuntimeException $e) {
            $db->rollback();
            ed2_respond(['ok' => false, 'error' => $e->getMessage()], 404);
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
        logActivity('song.lineAnnotation.delete', 'song', $songId, ['id' => $id]);
        ed2_respond(['ok' => true, 'deleted' => $removed ? 1 : 0]);
        break;
    }

    /* ---- credit_upsert (POST) — { role, credit:{id?, name?, first?, surname?, suffix?} } ---- */
    case 'credit_upsert': {
        $songId = trim((string)($body['songId'] ?? ''));
        $role   = (string)($body['role'] ?? '');
        $credit = is_array($body['credit'] ?? null) ? $body['credit'] : [];
        if ($songId === '' || !isset(ED2_CREDIT_TABLES[$role]) || !$credit) {
            ed2_respond(['ok' => false, 'error' => 'songId + a known role + credit are required.'], 400);
        }
        if (!ed2_songExists($db, $songId)) { ed2_respond(['ok' => false, 'error' => 'Song not found.'], 404); }
        $table    = ED2_CREDIT_TABLES[$role];     // from the allow-list constant only
        $creditId = isset($credit['id']) ? (int)$credit['id'] : 0;

        /* #960 — accept EITHER shape: the legacy/flat {name} the current UI
           still sends, or the structured {name?,first?,surname?,suffix?}
           shape a future 3-field UI sends. creditEntryNormalise() is the
           SAME function the whole-song save uses (includes/musicians_
           helpers.php) — this endpoint never re-forks the decompose/compose
           logic. */
        $entry = creditEntryNormalise($credit);
        if ($entry === null) { ed2_respond(['ok' => false, 'error' => 'credit name is required.'], 400); }
        $name = mb_substr($entry['name'], 0, 255);

        $db->begin_transaction();
        try {
            $oldName = '';
            if ($creditId > 0) {
                /* #1843 — capture the spelling this credit row held BEFORE the
                   edit, FOR UPDATE (serialises overlapping debounced upserts on
                   the same row), so the post-commit reap can clean up the junk
                   registry row an earlier partial keystroke ("Joh" → "John")
                   minted. */
                $old = $db->prepare("SELECT Name FROM `{$table}` WHERE Id = ? AND SongId = ? FOR UPDATE");
                $old->bind_param('is', $creditId, $songId);
                $old->execute();
                $oldRow = $old->get_result()->fetch_row();
                $old->close();
                $oldName = $oldRow ? (string)$oldRow[0] : '';

                $u = $db->prepare("UPDATE `{$table}` SET Name = ? WHERE Id = ? AND SongId = ?");
                $u->bind_param('sis', $name, $creditId, $songId);
                $u->execute();
                $u->close();
            } else {
                /* #1744-A5 — same-name dedup, mirroring the v1 whole-song
                   save's $seenCredit set (manage/editor/save_song_core.php,
                   #1178): a "new credit" call for a name that ALREADY sits
                   in this role's table for this song must update the
                   existing row's spelling rather than insert a duplicate
                   link — a client accumulation bug (or a curator re-adding
                   a name that autocomplete already offered) would otherwise
                   list the same person twice in the same role. Scoped to
                   (songId, table) exactly like v1's per-role-key
                   $seenCredit reset.
                   ELI5: before adding "John Newton" as a Writer, check
                   whether this song already HAS a Writer named that —
                   if so, just touch up the existing row instead of adding
                   a second one.
                   Case-insensitive comparison comes for free from the
                   table's utf8mb4_unicode_ci collation (schema.sql) — the
                   SAME bare `WHERE Name = ?` idiom
                   registerMusicianByName() already uses against
                   tblMusicians (includes/musician_helpers.php) — never a
                   second, re-forked name-matching rule (rule #22).
                   `FOR UPDATE` serialises this against a concurrent
                   debounced add for the same name, the same reason the
                   $creditId > 0 branch above locks its row. */
                $dupe = $db->prepare("SELECT Id, Name FROM `{$table}` WHERE SongId = ? AND Name = ? FOR UPDATE");
                $dupe->bind_param('ss', $songId, $name);
                $dupe->execute();
                $dupeRow = $dupe->get_result()->fetch_assoc();
                $dupe->close();

                if ($dupeRow) {
                    $creditId = (int)$dupeRow['Id'];
                    $oldName  = (string)$dupeRow['Name'];

                    $u = $db->prepare("UPDATE `{$table}` SET Name = ? WHERE Id = ? AND SongId = ?");
                    $u->bind_param('sis', $name, $creditId, $songId);
                    $u->execute();
                    $u->close();
                } else {
                    $i = $db->prepare("INSERT INTO `{$table}` (SongId, Name) VALUES (?, ?)");
                    $i->bind_param('ss', $songId, $name);
                    $i->execute();
                    $creditId = (int)$db->insert_id;
                    $i->close();
                }
            }
            /* #960 — the ACTUAL regression fix: promote the name into the
               tblMusicians registry in the SAME transaction as the
               role-table write, so a v2 credit save can never leave the two
               out of sync (the pre-#960 bug: this endpoint wrote Name-only
               to the role table and never touched the registry at all). */
            $registryPersonId = musicianPromote($db, $name, [
                'first'   => $entry['first'],
                'surname' => $entry['surname'],
                'suffix'  => $entry['suffix'],
            ]);
            ed2_touchRevision($db, $songId, $ed2UserId, 'credit');
            $db->commit();

            /* #1862 — a credit add/edit can change the PD-suggestion contributor
               set (or a death date via the registry read below); recompute the
               denorm post-commit, own failure boundary (pdRecomputeForSong()
               never throws — see pd_suggest.php's header). Tree-derived wiring
               guard: tests/php/test-editor2-metadata-1862.php scans every
               `INSERT INTO tblSongWriters` (etc.) and asserts this reference. */
            pdRecomputeForSong($db, $songId);

            /* #1843 — post-commit best-effort janitor. If this save RENAMED the
               credit (the old spelling differs from the new one), the old
               partial name may have auto-minted a junk uncurated registry row
               during earlier debounced keystrokes. Reap it — only if it is
               orphaned + uncurated + not $registryPersonId's own row (the
               step-3 same-row-by-Id guard), so the D2 read-back below can never
               lose the row it echoes. Runs in its OWN transaction and never
               throws, so a janitor hiccup can't roll back the committed save. */
            if ($oldName !== '' && $oldName !== $name) {
                musicianReapOrphanedAutoRow($db, $oldName, $registryPersonId);
            }
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }

        /* D2 — echo the REGISTRY's parts, never the caller's normalised
           input. musicianPromote()'s backfill is
           COALESCE(NULLIF(col,''),?) — it NEVER overwrites an existing
           curated value, so when the registry already held different parts
           than this request sent, the write above was a silent no-op for
           those columns. Echoing the input back would show the curator
           their own text while the DB kept the old value, and the very next
           load would silently revert it — a rule #30 "looks alive, isn't"
           failure. Gated on musicianNamePartsColumnsExist(): on an
           un-migrated install there are no registry parts to read back, so
           fall back to the entry's own (decompose-derived) parts — there is
           no curated value that fallback could ever shadow. */
        if (musicianNamePartsColumnsExist($db)) {
            $reg = $db->prepare('SELECT FirstNames, Surname, Suffix FROM tblMusicians WHERE Id = ?');
            $reg->bind_param('i', $registryPersonId);
            $reg->execute();
            $regRow = $reg->get_result()->fetch_assoc() ?: [];
            $reg->close();
            $first   = (string)($regRow['FirstNames'] ?? '');
            $surname = (string)($regRow['Surname']    ?? '');
            $suffix  = (string)($regRow['Suffix']     ?? '');
        } else {
            $first   = $entry['first'];
            $surname = $entry['surname'];
            $suffix  = $entry['suffix'];
        }

        logActivity('song.credit', 'song', $songId, ['role' => $role, 'creditId' => $creditId]);
        ed2_respond([
            'ok'               => true,
            'creditId'         => $creditId,
            'name'             => $name,
            'first'            => $first,
            'surname'          => $surname,
            'suffix'           => $suffix,
            'registryPersonId' => $registryPersonId,
        ]);
        break;
    }

    /* ---- credit_delete (POST) — { role, creditId } ---- */
    case 'credit_delete': {
        $songId  = trim((string)($body['songId'] ?? ''));
        $role    = (string)($body['role'] ?? '');
        $creditId = (int)($body['creditId'] ?? 0);
        if ($songId === '' || !isset(ED2_CREDIT_TABLES[$role]) || $creditId <= 0) {
            ed2_respond(['ok' => false, 'error' => 'songId + a known role + creditId are required.'], 400);
        }
        $table = ED2_CREDIT_TABLES[$role];
        $db->begin_transaction();
        try {
            /* #1843 — capture the credited name before deleting the row so the
               post-commit reap can clean up the junk registry row it minted. */
            $nameSel = $db->prepare("SELECT Name FROM `{$table}` WHERE Id = ? AND SongId = ?");
            $nameSel->bind_param('is', $creditId, $songId);
            $nameSel->execute();
            $nameRow = $nameSel->get_result()->fetch_row();
            $nameSel->close();
            $oldName = $nameRow ? (string)$nameRow[0] : '';

            $d = $db->prepare("DELETE FROM `{$table}` WHERE Id = ? AND SongId = ?");
            $d->bind_param('is', $creditId, $songId);
            $d->execute();
            $deleted = $d->affected_rows;
            $d->close();
            ed2_touchRevision($db, $songId, $ed2UserId, 'credit');
            $db->commit();

            /* #1862 — same post-commit PD recompute as credit_upsert above —
               removing a credit can change the contributor set too. */
            pdRecomputeForSong($db, $songId);

            /* #1843 — post-commit best-effort janitor. A delete has no
               successor name, so keepRegistryId = 0: reap the removed credit's
               registry row iff it is now orphaned + uncurated. Own transaction,
               never throws. */
            if ($oldName !== '') {
                musicianReapOrphanedAutoRow($db, $oldName, 0);
            }
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
        logActivity('song.credit.delete', 'song', $songId, ['role' => $role, 'creditId' => $creditId]);
        ed2_respond(['ok' => true, 'deleted' => (int)$deleted]);
        break;
    }

    /* ---- tag_list (GET) — tags currently attached to one song ---- */
    case 'tag_list': {
        $songId = trim((string)($_GET['id'] ?? ''));
        if ($songId === '') { ed2_respond(['ok' => false, 'error' => 'id is required.'], 400); }
        $tags = [];
        $q = $db->prepare(
            'SELECT t.Id, t.Name, t.Slug, t.Description
               FROM tblSongTagMap m JOIN tblSongTags t ON t.Id = m.TagId
              WHERE m.SongId = ? ORDER BY t.Name ASC'
        );
        $q->bind_param('s', $songId);
        $q->execute();
        $r = $q->get_result();
        while ($row = $r->fetch_assoc()) {
            $tags[] = ['id' => (int)$row['Id'], 'name' => (string)$row['Name'], 'slug' => (string)$row['Slug'], 'description' => (string)($row['Description'] ?? '')];
        }
        $q->close();
        ed2_respond(['ok' => true, 'tags' => $tags]);
        break;
    }

    /* ---- tag_search (GET) — typeahead over the registry, with usage counts.
           Empty q => top-N by usage; otherwise substring match (case-insensitive
           via the column collation). ---- */
    case 'tag_search': {
        $term  = trim((string)($_GET['q'] ?? ''));
        $limit = (int)($_GET['limit'] ?? 10);
        if ($limit < 1)  { $limit = 1; }
        if ($limit > 20) { $limit = 20; }
        $suggestions = [];
        if ($term === '') {
            $q = $db->prepare(
                'SELECT t.Id, t.Name, t.Slug, COUNT(m.TagId) AS UsageCount
                   FROM tblSongTags t LEFT JOIN tblSongTagMap m ON m.TagId = t.Id
                  GROUP BY t.Id, t.Name, t.Slug
                  ORDER BY UsageCount DESC, t.Name ASC LIMIT ?'
            );
            $q->bind_param('i', $limit);
        } else {
            $like = '%' . $term . '%';
            $q = $db->prepare(
                'SELECT t.Id, t.Name, t.Slug, COUNT(m.TagId) AS UsageCount
                   FROM tblSongTags t LEFT JOIN tblSongTagMap m ON m.TagId = t.Id
                  WHERE t.Name LIKE ?
                  GROUP BY t.Id, t.Name, t.Slug
                  ORDER BY UsageCount DESC, t.Name ASC LIMIT ?'
            );
            $q->bind_param('si', $like, $limit);
        }
        $q->execute();
        $r = $q->get_result();
        while ($row = $r->fetch_assoc()) {
            $suggestions[] = ['id' => (int)$row['Id'], 'name' => (string)$row['Name'], 'slug' => (string)$row['Slug'], 'usage' => (int)$row['UsageCount']];
        }
        $q->close();
        ed2_respond(['ok' => true, 'suggestions' => $suggestions]);
        break;
    }

    /* ---- tune_search (GET) — typeahead over the tblTunes registry (#1741
           P5c), a mirror of tag_search immediately above. Alias-JOINed
           (tblTuneAliases, when present) so a spelling variant surfaces its
           CANONICAL tune — the actual de-dup mechanism, parent plan §3B —
           `matchedAlias` is non-null only when the ALIAS (not the tune's own
           Name) matched, so the client can render "also known as …". Empty
           `q` => browse-mode top-N by usage, same convention as tag_search.
           `meter`, when given, folds both sides through
           ihymns_meter_normalize() (tune_helpers.php) and filters PHP-side —
           MeterCode is a free-text display column, so the fold cannot run in
           SQL (§3.5). Every optional table/column is existence-gated: an
           absent tblTunes degrades to tableMissing:true (empty list, not a
           500); an absent tblTuneAliases just drops the alias JOIN/leg; an
           absent tblSongs.TuneId (pre-#1090) drops the UsageCount JOIN
           (usage reads as 0 rather than throwing under STRICT). ---- */
    case 'tune_search': {
        $term  = trim((string)($_GET['q'] ?? ''));
        $limit = (int)($_GET['limit'] ?? 10);
        if ($limit < 1)  { $limit = 1; }
        if ($limit > 20) { $limit = 20; }
        $meterFilter = trim((string)($_GET['meter'] ?? ''));

        if (!tuneTunesTableExists($db)) {
            ed2_respond(['ok' => true, 'suggestions' => [], 'tableMissing' => true]);
        }

        $hasAliases       = ed2_tuneAliasesTableExists($db);
        $hasTuneIdOnSongs = ed2_tuneIdColumnExists($db);

        /* Meter-filtering needs a wider slice to PHP-filter against (the
           fold can't run in SQL) — capped so a pathological limit can't
           become an unbounded scan; the meter-carrying subset of tblTunes
           is small today (P4c §3.6 dormancy note). */
        $fetchLimit = $meterFilter !== '' ? min(100, $limit * 5) : $limit;

        $usageSelectSql = $hasTuneIdOnSongs ? 'COUNT(DISTINCT s.Id)' : '0';
        $usageJoinSql   = $hasTuneIdOnSongs ? 'LEFT JOIN tblSongs s ON s.TuneId = t.Id' : '';
        $aliasJoinSql   = $hasAliases ? 'LEFT JOIN tblTuneAliases a ON a.TuneId = t.Id' : '';
        /* MatchedAlias is only bound/computed when there IS a search term —
           an empty `q` would otherwise LIKE-match every alias row and
           MIN() would surface an arbitrary "aka …" on every browse-mode
           suggestion, which is not what "also known as" is meant to mean. */
        $wantAliasSelect = $hasAliases && $term !== '';
        $aliasSelectSql  = $wantAliasSelect ? 'MIN(CASE WHEN a.Name LIKE ? THEN a.Name END)' : 'NULL';

        /* Every interpolated {...} fragment above is a hardcoded literal
           built from a boolean existence gate — never request input (rule
           #5's carve-out); every VALUE below is bound. */
        $sql = "SELECT t.Id, t.Name, t.Slug, t.MeterCode,
                       {$usageSelectSql} AS UsageCount, {$aliasSelectSql} AS MatchedAlias
                  FROM tblTunes t
                  {$aliasJoinSql}
                  {$usageJoinSql}";

        $types  = '';
        $params = [];
        if ($wantAliasSelect) { $types .= 's'; $params[] = '%' . $term . '%'; }

        if ($term !== '') {
            $whereParts = ['t.Name LIKE ?'];
            $types .= 's'; $params[] = '%' . $term . '%';
            if ($hasAliases) {
                $whereParts[] = 'a.Name LIKE ?';
                $types .= 's'; $params[] = '%' . $term . '%';
            }
            $sql .= ' WHERE (' . implode(' OR ', $whereParts) . ')';
        }
        $sql   .= ' GROUP BY t.Id, t.Name, t.Slug, t.MeterCode ORDER BY UsageCount DESC, t.Name ASC LIMIT ?';
        $types .= 'i';
        $params[] = $fetchLimit;

        $q = $db->prepare($sql);
        $q->bind_param($types, ...$params);
        $q->execute();
        $r = $q->get_result();
        $rows = [];
        while ($row = $r->fetch_assoc()) {
            $rows[] = [
                'id'           => (int)$row['Id'],
                'name'         => (string)$row['Name'],
                'slug'         => (string)$row['Slug'],
                'meterCode'    => $row['MeterCode'] !== null ? (string)$row['MeterCode'] : null,
                'usage'        => (int)$row['UsageCount'],
                'matchedAlias' => $row['MatchedAlias'] !== null ? (string)$row['MatchedAlias'] : null,
            ];
        }
        $q->close();

        if ($meterFilter !== '') {
            $needle = ihymns_meter_normalize($meterFilter);
            if ($needle !== '') {
                $rows = array_values(array_filter($rows, static function (array $row) use ($needle): bool {
                    return $row['meterCode'] !== null && ihymns_meter_normalize($row['meterCode']) === $needle;
                }));
            }
            $rows = array_slice($rows, 0, $limit);
        }

        ed2_respond(['ok' => true, 'suggestions' => $rows]);
        break;
    }

    /* ---- publisher_search (GET) — typeahead for the Copyright Holder picker
           (#1862, rule #22). Mirrors tune_search above in posture, but does
           NO local SQL of its own — delegates entirely to the ONE shared
           publisher typeahead core (publisherSearchRows(), #1864), the same
           core /manage/publishers, /manage/songbooks and /manage/works
           already share. `[]` pre-migration — the helper itself degrades
           gracefully when tblPublishers is absent. ---- */
    case 'publisher_search': {
        $term  = trim((string)($_GET['q'] ?? ''));
        $limit = (int)($_GET['limit'] ?? 10);
        if ($limit < 1)  { $limit = 1; }
        if ($limit > 50) { $limit = 50; }

        $rows = publisherSearchRows($db, $term, $limit);
        ed2_respond([
            'ok'          => true,
            'suggestions' => array_map(
                static fn(array $p): array => ['id' => $p['id'], 'name' => $p['name'], 'slug' => $p['slug'], 'kind' => $p['kind']],
                $rows
            ),
        ]);
        break;
    }

    /* ---- work_search (GET) — typeahead over the tblWorks registry (#1860
           Phase 3), mirrors tune_search above in posture. ---- */
    case 'work_search': {
        $term  = trim((string)($_GET['q'] ?? ''));
        $limit = (int)($_GET['limit'] ?? 10);
        if ($limit < 1)  { $limit = 1; }
        if ($limit > 20) { $limit = 20; }

        if (!ed2_worksTableExists($db)) {
            ed2_respond(['ok' => true, 'suggestions' => [], 'tableMissing' => true]);
        }

        /* Ccli is a rule-#5 boolean-gated SQL fragment — a hardcoded literal
           chosen by a schema probe, never request input. Iswc is always
           selected: it predates the works-identity migration entirely. */
        $hasCcli       = ed2_worksCcliColumnExists($db);
        $ccliSelectSql = $hasCcli ? 'w.Ccli' : 'NULL';
        $ccliGroupSql  = $hasCcli ? ', w.Ccli' : '';

        $sql = "SELECT w.Id, w.Title, w.Slug, w.Disambiguation, w.ParentWorkId, w.Iswc,
                       {$ccliSelectSql} AS Ccli, COUNT(DISTINCT ws.SongId) AS UsageCount
                  FROM tblWorks w
                  LEFT JOIN tblWorkSongs ws ON ws.WorkId = w.Id";
        $types  = '';
        $params = [];
        if ($term !== '') {
            $sql .= ' WHERE w.Title LIKE ?';
            $types .= 's';
            $params[] = '%' . $term . '%';
        }
        $sql .= " GROUP BY w.Id, w.Title, w.Slug, w.Disambiguation, w.ParentWorkId, w.Iswc{$ccliGroupSql}"
              . ' ORDER BY UsageCount DESC, w.Title ASC LIMIT ?';
        $types .= 'i';
        $params[] = $limit;

        $q = $db->prepare($sql);
        $q->bind_param($types, ...$params);
        $q->execute();
        $r = $q->get_result();
        $rows = [];
        while ($row = $r->fetch_assoc()) {
            $rows[] = [
                'id'             => (int)$row['Id'],
                'title'          => (string)$row['Title'],
                'slug'           => (string)$row['Slug'],
                'disambiguation' => (string)$row['Disambiguation'],
                'iswc'           => $row['Iswc'] !== null ? (string)$row['Iswc'] : null,
                'ccli'           => $row['Ccli'] !== null ? (string)$row['Ccli'] : null,
                'parentWorkId'   => $row['ParentWorkId'] !== null ? (int)$row['ParentWorkId'] : null,
                'usage'          => (int)$row['UsageCount'],
            ];
        }
        $q->close();

        ed2_respond(['ok' => true, 'suggestions' => $rows]);
        break;
    }

    /* ---- tag_attach (POST) — attach a tag to a song, auto-creating the registry
           row if needed. Returns the CANONICAL {id,name,slug} so the client adopts
           the server's stored form. attached=false means it was already on the
           song (the (SongId,TagId) PK no-op'd). ---- */
    case 'tag_attach': {
        $songId = trim((string)($body['songId'] ?? ''));
        $name   = ed2_normalizeTag((string)($body['name'] ?? ''));
        if ($songId === '' || $name === '') { ed2_respond(['ok' => false, 'error' => 'songId + a tag name are required.'], 400); }
        if (!ed2_songExists($db, $songId)) { ed2_respond(['ok' => false, 'error' => 'Song not found.'], 404); }
        $slug = ed2_tagSlug($name);
        if ($slug === '') { ed2_respond(['ok' => false, 'error' => 'Tag name has no usable characters.'], 400); }

        $db->begin_transaction();
        try {
            /* ON DUPLICATE KEY pulls the existing row's Name up to the new
               Title-Cased form (re-canonicalises legacy lower-case rows) while
               LAST_INSERT_ID(Id) makes insert_id the existing Id on a dupe. */
            /* Name = ? (bound twice) instead of the deprecated VALUES(Name)
               (removed in MySQL 8.0.20+) — pulls an existing row's Name up to
               the new Title-Cased form (re-canonicalises legacy lower-case rows)
               while LAST_INSERT_ID(Id) makes insert_id the existing Id on a dupe. */
            $ins = $db->prepare(
                'INSERT INTO tblSongTags (Name, Slug) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE Id = LAST_INSERT_ID(Id), Name = ?'
            );
            $ins->bind_param('sss', $name, $slug, $name);
            $ins->execute();
            $tagId = (int)$db->insert_id;
            $ins->close();

            /* TaggedBy bound as nullable INT (not 0) so a session with no resolved
               user id doesn't trip fk_TagMap_User. INSERT IGNORE = PK dedupe. */
            $map = $db->prepare('INSERT IGNORE INTO tblSongTagMap (SongId, TagId, TaggedBy) VALUES (?, ?, ?)');
            $map->bind_param('sii', $songId, $tagId, $ed2UserId);
            $map->execute();
            $attached = $map->affected_rows > 0;
            $map->close();

            ed2_touchRevision($db, $songId, $ed2UserId, 'tag');
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
        logActivity('song.tag.attach', 'song', $songId, ['tag' => $name, 'tagId' => $tagId]);
        ed2_respond(['ok' => true, 'tag' => ['id' => $tagId, 'name' => $name, 'slug' => $slug], 'attached' => $attached]);
        break;
    }

    /* ---- tag_detach (POST) — remove a tag from a song by TagId ---- */
    case 'tag_detach': {
        $songId = trim((string)($body['songId'] ?? ''));
        $tagId  = (int)($body['tagId'] ?? 0);
        if ($songId === '' || $tagId <= 0) { ed2_respond(['ok' => false, 'error' => 'songId + tagId are required.'], 400); }
        $db->begin_transaction();
        try {
            $d = $db->prepare('DELETE FROM tblSongTagMap WHERE SongId = ? AND TagId = ?');
            $d->bind_param('si', $songId, $tagId);
            $d->execute();
            $removed = $d->affected_rows;
            $d->close();
            ed2_touchRevision($db, $songId, $ed2UserId, 'tag');
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
        logActivity('song.tag.detach', 'song', $songId, ['tagId' => $tagId]);
        ed2_respond(['ok' => true, 'removed' => (int)$removed]);
        break;
    }

    /* ---- link_save_all (POST) — reconcile the whole external-links sub-form
           (DELETE-then-INSERT), the SAME contract every other surface uses via
           saveExternalLinksForRow(). Links are a bounded sub-form with no
           dual-path race, so a reconcile (rather than per-row granular) is safe
           here and lets the editor reuse the shared card-list module + its DOM
           field naming verbatim. Returns the canonical persisted rows. ---- */
    case 'link_save_all': {
        $songId = trim((string)($body['songId'] ?? ''));
        $links  = is_array($body['links'] ?? null) ? $body['links'] : [];
        if ($songId === '') { ed2_respond(['ok' => false, 'error' => 'songId is required.'], 400); }
        if (!ed2_songExists($db, $songId)) { ed2_respond(['ok' => false, 'error' => 'Song not found.'], 404); }

        /* Unzip rows into the parallel arrays the shared helper expects. The
           helper itself validates each row (typeId>0, http(s) URL, ≤2048) and
           skips invalid ones, so a half-typed row never persists. */
        $typeIds = []; $urls = []; $notes = []; $verified = [];
        foreach ($links as $ln) {
            if (!is_array($ln)) { continue; }
            $typeIds[]  = (int)($ln['typeId'] ?? 0);
            $urls[]     = (string)($ln['url'] ?? '');
            $notes[]    = (string)($ln['note'] ?? '');
            $verified[] = !empty($ln['verified']) ? 1 : 0;
        }

        $db->begin_transaction();
        try {
            $count = saveExternalLinksForRow($db, 'tblSongExternalLinks', 'SongId', $songId, $typeIds, $urls, $notes, $verified);
            ed2_touchRevision($db, $songId, $ed2UserId, 'links');
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
        logActivity('song.external_links', 'song', $songId, ['count' => $count]);
        $saved = loadExternalLinksForRow($db, 'tblSongExternalLinks', 'SongId', $songId);
        ed2_respond(['ok' => true, 'count' => $count, 'links' => $saved]);
        break;
    }

    /* =====================================================================
     * SONG-LINK / COUNTERPART actions (#1608) — five cases ported verbatim
     * (matching v1's behaviour + response shape) from the legacy
     * manage/editor/api.php: get_song_links (:381), add_song_link (:459),
     * remove_song_link (:599), suggest_song_links (:676),
     * dismiss_song_link_suggestion (:772).
     *
     * ELI5: this is the "these are the same hymn, just in a different
     * songbook" feature. A curator can link e.g. MP-0031 (Amazing Grace) to
     * CH-0376 (also Amazing Grace) so the app can point between them, and
     * can accept/dismiss the computer's own guesses about which songs might
     * be the same.
     *
     * WHY THIS EXISTS HERE, NOW (#1608): v1's inline "Suggested counterparts"
     * panel in the editor sidebar was the ONLY place these five actions were
     * reachable, but `manage/editor/index.php` has 302-redirected every editor
     * visit to this v2 API's UI since #1601 landed — so the panel, and these
     * actions, have been LIVE-BUT-UNREACHABLE (the #1565/#1580 "looks alive,
     * isn't" class) for as long as v2 has been the default. #1220 explicitly
     * decided to keep the panel when the standalone suggestions PAGE was
     * absorbed into /manage/duplicate-songs (#1215) — the panel is a
     * complementary PUSH surface (surfaced the moment a curator has a song
     * open, with the most context) to duplicate-songs' PULL review queue, not
     * a duplicate of it. This commit is the "port the panel" resolution
     * recorded in .claude/wave4-prelaunch-plan.md §3/§6 Block C.
     *
     * RULE #22 (song_similarity.php is the ONE scorer): NONE of these five
     * actions call includes/song_similarity.php. suggest_song_links only ever
     * READS the PRE-SCORED tblSongLinkSuggestions table that the offline batch
     * `build-song-link-suggestions.php` fills (that batch is the scorer's only
     * caller) — so this file adds zero scoring maths, by construction.
     *
     * ENTITLEMENTS (per the plan + duplicate-songs.php's existing precedent,
     * duplicate-songs.php:34-42): page view rides the file-level editor-role
     * gate (same as v1); Link/Remove/Dismiss = edit_songs. The destructive
     * Merge action stays ONLY on /manage/duplicate-songs under
     * manage_duplicate_songs — this file never grows a merge case.
     *
     * No ed2_touchRevision() call anywhere below: tblSongLinks isn't part of
     * the song record (scalars/components/credits/tags/links-the-external-
     * kind) that a revision snapshot restores, matching v1's add/remove_song_link
     * exactly — neither of those wrote a revision row either.
     * ===================================================================== */

    /* ---- song_links (GET) — this song's cross-book counterpart group.
           Port of get_song_links (api.php:381).
           D10 (wave4-prelaunch-plan.md §5 defect list): v1 reads tblSongLinks
           UNPROBED — no INFORMATION_SCHEMA existence check — because the table
           ships in the SAME migration as tblSongLinkSuggestions/…Dismissed
           (#1216/#1219) and every install that has run any of #1608's sibling
           features already has it. Matched here exactly rather than inventing
           a new degrade v1 never had; only suggest_song_links (below) probes,
           because ITS table is the newer, separately-added
           tblSongLinkSuggestions from the fuzzy-match batch (#1219), which an
           older-but-still-#1216-migrated install could plausibly lack. ---- */
    case 'song_links': {
        /* @disabled-visible: admin editor API (#1765) — lists a song's links for
           the editor regardless of any book's public disabled state. */
        require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'song_soft_delete.php';
        $songId = trim((string)($_GET['id'] ?? ''));
        if ($songId === '') { ed2_respond(['ok' => false, 'error' => 'id is required.'], 400); }

        $stmt = $db->prepare('SELECT GroupId FROM tblSongLinks WHERE SongId = ? LIMIT 1');
        $stmt->bind_param('s', $songId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $groupId = $row ? (int)$row['GroupId'] : 0;

        $links = [];
        if ($groupId > 0) {
            /* #1694 — songVisibleSql() keeps a soft-deleted counterpart off
               this panel, the same guard v1 already had (api.php:420). */
            $stmt = $db->prepare(
                'SELECT l.Id           AS id,
                        l.SongId       AS songId,
                        l.Note         AS note,
                        l.Verified     AS verified,
                        s.Title        AS title,
                        s.Number       AS number,
                        s.SongbookAbbr AS songbook,
                        sb.Name        AS songbookName,
                        s.Language     AS language
                   FROM tblSongLinks l
                   JOIN tblSongs s      ON s.SongId = l.SongId
                   JOIN tblSongbooks sb ON sb.Abbreviation = s.SongbookAbbr
                  WHERE l.GroupId = ?
                    AND l.SongId  <> ?
                    AND ' . songVisibleSql($db, 's') . '
                  ORDER BY s.SongbookAbbr ASC, s.Number ASC'
            );
            $stmt->bind_param('is', $groupId, $songId);
            $stmt->execute();
            $links = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            foreach ($links as &$ln) {
                $ln['id']       = (int)$ln['id'];
                $ln['verified'] = (bool)$ln['verified'];
                $ln['number']   = ($ln['number'] === null) ? null : (int)$ln['number'];
            }
            unset($ln);
        }
        ed2_respond(['ok' => true, 'groupId' => $groupId, 'links' => $links]);
        break;
    }

    /* ---- song_link_add (POST) — link two songs as cross-book counterparts.
           Port of add_song_link (api.php:459). Body: { sourceSongId,
           targetSongId, note? }.

           Group-merge semantics, UNCHANGED from v1:
             - neither song grouped   -> mint a new GroupId, add both
             - exactly one grouped    -> add the other to it
             - both in the SAME group -> no-op (note refreshed if supplied)
             - both in DIFFERENT groups -> refuse; the curator must unlink
               one side first (rule #35: this is a STATUS code, 409, not a
               string the client pattern-matches). ---- */
    case 'song_link_add': {
        /* @disabled-visible: admin editor API (#1765) — cross-book link write;
           either endpoint may live in a disabled book, still fully editable. */
        ed2_requireEntitlement('edit_songs');
        require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'song_soft_delete.php';
        $srcId = trim((string)($body['sourceSongId'] ?? ''));
        $tgtId = trim((string)($body['targetSongId'] ?? ''));
        $note  = trim((string)($body['note'] ?? ''));
        if ($srcId === '' || $tgtId === '') {
            ed2_respond(['ok' => false, 'error' => 'sourceSongId and targetSongId are required.'], 400);
        }
        if ($srcId === $tgtId) {
            ed2_respond(['ok' => false, 'error' => 'A song cannot be linked to itself.'], 400);
        }

        /* Validate both songs exist AND are visible before mutating anything
           — cheaper than an FK failure, and #1694-consistent: a hidden song
           cannot be linked to (it's offered nowhere, so a stale request
           naming one should read as not-found, not silently succeed). */
        $probe = $db->prepare(
            'SELECT SongId FROM tblSongs WHERE SongId IN (?, ?) AND ' . songVisibleSql($db, '')
        );
        $probe->bind_param('ss', $srcId, $tgtId);
        $probe->execute();
        $found = [];
        $res = $probe->get_result();
        while ($r = $res->fetch_assoc()) { $found[] = $r['SongId']; }
        $probe->close();
        if (count($found) < 2) {
            ed2_respond(['ok' => false, 'error' => 'One or both songs were not found.'], 404);
        }

        $lookup = $db->prepare('SELECT SongId, GroupId FROM tblSongLinks WHERE SongId IN (?, ?)');
        $lookup->bind_param('ss', $srcId, $tgtId);
        $lookup->execute();
        $existing = [];
        $res = $lookup->get_result();
        while ($r = $res->fetch_assoc()) { $existing[$r['SongId']] = (int)$r['GroupId']; }
        $lookup->close();

        $srcGroup  = $existing[$srcId] ?? 0;
        $tgtGroup  = $existing[$tgtId] ?? 0;
        $createdBy = $ed2UserId;

        if ($srcGroup > 0 && $tgtGroup > 0 && $srcGroup === $tgtGroup) {
            if ($note !== '') {
                $db->begin_transaction();
                try {
                    $upd = $db->prepare('UPDATE tblSongLinks SET Note = ? WHERE SongId = ?');
                    $upd->bind_param('ss', $note, $tgtId);
                    $upd->execute();
                    $upd->close();
                    $db->commit();
                } catch (\Throwable $e) {
                    $db->rollback();
                    throw $e;
                }
            }
            ed2_respond(['ok' => true, 'groupId' => $srcGroup, 'noop' => true]);
        }
        if ($srcGroup > 0 && $tgtGroup > 0) {
            /* Different groups. The 409 status IS the contract the client
               branches on (rule #35) — the sentence is free to reword. */
            ed2_respond([
                'ok'    => false,
                'error' => 'Both songs are already in different counterpart groups. Unlink one before linking, or use the merge tool.',
            ], 409);
        }

        $db->begin_transaction();
        try {
            if ($srcGroup === 0 && $tgtGroup === 0) {
                /* Neither grouped — mint a new GroupId. MAX(GroupId)+1 is fine:
                   tblSongLinks is small + curator-edited, and an AUTO_INCREMENT
                   GroupId would complicate a future merge-groups op (matches
                   v1's reasoning verbatim, api.php:546-553). Wrapped in the
                   transaction (v1 was not) so two concurrent first-links can't
                   race onto the same minted id — a strict safety IMPROVEMENT
                   over v1, not a behaviour change any caller can observe. */
                $r = $db->query('SELECT COALESCE(MAX(GroupId), 0) + 1 AS NextId FROM tblSongLinks');
                $newGroup = $r ? (int)$r->fetch_assoc()['NextId'] : 1;
                if ($r) { $r->close(); }

                $ins = $db->prepare(
                    'INSERT INTO tblSongLinks (GroupId, SongId, Note, CreatedBy)
                     VALUES (?, ?, ?, ?), (?, ?, ?, ?)'
                );
                $emptyNote = '';
                /* CORRECTED type string, NOT a verbatim port of this one line:
                   v1 (api.php:561) binds this same 8-value pair as
                   'issiisis' — positions 7-8 swap $note's 's' and
                   $createdBy's 'i', so mysqli coerces the non-numeric $note
                   string to (int) 0 on every brand-new counterpart group,
                   silently discarding whatever text the curator typed. Caught
                   by this commit's own behavioural verification (a POSTed
                   note came back as the literal string "0" on re-GET) — a
                   type-string transposition bind_param()'s signature can't
                   catch (rule #5 talks about placeholder/VALUE COUNT
                   mismatches via bindParamSafe(); this is a same-count
                   type mismatch, a different failure shape). Fixing a
                   verbatim port would ship a KNOWN, freshly-discovered data-
                   loss bug into new code, so this one line intentionally
                   diverges from v1: 'issiissi' below is i,s,s,i,i,s,s,i —
                   note stays 's', createdBy stays 'i'. Filed as its own v1
                   bug report (does not block this port; v1 is scheduled for
                   retirement under #1601). */
                $ins->bind_param(
                    'issiissi',
                    $newGroup, $srcId, $emptyNote, $createdBy,
                    $newGroup, $tgtId, $note,      $createdBy
                );
                $ins->execute();
                $ins->close();
                $groupId = $newGroup;
                $extra   = ['created' => true];
            } else {
                /* Exactly one side already grouped — extend it. */
                $joinGroup = $srcGroup > 0 ? $srcGroup : $tgtGroup;
                $newSongId = $srcGroup > 0 ? $tgtId    : $srcId;
                $ins = $db->prepare(
                    'INSERT INTO tblSongLinks (GroupId, SongId, Note, CreatedBy)
                     VALUES (?, ?, ?, ?)'
                );
                $ins->bind_param('issi', $joinGroup, $newSongId, $note, $createdBy);
                $ins->execute();
                $ins->close();
                $groupId = $joinGroup;
                $extra   = ['extended' => true];
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }

        logActivity('song.link', 'song', $srcId, ['groupId' => $groupId, 'target' => $tgtId] + $extra);
        ed2_respond(['ok' => true, 'groupId' => $groupId] + $extra);
        break;
    }

    /* ---- song_link_remove (POST) — drop one song from its counterpart
           group. Port of remove_song_link (api.php:599). Body:
           { id?: int, songId?: string } — either identifier works.

           A resulting group of <2 members is meaningless (a "counterpart"
           of nobody), so it's cleaned up too — matches v1 verbatim.
           Already-gone -> {ok:true, deleted:0}, NOT a 404: a double-click on
           the Unlink button must not surface a spurious error. ---- */
    case 'song_link_remove': {
        ed2_requireEntitlement('edit_songs');
        $removeId = (int)($body['id'] ?? 0);
        $songId   = trim((string)($body['songId'] ?? ''));
        if ($removeId <= 0 && $songId === '') {
            ed2_respond(['ok' => false, 'error' => 'id or songId is required.'], 400);
        }

        if ($removeId > 0) {
            $stmt = $db->prepare('SELECT Id, GroupId FROM tblSongLinks WHERE Id = ?');
            $stmt->bind_param('i', $removeId);
        } else {
            $stmt = $db->prepare('SELECT Id, GroupId FROM tblSongLinks WHERE SongId = ?');
            $stmt->bind_param('s', $songId);
        }
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            ed2_respond(['ok' => true, 'deleted' => 0]);
        }

        $groupId = (int)$row['GroupId'];
        $rowId   = (int)$row['Id'];

        $db->begin_transaction();
        try {
            $del = $db->prepare('DELETE FROM tblSongLinks WHERE Id = ?');
            $del->bind_param('i', $rowId);
            $del->execute();
            $deleted = $del->affected_rows;
            $del->close();

            /* Fewer than two members left in the group? Drop the remainder
               — a singleton group is meaningless and would otherwise occupy
               a slot forever showing "no counterparts". */
            $r = $db->prepare('SELECT COUNT(*) AS n FROM tblSongLinks WHERE GroupId = ?');
            $r->bind_param('i', $groupId);
            $r->execute();
            $remaining = (int)$r->get_result()->fetch_assoc()['n'];
            $r->close();
            if ($remaining < 2) {
                $cleanup = $db->prepare('DELETE FROM tblSongLinks WHERE GroupId = ?');
                $cleanup->bind_param('i', $groupId);
                $cleanup->execute();
                $cleanup->close();
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }

        logActivity('song.link.remove', 'song', $songId !== '' ? $songId : (string)$rowId, ['groupId' => $groupId, 'deleted' => (int)$deleted]);
        ed2_respond(['ok' => true, 'deleted' => (int)$deleted]);
        break;
    }

    /* ---- song_link_suggestions (GET) — up to 5 highest-scoring pending
           counterpart suggestions involving this song. Port of
           suggest_song_links (api.php:676).

           RULE #22: this reads the PRE-SCORED tblSongLinkSuggestions table —
           built offline by build-song-link-suggestions.php, the ONE consumer
           of includes/song_similarity.php's ihymns_sim_score(). No scoring
           maths lives here or anywhere in this file.

           Probed (unlike song_links above — see that case's D10 comment):
           an install can have run #1216's original tblSongLinks migration
           without yet having run #1219's later tblSongLinkSuggestions
           addition, so an un-migrated read here degrades to an empty list +
           tableMissing:true rather than a mysqli-STRICT throw (matches v1
           exactly, api.php:686-700). ---- */
    case 'song_link_suggestions': {
        /* @disabled-visible: admin editor API (#1765) — suggestion review spans all
           songs regardless of any book's public disabled state. */
        require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'song_soft_delete.php';
        $songId = trim((string)($_GET['id'] ?? ''));
        if ($songId === '') { ed2_respond(['ok' => false, 'error' => 'id is required.'], 400); }

        $probe = $db->query(
            "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME   = 'tblSongLinkSuggestions' LIMIT 1"
        );
        $hasTable = $probe && $probe->fetch_row() !== null;
        if ($probe) { $probe->close(); }
        if (!$hasTable) {
            ed2_respond(['ok' => true, 'suggestions' => [], 'tableMissing' => true]);
        }

        $stmt = $db->prepare(
            'SELECT s.Id          AS id,
                    s.SongIdA     AS songIdA,
                    s.SongIdB     AS songIdB,
                    s.Score       AS score,
                    s.TitleScore  AS titleScore,
                    s.LyricsScore AS lyricsScore,
                    a.Title       AS titleA,
                    a.Number      AS numberA,
                    a.SongbookAbbr AS songbookA,
                    b.Title       AS titleB,
                    b.Number      AS numberB,
                    b.SongbookAbbr AS songbookB
               FROM tblSongLinkSuggestions s
               JOIN tblSongs a ON a.SongId = s.SongIdA AND ' . songVisibleSql($db, 'a') . '
               JOIN tblSongs b ON b.SongId = s.SongIdB AND ' . songVisibleSql($db, 'b') . '
              WHERE (s.SongIdA = ? OR s.SongIdB = ?)
                AND NOT EXISTS (
                    SELECT 1 FROM tblSongLinkSuggestionsDismissed d
                     WHERE d.SongIdA = s.SongIdA AND d.SongIdB = s.SongIdB
                )
                /* Skip pairs already in the same counterpart group — already linked. */
                AND NOT EXISTS (
                    SELECT 1
                      FROM tblSongLinks la
                      JOIN tblSongLinks lb ON la.GroupId = lb.GroupId
                     WHERE la.SongId = s.SongIdA AND lb.SongId = s.SongIdB
                )
              ORDER BY s.Score DESC
              LIMIT 5'
        );
        $stmt->bind_param('ss', $songId, $songId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        /* Normalise so the "other side" is always in a single `other` field,
           regardless of which slot the current song was found in. */
        $suggestions = [];
        foreach ($rows as $r) {
            $isA = ($r['songIdA'] === $songId);
            $suggestions[] = [
                'id'          => (int)$r['id'],
                'score'       => (float)$r['score'],
                'titleScore'  => (float)$r['titleScore'],
                'lyricsScore' => (float)$r['lyricsScore'],
                'other' => [
                    'songId'   => $isA ? $r['songIdB']   : $r['songIdA'],
                    'title'    => $isA ? $r['titleB']    : $r['titleA'],
                    'number'   => $isA ? $r['numberB']   : $r['numberA'],
                    'songbook' => $isA ? $r['songbookB'] : $r['songbookA'],
                ],
            ];
        }
        ed2_respond(['ok' => true, 'suggestions' => $suggestions]);
        break;
    }

    /* ---- song_link_suggestion_dismiss (POST) — curator says "no, different
           hymns". Port of dismiss_song_link_suggestion (api.php:772). Body:
           { songIdA, songIdB, reason? } — canonical order (SongIdA < SongIdB
           lexicographically) is enforced server-side so callers needn't
           pre-sort, matching the build-script invariant.

           Writes tblSongLinkSuggestionsDismissed — the SAME table
           /manage/duplicate-songs's `dismiss` action writes (CLAUDE.md rule
           #22: "a cluster is suppressed only when ALL its pairs are
           dismissed"), so a dismissal from this panel and a dismissal on
           duplicate-songs are two views of ONE workflow, never two
           divergent stores. ---- */
    case 'song_link_suggestion_dismiss': {
        ed2_requireEntitlement('edit_songs');
        $a      = trim((string)($body['songIdA'] ?? ''));
        $b      = trim((string)($body['songIdB'] ?? ''));
        $reason = trim((string)($body['reason'] ?? ''));
        if ($a === '' || $b === '' || $a === $b) {
            ed2_respond(['ok' => false, 'error' => 'songIdA and songIdB are required and must differ.'], 400);
        }
        if ($a > $b) { [$a, $b] = [$b, $a]; }

        $db->begin_transaction();
        try {
            $dismissedBy = $ed2UserId;
            $stmt = $db->prepare(
                'INSERT INTO tblSongLinkSuggestionsDismissed (SongIdA, SongIdB, DismissedBy, Reason)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE Reason = VALUES(Reason),
                                         DismissedBy = VALUES(DismissedBy),
                                         DismissedAt = CURRENT_TIMESTAMP'
            );
            $stmt->bind_param('ssis', $a, $b, $dismissedBy, $reason);
            $stmt->execute();
            $stmt->close();

            /* Drop the matching pending suggestion so it disappears from
               every consumer immediately (this panel AND duplicate-songs). */
            $del = $db->prepare(
                'DELETE FROM tblSongLinkSuggestions WHERE SongIdA = ? AND SongIdB = ?'
            );
            $del->bind_param('ss', $a, $b);
            $del->execute();
            $del->close();
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }

        logActivity('song.link.dismiss', 'song', $a, ['other' => $b, 'reason' => $reason]);
        ed2_respond(['ok' => true]);
        break;
    }

    /* =========================================================================
     * song_external_ids / song_external_id_add / song_external_id_delete
     * (#1741 P5b) — tblSongExternalIds' FIRST UI write path.
     *
     * ELI5: a song can have a Spotify id, a MusicBrainz recording id, a
     * SoundExchange code, and so on — this is the little list on the
     * Metadata tab where a curator can see and add those.
     *
     * DETAILED
     * --------------------------------------------------------------------
     * The table has existed since D5 (#1741, `migrate-song-external-ids.php`)
     * and the #1747 D5 backfill mirrored grandfathered identifiers into it,
     * but until now NOTHING in the editor could read or write a row — this
     * is that write path. It reuses, never re-forks:
     *   - the ONE table probe, `songExternalIdsTableExists()`
     *     (includes/song_external_ids.php), already `require_once`'d at
     *     module scope for the #1749 P5d ISRC mirror — no second
     *     INFORMATION_SCHEMA probe under a different name;
     *   - the ONE IdType/IdScope vocabulary, `RECORDING_EXTERNAL_ID_TYPES`
     *     + its validators (media_identifiers.php) — `mediaIdentifierIdTypeValid()`
     *     rejects an unrecognised slug, `mediaIdentifierValidateValue()`
     *     shape-checks the value "where standard" (a null `validate` pattern
     *     accepts any non-empty value, per that file's documented contract),
     *     `mediaIdentifierScopeForType()` derives IdScope server-side — a
     *     client can never claim its own scope;
     *   - the ONE ISRC fold, `ihymns_canonical_isrc()` — the identical
     *     canonicalisation the metadata_field_update ISRC branch applies to
     *     `tblSongs.Isrc`, so a curator-entered ISRC recording id lands in
     *     the SAME normalised shape either write path produces.
     *
     * No `ed2_touchRevision()` call anywhere below: external IDs are not part
     * of the song record a revision snapshot restores (scalars/components/
     * credits/tags/links-the-external-kind) — the SAME posture tblSongLinks
     * takes (see that section's comment above) and media file metadata takes
     * (kind/filename/annotation live outside the snapshot too).
     *
     * ENTITLEMENT: the file-level editor-role gate only (matches
     * credit_upsert/tag_attach) — adding or removing an external id is
     * ordinary curation, not a destructive class like delete_song.
     * ===================================================================== */

    /* ---- song_external_ids (GET) — a song's external IDs. ---- */
    case 'song_external_ids': {
        $songId = trim((string)($_GET['id'] ?? ''));
        if ($songId === '') { ed2_respond(['ok' => false, 'error' => 'id is required.'], 400); }
        if (!ed2_songExists($db, $songId)) { ed2_respond(['ok' => false, 'error' => 'Song not found.'], 404); }

        if (!songExternalIdsTableExists($db)) {
            /* Un-migrated read degrades to an empty list + tableMissing:true,
               matching song_link_suggestions above — never a mysqli-STRICT
               throw (rule #35: status/flag is the contract). */
            ed2_respond(['ok' => true, 'externalIds' => [], 'tableMissing' => true]);
        }

        $stmt = $db->prepare(
            'SELECT Id, IdScope, IdType, IdValue, Source, SourceRef
               FROM tblSongExternalIds
              WHERE SongId = ?
              ORDER BY IdType ASC, Id ASC'
        );
        $stmt->bind_param('s', $songId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        ed2_respond(['ok' => true, 'externalIds' => array_map('ed2_songExternalIdRowShape', $rows)]);
        break;
    }

    /* ---- song_external_id_add (POST) — add one manual external id. ---- */
    case 'song_external_id_add': {
        $songId  = trim((string)($body['songId'] ?? ''));
        $idType  = trim((string)($body['idType'] ?? ''));
        $idValue = trim((string)($body['idValue'] ?? ''));
        if ($songId === '' || $idType === '' || $idValue === '') {
            ed2_respond(['ok' => false, 'error' => 'songId, idType and idValue are required.'], 400);
        }
        if (!ed2_songExists($db, $songId)) { ed2_respond(['ok' => false, 'error' => 'Song not found.'], 404); }
        if (!songExternalIdsTableExists($db)) {
            ed2_respond(['ok' => false, 'error' => 'This install has not applied the song external IDs (#1741 D5) migration card yet (run it at /manage/setup-database).'], 409);
        }
        if (!mediaIdentifierIdTypeValid($idType)) {
            ed2_respond(['ok' => false, 'error' => 'Unrecognised external-ID type "' . $idType . '".'], 422);
        }
        /* ISRC canonicalises FIRST — the SAME fold the metadata_field_update
           ISRC branch (#1741 P5a) applies, so a value entered here and a value
           entered on the Metadata tab's ISRC field end up in the identical
           normalised form (uppercase, no separators) before shape-validation. */
        if ($idType === 'isrc') {
            $idValue = ihymns_canonical_isrc($idValue);
        }
        if (!mediaIdentifierValidateValue($idType, $idValue)) {
            $label = RECORDING_EXTERNAL_ID_TYPES[$idType]['label'] ?? $idType;
            ed2_respond(['ok' => false, 'error' => 'That value does not look like a valid ' . $label . '.'], 422);
        }
        /* Server-derived, never a client param — a caller cannot claim its
           own IdScope for a recognised IdType. */
        $idScope = (string)(mediaIdentifierScopeForType($idType) ?? 'recording');

        $db->begin_transaction();
        /* #1749 full unification — set only when $idType === 'isrc' (below);
           stays null for every other id type, so the response builder can
           branch on key-PRESENCE (rule #35) rather than a null-vs-absent
           ambiguity. */
        $isrcDenorm = null;
        try {
            /* INSERT IGNORE — uq_Song_Type_Value (SongId, IdType, IdValue)
               handles the dupe; $created below tells the client whether a NEW
               row actually landed, so a re-add of an existing value can say
               "Already recorded" instead of a silent (and misleading) success. */
            $source = 'manual';
            $ins = $db->prepare(
                'INSERT IGNORE INTO tblSongExternalIds (SongId, IdScope, IdType, IdValue, Source, SourceRef)
                 VALUES (?, ?, ?, ?, ?, NULL)'
            );
            $ins->bind_param('sssss', $songId, $idScope, $idType, $idValue, $source);
            $ins->execute();
            $created = $ins->affected_rows > 0;
            $ins->close();

            /* Re-select on EITHER outcome, so the echo is always the
               CANONICAL stored row (never the caller's own typed input) —
               matters most on a collision, where the existing row may carry
               a different Source (e.g. an earlier #1749 mirror or #1747
               backfill row for the identical value). */
            $sel = $db->prepare(
                'SELECT Id, IdScope, IdType, IdValue, Source, SourceRef
                   FROM tblSongExternalIds
                  WHERE SongId = ? AND IdType = ? AND IdValue = ?'
            );
            $sel->bind_param('sss', $songId, $idType, $idValue);
            $sel->execute();
            $row = $sel->get_result()->fetch_assoc();
            $sel->close();

            /* #1749 full unification — a manual ADD of an isrc row is a store
               mutation this panel makes OUTSIDE the tblSongs.Isrc mirror, so
               (unlike metadata_field_update above) nothing else in this
               transaction keeps the column in lockstep — that WAS the 0.9
               bug the build spec names ("the panel already desyncs the
               column, by design-of-the-old-model"). Project the store's
               current primary ISRC back into the column, in the SAME
               transaction, so an Add can never leave the two disagreeing
               even transiently. */
            if ($idType === 'isrc') {
                $isrcDenorm = songExternalIdSyncIsrcDenorm($db, $songId);
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }

        logActivity('song.external_id.add', 'song', $songId, ['idType' => $idType, 'idValue' => $idValue]);
        $ed2ExtIdAddResponse = [
            'ok'         => true,
            'externalId' => $row ? ed2_songExternalIdRowShape($row) : null,
            'created'    => $created,
        ];
        /* #1749 — key-PRESENCE is the client's branch signal (rule #35: a
           flag/shape, not error prose), so the key is added ONLY for an isrc
           add — never present-but-null for every other id type, which would
           make "is this an isrc row" ambiguous to the caller. */
        if ($idType === 'isrc') {
            $ed2ExtIdAddResponse['isrcDenorm'] = $isrcDenorm;
        }
        ed2_respond($ed2ExtIdAddResponse);
        break;
    }

    /* ---- song_external_id_delete (POST) — remove one external id row. ---- */
    case 'song_external_id_delete': {
        $songId = trim((string)($body['songId'] ?? ''));
        $id     = (int)($body['id'] ?? 0);
        if ($songId === '' || $id <= 0) {
            ed2_respond(['ok' => false, 'error' => 'songId and id are required.'], 400);
        }
        if (!songExternalIdsTableExists($db)) {
            ed2_respond(['ok' => false, 'error' => 'This install has not applied the song external IDs (#1741 D5) migration card yet (run it at /manage/setup-database).'], 409);
        }

        /* #1749 full unification — pre-read the row's IdType BEFORE the
           DELETE below, so we know AFTER it whether the store's projection
           needs re-syncing into tblSongs.Isrc. This is the reason this case
           now opens a transaction it didn't need before: a single DELETE was
           already atomic on its own (the comment this replaces said so, and
           it was true THEN), but "DELETE, then conditionally sync the
           denorm" is two statements that must commit or roll back together —
           the exact reason credit_delete/tag_detach open one for their own
           ed2_touchRevision() pairing, cited (and now itself expired) in the
           comment this replaces. */
        $preRead = $db->prepare('SELECT IdType FROM tblSongExternalIds WHERE Id = ? AND SongId = ?');
        $preRead->bind_param('is', $id, $songId);
        $preRead->execute();
        $preReadRow = $preRead->get_result()->fetch_assoc();
        $preRead->close();
        $wasIsrc = $preReadRow !== null && (string)$preReadRow['IdType'] === 'isrc';

        $isrcDenorm = null;
        $db->begin_transaction();
        try {
            /* SongId in the WHERE alongside Id — defence-in-depth against a
               cross-song id (a stale/tampered id from a different song's
               panel can never delete someone else's row). Already-gone ->
               deleted:0, the same idempotent-double-click posture as
               song_link_remove above, not an error. */
            $del = $db->prepare('DELETE FROM tblSongExternalIds WHERE Id = ? AND SongId = ?');
            $del->bind_param('is', $id, $songId);
            $del->execute();
            $deleted = $del->affected_rows;
            $del->close();

            /* #1749 — deleting the mirror row (or ANY isrc row) is allowed;
               it is no longer merely "harmless because the next ISRC save
               re-mints it" (that was true only because the OLD model let the
               column sit stale in between — exactly the desync class this
               unification exists to kill). Instead it is ACTUALLY harmless
               immediately: re-project the store's remaining primary ISRC
               (or NULL, if none is left) into the column, in the SAME
               transaction as the delete, so a curator never sees a stale
               column even for one page load. */
            if ($wasIsrc) {
                $isrcDenorm = songExternalIdSyncIsrcDenorm($db, $songId);
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }

        logActivity('song.external_id.delete', 'song', $songId, ['id' => $id]);
        $ed2ExtIdDeleteResponse = ['ok' => true, 'deleted' => (int)max(0, $deleted)];
        /* #1749 — key-PRESENCE (rule #35), mirroring song_external_id_add's
           response shape immediately above: present only when the deleted
           row was itself an isrc row, so the client's branch is unambiguous. */
        if ($wasIsrc) {
            $ed2ExtIdDeleteResponse['isrcDenorm'] = $isrcDenorm;
        }
        ed2_respond($ed2ExtIdDeleteResponse);
        break;
    }

    /* =========================================================================
     * song_alt_titles / song_alt_title_add / song_alt_title_delete (#1669,
     * epic #832) — tblSongAlternativeTitles' FIRST UI write path.
     *
     * ELI5: a song can be catalogued under more than one name — "Amazing
     * Grace" is also "Faith's Review and Expectation" — and this is the
     * little list on the Metadata tab (beside the Title field) where a
     * curator can see and add those.
     *
     * DETAILED
     * --------------------------------------------------------------------
     * The table has existed since #832 (`migrate-alternative-titles.php`)
     * and its READ half has been live the whole time —
     * `SongData::_songAltTitlesMap()` feeds the public song page's "Also
     * known as" line, the OG image, and the #832 search boost (a query
     * matching an alt title ranks the song top) — but until now the ONLY
     * `INSERT` anywhere in the tree was the `duplicate_song` case's
     * `INSERT … SELECT` COPY of an EXISTING song's rows (above), which can
     * never create a FIRST row. This is that write path. It reuses, never
     * re-forks, the ONE core `includes/song_alt_titles.php` (rule #22) —
     * every case body below is a thin delegate, no inline
     * tblSongAlternativeTitles SQL here.
     *
     * WHY NOT RULE #43's FIND-OR-CREATE PICKER: an alt title is per-song
     * FREE TEXT — a title string, not a reference to a registry entity
     * (tblTunes/tblPublishers/tblMusicians/…) that could be shared or
     * looked up across songs. Nothing here mints or reuses a cross-song
     * row, so rule #43 does not apply (see song_alt_titles.php's own
     * doc-block for the full reasoning).
     *
     * No `ed2_touchRevision()` call anywhere below: alt titles are not part
     * of the song record a revision snapshot restores — the SAME posture
     * tblSongExternalIds (see that section's comment above) and
     * tblSongLinks take.
     *
     * ENTITLEMENT: the file-level editor-role gate only (matches
     * credit_upsert/tag_attach/song_external_id_add) — adding or removing
     * an alt title is ordinary curation, not a destructive class like
     * delete_song.
     * ===================================================================== */

    /* ---- song_alt_titles (GET) — a song's alternative titles. ---- */
    case 'song_alt_titles': {
        $songId = trim((string)($_GET['id'] ?? ''));
        if ($songId === '') { ed2_respond(['ok' => false, 'error' => 'id is required.'], 400); }
        if (!ed2_songExists($db, $songId)) { ed2_respond(['ok' => false, 'error' => 'Song not found.'], 404); }

        if (!songAltTitlesTableExists($db)) {
            /* Un-migrated read degrades to an empty list + tableMissing:true,
               matching song_external_ids above — never a mysqli-STRICT
               throw (rule #35: status/flag is the contract). */
            ed2_respond(['ok' => true, 'altTitles' => [], 'tableMissing' => true]);
        }

        ed2_respond(['ok' => true, 'altTitles' => songAltTitlesList($db, $songId)]);
        break;
    }

    /* ---- song_alt_title_add (POST) — add one alternative title. ---- */
    case 'song_alt_title_add': {
        $songId   = trim((string)($body['songId'] ?? ''));
        $title    = trim((string)($body['title'] ?? ''));
        $language = (string)($body['language'] ?? '');
        $note     = (string)($body['note'] ?? '');

        if ($songId === '') {
            ed2_respond(['ok' => false, 'error' => 'songId is required.'], 400);
        }
        if (!ed2_songExists($db, $songId)) { ed2_respond(['ok' => false, 'error' => 'Song not found.'], 404); }
        if (!songAltTitlesTableExists($db)) {
            ed2_respond(['ok' => false, 'error' => 'This install has not applied the alternative titles (#832) migration card yet (run it at /manage/setup-database).'], 409);
        }

        /* An alt title identical to the song's own CURRENT main title
           (case-insensitive) is refused with a specific message rather
           than silently stored as a pointless duplicate-of-itself row —
           songAltTitleIsRedundant() is the shared pure check (also used by
           the optional C9 importer path). Read fresh from tblSongs rather
           than trusting any client-sent title, since the client's copy of
           the song could be stale.
           @deleted-visible: editor admin surface (#1694) — the redundancy
             check reads the song's own CURRENT main title by KNOWN SongId
             (existence already gated by ed2_songExists above). A soft-deleted
             song's title must still be compared; applying the visibility
             predicate here would blank $mainTitle and silently SKIP the
             redundancy guard, not protect anything.
           @disabled-visible: editor admin surface (#1765) — same read: the
             editor operates on one known song regardless of whether its
             songbook is disabled, and the comparison needs the real stored
             title. */
        $ts = $db->prepare('SELECT Title FROM tblSongs WHERE SongId = ?');
        $ts->bind_param('s', $songId);
        $ts->execute();
        $mainTitleRow = $ts->get_result()->fetch_row();
        $ts->close();
        $mainTitle = $mainTitleRow ? (string)$mainTitleRow[0] : '';
        if (songAltTitleIsRedundant($title, $mainTitle)) {
            ed2_respond(['ok' => false, 'error' => "That is already the song's main title."], 422);
        }

        try {
            $result = songAltTitleAdd($db, $songId, $title, $language, $note);
        } catch (\InvalidArgumentException $e) {
            ed2_respond(['ok' => false, 'error' => $e->getMessage()], 422);
        }

        logActivity('song.alt_title.add', 'song', $songId, ['title' => $result['row']['title']]);
        ed2_respond(['ok' => true, 'altTitle' => $result['row'], 'created' => $result['created']]);
        break;
    }

    /* ---- song_alt_title_delete (POST) — remove one alternative title. ---- */
    case 'song_alt_title_delete': {
        $songId = trim((string)($body['songId'] ?? ''));
        $id     = (int)($body['id'] ?? 0);
        if ($songId === '' || $id <= 0) {
            ed2_respond(['ok' => false, 'error' => 'songId and id are required.'], 400);
        }
        if (!songAltTitlesTableExists($db)) {
            ed2_respond(['ok' => false, 'error' => 'This install has not applied the alternative titles (#832) migration card yet (run it at /manage/setup-database).'], 409);
        }

        $deleted = songAltTitleDelete($db, $songId, $id);

        logActivity('song.alt_title.delete', 'song', $songId, ['id' => $id]);
        ed2_respond(['ok' => true, 'deleted' => $deleted]);
        break;
    }

    /* ---- media_list (GET) — file metadata for a song (never bytes) ---- */
    case 'media_list': {
        $songId = trim((string)($_GET['id'] ?? ''));
        if ($songId === '') { ed2_respond(['ok' => false, 'error' => 'id is required.'], 400); }
        $media = [];
        if (ed2_songMediaTableExists($db)) {
            $ms = $db->prepare(
                'SELECT Id, Kind, StorageBackend, FileName, MimeType, SizeBytes,
                        Annotation, SortOrder, UploadedBy, UploadedAt'
                 . songMediaVisibilitySelectFragment($db) . '
                   FROM tblSongMedia WHERE SongId = ?
                  ORDER BY Kind ASC, SortOrder ASC, Id ASC'
            );
            $ms->bind_param('s', $songId);
            $ms->execute();
            $mr = $ms->get_result();
            while ($row = $mr->fetch_assoc()) { $media[] = ed2_mediaRowShape($row); }
            $ms->close();
        }
        ed2_respond(['ok' => true, 'media' => $media]);
        break;
    }

    /* ---- media_upload (POST, MULTIPART) — the one multipart endpoint: reads
           $_POST + $_FILES, not the JSON $body. The top-of-file CSRF guard still
           applies (token in the X-CSRF-Token header). MIME is SNIFFED on the
           bytes (never the declared content-type); size-capped per kind; staged
           FS-or-DB by SongMediaStorage. An FS file staged before an INSERT that
           then fails is unlinked so nothing orphans. ---- */
    case 'media_upload': {
        if ($method !== 'POST') { ed2_respond(['ok' => false, 'error' => 'POST required.'], 405); }
        if (!ed2_songMediaTableExists($db)) { ed2_respond(['ok' => false, 'error' => 'Song Media migration has not been run.'], 503); }

        $songId     = trim((string)($_POST['songId'] ?? ''));
        $kind       = trim((string)($_POST['kind'] ?? ''));
        $annotation = trim((string)($_POST['annotation'] ?? ''));
        if ($songId === '' || !in_array($kind, SongMediaStorage::allKinds(), true)) {
            ed2_respond(['ok' => false, 'error' => 'Missing or invalid songId / kind.'], 400);
        }
        if ($annotation !== '') { $annotation = mb_substr($annotation, 0, 255); }
        if (!ed2_songExists($db, $songId)) { ed2_respond(['ok' => false, 'error' => 'Song not found.'], 404); }

        if (!isset($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $err = $_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE;
            $msg = match ($err) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File exceeds the server upload size limit.',
                UPLOAD_ERR_NO_FILE                        => 'No file received — expected multipart with a "file" field.',
                UPLOAD_ERR_PARTIAL                        => 'Upload was interrupted.',
                default                                   => 'Upload failed.',
            };
            ed2_respond(['ok' => false, 'error' => $msg, 'phpError' => $err], 400);
        }

        $tmpPath  = (string)$_FILES['file']['tmp_name'];
        $origName = (string)($_FILES['file']['name'] ?? 'upload');
        $size     = (int)($_FILES['file']['size'] ?? 0);
        $staged   = null;   // tracked so the outer catch can unlink an FS orphan

        try {
            $meta  = SongMediaStorage::validateUpload($tmpPath, $kind, $size);
            $bytes = file_get_contents($tmpPath);
            if ($bytes === false) { throw new \RuntimeException('Could not read upload tempfile.'); }
            $staged = SongMediaStorage::stage($bytes, $kind, $meta['extension']);

            $cleanName = basename($origName);
            $cleanName = preg_replace('/[\x00-\x1f\x7f]/', '', $cleanName) ?? 'upload';
            $cleanName = mb_substr($cleanName, 0, 255);

            $db->begin_transaction();
            try {
                /* SortOrder = (max+1) for this (song, kind) so new uploads append. */
                $mx = $db->prepare('SELECT COALESCE(MAX(SortOrder), -1) AS m FROM tblSongMedia WHERE SongId = ? AND Kind = ?');
                $mx->bind_param('ss', $songId, $kind);
                $mx->execute();
                $nextOrder = (int)($mx->get_result()->fetch_assoc()['m'] ?? -1) + 1;
                $mx->close();

                $annotationOrNull = ($annotation !== '') ? $annotation : null;
                $ins = $db->prepare(
                    'INSERT INTO tblSongMedia
                        (SongId, Kind, StorageBackend, FileName, MimeType, SizeBytes,
                         Sha256, Content, StoragePath, Annotation, SortOrder, UploadedBy)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                /* 's' for the BLOB content is fine under the sub-16MB cap (mysqli is binary-safe). */
                $ins->bind_param(
                    'sssssissssii',
                    $songId, $kind, $staged['backend'], $cleanName, $meta['mime'], $size,
                    $staged['sha256'], $staged['content'], $staged['path'], $annotationOrNull, $nextOrder, $ed2UserId
                );
                $ins->execute();
                $newId = (int)$db->insert_id;
                $ins->close();
                /* #1860 go-live — mint this media row's permanent IL-id (ILD…). */
                ilidStampNewRow($db, 'document', $newId);

                ed2_touchRevision($db, $songId, $ed2UserId, 'media');
                $db->commit();
            } catch (\Throwable $e) {
                $db->rollback();
                throw $e;
            }

            /* #1862 — a new media row can flip HasAudio/HasSheetMusic; recompute
               the derived UNION post-commit (own failure boundary, never throws —
               see song_media_flags.php's header). Tree-derived wiring guard:
               tests/php/test-editor2-metadata-1862.php scans every
               `INSERT INTO tblSongMedia` and asserts this hook is referenced. */
            songMediaRecomputeFlags($db, $songId);

            logActivity('song-media.upload', 'song', $songId, [
                'media_id' => $newId, 'kind' => $kind, 'backend' => $staged['backend'],
                'file_name' => $cleanName, 'mime' => $meta['mime'], 'size_bytes' => $size, 'sha256' => $staged['sha256'],
            ]);
            ed2_respond(['ok' => true, 'media' => ed2_mediaRowShape([
                'Id' => $newId, 'Kind' => $kind, 'FileName' => $cleanName, 'MimeType' => $meta['mime'],
                'SizeBytes' => $size, 'Annotation' => $annotation, 'SortOrder' => $nextOrder,
                'StorageBackend' => $staged['backend'], 'UploadedAt' => date('Y-m-d H:i:s'),
            ])]);
        } catch (\Throwable $e) {
            /* Unlink a staged FS file if the DB write never landed (no orphans). */
            if (is_array($staged) && ($staged['backend'] ?? '') === 'filesystem' && !empty($staged['path'])) {
                SongMediaStorage::deleteStorage(['StorageBackend' => 'filesystem', 'StoragePath' => $staged['path']]);
            }
            $userFacing = $e instanceof \RuntimeException || $e instanceof \InvalidArgumentException;
            error_log('[editor-v2-api media_upload] ' . $e->getMessage());
            ed2_respond([
                'ok'           => false,
                'error'        => $userFacing ? $e->getMessage() : 'Upload failed.',
                'error_detail' => $ed2IsAdmin ? ($e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine()) : null,
            ], $userFacing ? 400 : 500);
        }
        break;
    }

    /* ---- media_update (POST JSON) — only Annotation is mutable post-upload ---- */
    case 'media_update': {
        $mediaId    = (int)($body['mediaId'] ?? 0);
        $annotation = trim((string)($body['annotation'] ?? ''));
        if ($mediaId <= 0) { ed2_respond(['ok' => false, 'error' => 'mediaId is required.'], 400); }
        if (!ed2_songMediaTableExists($db)) { ed2_respond(['ok' => false, 'error' => 'Song Media migration has not been run.'], 503); }
        if ($annotation !== '') { $annotation = mb_substr($annotation, 0, 255); }
        $annotationOrNull = ($annotation !== '') ? $annotation : null;

        $sel = $db->prepare('SELECT SongId FROM tblSongMedia WHERE Id = ? LIMIT 1');
        $sel->bind_param('i', $mediaId);
        $sel->execute();
        $mrow = $sel->get_result()->fetch_assoc();
        $sel->close();
        if (!$mrow) { ed2_respond(['ok' => false, 'error' => 'Media not found.'], 404); }
        $mSongId = (string)$mrow['SongId'];

        $db->begin_transaction();
        try {
            $u = $db->prepare('UPDATE tblSongMedia SET Annotation = ? WHERE Id = ?');
            $u->bind_param('si', $annotationOrNull, $mediaId);
            $u->execute();
            $u->close();
            ed2_touchRevision($db, $mSongId, $ed2UserId, 'media');
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
        logActivity('song-media.update', 'song', $mSongId, ['mediaId' => $mediaId]);
        ed2_respond(['ok' => true, 'mediaId' => $mediaId]);
        break;
    }

    /* ---- media_set_visibility (POST JSON) — publish/unpublish one media row
           (#1968 P4, owner decision D1). Gates come free + are sufficient: the
           file-wide session + editor gate and the top-of-file X-Requested-With
           validateCsrfRequest() POST gate (rule #29) cover this. NO new
           entitlement is minted — a curator who can media_upload instantly-public
           media today gains no new exposure class by publishing imported media
           (rule #44's discipline applied to entitlements). 503 (status is the
           contract, rule #35) when the Visibility column is un-migrated. ---- */
    case 'media_set_visibility': {
        if ($method !== 'POST') { ed2_respond(['ok' => false, 'error' => 'POST required.'], 405); }
        $mediaId    = (int)($body['mediaId'] ?? 0);
        $visibility = trim((string)($body['visibility'] ?? ''));
        if ($mediaId <= 0) { ed2_respond(['ok' => false, 'error' => 'mediaId is required.'], 400); }
        if (!ed2_songMediaTableExists($db)) { ed2_respond(['ok' => false, 'error' => 'Song Media migration has not been run.'], 503); }
        if (!songMediaVisibilityColumnExists($db)) { ed2_respond(['ok' => false, 'error' => 'Media visibility migration has not been run.'], 503); }
        if (!songMediaVisibilityIsValid($visibility)) { ed2_respond(['ok' => false, 'error' => 'Invalid visibility value.'], 400); }

        $sel = $db->prepare('SELECT SongId FROM tblSongMedia WHERE Id = ? LIMIT 1');
        $sel->bind_param('i', $mediaId);
        $sel->execute();
        $mrow = $sel->get_result()->fetch_assoc();
        $sel->close();
        if (!$mrow) { ed2_respond(['ok' => false, 'error' => 'Media not found.'], 404); }
        $mSongId = (string)$mrow['SongId'];

        $db->begin_transaction();
        try {
            $u = $db->prepare('UPDATE tblSongMedia SET Visibility = ? WHERE Id = ?');
            $u->bind_param('si', $visibility, $mediaId);
            $u->execute();
            $u->close();
            ed2_touchRevision($db, $mSongId, $ed2UserId, 'media');
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
        logActivity('song-media.visibility', 'song', $mSongId, ['media_id' => $mediaId, 'visibility' => $visibility]);
        ed2_respond(['ok' => true, 'mediaId' => $mediaId, 'visibility' => $visibility]);
        break;
    }

    /* ---- media_delete (POST JSON) — removes the row + its underlying bytes ---- */
    case 'media_delete': {
        $mediaId = (int)($body['mediaId'] ?? 0);
        if ($mediaId <= 0) { ed2_respond(['ok' => false, 'error' => 'mediaId is required.'], 400); }
        if (!ed2_songMediaTableExists($db)) { ed2_respond(['ok' => false, 'error' => 'Song Media migration has not been run.'], 503); }

        $sel = $db->prepare('SELECT Id, SongId, Kind, StorageBackend, StoragePath, FileName FROM tblSongMedia WHERE Id = ? LIMIT 1');
        $sel->bind_param('i', $mediaId);
        $sel->execute();
        $mrow = $sel->get_result()->fetch_assoc();
        $sel->close();
        if (!$mrow) { ed2_respond(['ok' => false, 'error' => 'Media not found.'], 404); }
        $mSongId = (string)$mrow['SongId'];

        $db->begin_transaction();
        try {
            $d = $db->prepare('DELETE FROM tblSongMedia WHERE Id = ?');
            $d->bind_param('i', $mediaId);
            $d->execute();
            $deleted = $d->affected_rows;
            $d->close();
            ed2_touchRevision($db, $mSongId, $ed2UserId, 'media');
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
        /* Remove bytes only after the row is gone (orphan file recoverable;
           an orphan row after a "delete me" is worse). */
        SongMediaStorage::deleteStorage([
            'StorageBackend' => (string)$mrow['StorageBackend'],
            'StoragePath'    => (string)($mrow['StoragePath'] ?? ''),
        ]);
        /* #1862 — a removed media row can flip HasAudio/HasSheetMusic; recompute
           the derived UNION post-commit, same posture as the media_upload hook
           above (own failure boundary, never throws). Tree-derived wiring guard:
           tests/php/test-editor2-metadata-1862.php scans every
           `DELETE FROM tblSongMedia` and asserts this hook is referenced. */
        songMediaRecomputeFlags($db, $mSongId);
        logActivity('song-media.delete', 'song', $mSongId, [
            'mediaId' => $mediaId, 'kind' => (string)$mrow['Kind'], 'fileName' => (string)$mrow['FileName'],
        ]);
        ed2_respond(['ok' => true, 'deleted' => (int)$deleted, 'songId' => $mSongId]);
        break;
    }

    /* ---- media_reorder (POST JSON) — rewrite SortOrder for one (song, kind)
           group from the posted id order. The scoped WHERE (Id+SongId+Kind)
           prevents cross-song/cross-kind tampering. ---- */
    case 'media_reorder': {
        $songId = trim((string)($body['songId'] ?? ''));
        $kind   = trim((string)($body['kind'] ?? ''));
        $rawIds = is_array($body['ids'] ?? null) ? $body['ids'] : [];
        if ($songId === '' || !in_array($kind, SongMediaStorage::allKinds(), true)) {
            ed2_respond(['ok' => false, 'error' => 'Missing or invalid songId / kind.'], 400);
        }
        $orderedIds = [];
        foreach ($rawIds as $raw) {
            $id = (int)$raw;
            if ($id > 0 && !in_array($id, $orderedIds, true)) { $orderedIds[] = $id; }
        }
        if (empty($orderedIds)) { ed2_respond(['ok' => true, 'reordered' => 0]); }
        if (!ed2_songMediaTableExists($db)) { ed2_respond(['ok' => false, 'error' => 'Song Media migration has not been run.'], 503); }

        $db->begin_transaction();
        try {
            $u = $db->prepare('UPDATE tblSongMedia SET SortOrder = ? WHERE Id = ? AND SongId = ? AND Kind = ?');
            $written = 0;
            foreach ($orderedIds as $i => $id) {
                $u->bind_param('iiss', $i, $id, $songId, $kind);
                $u->execute();
                $written += $u->affected_rows;
            }
            $u->close();
            ed2_touchRevision($db, $songId, $ed2UserId, 'media');
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
        logActivity('song-media.reorder', 'song', $songId, ['kind' => $kind, 'count' => count($orderedIds)]);
        ed2_respond(['ok' => true, 'reordered' => $written]);
        break;
    }

    /* ---- components_replace (POST) — atomic bulk set of a song's components,
           for Paste & Reflow + single-song import (both produce a whole section
           set at once). mode 'replace' (default) wipes the existing components
           first; 'append' adds after the current max SortOrder. ONE transaction,
           ONE LyricsText rebuild, ONE revision + activity row — no N-request
           granular loop. Returns the persisted rows (with real ids) so the
           client re-hydrates the Structure tab. ---- */
    case 'components_replace': {
        $songId = trim((string)($body['songId'] ?? ''));
        $rows   = is_array($body['components'] ?? null) ? $body['components'] : [];
        $mode   = (($body['mode'] ?? 'replace') === 'append') ? 'append' : 'replace';
        if ($songId === '') { ed2_respond(['ok' => false, 'error' => 'songId is required.'], 400); }
        if (!ed2_songExists($db, $songId)) { ed2_respond(['ok' => false, 'error' => 'Song not found.'], 404); }

        $db->begin_transaction();
        try {
            /* #1235 P4/C5 — bulk replace/append through the shared drop-safe write path
               (lines authoritative + JSON shadow). PF1 / R1: on 'replace', a pasted
               component that OMITS chords/languages reclaims the position-matched
               original's (Type | Number | line-count, FIFO) — so Paste & Reflow / import
               (which rebuilds STRUCTURE, not enrichment) never silently drops it. Client-
               sent values always win. */
            $existing = ed2_currentComponents($db, $songId);
            /* #1860 Phase 5 §3.3 — Paste & Reflow REBUILDS structure; the pasted rows
               never carry `label`/`sourceWorkId` at all, so without a carry every
               reflow would silently wipe every custom label + work link on the song
               (layer 2 of §3's three-layer silent-wipe defence — this funnel's own
               FIFO carry, distinct from the generic handler/writer preserve layers,
               because it REPLACES the whole set rather than patching one row). */
            $carry = [];   // "type\x1fnumber\x1flineCount" => FIFO of ['c'=>chords array|null,'l'=>languages array|null,'lb'=>label|null,'sw'=>sourceWorkId|null]
            if ($mode === 'replace') {
                foreach ($existing as $pc) {
                    $ck = (string)$pc['type'] . "\x1f" . (string)(int)$pc['number'] . "\x1f" . count($pc['lines']);
                    $carry[$ck][] = ['c' => $pc['chords'], 'l' => $pc['languages'], 'lb' => $pc['label'] ?? null, 'sw' => $pc['sourceWorkId'] ?? null];
                }
            }

            /* Normalise the incoming components (editor shape), applying carry-forward. */
            $incoming = [];
            foreach ($rows as $comp) {
                if (!is_array($comp)) { continue; }
                $type   = mb_substr(trim((string)($comp['type'] ?? 'verse')), 0, 20) ?: 'verse';
                $number = max(0, (int)($comp['number'] ?? 0));
                $lines  = is_array($comp['lines'] ?? null) ? array_values(array_map('strval', $comp['lines'])) : [];
                $carried = null;
                if ($mode === 'replace') {
                    $ck = $type . "\x1f" . (string)$number . "\x1f" . count($lines);
                    if (!empty($carry[$ck])) { $carried = array_shift($carry[$ck]); }
                }
                $chords = (isset($comp['chords']) && is_array($comp['chords']))
                    ? array_values($comp['chords'])
                    : ($carried !== null ? $carried['c'] : null);
                $langs  = (isset($comp['languages']) && is_array($comp['languages']))
                    ? array_values($comp['languages'])
                    : ($carried !== null ? $carried['l'] : null);
                /* #1860 Phase 5 §3.3 — explicit-wins-else-carried, mirroring the
                   chords/languages shape immediately above (isset-based "was this
                   provided" test, not array_key_exists — this funnel rebuilds
                   STRUCTURE, so an incoming row without a real label/work-link value
                   simply carries the position-matched original forward). */
                $label = (isset($comp['label']) && trim((string)$comp['label']) !== '')
                    ? mb_substr(trim((string)$comp['label']), 0, 100)
                    : ($carried !== null ? $carried['lb'] : null);
                $sourceWorkId = (isset($comp['sourceWorkId']) && (int)$comp['sourceWorkId'] > 0)
                    ? (int)$comp['sourceWorkId']
                    : ($carried !== null ? $carried['sw'] : null);
                $incoming[] = [
                    'type'      => $type,
                    'number'    => $number,
                    'language'  => (isset($comp['language']) && trim((string)$comp['language']) !== '') ? trim((string)$comp['language']) : null,
                    'lines'     => $lines,
                    'chords'    => is_array($chords) ? $chords : null,
                    'languages' => is_array($langs) ? $langs : null,
                    'label'         => $label,
                    'sourceWorkId'  => $sourceWorkId,
                ];
            }
            $count = count($incoming);

            /* replace = the incoming set; append = existing rows then incoming. The
               shared writer upserts the existing rows Id-stably + inserts the rest. */
            $finalComps = ($mode === 'append') ? array_merge($existing, $incoming) : $incoming;
            ed2_persistComponents($db, $songId, $finalComps);
            ed2_touchRevision($db, $songId, $ed2UserId, 'components_replace');
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
        logActivity('song.components.replace', 'song', $songId, ['mode' => $mode, 'count' => $count]);

        /* Re-read the persisted set (real ids) — same editor shape load_song emits. */
        $out = ed2_currentComponents($db, $songId);
        ed2_respond(['ok' => true, 'count' => $count, 'components' => $out]);
        break;
    }

    /* ---- import_file (POST, MULTIPART) — single-file bulk import for the 7
           legacy single-file formats, routing the upload to the SHARED parser +
           universal saver (INSERT-only, skips existing). Auto-detects the format
           from the extension when format=auto. ZIP (multi-file/async) is a
           separate endpoint (4b.3). Returns the same summary shape the legacy
           bulk_import_* endpoints do. ---- */
    case 'import_file': {
        if ($method !== 'POST') { ed2_respond(['ok' => false, 'error' => 'POST required.'], 405); }
        $format = strtolower(trim((string)($_POST['format'] ?? 'auto')));
        $dedupe = ((string)($_POST['dedupeMode'] ?? 'off') === 'skip-title') ? 'skip-title' : 'off';
        /* #1674 — dry-run preview: '1' opts in, anything else (including
           absence) is a real import. Parsed beside dedupeMode so both
           per-request mode flags are set from the same place. */
        $dryRun = ((string)($_POST['dryRun'] ?? '0') === '1');

        if (!isset($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $err = $_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE;
            $msg = match ($err) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File exceeds the server upload size limit.',
                UPLOAD_ERR_NO_FILE                        => 'No file received — expected multipart with a "file" field.',
                UPLOAD_ERR_PARTIAL                        => 'Upload was interrupted.',
                default                                   => 'Upload failed.',
            };
            ed2_respond(['ok' => false, 'error' => $msg], 400);
        }

        /* Explicit size cap (defense-in-depth beyond php.ini upload_max_filesize),
           mirroring the legacy per-format caps — 25 MiB covers the largest (PPTX). */
        if ((int)($_FILES['file']['size'] ?? 0) > 25 * 1024 * 1024) {
            ed2_respond(['ok' => false, 'error' => 'File too large (max 25 MB for single-file import).'], 400);
        }
        $tmpPath  = (string)$_FILES['file']['tmp_name'];
        $origName = (string)($_FILES['file']['name'] ?? 'upload');
        $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

        /* Body-format uploads are read ONCE into $content. Pre-seeded to null so the
           `.json` content sniff below can fill it and the import re-use it rather
           than reading a 25 MB upload off disk twice (#1633). */
        $content = null;

        if ($format === 'auto' || $format === '') {
            $format = match ($ext) {
                'json'  => 'videopsalm',
                /* #882 — both .xml (OpenLyrics' native extension) and
                   .opensong resolve to the shared 'xmlauto' TARGET, which
                   routes through _bulkImport_processXmlAuto() below —
                   that function, not this map, is what actually tells
                   OpenLyrics and OpenSong apart (it content-sniffs via
                   _bulkImport_looksLikeOpenLyrics(), the same discriminator
                   the ZIP import loop uses for its own .xml entries). */
                'xml', 'opensong' => 'xmlauto',
                'pro6'  => 'pro6',
                'show'  => 'freeshow',
                'pptx'  => 'pptx',
                'db'    => 'easyworship',
                'txt'   => 'proclaim',
                'cho', 'chopro', 'crd', 'chord' => 'chordpro',   // #1264 ChordPro
                /* epic #1968 P0 — '.pro' is genuinely ambiguous (ChordPro's
                   own docs bless '.pro' too; ProPresenter 7+ also uses it
                   natively), so it resolves to the internal 'proauto' TARGET
                   below rather than straight to 'chordpro' — the same
                   #882/'xmlauto' + #1633/.json precedent this very function
                   already uses twice. Previously this line silently
                   mis-routed every real PP7 .pro upload to the ChordPro text
                   parser (plan §3.1's "the fix" bug report). */
                'pro'   => 'proauto',
                /* epic #1968 P2 — a `.probundle` is unambiguously ProPresenter's
                   own ZIP container (unlike bare `.pro`, it carries no
                   ChordPro/Pro6 ambiguity), so it maps straight to the
                   'probundle' body format below — no content sniff needed. */
                'probundle' => 'probundle',
                /* epic #1968 PR-3 — a `.proplaylist` is the SAME kind of ZIP
                   container as `.probundle` (unambiguous, no content sniff
                   needed) but decodes a DIFFERENT top-level message (a
                   service order, not a single presentation) and produces a
                   set list rather than (only) songs — see
                   _bulkImport_processProplaylist()'s own doc-block. */
                'proplaylist' => 'proplaylist',
                default => '',
            };

            /* ELI5: two different formats both use ".json", so peek inside to tell
               which one this is.
               #1633 — the extension map above cannot separate them: VideoPsalm
               claimed `.json` first, and the iHymns interchange format
               (tests/fixtures/songs.schema.json) uses the same extension. Sniffing
               the CONTENT is the established answer here — `'xml', 'opensong' =>
               'xmlauto'` above uses exactly the same content-sniff strategy, just
               via _bulkImport_processXmlAuto() downstream instead of inline.
               The sniff is CONSERVATIVE BY DESIGN and only ever REDIRECTS AWAY from
               videopsalm when the body is unambiguously ours (all three iHymns
               top-level keys present AND VideoPsalm's "Songs" key absent), so no
               existing VideoPsalm import can be re-routed by this. Anything
               ambiguous stays on the old path and the operator can still pick
               "iHymns interchange (.json)" explicitly from the format dropdown. */
            if ($format === 'videopsalm') {
                $probe = file_get_contents($tmpPath);
                if ($probe === false) { ed2_respond(['ok' => false, 'error' => 'Could not read the uploaded file.'], 500); }
                if (_bulkImport_looksLikeIHymnsJson($probe)) { $format = 'ihymns'; }
                $content = $probe;      // reused below — do not re-read the upload
            }

            /* epic #1968 P0/P1 (plan §3.1) — '.pro' resolves to the internal
               'proauto' target above; content-sniff it here via the ONE
               shared, AUTHORITATIVE sniff (_bulkImport_sniffProDialect() in
               includes/song_importers.php — the exact same function the ZIP
               importer's per-entry router defers to) to tell ProPresenter 7+
               (binary protobuf), a mis-extensioned ProPresenter 6 export
               (XML), and genuine ChordPro (plain text) apart. Same #882/
               #1633 "sniff resolves format=auto; an explicit pick bypasses
               it" precedent as the two content-sniffs immediately above. */
            if ($format === 'proauto') {
                $probe = file_get_contents($tmpPath);
                if ($probe === false) { ed2_respond(['ok' => false, 'error' => 'Could not read the uploaded file.'], 500); }
                $format  = _bulkImport_sniffProDialect($probe);
                $content = $probe;      // reused below — do not re-read the upload
            }
        }

        /* Configure the dedup mode for every _bulkImport_saveSong() this request makes. */
        _bulkImport_dedupeMode($dedupe);
        /* #1674 — set explicitly BOTH ways (true and false), never left to the
           function's default, so a stale flag from an earlier request in the
           same PHP process/worker can never leak into this one. */
        _bulkImport_dryRun($dryRun);

        /* #882 — 'xmlauto' is the internal auto-resolution target reached
           only via format=auto on a .xml/.opensong extension (never offered
           directly in the UI dropdown); 'opensong' is also explicitly
           pickable so an operator can override a sniff that guessed wrong
           (same #1633 precedent as the iHymns-vs-VideoPsalm JSON override). */
        $bodyFormats = ['videopsalm', 'ihymns', 'openlp', 'opensong', 'xmlauto', 'pro6', 'pro7', 'probundle', 'proplaylist', 'proclaim', 'freeshow', 'chordpro'];
        $summary = null;
        try {
            if (in_array($format, $bodyFormats, true)) {
                if ($content === null) { $content = file_get_contents($tmpPath); }
                if ($content === false) { throw new \RuntimeException('Could not read the uploaded file.'); }
                $summary = match ($format) {
                    'videopsalm' => _bulkImport_processVideoPsalm($content, $origName),
                    'ihymns'     => _bulkImport_processIHymnsJson($content, $origName),  // #1633
                    'openlp'     => _bulkImport_processOpenLp($content, $origName),
                    'opensong'   => _bulkImport_processOpenSong($content, $origName),    // #882
                    'xmlauto'    => _bulkImport_processXmlAuto($content, $origName),     // #882
                    'pro6'       => _bulkImport_processPro6($content, $origName),
                    'pro7'       => _bulkImport_processPro7($content, $origName, true),  // epic #1968 / #885 (+P4 bare-media warn)
                    'probundle'  => _bulkImport_processProbundle($content, $origName, ((int)($ed2UserId ?? 0)) > 0 ? (int)$ed2UserId : null),   // epic #1968 P2 + P4 media ingest (UploadedBy)
                    /* epic #1968 PR-3 — unlike every other arm above, this one
                       creates a SET LIST, not just song(s), so it needs the
                       resolved session user id as its owner. $ed2UserId is
                       resolved once at file scope (this endpoint's own
                       auth gate, above) — the ONE place this whole call
                       chain reads the session, per _bulkImport_
                       processProplaylist()'s own "no session access, by
                       design" doc-block note. Never null here in practice
                       (the file-scope guard already 403s an unauthenticated
                       request before this switch is reached), coerced
                       defensively rather than assumed. */
                    'proplaylist' => _bulkImport_processProplaylist((int)($ed2UserId ?? 0), $content, $origName),
                    'proclaim'   => _bulkImport_processProclaim($content, $origName),
                    'freeshow'   => _bulkImport_processFreeShow($content, $origName),
                    'chordpro'   => _bulkImport_processChordPro($content, $origName),   // #1264
                };
            } elseif ($format === 'pptx') {
                $summary = _bulkImport_processPptx($tmpPath, $origName);
            } elseif ($format === 'easyworship') {
                $summary = _bulkImport_processEasyWorship($tmpPath, $origName);
            } else {
                ed2_respond(['ok' => false, 'error' => 'Unknown or undetected format — choose one explicitly.'], 400);
            }
        } catch (\Throwable $e) {
            $userFacing = $e instanceof \RuntimeException || $e instanceof \InvalidArgumentException;
            error_log('[editor-v2-api import_file] ' . $e->getMessage());
            ed2_respond([
                'ok'           => false,
                'error'        => $userFacing ? $e->getMessage() : 'Import failed.',
                'error_detail' => $ed2IsAdmin ? ($e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine()) : null,
            ], $userFacing ? 400 : 500);
        }

        if (!is_array($summary)) { ed2_respond(['ok' => false, 'error' => 'Importer returned no result.'], 500); }

        /* Regenerate songbook-derived state after a successful import (mirrors the
           legacy bulk_import_* handlers). Best-effort + guarded.
           #1674 — skipped entirely under dry-run: nothing was created, so
           there is no songbook-derived state to regenerate; SongCount /
           other maintenance work would be a pure no-op at best and a
           misleading side-effecting call at worst. */
        if (!$dryRun && (int)($summary['songs_created'] ?? 0) > 0) {
            ed2_runSongbookMaintenance($db, 'import_file');
        }
        /* #882 — the auto-router (format 'xmlauto') stamps its own resolved
           'format' ('openlp' or 'opensong') into $summary; every other
           branch's $summary carries no 'format' key, so this falls back to
           the format that was actually used to parse. Same value feeds
           both the activity log and the response so they can't disagree. */
        $resolvedFormat = (string)($summary['format'] ?? $format);
        /* #1674 — the activity row STAYS even under dry-run (an audit trail
           entry about a preview is itself correct information), with
           'dryRun' in its detail so the log distinguishes a preview from a
           real import. */
        logActivity('song.import_file', 'import', $origName, [
            'format'  => $resolvedFormat,
            'created' => (int)($summary['songs_created'] ?? 0),
            'skipped' => (int)($summary['songs_skipped_existing'] ?? 0),
            'failed'  => (int)($summary['songs_failed'] ?? 0),
            'dryRun'  => $dryRun,
        ]);
        /* #1909 — ONE summary partner webhook per real import (never per-song —
           design §A.9). A dry-run preview creates nothing, so it emits nothing.
           abbr comes from the summary when the import targeted a single book;
           a multi-book import leaves it empty (still a valid "import finished"
           signal). Dormant no-op until enabled. */
        if (!$dryRun) {
            webhookEmit('songbook.import_completed', [
                'abbr'          => (string)($summary['songbook'] ?? $summary['abbr'] ?? ''),
                'songs_created' => (int)($summary['songs_created'] ?? 0),
                'songs_updated' => (int)($summary['songs_updated'] ?? 0),
                'songs_skipped' => (int)($summary['songs_skipped_existing'] ?? 0),
                'dry_run'       => false,
            ], ['source' => 'bulk_import', 'entity_id' => (string)($summary['songbook'] ?? $summary['abbr'] ?? '')]);
        }
        /* #1674 — a KEY, not prose (rule #35), so import2.php's renderSummary()
           can branch on it rather than parse a sentence. */
        $summary['dry_run'] = $dryRun;
        /* #882 — every single-file processor's contract is "ok=false ⇔ parse
           failed" (a save failure lands as ok=true + songs_failed>0
           instead); a parse failure is a genuine client error (bad/wrong
           file), so it belongs on 400, not 200 — CLAUDE.md rule #35: the
           HTTP status is the failure-kind contract, not the error prose.
           This endpoint previously always answered 200 regardless of
           $summary['ok'], which the #882 negative-path verification
           (auto-detect on a file that is neither dialect) surfaced. */
        $httpStatus = ($summary['ok'] ?? false) ? 200 : 400;
        ed2_respond(array_merge(['ok' => true, 'format' => $resolvedFormat], $summary), $httpStatus);
        break;
    }

    /* ---- import_zip (POST, MULTIPART) — async multi-song ZIP import. Mirrors
           the legacy bulk_import_zip orchestration but on the clean v2 surface:
           persist the upload, create a tblBulkImportJobs row, return {job_id,
           poll_url}, release the connection (fastcgi_finish_request), then run
           the SHARED _bulkImport_processZip worker. The persist dir + job table
           are SHARED with legacy. NB: the async success path does NOT use
           ed2_respond (which exit()s) — it echoes, flushes, then keeps working. ---- */
    case 'import_zip': {
        if ($method !== 'POST') { ed2_respond(['ok' => false, 'error' => 'POST required.'], 405); }
        /* #1911 — ZIP dry-run preview. Parsed the same way import_file parses
           it (#1674, beside dedupeMode below). #1674 originally REFUSED this
           flag outright: the async job's state lives on tblBulkImportJobs,
           which had no spare column to carry it across the worker's separate
           execution (post-fastcgi_finish_request) — a migration (rule #19),
           not something this endpoint could improvise. That column now
           exists (migrate-bulk-import-dryrun.php), so the refusal is
           column-existence-gated instead of unconditional: an un-migrated
           install still gets the honest 422 (rule #33 — never a silently
           ignored flag), a migrated one gets a working preview. */
        $dryRun = ((string)($_POST['dryRun'] ?? '0') === '1');
        if ($dryRun && !ed2_bulkJobsDryRunColumnExists($db)) {
            ed2_respond(['ok' => false, 'error' => 'Dry run is not yet supported for ZIP imports on this deployment — import a single file to preview instead, or ask an admin to run the pending migration (see #1911).'], 422);
        }
        /* #1911 — set explicitly BOTH ways (mirrors import_file's #1674
           placement in this same file), so a stale flag from an earlier
           request in the same PHP process/worker can never leak into this
           one. This one call is what gates every write below that never
           gets a job row to read DryRun back from — the EasyWorship inline
           branch and both "no job row yet" sync fallbacks — via the SAME
           static flag _bulkImport_saveSong() / _bulkImport_upsertSongbook()
           already consult. The async worker section (after
           fastcgi_finish_request()) re-derives the flag from the job row
           instead, inside _bulkImport_processZip() itself — see that
           function's own read, keyed off this request's persisted DryRun
           column rather than this in-process variable. */
        _bulkImport_dryRun($dryRun);
        _bulkImport_dedupeMode(((string)($_POST['dedupeMode'] ?? 'off') === 'skip-title') ? 'skip-title' : 'off');
        if (!class_exists('ZipArchive')) { ed2_respond(['ok' => false, 'error' => 'Server is missing the PHP zip extension.'], 500); }
        if (!isset($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $err = $_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE;
            $msg = match ($err) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Uploaded file is larger than the server limit.',
                UPLOAD_ERR_NO_FILE                        => 'No file received — expected a multipart upload with a "file" field.',
                default                                   => 'Upload failed.',
            };
            ed2_respond(['ok' => false, 'error' => $msg, 'phpError' => $err], 400);
        }
        $sizeBytes = (int)($_FILES['file']['size'] ?? 0);
        if ($sizeBytes > 100 * 1024 * 1024) { ed2_respond(['ok' => false, 'error' => 'Uploaded zip exceeds the 100 MB import limit.'], 413); }
        $tmpPath  = (string)$_FILES['file']['tmp_name'];
        $origName = (string)($_FILES['file']['name'] ?? 'upload.zip');

        /* EasyWorship export zips carry a Songs.db (not the song-file layout) —
           detect + hand to the EW reader synchronously (mirror legacy). */
        try {
            $ewProbe = new \ZipArchive();
            if ($ewProbe->open($tmpPath) === true) {
                $hasSongsDb = false;
                for ($zi = 0; $zi < $ewProbe->numFiles; $zi++) {
                    if (strtolower(basename((string)$ewProbe->getNameIndex($zi))) === 'songs.db') { $hasSongsDb = true; break; }
                }
                $ewProbe->close();
                if ($hasSongsDb) {
                    $summary = _bulkImport_processEasyWorship($tmpPath, $origName);
                    /* #1911 — this branch bypasses the async worker entirely
                       (the "synchronous EasyWorship-zip fallback" the plan's
                       risk register calls out), so it gates its own
                       maintenance write on the SAME $dryRun the top-of-case
                       _bulkImport_dryRun() call already primed
                       _bulkImport_saveSong() with. */
                    if (!$dryRun && (int)($summary['songs_created'] ?? 0) > 0) { ed2_runSongbookMaintenance($db, 'import_zip_easyworship'); }
                    logActivity('song.import_zip', 'import', $origName, ['mode' => 'easyworship', 'created' => (int)($summary['songs_created'] ?? 0), 'dryRun' => $dryRun]);
                    /* #1909 — ONE summary partner webhook for this sync zip path
                       (never per-song; design §A.9). Dormant no-op until enabled. */
                    if (!$dryRun) {
                        webhookEmit('songbook.import_completed', [
                            'abbr'          => (string)($summary['songbook'] ?? $summary['abbr'] ?? ''),
                            'songs_created' => (int)($summary['songs_created'] ?? 0),
                            'songs_updated' => (int)($summary['songs_updated'] ?? 0),
                            'songs_skipped' => (int)($summary['songs_skipped_existing'] ?? 0),
                            'dry_run'       => false,
                        ], ['source' => 'bulk_import', 'entity_id' => (string)($summary['songbook'] ?? $summary['abbr'] ?? '')]);
                    }
                    /* #1911 — a KEY, not prose (rule #35): import2.php's
                       renderSummary() branches on this for the sync-render
                       path importZip() falls back to when `data.async` is
                       absent. */
                    $summary['dry_run'] = $dryRun;
                    ed2_respond(array_merge(['ok' => (bool)($summary['ok'] ?? false)], $summary), ($summary['ok'] ?? false) ? 200 : 400);
                }
            }
        } catch (\Throwable $ewE) {
            error_log('[editor-v2-api import_zip] EasyWorship probe failed: ' . $ewE->getMessage());
        }

        /* Synchronous fallback when async job tracking isn't available. */
        if (!ed2_bulkJobsTableExists($db)) {
            try {
                $summary = _bulkImport_processZip($tmpPath);
                /* #1911 — no job row exists on this branch (the table itself
                   is absent, so no DryRun column could exist for it either),
                   so _bulkImport_processZip() has nothing to read a flag
                   back from; the top-of-case _bulkImport_dryRun($dryRun)
                   call already primed it for this request, and $dryRun
                   gates the maintenance write the same way as every other
                   branch in this case. */
                if (!$dryRun && (int)($summary['songs_created'] ?? 0) > 0) { ed2_runSongbookMaintenance($db, 'import_zip.sync'); }
                logActivity('song.import_zip', 'import', $origName, ['mode' => 'sync', 'created' => (int)($summary['songs_created'] ?? 0), 'dryRun' => $dryRun]);
                $summary['dry_run'] = $dryRun;
                ed2_respond(array_merge(['ok' => true], $summary));
            } catch (\Throwable $e) {
                error_log('[editor-v2-api import_zip sync] ' . $e->getMessage());
                ed2_respond(['ok' => false, 'error' => 'Import failed.', 'error_detail' => $ed2IsAdmin ? $e->getMessage() : null], 500);
            }
        }

        /* Persist the upload outside the docroot so it survives the request close
           (same dir + job table the legacy importer uses). */
        $persistDir = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '.bulk_import_uploads';
        if (!is_dir($persistDir)) { @mkdir($persistDir, 0700, true); }
        $persistPath = $persistDir . DIRECTORY_SEPARATOR . 'job-' . bin2hex(random_bytes(8)) . '-' . preg_replace('/[^A-Za-z0-9._-]/', '_', $origName);
        if (!@move_uploaded_file($tmpPath, $persistPath)) {
            /* move failed → sync fallback so the import still succeeds. No
               job row exists yet at this point either (#1911 — same
               reasoning as the table-absent branch above), so the
               top-of-case $dryRun still gates this. */
            try {
                $summary = _bulkImport_processZip($tmpPath);
                if (!$dryRun && (int)($summary['songs_created'] ?? 0) > 0) { ed2_runSongbookMaintenance($db, 'import_zip.sync_fallback'); }
                $summary['dry_run'] = $dryRun;
                ed2_respond(array_merge(['ok' => true], $summary));
            } catch (\Throwable $e) {
                error_log('[editor-v2-api import_zip move-fallback] ' . $e->getMessage());
                ed2_respond(['ok' => false, 'error' => 'Import failed.'], 500);
            }
        }
        @chmod($persistPath, 0600);

        /* Create the queued job row. Bind a guaranteed-int UserId (the auth gate
           guarantees a logged-in editor; the ?? 0 keeps the stored UserId
           consistent with the `?? 0` ownership filter in import_zip_status /
           import_zip_skipped_csv, so a row is never un-pollable on a NULL≠0 mismatch).
           #1911 — DryRun is written column-existence-gated: reaching this
           INSERT with $dryRun===true already implies the column exists (the
           gate at the top of this case would have 422'd otherwise), but the
           INSERT itself must still branch so a real (non-dry-run) import on
           an UN-migrated install keeps working exactly as before. */
        $insUid       = (int)($ed2UserId ?? 0);
        $hasDryRunCol = ed2_bulkJobsDryRunColumnExists($db);
        try {
            if ($hasDryRunCol) {
                $dryRunInt = $dryRun ? 1 : 0;
                $j = $db->prepare('INSERT INTO tblBulkImportJobs (UserId, Filename, TempPath, SizeBytes, Status, DryRun) VALUES (?, ?, ?, ?, "queued", ?)');
                $j->bind_param('issii', $insUid, $origName, $persistPath, $sizeBytes, $dryRunInt);
            } else {
                $j = $db->prepare('INSERT INTO tblBulkImportJobs (UserId, Filename, TempPath, SizeBytes, Status) VALUES (?, ?, ?, ?, "queued")');
                $j->bind_param('issi', $insUid, $origName, $persistPath, $sizeBytes);
            }
            $j->execute();
            $jobId = (int)$db->insert_id;
            $j->close();
        } catch (\Throwable $e) {
            @unlink($persistPath);
            error_log('[editor-v2-api import_zip] could not create job row: ' . $e->getMessage());
            ed2_respond(['ok' => false, 'error' => 'Could not start import job.'], 500);
        }

        /* Hand the browser its tracking handle, then release the connection.
           NOT ed2_respond — the worker must run AFTER the response is sent. */
        http_response_code(200);
        echo json_encode([
            'ok'       => true,
            'async'    => true,
            'job_id'   => $jobId,
            /* #1855: extensionless — this value becomes the browser's
               actual GET poll target (bulk-import-progress.js / import2.php
               read it as `data.poll_url`). A literal .php URL here is 301'd
               by .htaccess; GET survives a 301 so this was only a wasted
               redirect hop, not a data-loss bug, but the sibling v1 handler
               (api.php's bulk_import_status poll_url) already emits the
               extensionless form — this brings api2.php in line with it. */
            'status'   => 'queued',
            'poll_url' => '/manage/editor/api2?action=import_zip_status&job_id=' . $jobId,
        ], JSON_UNESCAPED_UNICODE);
        @session_write_close();
        @ignore_user_abort(true);
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        } else {
            if (ob_get_level() > 0) { @ob_end_flush(); }
            @flush();
        }

        /* Worker — runs after the HTTP connection is freed. Crash → job 'failed'. */
        try {
            $db = getDbMysqli();
            _bulkImport_jobMark($db, $jobId, 'running', ['StartedAt' => 'NOW()']);
            /* #1911 — _bulkImport_processZip() reads DryRun off THIS job row
               itself (not off the $dryRun PHP variable above) before its
               per-file loop starts, so the flag its writes obey is the
               persisted column, not request-local state — see that
               function's own doc-block. Read it back via the getter (no
               args) rather than trusting the local $dryRun, so the gates
               below reflect what the worker actually resolved. */
            $summary      = _bulkImport_processZip($persistPath, $db, $jobId);
            $jobWasDryRun = _bulkImport_dryRun();
            _bulkImport_jobMark($db, $jobId, 'completed', [
                'CompletedAt'           => 'NOW()',
                'SongbooksCreatedJson'  => json_encode($summary['songbooks_created']  ?? [], JSON_UNESCAPED_UNICODE),
                'SongbooksExistingJson' => json_encode($summary['songbooks_existing'] ?? [], JSON_UNESCAPED_UNICODE),
                'SongsCreated'          => (int)($summary['songs_created'] ?? 0),
                'SongsSkippedExisting'  => (int)($summary['songs_skipped_existing'] ?? 0),
                'SongsFailed'           => (int)($summary['songs_failed'] ?? 0),
                'ErrorsJson'            => json_encode($summary['errors'] ?? [], JSON_UNESCAPED_UNICODE),
                'SkippedSongIdsJson'    => json_encode($summary['skipped_song_ids'] ?? [], JSON_UNESCAPED_UNICODE),
                'PerSongbookJson'       => json_encode($summary['per_songbook'] ?? [], JSON_UNESCAPED_UNICODE),
                'PhaseLabel'            => 'completed',
                'TempPath'              => '',
            ]);
            /* #1911 — TempPath cleanup is NEVER gated by dry-run: a preview
               run that leaked its upload into .bulk_import_uploads/ forever
               would be a disk-fill regression nobody would think to check
               for, precisely because dry-run promises to leave no trace. */
            @unlink($persistPath);
            /* #1911 — under dry-run, skip maintenance AND the completion
               notification: both are finalisation writes beyond the job
               row's own counters/status, and a dry run promises to write
               nothing beyond that row (mirrors import_file's #1674 gate on
               ed2_runSongbookMaintenance). logActivity() below stays
               UNCONDITIONAL, same as import_file — an audit-trail entry
               recording that a preview ran is itself correct information,
               tagged via the 'dryRun' detail key rather than suppressed. */
            if (!$jobWasDryRun && (int)($summary['songs_created'] ?? 0) > 0) { ed2_runSongbookMaintenance($db, 'import_zip.async'); }

            /* Best-effort completion notification so the curator finds the result later.
               #1638 — was a hand-rolled INSERT INTO tblNotifications, one of
               three near-identical copies. Now the shared notifyUser() helper,
               which owns the best-effort try/catch, the column-width clipping
               and the #1238 Environment/ExpiresAt migration gate that this copy
               never had. */
            if (!$jobWasDryRun && $ed2UserId !== null) {
                $c = (int)($summary['songs_created'] ?? 0); $s = (int)($summary['songs_skipped_existing'] ?? 0); $fl = (int)($summary['songs_failed'] ?? 0);
                notifyUser(
                    $db,
                    $ed2UserId,
                    'bulk_import_complete',
                    "Import finished: {$c} new, {$s} skipped" . ($fl > 0 ? ", {$fl} failed" : ''),
                    'Bulk import of "' . $origName . '" completed.',
                    '/manage/editor/'
                );
            }
            logActivity('song.import_zip', 'import', $origName, [
                'job_id'  => $jobId, 'mode' => 'async',
                'created' => (int)($summary['songs_created'] ?? 0),
                'skipped' => (int)($summary['songs_skipped_existing'] ?? 0),
                'failed'  => (int)($summary['songs_failed'] ?? 0),
                'dryRun'  => $jobWasDryRun,
            ]);
        } catch (\Throwable $e) {
            error_log('[editor-v2-api import_zip worker] ' . $e->getMessage());
            try {
                $db = getDbMysqli();
                _bulkImport_jobMark($db, $jobId, 'failed', [
                    'ErrorsJson'  => json_encode([['entry' => '(worker)', 'error' => $e->getMessage()]], JSON_UNESCAPED_UNICODE),
                    'CompletedAt' => 'NOW()',
                    'PhaseLabel'  => 'failed',
                    'TempPath'    => '',
                ]);
                @unlink($persistPath);
            } catch (\Throwable $_e) { /* give up */ }
        }
        exit;   // worker finished; connection already released
    }

    /* ---- import_zip_status (GET) — poll an async import job (own jobs only) ---- */
    case 'import_zip_status': {
        $jobId = (int)($_GET['job_id'] ?? 0);
        if ($jobId <= 0) { ed2_respond(['ok' => false, 'error' => 'job_id required.'], 400); }
        if (!ed2_bulkJobsTableExists($db)) { ed2_respond(['ok' => false, 'error' => 'Bulk-import job tracking is not enabled on this deployment.', 'migration_needed' => true], 404); }

        $uid = $ed2UserId ?? 0;
        /* #1911 — DryRun is column-existence-gated in the SELECT list itself:
           an un-migrated install's tblBulkImportJobs has no such column, and
           naming it unconditionally would throw under STRICT (unknown
           column) on every single poll tick until the migration runs. */
        $hasDryRunCol = ed2_bulkJobsDryRunColumnExists($db);
        $s = $db->prepare(
            'SELECT Id, UserId, Filename, SizeBytes, Status, TotalEntries, ProcessedEntries,
                    SongbooksCreatedJson, SongbooksExistingJson, SongsCreated, SongsSkippedExisting,
                    SongsFailed, ErrorsJson, PerSongbookJson, PhaseLabel, StartedAt, CompletedAt, CreatedAt, UpdatedAt'
                    . ($hasDryRunCol ? ', DryRun' : '') . '
               FROM tblBulkImportJobs WHERE Id = ? AND UserId = ? LIMIT 1'
        );
        $s->bind_param('ii', $jobId, $uid);
        $s->execute();
        $row = $s->get_result()->fetch_assoc();
        $s->close();
        if (!$row) { ed2_respond(['ok' => false, 'error' => 'Job not found.'], 404); }

        $decode = static fn($x) => $x === null ? null : json_decode($x, true);
        $total = (int)$row['TotalEntries']; $processed = (int)$row['ProcessedEntries'];
        ed2_respond(['ok' => true, 'job' => [
            'id'                     => (int)$row['Id'],
            'status'                 => (string)$row['Status'],
            'filename'               => (string)$row['Filename'],
            'size_bytes'             => (int)$row['SizeBytes'],
            'total_entries'          => $total,
            'processed_entries'      => $processed,
            'percent'                => $total > 0 ? round(($processed / $total) * 100, 1) : 0,
            'songs_created'          => (int)$row['SongsCreated'],
            'songs_skipped_existing' => (int)$row['SongsSkippedExisting'],
            'songs_failed'           => (int)$row['SongsFailed'],
            'songbooks_created'      => $decode($row['SongbooksCreatedJson'])  ?? [],
            'songbooks_existing'     => $decode($row['SongbooksExistingJson']) ?? [],
            'errors'                 => $decode($row['ErrorsJson'])            ?? [],
            'per_songbook'           => $decode($row['PerSongbookJson'])       ?? null,
            'skip_reason'            => 'existing-in-db',
            /* #1911 — a KEY, not prose (rule #35): bulk-import-progress.js's
               render() branches on this to show the same dry-run banner
               import2.php's renderSummary() shows for the single-file /
               sync-fallback paths. Always false on an un-migrated install —
               the column-existence gate on import_zip itself means no job on
               such a deployment could ever have been created with
               DryRun=1 in the first place. */
            'dry_run'                => $hasDryRunCol && (int)($row['DryRun'] ?? 0) === 1,
            /* #1855: extensionless — this becomes an <a href> the browser
               navigates to as a plain GET download. Survives a 301 either way,
               but the sibling v1 handler (api.php's bulk_import_skipped_csv
               skipped_csv_url) already emits the extensionless form; this
               brings api2.php in line with it and drops the wasted hop. */
            'skipped_csv_url'        => (int)$row['SongsSkippedExisting'] > 0
                ? '/manage/editor/api2?action=import_zip_skipped_csv&job_id=' . (int)$row['Id'] : '',
            'phase_label'            => $row['PhaseLabel'] ?? null,
            'started_at'             => $row['StartedAt'],
            'completed_at'           => $row['CompletedAt'],
            'created_at'             => $row['CreatedAt'],
            'updated_at'             => $row['UpdatedAt'],
        ]]);
        break;
    }

    /* ---- import_zip_skipped_csv (GET) — CSV of the SongIds an async job skipped
           (already existed). Own jobs only. Streams CSV, not JSON. ---- */
    case 'import_zip_skipped_csv': {
        /* @disabled-visible: admin editor API (#1765) — CSV of skipped-import rows
           spans all songs/songbooks regardless of public disabled state. */
        $jobId = (int)($_GET['job_id'] ?? 0);
        if ($jobId <= 0) { ed2_respond(['ok' => false, 'error' => 'job_id required.'], 400); }
        if (!ed2_bulkJobsTableExists($db)) { ed2_respond(['ok' => false, 'error' => 'Job tracking not enabled.', 'migration_needed' => true], 404); }

        $uid = $ed2UserId ?? 0;
        $s = $db->prepare('SELECT Filename, Status, SkippedSongIdsJson FROM tblBulkImportJobs WHERE Id = ? AND UserId = ? LIMIT 1');
        $s->bind_param('ii', $jobId, $uid);
        $s->execute();
        $row = $s->get_result()->fetch_assoc();
        $s->close();
        if (!$row) { ed2_respond(['ok' => false, 'error' => 'Job not found.'], 404); }
        if ((string)$row['Status'] !== 'completed') { ed2_respond(['ok' => false, 'error' => 'Job is not yet completed.'], 409); }
        $skipped = json_decode((string)($row['SkippedSongIdsJson'] ?? '[]'), true);
        if (!is_array($skipped) || !$skipped) { ed2_respond(['ok' => false, 'error' => 'No skipped SongIds recorded for this job.'], 404); }

        $ph    = implode(',', array_fill(0, count($skipped), '?'));
        $types = str_repeat('s', count($skipped));
        /* @deleted-visible: audit CSV (#1694) — a skip happened because the
           row existed at import time; title resolution must not depend on
           later visibility. */
        $look = $db->prepare(
            "SELECT s.SongId, s.Title, s.SongbookAbbr, sb.Name AS SongbookName
               FROM tblSongs s LEFT JOIN tblSongbooks sb ON sb.Abbreviation = s.SongbookAbbr
              WHERE s.SongId IN ({$ph})"
        );
        $look->bind_param($types, ...$skipped);
        $look->execute();
        $byId = [];
        $lr = $look->get_result();
        while ($r = $lr->fetch_assoc()) { $byId[(string)$r['SongId']] = $r; }
        $look->close();

        $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '_', 'skipped-songids-job-' . $jobId . '-' . pathinfo((string)$row['Filename'], PATHINFO_FILENAME) . '.csv') ?? 'skipped-songids.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $safeName . '"');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        /* #1908 Commit 4 — shared emitter replaces the old inline
           echo BOM + fopen() pair (never both — see the double-BOM
           ban in test-csv-bom.php). */
        $out = ihymns_csv_output_begin();
        ihymns_fputcsv($out, ['SongId', 'Title', 'SongbookAbbr', 'SongbookName', 'Reason']);
        foreach ($skipped as $sid) {
            $sid = (string)$sid; $r = $byId[$sid] ?? null;
            ihymns_fputcsv($out, [$sid, $r ? (string)$r['Title'] : '', $r ? (string)$r['SongbookAbbr'] : '', $r ? (string)$r['SongbookName'] : '', 'existing-in-db']);
        }
        fclose($out);
        exit;   // CSV already streamed — don't fall through to JSON
    }

    /* ---- bulk_verify (POST) — set/clear the Verified flag on many songs in one
           transaction. Activity-logged once (no per-song revision: a bulk flag
           flip is audited via the log, not the per-song content history). ---- */
    case 'bulk_verify': {
        ed2_requireEntitlement('bulk_edit_songs');
        $rawIds = is_array($body['songIds'] ?? null) ? $body['songIds'] : [];
        $ids = [];
        foreach ($rawIds as $x) { $s = trim((string)$x); if ($s !== '') { $ids[] = $s; } }
        if (!$ids) { ed2_respond(['ok' => false, 'error' => 'songIds are required.'], 400); }
        $val = isset($body['verified']) ? (int)((bool)$body['verified']) : 1;
        $db->begin_transaction();
        try {
            $u = $db->prepare('UPDATE tblSongs SET Verified = ? WHERE SongId = ?');
            foreach ($ids as $sid) { $u->bind_param('is', $val, $sid); $u->execute(); }
            $u->close();
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
        logActivity('song.bulk_verify', 'song', '', ['count' => count($ids), 'verified' => $val]);
        ed2_respond(['ok' => true, 'count' => count($ids), 'verified' => $val]);
        break;
    }

    /* ---- bulk_tag_attach (POST) — attach one tag (auto-created) to many songs in
           one transaction. INSERT IGNORE skips already-tagged or missing songs. ---- */
    case 'bulk_tag_attach': {
        ed2_requireEntitlement('bulk_edit_songs');
        $rawIds = is_array($body['songIds'] ?? null) ? $body['songIds'] : [];
        $ids = [];
        foreach ($rawIds as $x) { $s = trim((string)$x); if ($s !== '') { $ids[] = $s; } }
        $name = ed2_normalizeTag((string)($body['name'] ?? ''));
        if (!$ids || $name === '') { ed2_respond(['ok' => false, 'error' => 'songIds + a tag name are required.'], 400); }
        $slug = ed2_tagSlug($name);
        if ($slug === '') { ed2_respond(['ok' => false, 'error' => 'Tag name has no usable characters.'], 400); }

        $db->begin_transaction();
        try {
            $ins = $db->prepare('INSERT INTO tblSongTags (Name, Slug) VALUES (?, ?) ON DUPLICATE KEY UPDATE Id = LAST_INSERT_ID(Id), Name = ?');
            $ins->bind_param('sss', $name, $slug, $name);
            $ins->execute();
            $tagId = (int)$db->insert_id;
            $ins->close();

            $map = $db->prepare('INSERT IGNORE INTO tblSongTagMap (SongId, TagId, TaggedBy) VALUES (?, ?, ?)');
            $attached = 0;
            foreach ($ids as $sid) {
                $map->bind_param('sii', $sid, $tagId, $ed2UserId);
                $map->execute();
                if ($db->affected_rows > 0) { $attached++; }
            }
            $map->close();
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
        logActivity('song.bulk_tag', 'song', '', ['tag' => $name, 'count' => count($ids), 'attached' => $attached]);
        ed2_respond(['ok' => true, 'tag' => ['id' => $tagId, 'name' => $name, 'slug' => $slug], 'attached' => $attached, 'count' => count($ids)]);
        break;
    }

    /* ---- bulk_tag_detach (POST) — remove one tag from many songs (#1628 item 3).
     *
     * ELI5: the opposite of bulk_tag_attach — takes a tag OFF a set of songs.
     *
     * WHY: v1's single `bulk_tag` action (#399) took BOTH `add:[]` and
     * `remove:[]`. v2 shipped only `bulk_tag_attach`, so the capability v2
     * appeared to have was narrower than it looked — a curator who bulk-tagged
     * 200 songs with the wrong tag had no way to undo it except one song at a
     * time. That asymmetry is invisible from the v2 UI, which simply has no
     * Remove button; it only bites after the mistake.
     *
     * Resolves the tag by SLUG, not by Id, so the client passes the same
     * human-typed name it passes to attach, normalised the same way. An unknown
     * tag is NOT an error — detaching a tag nothing carries is a successful
     * no-op, and 404-ing there would make "remove this tag everywhere" fail
     * whenever it had already succeeded.
     *
     * Deliberately does NOT delete the tag row itself when the last song loses
     * it. Tags are a curated vocabulary (#1152 seeds the CCLI theme taxonomy
     * into the same table, with hierarchy via ParentId); garbage-collecting a
     * standard theme because it briefly had no songs would silently prune the
     * vocabulary and orphan its children. ---- */
    case 'bulk_tag_detach': {
        ed2_requireEntitlement('bulk_edit_songs');
        $rawIds = is_array($body['songIds'] ?? null) ? $body['songIds'] : [];
        $ids = [];
        foreach ($rawIds as $x) { $s = trim((string)$x); if ($s !== '') { $ids[] = $s; } }
        $name = ed2_normalizeTag((string)($body['name'] ?? ''));
        if (!$ids || $name === '') { ed2_respond(['ok' => false, 'error' => 'songIds + a tag name are required.'], 400); }
        $slug = ed2_tagSlug($name);
        if ($slug === '') { ed2_respond(['ok' => false, 'error' => 'Tag name has no usable characters.'], 400); }

        $sel = $db->prepare('SELECT Id FROM tblSongTags WHERE Slug = ? LIMIT 1');
        $sel->bind_param('s', $slug);
        $sel->execute();
        $row = $sel->get_result()->fetch_row();
        $sel->close();
        if ($row === null) {
            /* Nothing carries this tag — a successful no-op, not a failure. */
            ed2_respond(['ok' => true, 'tag' => ['id' => null, 'name' => $name, 'slug' => $slug], 'detached' => 0, 'count' => count($ids)]);
        }
        $tagId = (int)$row[0];

        $db->begin_transaction();
        try {
            $del = $db->prepare('DELETE FROM tblSongTagMap WHERE SongId = ? AND TagId = ?');
            $detached = 0;
            foreach ($ids as $sid) {
                $del->bind_param('si', $sid, $tagId);
                $del->execute();
                if ($db->affected_rows > 0) { $detached++; }
            }
            $del->close();
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
        logActivity('song.bulk_tag_detach', 'song', '', ['tag' => $name, 'count' => count($ids), 'detached' => $detached]);
        ed2_respond(['ok' => true, 'tag' => ['id' => $tagId, 'name' => $name, 'slug' => $slug], 'detached' => $detached, 'count' => count($ids)]);
        break;
    }

    /* ---- bulk_move (POST) — move many songs to a different songbook in one
           request (#1628 item 3). Each song gets its OWN transaction through
           the SAME songRelocate() core the single-song
           `metadata_field_update` (field=songbook) branch above uses — #1679
           is RESOLVED option B, so a move re-keys the SongId; there is no
           other legal way to change a song's book (rule #27, enforced by
           tests/php/test-song-relocate-funnels.php — never write
           `SongbookAbbr` directly here).
           PER-SONG VERDICTS, never all-or-nothing (#1690 A7): one row that
           refuses (a stale FK, an already-relocated concurrent edit) must
           not abort the rest of a 300-song batch. The two-tier catch below
           is a literal mirror of the single-song block's (:2480-2492):
           \InvalidArgumentException is ordinary bad input (a per-song 422
           verdict, loop continues); \Throwable is a real server fault
           (rolled back, recorded, loop continues — never rethrown, or one
           bad row would 500 the whole batch).
           `targetAbbr` is validated ONCE up front (A3): a typo'd/unknown
           book must refuse the WHOLE request with one clear 422, not 300
           identical per-song failures — reusing songRelocate()'s own
           existence check rather than a second, possibly-divergent one.
           Response: `{ok, moved:[{oldId,newId}], failed:[{id,error,status}]}`
           — the NEW ids are the point: option B means every selected id is
           stale the instant this returns, so the client MUST re-key its
           selection from `moved`, never assume the old ids still resolve
           (they only resolve via the redirect layer). ---- */
    case 'bulk_move': {
        ed2_requireEntitlement('bulk_edit_songs');
        $rawIds = is_array($body['songIds'] ?? null) ? $body['songIds'] : [];
        $ids = [];
        foreach ($rawIds as $x) { $s = trim((string)$x); if ($s !== '') { $ids[] = $s; } }
        if (!$ids) { ed2_respond(['ok' => false, 'error' => 'songIds are required.'], 400); }
        if (count($ids) > 300) {
            ed2_respond(['ok' => false, 'error' => 'Too many songs selected (max 300 per bulk move).'], 400);
        }
        $targetAbbr = trim((string)($body['targetAbbr'] ?? ''));
        if ($targetAbbr === '') {
            ed2_respond(['ok' => false, 'error' => 'A target songbook abbreviation is required.'], 400);
        }

        /* A3 — probe the destination ONCE, before the per-song loop. Mirrors
           songRelocate()'s own step 2 existence check (song_relocate.php) —
           a bad book name is a request-level mistake, not a per-song one.
           @disabled-visible: admin write-path (#1765) — the SAME reasoning
           song_relocate.php's own identical check carries: a curator moving
           songs must be able to target ANY songbook, including one that is
           currently disabled (e.g. relocating songs OUT of a disabled book,
           or into the hidden staging book) — this pre-check is not a public
           listing, it exists only to name a bad targetAbbr precisely. */
        $bk = $db->prepare('SELECT 1 FROM tblSongbooks WHERE Abbreviation = ? LIMIT 1');
        $bk->bind_param('s', $targetAbbr);
        $bk->execute();
        $bkFound = $bk->get_result()->fetch_row() !== null;
        $bk->close();
        if (!$bkFound) {
            ed2_respond(['ok' => false, 'error' => 'Unknown target songbook "' . $targetAbbr . '".'], 422);
        }

        require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'song_relocate.php';
        $moved  = [];
        $failed = [];
        foreach ($ids as $songId) {
            $db->begin_transaction();
            try {
                $rel = songRelocate($db, $songId, $targetAbbr, $ed2UserId);
                ed2_touchRevision($db, $rel['songId'], $ed2UserId, 'metadata');
                $db->commit();
                $moved[] = ['oldId' => $songId, 'newId' => $rel['songId']];
            } catch (\InvalidArgumentException $e) {
                $db->rollback();
                $failed[] = ['id' => $songId, 'error' => $e->getMessage(), 'status' => 422];
            } catch (\Throwable $e) {
                $db->rollback();
                $failed[] = ['id' => $songId, 'error' => $e->getMessage(), 'status' => 500];
            }
        }

        logActivity('song.bulk_move', 'song', '', [
            'targetAbbr' => $targetAbbr,
            'count'      => count($ids),
            'moved'      => count($moved),
            'failed'     => count($failed),
        ]);
        ed2_respond(['ok' => true, 'moved' => $moved, 'failed' => $failed]);
        break;
    }

    /* ---- bulk_delete (POST) — soft-delete many songs in one request (#1628
           item 3). Each song goes through the SAME songSoftDelete() core the
           single-song `delete_song` case above uses (#1694) — a bulk delete
           writes NOTHING via a raw `DELETE FROM tblSongs`; that would be the
           hard-delete cascade songPurge() alone is allowed to run, and only
           from the deleted state.
           Entitlement is `delete_songs`, not `bulk_edit_songs`: a bulk
           delete is N repetitions of the SAME destructive act
           `delete_song` already gates on `delete_songs`, so the single-song
           entitlement governs — an operator who can delete one song can
           delete many, and revoking `delete_songs` must revoke both.
           PER-SONG VERDICTS: songSoftDelete() already returns a pure verdict
           per call (409 un-migrated/already-deleted, 422 unknown reason,
           404 absent, 200 ok) — this loop simply collects them, same
           posture as bulk_move above. Soft delete writes no redirects
           (#1694) and is restorable from /manage/deleted-songs, which is
           what makes a BULK version of a destructive action acceptable at
           all. ---- */
    case 'bulk_delete': {
        ed2_requireEntitlement('delete_songs');
        require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'song_soft_delete.php';
        $rawIds = is_array($body['songIds'] ?? null) ? $body['songIds'] : [];
        $ids = [];
        foreach ($rawIds as $x) { $s = trim((string)$x); if ($s !== '') { $ids[] = $s; } }
        if (!$ids) { ed2_respond(['ok' => false, 'error' => 'songIds are required.'], 400); }
        if (count($ids) > 300) {
            ed2_respond(['ok' => false, 'error' => 'Too many songs selected (max 300 per bulk delete).'], 400);
        }
        $reason = trim((string)($body['reason'] ?? ''));
        $note   = trim((string)($body['note'] ?? ''));

        $deleted = [];
        $failed  = [];
        foreach ($ids as $songId) {
            /* songSoftDelete() runs its OWN begin_transaction/commit per call
               (song_soft_delete.php) — no wrapping transaction here, matching
               how the single-song delete_song case above calls it. */
            $verdict = songSoftDelete($db, $songId, $ed2UserId, $reason === '' ? null : $reason, $note);
            if ($verdict['ok']) {
                $deleted[] = $songId;
            } else {
                $failed[] = ['id' => $songId, 'error' => $verdict['error'], 'status' => $verdict['status']];
            }
        }

        logActivity('song.bulk_delete', 'song', '', [
            'count'   => count($ids),
            'deleted' => count($deleted),
            'failed'  => count($failed),
            'reason'  => $reason === '' ? null : $reason,
        ]);
        ed2_respond(['ok' => true, 'deleted' => $deleted, 'failed' => $failed]);
        break;
    }

    /* ---- credit_search (GET) — autocomplete for credit names. UNIONs the six
           song-credit tables (grouped by name → combined usage count + the roles
           it appears in) + the tblMusicians registry for kind=any. Table +
           label fragments come ONLY from the hardcoded allow-list (CLAUDE.md #5);
           the query term is bound.

           #1800 C1 — the (role => table) map used to be a THIRD hand-typed copy
           of the same six pairs, alongside ED2_CREDIT_TABLES (this file, above)
           and MUSICIAN_CREDIT_ROLE_TABLES (includes/musician_helpers.php, already
           require_once'd by this file — line ~256). Flagged as "discovered, not
           fixed here" in tests/php/test-musician-credit-tables-single-list.php's
           doc-block since #1785; fixed here by delegating directly to
           MUSICIAN_CREDIT_ROLE_TABLES instead of ED2_CREDIT_TABLES — its keys are
           ALREADY the exact singular convention ('writer'/'composer'/…) this
           endpoint's own `kind=` query param uses, so no key transform is needed,
           whereas ED2_CREDIT_TABLES's plural JSON-payload keys ('writers'/…)
           would have needed one. Rule #22 — reuse the shared source of truth,
           never re-fork the list a third time. ---- */
    case 'credit_search': {
        $q     = trim((string)($_GET['q'] ?? ''));
        $kind  = strtolower(trim((string)($_GET['kind'] ?? 'any')));
        $limit = max(1, min(20, (int)($_GET['limit'] ?? 12)));
        if ($q === '') { ed2_respond(['ok' => true, 'suggestions' => []]); }

        /* Allow-list: only these (label => table) pairs ever reach the SQL —
           MUSICIAN_CREDIT_ROLE_TABLES itself, not a local re-typed copy. */
        $kindToTable = MUSICIAN_CREDIT_ROLE_TABLES;
        $tables = ($kind === 'any') ? $kindToTable : (isset($kindToTable[$kind]) ? [$kind => $kindToTable[$kind]] : []);
        if (!$tables) { ed2_respond(['ok' => false, 'error' => 'Unknown kind.'], 400); }

        $like = '%' . $q . '%';
        $parts = []; $params = []; $types = '';
        foreach ($tables as $label => $table) {
            $parts[]  = "SELECT Name, '{$label}' AS kindLabel, COUNT(*) AS cnt FROM `{$table}` WHERE Name LIKE ? GROUP BY Name";
            $params[] = $like; $types .= 's';
        }
        if ($kind === 'any') {
            $parts[]  = "SELECT Name, 'registry' AS kindLabel, 0 AS cnt FROM tblMusicians WHERE Name LIKE ?";
            $params[] = $like; $types .= 's';
        }
        $sql = 'SELECT u.Name, GROUP_CONCAT(DISTINCT u.kindLabel) AS kinds, SUM(u.cnt) AS usageCount
                  FROM (' . implode(' UNION ALL ', $parts) . ') u
                 GROUP BY u.Name ORDER BY usageCount DESC, u.Name ASC LIMIT ?';
        $types .= 'i'; $params[] = $limit;

        $stmt = $db->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        $suggestions = [];
        while ($row = $res->fetch_assoc()) {
            $suggestions[] = [
                'name'  => (string)$row['Name'],
                'usage' => (int)$row['usageCount'],
                'kinds' => $row['kinds'] !== null ? explode(',', (string)$row['kinds']) : [],
            ];
        }
        $stmt->close();
        ed2_respond(['ok' => true, 'suggestions' => $suggestions]);
        break;
    }

    /* ---- user_search (GET) — live-search users by display name / username
           (#498; ported into api2 by #1629 ahead of v1 api.php's retirement).
           Powers the /manage/restrictions name-first picker
           (manage/includes/renderEntityPicker.js) resolving a human-friendly
           label ("Lance Manasse · @admin") to the canonical tblUsers.Id.

           ELI5: the admin types a few letters of someone's name and this
           hands back a short list of matching accounts to choose from.

           WHY IT LIVES HERE, NOT ON THE PUBLIC api.php: tblUsers isn't
           exposed there, and this endpoint needs the SAME admin/editor+
           gate api2.php already enforces (line ~113) — matching api.php's
           `hasRole($currentUser['role'], 'editor')` check exactly, so
           moving files doesn't change who can call it.

           NOT an editor feature: grep finds zero call sites in editor.js /
           index.php / editor2.php / v2/* — its only consumer is Content
           Restrictions (manage/restrictions.php). It rode along on the
           editor's api.php only because that was the nearest admin-gated,
           tblUsers-reading endpoint (#1629 corrects sibling issue #1609,
           which had assumed this WAS editor-only).

           Query + bind_param sequence are unchanged from v1 (verbatim
           port); the only deliberate change is dropping v1's local
           try/catch-swallow-to-empty-list in favour of api2's shared
           ed2_respond()/outer-catch convention (every other case in this
           file follows that pattern — a DB error surfaces as a real 500
           with error_detail for admins, rather than a silent empty list
           that looks like "no matches" to the curator). ---- */
    case 'user_search': {
        $q     = trim((string)($_GET['q'] ?? ''));
        $limit = max(1, min(20, (int)($_GET['limit'] ?? 10)));
        if ($q === '') { ed2_respond(['ok' => true, 'suggestions' => []]); }
        $like = '%' . $q . '%';
        $stmt = $db->prepare(
            'SELECT Id, DisplayName, Username, Role
               FROM tblUsers
              WHERE DisplayName LIKE ? OR Username LIKE ?
              ORDER BY DisplayName ASC
              LIMIT ?'
        );
        $stmt->bind_param('ssi', $like, $like, $limit);
        $stmt->execute();
        $res = $stmt->get_result();
        $suggestions = [];
        while ($row = $res->fetch_assoc()) {
            $suggestions[] = [
                'id'    => (int)$row['Id'],
                'label' => $row['DisplayName'] ?: $row['Username'],
                'hint'  => '@' . $row['Username'] . ' · ' . $row['Role'],
            ];
        }
        $stmt->close();
        ed2_respond(['ok' => true, 'suggestions' => $suggestions]);
        break;
    }

    /* ---- org_search (GET) — live-search organisations by name/slug (#498;
           ported into api2 by #1629 alongside user_search — same picker,
           same reasoning, same gate parity). The "organisation" source in
           renderEntityPicker.js's Content Restrictions picker.

           ELI5: same idea as user_search above, but for choosing an
           organisation instead of a user.

           DETAIL: an empty q still returns a page of active orgs (browse
           mode) instead of nothing — mirrors v1's behaviour so opening the
           picker with no query yet shows choices rather than an empty
           panel. Query + bind_param sequence unchanged from v1; same
           ed2_respond()/outer-catch adaptation noted above user_search. ---- */
    case 'org_search': {
        $q     = trim((string)($_GET['q'] ?? ''));
        $limit = max(1, min(50, (int)($_GET['limit'] ?? 20)));
        if ($q === '') {
            $stmt = $db->prepare(
                'SELECT Id, Name, Slug, LicenceType FROM tblOrganisations
                  WHERE IsActive = 1
                  ORDER BY Name ASC
                  LIMIT ?'
            );
            $stmt->bind_param('i', $limit);
        } else {
            $like = '%' . $q . '%';
            $stmt = $db->prepare(
                'SELECT Id, Name, Slug, LicenceType FROM tblOrganisations
                  WHERE IsActive = 1 AND (Name LIKE ? OR Slug LIKE ?)
                  ORDER BY Name ASC
                  LIMIT ?'
            );
            $stmt->bind_param('ssi', $like, $like, $limit);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        $suggestions = [];
        while ($row = $res->fetch_assoc()) {
            $suggestions[] = [
                'id'    => (int)$row['Id'],
                'label' => $row['Name'],
                'hint'  => 'licence: ' . ($row['LicenceType'] ?: 'none') . ' · slug: ' . $row['Slug'],
            ];
        }
        $stmt->close();
        ed2_respond(['ok' => true, 'suggestions' => $suggestions]);
        break;
    }

    /* ---- load_index (GET) — the lightweight song list for the editor sidebar:
           id / number / title / songbook / songbookName (+ audio/sheet flags).
           Reuses SongData::getSongsSlimIndex() (the canonical slim index the PWA
           uses) — NEVER materialises the whole corpus (CLAUDE.md #17). ---- */
    case 'load_index': {
        require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'SongData.php';
        /* #1765 Feature 1 — admin surface: the v2 sidebar + songbook <select>
           must keep listing songs/songbooks disabled from the public site. */
        $songData = SongData::forAdmin();
        /* #1679 A2 — `songbooks` is the REAL catalogue, not the set of books that
           happen to appear in the song index.
           WHY: v2's songbook <select> derived its options from the loaded index
           (sidebar.js songbookList()), so a songbook with ZERO songs contributed
           no row and could not be chosen — a curator who had just created a book
           could not move the first song into it. That is a regression against v1,
           whose own load_index has always returned this exact list
           (manage/editor/api.php `case 'load_index'`). Same source
           (SongData::getSongbooks()), no new query.
           SHAPE: mapped to the {abbr, name} pairs the client actually consumes,
           rather than shipping the full catalogue row (series, compilers, links,
           colours…) for a two-field control. `id` is the Abbreviation — which IS
           the SongId prefix (rule #27), so it is the value that must be POSTed
           back as `songbook`. DisplayAbbr is deliberately NOT used here: it is
           display-only and is never a prefix.
           KEY AGREEMENT: `songbooks[].abbr` / `.name` are read verbatim by
           sidebar.js's songbookList(); tests/test-v2-songbook-move-ui.js pins the
           same two names on the client side, so the pair cannot drift silently
           (rule #35). */
        $books = [];
        foreach ($songData->getSongbooks() as $b) {
            $abbr = (string)($b['id'] ?? '');
            if ($abbr === '') { continue; }
            $books[] = ['abbr' => $abbr, 'name' => (string)($b['name'] ?? $abbr)];
        }
        ed2_respond([
            'ok'        => true,
            'songs'     => $songData->getSongsSlimIndex(),
            'songbooks' => $books,
            /* #1783 — the staging-book abbr, so the client can exclude it as a
               move TARGET (a duplicate is assigned FROM it, never TO it) without
               hardcoding the literal (rule #35). */
            'pendingSongbook' => ED2_PENDING_SONGBOOK,
        ]);
        break;
    }

    /* ---- easyworship_export (GET) — build + stream an EasyWorship Songs.db
           (#1059). Behaviourally identical to the legacy v1 handler this
           replaces (api.php:2604-2642, now delegating to the SAME shared
           includes/easyworship_export.php helpers, #1678 — v1's endpoint had
           no v2 equivalent, which the #1629 v1-consumer audit missed and
           epic #1601's v1 retirement would otherwise have broken). GET, so
           the POST-only X-Requested-With gate above does not apply; the
           file-level isAuthenticated()/editor-role guard already covers it.
           This is the ONE binary-response case in this file: on success it
           streams the file and `return`s instead of falling through to the
           JSON default, matching v1's structure exactly. ---- */
    case 'easyworship_export': {
        require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'SongData.php';
        require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'easyworship_export.php';
        $abbr     = strtoupper(trim((string)($_GET['abbr'] ?? '')));
        $oneId    = trim((string)($_GET['id'] ?? ''));
        $maxLines = max(0, (int)($_GET['maxLinesPerSlide'] ?? 0));
        try {
            /* #1765 Feature 1 — admin surface: exporting must reach a
               disabled songbook's songs. */
            $songData = SongData::forAdmin();
            $songs    = [];
            $stem     = 'EasyWorship';
            if ($oneId !== '') {
                $one = $songData->getSongById($oneId);
                if ($one !== null) { $songs = [$one]; $stem = (string)($one['title'] ?? $oneId); }
            } elseif ($abbr !== '') {
                $songs = $songData->getSongs($abbr);
                $stem  = $abbr;
            }
            if (empty($songs)) {
                ed2_respond(['ok' => false, 'error' => 'No songs found to export (pass ?abbr=<songbook> or ?id=<SongId>).'], 404);
            }
            $tmp = tempnam(sys_get_temp_dir(), 'ewexp_');
            $n   = _ewExport_writeDb($tmp, $songs, $maxLines);
            $fname = trim((string)preg_replace('/[^A-Za-z0-9 _\-]/', '', $stem));
            if ($fname === '') { $fname = 'EasyWorship'; }
            $fname .= ' Songs.db';
            header('Content-Type: application/x-sqlite3');
            header('Content-Disposition: attachment; filename="' . $fname . '"');
            header('Content-Length: ' . filesize($tmp));
            header('Cache-Control: no-store, no-cache, must-revalidate');
            header('X-Song-Count: ' . $n);
            readfile($tmp);
            @unlink($tmp);
            /* Streamed a binary file — don't fall through to the JSON default
               (copies v1's api.php:2604-2642 structure exactly, #1678). */
            return;
        } catch (\Throwable $e) {
            error_log('[easyworship_export] ' . $e->getMessage());
            ed2_respond(['ok' => false, 'error' => 'Export failed: ' . $e->getMessage()], 500);
        }
        break;
    }

    /* ---- revision_list (GET) — revision history for a song, newest first
           (metadata only; the before/after snapshot PAIR for one revision is
           fetched via revision_get below; the full NewData snapshot alone is
           fetched on restore). ---- */
    case 'revision_list': {
        $songId = trim((string)($_GET['songId'] ?? $_GET['id'] ?? ''));
        if ($songId === '') { ed2_respond(['ok' => false, 'error' => 'songId is required.'], 400); }
        $limit = (int)($_GET['limit'] ?? 50);
        if ($limit < 1 || $limit > 200) { $limit = 50; }
        $rows = [];
        $q = $db->prepare(
            'SELECT r.Id, r.Action, r.CreatedAt, r.UserId, u.Username
               FROM tblSongRevisions r LEFT JOIN tblUsers u ON u.Id = r.UserId
              WHERE r.SongId = ? ORDER BY r.CreatedAt DESC, r.Id DESC LIMIT ?'
        );
        $q->bind_param('si', $songId, $limit);
        $q->execute();
        $res = $q->get_result();
        while ($r = $res->fetch_assoc()) {
            $rows[] = [
                'id'        => (int)$r['Id'],
                'action'    => (string)$r['Action'],
                'createdAt' => (string)$r['CreatedAt'],
                'userId'    => $r['UserId'] !== null ? (int)$r['UserId'] : null,
                'username'  => $r['Username'] !== null ? (string)$r['Username'] : null,
            ];
        }
        $q->close();
        ed2_respond(['ok' => true, 'revisions' => $rows]);
        break;
    }

    /* ---- revision_snapshots (GET) — the RAW decoded NewData of EVERY revision
           for one song in ONE response (newest first), plus the window's base
           pre-state and the field-key -> column map, so the client can compute
           per-field BLAME ("who last changed this field, and when") by walking
           the whole history with the ONE shipped normaliser (diffSnapshots()'s
           shape logic, extended) — never a second server-side resolver (#1122,
           rule #22).

           Companion to revision_list (metadata only) and revision_get (ONE
           before/after PAIR, with its before-ladder): this is the BULK raw read,
           and — unlike revision_get — it carries NO before-ladder. Blame's
           pre-state chain IS the consecutive NewData rows (ed2_touchRevision()'s
           #1743-C2 chain rule below, api2.php:1983-1997), so `base` is simply the
           OLDEST returned row's own PreviousData (already selected, zero extra
           SQL) — the left side of the window's first pair, nothing more.

           Ordering + limit BYTE-MIRROR revision_list (rule #35: one ordering,
           not two): (CreatedAt, Id) DESC, default 50, max 200. We fetch limit+1
           to set `truncated` (older rows exist beyond the window) without a
           second COUNT.

           `fieldMap` is DERIVED from ED2_META_FIELDS at runtime (never a
           re-typed list) so the client can fold a legacy payload-shape lowercase
           key (`title`) to its canonical column (`Title`) with NO JS copy of the
           map. `noRollback` names the field keys whose metadata_field_update is
           NOT a plain field write — `songbook` re-keys via songRelocate(), and
           `hasAudio`/`hasSheetMusic` are DERIVED and ignore the sent value (rule
           #44) — so the client renders those blame-only, with no Revert button.

           A row whose NewData is NULL/undecodable is a ROW-LEVEL `null` (never a
           409, unlike revision_get: there is a whole list to render around one
           bad row). Pure read; no entitlement beyond the file-wide editor gate,
           no logActivity — identical posture to revision_list. ---- */
    case 'revision_snapshots': {
        $songId = trim((string)($_GET['songId'] ?? $_GET['id'] ?? ''));
        if ($songId === '') { ed2_respond(['ok' => false, 'error' => 'songId is required.'], 400); }
        $limit = (int)($_GET['limit'] ?? 50);
        if ($limit < 1 || $limit > 200) { $limit = 50; }
        $fetch = $limit + 1;   /* one extra row -> `truncated` without a COUNT */
        $rows = [];
        $q = $db->prepare(
            'SELECT r.Id, r.Action, r.CreatedAt, r.UserId, u.Username, r.PreviousData, r.NewData
               FROM tblSongRevisions r LEFT JOIN tblUsers u ON u.Id = r.UserId
              WHERE r.SongId = ? ORDER BY r.CreatedAt DESC, r.Id DESC LIMIT ?'
        );
        $q->bind_param('si', $songId, $fetch);
        $q->execute();
        $res = $q->get_result();
        while ($r = $res->fetch_assoc()) {
            $nd = $r['NewData'] !== null ? json_decode((string)$r['NewData'], true) : null;
            $rows[] = [
                'id'        => (int)$r['Id'],
                'action'    => (string)$r['Action'],
                'createdAt' => (string)$r['CreatedAt'],
                'userId'    => $r['UserId'] !== null ? (int)$r['UserId'] : null,
                'username'  => $r['Username'] !== null ? (string)$r['Username'] : null,
                'newData'   => is_array($nd) ? $nd : null,   /* row-level null; never a 409 */
                '_prev'     => $r['PreviousData'],           /* internal: only the oldest row's is used, stripped below */
            ];
        }
        $q->close();

        /* limit+1 fetch: if the probe (oldest extra) row came back, older
           history exists beyond this window. Drop it before building `base`. */
        $truncated = count($rows) > $limit;
        if ($truncated) { array_pop($rows); }

        /* base = the OLDEST returned row's own PreviousData (last element, since
           the order is newest-first) — the pre-state of the window's first pair.
           No ladder (that is revision_get's job); a decode miss degrades to
           baseSource:'none', a designed empty left side, never an error. */
        $base = null; $baseSource = 'none';
        if ($rows) {
            $oldestPrev = $rows[count($rows) - 1]['_prev'];
            if ($oldestPrev !== null) {
                $decoded = json_decode((string)$oldestPrev, true);
                if (is_array($decoded)) { $base = $decoded; $baseSource = 'previousData'; }
            }
        }
        foreach ($rows as &$row) { unset($row['_prev']); }
        unset($row);

        $fieldMap = array_map(static fn(array $v): string => $v[0], ED2_META_FIELDS);

        ed2_respond([
            'ok'         => true,
            'revisions'  => $rows,
            'base'       => $base,
            'baseSource' => $baseSource,   /* 'previousData' | 'none' (rule #20 vocabulary) */
            'truncated'  => $truncated,
            'fieldMap'   => $fieldMap,
            'noRollback' => ['songbook', 'hasAudio', 'hasSheetMusic'],
        ]);
        break;
    }

    /* ---- revision_get (GET) — the before/after snapshot PAIR for ONE
           revision, so a curator can see WHAT changed before committing to
           Restore (#1628 item 4). Companion to revision_list (metadata only,
           above) and revision_restore (writes, below) — never a third reader
           of tblSongRevisions with its own resolution logic (rule #22).

           ELI5: this is the one place that works out "what did the song look
           like right before this edit, and right after it" so the browser
           can just draw the two side by side — the browser never has to
           guess or re-derive that itself.

           DETAILED — the before-snapshot LADDER (rule #35: resolved ONCE,
           here, server-side; the client only reads the answer + its
           `beforeSource` label, never re-implements the chain):
             1. This row's OWN `PreviousData`, when non-NULL and decodable —
                the #1743-C2 chain (f18c54ac) populates this for every
                revision `ed2_touchRevision()` has written since, verbatim
                from whatever the immediately preceding row's NewData held.
             2. Else the immediately OLDER revision's own `NewData` (one
                extra bound SELECT, ordered `(CreatedAt, Id) DESC` — the SAME
                ordering revision_list above uses — and excluding this row
                itself) — the fallback for a pre-chain row whose
                PreviousData was never populated, or one where PreviousData
                failed to decode.
             3. Else `null` — genuinely nothing recorded before this
                revision: the song's very first revision, or an install with
                no earlier row at all.
           `beforeSource` names which rung answered as a VOCABULARY STRING
           ('previousData' | 'priorRevision' | 'none') — never a boolean pair
           (rule #20's discipline applied here in miniature) — so the client
           can render "No earlier state recorded" for 'none' without
           re-deriving which case it is.

           `after` (this row's own NewData) 409s with the same "no snapshot"
           wording revision_restore uses (below) when it is NULL/undecodable
           — there is nothing to diff either side against. `before` failing
           to resolve past rung 1/2 is NOT an error — it degrades to
           beforeSource:'none', a designed rendering (plan §A.1), never a
           thrown failure.

           Three snapshot SHAPES coexist in NewData/PreviousData (see
           ed2_touchRevision()'s doc-block below): the v2 full snapshot
           `{song:{...}, components, credits, tags, links}`, a bare
           tblSongs-row (the pre-#1743 v1 shape), or an old editor-payload
           lowercase-keys shape. This endpoint returns whichever shape each
           side actually is, untouched — normalising/diffing that is the
           CLIENT's job (diffSnapshots() in v2/revisions-tab.js), never
           this endpoint's. ---- */
    case 'revision_get': {
        $revisionId   = (int)($_GET['revisionId'] ?? 0);
        $expectSongId = trim((string)($_GET['songId'] ?? ''));   // optional defense-in-depth
        if ($revisionId <= 0) { ed2_respond(['ok' => false, 'error' => 'revisionId is required.'], 400); }

        $sel = $db->prepare(
            'SELECT r.Id, r.SongId, r.Action, r.CreatedAt, r.UserId, u.Username, r.PreviousData, r.NewData
               FROM tblSongRevisions r LEFT JOIN tblUsers u ON u.Id = r.UserId
              WHERE r.Id = ? LIMIT 1'
        );
        $sel->bind_param('i', $revisionId);
        $sel->execute();
        $row = $sel->get_result()->fetch_assoc();
        $sel->close();
        if (!$row) { ed2_respond(['ok' => false, 'error' => 'Revision not found.'], 404); }

        $songId = (string)$row['SongId'];
        /* Guard against a client passing a revisionId from a different song —
           the same defence-in-depth revision_restore applies (below,
           $expectSongId). */
        if ($expectSongId !== '' && $expectSongId !== $songId) {
            ed2_respond(['ok' => false, 'error' => 'Revision does not belong to the expected song.'], 409);
        }

        $after = $row['NewData'] !== null ? json_decode((string)$row['NewData'], true) : null;
        if (!is_array($after)) {
            /* Same "no snapshot" semantics revision_restore uses below —
               nothing to diff either side against. */
            ed2_respond(['ok' => false, 'error' => 'This revision has no snapshot to restore.'], 409);
        }

        /* The before-snapshot ladder — see the doc-block above for the full
           reasoning. $beforeSource stays null until a rung actually answers;
           the response coalesces null -> the 'none' vocabulary string right
           at the end (below), so 'none' never has to be a placeholder default
           threaded through the middle of this logic — it is purely the
           terminal, nothing-answered case, textually and logically last.

           Rung 1: this row's own chained PreviousData. */
        $before       = null;
        $beforeSource = null;
        if ($row['PreviousData'] !== null) {
            $decodedPrev = json_decode((string)$row['PreviousData'], true);
            if (is_array($decodedPrev)) {
                $before       = $decodedPrev;
                $beforeSource = 'previousData';
            }
        }
        /* Rung 2: the immediately older revision row for this SongId, by the
           SAME (CreatedAt, Id) DESC ordering revision_list uses — only
           reached when rung 1 didn't answer (PreviousData NULL or
           undecodable). */
        if ($beforeSource === null) {
            $createdAt = (string)$row['CreatedAt'];
            $prior = $db->prepare(
                'SELECT NewData FROM tblSongRevisions
                  WHERE SongId = ? AND (CreatedAt < ? OR (CreatedAt = ? AND Id < ?))
                  ORDER BY CreatedAt DESC, Id DESC LIMIT 1'
            );
            $prior->bind_param('sssi', $songId, $createdAt, $createdAt, $revisionId);
            $prior->execute();
            $priorRow = $prior->get_result()->fetch_assoc();
            $prior->close();
            if ($priorRow && $priorRow['NewData'] !== null) {
                $decodedPrior = json_decode((string)$priorRow['NewData'], true);
                if (is_array($decodedPrior)) {
                    $before       = $decodedPrior;
                    $beforeSource = 'priorRevision';
                }
            }
        }
        /* Rung 3 (implicit, resolved by the ?? below): neither rung
           answered — $before stays null, $beforeSource is reported as
           'none'. */

        ed2_respond([
            'ok'       => true,
            'revision' => [
                'id'        => (int)$row['Id'],
                'action'    => (string)$row['Action'],
                'createdAt' => (string)$row['CreatedAt'],
                'userId'    => $row['UserId'] !== null ? (int)$row['UserId'] : null,
                'username'  => $row['Username'] !== null ? (string)$row['Username'] : null,
            ],
            'after'        => $after,
            'before'       => $before,
            'beforeSource' => $beforeSource ?? 'none',
        ]);
        break;
    }

    /* ---- revision_restore (POST) — restore the song to a revision's full
           snapshot (scalars + components + credits + tags + links), atomically,
           then record a forced 'restore' revision so the trail stays linear. ---- */
    case 'revision_restore': {
        $revisionId   = (int)($body['revisionId'] ?? 0);
        $expectSongId = trim((string)($body['songId'] ?? ''));   // optional defense-in-depth
        if ($revisionId <= 0) { ed2_respond(['ok' => false, 'error' => 'revisionId is required.'], 400); }

        $sel = $db->prepare('SELECT SongId, NewData FROM tblSongRevisions WHERE Id = ? LIMIT 1');
        $sel->bind_param('i', $revisionId);
        $sel->execute();
        $rev = $sel->get_result()->fetch_assoc();
        $sel->close();
        if (!$rev) { ed2_respond(['ok' => false, 'error' => 'Revision not found.'], 404); }
        $songId = (string)$rev['SongId'];
        /* Guard against a client passing a revisionId from a different song. */
        if ($expectSongId !== '' && $expectSongId !== $songId) {
            ed2_respond(['ok' => false, 'error' => 'Revision does not belong to the expected song.'], 409);
        }
        $snap = $rev['NewData'] !== null ? json_decode((string)$rev['NewData'], true) : null;
        if (!is_array($snap)) { ed2_respond(['ok' => false, 'error' => 'This revision has no snapshot to restore.'], 409); }
        if (!ed2_songExists($db, $songId)) { ed2_respond(['ok' => false, 'error' => 'Song not found.'], 404); }

        $db->begin_transaction();
        try {
            ed2_applySongSnapshot($db, $songId, $snap);
            ed2_touchRevision($db, $songId, $ed2UserId, 'restore', true);   // force — always audit a restore
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }

        /* #1860 go-live — a restore can reintroduce identifiers a later
           save had cleared, or clear ones a linked work still reflects;
           re-run the Works auto-link so tblWorkSongs agrees with whatever
           the restore actually wrote. Post-commit (ownTransaction=true);
           re-READ the stored Ccli/Iswc rather than trusting $snap (rule
           #35 — the snapshot is INPUT to ed2_applySongSnapshot(), not proof
           of what landed).
           @deleted-visible: identifier read (#1860) — ed2_songExists($db,
           $songId) just above already confirmed this SongId exists
           (deliberately visible to a soft-deleted row, by that function's
           own reasoning); reading CCLI/ISWC by direct SongId to re-run the
           work link is the same editor-write-path posture, never a listing.
           @disabled-visible: same reasoning, one predicate over (#1765) —
           a song in a publicly-disabled book is still fully editable here. */
        $restoreIdStmt = $db->prepare('SELECT Ccli, Iswc FROM tblSongs WHERE SongId = ? LIMIT 1');
        $restoreIdStmt->bind_param('s', $songId);
        $restoreIdStmt->execute();
        $restoreIdRow = $restoreIdStmt->get_result()->fetch_assoc() ?: ['Ccli' => '', 'Iswc' => null];
        $restoreIdStmt->close();
        workAutolinkSafe($db, $songId, (string)($restoreIdRow['Ccli'] ?? ''), (string)($restoreIdRow['Iswc'] ?? ''), true);

        /* #1862 — a restore round-trips the snapshot's OWN HasAudio/
           HasSheetMusic/credit scalars (ed2_applySongSnapshot()'s generic
           ED2_META_FIELDS loop has no special case for the two flags, and the
           credit tables were just replaced too), so both derived denorms can
           legitimately go stale the instant a restore lands. Same
           "restore keeps the snapshot, then live truth immediately re-wins"
           contract as the legacy v1 restore path (manage/editor/api.php's
           song_restore case) — post-commit, own failure boundaries, neither
           call ever throws. */
        songMediaRecomputeFlags($db, $songId);
        pdRecomputeForSong($db, $songId);

        logActivity('song.revision.restore', 'song', $songId, ['fromRevisionId' => $revisionId]);
        ed2_respond(['ok' => true, 'songId' => $songId]);
        break;
    }

    /* ---- save_song (POST) — the legacy whole-song save, now SHARED ---- */
    /* The Song Editor's primary save path. Extracted VERBATIM into the shared
       editorSaveSongCore() (#1200) so this v2 API and the legacy api.php run the
       SAME save logic — the migration off the legacy api.php that does NOT risk
       the primary save path by re-implementing it. The CSRF gate + auth/role
       checks above already protect this POST; the core returns the HTTP status +
       body, which ed2_respond() emits in the v2 house style. */
    case 'save_song': {
        require_once __DIR__ . '/save_song_core.php';
        $r = editorSaveSongCore();
        ed2_respond($r['body'], $r['status']);
    }

    default:
        ed2_respond(['ok' => false, 'error' => 'Unknown action.'], 400);
    }
} catch (\Throwable $e) {
    error_log('[editor-v2-api ' . $action . '] ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    $ed2Payload = [
        'ok'           => false,
        'error'        => 'Server error.',
        'error_detail' => $ed2IsAdmin ? ($e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine()) : null,
    ];
    /* #1679 A8 — a refusal that names the migration to run, shown to EVERY role
       that can reach this endpoint.
       WHY A SEPARATE KEY: `error_detail` above is deliberately admin-only (it
       leaks file paths, line numbers and raw mysqli text). But this endpoint
       admits the `editor` role while $ed2IsAdmin is computed from `admin`, so a
       plain editor — the person most likely to trip an un-applied migration —
       got a bare "Server error." with nothing to act on. The relocate refusal is
       an environment fault with no sensitive content: it names four FK
       constraint names and a migration card. So it travels in its own field,
       ungated, and the admin-only channel is NOT widened.
       RECOGNISED BY TYPE, never by matching the sentence (rule #35) — the
       message is user-facing copy and will be reworded; the class will not. */
    if ($e instanceof SongRelocateEnvironmentException) {
        $ed2Payload['error_hint'] = $e->getMessage();
    }
    ed2_respond($ed2Payload, 500);
}
