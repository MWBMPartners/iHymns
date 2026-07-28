# Polishing for Claude Fable 5

Doctrine distilled from Anthropic's official guide, [Prompting Claude Fable 5](https://platform.claude.com/docs/en/build-with-claude/prompt-engineering/prompting-claude-fable-5) (model ID `claude-fable-5`; the same guidance applies to Claude Mythos 5). This is a transformation guide — adapt it to the diagnosed prompt; never paste it mechanically.

## Model profile

What changed from Claude Opus 4.8, in the words that matter when rewriting a prompt:

- **Built for the hardest version of the task.** Fable 5 completes end-to-end work that takes a person hours, days, or weeks. Teams get the best outcomes assigning it their hardest unsolved problems; slicing tasks thin undersells it. Polish toward "scope it, then execute end-to-end", not toward smaller pieces.
- **Brief steering beats enumeration.** Instruction-following is strong enough that one outcome-centered sentence replaces a list of named behaviors. Per the guide, prompts and skills written for prior models are often too prescriptive for Fable 5 and **can degrade output quality** — polishing an older prompt means deleting instructions, not adding them.
- **First-shot correctness on well-specified problems.** The highest-leverage words are specification — intent, deliverable, done-condition — not process.
- **Longer turns, deeper deliberation.** At higher effort it can over-gather context, over-plan on ambiguous tasks, or do unrequested tidying. Bound when to act and what "enough" looks like.
- **Dispatches subagents readily and sustains multi-day runs.** It needs boundaries and evidence gates more than encouragement.
- **Safety classifiers are live** for offensive cybersecurity, biology/life-sciences methods, and extraction of its reasoning. See "Refusal-aware polishing" below.

## Free by default — delete on sight

Fable 5 already does these. Instructions demanding them are noise, and noise costs instruction-following budget:

- "Think step by step" / "reason carefully before answering"
- "Be thorough" / "don't be lazy" / ALL-CAPS repetition for emphasis
- Enumerated micro-steps for work it can sequence itself
- Long "do not do A, B, C, D…" lists — replace with one outcome-centered instruction
- Forced plan-first rituals when the plan doesn't need user sign-off
- Encouragement to use subagents on simple tasks
- Role-play credentials ("you are a world-class senior engineer")

## Decision table

Apply only the rows the diagnosis triggered.

| Raw prompt diagnosis                                                      | Transformation                                                                                                                                                             |
| ------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Task stated without the why                                               | Open with intent (snippet 1). Context lets Fable connect the task to relevant information instead of inferring intent.                                                     |
| Ambiguous task, risk of over-planning                                     | Add act-when-ready (snippet 2).                                                                                                                                            |
| Routine code change or scoped edit                                        | Add scope discipline (snippet 3) to prevent unrequested refactors and abstractions at higher effort.                                                                       |
| Long-running or autonomous work (_agent_ class)                           | Add checkpoint boundary (snippet 4) + evidence-grounded progress (snippet 5). The evidence instruction nearly eliminated fabricated status reports in Anthropic's testing. |
| User describes a problem rather than requesting a change                  | Add assessment-vs-action boundary (snippet 6).                                                                                                                             |
| Verbose or hard-to-scan output expected                                   | Add lead-with-outcome (snippet 7); for long agentic sessions add the readability addendum (snippet 8).                                                                     |
| Parallelizable research, review, or verification                          | Add delegation nudge (snippet 9). Prefer asynchronous delegation over blocking.                                                                                            |
| Long build with quality stakes                                            | Add a verification cadence with fresh-context verifier subagents (snippet 10) — they outperform self-critique.                                                             |
| Will run unattended (pipeline, cron, CI)                                  | Add the autonomous-operation block (snippet 11).                                                                                                                           |
| Recurring agent that should improve over time (harness form only)         | Add a memory rule (snippet 12).                                                                                                                                            |
| Harness shows context/token countdowns (harness form only)                | Add context reassurance (snippet 13).                                                                                                                                      |
| Micromanaged steps, behavior lists, old-model scaffolding                 | Delete. Keep only ordering that genuinely matters. State the outcome and constraints instead.                                                                              |
| "Show your reasoning" / "explain your thought process" / "think out loud" | Remove — see Refusal-aware polishing. Ask for a concise rationale, assumptions, and evidence instead.                                                                      |

## Snippet library

Official guide language — tested by Anthropic. Adapt placeholders, trim sentences that don't apply, and keep only what the diagnosis called for.

**1 — Intent opener** (almost always worth it for _build_, _agent_, _review_):

```text
I'm working on [the larger task] for [who it's for]. They need [what the output
enables]. With that in mind: [request].
```

**2 — Act when ready** (ambiguous tasks, over-planning risk):

```text
When you have enough information to act, act. Do not re-derive facts already
established, re-litigate a decision already made, or narrate options you will not
pursue. If you are weighing a choice, give a recommendation, not an exhaustive survey.
```

**3 — Scope discipline** (routine code work, higher effort):

```text
Don't add features, refactor, or introduce abstractions beyond what the task requires.
A bug fix doesn't need surrounding cleanup. Don't design for hypothetical future
requirements: do the simplest thing that works well. Only validate at system boundaries
(user input, external APIs). Don't use feature flags or backwards-compatibility shims
when you can just change the code.
```

**4 — Checkpoint boundary** (long-running work; no need to enumerate every case):

```text
Pause for the user only when the work genuinely requires them: a destructive or
irreversible action, a real scope change, or input that only they can provide. If you
hit one of these, ask and end the turn, rather than ending on a promise.
```

**5 — Evidence-grounded progress** (long runs, status reporting):

```text
Before reporting progress, audit each claim against a tool result from this session.
Only report work you can point to evidence for; if something is not yet verified, say
so explicitly. Report outcomes faithfully: if tests fail, say so with the output; if a
step was skipped, say that; when something is done and verified, state it plainly
without hedging.
```

**6 — Assessment vs. action** (problem descriptions, questions, thinking out loud):

```text
When I'm describing a problem or asking a question rather than requesting a change,
the deliverable is your assessment. Report your findings and stop. Don't apply a fix
until I ask for one. Before running a command that changes system state, check that
the evidence actually supports that specific action.
```

**7 — Lead with the outcome** (one short brevity instruction beats listing each verbosity pattern):

```text
Lead with the outcome. Your first sentence after finishing should answer "what
happened" or "what did you find" — the TLDR. Supporting detail and reasoning come
after. Keep output short by being selective about what you include, not by compressing
the writing into fragments, abbreviations, or jargon.
```

**8 — Readability addendum** (extended agentic sessions where the user wasn't watching):

```text
Your final summary is for a reader who didn't see any of your working process. Write
it as a re-grounding, not a continuation: the outcome first, then the one or two
things you need from them. Write complete sentences, spell out terms, and don't use
arrow chains or labels you made up while working.
```

**9 — Delegation** (parallelizable subtasks):

```text
Delegate independent subtasks to subagents and keep working while they run. Intervene
if a subagent goes off track or is missing relevant context.
```

**10 — Verification cadence** (long builds; fresh-context verifiers beat self-critique):

```text
Establish a method for checking your own work as you build. At each [interval/milestone],
verify the work against the specification using fresh-context subagents, and fix what
they find before moving on.
```

**11 — Autonomous operation** (unattended pipelines; prevents rare early stopping):

```text
You are operating autonomously. No one is watching in real time or can answer
questions mid-task, so asking "Want me to…?" will block the work. For reversible
actions that follow from the original request, proceed without asking. Before ending
your turn, check your last paragraph: if it is a plan, a question, or a promise about
work you have not done, do that work now. End your turn only when the task is complete
or you are blocked on input only the user can provide.
```

**12 — Memory rule** (harness prompts for recurring agents only):

```text
Store one lesson per file with a one-line summary at the top. Record corrections and
confirmed approaches alike, including why they mattered. Don't save what the repo or
chat history already records; update an existing note rather than creating a
duplicate; delete notes that turn out to be wrong.
```

**13 — Context reassurance** (harness prompts that surface token countdowns only):

```text
You have ample context remaining. Do not stop, summarize, or suggest a new session on
account of context limits. Continue the work.
```

## Refusal-aware polishing

Fable 5 runs safety classifiers. When the raw prompt touches these areas, do not silently polish into a refusal:

- **Offensive cybersecurity** (exploits, malware, attack tooling) and **biology/life-sciences methods** — even benign work in these domains can trigger `stop_reason: "refusal"`. Flag it in the Note line and suggest targeting Claude Opus 4.8 instead, or configuring fallback to it.
- **Reasoning extraction** — any instruction to echo, transcribe, or explain internal reasoning as response text can trigger the `reasoning_extraction` refusal. Strip these during polish (this is a hard-gate item) and replace with: ask for a concise rationale, key assumptions, checks performed, and evidence.

## Skeletons

Starting shapes, not templates to fill blindly. Cut sections the task doesn't need.

### Tier 1 — direct task

```text
I'm [larger context / who this is for]. [Request], as [deliverable/format], for
[audience]. Keep it [length/style constraint].

If a requirement is ambiguous, make the most reasonable assumption and state it
briefly; ask only if the answer would materially change.
```

### Tier 3 — agentic work

```text
I'm working on [larger goal] for [who it's for]. They need [what the output enables].

Task: [specific request].

Scope: [included work]. Don't [excluded work]. Do the simplest thing that works well —
no speculative abstractions or unrequested cleanup.

When you have enough information to act, act. Pause only for destructive or
irreversible actions, real scope changes, or input only I can provide.

Before reporting progress, audit each claim against a tool result from this session;
say plainly when something is unverified, skipped, or failing.

Lead with the outcome in your final summary, written for a reader who didn't watch
you work.

Done means: [verifiable completion condition].
```

### Review / QA

```text
Review [artifact] for [risk category or quality goal]. I'll use the findings to
[decision the review enables].

Prefer concrete findings over general advice. For each: severity, location or
evidence, why it matters, suggested fix, and your confidence. Verify each finding
against the evidence before reporting it; suppress speculation unless the potential
impact is severe.
```

## Final check

Before emitting, confirm the polished prompt:

1. Is **shorter in instructions** (not necessarily words) than a prior-model version would be — prescription removed, intent added.
2. States why, what, and done — not how, unless the how genuinely constrains.
3. Contains no reasoning-extraction language and no refusal-domain surprise.
4. Uses only snippets the diagnosis called for, adapted to the task.
5. Would make Fable 5's defaults — long-horizon autonomy, first-shot correctness, brief steering — work for the user, not against them.
