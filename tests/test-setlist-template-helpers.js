/**
 * iHymns — set-list template / service-plan client helpers (#301, #1671 F4)
 * =========================================================================
 *
 * ELI5: the new "service plan" screen contains a handful of small
 * decision-making functions. This file IMPORTS them, CALLS them, and checks the
 * answers.
 *
 * WHY IT CALLS RATHER THAN READS
 * ------------------------------
 * Every property asserted here lives in a pure exported function, so it has a
 * runtime handle and this guard uses it. Reading the source for the right words
 * would assert vocabulary, and vocabulary survives any mutation that keeps the
 * words — the lesson `tests/php/test-transaction-fatal.php` was written to
 * record, and re-learned four times on this branch.
 *
 * THE ONE CROSS-FILE AGREEMENT WITH NO RUNTIME HANDLE is the SHAPE the client
 * mints and the shape the PHP sanitiser stores. Neither language can call the
 * other, so §5 re-reads the server's own charset literal out of
 * `includes/setlist_templates.php` on every run and tests minted ids against
 * it — in both directions, so the client can neither mint an id the server
 * would mangle nor quietly stop minting. If the extraction ever fails to find
 * the literal this FAILS LOUDLY rather than skipping: a check that quietly
 * stops checking is the wrong-but-green scanner this codebase keeps
 * rediscovering (rule #34).
 *
 * `setlist-templates.js` imports `js/utils/api-client.js`, `js/utils/html.js`
 * and `js/utils/announce.js`, none of which touches a browser global at module
 * scope, so a plain `import()` under Node works with no DOM stubs. Only the
 * DOM-wiring half reaches for `document`/`window`, and this file does not call
 * it — which is stated in §0 rather than left to be assumed.
 *
 * USAGE:
 *   node tests/test-setlist-template-helpers.js
 *
 * Exit status 0 = all pass, 1 = a failure.
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname  = path.dirname(__filename);
const ROOT       = path.resolve(__dirname, '..');
const MODULES    = path.join(ROOT, 'appWeb', 'public_html', 'js', 'modules');
const TPL_PHP    = path.join(ROOT, 'appWeb', 'public_html', 'includes', 'setlist_templates.php');

let failed = 0;
function ok(label, cond, detail = '') {
    console.log((cond ? '  ✅ ' : '  ❌ ') + label);
    if (!cond) {
        failed++;
        if (detail) console.log('       ' + detail);
    }
}

const tpl = await import(pathToFileURL(path.join(MODULES, 'setlist-templates.js')).href);

/* ---------------------------------------------------------------------------
 * 1. planFromTemplate() — the COPY that makes deleting a template harmless
 * ------------------------------------------------------------------------- */
console.log('\nplanFromTemplate()\n');

const template = {
    id: 12,
    name: 'Sunday Morning',
    slotsJson: [
        { id: 't1', label: 'Welcome',      type: 'welcome' },
        { id: 't2', label: 'Opening song', type: 'song' },
        { id: 't3', label: 'Reading',      type: 'reading' },
    ],
};

const plan = tpl.planFromTemplate(template);
ok('produces a plan with one slot per template row', plan?.slots.length === 3);
ok('records the template id as provenance', plan?.templateId === 12);
ok('SNAPSHOTS the template name (so it survives the template being deleted)',
    plan?.templateName === 'Sunday Morning');
ok('carries the labels through', plan.slots.map(s => s.label).join('|') === 'Welcome|Opening song|Reading');
ok('carries the types through', plan.slots.map(s => s.type).join('|') === 'welcome|song|reading');

/* NEW ids, not the template's. Two set lists built from one template would
   otherwise share slot ids — harmless today, a trap the moment anything keys
   across set lists. */
ok('mints NEW slot ids rather than reusing the template\'s',
    plan.slots.every(s => !['t1', 't2', 't3'].includes(s.id)));
ok('minted slot ids are distinct', new Set(plan.slots.map(s => s.id)).size === 3);

/* No songId is carried in: a template describes a SHAPE. */
ok('no slot arrives pre-filled with a song', plan.slots.every(s => !('songId' in s)));

/* Two applications of the same template must not collide. */
const planB = tpl.planFromTemplate(template);
ok('applying the same template twice yields disjoint slot ids',
    plan.slots.every(s => !planB.slots.some(b => b.id === s.id)));

ok('a template with no rows yields NO plan (null), not an empty header',
    tpl.planFromTemplate({ id: 1, name: 'x', slotsJson: [] }) === null);
ok('a malformed template yields null', tpl.planFromTemplate(null) === null);
ok('unlabelled template rows are dropped',
    tpl.planFromTemplate({ id: 1, name: 'x', slotsJson: [{ label: '  ' }, { label: 'Keep' }] })
        ?.slots.length === 1);
ok('a template with a blank name gets null provenance rather than an empty string',
    tpl.planFromTemplate({ id: 3, name: '   ', slotsJson: [{ label: 'A' }] })?.templateName === null);

/* ---------------------------------------------------------------------------
 * 2. assignSongToSlot() — immutability, and the clear that really clears
 * ------------------------------------------------------------------------- */
console.log('\nassignSongToSlot()\n');

const base = { templateId: null, templateName: null, slots: [
    { id: 'a', label: 'One', type: 'song' },
    { id: 'b', label: 'Two', type: 'song', songId: 'CP-0002' },
] };
const frozen = JSON.stringify(base);

const assigned = tpl.assignSongToSlot(base, 'a', 'MP-1008');
ok('assigns the song to the named slot', assigned.slots[0].songId === 'MP-1008');
ok('leaves other slots alone', assigned.slots[1].songId === 'CP-0002');
ok('does NOT mutate the plan it was given', JSON.stringify(base) === frozen);
ok('returns a NEW slots array', assigned.slots !== base.slots);

/* Clearing must REMOVE the key, matching what the PHP sanitiser stores. If the
   two disagreed, a plan would come back from a sync differing from the one just
   sent and a dirty-check would report unsaved changes forever. */
const cleared = tpl.assignSongToSlot(base, 'b', '');
ok('clearing REMOVES the songId key (never sets it to null)', !('songId' in cleared.slots[1]));
ok('clearing with whitespace also removes the key',
    !('songId' in tpl.assignSongToSlot(base, 'b', '   ').slots[1]));
ok('clearing with undefined also removes the key',
    !('songId' in tpl.assignSongToSlot(base, 'b', undefined).slots[1]));
ok('a trimmed song id is stored trimmed', tpl.assignSongToSlot(base, 'a', ' MP-1 ').slots[0].songId === 'MP-1');
ok('an unknown slot id changes nothing',
    JSON.stringify(tpl.assignSongToSlot(base, 'zzz', 'X').slots) === JSON.stringify(base.slots));
ok('a plan with no slots survives an assignment attempt',
    Array.isArray(tpl.assignSongToSlot({}, 'a', 'X').slots));

/* ---------------------------------------------------------------------------
 * 3. planProgress() — only SONG slots count
 * ------------------------------------------------------------------------- */
console.log('\nplanProgress()\n');

const mixed = { slots: [
    { id: '1', label: 'Welcome', type: 'welcome' },
    { id: '2', label: 'Song A',  type: 'song', songId: 'A' },
    { id: '3', label: 'Song B',  type: 'song' },
    { id: '4', label: 'Sermon',  type: 'sermon' },
] };
const prog = tpl.planProgress(mixed);
ok('counts only song slots in the total', prog.total === 2);
ok('counts filled song slots', prog.filled === 1);
ok('reports incomplete while a song slot is empty', prog.complete === false);

ok('a fully-assigned plan is complete',
    tpl.planProgress({ slots: [{ id: '1', type: 'song', songId: 'A' }] }).complete === true);
/* A plan with NO song slots is complete — there is nothing outstanding.
   Reporting 0/0 as incomplete would show a permanent warning on a plan with
   nothing wrong with it. */
ok('a plan with NO song slots is complete (0 of 0), not permanently warned',
    tpl.planProgress({ slots: [{ id: '1', type: 'prayer' }] }).complete === true);
ok('a slot with no type counts as a song slot (the default)',
    tpl.planProgress({ slots: [{ id: '1' }] }).total === 1);
ok('an empty-string songId does not count as filled',
    tpl.planProgress({ slots: [{ id: '1', type: 'song', songId: '' }] }).filled === 0);
ok('a malformed plan reports 0 of 0 rather than throwing', tpl.planProgress(null).total === 0);

/* ---------------------------------------------------------------------------
 * 4. templateSlotsFromSetlist() — a template is a SHAPE, not a song list
 * ------------------------------------------------------------------------- */
console.log('\ntemplateSlotsFromSetlist()\n');

const withPlan = {
    name: 'Last Sunday',
    songs: [{ id: 'A', title: 'Song A' }],
    plan: { slots: [
        { id: 'p1', label: 'Opening', type: 'song', songId: 'A' },
        { id: 'p2', label: 'Prayer',  type: 'prayer' },
    ] },
};
const fromPlan = tpl.templateSlotsFromSetlist(withPlan);
ok('an existing plan becomes the template shape', fromPlan.length === 2);
ok('the labels are kept', fromPlan.map(s => s.label).join('|') === 'Opening|Prayer');
ok('the types are kept', fromPlan.map(s => s.type).join('|') === 'song|prayer');
/* THE LOAD-BEARING ONE. Carrying one service's song choices into a template
   would apply last Sunday's songs to every future Sunday — which reads to the
   user as the app losing their edits. */
ok('song CHOICES are dropped (a template is a shape, not last Sunday\'s songs)',
    fromPlan.every(s => !('songId' in s)));
ok('nothing but label + type survives', fromPlan.every(s => Object.keys(s).sort().join(',') === 'label,type'));

const noPlan = tpl.templateSlotsFromSetlist({
    name: 'Ad hoc',
    songs: [{ id: 'A', title: 'Amazing Grace' }, { id: 'B', title: 'How Great' }],
});
ok('with no plan, one slot per song is derived', noPlan.length === 2);
ok('the derived labels are the song titles', noPlan[0].label === 'Amazing Grace');
ok('derived slots are all song slots', noPlan.every(s => s.type === 'song'));
ok('a song with no title still gets a usable label',
    tpl.templateSlotsFromSetlist({ songs: [{ id: 'A' }] })[0].label === 'Song 1');
ok('an empty set list yields no slots', tpl.templateSlotsFromSetlist({ songs: [] }).length === 0);
ok('a malformed set list yields no slots', tpl.templateSlotsFromSetlist(null).length === 0);
ok('a plan whose rows are all unlabelled falls back to nothing rather than empty rows',
    tpl.templateSlotsFromSetlist({ songs: [], plan: { slots: [{ label: '  ' }] } }).length === 0);

/* ---------------------------------------------------------------------------
 * 5. CROSS-FILE AGREEMENT — the minted id must survive the PHP sanitiser
 *
 * Neither language can call the other, so the server's own charset literal is
 * re-read here on every run rather than copied. Copying would be a second
 * thing to keep in step with nothing enforcing it (rule #35), and a comment
 * saying "keep these in sync" is the failure, not the fix.
 * ------------------------------------------------------------------------- */
console.log('\ncross-file — minted ids vs the PHP charset\n');

const phpSrc = fs.readFileSync(TPL_PHP, 'utf8');
const charsetMatch = phpSrc.match(/preg_replace\('\/\[\^([^\]]*)\]\/'/);
ok('the server\'s slot-id charset literal was FOUND in setlist_templates.php '
    + '(a check that quietly stops checking is worse than none)',
    charsetMatch !== null,
    'expected a preg_replace(\'/[^...]/\'...) in setlistTemplateSanitiseSlotId()');

if (charsetMatch) {
    /* PHP's `a-zA-Z0-9_\-` is a valid JS character class body too, so the
       server's own literal drives the JS test directly. */
    const allowed = new RegExp('^[' + charsetMatch[1] + ']+$');
    for (let i = 0; i < 200; i++) {
        const id = tpl.newSlotId();
        if (!allowed.test(id)) {
            ok('every minted slot id survives the server charset unchanged', false,
                `minted "${id}" contains a character the server would strip`);
            break;
        }
        if (i === 199) {
            ok('every minted slot id survives the server charset unchanged (200 samples)', true);
        }
    }
    /* The other direction: a charset that accepted EVERYTHING would make the
       assertion above vacuous, so prove it can reject. */
    ok('the extracted charset actually rejects something (the check is not vacuous)',
        !allowed.test('has space') && !allowed.test('semi;colon'));
    /* And the ids must be distinct enough to key DOM rows. */
    const minted = new Set(Array.from({ length: 500 }, () => tpl.newSlotId()));
    ok('minted ids are distinct across 500 samples', minted.size === 500,
        `got ${minted.size} distinct`);
}

/* Bound: the server truncates a slot id at 40 characters, so a longer minted id
   would be silently shortened and stop matching the client's DOM key. */
ok('minted slot ids are within the server\'s 40-character bound',
    Array.from({ length: 100 }, () => tpl.newSlotId()).every(id => id.length <= 40));

/* ---------------------------------------------------------------------------
 * 6. templateErrorForStatus() — branch on the NUMBER, never the sentence
 * ------------------------------------------------------------------------- */
console.log('\ntemplateErrorForStatus()\n');

ok('403 explains ownership', /only change templates you created/i.test(tpl.templateErrorForStatus(403)));
ok('404 explains absence', /no longer exists/i.test(tpl.templateErrorForStatus(404)));
ok('413 explains the cap AND says nothing changed',
    /too many rows/i.test(tpl.templateErrorForStatus(413))
    && /nothing was changed/i.test(tpl.templateErrorForStatus(413)));
ok('401 asks the user to sign in', /sign in/i.test(tpl.templateErrorForStatus(401)));
ok('429 mentions waiting', /wait/i.test(tpl.templateErrorForStatus(429)));
ok('500 is distinguished from a client error',
    tpl.templateErrorForStatus(500) !== tpl.templateErrorForStatus(400));
ok('an unknown status still produces a sentence', tpl.templateErrorForStatus(418).length > 0);
/* The server's own message wins when it sends one — but the BRANCH above is on
   the number, so the classification cannot rot when a string is reworded. */
ok('a server-supplied message is preferred over the canned one',
    tpl.templateErrorForStatus(403, 'Custom server text') === 'Custom server text');
ok('every status maps to a NON-EMPTY sentence',
    [400, 401, 403, 404, 409, 413, 422, 429, 500, 503].every(s => tpl.templateErrorForStatus(s).trim() !== ''));

/* ---------------------------------------------------------------------------
 * 7. renderPlanHtml() / renderTemplateMenu() — escaping + a11y
 *
 * These build strings, so they ARE callable without a DOM. Only the parts that
 * matter for safety and accessibility are asserted; the layout is not, because
 * pinning markup makes a guard fail on correct code (rule #34's other half).
 * ------------------------------------------------------------------------- */
console.log('\nrender helpers\n');

const hostile = { templateId: 1, templateName: '<img src=x onerror=alert(1)>', slots: [
    { id: 's1', label: '<script>bad()</script>', type: 'song' },
] };
const html = tpl.renderPlanHtml(hostile, [{ id: 'A"onmouseover="x', title: '<b>T</b>' }], { song: 'Song' });
ok('a hostile slot label is escaped', !html.includes('<script>bad()</script>'));
ok('a hostile template name is escaped', !html.includes('<img src=x'));
ok('a hostile song id is escaped in the option value', !html.includes('A"onmouseover="x'));
ok('a hostile song title is escaped', !html.includes('<b>T</b>'));
/* WCAG 3.3.2 / 4.1.2 — a bare <select> with no label is unusable with a screen
   reader, and this one is inside a repeated list where "combo box" alone says
   nothing about WHICH row it belongs to. */
ok('every song select carries a label naming its slot',
    (html.match(/<label[^>]*class="visually-hidden"/g) || []).length === 1);
ok('the select is associated with its label by id',
    /<label[^>]*for="plan-slot-s1"/.test(html) && /id="plan-slot-s1"/.test(html));
ok('a non-song slot renders NO select (there is nothing to choose)',
    !tpl.renderPlanHtml({ slots: [{ id: 'p', label: 'Prayer', type: 'prayer' }] }, [], {}).includes('<select'));
ok('an empty plan renders nothing at all', tpl.renderPlanHtml({ slots: [] }, [], {}) === '');
ok('a malformed plan renders nothing rather than throwing', tpl.renderPlanHtml(null, [], {}) === '');
ok('the progress readout appears', /1 of 1 songs chosen|0 of 1 songs chosen/.test(html));

const menu = tpl.renderTemplateMenu(null, [
    { id: 3, name: '<b>Evil</b>', slotsJson: [{ label: 'A' }] },
]);
ok('a hostile template name is escaped in the menu', !menu.includes('<b>Evil</b>'));
ok('the menu offers the manage entry', menu.includes('data-template-manage'));
ok('the menu offers an apply button per template', (menu.match(/data-template-apply=/g) || []).length === 1);
ok('an empty catalogue explains how to make one',
    /save as template/i.test(tpl.renderTemplateMenu(null, [])));
ok('the empty catalogue still offers the manage entry',
    tpl.renderTemplateMenu(null, []).includes('data-template-manage'));

console.log('');
if (failed > 0) {
    console.error(`${failed} assertion(s) failed.`);
    process.exit(1);
}
console.log('All set-list template helper assertions passed.');
