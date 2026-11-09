$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$manifestPath = Join-Path $projectRoot 'server\model.json'
if (-not (Test-Path -LiteralPath $manifestPath)) { throw 'Train and activate a model first.' }
$manifest = Get-Content -LiteralPath $manifestPath -Raw | ConvertFrom-Json
try { $health = Invoke-RestMethod -Uri 'http://127.0.0.1:8766/health' -TimeoutSec 2 } catch { $health = $null }
if ($health -and $health.ready) {
    if ($health.version -ne $manifest.version) { throw 'A different model is running on port 8766. Stop it before starting the new model.' }
    Write-Host "FruitScan model is already running: $($health.version)"
    exit 0
}
$pythonPath = Join-Path $projectRoot 'storage\ml\python\python.exe'
$servicePath = Join-Path $projectRoot 'ml\serve.py'
$logDirectory = Join-Path $projectRoot 'storage\ml'
$env:PYTHONUTF8 = '1'
$service = Start-Process -FilePath $pythonPath -ArgumentList @('-u', ('"' + $servicePath + '"')) -WorkingDirectory $projectRoot -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $logDirectory 'service-output.log') -RedirectStandardError (Join-Path $logDirectory 'service-error.log')
$service.Id | Set-Content -LiteralPath (Join-Path $logDirectory 'service.pid')
for ($attempt = 0; $attempt -lt 40; $attempt++) {
    Start-Sleep -Milliseconds 500
    try {
        $health = Invoke-RestMethod -Uri 'http://127.0.0.1:8766/health' -TimeoutSec 1
        if ($health.ready -and $health.version -eq $manifest.version) {
            Write-Host "FruitScan model is ready: $($health.version) (PID $($service.Id))"
            exit 0
        }
    } catch { }
    if ($service.HasExited) { throw 'The model service stopped. Check storage/ml/service-error.log.' }
}
throw 'The model service has not reported ready yet. Check storage/ml/service-error.log.'
