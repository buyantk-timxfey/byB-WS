# Smoke check before building a patch: lint all PHP and JS files.
# Run: powershell -File tools\smoke.ps1
$root = Split-Path $PSScriptRoot -Parent
$fail = 0

Write-Host "-- PHP --"
$php = Get-ChildItem -Path "$root\api\*.php", "$root\pages\*.php", "$root\*.php" -File |
    Where-Object { $_.FullName -notmatch '\\deploy\\|\\backups' }
foreach ($f in $php) {
    $out = & C:\xampp\php\php.exe -l $f.FullName 2>&1
    if ($LASTEXITCODE -ne 0) { Write-Host "FAIL: $($f.FullName)`n$out" -ForegroundColor Red; $fail++ }
}

Write-Host "-- JS --"
foreach ($f in Get-ChildItem "$root\assets\js\*.js" -File) {
    node --check $f.FullName 2>$null
    if ($LASTEXITCODE -ne 0) {
        Write-Host "FAIL: $($f.FullName)" -ForegroundColor Red; $fail++
    }
}

if ($fail -eq 0) { Write-Host "OK: all files passed" -ForegroundColor Green; exit 0 }
else { Write-Host "Failures: $fail" -ForegroundColor Red; exit 1 }
