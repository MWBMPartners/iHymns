# Dependabot alerts — findings and plan (24 November 2026)

Read-only analysis (Opus). Nothing was changed, merged, dismissed or pushed. Facts were checked today on
`origin/main`, `origin/beta` and `origin/alpha`, using `npm ls` / `npm audit` on copies of each branch's
`package.json` + `package-lock.json` in a scratch folder.

## The short answer

- All 19 open alerts are in **test and build tools only** (ESLint, jsdom, the protobuf command-line tool).
  None of that code is uploaded to a website or built into an app. I found **no way for an outsider to
  use any of them against us**; the worst realistic outcome is a slow or crashed CI job.
- They are open because GitHub only checks `main`, which was last promoted on 15 August (0.5250.0, 141
  merged pull requests behind `alpha`). `alpha` already has fixed versions of everything except **one**
  package (`brace-expansion` 2.x, alert #17).
- Every fix fits inside the version ranges already allowed, so **only the lock file changes**, never
  `package.json`. Tried on a copy of `main`: `npm audit` goes from 7 vulnerable packages to 0, ESLint is
  clean and all 61 Node test files pass.
- **Recommended:** (1) fix that one package on `alpha`; (2) a small lock-file-only pull request to `main`,
  merged by the owner — all 19 close within minutes; (3) the next promotion carries alpha's already-fixed
  lock file, so nothing comes back. No overrides, no dismissals.

## The 19 open alerts (npm, development scope, `package-lock.json`)

Pulled in by, on `main`: **E** = `eslint` 10.9.1 (lint step) · **J** = `jsdom` 30.0.1 (Node tests) ·
**J›css** = jsdom → `css-tree` 3.2.1 · **P›doc** = `protobufjs-cli` 2.7.0 → `jsdoc` 4.0.5 ·
**P›glob** = `protobufjs-cli` 2.7.0 → `glob` 8.1.0 → `minimatch` 5.1.9. "Step 2 / promo" = cleared by
Step 2, or by the next promotion if Step 2 is skipped.

| # | Package — advisory | Sev | On main | By | Fixed in | On alpha | Exploitable here? | Cleared by |
|---|---|---|---|---|---|---|---|---|
| 30 | minimatch — GHSA-g3hv-75wg-m249 | med | 10.2.6 | E | 11.0.1 | 11.0.9 ✔ | No — patterns come only from our own ESLint config | Step 2 / promo |
| 29 | undici — GHSA-4w7w-5xrc-9h26 | high | 8.9.0 | J | 8.12.2 | 8.13.1 ✔ | No — cache + redirect add-ons, unused; no real servers | Step 2 / promo |
| 28 | undici — GHSA-2m5v-pch6-74hx | high | 8.9.0 | J | 8.12.2 | ✔ | No — needs a custom certificate-check function; none | Step 2 / promo |
| 27 | undici — GHSA-qrxm-fqhp-jwvm | high | 8.9.0 | J | 8.12.2 | ✔ | No — needs HTTPS to a hostile server; tests make none | Step 2 / promo |
| 26 | undici — GHSA-9fm3-97px-rjf6 | med | 8.9.0 | J | 8.12.2 | ✔ | No — WebSocket only; tests open none | Step 2 / promo |
| 24 | undici — GHSA-xjj6-f33q-929c | high | 8.9.0 | J | 8.12.2 | ✔ | No — needs a hostile server reply; `fetch` is stubbed | Step 2 / promo |
| 11 | undici — GHSA-pmjh-fq2x-6v4x | med | 8.9.0 | J | 8.10.2 | ✔ | No — retry add-on, unused | Step 2 / promo |
| 10 | undici — GHSA-r53p-7pc4-xj5r | low | 8.9.0 | J | 8.10.2 | ✔ | No — retry add-on in a proxy, unused | Step 2 / promo |
| 7 | undici — GHSA-2jfj-6hjv-fm6j | med | 8.9.0 | J | 8.10.2 | ✔ | No — shared-cache add-on, unused | Step 2 / promo |
| 6 | undici — GHSA-2gqq-gqf2-x968 | low | 8.9.0 | J | 8.10.2 | ✔ | No — "dump" add-on, unused | Step 2 / promo |
| 5 | undici — GHSA-w293-vg96-wgc3 | high | 8.9.0 | J | 8.10.2 | ✔ | No — `BalancedPool` + custom TLS function, unused | Step 2 / promo |
| 4 | undici — GHSA-8436-99hf-9mmv | low | 8.9.0 | J | 8.10.2 | ✔ | No — cache add-on, unused | Step 2 / promo |
| 21 | markdown-it — GHSA-gfr2-rv44-r23c | high | 14.3.0 | P›doc | 14.3.3 | 14.3.3 ✔ | No — never loaded (see below) | Step 2 / promo |
| 20 | markdown-it — GHSA-vfgh-jcmw-g37q | low | 14.3.0 | P›doc | 14.3.3 | ✔ | No — never loaded | Step 2 / promo |
| 19 | markdown-it — GHSA-r7fv-28h4-cvq7 | high | 14.3.0 | P›doc | 14.3.2 | ✔ | No — never loaded | Step 2 / promo |
| 2 | markdown-it — GHSA-253c-mchw-3w2r | med | 14.3.0 | P›doc | 14.3.1 | ✔ | No — never loaded | Step 2 / promo |
| 18 | source-map-js — GHSA-68fv-2mgg-jv7q | high | 1.2.1 | J›css | 1.2.2 | gone ✔ (jsdom 31) | No — css-tree loads only the map *writer*; the bug is in the *reader* | Step 2 / promo |
| 17 | brace-expansion — GHSA-q2hr-2g5m-vwhr | med | 2.1.4 | P›glob | 2.1.7 | **2.1.4 ✘** | No — expands only our own fixed `.proto` paths | Step 2; promo **only after Step 1** |
| 16 | brace-expansion — GHSA-q2hr-2g5m-vwhr | med | 5.0.9 | E | 5.0.12 | 5.0.13 ✔ | No — same as #30 | Step 2 / promo |

Totals: 8 high, 7 medium, 4 low; **11** undici (not 10), 4 markdown-it, 2 brace-expansion, 1 minimatch,
1 source-map-js. Eleven more (undici #1, 3, 8, 9, 22, 23, 25; brace-expansion #12–15) were auto-dismissed
by a Dependabot rule on 23 Nov 12:54; Step 2 fixes those packages too.

## Why none of it is exploitable here

- **Nothing ships.** `deploy.yml` uploads `appWeb/public_html/` (plus `.sql`, `.auth`, `private_html`);
  `node_modules/` sits at the repo root and is never uploaded. No vendored copy of these five libraries
  exists on any branch (`manage/editor/vendor/protobuf.min.js` is the protobuf runtime, no alerts), and
  `tools/build-web.js` imports only Node's own modules.
- **undici** is used only by jsdom in the Node tests. Every test builds its page from a string at the
  made-up address `https://example.test/`, loads no outside resources (no `resources: 'usable'`, no
  `fromURL`) and replaces `fetch` with a stub. All eleven bugs need our code to talk to a hostile server.
  (Node's built-in `fetch` uses Node's own internal copy of undici; that updates with Node, not here.)
- **markdown-it** belongs to `jsdoc`, which `protobufjs-cli` starts only from its `pbts` command. We never
  run `pbts`; `tools/build-proto-static.js` uses `pbjs`, which never loads jsdoc.
- **minimatch / brace-expansion** read file-name patterns: ESLint's from our `eslint.config.js`; `pbjs`'s
  from a fixed list of our `.proto` files, run by hand by a developer, never in CI.
- **Who could trigger anything?** Only someone who can change repository files — who can already run any
  code — or a fork pull request, whose own code already runs in CI with read-only access and no secrets
  (`test.yml`: `pull_request`, `contents: read`). These bugs give neither anything extra.

## What happened to Dependabot pull requests #2191, #2192, #2193, #2196

- GitHub opens security fixes only against `main`. On 23 Nov **#2191 (undici), #2192 (minimatch + eslint)
  and #2193 (markdown-it)** were re-applied to `alpha` as #2195 (commit `33cbd111`: "the three Dependabot
  branches were cut from an old commit") and closed unmerged at 12:56. **#2196 (source-map-js)** was
  closed unmerged at 20:10; `alpha` no longer has source-map-js (jsdom 31, #2161). `main` never got any
  of them, so the alerts stayed open.
- Closing unmerged makes Dependabot say "I won't notify you again about this release", and the branches
  are deleted, so **Dependabot will not redo these** — waiting for it is not a route.
- **Correction to an earlier note:** CI *did* run and pass on #2191 and #2196. What never reports: the
  "Protect main branch" ruleset requires checks named **"Frontend (ubuntu-latest)"** and **"Backend
  (ubuntu-latest)"**, which no workflow on any branch produces, so every PR into `main` needs an admin bypass.
- No brace-expansion fix PR exists anywhere; why is not visible from here (the alert page shows it).

## Routes compared

| Route | Clears | Cost / risk |
|---|---|---|
| A. Promote alpha → beta → main | 18 (19 if Step 1 lands first) | It is the big production release (account rewrite, migrations, backups, switch-ons — 24 Nov handoff). Date unknown; alerts stay open until then |
| B. Lock-file-only PR to `main` | All 19, minutes after merge | Tiny; tested on a copy. Owner merges with a bypass. Harmless at promotion: alpha's lock file is equal or newer, so "take alpha's file" is the right conflict answer |
| C. `overrides` in `package.json` | Nothing by itself (GitHub reads `main`) | Not needed — every fix fits existing ranges. A hand-kept list that goes stale and can force a broken mix later. Keep for a parent that pins a bad range |
| D. Dismiss with a reason | Hides all 19 | Hides, fixes nothing; needs the owner's explicit OK; this session cannot dismiss. Last resort only |

## Recommended plan (in order)

**Step 0 — implementer.** File one issue first ("Clear the 19 Dependabot alerts on main — dev tools
only"), linking this file; optionally one each for Q2 and Q4.

**Step 1 — implementer, `alpha`, no owner action.** Run the standing-directives §2 branch check first;
none exists on the remote today — if one exists when you start, commit onto it instead.
```sh
SCR=<your scratchpad folder>      # never run npm in the main working tree
git -C /home/user/iHymns fetch origin alpha
git -C /home/user/iHymns worktree add "$SCR/wt-alpha" -b chore/deps-brace-expansion origin/alpha
cd "$SCR/wt-alpha"
npm update brace-expansion --package-lock-only --ignore-scripts --before=2026-11-21T00:00:00Z
git diff --stat; npm audit --package-lock-only     # lock file only; "found 0 vulnerabilities" (was 1 high)
npm ci && npm run lint && npm test
```
Expected: `node_modules/glob/node_modules/brace-expansion` 2.1.4 → 2.1.8, nothing else (tried on a copy).
`--before` keeps to versions a few days old (the same ones alpha runs) and skips `brace-expansion` 5.1.0,
published yesterday. PR title `chore(deps): brace-expansion 2.1.8 under glob (Dependabot #17)` (build
number only, rule #46); no WHATS-NEW bullet. It auto-merges once CI is green.

**Step 2 — implementer prepares, owner merges, `main`.** Needs Q1.
```sh
git -C /home/user/iHymns fetch origin main
git -C /home/user/iHymns worktree add "$SCR/wt-main" -b chore/deps-main-dev-tools origin/main
cd "$SCR/wt-main"
npm update eslint @eslint/config-array minimatch undici markdown-it source-map-js brace-expansion \
  --package-lock-only --ignore-scripts --before=2026-11-21T00:00:00Z
git diff --stat; npm audit --package-lock-only     # lock file only; 0 (was 7: 4 high, 3 moderate)
npm ci && npm run lint:js && npm test              # my run: ESLint clean, 61 of 61 test files pass
```
Expected after: eslint 10.15.0, @eslint/config-array 0.23.6, minimatch 11.0.9, brace-expansion 5.0.13 and
2.1.8, undici 8.13.1, markdown-it 14.3.3, source-map-js 1.2.4 — alpha's versions. ESLint's own helpers move
too (file-entry-cache 11, flat-cache 6, keyv 5, nine small new ones), already proven on alpha. Open the PR
via REST (`gh api repos/MWBMPartners/iHymns/pulls -f base=main -f head=chore/deps-main-dev-tools ...`),
title `chore(deps): refresh dev-tool lock file on main (clears 19 Dependabot alerts)`.
**No `security` or `backport` label:** `main` still runs the old backport workflow, which would try
`alpha` (conflict → a "Manual backport needed" issue) and open a `beta` PR whose checks are held.
The merge fires the deploy bridge → `deploy.yml` on `main`; it finds no `appWeb/` change in the last five
commits and uploads nothing — confirm the run says "No deployable files changed".
**Verify:** `gh api '/repos/MWBMPartners/iHymns/dependabot/alerts?state=open&per_page=100' --jq length`
→ `0` within minutes; the Security tab lists the 19 as fixed.

**Step 3 — owner, at the next promotions.** In the alpha → beta and beta → main pull requests, resolve any
`package.json` / `package-lock.json` conflict by taking alpha's files whole, then `npm audit
--package-lock-only` must say 0. `beta` is left alone until then: GitHub does not scan it, its lock file
is identical to `main`'s today, and promotion replaces it (the Step 2 commit cherry-picks cleanly onto
`beta` if the owner wants it clean sooner).

## Code scanning (15 alerts) — NOT verified (this session gets HTTP 403)

Visible facts: it is CodeQL **"default setup"** (no workflow file), run **weekly on `main` only** (last
17 Nov), checking **GitHub Actions workflows, JavaScript and Python** — not PHP. So the 15 describe August's
`main`, and **new code on `alpha` is never scanned** until promoted. Likely kinds (guesses from `main`'s
files): third-party actions pinned by a movable tag (`r0adkll/upload-google-play@v1`,
`shivammathur/setup-php@v2` — both still so on alpha); `apple.yml` with no top-level `permissions:`; the
backport workflow's `pull_request_target` trigger; JavaScript warnings in August's `appWeb/public_html/js/`
(some likely fixed on alpha); five Python tool files. **Owner:** Security → Code scanning → `is:open`,
sort by rule; send the rule names and file paths (a screenshot is fine) and we can sort them into
"fixed on alpha", "real, fix" and "not applicable".

## Owner decisions

**Q1. Fix `main` directly now (Step 2), or wait for the promotion?** *Blocks Step 2 only.*
Why: `main` is the owner's release branch, and it is the only branch GitHub counts.
A — lock-file PR to `main` now: all 19 close in minutes; one bypass merge; replaced harmlessly at promotion.
B — wait: 18 close at promotion (19 with Step 1); the tab stays at 34 until an unknown date.
C — dismiss as dev-only: hides them, fixes nothing, a reason per alert.
**Recommend A** — tiny, tested on a copy, and it stops a false alarm. Need back: "A, B or C"; if A and a
working branch already exists then, also "OK for a second branch for `main`" (standing-directives §2),
otherwise we wait for that branch to merge first.

**Q2. The two stale required checks on `main`.** *Does not block (you can bypass).*
Why: they make every PR into `main` — promotions too — need a bypass. Options: remove them (the
"Non-Alpha" ruleset already requires Lint & Validate, PHP 8.4, PHP 8.5 on `main`), or leave them and keep
bypassing (which trains everyone to bypass). **Recommend remove** (settings; this session cannot edit
rulesets). Need back: "remove" or "leave".

**Q3. Future Dependabot security PRs against `main`.** *Does not block.*
Why: closing them unmerged leaves the alert open and makes Dependabot skip that release. Options: merge
lock-file-only ones into `main` when alpha already has the fix (close any "Manual backport needed" issue
as "already on alpha"); keep closing them and live with open alerts until each promotion; or promote more
often. **Recommend merge.** Need back: "merge", "close" or "promote more often".

**Q4. Scan `alpha` with CodeQL, and turn on secret scanning?** *Does not block.*
Why: CodeQL sees only `main`, three months behind; secret scanning and push protection are **off** on this
public repository (free to turn on). Options: CodeQL "advanced setup" with a workflow that also runs on
`alpha` pushes and PRs (more CI minutes, free on public repos), or leave it; secret scanning on, or off.
**Recommend yes to both** — I can draft the CodeQL workflow; the switches are owner settings.
Need back: "yes/no" for each.

## Also noticed

- Libraries loaded from a CDN or download script (Bootstrap, pdf.js, Tone.js, Swagger UI, SortableJS) are
  invisible to Dependabot; mPDF's `composer.lock` is seen and has no alerts.
- `protobufjs-cli` (latest 2.7.1) still uses the deprecated `glob` 8, so more minimatch 5 / brace-expansion
  2 alerts will keep coming; each is a one-line lock-file refresh like Step 1.

## Not checked

Code scanning contents (403); Dependabot's job logs; the live sites; PHP CI on the Step 2 lock file
(PHP jobs only use `protobufjs`, unchanged). Scratch evidence:
`/tmp/claude-0/-home-user-iHymns/806b8fb0-f7a7-5072-bcfc-c47550df4dfc/scratchpad/deps/`.
