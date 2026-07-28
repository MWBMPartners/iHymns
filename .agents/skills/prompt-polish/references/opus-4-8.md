# Polishing for Claude Opus 4.8

Doctrine distilled from Anthropic's official guide, [Prompting Claude Opus 4.8](https://platform.claude.com/docs/en/build-with-claude/prompt-engineering/prompting-claude-opus-4-8) (model ID `claude-opus-4-8`). Opus 4.7 requests route here too: Opus 4.8 performs well out of the box on 4.7 prompts, and this is the closest published doctrine — say so in the Note line. This is a transformation guide — adapt it to the diagnosed prompt; never paste it mechanically.

## Model profile

The words that matter when rewriting a prompt:

- **Literal, explicit instruction follower** — especially at lower effort. It does not silently generalize an instruction from one item to another, and it does not infer requests you didn't make. Upside: precision, predictability, less thrash — the best Claude yet for carefully tuned pipelines. Cost: implicit scope is a bug. If an instruction should apply broadly, the prompt must say so.
- **Calibrates response length to judged task complexity** — short on lookups, long on open-ended analysis. Only tune verbosity if the product depends on a specific style, and tune with positive examples.
- **Favors reasoning over tool calls.** This usually helps; when you need more tool use, raise effort or explicitly describe when and why to use each tool.
- **Spawns fewer subagents by default.** Steerable — give explicit guidance on when delegation is and isn't desirable.
- **Effort is the master knob.** `xhigh` is best for coding and agentic work; minimum `high` for intelligence-sensitive tasks. It respects low effort strictly — it scopes work to exactly what was asked. Thinking is off unless `thinking: {type: "adaptive"}` is set. These are API settings, not prompt text — put recommendations in the Note line.
- **Strong design instincts with a persistent house style** — warm cream backgrounds (~#F4F1EA), serif display type, terracotta/amber accents. Beautiful for editorial; wrong for dashboards, dev tools, fintech. Generic "don't use cream" swaps one fixed palette for another; only concrete direction or propose-first breaks it.
- **Better bug-finding than prior models, and it follows your reporting bar faithfully** — "only report high-severity issues" means investigations happen but findings get withheld. Tune the bar, not the model.
- **Front-loading wins in interactive coding.** It reasons more after each user turn, so a complete first-turn spec maximizes both performance and token efficiency versus drip-fed clarifications.

## Free by default — delete on sight

Opus 4.8 already does these; instructions demanding them are noise:

- Forced interim status updates ("after every 3 tool calls, summarize progress") — its user-facing updates are already regular and high quality
- "Be precise" / "follow my instructions exactly" — it is already literal
- Verbosity padding or trimming rules with no specific style target — it calibrates to the task
- Lengthy anti-"AI slop" frontend lectures — a short snippet suffices (snippet 6)
- Validation-forward tone scaffolding — its default is already direct and opinionated
- Role-play credentials and "think step by step" rituals

## Decision table

Apply only the rows the diagnosis triggered.

| Raw prompt diagnosis                                                             | Transformation                                                                                                                                                   |
| -------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Instruction with implied scope ("format the section header" meaning all of them) | Make scope explicit (snippet 1). Literalism means it will do exactly — and only — what's written.                                                                |
| Pipeline / extraction / structured output (_pipeline_ class)                     | Specify exact output format, edge-case handling, and scope per field. This model rewards tuned prompts more than any prior Opus.                                 |
| Product depends on a specific voice or verbosity                                 | State it and show one positive example of the desired tone (snippet 2). Positive examples beat "don't" lists.                                                    |
| Needs more tool use (search, retrieval, agentic work)                            | Describe when and why to use each tool (snippet 3). Also recommend raising effort in the Note line.                                                              |
| Parallelizable work that should fan out                                          | Add explicit subagent guidance — both when to spawn and when not to (snippet 4).                                                                                 |
| Design / frontend brief (_design_ class)                                         | Either specify a concrete visual direction (snippet 5 shape) or have it propose directions first (snippet 5b). Add the aesthetics guard (snippet 6) for variety. |
| Code review or bug-finding harness (_review_ class)                              | Separate coverage from filtering (snippet 7), or set a concrete severity bar instead of qualitative words like "important" (snippet 7b).                         |
| Interactive coding session opener                                                | Front-load the complete spec: task, intent, constraints, done-condition in the first turn (snippet 8).                                                           |
| Must run at low effort but involves multi-step reasoning                         | Add a targeted reasoning trigger (snippet 9) — but the honest fix is raising effort; say so in the Note line.                                                    |
| Forced progress-update scaffolding from an older model                           | Delete it. If update shape matters, describe the desired shape and give an example instead.                                                                      |
| Warm/conversational product voice needed                                         | Add tone guidance (snippet 10) — the default is direct with sparing emoji.                                                                                       |

## Snippet library

Official guide language — adapt placeholders, keep only what the diagnosis called for.

**1 — Explicit scope** (the literalism rule):

```text
Apply [instruction] to every [item] in [scope], not just the first one.
```

State which items, which files, which sections. Where Fable 5 wants prescription removed, Opus 4.8 wants scope spelled out.

**2 — Verbosity / voice tuning** (only when the product depends on it):

```text
Provide concise, focused responses. Skip non-essential context, and keep examples
minimal. For example, a good answer to [typical question] looks like:

[one positive example of the desired length and tone]
```

**3 — Tool-use elicitation**:

```text
Use [tool] whenever [condition — e.g., the answer depends on facts you can't verify
from context]. Prefer checking over recalling: [why it matters for this task].
```

**4 — Subagent guidance** (it spawns fewer by default):

```text
Do not spawn a subagent for work you can complete directly in a single response
(e.g. refactoring a function you can already see).

Spawn multiple subagents in the same turn when fanning out across items or reading
multiple files.
```

**5 — Design direction** (breaks the cream/serif house style):

Concrete spec — the model follows explicit specs precisely. Name the atmosphere, palette (hex values), typography character, corner radius, spacing feel, and motion timing:

```text
The visual direction should come from [atmosphere — e.g., a cold monochrome palette of
pale silver-gray deepening into blue-gray and near-black]. Stay within this palette:
[4-6 hex values]. Typography: [character — e.g., a square, angular sans-serif with wide
letter spacing]. Use [N]px corner radius consistently. [Motion: e.g., subtle 160ms
ease-out transitions rather than dramatic motion.]
```

**5b — Propose-first** (when the user hasn't chosen a direction; also the replacement for temperature-based variety):

```text
Before building, propose 4 distinct visual directions tailored to this brief (each as:
bg hex / accent hex / typeface — one-line rationale). Ask me to pick one, then
implement only that direction.
```

**6 — Aesthetics guard** (frontend work, pairs with 5/5b):

```text
<frontend_aesthetics>
NEVER use generic AI-generated aesthetics like overused font families (Inter, Roboto,
Arial, system fonts), cliched color schemes (particularly purple gradients), predictable
layouts, and cookie-cutter design that lacks context-specific character. Use unique
fonts, cohesive colors and themes, and animations for micro-interactions.
</frontend_aesthetics>
```

**7 — Review coverage** (recall harnesses; works even without a real second stage):

```text
Report every issue you find, including ones you are uncertain about or consider
low-severity. Do not filter for importance or confidence at this stage — a separate
verification step will do that. Your goal here is coverage: it is better to surface a
finding that later gets filtered out than to silently drop a real bug. For each
finding, include your confidence level and an estimated severity so a downstream
filter can rank them.
```

**7b — Concrete bar** (single-pass review where self-filtering is wanted):

```text
Report any bug that could cause incorrect behavior, a test failure, or a misleading
result; only omit nits like pure style or naming preferences.
```

**8 — Front-loaded coding spec** (first turn of an interactive session):

```text
Task: [specific request]. Intent: [why — what this enables]. Constraints: [stack,
patterns to follow, what not to touch]. Done means: [verifiable condition]. Work
autonomously; don't wait for my confirmation between steps.
```

**9 — Low-effort reasoning trigger** (only when effort can't be raised):

```text
This task involves multi-step reasoning. Think carefully through the problem before
responding.
```

**10 — Warmer voice** (if the default direct style is too dry for the product):

```text
Use a warm, collaborative tone. Acknowledge the user's framing before answering.
```

## API settings that don't belong in the prompt

When the user controls the API call, put these in the Note line rather than the prompt text:

- **Effort:** `xhigh` for coding and agentic use; minimum `high` for intelligence-sensitive work; `medium`/`low` only for cost- or latency-bound routine tasks. If output seems shallow, raise effort before prompting around it.
- **Output budget:** at `xhigh`/`max`, start with a 64k max-token budget so the model has room to think and act.
- **Thinking:** off by default — set `thinking: {type: "adaptive"}` to enable it.
- **Computer use:** 1080p screenshots balance performance and cost.

## Skeletons

Starting shapes, not templates to fill blindly. Cut sections the task doesn't need.

### Tier 1 — direct task

```text
[Request], as [deliverable/format], for [audience]. Apply [any per-item instruction]
to every [item], not just the first. Keep it [length/style constraint].
```

### Pipeline / extraction

```text
[Task]. For each [input item], produce [exact output format].

Rules — apply each to every item:
- [field/transformation rule]
- [edge case]: handle by [explicit behavior]
- If [ambiguous case], [explicit tie-break] — do not guess silently.

Output only [format]; no commentary.
```

### Design brief

```text
Design [artifact] for [product/brand and what it does].

[Concrete direction per snippet 5 — atmosphere, palette hexes, typography, radius,
spacing, motion. Or open with snippet 5b and propose directions first.]

Structure: [sections in order]. [Aesthetics guard, snippet 6.]
```

### Code review

```text
Review [diff/files] for [risk category]. I'll use the findings to [decision].

[Snippet 7 or 7b, depending on whether a downstream filter exists.]

For each finding: file and line, what happens at runtime, severity, confidence, and
the minimal fix.
```

## Final check

Before emitting, confirm the polished prompt:

1. Leaves no scope implicit — every instruction says what it applies to.
2. Specifies output format and edge-case behavior wherever the task is pipeline-shaped.
3. Contains tool-use and subagent guidance only if the task needs the model to reach for them.
4. Breaks the design house style with concrete direction or propose-first — never with vague "make it modern".
5. Pushed API-level levers (effort, thinking, output budget) to the Note line, not into prompt text.
