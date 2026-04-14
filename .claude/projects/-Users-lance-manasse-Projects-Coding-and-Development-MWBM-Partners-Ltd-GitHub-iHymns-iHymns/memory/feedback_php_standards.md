---
name: PHP coding standards — constants, paths, strict types
description: Use PHP predefined constants everywhere. DIRECTORY_SEPARATOR for all paths. No shortcut variables. strict_types on all files. No .php in URLs.
type: feedback
---

PHP code must use predefined constants and strict typing throughout.

**Why:** User requires consistent, standards-compliant PHP code. Hardcoded '/' in paths breaks cross-platform compatibility. Shortcut variables add unnecessary indirection. Exposing .php in URLs reveals backend technology.

**How to apply:**

- `DIRECTORY_SEPARATOR` for ALL filesystem path concatenations — never use '/' in path strings
  - Before: `__DIR__ . '/includes/config.php'`
  - After: `__DIR__ . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'config.php'`
- Use `dirname(__DIR__, N)` instead of `__DIR__ . '/../'` patterns
- Use `$app["Application"]["Name"]` directly — never create alias variables like `$appName`
- `declare(strict_types=1)` on every PHP file
- `PHP_EOL` instead of `"\n"` where appropriate
- No `.php` visible in any user-facing URL — all routes rewritten via .htaccess
- Web dashboard at `/manage/setup-database` (no .php) for all DB admin tasks
- DreamHost has NO CLI access — all scripts must work via browser (web mode)
