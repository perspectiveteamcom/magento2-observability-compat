<?php
declare(strict_types=1);

namespace Perspective\ObservabilityCompat\Plugin;

class Publisher extends AbstractPlugin
{

    public function aroundEnqueue($subject, callable $proceed, ...$args)
    {
        $topic = isset($args[0]) && is_string($args[0]) ? substr($args[0], 0, 128) : 'unknown';
        return $this->invoke('publish ' . $topic, \Perspective\ObservabilityCompat\Model\Telemetry::PRODUCER,
            ['messaging.system' => 'rabbitmq', 'messaging.destination.name' => $topic], $proceed, $args, 'producer');
    }
}
