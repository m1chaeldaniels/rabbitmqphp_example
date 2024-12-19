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

    $msg = new AMQPMessage(json_encode($response));
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

    $channel->queue_declare('webMsg', false, false, false, false);
    $channel->queue_declare('responseRegister', false, false, false, false);

    echo " [*] Waiting for messages. To exit press CTRL+C\n";

    $callback = function ($msg) use ($channel) {
        $data = json_decode($msg->body, true);

        if (!isset($data['username'], $data['password'], $data['email'], $data['jobTitle'], $data['location'], $data['phone'])) {
            echo "Invalid message format\n";
            sendMessage($channel, 'responseRegister', false, 'Invalid message format');
            return;
        }

        $username = $data['username'];
        $password = $data['password'];
        $email = $data['email'];
        $jobTitle = $data['jobTitle'];
        $location = $data['location'];
        $phoneNumber = $data['phone'];

        echo " [x] Received ", $msg->getBody(), "\n";

        $mysqli = new mysqli('localhost', 'testUser', '12345', 'testdb');
        if ($mysqli->connect_error) {
            echo "Database connection failed: " . $mysqli->connect_error . "\n";
            sendMessage($channel, 'responseRegister', false, 'Database connection error');
            return;
        }

        $stmt = $mysqli->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
        $stmt->bind_param("ss", $username, $email);
        $stmt->execute();
        $stmt->bind_result($existingUserId);
        $stmt->fetch();
        $stmt->close();

        if ($existingUserId) {
            echo "User $username or email $email already exists \n";
            sendMessage($channel, 'responseRegister', false, 'User or email already exists');
        } else {
            $stmt = $mysqli->prepare("INSERT INTO users (username, password_hash, email, phone_number) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("ssss", $username, $password, $email, $phoneNumber);
            if ($stmt->execute()) {
                $userId = $stmt->insert_id;
                $stmt->close();

                $stmt = $mysqli->prepare("INSERT INTO user_preferences (user_id, jobTitle, location) VALUES (?, ?, ?)");
                $stmt->bind_param("iss", $userId, $jobTitle, $location);
                if ($stmt->execute()) {
                    echo "Inserted user and preferences: $username \n";
                    sendMessage($channel, 'responseRegister', true, 'User registered successfully', ['username' => $username]);
                } else {
                    echo "Failed to insert preferences for $username: " . $stmt->error . "\n";
                    sendMessage($channel, 'responseRegister', false, 'Error saving user preferences');
                }
                $stmt->close();
            } else {
                echo "Failed to register user $username: " . $stmt->error . "\n";
                sendMessage($channel, 'responseRegister', false, 'Error registering user');
            }
        }

        $mysqli->close();
    };

    $channel->basic_consume('webMsg', '', false, true, false, false, $callback);

    while ($channel->is_consuming()) {
        $channel->wait();
    }

    $channel->close();
    $connection->close();

} catch (\Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
