# Handoff — #1767 print-template enhancements (2026-08-05)

**Branch:** `claude/print-templates-1767` (off `alpha`, 7 commits, all pushed). Independent PR (one-PR-per-work).
**Model:** claude-opus-4-8. **Effort:** ultracode (xhigh). **Governing directive:** work the queue autonomously; surface only owner-only decisions, batched + non-blocking.

## What shipped (all no-schema; BlocksJson/PageOptionsJson already exist)

The song-print system (`js/modules/print.js` renderer + `manage/print-templates.php` editor + `tblPrintTemplates`) gained, in verified slices:

- **Mechanism first (commit 1, `2ef711f3`):** `tests/php/test-print-block-registry.php` — a rule-#35 guard deriving the block-type set + per-type option keys from `PRINT_BLOCK_TYPES` + `renderBlock()` + `$BLOCK_SCHEMA`, later extended to `PRINT_PAGE_OPTIONS`↔`$PAGE_OPTION_SCHEMA` + `PRINT_SHOWIF_CONDITIONS`↔`$SHOWIF_CONDITIONS`. Mutation-proven in every drift direction (its own parser was caught red-not-green mis-reading a nested `choices` key — rule #34).
- **N/O/P blocks (`853965e8`):** Scripture (`?include=scriptureRefs`), Tune+metre (`?include=tune`), Themes (`song.tags`).
- **R QR block (`8f606c07`):** QR of the permalink; async `prepareQrForSong()` pre-pass (CDN→vendored `qrcode-generator` fallback, rule #36) keeps the sync renderer + byte-identical preview intact; degrades to URL text on library failure. `index.php` now emits `qrCdn`/`qrLocal` on `app.config`.
- **A/B block options (`08ce00bf`):** lyrics `align`+`size`; subtitle `bookAbbr`.
- **G/V/AB/AM/F page options (`00b5ee15`):** page size, high-contrast, ink-saver, accent colour, line spacing — via a new `PRINT_PAGE_OPTIONS` registry (mirrors the block pattern; editor page-panel is now data-driven, the hardcoded fontPt input is gone).
- **Y conditional visibility (`5f023cdb`):** universal `showIf` (hasChords/hasCcli/…); evaluated in `renderBlock()` before the switch, fail-open; per-block select in the editor.
- **Docs (`d0d43884`):** CHANGELOG + `.claude/print-templates-1767-plan.md` (reconciled plan + shipped/remaining status).

Every feature behaviourally verified with `node` against the shared `renderTemplateBodyHtml()` / `buildPrintDoc()` (empty-data grace, bad-value fallbacks, QR failure path). `php -l` + guard green throughout.

## Remaining (tracked on #1767 comment + plan doc)

- **Buildable-now (this branch, next pass):** Z (clone + JSON import/export), J (system default `IsDefault`) — deferred for **live-DB behavioural verification** of their DB-backed admin POST actions + public-API default-selection (GIRFT). H-family multi-page/page-numbering (U/T/AA/AG) — high-risk `@page` support.
- **⚠️ C (MRL-gated chords) NOT enforced:** the pre-existing `showChords` lyrics option does not yet honour the MRL licensing gate the issue requires (omit chords when no org MRL). It's content-licensing infra (mirrors CCLI gate, tied to #1769/#1768) — sits in the decision-gated batch. Flagged on the issue so it isn't mistaken for done.
- **Decision-gated schema/server-PDF half (D/E/K/L/AK/AL/W/X/AC/AD/AJ + Q/S/AH):** rule #20 — not frozen until 4 batched owner decisions land (posted to #1767, none blocking): (1) server-PDF y/n [rec: browser-only], (2) AK count copies-vs-docs [rec: prompt copies], (3) branding/white-label → fold into #1769 gating, (4) uploadable-layout shape [rec: constrained slots].

## Next steps
1. If owner answers the 4 decisions → build the schema/server-PDF batch per #1767 (one-pass, forward-looking).
2. Else continue the queue: Z/J (with live-DB verify), then #1770 Live Follow UX; #91 FINAL docs is LAST.
3. Open the #1767 PR (base `alpha`) when the no-schema slice is deemed a complete unit — or continue adding Z/J to this branch first.

## Prior-session context (other branches, unaffected)
`claude/songbook-catalogue-enhancements` (#93 publishers, 20 commits) + fix branches `fix/db-backup-streaming-1771`, `fix/v1-songlink-note-bindparam-1739`, `fix/importer-writes-credits-1736` — all pushed. See `2026-08-04-HANDOFF.md` on the songbook branch.
