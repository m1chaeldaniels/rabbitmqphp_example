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

function sendResponse($channel, $queueResponse, $success, $message, $jobs = []) {
    $response = [
        'success' => $success,
        'message' => $message,
        'applied_jobs' => $jobs
    ];

    echo "Sending response to frontend:\n";
    print_r($response);

    $msg = new AMQPMessage(json_encode($response, JSON_UNESCAPED_SLASHES));
    $channel->basic_publish($msg, '', $queueResponse);
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

    $queueGetAppliedJobs = 'getAppliedJobs';
    $queueResponseGetAppliedJobs = 'responseGetAppliedJobs';

    $channel->queue_declare($queueGetAppliedJobs, false, false, false, false);
    $channel->queue_declare($queueResponseGetAppliedJobs, false, false, false, false);

    echo " [*] Waiting for get applied jobs requests. To exit press CTRL+C\n";

    $callback = function ($msg) use ($channel, $queueResponseGetAppliedJobs) {
        $data = json_decode($msg->body, true);

        if (!isset($data['username'])) {
            echo "Invalid message format\n";
            sendResponse($channel, $queueResponseGetAppliedJobs, false, "Invalid message format");
            return;
        }

        $username = $data['username'];
        echo " [x] Received request for applied jobs. Username: $username\n";

        $mysqli = new mysqli('localhost', 'testUser', '12345', 'testdb');

        if ($mysqli->connect_error) {
            echo "Database connection failed: " . $mysqli->connect_error . "\n";
            sendResponse($channel, $queueResponseGetAppliedJobs, false, "Database connection failed");
            return;
        }

        $stmt = $mysqli->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $stmt->bind_result($userId);
        $stmt->fetch();
        $stmt->close();

        if (!$userId) {
            echo "User not found for Username: $username\n";
            sendResponse($channel, $queueResponseGetAppliedJobs, false, "User not found");
            $mysqli->close();
            return;
        }

        $stmt = $mysqli->prepare("
            SELECT tj.id, tj.title, tj.company, tj.locations, tj.url, uaj.applied_at 
            FROM user_applied_jobs uaj
            JOIN total_jobs tj ON uaj.job_id = tj.id
            WHERE uaj.user_id = ?
        ");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();

        $appliedJobs = [];
        while ($row = $result->fetch_assoc()) {
            $appliedJobs[] = $row;
        }

        $stmt->close();
        $mysqli->close();

        if (!empty($appliedJobs)) {
            echo "Found " . count($appliedJobs) . " applied jobs for User ID: $userId\n";
            sendResponse($channel, $queueResponseGetAppliedJobs, true, "Applied jobs retrieved successfully", $appliedJobs);
        } else {
            echo "No applied jobs found for User ID: $userId\n";
            sendResponse($channel, $queueResponseGetAppliedJobs, false, "No applied jobs found", []);
        }
    };

    $channel->basic_consume($queueGetAppliedJobs, '', false, true, false, false, $callback);

    while ($channel->is_consuming()) {
        $channel->wait();
    }

    $channel->close();
    $connection->close();

} catch (\Throwable $exception) {
    echo "Error: " . $exception->getMessage() . "\n";
}
