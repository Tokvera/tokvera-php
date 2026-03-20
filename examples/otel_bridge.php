<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use DateTimeImmutable;
use Tokvera\TokveraSdk;
use Tokvera\TrackOptions;

$apiBaseUrl = getenv('TOKVERA_API_BASE_URL')
    ?: (getenv('TOKVERA_INGEST_URL') ? preg_replace('#/v1/events/?$#', '', getenv('TOKVERA_INGEST_URL')) : 'https://api.tokvera.org');
$feature = getenv('TOKVERA_FEATURE') ?: 'otel_bridge';
$tenantId = getenv('TOKVERA_TENANT_ID') ?: 'tenant_demo';

$bridge = TokveraSdk::createOtelBridge(new TrackOptions([
    'apiKey' => getenv('TOKVERA_API_KEY') ?: 'tok_live_replace_me',
    'baseUrl' => $apiBaseUrl,
    'feature' => $feature,
    'tenantId' => $tenantId,
]));

$bridge->exportSpans([[
    'name' => 'llm_call',
    'trace_id' => 'trc_php_otel',
    'span_id' => 'spn_php_otel',
    'start_time' => new DateTimeImmutable('-300 milliseconds'),
    'end_time' => new DateTimeImmutable('now'),
    'status_code' => 'ok',
    'attributes' => [
        'llm.provider' => 'openai',
        'gen_ai.request.model' => 'gpt-4o-mini',
        'tokvera.event_type' => 'openai.request',
        'tokvera.endpoint' => 'responses.create',
        'gen_ai.usage.total_tokens' => 19,
    ],
]]);

echo "Forwarded OTel spans to Tokvera.\n";
