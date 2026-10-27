<?php
declare(strict_types=1);

namespace Perspective\ObservabilityCompat\Plugin;

class Http extends AbstractPlugin
{

    public function aroundLaunch($subject, callable $proceed, ...$args)
    {
        $frame = $this->observe(function () {
            $headers = [];
            foreach (['traceparent', 'tracestate'] as $name) {
                $value = $_SERVER['HTTP_' . strtoupper($name)] ?? null;
                if (is_string($value)) { $headers[$name] = $value; }
            }
            return $this->telemetry->startRequest($headers);
        });
        $result = null;
        $error = null;
        try { return $result = $proceed(...$args); }
        catch (\Throwable $caught) { $error = $caught; throw $caught; }
        finally {
            $this->observe(function () use ($frame, $result, $error): void {
                $this->telemetry->response($frame, $result, $error);
            });
        }
    }
}
