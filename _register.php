<?php

declare(strict_types=1);

use OpenTelemetry\SDK\Sdk;
use ReactInspector\Cache\ReactCacheInstrumentation;

if (class_exists(Sdk::class) && Sdk::isInstrumentationDisabled(ReactCacheInstrumentation::NAME) === true) {
    return;
}

if (extension_loaded('opentelemetry') === false) {
    trigger_error('The opentelemetry extension must be loaded in order to autoload the OpenTelemetry PSR-16 auto-instrumentation', E_USER_WARNING);

    return;
}

ReactCacheInstrumentation::register();
