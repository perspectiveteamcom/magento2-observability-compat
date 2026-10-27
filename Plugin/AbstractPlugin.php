<?php
declare(strict_types=1);

namespace Perspective\ObservabilityCompat\Plugin;

use Perspective\ObservabilityCompat\Model\Telemetry;

/** Only telemetry is guarded. Business exceptions always keep their identity. */
abstract class AbstractPlugin
{
    protected Telemetry $telemetry;

    public function __construct(Telemetry $telemetry) { $this->telemetry = $telemetry; }

    protected function observe(\Closure $callback)
    {
        try { return $callback(); } catch (\Throwable $error) { return null; }
    }

    protected function invoke(string $name, int $kind, array $attributes, callable $proceed, array $args, ?string $job = null)
    {
        $frame = $this->observe(function () use ($name, $kind, $attributes) {
            return $this->telemetry->begin($name, $kind, $attributes);
        });
        $error = null;
        try { return $proceed(...$args); }
        catch (\Throwable $caught) { $error = $caught; throw $caught; }
        finally {
            $this->observe(function () use ($frame, $error, $job): void {
                $this->telemetry->complete($frame, $error, $job);
            });
        }
    }
}
