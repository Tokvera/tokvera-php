<?php

declare(strict_types=1);

namespace Tokvera;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use stdClass;
use Throwable;

final class TrackOptions
{
    /** @var array<string, true> */
    private array $provided = [];
    public ?string $apiKey = null;
    public ?string $baseUrl = 'https://api.tokvera.org';
    public ?string $feature = null;
    public ?string $tenantId = null;
    public ?string $customerId = null;
    public ?string $environment = null;
    public ?string $plan = null;
    public ?string $traceId = null;
    public ?string $runId = null;
    public ?string $conversationId = null;
    public ?string $spanId = null;
    public ?string $parentSpanId = null;
    public ?string $provider = null;
    public ?string $eventType = null;
    public ?string $endpoint = null;
    public ?string $model = null;
    public ?string $stepName = null;
    public ?string $spanKind = null;
    public ?string $toolName = null;
    public ?string $attemptType = null;
    public ?string $outcome = null;
    public ?string $retryReason = null;
    public ?string $fallbackReason = null;
    public ?string $qualityLabel = null;
    public ?float $feedbackScore = null;
    public bool $captureContent = false;
    public bool $emitLifecycleEvents = false;
    public array $payloadRefs = [];
    public array $payloadBlocks = [];
    public array $metrics = [];
    public array $decision = [];
    public ?string $schemaVersion = null;

    public function __construct(array $values = [])
    {
        foreach ($values as $key => $value) {
            if (property_exists($this, $key)) {
                $this->provided[$key] = true;
                $this->{$key} = $value;
            }
        }
    }

    public function wasProvided(string $key): bool
    {
        return isset($this->provided[$key]);
    }

    public static function merge(self $base, ?self $override = null): self
    {
        $merged = clone $base;
        if ($override === null) {
            return $merged;
        }
        foreach (get_object_vars($override) as $key => $value) {
            if ($key === 'provided') {
                continue;
            }
            if (is_bool($value)) {
                if ($override->wasProvided($key)) {
                    $merged->{$key} = $value;
                }
                continue;
            }
            if (is_array($value) && $value !== []) {
                $merged->{$key} = $value;
                continue;
            }
            if ($value !== null && $value !== '') {
                $merged->{$key} = $value;
            }
        }
        $merged->provided = array_merge($merged->provided, $override->provided);
        return $merged;
    }
}

final class Usage
{
    public function __construct(
        public int $promptTokens = 0,
        public int $completionTokens = 0,
        public int $totalTokens = 0,
    ) {}

    public function toArray(): array
    {
        return [
            'prompt_tokens' => $this->promptTokens,
            'completion_tokens' => $this->completionTokens,
            'total_tokens' => $this->totalTokens,
        ];
    }
}

final class FinishSpanOptions
{
    public ?Usage $usage = null;
    public ?string $outcome = null;
    public ?string $qualityLabel = null;
    public ?float $feedbackScore = null;
    public array $metrics = [];
    public array $decision = [];
    public array $payloadBlocks = [];
    public ?array $error = null;

    public function __construct(array $values = [])
    {
        foreach ($values as $key => $value) {
            if (property_exists($this, $key)) {
                $this->{$key} = $value;
            }
        }
    }
}

final class TraceHandle
{
    public function __construct(
        public string $traceId,
        public string $runId,
        public string $spanId,
        public ?string $parentSpanId,
        public DateTimeImmutable $startedAt,
        public string $provider,
        public string $eventType,
        public string $endpoint,
        public string $model,
        public TrackOptions $options,
    ) {}
}

final class ProviderRequest
{
    public ?string $model = null;
    public mixed $input = null;
    public ?string $eventType = null;
    public ?string $endpoint = null;
    public ?string $stepName = null;
    public ?string $spanKind = null;
    public ?string $toolName = null;

    public function __construct(array $values = [])
    {
        foreach ($values as $key => $value) {
            if (property_exists($this, $key)) {
                $this->{$key} = $value;
            }
        }
    }
}

final class ProviderResult
{
    public mixed $output = null;
    public ?Usage $usage = null;
    public ?string $outcome = 'success';
    public ?string $qualityLabel = null;
    public ?float $feedbackScore = null;
    public array $metrics = [];
    public array $decision = [];

    public function __construct(array $values = [])
    {
        foreach ($values as $key => $value) {
            if (property_exists($this, $key)) {
                $this->{$key} = $value;
            }
        }
    }
}

interface IngestClient
{
    public function ingestEvent(array $event): void;
}

class TokveraClient implements IngestClient
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl = 'https://api.tokvera.org',
    ) {}

    public function ingestEvent(array $event): void
    {
        $endpoint = rtrim($this->baseUrl, '/') . '/v1/events';
        $payload = json_encode($event, JSON_THROW_ON_ERROR);
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey,
        ];
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $payload,
                'ignore_errors' => true,
                'timeout' => 10,
            ],
        ]);
        $result = @file_get_contents($endpoint, false, $context);
        $statusLine = $http_response_header[0] ?? '';
        if (!is_string($statusLine) || !preg_match('/\s(\d{3})\s/', $statusLine, $matches)) {
            throw new RuntimeException('tokvera ingest failed: missing response status');
        }
        $status = (int) $matches[1];
        if ($status >= 400) {
            throw new RuntimeException('tokvera ingest failed: ' . $status . ' ' . (is_string($result) ? $result : ''));
        }
    }
}

final class TokveraTracer
{
    public const TRACE_SCHEMA_VERSION_V2 = '2026-04-01';

    public function __construct(
        private readonly TrackOptions $baseOptions,
        private readonly IngestClient $client,
    ) {}

    public static function create(TrackOptions $options, ?IngestClient $client = null): self
    {
        return new self(
            $options,
            $client ?? new TokveraClient((string) $options->apiKey, (string) ($options->baseUrl ?? 'https://api.tokvera.org'))
        );
    }

    public function startTrace(?TrackOptions $options = null): TraceHandle
    {
        $merged = TrackOptions::merge($this->baseOptions, $options);
        $handle = new TraceHandle(
            $options?->traceId ?: $merged->traceId ?: self::id('trc'),
            $options?->runId ?: $merged->runId ?: self::id('run'),
            $options?->spanId ?: $merged->spanId ?: self::id('spn'),
            null,
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
            $options?->provider ?: $merged->provider ?: 'tokvera',
            $options?->eventType ?: $merged->eventType ?: 'tokvera.trace',
            $options?->endpoint ?: $merged->endpoint ?: 'manual.trace',
            $options?->model ?: $merged->model ?: 'manual',
            $merged,
        );
        $this->hydrateOptions($handle);
        if ($handle->options->emitLifecycleEvents) {
            $this->client->ingestEvent($this->buildEvent($handle, 'in_progress', new FinishSpanOptions()));
        }
        return $handle;
    }

    public function startSpan(TraceHandle $parent, ?TrackOptions $options = null): TraceHandle
    {
        $merged = TrackOptions::merge(TrackOptions::merge($this->baseOptions, $parent->options), $options);
        $handle = new TraceHandle(
            $options?->traceId ?: $merged->traceId ?: $parent->traceId,
            $options?->runId ?: $merged->runId ?: $parent->runId,
            $options?->spanId ?: self::id('spn'),
            $options?->parentSpanId ?: $merged->parentSpanId ?: $parent->spanId,
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
            $options?->provider ?: $merged->provider ?: $parent->provider,
            $options?->eventType ?: $merged->eventType ?: 'tokvera.trace',
            $options?->endpoint ?: $merged->endpoint ?: 'manual.span',
            $options?->model ?: $merged->model ?: $parent->model,
            $merged,
        );
        $this->hydrateOptions($handle);
        if ($handle->options->emitLifecycleEvents) {
            $this->client->ingestEvent($this->buildEvent($handle, 'in_progress', new FinishSpanOptions()));
        }
        return $handle;
    }

    public function attachPayload(TraceHandle $handle, mixed $payload, string $payloadType = 'other'): TraceHandle
    {
        $updatedOptions = clone $handle->options;
        $updatedOptions->payloadBlocks[] = [
            'payload_type' => $payloadType,
            'content' => is_string($payload) ? $payload : json_encode($payload, JSON_THROW_ON_ERROR),
        ];
        return new TraceHandle(
            $handle->traceId,
            $handle->runId,
            $handle->spanId,
            $handle->parentSpanId,
            $handle->startedAt,
            $handle->provider,
            $handle->eventType,
            $handle->endpoint,
            $handle->model,
            $updatedOptions,
        );
    }

    public function finishSpan(TraceHandle $handle, ?FinishSpanOptions $options = null): void
    {
        $this->client->ingestEvent($this->buildEvent($handle, 'success', $options ?? new FinishSpanOptions()));
    }

    public function failSpan(TraceHandle $handle, Throwable $error, ?FinishSpanOptions $options = null): void
    {
        $effective = $options ?? new FinishSpanOptions();
        if ($effective->error === null) {
            $effective->error = ['type' => 'runtime_error', 'message' => $error->getMessage() ?: 'span failed'];
        }
        $this->client->ingestEvent($this->buildEvent($handle, 'failure', $effective));
    }

    public function getTrackOptionsFromTraceContext(TraceHandle $handle, ?TrackOptions $overrides = null): TrackOptions
    {
        $merged = TrackOptions::merge(TrackOptions::merge($this->baseOptions, $handle->options), $overrides);
        $merged->traceId = $overrides?->traceId ?: $handle->traceId;
        $merged->runId = $overrides?->runId ?: $handle->runId;
        $merged->parentSpanId = $overrides?->parentSpanId ?: $handle->spanId;
        $merged->spanId = $overrides?->spanId;
        $merged->provider = $overrides?->provider ?: $handle->provider;
        $merged->eventType = $overrides?->eventType ?: $handle->eventType;
        $merged->endpoint = $overrides?->endpoint ?: $handle->endpoint;
        $merged->model = $overrides?->model ?: $handle->model;
        return $merged;
    }

    public function trackOpenAI(TraceHandle $parent, ProviderRequest $request, callable $operation): ProviderResult
    {
        return $this->trackProvider($parent, 'openai', $request, $operation);
    }

    public function trackAnthropic(TraceHandle $parent, ProviderRequest $request, callable $operation): ProviderResult
    {
        return $this->trackProvider($parent, 'anthropic', $request, $operation);
    }

    public function trackGemini(TraceHandle $parent, ProviderRequest $request, callable $operation): ProviderResult
    {
        return $this->trackProvider($parent, 'gemini', $request, $operation);
    }

    public function trackMistral(TraceHandle $parent, ProviderRequest $request, callable $operation): ProviderResult
    {
        return $this->trackProvider($parent, 'mistral', $request, $operation);
    }

    private function trackProvider(TraceHandle $parent, string $provider, ProviderRequest $request, callable $operation): ProviderResult
    {
        $child = $this->startSpan($parent, new TrackOptions([
            'provider' => $provider,
            'eventType' => $request->eventType ?: $provider . '.request',
            'endpoint' => $request->endpoint ?: self::defaultProviderEndpoint($provider),
            'model' => $request->model,
            'stepName' => $request->stepName ?: $provider . '_call',
            'spanKind' => $request->spanKind ?: 'model',
            'toolName' => $request->toolName,
        ]));
        if ($request->input !== null && $child->options->captureContent) {
            $child = $this->attachPayload($child, $request->input, 'prompt_input');
        }
        try {
            $result = $operation();
        } catch (Throwable $error) {
            $this->failSpan($child, $error);
            throw $error;
        }
        if (!$result instanceof ProviderResult) {
            throw new RuntimeException('provider operation must return ProviderResult');
        }
        if ($result->output !== null && $child->options->captureContent) {
            $child = $this->attachPayload($child, $result->output, 'model_output');
        }
        $this->finishSpan($child, new FinishSpanOptions([
            'usage' => $result->usage,
            'outcome' => $result->outcome,
            'qualityLabel' => $result->qualityLabel,
            'feedbackScore' => $result->feedbackScore,
            'metrics' => $result->metrics,
            'decision' => $result->decision,
        ]));
        return $result;
    }

    private function buildEvent(TraceHandle $handle, string $status, FinishSpanOptions $options): array
    {
        $usage = $options->usage ?? new Usage();
        $latencyMs = $options->metrics['latency_ms'] ?? max(1, ((new DateTimeImmutable('now', new DateTimeZone('UTC')))->getTimestamp() - $handle->startedAt->getTimestamp()) * 1000);
        $outcome = $options->outcome ?: $handle->options->outcome ?: ($status === 'failure' ? 'failure' : 'success');
        $retryReason = $handle->options->retryReason ?: ($options->decision['retry_reason'] ?? null);
        $fallbackReason = $handle->options->fallbackReason ?: ($options->decision['fallback_reason'] ?? null);
        $qualityLabel = $options->qualityLabel ?: $handle->options->qualityLabel;
        $feedbackScore = $options->feedbackScore ?? $handle->options->feedbackScore;

        $evaluation = null;
        if ($outcome !== null || $retryReason !== null || $fallbackReason !== null || $qualityLabel !== null || $feedbackScore !== null) {
            $evaluation = [
                'outcome' => $outcome,
                'retry_reason' => $retryReason,
                'fallback_reason' => $fallbackReason,
                'quality_label' => $qualityLabel,
                'feedback_score' => $feedbackScore,
            ];
        }

        return [
            'schema_version' => $handle->options->schemaVersion ?: self::TRACE_SCHEMA_VERSION_V2,
            'event_type' => $handle->eventType,
            'provider' => $handle->provider,
            'endpoint' => $handle->endpoint,
            'status' => $status,
            'timestamp' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM),
            'latency_ms' => $latencyMs,
            'model' => $handle->model,
            'usage' => $usage->toArray(),
            'tags' => [
                'feature' => $handle->options->feature,
                'tenant_id' => $handle->options->tenantId,
                'customer_id' => $handle->options->customerId,
                'environment' => $handle->options->environment,
                'plan' => $handle->options->plan,
                'attempt_type' => $handle->options->attemptType,
                'trace_id' => $handle->traceId,
                'run_id' => $handle->runId,
                'conversation_id' => $handle->options->conversationId,
                'span_id' => $handle->spanId,
                'parent_span_id' => $handle->parentSpanId,
                'step_name' => $handle->options->stepName,
                'outcome' => $outcome,
                'retry_reason' => $retryReason,
                'fallback_reason' => $fallbackReason,
                'quality_label' => $qualityLabel,
                'feedback_score' => $feedbackScore,
            ],
            'evaluation' => $evaluation,
            'span_kind' => $handle->options->spanKind,
            'tool_name' => $handle->options->toolName,
            'payload_refs' => $handle->options->payloadRefs,
            'payload_blocks' => array_values(array_merge($handle->options->payloadBlocks, $options->payloadBlocks)),
            'metrics' => self::normalizeObjectValue(array_merge($handle->options->metrics, $options->metrics, [
                'latency_ms' => $latencyMs,
                'prompt_tokens' => $usage->promptTokens,
                'completion_tokens' => $usage->completionTokens,
                'total_tokens' => $usage->totalTokens,
            ])),
            'decision' => self::normalizeObjectValue(array_merge($handle->options->decision, $options->decision)),
            'error' => $options->error,
        ];
    }

    private function hydrateOptions(TraceHandle $handle): void
    {
        $handle->options->traceId = $handle->traceId;
        $handle->options->runId = $handle->runId;
        $handle->options->spanId = $handle->spanId;
        $handle->options->parentSpanId = $handle->parentSpanId;
        $handle->options->provider = $handle->provider;
        $handle->options->eventType = $handle->eventType;
        $handle->options->endpoint = $handle->endpoint;
        $handle->options->model = $handle->model;
        $handle->options->stepName = $handle->options->stepName ?: ($handle->parentSpanId === null ? 'trace_root' : 'span_step');
        $handle->options->spanKind = $handle->options->spanKind ?: 'orchestrator';
        $handle->options->schemaVersion = $handle->options->schemaVersion ?: self::TRACE_SCHEMA_VERSION_V2;
    }

    private static function defaultProviderEndpoint(string $provider): string
    {
        return match ($provider) {
            'openai' => 'responses.create',
            'anthropic' => 'messages.create',
            'gemini' => 'models.generate_content',
            'mistral' => 'chat.complete',
            default => 'manual.span',
        };
    }

    private static function id(string $prefix): string
    {
        return $prefix . '_' . bin2hex(random_bytes(12));
    }

    private static function normalizeObjectValue(array $value): array|stdClass
    {
        return $value === [] ? new stdClass() : $value;
    }
}

final class TokveraOtelBridge
{
    public function __construct(
        private readonly TrackOptions $baseOptions,
        private readonly IngestClient $client,
    ) {}

    public static function create(TrackOptions $options, ?IngestClient $client = null): self
    {
        return new self(
            $options,
            $client ?? new TokveraClient((string) $options->apiKey, (string) ($options->baseUrl ?? 'https://api.tokvera.org'))
        );
    }

    public function exportSpans(array $spans): void
    {
        foreach ($spans as $span) {
            $attributes = $span['attributes'] ?? [];
            $start = $span['start_time'] ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $end = $span['end_time'] ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $latencyMs = max(1, ((int) $end->format('Uu') - (int) $start->format('Uu')) / 1000);

            $this->client->ingestEvent([
                'schema_version' => TokveraTracer::TRACE_SCHEMA_VERSION_V2,
                'event_type' => $attributes['tokvera.event_type'] ?? 'tokvera.trace',
                'provider' => $attributes['llm.provider'] ?? 'tokvera',
                'endpoint' => $attributes['tokvera.endpoint'] ?? 'otel.span',
                'status' => ($span['status_code'] ?? 'ok') === 'error' ? 'failure' : 'success',
                'timestamp' => $end->format(DATE_ATOM),
                'latency_ms' => (int) $latencyMs,
                'model' => $attributes['gen_ai.request.model'] ?? 'otel',
                'usage' => [
                    'prompt_tokens' => (int) ($attributes['gen_ai.usage.input_tokens'] ?? 0),
                    'completion_tokens' => (int) ($attributes['gen_ai.usage.output_tokens'] ?? 0),
                    'total_tokens' => (int) ($attributes['gen_ai.usage.total_tokens'] ?? 0),
                ],
                'tags' => [
                    'feature' => $attributes['tokvera.feature'] ?? ($this->baseOptions->feature ?? 'otel_bridge'),
                    'tenant_id' => $attributes['tokvera.tenant_id'] ?? ($this->baseOptions->tenantId ?? 'otel'),
                    'trace_id' => $span['trace_id'],
                    'run_id' => $attributes['tokvera.run_id'] ?? $span['trace_id'],
                    'span_id' => $span['span_id'],
                    'parent_span_id' => $span['parent_span_id'] ?? null,
                    'step_name' => $span['name'] ?? 'otel_span',
                    'outcome' => ($span['status_code'] ?? 'ok') === 'error' ? 'failure' : 'success',
                ],
                'span_kind' => $attributes['tokvera.span_kind'] ?? 'orchestrator',
                'metrics' => [
                    'latency_ms' => (int) $latencyMs,
                    'prompt_tokens' => (int) ($attributes['gen_ai.usage.input_tokens'] ?? 0),
                    'completion_tokens' => (int) ($attributes['gen_ai.usage.output_tokens'] ?? 0),
                    'total_tokens' => (int) ($attributes['gen_ai.usage.total_tokens'] ?? 0),
                ],
            ]);
        }
    }
}

final class TokveraSdk
{
    public static function createTracer(TrackOptions $options, ?IngestClient $client = null): TokveraTracer
    {
        return TokveraTracer::create($options, $client);
    }

    public static function createOtelBridge(TrackOptions $options, ?IngestClient $client = null): TokveraOtelBridge
    {
        return TokveraOtelBridge::create($options, $client);
    }
}
