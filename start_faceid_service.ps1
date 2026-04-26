$ErrorActionPreference = "Stop"

$projectDir = Get-Location
$faceIdDir = Join-Path $projectDir "scripts\faceid"
$venvDir = Join-Path $faceIdDir ".venv"
$pythonPath = Join-Path $venvDir "Scripts\python.exe"
$scriptPath = Join-Path $faceIdDir "face_id_api.py"
$encodingsDir = Join-Path $projectDir "var\faceid\encodings"
$modelsDir = Join-Path $projectDir "var\faceid\models"

function Test-FaceIdVenv {
    param([string]$PythonExe)

    if (-not (Test-Path $PythonExe)) {
        return $false
    }

    try {
        # Some broken copied venv launchers print noisy "No Python at ..." errors to stderr.
        # Redirect both streams to keep startup output clean while we probe validity.
        & $PythonExe -c "import sys; print(sys.version)" 1>$null 2>$null
        return $LASTEXITCODE -eq 0
    }
    catch {
        return $false
    }
}

function Get-BasePythonCommand {
    if (Get-Command py -ErrorAction SilentlyContinue) {
        return "py -3.12"
    }
    if (Get-Command python -ErrorAction SilentlyContinue) {
        return "python"
    }

    throw "Python introuvable. Installe Python 3.12 puis relance ce script."
}

function Ensure-FaceIdVenv {
    param(
        [string]$VenvDirectory,
        [string]$PythonExe
    )

    if (Test-FaceIdVenv -PythonExe $PythonExe) {
        return
    }

    Write-Host "Face ID venv absent/casse. Recreation en cours..."
    if (Test-Path $VenvDirectory) {
        Remove-Item -Recurse -Force $VenvDirectory
    }

    $basePythonCmd = Get-BasePythonCommand
    Invoke-Expression "$basePythonCmd -m venv `"$VenvDirectory`""

    & $PythonExe -m pip install --upgrade pip
    & $PythonExe -m pip install flask numpy opencv-python
}

# Create directories if they don't exist
if (-not (Test-Path $encodingsDir)) {
    New-Item -ItemType Directory -Path $encodingsDir -Force | Out-Null
}

if (-not (Test-Path $modelsDir)) {
    New-Item -ItemType Directory -Path $modelsDir -Force | Out-Null
}

Ensure-FaceIdVenv -VenvDirectory $venvDir -PythonExe $pythonPath

Write-Host "Starting Face ID Service..."
Write-Host "Python: $pythonPath"
Write-Host "Script: $scriptPath"

# Start the service
& $pythonPath $scriptPath --host 127.0.0.1 --port 8765 --storage-dir $encodingsDir --models-dir $modelsDir --threshold 0.28