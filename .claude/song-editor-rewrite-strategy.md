# Song Editor Rewrite — Strategy & Design (DRAFT for owner review)

> **Status: DRAFT. No build work until approved.** Tracking issue: **#1200**.
> **Owner goals:** same feature set · MySQL-native model (not the JSON-blob) · better performance + efficiency · maintainable.
> This doc is the basis for the rewrite. Section 10 lists the decisions that need your input before Phase 0 starts.

---

## 1. Why we're rewriting (not patching)

`editor.js` is ~5,800 lines of vanilla JS built for the **old JSON-corpus model**: the whole song is held in memory as a JSON blob, manually synced to a flat DOM, and serialised back on save. Epic #1010 modernised the *read/persistence* layer to DB-direct MySQL, but the **editor never caught up** — and that architecture is the common root of the bugs found while stress-testing this session:

| Symptom | Architectural cause |
|---|---|
| Duplicated components/credits (#1178) | Two uncoordinated save paths (manual + auto-save) racing |
| `SongId` truncated to 20 chars (#1199) | **Client** generates ids (JSON-era temp strings), not the server |
| "Bloody slow" delete / edit | Full `renderComponents` rebuild on every change — no diff model |
| Modal-backdrop leak (#1197), stuck "Auto-saving…" status (#1194) | Imperative DOM juggling; status set in one place, reset in another |
| "Nothing to save" after real edits | `modifiedSongIds` flag out of step with the in-memory blob |

Each fix has surfaced the next — diminishing returns on patching.

## 2. Goals & non-goals

**Goals:** full feature parity · a relational, MySQL-native data model · per-entity incremental rendering (no whole-form rebuilds) · one coordinated save path with server-assigned canonical ids · modular, maintainable code · zero data loss.

**Non-goals (this rewrite):** changing the curator *workflow/UX semantics* (same tool, just fast + solid) · adopting a heavy SPA framework (unless explicitly chosen) · adding new features (the known wishlist — presentation/casting #1066 — comes *after* parity).

## 3. Target architecture

### 3.1 Data model — relational + reactive
Hold the song as its **relational parts mirroring MySQL** (not one denormalised blob): `song` scalars, `components[]`, `writers[]/composers[]/arrangers[]/…`, `tags[]`, `links[]`, `arrangement[]`. A small **reactive store** (~100 lines, hand-rolled): each slice is independently observable; a view subscribes only to its slice, so a credit edit never re-renders the component list. Loaded via the existing DB-direct `load_song` (one fully-hydrated record).

### 3.2 View layer — per-entity, incremental
Each tab is a module; each component/credit is a small self-rendering view that updates **itself** on its slice's change. Add/delete/reorder = a targeted DOM op on **one** node + a model mutation — never a full-list rebuild. (Directly kills the "bloody slow" delete.)

### 3.3 Save model — one gate, server-authoritative ids
A single **save state machine** (formalising the #1194 serial queue): auto-save + manual Save both go through one gate, strictly serialised — no races. **The server assigns the canonical `SongId` on first save and returns it**; the client adopts it (the editor URL updates). Every save still writes a `tblSongRevisions` row + activity log (already in place).

### 3.4 Module structure (replaces the 5.8k-line file)
```
editor/core/      store.js · save-gate.js · song-model.js
editor/tabs/      metadata · structure · credits · links · tags · media · preview
editor/features/  reflow · import · export · arrangement · revisions · multi-select
editor/widgets/   place-search · credit-autocomplete · chords · component-card
```

## 4. Performance strategy
- **Incremental rendering** — per-card add/update/delete, never rebuild-all.
- **Virtualise** the component list for very large songs (sidebar already scroll-loads).
- **Batch DOM writes / avoid layout thrash** — group `autoResize` into one `requestAnimationFrame`; read-then-write phases.
- **Lazy tab content** — render a tab's heavy widgets on first activation, not up-front.
- **Cached/cloned templates** — build the language `<select>` once, clone per card.
- **Minimal payloads** — diffed save (or granular saves — see §10.1).

## 5. Tech approach
Stay on the project's **no-build vanilla JS + Bootstrap 5** stack, with a disciplined component pattern + a tiny hand-rolled reactive store and template helpers. **Recommendation: no framework, no bundler** (matches the rest of the codebase; avoids a build step). A micro reactive renderer (µhtml / lit-html via CDN) is an option if hand-rolled views get unwieldy — flagged as an open decision (§10.2), not a default.

## 6. Feature-parity inventory (MUST be preserved — gates each phase)
7 tabs (Metadata / Structure / Credits / Links / Tags / Media / Preview) · Paste & Reflow · per-component language + chords · arrangement/sequence editor · multi-select bulk ops (verify/move/export) · Import (8 formats) · Export (PP6 / PP7 / OpenSong / OpenLP / VideoPsalm / FreeShow / Proclaim / EasyWorship) · Revisions history · Composition-Origin geocoder · credit-people autocomplete · missing-numbers prefill · external-links editor · draggable sidebar · auto-save + offline · **delete a song** (see regression below).

### 6a. Known regressions the rewrite MUST fix (found this session)

- **Deleting a song does nothing server-side.** `deleteSong()` is client-only — it removes the song from the in-memory list + toasts "Song deleted", but **no `delete_song` endpoint exists** (confirmed against the api.php action list). The DB is never touched, so the song returns on refresh. A #1010 regression (the old corpus-save used to persist the in-memory removal). The rewrite adds a real `delete_song` that removes the song + its full FK subtree (40 child FKs, only 16 cascade — so enumerate via `INFORMATION_SCHEMA` or add the missing cascades) inside a transaction, verifies `affected_rows`, writes a revision/activity row, and returns the true result.
- **Client SongId generation + VARCHAR(20) truncation** (#1199) — server owns ids in the new model.
- **PRINCIPLE: never report success without verifying the server result.** Several toasts ("Song deleted", "saved") fired on client intent, not server confirmation. In the rewrite, every mutating UI action awaits the server's `{ok, affected_rows}` and surfaces a real error on 0/failure — no optimistic lies.

## 7. Server-side changes (Phase 0 — parity-safe, ships first)
1. **`save_song` assigns a canonical `SongId`** for new songs (server-side) and returns it — client stops generating ids. Decide the scheme for numberless/Misc songs (§10.5).
2. (Optional) **granular endpoints** (`component_upsert`, `credit_upsert`, …) if we go granular (§10.1).
3. Keep `load_song` as-is (already DB-direct, fully hydrated).

## 8. Phased plan — incremental "strangler", NOT big-bang
- **Phase 0 — server:** canonical SongId assignment (+ optional granular endpoints). No UI change.
- **Phase 1 — core + Structure tab:** new store + save-gate + the components/arrangement tab (the worst pain). Behind a flag (§10.3). Parity sub-checklist.
- **Phase 2:** Credits + Metadata tabs.
- **Phase 3:** Links · Tags · Media · Preview.
- **Phase 4:** Reflow · Import/Export · Revisions · multi-select.
- **Phase 5:** final cleanup — remove the old editor code paths once every tab is on v2.

**Git / delivery (owner directive, 2026-06-08):** the WHOLE rework is **ONE PR**, per the CLAUDE.md "one PR per piece of work" rule, opened only at the **very end** when every phase is complete + parity-checked. Each phase/piece lands as its **own atomic, individually-revertable commit** on the long-lived branch `claude/song-editor-rewrite-phase0`. **Nothing deploys to `alpha` until that final PR merges** — so the current editor stays live + untouched throughout the rework. Each phase still gets an owner verify pass against the branch (headless can't validate UI).

## 9. Risks & mitigations
- **Feature loss** → the §6 parity checklist gates every phase.
- **Can't test headless** → owner screenshot-verify per milestone (the cadence that's worked this session).
- **Scope creep** → strict parity-first; new ideas → backlog, not this rewrite.
- **Data integrity** → server-assigned ids + the single save gate + revisions; regression-test against the "Here to Stay" corruption repro.
- **Long-lived branch drift** (the branch now lives until the final single PR) → **merge `alpha` into the branch periodically** to stay current; keep each phase's commit atomic so any conflict resolves per-phase.

## 10. Decisions — RESOLVED (owner, 2026-06-08)
1. **Save granularity → GRANULAR per-entity saves.** Each component/credit/metadata-field change is its own **atomic MySQL write** (`component_upsert`/`component_delete`/`credit_upsert`/…), debounced per field. This *eliminates the whole-song save race entirely* — there is no monolithic save to interleave — and is the truly MySQL-native model. Trade-off accepted: a larger server endpoint surface (built in Phase 0). The `tblSongRevisions` snapshot is written on a coalesced debounce so history stays per-meaningful-edit, not per-keystroke.
2. **Tech → hand-rolled vanilla + tiny reactive store, no build step.** Matches the project's no-bundler stack.
3. **Rollout → per-phase HARD CUTOVER.** Each phase's module replaces the old tab directly once its parity checklist passes — no opt-in flag. *Mitigation (since there's no live fallback): each phase ships only after its parity checklist + an owner verify pass; the previous version stays one `git revert` away; cutover lands early in a release window, not before a deploy freeze.*
4. **Scope → PARITY + presentation/casting groundwork (#1066).** The rewrite also wires the dormant presentation schema (themes / slide overrides / arrangement fidelity) into the relevant tabs as it rebuilds them — done in one pass on the clean foundation rather than retrofitted later.
5. **Canonical SongId scheme** — to settle at the top of Phase 0. Official songbooks keep `<ABBR>-<NNNN>`; numberless/Misc songs get a server-assigned clean id (candidate: `<ABBR>-<base36 seq>` or a short ULID-style id ≤ 20 chars). Decided in the Phase 0 PR.

---

### Next step

**Phase 0 — server foundation** is the first commit(s) on the branch (**the PR comes at the very end** of the whole rework — see the Git/delivery note in §8). With the granular-save decision it covers:
- **Canonical SongId assignment** on create (server-owned; client stops generating ids) + the Misc scheme (§10.5).
- **Granular CRUD endpoints**: `component_upsert` / `component_reorder` / `component_delete`, `credit_upsert` / `credit_delete`, `metadata_field_update`, `tag`/`link` mutations — each atomic, each writing a coalesced revision + activity-log row, each guarded (CSRF, role, bind_param).
- All additive + parity-safe (the current editor keeps using `save_song` until Phase 1 swaps the Structure tab onto the granular endpoints).

This is low-risk and self-contained. **On your go, I'll commit this strategy doc and open the Phase 0 PR.** The in-flight fixes (#1194/#1197/#1199…) keep the current editor usable meanwhile.

---

## 11. Build log + mid-build directives (2026-06-08)

**Owner directives mid-build:**
- **Clean, purpose-built editor API** — do NOT bolt onto the 7,800-line legacy `api.php`. Done: `appWeb/public_html/manage/editor/api2.php` is the v2 editor backend (granular, atomic, CSRF-guarded). The legacy `api.php` is untouched; its editor actions (incl. the interim `delete_song` added in commit 150bcc89) are removed at cutover (Phase 5).
- **Redo the broader public/PWA API + OpenAPI docs** around the new editor — tracked as a follow-up issue, to start immediately AFTER the editor rework.
- **Version bump → v0.1000.x** at the very end (in the final rework PR) to signify the significant change. Files: `appWeb/public_html/includes/infoAppVer.php`, `api-docs.yaml`, `appWeb/CHANGELOG.md` (+ the release-promotion recipe).

**Phase 0 status — COMPLETE + VALIDATED (server foundation):**
- `api2.php` — `load_song`, `create_song` (`<ABBR>-<NNNNNN>` server-owned id allocator), `delete_song` (cascade), `metadata_field_update`, `component_upsert`/`delete`/`reorder`, `credit_upsert`/`delete`. CSRF on every write; `bind_param` throughout; `logActivity` per edit; coalesced `tblSongRevisions`. **Every SQL path validated against a real local MySQL** (allocator, coalesce window, cascade-delete = 0 orphans).
- Tags + links reuse the existing granular endpoints (not duplicated).

**Phase 1+ — REMAINING (the client UI):** the new editor UI (hand-rolled reactive store + the 7 tabs + reflow/import/export/revisions/multi-select), consuming `api2.php`. This is the large remaining body of work and **fundamentally needs browser verification** (headless can't validate UI behaviour) — to be built with the owner's screenshot-verify loop, per the parity checklist (§6). The CSRF `<meta>` token must be emitted in the editor `<head>` so the client can send `X-CSRF-Token`.
