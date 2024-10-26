<?php

require_once __DIR__ . '/vendor/autoload.php';
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

$connection = new AMQPStreamConnection('172.29.85.9', 5672, 'test', 'test', 'Sql-Post');
$channel = $connection->channel();

// LOGIN QUEUES
$channel->queue_declare('login', false, false, false, false);
$channel->queue_declare('responseLogin', false, false, false, false);

echo " [*] Waiting for messages. To exit press CTRL+C\n";

$callback = function ($msg) use ($channel){
    $data = json_decode($msg->body, true);

    if (!isset($data['username'])) {
        echo "Invalid message format\n";
        sendMessage($channel, false, "Invalid message format", null, null, null, null, null);
        return;
    }

    $username = $data['username'];
    echo ' [x] Received ', $msg->getBody(), "\n";

    $mysqli = new mysqli('localhost', 'testUser', '12345', 'testdb');

    if ($mysqli->connect_error) {
        die("Connection failed: " . $mysqli->connect_error);
    }

    // Fetch user data from the users table
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

        sendMessage($channel, true, 'User Found', $username, $hashedPassword, $jobTitle, $location, $email);
        echo "Sent data for user: $username \n";

    } else {
        echo "User $username does not exist \n";
        sendMessage($channel, false, 'User Does Not Exist', $username, 'NA', null, null, null);
    }

    $mysqli->close();
};

function sendMessage($channel, $success, $message, $username, $password, $jobTitle, $location, $email) {
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
    $channel->basic_publish($msg, '', 'responseLogin');
}

$channel->basic_consume('login', '', false, true, false, false, $callback);

try {
    $channel->consume();
} catch (\Throwable $exception) {
    echo $exception->getMessage();
}

$channel->close();
$connection->close();
