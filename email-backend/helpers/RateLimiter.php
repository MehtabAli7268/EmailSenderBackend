<?php

declare(strict_types=1);

/**
 * Fixed-window, per-client rate limit kept in a single JSON file. If the
 * storage folder can't be used it logs the problem and allows the request,
 * because losing a real customer's enquiry is worse than a gap in spam
 * protection.
 */
final class RateLimiter
{
    public function __construct(
        private readonly string $directory,
        private readonly int $maxRequests,
        private readonly int $windowSeconds
    ) {
    }

    /** Records a request. Returns 0 if allowed, or the seconds to wait if not. */
    public function hit(string $clientId): int
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            error_log('[send-mail] Rate limit storage could not be created: ' . $this->directory);
            return 0;
        }

        $handle = @fopen($this->directory . '/ratelimit.json', 'c+');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            error_log('[send-mail] Rate limit storage could not be opened: ' . $this->directory);
            return 0;
        }

        $now = time();
        $clients = json_decode((string) stream_get_contents($handle), true);
        if (!is_array($clients)) {
            $clients = [];
        }

        // Drop expired windows so the file stays small.
        foreach ($clients as $id => $state) {
            if (!is_array($state) || !isset($state['start'], $state['count'])
                || $now - (int) $state['start'] >= $this->windowSeconds) {
                unset($clients[$id]);
            }
        }

        $key = hash('sha256', $clientId);
        $state = $clients[$key] ?? ['start' => $now, 'count' => 0];

        $retryAfter = 0;
        if ($state['count'] >= $this->maxRequests) {
            $retryAfter = max(1, (int) $state['start'] + $this->windowSeconds - $now);
        } else {
            $state['count']++;
            $clients[$key] = $state;
        }

        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, (string) json_encode($clients));
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);

        return $retryAfter;
    }
}
