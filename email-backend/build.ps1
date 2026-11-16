# Builds the cPanel upload package: build/nexgen-email-backend.zip
#
#   powershell -ExecutionPolicy Bypass -File build.ps1           (includes your .env)
#   powershell -ExecutionPolicy Bypass -File build.ps1 -NoEnv    (leaves .env out)
#
# The zip mirrors your cPanel home folder. Extract it in the home folder (the
# level above public_html): email-backend/ stays private, and only
# public_html/api/send-mail.php becomes public.

param([switch]$NoEnv)

$ErrorActionPreference = 'Stop'
$root = $PSScriptRoot
$out = Join-Path (Split-Path $root -Parent) 'dist/publish'
$stage = Join-Path $out 'package'
$app = Join-Path $stage 'public_html/api'
$warnings = @()

if (-not (Test-Path (Join-Path $root 'vendor\autoload.php'))) {
    throw 'vendor/ is missing. Run "composer install --no-dev" first (README section 2).'
}

if (Test-Path $out) { Get-ChildItem $out -Force | Remove-Item -Recurse -Force -Confirm:$false }
New-Item -ItemType Directory -Force -Path $app | Out-Null

Copy-Item (Join-Path $root 'api/*') $app -Force
foreach ($dir in 'config', 'helpers', 'services', 'vendor') {
    Copy-Item (Join-Path $root $dir) $app -Recurse
}
foreach ($file in '.env.example', 'composer.json', 'composer.lock', 'README.md') {
    Copy-Item (Join-Path $root $file) $app
}

$envFile = Join-Path $root '.env'
if ($NoEnv) {
    $warnings += 'No .env included. Create public_html/api/.env on the server from .env.example.'
} elseif (Test-Path $envFile) {
    Copy-Item $envFile $app
    $warnings += 'The zip contains your .env with the SMTP password. Do not share it or commit it.'
    $envText = Get-Content $envFile -Raw
    $origins = if ($envText -match '(?m)^ALLOWED_ORIGINS=(.*)$') { $Matches[1].Trim() } else { '' }
    if ($origins -eq '') {
        $warnings += 'ALLOWED_ORIGINS is empty in .env, so browsers on your site will get a 403.'
    } else {
        $warnings += "Check ALLOWED_ORIGINS is your live site: $origins"
    }
    if ($origins -match 'yourdomai|localhost|127\.0\.0\.1|http://') {
        $warnings += 'ALLOWED_ORIGINS has a placeholder, local or non-HTTPS address. Replace it with your live https:// site before going live.'
    }
    if ($envText -match '(?m)^SMTP_ENCRYPTION=none') {
        $warnings += 'SMTP_ENCRYPTION is none. Use tls or ssl in production.'
    }
} else {
    $warnings += 'No .env found, so none was included. Create public_html/api/.env on the server from .env.example.'
}

$placeholders = Select-String -Path (Join-Path $root 'config\regions.php') -Pattern "'(\w+)'\s*=>\s*'[^']*@example\.com'" -AllMatches |
    ForEach-Object { $_.Matches } | ForEach-Object { $_.Groups[1].Value }
if ($placeholders) {
    $warnings += "Placeholder recipients in config/regions.php for: $($placeholders -join ', '). Those regions will return a 500."
}

# Build the zip with forward-slash entry names. Compress-Archive in Windows
# PowerShell 5.1 writes backslashes, which Linux servers extract as file names.
Add-Type -AssemblyName System.IO.Compression, System.IO.Compression.FileSystem
$zipPath = Join-Path $out 'nexgen-email-backend.zip'
$stageFull = (Get-Item $stage).FullName.TrimEnd('\')
$zip = [System.IO.Compression.ZipFile]::Open($zipPath, 'Create')
try {
    Get-ChildItem $stageFull -Recurse -File -Force | ForEach-Object {
        $entry = $_.FullName.Substring($stageFull.Length + 1).Replace('\', '/')
        [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($zip, $_.FullName, $entry, 'Optimal') | Out-Null
    }
} finally {
    $zip.Dispose()
}

Write-Host "Built $zipPath ($([math]::Round((Get-Item $zipPath).Length / 1KB)) KB)"
foreach ($w in $warnings) { Write-Host "WARNING: $w" -ForegroundColor Yellow }
