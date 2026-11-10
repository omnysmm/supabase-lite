# FTP uploader for supabase-lite + blogpost SPA → /public_html_old/blogpost
param(
    [string]$FtpHost = "31.42.188.5",
    [string]$FtpUser = "zegewiag",
    [string]$FtpPass = "vbkkbfhlZAQ12wsx",
    [string]$RemoteRoot = "/public_html_old/blogpost",
    [string]$LocalRoot = "C:\supabase-lite\deploy\site"
)

$cred = New-Object System.Net.NetworkCredential($FtpUser, $FtpPass)

function FtpEnsureDir([string]$path) {
    $parts = $path.TrimEnd('/').Split('/') | Where-Object { $_ -ne '' }
    $cur = ''
    foreach ($p in $parts) {
        $cur += '/' + $p
        try {
            $req = [System.Net.FtpWebRequest]::Create("ftp://$FtpHost$cur")
            $req.Credentials = $cred
            $req.Method = [System.Net.WebRequestMethods+Ftp]::MakeDirectory
            $req.UsePassive = $true
            $resp = $req.GetResponse(); $resp.Close()
            Write-Output "  mkdir $cur"
        } catch { # exists already
        }
    }
}

function FtpUploadFile([string]$remotePath, [string]$localPath) {
    $req = [System.Net.FtpWebRequest]::Create("ftp://$FtpHost$remotePath")
    $req.Credentials = $cred
    $req.Method = [System.Net.WebRequestMethods+Ftp]::UploadFile
    $req.UsePassive = $true
    $req.UseBinary = $true
    $bytes = [System.IO.File]::ReadAllBytes($localPath)
    $req.ContentLength = $bytes.Length
    $stream = $req.GetRequestStream()
    $stream.Write($bytes, 0, $bytes.Length)
    $stream.Close()
    $resp = $req.GetResponse(); $resp.Close()
}

$files = Get-ChildItem -Path $LocalRoot -Recurse -File | Where-Object { $_.FullName -notmatch '\\data\\|\\node_modules\\' }
$total = $files.Count
$i = 0
$uploaded = 0
$failed = 0
foreach ($f in $files) {
    $i++
    $rel = $f.FullName.Substring($LocalRoot.Length).Replace('\', '/')
    $remoteDir = $RemoteRoot + (Split-Path $rel -Parent).Replace('\', '/')
    FtpEnsureDir $remoteDir
    try {
        FtpUploadFile ($RemoteRoot + $rel) $f.FullName
        $uploaded++
        if ($i % 25 -eq 0 -or $i -eq $total) { Write-Output "  [$i/$total] uploaded" }
    } catch {
        $failed++
        Write-Output "  FAIL $rel : $($_.Exception.Message.Substring(0, [Math]::Min(70, $_.Exception.Message.Length)))"
    }
}
Write-Output "DONE: uploaded=$uploaded failed=$failed total=$total"
