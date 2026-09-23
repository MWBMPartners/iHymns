# iHymns — Standing Operating Directives

Owner-stated (2026-08-18). **Project-wide and platform-agnostic** — these apply to every
AI tool working on this repo (Claude Code, Codex or any other; widened 2026-09-23), for all users, whatever platform (Web/PWA, Apple, Android/FireOS)
is being worked on. They sit alongside `CLAUDE.md` (the rules), `.claude/standing-tasks.md`
(the consistency checklist) and `.claude/project-rules.md` (the detailed expansion, incl. §17
model-tier selection which this section refines).

---

## 1. Model routing and how to think (GIRFT — Get It Right First Time)

*Revised 2026‑09‑23 (owner): deep analysis and planning now use **Opus**, not Fable. The reason
the owner gave: the newest Opus (Opus 5.5) costs less than the newest Fable and does the job at
least as well.*

Pick the model to suit the work. Use tokens and usage credits carefully, but never at the cost of
getting it right.

- **Think hard before acting.** For anything beyond a trivial edit, think the work through in
  depth first. ("Ultrathink" is the owner's word for this. In Claude Code it asks the model to
  spend more effort reasoning.) Use **workflows** (the Workflow tool, several agents driven by one
  script) to help plan and do the work where that helps. The owner has given standing permission
  for this in this repo.
- **Deep analysis and deep planning → Opus agents, one after another** (in sequence, *not* several
  running at the same time). Each agent builds on the last one's result. If Opus is unavailable, use the
  next best available model for that run (Fable, for example), then go back to Opus next time.
- **Implementation → Sonnet or Haiku**, whichever fits: Haiku for mechanical, low‑thought work,
  Sonnet for ordinary implementation.
- **Complex implementation → Opus.**
- **The aim is GIRFT**: top‑quality, correct code the first time. Check it, prove the tests can
  actually fail (rule #34), and never call something "done" without checking it.

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

1. **Commit + push** it to the active working branch — the one branch that will later be merged
   into `alpha` (see §2). Keep each commit small, clearly described, and signed with the footer.
   If no working branch exists, create one first.
2. **Update its GitHub issue(s), one at a time, for each task.** Give the commit numbers (SHAs) and
   the evidence. Close, annotate or reopen as the real state requires. File follow‑ups the moment
   you find them.
3. **Update Claude's notes in `.claude/`** — memory (`MEMORY.md` and the per‑user auto‑memory) and context (`ProjectBrief.md`,
   `CLAUDE.md`) so they match reality.
4. **Update Codex's notes in `.OpenAI/`** — memory (`.OpenAI/MEMORY.md`) and context
   (`.OpenAI/CONTEXT.md`), plus `AGENTS.md` if a rule changed. *(Added 2026‑09‑23.)* Codex
   (OpenAI's coding tool) reads these, so it must not work from an older picture than Claude does.
   Do not copy the handoff into `.OpenAI/`. There is **one** handoff, in `.claude/sessions/`,
   and both tools read it. Two copies would drift apart (rule #35).
5. **Update the handoff document** (see §4).

## 4. Keep the Handoff live

Maintain `.claude/sessions/<date>-HANDOFF.md` **continuously as work progresses**, not just at
session end — so any session can pick up exactly where the last left off if interrupted. It
records: what's done (with SHAs), what's in flight, what's blocked/deferred and why, open owner
decisions, and the next steps in order.

Update it **up to the minute**, not in batches. From 2026‑09‑23 it also records **which AI tool is
doing the work** and any switch between tools (§14). A handoff that is an hour stale is what turns a
tool switch or a restarted session into lost work. The newest `…-HANDOFF.md` should open with a
"Current state" section, so a fresh session with no chat history can resume from that one file.

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

- **Swagger UI already exists**, so do not add a second one: `/manage/api-docs` (`manage/api-docs.php`)
  renders `api-docs.yaml` in Swagger UI (a web page for browsing the API). It loads from a pinned web
  address with a security checksum, and falls back to a copy stored in the repo under
  `/vendor/swagger-ui/`, so it runs on ordinary shared hosting with no Docker. Checked 2026‑09‑23.
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

## 8. Use the dev‑team plugins, and have a second AI check the work

- Use the installed **dev‑team plugins** wherever they help: for the work itself, and for
  suggesting fixes, tweaks, improvements and new features.
- **Use a different AI system to check the work.** If Claude Code planned and built it, Codex
  reviews it. If Codex built it, Claude reviews it. Two different systems miss different things.
  The dev‑team plugins are one way to hand work to the other system. The review loop itself is in
  §13.

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
(§10 ask‑clarifications‑up‑front). Amended 2026-09-23: §1 Opus replaces Fable for deep work, plus
"think hard" and workflows; §3 adds Codex's `.OpenAI/` notes; §6 records the existing Swagger UI;
§8 adds cross‑AI checking; §11 records the fifth ask; new §12 progress tables, §13 the Codex review
loop, §14 AI fallback. Update this file (don't fork it) if the owner amends any directive.*

## 11. Plain, everyday English — in every reply and every written artefact (owner‑stated 2026‑08‑29, restated 2026‑09‑05, restated again 2026‑09‑07, 2026‑09‑08 and 2026‑09‑23)

**Write the way you would explain something to a capable colleague who does not work on this
particular system.** This is a standing rule, not a style preference, and it applies to *every*
assistant working here — Claude Code, Codex, Claude in the browser, ChatGPT — and to *every* kind of
output: chat replies, progress reports, code comments, commit messages, pull‑request text, issue
text, documentation, and anything a user will ever see.

**Asked for a FIFTH time on 2026-09-23** ("do not use technical jargon — it can confuse even some
technically skilled users and developers"). Before that: 2026-09-08, 2026-09-07, 2026-09-05 and
2026-08-29. The 2026-09-23 wording adds one thing worth keeping: **this covers people who ARE
technical too.** Jargon is not a courtesy to experts, so do not keep it "because the reader is a
developer".

Four asks means writing it down again is not the answer. It is already recorded in six places, and
it was recorded in all six BEFORE this fourth ask. **Another copy would be exactly the mistake
rule #35 warns about — a written statement is not a mechanism.**

So it is worth being precise about what actually goes wrong, because it is not ignorance of the rule:

- **The pull is strongest under a long task list.** Jargon is shorter, and under pressure the shortest
  phrasing wins. It also *sounds* more precise, which makes it feel like the safer choice. It is not:
  a sentence the reader has to decode is a sentence that can be misunderstood.
- **It slips most in progress reports and issue text**, not in code comments — because those are
  written fastest and feel like notes rather than deliverables. They are not; the owner reads them.
- **Naming a thing is not explaining it.** Writing "the SSRF guard" and moving on fails the rule even
  though every word is accurate. The test is: would somebody who has never opened this codebase know
  what was just said?

**The check to run before sending anything:** pick the two or three densest sentences and ask whether
a capable colleague outside this project would follow them. If not, rewrite those two or three. Not
the whole thing — just the ones carrying the weight.

**What it means in practice**

- Use ordinary words. Say "a number that only ever counts upward and never resets" rather than "a
  monotonically increasing counter". Say "the app checks who you are before letting you in" rather
  than "the middleware performs principal authentication".
- When a technical term is genuinely needed — a file name, a function name, a standard such as WCAG —
  use it, then say in ordinary words what it means and why it matters.
- **Using more words is fine, and better, if it makes the meaning clearer.** Never compress an
  explanation into jargon to save space.
- Prefer short sentences. Break a long one into two.
- Explain the *why*, not just the *what*.
- Avoid unexplained abbreviations, internal shorthand on first use, and filler that sounds impressive
  and says nothing.

**This does not lower the standard of the work.** The code, the analysis and the precision stay
exactly as rigorous. Only the way it is explained changes.

**When reporting on work done**, be direct about what is finished, what is not, what was not checked,
and what went wrong. Say "I could not test this because there is no database on this machine" rather
than implying it was verified.

**Where this rule is recorded** (keep all of these in step — rule #35: agreement between files needs a
mechanism, and until there is one, at least keep the list of places short and named):

| File | Who reads it |
| --- | --- |
| `.claude/CLAUDE.md` (top section) | Claude Code, on every session start in this repo |
| `AGENTS.md` (repo root) | Codex and other tools that read `AGENTS.md` |
| `.claude/project-rules.md` §22 | the detailed expansion |
| this file, §11 | the session‑start directive read |
| `~/.claude/CLAUDE.md` (not in the repo) | Claude Code, on this computer, in every project |
| `~/.codex/AGENTS.md` (not in the repo) | Codex, on this computer, in every project |

The last two are now installed from `.claude/device-level-rules.md` by
`tools/install-device-rules.sh` (added 2026‑09‑23). Edit the text there and re‑run the script,
rather than editing the home‑folder files by hand.

## 12. Progress updates as a table (owner‑stated 2026‑09‑23)

Give the owner **frequent** progress updates. Each one lists the queued tasks **in a table**, with
the state of each one: done, in progress, queued, or waiting on the owner (say for what). Send one
when the work starts, whenever a task changes state, and at the end. Keep the task names in plain
words. The table replaces long paragraphs of narration; it does not replace saying plainly what went
wrong.

## 13. Every change is reviewed by a different AI tool (normally Codex), repeated until it comes back clean (owner‑stated 2026‑09‑23)

All code goes through this loop before it counts as finished. The reviewer is a different AI tool
from the one that wrote the code. Normally Claude Code writes and Codex reviews. If Codex wrote it,
Claude reviews it (§8).

1. **Codex reviews the change** (for example `codex review`, or through the dev‑team plugins).
2. **Fix what it finds.** Where a fix is clear, make it automatically. Where a finding is wrong,
   write down why instead of making the change.
3. **Run Codex again on the updated code.** Repeat steps 2 and 3 until a review finds **no issues**.

Things to watch:

- **An empty review is not a clean review.** A Codex that has hit its usage limit can print nothing
  at all. Check its error output, and check the review actually says something, before treating it
  as a pass (trap #8 in the 2026‑09‑14 handoff).
- **If Codex is not available** (not installed on this machine, or out of credit), use §14. A fresh
  reviewer with no memory of how the code was built reviews it instead. Say so in the commit, and
  file a "catch‑up Codex review" issue so the real review still happens once Codex is back (#2123
  is the worked example).
- The loop is in addition to this repo's own automated checks (lint, tests, the CI guards), not
  instead of them.

## 14. When an AI service is unavailable, hand over, then switch back (owner‑stated 2026‑09‑23)

This applies to **any** AI tool or its agents — Claude Code, Codex, or anything else. It is written
this way on purpose, so it does not need updating when the tools change.

- **If the main AI service for this project stops working** (it is down, out of tokens or usage
  credit, or at a usage limit), you may hand the work to another suitable AI tool **if** that can be
  done without losing context or progress. The handoff document (§4) is what makes that possible.
  That is why it must be kept up to the minute.
- **Switch back to the main service often**, as soon as it is available again. Do not stay on the
  fallback just because it is working.
- **When the main service is back, run a FULL review** of everything done on the fallback. The
  cross‑AI reviews in §13 catch most differences in approach between tools, but a full review by the
  main service is still owed.
- **Record every switch in the handoff:** which tool took over, when, what it did, and whether the
  full review on return has happened yet.
- For this project the main service is **Claude Code**, and **Codex** is the usual reviewer and first
  fallback.

This rule also belongs in the **computer‑wide** settings, so it applies in every project, not just
this one. `.claude/device-level-rules.md` holds that text, and `tools/install-device-rules.sh` adds it
to `~/.claude/CLAUDE.md` and `~/.codex/AGENTS.md` on the computer where you run it.
