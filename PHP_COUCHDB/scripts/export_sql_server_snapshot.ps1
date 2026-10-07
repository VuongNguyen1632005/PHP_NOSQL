param(
    [string] $Server = 'localhost',
    [string] $Database = 'ShopQuanAo',
    [string] $FileName = ('sql-server-' + (Get-Date -Format 'yyyyMMdd-HHmmss') + '.json')
)

$ErrorActionPreference = 'Stop'

if ($FileName -notmatch '^[A-Za-z0-9][A-Za-z0-9._-]*\.json$') {
    throw 'FileName must be a simple .json file name.'
}

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$queryPath = Join-Path $projectRoot 'database\couchdb\migrations\export_sql_server_snapshot.sql'
$outputDirectory = Join-Path $projectRoot 'var\migration-exports'
$outputPath = Join-Path $outputDirectory $FileName
if (Test-Path -LiteralPath $outputPath) { throw "Refusing to overwrite existing export: $FileName" }
if (-not (Test-Path -LiteralPath $outputDirectory)) {
    New-Item -ItemType Directory -Path $outputDirectory | Out-Null
}

$builder = [System.Data.SqlClient.SqlConnectionStringBuilder]::new()
$builder['Data Source'] = $Server
$builder['Initial Catalog'] = $Database
$builder['Integrated Security'] = $true
$builder['Encrypt'] = $false
$builder['TrustServerCertificate'] = $true
$builder['Connect Timeout'] = 15

$connection = [System.Data.SqlClient.SqlConnection]::new($builder.ConnectionString)
$transaction = $null
try {
    $connection.Open()
    $transaction = $connection.BeginTransaction([System.Data.IsolationLevel]::Snapshot)
    $command = $connection.CreateCommand()
    $command.Transaction = $transaction
    $command.CommandTimeout = 300
    $command.CommandText = [System.IO.File]::ReadAllText($queryPath, [System.Text.Encoding]::UTF8)
    # SQL Server may return a large FOR JSON result as multiple rows/chunks.
    # ExecuteScalar reads only the first chunk (commonly 2033 characters),
    # leaving a syntactically truncated snapshot for realistic datasets.
    $reader = $command.ExecuteReader()
    $jsonBuilder = [System.Text.StringBuilder]::new()
    try {
        while ($reader.Read()) {
            if (-not $reader.IsDBNull(0)) {
                [void] $jsonBuilder.Append([string] $reader.GetValue(0))
            }
        }
    }
    finally {
        $reader.Dispose()
    }
    $json = $jsonBuilder.ToString()
    if ([string]::IsNullOrWhiteSpace($json)) { throw 'SQL Server returned an empty snapshot.' }

    # Reject malformed/truncated output before creating an export artifact.
    $parsed = ConvertFrom-Json -InputObject $json
    if ($parsed.format_version -ne 1 -or $parsed.source_database -ne $Database) {
        throw 'SQL Server snapshot metadata is invalid or names a different database.'
    }
    foreach ($table in @('customers','staff','categories','sizes','products','variants','images','vouchers','shipping_methods','carts','orders','order_items','reviews')) {
        if ($null -eq $parsed.$table) { throw "Snapshot is missing table collection: $table" }
    }

    $transaction.Commit()
    $utf8 = [System.Text.UTF8Encoding]::new($false)
    [System.IO.File]::WriteAllText($outputPath, $json + [Environment]::NewLine, $utf8)
    Write-Output ("Snapshot exported to var/migration-exports/{0}; rows: customer={1}, staff={2}, product={3}, order={4}, review={5}." -f `
        $FileName, @($parsed.customers).Count, @($parsed.staff).Count, @($parsed.products).Count, @($parsed.orders).Count, @($parsed.reviews).Count)
    Write-Warning 'The export contains personal data. It omits legacy password columns; protect the file and do not commit or share it.'
}
catch {
    if ($null -ne $transaction) { try { $transaction.Rollback() } catch {} }
    throw
}
finally {
    if ($null -ne $connection) { $connection.Dispose() }
}
