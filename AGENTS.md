# Working agreements for this repository

This file is read by coding assistants that look for `AGENTS.md` (Codex among them).
Claude Code reads `.claude/CLAUDE.md`, which carries the same instruction plus the
project's detailed architecture rules. **If you are Claude Code, read `.claude/CLAUDE.md`
as well — it is the fuller document and it governs.**

## Write in plain, everyday English

Write the way you would explain something to a capable colleague who does not
work on this particular system. This applies to everything: chat replies, code
comments, commit messages, pull request text, issue text, documentation, and
anything shown to a user.

**What this means in practice**

- Use ordinary words. Say "a number that only ever counts upward and never
  resets", not "a monotonically increasing counter". Say "the app checks who you
  are before letting you in", not "the middleware performs principal
  authentication".
- When a technical term is genuinely needed — a file name, a function name, a
  standard like WCAG — use it, then say in ordinary words what it means and why
  it matters.
- Using more words is fine, and better, if it makes the meaning clearer. Do not
  compress an explanation into jargon to save space.
- Prefer short sentences. Break a long one into two.
- Explain the "why", not just the "what". "This runs after the save, because
  before the save the line does not have an identity number yet" is far more
  useful than "ordering constraint".
- Avoid unexplained abbreviations and internal shorthand on first use.
- Avoid filler that sounds impressive and says nothing.

**This does not lower the standard of the work.** The code, the analysis and the
precision stay exactly as rigorous. Only the way it is explained changes.

**When reporting on work done**, be direct about what is finished, what is not,
what was not checked, and what went wrong. Say "I could not test this because
there is no database on this machine" rather than implying it was verified.

## Before you change anything here

Read `.claude/CLAUDE.md`. It contains fifty-plus numbered rules describing how this
codebase is meant to fit together, and the specific mistakes it has made before. The most
important recurring one, worth knowing up front:

> **Per-line song data is kept with a line by its identity number, never by its position.**
> If you try to carry a note, a chord or a singing part across an edit by remembering "it
> was the third line", it will end up attached to the wrong words as soon as somebody adds
> or moves a line. The save succeeds, the result looks perfectly normal, and nobody ever
> finds it. Wrong information is worse than missing information. If the identity is gone,
> let the data go, keep a copy so it can be recovered, and flag anything that depended on
> it.
