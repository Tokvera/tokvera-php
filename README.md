# tokvera-php

Preview PHP SDK for Tokvera tracing.

Current Wave 3 preview surface:
- manual tracer substrate
- lifecycle-capable root and child spans
- provider wrappers for OpenAI, Anthropic, Gemini, and Mistral
- OTel bridge
- runnable examples
- canonical contract check

This repo is not official until it clears:
- native PHP test execution in CI and local toolchains
- canonical contract validation
- shared smoke and soak visibility in `tokvera`
- dashboard visibility in traces, live traces, and trace detail

## Install

```bash
composer require tokvera/tokvera-php
```

## Quickstart

```php
use Tokvera\TokveraSdk;
use Tokvera\TrackOptions;

$tracer = TokveraSdk::createTracer(new TrackOptions([
    'apiKey' => getenv('TOKVERA_API_KEY'),
    'feature' => 'existing_app',
    'captureContent' => true,
    'emitLifecycleEvents' => true,
]));

$trace = $tracer->startTrace();
$span = $tracer->startSpan($trace, new TrackOptions(['stepName' => 'plan_response']));
$tracer->finishSpan($span);
```

## Examples

- `examples/manual_tracer.php`
- `examples/provider_wrappers.php`
- `examples/otel_bridge.php`

## Contract check

```bash
node scripts/check-canonical-contract.mjs
```
