<?php
header('Content-Type: application/json');
date_default_timezone_set('Asia/Kolkata');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status'=>'error']);
    exit;
}

/* ================= INPUT ================= */

$name     = trim($_POST['FirstName'] ?? '');
$email    = filter_var($_POST['EmailAddress'] ?? '', FILTER_VALIDATE_EMAIL);
$phone    = trim($_POST['Phone'] ?? '');
$project  = trim($_POST['mx_Project_Name'] ?? '');
$location = trim($_POST['mx_City'] ?? '');
$client   = trim($_POST['CLIENT'] ?? '');

if (!$name || !$email || !$phone) {
    echo json_encode(['status'=>'error']);
    exit;
}

/* ================= CRM (ATTEMPT ALWAYS) ================= */

$crmSuccess = false;

$crmUrl = 'https://api-in21.leadsquared.com/v2/LeadManagement.svc/Lead.Capture?accessKey=u$r809e24bb805afa1a050331c6cf61b994&secretKey=b09aa150b3e011b4589e29704d3ce9d85b28b7fb';

$crmData = [
    ["Attribute"=>"FirstName","Value"=>$name],
    ["Attribute"=>"EmailAddress","Value"=>$email],
    ["Attribute"=>"Phone","Value"=>$phone],
    ["Attribute"=>"mx_Project_Name","Value"=>$project],
    ["Attribute"=>"mx_City","Value"=>$location]
];

$ch = curl_init($crmUrl);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($crmData),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ["Content-Type: application/json"],
    CURLOPT_TIMEOUT => 4
]);

curl_exec($ch);
$crmHttp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$crmSuccess = ($crmHttp === 200);

/* ================= IP + GEO ================= */

$ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$ip = explode(',', $ip)[0];

$geo = ['country'=>'Unknown','region'=>'Unknown','city'=>'Unknown'];

if (!in_array($ip, ['127.0.0.1','::1'])) {
    $geoRes = @file_get_contents("https://ipwho.is/{$ip}");
    if ($geoRes) {
        $g = json_decode($geoRes,true);
        $geo['country'] = $g['country'] ?? 'Unknown';
        $geo['region']  = $g['region'] ?? 'Unknown';
        $geo['city']    = $g['city'] ?? 'Unknown';
    }
}

/* ================= GOOGLE SHEET (ALWAYS RUNS) ================= */
function base64url_encode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    function create_jwt($payload, $privateKey) {
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $segments = [];
        $segments[] = base64url_encode(json_encode($header));
        $segments[] = base64url_encode(json_encode($payload));
        $signing_input = implode('.', $segments);
        openssl_sign($signing_input, $signature, $privateKey, "SHA256");
        $segments[] = base64url_encode($signature);
        return implode('.', $segments);
    }

    // Row to insert into Google Sheet
    $sheetRow = [
        date("Y-m-d H:i:s"),
        $name,
        $email,
        $phone,
        $project,
        $location,
        $ip,
        $geo['country'],
        $geo['region'],
        $geo['city'],
        $Client
    ];

    $spreadsheetId = "1_3xJfI4wh-Zx3liNjSC3oRl157qSp99J6-fKDfuoRZ8";
    $sheetName = "Leads";

    // Service Account email
    $serviceAccountEmail = "fdr-939@fdrserver.iam.gserviceaccount.com";

    // Load private key
    $privateKey = file_get_contents(__DIR__ . "/key.pem");

    // JWT payload
    $now = time();
    $payload = [
        "iss"   => $serviceAccountEmail,
        "scope" => "https://www.googleapis.com/auth/spreadsheets",
        "aud"   => "https://oauth2.googleapis.com/token",
        "exp"   => $now + 3600,
        "iat"   => $now
    ];

    // Create JWT
    $jwt = create_jwt($payload, $privateKey);

    // Get access token
    $tokenCurl = curl_init("https://oauth2.googleapis.com/token");
    curl_setopt($tokenCurl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($tokenCurl, CURLOPT_POST, true);
    curl_setopt($tokenCurl, CURLOPT_POSTFIELDS, http_build_query([
        "grant_type" => "urn:ietf:params:oauth:grant-type:jwt-bearer",
        "assertion"  => $jwt
    ]));
    $tokenResponse = json_decode(curl_exec($tokenCurl), true);
    curl_close($tokenCurl);

    // Append row if access token valid
    if (isset($tokenResponse['access_token'])) {

        $accessToken = $tokenResponse['access_token'];

        $appendUrl = "https://sheets.googleapis.com/v4/spreadsheets/{$spreadsheetId}/values/{$sheetName}!A1:append?valueInputOption=RAW";
        $postData = ["values" => [$sheetRow]];

        $sheetCurl = curl_init($appendUrl);
        curl_setopt($sheetCurl, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer {$accessToken}",
            "Content-Type: application/json"
        ]);
        curl_setopt($sheetCurl, CURLOPT_POST, true);
        curl_setopt($sheetCurl, CURLOPT_POSTFIELDS, json_encode($postData));
        curl_setopt($sheetCurl, CURLOPT_RETURNTRANSFER, true);
        curl_exec($sheetCurl);
        curl_close($sheetCurl);
    }


/* ================= FINAL RESPONSE ================= */

echo json_encode(['status'=>'success']);
exit;
