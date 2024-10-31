<?php

require_once __DIR__ . '/vendor/autoload.php';
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

$connection = new AMQPStreamConnection('172.29.85.9', 5672, 'test', 'test', 'Sql-Post');
$channel = $connection->channel();

$channel->queue_declare('webMsg', false, false, false, false);
$channel->queue_declare('responseRegister', false, false, false, false);

echo " [*] Waiting for messages. To exit press CTRL+C\n";

$callback = function ($msg) use ($channel){
    $data = json_decode($msg->body, true);

    if (!isset($data['username'], $data['password'], $data['email'], $data['jobTitle'], $data['location'])) {
        echo "Invalid message format\n";
        sendMessage($channel, false, 'Invalid message format');
        return;
    }

    $username = $data['username'];
    $password = $data['password'];
    $email = $data['email'];
    $jobTitle = $data['jobTitle'];
    $location = $data['location'];

    echo ' [x] Received ', $msg->getBody(), "\n";

    $mysqli = new mysqli('localhost', 'testUser', '12345', 'testdb');

    if ($mysqli->connect_error) {
        die("Connection failed: " . $mysqli->connect_error);
    }

    $stmt = $mysqli->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();

    if ($count === 0) {
        $stmt = $mysqli->prepare("INSERT INTO users(username, password_hash, email) VALUES (?, ?, ?)");
        $stmt->bind_param("sss", $username, $password, $email);

        if ($stmt->execute()) {
            $userId = $stmt->insert_id;
            $stmt->close();

            $stmt = $mysqli->prepare("INSERT INTO user_preferences(user_id, jobTitle, location) VALUES (?, ?, ?)");
            $stmt->bind_param("iss", $userId, $jobTitle, $location);

            if ($stmt->execute()) {
                echo "Inserted user and preferences: $username \n";
                sendMessage($channel, true, 'User registered successfully: ' . $username);
                echo "Sent registration confirmation: $username \n";
            } else {
                echo "Failed to insert preferences for user: " . $stmt->error . "\n";
                sendMessage($channel, false, 'Error inserting preferences for user: ' . $stmt->error);
                echo "Sent preference insertion failure: $username \n";
            }

            $stmt->close();
        } else {
            echo "Failed to insert user: " . $stmt->error . "\n";
            sendMessage($channel, false, 'Error inserting user: ' . $stmt->error);
            echo "Sent registration failure: $username \n";
        }

    } else {
        echo "User $username or email $email already exists \n";
        sendMessage($channel, false, 'User or email already exists: ' . $username);
        echo "Sent user already exists: $username \n";
    }

    $mysqli->close();
};

function sendMessage($channel, $success, $message) {
    $response = [
        'success' => $success,
        'message' => $message,
    ];
    $msg = new AMQPMessage(json_encode($response));
    $channel->basic_publish($msg, '', 'responseRegister');
}

$channel->basic_consume('webMsg', '', false, true, false, false, $callback);

try {
    $channel->consume();
} catch (\Throwable $exception) {
    echo $exception->getMessage();
}

$channel->close();
$connection->close();