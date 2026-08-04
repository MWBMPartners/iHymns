<?php

declare(strict_types=1);

/**
 * iHymns — MARCXML admin wiring helper
 * (Songbook/Catalogue Enhancements epic, #1765, commit 6 — rule #22)
 *
 * ELI5
 * ----
 * The owner asked for MARCXML (the library-catalogue interchange format)
 * file support on Songbooks, Songbook Series and Collections. The heavy
 * lifting — parsing MARCXML into entity fields and generating MARCXML from
 * entity fields, XXE-safely — already lives in the pure, DB-free
 * `includes/marcxml.php` (shipped commit 1, with its own test). This file
 * is the thin ADMIN glue the three `/manage/*` pages share: turn a page's
 * request into either a downloadable MARCXML file (export) or a parsed set
 * of fields ready to create an entity from (import). Keeping it here means
 * the three pages don't each hand-roll the upload validation + the
 * download headers.
 *
 * DETAILED
 * --------
 * - Export: `marcxmlAdmin_sendExport()` drops empty fields, calls
 *   `marcxmlGenerate()` (the pure generator), and streams the result as an
 *   attachment. It EXITS — export is a terminal response, emitted before any
 *   page HTML (same posture as the JSON typeahead endpoints at the top of
 *   each admin page).
 * - Import: `marcxmlAdmin_parseUpload()` validates the `$_FILES` entry
 *   (present / non-empty / under the size cap / readable), parses it
 *   (catching the XXE-safe parser's throw into a friendly message), and maps
 *   the FIRST record to entity fields via `marcxmlMapToEntity()`. It never
 *   touches the DB — each page owns its own INSERT (the three tables differ
 *   in columns, slug/abbreviation derivation, and dedup), so this helper
 *   returns the mapped fields and lets the caller create the row.
 *
 * @link appWeb/public_html/includes/marcxml.php  the pure parse/map/generate core
 * @link appWeb/public_html/manage/songbooks.php        export + import call sites
 * @link appWeb/public_html/manage/songbook-series.php   export + import call sites
 * @link appWeb/public_html/manage/catalogues.php        export + import call sites
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'marcxml.php';

/**
 * Stream one entity as a downloadable MARCXML file, then exit.
 *
 * @param array<string,mixed> $fields     column => value (empties are dropped)
 * @param list<string>        $altTitles  alternative titles ($245 $b / repeats)
 * @param list<string>        $links      still-unclassified URLs → 856
 * @param string              $kind       'songbook' | 'series' | 'catalogue'
 * @param string              $filenameBase  human filename stem (sanitised)
 */
function marcxmlAdmin_sendExport(array $fields, array $altTitles, array $links, string $kind, string $filenameBase): void
{
    $fields = array_filter($fields, static fn($v): bool => $v !== null && $v !== '');
    $xml = marcxmlGenerate([
        'fields'    => $fields,
        'altTitles' => array_values(array_filter($altTitles, static fn($v): bool => is_string($v) && $v !== '')),
        'links'     => array_values(array_filter($links, static fn($v): bool => is_string($v) && $v !== '')),
    ], $kind);

    /* Sanitise the filename stem to a safe ASCII slug — never trust an
       entity Name straight into a Content-Disposition header. */
    $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', $filenameBase) ?? '';
    $safe = trim($safe, '_');
    if ($safe === '') { $safe = $kind; }

    header('Content-Type: application/marcxml+xml; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $safe . '.marcxml.xml"');
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . strlen($xml));
    echo $xml;
    exit;
}

/**
 * Validate + parse an uploaded MARCXML file into mapped entity fields.
 * Never touches the database.
 *
 * @param array $file  a single `$_FILES[...]` entry
 * @param string $kind 'songbook' | 'series' | 'catalogue'
 * @return array{ok:bool,error:?string,fields:array<string,string>,altTitles:list<string>,links:list<string>,unmapped:list<string>,recordCount:int}
 */
function marcxmlAdmin_parseUpload(array $file, string $kind): array
{
    $fail = static fn(string $m): array => [
        'ok' => false, 'error' => $m,
        'fields' => [], 'altTitles' => [], 'links' => [], 'unmapped' => [], 'recordCount' => 0,
    ];

    $uploadErr = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($uploadErr === UPLOAD_ERR_NO_FILE) { return $fail('No MARCXML file was chosen.'); }
    if ($uploadErr !== UPLOAD_ERR_OK)      { return $fail('The file upload failed (error code ' . (int)$uploadErr . ').'); }

    $size = (int)($file['size'] ?? 0);
    if ($size <= 0)               { return $fail('The uploaded file is empty.'); }
    if ($size > 2 * 1024 * 1024)  { return $fail('The MARCXML file is too large (2 MB maximum).'); }

    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_readable($tmp)) { return $fail('The uploaded file could not be read.'); }

    $xml = (string)file_get_contents($tmp);
    if (trim($xml) === '') { return $fail('The uploaded file is empty.'); }

    try {
        $records = marcxmlParse($xml);
    } catch (\Throwable $e) {
        return $fail('That does not look like valid MARCXML: ' . $e->getMessage());
    }
    if (!is_array($records) || $records === []) {
        return $fail('No <record> element was found in the MARCXML file.');
    }

    $entity = marcxmlMapToEntity($records[0], $kind);
    return [
        'ok'          => true,
        'error'       => null,
        'fields'      => $entity['fields']    ?? [],
        'altTitles'   => $entity['altTitles'] ?? [],
        'links'       => $entity['links']     ?? [],
        'unmapped'    => $entity['unmapped']  ?? [],
        'recordCount' => count($records),
    ];
}

/**
 * Run the identifier fields from a parsed MARCXML record through the ONE
 * shared publication-identifier validator (mediaIdentifierPublicationClean),
 * keeping the valid ones and reporting which were dropped. Used by the three
 * admin pages' MARCXML IMPORT handlers so a single slightly-off identifier in
 * an otherwise-good record does NOT block the whole import — the core record
 * is created and the bad identifier is skipped with a note (rule #22: one
 * validator, one place).
 *
 * @param array<string,mixed>  $fields   marcxmlMapToEntity()'s fields map
 * @param array<string,string> $colToSlug  column name => PUBLICATION_IDENTIFIER_TYPES slug
 *                              (e.g. ['Isbn'=>'isbn','ArkId'=>'ark', …])
 * @return array{0:array<string,?string>,1:list<string>} [valid col=>value, list of "Col (value): why" skips]
 */
function marcxmlAdmin_cleanPublicationIdentifiers(array $fields, array $colToSlug): array
{
    require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'media_identifiers.php';
    $valid   = [];
    $skipped = [];
    foreach ($colToSlug as $col => $slug) {
        $raw = (string)($fields[$col] ?? '');
        if (trim($raw) === '') { $valid[$col] = null; continue; }
        $clean = mediaIdentifierPublicationClean($slug, $raw);
        if ($clean['error'] !== null) {
            $valid[$col] = null;
            $skipped[]   = "{$col} (" . $raw . ')';
        } else {
            $valid[$col] = $clean['value'];
        }
    }
    return [$valid, $skipped];
}
