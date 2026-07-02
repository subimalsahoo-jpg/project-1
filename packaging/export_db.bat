@echo off
REM =====================================================================
REM  Export the CURRENT database into a seed file (db\payroll.sql)
REM
REM  RUN THIS ON YOUR CURRENT (SOURCE) MACHINE, where XAMPP + your live
REM  payroll_system database live. It only READS the database (mysqldump);
REM  it does NOT modify or delete anything.
REM
REM  The produced payroll.sql is what the installer ships as seed data.
REM  If you want to ship an EMPTY app (no employees/data), skip this and
REM  let the installer create an empty database instead.
REM =====================================================================
setlocal enableextensions

REM --- Adjust if your XAMPP is somewhere else ---
set "XAMPP=C:\xampp"
set "DB_NAME=payroll_system"
set "DB_USER=root"
set "DB_PASS="

set "DUMP=%XAMPP%\mysql\bin\mysqldump.exe"
set "OUT=%~dp0db\payroll.sql"

if not exist "%DUMP%" (
    echo [ERROR] mysqldump not found at "%DUMP%".
    echo Edit XAMPP path at the top of this file.
    pause
    exit /b 1
)

if not exist "%~dp0db" mkdir "%~dp0db"

echo Exporting %DB_NAME% ...
if "%DB_PASS%"=="" (
    "%DUMP%" -u %DB_USER% --single-transaction --routines --events --default-character-set=utf8mb4 %DB_NAME% > "%OUT%"
) else (
    "%DUMP%" -u %DB_USER% -p%DB_PASS% --single-transaction --routines --events --default-character-set=utf8mb4 %DB_NAME% > "%OUT%"
)

if errorlevel 1 (
    echo [ERROR] Export failed.
    pause
    exit /b 1
)

echo.
echo Done. Seed written to:
echo   %OUT%
echo You can now build the installer (Installer.iss).
pause
exit /b 0
