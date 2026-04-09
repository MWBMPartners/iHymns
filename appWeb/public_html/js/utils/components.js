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
 *   R  = Refrain        T  = Tag             IL = Interlude
 *   VP = Vamp           CD = Coda            AL = Ad-lib
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
    'refrain':     { short: 'R',  color: '#f59e0b', label: 'Refrain' },
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
 * Build a short tag for a component, e.g. "V1", "C", "B2", "PC1".
 * If the component has a number, it is appended; otherwise just the abbreviation.
 *
 * @param {Object} comp  Component object with `type` and optional `number`
 * @returns {string}     Short tag string
 */
export function shortTag(comp) {
    const meta = COMPONENT_TYPES[comp.type] || { short: comp.type.charAt(0).toUpperCase() };
    return meta.short + (comp.number != null ? comp.number : '');
}

/**
 * Build a full human-readable label for a component, e.g. "Verse 1", "Chorus".
 *
 * @param {Object} comp  Component object with `type` and optional `number`
 * @returns {string}     Full label
 */
export function fullLabel(comp) {
    const meta = COMPONENT_TYPES[comp.type];
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
 * All valid component type strings, in display order.
 * @type {string[]}
 */
export const ALL_TYPES = Object.keys(COMPONENT_TYPES);
