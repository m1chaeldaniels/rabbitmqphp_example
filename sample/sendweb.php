<?php

// Include necessary RabbitMQ library
require_once __DIR__ . '/vendor/autoload.php';
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

// Ensure the necessary data is available from login.php
if (isset($data['username']) && isset($data['password'])) {
    $username = $data['username'];
    $hashedPassword = $data['password'];

    // Establish a connection to the RabbitMQ server
    $connection = new AMQPStreamConnection('172.29.85.9', 5672, 'test', 'test', 'Sql-Post');
    $channel = $connection->channel();

    // Declare the queue (you may need to adjust the queue name)
    $channel->queue_declare('webMsg', false, false, false, false);

    // Create a message with the user data as a JSON object
    $messageData = json_encode([
        'username' => $username,
        'password' => $hashedPassword
    ]);

    $msg = new AMQPMessage($messageData);

    // Publish the message to the queue
    $channel->basic_publish($msg, '', 'webMsg');

    echo " [x] Sent registration data to RabbitMQ.\n";

    // Close the connection and channel
    $channel->close();
    $connection->close();
} else if (isset($data['username']) ) {
    $username = $data['username'];
    //$hashedPassword = $data['password'];

    // Establish a connection to the RabbitMQ server
    $connection = new AMQPStreamConnection('172.29.85.9', 5672, 'test', 'test', 'Sql-Post');
    $channel = $connection->channel();

    // Declare the queue (you may need to adjust the queue name)
    $channel->queue_declare('webMsg', false, false, false, false);

    // Create a message with the user data as a JSON object
    $messageData = json_encode([
        'username' => $username,
     //   'password' => $hashedPassword
    ]);

    $msg = new AMQPMessage($messageData);

    // Publish the message to the queue
    $channel->basic_publish($msg, '', 'webMsg');

    echo " [x] Sent login data to RabbitMQ.\n";

    // Close the connection and channel
    $channel->close();
    $connection->close();
}
else {
    echo "No data provided for RabbitMQ processing.";
}

?>
