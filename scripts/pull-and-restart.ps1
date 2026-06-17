param(
    [string]$RepoPath,
    [string]$ScriptName,
    [string]$RestartCommand,
    [string]$Remote = "origin",
    [string]$Branch = "main"
)

$ErrorActionPreference = "Stop"

if ([string]::IsNullOrWhiteSpace($RepoPath)) {
    throw "RepoPath is required."
}

if ([string]::IsNullOrWhiteSpace($ScriptName)) {
    throw "ScriptName is required."
}

if ([string]::IsNullOrWhiteSpace($RestartCommand)) {
    throw "RestartCommand is required."
}

$logDir = Join-Path $RepoPath "logs"
$safeScriptName = $ScriptName -replace '[^a-zA-Z0-9_.-]', '_'
$logPath = Join-Path $logDir "pull-and-restart-$safeScriptName.log"
$lockPath = Join-Path $logDir "pull-and-restart-$safeScriptName.lock"

function Write-Log {
    param([string]$Message)

    if (!(Test-Path -LiteralPath $logDir)) {
        New-Item -ItemType Directory -Force -Path $logDir | Out-Null
    }

    $timestamp = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
    Add-Content -LiteralPath $logPath -Value "[$timestamp] $Message"
}

function Invoke-Git {
    param([string[]]$Arguments)

    $previousErrorActionPreference = $ErrorActionPreference
    $ErrorActionPreference = "Continue"

    try {
        $output = & git @Arguments 2>&1
        $exitCode = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $previousErrorActionPreference
    }

    if ($output) {
        foreach ($line in $output) {
            Write-Log "git $($Arguments -join ' '): $line"
        }
    }

    if ($exitCode -ne 0) {
        throw "git $($Arguments -join ' ') failed with exit code $exitCode"
    }

    return $output
}

function Invoke-RestartCommand {
    $env:SCRIPT_NAME = $ScriptName

    Write-Log "Running restart command for ${ScriptName}: $RestartCommand"

    $previousErrorActionPreference = $ErrorActionPreference
    $ErrorActionPreference = "Continue"

    try {
        $output = cmd.exe /c $RestartCommand 2>&1
        $exitCode = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $previousErrorActionPreference
    }

    if ($output) {
        foreach ($line in $output) {
            Write-Log "restart output: $line"
        }
    }

    if ($exitCode -ne 0) {
        throw "Restart command failed with exit code $exitCode"
    }
}

if (!(Test-Path -LiteralPath $RepoPath)) {
    throw "RepoPath does not exist: $RepoPath"
}

if (!(Get-Command git -ErrorAction SilentlyContinue)) {
    throw "git.exe was not found in PATH"
}

if (!(Test-Path -LiteralPath $logDir)) {
    New-Item -ItemType Directory -Force -Path $logDir | Out-Null
}

if (Test-Path -LiteralPath $lockPath) {
    $lockAge = (Get-Date) - (Get-Item -LiteralPath $lockPath).LastWriteTime

    if ($lockAge.TotalMinutes -lt 15) {
        Write-Log "Skipped: another pull-and-restart run for $ScriptName is active."
        exit 0
    }

    Write-Log "Removing stale lock older than 15 minutes."
    Remove-Item -LiteralPath $lockPath -Force
}

try {
    Set-Content -LiteralPath $lockPath -Value ([System.Diagnostics.Process]::GetCurrentProcess().Id)
    Set-Location -LiteralPath $RepoPath

    Write-Log "Checking $Remote/$Branch for $ScriptName."

    Invoke-Git @("fetch", $Remote, $Branch) | Out-Null

    $localCommit = (& git rev-parse HEAD).Trim()
    $remoteCommit = (& git rev-parse "$Remote/$Branch").Trim()

    if ($localCommit -eq $remoteCommit) {
        Write-Log "No changes detected. Restart skipped."
        exit 0
    }

    $status = (& git status --porcelain=v1)

    if ($status) {
        $stamp = Get-Date -Format "yyyyMMdd-HHmmss"
        Write-Log "Local changes detected. Creating safety stash before pull."
        Invoke-Git @("stash", "push", "--include-untracked", "-m", "pull-and-restart-backup-$safeScriptName-$stamp") | Out-Null
    }

    Invoke-Git @("pull", "--ff-only", $Remote, $Branch) | Out-Null

    $newCommit = (& git rev-parse HEAD).Trim()
    Write-Log "Pull completed. Old HEAD: $localCommit. New HEAD: $newCommit."

    Invoke-RestartCommand
    Write-Log "Restart completed for $ScriptName."
} catch {
    Write-Log "ERROR: $($_.Exception.Message)"
    exit 1
} finally {
    if (Test-Path -LiteralPath $lockPath) {
        Remove-Item -LiteralPath $lockPath -Force
    }
}
