---
name: Database naming convention
description: MySQL tables use tblCamelCase prefix, columns use CamelCase. SQL aliases preserve lowercase API keys for backward compatibility.
type: feedback
---

Use CamelCase for all MySQL database identifiers with `tbl` prefix on table names.

**Why:** User prefers consistent CamelCase naming across the database schema for readability and consistency.

**How to apply:**
- Tables: `tblSongbooks`, `tblSongs`, `tblUsers`, `tblApiTokens`, etc.
- Columns: `SongId`, `CreatedAt`, `SongbookAbbr`, `LyricsPublicDomain`, etc.
- SQL SELECT queries use `AS` aliases to map CamelCase columns to lowercase API-compatible keys (e.g., `SongId AS id`, `SongbookAbbr AS songbook`)
- PHP array keys accessing PDO FETCH_ASSOC results must use CamelCase (e.g., `$user['PasswordHash']`, `$user['IsActive']`)
- API response keys remain lowercase for backward compatibility
