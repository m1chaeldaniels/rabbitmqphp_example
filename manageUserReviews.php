<?php

require_once __DIR__ . '/vendor/autoload.php';
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

$connection = new AMQPStreamConnection('172.29.85.9', 5672, 'test', 'test', 'Sql-Post');
$channel = $connection->channel();

$channel->queue_declare('manageUserReviews', false, false, false, false);
$channel->queue_declare('responseManageUserReviews', false, false, false, false);

echo " [*] Waiting for company review search messages. To exit press CTRL+C\n";

$callback = function ($msg) use ($channel) {
    echo ' [x] Received ', $msg->getBody(), "\n";

    $data = json_decode($msg->getBody(), true);

    if (json_last_error() !== JSON_ERROR_NONE || !isset($data['companyName'])) {
        echo "Error decoding JSON or missing 'companyName' field\n";
        sendResponse($channel, false, "Invalid message format");
        return;
    }

    $companyName = $data['companyName'];

    $mysqli = new mysqli('localhost', 'testUser', '12345', 'testdb');

    if ($mysqli->connect_error) {
        echo "Database connection failed: " . $mysqli->connect_error . "\n";
        sendResponse($channel, false, "Database connection failed");
        return;
    }

    $company_id = getCompanyID($mysqli, $companyName);
    if (!$company_id) {
        echo "Company not found for name: $companyName\n";
        sendResponse($channel, false, "Company not found");
        $mysqli->close();
        return;
    }

    $companyDetails = getCompanyDetails($mysqli, $company_id);

    $sql = "
        SELECT ur.rating, ur.review_text, u.username
        FROM user_reviews ur
        JOIN users u ON ur.user_id = u.id
        WHERE ur.company_id = ?
    ";
    $stmt = $mysqli->prepare($sql);

    if ($stmt === false) {
        echo "Error preparing statement: " . $mysqli->error . "\n";
        sendResponse($channel, false, "Error preparing statement");
        $mysqli->close();
        return;
    }

    $stmt->bind_param("i", $company_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $reviews = [];

    while ($row = $result->fetch_assoc()) {
        $reviews[] = $row;
    }

    $stmt->close();
    $mysqli->close();

    $responseData = [
        'company_name' => $companyDetails['name'] ?? $companyName,
        'reviews' => $reviews
    ];

    if (!empty($reviews)) {
        sendResponse($channel, true, "Matching reviews found", $responseData);
    } else {
         sendResponse($channel, false, "No reviews found for this company", $responseData);
    }
};

function getCompanyID($mysqli, $company_name) {
    $sql = "SELECT id FROM company_info WHERE name LIKE CONCAT('%', ?, '%') COLLATE utf8mb4_general_ci";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("s", $company_name);
    $stmt->execute();
    $stmt->bind_result($company_id);
    $stmt->fetch();
    $stmt->close();

    return $company_id ?? null;
}

function getCompanyDetails($mysqli, $company_id) {
    $sql = "SELECT name FROM company_info WHERE id = ?";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("i", $company_id);
    $stmt->execute();
    $stmt->bind_result($company_name);
    $stmt->fetch();
    $stmt->close();

    return ['name' => $company_name];
}

function sendResponse($channel, $success, $message, $data = []) {
    $response = [
        'success' => $success,
        'message' => $message,
        'companies' => $data,
    ];

    $msg = new AMQPMessage(json_encode($response, JSON_UNESCAPED_SLASHES));
    $channel->basic_publish($msg, '', 'responseManageUserReviews');
}

$channel->basic_consume('manageUserReviews', '', false, true, false, false, $callback);

try {
    $channel->consume();
} catch (\Throwable $exception) {
    echo $exception->getMessage();
}

$channel->close();
$connection->close();

?>
