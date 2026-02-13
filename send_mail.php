<?php
date_default_timezone_set('Asia/Kolkata');
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $raw = file_get_contents("php://input");
    $data = json_decode($raw, true) ?: [];

    $name     = trim($data['fullName'] ?? '');
    $email    = filter_var($data['email'] ?? '', FILTER_VALIDATE_EMAIL) ?: '';
    $phone    = trim($data['phone'] ?? '');
    $project  = trim($data['project'] ?? '');
    $location = trim($data['location'] ?? '');
    $Client   = trim($data['client'] ?? '');

    if (!$name || !$email || !$phone) {
        echo json_encode(["status"=>"error","message"=>"Required fields missing"]);
        exit;
    }

    // Split full name
    $nameParts = explode(" ", $name, 2);
    $firstName = $nameParts[0];
    $lastName  = $nameParts[1] ?? "";

    /* ================= CRM ================= */

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
    $crmHttp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    /* ================= LOG ================= */

    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    $log_file = __DIR__ . '/leads.txt';
    $log_entry = "[".date('Y-m-d H:i:s')."] Name: $name | Email: $email | Phone: $phone | Project: $project | Location: $location | CRM Status: $crmHttp\n";
    file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);

    /* ================= GOOGLE SHEETS (UNCHANGED STRUCTURE) ================= */

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
        $ip,
        "",
        "",
        "",
        $Client
    ];

    $spreadsheetId = "YOUR_SHEET_ID";
    $sheetName = "Leads";
    $serviceAccountEmail = "YOUR_SERVICE_ACCOUNT_EMAIL";
    $privateKey = file_get_contents(__DIR__ . "/key.pem");

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
    curl_setopt($tokenCurl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($tokenCurl, CURLOPT_POST, true);
    curl_setopt($tokenCurl, CURLOPT_POSTFIELDS, http_build_query([
        "grant_type"=>"urn:ietf:params:oauth:grant-type:jwt-bearer",
        "assertion"=>$jwt
    ]));
    $tokenResponse = json_decode(curl_exec($tokenCurl), true);
    curl_close($tokenCurl);

    if (isset($tokenResponse['access_token'])) {

        $appendUrl = "https://sheets.googleapis.com/v4/spreadsheets/{$spreadsheetId}/values/{$sheetName}!A1:append?valueInputOption=RAW";
        $postData = ["values"=>[$sheetRow]];

        $sheetCurl = curl_init($appendUrl);
        curl_setopt($sheetCurl, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer ".$tokenResponse['access_token'],
            "Content-Type: application/json"
        ]);
        curl_setopt($sheetCurl, CURLOPT_POST, true);
        curl_setopt($sheetCurl, CURLOPT_POSTFIELDS, json_encode($postData));
        curl_setopt($sheetCurl, CURLOPT_RETURNTRANSFER, true);
        curl_exec($sheetCurl);
        curl_close($sheetCurl);
    }

    /* ================= FINAL RESPONSE ================= */

    if ($crmHttp == 200) {
        echo json_encode(["status"=>"success"]);
    } else {
        echo json_encode(["status"=>"error","message"=>"CRM submission failed"]);
    }
}
?>
