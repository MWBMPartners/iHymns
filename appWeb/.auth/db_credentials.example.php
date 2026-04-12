<?php

/**
 * iHymns — MySQL Database Credentials (EXAMPLE)
 *
 * Copy this file to db_credentials.php and fill in your MySQL details.
 * The real credentials file is excluded from version control via .gitignore.
 *
 * USAGE:
 *   require_once __DIR__ . '/db_credentials.php';
 *   // Then use DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS, DB_CHARSET
 */

define('DB_HOST',    '127.0.0.1');      // MySQL server hostname or IP
define('DB_PORT',    3306);              // MySQL server port (default: 3306)
define('DB_NAME',    'ihymns');          // Database name
define('DB_USER',    'ihymns_user');     // Database username
define('DB_PASS',    '');                // Database password
define('DB_CHARSET', 'utf8mb4');         // Character set (do not change)
