<?php
declare(strict_types=1);

namespace Perspective\ObservabilityCompat\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Perspective\ObservabilityCompat\Model\Telemetry;

class CronStart implements ObserverInterface
{
    private Telemetry $telemetry;

    public function __construct(Telemetry $telemetry) { $this->telemetry = $telemetry; }

    public function execute(Observer $observer): void
    {
        try { $this->telemetry->startCron((string) $observer->getData('job_name')); }
        catch (\Throwable $error) {}
    }
}
