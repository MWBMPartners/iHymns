<?php

declare(strict_types=1);

/**
 * iHymns — per-line lyric enrichment write/read layer (#1235 P3 / #1088)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * The ONE place that creates / edits / deletes / loads per-line **translations**
 * (`tblLyricLineTranslations`) and **annotations** (`tblLyricLineAnnotations`),
 * both anchored on the normalised `tblLyricLines.Id` (rule #21). The web editor
 * (api2.php) and, later, the native write API (#1201) both call these — so the
 * enrichment contract lives in one shared module, never forked per client (the
 * modularity rule). This IS the iLyricsDB shared data contract for line
 * enrichment.
 *
 * Safe in the P1–P3 transition: enrichment FKs `tblLyricLines.Id`, and the
 * Id-preserving diff in lyric_lines_sync.php keeps those Ids across an edit, so a
 * translation/annotation survives a normal lyric save. (Per-line *language* is
 * different — it is a column the projector rewrites from the component, so it has
 * its own durable home via tblSongComponents.LanguagesJson, handled there.)
 *
 * Design rules encoded here:
 *  - Growable vocab is VARCHAR validated against a central map (rule #20), never
 *    ENUM — the *_VOCAB constants below are those maps.
 *  - LyricsId is DERIVED from the line, never trusted from the caller (schema
 *    comment on tblLyricLineTranslations.LyricsId).
 *  - Ownership is enforced: every line referenced must belong to a lyrics version
 *    of the target song, or the call 400s — a caller can't enrich another song's
 *    lines by guessing an Id.
 *  - Offsets are 0-based UTF-8 CODE-POINT indices (rule #21), validated against
 *    the line's code-point length with mb_* — never byte/UTF-16.
 *  - Every value is bound; the only interpolated SQL fragments are constants.
 *
 * Requires getDbMysqli()-style \mysqli (caller supplies it) under
 * MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT (failing statements throw). Validation
 * failures throw \InvalidArgumentException (caller → HTTP 400); not-found / ownership
 * failures throw \RuntimeException with a ::class marker the caller maps to 404/403.
 *
 * @see https://www.rfc-editor.org/info/bcp47   (language tags)
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

/* The canonical DB layer — getDbMysqli() + the bindParamSafe() count-guard (#928)
   every DB function below binds through. Lazy: requiring it opens no connection. */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'db_mysql.php';

/* ----------------------------------------------------------- Vocabularies --- */
/* Central allow-lists for the growable VARCHAR vocab (rule #20). Mirror the
   schema column COMMENTs in schema.sql (tblLyricLineTranslations /
   tblLyricLineAnnotations). Add a value HERE, never an ENUM ALTER. */

/** Translation row Kind — meaning vs romanization. */
const LINE_TRANSLATION_KINDS = ['translation', 'transliteration'];

/** Apple per-track translation type (Kind=translation only); NULL otherwise. */
const LINE_TRANSLATION_TYPES = ['subtitle', 'replacement'];

/** Annotation kind → icon/colour in the UI. */
const LINE_ANNOTATION_TYPES = [
    'explanation', 'reference', 'scripture', 'history', 'translation', 'trivia',
];

/** Annotation body format. */
const LINE_ANNOTATION_BODY_FORMATS = ['markdown', 'html', 'plain'];

/** Moderation status shared by both enrichment tables (mirrors tblLyrics). */
const LINE_ENRICHMENT_STATUSES = [
    'draft', 'pending_review', 'approved', 'rejected', 'archived',
];

/* -------------------------------------------------- Pure validators (test) -- */
/* No DB / I/O — unit-tested directly in tests/php/test-line-enrichment.php. */

/** Normalise a value against an allow-list. Returns the value (lower-cased,
 *  trimmed) if allowed, else null. */
function lineEnrichmentNormalizeVocab(string $value, array $allow): ?string
{
    $v = strtolower(trim($value));
    return in_array($v, $allow, true) ? $v : null;
}

/**
 * Validate a 0-based, end-exclusive code-point offset pair against the referent
 * line length(s). Returns [startOffset|null, endOffset|null] or throws.
 * NULL start = "from the beginning", NULL end = "to the end" (schema semantics).
 *
 * @param int|null $start
 * @param int|null $end
 * @param int      $startLineCpLen  code-point length of the start line
 * @param int      $endLineCpLen    code-point length of the end line (== start when single-line)
 * @param bool     $singleLine      true when the span is on one line (end offset must follow start)
 */
function lineEnrichmentValidateOffsets(
    ?int $start, ?int $end, int $startLineCpLen, int $endLineCpLen, bool $singleLine
): array {
    if ($start !== null) {
        if ($start < 0 || $start > $startLineCpLen) {
            throw new \InvalidArgumentException('startOffset out of range for the start line.');
        }
    }
    if ($end !== null) {
        if ($end < 0 || $end > $endLineCpLen) {
            throw new \InvalidArgumentException('endOffset out of range for the end line.');
        }
    }
    /* On a single line, an explicit start AND end must not invert. */
    if ($singleLine && $start !== null && $end !== null && $end < $start) {
        throw new \InvalidArgumentException('endOffset precedes startOffset on a single-line span.');
    }
    return [$start, $end];
}

/* ------------------------------------------------------- Schema readiness --- */

/** Both enrichment tables present? Memoised. Lets callers degrade gracefully on
 *  an un-migrated install (the #1088 migration may not have run). */
function lineEnrichmentTablesReady(\mysqli $db): bool
{
    static $ready = null;
    if ($ready !== null) { return $ready; }
    try {
        $r = $db->query(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME IN ('tblLyricLineTranslations', 'tblLyricLineAnnotations')"
        );
        $row   = $r ? $r->fetch_row() : null;
        $ready = ($row !== null && (int)$row[0] >= 2);
        if ($r) { $r->close(); }
    } catch (\Throwable $_e) {
        $ready = false;
    }
    return $ready;
}

/* --------------------------------------------------------- Ownership read --- */

/**
 * Resolve a tblLyricLines.Id to its owning lyrics version + text, ENFORCING that
 * the line belongs to a lyrics version of $songId. Returns
 * ['lyricsId'=>int, 'text'=>string, 'cpLen'=>int] or null when the line does not
 * exist or belongs to a different song (the caller maps null → 404/403).
 *
 * LyricsId comes from HERE (the line), never the request body.
 */
function lineEnrichmentResolveLine(\mysqli $db, int $lineId, string $songId): ?array
{
    $stmt = $db->prepare(
        "SELECT ll.LyricsId AS lyricsId, ll.LineText AS text
           FROM tblLyricLines ll
           JOIN tblLyrics ly ON ly.Id = ll.LyricsId
          WHERE ll.Id = ? AND ly.SongId = ?
          LIMIT 1"
    );
    $stmt->bind_param('is', $lineId, $songId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row === null) { return null; }
    $text = (string)$row['text'];
    return [
        'lyricsId' => (int)$row['lyricsId'],
        'text'     => $text,
        'cpLen'    => mb_strlen($text, 'UTF-8'),
    ];
}

/* =========================================================== Translations === */

/**
 * Create or update a per-line translation / transliteration. On create, $input
 * needs lineId; on update it needs id (LineId is immutable on update — move =
 * delete + create). Returns the persisted row (camelCase). Throws
 * \InvalidArgumentException (→400) on bad input, \RuntimeException (→404) when the
 * line / row is not owned by $songId.
 *
 * @param array<string,mixed> $input
 * @param int|null            $userId  SubmittedBy
 * @return array<string,mixed>
 */
function lineEnrichmentUpsertTranslation(\mysqli $db, string $songId, array $input, ?int $userId): array
{
    $id   = isset($input['id']) ? (int)$input['id'] : 0;
    $text = trim((string)($input['text'] ?? ''));
    if ($text === '') {
        throw new \InvalidArgumentException('text is required.');
    }

    $kind = lineEnrichmentNormalizeVocab((string)($input['kind'] ?? 'translation'), LINE_TRANSLATION_KINDS);
    if ($kind === null) {
        throw new \InvalidArgumentException('kind must be one of: ' . implode(', ', LINE_TRANSLATION_KINDS));
    }

    /* TargetLanguage — required, validated through the canonical BCP47 validator. */
    $lang = lineEnrichmentValidateLanguage((string)($input['targetLanguage'] ?? ''));
    if ($lang === null) {
        throw new \InvalidArgumentException('targetLanguage must be a valid BCP 47 tag.');
    }

    /* TranslationType — only meaningful for Kind=translation; else forced NULL. */
    $transType = null;
    if ($kind === 'translation' && isset($input['translationType']) && (string)$input['translationType'] !== '') {
        $transType = lineEnrichmentNormalizeVocab((string)$input['translationType'], LINE_TRANSLATION_TYPES);
        if ($transType === null) {
            throw new \InvalidArgumentException('translationType must be one of: ' . implode(', ', LINE_TRANSLATION_TYPES));
        }
    }

    $status = lineEnrichmentNormalizeVocab((string)($input['status'] ?? 'approved'), LINE_ENRICHMENT_STATUSES) ?? 'approved';
    $isPrimary = !empty($input['isPrimary']) ? 1 : 0;
    $sortOrder = isset($input['sortOrder']) ? max(0, (int)$input['sortOrder']) : 0;

    if ($id > 0) {
        /* UPDATE — fetch the row, enforce ownership via its line. */
        $existing = lineEnrichmentFetchTranslation($db, $id);
        if ($existing === null) {
            throw new \RuntimeException('Translation not found.');
        }
        $owner = lineEnrichmentResolveLine($db, (int)$existing['LineId'], $songId);
        if ($owner === null) {
            throw new \RuntimeException('Translation does not belong to this song.');
        }
        $lineId   = (int)$existing['LineId'];
        $lyricsId = $owner['lyricsId'];

        if ($isPrimary) {
            lineEnrichmentDemoteTranslationPrimary($db, $lineId, $lang, $kind, $id);
        }
        $u = $db->prepare(
            "UPDATE tblLyricLineTranslations
                SET Kind = ?, TargetLanguage = ?, TranslationType = ?, Text = ?,
                    SortOrder = ?, IsPrimary = ?, Status = ?
              WHERE Id = ?"
        );
        bindParamSafe('lineEnrichment translation UPDATE', $u,
            's'  // Kind
          . 's'  // TargetLanguage
          . 's'  // TranslationType (nullable)
          . 's'  // Text
          . 'i'  // SortOrder
          . 'i'  // IsPrimary
          . 's'  // Status
          . 'i', // Id
            $kind, $lang, $transType, $text, $sortOrder, $isPrimary, $status, $id
        );
        $u->execute();
        $u->close();
        return lineEnrichmentShapeTranslation(lineEnrichmentFetchTranslation($db, $id));
    }

    /* CREATE — line required + owned. */
    $lineId = isset($input['lineId']) ? (int)$input['lineId'] : 0;
    if ($lineId <= 0) {
        throw new \InvalidArgumentException('lineId is required to create a translation.');
    }
    $owner = lineEnrichmentResolveLine($db, $lineId, $songId);
    if ($owner === null) {
        throw new \RuntimeException('lineId does not belong to this song.');
    }
    $lyricsId = $owner['lyricsId'];

    if ($isPrimary) {
        lineEnrichmentDemoteTranslationPrimary($db, $lineId, $lang, $kind, 0);
    }
    $source = 'ihymns';
    $i = $db->prepare(
        "INSERT INTO tblLyricLineTranslations
            (LineId, LyricsId, Kind, TargetLanguage, TranslationType, Text,
             SortOrder, Source, IsPrimary, Status, SubmittedBy)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    bindParamSafe('lineEnrichment translation INSERT', $i,
        'i'  // LineId
      . 'i'  // LyricsId
      . 's'  // Kind
      . 's'  // TargetLanguage
      . 's'  // TranslationType (nullable)
      . 's'  // Text
      . 'i'  // SortOrder
      . 's'  // Source
      . 'i'  // IsPrimary
      . 's'  // Status
      . 'i', // SubmittedBy (nullable)
        $lineId, $lyricsId, $kind, $lang, $transType, $text,
        $sortOrder, $source, $isPrimary, $status, $userId
    );
    $i->execute();
    $newId = (int)$db->insert_id;
    $i->close();
    return lineEnrichmentShapeTranslation(lineEnrichmentFetchTranslation($db, $newId));
}

/** Delete a translation, enforcing it belongs to $songId. Returns true if a row
 *  was removed. */
function lineEnrichmentDeleteTranslation(\mysqli $db, string $songId, int $id): bool
{
    $existing = lineEnrichmentFetchTranslation($db, $id);
    if ($existing === null) { return false; }
    if (lineEnrichmentResolveLine($db, (int)$existing['LineId'], $songId) === null) {
        throw new \RuntimeException('Translation does not belong to this song.');
    }
    $d = $db->prepare("DELETE FROM tblLyricLineTranslations WHERE Id = ?");
    $d->bind_param('i', $id);
    $d->execute();
    $removed = $d->affected_rows > 0;
    $d->close();
    return $removed;
}

/** Demote any current primary for (LineId, TargetLanguage, Kind) except $exceptId
 *  (mirrors tblLyrics.IsPrimary — app-enforced, no DB constraint). */
function lineEnrichmentDemoteTranslationPrimary(\mysqli $db, int $lineId, string $lang, string $kind, int $exceptId): void
{
    $u = $db->prepare(
        "UPDATE tblLyricLineTranslations SET IsPrimary = 0
          WHERE LineId = ? AND TargetLanguage = ? AND Kind = ? AND Id <> ? AND IsPrimary = 1"
    );
    $u->bind_param('issi', $lineId, $lang, $kind, $exceptId);
    $u->execute();
    $u->close();
}

/** Raw translation row by Id (assoc) or null. */
function lineEnrichmentFetchTranslation(\mysqli $db, int $id): ?array
{
    $s = $db->prepare("SELECT * FROM tblLyricLineTranslations WHERE Id = ? LIMIT 1");
    $s->bind_param('i', $id);
    $s->execute();
    $row = $s->get_result()->fetch_assoc();
    $s->close();
    return $row ?: null;
}

/** Shape a translation row for clients (camelCase). */
function lineEnrichmentShapeTranslation(?array $r): array
{
    if ($r === null) { return []; }
    return [
        'id'              => (int)$r['Id'],
        'lineId'          => (int)$r['LineId'],
        'kind'            => (string)$r['Kind'],
        'targetLanguage'  => (string)$r['TargetLanguage'],
        'translationType' => $r['TranslationType'] !== null ? (string)$r['TranslationType'] : null,
        'text'            => (string)$r['Text'],
        'sortOrder'       => (int)$r['SortOrder'],
        'isPrimary'       => (bool)$r['IsPrimary'],
        'isAutoGenerated' => (bool)$r['IsAutoGenerated'],
        'status'          => (string)$r['Status'],
    ];
}

/* ============================================================ Annotations === */

/**
 * Create or update a per-line / per-span annotation. On create $input needs
 * startLineId; on update it needs id (span endpoints ARE editable on update).
 * Returns the persisted row. Same throw contract as the translation upsert.
 *
 * @param array<string,mixed> $input
 */
function lineEnrichmentUpsertAnnotation(\mysqli $db, string $songId, array $input, ?int $userId): array
{
    $id   = isset($input['id']) ? (int)$input['id'] : 0;
    $body = trim((string)($input['body'] ?? ''));
    if ($body === '') {
        throw new \InvalidArgumentException('body is required.');
    }

    $type = lineEnrichmentNormalizeVocab((string)($input['annotationType'] ?? 'explanation'), LINE_ANNOTATION_TYPES);
    if ($type === null) {
        throw new \InvalidArgumentException('annotationType must be one of: ' . implode(', ', LINE_ANNOTATION_TYPES));
    }
    $bodyFormat = lineEnrichmentNormalizeVocab((string)($input['bodyFormat'] ?? 'markdown'), LINE_ANNOTATION_BODY_FORMATS) ?? 'markdown';
    $status     = lineEnrichmentNormalizeVocab((string)($input['status'] ?? 'approved'), LINE_ENRICHMENT_STATUSES) ?? 'approved';
    $sortOrder  = isset($input['sortOrder']) ? max(0, (int)$input['sortOrder']) : 0;

    $lang = null;
    if (isset($input['languageCode']) && (string)$input['languageCode'] !== '') {
        $lang = lineEnrichmentValidateLanguage((string)$input['languageCode']);
        if ($lang === null) {
            throw new \InvalidArgumentException('languageCode must be a valid BCP 47 tag.');
        }
    }

    /* On UPDATE, ownership-check the existing row up front and default any omitted
       span endpoints from it. */
    $existing = null;
    if ($id > 0) {
        $existing = lineEnrichmentFetchAnnotation($db, $id);
        if ($existing === null) { throw new \RuntimeException('Annotation not found.'); }
        if (lineEnrichmentResolveLine($db, (int)$existing['StartLineId'], $songId) === null) {
            throw new \RuntimeException('Annotation does not belong to this song.');
        }
    }

    $startLineId = isset($input['startLineId']) ? (int)$input['startLineId'] : 0;
    if ($startLineId <= 0 && $existing !== null) {
        $startLineId = (int)$existing['StartLineId'];
    }
    if ($startLineId <= 0) {
        throw new \InvalidArgumentException('startLineId is required.');
    }

    /* endLineId: explicit > 0 wins; if the caller omits the key entirely on an
       update, keep the existing span; otherwise single-line. */
    $endLineId = null;
    if (isset($input['endLineId']) && (int)$input['endLineId'] > 0) {
        $endLineId = (int)$input['endLineId'];
    } elseif (!array_key_exists('endLineId', $input) && $existing !== null && $existing['EndLineId'] !== null) {
        $endLineId = (int)$existing['EndLineId'];
    }

    /* Resolve + ownership-check the span; LyricsId comes from the start line. */
    $startOwner = lineEnrichmentResolveLine($db, $startLineId, $songId);
    if ($startOwner === null) {
        throw new \RuntimeException('startLineId does not belong to this song.');
    }
    $lyricsId   = $startOwner['lyricsId'];
    $endCpLen   = $startOwner['cpLen'];
    $singleLine = true;
    if ($endLineId !== null && $endLineId !== $startLineId) {
        $endOwner = lineEnrichmentResolveLine($db, $endLineId, $songId);
        if ($endOwner === null) {
            throw new \RuntimeException('endLineId does not belong to this song.');
        }
        if ($endOwner['lyricsId'] !== $lyricsId) {
            throw new \InvalidArgumentException('Span endpoints must be in the same lyrics version.');
        }
        $endCpLen   = $endOwner['cpLen'];
        $singleLine = false;
    } else {
        $endLineId = null;   // a same-as-start endpoint normalises to NULL (schema)
    }

    $startOffset = (isset($input['startOffset']) && $input['startOffset'] !== '' && $input['startOffset'] !== null)
        ? (int)$input['startOffset'] : null;
    $endOffset   = (isset($input['endOffset']) && $input['endOffset'] !== '' && $input['endOffset'] !== null)
        ? (int)$input['endOffset'] : null;
    [$startOffset, $endOffset] = lineEnrichmentValidateOffsets(
        $startOffset, $endOffset, $startOwner['cpLen'], $endCpLen, $singleLine
    );

    if ($id > 0) {
        $u = $db->prepare(
            "UPDATE tblLyricLineAnnotations
                SET StartLineId = ?, EndLineId = ?, StartOffset = ?, EndOffset = ?,
                    LyricsId = ?, AnnotationType = ?, LanguageCode = ?, Body = ?,
                    BodyFormat = ?, SortOrder = ?, Status = ?
              WHERE Id = ?"
        );
        bindParamSafe('lineEnrichment annotation UPDATE', $u,
            'i'  // StartLineId
          . 'i'  // EndLineId (nullable)
          . 'i'  // StartOffset (nullable)
          . 'i'  // EndOffset (nullable)
          . 'i'  // LyricsId
          . 's'  // AnnotationType
          . 's'  // LanguageCode (nullable)
          . 's'  // Body
          . 's'  // BodyFormat
          . 'i'  // SortOrder
          . 's'  // Status
          . 'i', // Id
            $startLineId, $endLineId, $startOffset, $endOffset, $lyricsId,
            $type, $lang, $body, $bodyFormat, $sortOrder, $status, $id
        );
        $u->execute();
        $u->close();
        return lineEnrichmentShapeAnnotation(lineEnrichmentFetchAnnotation($db, $id));
    }

    $source = 'manual';
    $i = $db->prepare(
        "INSERT INTO tblLyricLineAnnotations
            (StartLineId, EndLineId, StartOffset, EndOffset, LyricsId,
             AnnotationType, LanguageCode, Body, BodyFormat, SortOrder, Source, Status, SubmittedBy)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    bindParamSafe('lineEnrichment annotation INSERT', $i,
        'i'  // StartLineId
      . 'i'  // EndLineId (nullable)
      . 'i'  // StartOffset (nullable)
      . 'i'  // EndOffset (nullable)
      . 'i'  // LyricsId
      . 's'  // AnnotationType
      . 's'  // LanguageCode (nullable)
      . 's'  // Body
      . 's'  // BodyFormat
      . 'i'  // SortOrder
      . 's'  // Source
      . 's'  // Status
      . 'i', // SubmittedBy (nullable)
        $startLineId, $endLineId, $startOffset, $endOffset, $lyricsId,
        $type, $lang, $body, $bodyFormat, $sortOrder, $source, $status, $userId
    );
    $i->execute();
    $newId = (int)$db->insert_id;
    $i->close();
    return lineEnrichmentShapeAnnotation(lineEnrichmentFetchAnnotation($db, $newId));
}

/** Delete an annotation, enforcing it belongs to $songId. */
function lineEnrichmentDeleteAnnotation(\mysqli $db, string $songId, int $id): bool
{
    $existing = lineEnrichmentFetchAnnotation($db, $id);
    if ($existing === null) { return false; }
    if (lineEnrichmentResolveLine($db, (int)$existing['StartLineId'], $songId) === null) {
        throw new \RuntimeException('Annotation does not belong to this song.');
    }
    $d = $db->prepare("DELETE FROM tblLyricLineAnnotations WHERE Id = ?");
    $d->bind_param('i', $id);
    $d->execute();
    $removed = $d->affected_rows > 0;
    $d->close();
    return $removed;
}

/** Raw annotation row by Id (assoc) or null. */
function lineEnrichmentFetchAnnotation(\mysqli $db, int $id): ?array
{
    $s = $db->prepare("SELECT * FROM tblLyricLineAnnotations WHERE Id = ? LIMIT 1");
    $s->bind_param('i', $id);
    $s->execute();
    $row = $s->get_result()->fetch_assoc();
    $s->close();
    return $row ?: null;
}

/** Shape an annotation row for clients (camelCase). */
function lineEnrichmentShapeAnnotation(?array $r): array
{
    if ($r === null) { return []; }
    return [
        'id'             => (int)$r['Id'],
        'startLineId'    => (int)$r['StartLineId'],
        'endLineId'      => $r['EndLineId'] !== null ? (int)$r['EndLineId'] : null,
        'startOffset'    => $r['StartOffset'] !== null ? (int)$r['StartOffset'] : null,
        'endOffset'      => $r['EndOffset'] !== null ? (int)$r['EndOffset'] : null,
        'annotationType' => (string)$r['AnnotationType'],
        'languageCode'   => $r['LanguageCode'] !== null ? (string)$r['LanguageCode'] : null,
        'body'           => (string)$r['Body'],
        'bodyFormat'     => (string)$r['BodyFormat'],
        'sortOrder'      => (int)$r['SortOrder'],
        'isVerified'     => (bool)$r['IsVerified'],
        'status'         => (string)$r['Status'],
    ];
}

/* ================================================================== Load === */

/**
 * All approved-or-editing enrichment for a song's primary lyrics version, for the
 * editor load. Returns ['translations'=>[…], 'annotations'=>[…]] (empty arrays on
 * an un-migrated install). Editors see ALL statuses (so drafts are editable); a
 * public read path filters Status='approved' itself (getSongDetailExtras).
 *
 * @return array{translations: list<array>, annotations: list<array>}
 */
function lineEnrichmentForSong(\mysqli $db, string $songId): array
{
    $out = ['translations' => [], 'annotations' => []];
    if (!lineEnrichmentTablesReady($db)) { return $out; }

    $t = $db->prepare(
        "SELECT tr.* FROM tblLyricLineTranslations tr
           JOIN tblLyrics ly ON ly.Id = tr.LyricsId
          WHERE ly.SongId = ? AND ly.Source = 'ihymns'
          ORDER BY tr.LineId, tr.SortOrder, tr.Id"
    );
    $t->bind_param('s', $songId);
    $t->execute();
    $tr = $t->get_result();
    while ($row = $tr->fetch_assoc()) { $out['translations'][] = lineEnrichmentShapeTranslation($row); }
    $t->close();

    $a = $db->prepare(
        "SELECT an.* FROM tblLyricLineAnnotations an
           JOIN tblLyrics ly ON ly.Id = an.LyricsId
          WHERE ly.SongId = ? AND ly.Source = 'ihymns'
          ORDER BY an.StartLineId, an.SortOrder, an.Id"
    );
    $a->bind_param('s', $songId);
    $a->execute();
    $ar = $a->get_result();
    while ($row = $ar->fetch_assoc()) { $out['annotations'][] = lineEnrichmentShapeAnnotation($row); }
    $a->close();

    return $out;
}

/* ---------------------------------------------------------- Language util --- */

/**
 * Validate a BCP 47 language tag via the canonical validator when available
 * (_ietfBcp47Validate, song_importers.php) so song / component / line all enforce
 * the SAME grammar; falls back to a conservative regex if it isn't loaded.
 * Returns the trimmed tag (capped at the 35-char column width) or null.
 */
function lineEnrichmentValidateLanguage(string $tag): ?string
{
    $tag = trim($tag);
    if ($tag === '') { return null; }
    if (function_exists('_ietfBcp47Validate')) {
        $valid = _ietfBcp47Validate($tag);   // trimmed tag | null (empty) | false (malformed)
        if ($valid === false || $valid === null) { return null; }
        return mb_substr((string)$valid, 0, 35);
    }
    /* Fallback grammar: language(2-3) + optional script(4) + region(2) + variants. */
    if (!preg_match('/^[A-Za-z]{2,3}([-_][A-Za-z0-9]{1,8})*$/', $tag)) { return null; }
    return mb_substr($tag, 0, 35);
}
