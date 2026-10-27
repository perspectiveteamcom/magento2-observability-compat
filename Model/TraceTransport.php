<?php
declare(strict_types=1);

namespace Perspective\ObservabilityCompat\Model;

use OpenTelemetry\SDK\Common\Export\TransportInterface;
use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Common\Future\CompletedFuture;
use OpenTelemetry\SDK\Common\Future\ErrorFuture;
use OpenTelemetry\SDK\Common\Future\FutureInterface;

final class TraceTransport implements TransportInterface
{
    private HttpSender $sender;
    private string $endpoint;

    public function __construct(HttpSender $sender, string $endpoint)
    {
        $this->sender = $sender;
        $this->endpoint = $endpoint;
    }

    public function contentType(): string { return 'application/json'; }

    public function send(string $payload, ?CancellationInterface $cancellation = null): FutureInterface
    {
        return $this->sender->post($this->endpoint, $payload)
            ? new CompletedFuture('')
            : new ErrorFuture(new \RuntimeException('Local telemetry export unavailable'));
    }

    public function shutdown(?CancellationInterface $cancellation = null): bool { return true; }
    public function forceFlush(?CancellationInterface $cancellation = null): bool { return true; }
}
