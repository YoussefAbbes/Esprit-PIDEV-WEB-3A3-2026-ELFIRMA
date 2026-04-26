@echo off
setlocal enabledelayedexpansion

cd /d "%~dp0"

echo.
echo ============================================================
echo   ZKFinger HTTP Bridge Server - Startup Script
echo   Compile: 64-bit javac  ^|  Run: 32-bit java (x86)
echo ============================================================
echo.

REM ── Check required files ─────────────────────────────────────
if not exist "lib\ZKFingerReader.jar" (
    echo [ERROR] SDK JAR not found: %~dp0lib\ZKFingerReader.jar
    echo         Place ZKFingerReader.jar inside the lib\ folder.
    echo.
    pause
    exit /b 1
)

if not exist "FingerprintBridgeServer.java" (
    echo [ERROR] Source file not found: %~dp0FingerprintBridgeServer.java
    echo.
    pause
    exit /b 1
)

if not exist "libzkfp.dll" (
    echo [ERROR] libzkfp.dll not found in: %~dp0
    echo         Copy the 32-bit libzkfp.dll next to this script.
    echo.
    pause
    exit /b 1
)

echo [OK]   libzkfp.dll found ^(32-bit DLL^).
echo.

REM ── 1. Find 64-bit javac for compilation ─────────────────────
echo [INFO] Looking for 64-bit javac for compilation...

set JAVAC_EXE=
where javac >nul 2>&1
if not errorlevel 1 (
    for /f "delims=" %%J in ('where javac 2^>nul') do (
        if "!JAVAC_EXE!"=="" set JAVAC_EXE=%%J
    )
    echo [OK]   Found javac on PATH: !JAVAC_EXE!
)

REM Search 64-bit Program Files for JDK
if "!JAVAC_EXE!"=="" (
    for /d %%D in (
        "%ProgramFiles%\Java\jdk*"
        "%ProgramFiles%\Eclipse Adoptium\jdk*"
        "%ProgramFiles%\Microsoft\jdk*"
        "%ProgramFiles%\Amazon Corretto\jdk*"
    ) do (
        if exist "%%D\bin\javac.exe" (
            if "!JAVAC_EXE!"=="" set JAVAC_EXE=%%D\bin\javac.exe
        )
    )
    if not "!JAVAC_EXE!"=="" (
        echo [OK]   Found 64-bit javac: !JAVAC_EXE!
    )
)

if "!JAVAC_EXE!"=="" (
    echo [ERROR] No javac found.
    echo         Install a 64-bit JDK from https://adoptium.net/ and add it to PATH.
    echo.
    pause
    exit /b 1
)

REM ── 2. Find 32-bit java for running ──────────────────────────
echo.
echo [INFO] Looking for 32-bit java ^(x86^) for running...

set JAVA32_EXE=

REM Search common x86 Program Files locations
for /d %%D in (
    "%ProgramFiles(x86)%\Java\jre*"
    "%ProgramFiles(x86)%\Java\jdk*"
    "%ProgramFiles(x86)%\Eclipse Adoptium\jre*"
    "%ProgramFiles(x86)%\Eclipse Adoptium\jdk*"
    "%ProgramFiles(x86)%\Amazon Corretto\jre*"
    "%ProgramFiles(x86)%\Amazon Corretto\jdk*"
) do (
    if exist "%%D\bin\java.exe" (
        if "!JAVA32_EXE!"=="" set JAVA32_EXE=%%D\bin\java.exe
    )
)

if not "!JAVA32_EXE!"=="" (
    echo [OK]   Found 32-bit java: !JAVA32_EXE!
) else (
    echo [WARN] No 32-bit java found in Program Files ^(x86^).
    echo.
    echo        libzkfp.dll is 32-bit. Using a 64-bit JVM will cause:
    echo           UnsatisfiedLinkError: ZKFPService.Initialize^(^)
    echo.
    echo        SOLUTION: Install the 32-bit JRE from:
    echo           https://www.java.com/en/download/manual.jsp
    echo           Choose "Windows x86 Offline"
    echo.
    echo        Falling back to PATH java ^(will likely fail^)...
    set JAVA32_EXE=java
)

REM ── 3. Show versions ─────────────────────────────────────────
echo.
echo [INFO] javac version:
"!JAVAC_EXE!" -version
echo.
echo [INFO] java ^(runtime^) version:
"!JAVA32_EXE!" -version
echo.

REM ── 4. Compile targeting Java 8 ──────────────────────────────
echo [INFO] Compiling FingerprintBridgeServer.java ^(target Java 8^)...
echo.

"!JAVAC_EXE!" -source 8 -target 8 -cp "lib\ZKFingerReader.jar" FingerprintBridgeServer.java

if errorlevel 1 (
    echo.
    echo [ERROR] Compilation failed! See errors above.
    echo.
    pause
    exit /b 1
)

echo.
echo [OK]   Compilation successful ^(Java 8 bytecode^).
echo.

REM ── 5. Check port 8085 ───────────────────────────────────────
netstat -ano | findstr ":8085 " | findstr "LISTENING" >nul 2>&1
if not errorlevel 1 (
    echo [WARN] Port 8085 is already in use. Another instance may be running.
    echo.
)

REM ── 6. Launch with 32-bit JRE ────────────────────────────────
echo [INFO] Starting ZKFinger HTTP Bridge on http://localhost:8085 ...
echo [INFO] JVM ^(32-bit^) : !JAVA32_EXE!
echo [INFO] DLL path     : %~dp0
echo [INFO] Press Ctrl+C to stop.
echo.
echo ============================================================
echo.

"!JAVA32_EXE!" ^
    -cp ".;lib\ZKFingerReader.jar" ^
    "-Djava.library.path=." ^
    "-Djava.util.logging.SimpleFormatter.format=[%%1$tT] [%%4$s] %%5$s%%6$s%%n" ^
    FingerprintBridgeServer

if errorlevel 1 (
    echo.
    echo [ERROR] Server exited with an error. See messages above.
    echo.
    echo   Possible causes:
    echo   1^) 64-bit JVM used with 32-bit libzkfp.dll
    echo      Install 32-bit JRE: https://www.java.com ^(Windows x86 Offline^)
    echo   2^) libzkfp.dll missing or wrong version
    echo   3^) ZKTeco device driver not installed
)

echo.
pause
