@echo off
cd /d "c:\laragon\www\PT-9"
"C:\laragon\bin\php\php-8.3.26-Win32-vs16-x64\php.exe" artisan schedule:run >> "c:\laragon\www\PT-9\storage\logs\schedule.log" 2>&1
