---
name: App directory naming convention
description: All platform app directories use appX/ prefix — appWeb/, appApple/, appAndroid/ (not web/, apple/, android/)
type: feedback
---

All platform directories must use the `app` prefix naming convention:
- `appWeb/` — Web/browser-based PWA
- `appApple/` — Apple universal app (Swift/SwiftUI)
- `appAndroid/` — Android app (Kotlin/Compose)

**Why:** User explicitly corrected from `apple/` and `android/` to `appApple/` and `appAndroid/` for consistency with `appWeb/`.

**How to apply:** Always use `appApple/`, `appAndroid/`, `appWeb/` in code, docs, CI/CD, and conversation. Never use bare `apple/`, `android/`, or `web/`.
