<?php

declare(strict_types=1);

/**
 * POST /api/send-mail.php
 *
 * Validates an enquiry or booking and emails it to the recipient configured
 * for its region. See README.md for the full contract.
 */

ini_set('display_errors', '0');
ini_set('log_errors', '1');
header_remove('X-Powered-By');

// Send CORS headers first so every response, including errors, carries them.
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Max-Age: 86400');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

const MAX_BODY_BYTES = 16384;

// The folder as built keeps this file one level below the app root. The cPanel
// layout keeps it in public_html/api, with the app in a sibling of public_html.
$root = null;
foreach ([__DIR__, dirname(__DIR__),dirname(__DIR__, 2) . '/email-backend'] as $candidate) {
    if (is_file($candidate . '/config/mail.php')) {
        $root = $candidate;
        break;
    }
}

if ($root === null) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Unable to send your inquiry. Please try again later.']);
    exit;
}

// cPanel's default error_log sits beside the script, where it can be downloaded.
// Keep logs with the rest of the app, outside the web root.
if (is_dir($root . '/storage/logs') || @mkdir($root . '/storage/logs', 0700, true)) {
    ini_set('error_log', $root . '/storage/logs/error.log');
}

require $root . '/helpers/Response.php';

try {
    require $root . '/helpers/Env.php';
    require $root . '/helpers/RateLimiter.php';

    Env::load($root . '/.env');
    $config = require $root . '/config/mail.php';

    applyCors($config['allowed_origins']);

    $method = $_SERVER['REQUEST_METHOD'] ?? '';
    if ($method === 'OPTIONS') {
        Response::noContent();
    }
    if ($method !== 'POST') {
        Response::send(405, false, 'Method not allowed.', ['Allow: POST, OPTIONS']);
    }

    $contentType = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
    if ($contentType !== 'application/json') {
        Response::send(415, false, Response::INVALID);
    }

    $body = file_get_contents('php://input', false, null, 0, MAX_BODY_BYTES + 1);
    if ($body === false) {
        Response::send(400, false, Response::INVALID);
    }
    if (strlen($body) > MAX_BODY_BYTES) {
        Response::send(413, false, Response::INVALID);
    }

    $limiter = new RateLimiter(
        $root . '/storage/ratelimit',
        $config['rate_limit']['max'],
        $config['rate_limit']['window']
    );
    $retryAfter = $limiter->hit((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    if ($retryAfter > 0) {
        Response::send(429, false, 'Too many requests. Please try again later.', ['Retry-After: ' . $retryAfter]);
    }

    try {
        $input = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        Response::send(400, false, Response::INVALID);
    }
    if (!is_array($input)) {
        Response::send(400, false, Response::INVALID);
    }

    // Honeypot: report success so bots don't learn they were filtered out.
    $honeypot = $input['website'] ?? null;
    if ($honeypot !== null && $honeypot !== '') {
        Response::send(200, true, Response::SENT);
    }

    $regions = array_change_key_case(require $root . '/config/regions.php', CASE_UPPER);

    $inquiry = validateInquiry($input, $regions);
    if ($inquiry === null) {
        Response::send(422, false, Response::INVALID);
    }

    require $root . '/vendor/autoload.php';
    require $root . '/services/MailService.php';

    (new MailService($config))->send($regions[$inquiry['region']], $inquiry);

    Response::send(200, true, Response::SENT);
} catch (Throwable $e) {
    error_log('[send-mail] ' . get_class($e) . ': ' . $e->getMessage());
    Response::send(500, false, Response::FAILED);
}

/**
 * CORS is open: any origin may call this API. No origin check is made.
 *
 * @param list<string> $allowedOrigins Unused, kept for the call site.
 */
function applyCors(array $allowedOrigins): void
{
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Max-Age: 86400');
}

/**
 * Returns the cleaned inquiry, or null if anything is invalid. The recipient is
 * never taken from here: it is looked up from the validated region code.
 *
 * @param array<mixed>         $input
 * @param array<string,string> $regions
 * @return array<string,string|bool|null>|null
 */
function validateInquiry(array $input, array $regions): ?array
{
    // Field name => maximum length. All of these must be one line.
    $singleLineFields = [
        'name' => 100,
        'email' => 254,
        'phone' => 30,
        'region' => 10,
        'subject' => 150,
        'country' => 60,
        'city' => 100,
        'service' => 150,
        'budget' => 100,
        'driver' => 30,
        'from' => 10,
        'to' => 10,
    ];

    $clean = [];
    foreach ($singleLineFields as $field => $maxLength) {
        $value = $input[$field] ?? '';
        if (!is_string($value)) {
            return null;
        }
        // Checked before trimming so a trailing CRLF can't slip through. This is
        // the header injection guard: no control or line separator characters.
        if (preg_match('/[\p{Cc}\x{2028}\x{2029}]/u', $value) !== 0) {
            return null;
        }
        $value = trim($value);
        if (mb_strlen($value, 'UTF-8') > $maxLength) {
            return null;
        }
        $clean[$field] = $value === '' ? null : $value;
    }

    $booking = $input['booking'] ?? false;
    if (!is_string($booking) && !is_bool($booking)) {
        return null;
    }
    $clean['booking'] = $booking === true || $booking === '1' || $booking === 'true';

    $message = $input['message'] ?? '';
    if (!is_string($message)) {
        return null;
    }
    $message = str_replace(["\r\n", "\r"], "\n", $message);
    $message = preg_replace('/[^\P{Cc}\n\t]/u', '', $message);
    if ($message === null) {
        return null;
    }
    $message = trim($message);
    if (mb_strlen($message, 'UTF-8') > 5000) {
        return null;
    }
    $clean['message'] = $message === '' ? null : $message;

    if ($clean['name'] === null || $clean['phone'] === null || $clean['region'] === null) {
        return null;
    }
    if ($clean['message'] === null && !$clean['booking']) {
        return null;
    }

    // Same phone rule as the website's own form validation.
    if (preg_match('/^\+?[\d\s\-()]{10,17}$/', $clean['phone']) !== 1
        || strlen((string) preg_replace('/\D/', '', $clean['phone'])) < 10) {
        return null;
    }

    if ($clean['email'] !== null && filter_var($clean['email'], FILTER_VALIDATE_EMAIL) === false) {
        return null;
    }

    $clean['region'] = strtoupper($clean['region']);
    if (!isset($regions[$clean['region']])) {
        return null;
    }

    foreach (['from', 'to'] as $dateField) {
        if ($clean[$dateField] !== null && !isValidDate($clean[$dateField])) {
            return null;
        }
    }

    if ($clean['subject'] === null) {
        $clean['subject'] = match (true) {
            $clean['booking'] => 'Car booking request',
            $clean['service'] !== null => 'Enquiry: ' . $clean['service'],
            default => 'Website enquiry',
        };
    }

    return $clean;
}

function isValidDate(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

    return $date !== false && $date->format('Y-m-d') === $value;
}
