<?php
declare(strict_types=1);

namespace Perspective\ObservabilityCompat\Model;

/** Bounded OTLP/JSON metrics and logs, independent of the legacy SDK's protobuf dependency. */
class Signals
{
    private HttpSender $sender;
    private array $metrics = [];
    private array $logs = [];
    private string $started;
    private const BUCKETS = [0.01, 0.05, 0.1, 0.25, 0.5, 1.0, 2.5, 5.0, 10.0];

    public function __construct(HttpSender $sender)
    {
        $this->sender = $sender;
        $this->started = self::now();
    }

    public static function now(): string { return sprintf('%.0f', microtime(true) * 1000000000); }

    public static function resource(): array
    {
        $resource = ['service.name' => getenv('OTEL_SERVICE_NAME') ?: 'magento'];
        foreach (explode(',', getenv('OTEL_RESOURCE_ATTRIBUTES') ?: '') as $entry) {
            $pair = explode('=', $entry, 2);
            if (count($pair) === 2 && in_array($pair[0], ['service.namespace', 'service.instance.id',
                'deployment.environment.name', 'project', 'host.name'], true)) {
                $resource[$pair[0]] = substr($pair[1], 0, 128);
            }
        }
        return $resource;
    }

    public static function attributes(array $attributes): array
    {
        $result = [];
        foreach ($attributes as $key => $value) {
            $result[] = ['key' => $key, 'value' => is_int($value)
                ? ['intValue' => (string) $value] : ['stringValue' => (string) $value]];
        }
        return $result;
    }

    public function record(string $name, string $type, float $value, array $labels = [], string $unit = ''): void
    {
        if (getenv('OTEL_METRICS_EXPORTER') === 'none' || !is_finite($value)) { return; }
        ksort($labels);
        $key = $name . json_encode($labels, JSON_THROW_ON_ERROR);
        if (!isset($this->metrics[$key])) {
            if (count($this->metrics) >= 256) { return; }
            $this->metrics[$key] = ['name' => $name, 'type' => $type, 'unit' => $unit,
                'labels' => $labels, 'value' => 0.0, 'count' => 0, 'buckets' => array_fill(0, count(self::BUCKETS) + 1, 0)];
        }
        $metric = &$this->metrics[$key];
        if ($type === 'gauge') { $metric['value'] = $value; return; }
        $metric['value'] += $value;
        if ($type === 'histogram') {
            $metric['count']++;
            $bucket = 0;
            while ($bucket < count(self::BUCKETS) && $value > self::BUCKETS[$bucket]) { $bucket++; }
            $metric['buckets'][$bucket]++;
        }
    }

    public function log($level, $message, string $logger, array $context = []): void
    {
        if (count($this->logs) >= 64 || (!is_string($message) && !is_numeric($message))) { return; }
        $body = preg_replace('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', '[email]', (string) $message);
        $body = preg_replace('/(password|authorization|token|secret)[=: ]+[^ ,;\s]+/i', '$1=[redacted]', $body);
        $number = is_object($level) && isset($level->value) ? (int) $level->value : (int) $level;
        $record = ['timeUnixNano' => self::now(), 'body' => ['stringValue' => substr($body, 0, 4096)],
            'severityNumber' => $number >= 500 ? 21 : ($number >= 400 ? 17 : ($number >= 300 ? 13 : ($number >= 200 ? 9 : 5))),
            'attributes' => self::attributes(['log.logger' => substr($logger, 0, 128)])];
        if (!empty($context['traceId'])) {
            $record['traceId'] = $context['traceId'];
            $record['spanId'] = $context['spanId'];
        }
        $this->logs[] = $record;
    }

    public function flush(): void
    {
        $metrics = $this->metrics;
        $logs = $this->logs;
        $started = $this->started;
        $this->metrics = $this->logs = [];
        $this->started = self::now();
        $resource = ['attributes' => self::attributes(self::resource())];
        $scope = ['name' => 'perspective.magento.compat', 'version' => '0.1.0'];
        $endpoint = rtrim(getenv('OTEL_EXPORTER_OTLP_ENDPOINT') ?: 'http://127.0.0.1:4318', '/');
        $export = [];
        foreach ($metrics as $metric) {
            $point = ['timeUnixNano' => $this->started, 'attributes' => self::attributes($metric['labels'])];
            $data = [];
            if ($metric['type'] === 'histogram') {
                $point += ['startTimeUnixNano' => $started, 'count' => (string) $metric['count'],
                    'sum' => $metric['value'], 'explicitBounds' => self::BUCKETS,
                    'bucketCounts' => array_map('strval', $metric['buckets'])];
                $data['aggregationTemporality'] = 1;
            } else {
                $point['asDouble'] = $metric['value'];
                if ($metric['type'] === 'sum') {
                    $point['startTimeUnixNano'] = $started;
                    $data = ['aggregationTemporality' => 1, 'isMonotonic' => true];
                }
            }
            $key = $metric['name'] . ':' . $metric['type'];
            if (!isset($export[$key])) {
                $data['dataPoints'] = [];
                $export[$key] = ['name' => $metric['name'], 'unit' => $metric['unit'], $metric['type'] => $data];
            }
            $export[$key][$metric['type']]['dataPoints'][] = $point;
        }
        if ($export) {
            $this->sender->post($endpoint . '/v1/metrics', json_encode(['resourceMetrics' => [[
                'resource' => $resource, 'scopeMetrics' => [['scope' => $scope, 'metrics' => array_values($export)]],
            ]]], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
        }
        if ($logs) {
            $this->sender->post($endpoint . '/v1/logs', json_encode(['resourceLogs' => [[
                'resource' => $resource, 'scopeLogs' => [['scope' => $scope, 'logRecords' => $logs]],
            ]]], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
        }
    }
}
