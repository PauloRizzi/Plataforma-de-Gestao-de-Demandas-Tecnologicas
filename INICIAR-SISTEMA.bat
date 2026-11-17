@echo off
setlocal
rem Liga o PGDT em http://localhost:8000 e abre o navegador. Para desligar, feche esta janela.

"C:\xampp\mysql\bin\mysql.exe" -u root -e "select 1" >nul 2>nul || (
    echo ERRO: o MySQL esta desligado. Abra o XAMPP Control Panel, clique em Start no MySQL e rode este arquivo de novo.
    pause
    exit /b 1
)

cd /d "%~dp0sistema"
if not exist vendor (
    echo ERRO: o sistema ainda nao foi instalado. Siga o passo a passo em docs\INSTALACAO.md primeiro.
    pause
    exit /b 1
)

echo.
echo PGDT ligado em http://localhost:8000
echo Para desligar, feche esta janela.
echo.
start "" cmd /c "timeout /t 4 >nul & start http://localhost:8000"
php artisan serve --port=8000
pause
