$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
$outDir = Join-Path $root 'assets\images\product-logos'

if (!(Test-Path $outDir)) {
    New-Item -ItemType Directory -Path $outDir | Out-Null
}

$phpCode = @'
$products = require 'data/products.php';
echo json_encode($products, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
'@

$products = (& php -r $phpCode) | ConvertFrom-Json

$brandIcons = @{
    'sentry-error-observability-suite' = @{ Slug = 'sentry'; Color = '362D59' }
    'google-maps-api-platform' = @{ Slug = 'googlemaps'; Color = '34A853' }
    'posthog-product-analytics-cloud' = @{ Slug = 'posthog'; Color = '000000' }
    'neon-serverless-postgres-scale-pack' = @{ Slug = 'neon'; Color = '00E599' }
    'redis-edge-cache-layer' = @{ Slug = 'redis'; Color = 'DC382D' }
    'openai-ecosystem' = @{ Slug = 'openai'; Color = '111827' }
    'logrocket-session-replay-studio' = @{ Slug = 'logrocket'; Color = '764ABC' }
    'leaflet-mapbox-vector-tiles' = @{ Slug = 'leaflet'; Color = '199900' }
    'stripe-billing-connector' = @{ Slug = 'stripe'; Color = '635BFF' }
    'gemini-ecosystem' = @{ Slug = 'googlegemini'; Color = '8E75B2' }
    'cloudflare-performance-shield' = @{ Slug = 'cloudflare'; Color = 'F38020' }
    'github-actions-ci-minutes' = @{ Slug = 'githubactions'; Color = '2088FF' }
    'docker-registry-private-pack' = @{ Slug = 'docker'; Color = '2496ED' }
    'openapi-documentation-studio' = @{ Slug = 'openapiinitiative'; Color = '6BA539' }
    'elastic-log-search-lite' = @{ Slug = 'elastic'; Color = '005571' }
    'object-storage-backup-vault' = @{ Slug = 'amazons3'; Color = '569A31' }
    'transactional-email-relay' = @{ Slug = 'sendgrid'; Color = '51A9E3' }
    'vector-database-starter' = @{ Slug = 'pinecone'; Color = '000000' }
    'identity-sso-connector' = @{ Slug = 'auth0'; Color = 'EB5424' }
    'two-factor-auth-api' = @{ Slug = 'authy'; Color = 'EC1C24' }
    'headless-cms-editorial-seat' = @{ Slug = 'contentful'; Color = '2478CC' }
    'crm-sync-bridge' = @{ Slug = 'hubspot'; Color = 'FF7A59' }
    'warehouse-etl-orchestrator' = @{ Slug = 'apacheairflow'; Color = '017CEE' }
    'business-intelligence-dashboard-kit' = @{ Slug = 'tableau'; Color = 'E97627' }
    'image-optimization-cdn' = @{ Slug = 'cloudinary'; Color = '3448C5' }
    'video-transcoding-worker' = @{ Slug = 'ffmpeg'; Color = '007808' }
    'background-job-queue' = @{ Slug = 'rabbitmq'; Color = 'FF6600' }
    'domain-dns-audit-pack' = @{ Slug = 'cloudflare'; Color = 'F38020' }
    'test-automation-grid' = @{ Slug = 'selenium'; Color = '43B02A' }
    'low-code-internal-tools-kit' = @{ Slug = 'retool'; Color = '3D3D3D' }
}

$faviconDomains = @{
    'ip-geolocation-api-pro-tier' = 'ipgeolocation.io'
    'openai-ecosystem' = 'openai.com'
    'logrocket-session-replay-studio' = 'logrocket.com'
    'mnotify-sms-gateway-prepaid-bulk' = 'mnotify.com'
    'object-storage-backup-vault' = 'aws.amazon.com'
    'transactional-email-relay' = 'sendgrid.com'
    'vector-database-starter' = 'pinecone.io'
    'two-factor-auth-api' = 'authy.com'
    'business-intelligence-dashboard-kit' = 'tableau.com'
}

$palette = @(
    @{ A = '#e0f2fe'; B = '#eef2ff'; C = '#0284c7' },
    @{ A = '#f5f3ff'; B = '#ecfeff'; C = '#7c3aed' },
    @{ A = '#ecfdf5'; B = '#f0f9ff'; C = '#059669' },
    @{ A = '#fff7ed'; B = '#f8fafc'; C = '#ea580c' },
    @{ A = '#fdf2f8'; B = '#eff6ff'; C = '#db2777' }
)

function ConvertTo-SvgText([string] $value) {
    return [System.Security.SecurityElement]::Escape($value)
}

function Get-Initials([string] $name) {
    $stopWords = @('api', 'pro', 'the', 'and', 'for', 'of', 'with', 'pack', 'suite', 'kit', 'engine', 'toolkit')
    $words = [regex]::Matches($name, '[A-Za-z0-9]+') | ForEach-Object { $_.Value }
    $chosen = @()

    foreach ($word in $words) {
        if ($stopWords -contains $word.ToLowerInvariant()) { continue }
        $chosen += $word.Substring(0, 1).ToUpperInvariant()
        if ($chosen.Count -ge 3) { break }
    }

    if ($chosen.Count -eq 0) {
        return 'MDP'
    }

    return ($chosen -join '')
}

function New-CustomLogoSvg($product, [int] $index, [string] $path) {
    $colors = $palette[$index % $palette.Count]
    $initials = ConvertTo-SvgText (Get-Initials $product.name)
    $label = ConvertTo-SvgText $product.name

    $svg = @"
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" role="img" aria-label="$label logo">
  <defs>
    <linearGradient id="bg" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0" stop-color="$($colors.A)"/>
      <stop offset="1" stop-color="$($colors.B)"/>
    </linearGradient>
    <linearGradient id="mark" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0" stop-color="$($colors.C)"/>
      <stop offset="1" stop-color="#7c3aed"/>
    </linearGradient>
    <filter id="shadow" x="-20%" y="-20%" width="140%" height="140%">
      <feDropShadow dx="0" dy="24" stdDeviation="24" flood-color="#0f172a" flood-opacity=".18"/>
    </filter>
  </defs>
  <rect width="512" height="512" rx="118" fill="url(#bg)"/>
  <circle cx="392" cy="96" r="118" fill="#ffffff" opacity=".48"/>
  <circle cx="112" cy="426" r="132" fill="#38bdf8" opacity=".15"/>
  <rect x="116" y="116" width="280" height="280" rx="84" fill="#ffffff" opacity=".94" filter="url(#shadow)"/>
  <circle cx="256" cy="256" r="104" fill="url(#mark)" opacity=".12"/>
  <text x="256" y="286" text-anchor="middle" font-family="Inter, Arial, sans-serif" font-size="108" font-weight="900" fill="url(#mark)" letter-spacing="-7">$initials</text>
</svg>
"@

    Set-Content -LiteralPath $path -Value $svg -Encoding UTF8
}

$downloaded = 0
$favicons = 0
$generated = 0
$failedBrands = @()
$failedFavicons = @()
$index = 0

foreach ($product in $products) {
    $id = [string] $product.id
    $out = Join-Path $outDir ($id + '.svg')
    $faviconOut = Join-Path $outDir ($id + '.png')

    if ($brandIcons.ContainsKey($id)) {
        if (Test-Path -LiteralPath $out) {
            $downloaded++
            $index++
            continue
        }

        $brand = $brandIcons[$id]
        $url = "https://cdn.simpleicons.org/$($brand.Slug)/$($brand.Color)"

        try {
            Invoke-WebRequest -Uri $url -UseBasicParsing -OutFile $out -TimeoutSec 30
            $downloaded++
            Start-Sleep -Milliseconds 80
            $index++
            continue
        } catch {
            $failedBrands += "$id ($($brand.Slug)): $($_.Exception.Message)"
        }
    }

    if ($faviconDomains.ContainsKey($id)) {
        if (Test-Path -LiteralPath $faviconOut) {
            $favicons++
            $index++
            continue
        }

        $domain = $faviconDomains[$id]
        $faviconUrl = "https://www.google.com/s2/favicons?domain=$domain&sz=256"

        try {
            Invoke-WebRequest -Uri $faviconUrl -UseBasicParsing -OutFile $faviconOut -TimeoutSec 30
            $favicons++
            Start-Sleep -Milliseconds 80
            $index++
            continue
        } catch {
            $failedFavicons += "$id ($domain): $($_.Exception.Message)"
        }
    }

    New-CustomLogoSvg -product $product -index $index -path $out
    $generated++
    $index++
}

Write-Output "Downloaded $downloaded brand logo assets."
Write-Output "Downloaded $favicons favicon/logo fallback assets."
Write-Output "Generated $generated custom logo tiles."

if ($failedBrands.Count -gt 0) {
    Write-Output "Brand logo fallbacks generated for:"
    $failedBrands | ForEach-Object { Write-Output $_ }
}

if ($failedFavicons.Count -gt 0) {
    Write-Output "Favicon fallbacks generated for:"
    $failedFavicons | ForEach-Object { Write-Output $_ }
}
