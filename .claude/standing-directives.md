# iHymns — Standing Operating Directives

Owner-stated (2026-08-18). **Project-wide and platform-agnostic** — these apply to every
Claude session on this repo, for all users, whatever platform (Web/PWA, Apple, Android/FireOS)
is being worked on. They sit alongside `CLAUDE.md` (the rules), `.claude/standing-tasks.md`
(the consistency checklist) and `.claude/project-rules.md` (the detailed expansion, incl. §17
model-tier selection which this section refines).

---

## 1. Model routing (GIRFT — Get It Right First Time)

Match the model to the work; spend tokens/credits efficiently but never at the cost of
correctness or quality.

- **Deep analysis & deep planning → sequential Fable‑5 agents** (one at a time, *not* a
  parallel fan‑out). If Fable‑5 is unavailable, fall back to **Opus** for that run, then
  **retry Fable‑5** on the next deep‑analysis/planning run (don't stay on the fallback).
- **Implementation → Sonnet or Haiku**, whichever fits the task (Haiku for mechanical/low‑
  reasoning work, Sonnet for standard implementation).
- **Complex implementation → Opus.**
- The philosophy is **GIRFT**: top‑quality, correct code the first time — verified, mutation‑
  tested (rule #34), no unverified "done".

## 2. One branch — no PR stacking

- All work lands on the **single active working branch** that will eventually target `alpha`
  via **one** PR created later.
- **Do not create multiple/stacked PRs** — they cause merge‑race conditions. Commit every
  piece of work to that one branch; the PR to alpha is created once, later.

### When may a new branch be created? (owner‑clarified 2026‑09‑04)

The rule is about avoiding a **second** working branch, not about branch creation as such.

- A **working branch** is any branch that is not one of the long‑lived channel branches:
  not `alpha`, not `beta`, not a release‑candidate branch, not `main`.
- **If a working branch already exists → do not create another one.** Commit to the existing
  one. A second working branch is what produces the merge races this section exists to
  prevent, and it needs explicit owner permission.
- **If no working branch exists → create one and get on with it.** No need to ask. Name it
  for the work (`fix/…`, `feat/…`, `chore/…`), branch it from `alpha`, and open the single PR
  to `alpha` when the work is done.

Check before assuming, because the answer changes as branches are merged and deleted:

```sh
git ls-remote --heads origin \
  | sed 's|.*refs/heads/||' \
  | grep -vE '^(alpha|beta|main|release-.*|rc/.*|archive/.*)$'
```

Empty output = no working branch = create one. Any output = commit to that branch instead.

The `archive/` exclusion is load‑bearing and was found by running the command rather than
trusting it: `archive/alpha` is a parked copy of a channel branch, not somebody's work in
progress, and without that term the check reports a working branch exists when none does —
which would send the next session to commit onto an archive. Run the command and read its
output before acting on it; do not assume a filter list is complete because it looks right.

⚠️ **Do not hard‑code the current branch name into this file.** It used to name
`claude/ilyrics-identity-work-model`, which was merged and deleted; a later session found the
named branch gone, no replacement named, and the "never create a new branch" line still
standing — so it had a rule it could not follow either way and had to stop and ask. A branch
name is a fact that goes stale silently, which is precisely the class of thing this project
puts behind a check rather than a written‑down value (rule #35: cross‑file agreement needs a
mechanism, not a note). Derive the answer from the remote with the command above.

## 3. After every task (per‑task close‑out)

The moment a piece of work is complete:

1. **Commit + push** it to the active working branch (atomic, well‑described, footer‑signed).
   If none exists, create one first — see §2.
2. **Update its GitHub issue(s) individually** — SHAs + evidence; close/annotate/reopen as the
   real state requires; file follow‑ups at the moment of discovery.
3. **Update Claude `.claude/`** — Memory (`MEMORY.md`/auto‑memory) + Context (`ProjectBrief.md`,
   `CLAUDE.md`) so they reflect reality.
4. **Update the Handoff document** (see §4).

## 4. Keep the Handoff live

Maintain `.claude/sessions/<date>-HANDOFF.md` **continuously as work progresses**, not just at
session end — so any session can pick up exactly where the last left off if interrupted. It
records: what's done (with SHAs), what's in flight, what's blocked/deferred and why, open owner
decisions, and the next steps in order.

## 5. Autonomy

- Work through **all** queued tasks **autonomously**. Do **not** halt or pause unless you need
  an **explicit decision/approval** that only the owner can give.
- When you must pause, state — in the simplest possible wording — **what** you need and **why**,
  using the asking‑owner shape (`CLAUDE.md` → "Asking the owner for a decision"). Then **keep
  going autonomously on every other queued task** while that one item waits; never idle the
  whole queue on a single decision.
- Prefer a defensible default + flag it (rule: pick the default, say so, mark it trivially
  changeable) over stalling, for non‑blocking sub‑questions.

## 6. Thorough documentation discipline

Keep documentation thorough and current as part of the work (not a someday backlog):

- **All `.md` docs** — `README.md`, `CHANGELOG.md`, `DEV_NOTES.md`, `PROJECT_STATUS.md`,
  `SECURITY.md`, `LICENSING.md`, and any others.
- **In‑app help / guides** — the user‑facing help content (`help/`, `help.php`, wiki mirrors).
- **API docs** — if the project exposes an API, keep the **OpenAPI/Swagger** spec
  (`api-docs.yaml`) accurate. If the project has web components and no browsable Swagger UI is
  bundled, add one **prepared to run on shared hosting (no Docker)** for hosting servers.
- **Claude `.claude/`** — Memory, Context, and this directory.
- Ground every doc in the **actual code** — never infer state from commit titles or other docs.

## 7. GitHub issues reflect reality (codebase‑grounded)

The issue tracker is the point of truth and must match the **actual codebase**. When
reconciling issues (open *and* closed), verify against real code — **no assumptions** from
docs, commit messages, or prior issue text. Verify before filing/closing.

## 8. Use the dev‑team plugins

Use the functionality provided by the installed **dev‑team plugins** where it helps — for the
work itself, and for managing suggestions/enhancements/new‑feature proposals.

## 9. Efficient / smart processing

Freely **adjust the order** of queued tasks and **bundle** related ones to process efficiently,
as long as correctness, the per‑task close‑out (§3), and the autonomy rules (§5) are honoured.

## 10. Ask every clarification UP FRONT (owner‑stated 2026‑08‑24)

When a task needs clarification that only the owner can give, ask **all** the questions you can
foresee **at the very start, batched together** — before beginning the work — **not** one at a time
as each becomes relevant mid‑flow.

- **Front‑load the ambiguities.** Read the *whole* task first, identify every decision and unknown
  it contains, and surface them in a **single** ask (using the asking‑owner shape — `CLAUDE.md` →
  "Asking the owner for a decision"). The owner's own precedent is *"ask any questions now, not when
  required, then proceed."*
- **Do not drip‑feed.** Never pause the work partway through for a question you could have foreseen
  and asked at the outset — that turns one review into many and stalls the queue repeatedly.
- **The narrow exception:** for something that genuinely could not have been foreseen until you were
  deep in the work, still prefer a **defensible default + flag it** (§5) over stalling; only escalate
  mid‑task when the default is truly not defensible.

This composes with §5 (autonomy): front‑loaded questions let the owner answer everything once, then
you run autonomously to completion.

---

*Change log: created 2026-08-18 from the owner's standing‑instructions message. Amended 2026-08-24
(§10 ask‑clarifications‑up‑front). Update this file (don't fork it) if the owner amends any
directive.*
