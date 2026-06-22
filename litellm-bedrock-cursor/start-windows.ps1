Set-Location $PSScriptRoot

if (!(Test-Path ".env")) {
  Write-Error "Missing .env file"
  exit 1
}

python -m venv .venv
.venv\Scripts\Activate.ps1
python -m pip install --upgrade pip
pip install "litellm[proxy]" boto3 python-dotenv

Get-Content .env | ForEach-Object {
  if ($_ -match "^\s*([^#][^=]+)=(.*)$") {
    [Environment]::SetEnvironmentVariable($matches[1].Trim(), $matches[2].Trim(), "Process")
  }
}

if ($null -eq $env:LITELLM_PORT -or $env:LITELLM_PORT -eq "") { $env:LITELLM_PORT = "4000" }
Write-Host "LiteLLM: port $($env:LITELLM_PORT)  OpenAI: http://127.0.0.1:$($env:LITELLM_PORT)/v1"
& litellm --config config.yaml --port $env:LITELLM_PORT
