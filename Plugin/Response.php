<?php
declare(strict_types=1);

namespace Perspective\ObservabilityCompat\Plugin;

class Response extends AbstractPlugin
{

    public function aroundSendResponse($subject, callable $proceed, ...$args)
    {
        $error = null;
        try { return $proceed(...$args); }
        catch (\Throwable $caught) { $error = $caught; throw $caught; }
        finally { $this->observe(function () use ($subject, $error): void { $this->telemetry->sent($subject, $error); }); }
    }
}
