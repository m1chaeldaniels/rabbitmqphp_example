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

function sendMessage($channel, $queueResponse, $success, $message, $username, $password, $jobTitle, $location, $email) {
    $response = [
        'success' => $success,
        'message' => $message,
        'username' => $username,
        'password' => $password,
        'jobTitle' => $jobTitle,
        'location' => $location,
        'email' => $email,
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

    $queueLogin = 'login';
    $queueResponse = 'responseLogin';

    $channel->queue_declare($queueLogin, false, false, false, false);
    $channel->queue_declare($queueResponse, false, false, false, false);

    echo " [*] Waiting for messages on '$queueLogin'. To exit press CTRL+C\n";

    $callback = function ($msg) use ($channel, $queueResponse) {
        $data = json_decode($msg->body, true);

        if (!isset($data['username'])) {
            echo "Invalid message format\n";
            sendMessage($channel, $queueResponse, false, "Invalid message format", null, null, null, null, null);
            return;
        }

        $username = $data['username'];
        echo ' [x] Received ', $msg->body, "\n";

        $mysqli = new mysqli('localhost', 'testUser', '12345', 'testdb');

        if ($mysqli->connect_error) {
            die("Connection failed: " . $mysqli->connect_error);
        }

        $stmt = $mysqli->prepare("SELECT id, password_hash, email FROM users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $stmt->bind_result($userId, $hashedPassword, $email);
        $stmt->fetch();
        $stmt->close();

        if ($userId) {
            $stmt = $mysqli->prepare("SELECT jobTitle, location FROM user_preferences WHERE user_id = ?");
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $stmt->bind_result($jobTitle, $location);
            $stmt->fetch();
            $stmt->close();

            sendMessage($channel, $queueResponse, true, 'User Found', $username, $hashedPassword, $jobTitle, $location, $email);
            echo "Sent data for user: $username \n";

        } else {
            echo "User $username does not exist \n";
            sendMessage($channel, $queueResponse, false, 'User Does Not Exist', $username, 'NA', null, null, null);
        }

        $mysqli->close();
    };

    $channel->basic_consume($queueLogin, '', false, true, false, false, $callback);

    while ($channel->is_consuming()) {
        $channel->wait();
    }

    $channel->close();
    $connection->close();

} catch (\Throwable $exception) {
    echo "Error: " . $exception->getMessage() . "\n";
}
