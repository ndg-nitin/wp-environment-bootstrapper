@echo off
rem wp-env - Windows wrapper for the environment bootstrapper.
rem Usage: bin\wp-env.cmd --dry-run

setlocal
set "ROOT=%~dp0.."
wp --require="%ROOT%\setup.php" setup %*
exit /b %ERRORLEVEL%
