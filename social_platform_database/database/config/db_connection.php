<?php

declare(strict_types=1);

mysqli_report(MYSQLI_REPORT_OFF);

$dbConfig = [
    'host' => getenv('DB_HOST') ?: '127.0.0.1',
    'port' => (int) (getenv('DB_PORT') ?: 3306),
    'database' => getenv('DB_DATABASE') ?: 'social_platform_database',
    'username' => getenv('DB_USERNAME') ?: 'root',
    'password' => getenv('DB_PASSWORD') ?: '',
];

$connection = @new mysqli(
    $dbConfig['host'],
    $dbConfig['username'],
    $dbConfig['password'],
    $dbConfig['database'],
    $dbConfig['port']
);

if ($connection->connect_errno) {
    http_response_code(500);
    exit('فشل الاتصال بقاعدة البيانات: ' . htmlspecialchars($connection->connect_error, ENT_QUOTES, 'UTF-8'));
}

$connection->set_charset('utf8mb4');
