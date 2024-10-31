<?php

require_once __DIR__ . '/vendor/autoload.php';
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

$connection = new AMQPStreamConnection('172.29.85.9', 5672, 'test', 'test', 'Sql-Post');
$channel = $connection->channel();

$channel->queue_declare('submitReview', false, false, false, false);
$channel->queue_declare('responseSubmitReview', false, false, false, false);

echo " [*] Waiting for user review messages. To exit press CTRL+C\n";

$callback = function ($msg) use ($channel) {
    echo ' [x] Received ', $msg->getBody(), "\n";

    $data = json_decode($msg->getBody(), true);

    if (json_last_error() !== JSON_ERROR_NONE || !isset($data['username'], $data['companyName'], $data['rating'], $data['reviewText'])) {
        echo "Error decoding JSON or missing required fields\n";
        sendResponse($channel, false, "Invalid message format");
        return;
    }

    $username = $data['username'];
    $companyName = $data['companyName'];
    $rating = (int)$data['rating'];
    $reviewText = $data['reviewText'];

    if ($rating < 1 || $rating > 5) {
        echo "Invalid rating value\n";
        sendResponse($channel, false, "Rating must be between 1 and 5");
        return;
    }

    $mysqli = new mysqli('localhost', 'testUser', '12345', 'testdb');

    if ($mysqli->connect_error) {
        echo "Database connection failed: " . $mysqli->connect_error . "\n";
        sendResponse($channel, false, "Database connection failed");
        return;
    }

    $user_id = getUserID($mysqli, $username);
    if (!$user_id) {
        echo "User not found for username: $username\n";
        sendResponse($channel, false, "User not found");
        $mysqli->close();
        return;
    }

    $company_id = getCompanyID($mysqli, $companyName);
    if (!$company_id) {
        echo "Company not found for name: $companyName\n";
        sendResponse($channel, false, "Company not found");
        $mysqli->close();
        return;
    }

    $existingReview = checkExistingReview($mysqli, $user_id, $company_id);
    if ($existingReview) {
        echo "Review already exists for user ID: $user_id and company ID: $company_id\n";
        sendResponse($channel, false, "Review already exists for this company");
        $mysqli->close();
        return;
    }

    $insertSql = "INSERT INTO user_reviews (user_id, company_id, company_name, rating, review_text) VALUES (?, ?, ?, ?, ?)";
    $stmt = $mysqli->prepare($insertSql);
    $stmt->bind_param("iisis", $user_id, $company_id, $companyName, $rating, $reviewText);

    if ($stmt->execute()) {
        echo "Review added by user ID: $user_id for company ID: $company_id with company name: $companyName\n";
        sendResponse($channel, true, "Review added successfully");
    } else {
        echo "Failed to add review\n";
        sendResponse($channel, false, "Failed to add review");
    }

    $stmt->close();
    $mysqli->close();
};

function getUserID($mysqli, $username) {
    $sql = "SELECT id FROM users WHERE username = ?";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $stmt->bind_result($user_id);
    $stmt->fetch();
    $stmt->close();

    return $user_id ?? null;
}

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

function checkExistingReview($mysqli, $user_id, $company_id) {
    $sql = "SELECT id FROM user_reviews WHERE user_id = ? AND company_id = ?";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("ii", $user_id, $company_id);
    $stmt->execute();
    $stmt->store_result();

    $exists = $stmt->num_rows > 0;
    $stmt->close();

    return $exists;
}

function sendResponse($channel, $success, $message, $data = []) {
    $response = [
        'success' => $success,
        'message' => $message,
        'data' => $data,
    ];

    echo "Sending response to frontend:\n";
    print_r($response);

    $msg = new AMQPMessage(json_encode($response, JSON_UNESCAPED_SLASHES));
    $channel->basic_publish($msg, '', 'responseSubmitReview');
}

$channel->basic_consume('submitReview', '', false, true, false, false, $callback);

try {
    $channel->consume();
} catch (\Throwable $exception) {
    echo $exception->getMessage();
}

$channel->close();
$connection->close();

?>
