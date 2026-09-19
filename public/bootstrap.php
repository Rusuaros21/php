<?php

declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

$config = require __DIR__ . '/../config/config.php';

App\Support\Auth::requireBasicAuth($config);

return $config;
