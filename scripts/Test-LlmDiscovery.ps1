[CmdletBinding()]
param(
    [string]$ProjectId = 'stinchcombe-list',
    [string]$Region = 'northamerica-northeast1',
    [string]$MaintenanceJob = 'stinchcombe-list-maintenance',
    [string]$TargetJson,
    [switch]$PurgeStale,
    [int]$LogRetryCount = 12,
    [int]$LogRetryDelaySeconds = 5
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Invoke-GcloudMaintenance {
    param(
        [Parameter(Mandatory)]
        [string]$Action,
        [Parameter(Mandatory)]
        [string]$Marker
    )

    $executionOutput = & gcloud run jobs execute $MaintenanceJob `
        "--project=$ProjectId" `
        "--region=$Region" `
        "--args=$Action" `
        '--wait' `
        '--format=value(metadata.name)' 2>&1
    if ($LASTEXITCODE -ne 0) {
        throw "Maintenance action '$Action' failed: $($executionOutput -join [Environment]::NewLine)"
    }

    $execution = @($executionOutput | ForEach-Object { $_.ToString().Trim() } |
        Where-Object { $_ -match "^$([regex]::Escape($MaintenanceJob))-[a-z0-9-]+$" } |
        Select-Object -Last 1)
    if ($execution.Count -ne 1) {
        throw "Could not determine the Cloud Run execution name from: $($executionOutput -join ' ')"
    }

    $filter = 'resource.type="cloud_run_job" AND resource.labels.job_name="{0}" AND labels."run.googleapis.com/execution_name"="{1}"' -f $MaintenanceJob, $execution[0]
    for ($attempt = 1; $attempt -le $LogRetryCount; $attempt++) {
        $logOutput = & gcloud logging read $filter `
            "--project=$ProjectId" `
            '--freshness=2h' `
            '--limit=1000' `
            '--order=asc' `
            '--format=value(textPayload)' 2>&1
        if ($LASTEXITCODE -ne 0) {
            throw "Could not read logs for $($execution[0]): $($logOutput -join [Environment]::NewLine)"
        }
        $line = @($logOutput | ForEach-Object { $_.ToString() } |
            Where-Object { $_ -like "*$Marker*" } |
            Select-Object -Last 1)
        if ($line.Count -eq 1) {
            $markerIndex = $line[0].IndexOf($Marker, [StringComparison]::Ordinal)
            return [pscustomobject]@{
                Execution = $execution[0]
                Payload = $line[0].Substring($markerIndex + $Marker.Length).Trim()
            }
        }
        if ($attempt -lt $LogRetryCount) {
            Start-Sleep -Seconds $LogRetryDelaySeconds
        }
    }

    throw "Marker '$Marker' was not found in logs for $($execution[0])."
}

function Invoke-HttpGet {
    param([Parameter(Mandatory)][string]$Uri)

    $handler = [System.Net.Http.HttpClientHandler]::new()
    $handler.AllowAutoRedirect = $true
    $client = [System.Net.Http.HttpClient]::new($handler)
    $client.Timeout = [TimeSpan]::FromSeconds(60)
    $client.DefaultRequestHeaders.UserAgent.ParseAdd('Stinchcombe-LLM-Acceptance/1.0')
    try {
        $response = $client.GetAsync($Uri).GetAwaiter().GetResult()
        $body = $response.Content.ReadAsStringAsync().GetAwaiter().GetResult()
        $mediaType = if ($null -ne $response.Content.Headers.ContentType) {
            $response.Content.Headers.ContentType.MediaType
        }
        else {
            ''
        }
        return [pscustomobject]@{
            Uri = $Uri
            Status = [int]$response.StatusCode
            ContentType = [string]$mediaType
            Body = $body
        }
    }
    finally {
        $client.Dispose()
        $handler.Dispose()
    }
}

function Get-JsonLdTypes {
    param($Value)

    $types = [System.Collections.Generic.List[string]]::new()
    if ($null -eq $Value) {
        return $types
    }
    if ($Value -is [System.Collections.IDictionary]) {
        if ($Value.Contains('@type')) {
            foreach ($item in @($Value['@type'])) {
                if ($null -ne $item) {
                    $types.Add([string]$item)
                }
            }
        }
        foreach ($item in $Value.Values) {
            foreach ($nested in @(Get-JsonLdTypes -Value $item)) {
                $types.Add($nested)
            }
        }
        return $types
    }
    if ($Value -is [System.Collections.IEnumerable] -and $Value -isnot [string]) {
        foreach ($item in $Value) {
            foreach ($nested in @(Get-JsonLdTypes -Value $item)) {
                $types.Add($nested)
            }
        }
    }
    return $types
}

function Test-JsonLd {
    param(
        [Parameter(Mandatory)][string]$Html,
        [Parameter(Mandatory)][string]$ExpectedType
    )

    $types = [System.Collections.Generic.List[string]]::new()
    $matches = [regex]::Matches(
        $Html,
        '<script[^>]+type=["'']application/ld\+json["''][^>]*>(?<json>.*?)</script>',
        [System.Text.RegularExpressions.RegexOptions]::IgnoreCase -bor [System.Text.RegularExpressions.RegexOptions]::Singleline
    )
    foreach ($match in $matches) {
        try {
            $value = $match.Groups['json'].Value | ConvertFrom-Json -AsHashtable -Depth 100
            foreach ($type in @(Get-JsonLdTypes -Value $value)) {
                $types.Add($type)
            }
        }
        catch {
            throw "Invalid JSON-LD encountered: $($_.Exception.Message)"
        }
    }
    return [pscustomobject]@{
        Found = $types -contains $ExpectedType
        Types = @($types | Sort-Object -Unique)
        ScriptCount = $matches.Count
    }
}

function Add-Result {
    param(
        [Parameter(Mandatory)][AllowEmptyCollection()][System.Collections.Generic.List[object]]$Results,
        [Parameter(Mandatory)][string]$Kind,
        [Parameter(Mandatory)][string]$Endpoint,
        [Parameter(Mandatory)][bool]$Healthy,
        [Parameter(Mandatory)][string]$Details
    )

    $Results.Add([pscustomobject]@{
        Kind = $Kind
        Endpoint = $Endpoint
        Healthy = $Healthy
        Details = $Details
    })
}

function Find-HealthyMarkdownTarget {
    param(
        [Parameter(Mandatory)][string]$HostName,
        [Parameter(Mandatory)][AllowEmptyCollection()][object[]]$Candidates,
        [Parameter(Mandatory)][string]$Kind,
        [Parameter(Mandatory)][AllowEmptyCollection()][System.Collections.Generic.List[object]]$Results
    )

    foreach ($candidate in $Candidates) {
        $uri = "https://$HostName$($candidate.path).md"
        try {
            $response = Invoke-HttpGet -Uri $uri
            if ($response.Status -eq 200 -and $response.ContentType -eq 'text/markdown' -and -not [string]::IsNullOrWhiteSpace($response.Body)) {
                Add-Result -Results $Results -Kind $Kind -Endpoint $uri -Healthy $true -Details "HTTP 200; $($response.ContentType)"
                return $candidate
            }
        }
        catch {
            continue
        }
    }

    Add-Result -Results $Results -Kind $Kind -Endpoint "https://$HostName" -Healthy $false -Details 'No discovered public candidate returned nonempty text/markdown.'
    return $null
}

function Invoke-AcceptanceSuite {
    param([Parameter(Mandatory)]$Targets)

    $results = [System.Collections.Generic.List[object]]::new()
    foreach ($domain in @($Targets.domains)) {
        $prefix = if ([string]::IsNullOrWhiteSpace([string]$domain.path_prefix)) { '' } else { "/$($domain.path_prefix)" }
        $uri = "https://$($domain.hostname)$prefix/llms.txt"
        try {
            $response = Invoke-HttpGet -Uri $uri
            $hasHeading = $response.Body -match '(?m)^#\s+\S'
            $hasMenu = $response.Body -match '(?m)^- \[[^\]]+\]\(https?://'
            $healthy = $response.Status -eq 200 -and $response.ContentType -eq 'text/markdown' -and $hasHeading -and $hasMenu
            $details = "HTTP $($response.Status); $($response.ContentType); heading=$hasHeading; menu=$hasMenu"
            Add-Result -Results $results -Kind 'llms.txt' -Endpoint $uri -Healthy $healthy -Details $details
        }
        catch {
            Add-Result -Results $results -Kind 'llms.txt' -Endpoint $uri -Healthy $false -Details $_.Exception.Message
        }
    }

    $hostName = [string]$Targets.default_hostname
    if ([string]::IsNullOrWhiteSpace($hostName)) {
        throw 'Production discovery returned no default hostname.'
    }

    $viewCandidates = @($Targets.view_paths | ForEach-Object { [pscustomobject]@{ path = [string]$_ } })
    $viewTarget = Find-HealthyMarkdownTarget -HostName $hostName -Candidates $viewCandidates -Kind 'View Markdown' -Results $results
    $nodeTarget = Find-HealthyMarkdownTarget -HostName $hostName -Candidates @($Targets.nodes) -Kind 'Node Markdown' -Results $results
    $termTarget = Find-HealthyMarkdownTarget -HostName $hostName -Candidates @($Targets.taxonomy_terms) -Kind 'Taxonomy Markdown' -Results $results

    $jsonLdChecks = @(
        [pscustomobject]@{ Kind = 'Home JSON-LD'; Path = '/'; Type = 'WebSite'; Target = [pscustomobject]@{ path = '/' } },
        [pscustomobject]@{ Kind = 'Node JSON-LD'; Path = if ($null -ne $nodeTarget) { $nodeTarget.path } else { '' }; Type = 'WebPage'; Target = $nodeTarget },
        [pscustomobject]@{ Kind = 'Taxonomy JSON-LD'; Path = if ($null -ne $termTarget) { $termTarget.path } else { '' }; Type = 'CollectionPage'; Target = $termTarget }
    )
    foreach ($check in $jsonLdChecks) {
        if ($null -eq $check.Target) {
            Add-Result -Results $results -Kind $check.Kind -Endpoint "https://$hostName" -Healthy $false -Details 'No healthy public source target was discovered.'
            continue
        }
        $uri = "https://$hostName$($check.Path)"
        try {
            $response = Invoke-HttpGet -Uri $uri
            $jsonLd = Test-JsonLd -Html $response.Body -ExpectedType $check.Type
            $healthy = $response.Status -eq 200 -and $response.ContentType -eq 'text/html' -and $jsonLd.Found
            $details = "HTTP $($response.Status); scripts=$($jsonLd.ScriptCount); types=$($jsonLd.Types -join ',')"
            Add-Result -Results $results -Kind $check.Kind -Endpoint $uri -Healthy $healthy -Details $details
        }
        catch {
            Add-Result -Results $results -Kind $check.Kind -Endpoint $uri -Healthy $false -Details $_.Exception.Message
        }
    }

    # Article JSON-LD is configured in the managed Metatag defaults. Exercise
    # it externally only when production contains a published Article; never
    # create content merely to satisfy an acceptance test.
    if (@($Targets.article_nodes).Count -gt 0) {
        $articleTarget = Find-HealthyMarkdownTarget -HostName $hostName -Candidates @($Targets.article_nodes) -Kind 'Article Markdown' -Results $results
        if ($null -ne $articleTarget) {
            $uri = "https://$hostName$($articleTarget.path)"
            try {
                $response = Invoke-HttpGet -Uri $uri
                $jsonLd = Test-JsonLd -Html $response.Body -ExpectedType 'Article'
                $healthy = $response.Status -eq 200 -and $response.ContentType -eq 'text/html' -and $jsonLd.Found
                $details = "HTTP $($response.Status); scripts=$($jsonLd.ScriptCount); types=$($jsonLd.Types -join ',')"
                Add-Result -Results $results -Kind 'Article JSON-LD' -Endpoint $uri -Healthy $healthy -Details $details
            }
            catch {
                Add-Result -Results $results -Kind 'Article JSON-LD' -Endpoint $uri -Healthy $false -Details $_.Exception.Message
            }
        }
    }

    return $results
}

if ([string]::IsNullOrWhiteSpace($TargetJson)) {
    $discovery = Invoke-GcloudMaintenance -Action 'acceptance-targets' -Marker 'STINCHCOMBE_ACCEPTANCE_JSON='
    $TargetJson = $discovery.Payload
    Write-Host "Discovered production targets with maintenance execution $($discovery.Execution)."
}
elseif (Test-Path -LiteralPath $TargetJson) {
    $TargetJson = Get-Content -LiteralPath $TargetJson -Raw
}

$targets = $TargetJson | ConvertFrom-Json -Depth 100
$results = Invoke-AcceptanceSuite -Targets $targets

if ($PurgeStale -and @($results | Where-Object { -not $_.Healthy -and $_.Kind -eq 'llms.txt' }).Count -gt 0) {
    $purge = Invoke-GcloudMaintenance -Action 'purge-llms' -Marker 'STINCHCOMBE_CLOUDFLARE_PURGE='
    $purgeResult = $purge.Payload | ConvertFrom-Json
    Write-Host "Purged $($purgeResult.purged) exact llms.txt URLs with maintenance execution $($purge.Execution); rerunning the complete suite."
    $results = Invoke-AcceptanceSuite -Targets $targets
}

$results | Format-Table Kind, Healthy, Endpoint, Details -AutoSize
$healthyCount = @($results | Where-Object Healthy).Count
$failedCount = @($results | Where-Object { -not $_.Healthy }).Count
$llmsHealthy = @($results | Where-Object { $_.Kind -eq 'llms.txt' -and $_.Healthy }).Count
$llmsFailed = @($results | Where-Object { $_.Kind -eq 'llms.txt' -and -not $_.Healthy }).Count

Write-Host "Acceptance totals: healthy=$healthyCount failed=$failedCount total=$($results.Count)"
Write-Host "llms.txt totals: healthy=$llmsHealthy failed=$llmsFailed total=$($llmsHealthy + $llmsFailed)"

if ($failedCount -gt 0) {
    exit 1
}
