<?php

declare(strict_types=1);

/**
 * iHymns — Songbook CREATE admin write core (#1993, rule #22)
 * ================================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * `/manage/songbooks`'s "Add a songbook" form, the NEW guided "New
 * Songbook" wizard on the same page, and the `admin_songbook_create`
 * native API action all need to do the exact same thing: check a
 * brand-new songbook's fields are sane and insert the row. Before #1993
 * the page and the API each carried their OWN hand-typed copy of this —
 * the classic rule #22 violation — and the two copies had drifted apart:
 * the API was missing DisplayAbbr (#1332) / PublicationCity+PublicationCityId
 * (places adoption) / IsPublicDomain + the OpenLibrary id pair (#1765
 * Features 2/3) / the publisher-registry seed (#1865), and it still
 * accepted an `internet_archive_url` input the page dropped in #1765
 * Feature 7 (that field is now silently ignored — see the scope note
 * below). This file is the ONE place the validation + row-write happens;
 * the page's `create` case, the new `wizard_create_songbook` case and the
 * `admin_songbook_create` API twin all call it.
 *
 * SCOPE — MARCXML stays OUT
 * ----------------------------------------------------------------------------
 * `marcxml_import` (the page's own case AND the API twin
 * `admin_songbook_marcxml_import`) is deliberately NOT folded in here —
 * each still carries its own small inline `INSERT INTO tblSongbooks`
 * (4 columns + a follow-up UPDATE, not this file's 23-bind shape) because
 * a MARCXML record supplies a materially different field set (no
 * Colour/DisplayOrder/IsOfficial/Publisher-picker input, bibliographic
 * fields parsed from the file rather than typed). Folding it into this
 * core is tracked as a follow-up rather than done opportunistically here
 * (see the songbooks.php doc-comment on both `marcxml_import` case bodies
 * for the tracking issue). `tests/php/test-songbook-wizard.php`'s
 * INSERT-singleton guard enumerates all THREE surviving
 * `INSERT INTO tblSongbooks` call sites by file so a stray 4th copy fails
 * the build.
 *
 * WHAT MOVED VERBATIM, WHAT STAYS PER-CALLER
 * ----------------------------------------------------------------------------
 * `songbookAdminValidateCreate()` is `manage/songbooks.php`'s pre-#1993
 * `case 'create':` field-parsing block, moved essentially verbatim — the
 * only real change is the read source, `$_POST` → the passed-in `$in`
 * array, because the page's own `$_POST` and the API's json_decode()'d
 * request body are both plain string-keyed arrays sharing the IDENTICAL
 * snake_case key set, so every caller can pass its raw input straight
 * through unmodified. `songbookAdminCreate()` is the INSERT + its four
 * schema-tolerant secondary UPDATEs + the publisher seed + the #1909
 * webhook emit, also moved verbatim (the INSERT itself is now wrapped so
 * a 1062 unique-key race — extremely rare, since every caller already
 * pre-checks uniqueness in Validate() — throws
 * `SongbookAdminDuplicateAbbreviationException` instead of an opaque
 * `mysqli_sql_exception`, closing the pre-check/INSERT TOCTOU window with
 * the SAME friendly message the pre-check already gives).
 *
 * Everything that differs PER FUNNEL stays in the three call sites, same
 * as #845's `externalLinkTypeAdminCreate()` precedent: the `logActivity()`
 * call (different action key / `via` tag per caller), the affiliation-
 * registry sync (`registerSongbookAffiliation()`), the post-write
 * `songbookMaintenanceRun()` orphan-SongId-prefix sweep, and each funnel's
 * own success message / JSON response shape.
 *
 * @link appWeb/public_html/includes/external_link_type_admin.php  the extraction precedent this mirrors
 * @link appWeb/public_html/includes/songbook_validation.php       validateSongbookAbbr()/…Colour()/…Bcp47() — the ONE grammar validators
 * @link appWeb/public_html/manage/songbooks.php                   page consumer — manual "Add a songbook" form + guided wizard
 * @link appWeb/public_html/api.php                                admin_songbook_create API consumer
 * @see #719 #1332 #1765 #1860 #1865 #1909 #1993 rule #22 rule #27 rule #37 rule #43 rule #44
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'songbook_validation.php';     /* validateSongbookAbbr()/…Colour()/…Bcp47() (+ ilidAbbrIsReserved() transitively) */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'media_identifiers.php';      /* mediaIdentifierPublicationClean() — ark_id / openlibrary-work / openlibrary-edition */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'places.php';                 /* placeColumnExists() */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ilyrics_id.php';             /* ilidStampNewRow() — #1860 go-live */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'publisher_helpers.php';      /* publisherResolvePickedOrCreate() — #1865 */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'webhooks.php';               /* webhookEmitSongbookEvent() — #1909 (dormant no-op) */
/* pickAutoSongbookColour() (#677) — lives in the manage/ tree, same
   sibling-require shape songbooks.php's own top-of-file requires already
   use for a manage/includes/*.php helper. */
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'manage' . DIRECTORY_SEPARATOR
    . 'includes' . DIRECTORY_SEPARATOR . 'songbook-palette.php';

/**
 * Thrown by songbookAdminCreate() when the INSERT itself hits the
 * Abbreviation UNIQUE-key violation (mysqli errno 1062) — a race between
 * two curators creating the same abbreviation at once, or a caller that
 * skipped songbookAdminValidateCreate()'s own pre-check. Callers catch
 * this specifically to respond 409 with the SAME friendly message the
 * pre-check already gives, rather than a generic 500 (closes the
 * pre-check/INSERT TOCTOU window — mirrors
 * ExternalLinkTypeDuplicateSlugException, includes/external_link_type_admin.php).
 */
final class SongbookAdminDuplicateAbbreviationException extends \RuntimeException
{
}

/**
 * Validate + normalise a brand-new songbook's fields, shared by all three
 * create funnels (manual form / guided wizard / API twin). Read-only
 * except for the abbreviation-uniqueness probe (see the class doc-block
 * above for why a race is still possible and how it's closed).
 *
 * @param  array<string,mixed> $in  Raw request fields — the page's own
 *         `$_POST`, the wizard's own `$_POST` or the API's json_decode()'d
 *         body all share the identical snake_case key set, so any of the
 *         three can be passed straight through unmodified.
 * @return array{0:?array<string,mixed>,1:?string,2:int,3:?string}
 *         `[$fields, $error, $httpStatus, $field]` — exactly one of
 *         `$fields`/`$error` is non-null. `$field` (nullable) names which
 *         INPUT the error is about — `'abbreviation'`/`'name'`/`'colour'`/
 *         `'language'`/`'ark_id'`/`'openlibrary_work_id'`/
 *         `'openlibrary_edition_id'` — so a JSON caller (the guided
 *         wizard) can route the curator back to the right STEP by this
 *         structured key rather than pattern-matching `$error`'s prose
 *         (rule #35); the classic page/API callers ignore it and just
 *         show/send `$error`.
 */
function songbookAdminValidateCreate(\mysqli $db, array $in): array
{
    /* @disabled-visible: admin surface (#1765) — the abbreviation-
       uniqueness check spans all books regardless of public disabled
       state. Verbatim from manage/songbooks.php's pre-#1993 case 'create'. */
    $abbr    = trim((string)($in['abbreviation']    ?? ''));
    $name    = trim((string)($in['name']            ?? ''));
    $colour  = trim((string)($in['colour']          ?? ''));
    $order   = (int)($in['display_order']           ?? 0);
    /* #502 — new metadata columns. All nullable; empty input normalises
       to null so the UNIQUE/null-group semantics work as expected. */
    $isOfficial = !empty($in['is_official']) ? 1 : 0;
    $publisher  = trim((string)($in['publisher']        ?? '')) ?: null;
    /* #1865 (rule #37/#43) — the create-form / wizard Publisher field's
       picker-claimed tblPublishers.Id. 0/absent means "nothing was
       picked". Verified server-side by publisherResolvePickedOrCreate()
       in songbookAdminCreate() below — never persisted unverified. */
    $publisherIdClaimed = (int)($in['publisher_id'] ?? 0) ?: null;
    $pubYear    = trim((string)($in['publication_year'] ?? '')) ?: null;
    $copyright  = trim((string)($in['copyright']        ?? '')) ?: null;
    $affiliation= trim((string)($in['affiliation']      ?? '')) ?: null;
    /* #1332 — optional free-text display label (any chars, ≤30); '' → NULL. */
    $displayAbbr = trim((string)($in['display_abbr']    ?? ''));
    $displayAbbr = $displayAbbr !== '' ? mb_substr($displayAbbr, 0, 30) : null;
    /* Places adoption sweep — display string + FK. The FK is populated by
       the place-search JS module when the curator picks a candidate;
       free-typing leaves the hidden id empty so we persist the string only. */
    $publicationCity   = trim((string)($in['publication_city']    ?? '')) ?: null;
    $publicationCityId = (int)($in['publication_city_id'] ?? 0) ?: null;
    /* #673 / #681 — optional language, validated against the v1 IETF
       BCP 47 grammar. Empty selection saves as NULL. */
    $language   = trim((string)($in['language']         ?? '')) ?: null;
    if ($language !== null) {
        $language = mb_substr($language, 0, 35);
        if ($e = validateSongbookBcp47($language)) { return [null, $e, 400, 'language']; }
    }

    /* #672 — bibliographic + authority-control identifiers. All nullable,
       all VARCHAR. trim()→null normalises blank inputs to real NULL. */
    $websiteUrl   = trim((string)($in['website_url']         ?? '')) ?: null;
    /* #1765 Feature 7 — the dedicated Internet Archive URL input was
       removed from the create form; IA links now go through the
       external-links card-list, reachable only after the row exists (the
       Edit modal). A fresh row simply starts with no InternetArchiveUrl.
       Any `internet_archive_url` a caller still posts (the pre-#1993 API
       shape) is now silently ignored here — the same behaviour the page
       has had since #1765 Feature 7, closing the page/API drift #1993 set
       out to fix. */
    $iaUrl        = null;
    $wikipediaUrl = trim((string)($in['wikipedia_url']       ?? '')) ?: null;
    $wikidataId   = trim((string)($in['wikidata_id']         ?? '')) ?: null;
    $oclcNumber   = trim((string)($in['oclc_number']         ?? '')) ?: null;
    $ocnNumber    = trim((string)($in['ocn_number']          ?? '')) ?: null;
    $lcpNumber    = trim((string)($in['lcp_number']          ?? '')) ?: null;
    $isbn         = trim((string)($in['isbn']                ?? '')) ?: null;
    /* #1765 Feature 3 — validated via the ONE shared validator,
       mediaIdentifierPublicationClean(). Empty input → null; a non-empty
       value that doesn't look like a real ARK is rejected. */
    $arkClean = mediaIdentifierPublicationClean('ark', (string)($in['ark_id'] ?? ''));
    if ($arkClean['error'] !== null) { return [null, $arkClean['error'], 400, 'ark_id']; }
    $arkId    = $arkClean['value'];
    $isniId       = trim((string)($in['isni_id']             ?? '')) ?: null;
    $viafId       = trim((string)($in['viaf_id']             ?? '')) ?: null;
    $lccn         = trim((string)($in['lccn']                ?? '')) ?: null;
    $lcClass      = trim((string)($in['lc_class']            ?? '')) ?: null;

    /* #1765 Feature 2 — public-domain flag (informational only, never a
       content gate). Feature 3 — the OpenLibrary Work/Edition id pair,
       same validated-via-shared-helper shape as ArkId above. All three
       are written in a schema-tolerant secondary UPDATE inside
       songbookAdminCreate() rather than folded into the 23-param bind, so
       an un-migrated install degrades to "field simply isn't offered"
       instead of a 500. */
    $isPublicDomain = !empty($in['is_public_domain']) ? 1 : 0;
    $olWorkClean = mediaIdentifierPublicationClean('openlibrary-work', (string)($in['openlibrary_work_id'] ?? ''));
    if ($olWorkClean['error'] !== null) { return [null, $olWorkClean['error'], 400, 'openlibrary_work_id']; }
    $olWorkId    = $olWorkClean['value'];
    $olEditionClean = mediaIdentifierPublicationClean('openlibrary-edition', (string)($in['openlibrary_edition_id'] ?? ''));
    if ($olEditionClean['error'] !== null) { return [null, $olEditionClean['error'], 400, 'openlibrary_edition_id']; }
    $olEditionId    = $olEditionClean['value'];

    if ($e = validateSongbookAbbr($abbr))   { return [null, $e, 400, 'abbreviation']; }
    if ($name === '')                { return [null, 'Name is required.', 400, 'name']; }
    if ($e = validateSongbookColour($colour)) { return [null, $e, 400, 'colour']; }

    /* Auto-colour fallback (#677). When a curator leaves the Colour field
       blank, pick a palette colour the catalogue isn't already using so
       the new badge is visually distinct from neighbouring books. An
       explicit colour wins — this only fires when $colour is empty after
       validation. */
    if ($colour === '') {
        $colour = pickAutoSongbookColour($db, $abbr);
    }

    $stmt = $db->prepare('SELECT Id FROM tblSongbooks WHERE Abbreviation = ?');
    $stmt->bind_param('s', $abbr);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_row() !== null;
    $stmt->close();
    if ($exists) { return [null, 'Abbreviation already exists.', 409, 'abbreviation']; }

    return [[
        'abbr' => $abbr, 'name' => $name, 'colour' => $colour, 'order' => $order,
        'isOfficial' => $isOfficial, 'publisher' => $publisher,
        'publisherIdClaimed' => $publisherIdClaimed, 'pubYear' => $pubYear,
        'copyright' => $copyright, 'affiliation' => $affiliation,
        'displayAbbr' => $displayAbbr,
        'publicationCity' => $publicationCity, 'publicationCityId' => $publicationCityId,
        'language' => $language,
        'websiteUrl' => $websiteUrl, 'iaUrl' => $iaUrl, 'wikipediaUrl' => $wikipediaUrl,
        'wikidataId' => $wikidataId, 'oclcNumber' => $oclcNumber, 'ocnNumber' => $ocnNumber,
        'lcpNumber' => $lcpNumber, 'isbn' => $isbn, 'arkId' => $arkId, 'isniId' => $isniId,
        'viafId' => $viafId, 'lccn' => $lccn, 'lcClass' => $lcClass,
        'isPublicDomain' => $isPublicDomain, 'olWorkId' => $olWorkId, 'olEditionId' => $olEditionId,
    ], null, 200, null];
}

/**
 * THE ONE CREATOR — inserts a brand-new `tblSongbooks` row plus its four
 * schema-tolerant secondary UPDATEs, the #1865 publisher seed and the
 * #1909 webhook emit (#1993). Caller has already validated via
 * songbookAdminValidateCreate() (so `$fields` is the validated/normalised
 * shape that function returns).
 *
 * @param  array<string,mixed> $fields  The validated shape from
 *         songbookAdminValidateCreate().
 * @param  array{hasPublishersSchema?:bool,hasPubDomainCol?:bool,hasOpenLibraryCols?:bool} $flags
 *         The caller's own hoisted schema probes — computed ONCE per
 *         request by the page (manage/songbooks.php:845/899-902) and
 *         computed inline by the API/wizard callers. PublicationCityId
 *         and DisplayAbbr stay probed INLINE here via placeColumnExists()
 *         (memoised per-request already), matching the pre-#1993 page
 *         code exactly rather than growing $flags for probes nothing
 *         hoists.
 * @return array{id:int,colour:string,publisherId:?int}
 * @throws SongbookAdminDuplicateAbbreviationException on a uq_Abbreviation
 *         race (1062) — belt-and-braces on top of the pre-check in
 *         songbookAdminValidateCreate().
 */
function songbookAdminCreate(\mysqli $db, array $fields, array $flags): array
{
    $abbr        = (string)$fields['abbr'];
    $name        = (string)$fields['name'];
    $orderInt    = (int)($fields['order'] ?: 0);
    $colour      = (string)$fields['colour'];
    $isOfficial  = (int)$fields['isOfficial'];
    $publisher   = $fields['publisher'];
    $pubYear     = $fields['pubYear'];
    $copyright   = $fields['copyright'];
    $affiliation = $fields['affiliation'];
    $language    = $fields['language'];
    $websiteUrl   = $fields['websiteUrl'];
    $iaUrl        = $fields['iaUrl'];
    $wikipediaUrl = $fields['wikipediaUrl'];
    $wikidataId   = $fields['wikidataId'];
    $oclcNumber   = $fields['oclcNumber'];
    $ocnNumber    = $fields['ocnNumber'];
    $lcpNumber    = $fields['lcpNumber'];
    $isbn         = $fields['isbn'];
    $arkId        = $fields['arkId'];
    $isniId       = $fields['isniId'];
    $viafId       = $fields['viafId'];
    $lccn         = $fields['lccn'];
    $lcClass      = $fields['lcClass'];

    $stmt = $db->prepare(
        'INSERT INTO tblSongbooks
            (Abbreviation, Name, DisplayOrder, Colour,
             IsOfficial, Publisher, PublicationYear, Copyright, Affiliation,
             Language,
             WebsiteUrl, InternetArchiveUrl, WikipediaUrl, WikidataId,
             OclcNumber, OcnNumber, LcpNumber, Isbn, ArkId, IsniId,
             ViafId, Lccn, LcClass)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?,
                 ?,
                 ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    /* 23-char type string mirrors the pre-#1993 web admin save (#694
       regression check): ssis (Abbr,Name,Order,Colour) + isssss
       (IsOfficial,Publisher,Year,Copyright,Affiliation) + s (Language) +
       13 × s (bibliographic identifiers).
       Wrapped so a uq_Abbreviation race (1062 — two curators racing the
       SAME abbreviation past the Validate() pre-check) is reported the
       SAME friendly way the pre-check already is, instead of an opaque
       mysqli_sql_exception (#1993 — closes the pre-check/INSERT TOCTOU). */
    try {
        $stmt->bind_param(
            'ssisissssssssssssssssss',
            $abbr, $name, $orderInt, $colour,
            $isOfficial, $publisher, $pubYear, $copyright, $affiliation,
            $language,
            $websiteUrl, $iaUrl, $wikipediaUrl, $wikidataId,
            $oclcNumber, $ocnNumber, $lcpNumber, $isbn, $arkId, $isniId,
            $viafId, $lccn, $lcClass
        );
        $stmt->execute();
    } catch (\mysqli_sql_exception $e) {
        $stmt->close();
        if ((int)$e->getCode() === 1062) {
            throw new SongbookAdminDuplicateAbbreviationException(
                'Abbreviation already exists.', 409, $e
            );
        }
        throw $e;
    }
    $newId = (int)$db->insert_id;
    $stmt->close();
    /* #1860 go-live — mint this songbook's permanent IL-id (ILB…). */
    ilidStampNewRow($db, 'songbook', $newId);

    /* Place columns — schema-tolerant separate UPDATE so the carefully-
       tuned 23-bind INSERT above stays untouched. Skipped on pre-adoption-
       migration installs (probe returns false). */
    if (placeColumnExists($db, 'tblSongbooks', 'PublicationCityId')) {
        $stmt = $db->prepare('UPDATE tblSongbooks SET PublicationCity = ?, PublicationCityId = ? WHERE Id = ?');
        $publicationCity   = $fields['publicationCity'];
        $publicationCityId = $fields['publicationCityId'];
        $stmt->bind_param('sii', $publicationCity, $publicationCityId, $newId);
        $stmt->execute();
        $stmt->close();
    }
    /* #1332 — display label in a schema-tolerant separate UPDATE (skipped
       pre-migration), same pattern as the place columns. */
    if (placeColumnExists($db, 'tblSongbooks', 'DisplayAbbr')) {
        $stmt = $db->prepare('UPDATE tblSongbooks SET DisplayAbbr = ? WHERE Id = ?');
        $displayAbbr = $fields['displayAbbr'];
        $stmt->bind_param('si', $displayAbbr, $newId);
        $stmt->execute();
        $stmt->close();
    }
    /* #1765 Feature 2 — public-domain flag, schema-tolerant separate
       UPDATE (skipped pre-migration). */
    if (!empty($flags['hasPubDomainCol'])) {
        $stmt = $db->prepare('UPDATE tblSongbooks SET IsPublicDomain = ? WHERE Id = ?');
        $isPublicDomain = (int)$fields['isPublicDomain'];
        $stmt->bind_param('ii', $isPublicDomain, $newId);
        $stmt->execute();
        $stmt->close();
    }
    /* #1765 Feature 3 — OpenLibrary Work/Edition id pair, same schema-
       tolerant pattern. Written together (both columns land in the same
       migration stage) so a partial-apply install skips both. */
    if (!empty($flags['hasOpenLibraryCols'])) {
        $stmt = $db->prepare('UPDATE tblSongbooks SET OpenLibraryWorkId = ?, OpenLibraryEditionId = ? WHERE Id = ?');
        $olWorkId    = $fields['olWorkId'];
        $olEditionId = $fields['olEditionId'];
        $stmt->bind_param('ssi', $olWorkId, $olEditionId, $newId);
        $stmt->execute();
        $stmt->close();
    }
    /* #1865 (rule #37/#43) — a brand-new songbook has no Edit modal yet,
       so the create-time Publisher field is the ONLY publisher-linking
       surface at create time. Resolve it through the ONE shared funnel —
       publisherResolvePickedOrCreate() — trusting a verified picker claim
       over a name-only resolve (tblPublishers has no uq_Name; rule #37),
       then seed the M:N link exactly the way the Edit arm's own richer
       multi-publisher reconciliation does — same tables, same shape, no
       second sync path. An empty field, or a pre-migration install
       without tblSongbookPublishers, leaves the songbook exactly as
       before this feature: display-string-only. */
    $createPublisherId = null;
    if (!empty($flags['hasPublishersSchema'])) {
        $publisherIdClaimed = $fields['publisherIdClaimed'];
        $createPublisherId = publisherResolvePickedOrCreate($db, (string)$publisher, $publisherIdClaimed);
        if ($createPublisherId !== null) {
            $pubRole = 'publisher';
            $stmt = $db->prepare(
                'INSERT INTO tblSongbookPublishers (SongbookId, PublisherId, Role, SortOrder) VALUES (?, ?, ?, 0)'
            );
            $stmt->bind_param('iis', $newId, $createPublisherId, $pubRole);
            $stmt->execute();
            $stmt->close();

            /* Denorm sync — the free-text Publisher mirror follows the
               registry Name exactly (rule #37), same shape as the Edit
               arm's primary-publisher resync. */
            $nameStmt = $db->prepare('SELECT Name FROM tblPublishers WHERE Id = ?');
            $nameStmt->bind_param('i', $createPublisherId);
            $nameStmt->execute();
            $resolvedName = $nameStmt->get_result()->fetch_assoc()['Name'] ?? null;
            $nameStmt->close();
            if ($resolvedName !== null) {
                $upd = $db->prepare('UPDATE tblSongbooks SET Publisher = ? WHERE Id = ?');
                $upd->bind_param('si', $resolvedName, $newId);
                $upd->execute();
                $upd->close();
            }
        }
    }

    /* #1909 — partner webhook (dormant no-op until enabled). */
    webhookEmitSongbookEvent($db, 'songbook.created', $abbr,
        ['title' => $name, 'is_official' => (bool)$isOfficial], ['source' => 'admin']);

    return ['id' => $newId, 'colour' => $colour, 'publisherId' => $createPublisherId];
}
