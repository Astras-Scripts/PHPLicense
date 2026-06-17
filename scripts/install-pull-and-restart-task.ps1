param(
    [string]$TaskName,
    [string]$RepoPath,
    [string]$ScriptName,
    [string]$RestartCommand,
    [int]$IntervalMinutes = 1
)

$ErrorActionPreference = "Stop"

if ([string]::IsNullOrWhiteSpace($TaskName)) {
    $TaskName = "Auto Pull Restart - $ScriptName"
}

if ([string]::IsNullOrWhiteSpace($RepoPath)) {
    throw "RepoPath is required."
}

if ([string]::IsNullOrWhiteSpace($ScriptName)) {
    throw "ScriptName is required."
}

if ([string]::IsNullOrWhiteSpace($RestartCommand)) {
    throw "RestartCommand is required."
}

$scriptPath = Join-Path $RepoPath "scripts\pull-and-restart.ps1"

if (!(Test-Path -LiteralPath $scriptPath)) {
    throw "Pull-and-restart script does not exist: $scriptPath"
}

$argument = "-NoProfile -ExecutionPolicy Bypass -File `"$scriptPath`" -RepoPath `"$RepoPath`" -ScriptName `"$ScriptName`" -RestartCommand `"$RestartCommand`""

$action = New-ScheduledTaskAction `
    -Execute "powershell.exe" `
    -Argument $argument

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
    -Description "Automatically pulls $RepoPath and restarts $ScriptName when a new commit is detected." `
    -Force | Out-Null

Write-Host "Installed scheduled task: $TaskName"
