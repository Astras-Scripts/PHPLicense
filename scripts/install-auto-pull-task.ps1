param(
    [string]$TaskName = "PHPLicense Auto Pull",
    [string]$RepoPath = "C:\xampp\htdocs\PHPLicense",
    [int]$IntervalMinutes = 1,
    [string]$Branch = "",
    [string]$ScriptName = "",
    [string]$ScriptVersion = ""
)

$ErrorActionPreference = "Stop"

$scriptPath = Join-Path $RepoPath "scripts\auto-pull.ps1"

if (!(Test-Path -LiteralPath $scriptPath)) {
    throw "Auto-pull script does not exist: $scriptPath"
}

$argumentParts = @(
    "-NoProfile",
    "-ExecutionPolicy Bypass",
    "-File `"$scriptPath`""
)

if (-not [string]::IsNullOrWhiteSpace($Branch)) {
    $argumentParts += "-Branch `"$Branch`""
}

if (-not [string]::IsNullOrWhiteSpace($ScriptName)) {
    $argumentParts += "-ScriptName `"$ScriptName`""
}

if (-not [string]::IsNullOrWhiteSpace($ScriptVersion)) {
    $argumentParts += "-ScriptVersion `"$ScriptVersion`""
}

$action = New-ScheduledTaskAction `
    -Execute "powershell.exe" `
    -Argument ($argumentParts -join ' ')

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
