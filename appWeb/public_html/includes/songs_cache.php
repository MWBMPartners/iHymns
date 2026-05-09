<?php

declare(strict_types=1);

/**
 * iHymns — Songs corpus on-disk cache (#932)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * Both /api?action=songs_json (public PWA, native clients, search index)
 * and /manage/editor/api?action=load (admin editor) need the entire
 * ~12,370-song corpus as JSON. Building it on-demand from MySQL via
 * SongData::exportAsJson() costs ~140 MB of PHP-array memory (#929 OOM
 * required #931's 512 MB band-aid) and a multi-second SQL fan-out per
 * request.
 *
 * This module precomputes the corpus into a static file under
 * appWeb/data_share/song_data/, plus pre-compressed .gz (and .br when
 * the brotli extension is available) siblings, and serves it via PHP
 * readfile() — chunked, ~zero peak memory.
 *
 * Regenerate is fired:
 *   - From Song Editor save_song (per-save, best-effort).
 *   - From bulk-import flows (once at end-of-run, best-effort).
 *   - From an admin "Regenerate songs cache" button on /manage/dashboard.
 *
 * The cache lives OUTSIDE webroot for parity with the existing
 * appWeb/data_share/.htaccess Deny-from-all defence-in-depth — PHP is
 * the only entry point so the existing auth model is preserved.
 *
 * @requires PHP 8.1+ with ext-zlib for gzencode(). Brotli is optional;
 *           if brotli_compress() exists the .br variant is emitted too.
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

/* =========================================================================
 * PATH LAYOUT
 * ========================================================================= */

/**
 * Resolved paths for the cache file and its precompressed variants.
 *
 * @return array{raw:string, gzip:string, brotli:string}
 */
function songsCachePaths(): array
{
    /* dirname(__DIR__, 2) = appWeb (parent of public_html). The
       data_share/ tree lives outside webroot — see
       appWeb/data_share/.htaccess (Deny from all). */
    $base = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR
          . 'data_share' . DIRECTORY_SEPARATOR
          . 'song_data'  . DIRECTORY_SEPARATOR
          . 'songs.json';
    return [
        'raw'    => $base,
        'gzip'   => $base . '.gz',
        'brotli' => $base . '.br',
    ];
}

/* =========================================================================
 * REGENERATE
 * ========================================================================= */

/**
 * Rebuild the songs cache from MySQL and write the raw + precompressed
 * variants to disk. Throws on failure — callers that want best-effort
 * semantics should use songsCacheRegenerateBestEffort().
 *
 * @return array{bytes:int, gzip_bytes:?int, brotli_bytes:?int, mtime:int, took_ms:int}
 * @throws \RuntimeException When SongData rebuild or disk write fails.
 */
function songsCacheRegenerate(): array
{
    /* The single peak-allocation site for the corpus — #929 / #932.
       Scope the 512 MB bump here so the read-path endpoints
       (songsCacheServe) keep PHP's default 128 MB limit. */
    @ini_set('memory_limit', '512M');

    $started = microtime(true);

    require_once __DIR__ . DIRECTORY_SEPARATOR . 'SongData.php';

    $songData = new SongData();
    $payload  = $songData->exportAsJson();

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new \RuntimeException(
            'songsCacheRegenerate: json_encode failed — ' . json_last_error_msg()
        );
    }

    $paths = songsCachePaths();
    $dir   = dirname($paths['raw']);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new \RuntimeException(
            'songsCacheRegenerate: cannot create cache directory: ' . $dir
        );
    }

    $rawBytes    = _songsCacheAtomicWrite($paths['raw'], $json);
    $gzipBytes   = null;
    $brotliBytes = null;

    /* gzip — universal, level 9. Build cost is one-shot per save; runtime
       cost is zero because Apache serves the precompressed sibling. */
    $gz = gzencode($json, 9);
    if ($gz !== false) {
        $gzipBytes = _songsCacheAtomicWrite($paths['gzip'], $gz);
    }

    /* brotli — optional. Most shared hosts don't ship the kjdev/php-ext-brotli
       extension. When available, level 11 typically saves another ~30 % over
       gzip on JSON. When absent we silently skip — Accept-Encoding negotiation
       in songsCacheServe falls back to gzip. */
    if (function_exists('brotli_compress')) {
        $br = @brotli_compress($json, 11);
        if ($br !== false) {
            $brotliBytes = _songsCacheAtomicWrite($paths['brotli'], $br);
        }
    }

    /* Free the source string before we return — it's the largest live
       allocation in the request and the caller (save-hook) keeps running
       after this function exits. */
    unset($json, $payload);

    clearstatcache(true, $paths['raw']);

    return [
        'bytes'        => $rawBytes,
        'gzip_bytes'   => $gzipBytes,
        'brotli_bytes' => $brotliBytes,
        'mtime'        => (int)filemtime($paths['raw']),
        'took_ms'      => (int)round((microtime(true) - $started) * 1000),
    ];
}

/**
 * Best-effort wrapper. Used by save_song and bulk-import flows where
 * the user-facing operation has already succeeded — failing the regen
 * shouldn't undo the save. Logs to error_log + tblActivityLog.
 *
 * @param string $context Short label identifying the calling site
 *                        (e.g. 'editor.save_song', 'bulk_import_zip').
 *                        Surfaces in the activity log row.
 * @return array|null Regen stats on success, null on failure.
 */
function songsCacheRegenerateBestEffort(string $context): ?array
{
    try {
        return songsCacheRegenerate();
    } catch (\Throwable $e) {
        error_log(sprintf(
            '[songs_cache] regenerate failed (%s): %s @ %s:%d',
            $context,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        ));
        if (function_exists('logActivityError')) {
            logActivityError(
                'songs_cache.regenerate_failed',
                'songs_cache',
                '',
                $e,
                ['context' => $context]
            );
        }
        return null;
    }
}

/**
 * Write $contents to a sibling .tmp file and rename atomically over $target.
 * Prevents a half-written file from being served if PHP dies mid-write.
 *
 * @return int Bytes written.
 * @throws \RuntimeException
 */
function _songsCacheAtomicWrite(string $target, string $contents): int
{
    $tmp = $target . '.tmp.' . bin2hex(random_bytes(4));
    $n   = @file_put_contents($tmp, $contents);
    if ($n === false) {
        @unlink($tmp);
        throw new \RuntimeException('songsCache: failed to write ' . $tmp);
    }
    if (!@rename($tmp, $target)) {
        @unlink($tmp);
        throw new \RuntimeException('songsCache: failed to rename ' . $tmp . ' -> ' . $target);
    }
    @chmod($target, 0644);
    return $n;
}

/* =========================================================================
 * SERVE
 * ========================================================================= */

/**
 * Serve the cache file as the HTTP response with conditional-request
 * support and pre-compressed-variant negotiation. Does NOT do any auth
 * check — caller is responsible for gating access.
 *
 * Cache-miss fallback: builds the cache inline on first call (typical
 * on a fresh deploy where no save has happened yet).
 *
 * @return void
 */
function songsCacheServe(): void
{
    $paths = songsCachePaths();
    $raw   = $paths['raw'];

    if (!is_file($raw)) {
        /* First-call fallback. Build inline so the response is real
           rather than empty; subsequent requests skip this path. */
        try {
            songsCacheRegenerate();
        } catch (\Throwable $e) {
            http_response_code(500);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode([
                'error'  => 'Failed to build songs cache.',
                'detail' => $e->getMessage(),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            return;
        }
    }

    clearstatcache(true, $raw);
    $mtime = (int)filemtime($raw);
    $size  = (int)filesize($raw);
    $etag  = '"' . dechex($mtime) . '-' . dechex($size) . '"';

    /* Conditional-request short-circuit. Browser revalidation, SW
       background revalidation, and curl --etag-compare all land here. */
    $ifNoneMatch     = $_SERVER['HTTP_IF_NONE_MATCH']     ?? '';
    $ifModifiedSince = $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '';
    $cachedHit       = ($ifNoneMatch !== '' && $ifNoneMatch === $etag)
                    || ($ifModifiedSince !== '' && strtotime($ifModifiedSince) >= $mtime);
    if ($cachedHit) {
        http_response_code(304);
        header('ETag: ' . $etag);
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
        header('Cache-Control: public, max-age=300, must-revalidate');
        return;
    }

    /* Accept-Encoding negotiation. Parse properly — a naive str_contains
       would mis-fire on contrived ordering and on q-value annotations. */
    $accepted = _songsCacheAcceptedEncodings();
    $serve    = $raw;
    $encoding = null;
    if (in_array('br', $accepted, true) && is_file($paths['brotli'])) {
        $serve    = $paths['brotli'];
        $encoding = 'br';
    } elseif (in_array('gzip', $accepted, true) && is_file($paths['gzip'])) {
        $serve    = $paths['gzip'];
        $encoding = 'gzip';
    }

    header('Content-Type: application/json; charset=UTF-8');
    if ($encoding !== null) {
        header('Content-Encoding: ' . $encoding);
        header('Vary: Accept-Encoding');
    }
    header('Content-Length: ' . filesize($serve));
    header('ETag: ' . $etag);
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
    header('Cache-Control: public, max-age=300, must-revalidate');
    header('X-Songs-Cache: hit; built=' . gmdate('Y-m-d\TH:i:s\Z', $mtime)
        . ($encoding ? '; enc=' . $encoding : ''));

    /* Drop any open output buffers before streaming so PHP doesn't
       re-buffer the file into memory — defeating the readfile() win.
       Apache's mod_deflate would also try to gzip our pre-gzipped
       payload if we let it; the Content-Encoding header above tells
       it to leave us alone. */
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    readfile($serve);
}

/**
 * Lower-cased Accept-Encoding tokens, q=0 entries removed.
 *
 * @return list<string>
 */
function _songsCacheAcceptedEncodings(): array
{
    $raw = strtolower((string)($_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''));
    if ($raw === '') {
        return [];
    }
    $out = [];
    foreach (explode(',', $raw) as $tok) {
        $tok   = trim($tok);
        $parts = preg_split('/\s*;\s*/', $tok);
        $name  = $parts[0] ?? '';
        if ($name === '') {
            continue;
        }
        /* q=0 means "do not use" per RFC 9110. */
        $q = 1.0;
        for ($i = 1, $n = count($parts); $i < $n; $i++) {
            if (strncmp($parts[$i], 'q=', 2) === 0) {
                $q = (float)substr($parts[$i], 2);
            }
        }
        if ($q > 0) {
            $out[] = $name;
        }
    }
    return $out;
}
