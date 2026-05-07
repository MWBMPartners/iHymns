<?php

declare(strict_types=1);

/**
 * iHymns — Card Layout Helper (#448)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Single place to compute the effective card order / hidden set for a
 * given viewer on a given surface ("dashboard" or "home"). The resolver
 * layers:
 *   1. baseline  — every known card ID on this surface, in the
 *                  template-author's preferred default order
 *   2. system    — admin override in tblAppSettings
 *   3. user      — per-user override in tblUsers.Settings JSON
 *
 * Missing IDs are appended (so shipping a new card never breaks anyone
 * who saved their layout yesterday); removed IDs drop (so a card pulled
 * from the codebase doesn't leave a ghost entry in saved layouts).
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'db_mysql.php';

const CARD_LAYOUT_SURFACES = ['dashboard', 'home'];

/**
 * Read the system-wide default layout for a surface.
 *
 * @return array{order: string[], hidden: string[]}
 */
function cardLayoutDefault(string $surface): array
{
    if (!in_array($surface, CARD_LAYOUT_SURFACES, true)) {
        return ['order' => [], 'hidden' => []];
    }

    $key = $surface === 'dashboard'
        ? 'dashboard_card_order_default'
        : 'home_card_order_default';

    try {
        $db = getDbMysqli();
        $stmt = $db->prepare('SELECT SettingValue FROM tblAppSettings WHERE SettingKey = ?');
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_row();
        $stmt->close();
        $raw = (string)($row[0] ?? '');
        if ($raw === '') return ['order' => [], 'hidden' => []];
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) return ['order' => [], 'hidden' => []];
        return [
            'order'  => cardLayoutSanitiseIds($decoded['order']  ?? []),
            'hidden' => cardLayoutSanitiseIds($decoded['hidden'] ?? []),
        ];
    } catch (\Throwable $_e) {
        return ['order' => [], 'hidden' => []];
    }
}

/**
 * Write the system-wide default layout. Caller must have confirmed the
 * `manage_default_card_layout` entitlement first.
 */
function cardLayoutSaveDefault(string $surface, array $layout): bool
{
    if (!in_array($surface, CARD_LAYOUT_SURFACES, true)) return false;

    $payload = json_encode([
        'order'  => cardLayoutSanitiseIds($layout['order']  ?? []),
        'hidden' => cardLayoutSanitiseIds($layout['hidden'] ?? []),
    ], JSON_UNESCAPED_SLASHES);

    $key = $surface === 'dashboard'
        ? 'dashboard_card_order_default'
        : 'home_card_order_default';

    try {
        $db = getDbMysqli();
        $stmt = $db->prepare(
            'INSERT INTO tblAppSettings (SettingKey, SettingValue) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE SettingValue = VALUES(SettingValue)'
        );
        $value = (string)$payload;
        $stmt->bind_param('ss', $key, $value);
        $stmt->execute();
        $stmt->close();
        return true;
    } catch (\Throwable $_e) {
        return false;
    }
}

/**
 * Read a user's layout override for a surface from tblUsers.Settings.
 *
 * @return array{order: string[], hidden: string[]}
 */
function cardLayoutUserOverride(int $userId, string $surface): array
{
    if ($userId <= 0 || !in_array($surface, CARD_LAYOUT_SURFACES, true)) {
        return ['order' => [], 'hidden' => []];
    }
    try {
        $db = getDbMysqli();
        $stmt = $db->prepare('SELECT Settings FROM tblUsers WHERE Id = ?');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_row();
        $stmt->close();
        $raw = (string)($row[0] ?? '');
        if ($raw === '') return ['order' => [], 'hidden' => []];
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) return ['order' => [], 'hidden' => []];
        $section = $decoded['cardLayouts'][$surface] ?? null;
        if (!is_array($section)) return ['order' => [], 'hidden' => []];
        return [
            'order'  => cardLayoutSanitiseIds($section['order']  ?? []),
            'hidden' => cardLayoutSanitiseIds($section['hidden'] ?? []),
        ];
    } catch (\Throwable $_e) {
        return ['order' => [], 'hidden' => []];
    }
}

/**
 * Write a user's layout override to tblUsers.Settings, merging with any
 * existing settings JSON (theme etc.) rather than replacing it.
 */
function cardLayoutSaveUserOverride(int $userId, string $surface, array $layout): bool
{
    if ($userId <= 0 || !in_array($surface, CARD_LAYOUT_SURFACES, true)) return false;

    try {
        $db = getDbMysqli();
        $stmt = $db->prepare('SELECT Settings FROM tblUsers WHERE Id = ?');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_row();
        $stmt->close();
        $raw = (string)($row[0] ?? '');
        $settings = $raw === '' ? [] : (json_decode($raw, true) ?: []);
        if (!isset($settings['cardLayouts']) || !is_array($settings['cardLayouts'])) {
            $settings['cardLayouts'] = [];
        }
        $settings['cardLayouts'][$surface] = [
            'order'  => cardLayoutSanitiseIds($layout['order']  ?? []),
            'hidden' => cardLayoutSanitiseIds($layout['hidden'] ?? []),
        ];
        $json = (string)json_encode($settings, JSON_UNESCAPED_SLASHES);
        $stmt = $db->prepare('UPDATE tblUsers SET Settings = ?, UpdatedAt = CURRENT_TIMESTAMP WHERE Id = ?');
        $stmt->bind_param('si', $json, $userId);
        $stmt->execute();
        $stmt->close();
        return true;
    } catch (\Throwable $_e) {
        return false;
    }
}

/**
 * Clear a user's override for a surface, reverting them to the system default.
 */
function cardLayoutClearUserOverride(int $userId, string $surface): bool
{
    if ($userId <= 0 || !in_array($surface, CARD_LAYOUT_SURFACES, true)) return false;
    try {
        $db = getDbMysqli();
        $stmt = $db->prepare('SELECT Settings FROM tblUsers WHERE Id = ?');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_row();
        $stmt->close();
        $raw = (string)($row[0] ?? '');
        if ($raw === '') return true;
        $settings = json_decode($raw, true) ?: [];
        if (isset($settings['cardLayouts'][$surface])) {
            unset($settings['cardLayouts'][$surface]);
        }
        $json = (string)json_encode($settings, JSON_UNESCAPED_SLASHES);
        $stmt = $db->prepare('UPDATE tblUsers SET Settings = ?, UpdatedAt = CURRENT_TIMESTAMP WHERE Id = ?');
        $stmt->bind_param('si', $json, $userId);
        $stmt->execute();
        $stmt->close();
        return true;
    } catch (\Throwable $_e) {
        return false;
    }
}

/**
 * Is the given user allowed to customise their own layout? Requires BOTH
 * the role-level entitlement AND (if they belong to a group) the group's
 * AllowCardReorder flag to be set. Groupless users get the role-level
 * answer alone.
 */
function cardLayoutUserCanCustomise(?array $user): bool
{
    if (!$user) return false;
    $role = $user['role'] ?? null;
    if (!userHasEntitlement('customise_own_card_layout', $role)) return false;

    $groupId = (int)($user['group_id'] ?? $user['GroupId'] ?? 0);
    if ($groupId <= 0) return true;

    try {
        $db = getDbMysqli();
        $stmt = $db->prepare('SELECT AllowCardReorder FROM tblUserGroups WHERE Id = ?');
        $stmt->bind_param('i', $groupId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_row();
        $stmt->close();
        /* Groups predating this column return null in $row[0]; rows
           that don't exist return null from fetch_row(). Both cases
           are treated as allowed (i.e. default on) so the feature
           rolls out non-destructively. */
        if ($row === null || $row[0] === null) return true;
        return (int)$row[0] === 1;
    } catch (\Throwable $_e) {
        return true;
    }
}

/**
 * Merge baseline card IDs with a saved order / hidden set.
 *
 * - IDs in the baseline but missing from `order` append at the end
 *   (new cards become visible for existing users).
 * - IDs in `order` but missing from the baseline drop (removed cards
 *   don't stick around as ghosts).
 * - `hidden` IDs that aren't in the baseline drop for the same reason.
 *
 * @param string[] $baseline          The template's canonical list of IDs
 * @param array{order: string[], hidden: string[]} $saved
 * @return array{order: string[], hidden: string[]}
 */
function cardLayoutMerge(array $baseline, array $saved): array
{
    $baselineSet = array_flip($baseline);
    $order = [];
    $seen  = [];

    foreach (($saved['order'] ?? []) as $id) {
        if (isset($baselineSet[$id]) && !isset($seen[$id])) {
            $order[] = $id;
            $seen[$id] = true;
        }
    }
    foreach ($baseline as $id) {
        if (!isset($seen[$id])) {
            $order[] = $id;
            $seen[$id] = true;
        }
    }
    $hidden = [];
    foreach (($saved['hidden'] ?? []) as $id) {
        if (isset($baselineSet[$id])) $hidden[] = $id;
    }
    return ['order' => $order, 'hidden' => $hidden];
}

/**
 * Resolve the effective layout for a viewer on a surface. Pure function —
 * just reads state, no side effects.
 *
 * @param string[] $baseline
 */
function cardLayoutResolve(array $baseline, string $surface, ?array $user): array
{
    $default = cardLayoutDefault($surface);
    $effective = cardLayoutMerge($baseline, $default);

    if ($user && cardLayoutUserCanCustomise($user)) {
        $override = cardLayoutUserOverride((int)($user['id'] ?? $user['Id'] ?? 0), $surface);
        if (!empty($override['order']) || !empty($override['hidden'])) {
            $effective = cardLayoutMerge($baseline, $override);
        }
    }
    return $effective;
}

/**
 * Reject anything that doesn't look like a card ID. IDs are short,
 * lowercase, alnum-plus-dash tokens (matches the template-author-chosen
 * data-card-id values throughout the PHP templates).
 *
 * @return string[]
 */
function cardLayoutSanitiseIds($value): array
{
    if (!is_array($value)) return [];
    $out = [];
    foreach ($value as $v) {
        if (!is_string($v)) continue;
        $v = trim($v);
        if ($v === '') continue;
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $v)) continue;
        $out[] = $v;
    }
    return array_values(array_unique($out));
}
