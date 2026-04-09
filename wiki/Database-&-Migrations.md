# Database & Migrations

> SQLite database, schema migrations, and multi-driver support

---

## Overview

The iHymns admin backend uses a relational database for user accounts, API tokens, setlists, and password reset tokens. The default driver is **SQLite** (zero-configuration), with migration support for **MySQL/MariaDB** and **SQL Server**.

---

## Configuration

Database configuration is in `appWeb/public_html/manage/includes/db.php`:

```php
define('DB_CONFIG', [
    'driver' => 'sqlite',  // Change to 'mysql' or 'sqlsrv' to switch

    'sqlite' => [
        'path' => dirname(__DIR__, 3) . '/data_share/SQLite/ihymns.db',
    ],

    'mysql' => [
        'host' => '127.0.0.1', 'port' => 3306,
        'database' => 'ihymns', 'username' => '', 'password' => '',
        'charset' => 'utf8mb4',
    ],

    'sqlsrv' => [
        'host' => '127.0.0.1', 'port' => 1433,
        'database' => 'ihymns', 'username' => '', 'password' => '',
    ],
]);
```

### SQLite File Location

The SQLite database is stored at `appWeb/data_share/SQLite/ihymns.db` — **outside the public web root** for security. The directory is created automatically if it doesn't exist.

**Important:** The `data_share/` directory is deployed **without** the `--delete` flag, so the database is preserved between deployments.

---

## Connection Factory

The `getDb()` function returns a shared PDO instance (singleton per request):

- Creates the connection on first call, reuses for subsequent calls
- Automatically creates the SQLite file and directory if needed
- Runs all pending migrations on every connection (idempotent)
- Enables WAL journal mode and foreign keys for SQLite

---

## Schema Migrations

Migrations are idempotent SQL statements tracked in a `migrations` table. New migrations are appended to the array and run in order.

### Current Migrations

| Migration | Table | Purpose |
|---|---|---|
| `001_create_users` | `users` | User accounts (username, password_hash, display_name, role, is_active) |
| `002_create_sessions` | `sessions` | PHP sessions (for admin panel) |
| `003_create_api_tokens` | `api_tokens` | Bearer tokens for public API auth |
| `004_create_user_setlists` | `user_setlists` | Server-side setlist storage linked to user accounts |
| `005_create_password_reset_tokens` | `password_reset_tokens` | Password reset tokens (48-char hex, 1hr expiry, single-use) |
| `006_add_user_email` | `users` | Adds `email` column for password reset delivery |

### Table Schemas

#### `users`

| Column | Type | Description |
|---|---|---|
| `id` | INTEGER PK | Auto-increment user ID |
| `username` | TEXT UNIQUE | Lowercase login name |
| `password_hash` | TEXT | BCRYPT hash (cost 12) |
| `display_name` | TEXT | Human-readable name |
| `role` | TEXT | `global_admin`, `admin`, `editor`, or `user` |
| `email` | TEXT | Email for password resets |
| `is_active` | INTEGER | 1=active, 0=disabled |
| `created_at` | TEXT | ISO 8601 timestamp |
| `updated_at` | TEXT | ISO 8601 timestamp |

#### `api_tokens`

| Column | Type | Description |
|---|---|---|
| `token` | TEXT PK | 64-char hex bearer token |
| `user_id` | INTEGER FK | References `users(id)` CASCADE |
| `created_at` | TEXT | ISO 8601 timestamp |
| `expires_at` | TEXT | Token expiry (30 days from creation) |

#### `user_setlists`

| Column | Type | Description |
|---|---|---|
| `id` | INTEGER PK | Auto-increment |
| `user_id` | INTEGER FK | References `users(id)` CASCADE |
| `setlist_id` | TEXT | Client-generated setlist UUID |
| `name` | TEXT | Setlist name |
| `songs_json` | TEXT | JSON array of song entries |
| `created_at` | TEXT | ISO 8601 timestamp |
| `updated_at` | TEXT | ISO 8601 timestamp |
| | UNIQUE | `(user_id, setlist_id)` — one per user per setlist |

#### `password_reset_tokens`

| Column | Type | Description |
|---|---|---|
| `token` | TEXT PK | 48-char hex reset token |
| `user_id` | INTEGER FK | References `users(id)` CASCADE |
| `created_at` | TEXT | ISO 8601 timestamp |
| `expires_at` | TEXT | Token expiry (1 hour from creation) |
| `used` | INTEGER | 0=unused, 1=used |

#### `sessions`

| Column | Type | Description |
|---|---|---|
| `id` | TEXT PK | Session ID |
| `user_id` | INTEGER FK | References `users(id)` CASCADE |
| `ip_address` | TEXT | Client IP |
| `user_agent` | TEXT | Client user agent |
| `created_at` | TEXT | ISO 8601 timestamp |
| `expires_at` | TEXT | Session expiry |

---

## Adding New Migrations

To add a new migration, append to the `$migrations` array in `runMigrations()`:

```php
'007_your_migration_name' => '
    CREATE TABLE IF NOT EXISTS your_table (
        id INTEGER PRIMARY KEY ' . ($driver === 'sqlite' ? 'AUTOINCREMENT' : 'AUTO_INCREMENT') . ',
        ...
    )
',
```

**Rules:**
- Use standard SQL compatible with both SQLite and MySQL
- Use the `$driver` variable for driver-specific syntax (e.g., `AUTOINCREMENT` vs `AUTO_INCREMENT`)
- Migrations are idempotent — use `IF NOT EXISTS` for CREATE, `IF EXISTS` for DROP
- Migration names must be unique — prefix with a sequential number
- Never modify existing migrations — always add new ones

---

## Switching Database Drivers

To switch from SQLite to MySQL:

1. Change `'driver' => 'mysql'` in `DB_CONFIG`
2. Fill in the MySQL connection details
3. The migrations will run automatically on first connection, creating all tables

The migrations use driver-conditional syntax for `AUTO_INCREMENT` vs `AUTOINCREMENT`.
