@echo off
rem Builds cbee-product-filters.zip, ready to upload via Plugins -> Add New -> Upload.
rem Uses tar (included in Windows 10+). Compress-Archive is avoided: Windows PowerShell 5.1
rem writes backslash paths into the zip, which breaks extraction on Linux servers.
cd /d "%~dp0"
if exist cbee-product-filters.zip del cbee-product-filters.zip
tar -a -c -f cbee-product-filters.zip cbee-product-filters
if errorlevel 1 (echo Build failed & exit /b 1)
echo Built cbee-product-filters.zip
