<?php

declare(strict_types=1);

/**
 * iHymns — Migration Registry (single source of truth)
 *
 * Each migration's four facets — script filename, dashboard card text, and
 * pending-probe — co-located under one slug. The legacy arrays in
 * setup-database.php ($migrationOrder, $migrationCards, $migrationProbes,
 * plus the migration entries of $scriptMap) are derived from this so
 * existing call sites continue to work unchanged.
 *
 * Adding a new migration: append one entry to the returned array. The order
 * of array keys IS the bulk-runner deployment order — keep dependencies
 * upstream of dependents. CI guards (tests/php/test-migration-registry.php
 * and tests/php/test-schema-coverage.php) verify that the registry stays
 * consistent and that schema.sql mirrors every migration-created table.
 *
 * Required helpers (defined in setup-database.php at include time):
 *   _migProbe_tableExists, _migProbe_columnExists,
 *   _migProbe_columnIsNullable, _migProbe_triggerExists
 *
 * Required globals (set by setup-database.php at include time):
 *   $hasCredentials  — used by the IANA card's extra_html block
 *
 * Direct HTTP access is denied — this file only meaningful when required
 * from setup-database.php's registry-load step.
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

return [
    'account-sync' => [
        'script' => 'migrate-account-sync.php',
        'card' => [
            'title'  => 'Account Sync &amp; Shared Setlists',
            'body'   => 'Adds the <code>Settings</code> column to <code>tblUsers</code>'
                      . ' (per-device prefs sync) and creates <code>tblSharedSetlists</code>,'
                      . ' then imports any legacy share-link JSON files into the new table.'
                      . ' Idempotent — safe to re-run.',
            'button' => 'Run Account Sync Migration',
        ],
        'probe' => static fn(\mysqli $db) => !_migProbe_columnExists($db, 'tblUsers', 'Settings'),
    ],
    'credits' => [
        'script' => 'migrate-credit-fields.php',
        'card' => [
            'title'  => 'Credit Fields (#497)',
            'body'   => 'Adds <code>TuneName</code> and <code>Iswc</code> columns to'
                      . ' <code>tblSongs</code>, and creates'
                      . ' <code>tblSongArrangers</code>, <code>tblSongAdaptors</code>'
                      . ' and <code>tblSongTranslators</code>. Idempotent — safe to re-run.',
            'button' => 'Run Credit Fields Migration',
        ],
        'probe' => static fn(\mysqli $db) => !_migProbe_columnExists($db, 'tblSongs', 'TuneName'),
    ],
    'songbook-meta' => [
        'script' => 'migrate-songbook-meta.php',
        'card' => [
            'title'  => 'Songbook Metadata (#502)',
            'body'   => 'Adds <code>Colour</code> (catch-up — missed forward-migration on older'
                      . ' databases), <code>IsOfficial</code>, <code>Publisher</code>,'
                      . ' <code>PublicationYear</code>, <code>Copyright</code> and'
                      . ' <code>Affiliation</code> columns to <code>tblSongbooks</code>,'
                      . ' and flags existing non-Misc songbooks as official.'
                      . ' Idempotent — safe to re-run.',
            'button' => 'Run Songbook Metadata Migration',
        ],
        'probe' => static fn(\mysqli $db) => !_migProbe_columnExists($db, 'tblSongbooks', 'IsOfficial'),
    ],
    'user-features-catchup' => [
        'script' => 'migrate-user-features-catchup.php',
        'card' => [
            'title'  => 'User Features Catch-Up (#517)',
            'body'   => 'Catches up three pieces of user-feature schema that landed in'
                      . ' <code>schema.sql</code> without forward-migrations and were'
                      . ' surfaced by the Schema Audit page: <code>tblUserGroups.AllowCardReorder</code>,'
                      . ' <code>tblUserSetlists</code> table, and <code>tblSearchQueries</code>'
                      . ' table. Idempotent — safe to re-run.',
            'button' => 'Run User Features Catch-Up Migration',
        ],
        'probe' => static fn(\mysqli $db) => !_migProbe_columnExists($db, 'tblUserGroups', 'AllowCardReorder'),
    ],
    'activity-log-expand' => [
        'script' => 'migrate-activity-log-expand.php',
        'card' => [
            'title'  => 'Activity Log Expansion (#535)',
            'body'   => 'Extends <code>tblActivityLog</code> with the columns required by the'
                      . ' comprehensive instrumentation pass: <code>Result</code>,'
                      . ' <code>UserAgent</code>, <code>RequestId</code>, <code>Method</code>,'
                      . ' <code>DurationMs</code>, plus indexes on <code>Result</code> and'
                      . ' <code>RequestId</code> for the common debug-query patterns.'
                      . ' Idempotent — safe to re-run.',
            'button' => 'Run Activity Log Expansion Migration',
        ],
        'probe' => static fn(\mysqli $db) => !_migProbe_columnExists($db, 'tblActivityLog', 'RequestId'),
    ],
    'credit-people' => [
        'script' => 'migrate-credit-people.php',
        'card' => [
            'title'  => 'Credit People Registry (#545)',
            'body'   => 'Creates the registry tables that back the new'
                      . ' <code>/manage/credit-people</code> area: <code>tblCreditPeople</code>'
                      . ' (canonical name plus optional birth/death + notes),'
                      . ' <code>tblCreditPersonLinks</code> (multiple external reference URLs'
                      . ' per person), and <code>tblCreditPersonIPI</code> (multiple IPI Name'
                      . ' Numbers per person). The five song-credit tables are not modified —'
                      . ' this is additive. Idempotent — safe to re-run.',
            'button' => 'Run Credit People Registry Migration',
        ],
        'probe' => static fn(\mysqli $db) => !_migProbe_tableExists($db, 'tblCreditPeople'),
    ],
    'credit-people-flags' => [
        'script' => 'migrate-credit-people-flags.php',
        'card' => [
            'title'  => 'Credit People Flags (#584, #585)',
            'body'   => 'Adds the <code>IsSpecialCase</code> and <code>IsGroup</code>'
                      . ' classification flags to <code>tblCreditPeople</code> so the registry'
                      . ' can distinguish special-case attributions (Anonymous, Traditional,'
                      . ' Public Domain, Unknown) from real individuals, and groups / bands /'
                      . ' collectives (Hillsong United, Bethel Music) from single people.'
                      . ' Backfills the four obvious special-case names on first run.'
                      . ' Idempotent — safe to re-run.',
            'button' => 'Run Credit People Flags Migration',
        ],
        'probe' => static fn(\mysqli $db) => !_migProbe_columnExists($db, 'tblCreditPeople', 'IsSpecialCase'),
    ],
    'song-artists' => [
        'script' => 'migrate-song-artists.php',
        'card' => [
            'title'  => 'Songs Artist credit (#587)',
            'body'   => 'Adds <code>tblSongArtists</code> — a sixth credit role parallel to the'
                      . ' existing five (writers / composers / arrangers / adaptors /'
                      . ' translators). Captures the recording / release artist of'
                      . ' contemporary worship songs (e.g. <em>Hillsong Worship</em> for'
                      . ' "What a Beautiful Name") and feeds the future ProPresenter export.'
                      . ' Names auto-register in <code>tblCreditPeople</code> via the same'
                      . ' INSERT-IGNORE pattern as the other roles. Idempotent — safe to re-run.',
            'button' => 'Run Songs Artist Migration',
        ],
        'probe' => static fn(\mysqli $db) => !_migProbe_tableExists($db, 'tblSongArtists'),
    ],
    'credit-people-slug' => [
        'script' => 'migrate-credit-people-slug.php',
        'card' => [
            'title'  => 'Credit People Slug + public page (#588)',
            'body'   => 'Adds <code>tblCreditPeople.Slug</code> with a UNIQUE index, backfills'
                      . ' it from each row\'s Name (collision-safe with numeric suffixes), and'
                      . ' unlocks the public <code>/people/&lt;slug&gt;</code> landing page —'
                      . ' bio, lifespan, external links, and a discography grouped by role'
                      . ' across the six song-credit tables. Idempotent — safe to re-run.',
            'button' => 'Run Credit People Slug Migration',
        ],
        'probe' => static fn(\mysqli $db) => !_migProbe_columnExists($db, 'tblCreditPeople', 'Slug'),
    ],
    'credit-people-slug-rebackfill' => [
        'script' => 'migrate-credit-people-slug-rebackfill.php',
        'card' => [
            'title'  => 'Credit People Slug re-backfill (audit follow-up)',
            'body'   => 'Data-fix counterpart to the slug-on-every-insert sweep across the eight'
                      . ' <code>INSERT INTO tblCreditPeople</code> call sites. Several admin paths'
                      . ' (Add Person, Rename / Merge auto-register, the editor save_song auto-promote)'
                      . ' historically omitted <code>Slug</code> from the INSERT — the column\'s'
                      . ' <code>NOT NULL DEFAULT \'\'</code> declaration meant the first such row landed'
                      . ' with <code>Slug=\'\'</code> and every subsequent INSERT tripped the UNIQUE'
                      . ' <code>uk_Slug</code> constraint. This migration finds any registry row whose'
                      . ' <code>Slug</code> is empty / NULL and assigns a collision-safe slug computed'
                      . ' from <code>Name</code>. Idempotent — re-running only touches rows that still'
                      . ' need a slug.',
            'button' => 'Re-backfill Empty Slugs',
        ],
        'probe' => static function (\mysqli $db): bool {
            if (!_migProbe_columnExists($db, 'tblCreditPeople', 'Slug')) return false;
            $res = $db->query("SELECT 1 FROM tblCreditPeople WHERE Slug = '' OR Slug IS NULL LIMIT 1");
            $needs = $res && $res->fetch_row() !== null;
            if ($res) $res->close();
            return $needs;
        },
    ],
    'credit-people-name-parts' => [
        'script' => 'migrate-credit-people-name-parts.php',
        'card' => [
            'title'  => 'Credit People Structured Name (#934)',
            'body'   => 'Adds <code>FirstNames</code>, <code>Surname</code> and <code>Suffix</code>'
                      . ' columns to <code>tblCreditPeople</code> so the registry can distinguish'
                      . ' "Cecil Frances Humphreys / Alexander" from "Charles / Wesley / Jr".'
                      . ' Backfills the three fields from each row\'s existing <code>Name</code>'
                      . ' using a heuristic that peels trailing suffixes (Jr, III, PhD…) and'
                      . ' assumes the last token is the surname. Group / special-case rows are'
                      . ' skipped — those keep <code>Name</code> as-is. Idempotent — safe to re-run;'
                      . ' a curator\'s manual edits are never overwritten on re-run.',
            'button' => 'Run Credit People Structured-Name Migration',
        ],
        'probe' => static fn(\mysqli $db) => !_migProbe_columnExists($db, 'tblCreditPeople', 'Surname'),
    ],
    'credit-people-aliases' => [
        'script' => 'migrate-credit-people-aliases.php',
        'card' => [
            'title'  => 'Credit People AKA / Aliases',
            'body'   => 'Adds <code>tblCreditPersonAliases</code> — MusicBrainz-style alternative'
                      . ' names per credit-person. Each alias carries a Type (legal / artist /'
                      . ' pseudonym / nickname / maiden / search-hint / misspelling / other), an'
                      . ' optional IETF BCP 47 Locale for transliterations'
                      . ' ("John Doe" en ↔ "ジョン・ドウ" ja), an <code>IsPrimary</code> flag for'
                      . ' the preferred display form, plus optional <code>SortName</code> /'
                      . ' <code>Note</code>. Searched alongside the canonical <code>Name</code> in'
                      . ' admin filter, editor typeahead and site search; surfaced as JSON-LD'
                      . ' <code>alternateName</code> on the public <code>/people/&lt;slug&gt;</code>'
                      . ' page. Bulk-import auto-detects "Smith (a.k.a. Jones)" patterns. Idempotent.',
            'button' => 'Run Credit People Aliases Migration',
        ],
        'probe' => static fn(\mysqli $db) => !_migProbe_tableExists($db, 'tblCreditPersonAliases'),
    ],
    'catalogues' => [
        'script' => 'migrate-catalogues.php',
        'card' => [
            'title'  => 'Catalogues — many-to-many song grouping (#941)',
            'body'   => 'Adds <code>tblCatalogues</code> + <code>tblCatalogueSongs</code> so songs can'
                      . ' be tagged into free-form thematic / curatorial groupings (Christmas, Modern'
                      . ' worship, Public-Domain only, denominational affiliations, …) — orthogonal to'
                      . ' the existing songbook hierarchy. One song can sit in many catalogues; admin'
                      . ' CRUD lives at <a href="/manage/catalogues">/manage/catalogues</a>.'
                      . ' Idempotent — safe to re-run.',
            'button' => 'Run Catalogues Migration',
        ],
        'probe' => static fn(\mysqli $db) => !_migProbe_tableExists($db, 'tblCatalogues'),
    ],
    'backfill-works-from-iswc' => [
        'script' => 'backfill-works-from-iswc.php',
        'card' => [
            'title'  => 'Backfill Works from existing ISWCs (#942)',
            'body'   => 'Walks <code>tblSongs.Iswc</code> and creates one <code>tblWorks</code> row per'
                      . ' distinct ISWC, then links every song carrying that ISWC into <code>tblWorkSongs</code>.'
                      . ' Title for new Works is derived from the most-common Title across member songs;'
                      . ' the lowest-numbered member is flagged <code>IsCanonical=1</code>. Idempotent —'
                      . ' re-runs add only the genuinely missing memberships and never overwrite a'
                      . ' curator\'s existing Work row. External-API enrichment (ISWCnet / MusicBrainz / MRO IDs)'
                      . ' is a separate follow-up (#943).',
            'button' => 'Backfill Works from ISWCs',
        ],
        'probe' => static function (\mysqli $db): bool {
            if (!_migProbe_tableExists($db, 'tblWorks')) return false;
            try {
                $r = $db->query(
                    "SELECT 1 FROM tblSongs s
                      WHERE s.Iswc IS NOT NULL AND TRIM(s.Iswc) <> ''
                        AND NOT EXISTS (SELECT 1 FROM tblWorks w WHERE w.Iswc = s.Iswc)
                      LIMIT 1"
                );
                $pending = $r && $r->fetch_row() !== null;
                if ($r) $r->close();
                return $pending;
            } catch (\Throwable $_e) { return false; }
        },
    ],
    'user-avatar-service' => [
        'script' => 'migrate-user-avatar-service.php',
        'card' => [
            'title'  => 'Per-user avatar service (#616)',
            'body'   => 'Adds <code>tblUsers.AvatarService</code> so each signed-in user can'
                      . ' override the project-level avatar resolver default — Gravatar,'
                      . ' Libravatar, DiceBear identicon (no third-party request), or None.'
                      . ' NULL on this column means "inherit project default", so existing'
                      . ' users behave identically until they choose to opt in or out via'
                      . ' Settings &gt; Profile &gt; Avatar source. Idempotent — safe to re-run.',
            'button' => 'Run Per-user Avatar Service Migration',
        ],
        'probe' => static fn(\mysqli $db) => !_migProbe_columnExists($db, 'tblUsers', 'AvatarService'),
    ],
    'organisation-licences' => [
        'script' => 'migrate-organisation-licences.php',
        'card' => [
            'title'  => 'Multiple licence types per organisation (#640)',
            'body'   => 'Adds <code>tblOrganisationLicences</code> — a join table so each'
                      . ' organisation can hold any number of licences (e.g. CCLI for lyrics +'
                      . ' MRL for musical notation). Backfills one row per org from the'
                      . ' existing primary <code>LicenceType</code> column. The primary column'
                      . ' is left in place for back-compat; tier resolution unions across both.'
                      . ' Idempotent — safe to re-run.',
            'button' => 'Run Multi-licence Migration',
        ],
        'probe' => static fn(\mysqli $db) => !_migProbe_tableExists($db, 'tblOrganisationLicences'),
    ],
    'songbook-affiliations' => [
        'script' => 'migrate-songbook-affiliations.php',
        'card' => [
            'title'  => 'Songbook affiliations registry (#670)',
            'body'   => 'Closes the &ldquo;Affiliation lookup table&rdquo; out-of-scope item from'
                      . ' #502. Adds <code>tblSongbookAffiliations</code> as a controlled'
                      . ' vocabulary (Name UNIQUE) so the songbook editor can typeahead-suggest'
                      . ' existing values instead of letting small typing variations create'
                      . ' duplicate entries. Backfills the registry from every distinct non-empty'
                      . ' <code>Affiliation</code> already in <code>tblSongbooks</code>.'
                      . ' Idempotent — safe to re-run.',
            'button' => 'Run Songbook Affiliations Migration',
        ],
        'probe' => static fn(\mysqli $db) => !_migProbe_tableExists($db, 'tblSongbookAffiliations'),
    ],
    'songbook-bibliographic' => [
        'script' => 'migrate-songbook-bibliographic.php',
        'card' => [
            'title'  => 'Songbook bibliographic metadata (#672)',
            'body'   => 'Adds 13 nullable columns to <code>tblSongbooks</code> for canonical'
                      . ' references to the wider bibliographic record: Website / Internet'
                      . ' Archive / Wikipedia URLs, plus the authority identifiers WikiData,'
                      . ' OCLC, OCN, LCP, ISBN, ARK, ISNI, VIAF, LCCN, and LC Class. All'
                      . ' optional; no FKs. Idempotent — safe to re-run.',
            'button' => 'Run Songbook Bibliographic Migration',
        ],
        'probe' => static fn(\mysqli $db) => !_migProbe_columnExists($db, 'tblSongbooks', 'WikidataId'),
    ],
    'songbook-language' => [
        'script' => 'migrate-songbook-language.php',
        'card' => [
            'title'  => 'Songbook language column (#673)',
            'body'   => 'Adds an optional <code>Language</code> column to <code>tblSongbooks</code>'
                      . ' (ISO 639-1 code, NULLable) so a curator can tag a songbook with its'
                      . ' predominant language. Mirrors <code>tblSongs.Language</code> without'
                      . ' the NOT NULL or DEFAULT — empty selection saves as NULL. Idempotent —'
                      . ' safe to re-run.',
            'button' => 'Run Songbook Language Migration',
        ],
        'probe' => static fn(\mysqli $db) => !_migProbe_columnExists($db, 'tblSongbooks', 'Language'),
    ],
    'ietf-bcp47-language' => [
        'script' => 'migrate-ietf-bcp47-language.php',
        'card' => [
            'title'  => 'IETF BCP 47 language tagging (#681)',
            'body'   => 'Brings every <code>Language</code> column on songs, songbooks,'
                      . ' translations and song-requests up to <code>VARCHAR(35)</code> so they'
                      . ' can hold a full IETF BCP 47 tag (language[-script][-region], e.g.'
                      . ' <code>pt-BR</code>, <code>zh-Hans-CN</code>, <code>sr-Latn</code>).'
                      . ' Adds <code>tblScripts</code> (~28 ISO 15924 codes) and'
                      . ' <code>tblRegions</code> (~255 ISO 3166-1 codes + six M.49 area'
                      . ' groupings) for the composite picker\'s typeahead. Idempotent — safe'
                      . ' to re-run.',
            'button' => 'Run IETF BCP 47 Language Migration',
        ],
        'probe' => static fn(\mysqli $db) =>
            !(_migProbe_tableExists($db, 'tblScripts') || _migProbe_tableExists($db, 'tblLanguageScripts')),
    ],
    'bulk-import-jobs' => [
        'script' => 'migrate-bulk-import-jobs.php',
        'card' => [
            'title'  => 'Bulk Import Jobs Tracking (#676)',
            'body'   => 'Adds <code>tblBulkImportJobs</code> so the Song Editor\'s ZIP import'
                      . ' can run asynchronously: the upload returns <code>{job_id}</code>'
                      . ' immediately, the worker keeps processing in the freed PHP request,'
                      . ' and the browser polls a status endpoint for live progress (% complete).'
                      . ' Lets a curator navigate away while a long import runs; a notification'
                      . ' fires on completion. Idempotent — safe to re-run.',
            'button' => 'Run Bulk Import Jobs Migration',
        ],
        'probe' => static fn(\mysqli $db) => !_migProbe_tableExists($db, 'tblBulkImportJobs'),
    ],
    'backfill-legacy-songbook-languages' => [
        'script' => 'migrate-backfill-legacy-songbook-languages.php',
        'card' => [
            'title'  => 'Backfill legacy songbook languages (#735)',
            'body'   => 'Sets <code>Language=\'en\'</code> on the 5 legacy English songbooks'
                      . ' (CP, JP, MP, SDAH, CH) where it isn\'t already set. Required by the'
                      . ' language filter (#734 / #736) — the filter renders only when ≥2'
                      . ' distinct primary subtags exist across songbooks UNION songs, so this'
                      . ' baseline ensures the filter appears the moment any non-English'
                      . ' songbook lands. Idempotent — re-running is safe; rows already set'
                      . ' (e.g. <code>en-GB</code>, <code>en-US</code>) are not touched.',
            'button' => 'Run Legacy Songbook Language Backfill',
        ],
        'probe' => static function (\mysqli $db): bool {
            if (!_migProbe_columnExists($db, 'tblSongbooks', 'Language')) {
                return false;
            }
            try {
                $res = $db->query(
                    "SELECT 1 FROM tblSongbooks
                      WHERE Abbreviation IN ('CP','JP','MP','SDAH','CH')
                        AND (Language IS NULL OR Language = '')
                      LIMIT 1"
                );
                $needs = $res && $res->fetch_row() !== null;
                if ($res) $res->close();
                return $needs;
            } catch (\Throwable $_e) { return false; }
        },
    ],
    'backfill-song-language-from-songbook' => [
        'script' => 'migrate-backfill-song-language-from-songbook.php',
        'card' => [
            'title'  => 'Backfill song language from songbook (audit follow-up)',
            'body'   => 'Several bulk-import passes landed every song in a non-English songbook'
                      . ' tagged <code>language=\'en\'</code> — HAC is the documented example: the'
                      . ' songbook itself was correctly marked Croatian, but every member song'
                      . ' carries the English tag. This walks every songbook that DECLARES a'
                      . ' single primary language (<code>tblSongbooks.Language</code> non-empty)'
                      . ' and rewrites any member song whose primary language subtag disagrees.'
                      . ' Conservative: songbooks with no Language declared are left alone'
                      . ' (multi-language books like Misc), and a song already carrying a more'
                      . ' specific tag whose primary matches (<code>en-GB</code> inside an'
                      . ' <code>en</code> songbook) is preserved. Re-runnable.'
                      . ' Run <code>/manage/data-health → Regenerate songs.json cache</code>'
                      . ' afterwards so the public PWA picks up the new tags.',
            'button' => 'Run Song Language Backfill',
        ],
        'probe' => static function (\mysqli $db): bool {
            try {
                $colS = $db->query(
                    "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE()
                        AND TABLE_NAME = 'tblSongs'
                        AND COLUMN_NAME = 'Language' LIMIT 1"
                );
                if (!$colS || $colS->fetch_row() === null) {
                    if ($colS) $colS->close();
                    return false;
                }
                $colS->close();
                $colB = $db->query(
                    "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE()
                        AND TABLE_NAME = 'tblSongbooks'
                        AND COLUMN_NAME = 'Language' LIMIT 1"
                );
                if (!$colB || $colB->fetch_row() === null) {
                    if ($colB) $colB->close();
                    return false;
                }
                $colB->close();
                /* Detect any single-language songbook with at least one
                   member whose primary language subtag differs. */
                $res = $db->query(
                    "SELECT 1
                       FROM tblSongs s
                       JOIN tblSongbooks b ON b.Abbreviation = s.SongbookAbbr
                      WHERE b.Language IS NOT NULL AND b.Language <> ''
                        AND (
                              s.Language IS NULL OR s.Language = ''
                           OR LOWER(SUBSTRING_INDEX(s.Language, '-', 1))
                              <> LOWER(SUBSTRING_INDEX(b.Language, '-', 1))
                        )
                      LIMIT 1"
                );
                $needs = $res && $res->fetch_row() !== null;
                if ($res) $res->close();
                return $needs;
            } catch (\Throwable $_e) {
                return false;
            }
        },
    ],
    'user-preferred-languages' => [
        'script' => 'migrate-user-preferred-languages.php',
        'card' => [
            'title'  => 'User preferred languages column (#736)',
            'body'   => 'Adds <code>tblUsers.PreferredLanguagesJson</code> so a signed-in user'
                      . ' can save their language-filter choice to their account and have it'
                      . ' sync across devices. Stored as a JSON array of IETF BCP 47 primary'
                      . ' subtags (e.g. <code>["en","es"]</code>); NULL or <code>[]</code>'
                      . ' means "show all languages". Idempotent — safe to re-run.',
            'button' => 'Run User Preferred Languages Migration',
        ],
        'probe' => static fn(\mysqli $db) => !_migProbe_columnExists($db, 'tblUsers', 'PreferredLanguagesJson'),
    ],
    'iana-language-subtag-registry' => [
        'script' => 'migrate-iana-language-subtag-registry.php',
        'card' => [
            'title'  => 'IETF BCP 47 Reference Data (#738)',
            'body'   => 'Imports the IANA Language Subtag Registry and CLDR English display'
                      . ' names — every language (~8,000), script (~225), region (~305), and'
                      . ' variant (~140) subtag the IETF BCP 47 standard recognises. Uses'
                      . ' bundled snapshots in <code>appWeb/.sql/data/</code>; the picker'
                      . ' autocomplete works completely offline once applied.'
                      . '</p><p class="card-text text-secondary small">'
                      . 'Schema work (idempotent): renames <code>tblScripts</code> →'
                      . ' <code>tblLanguageScripts</code> for clarity, adds'
                      . ' <code>tblLanguageVariants</code>, adds <code>tblLanguages.Scope</code>.'
                      . ' Re-running picks up new rows from a refreshed snapshot without'
                      . ' touching curator-flagged ones.',
            'button' => 'Run IANA + CLDR Import',
            /* The IANA + CLDR card has a paired live-refresh side button +
               status line; rendered after the primary button, inside the
               card body. */
            'extra_html' => '<button type="button"'
                          . ' class="btn btn-outline-warning btn-action ms-2 ' . ($hasCredentials ? '' : 'disabled') . '"'
                          . ' data-action="refresh-iana-cldr">'
                          . '<i class="bi bi-cloud-download me-1" aria-hidden="true"></i>'
                          . 'Refresh from IANA + CLDR (live)</button>'
                          . '<p class="card-text small text-muted mt-2 mb-0" data-iana-refresh-status>'
                          . 'Live refresh fetches the latest IANA registry and CLDR JSON files,'
                          . ' overwrites the bundled snapshots, then re-runs the import.</p>',
        ],
        'probe' => static fn(\mysqli $db) =>
            !_migProbe_tableExists($db, 'tblLanguageVariants') || !_migProbe_columnExists($db, 'tblLanguages', 'Scope'),
    ],
    'cldr-native-names' => [
        'script' => 'migrate-cldr-native-names.php',
        'card' => [
            'title'  => 'CLDR Native Names overlay',
            'body'   => 'Backfills <code>tblLanguages.NativeName</code> with each language\'s'
                      . ' self-name — the form a speaker would write in their own locale'
                      . ' ("Deutsch", "日本語", "Tshivenḓa", "العربية"). Sourced from'
                      . ' <code>appWeb/.sql/data/cldr-native-names.json</code> (~316 entries,'
                      . ' generated from <code>cldr-localenames-full</code>; rebuild with'
                      . ' <code>tools/fetch-cldr-native-names.sh</code>). Once applied, the'
                      . ' IETF picker (#681 / #685) shows e.g. "German (Deutsch) — de" instead'
                      . ' of just "German — de". Idempotent — re-running no-ops on rows whose'
                      . ' <code>NativeName</code> already matches.',
            'button' => 'Run CLDR Native Names Overlay',
        ],
        'probe' => static function (\mysqli $db): bool {
            if (!_migProbe_columnExists($db, 'tblLanguages', 'NativeName')) {
                return false;
            }
            $jsonPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '.sql'
                      . DIRECTORY_SEPARATOR . 'data'
                      . DIRECTORY_SEPARATOR . 'cldr-native-names.json';
            if (!is_readable($jsonPath)) return false;
            $decoded = json_decode((string)@file_get_contents($jsonPath), true);
            if (!is_array($decoded) || empty($decoded['languages'])) return false;
            $codes = array_map('strtolower', array_keys($decoded['languages']));
            if (!$codes) return false;
            try {
                $placeholders = implode(',', array_fill(0, count($codes), '?'));
                $types        = str_repeat('s', count($codes));
                $stmt = $db->prepare(
                    "SELECT 1 FROM tblLanguages
                      WHERE Code IN ({$placeholders})
                        AND (NativeName IS NULL OR NativeName = '')
                      LIMIT 1"
                );
                $stmt->bind_param($types, ...$codes);
                $stmt->execute();
                $needs = $stmt->get_result()->fetch_row() !== null;
                $stmt->close();
                return $needs;
            } catch (\Throwable $_e) { return false; }
        },
    ],
    'tag-titlecase' => [
        'script' => 'migrate-tag-titlecase.php',
        'card' => [
            'title'  => 'Tag Title-Case Backfill (#762)',
            'body'   => 'Walks <code>tblSongTags</code> and rewrites <code>Name</code> to Title'
                      . ' Case for any row that isn\'t already canonical. The <code>bulk_tag</code>'
                      . ' handler now Title-Cases on every upsert, so new tags land canonical'
                      . ' from creation; this backfill resolves rows that pre-date #762\'s'
                      . ' normalisation. Idempotent — re-runs no-op on canonical rows. Rare'
                      . ' collisions (two rows whose canonical forms would clash) are logged'
                      . ' and left untouched for resolution via the forthcoming /manage/tags'
                      . ' merge UI.',
            'button' => 'Run Tag Title-Case Backfill',
        ],
        'probe' => static function (\mysqli $db): bool {
            if (!_migProbe_tableExists($db, 'tblSongTags')) return false;
            try {
                $res = $db->query(
                    "SELECT 1 FROM tblSongTags
                      WHERE Name <> TRIM(Name)
                         OR Name LIKE '%  %'
                         OR BINARY LEFT(Name, 1) <> UPPER(LEFT(Name, 1))
                      LIMIT 1"
                );
                $needs = $res && $res->fetch_row() !== null;
                if ($res) $res->close();
                return $needs;
            } catch (\Throwable $_e) { return false; }
        },
    ],
    'tblsongs-number-nullable' => [
        'script' => 'migrate-tblsongs-number-nullable.php',
        'card' => [
            'title'  => 'tblSongs.Number nullable (#783)',
            'body'   => 'Aligns the schema with the post-#392 policy that lets songs in'
                      . ' unofficial songbooks (Misc, custom collections) persist'
                      . ' <code>Number</code> as <code>NULL</code>. Without this, the save_song'
                      . ' handler\'s intentional NULL-bind raises mysqli error 1048 ("Column'
                      . ' \'Number\' cannot be null") on every Misc save. Idempotent —'
                      . ' INFORMATION_SCHEMA probe; skips when already nullable.',
            'button' => 'Run tblSongs.Number Nullable Migration',
        ],
        'probe' => static fn(\mysqli $db) => !_migProbe_columnIsNullable($db, 'tblSongs', 'Number'),
    ],
    'multi-language-tables' => [
        'script' => 'migrate-multi-language-tables.php',
        'card' => [
            'title'  => 'Multi-language tables (#778 phase A)',
            'body'   => 'Creates <code>tblSongbookLanguages</code> + <code>tblSongLanguages</code>'
                      . ' for the multi-language songbook / song work, and back-fills the legacy'
                      . ' single-tag <code>Language</code> columns into them with'
                      . ' <code>IsPrimary=1</code>. Read paths consuming the legacy columns'
                      . ' continue to work unchanged. Phases B-E of #778 build the chip-list'
                      . ' editors, display surfaces, filter union, and bulk-import auto-link'
                      . ' on top. Idempotent.',
            'button' => 'Run Multi-Language Tables Migration',
        ],
        'probe' => static fn(\mysqli $db) => !_migProbe_tableExists($db, 'tblSongbookLanguages'),
    ],
    'parent-songbooks' => [
        'script' => 'migrate-parent-songbooks.php',
        'card' => [
            'title'  => 'Parent Songbooks (#782 phase A)',
            'body'   => 'Adds <code>tblSongbooks.ParentSongbookId</code> +'
                      . ' <code>ParentRelationship</code> for hierarchical relationships'
                      . ' (translations / editions / abridgements), plus'
                      . ' <code>tblSongbookSeries</code> + <code>tblSongbookSeriesMembership</code>'
                      . ' for peer-to-peer collections (Songs of Fellowship volumes, themed'
                      . ' compilations). Both shapes coexist — a row can carry a parent FK AND'
                      . ' series memberships. Schema only; phases B-E (admin picker, public'
                      . ' display, helpers, bulk-import auto-link) are tracked in #782. Idempotent.',
            'button' => 'Run Parent Songbooks Migration',
        ],
        'probe' => static fn(\mysqli $db) => !_migProbe_columnExists($db, 'tblSongbooks', 'ParentSongbookId'),
    ],
    'song-links' => [
        'script' => 'migrate-song-links.php',
        'card' => [
            'title'  => 'Cross-book Song Links (#807 / #808)',
            'body'   => 'Creates <code>tblSongLinks</code> for the "this hymn appears in multiple'
                      . ' songbooks" relationship (Amazing Grace as MP-031 / CH-376 / SDAH-108 /'
                      . ' SoF-29 / JP-006), plus <code>tblSongLinkSuggestions</code> +'
                      . ' <code>tblSongLinkSuggestionsDismissed</code> for the admin'
                      . ' similar-titled-song candidate list (#808). Distinct from'
                      . ' <code>tblSongTranslations</code> (different-language same hymn) and'
                      . ' <code>tblSongbooks.ParentSongbookId</code> (translated / edition'
                      . ' derivatives at the songbook level). Idempotent.',
            'button' => 'Run Cross-book Song Links Migration',
        ],
        'probe' => static fn(\mysqli $db) => !_migProbe_tableExists($db, 'tblSongLinks'),
    ],
    'songcount-triggers' => [
        'script' => 'migrate-songcount-triggers.php',
        'card' => [
            'title'  => 'SongCount Triggers (#793)',
            'body'   => 'Installs three triggers (<code>AFTER INSERT / UPDATE / DELETE</code>)'
                      . ' on <code>tblSongs</code> so <code>tblSongbooks.SongCount</code>'
                      . ' auto-maintains without any application-side recompute. Lifts the'
                      . ' cache-maintenance responsibility off every current and future write'
                      . ' path. Also runs an initial recompute as part of installation.'
                      . ' Idempotent. On hosts that disallow <code>CREATE TRIGGER</code> the'
                      . ' migration logs a friendly skip cleanly (#815) — PR #792\'s app-side'
                      . ' recompute remains the safety net.',
            'button' => 'Run SongCount Triggers Migration',
        ],
        /* Two-signal probe: applied when EITHER the AFTER INSERT trigger
           exists OR the migration left its `songcount_triggers_attempted`
           sentinel in tblAppSettings. The sentinel covers the host-disallows-
           CREATE-TRIGGER case where the migration's friendly-skip path
           (#815) runs the initial recompute but never installs the trigger
           — pre-sentinel this card stayed pending forever even after
           successful runs. */
        'probe' => static function (\mysqli $db): bool {
            if (_migProbe_triggerExists($db, 'trg_songs_songcount_ai')) return false;
            try {
                $stmt = $db->prepare(
                    "SELECT SettingValue FROM tblAppSettings
                      WHERE SettingKey = 'songcount_triggers_attempted' LIMIT 1"
                );
                $stmt->execute();
                $row = $stmt->get_result()->fetch_row();
                $stmt->close();
                return !($row && (string)$row[0] === '1');
            } catch (\Throwable $_e) { return true; }
        },
    ],
    'songbook-compilers' => [
        'script' => 'migrate-songbook-compilers.php',
        'card' => [
            'title'  => 'Songbook Compilers (#831)',
            'body'   => 'Adds <code>tblSongbookCompilers</code> — a many-to-many join between'
                      . ' <code>tblSongbooks</code> and <code>tblCreditPeople</code> so a hymnal'
                      . ' can record the people who compiled / edited it (e.g. Mission Praise →'
                      . ' Peter Horrobin &amp; Greg Leavers). Distinct from the per-song credit'
                      . ' tables; this is a credit at the <em>songbook</em> level. Carries'
                      . ' SortOrder + an optional Note for edition / co-compiler context.'
                      . ' Idempotent.',
            'button' => 'Run Songbook Compilers Migration',
        ],
        'probe' => static fn(\mysqli $db) => !_migProbe_tableExists($db, 'tblSongbookCompilers'),
    ],
    'alternative-titles' => [
        'script' => 'migrate-alternative-titles.php',
        'card' => [
            'title'  => 'Alternative Titles for Songs &amp; Songbooks (#832)',
            'body'   => 'Adds <code>tblSongAlternativeTitles</code> +'
                      . ' <code>tblSongbookAlternativeTitles</code> so curators can record'
                      . ' multiple "also known as" titles per entity. Used for internal'
                      . ' search (a query for "Faith\'s Review and Expectation" returns'
                      . ' Amazing Grace; "Adventist Hymnal" returns The Church Hymnal) and'
                      . ' surfaced via JSON-LD <code>alternateName</code> for SEO. Each'
                      . ' alt carries optional Note + per-row Language tag (songs only;'
                      . ' lets a Spanish alt of an English hymn be flagged'
                      . ' <code>es</code>). Idempotent.',
            'button' => 'Run Alternative Titles Migration',
        ],
        'probe' => static fn(\mysqli $db) => !_migProbe_tableExists($db, 'tblSongAlternativeTitles'),
    ],
    'external-links' => [
        'script' => 'migrate-external-links.php',
        'card' => [
            'title'  => 'External Links System (#833)',
            'body'   => 'MusicBrainz-style external-links registry for songs, songbooks AND'
                      . ' credit-people. Adds <code>tblExternalLinkTypes</code> (controlled'
                      . ' vocabulary, ~37 seeded types — Hymnary.org, CCLI Songselect, IMSLP,'
                      . ' YouTube, Spotify, Internet Archive, Wikipedia, Wikidata, MusicBrainz,'
                      . ' VIAF, social, …) plus three per-entity join tables'
                      . ' (<code>tblSongbookExternalLinks</code>, <code>tblSongExternalLinks</code>,'
                      . ' <code>tblCreditPersonExternalLinks</code>). Multiple links of the same'
                      . ' type per entity supported (e.g. five Internet Archive scans of one'
                      . ' hymnal). Idempotent — re-runs upsert seed rows by slug without'
                      . ' touching curator-modified IsActive / DisplayOrder.',
            'button' => 'Run External Links Migration',
        ],
        'probe' => static fn(\mysqli $db) =>
            !_migProbe_tableExists($db, 'tblExternalLinkTypes')
            || !_migProbe_tableExists($db, 'tblSongbookExternalLinks')
            || !_migProbe_tableExists($db, 'tblSongExternalLinks')
            || !_migProbe_tableExists($db, 'tblCreditPersonExternalLinks'),
    ],
    'backfill-songbook-links' => [
        'script' => 'migrate-backfill-songbook-links.php',
        'card' => [
            'title'  => 'Backfill Songbook URL columns → External Links (#833)',
            'body'   => 'Copies non-empty <code>tblSongbooks.WebsiteUrl</code> /'
                      . ' <code>InternetArchiveUrl</code> / <code>WikipediaUrl</code> values'
                      . ' into <code>tblSongbookExternalLinks</code> with the corresponding'
                      . ' link types. Idempotent — re-runs use a NOT EXISTS guard so duplicate'
                      . ' (SongbookId, LinkType, Url) tuples are no-ops. The legacy columns'
                      . ' stay in place as read-fallbacks for one release cycle.',
            'button' => 'Run Songbook Links Backfill',
        ],
        'probe' => static function (\mysqli $db): bool {
            if (!_migProbe_tableExists($db, 'tblSongbookExternalLinks')
                || !_migProbe_tableExists($db, 'tblExternalLinkTypes')
                || !_migProbe_tableExists($db, 'tblSongbooks')) {
                return false;
            }
            $mappings = [
                'WebsiteUrl'         => 'official-website',
                'InternetArchiveUrl' => 'internet-archive',
                'WikipediaUrl'       => 'wikipedia',
            ];
            foreach ($mappings as $col => $slug) {
                if (!_migProbe_columnExists($db, 'tblSongbooks', $col)) continue;
                try {
                    $stmt = $db->prepare(
                        "SELECT 1
                           FROM tblSongbooks b
                           JOIN tblExternalLinkTypes lt ON lt.Slug = ?
                          WHERE b.`{$col}` IS NOT NULL
                            AND LENGTH(TRIM(b.`{$col}`)) > 0
                            AND NOT EXISTS (
                                  SELECT 1 FROM tblSongbookExternalLinks x
                                   WHERE x.SongbookId = b.Id
                                     AND x.LinkTypeId = lt.Id
                                     AND x.Url        = b.`{$col}`
                                )
                          LIMIT 1"
                    );
                    $stmt->bind_param('s', $slug);
                    $stmt->execute();
                    $needs = $stmt->get_result()->fetch_row() !== null;
                    $stmt->close();
                    if ($needs) return true;
                } catch (\Throwable $_e) { /* fall through to next column */ }
            }
            return false;
        },
    ],
    'backfill-credit-person-links' => [
        'script' => 'migrate-backfill-credit-person-links.php',
        'card' => [
            'title'  => 'Backfill Credit-Person Links → External Links (#833)',
            'body'   => 'Migrates rows from the existing <code>tblCreditPersonLinks</code>'
                      . ' (free-text LinkType from #545) into <code>tblCreditPersonExternalLinks</code>.'
                      . ' Maps free-text type strings to controlled-vocabulary slugs'
                      . ' (<code>wikipedia</code> → <code>wikipedia</code>, <code>imslp</code> →'
                      . ' <code>imslp</code>, …). Unrecognised values fall through to'
                      . ' <code>other</code> with the original string preserved in Note.'
                      . ' Idempotent. Legacy <code>tblCreditPersonLinks</code> stays as a'
                      . ' read-fallback for one release cycle.',
            'button' => 'Run Credit-Person Links Backfill',
        ],
        'probe' => static function (\mysqli $db): bool {
            if (!_migProbe_tableExists($db, 'tblCreditPersonExternalLinks')
                || !_migProbe_tableExists($db, 'tblCreditPersonLinks')) {
                return false;
            }
            try {
                $res = $db->query(
                    "SELECT 1
                       FROM tblCreditPersonLinks l
                      WHERE NOT EXISTS (
                            SELECT 1 FROM tblCreditPersonExternalLinks x
                             WHERE x.CreditPersonId = l.CreditPersonId
                               AND x.Url            = l.Url
                      )
                      LIMIT 1"
                );
                $needs = $res && $res->fetch_row() !== null;
                if ($res) $res->close();
                return $needs;
            } catch (\Throwable $_e) { return false; }
        },
    ],
    'works' => [
        'script' => 'migrate-works.php',
        'card' => [
            'title'  => 'Works — composition grouping (#840)',
            'body'   => 'Adds <code>tblWorks</code> + <code>tblWorkSongs</code> +'
                      . ' <code>tblWorkExternalLinks</code> so curators can group multiple'
                      . ' <code>tblSongs</code> rows that represent the same underlying composition'
                      . ' across different songbooks / arrangements / translations'
                      . ' (mirrors MusicBrainz Work ↔ Recording). Each Work carries a canonical'
                      . ' Title, optional ISWC (the international standard code for musical works),'
                      . ' optional Notes, and any number of external links. Also widens'
                      . ' <code>tblExternalLinkTypes.AppliesTo</code> to include <code>\'work\'</code>'
                      . ' and seeds the new flag on the relevant link types. No data backfill'
                      . ' (Works is brand new). Idempotent.',
            'button' => 'Run Works Migration',
        ],
        'probe' => static fn(\mysqli $db) =>
            !_migProbe_tableExists($db, 'tblWorks')
            || !_migProbe_tableExists($db, 'tblWorkSongs')
            || (_migProbe_tableExists($db, 'tblExternalLinkTypes')
                && !_migProbe_tableExists($db, 'tblWorkExternalLinks')),
    ],
    'external-link-patterns' => [
        'script' => 'migrate-external-link-patterns.php',
        'card' => [
            'title'  => 'External-Link URL Patterns (#845)',
            'body'   => 'Adds <code>tblExternalLinkPatterns</code> — a curator-editable'
                      . ' table of host / path patterns that maps a pasted URL to its'
                      . ' <code>tblExternalLinkTypes</code> entry. Replaces the JS-hardcoded'
                      . ' rule list shipped in #841 with a DB-driven one so adding a new'
                      . ' provider is a row insert (no code deploy). Sub-domain matching'
                      . ' (suffix vs exact host) and optional path-prefix discrimination'
                      . ' are both supported. Seeds the same provider list shipped in JS'
                      . ' so the auto-detect behaviour is unchanged on first migration.',
            'button' => 'Run External-Link Patterns Migration',
        ],
        'probe' => static fn(\mysqli $db) =>
            !_migProbe_tableExists($db, 'tblExternalLinkPatterns'),
    ],
    'extra-streaming-platforms' => [
        'script' => 'migrate-extra-streaming-platforms.php',
        'card' => [
            'title'  => 'Extra Streaming Platforms',
            'body'   => 'Adds Tidal, Deezer, Amazon Music, Pandora, iHeartRadio,'
                      . ' Qobuz, Napster, Anghami, JioSaavn, Yandex Music, Mixcloud'
                      . ' and Audiomack to <code>tblExternalLinkTypes</code> (#833) and'
                      . ' seeds their host patterns into <code>tblExternalLinkPatterns</code>'
                      . ' (#845) so a pasted URL from any of those services auto-detects'
                      . ' to the correct provider in the songs / songbooks / works /'
                      . ' credit-people external-links editors. Idempotent — link types'
                      . ' upsert by Slug, patterns guard on (LinkTypeId, Host, PathPrefix)'
                      . ' before inserting.',
            'button' => 'Run Extra Streaming Platforms Migration',
        ],
        /* Pending when the prerequisite registry tables already exist AND the
           sentinel 'tidal' slug hasn't been seeded yet. Anchored to a single
           sentinel so the probe converges to "applied" once the migration
           runs; using all 12 slugs in an OR-chain would let one curator-deleted
           row keep the card stuck pending forever. */
        'probe' => static function (\mysqli $db): bool {
            if (!_migProbe_tableExists($db, 'tblExternalLinkTypes')
                || !_migProbe_tableExists($db, 'tblExternalLinkPatterns')) {
                return false;
            }
            $stmt = $db->prepare(
                'SELECT 1 FROM tblExternalLinkTypes WHERE Slug = ? LIMIT 1'
            );
            $sentinel = 'tidal';
            $stmt->bind_param('s', $sentinel);
            $stmt->execute();
            $present = $stmt->get_result()->fetch_row() !== null;
            $stmt->close();
            return !$present;
        },
    ],
    'media-database-providers' => [
        'script' => 'migrate-media-database-providers.php',
        'card' => [
            'title'  => 'Media Database Providers',
            'body'   => 'Adds IMDb, The Movie DB (TMDB), TheTVDB, Letterboxd,'
                      . ' Rotten Tomatoes, Metacritic, AllMovie, TVmaze, Trakt,'
                      . ' JustWatch, MyAnimeList, AniDB and IGDB to'
                      . ' <code>tblExternalLinkTypes</code> (#833) with patterns'
                      . ' in <code>tblExternalLinkPatterns</code> (#845). Forward-'
                      . 'looking groundwork for iLyrics DB + MeedyaDB which will'
                      . ' share the iHymns external-link registry —'
                      . ' <code>AppliesTo</code> is deliberately wide'
                      . ' (<code>song,songbook,person,work</code>) so these surface'
                      . ' on every entity editor. Idempotent — link types upsert'
                      . ' by Slug, patterns guard on (LinkTypeId, Host, PathPrefix)'
                      . ' before inserting.',
            'button' => 'Run Media Database Providers Migration',
        ],
        /* Pending when prerequisite registry tables exist AND the sentinel
           'imdb' slug hasn't been seeded yet. Same single-sentinel pattern
           as extra-streaming-platforms above. */
        'probe' => static function (\mysqli $db): bool {
            if (!_migProbe_tableExists($db, 'tblExternalLinkTypes')
                || !_migProbe_tableExists($db, 'tblExternalLinkPatterns')) {
                return false;
            }
            $stmt = $db->prepare(
                'SELECT 1 FROM tblExternalLinkTypes WHERE Slug = ? LIMIT 1'
            );
            $sentinel = 'imdb';
            $stmt->bind_param('s', $sentinel);
            $stmt->execute();
            $present = $stmt->get_result()->fetch_row() !== null;
            $stmt->close();
            return !$present;
        },
    ],
    'musicbrainz-style-links' => [
        'script' => 'migrate-musicbrainz-style-links.php',
        'card' => [
            'title'  => 'MusicBrainz-Parity External Links',
            'body'   => 'Adds Myspace, AllMusic, Last.fm, Bandsintown, Genius and'
                      . ' Muzikum.eu to <code>tblExternalLinkTypes</code> (#833) with'
                      . ' patterns in <code>tblExternalLinkPatterns</code> (#845) — the'
                      . ' set of providers commonly surfaced on a MusicBrainz artist'
                      . ' page that iHymns didn\'t yet detect. Idempotent — link types'
                      . ' upsert by Slug, patterns guard on (LinkTypeId, Host, PathPrefix).',
            'button' => 'Run MusicBrainz-Parity External Links Migration',
        ],
        /* Sentinel: 'allmusic' is the most distinctive of the six new slugs
           and is unlikely to collide with anything a curator would manually
           seed. Same single-sentinel pattern as extra-streaming-platforms. */
        'probe' => static function (\mysqli $db): bool {
            if (!_migProbe_tableExists($db, 'tblExternalLinkTypes')
                || !_migProbe_tableExists($db, 'tblExternalLinkPatterns')) {
                return false;
            }
            $stmt = $db->prepare(
                'SELECT 1 FROM tblExternalLinkTypes WHERE Slug = ? LIMIT 1'
            );
            $sentinel = 'allmusic';
            $stmt->bind_param('s', $sentinel);
            $stmt->execute();
            $present = $stmt->get_result()->fetch_row() !== null;
            $stmt->close();
            return !$present;
        },
    ],
    'credit-person-identifiers' => [
        'script' => 'migrate-credit-person-identifiers.php',
        'card' => [
            'title'  => 'Credit-Person Identifiers (IPI + ISNI)',
            'body'   => 'Creates <code>tblCreditPersonIdentifiers</code> — a unified'
                      . ' MusicBrainz-style identifier table that holds both IPI Name'
                      . ' Numbers (#545) and the new ISNI (International Standard Name'
                      . ' Identifier) rows side by side, with <code>IdentifierType</code>'
                      . ' discriminating. Backfills every existing'
                      . ' <code>tblCreditPersonIPI</code> row over with'
                      . ' <code>IdentifierType = \'ipi\'</code>. The legacy table stays in'
                      . ' place as a one-release rollback snapshot and gets dropped in a'
                      . ' follow-up migration. Idempotent — re-runs use a NOT EXISTS guard.',
            'button' => 'Run Credit-Person Identifiers Migration',
        ],
        'probe' => static fn(\mysqli $db) =>
            !_migProbe_tableExists($db, 'tblCreditPersonIdentifiers'),
    ],
    'worldcat-and-secondhandsongs' => [
        'script' => 'migrate-worldcat-and-secondhandsongs.php',
        'card' => [
            'title'  => 'WorldCat + SecondHandSongs Links',
            'body'   => 'Widens the existing <code>oclc-worldcat</code> link type from'
                      . ' <code>AppliesTo = \'songbook\'</code> to'
                      . ' <code>song,songbook,person,work</code> so a curator can attach'
                      . ' a WorldCat / WorldCat Identities URL to a credit-person (every'
                      . ' published author has one) or a song / work. Adds the missing'
                      . ' SecondHandSongs slug — the canonical database of song'
                      . ' originals, cover versions and releases — with its'
                      . ' <code>secondhandsongs.com</code> URL pattern.',
            'button' => 'Run WorldCat + SecondHandSongs Migration',
        ],
        /* Pending when oclc-worldcat is still on its narrow AppliesTo OR when
           the SecondHandSongs slug hasn't been seeded yet. Two checks because
           a curator who runs the migration once shouldn't see it re-surface
           if only one of the two changes is still pending. */
        'probe' => static function (\mysqli $db): bool {
            if (!_migProbe_tableExists($db, 'tblExternalLinkTypes')) {
                return false;
            }
            /* SecondHandSongs slug check. */
            $stmt = $db->prepare(
                'SELECT 1 FROM tblExternalLinkTypes WHERE Slug = ? LIMIT 1'
            );
            $slug = 'secondhandsongs';
            $stmt->bind_param('s', $slug);
            $stmt->execute();
            $hasShs = $stmt->get_result()->fetch_row() !== null;
            $stmt->close();
            if (!$hasShs) return true;
            /* WorldCat widening check — pending while AppliesTo is still
               just 'songbook' or hasn't been widened to include 'person'. */
            $stmt = $db->prepare(
                "SELECT 1 FROM tblExternalLinkTypes
                  WHERE Slug = 'oclc-worldcat'
                    AND NOT FIND_IN_SET('person', AppliesTo)
                  LIMIT 1"
            );
            $stmt->execute();
            $isNarrow = $stmt->get_result()->fetch_row() !== null;
            $stmt->close();
            return $isNarrow;
        },
    ],
    'canonicalise-existing-isni' => [
        'script' => 'migrate-canonicalise-existing-isni.php',
        'card' => [
            'title'  => 'Canonicalise Existing ISNI Rows',
            'body'   => 'Re-formats every existing ISNI row in'
                      . ' <code>tblCreditPersonIdentifiers</code> through the same'
                      . ' <code>canonicaliseIsni()</code> helper the runtime save path'
                      . ' uses — turning bare-digit storage like'
                      . ' <code>0000000121032683</code> into the canonical ISO 27729'
                      . ' display form <code>0000 0001 2103 2683</code>. Search,'
                      . ' link-out (<code>https://isni.org/isni/&lt;bare&gt;</code>)'
                      . ' and the <code>uk_PersonIdValue</code> UNIQUE constraint all'
                      . ' work better when every ISNI matches the same shape. Idempotent —'
                      . ' rows already canonical are no-ops; duplicate-key races (same'
                      . ' person, two stored renderings of the same ISNI) drop the'
                      . ' redundant row in favour of the canonical one.',
            'button' => 'Run ISNI Canonicalisation Migration',
        ],
        /* Pending when any ISNI row in the identifiers table isn\'t already
           in canonical "NNNN NNNN NNNN NNNX" form. Gated on the prerequisite
           table existing so this card stays hidden on fresh installs that
           haven\'t run migrate-credit-person-identifiers yet. */
        'probe' => static function (\mysqli $db): bool {
            if (!_migProbe_tableExists($db, 'tblCreditPersonIdentifiers')) {
                return false;
            }
            $sql = "SELECT 1 FROM tblCreditPersonIdentifiers
                     WHERE IdentifierType = 'isni'
                       AND IdentifierValue NOT REGEXP '^[0-9]{4} [0-9]{4} [0-9]{4} [0-9]{3}[0-9X]$'
                     LIMIT 1";
            $res = $db->query($sql);
            $hasNonCanonical = $res && $res->fetch_row() !== null;
            if ($res) $res->close();
            return (bool)$hasNonCanonical;
        },
    ],
    'song-media' => [
        'script' => 'migrate-song-media.php',
        'card' => [
            'title'  => 'Song Media Uploads (#853)',
            'body'   => 'Adds <code>tblSongMedia</code> — the unified per-song accompanying-files'
                      . ' table so curators can upload audio (MP3 / M4A / OGG / WAV / FLAC / ALAC),'
                      . ' sheet music (PDF), notation (MusicXML) and MIDI for each song via the'
                      . ' Song Editor. Hybrid storage: PDF / MIDI / MusicXML go to a'
                      . ' <code>MEDIUMBLOB</code> column for atomic backups + transactional gating;'
                      . ' audio goes to <code>appWeb/uploads/songs/&lt;hash&gt;</code> off the public'
                      . ' docroot, served via a gated <code>/song-media/&lt;id&gt;</code> route so'
                      . ' <code>checkContentAccess()</code> applies regardless of backend. The'
                      . ' legacy <code>tblSongs.HasAudio</code> / <code>HasSheetMusic</code> flags'
                      . ' from MissionPraise scraping stay in place as read-fallbacks for one'
                      . ' release cycle. Idempotent.',
            'button' => 'Run Song Media Migration',
        ],
        'probe' => static fn(\mysqli $db) =>
            !_migProbe_tableExists($db, 'tblSongMedia'),
    ],
    'song-component-language' => [
        'script' => 'migrate-song-component-language.php',
        'card' => [
            'title'  => 'Song Component Language Override (#858)',
            'body'   => 'Adds <code>Language VARCHAR(35) NULL</code> to'
                      . ' <code>tblSongComponents</code> so a multi-language medley'
                      . ' (e.g. an English carol with a Spanish chorus) can record'
                      . ' the actual language of each verse / chorus / bridge instead'
                      . ' of forcing the whole song under a single'
                      . ' <code>tblSongs.Language</code> tag. <code>NULL</code> means'
                      . ' "inherit from the parent song"; an explicit value overrides'
                      . ' per-component. Public render uses the column to set'
                      . ' <code>lang="…"</code> on each component <code>&lt;div&gt;</code>'
                      . ' (correct screen-reader pronunciation, browser hyphenation)'
                      . ' and to populate the JSON-LD <code>MusicComposition.inLanguage</code>'
                      . ' union. Idempotent.',
            'button' => 'Run Song Component Language Migration',
        ],
        'probe' => static fn(\mysqli $db) =>
            !_migProbe_columnExists($db, 'tblSongComponents', 'Language'),
    ],
    'song-arrangement' => [
        'script' => 'migrate-song-arrangement.php',
        'card' => [
            'title'  => 'Song Arrangement Persistence (#892)',
            'body'   => 'Adds <code>ArrangementJson JSON NULL</code> to'
                      . ' <code>tblSongs</code> so the Song Editor\'s Structure-tab'
                      . ' arrangement (an array of indices into <code>components[]</code>'
                      . ' that allows repetition — e.g. a refrain played between every'
                      . ' verse) can finally round-trip through save → reload. Pre-#892'
                      . ' the editor rendered the chips and POSTed the field, but the'
                      . ' server had no column to write into and silently dropped it.'
                      . ' <code>NULL</code> = render in stored <code>SortOrder</code>'
                      . ' (current behaviour); a JSON int-array overrides the order.'
                      . ' Idempotent.',
            'button' => 'Run Song Arrangement Migration',
        ],
        'probe' => static fn(\mysqli $db) =>
            !_migProbe_columnExists($db, 'tblSongs', 'ArrangementJson'),
    ],
    'bulk-import-per-songbook' => [
        'script' => 'migrate-bulk-import-per-songbook.php',
        'card' => [
            'title'  => 'Bulk-Import Per-Songbook Breakdown (#906)',
            'body'   => 'Adds <code>PerSongbookJson JSON NULL</code> to'
                      . ' <code>tblBulkImportJobs</code> so the bulk-import flow can'
                      . ' persist a per-songbook breakdown of created / skipped /'
                      . ' failed counts alongside the existing aggregate totals.'
                      . ' Pre-#906 the import notification only said'
                      . ' "Imported X new (Y skipped)" — the curator couldn\'t tell'
                      . ' whether the skips were "songs already in DB" (legitimate'
                      . ' skip) or parse failures (real bug). The new column carries'
                      . ' enough detail to render a per-songbook table in the import'
                      . ' summary. Idempotent.',
            'button' => 'Run Bulk-Import Per-Songbook Migration',
        ],
        'probe' => static fn(\mysqli $db) =>
            !_migProbe_columnExists($db, 'tblBulkImportJobs', 'PerSongbookJson'),
    ],
    'bulk-import-phase-label' => [
        'script' => 'migrate-bulk-import-phase-label.php',
        'card' => [
            'title'  => 'Bulk-Import Phase Label (#907)',
            'body'   => 'Adds <code>PhaseLabel VARCHAR(64) NULL</code> to'
                      . ' <code>tblBulkImportJobs</code> so the bulk-import worker'
                      . ' can record its current phase ("walking-zip", "parsing-songs",'
                      . ' "flushing-songbooks", etc.) and the polling frontend can'
                      . ' surface a human-readable status above the progress bar even'
                      . ' at 0% progress. Pre-#907 the curator saw a blank "0%"'
                      . ' indicator for the first several seconds while the worker'
                      . ' was reading the upload, walking the archive index, and'
                      . ' probing the schema — none of which advance the'
                      . ' <code>ProcessedEntries</code> counter. The new column lets'
                      . ' the frontend render "Walking ZIP archive…" so the user'
                      . ' understands progress is happening even when the percentage'
                      . ' isn\'t moving. Idempotent.',
            'button' => 'Run Bulk-Import Phase Label Migration',
        ],
        'probe' => static fn(\mysqli $db) =>
            !_migProbe_columnExists($db, 'tblBulkImportJobs', 'PhaseLabel'),
    ],
    'bulk-import-skipped-songids' => [
        'script' => 'migrate-bulk-import-skipped-songids.php',
        'card' => [
            'title'  => 'Bulk-Import Skipped SongIds',
            'body'   => 'Adds <code>SkippedSongIdsJson JSON NULL</code> to'
                      . ' <code>tblBulkImportJobs</code> so the worker can record every'
                      . ' SongId it left untouched (INSERT-only contract). The bulk-import'
                      . ' completion notification gains a "Download skipped SongIds"'
                      . ' button that streams the contents as a CSV — lets the curator'
                      . ' audit specifically WHICH rows the import refused to overwrite'
                      . ' after a re-upload, instead of staring at an opaque "4,661 skipped"'
                      . ' aggregate count. Idempotent.',
            'button' => 'Run Bulk-Import Skipped-SongIds Migration',
        ],
        'probe' => static fn(\mysqli $db) =>
            !_migProbe_columnExists($db, 'tblBulkImportJobs', 'SkippedSongIdsJson'),
    ],
    'activity-log-proxy-vpn' => [
        'script' => 'migrate-activity-log-proxy-vpn.php',
        'card' => [
            'title'  => 'Activity Log Proxy/VPN + Per-Request',
            'body'   => 'Adds <code>IpProxyChain</code>, <code>ProxyVpnIndicator</code>'
                      . ' and <code>ProxyVpnDetail</code> columns to <code>tblActivityLog</code>'
                      . ' so every audit row records the real client IP (resolved'
                      . ' through Cloudflare / X-Forwarded-For / X-Real-IP), the'
                      . ' intermediate proxy chain, and a heuristic + (future)'
                      . ' external classification of whether the request came'
                      . ' through a VPN, TOR exit, datacentre, or generic proxy.'
                      . ' Also adds a new <code>tblIpReputation</code> cache table'
                      . ' that the future external-lookup integration writes'
                      . ' through to, so a busy IP doesn\'t pay the lookup'
                      . ' latency on every subsequent request. Pairs with the'
                      . ' per-request shutdown logger that records every'
                      . ' dynamic-PHP request (action=request.success / .failure'
                      . ' / .error) with status code + duration. Idempotent.',
            'button' => 'Run Activity Log Proxy/VPN Migration',
        ],
        'probe' => static fn(\mysqli $db) =>
            !_migProbe_columnExists($db, 'tblActivityLog', 'ProxyVpnIndicator'),
    ],
    'email-verification-tokens' => [
        'script' => 'migrate-email-verification-tokens.php',
        'card' => [
            'title'  => 'Email Verification Tokens (#898)',
            'body'   => 'Creates <code>tblEmailVerificationTokens</code> — single-use'
                      . ' SHA-256-hashed tokens backing the verification email fired'
                      . ' on password-based registration. Powered by the new'
                      . ' <code>EmailService</code> abstraction; landed alongside the'
                      . ' real-email-delivery work (replacing the three'
                      . ' <code>error_log</code>-only auth flows). 24-hour expiry,'
                      . ' single-use, FK to <code>tblUsers</code> with cascade. Idempotent.',
            'button' => 'Run Email Verification Tokens Migration',
        ],
        'probe' => static fn(\mysqli $db) =>
            !_migProbe_tableExists($db, 'tblEmailVerificationTokens'),
    ],
    'password-reset-token-hash-width' => [
        'script' => 'migrate-password-reset-token-hash-width.php',
        'card' => [
            'title'  => 'Password Reset Token Hash Width (#898 follow-up)',
            'body'   => 'Widens <code>tblPasswordResetTokens.Token</code> from'
                      . ' <code>VARCHAR(48)</code> to <code>CHAR(64)</code> so the'
                      . ' SHA-256 hex hash is stored at full width rather than'
                      . ' silently truncated to 48 chars. Pre-existing rows hold a'
                      . ' 48-char prefix and will fail to validate after the ALTER —'
                      . ' since reset tokens expire in 1 hour, any in-flight tokens'
                      . ' at deploy time naturally cycle out within the hour. Idempotent.',
            'button' => 'Run Password Reset Token Hash Width Migration',
        ],
        'probe' => static fn(\mysqli $db) => (function (\mysqli $db): bool {
            $stmt = $db->prepare(
                "SELECT DATA_TYPE, CHARACTER_MAXIMUM_LENGTH
                   FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME   = 'tblPasswordResetTokens'
                    AND COLUMN_NAME  = 'Token'
                  LIMIT 1"
            );
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$row) return false; /* table missing — install.php will create it wide */
            $len  = (int)($row['CHARACTER_MAXIMUM_LENGTH'] ?? 0);
            $type = strtolower((string)$row['DATA_TYPE']);
            return !($len >= 64 && $type === 'char');
        })($db),
    ],
    'email-login-token-hashing' => [
        'script' => 'migrate-email-login-token-hashing.php',
        'card' => [
            'title'  => 'Email Login Token Hashing (#898 follow-up)',
            'body'   => 'Flips <code>tblEmailLoginTokens.Token</code> storage from raw'
                      . ' (48-char hex) to SHA-256-hashed (64-char hex). The auth.php'
                      . ' helpers now hash on insert and on lookup; this migration'
                      . ' clears any pre-existing rows so a stale plaintext row can\'t'
                      . ' shadow a freshly-hashed one. Magic-link tokens expire in 10'
                      . ' minutes — any user mid-sign-in at deploy time needs a fresh'
                      . ' code. The 6-digit Code column stays plaintext (low entropy;'
                      . ' the defence is single-use + email-scoped lookup + expiry).'
                      . ' Idempotent via a sentinel in <code>tblAppSettings</code>.',
            'button' => 'Run Email Login Token Hashing Migration',
        ],
        'probe' => static fn(\mysqli $db) => (function (\mysqli $db): bool {
            $stmt = $db->prepare(
                "SELECT SettingValue FROM tblAppSettings WHERE SettingKey = 'email_login_token_hashed' LIMIT 1"
            );
            $stmt->execute();
            $row = $stmt->get_result()->fetch_row();
            $stmt->close();
            return !($row && (string)$row[0] === '1');
        })($db),
    ],
];
