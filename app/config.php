<?php

declare(strict_types=1);

return [
    'db' => [
        'host'     => getenv('DB_HOST')     ?: ($_ENV['DB_HOST']     ?? 'localhost'),
        'database' => getenv('DB_NAME')     ?: ($_ENV['DB_NAME']     ?? 'sukali_db'),
        'user'     => getenv('DB_USER')     ?: ($_ENV['DB_USER']     ?? 'root'),
        'password' => getenv('DB_PASSWORD') ?: ($_ENV['DB_PASSWORD'] ?? ''),
        'port'     => getenv('DB_PORT')     ?: ($_ENV['DB_PORT']     ?? '3306'),
    ]
];
