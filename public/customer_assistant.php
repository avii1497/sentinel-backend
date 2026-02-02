<?php
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../lib/validation.php';
header("Content-Type: application/json; charset=UTF-8");

try {
    $db = new Database();
    $pdo = $db->getPdo();

    // --------------------------------------
    // 🧠 Read inputs
    // --------------------------------------
    $input = json_decode(file_get_contents("php://input"), true);
    $input = sanitize_array(is_array($input) ? $input : []);

    $message = v_string($input['message'] ?? null, 'message', 4000);
    $mode = v_enum($input['mode'] ?? 'general', 'mode', ['general', 'search', 'investment', 'property_chat'], false) ?? 'general';
    $propertyId = v_int($input['property_id'] ?? null, 'property id', 1, 2147483647, false);

    // --------------------------------------
    // 🌐 Detect base URL (same as your code)
    // --------------------------------------
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') 
        ? "https://" 
        : "http://";

    $host = $_SERVER['HTTP_HOST'];
    $baseGalleryURL = $protocol . $host . "/sentinel-backend/properties/uploads/gallery/";


    // ----------------------------------------------------------------
    // 🏡 Fetch property data if mode = property_chat
    // ----------------------------------------------------------------
    $propertyData = null;

    if ($mode === "property_chat" && $propertyId) {

        $sql = "
            SELECT 
                p.id,
                p.title,
                p.description,
                p.location,
                p.price,
                p.area_sqft,
                p.bedrooms,
                p.bathrooms,
                p.status,
                p.is_published,
                p.is_premium_listing,
                pt.type_name AS property_type,
                lt.type_name AS listing_type,

                (
                    SELECT image_url
                    FROM property_gallery
                    WHERE property_id = p.id
                    ORDER BY id ASC LIMIT 1
                ) AS main_image

            FROM properties p
            LEFT JOIN property_types pt ON p.property_type_id = pt.id
            LEFT JOIN listing_types lt ON p.listing_type_id = lt.id
            WHERE p.id = ?
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$propertyId]);
        $propertyData = $stmt->fetch(PDO::FETCH_ASSOC);

        // Fix image path format
        if ($propertyData && $propertyData['main_image']) {
            $img = $propertyData['main_image'];
            if (!preg_match('/^https?:\/\//', $img)) {
                $propertyData['main_image'] = $baseGalleryURL . $img;
            }
        }
    }


    // ----------------------------------------------------------------
    // 🧠 Build system prompt (CUSTOMER ONLY)
    // ----------------------------------------------------------------
    $systemPrompt = 
        "You are a friendly, professional real-estate consultant in Mauritius. 
         Your job is to help customers understand properties, search for real estate,
         and get clear investment advice. Avoid difficult words. Be helpful.";

    if ($mode === "search") {
        $systemPrompt .= 
            " The user wants help finding properties. 
              Do NOT invent fake listings; give general guidance based on filters.";
    }

    if ($mode === "investment") {
        $systemPrompt .= 
            " Provide investment insights such as ROI, rental potential, 
              tourist demand, and area analysis for Mauritius.";
    }

    if ($mode === "property_chat") {
        if ($propertyData) {
            $systemPrompt .= 
                " The user is asking about this real property. 
                  Use the factual data to explain it clearly. 
                  Here is the property data: " . json_encode($propertyData);
        } else {
            $systemPrompt .= 
                " No property data was found. Reply with general advice.";
        }
    }

   // --------------------------------------------------------------
// 🔥 SEND REQUEST TO OPENAI (NEW RESPONSES API)
// --------------------------------------------------------------

$apiKey = getenv("OPENROUTER_API_KEY");
if (!$apiKey && isset($_SERVER['OPENROUTER_API_KEY'])) {
    $apiKey = $_SERVER['OPENROUTER_API_KEY'];
}
if (!$apiKey && isset($_ENV['OPENROUTER_API_KEY'])) {
    $apiKey = $_ENV['OPENROUTER_API_KEY'];
}
if (!$apiKey) {
    echo json_encode([
        "success" => false,
        "error" => "Missing API key. Set OPENROUTER_API_KEY."
    ]);
    exit;
}

$payload = [
    "model" => "openai/gpt-4o-mini",
    "messages" => [
        [
            "role" => "system",
            "content" => $systemPrompt
        ],
        [
            "role" => "user",
            "content" => $message
        ]
    ]
];

$ch = curl_init("https://openrouter.ai/api/v1/chat/completions");

$referer = $protocol . $host;
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Content-Type: application/json",
    "Authorization: Bearer " . $apiKey,
    "HTTP-Referer: " . $referer,
    "X-Title: Sentinel Customer Assistant"
]);

curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr = curl_error($ch);
curl_close($ch);

if ($response === false) {
    echo json_encode([
        "success" => false,
        "error" => "Request failed: " . $curlErr
    ]);
    exit;
}

// Return raw response to React
http_response_code($httpCode ?: 200);
echo $response;


} catch (Throwable $e) {

    echo json_encode([
        "success" => false,
        "error" => $e->getMessage()
    ]);
}
?>
