<?php

declare(strict_types=1);

/**
 * ============================================================================
 * smtp_presets.php — canonical SMTP provider presets (ONE source of truth)
 * ============================================================================
 *
 * #1309 — promotes the #1303 SMTP "presets" to first-class email providers.
 *
 * ELI5: this is the single list of "known email companies and their server
 * addresses" so the settings page and the actual mail sender never disagree.
 *
 * Detailed: previously the host/port/encryption for Microsoft 365 and Google
 * Workspace lived ONLY in a `$SMTP_PRESETS` array inside manage/configuration.php
 * and were surfaced as a secondary "Provider preset" dropdown nested under a
 * generic "SMTP" provider choice — so an admin had to pick SMTP, THEN pick the
 * preset, and the endpoints weren't known to the server-side sender at all.
 * This file lifts that map to a shared include consumed by BOTH:
 *   - manage/configuration.php — renders the provider <select> + the JSON
 *     island the pre-fill JS reads;
 *   - includes/EmailService.php — defaults host/port/secure server-side when a
 *     first-class `office365` / `gmail` provider is selected without a manual
 *     host (belt-and-braces if the UI pre-fill didn't run).
 *
 * IMPORTANT: every key except 'custom' DOUBLES as a first-class
 * `email_service` provider value, so these keys MUST stay in lockstep with
 * $EMAIL_SERVICE_OPTIONS in configuration.php and the dispatch switch in
 * EmailService::dispatch(). Add a provider here once; both sides pick it up.
 *
 * Growable vocabulary lives in PHP (not an ENUM) per CLAUDE.md rule #20.
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 * @package    iHymns
 * @subpackage Email
 */

if (!function_exists('ihymns_smtp_presets')) {
    /**
     * The canonical preset map.
     *
     * @return array<string, array{label:string, host:string, port:string, secure:string}>
     *   Keyed by provider/preset key. `host`/`port`/`secure` are '' for
     *   'custom' (manual entry); for a real provider they are the published
     *   submission endpoints. `secure` is one of the $SMTP_SECURE_OPTIONS
     *   keys ('tls' = STARTTLS:587, 'ssl' = implicit TLS:465, 'none').
     */
    function ihymns_smtp_presets(): array
    {
        return [
            'custom'    => ['label' => 'Custom / manual',                 'host' => '',                   'port' => '',    'secure' => ''],
            'office365' => ['label' => 'Microsoft 365 (Exchange Online)', 'host' => 'smtp.office365.com', 'port' => '587', 'secure' => 'tls'],
            'gmail'     => ['label' => 'Google Workspace / Gmail',        'host' => 'smtp.gmail.com',     'port' => '587', 'secure' => 'tls'],
        ];
    }
}
