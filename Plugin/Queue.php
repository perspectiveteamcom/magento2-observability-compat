<?php
declare(strict_types=1);

namespace Perspective\ObservabilityCompat\Plugin;

class Queue extends AbstractPlugin
{

    public function aroundAcknowledge($subject, callable $proceed, ...$args)
    {
        return $this->settle($proceed, $args, false);
    }

    public function aroundReject($subject, callable $proceed, ...$args)
    {
        return $this->settle($proceed, $args, true);
    }

    private function settle(callable $proceed, array $args, bool $rejected)
    {
        $error = null;
        try { return $proceed(...$args); }
        catch (\Throwable $caught) { $error = $caught; throw $caught; }
        finally {
            $this->observe(function () use ($args, $rejected, $error): void {
                if (isset($args[0]) && is_object($args[0])) { $this->telemetry->consumed($args[0], $rejected, $error); }
            });
        }
    }
}
