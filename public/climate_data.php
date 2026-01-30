<?php
require_once __DIR__ . '/../cors.php';

header("Content-Type: application/json; charset=UTF-8");

$sourceUrl = "https://metservice.intnet.mu/climate-services/climate-info-and-data.php";
$cacheTtlSeconds = 1800;
$cacheFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "sentinel_climate_cache.json";

function fetch_html($url) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, "SentinelClimateFetcher/1.0");
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($response === false || $httpCode < 200 || $httpCode >= 300) {
        throw new RuntimeException("Failed to fetch climate data: " . ($curlErr ?: "HTTP " . $httpCode));
    }
    return $response;
}

function parse_documents($html, $sourceUrl) {
    $documents = [];
    $parts = parse_url($sourceUrl);
    $baseOrigin = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '');
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML($html);
    $xpath = new DOMXPath($dom);
    $links = $xpath->query("//a[contains(@href, '.pdf')]");
    if ($links === false) {
        libxml_clear_errors();
        return $documents;
    }
    foreach ($links as $link) {
        if (!($link instanceof DOMElement)) {
            continue;
        }
        $href = trim($link->getAttribute("href"));
        $label = trim($link->textContent);
        if ($href === "") {
            continue;
        }
        if (!preg_match('#^https?://#', $href)) {
            $href = rtrim($baseOrigin, "/") . "/" . ltrim($href, "/");
        }
        $labelLower = strtolower($label);
        $isClimate = strpos($labelLower, "climate") !== false ||
            strpos($labelLower, "rainfall") !== false ||
            strpos($labelLower, "temperature") !== false ||
            strpos($labelLower, "bulletin") !== false ||
            strpos($labelLower, "summary") !== false;
        if (!$isClimate) {
            continue;
        }
        $documents[] = [
            "title" => $label ?: basename(parse_url($href, PHP_URL_PATH)),
            "url" => $href
        ];
    }
    libxml_clear_errors();
    return $documents;
}

function parse_current_conditions($html) {
    $conditions = [];
    $pattern = '/<li>\\s*<h3>Current conditions<\\/h3>.*?<p class="vacoas_plaisance">([^<]+)<\\/p>\\s*<p class="conditions">([^<]+)<\\/p>.*?<p class="temperature">([^<]+)<\\/p>.*?<p><b>Wind:<\\/b>\\s*<span class="fgrey">([^<]+)<\\/span><\\/p>\\s*<p><b>Humidity:<\\/b>\\s*<span class="fgrey">([^<]+)<\\/span><\\/p>/si';
    if (preg_match_all($pattern, $html, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $conditions[] = [
                "station" => html_entity_decode(trim($match[1])),
                "condition" => html_entity_decode(trim($match[2])),
                "temperature" => html_entity_decode(trim($match[3])),
                "wind" => html_entity_decode(trim($match[4])),
                "humidity" => html_entity_decode(trim($match[5]))
            ];
        }
    }
    return $conditions;
}

try {
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < $cacheTtlSeconds)) {
        $cached = json_decode(file_get_contents($cacheFile), true);
        if (is_array($cached)) {
            echo json_encode($cached);
            exit;
        }
    }

    $html = fetch_html($sourceUrl);
    $data = [
        "success" => true,
        "source" => $sourceUrl,
        "fetched_at" => gmdate("c"),
        "data" => [
            "current_conditions" => parse_current_conditions($html),
            "documents" => parse_documents($html, $sourceUrl)
        ]
    ];

    file_put_contents($cacheFile, json_encode($data));
    echo json_encode($data);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "error" => $e->getMessage()
    ]);
}
