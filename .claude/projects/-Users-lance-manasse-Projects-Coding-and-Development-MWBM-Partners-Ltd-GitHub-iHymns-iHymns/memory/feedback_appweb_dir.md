---
name: Use appWeb not web directory
description: The web app directory should be appWeb/ not web/ — user explicitly changed this to maintain naming consistency with original repo.
type: feedback
---

Use `appWeb/` as the web application directory, not `web/`.

**Why:** User explicitly requested this to maintain naming consistency with the original repo structure (appWeb/, appAppleIOS, etc.).

**How to apply:** All web-related files, deployment paths, and documentation should reference `appWeb/`. The sub-structure is: `public_html/` (single source, deployed to all environments), `data_share/` (shared data), `private_html/` (admin/editor).
