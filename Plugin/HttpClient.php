<?php
declare(strict_types=1);

namespace Perspective\ObservabilityCompat\Plugin;

class HttpClient extends AbstractPlugin
{

    public function aroundGet($subject, callable $proceed, ...$args)
    {
        return $this->invoke('HTTP GET', \Perspective\ObservabilityCompat\Model\Telemetry::CLIENT, ['http.request.method' => 'GET'], $proceed, $args);
    }

    public function aroundPost($subject, callable $proceed, ...$args)
    {
        return $this->invoke('HTTP POST', \Perspective\ObservabilityCompat\Model\Telemetry::CLIENT, ['http.request.method' => 'POST'], $proceed, $args);
    }
}
