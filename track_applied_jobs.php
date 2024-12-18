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

function sendResponse($channel, $queueResponse, $success, $message) {
    $response = [
        'success' => $success,
        'message' => $message
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

    $queueTrackAppliedJob = 'trackAppliedJob';
    $queueResponseTrackAppliedJob = 'responseTrackAppliedJob';

    $channel->queue_declare($queueTrackAppliedJob, false, false, false, false);
    $channel->queue_declare($queueResponseTrackAppliedJob, false, false, false, false);

    echo " [*] Waiting for job tracking requests. To exit press CTRL+C\n";

    $callback = function ($msg) use ($channel, $queueResponseTrackAppliedJob) {
        $data = json_decode($msg->body, true);

        if (!isset($data['username'], $data['id'])) {
            echo "Invalid message format\n";
            sendResponse($channel, $queueResponseTrackAppliedJob, false, "Invalid message format");
            return;
        }

        $username = $data['username'];
        $jobId = (int)$data['id'];

        echo " [x] Received tracking request for Username: $username, Job ID: $jobId\n";

        $mysqli = new mysqli('localhost', 'testUser', '12345', 'testdb');

        if ($mysqli->connect_error) {
            echo "Database connection failed: " . $mysqli->connect_error . "\n";
            sendResponse($channel, $queueResponseTrackAppliedJob, false, "Database connection failed");
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
            sendResponse($channel, $queueResponseTrackAppliedJob, false, "User not found");
            $mysqli->close();
            return;
        }

        // Get job ID
        $stmt = $mysqli->prepare("SELECT id FROM total_jobs WHERE id = ?");
        $stmt->bind_param("i", $jobId);
        $stmt->execute();
        $stmt->bind_result($existingJobId);
        $stmt->fetch();
        $stmt->close();

        if (!$existingJobId) {
            echo "Job not found for Job ID: $jobId\n";
            sendResponse($channel, $queueResponseTrackAppliedJob, false, "Job not found");
            $mysqli->close();
            return;
        }

        // Check if the user has already applied to the job
        $stmt = $mysqli->prepare("SELECT id FROM user_applied_jobs WHERE user_id = ? AND job_id = ?");
        $stmt->bind_param("ii", $userId, $jobId);
        $stmt->execute();
        $stmt->bind_result($appliedJobId);
        $stmt->fetch();
        $stmt->close();

        if ($appliedJobId) {
            echo "User has already applied for Job ID: $jobId\n";
            sendResponse($channel, $queueResponseTrackAppliedJob, false, "User has already applied for this job");
            $mysqli->close();
            return;
        }

        $stmt = $mysqli->prepare("INSERT INTO user_applied_jobs (user_id, job_id, applied_at) VALUES (?, ?, CURRENT_TIMESTAMP)");
        $stmt->bind_param("ii", $userId, $jobId);

        if ($stmt->execute()) {
            echo "Successfully tracked job application for User ID: $userId, Job ID: $jobId\n";
            sendResponse($channel, $queueResponseTrackAppliedJob, true, "Job marked as applied");
        } else {
            echo "Failed to track job application\n";
            sendResponse($channel, $queueResponseTrackAppliedJob, false, "Failed to mark job as applied");
        }

        $stmt->close();
        $mysqli->close();
    };

    $channel->basic_consume($queueTrackAppliedJob, '', false, true, false, false, $callback);

    while ($channel->is_consuming()) {
        $channel->wait();
    }

    $channel->close();
    $connection->close();

} catch (\Throwable $exception) {
    echo "Error: " . $exception->getMessage() . "\n";
}
