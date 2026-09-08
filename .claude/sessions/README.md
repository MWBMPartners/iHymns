# `.claude/sessions/` — what lives here and why

This folder holds two very different kinds of file. Only one of them is committed.

## 1. Handoff notes — `<date>-HANDOFF.md` — **committed, and the ones that matter**

A handoff note is written by hand at the end of a working session. It says what landed, what did
not, what was not checked, what is still open, and where to pick up. It is plain text, so a person,
an editor, or any assistant can read it on any computer.

**These are what actually carry work forward.** Ten of them together come to about 224 KB. Every
other `.claude/` document that mentions session history points at these, never at a raw log.

If you are finishing a session, write one of these. Follow the shape of the most recent one: a
"where things stand right now" table at the top, then what landed, then what is still open.

## 2. Raw conversation logs — `*.jsonl` — **not committed since 2026-09-07 (#2096)**

These are the complete machine-readable record of a conversation: every file read, every command
run, every reply. They are useful locally. They were committed for about five months, and it turns
out they could never have done the job they were kept for.

**Why they could not work.** Claude Code looks for a conversation in a folder named after **the full
path of the project on that particular machine**. For this repository on this computer that is:

```
~/.claude/projects/-Users-lance-manasse-Projects-Coding---Development-MWBM-Partners-Ltd-GitHub-iHymns
```

The name is the absolute path with every character that is not a letter or a number replaced by a
dash. So on a different computer, a different user account, or the same computer with the project in
a different folder, the name is different and Claude Code looks somewhere else entirely. A log
sitting inside the repository is, by definition, in the wrong place.

On top of that, `tools/sync-claude-session.sh` only ever copied **one way** — into the repository,
never back out. There was no script that could put one where it needed to go.

The result: twelve logs, **162 MB**, carried in every clone, read by nothing, ever.

### What to do instead

**To carry the work across** — which is nearly always what you actually want — write a handoff note.

**To carry an actual conversation across**, the script is now two-way:

```bash
tools/sync-claude-session.sh --where            # where does Claude Code look on this machine?
tools/sync-claude-session.sh                    # take scrubbed copies of the live logs
# copy the .jsonl to the other machine however you like — USB, file transfer, cloud drive
tools/sync-claude-session.sh --restore FILE     # run this THERE; puts it where it will be found
```

`--restore` works out the right folder from the repository's own location, so it is correct on any
machine without being told anything. It refuses to overwrite a conversation that is already there.

Be aware that a very long conversation is summarised rather than replayed in full when it is
reopened, so a handoff note is often the better tool even when you have the log.

## A word about secrets

The export step runs each log through a scrubber that redacts things **matching a known pattern**:
Anthropic keys, GitHub tokens, AWS keys, `Bearer` headers, private-key blocks. A check run on
2026-09-07 confirmed it is doing its job — searching the committed logs for those shapes returned
only already-redacted placeholders.

But it is best-effort and always will be. It cannot catch a password you typed while debugging, a
customer's email address in a test file, or a database dump pasted into a prompt, because none of
those look like anything in particular. **Before you share a log with anyone, read it.**
