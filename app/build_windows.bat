@echo off
echo === Build TesKompetensi.exe ===

pip install pyinstaller >nul 2>&1

pyinstaller app.spec --noconfirm --clean

echo.
echo === Menyalin file data ke dist\ ===
copy /Y ..\bank_soal.json dist\TesKompetensi\bank_soal.json 2>nul
copy /Y ..\bank_soal.json dist\bank_soal.json 2>nul

echo.
echo === Selesai ===
echo Jalankan: dist\TesKompetensi.exe
pause
