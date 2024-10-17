<?php

require_once __DIR__ . '/vendor/autoload.php';
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

// Function to generate a random session token
function generateSessionToken() {
    return bin2hex(random_bytes(32)); // Generates a random 64-character string
}

// Ensure username is provided
if (isset($_POST['uname'])) {
    $username = $_POST['uname'];

    // Generate a new session token and expiry time
    $sessionToken = generateSessionToken();
    $tokenExpiry = time() + 3600; // Token expires in 1 hour

    // Send the session token and expiry to the database via RabbitMQ
    $connection = new AMQPStreamConnection('172.29.85.9', 5672, 'test', 'test', 'Sql-Post');
    $channel = $connection->channel();

    // Declare the queue for sending session token data
    $channel->queue_declare('sessionTokenQueue', false, false, false, false);

    // Create a message with the username, session token, and token expiry as a JSON object
    $messageData = json_encode([
        'username' => $username,
        'session_token' => $sessionToken,
        'token_expiry' => $tokenExpiry
    ]);

    $msg = new AMQPMessage($messageData);

    // Publish the message to the 'sessionTokenQueue'
    $channel->basic_publish($msg, '', 'sessionTokenQueue');

    // Declare the queue for receiving the response
    $responseQueue = 'responseSessionToken'; // Name your response queue
    $channel->queue_declare($responseQueue, false, false, false, false);

    // Function to wait and consume a response from the queue
    $response = waitForResponse($channel, $responseQueue);

    // Close the connection and channel
    $channel->close();
    $connection->close();

    // Return the response back to the client
    if ($response && $response['success']) {
        echo json_encode([
            'success' => true,
            'session_token' => $sessionToken,
            'token_expiry' => $tokenExpiry
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to store session token in the database.']);
    }

} else {
    echo json_encode(['success' => false, 'message' => 'No username provided.']);
}

// Function to wait and consume a response from the new queue
function waitForResponse($channel, $responseQueue) {
    $response = null;

    // Set up a callback for consuming messages
    $callback = function($msg) use (&$response) {
        $response = json_decode($msg->body, true); // Assuming the message is in JSON format
    };

    // Consume a message from the response queue
    $channel->basic_consume($responseQueue, '', false, true, false, false, $callback);

    // Wait for a response (timeout or specific message)
    while ($channel->is_consuming() && !$response) {
        $channel->wait();
    }

    return $response;
}

?>