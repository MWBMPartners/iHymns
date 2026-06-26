# Editor — Vendored Runtime

Third-party JavaScript libraries that the iHymns Song Editor loads at
runtime, vendored locally so the editor has zero external CDN
dependencies.

| File | Library | Version | License | Source |
| --- | --- | --- | --- | --- |
| `protobuf.min.js` | protobuf.js | 8.0.3 | BSD-3-Clause (see `protobuf.LICENSE`) | https://github.com/protobufjs/protobuf.js |

## Why vendor?

The editor sits in `private_html/` behind HTTP Basic Auth and is the
authoritative tool for building the iHymns catalogue. Vendoring keeps
it usable on shared hosts (Dreamhost et al) that may block outbound
CDN traffic, enforce strict CSPs, or be operated offline.

## Updating

If you bump `protobufjs` in `package.json` (and re-run
`npm run build:proto` if the protobuf wire format ever changes):

```bash
cp node_modules/protobufjs/dist/protobuf.min.js \
   appWeb/private_html/editor/vendor/protobuf.min.js
cp node_modules/protobufjs/LICENSE \
   appWeb/private_html/editor/vendor/protobuf.LICENSE

# Recompute the Subresource Integrity hash and update the
# integrity="sha384-…" attribute in index.php.
openssl dgst -sha384 -binary \
    appWeb/private_html/editor/vendor/protobuf.min.js \
  | openssl base64 -A
```

The browser will refuse to execute the script if the bytes don't match
the integrity hash — replace one without the other and the editor
fails closed rather than silently mis-encoding files.
