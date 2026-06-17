param(
    [string]$TaskName = "PHPLicense Auto Pull",
    [string]$RepoPath = "C:\xampp\htdocs\PHPLicense",
    [int]$IntervalMinutes = 1
)

$ErrorActionPreference = "Stop"

$scriptPath = Join-Path $RepoPath "scripts\auto-pull.ps1"

if (!(Test-Path -LiteralPath $scriptPath)) {
    throw "Auto-pull script does not exist: $scriptPath"
}

$action = New-ScheduledTaskAction `
    -Execute "powershell.exe" `
    -Argument "-NoProfile -ExecutionPolicy Bypass -File `"$scriptPath`""

$trigger = New-ScheduledTaskTrigger `
    -Once `
    -At (Get-Date).AddMinutes(1) `
    -RepetitionInterval (New-TimeSpan -Minutes $IntervalMinutes) `
    -RepetitionDuration (New-TimeSpan -Days 3650)

$settings = New-ScheduledTaskSettingsSet `
    -AllowStartIfOnBatteries `
    -DontStopIfGoingOnBatteries `
    -StartWhenAvailable `
    -MultipleInstances IgnoreNew

Register-ScheduledTask `
    -TaskName $TaskName `
    -Action $action `
    -Trigger $trigger `
    -Settings $settings `
    -Description "Automatically pulls $RepoPath from GitHub." `
    -Force | Out-Null

Write-Host "Installed scheduled task: $TaskName"
