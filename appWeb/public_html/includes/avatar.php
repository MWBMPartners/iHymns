<?php

declare(strict_types=1);

/**
 * iHymns — Avatar URL resolver (#581)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * Builds a circular avatar URL for a signed-in user. Default service
 * is Gravatar (`d=identicon` so users without a Gravatar still get a
 * deterministic geometric pattern instead of a blank tile).
 *
 * APP_CONFIG['avatar'] keys (all optional):
 *   service  : 'gravatar' | 'libravatar' | 'dicebear' | 'none'
 *              (default: 'gravatar')
 *   default  : Gravatar `d=` value when the email has no avatar
 *              (default: 'identicon' — also used by Libravatar)
 *   rating   : Gravatar `r=` value (default: 'g' — General audiences)
 *
 * Per-user opt-out is filed as a follow-up (Settings → Profile toggle
 * + a `tblUsers.AvatarService` column). For now the project-level
 * setting is the single switch.
 */

/**
 * Compute the avatar URL for a user.
 *
 * @param string|null $email  The user's email address. Empty / null
 *                            returns the static fallback identicon.
 * @param int         $size   Pixel size requested from the resolver.
 * @return string             Absolute URL ready to drop into <img src>.
 */
function userAvatarUrl(?string $email, int $size = 64): string
{
    $cfg = (defined('APP_CONFIG') && is_array(APP_CONFIG) && isset(APP_CONFIG['avatar']))
         ? APP_CONFIG['avatar']
         : [];
    $service = strtolower((string)($cfg['service'] ?? 'gravatar'));
    $default = (string)($cfg['default'] ?? 'identicon');
    $rating  = (string)($cfg['rating']  ?? 'g');

    $email = trim((string)$email);

    /* No email or service disabled → static SVG identicon shipped with
       the app. Keeps the avatar surface working offline / behind a
       firewall that blocks Gravatar. */
    if ($email === '' || $service === 'none') {
        return '/assets/avatar-fallback.svg';
    }

    /* Gravatar accepts both MD5 (legacy) and SHA-256 since 2022. We
       use SHA-256 because the JS-side resolver in user-auth.js gets
       it free from SubtleCrypto and we want both surfaces to produce
       byte-identical URLs for the same signed-in user. */
    $hash = hash('sha256', strtolower($email));
    $size = max(16, min(512, $size));

    switch ($service) {
        case 'libravatar':
            /* Libravatar is gravatar-protocol-compatible; same hash,
               same query keys. Falls back to Gravatar for emails it
               doesn't have a record for. */
            return sprintf(
                'https://seccdn.libravatar.org/avatar/%s?s=%d&d=%s&r=%s',
                $hash, $size, urlencode($default), urlencode($rating)
            );

        case 'dicebear':
            /* DiceBear renders SVG identicons server-side — no email
               leak to a third party (the service sees the hash, not
               the email). Useful for the EU-default scenario the
               #581 issue mentions. */
            return sprintf(
                'https://api.dicebear.com/7.x/identicon/svg?seed=%s&size=%d',
                urlencode($hash), $size
            );

        case 'gravatar':
        default:
            return sprintf(
                'https://www.gravatar.com/avatar/%s?s=%d&d=%s&r=%s',
                $hash, $size, urlencode($default), urlencode($rating)
            );
    }
}
