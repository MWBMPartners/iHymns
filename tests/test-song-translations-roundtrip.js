/**
 * iHymns — song translation-link round-trip test (#1626)
 *
 * The bug this guards was SILENT DATA LOSS. A curator added a link in the
 * editor's Translations panel (#352), got a green "Translation link added."
 * toast, saved — and nothing was written. `tblSongTranslations` appeared
 * ZERO times in the whole-song save core; the only writers were the legacy
 * api.php add_translation / remove_translation endpoints, which have no JS
 * call sites at all. Almost certainly lost when the whole-corpus `save` was
 * tombstoned (#1016) and the per-record save_song became the only write path.
 *
 * That is the same silent-no-op class as #1565 (fragment scripts) and #1581
 * (event-name typos): the surrounding UI works, so the feature LOOKS alive and
 * only fails on reload. A round trip is only real if all four legs hold, so
 * this test pins all four against the SHIPPED source:
 *
 *   1. LOAD    — SongData emits `translations` on the single-song read.
 *   2. WIRE    — the editor's serialiser forwards the key to the save.
 *   3. SAVE    — editorSaveSongCore() actually writes tblSongTranslations,
 *                gated, fully bound, and as a DIFF rather than a wipe.
 *   4. HONESTY — the toast no longer claims a write that hasn't happened.
 *
 * Section 5 exercises the diff LOGIC behaviourally. There is no MySQL in CI,
 * so the resolver is re-declared here against the same contract (the
 * test-setlist-playback.js pattern); the structural checks above fail if the
 * real implementation's shape drifts away from it.
 *
 *   node tests/test-song-translations-roundtrip.js
 *
 * Exit status 0 = all pass, 1 = at least one failure.
 */

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const __dirname = dirname(fileURLToPath(import.meta.url));
const WEB = join(__dirname, '..', 'appWeb', 'public_html');
const SAVE_CORE = join(WEB, 'manage', 'editor', 'save_song_core.php');
const EDITOR_JS = join(WEB, 'manage', 'editor', 'editor.js');
const SONG_DATA = join(WEB, 'includes', 'SongData.php');
const SCHEMA_SQL = join(__dirname, '..', 'appWeb', '.sql', 'schema.sql');

const saveSrc = readFileSync(SAVE_CORE, 'utf8');
const editorSrc = readFileSync(EDITOR_JS, 'utf8');
const songDataSrc = readFileSync(SONG_DATA, 'utf8');
const schemaSrc = readFileSync(SCHEMA_SQL, 'utf8');

let passed = 0;
let failed = 0;
const failures = [];

function check(label, cond, detail = '') {
    if (cond) { passed++; console.log(`  PASS  ${label}`); }
    else { failed++; failures.push(`${label}${detail ? ` — ${detail}` : ''}`); console.log(`  FAIL  ${label}${detail ? ` — ${detail}` : ''}`); }
}

/* The save's translations block, sliced out so the SQL assertions below can't
   accidentally match some unrelated statement elsewhere in a 1400-line file. */
const blockStart = saveSrc.indexOf('Translation links (#352)');
const blockEnd = saveSrc.indexOf('$db->commit();', blockStart);
const transBlock = blockStart > -1 && blockEnd > blockStart
    ? saveSrc.slice(blockStart, blockEnd)
    : '';

/* ---------------------------------------------------------------------- */
/* 1. LOAD — the panel is populated from the single-song read              */
/* ---------------------------------------------------------------------- */

check('SongData exposes translation links on the single-song read path',
    /\$row\['translations'\]\s*=\s*\$translations/.test(songDataSrc));

check('the load reads the DIRECTIONAL source side (WHERE SourceSongId = ?)',
    /_getTranslations[\s\S]{0,600}?FROM tblSongTranslations[\s\S]{0,120}?WHERE SourceSongId = \?/.test(songDataSrc));

check('the loaded shape is {songId, language} — what the editor panel renders',
    /TranslatedSongId AS songId,\s*TargetLanguage AS language/.test(songDataSrc));

/* ---------------------------------------------------------------------- */
/* 2. WIRE — the key survives the trip back to the server                  */
/* ---------------------------------------------------------------------- */

check('the editor merges the full loaded record into the in-memory song',
    /Object\.assign\(song, full\)/.test(editorSrc));

check('serialiseSongForSave forwards every song key (translations included)',
    /Object\.keys\(song \|\| \{\}\)\.forEach\(function \(k\) \{ out\[k\] = song\[k\]; \}\)/.test(editorSrc));

/* ---------------------------------------------------------------------- */
/* 3. SAVE — the write that was missing, and the shape it must have        */
/* ---------------------------------------------------------------------- */

check('the whole-song save core references tblSongTranslations at all',
    /tblSongTranslations/.test(saveSrc),
    'this is the exact regression: grep -c returned 0 before #1626');

check('the translations block was located for inspection',
    transBlock.length > 0);

check('the write is gated on the table existing (un-migrated env degrades)',
    /_songTranslationsTableExists\(\$db\)/.test(transBlock)
    && /function _songTranslationsTableExists/.test(saveSrc));

check('the gate probes INFORMATION_SCHEMA rather than try/catching a query',
    /_songTranslationsTableExists[\s\S]{0,500}?INFORMATION_SCHEMA\.TABLES/.test(saveSrc));

check('an ABSENT translations key is a no-op (never deletes a stub-save\'s links)',
    /is_array\(\$song\['translations'\] \?\? null\)/.test(transBlock));

check('it DIFFS — no blanket "DELETE ... WHERE SourceSongId" wipe',
    !/DELETE FROM tblSongTranslations WHERE SourceSongId/.test(saveSrc),
    'a wipe+reinsert would reset the curator-owned Translator / Verified columns');

check('a re-pointed link UPDATEs in place, preserving Translator / Verified',
    /UPDATE tblSongTranslations SET TranslatedSongId = \? WHERE Id = \?/.test(transBlock));

check('removals DELETE by primary key',
    /DELETE FROM tblSongTranslations WHERE Id = \?/.test(transBlock));

check('new links INSERT the three columns the payload models',
    /INSERT INTO tblSongTranslations \(SourceSongId, TranslatedSongId, TargetLanguage\)/.test(transBlock));

/* Rule #5 — every VALUE bound; the only legal interpolation is a placeholder
   string built from a COUNT. */
check('every translation statement binds its values',
    (transBlock.match(/->prepare\(/g) || []).length
        === (transBlock.match(/->bind_param\(/g) || []).length,
    'prepare() and bind_param() counts must match');

check('IN-list placeholders are built from a count, never from user data',
    /array_fill\(0, count\(\$wantLangs\), '\?'\)/.test(transBlock)
    && /array_fill\(0, count\(\$wantIds\), '\?'\)/.test(transBlock));

check('no string concatenation of values into any translation SQL',
    !/(SELECT|INSERT|UPDATE|DELETE)[^;]{0,400}?tblSongTranslations[^;]{0,400}?['"]\s*\.\s*\$/.test(transBlock));

/* Both FK parents are pre-validated. tblSongs.Language is a free-text BCP-47
   tag ("pt-BR") while tblLanguages.Code holds IANA subtags, so a composite tag
   copied off the target song would violate fk_Trans_Lang and — inside the save
   transaction — could cost the curator their lyrics edit. */
check('schema really does constrain TargetLanguage to tblLanguages.Code',
    /fk_Trans_Lang[\s\S]{0,160}?REFERENCES tblLanguages\(Code\)/.test(schemaSrc));

check('the language tag is validated against tblLanguages before insert',
    /SELECT Code FROM tblLanguages WHERE Code IN \(\$lp\)/.test(transBlock));

check('the target song is validated against tblSongs before insert',
    /SELECT SongId FROM tblSongs WHERE SongId IN \(\$ip\)/.test(transBlock));

check('a self-link is rejected server-side, not just in the UI',
    /strcasecmp\(\$tId, \$songId\) === 0/.test(transBlock));

check('a translation failure cannot roll back the curator\'s song edit',
    /catch \(\\Throwable \$_e\)[\s\S]{0,900}?translation links failed/.test(transBlock));

check('skipped links are reported to the client, never dropped silently',
    /\$respBody\['translationWarnings'\]/.test(saveSrc)
    && /translationWarnings/.test(editorSrc));

check('translationWarnings is omitted on the happy path (wire shape unchanged)',
    /if \(\$translationWarnings !== \[\]\) \{/.test(saveSrc));

/* ---------------------------------------------------------------------- */
/* 4. HONESTY — staged vs saved                                            */
/* ---------------------------------------------------------------------- */

check('the add toast no longer claims a completed write',
    !/showToast\('Translation link added\.'/.test(editorSrc),
    'that wording is what let the missing persistence go unnoticed');

check('the add toast says the link is staged until Save',
    /Translation link staged\. Click Save to persist\./.test(editorSrc));

check('the remove toast is equally explicit about staging',
    /Translation link removed\. Click Save to persist\./.test(editorSrc));

check('the UI mirrors the (SourceSongId, TargetLanguage) UNIQUE key',
    /uq_Translation \(SourceSongId, TargetLanguage\)/.test(schemaSrc)
    && /sameLangIdx/.test(editorSrc),
    'without this a curator can stage two links the DB can only store one of');

/* ---------------------------------------------------------------------- */
/* 5. The diff resolver, behaviourally                                     */
/* ---------------------------------------------------------------------- */

/**
 * Mirrors the PHP diff in editorSaveSongCore(): keyed on TargetLanguage
 * (case-insensitively, matching the utf8mb4_unicode_ci UNIQUE key), returning
 * the writes it would issue. `payload === null` models an ABSENT key.
 */
function diffTranslations(payload, existingRows, sourceId, knownLangs, knownSongs) {
    if (!Array.isArray(payload)) return { skipped: true, deletes: [], updates: [], inserts: [], warnings: [] };

    const warnings = [];
    const desired = new Map();
    for (const t of payload) {
        if (!t) continue;
        const id = String(t.songId || '').trim();
        const lang = String(t.language || '').trim();
        if (!id || !lang) continue;
        if (id.toLowerCase() === sourceId.toLowerCase()) {
            warnings.push('self');
            continue;
        }
        desired.set(lang.toLowerCase(), { songId: id, language: lang }); /* last wins */
    }
    for (const [k, d] of [...desired]) {
        if (!knownLangs.includes(d.language.toLowerCase())) { warnings.push('lang'); desired.delete(k); continue; }
        if (!knownSongs.includes(d.songId.toLowerCase())) { warnings.push('song'); desired.delete(k); }
    }

    const existing = new Map(existingRows.map((r) => [r.language.toLowerCase(), r]));
    const deletes = [];
    const updates = [];
    const inserts = [];
    for (const [k, ex] of existing) if (!desired.has(k)) deletes.push(ex.id);
    for (const [k, d] of desired) {
        const ex = existing.get(k);
        if (!ex) { inserts.push(d); continue; }
        if (ex.songId.toLowerCase() !== d.songId.toLowerCase()) updates.push({ id: ex.id, songId: d.songId });
    }
    return { skipped: false, deletes, updates, inserts, warnings };
}

const LANGS = ['en', 'es', 'fr'];
const SONGS = ['mp-1008', 'sdah-123', 'hlc-9', 'mp-1'];
const run = (p, e, known = SONGS) => diffTranslations(p, e, 'MP-1008', LANGS, known);

/* The common path: a song that has never used the panel must issue NO writes. */
let r = run([], []);
check('empty payload + empty table ⇒ zero writes',
    !r.skipped && r.deletes.length === 0 && r.updates.length === 0 && r.inserts.length === 0);

/* The safety valve: a caller that doesn't manage the collection changes nothing. */
r = run(null, [{ id: 7, songId: 'SDAH-123', language: 'es' }]);
check('absent key leaves stored links untouched',
    r.skipped && r.deletes.length === 0);

/* The reported bug: a staged link is persisted. */
r = run([{ songId: 'SDAH-123', language: 'es' }], []);
check('a newly staged link becomes an INSERT',
    r.inserts.length === 1 && r.inserts[0].songId === 'SDAH-123' && r.deletes.length === 0);

/* An unrelated save must not churn rows. */
r = run([{ songId: 'SDAH-123', language: 'es' }], [{ id: 7, songId: 'SDAH-123', language: 'es' }]);
check('an unchanged link issues no write at all',
    r.inserts.length === 0 && r.updates.length === 0 && r.deletes.length === 0);

/* Removing the last link must actually remove it — the mirror-image of the bug. */
r = run([], [{ id: 7, songId: 'SDAH-123', language: 'es' }]);
check('clearing the panel DELETEs the stored link',
    r.deletes.length === 1 && r.deletes[0] === 7);

/* Re-pointing keeps the row (and its Translator / Verified) alive. */
r = run([{ songId: 'HLC-9', language: 'es' }], [{ id: 7, songId: 'SDAH-123', language: 'es' }]);
check('re-pointing a language UPDATEs the existing row instead of churning it',
    r.updates.length === 1 && r.updates[0].id === 7 && r.inserts.length === 0 && r.deletes.length === 0);

/* The UNIQUE key allows one row per language — the diff must not emit two. */
r = run([{ songId: 'SDAH-123', language: 'es' }, { songId: 'HLC-9', language: 'es' }], []);
check('two same-language links collapse to one (last wins, uq_Translation)',
    r.inserts.length === 1 && r.inserts[0].songId === 'HLC-9');

/* Different languages coexist happily. */
r = run([{ songId: 'SDAH-123', language: 'es' }, { songId: 'HLC-9', language: 'fr' }], []);
check('links in different languages both persist',
    r.inserts.length === 2);

/* Repeated saves must not accumulate duplicates. */
r = run([{ songId: 'SDAH-123', language: 'es' }], [{ id: 7, songId: 'sdah-123', language: 'ES' }]);
check('case differences do not create a duplicate row',
    r.inserts.length === 0 && r.updates.length === 0 && r.deletes.length === 0);

/* Guard rails. */
r = run([{ songId: 'MP-1008', language: 'en' }], []);
check('a self-link is dropped with a warning',
    r.inserts.length === 0 && r.warnings.includes('self'));

r = run([{ songId: 'SDAH-123', language: 'pt-BR' }], []);
check('a BCP-47 tag absent from tblLanguages is skipped, not thrown',
    r.inserts.length === 0 && r.warnings.includes('lang'));

r = run([{ songId: 'GONE-1', language: 'es' }], [], SONGS);
check('a vanished target song is skipped with a warning',
    r.inserts.length === 0 && r.warnings.includes('song'));

/* A bad entry must not take its valid siblings down with it. */
r = run([{ songId: 'SDAH-123', language: 'es' }, { songId: 'GONE-1', language: 'fr' }], []);
check('one bad link does not block the valid ones',
    r.inserts.length === 1 && r.inserts[0].songId === 'SDAH-123' && r.warnings.includes('song'));

/* ---------------------------------------------------------------------- */

console.log(`\n${passed} passed, ${failed} failed`);
if (failed > 0) {
    console.error('\nFailures:');
    for (const f of failures) console.error(`  - ${f}`);
    process.exit(1);
}
console.log('\nAll song translation round-trip assertions passed.');
