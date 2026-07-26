# prompt-polish

An agent skill that rewrites rough prompts into polished, model-specific prompts — grounded in the official Anthropic prompting guides, not prompt-engineering folklore.

```text
prompt-polish/[MODEL]/[YOUR ROUGH PROMPT]
```

## Why

Most prompt advice is model-agnostic, which makes it wrong for any specific model. Frontier models have diverged: Claude Fable 5 degrades when you micromanage it — the official guide says prompts written for prior models are often too prescriptive and hurt output quality. Claude Opus 4.8 follows instructions so literally that implicit scope is a bug. The same "improvement" helps one model and breaks the other.

`prompt-polish` encodes each model's **official prompting guide** as a separate doctrine file and routes your prompt through the right one. No folklore, no cargo-cult — every transformation traces to a published source.

The second idea it encodes: **polishing is mostly subtraction.** Modern models rarely fail from missing instructions. They fail from missing intent, instruction noise, and habits carried over from older models. The polished prompt is the smallest set of words that makes the target model's defaults work in your favor.

## Install

With the [skills CLI](https://github.com/vercel-labs/skills) — works for Claude Code, Codex, Cursor, Windsurf, and every other agent it supports:

```bash
npx skills add mfarzanansari/prompt-polish
```

Add `-g` for a global (user-level) install instead of per-project, and update later with `npx skills update prompt-polish`.

Or clone directly:

```bash
# Claude Code
git clone https://github.com/mfarzanansari/prompt-polish ~/.claude/skills/prompt-polish

# Codex CLI
git clone https://github.com/mfarzanansari/prompt-polish ~/.codex/skills/prompt-polish
```

## Usage

Any of these shapes work:

```text
prompt-polish/FABLE 5/review my api code and fix whatever is broken
prompt-polish/OPUS 4.8/build me a landing page for my coffee brand
prompt polish / fable / summarize this contract for my cofounder
polish this for Opus: extract the line items from these invoices
```

You get back the polished prompt in a copy-ready code block — plus, only when material, a `Fill in:` line for placeholders you need to complete and a one-line `Note:` (for example, a refusal-risk warning or an API effort recommendation that can't live inside a prompt). No lecture about what was changed unless you ask.

## How it works

1. **Parse and route** — model aliases resolve to one doctrine file; only that file is loaded.
2. **Diagnose** — the raw prompt is classified by form (one-off message vs. system/harness prompt), class (ask · build · agent · review · design · pipeline), gaps (missing intent, deliverable, done-condition), and noise (instructions the target model makes redundant).
3. **Size** — three tiers: light touch, structured, full agentic harness. The smallest tier that fits wins; a two-line question never becomes an operating manual.
4. **Rewrite** — using the doctrine file's decision table and snippet library of official, Anthropic-tested language.
5. **Gate** — a hard gate guarantees the task is never changed, nothing is invented (unknowns become `[BRACKETED PLACEHOLDERS]`), and no chain-of-thought-extraction language survives.

```text
prompt-polish/
├── SKILL.md               # model-agnostic contract: parsing, diagnosis, tiers, gates
├── references/
│   ├── fable-5.md         # Claude Fable 5 doctrine
│   └── opus-4-8.md        # Claude Opus 4.8 doctrine (also serves 4.7 requests)
└── agents/
    └── openai.yaml        # Codex interface metadata
```

## Supported models

| Model | Doctrine | Source |
|---|---|---|
| Claude Fable 5 (`claude-fable-5`) | `references/fable-5.md` | [Prompting Claude Fable 5](https://platform.claude.com/docs/en/build-with-claude/prompt-engineering/prompting-claude-fable-5) |
| Claude Opus 4.8 (`claude-opus-4-8`) | `references/opus-4-8.md` | [Prompting Claude Opus 4.8](https://platform.claude.com/docs/en/build-with-claude/prompt-engineering/prompting-claude-opus-4-8) |
| Claude Opus 4.7 | routes to `opus-4-8.md` | closest published doctrine; noted in output |

Unsupported models are declined honestly — the skill never fakes model-specific guidance.

## Design principles

1. **Official doctrine only.** Every transformation traces to a published guide. Adding a model starts with finding its guide, never with writing rules from memory.
2. **Polishing is subtraction.** Every instruction in the output must change the target model's behavior, or it gets deleted.
3. **Smallest tier that fits.** Inflation — turning a question into an agent operating manual — is treated as a bug, not thoroughness.
4. **Never drift the task.** Same task, better odds. The hard gate rejects rewrites that add deliverables, audiences, or quality bars the user never asked for.
5. **Placeholders over inventions.** What only the user knows becomes a bracketed placeholder. Nothing is ever fabricated to make a prompt look complete.

## Extending to a new model

1. Find the model's official prompting guide.
2. Distill it into `references/<model-slug>.md` following the existing structure: model profile → free-by-default list → decision table → snippet library → skeletons → final check.
3. Add an alias row to the routing table in `SKILL.md`.

## License

[MIT](LICENSE)
