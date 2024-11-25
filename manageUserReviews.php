#!/usr/bin/php
<?php

require_once __DIR__ . '/vendor/autoload.php';
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

function getRabbitMQConfig() {
    $config = parse_ini_file("/etc/RabbitMQ.ini", true);
    if (!isset($config['rabbitMQ'])) {
        throw new Exception("RabbitMQ configuration for 'rabbitMQ' not found in INI file.");
    }
    return $config['rabbitMQ'];
}

function sendResponse($channel, $queue, $success, $message, $data = []) {
    $response = [
        'success' => $success,
        'message' => $message,
        'companies' => $data,
    ];

    $jsonResponse = json_encode($response, JSON_UNESCAPED_SLASHES);

    if (json_last_error() !== JSON_ERROR_NONE) {
        echo "JSON Encoding Error: " . json_last_error_msg() . "\n";
        echo "Response Data: " . print_r($response, true) . "\n";
        return;
    }

    echo "Sending JSON Response: $jsonResponse\n";

    $msg = new AMQPMessage($jsonResponse);
    $channel->basic_publish($msg, '', $queue);
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

try {
    $config = getRabbitMQConfig();

    $connection = new AMQPStreamConnection(
        $config['host'],
        $config['port'],
        $config['username'],
        $config['password'],
        $config['vhost']
    );
    $channel = $connection->channel();

    $queueManageUserReviews = 'manageUserReviews';
    $queueResponseManageUserReviews = 'responseManageUserReviews';

    $channel->queue_declare($queueManageUserReviews, false, false, false, false);
    $channel->queue_declare($queueResponseManageUserReviews, false, false, false, false);

    echo " [*] Waiting for company review search messages. To exit press CTRL+C\n";

    $callback = function ($msg) use ($channel, $queueResponseManageUserReviews) {
        echo ' [x] Received ', $msg->getBody(), "\n";

        $data = json_decode($msg->getBody(), true);

        if (json_last_error() !== JSON_ERROR_NONE || !isset($data['companyName'])) {
            echo "Error decoding JSON or missing 'companyName' field\n";
            sendResponse($channel, $queueResponseManageUserReviews, false, "Invalid message format");
            return;
        }

        $companyName = $data['companyName'];

        $mysqli = new mysqli('localhost', 'testUser', '12345', 'testdb');

        if ($mysqli->connect_error) {
            echo "Database connection failed: " . $mysqli->connect_error . "\n";
            sendResponse($channel, $queueResponseManageUserReviews, false, "Database connection failed");
            return;
        }

        $company_id = getCompanyID($mysqli, $companyName);
        if (!$company_id) {
            echo "Company not found for name: $companyName\n";
            sendResponse($channel, $queueResponseManageUserReviews, false, "Company not found");
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
            sendResponse($channel, $queueResponseManageUserReviews, false, "Error preparing statement");
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
            sendResponse($channel, $queueResponseManageUserReviews, true, "Matching reviews found", $responseData);
        } else {
            sendResponse($channel, $queueResponseManageUserReviews, false, "No reviews found for this company", $responseData);
        }
    };

    $channel->basic_consume($queueManageUserReviews, '', false, true, false, false, $callback);

    while ($channel->is_consuming()) {
        $channel->wait();
    }

    $channel->close();
    $connection->close();

} catch (\Throwable $exception) {
    echo "Error: " . $exception->getMessage() . "\n";
}
