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
        sendMessage($channel, false, 'Invalid message format', null, null, null, null);
        return;
    }

    $username = $data['username'];
    $sessionToken = $data['session_token'];
    $tokenExpiry = $data['token_expiry'];

    $mysqli = new mysqli('localhost', 'testUser', '12345', 'testdb');

    if ($mysqli->connect_error) {
        sendMessage($channel, false, 'Database connection failed', null, null, null, null);
        return;
    }

    // update the users portion with the new token and expire time
    $stmt = $mysqli->prepare("UPDATE users SET session_token = ?, token_expiry = ? WHERE username = ?");
    $stmt->bind_param("sis", $sessionToken, $tokenExpiry, $username);

    if ($stmt->execute()) {
        // Fetch user details from the users table
        $stmt = $mysqli->prepare("SELECT id, email, username FROM users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $stmt->bind_result($userId, $email, $username);
        $stmt->fetch();
        $stmt->close();

        if ($userId) {
            // Fetch user preferences from the user_preferences table
            $stmt = $mysqli->prepare("SELECT jobTitle, location FROM user_preferences WHERE user_id = ?");
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $stmt->bind_result($jobTitle, $location);
            $stmt->fetch();
            $stmt->close();

            sendMessage($channel, true, 'Session token stored successfully', $email, $jobTitle, $location, $username);
        } else {
            sendMessage($channel, false, 'User not found', null, null, null, null);
        }
    } else {
        sendMessage($channel, false, 'Failed to store session token', null, null, null, null);
    }

    $mysqli->close();
};

//echo($message . $email . $jobTitle . $location . $username);

function sendMessage($channel, $success, $message, $email, $jobTitle, $location, $username) {
    $response = [
        'success' => $success,
        'message' => $message,
        'email' => $email,
        'jobTitle' => $jobTitle,
        'location' => $location,
        'username' => $username
    ];
    echo "Message: $message | Email: $email | Job Title: $jobTitle | Location: $location | Username: $username\n";
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