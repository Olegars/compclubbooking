# Hikvision NVR marker agent (club LAN) — DS-7764NI-M4 / DS-77xxNI-M4
# Polls cloud queue and PUTs ISAPI record tags (HTTP Digest) to the NVR.
#
# Usage (PowerShell):
#   $env:VIDEO_API_BASE = "https://your-club.example"
#   $env:VIDEO_MARKER_TOKEN = "same-as-VIDEO_MARKER_RELAY_TOKEN-or-CLUB_WOL_RELAY_TOKEN"
#   $env:VIDEO_POLL_SECONDS = "3"
#   powershell -ExecutionPolicy Bypass -File scripts\hikvision-marker-agent.ps1
#
# NVR IP / login / password come from admin /admin/video-surveillance (in the pull payload).
# Requires curl.exe (Windows 10+). Run as Scheduled Task on a PC in the club LAN.
# ffmpeg pulls one clip per cycle: assembly bench OR a hall incident episode, never both.
# FACE-01 also decodes 2–3 RTSP substreams; a second ffmpeg spikes RAM and drops frames.

$ErrorActionPreference = "Stop"

$ApiBase = ($env:VIDEO_API_BASE -replace '/$', '')
if (-not $ApiBase) { throw "Set VIDEO_API_BASE (e.g. https://0451.space)" }

$Token = $env:VIDEO_MARKER_TOKEN
if (-not $Token) { throw "Set VIDEO_MARKER_TOKEN" }

$PollSeconds = if ($env:VIDEO_POLL_SECONDS) { [int]$env:VIDEO_POLL_SECONDS } else { 3 }

$curl = Get-Command curl.exe -ErrorAction SilentlyContinue
if (-not $curl) { throw "curl.exe not found (needed for HTTP Digest)" }

$ffmpeg = Get-Command ffmpeg.exe -ErrorAction SilentlyContinue
if (-not $ffmpeg) {
    $ffmpeg = Get-Command ffmpeg -ErrorAction SilentlyContinue
}

Write-Host "Hikvision marker agent: $ApiBase (poll ${PollSeconds}s)"
$clipDir = if ($env:VIDEO_CLIP_DIR) { $env:VIDEO_CLIP_DIR } else { "C:\temp_clips" }
if ($ffmpeg) {
    try {
        if (-not (Test-Path $clipDir)) {
            New-Item -ItemType Directory -Path $clipDir -Force | Out-Null
        }
    }
    catch {
        $clipDir = [System.IO.Path]::GetTempPath()
    }
    Write-Host "ffmpeg: assembly + incident clips enabled ($clipDir)"
} else {
    Write-Host "ffmpeg not found - NVR clips will be skipped (markers still work)"
}

function Invoke-IncidentFfmpeg([string]$Rtsp, [string]$OutFile, [int]$Seconds) {
    $codecPasses = New-Object System.Collections.Generic.List[string[]]
    $codecPasses.Add([string[]]@("-c", "copy"))
    $codecPasses.Add([string[]]@("-c:v", "copy", "-an"))
    $last = "ffmpeg failed"
    foreach ($codec in $codecPasses) {
        if (Test-Path $OutFile) { Remove-Item $OutFile -Force -ErrorAction SilentlyContinue }
        $ffArgs = @(
            "-y", "-hide_banner", "-loglevel", "error",
            "-rtsp_transport", "tcp",
            "-i", $Rtsp
        ) + @($codec) + @("-t", "$Seconds", "-movflags", "+faststart", $OutFile)
        $quoted = foreach ($arg in $ffArgs) {
            $text = [string]$arg
            $dq = [string][char]34
            $bs = [string][char]92
            if ($text.Contains(" ") -or $text.Contains($dq)) {
                $dq + $text.Replace($dq, $bs + $dq) + $dq
            } else {
                $text
            }
        }
        $psi = New-Object System.Diagnostics.ProcessStartInfo
        $psi.FileName = $ffmpeg.Source
        $psi.Arguments = ($quoted -join " ")
        $psi.UseShellExecute = $false
        $psi.CreateNoWindow = $true
        $proc = [System.Diagnostics.Process]::Start($psi)
        if (-not $proc.WaitForExit(90000)) {
            try { $proc.Kill() } catch {}
            throw "ffmpeg timeout"
        }
        if ($proc.ExitCode -eq 0 -and (Test-Path $OutFile) -and ((Get-Item $OutFile).Length -gt 1024)) {
            return
        }
        $last = "ffmpeg exit $($proc.ExitCode)"
    }
    throw $last
}

function Invoke-Isapi([string]$Method, [string]$Url, [string]$Body, [string]$Login, [string]$Password) {
    $tmp = [System.IO.Path]::GetTempFileName()
    try {
        [System.IO.File]::WriteAllText($tmp, $Body, [System.Text.UTF8Encoding]::new($false))
        $args = @(
            "-sS", "-k", "--digest",
            "-u", "${Login}:${Password}",
            "-X", $Method,
            "--max-time", "8",
            "-H", "Content-Type: application/xml; charset=UTF-8",
            "--data-binary", "@$tmp",
            "-w", "`nHTTPSTATUS:%{http_code}",
            $Url
        )
        $out = & curl.exe @args 2>&1 | Out-String
        if ($LASTEXITCODE -ne 0) {
            throw ("curl exit " + $LASTEXITCODE + " " + $out)
        }
        if ($out -notmatch "HTTPSTATUS:(2\d\d)") {
            throw "NVR rejected: $out"
        }
        return $out
    }
    finally {
        if (Test-Path $tmp) { Remove-Item $tmp -Force -ErrorAction SilentlyContinue }
    }
}

function Test-ClipJob([object]$Resp) {
    if ($null -eq $Resp -or -not $Resp.enabled) { return $false }
    $jobs = @($Resp.jobs)
    if ($jobs.Count -lt 1 -or $null -eq $jobs[0] -or $null -eq $jobs[0].id) { return $false }
    return $true
}

# One bench clip. Returns $true when a job was taken (ffmpeg ran), even if encode/upload failed.
function Invoke-AssemblyClipPass {
    $clipUrl = "$ApiBase/api/video/assembly-clip-targets?token=$([uri]::EscapeDataString($Token))&limit=1"
    $clipResp = Invoke-RestMethod -Method Get -Uri $clipUrl -TimeoutSec 20
    if (-not (Test-ClipJob $clipResp)) { return $false }

    $login = [string]$clipResp.nvr.login
    $password = [string]$clipResp.nvr.password
    $sent = New-Object System.Collections.Generic.List[int]
    $failed = New-Object System.Collections.Generic.List[object]
    $job = @($clipResp.jobs)[0]
    $id = [int]$job.id
    $tmpMp4 = [System.IO.Path]::Combine([System.IO.Path]::GetTempPath(), "assembly-$id.mp4")
    try {
        $rtsp = [string]$job.rtsp
        if (-not $rtsp) { throw "no rtsp in job $id" }
        $rtsp = $rtsp.Replace("{login}", [uri]::EscapeDataString($login)).Replace("{password}", [uri]::EscapeDataString($password))
        $seconds = 60
        if ($null -ne $job.max_seconds) { $seconds = [Math]::Max(30, [int]$job.max_seconds) }
        $ffBin = $ffmpeg.Source
        $ffArgs = @(
            "-y", "-hide_banner", "-loglevel", "error",
            "-rtsp_transport", "tcp",
            "-i", $rtsp,
            "-t", "$seconds",
            "-vf", "scale=-2:720",
            "-c:v", "libx264", "-preset", "veryfast", "-crf", "28",
            "-an", "-movflags", "+faststart",
            $tmpMp4
        )
        & $ffBin @ffArgs | Out-Null
        if ($LASTEXITCODE -ne 0 -or -not (Test-Path $tmpMp4)) {
            throw "ffmpeg exit $LASTEXITCODE"
        }
        $uploadUrl = [string]$job.upload.url
        $curlArgs = @(
            "-sS", "-k",
            "-X", "POST",
            "-F", "token=$Token",
            "-F", "job_id=$id",
            "-F", "clip=@$tmpMp4;type=video/mp4",
            "--max-time", "180",
            "-w", "`nHTTPSTATUS:%{http_code}",
            $uploadUrl
        )
        $up = & curl.exe @curlArgs 2>&1 | Out-String
        if ($up -notmatch "HTTPSTATUS:(2\d\d)") {
            throw "upload rejected: $up"
        }
        $sent.Add($id) | Out-Null
        Write-Host "$(Get-Date -Format o) assembly clip job $id uploaded"
    }
    catch {
        $msg = $_.Exception.Message
        $failed.Add([pscustomobject]@{ id = $id; error = $msg }) | Out-Null
        Write-Warning "assembly job $id failed: $msg"
    }
    finally {
        if (Test-Path $tmpMp4) { Remove-Item $tmpMp4 -Force -ErrorAction SilentlyContinue }
    }

    $clipBody = @{
        token    = $Token
        sent_ids = @($sent)
        failed   = @($failed)
    } | ConvertTo-Json -Depth 5

    Invoke-RestMethod -Method Post -Uri "$ApiBase/api/video/assembly-clip-applied" `
        -ContentType "application/json; charset=utf-8" `
        -Body $clipBody -TimeoutSec 20 | Out-Null
    return $true
}

# One hall episode (-c copy). Returns $true when a job was taken.
function Invoke-IncidentClipPass {
    $incUrl = "$ApiBase/api/video/incident-clip-targets?token=$([uri]::EscapeDataString($Token))&limit=1"
    $incResp = Invoke-RestMethod -Method Get -Uri $incUrl -TimeoutSec 20
    if (-not (Test-ClipJob $incResp)) { return $false }

    $login = [string]$incResp.nvr.login
    $password = [string]$incResp.nvr.password
    $sent = New-Object System.Collections.Generic.List[int]
    $failed = New-Object System.Collections.Generic.List[object]
    $job = @($incResp.jobs)[0]
    $id = [int]$job.id
    $name = if ($job.file_name_target) { [string]$job.file_name_target } else { "incident-$id.mp4" }
    $name = [System.IO.Path]::GetFileName($name)
    $tmpMp4 = [System.IO.Path]::Combine($clipDir, $name)
    try {
        $rtsp = [string]$job.rtsp
        if (-not $rtsp) { throw "no rtsp in job $id" }
        $rtsp = $rtsp.Replace("{login}", [uri]::EscapeDataString($login)).Replace("{password}", [uri]::EscapeDataString($password))
        $seconds = 45
        if ($null -ne $job.max_seconds) { $seconds = [Math]::Max(5, [int]$job.max_seconds) }
        Invoke-IncidentFfmpeg -Rtsp $rtsp -OutFile $tmpMp4 -Seconds $seconds
        $uploadUrl = [string]$job.upload.url
        $curlArgs = @(
            "-sS", "-k",
            "-X", "POST",
            "-F", "token=$Token",
            "-F", "job_id=$id",
            "-F", "clip=@$tmpMp4;type=video/mp4",
            "--max-time", "120",
            "-w", "`nHTTPSTATUS:%{http_code}",
            $uploadUrl
        )
        $up = & curl.exe @curlArgs 2>&1 | Out-String
        if ($up -notmatch "HTTPSTATUS:(2\d\d)") {
            throw "upload rejected: $up"
        }
        $sent.Add($id) | Out-Null
        Write-Host "$(Get-Date -Format o) incident clip job $id uploaded"
    }
    catch {
        $msg = $_.Exception.Message
        $failed.Add([pscustomobject]@{ id = $id; error = $msg }) | Out-Null
        Write-Warning "incident job $id failed: $msg"
    }
    finally {
        if (Test-Path $tmpMp4) { Remove-Item $tmpMp4 -Force -ErrorAction SilentlyContinue }
    }

    $incBody = @{
        token    = $Token
        sent_ids = @($sent)
        failed   = @($failed)
    } | ConvertTo-Json -Depth 5

    Invoke-RestMethod -Method Post -Uri "$ApiBase/api/video/incident-clip-applied" `
        -ContentType "application/json; charset=utf-8" `
        -Body $incBody -TimeoutSec 20 | Out-Null
    return $true
}

# After a hall episode, the next cycle looks at the bench first, and the other way around.
$script:preferIncidentClip = $true

while ($true) {
    try {
        $url = "$ApiBase/api/video/marker-targets?token=$([uri]::EscapeDataString($Token))"
        $resp = Invoke-RestMethod -Method Get -Uri $url -TimeoutSec 20

        if (-not $resp.enabled) {
            Write-Host "$(Get-Date -Format o) hikvision markers disabled on server"
            Start-Sleep -Seconds ([Math]::Max(10, $PollSeconds))
            continue
        }

        $jobs = @($resp.jobs)
        if ($jobs.Count -gt 0) {
            $login = [string]$resp.nvr.login
            $password = [string]$resp.nvr.password
            if (-not $login -or -not $password) {
                throw "NVR login/password missing in pull payload — set them in admin video-surveillance"
            }

            $sent = New-Object System.Collections.Generic.List[int]
            $failed = New-Object System.Collections.Generic.List[object]

            foreach ($job in $jobs) {
                $id = [int]$job.id
                try {
                    foreach ($req in @($job.requests)) {
                        $required = $true
                        if ($null -ne $req.required) { $required = [bool]$req.required }
                        try {
                            Invoke-Isapi -Method ([string]$req.method) -Url ([string]$req.url) `
                                -Body ([string]$req.body) -Login $login -Password $password | Out-Null
                        }
                        catch {
                            if ($required) { throw }
                            Write-Warning "job $id optional $($req.id) skipped: $($_.Exception.Message)"
                        }
                    }
                    $sent.Add($id) | Out-Null
                    Write-Host "$(Get-Date -Format o) tagged track $($job.track_id) job $id «$($job.tag_name)»"
                }
                catch {
                    $msg = $_.Exception.Message
                    $failed.Add([pscustomobject]@{ id = $id; error = $msg }) | Out-Null
                    Write-Warning "job $id failed: $msg"
                }
            }

            $body = @{
                token    = $Token
                sent_ids = @($sent)
                failed   = @($failed)
            } | ConvertTo-Json -Depth 5

            Invoke-RestMethod -Method Post -Uri "$ApiBase/api/video/marker-applied" `
                -ContentType "application/json; charset=utf-8" `
                -Body $body -TimeoutSec 20 | Out-Null
        }
    }
    catch {
        Write-Warning "$(Get-Date -Format o) poll error: $($_.Exception.Message)"
        Start-Sleep -Seconds ([Math]::Max(5, $PollSeconds))
        continue
    }

    if (-not $ffmpeg) {
        Start-Sleep -Seconds $PollSeconds
        continue
    }

    # One ffmpeg this cycle. Prefer the queue we did not cut last time.
    # An empty queue falls through to the other; a taken job does not.
    $phases = if ($script:preferIncidentClip) { @("incident", "assembly") } else { @("assembly", "incident") }
    $cutKind = $null
    foreach ($phase in $phases) {
        try {
            $took = $false
            if ($phase -eq "incident") {
                $took = Invoke-IncidentClipPass
            } else {
                $took = Invoke-AssemblyClipPass
            }
            if ($took) {
                $cutKind = $phase
                break
            }
        }
        catch {
            Write-Warning "$(Get-Date -Format o) $phase clip poll error: $($_.Exception.Message)"
        }
    }
    if ($cutKind -eq "incident") {
        $script:preferIncidentClip = $false
    } elseif ($cutKind -eq "assembly") {
        $script:preferIncidentClip = $true
    }

    Start-Sleep -Seconds $PollSeconds
}
