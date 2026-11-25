@echo off
REM -------------------------------------------------
REM Lighthouse audit – baseline (before)
REM -------------------------------------------------
lighthouse http://localhost/coba/ ^
  --output html --output-path %cd%\audit\lighthouse-before.html ^
  --quiet ^
  --chrome-flags="--headless"

REM -------------------------------------------------
REM Setelah perbaikan, jalankan lagi (after)
REM -------------------------------------------------
lighthouse http://localhost/coba/ ^
  --output html --output-path %cd%\audit\lighthouse-after.html ^
  --quiet ^
  --chrome-flags="--headless"