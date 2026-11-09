param([Parameter(Mandatory=$true)][string]$Email, [string]$Name = 'FruitScan Administrator')
$ErrorActionPreference = 'Stop'
$phpCommand = Get-Command php -ErrorAction SilentlyContinue
$phpPath = if ($phpCommand) { $phpCommand.Source } else { 'C:\xamppni\php\php.exe' }
$securePassword = Read-Host 'Administrator password (10-72 characters)' -AsSecureString
$passwordPointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($securePassword)
try {
    $env:FRUITSCAN_ADMIN_PASSWORD = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($passwordPointer)
    & $phpPath (Join-Path $PSScriptRoot 'admin.php') $Email $Name
} finally {
    [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($passwordPointer)
    Remove-Item Env:\FRUITSCAN_ADMIN_PASSWORD -ErrorAction SilentlyContinue
}
