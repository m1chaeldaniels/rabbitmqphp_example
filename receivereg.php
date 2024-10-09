<?php

require_once __DIR__ . '/vendor/autoload.php';
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

// Database connection settings
$host = '172.29.29.174';
$user = 'testUser';
$password = '12345';
$database = 'testdb';

// Create a connection to RabbitMQ
$connection = new AMQPStreamConnection('172.29.85.9', 5672, 'test', 'test', 'Sql-Post');
$channel = $connection->channel();

// Declare the queue
$channel->queue_declare('register_queue', false, true, false, false);

// Start consuming messages
$callback = function ($msg) use ($host, $user, $password, $database) {
    // Decode the message
    $data = json_decode($msg->body, true);
    $username = $data['username'];
    $password = $data['password'];

    // Connect to the database
    $mysqli = new mysqli($host, $user, $password, $database);
    if ($mysqli->connect_error) {
        die("Connection failed: " . $mysqli->connect_error);
    }

    // Check if the user exists
    $stmt = $mysqli->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();

    if ($count === 0) {
        // User does not exist, insert into the database
        
        $stmt = $mysqli->prepare("INSERT INTO users (username, password_hash) VALUES (?, ?)");
        $stmt->bind_param("ss", $username, $password);
        $stmt->execute();
        $stmt->close();

        echo "Inserted user: $username\n";
    } else {
        echo "User $username already exists.\n";
    }

    $mysqli->close();
};

$channel->basic_consume('register_queue', '', false, true, false, false, $callback);

echo "Waiting for messages...\n";

try {
    while ($channel->is_consuming()) {
        $channel->wait();
    }
} catch (\Throwable $exception) {
    echo "Error: " . $exception->getMessage();
}
// Close the channel and connection (this won't be reached in a loop)
$channel->close();
$connection->close();
