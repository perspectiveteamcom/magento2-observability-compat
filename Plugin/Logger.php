<?php
declare(strict_types=1);

namespace Perspective\ObservabilityCompat\Plugin;

class Logger extends AbstractPlugin
{

    public function beforeAddRecord($subject, ...$args)
    {
        $this->observe(function () use ($subject, $args): void {
            if (count($args) >= 2) { $this->telemetry->log($args[0], $args[1], $subject); }
        });
        return null;
    }
}
