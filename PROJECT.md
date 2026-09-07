# PROJECT.md — hardening after twelve copyrighted files were found tracked in public

This is the external memory for this dev-team-orchestrator run. Read this file cold and you
should know exactly where things stand — no need to read the chat transcript.

Branch: `fix/untrack-temp-copyrighted-samples` (based on `alpha`). **Nobody but the lead commits.**
Everyone else stages changes and hands back; the lead commits at checkpoints.

## Current status

**Stage 1 (untrack + guard) — in flight, partially done.**

| Piece | State |
|---|---|
| Twelve tracked files removed from git's index | **Done, staged.** `git rm --cached` has run; `git status` shows all twelve as `deleted:` and staged. |
| The twelve files themselves | **Still on the owner's disk**, in `_temp/_ProPresenter Sample Files/` — this was a cached-only removal, not a delete. Confirmed present. |
| `.gitignore` recursive rule | **Written, not yet staged.** Working tree has `_temp/*` + `_temp/_ProPresenter Sample Files/*` replaced by one recursive `_temp/` rule, with a comment explaining why the old pair missed sub-folders and why a rule here does not untrack an already-tracked file. Also added the "files kept on purpose despite a broader ignore rule" section (`.gitkeep`/`.htaccess` re-opens for `data_share/*`, `SQLite/`, and the vendored mPDF `tmp/`/`random_compat/dist/` folders) — this second block was needed because building the guard surfaced them, see below. |
| `tests/test-no-tracked-scratch.js` (the guard) | **Not yet created.** This is next. |
| Stray mutation-test leftover | **Cleared.** A `_temp/mutationtest/fake.pro` had been staged as a new file — someone testing "does the future guard catch a re-added file under `_temp/`" before the guard existed. It is gone as of the last check (removed, not committed). If it reappears, it must NOT be committed — it is a live instance of the exact bug this branch fixes, just with dummy content instead of real lyrics. |

**What "done" for Stage 1 looks like:** the twelve deletions + the `.gitignore` rewrite staged and committed together, plus a new `tests/test-no-tracked-scratch.js` that is tree-derived (walks the actual `.gitignore` rules and the actual tracked-file list, not a typed path list) and mutation-proven (add a file under `_temp/`, force-add it, confirm the guard goes red; remove it, confirm green).

**Stage 2 (verification hardening)** — a three-pass Fable analysis was reported as "now running" when this run began: can the test suites lie about being green, what else is fragile, and in what order to fix it. No output from that analysis has landed in this repo yet as of this check — resume by asking whoever ran it for the result, or re-run it if it did not survive.

**Stage 3 (Codex cross-review, then merge)** — not started. Do not open a PR or merge before this.

## Open decision for the owner — nothing else should proceed past this without it being current

Untracking (Stage 1) stops the files being handed out **going forward**. It does **not** remove
them from the public history — anyone can still fetch commits `fac03cc5` / `2642f284` (27 Aug 2026)
directly, and GitHub's own caches may hold them regardless of what the branch tip shows.

Options put to the owner:

1. **Rewrite history and force-push.** Genuinely removes the files from every reachable commit.
   Cost: every commit hash from 27 Aug onward changes, and anyone with an existing clone must
   re-clone or their history diverges unrecoverably.
2. **Untrack only** (where things stand right now if this branch merges as-is). Cheapest, but the
   copyrighted lyrics remain fetchable from history indefinitely.
3. **Make the repository private.** Removes public exposure without rewriting history, but changes
   who can see the *whole* repo, not just these files — a bigger decision than it looks.

The lead's recommendation is **1 or 3**, and the lead will not force-push without an explicit
instruction to do so. **This has not been answered yet.** Whoever resumes this run should raise it
again before merging Stage 1, not just note it and move on — merging without an answer quietly
picks option 2 by default.

## Decision log

| When | Decision | By |
|---|---|---|
| (branch start) | Untrack the twelve files from git's index but keep them on the owner's disk, rather than deleting them outright | lead, uncontested — files are the owner's own ProPresenter exports and are needed locally for testing |
| (branch start) | Recommend history rewrite or making the repo private over "untrack only" | lead, **awaiting owner reply** |
| (branch start) | Guard test must be tree-derived and mutation-proven per rule #34, not a typed list of paths | standing project rule, not a new call |

## Why this happened — the whole point of the exercise

The project had already decided, in writing, three separate times that these files must never be
committed:

- `tools/pp7-sanitise-fixture.js` says the originals "live in `_temp/` on alpha and are NOT
  committed".
- `tests/fixtures/propresenter/README.md` has a section headed "Deliberately NOT committed" that
  names these exact files and the copyright reason (one of them is "Oceans (Where Feet May Fail)"
  © 2012 Hillsong, CCLI 6428767).
- `.gitignore` carried `_temp/*` rules the entire time.

None of that stopped it, because **a `.gitignore` rule does not untrack a file git is already
following.** The twelve files were committed in `fac03cc5` / `2642f284` (27 Aug 2026) — before the
ignore rules existed — and simply stayed tracked afterwards. Every `git status` for months would
have shown them as ordinary tracked files; nobody read that as a signal, because there was no
reason to expect a problem once the ignore rule was "in place".

This is this codebase's own rule #35 in miniature (`.claude/CLAUDE.md`): **when two things must
agree, a comment or a written intention is not a mechanism.** The ignore rule *said* the right
thing. It did not *enforce* it. `tests/test-no-tracked-scratch.js` is the enforcement — Stage 1
is not finished until that exists and has been proven able to fail.

## The second thread — a genuine test failure that local checks missed

A pull request (`#2093`, "four forensics presets for the SQL console") failed four CI checks on
one PHP test (`test-lyrics-version-resolver.php`) that a local full-suite run had reported clean.

The cause: `tools/run-php-tests.php` reports each failing file as its own line —
`❌ <file>.php (exit 1)` — and finishes with `❌ Failed: <n>`. The person running the suite locally
was instead grepping the output for the word `FAIL` underneath a `--- file ---` header. That wrong
pattern happened to agree with the real answer for six long-standing, environment-only failures
(see below), so it looked trustworthy — right up until it silently missed a seventh, genuine one.

This is the exact same failure shape as the `.gitignore` story above: **a check that prints green
while the thing it names is broken, trusted because it has always agreed before.** It is recorded
in `.claude/sessions/2026-09-04-HANDOFF.md` under "HOW TO READ THE PHP TEST RUNNER" (added in commit
`b34089a0`, the branch's parent commit).

**Also standing, and treated as an accepted "baseline" rather than fixed:** six PHP tests fail
permanently in the local Docker container used on this machine (they need a text/ICU library and
more memory than the container has — `test-ccli-resolver`, `test-ia-reconcile-guards`,
`test-musician-name-similarity`, `test-orphan-inventory`, `test-search-fold`,
`test-song-similarity`). A test that is always red gets ignored by habit, which is exactly how the
real seventh failure above got missed for as long as it did. Whether to fix the container, or add a
mechanism (not a comment) that distinguishes "known-red for an environment reason" from "newly red",
is in scope for Stage 2 and has not been decided yet.

## Definition of done for this whole run

- No gitignored file is tracked, anywhere in the tree.
- A tree-derived, mutation-proven guard (`tests/test-no-tracked-scratch.js`) fails the build if
  that recurs.
- The local test-running instructions/scripts cannot report green while a real test is red — the
  local check must read the runner's actual `❌` lines, not a hand-rolled pattern that happens to
  agree today.
- Codex has cross-reviewed the change before it merges.
- CI green, merged to `alpha`.
- The open owner decision above has been answered (or explicitly deferred by the owner, not by
  the agent).

## House references every agent on this run must follow

- `.claude/CLAUDE.md` rule #34 — a guard must be **derived from the tree**, never a typed list, and
  must be **proven able to fail** (break the thing on purpose, watch it go red, then fix it).
- `.claude/CLAUDE.md` rule #35 — cross-file/cross-tool agreement needs a **mechanism**, not a
  comment saying "keep these in sync".
- `.claude/standing-directives.md` §2 — one working branch at a time; this run uses
  `fix/untrack-temp-copyrighted-samples` and nobody should start a second one for this work.
- `.claude/standing-tasks.md` §2a — every actionable finding gets its own GitHub issue at the
  moment it is found. **Neither the tracked-files incident nor the local-test-runner mismatch has
  a GitHub issue yet as of this check** (searched; none found). Filing those two issues — with the
  commit SHAs `fac03cc5`/`2642f284` and PR `#2093` as evidence — is outstanding and should happen
  before this run closes out, per that standing rule.
- Test commands in use:
  - `docker run --rm -m 2g -v "$PWD":/app -w /app ihymns-php:8.3 php -d memory_limit=1G tools/run-php-tests.php` — **read the `❌ <file> (exit 1)` lines, not surrounding prose.**
  - `node tools/run-node-tests.js`
  - `npx eslint appWeb/public_html/js appWeb/public_html/manage`
- No local PHP or MySQL on this machine. Some node tests shell out to a `php` binary; that needs a
  shim routing to the Docker image (recipe in `.claude/sessions/2026-09-04-HANDOFF.md`, near the
  end). **The scratchpad holding that shim does not survive between sessions** — recreate it before
  trusting a "0 node failures" result.

## Resume checklist

1. `git status` — expect the twelve staged deletions and a modified `.gitignore`, nothing committed.
2. Check whether `_temp/mutationtest/fake.pro` (or anything else new under `_temp/`) is staged. If
   so, unstage/remove it before anything gets committed — see the table above.
3. Write `tests/test-no-tracked-scratch.js`: tree-derive the ignore rules and the tracked-file list,
   fail if anything under `_temp/` is tracked, prove it can go red by a real mutation test.
4. Hand the staged untrack + `.gitignore` + new guard to the lead for a checkpoint commit.
5. Re-raise the open owner decision (history rewrite vs. private repo vs. untrack-only) before
   merging — do not let Stage 1 land silently as option 2.
6. Move to Stage 2: resume or re-run the three-pass Fable verification-hardening analysis; decide
   what to do about the six permanently-red local PHP tests and the local-vs-CI reporting mismatch.
7. File the two outstanding GitHub issues (tracked-files incident, local-test-runner mismatch)
   before Stage 3.
8. Stage 3: Codex cross-review, then CI green, then merge to `alpha`.
