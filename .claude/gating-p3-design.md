# #1769 P3 — emitter adoption + song.php fork collapse: implementation blueprint

> Durable blueprint for P3 (branch `claude/gating-model-review`, follows P2 = `.claude/gating-p2-design.md`).
> Produced by a sequential Fable design pass (orchestrator-verified against source), 2026-08-04.
> PRIME INVARIANT (same as P2): every commit is a **byte-identical no-op in BOTH states** — (a) `content_gating_enabled='0'`
> (live default: nothing changes at all) and (b) `='1'` (the same lyrics/media survive as today for every viewer).
> Everything stays DORMANT; P3 never flips the switch (P6). The two deliberate exceptions are Commit E's **response
> headers** (the cache-gap fix is itself the deliverable, body bytes unchanged) and Commit F's severable wiring —
> **DEFERRED to an issue in this build** (state-(b) delta, no explicit owner sign-off for a behaviour change).
> `CG`=`includes/content_gating.php`, `AC`=`includes/access_context.php`, `AR`=`includes/access_resolver.php`, `song.php`=`includes/pages/song.php`.

## 0. Findings that reshape the brief (verified against source)

1. **song.php:223–227 "renders are anonymous" note is HALF-STALE.** `getAuthBearerToken()` has a cookie fallback to `ihymns_auth` (#390), set by every login flow and `apiFetch` sends `credentials:'same-origin'`, so `getAuthenticatedUser()` inside song.php ALREADY resolves a signed-in web user. Only a degenerate cookie-less-but-token-holding session renders anonymously. **Auth-forwarding needs no new code** — correct the comment + fix the cache half.
2. **The api.php fragment ETag is generate-then-compare** (not a stored map): re-renders for THIS request, hashes, 304 only when the client's copy matches. Cannot serve a wrong body; already sends `Cache-Control: private` + `Vary: Cookie, Authorization`. The genuine leak channel is the **service-worker Cache-API buckets** (RECENT/PAGES/SAVED), which key by URL alone (Cache API can't evaluate `Vary: Cookie`), served across auth states of one device when offline, cleared only on logout.
3. **song.php ORs presence into ALL THREE media kinds** (sheet/midi too), while the API pipeline ORs presence only into `view_copyrighted`/`play_audio`. A real, pre-existing, dormant web/API divergence. P3 **preserves it byte-identically** and files it as an owner decision — silent convergence either way violates the invariant.
4. **song.php resolves Service-Mode presence up to TWICE per render** (256 CCL-notice copyrighted-only; 316–321 PD-independent media). Neither inner-caught. Collapse converges on the API's deny-on-error (§2.4, accepted).
5. **audio-media.php has NO tier decision today** (entity gate only, signing branch). Adding one is state-(b) = P6. P3 does not touch it (issue filed).
6. **checkBulkAccess() hard-codes presenceToken=null** (the D-2 congregant-denied-by-bulk gap). Additive param is byte-identical; WIRING is state-(b) = **Commit F, DEFERRED to issue**.
7. **songbook_export builds a full viewer per song** (resolveEffectiveTier = recursive CTE + userHasValidCcli + getUserEffectiveLicenceTypes + presence + api-key probe, all uncached). The 1285 hoist is the one adoption with real payoff.

## 1. api.php emitter adoption
- `random` (989), `song_detail` (1164): **STAY on the delegate** — one call each, the delegate IS the pipeline since P2; inlining re-copies the bail/try/fail-open surface for zero benefit.
- `songbook_export` loop (1284–1288): **MOVE — build the viewer ONCE, `array_map(accessApplySong)`** (§1.1).
- `bulk_audio` (2028): **STAY** — already one verdict per request.
- `song-media.php` (216): **STAY** — one call/request; delegate builds with `apiKeyScopes=[]`.
- `audio-media.php`: **NO CHANGE** (finding 5).

### 1.1 songbook_export hoist (Commit D)
Inside the `content_gating_enabled==='1'` branch, after the entity filter: `require_once` AC+AR (+ `function_exists('accessApplySong')` deploy guard); build `$exViewer = accessViewerContext($exUid2,$exPlat2,$exPres)` ONCE in try/catch (throw → error_log once + skip map); `$sbSongs = array_map(fn($s)=>accessApplySong($s,$exViewer), $sbSongs)`. `apiKeyScopes` stays null. Byte-identical: viewer is song-independent → `contentGatingApply($s_i) ≡ accessApplySong($s_i,V)` per P2. Accepted non-payload deltas (commit body): one error_log vs N; all-or-nothing vs mixed on a mid-loop transient (saner); N−1 fewer query clusters (perf).

## 2. song.php fork collapse (Commits A→B→C)
**KEPT verbatim:** the flag guard; auth+cookie-shape+entity gate (`checkContentAccess`→`$lyricsGated`/`$gateReason`); `$serviceCcliNumber` for the CCL notice (viewer carries only the bool); the outer try/catch fail-open + its error_log; the media-filter loop + `contentGatingMediaKindCap()` + `renderContentGatedFragment`.

**REPLACED (each equivalence-argued):**
- Tier hoist 262–277 → `$viewer = accessViewerContext($uid,'PWA',$presenceTok!==''?$presenceTok:null, []);` (`viewer['tier']`≡`resolveEffectiveTier()?:'public'`; `viewer['hasCcli']`≡same; tier throw propagates to the outer catch as today).
- Tier lyric gate 290–297 → `if(!$lyricsGated && !$lyricsPublicDomain && $serviceCcliNumber===null && empty($viewer['caps']['view_copyrighted'])){ $lyricsGated=true; $gateReason='A higher access tier is required to view these lyrics.'; }` (keep the human sentence — prose surface, not API reason keys; presence stays expressed via `$serviceCcliNumber===null`).
- `$mediaPresenceOk` 315–321 → `$viewer['presenceCcli']` (both = `serviceMode_presenceCcliNumber(...)!==null`, PD-independent; deletes the double DB hit).
- `$audioOk/$sheetOk/$midiOk` 322–327 → `$audioOk = accessMediaAllowed('audio',[],$viewer);` `$sheetOk = !empty($viewer['caps']['download_pdf']) || $viewer['presenceCcli'];` `$midiOk = !empty($viewer['caps']['download_midi']) || $viewer['presenceCcli'];` — sheet/midi deliberately DON'T use accessMediaAllowed (preserve the finding-3 quirk; comment marks it pinned-by-golden + the tracking issue).
- Flag flips 330–331 + media capBool 342–346: unchanged, fed by the trio.
- 223–227 comment: rewritten to §0.1 reality + pointer to the Commit E cache exclusion.

⚠ **The single nastiest trap:** the song.php viewer MUST be built with **`apiKeyScopes=[]`**. `null` would (i) newly resolve `content:gated` keys on page renders, and (ii) worse — the bypass short-circuit returns the neutral ALL-FALSE caps struct, and since song.php reads caps directly (not via accessApplySong's bypass early-return), a bypass-key request that renders fully today would come out GATED.

### 2.3 Presence restructure (one resolve, both consumers)
Resolve once up-front when `$presenceTok!==''` + helpers exist: `$presenceNumber = serviceMode_presenceCcliNumber(getDbMysqli(),$presenceTok,serviceMode_channel());` (NOT inner-caught → outer catch, today's semantics). `$serviceCcliNumber = ($entityAllowed && !$fullyPublicDomain && $presenceNumber!==null) ? $presenceNumber : null;` (identical derivation). The viewer additionally resolves `presenceCcli` internally (accepted second lookup — one indexed read, present only during live services; do NOT hand-assemble via accessViewerAssemble to save it — that re-forks "who is asking").

### 2.4 Throw-path deltas (accepted, documented) — all converge song.php on the API's deny-on-error; deterministic outputs golden-identical.

### 2.5 Location: extract the maths to **`includes/song_page_gating.php`** (require_once-safe — mandatory: bulk_songs + gating-noop-verify include song.php repeatedly). `songPageGatingDecide(array $viewer, bool $entityAllowed, string $entityReason, ?string $presenceNumber, bool $lyricsPD, bool $fullyPD, bool $hasAudio, bool $hasSheet, array $media): array{lyricsGated,gateReason,serviceCcliNumber,hasAudio,hasSheet,media}` — pure, I/O-free, the DB-free test seam. Optional `$gatingViewer` injection (bulk_songs pre-builds one viewer; song.php honours `if(!isset($gatingViewer))`).

## 3. Auth-forwarding + cache exclusion (Commit E)
**Auth: no code** (§0.1) — do NOT add `auth:true` to `loadPage()` (changes every navigation for a degenerate benefit; note in issue). Rewrite the stale comment in Commit C.
**Cache exclusion — header-driven (rule #35):**
1. api.php (~607): `$_gatedSongFragment = ($page==='song' && contentGatingEnabled());` exclude from `$_shouldCachePage`; emit `Cache-Control: private, no-store` on that branch. Flag off → one static-cached getAppSetting read, byte-identical. Flag on → all song fragments are viewer-dependent, so wholesale exclusion is correct. Do NOT touch home/songbook (rule #6).
2. service-worker.js.php: add `swResponseCacheable(response)` (false when `Cache-Control` has `no-store`); consult at EVERY fragment/song `cache.put` (fetch-handler PAGES_CACHE put, RECENT/SAVED put block, both CACHE_ALL_SONGS loops). Today no cacheable response carries no-store → state (a) byte-identical.
3. State-(b) header change IS the deliverable (closes the P6 "song.php cache MUST precede first-enable" constraint). Declared in the PR body.
4. Issues: CLEAR_USER_CACHES on login (today logout-only); SAVED_CACHE re-gate-on-next-fetch stays #1388 posture.

## 4. Verification
- Order A(seam)→B(capture)→C(collapse), goldens from the REAL pre-refactor maths.
- `tools/capture-song-page-gating-goldens.php` (CLI, DB-free, refuses-if-DB) runs the LEGACY seam over: tier×6 (incl. made_up_tier) × hasCcli×2 × entity(allow/deny+reason) × presence(none/number'741100'/resolve-null) × (lyricsPD,musicPD)×4 × media shapes(full/[]/absent/malformed/unknown) × hasAudio/hasSheet combos. Writes `tests/php/fixtures/song-page-gating-goldens.json` (tuple + matrixSha; JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).
- `test-song-page-gating-equivalence.php`: matrix sentinels + skip-if-DB + build viewers via `accessViewerAssemble(true,…)` + assert tuple≡golden + matrixSha.
- Structure guards: upgrade `test-gating-pipeline-structure.php` (b) to tree-derive its file list (grep includes/**/*.php for `$viewer[`); assert song.php/song_page_gating.php have NO direct `resolveEffectiveTier(`/`checkTierAccess(`/second media presence-resolve; song.php viewer built `apiKeyScopes=[]`; songbook_export's `accessViewerContext(` OUTSIDE the array_map; api.php `$_shouldCachePage` carries the gated-song exclusion; every SW `.put(` in fragment/song paths behind `swResponseCacheable` (put-sites derived by scanning the SW).
- On-server: `manage/gating-noop-verify.php` baseline before/verify after each of A/C/D/E on alpha (it hashes song.php HTML + the JSON).
- Local flag-ON differential: on dev `ihymns_live` (flag flippable), hash song.php HTML + song_detail JSON + one songbook_export × {anon, one user/tier} at parent vs each of A/C/D — byte compare, record shas in PR body.

Mutation checklist (break→red→restore): drop `$serviceCcliNumber===null` → replay red; reword tier sentence → replay(gateReason); remove sheet presence-OR (the quirk) → replay(present+under-tier+sheet); song.php viewer→null → structure; re-add `checkTierAccess(` to song.php → structure; move D's viewer into the map → structure(+local diff); typo'd `$viewer['cap']` → viewer-key guard; delete api.php cache exclusion → structure; remove `swResponseCacheable` from a put → SW guard; hand-edit a golden → matrixSha/replay.

## 5. Commit sequence (one PR → alpha)
- **A** — song.php seam extraction (`song_page_gating.php` with LEGACY maths + the one presence-resolve hoist) — behind flag, noop-verify baseline, local flag-on HTML diff vs parent.
- **B** — golden capture tool + fixture (from the A seam).
- **C** — the collapse (viewer `apiKeyScopes=[]`, decisions from `$viewer`, double-presence + tier/ccli inlines deleted, 223–227 rewritten); equivalence replay + structure guards; run the mutation checklist.
- **D** — songbook_export hoist + in-code notes at 989/1164/2028/song-media that staying on the delegates is the decided end-state; optional bulk_songs viewer injection.
- **E** — cache exclusion + SW no-store honour + guards. The one intended state-(b) header delta, called out in the PR body.
- **F — DEFERRED** — `checkBulkAccess` additive `?string $presenceToken=null` + the D-2 wiring is a state-(b) delta → filed as an issue for owner sign-off, NOT shipped in this dormant build.
- **G** — docs + issues: annotate every new file; update the plan, this file, CHANGELOG, project-rules §18.7 (Wiki at #91). File issues: (1) sheet/midi presence-OR web/API divergence (P6); (2) audio-media.php serve-time tier gate (P6); (3) SW cache clear on login; (4) D-2 wiring (Commit F deferral); (5) retrospective note correcting the stale song.php:223–227 claim.

## Scope fence — P3 does NOT
Move random/song_detail/bulk_audio/song-media off the delegates; touch audio-media.php; converge the sheet/midi quirk or any state-(b) behaviour (except the declared Commit E headers); add `auth:true` to loadPage; per-user-ETag anything (rule #6); wire `accessResolve()` into production (P6); emit rights-fact keys from SongData; build the admin hub / Rights panel (P4); flip the master switch (P6); ship Commit F's bulk-presence wiring without owner sign-off.
