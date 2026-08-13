<?php

declare(strict_types=1);

$products = require dirname(__DIR__) . '/data/products.php';
$outputDir = dirname(__DIR__) . '/assets/images/products';

if (!is_dir($outputDir)) {
    mkdir($outputDir, 0775, true);
}

function art_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function art_wrap(string $text, int $limit = 28, int $maxLines = 2): array
{
    $words = preg_split('/\s+/', trim($text)) ?: [];
    $lines = [];
    $line = '';

    foreach ($words as $word) {
        $candidate = trim($line . ' ' . $word);

        if (mb_strlen($candidate) > $limit && $line !== '') {
            $lines[] = $line;
            $line = $word;
        } else {
            $line = $candidate;
        }

        if (count($lines) === $maxLines) {
            break;
        }
    }

    if ($line !== '' && count($lines) < $maxLines) {
        $lines[] = $line;
    }

    return array_slice($lines, 0, $maxLines);
}

function art_palette(string $category): array
{
    return match ($category) {
        'Maps & Location' => ['#0ea5e9', '#22c55e', '#e0f2fe'],
        'Security', 'Compliance' => ['#6366f1', '#06b6d4', '#ede9fe'],
        'Messaging' => ['#0891b2', '#f59e0b', '#ecfeff'],
        'Analytics', 'Data' => ['#7c3aed', '#38bdf8', '#f5f3ff'],
        'AI Tools' => ['#8b5cf6', '#22d3ee', '#faf5ff'],
        'Infrastructure', 'DevOps', 'Performance' => ['#0284c7', '#0f172a', '#eff6ff'],
        'Payments' => ['#059669', '#38bdf8', '#ecfdf5'],
        'Experience', 'Growth', 'Content' => ['#db2777', '#7c3aed', '#fdf2f8'],
        'Quality', 'Automation' => ['#2563eb', '#14b8a6', '#eff6ff'],
        default => ['#2563eb', '#7c3aed', '#f8fafc'],
    };
}

function art_scene(string $category, string $a, string $b): string
{
    if ($category === 'Maps & Location') {
        return <<<SVG
        <path d="M86 214h430M86 268h430M86 322h430M156 174v190M260 174v190M364 174v190M468 174v190" stroke="{$a}" stroke-opacity=".20" stroke-width="5"/>
        <path d="M126 326c56-88 132-120 224-80 58 26 104 8 142-52" fill="none" stroke="{$b}" stroke-width="14" stroke-linecap="round"/>
        <path d="M318 176c-32 0-58 26-58 58 0 45 58 91 58 91s58-46 58-91c0-32-26-58-58-58z" fill="{$a}"/>
        <circle cx="318" cy="234" r="21" fill="#fff"/>
        SVG;
    }

    if ($category === 'Messaging') {
        return <<<SVG
        <rect x="80" y="178" width="260" height="118" rx="28" fill="#fff" stroke="{$a}" stroke-opacity=".25"/>
        <path d="M118 218h168M118 250h104" stroke="#334155" stroke-width="13" stroke-linecap="round"/>
        <rect x="302" y="260" width="214" height="92" rx="26" fill="{$a}" fill-opacity=".92"/>
        <path d="M335 297h120M335 324h72" stroke="#fff" stroke-width="11" stroke-linecap="round"/>
        <circle cx="500" cy="174" r="34" fill="{$b}"/>
        <text x="489" y="187" fill="#fff" font-family="Arial" font-size="34" font-weight="900">✓</text>
        SVG;
    }

    if (in_array($category, ['Analytics', 'Data'], true)) {
        return <<<SVG
        <rect x="76" y="168" width="176" height="176" rx="32" fill="#fff" stroke="{$a}" stroke-opacity=".20"/>
        <path d="M122 304v-58M164 304v-102M206 304v-78" stroke="{$a}" stroke-width="24" stroke-linecap="round"/>
        <rect x="284" y="168" width="244" height="176" rx="32" fill="#fff" stroke="{$a}" stroke-opacity=".20"/>
        <path d="M318 292l52-62 54 38 68-82" fill="none" stroke="{$b}" stroke-width="13" stroke-linecap="round" stroke-linejoin="round"/>
        <path d="M320 322h162" stroke="#cbd5e1" stroke-width="9" stroke-linecap="round"/>
        SVG;
    }

    if (in_array($category, ['Security', 'Compliance'], true)) {
        return <<<SVG
        <path d="M306 156l132 54v84c0 64-52 104-132 136-80-32-132-72-132-136v-84z" fill="#fff" stroke="{$a}" stroke-width="7" stroke-linejoin="round"/>
        <path d="M252 282l36 36 82-94" fill="none" stroke="{$b}" stroke-width="18" stroke-linecap="round" stroke-linejoin="round"/>
        <rect x="72" y="196" width="132" height="36" rx="18" fill="{$a}" fill-opacity=".18"/>
        <rect x="72" y="250" width="96" height="36" rx="18" fill="{$b}" fill-opacity=".20"/>
        SVG;
    }

    if ($category === 'AI Tools') {
        return <<<SVG
        <rect x="82" y="166" width="206" height="176" rx="36" fill="#fff" stroke="{$a}" stroke-opacity=".22"/>
        <circle cx="144" cy="230" r="20" fill="{$a}"/>
        <circle cx="226" cy="230" r="20" fill="{$b}"/>
        <path d="M150 286h72" stroke="#475569" stroke-width="12" stroke-linecap="round"/>
        <path d="M350 160c28 78 62 112 140 140-78 28-112 62-140 140-28-78-62-112-140-140 78-28 112-62 140-140z" fill="{$a}" fill-opacity=".18" stroke="{$b}" stroke-width="6"/>
        SVG;
    }

    if (in_array($category, ['Infrastructure', 'DevOps', 'Performance'], true)) {
        return <<<SVG
        <rect x="76" y="178" width="448" height="64" rx="22" fill="#fff" stroke="{$a}" stroke-opacity=".25"/>
        <rect x="76" y="264" width="448" height="64" rx="22" fill="#fff" stroke="{$a}" stroke-opacity=".25"/>
        <circle cx="122" cy="210" r="13" fill="#22c55e"/>
        <circle cx="122" cy="296" r="13" fill="#22c55e"/>
        <path d="M164 210h206M164 296h246" stroke="#475569" stroke-width="11" stroke-linecap="round"/>
        <path d="M438 198l36 24-36 24M438 284l36 24-36 24" fill="none" stroke="{$a}" stroke-width="10" stroke-linecap="round" stroke-linejoin="round"/>
        SVG;
    }

    if ($category === 'Payments') {
        return <<<SVG
        <rect x="84" y="184" width="420" height="176" rx="34" fill="#fff" stroke="{$a}" stroke-opacity=".24"/>
        <path d="M84 232h420" stroke="{$a}" stroke-width="30" stroke-opacity=".22"/>
        <path d="M126 286h118M126 322h188" stroke="#475569" stroke-width="12" stroke-linecap="round"/>
        <circle cx="400" cy="312" r="36" fill="{$a}" fill-opacity=".88"/>
        <circle cx="442" cy="312" r="36" fill="{$b}" fill-opacity=".84"/>
        SVG;
    }

    return <<<SVG
    <rect x="76" y="166" width="448" height="178" rx="34" fill="#fff" stroke="{$a}" stroke-opacity=".24"/>
    <rect x="112" y="204" width="160" height="32" rx="16" fill="{$a}" fill-opacity=".20"/>
    <rect x="112" y="260" width="286" height="24" rx="12" fill="#cbd5e1"/>
    <rect x="112" y="306" width="206" height="24" rx="12" fill="#dbeafe"/>
    <circle cx="438" cy="250" r="48" fill="{$a}" fill-opacity=".18" stroke="{$b}" stroke-width="8"/>
    <path d="M414 250h48M438 226v48" stroke="{$b}" stroke-width="10" stroke-linecap="round"/>
    SVG;
}

foreach ($products as $index => $product) {
    [$a, $b, $light] = art_palette((string) $product['category']);
    $nameLines = art_wrap((string) $product['name'], 30, 2);
    $price = '$' . number_format((float) $product['price'], 2) . '/mo';
    $badge = !empty($product['featured']) ? 'FEATURED' : strtoupper((string) $product['category']);
    $titleSvg = '';

    foreach ($nameLines as $lineIndex => $line) {
        $y = 72 + ($lineIndex * 34);
        $titleSvg .= '<text x="50" y="' . $y . '" fill="#0f172a" font-family="Arial, sans-serif" font-size="30" font-weight="900">' . art_h($line) . '</text>' . PHP_EOL;
    }

    $scene = art_scene((string) $product['category'], $a, $b);
    $number = str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT);
    $safeName = art_h((string) $product['name']);
    $safeCategory = art_h((string) $product['category']);
    $safeBadge = art_h($badge);
    $safePrice = art_h($price);

    $svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="640" height="420" viewBox="0 0 640 420" role="img" aria-labelledby="title desc">
  <title id="title">{$safeName} product preview</title>
  <desc id="desc">Professional software marketplace preview artwork for {$safeName}.</desc>
  <defs>
    <linearGradient id="bg" x1="0" x2="1" y1="0" y2="1">
      <stop stop-color="{$light}"/>
      <stop offset=".55" stop-color="#ffffff"/>
      <stop offset="1" stop-color="#dbeafe"/>
    </linearGradient>
    <radialGradient id="glow" cx=".85" cy=".12" r=".7">
      <stop stop-color="{$a}" stop-opacity=".30"/>
      <stop offset="1" stop-color="{$a}" stop-opacity="0"/>
    </radialGradient>
  </defs>
  <rect width="640" height="420" rx="42" fill="url(#bg)"/>
  <rect width="640" height="420" rx="42" fill="url(#glow)"/>
  <rect x="30" y="28" width="580" height="364" rx="34" fill="#f8fafc" fill-opacity=".80" stroke="#ffffff" stroke-width="2"/>
  <rect x="50" y="132" width="540" height="238" rx="34" fill="#eff6ff" stroke="#cbd5e1" stroke-opacity=".72"/>
  <rect x="438" y="44" width="112" height="36" rx="18" fill="{$a}" fill-opacity=".12" stroke="{$a}" stroke-opacity=".26"/>
  <text x="462" y="68" fill="{$a}" font-family="Arial, sans-serif" font-size="15" font-weight="900">{$safeBadge}</text>
  <circle cx="568" cy="62" r="18" fill="{$a}"/>
  <text x="558" y="68" fill="#ffffff" font-family="Arial, sans-serif" font-size="15" font-weight="900">{$number}</text>
  {$titleSvg}
  <text x="50" y="124" fill="#475569" font-family="Arial, sans-serif" font-size="18" font-weight="800">{$safeCategory} · {$safePrice}</text>
  {$scene}
</svg>
SVG;

    file_put_contents($outputDir . '/' . $product['id'] . '.svg', $svg);
}

echo 'Generated ' . count($products) . ' product images.' . PHP_EOL;
