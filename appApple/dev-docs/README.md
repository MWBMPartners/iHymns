# `appApple/dev-docs/` — developer documentation (NEVER shipped)

Everything in this folder is **developer/admin documentation only**. It is
deliberately kept **out of the app bundle** — none of it is packaged into any
iHymns Apple app (iOS / iPadOS / macOS / tvOS / watchOS / visionOS / widgets)
or submitted to TestFlight / the App Store.

## Why it can't ship (two independent guarantees)

1. **It's outside every target's source path.** In `appApple/project.yml`, each
   app target's `sources:` is scoped to `Apps/<target>/Sources`. This `dev-docs/`
   folder is a *sibling* of `Apps/`, so XcodeGen never reaches into it — nothing
   here is added to any target, let alone a "Copy Bundle Resources" phase.
2. **Belt-and-suspenders `.md` exclude.** Every target's `sources:` in
   `project.yml` also carries `excludes: ["**/*.md"]`, so even a Markdown file
   *accidentally* dropped into a `Sources/` folder is never bundled. (XcodeGen
   auto-classifies non-source files inside a `sources:` path by extension — e.g.
   `.xcprivacy` → a bundle resource — so this guard matters.)

## The convention

- **Developer docs → `appApple/dev-docs/`** (this folder). Never shipped.
- **If you ever need docs that SHOULD ship** (in-app help content, licences,
  etc.), do NOT put them here — add them under a target's `Sources/` (or a
  `Resources/` folder) and declare them explicitly (e.g. an XcodeGen `resources:`
  entry, or a SwiftPM `.copy(...)`/`.process(...)` in `Packages/iHymnsKit/Package.swift`).
  Shipping is opt-in and explicit; dev docs are never shipped by default.

## Contents

- `Provisioning-Runbook.md` — owner/developer runbook for Apple App ID,
  capabilities, App Store Connect, Sign in with Apple key + `.p8`, secret
  provisioning (#1466), CarPlay, and the verification walkthrough.
