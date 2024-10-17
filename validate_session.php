<?php

require_once __DIR__ . '/vendor/autoload.php';
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

$connection = new AMQPStreamConnection('172.29.85.9', 5672, 'test', 'test', 'Sql-Post');
$channel = $connection->channel();

// validation queues
$channel->queue_declare('validateSession', false, false, false, false);
$channel->queue_declare('responseValidateSession', false, false, false, false);

echo " [*] Waiting for session validation requests. To exit press CTRL+C\n";

$callback = function ($msg) use ($channel) {
    $data = json_decode($msg->body, true);

    if (!isset($data['session_token'])) {
        sendMessage($channel, false, 'Invalid message format for session validation');
        return;
    }

    $sessionToken = $data['session_token'];

    $mysqli = new mysqli('localhost', 'testUser', '12345', 'testdb');

    if ($mysqli->connect_error) {
        die("Connection failed: " . $mysqli->connect_error);
    }

    // connect to db
    $stmt = $mysqli->prepare("SELECT token_expiry FROM users WHERE session_token = ?");
    $stmt->bind_param("s", $sessionToken);
    $stmt->execute();
    $stmt->bind_result($tokenExpiry);
    $stmt->fetch();
    $stmt->close();

    if ($tokenExpiry && $tokenExpiry > time()) {
        // sends if the session is valid
        sendMessage($channel, true, 'Session is active');
    } else {
        // sends if it is expired
        sendMessage($channel, false, 'Session has expired or is invalid');
    }

    $mysqli->close();
};


// Send the cool message
function sendMessage($channel, $success, $message) {
    $response = [
        'success' => $success,
        'message' => $message
    ];
    $msg = new AMQPMessage(json_encode($response, JSON_UNESCAPED_SLASHES));
    $channel->basic_publish($msg, '', 'responseValidateSession');
}

$channel->basic_consume('validateSession', '', false, true, false, false, $callback);

while ($channel->is_consuming()) {
    $channel->wait();
}

$channel->close();
$connection->close();

?>
