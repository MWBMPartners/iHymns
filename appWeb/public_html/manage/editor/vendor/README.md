# Editor — Vendored Runtime

Third-party JavaScript libraries that the iHymns Song Editor loads at
runtime, vendored locally so the editor has zero external CDN
dependencies.

| File | Library | Version | License | Source |
| --- | --- | --- | --- | --- |
| `protobuf.min.js` | protobuf.js | 8.0.3 | BSD-3-Clause (see `protobuf.LICENSE`) | https://github.com/protobufjs/protobuf.js |

## Why vendor?

The live editor is `/manage/editor/` (this directory), gated by
session auth + the editor+ role (`requireEditor()`) rather than HTTP
Basic Auth — the older `private_html/editor/` location is a retired
301-redirect stub (#589).
It is the authoritative tool for building the iHymns catalogue.
Vendoring keeps it usable on shared hosts (Dreamhost et al) that may
block outbound CDN traffic, enforce strict CSPs, or be operated
offline.

## Updating

If you bump `protobufjs` in `package.json` (and re-run
`npm run build:proto` if the protobuf wire format ever changes):

```bash
cp node_modules/protobufjs/dist/protobuf.min.js \
   appWeb/public_html/manage/editor/vendor/protobuf.min.js
cp node_modules/protobufjs/LICENSE \
   appWeb/public_html/manage/editor/vendor/protobuf.LICENSE
```

`index.php` currently loads this file with a plain
`<script src="vendor/protobuf.min.js">` — no `integrity=` attribute is
set today, so a byte-for-byte mismatch after an update is not caught by
the browser. If an `integrity` attribute is added to that tag in future
(matching the SRI convention `bootstrap_assets.php` uses for the CDN
loads on this same page, CLAUDE.md rule #36), recompute the hash on
every update and keep the two in lockstep:

```bash
openssl dgst -sha384 -binary \
    appWeb/public_html/manage/editor/vendor/protobuf.min.js \
  | openssl base64 -A
```
