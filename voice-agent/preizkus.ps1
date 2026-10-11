# Zazene nabor preizkusnih pogovorov in izpis shrani v dnevnik.
#
# Zagon iz te mape:
#     .\preizkus.ps1                              # scenarios.yaml
#     .\preizkus.ps1 -Nabor scenarios-napadi.yaml  # napadalni nabor
#
# Dnevniki gredo v asistent\logs\, ker jih .gitignore in .cpanel.yml tam ze
# izlocita iz gita in iz deploya.
#
# ponytail: izpis zajamemo tak, kot ga da lk, in padcev ne stejemo sami.
# Ko bo lk agent simulate znal strojno berljiv izid, ga uporabi in tu izpisi
# samo povzetek.

param([string]$Nabor = 'scenarios.yaml')

$ErrorActionPreference = 'Stop'
Set-Location $PSScriptRoot

if ($null -eq (Get-Command lk -ErrorAction SilentlyContinue)) {
    throw "lk ni v PATH. Brez LiveKit CLI simulacija ne stece."
}
if (-not (Test-Path .env)) {
    throw ".env manjka v tej mapi. Brez kljucev se simulacija ne poveze."
}
if (-not (Test-Path $Nabor)) {
    throw "$Nabor ne obstaja v tej mapi."
}

$mapa = Join-Path $PSScriptRoot '..\logs'
if (-not (Test-Path $mapa)) { New-Item -ItemType Directory -Path $mapa | Out-Null }
$oznaka = [System.IO.Path]::GetFileNameWithoutExtension($Nabor)
$dnevnik = Join-Path $mapa ("preizkus-" + $oznaka + '-' + (Get-Date -Format 'yyyyMMdd-HHmm') + '.log')

$glava = "=== Zagon " + (Get-Date -Format 'yyyy-MM-dd HH:mm') + " | $Nabor ==="
$glava | Out-File -FilePath $dnevnik -Encoding utf8
Write-Output $glava

# Preusmeritev dela cmd in ne PowerShell: v 5.1 bi `2>&1` vsako vrstico
# stderr zavil v ErrorRecord in skript bi padel na prvem opozorilu lk-ja.
# --concurrency 2: pri vseh 17 hkrati delavec sob ni prevzel in trije pogovori
# so padli z "No agent joined room within 30s", ne zaradi vedenja.
cmd /c "lk agent simulate --scenarios $Nabor --agent-name tatjana --concurrency 2 2>&1" |
    ForEach-Object { Write-Output $_; $_ | Out-File -FilePath $dnevnik -Append -Encoding utf8 }

$koda = $LASTEXITCODE
$noga = "=== Konec " + (Get-Date -Format 'HH:mm') + " | izhodna koda $koda ==="
$noga | Out-File -FilePath $dnevnik -Append -Encoding utf8
Write-Output $noga
Write-Output "Dnevnik: $dnevnik"

if ($koda -ne 0) { exit $koda }
