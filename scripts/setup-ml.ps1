$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$runtimeRoot = Join-Path $projectRoot 'storage\ml\python'
New-Item -ItemType Directory -Force -Path $runtimeRoot | Out-Null
$pythonPath = Join-Path $runtimeRoot 'python.exe'
if (-not (Test-Path -LiteralPath $pythonPath)) {
    $archivePath = Join-Path $runtimeRoot 'python-runtime.zip'
    Invoke-WebRequest -UseBasicParsing -Uri 'https://www.python.org/ftp/python/3.12.10/python-3.12.10-embed-amd64.zip' -OutFile $archivePath
    Expand-Archive -LiteralPath $archivePath -DestinationPath $runtimeRoot -Force
    $pathFile = Join-Path $runtimeRoot 'python312._pth'
    @('python312.zip', '.', 'Lib\site-packages', 'import site') | Set-Content -LiteralPath $pathFile -Encoding ASCII
}
$env:PYTHONUTF8 = '1'
if (-not (Test-Path -LiteralPath (Join-Path $runtimeRoot 'Lib\site-packages\pip'))) {
    $installer = Join-Path $runtimeRoot 'get-pip.py'
    Invoke-WebRequest -UseBasicParsing -Uri 'https://bootstrap.pypa.io/get-pip.py' -OutFile $installer
    & $pythonPath $installer --no-warn-script-location --cache-dir (Join-Path $projectRoot 'storage\ml\pip-cache')
    if ($LASTEXITCODE -ne 0) { throw 'pip setup failed.' }
}
& $pythonPath -m pip install --disable-pip-version-check --no-warn-script-location --cache-dir (Join-Path $projectRoot 'storage\ml\pip-cache') torch==2.6.0 torchvision==0.21.0 --index-url https://download.pytorch.org/whl/cpu
if ($LASTEXITCODE -ne 0) { throw 'PyTorch installation failed.' }
& $pythonPath -m pip install --disable-pip-version-check --no-warn-script-location --cache-dir (Join-Path $projectRoot 'storage\ml\pip-cache') -r (Join-Path $projectRoot 'ml\requirements.txt')
if ($LASTEXITCODE -ne 0) { throw 'Model dependency installation failed.' }
Write-Host 'FruitScan model environment is ready in storage/ml/python.'
