<?php
declare(strict_types=1);

namespace Perspective\ObservabilityCompat\Plugin;

class Schedule extends AbstractPlugin
{

    public function afterSave($subject, $result)
    {
        $this->observe(function () use ($subject): void { $this->telemetry->savedSchedule($subject); });
        return $result;
    }
}
