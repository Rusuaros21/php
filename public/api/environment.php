<?php

declare(strict_types=1);

use App\Support\Environment;

$config = require __DIR__ . '/../bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

echo json_encode(Environment::detect(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
