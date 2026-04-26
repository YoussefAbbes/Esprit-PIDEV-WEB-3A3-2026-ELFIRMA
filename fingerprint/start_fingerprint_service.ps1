# ============================================================
#   ZKFinger HTTP Bridge Server - PowerShell Startup Script
#   Strategy:
#     - Compile with 64-bit javac targeting Java 8 source/target
#     - Run   with 32-bit JRE (to match 32-bit libzkfp.dll)
# ============================================================

Set-Location $PSScriptRoot

Write-Host ""
Write-Host "============================================================" -ForegroundColor Cyan
Write-Host "  ZKFinger HTTP Bridge Server - PowerShell Startup Script  " -ForegroundColor Cyan
Write-Host "============================================================" -ForegroundColor Cyan
Write-Host ""

# ── Verify required files ─────────────────────────────────────────────────────

if (-not (Test-Path "lib\ZKFingerReader.jar")) {
    Write-Host "[ERROR] SDK JAR not found: $PSScriptRoot\lib\ZKFingerReader.jar" -ForegroundColor Red
    Read-Host "Press Enter to exit"; exit 1
}

if (-not (Test-Path "FingerprintBridgeServer.java")) {
    Write-Host "[ERROR] Source file not found: $PSScriptRoot\FingerprintBridgeServer.java" -ForegroundColor Red
    Read-Host "Press Enter to exit"; exit 1
}

if (-not (Test-Path "libzkfp.dll")) {
    Write-Host "[ERROR] libzkfp.dll not found in: $PSScriptRoot" -ForegroundColor Red
    Write-Host "        Copy the 32-bit libzkfp.dll next to this script." -ForegroundColor Red
    Read-Host "Press Enter to exit"; exit 1
}

Write-Host "[OK]   libzkfp.dll found (32-bit DLL)." -ForegroundColor Green
Write-Host ""

# ── 1. Find 64-bit javac (for compilation) ────────────────────────────────────
# We compile with 64-bit javac but target Java 8 bytecode so the
# 32-bit JRE 8 can load the resulting .class files.

Write-Host "[INFO] Looking for 64-bit javac (for compilation)..." -ForegroundColor Cyan

$javacExe = $null

# Check PATH first
$pathJavac = Get-Command javac -ErrorAction SilentlyContinue
if ($pathJavac) {
    $javacExe = $pathJavac.Source
    Write-Host "[OK]   Found javac on PATH: $javacExe" -ForegroundColor Green
}

# Search 64-bit Program Files
if (-not $javacExe) {
    $roots64 = @(
        $env:ProgramFiles,
        "C:\Program Files"
    )
    $subDirs = @("Java","Eclipse Adoptium","Microsoft","Amazon Corretto","BellSoft","Azul Systems")
    foreach ($root in $roots64) {
        foreach ($sub in $subDirs) {
            $path = Join-Path $root $sub
            if (Test-Path $path) {
                $found = Get-ChildItem -Path $path -Directory -ErrorAction SilentlyContinue |
                         Where-Object { Test-Path "$($_.FullName)\bin\javac.exe" } |
                         Sort-Object Name -Descending |
                         Select-Object -First 1
                if ($found) {
                    $javacExe = "$($found.FullName)\bin\javac.exe"
                    Write-Host "[OK]   Found 64-bit javac: $javacExe" -ForegroundColor Green
                    break
                }
            }
        }
        if ($javacExe) { break }
    }
}

if (-not $javacExe) {
    Write-Host "[ERROR] No javac found. Install a 64-bit JDK (11+) from https://adoptium.net/" -ForegroundColor Red
    Read-Host "Press Enter to exit"; exit 1
}

# ── 2. Find 32-bit java (for running) ─────────────────────────────────────────
# libzkfp.dll is 32-bit so the JVM that loads it MUST be 32-bit.

Write-Host ""
Write-Host "[INFO] Looking for 32-bit java (for running)..." -ForegroundColor Cyan

$javaExe = $null

# Search x86 Program Files
$roots32 = @(
    ${env:ProgramFiles(x86)},
    "C:\Program Files (x86)"
)
foreach ($root in $roots32) {
    if (-not $root) { continue }
    $subDirs = @("Java","Eclipse Adoptium","Microsoft","Amazon Corretto","BellSoft","Azul Systems")
    foreach ($sub in $subDirs) {
        $path = Join-Path $root $sub
        if (Test-Path $path) {
            $found = Get-ChildItem -Path $path -Directory -ErrorAction SilentlyContinue |
                     Where-Object { Test-Path "$($_.FullName)\bin\java.exe" } |
                     Sort-Object Name -Descending |
                     Select-Object -First 1
            if ($found) {
                $javaExe = "$($found.FullName)\bin\java.exe"
                Write-Host "[OK]   Found 32-bit java: $javaExe" -ForegroundColor Green
                break
            }
        }
    }
    if ($javaExe) { break }
}

if (-not $javaExe) {
    Write-Host "[WARN] No 32-bit Java found in Program Files (x86)." -ForegroundColor Yellow
    Write-Host "       Download JDK 8 x86 from: https://www.java.com/en/download/manual.jsp" -ForegroundColor Yellow
    Write-Host "       (choose 'Windows x86 Offline')" -ForegroundColor Yellow
    Write-Host ""
    Write-Host "       Falling back to PATH java — will likely fail with UnsatisfiedLinkError." -ForegroundColor Yellow
    $javaExe = "java"
}

# ── 3. Show versions ──────────────────────────────────────────────────────────

Write-Host ""
Write-Host "[INFO] javac version:" -ForegroundColor Cyan
& $javacExe -version
Write-Host ""
Write-Host "[INFO] java (runtime) version:" -ForegroundColor Cyan
& $javaExe -version
Write-Host ""

# ── 4. Compile with 64-bit javac, target Java 8 ───────────────────────────────

Write-Host "[INFO] Compiling FingerprintBridgeServer.java (targeting Java 8)..." -ForegroundColor Cyan
Write-Host ""

& $javacExe -source 8 -target 8 -cp "lib\ZKFingerReader.jar" FingerprintBridgeServer.java

if ($LASTEXITCODE -ne 0) {
    Write-Host ""
    Write-Host "[ERROR] Compilation failed! See errors above." -ForegroundColor Red
    Read-Host "Press Enter to exit"; exit 1
}

Write-Host ""
Write-Host "[OK]   Compilation successful (Java 8 bytecode)." -ForegroundColor Green
Write-Host ""

# ── 5. Check port 8085 ────────────────────────────────────────────────────────

$portUsed = netstat -ano 2>$null | Select-String ":8085 " | Select-String "LISTENING"
if ($portUsed) {
    Write-Host "[WARN] Port 8085 is already in use. Another instance may be running." -ForegroundColor Yellow
    Write-Host ""
}

# ── 6. Launch with 32-bit JRE ─────────────────────────────────────────────────

Write-Host "[INFO] Starting ZKFinger HTTP Bridge on http://localhost:8085 ..." -ForegroundColor Cyan
Write-Host "[INFO] JVM (32-bit) : $javaExe" -ForegroundColor Cyan
Write-Host "[INFO] DLL path     : $PSScriptRoot" -ForegroundColor Cyan
Write-Host "[INFO] Press Ctrl+C to stop." -ForegroundColor Cyan
Write-Host ""
Write-Host "============================================================" -ForegroundColor Cyan
Write-Host ""

& $javaExe `
    -cp ".;lib\ZKFingerReader.jar" `
    "-Djava.library.path=." `
    "-Djava.util.logging.SimpleFormatter.format=[%1`$tT] [%4`$s] %5`$s%6`$s%n" `
    FingerprintBridgeServer

if ($LASTEXITCODE -ne 0) {
    Write-Host ""
    Write-Host "[ERROR] Server exited with code $LASTEXITCODE." -ForegroundColor Red
    Write-Host ""
    Write-Host "  Possible causes:" -ForegroundColor Yellow
    Write-Host "  1) 64-bit JVM used with 32-bit libzkfp.dll" -ForegroundColor Yellow
    Write-Host "     -> Install 32-bit JRE from https://www.java.com (Windows x86 Offline)" -ForegroundColor Yellow
    Write-Host "  2) libzkfp.dll missing or wrong version" -ForegroundColor Yellow
    Write-Host "  3) ZKTeco device driver not installed" -ForegroundColor Yellow
}

Write-Host ""
Read-Host "Press Enter to exit"
