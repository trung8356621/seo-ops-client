@echo off
setlocal
cd /d "%~dp0"

echo ========================================================
echo Fresh Client Full Service Bootstrap
echo ========================================================
echo Running: php artisan service:simulate --all --force
echo.

php artisan service:simulate --all --force
if %ERRORLEVEL% NEQ 0 (
    echo.
    echo ========================================================
    echo BOOTSTRAP FAILED with error code %ERRORLEVEL%
    echo ========================================================
    pause
    exit /b %ERRORLEVEL%
)

echo.
echo ========================================================
echo BOOTSTRAP SUCCESSFUL
echo ========================================================
pause
exit /b 0
