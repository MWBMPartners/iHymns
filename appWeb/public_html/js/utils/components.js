/**
 * iHymns — Song Component Utility Functions
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * Shared helpers for song component types: short tag labels (V, C, PC, B, ...),
 * colour coding, and label formatting used across the setlist arrangement
 * editor and the manage/editor arrangement panel.
 *
 * SHORT TAGS follow industry-standard abbreviations (ProPresenter, OpenLP, etc.):
 *   V  = Verse          PC = Pre-Chorus      I  = Intro
 *   C  = Chorus         B  = Bridge          O  = Outro
 *   T  = Tag            CD = Coda            IL = Interlude
 *   VP = Vamp           AL = Ad-lib
 *
 * "Refrain" is recognised as an alias for "Chorus" (imported data may use
 * either term). The short tag "R" is accepted on import but resolves to
 * Chorus in the UI.
 *
 * Numbered variants append the number: V1, V2, C1, PC2, etc.
 */

/* ── Component type metadata ──────────────────────────────────────────── */

/**
 * Canonical map of component types to short abbreviation and display colour.
 * @type {Object<string, { short: string, color: string, label: string }>}
 */
export const COMPONENT_TYPES = {
    'verse':       { short: 'V',  color: '#3b82f6', label: 'Verse' },
    'chorus':      { short: 'C',  color: '#f59e0b', label: 'Chorus' },
    'refrain':     { short: 'R',  color: '#f59e0b', label: 'Chorus', aliasOf: 'chorus' },
    'pre-chorus':  { short: 'PC', color: '#ec4899', label: 'Pre-Chorus' },
    'bridge':      { short: 'B',  color: '#8b5cf6', label: 'Bridge' },
    'tag':         { short: 'T',  color: '#6b7280', label: 'Tag' },
    'coda':        { short: 'CD', color: '#6b7280', label: 'Coda' },
    'intro':       { short: 'I',  color: '#10b981', label: 'Intro' },
    'outro':       { short: 'O',  color: '#ef4444', label: 'Outro' },
    'interlude':   { short: 'IL', color: '#06b6d4', label: 'Interlude' },
    'vamp':        { short: 'VP', color: '#f97316', label: 'Vamp' },
    'ad-lib':      { short: 'AL', color: '#84cc16', label: 'Ad-lib' },
};

/**
 * Reverse map: short abbreviation → component type.
 * Built once from COMPONENT_TYPES for O(1) lookups during parsing.
 * @type {Object<string, string>}
 */
export const SHORT_TO_TYPE = Object.fromEntries(
    Object.entries(COMPONENT_TYPES).map(([type, meta]) => [meta.short, type])
);

/* ── Label helpers ────────────────────────────────────────────────────── */

/**
 * Resolve a component type entry, following aliasOf if present.
 * @param {string} type  Component type string
 * @returns {{ short: string, color: string, label: string }|null}
 */
function resolveType(type) {
    const meta = COMPONENT_TYPES[type];
    if (!meta) return null;
    return meta.aliasOf ? COMPONENT_TYPES[meta.aliasOf] || meta : meta;
}

/**
 * Build a short tag for a component, e.g. "V1", "C", "B2", "PC1".
 * Aliases resolve to their canonical type (e.g. refrain → "C").
 *
 * @param {Object} comp  Component object with `type` and optional `number`
 * @returns {string}     Short tag string
 */
export function shortTag(comp) {
    const meta = resolveType(comp.type) || { short: comp.type.charAt(0).toUpperCase() };
    return meta.short + (comp.number != null ? comp.number : '');
}

/**
 * Build a full human-readable label for a component, e.g. "Verse 1", "Chorus".
 * Aliases resolve to their canonical label (e.g. refrain → "Chorus").
 *
 * @param {Object} comp  Component object with `type` and optional `number`
 * @returns {string}     Full label
 */
export function fullLabel(comp) {
    const meta = resolveType(comp.type);
    const label = meta ? meta.label : comp.type.charAt(0).toUpperCase() + comp.type.slice(1);
    return comp.number != null ? `${label} ${comp.number}` : label;
}

/**
 * Get the display colour for a component type.
 *
 * @param {string} type  Component type string (e.g. 'verse', 'chorus')
 * @returns {string}     Hex colour code
 */
export function typeColor(type) {
    return (COMPONENT_TYPES[type] || {}).color || '#6b7280';
}

/**
 * Get the WCAG-contrast-safe text colour for a component type badge.
 * Uses relative luminance to determine dark (#1a1a1a) vs light (#ffffff).
 *
 * @param {string} type  Component type string
 * @returns {string}     '#1a1a1a' or '#ffffff'
 */
export function typeTextColor(type) {
    const hex = typeColor(type);
    const r = parseInt(hex.slice(1, 3), 16) / 255;
    const g = parseInt(hex.slice(3, 5), 16) / 255;
    const b = parseInt(hex.slice(5, 7), 16) / 255;
    const toLinear = c => c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
    const L = 0.2126 * toLinear(r) + 0.7152 * toLinear(g) + 0.0722 * toLinear(b);
    return L > 0.4 ? '#1a1a1a' : '#ffffff';
}

/**
 * Parse a short tag string (e.g. "V1", "PC2", "C") back to { type, number }.
 * Returns null if the tag cannot be recognised.
 *
 * @param {string} tag  Short tag string
 * @returns {{ type: string, number: number|null }|null}
 */
export function parseShortTag(tag) {
    const trimmed = (tag || '').trim().toUpperCase();
    if (!trimmed) return null;

    /* Try longest prefix first (2-char abbreviations like PC, CD, IL, VP, AL) */
    for (const len of [2, 1]) {
        const prefix = trimmed.slice(0, len);
        const type = SHORT_TO_TYPE[prefix];
        if (type) {
            const rest = trimmed.slice(len);
            const number = rest ? parseInt(rest, 10) : null;
            if (rest && isNaN(number)) continue; /* e.g. "VX" is invalid */
            return { type, number };
        }
    }

    return null;
}

/**
 * All valid component type strings, in display order (excludes aliases).
 * @type {string[]}
 */
export const ALL_TYPES = Object.keys(COMPONENT_TYPES).filter(
    t => !COMPONENT_TYPES[t].aliasOf
);
