<?php

declare(strict_types=1);

final class Response
{
    public const SENT = 'Your inquiry has been sent successfully.';
    public const INVALID = 'Invalid request data.';
    public const FAILED = 'Unable to send your inquiry. Please try again later.';

    /** @param list<string> $headers Extra headers, for example "Retry-After: 60" */
    public static function send(int $status, bool $success, string $message, array $headers = []): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        foreach ($headers as $header) {
            header($header);
        }

        echo json_encode(['success' => $success, 'message' => $message], JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function noContent(): never
    {
        http_response_code(204);
        exit;
    }
}
