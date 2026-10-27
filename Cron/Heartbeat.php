<?php
declare(strict_types=1);

namespace Perspective\ObservabilityCompat\Cron;

use Magento\Framework\App\ResourceConnection;
use Perspective\ObservabilityCompat\Model\Telemetry;

class Heartbeat
{
    private ResourceConnection $resource;
    private Telemetry $telemetry;

    public function __construct(ResourceConnection $resource, Telemetry $telemetry)
    {
        $this->resource = $resource;
        $this->telemetry = $telemetry;
    }

    public function execute(): void
    {
        try { $this->telemetry->heartbeat($this->resource); } catch (\Throwable $error) {}
    }
}
