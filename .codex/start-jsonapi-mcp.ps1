$ErrorActionPreference = 'Stop'

$configPath = Join-Path $PSScriptRoot 'jsonapi.env'
if (Test-Path -LiteralPath $configPath) {
    foreach ($line in Get-Content -LiteralPath $configPath) {
        $trimmed = $line.Trim()
        if (-not $trimmed -or $trimmed.StartsWith('#')) {
            continue
        }

        $parts = $trimmed.Split('=', 2)
        if ($parts.Count -ne 2) {
            throw "Invalid line in $configPath. Expected NAME=value."
        }

        [Environment]::SetEnvironmentVariable($parts[0].Trim(), $parts[1].Trim(), 'Process')
    }
}

$server = Join-Path $PSScriptRoot 'jsonapi_mcp.py'
$candidates = @(
    $env:STINCHCOMBE_PYTHON,
    (Join-Path $env:USERPROFILE '.cache\codex-runtimes\codex-primary-runtime\dependencies\python\python.exe'),
    'py.exe',
    'python.exe',
    'python3.exe'
) | Where-Object { $_ }

foreach ($candidate in $candidates) {
    try {
        if ($candidate -eq 'py.exe') {
            & $candidate -3 $server
        }
        else {
            & $candidate $server
        }
        exit $LASTEXITCODE
    }
    catch [System.Management.Automation.CommandNotFoundException] {
        continue
    }
}

throw 'Python 3 was not found. Set STINCHCOMBE_PYTHON to a Python 3 executable.'
