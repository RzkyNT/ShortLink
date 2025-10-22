# Minta user input folder assets
$assetDir = Read-Host "Masukkan path folder assets (misal: C:\laragon\www\pas\public\portal\assets)"
if (-not (Test-Path $assetDir)) { Write-Host "Folder tidak ditemukan!" -ForegroundColor Red; exit }

# Pastikan folder CSS, JS, fonts, local_cdn ada
$folders = @{
    "css" = Join-Path $assetDir "css"
    "js" = Join-Path $assetDir "js"
    "fonts" = Join-Path $assetDir "fonts"
    "local_cdn" = Join-Path $assetDir "local_cdn"
}
foreach ($f in $folders.Values) {
    if (-not (Test-Path $f)) {
        New-Item -ItemType Directory -Path $f | Out-Null
        Write-Host "Created folder: $f"
    }
}

# File log
$logFile = Join-Path $assetDir "..\cdn_download.log"
Add-Content -Path $logFile -Value "`n`n==== Start: $(Get-Date) ====`n"

# Regex CDN populer
$cdnPattern = 'https?://(?:.*cdn.*|.*jsdelivr.*|.*cdnjs.*|.*googleapis.*|.*fonts.googleapis.*|.*fontawesome.*|.*bootstrap.*|.*jquery.*|.*chart\.js.*|.*popper.*)'

# Root project
$projectDir = "."

# Ambil semua file project
Get-ChildItem -Path $projectDir -Recurse -Include *.php,*.html,*.css,*.js | ForEach-Object {
    $filePath = $_.FullName
    $content = Get-Content $filePath -Raw
    $changed = $false

    # Cari semua URL CDN (JS/CSS)
    $matches = [regex]::Matches($content, '(https?://[^\s"''<>]+(\.css|\.js))')

    foreach ($match in $matches) {
        $url = $match.Groups[1].Value
        if ($url -notmatch $cdnPattern) { continue }

        $ext = [System.IO.Path]::GetExtension($url).TrimStart('.').ToLower()
        $saveFolder = switch ($ext) { "css" { $folders["css"] } "js" { $folders["js"] } default { $folders["local_cdn"] } }

        $fileName = [System.IO.Path]::GetFileName($url)
        if ([string]::IsNullOrEmpty($fileName)) { $fileName = "file_$([guid]::NewGuid()).$ext" }

        $savePath = Join-Path $saveFolder $fileName

        try {
            Invoke-WebRequest -Uri $url -OutFile $savePath -ErrorAction Stop
            Write-Host "Downloaded: $fileName"
            Add-Content -Path $logFile -Value "Downloaded: $fileName from $url"
        } 
        catch { 
            Write-Warning "Failed to download: $url"
            Add-Content -Path $logFile -Value "Failed to download: $url"
            continue
        }

        # Jika CSS, cek @import & font URLs
        if ($ext -eq "css") {
            $cssContent = Get-Content $savePath -Raw

            # Download font URLs
            $cssContent = [regex]::Replace($cssContent, 'url\((https?://[^\)]+)\)', {
                param($m)
                $fontUrl = $m.Groups[1].Value
                $fontName = [System.IO.Path]::GetFileName($fontUrl)
                $fontSavePath = Join-Path $folders["fonts"] $fontName
                try { 
                    Invoke-WebRequest -Uri $fontUrl -OutFile $fontSavePath -ErrorAction Stop
                    Write-Host "Downloaded font: $fontName"
                    Add-Content -Path $logFile -Value "Downloaded font: $fontName from $fontUrl"
                } catch {
                    Add-Content -Path $logFile -Value "Failed to download font: $fontUrl"
                }
                "url(fonts/$fontName)"
            })

            # Download @import CSS
            $cssContent = [regex]::Replace($cssContent, '@import\s+url\(["'']?(https?://[^)"'']+)["'']?\)', {
                param($m)
                $importUrl = $m.Groups[1].Value
                $importName = [System.IO.Path]::GetFileName($importUrl)
                $importSavePath = Join-Path $folders["css"] $importName
                try { 
                    Invoke-WebRequest -Uri $importUrl -OutFile $importSavePath -ErrorAction Stop
                    Write-Host "Downloaded import CSS: $importName"
                    Add-Content -Path $logFile -Value "Downloaded import CSS: $importName from $importUrl"
                } catch {
                    Add-Content -Path $logFile -Value "Failed to download import CSS: $importUrl"
                }
                "@import url(css/$importName)"
            })

            Set-Content -Path $savePath -Value $cssContent
        }

        # Update path di file project
        $relativePath = switch ($ext) { "css" { "assets/css/$fileName" } "js" { "assets/js/$fileName" } default { "assets/local_cdn/$fileName" } }
        $content = $content -replace [regex]::Escape($url), $relativePath
        $changed = $true
    }

    if ($changed) { 
        Set-Content -Path $filePath -Value $content
        Write-Host "Updated file: $filePath"
        Add-Content -Path $logFile -Value "Updated file: $filePath"
    }
}

Add-Content -Path $logFile -Value "✅ Selesai: $(Get-Date)"
Write-Host "✅ Semua CDN populer, JS/CSS/Fonts berhasil diarahkan ke lokal! Log tersimpan di cdn_download.log"
