# .claude/sessions/

Scrubbed Claude Code session transcripts for this project. Committed so that starting a session on another dev device gives Claude continuity via `/resume`.

## ⚠️ Read this before committing

Session transcripts are the full log of a Claude Code session — every prompt, every tool call, every file Claude read, every command Claude ran, and every response. That means they legitimately contain whatever happened to be in context, including:

- The contents of any `.env`, `.htpasswd`, config file, or credential file Claude read.
- DB dumps, SQL results, query outputs with real data.
- API responses, including anything with a token in an `Authorization` header.
- Anything you pasted into the prompt.

The sync script (`tools/sync-claude-session.sh`) runs a **best-effort** secret-scrubber over each line before copying it here. It redacts common known token shapes (Anthropic keys, GitHub PATs, AWS access keys, Google API keys, generic `Bearer …`, private-key blocks). **It will not catch:**

- A database password typed as plain text in a prompt.
- A customer email in a test fixture Claude read.
- A production URL with a session ID in the query string.
- Any other secret whose shape doesn't match a known pattern.

### Workflow

```
tools/sync-claude-session.sh            # copy + scrub every transcript
git diff .claude/sessions/              # REVIEW THE DIFF
git add .claude/sessions/               # only after the review passes
git commit -m "chore(sessions): sync latest Claude transcripts"
```

If you find something sensitive in the diff, either:
- Edit the file by hand to redact it, OR
- Delete the file and don't commit it.

### Options

```
tools/sync-claude-session.sh --dry-run   # list what would be synced
tools/sync-claude-session.sh --latest    # only the newest transcript
```

## What lives here

- `*.jsonl` — one transcript per session, scrubbed. The filename matches the Claude Code session ID so `/resume` can find it.

## What doesn't live here

- Per-user global memory (`~/.claude/CLAUDE.md`) — that's user-level, not project policy.
- Custom slash commands / agents — those go in `.claude/commands/` or `.claude/agents/` when we agree to share them across the team.
- Secrets, credentials, real customer data, DB dumps — full stop.

## When in doubt

Don't commit. A rescinded token is ten minutes of admin; a leaked one in git history is a forever problem.
