@echo off
setlocal
title RepoChronos
cd /d "%~dp0"

set "RC_PHP="
if defined REPOCHRONOS_PHP (
    call :try_php "%REPOCHRONOS_PHP%"
    if not defined RC_PHP (
        echo REPOCHRONOS_PHP must point to PHP 8.4.1 or newer with pdo_pgsql enabled.
        goto :failed
    )
)
for /f "delims=" %%P in ('where php 2^>nul') do call :try_php "%%P"
if not defined RC_PHP if exist "%~dp0.local\php\php.exe" call :try_php "%~dp0.local\php\php.exe"
if not defined RC_PHP if exist "%LOCALAPPDATA%\SubscriptionManagement\php\php.exe" call :try_php "%LOCALAPPDATA%\SubscriptionManagement\php\php.exe"
if not defined RC_PHP (
    echo No compatible PHP was found. PHP 8.4.1 or newer with pdo_pgsql is required.
    echo Herd Lite may not include the PostgreSQL driver. Install PHP with pdo_pgsql,
    echo then add it to PATH or set REPOCHRONOS_PHP to its full php.exe path.
    goto :failed
)
for %%P in ("%RC_PHP%") do set "PATH=%%~dpP;%PATH%"
if not exist "vendor\autoload.php" (
    echo Dependencies are missing. Run composer install in this folder first.
    goto :failed
)
if not exist ".env" (
    echo Configuration is missing. Copy .env.example to .env and configure Supabase.
    echo Follow README.md before starting the application.
    goto :failed
)

echo Checking Supabase, Python and application requirements...
"%RC_PHP%" -r "echo 'Using PHP: ', PHP_BINARY, PHP_EOL;"
"%RC_PHP%" artisan repochronos:doctor
if errorlevel 1 goto :failed
if /i "%~1"=="--check" exit /b 0

powershell -NoProfile -Command "try { $r = Invoke-WebRequest 'http://127.0.0.1:8000/login' -UseBasicParsing -TimeoutSec 5; if ($r.Content -match 'RepoChronos') { exit 0 }; exit 1 } catch { exit 1 }" >nul 2>nul
if not errorlevel 1 (
    echo RepoChronos is already running.
    start "" "http://127.0.0.1:8000"
    exit /b 0
)

echo Starting RepoChronos at http://127.0.0.1:8000
echo Keep this window open. Press Ctrl+C to stop the server.
start "" "http://127.0.0.1:8000"
"%RC_PHP%" artisan serve --host=127.0.0.1 --port=8000 --tries=1 --no-reload
if errorlevel 1 goto :failed
exit /b 0

:failed
echo.
echo RepoChronos could not start. Check the message above and README.md.
if /i not "%~1"=="--check" pause
exit /b 1

:try_php
if defined RC_PHP exit /b 0
if not exist "%~1" exit /b 0
"%~1" -r "exit(PHP_VERSION_ID >= 80401 && extension_loaded('pdo_pgsql') ? 0 : 1);" >nul 2>nul
if errorlevel 1 exit /b 0
set "RC_PHP=%~1"
exit /b 0
