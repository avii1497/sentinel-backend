<?php
require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../lib/validation.php';

header("Content-Type: application/json; charset=UTF-8");

$sourceUrl = "https://metservice.intnet.mu/climate-services/climate-info-and-data.php";

function fetch_html($url) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, "SentinelClimateAssistant/1.0");
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
    $input = json_decode(file_get_contents("php://input"), true);
    $input = sanitize_array(is_array($input) ? $input : []);
    $region = v_string($input['region'] ?? 'Mauritius', 'region', 200, 0, false);
    if ($region === '') $region = 'Mauritius';
    $focus = v_string($input['focus'] ?? 'coastal risk, heat stress, and water resilience', 'focus', 500, 0, false);
    if ($focus === '') $focus = 'coastal risk, heat stress, and water resilience';

    $html = fetch_html($sourceUrl);
    $documents = parse_documents($html, $sourceUrl);
    $conditions = parse_current_conditions($html);

    $apiKey = getenv("OPENROUTER_API_KEY");
    if (!$apiKey && isset($_SERVER['OPENROUTER_API_KEY'])) {
        $apiKey = $_SERVER['OPENROUTER_API_KEY'];
    }
    if (!$apiKey && isset($_ENV['OPENROUTER_API_KEY'])) {
        $apiKey = $_ENV['OPENROUTER_API_KEY'];
    }
    if (!$apiKey) {
        throw new RuntimeException("Missing API key. Set OPENROUTER_API_KEY.");
    }

    $context = [
        "source" => $sourceUrl,
        "documents" => array_slice($documents, 0, 8),
        "current_conditions" => array_slice($conditions, 0, 6)
    ];

    $systemPrompt = "You are a climate insight assistant for Mauritius. "
        . "Use only the dataset provided. Return JSON with keys: summary, key_takeaways (array), "
        . "risk_flags (array), recommended_actions (array). Keep it concise.";

    $userPrompt = "Region focus: " . $region . ". "
        . "Focus topics: " . $focus . ". "
        . "Dataset: " . json_encode($context);

    $payload = [
        "model" => "openai/gpt-4o-mini",
        "messages" => [
            ["role" => "system", "content" => $systemPrompt],
            ["role" => "user", "content" => $userPrompt]
        ]
    ];

    $ch = curl_init("https://openrouter.ai/api/v1/chat/completions");
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json",
        "Authorization: Bearer " . $apiKey,
        "HTTP-Referer: " . $sourceUrl,
        "X-Title: Sentinel Climate Assistant"
    ]);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        throw new RuntimeException("Request failed: " . $curlErr);
    }

    $decoded = json_decode($response, true);
    $content = $decoded['choices'][0]['message']['content'] ?? null;
    $insight = $content ? json_decode($content, true) : null;

    echo json_encode([
        "success" => true,
        "data" => $insight ?: ["raw" => $content],
        "source" => $sourceUrl
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "error" => $e->getMessage()
    ]);
}
