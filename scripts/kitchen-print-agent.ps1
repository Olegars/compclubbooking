# Kitchen ESC/POS print agent (club LAN)
# Polls cloud queue and sends raw bytes to one Ethernet printer (TCP 9100).
#
# Usage (PowerShell):
#   $env:KITCHEN_API_BASE = "https://your-club.example"
#   $env:KITCHEN_PRINT_TOKEN = "same-as-KITCHEN_PRINT_RELAY_TOKEN-or-CLUB_WOL_RELAY_TOKEN"
#   $env:KITCHEN_PRINTER_HOST = "192.168.1.50"
#   $env:KITCHEN_PRINTER_PORT = "9100"
#   $env:KITCHEN_POLL_SECONDS = "3"
#   powershell -ExecutionPolicy Bypass -File scripts\kitchen-print-agent.ps1
#
# Run as Scheduled Task / always-on service on a PC or NUC in the club LAN.

$ErrorActionPreference = "Stop"

$ApiBase = ($env:KITCHEN_API_BASE -replace '/$', '')
if (-not $ApiBase) { throw "Set KITCHEN_API_BASE (e.g. https://0451.space)" }

$Token = $env:KITCHEN_PRINT_TOKEN
if (-not $Token) { throw "Set KITCHEN_PRINT_TOKEN" }

$PrinterHost = if ($env:KITCHEN_PRINTER_HOST) { $env:KITCHEN_PRINTER_HOST } else { "192.168.1.50" }
$PrinterPort = if ($env:KITCHEN_PRINTER_PORT) { [int]$env:KITCHEN_PRINTER_PORT } else { 9100 }
$PollSeconds = if ($env:KITCHEN_POLL_SECONDS) { [int]$env:KITCHEN_POLL_SECONDS } else { 3 }

Write-Host "Kitchen print agent: $ApiBase -> ${PrinterHost}:${PrinterPort} (poll ${PollSeconds}s)"

function Send-EscPos([byte[]]$Bytes, [string]$HostName, [int]$Port) {
    $client = New-Object System.Net.Sockets.TcpClient
    try {
        $iar = $client.BeginConnect($HostName, $Port, $null, $null)
        $ok = $iar.AsyncWaitHandle.WaitOne(3000, $false)
        if (-not $ok -or -not $client.Connected) {
            throw "Connect timeout to ${HostName}:${Port}"
        }
        $client.EndConnect($iar) | Out-Null
        $stream = $client.GetStream()
        $stream.Write($Bytes, 0, $Bytes.Length)
        $stream.Flush()
    }
    finally {
        if ($client) { $client.Close() }
    }
}

while ($true) {
    try {
        $url = "$ApiBase/api/kitchen/print-targets?token=$([uri]::EscapeDataString($Token))"
        $resp = Invoke-RestMethod -Method Get -Uri $url -TimeoutSec 20

        if (-not $resp.enabled) {
            Write-Host "$(Get-Date -Format o) kitchen print disabled on server"
            Start-Sleep -Seconds ([Math]::Max(10, $PollSeconds))
            continue
        }

        $jobs = @($resp.jobs)
        if ($jobs.Count -eq 0) {
            Start-Sleep -Seconds $PollSeconds
            continue
        }

        $printed = New-Object System.Collections.Generic.List[int]
        $failed = New-Object System.Collections.Generic.List[object]

        foreach ($job in $jobs) {
            $id = [int]$job.id
            try {
                $raw = [Convert]::FromBase64String([string]$job.escpos_base64)
                Send-EscPos -Bytes $raw -HostName $PrinterHost -Port $PrinterPort
                $printed.Add($id) | Out-Null
                Write-Host "$(Get-Date -Format o) printed order #$($job.order_id) job $id"
            }
            catch {
                $msg = $_.Exception.Message
                $failed.Add([pscustomobject]@{ id = $id; error = $msg }) | Out-Null
                Write-Warning "job $id failed: $msg"
            }
        }

        $body = @{
            token       = $Token
            printed_ids = @($printed)
            failed      = @($failed)
        } | ConvertTo-Json -Depth 5

        Invoke-RestMethod -Method Post -Uri "$ApiBase/api/kitchen/print-applied" `
            -ContentType "application/json; charset=utf-8" `
            -Body $body -TimeoutSec 20 | Out-Null
    }
    catch {
        Write-Warning "$(Get-Date -Format o) poll error: $($_.Exception.Message)"
        Start-Sleep -Seconds ([Math]::Max(5, $PollSeconds))
    }
}
