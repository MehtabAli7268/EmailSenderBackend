<?php

declare(strict_types=1);

/**
 * Minimal .env reader. Values are held in memory rather than passed to putenv(),
 * so credentials don't leak into the process environment or phpinfo().
 */
final class Env
{
    /** @var array<string,string> */
    private static array $values = [];

    public static function load(string $path): void
    {
        $lines = is_readable($path) ? file($path, FILE_IGNORE_NEW_LINES) : false;
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            // Windows editors may save a byte order mark and CRLF line endings.
            $line = trim(preg_replace('/^\xEF\xBB\xBF/', '', $line) ?? $line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            self::$values[trim($key)] = self::parseValue(trim($value));
        }
    }

    public static function get(string $key, string $default = ''): string
    {
        $value = self::$values[$key] ?? getenv($key);

        return $value === false || $value === '' ? $default : $value;
    }

    private static function parseValue(string $value): string
    {
        // Quoted values are taken literally, so passwords may contain # or spaces.
        if (preg_match('/^(["\'])(.*)\1(\s+#.*)?$/', $value, $match) === 1) {
            return $match[2];
        }

        // An unquoted " #" starts a comment. A # with no space before it is kept.
        return trim(preg_replace('/\s+#.*$/', '', $value) ?? $value);
    }
}
