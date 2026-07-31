-- =====================================================================
-- iHymns — live-database STRUCTURAL probe (#1708)
--
-- ELI5: asks the database to describe its own SHAPE. It reads no songs,
-- no users, no emails — only column names and types.
--
-- WHY THIS EXISTS
-- Migrations here are run BY HAND from /manage/setup-database, and three
-- docroots share one MySQL. So "it is in schema.sql" and "it exists on
-- alpha" are different statements, and nothing in the container can see
-- which. This is the smallest thing that answers it.
--
-- ⚠️ DO NOT RUN ANY MIGRATION FIRST. The whole point is to capture the
-- CURRENT state, pending work included. Migrating first shows the tidied
-- answer rather than the real one.
--
-- SAFETY: every statement is a SELECT against INFORMATION_SCHEMA. It takes
-- no locks, writes nothing, and returns NO personal data — safe to paste
-- into an issue or a chat. A table that does not exist yields no rows
-- rather than an error, so a partial install cannot make it fail.
--
--   mariadb -u <user> -p <database> < tools/db-structural-probe.sql
--   mysql   -u <user> -p <database> < tools/db-structural-probe.sql
-- =====================================================================

SELECT '--- 1. table count ---' AS section;
SELECT COUNT(*) AS live_tables
  FROM INFORMATION_SCHEMA.TABLES
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE';

SELECT '--- 2. the #1708 suspects: do the FK types match? ---' AS section;
SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE
  FROM INFORMATION_SCHEMA.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE()
   AND ( (TABLE_NAME = 'tblLiveFollowSessions'   AND COLUMN_NAME = 'Id')
      OR (TABLE_NAME = 'tblSessionControlTokens' AND COLUMN_NAME = 'SessionId')
      OR (TABLE_NAME = 'tblApnsTokens'           AND COLUMN_NAME = 'SessionId') )
 ORDER BY TABLE_NAME;

SELECT '--- 3. does tblSongs already have the #1694 soft-delete columns? ---' AS section;
SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
  FROM INFORMATION_SCHEMA.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblSongs'
   AND COLUMN_NAME IN ('IsDeleted','DeletedAt','DeletedBy','DeletedReason','DeleteNote')
 ORDER BY ORDINAL_POSITION;

SELECT '--- 4. do the SongCount triggers exist on this host? (D1) ---' AS section;
SELECT TRIGGER_NAME, EVENT_MANIPULATION, ACTION_TIMING
  FROM INFORMATION_SCHEMA.TRIGGERS
 WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = 'tblSongs'
 ORDER BY TRIGGER_NAME;

SELECT '--- 5. every FK to tblSongs(SongId) and its delete rule ---' AS section;
SELECT k.TABLE_NAME, k.COLUMN_NAME, k.CONSTRAINT_NAME, r.DELETE_RULE, r.UPDATE_RULE
  FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE k
  JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS r
    ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
   AND r.CONSTRAINT_NAME   = k.CONSTRAINT_NAME
 WHERE k.TABLE_SCHEMA = DATABASE()
   AND k.REFERENCED_TABLE_NAME = 'tblSongs'
   AND k.REFERENCED_COLUMN_NAME = 'SongId'
 ORDER BY r.DELETE_RULE, k.TABLE_NAME;
