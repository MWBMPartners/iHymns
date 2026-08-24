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

- All work lands on the **single active feature branch** that will eventually target `alpha`
  via **one** PR created later (currently `claude/ilyrics-identity-work-model`).
- **Do not create multiple/stacked PRs** — they cause merge‑race conditions. Commit every
  piece of work to that one branch; the PR to alpha is created once, later.
- Never create a *new* branch without explicit owner permission.

## 3. After every task (per‑task close‑out)

The moment a piece of work is complete:

1. **Commit + push** it to the active feature branch (atomic, well‑described, footer‑signed).
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

---

*Change log: created 2026-08-18 from the owner's standing‑instructions message. Update this file
(don't fork it) if the owner amends any directive.*
