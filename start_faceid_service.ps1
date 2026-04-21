$projectDir = Get-Location
$pythonPath = Join-Path $projectDir "scripts\faceid\.venv\Scripts\python.exe"
$scriptPath = Join-Path $projectDir "scripts\faceid\face_id_api.py"
$encodingsDir = Join-Path $projectDir "var\faceid\encodings"
$modelsDir = Join-Path $projectDir "var\faceid\models"

# Create directories if they don't exist
if (-not (Test-Path $encodingsDir)) {
    New-Item -ItemType Directory -Path $encodingsDir -Force | Out-Null
}

if (-not (Test-Path $modelsDir)) {
    New-Item -ItemType Directory -Path $modelsDir -Force | Out-Null
}

Write-Host "Starting Face ID Service..."
Write-Host "Python: $pythonPath"
Write-Host "Script: $scriptPath"

# Start the service
& $pythonPath $scriptPath --host 127.0.0.1 --port 8765 --storage-dir $encodingsDir --models-dir $modelsDir --threshold 0.28