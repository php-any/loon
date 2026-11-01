param([string]$Python='python')
$ErrorActionPreference='Stop'
$root=Split-Path -Parent $PSScriptRoot
& $Python -m venv (Join-Path $root '.venv')
if ($LASTEXITCODE -ne 0) { throw 'Cannot create Python test environment' }
& (Join-Path $root '.venv/Scripts/python.exe') -m pip install -r (Join-Path $root 'tests/e2e/requirements.txt')
if ($LASTEXITCODE -ne 0) { throw 'Cannot install protocol test dependencies' }
