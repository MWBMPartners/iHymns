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
 *   _migProbe_tableExists, _migProbe_columnExists, _migProbe_indexExists,
 *   _migProbe_columnIsNullable, _migProbe_triggerExists,
 *   _migProbe_columnDataType (#1741 P1 — ENUM/SET->VARCHAR widening probes)
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

/* The FK expectations two of the probes below need (#1690).
 *
 * ELI5: the list of "which links to a song's id should exist" lives in one
 * place; the migration cards that create those links read it from there rather
 * than each keeping their own copy.
 *
 * Detail: `SONG_RELOCATE_EXPECTED_SONGID_FKS` + `songRelocateFksFixedBy()` are
 * declared in includes/song_relocate.php, which is where the songbook move
 * consumes them. Sourcing the probes from the same const is what stops a card's
 * pendency, the move's refusal and the migration's own DDL from drifting apart —
 * the exact drift that left `songid-prefix-fixup` permanently "already applied"
 * on an install whose four FKs were still RESTRICT (rule #35).
 *
 * Loading is side-effect-free: the file declares functions and constants, and
 * its own require of db_mysql.php only reads a credentials file if one exists —
 * it opens no connection. */
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes'
           . DIRECTORY_SEPARATOR . 'song_relocate.php';

/**
 * Does a live FK constraint exist on this table, and if so is its UPDATE_RULE
 * something other than CASCADE?
 *
 * ELI5: "is this link set up so a rename follows through?" — used by the two
 * cards that create or repair such links.
 *
 * Detail: returns FALSE when the constraint is absent (that is the other
 * probe's question) and TRUE only for a constraint that is really there and
 * really not cascading. Names are BOUND, never interpolated (rule #5).
 */
function _migProbe_fkUpdateRuleNotCascade(\mysqli $db, string $table, string $constraint): bool
{
    $stmt = $db->prepare(
        "SELECT 1 FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS
          WHERE CONSTRAINT_SCHEMA = DATABASE()
            AND TABLE_NAME        = ?
            AND CONSTRAINT_NAME   = ?
            AND UPDATE_RULE      <> 'CASCADE'
          LIMIT 1"
    );
    $stmt->bind_param('ss', $table, $constraint);
    $stmt->execute();
    $hit = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
    return $hit;
}

/**
 * Is a named FK constraint present in the live schema?
 *
 * ELI5: "has this link been created yet?"
 */
function _migProbe_constraintExists(\mysqli $db, string $table, string $constraint): bool
{
    $stmt = $db->prepare(
        "SELECT 1 FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
          WHERE CONSTRAINT_SCHEMA = DATABASE()
            AND TABLE_NAME        = ?
            AND CONSTRAINT_NAME   = ?
          LIMIT 1"
    );
    $stmt->bind_param('ss', $table, $constraint);
    $stmt->execute();
    $hit = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
    return $hit;
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
                /* @deleted-visible: migration probe (#1694) — backfill
                   completeness is PHYSICAL; a hidden song's ISWC still needs
                   its Work row so the link is intact on restore. */
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
                      . ' Nothing to regenerate afterwards — song reads are DB-direct'
                      . ' (WS-J #1020), so the public PWA picks up the new tags on its'
                      . ' next fetch.',
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
                /* @deleted-visible: migration probe (#1694) — language
                   backfill is PHYSICAL; a hidden row still needs the tag. */
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
    'songid-prefix-fixup' => [
        'script' => 'migrate-songid-prefix-fixup.php',
        'card' => [
            'title'  => 'Re-prefix SongIds whose SongbookAbbr no longer matches',
            'body'   => 'Before the songbook rename flow was patched to also re-prefix SongIds, renaming'
                      . ' an abbreviation (e.g. <code>HA → HAOLD</code> to free <code>HA</code> for a fresh'
                      . ' scrape) updated <code>tblSongs.SongbookAbbr</code> but left the SongIds carrying'
                      . ' the OLD prefix (<code>HA-0001</code> staying as <code>HA-0001</code> even though'
                      . ' the row now belongs to songbook <code>HAOLD</code>). A subsequent bulk-import of'
                      . ' the original abbreviation then collides on the SongId primary key. This migration'
                      . ' detects every row whose SongId prefix doesn\'t match its <code>SongbookAbbr</code>'
                      . ' and rewrites the prefix (re-prefixing the four child tables that lack'
                      . ' <code>ON UPDATE CASCADE</code> first, then <code>tblSongs.SongId</code>;'
                      . ' the other ~14 child FKs cascade automatically). Rows whose target SongId is'
                      . ' already taken are reported for manual merge rather than silently skipped.'
                      . ' Re-runnable.',
            'button' => 'Run SongId Prefix Fixup',
        ],
        /* TWO reasons to be pending, OR'd (#1690).
         *
         * (a) is the data fix the card was written for. (b) is the migration's
         * STEP 1, which runs unconditionally and adds ON UPDATE CASCADE to four
         * FKs created without it — and which the probe used to be blind to. On
         * an install with clean prefixes but RESTRICT FKs, (a) alone answered
         * "not pending", so the card sat in the collapsed "already applied"
         * expander, "Apply all pending" skipped it, and the only thing that
         * would repair those FKs was never offered — while /manage/editor
         * refused to move any song that had a media row, an external link, an
         * alternative title or a work membership. A probe must detect actual
         * completion of everything its migration does (rule #19), not just the
         * part that named the card.
         *
         * The four constraint names come from SONG_RELOCATE_EXPECTED_SONGID_FKS
         * so the probe, the move's refusal and the migration cannot disagree
         * about which FKs this card owns. */
        'probe' => static function (\mysqli $db): bool {
            /* Read OUTSIDE the try, deliberately. `catch (\Throwable)` would also
               swallow an `Error` from a missing/renamed helper, and this probe's
               fail-shape is `false` = "already applied" — so a typo would hide
               the card forever, which is the very failure #1690 fixed. A DB
               error degrades quietly; a code error must not. */
            $targets = songRelocateFksFixedBy('songid-prefix-fixup');
            try {
                /* (a) Pending whenever any row's SongId prefix disagrees with
                   its declared SongbookAbbr. Self-clears once the migration
                   has run. */
                /* @deleted-visible: migration probe (#1694) — prefix/abbr
                   agreement is PHYSICAL integrity; a hidden drifted row still
                   needs the fixup. */
                $res = $db->query(
                    "SELECT 1 FROM tblSongs
                      WHERE SongbookAbbr IS NOT NULL
                        AND SongbookAbbr <> ''
                        AND SongbookAbbr <> SUBSTRING_INDEX(SongId, '-', 1)
                      LIMIT 1"
                );
                $needs = $res && $res->fetch_row() !== null;
                if ($res) $res->close();
                if ($needs) { return true; }

                /* (b) …or whenever one of the four cascade targets is present
                   but still not ON UPDATE CASCADE. A constraint that is ABSENT
                   is not this card's business — the migration only re-ALTERs
                   existing FKs, and it reports a missing one as [skip]. */
                foreach ($targets as [$table, , $constraint, ]) {
                    if (_migProbe_fkUpdateRuleNotCascade($db, $table, $constraint)) { return true; }
                }
                return false;
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
    'audio-signing-setting' => [
        'script' => 'migrate-add-audio-signing-setting.php',
        'card' => [
            'title'  => 'Audio Signing Setting (#1358)',
            'body'   => 'Seeds the <code>audio_signing_enabled</code> flag into'
                      . ' <code>tblAppSettings</code> (default <code>0</code> = off). The'
                      . ' second of three dormancy switches for signed-audio sealing —'
                      . ' the others are <code>content_gating_enabled</code> and the'
                      . ' operator-provisioned <code>AUDIO_SIGNING_KEY</code> constant in'
                      . ' <code>.auth/</code>. With this at <code>0</code>, the'
                      . ' offline audio bundle keeps emitting the legacy'
                      . ' <code>/data/audio/&lt;id&gt;.mp3</code> literal (a verified'
                      . ' no-op). No tables or columns — a single non-destructive'
                      . ' <code>INSERT IGNORE</code>, so re-running NEVER resets a value'
                      . ' an operator has flipped to <code>1</code>. Idempotent.',
            'button' => 'Run Audio Signing Setting Migration',
        ],
        /* Pending until the seed row exists; applied once it does. The migration
           INSERT IGNOREs it, so presence == applied. Sentinel-style probe modelled
           on email-login-token-hashing above. */
        'probe' => static fn(\mysqli $db) => (function (\mysqli $db): bool {
            $stmt = $db->prepare(
                "SELECT 1 FROM tblAppSettings WHERE SettingKey = 'audio_signing_enabled' LIMIT 1"
            );
            $stmt->execute();
            $row = $stmt->get_result()->fetch_row();
            $stmt->close();
            return $row === null;   /* row absent → still pending */
        })($db),
    ],
    'places' => [
        'script' => 'migrate-places.php',
        'card' => [
            'title'  => 'Places Registry',
            'body'   => 'Creates <code>tblPlaces</code> as the canonical registry of geographic'
                      . ' locations (suburb / city / state / country) and adds'
                      . ' <code>BirthPlaceId</code> + <code>DeathPlaceId</code> FK columns to'
                      . ' <code>tblCreditPeople</code>. Powers the live location autocomplete'
                      . ' (Photon + Nominatim, both OpenStreetMap) in the Credit People edit'
                      . ' drawer\'s Birth / Death place fields so two curators picking'
                      . ' &ldquo;Sydney&rdquo; resolve to the same row instead of two'
                      . ' near-identical strings. Legacy <code>BirthPlace</code> /'
                      . ' <code>DeathPlace</code> VARCHAR columns stay alongside as'
                      . ' denormalised display strings so reports + read paths don\'t need a'
                      . ' JOIN on every read. Idempotent — safe to re-run.',
            'button' => 'Run Places Registry Migration',
        ],
        'probe' => static fn(\mysqli $db) => !_migProbe_tableExists($db, 'tblPlaces'),
    ],
    'places-adoption' => [
        'script' => 'migrate-places-adoption.php',
        'card' => [
            'title'  => 'Places Adoption Sweep',
            'body'   => 'Extends the Places registry FK pattern to four more entities so the'
                      . ' normalised place catalogue spreads beyond Credit People. Adds a'
                      . ' VARCHAR display-string mirror + nullable FK pair on each of'
                      . ' <code>tblSongbooks</code> (<code>PublicationCity</code> /'
                      . ' <code>PublicationCityId</code>),'
                      . ' <code>tblOrganisations</code> (<code>PhysicalCity</code> /'
                      . ' <code>PhysicalCityId</code>),'
                      . ' <code>tblSongs</code> (<code>OriginCity</code> /'
                      . ' <code>OriginCityId</code>) and'
                      . ' <code>tblWorks</code> (<code>OriginCity</code> /'
                      . ' <code>OriginCityId</code>). Unlocks the live location autocomplete'
                      . ' on the Songbook publisher / Organisation profile / Song editor /'
                      . ' Work editor forms. Idempotent — safe to re-run.',
            'button' => 'Run Places Adoption Migration',
        ],
        'probe' => static fn(\mysqli $db) => !_migProbe_columnExists($db, 'tblSongbooks', 'PublicationCityId'),
    ],
    'drop-songbook-name' => [
        'script' => 'migrate-drop-songbook-name.php',
        'card' => [
            'title'  => 'Drop denormalised SongbookName (WS-E #1013)',
            'body'   => 'Removes the denormalised <code>tblSongs.SongbookName</code> column. The'
                      . ' songbook name is now read live via JOIN to <code>tblSongbooks.Name</code>'
                      . ' everywhere, so the per-row copy is dead weight that also drifts when a'
                      . ' songbook is renamed. Data-preserving: BEFORE the drop it ensures every'
                      . ' <code>SongbookAbbr</code> has a <code>tblSongbooks</code> row and'
                      . ' backfills any empty <code>tblSongbooks.Name</code> from the denorm value,'
                      . ' and refuses to drop the column if that preservation step fails.'
                      . ' Idempotent — once the column is gone the migration is a no-op.',
            'button' => 'Drop denormalised SongbookName',
        ],
        /* Pending while the column STILL EXISTS (inverse of the ADD
           migrations above): a DROP is "applied" once the column is gone,
           so the probe self-clears after a successful run. */
        'probe' => static fn(\mysqli $db) => _migProbe_columnExists($db, 'tblSongs', 'SongbookName'),
    ],
    'user-data-sync' => [
        'script' => 'migrate-user-data-sync.php',
        'card' => [
            'title'  => 'User-data DB-first sync (WS-F/G #1018 / #1019)',
            'body'   => 'Adds the two schema pieces the DB-first + auto-sync rewrite of the'
                      . ' user data stores needs: <code>tblUserCustomTags</code> (per-user pool'
                      . ' of custom favourite-tag names — the DB-first counterpart of the'
                      . ' localStorage <code>ihymns_custom_tags</code> array, distinct from the'
                      . ' curator-managed global <code>tblSongTags</code>) and a'
                      . ' <code>Tags</code> JSON column on <code>tblUserFavorites</code> so each'
                      . ' favourite&rsquo;s per-song tags survive a cross-device sync (previously'
                      . ' the sync sent bare song IDs and silently dropped tags). Setlists,'
                      . ' favourites and history already have their server tables; this only'
                      . ' fills the gaps. Data-preserving + idempotent — safe to re-run.',
            'button' => 'Run User-data Sync Migration',
        ],
        /* Pending until BOTH pieces exist — the new table and the new
           column. Either missing keeps the card actionable so the
           dashboard counter only clears once the migration has fully
           landed. */
        'probe' => static fn(\mysqli $db) =>
            !_migProbe_tableExists($db, 'tblUserCustomTags')
            || !_migProbe_columnExists($db, 'tblUserFavorites', 'Tags'),
    ],
    'song-history-table' => [
        'script' => 'migrate-song-history.php',
        'card' => [
            'title'  => 'Recently-Viewed history table (#1236)',
            'body'   => 'Creates <code>tblSongHistory</code> on long-running installs that predate it'
                      . ' (fresh installs already get it from schema.sql). Without the table the'
                      . ' <code>song_history</code> API throws, returns <code>{ fallback: true }</code>,'
                      . ' and the client silently falls back to per-device <code>localStorage</code> — so a'
                      . ' signed-in user&rsquo;s Recently-Viewed list differs across devices. The client +'
                      . ' API (#546 / #549 / WS-G #1019) are already built; this only adds the missing'
                      . ' table. Additive + idempotent — safe to re-run.',
            'button' => 'Run Recently-Viewed History Migration',
        ],
        /* Pending until the table exists; self-clears once it has been created. */
        'probe' => static fn(\mysqli $db) => !_migProbe_tableExists($db, 'tblSongHistory'),
    ],
    'notification-scope-expiry' => [
        'script' => 'migrate-notification-scope-expiry.php',
        'card' => [
            'title'  => 'Notification scope + expiry (#1238)',
            'body'   => 'Adds <code>Environment</code> (NULL = all; alpha / beta / production = that env'
                      . ' only — the three environments share one DB) and <code>ExpiresAt</code>'
                      . ' (NULL = never) to <code>tblNotifications</code>, so an admin broadcast can'
                      . ' target a single environment and/or auto-expire. Additive + idempotent —'
                      . ' safe to re-run.',
            'button' => 'Run Notification Scope + Expiry Migration',
        ],
        /* Pending until BOTH columns exist; self-clears once they have landed. */
        'probe' => static fn(\mysqli $db) =>
            !_migProbe_columnExists($db, 'tblNotifications', 'Environment')
            || !_migProbe_columnExists($db, 'tblNotifications', 'ExpiresAt'),
    ],
    'lyric-lines-mirror' => [
        'script' => 'migrate-lyric-lines-mirror.php',
        'card' => [
            'title'  => 'Lyric-lines mirror: chords/notes + backfill (#1235 P1)',
            'body'   => 'Lyric-line normalisation Phase 1 (Option 1). Adds the two per-line homes'
                      . ' <code>tblLyricLines</code> still needed — <code>ChordsJson</code> +'
                      . ' <code>Note</code> — then backfills the WHOLE catalogue into'
                      . ' <code>tblLyricLines</code> from the authoritative <code>tblSongComponents</code>'
                      . ' (line text + part identity + chords + notes + per-component language),'
                      . ' <strong>in place, no re-import</strong>. <code>tblSongComponents</code> stays'
                      . ' authoritative + reads are unchanged; the mirror is the future single source of'
                      . ' truth. Additive + idempotent (delete + reinsert per song) — safe to re-run.',
            'button' => 'Run Lyric-lines Mirror Migration',
        ],
        /* Pending until BOTH per-line columns exist; self-clears once they land.
           (The backfill is idempotent, so re-running after an edit is safe.) */
        'probe' => static fn(\mysqli $db) =>
            !_migProbe_columnExists($db, 'tblLyricLines', 'ChordsJson')
            || !_migProbe_columnExists($db, 'tblLyricLines', 'Note'),
    ],

    'component-line-languages' => [
        'script' => 'migrate-component-line-languages.php',
        'card' => [
            'title'  => 'Per-line language: tblSongComponents.LanguagesJson (#1235 P3)',
            'body'   => 'Adds <code>tblSongComponents.LanguagesJson</code> — a per-line language'
                      . ' parallel array (like <code>ChordsJson</code> / <code>NotesJson</code>) giving'
                      . ' per-line language overrides a durable home that survives lyric-line'
                      . ' reprojection. A line without one inherits the component language'
                      . ' (which inherits <code>tblSongs.Language</code>). Strictly additive +'
                      . ' idempotent, no backfill.',
            'button' => 'Add Per-line Language Column',
        ],
        /* Pending until the column lands; self-clears once it exists. #1235 P4/C6
           resurrection guard: pending only while the JSON era is active (LinesJson present),
           so the C6 drop of LanguagesJson is never undone by an "Apply all" re-run. */
        'probe' => static fn(\mysqli $db) =>
            _migProbe_columnExists($db, 'tblSongComponents', 'LinesJson')
            && !_migProbe_columnExists($db, 'tblSongComponents', 'LanguagesJson'),
    ],

    /* ---- iLyricsDB alignment, pre-deploy (#1044 / #1045 / #1046) ----------
       Shape-readiness so the iHymns DB can become the shared iLyricsDB core
       without a second re-architecture. All three are strictly additive. */
    'songbook-entries' => [
        'script' => 'migrate-add-songbook-entries.php',
        'card' => [
            'title'  => 'Songbook Entries N:N junction (#1044)',
            'body'   => 'Creates <code>tblSongbookEntries</code> (many-to-many song↔songbook) and'
                      . ' backfills one <em>home</em> entry per song from'
                      . ' <code>(SongbookAbbr, Number)</code>. Lets a hymn live in several'
                      . ' hymnals with different numbers, and a future non-Christian song have'
                      . ' zero entries (owned via its artist). Strictly additive —'
                      . ' <code>SongbookAbbr</code> / <code>Number</code> / the <code>SongId</code>'
                      . ' prefix are untouched and nothing reads the junction yet. Idempotent.',
            'button' => 'Run Songbook Entries Migration',
        ],
        'probe' => static fn(\mysqli $db) => !_migProbe_tableExists($db, 'tblSongbookEntries'),
    ],
    'songbook-ischristian' => [
        'script' => 'migrate-songbook-ischristian.php',
        'card' => [
            'title'  => 'Songbook IsChristian filter axis (#1045)',
            'body'   => 'Adds <code>tblSongbooks.IsChristian</code> (default 1, indexed) — the'
                      . ' machine axis that lets iHymns surface only Christian lyrics'
                      . ' (<code>WHERE IsChristian=1</code>) from a shared generic corpus, while'
                      . ' the iLyricsDB core applies no filter. Every existing songbook defaults'
                      . ' to Christian. Idempotent — safe to re-run.',
            'button' => 'Run Songbook IsChristian Migration',
        ],
        'probe' => static fn(\mysqli $db) => !_migProbe_columnExists($db, 'tblSongbooks', 'IsChristian'),
    ],
    'song-explicit-genre' => [
        'script' => 'migrate-song-explicit-genre.php',
        'card' => [
            'title'  => 'Song IsExplicit + Genre (#1046)',
            'body'   => 'Adds two cheap additive columns the generic model carries:'
                      . ' <code>tblSongs.IsExplicit</code> (default 0) and'
                      . ' <code>tblSongs.Genre</code> (free-text, indexed). Genre is a secondary'
                      . ' classification (Hymn / Contemporary Worship / …), NOT the Christian'
                      . ' filter (that is <code>tblSongbooks.IsChristian</code>). Idempotent.',
            'button' => 'Run Song IsExplicit + Genre Migration',
        ],
        'probe' => static fn(\mysqli $db) => !_migProbe_columnExists($db, 'tblSongs', 'IsExplicit'),
    ],
    'normalize-lyrics' => [
        'script' => 'migrate-normalize-lyrics.php',
        'card' => [
            'title'  => 'Normalised lyrics model (#1047)',
            'body'   => 'Creates <code>tblLyrics</code> / <code>tblLyricLines</code> /'
                      . ' <code>tblLyricWords</code> — the generic, timing-capable lyrics model'
                      . ' the shared iLyricsDB core uses and that TTML / word-timing ingest'
                      . ' depends on. Backfills one primary &ldquo;ihymns&rdquo; lyrics per song'
                      . ' and its lines from <code>tblSongComponents.LinesJson</code>.'
                      . ' <strong>Additive</strong> — <code>tblSongComponents</code> stays'
                      . ' authoritative and no read path changes yet;'
                      . ' <code>tblLyricWords</code> starts empty for future timed imports.'
                      . ' Idempotent — safe to re-run.',
            'button' => 'Run Normalised Lyrics Migration',
        ],
        'probe' => static fn(\mysqli $db) => !_migProbe_tableExists($db, 'tblLyrics'),
    ],
    'lyric-syllables' => [
        'script' => 'migrate-lyric-syllables.php',
        'card' => [
            'title'  => 'Syllable timing + TTML metadata (#1047 / iLyricsDB #141)',
            'body'   => 'Completes the timing model line → word → <strong>syllable</strong>:'
                      . ' creates <code>tblLyricSyllables</code> (FK to <code>tblLyricWords</code>),'
                      . ' adds <code>tblLyrics.HasSyllableTiming</code>, and adds a lossless'
                      . ' <code>MetaJson</code> column to <code>tblLyricLines</code> /'
                      . ' <code>tblLyricWords</code> / <code>tblLyricSyllables</code> so Apple'
                      . ' Music syllable-synced TTML attributes (ttm:role, itunes:key,'
                      . ' background-vocal, agent) are never discarded on import. Empty until'
                      . ' timed imports populate it. Additive + idempotent.',
            'button' => 'Run Syllable Timing Migration',
        ],
        'probe' => static fn(\mysqli $db) => !_migProbe_tableExists($db, 'tblLyricSyllables'),
    ],

    'api-keys' => [
        'script' => 'migrate-api-keys.php',
        'card' => [
            'title'  => 'API keys (machine-to-machine) (#1064)',
            'body'   => 'Creates <code>tblApiKeys</code> so external services (e.g. MeedyaDL)'
                      . ' can authenticate to the lyrics-ingest endpoint with a per-client,'
                      . ' SHA-256-hashed, scoped, revocable key instead of a session. The raw'
                      . ' key is shown once at creation and never stored. Manage keys at'
                      . ' <code>/manage/api-keys</code>. Additive + idempotent.',
            'button' => 'Run API Keys Migration',
        ],
        'probe' => static fn(\mysqli $db) => !_migProbe_tableExists($db, 'tblApiKeys'),
    ],

    'song-recording-ids' => [
        'script' => 'migrate-song-recording-ids.php',
        'card' => [
            'title'  => 'Song recording IDs — ISRC/UPC (#1064)',
            'body'   => 'Adds <code>tblSongs.Isrc</code> + <code>tblSongs.Upc</code> (indexed ISRC)'
                      . ' so the lyrics-ingest endpoint can store the recording/release identifiers'
                      . ' MeedyaDL supplies, and the duplicate-songs review page can detect songs that'
                      . ' share an ISRC. Additive + idempotent.',
            'button' => 'Run Song Recording IDs Migration',
        ],
        'probe' => static fn(\mysqli $db) => !_migProbe_columnExists($db, 'tblSongs', 'Isrc'),
    ],

    'song-softref-fks' => [
        'script' => 'migrate-song-softref-fks.php',
        'card' => [
            'title'  => 'Harden soft SongId references (FKs) (#1064)',
            'body'   => 'Adds real FOREIGN KEY constraints to the three columns that referenced'
                      . ' <code>tblSongs.SongId</code> without one'
                      . ' (<code>tblSongRequests.ResolvedSongId</code> → SET NULL;'
                      . ' <code>tblSongRevisions.SongId</code> and'
                      . ' <code>tblSongLinkSuggestionsDismissed.SongIdA/B</code> → CASCADE), after'
                      . ' cleaning any dangling values. The database now guarantees no orphaned'
                      . ' SongId references. Idempotent.',
            'button' => 'Run Soft-Reference FK Hardening',
        ],
        /* A MULTI-OBJECT probe: pending until ALL FOUR constraints exist (#1690).
         *
         * It used to ask about the "representative" fk_Revisions_Song alone, so
         * a PARTIAL apply — the revisions FK added, then one of the dismissed-
         * suggestion ALTERs failing on dangling data — showed the card green
         * with two FKs still missing. That is precisely the multi-object case
         * rule #19 requires an OR-probe for, and it matters more than usual
         * here: a column with no FK leaves no INFORMATION_SCHEMA row at all, so
         * a songbook move stranded every row in it SILENTLY.
         *
         * Each constraint is gated on its own table+column existing, because
         * the migration itself only adds an FK to a column that is there — a
         * minimal install missing tblSongLinkSuggestionsDismissed entirely must
         * not be reported as pending forever. Names come from
         * SONG_RELOCATE_EXPECTED_SONGID_FKS (rule #35). */
        'probe' => static function (\mysqli $db): bool {
            /* Outside the try — see the songid-prefix-fixup probe for why a code
               error must not degrade to "already applied". */
            $targets = songRelocateFksFixedBy('song-softref-fks');
            try {
                foreach ($targets as [$table, $column, $constraint, ]) {
                    if (!_migProbe_columnExists($db, $table, $column))         { continue; }
                    if (!_migProbe_constraintExists($db, $table, $constraint)) { return true; }
                }
                return false;
            } catch (\Throwable $_e) {
                return false;
            }
        },
    ],

    /* ----------------------------------------------------------------------
     * #1066 — one-pass forward-looking schema for interchange fidelity, ingest
     * hardening, identity map. Additive + dormant until the consuming features
     * land; shipped together to avoid a second migration round.
     * -------------------------------------------------------------------- */
    'interchange-fidelity' => [
        'script' => 'migrate-interchange-fidelity.php',
        'card' => [
            'title'  => 'Interchange fidelity — chords/notes/arrangements (#1066)',
            'body'   => 'Adds <code>tblSongComponents.ChordsJson</code> +'
                      . ' <code>NotesJson</code> (lossless chord + presenter-note'
                      . ' round-trip) and creates <code>tblSongArrangements</code>'
                      . ' (named component reorderings the PP7 exporter reads via'
                      . ' <code>IsDefault=1</code>). Additive + idempotent.',
            'button' => 'Run Interchange Fidelity Migration',
        ],
        /* #1235 P4/C6 resurrection guard: "pending" only while the JSON era is active
           (tblSongComponents.LinesJson present). Once C6 drops the payload columns,
           LinesJson is gone and this must NOT report pending — re-running would re-ADD the
           retired ChordsJson. (tblSongArrangements, also created here, survives independently
           and is in schema.sql, so a fresh post-C6 install gets it without this migration.) */
        'probe' => static fn(\mysqli $db) =>
            _migProbe_columnExists($db, 'tblSongComponents', 'LinesJson')
            && !_migProbe_columnExists($db, 'tblSongComponents', 'ChordsJson'),
    ],

    'ingest-review-queue' => [
        'script' => 'migrate-ingest-review-queue.php',
        'card' => [
            'title'  => 'Lyrics review queue + conflicts (#1066)',
            'body'   => 'Creates <code>tblLyricsConflicts</code> +'
                      . ' <code>tblLyricsReviewQueue</code> — a moderation gate'
                      . ' between lyrics ingest and the public read path, so a'
                      . ' conflicting incoming version is queued for review instead'
                      . ' of silently overwriting curated data. Additive + idempotent.',
            'button' => 'Run Lyrics Review Queue Migration',
        ],
        'probe' => static fn(\mysqli $db) => !_migProbe_tableExists($db, 'tblLyricsReviewQueue'),
    ],

    'api-key-hardening' => [
        'script' => 'migrate-api-key-hardening.php',
        'card' => [
            'title'  => 'API-key hardening — rate limits + idempotency (#1066)',
            'body'   => 'Adds <code>tblApiKeys.RateLimitPerMin/PerDay</code> and'
                      . ' creates <code>tblApiKeyUsage</code> (rolling counters,'
                      . ' per-scope-ready) + <code>tblApiKeyIdempotency</code>'
                      . ' (safe-retry response cache) for the ingest endpoint.'
                      . ' Additive + idempotent.',
            'button' => 'Run API-Key Hardening Migration',
        ],
        'probe' => static fn(\mysqli $db) => !_migProbe_columnExists($db, 'tblApiKeys', 'RateLimitPerMin'),
    ],

    'song-normalized-title' => [
        'script' => 'migrate-song-normalized-title.php',
        'card' => [
            'title'  => 'NormalizedTitle dedup column (#1066)',
            'body'   => 'Adds <code>tblSongs.NormalizedTitle</code> (indexed,'
                      . ' app-maintained fold of <code>Title</code>) and backfills'
                      . ' every song, so duplicate detection + ingest resolution'
                      . ' pre-filter on the index instead of normalising row-by-row'
                      . ' in PHP. Additive + idempotent (backfill re-runnable).',
            'button' => 'Run NormalizedTitle Migration',
        ],
        'probe' => static fn(\mysqli $db) => !_migProbe_columnExists($db, 'tblSongs', 'NormalizedTitle'),
    ],

    'activity-log-observability' => [
        'script' => 'migrate-activity-log-observability.php',
        'card' => [
            'title'  => 'Activity Log observability columns (#1207)',
            'body'   => 'Adds <code>Environment</code> / <code>RequestPath</code> / '
                      . '<code>Referrer</code> / <code>Country</code> to <code>tblActivityLog</code> '
                      . '— the shared DB serves all three environments, so each row records which '
                      . 'env / file / referrer / country it came from. Also adds dormant geo-cache '
                      . 'columns to <code>tblIpReputation</code> for the IP→country resolver (#1208). '
                      . 'Strictly additive + idempotent.',
            'button' => 'Run Activity Log Observability Migration',
        ],
        /* OR-probe (rule #19): pending until EVERY added column exists, so a
           partial apply re-applies instead of showing the card green. */
        'probe' => static fn(\mysqli $db) =>
               !_migProbe_columnExists($db, 'tblActivityLog', 'Environment')
            || !_migProbe_columnExists($db, 'tblActivityLog', 'RequestPath')
            || !_migProbe_columnExists($db, 'tblActivityLog', 'Referrer')
            || !_migProbe_columnExists($db, 'tblActivityLog', 'Country')
            || !_migProbe_columnExists($db, 'tblIpReputation', 'CountryCode')
            || !_migProbe_columnExists($db, 'tblIpReputation', 'CountryName')
            || !_migProbe_columnExists($db, 'tblIpReputation', 'GeoLookedUpAt'),
    ],

    'song-link-confidence' => [
        'script' => 'migrate-song-link-confidence.php',
        'card' => [
            'title'  => 'Song-link confidence tiers (#1066)',
            'body'   => 'Adds <code>tblSongLinkSuggestions.Confidence</code>'
                      . ' (high/medium/low) + <code>Signal</code> (detecting'
                      . ' method, e.g. shared-isrc) so curators triage strong-key'
                      . ' matches ahead of fuzzy title guesses. Additive + idempotent.',
            'button' => 'Run Song-Link Confidence Migration',
        ],
        /* Multi-column OR-probe (rule #19): pending until BOTH columns exist.
           A single-column probe on 'Confidence' alone left the card green when
           a partial apply added Confidence but not Signal — Apply-all then
           never re-ran it to add the missing Signal (the addCol calls are each
           idempotent, so a re-run safely adds only what's absent). */
        'probe' => static fn(\mysqli $db) =>
               !_migProbe_columnExists($db, 'tblSongLinkSuggestions', 'Confidence')
            || !_migProbe_columnExists($db, 'tblSongLinkSuggestions', 'Signal'),
    ],

    'song-identity-map' => [
        'script' => 'migrate-song-identity-map.php',
        'card' => [
            'title'  => 'Cross-system identity map + Christian view (#1066)',
            'body'   => 'Adds <code>tblWorks.MusicBrainzWorkMBID</code> (composition'
                      . ' identity), creates <code>tblSongIdentityMap</code>'
                      . ' (recording identity: ISRC/MusicBrainz/Spotify/Genius) and'
                      . ' the <code>v_ChristianSongs</code> read fence. The iLyricsDB'
                      . ' bridge stays gated on the DB-merge decision. Additive + idempotent.',
            'button' => 'Run Identity Map Migration',
        ],
        /* Pending until ALL THREE objects this migration creates exist — the
         * table, the tblWorks column, and the view — so a partial apply (table
         * created but a later step threw) never shows the card as green. */
        'probe' => static function (\mysqli $db): bool {
            if (!_migProbe_tableExists($db, 'tblSongIdentityMap')) {
                return true;
            }
            if (!_migProbe_columnExists($db, 'tblWorks', 'MusicBrainzWorkMBID')) {
                return true;
            }
            $v = $db->query(
                "SELECT 1 FROM INFORMATION_SCHEMA.VIEWS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = 'v_ChristianSongs' LIMIT 1"
            );
            return !($v && $v->num_rows > 0);
        },
    ],

    /* ----------------------------------------------------------------------
     * #1088 — per-line lyric enrichment: translations + Genius-style
     * annotations, anchored on tblLyricLines.Id. Additive + dormant until the
     * ingest/UI/display feature work lands.
     * -------------------------------------------------------------------- */
    'line-enrichment' => [
        'script' => 'migrate-line-enrichment.php',
        'card' => [
            'title'  => 'Per-line lyric enrichment — translations + annotations (#1088)',
            'body'   => 'Creates <code>tblLyricLineTranslations</code> (per-line meaning'
                      . ' translation + romanization, modelling the Apple Music TTML'
                      . ' <code>&lt;translation&gt;</code>/<code>&lt;transliteration&gt;</code> tracks)'
                      . ' and <code>tblLyricLineAnnotations</code> (Genius-style explanatory'
                      . ' gloss over a line/phrase span). Both anchor on'
                      . ' <code>tblLyricLines.Id</code>. Additive + idempotent.',
            'button' => 'Run Line Enrichment Migration',
        ],
        /* Pending until BOTH tables exist (multi-table OR-form). */
        'probe' => static fn(\mysqli $db) =>
            !_migProbe_tableExists($db, 'tblLyricLineTranslations')
            || !_migProbe_tableExists($db, 'tblLyricLineAnnotations'),
    ],

    /* ----------------------------------------------------------------------
     * #1090 — enhancement-foundation batch: forward-looking, additive, dormant
     * schema for the next program phase (tune entity, CCLI usage spine, corpus
     * linter, annotation voting, embeddings, live-follow) + request-corrections
     * + ENUM->VARCHAR hardening. Deployable ahead of the consuming features.
     * -------------------------------------------------------------------- */
    'tunes-entity' => [
        'script' => 'migrate-tunes-entity.php',
        'card' => [
            'title'  => 'Tune + meter entity (#1090 P4)',
            'body'   => 'Creates <code>tblTunes</code> + <code>tblTuneAliases</code>'
                      . ' and adds <code>tblSongs.TuneId</code> (FK), promoting the'
                      . ' hymn tune from free-text <code>TuneName</code> to a'
                      . ' first-class entity (metre, MusicBrainz Work). Backfills'
                      . ' from distinct tune names + links songs. Additive + idempotent.',
            'button' => 'Run Tune Entity Migration',
        ],
        'probe' => static fn(\mysqli $db) =>
            !_migProbe_tableExists($db, 'tblTunes')
            || !_migProbe_columnExists($db, 'tblSongs', 'TuneId'),
    ],

    /* ----------------------------------------------------------------------
     * Epic #1741 P1 — additive/idempotent/dormant schema batch for the
     * MusicBrainz-shaped catalogue expansion (Musicians/Works/Tunes/Songs).
     * Four cards, one per entity family, per
     * .claude/catalogue-expansion-1741-plan.md §2. Nothing reads or writes
     * any of these new columns/tables yet — zero behaviour change until the
     * later phases (P2 rename, P3 resolver, P4 pages, P5 editor) consume
     * them. 'works-identity' and 'tune-enrichment' both FK to tblTunes, so
     * they are placed AFTER 'tunes-entity' above; each also tolerates being
     * run before it (skip-and-warn on the FK / whole script respectively)
     * rather than erroring, so out-of-order clicks are safe.
     * -------------------------------------------------------------------- */
    'musician-profile' => [
        'script' => 'migrate-musician-profile.php',
        'card' => [
            'title'  => 'Musician profile schema (#1741 P1)',
            'body'   => 'Adds <code>tblCreditPeople.Type/Disambiguation/Biography</code>'
                      . ' (entity-type vocabulary + bio field, backfilled from'
                      . ' <code>IsGroup</code>/<code>IsSpecialCase</code>/<code>Notes</code> — Notes is'
                      . ' copied, not cleared, so the live bio on <code>/people/&lt;slug&gt;</code> keeps'
                      . ' working unchanged), extends <code>tblCreditPersonMembers</code> with a dated'
                      . ' <code>RelationType</code> (member | portrays) and re-keys its UNIQUE constraint,'
                      . ' and widens <code>tblCreditPersonAliases.Type</code> from ENUM to VARCHAR.'
                      . ' Additive, idempotent, dormant — safe to re-run.',
            'button' => 'Run Musician Profile Migration',
        ],
        /* Multi-object OR-probe (rule #19): pending until every column, the
           re-keyed UNIQUE (new one present AND old one gone), and the
           widened alias Type all agree the migration fully landed. */
        'probe' => static fn(\mysqli $db) =>
               !_migProbe_columnExists($db, 'tblCreditPeople', 'Type')
            || !_migProbe_columnExists($db, 'tblCreditPeople', 'Disambiguation')
            || !_migProbe_columnExists($db, 'tblCreditPeople', 'Biography')
            || !_migProbe_columnExists($db, 'tblCreditPersonMembers', 'RelationType')
            || !_migProbe_columnExists($db, 'tblCreditPersonMembers', 'DateFrom')
            || !_migProbe_columnExists($db, 'tblCreditPersonMembers', 'DateFromPrecision')
            || !_migProbe_columnExists($db, 'tblCreditPersonMembers', 'DateTo')
            || !_migProbe_columnExists($db, 'tblCreditPersonMembers', 'DateToPrecision')
            || !_migProbe_columnExists($db, 'tblCreditPersonMembers', 'Note')
            || !_migProbe_columnExists($db, 'tblCreditPersonMembers', 'UpdatedAt')
            || !_migProbe_indexExists($db, 'tblCreditPersonMembers', 'uq_group_member_rel')
            || _migProbe_indexExists($db, 'tblCreditPersonMembers', 'uq_group_member')
            || _migProbe_columnDataType($db, 'tblCreditPersonAliases', 'Type') !== 'varchar',
    ],

    'works-identity' => [
        'script' => 'migrate-works-identity.php',
        'card' => [
            'title'  => 'Works identity schema (#1741 P1)',
            'body'   => 'Adds <code>tblWorks.Ccli</code> (+ <code>uq_ccli</code>),'
                      . ' <code>Subtitle</code>, <code>Disambiguation</code>,'
                      . ' <code>TuneName</code>/<code>TuneId</code> (+ <code>idx_TuneId</code> and the'
                      . ' trailing <code>fk_Works_Tune</code> FK), <code>FirstPublishedYear</code>, and'
                      . ' <code>CopyrightYears</code>/<code>CopyrightHolder</code> — the Work side of the'
                      . ' catalogue-identity expansion, mirroring the tblSongs/tblTunes shapes. The FK'
                      . ' needs <code>tblTunes</code> to exist first (run &ldquo;Tune + meter entity&rdquo;'
                      . ' above if this card warns about it); every other column applies regardless.'
                      . ' Additive, idempotent, dormant — safe to re-run.',
            'button' => 'Run Works Identity Migration',
        ],
        /* Multi-object OR-probe. fk_Works_Tune can never exist before
           tblTunes does, so — correctly — this card stays pending on an
           install that hasn't run 'tunes-entity' yet, even though every
           column this script CAN apply already has been; the registry
           ORDER (this entry after 'tunes-entity') is what resolves that
           for "Apply all pending". */
        'probe' => static fn(\mysqli $db) =>
               !_migProbe_columnExists($db, 'tblWorks', 'Ccli')
            || !_migProbe_columnExists($db, 'tblWorks', 'Subtitle')
            || !_migProbe_columnExists($db, 'tblWorks', 'Disambiguation')
            || !_migProbe_columnExists($db, 'tblWorks', 'TuneName')
            || !_migProbe_columnExists($db, 'tblWorks', 'TuneId')
            || !_migProbe_columnExists($db, 'tblWorks', 'FirstPublishedYear')
            || !_migProbe_columnExists($db, 'tblWorks', 'CopyrightYears')
            || !_migProbe_columnExists($db, 'tblWorks', 'CopyrightHolder')
            || !_migProbe_indexExists($db, 'tblWorks', 'idx_TuneId')
            || !_migProbe_indexExists($db, 'tblWorks', 'uq_ccli')
            || !_migProbe_constraintExists($db, 'tblWorks', 'fk_Works_Tune'),
    ],

    'tune-enrichment' => [
        'script' => 'migrate-tune-enrichment.php',
        'card' => [
            'title'  => 'Tune enrichment schema (#1741 P1)',
            'body'   => 'Adds <code>tblTunes.Subtitle</code>/<code>Disambiguation</code>, creates'
                      . ' <code>tblTuneCredits</code> (Role-discriminated composer/arranger/harmoniser/'
                      . 'source credits, with a reserved dormant <code>CreditPersonId</code> FK) and'
                      . ' <code>tblTuneExternalLinks</code> (mirrors'
                      . ' <code>tblWorkExternalLinks</code>), and widens'
                      . ' <code>tblExternalLinkTypes.Category</code> (ENUM) and'
                      . ' <code>.AppliesTo</code> (SET) to VARCHAR so a future <code>tune</code> value never'
                      . ' needs its own ALTER. Requires <code>tblTunes</code> to exist — run &ldquo;Tune +'
                      . ' meter entity&rdquo; above first if this card warns about it. Additive, idempotent,'
                      . ' dormant — safe to re-run.',
            'button' => 'Run Tune Enrichment Migration',
        ],
        /* Multi-object OR-probe. The whole script is gated on tblTunes
           existing (unlike works-identity, every object here either ALTERs
           tblTunes or FKs to it), so — correctly — this stays pending until
           'tunes-entity' has run; the registry order resolves that. */
        'probe' => static fn(\mysqli $db) =>
               !_migProbe_columnExists($db, 'tblTunes', 'Subtitle')
            || !_migProbe_columnExists($db, 'tblTunes', 'Disambiguation')
            || !_migProbe_tableExists($db, 'tblTuneCredits')
            || !_migProbe_tableExists($db, 'tblTuneExternalLinks')
            || _migProbe_columnDataType($db, 'tblExternalLinkTypes', 'Category') !== 'varchar'
            || _migProbe_columnDataType($db, 'tblExternalLinkTypes', 'AppliesTo') !== 'varchar',
    ],

    'song-identity-fields' => [
        'script' => 'migrate-song-identity-fields.php',
        'card' => [
            'title'  => 'Song identity fields (#1741 P1)',
            'body'   => 'Adds <code>tblSongs.Subtitle</code>, <code>Disambiguation</code>,'
                      . ' <code>CopyrightYears</code>/<code>CopyrightHolder</code> (legacy'
                      . ' <code>Copyright</code> kept as-is, not auto-parsed), and'
                      . ' <code>FirstPublishedYear</code>; canonicalises existing'
                      . ' <code>Iswc</code> (to <code>T-NNN.NNN.NNN-C</code>) and <code>Isrc</code> (to a'
                      . ' bare 12-character code) values in place, then adds'
                      . ' <code>idx_Iswc</code>/<code>idx_Ccli</code> so the future /iswc/ and /ccli/'
                      . ' resolvers get an indexed exact match. Unparseable identifier values are left'
                      . ' untouched and logged, never guessed at. Additive, idempotent, dormant — safe'
                      . ' to re-run.',
            'button' => 'Run Song Identity Fields Migration',
        ],
        /* Multi-object OR-probe. The two indexes are created LAST in the
           migration (after both backfills), so their presence is the
           probe's proof the backfills also completed — rule #19 step
           order. */
        'probe' => static fn(\mysqli $db) =>
               !_migProbe_columnExists($db, 'tblSongs', 'Subtitle')
            || !_migProbe_columnExists($db, 'tblSongs', 'Disambiguation')
            || !_migProbe_columnExists($db, 'tblSongs', 'CopyrightYears')
            || !_migProbe_columnExists($db, 'tblSongs', 'CopyrightHolder')
            || !_migProbe_columnExists($db, 'tblSongs', 'FirstPublishedYear')
            || !_migProbe_indexExists($db, 'tblSongs', 'idx_Iswc')
            || !_migProbe_indexExists($db, 'tblSongs', 'idx_Ccli'),
    ],

    /* ----------------------------------------------------------------------
     * Epic #1741 P2-A — Musicians rename: SCHEMA + compat views ONLY. App
     * code (routes/actions/log keys/PHP+JS identifiers/entitlement name) is
     * NOT touched here — that is #1741 P2-B, a deliberately separate, later
     * step. Placed immediately after the 4 P1 entries above because it
     * REQUIRES all four to be fully applied first (migrate-musicians-rename
     * .php's own precondition gate refuses to run otherwise, since
     * tune-enrichment's tblTuneCredits carries a foreign key to
     * tblCreditPeople, and a foreign key may only ever reference a base
     * table — never a view, which is what tblCreditPeople becomes the
     * moment this card lands).
     * -------------------------------------------------------------------- */
    'musicians-rename' => [
        'script' => 'migrate-musicians-rename.php',
        'card' => [
            'title'  => 'Musicians rename — schema + compat views (#1741 P2)',
            'body'   => 'Renames the 7 <code>tblCreditPe*/tblCreditPerson*</code> tables to'
                      . ' <code>tblMusician*</code>, renames every <code>CreditPersonId</code>-family'
                      . ' column to <code>MusicianId</code> (or <code>Subject/ObjectMusicianId</code> on'
                      . ' the relation table, renamed from <code>tblCreditPersonMembers</code> to'
                      . ' <code>tblMusicianRelations</code>), renames the indexes/FK constraints that'
                      . ' named the old vocabulary, and creates an UPDATABLE compat <code>VIEW</code>'
                      . ' under every OLD table name so existing app code — every current read and'
                      . ' write against <code>tblCreditPeople</code> etc. — keeps working completely'
                      . ' unchanged. Also widens <code>tblExternalLinkTypes.AppliesTo</code> to carry a'
                      . ' <code>musician</code> token ALONGSIDE the retained legacy <code>person</code>'
                      . ' token (never a destructive rewrite — see the migration file for why).'
                      . ' <strong>Requires</strong> all four #1741 P1 cards above (Musician profile /'
                      . ' Works identity / Tune enrichment / Song identity fields) to be fully applied'
                      . ' first; refuses to run and explains why otherwise. Idempotent — safe to'
                      . ' re-run. The compat views are permanent until a dedicated later drop card'
                      . ' (#1741 P2b, not this one) runs once every docroot serves renamed app code.',
            'button' => 'Run Musicians Rename Migration',
        ],
        /* Multi-object OR-probe (rule #19): pending until the table rename, every
           FK-carrying table's column rename, the re-keyed relation UNIQUE index, a
           sample renamed FK constraint (proof Step 4 landed, not just Step 3), AND
           the AppliesTo back-compat token addition have ALL completed.
           NOTE the AppliesTo check is deliberately NOT "has the legacy 'person'
           token been removed" — this migration's whole point is that 'person' is
           KEPT permanently (see migrate-musicians-rename.php's file doc-block) so
           old app code's FIND_IN_SET('person', …) calls never stop matching. The
           real completion signal is "does every row that carries 'person' also
           carry 'musician'" — mirroring the migration's own UPDATE …WHERE guard. */
        'probe' => static function (\mysqli $db): bool {
            if (!_migProbe_tableExists($db, 'tblMusicians'))                              { return true; }
            if (!_migProbe_columnExists($db, 'tblMusicianIdentifiers', 'MusicianId'))     { return true; }
            if (!_migProbe_columnExists($db, 'tblMusicianExternalLinks', 'MusicianId'))   { return true; }
            if (!_migProbe_columnExists($db, 'tblMusicianAliases', 'MusicianId'))         { return true; }
            if (!_migProbe_columnExists($db, 'tblMusicianLinks', 'MusicianId'))           { return true; }
            if (!_migProbe_columnExists($db, 'tblMusicianIPI', 'MusicianId'))             { return true; }
            if (!_migProbe_columnExists($db, 'tblMusicianRelations', 'SubjectMusicianId')){ return true; }
            if (!_migProbe_columnExists($db, 'tblMusicianRelations', 'ObjectMusicianId')) { return true; }
            if (!_migProbe_columnExists($db, 'tblSongbookCompilers', 'MusicianId'))       { return true; }
            if (!_migProbe_columnExists($db, 'tblTuneCredits', 'MusicianId'))             { return true; }
            if (!_migProbe_columnExists($db, 'tblVocalParts', 'MusicianId'))              { return true; }
            if (!_migProbe_indexExists($db, 'tblMusicianRelations', 'uq_subject_object_rel')) { return true; }
            if (!_migProbe_constraintExists($db, 'tblMusicians', 'fk_Musicians_BirthPlace'))  { return true; }
            try {
                if (!_migProbe_tableExists($db, 'tblExternalLinkTypes')) { return false; }
                $res = $db->query(
                    "SELECT 1 FROM tblExternalLinkTypes
                      WHERE FIND_IN_SET('person', AppliesTo) > 0
                        AND FIND_IN_SET('musician', AppliesTo) = 0
                      LIMIT 1"
                );
                $needsToken = $res && $res->fetch_row() !== null;
                if ($res) { $res->close(); }
                return $needsToken;
            } catch (\Throwable $_e) { return false; }
        },
    ],

    'usage-events' => [
        'script' => 'migrate-usage-events.php',
        'card' => [
            'title'  => 'Song usage-event log (#1090 P5)',
            'body'   => 'Creates <code>tblSongUsageEvents</code> — the reportable'
                      . ' "song used on date, in context, by org" spine that backs'
                      . ' CCLI / CCS / OneLicense usage reports. Dormant until the'
                      . ' projection/print/schedule write-hooks land. Additive + idempotent.',
            'button' => 'Run Usage-Event Log Migration',
        ],
        'probe' => static fn(\mysqli $db) => !_migProbe_tableExists($db, 'tblSongUsageEvents'),
    ],

    'quality-findings' => [
        'script' => 'migrate-quality-findings.php',
        'card' => [
            'title'  => 'Corpus-quality linter findings (#1090 P8)',
            'body'   => 'Creates <code>tblSongQualityFindings</code> — one row per'
                      . ' (song, rule) defect, UPSERTed each lint run and triaged'
                      . ' like the review queue, so corpus defects become a finite'
                      . ' assignable worklist. Additive + idempotent.',
            'button' => 'Run Quality-Findings Migration',
        ],
        'probe' => static fn(\mysqli $db) => !_migProbe_tableExists($db, 'tblSongQualityFindings'),
    ],

    'annotation-votes' => [
        'script' => 'migrate-annotation-votes.php',
        'card' => [
            'title'  => 'Annotation votes — sort-only (#1090 P10)',
            'body'   => 'Creates <code>tblLyricAnnotationVotes</code> (one vote per'
                      . ' user per annotation). The tally drives reviewer-queue SORT'
                      . ' ORDER only — it never auto-publishes to the read path.'
                      . ' Additive + idempotent.',
            'button' => 'Run Annotation Votes Migration',
        ],
        'probe' => static fn(\mysqli $db) => !_migProbe_tableExists($db, 'tblLyricAnnotationVotes'),
    ],

    'song-embeddings' => [
        'script' => 'migrate-song-embeddings.php',
        'card' => [
            'title'  => 'Song embeddings — semantic search (#1090 P11)',
            'body'   => 'Creates <code>tblSongEmbeddings</code> — the durable vector'
                      . ' cache for semantic search, with a content-hash so an edit'
                      . ' re-embeds only the affected rows (never the whole corpus).'
                      . ' Prepped now, dormant until semantic search ships. Additive + idempotent.',
            'button' => 'Run Song Embeddings Migration',
        ],
        'probe' => static fn(\mysqli $db) => !_migProbe_tableExists($db, 'tblSongEmbeddings'),
    ],

    'live-follow' => [
        'script' => 'migrate-live-follow.php',
        'card' => [
            'title'  => 'Live-follow sessions (#1090 P7)',
            'body'   => 'Creates <code>tblLiveFollowSessions</code> — ephemeral'
                      . ' broadcast state for the native "Live Follow" feature'
                      . ' (congregants mirror the leader\'s current slide via a join'
                      . ' code). Additive + idempotent.',
            'button' => 'Run Live-Follow Migration',
        ],
        'probe' => static fn(\mysqli $db) => !_migProbe_tableExists($db, 'tblLiveFollowSessions'),
    ],

    'live-follow-revision' => [
        'script' => 'migrate-live-follow-revision.php',
        'card' => [
            'title'  => 'Live-follow: StateRevision (#1268)',
            'body'   => 'Adds <code>tblLiveFollowSessions.StateRevision</code> — a'
                      . ' monotonic broadcast counter so web/PWA Live-Follow'
                      . ' followers short-poll cheaply (<code>?since=</code>) without'
                      . ' holding a server connection open. Additive + idempotent;'
                      . ' run the Live-follow sessions migration first.',
            'button' => 'Run Live-Follow Revision Migration',
        ],
        'probe' => static fn(\mysqli $db) => !_migProbe_columnExists($db, 'tblLiveFollowSessions', 'StateRevision'),
    ],

    'request-corrections' => [
        'script' => 'migrate-request-corrections.php',
        'card' => [
            'title'  => 'Song-request corrections (#1090 N2)',
            'body'   => 'Extends <code>tblSongRequests</code> with'
                      . ' <code>RequestType</code> + <code>SongId</code> (FK) +'
                      . ' <code>FieldName</code> + <code>OriginalValue</code>/'
                      . '<code>ProposedValue</code> so corrections to an existing'
                      . ' song are captured as a structured before/after diff.'
                      . ' Additive + idempotent.',
            'button' => 'Run Request-Corrections Migration',
        ],
        'probe' => static fn(\mysqli $db) => !_migProbe_columnExists($db, 'tblSongRequests', 'RequestType'),
    ],

    'identifier-media-hardening' => [
        'script' => 'migrate-identifier-media-hardening.php',
        'card' => [
            'title'  => 'Identifier + media hardening (#1090 P6/N6-N7)',
            'body'   => 'Widens <code>tblCreditPersonIdentifiers.IdentifierType</code>'
                      . ' and <code>tblSongMedia.Kind</code> from ENUM to VARCHAR'
                      . ' (new identifier/media kinds need no ALTER) and adds'
                      . ' <code>tblCreditPeople.MusicBrainzArtistMBID</code>.'
                      . ' Data-preserving + idempotent.',
            'button' => 'Run Identifier + Media Hardening',
        ],
        /* Pending until the new column exists AND IdentifierType is widened. */
        'probe' => static function (\mysqli $db): bool {
            if (!_migProbe_columnExists($db, 'tblCreditPeople', 'MusicBrainzArtistMBID')) {
                return true;
            }
            $r = $db->query(
                "SELECT DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblCreditPersonIdentifiers'
                    AND COLUMN_NAME = 'IdentifierType' LIMIT 1"
            );
            $row = $r ? $r->fetch_assoc() : null;
            return !$row || strtolower((string)$row['DATA_TYPE']) !== 'varchar';
        },
    ],

    /* ----------------------------------------------------------------------
     * Schema-completeness batch (#1090 audit) — gaps the cross-repo iHymns +
     * iLyricsDB audit found iHymns missing (vocal parts headline). Additive +
     * dormant; anchored on shipped tables.
     * -------------------------------------------------------------------- */
    'vocal-parts' => [
        'script' => 'migrate-vocal-parts.php',
        'card' => [
            'title'  => 'Vocal / singing parts (#1137)',
            'body'   => 'Creates <code>tblVocalParts</code> (per-version singing-part'
                      . ' registry — lead/backing/soloist/duet/named-singer, reusing'
                      . ' <code>tblCreditPeople</code>) + <code>tblLyricLineVocalParts</code>'
                      . ' + <code>tblLyricWordVocalParts</code> (many-to-many for'
                      . ' duet/unison) — first-class queryable vocal parts vs the'
                      . ' lossless-only MetaJson today. Additive + idempotent.',
            'button' => 'Run Vocal Parts Migration',
        ],
        'probe' => static fn(\mysqli $db) => !_migProbe_tableExists($db, 'tblVocalParts'),
    ],

    'song-part-types' => [
        'script' => 'migrate-song-part-types.php',
        'card' => [
            'title'  => 'Song-part types (#1138)',
            'body'   => 'Creates <code>tblSongPartTypes</code> (seeded: Intro/Verse/'
                      . 'Chorus/Bridge/Tag/…) and adds <code>tblLyricLines.PartTypeSlug</code>'
                      . ' so reorder-by-part / part-headers are a JOIN, not a string'
                      . ' match. Additive + idempotent.',
            'button' => 'Run Song-Part Types Migration',
        ],
        'probe' => static fn(\mysqli $db) =>
            !_migProbe_tableExists($db, 'tblSongPartTypes')
            || !_migProbe_columnExists($db, 'tblLyricLines', 'PartTypeSlug'),
    ],

    'scripture-index' => [
        'script' => 'migrate-scripture-index.php',
        'card' => [
            'title'  => 'Scripture cross-reference index (#1112)',
            'body'   => 'Creates <code>tblBibleBooks</code> (seeded with the 66-book'
                      . ' OSIS canon) + <code>tblSongScriptureRefs</code> for'
                      . ' browse-by-passage ("every hymn that sets Isaiah 53").'
                      . ' Owner-confirmed; unblocks lectionary. Additive + idempotent.',
            'button' => 'Run Scripture Index Migration',
        ],
        'probe' => static fn(\mysqli $db) =>
            !_migProbe_tableExists($db, 'tblBibleBooks')
            || !_migProbe_tableExists($db, 'tblSongScriptureRefs'),
    ],

    'rights-axis' => [
        'script' => 'migrate-rights-axis.php',
        'card' => [
            'title'  => 'Rights axis — availability / royalty IDs / per-action (#1090)',
            'body'   => 'Adds <code>tblSongs.Availability</code> (available|paid_only|'
                      . 'unavailable), <code>tblContentRestrictions.AppliesToAction</code>'
                      . ' (display vs print/export/translate), and creates'
                      . ' <code>tblSongRoyaltyIds</code> (per-song PRO IDs). Additive + idempotent.',
            'button' => 'Run Rights Axis Migration',
        ],
        'probe' => static fn(\mysqli $db) =>
            !_migProbe_columnExists($db, 'tblSongs', 'Availability')
            || !_migProbe_tableExists($db, 'tblSongRoyaltyIds')
            || !_migProbe_columnExists($db, 'tblContentRestrictions', 'AppliesToAction'),
    ],

    'search-synonyms' => [
        'script' => 'migrate-search-synonyms.php',
        'card' => [
            'title'  => 'Search synonyms + diacritic folding (#1142)',
            'body'   => 'Creates <code>tblSearchSynonyms</code> and adds a'
                      . ' diacritic-folded <code>tblSongs.LyricsTextFolded</code> +'
                      . ' FULLTEXT index for accent-insensitive search'
                      . ' (Noël↔Noel, Saviour↔Savior). Additive + idempotent.',
            'button' => 'Run Search Synonyms Migration',
        ],
        'probe' => static fn(\mysqli $db) =>
            !_migProbe_tableExists($db, 'tblSearchSynonyms')
            || !_migProbe_columnExists($db, 'tblSongs', 'LyricsTextFolded'),
    ],

    'source-documents' => [
        'script' => 'migrate-source-documents.php',
        'card' => [
            'title'  => 'Raw-source-document store (#1143)',
            'body'   => 'Creates <code>tblLyricsSourceDocuments</code> — verbatim'
                      . ' ingested carrier docs (LyricsFile YAML / .ilyrics / raw TTML)'
                      . ' for lossless whole-document round-trip. Additive + idempotent.',
            'button' => 'Run Source-Documents Migration',
        ],
        'probe' => static fn(\mysqli $db) => !_migProbe_tableExists($db, 'tblLyricsSourceDocuments'),
    ],

    'presentation-themes' => [
        'script' => 'migrate-presentation-themes.php',
        'card' => [
            'title'  => 'Presentation themes + cascade (#1168)',
            'body'   => 'Creates the 8-table presentation-styling groundwork for casting /'
                      . ' projection — reusable <code>tblPresentationThemes</code> + per-display-role'
                      . ' <code>tblPresentationThemeVariants</code> (with background-layer + footer'
                      . ' child tables), the discriminated <code>tblPresentationThemeAssignments</code>'
                      . ' cascade spine, <code>tblPresentationSlideOverrides</code>, theme tags, and a'
                      . ' format-fidelity round-trip carrier. Additive + idempotent + <strong>dormant</strong>'
                      . ' (nothing reads it until the casting/editor feature consumes it).',
            'button' => 'Run Presentation Themes Migration',
        ],
        /* Pending until ALL eight tables exist (multi-object OR-form, mirrors #1088). */
        'probe' => static fn(\mysqli $db) =>
            !_migProbe_tableExists($db, 'tblPresentationThemes')
            || !_migProbe_tableExists($db, 'tblPresentationThemeVariants')
            || !_migProbe_tableExists($db, 'tblPresentationBackgroundLayers')
            || !_migProbe_tableExists($db, 'tblPresentationFooterOverlays')
            || !_migProbe_tableExists($db, 'tblPresentationThemeAssignments')
            || !_migProbe_tableExists($db, 'tblPresentationSlideOverrides')
            || !_migProbe_tableExists($db, 'tblPresentationThemeTags')
            || !_migProbe_tableExists($db, 'tblPresentationFormatFidelity'),
    ],

    'entity-colours' => [
        'script' => 'migrate-entity-colours.php',
        'card' => [
            'title'  => 'Catalogue + Series colours (#1181)',
            'body'   => 'Adds <code>tblCatalogues.Colour</code> + <code>tblSongbookSeries.Colour</code>'
                      . ' (badge hex <code>#RRGGBB</code>, empty = theme default) so catalogues /'
                      . ' collections carry a badge colour and a songbook series defines ONE colour its'
                      . ' member songbooks share. Additive + idempotent.',
            'button' => 'Run Entity Colours Migration',
        ],
        /* Pending until BOTH columns exist (multi-column OR-form). */
        'probe' => static fn(\mysqli $db) =>
            !_migProbe_columnExists($db, 'tblCatalogues', 'Colour')
            || !_migProbe_columnExists($db, 'tblSongbookSeries', 'Colour'),
    ],

    'theme-vocabulary' => [
        'script' => 'migrate-seed-theme-vocabulary.php',
        'card' => [
            'title'  => 'Standard Theme Vocabulary (CCLI / OpenLyrics) (#1152)',
            'body'   => 'Adds <code>tblSongTags.ParentId</code> (self-FK for the 2-level theme'
                      . ' hierarchy), <code>CcliThemeId</code> and <code>Source</code>, then seeds'
                      . ' the standard CCLI / OpenLyrics theme vocabulary'
                      . ' (<code>themelist.txt</code>) marked <code>Source=ccli-openlyrics</code>.'
                      . ' Curators keep adding custom tags; the seed gives Browse-by-Theme and the'
                      . ' tag typeahead a professional, interoperable baseline. Idempotent — re-runs'
                      . ' upsert by name, never duplicating.',
            'button' => 'Run Standard Theme Vocabulary Migration',
        ],
        /* Multi-object OR-probe: pending until all three columns exist AND the
           standard vocabulary has actually been seeded (a partial apply never
           shows the card green). */
        'probe' => static function (\mysqli $db): bool {
            if (!_migProbe_columnExists($db, 'tblSongTags', 'ParentId'))    return true;
            if (!_migProbe_columnExists($db, 'tblSongTags', 'CcliThemeId')) return true;
            if (!_migProbe_columnExists($db, 'tblSongTags', 'Source'))      return true;
            try {
                $res = $db->query("SELECT 1 FROM tblSongTags WHERE Source = 'ccli-openlyrics' LIMIT 1");
                $seeded = $res && $res->fetch_row() !== null;
                if ($res) $res->close();
                return !$seeded;   /* pending while nothing has been seeded */
            } catch (\Throwable $_e) { return false; }
        },
    ],
    'lyric-lines-parttypeslug' => [
        'script' => 'migrate-lyric-lines-parttypeslug.php',
        'card' => [
            'title'  => 'Lyric-line PartTypeSlug backfill (#1138)',
            'body'   => 'Populates <code>tblLyricLines.PartTypeSlug</code> from the free-text'
                      . ' <code>PartType</code> via the <code>tblSongPartTypes</code> allow-list'
                      . ' (the #1138 typed-part column was added but never backfilled — NULL on every'
                      . ' line). Data-only, no schema change. Part of #1235 P4 (lines authoritative) —'
                      . ' the projector now writes the slug on every save too, so the backfill cannot'
                      . ' rot. Idempotent — re-runs touch only rows still NULL; an unrecognised type is'
                      . ' left NULL, never invented.',
            'button' => 'Run PartTypeSlug Backfill',
        ],
        /* Pending while any line with a MAPPABLE PartType still has a NULL slug; the
           JOIN restricts to known vocab so an unknown type never keeps the card red.
           Self-clears once the backfill (+ slug-at-write) have run. Returns not-pending
           on an install lacking the prerequisite column/table. */
        'probe' => static function (\mysqli $db): bool {
            if (!_migProbe_columnExists($db, 'tblLyricLines', 'PartTypeSlug')) return false;
            if (!_migProbe_tableExists($db, 'tblSongPartTypes'))              return false;
            try {
                $res = $db->query(
                    "SELECT 1 FROM tblLyricLines ll
                       JOIN tblSongPartTypes pt ON pt.Slug = LOWER(ll.PartType)
                      WHERE ll.PartTypeSlug IS NULL LIMIT 1"
                );
                $needs = $res && $res->fetch_row() !== null;
                if ($res) { $res->close(); }
                return $needs;
            } catch (\Throwable $_e) { return false; }
        },
    ],

    /* ---- #1235 P4 / C6 — the DROP (DESTRUCTIVE, manual + gated) ----------------
       Retires the four tblSongComponents JSON payload columns now that tblLyricLines is
       authoritative. Registered LAST (after every ADD migration). alpha/beta/production SHARE
       ONE database, so this is a SINGLE in-place drop run ONCE BY HAND — never per-env (a
       re-run is an idempotent no-op) — only after C4/C5 is live on ALL THREE envs and a soak
       is green, with all three UIs frozen (#1234) + a tested backup. The real safety is in the
       script: Stage 0 ABORTS unless confirm=1 + the C3 verifier sentinel is pre-drop/green/<24h
       + live parity holds — so even an accidental "Apply all pending" is a harmless no-op skip.
       Recovery: regenerate-lines-json-from-lines.php. */
    'retire-component-lines-json' => [
        'script' => 'migrate-retire-component-lines-json.php',
        /* #1235 P4/C6 — DESTRUCTIVE + manual-only: EXCLUDED from "Apply all" (both the JS
           bulk runner and the no-JS apply-all loop) and from the pending counter, and its
           single-run requires confirm=1 (like drop-legacy). Without this, its perpetually-
           "pending" probe would (a) let a routine Apply-all execute the irreversible drop and
           (b) keep the counter stuck non-zero + hard-fail the no-JS bulk run. setup-database.php
           honours `manual` in $migrationManual. */
        'manual' => true,
        'card' => [
            'title'  => '⚠ RETIRE tblSongComponents JSON columns (#1235 P4/C6)',
            'body'   => 'DESTRUCTIVE — drops <code>LinesJson</code> / <code>ChordsJson</code> /'
                      . ' <code>NotesJson</code> / <code>LanguagesJson</code> from'
                      . ' <code>tblSongComponents</code> now that <code>tblLyricLines</code> is the'
                      . ' single source of truth. Do NOT run via "Apply all" — run by hand inside a'
                      . ' #1234 maintenance freeze with a fresh tested backup, AFTER a ≥7-night soak.'
                      . ' It REFUSES to drop unless <code>appWeb/.sql/verify-lyrics-cutover.php'
                      . ' --phase=pre-drop</code> wrote a green sentinel &lt; 24h ago AND live line-count'
                      . ' parity holds. Reversible via <code>regenerate-lines-json-from-lines.php</code>.',
            'button' => 'RETIRE JSON Columns (gated)',
        ],
        /* "Pending" while LinesJson still exists (the drop has not run); self-clears to
           applied once retired. Detects real completion (never always-true). */
        'probe' => static fn(\mysqli $db) =>
            _migProbe_columnExists($db, 'tblSongComponents', 'LinesJson'),
    ],

    'activity-log-microtime' => [
        'script' => 'migrate-activity-log-microtime.php',
        'card' => [
            'title'  => 'Activity Log: microsecond timestamps (#1287)',
            'body'   => 'Widens <code>tblActivityLog.CreatedAt</code> to'
                      . ' <code>TIMESTAMP(6)</code> so log entries capture sub-second'
                      . ' time (<code>logActivity</code> writes <code>NOW(6)</code>) —'
                      . ' forensics / latency / precise sequencing. Idempotent;'
                      . ' one-time column rewrite (opt-in per env).',
            'button' => 'Run Activity-Log Microtime Migration',
        ],
        /* Pending until CreatedAt is TIMESTAMP(6); self-clears once the precision is
           6. Table-absent (fresh install pre-Install) reports not-pending. */
        'probe' => static function (\mysqli $db): bool {
            $r = $db->query(
                "SELECT DATETIME_PRECISION FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME   = 'tblActivityLog'
                    AND COLUMN_NAME  = 'CreatedAt' LIMIT 1"
            );
            $row = $r ? $r->fetch_assoc() : null;
            if ($r) { $r->close(); }
            if ($row === null) { return false; }
            return (int)($row['DATETIME_PRECISION'] ?? 0) < 6;
        },
    ],
    'org-venues' => [
        'script' => 'migrate-org-venues.php',
        'card' => [
            'title'  => 'Org Venues &amp; Service Schedules (#1325)',
            'body'   => 'Creates <code>tblOrgVenues</code> (org physical venues — name, address,'
                      . ' lat/lng + radius, timezone) and <code>tblOrgServiceSchedules</code>'
                      . ' (recurring service times per venue). The forward-looking foundation for'
                      . ' &ldquo;Service Mode&rdquo; (#1323); ships empty, populated by'
                      . ' <code>/manage/venues</code>. Idempotent — safe to re-run.',
            'button' => 'Run Org Venues Migration',
        ],
        /* Multi-object OR-probe (rule #19): pending until BOTH tables exist, so a
           partial apply never shows the card green. */
        'probe' => static fn(\mysqli $db) =>
            !_migProbe_tableExists($db, 'tblOrgVenues')
            || !_migProbe_tableExists($db, 'tblOrgServiceSchedules'),
    ],
    'songbook-display-abbr' => [
        'script' => 'migrate-songbook-display-abbr.php',
        'card' => [
            'title'  => 'Songbook display label (#1332)',
            'body'   => 'Adds <code>tblSongbooks.DisplayAbbr</code> — an optional free-text label'
                      . ' shown to users in place of the Abbreviation (so a book can display'
                      . ' &ldquo;AH-OLD&rdquo;, &ldquo;Psalty:Kids&rdquo;, …) while the Abbreviation stays'
                      . ' the alphanumeric SongId prefix. Idempotent — safe to re-run.',
            'button' => 'Run Songbook Display Label Migration',
        ],
        'probe' => static fn(\mysqli $db) => !_migProbe_columnExists($db, 'tblSongbooks', 'DisplayAbbr'),
    ],
    'external-systems' => [
        'script' => 'migrate-external-systems.php',
        'card' => [
            'title'  => 'External-system integration hook (#1327)',
            'body'   => 'Creates the DORMANT integration scaffold: <code>tblExternalSystems</code>'
                      . ' (system registry, seeded with a paused <code>webms-intra</code> row) plus'
                      . ' per-entity ref tables <code>tblOrganisationExternalRefs</code>,'
                      . ' <code>tblOrgVenueExternalRefs</code> and'
                      . ' <code>tblOrgServiceScheduleExternalRefs</code> — so orgs / venues / service'
                      . ' times can be linked to WebMS-Intra (or another system) in future without a'
                      . ' second migration. No read/write code consumes them yet. Runs after Org Venues.'
                      . ' Idempotent — safe to re-run.',
            'button' => 'Run External-Systems Migration',
        ],
        /* Multi-object OR-probe (rule #19): pending until all four tables exist
           AND the seeded webms-intra registry row is present (the tableExists
           guards short-circuit before the SELECT, so it never runs against a
           missing table under STRICT). */
        'probe' => static function (\mysqli $db): bool {
            if (!_migProbe_tableExists($db, 'tblExternalSystems')) { return true; }
            if (!_migProbe_tableExists($db, 'tblOrganisationExternalRefs')) { return true; }
            if (!_migProbe_tableExists($db, 'tblOrgVenueExternalRefs')) { return true; }
            if (!_migProbe_tableExists($db, 'tblOrgServiceScheduleExternalRefs')) { return true; }
            $r = $db->query("SELECT 1 FROM tblExternalSystems WHERE SystemKey = 'webms-intra' LIMIT 1");
            $seeded = $r && $r->num_rows > 0;
            if ($r) { $r->close(); }
            return !$seeded;
        },
    ],
    'service-mode-sessions' => [
        'script' => 'migrate-service-mode-sessions.php',
        'card' => [
            'title'  => 'Service Mode sessions (#1335)',
            'body'   => 'Extends <code>tblLiveFollowSessions</code> (NULL-able host + venue/schedule/'
                      . 'occurrence/kind + the 3-docroot <code>Channel</code> discriminator) and creates'
                      . ' <code>tblLiveFollowJoinCodes</code>, <code>tblServicePresence</code> and'
                      . ' <code>tblServicePollCounters</code> — the dormant foundation for congregation'
                      . ' &ldquo;Service Mode&rdquo; (#1323). Runs after Org Venues. Idempotent — safe to re-run.',
            'button' => 'Run Service Mode Sessions Migration',
        ],
        /* Multi-object OR-probe (rule #19): pending until the spine column AND all
           three new tables exist. */
        'probe' => static fn(\mysqli $db) =>
            !_migProbe_columnExists($db, 'tblLiveFollowSessions', 'VenueId')
            || !_migProbe_tableExists($db, 'tblLiveFollowJoinCodes')
            || !_migProbe_tableExists($db, 'tblServicePresence')
            || !_migProbe_tableExists($db, 'tblServicePollCounters'),
    ],
    'service-code-uniqueness' => [
        'script' => 'migrate-service-code-uniqueness.php',
        'card' => [
            'title'  => 'Globally-unique service join codes (#1621)',
            'body'   => 'Makes a LIVE Service-Mode join code unique across the whole install, enforced'
                      . ' by the database. Adds <code>tblLiveFollowJoinCodes.ActiveCode</code> — a STORED'
                      . ' generated column mirroring <code>Code</code> only while'
                      . ' <code>Status</code> is <code>current</code>/<code>previous</code> — under'
                      . ' <code>UNIQUE KEY uq_ActiveCode</code> (MySQL has no filtered index; multiple'
                      . ' NULLs are distinct, so retired rows coexist freely). The original'
                      . ' <code>uq_Session_Code</code> was <em>session</em>-scoped, so two concurrent'
                      . ' services could hold the same code and a typed join was resolved by'
                      . ' freshest-heartbeat — silently joining a congregant to another organisation’s'
                      . ' service, and with it that org’s CCLI unlock. Also documents the new'
                      . ' <code>expired</code> status value, adds <code>idx_Live_Expiry</code> for the'
                      . ' expiry-retirement pass, and retires (status only — <strong>never deletes</strong>)'
                      . ' any pre-existing expired or duplicated live codes so the UNIQUE key can land.'
                      . ' Runs after Service Mode sessions. Idempotent — safe to re-run.',
            'button' => 'Run Service Join-Code Uniqueness Migration',
        ],
        /* Multi-object OR-probe (rule #19): pending until the generated column,
           its UNIQUE key AND the retirement index have all landed, so a partial
           apply (e.g. the column added but the ALTER interrupted before the key)
           never shows the card green. */
        'probe' => static fn(\mysqli $db) =>
            !_migProbe_columnExists($db, 'tblLiveFollowJoinCodes', 'ActiveCode')
            || !_migProbe_indexExists($db, 'tblLiveFollowJoinCodes', 'uq_ActiveCode')
            || !_migProbe_indexExists($db, 'tblLiveFollowJoinCodes', 'idx_Live_Expiry'),
    ],

    'song-link-origin' => [
        'script' => 'migrate-song-link-origin.php',
        'card' => [
            'title'  => 'Song-link origin marker (#1125)',
            'body'   => 'Adds <code>tblSongLinks.Origin</code> so a counterpart link records whether it'
                      . ' was created by a curator (<code>manual</code>) or by the #1125 hard-key'
                      . ' auto-promoter (<code>auto-iswc</code> / <code>auto-ccli</code> / <code>auto-isrc</code>).'
                      . ' Keeps auto-links auditable + revertable. Additive, idempotent — safe to re-run;'
                      . ' the auto-linker works without it (writes a Note tag instead) on an un-migrated install.',
            'button' => 'Run Song-Link Origin Migration',
        ],
        'probe' => static fn(\mysqli $db) => !_migProbe_columnExists($db, 'tblSongLinks', 'Origin'),
    ],

    'song-redirects' => [
        'script' => 'migrate-song-redirects.php',
        'card' => [
            'title'  => 'Song permalink redirects (#1343)',
            'body'   => 'Creates <code>tblSongRedirects</code> so a shared permalink'
                      . ' (<code>/song/&lt;SongId&gt;</code>) keeps resolving after a song is merged,'
                      . ' deleted or renamed — a 301 to the replacement, or a friendly &ldquo;removed&rdquo;'
                      . ' tombstone, instead of a dead 404 (the &ldquo;Here To Stay&rdquo; problem). Merge'
                      . ' auto-populates it; delete offers relink-or-tombstone. Idempotent — safe to re-run.',
            'button' => 'Run Song Permalink Redirects Migration',
        ],
        'probe' => static fn(\mysqli $db) => !_migProbe_tableExists($db, 'tblSongRedirects'),
    ],

    'song-public-id' => [
        'script' => 'migrate-song-public-id.php',
        'card' => [
            'title'  => 'Song PublicId / permalink id (#1343-B)',
            'body'   => 'Adds <code>tblSongs.PublicId</code> — an opaque, stable, location-independent'
                      . ' permalink id (Crockford base32) so a shared <code>/song/&lt;id&gt;</code> link'
                      . ' survives the song moving songbook or being renumbered (the SongId stays the'
                      . ' internal key). Backfills every existing song, then adds a UNIQUE index. Idempotent —'
                      . ' <strong>re-run until it reports 0 remaining</strong>, then the UNIQUE is added.',
            'button' => 'Run Song PublicId Migration',
        ],
        /* Pending until the column exists AND every row is backfilled AND the UNIQUE
           index has landed (#1343-B review wfgwvkokd). The index check is NOT redundant
           with the NULL check: MySQL UNIQUE permits multiple NULLs, and an interrupted
           Stage 3 (ADD UNIQUE after the final backfill batch) would otherwise leave the
           card green with no uniqueness constraint — re-running adds it (Stage 3 is
           idempotent), so the card must stay pending to signal the re-run. */
        'probe' => static fn(\mysqli $db) =>
            !_migProbe_columnExists($db, 'tblSongs', 'PublicId')
            || _migProbe_hasNullPublicId($db)
            || !_migProbe_indexExists($db, 'tblSongs', 'uniq_PublicId'),
    ],
    'print-templates' => [
        'script' => 'migrate-print-templates.php',
        'card' => [
            'title'  => 'Print templates (#1350)',
            'body'   => 'Creates <code>tblPrintTemplates</code> — curator-authored, block-based print'
                      . ' layouts for the clean song-print path (the 3 built-ins ship in JS; this'
                      . ' stores custom ones built in the <code>/manage/print-templates</code> editor).'
                      . ' Layout is JSON blocks so new block types need no ALTER (rule #20). Idempotent.',
            'button' => 'Run Print Templates Migration',
        ],
        'probe' => static fn(\mysqli $db) => !_migProbe_tableExists($db, 'tblPrintTemplates'),
    ],
    'tier-capabilities-json' => [
        'script' => 'migrate-add-tier-capabilities-json.php',
        'card' => [
            'title'  => 'JSON-backed tier capabilities',
            'body'   => 'Adds <code>tblAccessTiers.Capabilities</code> (JSON) — the additive,'
                      . ' schema-free home for NEW gated tier capabilities. The 7 original caps'
                      . ' (<code>CanViewLyrics</code> … <code>RequiresCcli</code>) stay as their own'
                      . ' TINYINT columns (the native-app API contract reads them); future caps live'
                      . ' as named keys inside this one JSON column, so adding a gated feature is a'
                      . ' ONE-LINE change in <code>TIER_CAPS</code> (storage <code>json</code>) — no'
                      . ' further ALTER (rule #20). NULL = no json caps set → each falls back to its'
                      . ' TIER_CAPS default. Idempotent — column-existence guarded, safe to re-run.',
            'button' => 'Run JSON Tier Capabilities Migration',
        ],
        'probe' => static fn(\mysqli $db) => !_migProbe_columnExists($db, 'tblAccessTiers', 'Capabilities'),
    ],
    'read-rate-limit' => [
        'script' => 'migrate-add-read-rate-limit.php',
        'card' => [
            'title'  => 'Public-read rate-limit counters (#1354)',
            'body'   => 'Creates <code>tblReadRateLimit</code> — fixed-window request counters'
                      . ' keyed by <em>token-or-IP</em> (not an API key id — that is'
                      . ' <code>tblApiKeyUsage</code>). Backs the lightweight per-requester'
                      . ' rate limiter on the heaviest <strong>public</strong> reads in'
                      . ' <code>api.php</code> (<code>song_detail</code> / <code>search</code> /'
                      . ' <code>bulk_songs</code> / <code>songs_index</code> /'
                      . ' <code>related_songs</code> …) to blunt scraping. The limiter is'
                      . ' <strong>fail-open</strong> — until this runs it is a clean no-op, so'
                      . ' the reads behave exactly as today. Additive, idempotent — safe to re-run.',
            'button' => 'Run Public-Read Rate-Limit Migration',
        ],
        'probe' => static fn(\mysqli $db) => !_migProbe_tableExists($db, 'tblReadRateLimit'),
    ],

    'api-key-requests' => [
        'script' => 'migrate-add-api-key-requests.php',
        'card' => [
            'title'  => 'Self-serve API-key requests (Phase D)',
            'body'   => 'Creates <code>tblApiKeyRequests</code> — the self-serve key-request'
                      . ' workflow. An admin (entitlement <code>request_api_keys</code>) requests'
                      . ' a <code>catalogue:read</code> key with a justification; a global admin'
                      . ' reviews it on <code>/manage/api-keys</code> and <strong>approves</strong>'
                      . ' (mints + links the key) or <strong>rejects</strong>. Sensitive scopes'
                      . ' (<code>lyrics:ingest</code>, <code>content:gated</code>) stay'
                      . ' approval-only. Additive, idempotent — safe to re-run.',
            'button' => 'Run Self-Serve API-Key Requests Migration',
        ],
        'probe' => static fn(\mysqli $db) => !_migProbe_tableExists($db, 'tblApiKeyRequests'),
    ],

    'creditpeople-date-precision' => [
        'script' => 'migrate-add-creditpeople-date-precision.php',
        'card' => [
            'title'  => 'Credit-people partial dates',
            'body'   => 'Adds <code>tblCreditPeople.BirthDatePrecision</code> + '
                      . '<code>DeathDatePrecision</code> (VARCHAR <code>year|month|day</code>) so a '
                      . 'historical writer with only a known <strong>year</strong> (or month + year) of '
                      . 'birth/death can be recorded — the <code>DATE</code> column stays (partial dates '
                      . 'normalise to the first of the period; existing full dates backfill to '
                      . '<code>day</code>). Additive, idempotent — safe to re-run.',
            'button' => 'Run Credit-People Partial Dates Migration',
        ],
        'probe' => static fn(\mysqli $db) => !_migProbe_columnExists($db, 'tblCreditPeople', 'BirthDatePrecision'),
    ],

    'shared-setlist-live-link' => [
        'script' => 'migrate-shared-setlist-live-link.php',
        'card' => [
            'title'  => 'Live-link shared setlists (#1380)',
            'body'   => 'Adds <code>tblSharedSetlists.OwnerUserId</code> (FK to'
                      . ' <code>tblUsers</code>) and <code>SourceSetlistId</code> so a public'
                      . ' share LINKS to the owner&rsquo;s live <code>tblUserSetlists</code> row'
                      . ' instead of freezing a snapshot — the owner&rsquo;s later edits then'
                      . ' reach link-holders. Both NULL = legacy/anonymous snapshot-only share'
                      . ' (existing shares keep working unchanged). Additive, idempotent —'
                      . ' column-existence + constraint-name guarded, safe to re-run.',
            'button' => 'Run Live-Link Shared Setlists Migration',
        ],
        /* Multi-object OR-probe (rule #19): pending until BOTH columns exist, so a
           partial apply never shows the card green. Detects real completion. */
        'probe' => static fn(\mysqli $db) =>
            !_migProbe_columnExists($db, 'tblSharedSetlists', 'OwnerUserId')
            || !_migProbe_columnExists($db, 'tblSharedSetlists', 'SourceSetlistId'),
    ],

    'backfill-canonical-songids' => [
        'script' => 'migrate-backfill-canonical-songids.php',
        /* #1380 — DATA REWRITE + manual-only: EXCLUDED from "Apply all" (both the JS bulk
           runner and the no-JS apply-all loop) and the pending counter. A WEB run defaults
           to ?dry=1 REPORT-ONLY and REFUSES to mutate without ?confirm=1, so it can NEVER
           be triggered by an "Apply all" bulk fetch. Renames every draft SongId
           (song-XXXX) to its canonical <Abbr>-NNNN id; FK children cascade automatically,
           the non-FK / JSON stores are rewritten in-band, each song in its own transaction. */
        'manual' => true,
        /* #1380 FIX 4 — this manual migration has a real DRY-RUN: a web run WITHOUT
           &confirm=1 only REPORTS (it refuses to mutate). setup-database.php auto-
           appends &confirm=1 to a `manual` card's run link, so without this flag the
           ONLY button would mutate — denying the WEB-ONLY operator the dry-run-first
           path the script was designed around. `dryRunnable` makes the card ALSO
           render a distinct "Dry-run (report only)" link (no &confirm=1, no type-to-
           confirm) alongside the confirm/apply button, and tailors the manual-card
           copy/confirm wording away from the destructive-DROP defaults. The C6
           JSON-column DROP is `manual` but NOT dryRunnable (it has no report mode), so
           it keeps the drop-only single button. */
        'dryRunnable' => true,
        'card' => [
            'title'  => 'Backfill canonical SongIds (#1380)',
            'body'   => 'DATA REWRITE — renames every legacy draft <code>song-XXXX</code>'
                      . ' SongId to its canonical <code>&lt;Abbr&gt;-NNNN</code> id (the 41 FK'
                      . ' children cascade via <code>ON UPDATE CASCADE</code>; the non-FK / JSON'
                      . ' stores — <code>tblContentRestrictions.EntityId</code>,'
                      . ' <code>tblUserSetlists.SongsJson</code>, <code>tblSharedSetlists.Data</code>'
                      . ' — are rewritten in-band, and a <code>tblSongRedirects</code> row keeps'
                      . ' old permalinks alive). Do NOT run via &ldquo;Apply all&rdquo;. Defaults'
                      . ' to <strong>dry-run</strong> (report only); pass <code>&amp;confirm=1</code>'
                      . ' to mutate. Per-song transactional + idempotent — safe to re-run.',
            'button' => 'Backfill Canonical SongIds (dry-run unless confirmed)',
        ],
        /* "Pending" while ANY draft-id song remains; self-clears to applied once every
           SongId is canonical. Detects real completion from live data (never always-true). */
        'probe' => static function (\mysqli $db): bool {
            try {
                /* @deleted-visible: migration probe (#1694) — a hidden
                   draft-id row still needs its canonical id minted. */
                $r = $db->query("SELECT 1 FROM tblSongs WHERE SongId LIKE 'song-%' LIMIT 1");
                $pending = ($r && $r->fetch_row() !== null);
                if ($r) { $r->close(); }
                return $pending;
            } catch (\Throwable $_e) {
                /* Table absent (fresh install pre-Install) → not pending. */
                return false;
            }
        },
    ],

    'user-auth-providers' => [
        'script' => 'migrate-user-auth-providers.php',
        'card' => [
            'title'  => 'Sign in with Apple — provider links + nonce ledger (#1402)',
            'body'   => 'Creates <code>tblUserAuthProviders</code> (external identity-provider'
                      . ' links — Apple <code>sub</code> → iHymns user, refresh-token custody for'
                      . ' account-deletion revocation) and <code>tblAuthNonces</code> (single-use'
                      . ' sign-in nonce ledger, anti-replay). Additive, dormant until the'
                      . ' <code>auth_apple</code> endpoint is called. Idempotent — safe to re-run.',
            'button' => 'Run Auth Providers Migration',
        ],
        /* Multi-object OR-probe (rule #19): a partial apply must never show green. */
        'probe' => static fn(\mysqli $db) => !_migProbe_tableExists($db, 'tblUserAuthProviders')
                                          || !_migProbe_tableExists($db, 'tblAuthNonces'),
    ],

    'analytics-events' => [
        'script' => 'migrate-add-analytics-events.php',
        'card' => [
            'title'  => 'First-party analytics event storage (#1448)',
            'body'   => 'Creates <code>tblAppAnalyticsEvents</code> — storage for the native-app'
                      . ' analytics ingestion endpoint (<code>?action=analytics_ingest</code>).'
                      . ' Deliberately carries NO user/device identifier and NO IP address column'
                      . ' (see <code>includes/analytics_ingest.php</code>&rsquo;s header for the'
                      . ' full PII/retention stance) — abuse mitigation is via per-IP rate limiting'
                      . ' on the endpoint itself, never by retaining IP alongside the event.'
                      . ' Additive, idempotent — safe to re-run.',
            'button' => 'Run Analytics Events Migration',
        ],
        'probe' => static fn(\mysqli $db) => !_migProbe_tableExists($db, 'tblAppAnalyticsEvents'),
    ],

    'gating-registry' => [
        'script' => 'migrate-add-gating-registry.php',
        'card' => [
            'title'  => 'Admin-configurable feature gating (#1481 P1)',
            'body'   => 'Creates <code>tblGatingCapabilities</code> (admin-defined capability'
                      . ' DEFINITIONS — each enabled row is unioned into the code'
                      . ' <code>TIER_CAPS</code> registry by <code>tierCapsEffective()</code>, so a'
                      . ' Global Admin can define a new gated feature from'
                      . ' <code>/manage/feature-gating</code> with no code deploy) and'
                      . ' <code>tblGatingRules</code> (P2 schema, created now per rule #20 — the'
                      . ' enforcement loop that reads it is a separate, not-yet-built change; rows'
                      . ' are born disabled). Both additive — with'
                      . ' <code>tblGatingCapabilities</code> empty, every surface (the'
                      . ' <code>/manage/tiers</code> matrix, the <code>access_tiers</code>/'
                      . ' <code>tier_check</code> API, content-gating enforcement) behaves'
                      . ' byte-identically to before this migration. Idempotent — safe to re-run.',
            'button' => 'Run Admin-Configurable Feature Gating Migration',
        ],
        /* Multi-object OR-probe (rule #19/#20): pending until BOTH tables exist, so a
           partial apply (e.g. the first CREATE TABLE lands, the second fails) never
           shows the card green. */
        'probe' => static fn(\mysqli $db) =>
            !_migProbe_tableExists($db, 'tblGatingCapabilities')
            || !_migProbe_tableExists($db, 'tblGatingRules'),
    ],

    /* ---- #1466 P3 — the gated encrypt-in-place migration (DATA REWRITE, manual + gated) ----
       Encrypts the 8 flagged tblAppSettings secret values (SMTP/API keys, SIWA .p8) in place
       under the master key and flips secret_encryption_active=1. NOT destructive (readers
       decrypt transparently; reversible while the key exists) but still one-shot + manual +
       confirm=1-gated, mirroring the #1235 P4/C6 drop's Stage-0 model — running it before the
       P1 decrypt-capable readers are live on ALL 3 docroots would strand a lagging docroot with
       ciphertext it can't decrypt (the prod-stale hazard .claude/secret-encryption-strategy.md
       §7 exists to prevent). The real safety is in the script: Stage 0 REFUSES unless confirm=1
       + a master key is loaded + the cross-docroot sentinel round-trips green (§8). */
    'secret-encrypt-inplace' => [
        'script' => 'migrate-secret-encrypt-inplace.php',
        /* #1466 P3 — GATED, not destructive: EXCLUDED from "Apply all" (both the JS bulk
           runner and the no-JS apply-all loop) and from the pending counter, and its single
           run requires confirm=1 (like drop-legacy / the C6 JSON-column drop). Without this,
           a routine Apply-all could re-wrap plaintext secrets before every docroot can
           decrypt them. setup-database.php honours `manual` in $migrationManual. */
        'manual' => true,
        'card' => [
            'title'  => '🔐 Encrypt secrets at rest — ENCRYPT-IN-PLACE (#1466)',
            'body'   => 'One-shot: encrypts the 8 secret <code>tblAppSettings</code> values'
                      . ' (SMTP/API keys, SIWA <code>.p8</code>) under the master key and flips'
                      . ' <code>secret_encryption_active=1</code>. REFUSES to run unless a master'
                      . ' key is loaded AND the cross-docroot sentinel round-trips GREEN on this'
                      . ' docroot. Run ONLY after the P1 readers + the key are live on ALL 3'
                      . ' docroots with identical fingerprints (strategy §7/§8). Non-destructive'
                      . ' (readers decrypt transparently); reversible only while the key exists.',
            'button' => 'Encrypt secrets at rest (gated)',
        ],
        /* PENDING (true) until the cutover flag is set. A bound read of the
           secret_encryption_active row — never a hardcoded static-true (CI's
           test-migration-registry.php bans that pattern). */
        'probe' => static function (\mysqli $db): bool {
            try {
                $stmt = $db->prepare("SELECT SettingValue FROM tblAppSettings WHERE SettingKey = 'secret_encryption_active' LIMIT 1");
                $stmt->execute();
                $row = $stmt->get_result()->fetch_row();
                $stmt->close();
                return !($row && (string)$row[0] === '1');
            } catch (\Throwable $_e) { return true; }
        },
    ],

    'creditperson-maiden-surname' => [
        'script' => 'migrate-add-creditperson-maiden-surname.php',
        'card' => [
            'title'  => 'Credit-person maiden surname (#1501)',
            'body'   => 'Adds <code>tblCreditPeople.MaidenSurname</code> (VARCHAR(100), optional) '
                      . 'so a curator can record a writer/composer&rsquo;s birth surname when it '
                      . 'differs from the current <code>Surname</code> (#934), without touching the '
                      . 'canonical <code>Name</code> the song-credit tables cite. Additive, '
                      . 'idempotent — safe to re-run.',
            'button' => 'Run Credit-Person Maiden Surname Migration',
        ],
        'probe' => static fn(\mysqli $db) => !_migProbe_columnExists($db, 'tblCreditPeople', 'MaidenSurname'),
    ],
    'creditperson-members' => [
        'script' => 'migrate-add-creditperson-members.php',
        'card' => [
            'title'  => 'Credit-person Group membership (#1502)',
            'body'   => 'Adds <code>tblCreditPersonMembers</code> so a &ldquo;Group / band / '
                      . 'collective&rdquo; credit person (<code>IsGroup</code>, #585) can list its '
                      . 'individual member people &mdash; each an ordinary registry row of their own. '
                      . 'Both FKs cascade-delete; a UNIQUE key prevents duplicate membership. '
                      . 'Additive, idempotent — safe to re-run.',
            'button' => 'Run Credit-Person Group Membership Migration',
        ],
        'probe' => static fn(\mysqli $db) => !_migProbe_tableExists($db, 'tblCreditPersonMembers'),
    ],

    /* ----------------------------------------------------------------------
     * Apple Phase-2 live schema batch (#1407/#1408/#1409/#1410) — one-pass
     * forward-looking schema for the whole "drive the TV / remote-control /
     * device pairing" native-app family (rule #20). Additive + dormant until
     * each consuming PR ships and starts reading/writing these columns/
     * tables. See migrate-apple-phase2-live-schema.php's header for the
     * five-object breakdown.
     * -------------------------------------------------------------------- */
    'apple-phase2-live-schema' => [
        'script' => 'migrate-apple-phase2-live-schema.php',
        'card' => [
            'title'  => 'Apple Phase-2 live schema batch (#1407/#1408/#1409/#1410)',
            'body'   => 'One-pass forward-looking schema (rule #20) for the upcoming native '
                      . '"drive the TV / remote-control / device pairing" feature family: adds '
                      . '<code>tblServicePresence.Role</code> (congregant/projector poll budget, #1406) '
                      . 'and <code>tblApiTokens.DeviceName/Platform/AppVersion/LastSeenAt</code> (#1409), '
                      . 'and creates <code>tblAuthDeviceCodes</code> (RFC 8628 device-code pairing, #1407), '
                      . '<code>tblSessionControlTokens</code> (scoped remote-control delegation, #1408), and '
                      . '<code>tblApnsTokens</code> (device + Live Activity push tokens, #1410). '
                      . '<code>tblAuthDeviceCodes</code> now has real consumers — the '
                      . '<code>auth_device_code_request</code>/<code>_poll</code>/<code>_link_lookup</code>/'
                      . '<code>_approve</code>/<code>_deny</code> API actions + the <code>/link</code> page '
                      . '(#1407, table-existence-gated so this card must be applied first) — while '
                      . '<code>tblSessionControlTokens</code>/<code>tblApnsTokens</code> and the two ALTERed '
                      . 'columns stay dormant (additive/nullable-or-defaulted, no existing read/write changes) '
                      . 'until their own PRs (#1408/#1409/#1410) ship. Additive, idempotent — safe to re-run.',
            'button' => 'Run Apple Phase-2 Live Schema Migration',
        ],
        /* Multi-object OR-probe (rule #19): pending until EVERY one of the five
           objects exists, so a partial apply (e.g. the connection dropped after
           the first two ALTERs but before the three CREATE TABLEs) never shows
           the card green — "Apply all pending" would otherwise skip the rest. */
        'probe' => static fn(\mysqli $db) =>
               !_migProbe_columnExists($db, 'tblServicePresence', 'Role')
            || !_migProbe_columnExists($db, 'tblApiTokens', 'DeviceName')
            || !_migProbe_columnExists($db, 'tblApiTokens', 'Platform')
            || !_migProbe_columnExists($db, 'tblApiTokens', 'AppVersion')
            || !_migProbe_columnExists($db, 'tblApiTokens', 'LastSeenAt')
            || !_migProbe_tableExists($db, 'tblAuthDeviceCodes')
            || !_migProbe_tableExists($db, 'tblSessionControlTokens')
            || !_migProbe_tableExists($db, 'tblApnsTokens'),
    ],

    /* ----------------------------------------------------------------------
     * #1668 — remove the dead, self-misdescribing `ccli_validation_enabled`
     * setting. Nothing has ever read the key, but its seeded description
     * presented it as the CCLI enforcement switch, so an operator flipping it
     * to 1 would believe copyright enforcement was live when nothing had
     * changed. schema.sql's copy was corrected in #1640, but INSERT IGNORE
     * means existing installs kept the false text — only a migration reaches
     * them. Non-destructive in any meaningful sense (zero readers), so NOT
     * flagged `manual`: it should run as part of "Apply all pending".
     * -------------------------------------------------------------------- */
    'remove-ccli-validation-setting' => [
        'script' => 'migrate-remove-ccli-validation-setting.php',
        'card' => [
            'title'  => 'Remove dead ccli_validation_enabled setting (#1668)',
            'body'   => 'Deletes the <code>ccli_validation_enabled</code> row from'
                      . ' <code>tblAppSettings</code>. Nothing in the codebase has ever read'
                      . ' this key, yet its description called it the CCLI enforcement switch —'
                      . ' so setting it to <code>1</code> enforced nothing while looking like it'
                      . ' did. The real controls are <code>content_gating_enabled</code> plus'
                      . ' <code>require_licence:ccli</code> rows in'
                      . ' <code>tblContentRestrictions</code>, with the licence itself resolved'
                      . ' by <code>userHasValidCcli()</code> (#1668). No tables, no columns, no'
                      . ' readers — deleting the row cannot change behaviour. Idempotent.',
            'button' => 'Remove ccli_validation_enabled Setting',
        ],
        /* Pending while the row STILL EXISTS; applied once it is gone — the
           inverse polarity of the seeding migrations, mirroring the DROP cards.
           Detects real completion from live data (rule #19), never static-true,
           and self-clears after a successful run. A fresh install passes
           trivially because schema.sql no longer seeds the row. */
        'probe' => static fn(\mysqli $db) => (function (\mysqli $db): bool {
            $stmt = $db->prepare(
                "SELECT 1 FROM tblAppSettings WHERE SettingKey = 'ccli_validation_enabled' LIMIT 1"
            );
            $stmt->execute();
            $row = $stmt->get_result()->fetch_row();
            $stmt->close();
            return $row !== null;   /* row present → still pending */
        })($db),
    ],

    /* ----------------------------------------------------------------------
     * X6 / #1685 — reword the captcha_* / ads_* seed descriptions as RESERVED.
     * Six tblAppSettings rows described providers/toggles that no code in
     * this repo has ever read (orphan inventory §6.2) — the same species of
     * doc-vs-code lie #1668 fixed for ccli_validation_enabled, except these
     * six describe plausible FUTURE features rather than an actively
     * misleading security switch, so the remediation plan's default is
     * REWORD, not delete. schema.sql's INSERT IGNORE seed already carries
     * the corrected text for fresh installs; only a migration reaches the
     * Description column on an existing row. Never touches SettingValue —
     * no operator-set value changes, nothing here can alter behaviour (the
     * six keys have no reader either way). Not `manual`: safe inside
     * "Apply all pending".
     * -------------------------------------------------------------------- */
    'fix-captcha-ads-descriptions' => [
        'script' => 'migrate-fix-captcha-ads-descriptions.php',
        'card' => [
            'title'  => 'Reword captcha/ads seed descriptions (#1685)',
            'body'   => 'Rewords the <code>Description</code> of six'
                      . ' <code>tblAppSettings</code> rows — <code>captcha_provider</code>,'
                      . ' <code>captcha_site_key</code>, <code>captcha_secret_key</code>,'
                      . ' <code>ads_enabled</code>, <code>ads_provider</code>,'
                      . ' <code>ads_publisher_id</code> — to say plainly that nothing reads'
                      . ' them yet, instead of describing bot-protection / advertisement'
                      . ' features that do not exist in this codebase. Only the description'
                      . ' text changes; operator-set values are untouched. Idempotent.',
            'button' => 'Reword Captcha/Ads Descriptions',
        ],
        /* Multi-row probe (rule #19 discipline applied to a data migration —
           not just DDL): pending while ANY of the six rows still carries text
           that is not the reworded RESERVED text, so a partial run (e.g. the
           connection dropped after row 3 of 6) never shows the card green. A
           fresh install passes trivially because schema.sql already seeds the
           corrected text; a very old install missing one of the six rows
           entirely also can't block "applied" — a missing row has nothing
           misleading to reword. */
        'probe' => static fn(\mysqli $db) => (function (\mysqli $db): bool {
            $keys = ['captcha_provider', 'captcha_site_key', 'captcha_secret_key',
                     'ads_enabled', 'ads_provider', 'ads_publisher_id'];
            $placeholders = implode(',', array_fill(0, count($keys), '?'));
            $stmt = $db->prepare(
                "SELECT COUNT(*) AS c FROM tblAppSettings"
                . " WHERE SettingKey IN ({$placeholders})"
                . " AND Description NOT LIKE 'RESERVED —%'"
            );
            $stmt->bind_param(str_repeat('s', count($keys)), ...$keys);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            return ((int)($row['c'] ?? 0)) > 0;   /* any non-reworded row → still pending */
        })($db),
    ],

    /* ----------------------------------------------------------------------
     * #1661 — setlist tombstones + optional expiry. The storage half of sync
     * protocol 2: deletion becomes something a client SAYS (a tombstone row)
     * rather than something the server INFERS from a setlist being absent
     * from a payload — the inference that made a truncated payload look like
     * a mass deletion (#1649). The 50-setlist cap goes away in the same
     * change; over-size bodies are REJECTED (413), never silently clipped.
     *
     * Additive and dormant-safe: every reader is existence-gated
     * (userSyncTombstonesReady() / userSyncExpiryReady() in
     * includes/user_sync.php), so an install that has not run this card keeps
     * exactly today's behaviour instead of throwing under STRICT (#1228).
     * -------------------------------------------------------------------- */
    'setlist-tombstones' => [
        'script' => 'migrate-setlist-tombstones.php',
        'card' => [
            'title'  => 'Setlist tombstones + optional expiry (#1661)',
            'body'   => 'Creates <code>tblUserSetlistTombstones</code> (an explicit, permanent'
                      . ' record that a set list was deleted — so a deletion can never again be'
                      . ' inferred from a truncated sync payload, #1649) and adds the optional'
                      . ' <code>tblUserSetlists.ExpiresAt</code> column (<code>NULL</code> ='
                      . ' never expires, which is what every existing row gets, so no set list'
                      . ' changes behaviour). An expiry that passes is converted server-side'
                      . ' into a tombstone with <code>Reason=\'expired\'</code>, so expiry and'
                      . ' deletion propagate through one mechanism. Until this card is applied'
                      . ' the sync path is existence-gated and behaves exactly as before —'
                      . ' native app sync is unaffected either way. Additive, idempotent — safe'
                      . ' to re-run.',
            'button' => 'Run Setlist Tombstones Migration',
        ],
        /* Multi-object OR-probe (rule #19): the migration creates a table AND
           alters another, so a partial apply (connection lost between the two)
           must NOT show the card green — otherwise "Apply all pending" would
           skip the half that never ran, and the expiry gate would sit
           permanently false with nothing indicating why. */
        'probe' => static fn(\mysqli $db) =>
               !_migProbe_tableExists($db, 'tblUserSetlistTombstones')
            || !_migProbe_columnExists($db, 'tblUserSetlists', 'ExpiresAt'),
    ],

    /* ---- #301 / #1671 F4 — set-list service plans (template slots) -------------
       Adds tblUserSetlists.SlotsJson, the storage half that #1671 Batch 8 found
       missing: tblSetlistTemplates has stored a template's SlotsJson since #301,
       but user_setlists_sync had nowhere to keep the plan once a template was
       applied, so slots round-tripped and came back GONE for signed-in users.
       ONE column, ONE object, so the probe is a single columnExists — a
       multi-object OR-probe would be dishonest here (rule #19 asks for one only
       when a partial apply is possible). */
    'setlist-slots' => [
        'script' => 'migrate-setlist-slots.php',
        'card' => [
            'title'  => 'Set-list service plans (#301)',
            'body'   => 'Adds the optional <code>tblUserSetlists.SlotsJson</code> column so a'
                      . ' set list can remember the service-order plan it was built from'
                      . ' (<code>{templateId, templateName, slots[]}</code>). Without it,'
                      . ' applying a set-list <em>template</em> produced slots that the very'
                      . ' next sync silently discarded — the reason the template UI was'
                      . ' deliberately not built in the #1671 Batch-8 pass. <code>NULL</code> ='
                      . ' no plan, which is what every existing set list gets, so applying'
                      . ' this changes the behaviour of zero existing rows. Until it is'
                      . ' applied the sync path is existence-gated and behaves exactly as'
                      . ' before. Additive, idempotent — safe to re-run.',
            'button' => 'Run Set-list Service Plans Migration',
        ],
        'probe' => static fn(\mysqli $db) =>
            !_migProbe_columnExists($db, 'tblUserSetlists', 'SlotsJson'),
    ],

    /* ---- #1698 — account lifecycle: disabled vs deleted ------------------------
       Adds tblUsers.Status + tblUsers.StatusChangedAt + idx_Status. `IsActive`
       remains THE lock on every authenticated path (getAuthenticatedUser() filters
       IsActive = 1, and manage/includes/auth.php has thirteen more enforcement
       points); Status exists ONLY to distinguish a reversible DISABLE from an
       irreversible anonymised-tombstone ERASE.

       THREE objects, so a THREE-clause OR-probe (rule #19). The migration applies
       them as three separate guarded statements precisely so a dropped connection
       leaves a partial state — and a partial apply must NOT show the card green,
       or "Apply all pending" skips the half that never ran while
       userStatusColumnReady() sits true and the erasure core writes a Status the
       index cannot serve. */
    'user-account-status' => [
        'script' => 'migrate-user-account-status.php',
        'card' => [
            'title'  => 'Account lifecycle status (#1698)',
            'body'   => 'Adds <code>tblUsers.Status</code> (<code>active | disabled | deleted</code>)'
                      . ' + <code>StatusChangedAt</code> + an index, so a <strong>disabled</strong>'
                      . ' account (switched off, fully reversible) is distinguishable from an'
                      . ' <strong>erased</strong> one (anonymised tombstone). Existing'
                      . ' <code>IsActive = 0</code> rows are backfilled to <code>disabled</code>,'
                      . ' which is what they are. Adds no new lock — <code>IsActive</code> is still'
                      . ' what every auth path checks — so applying this changes the behaviour of'
                      . ' zero existing accounts. Until it is applied every reader is'
                      . ' existence-gated and falls back to <code>IsActive</code>, and account'
                      . ' <em>erasure</em> deliberately REFUSES rather than falling back to the old'
                      . ' irreversible hard delete. Additive, idempotent — safe to re-run.',
            'button' => 'Run Account Lifecycle Status Migration',
        ],
        'probe' => static fn(\mysqli $db) =>
               !_migProbe_columnExists($db, 'tblUsers', 'Status')
            || !_migProbe_columnExists($db, 'tblUsers', 'StatusChangedAt')
            || !_migProbe_indexExists($db, 'tblUsers', 'idx_Status'),
    ],

    /* ---- #1613 — DROP tblSongChords (DESTRUCTIVE, manual + gated) ---------------
       tblSongChords (#299) has zero PHP/JS references — chord notation now lives
       per-line on tblLyricLines.ChordsJson (rule #21/#25 of .claude/CLAUDE.md). The only
       two places that ever modelled a positioned-chord pair were this table and the now-
       deleted js/utils/transpose.js (#1612); both were dead code with no callers.
       Registered manual + confirm=1-gated (mirrors drop-legacy-tables.php / the #1235 P4/C6
       JSON-column drop) so a routine "Apply all pending" can never trigger it. The real
       safety is in the script itself: it REFUSES to drop unless the live table is
       verifiably EMPTY, independent of confirm — this codebase's zero-reference analysis
       can't see the live DB, so a non-zero row count on some install is a hard stop rather
       than silent data loss. */
    'drop-song-chords' => [
        'script' => 'migrate-drop-song-chords.php',
        /* #1613 — DESTRUCTIVE + manual-only: EXCLUDED from "Apply all" (both the JS bulk
           runner and the no-JS apply-all loop) and from the pending counter; the single run
           still requires confirm=1. setup-database.php honours `manual` in $migrationManual. */
        'manual' => true,
        'card' => [
            'title'  => '⚠ Drop unused tblSongChords (#1613)',
            'body'   => 'DESTRUCTIVE — drops <code>tblSongChords</code> (#299), which has zero'
                      . ' PHP/JS references; chord notation now lives per-line on'
                      . ' <code>tblLyricLines.ChordsJson</code>. REFUSES to drop unless the live'
                      . ' table is verifiably EMPTY (checked independent of confirmation), so an'
                      . ' unexpected row on this install is a hard stop rather than silent data'
                      . ' loss. Idempotent — once the table is gone the migration is a no-op.',
            'button' => 'Drop tblSongChords (gated)',
        ],
        /* Pending while the table STILL EXISTS (inverse of the ADD migrations above,
           mirrors drop-songbook-name's polarity): a DROP is "applied" once the table is
           gone, so the probe self-clears after a successful run. Detects real completion —
           never a hardcoded static-true. */
        'probe' => static fn(\mysqli $db) => _migProbe_tableExists($db, 'tblSongChords'),
    ],

    'drop-user-preferences' => [
        'script' => 'migrate-drop-user-preferences.php',
        /* #1671 F5 — DESTRUCTIVE + manual-only: EXCLUDED from "Apply all" (both the JS bulk
           runner and the no-JS apply-all loop) and from the pending counter; the single run
           still requires confirm=1. Same convention as drop-song-chords / C6 above —
           setup-database.php honours `manual` in $migrationManual. */
        'manual' => true,
        'card' => [
            'title'  => '⚠ Drop superseded tblUserPreferences (#1671 F5)',
            'body'   => 'DESTRUCTIVE — drops <code>tblUserPreferences</code> (#310), an'
                      . ' un-namespaced duplicate of <code>tblUsers.Settings</code>. Its only two'
                      . ' endpoints (<code>user_preferences</code>,'
                      . ' <code>user_preferences_sync</code>) had no caller anywhere in the tree'
                      . ' and were removed; preference sync now lives solely on'
                      . ' <code>action=user_settings</code>, which gained a namespaced write'
                      . ' contract so a second product can share the row. REFUSES to drop unless'
                      . ' the live table is verifiably EMPTY (checked independent of'
                      . ' confirmation), so a row written by an out-of-repo caller is a hard stop'
                      . ' rather than silent data loss. Idempotent — a no-op once the table is'
                      . ' gone.',
            'button' => 'Drop tblUserPreferences (gated)',
        ],
        /* Pending while the table STILL EXISTS — a DROP is "applied" once the table is gone,
           so the probe self-clears after a successful run (the same inverted polarity as
           drop-song-chords). Reads the live schema; never a hardcoded static-true. */
        'probe' => static fn(\mysqli $db) => _migProbe_tableExists($db, 'tblUserPreferences'),
    ],

    /* ----------------------------------------------------------------------
     * #1590 — entitlement truth-up: clear the three stale saved overrides.
     *
     * Nine entitlement checkboxes on /manage/entitlements were decorative —
     * nothing anywhere called userHasEntitlement() with them, so an operator's
     * tick changed nothing, silently. Batch 6 wired them all, with defaults
     * chosen to equal the raw role gates they replace, so that nothing changes
     * until an operator deliberately overrides.
     *
     * That equality holds for the SHIPPED defaults. It does not hold for a
     * saved override — and `saveEntitlementOverrides()` writes the entire map,
     * every key, on every save, so an install where the page has ever been
     * saved carries an explicit stored value for all of them. For the three
     * keys whose default had to move to match reality, that stale stored value
     * would start being obeyed the moment the checks went live and would take
     * away abilities curators use today. This clears exactly those three.
     *
     * Data-only: no DDL, so nothing to mirror into schema.sql beyond the
     * sentinel seed (which is there so a fresh install — which has no stale
     * override by construction — shows the card already applied).
     * -------------------------------------------------------------------- */
    'entitlement-truthup' => [
        'script' => 'migrate-entitlement-truthup.php',
        'card' => [
            'title'  => 'Entitlement truth-up — clear stale overrides (#1590)',
            'body'   => 'Nine permission checkboxes on <code>/manage/entitlements</code> were'
                      . ' decorative — nothing read them, so ticking or unticking one changed'
                      . ' nothing. They are now enforced. This clears the three saved overrides'
                      . ' whose stored value no longer means what it did —'
                      . ' <code>delete_songs</code>, <code>bulk_edit_songs</code> and'
                      . ' <code>run_db_backup</code> — so they fall back to the corrected'
                      . ' shipped defaults instead of silently removing access curators have'
                      . ' today. Every other saved permission is left untouched. Idempotent;'
                      . ' a no-op on an install that has never saved that page.',
            'button' => 'Clear stale entitlement overrides',
        ],
        /* Sentinel probe, deliberately NOT "are the three keys absent?".
           Re-saving /manage/entitlements legitimately writes all three keys back,
           so a key-absence probe would flip this card from applied to pending for
           the rest of time — the "pending counter can never reach zero" failure
           rule #19 exists to prevent. The sentinel records that the one-off prune
           HAPPENED, which is the thing that cannot un-happen. It is real evidence
           read from live data, never a hardcoded `static fn() => true`. */
        'probe' => static fn(\mysqli $db) => (function (\mysqli $db): bool {
            $stmt = $db->prepare(
                "SELECT 1 FROM tblAppSettings WHERE SettingKey = 'entitlement_truthup_applied' LIMIT 1"
            );
            $stmt->execute();
            $seen = $stmt->get_result()->fetch_row() !== null;
            $stmt->close();
            return !$seen;   /* no sentinel row → still pending */
        })($db),
    ],

    /* ---- #1695 (epic #1692 stage 3) — let the re-widened default take effect --
       A DATA-ONLY card: no DDL, no new object. It exists because widening
       `delete_songs` back to editor+ in PHP is a SILENT NO-OP on any install
       where /manage/entitlements has ever been saved — a stored override
       replaces the code default outright, and saveEntitlementOverrides() writes
       the whole map. Conditional by design: it prunes ONLY the exact stage-1
       interim value, because in the database that is byte-identical to a
       deliberate operator lockdown, and blanket-clearing would silently WIDEN a
       privilege on a site that had decided otherwise. */
    'delete-songs-rewiden' => [
        'script' => 'migrate-delete-songs-rewiden.php',
        'card' => [
            'title'  => 'Re-widen song deletion to curators (#1695)',
            'body'   => 'Deleting a song is now recoverable (#1694), so <code>delete_songs</code>'
                      . ' returns to editor+ — the interim admin-only restriction existed only'
                      . ' because deletion used to be permanent. The permissions screen stores its'
                      . ' own copy of every rule, and a stored copy beats the shipped default, so'
                      . ' this clears the remembered interim value. <strong>Only</strong> if it is'
                      . ' exactly the value we shipped: a deliberate lockdown you chose yourself'
                      . ' is preserved untouched. Idempotent; a no-op on an install that has never'
                      . ' saved that page.',
            'button' => 'Apply the re-widened delete permission',
        ],
        /* Sentinel probe, for the same reason as #1590's: re-saving
           /manage/entitlements legitimately writes the key back, so a
           key-absence probe would flip this card pending again forever (rule
           #19's un-reachable-zero failure). The sentinel records that the
           one-off prune HAPPENED — real evidence from live data, never a
           hardcoded `static fn() => true`. */
        'probe' => static fn(\mysqli $db) => (function (\mysqli $db): bool {
            $stmt = $db->prepare(
                "SELECT 1 FROM tblAppSettings WHERE SettingKey = 'delete_songs_rewiden_applied' LIMIT 1"
            );
            $stmt->execute();
            $seen = $stmt->get_result()->fetch_row() !== null;
            $stmt->close();
            return !$seen;   /* no sentinel row → still pending */
        })($db),
    ],

    /* ---- #1694 (epic #1692) — song soft delete -------------------------------
       Five columns + one index + one FK on tblSongs so a "deleted" song is
       HIDDEN and restorable instead of gone: 38 of the 41 FKs referencing
       tblSongs(SongId) CASCADE, so the old hard delete destroyed components,
       lyric lines, credits, media, tags and the whole revision history in one
       irreversible stroke. Additive and DORMANT — nothing reads the columns
       until the #1694 read-path sweep + write cores land, so applying this
       card changes the behaviour of zero existing reads (rule #20 one-pass).

       The card also redefines the three SongCount triggers (#793) so the
       cached per-songbook count means VISIBLE songs (owner decision D1a) —
       but the triggers are deliberately NOT in this probe: they are
       host-optional (shared hosts deny CREATE TRIGGER, #815), so a
       trigger-presence clause would pin this card pending forever on exactly
       the hosts the friendly-skip path exists for. #793's own card already
       owns trigger pendency via its sentinel. */
    'song-soft-delete' => [
        'script' => 'migrate-song-soft-delete.php',
        'card' => [
            'title'  => 'Song soft delete (#1694)',
            'body'   => 'Adds the soft-delete columns to <code>tblSongs</code>'
                      . ' (<code>IsDeleted</code>, <code>DeletedAt</code>, <code>DeletedBy</code>,'
                      . ' <code>DeletedReason</code>, <code>DeleteNote</code>) plus the'
                      . ' <code>idx_IsDeleted</code> index and the <code>fk_Songs_DeletedBy</code>'
                      . ' attribution FK, so deleting a song hides it (restorable from the'
                      . ' forthcoming <code>/manage/deleted-songs</code>) instead of cascading away'
                      . ' its components, credits, media and revision history. Also redefines the'
                      . ' SongCount triggers (#793) to count visible songs only, with the same'
                      . ' friendly skip on trigger-denied hosts. Additive and dormant until the'
                      . ' #1694 read/write sweep ships. Idempotent — safe to re-run.',
            'button' => 'Run Song Soft Delete Migration',
        ],
        /* Multi-object OR-probe (rule #19): SEVEN separately-guarded objects,
           so a partial apply (connection dropped between ALTERs) must never
           show the card green — "Apply all pending" would otherwise skip the
           half that never ran while songSoftDeleteReady() (which requires all
           five columns in lockstep) sits false with nothing indicating why. */
        'probe' => static fn(\mysqli $db) =>
               !_migProbe_columnExists($db, 'tblSongs', 'IsDeleted')
            || !_migProbe_columnExists($db, 'tblSongs', 'DeletedAt')
            || !_migProbe_columnExists($db, 'tblSongs', 'DeletedBy')
            || !_migProbe_columnExists($db, 'tblSongs', 'DeletedReason')
            || !_migProbe_columnExists($db, 'tblSongs', 'DeleteNote')
            || !_migProbe_indexExists($db, 'tblSongs', 'idx_IsDeleted')
            || !_migProbe_constraintExists($db, 'tblSongs', 'fk_Songs_DeletedBy'),
    ],

    /* ---- #1725/#1727 — MWBM-IntAppsAPI gateway sync cache ---------------
       ONE dormant table. Nothing in the tree reads or writes it until the
       client module (includes/intapps_client.php, a later commit in this
       same batch) ships AND an admin lists the current channel in
       tblAppSettings.intappsapi_enabled_channels — so applying this card
       changes the behaviour of zero existing reads (rule #20 one-pass:
       Scope/Channel/AppSlug are all reserved-multiplicity columns baked in
       up front, so a second scope, a per-channel gateway app, or a second
       registered app slug is a data change, never a second migration). See
       .claude/intappsapi-integration-plan.md §4 for the full design and
       .claude/wave4-prelaunch-plan.md §6 Block D for the pre-launch delta
       this card is part of. */
    'intapps-sync' => [
        'script' => 'migrate-add-intapps-sync.php',
        'card' => [
            'title'  => 'IntAppsAPI Gateway Sync Cache (#1725/#1727)',
            'body'   => 'Creates <code>tblIntAppsSync</code>, the local snapshot +'
                      . ' refresh-bookkeeping table for the MWBM-IntAppsAPI gateway'
                      . ' integration (server-proxied feature-flag kill switches).'
                      . ' Entirely dormant until an admin lists the current channel'
                      . ' in the IntAppsAPI card on <code>/manage/configuration</code>'
                      . ' — until then this card being applied or pending changes'
                      . ' nothing observable anywhere in the app. Idempotent — safe'
                      . ' to re-run.',
            'button' => 'Create IntApps Sync Table',
        ],
        /* Single-object probe (one CREATE TABLE, nothing else) — a real
           live-schema check, never a `true`-returning stub (rule #19). */
        'probe' => static fn(\mysqli $db) => !_migProbe_tableExists($db, 'tblIntAppsSync'),
    ],

    /* ----------------------------------------------------------------------
     * Epic #1741 D5 — comprehensive external/catalogue ID storage foundation
     * (media-identifiers-spec.md §4b taxonomy, §4c decisions A=c / B=yes).
     * Two additive/idempotent/dormant cards; nothing reads or writes either
     * new object yet — the P3 alias-URL resolver is what starts consuming
     * them, and is explicitly out of scope for this storage-layer batch.
     * -------------------------------------------------------------------- */
    'song-external-ids' => [
        'script' => 'migrate-song-external-ids.php',
        'card' => [
            'title'  => 'Song external IDs (#1741 D5)',
            'body'   => 'Creates <code>tblSongExternalIds</code>, a key/value'
                      . ' (SongId, IdType, IdValue) successor to the'
                      . ' one-column-per-provider <code>tblSongIdentityMap</code>'
                      . ' shape — absorbs the ~13 DSP + ~10 database + legacy'
                      . ' provider IDs (ISRC, Spotify, Apple Music, MusicBrainz,'
                      . ' Discogs, ICPN, …) at zero further ALTER cost per new'
                      . ' provider. The 4 existing <code>tblSongIdentityMap</code>'
                      . ' columns are untouched (grandfathered reads). Entirely'
                      . ' dormant until the P3 alias-URL resolver lands.'
                      . ' Idempotent — safe to re-run.',
            'button' => 'Create Song External IDs Table',
        ],
        /* Single-object probe (one CREATE TABLE + its keys, all created in
           the same statement — nothing to partially apply), plus the two
           indexes as an extra guard so a hand-edited/truncated DDL on some
           future fork still shows pending rather than a false green. */
        'probe' => static fn(\mysqli $db) =>
               !_migProbe_tableExists($db, 'tblSongExternalIds')
            || !_migProbe_indexExists($db, 'tblSongExternalIds', 'uq_Song_Type_Value')
            || !_migProbe_indexExists($db, 'tblSongExternalIds', 'idx_Type_Value'),
    ],

    /* #1747 D5 backfill (owner-confirmed 2026-08-02) — data-only follow-up to
       'song-external-ids' immediately above. MUST be ordered after it: the
       backfill INSERTs into tblSongExternalIds, so the card is meaningless
       (and its own probe would read a missing table) until that table
       exists. Creates no schema object itself — see the script's own
       doc-block for why "no schema.sql change" is correct here, not an
       oversight. */
    'backfill-song-external-ids' => [
        'script' => 'migrate-backfill-song-external-ids.php',
        'card' => [
            'title'  => 'Backfill song external IDs (#1747 D5)',
            'body'   => 'Copies the existing data out of the grandfathered'
                      . ' <code>tblSongs.Isrc</code> column and'
                      . ' <code>tblSongIdentityMap</code>&rsquo;s four'
                      . ' recording-ID columns'
                      . ' (<code>MusicBrainzRecordingMBID</code> /'
                      . ' <code>SpotifyTrackId</code> / <code>GeniusTrackId</code> /'
                      . ' <code>IsrcCode</code>) into the new'
                      . ' <code>tblSongExternalIds</code> key/value store, so'
                      . ' the new table becomes the comprehensive single home'
                      . ' for recording IDs rather than starting empty next'
                      . ' to columns that already had data. The old columns'
                      . ' are left untouched (still readable) — this only'
                      . ' adds rows, never deletes or alters anything.'
                      . ' Idempotent — safe to re-run at any time; already-'
                      . 'present rows are silently skipped via'
                      . ' <code>INSERT IGNORE</code>. Requires the'
                      . ' &ldquo;Song external IDs&rdquo; card above to be'
                      . ' applied first.',
            'button' => 'Backfill Song External IDs',
        ],
        /* Real data-derived probe (rule #19 — never `=> true`): pending when
           the target table is missing entirely, OR when ANY grandfathered
           source value the backfill copies lacks its mirror row in
           tblSongExternalIds. It checks EVERY source the migration writes —
           tblSongs.Isrc plus all four tblSongIdentityMap columns (each to its
           own IdType) — rather than ISRC alone: an install could one day
           (post the #1010 iLyricsDB merge) carry a MusicBrainz/Spotify/Genius
           value with no ISRC anywhere, and an ISRC-only probe would then show
           a false "applied" green while that data sat un-mirrored. Checking
           all five sources keeps the probe a true completion detector with no
           false-green window, at negligible cost against the tiny/dormant
           tblSongIdentityMap. */
        'probe' => static function (\mysqli $db): bool {
            if (!_migProbe_tableExists($db, 'tblSongExternalIds')) return true;
            try {
                /* @deleted-visible: migration probe (#1694) — backfill
                   completeness is PHYSICAL; a hidden/soft-deleted song's ISRC
                   still needs its tblSongExternalIds mirror row so the link
                   is intact if that song is ever restored. Deliberately not
                   scoped to visible-only rows. */
                $r = $db->query(
                    "SELECT 1 FROM tblSongs s
                      WHERE s.Isrc IS NOT NULL AND s.Isrc <> ''
                        AND NOT EXISTS (
                            SELECT 1 FROM tblSongExternalIds e
                             WHERE e.SongId = s.SongId AND e.IdType = 'isrc' AND e.IdValue = s.Isrc
                        )
                      LIMIT 1"
                );
                if ($r && $r->fetch_row() !== null) return true;

                if (_migProbe_tableExists($db, 'tblSongIdentityMap')) {
                    /* All four tblSongIdentityMap columns, each vs its own
                       IdType — one row un-mirrored on ANY of them = pending.
                       Column names + IdType literals are hardcoded PHP
                       constants (rule #5), never request input. */
                    $r2 = $db->query(
                        "SELECT 1 FROM tblSongIdentityMap m WHERE
                            (m.IsrcCode IS NOT NULL AND m.IsrcCode <> '' AND NOT EXISTS (
                                SELECT 1 FROM tblSongExternalIds e
                                 WHERE e.SongId = m.SongId AND e.IdType = 'isrc' AND e.IdValue = m.IsrcCode))
                         OR (m.MusicBrainzRecordingMBID IS NOT NULL AND m.MusicBrainzRecordingMBID <> '' AND NOT EXISTS (
                                SELECT 1 FROM tblSongExternalIds e
                                 WHERE e.SongId = m.SongId AND e.IdType = 'musicbrainz-recording' AND e.IdValue = m.MusicBrainzRecordingMBID))
                         OR (m.SpotifyTrackId IS NOT NULL AND m.SpotifyTrackId <> '' AND NOT EXISTS (
                                SELECT 1 FROM tblSongExternalIds e
                                 WHERE e.SongId = m.SongId AND e.IdType = 'spotify' AND e.IdValue = m.SpotifyTrackId))
                         OR (m.GeniusTrackId IS NOT NULL AND m.GeniusTrackId <> '' AND NOT EXISTS (
                                SELECT 1 FROM tblSongExternalIds e
                                 WHERE e.SongId = m.SongId AND e.IdType = 'genius' AND e.IdValue = m.GeniusTrackId))
                         LIMIT 1"
                    );
                    if ($r2 && $r2->fetch_row() !== null) return true;
                }
            } catch (\Throwable $e) {
                return true;
            }
            return false;
        },
    ],

    'work-bowi' => [
        'script' => 'migrate-work-bowi.php',
        'card' => [
            'title'  => 'Work BOWI identifier (#1741 D5)',
            'body'   => 'Adds <code>tblWorks.Bowi</code> (Best Open Work'
                      . ' Identifier, Luminate Data&rsquo;s open ISWC'
                      . ' alternative) + the <code>uq_bowi</code> unique key,'
                      . ' mirroring the existing <code>uq_iswc</code>/'
                      . '<code>uq_ccli</code> shape (NULL, not empty string,'
                      . ' so absent values coexist). Its own tiny migration'
                      . ' rather than folded into the already-committed'
                      . ' &ldquo;Works identity schema&rdquo; card above.'
                      . ' Additive, idempotent, dormant — safe to re-run.',
            'button' => 'Run Work BOWI Migration',
        ],
        /* Multi-object OR-probe (rule #19): pending until BOTH the column
           and its UNIQUE key exist. */
        'probe' => static fn(\mysqli $db) =>
               !_migProbe_columnExists($db, 'tblWorks', 'Bowi')
            || !_migProbe_indexExists($db, 'tblWorks', 'uq_bowi'),
    ],
];
