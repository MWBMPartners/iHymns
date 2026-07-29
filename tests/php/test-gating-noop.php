<?php

declare(strict_types=1);

/**
 * iHymns — Content-gating no-op + signing-dormancy test (#1357 / #1358)
 *
 * The #1 requirement of the content-gating completion is that EVERY change is a
 * BYTE-IDENTICAL NO-OP while content_gating_enabled='0' (the live state on the
 * three shared-DB docroots). This test pins that contract at CI time, with NO
 * database:
 *
 *   - With no .auth/db_credentials.php present, getDbMysqli() throws and
 *     getAppSetting() catches it → returns the '0' default → contentGatingEnabled()
 *     is false. So the real functions run their OFF path with zero stubbing.
 *
 * It asserts:
 *   (1) contentGatingApply($song, null)                   === $song   (3-arg off no-op)
 *   (2) contentGatingApply($song, null, 'PWA', 'tok')     === $song   (4-arg off no-op)
 *   (3) audioSigningEnabled()                             === false   (triple-dormant)
 *   (4) audioSignedUrlFor('CP-1')                         === null    (off → legacy URL)
 *   (5) audioSignatureValid(...) is false when the key is undefined  (fail-closed)
 *  (10) contentGatingMediaAllowed(kind, null[, token])  === true    (#1388 media gate
 *       off no-op, pinned per kind — it gates /song-media/<id> bytes + bulk_audio)
 *
 * Engaged-case (no DB): the strip DECISION is driven by checkTierAccess(), which
 * for the 'public' tier denies view_copyrighted / play_audio / download_pdf via
 * its fallback matrix (capsForTierFromRegistry returns null without a DB). We
 * assert that decision directly — the exact gate that flips $hasAudio/$hasSheet
 * and strips the lyric body when content_gating_enabled='1'.
 *
 *   php tests/php/test-gating-noop.php
 *
 * Exit status 0 = all pass, 1 = at least one failure.
 */

$failures = 0;
function check(string $label, bool $cond): void
{
    global $failures;
    echo ($cond ? "  PASS  " : "  FAIL  ") . $label . "\n";
    if (!$cond) { $failures++; }
}

$root = dirname(__DIR__, 2) . '/appWeb/public_html/includes';

/* content_gating.php / audio_signing.php direct-access guards key on
   SCRIPT_FILENAME (= this test under CLI, not the include), so they pass. The
   files require db_mysql/maintenance/ccli_validator but make NO DB connection at
   include time — the connection is lazy (getDbMysqli on first use). */
require_once $root . '/content_gating.php';
require_once $root . '/audio_signing.php';

/* Guard: this test is only meaningful with gating OFF. With no DB credentials
   the default is '0'; if a stray env flips it on, skip the no-op assertions
   rather than report false failures. */
$gatingOn = function_exists('contentGatingEnabled') && contentGatingEnabled();
if ($gatingOn) {
    echo "  SKIP  content_gating_enabled resolves to ON in this env — no-op assertions skipped.\n";
} else {
    /* A representative copyrighted song with audio + sheet + a lyric body. */
    $song = [
        'id'                 => 'CP-1',
        'title'              => 'A Copyrighted Hymn',
        'number'             => 1,
        'songbook'           => 'CP',
        'lyricsPublicDomain' => false,
        'hasAudio'           => true,
        'hasSheetMusic'      => true,
        'components'         => [['type' => 'verse', 'lines' => ['Line one', 'Line two']]],
        'translations'       => [['lang' => 'es', 'text' => 'uno']],
        'media'              => [
            ['kind' => 'audio',       'streamUrl' => '/song-media/10'],
            ['kind' => 'sheet-music', 'streamUrl' => '/song-media/11'],
            ['kind' => 'midi',        'streamUrl' => '/song-media/12'],
        ],
    ];

    /* (1) 3-arg off no-op — byte-identical (strict ===, same keys + order). */
    $out3 = contentGatingApply($song, null);
    check('(1) contentGatingApply(song, null) is byte-identical when off', $out3 === $song);

    /* (2) 4-arg off no-op — the new presence-token arg changes nothing off. */
    $out4 = contentGatingApply($song, null, 'PWA', 'sometoken');
    check('(2) contentGatingApply(song, null, PWA, token) is byte-identical when off', $out4 === $song);

    /* (3) Signing is triple-dormant: gating off ⇒ audioSigningEnabled() false. */
    check('(3) audioSigningEnabled() === false when off', audioSigningEnabled() === false);

    /* (4) Off ⇒ no signed URL (caller falls back to the legacy literal). */
    check('(4) audioSignedUrlFor(CP-1) === null when off', audioSignedUrlFor('CP-1') === null);

    /* (10) #1388 — the media-bytes gate obeys the SAME master switch.
       contentGatingMediaAllowed() gates /song-media/<id> and the bulk_audio
       manifest. Off, it must allow EVERY kind for EVERY caller — including an
       anonymous one with no presence token, which is the strictest possible
       input and therefore the one most likely to regress to a deny. A false
       here would 403 media on all three docroots the moment this ships, so it
       is pinned per kind rather than as a single spot-check. */
    foreach (['audio', 'midi', 'sheet-music', 'musicxml'] as $kind) {
        check("(10) contentGatingMediaAllowed('{$kind}', null) === true when off",
            contentGatingMediaAllowed($kind, null) === true);
    }
    check('(10) contentGatingMediaAllowed with a presence token === true when off',
        contentGatingMediaAllowed('audio', null, 'sometoken') === true);
    check('(10) contentGatingMediaAllowed for an unknown kind === true when off',
        contentGatingMediaAllowed('something-new', null) === true);
}

/* (5) Verify is fail-closed when the key is undefined (independent of the flag). */
if (!defined('AUDIO_SIGNING_KEY')) {
    check('(5) audioSignatureValid() === false when AUDIO_SIGNING_KEY undefined',
        audioSignatureValid('CP-1', time() + 3600, 'deadbeef') === false);
} else {
    echo "  SKIP  AUDIO_SIGNING_KEY is defined in this env — fail-closed-on-undefined assertion skipped.\n";
}

/* ----------------------------------------------------------------------- *
 * Engaged-case DECISION (no DB). checkTierAccess for the 'public' tier uses
 * its fallback matrix (the registry read returns null without a DB), which is
 * exactly the un-migrated path. The 'public' tier denies the three caps that
 * strip the lyric body / flip $hasAudio / flip $hasSheet when gating is ON.
 * ----------------------------------------------------------------------- */
check('(6) public tier is DENIED view_copyrighted (drives lyric-body strip)',
    empty(checkTierAccess('public', 'view_copyrighted', false)['allowed']));
check('(7) public tier is DENIED play_audio (flips $hasAudio false)',
    empty(checkTierAccess('public', 'play_audio', false)['allowed']));
check('(8) public tier is DENIED download_pdf (flips $hasSheet false)',
    empty(checkTierAccess('public', 'download_pdf', false)['allowed']));
/* A 'free' tier viewer CAN see copyrighted lyrics — sanity that the matrix
   isn't denying everything (would mask a real strip bug). */
check('(9) free tier is ALLOWED view_copyrighted (sanity)',
    !empty(checkTierAccess('free', 'view_copyrighted', false)['allowed']));

if ($failures === 0) {
    echo "\nAll gating no-op + signing-dormancy assertions passed.\n";
    exit(0);
}
fwrite(STDERR, "\n{$failures} assertion(s) failed.\n");
exit(1);
