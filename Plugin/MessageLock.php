<?php
declare(strict_types=1);

namespace Perspective\ObservabilityCompat\Plugin;

class MessageLock extends AbstractPlugin
{

    public function afterLock($subject, $result, ...$args)
    {
        $this->observe(function () use ($args): void {
            if (isset($args[0]) && is_object($args[0])) { $this->telemetry->consume($args[0]); }
        });
        return $result;
    }
}
