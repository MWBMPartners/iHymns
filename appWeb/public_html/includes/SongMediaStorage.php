<?php

declare(strict_types=1);

/**
 * iHymns — Song Media Storage Layer (#853)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Hybrid storage routing for song accompanying files. PDF / MIDI /
 * MusicXML go to a MEDIUMBLOB column on tblSongMedia (small files,
 * atomic backups, transactional gating). Audio kinds go to the
 * filesystem under appWeb/uploads/songs/ (off the public docroot,
 * preserves HTTP range requests for audio scrubbing).
 *
 * The DB-vs-FS choice is encoded in two places:
 *   - This class's FS_KINDS / DB_KINDS constants (write path)
 *   - The row's StorageBackend column (read path)
 * Both are explicit so a future migration can rebalance without
 * touching consumer code; the column wins on read so legacy rows
 * keep working.
 *
 * Future R2/S3 backend slots in here as a third branch keyed off
 * StorageBackend = 'object-store' (no schema change required).
 *
 * @see appWeb/.sql/migrate-song-media.php for schema
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

class SongMediaStorage
{
    /* Kind → backend routing. Audio is the only kind on disk; the rest
       fit comfortably in a MEDIUMBLOB (~16MB cap). */
    public const FS_KINDS = ['audio'];
    public const DB_KINDS = ['sheet-music', 'midi', 'musicxml'];

    /* Per-kind upload size caps. Audio is intentionally generous so
       FLAC / WAV uploads aren't kneecapped; the others are tight. */
    public const SIZE_CAPS = [
        'audio'       => 50 * 1024 * 1024,
        'sheet-music' => 10 * 1024 * 1024,
        'midi'        =>  1 * 1024 * 1024,
        'musicxml'    =>  1 * 1024 * 1024,
    ];

    /* Allowed MIME types per kind, plus the canonical filename
       extension to record (we re-derive the extension from the
       sniffed MIME — never trust the upload's filename suffix). */
    public const ALLOWED_MIMES = [
        'audio' => [
            'audio/mpeg'      => 'mp3',
            'audio/mp4'       => 'm4a',  /* covers AAC + ALAC in M4A wrapper */
            'audio/aac'       => 'aac',
            'audio/x-m4a'     => 'm4a',
            'audio/wav'       => 'wav',
            'audio/x-wav'     => 'wav',
            'audio/wave'      => 'wav',
            'audio/flac'      => 'flac',
            'audio/x-flac'    => 'flac',
            'audio/ogg'       => 'ogg',
            'audio/vorbis'    => 'ogg',
            'audio/aiff'      => 'aiff',
            'audio/x-aiff'    => 'aiff',
        ],
        'sheet-music' => [
            'application/pdf' => 'pdf',
        ],
        'midi' => [
            'audio/midi'      => 'mid',
            'audio/x-midi'    => 'mid',
            'audio/mid'       => 'mid',
        ],
        'musicxml' => [
            'application/vnd.recordare.musicxml+xml' => 'musicxml',
            'application/xml'                        => 'xml',
            'text/xml'                               => 'xml',
        ],
    ];

    /**
     * @return string[] All recognised media kinds.
     */
    public static function allKinds(): array
    {
        return array_merge(self::FS_KINDS, self::DB_KINDS);
    }

    /**
     * Per-kind storage backend.
     *
     * @throws \InvalidArgumentException if kind is unknown.
     */
    public static function backendForKind(string $kind): string
    {
        if (in_array($kind, self::FS_KINDS, true)) return 'filesystem';
        if (in_array($kind, self::DB_KINDS, true)) return 'database';
        throw new \InvalidArgumentException("Unknown song-media kind: {$kind}");
    }

    /**
     * Filesystem root for FS-backed media. Sibling of public_html/, off
     * the public docroot — kept inaccessible to direct HTTP so the
     * /song-media/<id> route stays the only way in.
     */
    public static function fsRoot(): string
    {
        /* __DIR__ = appWeb/public_html/includes
           dirname(__DIR__, 2) = appWeb */
        return dirname(__DIR__, 2)
             . DIRECTORY_SEPARATOR . 'uploads'
             . DIRECTORY_SEPARATOR . 'songs';
    }

    /**
     * Validate + sniff an uploaded file. Returns the kind-canonical
     * extension and detected MIME, or throws.
     *
     * Pipeline:
     *   1. Cap size against SIZE_CAPS[$kind]
     *   2. finfo MIME sniff on the actual bytes (NOT $_FILES['type'] —
     *      browsers + curl will happily lie about that)
     *   3. Cross-check against ALLOWED_MIMES[$kind]
     *
     * @return array{mime:string, extension:string} canonical metadata
     * @throws \RuntimeException on any failure (caller maps to HTTP 4xx)
     */
    public static function validateUpload(string $tmpPath, string $kind, int $sizeBytes): array
    {
        if (!isset(self::SIZE_CAPS[$kind])) {
            throw new \RuntimeException("Unknown media kind: {$kind}");
        }
        if ($sizeBytes <= 0) {
            throw new \RuntimeException("Empty upload.");
        }
        if ($sizeBytes > self::SIZE_CAPS[$kind]) {
            $capMb = (int) (self::SIZE_CAPS[$kind] / (1024 * 1024));
            throw new \RuntimeException(
                "File too large for {$kind} (max {$capMb} MB)."
            );
        }
        if (!is_readable($tmpPath)) {
            throw new \RuntimeException("Uploaded file unreadable.");
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            throw new \RuntimeException("Could not initialise mime sniff.");
        }
        $mime = finfo_file($finfo, $tmpPath) ?: '';
        finfo_close($finfo);

        $allowed = self::ALLOWED_MIMES[$kind] ?? [];
        if (!isset($allowed[$mime])) {
            throw new \RuntimeException(
                "Disallowed mime '{$mime}' for kind {$kind}."
            );
        }
        return ['mime' => $mime, 'extension' => $allowed[$mime]];
    }

    /**
     * Persist file bytes for an upload. For FS kinds: writes to
     * uploads/songs/<2>/<sha256>.<ext> with 0640 perms; returns the
     * relative path for tblSongMedia.StoragePath. For DB kinds:
     * returns the bytes for the caller to bind() into the INSERT.
     *
     * Returns a single shape regardless of backend so the caller's
     * INSERT prepares once: ['backend','path','content'].
     *
     * @return array{backend:string, path:?string, content:?string, sha256:string}
     * @throws \RuntimeException on any FS error.
     */
    public static function stage(string $bytes, string $kind, string $extension): array
    {
        $backend = self::backendForKind($kind);
        $sha256  = hash('sha256', $bytes);

        if ($backend === 'database') {
            return [
                'backend' => 'database',
                'path'    => null,
                'content' => $bytes,
                'sha256'  => $sha256,
            ];
        }

        /* filesystem */
        $rel = substr($sha256, 0, 2)
             . DIRECTORY_SEPARATOR . $sha256 . '.' . $extension;
        $abs = self::fsRoot() . DIRECTORY_SEPARATOR . $rel;
        $dir = dirname($abs);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException("Could not create media dir: {$dir}");
        }
        if (file_put_contents($abs, $bytes) === false) {
            throw new \RuntimeException("Could not write media file: {$abs}");
        }
        @chmod($abs, 0640);

        return [
            'backend' => 'filesystem',
            'path'    => $rel,
            'content' => null,
            'sha256'  => $sha256,
        ];
    }

    /**
     * Read full content for a tblSongMedia row.
     *
     * Returns null when the underlying storage is missing (FS file
     * unlinked, DB Content NULL on a 'database' row). Caller maps
     * null → 410 Gone.
     */
    public static function read(array $row): ?string
    {
        $backend = (string) ($row['StorageBackend'] ?? '');
        if ($backend === 'database') {
            return $row['Content'] ?? null;
        }
        if ($backend === 'filesystem') {
            $path = (string) ($row['StoragePath'] ?? '');
            if ($path === '') return null;
            $abs = self::fsRoot() . DIRECTORY_SEPARATOR . $path;
            if (!is_file($abs) || !is_readable($abs)) return null;
            $bytes = file_get_contents($abs);
            return $bytes === false ? null : $bytes;
        }
        return null;
    }

    /**
     * Write a byte range to $writeChunk. Used by the streaming
     * endpoint (phase E) to satisfy HTTP Range requests on audio.
     *
     * For DB-backed rows: substr() the in-memory blob (DB rows are
     * already <16MB so this is fine).
     *
     * For FS-backed rows: fopen/fseek/fread in 64KB chunks so a 50MB
     * audio doesn't briefly live in PHP's memory in full.
     *
     * @param array    $row          Row from tblSongMedia (StorageBackend, StoragePath, Content).
     * @param int      $start        Inclusive start offset.
     * @param int      $end          Inclusive end offset (must be >= $start).
     * @param callable $writeChunk   function(string $bytes): void
     * @return bool                  True on success, false on storage missing.
     */
    public static function streamRange(
        array $row,
        int $start,
        int $end,
        callable $writeChunk,
        int $chunkSize = 65536
    ): bool {
        if ($end < $start) return false;
        $backend = (string) ($row['StorageBackend'] ?? '');

        if ($backend === 'database') {
            $content = (string) ($row['Content'] ?? '');
            if ($content === '') return false;
            $writeChunk(substr($content, $start, $end - $start + 1));
            return true;
        }

        if ($backend === 'filesystem') {
            $path = (string) ($row['StoragePath'] ?? '');
            if ($path === '') return false;
            $abs = self::fsRoot() . DIRECTORY_SEPARATOR . $path;
            if (!is_file($abs)) return false;
            $f = @fopen($abs, 'rb');
            if (!$f) return false;
            try {
                if (fseek($f, $start) !== 0) return false;
                $remaining = $end - $start + 1;
                while ($remaining > 0 && !feof($f)) {
                    $take  = (int) min($chunkSize, $remaining);
                    $chunk = fread($f, $take);
                    if ($chunk === false || $chunk === '') break;
                    $writeChunk($chunk);
                    $remaining -= strlen($chunk);
                }
                return true;
            } finally {
                @fclose($f);
            }
        }
        return false;
    }

    /**
     * Delete the underlying storage for a row. Caller still has to
     * DELETE the row itself — this only removes the bytes (relevant
     * for FS only; DB content goes with the row).
     *
     * Best-effort: missing-file is treated as success (the row's
     * gone whether or not the file ever made it).
     */
    public static function deleteStorage(array $row): bool
    {
        $backend = (string) ($row['StorageBackend'] ?? '');
        if ($backend !== 'filesystem') return true;
        $path = (string) ($row['StoragePath'] ?? '');
        if ($path === '') return true;
        $abs = self::fsRoot() . DIRECTORY_SEPARATOR . $path;
        if (is_file($abs)) {
            @unlink($abs);
        }
        return true;
    }

    /**
     * Friendly-label map for a given kind. Used by the Song Editor
     * UI (phase C) to label dropdown options + by the public song
     * page (phase D) to label the "Sheet music (PDF)" / "MIDI" /
     * etc. download buttons.
     */
    public static function kindLabel(string $kind): string
    {
        return match ($kind) {
            'audio'       => 'Audio',
            'sheet-music' => 'Sheet music (PDF)',
            'midi'        => 'MIDI',
            'musicxml'    => 'MusicXML',
            default       => ucfirst($kind),
        };
    }
}
