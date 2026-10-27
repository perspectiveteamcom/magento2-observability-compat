<?php
declare(strict_types=1);

namespace Perspective\ObservabilityCompat\Plugin;

class Consumer extends AbstractPlugin
{

    public function aroundProcess($subject, callable $proceed, ...$args)
    {
        try { return $proceed(...$args); }
        finally { $this->observe(function (): void { $this->telemetry->closeMessages(); }); }
    }
}
