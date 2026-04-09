# Security

> Security measures, authentication, and best practices

---

## Content Security Policy (CSP)

Every request generates a unique nonce for inline scripts. The CSP header includes:

```
default-src 'self';
script-src 'self' 'nonce-<random>' https://cdn.jsdelivr.net ...;
style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net ...;
img-src 'self' data: https:;
font-src 'self' https://cdn.jsdelivr.net ...;
connect-src 'self' https://www.google-analytics.com ...;
frame-ancestors 'self';
base-uri 'self';
form-action 'self';
upgrade-insecure-requests;
```

All CDN resources include **Subresource Integrity (SRI)** hashes.

---

## Authentication Security

### Password Hashing
- Algorithm: **BCRYPT** (`PASSWORD_BCRYPT`)
- Cost factor: **12** (higher than default for stronger protection)
- PHP's `password_hash()` / `password_verify()` — timing-safe comparison

### Bearer Tokens
- 64-character lowercase hexadecimal (32 bytes of `random_bytes()`)
- 30-day expiry with server-side validation
- Stored in `api_tokens` table
- Deleted on logout and password reset

### Password Reset Tokens
- 48-character lowercase hexadecimal (24 bytes of `random_bytes()`)
- 1-hour expiry
- Single-use (marked as `used` after consumption)
- Previous tokens for the same user are deleted when a new one is generated
- Password reset invalidates ALL API tokens (forces re-login on all devices)

### Session Security (Admin Panel)
- `httponly` flag — prevents JavaScript access to session cookie
- `samesite=Strict` — prevents CSRF via cross-site requests
- `secure` flag — when HTTPS is detected
- `session_regenerate_id(true)` on login — prevents session fixation
- Session cookie scoped to `/manage/` path only

### CSRF Protection
- Per-session CSRF token (64 hex chars via `random_bytes(32)`)
- Validated with `hash_equals()` — timing-safe comparison
- Required on all admin panel form submissions

---

## Input Sanitisation

### API Inputs
- Usernames: lowercased, trimmed, validated against `/^[a-z0-9_.\-]+$/`
- Song IDs: validated against `/^[A-Za-z]+-\d+$/`
- Setlist IDs: alphanumeric only (regex filtered)
- Owner UUIDs: hex + hyphen only
- Display names: trimmed, truncated to 100 chars
- Setlist names: trimmed, truncated to 200 chars
- Song counts: capped at 200 per setlist, 50 setlists per user
- Arrangements: validated as arrays of non-negative integers

### HTML Output
- All dynamic content escaped with `htmlspecialchars()`
- JavaScript uses `escapeHtml()` from `js/utils/html.js`
- No raw HTML interpolation of user data

---

## File Security

### Direct Access Prevention
Every PHP include file starts with:
```php
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}
```

### SQLite Database Location
- Stored in `appWeb/data_share/SQLite/` — **outside the public web root**
- Not directly accessible via HTTP
- `.htaccess` rules prevent access to `data_share/` if misconfigured

### Shared Setlist Files
- Stored in `appWeb/data_share/setlist_json/`
- Setlist IDs are 8-character hex strings (4 bytes of randomness)
- Atomic file creation with `fopen('x')` to prevent TOCTOU races
- Ownership verified before updates (owner UUID must match)

---

## User Enumeration Prevention

- `auth_forgot_password` always returns HTTP 200 with the same message, regardless of whether the user exists
- Registration returns 409 for duplicate usernames (necessary for UX, acceptable trade-off)

---

## Rate Limiting

Currently not implemented at the application level. Recommendations:
- Use web server rate limiting (Apache `mod_ratelimit`, nginx `limit_req`)
- Consider adding application-level rate limiting for auth endpoints
- Rate limit password reset requests per IP

---

## Security Headers

| Header | Value |
|---|---|
| `Content-Security-Policy` | Per-request with nonce (see above) |
| `X-Content-Type-Options` | `nosniff` (on all JSON responses) |
| `Cache-Control` | `no-cache, must-revalidate` (on API responses) |

---

## Recommendations for Production

1. **Enable HTTPS** — the session cookie `secure` flag activates automatically
2. **Set up rate limiting** at the web server level for `/api.php` auth endpoints
3. **Remove `_dev_token`** from `auth_forgot_password` response in production
4. **Implement email delivery** for password reset tokens
5. **Monitor** `api_tokens` table size and clean up expired tokens periodically
6. **Backup** the SQLite database regularly (it's in `data_share/SQLite/`)
