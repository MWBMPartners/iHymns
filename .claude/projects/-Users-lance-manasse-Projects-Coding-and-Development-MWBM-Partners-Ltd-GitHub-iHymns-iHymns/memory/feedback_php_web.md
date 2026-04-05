---
name: Web app can use PHP
description: The web/browser-based PWA can use PHP files for flexibility. Hosted on shared hosting like DreamHost where PHP is natively supported.
type: feedback
---

The web app does NOT need to be static-only. PHP files are acceptable and preferred for flexibility.

**Why:** User explicitly corrected the assumption of static-only files. The web app will be hosted on shared hosting (like DreamHost) where PHP is natively supported. PHP provides server-side flexibility for dynamic includes, config, versioning, etc.

**How to apply:** Use .php file extensions where server-side processing adds value (e.g., version injection, dynamic includes, config). Still use HTML5/CSS3/JS on the frontend. Follow the phpWhoIs pattern which also uses PHP on shared hosting. The Vite dev server can still be used for local development.
