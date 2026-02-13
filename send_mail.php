<?php
date_default_timezone_set('Asia/Kolkata'); // Set timezone

require 'PHPMailer/vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Read JSON body (for fetch)
    $raw = file_get_contents("php://input");
    $data = json_decode($raw, true) ?: [];

    // Trim all inputs
    $data = array_map('trim', $data);

    $name     = $data['name'] ?? '';
    $email    = filter_var($data['email'] ?? '', FILTER_VALIDATE_EMAIL) ?: '';
    $phone    = $data['phone'] ?? '';
    $project  = $data['project'] ?? '';
    $location = $data['location'] ?? '';
    $Client = $data['client'] ?? '';

    // Get IP
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $ip = explode(',', $ip)[0];

    // Fetch geo data
    if ($ip === '127.0.0.1' || $ip === '::1') {
        $geo = ['country'=>'Localhost','region'=>'Localhost','city'=>'Localhost','org'=>'Localhost'];
    } else {
        $ch = curl_init("https://ipwho.is/{$ip}");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        $response = curl_exec($ch);
        curl_close($ch);
        $d = $response ? json_decode($response,true) : [];
        $geo = [
            'country' => $d['country'] ?? 'Unknown',
            'region'  => $d['region'] ?? 'Unknown',
            'city'    => $d['city'] ?? 'Unknown',
            'org'     => $d['org'] ?? 'Unknown'
        ];
    }

    // Build email table
    $rows = [
        'Name' => $name,
        'Email' => $email,
        'Mobile' => $phone,
        'Project' => $project,
        'Location' => $location,
        'Client IP' => $ip,
        'IP Org' => $geo['org'],
        'IP Country' => $geo['country'],
        'IP Region' => $geo['region'],
        'IP City' => $geo['city'],
        'Submitted At' => date('Y-m-d h:i:s A')
    ];

    $email_content = "<table border='1' cellpadding='6' cellspacing='0' style='border-collapse:collapse;font-family:Arial,sans-serif;font-size:14px;'>";
    foreach ($rows as $k=>$v) {
        $email_content .= "<tr><td style='font-weight:bold;background:#f7f7f7;width:180px;'>".htmlspecialchars($k)."</td><td>".htmlspecialchars($v)."</td></tr>";
    }
    $email_content .= "</table>";

    // Log into leads.txt
    $log_file = __DIR__ . '/leads.txt';
    $log_entry = "[".date('Y-m-d H:i:s')."] Name: $name | Email: $email | Phone: $phone | Project: $project | Location: $location | IP: $ip | Org: {$geo['org']} | Country: {$geo['country']} | Region: {$geo['region']} | City: {$geo['city']}\n";
    file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);



    /* ==================================================
                 GOOGLE SHEETS UPDATE START
       ================================================== */

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

    $spreadsheetId = "1YWz7-G8AMWpchCUeSDmQbgAHEOJHNRfuIJIghYS1EPg";
    $sheetName = "Leads";

    // Service Account email
    $serviceAccountEmail = "vikram@lead-management-480107.iam.gserviceaccount.com";

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

    /* ==================================================
                 GOOGLE SHEETS UPDATE END
       ================================================== */



    // Send email notification
    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'umikoweb58@gmail.com';
        $mail->Password = 'msiiaabtgqwbzjbu'; // Gmail app password
        $mail->SMTPSecure = 'ssl';
        $mail->Port = 465;
        $mail->CharSet = 'UTF-8';

        $mail->setFrom('umikoweb58@gmail.com', 'Website Enquiry');
        if ($email) {
            $mail->addReplyTo($email, $name);
        }
        $mail->addAddress('thebigcarpethomes@gmail.com');
        $mail->addAddress('vinaymachha@gmail.com');

        $mail->isHTML(true);
        $mail->Subject = $project ?: 'New Enquiry';
        $mail->Body = $email_content;

        $mail->send();
        exit;

    } catch (Exception $e) {
        echo "<script>alert('Server error. Submission logged successfully.');location='http://newlaunch-gurgaon.com';</script>";
    }
}
?>
