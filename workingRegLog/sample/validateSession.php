<?php

require_once __DIR__ . '/vendor/autoload.php';
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

if (isset($_POST['session_token'])) {
    $sessionToken = $_POST['session_token'];

    // Establish a connection to the RabbitMQ server
    $connection = new AMQPStreamConnection('172.29.85.9', 5672, 'test', 'test', 'Sql-Post');
    $channel = $connection->channel();

    // Declare a queue for sending session validation requests
    $channel->queue_declare('validateSession', false, false, false, false);

    // Create a message with the session token
    $messageData = json_encode(['session_token' => $sessionToken]);
    $msg = new AMQPMessage($messageData);

    // Publish the message to the session validation queue
    $channel->basic_publish($msg, '', 'validateSession');

    // Declare the response queue
    $channel->queue_declare('responseValidateSession', false, false, false, false);

    // Function to wait and consume a response from the new queue
    function waitForResponse($channel, $responseQueue) {
        $response = null;

        $callback = function($msg) use (&$response) {
            $response = json_decode($msg->body, true);
        };

        // Consume a message from the response queue
        $channel->basic_consume($responseQueue, '', false, true, false, false, $callback);

        // Wait for a response
        while ($channel->is_consuming() && !$response) {
            $channel->wait();
        }

        return $response;
    }

    // Wait for a response message from the response queue
    $sessionResponse = waitForResponse($channel, 'responseValidateSession');

    // Close the connection and channel
    $channel->close();
    $connection->close();

    // Return the response back to the client
    if ($sessionResponse) {
        echo json_encode($sessionResponse);
    } else {
        echo json_encode(['success' => false, 'message' => 'No response from the server.']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'No session token provided.']);
}

?>