<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Tokvera\FinishSpanOptions;
use Tokvera\ProviderRequest;
use Tokvera\ProviderResult;
use Tokvera\TokveraSdk;
use Tokvera\TrackOptions;
use Tokvera\Usage;

$apiBaseUrl = getenv('TOKVERA_API_BASE_URL')
    ?: (getenv('TOKVERA_INGEST_URL') ? preg_replace('#/v1/events/?$#', '', getenv('TOKVERA_INGEST_URL')) : 'https://api.tokvera.org');
$feature = getenv('TOKVERA_FEATURE') ?: 'router';
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
    'stepName' => 'router_root',
    'spanKind' => 'orchestrator',
]));

$tracer->trackMistral($root, new ProviderRequest([
    'model' => 'mistral-small',
    'input' => ['prompt' => 'Classify this ticket.'],
]), static fn () => new ProviderResult([
    'output' => ['route' => 'billing'],
    'usage' => new Usage(11, 3, 14),
]));

$tracer->finishSpan($root, new FinishSpanOptions(['outcome' => 'success']));

echo "Tracked provider child span.\n";
