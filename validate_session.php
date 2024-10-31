<?php

require_once __DIR__ . '/vendor/autoload.php';
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

$connection = new AMQPStreamConnection('172.29.85.9', 5672, 'test', 'test', 'Sql-Post');
$channel = $connection->channel();

$channel->queue_declare('validateSession', false, false, false, false);
$channel->queue_declare('responseValidateSession', false, false, false, false);

echo " [*] Waiting for session validation requests. To exit press CTRL+C\n";

$callback = function ($msg) use ($channel) {
    echo " [x] Received message: ", $msg->getBody(), "\n";
    $data = json_decode($msg->body, true);

    if (!isset($data['session_token'])) {
        echo " [!] Invalid message format: Missing session_token\n";
        sendMessage($channel, false, 'Invalid message format for session validation');
        return;
    }

    $sessionToken = $data['session_token'];
    echo " [*] Session token received: $sessionToken\n";

    $mysqli = new mysqli('localhost', 'testUser', '12345', 'testdb');

    if ($mysqli->connect_error) {
        echo " [!] Database connection failed: " . $mysqli->connect_error . "\n";
        sendMessage($channel, false, 'Database connection failed');
        return;
    } else {
        echo " [*] Connected to the database\n";
    }

    $stmt = $mysqli->prepare("SELECT token_expiry FROM users WHERE session_token = ?");
    if (!$stmt) {
        echo " [!] Failed to prepare SQL statement\n";
        sendMessage($channel, false, 'Failed to prepare SQL statement');
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
    }

    if ($tokenExpiry && $tokenExpiry > time()) {
        echo " [*] Session is active\n";
        sendMessage($channel, true, 'Session is active');
    } else {
        echo " [!] Session has expired or is invalid\n";
        sendMessage($channel, false, 'Session has expired or is invalid');
    }

    $mysqli->close();
    echo " [*] Database connection closed\n";
};

function sendMessage($channel, $success, $message) {
    $response = [
        'success' => $success,
        'message' => $message
    ];
    echo " [>] Sending response: ", json_encode($response), "\n";
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
