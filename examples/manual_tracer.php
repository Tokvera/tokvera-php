<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Tokvera\FinishSpanOptions;
use Tokvera\TokveraSdk;
use Tokvera\TrackOptions;
use Tokvera\Usage;

$tracer = TokveraSdk::createTracer(new TrackOptions([
    'apiKey' => getenv('TOKVERA_API_KEY') ?: 'tok_live_replace_me',
    'feature' => 'existing_app',
    'tenantId' => 'tenant_demo',
    'environment' => 'production',
    'captureContent' => true,
    'emitLifecycleEvents' => true,
]));

$root = $tracer->startTrace(new TrackOptions([
    'stepName' => 'request_flow',
    'spanKind' => 'orchestrator',
]));
$child = $tracer->startSpan($root, new TrackOptions([
    'provider' => 'openai',
    'eventType' => 'openai.request',
    'endpoint' => 'responses.create',
    'model' => 'gpt-4o-mini',
    'stepName' => 'draft_reply',
    'spanKind' => 'model',
]));
$child = $tracer->attachPayload($child, ['prompt' => 'Draft a short support reply.'], 'prompt_input');
$tracer->finishSpan($child, new FinishSpanOptions([
    'usage' => new Usage(24, 48, 72),
    'outcome' => 'success',
]));
$tracer->finishSpan($root, new FinishSpanOptions(['outcome' => 'success']));

echo "Sent lifecycle-enabled trace to Tokvera.\n";
