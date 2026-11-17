<?php

namespace App\HoyoverseBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class PublicPowershellScriptController extends AbstractController
{
    #[Route('/api/pulls/script/powershell', name: 'public_pulls_script_powershell', methods: ['GET'])]
    public function getPowershellScript(): Response
    {
        $script = <<<'POWERSHELL'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
Write-Host "==========================================================" -ForegroundColor Cyan
Write-Host "   HoYoverse Gacha / Warp Link Extractor                  " -ForegroundColor Yellow
Write-Host "==========================================================" -ForegroundColor Cyan

$foundUrl = $null

# 1. Search in AppData\LocalLow webCaches data_2 files
$cacheFiles = Get-ChildItem -Path "$env:USERPROFILE\AppData\LocalLow" -Filter "data_2" -Recurse -ErrorAction SilentlyContinue | Sort-Object LastWriteTime -Descending

foreach ($file in $cacheFiles) {
    try {
        $tempCopy = [System.IO.Path]::GetTempFileName()
        Copy-Item -Path $file.FullName -Destination $tempCopy -Force
        $content = [System.IO.File]::ReadAllText($tempCopy)
        Remove-Item -Path $tempCopy -Force -ErrorAction SilentlyContinue

        if ($content -match '(https://[^\s"''<>]*/(?:common/hkrpg_gacha_record|gacha_info|common/gacha_record|common/nap_gacha_record)/api/getGachaLog\?[^\s"''<>]*authkey=[^\s"''<>]*)') {
            $foundUrl = $matches[1]
            break
        }
    } catch {
        continue
    }
}

# 2. Search in Unity Player.log / output_log.txt if not found in cache
if (-not $foundUrl) {
    $logFiles = Get-ChildItem -Path "$env:USERPROFILE\AppData\LocalLow" -Include "Player.log","output_log.txt" -Recurse -ErrorAction SilentlyContinue | Sort-Object LastWriteTime -Descending
    foreach ($log in $logFiles) {
        try {
            $content = Get-Content -Path $log.FullName -Tail 500 -ErrorAction SilentlyContinue
            foreach ($line in $content) {
                if ($line -match '(https://[^\s"''<>]*/(?:common/hkrpg_gacha_record|gacha_info|common/gacha_record|common/nap_gacha_record)/api/getGachaLog\?[^\s"''<>]*authkey=[^\s"''<>]*)') {
                    $foundUrl = $matches[1]
                    break
                }
            }
            if ($foundUrl) { break }
        } catch {
            continue
        }
    }
}

if ($foundUrl) {
    Set-Clipboard -Value $foundUrl
    Write-Host "`n[SUCCESS] Gacha link found and automatically copied to clipboard!" -ForegroundColor Green
    Write-Host "Return to the web application and paste it into the import modal.`n" -ForegroundColor White
} else {
    Write-Host "`n[ERROR] Could not find the gacha history link." -ForegroundColor Red
    Write-Host "Please make sure to:" -ForegroundColor Yellow
    Write-Host " 1. Launch the game (Honkai: Star Rail, Genshin Impact, or Zenless Zone Zero)." -ForegroundColor Yellow
    Write-Host " 2. Open the in-game Warp / Wish / Signal Search History screen." -ForegroundColor Yellow
    Write-Host " 3. Re-run this command in PowerShell.`n" -ForegroundColor Yellow
}
POWERSHELL;

        return new Response($script, Response::HTTP_OK, [
            'Content-Type' => 'text/plain; charset=utf-8',
        ]);
    }
}
