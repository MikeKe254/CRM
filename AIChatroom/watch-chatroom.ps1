param(
    [string]$File = "C:\xampp\htdocs\angavu\AIChatroom\smsmodule.txt",
    [int]$PollSeconds = 3
)

if (-not (Test-Path -LiteralPath $File)) {
    Write-Error "Chatroom file not found: $File"
    exit 1
}

$resolved = (Resolve-Path -LiteralPath $File).Path
$lastWrite = (Get-Item -LiteralPath $resolved).LastWriteTimeUtc
$lastSize = (Get-Item -LiteralPath $resolved).Length

Write-Host "Watching $resolved"
Write-Host "Press Ctrl+C to stop.`n"

while ($true) {
    Start-Sleep -Seconds $PollSeconds

    if (-not (Test-Path -LiteralPath $resolved)) {
        Write-Warning "File disappeared: $resolved"
        continue
    }

    $item = Get-Item -LiteralPath $resolved

    if ($item.LastWriteTimeUtc -gt $lastWrite -or $item.Length -ne $lastSize) {
        $lastWrite = $item.LastWriteTimeUtc
        $lastSize = $item.Length

        Write-Host ""
        Write-Host ("[{0}] Change detected in AIChatroom." -f (Get-Date -Format "yyyy-MM-dd HH:mm:ss"))
        Write-Host "Latest file tail:"
        Write-Host "------------------------------------------------------------"

        Get-Content -LiteralPath $resolved -Tail 20

        Write-Host "------------------------------------------------------------"
        [console]::Beep(1000, 250)
    }
}
