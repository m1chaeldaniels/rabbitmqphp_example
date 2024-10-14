<?php
require_once __DIR__ . '/vendor/autoload.php';
use Firebase\JWT\JWT;

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
header('Content-Type: application/json');

$secretKey = 'your-very-secret-key';

$headers = apache_request_headers();
$authHeader = isset($headers['Authorization']) ? $headers['Authorization'] : '';

if ($authHeader) {
    list($jwt) = sscanf($authHeader, 'Bearer %s');

    try {
        $decoded = JWT::decode($jwt, $secretKey, ['HS256']);
        echo json_encode(["status" => "success", "username" => "User"]); // Replace with decoded user info
    } catch (Exception $e) {
        echo json_encode(["status" => "error", "message" => "Invalid token"]);
    }
} else {
    echo json_encode(["status" => "error", "message" => "No token provided"]);
}
?>
