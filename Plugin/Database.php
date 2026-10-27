<?php
declare(strict_types=1);

namespace Perspective\ObservabilityCompat\Plugin;

class Database extends AbstractPlugin
{

    public function aroundQuery($subject, callable $proceed, ...$args)
    {
        return $this->invoke('database query', \Perspective\ObservabilityCompat\Model\Telemetry::CLIENT, ['db.system' => 'mysql'], $proceed, $args);
    }
}
