<?php
declare(strict_types=1);

namespace Perspective\ObservabilityCompat\Model;

/** Owns telemetry state only. No Magento configuration, messages or response setters. */
class Telemetry
{
    // Values from OpenTelemetry API SpanKind, available even without the optional SDK.
    public const INTERNAL = 0;
    public const CLIENT = 1;
    public const SERVER = 2;
    public const PRODUCER = 3;
    public const CONSUMER = 4;
    private HttpSender $sender;
    private Signals $signals;
    private bool $busy = false;
    private bool $initialized = false;
    private bool $shutdownRegistered = false;
    private bool $reported = false;
    private $provider = null;
    private $tracer = null;
    private $request = null;
    private array $cron = [];
    private \SplObjectStorage $responses;
    private \SplObjectStorage $messages;

    public function __construct(HttpSender $sender, Signals $signals)
    {
        $this->sender = $sender;
        $this->signals = $signals;
        $this->responses = new \SplObjectStorage();
        $this->messages = new \SplObjectStorage();
    }

    public function enabled(): bool
    {
        return !$this->busy
            && filter_var(getenv('OBSERVABILITY_COMPAT_ENABLED'), FILTER_VALIDATE_BOOLEAN)
            && !filter_var(getenv('OTEL_SDK_DISABLED'), FILTER_VALIDATE_BOOLEAN);
    }

    public function guard(\Closure $callback)
    {
        $previous = $this->busy;
        $this->busy = true;
        try { return $callback(); }
        catch (\Throwable $error) {
            if (!$this->reported) {
                $this->reported = true;
                try { @error_log('Perspective_ObservabilityCompat telemetry skipped: ' . get_class($error)); }
                catch (\Throwable $loggingError) {}
            }
            return null;
        } finally { $this->busy = $previous; }
    }

    private function initialize(): void
    {
        if (!$this->shutdownRegistered) {
            $this->shutdownRegistered = true;
            register_shutdown_function(function (): void { $this->shutdown(); });
        }
        if ($this->initialized) { return; }
        $this->initialized = true;
        \Perspective\Observability\Profiler::register();
        if (getenv('OTEL_TRACES_EXPORTER') === 'none'
            || !class_exists(\OpenTelemetry\SDK\Trace\TracerProvider::class)) { return; }
        $endpoint = getenv('OTEL_EXPORTER_ZIPKIN_ENDPOINT') ?: 'http://127.0.0.1:9411/api/v2/spans';
        $exporter = new \OpenTelemetry\Contrib\Zipkin\Exporter(new TraceTransport($this->sender, $endpoint));
        $processor = new \OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor(
            $exporter, \OpenTelemetry\SDK\Common\Time\ClockFactory::getDefault(), 512, 5000, 250, 128, false
        );
        $ratio = getenv('OTEL_TRACES_SAMPLER_ARG');
        $ratio = $ratio === false || $ratio === '' ? 0.1 : max(0, min(1, (float) $ratio));
        $this->provider = \OpenTelemetry\SDK\Trace\TracerProvider::builder()
            ->setSampler(new \OpenTelemetry\SDK\Trace\Sampler\ParentBased(
                new \OpenTelemetry\SDK\Trace\Sampler\TraceIdRatioBasedSampler($ratio)))
            ->setResource(\OpenTelemetry\SDK\Resource\ResourceInfo::create(
                \OpenTelemetry\SDK\Common\Attribute\Attributes::create(Signals::resource())))
            ->addSpanProcessor($processor)->build();
        $this->tracer = $this->provider->getTracer('perspective.magento.compat', '0.1.0');
    }

    public function begin(string $name, int $kind = self::INTERNAL, array $attributes = [], array $headers = [])
    {
        if (!$this->enabled()) { return null; }
        // Keep timing/metric state even when the optional SDK cannot initialize.
        $frame = (object) ['span' => null, 'scope' => null, 'closed' => false, 'start' => hrtime(true),
            'route' => 'unresolved', 'method' => 'HTTP'];
        $this->guard(function () use ($frame, $name, $kind, $attributes, $headers): void {
            $this->initialize();
            if (!$this->tracer) { return; }
            $builder = $this->tracer->spanBuilder(substr($name, 0, 128))->setSpanKind($kind);
            if ($headers) {
                $builder->setParent(\OpenTelemetry\API\Trace\Propagation\TraceContextPropagator::getInstance()->extract($headers));
            }
            foreach ($attributes as $key => $value) { $builder->setAttribute($key, $value); }
            $frame->span = $builder->startSpan();
            $frame->scope = $frame->span->activate();
        });
        return $frame;
    }

    public function finish($frame, ?\Throwable $error = null, bool $failed = false): void
    {
        if (!$frame || $frame->closed) { return; }
        $frame->closed = true;
        // Attempt each cleanup independently; a bad detach must not skip span ending.
        $this->detach($frame);
        $this->guard(static function () use ($frame, $error, $failed): void {
            if (!$frame->span) { return; }
            try {
                if ($error || $failed) {
                    $frame->span->setStatus('Error');
                    if ($error) { $frame->span->setAttribute('error.type', get_class($error)); }
                }
            } finally { $frame->span->end(); }
        });
    }

    private function detach($frame): void
    {
        if ($frame && $frame->scope) {
            $scope = $frame->scope;
            $frame->scope = null;
            $this->guard(static function () use ($scope): void { $scope->detach(); });
        }
    }

    public function complete($frame, ?\Throwable $error, ?string $job): void
    {
        $this->finish($frame, $error);
        if ($frame && $job) { $this->job($job, $error !== null); }
    }

    public function startRequest(array $headers = [])
    {
        $frame = $this->begin('HTTP request', self::SERVER, [], $headers);
        if ($frame) { $this->request = $frame; }
        return $frame;
    }

    public function route($request): void
    {
        if (!$this->request || !$this->enabled()) { return; }
        $this->guard(function () use ($request): void {
            $route = (string) $request->getFullActionName();
            $method = (string) $request->getMethod();
            $this->request->route = preg_match('/^[a-zA-Z0-9_]{1,128}$/D', $route) ? $route : 'unresolved';
            $this->request->method = in_array($method, ['GET','POST','PUT','PATCH','DELETE','HEAD','OPTIONS'], true) ? $method : 'HTTP';
            if ($this->request->span) {
                $this->request->span->updateName($this->request->method . ' ' . $this->request->route);
                $this->request->span->setAttribute('magento.route', $this->request->route);
            }
        });
    }

    public function response($frame, $response, ?\Throwable $error): void
    {
        if (!$frame) { return; }
        $this->request = null;
        if (!$error && is_object($response) && method_exists($response, 'sendResponse') && count($this->responses) < 32) {
            $this->detach($frame);
            $this->responses[$response] = $frame;
            return;
        }
        $this->finishRequest($frame, $response, $error);
    }

    public function sent($response, ?\Throwable $error): void
    {
        if (!isset($this->responses[$response])) { return; }
        $frame = $this->responses[$response];
        unset($this->responses[$response]);
        $this->finishRequest($frame, $response, $error);
        $this->flush();
    }

    private function finishRequest($frame, $response, ?\Throwable $error): void
    {
        if ($frame->closed) { return; }
        $this->guard(function () use ($frame, $response, $error): void {
            $status = $error ? 500 : (is_object($response) && method_exists($response, 'getHttpResponseCode')
                ? (int) $response->getHttpResponseCode() : 200);
            if ($frame->span) {
                $frame->span->setAttribute('http.response.status_code', $status);
                if ($status >= 500) { $frame->span->setStatus('Error'); }
            }
            $this->signals->record('magento.http.server.duration', 'histogram', (hrtime(true) - $frame->start) / 1000000000,
                ['magento.route' => $frame->route, 'http.response.status_code' => $status], 's');
        });
        $this->finish($frame, $error);
    }

    public function consume($message): void
    {
        if (!$this->enabled() || isset($this->messages[$message]) || count($this->messages) >= 32) { return; }
        $properties = $this->guard(static function () use ($message) { return $message->getProperties(); });
        if (!is_array($properties)) { return; }
        $headers = $properties['application_headers'] ?? [];
        $headers = $this->guard(static function () use ($headers): array {
            return is_object($headers) && method_exists($headers, 'getNativeData') ? $headers->getNativeData() : (array) $headers;
        });
        $topic = is_string($properties['topic_name'] ?? null) ? substr($properties['topic_name'], 0, 128) : 'unknown';
        $this->messages[$message] = $this->begin('consume ' . $topic, self::CONSUMER,
            ['messaging.system' => 'magento', 'messaging.destination.name' => $topic], $headers ?: []);
    }

    public function consumed($message, bool $rejected, ?\Throwable $error): void
    {
        if (!isset($this->messages[$message])) { return; }
        $frame = $this->messages[$message];
        unset($this->messages[$message]);
        $this->finish($frame, $error, $rejected);
        $this->job('consumer', $error !== null || $rejected);
        $this->flush();
    }

    public function startCron(string $jobName): void
    {
        if (!$this->enabled() || count($this->cron) >= 32) { return; }
        $parts = explode('/', $jobName, 3);
        if (count($parts) !== 3 || $parts[0] !== 'cron' || isset($this->cron[$parts[2]])) { return; }
        $this->cron[$parts[2]] = $this->begin('cron ' . substr($parts[2], 0, 128), self::INTERNAL,
            ['magento.cron.job' => substr($parts[2], 0, 128), 'magento.cron.group' => substr($parts[1], 0, 128)]);
    }

    public function savedSchedule($schedule): void
    {
        if (!$this->enabled()) { return; }
        $details = $this->guard(static function () use ($schedule): array { return [$schedule->getJobCode(), $schedule->getStatus()]; });
        if (!$details || !isset($this->cron[$details[0]]) || !in_array($details[1], ['success', 'error', 'missed'], true)) { return; }
        $frame = $this->cron[$details[0]];
        unset($this->cron[$details[0]]);
        $failed = $details[1] !== 'success';
        $this->finish($frame, null, $failed);
        $this->job('cron', $failed);
        $this->flush();
    }

    private function job(string $type, bool $failed): void
    {
        $this->guard(function () use ($type, $failed): void {
            $this->signals->record('magento.job.executions', 'sum', 1, ['job.type' => $type, 'status' => $failed ? 'error' : 'success']);
        });
    }

    public function log($level, $message, $logger): void
    {
        if (!$this->enabled() || getenv('OTEL_LOGS_EXPORTER') === 'none') { return; }
        $this->guard(function () use ($level, $message, $logger): void {
            $this->initialize();
            $context = [];
            if ($this->tracer) {
                $span = \OpenTelemetry\API\Trace\Span::getCurrent()->getContext();
                if ($span->isValid()) { $context = ['traceId' => $span->getTraceId(), 'spanId' => $span->getSpanId()]; }
            }
            $this->signals->log($level, $message, (string) $logger->getName(), $context);
        });
    }

    public function heartbeat($resource): void
    {
        if (!$this->enabled()) { return; }
        $this->guard(function () use ($resource): void {
            $connection = $resource->getConnection();
            $table = $connection->quoteIdentifier($resource->getTableName('cron_schedule'));
            $rows = $connection->fetchPairs("SELECT status,COUNT(*) FROM {$table} WHERE scheduled_at >= UTC_TIMESTAMP() - INTERVAL 1 DAY GROUP BY status");
            $this->signals->record('magento.cron.heartbeat', 'gauge', (float) time(), [], 's');
            foreach (['pending', 'running', 'success', 'missed', 'error'] as $status) {
                $this->signals->record('magento.cron.jobs', 'gauge', (float) ($rows[$status] ?? 0), ['status' => $status]);
            }
        });
        $this->flush();
    }

    public function flush(): void
    {
        $this->guard(function (): void { if ($this->provider) { $this->provider->forceFlush(); } });
        $this->guard(function (): void { $this->signals->flush(); });
    }

    public function shutdown(): void
    {
        $this->guard(function (): void {
            foreach ($this->responses as $response) { $this->finishRequest($this->responses[$response], $response, null); }
            $this->responses = new \SplObjectStorage();
        });
        $this->closeMessages();
        $this->closeCron();
        $this->flush();
        $this->guard(function (): void { if ($this->provider) { $this->provider->shutdown(); } });
    }

    public function closeMessages(): void
    {
        $this->guard(function (): void {
            $frames = [];
            foreach ($this->messages as $message) { $frames[] = $this->messages[$message]; }
            $this->messages = new \SplObjectStorage();
            foreach (array_reverse($frames) as $frame) {
                $this->finish($frame, null, true);
                $this->job('consumer', true);
            }
        });
        $this->flush();
    }

    public function closeCron(): void
    {
        $this->guard(function (): void {
            $frames = array_reverse($this->cron);
            $this->cron = [];
            foreach ($frames as $frame) {
                $this->finish($frame, null, true);
                $this->job('cron', true);
            }
        });
        $this->flush();
    }
}
