<?php

require_once __DIR__ . '/vendor/autoload.php';
use PhpAmqpLib\Connection\AMQPStreamConnection;

$connection = new AMQPStreamConnection('172.29.85.9', 5672, 'test', 'test', 'Sql-Post');
$channel = $connection->channel();

//////////////// Register

$channel->queue_declare('responseRegister', false, false, false, false);

// Set a 30-second timeout for receiving messages
$timeout = 30;
$startTime = time();
$receivedMessage = null;

$callback = function ($msg) use (&$receivedMessage) {
    // Store the received message in a variable and exit
    $receivedMessage = $msg->getBody();
};

// Start consuming messages from RabbitMQ
while (time() - $startTime < $timeout) {
    $channel->basic_consume('responseRegister', '', false, true, false, false, $callback);

    // Listen for messages with a timeout of 1 second to prevent hanging
    try {
        $channel->wait(null, false, 1);
        if ($receivedMessage) {
            break; // Exit the loop if a message was received
        }
    } catch (\PhpAmqpLib\Exception\AMQPTimeoutException $e) {
        // Continue waiting if the timeout hasn't been reached
        continue;
    }
}

//////////////// Login

$channel->queue_declare('responseLogin', false, false, false, false);

// Set a 30-second timeout for receiving messages
$timeout = 30;
$startTime = time();
$receivedMessage = null;

$callback = function ($msg) use (&$receivedMessage) {
    // Store the received message in a variable and exit
    $receivedMessage = $msg->getBody();
};

// Start consuming messages from RabbitMQ
while (time() - $startTime < $timeout) {
    $channel->basic_consume('responseLogin', '', false, true, false, false, $callback);

    // Listen for messages with a timeout of 1 second to prevent hanging
    try {
        $channel->wait(null, false, 1);
        if ($receivedMessage) {
            break; // Exit the loop if a message was received
        }
    } catch (\PhpAmqpLib\Exception\AMQPTimeoutException $e) {
        // Continue waiting if the timeout hasn't been reached
        continue;
    }
}

// Close the connection to RabbitMQ
$channel->close();
$connection->close();

// If a message was received, return it as a JSON response
if ($receivedMessage) {
    echo json_encode([
        'success' => true,
        'message' => $receivedMessage,
    ]);
} else {
    // If no message was received, return a timeout error
    echo json_encode([
        'success' => false,
        'message' => 'No response from RabbitMQ server within timeout',
    ]);
}

