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
if ($ffmpeg) {
    Write-Host "ffmpeg: assembly clips enabled"
} else {
    Write-Host "ffmpeg not found — NVR assembly clips will be skipped (markers still work)"
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
            throw "curl exit $LASTEXITCODE: $out"
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

    try {
        $clipUrl = "$ApiBase/api/video/assembly-clip-targets?token=$([uri]::EscapeDataString($Token))"
        $clipResp = Invoke-RestMethod -Method Get -Uri $clipUrl -TimeoutSec 20
        if (-not $clipResp.enabled) {
            Start-Sleep -Seconds $PollSeconds
            continue
        }

        $clipJobs = @($clipResp.jobs)
        if ($clipJobs.Count -eq 0) {
            Start-Sleep -Seconds $PollSeconds
            continue
        }

        $login = [string]$clipResp.nvr.login
        $password = [string]$clipResp.nvr.password
        $sent = New-Object System.Collections.Generic.List[int]
        $failed = New-Object System.Collections.Generic.List[object]

        foreach ($job in $clipJobs) {
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
                & $ffBin @ffArgs
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
        }

        $clipBody = @{
            token    = $Token
            sent_ids = @($sent)
            failed   = @($failed)
        } | ConvertTo-Json -Depth 5

        Invoke-RestMethod -Method Post -Uri "$ApiBase/api/video/assembly-clip-applied" `
            -ContentType "application/json; charset=utf-8" `
            -Body $clipBody -TimeoutSec 20 | Out-Null
    }
    catch {
        Write-Warning "$(Get-Date -Format o) assembly clip poll error: $($_.Exception.Message)"
        Start-Sleep -Seconds ([Math]::Max(5, $PollSeconds))
        continue
    }

    Start-Sleep -Seconds $PollSeconds
}
