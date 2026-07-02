@echo off
REM =====================================================================
REM  Stop Payroll System
REM  - Cleanly shuts down the bundled MariaDB
REM  - Stops the PHP built-in web server
REM =====================================================================
setlocal enableextensions
set "BASE=%~dp0"
call "%BASE%config.bat"

set "MYSQLADMIN=%BASE%mysql\bin\mysqladmin.exe"

echo Stopping database...
"%MYSQLADMIN%" --port=%DB_PORT% -h 127.0.0.1 -u %DB_USER% shutdown >nul 2>&1

echo Stopping web server...
REM Stop the PHP server window we started (title "PayrollWeb").
taskkill /FI "WINDOWTITLE eq PayrollWeb*" /T /F >nul 2>&1
REM Fallback: stop the built-in server listening on our port.
for /f "tokens=5" %%P in ('netstat -ano ^| findstr ":%APP_PORT% " ^| findstr LISTENING') do (
    taskkill /PID %%P /F >nul 2>&1
)

echo.
echo Payroll System stopped.
timeout /t 2 /nobreak >nul
exit /b 0
