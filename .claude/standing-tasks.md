# Standing tasks — keep everything consistent, always

> **Non-negotiable.** GitHub Issues are this project's **point of truth**. Code,
> documentation, the Wiki, Milestones, the Project board and the Claude `.claude/`
> docs must all stay consistent with reality so that when work is progressed the
> issue tracker (and everything around it) can be relied upon. These tasks run
> **after every substantive piece of work** — ideally before a commit is
> considered "done", and always before a session ends — so nothing drifts.
>
> Loaded as project policy via `.claude/CLAUDE.md` (§ "Standing consistency
> tasks"). Last updated: 2026-06-05.

## The after-work checklist

Run this whole list after any feature, fix, refactor, audit, or design decision.
"Done" means the work **and** its paper-trail are consistent.

### 1. Code annotations (every new/changed file)
Annotate to the project standard (see [§ Annotation standard](#annotation-standard)
below): a **file/section doc-block** explaining purpose + how it fits, and
**inline line-by-line annotations** on non-obvious logic — both an **ELI5** plain
explanation and the **detailed** "why", with links to official documentation
where a reader would benefit (MDN, PHP manual, WCAG, the library's docs, the
relevant `#issue`). New code is annotated as it is written; touched code is
brought up to standard opportunistically. A retroactive backfill of legacy files
is tracked as its own program (see the open "code-annotation backfill" issue) —
never mass-rewrite comments across the whole tree in one unreviewed sweep.

### 2. GitHub Issues — the point of truth
- Every user-reported bug / feature / decision has a tracking issue **before** the
  commit that addresses it (so the timeline reads sensibly).

#### 2a. EVERY identified task gets an issue — including ones found mid-investigation

**File the issue at the moment of discovery, not at the end of the work.** If an
investigation turns up five things worth doing, that is five issues, not one
paragraph in a report and a hope that somebody remembers.

- **Findings from an audit/analysis become issues immediately** — one per
  actionable item, each with the `file:line` evidence that established it. A
  finding recorded only in a session report, a handoff, or a chat reply is
  effectively lost: the next session reads the tracker, not the transcript.
- **Group related findings under an Epic** (a parent issue that states the goal
  and the ordering constraint), with one child issue per unit of work. The Epic
  carries the "why now" and the sequencing; the children carry the doing.
  Worked example: **#1601** (make the v2 editor the default) with children
  **#1606–#1610**, each a single verified parity gap.
- **Work already done without an issue → file it retrospectively.** State plainly
  that it is retrospective and name the commit. #1602 is the pattern: the finding
  (three importer suites were never referenced by CI at all) mattered more than
  the fix, and would otherwise have survived only in a commit message.
- **A verification task is still a task.** "Confirm X replaces Y and does not
  re-materialise the corpus" is a legitimate issue (#1610) — the answer might be
  "already fine, close it", and knowing that is worth the issue.
- **Do not file an issue per symptom when one cause explains them all**, and do
  not file for a rename. Verify first: of 34 apparent v1→v2 API differences, 19
  were renames and 8 collapsed into one generic handler, leaving 6 real gaps.
  Filing 34 issues would have been worse than filing none.
- Unactioned suggestions and owner decisions → `for consideration`.
- Update issues to reflect reality: comment what landed (with the **commit SHA**),
  tick checklists, close completed issues **with evidence**, reopen ones that
  regressed, and split/relabel as scope changes.
- Unactioned suggestions → `for consideration`-labelled issues (global rule).
- Keep schema/state issues accurate at all times so state is reconstructable if
  local work is lost.

### 3. GitHub Milestones + Project board
- Assign new issues to the right Milestone; move closed ones out of "in progress".
- Keep the Project board columns reflecting actual status.
- If a milestone's scope changed, update its description.

### 4. GitHub Wiki (`iHymns.wiki/`)
- Update the affected Wiki page(s) when behaviour, architecture, API, schema,
  setup or deployment changes (e.g. `API-Reference.md`, `Architecture.md`,
  `Database-&-Migrations.md`, `Getting-Started.md`).
- The Wiki is a separate nested clone — commit + push it on its own.

### 5. Markdown docs (repo)
- `README.md` (version badge, features, structure, links), `CHANGELOG.md`
  (per `appWeb/CHANGELOG.md` + the platform changelogs), `DEV_NOTES.md`,
  `PROJECT_STATUS.md`, `Project_Plan.md`.
- `SECURITY.md` (disclosure policy + security model) and `LICENSING.md`
  (proprietary licence + third-party dependency licences) when security posture
  or dependencies change.

### 6. Claude `.claude/` docs
- **Memory** — per-user auto-memory (`~/.claude/projects/.../memory/`): add/refresh
  the session-handoff memory + `MEMORY.md` pointer; supersede stale resume points.
- **Context** — `.claude/ProjectBrief.md` (current state) + `.claude/CLAUDE.md`
  (rules/conventions): add a dated continuation note; codify any new convention.
- **History** — `.claude/sessions/<date>-HANDOFF.md`: what landed (commit SHAs),
  issues touched, next steps, standing constraints.
- `.claude/project-rules.md` for permanent expansions of conventions.

## Annotation standard

Comments serve **maintenance, learning, and debugging** — write for a future
maintainer who is smart but new to this file.

- **File/section doc-block** — what this file/class does, where it sits in the
  architecture, the load-bearing decisions, and the `#issue`(s) that shaped it.
- **Inline, two registers** where logic isn't self-evident:
  - **ELI5** — a plain-language sentence: *what* this does and *why it matters*.
  - **Detailed** — the precise "why": the edge case, the gotcha, the spec rule,
    the perf/security reason, the historical regression it prevents.
- **Link official docs** when they help: MDN (web APIs/CSS/JS), the PHP manual,
  WCAG success criteria, the library's own docs, OWASP, the IETF RFC, etc.
- **Match the surrounding style** — comment density and idiom should fit the file.
- **Don't narrate the obvious** (`$i++; // increment i`) — annotate intent, edges,
  and "why", not syntax. Over-commenting is as harmful as under-commenting.

## When this runs
- **Per piece of work** — items 1–2 minimum before a commit is "done".
- **Before a PR** — the full list, plus the pre-PR security review.
- **End of session** — the full list, so the next session resumes from truth.

If any item can't be completed (e.g. Wiki push needs network, a milestone needs
an owner decision), **say so explicitly** in the session handoff and leave a
tracked task — never silently skip and never claim it was done.
