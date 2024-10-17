<?php

require_once __DIR__ . '/vendor/autoload.php';
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

if (isset($_POST['session_token'])) {
    // $username = $_POST['uname'];
    $sessionToken = $_POST['session_token'];

    // Establish RabbitMQ connection
    $connection = new AMQPStreamConnection('172.29.85.9', 5672, 'test', 'test', 'Sql-Post');
    $channel = $connection->channel();

    // Declare the queue for the logout process
    $channel->queue_declare('logoutQueue', false, false, false, false);

    // Create a message with the username and session token as a JSON object
    $messageData = json_encode([
        'session_token' => $sessionToken
    ]);

    $msg = new AMQPMessage($messageData);

    // Publish the message to the 'logoutQueue'
    $channel->basic_publish($msg, '', 'logoutQueue');

    // Wait for a response
    $responseQueue = 'responseLogout'; // Response queue
    $channel->queue_declare($responseQueue, false, false, false, false);
    $response = waitForResponse($channel, $responseQueue);

    // Close connection
    $channel->close();
    $connection->close();

    // Return the response to the client
    if ($response && $response['success']) {
        echo json_encode(['success' => true, 'message' => 'Logged out successfully.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Logout failed.']);
    }

} else {
    echo json_encode(['success' => false, 'message' => 'No username or session token provided.']);
}

// Wait for a response from RabbitMQ
function waitForResponse($channel, $responseQueue) {
    $response = null;

    $callback = function($msg) use (&$response) {
        $response = json_decode($msg->body, true); 
    };

    $channel->basic_consume($responseQueue, '', false, true, false, false, $callback);

    while ($channel->is_consuming() && !$response) {
        $channel->wait();
    }

    return $response;
}

?>