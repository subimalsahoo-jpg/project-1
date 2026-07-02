@echo off
REM =====================================================================
REM  First-run database setup (runs once, automatically, after install)
REM  - Initializes the bundled MariaDB data directory (system tables)
REM  - Starts a temporary DB instance
REM  - Creates the payroll database and imports the seed dump (db\payroll.sql)
REM  - Shuts the temporary instance down
REM
REM  Safe to run again: if the data dir already exists it will NOT be
REM  re-initialized or re-imported (your data is left untouched).
REM =====================================================================
setlocal enableextensions
set "BASE=%~dp0"
call "%BASE%config.bat"

set "MYSQLD=%BASE%mysql\bin\mysqld.exe"
set "MYSQL=%BASE%mysql\bin\mysql.exe"
set "MYSQLADMIN=%BASE%mysql\bin\mysqladmin.exe"
set "DATADIR=%BASE%mysql\data"
set "BASEDIR=%BASE%mysql"

if not exist "%MYSQLD%" (
    echo [ERROR] MariaDB not found at "%MYSQLD%".
    echo The installer did not copy the database engine. Aborting.
    exit /b 1
)

REM ---- Already set up?  (mysql system schema present) -----------------
if exist "%DATADIR%\mysql" (
    echo Database already initialized - skipping first-run setup.
    exit /b 0
)

echo.
echo === Initializing Payroll database (first run) ===
if not exist "%DATADIR%" mkdir "%DATADIR%"

REM ---- Initialize system tables (support both tool names) -------------
if exist "%BASE%mysql\bin\mariadb-install-db.exe" (
    "%BASE%mysql\bin\mariadb-install-db.exe" --datadir="%DATADIR%" --basedir="%BASEDIR%"
) else if exist "%BASE%mysql\bin\mysql_install_db.exe" (
    "%BASE%mysql\bin\mysql_install_db.exe" --datadir="%DATADIR%" --basedir="%BASEDIR%"
) else (
    echo [ERROR] No mariadb-install-db.exe / mysql_install_db.exe found.
    exit /b 1
)

REM ---- Start a temporary server --------------------------------------
echo Starting temporary database instance...
start "PayrollDBInit" /MIN "%MYSQLD%" --no-defaults --datadir="%DATADIR%" --basedir="%BASEDIR%" --lc-messages-dir="%BASEDIR%\share" --port=%DB_PORT% --bind-address=127.0.0.1

REM ---- Wait until it accepts connections (max ~60s) ------------------
set /a tries=0
:waitdb
"%MYSQLADMIN%" --port=%DB_PORT% -h 127.0.0.1 -u %DB_USER% ping >nul 2>&1
if not errorlevel 1 goto dbup
set /a tries+=1
if %tries% GEQ 30 (
    echo [ERROR] Database did not start in time.
    exit /b 1
)
timeout /t 2 /nobreak >nul
goto waitdb

:dbup
echo Creating database "%DB_NAME%" and importing data...
"%MYSQL%" --port=%DB_PORT% -h 127.0.0.1 -u %DB_USER% -e "CREATE DATABASE IF NOT EXISTS %DB_NAME% CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"

if exist "%BASE%db\payroll.sql" (
    "%MYSQL%" --port=%DB_PORT% -h 127.0.0.1 -u %DB_USER% %DB_NAME% < "%BASE%db\payroll.sql"
    echo Seed data imported from db\payroll.sql
) else (
    echo [WARN] db\payroll.sql not found - created an EMPTY "%DB_NAME%" database.
)

REM ---- Shut the temporary server down cleanly ------------------------
echo Shutting temporary database instance down...
"%MYSQLADMIN%" --port=%DB_PORT% -h 127.0.0.1 -u %DB_USER% shutdown
timeout /t 3 /nobreak >nul

echo.
echo === Database setup complete ===
exit /b 0
