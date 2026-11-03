
<?php

// Example database configuration.
// Copy this file to db.php and update the settings.

$host = '127.0.0.1';
$database = 'campus_lost_found';
$username = 'YOUR_DATABASE_USERNAME';
$password = 'YOUR_DATABASE_PASSWORD';

try {
    $pdo = new PDO(
        "mysql:host=$host;port=3306;dbname=$database;charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
} catch (PDOException $error) {
    error_log($error->getMessage());

    http_response_code(500);

    exit(
        'Database connection failed. Check your settings.'
    );
}
