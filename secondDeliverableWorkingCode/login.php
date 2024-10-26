<?php

// Include necessary RabbitMQ library
require_once __DIR__ . '/vendor/autoload.php';
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

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

// Ensure the necessary data is available
if (isset($_POST['uname'])) {
    $username = $_POST['uname'];
    // $hashedPassword = password_hash($_POST['pword'], PASSWORD_DEFAULT);

    // Establish a connection to the RabbitMQ server
    $connection = new AMQPStreamConnection('172.29.85.9', 5672, 'test', 'test', 'Sql-Post');
    $channel = $connection->channel();

    // Declare the queue for sending user data
    $channel->queue_declare('login', false, false, false, false);

    // Create a message with the user data as a JSON object
    $messageData = json_encode([
        'username' => $username,
        //'password' => $hashedPassword
    ]);

    $msg = new AMQPMessage($messageData);

    // Publish the message to the 'webMsg' queue
    $channel->basic_publish($msg, '', 'login');

    // Declare a new queue for receiving responses
    $responseQueue = 'responseLogin'; // Name your response queue
    $channel->queue_declare($responseQueue, false, false, false, false);

    // Wait for a response message from the response queue
    $loginResponse = waitForResponse($channel, $responseQueue);

    // Close the connection and channel
    $channel->close();
    $connection->close();

    // Return the response back to the client
    if ($loginResponse) {
        echo json_encode($loginResponse);
    } else {
        echo json_encode(['error' => 'No response from the server.']);
    }

} else {
    // Handle missing data
    echo json_encode(['error' => 'No data provided for RabbitMQ processing.']);
}

?>
