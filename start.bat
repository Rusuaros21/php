@echo off
setlocal
cd /d "%~dp0"

where php >nul 2>nul
if errorlevel 1 (
    echo [ERRO] PHP nao foi encontrado no PATH.
    echo Baixe em https://windows.php.net/download/ e adicione a pasta do PHP
    echo as variaveis de ambiente do Windows.
    echo.
    pause
    exit /b 1
)

where nmap >nul 2>nul
if errorlevel 1 (
    echo [AVISO] nmap nao encontrado. O monitor ainda funciona, mas fica mais
    echo lento e menos completo sem ele. Recomendado: https://nmap.org/download.html#windows
    echo.
)

set PORT=8080
set URL=http://127.0.0.1:%PORT%/

echo ============================================
echo   Monitor de Rede em Tempo Real
echo ============================================
echo Iniciando servidor em %URL%
echo O navegador vai abrir automaticamente em alguns segundos.
echo ============================================
echo.

start "Monitor de Rede - servidor (nao feche)" /min cmd /c "php -S 127.0.0.1:%PORT% -t public"

ping -n 3 127.0.0.1 >nul

start "" "%URL%"

echo O monitor esta rodando em segundo plano (janela minimizada
echo "Monitor de Rede - servidor").
echo.
echo Pressione qualquer tecla nesta janela para PARAR o monitor.
pause >nul

taskkill /fi "WindowTitle eq Monitor de Rede - servidor*" /t /f >nul 2>nul
echo.
echo Monitor encerrado.
timeout /t 2 >nul
