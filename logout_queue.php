<?php

require_once __DIR__ . '/vendor/autoload.php';
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

$connection = new AMQPStreamConnection('172.29.85.9', 5672, 'test', 'test', 'Sql-Post');
$channel = $connection->channel();

$channel->queue_declare('logoutQueue', false, false, false, false);
$channel->queue_declare('responseLogout', false, false, false, false);

echo " [*] Waiting for logout messages. To exit press CTRL+C\n";

$callback = function ($msg) use ($channel) {
    $data = json_decode($msg->body, true);

    // grabs the session token from the message received
    if (!isset($data['session_token'])) {
        sendMessage($channel, false, 'Invalid message format');
        return;
    }

    $sessionToken = $data['session_token'];

    $mysqli = new mysqli('localhost', 'testUser', '12345', 'testdb');

    if ($mysqli->connect_error) {
        sendMessage($channel, false, 'Database connection failed');
        return;
    }


    // makes both the token and its expire time null
    $stmt = $mysqli->prepare("UPDATE users SET session_token = NULL, token_expiry = NULL WHERE session_token = ?");
    $stmt->bind_param("s", $sessionToken);

    if ($stmt->execute()) {
        sendMessage($channel, true, 'Logout successful');
    } else {
        sendMessage($channel, false, 'Failed to log out');
    }

    $stmt->close();
    $mysqli->close();
};

function sendMessage($channel, $success, $message) {
    $response = [
        'success' => $success,
        'message' => $message
    ];
    $msg = new AMQPMessage(json_encode($response, JSON_UNESCAPED_SLASHES));
    $channel->basic_publish($msg, '', 'responseLogout');
}

$channel->basic_consume('logoutQueue', '', false, true, false, false, $callback);

while ($channel->is_consuming()) {
    $channel->wait();
}

$channel->close();
$connection->close();

?>