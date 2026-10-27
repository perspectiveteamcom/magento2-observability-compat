<?php
declare(strict_types=1);

namespace Perspective\ObservabilityCompat\Model;

/** Shared failure backoff for the local Collector's Zipkin and OTLP endpoints. */
class HttpSender
{
    private float $retryAt = 0.0;

    public function post(string $endpoint, string $body): bool
    {
        if (microtime(true) < $this->retryAt || strlen($body) > 512 * 1024) {
            return false;
        }
        $curl = null;
        try {
            $parts = parse_url($endpoint);
            if (!$parts || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
                || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
                return false;
            }
            $curl = curl_init($endpoint);
            if ($curl === false) { throw new \RuntimeException('Cannot create telemetry transport'); }
            $configured = curl_setopt_array($curl, [
                CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_CONNECTTIMEOUT_MS => 50, CURLOPT_TIMEOUT_MS => 250,
                CURLOPT_NOSIGNAL => true, CURLOPT_FOLLOWLOCATION => false,
                // Discard the response body without buffering an unbounded error page.
                CURLOPT_WRITEFUNCTION => static function ($handle, string $data): int { return strlen($data); },
            ]);
            if (!$configured) { throw new \RuntimeException('Cannot configure telemetry transport'); }
            $ok = curl_exec($curl) !== false;
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            if ($ok && $status >= 200 && $status < 300) { return true; }
        } catch (\Throwable $error) {
            // This transport has no application logger and never emits output.
        } finally {
            if (PHP_VERSION_ID < 80000 && $curl !== null && $curl !== false) {
                try { curl_close($curl); } catch (\Throwable $error) {}
            }
        }
        $this->retryAt = microtime(true) + 5;
        return false;
    }
}
