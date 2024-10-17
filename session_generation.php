<?php

require_once __DIR__ . '/vendor/autoload.php';
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

$connection = new AMQPStreamConnection('172.29.85.9', 5672, 'test', 'test', 'Sql-Post');
$channel = $connection->channel();

$channel->queue_declare('sessionTokenQueue', false, false, false, false);
$channel->queue_declare('responseSessionToken', false, false, false, false);

echo " [*] Waiting for session token messages. To exit press CTRL+C\n";

$callback = function ($msg) use ($channel){
    $data = json_decode($msg->body, true);

    // grabs both the username and the session tok
    if (!isset($data['username'], $data['session_token'], $data['token_expiry'])) {
        sendMessage($channel, false, 'Invalid message format');
        return;
    }

    $username = $data['username'];
    $sessionToken = $data['session_token'];
    $tokenExpiry = $data['token_expiry'];

    $mysqli = new mysqli('localhost', 'testUser', '12345', 'testdb');

    if ($mysqli->connect_error) {
        sendMessage($channel, false, 'Database connection failed');
        return;
    }

    // update the users portion with the new token and expire time
    $stmt = $mysqli->prepare("UPDATE users SET session_token = ?, token_expiry = ? WHERE username = ?");
    $stmt->bind_param("sis", $sessionToken, $tokenExpiry, $username);

    if ($stmt->execute()) {
        sendMessage($channel, true, 'Session token stored successfully');
    } else {
        sendMessage($channel, false, 'Failed to store session token');
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
    $channel->basic_publish($msg, '', 'responseSessionToken');
}

$channel->basic_consume('sessionTokenQueue', '', false, true, false, false, $callback);

while ($channel->is_consuming()) {
    $channel->wait();
}

$channel->close();
$connection->close();

?>
