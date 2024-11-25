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

function sendMessage($channel, $queue, $success, $message) {
    $response = [
        'success' => $success,
        'message' => $message
    ];
    echo "Sending response: " . json_encode($response) . "\n";
    $msg = new AMQPMessage(json_encode($response, JSON_UNESCAPED_SLASHES));
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

    $queueLogout = 'logoutQueue';
    $queueResponseLogout = 'responseLogout';

    $channel->queue_declare($queueLogout, false, false, false, false);
    $channel->queue_declare($queueResponseLogout, false, false, false, false);

    echo " [*] Waiting for logout messages. To exit press CTRL+C\n";

    $callback = function ($msg) use ($channel, $queueResponseLogout) {
        $data = json_decode($msg->body, true);

        if (!isset($data['session_token'])) {
            echo "Invalid message format\n";
            sendMessage($channel, $queueResponseLogout, false, 'Invalid message format');
            return;
        }

        $sessionToken = $data['session_token'];

        $mysqli = new mysqli('localhost', 'testUser', '12345', 'testdb');
        if ($mysqli->connect_error) {
            echo "Database connection failed: " . $mysqli->connect_error . "\n";
            sendMessage($channel, $queueResponseLogout, false, 'Database connection failed');
            return;
        }

        $stmt = $mysqli->prepare("UPDATE users SET session_token = NULL, token_expiry = NULL WHERE session_token = ?");
        $stmt->bind_param("s", $sessionToken);

        if ($stmt->execute()) {
            echo "Logout successful for session token: $sessionToken\n";
            sendMessage($channel, $queueResponseLogout, true, 'Logout successful');
        } else {
            echo "Failed to log out for session token: $sessionToken\n";
            sendMessage($channel, $queueResponseLogout, false, 'Failed to log out');
        }

        $stmt->close();
        $mysqli->close();
    };

    $channel->basic_consume($queueLogout, '', false, true, false, false, $callback);

    while ($channel->is_consuming()) {
        $channel->wait();
    }

    $channel->close();
    $connection->close();

} catch (\Throwable $exception) {
    echo "Error: " . $exception->getMessage() . "\n";
}
