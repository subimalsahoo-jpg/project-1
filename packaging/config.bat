@echo off
REM =====================================================================
REM  Payroll System - central configuration
REM  Edit these values ONLY if you need different ports / DB settings.
REM  These MUST match db.php:  host=localhost  user=root  pass=(empty)
REM                            database=payroll_system  port=3306
REM =====================================================================

REM --- Web server (PHP built-in server) ---
set "APP_HOST=localhost"
set "APP_PORT=8080"
set "APP_START_PAGE=login.php"

REM --- Database (bundled MariaDB) ---
set "DB_PORT=3306"
set "DB_NAME=payroll_system"
set "DB_USER=root"
set "DB_PASS="
