<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function respond(int $status, string $message, bool $ok = false): void
{
    http_response_code($status);
    echo json_encode(['ok' => $ok, 'message' => $message]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    respond(405, 'Please submit the contact form.');
}

$name = trim((string) ($_POST['name'] ?? ''));
$email = trim((string) ($_POST['email'] ?? ''));
$subject = trim((string) ($_POST['subject'] ?? ''));
$message = trim((string) ($_POST['message'] ?? ''));

if ($name === '' || $email === '' || $subject === '' || $message === '') {
    respond(422, 'Please complete all fields.');
}
if (strlen($name) > 500 || strlen($subject) > 720 || strlen($message) > 40000 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(422, 'Please check the form fields and try again.');
}

// Set these in the hosting provider's environment/secret settings.
$serviceId = getenv('EMAILJS_SERVICE_ID') ?: 'service_acgwzcs';
$templateId = getenv('EMAILJS_TEMPLATE_ID') ?: 'template_10fi8cb';
$publicKey = getenv('EMAILJS_PUBLIC_KEY') ?: '';
$privateKey = getenv('EMAILJS_PRIVATE_KEY') ?: '';

if ($publicKey === '' || $privateKey === '') {
    error_log('Portfolio contact: EMAILJS_PUBLIC_KEY or EMAILJS_PRIVATE_KEY is not configured.');
    respond(503, 'The email service is not configured yet. Please email me directly.');
}
if (!function_exists('curl_init')) {
    error_log('Portfolio contact: PHP cURL extension is unavailable.');
    respond(503, 'The email service is unavailable on this server. Please email me directly.');
}

// The EmailJS template must use these exact variable names.
$payload = [
    'service_id' => $serviceId,
    'template_id' => $templateId,
    'user_id' => $publicKey,
    'accessToken' => $privateKey,
    'template_params' => [
        'name' => $name,
        'email' => $email,
        'subject' => $subject,
        'message' => $message,
        'reply_to' => $email,
    ],
];
$json = json_encode($payload);
if ($json === false) {
    respond(500, 'Unable to prepare your message. Please try again.');
}

$curl = curl_init('https://api.emailjs.com/api/v1.0/email/send');
curl_setopt_array($curl, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $json,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 25,
]);
$providerResponse = curl_exec($curl);
$curlErrorNumber = curl_errno($curl);
$providerStatus = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
curl_close($curl);

if ($providerResponse === false || $curlErrorNumber !== 0) {
    error_log('Portfolio contact: EmailJS request failed (cURL error ' . $curlErrorNumber . ').');
    respond(502, 'Could not connect to the email service. Please try again or email me directly.');
}
if ($providerStatus < 200 || $providerStatus >= 300) {
    // Provider details are kept in the server log for diagnosis, never returned to visitors.
    error_log('Portfolio contact: EmailJS HTTP ' . $providerStatus . ': ' . substr((string) $providerResponse, 0, 500));
    respond(502, 'The email service rejected the message. Please check its service and template settings.');
}

respond(200, 'Thanks! Your message has been sent.', true);
