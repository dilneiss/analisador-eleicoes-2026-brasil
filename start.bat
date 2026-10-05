@echo off
title Eleicoes 2026 - Monitor TSE
cd /d "%~dp0"
set "PHP_CMD=php"
where php >nul 2>&1
if errorlevel 1 (
  if exist "%USERPROFILE%\.config\herd\bin\php.bat" (
    set "PHP_CMD=%USERPROFILE%\.config\herd\bin\php.bat"
  ) else if exist "%USERPROFILE%\.config\herd\bin\php84\php.exe" (
    set "PHP_CMD=%USERPROFILE%\.config\herd\bin\php84\php.exe"
  ) else (
    echo PHP nao encontrado no PATH nem no Herd.
    echo Instale PHP 8+ com cURL habilitado.
    pause
    exit /b 1
  )
)
start "" http://127.0.0.1:8080/index.html
"%PHP_CMD%" -S 127.0.0.1:8080
