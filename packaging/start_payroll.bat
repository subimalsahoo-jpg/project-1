@echo off
REM =====================================================================
REM  Start Payroll System
REM  - Starts the bundled MariaDB (background)
REM  - Starts the PHP built-in web server (background)
REM  - Opens the app in the default browser
REM  Use stop_payroll.bat to shut everything down.
REM =====================================================================
setlocal enableextensions
set "BASE=%~dp0"
call "%BASE%config.bat"

set "MYSQLD=%BASE%mysql\bin\mysqld.exe"
set "MYSQLADMIN=%BASE%mysql\bin\mysqladmin.exe"
set "PHP=%BASE%php\php.exe"
set "DATADIR=%BASE%mysql\data"
set "BASEDIR=%BASE%mysql"

REM ---- Make sure first-run setup has been done -----------------------
if not exist "%DATADIR%\mysql" (
    echo First run detected - setting up the database...
    call "%BASE%firstrun_setup.bat"
)

REM ---- Start database if not already accepting connections -----------
"%MYSQLADMIN%" --port=%DB_PORT% -h 127.0.0.1 -u %DB_USER% ping >nul 2>&1
if errorlevel 1 (
    echo Starting database...
    start "PayrollDB" /MIN "%MYSQLD%" --no-defaults --datadir="%DATADIR%" --basedir="%BASEDIR%" --lc-messages-dir="%BASEDIR%\share" --port=%DB_PORT% --bind-address=127.0.0.1
) else (
    echo Database already running.
)

REM ---- Wait for the database (max ~40s) ------------------------------
set /a tries=0
:waitdb
"%MYSQLADMIN%" --port=%DB_PORT% -h 127.0.0.1 -u %DB_USER% ping >nul 2>&1
if not errorlevel 1 goto dbup
set /a tries+=1
if %tries% GEQ 20 (
    echo [ERROR] Database did not start. Check that port %DB_PORT% is free.
    pause
    exit /b 1
)
timeout /t 2 /nobreak >nul
goto waitdb

:dbup
REM ---- Start the PHP web server --------------------------------------
echo Starting web server on http://%APP_HOST%:%APP_PORT% ...
start "PayrollWeb" /MIN "%PHP%" -S %APP_HOST%:%APP_PORT% -t "%BASE%app"

timeout /t 2 /nobreak >nul

REM ---- Open the app in the browser -----------------------------------
start "" "http://%APP_HOST%:%APP_PORT%/%APP_START_PAGE%"

echo.
echo Payroll System is running.
echo   Web : http://%APP_HOST%:%APP_PORT%/%APP_START_PAGE%
echo   DB  : 127.0.0.1:%DB_PORT%  (database: %DB_NAME%)
echo.
echo Leave this window open, or close it - the servers keep running.
echo To stop everything, run stop_payroll.bat
exit /b 0
