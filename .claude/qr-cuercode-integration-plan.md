# QR via CueRCode — integration plan (owner directive, 2026-08-05)

**Owner directive:** "QR code functionality was to be provided via integration with our CueRCode
project (https://github.com/MWBMPartners/CueRCode) via its API. Continue to take that approach
throughout iHymns." → CueRCode is THE QR mechanism across iHymns; the client-side vendored
`qrcode-generator` approach (my #1767 R block + the pre-existing service-projection QR, rule #26) is
superseded.

Branch: `claude/print-templates-1767` (the R block being corrected lives here; #1767 unmerged).

## CueRCode API contract (from its OpenAPI `api/v1/openapi.json`, verified against source)

- `POST {base}/api/v1/generate` — body `{"type":"url","input":{"url":"…"},"customization":{"format":"svg|png|…","size":100-4000,"ecc":"L|M|Q|H","fg_color","bg_color",…}}`
- Auth: **`X-API-Key: cuercode_<40 hex>`** header — a SECRET (HMAC-SHA256 hashed in CueRCode's `tblAPIKeys`). **Must never reach the browser** → server-side proxy only.
- 200 → `{success:true, data:{uuid, image:"data:image/…;base64,…", download_url, mime_type, format}, …}`.
- Errors 400/401/422/429 → `{success:false, message, error_code}`. Rate-limited (`X-RateLimit-*`, `Retry-After`).
- `/preview` (no persistence, fixed 300px PNG), `/download/{uuid}`, `/types`, `/formats`, `/health` also exist. We use **`/generate`** (needs SVG + size control); `/generate` persists a row+file per call on CueRCode's side, so iHymns caches aggressively.

## Architecture — mirror `includes/intapps_client.php` (rule #22, the battle-tested outbound-service precedent)

1. **`includes/cuercode_client.php`** — server-side client, side-effect-free to require:
   - Settings-key constants (once, rule #35): `cuercode_base_url` (default `https://cuercode.net`), `cuercode_api_key` (secret), `cuercode_allow_loopback` (tests).
   - `cuercodeConfig(): ?array` — null (→ graceful/dormant) unless the API key is set.
   - `_cuercodeResolveUrl()` — SSRF-hardened (https-only + loopback carve-out), host-bound, copied shape from intapps `_intappsResolveUrl()`.
   - `cuercodeGenerate(string $payloadUrl, array $opts): ?array{bytes,mime,format}` — one cURL POST to `/generate` with `X-API-Key`; size-capped write-callback; no redirects; SSL verify; timeouts (2s/3s house band); decode `data.image` data-URI → raw bytes; returns null on ANY failure (never throws).
   - `secret_crypto.php::secretSettingKeys()` gains `'cuercode_api_key'` (transparent encrypt/decrypt via `getAppSetting`).
2. **`qr.php`** (standalone, mirrors `og-image.php`) — the same-origin image endpoint the clients hit:
   - `GET /qr.php?data=<url>&size=<100-1000>&format=<svg|png>&ecc=<L|M|Q|H>` → streams the QR **bytes** with `Content-Type` + `Cache-Control: public, max-age=31536000, immutable` (a QR for a fixed payload never changes).
   - `data` length-capped (~1024), `size` clamped, `format`/`ecc` allow-listed. Rate-limited (`enforceReadRateLimit`). No auth (public — the print/service QR is anon-facing) but abuse-bounded by the caps + rate limit.
   - Calls `cuercodeGenerate()`; on failure or no-key → **HTTP 503 + no body** so the client `<img>` fails and the always-present URL caption is the fallback (the "typed value always shows" principle). Never leaks the key or CueRCode errors.
   - Server-side cache (v1): filesystem if a writable cache dir exists, else HTTP-cache-only (follow-up: a shared `tblQrCache`).
3. **Clients emit a plain `<img>`** to `qr.php` — no client-side library, no async pre-pass:
   - `js/modules/print.js` R block: `renderBlock('qr')` → `<img src="{origin}/qr.php?data=…&size=…&format=svg">` + the URL caption (always shown). **Remove** `prepareQrForSong`, the qrcodegen loader, the go-handler `await`, the admin sample-QR boot, and `index.php`'s `qrCdn`/`qrLocal` config. CSP already allows `img-src 'self'`.
   - `manage/service-projection.php`: replace the qrcodegen dynamic-import `renderQr()` with an `<img>` to `qr.php?data=<joinUrl>` (+ its existing load/error fallback to the typed code).
4. **Config + cleanup:**
   - `manage/configuration.php` — CueRCode base URL + API-key fields (mirrors the intapps credential block).
   - Remove the now-unused vendored `qrcode-generator`: `APP_CONFIG['libraries']['qrcodegen']` (config.php), the `tools/download-vendor.sh` entry, and retire/replace `tests/test-qr.js` (it guards the vendored lib's SRI — obsolete once the lib is gone; rule #34: don't leave a guard for a deleted thing).
5. **Guard + docs:** a CI guard banning client-side QR-lib usage (no `qrcode.mjs` import / `createSvgTag` in app JS) + asserting the two surfaces emit a `qr.php` `<img>`; CHANGELOG; a new CLAUDE.md rule (QR via CueRCode) superseding the rule #26/#36 QR mentions; update #1767 + a new tracking issue for the "throughout iHymns" adoption; handoff.

## Blocker / owner note (non-blocking, deploy-time — mirrors intapps #1726)
The integration is **dormant until an iHymns→CueRCode API key is provisioned** and pasted on
`/manage/configuration` (generated in CueRCode's admin panel). Until then `cuercodeConfig()` returns
null, `qr.php` answers 503, and every QR surface degrades to the URL/code text — byte-safe, no errors.
Owner action: provision a `cuercode_` API key for iHymns and set the base URL (prod `cuercode.net`
vs a dev alpha). I cannot live-verify the round-trip here without the key; everything else is
verified (syntax, guard, graceful-degradation).

## Defensible defaults I've taken (trivially changeable)
- Base URL default `https://cuercode.net` (prod).
- Format: **SVG** for print (crisp scaling), **PNG** acceptable for projector; endpoint supports both.
- No offline fallback library: since every QR surface's data (song_data / join session) already needs
  network, dropping the offline client lib loses no real capability; the URL/code text is the fallback.
- v1 caching = HTTP `immutable` + optional filesystem; a shared `tblQrCache` is a noted follow-up.
