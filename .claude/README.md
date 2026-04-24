# .claude/

Context + rules for anyone — human or Claude — working in this repo.

## What's in here

| File | Purpose | Loaded automatically? |
|---|---|---|
| **`CLAUDE.md`** | Project memory: modularity rule + top-level guardrails. Claude Code auto-loads this at session start. | ✅ Yes |
| **`project-rules.md`** | Long-form expansion of the rules (naming, auth, errors, a11y, perf, workflow, anti-patterns). Cite in PR reviews. | Linked from `CLAUDE.md` |
| **`ProjectBrief.md`** | Current state snapshot: version, phase, tech stack, schema summary. | Linked from `CLAUDE.md` |
| **`ProjectOverview.md`** | Original scoping doc from the start of the project. | Linked from `CLAUDE.md` |
| **`projects/`** | Historical per-project notes. | No — reference only |

## What is NOT in here (intentionally)

- **Claude Code session transcripts** (`~/.claude/projects/<hash>/*.jsonl`) live in the user's home directory, not the repo. They can contain API keys or debugging payloads — committing them would be a security risk.
- **Per-user global memory** (`~/.claude/CLAUDE.md`) — that's user-specific, not project policy.
- **Custom slash commands / agents** — add these under `.claude/commands/` or `.claude/agents/` if the team agrees a command is worth sharing across sessions. Currently none defined.

## How to extend

- **New permanent rule** → append to `project-rules.md`, and if it's a red-flag check, also surface it in `CLAUDE.md`.
- **Shipping a new shared module** → list it in the table under §1.1 of `project-rules.md` so future work knows where to find it.
- **Recurring anti-pattern** → add to §9 of `project-rules.md` with the concrete example.

If something was important enough to discover the hard way, it's important enough to write down here.
