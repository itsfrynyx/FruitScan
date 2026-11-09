param([int]$Port = 8765)
$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$phpCommand = Get-Command php -ErrorAction SilentlyContinue
$phpPath = if ($phpCommand) { $phpCommand.Source } else { 'C:\xamppni\php\php.exe' }
if (-not (Test-Path -LiteralPath $phpPath)) { throw 'PHP was not found. Add PHP 8.2 or newer to PATH.' }
if (Test-Path -LiteralPath (Join-Path $projectRoot 'server\model-service.json')) {
    & (Join-Path $PSScriptRoot 'start-model-background.ps1')
    if ($LASTEXITCODE -ne 0) { throw 'The trained model service could not start.' }
}
Write-Host "FruitScan: http://localhost:$Port"
Write-Host 'Press Ctrl+C to stop the development server.'
Push-Location $projectRoot
try { & $phpPath -d upload_max_filesize=10M -d post_max_size=12M -d display_errors=0 -S "localhost:$Port" router.php }
finally { Pop-Location }
