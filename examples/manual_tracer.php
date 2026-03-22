<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Tokvera\FinishSpanOptions;
use Tokvera\TokveraSdk;
use Tokvera\TrackOptions;
use Tokvera\Usage;

$apiBaseUrl = getenv('TOKVERA_API_BASE_URL')
    ?: (getenv('TOKVERA_INGEST_URL') ? preg_replace('#/v1/events/?$#', '', getenv('TOKVERA_INGEST_URL')) : 'https://api.tokvera.org');
$feature = getenv('TOKVERA_FEATURE') ?: 'existing_app';
$tenantId = getenv('TOKVERA_TENANT_ID') ?: 'tenant_demo';
$environment = getenv('TOKVERA_ENVIRONMENT') ?: 'production';

$tracer = TokveraSdk::createTracer(new TrackOptions([
    'apiKey' => getenv('TOKVERA_API_KEY') ?: 'tok_live_replace_me',
    'baseUrl' => $apiBaseUrl,
    'feature' => $feature,
    'tenantId' => $tenantId,
    'environment' => $environment,
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
