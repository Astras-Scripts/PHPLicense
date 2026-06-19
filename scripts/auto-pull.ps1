param(
    [string]$RepoPath = "C:\xampp\htdocs\PHPLicense",
    [string]$Remote = "origin",
    [string]$Branch = "",
    [string]$ScriptName = "",
    [string]$ScriptVersion = "",
    [string]$LegacyBranch = "main",
    [string]$ModernBranch = "license-devrequest",
    [string]$VersionCutoff = "1.1.0.5",
    [string]$UsedCarsName = "ast3ra_used_cars_v3"
)

$ErrorActionPreference = "Stop"

$logDir = Join-Path $RepoPath "logs"
$logPath = Join-Path $logDir "auto-pull.log"
$lockPath = Join-Path $logDir "auto-pull.lock"

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

if (!(Test-Path -LiteralPath $RepoPath)) {
    throw "RepoPath does not exist: $RepoPath"
}

if (!(Get-Command git -ErrorAction SilentlyContinue)) {
    throw "git.exe was not found in PATH"
}

. (Join-Path $PSScriptRoot "license-branch-routing.ps1")

$branchInfo = Resolve-LicenseBranch `
    -ScriptName $ScriptName `
    -ScriptVersion $ScriptVersion `
    -RequestedBranch $Branch `
    -LegacyBranch $LegacyBranch `
    -ModernBranch $ModernBranch `
    -VersionCutoff $VersionCutoff `
    -UsedCarsName $UsedCarsName

$Branch = $branchInfo.Branch

if (!(Test-Path -LiteralPath $logDir)) {
    New-Item -ItemType Directory -Force -Path $logDir | Out-Null
}

if (Test-Path -LiteralPath $lockPath) {
    $lockAge = (Get-Date) - (Get-Item -LiteralPath $lockPath).LastWriteTime

    if ($lockAge.TotalMinutes -lt 15) {
        Write-Log "Skipped: another auto-pull run is active."
        exit 0
    }

    Write-Log "Removing stale lock older than 15 minutes."
    Remove-Item -LiteralPath $lockPath -Force
}

try {
    Set-Content -LiteralPath $lockPath -Value ([System.Diagnostics.Process]::GetCurrentProcess().Id)
    Set-Location -LiteralPath $RepoPath

    Write-Log "Starting auto-pull for $Remote/$Branch. Rule: $($branchInfo.Reason)"

    Invoke-Git @("fetch", $Remote, $Branch) | Out-Null

    $localCommit = (& git rev-parse HEAD).Trim()
    $remoteCommit = (& git rev-parse "$Remote/$Branch").Trim()

    if ($localCommit -eq $remoteCommit) {
        Write-Log "Already up to date at $localCommit."
        exit 0
    }

    $status = (& git status --porcelain=v1)

    if ($status) {
        $stamp = Get-Date -Format "yyyyMMdd-HHmmss"
        Write-Log "Local changes detected. Creating safety stash before pull."
        Invoke-Git @("stash", "push", "--include-untracked", "-m", "auto-pull-backup-$stamp") | Out-Null
    }

    Invoke-Git @("pull", "--ff-only", $Remote, $Branch) | Out-Null

    $newCommit = (& git rev-parse HEAD).Trim()
    Write-Log "Pull completed. New HEAD: $newCommit."
} catch {
    Write-Log "ERROR: $($_.Exception.Message)"
    exit 1
} finally {
    if (Test-Path -LiteralPath $lockPath) {
        Remove-Item -LiteralPath $lockPath -Force
    }
}
