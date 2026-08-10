# HANDOFF — branch consolidation + queued work (2026-08-05)

**Working branch: `claude/issue-sweep-fixes-89`** (off `alpha`). ALL future work + commits go here.
NO PR stacking — one branch → alpha via a single later PR. Owner has NOT asked for the PR yet.

## ✅ DONE — 6-branch consolidation into `claude/issue-sweep-fixes-89`

All six branches merged (sequential `git merge`, additive conflicts resolved as UNION), pushed to
origin. Verified each source tip is an ancestor of HEAD (fully contained). Whole test suite green:
**PHP 128/128, node 50/50**; tree-wide conflict-marker scan clean; `php -l` + `node --check` clean.

| Source branch | brought in | merge commit |
|---|---|---|
| `fix/v1-songlink-note-bindparam-1739` | #1739 v1 song-link note bindparam fix | `91b1d7d8` |
| `fix/importer-writes-credits-1736` | #1736 bulk importers persist credits | `f9d88098` |
| `fix/db-backup-streaming-1771` | #1771 streaming DB backup + VIEW dumps | `59928bfd` |
| `claude/songbook-catalogue-enhancements` | #1765 epic + #93 publisher registry (rule #37) | `9f2122ed` |
| `claude/gating-model-review` | #1769 P0–P5 gating consolidation (dormant) | `0429c516` |
| `claude/print-templates-1767` | #1767 print enhancements + QR-via-CueRCode (rule #38) | `802eb3ef` |

Consolidation also fixed 3 cross-branch guard gaps the merge surfaced (commit `8e5c73f3`):
- a11y guard: reworded a `//` comment in `print-templates.php` that mentioned `<img>` (no real image).
- musician-rename guard: dropped stale old-name `tblCreditPeople` from a #1736 importer comment.
- songbook-visibility guard: added `@disabled-visible:` markers to 4 #1769 admin/editor/migration
  reads that legitimately must see disabled songbooks (licence-type delete-safety count, editor
  songbook-defaults read, editor single-song by-id read, derive-rights-facts completeness probe).

### ⚠️ Branch DELETION blocked — owner action needed (safe list confirmed)
The agent git proxy returns **HTTP 403 on delete-ref** (permits push, not branch deletion). Per the
owner's fallback instruction, here is the **confirmed safe-to-delete list** — all 6 are 100% merged
into `claude/issue-sweep-fixes-89` (tips are ancestors of HEAD, pushed to origin) and **no open PR
references any of them** (repo has 0 open PRs):
```
claude/print-templates-1767
claude/songbook-catalogue-enhancements
fix/importer-writes-credits-1736
fix/v1-songlink-note-bindparam-1739
fix/db-backup-streaming-1771
claude/gating-model-review
```
Owner can delete these on GitHub (Branches page) or `git push origin --delete <branch>` locally.

## ✅ PROGRESS LOG (this session, all on `claude/issue-sweep-fixes-89`, pushed)
- **Owner bug batch 2026-08-05 (5 issues filed #1788–#1792)**:
  - **#1788 ProPresenter 7+ export broken (CSP unsafe-eval)** — root cause CONFIRMED:
    `propresenter-export.js` loads schema via `protobuf.Root.fromJSON` (reflection) → `.encode()`
    codegens via `new Function()` → refused by the enforcing nonce CSP (#117). Only PP7 (protobuf)
    hits it. **Fix = protobufjs static-module (`pbjs -t static-module`)** — the .proto sources (118)
    are present but `protobufjs-cli` isn't installed + no offline npx, so it needs `npm i protobufjs-cli`
    via the proxy, then rewire `init()`. NOT done yet. NEVER add `'unsafe-eval'` (would gut the CSP).
  - **#1789 setlist Print junk + no template choice** — INTERIM FIX LANDED (`60cd3bc6`): tagged the
    report/correction block `song-page-feedback d-print-none` in song.php + the setlist print CSS now
    hides `.d-print-none`/`.song-page-feedback`/`#song-correction-form`. FULL fix (render each song via
    the #1767 print-template engine so setlist Print offers/honours a template) → folded into #1767.
  - **#1788 LANDED** (commit `453c6dc2`): PP7 export was dead under the nonce CSP — reflection
    protobuf (`Root.fromJSON`→lazy `new Function` codegen) is refused by #117. Fixed with a
    precompiled **static** encoder (`pbjs -t static` → `protos/pp7-proto-static.js` +
    `tools/build-proto-static.js`), exporter prefers it, loaded on all 3 surfaces. Proven
    BYTE-IDENTICAL to reflection + CSP-safe (`tests/test-propresenter-static-csp.js`, mutation-proven).
    `protobufjs-cli` = documented one-off install, NOT a committed devDep. NEVER add `unsafe-eval`.
  - **#1790 setlist share = playlist** (open link → tap song → navigate; drop Import + "Use") → Fable.
  - **#1791 setlist collab via share-link** (no account/email invite; capability-URL edit token) → Fable.
  - **#1792 Go Live/Join Live untestable** = the `Channel` wall (rule #26): desktop-on-dev + phone-on-www
    are different channels → always "wrong code". Fix = same-channel test procedure + clearer cross-channel
    error + (maybe) admin cross-channel bridge → planned under #1770.
  - Owner flagged "more, especially Editor2, later" — expect an Editor2 bug batch next.

- **Musicians epic #1787 (NEW owner asks 2026-08-05, two messages + screenshots)** — filed epic
  #1787 + children #1784/#1785/#1786.
  - **#1784 LANDED** (commits `bb75b682` + ref-fix `34b990df`): the weeks-old "1 person credited but
    not saved" stuck counter = an **invisible-byte mismatch** (credit-table `"Eddie James "` with a
    trailing space vs registry `"Eddie James"`; list links by EXACT byte, never links them; fuzzy
    shows "merge X into X"; merge button trimmed-then-skipped so it could never fix it). Fix:
    `musicianReconcileCreditNameBytes()` shared core + `migrate-reconcile-credit-name-bytes.php`
    (Apply-all card + drift probe) rewrites legacy credit bytes to the registry canonical / auto-
    registers; "Add all N now" + the fuzzy-merge branch now call/behave correctly;
    `musicianCitedUnregisteredNames()` aligned to BINARY (rule #35). **Verified end-to-end on live DB**
    (inject→probe pending→reconcile adopt+register→probe clear→idempotent 0/0). Terminology swept
    "Credit People"→"Musicians" on the live page/bulk-promote/songbook person-picker (UI-copy-only,
    rule #24; internal ids + migration CARD titles unchanged). **⚠️ Owner must run the "Reconcile
    Credit-Name Bytes" migration card on the shared DB after deploy to clear the live counter.**
  - **Finding:** auto-register is ALREADY implemented — `musicianPromote()` runs on every credit-write
    funnel (credit_upsert/save/importers/lyrics_ingest). New musicians auto-register with matching
    trimmed bytes; the stuck-1 was purely legacy data.
  - **#1785** (registry-vs-registry dedup + easier/disambiguated merge UX) and **#1786** (app-wide
    multi-column sortable tables, admin+public) → **Fable deep-plan queued AFTER #1770** (sequential
    Fable rule). Broader terminology-consistency audit tracked under the epic.

- **#85** numberless `#0` in share title/breadcrumb/OG — fixed (pre-consolidation).
- **#112** offline count missed saved-cache — fixed (pre-consolidation).
- **6-branch consolidation** — DONE (see below), branch-delete blocked (safe list below).
- **#288** song-page tags rendered server-side — commit `e9fcf3e7`.
- **#150** article-aware songbook sort (`includes/sort_helpers.php` + guard) — commit `ff24d850`.
- **#307** dead search-autocomplete removed (client+CSS+endpoint+`suggestSongs`+api-docs+test) — `3e5e2a46`.
- **#299** inline chord charts + toggle + transpose wiring (`includes/chord_display.php` + guard) — `b62333f5`.
- **#302** set-list "Save as PDF" (browser print, house-style) + dead page-button removed — `02ffc165`.
- **Print tweak** — Songbook+number block prints nothing for unofficial/catalogue songs
  (`songInPublishedBook()` in print.js) + in-app note + `help/exporting.md` — `86a2e56d`.
- **#1770 direction captured** in `.claude/live-follow-1770-analysis.md` (Fable analysis + owner
  steering: quick-session persistence, configurable idle-timeout hierarchy, host-CCLI unlock,
  ProPresenter-as-console). NOT built — awaiting scheduling + a planning pass.

- **Duplicate-song feature (#1783, NEW owner ask) — ✅ COMPLETE (commits 1-6).** Option C
  (hidden `PENDING` staging book, `.claude/duplicate-song-1783-plan.md`, now marked AS-BUILT).
  Commits 1-3 (`34b86654`, `81471f66`) + 3 CI-gap fixes (`1f54583d`) landed earlier. This
  session: **commit 4** (`b6a74519`) — per-line enrichment (`tblLyricLineTranslations` /
  `tblLyricLineAnnotations`) + scripture refs (`tblSongScriptureRefs`) re-anchored onto the NEW
  song's lines via a positional src→new line-id map (best-effort; un-migrated optional table is
  caught + skipped). **Runtime-verified** against a live-DB fixture: translations/annotations/
  scripture land on the new line ids, `EndLineId` remaps, whole-song scripture `StartLineId` stays
  NULL, source untouched. **Commit 5** (`841e9217`) — mutation-proven guards
  `tests/test-editor-duplicate-contract.js` (client↔server↔shell contract + no client `'PENDING'`
  literal) + `tests/php/test-duplicate-copy-set.php` (copy-set: resets, ONE apply path, enrichment
  re-anchored, no move/personal-state leak) + explicit `?duplicate=` leg in
  `test-editor-deep-links.js`; all 7 legs proven able to fail. **Commit 6** = this docs pass.
  Full suite green: **node 52/52, PHP 132/132.**
  **Deferred (filed `for consideration`, non-blocking):** per-line presenter `Note` carry;
  a `/manage/duplicate-songs` `?duplicate=` emitter; a `songRelocate()` no-redirect flag for
  staging-origin moves; **D5 counterpart auto-link — re-decided**: NOT auto-linked at duplicate
  time (a PENDING draft link is premature + would surface a draft in the source's counterpart
  panel + need re-keying on assign); the correct place is ASSIGN time and it needs a source
  marker — deferred rather than done wrong.
  **ProjectBrief.md** full refresh deferred to the FINAL docs sweep (#91). **Wiki editor page**
  NOT updated — no `iHymns.wiki/` checkout in this environment (flag for the docs sweep).

**STILL TO DO this queue:** #1789 set-list print full fix (via #1767 engine); #1791
collab-by-link; #1785/#1786 musicians-dedup + app-wide sortable tables; #1792/#1770 Live
Follow UX; then the thorough documentation sweep (all .md/in-app/OpenAPI/Swagger UI/.claude
+ ProjectBrief refresh + Wiki) and the version bump — all LAST (#91). #1783 is DONE.
**#1770 DECIDED** (Option A — quick console optional, host-CCLI unlock to quick followers,
slide-level ProPresenter control on scheduled). Full spec in `live-follow-1770-analysis.md`;
build is queued behind #1783 (needs its own Fable planning pass over the 7 captured requirements).

## 🔜 QUEUE (owner-stated order, adjust/bundle as sensible)
1. **#1770 Live Follow / Service Mode UX rethink** (ad-hoc vs scheduled) — Fable 5 sequential deep
   analysis + planning (fallback Opus), then Sonnet/Haiku impl. "as discussed yesterday."
2. **Sweep actions** (from #89 triage, `.claude/sessions/2026-08-05-issue-sweep-89.md`):
   - #288 wire song-page tag display (`#song-tags-list` hidden, tags already in `song_detail`)
   - #299 wire chord toggle + transpose (`#btn-toggle-chords` hidden; transpose needs `[data-chord]`)
   - #302 **BUILD** setlist PDF export now (`#btn-export-pdf` hidden, no PDF path)
   - #307 remove dead search autocomplete (`_initAutocomplete` has ZERO callers) — or wire it
   - #150 article-aware songbook sort (`getSongbooks()` ORDER BY b.Name, no article strip)
3. **Print-template tweak** — a song in an unofficial songbook / catalogue / non-published book has
   NO Song Number, so **Songbook + Songbook-number print blocks must output NOTHING** for it
   (`print.js` renderBlock). Document in in-app help + user guides.
4. **Duplicate-song feature (NEW owner ask 2026-08-05)** — "duplicate a song as a starting point for
   a new songbook": clone opens immediately in the Song Editor with **Songbook + Song number EMPTY**,
   editable (lyrics/chords/credits/external IDs/songbook/number). File a GitHub issue first.
5. **Documentation sweep (LAST)** — all .md, in-app help/guides, wiki, OpenAPI/Swagger (+ Swagger UI,
   shared-host friendly, no Docker), .claude Memory/Context, version bump.

Also still open from prior queue: #48 annotation backfill (#1158), #90 ranked proposals, #94 IA OCR
importer (owner feasibility Q), #91 FINAL docs (LAST).

## Working method (owner-stated)
- Deep Analysis + Deep Planning via **sequential (not parallel) Fable 5** agents (fallback Opus, retry
  Fable next time). Implementation via Sonnet/Haiku (Opus if complex). Use dev-team-plugins.
- After EACH task: commit+push to working branch · update the GitHub issue(s) individually ·
  update .claude Memory/Context · update THIS handoff.
- Autonomous — only surface a decision that EXPLICITLY needs the owner (owner-question shape: decision
  / why / options+consequences / recommendation / smallest reply). GIRFT. Model = claude-opus-4-8
  (NEVER in commits/PRs/artifacts).

## Local test infra
MariaDB socket `/run/mysqld/mysqld.sock`; restart: `mkdir -p /run/mysqld && chown mysql:mysql
/run/mysqld && nohup mariadbd --user=mysql --datadir=/var/lib/mysql --socket=/run/mysqld/mysqld.sock &`.
Creds `appWeb/.auth/db_credentials.php` → `127.0.0.1:3306/ihymns_live` (149 tables). CI globs
`tests/php/*.php` + `tests/*.js`.
