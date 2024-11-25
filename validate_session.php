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

function sendMessage($channel, $queue, $success, $message, $additionalData = []) {
    $response = array_merge([
        'success' => $success,
        'message' => $message,
    ], $additionalData);

    echo " [>] Sending response: ", json_encode($response), "\n";
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

    $channel->queue_declare('validateSession', false, false, false, false);
    $channel->queue_declare('responseValidateSession', false, false, false, false);

    echo " [*] Waiting for session validation requests. To exit press CTRL+C\n";

    $callback = function ($msg) use ($channel) {
        echo " [x] Received message: ", $msg->getBody(), "\n";
        $data = json_decode($msg->body, true);

        if (!isset($data['session_token'])) {
            echo " [!] Invalid message format: Missing session_token\n";
            sendMessage($channel, 'responseValidateSession', false, 'Invalid message format for session validation');
            return;
        }

        $sessionToken = $data['session_token'];
        echo " [*] Session token received: $sessionToken\n";

        $mysqli = new mysqli('localhost', 'testUser', '12345', 'testdb');
        if ($mysqli->connect_error) {
            echo " [!] Database connection failed: " . $mysqli->connect_error . "\n";
            sendMessage($channel, 'responseValidateSession', false, 'Database connection failed');
            return;
        }

        $stmt = $mysqli->prepare("SELECT token_expiry FROM users WHERE session_token = ?");
        if (!$stmt) {
            echo " [!] Failed to prepare SQL statement\n";
            sendMessage($channel, 'responseValidateSession', false, 'Failed to prepare SQL statement');
            $mysqli->close();
            return;
        }

        $stmt->bind_param("s", $sessionToken);
        $stmt->execute();
        $stmt->bind_result($tokenExpiry);
        $stmt->fetch();
        $stmt->close();

        if ($tokenExpiry) {
            echo " [*] Token expiry found: $tokenExpiry\n";
        } else {
            echo " [!] No matching session token found in the database\n";
            sendMessage($channel, 'responseValidateSession', false, 'Invalid session token');
            $mysqli->close();
            return;
        }

        if ($tokenExpiry > time()) {
            echo " [*] Session is active\n";
            sendMessage($channel, 'responseValidateSession', true, 'Session is active', ['expiry' => $tokenExpiry]);
        } else {
            echo " [!] Session has expired\n";
            sendMessage($channel, 'responseValidateSession', false, 'Session has expired');
        }

        $mysqli->close();
        echo " [*] Database connection closed\n";
    };

    $channel->basic_consume('validateSession', '', false, true, false, false, $callback);

    while ($channel->is_consuming()) {
        $channel->wait();
    }

    $channel->close();
    $connection->close();

} catch (\Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
