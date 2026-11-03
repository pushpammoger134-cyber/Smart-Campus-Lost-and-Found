<?php
session_start();

$token = $_POST['csrf_token'] ?? '';

if (
    $_SERVER['REQUEST_METHOD'] !== 'POST' ||
    !is_string($token) ||
    !hash_equals($_SESSION['csrf_token'] ?? '', $token) ||
    $token === ''
) {
    http_response_code(403);
    exit('Invalid request. Return to the dashboard and try again.');
}

// Remove all information stored in this session.
$_SESSION = [];

// Remove the browser's session cookie.
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();

    setcookie(session_name(), '', [
        'expires' => time() - 42000,
        'path' => $params['path'],
        'domain' => $params['domain'],
        'secure' => $params['secure'],
        'httponly' => $params['httponly'],
        'samesite' => $params['samesite'] ?? 'Lax'
    ]);
}

session_destroy();

header('Location: login.php');
exit;