<?php
declare(strict_types=1);

namespace Perspective\ObservabilityCompat\Plugin;

class Template extends AbstractPlugin
{

    public function aroundFetchView($subject, callable $proceed, ...$args)
    {
        return $this->invoke('template render', \Perspective\ObservabilityCompat\Model\Telemetry::INTERNAL, [], $proceed, $args);
    }
}
