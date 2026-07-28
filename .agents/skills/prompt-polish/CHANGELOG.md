# Changelog

## 1.0.2 — 2026-06-12

- Documented `npx skills add mfarzanansari/prompt-polish` as the primary install path. Verified end-to-end: the skills CLI discovers the root SKILL.md and installs the full package (including `references/`) to all supported agents.

## 1.0.1 — 2026-06-12

Hardening from a subagent compliance audit (the skill was executed end-to-end against test invocations by a fresh-context agent acting as a harsh critic):

- **Precedence rule**: user intent outranks model doctrine. When the raw prompt itself contains a doctrine anti-pattern ("make it modern", "clean up everything"), it gets repackaged — bounded, made concrete, or routed through propose-first — never deleted.
- **Build-vs-agent tiebreaker**: *agent* class (and its Tier 3 harness) only applies if the work should survive the user walking away for an hour. Makes tier selection — and therefore output size — deterministic.
- **Placeholder budget**: bracketed example hints are explicitly suggestions, not inventions; more than four placeholders means the task is underspecified and the skill asks instead.

## 1.0.0 — 2026-06-12

Initial public release.

- **Claude Fable 5 doctrine** (`references/fable-5.md`) distilled from the official prompting guide: subtraction-first rewriting, intent openers, autonomy boundaries, evidence-grounded progress, verifier-subagent cadence, refusal-aware polishing (cybersecurity/bio domains and reasoning-extraction language).
- **Claude Opus 4.8 doctrine** (`references/opus-4-8.md`) distilled from the official prompting guide: explicit-scope rewriting for literal instruction following, pipeline/extraction shaping, tool-use and subagent elicitation, design house-style breaking, review coverage-vs-filtering, front-loaded coding specs. Opus 4.7 requests route here.
- **Diagnosis-driven workflow**: form (message vs. harness) × class (ask/build/agent/review/design/pipeline) × gaps × noise, mapped to three sizing tiers.
- **Hard gate**: never change the task, never invent facts, every instruction earns its keep, no chain-of-thought extraction.
- **Strict output contract**: copy-ready prompt block, `Fill in:` placeholders, one-line `Note:` — nothing else by default.
