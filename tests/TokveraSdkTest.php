<?php

declare(strict_types=1);

namespace Tokvera\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tokvera\FinishSpanOptions;
use Tokvera\IngestClient;
use Tokvera\ProviderRequest;
use Tokvera\ProviderResult;
use Tokvera\TokveraSdk;
use Tokvera\TrackOptions;
use Tokvera\Usage;

final class RecordingClient implements IngestClient
{
    public array $events = [];

    public function ingestEvent(array $event): void
    {
        $this->events[] = $event;
    }
}

final class TokveraSdkTest extends TestCase
{
    public function testManualTracingKeepsLifecycleOnOneTrace(): void
    {
        $client = new RecordingClient();
        $tracer = TokveraSdk::createTracer(new TrackOptions([
            'apiKey' => 'tok_test',
            'feature' => 'existing_app',
            'tenantId' => 'tenant_test',
            'captureContent' => true,
            'emitLifecycleEvents' => true,
        ]), $client);

        $root = $tracer->startTrace(new TrackOptions(['stepName' => 'request_flow']));
        $child = $tracer->startSpan($root, new TrackOptions([
            'provider' => 'openai',
            'eventType' => 'openai.request',
            'endpoint' => 'responses.create',
            'model' => 'gpt-4o-mini',
        ]));
        $child = $tracer->attachPayload($child, ['prompt' => 'Hello'], 'prompt_input');
        $tracer->finishSpan($child, new FinishSpanOptions([
            'usage' => new Usage(12, 8, 20),
        ]));
        $tracer->finishSpan($root, new FinishSpanOptions(['outcome' => 'success']));

        self::assertNotSame($root->spanId, $child->spanId);
        self::assertSame($root->spanId, $child->parentSpanId);
        self::assertCount(4, $client->events);
        self::assertSame('in_progress', $client->events[0]['status']);
        self::assertSame('success', $client->events[2]['status']);
        self::assertSame($root->traceId, $client->events[2]['tags']['trace_id']);
        self::assertSame($child->spanId, $client->events[2]['tags']['span_id']);
        self::assertCount(1, $client->events[2]['payload_blocks']);
    }

    public function testProviderWrapperEmitsMistralChildSpan(): void
    {
        $client = new RecordingClient();
        $tracer = TokveraSdk::createTracer(new TrackOptions([
            'apiKey' => 'tok_test',
            'feature' => 'router',
            'tenantId' => 'tenant_test',
            'captureContent' => true,
        ]), $client);
        $root = $tracer->startTrace(new TrackOptions(['stepName' => 'router_root']));

        $result = $tracer->trackMistral($root, new ProviderRequest([
            'model' => 'mistral-small',
            'input' => ['prompt' => 'Classify'],
        ]), static fn () => new ProviderResult([
            'output' => ['label' => 'billing'],
            'usage' => new Usage(10, 2, 12),
        ]));

        self::assertSame('billing', $result->output['label']);
        self::assertCount(1, $client->events);
        self::assertSame('mistral.request', $client->events[0]['event_type']);
        self::assertSame('mistral', $client->events[0]['provider']);
        self::assertSame($root->traceId, $client->events[0]['tags']['trace_id']);
    }

    public function testProviderWrapperRequiresProviderResult(): void
    {
        $this->expectException(RuntimeException::class);

        $client = new RecordingClient();
        $tracer = TokveraSdk::createTracer(new TrackOptions(['apiKey' => 'tok_test']), $client);
        $root = $tracer->startTrace();
        $tracer->trackOpenAI($root, new ProviderRequest(), static fn () => null);
    }

    public function testOtelBridgeExportsCanonicalSpan(): void
    {
        $client = new RecordingClient();
        $bridge = TokveraSdk::createOtelBridge(new TrackOptions(['apiKey' => 'tok_test']), $client);
        $bridge->exportSpans([[
            'name' => 'llm_call',
            'trace_id' => 'trc_otel',
            'span_id' => 'spn_otel',
            'start_time' => new \DateTimeImmutable('-1 second'),
            'end_time' => new \DateTimeImmutable('now'),
            'status_code' => 'ok',
            'attributes' => [
                'llm.provider' => 'openai',
                'gen_ai.request.model' => 'gpt-4o-mini',
                'tokvera.event_type' => 'openai.request',
                'tokvera.endpoint' => 'responses.create',
                'gen_ai.usage.total_tokens' => 17,
            ],
        ]]);

        self::assertCount(1, $client->events);
        self::assertSame('openai.request', $client->events[0]['event_type']);
        self::assertSame('trc_otel', $client->events[0]['tags']['trace_id']);
    }
}
