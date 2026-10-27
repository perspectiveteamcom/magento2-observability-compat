<?php
declare(strict_types=1);

namespace Perspective\ObservabilityCompat\Plugin;

class FrontController extends AbstractPlugin
{

    public function aroundDispatch($subject, callable $proceed, ...$args)
    {
        try { return $this->invoke('front controller', \Perspective\ObservabilityCompat\Model\Telemetry::INTERNAL, [], $proceed, $args); }
        finally {
            $this->observe(function () use ($args): void {
                if (isset($args[0]) && is_object($args[0])) { $this->telemetry->route($args[0]); }
            });
        }
    }
}
