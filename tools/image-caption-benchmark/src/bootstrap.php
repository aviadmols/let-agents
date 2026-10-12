<?php

/*
 * Boots the API app for its classes and HTTP client and loads the benchmark's own classes.
 * Nothing here is registered with the app: no provider, command, route or setting.
 */

$api = dirname(__DIR__, 3).'/apps/api';

require $api.'/vendor/autoload.php';

$app = require $api.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

foreach (['Vocabulary', 'Catalog', 'MetaBuilder', 'GeminiVision', 'Flow', 'Evaluator', 'Store', 'Search', 'Report'] as $class) {
    require_once __DIR__.'/'.$class.'.php';
}

return require dirname(__DIR__).'/config.php';
