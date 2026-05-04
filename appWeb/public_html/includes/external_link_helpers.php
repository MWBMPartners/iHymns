<?php

declare(strict_types=1);

/**
 * iHymns — External-link helpers (#845)
 *
 * Shared loader used by every admin edit-modal that ships the
 * `tblExternalLinkTypes` registry to its row builder via
 * `window._iHymnsLinkTypes`. Attaches each type's URL → provider
 * patterns from `tblExternalLinkPatterns` so the JS auto-detect
 * module reads its rules from the DB rather than the hard-coded
 * fallback list.
 *
 * Probe-gated on the patterns table existing — pre-migration
 * deployments get an empty `patterns` array per type and the JS
 * module silently falls back to its bundled RULES.
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

/**
 * Attach a `patterns` array to each link-type row in $types.
 *
 * @param \mysqli              $db
 * @param array<int,array>     $types  Rows already loaded from
 *                                     tblExternalLinkTypes — each
 *                                     row must carry an `id` key.
 * @return array<int,array>            Same array, mutated in place
 *                                     (returned for chaining).
 */
function attachExternalLinkPatterns(\mysqli $db, array $types): array
{
    if (empty($types)) return $types;

    /* Probe the patterns table — quietly no-op when missing. */
    try {
        $r = $db->query(
            "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME   = 'tblExternalLinkPatterns' LIMIT 1"
        );
        $hasTable = $r && $r->fetch_row() !== null;
        if ($r) $r->close();
    } catch (\Throwable $_e) {
        $hasTable = false;
    }
    if (!$hasTable) {
        foreach ($types as &$t) {
            if (!isset($t['patterns'])) $t['patterns'] = [];
        }
        return $types;
    }

    $idList = array_values(array_filter(array_map(
        static fn($t) => (int)($t['id'] ?? 0),
        $types
    )));
    if (empty($idList)) return $types;
    $ph = implode(',', array_fill(0, count($idList), '?'));

    try {
        $sql = "SELECT LinkTypeId, Host, PathPrefix, MatchSubdomains, Priority
                  FROM tblExternalLinkPatterns
                 WHERE LinkTypeId IN ($ph)
                   AND COALESCE(IsActive, 1) = 1
                 ORDER BY Priority ASC, Host ASC";
        $stmt = $db->prepare($sql);
        $stmt->bind_param(str_repeat('i', count($idList)), ...$idList);
        $stmt->execute();
        $res = $stmt->get_result();
        $byType = [];
        while ($row = $res->fetch_assoc()) {
            $tid = (int)$row['LinkTypeId'];
            $byType[$tid][] = [
                'host'            => (string)$row['Host'],
                'pathPrefix'      => $row['PathPrefix'] !== null ? (string)$row['PathPrefix'] : null,
                'matchSubdomains' => (int)$row['MatchSubdomains'] === 1,
                'priority'        => (int)$row['Priority'],
            ];
        }
        $stmt->close();
    } catch (\Throwable $e) {
        error_log('[attachExternalLinkPatterns] ' . $e->getMessage());
        $byType = [];
    }

    foreach ($types as &$t) {
        $tid = (int)($t['id'] ?? 0);
        $t['patterns'] = $byType[$tid] ?? [];
    }
    unset($t);
    return $types;
}
