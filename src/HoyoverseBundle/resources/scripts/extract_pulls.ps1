[Net.ServicePointManager]::SecurityProtocol = [Net.ServicePointManager]::SecurityProtocol -bor [Net.SecurityProtocolType]::Tls12
Add-Type -AssemblyName System.Web
$ProgressPreference = 'SilentlyContinue'

Write-Host "==========================================================" -ForegroundColor Cyan
Write-Host "   __TITLE__ Gacha Link Extractor" -ForegroundColor Yellow
Write-Host "==========================================================" -ForegroundColor Cyan
Write-Host "Attempting to locate Gacha History URL..."

$localLowRoot = [IO.Path]::GetFullPath((Join-Path ([Environment]::GetFolderPath('LocalApplicationData')) '..\LocalLow'))
$candidateFolders = @(__FOLDERS__)
$candidateLogFiles = @(__LOG_FILES__)
$dataFolderRegex = '([a-zA-Z]:[/\\][^\r\n"]*?(?:__DATA_FOLDER_PATTERN__))'
$urlFilterRegex = '__URL_REGEX__'
$gachaEndpointUrl = '__GACHA_ENDPOINT_URL__'
$testGachaType = '__TEST_GACHA_TYPE__'

$discoveredLogs = @()
foreach ($relFolder in $candidateFolders) {
    $folderPath = Join-Path $localLowRoot $relFolder
    if (-not (Test-Path $folderPath)) {
        continue
    }

    foreach ($logName in $candidateLogFiles) {
        $logPath = Join-Path $folderPath $logName
        if ([IO.File]::Exists($logPath)) {
            $discoveredLogs += Get-Item -LiteralPath $logPath -ErrorAction SilentlyContinue
        }
    }
}

$discoveredLogs = $discoveredLogs | Sort-Object LastWriteTime -Descending

$gamePaths = New-Object 'System.Collections.Generic.List[string]'
$seenGamePaths = New-Object 'System.Collections.Generic.HashSet[string]' ([System.StringComparer]::OrdinalIgnoreCase)

foreach ($logItem in $discoveredLogs) {
    $logStream = $null
    $logReader = $null
    try {
        $shareMode = [System.IO.FileShare]::ReadWrite -bor [System.IO.FileShare]::Delete
        $logStream = [System.IO.File]::Open($logItem.FullName, [System.IO.FileMode]::Open, [System.IO.FileAccess]::Read, $shareMode)
        $logReader = New-Object System.IO.StreamReader($logStream, [System.Text.Encoding]::UTF8, $true)

        while ($null -ne ($line = $logReader.ReadLine())) {
            if ($line -match '^Loading player data from (.+?)data\.unity3d') {
                $resolvedPath = $matches[1].Trim().TrimEnd('/', '\')
                if ($resolvedPath -and (Test-Path $resolvedPath) -and $seenGamePaths.Add($resolvedPath)) {
                    $gamePaths.Add($resolvedPath)
                }
                break
            }

            if ($line -match '^\[Subsystems\] Discovering subsystems at path (.+?)UnitySubsystems') {
                $resolvedPath = $matches[1].Trim().TrimEnd('/', '\')
                if ($resolvedPath -and (Test-Path $resolvedPath) -and $seenGamePaths.Add($resolvedPath)) {
                    $gamePaths.Add($resolvedPath)
                }
                break
            }

            if ($line -match $dataFolderRegex) {
                $resolvedPath = $matches[1].Trim().TrimEnd('/', '\')
                if ($resolvedPath -and (Test-Path $resolvedPath) -and $seenGamePaths.Add($resolvedPath)) {
                    $gamePaths.Add($resolvedPath)
                }
                break
            }
        }
    } catch {
        continue
    } finally {
        if ($logReader) {
            $logReader.Dispose()
        } elseif ($logStream) {
            $logStream.Dispose()
        }
    }
}

foreach ($gamePath in $gamePaths) {
    $webCachesDir = Join-Path $gamePath 'webCaches'
    if (-not (Test-Path $webCachesDir)) {
        continue
    }

    $cachePath = Join-Path $webCachesDir 'Cache\Cache_Data\data_2'
    $maxVersion = [Version]'0.0.0.0'

    $versionDirs = Get-ChildItem -LiteralPath $webCachesDir -Directory -ErrorAction SilentlyContinue
    foreach ($dir in $versionDirs) {
        if ($dir.Name -match '^\d+\.\d+\.\d+\.\d+$') {
            try {
                $parsedVersion = [Version]$dir.Name
                $candidateCache = Join-Path $dir.FullName 'Cache\Cache_Data\data_2'
                if (($parsedVersion -ge $maxVersion) -and [IO.File]::Exists($candidateCache)) {
                    $maxVersion = $parsedVersion
                    $cachePath = $candidateCache
                }
            } catch {
                continue
            }
        }
    }

    if (-not [IO.File]::Exists($cachePath)) {
        continue
    }

    $cacheStream = $null
    $cacheReader = $null
    try {
        $shareMode = [System.IO.FileShare]::ReadWrite -bor [System.IO.FileShare]::Delete
        $cacheStream = [System.IO.File]::Open($cachePath, [System.IO.FileMode]::Open, [System.IO.FileAccess]::Read, $shareMode)
        $cacheReader = New-Object System.IO.StreamReader($cacheStream, [System.Text.Encoding]::UTF8, $true)
        $cacheData = $cacheReader.ReadToEnd()
    } catch {
        continue
    } finally {
        if ($cacheReader) {
            $cacheReader.Dispose()
        } elseif ($cacheStream) {
            $cacheStream.Dispose()
        }
    }

    $cacheEntries = $cacheData -split '1/0/'
    for ($i = $cacheEntries.Length - 1; $i -ge 0; $i--) {
        $entry = $cacheEntries[$i]
        $isApiUrl = $entry.Contains('getGachaLog') -or $entry.Contains('getLdGachaLog')
        $isWebviewUrl = $entry.Contains('webview_gacha') -and $entry.Contains('authkey=')

        if (($isApiUrl -or $isWebviewUrl) -and ($entry -match '(https://[^\0\r\n"''<>]+)')) {
            $rawUrl = $matches[1].Trim()
            if ($urlFilterRegex -and ($rawUrl -notmatch $urlFilterRegex)) {
                continue
            }

            try {
                $uri = [Uri]$rawUrl
                $query = [System.Web.HttpUtility]::ParseQueryString($uri.Query)
                if ([string]::IsNullOrEmpty($query['authkey'])) {
                    continue
                }

                $allowedKeys = @('authkey', 'authkey_ver', 'sign_type', 'game_biz', 'lang')
                foreach ($key in @($query.AllKeys)) {
                    if ($key -and ($allowedKeys -notcontains $key)) {
                        $query.Remove($key)
                    }
                }

                if ($rawUrl -match '/get(?:Ld)?GachaLog\b') {
                    $apiBaseUrl = $uri.Scheme + '://' + $uri.Host + $uri.AbsolutePath
                    $testUrl = $rawUrl
                } else {
                    $apiBaseUrl = $gachaEndpointUrl
                    $testQuery = [System.Web.HttpUtility]::ParseQueryString($query.ToString())
                    if ($testGachaType) {
                        $testQuery.Set('gacha_type', $testGachaType)
                    }
                    $testQuery.Set('size', '5')
                    if ([string]::IsNullOrEmpty($testQuery['lang'])) {
                        $testQuery.Set('lang', 'en')
                    }
                    $testUrl = $apiBaseUrl + '?' + $testQuery.ToString()
                }

                $res = Invoke-WebRequest -Uri $testUrl -ContentType 'application/json' -UseBasicParsing -TimeoutSec 10 -ErrorAction Stop | ConvertFrom-Json
                if ($res.retcode -eq 0) {
                    $cleanUrl = $apiBaseUrl + '?' + $query.ToString()
                    Set-Clipboard -Value $cleanUrl

                    Write-Host "`n[SUCCESS] Gacha link found and automatically copied to clipboard!" -ForegroundColor Green
                    Write-Host $cleanUrl -ForegroundColor DarkGray
                    Write-Host "Return to the web application and paste it into the import modal.`n" -ForegroundColor White
                    return
                }
            } catch {
                continue
            }
        }
    }
}

Write-Host "`n[ERROR] Could not locate a valid gacha history link." -ForegroundColor Red
Write-Host "Please make sure to:" -ForegroundColor Yellow
Write-Host " 1. Launch the game (__TITLE__)." -ForegroundColor Yellow
Write-Host " 2. Open the in-game Wish / Warp / Signal Search History screen and wait for it to load." -ForegroundColor Yellow
Write-Host " 3. Re-run this command in PowerShell.`n" -ForegroundColor Yellow
