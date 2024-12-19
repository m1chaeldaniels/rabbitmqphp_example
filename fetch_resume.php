#!/usr/bin/php
<?php

require_once('vendor/autoload.php');
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

function getRabbitMQConfig() {
    $config = parse_ini_file("/etc/RabbitMQ.ini", true);
    if (!isset($config['rabbitMQ'])) {
        throw new Exception("RabbitMQ configuration for 'rabbitMQ' not found in INI file.");
    }
    return $config['rabbitMQ'];
}

function sendMessage($channel, $queueResponse, $success, $message, $username, $filename = null, $fileData = null) {
    $response = [
        'success' => $success,
        'message' => $message,
        'username' => $username,
        'filename' => $filename,
        'file_data' => $fileData
    ];
    $msg = new AMQPMessage(json_encode($response, JSON_UNESCAPED_SLASHES));
    $channel->basic_publish($msg, '', $queueResponse);
}

try {
    $config = getRabbitMQConfig();

    $host = $config['host'];
    $port = $config['port'];
    $username = $config['username'];
    $password = $config['password'];
    $vhost = $config['vhost'];

    $connection = new AMQPStreamConnection($host, $port, $username, $password, $vhost);
    $channel = $connection->channel();

    $queueFetchResume = 'fetchResume';
    $queueResponse = 'responseFetchResume';

    $channel->queue_declare($queueFetchResume, false, false, false, false);
    $channel->queue_declare($queueResponse, false, false, false, false);

    echo " [*] Waiting for messages on '$queueFetchResume'. To exit press CTRL+C\n";

    $callback = function ($msg) use ($channel, $queueResponse) {
        $data = json_decode($msg->body, true);

        if (!isset($data['username'])) {
            echo "Invalid message format\n";
            sendMessage($channel, $queueResponse, false, "Invalid message format", null);
            return;
        }

        $username = $data['username'];
        echo ' [x] Received ', $msg->body, "\n";

        $mysqli = new mysqli('localhost', 'testUser', '12345', 'testdb');

        if ($mysqli->connect_error) {
            die("Connection failed: " . $mysqli->connect_error);
        }

        // Fetch user ID based on username
        $stmt = $mysqli->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $stmt->bind_result($userId);
        $stmt->fetch();
        $stmt->close();

        if (!$userId) {
            echo "User $username does not exist \n";
            sendMessage($channel, $queueResponse, false, "User Does Not Exist", $username);
            $mysqli->close();
            return;
        }

        $stmt = $mysqli->prepare("SELECT filename, file_data FROM user_resumes WHERE user_id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $stmt->bind_result($filename, $fileData);
        $stmt->fetch();
        $stmt->close();

        if ($filename && $fileData) {
            $encodedFileData = base64_encode($fileData);
            sendMessage($channel, $queueResponse, true, "Resume Found", $username, $filename, $encodedFileData);
            echo "Resume sent for user: $username \n";
        } else {
            echo "No resume found for user: $username \n";
            sendMessage($channel, $queueResponse, false, "No Resume Found", $username);
        }

        $mysqli->close();
    };

    $channel->basic_consume($queueFetchResume, '', false, true, false, false, $callback);

    while ($channel->is_consuming()) {
        $channel->wait();
    }

    $channel->close();
    $connection->close();

} catch (\Throwable $exception) {
    echo "Error: " . $exception->getMessage() . "\n";
}
