function Resolve-LicenseBranch {
    param(
        [string]$ScriptName,
        [string]$ScriptVersion,
        [string]$RequestedBranch = "",
        [string]$LegacyBranch = "main",
        [string]$ModernBranch = "license-devrequest",
        [string]$VersionCutoff = "1.1.0.5",
        [string]$UsedCarsName = "ast3ra_used_cars_v3"
    )

    $requested = ""
    if ($null -ne $RequestedBranch) {
        $requested = $RequestedBranch.Trim()
    }
    if (-not [string]::IsNullOrWhiteSpace($requested)) {
        return [pscustomobject]@{
            Branch = $requested
            Reason = "explicit branch override"
        }
    }

    $name = ""
    if ($null -ne $ScriptName) {
        $name = $ScriptName.Trim()
    }
    if (-not [string]::IsNullOrWhiteSpace($name) -and $name -ieq $UsedCarsName) {
        return [pscustomobject]@{
            Branch = $ModernBranch
            Reason = "used cars v3 exception"
        }
    }

    $versionText = ""
    if ($null -ne $ScriptVersion) {
        $versionText = $ScriptVersion.Trim()
    }
    if ([string]::IsNullOrWhiteSpace($versionText)) {
        return [pscustomobject]@{
            Branch = $LegacyBranch
            Reason = "no script version supplied"
        }
    }

    try {
        $current = [version]$versionText
        $cutoff = [version]$VersionCutoff

        if ($current -gt $cutoff) {
            return [pscustomobject]@{
                Branch = $ModernBranch
                Reason = "version above cutoff"
            }
        }

        return [pscustomobject]@{
            Branch = $LegacyBranch
            Reason = "version at or below cutoff"
        }
    } catch {
        if ($versionText -match '^\s*3\.' -or $versionText -match '(^|\.)3(\.|$)') {
            return [pscustomobject]@{
                Branch = $ModernBranch
                Reason = "version parse fallback for v3"
            }
        }

        return [pscustomobject]@{
            Branch = $LegacyBranch
            Reason = "version parse fallback"
        }
    }
}
