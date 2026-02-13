<?php
date_default_timezone_set('Asia/Kolkata');

// Allow only POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: index.php");
    exit;
}

/* ================= GET FORM DATA ================= */

$name     = trim($_POST['FirstName'] ?? '');
$email    = filter_var($_POST['EmailAddress'] ?? '', FILTER_VALIDATE_EMAIL);
$phone    = trim($_POST['Phone'] ?? '');
$project  = trim($_POST['mx_Project_Name'] ?? '');
$location = trim($_POST['mx_City'] ?? '');
$Client   = trim($_POST['CLIENT'] ?? '');

if (!$name || !$email || !$phone) {
    header("Location: index.php?error=missing_fields");
    exit;
}

// Split name
$nameParts = explode(" ", $name, 2);
$firstName = $nameParts[0];
$lastName  = $nameParts[1] ?? "";

/* ================= CRM SUBMISSION ================= */

$crmUrl = "https://api-in21.leadsquared.com/v2/LeadManagement.svc/Lead.Capture?accessKey=YOUR_ACCESS_KEY&secretKey=YOUR_SECRET_KEY";

$crmData = [
    ["Attribute"=>"FirstName","Value"=>$firstName],
    ["Attribute"=>"LastName","Value"=>$lastName],
    ["Attribute"=>"EmailAddress","Value"=>$email],
    ["Attribute"=>"Phone","Value"=>$phone],
    ["Attribute"=>"mx_Project_Name","Value"=>$project],
    ["Attribute"=>"mx_City","Value"=>$location],
    ["Attribute"=>"SearchBy","Value"=>"Phone"],
    ["Attribute"=>"LeadType","Value"=>"OT_1"]
];

$ch = curl_init($crmUrl);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($crmData),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ["Content-Type: application/json"]
]);

$crmResponse = curl_exec($ch);
$crmHttp     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

/* ================= GOOGLE SHEETS ================= */

function base64url_encode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function create_jwt($payload, $privateKey) {
    $header = ['alg'=>'RS256','typ'=>'JWT'];
    $segments = [];
    $segments[] = base64url_encode(json_encode($header));
    $segments[] = base64url_encode(json_encode($payload));
    $signing_input = implode('.', $segments);
    openssl_sign($signing_input, $signature, $privateKey, "SHA256");
    $segments[] = base64url_encode($signature);
    return implode('.', $segments);
}

$sheetRow = [
    date("Y-m-d H:i:s"),
    $name,
    $email,
    $phone,
    $project,
    $location,
    $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
    "",
    "",
    "",
    $Client
];

$spreadsheetId = "YOUR_SHEET_ID";
$sheetName = "Leads";
$serviceAccountEmail = "YOUR_SERVICE_ACCOUNT_EMAIL";
$privateKeyPath = __DIR__ . "/key.pem";

if (file_exists($privateKeyPath)) {

    $privateKey = file_get_contents($privateKeyPath);

    $now = time();
    $payload = [
        "iss"=>$serviceAccountEmail,
        "scope"=>"https://www.googleapis.com/auth/spreadsheets",
        "aud"=>"https://oauth2.googleapis.com/token",
        "exp"=>$now+3600,
        "iat"=>$now
    ];

    $jwt = create_jwt($payload, $privateKey);

    $tokenCurl = curl_init("https://oauth2.googleapis.com/token");
    curl_setopt_array($tokenCurl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            "grant_type"=>"urn:ietf:params:oauth:grant-type:jwt-bearer",
            "assertion"=>$jwt
        ])
    ]);

    $tokenResponse = json_decode(curl_exec($tokenCurl), true);
    curl_close($tokenCurl);

    if (isset($tokenResponse['access_token'])) {

        $appendUrl = "https://sheets.googleapis.com/v4/spreadsheets/{$spreadsheetId}/values/{$sheetName}!A1:append?valueInputOption=RAW";

        $sheetCurl = curl_init($appendUrl);
        curl_setopt_array($sheetCurl, [
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer ".$tokenResponse['access_token'],
                "Content-Type: application/json"
            ],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(["values"=>[$sheetRow]]),
            CURLOPT_RETURNTRANSFER => true
        ]);

        curl_exec($sheetCurl);
        curl_close($sheetCurl);
    }
}

/* ================= FINAL REDIRECT ================= */

if ($crmHttp == 200) {
    header("Location: thank-you.html");
    exit;
} else {
    header("Location: index.php?error=crm_failed");
    exit;
}
?>
