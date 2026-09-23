# `.OpenAI/` — Codex's notes for this repository

This folder is for **Codex** (OpenAI's coding tool) and any other OpenAI-based assistant working on
iHymns. It is the Codex counterpart of Claude's `.claude/` folder, and was created on 2026-09-23 at
the owner's request.

| File | What it holds |
| --- | --- |
| `CONTEXT.md` | How to find your way around this project, and which files hold the rules |
| `MEMORY.md` | Short, lasting facts and pitfalls that are worth knowing before you start |

## What deliberately is NOT here

- **The handoff.** There is one handoff, in `.claude/sessions/<date>-HANDOFF.md`, and Claude and
  Codex both read and update it. A second copy here would drift out of step with the first.
- **The rules themselves.** Those live in `AGENTS.md` (repo root), `.claude/CLAUDE.md` and
  `.claude/standing-directives.md`. This folder points at them rather than repeating them, for the
  same reason.

## When to update it

After every task (see `.claude/standing-directives.md` §3, step 4): add anything you learned that the
next session should know to `MEMORY.md`, and fix anything in `CONTEXT.md` that has stopped being true.
