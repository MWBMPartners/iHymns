<?php

declare(strict_types=1);

/**
 * iHymns — Partial date helper (credit-people birth/death dates)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * WHY:
 * Historical writers often have only a known YEAR (or month + year) of birth /
 * death. We store the date in a `DATE` column normalised to the FIRST of the period
 * plus a precision flag ('year' | 'month' | 'day') — see
 * migrate-add-creditpeople-date-precision.php. This is the ONE place that parses the
 * editor input and formats a stored (date, precision) back out, so the server, the
 * edit form, the public page and the JSON-LD all agree. (A JS mirror of partialDateParse
 * lives inline in manage/credit-people.php for live client validation.)
 *
 * ACCEPTED INPUT (partialDateParse):
 *   - ''                empty            → no date
 *   - YYYY              year only        → e.g. 1823          → 1823-01-01, 'year'
 *   - MM/YYYY           month + year     → e.g. 03/1823       → 1823-03-01, 'month'
 *   - DD/MM/YYYY        full (UK order)  → e.g. 15/03/1823    → 1823-03-15, 'day'
 *   - YYYY-MM           ISO month        → 1823-03           → 1823-03-01, 'month'
 *   - YYYY-MM-DD        ISO full (picker)→ 1823-03-15        → 1823-03-15, 'day'
 *
 * @link https://schema.org/Date  (JSON-LD accepts ISO-8601 partials: 1823, 1823-03)
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

const PARTIAL_DATE_MONTHS = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April', 5 => 'May', 6 => 'June',
    7 => 'July', 8 => 'August', 9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December',
];

/**
 * Parse a flexible date string into a normalised (date, precision) pair.
 *
 * @param string $input Raw user input (any accepted form above).
 * @return array{ok:bool, date:?string, precision:?string, error:?string}
 *         ok=true + null date/precision when EMPTY; ok=true + values when valid;
 *         ok=false + error message when malformed.
 */
function partialDateParse(string $input): array
{
    $s = trim($input);
    if ($s === '') {
        return ['ok' => true, 'date' => null, 'precision' => null, 'error' => null];
    }

    $ok   = static fn(string $date, string $prec): array => ['ok' => true, 'date' => $date, 'precision' => $prec, 'error' => null];
    $bad  = static fn(string $msg): array => ['ok' => false, 'date' => null, 'precision' => null, 'error' => $msg];
    $year = static fn(int $y): bool => $y >= 1 && $y <= 9999;

    /* ISO full date (the native picker submits this) — also UK day form. */
    if (preg_match('#^(\d{4})-(\d{1,2})-(\d{1,2})$#', $s, $m)) {
        [$y, $mo, $d] = [(int)$m[1], (int)$m[2], (int)$m[3]];
        if (!checkdate($mo, $d, $y) || !$year($y)) { return $bad('Not a valid date.'); }
        return $ok(sprintf('%04d-%02d-%02d', $y, $mo, $d), 'day');
    }
    if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $s, $m)) {        /* DD/MM/YYYY */
        [$d, $mo, $y] = [(int)$m[1], (int)$m[2], (int)$m[3]];
        if (!checkdate($mo, $d, $y) || !$year($y)) { return $bad('Not a valid date (use DD/MM/YYYY).'); }
        return $ok(sprintf('%04d-%02d-%02d', $y, $mo, $d), 'day');
    }
    /* Month + year: MM/YYYY or ISO YYYY-MM. */
    if (preg_match('#^(\d{1,2})/(\d{4})$#', $s, $m)) {                 /* MM/YYYY */
        [$mo, $y] = [(int)$m[1], (int)$m[2]];
        if ($mo < 1 || $mo > 12 || !$year($y)) { return $bad('Month must be 01–12 (use MM/YYYY).'); }
        return $ok(sprintf('%04d-%02d-01', $y, $mo), 'month');
    }
    if (preg_match('#^(\d{4})-(\d{1,2})$#', $s, $m)) {                 /* YYYY-MM */
        [$y, $mo] = [(int)$m[1], (int)$m[2]];
        if ($mo < 1 || $mo > 12 || !$year($y)) { return $bad('Month must be 01–12.'); }
        return $ok(sprintf('%04d-%02d-01', $y, $mo), 'month');
    }
    /* Year only. */
    if (preg_match('#^(\d{4})$#', $s, $m)) {
        $y = (int)$m[1];
        if (!$year($y)) { return $bad('Year out of range.'); }
        return $ok(sprintf('%04d-01-01', $y), 'year');
    }

    return $bad('Use YYYY, MM/YYYY, or DD/MM/YYYY.');
}

/** Split a normalised 'YYYY-MM-DD' into [y, m, d] ints (or null if unparseable). */
function _partialDateParts(?string $date): ?array
{
    if ($date === null || !preg_match('#^(\d{4})-(\d{2})-(\d{2})$#', substr($date, 0, 10), $m)) { return null; }
    return [(int)$m[1], (int)$m[2], (int)$m[3]];
}

/**
 * Format a stored (date, precision) back into the EDIT-input form
 * (YYYY / MM/YYYY / DD/MM/YYYY) so a partial date round-trips through the editor.
 */
function partialDateFormatInput(?string $date, ?string $precision): string
{
    $p = _partialDateParts($date);
    if ($p === null) { return ''; }
    [$y, $mo, $d] = $p;
    return match ($precision) {
        'year'  => sprintf('%04d', $y),
        'month' => sprintf('%02d/%04d', $mo, $y),
        default => sprintf('%02d/%02d/%04d', $d, $mo, $y),   /* 'day' or legacy NULL precision */
    };
}

/**
 * Human-readable display: "1823" / "March 1823" / "15 March 1823".
 * Legacy rows with a date but NULL precision are treated as full ('day').
 */
function partialDateFormatDisplay(?string $date, ?string $precision): string
{
    $p = _partialDateParts($date);
    if ($p === null) { return ''; }
    [$y, $mo, $d] = $p;
    $monthName = PARTIAL_DATE_MONTHS[$mo] ?? (string)$mo;
    return match ($precision) {
        'year'  => sprintf('%04d', $y),
        'month' => $monthName . ' ' . sprintf('%04d', $y),
        default => $d . ' ' . $monthName . ' ' . sprintf('%04d', $y),
    };
}

/**
 * ISO-8601 partial for structured data (JSON-LD birthDate/deathDate):
 * 'year' → "1823", 'month' → "1823-03", 'day' → "1823-03-15".
 */
function partialDateIso(?string $date, ?string $precision): string
{
    $p = _partialDateParts($date);
    if ($p === null) { return ''; }
    [$y, $mo, $d] = $p;
    return match ($precision) {
        'year'  => sprintf('%04d', $y),
        'month' => sprintf('%04d-%02d', $y, $mo),
        default => sprintf('%04d-%02d-%02d', $y, $mo, $d),
    };
}
