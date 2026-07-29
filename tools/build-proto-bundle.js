/**
 * iHymns — ProPresenter Proto Bundle Builder
 * ==========================================
 *
 * Compiles the vendored ProPresenter 7+ `.proto` schema files into a
 * single JSON descriptor that the browser editor loads at runtime.
 *
 * The schema is sourced verbatim from greyshirtguy/ProPresenter7-Proto
 * (Proto 7.16) and lives in
 * `appWeb/public_html/manage/editor/protos/proto-7.16/`. We use the full set
 * of `.proto` files (not just `presentation.proto`) because protobufjs
 * resolves imports lazily and we want all transitive dependencies
 * baked into the descriptor.
 *
 * Output:
 *   appWeb/public_html/manage/editor/protos/proto-bundle.json
 *
 * Usage:
 *   node tools/build-proto-bundle.js
 *   npm run build:proto
 *
 * Re-run this whenever the vendored `.proto` files change.
 *
 * Copyright © 2026 MWBM Partners Ltd. All rights reserved.
 * This software is proprietary.
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import protobuf from 'protobufjs';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const PROJECT_ROOT = path.resolve(__dirname, '..');
/* Paths follow the LIVE editor location (#1634).
 *
 * ELI5: these pointed at the old editor folder, which no longer holds any of
 * these files, so the script died on its first line of real work.
 *
 * Detail: appWeb/private_html/editor/ is the retired legacy editor — it now
 * contains only a 301 redirect stub and .gitkeep, and has no protos/
 * subdirectory at all. The vendored .proto set and the committed bundle both
 * live under public_html/manage/editor/protos/. Same class as #1631 item 2,
 * which fixed the identical stale path in tools/export-pro-sample.js; this
 * file was missed in that pass, which is why the two now deliberately use the
 * same shape — a reader comparing them should see no difference. */
const PROTO_DIR = path.join(
    PROJECT_ROOT, 'appWeb', 'public_html', 'manage', 'editor', 'protos', 'proto-7.16'
);
const OUTPUT_PATH = path.join(
    PROJECT_ROOT, 'appWeb', 'public_html', 'manage', 'editor', 'protos', 'proto-bundle.json'
);

/* Load presentation.proto first — it transitively imports the
   dependencies the exporter actually needs. We then explicitly load
   any further files the exporter touches so a future schema change
   that introduces a new top-level type still gets included. */
const ENTRY_POINTS = [
    'presentation.proto',
    /* Defensive belt-and-braces in case a future export touches these
       directly without going through `Presentation`. */
    'cue.proto',
    'action.proto',
    'slide.proto',
    'presentationSlide.proto',
    'graphicsData.proto',
    'groups.proto',
    'uuid.proto',
    'ccli.proto'
];

async function main() {
    if (!fs.existsSync(PROTO_DIR)) {
        console.error(`Proto directory not found: ${PROTO_DIR}`);
        process.exit(1);
    }

    const root = new protobuf.Root();

    /* Resolve imports against the local proto directory. protobufjs
       walks the imports graph automatically; we just point it at the
       right folder for everything that isn't a google/* well-known
       proto (which protobufjs ships internally). */
    root.resolvePath = (origin, target) => {
        if (target.startsWith('google/')) return null;

        /* If the target is an absolute path (the entry point we pass
           to root.load below), use it directly. */
        if (path.isAbsolute(target)) {
            return fs.existsSync(target) ? target : null;
        }

        /* Otherwise resolve relative to the proto directory. */
        const local = path.join(PROTO_DIR, target);
        if (fs.existsSync(local)) return local;

        /* And fall back to resolving against the importing file. */
        if (origin) {
            const sibling = path.join(path.dirname(origin), target);
            if (fs.existsSync(sibling)) return sibling;
        }
        return null;
    };

    for (const entry of ENTRY_POINTS) {
        const entryPath = path.join(PROTO_DIR, entry);
        if (!fs.existsSync(entryPath)) {
            console.warn(`Skipping missing entry: ${entry}`);
            continue;
        }
        await root.load(entryPath, { keepCase: true });
    }

    /* Ensure every type is fully resolved before serialising — this
       catches missing imports up-front rather than at export time in
       the browser. */
    root.resolveAll();

    const json = root.toJSON({ keepComments: false });

    /* Sanity-check: the type the exporter relies on must be present. */
    const probe = protobuf.Root.fromJSON(json);
    const Presentation = probe.lookupType('rv.data.Presentation');
    if (!Presentation) {
        console.error('rv.data.Presentation not present in the bundle.');
        process.exit(1);
    }

    /* Emit with keys sorted, so the output is DETERMINISTIC (#1634).
     *
     * ELI5: always write the entries in the same order, so that when the schema
     * really does change you can see what changed.
     *
     * Detail: protobufjs's toJSON() does not guarantee a stable key order, so
     * two runs over an unchanged .proto set produced a ~3,300-line diff that
     * was pure reordering — verified by parsing both and comparing: they were
     * semantically identical. That is worse than untidy. This bundle is
     * regenerated precisely when the schema HAS changed (a new ProPresenter
     * version, a new field), and that is exactly when the real delta needs to
     * be reviewable. Burying a two-line change in three thousand lines of
     * noise means nobody reviews it.
     *
     * Sorting is safe here: the descriptor is a set of maps, and every field's
     * wire identity is its explicit `id`, never its position. Key order carries
     * no meaning to protobuf.Root.fromJSON().
     *
     * https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/JSON/stringify#the_replacer_parameter */
    const sortKeysDeep = (value) => {
        if (Array.isArray(value)) { return value.map(sortKeysDeep); }
        if (value && typeof value === 'object') {
            return Object.keys(value).sort().reduce((acc, k) => {
                acc[k] = sortKeysDeep(value[k]);
                return acc;
            }, {});
        }
        return value;
    };

    fs.writeFileSync(OUTPUT_PATH, JSON.stringify(sortKeysDeep(json), null, 2) + '\n', 'utf8');
    const size = fs.statSync(OUTPUT_PATH).size;
    console.log(`Wrote ${path.relative(PROJECT_ROOT, OUTPUT_PATH)} (${size} bytes)`);
    console.log(`rv.data.Presentation has ${Object.keys(Presentation.fields).length} fields.`);
}

main().catch((err) => {
    console.error(err);
    process.exit(1);
});
