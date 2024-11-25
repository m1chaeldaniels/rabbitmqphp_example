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

function sendResponse($channel, $queue, $success, $message, $companies = []) {
    $response = [
        'success' => $success,
        'message' => $message,
        'companies' => $companies,
    ];

    $jsonResponse = json_encode($response, JSON_UNESCAPED_SLASHES);

    if (json_last_error() !== JSON_ERROR_NONE) {
        $jsonError = json_last_error_msg();
        echo "JSON Encoding Error: $jsonError\n";
        echo "Response Data: " . print_r($response, true) . "\n";
        return;
    }

    echo "Sending JSON Response: $jsonResponse\n";

    $msg = new AMQPMessage($jsonResponse);
    $channel->basic_publish($msg, '', $queue);
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

    $queueCompanySearch = 'companySearch';
    $queueResponseCompanySearch = 'responseCompanySearch';

    $channel->queue_declare($queueCompanySearch, false, false, false, false);
    $channel->queue_declare($queueResponseCompanySearch, false, false, false, false);

    echo " [*] Waiting for company search messages. To exit press CTRL+C\n";

    $callback = function ($msg) use ($channel, $queueResponseCompanySearch) {
        echo ' [x] Received ', $msg->getBody(), "\n";

        $data = json_decode($msg->getBody(), true);

        if (json_last_error() !== JSON_ERROR_NONE || !isset($data['companyName'])) {
            echo "Error decoding JSON or missing 'companyName' field\n";
            sendResponse($channel, $queueResponseCompanySearch, false, "Invalid message format");
            return;
        }

        $searchTerm = $data['companyName'];

        $mysqli = new mysqli('localhost', 'testUser', '12345', 'testdb');

        if ($mysqli->connect_error) {
            echo "Database connection failed: " . $mysqli->connect_error . "\n";
            sendResponse($channel, $queueResponseCompanySearch, false, "Database connection failed");
            return;
        }

        $sql = "SELECT * FROM company_info WHERE name LIKE CONCAT('%', ?, '%')";
        $stmt = $mysqli->prepare($sql);

        if ($stmt === false) {
            echo "Error preparing statement: " . $mysqli->error . "\n";
            sendResponse($channel, $queueResponseCompanySearch, false, "Error preparing statement");
            $mysqli->close();
            return;
        }

        $stmt->bind_param("s", $searchTerm);
        $stmt->execute();
        $result = $stmt->get_result();

        $companies = [];

        while ($row = $result->fetch_assoc()) {
            $companies[] = $row;
        }

        $stmt->close();
        $mysqli->close();

        if (!empty($companies)) {
            sendResponse($channel, $queueResponseCompanySearch, true, "Matching companies found", $companies);
        } else {
            sendResponse($channel, $queueResponseCompanySearch, false, "No matching companies found");
        }
    };

    $channel->basic_consume($queueCompanySearch, '', false, true, false, false, $callback);

    while ($channel->is_consuming()) {
        $channel->wait();
    }

    $channel->close();
    $connection->close();

} catch (\Throwable $exception) {
    echo "Error: " . $exception->getMessage() . "\n";
}
