param([int]$Port = 8766)
$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$pythonPath = Join-Path $projectRoot 'storage\ml\python\python.exe'
if (-not (Test-Path -LiteralPath $pythonPath)) { throw 'Run scripts/setup-ml.ps1 first.' }
if (-not (Test-Path -LiteralPath (Join-Path $projectRoot 'server\model-service.json'))) { throw 'Train and activate a model first. See ml/README.md.' }
$env:PYTHONUTF8 = '1'
& $pythonPath -u (Join-Path $projectRoot 'ml\serve.py') --port $Port
