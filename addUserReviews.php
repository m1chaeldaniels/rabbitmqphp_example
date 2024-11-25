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
        'data' => $data,
    ];

    echo "Sending response to frontend:\n";
    print_r($response);

    $msg = new AMQPMessage(json_encode($response, JSON_UNESCAPED_SLASHES));
    $channel->basic_publish($msg, '', $queue);
}

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

    $queueSubmitReview = 'submitReview';
    $queueResponseSubmitReview = 'responseSubmitReview';

    $channel->queue_declare($queueSubmitReview, false, false, false, false);
    $channel->queue_declare($queueResponseSubmitReview, false, false, false, false);

    echo " [*] Waiting for user review messages. To exit press CTRL+C\n";

    $callback = function ($msg) use ($channel, $queueResponseSubmitReview) {
        echo ' [x] Received ', $msg->getBody(), "\n";

        $data = json_decode($msg->getBody(), true);

        if (json_last_error() !== JSON_ERROR_NONE || !isset($data['username'], $data['companyName'], $data['rating'], $data['reviewText'])) {
            echo "Error decoding JSON or missing required fields\n";
            sendResponse($channel, $queueResponseSubmitReview, false, "Invalid message format");
            return;
        }

        $username = $data['username'];
        $companyName = $data['companyName'];
        $rating = (int)$data['rating'];
        $reviewText = $data['reviewText'];

        if ($rating < 1 || $rating > 5) {
            echo "Invalid rating value\n";
            sendResponse($channel, $queueResponseSubmitReview, false, "Rating must be between 1 and 5");
            return;
        }

        $mysqli = new mysqli('localhost', 'testUser', '12345', 'testdb');

        if ($mysqli->connect_error) {
            echo "Database connection failed: " . $mysqli->connect_error . "\n";
            sendResponse($channel, $queueResponseSubmitReview, false, "Database connection failed");
            return;
        }

        $user_id = getUserID($mysqli, $username);
        if (!$user_id) {
            echo "User not found for username: $username\n";
            sendResponse($channel, $queueResponseSubmitReview, false, "User not found");
            $mysqli->close();
            return;
        }

        $company_id = getCompanyID($mysqli, $companyName);
        if (!$company_id) {
            echo "Company not found for name: $companyName\n";
            sendResponse($channel, $queueResponseSubmitReview, false, "Company not found");
            $mysqli->close();
            return;
        }

        $existingReview = checkExistingReview($mysqli, $user_id, $company_id);
        if ($existingReview) {
            echo "Review already exists for user ID: $user_id and company ID: $company_id\n";
            sendResponse($channel, $queueResponseSubmitReview, false, "Review already exists for this company");
            $mysqli->close();
            return;
        }

        $insertSql = "INSERT INTO user_reviews (user_id, company_id, company_name, rating, review_text) VALUES (?, ?, ?, ?, ?)";
        $stmt = $mysqli->prepare($insertSql);
        $stmt->bind_param("iisis", $user_id, $company_id, $companyName, $rating, $reviewText);

        if ($stmt->execute()) {
            echo "Review added by user ID: $user_id for company ID: $company_id with company name: $companyName\n";
            sendResponse($channel, $queueResponseSubmitReview, true, "Review added successfully");
        } else {
            echo "Failed to add review\n";
            sendResponse($channel, $queueResponseSubmitReview, false, "Failed to add review");
        }

        $stmt->close();
        $mysqli->close();
    };

    $channel->basic_consume($queueSubmitReview, '', false, true, false, false, $callback);

    while ($channel->is_consuming()) {
        $channel->wait();
    }

    $channel->close();
    $connection->close();

} catch (\Throwable $exception) {
    echo "Error: " . $exception->getMessage() . "\n";
}
