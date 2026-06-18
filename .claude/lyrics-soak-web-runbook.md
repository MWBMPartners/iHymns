# Lyrics-cutover SOAK — web runbook (#1235 P4)

> How the **web-only** owner runs the #1235 cutover verification soak entirely from
> **/manage/setup-database** (no CLI — shared DreamHost; see the operator-web-only
> constraint). The gate is `appWeb/.sql/verify-lyrics-cutover.php`, surfaced as the
> **"Verify Lyrics-Cutover Gate (#1235)"** card (web runner #1280). Companions:
> `lyrics-cutover-promotion-checklist.md`, `lyrics-cutover-dress-rehearsal.md`,
> `lyrics-normalisation-strategy.md` §11.

## Prereqs (done 2026-06-18)
- Track-A migrations applied on alpha via **Apply all** (`component-line-languages`,
  `lyric-lines-parttypeslug`; `song-part-types` + `lyric-lines-mirror` were already
  applied). Only the manual `retire-component-lines-json` drop remains pending.
- **Guardrail for the whole cutover:** edit lyrics on **alpha only**. A lyric edit on
  beta/production (pre-cutover code) writes `LinesJson` only and orphans `tblLyricLines`,
  breaking parity.

## The card buttons
`Smoke test` (first 50, no sentinel) · `--phase=pre` (full baseline + sentinel) ·
`--phase=soak` · `--phase=pre-drop` (confirm-gated, drop-day) · `--phase=post-drop`.
A **complete** run ends with `== GREEN — phase <p> passed ==` (or `RED`); `pre`/`pre-drop`
also print `sentinel: lyrics_cutover_gate written (green)`. No trailer ⇒ the request was
truncated by a host timeout — nothing was armed, just re-run.

## Step 1 — Smoke test (safe path check)
Click **Smoke test**. Expect all 10 gates `[PASS]`, `songs:50`,
`sentinel: NOT written (--limit run …)` (correct), `== GREEN — phase pre passed ==`,
badge **Complete**. RED here (real divergence in 50 songs) ⇒ stop, capture output.

## Step 2 — Full baseline (`--phase=pre`, once)
Click **Run --phase=pre**. Full corpus (~16,083 songs), ~1–3 min, leave tab open.
Success = `== GREEN — phase pre passed ==` **and** `sentinel: lyrics_cutover_gate written (green)`.
(2026-06-18: GREEN on the full corpus — 16,083 songs / 70,132 components / 291,634 lines,
all 10 gates incl. G2 byte-identical; fingerprint `corpusSha256 30ec02…9bcc`.) Not destructive;
`pre` does NOT arm the drop (only `pre-drop` + `confirm=1` can).

## Step 3 — Soak (`--phase=soak`, recurring)
Run periodically through the window. **The meaningful test:** make real edits in the alpha
Song Editor (verse/chorus/**chords**/**per-line language**/**revision restore**/**import**),
then run `--phase=soak`. Expect a **different** fingerprint (content changed — fine) but every
gate, especially **G2**, still `[PASS]`. A `phase=soak` sentinel CANNOT arm the drop.
⚠ If `G2`/`G1`/`G3`/`G8` ever flips `[FAIL]` after an edit → that's the bug the soak exists to
catch; stop and capture the output.

### Automated nightly soak (DreamHost panel cron)
Set up 2026-06-18 by the owner. Command shape (no interactive shell needed):
`php <dev.ihymns.app web root>/.sql/verify-lyrics-cutover.php --phase=soak`
(`.sql/` is the sibling of the web root; the script is dual-mode CLI/web). The cron's STDOUT
goes to DreamHost cron email — but every run ALSO writes an **Activity-Log row** (#1282), so a
RED soak is visible at **/manage/activity-log** (`action=setup.verify_cutover`, `result=failure`)
without checking email.

## Observability (#1282)
Every verify-gate run (cron + web) and every setup-database migration/ops run logs to the
Activity Log with status + error. To check soak health in-app: `/manage/activity-log` → filter
`action=setup.verify_cutover` → any `failure` row's details list the failing gates.

## Exit criteria → promotion
Ready for the alpha→beta→main promotion (then the gated drop) when: `pre` baseline GREEN + sentinel
written (✅); several `soak` runs GREEN **including after real edits**; alpha song pages render
correctly. Then: promote the full stack to beta+prod (so all 3 shared-DB envs are drop-safe), then
run `pre-drop` inside a 3-env maintenance freeze and the gated `retire-component-lines-json` drop ONCE.
