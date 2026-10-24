@echo off
rem Double-click to start the BIGKAS-AI engine and scheduler together, in this one window.
rem   start-bigkas.bat               engine + scheduler
rem   start-bigkas.bat -NoScheduler   engine only (single-child assessments)
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0start-bigkas.ps1" %*
if errorlevel 1 pause
