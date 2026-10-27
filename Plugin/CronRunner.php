<?php
declare(strict_types=1);

namespace Perspective\ObservabilityCompat\Plugin;

class CronRunner extends AbstractPlugin
{

    public function aroundExecute($subject, callable $proceed, ...$args)
    {
        try { return $proceed(...$args); }
        finally { $this->observe(function (): void { $this->telemetry->closeCron(); }); }
    }
}
