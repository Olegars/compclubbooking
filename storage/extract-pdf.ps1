Add-Type -AssemblyName System.IO.Compression
$path = "C:\Qt\compclubbooking\storage\doc-104667.pdf"
$bytes = [System.IO.File]::ReadAllBytes($path)
$latin = [System.Text.Encoding]::GetEncoding(28591)
$raw = $latin.GetString($bytes)

function Inflate-Pdf([byte[]]$slice) {
  $end = $slice.Length - 1
  while ($end -ge 0 -and ($slice[$end] -eq 10 -or $slice[$end] -eq 13 -or $slice[$end] -eq 32)) { $end-- }
  $len = $end - 1
  if ($len -le 8) { return $null }
  $payload = New-Object byte[] $len
  [Array]::Copy($slice, 2, $payload, 0, $len)
  try {
    $msIn = New-Object System.IO.MemoryStream(,$payload)
    $ds = New-Object System.IO.Compression.DeflateStream($msIn, [System.IO.Compression.CompressionMode]::Decompress)
    $out = New-Object System.IO.MemoryStream
    $ds.CopyTo($out)
    $ds.Dispose()
    return $out.ToArray()
  } catch { return $null }
}

$streamRx = [regex]::new('stream\r?\n')
$streams = @()
$si = 0
foreach ($m in $streamRx.Matches($raw)) {
  $start = $m.Index + $m.Length
  $endTok = $raw.IndexOf("endstream", $start)
  if ($endTok -lt 0) { continue }
  $slice = New-Object byte[] ($endTok - $start)
  [Array]::Copy($bytes, $start, $slice, 0, $slice.Length)
  $plain = Inflate-Pdf $slice
  $streams += [pscustomobject]@{ i=$si; plain=$plain }
  $si++
}

function Parse-CMap([string]$txt) {
  $map = @{}
  # array ranges first so they win, then simple ranges, then bfchar last? 
  # Process in CMap order: walk lines
  $lines = $txt -split "`n"
  $mode = ""
  foreach ($line in $lines) {
    $line = $line.Trim()
    if ($line -match 'beginbfchar') { $mode = 'bf'; continue }
    if ($line -match 'endbfchar') { $mode = ''; continue }
    if ($line -match 'beginbfrange') { $mode = 'rg'; continue }
    if ($line -match 'endbfrange') { $mode = ''; continue }
    if ($mode -eq 'bf') {
      $bm = [regex]::Match($line, '<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>')
      if ($bm.Success) {
        $gid = [Convert]::ToInt32($bm.Groups[1].Value, 16)
        $hex = $bm.Groups[2].Value
        $chars = ""
        for ($k=0; $k -lt $hex.Length; $k+=4) {
          $chars += [char]([Convert]::ToInt32($hex.Substring($k,4), 16))
        }
        $map[$gid] = $chars
      }
    } elseif ($mode -eq 'rg') {
      $am = [regex]::Match($line, '<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*\[([^\]]+)\]')
      if ($am.Success) {
        $a = [Convert]::ToInt32($am.Groups[1].Value, 16)
        $arr = [regex]::Matches($am.Groups[3].Value, '<([0-9A-Fa-f]+)>')
        for ($j=0; $j -lt $arr.Count; $j++) {
          $hex = $arr[$j].Groups[1].Value
          $chars = ""
          for ($k=0; $k -lt $hex.Length; $k+=4) {
            $chars += [char]([Convert]::ToInt32($hex.Substring($k,4), 16))
          }
          $map[$a + $j] = $chars
        }
        continue
      }
      $rm = [regex]::Match($line, '<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>')
      if ($rm.Success) {
        $a = [Convert]::ToInt32($rm.Groups[1].Value, 16)
        $b = [Convert]::ToInt32($rm.Groups[2].Value, 16)
        $dst = [Convert]::ToInt32($rm.Groups[3].Value.Substring(0,4), 16)
        if (($b-$a) -le 8000) {
          for ($g=$a; $g -le $b; $g++) { $map[$g] = [string]([char]($dst + ($g-$a))) }
        }
      }
    }
  }
  return $map
}

$cmaps = @{}
foreach ($s in $streams) {
  if (-not $s.plain) { continue }
  $txt = $latin.GetString($s.plain)
  if ($txt -notmatch 'beginbfchar' -and $txt -notmatch 'beginbfrange') { continue }
  $cmaps[$s.i] = Parse-CMap $txt
  Write-Output ("cmap $($s.i) $($cmaps[$s.i].Count)")
}

function Score-Map($gids, $map) {
  $good = 0
  foreach ($gid in $gids) {
    if (-not $map.ContainsKey($gid)) { continue }
    $ch = $map[$gid]
    if ($ch.Length -eq 0) { continue }
    $c = [int][char]$ch[0]
    $ok = ($c -eq 32) -or ($c -ge 48 -and $c -le 57) -or ($c -ge 65 -and $c -le 90) -or ($c -ge 97 -and $c -le 122) -or ($c -ge 0x0400 -and $c -le 0x04FF) -or ($c -eq 0x2014) -or ($c -eq 0x2013) -or ($c -eq 0x00AB) -or ($c -eq 0x00BB) -or ('.,:;!?()[]{}+-*/=#_%"''«»—–/\'.Contains($ch[0]))
    if ($ok) { $good++ }
  }
  return $good
}

function Decode-Page([string]$content) {
  $sb = New-Object System.Text.StringBuilder
  $font = "F4"
  $fontMaps = @{}
  # collect gids per font
  $per = @{}
  $cur = "F4"
  $toks = [regex]::Matches($content, '/(F\d+)\s+[0-9.]+\s+Tf|<([0-9A-Fa-f]+)>\s*Tj|(?<y>-?\d+(?:\.\d+)?)\s+(?<dy>-?\d+(?:\.\d+)?)\s+Td|(?<tm>-?\d+(?:\.\d+)?)\s+-?\d+(?:\.\d+)?\s+-?\d+(?:\.\d+)?\s+-?\d+(?:\.\d+)?\s+(?<tx>-?\d+(?:\.\d+)?)\s+(?<ty>-?\d+(?:\.\d+)?)\s+Tm')
  foreach ($t in $toks) {
    if ($t.Value -match '^/F') {
      $cur = $t.Groups[1].Value
      if (-not $per.ContainsKey($cur)) { $per[$cur] = New-Object System.Collections.Generic.List[int] }
    } elseif ($t.Groups[2].Success) {
      if (-not $per.ContainsKey($cur)) { $per[$cur] = New-Object System.Collections.Generic.List[int] }
      $hex = $t.Groups[2].Value
      for ($k=0; $k+4 -le $hex.Length; $k+=4) {
        $per[$cur].Add([Convert]::ToInt32($hex.Substring($k,4), 16))
      }
    }
  }
  foreach ($fn in $per.Keys) {
    $best = $null; $bestScore = -1
    foreach ($id in $cmaps.Keys) {
      $sc = Score-Map $per[$fn] $cmaps[$id]
      if ($sc -gt $bestScore) { $bestScore = $sc; $best = $id }
    }
    $fontMaps[$fn] = $best
    Write-Output ("  font $fn glyphs $($per[$fn].Count) -> cmap $best score $bestScore")
  }

  $cur = "F4"
  $curY = 0.0
  $lastBucket = $null
  $line = New-Object System.Text.StringBuilder
  $lastX = -9999.0
  foreach ($t in $toks) {
    if ($t.Value -match '^/F') { $cur = $t.Groups[1].Value; continue }
    if ($t.Value -match 'Tm$' -and $t.Groups['ty'].Success) {
      $curY = [double]$t.Groups['ty'].Value
      continue
    }
    if ($t.Value -match 'Td$' -and $t.Groups['y'].Success) {
      $curY += [double]$t.Groups['y'].Value
      $dx = [double]$t.Groups['dy'].Value
      # large horizontal jump within line may be a space already in glyphs
      continue
    }
    if ($t.Groups[2].Success) {
      $bucket = [math]::Round($curY, 0)
      if ($lastBucket -ne $null -and [math]::Abs($bucket - $lastBucket) -gt 2) {
        [void]$sb.AppendLine($line.ToString())
        $line = New-Object System.Text.StringBuilder
      }
      $map = $null
      if ($fontMaps.ContainsKey($cur)) { $map = $cmaps[$fontMaps[$cur]] }
      $hex = $t.Groups[2].Value
      for ($k=0; $k+4 -le $hex.Length; $k+=4) {
        $gid = [Convert]::ToInt32($hex.Substring($k,4), 16)
        if ($map -and $map.ContainsKey($gid)) { [void]$line.Append($map[$gid]) } else { [void]$line.Append("?") }
      }
      $lastBucket = $bucket
    }
  }
  if ($line.Length -gt 0) { [void]$sb.AppendLine($line.ToString()) }
  return $sb.ToString()
}

$out = New-Object System.Text.StringBuilder
$page = 0
foreach ($s in $streams) {
  if (-not $s.plain) { continue }
  $txt = $latin.GetString($s.plain)
  if ($txt -notmatch 'Tj') { continue }
  $page++
  [void]$out.AppendLine("===== PAGE $page =====")
  Write-Output "PAGE $page"
  [void]$out.AppendLine((Decode-Page $txt))
}
[System.IO.File]::WriteAllText("C:\Qt\compclubbooking\storage\doc-104667-text.txt", $out.ToString(), [System.Text.UTF8Encoding]::new($false))
Write-Output "done $($out.Length)"
