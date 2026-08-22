<?php

declare(strict_types=1);

/**
 * iHymns — Organisation logo admin CRUD shared core (#1830)
 * ================================================================================
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * ELI5
 * ----
 * `/manage/organisations` (system admins) and `/manage/my-organisations`
 * (org admins) both need to do the SAME things when a curator uploads,
 * removes, or hides a logo: check the file is actually a safe picture,
 * save it, and update it later. This file is the ONE place each of those
 * is written — both pages call these functions instead of re-typing their
 * own copy (rule #22 / #35). Modelled line-for-line on the
 * `includes/publisher_admin.php` + `includes/publisher_helpers.php` split
 * (rule #37) and on `SongMediaStorage::validateUpload()`'s upload-safety
 * pipeline (rule #22/#5's "uploads precedent").
 *
 * DETAILED / THE VALIDATION PIPELINE (§4.4 of the plan)
 * ----------------------------------------------------------------------------
 * `orgLogoValidateAndStage()` NEVER trusts `$_FILES['type']` or a filename
 * suffix — it `finfo`-sniffs the actual bytes (the `SongMediaStorage`
 * precedent), then branches:
 *   - PNG (covers APNG — an APNG IS a PNG container, `finfo` reports
 *     `image/png` for both): 8-byte magic re-checked explicitly, dimension
 *     cap via `getimagesize()`, bytes stored UNCHANGED (no re-encode — that
 *     would strip APNG animation frames).
 *   - SVG: the ONLY path into `includes/svg_sanitizer.php`'s
 *     `ihymnsSanitizeSvg()` — a `null` result is a REJECT, never a
 *     partial-repair; the sanitiser's OWN output (never the raw upload)
 *     is what gets stored in `ContentSanitised`, with the untouched
 *     upload kept in the dormant `ContentOriginal` (never served).
 *   - anything else: rejected with a plain-English message.
 * Every reject throws `\RuntimeException` with wording straight out of
 * `.claude/admin-plain-english.md` / the plan's §7.2 quotes — the caller
 * (a `/manage/*` form handler) catches it and shows the message as-is,
 * never a stack trace, never jargon.
 *
 * `orgLogoUpsert()` validates `$kind`/`$variant` against the ONE registry
 * (`includes/org_logo_helpers.php`) BEFORE any SQL — a crafted POST can
 * never smuggle an unvalidated kind into the table. An org holds AT MOST
 * ONE logo per `(kind, variant)` — a re-upload REPLACES the row via
 * `INSERT … ON DUPLICATE KEY UPDATE` on `uq_OrgKindVariant`, never a
 * second insert.
 *
 * @link appWeb/public_html/includes/org_logo_helpers.php  vocab + reads (the sibling half of this split)
 * @link appWeb/public_html/includes/svg_sanitizer.php      the ONE SVG hardening path this file's SVG branch calls
 * @link appWeb/public_html/includes/SongMediaStorage.php   the finfo-sniff-never-trust-the-client upload precedent
 * @link appWeb/public_html/includes/publisher_admin.php    the CRUD-core split precedent this mirrors (rule #37)
 * @see .claude/org-logos-1830-plan.md §4  the full design this file implements
 * @see #1830
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'org_logo_helpers.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'svg_sanitizer.php';

/**
 * Validate + stage an uploaded logo file's bytes (§4.4 pipeline). Never
 * touches the database — pure validation + transformation over a temp-file
 * path, so it is unit-testable without a `$_FILES` superglobal or a
 * connection (the caller supplies `$tmpPath`/`$sizeBytes`, which for a real
 * upload come from `$_FILES['logo_file']['tmp_name']`/`['size']`).
 *
 * @param  string $tmpPath    Path to the uploaded (or test fixture) file.
 * @param  int    $sizeBytes  Reported size in bytes (PHP's own upload size,
 *                              or `filesize($tmpPath)` for a test fixture).
 * @return array{mime:string, content:string, originalContent:?string,
 *               width:?int, height:?int, sanitiserVersion:int,
 *               storageBackend:string, sha256:string, byteSize:int}
 * @throws \RuntimeException  A PLAIN-ENGLISH message on any validation
 *                              failure — safe to show a curator verbatim.
 */
function orgLogoValidateAndStage(string $tmpPath, int $sizeBytes): array
{
    /* ---- 1. Coarse pre-sniff size gate (the LARGER of the two per-type caps) ---- */
    $largerCap = max(IHYMNS_ORG_LOGO_MAX_SVG_BYTES, IHYMNS_ORG_LOGO_MAX_PNG_BYTES);
    if ($sizeBytes <= 0) {
        throw new \RuntimeException('That upload was empty. Please choose a file and try again.');
    }
    if ($sizeBytes > $largerCap) {
        $capMb = (int)round($largerCap / (1024 * 1024));
        throw new \RuntimeException("That file is too large for a logo (max {$capMb} MB).");
    }
    if (!is_readable($tmpPath)) {
        throw new \RuntimeException('The uploaded file could not be read. Please try again.');
    }

    /* ---- 2. finfo MIME sniff on the ACTUAL bytes — never $_FILES['type'] ---- */
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo === false) {
        throw new \RuntimeException('Could not check the uploaded file. Please try again.');
    }
    $sniffedMime = finfo_file($finfo, $tmpPath) ?: '';
    finfo_close($finfo);

    /* ---- 3. Branch on sniffed MIME ---- */
    if ($sniffedMime === 'image/png') {
        return orgLogoStagePng($tmpPath, $sizeBytes);
    }
    /* Lean/hand-written SVGs sometimes sniff as text/xml or even text/plain —
       accepted ONLY IF ihymnsSanitizeSvg() itself then succeeds; its own
       root-element check is the real arbiter of "is this actually SVG"
       (§4.4's documented carve-out). */
    if (in_array($sniffedMime, ['image/svg+xml', 'text/xml', 'text/plain'], true)) {
        $staged = orgLogoStageSvg($tmpPath, $sizeBytes);
        if ($staged !== null) {
            return $staged;
        }
        /* A text/xml or text/plain sniff that ISN'T actually a safe SVG
           falls through to the generic reject below, never a special
           "your XML failed" message that would leak sanitiser internals. */
        if ($sniffedMime === 'image/svg+xml') {
            throw new \RuntimeException(
                "That file couldn't be read as a safe logo. Please export a plain SVG without "
                . 'scripts or embedded images and try again.'
            );
        }
    }

    throw new \RuntimeException('Logos must be SVG or PNG files.');
}

/**
 * Stage a PNG (or APNG — an APNG is a valid PNG container, `finfo` reports
 * `image/png` for both) upload. Bytes are stored UNCHANGED — re-encoding
 * via GD would strip APNG animation frames — after re-checking the 8-byte
 * PNG magic (defence in depth over the `finfo` sniff) and a dimension cap
 * via `getimagesize()`.
 *
 * @throws \RuntimeException  Plain-English message on any failure.
 */
function orgLogoStagePng(string $tmpPath, int $sizeBytes): array
{
    if ($sizeBytes > IHYMNS_ORG_LOGO_MAX_PNG_BYTES) {
        $capMb = (int)round(IHYMNS_ORG_LOGO_MAX_PNG_BYTES / (1024 * 1024));
        throw new \RuntimeException("That PNG is too large for a logo (max {$capMb} MB).");
    }

    $magic = file_get_contents($tmpPath, false, null, 0, 8);
    if ($magic === false || $magic !== "\x89PNG\r\n\x1a\n") {
        throw new \RuntimeException('That file is not a valid PNG image.');
    }

    $dims = @getimagesize($tmpPath);
    if ($dims === false || !isset($dims[0], $dims[1])) {
        throw new \RuntimeException('That file could not be read as an image.');
    }
    $width  = (int)$dims[0];
    $height = (int)$dims[1];
    if ($width <= 0 || $height <= 0
        || $width > IHYMNS_ORG_LOGO_MAX_DIMENSION || $height > IHYMNS_ORG_LOGO_MAX_DIMENSION) {
        throw new \RuntimeException(
            'That image is too large (max ' . IHYMNS_ORG_LOGO_MAX_DIMENSION . 'px on either side).'
        );
    }

    $bytes = file_get_contents($tmpPath);
    if ($bytes === false) {
        throw new \RuntimeException('The uploaded file could not be read. Please try again.');
    }

    return [
        'mime'             => 'image/png',
        'content'          => $bytes,
        'originalContent'  => null, // raster rows never carry a dormant "original" copy
        'width'            => $width,
        'height'           => $height,
        'sanitiserVersion' => 0,    // not applicable — no sanitiser ran
        'storageBackend'   => 'database',
        'sha256'           => hash('sha256', $bytes),
        'byteSize'         => strlen($bytes),
    ];
}

/**
 * Stage an SVG upload through `includes/svg_sanitizer.php`'s
 * `ihymnsSanitizeSvg()` — the ONE hardened path any SVG bytes reach before
 * ever being stored (rule: never a second sanitiser fork).
 *
 * @return array{mime:string, content:string, originalContent:string,
 *               width:?int, height:?int, sanitiserVersion:int,
 *               storageBackend:string, sha256:string, byteSize:int}|null
 *         null when the upload wasn't valid SVG at all (caller decides the
 *         reject wording) or over the SVG byte cap.
 */
function orgLogoStageSvg(string $tmpPath, int $sizeBytes): ?array
{
    if ($sizeBytes > IHYMNS_ORG_LOGO_MAX_SVG_BYTES) {
        $capKb = (int)round(IHYMNS_ORG_LOGO_MAX_SVG_BYTES / 1024);
        throw new \RuntimeException("That SVG is too large for a logo (max {$capKb} KB).");
    }

    $original = file_get_contents($tmpPath);
    if ($original === false) {
        throw new \RuntimeException('The uploaded file could not be read. Please try again.');
    }

    $sanitised = ihymnsSanitizeSvg($original);
    if ($sanitised === null) {
        return null; // caller (orgLogoValidateAndStage) turns this into the reject message
    }

    return [
        'mime'             => 'image/svg+xml',
        'content'          => $sanitised['bytes'],
        'originalContent'  => $original, // dormant — never served, sole re-sanitise source
        'width'            => $sanitised['width'],
        'height'           => $sanitised['height'],
        'sanitiserVersion' => IHYMNS_SVG_SANITISER_VERSION,
        'storageBackend'   => 'database',
        'sha256'           => hash('sha256', $sanitised['bytes']),
        'byteSize'         => strlen($sanitised['bytes']),
    ];
}

/**
 * Upsert one `(OrgId, Kind, Variant)` logo row — a re-upload REPLACES the
 * row (`ON DUPLICATE KEY UPDATE` on `uq_OrgKindVariant`), never a second
 * insert. `$kind`/`$variant` are validated against the ONE registry BEFORE
 * any SQL touches the database — a crafted POST cannot smuggle an
 * unvalidated kind past this function.
 *
 * @param  array $staged  `orgLogoValidateAndStage()`'s return shape.
 * @return int  The row's `Id`.
 * @throws \InvalidArgumentException  On an unknown `$kind`/`$variant`.
 */
function orgLogoUpsert(
    \mysqli $db,
    int $orgId,
    string $kind,
    string $variant,
    array $staged,
    ?string $altText,
    ?int $userId
): int {
    if (!in_array($kind, ihymnsOrgLogoKindKeys(), true)) {
        throw new \InvalidArgumentException("Unknown logo kind '{$kind}'.");
    }
    if (!in_array($variant, IHYMNS_ORG_LOGO_VARIANTS, true)) {
        throw new \InvalidArgumentException("Unknown logo variant '{$variant}'.");
    }
    if ($orgId <= 0) {
        throw new \InvalidArgumentException('Invalid organisation.');
    }

    $altText = ($altText !== null && trim($altText) !== '') ? mb_substr(trim($altText), 0, 255) : null;

    $stmt = $db->prepare(
        'INSERT INTO tblOrganisationLogos
            (OrgId, Kind, Variant, Mime, Width, Height, ByteSize, Sha256,
             StorageBackend, ContentSanitised, ContentOriginal, SanitiserVersion,
             AltText, IsActive, UploadedBy)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)
         ON DUPLICATE KEY UPDATE
            Mime              = VALUES(Mime),
            Width             = VALUES(Width),
            Height            = VALUES(Height),
            ByteSize          = VALUES(ByteSize),
            Sha256            = VALUES(Sha256),
            StorageBackend    = VALUES(StorageBackend),
            ContentSanitised  = VALUES(ContentSanitised),
            ContentOriginal   = VALUES(ContentOriginal),
            SanitiserVersion  = VALUES(SanitiserVersion),
            AltText           = VALUES(AltText),
            IsActive          = 1,
            UploadedBy        = VALUES(UploadedBy)'
    );
    $stmt->bind_param(
        'isssiiissssisi',
        $orgId,
        $kind,
        $variant,
        $staged['mime'],
        $staged['width'],
        $staged['height'],
        $staged['byteSize'],
        $staged['sha256'],
        $staged['storageBackend'],
        $staged['content'],
        $staged['originalContent'],
        $staged['sanitiserVersion'],
        $altText,
        $userId
    );
    $stmt->execute();
    $insertId = (int)$db->insert_id;
    $stmt->close();

    if ($insertId > 0) {
        return $insertId;
    }
    /* ON DUPLICATE KEY UPDATE leaves insert_id at 0 on some MySQL/MariaDB
       versions when the row was unchanged; re-select the row's real Id. */
    $sel = $db->prepare('SELECT Id FROM tblOrganisationLogos WHERE OrgId = ? AND Kind = ? AND Variant = ? LIMIT 1');
    $sel->bind_param('iss', $orgId, $kind, $variant);
    $sel->execute();
    $row = $sel->get_result()->fetch_assoc();
    $sel->close();
    return (int)($row['Id'] ?? 0);
}

/**
 * Delete one `(OrgId, Kind, Variant)` logo row outright (the admin card's
 * "Remove" button, §7.2 — confirmed client-side before the POST).
 */
function orgLogoDelete(\mysqli $db, int $orgId, string $kind, string $variant): bool
{
    if ($orgId <= 0 || !in_array($kind, ihymnsOrgLogoKindKeys(), true) || !in_array($variant, IHYMNS_ORG_LOGO_VARIANTS, true)) {
        return false;
    }
    $stmt = $db->prepare('DELETE FROM tblOrganisationLogos WHERE OrgId = ? AND Kind = ? AND Variant = ?');
    $stmt->bind_param('iss', $orgId, $kind, $variant);
    $stmt->execute();
    $affected = $stmt->affected_rows > 0;
    $stmt->close();
    return $affected;
}

/**
 * Toggle a logo row's `IsActive` flag WITHOUT deleting the upload (§7.2's
 * "active toggle" — hides a logo from every surface, e.g. `org-logo.php`'s
 * `orgLogoFetchServeRow()`, without losing the bytes).
 */
function orgLogoSetActive(\mysqli $db, int $orgId, string $kind, string $variant, bool $active): bool
{
    if ($orgId <= 0 || !in_array($kind, ihymnsOrgLogoKindKeys(), true) || !in_array($variant, IHYMNS_ORG_LOGO_VARIANTS, true)) {
        return false;
    }
    $activeInt = $active ? 1 : 0;
    $stmt = $db->prepare('UPDATE tblOrganisationLogos SET IsActive = ? WHERE OrgId = ? AND Kind = ? AND Variant = ?');
    $stmt->bind_param('iiss', $activeInt, $orgId, $kind, $variant);
    $stmt->execute();
    $affected = $stmt->affected_rows > 0;
    $stmt->close();
    return $affected;
}

/**
 * #1840 — delete EVERY variant row of one `(OrgId, Kind)` in one go — the
 * `logo_remove` handler calls this when a curator removes a kind's
 * `'default'` row, so its `light`/`dark` theme versions don't survive as
 * invisible orphans the admin card can no longer manage (one theme would
 * keep silently rendering them, the other would silently show nothing —
 * exactly the half-feature state this codebase refuses to mint). Removing
 * an EXPLICIT `light`/`dark` row alone still goes through the plain
 * `orgLogoDelete()` above — only a `'default'`-row removal cascades.
 *
 * @return int  Number of rows actually deleted (0..3 — default + light + dark).
 */
function orgLogoDeleteKindAll(\mysqli $db, int $orgId, string $kind): int
{
    if ($orgId <= 0 || !in_array($kind, ihymnsOrgLogoKindKeys(), true)) {
        return 0;
    }
    $stmt = $db->prepare('DELETE FROM tblOrganisationLogos WHERE OrgId = ? AND Kind = ?');
    $stmt->bind_param('is', $orgId, $kind);
    $stmt->execute();
    $affected = (int)$stmt->affected_rows;
    $stmt->close();
    return max(0, $affected);
}

/**
 * #1840 — toggle `IsActive` for EVERY variant row of one `(OrgId, Kind)` at
 * once (unlike `orgLogoSetActive()` above, which is variant-scoped). The
 * admin card's Show/Hide control is deliberately ONE switch per KIND
 * (per-asset visibility), not one per rendition — a kind half-hidden (its
 * `'default'` shown, its `'dark'` still hidden or vice-versa) is another
 * silent-half state that would only misbehave on one theme, so `logo_toggle`
 * calls this instead of looping `orgLogoSetActive()` per variant.
 */
function orgLogoSetActiveKind(\mysqli $db, int $orgId, string $kind, bool $active): bool
{
    if ($orgId <= 0 || !in_array($kind, ihymnsOrgLogoKindKeys(), true)) {
        return false;
    }
    $activeInt = $active ? 1 : 0;
    $stmt = $db->prepare('UPDATE tblOrganisationLogos SET IsActive = ? WHERE OrgId = ? AND Kind = ?');
    $stmt->bind_param('iis', $activeInt, $orgId, $kind);
    $stmt->execute();
    $affected = $stmt->affected_rows > 0;
    $stmt->close();
    return $affected;
}
